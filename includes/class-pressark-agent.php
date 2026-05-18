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
	const LIVE_COMPACTION_TOKEN_RATIO  = 0.90;
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
	private int                    $context_tokens_used = 0;
	private int                    $user_context_tokens_used = 0;
	private int                    $system_context_tokens_used = 0;
	private int                    $icu_spent = 0;
	private int                    $model_rounds    = 0;    // Actual model calls made.
	private string                 $actual_provider = '';    // Provider used in API calls.
	private string                 $actual_model    = '';    // Model used in API calls.
	private array                  $streamed_tool_summaries = array();
	private int                    $streamed_tool_token_count = 0;
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
	private int    $max_discover_calls = self::MAX_DISCOVER_CALLS;
	private int    $initial_tool_count = 0;

	// v3.2.0: Spin detection — tracks consecutive rounds with no real progress.
	private int    $idle_rounds        = 0;
	private string $last_tool_signature = '';

	// v3.6.0: Task type from classifier — used for step-ordering hints.
	private string $task_type          = 'chat';
	private string $mode               = 'execute';
	private bool   $has_proposed_write = false;

	// v4.3.2: Execution plan from planner — guides agent step ordering.
	private array  $plan_steps = array();
	private array  $cached_ai_plan = array();
	private int    $plan_step  = 1;
	private string $planning_mode = 'none';
	private array  $planning_decision = array();
	private array  $active_plan_artifact = array();
	private string $last_plan_progress_signature = '';

	// Set when update_plan succeeds in plan mode. Signals the agent loop to
	// break early and let finalize_plan_mode_result render a plan_ready card,
	// so the user doesn't wait for round_limit to fire.
	private bool   $plan_just_proposed = false;
	private int    $plan_stall_rounds = 0;
	private string $plan_stall_message = '';

	// v5.8.2 (2026-05-13, iter-38): true when the current round is a wrap
	// round triggered by a [Continue] envelope. Read by normalize_result_for_mode
	// to bypass plan_ready coercion: a wrap round's text is a summary of work
	// that already happened, not a new plan proposal, and re-coercing it back
	// into plan_ready produces a confusing "fresh checklist" card on top of a
	// completed action.
	private bool   $is_post_keep_wrap_round = false;

	// v4.0.0: Automation context for unattended runs.
	private ?array $automation_context = null;
	private string $run_id             = '';
	private int    $chat_id            = 0;
	private string $request_message    = '';

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
	 * Switch between execute mode and read-only plan mode.
	 */
	public function set_mode( string $mode ): void {
		$mode       = sanitize_key( $mode );
		$this->mode = 'plan' === $mode ? 'plan' : 'execute';
	}

	/**
	 * Attach router-side planning policy context.
	 */
	public function set_planning_context( array $context ): void {
		$planning_mode = sanitize_key( (string) ( $context['planning_mode'] ?? 'none' ) );
		$this->planning_mode = in_array( $planning_mode, array( 'none', 'soft_plan', 'hard_plan' ), true )
			? $planning_mode
			: 'none';
		$this->planning_decision = is_array( $context['planning_decision'] ?? null ) ? (array) $context['planning_decision'] : array();
		$this->max_discover_calls = max( 0, (int) ( $context['max_discover_calls'] ?? self::MAX_DISCOVER_CALLS ) );

		if ( class_exists( 'PressArk_Plan_Artifact' ) && ! empty( $context['plan_artifact'] ) && is_array( $context['plan_artifact'] ) ) {
			$this->active_plan_artifact = PressArk_Plan_Artifact::ensure( $context['plan_artifact'] );
		}
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

		if ( $this->is_plan_mode() ) {
			$meta['permission_mode'] = 'plan';
		}

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

	private static function ensure_plan_mode_loaded(): void {
		if ( ! class_exists( 'PressArk_Plan_Mode' ) ) {
			require_once __DIR__ . '/class-pressark-plan-mode.php';
		}
	}

	private function is_plan_mode(): bool {
		return 'plan' === $this->mode;
	}

	private function is_soft_plan_mode(): bool {
		return 'soft_plan' === $this->planning_mode;
	}

	private function is_hard_plan_policy(): bool {
		return 'hard_plan' === $this->planning_mode || $this->is_plan_mode();
	}

	private function plan_mode_allows_tool_name( string $tool_name ): bool {
		$tool_name = sanitize_key( $tool_name );
		if ( '' === $tool_name ) {
			return false;
		}

		if ( in_array( $tool_name, array( 'discover_tools', 'load_tools', 'load_tool_group', 'update_plan' ), true ) ) {
			return true;
		}

		if ( ! class_exists( 'PressArk_Tools' ) ) {
			return false;
		}

		$tool = PressArk_Tools::get_tool( $tool_name );

		return is_object( $tool )
			&& method_exists( $tool, 'is_readonly' )
			&& (bool) $tool->is_readonly();
	}

	private function filter_tool_set_for_mode( array $tool_set ): array {
		if ( ! $this->is_plan_mode() ) {
			return $tool_set;
		}

		$allowed_names  = array_values( array_filter(
			array_map( 'sanitize_key', (array) ( $tool_set['tool_names'] ?? array() ) ),
			fn( string $tool_name ): bool => $this->plan_mode_allows_tool_name( $tool_name )
		) );
		$allowed_lookup = array_flip( $allowed_names );

		$tool_set['tool_names'] = $allowed_names;
		$tool_set['schemas']    = array_values( array_filter(
			(array) ( $tool_set['schemas'] ?? array() ),
			static function ( $schema ) use ( $allowed_lookup ): bool {
				$name = sanitize_key( (string) ( is_array( $schema ) ? ( $schema['function']['name'] ?? '' ) : '' ) );
				return '' !== $name && isset( $allowed_lookup[ $name ] );
			}
		) );
		$tool_set['tool_count'] = count( $tool_set['schemas'] );

		$tool_set['effective_visible_tools'] = array_values( array_filter(
			array_map( 'sanitize_key', (array) ( $tool_set['effective_visible_tools'] ?? $allowed_names ) ),
			static fn( string $tool_name ): bool => isset( $allowed_lookup[ $tool_name ] )
		) );

		$descriptor_tools = array_values( array_filter(
			$allowed_names,
			static fn( string $tool_name ): bool => ! in_array( $tool_name, array( 'discover_tools', 'load_tools', 'load_tool_group', 'update_plan' ), true )
		) );
		$tool_set['descriptors']            = class_exists( 'PressArk_Tools' )
			? PressArk_Tools::get_prompt_snippets( $descriptor_tools )
			: '';
		$tool_set['capability_map']         = '';
		$tool_set['capability_maps']        = array();
		$tool_set['capability_map_variant'] = 'plan_readonly';
		$tool_set['groups']                 = $this->groups_for_tool_names( $allowed_names );

		$tool_state = is_array( $tool_set['tool_state'] ?? null ) ? $tool_set['tool_state'] : array();
		if ( ! empty( $tool_state ) ) {
			foreach ( array(
				'visible_tools',
				'loaded_tools',
				'searchable_tools',
				'discovered_tools',
				'discovered_history',
				'always_loaded_tools',
				'auto_loaded_tools',
				'deferred_loaded_tools',
				'deferred_available_tools',
				'request_hidden_tools',
			) as $field ) {
				if ( isset( $tool_state[ $field ] ) && is_array( $tool_state[ $field ] ) ) {
					$tool_state[ $field ] = array_values( array_filter(
						array_map( 'sanitize_key', $tool_state[ $field ] ),
						static fn( string $tool_name ): bool => isset( $allowed_lookup[ $tool_name ] )
					) );
				}
			}

			if ( isset( $tool_state['tools'] ) && is_array( $tool_state['tools'] ) ) {
				$tool_state['tools'] = array_values( array_filter(
					$tool_state['tools'],
					static function ( $row ) use ( $allowed_lookup ): bool {
						$name = sanitize_key( (string) ( is_array( $row ) ? ( $row['name'] ?? '' ) : '' ) );
						return '' !== $name && isset( $allowed_lookup[ $name ] );
					}
				) );
			}

			$tool_state['loaded_groups']         = $this->groups_for_tool_names( (array) ( $tool_state['loaded_tools'] ?? array() ) );
			$tool_state['visible_groups']        = $this->groups_for_tool_names( (array) ( $tool_state['visible_tools'] ?? array() ) );
			$tool_state['searchable_groups']     = $this->groups_for_tool_names( (array) ( $tool_state['searchable_tools'] ?? array() ) );
			$tool_state['discovered_groups']     = $this->groups_for_tool_names( (array) ( $tool_state['discovered_tools'] ?? array() ) );
			$tool_state['loaded_groups_visible'] = $this->groups_for_tool_names( (array) ( $tool_state['loaded_tools'] ?? array() ) );
			$tool_state['visible_tool_count']    = count( (array) ( $tool_state['visible_tools'] ?? array() ) );
			$tool_state['loaded_tool_count']     = count( (array) ( $tool_state['loaded_tools'] ?? array() ) );
			$tool_state['searchable_tool_count'] = count( (array) ( $tool_state['searchable_tools'] ?? array() ) );
			$tool_state['discovered_tool_count'] = count( (array) ( $tool_state['discovered_tools'] ?? array() ) );
			$tool_state['blocked_tool_count']    = 0;
			$tool_state['blocked_tools']         = array();
			$tool_state['blocked_groups']        = array();
			$tool_set['tool_state']              = $tool_state;
		}

		return $tool_set;
	}

	private function groups_for_tool_names( array $tool_names ): array {
		$groups = array();
		foreach ( array_map( 'sanitize_key', $tool_names ) as $tool_name ) {
			$group = class_exists( 'PressArk_Operation_Registry' )
				? PressArk_Operation_Registry::get_group( $tool_name )
				: '';
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		return array_values( array_unique( $groups ) );
	}

	private function filter_discovery_results_for_mode( array $results ): array {
		if ( ! $this->is_plan_mode() ) {
			return $results;
		}

		return array_values( array_filter(
			$results,
			function ( $row ): bool {
				if ( ! is_array( $row ) ) {
					return false;
				}

				if ( 'resource' === (string) ( $row['type'] ?? '' ) ) {
					return true;
				}

				return $this->plan_mode_allows_tool_name( (string) ( $row['name'] ?? '' ) );
			}
		) );
	}

	private function build_plan_step_rows( array $steps ): array {
		$rows = array();
		foreach ( array_values( $steps ) as $index => $step ) {
			if ( is_array( $step ) ) {
				$text = sanitize_text_field( (string) ( $step['text'] ?? '' ) );
			} else {
				$text = sanitize_text_field( (string) $step );
			}

			if ( '' === $text ) {
				continue;
			}

			$rows[] = array(
				'index'  => count( $rows ) + 1,
				'text'   => $text,
				'status' => 0 === $index ? 'active' : 'pending',
			);
		}

		return $rows;
	}

	private function build_plan_markdown( array $step_rows, string $fallback = '' ): string {
		$lines = array();
		foreach ( $step_rows as $row ) {
			$text = sanitize_text_field( (string) ( $row['text'] ?? '' ) );
			if ( '' !== $text ) {
				$lines[] = sprintf( '%d. %s', count( $lines ) + 1, $text );
			}
		}

		if ( ! empty( $lines ) ) {
			return implode( "\n", $lines );
		}

		return sanitize_textarea_field( trim( $fallback ) );
	}

	private function build_plan_ready_reply( array $step_rows ): string {
		$count = count( $step_rows );
		if ( $count > 0 ) {
			return sprintf(
				/* translators: %d: number of checklist steps. */
				__( 'Plan ready. Review the %d-step checklist below, then approve, revise, or cancel before execution continues.', 'pressark' ),
				$count
			);
		}

		return __( 'I need a narrower request before I can build a safe execution plan.', 'pressark' );
	}

	private static function compose_escalation_reply( array $reason_codes ): string {
		$reason_codes = array_values( array_unique( array_filter( array_map( 'sanitize_key', $reason_codes ) ) ) );
		if ( empty( $reason_codes ) ) {
			return '';
		}

		// v5.8.6 (2026-05-14, iter-42): share policy reason copy for escalation and upfront hard plans.
		$reason_phrases = array(
			'destructive_operation'  => __( 'an irreversible operation (delete/remove/reset)', 'pressark' ),
			'bulk_write_scope'       => __( 'a bulk write across multiple items', 'pressark' ),
			'ambiguous_targets'      => __( 'targets that were not explicitly named', 'pressark' ),
			'ambiguous_target'       => __( 'targets that were not explicitly named', 'pressark' ),
			'commerce_critical_write' => __( 'a commerce-critical change', 'pressark' ),
			'broad_scope'            => __( 'a broad site or store scope', 'pressark' ),
			'async_heavy_write'      => __( 'a long-running write', 'pressark' ),
			'multi_domain'           => __( 'work spanning multiple site areas', 'pressark' ),
			'multi_entity_write'     => __( 'changes across multiple items', 'pressark' ),
			'discovery_required'     => __( 'work that needs discovery before execution', 'pressark' ),
		);
		$soft_reason_codes = array(
			'explicit_plan_directive',
			'legacy_plan_signal',
			'predicted_write',
			'low_risk_read',
			'small_preview_protected_write',
			'contained_multi_step_work',
			'direct_execution_ok',
			'continuation_plan_resume',
			'continuation_execute_resume',
			'approved_plan_execution',
			'plan_policy_hard',
			'plan_policy_soft',
		);

		$reason_strings = array();
		$generic_needed = false;
		foreach ( $reason_codes as $code ) {
			if ( isset( $reason_phrases[ $code ] ) ) {
				$reason_strings[] = $reason_phrases[ $code ];
				continue;
			}

			if ( in_array( $code, $soft_reason_codes, true ) || str_starts_with( $code, 'router_preload_' ) ) {
				continue;
			}

			$generic_needed = true;
		}

		if ( $generic_needed ) {
			$reason_strings[] = __( 'a higher-risk action than usual', 'pressark' );
		}

		$reason_strings = array_values( array_unique( $reason_strings ) );
		if ( empty( $reason_strings ) ) {
			return '';
		}

		if ( count( $reason_strings ) > 1 ) {
			$last = array_pop( $reason_strings );
			$reasons_clause = implode( ', ', $reason_strings ) . ' and ' . $last;
		} else {
			$reasons_clause = $reason_strings[0];
		}

		return sprintf(
			/* translators: %s: enumerated escalation reasons */
			__( 'I paused before %s. The updated plan below shows what will run — approve to continue, revise to constrain it, or reject to cancel.', 'pressark' ),
			$reasons_clause
		);
	}

	private function build_plan_fallback_rows(): array {
		$fallback_steps = $this->build_plan_step_rows( $this->plan_steps );
		if ( ! empty( $fallback_steps ) ) {
			return $fallback_steps;
		}

		// Prefer a cached plan_with_ai result over the regex template so the
		// user-visible plan reflects the AI-driven classifier output rather
		// than generic "review / prepare / apply / verify" scaffolding.
		if ( ! empty( $this->cached_ai_plan['steps'] ?? array() ) ) {
			$ai_steps = array_values( array_filter( array_map(
				static fn( $s ) => is_string( $s ) ? trim( $s ) : '',
				(array) $this->cached_ai_plan['steps']
			), 'strlen' ) );
			if ( ! empty( $ai_steps ) ) {
				return $this->build_plan_step_rows( $ai_steps );
			}
		}

		$message = trim( $this->request_message );
		if ( '' === $message ) {
			return array();
		}

		$task_type = self::refine_chat_task_type( $message, $this->task_type );

		$groups = $this->refine_preload_groups(
			$message,
			$task_type,
			self::detect_preload_groups( $message, $task_type )
		);

		return $this->build_plan_step_rows(
			$this->infer_local_plan_steps( $message, $task_type, $groups )
		);
	}

	private static function refine_chat_task_type( string $message, string $task_type ): string {
		if ( 'chat' !== $task_type ) {
			return $task_type;
		}

		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return $task_type;
		}

		if (
			preg_match( '/\b(?:make|create|build|design)\b/i', $normalized )
			&& preg_match( '/\b(?:new|fresh|from scratch)\b/i', $normalized )
			&& preg_match(
				'/\b(?:post|page|article|blog(?:\s+post)?|landing\s+page|homepage|home\s+page|description|copy|headline|hero|section|banner)\b/i',
				$normalized
			)
		) {
			return 'generate';
		}

		if (
			(
				preg_match( '/\b(?:product|products|catalog|catalogue|store|shop|woo|woocommerce)\b/i', $normalized )
				&& preg_match( '/\b(?:price|pricing|sale|discount|markdown|markup|off|regular price|sale price)\b/i', $normalized )
			)
			|| preg_match(
				'/\b(?:current price|regular price|sale price)\b.*(?:[-+]\s*\d+(?:\.\d+)?%?|\d+(?:\.\d+)?%\s*off)|(?:[-+]\s*\d+(?:\.\d+)?%?|\d+(?:\.\d+)?%\s*off).*\b(?:current price|regular price|sale price)\b/i',
				$normalized
			)
		) {
			return 'edit';
		}

		if (
			preg_match(
				'/\b(?:make|turn|move|match|use|swap|rename|reorder|restyl(?:e|ing)|redesign|adjust|align|clean(?:\s+up)?|polish|tighten|refresh|show|hide)\b/i',
				$normalized
			)
			&& preg_match(
				'/\b(?:hero|section|banner|headline|title|cta|button|copy|text|image|gallery|logo|header|footer|menu|navigation|layout|block|widget|form|page|post|homepage|home\s+page|landing\s+page|product|pricing|price|seo|meta|description|setting|theme|plugin|template|css|php|javascript|js)\b/i',
				$normalized
			)
		) {
			return 'edit';
		}

		return $task_type;
	}

	private static function infer_wc_price_write_mode( string $message ): string {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return 'none';
		}

		if ( preg_match( '/\b(?:remove|end|clear|stop|cancel)\b.*\bsale\b/i', $normalized ) ) {
			return 'clear_sale';
		}

		if (
			preg_match( '/\b(?:sale price|on sale)\b/i', $normalized )
			|| preg_match( '/\b(?:apply|start|run|set|put|place|mark)\b.{0,24}\bsale\b/i', $normalized )
			|| preg_match( '/\b\d+(?:\.\d+)?%\s+sale\b/i', $normalized )
		) {
			return 'sale';
		}

		if (
			preg_match( '/\b(?:regular price|base price|current price)\b/i', $normalized )
			|| preg_match( '/\b(?:increase|decrease|raise|lower|reduce|drop|cut|bump|mark\s+up|markup)\b/i', $normalized )
		) {
			return 'regular';
		}

		if (
			preg_match( '/\b(?:discount|off|cheaper|less expensive|price change|price adjustment|adjust price|change price|update price)\b/i', $normalized )
			|| preg_match( '/(?:[-+]\s*\d+(?:\.\d+)?%?|\d+(?:\.\d+)?%\s*off)/i', $normalized )
		) {
			return 'ambiguous';
		}

		if ( preg_match( '/\bsale\b/i', $normalized ) ) {
			return 'sale';
		}

		return 'none';
	}

	private static function wc_message_mentions_price_amount( string $message ): bool {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/(?:[-+]\s*\d+(?:\.\d+)?%?|\d+(?:\.\d+)?%\s*off|\b(?:to|at|for)\s*(?:usd|eur|gbp|cad|aud|\$)?\s*\d+(?:\.\d+)?\b|(?:usd|eur|gbp|cad|aud|\$)\s*\d+(?:\.\d+)?\b|\bfree\b)/i',
			$normalized
		);
	}

	public static function build_plan_clarification_reply( string $message, string $task_type = 'chat', array $groups = array() ): string {
		$message   = trim( PressArk_Plan_Mode::strip_plan_directive( $message ) );
		$task_type = self::refine_chat_task_type( $message, $task_type );
		$groups    = array_values( array_filter( array_map(
			static function ( $group ) {
				if ( ! is_string( $group ) ) {
					return '';
				}

				if ( class_exists( 'PressArk_Operation_Registry' ) && ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
					return '';
				}

				return $group;
			},
			$groups
		) ) );

		if (
			preg_match( '/\bproducts?\b/i', $message )
			|| in_array( 'woocommerce', $groups, true )
		) {
			$has_bulk_scope = (bool) preg_match(
				'/\b(?:all(?:\s+of\s+them)?|every|each|bulk|batch|across|multiple|site-?wide|entire|catalog|catalogue|dozens|hundreds)\b/i',
				$message
			);
			$price_mode       = self::infer_wc_price_write_mode( $message );
			$has_price_amount = self::wc_message_mentions_price_amount( $message );
			if ( 'ambiguous' === $price_mode ) {
				return __( 'Do you want this applied as a WooCommerce sale price, or should I reduce the regular price instead?', 'pressark' );
			}
			if ( 'sale' === $price_mode ) {
				if ( $has_bulk_scope && ! $has_price_amount ) {
					return __( 'What sale price or discount should I apply across those products?', 'pressark' );
				}

				if ( ! $has_bulk_scope ) {
					return $has_price_amount
						? __( 'Which products should I put on sale?', 'pressark' )
						: __( 'Which products should I put on sale, and what sale amount should I use?', 'pressark' );
				}
			}

			if ( 'regular' === $price_mode ) {
				if ( $has_bulk_scope && ! $has_price_amount ) {
					return __( 'How much should I increase or decrease the regular price across those products?', 'pressark' );
				}

				if ( ! $has_bulk_scope ) {
					return $has_price_amount
						? __( 'Which products should I change the regular price for?', 'pressark' )
						: __( 'Which products should I update, and by how much should I change the regular price?', 'pressark' );
				}
			}

			if ( 'clear_sale' === $price_mode && ! $has_bulk_scope ) {
				return __( 'Which products should I remove the sale from?', 'pressark' );
			}

			return (bool) preg_match(
				'/\b(?:all|every|bulk|batch|across|multiple|site-?wide|entire|catalog|catalogue|dozens|hundreds|\d+\s+(?:products?|posts?|pages?|items?|orders?))\b/i',
				$message
			)
				? __( 'Which products should I update, and what change should I make to them?', 'pressark' )
				: __( 'Which product should I update, and what should I change?', 'pressark' );
		}

		if (
			preg_match( '/\bseo\b|meta.?title|meta.?desc|canonical|robots\.txt|schema|search.?engine/i', $message )
			|| in_array( 'seo', $groups, true )
		) {
			return __( 'Which page, post, or product should I work on for this SEO change?', 'pressark' );
		}

		if (
			preg_match( '/\bplugin|theme|css|php|javascript|js|template|shortcode|snippet|hook|filter|code\b/i', $message )
			|| 'code' === $task_type
		) {
			return __( 'Which plugin, theme area, or code file should I change?', 'pressark' );
		}

		if ( preg_match( '/\bsetting|settings|option|site title|tagline|menu|widget\b/i', $message ) ) {
			return __( 'Which setting or admin area should I update?', 'pressark' );
		}

		if (
			preg_match(
				'/\bpage|post|article|homepage|home\s+page|landing\s+page|hero|section|banner|headline|cta|button|copy|text|image|header|footer|layout\b/i',
				$message
			)
			|| in_array( 'content', $groups, true )
		) {
			return __( 'Which page or post should I update, and what should I change on it?', 'pressark' );
		}

		if ( 'generate' === $task_type ) {
			return __( 'What should I create, and where should it go on the site?', 'pressark' );
		}

		if ( 'edit' === $task_type ) {
			return __( 'What exact part of the site should I change, and what should the new result be?', 'pressark' );
		}

		return __( 'What should I work on first: a page, product, setting, or code change?', 'pressark' );
	}

	private function plan_response_requires_user_input( string $text ): bool {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return false;
		}

		// ── Any sentence-final "?" anywhere in the response → awaiting user.
		// The original first-line-only check missed trailing questions like
		// "Yes, I'm here. What would you like to work on?" which pushed the
		// agent loop into a grounding-retry even though the model had clearly
		// asked the user something. Using /m so `$` matches end-of-line inside
		// multi-line replies; /u so unicode ? variants still match.
		if ( preg_match( '/\?\s*$/mu', $text ) ) {
			return true;
		}

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$lead  = '';
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$lead = $line;
				break;
			}
		}
		if ( '' === $lead ) {
			$lead = $text;
		}

		if ( preg_match(
			'/^(?:could you provide|please provide|i need to know|i need more context|which (?:post|page|product|item|record)|what (?:post|page|product|item)|send me|share|give me)\b/i',
			$lead
		) ) {
			return true;
		}

		return (bool) (
			preg_match( '/\?\s*$/', $lead )
			&& preg_match( '/^(?:which|what|where|when|who|could you|can you|please)\b/i', $lead )
		);
	}

	private function should_promote_prose_approval_to_plan_ready( string $text, ?PressArk_Checkpoint $checkpoint = null ): bool {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text || ! $this->plan_response_requires_user_input( $text ) ) {
			return false;
		}

		if ( ! preg_match( '/\b(?:approve|approval|proceed|continue|go\s+ahead|confirm)\b/i', $text ) ) {
			return false;
		}

		$request = strtolower( $this->resolve_effective_request_message( $checkpoint ) );
		if ( '' === trim( $request ) ) {
			return false;
		}

		$has_write_intent = (bool) preg_match(
			'/\b(?:set|change|update|edit|modify|remove|delete|trash|reset|clear|deactivate|disable|uninstall|revoke|replace)\b/i',
			$request
		);
		if ( ! $has_write_intent ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:admin\s+email|site\s+title|tagline|setting|settings|option|plugin|theme|users?|roles?|capabilities|permissions?|product|products?|order|orders?|payment|shipping|tax|checkout|delete|remove|trash|reset|deactivate|disable|uninstall|revoke)\b/i',
			$request
		);
	}

	// v5.8.12 (2026-05-14): fail empty approved-plan execution before silent success.
	private function should_fail_empty_approved_plan_execution_response( string $text, ?PressArk_Checkpoint $checkpoint = null ): bool {
		if ( '' !== trim( $text ) || $this->is_plan_mode() || 'execute' !== $this->mode || ! $checkpoint ) {
			return false;
		}

		if ( ! method_exists( $checkpoint, 'get_plan_phase' ) || 'executing' !== $checkpoint->get_plan_phase() ) {
			return false;
		}

		if ( $this->has_proposed_write ) {
			return false;
		}

		if ( method_exists( $checkpoint, 'get_approval_outcomes' ) && ! empty( $checkpoint->get_approval_outcomes() ) ) {
			return false;
		}

		return true;
	}

	// v5.8.1 (2026-05-13, iter-37): Plan Mode terminal-conclusion classifier.
	// Observed 2026-05-13 Chain G ("Add Phase 4A FAQ page to the main menu"
	// when FAQ already in menu): after grounding reads, Sonnet 4.6 emitted
	// "No changes needed" as plain text. The harness then forced 3 retry
	// rounds via build_plan_synthesis_retry_message ("write the numbered
	// checklist now"), burning ~165k tokens reloading the same 55kB request
	// and ending with a stale Plan card still asking the user to Execute a
	// redundant 4-step plan. Conservative pattern list — only matches when
	// the model has clearly TERMINATED the request, not when it's narrating
	// progress. False negatives here are harmless (current loop behavior);
	// false positives skip a legitimate "you forgot the checklist" nudge.
	private function plan_response_concludes_no_action( string $text ): bool {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return false;
		}

		// "No changes/action/writes/checklist/edits/work needed" — primary signal.
		if ( preg_match(
			'/\bno\s+(?:changes?|action|writes?|edits?|checklist|plan|work|updates?|modifications?|menu\s+updates?)\s+(?:are\s+|is\s+)?(?:needed|required|necessary|to\s+(?:make|do|apply))\b/i',
			$text
		) ) {
			return true;
		}

		// "Nothing to add/do/change/create" — explicit negative-existential.
		if ( preg_match(
			'/\b(?:nothing|none)\s+to\s+(?:add|do|change|update|edit|create|modify|fix|apply|plan|execute)\b/i',
			$text
		) ) {
			return true;
		}

		// "Already complete/done/in the menu/exists" — state-already-matches signal.
		// Anchored on common phrases the model uses when grounding reveals the
		// requested state was already satisfied.
		if ( preg_match(
			'/\b(?:task|request|work|goal)\s+is\s+already\s+(?:complete|done|fulfilled|satisfied)\b/i',
			$text
		) ) {
			return true;
		}

		return false;
	}

	private function checkpoint_has_grounded_plan_context( PressArk_Checkpoint $checkpoint ): bool {
		if ( method_exists( $checkpoint, 'get_read_state' ) && ! empty( $checkpoint->get_read_state() ) ) {
			return true;
		}

		if ( method_exists( $checkpoint, 'get_selected_target' ) && ! empty( $checkpoint->get_selected_target() ) ) {
			return true;
		}

		return method_exists( $checkpoint, 'get_entities' ) && ! empty( $checkpoint->get_entities() );
	}

	private function planning_requires_grounded_reads(): bool {
		return $this->is_plan_mode() || $this->is_soft_plan_mode();
	}

	// v5.8.2 (2026-05-13, iter-38): "Is the latest user turn a synthetic
	// [Continue] envelope?" — if yes, the chain is already past the planning
	// phase (the user clicked Execute → preview → Keep, the harness is now
	// driving the wrap round). Synthesis-retry and plan_ready coercion are
	// inappropriate here: there's nothing new for the model to plan, the
	// task is mid- or post-execution. Observed Chain B post-Keep wrap
	// (2026-05-13): R3 emitted a clean "page has been published" wrap; the
	// harness then injected the synthesis-retry message and R4 returned a
	// retrospective numbered list, which normalize_result_for_mode coerced
	// back to plan_ready — producing a confusing "Plan ready - 4 step
	// checklist" card next to a page that was already created.
	//
	// We look at the messages array directly rather than the checkpoint
	// state because compaction can summarize away ledger context but the
	// raw messages still contain the [Continue] envelope.
	private function messages_indicate_post_keep_wrap( array $messages ): bool {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$msg  = $messages[ $i ];
			$role = sanitize_key( (string) ( $msg['role'] ?? '' ) );
			if ( 'user' !== $role ) {
				continue;
			}
			$content = $msg['content'] ?? '';
			if ( is_array( $content ) ) {
				$content = implode( ' ', array_map(
					static function ( $part ): string {
						if ( is_array( $part ) ) {
							return (string) ( $part['text'] ?? '' );
						}
						return is_string( $part ) ? $part : '';
					},
					$content
				) );
			}
			$content = (string) $content;
			// [Continue] is the harness-injected envelope for post-Keep and
			// post-apply auto-resume. Match at start (after optional whitespace).
			return 1 === preg_match( '/^\s*\[Continue\]/', $content );
		}
		return false;
	}

	private static function last_tool_result_was_empty_success( array $messages ): bool {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$message = is_array( $messages[ $i ] ?? null ) ? $messages[ $i ] : array();
			if ( 'tool' !== sanitize_key( (string) ( $message['role'] ?? '' ) ) ) {
				continue;
			}

			$content = $message['content'] ?? '';
			$payload = null;
			if ( is_string( $content ) ) {
				$decoded = json_decode( trim( $content ), true );
				if ( is_array( $decoded ) ) {
					$payload = $decoded;
				}
			} elseif ( is_array( $content ) ) {
				if ( array_key_exists( 'success', $content ) ) {
					$payload = $content;
				} else {
					foreach ( $content as $part ) {
						$text = is_array( $part )
							? (string) ( $part['text'] ?? $part['content'] ?? '' )
							: ( is_string( $part ) ? $part : '' );
						if ( '' === trim( $text ) ) {
							continue;
						}
						$decoded = json_decode( trim( $text ), true );
						if ( is_array( $decoded ) ) {
							$payload = $decoded;
							break;
						}
					}
				}
			}

			if ( ! is_array( $payload ) || true !== ( $payload['success'] ?? null ) ) {
				return false;
			}

			foreach ( array( 'count', 'total', 'total_count', 'found', 'found_posts' ) as $count_key ) {
				if ( array_key_exists( $count_key, $payload ) && is_numeric( $payload[ $count_key ] ) && 0 === (int) $payload[ $count_key ] ) {
					return true;
				}
			}

			foreach ( array( 'data', 'items', 'products', 'orders', 'results', 'rows', 'customers', 'reviews', 'variations', 'categories', 'posts', 'pages', 'users' ) as $list_key ) {
				if ( array_key_exists( $list_key, $payload ) && is_array( $payload[ $list_key ] ) && empty( $payload[ $list_key ] ) ) {
					return true;
				}
			}

			$pagination = is_array( $payload['_pagination'] ?? null ) ? $payload['_pagination'] : array();
			if ( array_key_exists( 'total', $pagination ) && is_numeric( $pagination['total'] ) && 0 === (int) $pagination['total'] ) {
				return true;
			}

			return false;
		}

		return false;
	}

	private function build_grounding_retry_message( bool $before_writes = false ): string {
		return $before_writes
			? __( 'Inspect the current state with relevant read tools first. Do not propose or execute writes until those read results are in context.', 'pressark' )
			: __( 'Use relevant read-only tools first to inspect the current state and exact targets. Do not finalize the plan yet; build it from those read results.', 'pressark' );
	}

	private function build_plan_synthesis_retry_message(): string {
		return __( 'Use the read results already in context to write the numbered checklist now. Base it on the exact targets, current state, risks, and verification reads you uncovered.', 'pressark' );
	}

	private function build_read_first_synthetic_result(): array {
		return array(
			'success' => false,
			'error'   => 'read_before_write_required',
			'message' => $this->build_grounding_retry_message( true ),
		);
	}

	/**
	 * Build a synthetic tool-result for malformed/unknown tool calls.
	 *
	 * Includes the tool name (so the model can correlate without parsing
	 * tool_use_id) and, in plan mode, a hint pointing the model toward the
	 * read-only / meta tools that ARE available — which avoids the model
	 * silently retrying the same blocked write call.
	 */
	private function build_invalid_tool_input_result( string $tool_name, string $message ): array {
		$message = trim( sanitize_text_field( $message ) );
		if ( '' === $message ) {
			$message = __( 'Invalid input for this tool.', 'pressark' );
		}

		if ( $this->is_plan_mode() ) {
			$message .= ' ' . __( 'You are in plan mode — only read-only tools and update_plan are available. Use discover_tools or load_tools to find the right read tool, inspect the current state, then call update_plan with the proposed steps.', 'pressark' );
		}

		return array(
			'success' => false,
			'error'   => 'invalid_tool_input',
			'tool'    => sanitize_key( $tool_name ),
			'message' => sanitize_text_field( $message ),
		);
	}

	/**
	 * Build synthetic tool results for writes that pause in the preview flow.
	 *
	 * This closes the replay gap where preview-triggering tool calls used to
	 * return without any matching tool_result message, which forced replay
	 * repair to inject a placeholder later.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls Previewable tool calls.
	 * @param array<string,mixed>            $session    Preview session payload.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_preview_pause_tool_results( array $tool_calls, array $session ): array {
		return $this->build_write_pause_tool_results(
			$tool_calls,
			'preview_pending',
			array(
				'preview_session_id' => sanitize_text_field( (string) ( $session['session_id'] ?? '' ) ),
				'preview_url'        => esc_url_raw( (string) ( $session['signed_url'] ?? '' ) ),
				'diff'               => is_array( $session['diff'] ?? null ) ? (array) $session['diff'] : array(),
			)
		);
	}

	/**
	 * Build synthetic tool results for writes that pause behind a confirm card.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls Confirm-required tool calls.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_confirm_pause_tool_results( array $tool_calls ): array {
		return $this->build_write_pause_tool_results( $tool_calls, 'confirm_pending' );
	}

	/**
	 * Build synthetic tool results for preview-session creation failures.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls Previewable tool calls.
	 * @param string                         $message    Failure message.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_preview_failure_tool_results( array $tool_calls, string $message ): array {
		$results       = array();
		$pending_count = count( $tool_calls );
		$message       = sanitize_text_field( $message );

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$tool_use_id = sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) );
			if ( '' === $tool_use_id ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			$args      = is_array( $tool_call['arguments'] ?? null )
				? (array) $tool_call['arguments']
				: ( is_array( $tool_call['params'] ?? null ) ? (array) $tool_call['params'] : array() );

			$results[] = array(
				'tool_use_id' => $tool_use_id,
				'result'      => array(
					'success' => false,
					'error'   => 'preview_session_failed',
					'message' => $message,
					'data'    => array_filter(
						array(
							'status'               => 'preview_error',
							'approval_ui'          => 'preview',
							'tool_name'            => $tool_name,
							'pending_action_count' => $pending_count,
							'args_preview'         => $this->summarize_pause_tool_args( $args ),
						),
						static function ( $value ): bool {
							return is_array( $value ) ? ! empty( $value ) : '' !== (string) $value;
						}
					),
				),
			);
		}

		return $results;
	}

	/**
	 * Build replay-safe synthetic tool results for write proposals.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls Tool calls to materialize.
	 * @param string                         $status     preview_pending|confirm_pending.
	 * @param array<string,mixed>            $extra      Shared status metadata.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_write_pause_tool_results( array $tool_calls, string $status, array $extra = array() ): array {
		$results       = array();
		$pending_count = count( $tool_calls );

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$tool_use_id = sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) );
			if ( '' === $tool_use_id ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			$args      = is_array( $tool_call['arguments'] ?? null )
				? (array) $tool_call['arguments']
				: ( is_array( $tool_call['params'] ?? null ) ? (array) $tool_call['params'] : array() );

			$data = array(
				'status'               => $status,
				'approval_ui'          => 'preview_pending' === $status ? 'preview' : 'confirm_card',
				'tool_name'            => $tool_name,
				'pending_action_count' => $pending_count,
				'args_preview'         => $this->summarize_pause_tool_args( $args ),
			);

			if ( 'preview_pending' === $status ) {
				$data['preview_session_id'] = sanitize_text_field( (string) ( $extra['preview_session_id'] ?? '' ) );
				$data['preview_url']        = esc_url_raw( (string) ( $extra['preview_url'] ?? '' ) );
				$data['diff_summary']       = $this->summarize_preview_diff( (array) ( $extra['diff'] ?? array() ) );
			}

			$results[] = array(
				'tool_use_id' => $tool_use_id,
				'result'      => array(
					'success' => true,
					'message' => $this->write_pause_result_message( $status, $pending_count ),
					'data'    => array_filter(
						$data,
						static function ( $value ): bool {
							return is_array( $value ) ? ! empty( $value ) : '' !== (string) $value;
						}
					),
				),
			);
		}

		return $results;
	}

	/**
	 * Human-readable pause status for replayed write tool results.
	 */
	private function write_pause_result_message( string $status, int $pending_count ): string {
		if ( 'preview_pending' === $status ) {
			return $pending_count > 1
				? __( 'Writes staged in preview. Awaiting user approval before apply.', 'pressark' )
				: __( 'Write staged in preview. Awaiting user approval before apply.', 'pressark' );
		}

		if ( 'confirm_pending' === $status ) {
			return $pending_count > 1
				? __( 'Write proposals are awaiting user confirmation before apply.', 'pressark' )
				: __( 'Write proposal is awaiting user confirmation before apply.', 'pressark' );
		}

		return __( 'Write request is awaiting approval before apply.', 'pressark' );
	}

	/**
	 * Keep only compact scalar args in synthetic write tool results.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	private function summarize_pause_tool_args( array $args ): array {
		$summary = array();

		foreach ( $args as $key => $value ) {
			if ( count( $summary ) >= 6 ) {
				break;
			}

			$clean_key = sanitize_key( (string) $key );
			if ( '' === $clean_key ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				if ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
					if ( '' === trim( $value ) ) {
						continue;
					}
					if ( mb_strlen( $value ) > 120 ) {
						$value = mb_substr( $value, 0, 117 ) . '...';
					}
				}

				$summary[ $clean_key ] = $value;
				continue;
			}

			if ( ! is_array( $value ) || empty( $value ) ) {
				continue;
			}

			$scalars = array();
			foreach ( array_slice( $value, 0, 3 ) as $item ) {
				if ( ! is_scalar( $item ) && null !== $item ) {
					continue;
				}

				if ( is_string( $item ) ) {
					$item = sanitize_text_field( $item );
					if ( mb_strlen( $item ) > 80 ) {
						$item = mb_substr( $item, 0, 77 ) . '...';
					}
				}

				$scalars[] = $item;
			}

			if ( ! empty( $scalars ) ) {
				$summary[ $clean_key ] = $scalars;
			} else {
				$summary[ $clean_key . '_count' ] = count( $value );
			}
		}

		return $summary;
	}

	/**
	 * Compact diff summary for preview-session replay results.
	 *
	 * @param array<int,array<string,mixed>> $diff Preview diff rows.
	 * @return array<string,mixed>
	 */
	private function summarize_preview_diff( array $diff ): array {
		if ( empty( $diff ) ) {
			return array();
		}

		$labels = array();
		$fields = array();

		foreach ( array_slice( $diff, 0, 3 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $entry['label'] ?? '' ) );
			if ( '' !== $label ) {
				$labels[] = $label;
			}

			foreach ( array_slice( (array) ( $entry['items'] ?? array() ), 0, 3 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$field = sanitize_text_field( (string) ( $item['field'] ?? '' ) );
				if ( '' !== $field ) {
					$fields[] = $field;
				}
			}
		}

		return array_filter(
			array(
				'change_count' => count( $diff ),
				'labels'       => array_values( array_unique( $labels ) ),
				'fields'       => array_values( array_unique( $fields ) ),
			),
			static function ( $value ): bool {
				return is_array( $value ) ? ! empty( $value ) : null !== $value && '' !== (string) $value;
			}
		);
	}

	private function task_requires_externalized_plan( ?PressArk_Checkpoint $checkpoint ): bool {
		$existing_steps = $checkpoint ? $checkpoint->get_plan_steps() : array();
		if ( count( $existing_steps ) >= 3 ) {
			return true;
		}

		if ( $this->is_plan_mode() || 'hard_plan' === $this->planning_mode ) {
			return true;
		}

		$message = $this->resolve_effective_request_message( $checkpoint );
		if ( '' === trim( $message ) ) {
			return false;
		}

		$task_type = self::refine_chat_task_type(
			$message,
			'' !== $this->task_type ? $this->task_type : self::classify_task( $message )
		);
		$groups = $this->refine_preload_groups(
			$message,
			$task_type,
			self::detect_preload_groups( $message, $task_type )
		);

		return count( $this->infer_local_plan_steps( $message, $task_type, $groups ) ) >= 3;
	}

	private function requires_plan( string $user_message, ?PressArk_Checkpoint $checkpoint = null ): bool {
		if ( $checkpoint ) {
			$remaining_steps = array_values( array_filter(
				$checkpoint->get_plan_steps(),
				static fn( $step ): bool => is_array( $step ) && ! in_array( (string) ( $step['status'] ?? '' ), array( 'completed', 'blocked' ), true )
			) );
			if ( ! empty( $remaining_steps ) ) {
				return true;
			}
		}

		if ( $this->is_plan_mode() || 'hard_plan' === $this->planning_mode ) {
			return true;
		}

		$message = trim( $user_message );
		if ( '' === $message ) {
			$message = $this->resolve_effective_request_message( $checkpoint );
		}
		if ( '' === trim( $message ) ) {
			return false;
		}

		$task_type = self::refine_chat_task_type(
			$message,
			'' !== $this->task_type ? $this->task_type : self::classify_task( $message )
		);
		$groups = $this->refine_preload_groups(
			$message,
			$task_type,
			self::detect_preload_groups( $message, $task_type )
		);

		return count( $this->infer_local_plan_steps( $message, $task_type, $groups ) ) >= 3;
	}

	private function has_active_plan_step( ?PressArk_Checkpoint $checkpoint ): bool {
		return $checkpoint
			&& 1 === $checkpoint->get_in_progress_plan_step_count()
			&& ! empty( $this->get_in_progress_step( $checkpoint ) );
	}

	private function get_in_progress_step( ?PressArk_Checkpoint $checkpoint ): array {
		if ( ! $checkpoint ) {
			return array();
		}

		foreach ( $checkpoint->get_plan_steps() as $step ) {
			if ( is_array( $step ) && 'in_progress' === ( $step['status'] ?? '' ) ) {
				return $step;
			}
		}

		return array();
	}

	private function is_plan_target_selector_key( string $key ): bool {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return false;
		}

		return in_array(
			$key,
			array(
				'id',
				'ids',
				'post_id',
				'post_ids',
				'page_id',
				'page_ids',
				'product_id',
				'product_ids',
				'order_id',
				'order_ids',
				'user_id',
				'user_ids',
				'term_id',
				'term_ids',
				'comment_id',
				'comment_ids',
				'variation_id',
				'variation_ids',
				'template_id',
				'template_ids',
				'widget_id',
				'widget_ids',
				'slug',
				'slugs',
				'path',
				'paths',
				'file',
				'files',
				'template',
				'templates',
				'key',
				'keys',
				'handle',
				'handles',
			),
			true
		) || (bool) preg_match( '/(?:^|_)(?:id|slug|path|file|template|key|handle)$/', $key );
	}

	private function normalize_plan_match_token( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/^[`"\']+|[`"\']+$/', '', $value );
		$value = str_replace( '\\', '/', $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		return sanitize_text_field( (string) $value );
	}

	private function extract_step_target_tokens( array $step ): array {
		$tokens = array();
		$texts  = array_filter( array(
			sanitize_text_field( (string) ( $step['content'] ?? '' ) ),
			sanitize_text_field( (string) ( $step['activeForm'] ?? '' ) ),
		) );

		foreach ( $texts as $text ) {
			if ( preg_match_all( '/[A-Za-z0-9._\/-]+\.[A-Za-z0-9]{2,8}/', $text, $matches ) ) {
				foreach ( (array) ( $matches[0] ?? array() ) as $match ) {
					$tokens[] = $this->normalize_plan_match_token( (string) $match );
				}
			}
		}

		$post_id = absint( $step['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$tokens[] = (string) $post_id;
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	private function extract_tool_call_target_tokens( string $tool_name, array $args ): array {
		$tokens = array();

		$collect = function ( $value, string $key = '' ) use ( &$collect, &$tokens ): void {
			if ( is_array( $value ) ) {
				foreach ( $value as $child_key => $child_value ) {
					$collect( $child_value, is_string( $child_key ) ? $child_key : $key );
				}
				return;
			}

			if ( ! is_scalar( $value ) ) {
				return;
			}

			$text = $this->normalize_plan_match_token( (string) $value );
			if ( '' === $text ) {
				return;
			}

			if (
				$this->is_plan_target_selector_key( $key )
				|| (bool) preg_match( '/[A-Za-z0-9._\/-]+\.[A-Za-z0-9]{2,8}/', $text )
				|| false !== strpos( $text, '/' )
			) {
				$tokens[] = $text;
			}
		};

		foreach ( $args as $key => $value ) {
			$collect( $value, is_string( $key ) ? $key : '' );
		}

		$post_id = absint( $args['post_id'] ?? $args['id'] ?? $args['product_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$tokens[] = (string) $post_id;
		}

		if ( 'read_resource' === sanitize_key( $tool_name ) ) {
			$tokens = array_merge( $tokens, $this->extract_read_resource_target_tokens( $args ) );
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	private function extract_read_resource_target_tokens( array $args ): array {
		$uri = sanitize_text_field( (string) ( $args['uri'] ?? '' ) );
		if ( '' === $uri ) {
			return array();
		}

		if (
			! class_exists( 'PressArk_Tool_Result_Artifacts' )
			|| ! method_exists( 'PressArk_Tool_Result_Artifacts', 'is_tool_result_uri' )
			|| ! PressArk_Tool_Result_Artifacts::is_tool_result_uri( $uri )
			|| ! method_exists( 'PressArk_Tool_Result_Artifacts', 'read_resource' )
		) {
			return array();
		}

		$artifact = PressArk_Tool_Result_Artifacts::read_resource(
			$uri,
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
		);
		if ( empty( $artifact['success'] ) ) {
			return array();
		}

		$meta = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::sanitize_snapshot( $artifact['data']['meta'] ?? array() )
			: ( is_array( $artifact['data']['meta'] ?? null ) ? (array) $artifact['data']['meta'] : array() );
		if ( empty( $meta ) ) {
			return array();
		}

		$tokens = array();
		foreach ( (array) ( $meta['target_post_ids'] ?? array() ) as $target_post_id ) {
			$target_post_id = absint( $target_post_id );
			if ( $target_post_id > 0 ) {
				$tokens[] = (string) $target_post_id;
			}
		}

		foreach ( (array) ( $meta['resource_uris'] ?? array() ) as $resource_uri ) {
			$resource_uri = $this->normalize_plan_match_token( (string) $resource_uri );
			if ( '' !== $resource_uri ) {
				$tokens[] = $resource_uri;
			}
		}

		return array_values( array_unique( array_filter( $tokens ) ) );
	}

	private function extract_step_keyword_tokens( array $step ): array {
		$text = strtolower(
			trim(
				sanitize_text_field(
					implode(
						' ',
						array_filter( array(
							(string) ( $step['content'] ?? '' ),
							(string) ( $step['activeForm'] ?? '' ),
						) )
					)
				)
			)
		);
		if ( '' === $text ) {
			return array();
		}

		$words     = preg_split( '/[^a-z0-9._-]+/', $text ) ?: array();
		$stopwords = array(
			'edit',
			'editing',
			'update',
			'updating',
			'run',
			'running',
			'read',
			'reading',
			'check',
			'checking',
			'review',
			'reviewing',
			'apply',
			'applying',
			'preview',
			'confirm',
			'complete',
			'completing',
			'the',
			'and',
			'for',
			'with',
			'into',
			'from',
			'this',
			'that',
		);
		$tokens    = array();

		foreach ( $words as $word ) {
			$word = sanitize_key( (string) $word );
			if ( '' === $word || in_array( $word, $stopwords, true ) ) {
				continue;
			}
			if ( strlen( $word ) < 4 && ! in_array( $word, array( 'css', 'seo' ), true ) ) {
				continue;
			}
			if ( false !== strpos( $word, '.' ) ) {
				continue;
			}
			$tokens[] = $word;
		}

		return array_values( array_unique( array_slice( $tokens, 0, 6 ) ) );
	}

	private function tool_matches_current_step( string $tool_name, array $args, array $current_step ): bool {
		if ( empty( $current_step ) ) {
			return false;
		}

		$expected_post_id = absint( $current_step['post_id'] ?? 0 );
		if ( $expected_post_id > 0 ) {
			$actual_post_id = absint( $args['post_id'] ?? $args['id'] ?? $args['product_id'] ?? 0 );
			if ( $actual_post_id > 0 && $actual_post_id !== $expected_post_id ) {
				return false;
			}
		}

		if ( ! empty( $current_step['preview_required'] ) && ! empty( $current_step['apply_succeeded'] ) && 'completed' !== ( $current_step['status'] ?? '' ) ) {
			return false;
		}

		$expected_tool = sanitize_key( (string) ( $current_step['tool_name'] ?? '' ) );
		$tool_matches  = '' !== $expected_tool && $expected_tool === $tool_name;
		$step_targets = $this->extract_step_target_tokens( $current_step );
		$tool_targets = $this->extract_tool_call_target_tokens( $tool_name, $args );

		if ( ! empty( $step_targets ) ) {
			if ( empty( $tool_targets ) ) {
				if ( $tool_matches ) {
					return true;
				}

				$haystack = strtolower( $tool_name . ' ' . (string) wp_json_encode( $args ) );
				foreach ( $step_targets as $target ) {
					if ( false !== strpos( $haystack, $target ) ) {
						return true;
					}
				}

				return false;
			}

			foreach ( $step_targets as $expected_target ) {
				foreach ( $tool_targets as $actual_target ) {
					if (
						$expected_target === $actual_target
						|| false !== strpos( $actual_target, $expected_target )
						|| false !== strpos( $expected_target, $actual_target )
					) {
						return true;
					}
				}
			}

			return false;
		}

		if ( $tool_matches ) {
			return true;
		}

		if ( '' !== $expected_tool ) {
			$tool_haystack = strtolower( $tool_name . ' ' . (string) wp_json_encode( $args ) );
			if ( false !== strpos( $tool_haystack, str_replace( '_', ' ', $expected_tool ) ) ) {
				return true;
			}

			// Step has an explicit `tool_name` contract and we already tried
			// both strict and fuzzy matching — neither passed. Reject here
			// instead of falling through to keyword matching, which is a
			// legacy fallback for steps that never declared a `tool_name`.
			// Without this guard, e.g. a step with tool_name=get_products_on_sale
			// would accept a bulk_edit_products call because both share the
			// keyword "products" (and the bulk_edit args carry "sale"), which
			// is exactly the silent-mismatch bug that lets the model jump to
			// a pending step without updating the plan.
			return false;
		}

		$keywords = $this->extract_step_keyword_tokens( $current_step );
		if ( empty( $keywords ) ) {
			return true;
		}

		$haystack = strtolower( $tool_name . ' ' . (string) wp_json_encode( $args ) );
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $haystack, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	private function plan_step_labels_from_rows( array $steps ): array {
		$labels = array();

		foreach ( $steps as $step ) {
			if ( is_array( $step ) ) {
				$status = sanitize_key( (string) ( $step['status'] ?? '' ) );
				$text   = 'in_progress' === $status
					? sanitize_text_field( (string) ( $step['activeForm'] ?? $step['content'] ?? $step['text'] ?? '' ) )
					: sanitize_text_field( (string) ( $step['content'] ?? $step['text'] ?? $step['activeForm'] ?? '' ) );
			} else {
				$text = sanitize_text_field( (string) $step );
			}

			if ( '' !== $text ) {
				$labels[] = $text;
			}
		}

		return array_values( $labels );
	}

	private function update_plan_step_has_content( array $step ): bool {
		return '' !== sanitize_text_field( (string) (
			$step['content']
			?? $step['label']
			?? $step['title']
			?? $step['text']
			?? $step['description']
			?? ''
		) );
	}

	private function normalize_update_plan_args( array $args, ?PressArk_Checkpoint $checkpoint ): array {
		// Soft outer-name aliasing. The schema only declares `steps` / `updates` /
		// `changes`, but Claude Sonnet 4.6 occasionally emits `{tasks:[...]}` and
		// a few common siblings. Rename to canonical `steps` here so the patch
		// detection and validator downstream see a well-formed payload instead
		// of synth-erroring the round. Canonical `steps` always wins when both
		// are present; the alias is then dropped so it cannot resurface.
		$has_canonical_steps = isset( $args['steps'] ) && is_array( $args['steps'] ) && ! empty( $args['steps'] );
		// `plan` is intentionally excluded — it is a singular noun and could
		// collide with future args (e.g. a top-level plan-name string). The
		// other five names are unambiguously plural list aliases.
		foreach ( array( 'tasks', 'todos', 'items', 'plan_steps', 'checklist' ) as $alias ) {
			if ( ! array_key_exists( $alias, $args ) ) {
				continue;
			}
			if ( ! $has_canonical_steps && is_array( $args[ $alias ] ) && ! empty( $args[ $alias ] ) ) {
				$args['steps']       = $args[ $alias ];
				$has_canonical_steps = true;
			}
			unset( $args[ $alias ] );
		}

		$existing_steps = $checkpoint ? $checkpoint->get_plan_steps() : array();
		if ( empty( $existing_steps ) ) {
			return $args;
		}

		$patches = array();
		if ( isset( $args['updates'] ) && is_array( $args['updates'] ) ) {
			$patches = $this->filter_update_plan_patch_rows( (array) $args['updates'] );
		} elseif ( isset( $args['changes'] ) && is_array( $args['changes'] ) ) {
			$patches = $this->filter_update_plan_patch_rows( (array) $args['changes'] );
		} elseif ( isset( $args['steps'] ) && is_array( $args['steps'] ) && $this->update_plan_rows_are_patch_like( (array) $args['steps'] ) ) {
			$patches = $this->filter_update_plan_patch_rows( (array) $args['steps'] );
		}

		if ( empty( $patches ) ) {
			return $args;
		}

		// v5.8.16 (2026-05-14): accept patch-style update_plan rows when a
		// server-side plan already exists. Follow-up turns naturally emit
		// `{updates:[{step:3,status:"in_progress"}]}`; merging into the full
		// checkpoint ledger preserves the guard contract without a retry lap.
		$args['steps'] = $this->apply_update_plan_patches( $existing_steps, $patches );
		unset( $args['updates'], $args['changes'] );

		return $args;
	}

	private function filter_update_plan_patch_rows( array $rows ): array {
		$patches = array();
		foreach ( array_slice( $rows, 0, 12 ) as $row ) {
			if ( is_array( $row ) ) {
				$patches[] = $row;
			}
		}

		return $patches;
	}

	private function update_plan_rows_are_patch_like( array $rows ): bool {
		$has_patch_without_content = false;
		foreach ( array_slice( $rows, 0, 12 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$has_locator = isset( $row['step'] )
				|| isset( $row['step_number'] )
				|| isset( $row['number'] )
				|| isset( $row['index'] )
				|| isset( $row['id'] );
			if ( ! $has_locator ) {
				return false;
			}

			if ( ! $this->update_plan_step_has_content( $row ) ) {
				$has_patch_without_content = true;
			}
		}

		return $has_patch_without_content;
	}

	private function apply_update_plan_patches( array $existing_steps, array $patches ): array {
		$merged = array_values( array_filter( $existing_steps, 'is_array' ) );
		if ( empty( $merged ) ) {
			return $existing_steps;
		}

		$applied = false;
		foreach ( $patches as $patch ) {
			if ( ! is_array( $patch ) ) {
				continue;
			}

			$index = $this->resolve_update_plan_patch_index( $patch, $merged );
			if ( $index < 0 || ! isset( $merged[ $index ] ) || ! is_array( $merged[ $index ] ) ) {
				continue;
			}

			$row = $merged[ $index ];

			$content = sanitize_text_field( (string) (
				$patch['content']
				?? $patch['label']
				?? $patch['title']
				?? $patch['text']
				?? $patch['description']
				?? ''
			) );
			if ( '' !== $content ) {
				$row['content'] = $content;
			}

			$active_form = sanitize_text_field( (string) (
				$patch['activeForm']
				?? $patch['active_form']
				?? $patch['active']
				?? ''
			) );
			if ( '' !== $active_form ) {
				$row['activeForm'] = $active_form;
				if ( empty( $row['content'] ) ) {
					$row['content'] = $active_form;
				}
			}

			if ( isset( $patch['status'] ) ) {
				$row['status'] = sanitize_key( (string) $patch['status'] );
			}
			if ( isset( $patch['post_id'] ) ) {
				$row['post_id'] = absint( $patch['post_id'] );
			}
			$tool_name = sanitize_key( (string) ( $patch['tool_name'] ?? $patch['toolName'] ?? $patch['tool'] ?? '' ) );
			if ( '' !== $tool_name ) {
				$row['tool_name'] = $tool_name;
			}
			foreach ( array( 'preview_required', 'apply_succeeded' ) as $bool_key ) {
				if ( array_key_exists( $bool_key, $patch ) ) {
					$row[ $bool_key ] = ! empty( $patch[ $bool_key ] );
				}
			}
			foreach ( array( 'applied_tool_name', 'updated_at', 'kind', 'group' ) as $text_key ) {
				if ( array_key_exists( $text_key, $patch ) ) {
					$row[ $text_key ] = sanitize_text_field( (string) $patch[ $text_key ] );
				}
			}
			if ( isset( $patch['depends_on'] ) && is_array( $patch['depends_on'] ) ) {
				$row['depends_on'] = array_values( array_filter( array_map( 'sanitize_key', (array) $patch['depends_on'] ) ) );
			}
			if ( isset( $patch['metadata'] ) && is_array( $patch['metadata'] ) ) {
				$row['metadata'] = (array) $patch['metadata'];
			}

			$merged[ $index ] = $row;
			$applied          = true;
		}

		return $applied ? $merged : array();
	}

	private function resolve_update_plan_patch_index( array $patch, array $existing_steps ): int {
		foreach ( array( 'step', 'step_number', 'number' ) as $key ) {
			if ( isset( $patch[ $key ] ) ) {
				$index = absint( $patch[ $key ] ) - 1;
				if ( $index >= 0 && isset( $existing_steps[ $index ] ) ) {
					return $index;
				}
			}
		}

		if ( isset( $patch['index'] ) ) {
			$raw_index = absint( $patch['index'] );
			if ( isset( $existing_steps[ $raw_index ] ) ) {
				return $raw_index;
			}
			$one_based = $raw_index - 1;
			if ( $one_based >= 0 && isset( $existing_steps[ $one_based ] ) ) {
				return $one_based;
			}
		}

		$id = sanitize_key( (string) ( $patch['id'] ?? '' ) );
		if ( '' !== $id ) {
			foreach ( $existing_steps as $index => $step ) {
				if ( is_array( $step ) && $id === sanitize_key( (string) ( $step['id'] ?? '' ) ) ) {
					return (int) $index;
				}
			}
		}

		return -1;
	}

	private function normalize_update_plan_steps( array $steps ): array {
		$normalized = array();
		$used_ids   = array();

		foreach ( array_slice( $steps, 0, 12 ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Models routinely emit `label`/`title`/`text`/`description` instead of `content`
			// (the schema's required name). Treat the first non-empty alias as
			// content so a single missing field doesn't waste a whole round.
			$content = sanitize_text_field( (string) (
				$step['content']
				?? $step['label']
				?? $step['title']
				?? $step['text']
				?? $step['description']
				?? ''
			) );
			if ( '' === $content ) {
				continue;
			}

			$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			if ( 'done' === $status || 'verified' === $status ) {
				$status = 'completed';
			}
			// v5.8.6 (2026-05-13, post-iter-41): the Plan Stall prompt tells
			// the model to mark failed diagnostic steps blocked; preserving it
			// lets the chain advance instead of retrying the failed speed tool.
			if ( ! in_array( $status, array( 'pending', 'blocked', 'in_progress', 'completed' ), true ) ) {
				$status = 'pending';
			}

			$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
			$step_id   = $this->dedupe_update_plan_step_id(
				sanitize_key( (string) ( $step['id'] ?? '' ) ),
				$tool_name,
				$index,
				$used_ids
			);
			$depends_on = array();
			foreach ( (array) ( $step['depends_on'] ?? array() ) as $dependency ) {
				$dependency = sanitize_key( (string) $dependency );
				if ( '' !== $dependency ) {
					$depends_on[] = $dependency;
				}
			}

			$normalized[] = array(
				'id'               => $step_id,
				'content'          => $content,
				'activeForm'       => sanitize_text_field( (string) ( $step['activeForm'] ?? $content ) ),
				'status'           => $status,
				'post_id'          => absint( $step['post_id'] ?? 0 ),
				'tool_name'        => $tool_name,
				'preview_required' => ! empty( $step['preview_required'] ),
				'apply_succeeded'  => ! empty( $step['apply_succeeded'] ),
				'applied_tool_name'=> sanitize_key( (string) ( $step['applied_tool_name'] ?? '' ) ),
				'updated_at'       => sanitize_text_field( (string) ( $step['updated_at'] ?? '' ) ),
				'kind'             => sanitize_key( (string) ( $step['kind'] ?? '' ) ),
				'group'            => sanitize_key( (string) ( $step['group'] ?? '' ) ),
				'depends_on'       => array_values( array_unique( $depends_on ) ),
				'metadata'         => is_array( $step['metadata'] ?? null ) ? (array) $step['metadata'] : array(),
			);
		}

		return $normalized;
	}

	private function dedupe_update_plan_step_id( string $id, string $tool_name, int $index, array &$used_ids ): string {
		$base = sanitize_key( $id );
		if ( '' === $base ) {
			$base = '' !== $tool_name ? $tool_name . '_' . ( $index + 1 ) : 'step_' . ( $index + 1 );
		}

		$step_id = $base;
		$suffix  = 2;
		while ( isset( $used_ids[ $step_id ] ) ) {
			$step_id = $base . '_' . $suffix;
			$suffix++;
		}

		$used_ids[ $step_id ] = true;
		return $step_id;
	}

	private function find_matching_plan_step( array $candidate, array $existing_steps ): array {
		$candidate_content = sanitize_text_field( (string) ( $candidate['content'] ?? '' ) );
		$candidate_tool    = sanitize_key( (string) ( $candidate['tool_name'] ?? '' ) );
		$candidate_post_id = absint( $candidate['post_id'] ?? 0 );

		foreach ( $existing_steps as $existing ) {
			if ( ! is_array( $existing ) ) {
				continue;
			}

			$existing_content = sanitize_text_field( (string) ( $existing['content'] ?? '' ) );
			if ( '' !== $candidate_content && $existing_content !== $candidate_content ) {
				continue;
			}

			$existing_tool = sanitize_key( (string) ( $existing['tool_name'] ?? '' ) );
			if ( '' !== $candidate_tool && '' !== $existing_tool && $candidate_tool !== $existing_tool ) {
				continue;
			}

			$existing_post_id = absint( $existing['post_id'] ?? 0 );
			if ( $candidate_post_id > 0 && $existing_post_id > 0 && $candidate_post_id !== $existing_post_id ) {
				continue;
			}

			return $existing;
		}

		return array();
	}

	private function plan_step_requires_apply_success( array $step ): bool {
		if ( ! empty( $step['preview_required'] ) ) {
			return true;
		}

		$kind = sanitize_key( (string) ( $step['kind'] ?? '' ) );
		if ( in_array( $kind, array( 'preview', 'confirm', 'write' ), true ) ) {
			return true;
		}

		$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
		if ( '' === $tool_name || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return false;
		}

		return in_array( PressArk_Operation_Registry::classify( $tool_name ), array( 'preview', 'confirm' ), true );
	}

	private function plan_step_allows_parallel_in_progress( array $step ): bool {
		$kind = sanitize_key( (string) ( $step['kind'] ?? '' ) );
		if ( in_array( $kind, array( 'read', 'analyze', 'verify' ), true ) ) {
			return true;
		}

		$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
		if ( '' === $tool_name || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return false;
		}

		return 'read' === PressArk_Operation_Registry::classify( $tool_name );
	}

	/**
	 * Bump a per-day counter when validate_update_plan_tool_call rejects a
	 * plan submission. Used to spot LLM drift cheaply — no admin UI, no new
	 * table, autoload=false so it stays off the hot path. Read via
	 * `wp option list --search='pressark_plan_validation_failures_*'`.
	 *
	 * @param string $reason Short snake_case key describing the failure mode.
	 */
	private function record_plan_validation_failure( string $reason ): void {
		$reason = sanitize_key( $reason );
		if ( '' === $reason ) {
			return;
		}

		$key            = 'pressark_plan_validation_failures_' . gmdate( 'Y-m-d' );
		$counts         = (array) get_option( $key, array() );
		$counts[ $reason ] = ( (int) ( $counts[ $reason ] ?? 0 ) ) + 1;
		$counts['_total'] = ( (int) ( $counts['_total'] ?? 0 ) ) + 1;
		update_option( $key, $counts, false );
	}

	// TODO(Fix 9 follow-up): also call record_plan_validation_failure() from
	// normalize_update_plan_args() with reason='outer_alias_rename' when the
	// `tasks`/`todos`/`items`/`plan_steps`/`checklist` alias loop fires. Not
	// a rejection but a drift signal worth knowing about.

	private function validate_update_plan_tool_call( array $args, ?PressArk_Checkpoint $checkpoint ): array {
		$args      = $this->normalize_update_plan_args( $args, $checkpoint );
		$raw_steps = (array) ( $args['steps'] ?? array() );
		foreach ( array_slice( $raw_steps, 0, 12 ) as $step_index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			// Only `content` is hard-required: the normalizer fills `activeForm`
			// from `content` and accepts `label`/`title`/`text`/`description` as aliases for
			// `content`. Reject only when none of those carry a value.
			$has_content = $this->update_plan_step_has_content( $step );
			if ( ! $has_content ) {
				$received_keys = implode( ', ', array_map( 'sanitize_key', array_keys( $step ) ) );
				$this->record_plan_validation_failure( 'missing_content' );
				return array(
					'valid'   => false,
					'message' => sprintf(
						'Plan step #%d is missing a "content" field (or one of its aliases: label, title, text, description). Received keys: [%s]. Each step also accepts an "activeForm" (present-continuous rendering); if omitted, content is reused.',
						(int) $step_index + 1,
						'' !== $received_keys ? $received_keys : 'none'
					),
				);
			}
		}

		$steps = $this->normalize_update_plan_steps( $raw_steps );
		if ( empty( $steps ) ) {
			$this->record_plan_validation_failure( 'empty_steps' );
			return array(
				'valid'   => false,
				'message' => 'update_plan requires a non-empty ordered steps array.',
			);
		}

		$remaining_indices = array();
		$active_indices    = array();
		$active_read_indices = array();
		foreach ( $steps as $index => $step ) {
			if ( ! in_array( (string) ( $step['status'] ?? '' ), array( 'completed', 'blocked' ), true ) ) {
				$remaining_indices[] = $index;
			}
			if ( 'in_progress' === ( $step['status'] ?? '' ) ) {
				$active_indices[] = $index;
				if ( $this->plan_step_allows_parallel_in_progress( $step ) ) {
					$active_read_indices[] = $index;
				}
			}
		}

		$existing_steps = $checkpoint ? $checkpoint->get_plan_steps() : array();

		if ( $this->requires_plan( $this->resolve_effective_request_message( $checkpoint ), $checkpoint ) && ! empty( $remaining_indices ) ) {
			$active_count_is_valid = 1 === count( $active_indices )
				|| ( count( $active_indices ) > 1 && count( $active_indices ) === count( $active_read_indices ) );
			if ( empty( $existing_steps ) ) {
				if ( ! $active_count_is_valid ) {
					$this->record_plan_validation_failure( 'multiple_in_progress' );
					return array(
						'valid'   => false,
						'message' => 'For multi-step tasks, update_plan must keep exactly one write/confirm step in_progress at a time. Parallel in_progress steps are only allowed for read-only diagnostic work.',
					);
				}
			} elseif ( ! $active_count_is_valid ) {
				$this->record_plan_validation_failure( 'multiple_in_progress' );
				return array(
					'valid'   => false,
					'message' => 'For multi-step tasks, update_plan must keep exactly one write/confirm step in_progress at a time. Parallel in_progress steps are only allowed for read-only diagnostic work.',
				);
			}
		}

		if ( 1 === count( $active_indices ) && ! empty( $remaining_indices ) && $active_indices[0] !== $remaining_indices[0] ) {
			$this->record_plan_validation_failure( 'out_of_order_active' );
			return array(
				'valid'   => false,
				'message' => 'Do not start a later step while an earlier unfinished step still exists. Move the earliest unfinished step to in_progress first.',
			);
		}

		foreach ( $steps as $step ) {
			if ( 'completed' !== ( $step['status'] ?? '' ) || ! $this->plan_step_requires_apply_success( $step ) ) {
				continue;
			}

			$existing = $this->find_matching_plan_step( $step, $existing_steps );
			if ( empty( $existing['apply_succeeded'] ) ) {
				$this->record_plan_validation_failure( 'completed_without_apply' );
				return array(
					'valid'   => false,
					'message' => 'Write or confirm steps can only be marked completed after the matching apply step actually succeeded.',
				);
			}
		}

		foreach ( $steps as $step ) {
			if ( 'completed' === ( $step['status'] ?? '' ) ) {
				continue;
			}

			if ( 'edit_content' !== sanitize_key( (string) ( $step['tool_name'] ?? '' ) ) ) {
				continue;
			}

			$post_id = absint( $step['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}

			if ( ! empty( get_post_meta( $post_id, '_elementor_data', true ) ) ) {
				$this->record_plan_validation_failure( 'elementor_edit' );
				return array(
					'valid'   => false,
					'message' => sprintf(
						'Step targeting post #%d uses Elementor — replace tool_name "edit_content" with an elementor_* tool (e.g. elementor_update_widget) for this step. edit_content corrupts builder pages.',
						$post_id
					),
				);
			}
		}

		if ( class_exists( 'PressArk_Operation_Registry' ) ) {
			$unknown_names = array();
			foreach ( $steps as $step ) {
				$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
				if ( '' === $tool_name ) {
					continue;
				}
				$resolved = PressArk_Operation_Registry::resolve_alias( $tool_name );
				if ( ! PressArk_Operation_Registry::exists( $resolved ) ) {
					$unknown_names[ $tool_name ] = true;
				}
			}
			if ( ! empty( $unknown_names ) ) {
				$loaded_names = array();
				if ( class_exists( 'PressArk_Operation_Registry' ) ) {
					foreach ( (array) $this->loaded_groups as $group ) {
						foreach ( (array) PressArk_Operation_Registry::tool_names_for_group( (string) $group ) as $name ) {
							$loaded_names[ $name ] = true;
						}
					}
				}
				$loaded_names = array_keys( $loaded_names );
				sort( $loaded_names );
				$this->record_plan_validation_failure( 'unknown_tool' );
				return array(
					'valid'   => false,
					'message' => sprintf(
						'Unknown tool_name(s) in plan steps: %s. Use an exact name from the currently loaded tools: %s. If the tool you need is not listed, call discover_tools or load_tools first, then re-submit the plan.',
						implode( ', ', array_keys( $unknown_names ) ),
						implode( ', ', array_slice( $loaded_names, 0, 40 ) )
					),
				);
			}
		}

		$args['steps'] = $steps;

		return array(
			'valid'  => true,
			'params' => $args,
		);
	}

	private function tool_call_matches_active_plan_step( array $step, string $tool_name, array $args ): bool {
		$expected_tool = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
		if ( '' !== $expected_tool && $expected_tool !== $tool_name ) {
			return false;
		}

		$expected_post_id = absint( $step['post_id'] ?? 0 );
		if ( $expected_post_id > 0 ) {
			$actual_post_id = absint( $args['post_id'] ?? $args['id'] ?? $args['product_id'] ?? 0 );
			if ( $actual_post_id > 0 && $actual_post_id !== $expected_post_id ) {
				return false;
			}
		}

		return true;
	}

	private function guard_tool_call_against_plan( string $user_message, string $tool_name, array $args, ?PressArk_Checkpoint $checkpoint ): ?string {
		if ( 'update_plan' === $tool_name ) {
			return null;
		}

		if ( ! $this->requires_plan( $user_message, $checkpoint ) ) {
			return null;
		}

		if ( ! $checkpoint ) {
			return null;
		}

		// Read-only and meta tools are never gated by the plan-guard,
		// regardless of plan state. Reads are grounding actions — the model
		// must be free to branch, re-read, or re-verify at any point in the
		// chain without first pivoting the plan. Meta tools (discover_tools /
		// load_tools / load_tool_group) are session-management, not plan
		// steps. Only write tools (preview/confirm capability) go through
		// the step-matching guard below, because writes are the ones that
		// must be mirrored on the plan ledger so preview/keep can mark the
		// right step completed without triggering a duplicate write.
		$contract = $this->resolve_tool_contract( $tool_name, $args );
		if (
			in_array( $tool_name, array( 'discover_tools', 'load_tools', 'load_tool_group' ), true )
			|| ! empty( $contract['readonly'] )
		) {
			return null;
		}

		if ( ! $this->has_active_plan_step( $checkpoint ) ) {
			return 'Re-emit BOTH `update_plan` and this tool in the SAME assistant response as parallel tool_calls (not sequential rounds). Your `update_plan` must set exactly ONE step to `in_progress` whose tool matches the one you are calling — otherwise this write is rejected again, and without that plan step the harness cannot track the write through preview/keep, risking a duplicate write after the user confirms.';
		}

		$current_step = $this->get_in_progress_step( $checkpoint );
		if ( empty( $current_step ) ) {
			return 'Re-emit BOTH `update_plan` and this tool in the SAME assistant response as parallel tool_calls (not sequential rounds). Your `update_plan` must set exactly ONE step to `in_progress` whose tool matches the one you are calling — otherwise this write is rejected again, and without that plan step the harness cannot track the write through preview/keep, risking a duplicate write after the user confirms.';
		}

		if ( ! $this->tool_matches_current_step( $tool_name, $args, $current_step ) ) {
			$current_label = sanitize_text_field( (string) ( $current_step['content'] ?? 'the current step' ) );
			return 'Step "' . $current_label . '" is still in_progress and the tool you emitted does not match it. Recover by emitting update_plan (advance the plan to the correct step) AND your intended tool together in the SAME response as parallel tool_calls — NOT in two separate rounds. Solo update_plan or solo write will waste another round.';
		}

		return null;
	}

	private function build_plan_guard_result( string $tool_use_id, string $error, string $message ): array {
		return array(
			'tool_use_id' => $tool_use_id,
			'result'      => array(
				'success' => false,
				'error'   => sanitize_key( $error ),
				'message' => sanitize_text_field( $message ),
			),
		);
	}

	private function current_plan_progress_signature( ?PressArk_Checkpoint $checkpoint ): string {
		if ( ! $checkpoint ) {
			return '';
		}

		$steps = $checkpoint->get_plan_steps();
		if ( empty( $steps ) ) {
			return '';
		}

		$completed = count(
			array_filter(
				$steps,
				static fn( array $step ): bool => 'completed' === ( $step['status'] ?? '' )
			)
		);
		$active_index = $checkpoint->get_active_plan_step_index();
		$active_step  = $checkpoint->get_active_plan_step();
		$active_text  = sanitize_text_field( (string) ( $active_step['content'] ?? '' ) );
		$active_status = sanitize_key( (string) ( $active_step['status'] ?? '' ) );

		return sanitize_text_field(
			sprintf(
				'%d|%d|%s|%s',
				$completed,
				$active_index,
				$active_status,
				$active_text
			)
		);
	}

	private function refresh_plan_stall_state( ?PressArk_Checkpoint $checkpoint ): void {
		if ( ! $checkpoint || ! $this->task_requires_externalized_plan( $checkpoint ) ) {
			$this->last_plan_progress_signature = '';
			$this->plan_stall_rounds            = 0;
			$this->plan_stall_message           = '';
			return;
		}

		$signature = $this->current_plan_progress_signature( $checkpoint );
		if ( '' === $signature ) {
			$this->last_plan_progress_signature = '';
			$this->plan_stall_rounds            = 0;
			$this->plan_stall_message           = '';
			return;
		}

		if ( $signature === $this->last_plan_progress_signature ) {
			$this->plan_stall_rounds++;
		} else {
			$this->plan_stall_rounds  = 0;
			$this->plan_stall_message = '';
		}

		$this->last_plan_progress_signature = $signature;

		if ( $this->plan_stall_rounds >= 2 ) {
			$active = $checkpoint->get_active_plan_step();
			$label  = sanitize_text_field( (string) ( $active['activeForm'] ?? $active['content'] ?? 'the current step' ) );
			$this->plan_stall_message = 'Plan stalled on step "' . $label . '" — update_plan or mark blocked before taking more actions.';
		}
	}

	private function should_inject_plan_summary_prompt( ?PressArk_Checkpoint $checkpoint ): bool {
		if ( ! $checkpoint ) {
			return false;
		}

		foreach ( $checkpoint->get_plan_steps() as $step ) {
			if ( is_array( $step ) && 'completed' !== ( $step['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function build_plan_prompt_summary_block( PressArk_Checkpoint $checkpoint ): string {
		$rows = array_slice( $checkpoint->get_plan_steps(), 0, 6 );
		if ( empty( $rows ) ) {
			return '';
		}

		$parts = array();
		foreach ( $rows as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $step['activeForm'] ?? $step['content'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}

			$status       = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			$status_label = 'in_progress' === $status ? 'IN PROGRESS' : str_replace( '_', ' ', $status );
			$parts[]      = sprintf( '%d) %s [%s]', $index + 1, $label, $status_label );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return 'Current plan: ' . implode( ', ', $parts );
	}

	private function normalize_and_guard_tool_calls(
		array $tool_calls,
		array &$messages,
		string $user_message,
		string $provider,
		?PressArk_Checkpoint $checkpoint,
		int $round
	): array {
		if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return array(
				'tool_calls' => $tool_calls,
			);
		}

		$normalized_tool_calls = array();
		$synthetic_results     = array();

		// Pre-scan: if the batch contains a valid update_plan call, project
		// its step transitions onto a cloned checkpoint so sibling tool calls
		// in the same batch are guarded against the post-transition plan
		// rather than the stale pre-batch plan. Without this, the natural
		// parallel pattern `update_plan(mark step N completed, N+1 in_progress)
		// + call_tool_for_step_N+1` is rejected as a plan_step_guard violation
		// because the checkpoint still shows step N as in_progress when the
		// sibling guard runs.
		$guard_checkpoint = $this->project_plan_guard_checkpoint( $tool_calls, $checkpoint );

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			if ( '' === $tool_name ) {
				continue;
			}

			$canonical = PressArk_Operation_Registry::resolve_alias( $tool_name );
			$args      = is_array( $tool_call['arguments'] ?? null )
				? $tool_call['arguments']
				: ( is_array( $tool_call['params'] ?? null ) ? $tool_call['params'] : array() );

			$validation = PressArk_Operation_Registry::validate_input( $canonical, $args );
			if ( ! ( $validation['valid'] ?? true ) ) {
				$synthetic_results[] = array(
					'tool_use_id' => $tool_call['id'] ?? '',
					'result'      => $this->build_invalid_tool_input_result(
						$canonical !== '' ? $canonical : $tool_name,
						(string) ( $validation['message'] ?? '' )
					),
				);
				continue;
			}

			if ( isset( $validation['params'] ) && is_array( $validation['params'] ) ) {
				$args = $validation['params'];
			}

			if ( 'update_plan' === $canonical ) {
				// Validate against the real checkpoint — the update_plan
				// validation inspects existing completions and preview state,
				// which must reflect the actual persisted plan.
				$plan_validation = $this->validate_update_plan_tool_call( $args, $checkpoint );
				if ( ! ( $plan_validation['valid'] ?? false ) ) {
					$synthetic_results[] = $this->build_plan_guard_result(
						(string) ( $tool_call['id'] ?? '' ),
						'plan_update_rejected',
						(string) ( $plan_validation['message'] ?? 'The submitted plan update was rejected.' )
					);
					continue;
				}

				if ( isset( $plan_validation['params'] ) && is_array( $plan_validation['params'] ) ) {
					$args = $plan_validation['params'];
				}
			} else {
				// Sibling tool calls are guarded against the projected plan
				// state (see $guard_checkpoint above) so a parallel
				// `update_plan + next_step_tool` batch can succeed atomically.
				$plan_guard_message = $this->guard_tool_call_against_plan( $user_message, $canonical, $args, $guard_checkpoint );
				if ( null !== $plan_guard_message ) {
					$synthetic_results[] = $this->build_plan_guard_result(
						(string) ( $tool_call['id'] ?? '' ),
						'plan_step_guard',
						$plan_guard_message
					);
					continue;
				}
			}

			$tool_call['name']      = $canonical;
			$tool_call['arguments'] = $args;
			if ( isset( $tool_call['type'] ) ) {
				$tool_call['type'] = $canonical;
			}
			unset( $tool_call['params'] );

			$normalized_tool_calls[] = $tool_call;
		}

		if ( empty( $synthetic_results ) ) {
			return array(
				'tool_calls' => $normalized_tool_calls,
			);
		}

		$this->append_tool_results( $messages, $synthetic_results, $provider );
		$this->sync_replay_snapshot( $checkpoint, $messages );
		$this->record_activity_event(
			'tool.input_validation',
			'tool_input_invalid',
			'retrying',
			'agent',
			'Blocked malformed tool input before permission, preview, or execution handling.',
			array(
				'round'         => $round,
				'invalid_calls' => count( $synthetic_results ),
			)
		);

		return array(
			'retry'      => true,
			'tool_calls' => array(),
		);
	}

	/**
	 * Return a checkpoint view that reflects the plan transitions any valid
	 * update_plan call in this batch is about to apply. Used by
	 * guard_tool_call_against_plan so sibling calls in the same batch are
	 * evaluated against the post-transition plan instead of the stale
	 * pre-batch plan. Returns the original checkpoint unchanged when the
	 * batch has no update_plan, or when update_plan fails validation (the
	 * main loop will synth-error the bad update_plan and legitimately
	 * reject siblings against the old plan).
	 */
	private function project_plan_guard_checkpoint( array $tool_calls, ?PressArk_Checkpoint $checkpoint ): ?PressArk_Checkpoint {
		if ( ! $checkpoint || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return $checkpoint;
		}

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$peek_name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			if ( '' === $peek_name ) {
				continue;
			}
			if ( 'update_plan' !== PressArk_Operation_Registry::resolve_alias( $peek_name ) ) {
				continue;
			}

			$peek_args = is_array( $tool_call['arguments'] ?? null )
				? $tool_call['arguments']
				: ( is_array( $tool_call['params'] ?? null ) ? $tool_call['params'] : array() );

			$peek_input = PressArk_Operation_Registry::validate_input( 'update_plan', $peek_args );
			if ( ! ( $peek_input['valid'] ?? true ) ) {
				continue;
			}
			if ( isset( $peek_input['params'] ) && is_array( $peek_input['params'] ) ) {
				$peek_args = $peek_input['params'];
			}

			$peek_plan = $this->validate_update_plan_tool_call( $peek_args, $checkpoint );
			if ( ! ( $peek_plan['valid'] ?? false ) ) {
				continue;
			}

			$peek_steps = isset( $peek_plan['params']['steps'] ) && is_array( $peek_plan['params']['steps'] )
				? $peek_plan['params']['steps']
				: (array) ( $peek_args['steps'] ?? array() );
			if ( empty( $peek_steps ) ) {
				continue;
			}

			$projected = clone $checkpoint;
			$projected->set_plan_steps( $peek_steps );
			return $projected;
		}

		return $checkpoint;
	}

	private function build_wc_price_intent_retry_message( string $mode ): string {
		return match ( $mode ) {
			'sale' => __( 'This request explicitly asks for a sale. For a percentage-off sale (e.g. "apply a 10% sale"), use sale_adjust_pct (negative, e.g. -10). For a specific sale amount, use sale_price. Preserve regular_price unless the user also asked to change the base price.', 'pressark' ),
			'regular' => __( 'This request changes the current or regular price. Use regular_price, price_delta, or price_adjust_pct instead of sale_price / sale_adjust_pct.', 'pressark' ),
			'clear_sale' => __( 'This request removes a sale. Use clear_sale=true and preserve the regular_price.', 'pressark' ),
			default => __( 'This price request needs an explicit WooCommerce price field that matches the user intent.', 'pressark' ),
		};
	}

	private function resolve_effective_request_message( ?PressArk_Checkpoint $checkpoint = null ): string {
		$request_message = trim( $this->request_message );
		if ( '' === $request_message && $checkpoint && method_exists( $checkpoint, 'get_goal' ) ) {
			$request_message = trim( (string) $checkpoint->get_goal() );
		}

		return $request_message;
	}

	private static function wc_price_field_is_supplied( string $field, $value ): bool {
		if ( 'clear_sale' === $field ) {
			return ! empty( $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return true;
		}

		if ( null === $value ) {
			return false;
		}

		return '' !== trim( (string) $value );
	}

	private static function collect_supplied_wc_price_fields( array $payload ): array {
		$fields = array();
		foreach ( array( 'regular_price', 'sale_price', 'sale_adjust_pct', 'clear_sale', 'sale_from', 'sale_to', 'price_delta', 'price_adjust_pct' ) as $field ) {
			if ( array_key_exists( $field, $payload ) && self::wc_price_field_is_supplied( $field, $payload[ $field ] ) ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	private function collect_wc_price_fields_from_tool_call( string $tool_name, array $args ): array {
		$tool_name = sanitize_key( $tool_name );
		$fields    = array();

		switch ( $tool_name ) {
			case 'edit_product':
				$fields = self::collect_supplied_wc_price_fields( (array) ( $args['changes'] ?? array() ) );
				break;

			case 'bulk_edit_products':
				$fields = self::collect_supplied_wc_price_fields( (array) ( $args['changes'] ?? array() ) );
				foreach ( (array) ( $args['products'] ?? array() ) as $product_row ) {
					if ( is_array( $product_row ) ) {
						$fields = array_merge( $fields, self::collect_supplied_wc_price_fields( (array) ( $product_row['changes'] ?? array() ) ) );
					}
				}
				break;

			case 'edit_variation':
			case 'create_product':
			case 'create_variation':
				$fields = self::collect_supplied_wc_price_fields( $args );
				if ( empty( $fields ) && ! empty( $args['changes'] ) && is_array( $args['changes'] ) ) {
					$fields = self::collect_supplied_wc_price_fields( (array) $args['changes'] );
				}
				break;

			case 'bulk_edit_variations':
				$fields = self::collect_supplied_wc_price_fields( (array) ( $args['changes'] ?? array() ) );
				if ( empty( $fields ) ) {
					$fields = self::collect_supplied_wc_price_fields( $args );
				}
				break;
		}

		return array_values( array_unique( $fields ) );
	}

	private function maybe_guard_wc_price_write_intent(
		array $write_calls,
		?PressArk_Checkpoint $checkpoint,
		array &$messages,
		string $provider,
		int $round,
		array $tool_set,
		array $initial_groups
	): array {
		// Intent must be inferred from BOTH the current message and the
		// checkpoint goal. After a preview-apply, the agent loop's current
		// message becomes a synthetic "[Continue] Completed: ..." string
		// that no longer contains the user's original "10% sale" wording —
		// so price intent gets lost and this guard would silently skip,
		// letting the model write to regular_price when the user asked for
		// a sale. Combining both inputs preserves intent across rounds.
		$current_message = $this->resolve_effective_request_message( $checkpoint );
		$goal_message    = $checkpoint && method_exists( $checkpoint, 'get_goal' )
			? trim( (string) $checkpoint->get_goal() )
			: '';
		$combined_for_intent = trim( $current_message . ' ' . $goal_message );
		$price_mode          = self::infer_wc_price_write_mode( $combined_for_intent );
		if ( 'none' === $price_mode ) {
			return array();
		}
		// Use the message that surfaced the intent for downstream messaging.
		$request_message = '' !== $current_message ? $current_message : $goal_message;

		$price_tools       = array( 'edit_product', 'bulk_edit_products', 'edit_variation', 'bulk_edit_variations', 'create_product', 'create_variation' );
		$relevant_calls    = array();
		$offending_calls   = array();
		$regular_drivers   = array( 'regular_price', 'price_delta', 'price_adjust_pct' );

		foreach ( $write_calls as $write_call ) {
			$tool_name = sanitize_key( (string) ( $write_call['name'] ?? '' ) );
			if ( ! in_array( $tool_name, $price_tools, true ) ) {
				continue;
			}

			$relevant_calls[] = $write_call;
			$fields           = $this->collect_wc_price_fields_from_tool_call( $tool_name, (array) ( $write_call['arguments'] ?? array() ) );

			if ( 'ambiguous' === $price_mode ) {
				continue;
			}

			$has_sale_price       = in_array( 'sale_price', $fields, true );
			$has_sale_adjust_pct  = in_array( 'sale_adjust_pct', $fields, true );
			$has_clear_sale       = in_array( 'clear_sale', $fields, true );
			$has_regular_driver   = ! empty( array_intersect( $regular_drivers, $fields ) );

			// "sale" intent is satisfied by either sale_price (absolute) or
			// sale_adjust_pct (canonical percentage-off). Before this, only
			// sale_price counted — which meant a model emitting the cleaner
			// sale_adjust_pct:-10 got rejected by the intent guard even though
			// it was the correct field for "apply a 10% sale".
			$violates_mode = match ( $price_mode ) {
				'sale' => ! $has_sale_price && ! $has_sale_adjust_pct,
				'regular' => ! $has_regular_driver,
				'clear_sale' => ! $has_clear_sale,
				default => false,
			};

			if ( $violates_mode ) {
				$offending_calls[] = $write_call;
			}
		}

		if ( empty( $relevant_calls ) ) {
			return array();
		}

		if ( 'ambiguous' === $price_mode ) {
			$clarification = self::build_plan_clarification_reply( $request_message, $this->task_type, $this->loaded_groups );
			$this->record_activity_event(
				'wc_price.intent_guard',
				'price_intent_clarification_required',
				'needs_input',
				'agent',
				'Ambiguous WooCommerce pricing wording was blocked until the user clarifies sale price versus regular price.',
				array(
					'round' => $round,
					'mode'  => 'ambiguous',
					'calls' => count( $relevant_calls ),
				)
			);

			return array(
				'result' => $this->build_result(
					array(
						'type'        => 'final_response',
						'status'      => 'needs_input',
						'exit_reason' => 'needs_input',
						'message'     => $clarification,
						'reply'       => $clarification,
						'can_execute' => false,
					),
					$tool_set,
					$initial_groups,
					$checkpoint
				),
			);
		}

		if ( empty( $offending_calls ) ) {
			return array();
		}

		$synthetic_results = array();
		foreach ( $offending_calls as $write_call ) {
			$synthetic_results[] = array(
				'tool_use_id' => $write_call['id'] ?? '',
				'result'      => array(
					'success' => false,
					'error'   => 'wc_price_intent_mismatch',
					'message' => $this->build_wc_price_intent_retry_message( $price_mode ),
				),
			);
		}

		$this->append_tool_results( $messages, $synthetic_results, $provider );
		$this->sync_replay_snapshot( $checkpoint, $messages );
		$this->record_activity_event(
			'wc_price.intent_guard',
			'wc_price_field_mismatch',
			'retrying',
			'agent',
			'Blocked a WooCommerce price write whose field selection did not match the user request intent.',
			array(
				'round'          => $round,
				'mode'           => $price_mode,
				'offending_calls'=> count( $offending_calls ),
			)
		);

		return array( 'retry' => true );
	}

	private function result_has_explicit_plan_payload( array $data, string $source_text = '' ): bool {
		self::ensure_plan_mode_loaded();

		if ( ! empty( $data['plan_artifact'] ) && is_array( $data['plan_artifact'] ) ) {
			return true;
		}

		foreach ( (array) ( $data['plan_steps'] ?? array() ) as $row ) {
			$text = is_array( $row )
				? (string) ( $row['text'] ?? '' )
				: (string) $row;
			if ( '' !== trim( $text ) ) {
				return true;
			}
		}

		$type = sanitize_key( (string) ( $data['type'] ?? '' ) );
		if ( 'plan_ready' === $type ) {
			return true;
		}

		$plan_text = sanitize_textarea_field( (string) ( $data['plan_markdown'] ?? '' ) );
		if ( '' === trim( $plan_text ) ) {
			$plan_text = sanitize_textarea_field( $source_text );
		}
		if ( '' === trim( $plan_text ) ) {
			$plan_text = sanitize_textarea_field( (string) ( $data['reply'] ?? $data['message'] ?? '' ) );
		}
		if ( '' === trim( $plan_text ) ) {
			return false;
		}

		return ! empty( PressArk_Plan_Mode::extract_steps( $plan_text ) );
	}

	private function maybe_materialize_soft_plan_from_grounding(
		PressArk_Checkpoint $checkpoint,
		string $message,
		array $conversation
	): void {
		if ( ! $this->is_soft_plan_mode() || $this->is_plan_mode() ) {
			return;
		}

		if ( ! $this->checkpoint_has_grounded_plan_context( $checkpoint ) ) {
			return;
		}

		// v5.8.15 (2026-05-14): read-only soft plans should not persist an executable artifact.
		// Follow-up turns such as "fix 3" need the read target, not a stale
		// "Analyze Seo" approved-plan artifact from the diagnostic round.
		if ( ! $this->soft_plan_should_materialize_artifact() ) {
			if ( method_exists( $checkpoint, 'clear_plan_state' ) ) {
				$checkpoint->clear_plan_state();
			}
			return;
		}

		$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		if ( empty( $artifact ) ) {
			$plan = $this->plan_with_ai( $message, $conversation );
			$this->cached_ai_plan = is_array( $plan ) ? $plan : array();
			$plan['task_type'] = $this->task_type ?: (string) ( $plan['task_type'] ?? 'edit' );
			$plan['groups']    = array_values(
				array_slice(
					array_unique(
						array_filter(
							array_merge(
								(array) ( $plan['groups'] ?? array() ),
								$this->loaded_groups
							)
						)
					),
					0,
					6
				)
			);
			$artifact = $this->build_plan_artifact( $checkpoint, $plan, $message, $conversation );
			if ( empty( $artifact ) ) {
				return;
			}

			$this->apply_plan_artifact_to_checkpoint( $checkpoint, $artifact, true );
			$this->record_activity_event(
				'plan.soft_grounded',
				'soft_plan_grounded_after_reads',
				'ready',
				'plan',
				'Generated the soft plan after grounding the request with read results.',
				array(
					'approval_level' => 'soft',
					'plan_id'        => (string) ( $artifact['plan_id'] ?? '' ),
					'version'        => (int) ( $artifact['version'] ?? 1 ),
				)
			);
		} elseif ( 'executing' !== $checkpoint->get_plan_phase() ) {
			$this->apply_plan_artifact_to_checkpoint( $checkpoint, $artifact, true );
		}

		if ( class_exists( 'PressArk_Plan_Artifact' ) ) {
			$artifact = PressArk_Plan_Artifact::ensure( $checkpoint->get_plan_artifact() );
			$this->active_plan_artifact = $artifact;
			$this->plan_steps           = array_values(
				array_filter(
					array_map(
						static fn( $row ) => sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
						PressArk_Plan_Artifact::to_plan_steps( $artifact )
					)
				)
			);
			$this->advance_task_graph( $checkpoint );
		}
	}

	private function soft_plan_should_materialize_artifact(): bool {
		if ( empty( $this->planning_decision ) ) {
			return true;
		}

		$reason_codes = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) ( $this->planning_decision['reason_codes'] ?? array() ) )
			)
		);

		return in_array( 'predicted_write', $reason_codes, true )
			|| in_array( 'commerce_critical_write', $reason_codes, true )
			|| in_array( 'multi_entity_write', $reason_codes, true )
			|| in_array( 'small_preview_protected_write', $reason_codes, true )
			|| ! empty( $this->planning_decision['approval_required'] );
	}

	private function normalize_result_for_mode( array $data, ?PressArk_Checkpoint $checkpoint = null ): array {
		if ( ! $this->is_plan_mode() ) {
			return $data;
		}

		$type = (string) ( $data['type'] ?? 'final_response' );
		if ( ! empty( $data['is_error'] ) || in_array( $type, array( 'preview', 'confirm_card' ), true ) ) {
			return $data;
		}

		self::ensure_plan_mode_loaded();

		$source_text = sanitize_textarea_field( (string) ( $data['reply'] ?? $data['plan_markdown'] ?? $data['message'] ?? '' ) );
		if ( ! empty( $data['empty_data_final_response'] ) && '' !== trim( $source_text ) ) {
			// v5.8.10 (2026-05-14, iter-46): accept text-final wraps after empty successful tool reads.
			$data['type']        = 'final_response';
			$data['exit_reason'] = 'empty_data_final_response';
			$data['reply']       = $source_text;
			$data['message']     = $source_text;
			$data['can_execute'] = false;
			$data['plan_card_obsolete'] = true;
			$data['plan_card_obsolete_reason'] = 'empty_data_final_response';
			if ( $checkpoint ) {
				if ( method_exists( $checkpoint, 'set_plan_phase' ) ) {
					$checkpoint->set_plan_phase( 'completed' );
				}
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'completed' );
				}
			}
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			return $data;
		}
		if ( $this->plan_response_requires_user_input( $source_text ) ) {
			if ( $this->should_promote_prose_approval_to_plan_ready( $source_text, $checkpoint ) ) {
				// v5.8.11 (2026-05-14): route risky prose approval questions into structured review.
				$data['prose_approval_promoted_to_plan_ready'] = true;
				if ( ! $this->result_has_explicit_plan_payload( $data, $source_text ) ) {
					$fallback_rows = $this->build_plan_fallback_rows();
					if ( ! empty( $fallback_rows ) ) {
						$data['plan_steps']    = $fallback_rows;
						$data['plan_markdown'] = $this->build_plan_markdown( $fallback_rows, $source_text );
					}
				}
				$this->record_activity_event(
					'plan.prose_approval_promoted',
					'approval_question_promoted_to_plan_ready',
					'waiting',
					'plan',
					'Promoted a risky prose approval question into the structured plan review surface.',
					array(
						'mode' => $this->is_plan_mode() ? 'hard_plan' : ( $this->is_soft_plan_mode() ? 'soft_plan' : 'none' ),
					)
				);
			} else {
				$data['type']        = 'final_response';
				$data['status']      = 'needs_input';
				$data['exit_reason'] = 'needs_input';
				$data['reply']       = $source_text;
				$data['message']     = $source_text;
				unset( $data['plan_markdown'], $data['plan_steps'] );
				return $data;
			}
		}

		// v5.8.2 (2026-05-13, iter-38): Sister branch to the user-input check.
		// When the model concluded "no action needed" in plain text, do NOT
		// coerce the result back into a plan_ready card from the round-1
		// artifact. Without this branch, the no-action exit at agent.php
		// L4282-L4306 returns final_response but normalize_result_for_mode
		// promotes it to plan_ready at L2758 because the checkpoint still
		// carries the speculative R1 plan_artifact, producing the stale
		// "Execute / Revise / Reject" UI card next to the model's
		// "no changes needed" text. Observed Chain G FAQ repro (2026-05-13).
		if (
			'' !== trim( $source_text )
			&& $this->plan_response_concludes_no_action( $source_text )
		) {
			$data['type']                  = 'final_response';
			$data['status']                = 'no_action';
			$data['exit_reason']           = 'plan_no_action';
			$data['reply']                 = $source_text;
			$data['message']               = $source_text;
			$data['can_execute']           = false;
			$data['plan_card_obsolete']    = true;
			$data['plan_card_obsolete_reason'] = 'model_concluded_no_action';
			if ( $checkpoint ) {
				if ( method_exists( $checkpoint, 'set_plan_phase' ) ) {
					$checkpoint->set_plan_phase( 'completed' );
				}
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'no_action' );
				}
			}
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			// Preserve $data['plan_artifact'] so JS can still display the
			// "what we considered" trail if the user expands history, but
			// the new can_execute=false + plan_card_obsolete=true tells the
			// renderer to suppress the actionable Plan card.
			return $data;
		}

		// v5.8.2 (2026-05-13, iter-38): Wrap-round bypass.
		// When the current round is a post-Keep wrap (latest user msg was a
		// [Continue] envelope), the model's text is a summary of work that
		// already happened — NOT a new plan proposal. Without this bypass,
		// the legacy path below sees the wrap text's numbered "1. ... 2. ..."
		// retrospective list, treats it as a plan, and coerces type back to
		// plan_ready — rendering a fresh "Execute" Plan card on top of a
		// completed action. Observed Chain B post-Keep (2026-05-13): page
		// was created successfully, R3 wrap was clean, harness then forced
		// R4 retrospective, normalize re-coerced to plan_ready.
		if ( $this->is_post_keep_wrap_round && '' !== trim( $source_text ) ) {
			$data['type']    = 'final_response';
			$data['reply']   = $source_text;
			$data['message'] = $source_text;
			$data['can_execute'] = false;
			// Tell the JS to clear any lingering R1 Plan card — the chain has
			// already executed, the original plan was consumed.
			$data['plan_card_obsolete'] = true;
			$data['plan_card_obsolete_reason'] = 'post_keep_wrap';
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			return $data;
		}

		// Skip the grounding-context exploring fallback when the early-exit has
		// already declared plan_ready via $this->plan_just_proposed. In that path
		// update_plan has already stored real plan steps on the checkpoint; the
		// model may have parallel-emitted update_plan with a meta tool like
		// load_tools (legitimate R1 pattern) which does not populate read_state,
		// selected_target, or entities. Without this bypass the flow dead-ends
		// with type=final_response/status=exploring and no plan_ready card renders.
		$early_exit_plan_ready = 'plan_ready' === (string) ( $data['exit_reason'] ?? '' );
		if ( ! $early_exit_plan_ready && $checkpoint && $this->planning_requires_grounded_reads() && ! $this->checkpoint_has_grounded_plan_context( $checkpoint ) ) {
			$checkpoint->set_plan_phase( 'exploring' );
			if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
				$checkpoint->set_plan_status( 'exploring' );
			}

			// The grounding directive ("Use relevant read-only tools first...")
			// is a model-facing retry hint, not a user-facing reply. Many
			// terminal exit paths (round_limit, token_budget, wall-clock
			// timeout, spin_detected, parse failure, cancellation) provide
			// their own user-friendly message. Only fall back to the directive
			// when the agent truly has nothing to say AND nothing has been
			// extracted — otherwise PressArk leaks an internal hint as the
			// assistant reply.
			$has_terminal_message = ! empty( $data['hit_limit'] )
				|| ! empty( $data['cancelled'] )
				|| ! empty( $data['error'] )
				|| ! empty( trim( (string) ( $data['message'] ?? '' ) ) )
				|| ! empty( trim( (string) ( $data['reply'] ?? '' ) ) )
				|| in_array( (string) ( $data['exit_reason'] ?? '' ), array( 'spin_detected', 'token_budget', 'round_limit', 'max_request_icus_compacted', 'tool_call_parse_failure' ), true );

			if ( ! $has_terminal_message ) {
				$research_reply      = $this->build_grounding_retry_message();
				$data['reply']       = $research_reply;
				$data['message']     = $research_reply;
				$data['exit_reason'] = 'needs_research';
			}

			$data['type']        = 'final_response';
			$data['status']      = 'exploring';
			$data['can_execute'] = false;
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['plan_artifact'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			return $data;
		}

		$fallback_steps   = $this->build_plan_fallback_rows();
		$artifact         = $checkpoint ? $this->plan_artifact_from_checkpoint( $checkpoint ) : array();
		if ( class_exists( 'PressArk_Plan_Artifact' ) && ! empty( $data['plan_artifact'] ) && is_array( $data['plan_artifact'] ) ) {
			$artifact = PressArk_Plan_Artifact::ensure( $data['plan_artifact'] );
		}

		$plan_markdown   = ! empty( $artifact ) && class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::to_markdown( $artifact )
			: sanitize_textarea_field( (string) ( $data['plan_markdown'] ?? $data['reply'] ?? $data['message'] ?? '' ) );
		$has_plan_shape = ! empty( $artifact ) || $this->result_has_explicit_plan_payload( $data, $plan_markdown );
		$plan_steps     = ! empty( $artifact ) && class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::to_plan_steps( $artifact )
			: PressArk_Plan_Mode::extract_steps( $plan_markdown, $has_plan_shape ? $fallback_steps : array() );
		if ( empty( $artifact ) && ! $has_plan_shape ) {
			if ( $checkpoint ) {
				$checkpoint->set_plan_phase( 'exploring' );
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'exploring' );
				}
			}
			$data['type']        = 'final_response';
			$data['status']      = 'exploring';
			$data['can_execute'] = false;
			$data['reply']       = sanitize_textarea_field( (string) ( $data['reply'] ?? $data['message'] ?? '' ) );
			$data['message']     = sanitize_textarea_field( (string) ( $data['message'] ?? $data['reply'] ?? '' ) );
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['plan_artifact'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			return $data;
		}
		if ( empty( $artifact ) && $checkpoint && class_exists( 'PressArk_Plan_Artifact' ) ) {
			$request_message = trim( $this->request_message );
			if ( '' === $request_message && method_exists( $checkpoint, 'get_goal' ) ) {
				$request_message = trim( (string) $checkpoint->get_goal() );
			}
			$artifact = PressArk_Plan_Artifact::ensure(
				$plan_markdown,
				$plan_steps,
				array(
					'plan_markdown'    => $plan_markdown,
					'approval_level'   => 'hard',
					'request_message'  => PressArk_Plan_Mode::strip_plan_directive( $request_message ),
					'execute_message'  => PressArk_Plan_Mode::strip_plan_directive( $request_message ),
					'request_summary'  => method_exists( $checkpoint, 'get_goal' ) ? $checkpoint->get_goal() : $request_message,
					'affected_entities'=> method_exists( $checkpoint, 'get_entities' ) ? $checkpoint->get_entities() : array(),
					'groups'           => $this->loaded_groups,
					'run_id'           => $this->run_id,
				)
			);
			if ( ! empty( $artifact ) ) {
				$plan_markdown = PressArk_Plan_Artifact::to_markdown( $artifact );
				$plan_steps    = PressArk_Plan_Artifact::to_plan_steps( $artifact );
			}
		}
		if ( empty( $plan_steps ) ) {
			$plan_steps = $fallback_steps;
		}
		if ( empty( $plan_steps ) ) {
			$request_message = trim( $this->request_message );
			if ( '' === $request_message && $checkpoint && method_exists( $checkpoint, 'get_goal' ) ) {
				$request_message = trim( (string) $checkpoint->get_goal() );
			}
			$clarification = self::build_plan_clarification_reply( $request_message, $this->task_type, $this->loaded_groups );
			if ( $checkpoint ) {
				$checkpoint->set_plan_phase( 'exploring' );
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'needs_input' );
				}
			}
			$data['type']        = 'final_response';
			$data['status']      = 'needs_input';
			$data['exit_reason'] = 'needs_input';
			$data['reply']       = $clarification;
			$data['message']     = $clarification;
			$data['can_execute'] = false;
			unset(
				$data['plan_markdown'],
				$data['plan_steps'],
				$data['plan_artifact'],
				$data['approve_endpoint'],
				$data['execute_endpoint'],
				$data['revise_endpoint'],
				$data['reject_endpoint']
			);
			return $data;
		}
		if ( empty( $plan_markdown ) || 'round_limit' === (string) ( $data['exit_reason'] ?? '' ) ) {
			$plan_markdown = $this->build_plan_markdown( $plan_steps, $plan_markdown );
		}

		if ( $checkpoint ) {
			$checkpoint->set_plan_phase( 'planning' );
			$checkpoint->set_plan_text( $plan_markdown );
			if ( ! empty( $artifact ) && method_exists( $checkpoint, 'set_plan_artifact' ) ) {
				$checkpoint->set_plan_artifact( $artifact );
			}
			if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
				$checkpoint->set_plan_status( 'ready' );
			}
		}

		if ( ! empty( $plan_steps ) ) {
			$data['hit_limit']   = false;
			$data['exit_reason'] = 'plan_ready';
		}

		$policy_reason_codes = array_values( array_filter( array_map( 'sanitize_key', (array) ( $this->planning_decision['reason_codes'] ?? array() ) ) ) );
		$policy_reason_reply = 'hard_plan' === $this->planning_mode
			? self::compose_escalation_reply( $policy_reason_codes )
			: '';

		// v5.8.6 (2026-05-14, iter-42): upfront hard plans now reuse the same policy reason copy as soft escalation.
		$data['type']          = 'plan_ready';
		$data['status']        = 'ready';
		$data['reply']         = '' !== $policy_reason_reply ? $policy_reason_reply : $this->build_plan_ready_reply( $plan_steps );
		$data['plan_markdown'] = $plan_markdown;
		$data['plan_steps']    = $plan_steps;
		$data['plan_artifact'] = $artifact;
		$data['approval_level'] = ! empty( $artifact['approval_level'] ) ? $artifact['approval_level'] : 'hard';
		$data['plan_phase']    = 'planning';
		$data['message']       = $plan_markdown;
		$data['policy_reason_codes'] = $policy_reason_codes;

		return $data;
	}

	private function sync_checkpoint_planning_context( PressArk_Checkpoint $checkpoint, string $message, array $conversation ): void {
		if ( method_exists( $checkpoint, 'set_plan_policy' ) && ! empty( $this->planning_decision ) ) {
			$checkpoint->set_plan_policy( $this->planning_decision );
		}

		if ( method_exists( $checkpoint, 'set_plan_request_context' ) ) {
			$checkpoint->set_plan_request_context(
				array_merge(
					$checkpoint->get_plan_request_context(),
					array(
						'message'         => $message,
						'execute_message' => PressArk_Plan_Mode::strip_plan_directive( $message ),
						'conversation'    => $conversation,
						'loaded_groups'   => array_values( array_unique( $this->loaded_groups ) ),
					)
				)
			);
		}
	}

	/**
	 * Persist the agent's current loaded_groups into the checkpoint so that
	 * plan-continuation replays (request-compiler) rehydrate the correct
	 * tool loadout instead of starting from the slim preload each round.
	 */
	private function persist_loaded_groups_to_checkpoint( PressArk_Checkpoint $checkpoint ): void {
		if ( ! method_exists( $checkpoint, 'set_plan_request_context' ) ) {
			return;
		}
		$checkpoint->set_plan_request_context(
			array_merge(
				$checkpoint->get_plan_request_context(),
				array(
					'loaded_groups' => array_values( array_unique( $this->loaded_groups ) ),
				)
			)
		);
	}

	private function plan_artifact_from_checkpoint( PressArk_Checkpoint $checkpoint ): array {
		if ( ! method_exists( $checkpoint, 'get_plan_artifact' ) || ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return array();
		}

		return PressArk_Plan_Artifact::ensure( $checkpoint->get_plan_artifact() );
	}

	private function extract_artifact_groups( array $artifact ): array {
		$groups = array();
		foreach ( (array) ( $artifact['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$group = sanitize_key( (string) ( $step['group'] ?? '' ) );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		return array_values( array_slice( array_unique( $groups ), 0, 6 ) );
	}

	private function build_plan_artifact(
		PressArk_Checkpoint $checkpoint,
		array $plan,
		string $message,
		array $conversation
	): array {
		if ( ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return array();
		}

		$prior_artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		$revision_note  = method_exists( $checkpoint, 'get_plan_revision_note' )
			? $checkpoint->get_plan_revision_note()
			: '';
		$constraints    = method_exists( $checkpoint, 'get_constraints' ) ? $checkpoint->get_constraints() : array();
		if ( '' !== $revision_note ) {
			$constraints[] = $revision_note;
		}

		$version = method_exists( $checkpoint, 'get_next_plan_version' )
			? max( 1, (int) $checkpoint->get_next_plan_version() )
			: max( 1, (int) ( $prior_artifact['version'] ?? 1 ) );

		return PressArk_Plan_Artifact::build(
			$plan,
			array(
				'prior_artifact'   => $prior_artifact,
				'approval_level'   => $this->is_hard_plan_policy() ? 'hard' : 'soft',
				'request_message'  => PressArk_Plan_Mode::strip_plan_directive( $message ),
				'execute_message'  => PressArk_Plan_Mode::strip_plan_directive( $message ),
				'request_summary'  => method_exists( $checkpoint, 'get_goal' ) ? $checkpoint->get_goal() : $message,
				'constraints'      => $constraints,
				'affected_entities'=> method_exists( $checkpoint, 'get_entities' ) ? $checkpoint->get_entities() : array(),
				'run_id'           => $this->run_id,
				'version'          => $version,
				'groups'           => (array) ( $plan['groups'] ?? array() ),
				'conversation'     => $conversation,
			)
		);
	}

	private function apply_plan_artifact_to_checkpoint( PressArk_Checkpoint $checkpoint, array $artifact, bool $ready_for_execution = false ): void {
		if ( empty( $artifact ) || ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return;
		}

		$this->active_plan_artifact = PressArk_Plan_Artifact::ensure( $artifact );
		if ( method_exists( $checkpoint, 'set_plan_artifact' ) ) {
			$checkpoint->set_plan_artifact( $this->active_plan_artifact );
		}
		if ( method_exists( $checkpoint, 'set_plan_text' ) ) {
			$checkpoint->set_plan_text( PressArk_Plan_Artifact::to_markdown( $this->active_plan_artifact ) );
		}

		$seeded_execution = PressArk_Plan_Artifact::seed_execution_ledger(
			$this->active_plan_artifact,
			$checkpoint->get_execution()
		);
		$checkpoint->set_execution( $seeded_execution );

		if ( $ready_for_execution ) {
			$checkpoint->set_plan_phase( 'executing' );
			if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
				$checkpoint->set_plan_status( $this->is_soft_plan_mode() ? 'soft_generated' : 'approved' );
			}
		}
	}

	private function sync_plan_artifact_from_execution( PressArk_Checkpoint $checkpoint ): void {
		if ( ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return;
		}

		$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		if ( empty( $artifact ) ) {
			return;
		}

		$artifact = PressArk_Plan_Artifact::sync_step_statuses( $artifact, $checkpoint->get_execution() );
		$this->active_plan_artifact = $artifact;
		$checkpoint->set_plan_artifact( $artifact );
	}

	private function complete_current_plan_task( PressArk_Checkpoint $checkpoint, array $allowed_kinds, string $evidence ): void {
		if ( 'executing' !== $checkpoint->get_plan_phase() ) {
			return;
		}

		$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		if ( empty( $artifact ) || ! class_exists( 'PressArk_Execution_Ledger' ) ) {
			return;
		}

		$execution = PressArk_Execution_Ledger::complete_current_task(
			$checkpoint->get_execution(),
			$allowed_kinds,
			$evidence
		);
		$checkpoint->set_execution( $execution );
		$this->sync_plan_artifact_from_execution( $checkpoint );
	}

	private function dynamic_tool_target_post_id( array $args ): int {
		foreach ( array( 'post_id', 'product_id', 'target_post_id', 'target_id', 'object_id' ) as $key ) {
			$post_id = absint( $args[ $key ] ?? 0 );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		foreach ( array( 'post', 'page', 'target', 'item' ) as $key ) {
			if ( ! is_array( $args[ $key ] ?? null ) ) {
				continue;
			}
			$post_id = $this->dynamic_tool_target_post_id( (array) $args[ $key ] );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		foreach ( array( 'fixes', 'products', 'items' ) as $key ) {
			if ( ! is_array( $args[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( (array) $args[ $key ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$post_id = $this->dynamic_tool_target_post_id( $row );
				if ( $post_id > 0 ) {
					return $post_id;
				}
			}
		}

		return 0;
	}

	private function is_terminal_dynamic_plan_status( string $status ): bool {
		return in_array( sanitize_key( $status ), array( 'completed', 'verified', 'skipped', 'done' ), true );
	}

	private function is_model_plan_tracking_task( array $task ): bool {
		$metadata = is_array( $task['metadata'] ?? null ) ? (array) $task['metadata'] : array();
		$origin   = sanitize_key( (string) ( $metadata['origin'] ?? '' ) );
		if ( 'dynamic_execution' === $origin ) {
			return false;
		}
		if ( 'model_plan' === $origin ) {
			return true;
		}

		return '' !== sanitize_key( (string) ( $metadata['plan_step_id'] ?? '' ) );
	}

	private function model_plan_task_covers_read_tool( array $task, string $group ): bool {
		$metadata = is_array( $task['metadata'] ?? null ) ? (array) $task['metadata'] : array();
		if ( '' !== sanitize_key( (string) ( $metadata['tool_name'] ?? '' ) ) ) {
			return false;
		}

		$task_kind  = sanitize_key( (string) ( $metadata['kind'] ?? '' ) );
		$task_group = sanitize_key( (string) ( $metadata['group'] ?? '' ) );
		$label      = strtolower( sanitize_text_field( (string) ( $task['label'] ?? '' ) ) );

		if ( in_array( $task_kind, array( 'read', 'analyze', 'verify' ), true ) ) {
			return true;
		}
		if ( '' !== $group
			&& $task_group === $group
			&& ! in_array( $task_kind, array( 'preview', 'confirm', 'write' ), true )
		) {
			return true;
		}

		return 1 === preg_match( '/\b(audit|analy[sz]e|research|check|scan|identify|find|inspect|review|read|list)\b/', $label );
	}

	private function should_suppress_dynamic_plan_step( array $execution, string $tool_name, array $args, string $kind, string $group ): bool {
		$tool_name = sanitize_key( $tool_name );
		if ( '' === $tool_name ) {
			return false;
		}

		$tasks          = (array) ( $execution['tasks'] ?? array() );
		$target_post_id = $this->dynamic_tool_target_post_id( $args );
		$terminal_same  = false;

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) || ! $this->is_model_plan_tracking_task( $task ) ) {
				continue;
			}

			$metadata  = is_array( $task['metadata'] ?? null ) ? (array) $task['metadata'] : array();
			$task_tool = sanitize_key( (string) ( $metadata['tool_name'] ?? '' ) );
			if ( $task_tool !== $tool_name ) {
				continue;
			}

			$status = sanitize_key( (string) ( $task['status'] ?? 'pending' ) );
			if ( ! $this->is_terminal_dynamic_plan_status( $status ) ) {
				return true;
			}

			$task_post_id = absint( $metadata['post_id'] ?? 0 );
			if ( $target_post_id > 0 && $task_post_id > 0 && $target_post_id !== $task_post_id ) {
				continue;
			}
			if ( 0 === $target_post_id && in_array( $tool_name, array( 'create_post', 'elementor_create_page' ), true ) ) {
				continue;
			}

			$terminal_same = true;
		}

		if ( $terminal_same ) {
			return true;
		}

		if ( 'read' !== sanitize_key( $kind ) ) {
			return false;
		}

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) || ! $this->is_model_plan_tracking_task( $task ) ) {
				continue;
			}
			if ( $this->is_terminal_dynamic_plan_status( (string) ( $task['status'] ?? 'pending' ) ) ) {
				continue;
			}
			if ( $this->model_plan_task_covers_read_tool( $task, $group ) ) {
				return true;
			}
		}

		return false;
	}

	private function maybe_insert_dynamic_plan_steps( PressArk_Checkpoint $checkpoint, array $tool_calls ): void {
		if ( 'executing' !== $checkpoint->get_plan_phase() || empty( $tool_calls ) ) {
			return;
		}
		if ( ! class_exists( 'PressArk_Plan_Artifact' ) || ! class_exists( 'PressArk_Execution_Ledger' ) ) {
			return;
		}

		$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		if ( empty( $artifact ) ) {
			return;
		}

		$existing_ids = array();
		foreach ( (array) ( $artifact['steps'] ?? array() ) as $step ) {
			$step_id = sanitize_key( (string) ( $step['id'] ?? '' ) );
			if ( '' !== $step_id ) {
				$existing_ids[ $step_id ] = true;
			}
		}

		$dynamic_steps = array();
		$dynamic_tasks = array();
		$previous_key  = '';
		$current_task  = PressArk_Execution_Ledger::next_actionable_task( $checkpoint->get_execution() );
		if ( ! empty( $current_task['key'] ) ) {
			$previous_key = sanitize_key( (string) $current_task['key'] );
		} elseif ( ! empty( $artifact['steps'] ) ) {
			$last_step     = end( $artifact['steps'] );
			$previous_key  = sanitize_key( (string) ( $last_step['id'] ?? '' ) );
			reset( $artifact['steps'] );
		}

		// v5.6.4 (2026-05-12): Tools that don't represent user-visible work
		// should not generate a dynamic tracking task — they pollute the
		// Completed/Remaining lists the user sees in [Continue] envelopes
		// and the model sees in wrap-round STATE blocks. Specifically
		// update_plan is the planning tool itself: tracking "the model
		// called update_plan" as a remaining task is meaningless to the
		// user and self-referential to the harness. iter-11's marking hook
		// in handle_update_plan only marks dynamic tasks done by matching
		// a MODEL-emitted step's tool_name, which never references the
		// planning tool — so the tracker for update_plan would linger as
		// `in_progress` forever. Observed 2026-05-12: "Update Plan" stuck
		// in the wrap-round [Continue] Remaining list on the World Cup
		// t-shirts chain. Skip these tools entirely.
		// v5.8.0 (2026-05-13): Observation Chain A showed 10-step bloat
		// from dynamic rows duplicating a 3-step model plan. Suppress rows
		// covered by model-plan tasks before writing either canonical store.
		$skip_dynamic_tracker_tools = array(
			'update_plan'   => true,
			'read_resource' => true,
		);

		foreach ( $tool_calls as $index => $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? '' ) );
			if ( '' === $tool_name ) {
				continue;
			}
			if ( isset( $skip_dynamic_tracker_tools[ $tool_name ] ) ) {
				continue;
			}

			$contract = is_array( $tool_call['_tool_contract'] ?? null )
				? $tool_call['_tool_contract']
				: $this->resolve_tool_contract( $tool_name, $tool_call['arguments'] ?? array() );
			$kind     = ! empty( $contract['readonly'] )
				? 'read'
				: ( 'preview' === ( $contract['capability'] ?? 'confirm' ) ? 'preview' : 'write' );
			$group    = sanitize_key( (string) ( $contract['group'] ?? '' ) );
			if ( $this->should_suppress_dynamic_plan_step(
				$checkpoint->get_execution(),
				$tool_name,
				is_array( $tool_call['arguments'] ?? null ) ? (array) $tool_call['arguments'] : array(),
				$kind,
				$group
			) ) {
				continue;
			}
			$step_id   = sanitize_key( 'dynamic_' . $tool_name . '_' . ( $index + 1 ) );
			if ( isset( $existing_ids[ $step_id ] ) ) {
				continue;
			}

			if ( '' === $group ) {
				$group = 'system';
			}

			$title = sanitize_text_field(
				ucwords( str_replace( '_', ' ', $tool_name ) )
			);

			$dynamic_steps[] = array(
				'id'            => $step_id,
				'title'         => $title,
				'description'   => $title,
				'kind'          => $kind,
				'group'         => $group,
				'depends_on'    => '' !== $previous_key ? array( $previous_key ) : array(),
				'metadata'      => array(
					'tool_name' => $tool_name,
					'origin'    => 'dynamic_execution',
				),
				'verification'  => '',
				'rollback_hint' => '',
			);
			$dynamic_tasks[] = array(
				'key'        => $step_id,
				'label'      => $title,
				'depends_on' => '' !== $previous_key ? array( $previous_key ) : array(),
				'metadata'   => array(
					'kind'      => $kind,
					'group'     => $group,
					'tool_name' => $tool_name,
					'origin'    => 'dynamic_execution',
				),
			);
			$existing_ids[ $step_id ] = true;
			$previous_key             = $step_id;
		}

		if ( empty( $dynamic_steps ) ) {
			return;
		}

		$artifact  = PressArk_Plan_Artifact::append_steps( $artifact, $dynamic_steps );
		$execution = PressArk_Execution_Ledger::insert_dynamic_tasks( $checkpoint->get_execution(), $dynamic_tasks );
		$checkpoint->set_execution( $execution );
		$checkpoint->set_plan_artifact( $artifact );
		$this->active_plan_artifact = $artifact;
	}

	private function soft_plan_has_single_tracked_entity( PressArk_Checkpoint $checkpoint ): bool {
		$selected_target = method_exists( $checkpoint, 'get_selected_target' )
			? (array) $checkpoint->get_selected_target()
			: array();
		foreach ( array( 'post_id', 'id', 'title', 'slug', 'key' ) as $field ) {
			if ( '' !== trim( (string) ( $selected_target[ $field ] ?? '' ) ) ) {
				return true;
			}
		}

		if ( class_exists( 'PressArk_Execution_Ledger' )
			&& PressArk_Execution_Ledger::current_target_post_id( $checkpoint->get_execution() ) > 0
		) {
			return true;
		}

		$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		$entities = array_values(
			array_filter(
				(array) ( $artifact['affected_entities'] ?? array() ),
				static function ( $entity ): bool {
					if ( ! is_array( $entity ) ) {
						return false;
					}

					foreach ( array( 'id', 'label', 'slug', 'key', 'type' ) as $field ) {
						if ( '' !== trim( (string) ( $entity[ $field ] ?? '' ) ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);

		if ( 1 === count( $entities ) ) {
			return true;
		}

		$checkpoint_entities = array_values(
			array_filter(
				(array) $checkpoint->get_entities(),
				static function ( $entity ): bool {
					if ( ! is_array( $entity ) ) {
						return false;
					}

					foreach ( array( 'id', 'title', 'type' ) as $field ) {
						if ( '' !== trim( (string) ( $entity[ $field ] ?? '' ) ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);

		return 1 === count( $checkpoint_entities );
	}

	private function soft_plan_call_has_explicit_target( array $args ): bool {
		foreach ( $args as $key => $value ) {
			$normalized = sanitize_key( (string) $key );
			if ( '' === $normalized ) {
				continue;
			}

			$is_selector = in_array(
				$normalized,
				array(
					'id',
					'ids',
					'post_id',
					'post_ids',
					'page_id',
					'page_ids',
					'product_id',
					'product_ids',
					'order_id',
					'order_ids',
					'user_id',
					'user_ids',
					'term_id',
					'term_ids',
					'comment_id',
					'comment_ids',
					'variation_id',
					'variation_ids',
					'template_id',
					'template_ids',
					'widget_id',
					'widget_ids',
					'slug',
					'slugs',
					'path',
					'paths',
					'key',
					'keys',
					'handle',
					'handles',
				),
				true
			) || (bool) preg_match( '/(?:^|_)(?:id|slug|path|key|handle)$/', $normalized );

			if ( ! $is_selector ) {
				continue;
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $candidate ) {
					if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	private function soft_plan_scope_flag_is_enabled( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array(
				strtolower( trim( $value ) ),
				array( '1', 'true', 'yes', 'all', 'bulk', 'batch', 'global', 'sitewide', 'site-wide' ),
				true
			);
		}

		return false;
	}

	private function soft_plan_call_is_broad_scope( string $tool_name, array $args ): bool {
		if ( preg_match( '/(?:bulk|batch|mass|sitewide|site_wide|global|all_items?|all_posts?|all_products?)/i', $tool_name ) ) {
			return true;
		}

		foreach ( $args as $key => $value ) {
			$normalized = sanitize_key( (string) $key );
			if ( '' === $normalized ) {
				continue;
			}

			if ( in_array( $normalized, array( 'all', 'bulk', 'batch', 'sitewide', 'site_wide', 'global', 'entire_site', 'apply_to_all', 'match_all' ), true )
				&& $this->soft_plan_scope_flag_is_enabled( $value )
			) {
				return true;
			}

			if ( is_array( $value )
				&& (bool) preg_match( '/(?:^|_)(?:ids|post_ids|product_ids|order_ids|user_ids|term_ids|paths|slugs|keys)$/', $normalized )
			) {
				$target_count = 0;
				foreach ( $value as $candidate ) {
					if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
						$target_count++;
					}
					if ( $target_count > 2 ) {
						return true;
					}
				}
			}

			if ( is_string( $value )
				&& in_array( $normalized, array( 'scope', 'target', 'selection', 'query', 'filter', 'where', 'match', 'mode' ), true )
				&& preg_match( '/\b(?:all|every|site-?wide|global|bulk|batch)\b/i', $value )
			) {
				return true;
			}
		}

		return false;
	}

	private function maybe_escalate_soft_plan(
		array $tool_calls,
		array $tool_set,
		array $initial_groups,
		PressArk_Checkpoint $checkpoint
	): ?array {
		if ( ! $this->is_soft_plan_mode() || $this->is_plan_mode() ) {
			return null;
		}

		$write_calls            = array();
		$destructive            = false;
		$broad_scope            = false;
		$ambiguous_targets      = false;
		$single_tracked_entity  = $this->soft_plan_has_single_tracked_entity( $checkpoint );

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$contract = is_array( $tool_call['_tool_contract'] ?? null )
				? $tool_call['_tool_contract']
				: $this->resolve_tool_contract( (string) ( $tool_call['name'] ?? '' ), $tool_call['arguments'] ?? array() );
			if ( ! empty( $contract['readonly'] ) ) {
				continue;
			}

			$write_calls[] = $tool_call;
			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? '' ) );
			if ( preg_match( '/(?:delete|remove|reset|deactivate|uninstall|trash|cancel|refund)/i', $tool_name ) ) {
				$destructive = true;
			}

			$args = is_array( $tool_call['arguments'] ?? null ) ? (array) $tool_call['arguments'] : array();
			$is_broad_write = $this->soft_plan_call_is_broad_scope( $tool_name, $args );
			if ( $is_broad_write ) {
				$broad_scope = true;
			}

			if ( ! $single_tracked_entity
				&& ! $is_broad_write
				&& ! $this->soft_plan_call_has_explicit_target( $args )
			) {
				$ambiguous_targets = true;
			}
		}

		if ( empty( $write_calls ) ) {
			return null;
		}

		$reason_codes = array();
		if ( $destructive ) {
			$reason_codes[] = 'destructive_operation';
		}
		if ( $broad_scope ) {
			$reason_codes[] = 'bulk_write_scope';
		}
		if ( $ambiguous_targets ) {
			$reason_codes[] = 'ambiguous_targets';
		}

		if ( empty( $reason_codes ) ) {
			return null;
		}

		$reply = self::compose_escalation_reply( $reason_codes );
		if ( '' === $reply ) {
			return null;
		}

		$artifact = $this->build_plan_artifact(
			$checkpoint,
			array(
				'task_type' => $this->task_type ?: 'edit',
				'steps'     => $this->plan_steps,
				'groups'    => $this->extract_artifact_groups( $this->plan_artifact_from_checkpoint( $checkpoint ) ),
				'artifact'  => array(
					'risks' => array_merge(
						array_values( (array) ( $this->plan_artifact_from_checkpoint( $checkpoint )['risks'] ?? array() ) ),
						$reason_codes
					),
				),
			),
			$this->request_message ?: $checkpoint->get_goal(),
			array()
		);

		if ( empty( $artifact ) ) {
			$artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		}
		if ( empty( $artifact ) ) {
			return null;
		}

		$artifact['approval_level'] = 'hard';
		$checkpoint->set_plan_artifact( $artifact );
		$checkpoint->set_plan_phase( 'planning' );
		if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
			$checkpoint->set_plan_status( 'escalated' );
		}

		$this->planning_mode = 'hard_plan';
		$this->active_plan_artifact = PressArk_Plan_Artifact::ensure( $artifact );
		$this->record_activity_event(
			'plan.escalated',
			'soft_plan_escalated',
			'waiting',
			'plan',
			'Soft planning escalated to hard approval before writes.',
			array(
				'reason_codes' => $reason_codes,
				'escalation_source' => 'tool_call_scope',
			)
		);

		return $this->build_result(
			array(
				'type'           => 'plan_ready',
				'status'         => 'ready',
				'reply'          => $reply,
				'message'        => PressArk_Plan_Artifact::to_markdown( $artifact ),
				'plan_markdown'  => PressArk_Plan_Artifact::to_markdown( $artifact ),
				'plan_steps'     => PressArk_Plan_Artifact::to_plan_steps( $artifact ),
				'plan_artifact'  => $artifact,
				'approval_level' => 'hard',
				'plan_phase'     => 'planning',
				'escalation_source' => 'soft_plan',
				'policy_reason_codes' => $reason_codes,
			),
			$tool_set,
			$initial_groups,
			$checkpoint
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
		if ( $this->is_plan_mode() ) {
			$round_limit = max( 3, min( $round_limit, 4 ) );
		}
		$token_budget = (int) PressArk_Entitlements::tier_value( $this->tier, 'agent_token_budget' );

		// v5.0.1: Wall-clock deadline prevents runaway loops even when
		// round count and token budget are within limits. When the dev
		// simulator is active, sub-agent observers can take 20-60s per
		// round, so bump to 30 minutes — the round_limit and token_budget
		// still apply as the real ceilings.
		$loop_timeout = PressArk_AI_Connector::simulator_active() ? 1800 : self::LOOP_TIMEOUT_SECONDS;
		$deadline     = microtime( true ) + $loop_timeout;

		$this->tokens_used         = 0;
		$this->output_tokens_used  = 0;
		$this->input_tokens_used   = 0;
		$this->cache_read_tokens   = 0;
		$this->cache_write_tokens  = 0;
		$this->context_tokens_used = 0;
		$this->user_context_tokens_used = 0;
		$this->system_context_tokens_used = 0;
		$this->icu_spent           = 0;
		$this->model_rounds        = 0;
		$this->actual_provider     = '';
		$this->actual_model        = '';
		$this->streamed_tool_summaries = array();
		$this->streamed_tool_token_count = 0;
		$this->steps               = array();
		$this->request_message     = $message;
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
		$this->sync_checkpoint_planning_context( $checkpoint, $message, $conversation );
		if ( $this->is_plan_mode() ) {
			$checkpoint->set_plan_phase( 'exploring' );
			if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
				$checkpoint->set_plan_status( 'exploring' );
			}
		}
		$this->plan_steps = $this->plan_step_labels_from_rows( $checkpoint->get_plan_steps() );
		$this->plan_step  = max( 1, (int) $checkpoint->get_active_plan_step_index() + 1 );

		$approved_artifact = ( ! $this->is_plan_mode() && 'executing' === $checkpoint->get_plan_phase() )
			? $this->plan_artifact_from_checkpoint( $checkpoint )
			: array();

		// v4.3.2: Planner call. Same cheap model as v4.3.1 classifier, but
		// includes the capability map so the model can see all available tool
		// domains and return a structured execution plan with steps + groups.
		// Saves 7-17K tokens by eliminating discover→load round-trips.
		// Falls back to local regex on failure.
		if ( ! empty( $approved_artifact ) ) {
			$plan = array(
				'task_type' => self::classify_task( $message, $conversation ),
				'steps'     => array_values(
					array_filter(
						array_map(
							static fn( $row ) => sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
							PressArk_Plan_Artifact::to_plan_steps( $approved_artifact )
						)
					)
				),
				'groups'    => $this->extract_artifact_groups( $approved_artifact ),
				'artifact'  => $approved_artifact,
			);
			$this->active_plan_artifact = $approved_artifact;
		} else {
			$plan = $this->plan_with_ai( $message, $conversation );
			$this->cached_ai_plan = is_array( $plan ) ? $plan : array();
		}
		$task_type = $plan['task_type'];
		$this->task_type = $task_type;

		$defer_grounded_plan = empty( $approved_artifact ) && ( $this->is_plan_mode() || $this->is_soft_plan_mode() );
		$plan_artifact = ! empty( $approved_artifact )
			? $approved_artifact
			: ( $defer_grounded_plan ? array() : $this->build_plan_artifact( $checkpoint, $plan, $message, $conversation ) );
		if ( ! empty( $plan_artifact ) ) {
			if ( empty( $approved_artifact ) || empty( $checkpoint->get_execution()['tasks'] ) ) {
				$this->apply_plan_artifact_to_checkpoint(
					$checkpoint,
					$plan_artifact,
					! $this->is_plan_mode()
				);
			} else {
				$this->active_plan_artifact = PressArk_Plan_Artifact::ensure( $plan_artifact );
				$this->sync_plan_artifact_from_execution( $checkpoint );
			}
			$this->plan_steps = array_values(
				array_filter(
					array_map(
						static fn( $row ) => sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
						PressArk_Plan_Artifact::to_plan_steps( $plan_artifact )
					)
				)
			);
			if ( $this->is_plan_mode() && empty( $approved_artifact ) ) {
				$this->record_activity_event(
					'plan.hard_generated',
					'hard_plan_generated',
					'ready',
					'plan',
					'Generated a hard approval-gated plan artifact.',
					array(
						'approval_level' => 'hard',
						'plan_id'        => (string) ( $plan_artifact['plan_id'] ?? '' ),
						'version'        => (int) ( $plan_artifact['version'] ?? 1 ),
					)
				);
			} elseif ( $this->is_soft_plan_mode() && empty( $approved_artifact ) ) {
				$this->record_activity_event(
					'plan.soft_generated',
					'soft_plan_generated',
					'ready',
					'plan',
					'Generated a soft execution plan and attached it to the run context.',
					array(
						'approval_level' => 'soft',
						'plan_id'        => (string) ( $plan_artifact['plan_id'] ?? '' ),
						'version'        => (int) ( $plan_artifact['version'] ?? 1 ),
					)
				);
			}
		} elseif ( empty( $this->plan_steps ) ) {
			$this->plan_steps = $defer_grounded_plan ? array() : $plan['steps'];
		}
		$this->plan_step = max(
			1,
			method_exists( $checkpoint, 'get_active_plan_step_index' )
				? ( (int) $checkpoint->get_active_plan_step_index() + 1 )
				: 1
		);

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
			$plan_payload = array(
				'run_id'         => $this->run_id,
				'steps'          => $plan_items,
				'plan_steps'     => $plan_items,
				'plan_phase'     => $this->is_plan_mode() ? 'planning' : 'executing',
				'approval_level' => $this->is_plan_mode() ? 'hard' : '',
				'can_execute'    => false,
			);
			$this->steps[] = array(
				'type'    => 'plan',
				'content' => $plan_payload,
			);
			$emit_fn( 'plan', $plan_payload );

			if ( $this->is_plan_mode() ) {
				$checkpoint->set_plan_phase( 'planning' );
			} elseif ( $this->is_soft_plan_mode() ) {
				$checkpoint->set_plan_phase( 'executing' );
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'soft_generated' );
				}
			}
			$checkpoint->set_plan_text( implode( "\n", $this->plan_steps ) );
		}

		// v5.3.0: Mark the first actionable task as in_progress.
		$this->advance_task_graph( $checkpoint );

		if ( $this->is_plan_mode() ) {
			$this->ai->resolve_for_phase(
				'plan_mode',
				array(
					'deep_mode' => $deep_mode,
				)
			);
		} else {
			$this->ai->resolve_for_task( $task_type, $deep_mode );
		}
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
		$tool_set     = $this->filter_tool_set_for_mode( $tool_set );

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
		$leaked_retry              = false;
		// v5.8.1 (2026-05-13, iter-37): defense-in-depth retry cap for the
		// Plan Mode synthesis nudge. Without this, a misbehaving model that
		// keeps emitting prose instead of a checklist burns the full
		// round_limit (4 in Plan Mode) reloading the same 55kB request.
		$plan_synthesis_retries    = 0;
		// v5.8.2 (2026-05-13, iter-38): parallel cap for the earlier
		// grounding-retry nudge ("Use relevant read-only tools first..."),
		// which can loop the wrap round when checkpoint_has_grounded_plan_context
		// returns false despite reads having been done. Without this cap,
		// Chain B iter38b Shipping Notes (2026-05-13) burned 4 round_limit
		// slots on post-Keep grounding-retries.
		$plan_grounding_retries    = 0;
		// v5.8.11 (2026-05-14): per-loop guard for iter-45 @-syntax recovery.
		$at_syntax_recovery_signatures = array();

		while ( $round < $round_limit && $this->output_tokens_used < $token_budget ) {
			$round++;
			$this->is_post_keep_wrap_round = $this->messages_indicate_post_keep_wrap( $messages );

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

			$messages = $this->apply_lightweight_compaction( $messages, $round, $checkpoint );

			// v4.3.0: Credit-aware mid-loop compaction thresholds.
			$compaction_threshold = $deep_mode ? 12000 : 8000;
			$this->refresh_plan_stall_state( $checkpoint );
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
			// v5.6.2: build_round_system_prompt may mutate $tool_set['schemas']
			// in wrap-round mode (clears tools when the chain is just summarizing).
			// Refresh $tool_defs so the wrap round actually ships an empty tools
			// array instead of the one captured at execute_loop entry.
			$tool_defs        = (array) ( $tool_set['schemas'] ?? array() );
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
				$tool_defs         = (array) ( $tool_set['schemas'] ?? array() );
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
			$tool_defs        = (array) ( $tool_set['schemas'] ?? array() );

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
				$context_metrics = $this->ai->get_last_context_metrics();
				$this->context_tokens_used += max( 0, (int) ( $context_metrics['context_tokens'] ?? 0 ) );
				$this->user_context_tokens_used += max( 0, (int) ( $context_metrics['user_context_tokens'] ?? 0 ) );
				$this->system_context_tokens_used += max( 0, (int) ( $context_metrics['system_context_tokens'] ?? 0 ) );
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
			if ( $this->uses_prompted_tool_transport()
				&& empty( $assistant_tool_calls )
				&& '' !== trim( $assistant_text )
			) {
				$assistant_tool_calls = $this->extract_prompted_tool_calls( $assistant_text );
				if ( ! empty( $assistant_tool_calls ) ) {
					$stop_reason = 'tool_use';
				}
			}
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
					// v5.8.9 (2026-05-14, iter-45): @-syntax detector for text-only writes.
					$at_recovery = $this->maybe_recover_at_syntax_tool_call( $text, $messages, $round, $emit_fn, $at_syntax_recovery_signatures );
					if ( ! empty( $at_recovery['retry'] ) ) {
						continue;
					}
					if ( ! empty( $at_recovery['reconstructed_tool_call'] ) ) {
						$tool_calls = array( $at_recovery['reconstructed_tool_call'] );
					} elseif ( ! empty( $at_recovery ) ) {
						return $this->build_result( $at_recovery, $tool_set, $initial_groups, $checkpoint );
					} else {
						$empty_data_final_response = '' !== trim( $text ) && self::last_tool_result_was_empty_success( $messages );
						if (
							$this->planning_requires_grounded_reads()
							&& ! $this->plan_response_requires_user_input( $text )
							&& ! $this->checkpoint_has_grounded_plan_context( $checkpoint )
							&& ! $empty_data_final_response
							// v5.8.2 (2026-05-13, iter-38): same wrap-round bypass as the
							// downstream synthesis-retry. Post-Keep rounds are summarizing
							// work that's already done; injecting "Use relevant read-only
							// tools first..." here makes the model loop emitting wrap
							// variants for the rest of round_limit. Observed Chain B
							// iter38b Shipping Notes (2026-05-13): R5/R6/R7 each fired
							// this retry after the Keep had landed and the wrap text had
							// already shipped at R4.
							&& ! $this->messages_indicate_post_keep_wrap( $messages )
							// Defense-in-depth retry cap. Same rationale as the
							// synthesis-retry cap from iter-37.
							&& $plan_grounding_retries < 1
						) {
							$messages[] = array(
								'role'    => 'user',
								'content' => $this->build_grounding_retry_message( $this->is_soft_plan_mode() && ! $this->is_plan_mode() ),
							);
							$this->sync_replay_snapshot( $checkpoint, $messages );
							$plan_grounding_retries++;
							$this->record_activity_event(
								'plan.grounding_required',
								'grounding_before_plan_response',
								'retrying',
								'plan',
								'The model tried to finalize planning before any grounding reads completed.',
								array(
									'round' => $round,
									'mode'  => $this->is_plan_mode() ? 'hard_plan' : ( $this->is_soft_plan_mode() ? 'soft_plan' : 'none' ),
									'retry' => $plan_grounding_retries,
								)
							);
							continue;
						}

						if (
							$this->is_plan_mode()
							&& $this->checkpoint_has_grounded_plan_context( $checkpoint )
							&& ! $this->result_has_explicit_plan_payload(
								array(
									'type'    => 'final_response',
									'reply'   => $text,
									'message' => $text,
								),
								$text
							)
						&& ! $empty_data_final_response
						// v5.8.1 (2026-05-13, iter-37): two escape hatches.
						// (1) The model concluded research and reported the
						//     requested state is ALREADY SATISFIED — no plan
						//     is needed. Forcing a "write the checklist now"
						//     retry produces a redundant Plan card and burns
						//     a full round's request. Observed Chain G FAQ
						//     repro (2026-05-13): 3 wasted rounds, ~165k tok.
						// (2) Hard cap on how many times this nudge fires. A
						//     misbehaving model that ignores the nudge twice
						//     will keep ignoring it; better to surface the
						//     prose reply than burn the rest of round_limit.
						&& ! $this->plan_response_concludes_no_action( $text )
						&& $plan_synthesis_retries < 1
						// v5.8.2 (2026-05-13, iter-38): third escape hatch.
						// (3) Post-Keep wrap rounds (latest user message is a
						//     [Continue] envelope) have already done the
						//     planning + execution work. Forcing the model to
						//     emit "a numbered checklist" produces a
						//     retrospective list that downstream
						//     normalize_result_for_mode re-coerces to
						//     plan_ready, dropping the user's wrap message
						//     and rendering a confusing fresh Plan card.
						//     Observed Chain B post-Keep wrap (2026-05-13).
						&& ! $this->messages_indicate_post_keep_wrap( $messages )
					) {
						$messages[] = array(
							'role'    => 'user',
							'content' => $this->build_plan_synthesis_retry_message(),
						);
						$this->sync_replay_snapshot( $checkpoint, $messages );
						$plan_synthesis_retries++;
						$this->record_activity_event(
							'plan.checklist_required',
							'grounded_checklist_missing',
							'retrying',
							'plan',
							'The model finished research but did not produce a grounded checklist yet.',
							array(
								'round' => $round,
								'mode'  => 'hard_plan',
								'retry' => $plan_synthesis_retries,
							)
						);
						continue;
					}

					if ( $this->should_fail_empty_approved_plan_execution_response( $text, $checkpoint ) ) {
						$message = 'The assistant response was interrupted before a preview or change could be prepared. No changes were made. Please retry the execution.';
						$this->record_activity_event(
							'agent.empty_execute_response_failed',
							'empty_approved_plan_execution_response',
							'failed',
							'agent',
							'Stopped an approved plan execution after the assistant returned an empty response before preparing a preview or change.',
							array(
								'round' => $round,
							)
						);
						return $this->build_result(
							array(
								'type'          => 'final_response',
								'message'       => $message,
								'reply'         => $message,
								'is_error'      => true,
								'error'         => 'empty_approved_plan_execution_response',
								'exit_reason'   => 'empty_approved_plan_execution_response',
								'failure_class' => class_exists( 'PressArk_AI_Connector' )
									? PressArk_AI_Connector::FAILURE_PROVIDER_ERROR
									: 'provider_error',
							),
							$tool_set,
							$initial_groups,
							$checkpoint
						);
					}

					if ( empty( $text ) && $round > 1 ) {
						$text = 'I reviewed the data but wasn\'t able to produce a useful response. Try rephrasing or providing more detail about what you need.';
					}
					// v5.8.2 (2026-05-13, iter-38): tell the frontend that any
					// Plan Mode card from round 1 is now stale. iter-37 stopped
					// the wasted retry rounds; this stops the stale Plan card
					// from sitting in the chat asking the user to Execute the
					// very plan the model just concluded was unnecessary.
					// Observed Chain G FAQ repro (2026-05-13): post-iter-37
					// the 3-round chain completed cleanly but the R1 Plan card
					// still rendered Execute/Revise/Reject buttons next to a
					// "no changes needed" reply.
					$result_payload = array(
						'type'    => 'final_response',
						'message' => $text,
					);
					if ( $empty_data_final_response ) {
						$result_payload['empty_data_final_response'] = true;
						$result_payload['exit_reason'] = 'empty_data_final_response';
						$result_payload['plan_card_obsolete'] = true;
						$result_payload['plan_card_obsolete_reason'] = 'empty_data_final_response';
						$this->record_activity_event(
							'agent.empty_data_final_response',
							'accepted_empty_success_text_wrap',
							'accepted',
							'agent',
							'Accepted a text final response after the latest successful tool result returned empty data.',
							array(
								'round' => $round,
								'mode'  => $this->is_plan_mode() ? 'hard_plan' : ( $this->is_soft_plan_mode() ? 'soft_plan' : 'none' ),
							)
						);
					}
					if (
						$this->is_plan_mode()
						&& '' !== trim( $text )
						&& $this->plan_response_concludes_no_action( $text )
					) {
						$result_payload['plan_card_obsolete'] = true;
						$result_payload['plan_card_obsolete_reason'] = 'model_concluded_no_action';
					}
					return $this->build_result( $result_payload, $tool_set, $initial_groups, $checkpoint );
				}
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
						// v5.8.9 (2026-05-14, iter-45): recover text-only @tool args before falling into parse failure.
						$at_recovery = $this->maybe_recover_at_syntax_tool_call( $text, $messages, $round, $emit_fn, $at_syntax_recovery_signatures );
						if ( ! empty( $at_recovery['retry'] ) ) {
							continue;
						}
						if ( ! empty( $at_recovery['reconstructed_tool_call'] ) ) {
							$tool_calls = array( $at_recovery['reconstructed_tool_call'] );
						} elseif ( ! empty( $at_recovery ) ) {
							return $this->build_result( $at_recovery, $tool_set, $initial_groups, $checkpoint );
						} else {
						return $this->build_result( array(
							'type'    => 'final_response',
							'message' => $text ?: 'The AI returned an unexpected response format. No changes were made. Please try again.',
							'error'   => 'tool_call_parse_failure',
						), $tool_set, $initial_groups, $checkpoint );
						}
					}
				}
			}

			// Emit tool_call events for streaming clients.
			foreach ( $tool_calls as $tc ) {
				$emit_fn( 'tool_call', array(
					'id'       => $tc['id'] ?? '',
					'name'     => $tc['name'] ?? '',
					'tool_key' => $this->tool_progress_key( $tc ),
					'args'     => $tc['arguments'] ?? array(),
				) );
			}

			$guardrail_results = array();
			$tool_calls        = $this->filter_retry_guarded_tool_calls( $tool_calls, $checkpoint, $guardrail_results );
			if ( ! empty( $guardrail_results ) ) {
				$this->append_tool_results( $messages, $guardrail_results, $provider );
				$this->sync_replay_snapshot( $checkpoint, $messages );
				if ( empty( $tool_calls ) ) {
					continue;
				}
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

			$normalized_tool_calls = $this->normalize_and_guard_tool_calls(
				$tool_calls,
				$messages,
				$message,
				$provider,
				$checkpoint,
				$round
			);
			if ( ! empty( $normalized_tool_calls['retry'] ) ) {
				continue;
			}
			$tool_calls = is_array( $normalized_tool_calls['tool_calls'] ?? null )
				? $normalized_tool_calls['tool_calls']
				: array();
			if ( empty( $tool_calls ) ) {
				continue;
			}

			$permission_gate = $this->maybe_gate_tool_calls(
				$tool_calls,
				$screen,
				$post_id,
				$tool_set,
				$initial_groups,
				$checkpoint
			);

			if ( is_array( $permission_gate ) ) {
				return $permission_gate;
			}

			// Classify tool calls into read / preview / confirm groups.
			$read_calls    = array();
			$preview_calls = array();
			$confirm_calls = array();

			foreach ( $tool_calls as $tc ) {
				$contract                 = $this->resolve_tool_contract( $tc['name'], $tc['arguments'] ?? array() );
				$tc['_tool_contract']     = $contract;
				$tc['_concurrency_safe']  = ! empty( $contract['concurrency_safe'] );

				if ( ! empty( $contract['readonly'] ) ) {
					$read_calls[] = $tc;
				} elseif ( 'preview' === ( $contract['capability'] ?? 'confirm' ) ) {
					$preview_calls[] = $tc;
				} else {
					$confirm_calls[] = $tc;
				}
			}
			// PATRACE
			pressark_trace(
				'TOOL_BUCKETS',
				array(
					'run_id'        => $this->run_id,
					'round'         => $round,
					'read_calls'    => array_values( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $read_calls ) ),
					'preview_calls' => array_values( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $preview_calls ) ),
					'confirm_calls' => array_values( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $confirm_calls ) ),
				)
			);

			// ── CASE A: All reads — execute and continue loop ────────────────
			if (
				$this->is_soft_plan_mode()
				&& ! $this->is_plan_mode()
				&& empty( $read_calls )
				&& ( ! empty( $preview_calls ) || ! empty( $confirm_calls ) )
				&& ! $this->checkpoint_has_grounded_plan_context( $checkpoint )
			) {
				$synthetic_results = array();
				foreach ( array_merge( $preview_calls, $confirm_calls ) as $write_call ) {
					$synthetic_results[] = array(
						'tool_use_id' => $write_call['id'] ?? '',
						'result'      => $this->build_read_first_synthetic_result(),
					);
				}
				$this->append_tool_results( $messages, $synthetic_results, $provider );
				$this->sync_replay_snapshot( $checkpoint, $messages );
				$this->record_activity_event(
					'plan.grounding_required',
					'grounding_before_write',
					'retrying',
					'plan',
					'Soft planning blocked writes until current-state reads completed.',
					array(
						'round'      => $round,
						'write_calls'=> count( $preview_calls ) + count( $confirm_calls ),
					)
				);
				continue;
			}

			if ( ! empty( $read_calls ) && empty( $preview_calls ) && empty( $confirm_calls ) ) {
				$tool_results = $this->execute_reads_orchestrated(
					$read_calls,
					$checkpoint,
					$round,
					$screen,
					$post_id,
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
				$this->maybe_materialize_soft_plan_from_grounding( $checkpoint, $message, $conversation );
				$this->maybe_insert_dynamic_plan_steps( $checkpoint, $read_calls );

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

				// Plan mode early-exit: if update_plan just stored a plan,
				// return immediately so finalize_plan_mode_result renders the
				// plan_ready card. Without this, the loop would keep going
				// until round_limit (wasting a round and risking a late exit
				// that surfaces as a "grounding retry" or provider error).
				if ( $this->plan_just_proposed ) {
					$this->plan_just_proposed = false;
					return $this->build_result( array(
						'type'        => 'final_response',
						'hit_limit'   => true,
						'exit_reason' => 'plan_ready',
					), $tool_set, $initial_groups, $checkpoint );
				}

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
					$screen,
					$post_id,
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
			$wc_price_guard = $this->maybe_guard_wc_price_write_intent(
				array_merge( $preview_calls, $confirm_calls ),
				$checkpoint,
				$messages,
				$provider,
				$round,
				$tool_set,
				$initial_groups
			);
			if ( ! empty( $wc_price_guard['result'] ) && is_array( $wc_price_guard['result'] ) ) {
				return $wc_price_guard['result'];
			}
			if ( ! empty( $wc_price_guard['retry'] ) ) {
				continue;
			}

			$this->maybe_materialize_soft_plan_from_grounding( $checkpoint, $message, $conversation );
			$this->maybe_insert_dynamic_plan_steps( $checkpoint, $tool_calls );
			$escalated = $this->maybe_escalate_soft_plan( $tool_calls, $tool_set, $initial_groups, $checkpoint );
			if ( is_array( $escalated ) ) {
				return $escalated;
			}
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

				$preview_payloads = $this->strip_internal_tool_call_metadata( $preview_calls );
				// PATRACE
				pressark_trace(
					'PREVIEW_SESSION_START',
					array(
						'run_id'       => $this->run_id,
						'round'        => $round,
						'tool_names'   => array_values( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $preview_payloads ) ),
						'tool_use_ids' => array_values( array_map( static fn( array $call ): string => sanitize_text_field( (string) ( $call['id'] ?? '' ) ), $preview_payloads ) ),
					)
				);
				$preview = new PressArk_Preview();
				$session = $preview->create_session( $preview_payloads, $preview_payloads[0]['arguments'] ?? array() );
				// PATRACE
				pressark_trace(
					'PREVIEW_SESSION_RESULT',
					array(
						'run_id'             => $this->run_id,
						'round'              => $round,
						'success'            => ! empty( $session['success'] ),
						'preview_session_id' => sanitize_text_field( (string) ( $session['session_id'] ?? '' ) ),
						'diff_count'         => is_array( $session['diff'] ?? null ) ? count( (array) $session['diff'] ) : 0,
						'message'            => sanitize_text_field( (string) ( $session['message'] ?? '' ) ),
					)
				);
				if ( empty( $session['success'] ) && isset( $session['message'] ) ) {
					$preview_failures = $this->build_preview_failure_tool_results(
						$preview_payloads,
						(string) $session['message']
					);
					if ( ! empty( $preview_failures ) ) {
						$this->append_tool_results( $messages, $preview_failures, $provider );
						$this->sync_replay_snapshot( $checkpoint, $messages );
					}

					// v5.7.0 (2026-05-12): When the preview's structured error
					// is recoverable (model emitted unsupported field names —
					// see iter-19b's tool-aware unsupported_fix_keys path),
					// continue the loop so the model gets a fresh round with
					// the structured tool_result in context and can self-correct.
					// Old behavior: returned `final_response` with the technical
					// error string rendered straight into the chat panel, which
					// (a) exposed harness-internal feedback to the end user and
					// (b) terminated the chain — the model never got a chance
					// to retry. The fix is below: tool_results are still
					// populated (model SEES the error), but we skip the
					// premature return and let the loop run another LLM round.
					// Recoverable errors are those carrying a structured `error`
					// code we know the model can act on. Non-structured / fatal
					// errors still terminate via the existing final_response
					// path so we don't loop forever on a hopeless case.
					// v5.7.12 (2026-05-13): Expand recoverable list to include
					// preview_staging_empty. That error carries a clear, model-
					// actionable message ("Re-read the target with read_content
					// and retry using the canonical field names") — exactly the
					// kind of self-correctable shape error iter-20 routes back
					// to the LLM as tool_result. Pre-iter-32, an empty-diff
					// preview terminated the chain even though the model could
					// have retried with correct args on the next round.
					$recoverable_preview_errors = array(
						'unsupported_fix_keys',
						'preview_staging_empty',
					);
					$preview_error_code = sanitize_key( (string) ( $session['error'] ?? '' ) );
					if ( in_array( $preview_error_code, $recoverable_preview_errors, true ) ) {
						$emit_fn( 'step', array(
							'status' => 'preview_retry',
							'label'  => 'Retrying with corrected arguments',
							'tool'   => $preview_payloads[0]['name'] ?? '',
						) );
						continue;
					}

					return $this->build_result( array(
						'type'     => 'final_response',
						'message'  => sanitize_text_field( (string) $session['message'] ),
						'is_error' => true,
					), $tool_set, $initial_groups, $checkpoint );
				}
				$preview_calls = $preview->get_session_tool_calls( $session['session_id'] );
				$preview_results = $this->build_preview_pause_tool_results( $preview_calls, $session );
				if ( ! empty( $preview_results ) ) {
					$this->append_tool_results( $messages, $preview_results, $provider );
					$this->sync_replay_snapshot( $checkpoint, $messages );
				}
				// PATRACE
				pressark_trace(
					'AGENT_PREVIEW_RETURN',
					array(
						'run_id'             => $this->run_id,
						'round'              => $round,
						'preview_session_id' => sanitize_text_field( (string) ( $session['session_id'] ?? '' ) ),
						'pending_actions'    => count( $preview_calls ),
						'tool_names'         => array_values( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $preview_calls ) ),
					)
				);

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
				$confirm_results = $this->build_confirm_pause_tool_results( $confirm_calls );
				if ( ! empty( $confirm_results ) ) {
					$this->append_tool_results( $messages, $confirm_results, $provider );
					$this->sync_replay_snapshot( $checkpoint, $messages );
				}

				return $this->build_result( array(
					'type'            => 'confirm_card',
					'message'         => $this->ai->extract_text( $raw, $provider ),
					'pending_actions' => $this->strip_internal_tool_call_metadata( $confirm_calls ),
				), $tool_set, $initial_groups, $checkpoint );
			}
		}

		// v3.2.0: Explicit exit reason for observability.
		// v3.7.4: Differentiate token-budget exits from safety step ceilings.
		$exit_reason = $this->output_tokens_used >= $token_budget ? 'token_budget' : 'round_limit';
		if ( 'token_budget' === $exit_reason ) {
			$exit_msg = 'I reached the token budget for this session. Here\'s what I found so far — you can continue in a follow-up message.';
		} else {
			$exit_msg = 'I reached the safety step limit for this session. This usually means the task needs to be broken into smaller pieces — try a more focused request to finish up.';
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
	 * Fast per-tool permission probe before any tool execution begins.
	 *
	 * @since 5.6.0
	 */
	private function maybe_gate_tool_calls(
		array $tool_calls,
		string $screen,
		int $post_id,
		array $tool_set,
		array $initial_groups,
		?PressArk_Checkpoint $checkpoint = null
	): ?array {
		if ( ! class_exists( 'PressArk_Tools' ) ) {
			return null;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $tool_calls as $tool_call ) {
			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? '' ) );
			if ( '' === $tool_name ) {
				continue;
			}

			if ( $this->is_plan_mode() && ! $this->plan_mode_allows_tool_name( $tool_name ) ) {
				$this->record_activity_event(
					'tool.permission_denied',
					'preflight_blocked',
					'blocked',
					'plan',
					'Plan mode blocked a non-readonly tool call.',
					array(
						'tool_name' => $tool_name,
						'mode'      => 'plan',
					)
				);

				return $this->build_result(
					array(
						'type'            => 'final_response',
						'message'         => class_exists( 'PressArk_Plan_Mode' )
							? PressArk_Plan_Mode::permission_denied_message()
							: __( 'Plan mode only allows read-only research.', 'pressark' ),
						'is_error'        => true,
						'permission_tool' => $tool_name,
					),
					$tool_set,
					$initial_groups,
					$checkpoint
				);
			}

			$tool = PressArk_Tools::get_tool( $tool_name );
			if ( ! is_object( $tool ) || ! method_exists( $tool, 'check_permissions' ) ) {
				continue;
			}

			$params = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : array();
			if (
				$post_id > 0
				&& ! isset( $params['post_id'], $params['id'], $params['product_id'], $params['order_id'], $params['attachment_id'], $params['media_id'] )
			) {
				$params['post_id'] = $post_id;
			}

			$permission = $tool->check_permissions( $params, $user_id, $this->tier );
			$behavior   = sanitize_key(
				(string) (
					$permission['behavior']
					?? ( ! empty( $permission['allowed'] ) ? 'allow' : 'block' )
				)
			);

			if ( 'allow' === $behavior ) {
				continue;
			}

			$permission['tool_name'] = sanitize_key( (string) ( $permission['tool_name'] ?? $tool_name ) );

			if ( 'ask' === $behavior ) {
				if ( $this->should_defer_permission_ask_to_write_approval( $permission, $tool_name, $params ) ) {
					continue;
				}

				return $this->build_result(
					array_merge(
						PressArk_Pipeline::build_permission_response( $permission ),
						array(
							'permission'      => $permission,
							'permission_tool' => $tool_name,
						)
					),
					$tool_set,
					$initial_groups,
					$checkpoint
				);
			}

			return $this->build_result(
				array(
					'type'            => 'final_response',
					'message'         => sanitize_text_field( (string) ( $permission['reason'] ?? __( 'You do not have permission to perform this action.', 'pressark' ) ) ),
					'is_error'        => true,
					'permission'      => $permission,
					'permission_tool' => $tool_name,
				),
				$tool_set,
				$initial_groups,
				$checkpoint
			);
		}

		return null;
	}

	/**
	 * Let interactive write approvals continue into the existing preview/confirm flow.
	 *
	 * The policy layer intentionally returns ASK for interactive writes. That
	 * verdict should only short-circuit the loop when approval is unavailable
	 * (for example upgrade gating), not when the agent can satisfy it with the
	 * normal preview/confirm UI.
	 */
	private function should_defer_permission_ask_to_write_approval( array $permission, string $tool_name, array $params = array() ): bool {
		$behavior = sanitize_key( (string) ( $permission['behavior'] ?? '' ) );
		if ( 'ask' !== $behavior ) {
			return false;
		}

		$ui_action = sanitize_key( (string) ( $permission['ui_action'] ?? '' ) );
		if ( ! in_array( $ui_action, array( 'preview', 'confirm' ), true ) ) {
			return false;
		}

		$contract = $this->resolve_tool_contract( $tool_name, $params );
		if ( ! empty( $contract['readonly'] ) ) {
			return false;
		}

		return in_array( (string) ( $contract['capability'] ?? '' ), array( 'preview', 'confirm' ), true );
	}

	/**
	 * Execute read tool calls using batched orchestration.
	 *
	 * @return array[]|null Ordered results, or null if cancelled.
	 */
	private function execute_reads_orchestrated(
		array $read_calls,
		PressArk_Checkpoint $checkpoint,
		int $round,
		string $screen,
		int $post_id,
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
			$meta_result = $this->handle_meta_tool( $tc, $loader, $tool_set, $tool_defs, $checkpoint );
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
			$streamed_entries = array();

			if ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG ) {
				PressArk_Error_Tracker::debug(
					'Agent',
					'Read orchestration: ' . PressArk_Read_Orchestrator::describe_batches( $batches )
				);
			}

			// Execute callback: runs a single tool and records checkpoint + bundle.
			$exec_fn = function ( array $tc ) use ( $checkpoint, $round, $screen, $post_id, $emit_fn, $cancel_check, &$streamed_entries ): array {
				$result              = array();
				$result_chunks       = array();
				$streamed_tokens     = 0;
				$progress_sequence   = 0;
				$stream_limit_hit    = false;
				$tool_contract       = is_array( $tc['_tool_contract'] ?? null )
					? $tc['_tool_contract']
					: $this->resolve_tool_contract( $tc['name'], $tc['arguments'] ?? array() );
				$tool_object         = $tool_contract['tool'] ?? null;
				$operation           = PressArk_Operation_Registry::resolve( $tc['name'] );
				$max_chunk_tokens    = max(
					1,
					(int) ( $operation instanceof PressArk_Operation ? $operation->max_stream_chunk_tokens : 500 )
				);
				$tool_progress_key   = $this->tool_progress_key( $tc );
				$progress_callback   = function ( $chunk ) use ( $emit_fn, $tc, $tool_progress_key, $max_chunk_tokens, &$result_chunks, &$streamed_tokens, &$progress_sequence, &$stream_limit_hit ): void {
					try {
						foreach ( $this->build_stream_progress_packets( $chunk, $max_chunk_tokens ) as $packet ) {
							$chunk_tokens = (int) ( $packet['estimated_tokens'] ?? 0 );
							if ( $chunk_tokens <= 0 ) {
								continue;
							}

							if ( ( $streamed_tokens + $chunk_tokens ) > self::MAX_TOOL_RESULT_TOKENS ) {
								if ( ! $stream_limit_hit ) {
									$stream_limit_hit = true;
									PressArk_Error_Tracker::warning(
										'Agent',
										'Stopped streaming progress after reaching the tool progress ceiling',
										array(
											'tool'       => $tc['name'],
											'tool_use_id'=> $tc['id'] ?? '',
											'max_tokens' => self::MAX_TOOL_RESULT_TOKENS,
										)
									);
								}
								break;
							}

							$progress_sequence++;
							$progress_payload = array(
								'id'        => $tc['id'] ?? '',
								'name'      => $tc['name'] ?? '',
								'tool'      => $tc['name'] ?? '',
								'tool_key'  => $tool_progress_key,
								'sequence'  => $progress_sequence,
								'progress'  => $packet['data'],
							);

							$emit_fn( 'tool_progress', $progress_payload );

							$result_chunks[] = array(
								'sequence' => $progress_sequence,
								'data'     => $packet['data'],
							);
							$streamed_tokens += $chunk_tokens;
						}
					} catch ( \Throwable $e ) {
						PressArk_Error_Tracker::warning(
							'Agent',
							'Tool progress callback failed but execution continued',
							array(
								'tool'  => $tc['name'],
								'id'    => $tc['id'] ?? '',
								'error' => $e->getMessage(),
							)
						);
					}
				};

				if ( is_object( $tool_object ) && method_exists( $tool_object, 'execute' ) ) {
					$context = PressArk_Tools::create_tool_context(
						function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
						$this->tier,
						$post_id,
						$screen,
						class_exists( 'PressArk_Policy_Engine' )
							? PressArk_Policy_Engine::CONTEXT_AGENT_READ
							: 'agent_read',
						$cancel_check,
						$this->engine,
						$progress_callback,
						array_filter(
							array(
								'run_id'      => $this->run_id,
								'chat_id'     => $this->chat_id,
								'round'       => $round,
								'source'      => 'agent_loop',
								'tool_use_id' => (string) ( $tc['id'] ?? '' ),
							),
							static function ( $value ) {
								return ! ( is_string( $value ) && '' === $value );
							}
						)
					);
					$result  = $tool_object->execute( (array) ( $tc['arguments'] ?? array() ), $context );
				} else {
					$result = $this->engine->execute_read( $tc['name'], $tc['arguments'], $progress_callback );
				}
				$result = $this->enforce_tool_result_limit( $result, $tc['name'], ! empty( $result_chunks ) );
				if ( class_exists( 'PressArk_Read_Metadata' ) && ! empty( $result['success'] ) ) {
					$result = PressArk_Read_Metadata::annotate_tool_result(
						$tc['name'],
						$tc['arguments'] ?? array(),
						$result
					);
				}

				if ( ! empty( $result_chunks ) ) {
					$streamed_entry_key = ! empty( $tc['id'] ) ? (string) $tc['id'] : $tool_progress_key;
					$streamed_entries[ $streamed_entry_key ] = array(
						'streamed_chunks'      => $result_chunks,
						'streamed_chunk_count' => count( $result_chunks ),
						'streamed_token_count' => $streamed_tokens,
						'streamed_progress'    => $this->merge_streamed_progress_chunks( $result_chunks ),
						'tool_key'             => $tool_progress_key,
					);
					$this->record_streamed_tool_summary( $tc, $result_chunks, $streamed_tokens );
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
						'id'       => $tc['id'] ?? '',
						'name'     => $tc['name'],
						'tool_key' => $this->tool_progress_key( $tc ),
						'success'  => true,
						'summary'  => $this->summarize_result( $result ),
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
				$stream_key   = $entry['tool_use_id'] ?? ( $execute_calls[ $j ]['id'] ?? '' );
				$stream_meta  = is_string( $stream_key ) && isset( $streamed_entries[ $stream_key ] )
					? $streamed_entries[ $stream_key ]
					: array();
				$slot_results[ $original_pos ] = array_merge(
					$entry,
					$stream_meta,
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
	 * Handle meta-tools (discover_tools, load_tools, load_tool_group, update_plan).
	 * Returns a tool result array if handled, or null for non-meta tools.
	 *
	 * @param array               $tc        Tool call from AI.
	 * @param PressArk_Tool_Loader $loader   Tool loader instance.
	 * @param array               &$tool_set Current tool set (modified by reference).
	 * @param array               &$tool_defs Current tool defs (modified by reference).
	 * @return array|null Tool result, or null if not a meta-tool.
	 */
	private function handle_meta_tool( array $tc, PressArk_Tool_Loader $loader, array &$tool_set, array &$tool_defs, ?PressArk_Checkpoint $checkpoint = null ): ?array {
		$name = $tc['name'];

		// ── discover_tools ──────────────────────────────────────────────
		if ( 'discover_tools' === $name ) {
			$this->discover_calls++;
			$query        = $tc['arguments']['query'] ?? '';
			$loaded_names = $tool_set['tool_names'] ?? array();

			// v3.2.0: Enforce discover call budget.
			if ( $this->discover_calls > $this->max_discover_calls ) {
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
						'message' => 'Discovery budget reached (' . $this->max_discover_calls . ' calls). Work with your currently loaded tools or respond to the user with what you know.',
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
			$results = $this->filter_discovery_results_for_mode( $results );

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
			$group = $tc['arguments']['group'] ?? '';
			$tools = $tc['arguments']['tools'] ?? array();

			if ( ! empty( $group ) && in_array( $group, $this->loaded_groups, true ) ) {
				$this->emit_step( 'reading', $name, $tc['arguments'] );
				$this->emit_step( 'done', $name, $tc['arguments'] );

				return array(
					'tool_use_id' => $tc['id'],
					'result'      => array(
						'success' => true,
						'message' => sprintf(
							'Group "%s" is already loaded — %d tools available. Use them directly without calling load_tools again.',
							$group,
							count( (array) ( $tool_set['tool_names'] ?? array() ) )
						),
					),
				);
			}

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
				$tool_set = $this->filter_tool_set_for_mode( $tool_set );
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
				$tool_set = $this->filter_tool_set_for_mode( $tool_set );
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

			if ( $checkpoint && ( ! empty( $group ) || ! empty( $tools ) ) ) {
				$this->persist_loaded_groups_to_checkpoint( $checkpoint );
			}

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
			$tool_set = $this->filter_tool_set_for_mode( $tool_set );
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

		// ── update_plan ─────────────────────────────────────────────────
		if ( 'update_plan' === $name ) {
			if ( ! $checkpoint ) {
				return array(
					'tool_use_id' => $tc['id'],
					'result'      => array(
						'success' => false,
						'message' => 'Plan storage is unavailable for this run.',
					),
				);
			}

			$checkpoint->set_plan_steps( (array) ( $tc['arguments']['steps'] ?? array() ) );
			$steps = $checkpoint->get_plan_steps();

			// v5.6.3: When the model marks a plan step `completed` with a known
			// tool_name, also mark the matching dynamic execution.tasks entry
			// done. Closes the O-2 stale-Remaining gap for non-write tools
			// (read_content, search_knowledge, update_plan itself) where
			// record_write doesn't fire.
			if ( class_exists( 'PressArk_Execution_Ledger' ) ) {
				$execution = PressArk_Execution_Ledger::adopt_plan_steps( $checkpoint->get_execution(), $steps );
				$mutated   = false;
				foreach ( $steps as $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status    = sanitize_key( (string) ( $step['status'] ?? '' ) );
					$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
					if ( 'completed' !== $status || '' === $tool_name ) {
						continue;
					}
					$evidence  = sanitize_text_field( (string) ( $step['activeForm'] ?? $step['content'] ?? "{$tool_name} completed via plan" ) );
					$execution = PressArk_Execution_Ledger::mark_dynamic_task_done_by_tool( $execution, $tool_name, $evidence );
					$mutated   = true;
				}
				if ( $mutated || ! empty( $steps ) ) {
					$checkpoint->set_execution( $execution );
				}
			}

			if ( $this->is_plan_mode() ) {
				$checkpoint->set_plan_phase( 'planning' );
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'ready' );
				}
			} elseif ( '' === $checkpoint->get_plan_phase() ) {
				$checkpoint->set_plan_phase( 'executing' );
				if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
					$checkpoint->set_plan_status( 'tracking' );
				}
			}

			$this->plan_steps = $this->plan_step_labels_from_rows( $steps );
			$active_index     = method_exists( $checkpoint, 'get_active_plan_step_index' )
				? (int) $checkpoint->get_active_plan_step_index()
				: -1;
			$this->plan_step  = $active_index >= 0 ? $active_index + 1 : max( 1, count( $this->plan_steps ) );

			// Signal the agent loop to exit now so the user sees a plan_ready
			// card without burning the rest of the round budget. Only fires in
			// plan mode and only when update_plan actually stored steps.
			if ( $this->is_plan_mode() && ! empty( $steps ) ) {
				$this->plan_just_proposed = true;
			}

			$this->emit_step( 'reading', $name, $tc['arguments'] );
			$this->emit_step( 'done', $name, $tc['arguments'] );

			return array(
				'tool_use_id' => $tc['id'],
				'result'      => array(
					'success' => ! empty( $steps ),
					'message' => '' !== $checkpoint->build_plan_summary( 6 )
						? 'Plan updated: ' . $checkpoint->build_plan_summary( 6 )
						: 'Plan updated.',
					'active_step' => method_exists( $checkpoint, 'get_active_plan_step' ) ? $checkpoint->get_active_plan_step() : array(),
				),
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
		$data               = $this->normalize_result_for_mode( $data, $checkpoint );
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
		$plan_artifact      = $checkpoint ? $this->plan_artifact_from_checkpoint( $checkpoint ) : $this->active_plan_artifact;
		$plan_phase         = $checkpoint ? $checkpoint->get_plan_phase() : '';

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
			// v5.8.3 (2026-05-13, iter-39): emit suggestions for cleanly
			// terminated Plan Mode chains (no_action, plan_no_action exit,
			// or post-Keep wrap rounds). Without this, Chain G ("Add FAQ to
			// menu" when FAQ already in menu) ended with NO chips — a
			// dead-end UX. iter-37/38 fixed the runtime token waste but the
			// observer was still stuck with no obvious next action.
			'suggestions'        => $this->should_suppress_suggestions( $data )
				? array()
				: $this->generate_suggestions(
					$data['message'] ?? $data['reply'] ?? '',
					$data
				),
			'mode'               => $this->mode,
			'planning_mode'      => $this->planning_mode,
			'planning_decision'  => $this->planning_decision,
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
			'plan_artifact'      => $plan_artifact,
			'plan_phase'         => $plan_phase,
			'approval_level'     => ! empty( $plan_artifact['approval_level'] ) ? (string) $plan_artifact['approval_level'] : '',
			'effective_visible_tools' => array_values( array_unique(
				(array) ( $tool_set['effective_visible_tools'] ?? $tool_set['tool_names'] ?? array() )
			) ),
			'permission_surface' => (array) ( $tool_set['permission_surface'] ?? array() ),
			'tool_state'         => $tool_state,
			'harness_state'      => $harness_state,
			'routing_decision'   => $this->routing_decision,
			'activity_events'    => $this->activity_events,
		) );
		if ( $this->context_tokens_used > 0 ) {
			$base['context_used']          = true;
			$base['context_tokens']        = max( 0, $this->context_tokens_used );
			$base['user_context_tokens']   = max( 0, $this->user_context_tokens_used );
			$base['system_context_tokens'] = max( 0, $this->system_context_tokens_used );
		}
		$context_inspector = $this->build_context_inspector( $tool_set, $checkpoint );
		if ( ! empty( $context_inspector ) ) {
			$base['context_inspector'] = $context_inspector;
		}

		if ( ! empty( $this->streamed_tool_summaries ) ) {
			$base['streamed_chunks']     = array_values( $this->streamed_tool_summaries );
			$base['streamed_token_count'] = $this->streamed_tool_token_count;
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
	// v5.8.3 (2026-05-13, iter-39): suppress suggestions only when the chain
	// is still in an interactive Plan Mode state (user is about to see the
	// plan_ready card or a preview/confirm card). When the chain has cleanly
	// terminated — via my iter-38 no_action or post_keep_wrap paths, or via
	// any error/cancellation — suggestions help the user pick the next move.
	private function should_suppress_suggestions( array $data ): bool {
		$type   = sanitize_key( (string) ( $data['type'] ?? 'final_response' ) );
		$status = sanitize_key( (string) ( $data['status'] ?? '' ) );
		$reason = sanitize_key( (string) ( $data['exit_reason'] ?? '' ) );

		// Plan-mode in mid-flight: classify or planning has emitted a
		// plan_ready card. Chips here would compete with Execute/Revise/Reject
		// and confuse the operator. Also covers soft_plan upgrade-to-hard_plan
		// cards where mode flips during the run.
		if ( 'plan_ready' === $type ) {
			return true;
		}
		// Preview/confirm cards have their own actions; chips would steal focus.
		if ( in_array( $type, array( 'preview', 'confirm_card' ), true ) ) {
			return true;
		}
		// Permission gates / queued runs / explicit cancellations.
		if ( in_array( $type, array( 'permission_required', 'queued' ), true ) ) {
			return true;
		}
		if ( ! empty( $data['cancelled'] ) || ! empty( $data['is_error'] ) ) {
			return true;
		}
		// Status==='exploring' means a Plan Mode chain that's still seeking
		// grounding; emit suggestions only once it has terminated.
		if ( 'exploring' === $status ) {
			return true;
		}
		// v5.8.8 (2026-05-14, iter-44): suppress chips on needs_input clarification terminals.
		// Day-2 S4/S7 ended with model questions; chips would distract from the user's answer.
		if ( 'needs_input' === $status && 'needs_input' === $reason ) {
			return true;
		}
		// All other Plan Mode exits — including no_action, plan_no_action,
		// post_keep_wrap, progress_complete — should get suggestions.
		unset( $reason );
		return false;
	}

	// v5.8.7 (2026-05-14, iter-43): commerce and user reads need chips from the same shared tool-trail dispatch as diagnostics.
	private static function build_tool_context_suggestions( array $tools_used ): array {
		$tools_used = array_values( array_unique( array_filter( array_map(
			'sanitize_key',
			array_map( 'strval', $tools_used )
		) ) ) );

		if ( empty( $tools_used ) ) {
			return array();
		}

		$dispatch = array(
			array(
				'tools' => array( 'measure_page_speed' ),
				'chips' => array(
					'Run another speed test with caching off',
					'Show me the longest scripts',
					'Suggest a cache plugin',
				),
			),
			array(
				'tools' => array( 'inspect_hooks' ),
				'chips' => array(
					'Show me the slowest hooks',
					'Disable a hook',
				),
			),
			array(
				'tools' => array( 'diagnose_cache' ),
				'chips' => array(
					'Install Redis object cache',
					'Show me caching plugin options',
				),
			),
			array(
				'tools' => array( 'analyze_seo' ),
				'chips' => array(
					'Fix the highest-impact issue',
					'Re-scan after changes',
				),
			),
			array(
				'tools' => array( 'scan_security' ),
				'chips' => array(
					'Auto-fix what you can',
					'Show the highest-risk finding first',
				),
			),
			array(
				'tools' => array( 'inventory_report', 'stock_report' ),
				'chips' => array(
					'Show products that are out of stock',
					'Update stock quantities',
					'Show me top-selling products',
				),
			),
			array(
				'tools' => array( 'get_product', 'analyze_store', 'get_products_on_sale', 'list_variations', 'list_product_attributes' ),
				'chips' => array(
					'Check product inventory',
					'Show products on sale',
					'Find products missing details',
				),
			),
			array(
				'tools' => array( 'list_orders', 'get_order', 'get_order_statuses' ),
				'chips' => array(
					'Show recent orders',
					'Check failed orders',
					'Show this month\'s revenue',
				),
			),
			array(
				'tools' => array( 'sales_summary', 'revenue_report', 'get_top_sellers', 'category_report' ),
				'chips' => array(
					'Show top-selling products',
					'Break down sales by category',
					'Show recent orders',
				),
			),
			array(
				'tools' => array( 'list_users', 'get_user' ),
				'chips' => array(
					'List users by role',
					'Show administrator details',
					'Change someone\'s role',
				),
			),
		);

		$suggestions = array();
		foreach ( $dispatch as $row ) {
			if ( empty( array_intersect( (array) $row['tools'], $tools_used ) ) ) {
				continue;
			}
			foreach ( (array) $row['chips'] as $chip ) {
				$suggestions[] = $chip;
			}
		}

		return array_values( array_unique( array_filter( $suggestions ) ) );
	}

	private function generate_suggestions( string $response_text, array $data = array() ): array {
		if ( '' === $response_text ) {
			return array();
		}

		// v5.8.5 (2026-05-14, iter-41): task_type is fixed at classify time,
		// so filter canned chips through the actual tool trail before showing
		// follow-ups like SEO fixes or undo actions.
		$tools_used = array_values( array_unique( array_filter( array_map(
			static fn( $s ) => sanitize_key( (string) ( is_array( $s ) ? ( $s['tool'] ?? '' ) : '' ) ),
			(array) $this->steps
		), static fn( $tool ) => '' !== $tool && '_' !== $tool[0] ) ) );

		$default_suggestions = array( 'Check my SEO', 'Any issues on my site?', 'What\'s new in my store?' );
		$finalize_suggestions = static function ( array $suggestions ) use ( $default_suggestions ): array {
			$suggestions = array_values( array_unique( array_filter( array_map( 'strval', $suggestions ) ) ) );
			if ( count( $suggestions ) < 1 ) {
				$suggestions = $default_suggestions;
			}
			return array_slice( $suggestions, 0, 3 );
		};

		$has_tool_matching = static function ( array $needles ) use ( $tools_used ): bool {
			foreach ( $tools_used as $tool ) {
				foreach ( $needles as $needle ) {
					if ( $tool === $needle || false !== strpos( $tool, $needle ) ) {
						return true;
					}
				}
			}
			return false;
		};

		$tool_is_write_like = static function ( string $tool ): bool {
			if ( '' === $tool ) {
				return false;
			}
			if ( class_exists( 'PressArk_Operation_Registry' ) ) {
				$kind = sanitize_key( (string) PressArk_Operation_Registry::classify( $tool ) );
				return in_array( $kind, array( 'preview', 'write', 'confirm' ), true );
			}
			return (bool) preg_match( '/^(create_|update_|edit_|rewrite_|delete_|trash_|fix_|apply_|bulk_)/', $tool );
		};

		$applied_writes = false;
		$preview_or_write_tool_ran = false;
		$write_ignore_tools = array( 'update_plan', 'load_tools', 'discover_tools', 'read_resource' );
		foreach ( (array) $this->steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$tool = sanitize_key( (string) ( $step['tool'] ?? '' ) );
			if ( '' === $tool || in_array( $tool, $write_ignore_tools, true ) || ! $tool_is_write_like( $tool ) ) {
				continue;
			}
			$preview_or_write_tool_ran = true;
			if ( 'done' === sanitize_key( (string) ( $step['status'] ?? '' ) ) ) {
				$applied_writes = true;
				break;
			}
		}

		$response_plain = wp_strip_all_tags( $response_text );
		$seo_tool_used = $has_tool_matching( array( 'fix_seo', 'analyze_seo', 'seo_audit', 'generate_meta', 'seo', 'meta' ) );
		$security_tool_used = $has_tool_matching( array( 'scan_security', 'fix_security', 'security', 'vulnerab', 'malware' ) );
		$seo_surface = $seo_tool_used || (bool) preg_match( '/\b(?:seo|meta)\b/i', $response_plain );
		$security_surface = $security_tool_used || (bool) preg_match( '/\b(?:vulnerab|security)\b/i', $response_plain );

		// v5.8.3 (2026-05-13, iter-39): no-action / wrap exits — suggest
		// next-explore moves that match the observed surface. Generic chips
		// like "Undo the changes" are misleading when no change was made;
		// pick context-aware reads instead.
		$exit_reason = sanitize_key( (string) ( $data['exit_reason'] ?? '' ) );
		$status      = sanitize_key( (string) ( $data['status'] ?? '' ) );
		if (
			in_array( $exit_reason, array( 'plan_no_action', 'progress_complete' ), true )
			|| 'no_action' === $status
			|| ! empty( $data['plan_card_obsolete'] )
		) {
			return $this->build_no_action_suggestions( $response_text );
		}

		$tool_context_suggestions = self::build_tool_context_suggestions( $tools_used );
		if ( ! empty( $tool_context_suggestions ) ) {
			return $finalize_suggestions( $tool_context_suggestions );
		}

		$type = $this->task_type;

		if ( in_array( $type, array( 'analyze', 'diagnose' ), true ) ) {
			$suggestions = array();
			if ( $seo_surface ) {
				$suggestions[] = 'Fix the SEO issues';
			}
			if ( $security_surface ) {
				$suggestions[] = 'Fix the security issues';
			}
			if ( $seo_surface || $security_surface ) {
				$suggestions[] = 'Auto-fix what you can';
			}
			return $finalize_suggestions( $suggestions );
		}

		if ( 'generate' === $type ) {
			$suggestions = array( 'Publish it' );
			if ( false !== stripos( $response_text, 'word' ) || strlen( $response_text ) > 1500 ) {
				$suggestions[] = 'Make it shorter';
			} else {
				$suggestions[] = 'Make it longer';
			}
			$suggestions[] = 'Change the tone';
			return $finalize_suggestions( $suggestions );
		}

		if ( 'edit' === $type ) {
			$suggestions = array();
			if ( $preview_or_write_tool_ran && $applied_writes ) {
				$suggestions[] = 'How does it look now?';
			}
			if ( ! $seo_tool_used ) {
				$suggestions[] = 'Check the SEO too';
			}
			if ( $applied_writes ) {
				$suggestions[] = 'Undo the changes';
			}
			return $finalize_suggestions( $suggestions );
		}

		if ( 'code' === $type ) {
			return $finalize_suggestions( array( 'Run it again', 'Explain what changed', 'Check for issues' ) );
		}

		// Fallback for chat and other task types.
		return $finalize_suggestions( $default_suggestions );
	}

	// v5.8.3 (2026-05-13, iter-39): build chips for no-action / wrap exits.
	// Pick categories of next-action by sniffing the response text and the
	// most-recent loaded groups. The chips are deliberately conservative —
	// safe explorations or natural follow-ups, never a write that the user
	// didn't ask for.
	private function build_no_action_suggestions( string $response_text ): array {
		$text   = strtolower( wp_strip_all_tags( $response_text ) );
		$groups = array_map( 'strval', (array) $this->loaded_groups );
		$has_group = static function ( string $needle ) use ( $groups ): bool {
			foreach ( $groups as $g ) {
				if ( false !== stripos( $g, $needle ) ) {
					return true;
				}
			}
			return false;
		};

		$suggestions = array();

		// Menu / navigation surface — "already in the menu", "menu", "nav".
		if (
			false !== strpos( $text, 'menu' )
			|| false !== strpos( $text, 'navigation' )
			|| $has_group( 'menu' )
		) {
			$suggestions[] = 'Show me all menu items';
			$suggestions[] = 'Reorder the menu';
		}

		// Page / post surface — "page exists", "already published", "is live at".
		if (
			false !== strpos( $text, 'page' )
			|| false !== strpos( $text, 'post' )
			|| false !== strpos( $text, 'published' )
			|| $has_group( 'content' )
		) {
			$suggestions[] = 'Edit this page';
			$suggestions[] = 'Check its SEO';
		}

		// SEO / security / performance scan results — "no issues found".
		if ( false !== strpos( $text, 'seo' ) || false !== strpos( $text, 'meta' ) ) {
			$suggestions[] = 'Run a full SEO audit';
		}
		if ( false !== strpos( $text, 'security' ) || false !== strpos( $text, 'vulnerab' ) ) {
			$suggestions[] = 'Run a deeper security scan';
		}

		if ( empty( $suggestions ) ) {
			// v5.8.5 (2026-05-14, iter-41): no-action text can be terse;
			// use the completed tool trail before falling back to generic chat.
			$tools_used = array_values( array_unique( array_filter( array_map(
				static fn( $s ) => sanitize_key( (string) ( is_array( $s ) ? ( $s['tool'] ?? '' ) : '' ) ),
				(array) $this->steps
			), static fn( $tool ) => '' !== $tool && '_' !== $tool[0] ) ) );

			$tool_suggestions = self::build_tool_context_suggestions( $tools_used );
			if ( ! empty( $tool_suggestions ) ) {
				return array_slice( array_values( array_unique( $tool_suggestions ) ), 0, 3 );
			}
		}

		// Always-useful continuation prompts when we don't have a specific cue.
		if ( empty( $suggestions ) ) {
			$suggestions = array(
				'What else needs attention?',
				'Any issues on my site?',
				'Show me my site overview',
			);
		} else {
			// Cap the cue-derived list and add one safe explore fallback.
			$suggestions = array_slice( array_values( array_unique( $suggestions ) ), 0, 2 );
			$suggestions[] = 'What else needs attention?';
		}

		return array_slice( $suggestions, 0, 3 );
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
				'content' => $this->uses_prompted_tool_transport()
					? 'Your previous response printed an invalid tool call. When a tool is needed, reply with JSON only using {"tool":"name","arguments":{...}} or {"tool_calls":[{"tool":"name","arguments":{...}}]}. Do not wrap the JSON in markdown or add extra prose. If no tool is needed, reply with a normal user-facing answer.'
					: 'Your previous response printed a tool call as plain text. Do not print tool calls, raw JSON, XML, or markdown representations of tools. If you need to act, use the native tool-calling interface. If no tool is needed, reply with a normal user-facing answer.',
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
	 * Recover old @tool_name key="value" action syntax emitted as assistant text.
	 *
	 * This is intentionally separate from leaked confirm-card recovery: @-syntax
	 * already names the intended tool and args, so retrying the model wastes the
	 * exact rounds this detector is meant to save.
	 *
	 * @param string   $text      Extracted assistant text.
	 * @param array    &$messages Conversation messages array, kept for parity with recovery hooks.
	 * @param int      $round     Current execution round.
	 * @param callable $emit_fn   SSE emit callback, kept for parity with recovery hooks.
	 * @param array    &$recovered_signatures Per-loop recovered action signatures.
	 * @return array|null Reconstructed tool call payload, final-response payload, or null when no @-syntax was detected.
	 */
	private function maybe_recover_at_syntax_tool_call(
		string $text,
		array &$messages,
		int $round,
		callable $emit_fn,
		array &$recovered_signatures
	): ?array {
		$tool_call = $this->reconstruct_at_syntax_tool_call( $text );
		if ( empty( $tool_call ) ) {
			return null;
		}

		$signature = md5(
			sanitize_key( (string) ( $tool_call['name'] ?? '' ) )
			. ':'
			. wp_json_encode( is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : array() )
		);
		if ( isset( $recovered_signatures[ $signature ] ) ) {
			$this->record_activity_event(
				'tool.recovery_skipped',
				'at_syntax_repeat_guard',
				'blocked',
				'agent',
				'Blocked a repeated text-only @-syntax tool call recovery in the same loop.',
				array(
					'round' => $round,
					'tool'  => sanitize_key( (string) ( $tool_call['name'] ?? '' ) ),
				)
			);

			return array(
				'type'    => 'final_response',
				'message' => 'I stopped before repeating the same recovered action again. Please retry the request if you still want me to continue.',
				'error'   => 'at_syntax_recovery_repeat_guard',
			);
		}
		$recovered_signatures[ $signature ] = true;

		$this->record_activity_event(
			'tool.recovered',
			'at_syntax_tool_call',
			'recovered',
			'agent',
			'Recovered a text-only @-syntax tool call without a retry round.',
			array(
				'round' => $round,
				'tool'  => sanitize_key( (string) ( $tool_call['name'] ?? '' ) ),
			)
		);

		PressArk_Error_Tracker::debug(
			'Agent',
			sprintf( 'Recovered @-syntax tool call in round %d as %s', $round, $tool_call['name'] ?? 'unknown' )
		);

		return array(
			'reconstructed_tool_call' => $tool_call,
		);
	}

	/**
	 * Parse one strict @tool_name args line outside markdown code blocks.
	 *
	 * @param string $text Assistant text content.
	 * @return array|null
	 */
	private function reconstruct_at_syntax_tool_call( string $text ): ?array {
		$text = trim( $this->strip_markdown_code_blocks( $text ) );
		if ( '' === $text ) {
			return null;
		}

		if ( ! preg_match_all( '/^[ \t]*@([a-z][a-z0-9_]{0,39})(?:[ \t]+([^\r\n]*))?[ \t]*$/m', $text, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $matches as $match ) {
			$raw_name = sanitize_key( (string) ( $match[1] ?? '' ) );
			if ( '' === $raw_name || false === strpos( $raw_name, '_' ) ) {
				continue;
			}

			if ( ! class_exists( 'PressArk_Operation_Registry' ) || ! method_exists( 'PressArk_Operation_Registry', 'exists' ) ) {
				continue;
			}

			$tool_name = PressArk_Operation_Registry::resolve_alias( $raw_name );
			if ( ! PressArk_Operation_Registry::exists( $tool_name ) ) {
				continue;
			}

			$argument_text = trim( (string) ( $match[2] ?? '' ) );
			$arguments     = $this->parse_at_syntax_tool_arguments( $argument_text );
			if ( '' !== $argument_text && empty( $arguments ) ) {
				continue;
			}

			$arguments = $this->normalize_at_syntax_tool_arguments( $tool_name, $arguments );

			return array(
				'id'        => 'recovered_at_' . substr( md5( $tool_name . ':' . wp_json_encode( $arguments ) . ':' . $text ), 0, 12 ),
				'name'      => $tool_name,
				'arguments' => $arguments,
			);
		}

		return null;
	}

	/**
	 * Remove fenced markdown blocks before scanning for executable @-syntax.
	 */
	private function strip_markdown_code_blocks( string $text ): string {
		$stripped = preg_replace( '/```[\s\S]*?```/', '', $text );
		return is_string( $stripped ) ? $stripped : $text;
	}

	/**
	 * Parse strict key=value pairs or a single JSON object after an @tool_name.
	 *
	 * @param string $argument_text Raw argument text after the tool name.
	 * @return array<string,mixed>
	 */
	private function parse_at_syntax_tool_arguments( string $argument_text ): array {
		$argument_text = trim( $argument_text );
		if ( '' === $argument_text ) {
			return array();
		}

		if ( str_starts_with( $argument_text, '{' ) && str_ends_with( $argument_text, '}' ) ) {
			$decoded = json_decode( $argument_text, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		$arguments = array();
		$offset    = 0;
		$length    = strlen( $argument_text );
		$pattern   = '/\G\s*,?\s*([a-z_][a-z0-9_]*)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,\s]+)/i';

		while ( $offset < $length ) {
			if ( ! preg_match( $pattern, $argument_text, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				return '' === trim( substr( $argument_text, $offset ), " \t\r\n," ) ? $arguments : array();
			}

			$full = (string) ( $match[0][0] ?? '' );
			if ( '' === $full ) {
				return array();
			}

			$key   = sanitize_key( (string) ( $match[1][0] ?? '' ) );
			$value = trim( (string) ( $match[2][0] ?? '' ) );
			if ( '' === $key || '' === $value ) {
				return array();
			}

			$arguments[ $key ] = $this->normalize_leaked_argument_value( $value );
			$offset            = (int) ( $match[0][1] ?? $offset ) + strlen( $full );
		}

		return $arguments;
	}

	/**
	 * Map old flat @action args onto canonical nested contracts where obvious.
	 *
	 * @param string $tool_name Tool name after alias resolution.
	 * @param array  $arguments Parsed scalar arguments.
	 * @return array
	 */
	private function normalize_at_syntax_tool_arguments( string $tool_name, array $arguments ): array {
		if ( empty( $arguments ) || isset( $arguments['changes'] ) || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return $arguments;
		}

		$contract = PressArk_Operation_Registry::get_parameter_contract( $tool_name );
		if ( ! is_array( $contract ) ) {
			return $arguments;
		}

		$properties     = is_array( $contract['properties'] ?? null ) ? $contract['properties'] : array();
		$changes_schema = is_array( $properties['changes'] ?? null ) ? $properties['changes'] : array();
		$change_props   = is_array( $changes_schema['properties'] ?? null ) ? array_keys( $changes_schema['properties'] ) : array();
		if ( empty( $change_props ) ) {
			return $arguments;
		}

		$changes = array();
		foreach ( $arguments as $key => $value ) {
			if ( in_array( $key, $change_props, true ) ) {
				$changes[ $key ] = $value;
				unset( $arguments[ $key ] );
			}
		}

		if ( ! empty( $changes ) ) {
			$arguments['changes'] = $changes;
		}

		return $arguments;
	}

	private function uses_prompted_tool_transport(): bool {
		$snapshot = $this->ai->get_last_request_snapshot();

		return 'prompted' === sanitize_key(
			(string) ( $snapshot['transport_contract']['tool_choice']['transport'] ?? '' )
		);
	}

	private function extract_prompted_tool_calls( string $content ): array {
		foreach ( $this->extract_prompted_tool_candidates( $content ) as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$calls = $this->normalize_prompted_tool_payload( $decoded );
			if ( ! empty( $calls ) ) {
				return $calls;
			}
		}

		return array();
	}

	private function extract_prompted_tool_candidates( string $content ): array {
		$content    = trim( $content );
		$candidates = array();

		if ( '' === $content ) {
			return $candidates;
		}

		$candidates[] = $content;

		if ( preg_match_all( '/```(?:json)?\s*([\s\S]*?)```/i', $content, $matches ) ) {
			foreach ( $matches[1] as $block ) {
				$block = trim( (string) $block );
				if ( '' !== $block ) {
					$candidates[] = $block;
				}
			}
		}

		$object_start = strpos( $content, '{' );
		$object_end   = strrpos( $content, '}' );
		if ( false !== $object_start && false !== $object_end && $object_end > $object_start ) {
			$candidates[] = trim( substr( $content, $object_start, ( $object_end - $object_start ) + 1 ) );
		}

		$array_start = strpos( $content, '[' );
		$array_end   = strrpos( $content, ']' );
		if ( false !== $array_start && false !== $array_end && $array_end > $array_start ) {
			$candidates[] = trim( substr( $content, $array_start, ( $array_end - $array_start ) + 1 ) );
		}

		return array_values( array_unique( array_filter( $candidates, 'strlen' ) ) );
	}

	private function normalize_prompted_tool_payload( array $payload ): array {
		if ( isset( $payload['tool_calls'] ) && is_array( $payload['tool_calls'] ) ) {
			return $this->normalize_prompted_tool_payload( $payload['tool_calls'] );
		}

		$items = $this->is_list_array( $payload ) ? $payload : array( $payload );
		$calls = array();

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name      = sanitize_key( (string) ( $item['tool'] ?? $item['name'] ?? '' ) );
			$arguments = $item['arguments'] ?? $item['params'] ?? array();

			if ( is_string( $arguments ) ) {
				$decoded   = json_decode( $arguments, true );
				$arguments = JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ? $decoded : array();
			}

			if ( '' === $name || ! is_array( $arguments ) ) {
				continue;
			}

			$calls[] = array(
				'id'        => 'prompted_' . substr( md5( $name . ':' . wp_json_encode( $arguments ) . ':' . (int) $index ), 0, 12 ),
				'name'      => $name,
				'arguments' => $arguments,
			);
		}

		return $calls;
	}

	private function is_list_array( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
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
	 * Remove internal execution metadata before persisting or returning calls.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls
	 * @return array<int,array<string,mixed>>
	 */
	private function strip_internal_tool_call_metadata( array $tool_calls ): array {
		return array_map(
			static function ( array $tool_call ): array {
				unset( $tool_call['_tool_contract'], $tool_call['_concurrency_safe'] );
				return $tool_call;
			},
			$tool_calls
		);
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

	/**
	 * Block run-scoped retry loops before another near-duplicate write executes.
	 *
	 * WooCommerce pricing writes are guarded first: after two uncertain pricing
	 * attempts on the same product, the next price write is replaced with a
	 * synthetic blocked result so the model must explain/escalate instead.
	 *
	 * @param array               $tool_calls Tool calls extracted from the model.
	 * @param PressArk_Checkpoint $checkpoint Active checkpoint with execution ledger.
	 * @param array               &$synthetic_results Synthetic tool results to append.
	 * @return array Filtered tool calls.
	 */
	private function filter_retry_guarded_tool_calls(
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

			$guard_result = PressArk_Execution_Ledger::maybe_block_wc_price_retry( $execution, $name, $args );
			if ( is_array( $guard_result ) && ! empty( $guard_result ) ) {
				$synthetic_results[] = array(
					'tool_use_id' => $tc['id'] ?? '',
					'result'      => $guard_result,
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
		$this->complete_current_plan_task( $checkpoint, array( 'read', 'analyze', 'verify' ), $tool_name );

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
		$this->sync_plan_artifact_from_execution( $checkpoint );
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
	 * Reuse a prior read result when the exact same read is still fresh.
	 *
	 * Reuse is conservative:
	 * - only readonly tools participate,
	 * - the tool must be explicitly cacheable (or be read_content),
	 * - the matching read-state snapshot must still be fresh,
	 * - the tool's own cache TTL must not have expired.
	 *
	 * @return array|null Cached result, or null to proceed with a real read.
	 */
	private function check_bundle_hit( PressArk_Checkpoint $checkpoint, string $tool_name, array $args ): ?array {
		if ( ! $this->tool_supports_bundle_reuse( $tool_name, $args ) ) {
			return null;
		}

		$bundle_match = $this->find_bundle_match( $checkpoint, $tool_name, $args );
		$bundle       = is_array( $bundle_match['payload'] ?? null ) ? $bundle_match['payload'] : array();
		$snapshot     = is_array( $bundle_match['snapshot'] ?? null ) ? $bundle_match['snapshot'] : array();
		$bundle_id    = sanitize_text_field( (string) ( $bundle_match['id'] ?? '' ) );

		if ( empty( $bundle ) || '' === $bundle_id || ! $this->bundle_payload_is_reusable( $tool_name, $args, $bundle, $snapshot ) ) {
			return null;
		}

		$result = is_array( $bundle['result'] ?? null ) ? $bundle['result'] : array();
		if ( empty( $result ) ) {
			return null;
		}

		if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			$result['data'] = array();
		}
		$result['cached']              = true;
		$result['stored_at']           = sanitize_text_field( (string) ( $bundle['stored_at'] ?? '' ) );
		$result['data']['bundle_hit'] = true;
		$result['data']['bundle_id']  = $bundle_id;
		if ( empty( $result['message'] ) ) {
			$summary = sanitize_text_field(
				(string) (
					$snapshot['summary']
					?? $result['data']['title']
					?? $bundle['tool_name']
					?? $tool_name
				)
			);
			$result['message'] = '' !== $summary
				? sprintf( 'Reused cached %s result for %s.', $tool_name, $summary )
				: sprintf( 'Reused cached %s result.', $tool_name );
		}
		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$result = PressArk_Read_Metadata::annotate_tool_result(
				$tool_name,
				$args,
				$result,
				array(
					'freshness'   => 'cached',
					'provider'    => 'bundle_cache',
					'captured_at' => sanitize_text_field(
						(string) (
							$bundle['stored_at']
							?? $snapshot['captured_at']
							?? gmdate( 'c' )
						)
					),
					'stored_at'   => sanitize_text_field( (string) ( $bundle['stored_at'] ?? '' ) ),
				)
			);
		}

		return $result;
	}

	/**
	 * Find the best matching bundle for a read call.
	 *
	 * Prefers the deterministic bundle ID, then falls back to matching the
	 * read fingerprint across checkpoint-owned bundles so alias/call-shape
	 * differences can still reuse the same prior read.
	 *
	 * @return array{id: string, payload: array, snapshot: array}|array{}
	 */
	private function find_bundle_match( PressArk_Checkpoint $checkpoint, string $tool_name, array $args ): array {
		$snapshot    = $this->find_read_snapshot( $checkpoint, $tool_name, $args );
		$fingerprints = $this->bundle_lookup_fingerprints( $tool_name, $args );
		$candidate_ids = array_values(
			array_unique(
				array_filter(
					array_merge(
						array( PressArk_Checkpoint::compute_bundle_id( $tool_name, $args ) ),
						$this->bundle_alias_candidate_ids( $tool_name, $args )
					)
				)
			)
		);

		foreach ( $candidate_ids as $bundle_id ) {
			if ( ! $checkpoint->has_bundle( $bundle_id ) ) {
				continue;
			}

			$payload = PressArk_Checkpoint::get_bundle_payload( $bundle_id );
			if (
				is_array( $payload )
				&& ! empty( $payload )
				&& $this->bundle_payload_is_reusable( $tool_name, $args, $payload, $snapshot )
			) {
				return array(
					'id'       => sanitize_text_field( (string) $bundle_id ),
					'payload'  => $payload,
					'snapshot' => $snapshot,
				);
			}
		}

		if ( empty( $fingerprints ) ) {
			return array();
		}

		foreach ( $checkpoint->get_bundle_ids() as $bundle_id ) {
			$bundle_id = sanitize_text_field( (string) $bundle_id );
			if ( '' === $bundle_id ) {
				continue;
			}

			$payload = PressArk_Checkpoint::get_bundle_payload( $bundle_id );
			if ( ! is_array( $payload ) || empty( $payload ) ) {
				continue;
			}

			if (
				array_intersect( $fingerprints, $this->bundle_payload_fingerprints( $payload ) )
				&& $this->bundle_payload_is_reusable( $tool_name, $args, $payload, $snapshot )
			) {
				return array(
					'id'       => $bundle_id,
					'payload'  => $payload,
					'snapshot' => $snapshot,
				);
			}
		}

		return array();
	}

	/**
	 * Check whether a bundle payload is still safe to reuse.
	 */
	private function bundle_payload_is_reusable( string $tool_name, array $args, array $bundle, array $snapshot = array() ): bool {
		$result = is_array( $bundle['result'] ?? null ) ? $bundle['result'] : array();
		if ( empty( $result['success'] ) ) {
			return false;
		}

		$tool_name = sanitize_key( $tool_name );
		$operation = PressArk_Operation_Registry::resolve( $tool_name );
		if ( 'read_content' !== $tool_name && ! ( $operation instanceof PressArk_Operation && $operation->is_cacheable() ) ) {
			return false;
		}

		if ( ! empty( $snapshot ) ) {
			if ( 'stale' === sanitize_key( (string) ( $snapshot['freshness'] ?? '' ) ) ) {
				return false;
			}
		} elseif ( 'read_content' !== $tool_name ) {
			return false;
		}

		if ( $operation instanceof PressArk_Operation && $operation->cache_ttl > 0 ) {
			$captured_at = (string) ( $bundle['stored_at'] ?? $snapshot['captured_at'] ?? '' );
			$captured_ts = '' !== $captured_at ? strtotime( $captured_at ) : 0;
			if ( $captured_ts <= 0 || ( time() - $captured_ts ) > $operation->cache_ttl ) {
				return false;
			}
		}

		if ( 'read_content' === $tool_name ) {
			$post_id = (int) ( $args['post_id'] ?? $bundle['post_id'] ?? $result['data']['id'] ?? 0 );
			if ( $post_id <= 0 ) {
				return false;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return false;
			}

			$stored_modified  = (string) ( $bundle['post_modified'] ?? '' );
			$current_modified = (string) ( $post->post_modified_gmt ?: $post->post_modified );
			if ( $stored_modified && $current_modified && $stored_modified !== $current_modified ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether a successful read result should be bundled.
	 */
	private function should_bundle_read_result( string $tool_name, array $args, array $result = array() ): bool {
		if ( empty( $result['success'] ) || $this->is_tool_result_limit_result( $result ) ) {
			return false;
		}

		$contract = $this->resolve_tool_contract( $tool_name, $args );
		if ( empty( $contract['readonly'] ) ) {
			return false;
		}

		$tool_name = sanitize_key( $tool_name );
		if ( 'read_content' === $tool_name ) {
			return true;
		}

		$operation = PressArk_Operation_Registry::resolve( $tool_name );
		return $operation instanceof PressArk_Operation && $operation->is_cacheable();
	}

	/**
	 * Find the current read-state snapshot for a read call.
	 */
	private function find_read_snapshot( PressArk_Checkpoint $checkpoint, string $tool_name, array $args ): array {
		if ( ! class_exists( 'PressArk_Read_Metadata' ) ) {
			return array();
		}

		$fingerprints = $this->bundle_lookup_fingerprints( $tool_name, $args );
		if ( empty( $fingerprints ) ) {
			return array();
		}

		foreach ( PressArk_Read_Metadata::sanitize_snapshot_collection( $checkpoint->get_read_state() ) as $snapshot ) {
			$handle            = sanitize_text_field( (string) ( $snapshot['handle'] ?? '' ) );
			$query_fingerprint = sanitize_text_field( (string) ( $snapshot['query_fingerprint'] ?? '' ) );
			if ( in_array( $handle, $fingerprints, true ) || in_array( $query_fingerprint, $fingerprints, true ) ) {
				return $snapshot;
			}
		}

		return array();
	}

	/**
	 * Build stable lookup fingerprints for a read call.
	 *
	 * Includes both the requested tool name and the canonical operation name so
	 * aliases can still find the same cached payload.
	 *
	 * @return string[]
	 */
	private function bundle_lookup_fingerprints( string $tool_name, array $args ): array {
		if ( ! class_exists( 'PressArk_Read_Metadata' ) ) {
			return array();
		}

		$names     = array( sanitize_key( $tool_name ) );
		$operation = PressArk_Operation_Registry::resolve( $tool_name );
		if ( $operation instanceof PressArk_Operation ) {
			$names[] = sanitize_key( $operation->name );
		}

		$fingerprints = array();
		foreach ( array_values( array_unique( array_filter( $names ) ) ) as $name ) {
			$fingerprints[] = PressArk_Read_Metadata::build_query_fingerprint( 'tool', $name, $args );
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $fingerprints ) ) ) );
	}

	/**
	 * Compute alternate bundle IDs for canonical tool aliases.
	 *
	 * @return string[]
	 */
	private function bundle_alias_candidate_ids( string $tool_name, array $args ): array {
		$operation = PressArk_Operation_Registry::resolve( $tool_name );
		if ( ! ( $operation instanceof PressArk_Operation ) ) {
			return array();
		}

		$canonical = sanitize_key( $operation->name );
		if ( '' === $canonical || $canonical === sanitize_key( $tool_name ) ) {
			return array();
		}

		return array( PressArk_Checkpoint::compute_bundle_id( $canonical, $args ) );
	}

	/**
	 * Extract lookup fingerprints from a stored bundle payload.
	 *
	 * @return string[]
	 */
	private function bundle_payload_fingerprints( array $bundle ): array {
		$fingerprints = array();

		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$stored_meta = PressArk_Read_Metadata::sanitize_snapshot( $bundle['result']['read_meta'] ?? array() );
			foreach ( array( $stored_meta['query_fingerprint'] ?? '', $stored_meta['handle'] ?? '' ) as $fingerprint ) {
				$fingerprint = sanitize_text_field( (string) $fingerprint );
				if ( '' !== $fingerprint ) {
					$fingerprints[] = $fingerprint;
				}
			}
		}

		$stored_tool = sanitize_key( (string) ( $bundle['tool_name'] ?? '' ) );
		$stored_args = is_array( $bundle['args'] ?? null ) ? (array) $bundle['args'] : array();
		$fingerprints = array_merge( $fingerprints, $this->bundle_lookup_fingerprints( $stored_tool, $stored_args ) );

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $fingerprints ) ) ) );
	}

	/**
	 * Check whether a tool participates in read-result reuse.
	 */
	private function tool_supports_bundle_reuse( string $tool_name, array $args ): bool {
		$contract = $this->resolve_tool_contract( $tool_name, $args );
		if ( empty( $contract['readonly'] ) ) {
			return false;
		}

		$tool_name = sanitize_key( $tool_name );
		if ( 'read_content' === $tool_name ) {
			return true;
		}

		$operation = PressArk_Operation_Registry::resolve( $tool_name );
		return $operation instanceof PressArk_Operation && $operation->is_cacheable();
	}

	/**
	 * Record a bundle payload after a successful reusable read.
	 */
	private function record_bundle( PressArk_Checkpoint $checkpoint, string $tool_name, array $args, array $result = array() ): void {
		if ( ! $this->should_bundle_read_result( $tool_name, $args, $result ) ) {
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
			$checkpoint,
			$messages
		);
		// v5.6.2: On wrap rounds, also strip the tool array — there's no write
		// to make. With tool_choice unset and tools=[], the model can only emit
		// text. Saves ~70-96k chars per wrap on top of the sys[1] trim.
		// Detection mirrors the wrap-trim in build_round_prompt_sections; the
		// flag is set there and propagated via $prompt_sections['is_wrap_round'].
		if ( ! empty( $prompt_sections['is_wrap_round'] )
			&& isset( $tool_set['schemas'] )
			&& is_array( $tool_set['schemas'] )
			&& ! empty( $tool_set['schemas'] )
		) {
			$tool_set['_wrap_stripped_tool_count'] = count( $tool_set['schemas'] );
			$tool_set['schemas']                   = array();
			$tool_set['descriptors']               = '';
			$tool_set['capability_map']            = '';
		}
		$descriptors = trim( (string) ( $tool_set['descriptors'] ?? '' ) );
		if ( '' !== $descriptors ) {
			$this->append_round_prompt_section(
				$prompt_sections['stable'],
				$prompt_sections['labels']['stable'],
				'tool_descriptors',
				"## Tool Descriptors\n" . $descriptors
			);
		}
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
		PressArk_Checkpoint $checkpoint,
		array $messages = array()
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
		if ( defined( 'PRESSARK_DEBUG_ROUTE' ) && PRESSARK_DEBUG_ROUTE && class_exists( 'PressArk_Planning_Policy' ) && PressArk_Planning_Policy::route_debug_env_ok() ) {
			$log_path = defined( 'PRESSARK_DEBUG_ROUTE_LOG' ) ? (string) PRESSARK_DEBUG_ROUTE_LOG : '/tmp/pressark-route.log';
			@file_put_contents(
				$log_path,
				sprintf( "[%s] AGENT planning_mode=%s mode=%s\n", gmdate( 'H:i:s' ), $this->planning_mode, $this->mode ),
				FILE_APPEND
			);
		}

		// v5.6.1: Wrap-round detection. When the chain just kept a preview write
		// and PressArk is dispatching a [Continue] re-pump, the next round is
		// typically a text summary — the model doesn't need Plan Mode rules,
		// Plan Execution Contract, Block Editor / Content Generation knowledge,
		// task-scoped skills, the site playbook, or the "continue executing"
		// nudge. Strip them so the wrap round stays cheap.
		//
		// Observed 2026-05-12 on iter-7 chain B W8: the wrap round shipped
		// 151k chars (96k of tools + 16k of sys[1]) for a 277-byte text reply.
		// Volatile state (Trusted System Facts, Verified Evidence, Harness
		// State, Recent Approved Writes, etc.) stays — the model needs it to
		// produce an accurate summary.
		//
		// Detection — what we tried and why it didn't work:
		//   v1 attempt: progress.is_complete AND is_continuation_message($message).
		//     Failed on iter-8 X8: $message is the original user request, not
		//     the latest [Continue] envelope, so is_continuation_message returned
		//     false.
		//   v2 attempt: progress.is_complete alone.
		//     Failed on iter-8 Y6: the [Continue] envelope's "Remaining" field
		//     still listed every just-done step (the stale-snapshot bug O-2),
		//     so the execution-ledger's progress_snapshot reported is_complete
		//     as false even though the chain was clearly in wrap.
		//
		// Working signal: the LATEST user-role message in $messages is a
		// [Continue]/[Confirmed] re-pump (NB: $message is the ORIGINAL user
		// request — it doesn't update per round — so we have to look at the
		// full $messages array to find the synthetic re-pump), AND the most
		// recent approval outcome on the checkpoint is 'approved'. Together
		// these mean the chain just kept a preview write and the harness is
		// re-pumping; the natural next round is a summary, regardless of
		// whether the ledger snapshot caught up to it.
		$is_wrap_round = false;
		$latest_user_msg = '';
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( ( $messages[ $i ]['role'] ?? '' ) === 'user' ) {
				$content = $messages[ $i ]['content'] ?? '';
				if ( is_string( $content ) ) {
					$latest_user_msg = $content;
				}
				break;
			}
		}
		if ( self::is_continuation_message( $latest_user_msg )
			&& method_exists( $checkpoint, 'get_approval_outcomes' )
		) {
			$approvals_for_wrap = (array) $checkpoint->get_approval_outcomes();
			if ( ! empty( $approvals_for_wrap ) ) {
				$latest_outcome = end( $approvals_for_wrap );
				$latest_status  = is_array( $latest_outcome )
					? sanitize_key( (string) ( $latest_outcome['status'] ?? '' ) )
					: '';
				$is_wrap_round  = ( 'approved' === $latest_status );
				if ( $is_wrap_round && class_exists( 'PressArk_Continuation_Service' ) ) {
					$wrap_decision = PressArk_Continuation_Service::evaluate(
						$checkpoint,
						method_exists( $checkpoint, 'get_execution' ) ? (array) $checkpoint->get_execution() : array(),
						array( 'latest_user_message' => $latest_user_msg )
					);
					$is_wrap_round = ! empty( $wrap_decision['should_emit_wrap_round'] );
				}
			}
		}
		// Surface the flag for build_round_system_prompt to also strip tools.
		$sections['is_wrap_round'] = $is_wrap_round;

		if ( $is_wrap_round ) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'wrap_mode',
				"## Wrap Mode\nThe approved plan is complete and all writes were applied. Emit a brief text summary (1-3 sentences) of what was done, naming the post id(s) and the field(s) that changed. Do not call tools. Do not propose new write tools or new tasks the user did not ask for — if you noticed adjacent improvements worth doing later, mention them in passing as suggestions only."
			);
		} elseif ( 'hard_plan' === $this->planning_mode ) {
			self::ensure_plan_mode_loaded();
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'plan_mode',
				"## Plan Mode\n" . PressArk_Plan_Mode::get_system_prompt()
			);
		} elseif ( $this->is_soft_plan_mode() ) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'soft_plan_mode',
				"## Soft Plan Mode\nStart by grounding the current state with relevant read tools, but when the request clearly needs tracked multi-step work you should emit update_plan in the SAME response as your first grounding read(s) instead of spending a round on standalone exploratory tool calls first. Once the reads have grounded the request, briefly state the contained plan you will follow and then continue automatically. Trust the soft-plan route once it has been chosen, and do not stop for a hard plan just because the work spans several contained steps, previews, or closely related edits. Read the current target state before any write and use native WordPress/WooCommerce tools for domain data. For new posts and pages, default to create_post unless the user explicitly asks for Elementor or the task is specifically about Elementor. For WooCommerce price work, explicit sale requests map to sale_price, increase/decrease/current-price or regular-price requests map to regular_price or a relative regular_price adjustment, and ambiguous wording about sale price vs regular price requires a brief clarification before any price write. Preserve regular_price when removing a sale unless the user explicitly asked to change it, and if a tool fails or verification disagrees, stop repeating the same fix and choose a materially different next step. Escalate to a hard plan only if the work turns destructive or the intended write target becomes clearly broad or ambiguous."
			);
		}
		$plan_phase_for_contract = method_exists( $checkpoint, 'get_plan_phase' )
			? (string) $checkpoint->get_plan_phase()
			: '';
		$should_append_plan_execution_contract = ! $is_wrap_round && (
			$this->is_soft_plan_mode()
			|| 'executing' === $plan_phase_for_contract
			|| ( 'hard_plan' === $this->planning_mode && ! $this->is_plan_mode() )
		);
		if ( $should_append_plan_execution_contract ) {
			// v5.8.13 (2026-05-14): keep read-only hard Plan Mode free of write-execution contract text.
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'plan_execution_contract',
				'## Plan Execution Contract' . "\n"
				. 'MANDATORY parallel emission: ANY write tool call (create_post, edit_content, update_meta, delete_content, bulk writes, Elementor writes, etc.) MUST be emitted in the SAME response as an `update_plan` call that sets exactly one step to in_progress whose tool_name matches that write. Solo writes are rejected by plan_step_guard — that costs a wasted round AND leaves the harness with no plan step to mark completed after preview/keep, which risks the write being re-triggered on the next round as a duplicate. This rule applies even for single-write tasks (a 1-step plan is still required). For multi-step tasks: initialize the plan with update_plan as soon as you know tracked steps are needed, and whenever possible emit update_plan in the SAME response as the first grounding read(s) so the plan is in place from round 1. STEP ADVANCEMENT — when the prior step just finished (e.g., step N was a read that returned its tool_result, and you are about to emit step N+1): emit `update_plan(mark step N completed, step N+1 in_progress) + tool_for_step_N+1` ATOMICALLY in one response. Never emit step N+1 solo just because it feels natural after the read — the plan still shows step N as in_progress and the guard will reject. The update_plan that advances the ledger and the tool that executes the new step belong in the same tool_calls array, always. Once a plan exists, every subsequent tool call must match the in_progress step (or you must call update_plan again to change steps). Keep exactly one step in_progress at a time. Use activeForm to describe the live step you are working on. Mark non-preview steps completed immediately after real success. For preview-required steps (create_post, edit_content, update_meta, delete_content, bulk writes, etc.) the harness auto-completes the matching plan step server-side once the user keeps the preview — do NOT emit a follow-up update_plan call just to mark such a step completed; that costs an entire wasted round. After a preview-keep, your next response should either (a) emit `update_plan(advance to step N+1 in_progress) + next_tool` atomically if more steps remain, or (b) emit a short text reply summarizing the result if the plan is fully done. Include tool_name when known so the harness can keep you on the correct step.'
			);
		}
		$site_notes      = $this->resolve_site_notes( $message );
		$playbook_groups = $this->resolve_playbook_tool_groups( $tool_set );
		$playbook        = $this->resolve_site_playbook( $task_type, $playbook_groups, $message );
		$verification    = PressArk_Execution_Ledger::verification_summary( $checkpoint->get_execution() );
		$read_strata  = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::build_prompt_strata( $checkpoint->get_read_state(), $verification, $site_notes )
			: array();
		$trusted_reads = trim( (string) preg_replace( '/^##\s+Trusted System Facts\s*/i', '', (string) ( $read_strata['trusted_system'] ?? '' ) ) );
		$context_block = trim( $context->build( $screen, $post_id ) );
		// v5.6.8 (2026-05-12): When there are no trusted-read entries, just emit
		// the context block directly. The context block already starts with its
		// own `## Current Context` header, so wrapping it in an outer
		// `## Trusted System Facts` produces a header-within-a-header pattern
		// with the outer header sitting on an empty body line. Observed in the
		// SEO audit capture: sys[1] section dump showed `## Trusted System
		// Facts (0 chars)` immediately followed by `## Current Context (488
		// chars)`. The header wraps nothing useful — drop it when empty.
		if ( '' === $trusted_reads ) {
			$trusted_system_block = $context_block;
		} else {
			$trusted_parts = array_filter( array( $context_block, $trusted_reads ) );
			$trusted_system_block = empty( $trusted_parts )
				? ''
				: "## Trusted System Facts\n" . PressArk_AI_Connector::join_prompt_sections( $trusted_parts );
		}

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

		// v5.6.1: Skip the Block Editor / Content Generation / Conditional knowledge
		// blocks on wrap rounds — they're guidance for writing, not summarizing.
		$conditional_blocks = $is_wrap_round
			? ''
			: PressArk_AI_Connector::get_conditional_blocks(
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

		// v5.6.1: Task-scoped skills are write-time guidance; skip on wrap.
		$task_skills = $is_wrap_round
			? ''
			: PressArk_Skills::get_dynamic_task_scoped( $task_type, array(
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
			$is_wrap_round ? '' : (string) ( $playbook['text'] ?? '' )
		);

		$execution_guard = PressArk_Execution_Ledger::build_runtime_guard( $checkpoint->get_execution() );
		$approved_artifact = $this->plan_artifact_from_checkpoint( $checkpoint );
		// v5.6.1: Don't tell the model "continue executing the plan" when the
		// plan is already complete — that nudges scope creep.
		if ( ! $is_wrap_round
			&& ! $this->is_plan_mode()
			&& ! empty( $approved_artifact )
			&& 'executing' === $checkpoint->get_plan_phase()
		) {
			$this->append_round_prompt_section(
				$sections['stable'],
				$sections['labels']['stable'],
				'approved_plan_execution',
				"## Approved Plan Execution\nA plan is already approved and in progress. Continue executing that plan instead of proposing a new one. Reuse the grounded reads already present in the transcript and read-state blocks before calling more read tools; only reread when a needed fact is missing or marked stale. Do not stop for another plan or approval boundary unless the user's goal changed or the approved plan became materially unsafe because the target or scope is now broad, destructive, or ambiguous."
			);
		}
		if ( ! empty( $execution_guard ) && self::is_continuation_message( $message ) ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'execution_guard',
				$execution_guard
			);
		}

		$recent_approved_writes = array();
		if ( self::is_continuation_message( $message ) && method_exists( $checkpoint, 'get_approval_outcomes' ) ) {
			foreach ( array_slice( (array) $checkpoint->get_approval_outcomes(), -6 ) as $approval_outcome ) {
				if ( ! is_array( $approval_outcome ) ) {
					continue;
				}
				if ( 'approved' !== sanitize_key( (string) ( $approval_outcome['status'] ?? '' ) ) ) {
					continue;
				}

				$action = sanitize_key( (string) ( $approval_outcome['action'] ?? 'write' ) );
				$scope  = sanitize_key( (string) ( $approval_outcome['scope'] ?? '' ) );
				$line   = '' !== $action ? $action : 'write';
				if ( '' !== $scope ) {
					$line .= ' via ' . $scope;
				}
				$recent_approved_writes[] = $line;
			}
		}
		if ( ! empty( $recent_approved_writes ) ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'recent_approved_writes',
				"## Recent Approved Writes\n"
				. "These approvals were already settled and the underlying writes were applied in this run:\n- "
				. implode( "\n- ", array_values( array_unique( $recent_approved_writes ) ) )
				. "\nDo not ask for the same preview or confirmation again unless you are proposing a new write outside that settled scope."
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

		// v5.6.8 (2026-05-12): When the structured `## Approved Plan Artifact`
		// block is emitted, the per-step `[STATUS]` markers it now carries
		// (see PressArk_Plan_Artifact::to_prompt_block) supersede the prose
		// "Current plan: 1) X [IN PROGRESS], 2) Y [PENDING]…" block. Skip the
		// prose duplicate to avoid two representations of the same data in
		// the same system message (sub-agent observation 2026-05-12 SEO audit
		// capture: same plan emitted twice with risk of divergence after a
		// ledger-sync race). Track which path we took so the prose form
		// remains the fallback for the non-executing case.
		$emitted_structured_plan = false;
		if ( ! empty( $approved_artifact ) && 'executing' === $checkpoint->get_plan_phase() && class_exists( 'PressArk_Plan_Artifact' ) ) {
			$plan_block = PressArk_Plan_Artifact::to_prompt_block( $approved_artifact );
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'execution_plan',
				$plan_block
			);
			$emitted_structured_plan = true;
		} elseif ( count( $this->plan_steps ) > 1 ) {
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
		if ( ! $emitted_structured_plan && $this->should_inject_plan_summary_prompt( $checkpoint ) ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'plan_summary',
				$this->build_plan_prompt_summary_block( $checkpoint )
			);
		}
		if ( '' !== $this->plan_stall_message ) {
			$this->append_round_prompt_section(
				$sections['volatile'],
				$sections['labels']['volatile'],
				'plan_stall',
				'## Plan Stall' . "\n" . $this->plan_stall_message
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
			'soft_plan_mode'        => 'Soft Plan Mode',
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
		$context_metrics = is_array( $provider_request['context'] ?? null ) ? (array) $provider_request['context'] : array();
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
			'context_tokens'         => (int) ( $context_metrics['context_tokens'] ?? 0 ),
			'user_context_tokens'    => (int) ( $context_metrics['user_context_tokens'] ?? 0 ),
			'system_context_tokens'  => (int) ( $context_metrics['system_context_tokens'] ?? 0 ),
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
			'deferred_candidates'     => (array) ( $tool_set['deferred_candidates'] ?? $tool_set['deferred_groups'] ?? array() ),
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
			'deferred_candidates'     => (array) ( $tool_set['deferred_candidates'] ?? $tool_set['deferred_groups'] ?? array() ),
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
			'deferred_candidates' => (array) ( $tool_set['deferred_candidates'] ?? $tool_set['deferred_groups'] ?? array() ),
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
			'deferred_candidates' => (array) ( $tool_set['deferred_candidates'] ?? $tool_set['deferred_groups'] ?? array() ),
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
	private function enforce_tool_result_limit( array $result, string $tool_name, bool $already_streamed = false ): array {
		if ( $already_streamed ) {
			return $result;
		}

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
		// Only append the incoming message when it isn't already the last
		// user message in history. The chat handler typically persists the
		// user's prompt to history before invoking the loop, so appending
		// unconditionally produces a duplicate user turn that the model sees
		// twice in row.
		$last = end( $fallback_messages );
		$last_is_same_user = is_array( $last )
			&& 'user' === ( $last['role'] ?? '' )
			&& trim( (string) ( $last['content'] ?? '' ) ) === trim( $message );
		if ( ! $last_is_same_user ) {
			$fallback_messages[] = array( 'role' => 'user', 'content' => $message );
		}

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
				$messages   = $this->strip_replay_checkpoint_headers( $messages );
				$replay_last = end( $messages );
				$replay_last_same = is_array( $replay_last )
					&& 'user' === ( $replay_last['role'] ?? '' )
					&& trim( (string) ( $replay_last['content'] ?? '' ) ) === trim( $message );
				if ( ! $replay_last_same ) {
					$messages[] = array( 'role' => 'user', 'content' => $message );
				}
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
	 * Drop synthetic checkpoint header messages from persisted replay state.
	 *
	 * These headers are useful when compressing a live conversation for a
	 * single provider request, but once they are stored inside replay state
	 * they can become stale and contradict the current execution phase on
	 * continuation turns. The current checkpoint and system prompt already
	 * carry the authoritative state, so replay can safely omit them.
	 *
	 * @param array $messages Replay transcript candidate.
	 * @return array
	 */
	private function strip_replay_checkpoint_headers( array $messages ): array {
		if ( empty( $messages ) ) {
			return $messages;
		}

		$filtered = array();
		$changed  = false;

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				$filtered[] = $message;
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
			$content = (string) ( $message['content'] ?? '' );

			// Match synthetic headers regardless of role — they used to be
			// emitted as role=user but are now role=system. Content pattern
			// is strict (regex on "[Conversation State (turn N)]" prefix)
			// so this won't catch anything legitimate.
			if ( in_array( $role, array( 'user', 'system' ), true ) && self::is_synthetic_checkpoint_header_message( $content ) ) {
				$changed = true;
				continue;
			}

			$filtered[] = $message;
		}

		return $changed ? array_values( $filtered ) : $messages;
	}

	/**
	 * Synthetic checkpoint headers always start with the standard
	 * conversation-state prefix emitted by PressArk_Checkpoint::to_context_header().
	 */
	private static function is_synthetic_checkpoint_header_message( string $content ): bool {
		return 1 === preg_match( '/^\[Conversation State \(turn \d+\)\]/', ltrim( $content ) );
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

		$messages            = $this->strip_replay_checkpoint_headers( $messages );
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
				if (
					! empty( $tr['_artifactized'] )
					|| ! empty( $tr['result']['_artifactized'] )
					|| ! empty( $tr['streamed_chunks'] )
				) {
					return $tr;
				}

				$tr['result'] = $this->compact_tool_result( $tr['result'] );
				return $tr;
			},
			$prepared
		);
	}

	/**
	 * Record compact stream metadata for the final response without changing the
	 * tool result payload that gets sent back to the model.
	 *
	 * @param array[] $chunks Streamed progress chunks.
	 */
	private function record_streamed_tool_summary( array $tc, array $chunks, int $token_count ): void {
		$this->streamed_tool_summaries[] = array_filter(
			array(
				'tool'        => sanitize_key( (string) ( $tc['name'] ?? '' ) ),
				'id'          => sanitize_text_field( (string) ( $tc['id'] ?? '' ) ),
				'tool_key'    => $this->tool_progress_key( $tc ),
				'chunk_count' => count( $chunks ),
				'token_count' => max( 0, $token_count ),
				'progress'    => $this->merge_streamed_progress_chunks( $chunks ),
			),
			static function ( $value ) {
				return ! ( is_string( $value ) && '' === $value );
			}
		);
		$this->streamed_tool_token_count += max( 0, $token_count );
	}

	/**
	 * Keep only the latest progress snapshot for top-level response metadata.
	 */
	private function merge_streamed_progress_chunks( array $chunks ): array {
		if ( empty( $chunks ) ) {
			return array();
		}

		$last = end( $chunks );
		reset( $chunks );
		return is_array( $last['data'] ?? null ) ? (array) $last['data'] : array();
	}

	/**
	 * Build a stable per-tool progress key for idempotent UI replacement.
	 */
	private function tool_progress_key( array $tc ): string {
		$id = sanitize_text_field( (string) ( $tc['id'] ?? '' ) );
		if ( '' !== $id ) {
			return $id;
		}

		$name      = sanitize_key( (string) ( $tc['name'] ?? 'tool' ) );
		$arguments = wp_json_encode( $tc['arguments'] ?? array() );
		if ( ! is_string( $arguments ) ) {
			$arguments = '';
		}

		return $name . ':' . substr( md5( $arguments ), 0, 12 );
	}

	/**
	 * Normalize and chunk streamed progress payloads so handlers can emit
	 * repeated updates without producing oversized SSE messages.
	 *
	 * @param mixed $chunk Raw progress payload from the handler.
	 * @return array<int, array{data: array, estimated_tokens: int}>
	 */
	private function build_stream_progress_packets( $chunk, int $max_chunk_tokens ): array {
		$data = $this->normalize_stream_progress_chunk( $chunk );
		if ( empty( $data ) ) {
			return array();
		}

		$estimated = $this->estimate_value_tokens( $data );
		if ( $estimated <= $max_chunk_tokens ) {
			return array(
				array(
					'data'             => $data,
					'estimated_tokens' => $estimated,
				),
			);
		}

		$packets = array();
		$current = array();
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );

		foreach ( $data as $key => $value ) {
			$candidate = $current;
			if ( $is_list ) {
				$candidate[] = $value;
			} else {
				$candidate[ $key ] = $value;
			}

			if ( ! empty( $current ) && $this->estimate_value_tokens( $candidate ) > $max_chunk_tokens ) {
				$packets[] = array(
					'data'             => $current,
					'estimated_tokens' => $this->estimate_value_tokens( $current ),
				);
				$current = $is_list ? array( $value ) : array( $key => $value );
				continue;
			}

			$current = $candidate;
		}

		if ( ! empty( $current ) ) {
			$packets[] = array(
				'data'             => $current,
				'estimated_tokens' => $this->estimate_value_tokens( $current ),
			);
		}

		return ! empty( $packets )
			? $packets
			: array(
				array(
					'data'             => $data,
					'estimated_tokens' => $estimated,
				),
			);
	}

	/**
	 * Convert arbitrary handler progress payloads into a predictable array shape.
	 */
	private function normalize_stream_progress_chunk( $chunk ): array {
		if ( is_array( $chunk ) ) {
			$data = $chunk;
		} elseif ( is_object( $chunk ) ) {
			$data = (array) $chunk;
		} elseif ( null === $chunk ) {
			return array();
		} else {
			$data = array(
				'message' => sanitize_text_field( (string) $chunk ),
			);
		}

		return array_filter(
			$data,
			static function ( $value ) {
				if ( null === $value ) {
					return false;
				}
				if ( is_string( $value ) ) {
					return '' !== trim( $value );
				}
				if ( is_array( $value ) ) {
					return ! empty( $value );
				}
				return true;
			}
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
	private function apply_lightweight_compaction( array $messages, int $round, ?PressArk_Checkpoint $checkpoint = null ): array {
		if ( count( $messages ) <= 5 ) {
			return $messages;
		}

		$continue_fence = -1;
		foreach ( $messages as $index => $message ) {
			if ( 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$content = trim( (string) ( $message['content'] ?? '' ) );
			if ( str_starts_with( $content, '[Continue]' ) ) {
				$continue_fence = $index;
			}
		}

		$protected_start = max( 0, count( $messages ) - 3 );
		$sort_args       = static function ( $value ) use ( &$sort_args ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			foreach ( $value as $key => $child ) {
				$value[ $key ] = $sort_args( $child );
			}

			if ( ! empty( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			}

			return $value;
		};
		$build_tool_call_map = static function ( array $source_messages ) use ( $sort_args ): array {
			$tool_call_map = array();

			foreach ( $source_messages as $assistant_index => $message ) {
				if ( 'assistant' !== ( $message['role'] ?? '' ) || empty( $message['tool_calls'] ) || ! is_array( $message['tool_calls'] ) ) {
					continue;
				}

				foreach ( $message['tool_calls'] as $call_index => $call ) {
					$tool_call_id = sanitize_text_field( (string) ( $call['id'] ?? '' ) );
					if ( '' === $tool_call_id ) {
						continue;
					}

					$tool_name = sanitize_key( (string) ( $call['function']['name'] ?? '' ) );
					$args      = json_decode( (string) ( $call['function']['arguments'] ?? '' ), true );
					if ( ! is_array( $args ) ) {
						$args = array();
					}

					$args = $sort_args( $args );
					$tool_call_map[ $tool_call_id ] = array(
						'assistant_index' => $assistant_index,
						'call_index'      => $call_index,
						'tool_name'       => $tool_name,
						'args_hash'       => md5( (string) wp_json_encode( $args ) ),
					);
				}
			}

			return $tool_call_map;
		};

		$tool_call_map         = $build_tool_call_map( $messages );
		$duplicate_groups      = array();
		$assistant_call_prunes = array();
		$dropped_tool_indexes  = array();

		foreach ( $messages as $index => $message ) {
			if (
				$index <= $continue_fence
				|| $index >= $protected_start
				|| 'tool' !== ( $message['role'] ?? '' )
			) {
				continue;
			}

			$tool_call_id = sanitize_text_field( (string) ( $message['tool_call_id'] ?? '' ) );
			if ( '' === $tool_call_id || empty( $tool_call_map[ $tool_call_id ] ) ) {
				continue;
			}

			$meta = $tool_call_map[ $tool_call_id ];
			if ( $meta['assistant_index'] <= $continue_fence || $meta['assistant_index'] >= $protected_start ) {
				continue;
			}

			$key = $meta['tool_name'] . '|' . $meta['args_hash'];
			$duplicate_groups[ $key ][] = array(
				'tool_index'       => $index,
				'assistant_index'  => $meta['assistant_index'],
				'call_index'       => $meta['call_index'],
			);
		}

		foreach ( $duplicate_groups as $entries ) {
			if ( count( $entries ) < 2 ) {
				continue;
			}

			$latest = array_pop( $entries );
			unset( $latest );

			foreach ( $entries as $entry ) {
				$dropped_tool_indexes[ $entry['tool_index'] ] = true;
				$assistant_call_prunes[ $entry['assistant_index'] ][ $entry['call_index'] ] = true;
			}
		}

		if ( ! empty( $dropped_tool_indexes ) ) {
			$before_messages = $messages;
			$deduped         = array();

			foreach ( $messages as $index => $message ) {
				if ( isset( $dropped_tool_indexes[ $index ] ) ) {
					continue;
				}

				if ( isset( $assistant_call_prunes[ $index ] ) && 'assistant' === ( $message['role'] ?? '' ) && ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$pruned_calls = array();
					foreach ( $message['tool_calls'] as $call_index => $call ) {
						if ( isset( $assistant_call_prunes[ $index ][ $call_index ] ) ) {
							continue;
						}

						$pruned_calls[] = $call;
					}

					if ( empty( $pruned_calls ) ) {
						continue;
					}

					$message['tool_calls'] = array_values( $pruned_calls );
				}

				$deduped[] = $message;
			}

			$messages = $deduped;
			if ( $checkpoint ) {
				$this->record_compaction_event(
					$checkpoint,
					$round,
					'lightweight_dedup',
					$before_messages,
					$messages,
					array(),
					'',
					'lightweight_dedup'
				);
			}
		}

		$tool_call_map      = $build_tool_call_map( $messages );
		$user_after_counts  = array_fill( 0, count( $messages ), 0 );
		$assist_after_counts = array_fill( 0, count( $messages ), 0 );
		$user_after         = 0;
		$assistant_after    = 0;

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			$user_after_counts[ $index ]   = $user_after;
			$assist_after_counts[ $index ] = $assistant_after;

			$role = $messages[ $index ]['role'] ?? '';
			if ( 'user' === $role ) {
				$user_after++;
			} elseif ( 'assistant' === $role ) {
				$assistant_after++;
			}
		}

		$before_summary_messages = $messages;
		$condensed               = false;
		foreach ( $messages as $index => &$message ) {
			if ( $index <= $continue_fence || 'tool' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$tool_call_id = sanitize_text_field( (string) ( $message['tool_call_id'] ?? '' ) );
			if ( '' === $tool_call_id || empty( $tool_call_map[ $tool_call_id ] ) ) {
				continue;
			}

			$meta = $tool_call_map[ $tool_call_id ];
			if ( 'update_plan' === $meta['tool_name'] ) {
				continue;
			}

			if ( $user_after_counts[ $index ] < 3 || $assist_after_counts[ $index ] < 3 ) {
				continue;
			}

			$decoded = json_decode( (string) ( $message['content'] ?? '' ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$summary = '';
			if ( ! empty( $decoded['read_meta']['summary'] ) ) {
				$summary = (string) $decoded['read_meta']['summary'];
			} elseif ( ! empty( $decoded['summary'] ) ) {
				$summary = (string) $decoded['summary'];
			} elseif ( ! empty( $decoded['message'] ) ) {
				$summary = (string) $decoded['message'];
			}

			$summary = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $summary ) ) );
			if ( '' === $summary ) {
				continue;
			}

			$message['content'] = (string) wp_json_encode(
				array(
					'success' => array_key_exists( 'success', $decoded ) ? (bool) $decoded['success'] : true,
					'summary' => $summary,
				)
			);
			$condensed = true;
		}
		unset( $message );

		if ( $condensed && $checkpoint ) {
			$this->record_compaction_event(
				$checkpoint,
				$round,
				'lightweight_summary',
				$before_summary_messages,
				$messages,
				array(),
				'',
				'lightweight_summary'
			);
		}

		return $messages;
	}

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

		// Fresh-source the active_request_verbatim from the earliest user
		// message in this compaction's input, so cascading compactions can't
		// truncate or paraphrase the original ask. If no user message is
		// found, leave preserved_details unchanged.
		//
		// v5.4.0: The harness injects role=user messages that aren't from the
		// user — [Continue]/[Confirmed] post-approval re-pumps, prose-tool-call
		// correctives ("Your previous response printed a tool call as plain
		// text..."), and plan-mode grounding directives ("Use relevant read-only
		// tools first..."). Without filtering, cascading compactions promote
		// one of these synthetic messages to active_request_verbatim, and the
		// next compaction loses the user's real ask. Observed in claude-bridge
		// captures 2026-05-12 — the summary's "USER REQUEST" section ended up
		// describing the harness corrective as the user's intent.
		$earliest_user_content = '';
		foreach ( $messages as $msg ) {
			if ( ( $msg['role'] ?? '' ) === 'user' ) {
				$candidate = is_string( $msg['content'] ?? null ) ? trim( (string) $msg['content'] ) : '';
				if ( '' === $candidate ) {
					continue;
				}
				// Skip prior compaction summaries (our own marker).
				if ( strpos( $candidate, '[Context compaction boundary' ) !== false ) {
					continue;
				}
				// Skip post-approval/continuation re-pumps.
				if ( str_starts_with( $candidate, '[Continue]' )
					|| str_starts_with( $candidate, '[Confirmed]' ) ) {
					continue;
				}
				// Skip known harness-injected correctives (match by stable
				// prefix; if you rename one of these strings in agent.php,
				// update the prefix list here in lockstep).
				$synthetic_prefixes = array(
					'Your previous response printed a tool call as plain text',
					'Your previous response printed an invalid tool call',
					'Use relevant read-only tools first to inspect',
					'Inspect the current state with relevant read tools first',
				);
				$is_synthetic = false;
				foreach ( $synthetic_prefixes as $prefix ) {
					if ( str_starts_with( $candidate, $prefix ) ) {
						$is_synthetic = true;
						break;
					}
				}
				if ( $is_synthetic ) {
					continue;
				}
				$earliest_user_content = $candidate;
				break;
			}
		}
		if ( '' !== $earliest_user_content ) {
			$verbatim_line = 'active_request_verbatim: ' . $earliest_user_content;
			// Replace any existing active_request_verbatim line, or prepend if none.
			$replaced = false;
			foreach ( $preserved_details as $i => $line ) {
				if ( is_string( $line ) && strpos( $line, 'active_request_verbatim:' ) === 0 ) {
					$preserved_details[ $i ] = $verbatim_line;
					$replaced                = true;
					break;
				}
			}
			if ( ! $replaced ) {
				array_unshift( $preserved_details, $verbatim_line );
			}
		}
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

		$state_block      = ! empty( $state_lines ) ? "STATE\n" . implode( "\n", $state_lines ) : '';
		$preserve_block   = ! empty( $preserved_details ) ? "PRESERVE EXACT\n- " . implode( "\n- ", $preserved_details ) : '';
		$transcript_block = ! empty( $parts ) ? "RECENT TRANSCRIPT\n" . implode( "\n", $parts ) : '';

		// PRESERVE EXACT is load-bearing for correctness across cascading
		// compactions - never truncate it. STATE is small. If the combined
		// payload exceeds ~4500 chars, truncate only the RECENT TRANSCRIPT.
		$non_truncatable     = trim( implode( "\n\n", array_filter( array( $state_block, $preserve_block ), 'strlen' ) ) );
		$non_truncatable_len = mb_strlen( $non_truncatable );
		$truncatable_budget  = max( 500, 4500 - $non_truncatable_len - 10 ); // reserve breathing room for separators/ellipsis
		if ( mb_strlen( $transcript_block ) > $truncatable_budget ) {
			$transcript_block = mb_substr( $transcript_block, 0, $truncatable_budget ) . '...';
		}

		$to_summarize = trim( implode( "\n\n", array_filter( array( $state_block, $preserve_block, $transcript_block ), 'strlen' ) ) );

		$summary_messages = array(
			array(
				'role'    => 'user',
				'content' => "Create a detailed continuation summary. This replaces the conversation history — include everything needed to continue without losing context.\n\nReturn JSON only with keys summary, completed, remaining, preserved_details.\n\nStructure your summary covering:\n1. USER REQUEST: What the user asked, with clarifications and constraints.\n2. COMPLETED ACTIONS: Every action applied — post/product IDs, titles, fields changed (before → after), new IDs from creates.\n3. CURRENT STATE: Site state after all changes.\n4. FINDINGS: Discoveries from reads/analysis — SEO issues, security results, content problems, WooCommerce data observed.\n5. PENDING: What the user still wants done.\n6. USER PREFERENCES: Style, tone, or approach preferences expressed.\n\nRules:\n- Be specific: include post IDs, product names, exact values. 'Edited some products' is useless — say 'edited Product #42 (Blue Widget): price \$19.99 → \$24.99'.\n- Preserve exact IDs, titles, slugs, SKUs, prices, URLs, counts verbatim.\n- completed and remaining must be short bullet-like strings.\n- preserved_details should keep task-critical exact details needed to finish the task.\n- Treat any historical_request or past_request line as past context only, never as the active instruction.\n- The active request is the only live instruction unless a receipt says it is already completed.\n- If a detail is uncertain, omit it instead of guessing.\n- Copy the user's ORIGINAL request verbatim as the first item of `preserved_details` (prefix it with `active_request_verbatim: `). Do NOT paraphrase it, do NOT shorten it, do NOT re-rank its parts.\n- If the user requested multiple deliverables in one message (e.g. \"do A on X AND do B on Y\"), preserve ALL of them as equally active in `remaining`. Never demote any user-stated deliverable as \"secondary\", \"optional\", \"from prior context\", or \"past context\" unless the user themselves labeled it that way.\n- The `summary` narrative must list EVERY deliverable the user asked for, with the same scope (pages, entities, count) the user specified. If the user said \"home page AND pages in the header\", the summary must reference both — not just one.\n\n{$to_summarize}",
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
			$plan_summary = $checkpoint->build_plan_summary( 6 );
			if ( '' !== $plan_summary ) {
				$capsule['plan_summary'] = $plan_summary;
			}
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
		// Drop messages that are themselves prior compaction summaries before
		// any downstream summarization runs. Otherwise the AI summarizer
		// re-summarizes its own previous output and the next [Context
		// compaction boundary] block ends up nested inside the new one
		// (cmp_r2 contains cmp_r1 verbatim). The structured fields that
		// matter — created_post_ids, ai_decisions, completed/remaining,
		// preserved_details — are already persisted on the checkpoint via
		// the existing capsule, so dropping the summary text loses nothing.
		$messages = array_values( array_filter( $messages, static function ( $msg ): bool {
			if ( ! is_array( $msg ) ) {
				return true;
			}
			$content = is_string( $msg['content'] ?? '' ) ? (string) $msg['content'] : '';
			return false === strpos( $content, '[Context compaction boundary' );
		} ) );

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

			// When receipts already prove a post was created (preview-confirm
			// flow doesn't always feed back into the ledger's task graph),
			// drop create-post-shaped labels from the remaining list. Without
			// this, the compaction summary contradicts itself —
			// "Posts created (DO NOT recreate): 6" alongside
			// "Remaining: Create Post" — and a less robust model will
			// emit a duplicate create_post next round.
			if ( ! empty( $created_post_ids ) ) {
				$remaining = array_values( array_filter( $remaining, static function ( $label ): bool {
					$label = (string) $label;
					if ( '' === $label ) {
						return false;
					}
					if ( preg_match( '/\bcreate_post\b/i', $label ) ) {
						return false;
					}
					return ! preg_match( '/\b(create|publish|add|write|draft)\b.*\b(post|page|blog|content|landing)\b/i', $label );
				} ) );
			}

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
		$normalized = preg_replace( '/\s*Do not repeat completed steps or recreate completed content\.?/i', '', $normalized );
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
			'update_plan'             => 'Updating plan',
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
			'update_plan'             => 'Updating plan',
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
	 * Resolve tool-owned execution metadata with dynamic registry overrides.
	 *
	 * The tool object is the primary source for read-only and concurrency
	 * hints, while the operation registry keeps dynamic input-based overrides
	 * for legacy tools whose mode changes with arguments.
	 *
	 * @return array{tool: object|null, capability: string, readonly: bool, concurrency_safe: bool}
	 */
	private function resolve_tool_contract( string $tool_name, array $args = array() ): array {
		$tool_name = sanitize_key( $tool_name );
		$tool      = class_exists( 'PressArk_Tools' ) ? PressArk_Tools::get_tool( $tool_name ) : null;
		$capability = self::classify_tool( $tool_name, $args );
		$readonly   = is_object( $tool ) && method_exists( $tool, 'is_readonly' )
			? (bool) $tool->is_readonly()
			: 'read' === $capability;

		if ( 'read' !== $capability ) {
			$readonly = false;
		}

		$concurrency_safe = is_object( $tool ) && method_exists( $tool, 'is_concurrency_safe' )
			? (bool) $tool->is_concurrency_safe()
			: false;

		if ( class_exists( 'PressArk_Operation_Registry' ) ) {
			$concurrency_safe = $readonly && PressArk_Operation_Registry::is_concurrency_safe( $tool_name, $args );
		}

		return array(
			'tool'             => is_object( $tool ) ? $tool : null,
			'capability'       => $capability,
			'readonly'         => $readonly,
			'concurrency_safe' => $concurrency_safe,
		);
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
			return $this->plan_local_fallback( $message, $conversation );
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
			. "{\"task\":\"<type>\",\"steps\":[\"<verb> <object>\"],\"groups\":[\"<group>\"],\"artifact\":{\"assumptions\":[\"<assumption>\"],\"constraints\":[\"<constraint>\"],\"affected_entities\":[{\"type\":\"content\",\"label\":\"<entity>\"}],\"risks\":[\"<risk>\"],\"verification_steps\":[\"<verification>\"],\"steps\":[{\"id\":\"step_1\",\"title\":\"<step>\",\"description\":\"<detail>\",\"kind\":\"read|analyze|preview|confirm|write|verify\",\"group\":\"content|seo|woocommerce|elementor|system\",\"depends_on\":[],\"verification\":\"<verification>\",\"rollback_hint\":\"<rollback>\",\"metadata\":{}}]}}\n\n"
			. "task: chat, analyze, generate, edit, code, diagnose\n"
			. "steps: 1-4 short imperative phrases (what to do in order)\n"
			. "groups: tool groups needed (from list below), max 3, [] for chat\n\n"
			. "artifact.steps: mirror the execution order with structured step metadata when possible\n\n"
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
				'artifact'  => is_array( $parsed['artifact'] ?? null ) ? (array) $parsed['artifact'] : array(),
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
		$task   = self::refine_chat_task_type( $message, self::classify_task( $message, $conversation ) );
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

		if ( self::is_explicit_content_read_request( $msg ) && 'generate' !== $task_type ) {
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

		if ( self::is_explicit_content_read_request( $msg ) && 'generate' !== $task_type ) {
			$normalized[] = 'blocks';
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				$normalized[] = 'elementor';
			}
		}

		if ( 'generate' === $task_type && $mentions_content_surface ) {
			$normalized = array_values( array_diff( $normalized, array( 'seo', 'blocks', 'elementor' ) ) );
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
		self::ensure_plan_mode_loaded();

		$message = PressArk_Plan_Mode::strip_plan_directive( $message );
		$task_type = self::refine_chat_task_type( $message, $task_type );
		$msg     = strtolower( trim( $message ) );
		if ( '' === $msg || 'chat' === $task_type ) {
			return array();
		}

		$is_bulk = $this->message_is_bulk_scope( $msg );
		$target  = $this->infer_plan_target_phrase( $msg, $task_type, $groups, $is_bulk );
		$steps   = array();

		switch ( $task_type ) {
			case 'analyze':
				$steps[] = 'review ' . $target . ' and collect the relevant context';
				$steps[] = $this->infer_analysis_plan_step( $msg, $groups, $target, $is_bulk );
				$steps[] = 'summarize the findings and recommended next actions';
				break;

			case 'diagnose':
				$steps[] = 'inspect ' . $target . ' and gather the current signals';
				$steps[] = 'identify the most likely cause across the related settings, content, or plugins';
				$steps[] = 'summarize the likely fix and any follow-up verification to run';
				break;

			case 'code':
				$steps[] = 'inspect ' . $target . ' and identify the files or settings involved';
				$steps[] = 'prepare the code or configuration changes needed for the request';
				$steps[] = 'apply the approved changes and verify the result';
				break;

			case 'generate':
				$steps[] = 'review ' . $target . ' and gather the constraints that should shape the new content';
				$steps[] = $this->infer_generation_plan_step( $msg, $target );
				$steps[] = 'apply the approved content changes';
				$steps[] = 'verify the final formatting and placement';
				break;

			case 'edit':
			default:
				$steps[] = 'review ' . $target . ' and confirm the requested scope';
				$steps[] = $this->infer_edit_plan_step( $msg, $groups, $target, $is_bulk );
				$steps[] = 'apply the approved changes to ' . $target;
				$steps[] = 'verify the updated result';
				break;
		}

		$normalized = array();
		foreach ( $steps as $step ) {
			$step = sanitize_text_field( trim( (string) $step ) );
			if ( '' !== $step ) {
				$normalized[] = $step;
			}
		}

		return array_slice( array_values( array_unique( $normalized ) ), 0, 4 );
	}

	private function message_is_bulk_scope( string $message ): bool {
		return (bool) preg_match(
			'/\b(?:all|every|bulk|batch|across|multiple|site-?wide|entire|catalog|catalogue|dozens|hundreds|\d+\s+(?:products?|posts?|pages?|items?|orders?))\b/i',
			$message
		);
	}

	private function infer_plan_target_phrase( string $message, string $task_type, array $groups, bool $is_bulk ): string {
		if ( preg_match( '/\bsite title\b/i', $message ) ) {
			return 'the site title setting';
		}

		if ( preg_match( '/\bhomepage|home\s+page\b/i', $message ) ) {
			return preg_match( '/\bseo\b|meta.?title|meta.?desc|canonical|robots\.txt/i', $message )
				? 'the homepage SEO setup'
				: 'the homepage content';
		}

		if ( preg_match( '/\bproduct\s+description\b/i', $message ) ) {
			return $is_bulk ? 'the affected product descriptions' : 'the current product description';
		}

		if ( preg_match( '/\bproducts?\b/i', $message ) || in_array( 'woocommerce', $groups, true ) ) {
			if ( preg_match( '/\bprice|pricing\b/i', $message ) ) {
				return $is_bulk ? 'the affected product pricing' : 'the target product pricing';
			}

			return $is_bulk ? 'the affected products' : 'the target product';
		}

		if ( preg_match( '/\bposts?|pages?|articles?|content|copy|headline|excerpt|description\b/i', $message )
			|| in_array( 'content', $groups, true )
		) {
			return $is_bulk ? 'the affected content items' : 'the current content';
		}

		if ( preg_match( '/\bhero|section|banner|cta|button|header|footer|layout\b/i', $message ) ) {
			return 'the relevant page section';
		}

		if ( preg_match( '/\bseo\b|meta.?title|meta.?desc|canonical|robots\.txt|schema|search.?engine/i', $message )
			|| in_array( 'seo', $groups, true )
		) {
			return 'the current SEO configuration';
		}

		if ( preg_match( '/\bplugin|theme|css|php|javascript|js|template|shortcode|snippet|hook|filter|code\b/i', $message )
			|| 'code' === $task_type
		) {
			return 'the relevant code and configuration';
		}

		if ( preg_match( '/\bsetting|settings|option|option[s]?|title|tagline|menu|menus|widget|widgets\b/i', $message ) ) {
			return 'the relevant site settings';
		}

		return 'the affected area';
	}

	private function infer_analysis_plan_step( string $message, array $groups, string $target, bool $is_bulk ): string {
		if ( preg_match( '/\bseo\b|meta.?title|meta.?desc|canonical|robots\.txt|search.?engine|ranking/i', $message )
			|| in_array( 'seo', $groups, true )
		) {
			return 'check the metadata, headings, links, and structure that affect SEO';
		}

		if ( preg_match( '/\bprice|pricing|tag|inventory|stock|sale\b/i', $message )
			|| in_array( 'woocommerce', $groups, true )
		) {
			return $is_bulk
				? 'identify which products match the requested scope and compare their current data'
				: 'check the current product data and related store context';
		}

		if ( preg_match( '/\bplugin|theme|setting|option|menu|widget\b/i', $message ) ) {
			return 'inspect the related configuration and note any constraints or dependencies';
		}

		return 'identify the key findings, gaps, and constraints that matter for this request';
	}

	private function infer_generation_plan_step( string $message, string $target ): string {
		if ( preg_match( '/\bseo\b|meta.?title|meta.?desc|search.?engine\b/i', $message ) ) {
			return 'draft the requested content and align it with the SEO requirements';
		}

		if ( preg_match( '/\bdescription|copy|headline|excerpt\b/i', $message ) ) {
			return 'draft the requested copy changes for ' . $target;
		}

		return 'draft the new content for ' . $target;
	}

	private function infer_edit_plan_step( string $message, array $groups, string $target, bool $is_bulk ): string {
		if ( preg_match( '/\blonger|shorter|expand|trim|rewrite|reword|description|copy|headline|excerpt\b/i', $message ) ) {
			return 'draft the requested copy changes while preserving the existing intent and important details';
		}

		if ( preg_match( '/\bprice|pricing|tag|sale|discount|increase|decrease|raise|lower\b/i', $message )
			|| in_array( 'woocommerce', $groups, true )
		) {
			return $is_bulk
				? 'identify the exact products that match the request and prepare the pricing or tag updates'
				: 'prepare the requested product updates and confirm the affected fields';
		}

		if ( preg_match( '/\bplugin|theme|css|php|javascript|js|template|shortcode|snippet|hook|filter|code\b/i', $message ) ) {
			return 'prepare the requested code or configuration changes and note any affected dependencies';
		}

		if ( preg_match( '/\bsetting|settings|option|site title|tagline|menu|widget\b/i', $message ) ) {
			return 'prepare the requested settings changes and confirm where they will take effect';
		}

		return 'prepare the exact changes needed for ' . $target;
	}

	private static function explicitly_mentions_custom_fields( string $message ): bool {
		return (bool) preg_match(
			'/\b(custom\s+field|custom\s+fields|acf|advanced\s+custom\s+fields|meta\s+field|meta\s+fields|post\s+meta|field\s+key|meta\s+key)\b/i',
			$message
		);
	}

	private static function mentions_content_surface( string $message ): bool {
		return (bool) preg_match(
			'/\b(post|page|article|blog(?:\s+post)?|homepage|home\s+page|landing\s+page|about\s+page|contact\s+page|services\s+page|sales\s+page|content|copy|headline|excerpt|slug|hero|section|banner|cta|button|header|footer|layout)\b/i',
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

		$refined_task = self::refine_chat_task_type( $message, 'chat' );
		if ( 'chat' !== $refined_task ) {
			return $refined_task;
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
