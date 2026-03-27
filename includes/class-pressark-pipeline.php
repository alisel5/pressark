<?php
/**
 * PressArk Pipeline — Unified post-execution orchestration.
 *
 * Extracted from class-chat.php process_chat() and class-pressark-task-queue.php
 * finalize_result(). All paths (workflow, agent, legacy, async) share this
 * single settlement/tracking/response-building pipeline.
 *
 * @package PressArk
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Pipeline {

	private PressArk_Reservation   $reservation;
	private PressArk_Usage_Tracker $tracker;
	private PressArk_Throttle      $throttle;
	private string                 $tier;
	private array                  $plan_info;

	// Resource tracking for cleanup.
	private ?string $reservation_id = null;
	private ?int    $user_id        = null;
	private bool    $slot_acquired  = false;
	private string  $slot_id        = '';

	public function __construct(
		PressArk_Reservation   $reservation,
		PressArk_Usage_Tracker $tracker,
		PressArk_Throttle      $throttle,
		string                 $tier,
		array                  $plan_info = array()
	) {
		$this->reservation = $reservation;
		$this->tracker     = $tracker;
		$this->throttle    = $throttle;
		$this->tier        = $tier;
		$this->plan_info   = $plan_info;
	}

	// ── Resource Lifecycle ─────────────────────────────────────────

	/**
	 * Register resources that need cleanup on failure.
	 *
	 * @param string $reservation_id Reservation ID from token reservation.
	 * @param int    $user_id        Current user ID.
	 * @param bool   $slot           Whether a concurrency slot was acquired.
	 * @param string $slot_id        v3.7.0: Slot ID for precise release.
	 */
	public function register_resources( string $reservation_id, int $user_id, bool $slot = false, string $slot_id = '' ): void {
		$this->reservation_id = $reservation_id;
		$this->user_id        = $user_id;
		$this->slot_acquired  = $slot;
		$this->slot_id        = $slot_id;
	}

	/**
	 * Release all acquired resources. Safe to call multiple times (idempotent).
	 *
	 * @param string $reason Failure reason (empty = normal cleanup).
	 */
	public function cleanup( string $reason = '' ): void {
		// Release concurrency slot.
		if ( $this->slot_acquired && $this->user_id ) {
			$this->throttle->release_slot( $this->user_id, $this->slot_id ?? '' );
			$this->slot_acquired = false;
		}

		// Fail the reservation if we have one and a reason.
		if ( ! empty( $reason ) && ! empty( $this->reservation_id ) ) {
			$this->reservation->fail( $this->reservation_id, $reason );
			$this->reservation_id = null;
		}
	}

	// ── Token Settlement ───────────────────────────────────────────

	/**
	 * Settle tokens from an execution result.
	 *
	 * Normalizes usage data from any result shape:
	 * - Agent: input_tokens, output_tokens (top-level keys)
	 * - Legacy: usage.prompt_tokens, usage.completion_tokens (nested keys)
	 * - Workflow: tokens_used only (no breakdown)
	 * - Async: same as agent
	 *
	 * @param array  $result Raw result from agent/workflow/legacy.
	 * @param string $route  'agent' | 'workflow' | 'legacy' | 'async'.
	 * @return array Token status from reservation->settle().
	 */
	public function settle( array $result, string $route = 'agent' ): array {
		if ( empty( $this->reservation_id ) ) {
			return array();
		}

		$input_tokens  = (int) ( $result['input_tokens']
		                         ?? $result['usage']['prompt_tokens'] ?? 0 );
		$output_tokens = (int) ( $result['output_tokens']
		                         ?? $result['usage']['completion_tokens'] ?? 0 );
		$cache_read    = (int) ( $result['cache_read_tokens'] ?? 0 );
		$cache_write   = (int) ( $result['cache_write_tokens'] ?? 0 );

		$raw_total = (int) ( $result['tokens_used'] ?? ( $input_tokens + $output_tokens ) );

		$actual = array(
			'settled_tokens'     => $raw_total,
			'input_tokens'       => $input_tokens,
			'output_tokens'      => $output_tokens,
			'cache_read_tokens'  => $cache_read,
			'cache_write_tokens' => $cache_write,
			'agent_rounds'       => (int) ( $result['agent_rounds'] ?? count( $result['steps'] ?? array() ) ),
			'provider'           => ! empty( $result['provider'] )
				? $result['provider']
				: get_option( 'pressark_api_provider', 'openrouter' ),
			'model'              => ! empty( $result['model'] )
				? $result['model']
				: get_option( 'pressark_model', '' ),
			'route'              => $route,
		);

		return $this->reservation->settle( $this->reservation_id, $actual, $this->tier );
	}

	// ── Usage Tracking ─────────────────────────────────────────────

	/**
	 * Track token usage in wp_options counters.
	 *
	 * @param array  $result Raw result from execution.
	 * @param string $route  Execution route.
	 */
	public function track_usage( array $result, string $route = 'agent' ): void {
		$tokens_used = (int) ( $result['tokens_used'] ?? 0 );
		if ( $tokens_used <= 0 ) {
			return;
		}

		$model = ! empty( $result['model'] )
			? $result['model']
			: get_option( 'pressark_model', '' );

		PressArk_Usage_Tracker::track_tokens(
			$tokens_used,
			0,
			$model,
			array(
				'agent_steps'       => (int) ( $result['agent_rounds'] ?? count( $result['steps'] ?? array() ) ),
				'route'             => $route,
				'provider'          => $result['provider'] ?? '',
				'cache_read_tokens' => (int) ( $result['cache_read_tokens'] ?? 0 ),
			)
		);
	}

	// ── Opportunistic Reconcile ────────────────────────────────────

	/**
	 * Deterministic stale reservation cleanup.
	 *
	 * v5.0.1: Replaced the 1% random trigger with a time-based transient check.
	 * The old approach meant ~99% of requests skipped cleanup, allowing stale
	 * reservations to persist for hours on low-traffic sites. This runs at most
	 * once per 5 minutes per site, covering the broken-cron scenario while
	 * avoiding excessive DB queries on high-traffic sites.
	 */
	public function maybe_reconcile(): void {
		$last_run = get_transient( 'pressark_last_reconcile' );
		if ( false !== $last_run ) {
			return; // Already ran within the last 5 minutes.
		}
		set_transient( 'pressark_last_reconcile', time(), 5 * MINUTE_IN_SECONDS );
		$this->reservation->reconcile();
	}

	// ── Confirm Card Builder ───────────────────────────────────────

	/**
	 * Build pending_actions array from raw tool calls.
	 *
	 * Eliminates the duplicate confirm_card building in workflow/agent paths.
	 *
	 * @param array    $tool_calls  Raw tool calls from AI response.
	 * @param callable $preview_fn  Preview generator (e.g. chat->generate_preview).
	 * @return array Formatted pending_actions array.
	 */
	public function build_pending_actions( array $tool_calls, callable $preview_fn ): array {
		$pending = array();

		foreach ( $tool_calls as $tool_call ) {
			$action_data = array(
				'type'   => $tool_call['name'] ?? $tool_call['type'] ?? '',
				'params' => $tool_call['arguments'] ?? $tool_call['params'] ?? array(),
			);
			$pending[] = array(
				'action'  => $action_data,
				'preview' => call_user_func( $preview_fn, $action_data ),
				'status'  => 'pending_confirmation',
			);
		}

		return $pending;
	}

	// ── Response Building ──────────────────────────────────────────

	/**
	 * Build a unified WP_REST_Response from any execution result.
	 *
	 * Preserves all path-specific fields while sharing the common base.
	 *
	 * @param array  $result       Raw result from agent/workflow/legacy.
	 * @param string $route        'agent' | 'workflow' | 'legacy'.
	 * @param array  $token_status Settlement result.
	 * @return WP_REST_Response
	 */
	public function build_response( array $result, string $route, array $token_status ): WP_REST_Response {
		// Base fields present in ALL response shapes.
		$data = array(
			'type'              => $result['type'] ?? 'final_response',
			'reply'             => $result['message'] ?? $result['reply'] ?? '',
			'actions_performed' => $result['actions_performed'] ?? array(),
			'pending_actions'   => $result['pending_actions'] ?? array(),
			'usage'             => $this->tracker->get_usage_data(),
			'token_status'      => $token_status,
			'plan_info'         => $this->plan_info,
		);

		// v3.1.0: Run ID for durable execution tracking.
		if ( ! empty( $result['run_id'] ) ) {
			$data['run_id'] = $result['run_id'];
		}

		foreach ( array(
			'tokens_used',
			'input_tokens',
			'output_tokens',
			'cache_read_tokens',
			'cache_write_tokens',
			'provider',
			'model',
			'agent_rounds',
			'icu_spent',
			'task_type',
			'workflow_class',
			'loaded_groups',
			'tool_loading',
			'exit_reason',
			'silent_continuation',
			'verification',
			'checkpoint',
		) as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$data[ $field ] = $result[ $field ];
			}
		}

		// Steps (agent + workflow paths).
		if ( in_array( $route, array( 'agent', 'workflow' ), true ) ) {
			$data['steps'] = $result['steps'] ?? array();
		}

		// Agent-only fields.
		if ( 'agent' === $route ) {
			$data['checkpoint'] = $result['checkpoint'] ?? array();
		}

		// Preview fields (any path may produce a preview).
		if ( 'preview' === ( $data['type'] ?? '' ) ) {
			$data['preview_session_id'] = $result['preview_session_id'] ?? '';
			$data['preview_url']        = $result['preview_url'] ?? '';
			$data['diff']               = $result['diff'] ?? array();
		}

		// v3.1.0: workflow_state is now server-internal only (persisted in run store).
		// Deliberately omitted from the public response.

		// Error flag.
		if ( ! empty( $result['is_error'] ) ) {
			$data['is_error'] = true;
		}

		return new WP_REST_Response( $data, 200 );
	}

	// ── Run Lifecycle (v3.1.0) ────────────────────────────────────

	/**
	 * Settle a durable run record, optionally resuming workflow verify.
	 *
	 * Central authority for run settlement — used by confirm, preview keep,
	 * and async worker instead of calling Run_Store directly.
	 *
	 * @param string $run_id Run ID to settle.
	 * @param array  $result Execution result.
	 * @return array Result, potentially enriched with verification.
	 */
	public static function settle_run( string $run_id, array $result ): array {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run || (int) $run['user_id'] !== get_current_user_id() ) {
			return $result;
		}

		// Resume workflow verify if the run originated from a workflow.
		if ( ! empty( $run['workflow_class'] ) && ! empty( $run['workflow_state'] ) ) {
			$verification = self::resume_workflow_verify( $run, $result );
			if ( $verification ) {
				$result['verification'] = $verification;
			}
		}

		$run_store->settle( $run['run_id'], $result );
		$result['run_id'] = $run['run_id'];

		return $result;
	}

	/**
	 * Fail a durable run record.
	 *
	 * @param string $run_id Run ID to fail.
	 * @param string $reason Failure reason.
	 */
	public static function fail_run( string $run_id, string $reason = '' ): void {
		$run_store = new PressArk_Run_Store();
		$run_store->fail( $run_id, $reason );
	}

	/**
	 * Resume the workflow verify phase after preview keep or confirm.
	 *
	 * @param array $run    Run record from PressArk_Run_Store.
	 * @param array $result Result from Preview::keep() or confirm execution.
	 * @return array|null Verification result, or null if class not found.
	 */
	private static function resume_workflow_verify( array $run, array $result ): ?array {
		$class = $run['workflow_class'];
		if ( ! class_exists( $class ) ) {
			return null;
		}

		try {
			$tier      = $run['tier'] ?? 'free';
			$connector = new PressArk_AI_Connector( $tier );
			$logger    = new PressArk_Action_Logger();
			$engine    = new PressArk_Action_Engine( $logger );

			/** @var PressArk_Workflow_Runner $workflow */
			$workflow = new $class( $connector, $engine, $tier );
			$workflow->restore_state( $run['workflow_state'] );

			return $workflow->run_post_apply( $result );
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Pipeline', 'Workflow verify failed', array( 'run_id' => $run['run_id'], 'error' => $e->getMessage() ) );
			return array(
				'type'     => 'final_response',
				'message'  => 'Changes applied, but verification encountered an issue: ' . $e->getMessage(),
				'is_error' => true,
			);
		}
	}

	// ── Full Finalize ──────────────────────────────────────────────

	/**
	 * One-call finalization for all execution paths.
	 *
	 * Replaces the duplicated settle → track → release → reconcile sequence
	 * that was previously copied across workflow, agent, and legacy paths.
	 *
	 * @param array  $result Raw result from any execution path.
	 * @param string $route  'agent' | 'workflow' | 'legacy'.
	 * @return array{token_status: array, response: WP_REST_Response}
	 */
	public function finalize( array $result, string $route ): array {
		// [9] Settle reservation.
		$token_status = $this->settle( $result, $route );

		$this->plan_info = PressArk_Entitlements::get_plan_info( $this->tier );

		// [9b] Track usage.
		$this->track_usage( $result, $route );

		// [10] Release concurrency slot.
		if ( $this->slot_acquired && $this->user_id ) {
			$this->throttle->release_slot( $this->user_id, $this->slot_id );
			$this->slot_acquired = false;
		}

		// [11] Opportunistic reconcile (1% chance).
		$this->maybe_reconcile();

		// [12] Build response.
		return array(
			'token_status' => $token_status,
			'response'     => $this->build_response( $result, $route, $token_status ),
		);
	}
}
