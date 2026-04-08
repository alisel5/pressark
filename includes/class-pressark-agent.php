<?php
/**
 * PressArk Agentic Loop Controller
 *
 * Runs multi-round AI execution:
 * - Read tools execute automatically (no user confirm, free tier friendly)
 * - Write tools pause the loop and return proposed actions for preview
 * - Token budget tracked per session to prevent runaway costs
 * - Each step emitted for real-time UI activity strip
 *
 * v2.3.1: Self-service tool loading via discover_tools + load_tools.
 * No keyword-based intent classifier. Conversation-scoped tool state.
 *
 * v3.2.0: Bounded execution kernel.
 * - Hard round limits per tier (no more PHP_INT_MAX).
 * - Spin detection: exit after N consecutive no-progress rounds.
 * - Meta-tool budgets: capped discover + load calls per session.
 * - No full-tool fallback — guided degradation instead of "load everything".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Agent {

	// v3.2.0: Meta-tool call budgets — prevent discovery loops.
	const MAX_DISCOVER_CALLS = 5;
	const MAX_LOAD_CALLS     = 5;

	// v3.2.0: Consecutive no-progress rounds before forced exit.
	const MAX_IDLE_ROUNDS = 3;

	// v4.4.1: Per-tool-call output ceiling for results sent back into the loop.
	const MAX_TOOL_RESULT_TOKENS = 10000;

	// v5.0.1: Wall-clock timeout in seconds. Prevents runaway loops from
	// burning tokens indefinitely when round/token limits are not hit
	// (e.g. cheap read-only tool calls in a spin cycle).
	const LOOP_TIMEOUT_SECONDS = 120;

	// Proactive checkpoint priming happens earlier than live compaction so the
	// run always has a durable continuation capsule before budget pressure.
	const SOFT_OUTPUT_BUDGET_RATIO     = 0.6;
	const LIVE_COMPACTION_TOKEN_RATIO  = 0.82;
	const SUMMARY_HEADROOM_OUT_TOKENS  = 320;

	// v5.0.6: Token-based compression ceiling (replaces ICU-based).
	// Model-agnostic: total tokens consumed across all rounds in a single request.
	const MAX_REQUEST_TOKENS           = 258000;
	const SOFT_PRIME_TOKEN_RATIO       = 0.65;   // ~167K → start capsule priming.
	const SOFT_COMPACTION_TOKEN_RATIO  = 0.86;   // ~222K → live message compaction.
	const PAUSE_HEADROOM_TOKENS        = 8000;   // Pause when within 8K of ceiling.

	// Tool capability classification is centralized in PressArk_Tool_Catalog.
	// Use PressArk_Agent::classify_tool() which delegates to the catalog.

	private PressArk_AI_Connector  $ai;
	private PressArk_Action_Engine $engine;
	private string                 $tier;
	private int                    $tokens_used = 0;        // Total tokens (for billing).
	private int                    $output_tokens_used = 0; // Output tokens only (for budget control).
	private int                    $input_tokens_used = 0;  // Input tokens (for telemetry).
	private int                    $cache_read_tokens = 0;  // Cache read tokens (for telemetry).
	private int                    $cache_write_tokens = 0; // Cache write tokens (for telemetry).
	private int                    $icu_spent = 0;
	private int                    $model_rounds    = 0;    // Actual model calls made.
	private string                 $actual_provider = '';    // Provider used in API calls.
	private string                 $actual_model    = '';    // Model used in API calls.
	private array                  $steps       = array();
	private ?PressArk_Token_Budget_Manager $budget_manager = null;
	private array                  $budget_report = array();
	private array                  $history_budget = array();
	private array                  $activity_events = array();
	private array                  $routing_decision = array();
	private array                  $context_inspector = array();

	// v2.3.1: Conversation-scoped tool loading state.
	private array  $loaded_groups      = array();
	private int    $discover_calls     = 0;
	private int    $discover_zero_hits = 0;
	private int    $load_calls         = 0;
	private int    $initial_tool_count = 0;

	// v3.2.0: Spin detection — tracks consecutive rounds with no real progress.
	private int    $idle_rounds        = 0;
	private string $last_tool_signature = '';

	// v3.6.0: Task type from classifier — used for step-ordering hints.
	private string $task_type          = 'chat';
	private bool   $has_proposed_write = false;

	// v4.3.2: Execution plan from planner — guides agent step ordering.
	private array  $plan_steps = array();
	private int    $plan_step  = 1;

	// v4.0.0: Automation context for unattended runs.
	private ?array $automation_context = null;
	private string $run_id             = '';
	private int    $chat_id            = 0;

	public function __construct(
		PressArk_AI_Connector  $ai,
		PressArk_Action_Engine $engine,
		string                 $tier = 'free'
	) {
		$this->ai     = $ai;
		$this->engine = $engine;
		$this->tier   = $tier;
	}

	/**
	 * v3.7.1: Set async task context for business idempotency.
	 * Propagates to the action engine and all domain handlers so
	 * destructive operations (refunds, emails, orders) can record
	 * receipts and avoid double-execution when the task retries.
	 *
	 * @param string $task_id The current async task ID.
	 */
	public function set_async_context( string $task_id ): void {
		$this->engine->set_async_context( $task_id );
	}

	/**
	 * Set durable run metadata for prompt-side tool-result artifact storage.
	 */
	public function set_run_context( string $run_id, int $chat_id = 0 ): void {
		$this->run_id  = sanitize_key( $run_id );
		$this->chat_id = max( 0, $chat_id );
	}

	/**
	 * v4.0.0: Set automation context for unattended runs.
	 * Injects an addendum into the system prompt that guides unattended execution.
	 *
	 * @param array $automation The automation record.
	 */
	public function set_automation_context( array $automation ): void {
		$this->automation_context = $automation;
	}

	/**
	 * Buffer a canonical activity event to be published at phase end.
	 *
	 * @param array<string,mixed> $payload Optional event payload.
	 */
	private function record_activity_event(
		string $event_type,
		string $reason,
		string $status = 'info',
		string $phase = 'agent',
		string $summary = '',
		array $payload = array()
	): void {
		$this->activity_events[] = array(
			'event_type' => $event_type,
			'reason'     => $reason,
			'status'     => sanitize_key( $status ),
			'phase'      => sanitize_key( $phase ),
			'summary'    => $summary,
			'payload'    => $payload,
		);
	}

	/**
	 * Map raw provider stop reasons into the shared vocabulary.
	 */
	private function canonical_stop_reason( string $stop_reason ): string {
		return match ( sanitize_key( $stop_reason ) ) {
			'tool_use', 'tool_calls' => 'stop_tool_use',
			'end_turn', 'stop', 'finished', 'complete' => 'stop_end_turn',
			'max_tokens', 'length' => 'stop_max_tokens',
			default => 'state_change',
		};
	}

	/**
	 * Resolve the permission context used for tool exposure in this run.
	 */
	private function tool_permission_context(): string {
		if ( $this->automation_context ) {
			return class_exists( 'PressArk_Policy_Engine' )
				? PressArk_Policy_Engine::CONTEXT_AUTOMATION
				: 'automation';
		}

		return class_exists( 'PressArk_Policy_Engine' )
			? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
			: 'interactive';
	}

	/**
	 * Build permission metadata for tool exposure and discovery.
	 */
	private function tool_permission_meta(): array {
		$meta = array(
			'tier'    => $this->tier,
			'run_id'  => $this->run_id,
			'chat_id' => $this->chat_id,
		);

		if ( function_exists( 'get_current_user_id' ) ) {
			$meta['user_id'] = (int) get_current_user_id();
		}

		if ( $this->automation_context ) {
			$meta['policy'] = sanitize_key(
				(string) (
					$this->automation_context['approval_policy']
					?? $this->automation_context['policy']
					?? PressArk_Automation_Policy::POLICY_EDITORIAL
				)
			);
		}

		return array_filter(
			$meta,
			static function ( $value ) {
				return ! ( is_string( $value ) && '' === $value );
			}
		);
	}

	/**
	 * Run the agentic loop for a user message.
	 *
	 * Correct agentic loop protocol:
	 * 1. Always append the FULL assistant response before processing tool calls
	 * 2. Use stop_reason from the raw API response as the exit condition
	 * 3. Bundle ALL tool results into the correct provider format
	 * 4. Execute reads first when mixed with writes, then pause for writes
	 *
	 * @param string $message       User's message.
	 * @param array  $conversation  Full conversation history.
	 * @param bool   $deep_mode     Deep mode active?
	 * @param string $screen        Current admin screen slug.
	 * @param int    $post_id       Current post ID (0 if not on post editor).
	 * @param array  $loaded_groups Groups loaded in previous turns (v2.3.1).
	 * @return array {
	 *   type: 'final_response' | 'preview' | 'confirm_card',
	 *   message: string,
	 *   steps: array,
	 *   preview_session_id?: string,
	 *   preview_url?: string,
	 *   diff?: array,
	 *   pending_actions?: array,
	 *   tokens_used: int,
	 *   loaded_groups: array,
	 *   tool_loading: array
	 * }
	 */
	public function run(
		string $message,
		array  $conversation,
		bool   $deep_mode = false,
		string $screen    = '',
		int    $post_id   = 0,
		array  $loaded_groups = array(),
		?array $checkpoint_data = null,
		?callable $cancel_check_override = null
	): array {
		$ai_call = fn( array $msgs, array $tools, string $sys, bool $deep ) =>
			$this->ai->send_message_raw( $msgs, $tools, $sys, $deep );

		$emit_fn = static function ( string $type, array $data ): void {
			// No-op for non-streaming — steps are already tracked in $this->steps.
		};

		$cancel_check = $cancel_check_override ?? static fn(): bool => false;

		return $this->execute_loop(
			$ai_call,
			$emit_fn,
			$cancel_check,
			$message, $conversation, $deep_mode, $screen, $post_id, $loaded_groups, $checkpoint_data
		);
	}

	/**
	 * Run the agentic loop with SSE streaming.
	 *
	 * Structurally identical to run() but uses the stream connector for AI
	 * calls (tokens stream in real-time) and emits step events via SSE.
	 *
	 * @since 4.4.0
	 */
	public function run_streaming(
		string $message,
		array  $conversation,
		bool   $deep_mode,
		string $screen,
		int    $post_id,
		array  $loaded_groups,
		?array $checkpoint_data,
		PressArk_Stream_Connector $stream_connector,
		PressArk_SSE_Emitter $emitter,
		?callable $cancel_check_override = null
	): array {
		$ai_call = fn( array $msgs, array $tools, string $sys, bool $deep ) =>
			$stream_connector->send_streaming( $msgs, $tools, $sys, $deep );

		$emit_fn = static function ( string $type, array $data ) use ( $emitter ): void {
			if ( $emitter->is_connected() ) {
				$emitter->emit( $type, $data );
			}
		};

		$cancel_check = $cancel_check_override ?? static fn(): bool => ! $emitter->check_connection();

		return $this->execute_loop(
			$ai_call,
			$emit_fn,
			$cancel_check,
			$message, $conversation, $deep_mode, $screen, $post_id, $loaded_groups, $checkpoint_data
		);
	}

	/**
	 * Core agentic loop — shared by run() and run_streaming().
	 *
	 * @param callable $ai_call      ($messages, $tools, $system_prompt, $deep_mode) => array (same shape as send_message_raw)
	 * @param callable $emit_fn      ($type, $data) => void (SSE emit for streaming, no-op otherwise)
	 * @param callable $cancel_check () => bool — returns true when the client has disconnected (streaming) or request is cancelled.
	 *
	 * @since 4.4.0
	 */
	private function execute_loop(
		callable $ai_call,
		callable $emit_fn,
		callable $cancel_check,
		string   $message,
		array    $conversation,
		bool     $deep_mode,
		string   $screen,
		int      $post_id,
		array    $loaded_groups,
		?array   $checkpoint_data
	): array {
		$round_limit  = PressArk_Entitlements::max_agent_rounds( $this->tier );
		$token_budget = (int) PressArk_Entitlements::tier_value( $this->tier, 'agent_token_budget' );

		// v5.0.1: Wall-clock deadline prevents runaway loops even when
		// round count and token budget are within limits.
		$deadline = microtime( true ) + self::LOOP_TIMEOUT_SECONDS;

		$this->tokens_used         = 0;
		$this->output_tokens_used  = 0;
		$this->input_tokens_used   = 0;
		$this->cache_read_tokens   = 0;
		$this->cache_write_tokens  = 0;
		$this->icu_spent           = 0;
		$this->model_rounds        = 0;
		$this->actual_provider     = '';
		$this->actual_model        = '';
		$this->steps               = array();
		$this->budget_manager      = null;
		$this->budget_report       = array();
		$this->history_budget      = array();
		$this->activity_events     = array();
		$this->routing_decision    = array();
		$this->context_inspector   = array();
		$this->loaded_groups       = $loaded_groups;
		$this->discover_calls      = 0;
		$this->discover_zero_hits  = 0;
		$this->load_calls          = 0;
		$this->idle_rounds         = 0;
		$this->last_tool_signature = '';
		$round                     = 0;

		// v2.4.0: Structured conversation checkpoint.
		$checkpoint = ! empty( $checkpoint_data )
			? PressArk_Checkpoint::from_array( $checkpoint_data )
			: new PressArk_Checkpoint();
		$checkpoint->sync_execution_goal( $message );

		// v4.3.2: Planner call. Same cheap model as v4.3.1 classifier, but
		// includes the capability map so the model can see all available tool
		// domains and return a structured execution plan with steps + groups.
		// Saves 7-17K tokens by eliminating discover→load round-trips.
		// Falls back to local regex on failure.
		$plan = $this->plan_with_ai( $message, $conversation );
		$task_type = $plan['task_type'];
		$this->task_type = $task_type;
		$this->plan_steps = $plan['steps'];
		$this->plan_step = 1;

		// v5.2.0: Surface execution plan for multi-step transparency.
		if ( count( $this->plan_steps ) >= 2 ) {
			$plan_items = array();
			foreach ( $this->plan_steps as $i => $step ) {
				$plan_items[] = array(
					'index'  => $i + 1,
					'text'   => $step,
					'status' => 0 === $i ? 'active' : 'pending',
				);
			}
			$this->steps[] = array(
				'type'    => 'plan',
				'content' => $plan_items,
			);
			$emit_fn( 'plan', $plan_items );

			// v5.3.0: Transition plan state — mark the plan as approved and
			// ready for execution when multi-step plans are generated.
			$checkpoint->set_plan_phase( 'executing' );
			$checkpoint->set_plan_text( implode( "\n", $this->plan_steps ) );
		}

		// v5.3.0: Mark the first actionable task as in_progress.
		$this->advance_task_graph( $checkpoint );

		$this->ai->resolve_for_task( $task_type, $deep_mode );
		$this->budget_manager = $this->ai->build_token_budget_manager();
		$this->history_budget = $this->budget_manager->recommended_history_config( $deep_mode );

		// Compressed history (v2.4.0: checkpoint-aware, v5.4.0 budget-aware).
		$history = PressArk_History_Manager::prepare(
			$conversation,
			$deep_mode,
			$checkpoint,
			$this->history_budget
		);

		// Build initial messages array from compressed history or durable
		// replay state when a continuation resumes from a checkpoint.
		$messages = $this->build_initial_loop_messages( $history, $message, $checkpoint );

		// Local heuristics guarantee critical domains (like content editing)
		// are available even when the planner returns a conservative group set.
		$preload_groups = $this->refine_preload_groups(
			$message,
			$task_type,
			array_slice(
				array_values(
					array_unique(
						array_merge(
							self::detect_preload_groups( $message, $task_type ),
							(array) ( $plan['groups'] ?? array() )
						)
					)
				),
				0,
				3
			)
		);

		// v2.3.1: Start with base tools + sticky conversation-scoped groups.
		// v5.4.0: Heuristic preloads are admitted only if they fit the
		// current prompt budget.
		$loader       = new PressArk_Tool_Loader();
		$loader_args  = array(
			'candidate_groups'      => $preload_groups,
			'budget_manager'        => $this->budget_manager,
			'conversation_messages' => $messages,
			'permission_context'    => $this->tool_permission_context(),
			'permission_meta'       => $this->tool_permission_meta(),
		);
		$tool_set     = $this->ai->supports_tool_search()
			? $loader->resolve_native_search(
				$this->tier,
				array_merge(
					$loader_args,
					array(
						'loaded_groups' => $this->loaded_groups,
					)
				)
			)
			: $loader->resolve( $message, $conversation, $this->tier, $this->loaded_groups, $loader_args );

		$tool_defs                = $tool_set['schemas'];
		$this->initial_tool_count = $tool_set['tool_count'];
		$this->budget_report      = (array) ( $tool_set['budget'] ?? array() );

		// Track preloaded groups in loaded_groups for subsequent rounds.
		$this->loaded_groups = $tool_set['groups'];

		// Record initial groups for observability.
		$initial_groups = $tool_set['groups'];

		// ─── THE AGENTIC LOOP ────────────────────────────────────────────────
		$build_cancelled_result = fn(): array => $this->build_result( array(
			'type'      => 'final_response',
			'message'   => '',
			'cancelled' => true,
		), $tool_set, $initial_groups, $checkpoint );
		$leaked_retry           = false;

		while ( $round < $round_limit && $this->output_tokens_used < $token_budget ) {
			$round++;

			// v5.0.1: Wall-clock timeout check.
			if ( microtime( true ) >= $deadline ) {
				return $this->build_result( array(
					'type'    => 'final_response',
					'message' => 'This request took longer than expected and was stopped to protect your token budget. You can continue the conversation to pick up where it left off.',
				), $tool_set, $initial_groups, $checkpoint );
			}

			// Client disconnect check — exit early to stop burning tokens.
			if ( $cancel_check() ) {
				return $build_cancelled_result();
			}

			// v4.3.0: Tier-based mid-loop compaction thresholds.
			// Free tier compacts sooner to keep rounds cheaper.
			$compaction_threshold = match ( true ) {
				PressArk_Entitlements::is_paid_tier( $this->tier )
					&& in_array( $this->tier, array( 'team', 'agency', 'enterprise' ), true ) => 12000,
				PressArk_Entitlements::is_paid_tier( $this->tier ) => 8000,
				default => 5000,
			};
			$estimated_tokens = $this->estimate_messages_tokens( $messages );
			$system_prompt    = $this->build_round_system_prompt(
				$screen,
				$post_id,
				$message,
				$task_type,
				$tool_set,
				$checkpoint,
				$messages
			);
			$request_budget   = (array) ( $tool_set['budget'] ?? array() );

			if ( $estimated_tokens > $compaction_threshold || $this->should_compact_live_context( $messages, $token_budget, $estimated_tokens, $request_budget ) ) {
				$compaction_reason = $this->resolve_live_compaction_reason( $estimated_tokens, $compaction_threshold, $request_budget );
				$messages          = $this->compact_loop_messages( $messages, $round, $checkpoint, $request_budget, $compaction_reason );
				$estimated_tokens  = $this->estimate_messages_tokens( $messages );
				$system_prompt     = $this->build_round_system_prompt(
					$screen,
					$post_id,
					$message,
					$task_type,
					$tool_set,
					$checkpoint,
					$messages
				);
				$request_budget    = (array) ( $tool_set['budget'] ?? array() );
			} elseif ( $this->should_prime_context_capsule( $messages, $checkpoint, $token_budget, $request_budget ) ) {
				$this->prime_context_capsule( $checkpoint, $messages, $round );
			}

			if ( $round > 1 && $this->should_pause_for_request_budget( $request_budget ) ) {
				$this->emit_step( 'compressing_context', '_context_compaction', array() );
				$emit_fn(
					'step',
					array(
						'status' => 'compressing_context',
						'label'  => 'Compressing context…',
						'tool'   => '_context_compaction',
					)
				);
				$this->prime_context_capsule( $checkpoint, $messages, $round, true );
				$this->record_compaction_pause( $checkpoint, $messages, $round, 'request_headroom_pause', $request_budget );
				return $this->build_result( array(
					'type'                => 'final_response',
					'message'             => '',
					'hit_limit'           => true,
					'exit_reason'         => 'max_request_icus_compacted',
					'silent_continuation' => true,
				), $tool_set, $initial_groups, $checkpoint );
			}

			$repair_context = $this->repair_replay_messages( $messages, $checkpoint, 'provider_call', $round );
			$messages       = $repair_context['messages'];
			$estimated_tokens = $this->estimate_messages_tokens( $messages );
			$system_prompt    = $this->build_round_system_prompt(
				$screen,
				$post_id,
				$message,
				$task_type,
				$tool_set,
				$checkpoint,
				$messages
			);

			// Debug logging (enable via PRESSARK_DEBUG constant).
			if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
				PressArk_Error_Tracker::debug( 'Agent', sprintf( 'Round %d | Messages: %d | OutputTokens: %d/%d | TotalTokens: %d | EstInput: %d', $round, count( $messages ), $this->output_tokens_used, $token_budget, $this->tokens_used, $estimated_tokens ) );
			}

			// Call API — returns raw response + effective provider string.
			$api_result = $ai_call( $messages, $tool_defs, $system_prompt, $deep_mode );
			$raw        = $api_result['raw'];
			$provider   = $api_result['provider'];
			if ( ! empty( $api_result['request_made'] ) ) {
				$this->model_rounds++;
			}

			if ( ! empty( $api_result['cancelled'] ) || ! empty( $raw['__pressark_cancelled'] ) ) {
				return $build_cancelled_result();
			}

			// Capture actual provider/model from first successful round.
			if ( empty( $this->actual_provider ) ) {
				$this->actual_provider = $provider;
				$this->actual_model    = (string) ( $api_result['model'] ?? $this->ai->get_model() );
			}

			$this->context_inspector = $this->build_request_context_snapshot( $round, $tool_set, $messages, $checkpoint );

			if ( ! empty( $api_result['routing_decision'] ) && empty( $this->routing_decision ) ) {
				$this->routing_decision = (array) $api_result['routing_decision'];
				$this->record_activity_event(
					'provider.routing',
					'routing_decision_recorded',
					'observed',
					'provider',
					'Recorded the provider and model route chosen for this run.',
					array(
						'round'               => $round,
						'provider'            => (string) ( $this->routing_decision['provider'] ?? $provider ),
						'model'               => (string) ( $this->routing_decision['model'] ?? $this->actual_model ),
						'routing_basis'       => (string) ( $this->routing_decision['selection']['basis'] ?? '' ),
						'requires_tools'      => ! empty( $this->routing_decision['capability_assumptions']['requires_tools'] ),
						'supports_tool_search'=> ! empty( $this->routing_decision['capability_assumptions']['supports_tool_search'] ),
						'fallback_candidates' => (array) ( $this->routing_decision['fallback']['considered'] ?? array() ),
					)
				);
			}

			if ( ! empty( $api_result['fallback_used'] ) ) {
				$this->record_activity_event(
					'provider.fallback',
					'fallback_model_policy',
					'degraded',
					'provider',
					'Provider call fell back to an alternate model candidate.',
					array(
						'round'    => $round,
						'provider' => $provider,
						'model'    => (string) ( $api_result['model'] ?? $this->ai->get_model() ),
						'attempts' => (int) ( $api_result['attempts'] ?? 0 ),
					)
				);
			}

			// Error handling.
			if ( ! empty( $raw['error'] ) && ! isset( $raw['choices'] ) && ! isset( $raw['content'] ) ) {
				$error_msg     = is_string( $raw['error'] ) ? $raw['error'] : ( $raw['error']['message'] ?? 'Unknown API error' );
				$failure_class = $api_result['failure_class'] ?? PressArk_AI_Connector::FAILURE_PROVIDER_ERROR;
				return $this->build_result( array(
					'type'          => 'final_response',
					'message'       => $error_msg,
					'is_error'      => true,
					'failure_class' => $failure_class,
				), $tool_set, $initial_groups, $checkpoint );
			}

			// Track tokens from raw response.
			$round_output_tokens       = $this->ai->extract_output_usage( $raw, $provider );
			$round_input_tokens        = (int) ( $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0 );
			$this->tokens_used        += $this->ai->extract_usage( $raw, $provider );
			$this->output_tokens_used += $round_output_tokens;
			$this->input_tokens_used  += $round_input_tokens;

			// Accumulate cache metrics.
			$cache = $api_result['cache_metrics'] ?? array();
			$this->cache_read_tokens  += (int) ( $cache['cache_read'] ?? 0 );
			$this->cache_write_tokens += (int) ( $cache['cache_write'] ?? 0 );

			$round_model      = (string) ( $api_result['model'] ?? $this->ai->get_model() );
			$round_multiplier = PressArk_Model_Policy::get_model_multiplier( $round_model );
			$this->icu_spent += (int) ceil(
				( $round_input_tokens * (int) ( $round_multiplier['input'] ?? 10 ) )
				+ ( $round_output_tokens * (int) ( $round_multiplier['output'] ?? 30 ) )
			);

			// Budget check after API response — prevent overspend.
			if ( $this->output_tokens_used >= $token_budget ) {
				$this->prime_context_capsule( $checkpoint, $messages, $round );
				$this->record_compaction_pause( $checkpoint, $messages, $round, 'token_budget_pause', $request_budget );
				$text = $this->ai->extract_text( $raw, $provider );
				return $this->build_result( array(
					'type'        => 'final_response',
					'message'     => $text ?: 'I reached the token budget for this session. Here\'s what I found so far — you can continue in a follow-up message.',
					'hit_limit'   => true,
					'exit_reason' => 'token_budget',
				), $tool_set, $initial_groups, $checkpoint );
			}

			// Get stop reason from raw response (source of truth).
			$stop_reason = $this->ai->extract_stop_reason( $raw, $provider );
			$assistant_text = $this->ai->extract_text( $raw, $provider );
			$assistant_tool_calls = 'tool_use' === $stop_reason
				? $this->ai->extract_tool_calls( $raw, $provider )
				: array();
			$this->record_activity_event(
				'provider.stop',
				$this->canonical_stop_reason( $stop_reason ),
				'observed',
				'provider',
				'Observed a provider stop reason for this round.',
				array(
					'round'      => $round,
					'provider'   => $provider,
					'stop_reason'=> sanitize_key( $stop_reason ),
					'tool_calls' => count( $assistant_tool_calls ),
				)
			);

			// RULE 1: Always append full assistant response BEFORE processing tool calls.
			$messages[] = $this->ai->build_assistant_message( $raw, $provider );
			$this->sync_replay_snapshot( $checkpoint, $messages );
			$this->observe_post_compaction_turn(
				$checkpoint,
				$round,
				$stop_reason,
				$assistant_tool_calls,
				$assistant_text,
				$request_budget
			);

			if ( 'tool_use' === $stop_reason && $this->should_pause_for_request_budget( $request_budget ) ) {
				$this->emit_step( 'compressing_context', '_context_compaction', array() );
				$emit_fn(
					'step',
					array(
						'status' => 'compressing_context',
						'label'  => 'Compressing context…',
						'tool'   => '_context_compaction',
					)
				);
				$this->prime_context_capsule( $checkpoint, $messages, $round, true );
				$this->record_compaction_pause( $checkpoint, $messages, $round, 'request_headroom_pause', $request_budget );
				return $this->build_result( array(
					'type'                => 'final_response',
					'message'             => '',
					'hit_limit'           => true,
					'exit_reason'         => 'max_request_icus_compacted',
					'silent_continuation' => true,
				), $tool_set, $initial_groups, $checkpoint );
			}

			if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
				PressArk_Error_Tracker::debug( 'Agent', sprintf( 'Round %d | Provider: %s | StopReason: %s', $round, $provider, $stop_reason ) );
			}

			// ── FINAL ANSWER ─────────────────────────────────────────────────
			$tool_calls = array();

			if ( 'tool_use' !== $stop_reason ) {
				$text     = $assistant_text;
				$recovery = $this->maybe_recover_leaked_tool_call( $text, $messages, $round, $leaked_retry, $emit_fn );
				if ( ! empty( $recovery['retry'] ) ) {
					continue;
				}
				if ( ! empty( $recovery['reconstructed_tool_call'] ) ) {
					$tool_calls = array( $recovery['reconstructed_tool_call'] );
				} elseif ( ! empty( $recovery ) ) {
					return $this->build_result( $recovery, $tool_set, $initial_groups, $checkpoint );
				} else {
					if ( empty( $text ) && $round > 1 ) {
						$text = 'I reviewed the data but wasn\'t able to produce a useful response. Try rephrasing or providing more detail about what you need.';
					}
					return $this->build_result( array(
						'type'    => 'final_response',
						'message' => $text,
					), $tool_set, $initial_groups, $checkpoint );
				}
			}

			// ── TOOL CALLS ───────────────────────────────────────────────────
			if ( 'tool_use' === $stop_reason ) {
				$tool_calls = $assistant_tool_calls;

				if ( empty( $tool_calls ) ) {
					// stop_reason was tool_use but no tool calls extracted — parse error.
					$text     = $this->ai->extract_text( $raw, $provider );
					$recovery = $this->maybe_recover_leaked_tool_call( $text, $messages, $round, $leaked_retry, $emit_fn );
					if ( ! empty( $recovery['retry'] ) ) {
						continue;
					}
					if ( ! empty( $recovery['reconstructed_tool_call'] ) ) {
						$tool_calls = array( $recovery['reconstructed_tool_call'] );
					} elseif ( ! empty( $recovery ) ) {
						return $this->build_result( $recovery, $tool_set, $initial_groups, $checkpoint );
					} else {
						return $this->build_result( array(
							'type'    => 'final_response',
							'message' => $text ?: 'The AI returned an unexpected response format. No changes were made. Please try again.',
							'error'   => 'tool_call_parse_failure',
						), $tool_set, $initial_groups, $checkpoint );
					}
				}
			}

			// Emit tool_call events for streaming clients.
			foreach ( $tool_calls as $tc ) {
				$emit_fn( 'tool_call', array(
					'id'   => $tc['id'] ?? '',
					'name' => $tc['name'] ?? '',
					'args' => $tc['arguments'] ?? array(),
				) );
			}

			// Continuation safety: if the run already completed a non-idempotent
			// step (for example create_post), do not let the model replay it.
			$duplicate_results = array();
			if ( self::is_continuation_message( $message ) ) {
				$tool_calls = $this->filter_duplicate_tool_calls( $tool_calls, $checkpoint, $duplicate_results );
				if ( ! empty( $duplicate_results ) ) {
					$this->append_tool_results( $messages, $duplicate_results, $provider );
					$this->sync_replay_snapshot( $checkpoint, $messages );
					if ( empty( $tool_calls ) ) {
						continue;
					}
				}
			}

			if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
				foreach ( $tool_calls as $tc ) {
					PressArk_Error_Tracker::debug( 'Agent', sprintf( 'Tool call: %s | ID: %s', $tc['name'], $tc['id'] ) );
				}
			}

			// Classify tool calls into read / preview / confirm groups.
			$read_calls    = array();
			$preview_calls = array();
			$confirm_calls = array();

			foreach ( $tool_calls as $tc ) {
				$class = self::classify_tool( $tc['name'], $tc['arguments'] ?? array() );

				if ( 'preview' === $class ) {
					$preview_calls[] = $tc;
				} elseif ( 'confirm' === $class ) {
					$confirm_calls[] = $tc;
				} else {
					$read_calls[] = $tc;
				}
			}

			// ── CASE A: All reads — execute and continue loop ────────────────
			if ( ! empty( $read_calls ) && empty( $preview_calls ) && empty( $confirm_calls ) ) {
				$tool_results = $this->execute_reads_orchestrated(
					$read_calls,
					$checkpoint,
					$round,
					$loader,
					$tool_set,
					$tool_defs,
					$emit_fn,
					$cancel_check
				);

				if ( null === $tool_results ) {
					return $build_cancelled_result();
				}

				$compacted = $this->prepare_tool_results_for_prompt( $tool_results, $round, $checkpoint );

				// RULE 3: ALL results in provider-correct format (compacted for loop).
				$this->append_tool_results( $messages, $compacted, $provider );
				$this->sync_replay_snapshot( $checkpoint, $messages );

				// v3.2.0: Spin detection — check if this round's tool calls
				// are identical to the previous round's (agent is looping).
				$sig = $this->build_tool_signature( $read_calls );
				if ( $sig === $this->last_tool_signature ) {
					$this->idle_rounds++;
					if ( $this->idle_rounds >= self::MAX_IDLE_ROUNDS ) {
						return $this->build_result( array(
							'type'    => 'final_response',
							'message' => 'I\'ve read the same data multiple times without making progress. Here\'s what I found so far. Try rephrasing your request or providing more specifics so I can take the right action.',
							'exit_reason' => 'spin_detected',
						), $tool_set, $initial_groups, $checkpoint );
					}
				} else {
					$this->idle_rounds = 0;
				}
				$this->last_tool_signature = $sig;

				// Continue loop — AI sees results and decides next step.
				continue;
			}

			// ── CASE B: Writes present — execute reads first, then pause ─────
			// If MIXED (some reads + some writes in same response):
			// Execute reads first, then pause for writes.
			if ( ! empty( $read_calls ) ) {
				$read_results = $this->execute_reads_orchestrated(
					$read_calls,
					$checkpoint,
					$round,
					$loader,
					$tool_set,
					$tool_defs,
					$emit_fn,
					$cancel_check
				);

				if ( null === $read_results ) {
					return $build_cancelled_result();
				}

				$compacted_reads = $this->prepare_tool_results_for_prompt( $read_results, $round, $checkpoint );

				$this->append_tool_results( $messages, $compacted_reads, $provider );
				$this->sync_replay_snapshot( $checkpoint, $messages );
			}

			// ── PREVIEWABLE WRITES → Live preview ────────────────────────────
			if ( ! empty( $preview_calls ) || ! empty( $confirm_calls ) ) {
				$this->has_proposed_write = true;
				// v4.3.2: Advance plan step when writes are proposed.
				if ( $this->plan_step <= count( $this->plan_steps ) ) {
					$this->plan_step++;
				}
			}
			if ( $cancel_check() ) {
				return $build_cancelled_result();
			}
			if ( ! empty( $preview_calls ) ) {
				$this->emit_step( 'preparing_preview', $preview_calls[0]['name'], $preview_calls[0]['arguments'] ?? array() );
				$emit_fn( 'step', array( 'status' => 'preparing_preview', 'label' => 'Preparing preview', 'tool' => $preview_calls[0]['name'] ) );

				$preview = new PressArk_Preview();
				$session = $preview->create_session( $preview_calls, $preview_calls[0]['arguments'] ?? array() );

				return $this->build_result( array(
					'type'               => 'preview',
					'message'            => $this->ai->extract_text( $raw, $provider ),
					'preview_session_id' => $session['session_id'],
					'preview_url'        => $session['signed_url'],
					'diff'               => $session['diff'],
					'pending_actions'    => $preview_calls,
				), $tool_set, $initial_groups, $checkpoint );
			}

			// ── CONFIRM CARD WRITES → Text diff card ─────────────────────────
			if ( ! empty( $confirm_calls ) ) {
				foreach ( $confirm_calls as $cc ) {
					$this->emit_step( 'needs_confirm', $cc['name'], $cc['arguments'] ?? array() );
					$emit_fn( 'step', array( 'status' => 'needs_confirm', 'label' => $this->get_step_label( $cc['name'], $cc['arguments'] ?? array() ), 'tool' => $cc['name'] ) );
				}

				return $this->build_result( array(
					'type'            => 'confirm_card',
					'message'         => $this->ai->extract_text( $raw, $provider ),
					'pending_actions' => $confirm_calls,
				), $tool_set, $initial_groups, $checkpoint );
			}
		}

		// v3.2.0: Explicit exit reason for observability.
		// v3.7.4: Differentiate free-tier (upgrade nudge) from paid-tier (safety ceiling).
		$exit_reason = $this->output_tokens_used >= $token_budget ? 'token_budget' : 'round_limit';
		if ( 'token_budget' === $exit_reason ) {
			$exit_msg = 'I reached the token budget for this session. Here\'s what I found so far — you can continue in a follow-up message.';
		} elseif ( PressArk_Entitlements::is_paid_tier( $this->tier ) ) {
			$exit_msg = 'I reached the safety step limit for this session. This usually means the task needs to be broken into smaller pieces — try a more focused request to finish up.';
		} else {
			$exit_msg = 'I reached the maximum number of steps for this request. Here\'s what I found so far — upgrade to a paid plan for longer multi-step tasks.';
		}
		$this->prime_context_capsule( $checkpoint, $messages, $round );
		return $this->build_result( array(
			'type'        => 'final_response',
			'message'     => $exit_msg,
			'hit_limit'   => true,
			'exit_reason' => $exit_reason,
		), $tool_set, $initial_groups, $checkpoint );
	}

	// ── Orchestrated Read Execution (v5.2.0) ────────────────────────────

	/**
	 * Execute read tool calls using batched orchestration.
	 *
	 * Analogous to Claude Code's partitionToolCalls + runToolsConcurrently:
	 * consecutive concurrency-safe reads are grouped into batches where all
	 * "reading" step events fire upfront and all "done" events fire after.
	 * Meta-tools and non-safe reads execute serially with full step events.
	 *
	 * Pre-processing extracts meta-tool results and bundle-hit stubs first
	 * (they have their own step-event handling), then passes only real
	 * execution calls to the orchestrator, then merges everything back in
	 * original model-emission order.
	 *
	 * @param array[]               $read_calls  Ordered read tool calls.
	 * @param PressArk_Checkpoint   $checkpoint  Current checkpoint.
	 * @param int                   $round       Current agent round.
	 * @param PressArk_Tool_Loader  $loader      Tool loader (for meta-tools).
	 * @param array                &$tool_set    Current tool set (meta-tools mutate).
	 * @param array                &$tool_defs   Current tool defs (meta-tools mutate).
	 * @param callable              $emit_fn     SSE step emitter.
	 * @param callable              $cancel_check Cancellation check.
	 * @return array[]|null Ordered results, or null if cancelled.
	 */
	private function execute_reads_orchestrated(
		array $read_calls,
		PressArk_Checkpoint $checkpoint,
		int $round,
		PressArk_Tool_Loader $loader,
		array &$tool_set,
		array &$tool_defs,
		callable $emit_fn,
		callable $cancel_check
	): ?array {
		// Phase 1: Pre-process — handle meta-tools and bundle-hits in order.
		// These produce immediate results with their own step events and must
		// not enter the orchestrator (meta-tools mutate tool_set/tool_defs).
		$slot_results   = array(); // position → result entry
		$execute_calls  = array(); // calls that need real execution
		$execute_indices = array(); // maps execute_calls index → original position

		foreach ( $read_calls as $i => $tc ) {
			if ( $cancel_check() ) {
				return null;
			}

			// Meta-tool fast path (handles its own step events).
			$meta_result = $this->handle_meta_tool( $tc, $loader, $tool_set, $tool_defs );
			if ( null !== $meta_result ) {
				$slot_results[ $i ] = array_merge( $meta_result, array( 'tool_name' => $tc['name'] ) );
				continue;
			}

			// Bundle-hit fast path (no step events needed).
			$bundle_stub = $this->check_bundle_hit( $checkpoint, $tc['name'], $tc['arguments'] ?? array() );
			if ( null !== $bundle_stub ) {
				$this->update_checkpoint_from_result( $checkpoint, $tc, $bundle_stub, $round );
				$slot_results[ $i ] = array(
					'tool_use_id' => $tc['id'],
					'tool_name'   => $tc['name'],
					'result'      => $bundle_stub,
				);
				continue;
			}

			// Needs real execution — send to orchestrator.
			$execute_indices[ count( $execute_calls ) ] = $i;
			$execute_calls[] = $tc;
		}

		// Phase 2: Partition and execute remaining calls.
		if ( ! empty( $execute_calls ) ) {
			$batches = PressArk_Read_Orchestrator::partition( $execute_calls );

			if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
				PressArk_Error_Tracker::debug(
					'Agent',
					'Read orchestration: ' . PressArk_Read_Orchestrator::describe_batches( $batches )
				);
			}

			// Execute callback: runs a single tool and records checkpoint + bundle.
			$exec_fn = function ( array $tc ) use ( $checkpoint, $round ): array {
				$result = $this->engine->execute_read( $tc['name'], $tc['arguments'] );
				$result = $this->enforce_tool_result_limit( $result, $tc['name'] );
				if ( class_exists( 'PressArk_Read_Metadata' ) && ! empty( $result['success'] ) ) {
					$result = PressArk_Read_Metadata::annotate_tool_result(
						$tc['name'],
						$tc['arguments'] ?? array(),
						$result
					);
				}

				// Checkpoint and bundle recording (safe within single-threaded PHP).
				$this->update_checkpoint_from_result( $checkpoint, $tc, $result, $round );
				if ( ! $this->is_tool_result_limit_result( $result ) ) {
					$this->record_bundle( $checkpoint, $tc['name'], $tc['arguments'] ?? array(), $result );
				}

				return $result;
			};

			// Step-event callback: emits reading/done events to SSE stream.
			$step_fn = function ( string $status, array $tc, ?array $result ) use ( $emit_fn ): void {
				if ( 'reading' === $status ) {
					$this->emit_step( 'reading', $tc['name'], $tc['arguments'] );
					$emit_fn( 'step', array(
						'status' => 'reading',
						'label'  => $this->get_step_label( $tc['name'], $tc['arguments'] ),
						'tool'   => $tc['name'],
					) );
				} elseif ( 'done' === $status && null !== $result ) {
					$this->emit_step( 'done', $tc['name'], $tc['arguments'], $result );
					$emit_fn( 'tool_result', array(
						'id'      => $tc['id'] ?? '',
						'name'    => $tc['name'],
						'success' => true,
						'summary' => $this->summarize_result( $result ),
					) );
				}
			};

			$orchestrated = PressArk_Read_Orchestrator::execute(
				$batches,
				$exec_fn,
				$step_fn,
				$cancel_check
			);

			if ( $orchestrated['cancelled'] ) {
				return null;
			}

			// Map orchestrated results back to their original positions.
			foreach ( $orchestrated['results'] as $j => $entry ) {
				$original_pos = $execute_indices[ $j ];
				$slot_results[ $original_pos ] = array_merge(
					$entry,
					array( 'tool_name' => $execute_calls[ $j ]['name'] ?? '' )
				);
			}
		}

		// Phase 3: Assemble results in deterministic original order.
		ksort( $slot_results );
		return array_values( $slot_results );
	}

	// ── Meta-Tool Handling (v2.3.1) ─────────────────────────────────────

	/**
	 * Handle meta-tools (discover_tools, load_tools, load_tool_group).
	 * Returns a tool result array if handled, or null for non-meta tools.
	 *
	 * @param array               $tc        Tool call from AI.
	 * @param PressArk_Tool_Loader $loader   Tool loader instance.
	 * @param array               &$tool_set Current tool set (modified by reference).
	 * @param array               &$tool_defs Current tool defs (modified by reference).
	 * @return array|null Tool result, or null if not a meta-tool.
	 */
	private function handle_meta_tool( array $tc, PressArk_Tool_Loader $loader, array &$tool_set, array &$tool_defs ): ?array {
		$name = $tc['name'];

		// ── discover_tools ──────────────────────────────────────────────
		if ( 'discover_tools' === $name ) {
			$this->discover_calls++;
			$query        = $tc['arguments']['query'] ?? '';
			$loaded_names = $tool_set['tool_names'] ?? array();

			// v3.2.0: Enforce discover call budget.
			if ( $this->discover_calls > self::MAX_DISCOVER_CALLS ) {
				$this->emit_step( 'reading', $name, $tc['arguments'] );
				$this->emit_step( 'done', $name, $tc['arguments'] );
				$this->record_activity_event(
					'tool.discovery',
					'discover_budget_reached',
					'degraded',
					'agent',
					'Tool discovery hit the configured call budget.',
					array(
						'query'          => sanitize_text_field( (string) $query ),
						'discover_calls' => $this->discover_calls,
						'loaded_groups'  => array_values( array_unique( $this->loaded_groups ) ),
					)
				);
				return array(
					'tool_use_id' => $tc['id'],
					'result'      => array(
						'success' => false,
						'message' => 'Discovery budget reached (' . self::MAX_DISCOVER_CALLS . ' calls). Work with your currently loaded tools or respond to the user with what you know.',
					),
				);
			}

			$results = PressArk_Tool_Catalog::instance()->discover(
				$query,
				$loaded_names,
				array(
					'permission_context' => $this->tool_permission_context(),
					'permission_meta'    => $this->tool_permission_meta(),
				)
			);

			if ( empty( $results ) ) {
				$this->discover_zero_hits++;
				$this->record_activity_event(
					'tool.discovery',
					$this->discover_zero_hits >= 3 ? 'discover_repeated_misfire' : 'discover_no_hits',
					$this->discover_zero_hits >= 3 ? 'degraded' : 'observed',
					'agent',
					$this->discover_zero_hits >= 3
						? 'Repeated discovery attempts failed to find a visible tool family.'
						: 'Discovery returned no visible candidates for this query.',
					array(
						'query'             => sanitize_text_field( (string) $query ),
						'zero_hit_count'    => $this->discover_zero_hits,
						'discover_calls'    => $this->discover_calls,
						'loaded_groups'     => array_values( array_unique( $this->loaded_groups ) ),
					'requested_families'=> PressArk_Tool_Catalog::instance()->match_groups( (string) $query ),
					)
				);
			}

			$tool_set = $loader->mark_discovered_tools(
				$tool_set,
				array_values( array_filter( array_map(
					static function ( array $row ): string {
						if ( 'resource' === (string) ( $row['type'] ?? '' ) ) {
							return '';
						}
						return sanitize_key( (string) ( $row['name'] ?? '' ) );
					},
					(array) $results
				) ) ),
				array(
					'permission_context' => $this->tool_permission_context(),
					'permission_meta'    => $this->tool_permission_meta(),
					'tier'               => $this->tier,
				)
			);

			$this->emit_step( 'reading', $name, $tc['arguments'] );

			// v3.2.0: Guided degradation instead of full-tool fallback.
			// After repeated misses, suggest available groups rather than loading everything.
			if ( empty( $results ) && $this->discover_zero_hits >= 3 ) {
				$available_groups = PressArk_Operation_Registry::group_names();
				$result_msg = 'No tools found for "' . $query . '" after multiple attempts. '
					. 'Available tool groups you can load directly: ' . implode( ', ', $available_groups ) . '. '
					. 'Use load_tools(group: "group_name") to load a specific group, or respond to the user with what you already know.';
			} elseif ( empty( $results ) ) {
				$result_msg = 'No tools found matching "' . $query . '". Try broader search terms, '
					. 'check available groups with get_available_tools, or use load_tools(group: "group_name") to load a whole group.';
			} else {
				$result_msg = wp_json_encode( $results );
			}

			// v3.6.0: Step-ordering hint for multi-step tasks.
			// When the task is "generate" and the model discovers SEO/analysis
			// tools before creating content, steer it back to creation first.
			if ( ! $this->has_proposed_write && 'generate' === $this->task_type && ! empty( $results ) ) {
				$is_seo_query = (bool) preg_match( '/\bseo\b|meta.?title|meta.?desc/i', $query );
				if ( $is_seo_query ) {
					$result_msg .= "\n\nNote: The user's request starts with content creation. "
						. 'Create the post/page first using your currently loaded tools '
						. '(create_post, read_content, etc.), then load SEO tools afterward '
						. 'to optimize it. SEO optimization requires the content to exist first.';
				}
			}

			$this->emit_step( 'done', $name, $tc['arguments'] );

			return array(
				'tool_use_id' => $tc['id'],
				'result'      => array(
					'success' => ! empty( $results ),
					'message' => $result_msg,
				),
			);
		}

		// ── load_tools ──────────────────────────────────────────────────
		if ( 'load_tools' === $name ) {
			$this->load_calls++;

			// v3.2.0: Enforce load call budget.
			if ( $this->load_calls > self::MAX_LOAD_CALLS ) {
				$this->emit_step( 'reading', $name, $tc['arguments'] );
				$this->emit_step( 'done', $name, $tc['arguments'] );
				return array(
					'tool_use_id' => $tc['id'],
					'result'      => array(
						'success' => false,
						'message' => 'Tool loading budget reached (' . self::MAX_LOAD_CALLS . ' calls). Work with your currently loaded tools: ' . implode( ', ', $tool_set['tool_names'] ?? array() ),
					),
				);
			}

			$group = $tc['arguments']['group'] ?? '';
			$tools = $tc['arguments']['tools'] ?? array();
			$msgs  = array();

			if ( ! empty( $group ) ) {
				$tool_set = $loader->expand(
					$tool_set,
					$group,
					array(
						'permission_context' => $this->tool_permission_context(),
						'permission_meta'    => $this->tool_permission_meta(),
						'tier'               => $this->tier,
					)
				);
				if ( ! in_array( $group, $this->loaded_groups, true ) ) {
					$this->loaded_groups[] = $group;
				}
				$msgs[] = sprintf( 'Group "%s" loaded.', $group );
			}

			if ( ! empty( $tools ) && is_array( $tools ) ) {
				$tool_set = $loader->expand_tools(
					$tool_set,
					$tools,
					array(
						'permission_context' => $this->tool_permission_context(),
						'permission_meta'    => $this->tool_permission_meta(),
						'tier'               => $this->tier,
					)
				);
				// Track any new groups.
				foreach ( $tool_set['groups'] as $g ) {
					if ( ! in_array( $g, $this->loaded_groups, true ) ) {
						$this->loaded_groups[] = $g;
					}
				}
				$msgs[] = sprintf( 'Tools loaded: %s', implode( ', ', $tools ) );
			}

			$tool_defs = $tool_set['schemas'];
			$this->emit_step( 'reading', $name, $tc['arguments'] );

			$loaded_msg = ! empty( $msgs )
				? implode( ' ', $msgs ) . ' Available tools: ' . implode( ', ', $tool_set['tool_names'] ?? array() )
				: 'No group or tools specified. Provide "group" or "tools" parameter.';

			$this->emit_step( 'done', $name, $tc['arguments'] );

			return array(
				'tool_use_id' => $tc['id'],
				'result'      => array(
					'success' => ! empty( $group ) || ! empty( $tools ),
					'message' => $loaded_msg,
				),
			);
		}

		// ── load_tool_group (legacy backward compat) ────────────────────
		if ( 'load_tool_group' === $name ) {
			$group    = $tc['arguments']['group'] ?? '';
			$tool_set = $loader->expand(
				$tool_set,
				$group,
				array(
					'permission_context' => $this->tool_permission_context(),
					'permission_meta'    => $this->tool_permission_meta(),
					'tier'               => $this->tier,
				)
			);
			$tool_defs = $tool_set['schemas'];
			if ( ! empty( $group ) && ! in_array( $group, $this->loaded_groups, true ) ) {
				$this->loaded_groups[] = $group;
			}

			$this->emit_step( 'reading', $name, $tc['arguments'] );
			$result = $this->engine->execute_read( $name, $tc['arguments'] );
			$this->emit_step( 'done', $name, $tc['arguments'], $result );

			return array(
				'tool_use_id' => $tc['id'],
				'result'      => $result,
			);
		}

		return null;
	}

	/**
	 * Build a standardized result array with tool loading metadata.
	 *
	 * @param array                    $data           Response-specific fields.
	 * @param array                    $tool_set       Current tool set.
	 * @param array                    $initial_groups Groups at start of session.
	 * @param PressArk_Checkpoint|null $checkpoint     Conversation checkpoint (v2.4.0).
	 * @return array Complete result array.
	 */
	private function build_result( array $data, array $tool_set, array $initial_groups, ?PressArk_Checkpoint $checkpoint = null ): array {
		$tool_state         = is_array( $tool_set['tool_state'] ?? null ) ? (array) $tool_set['tool_state'] : array();
		$loaded_groups      = array_values( array_unique(
			! empty( $this->loaded_groups ) ? $this->loaded_groups : ( $tool_set['groups'] ?? array() )
		) );
		$deferred_group_rows = array_values( array_filter(
			(array) ( $tool_set['deferred_groups'] ?? array() ),
			static function ( $candidate ): bool {
				return is_array( $candidate ) || is_string( $candidate );
			}
		) );
		$deferred_groups    = array_values( array_filter( array_map(
			static function ( $candidate ) {
				if ( is_array( $candidate ) ) {
					return (string) ( $candidate['group'] ?? $candidate['name'] ?? '' );
				}
				return is_string( $candidate ) ? $candidate : '';
			},
			$deferred_group_rows
		) ) );
		$harness_state      = $this->build_harness_state_snapshot( $tool_set, $checkpoint );

		$base = array_merge( $data, array(
			'steps'              => $this->steps,
			'tokens_used'        => $this->tokens_used,
			'icu_spent'          => $this->icu_spent,
			'input_tokens'       => $this->input_tokens_used,
			'output_tokens'      => $this->output_tokens_used,
			'cache_read_tokens'  => $this->cache_read_tokens,
			'cache_write_tokens' => $this->cache_write_tokens,
			'provider'           => $this->actual_provider,
			'model'              => $this->actual_model,
			'agent_rounds'       => $this->model_rounds,
			'task_type'          => $this->task_type,
			'suggestions'        => $this->generate_suggestions( $data['message'] ?? '' ),
			'loaded_groups'      => $loaded_groups,
			'tool_loading'  => array(
				'initial_groups'  => $initial_groups,
				'final_groups'    => $loaded_groups,
				'deferred_groups' => $deferred_groups,
				'deferred_group_rows' => $deferred_group_rows,
				'discover_calls'  => $this->discover_calls,
				'discover_zero_hits' => $this->discover_zero_hits,
				'load_calls'      => $this->load_calls,
				'initial_count'   => $this->initial_tool_count,
				'final_count'     => $tool_set['tool_count'] ?? 0,
				'visible_count'   => (int) ( $tool_state['visible_tool_count'] ?? count( (array) ( $tool_set['effective_visible_tools'] ?? array() ) ) ),
				'loaded_count'    => (int) ( $tool_state['loaded_tool_count'] ?? ( $tool_set['tool_count'] ?? 0 ) ),
				'searchable_count' => (int) ( $tool_state['searchable_tool_count'] ?? 0 ),
				'discovered_count' => (int) ( $tool_state['discovered_tool_count'] ?? 0 ),
				'blocked_count'    => (int) ( $tool_state['blocked_tool_count'] ?? 0 ),
				'capability_map_variant' => (string) ( $tool_set['capability_map_variant'] ?? '' ),
				'history_budget'  => $this->history_budget,
			),
			'budget' => ! empty( $tool_set['budget'] ) ? $tool_set['budget'] : $this->budget_report,
			'compaction' => $checkpoint ? (array) ( $checkpoint->get_context_capsule()['compaction'] ?? array() ) : array(),
			'plan' => array(
				'steps'        => $this->plan_steps,
				'current_step' => $this->plan_step,
			),
			'effective_visible_tools' => array_values( array_unique(
				(array) ( $tool_set['effective_visible_tools'] ?? $tool_set['tool_names'] ?? array() )
			) ),
			'permission_surface' => (array) ( $tool_set['permission_surface'] ?? array() ),
			'tool_state'         => $tool_state,
			'harness_state'      => $harness_state,
			'routing_decision'   => $this->routing_decision,
			'activity_events'    => $this->activity_events,
		) );
		$context_inspector = $this->build_context_inspector( $tool_set, $checkpoint );
		if ( ! empty( $context_inspector ) ) {
			$base['context_inspector'] = $context_inspector;
		}

		// v2.4.0: Include checkpoint for frontend round-trip.
		if ( $checkpoint && ! $checkpoint->is_empty() ) {
			$checkpoint->set_loaded_tool_groups( $this->loaded_groups );
			if ( in_array( (string) ( $data['type'] ?? '' ), array( 'preview', 'confirm_card' ), true )
				&& '' === $checkpoint->get_workflow_stage()
			) {
				$checkpoint->set_workflow_stage( 'preview' );
			}
			$base['checkpoint'] = $checkpoint->to_array();
			$replay_sidecar     = $checkpoint->get_replay_sidecar();
			if ( ! empty( $replay_sidecar ) ) {
				$base['replay'] = $replay_sidecar;
			}
		}

		return $base;
	}

	/**
	 * Generate contextual follow-up suggestion chips based on task type and response content.
	 *
	 * @param string $response_text The AI response text.
	 * @return string[] Up to 3 suggestion strings.
	 */
	private function generate_suggestions( string $response_text ): array {
		if ( '' === $response_text ) {
			return array();
		}

		$type = $this->task_type;

		if ( in_array( $type, array( 'analyze', 'diagnose' ), true ) ) {
			$suggestions = array();
			if ( false !== stripos( $response_text, 'seo' ) || false !== stripos( $response_text, 'meta' ) ) {
				$suggestions[] = 'Fix the SEO issues';
			}
			if ( false !== stripos( $response_text, 'security' ) || false !== stripos( $response_text, 'vulnerab' ) ) {
				$suggestions[] = 'Fix the security issues';
			}
			$suggestions[] = 'Auto-fix what you can';
			return array_slice( $suggestions, 0, 3 );
		}

		if ( 'generate' === $type ) {
			$suggestions = array( 'Publish it' );
			if ( false !== stripos( $response_text, 'word' ) || strlen( $response_text ) > 1500 ) {
				$suggestions[] = 'Make it shorter';
			} else {
				$suggestions[] = 'Make it longer';
			}
			$suggestions[] = 'Change the tone';
			return array_slice( $suggestions, 0, 3 );
		}

		if ( 'edit' === $type ) {
			return array( 'How does it look now?', 'Check the SEO too', 'Undo the changes' );
		}

		if ( 'code' === $type ) {
			return array( 'Run it again', 'Explain what changed', 'Check for issues' );
		}

		// Fallback for chat and other task types.
		return array( 'Check my SEO', 'Any issues on my site?', 'What\'s new in my store?' );
	}

	/**
	 * Detect leaked plain-text tool calls and give the model one corrective retry.
	 *
	 * @param string   $text          Extracted assistant text.
	 * @param array    &$messages     Conversation messages array.
	 * @param int      $round         Current execution round.
	 * @param bool     &$leaked_retry Whether leaked tool recovery has already been attempted.
	 * @param callable $emit_fn       SSE emit callback for streaming status to the client.
	 * @return array|null Retry sentinel, reconstructed tool call, final-response payload, or null when no leak was detected.
	 */
	private function maybe_recover_leaked_tool_call(
		string $text,
		array &$messages,
		int $round,
		bool &$leaked_retry,
		callable $emit_fn
	): ?array {
		if ( ! $this->has_leaked_tool_pattern( $text ) ) {
			return null;
		}

		if ( ! $leaked_retry ) {
			$leaked_retry = true;
			$this->record_activity_event(
				'run.retry_requested',
				'retry_format_leak',
				'retrying',
				'agent',
				'Retrying after a leaked plain-text tool call.',
				array(
					'round' => $round,
				)
			);

			PressArk_Error_Tracker::debug(
				'Agent',
				sprintf( 'Leaked tool call detected in round %d, re-prompting...', $round )
			);

			$emit_fn( 'step', array(
				'status' => 'reading',
				'label'  => 'Retrying with corrected format…',
				'tool'   => '_leaked_recovery',
			) );

			$messages[] = array(
				'role'    => 'user',
				'content' => 'Your previous response printed a tool call as plain text. Do not print tool calls, raw JSON, XML, or markdown representations of tools. If you need to act, use the native tool-calling interface. If no tool is needed, reply with a normal user-facing answer.',
			);

			return array( 'retry' => true );
		}

		$reconstructed_tool_call = $this->reconstruct_leaked_tool_call( $text );
		if ( ! empty( $reconstructed_tool_call ) ) {
			PressArk_Error_Tracker::debug(
				'Agent',
				sprintf( 'Recovered leaked tool call in round %d as %s', $round, $reconstructed_tool_call['name'] ?? 'unknown' )
			);

			return array(
				'reconstructed_tool_call' => $reconstructed_tool_call,
			);
		}

		return array(
			'type'    => 'final_response',
			'message' => 'I attempted to perform the requested action but encountered a formatting issue. Please try again or switch to a different AI model in PressArk settings for more reliable tool execution.',
			'error'   => 'leaked_tool_call_retry_exhausted',
		);
	}

	/**
	 * Reconstruct a leaked confirm-card style tool call from plain text.
	 *
	 * @param string $text Assistant text content.
	 * @return array|null
	 */
	private function reconstruct_leaked_tool_call( string $text ): ?array {
		$native_tool_call = $this->reconstruct_parenthesized_tool_call( $text );
		if ( ! empty( $native_tool_call ) ) {
			return $native_tool_call;
		}

		if ( ! preg_match( '/\b(?:post_id|post\s+id|review\s+id|comment\s+id|ID)\b[):.\s]*[:=#-]?\s*(\d+)\b/i', $text, $post_id_match ) ) {
			return null;
		}

		if ( ! preg_match( '/\baction\s*:\s*(publish|edit|delete|update|create|reply)\b/i', $text, $action_match ) ) {
			return null;
		}

		$post_id = absint( $post_id_match[1] ?? 0 );
		$action  = strtolower( $action_match[1] ?? '' );

		if ( $post_id <= 0 || '' === $action ) {
			return null;
		}

		$tool_call = array(
			'id'        => 'recovered_' . substr( md5( $action . ':' . $post_id . ':' . $text ), 0, 12 ),
			'name'      => '',
			'arguments' => array(
				'post_id' => $post_id,
			),
		);

		if ( 'delete' === $action ) {
			$tool_call['name'] = 'delete_content';
			return $tool_call;
		}

		$changes = array();

		if ( 'publish' !== $action ) {
			$field_map = array(
				'Title'          => 'title',
				'Content'        => 'content',
				'Excerpt'        => 'excerpt',
				'Slug'           => 'slug',
				'Status'         => 'status',
				'Scheduled Date' => 'scheduled_date',
			);

			foreach ( $field_map as $label => $field_key ) {
				$value = $this->extract_labeled_tool_value( $text, $label );
				if ( null === $value || '' === $value ) {
					continue;
				}

				if ( 'status' === $field_key ) {
					$value = strtolower( $value );
				}

				$changes[ $field_key ] = $value;
			}
		}

		if ( 'publish' === $action ) {
			$changes['status'] = 'publish';
		}

		if ( empty( $changes ) ) {
			return null;
		}

		$tool_call['name']                 = 'edit_content';
		$tool_call['arguments']['changes'] = $changes;

		return $tool_call;
	}

	/**
	 * Reconstruct leaked native-style read calls such as read_content(id=33).
	 *
	 * Only read-class tools are recovered here so the safety profile stays
	 * aligned with the existing automatic read orchestration.
	 *
	 * @param string $text Assistant text content.
	 * @return array|null
	 */
	private function reconstruct_parenthesized_tool_call( string $text ): ?array {
		$tool_names = array_merge(
			array_keys( PressArk_Operation_Registry::all() ),
			array_keys( PressArk_Operation_Registry::get_aliases() )
		);
		$tool_names = array_values( array_unique( array_filter( array_map( 'sanitize_key', $tool_names ) ) ) );

		if ( empty( $tool_names ) ) {
			return null;
		}

		$escaped_names = array_map(
			static fn( string $name ): string => preg_quote( $name, '/' ),
			$tool_names
		);
		$tool_pattern = implode( '|', $escaped_names );

		if ( ! preg_match( '/@?\b(' . $tool_pattern . ')\s*\(([^()]*)\)/i', $text, $matches ) ) {
			return null;
		}

		$tool_name = PressArk_Operation_Registry::resolve_alias( sanitize_key( (string) ( $matches[1] ?? '' ) ) );
		$arguments = $this->parse_parenthesized_tool_arguments( (string) ( $matches[2] ?? '' ) );

		if ( isset( $arguments['id'] ) && ! isset( $arguments['post_id'] ) ) {
			$arguments['post_id'] = $arguments['id'];
		}
		if ( isset( $arguments['page_id'] ) && ! isset( $arguments['post_id'] ) ) {
			$arguments['post_id'] = $arguments['page_id'];
		}
		unset( $arguments['id'], $arguments['page_id'] );

		if ( '' === $tool_name || 'read' !== self::classify_tool( $tool_name, $arguments ) ) {
			return null;
		}

		return array(
			'id'        => 'recovered_' . substr( md5( $tool_name . ':' . wp_json_encode( $arguments ) . ':' . $text ), 0, 12 ),
			'name'      => $tool_name,
			'arguments' => $arguments,
		);
	}

	/**
	 * Parse a simple key=value argument list from leaked parenthesized tool text.
	 *
	 * @param string $argument_string Raw argument substring.
	 * @return array<string,mixed>
	 */
	private function parse_parenthesized_tool_arguments( string $argument_string ): array {
		$arguments = array();

		if ( ! preg_match_all(
			'/([a-z_][a-z0-9_]*)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,\s)]+)/i',
			$argument_string,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $arguments;
		}

		foreach ( $matches as $match ) {
			$key   = sanitize_key( (string) ( $match[1] ?? '' ) );
			$value = trim( (string) ( $match[2] ?? '' ) );

			if ( '' === $key || '' === $value ) {
				continue;
			}

			$arguments[ $key ] = $this->normalize_leaked_argument_value( $value );
		}

		return $arguments;
	}

	/**
	 * Normalize a leaked argument token into a scalar PHP value.
	 *
	 * @param string $value Raw token value.
	 * @return mixed
	 */
	private function normalize_leaked_argument_value( string $value ) {
		$value = trim( $value );

		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}

		$lower = strtolower( $value );
		if ( 'true' === $lower ) {
			return true;
		}
		if ( 'false' === $lower ) {
			return false;
		}

		if (
			( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) )
			|| ( str_starts_with( $value, '\'' ) && str_ends_with( $value, '\'' ) )
		) {
			$value = substr( $value, 1, -1 );
			$value = stripcslashes( $value );
		}

		return $value;
	}

	/**
	 * Extract the value of a labeled line block from leaked confirm-card text.
	 *
	 * @param string $text  Assistant text content.
	 * @param string $label Field label to extract.
	 * @return string|null
	 */
	private function extract_labeled_tool_value( string $text, string $label ): ?string {
		$pattern = '/(?:^|\R)\s*' . preg_quote( $label, '/' ) . '\s*:\s*(.+?)(?=\R\s*[A-Za-z][A-Za-z ]{0,40}\s*:|\z)/is';
		if ( ! preg_match( $pattern, $text, $matches ) ) {
			return null;
		}

		$value = trim( (string) ( $matches[1] ?? '' ) );
		return '' === $value ? null : $value;
	}

	/**
	 * Detect common leaked tool-call formats in model text output.
	 *
	 * This is detection-only. Leaked tool calls are never parsed or executed.
	 *
	 * @param string $text Assistant text content.
	 * @return bool
	 */
	private function has_leaked_tool_pattern( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text || strlen( $text ) < 20 ) {
			return false;
		}

		if ( false !== strpos( $text, '[PRESSARK_CONFIRM_CARD]' ) || false !== strpos( $text, '[PRESSARK_CARD' ) ) {
			return true;
		}

		if (
			preg_match( '/\baction\s*:\s*(publish|edit|delete|update|create|reply|fix|bulk)\b/i', $text )
			&& preg_match( '/\b(?:post_id|post\s+id)\b\s*[:=#-]?\s*\d+\b/i', $text )
		) {
			return true;
		}

		if ( preg_match( '/@"[a-z0-9_]+"\s+json\s*[{[]/i', $text ) ) {
			return true;
		}

		if ( preg_match( '/<(tool_call|function_call)\b/i', $text ) ) {
			return true;
		}

		if ( preg_match_all( '/```(?:json|tool_call|function_call)?\s*([\s\S]*?)```/i', $text, $matches ) ) {
			foreach ( $matches[1] as $block ) {
				$block = trim( (string) $block );
				if ( '' === $block ) {
					continue;
				}

				$decoded = json_decode( $block, true );
				if ( JSON_ERROR_NONE === json_last_error() && $this->has_named_arguments_payload( $decoded ) ) {
					return true;
				}

				if ( preg_match( '/["\']name["\']\s*:/i', $block ) && preg_match( '/["\']arguments["\']\s*:/i', $block ) ) {
					return true;
				}
			}
		}

		$decoded = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() && $this->has_named_arguments_payload( $decoded ) ) {
			return true;
		}

		$tool_names = array_merge(
			array_keys( PressArk_Operation_Registry::all() ),
			array_keys( PressArk_Operation_Registry::get_aliases() ),
			array( 'edit_content', 'create_post', 'update_post', 'delete_content', 'reply_to_review' )
		);
		$tool_names = array_values( array_unique( array_filter( array_map( 'sanitize_key', $tool_names ) ) ) );

		if ( empty( $tool_names ) ) {
			return false;
		}

		$escaped_names = array_map(
			static fn( string $name ): string => preg_quote( $name, '/' ),
			$tool_names
		);
		$tool_pattern  = implode( '|', $escaped_names );

		return (bool) preg_match(
			'/\b(?:' . $tool_pattern . ')\b(?:\s|["\':=\-]){0,24}(?:json\b(?:\s|:){0,8})?[{\[]/i',
			$text
		) || (bool) preg_match(
			'/@?\b(?:' . $tool_pattern . ')\s*\([^()\n]+\)/i',
			$text
		);
	}

	/**
	 * Recursively detect a JSON payload that contains tool-call name/arguments keys.
	 *
	 * @param mixed $payload Decoded JSON payload.
	 * @return bool
	 */
	private function has_named_arguments_payload( $payload ): bool {
		if ( ! is_array( $payload ) ) {
			return false;
		}

		if ( isset( $payload['name'] ) && array_key_exists( 'arguments', $payload ) ) {
			return true;
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) && $this->has_named_arguments_payload( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a deterministic signature for a set of tool calls.
	 * Used for spin detection — identical signatures across rounds means no progress.
	 *
	 * @since 3.2.0
	 */
	private function build_tool_signature( array $tool_calls ): string {
		$parts = array();
		foreach ( $tool_calls as $tc ) {
			$name = $tc['name'] ?? '';
			$args = $tc['arguments'] ?? array();
			ksort( $args );
			$parts[] = $name . ':' . md5( wp_json_encode( $args ) );
		}
		sort( $parts );
		return implode( '|', $parts );
	}

	/**
	 * Remove duplicate non-idempotent calls during continuation runs.
	 *
	 * If the execution ledger says a create_post already completed for this
	 * request, the agent gets a synthetic tool result instead of replaying it.
	 *
	 * @param array               $tool_calls Tool calls extracted from the model.
	 * @param PressArk_Checkpoint $checkpoint Active checkpoint with execution ledger.
	 * @param array               &$synthetic_results Synthetic tool results to append.
	 * @return array Filtered tool calls.
	 */
	private function filter_duplicate_tool_calls(
		array $tool_calls,
		PressArk_Checkpoint $checkpoint,
		array &$synthetic_results
	): array {
		$filtered  = array();
		$execution = $checkpoint->get_execution();

		foreach ( $tool_calls as $tc ) {
			$name = sanitize_key( $tc['name'] ?? '' );
			$args = $tc['arguments'] ?? array();
			if ( ! is_array( $args ) ) {
				$args = array();
			}

			$args            = PressArk_Execution_Ledger::normalize_scoped_tool_args( $execution, $name, $args );
			$tc['arguments'] = $args;

			if ( PressArk_Execution_Ledger::should_skip_duplicate( $execution, $name, $args ) ) {
				$synthetic_results[] = array(
					'tool_use_id' => $tc['id'],
					'result'      => PressArk_Execution_Ledger::duplicate_skip_result( $execution, $name ),
				);
				continue;
			}

			$filtered[] = $tc;
		}

		return $filtered;
	}

	// ── Checkpoint Helpers (v2.4.0) ─────────────────────────────────────

	/**
	 * Update checkpoint from a tool call result.
	 * Extracts structured data from known tool types.
	 *
	 * @since 2.4.0
	 */
	private function update_checkpoint_from_result(
		PressArk_Checkpoint $checkpoint,
		?array $tool_call,
		array  $result,
		int    $round
	): void {
		if ( ! $tool_call || empty( $result['success'] ) ) {
			return;
		}

		$tool_name = $tool_call['name'] ?? '';
		$data      = $result['data'] ?? array();

		$checkpoint->set_turn( $round );
		$checkpoint->record_execution_read( $tool_name, $tool_call['arguments'] ?? array(), $result );

		match ( $tool_name ) {
			'read_content'                     => $this->checkpoint_from_read_content( $checkpoint, $data ),
			'search_content', 'list_posts'     => $this->checkpoint_from_search( $checkpoint, $data, $tool_call['arguments'] ?? array() ),
			'search_knowledge'                 => $this->checkpoint_from_knowledge( $checkpoint, $data, $tool_call['arguments'] ?? array() ),
			'get_site_overview', 'get_site_map' => $this->checkpoint_from_site_info( $checkpoint, $result ),
			default                            => null,
		};

		// v5.3.0: After recording results, resolve dependency graph.
		$this->advance_task_graph( $checkpoint );
	}

	/**
	 * Advance the task graph: resolve blocked tasks and mark the next
	 * actionable task as in_progress.
	 *
	 * @since 5.3.0
	 */
	private function advance_task_graph( PressArk_Checkpoint $checkpoint ): void {
		$execution = $checkpoint->get_execution();
		if ( empty( $execution['tasks'] ) ) {
			return;
		}

		$execution = PressArk_Execution_Ledger::resolve_blocked( $execution );

		// Find the next actionable task and mark it in_progress.
		$next = PressArk_Execution_Ledger::next_actionable_task( $execution );
		if ( $next && 'pending' === $next['status'] ) {
			$execution = PressArk_Execution_Ledger::mark_task_in_progress( $execution, $next['key'] );
		}

		// Write back (checkpoint will persist it).
		$this->update_checkpoint_execution( $checkpoint, $execution );
	}

	/**
	 * Write execution ledger back to the checkpoint.
	 *
	 * @since 5.3.0
	 */
	private function update_checkpoint_execution( PressArk_Checkpoint $checkpoint, array $execution ): void {
		$checkpoint->set_execution( $execution );
	}

	private function checkpoint_from_read_content( PressArk_Checkpoint $checkpoint, array $data ): void {
		if ( ! empty( $data['id'] ) && ! empty( $data['title'] ) ) {
			$checkpoint->add_entity( (int) $data['id'], $data['title'], $data['type'] ?? 'post' );
		}
		if ( ! empty( $data['word_count'] ) ) {
			$checkpoint->add_fact( "post_{$data['id']}_words", (string) $data['word_count'] );
		}
		if ( ! empty( $data['flags'] ) ) {
			foreach ( $data['flags'] as $flag ) {
				$checkpoint->add_fact( "post_{$data['id']}_flag", $flag );
			}
		}
	}

	// ── v3.7.0: Retrieval Bundle Dedup ──────────────────────────────

	/**
	 * Check if a read tool call has already been executed in this session.
	 *
	 * Returns a compact stub result if:
	 * 1. The bundle_id exists in the checkpoint, AND
	 * 2. The target post has not been modified since the bundle was recorded.
	 *
	 * Only applies to read_content calls (the most token-heavy reads).
	 *
	 * @return array|null Stub result, or null to proceed with real read.
	 */
	private function check_bundle_hit( PressArk_Checkpoint $checkpoint, string $tool_name, array $args ): ?array {
		if ( 'read_content' !== $tool_name ) {
			return null;
		}

		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return null;
		}

		$bundle_id = PressArk_Checkpoint::compute_bundle_id( $tool_name, $args );
		if ( ! $checkpoint->has_bundle( $bundle_id ) ) {
			return null;
		}

		$bundle = PressArk_Checkpoint::get_bundle_payload( $bundle_id );
		if ( empty( $bundle['result'] ) || ! is_array( $bundle['result'] ) ) {
			return null;
		}

		// Verify post hasn't changed since last read.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$stored_modified = (string) ( $bundle['post_modified'] ?? '' );
		$current_modified = (string) ( $post->post_modified_gmt ?: $post->post_modified );
		if ( $stored_modified && $current_modified && $stored_modified !== $current_modified ) {
			return null;
		}

		$result = $bundle['result'];
		if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			$result['data'] = array();
		}
		$result['cached']              = true;
		$result['stored_at']           = sanitize_text_field( (string) ( $bundle['stored_at'] ?? '' ) );
		$result['data']['bundle_hit'] = true;
		$result['data']['bundle_id']  = $bundle_id;
		if ( empty( $result['message'] ) ) {
			$result['message'] = sprintf(
				'Loaded cached %s result for "%s".',
				$tool_name,
				$post->post_title
			);
		}
		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$result = PressArk_Read_Metadata::annotate_tool_result(
				$tool_name,
				$args,
				$result,
				array(
					'freshness'   => 'cached',
					'provider'    => 'bundle_cache',
					'captured_at' => sanitize_text_field( (string) ( $bundle['stored_at'] ?? gmdate( 'c' ) ) ),
					'stored_at'   => sanitize_text_field( (string) ( $bundle['stored_at'] ?? '' ) ),
				)
			);
		}

		return $result;
	}

	/**
	 * Record a bundle ID in the checkpoint after a successful read.
	 */
	private function record_bundle( PressArk_Checkpoint $checkpoint, string $tool_name, array $args, array $result = array() ): void {
		if ( 'read_content' !== $tool_name ) {
			return;
		}
		$checkpoint->remember_bundle( $tool_name, $args, $result );
	}

	private function checkpoint_from_search( PressArk_Checkpoint $checkpoint, $data, array $args = array() ): void {
		if ( ! is_array( $data ) ) {
			return;
		}
		$retrieval_ids    = array();
		$retrieval_titles = array();
		foreach ( array_slice( $data, 0, 5 ) as $item ) {
			$id    = $item['id'] ?? $item['post_id'] ?? 0;
			$title = $item['title'] ?? '';
			$type  = $item['type'] ?? $item['post_type'] ?? 'post';
			if ( $id && $title ) {
				$checkpoint->add_entity( (int) $id, $title, $type );
				$retrieval_ids[]    = (int) $id;
				$retrieval_titles[] = $title;
			}
		}
		if ( ! empty( $retrieval_ids ) ) {
			$checkpoint->set_retrieval( array(
				'kind'          => 'content_search',
				'query'         => sanitize_text_field( $args['query'] ?? '' ),
				'count'         => count( $data ),
				'source_ids'    => $retrieval_ids,
				'source_titles' => $retrieval_titles,
				'updated_at'    => gmdate( 'c' ),
			) );
		}
	}

	private function checkpoint_from_knowledge( PressArk_Checkpoint $checkpoint, $data, array $args = array() ): void {
		if ( ! is_array( $data ) ) {
			return;
		}
		$retrieval_ids    = array();
		$retrieval_titles = array();
		foreach ( array_slice( $data, 0, 3 ) as $item ) {
			$id    = $item['post_id'] ?? 0;
			$title = $item['title'] ?? '';
			$type  = $item['post_type'] ?? $item['type'] ?? 'post';
			if ( $id && $title ) {
				$checkpoint->add_entity( (int) $id, $title, $type );
				$retrieval_ids[]    = (int) $id;
				$retrieval_titles[] = $title;
			}
		}
		if ( ! empty( $retrieval_ids ) ) {
			$checkpoint->set_retrieval( array(
				'kind'          => 'knowledge',
				'query'         => sanitize_text_field( $args['query'] ?? '' ),
				'count'         => count( $data ),
				'source_ids'    => $retrieval_ids,
				'source_titles' => $retrieval_titles,
				'updated_at'    => gmdate( 'c' ),
			) );
		}
	}

	/**
	 * Build the compact harness-state snapshot shared by prompt inspection and
	 * chat-facing run visibility surfaces.
	 */
	private function build_harness_state_snapshot( array $tool_set, ?PressArk_Checkpoint $checkpoint = null ): array {
		$tool_state         = is_array( $tool_set['tool_state'] ?? null ) ? (array) $tool_set['tool_state'] : array();
		$permission_surface = (array) ( $tool_set['permission_surface'] ?? array() );
		$loaded_groups      = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) ( $this->loaded_groups ?: ( $tool_set['groups'] ?? array() ) )
					)
				)
			)
		);
		$visibility_state   = class_exists( 'PressArk_Policy_Diagnostics' )
			? PressArk_Policy_Diagnostics::build_harness_visibility_summary(
				$permission_surface,
				$tool_state,
				array(
					'loaded_groups'   => $loaded_groups,
					'deferred_groups' => (array) ( $tool_set['deferred_groups'] ?? array() ),
				)
			)
			: array(
				'loaded_groups'  => $loaded_groups,
				'deferred_groups'=> array_values( array_filter( array_map(
					static function ( $candidate ): string {
						if ( is_array( $candidate ) ) {
							return sanitize_key( (string) ( $candidate['group'] ?? $candidate['name'] ?? '' ) );
						}
						return is_string( $candidate ) ? sanitize_key( $candidate ) : '';
					},
					(array) ( $tool_set['deferred_groups'] ?? array() )
				) ) ),
				'blocked_groups' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['blocked_groups'] ?? array() ) ) ) ),
				'hidden_reasons' => array_values( array_filter(
					array_map(
						static function ( $row ) {
							return is_array( $row ) ? $row : null;
						},
						(array) ( $permission_surface['hidden_reason_rows'] ?? array() )
					)
				) ),
			);
		$discover_state     = $this->build_harness_discovery_state();
		$context_trim       = $this->build_harness_context_trim_snapshot( $checkpoint );
		$route_status       = $this->build_harness_route_status_snapshot();
		$recovery_hints     = array();

		foreach ( (array) ( $visibility_state['recovery_hints'] ?? array() ) as $hint ) {
			$hint = sanitize_text_field( (string) $hint );
			if ( '' !== $hint ) {
				$recovery_hints[ $hint ] = true;
			}
		}
		if ( ! empty( $context_trim['hint'] ) ) {
			$recovery_hints[ sanitize_text_field( (string) $context_trim['hint'] ) ] = true;
		}
		if ( ! empty( $route_status['hint'] ) ) {
			$recovery_hints[ sanitize_text_field( (string) $route_status['hint'] ) ] = true;
		}
		if ( ! empty( $discover_state['hint'] ) ) {
			$recovery_hints[ sanitize_text_field( (string) $discover_state['hint'] ) ] = true;
		}

		return array_filter(
			array(
				'contract'       => 'harness_state',
				'version'        => 1,
				'loaded_groups'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $visibility_state['loaded_groups'] ?? $loaded_groups ) ) ) ),
				'deferred_groups'=> array_values( array_filter( array_map( 'sanitize_key', (array) ( $visibility_state['deferred_groups'] ?? array() ) ) ) ),
				'deferred_rows'  => array_values(
					array_slice(
						array_values(
							array_filter(
								array_map(
									static function ( $row ) {
										return is_array( $row ) ? $row : null;
									},
									(array) ( $visibility_state['deferred_rows'] ?? array() )
								)
							)
						),
						0,
						4
					)
				),
				'blocked_groups' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $visibility_state['blocked_groups'] ?? array() ) ) ) ),
				'hidden_reasons' => array_values( array_slice( (array) ( $visibility_state['hidden_reasons'] ?? array() ), 0, 4 ) ),
				'discover'       => $discover_state,
				'context_trim'   => $context_trim,
				'route_status'   => $route_status,
				'recovery_hints' => array_slice( array_keys( $recovery_hints ), 0, 4 ),
			),
			static function ( $value, $key ) {
				if ( in_array( $key, array( 'contract', 'version' ), true ) ) {
					return true;
				}

				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Surface discovery dead-ends so the model stops repeating the same miss.
	 */
	private function build_harness_discovery_state(): array {
		if ( $this->discover_calls <= 0 && $this->discover_zero_hits <= 0 ) {
			return array();
		}

		$hint = '';
		if ( $this->discover_zero_hits >= 3 ) {
			$hint = 'Stop retrying the same discovery search. Load a visible group directly or explain the limitation.';
		} elseif ( $this->discover_zero_hits > 0 ) {
			$hint = 'If discovery keeps missing, load a visible group directly instead of repeating the same search.';
		}

		return array_filter(
			array(
				'discover_calls'   => $this->discover_calls > 0 ? $this->discover_calls : null,
				'zero_hit_streak'  => $this->discover_zero_hits > 0 ? $this->discover_zero_hits : null,
				'hint'             => $hint,
			),
			static function ( $value ) {
				return null !== $value && '' !== (string) $value;
			}
		);
	}

	/**
	 * Summarize any active context trimming/compaction state.
	 */
	private function build_harness_context_trim_snapshot( ?PressArk_Checkpoint $checkpoint = null ): array {
		if ( ! $checkpoint ) {
			return array();
		}

		$compaction = (array) ( $checkpoint->get_context_capsule()['compaction'] ?? array() );
		$count      = max( 0, (int) ( $compaction['count'] ?? 0 ) );
		if ( $count <= 0 ) {
			return array();
		}

		$reason = sanitize_key( (string) ( $compaction['last_reason'] ?? '' ) );
		$detail = 1 === $count
			? 'Earlier context was compacted to keep the run moving.'
			: $count . ' context compactions kept this run within budget.';

		return array_filter(
			array(
				'count'  => $count,
				'reason' => $reason,
				'label'  => 'Context trimmed',
				'detail' => $detail,
				'hint'   => 'If something seems missing, continue from the latest result or rerun with a narrower scope.',
			),
			static function ( $value ) {
				return ! ( is_int( $value ) ? $value <= 0 : '' === (string) $value );
			}
		);
	}

	/**
	 * Summarize active route degradation or fallback state for the next round.
	 */
	private function build_harness_route_status_snapshot(): array {
		$routing  = is_array( $this->routing_decision ) ? $this->routing_decision : array();
		$fallback = is_array( $routing['fallback'] ?? null ) ? $routing['fallback'] : array();

		if ( empty( $fallback['used'] ) ) {
			return array();
		}

		$failure = sanitize_text_field( (string) ( $fallback['failure_class'] ?? '' ) );
		$detail  = '' !== $failure
			? 'Route degraded after ' . strtolower( str_replace( '_', ' ', $failure ) ) . '.'
			: 'The run switched to a fallback model to keep moving.';

		return array(
			'state'  => 'degraded',
			'label'  => 'Fallback route active',
			'detail' => $detail,
			'hint'   => 'If you need the strongest tool or format support, retry in Deep Mode or rerun later.',
		);
	}

	/**
	 * Render the model-visible harness-state block that explains what is
	 * loaded, deferred, blocked, hidden, trimmed, or degraded right now.
	 */
	private function build_harness_state_prompt_block( array $tool_set, ?PressArk_Checkpoint $checkpoint = null ): string {
		$state = $this->build_harness_state_snapshot( $tool_set, $checkpoint );
		if ( empty( $state ) ) {
			return '';
		}

		$payload = array_filter(
			array(
				'loaded_groups'  => array_values( array_slice( (array) ( $state['loaded_groups'] ?? array() ), 0, 8 ) ),
				'deferred_groups'=> array_values( array_slice( (array) ( $state['deferred_groups'] ?? array() ), 0, 4 ) ),
				'blocked_groups' => array_values( array_slice( (array) ( $state['blocked_groups'] ?? array() ), 0, 4 ) ),
				'hidden_reasons' => array_values(
					array_map(
						static function ( array $row ): array {
							return array_filter(
								array(
									'kind'   => sanitize_key( (string) ( $row['kind'] ?? '' ) ),
									'count'  => max( 0, (int) ( $row['count'] ?? 0 ) ),
									'groups' => array_values( array_slice( array_filter( array_map( 'sanitize_key', (array) ( $row['groups'] ?? array() ) ) ), 0, 3 ) ),
									'hint'   => sanitize_text_field( (string) ( $row['hint'] ?? '' ) ),
								),
								static function ( $value ) {
									return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value || 0 === (int) $value );
								}
							);
						},
						array_slice( (array) ( $state['hidden_reasons'] ?? array() ), 0, 3 )
					)
				),
				'context_trim'   => ! empty( $state['context_trim'] )
					? array_filter(
						array(
							'count'  => max( 0, (int) ( $state['context_trim']['count'] ?? 0 ) ),
							'reason' => sanitize_key( (string) ( $state['context_trim']['reason'] ?? '' ) ),
							'hint'   => sanitize_text_field( (string) ( $state['context_trim']['hint'] ?? '' ) ),
						),
						static function ( $value ) {
							return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value || 0 === (int) $value );
						}
					)
					: array(),
				'route_status'   => ! empty( $state['route_status'] )
					? array_filter(
						array(
							'state' => sanitize_key( (string) ( $state['route_status']['state'] ?? '' ) ),
							'label' => sanitize_text_field( (string) ( $state['route_status']['label'] ?? '' ) ),
							'hint'  => sanitize_text_field( (string) ( $state['route_status']['hint'] ?? '' ) ),
						),
						static function ( $value ) {
							return '' !== (string) $value;
						}
					)
					: array(),
				'discover'       => ! empty( $state['discover'] )
					? array_filter(
						array(
							'discover_calls'  => max( 0, (int) ( $state['discover']['discover_calls'] ?? 0 ) ),
							'zero_hit_streak' => max( 0, (int) ( $state['discover']['zero_hit_streak'] ?? 0 ) ),
							'hint'            => sanitize_text_field( (string) ( $state['discover']['hint'] ?? '' ) ),
						),
						static function ( $value ) {
							return ! ( is_int( $value ) ? $value <= 0 : '' === (string) $value );
						}
					)
					: array(),
				'recovery_hints' => array_values( array_slice( array_filter( array_map( 'sanitize_text_field', (array) ( $state['recovery_hints'] ?? array() ) ) ), 0, 3 ) ),
			),
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return '';
		}

		return "## Harness State\n"
			. $json
			. "\nTreat this as harness truth. Do not retry blocked or hidden capabilities until the state changes; load deferred groups only when the user's goal requires them.";
	}

	private function build_round_system_prompt(
		string $screen,
		int $post_id,
		string $message,
		string $task_type,
		array &$tool_set,
		PressArk_Checkpoint $checkpoint,
		array $messages
	): string {
		$prompt_sections = $this->build_round_prompt_sections(
			$screen,
			$post_id,
			$message,
			$task_type,
			$tool_set,
			$checkpoint
		);
		$tool_set        = $this->apply_budgeted_tool_support_sections( $tool_set, $prompt_sections, $messages );

		$capability_map = $tool_set['capability_map'] ?? '';
		if ( ! empty( $capability_map ) ) {
			$this->append_round_prompt_section(
				$prompt_sections['volatile'],
				$prompt_sections['labels']['volatile'],
				'capability_map',
				$capability_map
			);
		}

		$tool_set['prompt_assembly'] = $this->describe_round_prompt_assembly( $prompt_sections );

		return $this->compose_round_prompt_sections( $prompt_sections );
	}

	private function build_round_prompt_sections(
		string $screen,
		int $post_id,
		string $message,
		string $task_type,
		array $tool_set,
		PressArk_Checkpoint $checkpoint
	): array {
		$context  = new PressArk_Context();
		$sections = array(
			'stable'   => array(),
			'volatile' => array(),
			'labels'   => array(
				'stable'   => array(),
				'volatile' => array(),
			),
			'inspector' => array(),
		);
		$site_notes      = $this->resolve_site_notes( $message );
		$playbook_groups = $this->resolve_playbook_tool_groups( $tool_set );
		$playbook        = $this->resolve_site_playbook( $task_type, $playbook_groups, $message );
		$verification    = PressArk_Execution_Ledger::verification_summary( $checkpoint->get_execution() );
		$read_strata  = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::build_prompt_strata( $checkpoint->get_read_state(), $verification, $site_notes )
			: array();
		$trusted_reads = trim( (string) preg_replace( '/^##\s+Trusted System Facts\s*/i', '', (string) ( $read_strata['trusted_system'] ?? '' ) ) );
		$trusted_parts = array_filter( array(
			trim( $context->build( $screen, $post_id ) ),
			$trusted_reads,
		) );
		$trusted_system_block = empty( $trusted_parts )
			? ''
			: "## Trusted System Facts\n" . PressArk_AI_Connector::join_prompt_sections( $trusted_parts );

		$this->append_round_prompt_section(
			$sections['volatile'],
			$sections['labels']['volatile'],
			'trusted_system_facts',
			$trusted_system_block
		);
		foreach ( array(
			'verified_evidence' => 'verified_evidence',
			'derived_summaries' => 'derived_summaries',
			'untrusted_content' => 'untrusted_site_content',
		) as $stratum_key => $label ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				$label,
				(string) ( $read_strata[ $stratum_key ] ?? '' )
			);
		}

		$harness_prompt_block = $this->build_harness_state_prompt_block( $tool_set, $checkpoint );
		$this->append_round_prompt_section(
			$sections['volatile'],
			$sections['labels']['volatile'],
			'harness_state',
			$harness_prompt_block
		);

		if ( $this->automation_context ) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'automation_context',
				PressArk_AI_Connector::build_automation_addendum( $this->automation_context )
			);
		}

		$conditional_blocks = PressArk_AI_Connector::get_conditional_blocks(
			$task_type,
			$screen,
			(array) ( $this->loaded_groups ?: ( $tool_set['groups'] ?? array() ) )
		);
		$this->append_round_prompt_section(
			$sections['stable'],
			$sections['labels']['stable'],
			'conditional_blocks',
			$conditional_blocks
		);

		$task_skills = PressArk_Skills::get_dynamic_task_scoped( $task_type, array(
			'has_woo'       => class_exists( 'WooCommerce' ),
			'has_elementor' => defined( 'ELEMENTOR_VERSION' ),
		) );
		$this->append_round_prompt_section(
			$sections['stable'],
			$sections['labels']['stable'],
			'task_scoped_skills',
			$task_skills
		);

		$this->append_round_prompt_section(
			$sections['stable'],
			$sections['labels']['stable'],
			'site_playbook',
			(string) ( $playbook['text'] ?? '' )
		);

		$execution_guard = PressArk_Execution_Ledger::build_runtime_guard( $checkpoint->get_execution() );
		if ( ! empty( $execution_guard ) && self::is_continuation_message( $message ) ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'execution_guard',
				$execution_guard
			);
		}

		if ( $checkpoint->has_unapplied_confirms() ) {
			$pending_names = array_map(
				fn( $p ) => $p['action'] . ( $p['target'] ? ' on ' . $p['target'] : '' ),
				$checkpoint->get_pending()
			);
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'pending_confirmation',
				"## Pending Confirmation\n"
				. "You previously proposed these write actions but they were NOT applied yet â€” "
				. "the user has not clicked Approve on the confirm card:\n- "
				. implode( "\n- ", $pending_names )
				. "\nDo NOT claim these actions are done. If the user asks you to proceed, "
				. "re-emit the tool calls so a new confirm card is generated."
			);
		}

		if ( count( $this->plan_steps ) > 1 ) {
			$plan_lines = array();
			foreach ( $this->plan_steps as $i => $step ) {
				$plan_lines[] = ( $i + 1 ) . '. ' . $step;
			}
			$plan_block = "PLAN:\n" . implode( "\n", $plan_lines );
			if ( $this->plan_step <= count( $this->plan_steps ) ) {
				$plan_block .= "\nYou are on step {$this->plan_step}. Complete each step before moving to the next.";
			}
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'execution_plan',
				$plan_block
			);
		}

		if ( 'generate' === $task_type ) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'generation_order',
				'IMPORTANT: Create the content first with currently loaded tools. Load SEO or specialty groups only after the content exists.'
			);
		}

		$env_type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $env_type ) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'environment_note',
				"ENVIRONMENT NOTE: This is a {$env_type} environment. You can be less cautious about experimental changes â€” they won't affect the live site. Still use the preview/confirm flow, but don't over-warn about risks."
			);
		}

		$sections['inspector'] = array(
			'site_notes_included'  => '' !== trim( $site_notes ),
			'site_notes_preview'   => $this->preview_context_text( $site_notes, 220 ),
			'site_playbook_included' => '' !== trim( (string) ( $playbook['text'] ?? '' ) ),
			'site_playbook_preview'  => sanitize_text_field( (string) ( $playbook['preview'] ?? '' ) ),
			'site_playbook_titles'   => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) ( $playbook['titles'] ?? array() )
					)
				)
			),
			'dynamic_skill_names'  => $this->resolve_dynamic_skill_names( $task_type ),
			'conditional_blocks'   => $this->resolve_conditional_prompt_blocks(
				$task_type,
				$screen,
				(array) ( $this->loaded_groups ?: ( $tool_set['groups'] ?? array() ) )
			),
			'harness_state'        => $this->build_harness_state_snapshot( $tool_set, $checkpoint ),
			'site_profile_snapshots' => $this->extract_site_profile_snapshot_summaries( $checkpoint->get_read_state() ),
		);

		return $sections;
	}

	private function describe_round_prompt_assembly( array $prompt_sections ): array {
		return array(
			'stable_sections'   => array_values( array_unique( (array) ( $prompt_sections['labels']['stable'] ?? array() ) ) ),
			'volatile_sections' => array_values( array_unique( (array) ( $prompt_sections['labels']['volatile'] ?? array() ) ) ),
			'stable_blocks'     => $this->describe_prompt_blocks(
				(array) ( $prompt_sections['stable'] ?? array() ),
				(array) ( $prompt_sections['labels']['stable'] ?? array() )
			),
			'volatile_blocks'   => $this->describe_prompt_blocks(
				(array) ( $prompt_sections['volatile'] ?? array() ),
				(array) ( $prompt_sections['labels']['volatile'] ?? array() )
			),
			'inspector'         => is_array( $prompt_sections['inspector'] ?? null ) ? $prompt_sections['inspector'] : array(),
		);
	}

	private function describe_prompt_blocks( array $blocks, array $labels ): array {
		$described = array();

		foreach ( array_values( $blocks ) as $index => $content ) {
			$content = is_string( $content ) ? trim( $content ) : '';
			if ( '' === $content ) {
				continue;
			}

			$label = sanitize_key( (string) ( $labels[ $index ] ?? 'prompt_block_' . $index ) );
			$described[] = array(
				'id'      => $label,
				'label'   => $this->prompt_block_title( $label ),
				'tokens'  => $this->estimate_inspector_tokens( $content ),
				'chars'   => mb_strlen( $content ),
				'lines'   => max( 1, substr_count( $content, "\n" ) + 1 ),
				'preview' => $this->preview_context_text( $content, 220 ),
			);
		}

		return $described;
	}

	private function prompt_block_title( string $label ): string {
		return match ( $label ) {
			'automation_context'    => 'Automation Context',
			'conditional_blocks'    => 'Conditional Prompt Blocks',
			'task_scoped_skills'    => 'Task-Scoped Skills',
			'site_playbook'         => 'Site Playbook',
			'trusted_system_facts'  => 'Trusted System Facts',
			'verified_evidence'     => 'Verified Evidence',
			'derived_summaries'     => 'Derived Summaries',
			'untrusted_site_content'=> 'Untrusted Site Content',
			'execution_guard'       => 'Execution Guard',
			'pending_confirmation'  => 'Pending Confirmation',
			'execution_plan'        => 'Execution Plan',
			'harness_state'         => 'Harness State',
			'generation_order'      => 'Generation Ordering',
			'environment_note'      => 'Environment Note',
			'capability_map'        => 'Capability Map',
			default                 => ucwords( str_replace( '_', ' ', $label ) ),
		};
	}

	private function resolve_dynamic_skill_names( string $task_type ): array {
		if ( ! is_callable( array( 'PressArk_Skills', 'skills_for_task' ) ) ) {
			return array();
		}

		$skills = array_values( array_filter( array_map(
			'sanitize_key',
			PressArk_Skills::skills_for_task( $task_type )
		) ) );

		if ( ! defined( 'ELEMENTOR_VERSION' ) && in_array( $task_type, array( 'generate', 'edit' ), true ) ) {
			$skills[] = 'block_editor';
		}

		return array_values( array_unique( array_filter( $skills ) ) );
	}

	private function resolve_conditional_prompt_blocks( string $task_type, string $screen, array $loaded_groups ): array {
		$blocks = array();

		if ( defined( 'ELEMENTOR_VERSION' ) && ( in_array( $task_type, array( 'edit', 'generate' ), true ) || str_contains( $screen, 'elementor' ) ) ) {
			$blocks[] = 'elementor';
		}

		$needs_wc_block = in_array( 'woocommerce', $loaded_groups, true ) || str_contains( $screen, 'woocommerce' );
		if ( class_exists( 'WooCommerce' ) && $needs_wc_block ) {
			$blocks[] = 'woocommerce';
		}

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && in_array( $task_type, array( 'edit', 'generate' ), true ) ) {
			$blocks[] = 'fse';
		}

		return array_values( array_unique( $blocks ) );
	}

	private function extract_site_profile_snapshot_summaries( array $snapshots ): array {
		$matches = array();
		$snapshots = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::sanitize_snapshot_collection( $snapshots )
			: array();

		foreach ( $snapshots as $snapshot ) {
			$tool_name = sanitize_key( (string) ( $snapshot['tool_name'] ?? '' ) );
			if ( ! in_array( $tool_name, array( 'view_site_profile', 'get_brand_profile' ), true ) ) {
				continue;
			}

			$matches[] = array_filter( array(
				'tool_name'     => $tool_name,
				'summary'       => sanitize_text_field( (string) ( $snapshot['summary'] ?? '' ) ),
				'freshness'     => sanitize_key( (string) ( $snapshot['freshness'] ?? '' ) ),
				'completeness'  => sanitize_key( (string) ( $snapshot['completeness'] ?? '' ) ),
				'captured_at'   => sanitize_text_field( (string) ( $snapshot['captured_at'] ?? '' ) ),
			), static function ( $value ) {
				return '' !== (string) $value;
			} );
		}

		return $matches;
	}

	private function estimate_inspector_tokens( $value ): int {
		if ( $this->budget_manager instanceof PressArk_Token_Budget_Manager ) {
			return $this->budget_manager->estimate_value_tokens( $value );
		}

		$serialized = is_string( $value ) ? $value : wp_json_encode( $value );
		if ( ! is_string( $serialized ) || '' === $serialized ) {
			return 0;
		}

		return (int) ceil( mb_strlen( $serialized ) / 4 );
	}

	private function preview_context_text( string $text, int $max = 180 ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}

		return mb_strlen( $text ) > $max
			? mb_substr( $text, 0, max( 0, $max - 3 ) ) . '...'
			: $text;
	}

	private function summarize_context_messages( array $messages ): array {
		$canonical = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::canonicalize_messages( $messages )
			: $messages;
		$canonical = is_array( $canonical ) ? $canonical : array();
		$role_counts = array();
		$items       = array();

		foreach ( $canonical as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role = sanitize_key( (string) ( $message['role'] ?? 'unknown' ) );
			if ( '' === $role ) {
				$role = 'unknown';
			}
			$role_counts[ $role ] = (int) ( $role_counts[ $role ] ?? 0 ) + 1;
		}

		foreach ( array_slice( $canonical, -24 ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? 'unknown' ) );
			$content = $message['content'] ?? '';
			$kind    = 'message';
			$preview = '';
			$details = array();

			if ( 'assistant' === $role && ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
				$kind = 'assistant_tool_calls';
				$tool_names = array_values( array_filter( array_map(
					static function ( array $call ): string {
						return sanitize_key( (string) ( $call['function']['name'] ?? '' ) );
					},
					(array) $message['tool_calls']
				) ) );
				$preview = ! empty( $tool_names ) ? implode( ', ', $tool_names ) : 'tool calls';
				$details['tool_call_count'] = count( (array) $message['tool_calls'] );
			} elseif ( 'tool' === $role ) {
				$kind = 'tool_result';
				$preview = is_scalar( $content ) ? (string) $content : (string) wp_json_encode( $content );
				$details['tool_call_id'] = sanitize_text_field( (string) ( $message['tool_call_id'] ?? '' ) );
			} else {
				$preview = is_scalar( $content ) ? (string) $content : (string) wp_json_encode( $content );
			}

			$preview = $this->preview_context_text( $preview, 140 );
			$items[] = array_filter( array(
				'role'    => $role,
				'kind'    => $kind,
				'chars'   => is_string( $preview ) ? mb_strlen( $preview ) : 0,
				'preview' => $preview,
			) + $details, static function ( $value ) {
				return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
			} );
		}

		return array(
			'total_messages' => count( $canonical ),
			'role_counts'    => $role_counts,
			'items'          => $items,
		);
	}

	private function simplify_hidden_decisions( array $hidden_decisions ): array {
		$rows = array();

		foreach ( $hidden_decisions as $tool_name => $decision ) {
			if ( ! is_array( $decision ) ) {
				continue;
			}

			$rows[] = array_filter( array(
				'tool'              => sanitize_key( (string) $tool_name ),
				'verdict'           => sanitize_key( (string) ( $decision['verdict'] ?? '' ) ),
				'source'            => sanitize_key( (string) ( $decision['source'] ?? '' ) ),
				'reasons'           => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $decision['reasons'] ?? array() ) ) ) ),
				'reason_codes'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['visibility']['reason_codes'] ?? array() ) ) ) ),
				'approval_mode'     => sanitize_key( (string) ( $decision['approval']['mode'] ?? '' ) ),
				'entitlement_basis' => sanitize_key( (string) ( $decision['entitlement']['basis'] ?? '' ) ),
			), static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			} );
		}

		return $rows;
	}

	private function simplify_read_snapshots( array $snapshots ): array {
		$items = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::sanitize_snapshot_collection( $snapshots )
			: array();
		$rows  = array();

		foreach ( $items as $snapshot ) {
			$rows[] = array_filter( array(
				'handle'         => sanitize_text_field( (string) ( $snapshot['handle'] ?? '' ) ),
				'tool_name'      => sanitize_key( (string) ( $snapshot['tool_name'] ?? '' ) ),
				'resource_uri'   => sanitize_text_field( (string) ( $snapshot['resource_uri'] ?? '' ) ),
				'summary'        => sanitize_text_field( (string) ( $snapshot['summary'] ?? '' ) ),
				'freshness'      => sanitize_key( (string) ( $snapshot['freshness'] ?? '' ) ),
				'completeness'   => sanitize_key( (string) ( $snapshot['completeness'] ?? '' ) ),
				'trust_class'    => sanitize_key( (string) ( $snapshot['trust_class'] ?? '' ) ),
				'provider'       => sanitize_key( (string) ( $snapshot['provider'] ?? '' ) ),
				'captured_at'    => sanitize_text_field( (string) ( $snapshot['captured_at'] ?? '' ) ),
				'stale_at'       => sanitize_text_field( (string) ( $snapshot['stale_at'] ?? '' ) ),
				'stale_reason'   => sanitize_text_field( (string) ( $snapshot['stale_reason'] ?? '' ) ),
				'provenance'     => is_array( $snapshot['provenance'] ?? null ) ? $snapshot['provenance'] : array(),
				'target_post_ids'=> array_values( array_map( 'absint', (array) ( $snapshot['target_post_ids'] ?? array() ) ) ),
			), static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			} );
		}

		return $rows;
	}

	private function simplify_replay_events( array $events ): array {
		$rows = array();

		foreach ( array_slice( $events, -16 ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$rows[] = array_filter( array(
				'type'                     => sanitize_key( (string) ( $event['type'] ?? '' ) ),
				'phase'                    => sanitize_key( (string) ( $event['phase'] ?? '' ) ),
				'reason'                   => sanitize_key( (string) ( $event['reason'] ?? '' ) ),
				'round'                    => max( 0, (int) ( $event['round'] ?? 0 ) ),
				'dropped_messages'         => max( 0, (int) ( $event['dropped_messages'] ?? 0 ) ),
				'dropped_rounds'           => max( 0, (int) ( $event['dropped_rounds'] ?? 0 ) ),
				'kept_rounds'              => max( 0, (int) ( $event['kept_rounds'] ?? 0 ) ),
				'inserted_missing_results' => max( 0, (int) ( $event['inserted_missing_results'] ?? 0 ) ),
				'dropped_orphan_results'   => max( 0, (int) ( $event['dropped_orphan_results'] ?? 0 ) ),
				'dropped_duplicate_results'=> max( 0, (int) ( $event['dropped_duplicate_results'] ?? 0 ) ),
				'tool_name'                => sanitize_key( (string) ( $event['tool_name'] ?? '' ) ),
				'artifact_uri'             => sanitize_text_field( (string) ( $event['artifact_uri'] ?? '' ) ),
				'at'                       => sanitize_text_field( (string) ( $event['at'] ?? '' ) ),
			), static function ( $value ) {
				return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
			} );
		}

		return $rows;
	}

	private function simplify_replacements( array $journal ): array {
		$entries = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_replacement_journal( $journal )
			: array();
		$rows    = array();

		foreach ( $entries as $entry ) {
			$replacement = $entry['replacement'] ?? array();
			$preview     = '';
			if ( is_array( $replacement ) ) {
				$json = wp_json_encode( $replacement );
				$preview = is_string( $json ) ? $json : '';
			} elseif ( is_scalar( $replacement ) ) {
				$preview = (string) $replacement;
			}

			$rows[] = array_filter( array(
				'tool_use_id'    => sanitize_text_field( (string) ( $entry['tool_use_id'] ?? '' ) ),
				'tool_name'      => sanitize_key( (string) ( $entry['tool_name'] ?? '' ) ),
				'reason'         => sanitize_key( (string) ( $entry['reason'] ?? '' ) ),
				'round'          => max( 0, (int) ( $entry['round'] ?? 0 ) ),
				'inline_tokens'  => max( 0, (int) ( $entry['inline_tokens'] ?? 0 ) ),
				'stored_at'      => sanitize_text_field( (string) ( $entry['stored_at'] ?? '' ) ),
				'artifact_uri'   => sanitize_text_field( (string) ( $entry['artifact_uri'] ?? '' ) ),
				'replacement_preview' => $this->preview_context_text( $preview, 140 ),
			), static function ( $value ) {
				return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
			} );
		}

		return $rows;
	}

	private function build_token_footprint_snapshot( array $tool_set, array $provider_request ): array {
		$budget         = ! empty( $tool_set['budget'] ) ? (array) $tool_set['budget'] : (array) $this->budget_report;
		$prompt_assembly = (array) ( $tool_set['prompt_assembly'] ?? array() );
		$dynamic_blocks = array();

		foreach ( (array) ( $prompt_assembly['stable_blocks'] ?? array() ) as $block ) {
			if ( is_array( $block ) ) {
				$block['bucket'] = 'stable';
				$dynamic_blocks[] = $block;
			}
		}
		foreach ( (array) ( $prompt_assembly['volatile_blocks'] ?? array() ) as $block ) {
			if ( is_array( $block ) ) {
				$block['bucket'] = 'volatile';
				$dynamic_blocks[] = $block;
			}
		}

		return array_filter( array(
			'estimated_prompt_tokens' => (int) ( $budget['estimated_prompt_tokens'] ?? 0 ),
			'estimated_output_tokens' => (int) ( $budget['estimated_output_tokens'] ?? 0 ),
			'remaining_tokens'       => (int) ( $budget['remaining_tokens'] ?? 0 ),
			'segments'               => (array) ( $budget['segments'] ?? array() ),
			'prompt_sections'        => (array) ( $budget['prompt_sections'] ?? array() ),
			'cached_blocks'          => (array) ( $provider_request['cached_blocks'] ?? array() ),
			'dynamic_blocks'         => $dynamic_blocks,
		), static function ( $value ) {
			return ! ( is_array( $value ) ? empty( $value ) : 0 === $value );
		} );
	}

	private function build_request_context_snapshot( int $round, array $tool_set, array $messages, ?PressArk_Checkpoint $checkpoint = null ): array {
		$provider_request = $this->ai->get_last_request_snapshot();
		$prompt_assembly  = (array) ( $tool_set['prompt_assembly'] ?? array() );
		$inspector_meta   = is_array( $prompt_assembly['inspector'] ?? null ) ? $prompt_assembly['inspector'] : array();
		$permission_surface = (array) ( $tool_set['permission_surface'] ?? array() );
		$tool_state         = is_array( $tool_set['tool_state'] ?? null ) ? (array) $tool_set['tool_state'] : array();
		$harness_state      = $this->build_harness_state_snapshot( $tool_set, $checkpoint );

		return array(
			'contract' => 'context_inspector',
			'version'  => 1,
			'round'    => $round,
			'task_type'=> $this->task_type,
			'provider_request' => $provider_request,
			'prompt'   => array_filter( array(
				'stable_blocks'        => (array) ( $prompt_assembly['stable_blocks'] ?? array() ),
				'volatile_blocks'      => (array) ( $prompt_assembly['volatile_blocks'] ?? array() ),
				'stable_section_ids'   => (array) ( $prompt_assembly['stable_sections'] ?? array() ),
				'volatile_section_ids' => (array) ( $prompt_assembly['volatile_sections'] ?? array() ),
				'capability_map_variant' => sanitize_key( (string) ( $tool_set['capability_map_variant'] ?? '' ) ),
				'capability_map_included' => ! empty( $tool_set['capability_map'] ),
				'dynamic_skill_names'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $inspector_meta['dynamic_skill_names'] ?? array() ) ) ) ),
				'conditional_blocks'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $inspector_meta['conditional_blocks'] ?? array() ) ) ) ),
				'site_playbook'        => ! empty( $inspector_meta['site_playbook_included'] )
					? array(
						'included' => true,
						'titles'   => array_values(
							array_filter(
								array_map(
									'sanitize_text_field',
									(array) ( $inspector_meta['site_playbook_titles'] ?? array() )
								)
							)
						),
						'preview'  => sanitize_text_field( (string) ( $inspector_meta['site_playbook_preview'] ?? '' ) ),
					)
					: array(),
				'site_notes'           => ! empty( $inspector_meta['site_notes_included'] )
					? array(
						'included' => true,
						'preview'  => sanitize_text_field( (string) ( $inspector_meta['site_notes_preview'] ?? '' ) ),
					)
					: array(),
				'harness_state'        => is_array( $inspector_meta['harness_state'] ?? null ) ? (array) $inspector_meta['harness_state'] : $harness_state,
				'site_profiles'        => (array) ( $inspector_meta['site_profile_snapshots'] ?? array() ),
			), static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : false === $value || '' === (string) $value );
			} ),
			'tool_surface' => array_filter( array(
				'context'          => sanitize_key( (string) ( $tool_state['context'] ?? $permission_surface['context'] ?? '' ) ),
				'visible_tools'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['visible_tools'] ?? $permission_surface['visible_tools'] ?? array() ) ) ) ),
				'loaded_tools'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['loaded_tools'] ?? $permission_surface['visible_tools'] ?? array() ) ) ) ),
				'searchable_tools' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['searchable_tools'] ?? array() ) ) ) ),
				'discovered_tools' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['discovered_tools'] ?? array() ) ) ) ),
				'blocked_tools'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['blocked_tools'] ?? array() ) ) ) ),
				'blocked_summary'  => (array) ( $tool_state['blocked_summary'] ?? array() ),
				'hidden_tools'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $permission_surface['hidden_tools'] ?? array() ) ) ) ),
				'hidden_summary'   => (array) ( $permission_surface['hidden_summary'] ?? array() ),
				'hidden_decisions' => $this->simplify_hidden_decisions( (array) ( $permission_surface['hidden_decisions'] ?? array() ) ),
				'state_rows'       => (array) ( $tool_state['tools'] ?? array() ),
			), static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			} ),
			'messages'        => $this->summarize_context_messages( $messages ),
			'harness_state'   => $harness_state,
			'token_footprint' => $this->build_token_footprint_snapshot( $tool_set, $provider_request ),
		);
	}

	private function build_context_inspector( array $tool_set, ?PressArk_Checkpoint $checkpoint = null ): array {
		$inspector = is_array( $this->context_inspector ) ? $this->context_inspector : array();
		if ( empty( $inspector ) ) {
			$tool_state = is_array( $tool_set['tool_state'] ?? null ) ? (array) $tool_set['tool_state'] : array();
			$inspector = array(
				'contract' => 'context_inspector',
				'version'  => 1,
				'prompt'   => array(
					'capability_map_variant' => sanitize_key( (string) ( $tool_set['capability_map_variant'] ?? '' ) ),
				),
				'tool_surface' => array_filter( array(
					'visible_tools'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['visible_tools'] ?? $tool_set['effective_visible_tools'] ?? array() ) ) ) ),
					'loaded_tools'     => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['loaded_tools'] ?? $tool_set['effective_visible_tools'] ?? array() ) ) ) ),
					'searchable_tools' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['searchable_tools'] ?? array() ) ) ) ),
					'discovered_tools' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['discovered_tools'] ?? array() ) ) ) ),
					'blocked_tools'    => array_values( array_filter( array_map( 'sanitize_key', (array) ( $tool_state['blocked_tools'] ?? array() ) ) ) ),
					'blocked_summary'  => (array) ( $tool_state['blocked_summary'] ?? array() ),
					'hidden_summary'   => (array) ( $tool_set['permission_surface']['hidden_summary'] ?? array() ),
					'state_rows'       => (array) ( $tool_state['tools'] ?? array() ),
				), static function ( $value ) {
					return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
				} ),
				'token_footprint' => $this->build_token_footprint_snapshot( $tool_set, $this->ai->get_last_request_snapshot() ),
			);
		}

		$inspector['harness_state'] = $this->build_harness_state_snapshot( $tool_set, $checkpoint );

		if ( $checkpoint ) {
			$read_rows    = $this->simplify_read_snapshots( $checkpoint->get_read_state() );
			$trust_counts = array(
				'trusted_system'    => 0,
				'derived_summary'   => 0,
				'untrusted_content' => 0,
				'stale'             => 0,
			);
			foreach ( $read_rows as $row ) {
				$trust = sanitize_key( (string) ( $row['trust_class'] ?? '' ) );
				if ( isset( $trust_counts[ $trust ] ) ) {
					++$trust_counts[ $trust ];
				}
				if ( 'stale' === ( $row['freshness'] ?? '' ) ) {
					++$trust_counts['stale'];
				}
			}

			$replay_state = $checkpoint->get_replay_state();
			$compaction   = (array) ( $checkpoint->get_context_capsule()['compaction'] ?? array() );

			$inspector['prompt']['site_profiles'] = $this->extract_site_profile_snapshot_summaries( $checkpoint->get_read_state() );
			$inspector['reads'] = array(
				'summary'   => $trust_counts,
				'snapshots' => $read_rows,
			);
			$inspector['replay'] = array_filter( array(
				'compaction'  => $compaction,
				'events'      => $this->simplify_replay_events( (array) ( $replay_state['events'] ?? array() ) ),
				'last_resume' => is_array( $replay_state['last_resume'] ?? null ) ? $replay_state['last_resume'] : array(),
			), static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			} );
			$inspector['replacements'] = $this->simplify_replacements( $checkpoint->get_replay_replacements() );
		}

		if ( ! empty( $this->routing_decision ) ) {
			$inspector['routing'] = $this->routing_decision;
		}

		return $inspector;
	}

	private function append_round_prompt_section( array &$bucket, array &$labels, string $label, string $content ): void {
		$content = trim( $content );
		if ( '' === $content ) {
			return;
		}

		$bucket[] = $content;
		$labels[] = sanitize_key( $label );
	}

	private function compose_round_prompt_group( array $sections ): string {
		return PressArk_AI_Connector::join_prompt_sections( $sections );
	}

	private function compose_round_prompt_sections( array $sections ): string {
		$parts    = array();
		$stable   = $this->compose_round_prompt_group( (array) ( $sections['stable'] ?? array() ) );
		$volatile = $this->compose_round_prompt_group( (array) ( $sections['volatile'] ?? array() ) );

		if ( '' !== $stable ) {
			$parts[] = "## Stable Run Prefix\n" . $stable;
		}
		if ( '' !== $volatile ) {
			$parts[] = "## Volatile Run State\n" . $volatile;
		}

		return PressArk_AI_Connector::join_prompt_sections( $parts );
	}

	private function apply_budgeted_tool_support_sections( array $tool_set, array $prompt_sections, array $messages ): array {
		$capability_maps = (array) ( $tool_set['capability_maps'] ?? array() );
		if ( empty( $capability_maps ) ) {
			$capability_maps = PressArk_Tool_Catalog::instance()->get_capability_maps(
				(array) ( $tool_set['groups'] ?? array() )
			);
		}

		$stable_prompt    = $this->compose_round_prompt_group( (array) ( $prompt_sections['stable'] ?? array() ) );
		$volatile_prompt  = $this->compose_round_prompt_group( (array) ( $prompt_sections['volatile'] ?? array() ) );
		$base_prompt_text = $this->compose_round_prompt_sections( $prompt_sections );
		$preference_order = $this->model_rounds > 0
			? array( 'compact', 'minimal', 'full' )
			: array( 'full', 'compact', 'minimal' );

		if ( ! $this->budget_manager instanceof PressArk_Token_Budget_Manager ) {
			$variant = '';
			foreach ( $preference_order as $candidate ) {
				if ( ! empty( $capability_maps[ $candidate ] ) ) {
					$variant = $candidate;
					break;
				}
			}
			$tool_set['capability_maps']        = $capability_maps;
			$tool_set['capability_map_variant'] = $variant;
			$tool_set['capability_map']         = '' !== $variant ? (string) ( $capability_maps[ $variant ] ?? '' ) : '';
			return $tool_set;
		}

		list( $conversation_messages, $tool_result_messages ) = $this->split_messages_for_budget( $messages );
		$base_ledger = $this->budget_manager->build_request_ledger( array(
			'dynamic_prompt'          => $base_prompt_text,
			'dynamic_prompt_stable'   => $stable_prompt,
			'dynamic_prompt_volatile' => $volatile_prompt,
			'loaded_tool_schemas'     => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'            => $conversation_messages,
			'tool_results'            => $tool_result_messages,
			'deferred_candidates'     => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );
		$variant    = $this->budget_manager->choose_support_variant(
			$capability_maps,
			$base_ledger,
			$preference_order
		);
		$map_text   = '' !== $variant ? (string) ( $capability_maps[ $variant ] ?? '' ) : '';
		$ledger_sections = $prompt_sections;
		if ( '' !== $map_text ) {
			$ledger_sections['volatile'][] = $map_text;
		}
		$ledger_prompt   = $this->compose_round_prompt_sections( $ledger_sections );
		$ledger_volatile = $this->compose_round_prompt_group( (array) ( $ledger_sections['volatile'] ?? array() ) );

		$tool_set['capability_maps']        = $capability_maps;
		$tool_set['capability_map_variant'] = $variant;
		$tool_set['capability_map']         = $map_text;
		$tool_set['budget']                 = $this->budget_manager->build_request_ledger( array(
			'dynamic_prompt'          => $ledger_prompt,
			'dynamic_prompt_stable'   => $stable_prompt,
			'dynamic_prompt_volatile' => $ledger_volatile,
			'loaded_tool_schemas'     => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'            => $conversation_messages,
			'tool_results'            => $tool_result_messages,
			'deferred_candidates'     => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );
		$this->budget_report                = (array) $tool_set['budget'];

		return $tool_set;
	}

	// ── Site Memory ──────────────────────────────────────────────────

	/**
	 * Merge currently-loaded and currently-available tool groups for memory recall.
	 *
	 * @param array $tool_set Active tool-set metadata.
	 * @return array<int,string>
	 */
	private function resolve_playbook_tool_groups( array $tool_set = array() ): array {
		$merged = array_merge(
			(array) $this->loaded_groups,
			(array) ( $tool_set['groups'] ?? array() )
		);

		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $merged )
				)
			)
		);
	}

	/**
	 * Resolve operator-authored Site Playbook instructions for this run.
	 *
	 * @param string $task_type Current routed task type.
	 * @param array<int,string> $tool_groups Active tool groups.
	 * @param string $message Current user message.
	 * @return array{text:string,preview:string,entries:array,titles:array,task_type:string,tool_groups:array}
	 */
	private function resolve_site_playbook( string $task_type, array $tool_groups, string $message ): array {
		if ( ! class_exists( 'PressArk_Site_Playbook' ) ) {
			return array(
				'text'        => '',
				'preview'     => '',
				'entries'     => array(),
				'titles'      => array(),
				'task_type'   => sanitize_key( $task_type ),
				'tool_groups' => array_values( array_filter( array_map( 'sanitize_key', $tool_groups ) ) ),
			);
		}

		return PressArk_Site_Playbook::resolve_prompt_context( $task_type, $tool_groups, $message );
	}

	/**
	 * Resolve and format relevant site notes for injection into the system prompt.
	 *
	 * Two-path selection:
	 * - ≤10 notes: inject all (cheaper than filtering or an API call).
	 * - 11+ notes: category-based pre-filter → AI selection if still >15.
	 *
	 * @param string $message Current user message (used for AI selection context).
	 * @return string Formatted notes block or empty string.
	 */
	/**
	 * Choose the richest capability/resource support text that fits the
	 * current round budget.
	 *
	 * @param array  $tool_set           Current tool set.
	 * @param string $system_prompt_base Dynamic prompt text before support text.
	 * @param array  $messages           Current loop messages.
	 * @return array
	 */
	private function apply_budgeted_tool_support( array $tool_set, string $system_prompt_base, array $messages ): array {
		$capability_maps = (array) ( $tool_set['capability_maps'] ?? array() );
		if ( empty( $capability_maps ) ) {
			$capability_maps = PressArk_Tool_Catalog::instance()->get_capability_maps(
				(array) ( $tool_set['groups'] ?? array() )
			);
		}

		$preference_order = $this->model_rounds > 0
			? array( 'compact', 'minimal', 'full' )
			: array( 'full', 'compact', 'minimal' );

		if ( ! $this->budget_manager instanceof PressArk_Token_Budget_Manager ) {
			$variant = '';
			foreach ( $preference_order as $candidate ) {
				if ( ! empty( $capability_maps[ $candidate ] ) ) {
					$variant = $candidate;
					break;
				}
			}
			$tool_set['capability_maps']        = $capability_maps;
			$tool_set['capability_map_variant'] = $variant;
			$tool_set['capability_map']         = '' !== $variant ? (string) ( $capability_maps[ $variant ] ?? '' ) : '';
			return $tool_set;
		}

		list( $conversation_messages, $tool_result_messages ) = $this->split_messages_for_budget( $messages );
		$base_ledger = $this->budget_manager->build_request_ledger( array(
			'dynamic_prompt'      => $system_prompt_base,
			'loaded_tool_schemas' => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'        => $conversation_messages,
			'tool_results'        => $tool_result_messages,
			'deferred_candidates' => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );
		$variant    = $this->budget_manager->choose_support_variant(
			$capability_maps,
			$base_ledger,
			$preference_order
		);
		$map_text   = '' !== $variant ? (string) ( $capability_maps[ $variant ] ?? '' ) : '';
		$ledger_prompt = '' !== $map_text
			? $system_prompt_base . "\n\n" . $map_text
			: $system_prompt_base;

		$tool_set['capability_maps']        = $capability_maps;
		$tool_set['capability_map_variant'] = $variant;
		$tool_set['capability_map']         = $map_text;
		$tool_set['budget']                 = $this->budget_manager->build_request_ledger( array(
			'dynamic_prompt'      => $ledger_prompt,
			'loaded_tool_schemas' => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'        => $conversation_messages,
			'tool_results'        => $tool_result_messages,
			'deferred_candidates' => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );
		$this->budget_report                = (array) $tool_set['budget'];

		return $tool_set;
	}

	/**
	 * Split loop messages into conversational context versus tool-result
	 * payloads for budget accounting.
	 *
	 * @param array $messages Current loop messages.
	 * @return array{0: array, 1: array}
	 */
	private function split_messages_for_budget( array $messages ): array {
		$conversation = array();
		$tool_results = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			if ( 'tool' === $role || $this->is_budget_tool_result_message( $msg ) ) {
				$tool_results[] = $msg;
				continue;
			}

			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$conversation[] = $msg;
			}
		}

		return array( $conversation, $tool_results );
	}

	/**
	 * Detect Anthropic-style tool-result messages that use a user role with
	 * tool_result content blocks.
	 *
	 * @param array $message Message candidate.
	 * @return bool
	 */
	private function is_budget_tool_result_message( array $message ): bool {
		if ( 'user' !== ( $message['role'] ?? '' ) || ! is_array( $message['content'] ?? null ) || empty( $message['content'] ) ) {
			return false;
		}

		foreach ( $message['content'] as $block ) {
			if ( ! is_array( $block ) || 'tool_result' !== ( $block['type'] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve and format relevant site notes for injection into the system prompt.
	 *
	 * Two-path selection:
	 * - inject all when note count is small
	 * - category-filter, then AI-select if still large
	 *
	 * @param string $message Current user message.
	 * @return string
	 */
	private function resolve_site_notes( string $message ): string {
		$raw       = get_option( 'pressark_site_notes', '[]' );
		$all_notes = json_decode( is_string( $raw ) ? $raw : '[]', true );

		if ( empty( $all_notes ) || ! is_array( $all_notes ) ) {
			return '';
		}

		// Path A: ≤10 notes — inject all, cheaper than filtering.
		if ( count( $all_notes ) <= 10 ) {
			return $this->format_site_notes( $all_notes );
		}

		// Path B: 11+ notes — category-based pre-filter.
		$filtered = $this->get_relevant_site_notes( $all_notes );

		// If still >15 after category filter, use AI selection.
		if ( count( $filtered ) > 15 ) {
			$ai_selected = $this->ai_select_notes( $filtered, $message );
			if ( null !== $ai_selected ) {
				$filtered = $ai_selected;
			} else {
				$filtered = array_slice( $filtered, -15 );
			}
		}

		return $this->format_site_notes( $filtered );
	}

	/**
	 * Category-based pre-filter for site notes.
	 *
	 * @param array $all_notes All stored notes.
	 * @return array Filtered notes (preferences + category-matched + recent 5), deduped, capped at 15.
	 */
	private function get_relevant_site_notes( array $all_notes ): array {
		// Map note categories to actual registry group names.
		$category_groups = array(
			'products'    => array( 'woocommerce' ),
			'content'     => array( 'core', 'content', 'seo', 'media', 'generation', 'bulk', 'index' ),
			'technical'   => array( 'health', 'settings', 'plugins', 'themes', 'database', 'logs' ),
			'issues'      => array( 'health', 'security', 'settings', 'plugins', 'database' ),
		);

		$loaded = (array) $this->loaded_groups;

		// Start with recent 5 (recency guarantee — these are never dropped).
		$recent = array_slice( $all_notes, -5 );
		$merged = array();
		$seen   = array();
		foreach ( $recent as $note ) {
			$key          = md5( $note['note'] ?? '' );
			$seen[ $key ] = true;
			$merged[]     = $note;
		}

		// Add preferences (always relevant, max 5).
		$pref_count = 0;
		foreach ( $all_notes as $note ) {
			if ( 'preferences' !== ( $note['category'] ?? '' ) ) {
				continue;
			}
			$key = md5( $note['note'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$pref_count++;
			if ( $pref_count > 5 ) {
				break;
			}
			$seen[ $key ] = true;
			$merged[]     = $note;
		}

		// Add category-matched notes based on loaded tool groups.
		foreach ( $all_notes as $note ) {
			$cat = $note['category'] ?? '';
			$key = md5( $note['note'] ?? '' );
			if ( isset( $seen[ $key ] ) || 'preferences' === $cat ) {
				continue;
			}
			$mapped = $category_groups[ $cat ] ?? array();
			foreach ( $mapped as $group ) {
				if ( in_array( $group, $loaded, true ) ) {
					$seen[ $key ] = true;
					$merged[]     = $note;
					break;
				}
			}
		}

		return $merged;
	}

	/**
	 * Format notes into a compact context string with a 2400-char (~600 token) budget cap.
	 *
	 * @param array $notes Notes to format.
	 * @return string Formatted block or empty string.
	 */
	private function format_site_notes( array $notes ): string {
		if ( empty( $notes ) ) {
			return '';
		}

		$grouped = array();
		foreach ( $notes as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['note'] ) || empty( $entry['category'] ) ) {
				continue;
			}
			$cat = sanitize_text_field( (string) $entry['category'] );
			$grouped[ $cat ][] = sanitize_text_field( (string) $entry['note'] );
		}

		if ( empty( $grouped ) ) {
			return '';
		}

		$note_parts = array();
		foreach ( $grouped as $cat => $items ) {
			$note_parts[] = ucfirst( $cat ) . ': ' . implode( '; ', array_slice( $items, -5 ) );
		}

		$text = "\n\nSite Notes: " . implode( ' | ', $note_parts );

		// Hard cap: ~600 tokens.
		while ( strlen( $text ) > 2400 && count( $note_parts ) > 1 ) {
			array_shift( $note_parts );
			$text = "\n\nSite Notes: " . implode( ' | ', $note_parts );
		}

		return $text;
	}

	/**
	 * Use a helper model to select the most relevant notes for a given message.
	 *
	 * Falls back to null on any failure so the caller can use the pre-filtered set.
	 *
	 * @param array  $notes   Candidate notes (already pre-filtered).
	 * @param string $message User's current message.
	 * @return array|null Selected notes, or null on failure.
	 */
	private function ai_select_notes( array $notes, string $message ): ?array {
		$numbered = '';
		foreach ( $notes as $i => $note ) {
			$numbered .= ( $i + 1 ) . '. [' . ( $note['category'] ?? '' ) . '] ' . ( $note['note'] ?? '' ) . "\n";
		}

		$trimmed_msg = mb_substr( trim( $message ), 0, 200 );

		try {
			$result = $this->ai->send_message_raw(
				array( array(
					'role'    => 'user',
					'content' => "Given this user message: \"{$trimmed_msg}\"\n\nWhich of these site notes are relevant? Return ONLY the note numbers, comma-separated. If none relevant, return \"none\".\n\n" . $numbered,
				) ),
				array(),
				'Return only comma-separated note numbers or "none". No explanation.',
				false,
				array( 'phase' => 'memory_selection' )
			);

			$raw      = $result['raw'] ?? array();
			$provider = (string) ( $result['provider'] ?? '' );

			if ( ! empty( $raw['error'] ) ) {
				return null;
			}

			// Track cost.
			$round_input  = (int) ( $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0 );
			$round_output = $this->ai->extract_output_usage( $raw, $provider );
			$this->tokens_used        += $this->ai->extract_usage( $raw, $provider );
			$this->output_tokens_used += $round_output;
			$this->input_tokens_used  += $round_input;

			$cache = $result['cache_metrics'] ?? array();
			$this->cache_read_tokens  += (int) ( $cache['cache_read'] ?? 0 );
			$this->cache_write_tokens += (int) ( $cache['cache_write'] ?? 0 );

			$model      = (string) ( $result['model'] ?? $this->ai->get_model() );
			$multiplier = PressArk_Model_Policy::get_model_multiplier( $model );
			$this->icu_spent += (int) ceil(
				( $round_input * (int) ( $multiplier['input'] ?? 10 ) )
				+ ( $round_output * (int) ( $multiplier['output'] ?? 30 ) )
			);

			// Parse response: expect comma-separated numbers or "none".
			$text = trim( $this->ai->extract_text( $raw, $provider ) );

			if ( 'none' === strtolower( $text ) ) {
				return array();
			}

			$indices  = array_map( 'intval', preg_split( '/[,\s]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) );
			$selected = array();
			foreach ( $indices as $idx ) {
				$zero_based = $idx - 1;
				if ( isset( $notes[ $zero_based ] ) ) {
					$selected[] = $notes[ $zero_based ];
				}
			}

			return ! empty( $selected ) ? array_slice( $selected, 0, 15 ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function checkpoint_from_site_info( PressArk_Checkpoint $checkpoint, array $result ): void {
		// get_site_overview returns text in 'message', not structured data.
		// Just note the fact that site info was retrieved.
		$msg = $result['message'] ?? '';
		if ( preg_match( '/Pages:\s*(\d+)\s*\|\s*Posts:\s*(\d+)/', $msg, $m ) ) {
			$checkpoint->add_fact( 'total_pages', $m[1] );
			$checkpoint->add_fact( 'total_posts', $m[2] );
		}
	}

	/**
	 * Compact a tool result for in-loop messages.
	 *
	 * v4.3.0: Two-tier compaction:
	 * - Immediate: results > 800 tokens get compacted to key facts.
	 * - Age-based: results from rounds older than 2 get 1-line summaries.
	 *
	 * @since 2.4.0
	 * @since 4.3.0 Lower threshold (1500→800), age-based compaction.
	 *
	 * @param array $result     Tool result array.
	 * @param int   $result_age How many rounds ago this result was generated (0 = current).
	 * @return array Compacted result.
	 */
	private function compact_tool_result( array $result, int $result_age = 0 ): array {
		if ( ! empty( $result['_artifactized'] ) ) {
			return $result;
		}

		if ( isset( $result['data'] ) && is_array( $result['data'] ) && 'tool_result_artifact' === ( $result['data']['resource_type'] ?? '' ) ) {
			return $result;
		}

		// Age-based compaction: results from 3+ rounds ago get 1-line summaries.
		if ( $result_age >= 3 ) {
			$tool   = $result['_tool_name'] ?? 'tool';
			$msg    = $result['message'] ?? '';
			$status = ! empty( $result['data']['status'] ) ? " status={$result['data']['status']}" : '';
			$count  = '';
			if ( isset( $result['data'] ) && is_array( $result['data'] ) && isset( $result['data'][0] ) ) {
				$count = ' (' . count( $result['data'] ) . ' items)';
			}
			$summary = mb_substr( $msg, 0, 100 );
			return $this->attach_compacted_read_meta( array(
				'success'    => array_key_exists( 'success', $result ) ? (bool) $result['success'] : true,
				'message'    => "[Prior round: {$tool}{$status}{$count}] {$summary}",
				'_compacted' => 'age',
				'_tool_name' => $tool,
			), $result, 'summary', 'age_compaction' );
		}

		$est_tokens = $this->estimate_value_tokens( $result );

		// v4.3.0: Lowered threshold from 1500 → 1000 tokens.
		if ( $est_tokens <= 1000 ) {
			return $result; // Small enough, no compaction needed.
		}

		$data = $result['data'] ?? null;
		if ( ! is_array( $data ) ) {
			// Non-structured result: truncate the message.
			if ( ! empty( $result['message'] ) && mb_strlen( $result['message'] ) > 1200 ) {
				$result['message'] = mb_substr( $result['message'], 0, 1200 ) . '... [truncated]';
				$result['_compacted'] = true;
				$result = $this->attach_compacted_read_meta( $result, $result, 'summary', 'message_truncation' );
			}
			return $result;
		}

		// read_content result: strip raw content, keep metadata.
		if ( isset( $data['content'] ) && isset( $data['title'] ) ) {
			$content_len = mb_strlen( $data['content'] );
			unset( $result['data']['content'] );
			$result['data']['_compacted']      = true;
			$result['data']['_content_length'] = $content_len;
			$result['message'] = ( $result['message'] ?? '' ) . ' [Content compacted; full result sent to user.]';
			return $this->attach_compacted_read_meta( $result, $result, 'preview', 'content_preview' );
		}

		// Security scan: extract key findings only.
		if ( ! empty( $data['issues'] ) && is_array( $data['issues'] ) ) {
			$issue_count = count( $data['issues'] );
			$summaries   = array();
			foreach ( array_slice( $data['issues'], 0, 5 ) as $issue ) {
				$summaries[] = $issue['title'] ?? $issue['id'] ?? 'issue';
			}
			return $this->attach_compacted_read_meta( array(
				'message'    => sprintf(
					'Security scan: %d issues found (%s).%s',
					$issue_count,
					implode( ', ', $summaries ),
					$issue_count > 5 ? ' Plus ' . ( $issue_count - 5 ) . ' more.' : ''
				),
				'data'       => array( 'issue_count' => $issue_count, '_compacted' => true ),
				'_compacted' => true,
			), $result, 'summary', 'issue_summary' );
		}

		// Search results array: keep first 5 items, truncate content.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$result['_total_results'] = count( $data );
			$result['_hint']          = 'Use "offset" and "limit" params to paginate, or "search" to filter.';
			$result['data']           = array_slice( $data, 0, 5 );
			foreach ( $result['data'] as &$item ) {
				if ( isset( $item['content'] ) && mb_strlen( $item['content'] ) > 200 ) {
					$item['content'] = mb_substr( $item['content'], 0, 200 ) . '...';
				}
				if ( isset( $item['snippet'] ) && mb_strlen( $item['snippet'] ) > 200 ) {
					$item['snippet'] = mb_substr( $item['snippet'], 0, 200 ) . '...';
				}
			}
			unset( $item );
			$result['_compacted'] = true;
			return $this->attach_compacted_read_meta( $result, $result, 'preview', 'list_preview' );
		}

		// Generic: slice the data to first 8 keys.
		$result['data']       = array_slice( $data, 0, 8, true );
		$result['_compacted'] = true;
		return $this->attach_compacted_read_meta( $result, $result, 'preview', 'generic_preview' );
	}

	private function attach_compacted_read_meta( array $compacted, array $source_result, string $completeness, string $reason ): array {
		if ( ! class_exists( 'PressArk_Read_Metadata' ) || empty( $source_result['read_meta'] ) ) {
			return $compacted;
		}

		$compacted['read_meta'] = PressArk_Read_Metadata::preview_meta(
			(array) $source_result['read_meta'],
			array(
				'completeness' => $completeness,
				'provider'     => 'live_compaction',
				'reason'       => $reason,
			)
		);
		return $compacted;
	}

	/**
	 * Replace oversized raw tool results with a compact retry stub.
	 *
	 * @param array  $result    Raw tool result.
	 * @param string $tool_name Tool name that produced the result.
	 * @return array
	 */
	private function enforce_tool_result_limit( array $result, string $tool_name ): array {
		$estimated_tokens = $this->estimate_value_tokens( $result );
		if ( $estimated_tokens <= self::MAX_TOOL_RESULT_TOKENS ) {
			return $result;
		}

		if ( PressArk_Tool_Result_Artifacts::should_preserve_full_result( $tool_name, $result ) ) {
			return $result;
		}

		return array(
			'success' => false,
			'error'   => 'tool_output_limit_exceeded',
			'message' => sprintf(
				'Tool response exceeded the allowed limit of %d tokens. Try another way or a tighter call.',
				self::MAX_TOOL_RESULT_TOKENS
			),
			'data'    => array(
				'tool'             => sanitize_key( $tool_name ),
				'estimated_tokens' => $estimated_tokens,
				'max_tokens'       => self::MAX_TOOL_RESULT_TOKENS,
				'retry_hint'       => 'Response too large. Try: (1) add a "limit" or "count" parameter to reduce results, (2) add a "search" or "filter" parameter to narrow results, (3) use "mode":"light" for compact output, (4) request specific fields with "fields" parameter, (5) use "offset" to paginate through results.',
			),
			'_tool_name'                  => sanitize_key( $tool_name ),
			'_tool_output_limit_exceeded' => true,
		);
	}

	/**
	 * Detect the synthetic oversized-result stub.
	 *
	 * @param array $result Tool result.
	 * @return bool
	 */
	private function is_tool_result_limit_result( array $result ): bool {
		return ! empty( $result['_tool_output_limit_exceeded'] );
	}

	// ── Infrastructure ──────────────────────────────────────────────────

	/**
	 * Build the live loop transcript, restoring checkpoint replay state when a
	 * continuation resumes after an approval or budget boundary.
	 */
	private function build_initial_loop_messages( array $history, string $message, PressArk_Checkpoint $checkpoint ): array {
		$fallback_messages = array();
		foreach ( $history as $msg ) {
			$role = $msg['role'] ?? 'user';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$fallback_messages[] = array(
					'role'    => $role,
					'content' => $msg['content'] ?? '',
				);
			}
		}
		$fallback_messages[] = array( 'role' => 'user', 'content' => $message );

		$messages = $fallback_messages;
		if ( self::is_continuation_message( $message ) && class_exists( 'PressArk_Replay_Integrity' ) ) {
			$replay_messages = $checkpoint->get_replay_messages();
			$this->record_activity_event(
				'run.resumed',
				'resume_after_checkpoint',
				'resumed',
				'resume',
				'Execution resumed from a checkpoint boundary.',
				array(
					'used_checkpoint_replay' => ! empty( $replay_messages ),
					'history_messages'       => count( $history ),
				)
			);
			$resume_event    = array(
				'type'                   => 'resume',
				'phase'                  => 'resume_boundary',
				'source'                 => ! empty( $replay_messages ) ? 'checkpoint_replay' : 'conversation_reconstruction',
				'used_checkpoint_replay' => ! empty( $replay_messages ),
				'at'                     => gmdate( 'c' ),
			);

			if ( ! empty( $replay_messages ) ) {
				$messages   = PressArk_Replay_Integrity::canonicalize_messages( $replay_messages );
				$messages[] = array( 'role' => 'user', 'content' => $message );
			}

			$resume_event['message_count_before'] = $this->count_replay_messages( $messages );
			$repair                              = $this->repair_replay_messages( $messages, $checkpoint, 'resume_boundary', 0 );
			$messages                            = $repair['messages'];
			$resume_event['message_count_after'] = $this->count_replay_messages( $messages );
			$resume_event['repaired']            = ! empty( $repair['changed'] );
			$checkpoint->set_last_replay_resume( $resume_event );
			$this->sync_replay_snapshot( $checkpoint, $messages );

			return $messages;
		}

		$this->sync_replay_snapshot( $checkpoint, $messages );
		return $messages;
	}

	/**
	 * Canonical message count for replay telemetry.
	 */
	private function count_replay_messages( array $messages ): int {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return count( $messages );
		}

		return count( PressArk_Replay_Integrity::canonicalize_messages( $messages ) );
	}

	/**
	 * Repair transcript structure before provider calls or resume boundaries.
	 *
	 * @return array{messages:array,changed:bool,event:array}
	 */
	private function repair_replay_messages(
		array $messages,
		?PressArk_Checkpoint $checkpoint,
		string $phase,
		int $round = 0
	): array {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return array(
				'messages' => $messages,
				'changed'  => false,
				'event'    => array(),
			);
		}

		$repair   = PressArk_Replay_Integrity::repair_messages( $messages, $phase );
		$messages = $repair['messages'];

		if ( $checkpoint ) {
			if ( ! empty( $repair['changed'] ) ) {
				$event = (array) ( $repair['event'] ?? array() );
				if ( $round > 0 && empty( $event['round'] ) ) {
					$event['round'] = $round;
				}
				$checkpoint->add_replay_event( $event );

				if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
					PressArk_Error_Tracker::debug( 'Replay', sprintf( 'Transcript repaired at %s (round %d).', $phase, max( 0, $round ) ) );
				}
			}

			$this->sync_replay_snapshot( $checkpoint, $messages );
		}

		return array(
			'messages' => $messages,
			'changed'  => ! empty( $repair['changed'] ),
			'event'    => (array) ( $repair['event'] ?? array() ),
		);
	}

	/**
	 * Persist a bounded replay transcript snapshot to checkpoint sidecar state.
	 */
	private function sync_replay_snapshot( ?PressArk_Checkpoint $checkpoint, array $messages ): void {
		if ( ! $checkpoint || ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return;
		}

		$state               = $checkpoint->get_replay_state();
		$snapshot            = PressArk_Replay_Integrity::snapshot_messages( $messages );
		$state['messages']   = (array) ( $snapshot['messages'] ?? array() );
		$state['updated_at'] = gmdate( 'c' );
		$checkpoint->set_replay_state( $state );
	}

	/**
	 * Append tool results to messages array in the correct provider format.
	 *
	 * @param array  &$messages     Messages array (modified by reference).
	 * @param array  $tool_results  Array of ['tool_use_id' => string, 'result' => mixed].
	 * @param string $provider      Provider name.
	 */
	private function append_tool_results( array &$messages, array $tool_results, string $provider ): void {
		$result_message = $this->ai->build_tool_result_message( $tool_results, $provider );

		if ( ! empty( $result_message['__multi'] ) ) {
			// OpenAI format: multiple tool role messages.
			foreach ( $result_message['messages'] as $msg ) {
				$messages[] = $msg;
			}
		} else {
			// Anthropic format: single user message with all results.
			$messages[] = $result_message;
		}
	}

	/**
	 * Prepare read results for prompt insertion.
	 *
	 * Large, high-value reads are first externalized into run-scoped artifacts.
	 * Anything still inline then falls back to the legacy compaction path.
	 *
	 * @param array[]                  $tool_results Read tool results.
	 * @param int                      $round        Current loop round.
	 * @param PressArk_Checkpoint|null $checkpoint   Optional replay checkpoint.
	 * @return array[]
	 */
	private function prepare_tool_results_for_prompt( array $tool_results, int $round, ?PressArk_Checkpoint $checkpoint = null ): array {
		if ( empty( $tool_results ) ) {
			return array();
		}

		$artifacts = new PressArk_Tool_Result_Artifacts(
			$this->run_id,
			$this->chat_id,
			get_current_user_id(),
			$round
		);

		$prepared = $artifacts->prepare_batch(
			$tool_results,
			$checkpoint ? $checkpoint->get_replay_replacements() : array()
		);

		if ( $checkpoint ) {
			$checkpoint->merge_replay_replacements( $artifacts->get_replacement_journal() );
			foreach ( $artifacts->get_replacement_events() as $event ) {
				if ( empty( $event['round'] ) ) {
					$event['round'] = $round;
				}
				$checkpoint->add_replay_event( $event );
			}
		}

		return array_map(
			function ( array $tr ): array {
				if ( ! empty( $tr['_artifactized'] ) || ! empty( $tr['result']['_artifactized'] ) ) {
					return $tr;
				}

				$tr['result'] = $this->compact_tool_result( $tr['result'] );
				return $tr;
			},
			$prepared
		);
	}

	/**
	 * Estimate token count for messages array.
	 * Uses 4 chars/token heuristic.
	 */
	private function estimate_messages_tokens( array $messages ): int {
		return $this->estimate_value_tokens( $messages );
	}

	/**
	 * Estimate token count for an arbitrary payload using the standard 4 chars/token heuristic.
	 *
	 * @param mixed $value Arbitrary payload.
	 * @return int
	 */
	private function estimate_value_tokens( $value ): int {
		if ( $this->budget_manager instanceof PressArk_Token_Budget_Manager ) {
			return $this->budget_manager->estimate_value_tokens( $value );
		}

		$serialized = is_string( $value ) ? $value : wp_json_encode( $value );
		if ( ! is_string( $serialized ) ) {
			$serialized = '';
		}

		return (int) ceil( mb_strlen( $serialized ) / 4 );
	}

	/**
	 * Compact messages mid-loop to prevent context overflow.
	 *
	 * v4.3.0: AI-summarized compaction (Cursor pattern). Instead of dropping
	 * messages silently, makes a cheap model call to summarize the conversation
	 * so far. This costs ~300 output tokens but compresses 5K+ tokens of
	 * history to ~200 tokens. Falls back to simple truncation if the AI call
	 * fails or would exceed budget.
	 *
	 * @param array $messages All messages in the loop.
	 * @param int   $round    Current round number (for budget decisions).
	 * @return array Compacted messages array.
	 */
private function compact_loop_messages_legacy( array $messages, int $round = 0, ?PressArk_Checkpoint $checkpoint = null, array $request_budget = array(), string $reason = 'message_threshold' ): array {
		if ( count( $messages ) > 5 ) {
			$first_user_message = $messages[0];
			$recent             = array_slice( $messages, -4 );
			$dropped_count      = count( $messages ) - 5;
			$middle             = array_slice( $messages, 1, -4 );
			$marker             = $checkpoint ? $this->build_compaction_marker( $checkpoint, $round ) : '';

			$capsule = $this->build_context_capsule( $middle, $checkpoint );
			if ( ! empty( $capsule ) ) {
				if ( $checkpoint ) {
					$checkpoint->set_context_capsule( $capsule );
				}
				$summary_text = $this->build_context_capsule_text( $capsule );
				$summary      = array(
					'role'    => 'user',
					'content' => "[Context compaction boundary {$marker}]\nTrigger: {$reason}. Treat the summary below as historical state only.\n[Conversation summary (rounds 1-{$dropped_count})]\n{$summary_text}\n[End compaction boundary {$marker} â€” continuing from latest results below.]",
				);
				$compacted    = array_merge( array( $first_user_message, $summary ), $recent );
				if ( $checkpoint ) {
					$this->record_compaction_event(
						$checkpoint,
						$round,
						$reason,
						$messages,
						$compacted,
						$request_budget,
						$marker,
						'capsule_summary'
					);
				}
				return $compacted;
			}

			$summary = array(
				'role'    => 'user',
				'content' => sprintf(
					'[Context compaction boundary %s] Trigger: %s. %d earlier tool call rounds were compacted. The task continues from the latest results below. [End compaction boundary %s]',
					$marker,
					$reason,
					$dropped_count,
					$marker
				),
			);
			$compacted = array_merge( array( $first_user_message, $summary ), $recent );
			if ( $checkpoint ) {
				$this->record_compaction_event(
					$checkpoint,
					$round,
					$reason,
					$messages,
					$compacted,
					$request_budget,
					$marker,
					'context_note'
				);
			}
			return $compacted;
		}

		if ( count( $messages ) <= 5 ) {
			return $messages;
		}

		$first_user_message = $messages[0];
		$recent             = array_slice( $messages, -4 );
		$dropped_count      = count( $messages ) - 5;
		$middle             = array_slice( $messages, 1, -4 );

		$capsule = $this->build_context_capsule( $middle, $checkpoint );
		if ( ! empty( $capsule ) ) {
			if ( $checkpoint ) {
				$checkpoint->set_context_capsule( $capsule );
			}
			$summary_text = $this->build_context_capsule_text( $capsule );
			$summary      = array(
					'role'    => 'user',
					'content' => "[Conversation summary (rounds 1-{$dropped_count})]\n{$summary_text}\n[End summary — continuing from latest results below.]",
				);
				return array_merge( array( $first_user_message, $summary ), $recent );
			}
		// Fallback: simple context note.
		$summary = array(
			'role'    => 'user',
			'content' => sprintf(
				'[Context note: %d earlier tool call rounds were compacted. The task continues from the latest results below.]',
				$dropped_count
			),
		);

		return array_merge( array( $first_user_message, $summary ), $recent );
	}

	/**
	 * Compact messages mid-loop while preserving whole assistant API rounds.
	 */
	private function compact_loop_messages( array $messages, int $round = 0, ?PressArk_Checkpoint $checkpoint = null, array $request_budget = array(), string $reason = 'message_threshold' ): array {
		if ( count( $messages ) <= 5 ) {
			return $messages;
		}

		$first_user_message = $messages[0];
		$window             = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::select_round_compaction_window( $messages, 2, 4 )
			: array(
				'recent_messages'  => array_slice( $messages, -4 ),
				'dropped_messages' => array_slice( $messages, 1, -4 ),
				'dropped_rounds'   => 0,
				'kept_rounds'      => 0,
				'used_rounds'      => false,
			);
		$recent             = ! empty( $window['recent_messages'] ) ? (array) $window['recent_messages'] : array_slice( $messages, -4 );
		$middle             = ! empty( $window['dropped_messages'] ) ? (array) $window['dropped_messages'] : array_slice( $messages, 1, -4 );
		$dropped_count      = count( $middle );

		if ( $dropped_count <= 0 ) {
			return $messages;
		}

		$marker  = $checkpoint ? $this->build_compaction_marker( $checkpoint, $round ) : '';
		$capsule = $this->build_context_capsule( $middle, $checkpoint );

		if ( ! empty( $capsule ) ) {
			if ( $checkpoint ) {
				$checkpoint->set_context_capsule( $capsule );
			}
			$summary_text = $this->build_context_capsule_text( $capsule );
			$summary      = array(
				'role'    => 'user',
				'content' => "[Context compaction boundary {$marker}]\nTrigger: {$reason}. Treat the summary below as historical state only.\n[Conversation summary]\n{$summary_text}\n[End compaction boundary {$marker}; continue from the latest round below.]",
			);
			$compacted    = array_merge( array( $first_user_message, $summary ), $recent );
			if ( $checkpoint ) {
				$this->record_compaction_event(
					$checkpoint,
					$round,
					$reason,
					$messages,
					$compacted,
					$request_budget,
					$marker,
					'capsule_summary'
				);
				$checkpoint->add_replay_event( array(
					'type'                 => 'compaction',
					'phase'                => 'live_loop',
					'source'               => ! empty( $window['used_rounds'] ) ? 'api_rounds' : 'tail_window',
					'reason'               => $reason,
					'round'                => max( 1, $round ),
					'message_count_before' => count( $messages ),
					'message_count_after'  => count( $compacted ),
					'dropped_messages'     => $dropped_count,
					'dropped_rounds'       => max( 0, (int) ( $window['dropped_rounds'] ?? 0 ) ),
					'kept_rounds'          => max( 0, (int) ( $window['kept_rounds'] ?? 0 ) ),
					'at'                   => gmdate( 'c' ),
				) );
				$this->sync_replay_snapshot( $checkpoint, $compacted );
			}
			return $compacted;
		}

		$summary = array(
			'role'    => 'user',
			'content' => sprintf(
				'[Context compaction boundary %s] Trigger: %s. %d earlier message(s) across %d completed round(s) were compacted. The task continues from the latest round below. [End compaction boundary %s]',
				$marker,
				$reason,
				$dropped_count,
				max( 0, (int) ( $window['dropped_rounds'] ?? 0 ) ),
				$marker
			),
		);
		$compacted = array_merge( array( $first_user_message, $summary ), $recent );
		if ( $checkpoint ) {
			$this->record_compaction_event(
				$checkpoint,
				$round,
				$reason,
				$messages,
				$compacted,
				$request_budget,
				$marker,
				'context_note'
			);
			$checkpoint->add_replay_event( array(
				'type'                 => 'compaction',
				'phase'                => 'live_loop',
				'source'               => ! empty( $window['used_rounds'] ) ? 'api_rounds' : 'tail_window',
				'reason'               => $reason,
				'round'                => max( 1, $round ),
				'message_count_before' => count( $messages ),
				'message_count_after'  => count( $compacted ),
				'dropped_messages'     => $dropped_count,
				'dropped_rounds'       => max( 0, (int) ( $window['dropped_rounds'] ?? 0 ) ),
				'kept_rounds'          => max( 0, (int) ( $window['kept_rounds'] ?? 0 ) ),
				'at'                   => gmdate( 'c' ),
			) );
			$this->sync_replay_snapshot( $checkpoint, $compacted );
		}

		return $compacted;
	}

	/**
	 * AI-summarized compaction using the dedicated summarize phase.
	 *
	 * Returns a structured capsule fragment so deterministic checkpoint data
	 * can be merged with model-written summaries without losing exact titles,
	 * IDs, values, or other task-critical details.
	 *
	 * @param array $messages Messages to summarize.
	 * @return array<string,mixed>
	 */
	private function compact_with_ai( array $messages, ?PressArk_Checkpoint $checkpoint = null ): array {
		$task              = $this->resolve_capsule_task( $checkpoint, $messages );
		$historical_tasks  = $this->extract_historical_requests( $messages, $task );
		$target_label      = $this->format_capsule_target( $checkpoint );
		$preserved_details = $this->collect_preserved_detail_lines( $checkpoint, $messages );
		$state_lines       = array();

		if ( '' !== $task ) {
			$state_lines[] = 'Active request: ' . $task;
		}
		if ( ! empty( $historical_tasks ) ) {
			$state_lines[] = 'Historical requests: ' . implode( '; ', array_slice( $historical_tasks, 0, 3 ) );
		}
		if ( '' !== $target_label ) {
			$state_lines[] = 'Target: ' . $target_label;
		}

		if ( $checkpoint ) {
			if ( '' !== $checkpoint->get_workflow_stage() ) {
				$state_lines[] = 'Stage: ' . sanitize_text_field( $checkpoint->get_workflow_stage() );
			}

			$progress = PressArk_Execution_Ledger::progress_snapshot( $checkpoint->get_execution() );
			if ( ! empty( $progress['completed_labels'] ) ) {
				$state_lines[] = 'Completed: ' . implode( '; ', array_slice( array_map( 'sanitize_text_field', (array) $progress['completed_labels'] ), 0, 4 ) );
			}
			if ( ! empty( $progress['remaining_labels'] ) ) {
				$state_lines[] = 'Remaining: ' . implode( '; ', array_slice( array_map( 'sanitize_text_field', (array) $progress['remaining_labels'] ), 0, 4 ) );
			}

			$receipts = $this->collect_recent_receipts( $checkpoint );
			if ( ! empty( $receipts ) ) {
				$state_lines[] = 'Recent receipts: ' . implode( '; ', array_slice( $receipts, 0, 4 ) );
			}
		}

		$parts = array();
		foreach ( array_slice( $messages, -6 ) as $msg ) {
			$role    = sanitize_key( (string) ( $msg['role'] ?? 'unknown' ) );
			$content = '';
			if ( is_string( $msg['content'] ?? null ) ) {
				$content = trim( (string) $msg['content'] );
			} elseif ( is_array( $msg['content'] ?? null ) ) {
				$content = trim( (string) wp_json_encode( $msg['content'] ) );
			}
			if ( '' !== $content ) {
				$parts[] = "[{$role}] " . mb_substr( $content, 0, 260 );
			}
		}

		$payload_parts = array();
		if ( ! empty( $state_lines ) ) {
			$payload_parts[] = "STATE\n" . implode( "\n", $state_lines );
		}
		if ( ! empty( $preserved_details ) ) {
			$payload_parts[] = "PRESERVE EXACT\n- " . implode( "\n- ", $preserved_details );
		}
		if ( ! empty( $parts ) ) {
			$payload_parts[] = "RECENT TRANSCRIPT\n" . implode( "\n", $parts );
		}

		if ( empty( $payload_parts ) ) {
			return array();
		}

		$to_summarize = trim( implode( "\n\n", $payload_parts ) );
		if ( mb_strlen( $to_summarize ) > 4500 ) {
			$to_summarize = mb_substr( $to_summarize, 0, 4500 ) . '...';
		}

		$summary_messages = array(
			array(
				'role'    => 'user',
				'content' => "Create a detailed continuation summary. This replaces the conversation history — include everything needed to continue without losing context.\n\nReturn JSON only with keys summary, completed, remaining, preserved_details.\n\nStructure your summary covering:\n1. USER REQUEST: What the user asked, with clarifications and constraints.\n2. COMPLETED ACTIONS: Every action applied — post/product IDs, titles, fields changed (before → after), new IDs from creates.\n3. CURRENT STATE: Site state after all changes.\n4. FINDINGS: Discoveries from reads/analysis — SEO issues, security results, content problems, WooCommerce data observed.\n5. PENDING: What the user still wants done.\n6. USER PREFERENCES: Style, tone, or approach preferences expressed.\n\nRules:\n- Be specific: include post IDs, product names, exact values. 'Edited some products' is useless — say 'edited Product #42 (Blue Widget): price \$19.99 → \$24.99'.\n- Preserve exact IDs, titles, slugs, SKUs, prices, URLs, counts verbatim.\n- completed and remaining must be short bullet-like strings.\n- preserved_details should keep task-critical exact details needed to finish the task.\n- Treat any historical_request or past_request line as past context only, never as the active instruction.\n- The active request is the only live instruction unless a receipt says it is already completed.\n- If a detail is uncertain, omit it instead of guessing.\n\n{$to_summarize}",
			),
		);

		try {
			$result = $this->ai->send_message_raw(
				$summary_messages,
				array(),
				'You create detailed continuation summaries that replace conversation history. Output valid JSON only. Be maximally specific — include exact IDs, titles, values, and before/after states. Never paraphrase task-critical details.',
				false,
				array(
					'phase'              => 'summarize',
					'proxy_route'        => 'summarize',
					'estimated_icus'     => 1200, // Approximate ICU cost of summarization.
					'deliverable_schema' => array(
						'summary'           => 'string',
						'completed'         => array( 'string' ),
						'remaining'         => array( 'string' ),
						'preserved_details' => array( 'string' ),
					),
					'schema_mode'        => 'strict',
				)
			);

			$raw      = $result['raw'] ?? array();
			$provider = (string) ( $result['provider'] ?? '' );

			if ( ! empty( $raw['error'] ) ) {
				return array();
			}

			$round_input_tokens  = (int) ( $raw['usage']['prompt_tokens'] ?? $raw['usage']['input_tokens'] ?? 0 );
			$round_output_tokens = $this->ai->extract_output_usage( $raw, $provider );
			$this->tokens_used        += $this->ai->extract_usage( $raw, $provider );
			$this->output_tokens_used += $round_output_tokens;
			$this->input_tokens_used  += $round_input_tokens;

			$cache = $result['cache_metrics'] ?? array();
			$this->cache_read_tokens  += (int) ( $cache['cache_read'] ?? 0 );
			$this->cache_write_tokens += (int) ( $cache['cache_write'] ?? 0 );

			$summary_model      = (string) ( $result['model'] ?? $this->ai->get_model() );
			$summary_multiplier = PressArk_Model_Policy::get_model_multiplier( $summary_model );
			$this->icu_spent += (int) ceil(
				( $round_input_tokens * (int) ( $summary_multiplier['input'] ?? 10 ) )
				+ ( $round_output_tokens * (int) ( $summary_multiplier['output'] ?? 30 ) )
			);

			$parsed                      = $this->parse_compacted_capsule_response( $this->ai->extract_text( $raw, $provider ) );
			$parsed['compression_model'] = sanitize_text_field( $summary_model );
			return $parsed;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * v5.0.6: Token-based compression triggers (replaces ICU-based).
	 *
	 * Uses total tokens consumed (input + output) across all rounds instead
	 * of ICUs. This is model-agnostic — 258K tokens means the same thing
	 * whether using DeepSeek V3 or Claude Opus 4.
	 */

	private function should_prime_context_capsule( array $messages, ?PressArk_Checkpoint $checkpoint, int $token_budget, array $request_budget = array() ): bool {
		if ( ! $checkpoint || count( $messages ) < 4 ) {
			return false;
		}

		$soft_output_limit = (int) floor( $token_budget * self::SOFT_OUTPUT_BUDGET_RATIO );
		$soft_total_limit  = (int) floor( self::MAX_REQUEST_TOKENS * self::SOFT_PRIME_TOKEN_RATIO );
		$needs_capsule     = $this->output_tokens_used >= $soft_output_limit
			|| $this->tokens_used >= $soft_total_limit
			|| $this->should_prime_for_request_headroom( $request_budget );

		if ( ! $needs_capsule ) {
			return false;
		}

		$capsule = $checkpoint->get_context_capsule();
		if ( empty( $capsule ) ) {
			return true;
		}

		$updated_at = strtotime( (string) ( $capsule['updated_at'] ?? '' ) );
		return ! $updated_at || ( time() - $updated_at ) >= 30;
	}

	private function should_compact_live_context( array $messages, int $token_budget, ?int $estimated_tokens = null, array $request_budget = array() ): bool {
		if ( count( $messages ) <= 5 ) {
			return false;
		}

		$soft_output_limit = (int) floor( $token_budget * self::LIVE_COMPACTION_TOKEN_RATIO );
		$soft_total_limit  = (int) floor( self::MAX_REQUEST_TOKENS * self::SOFT_COMPACTION_TOKEN_RATIO );

		return $this->output_tokens_used >= $soft_output_limit
			|| $this->tokens_used >= $soft_total_limit
			|| $this->should_compact_for_request_headroom( $request_budget );
	}

	private function should_pause_for_request_budget( array $request_budget = array() ): bool {
		$remaining_tokens = self::MAX_REQUEST_TOKENS - $this->tokens_used;
		return $remaining_tokens <= self::PAUSE_HEADROOM_TOKENS
			|| ( ! empty( $request_budget ) && ( empty( $request_budget['fits_context_window'] ) || (int) ( $request_budget['remaining_tokens'] ?? 0 ) <= 0 ) );
	}

	private function resolve_request_headroom_floor( array $request_budget = array() ): int {
		$reserved = (array) ( $request_budget['reserved'] ?? array() );
		$floor    = (int) ceil(
			(
				(int) ( $reserved['follow_up_tools'] ?? 0 )
				+ (int) ( $reserved['safety_margin'] ?? 0 )
			) / 2
		);

		return max( 1024, min( 4096, $floor ?: 1024 ) );
	}

	private function should_prime_for_request_headroom( array $request_budget = array() ): bool {
		if ( empty( $request_budget ) ) {
			return false;
		}

		$remaining = max( 0, (int) ( $request_budget['remaining_tokens'] ?? 0 ) );
		$floor     = $this->resolve_request_headroom_floor( $request_budget );
		$pressure  = (string) ( $request_budget['context_pressure'] ?? '' );

		return empty( $request_budget['fits_context_window'] )
			|| $remaining <= max( 1536, $floor * 2 )
			|| ( 'critical' === $pressure && $remaining <= max( 3072, $floor * 3 ) );
	}

	private function should_compact_for_request_headroom( array $request_budget = array() ): bool {
		if ( empty( $request_budget ) ) {
			return false;
		}

		$remaining = max( 0, (int) ( $request_budget['remaining_tokens'] ?? 0 ) );
		$floor     = $this->resolve_request_headroom_floor( $request_budget );
		$pressure  = (string) ( $request_budget['context_pressure'] ?? '' );

		return empty( $request_budget['fits_context_window'] )
			|| $remaining <= $floor
			|| ( 'critical' === $pressure && $remaining <= max( 2048, $floor * 2 ) );
	}

	private function resolve_live_compaction_reason( int $estimated_tokens, int $compaction_threshold, array $request_budget = array() ): string {
		if ( $this->should_compact_for_request_headroom( $request_budget ) ) {
			return 'reserved_headroom';
		}

		if ( $estimated_tokens > $compaction_threshold ) {
			return 'message_threshold';
		}

		return 'token_ratio';
	}

	private function has_ai_compaction_headroom(): bool {
		$remaining_output = max( 0, (int) PressArk_Entitlements::tier_value( $this->tier, 'agent_token_budget' ) - $this->output_tokens_used );
		$remaining_tokens = max( 0, self::MAX_REQUEST_TOKENS - $this->tokens_used );

		return $remaining_output > self::SUMMARY_HEADROOM_OUT_TOKENS
			&& $remaining_tokens > self::PAUSE_HEADROOM_TOKENS;
	}

	private function prime_context_capsule( PressArk_Checkpoint $checkpoint, array $messages, int $round, bool $force_ai = false ): void {
		$checkpoint->set_turn( $round );
		$checkpoint->set_loaded_tool_groups( $this->loaded_groups );

		$capsule = $this->build_context_capsule( $messages, $checkpoint, $force_ai );
		if ( ! empty( $capsule ) ) {
			$checkpoint->set_context_capsule( $capsule );
		}
	}

	private function build_compaction_marker( PressArk_Checkpoint $checkpoint, int $round ): string {
		$capsule    = $checkpoint->get_context_capsule();
		$compaction = is_array( $capsule['compaction'] ?? null ) ? (array) $capsule['compaction'] : array();
		$count      = max( 0, (int) ( $compaction['count'] ?? 0 ) ) + 1;

		return sanitize_key( sprintf( 'cmp_r%d_c%d', max( 1, $round ), $count ) );
	}

	private function record_compaction_event(
		PressArk_Checkpoint $checkpoint,
		int $round,
		string $reason,
		array $before_messages,
		array $after_messages,
		array $request_budget = array(),
		string $marker = '',
		string $summary_mode = 'capsule_summary'
	): void {
		$capsule    = $checkpoint->get_context_capsule();
		$compaction = is_array( $capsule['compaction'] ?? null ) ? (array) $capsule['compaction'] : array();
		$marker     = '' !== $marker ? sanitize_key( $marker ) : $this->build_compaction_marker( $checkpoint, $round );
		$event      = array(
			'marker'                  => $marker,
			'reason'                  => sanitize_key( $reason ),
			'round'                   => max( 1, $round ),
			'before_messages'         => count( $before_messages ),
			'after_messages'          => count( $after_messages ),
			'dropped_messages'        => max( 0, count( $before_messages ) - count( $after_messages ) ),
			'estimated_tokens_before' => $this->estimate_messages_tokens( $before_messages ),
			'estimated_tokens_after'  => $this->estimate_messages_tokens( $after_messages ),
			'remaining_tokens'        => max( 0, (int) ( $request_budget['remaining_tokens'] ?? 0 ) ),
			'context_pressure'        => sanitize_key( (string) ( $request_budget['context_pressure'] ?? '' ) ),
			'summary_mode'            => sanitize_key( $summary_mode ),
			'at'                      => gmdate( 'c' ),
		);

		$compaction['count']                  = max( 0, (int) ( $compaction['count'] ?? 0 ) ) + 1;
		$compaction['last_marker']            = $marker;
		$compaction['last_reason']            = $event['reason'];
		$compaction['last_round']             = $event['round'];
		$compaction['last_at']                = $event['at'];
		$compaction['last_event']             = $event;
		$compaction['pending_post_compaction'] = array(
			'marker' => $marker,
			'reason' => $event['reason'],
			'round'  => $event['round'],
			'at'     => $event['at'],
		);

		$capsule['compaction'] = $compaction;
		if ( empty( $capsule['updated_at'] ) ) {
			$capsule['updated_at'] = gmdate( 'c' );
		}
		$checkpoint->set_context_capsule( $capsule );
	}

	private function record_compaction_pause(
		?PressArk_Checkpoint $checkpoint,
		array $messages,
		int $round,
		string $reason,
		array $request_budget = array()
	): void {
		if ( ! $checkpoint ) {
			return;
		}

		$this->record_activity_event(
			'run.degraded',
			'request_headroom_pause' === $reason ? 'degraded_request_headroom' : 'degraded_token_budget',
			'degraded',
			'pause',
			'Execution paused after compaction or budget pressure.',
			array(
				'round'            => $round,
				'remaining_tokens' => max( 0, (int) ( $request_budget['remaining_tokens'] ?? 0 ) ),
				'context_pressure' => sanitize_key( (string) ( $request_budget['context_pressure'] ?? '' ) ),
			)
		);

		$marker = $this->build_compaction_marker( $checkpoint, $round );
		$this->record_compaction_event(
			$checkpoint,
			$round,
			$reason,
			$messages,
			$messages,
			$request_budget,
			$marker,
			'checkpoint_capsule'
		);
	}

	private function observe_post_compaction_turn(
		?PressArk_Checkpoint $checkpoint,
		int $round,
		string $stop_reason,
		array $tool_calls,
		string $text,
		array $request_budget = array()
	): void {
		if ( ! $checkpoint ) {
			return;
		}

		$capsule    = $checkpoint->get_context_capsule();
		$compaction = is_array( $capsule['compaction'] ?? null ) ? (array) $capsule['compaction'] : array();
		$pending    = is_array( $compaction['pending_post_compaction'] ?? null ) ? (array) $compaction['pending_post_compaction'] : array();
		if ( empty( $pending['marker'] ) ) {
			return;
		}

		$trimmed = trim( $text );
		$healthy = '' !== $trimmed || ! empty( $tool_calls );

		$compaction['first_post_compaction'] = array(
			'marker'          => sanitize_key( (string) $pending['marker'] ),
			'reason'          => sanitize_key( (string) ( $pending['reason'] ?? '' ) ),
			'observed_round'  => max( 1, $round ),
			'stop_reason'     => sanitize_key( $stop_reason ),
			'tool_calls'      => count( $tool_calls ),
			'had_text'        => '' !== $trimmed,
			'healthy'         => $healthy,
			'remaining_tokens'=> max( 0, (int) ( $request_budget['remaining_tokens'] ?? 0 ) ),
			'context_pressure'=> sanitize_key( (string) ( $request_budget['context_pressure'] ?? '' ) ),
			'at'              => gmdate( 'c' ),
		);
		unset( $compaction['pending_post_compaction'] );

		$capsule['compaction'] = $compaction;
		$checkpoint->set_context_capsule( $capsule );
	}

	private function build_context_capsule( array $messages, ?PressArk_Checkpoint $checkpoint = null, bool $force_ai = false ): array {
		$task              = $this->resolve_capsule_task( $checkpoint, $messages );
		$historical_tasks  = $this->extract_historical_requests( $messages, $task );
		$target_snapshot   = $this->format_capsule_target( $checkpoint );
		$summary           = $this->build_deterministic_capsule_summary( $checkpoint, $messages );
		$completed         = array();
		$remaining         = array();
		$scope             = array();
		$receipts          = array();
		$ai_decisions      = array();
		$created_post_ids  = array();
		$preserved_details = $this->collect_preserved_detail_lines( $checkpoint, $messages );
		$compression_model = '';
		$existing_capsule  = $checkpoint ? $checkpoint->get_context_capsule() : array();
		$compaction_state  = is_array( $existing_capsule['compaction'] ?? null )
			? (array) $existing_capsule['compaction']
			: array();

		if ( $checkpoint ) {
			$exec = $checkpoint->get_execution();

			// Extract write operations as decisions.
			$writes = (array) ( $exec['writes'] ?? array() );
			foreach ( array_slice( $writes, -5 ) as $w ) {
				if ( ! is_array( $w ) ) {
					continue;
				}

				$tool    = (string) ( $w['tool'] ?? '' );
				$args    = (array) ( $w['arguments'] ?? array() );
				$post_id = (int) ( $args['post_id'] ?? 0 );

				if ( $tool && $post_id ) {
					$ai_decisions[] = $tool . ' on post_id=' . $post_id;
				}
				if ( in_array( $tool, array( 'create_post', 'create_content' ), true ) && $post_id ) {
					$created_post_ids[] = $post_id;
				}
			}

			// Durable ledger receipts survive checkpoint serialization even when raw writes do not.
			foreach ( array_slice( (array) ( $exec['receipts'] ?? array() ), -5 ) as $receipt ) {
				if ( ! is_array( $receipt ) ) {
					continue;
				}

				$tool    = (string) ( $receipt['tool'] ?? '' );
				$post_id = (int) ( $receipt['post_id'] ?? 0 );

				if ( $tool && $post_id ) {
					$ai_decisions[] = $tool . ' on post_id=' . $post_id;
				}
				if ( in_array( $tool, array( 'create_post', 'create_content', 'elementor_create_page' ), true ) && $post_id ) {
					$created_post_ids[] = $post_id;
				}
			}

			// Selected target is a key decision.
			$target = $checkpoint->get_selected_target();
			$target_post_id = (int) ( $target['post_id'] ?? $target['id'] ?? 0 );
			if ( ! empty( $target['title'] ) ) {
				$ai_decisions[] = 'Selected target: "' . sanitize_text_field( $target['title'] ) . '"';
			}
			if ( $target_post_id > 0 ) {
				$ai_decisions[] = 'Working on post_id=' . $target_post_id;
			}

			$progress  = PressArk_Execution_Ledger::progress_snapshot( $checkpoint->get_execution() );
			$completed = array_slice( (array) ( $progress['completed_labels'] ?? array() ), 0, 6 );
			$remaining = array_slice( (array) ( $progress['remaining_labels'] ?? array() ), 0, 6 );
			$receipts  = $this->collect_recent_receipts( $checkpoint );

			$target = $checkpoint->get_selected_target();
			if ( ! empty( $target['title'] ) ) {
				$scope[] = sanitize_text_field( (string) $target['title'] );
			}
			if ( ! empty( $target['type'] ) ) {
				$scope[] = sanitize_text_field( (string) $target['type'] );
			}
			if ( '' !== $checkpoint->get_workflow_stage() ) {
				$scope[] = 'stage:' . sanitize_key( $checkpoint->get_workflow_stage() );
			}
		}

		// v5.0.6: AI compaction cooldown — skip costly AI summarization if a
		// recent capsule exists (< 45s). Prevents repeated AI calls during
		// in-loop compaction burning ICUs without progress. Force-AI calls
		// (from the pause-and-continue path) bypass the cooldown.
		$skip_ai_cooldown = false;
		if ( ! $force_ai && $checkpoint ) {
			$existing_updated_at = strtotime( (string) ( $existing_capsule['updated_at'] ?? '' ) );
			if ( $existing_updated_at && ( time() - $existing_updated_at ) < 45 ) {
				$skip_ai_cooldown = true;
			}
		}

		if ( ! $skip_ai_cooldown && ( $force_ai || $this->has_ai_compaction_headroom() ) ) {
			$ai_capsule = $this->compact_with_ai( $messages, $checkpoint );
			if ( ! empty( $ai_capsule['summary'] ) ) {
				$summary = sanitize_text_field( (string) $ai_capsule['summary'] );
			}
			$completed         = $this->merge_compaction_lines( $completed, (array) ( $ai_capsule['completed'] ?? array() ), 6 );
			$remaining         = $this->merge_compaction_lines( $remaining, (array) ( $ai_capsule['remaining'] ?? array() ), 6 );
			$preserved_details = $this->merge_compaction_lines( $preserved_details, (array) ( $ai_capsule['preserved_details'] ?? array() ), 8 );
			$compression_model = sanitize_text_field( (string) ( $ai_capsule['compression_model'] ?? '' ) );
		}

		$capsule = array(
			'task'                => sanitize_text_field( $task ),
			'active_request'      => sanitize_text_field( $task ),
			'historical_requests' => $this->merge_compaction_lines( array(), $historical_tasks, 3 ),
			'target'              => sanitize_text_field( $target_snapshot ),
			'summary'             => sanitize_text_field( $summary ),
			'completed'           => $this->merge_compaction_lines( array(), $completed, 6 ),
			'remaining'           => $this->merge_compaction_lines( array(), $remaining, 6 ),
			'preserved_details'   => $this->merge_compaction_lines( array(), $preserved_details, 8 ),
			'recent_receipts'     => array_values( array_filter( array_map( 'sanitize_text_field', $receipts ) ) ),
			'loaded_groups'       => $this->loaded_groups,
			'ai_decisions'        => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ai_decisions ) ) ) ),
			'created_post_ids'    => array_values( array_unique( array_filter( $created_post_ids ) ) ),
			'scope'               => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $scope ) ) ) ),
			'compression_model'   => $compression_model,
			'compaction'          => $compaction_state,
			'updated_at'          => gmdate( 'c' ),
		);

		return array_filter(
			$capsule,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	private function build_deterministic_capsule_summary( ?PressArk_Checkpoint $checkpoint, array $messages = array() ): string {
		$goal         = $this->resolve_capsule_task( $checkpoint, $messages );
		$target_label = '';
		$stage        = '';
		$completed    = array();
		$remaining    = array();

		if ( $checkpoint ) {
			$checkpoint_data = $checkpoint->to_array();
			$stage           = sanitize_key( $checkpoint->get_workflow_stage() );

			$target = $checkpoint->get_selected_target();
			if ( ! empty( $target['title'] ) || ! empty( $target['id'] ) ) {
				$target_label = trim(
					sanitize_text_field( (string) ( $target['title'] ?? '' ) )
					. ( ! empty( $target['id'] ) ? ' #' . (int) $target['id'] : '' )
				);
			}

			$progress   = PressArk_Execution_Ledger::progress_snapshot( $checkpoint->get_execution() );
			$completed  = array_slice( (array) ( $progress['completed_labels'] ?? array() ), 0, 2 );
			$remaining  = array_slice( (array) ( $progress['remaining_labels'] ?? array() ), 0, 2 );
		}

		if ( '' === $goal ) {
			foreach ( $messages as $message ) {
				if ( 'user' === ( $message['role'] ?? '' ) && ! empty( $message['content'] ) && is_string( $message['content'] ) ) {
					$goal = $this->normalize_request_message( (string) $message['content'] );
					if ( '' !== $goal ) {
						$goal = mb_substr( $goal, 0, 160 );
						break;
					}
				}
			}
		}

		$parts = array();
		if ( '' !== $goal ) {
			$parts[] = $goal;
		}
		if ( '' !== $target_label ) {
			$parts[] = 'target ' . $target_label;
		}
		if ( ! empty( $completed ) ) {
			$parts[] = 'completed ' . implode( ' + ', array_map( 'sanitize_text_field', $completed ) );
		}
		if ( ! empty( $remaining ) ) {
			$parts[] = 'remaining ' . implode( ' + ', array_map( 'sanitize_text_field', $remaining ) );
		}
		if ( '' !== $stage ) {
			$parts[] = 'stage ' . $stage;
		}

		return ! empty( $parts )
			? sanitize_text_field( mb_substr( implode( '; ', $parts ), 0, 220 ) )
			: 'Resumable state captured for continuation.';
	}

	private function collect_recent_receipts( PressArk_Checkpoint $checkpoint ): array {
		$execution = $checkpoint->get_execution();
		$receipts  = array_slice( (array) ( $execution['receipts'] ?? array() ), -4 );
		$result    = array();

		foreach ( $receipts as $receipt ) {
			if ( ! is_array( $receipt ) ) {
				continue;
			}

			$summary = sanitize_text_field( (string) ( $receipt['summary'] ?? '' ) );
			if ( '' !== $summary ) {
				$result[] = $summary;
			}
		}

		if ( empty( $result ) ) {
			$checkpoint_data = $checkpoint->to_array();
			foreach ( array_slice( (array) ( $checkpoint_data['outcomes'] ?? array() ), -4 ) as $outcome ) {
				if ( ! is_array( $outcome ) ) {
					continue;
				}

				$label = trim(
					sanitize_text_field( (string) ( $outcome['action'] ?? '' ) )
					. ': '
					. sanitize_text_field( (string) ( $outcome['result'] ?? '' ) )
				);
				if ( ': ' !== $label && '' !== trim( $label, ': ' ) ) {
					$result[] = $label;
				}
			}
		}

		return array_slice( array_values( array_unique( $result ) ), -4 );
	}

	private function build_context_capsule_text( array $capsule ): string {
		$lines               = array();
		$task                = sanitize_text_field( (string) ( $capsule['active_request'] ?? $capsule['task'] ?? '' ) );
		$target              = sanitize_text_field( (string) ( $capsule['target'] ?? '' ) );
		$summary             = sanitize_text_field( (string) ( $capsule['summary'] ?? '' ) );
		$historical_requests = array_values( array_filter( array_map(
			'sanitize_text_field',
			(array) ( $capsule['historical_requests'] ?? array() )
		) ) );

		if ( '' !== $task ) {
			$lines[] = 'Active request at checkpoint: ' . $task;
		}
		if ( ! empty( $historical_requests ) ) {
			$lines[] = 'Past requests already in context: ' . implode( '; ', array_slice( $historical_requests, 0, 3 ) );
		}
		if ( '' !== $target ) {
			$lines[] = 'Target: ' . $target;
		}
		if ( '' !== $summary ) {
			$lines[] = 'Summary: ' . $summary;
		}
		if ( ! empty( $capsule['preserved_details'] ) ) {
			$lines[] = 'Keep exact: ' . implode( '; ', array_slice( (array) $capsule['preserved_details'], 0, 4 ) );
		}
		if ( ! empty( $capsule['completed'] ) ) {
			$lines[] = 'Completed: ' . implode( '; ', array_slice( (array) $capsule['completed'], 0, 4 ) );
		}
		if ( ! empty( $capsule['remaining'] ) ) {
			$lines[] = 'Remaining: ' . implode( '; ', array_slice( (array) $capsule['remaining'], 0, 4 ) );
		}
		if ( ! empty( $capsule['recent_receipts'] ) ) {
			$lines[] = 'Recent receipts: ' . implode( '; ', array_slice( (array) $capsule['recent_receipts'], 0, 4 ) );
		}
		if ( ! empty( $capsule['loaded_groups'] ) ) {
			$lines[] = 'Loaded tool groups (DO NOT re-load): ' . implode( ', ', array_slice( (array) $capsule['loaded_groups'], 0, 8 ) );
		}
		if ( ! empty( $capsule['ai_decisions'] ) ) {
			$lines[] = 'AI decisions made (DO NOT redo): ' . implode( '; ', array_slice( (array) $capsule['ai_decisions'], 0, 5 ) );
		}
		if ( ! empty( $capsule['created_post_ids'] ) ) {
			$lines[] = 'Posts created (DO NOT recreate): ' . implode( ', ', array_slice( (array) $capsule['created_post_ids'], 0, 5 ) );
		}

		return implode( "\n", $lines );
	}

	private function resolve_capsule_task( ?PressArk_Checkpoint $checkpoint, array $messages ): string {
		$recent_requests = $this->extract_recent_user_requests( $messages, 1 );
		if ( ! empty( $recent_requests ) ) {
			return mb_substr( $recent_requests[0], 0, 220 );
		}

		if ( $checkpoint ) {
			$checkpoint_data = $checkpoint->to_array();
			$goal            = sanitize_text_field( (string) ( $checkpoint_data['goal'] ?? '' ) );
			if ( '' !== $goal ) {
				return mb_substr( $goal, 0, 220 );
			}
		}

		return '';
	}

	private function extract_historical_requests( array $messages, string $active_request = '' ): array {
		$requests = $this->extract_recent_user_requests( $messages, 4 );
		if ( empty( $requests ) ) {
			return array();
		}

		$historical = $requests;
		if ( '' !== $active_request && ! empty( $historical ) && $historical[0] === $active_request ) {
			array_shift( $historical );
		}

		return array_slice( $historical, 0, 3 );
	}

	private function format_capsule_target( ?PressArk_Checkpoint $checkpoint ): string {
		if ( ! $checkpoint ) {
			return '';
		}

		$target = $checkpoint->get_selected_target();
		if ( empty( $target ) ) {
			return '';
		}

		$label = sanitize_text_field( (string) ( $target['title'] ?? '' ) );
		if ( ! empty( $target['id'] ) ) {
			$label .= ' #' . (int) $target['id'];
		}
		if ( ! empty( $target['type'] ) ) {
			$label .= ' (' . sanitize_text_field( (string) $target['type'] ) . ')';
		}

		return trim( $label );
	}

	private function collect_preserved_detail_lines( ?PressArk_Checkpoint $checkpoint, array $messages ): array {
		$lines            = array();
		$task             = $this->resolve_capsule_task( $checkpoint, $messages );
		$historical_tasks = $this->extract_historical_requests( $messages, $task );
		if ( '' !== $task ) {
			$lines[] = 'active_request=' . $task;
		}
		foreach ( $historical_tasks as $index => $historical_task ) {
			$lines[] = 'past_request_' . ( $index + 1 ) . '=' . $historical_task;
		}

		$target = $this->format_capsule_target( $checkpoint );
		if ( '' !== $target ) {
			$lines[] = 'target=' . $target;
		}

		if ( $checkpoint ) {
			$data = $checkpoint->to_array();
			foreach ( array_slice( (array) ( $data['entities'] ?? array() ), 0, 4 ) as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue;
				}
				$entity_label = sanitize_text_field( (string) ( $entity['title'] ?? '' ) );
				$entity_id    = (int) ( $entity['id'] ?? 0 );
				$entity_type  = sanitize_text_field( (string) ( $entity['type'] ?? '' ) );
				if ( '' !== $entity_label && $entity_id > 0 ) {
					$lines[] = sprintf( 'entity=%s #%d (%s)', $entity_label, $entity_id, $entity_type ?: 'item' );
				}
			}

			foreach ( array_slice( (array) ( $data['pending'] ?? array() ), 0, 3 ) as $pending ) {
				if ( ! is_array( $pending ) ) {
					continue;
				}
				$action = sanitize_text_field( (string) ( $pending['action'] ?? '' ) );
				$target_label = sanitize_text_field( (string) ( $pending['target'] ?? '' ) );
				$detail = sanitize_text_field( (string) ( $pending['detail'] ?? '' ) );
				if ( '' !== $action || '' !== $target_label || '' !== $detail ) {
					$lines[] = trim( 'pending=' . $action . ' -> ' . $target_label . ( '' !== $detail ? ' :: ' . $detail : '' ) );
				}
			}

			foreach ( array_slice( (array) ( $data['facts'] ?? array() ), 0, 4 ) as $fact ) {
				if ( ! is_array( $fact ) ) {
					continue;
				}
				$key   = sanitize_text_field( (string) ( $fact['key'] ?? '' ) );
				$value = sanitize_text_field( (string) ( $fact['value'] ?? '' ) );
				if ( '' !== $key && '' !== $value ) {
					$lines[] = $key . '=' . $value;
				}
			}
		}

		return array_slice(
			array_values(
				array_unique(
					array_filter(
						$lines
					)
				)
			),
			0,
			8
		);
	}

	private function extract_recent_user_requests( array $messages, int $limit = 3 ): array {
		$requests = array();

		foreach ( array_reverse( $messages ) as $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) || ! is_string( $message['content'] ?? null ) ) {
				continue;
			}

			if ( $this->is_compacted_context_message( (string) $message['content'] ) ) {
				continue;
			}

			$content = $this->normalize_request_message( (string) $message['content'] );
			if ( '' === $content ) {
				continue;
			}

			$requests[] = mb_substr( $content, 0, 220 );
			if ( count( $requests ) >= $limit ) {
				break;
			}
		}

		return array_values( array_unique( $requests ) );
	}

	private function normalize_request_message( string $message ): string {
		$normalized = trim( preg_replace( '/^\[(?:Continue|Confirmed)\]\s*/i', '', $message ) );
		$normalized = preg_replace( '/Please continue with the remaining steps from my original request\.?$/i', '', $normalized );
		return sanitize_text_field( trim( (string) $normalized ) );
	}

	private function is_compacted_context_message( string $message ): bool {
		$message = ltrim( $message );

		return 1 === preg_match(
			'/^\[(?:Conversation summary|Historical context from earlier rounds only|Context note:|Conversation State)/i',
			$message
		);
	}

	private function merge_compaction_lines( array $base, array $extra, int $limit ): array {
		return array_slice(
			array_values(
				array_unique(
					array_filter(
						array_map(
							'sanitize_text_field',
							array_merge( $base, $extra )
						)
					)
				)
			),
			0,
			$limit
		);
	}

	private function parse_compacted_capsule_response( string $text ): array {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/is', $text, $match ) ) {
			$text = trim( $match[1] );
		}

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$decoded = json_decode( substr( $text, $start, ( $end - $start ) + 1 ), true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return array(
				'summary' => sanitize_text_field( mb_substr( $text, 0, 220 ) ),
			);
		}

		return array_filter(
			array(
				'summary'           => sanitize_text_field( (string) ( $decoded['summary'] ?? '' ) ),
				'completed'         => $this->merge_compaction_lines( array(), (array) ( $decoded['completed'] ?? array() ), 6 ),
				'remaining'         => $this->merge_compaction_lines( array(), (array) ( $decoded['remaining'] ?? array() ), 6 ),
				'preserved_details' => $this->merge_compaction_lines( array(), (array) ( $decoded['preserved_details'] ?? array() ), 8 ),
			),
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	/**
	 * Emit a step for the UI activity strip.
	 */
	private function emit_step( string $status, string $tool, array $args, $result = null ): void {
		$labels = array(
			'read_content'            => 'Reading post content',
			'search_content'          => 'Searching content',
			'list_posts'              => 'Fetching posts',
			'analyze_seo'             => 'Running SEO analysis',
			'scan_security'           => 'Scanning for security issues',
			'get_site_settings'       => 'Reading site settings',
			'get_menus'               => 'Reading menus',
			'list_media'              => 'Browsing media library',
			'list_comments'           => 'Fetching comments',
			'list_users'              => 'Fetching users',
			'list_taxonomies'         => 'Fetching categories/tags',
			'site_health'             => 'Running site health check',
			'list_plugins'            => 'Listing plugins',
			'list_themes'             => 'Listing themes',
			'get_theme_settings'      => 'Reading theme settings',
			'list_orders'             => 'Fetching orders',
			'list_customers'          => 'Fetching customers',
			'get_order'               => 'Reading order details',
			'inventory_report'        => 'Generating inventory report',
			'reply_review'            => 'Preparing review reply',
			'bulk_reply_reviews'      => 'Preparing bulk review replies',
			'list_variations'         => 'Fetching product variations',
			'search_knowledge'        => 'Searching knowledge base',
			'get_site_overview'       => 'Reading site overview',
			'get_site_map'            => 'Reading site map',
			'get_brand_profile'       => 'Reading brand profile',
			'view_site_profile'       => 'Reading site profile',
			'analyze_store'           => 'Analyzing store',
			'elementor_read_page'     => 'Reading page builder data',
			'elementor_find_widgets'  => 'Searching page builder widgets',
			'edit_content'            => 'Preparing content changes',
			'create_post'             => 'Preparing new post',
			'update_meta'             => 'Preparing meta changes',
			'fix_seo'                 => 'Preparing SEO fixes',
			'generate_content'        => 'Generating content',
			'update_site_settings'    => 'Preparing settings changes',
			'update_theme_setting'    => 'Preparing theme changes',
			'discover_tools'          => 'Searching for tools',
			'load_tools'              => 'Loading tools',
			'_context_compaction'     => 'Compressing context…',
		);

		$label = $labels[ $tool ] ?? ucwords( str_replace( '_', ' ', $tool ) );

		// Add context clue if post title is in args.
		if ( ! empty( $args['post_id'] ) ) {
			$post = get_post( $args['post_id'] );
			if ( $post ) {
				$label .= ' — ' . $post->post_title;
			}
		}

		$this->steps[] = array(
			'status' => $status,  // reading | done | preparing_preview | needs_confirm
			'label'  => $label,
			'tool'   => $tool,
			'time'   => microtime( true ),
		);
	}

	/**
	 * Get a human-readable step label for a tool call.
	 *
	 * Extracted from emit_step() so streaming can also use it.
	 *
	 * @since 4.4.0
	 */
	private function get_step_label( string $tool, array $args ): string {
		static $labels = array(
			'read_content'            => 'Reading post content',
			'search_content'          => 'Searching content',
			'list_posts'              => 'Fetching posts',
			'analyze_seo'             => 'Running SEO analysis',
			'scan_security'           => 'Scanning for security issues',
			'get_site_settings'       => 'Reading site settings',
			'get_menus'               => 'Reading menus',
			'list_media'              => 'Browsing media library',
			'list_comments'           => 'Fetching comments',
			'list_users'              => 'Fetching users',
			'list_taxonomies'         => 'Fetching categories/tags',
			'site_health'             => 'Running site health check',
			'list_plugins'            => 'Listing plugins',
			'list_themes'             => 'Listing themes',
			'get_theme_settings'      => 'Reading theme settings',
			'list_orders'             => 'Fetching orders',
			'list_customers'          => 'Fetching customers',
			'get_order'               => 'Reading order details',
			'inventory_report'        => 'Generating inventory report',
			'reply_review'            => 'Preparing review reply',
			'bulk_reply_reviews'      => 'Preparing bulk review replies',
			'list_variations'         => 'Fetching product variations',
			'search_knowledge'        => 'Searching knowledge base',
			'get_site_overview'       => 'Reading site overview',
			'get_site_map'            => 'Reading site map',
			'get_brand_profile'       => 'Reading brand profile',
			'view_site_profile'       => 'Reading site profile',
			'analyze_store'           => 'Analyzing store',
			'elementor_read_page'     => 'Reading page builder data',
			'elementor_find_widgets'  => 'Searching page builder widgets',
			'edit_content'            => 'Preparing content changes',
			'create_post'             => 'Preparing new post',
			'update_meta'             => 'Preparing meta changes',
			'fix_seo'                 => 'Preparing SEO fixes',
			'generate_content'        => 'Generating content',
			'update_site_settings'    => 'Preparing settings changes',
			'update_theme_setting'    => 'Preparing theme changes',
			'discover_tools'          => 'Searching for tools',
			'load_tools'              => 'Loading tools',
			'_context_compaction'     => 'Compressing context…',
		);

		$label = $labels[ $tool ] ?? ucwords( str_replace( '_', ' ', $tool ) );

		if ( ! empty( $args['post_id'] ) ) {
			$post = get_post( $args['post_id'] );
			if ( $post ) {
				$label .= ' — ' . $post->post_title;
			}
		}

		return $label;
	}

	/**
	 * Build a compact summary of a tool result for streaming tool_result events.
	 *
	 * @since 4.4.0
	 */
	private function summarize_result( $result ): string {
		if ( is_string( $result ) ) {
			return mb_substr( $result, 0, 100 );
		}
		if ( is_array( $result ) ) {
			if ( isset( $result['error'] ) ) {
				return 'Error: ' . mb_substr( (string) $result['error'], 0, 80 );
			}
			$count = count( $result );
			return $count . ' item' . ( 1 === $count ? '' : 's' ) . ' returned';
		}
		return 'Done';
	}

	/**
	 * Share tool classification rules across agentic and legacy chat flows.
	 * Delegates to PressArk_Tool_Catalog for centralized capability lookup.
	 */
	public static function classify_tool( string $tool_name, array $args = array() ): string {
		return PressArk_Tool_Catalog::instance()->classify( $tool_name, $args );
	}

	/**
	 * Get the total tokens used by this agent session.
	 */
	public function get_tokens_used(): int {
		return $this->tokens_used;
	}

	// ── Task Planning (v3.5.0 classify, v4.3.1 AI classify, v4.3.2 planner) ─────────

	/**
	 * AI-powered planner.
	 *
	 * v4.3.2: Upgrades the v4.3.1 classifier into a planner. Same cheap model,
	 * same cost tier, but includes the capability map so the model can see all
	 * available tool domains and return a structured execution plan.
	 *
	 * Returns {task_type, steps[], groups[]} — steps are imperative phrases
	 * that guide the agent through multi-step requests.
	 *
	 * - Bundled users: routes via 'classification' phase → cheapest economy model.
	 * - BYOK users: uses their own model/key automatically (send_message_raw handles it).
	 * - Continuations: skips AI call, uses local regex (classifies from original message).
	 * - Short messages (<15 chars): skips AI call, returns chat + no groups + no steps.
	 * - Failure: falls back to local regex classify_task + detect_preload_groups.
	 *
	 * @param string $message      User's message.
	 * @param array  $conversation Conversation history.
	 * @return array{task_type: string, steps: string[], groups: string[]}
	 */
	private function plan_with_ai( string $message, array $conversation ): array {
		// Continuations: plan from original message locally (no AI needed).
		if ( self::is_continuation_message( $message ) ) {
			return $this->plan_local_fallback( $message, $conversation );
		}

		// Greetings / acknowledgements should never pay for a planner round.
		if ( self::is_lightweight_chat_request( $message, $conversation ) ) {
			return array(
				'task_type' => 'chat',
				'steps'     => array(),
				'groups'    => array(),
			);
		}

		// Trivial messages: skip the AI call entirely.
		$trimmed = trim( $message );
		if ( mb_strlen( $trimmed ) < 15 ) {
			return array(
				'task_type' => self::classify_task( $message, $conversation ),
				'steps'     => array(),
				'groups'    => array(),
			);
		}

		$local_plan = $this->plan_local_fallback( $message, $conversation );
		if ( $this->should_skip_ai_planner( $trimmed, $local_plan ) ) {
			return $local_plan;
		}

		// v4.3.2: Get capability map so the planner can see all available tool domains.
		$capability_map = PressArk_Tool_Catalog::instance()->get_capability_map( array() );

		// Site capability flags — tells planner which plugins are active.
		// When is_woocommerce=true, "products" = WC products, not posts.
		$plugin_flags = PressArk_Context::get_plugin_flags();
		$flag_parts = array();
		foreach ( $plugin_flags as $key => $val ) {
			$flag_parts[] = $key . '=' . ( $val ? 'true' : 'false' );
		}
		$flags_line = 'Site: ' . implode( ', ', $flag_parts );

		$prompt = "Plan this WordPress task. Return JSON only:\n"
			. "{\"task\":\"<type>\",\"steps\":[\"<verb> <object>\"],\"groups\":[\"<group>\"]}\n\n"
			. "task: chat, analyze, generate, edit, code, diagnose\n"
			. "steps: 1-4 short imperative phrases (what to do in order)\n"
			. "groups: tool groups needed (from list below), max 3, [] for chat\n\n"
			. "{$flags_line}\n\n"
			. "{$capability_map}\n\n"
			. "Message: {$trimmed}";

		try {
			$result = $this->ai->send_message_raw(
				array( array( 'role' => 'user', 'content' => $prompt ) ),
				array(),
				'Return only valid JSON. No explanation.',
				false,
				array( 'phase' => 'classification' )
			);

			$raw      = $result['raw'] ?? array();
			$provider = $result['provider'] ?? '';

			if ( ! empty( $raw['error'] ) ) {
				return $this->plan_local_fallback( $message, $conversation );
			}

			// Track planner cost in the session totals.
			$this->tokens_used        += $this->ai->extract_usage( $raw, $provider );
			$this->output_tokens_used += $this->ai->extract_output_usage( $raw, $provider );

			// Parse JSON — strip markdown fences if model wraps them.
			$text = trim( $this->ai->extract_text( $raw, $provider ) );
			$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
			$text = preg_replace( '/\s*```$/', '', $text );

			$parsed = json_decode( $text, true );
			if ( ! is_array( $parsed ) ) {
				return $this->plan_local_fallback( $message, $conversation );
			}

			// Validate task_type.
			$valid_tasks = array( 'chat', 'analyze', 'generate', 'edit', 'code', 'diagnose' );
			$task_type = in_array( $parsed['task'] ?? '', $valid_tasks, true )
				? $parsed['task']
				: self::classify_task( $message, $conversation );

			// Validate and cap groups at 3.
			$groups = array();
			foreach ( (array) ( $parsed['groups'] ?? array() ) as $g ) {
				if ( is_string( $g ) && PressArk_Operation_Registry::is_valid_group( $g ) ) {
					$groups[] = $g;
				}
			}
			$groups = array_slice( array_unique( $groups ), 0, 3 );

			// Validate and cap steps at 4.
			$steps = array();
			foreach ( (array) ( $parsed['steps'] ?? array() ) as $s ) {
				if ( is_string( $s ) && ! empty( trim( $s ) ) ) {
					$steps[] = mb_substr( trim( $s ), 0, 120 );
				}
			}
			$steps = array_slice( $steps, 0, 4 );

			return array(
				'task_type' => $task_type,
				'steps'     => $steps,
				'groups'    => $groups,
			);
		} catch ( \Throwable $e ) {
			return $this->plan_local_fallback( $message, $conversation );
		}
	}

	/**
	 * Local regex fallback for planning.
	 *
	 * Used when AI planner call fails or is skipped (continuations).
	 * Combines classify_task() + detect_preload_groups() results.
	 *
	 * @param string $message      User's message.
	 * @param array  $conversation Conversation history.
	 * @return array{task_type: string, steps: string[], groups: string[]}
	 */
	private function plan_local_fallback( string $message, array $conversation ): array {
		$task   = self::classify_task( $message, $conversation );
		$groups = $this->refine_preload_groups(
			$message,
			$task,
			self::detect_preload_groups( $message, $task )
		);

		return array(
			'task_type' => $task,
			'steps'     => $this->infer_local_plan_steps( $message, $task, $groups ),
			'groups'    => $groups,
		);
	}

	/**
	 * Classify a user message into a task category.
	 *
	 * v3.7.3: Accepts optional conversation array for continuation detection.
	 * When the message is a post-approval continuation marker ([Confirmed] or
	 * [Continue]), the classifier walks backward through conversation history
	 * to find the original user request and classifies based on THAT. This
	 * ensures the continuation run selects the correct model tier and loads
	 * the right domain skills (e.g. generation skills for a "create post +
	 * optimize SEO" flow that paused after the create step).
	 *
	 * @param string $message      The user's message.
	 * @param array  $conversation Conversation history (optional).
	 * @return string Task category: diagnose|code|analyze|generate|edit|chat|classify.
	 */
	/**
	 * v4.3.0: Detect tool groups to preload based on message + task type.
	 *
	 * Uses keyword patterns to identify high-confidence domain intent.
	 * Only preloads when confidence is strong — avoids loading unnecessary
	 * tools that bloat the prompt. Saves a full AI round (~7K tokens)
	 * when the user's intent clearly maps to a specific domain.
	 *
	 * @param string $message   User's message.
	 * @param string $task_type Classified task type.
	 * @return string[] Group names to preload.
	 */
	private static function detect_preload_groups( string $message, string $task_type ): array {
		$msg    = strtolower( $message );
		$groups = array();
		$mentions_content_surface = self::mentions_content_surface( $msg );

		if ( self::is_explicit_content_read_request( $msg ) ) {
			$groups[] = 'blocks';
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				$groups[] = 'elementor';
			}
		}

		// Content/generation intent.
		if ( 'generate' === $task_type ) {
			$groups[] = 'generation';
		}
		if ( $mentions_content_surface && in_array( $task_type, array( 'edit', 'generate' ), true ) ) {
			$groups[] = 'content';
		}

		// Security intent — high confidence keywords.
		if ( preg_match( '/\b(secur|vulnerab|hack|malware|scan.+site|security.+scan|xml.?rpc|brute.?force)\b/i', $msg ) ) {
			$groups[] = 'security';
		}

		// SEO intent.
		if ( preg_match( '/\b(seo|meta.?title|meta.?desc|sitemap|search.?engine|ranking|crawl|robots\.txt|canonical)\b/i', $msg )
			&& ! ( 'generate' === $task_type && $mentions_content_surface )
		) {
			$groups[] = 'seo';
		}

		// WooCommerce intent.
		if ( preg_match( '/\b(product|order|cart|checkout|shop|store|woo|coupon|shipping|inventory|variation|refund|revenue|sales)\b/i', $msg )
			&& class_exists( 'WooCommerce' )
		) {
			$groups[] = 'woocommerce';
		}

		// Elementor intent.
		if ( preg_match( '/\b(elementor|widget|popup|dynamic.?tag|page.?builder)\b/i', $msg )
			&& defined( 'ELEMENTOR_VERSION' )
		) {
			$groups[] = 'elementor';
		}

		// Diagnostics intent.
		if ( 'diagnose' === $task_type ) {
			$groups[] = 'health';
		}

		// Plugin/theme management.
		if ( preg_match( '/\b(plugin|deactivat|activat|install.+plugin)\b/i', $msg ) ) {
			$groups[] = 'plugins';
		}
		if ( preg_match( '/\b(theme|switch.+theme|customizer)\b/i', $msg ) ) {
			$groups[] = 'themes';
		}

		// v4.3.2: Raised cap from 2 to 3 to match planner output.
		return array_slice( array_unique( $groups ), 0, 3 );
	}

	private function should_skip_ai_planner( string $message, array $local_plan ): bool {
		$task_type = (string) ( $local_plan['task_type'] ?? '' );
		$groups    = array_values( array_filter( (array) ( $local_plan['groups'] ?? array() ), 'is_string' ) );

		if ( self::is_explicit_content_read_request( $message ) ) {
			return true;
		}

		if ( 'generate' === $task_type ) {
			if ( count( $groups ) < 2 ) {
				return false;
			}

			if ( mb_strlen( $message ) > 220 ) {
				return false;
			}

			return true;
		}

		return false;
	}

	private function refine_preload_groups( string $message, string $task_type, array $groups ): array {
		$msg                      = strtolower( $message );
		$mentions_content_surface = self::mentions_content_surface( $msg );
		$normalized               = array_values( array_filter( array_map(
			static function ( $group ) {
				return is_string( $group ) && PressArk_Operation_Registry::is_valid_group( $group )
					? $group
					: '';
			},
			$groups
		) ) );

		if ( self::is_explicit_content_read_request( $msg ) ) {
			$normalized[] = 'blocks';
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				$normalized[] = 'elementor';
			}
		}

		if ( 'generate' === $task_type && $mentions_content_surface ) {
			$normalized = array_values( array_diff( $normalized, array( 'seo' ) ) );
		}

		if ( ! self::explicitly_mentions_custom_fields( $msg ) ) {
			$normalized = array_values( array_diff( $normalized, array( 'custom_fields' ) ) );
		}

		$mentions_health_diagnostics = (bool) preg_match(
			'/\b(site.?health|page.?speed|crawl|email.?deliver|uptime|slow|broken.?link|performance|cache|query|hook|diagnostic)\b/i',
			$msg
		);
		if ( in_array( 'health', $normalized, true )
			&& ! $mentions_health_diagnostics
			&& (
				in_array( 'security', $normalized, true )
				|| in_array( 'seo', $normalized, true )
				|| in_array( 'woocommerce', $normalized, true )
			)
		) {
			$normalized = array_values( array_diff( $normalized, array( 'health' ) ) );
		}

		return array_slice( array_values( array_unique( $normalized ) ), 0, 3 );
	}

	private function infer_local_plan_steps( string $message, string $task_type, array $groups ): array {
		if ( 'generate' !== $task_type ) {
			return array();
		}

		$has_sequence = (bool) preg_match(
			'/\b(?:then|after\s+that|once\s+(?:done|that\'s\s+done|finished)|and\s+then|afterwards?|next|finally)\b/i',
			$message
		);
		if ( ! $has_sequence ) {
			return array();
		}

		$steps = array();

		if ( in_array( 'content', $groups, true ) || self::mentions_content_surface( strtolower( $message ) ) ) {
			$steps[] = 'create the content';
		}

		if ( preg_match( '/\bseo\b|meta.?title|meta.?desc|search.?engine|ranking|canonical|robots\.txt/i', $message ) ) {
			$steps[] = 'optimize the SEO';
		}

		return array_slice( array_values( array_unique( $steps ) ), 0, 4 );
	}

	private static function explicitly_mentions_custom_fields( string $message ): bool {
		return (bool) preg_match(
			'/\b(custom\s+field|custom\s+fields|acf|advanced\s+custom\s+fields|meta\s+field|meta\s+fields|post\s+meta|field\s+key|meta\s+key)\b/i',
			$message
		);
	}

	private static function mentions_content_surface( string $message ): bool {
		return (bool) preg_match(
			'/\b(post|page|article|blog(?:\s+post)?|homepage|home\s+page|landing\s+page|about\s+page|contact\s+page|services\s+page|sales\s+page|content|copy|headline|excerpt|slug)\b/i',
			$message
		);
	}

	private static function is_explicit_content_read_request( string $message ): bool {
		return (bool) preg_match(
			'/\b(?:read|inspect|review|analyz(?:e|ing)?|summari(?:ze|zing)|check|show|look\s+at|what(?:\'s| is))\b.*\b(?:page|post|article|content|copy|homepage|home\s+page|landing\s+page)\b/i',
			$message
		);
	}

	public static function classify_task( string $message, array $conversation = array() ): string {
		// v3.7.3: Continuation detection — classify based on the original
		// request when the current message is a post-approval continuation.
		if ( self::is_continuation_message( $message ) && ! empty( $conversation ) ) {
			// Walk backward to find the most recent non-continuation user message.
			for ( $i = count( $conversation ) - 1; $i >= 0; $i-- ) {
				$turn = $conversation[ $i ] ?? array();
				if ( 'user' === ( $turn['role'] ?? '' )
					&& ! self::is_continuation_message( $turn['content'] ?? '' ) ) {
					// Recurse without conversation to get a clean classification.
					return self::classify_task( $turn['content'] ?? '' );
				}
			}
			// No original user message found — fall through to default classification.
		}

		$msg = strtolower( $message );

		// Diagnose: site health, speed, email delivery, crawl.
		if ( preg_match( '/\b(diagnos|site.?health|page.?speed|crawl|email.?deliver|uptime|slow|broken.?link)/i', $msg ) ) {
			return 'diagnose';
		}

		// Code: shortcodes, Elementor, CSS, theme editing, PHP.
		if ( preg_match( '/\b(code|shortcode|elementor|css|php|html|javascript|widget|template|snippet|hook|filter)/i', $msg ) ) {
			return 'code';
		}

		// Analyze: scan, audit, review, check, report, compare.
		if ( preg_match( '/\b(scan|audit|review|check|report|compar|analyz|assess|inspect|summar|overview|status)/i', $msg ) ) {
			return 'analyze';
		}

		if ( self::is_explicit_content_read_request( $msg ) ) {
			return 'analyze';
		}

		// Generate: write new content, create, draft, compose.
		if ( preg_match( '/\b(generat|write.+(?:new|post|page|article)|creat|draft|compos|produce|brainstorm)/i', $msg ) ) {
			return 'generate';
		}

		// Edit: change existing content, update, fix, modify, delete.
		if ( preg_match( '/\b(edit|updat|chang|modif|delet|fix|replac|rewrit|improv|optimiz|refactor|remov|add.+to|set.+to|enabl|disabl|toggle|publish|unpublish)/i', $msg ) ) {
			return 'edit';
		}

		// Classify: short messages, greetings, simple questions.
		if ( strlen( $msg ) < 30 || preg_match( '/^(hi|hello|hey|what|how|can you|help|thanks)/i', $msg ) ) {
			return 'chat';
		}

		// Default: chat (cheapest).
		return 'chat';
	}

	/**
	 * Detect lightweight conversational turns that should avoid the full agent stack.
	 *
	 * Matches greetings, acknowledgements, and capability smalltalk only.
	 * Any site-specific noun or actionable verb keeps the request on the
	 * normal routed path so prompts like "what plugins do I have?" still use tools.
	 */
	public static function is_lightweight_chat_request( string $message, array $conversation = array() ): bool {
		unset( $conversation );

		if ( self::is_continuation_message( $message ) ) {
			return false;
		}

		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return false;
		}

		$normalized_words = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $normalized );
		$word_count       = str_word_count( $normalized_words );
		if ( $word_count > 8 ) {
			return false;
		}

		if ( preg_match(
			'/\b(wordpress|site|page|post|blog|content|homepage|home page|product|order|cart|checkout|inventory|stock|woo|woocommerce|plugin|theme|seo|title|meta|image|media|menu|navigation|elementor|widget|template|block|comment|category|tag|settings|email|user|customer|database|cache|log|cron|security|audit|scan|speed|performance|analytics?)\b/i',
			$normalized
		) ) {
			return false;
		}

		if ( preg_match(
			'/\b(read|list|find|search|check|analy[sz]e|audit|scan|inspect|create|write|draft|generate|edit|update|change|fix|delete|remove|replace|publish|optimi[sz]e|install|activate|deactivate|toggle|configure|set)\b/i',
			$normalized
		) ) {
			return false;
		}

		return 1 === preg_match(
			'/^(?:hi|hello|hello there|hey|hey there|thanks|thank you|thx|ok|okay|yes|no|sure|bye|cool|great|perfect|awesome|got it|sounds good|good morning|good afternoon|good evening|how are you|are you there|what can you do(?: for me)?|who are you|help(?: me)?|can you help|how can you help)[\s!?.,]*$/i',
			$normalized
		);
	}

	/**
	 * Detect a post-approval continuation marker.
	 */
	private static function is_continuation_message( string $message ): bool {
		return 1 === preg_match( '/^\[(?:Confirmed|Continue)\]/', trim( $message ) );
	}
}
