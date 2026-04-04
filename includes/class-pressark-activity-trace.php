<?php
/**
 * Canonical activity envelopes, correlation IDs, and joined trace helpers.
 *
 * @package PressArk
 * @since   5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Activity_Trace {

	const SCHEMA_VERSION = 1;

	/**
	 * Shared reason vocabulary for plugin and bank traces.
	 *
	 * @return array<string,string>
	 */
	public static function reason_catalog(): array {
		return array(
			'request_started'         => 'Request accepted and trace context created.',
			'user_cancelled'          => 'The user explicitly stopped the flow.',
			'client_disconnected'     => 'The client disconnected before completion.',
			'approval_wait_preview'   => 'The run is waiting for a preview keep/discard decision.',
			'approval_wait_confirm'   => 'The run is waiting for one or more confirmation decisions.',
			'approval_partial_progress' => 'Some confirmations completed while other actions remain pending.',
			'retry_format_leak'       => 'The model leaked a tool call and the loop retried with corrective guidance.',
			'retry_async_failure'     => 'The async worker scheduled a retry after a retryable failure.',
			'fallback_model_policy'   => 'The provider call fell back to another model candidate.',
			'degraded_request_headroom' => 'The loop paused or compacted because request headroom was low.',
			'degraded_token_budget'   => 'The loop hit the configured token budget.',
			'resume_after_checkpoint' => 'Execution resumed from checkpoint replay state.',
			'stop_tool_use'           => 'The provider stopped because tool use was requested.',
			'stop_end_turn'           => 'The provider stopped after finishing its turn.',
			'stop_max_tokens'         => 'The provider stopped because max tokens or truncation limits were hit.',
			'reserve_requested'       => 'A reservation request was issued to the bank.',
			'reserve_blocked_budget'  => 'The reservation was blocked by available budget.',
			'reserve_idempotent'      => 'An existing reservation was reused idempotently.',
			'settle_applied'          => 'Settlement completed successfully.',
			'settle_idempotent'       => 'Settlement was ignored because the reservation was already terminal.',
			'release_cleanup'         => 'A held reservation was released during cleanup.',
			'provider_error'          => 'The downstream AI provider returned an error.',
			'usage_missing_stream'    => 'Streaming ended without usage data, so the reservation was left held.',
			'state_change'            => 'A durable run state transition occurred.',
			'completed'               => 'The flow completed successfully.',
			'failed'                  => 'The flow failed.',
		);
	}

	/**
	 * Generate a canonical event ID.
	 */
	public static function new_event_id(): string {
		return 'evt_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Generate a canonical correlation ID.
	 */
	public static function new_correlation_id(): string {
		return 'corr_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * Normalize a caller-provided correlation ID.
	 */
	public static function normalize_correlation_id( string $candidate = '' ): string {
		$candidate = strtolower( trim( $candidate ) );
		$candidate = preg_replace( '/[^a-z0-9_\-:\.]/', '', $candidate );

		return '' !== (string) $candidate
			? (string) mb_substr( $candidate, 0, 64 )
			: self::new_correlation_id();
	}

	/**
	 * Merge a partial context into the current request context.
	 *
	 * @param array<string,mixed> $context Partial context.
	 */
	public static function set_current_context( array $context ): void {
		$current = self::current_context();
		$context = array_merge( $current, self::sanitize_context( $context ) );
		$GLOBALS['pressark_activity_trace_context'] = array_filter(
			$context,
			static function ( $value ) {
				return ! ( is_string( $value ) && '' === $value );
			}
		);
	}

	/**
	 * Clear the current request context.
	 */
	public static function clear_current_context(): void {
		unset( $GLOBALS['pressark_activity_trace_context'] );
	}

	/**
	 * Current trace context for this request/process.
	 *
	 * @return array<string,mixed>
	 */
	public static function current_context(): array {
		$current = $GLOBALS['pressark_activity_trace_context'] ?? array();
		return is_array( $current ) ? $current : array();
	}

	/**
	 * Build a canonical envelope without persisting it.
	 *
	 * @param array<string,mixed> $event   Partial event.
	 * @param array<string,mixed> $context Additional context.
	 * @return array<string,mixed>
	 */
	public static function build_event( array $event, array $context = array() ): array {
		$context = array_merge(
			self::current_context(),
			array_filter(
				self::sanitize_context( $context ),
				static function ( $value ) {
					if ( is_string( $value ) ) {
						return '' !== $value;
					}

					if ( is_int( $value ) ) {
						return $value > 0;
					}

					return null !== $value;
				}
			)
		);
		$correlation_id = self::normalize_correlation_id( (string) ( $event['correlation_id'] ?? $context['correlation_id'] ?? '' ) );
		$event_type     = self::normalize_event_name( (string) ( $event['event_type'] ?? 'trace.event' ) );
		$reason         = self::normalize_reason( (string) ( $event['reason'] ?? '' ) );
		$status         = sanitize_key( (string) ( $event['status'] ?? 'info' ) );
		$payload        = self::sanitize_payload( is_array( $event['payload'] ?? null ) ? $event['payload'] : array() );

		return array(
			'event_id'       => sanitize_text_field( (string) ( $event['event_id'] ?? self::new_event_id() ) ),
			'schema_version' => self::SCHEMA_VERSION,
			'correlation_id' => $correlation_id,
			'run_id'         => sanitize_text_field( (string) ( $event['run_id'] ?? $context['run_id'] ?? '' ) ),
			'task_id'        => sanitize_text_field( (string) ( $event['task_id'] ?? $context['task_id'] ?? '' ) ),
			'reservation_id' => sanitize_text_field( (string) ( $event['reservation_id'] ?? $context['reservation_id'] ?? '' ) ),
			'chat_id'        => max( 0, (int) ( $event['chat_id'] ?? $context['chat_id'] ?? 0 ) ),
			'user_id'        => max( 0, (int) ( $event['user_id'] ?? $context['user_id'] ?? 0 ) ),
			'route'          => sanitize_key( (string) ( $event['route'] ?? $context['route'] ?? '' ) ),
			'source'         => sanitize_key( (string) ( $event['source'] ?? 'plugin' ) ),
			'event_type'     => $event_type,
			'phase'          => sanitize_key( (string) ( $event['phase'] ?? '' ) ),
			'status'         => '' !== $status ? $status : 'info',
			'reason'         => $reason,
			'summary'        => mb_substr( sanitize_text_field( (string) ( $event['summary'] ?? self::default_summary( $event_type, $reason, $status ) ) ), 0, 255 ),
			'payload'        => $payload,
			'occurred_at'    => sanitize_text_field( (string) ( $event['occurred_at'] ?? current_time( 'mysql', true ) ) ),
		);
	}

	/**
	 * Persist one canonical event.
	 *
	 * @param array<string,mixed> $event   Partial event.
	 * @param array<string,mixed> $context Additional context.
	 * @return array<string,mixed>|null
	 */
	public static function publish( array $event, array $context = array() ): ?array {
		$envelope = self::build_event( $event, $context );
		$store    = new PressArk_Activity_Event_Store();

		return $store->record( $envelope ) ? $envelope : null;
	}

	/**
	 * Persist multiple canonical events against the same context.
	 *
	 * @param array<int,array<string,mixed>> $events   Partial events.
	 * @param array<string,mixed>            $context  Shared context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function publish_many( array $events, array $context = array() ): array {
		$written = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$envelope = self::publish( $event, $context );
			if ( is_array( $envelope ) ) {
				$written[] = $envelope;
			}
		}

		return $written;
	}

	/**
	 * Publish buffered result-level events through one phase-end path.
	 *
	 * @param array<string,mixed> $result       Execution result.
	 * @param string              $route        Execution route.
	 * @param array<string,mixed> $token_status Settlement result.
	 */
	public static function publish_result_events( array $result, string $route, array $token_status = array() ): void {
		$context = array(
			'run_id'         => (string) ( $result['run_id'] ?? '' ),
			'correlation_id' => (string) ( $result['correlation_id'] ?? '' ),
			'reservation_id' => (string) ( $result['reservation_id'] ?? '' ),
			'route'          => $route,
		);

		self::publish_many( (array) ( $result['activity_events'] ?? array() ), $context );

		$status = ! empty( $result['is_error'] ) ? 'failed' : 'succeeded';
		if ( ! empty( $result['cancelled'] ) ) {
			$status = 'cancelled';
		}
		if ( in_array( (string) ( $result['type'] ?? '' ), array( 'preview', 'confirm_card' ), true ) ) {
			$status = 'waiting';
		}

		self::publish(
			array(
				'event_type' => 'run.phase_completed',
				'phase'      => 'phase_end',
				'status'     => $status,
				'reason'     => self::infer_terminal_reason( $result ),
				'summary'    => 'Phase-end canonical event published.',
				'payload'    => array(
					'route'              => $route,
					'result_type'        => (string) ( $result['type'] ?? 'final_response' ),
					'tokens_used'        => (int) ( $result['tokens_used'] ?? 0 ),
					'input_tokens'       => (int) ( $result['input_tokens'] ?? 0 ),
					'output_tokens'      => (int) ( $result['output_tokens'] ?? 0 ),
					'agent_rounds'       => (int) ( $result['agent_rounds'] ?? 0 ),
					'pending_actions'    => is_array( $result['pending_actions'] ?? null ) ? count( $result['pending_actions'] ) : 0,
					'actual_icus'        => (int) ( $token_status['actual_icus'] ?? 0 ),
					'raw_actual_tokens'  => (int) ( $token_status['raw_actual_tokens'] ?? 0 ),
				),
			),
			$context
		);
	}

	/**
	 * Fetch and merge all locally persisted trace rows for a run.
	 *
	 * @param array<string,mixed> $run Run row.
	 * @param int                 $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_local_trace( array $run, int $limit = 120 ): array {
		$store          = new PressArk_Activity_Event_Store();
		$correlation_id = (string) ( $run['correlation_id'] ?? '' );
		$run_id         = (string) ( $run['run_id'] ?? '' );

		if ( '' !== $correlation_id ) {
			return $store->get_by_correlation( $correlation_id, $limit );
		}

		return $store->get_by_run( $run_id, $limit );
	}

	/**
	 * Merge local and remote trace events into one ordered timeline.
	 *
	 * @param array<int,array<string,mixed>> $local Local/plugin events.
	 * @param array<int,array<string,mixed>> $remote Remote/bank events.
	 * @return array<int,array<string,mixed>>
	 */
	public static function merge_traces( array $local, array $remote ): array {
		$merged = array();
		$seen   = array();

		foreach ( array_merge( $local, $remote ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_id = (string) ( $event['event_id'] ?? '' );
			if ( '' !== $event_id && isset( $seen[ $event_id ] ) ) {
				continue;
			}

			if ( '' !== $event_id ) {
				$seen[ $event_id ] = true;
			}

			$merged[] = $event;
		}

		usort(
			$merged,
			static function ( array $left, array $right ): int {
				$left_at  = (string) ( $left['occurred_at'] ?? $left['created_at'] ?? '' );
				$right_at = (string) ( $right['occurred_at'] ?? $right['created_at'] ?? '' );

				if ( $left_at === $right_at ) {
					return strcmp( (string) ( $left['event_id'] ?? '' ), (string) ( $right['event_id'] ?? '' ) );
				}

				return strcmp( $left_at, $right_at );
			}
		);

		return $merged;
	}

	/**
	 * Fetch a sanitized bank trace for the given run.
	 *
	 * @param array<string,mixed> $run Run row.
	 * @param int                 $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fetch_bank_trace( array $run, int $limit = 80 ): array {
		$correlation_id = (string) ( $run['correlation_id'] ?? '' );
		$reservation_id = (string) ( $run['reservation_id'] ?? '' );

		if ( '' === $correlation_id && '' === $reservation_id ) {
			return array();
		}

		if ( ! class_exists( 'PressArk_Token_Bank' ) ) {
			return array();
		}

		$bank = new PressArk_Token_Bank();
		$rows = $bank->get_trace( $correlation_id, $reservation_id, $limit );

		return array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return is_array( $row ) ? $row : null;
					},
					(array) $rows
				)
			)
		);
	}

	/**
	 * Infer a canonical reason for terminal and phase-end events.
	 *
	 * @param array<string,mixed> $result Execution result.
	 */
	public static function infer_terminal_reason( array $result ): string {
		if ( ! empty( $result['cancelled'] ) ) {
			return 'user_cancelled';
		}

		$exit_reason = sanitize_key( (string) ( $result['exit_reason'] ?? '' ) );
		if ( 'token_budget' === $exit_reason || 'max_request_icus_compacted' === $exit_reason ) {
			return 'degraded_token_budget';
		}

		$type = (string) ( $result['type'] ?? '' );
		if ( 'preview' === $type ) {
			return 'approval_wait_preview';
		}
		if ( 'confirm_card' === $type ) {
			return 'approval_wait_confirm';
		}
		if ( ! empty( $result['is_error'] ) ) {
			return 'failed';
		}

		return 'completed';
	}

	/**
	 * Infer a failure reason from a free-form message.
	 */
	public static function infer_failure_reason( string $message ): string {
		$message = strtolower( $message );

		if ( str_contains( $message, 'cancel' ) ) {
			return 'user_cancelled';
		}
		if ( str_contains( $message, 'disconnect' ) ) {
			return 'client_disconnected';
		}

		return 'failed';
	}

	/**
	 * Normalize and whitelist trace context keys.
	 *
	 * @param array<string,mixed> $context Partial context.
	 * @return array<string,mixed>
	 */
	private static function sanitize_context( array $context ): array {
		return array(
			'correlation_id' => ! empty( $context['correlation_id'] ) ? self::normalize_correlation_id( (string) $context['correlation_id'] ) : '',
			'run_id'         => sanitize_text_field( (string) ( $context['run_id'] ?? '' ) ),
			'task_id'        => sanitize_text_field( (string) ( $context['task_id'] ?? '' ) ),
			'reservation_id' => sanitize_text_field( (string) ( $context['reservation_id'] ?? '' ) ),
			'chat_id'        => max( 0, (int) ( $context['chat_id'] ?? 0 ) ),
			'user_id'        => max( 0, (int) ( $context['user_id'] ?? 0 ) ),
			'route'          => sanitize_key( (string) ( $context['route'] ?? '' ) ),
		);
	}

	/**
	 * Normalize a canonical event name.
	 */
	private static function normalize_event_name( string $event_type ): string {
		$event_type = strtolower( trim( $event_type ) );
		$event_type = preg_replace( '/[^a-z0-9_\-\.]/', '', $event_type );

		return '' !== (string) $event_type ? (string) $event_type : 'trace.event';
	}

	/**
	 * Normalize a shared reason key.
	 */
	private static function normalize_reason( string $reason ): string {
		$reason = sanitize_key( $reason );
		$known  = self::reason_catalog();

		return isset( $known[ $reason ] ) ? $reason : 'state_change';
	}

	/**
	 * Redact sensitive or oversized payload fields before persistence.
	 *
	 * @param mixed $payload Payload node.
	 * @return mixed
	 */
	private static function sanitize_payload( $payload ) {
		if ( is_array( $payload ) ) {
			$clean = array();
			foreach ( $payload as $key => $value ) {
				$key_string = is_string( $key ) ? strtolower( $key ) : (string) $key;
				if ( preg_match( '/(?:secret|authorization|api[_-]?key|site[_-]?token|request[_-]?body|provider[_-]?body|raw[_-]?body|headers|content)$/', $key_string ) ) {
					continue;
				}
				$clean[ $key ] = self::sanitize_payload( $value );
			}
			return $clean;
		}

		if ( is_string( $payload ) ) {
			return mb_substr( sanitize_textarea_field( $payload ), 0, 500 );
		}

		if ( is_bool( $payload ) || is_int( $payload ) || is_float( $payload ) || null === $payload ) {
			return $payload;
		}

		return sanitize_text_field( (string) $payload );
	}

	/**
	 * Build a fallback summary when a caller does not provide one.
	 */
	private static function default_summary( string $event_type, string $reason, string $status ): string {
		$summary = trim( sprintf( '%s %s %s', $event_type, $status, $reason ) );
		return preg_replace( '/\s+/', ' ', $summary ) ?: 'Activity event recorded.';
	}
}
