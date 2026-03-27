<?php
/**
 * PressArk Workflow Runner — Abstract base class for deterministic workflows.
 *
 * Unlike the general-purpose agent (which gives AI full tool-call control),
 * workflows are deterministic state machines where the CODE controls the flow
 * and the AI is a scoped tool used within specific phases.
 *
 * Standard phases:
 *   1. discover        — Find candidates (pure reads, no AI).
 *   2. select_target   — Pick target from candidates (may use scoped AI).
 *   3. gather_context  — Read full context for the selected target (pure reads).
 *   4. plan            — AI plans the changes (scoped prompt, restricted tools).
 *   5. preview         — Stage writes via PressArk_Preview (returns to frontend).
 *   --- preview boundary: user approves/discards ---
 *   6. apply           — Handled by PressArk_Preview::keep() or handle_confirm().
 *   7. verify          — Read back and confirm changes landed (post-apply).
 *
 * @package PressArk
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class PressArk_Workflow_Runner {

	/**
	 * Phase order — deterministic, never reordered.
	 */
	public const PHASES = array(
		'discover',
		'select_target',
		'gather_context',
		'plan',
		'preview',
		// --- preview boundary ---
		'apply',
		'verify',
	);

	// ── Dependencies ──────────────────────────────────────────────

	protected PressArk_AI_Connector  $ai;
	protected PressArk_Action_Engine $engine;
	protected string                 $tier;

	// ── State machine ─────────────────────────────────────────────

	protected string $phase             = 'discover';
	protected array  $state             = array();
	protected array  $steps              = array();
	protected int    $tokens_used        = 0;
	protected int    $output_tokens_used = 0;
	protected int    $input_tokens_used  = 0;
	protected int    $cache_read_tokens  = 0;
	protected int    $cache_write_tokens = 0;
	protected int    $ai_rounds          = 0;
	protected string $actual_provider    = '';
	protected string $actual_model       = '';

	// ── Constructor ───────────────────────────────────────────────

	public function __construct(
		PressArk_AI_Connector  $ai,
		PressArk_Action_Engine $engine,
		string                 $tier = 'free'
	) {
		$this->ai     = $ai;
		$this->engine = $engine;
		$this->tier   = $tier;
	}

	// ── Abstract: subclasses define what each phase does ──────────

	/**
	 * Return tool group names for this workflow (e.g. ['content', 'seo']).
	 *
	 * @return array
	 */
	abstract protected function tool_groups(): array;

	/**
	 * Public accessor for tool groups (v3.7.0).
	 * Used by chat pipeline to record loaded groups in checkpoint.
	 */
	public function get_tool_groups(): array {
		return $this->tool_groups();
	}

	/**
	 * Return the workflow's dominant task type for telemetry/model analysis.
	 *
	 * @return string
	 */
	protected function workflow_task_type(): string {
		return 'edit';
	}

	/**
	 * Phase 1: Discover candidates. Pure reads, no AI.
	 * Return data to merge into $state. Key: 'candidates' => array.
	 */
	abstract protected function phase_discover(): array;

	/**
	 * Phase 2: Select target from discovered candidates.
	 * May use a scoped AI call to pick the best candidate.
	 * Return: ['target' => array].
	 */
	abstract protected function phase_select_target(): array;

	/**
	 * Phase 3: Gather full context for the selected target. Pure reads.
	 */
	abstract protected function phase_gather_context(): array;

	/**
	 * Phase 4: AI plans the changes.
	 * Scoped prompt, text-only response (no tools).
	 * Return: ['plan' => array].
	 */
	abstract protected function phase_plan(): array;

	/**
	 * Phase 5: Build preview.
	 * Must return ['__return' => response_array] via build_preview_response()
	 * or build_confirm_response().
	 */
	abstract protected function phase_preview(): array;

	/**
	 * Phase 7: Verify applied changes.
	 * Reads back data and confirms changes landed.
	 * Return: ['summary' => string].
	 */
	abstract protected function phase_verify(): array;

	// ── Main Entry Point ──────────────────────────────────────────

	/**
	 * Run the workflow state machine (phases 1-5).
	 *
	 * Drives phases sequentially until the preview boundary.
	 * Returns same response shape as PressArk_Agent::run().
	 *
	 * @param string $message      User's sanitized message.
	 * @param array  $conversation Conversation history.
	 * @param string $screen       Admin screen slug.
	 * @param int    $post_id      Current post ID (0 if none).
	 * @return array Response array.
	 */
	public function run(
		string $message,
		array  $conversation,
		string $screen = '',
		int    $post_id = 0
	): array {
		// Initialize state.
		$this->state['message']      = $message;
		$this->state['conversation'] = $conversation;
		$this->state['screen']       = $screen;
		$this->state['post_id']      = $post_id;
		$this->state['loaded_tool_groups']   = $this->tool_groups();
		$this->state['retrieval_bundle_ids'] = $this->state['retrieval_bundle_ids'] ?? array();
		$this->state['blockers']             = $this->state['blockers'] ?? array();
		$this->state['approvals']            = $this->state['approvals'] ?? array();
		$this->sync_workflow_memory();

		$token_budget = $this->get_token_budget();

		// Run phases sequentially until preview boundary or completion.
		while ( 'done' !== $this->phase && $this->output_tokens_used < $token_budget ) {

			// Skip 'apply' — handled externally by preview/keep or confirm.
			if ( 'apply' === $this->phase ) {
				$this->phase = $this->advance();
				continue;
			}

			// Skip 'verify' — called via run_post_apply() after user approves.
			if ( 'verify' === $this->phase ) {
				$this->phase = 'done';
				break;
			}

			$phase_method = 'phase_' . $this->phase;
			$this->sync_workflow_memory();

			try {
				$phase_result = $this->{$phase_method}();
			} catch ( \Throwable $e ) {
				$this->remember_blocker( sprintf( '%s phase failed: %s', $this->phase, $e->getMessage() ) );
				return $this->error_response(
					sprintf( 'Workflow failed in %s phase: %s', $this->phase, $e->getMessage() ),
					PressArk_AI_Connector::FAILURE_PROVIDER_ERROR
				);
			}

			// Merge phase output into state.
			$this->state = array_merge( $this->state, $phase_result );
			$this->sync_workflow_memory();

			// Check for early return (preview/confirm boundary).
			if ( ! empty( $phase_result['__return'] ) ) {
				return $phase_result['__return'];
			}

			// Check for phase error.
			if ( ! empty( $phase_result['__error'] ) ) {
				$this->remember_blocker( (string) $phase_result['__error'] );
				return $this->error_response(
					$phase_result['__error'],
					(string) ( $phase_result['__failure_class'] ?? '' )
				);
			}

			// Advance to next phase.
			$this->phase = $this->advance();
		}

		// Budget exhausted.
		if ( $this->output_tokens_used >= $token_budget ) {
			return $this->error_response(
				'This workflow reached its token budget before completing all phases. No changes were made. Try a more focused request or upgrade your plan for higher limits.',
				PressArk_AI_Connector::FAILURE_TRUNCATION
			);
		}

		// Should not reach here — phase_preview returns via __return.
		return $this->error_response(
			'Workflow completed but did not produce a preview or result. This is unexpected — please try again.',
			PressArk_AI_Connector::FAILURE_PROVIDER_ERROR
		);
	}

	// ── Post-Apply Entry (Verify Phase) ───────────────────────────

	/**
	 * Run the verify phase after preview has been kept / action confirmed.
	 *
	 * Called by the chat pipeline after preview/keep or confirm succeeds.
	 *
	 * @param array $kept_result Result from PressArk_Preview::keep() or confirm.
	 * @return array Response array with verification summary.
	 */
	public function run_post_apply( array $kept_result ): array {
		$this->state['applied'] = $kept_result;
		$this->phase = 'verify';
		$this->remember_approval_from_result( $kept_result );
		$this->sync_workflow_memory();

		try {
			$verify_result = $this->phase_verify();
		} catch ( \Throwable $e ) {
			$this->remember_blocker( 'Post-apply verification failed: ' . $e->getMessage() );
			// Verify failure is non-fatal — changes are already applied.
			return array_merge(
				array(
					'type'    => 'final_response',
					'message' => 'Changes applied successfully. Post-apply verification could not confirm the result ('
					           . $e->getMessage() . '), but the changes are live. Refresh the page to verify manually.',
				),
				$this->telemetry_fields(),
			);
		}

		$this->state['workflow_stage'] = 'settled';
		$this->sync_workflow_memory();

		return array_merge(
			array(
				'type'    => 'final_response',
				'message' => $verify_result['summary'] ?? 'Changes applied and verified.',
			),
			$this->telemetry_fields(),
		);
	}

	// ── State Machine Transitions ─────────────────────────────────

	/**
	 * Advance to the next phase.
	 *
	 * @return string Next phase name, or 'done'.
	 */
	protected function advance(): string {
		$current_index = array_search( $this->phase, self::PHASES, true );

		if ( false === $current_index ) {
			return 'done';
		}

		$next_index = $current_index + 1;

		if ( $next_index >= count( self::PHASES ) ) {
			return 'done';
		}

		return self::PHASES[ $next_index ];
	}

	// ── Shared Infrastructure ─────────────────────────────────────

	/**
	 * Execute a read-only tool through the action engine.
	 * Emits steps automatically for the activity strip.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Tool arguments.
	 * @return array Action engine result.
	 */
	protected function exec_read( string $tool, array $args ): array {
		$this->emit_step( 'reading', $tool, $args );
		$result = $this->engine->execute_read( $tool, $args );
		$this->emit_step( 'done', $tool, $args, $result );
		$this->remember_retrieval_bundle( $tool, $args, $result );
		return $result;
	}

	/**
	 * Make a scoped AI call with a specific prompt and restricted tools.
	 *
	 * The runner controls WHAT the AI is asked, with WHICH tools, and
	 * interprets the result. This is fundamentally different from the
	 * agent where AI decides everything.
	 *
	 * @param string $scoped_prompt  Specific instruction for this phase.
	 * @param array  $context_data   Structured data to include in the prompt.
	 * @param array  $allowed_tools  Tool names the AI can call (empty = text-only).
	 * @return array { text: string, tool_calls: array, raw: array, provider: string }
	 */
	protected function ai_call(
		string $scoped_prompt,
		array  $context_data  = array(),
		array  $allowed_tools = array(),
		array  $options       = array()
	): array {
		// Build context.
		$context = new PressArk_Context();
		$system  = $context->build( $this->state['screen'] ?? '', $this->state['post_id'] ?? 0 );

		// Build user message with scoped prompt and structured data.
		$user_content = $scoped_prompt;
		if ( ! empty( $context_data ) ) {
			$user_content .= "\n\n## Data\n```json\n"
			               . wp_json_encode( $context_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
			               . "\n```";
		}

		$messages = array(
			array( 'role' => 'user', 'content' => $user_content ),
		);

		// Build tool schemas only for allowed tools.
		$tool_defs = array();
		if ( ! empty( $allowed_tools ) ) {
			$catalog   = PressArk_Tool_Catalog::instance();
			$tool_defs = $catalog->get_schemas( $allowed_tools );
		}

		// Call AI.
		$api_result    = $this->ai->send_message_raw( $messages, $tool_defs, $system, false, $options );
		$raw           = $api_result['raw'] ?? array();
		$provider      = $api_result['provider'] ?? 'openai';
		$failure_class = (string) ( $api_result['failure_class'] ?? '' );
		if ( ! empty( $api_result['request_made'] ) ) {
			$this->ai_rounds++;
		}

		// Capture actual provider/model from first call.
		if ( empty( $this->actual_provider ) ) {
			$this->actual_provider = $provider;
			$this->actual_model    = (string) ( $api_result['model'] ?? $this->ai->get_model() );
		}

		// Track tokens.
		$this->tokens_used        += $this->ai->extract_usage( $raw, $provider );
		$this->output_tokens_used += $this->ai->extract_output_usage( $raw, $provider );
		$this->input_tokens_used  += (int) ( $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0 );

		// Accumulate cache metrics.
		$cache = $api_result['cache_metrics'] ?? array();
		$this->cache_read_tokens  += (int) ( $cache['cache_read'] ?? 0 );
		$this->cache_write_tokens += (int) ( $cache['cache_write'] ?? 0 );

		// Extract text.
		$text = $this->ai->extract_text( $raw, $provider );

		// Extract tool calls (if any).
		$tool_calls = array();
		if ( 'tool_use' === $this->ai->extract_stop_reason( $raw, $provider ) ) {
			$tool_calls = $this->ai->extract_tool_calls( $raw, $provider );
		}

		return array(
			'text'       => $text,
			'tool_calls' => $tool_calls,
			'raw'        => $raw,
			'provider'   => $provider,
			'failure_class' => $failure_class,
			'fallback_used' => ! empty( $api_result['fallback_used'] ),
			'attempts'      => (int) ( $api_result['attempts'] ?? 0 ),
		);
	}

	/**
	 * Build a preview response (pauses the workflow at the preview boundary).
	 *
	 * @param array  $tool_calls Tool calls to stage.
	 * @param string $message    Summary message for the user.
	 * @return array Phase result with __return key.
	 */
	protected function build_preview_response( array $tool_calls, string $message = '' ): array {
		$preview = new PressArk_Preview();
		$session = $preview->create_session(
			$tool_calls,
			$tool_calls[0]['arguments'] ?? array()
		);

		return array(
			'__return' => array_merge(
				array(
					'type'               => 'preview',
					'message'            => $message,
					'preview_session_id' => $session['session_id'],
					'preview_url'        => $session['signed_url'],
					'diff'               => $session['diff'],
					'pending_actions'    => $tool_calls,
					'workflow_state'     => $this->serialize_state(),
				),
				$this->telemetry_fields(),
			),
		);
	}

	/**
	 * Build a confirm card response for non-previewable writes.
	 *
	 * @param array  $pending_actions Tool calls needing user confirmation.
	 * @param string $message         Summary message for the user.
	 * @return array Phase result with __return key.
	 */
	protected function build_confirm_response( array $pending_actions, string $message = '' ): array {
		return array(
			'__return' => array_merge(
				array(
					'type'            => 'confirm_card',
					'message'         => $message,
					'pending_actions' => $pending_actions,
					'workflow_state'  => $this->serialize_state(),
				),
				$this->telemetry_fields(),
			),
		);
	}

	// ── State Serialization ───────────────────────────────────────

	/**
	 * Serialize minimal state for resumption after preview approval.
	 *
	 * @return array Serialized state sufficient for the verify phase.
	 */
	public function serialize_state(): array {
		$this->sync_workflow_memory();

		return array(
			'workflow_class'     => static::class,
			'phase'              => $this->phase,
			'post_id'            => $this->state['post_id'] ?? 0,
			'target'             => $this->state['target'] ?? array(),
			'selected_target'    => $this->state['selected_target'] ?? array(),
			'plan'               => $this->state['plan'] ?? array(),
			'creation'           => $this->state['creation'] ?? array(),
			'workflow_stage'     => $this->state['workflow_stage'] ?? $this->checkpoint_stage(),
			'loaded_tool_groups' => $this->state['loaded_tool_groups'] ?? $this->tool_groups(),
			'blockers'           => $this->state['blockers'] ?? array(),
			'approvals'          => $this->state['approvals'] ?? array(),
			'retrieval_bundle_ids' => $this->state['retrieval_bundle_ids'] ?? array(),
			'steps'              => $this->steps,
			'tokens_used'        => $this->tokens_used,
			'input_tokens'       => $this->input_tokens_used,
			'output_tokens'      => $this->output_tokens_used,
			'cache_read_tokens'  => $this->cache_read_tokens,
			'cache_write_tokens' => $this->cache_write_tokens,
			'provider'           => $this->actual_provider,
			'model'              => $this->actual_model,
			'agent_rounds'       => $this->ai_rounds,
		);
	}

	/**
	 * Restore state from serialized data (for post-apply verify).
	 *
	 * @param array $serialized Data from serialize_state().
	 */
	public function restore_state( array $serialized ): void {
		// Always resume at verify — restore is only called for post-apply verification.
		$this->phase              = 'verify';
		$this->state              = $serialized;
		$this->steps              = $serialized['steps'] ?? array();
		$this->tokens_used        = (int) ( $serialized['tokens_used'] ?? 0 );
		$this->input_tokens_used  = (int) ( $serialized['input_tokens'] ?? 0 );
		$this->output_tokens_used = (int) ( $serialized['output_tokens'] ?? 0 );
		$this->cache_read_tokens  = (int) ( $serialized['cache_read_tokens'] ?? 0 );
		$this->cache_write_tokens = (int) ( $serialized['cache_write_tokens'] ?? 0 );
		$this->actual_provider    = (string) ( $serialized['provider'] ?? '' );
		$this->actual_model       = (string) ( $serialized['model'] ?? '' );
		$this->ai_rounds          = (int) ( $serialized['agent_rounds'] ?? 0 );
		$this->sync_workflow_memory();
	}

	// ── Helpers ───────────────────────────────────────────────────

	/**
	 * Emit a step for the activity strip (same format as agent).
	 *
	 * @param string     $status 'reading'|'done'|'preparing_preview'|'needs_confirm'.
	 * @param string     $tool   Tool name.
	 * @param array      $args   Tool arguments.
	 * @param array|null $result Tool result (for 'done' status).
	 */
	protected function emit_step( string $status, string $tool, array $args, $result = null ): void {
		$label = ucwords( str_replace( '_', ' ', $tool ) );

		if ( ! empty( $args['post_id'] ) ) {
			$post = get_post( (int) $args['post_id'] );
			if ( $post ) {
				$label .= ' — ' . $post->post_title;
			}
		}

		$this->steps[] = array(
			'status' => $status,
			'label'  => $label,
			'tool'   => $tool,
			'time'   => microtime( true ),
		);
	}

	/**
	 * Return execution telemetry fields for inclusion in every response.
	 *
	 * @since 3.8.0
	 * @return array Telemetry fields.
	 */
	protected function telemetry_fields(): array {
		$loaded_groups = array_values( array_unique( array_filter(
			array_map( 'sanitize_text_field', (array) ( $this->state['loaded_tool_groups'] ?? $this->tool_groups() ) )
		) ) );

		return array(
			'steps'              => $this->steps,
			'tokens_used'        => $this->tokens_used,
			'input_tokens'       => $this->input_tokens_used,
			'output_tokens'      => $this->output_tokens_used,
			'cache_read_tokens'  => $this->cache_read_tokens,
			'cache_write_tokens' => $this->cache_write_tokens,
			'provider'           => $this->actual_provider,
			'model'              => $this->actual_model,
			'agent_rounds'       => $this->ai_rounds,
			'workflow_class'     => static::class,
			'task_type'          => $this->workflow_task_type(),
			'loaded_groups'      => $loaded_groups,
			'tool_loading'       => $this->workflow_tool_loading( $loaded_groups ),
		);
	}

	/**
	 * Build workflow tool-loading telemetry in the same shape as the agent path.
	 *
	 * @param array $groups Loaded workflow groups.
	 * @return array
	 */
	protected function workflow_tool_loading( array $groups ): array {
		$catalog    = PressArk_Tool_Catalog::instance();
		$tool_count = count( $catalog->get_tool_names_for_groups( $groups ) );

		return array(
			'initial_groups' => $groups,
			'final_groups'   => $groups,
			'discover_calls' => 0,
			'load_calls'     => 0,
			'initial_count'  => $tool_count,
			'final_count'    => $tool_count,
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @return array Response array.
	 */
	protected function error_response( string $message, string $failure_class = '' ): array {
		$this->sync_workflow_memory();

		return array_merge(
			array(
				'type'     => 'final_response',
				'message'  => $message,
				'is_error' => true,
				'failure_class' => $failure_class,
				'resume_safe'   => $this->can_resume_from_checkpoint(),
				'workflow_state' => $this->serialize_state(),
			),
			$this->telemetry_fields(),
		);
	}

	protected function phase_error( string $message, string $failure_class ): array {
		return array(
			'__error'         => $message,
			'__failure_class' => $failure_class,
		);
	}

	protected function bad_retrieval( string $message ): array {
		return $this->phase_error( $message, PressArk_AI_Connector::FAILURE_BAD_RETRIEVAL );
	}

	protected function validation_failure( string $message ): array {
		return $this->phase_error( $message, PressArk_AI_Connector::FAILURE_VALIDATION );
	}

	protected function tool_failure( string $message ): array {
		return $this->phase_error( $message, PressArk_AI_Connector::FAILURE_TOOL_ERROR );
	}

	protected function provider_failure( string $message ): array {
		return $this->phase_error( $message, PressArk_AI_Connector::FAILURE_PROVIDER_ERROR );
	}

	protected function side_effect_risk( string $message ): array {
		return $this->phase_error( $message, PressArk_AI_Connector::FAILURE_SIDE_EFFECT_RISK );
	}

	protected function decode_json_response( string $text, string $shape = 'object' ): array {
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$result = json_decode( $text, true );
		if ( ! is_array( $result ) ) {
			return array(
				'error'         => 'The AI response was not valid JSON.',
				'failure_class' => PressArk_AI_Connector::FAILURE_VALIDATION,
			);
		}

		$is_list = $this->is_list_array( $result );
		if ( 'object' === $shape && $is_list ) {
			return array(
				'error'         => 'The AI returned a JSON list where an object was required.',
				'failure_class' => PressArk_AI_Connector::FAILURE_VALIDATION,
			);
		}

		if ( 'list' === $shape && ! $is_list ) {
			return array(
				'error'         => 'The AI returned a JSON object where a list was required.',
				'failure_class' => PressArk_AI_Connector::FAILURE_VALIDATION,
			);
		}

		if ( empty( $result ) ) {
			return array(
				'error'         => 'The AI returned an empty JSON payload.',
				'failure_class' => PressArk_AI_Connector::FAILURE_VALIDATION,
			);
		}

		return array( 'data' => $result );
	}

	protected function checkpoint_stage(): string {
		return match ( $this->phase ) {
			'discover', 'select_target' => 'discover',
			'gather_context'            => 'gather',
			'plan'                      => 'plan',
			'preview'                   => 'preview',
			'apply'                     => 'apply',
			'verify'                    => 'verify',
			default                     => 'settled',
		};
	}

	protected function sync_workflow_memory(): void {
		if ( 'settled' !== (string) ( $this->state['workflow_stage'] ?? '' ) ) {
			$this->state['workflow_stage'] = $this->checkpoint_stage();
		}

		if ( empty( $this->state['loaded_tool_groups'] ) ) {
			$this->state['loaded_tool_groups'] = $this->tool_groups();
		}
		$this->state['loaded_tool_groups'] = array_values( array_unique( array_filter(
			array_map( 'sanitize_text_field', (array) $this->state['loaded_tool_groups'] )
		) ) );

		$this->state['retrieval_bundle_ids'] = array_values( array_unique( array_filter(
			(array) ( $this->state['retrieval_bundle_ids'] ?? array() )
		) ) );

		$this->state['blockers'] = array_values( array_unique( array_filter(
			array_map( 'sanitize_text_field', (array) ( $this->state['blockers'] ?? array() ) )
		) ) );

		$this->state['approvals'] = array_values( array_filter(
			(array) ( $this->state['approvals'] ?? array() ),
			static function ( $approval ) {
				return is_array( $approval ) && ! empty( $approval['action'] );
			}
		) );

		if ( ! empty( $this->state['target'] ) && empty( $this->state['selected_target'] ) ) {
			$this->state['selected_target'] = $this->normalize_selected_target( $this->state['target'] );
		}
	}

	protected function remember_blocker( string $message ): void {
		$message = sanitize_text_field( $message );
		if ( '' === $message ) {
			return;
		}

		$blockers = (array) ( $this->state['blockers'] ?? array() );
		if ( ! in_array( $message, $blockers, true ) ) {
			$blockers[] = $message;
		}
		$this->state['blockers'] = array_slice( $blockers, 0, 10 );
	}

	protected function remember_approval( string $action ): void {
		$action = sanitize_text_field( $action );
		if ( '' === $action ) {
			return;
		}

		$approvals = (array) ( $this->state['approvals'] ?? array() );
		foreach ( $approvals as $approval ) {
			if ( ( $approval['action'] ?? '' ) === $action ) {
				return;
			}
		}

		$approvals[] = array(
			'action'      => $action,
			'approved_at' => gmdate( 'c' ),
		);
		$this->state['approvals'] = array_slice( $approvals, 0, 10 );
	}

	protected function remember_retrieval_bundle( string $tool, array $args, array $result ): void {
		if ( empty( $result['success'] ) ) {
			return;
		}

		$bundleable_tools = array( 'read_content', 'search_content', 'search_knowledge', 'list_posts' );
		if ( ! in_array( $tool, $bundleable_tools, true ) ) {
			return;
		}

		$bundle_id = PressArk_Checkpoint::compute_bundle_id( $tool, $args );
		PressArk_Checkpoint::store_bundle_payload( $bundle_id, $tool, $args, $result );

		$bundle_ids = (array) ( $this->state['retrieval_bundle_ids'] ?? array() );
		if ( ! in_array( $bundle_id, $bundle_ids, true ) ) {
			$bundle_ids[] = $bundle_id;
		}
		$this->state['retrieval_bundle_ids'] = array_slice( $bundle_ids, -20 );
	}

	protected function normalize_selected_target( array $target ): array {
		if ( ! empty( $target['post_ids'] ) && is_array( $target['post_ids'] ) ) {
			return array();
		}

		$id    = absint( $target['post_id'] ?? $target['id'] ?? 0 );
		$title = sanitize_text_field( $target['title'] ?? '' );
		$type  = sanitize_text_field( $target['type'] ?? '' );

		if ( $id > 0 && ( '' === $title || '' === $type ) ) {
			$post = get_post( $id );
			if ( $post ) {
				$title = $title ?: $post->post_title;
				$type  = $type ?: $post->post_type;
			}
		}

		if ( $id <= 0 ) {
			return array();
		}

		return array(
			'id'    => $id,
			'title' => $title,
			'type'  => $type ?: 'post',
		);
	}

	private function remember_approval_from_result( array $kept_result ): void {
		foreach ( (array) ( $kept_result['applied'] ?? array() ) as $label ) {
			if ( is_string( $label ) && '' !== trim( $label ) ) {
				$this->remember_approval( $label );
			}
		}
	}

	/**
	 * Get token budget for the current tier.
	 *
	 * v3.5.1: Sources from PressArk_Entitlements (single source of truth).
	 *
	 * @return int Token budget.
	 */
	protected function get_token_budget(): int {
		return (int) PressArk_Entitlements::tier_value( $this->tier, 'workflow_token_budget' );
	}

	/**
	 * Get cumulative tokens used across all phases.
	 *
	 * @return int
	 */
	public function get_tokens_used(): int {
		return $this->tokens_used;
	}

	protected function can_resume_from_checkpoint(): bool {
		return ! in_array( $this->phase, array( 'apply', 'verify' ), true );
	}

	private function is_list_array( array $data ): bool {
		$expected = 0;
		foreach ( array_keys( $data ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}
		return true;
	}
}
