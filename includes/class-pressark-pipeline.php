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
			$preview   = call_user_func( $preview_fn, $action_data );
			$pending[] = array(
				'action'              => $action_data,
				'preview'             => $preview,
				'status'              => 'pending_confirmation',
				'permission_decision' => $permission_decision,
				'risk_receipt'        => self::build_risk_receipt(
					$action_data,
					is_array( $preview ) ? $preview : array(),
					$permission_decision
				),
			);
		}

		return $pending;
	}

	// ── Response Building ──────────────────────────────────────────

	/**
	 * Build a compact structural risk receipt for approval surfaces.
	 *
	 * @param array $action              Action payload with type/params.
	 * @param array $preview             Optional preview metadata.
	 * @param array $permission_decision Optional permission decision.
	 * @param array $context             Extra receipt context.
	 * @return array
	 */
	public static function build_risk_receipt(
		array $action,
		array $preview = array(),
		array $permission_decision = array(),
		array $context = array()
	): array {
		$action_name   = sanitize_key( (string) ( $action['type'] ?? $action['name'] ?? '' ) );
		$params        = is_array( $action['params'] ?? null ) ? $action['params'] : (array) ( $action['arguments'] ?? array() );
		$operation     = class_exists( 'PressArk_Operation_Registry' ) ? PressArk_Operation_Registry::resolve( $action_name ) : null;
		$capability    = sanitize_key( (string) ( $context['capability'] ?? ( $operation->capability ?? '' ) ) );
		$action_count  = max( 1, (int) ( $context['action_count'] ?? 1 ) );
		$approval_mode = sanitize_key( (string) ( $context['approval_mode'] ?? $permission_decision['approval']['mode'] ?? '' ) );
		$risk_level    = sanitize_key( (string) ( $context['risk_level'] ?? ( $operation->risk ?? '' ) ) );
		$downstream    = array_merge(
			self::build_downstream_effects( $operation, $permission_decision, $action_count ),
			(array) ( $context['downstream_effects'] ?? array() )
		);
		$verification  = sanitize_text_field(
			(string) (
				$context['verification_plan']
				?? self::build_verification_plan( $operation, $approval_mode )
			)
		);
		$target        = sanitize_text_field(
			(string) (
				$context['target']
				?? self::summarize_action_target( $action, $preview, $action_count )
			)
		);
		$blast_radius  = sanitize_text_field(
			(string) (
				$context['blast_radius']
				?? self::describe_blast_radius( $operation, $action, $preview, $action_count )
			)
		);
		$reversibility = sanitize_text_field(
			(string) (
				$context['reversibility']
				?? self::describe_reversibility( $operation, $approval_mode )
			)
		);

		if ( '' === $capability && class_exists( 'PressArk_Operation_Registry' ) ) {
			$capability = sanitize_key( (string) PressArk_Operation_Registry::classify( $action_name, $params ) );
		}

		if ( '' === $risk_level ) {
			$risk_level = in_array( $capability, array( 'confirm', 'preview' ), true ) ? 'moderate' : 'safe';
		}

		$receipt = array(
			'contract'           => 'risk_receipt',
			'version'            => 1,
			'operation'          => $action_name,
			'label'              => sanitize_text_field(
				(string) (
					$context['label']
					?? ( $operation->label ?? ucwords( str_replace( '_', ' ', $action_name ?: 'change' ) ) )
				)
			),
			'capability'         => $capability,
			'risk_level'         => $risk_level,
			'approval_mode'      => $approval_mode,
			'target'             => $target,
			'blast_radius'       => $blast_radius,
			'reversibility'      => $reversibility,
			'downstream_effects' => self::sanitize_string_list( $downstream ),
			'verification_plan'  => $verification,
		);

		return array_filter(
			$receipt,
			static function ( $value, $key ) {
				if ( 'version' === $key ) {
					return true;
				}
				if ( 'downstream_effects' === $key ) {
					return ! empty( $value );
				}
				return '' !== (string) $value;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Build a compact chat-facing run transparency surface.
	 *
	 * @param array  $result         Raw result payload.
	 * @param string $route          Execution route.
	 * @param array  $activity_strip Optional compact activity strip.
	 * @return array
	 */
	public static function build_run_surface( array $result, string $route, array $activity_strip = array(), array $budget = array() ): array {
		$routing          = is_array( $result['routing_decision'] ?? null ) ? $result['routing_decision'] : array();
		$inspector        = is_array( $result['context_inspector'] ?? null ) ? $result['context_inspector'] : array();
		$provider_request = is_array( $inspector['provider_request'] ?? null ) ? $inspector['provider_request'] : array();
		$transport_contract = is_array( $provider_request['transport_contract'] ?? null ) ? $provider_request['transport_contract'] : array();
		$tool_contract      = is_array( $transport_contract['tool_choice'] ?? null ) ? $transport_contract['tool_choice'] : array();
		$schema_contract    = is_array( $transport_contract['structured_output'] ?? null ) ? $transport_contract['structured_output'] : array();
		$tool_contract_row   = self::describe_tool_choice_contract( $tool_contract );
		$schema_contract_row = self::describe_structured_output_contract( $schema_contract );
		$tool_context        = self::build_tool_context( $result, $inspector );
		$harness_state       = self::build_harness_state( $result, $inspector );
		$fallback         = is_array( $routing['fallback'] ?? null ) ? $routing['fallback'] : array();
		$fallback_used    = ! empty( $fallback['used'] );
		$fallback_detail  = '';

		if ( $fallback_used ) {
			$fallback_failure = sanitize_text_field( (string) ( $fallback['failure_class'] ?? '' ) );
			if ( '' !== $fallback_failure ) {
				$fallback_detail = ucwords( str_replace( '_', ' ', $fallback_failure ) ) . ' fallback';
			} elseif ( ! empty( $fallback['considered'] ) && is_array( $fallback['considered'] ) ) {
				$fallback_detail = 'Switched models to keep the run moving.';
			} else {
				$fallback_detail = 'Fallback model used.';
			}
		}

		$degraded_labels = array();
		foreach ( (array) ( $activity_strip['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
			if ( ! in_array( $status, array( 'degraded', 'blocked' ), true ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( '' !== $label ) {
				$degraded_labels[ $label ] = true;
			}
		}

		if ( empty( $degraded_labels ) ) {
			$exit_reason = sanitize_key( (string) ( $result['exit_reason'] ?? '' ) );
			if ( in_array( $exit_reason, array( 'token_budget', 'max_request_icus_compacted' ), true ) ) {
				$degraded_labels['Token budget reached'] = true;
			}
		}
		if ( ! empty( $tool_contract_row['degraded_label'] ) ) {
			$degraded_labels[ (string) $tool_contract_row['degraded_label'] ] = true;
		}
		if ( ! empty( $schema_contract_row['degraded_label'] ) ) {
			$degraded_labels[ (string) $schema_contract_row['degraded_label'] ] = true;
		}

		$degraded_summary = '';
		if ( ! empty( $degraded_labels ) ) {
			$degraded_summary = implode( ' • ', array_slice( array_keys( $degraded_labels ), 0, 2 ) );
		}

		$metric_chips = self::build_run_metric_chips( $harness_state, $tool_context );
		$notices      = self::build_run_notices(
			$harness_state,
			$fallback_used,
			$fallback_detail,
			$tool_contract_row,
			$schema_contract_row
		);

		$route_label = self::describe_route_label( $route );
		$model_class = self::describe_model_class( $routing );
		$model              = sanitize_text_field( (string) ( $provider_request['model'] ?? $result['model'] ?? $routing['model'] ?? '' ) );
		$transport_mode     = sanitize_text_field( (string) ( $provider_request['transport_mode'] ?? $budget['transport_mode'] ?? $routing['transport_mode'] ?? '' ) );
		$transport_provider = sanitize_text_field( (string) ( $provider_request['transport_provider'] ?? $result['provider'] ?? $routing['provider'] ?? '' ) );

		$badges = array(
			array(
				'label' => $route_label,
				'tone'  => 'neutral',
			),
		);
		if ( '' !== $model_class ) {
			$badges[] = array(
				'label' => $model_class,
				'tone'  => 'neutral',
			);
		}
		if ( $fallback_used ) {
			$badges[] = array(
				'label' => 'Fallback',
				'tone'  => 'warning',
			);
		}
		if ( '' !== $degraded_summary ) {
			$badges[] = array(
				'label' => 'Degraded',
				'tone'  => 'warning',
			);
		}
		if ( ! empty( $tool_context['loaded_groups'] ) ) {
			$badges[] = array(
				'label' => count( (array) $tool_context['loaded_groups'] ) . ' tool groups',
				'tone'  => 'neutral',
			);
		}

		$summary_parts = array( $route_label );
		if ( '' !== $model_class ) {
			$summary_parts[] = $model_class;
		}
		if ( $fallback_used ) {
			$summary_parts[] = 'Fallback active';
		}
		if ( '' !== $degraded_summary ) {
			$summary_parts[] = $degraded_summary;
		}
		if ( ! empty( $harness_state['deferred_groups'] ) ) {
			$summary_parts[] = count( (array) $harness_state['deferred_groups'] ) . ' deferred';
		}
		if ( ! empty( $harness_state['blocked_groups'] ) ) {
			$summary_parts[] = count( (array) $harness_state['blocked_groups'] ) . ' blocked';
		} elseif ( ! empty( $tool_context['loaded_groups'] ) ) {
			$summary_parts[] = count( (array) $tool_context['loaded_groups'] ) . ' tool groups loaded';
		}

		$detail_rows = array(
			array(
				'label' => 'Route',
				'value' => $route_label,
			),
		);
		if ( '' !== $model_class ) {
			$detail_rows[] = array(
				'label' => 'Model class',
				'value' => $model_class,
			);
		}
		if ( '' !== $transport_mode ) {
			$detail_rows[] = array(
				'label' => 'Transport mode',
				'value' => $transport_mode,
			);
		}
		if ( '' !== $transport_provider ) {
			$detail_rows[] = array(
				'label' => 'Transport provider',
				'value' => $transport_provider,
			);
		}
		if ( '' !== $model ) {
			$detail_rows[] = array(
				'label' => 'Model',
				'value' => $model,
			);
		}
		if ( ! empty( $tool_contract_row['value'] ) ) {
			$detail_rows[] = array(
				'label' => 'Tool choice',
				'value' => (string) $tool_contract_row['value'],
			);
		}
		if ( ! empty( $schema_contract_row['value'] ) ) {
			$detail_rows[] = array(
				'label' => 'Structured output',
				'value' => (string) $schema_contract_row['value'],
			);
		}
		if ( $fallback_used ) {
			$detail_rows[] = array(
				'label' => 'Fallback',
				'value' => $fallback_detail,
			);
		}
		if ( '' !== $degraded_summary ) {
			$detail_rows[] = array(
				'label' => 'Degraded state',
				'value' => $degraded_summary,
			);
		}
		if ( ! empty( $harness_state['loaded_groups'] ) ) {
			$detail_rows[] = array(
				'label' => 'Loaded groups',
				'value' => self::format_group_list_value( (array) $harness_state['loaded_groups'] ),
			);
		}
		if ( ! empty( $harness_state['deferred_groups'] ) ) {
			$detail_rows[] = array(
				'label' => 'Deferred groups',
				'value' => self::format_group_list_value( (array) $harness_state['deferred_groups'] ),
			);
		}
		if ( ! empty( $harness_state['blocked_groups'] ) ) {
			$detail_rows[] = array(
				'label' => 'Blocked groups',
				'value' => self::format_group_list_value( (array) $harness_state['blocked_groups'] ),
			);
		}
		if ( ! empty( $harness_state['context_trim']['count'] ) ) {
			$detail_rows[] = array(
				'label' => 'Context trim',
				'value' => max( 0, (int) $harness_state['context_trim']['count'] ) . ' compaction' . ( (int) $harness_state['context_trim']['count'] > 1 ? 's' : '' ),
			);
		}
		if ( ! empty( $tool_context['loaded_groups'] ) ) {
			$tool_groups = array_slice( (array) $tool_context['loaded_groups'], 0, 4 );
			if ( count( (array) $tool_context['loaded_groups'] ) > count( $tool_groups ) ) {
				$tool_groups[] = '+' . ( count( (array) $tool_context['loaded_groups'] ) - count( $tool_groups ) ) . ' more';
			}
			$detail_rows[] = array(
				'label' => 'Tool context',
				'value' => implode( ', ', $tool_groups ),
			);
		} elseif ( ! empty( $tool_context['loaded_count'] ) ) {
			$detail_rows[] = array(
				'label' => 'Tool context',
				'value' => (int) $tool_context['loaded_count'] . ' tools ready',
			);
		}

		$billing_receipt = is_array( $budget['billing_receipt'] ?? null ) ? (array) $budget['billing_receipt'] : array();
		if ( ! empty( $billing_receipt['authority_label'] ) ) {
			$badges[] = array(
				'label' => (string) $billing_receipt['authority_label'],
				'tone'  => 'neutral',
			);
		}
		if ( ! empty( $billing_receipt['reduced_certainty'] ) ) {
			$badges[] = array(
				'label' => 'Reduced certainty',
				'tone'  => 'warning',
			);
		}
		if ( ! empty( $billing_receipt['summary'] ) ) {
			$detail_rows[] = array(
				'label' => 'Billing',
				'value' => sanitize_text_field( (string) $billing_receipt['summary'] ),
			);
		}

		$surface = array(
			'contract'     => 'run_surface',
			'version'      => 3,
			'route'        => sanitize_key( $route ),
			'route_label'  => $route_label,
			'summary'      => implode( ' • ', array_filter( $summary_parts ) ),
			'badges'       => array_values( array_filter( $badges ) ),
			'metric_chips' => $metric_chips,
			'notices'      => $notices,
			'detail_rows'  => array_values( array_filter( $detail_rows ) ),
			'billing_receipt' => $billing_receipt,
			'fallback'     => $fallback_used
				? array(
					'used'   => true,
					'detail' => $fallback_detail,
				)
				: array(),
			'degraded'     => '' !== $degraded_summary
				? array(
					'state'   => 'degraded',
					'summary' => $degraded_summary,
				)
				: array(),
			'tool_context' => $tool_context,
			'harness_state' => $harness_state,
		);

		return array_filter(
			$surface,
			static function ( $value, $key ) {
				if ( 'version' === $key ) {
					return true;
				}
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Build a compact activity strip from canonical trace events when available.
	 *
	 * @param array  $result Raw result payload.
	 * @param string $route  Execution route.
	 * @return array
	 */
	public static function build_activity_strip( array $result, string $route ): array {
		$events       = is_array( $result['activity_events'] ?? null ) ? $result['activity_events'] : array();
		$items        = array();
		$should_phase = ! empty( $events )
			|| in_array( (string) ( $result['type'] ?? '' ), array( 'preview', 'confirm_card' ), true )
			|| ! empty( $result['approval_outcome'] )
			|| ! empty( $result['cancelled'] )
			|| ! empty( $result['is_error'] )
			|| ! empty( $result['exit_reason'] )
			|| ! empty( $result['routing_decision']['fallback']['used'] );

		if ( class_exists( 'PressArk_Activity_Trace' ) ) {
			if ( $should_phase ) {
				$events[] = self::synthesize_phase_end_event( $result, $route );
			}

			$deduped = array();
			foreach ( $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}

				$item = PressArk_Activity_Trace::describe_event_for_chat( $event );
				if ( empty( $item ) ) {
					continue;
				}

				$key             = sanitize_key( (string) ( $item['reason'] ?? '' ) ) . '|' . sanitize_key( (string) ( $item['status'] ?? '' ) ) . '|' . sanitize_key( (string) ( $item['label'] ?? '' ) );
				$deduped[ $key ] = $item;
			}
			$items = array_values( $deduped );
		}

		if ( empty( $items ) && ! empty( $result['steps'] ) && is_array( $result['steps'] ) ) {
			foreach ( (array) $result['steps'] as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $step['label'] ?? $step['tool'] ?? '' ) );
				if ( '' === $label ) {
					continue;
				}
				$items[] = array(
					'status' => sanitize_key( (string) ( $step['status'] ?? 'done' ) ),
					'label'  => $label,
					'detail' => '',
					'reason' => '',
					'source' => 'step',
				);
			}
		}

		if ( empty( $items ) ) {
			return array();
		}

		if ( count( $items ) > 4 ) {
			$items = array_slice( $items, -4 );
		}

		return array(
			'contract' => 'activity_strip',
			'version'  => 1,
			'items'    => $items,
		);
	}

	/**
	 * Normalize a list of strings.
	 *
	 * @param array $values Input values.
	 * @return array
	 */
	private static function sanitize_string_list( array $values ): array {
		$clean = array();
		foreach ( $values as $value ) {
			$text = sanitize_text_field( (string) $value );
			if ( '' !== $text ) {
				$clean[ $text ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * Summarize the action target for approval surfaces.
	 *
	 * @param array $action       Action payload.
	 * @param array $preview      Preview payload.
	 * @param int   $action_count Number of grouped actions.
	 * @return string
	 */
	private static function summarize_action_target( array $action, array $preview = array(), int $action_count = 1 ): string {
		$params = is_array( $action['params'] ?? null ) ? $action['params'] : (array) ( $action['arguments'] ?? array() );
		$title  = sanitize_text_field( (string) ( $preview['post_title'] ?? $preview['target'] ?? '' ) );

		if ( '' !== $title ) {
			return $title;
		}

		foreach ( (array) ( $preview['diff'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $entry['label'] ?? '' ) );
			if ( '' !== $label ) {
				return $label;
			}
		}

		if ( ! empty( $params['post_id'] ) ) {
			return 'Post #' . absint( $params['post_id'] );
		}

		if ( ! empty( $params['post_ids'] ) && is_array( $params['post_ids'] ) ) {
			$post_ids = array_values( array_filter( array_map( 'absint', $params['post_ids'] ) ) );
			if ( count( $post_ids ) > 1 ) {
				return count( $post_ids ) . ' selected items';
			}
			if ( 1 === count( $post_ids ) ) {
				return 'Post #' . (int) $post_ids[0];
			}
		}

		if ( ! empty( $params['title'] ) ) {
			return '"' . sanitize_text_field( (string) $params['title'] ) . '"';
		}

		if ( ! empty( $params['name'] ) ) {
			return sanitize_text_field( (string) $params['name'] );
		}

		if ( $action_count > 1 ) {
			return $action_count . ' staged changes';
		}

		return 'Site';
	}

	/**
	 * Describe the blast radius for a change.
	 *
	 * @param mixed $operation     Resolved operation or null.
	 * @param array $action        Action payload.
	 * @param array $preview       Preview payload.
	 * @param int   $action_count  Number of grouped actions.
	 * @return string
	 */
	private static function describe_blast_radius( $operation, array $action, array $preview = array(), int $action_count = 1 ): string {
		$params = is_array( $action['params'] ?? null ) ? $action['params'] : (array) ( $action['arguments'] ?? array() );
		if ( ! empty( $params['post_ids'] ) && is_array( $params['post_ids'] ) ) {
			$post_ids = array_values( array_filter( array_map( 'absint', $params['post_ids'] ) ) );
			if ( count( $post_ids ) > 1 ) {
				return count( $post_ids ) . ' targets';
			}
		}

		if ( $action_count > 1 ) {
			return $action_count . ' staged changes';
		}

		if ( ( $operation && 'bulk' === (string) $operation->group ) || ! empty( $params['items'] ) ) {
			return 'Multiple targets';
		}

		if ( ( $operation && 'settings' === (string) $operation->group ) || ! empty( $params['settings'] ) || ! empty( $params['option_name'] ) ) {
			return 'Site-wide';
		}

		if ( ! empty( $params['post_id'] ) || ! empty( $preview['post_title'] ) ) {
			return 'Single target';
		}

		return 'Focused change';
	}

	/**
	 * Describe how reversible an action is.
	 *
	 * @param mixed  $operation     Resolved operation or null.
	 * @param string $approval_mode Approval mode.
	 * @return string
	 */
	private static function describe_reversibility( $operation, string $approval_mode ): string {
		if ( 'preview' === $approval_mode || ( $operation && 'preview' === (string) $operation->capability ) ) {
			return 'Reversible until applied';
		}

		if ( $operation && ( 'destructive' === (string) $operation->risk || in_array( 'irreversible', (array) ( $operation->tags ?? array() ), true ) ) ) {
			return 'Manual recovery may be required';
		}

		if ( $operation && ! empty( $operation->idempotent ) ) {
			return 'Safe to retry if needed';
		}

		return 'Undo or revision restore may be needed';
	}

	/**
	 * Describe downstream effects for the approval surface.
	 *
	 * @param mixed $operation           Resolved operation or null.
	 * @param array $permission_decision Permission decision payload.
	 * @param int   $action_count        Number of grouped actions.
	 * @return array
	 */
	private static function build_downstream_effects( $operation, array $permission_decision, int $action_count ): array {
		$effects = array();

		if ( $operation && ! empty( $operation->read_invalidation['reason'] ) ) {
			$effects[] = sanitize_text_field( (string) $operation->read_invalidation['reason'] );
		}

		if ( $operation && ! empty( $operation->requires ) ) {
			$effects[] = ucfirst( sanitize_text_field( (string) $operation->requires ) ) . ' context is required.';
		}

		if ( $action_count > 1 ) {
			$effects[] = 'More than one target may change in this run.';
		}

		foreach ( (array) ( $permission_decision['reasons'] ?? array() ) as $reason ) {
			$reason = sanitize_text_field( (string) $reason );
			if ( '' !== $reason ) {
				$effects[] = $reason;
				break;
			}
		}

		return self::sanitize_string_list( $effects );
	}

	/**
	 * Describe how the backend plans to verify the action.
	 *
	 * @param mixed  $operation     Resolved operation or null.
	 * @param string $approval_mode Approval mode.
	 * @return string
	 */
	private static function build_verification_plan( $operation, string $approval_mode ): string {
		if ( 'preview' === $approval_mode ) {
			return 'Review the staged diff before applying.';
		}

		if ( ! $operation || empty( $operation->verification ) || ! is_array( $operation->verification ) ) {
			return 'User review recommended after the change.';
		}

		$verification = (array) $operation->verification;
		$strategy     = sanitize_key( (string) ( $verification['strategy'] ?? '' ) );
		$read_tool    = sanitize_key( (string) ( $verification['read_tool'] ?? '' ) );
		$fields       = self::sanitize_string_list( (array) ( $verification['check_fields'] ?? array() ) );

		switch ( $strategy ) {
			case 'read_back':
				return '' !== $read_tool
					? 'Read back the result with ' . $read_tool . '.'
					: 'Read the updated target back after execution.';
			case 'field_check':
				return ! empty( $fields ) && '' !== $read_tool
					? 'Check ' . implode( ', ', array_slice( $fields, 0, 3 ) ) . ' via ' . $read_tool . '.'
					: 'Verify the changed fields after execution.';
			case 'existence_check':
				return '' !== $read_tool
					? 'Confirm the updated target exists via ' . $read_tool . '.'
					: 'Confirm the updated target exists after execution.';
			case 'none':
				return 'No automatic read-back. User review is recommended.';
			default:
				return 'Review the final result after execution.';
		}
	}

	/**
	 * Resolve the compact harness-state payload from the result or inspector.
	 *
	 * @param array $result    Raw result payload.
	 * @param array $inspector Optional context inspector.
	 * @return array
	 */
	private static function build_harness_state( array $result, array $inspector = array() ): array {
		$state = is_array( $result['harness_state'] ?? null ) ? (array) $result['harness_state'] : array();
		if ( empty( $state ) && is_array( $inspector['harness_state'] ?? null ) ) {
			$state = (array) $inspector['harness_state'];
		}
		if ( empty( $state ) && is_array( $inspector['prompt']['harness_state'] ?? null ) ) {
			$state = (array) $inspector['prompt']['harness_state'];
		}

		return $state;
	}

	/**
	 * Build compact state chips for the run card.
	 *
	 * @param array $harness_state Canonical harness-state payload.
	 * @param array $tool_context  Compact tool-context payload.
	 * @return array
	 */
	private static function build_run_metric_chips( array $harness_state, array $tool_context = array() ): array {
		$chips = array();

		if ( ! empty( $harness_state['loaded_groups'] ) ) {
			$chips[] = array(
				'label' => 'Loaded',
				'value' => self::format_group_list_value( (array) $harness_state['loaded_groups'] ),
				'tone'  => 'neutral',
			);
		} elseif ( ! empty( $tool_context['loaded_count'] ) ) {
			$chips[] = array(
				'label' => 'Loaded',
				'value' => (int) $tool_context['loaded_count'] . ' tools',
				'tone'  => 'neutral',
			);
		}

		if ( ! empty( $harness_state['deferred_groups'] ) ) {
			$chips[] = array(
				'label' => 'Deferred',
				'value' => self::format_group_list_value( (array) $harness_state['deferred_groups'] ),
				'tone'  => 'neutral',
			);
		}

		if ( ! empty( $harness_state['blocked_groups'] ) ) {
			$chips[] = array(
				'label' => 'Blocked',
				'value' => self::format_group_list_value( (array) $harness_state['blocked_groups'] ),
				'tone'  => 'warning',
			);
		}

		if ( ! empty( $harness_state['context_trim']['count'] ) ) {
			$count   = max( 0, (int) $harness_state['context_trim']['count'] );
			$chips[] = array(
				'label' => 'Trimmed',
				'value' => $count . ' compacted context block' . ( $count > 1 ? 's' : '' ),
				'tone'  => 'warning',
			);
		}

		return array_values( array_slice( array_filter( $chips ), 0, 4 ) );
	}

	/**
	 * Build actionable notices for the run card.
	 *
	 * @param array  $harness_state      Canonical harness-state payload.
	 * @param bool   $fallback_used      Whether fallback was used.
	 * @param string $fallback_detail    Fallback detail string.
	 * @param array  $tool_contract_row  Tool-choice contract row.
	 * @param array  $schema_contract_row Structured-output contract row.
	 * @return array
	 */
	private static function build_run_notices(
		array $harness_state,
		bool $fallback_used,
		string $fallback_detail,
		array $tool_contract_row = array(),
		array $schema_contract_row = array()
	): array {
		$notices = array();

		foreach ( array_slice( (array) ( $harness_state['hidden_reasons'] ?? array() ), 0, 2 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'warning',
					'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'text'  => sanitize_text_field( (string) ( $row['summary'] ?? '' ) ),
					'hint'  => sanitize_text_field( (string) ( $row['hint'] ?? '' ) ),
					'meta'  => self::format_group_list_value( (array) ( $row['groups'] ?? array() ), 2 ),
				)
			);
		}

		if ( ! empty( $harness_state['context_trim'] ) && is_array( $harness_state['context_trim'] ) ) {
			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'neutral',
					'label' => sanitize_text_field( (string) ( $harness_state['context_trim']['label'] ?? 'Context trimmed' ) ),
					'text'  => sanitize_text_field( (string) ( $harness_state['context_trim']['detail'] ?? '' ) ),
					'hint'  => sanitize_text_field( (string) ( $harness_state['context_trim']['hint'] ?? '' ) ),
				)
			);
		}

		$route_state = is_array( $harness_state['route_status'] ?? null ) ? (array) $harness_state['route_status'] : array();
		if ( $fallback_used || ! empty( $route_state ) ) {
			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'warning',
					'label' => sanitize_text_field( (string) ( $route_state['label'] ?? 'Fallback route active' ) ),
					'text'  => '' !== $fallback_detail
						? $fallback_detail
						: sanitize_text_field( (string) ( $route_state['detail'] ?? 'The run switched routes to keep moving.' ) ),
					'hint'  => sanitize_text_field(
						(string) (
							$route_state['hint']
							?? 'If you need the strongest tool or format support, retry in Deep Mode or rerun later.'
						)
					),
				)
			);
		}

		if ( ! empty( $tool_contract_row['degraded_label'] ) ) {
			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'warning',
					'label' => sanitize_text_field( (string) $tool_contract_row['degraded_label'] ),
					'text'  => sanitize_text_field( (string) ( $tool_contract_row['value'] ?? '' ) ),
					'hint'  => sanitize_text_field( (string) ( $tool_contract_row['hint'] ?? '' ) ),
				)
			);
		}

		if ( ! empty( $schema_contract_row['degraded_label'] ) ) {
			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'warning',
					'label' => sanitize_text_field( (string) $schema_contract_row['degraded_label'] ),
					'text'  => sanitize_text_field( (string) ( $schema_contract_row['value'] ?? '' ) ),
					'hint'  => sanitize_text_field( (string) ( $schema_contract_row['hint'] ?? '' ) ),
				)
			);
		}

		if ( empty( $notices ) && ! empty( $harness_state['deferred_rows'][0] ) && is_array( $harness_state['deferred_rows'][0] ) ) {
			$deferred = (array) $harness_state['deferred_rows'][0];
			self::append_run_notice(
				$notices,
				array(
					'tone'  => 'neutral',
					'label' => 'Deferred for this run',
					'text'  => sanitize_text_field( (string) ( $deferred['summary'] ?? 'Some capabilities were deferred to keep the run compact.' ) ),
					'hint'  => sanitize_text_field( (string) ( $deferred['hint'] ?? '' ) ),
					'meta'  => sanitize_text_field( (string) ( $deferred['label'] ?? '' ) ),
				)
			);
		}

		return array_values( array_slice( array_values( $notices ), 0, 4 ) );
	}

	/**
	 * Append a deduplicated notice row.
	 *
	 * @param array $notices Notice bucket.
	 * @param array $notice  Notice payload.
	 */
	private static function append_run_notice( array &$notices, array $notice ): void {
		$label = sanitize_text_field( (string) ( $notice['label'] ?? '' ) );
		$text  = sanitize_text_field( (string) ( $notice['text'] ?? '' ) );
		if ( '' === $label && '' === $text ) {
			return;
		}

		$key = sanitize_key( $label . '|' . (string) ( $notice['tone'] ?? 'neutral' ) );
		$notices[ $key ] = array_filter(
			array(
				'tone'  => sanitize_key( (string) ( $notice['tone'] ?? 'neutral' ) ),
				'label' => $label,
				'text'  => $text,
				'hint'  => sanitize_text_field( (string) ( $notice['hint'] ?? '' ) ),
				'meta'  => sanitize_text_field( (string) ( $notice['meta'] ?? '' ) ),
			),
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Render a compact group list for run-card rows and chips.
	 *
	 * @param array $groups Group names.
	 * @param int   $limit  Display limit.
	 * @return string
	 */
	private static function format_group_list_value( array $groups, int $limit = 3 ): string {
		$groups = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $group ): string {
							return self::display_group_label( (string) $group );
						},
						$groups
					)
				)
			)
		);
		if ( empty( $groups ) ) {
			return '';
		}

		$visible = array_slice( $groups, 0, max( 1, $limit ) );
		if ( count( $groups ) > count( $visible ) ) {
			$visible[] = '+' . ( count( $groups ) - count( $visible ) ) . ' more';
		}

		return implode( ', ', $visible );
	}

	/**
	 * Render a compact display label for a tool group.
	 *
	 * @param string $group Group name.
	 * @return string
	 */
	private static function display_group_label( string $group ): string {
		$group = sanitize_key( $group );
		return '' === $group ? '' : ucwords( str_replace( '_', ' ', $group ) );
	}

	/**
	 * Build the compact tool context for run transparency.
	 *
	 * @param array $result    Raw result payload.
	 * @param array $inspector Optional context inspector payload.
	 * @return array
	 */
	private static function build_tool_context( array $result, array $inspector = array() ): array {
		$tool_loading = is_array( $result['tool_loading'] ?? null ) ? $result['tool_loading'] : array();
		$tool_surface = is_array( $inspector['tool_surface'] ?? null ) ? $inspector['tool_surface'] : array();
		$groups       = self::sanitize_string_list(
			(array) ( $tool_loading['final_groups'] ?? $result['loaded_groups'] ?? array() )
		);

		return array_filter(
			array(
				'loaded_groups'    => $groups,
				'loaded_count'     => (int) ( $tool_loading['loaded_count'] ?? count( (array) ( $tool_surface['loaded_tools'] ?? array() ) ) ),
				'visible_count'    => (int) ( $tool_loading['visible_count'] ?? count( (array) ( $tool_surface['visible_tools'] ?? array() ) ) ),
				'searchable_count' => (int) ( $tool_loading['searchable_count'] ?? count( (array) ( $tool_surface['searchable_tools'] ?? array() ) ) ),
				'discovered_count' => (int) ( $tool_loading['discovered_count'] ?? count( (array) ( $tool_surface['discovered_tools'] ?? array() ) ) ),
			),
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : 0 === (int) $value );
			}
		);
	}

	/**
	 * Describe the route label used in the compact run surface.
	 *
	 * @param string $route Route key.
	 * @return string
	 */
	private static function describe_route_label( string $route ): string {
		switch ( sanitize_key( $route ) ) {
			case 'agent':
				return 'Agent route';
			case 'async':
				return 'Async handoff';
			case 'legacy':
				return 'Legacy route';
			default:
				return 'Standard route';
		}
	}

	/**
	 * Describe the model class using routing metadata.
	 *
	 * @param array $routing Routing decision payload.
	 * @return string
	 */
	private static function describe_model_class( array $routing ): string {
		$selection = is_array( $routing['selection'] ?? null ) ? $routing['selection'] : array();
		if ( ! empty( $selection['deep_mode'] ) ) {
			return 'Deep mode';
		}

		switch ( sanitize_key( (string) ( $selection['mode'] ?? '' ) ) ) {
			case 'byok':
				return 'BYOK model';
			case 'pinned':
				return 'Pinned model';
			case 'auto':
				return 'Auto model';
			default:
				return '';
		}
	}

	/**
	 * Describe the effective tool-choice contract shown in run metadata.
	 *
	 * @param array $contract Transport contract snapshot.
	 * @return array<string,string>
	 */
	private static function describe_tool_choice_contract( array $contract ): array {
		$requested = sanitize_key( (string) ( $contract['requested'] ?? '' ) );
		$effective = sanitize_key( (string) ( $contract['effective'] ?? '' ) );
		$transport = sanitize_text_field( (string) ( $contract['transport'] ?? '' ) );
		if ( '' === $requested && '' === $effective && '' === $transport ) {
			return array();
		}

		$value = self::describe_contract_mode( $effective, 'tool' );
		$via   = self::describe_contract_transport( $transport );
		if ( '' !== $via ) {
			$value .= ' via ' . $via;
		}

		$degraded_label = '';
		$hint           = '';
		if ( ! empty( $contract['downgraded'] ) ) {
			$degraded_label = 'Tool policy downgraded';
			$value         .= self::describe_contract_downgrade( $requested, $effective, (string) ( $contract['reason'] ?? '' ), 'tool' );
			$hint           = self::describe_contract_hint( (string) ( $contract['reason'] ?? '' ), 'tool' );
		}

		return array(
			'value'          => $value,
			'degraded_label' => $degraded_label,
			'hint'           => $hint,
		);
	}

	/**
	 * Describe the effective structured-output contract shown in run metadata.
	 *
	 * @param array $contract Transport contract snapshot.
	 * @return array<string,string>
	 */
	private static function describe_structured_output_contract( array $contract ): array {
		$requested = sanitize_key( (string) ( $contract['requested'] ?? '' ) );
		$effective = sanitize_key( (string) ( $contract['effective'] ?? '' ) );
		$transport = sanitize_text_field( (string) ( $contract['transport'] ?? '' ) );
		if ( '' === $requested && '' === $effective && '' === $transport ) {
			return array();
		}

		$value = self::describe_contract_mode( $effective, 'schema' );
		$via   = self::describe_contract_transport( $transport );
		if ( '' !== $via ) {
			$value .= ' via ' . $via;
		}

		$degraded_label = '';
		$hint           = '';
		if ( ! empty( $contract['downgraded'] ) ) {
			$degraded_label = 'Structured output downgraded';
			$value         .= self::describe_contract_downgrade( $requested, $effective, (string) ( $contract['reason'] ?? '' ), 'schema' );
			$hint           = self::describe_contract_hint( (string) ( $contract['reason'] ?? '' ), 'schema' );
		}

		return array(
			'value'          => $value,
			'degraded_label' => $degraded_label,
			'hint'           => $hint,
		);
	}

	/**
	 * Render a contract mode in concise user-facing language.
	 */
	private static function describe_contract_mode( string $mode, string $kind ): string {
		$mode = sanitize_key( $mode );

		if ( 'schema' === $kind ) {
			return match ( $mode ) {
				'strict'      => 'Strict JSON schema',
				'prompt_only' => 'Prompt-only schema guidance',
				'none'        => 'No schema enforcement',
				default       => '' === $mode ? '' : ucwords( str_replace( '_', ' ', $mode ) ),
			};
		}

		return match ( $mode ) {
			'text_only'       => 'Text-only replies',
			'restricted_auto' => 'Restricted auto tools',
			'required', 'any' => 'Required tool use',
			default           => '' === $mode ? '' : ucwords( str_replace( '_', ' ', $mode ) ),
		};
	}

	/**
	 * Render the transport channel that actually enforced the contract.
	 */
	private static function describe_contract_transport( string $transport ): string {
		$transport = trim( $transport );

		return match ( $transport ) {
			'response_format'      => 'response_format',
			'output_config.format' => 'output_config.format',
			'prompt_only'          => 'prompt instructions',
			'auto'                 => 'tool_choice=auto',
			'required'             => 'tool_choice=required',
			'any'                  => 'tool_choice=any',
			'none'                 => 'tool_choice=none',
			'omitted'              => 'provider default behavior',
			default                => sanitize_text_field( $transport ),
		};
	}

	/**
	 * Render an explicit downgrade note when requested and enforced contracts differ.
	 */
	private static function describe_contract_downgrade( string $requested, string $effective, string $reason, string $kind ): string {
		$parts         = array();
		$request_label = self::describe_contract_mode( $requested, $kind );

		if ( '' !== $request_label && $requested !== $effective ) {
			$parts[] = 'from ' . strtolower( $request_label );
		}

		$reason_label = self::describe_contract_reason( $reason );
		if ( '' !== $reason_label ) {
			$parts[] = $reason_label;
		}

		return empty( $parts ) ? ' (downgraded)' : ' (downgraded: ' . implode( '; ', $parts ) . ')';
	}

	/**
	 * Render a compact downgrade reason for chat-facing metadata.
	 */
	private static function describe_contract_reason( string $reason ): string {
		return match ( sanitize_key( $reason ) ) {
			'no_tools_exposed' => 'no tools were exposed',
			'provider_cannot_disable_tools' => 'transport cannot disable tools',
			'provider_cannot_require_tools' => 'transport cannot require tools',
			'provider_model_lacks_native_structured_outputs' => 'transport cannot enforce native structured output',
			'schema_missing' => 'schema was missing',
			default => '',
		};
	}

	/**
	 * Render an actionable recovery hint for a downgraded request contract.
	 *
	 * @param string $reason Downgrade reason.
	 * @param string $kind   tool|schema.
	 * @return string
	 */
	private static function describe_contract_hint( string $reason, string $kind ): string {
		$reason = sanitize_key( $reason );

		if ( 'schema' === $kind ) {
			return match ( $reason ) {
				'provider_model_lacks_native_structured_outputs' => 'Ask for a smaller scoped response or retry with a model that supports native structured output.',
				'schema_missing' => 'Retry with a schema-capable request if you need stricter structure guarantees.',
				default => 'If you need stricter formatting guarantees, retry with a model or route that supports them natively.',
			};
		}

		return match ( $reason ) {
			'no_tools_exposed' => 'Load a visible group first if you need tool use on the next step.',
			'provider_cannot_disable_tools' => 'Keep the request focused on the visible tool surface instead of relying on strict tool disable.',
			'provider_cannot_require_tools' => 'If you need guaranteed tool use, retry with a provider or model that supports required tool routing.',
			default => 'If you need stricter tool routing, retry with a route that supports it directly.',
		};
	}

	/**
	 * Build a synthetic phase-end event for chat-facing activity surfaces.
	 *
	 * @param array  $result Raw result payload.
	 * @param string $route  Execution route.
	 * @return array
	 */
	private static function synthesize_phase_end_event( array $result, string $route ): array {
		$status = ! empty( $result['is_error'] ) ? 'failed' : 'succeeded';
		if ( ! empty( $result['cancelled'] ) ) {
			$status = 'cancelled';
		}
		if ( in_array( (string) ( $result['type'] ?? '' ), array( 'preview', 'confirm_card' ), true ) ) {
			$status = 'waiting';
		}

		return array(
			'event_type' => 'run.phase_completed',
			'phase'      => 'phase_end',
			'status'     => $status,
			'reason'     => class_exists( 'PressArk_Activity_Trace' )
				? PressArk_Activity_Trace::infer_terminal_reason( $result )
				: '',
			'payload'    => array(
				'route'    => $route,
				'provider' => (string) ( $result['provider'] ?? '' ),
				'model'    => (string) ( $result['model'] ?? '' ),
			),
		);
	}

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
		$activity_strip = self::build_activity_strip( $result, $route );
		$budget         = array_key_exists( 'budget', $result )
			? $this->build_budget_response( (array) $result['budget'], $result, $token_status )
			: array();
		$run_surface    = self::build_run_surface( $result, $route, $activity_strip, $budget );

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
			'routing_decision',
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
			'approval_outcome',
			'approval_receipt',
		) as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$data[ $field ] = $result[ $field ];
			}
		}

		if ( ! empty( $activity_strip ) ) {
			$data['activity_strip'] = $activity_strip;
		}

		if ( ! empty( $run_surface ) ) {
			$data['run_surface'] = $run_surface;
		}

		if ( ! empty( $budget ) ) {
			$data['budget'] = $budget;
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

			if ( empty( $data['risk_receipt'] ) ) {
				$preview_actions = is_array( $result['preview_actions'] ?? null ) ? $result['preview_actions'] : array();
				if ( ! empty( $preview_actions ) ) {
					$preview_action  = $preview_actions[0];
					$preview_context = array(
						'diff'       => $result['diff'] ?? array(),
						'post_title' => $result['post_title'] ?? '',
						'target'     => $result['target'] ?? '',
					);
					$data['risk_receipt'] = self::build_risk_receipt(
						$preview_action,
						$preview_context,
						array(),
						array(
							'approval_mode' => 'preview',
							'capability'    => 'preview',
							'action_count'  => count( $preview_actions ),
						)
					);
				}
			}
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
			'transport_mode',
			'billing_authority',
			'billing_state',
			'billing_service_state',
			'billing_handshake_state',
			'billing_spend_source',
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
			'reserve_certainty',
			'reserve_envelope',
		) as $field ) {
			if ( array_key_exists( $field, $token_status ) ) {
				$budget[ $field ] = $token_status[ $field ];
			}
		}
		if ( array_key_exists( 'settlement_delta', $token_status ) ) {
			$budget['settlement_delta'] = $token_status['settlement_delta'];
		}

		$budget['fits_credit_budget'] = ! empty( $budget['is_byok'] )
			|| (int) ( $budget['estimated_request_icus'] ?? 0 ) <= max( 0, (int) ( $budget['total_remaining'] ?? 0 ) );
		$budget['billing_receipt'] = $this->build_billing_receipt( $budget, $result, $token_status );

		return $budget;
	}

	private function build_billing_receipt( array $budget, array $result, array $token_status ): array {
		$billing_state = is_array( $budget['billing_state'] ?? null ) ? (array) $budget['billing_state'] : array();
		$settlement    = is_array( $budget['settlement_delta'] ?? null ) ? (array) $budget['settlement_delta'] : array();
		$trace_rollup  = self::aggregate_bank_receipt_trace( $result );

		$planned_icus   = max( 0, (int) ( $budget['estimated_request_icus'] ?? 0 ) );
		$planned_tokens = max( 0, (int) ( $budget['estimated_prompt_tokens'] ?? 0 ) );
		$estimated_icus = max( 0, (int) ( $trace_rollup['estimated_icus'] ?? $settlement['estimated_icus'] ?? $planned_icus ) );
		$estimated_raw_tokens = max( 0, (int) ( $trace_rollup['estimated_raw_tokens'] ?? $settlement['estimated_raw_tokens'] ?? 0 ) );
		$provider_reported_tokens = max( 0, (int) ( $trace_rollup['provider_reported_tokens'] ?? $budget['raw_actual_tokens'] ?? $token_status['raw_actual_tokens'] ?? 0 ) );
		$bank_settled_icus = max( 0, (int) ( $trace_rollup['bank_settled_icus'] ?? $settlement['settled_icus'] ?? $budget['actual_icus'] ?? $token_status['actual_icus'] ?? 0 ) );
		$settlement_delta = array_key_exists( 'delta_icus', $settlement )
			? (int) $settlement['delta_icus']
			: ( $bank_settled_icus > 0 && $estimated_icus > 0 ? $bank_settled_icus - $estimated_icus : 0 );
		$reserve_envelope = is_array( $budget['reserve_envelope'] ?? null ) ? (array) $budget['reserve_envelope'] : (array) ( $billing_state['reserve_envelope'] ?? array() );

		$stages = array();
		if ( $planned_icus > 0 || $planned_tokens > 0 ) {
			$stages[] = array(
				'key'           => 'planned',
				'label'         => 'Planned',
				'value'         => $planned_icus > 0 ? self::format_billing_quantity( $planned_icus, 'ICUs' ) : self::format_billing_quantity( $planned_tokens, 'tokens' ),
				'secondary'     => $planned_icus > 0 && $planned_tokens > 0 ? self::format_billing_quantity( $planned_tokens, 'prompt tokens' ) : '',
				'authority_tag' => 'Plugin plan',
				'note'          => 'Request budget before the run started.',
				'tone'          => 'neutral',
			);
		}

		if ( $estimated_icus > 0 || $estimated_raw_tokens > 0 ) {
			$stages[] = array(
				'key'           => 'estimated',
				'label'         => 'Estimated',
				'value'         => $estimated_icus > 0 ? self::format_billing_quantity( $estimated_icus, 'ICUs' ) : self::format_billing_quantity( $estimated_raw_tokens, 'tokens' ),
				'secondary'     => $estimated_raw_tokens > 0 ? self::format_billing_quantity( $estimated_raw_tokens, 'reserved tokens' ) : '',
				'authority_tag' => 'Plugin estimate',
				'note'          => 'Reserve held before provider usage was finalized.',
				'tone'          => 'neutral',
			);
		}

		if ( $provider_reported_tokens > 0 ) {
			$stages[] = array(
				'key'           => 'provider_reported',
				'label'         => 'Provider reported',
				'value'         => self::format_billing_quantity( $provider_reported_tokens, 'tokens' ),
				'secondary'     => '',
				'authority_tag' => 'Provider report',
				'note'          => 'Raw usage reported after execution.',
				'tone'          => 'neutral',
			);
		}

		if ( $bank_settled_icus > 0 || ! empty( $settlement ) ) {
			$delta_prefix = $settlement_delta > 0 ? '+' : '';
			$stages[] = array(
				'key'           => 'bank_settled',
				'label'         => 'Bank settled',
				'value'         => self::format_billing_quantity( $bank_settled_icus, 'ICUs' ),
				'secondary'     => 0 !== $settlement_delta ? $delta_prefix . self::format_billing_quantity( abs( $settlement_delta ), 'ICUs vs estimate' ) : '',
				'authority_tag' => 'Bank settled',
				'note'          => sanitize_text_field( (string) ( $settlement['summary'] ?? 'Final billable usage settled by the bank.' ) ),
				'tone'          => 'success',
			);
		}

		$notices = array_values(
			array_filter(
				array(
					! empty( $billing_state['authority_notice'] ) ? array(
						'label' => 'Authority',
						'text'  => sanitize_text_field( (string) $billing_state['authority_notice'] ),
						'tone'  => 'neutral',
					) : array(),
					! empty( $billing_state['service_notice'] ) ? array(
						'label' => 'Service',
						'text'  => sanitize_text_field( (string) $billing_state['service_notice'] ),
						'tone'  => 'warning',
					) : array(),
					! empty( $billing_state['estimate_notice'] ) ? array(
						'label' => 'Estimate',
						'text'  => sanitize_text_field( (string) $billing_state['estimate_notice'] ),
						'tone'  => ! empty( $reserve_envelope ) ? 'warning' : 'neutral',
					) : array(),
				),
				static fn( $row ): bool => ! empty( $row )
			)
		);

		$summary = '';
		if ( $estimated_icus > 0 && $bank_settled_icus > 0 ) {
			$summary = sprintf(
				'Estimated %s, bank settled %s.',
				self::format_billing_quantity( $estimated_icus, 'ICUs' ),
				self::format_billing_quantity( $bank_settled_icus, 'ICUs' )
			);
		} elseif ( $planned_icus > 0 ) {
			$summary = sprintf(
				'Planned %s before provider usage finalized.',
				self::format_billing_quantity( $planned_icus, 'ICUs' )
			);
		}

		$receipt = array(
			'contract'          => 'billing_receipt',
			'version'           => 1,
			'label'             => 'Per-run receipt',
			'summary'           => $summary,
			'authority_label'   => sanitize_text_field( (string) ( $billing_state['authority_label'] ?? '' ) ),
			'service_label'     => sanitize_text_field( (string) ( $billing_state['service_label'] ?? '' ) ),
			'spend_label'       => sanitize_text_field( (string) ( $billing_state['spend_label'] ?? '' ) ),
			'stages'            => $stages,
			'notices'           => $notices,
			'reduced_certainty' => ! empty( $reserve_envelope ) ? array(
				'label'  => sanitize_text_field( (string) ( $reserve_envelope['label'] ?? 'Reduced-certainty reserve' ) ),
				'value'  => self::format_billing_quantity( (int) ( $reserve_envelope['limit_icus'] ?? 0 ), 'ICU cap' ),
				'detail' => sanitize_text_field( (string) ( $reserve_envelope['detail'] ?? '' ) ),
			) : array(),
		);

		return array_filter(
			$receipt,
			static function ( $value, $key ) {
				if ( 'version' === $key ) {
					return true;
				}
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	private static function aggregate_bank_receipt_trace( array $result ): array {
		if ( ! class_exists( 'PressArk_Activity_Trace' ) ) {
			return array();
		}

		$events = PressArk_Activity_Trace::fetch_bank_trace(
			array(
				'correlation_id' => (string) ( $result['correlation_id'] ?? '' ),
				'reservation_id' => (string) ( $result['reservation_id'] ?? '' ),
			),
			120
		);
		if ( empty( $events ) ) {
			return array();
		}

		$seen_reserves = array();
		$seen_settles  = array();
		$rollup        = array(
			'estimated_icus'           => 0,
			'estimated_raw_tokens'     => 0,
			'provider_reported_tokens' => 0,
			'bank_settled_icus'        => 0,
		);

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_type = str_replace( '.', '', sanitize_key( (string) ( $event['event_type'] ?? '' ) ) );
			$status     = sanitize_key( (string) ( $event['status'] ?? '' ) );
			$payload    = is_array( $event['payload'] ?? null ) ? (array) $event['payload'] : array();
			$event_key  = sanitize_text_field( (string) ( $event['reservation_id'] ?? $event['event_id'] ?? '' ) );

			if ( 'bankreserve' === $event_type && 'blocked' !== $status ) {
				if ( isset( $seen_reserves[ $event_key ] ) ) {
					continue;
				}
				$seen_reserves[ $event_key ] = true;
				$rollup['estimated_icus']       += max( 0, (int) ( $payload['requested_icus'] ?? 0 ) );
				$rollup['estimated_raw_tokens'] += max( 0, (int) ( $payload['estimated_raw_tokens'] ?? 0 ) );
			}

			if ( 'banksettle' === $event_type && 'noop' !== $status ) {
				if ( isset( $seen_settles[ $event_key ] ) ) {
					continue;
				}
				$seen_settles[ $event_key ] = true;
				$rollup['provider_reported_tokens'] += max( 0, (int) ( $payload['raw_actual_tokens'] ?? 0 ) );
				$rollup['bank_settled_icus']        += max( 0, (int) ( $payload['actual_icus'] ?? 0 ) );
			}
		}

		return array_filter( $rollup );
	}

	private static function format_billing_quantity( int $amount, string $unit ): string {
		$value = abs( $amount );
		$label = (string) $value;
		if ( $value >= 1000000 ) {
			$label = number_format( $value / 1000000, 1 ) . 'M';
		} elseif ( $value >= 1000 ) {
			$label = number_format( $value / 1000, 1 ) . 'K';
		}

		$label = str_replace( '.0', '', $label );
		return trim( $label . ' ' . $unit );
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
