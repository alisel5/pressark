<?php
/**
 * PressArk Pipeline — Unified post-execution orchestration.
 *
 * Extracted from class-chat.php process_chat() and class-pressark-task-queue.php
 * finalize_result(). All paths (agent, legacy, async) share this
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
	 * - Async: same as agent
	 *
	 * @param array  $result Raw result from agent/legacy/async.
	 * @param string $route  'agent' | 'legacy' | 'async'.
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
	 * Eliminates duplicate confirm_card building across execution paths.
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
			$permission_decision = class_exists( 'PressArk_Permission_Service' )
				? PressArk_Permission_Service::evaluate(
					(string) ( $action_data['type'] ?? '' ),
					(array) ( $action_data['params'] ?? array() ),
					class_exists( 'PressArk_Policy_Engine' )
						? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
						: 'interactive'
				)
				: array();
			$pending[] = array(
				'action'              => $action_data,
				'preview'             => call_user_func( $preview_fn, $action_data ),
				'status'              => 'pending_confirmation',
				'permission_decision' => $permission_decision,
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
	 * @param array  $result       Raw result from agent/legacy/async.
	 * @param string $route        'agent' | 'legacy' | 'async'.
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
			'billing_status'    => $token_status,
			'plan_info'         => $this->plan_info,
		);

		// v3.1.0: Run ID for durable execution tracking.
		if ( ! empty( $result['run_id'] ) ) {
			$data['run_id'] = $result['run_id'];
		}
		if ( ! empty( $result['correlation_id'] ) ) {
			$data['correlation_id'] = $result['correlation_id'];
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
			'loaded_groups',
			'tool_loading',
			'exit_reason',
			'silent_continuation',
			'verification',
			'checkpoint',
			'suggestions',
		) as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$data[ $field ] = $result[ $field ];
			}
		}

		if ( array_key_exists( 'budget', $result ) ) {
			$data['budget'] = $this->build_budget_response( (array) $result['budget'], $result, $token_status );
		}

		// Steps for interactive, tool-capable paths.
		if ( 'agent' === $route ) {
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

		// v3.1.0: workflow_state is now a server-internal pause snapshot (persisted in run store).
		// Deliberately omitted from the public response.

		// Error flag.
		if ( ! empty( $result['is_error'] ) ) {
			$data['is_error'] = true;
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Merge planning ledger data with settled usage and billing status.
	 *
	 * @param array $budget       Pre-request planning ledger.
	 * @param array $result       Execution result.
	 * @param array $token_status Settled billing status.
	 * @return array
	 */
	private function build_budget_response( array $budget, array $result, array $token_status ): array {
		$raw_actual_tokens = (int) ( $token_status['raw_actual_tokens'] ?? $result['tokens_used'] ?? 0 );
		$actual_icus       = (int) ( $token_status['actual_icus'] ?? $result['icu_spent'] ?? 0 );

		$budget['raw_actual_tokens'] = $raw_actual_tokens;
		$budget['actual_icus']       = $actual_icus;

		foreach ( array(
			'billing_authority',
			'billing_tier',
			'budget_pressure_state',
			'monthly_icu_budget',
			'monthly_included_icu_budget',
			'monthly_remaining',
			'monthly_included_remaining',
			'credits_remaining',
			'purchased_credits_remaining',
			'legacy_bonus_remaining',
			'total_available',
			'total_remaining',
			'spendable_credits_remaining',
			'spendable_icus_remaining',
			'using_purchased_credits',
			'using_legacy_bonus',
		) as $field ) {
			if ( array_key_exists( $field, $token_status ) ) {
				$budget[ $field ] = $token_status[ $field ];
			}
		}

		$budget['fits_credit_budget'] = ! empty( $budget['is_byok'] )
			|| (int) ( $budget['estimated_request_icus'] ?? 0 ) <= max( 0, (int) ( $budget['total_remaining'] ?? 0 ) );

		return $budget;
	}

	// ── Run Lifecycle (v3.1.0) ────────────────────────────────────

	/**
	 * Settle a durable run record.
	 *
	 * Central authority for run settlement — used by confirm, preview keep,
	 * and async worker instead of calling Run_Store directly.
	 *
	 * @param string $run_id Run ID to settle.
	 * @param array  $result Execution result.
	 * @return array Result with run_id attached.
	 */
	public static function settle_run( string $run_id, array $result ): array {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run || (int) $run['user_id'] !== get_current_user_id() ) {
			return $result;
		}

		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
				'run_id'         => (string) ( $run['run_id'] ?? '' ),
				'reservation_id' => (string) ( $run['reservation_id'] ?? '' ),
				'task_id'        => (string) ( $run['task_id'] ?? '' ),
				'chat_id'        => (int) ( $run['chat_id'] ?? 0 ),
				'user_id'        => (int) ( $run['user_id'] ?? 0 ),
				'route'          => (string) ( $run['route'] ?? '' ),
			)
		);

		$run_store->settle( $run['run_id'], $result );
		$result['run_id'] = $run['run_id'];
		$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );

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
		$run       = $run_store->get( $run_id );
		if ( $run ) {
			PressArk_Activity_Trace::set_current_context(
				array(
					'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
					'run_id'         => (string) ( $run['run_id'] ?? '' ),
					'reservation_id' => (string) ( $run['reservation_id'] ?? '' ),
					'task_id'        => (string) ( $run['task_id'] ?? '' ),
					'chat_id'        => (int) ( $run['chat_id'] ?? 0 ),
					'user_id'        => (int) ( $run['user_id'] ?? 0 ),
					'route'          => (string) ( $run['route'] ?? '' ),
				)
			);
		}
		$run_store->fail( $run_id, $reason );
	}

	// ── Full Finalize ──────────────────────────────────────────────

	/**
	 * One-call finalization for all execution paths.
	 *
	 * Replaces the duplicated settle → track → release → reconcile sequence
	 * that was previously copied across agent and legacy paths.
	 *
	 * @param array  $result Raw result from any execution path.
	 * @param string $route  'agent' | 'legacy' | 'async'.
	 * @return array{token_status: array, response: WP_REST_Response}
	 */
	public function finalize( array $result, string $route ): array {
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => (string) ( $result['correlation_id'] ?? '' ),
				'run_id'         => (string) ( $result['run_id'] ?? '' ),
				'reservation_id' => (string) ( $result['reservation_id'] ?? $this->reservation_id ?? '' ),
				'route'          => $route,
			)
		);

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

		// [11b] Canonical phase-end publishing.
		PressArk_Activity_Trace::publish_result_events( $result, $route, is_array( $token_status ) ? $token_status : array() );

		// [12] Build response.
		return array(
			'token_status' => $token_status,
			'response'     => $this->build_response( $result, $route, $token_status ),
		);
	}
}
