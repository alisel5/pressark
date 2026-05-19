<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-pressark-request-compiler.php';

/**
 * Executes chat orchestration flows behind the REST controller facade.
 */
class PressArk_Orchestration_Service {

	private const MAX_STREAM_PROGRESS_TOKENS = 10000;

	private PressArk_Request_Compiler $request_compiler;

	public function __construct( ?PressArk_Request_Compiler $request_compiler = null ) {
		$this->request_compiler = $request_compiler ?: new PressArk_Request_Compiler();
	}

	/**
	 * Start a canonical trace context for this request before reservation/run creation.
	 */
	private function begin_trace_context( int $user_id, int $chat_id, string $route = '' ): string {
		$correlation_id = PressArk_Activity_Trace::new_correlation_id();
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => $correlation_id,
				'user_id'        => $user_id,
				'chat_id'        => $chat_id,
				'route'          => $route,
			)
		);

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'request.started',
				'phase'      => 'request',
				'status'     => 'started',
				'reason'     => 'request_started',
				'summary'    => 'Request accepted and awaiting reservation/run creation.',
				'payload'    => array(
					'chat_id' => $chat_id,
				),
			)
		);

		return $correlation_id;
	}

	/**
	 * Hydrate the current trace context from a stored run row.
	 *
	 * @param array<string,mixed> $run Stored run row.
	 */
	private function hydrate_trace_context_from_run( array $run ): void {
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

	/**
	 * Build a normalized approval outcome payload for settlement and checkpoint flows.
	 *
	 * @param string $status    Outcome status.
	 * @param array  $overrides Extra outcome fields.
	 * @return array<string,mixed>
	 */
	private function build_approval_outcome( string $status, array $overrides = array() ): array {
		if ( class_exists( 'PressArk_Permission_Decision' ) ) {
			return PressArk_Permission_Decision::approval_outcome( $status, $overrides );
		}

		$outcome = array(
			'status'      => sanitize_key( $status ),
			'recorded_at' => sanitize_text_field( (string) ( $overrides['recorded_at'] ?? gmdate( 'c' ) ) ),
		);

		foreach ( array( 'action', 'scope', 'source', 'actor', 'reason_code' ) as $field ) {
			$value = sanitize_key( (string) ( $overrides[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$outcome[ $field ] = $value;
			}
		}

		$message = sanitize_text_field( (string) ( $overrides['message'] ?? '' ) );
		if ( '' !== $message ) {
			$outcome['message'] = $message;
		}
		if ( is_array( $overrides['meta'] ?? null ) && ! empty( $overrides['meta'] ) ) {
			$outcome['meta'] = $overrides['meta'];
		}

		return $outcome;
	}

	/**
	 * Attach a typed approval outcome to a result while preserving legacy flags.
	 *
	 * @param array  $result    Result payload.
	 * @param string $status    Outcome status.
	 * @param array  $overrides Extra outcome fields.
	 * @return array
	 */
	private function attach_approval_outcome( array $result, string $status, array $overrides = array() ): array {
		$result['approval_outcome'] = $this->build_approval_outcome( $status, $overrides );
		$normalized_status          = sanitize_key( (string) ( $result['approval_outcome']['status'] ?? $status ) );

		if ( in_array( $normalized_status, array( 'cancelled', 'aborted' ), true ) ) {
			$result['cancelled'] = true;
		} else {
			unset( $result['cancelled'] );
		}

		if ( 'discarded' === $normalized_status ) {
			$result['discarded'] = true;
		} else {
			unset( $result['discarded'] );
		}

		return $this->hydrate_approval_receipt( $result );
	}

	/**
	 * Attach the compact server acknowledgement receipt for the current approval outcome.
	 *
	 * @param array $result  Result payload.
	 * @param array $context Extra settlement context.
	 * @return array
	 */
	private function hydrate_approval_receipt( array $result, array $context = array() ): array {
		if ( ! class_exists( 'PressArk_Permission_Decision' ) ) {
			return $result;
		}

		$outcome = is_array( $result['approval_outcome'] ?? null ) ? $result['approval_outcome'] : array();
		$receipt = PressArk_Permission_Decision::approval_receipt(
			$outcome,
			array_merge(
				array(
					'run_id'         => sanitize_text_field( (string) ( $result['run_id'] ?? '' ) ),
					'correlation_id' => sanitize_text_field( (string) ( $result['correlation_id'] ?? '' ) ),
					'message'        => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				),
				array_key_exists( 'success', $result )
					? array( 'execution_ok' => ! empty( $result['success'] ) )
					: array(),
				$context
			)
		);

		if ( ! empty( $receipt ) ) {
			$result['approval_receipt'] = $receipt;
		} else {
			unset( $result['approval_receipt'] );
		}

		return $result;
	}

	/**
	 * Attach compact chat-facing surfaces derived from canonical backend state.
	 *
	 * @param array  $result Result payload.
	 * @param string $route  Execution route.
	 * @return array
	 */
	private function hydrate_chat_surfaces( array $result, string $route = '' ): array {
		if ( ! class_exists( 'PressArk_Pipeline' ) ) {
			return $result;
		}

		$route_key = sanitize_key( $route );
		if ( '' === $route_key ) {
			$route_key = sanitize_key( (string) ( $result['routing_decision']['route'] ?? '' ) );
		}
		if ( '' === $route_key ) {
			$route_key = 'agent';
		}

		$activity_strip = PressArk_Pipeline::build_activity_strip( $result, $route_key );
		if ( ! empty( $activity_strip ) ) {
			$result['activity_strip'] = $activity_strip;
		} else {
			unset( $result['activity_strip'] );
		}

		$run_surface = PressArk_Pipeline::build_run_surface(
			$result,
			$route_key,
			$activity_strip,
			is_array( $result['budget'] ?? null ) ? (array) $result['budget'] : array()
		);
		if ( ! empty( $run_surface ) ) {
			$result['run_surface'] = $run_surface;
		} else {
			unset( $result['run_surface'] );
		}

		return $result;
	}

	/**
	 * Build a terminal result around a typed approval outcome.
	 *
	 * @param string $status            Outcome status.
	 * @param string $message           User-facing message.
	 * @param array  $result_overrides  Top-level result overrides.
	 * @param array  $outcome_overrides Approval outcome overrides.
	 * @return array
	 */
	private function build_terminal_outcome_result(
		string $status,
		string $message,
		array $result_overrides = array(),
		array $outcome_overrides = array()
	): array {
		$result = array_merge(
			array(
				'success' => true,
				'type'    => 'final_response',
				'message' => $message,
			),
			$result_overrides
		);

		return $this->attach_approval_outcome(
			$result,
			$status,
			array_merge(
				array(
					'message' => $message,
					'source'  => 'chat',
				),
				$outcome_overrides
			)
		);
	}

	/**
	 * Persist an approval outcome into checkpoint memory when supported.
	 */
	private function remember_approval_outcome( PressArk_Checkpoint $checkpoint, string $action, string $status, array $meta = array() ): void {
		if ( method_exists( $checkpoint, 'record_approval_outcome' ) ) {
			$checkpoint->record_approval_outcome( $action, $status, $meta );
		}
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route( 'pressark/v1', '/chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_chat' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'message'      => array(
					'required'          => true,
					'type'              => 'string',
					'maxLength'         => 10000,
					'sanitize_callback' => function( $value ) {
						// Preserve HTML/code in chat messages (they're sent to the AI, not rendered as HTML).
						// Strip null bytes and invalid UTF-8 but keep angle brackets, line breaks, etc.
						$value = wp_check_invalid_utf8( $value );
						$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
						return wp_unslash( $value );
					},
				),
				'conversation' => array(
					'required'          => false,
					'type'              => 'array',
					'default'           => array(),
					'validate_callback' => array( $this, 'validate_conversation' ),
				),
				'screen'       => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id'      => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'deep_mode'    => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
				'loaded_groups' => array(
					'required' => false,
					'type'     => 'array',
					'default'  => array(),
					'maxItems' => 20,
				),
				'checkpoint' => array(
					'required'          => false,
					'type'              => array( 'object', 'null' ),
					'default'           => null,
					'validate_callback' => array( $this, 'validate_checkpoint' ),
				),
				'chat_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// v4.4.0: SSE streaming endpoint ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â real-time token delivery.
		register_rest_route( 'pressark/v1', '/chat-stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_chat_stream' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'message'      => array(
					'required'          => true,
					'type'              => 'string',
					'maxLength'         => 10000,
					'sanitize_callback' => function( $value ) {
						// Preserve HTML/code in chat messages (they're sent to the AI, not rendered as HTML).
						// Strip null bytes and invalid UTF-8 but keep angle brackets, line breaks, etc.
						$value = wp_check_invalid_utf8( $value );
						$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
						return wp_unslash( $value );
					},
				),
				'conversation' => array(
					'required'          => false,
					'type'              => 'array',
					'default'           => array(),
					'validate_callback' => array( $this, 'validate_conversation' ),
				),
				'screen'       => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'post_id'      => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'deep_mode'    => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
				'loaded_groups' => array(
					'required' => false,
					'type'     => 'array',
					'default'  => array(),
					'maxItems' => 20,
				),
				'checkpoint' => array(
					'required'          => false,
					'type'              => array( 'object', 'null' ),
					'default'           => null,
					'validate_callback' => array( $this, 'validate_checkpoint' ),
				),
				'chat_id' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/confirm', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_confirm' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				// v3.7.2: Server-issued index into the run's persisted pending_actions.
				'action_index' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'confirmed' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/confirm-stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_confirm_stream' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'action_index' => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'confirmed' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/execute', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_execute' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/execute-stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_execute_stream' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/approve', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_approve' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/approve-stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_approve_stream' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/revise', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_revise' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'revision_note' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/plan/reject', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_plan_reject' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'reason' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/onboarded', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_onboarded' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		register_rest_route( 'pressark/v1', '/undo', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_undo' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'log_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// Preview keep/discard endpoints.
		register_rest_route( 'pressark/v1', '/preview/keep', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_preview_keep' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/preview/discard', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_preview_discard' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_cancel' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		register_rest_route( 'pressark/v1', '/poll', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_poll' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// v4.2.0: Activity feed ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â fetch task result without requiring heartbeat.
		register_rest_route( 'pressark/v1', '/activity/task/(?P<task_id>[a-f0-9-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_task_result' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Chat history endpoints.
		register_rest_route( 'pressark/v1', '/chats', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_chats' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_chat' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		register_rest_route( 'pressark/v1', '/chats/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_chat' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_delete_chat' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			),
		) );

		// Developer-only: probe the simulator proxy to show a reachability
		// badge in the Settings UI. Gated in the handler so the endpoint is
		// a no-op on production installs even if somehow reached.
		register_rest_route( 'pressark/v1', '/dev/simulator-probe', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_simulator_probe' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	/**
	 * Probe the simulator URL and return a reachability snapshot. Refuses to
	 * run outside a dev environment and refuses non-local hosts so the
	 * endpoint can't be used as an SSRF surface.
	 */
	public function handle_simulator_probe( WP_REST_Request $request ): WP_REST_Response {
		if ( ! PressArk_AI_Connector::simulator_is_dev_environment() ) {
			return new WP_REST_Response( array( 'reachable' => false, 'error' => 'disabled' ), 403 );
		}

		$url = trim( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_REST_Response( array( 'reachable' => false, 'error' => 'missing url' ), 400 );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_REST_Response( array( 'reachable' => false, 'error' => 'bad url' ), 400 );
		}

		$host = strtolower( (string) $parts['host'] );
		$is_local = in_array( $host, array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' ), true )
			|| str_ends_with( $host, '.local' )
			|| str_ends_with( $host, '.test' )
			|| str_ends_with( $host, '.localhost' );
		if ( ! $is_local && filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$is_local = 0 === strpos( $host, '10.' )
				|| 0 === strpos( $host, '192.168.' )
				|| 0 === strpos( $host, '127.' )
				|| 1 === preg_match( '/^172\.(1[6-9]|2\d|3[01])\./', $host );
		}
		if ( ! $is_local ) {
			return new WP_REST_Response( array( 'reachable' => false, 'error' => 'non-local host' ), 400 );
		}

		// Derive /health URL from whatever they entered (might be …/v1/chat/completions).
		$probe_url = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/health';

		$response = wp_safe_remote_get( $probe_url, array(
			'timeout'   => 3,
			'sslverify' => false,
		) );

		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array(
				'reachable' => false,
				'error'     => $response->get_error_message(),
				'probe_url' => $probe_url,
			) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return rest_ensure_response( array(
			'reachable'   => $code >= 200 && $code < 500,
			'status_code' => $code,
			'probe_url'   => $probe_url,
		) );
	}

	/**
	 * Check that the user has admin capabilities.
	 */
	public function check_permissions(): bool {
		return self::user_can_access();
	}

	/**
	 * Validate the conversation array parameter.
	 *
	 * Limits to 100 items, each with only 'role' and 'content' keys,
	 * and each content string capped at 50 000 characters.
	 *
	 * @param mixed           $value   Parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_conversation( $value, WP_REST_Request $request, string $param ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'rest_invalid_param', sprintf( '%s must be an array.', $param ) );
		}

		if ( count( $value ) > 100 ) {
			return new WP_Error( 'rest_invalid_param', sprintf( '%s exceeds 100 items.', $param ) );
		}

		$allowed_keys = array( 'role', 'content' );

		foreach ( $value as $i => $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'rest_invalid_param', sprintf( '%s[%d] must be an object.', $param, $i ) );
			}

			$extra = array_diff( array_keys( $item ), $allowed_keys );
			if ( ! empty( $extra ) ) {
				return new WP_Error( 'rest_invalid_param', sprintf( '%s[%d] contains invalid keys: %s', $param, $i, implode( ', ', $extra ) ) );
			}

			if ( isset( $item['content'] ) && is_string( $item['content'] ) && mb_strlen( $item['content'] ) > 50000 ) {
				return new WP_Error( 'rest_invalid_param', sprintf( '%s[%d].content exceeds 50 000 characters.', $param, $i ) );
			}
		}

		return true;
	}

	/**
	 * Validate the checkpoint parameter.
	 *
	 * Rejects payloads whose serialized size exceeds 100 KB.
	 *
	 * @param mixed           $value   Parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_checkpoint( $value, WP_REST_Request $request, string $param ) {
		if ( null === $value ) {
			return true;
		}

		$size = strlen( wp_json_encode( $value ) );
		if ( $size > 102400 ) { // 100 KB
			return new WP_Error( 'rest_invalid_param', sprintf( '%s exceeds 100 KB.', $param ) );
		}

		return true;
	}

	private function ensure_plan_mode_loaded(): void {
		if ( ! class_exists( 'PressArk_Plan_Mode' ) ) {
			require_once __DIR__ . '/../class-pressark-plan-mode.php';
		}
	}

	private function is_plan_route( array $routing ): bool {
		return PressArk_Router::ROUTE_PLAN === (string) ( $routing['route'] ?? '' );
	}

	private function is_legacy_route( array $routing ): bool {
		return PressArk_Router::ROUTE_LEGACY === (string) ( $routing['route'] ?? '' );
	}

	private function merge_routing_preload_groups( array $loaded_groups, array $routing ): array {
		$preload_plan = is_array( $routing['meta']['preload_plan'] ?? null ) ? (array) $routing['meta']['preload_plan'] : array();
		$planned_groups = is_array( $preload_plan['groups'] ?? null )
			? (array) $preload_plan['groups']
			: ( is_array( $routing['meta']['preloaded_groups'] ?? null ) ? (array) $routing['meta']['preloaded_groups'] : array() );

		if ( empty( $planned_groups ) ) {
			return $loaded_groups;
		}

		return array_values(
			array_unique(
				array_merge(
					$loaded_groups,
					array_map( 'sanitize_text_field', $planned_groups )
				)
			)
		);
	}

	private function decorate_plan_ready_result( array $result, array $ctx ): array {
		$this->ensure_plan_mode_loaded();

		$checkpoint = ! empty( $result['checkpoint'] ) && is_array( $result['checkpoint'] )
			? PressArk_Checkpoint::from_array( $result['checkpoint'] )
			: ( ! empty( $ctx['checkpoint_data'] ) && is_array( $ctx['checkpoint_data'] )
				? PressArk_Checkpoint::from_array( $ctx['checkpoint_data'] )
				: PressArk_Checkpoint::from_array( array() ) );

		$artifact = class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::ensure(
				is_array( $result['plan_artifact'] ?? null ) ? (array) $result['plan_artifact'] : array(),
				is_array( $result['plan_steps'] ?? null ) ? (array) $result['plan_steps'] : array(),
				array(
					'plan_markdown'   => (string) ( $result['plan_markdown'] ?? $result['message'] ?? '' ),
					'approval_level'  => (string) ( $result['approval_level'] ?? 'hard' ),
					'request_message' => (string) ( $ctx['message'] ?? $ctx['original_message'] ?? '' ),
					'execute_message' => (string) ( $ctx['execution_message'] ?? $ctx['message'] ?? '' ),
					'run_id'          => (string) ( $ctx['run_id'] ?? '' ),
				)
			)
			: array();

		if ( empty( $artifact ) && method_exists( $checkpoint, 'get_plan_artifact' ) ) {
			$artifact = $checkpoint->get_plan_artifact();
		}

		$plan_markdown = ! empty( $artifact ) && class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::to_markdown( $artifact )
			: sanitize_textarea_field( (string) ( $result['plan_markdown'] ?? $result['message'] ?? '' ) );
		$step_source   = ! empty( $artifact ) && class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::to_plan_steps( $artifact )
			: ( $result['plan_steps'] ?? $result['steps'] ?? array() );
		$steps         = PressArk_Plan_Mode::extract_steps( $plan_markdown, is_array( $step_source ) ? $step_source : array() );
		$approval_level = sanitize_key( (string) ( $artifact['approval_level'] ?? $result['approval_level'] ?? 'hard' ) );
		if ( ! in_array( $approval_level, array( 'soft', 'hard' ), true ) ) {
			$approval_level = 'hard';
		}

		if ( empty( $steps ) ) {
			$request_message = (string) ( $ctx['message'] ?? $ctx['original_message'] ?? '' );
			$conversation    = is_array( $ctx['conversation'] ?? null ) ? (array) $ctx['conversation'] : array();
			$task_type       = class_exists( 'PressArk_Agent' )
				? PressArk_Agent::classify_task( $request_message, $conversation )
				: 'chat';
			$reply           = class_exists( 'PressArk_Agent' )
				? PressArk_Agent::build_plan_clarification_reply(
					$request_message,
					$task_type,
					is_array( $ctx['loaded_groups'] ?? null ) ? (array) $ctx['loaded_groups'] : array()
				)
				: __( 'What should I work on first: a page, product, setting, or code change?', 'pressark' );

			if ( method_exists( $checkpoint, 'set_plan_phase' ) ) {
				$checkpoint->set_plan_phase( 'exploring' );
			}
			if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
				$checkpoint->set_plan_status( 'needs_input' );
			}

			return array_merge(
				$result,
				array(
					'type'            => 'final_response',
					'status'          => 'needs_input',
					'exit_reason'     => 'needs_input',
					'reply'           => sanitize_text_field( $reply ),
					'message'         => sanitize_text_field( $reply ),
					'plan_markdown'   => '',
					'plan_steps'      => array(),
					'steps'           => array(),
					'can_execute'     => false,
					'approve_endpoint'=> '',
					'execute_endpoint'=> '',
					'revise_endpoint' => '',
					'reject_endpoint' => '',
				)
			);
		}

		if ( '' === trim( $plan_markdown ) && ! empty( $steps ) ) {
			$lines = array();
			foreach ( $steps as $index => $step ) {
				$text = sanitize_text_field( (string) ( is_array( $step ) ? ( $step['text'] ?? '' ) : $step ) );
				if ( '' !== $text ) {
					$lines[] = sprintf( '%d. %s', $index + 1, $text );
				}
			}
			$plan_markdown = implode( "\n", $lines );
		}

		if ( ! empty( $artifact ) && method_exists( $checkpoint, 'set_plan_artifact' ) ) {
			$checkpoint->set_plan_artifact( $artifact );
			if ( ! empty( $ctx['chat_id'] ) ) {
				$checkpoint->touch();
				$checkpoint->save( (int) $ctx['chat_id'], (int) ( $ctx['user_id'] ?? get_current_user_id() ) );
			}
		}

		if ( 'hard' === $approval_level && ! empty( $ctx['run_id'] ) ) {
			PressArk_Plan_Mode::enter(
				(string) $ctx['run_id'],
				(string) ( $ctx['original_message'] ?? $ctx['message'] ?? '' ),
				array(
					'conversation'   => is_array( $ctx['conversation'] ?? null ) ? $ctx['conversation'] : array(),
					'screen'         => sanitize_text_field( (string) ( $ctx['screen'] ?? '' ) ),
					'post_id'        => (int) ( $ctx['post_id'] ?? 0 ),
					'deep_mode'      => ! empty( $ctx['deep_mode'] ),
					'loaded_groups'  => is_array( $ctx['loaded_groups'] ?? null ) ? $ctx['loaded_groups'] : array(),
					'checkpoint'     => $checkpoint->to_array(),
					'chat_id'        => (int) ( $ctx['chat_id'] ?? 0 ),
					'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
					'correlation_id' => (string) ( $ctx['correlation_id'] ?? '' ),
					'permission'     => is_array( $ctx['routing']['meta']['permission'] ?? null ) ? $ctx['routing']['meta']['permission'] : array(),
					'approval_level' => $approval_level,
					'planning_mode'  => (string) ( $result['planning_mode'] ?? $ctx['routing']['meta']['planning_mode'] ?? 'hard_plan' ),
					'policy'         => is_array( $result['planning_decision'] ?? null )
						? (array) $result['planning_decision']
						: ( is_array( $ctx['routing']['meta']['planning_decision'] ?? null ) ? (array) $ctx['routing']['meta']['planning_decision'] : array() ),
					'phase'          => (string) ( $result['plan_phase'] ?? 'planning' ),
				)
			);
		}

		$reply = sanitize_text_field(
			(string) (
				$result['reply']
				?? (
					! empty( $steps )
						? sprintf(
							/* translators: %d: number of checklist steps. */
							__( 'Plan ready. Review the %d-step checklist below, then approve, revise, or cancel before execution continues.', 'pressark' ),
							count( $steps )
						)
						: __( 'I need a narrower request before I can build a safe execution plan.', 'pressark' )
				)
			)
		);
		$plan_phase = sanitize_key( (string) ( $result['plan_phase'] ?? ( method_exists( $checkpoint, 'get_plan_phase' ) ? $checkpoint->get_plan_phase() : 'planning' ) ) );

		$result['type']             = 'plan_ready';
		$result['status']           = 'ready';
		$result['permission_mode']  = 'hard' === $approval_level ? 'plan' : 'mixed';
		$result['mode']             = 'hard' === $approval_level ? 'plan' : 'execute';
		$result['reply']            = $reply;
		$result['plan_markdown']    = $plan_markdown;
		$result['plan_steps']       = $steps;
		$result['steps']            = $steps;
		$result['plan_artifact']    = $artifact;
		$result['plan_phase']       = $plan_phase ?: 'planning';
		$result['approval_level']   = $approval_level;
		$result['can_execute']      = ! empty( $steps );
		$result['approve_endpoint'] = ! empty( $steps ) ? esc_url_raw( rest_url( 'pressark/v1/plan/approve' ) ) : '';
		$result['execute_endpoint'] = ! empty( $steps ) ? esc_url_raw( rest_url( 'pressark/v1/plan/execute' ) ) : '';
		$result['revise_endpoint']  = ! empty( $steps ) ? esc_url_raw( rest_url( 'pressark/v1/plan/revise' ) ) : '';
		$result['reject_endpoint']  = ! empty( $steps ) ? esc_url_raw( rest_url( 'pressark/v1/plan/reject' ) ) : '';

		$trace_context = array(
			'run_id'         => sanitize_text_field( (string) ( $ctx['run_id'] ?? '' ) ),
			'correlation_id' => sanitize_text_field( (string) ( $ctx['correlation_id'] ?? '' ) ),
			'reservation_id' => sanitize_text_field( (string) ( $ctx['reservation_id'] ?? '' ) ),
			'chat_id'        => (int) ( $ctx['chat_id'] ?? 0 ),
			'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
			'route'          => sanitize_text_field( (string) ( $ctx['routing']['route'] ?? PressArk_Router::ROUTE_PLAN ) ),
		);

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'run.plan_ready',
				'phase'      => 'plan',
				'status'     => 'waiting',
				'reason'     => 'plan_ready',
				'summary'    => 'Plan ready for user review.',
				'payload'    => array(
					'type'             => 'plan_ready',
					'step_count'       => count( $steps ),
					'steps'            => $steps,
					'permission_mode'  => $result['permission_mode'],
					'plan_markdown'    => $plan_markdown,
					'can_execute'      => ! empty( $steps ),
					'execute_endpoint' => $result['execute_endpoint'],
					'approve_endpoint' => $result['approve_endpoint'],
					'revise_endpoint'  => $result['revise_endpoint'],
					'reject_endpoint'  => $result['reject_endpoint'],
				),
			),
			$trace_context
		);

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'hard' === $approval_level ? 'plan.hard_generated' : 'plan.soft_generated',
				'phase'      => 'plan',
				'status'     => 'ready',
				'reason'     => 'hard' === $approval_level ? 'approval_required' : 'soft_plan_generated',
				'summary'    => 'hard' === $approval_level
					? 'Generated a hard approval-gated plan artifact.'
					: 'Generated a soft execution plan artifact.',
				'payload'    => array(
					'plan_id'      => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
					'plan_version' => (int) ( $artifact['version'] ?? 1 ),
					'approval_level' => $approval_level,
					'policy'       => is_array( $ctx['routing']['meta']['planning_decision'] ?? null ) ? (array) $ctx['routing']['meta']['planning_decision'] : array(),
				),
			),
			$trace_context
		);

		return $result;
	}

	private function load_plan_action_run( string $run_id ): array|WP_REST_Response {
		$run_id = sanitize_text_field( $run_id );
		if ( '' === $run_id ) {
			return new WP_REST_Response( array(
				'error'   => 'missing_run_id',
				'message' => __( 'A plan run ID is required.', 'pressark' ),
			), 400 );
		}

		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );
		if ( ! $run ) {
			return new WP_REST_Response( array(
				'error'   => 'plan_not_found',
				'message' => __( 'That plan could not be found. Please generate it again.', 'pressark' ),
			), 404 );
		}

		if ( (int) ( $run['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_REST_Response( array(
				'error'   => 'forbidden',
				'message' => __( 'You do not have permission to manage this plan.', 'pressark' ),
			), 403 );
		}

		return $run;
	}

	private function resolve_run_plan_artifact( array $run, PressArk_Checkpoint $checkpoint ): array {
		$artifact = method_exists( $checkpoint, 'get_plan_artifact' ) ? $checkpoint->get_plan_artifact() : array();
		if ( ! empty( $artifact ) ) {
			return $artifact;
		}

		$result_snapshot = is_array( $run['result'] ?? null ) ? $run['result'] : array();
		if ( class_exists( 'PressArk_Plan_Artifact' ) ) {
			return PressArk_Plan_Artifact::ensure(
				is_array( $result_snapshot['plan_artifact'] ?? null ) ? (array) $result_snapshot['plan_artifact'] : array(),
				is_array( $result_snapshot['plan_steps'] ?? null ) ? (array) $result_snapshot['plan_steps'] : array(),
				array(
					'plan_markdown'   => (string) ( $result_snapshot['plan_markdown'] ?? '' ),
					'approval_level'  => (string) ( $result_snapshot['approval_level'] ?? 'hard' ),
					'request_message' => (string) ( $run['message'] ?? '' ),
					'execute_message' => PressArk_Plan_Mode::strip_plan_directive( (string) ( $run['message'] ?? '' ) ),
					'run_id'          => (string) ( $run['run_id'] ?? '' ),
				)
			);
		}

		return array();
	}

	private function resolve_plan_context( array $run, PressArk_Checkpoint $checkpoint ): array {
		$this->ensure_plan_mode_loaded();

		$run_id        = sanitize_text_field( (string) ( $run['run_id'] ?? '' ) );
		$transient     = '' !== $run_id ? PressArk_Plan_Mode::get_context( $run_id ) : array();
		$request_state = method_exists( $checkpoint, 'get_plan_request_context' ) ? $checkpoint->get_plan_request_context() : array();
		$artifact      = $this->resolve_run_plan_artifact( $run, $checkpoint );
		$result_state  = is_array( $run['result'] ?? null ) ? (array) $run['result'] : array();
		$chat_id       = (int) ( $request_state['chat_id'] ?? $run['chat_id'] ?? 0 );

		$conversation = is_array( $transient['conversation'] ?? null ) ? (array) $transient['conversation'] : array();
		if ( empty( $conversation ) && is_array( $request_state['conversation'] ?? null ) ) {
			$conversation = (array) $request_state['conversation'];
		}
		if ( empty( $conversation ) && $chat_id > 0 ) {
			$chat_history = new PressArk_Chat_History();
			$stored_chat  = $chat_history->get_chat( $chat_id );
			if ( $stored_chat && is_array( $stored_chat['messages'] ?? null ) ) {
				$conversation = (array) $stored_chat['messages'];
			}
		}

		$message = (string) (
			$transient['message']
			?? $request_state['message']
			?? $run['message']
			?? ''
		);
		$execute_message = (string) (
			$transient['execute_message']
			?? $request_state['execute_message']
			?? ( $artifact['execute_message'] ?? '' )
			?? PressArk_Plan_Mode::strip_plan_directive( $message )
		);

		return array(
			'message'         => $message,
			'execute_message' => '' !== trim( $execute_message ) ? $execute_message : PressArk_Plan_Mode::strip_plan_directive( $message ),
			'conversation'    => $conversation,
			'screen'          => sanitize_text_field( (string) ( $transient['screen'] ?? $request_state['screen'] ?? '' ) ),
			'post_id'         => absint( $transient['post_id'] ?? $request_state['post_id'] ?? ( $run['post_id'] ?? 0 ) ),
			'deep_mode'       => ! empty( $transient['deep_mode'] ) || ! empty( $request_state['deep_mode'] ),
			'loaded_groups'   => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) ( $transient['loaded_groups'] ?? $request_state['loaded_groups'] ?? array() )
					)
				)
			),
			'chat_id'         => $chat_id,
			'approval_level'  => sanitize_key( (string) ( $artifact['approval_level'] ?? $result_state['approval_level'] ?? 'hard' ) ),
			'planning_mode'   => sanitize_key( (string) ( $transient['planning_mode'] ?? $result_state['planning_mode'] ?? 'hard_plan' ) ),
			'policy'          => is_array( $transient['policy'] ?? null )
				? (array) $transient['policy']
				: ( method_exists( $checkpoint, 'get_plan_policy' ) ? $checkpoint->get_plan_policy() : array() ),
		);
	}

	private function publish_plan_trace_event( array $run, string $event_type, string $status, string $reason, string $summary, array $payload = array() ): void {
		PressArk_Activity_Trace::publish(
			array(
				'event_type' => $event_type,
				'phase'      => 'plan',
				'status'     => sanitize_key( $status ),
				'reason'     => sanitize_key( $reason ),
				'summary'    => $summary,
				'payload'    => $payload,
			),
			array(
				'run_id'         => sanitize_text_field( (string) ( $run['run_id'] ?? '' ) ),
				'correlation_id' => sanitize_text_field( (string) ( $run['correlation_id'] ?? '' ) ),
				'chat_id'        => (int) ( $run['chat_id'] ?? 0 ),
				'user_id'        => (int) ( $run['user_id'] ?? 0 ),
				'route'          => sanitize_text_field( (string) ( $run['route'] ?? '' ) ),
			)
		);
	}

	private function advance_plan_execution_state( PressArk_Checkpoint $checkpoint, array $allowed_kinds, string $evidence ): void {
		if ( ! class_exists( 'PressArk_Execution_Ledger' ) || ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return;
		}
		if ( 'executing' !== $checkpoint->get_plan_phase() ) {
			return;
		}

		$artifact = $checkpoint->get_plan_artifact();
		if ( empty( $artifact ) ) {
			return;
		}

		$execution = PressArk_Execution_Ledger::complete_current_task(
			$checkpoint->get_execution(),
			$allowed_kinds,
			$evidence
		);
		$checkpoint->set_execution( $execution );
		$checkpoint->set_plan_artifact( PressArk_Plan_Artifact::sync_step_statuses( $artifact, $execution ) );
	}

	private function should_clear_plan_state_after_execution( PressArk_Checkpoint $checkpoint ): bool {
		if ( class_exists( 'PressArk_Continuation_Service' ) ) {
			$execution = method_exists( $checkpoint, 'get_execution' )
				? (array) $checkpoint->get_execution()
				: array();
			$decision  = PressArk_Continuation_Service::evaluate( $checkpoint, $execution );
			return ! empty( $decision['should_clear_plan'] );
		}

		if ( ! class_exists( 'PressArk_Execution_Ledger' ) ) {
			return true;
		}

		// Don't clear if the ledger still has remaining tasks.
		if ( PressArk_Execution_Ledger::has_remaining_tasks( $checkpoint->get_execution() ) ) {
			return false;
		}

		// v5.6.6 (2026-05-12): Also don't clear if the model's plan_artifact has
		// unfinished steps. The ledger may be empty of remaining tasks while the
		// plan still has steps the model hasn't emitted tool_calls for yet —
		// e.g., a 2-page request where round-1 emitted update_plan + create_post
		// for page A, leaving page B as a `pending` step in the plan but with
		// no corresponding dynamic ledger task. iter-13's `update_plan` tracker
		// skip exposed this gap (the orphan `dynamic_update_plan_N` had been
		// silently keeping plan_state alive). The model's plan is the
		// authoritative truth — honor it.
		$artifact = method_exists( $checkpoint, 'get_plan_artifact' )
			? (array) $checkpoint->get_plan_artifact()
			: array();
		$steps = is_array( $artifact['steps'] ?? null ) ? $artifact['steps'] : array();
		foreach ( $steps as $step ) {
			$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
				return false;
			}
		}

		// v5.7.6 (2026-05-12): Also check plan_steps (model-emitted update_plan
		// storage when no Plan Mode pre-seeded an artifact). Mirrors the
		// attach_continuation_context dual-source check — without this site,
		// clear_plan_state would wipe plan_steps after step N's Keep even when
		// step N+1 is still pending in the model's own emitted plan.
		if ( method_exists( $checkpoint, 'get_plan_steps' ) ) {
			$plan_steps = (array) $checkpoint->get_plan_steps();
			foreach ( $plan_steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
				if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
					$this->log_continuation_evaluator_decision( $checkpoint, 'should_clear_plan_state_after_execution', false );
					return false;
				}
			}
		}

		$this->log_continuation_evaluator_decision( $checkpoint, 'should_clear_plan_state_after_execution', true );
		return true;
	}

	/**
	 * v5.7.13 (2026-05-13): Phase 3a forensic — log when the evaluator's
	 * should_clear_plan decision diverges from the legacy decision. Cheap
	 * (one error_log line on divergence only). Removed in Phase 3b once
	 * call sites consume evaluator-as-truth.
	 */
	private function log_continuation_evaluator_decision( PressArk_Checkpoint $checkpoint, string $site, bool $legacy_should_clear ): void {
		if ( ! class_exists( 'PressArk_Continuation_Service' ) ) {
			return;
		}
		$execution = method_exists( $checkpoint, 'get_execution' ) ? (array) $checkpoint->get_execution() : array();
		$decision  = PressArk_Continuation_Service::evaluate( $checkpoint, $execution );
		if ( $decision['should_clear_plan'] !== $legacy_should_clear ) {
			if ( class_exists( 'PressArk_Error_Tracker' ) ) {
				PressArk_Error_Tracker::warning(
					'Continuation',
					'Continuation evaluator divergence',
					array(
						'site'                   => $site,
						'legacy_should_clear'    => $legacy_should_clear,
						'evaluator_should_clear' => (bool) $decision['should_clear_plan'],
						'reason_code'            => (string) $decision['reason_code'],
					)
				);
			}
		}
	}

	private function maybe_complete_plan_execution( array $run, PressArk_Checkpoint $checkpoint ): void {
		if ( 'executing' !== $checkpoint->get_plan_phase() ) {
			return;
		}
		if ( ! $this->should_clear_plan_state_after_execution( $checkpoint ) ) {
			return;
		}
		if ( method_exists( $checkpoint, 'get_plan_status' ) && 'completed' === $checkpoint->get_plan_status() ) {
			$checkpoint->clear_plan_state();
			return;
		}

		$artifact = $checkpoint->get_plan_artifact();
		if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
			$checkpoint->set_plan_status( 'completed' );
		}

		$this->publish_plan_trace_event(
			$run,
			'plan.execution_completed',
			'completed',
			'approved_plan_finished',
			'Execution completed for the approved plan artifact.',
			array(
				'plan_id'      => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
				'plan_version' => (int) ( $artifact['version'] ?? 1 ),
				'approval_level' => sanitize_key( (string) ( $artifact['approval_level'] ?? 'hard' ) ),
			)
		);

		$checkpoint->clear_plan_state();
	}

	private function build_plan_execute_request( array $run, array $plan_context, PressArk_Checkpoint $checkpoint ): WP_REST_Request {
		return $this->request_compiler->build_plan_execute_request( $run, $plan_context, $checkpoint );
	}

	/**
	 * Cancel the active run for a given chat.
	 *
	 * Called when the user clicks Stop in the chat panel. Marks the
	 * target run as cancelled so the Activity page reflects the
	 * cancellation immediately, without waiting for connection_aborted()
	 * detection on the streaming path.
	 *
	 * Resolution order:
	 *   1. `run_id` ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â direct, no ambiguity (preferred, from run_started SSE)
	 *   2. `chat_id` ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â lookup most recent cancellable run for this chat
	 *   3. user-level fallback ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â most recent cancellable run for the user
	 *      (covers very-fast-cancel: user hit Stop before run_started arrived)
	 */
	public function handle_cancel( WP_REST_Request $request ): WP_REST_Response {
		$run_id_param = sanitize_text_field( $request->get_param( 'run_id' ) ?? '' );
		$chat_id      = absint( $request->get_param( 'chat_id' ) );
		$user_id      = get_current_user_id();

		if ( ! $user_id ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}

		$run_store = new PressArk_Run_Store();

		// 1. Preferred path: direct run_id (no ambiguity, no lookup).
		if ( $run_id_param ) {
			$run = $run_store->get( $run_id_param );
			if ( $run && (int) $run['user_id'] === $user_id ) {
				$cancel_result = $this->build_terminal_outcome_result(
					'cancelled',
					__( 'The request was cancelled before completion.', 'pressark' ),
					array(
						'success'  => false,
						'is_error' => true,
					),
					array(
						'actor'       => 'user',
						'scope'       => 'run',
						'reason_code' => 'user_cancelled',
					)
				);
				$run_store->mark_cancelled( $run_id_param );
				$run_store->persist_detail_snapshot( $run_id_param, null, $cancel_result );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}

		// 2. Fallback: lookup by chat_id.
		if ( $chat_id ) {
			$found_run_id = $run_store->find_latest_cancellable_run_id( $user_id, $chat_id );
			if ( $found_run_id ) {
				$cancel_result = $this->build_terminal_outcome_result(
					'cancelled',
					__( 'The request was cancelled before completion.', 'pressark' ),
					array(
						'success'  => false,
						'is_error' => true,
					),
					array(
						'actor'       => 'user',
						'scope'       => 'run',
						'reason_code' => 'user_cancelled',
					)
				);
				$run_store->mark_cancelled( $found_run_id );
				$run_store->persist_detail_snapshot( $found_run_id, null, $cancel_result );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}

		// 3. Last resort: cancel the user's most recent cancellable run.
		//    Covers very-fast-cancel (Stop pressed before run_started SSE).
		if ( ! $run_id_param && ! $chat_id ) {
			$found_run_id = $run_store->find_latest_cancellable_run_id( $user_id );
			if ( $found_run_id ) {
				$cancel_result = $this->build_terminal_outcome_result(
					'cancelled',
					__( 'The request was cancelled before completion.', 'pressark' ),
					array(
						'success'  => false,
						'is_error' => true,
					),
					array(
						'actor'       => 'user',
						'scope'       => 'run',
						'reason_code' => 'user_cancelled',
					)
				);
				$run_store->mark_cancelled( $found_run_id );
				$run_store->persist_detail_snapshot( $found_run_id, null, $cancel_result );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}

		// No matching run found ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â already settled, failed, or never created.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Poll for async task completions and queue state.
	 *
	 * v4.2.0: Added unread_count and activity_url so the chat panel can
	 * point users to the Activity page when results are waiting.
	 */
	public function handle_poll( WP_REST_Request $_request ): WP_REST_Response {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return rest_ensure_response( array(
				'completed_tasks'    => array(),
				'pending_task_count' => 0,
				'has_pending_tasks'  => false,
				'unread_count'       => 0,
			) );
		}

		$queue      = new PressArk_Task_Queue();
		$task_store = new PressArk_Task_Store();
		$index      = new PressArk_Content_Index();

		$notifications = $queue->pop_notifications( $user_id );
		$pending_count = $task_store->pending_count( $user_id );
		$unread_count  = $task_store->unread_count( $user_id );
		$index_status  = $index->get_runtime_status();

		// v4.3.0: Schedule rescue processing for overdue queued tasks.
		// The poll endpoint fires every 15-60s from the frontend, making
		// it a reliable heartbeat for detecting stuck tasks. Processing
		// runs after the response via shutdown + fastcgi_finish_request,
		// so the client sees no delay.
		self::maybe_rescue_task_after_response( $task_store );

		return rest_ensure_response( array(
			'completed_tasks'    => $notifications,
			'pending_task_count' => $pending_count,
			'has_pending_tasks'  => $pending_count > 0,
			'unread_count'       => $unread_count,
			'activity_url'       => self::get_activity_url( $unread_count > 0 ),
			'index_status'       => $index_status,
			'index_rebuilding'   => (bool) $index_status['running'],
			'index_progress'     => (int) $index_status['processed_posts'],
		) );
	}

	/**
	 * Rescue stuck tasks via shutdown hook after the poll response.
	 *
	 * Both WP-Cron and Action Scheduler depend on HTTP loopback, which
	 * fails in Docker, firewalled hosts, and reverse proxies. This
	 * detects two failure modes:
	 *
	 * 1. Stuck AS actions ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â pressark actions piling up in 'pending'
	 *    because AS's own runner never fires. Fix: kick AS inline.
	 *
	 * 2. Overdue queued tasks ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â tasks stuck in pressark_tasks because
	 *    neither AS nor WP-Cron processed the cron event. Fix: process
	 *    the task directly in a shutdown handler.
	 *
	 * The poll endpoint fires every 15-60s from the frontend, making it
	 * the most reliable heartbeat. Processing runs after the response
	 * via shutdown + fastcgi_finish_request, so the client sees no delay.
	 *
	 * @since 4.3.0
	 */
	private static function maybe_rescue_task_after_response( PressArk_Task_Store $task_store ): void {
		static $scheduled = false;
		if ( $scheduled ) {
			return;
		}

		// Throttle to one rescue attempt per 60 seconds.
		$transient_key = 'pressark_poll_task_rescue';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		$has_stuck_as = self::has_pending_as_actions();
		$overdue      = $task_store->find_oldest_overdue_queued();

		if ( ! $has_stuck_as && ! $overdue ) {
			return;
		}

		$scheduled = true;
		set_transient( $transient_key, 1, 60 );

		$task_id = $overdue ? $overdue['task_id'] : null;
		register_shutdown_function( static function () use ( $task_id, $has_stuck_as ) {
			// Close the client connection so the browser doesn't wait.
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			ignore_user_abort( true );
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Intentional: this code runs in a post-response shutdown handler (fastcgi_finish_request above closes the client connection); extending the time limit is required to drain stuck Action Scheduler tasks without aborting mid-process.
			set_time_limit( 300 );

			// Kick AS runner to process stuck pressark actions.
			if ( $has_stuck_as ) {
				PressArk_Cron_Manager::maybe_kick_as_runner();
			}

			// Directly process one overdue task as a last resort.
			if ( $task_id ) {
				$queue = new PressArk_Task_Queue();
				$queue->process( $task_id );
			}
		} );
	}

	/**
	 * Check if Action Scheduler has pending pressark actions past their
	 * scheduled time.
	 *
	 * @since 4.3.0
	 */
	private static function has_pending_as_actions(): bool {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is the wpdb-prefixed literal 'actionscheduler_actions'; user data bound via %s placeholders. Real-time queue-health probe; caching would defeat its purpose.
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE hook LIKE %s
			 AND status = %s
			 AND scheduled_date_gmt <= UTC_TIMESTAMP()",
			'pressark%',
			'pending'
		) ) > 0;
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Fetch a task's full result by ID.
	 *
	 * This allows the chat panel (or any client) to retrieve a completed
	 * task result without relying on the heartbeat poll. Marks as read.
	 *
	 * @since 4.2.0
	 */
	public function handle_get_task_result( WP_REST_Request $request ): WP_REST_Response {
		$task_id = sanitize_text_field( $request['task_id'] );
		$user_id = get_current_user_id();

		$task_store = new PressArk_Task_Store();
		$task       = $task_store->get_result_for_user( $task_id, $user_id );

		if ( ! $task ) {
			return new WP_REST_Response( array( 'error' => 'Task not found.' ), 404 );
		}

		return rest_ensure_response( array(
			'task_id'      => $task['task_id'],
			'status'       => $task['status'],
			'message'      => $task['message'],
			'result'       => $task['result'],
			'progress'     => is_array( $task['progress'] ?? null ) ? $task['progress'] : array(),
			'fail_reason'  => $task['fail_reason'] ?? null,
			'created_at'   => $task['created_at'],
			'completed_at' => $task['completed_at'],
			'retries'      => $task['retries'],
		) );
	}

	/**
	 * Can the current user access PressArk chat?
	 *
	 * Delegates to PressArk_Capabilities::current_user_can_use().
	 * Kept as a static facade for backward compatibility ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â called from
	 * class-pressark.php, class-admin-activity.php, and third-party code.
	 *
	 * @since 4.2.0 Delegates to PressArk_Capabilities.
	 */
	public static function user_can_access(): bool {
		$can = PressArk_Capabilities::current_user_can_use();

		// Backward-compatible shim: honour the old filter for one major version.
		if ( has_filter( 'pressark_can_access_chat' ) ) {
			_deprecated_hook(
				'pressark_can_access_chat',
				'4.2.0',
				'pressark_can_use',
				esc_html__( 'Use the pressark_can_use filter instead.', 'pressark' )
			);
			$can = (bool) apply_filters( 'pressark_can_access_chat', $can, '' );
		}

		return $can;
	}

	/**
	 * Build the current user's Activity URL.
	 */
	public static function get_activity_url( bool $prefer_tasks = false ): string {
		$args = array( 'page' => 'pressark-activity' );

		if ( $prefer_tasks ) {
			$args['view'] = 'tasks';
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Sanitize the conversation array from the client.
	 * Only allow 'user' and 'assistant' roles, sanitize content.
	 */
	/**
	 * Sanitize the conversation array from the client.
	 *
	 * Only allow 'user' and 'assistant' roles. Content is plain text ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â
	 * strip all HTML since the client renders via its own markdown
	 * renderer. Using sanitize_text_field instead of wp_kses_post
	 * prevents stored HTML from surviving round-trips through chat
	 * history and becoming executable on reload.
	 */
	private function sanitize_conversation( $conversation ): array {
		return $this->request_compiler->sanitize_conversation( $conversation );
	}

	/**
	 * Sanitize conversation content preserving code/HTML.
	 * Strips control characters and invalid UTF-8 but keeps angle brackets,
	 * line breaks, and whitespace ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â same logic as the message input field.
	 */
	private function sanitize_conversation_content( string $content ): string {
		return $this->request_compiler->sanitize_conversation_content( $content );
	}

	/**
	 * Pre-flight write check using the unified entitlement service.
	 *
	 * v3.5.0: Replaced unreliable pattern-matching heuristic with a direct
	 * entitlement check. The AI can still perform reads regardless of write
	 * quota ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â writes are gated at confirm/preview time. This pre-flight
	 * blocks requests that pattern-match obvious write intent to save
	 * token budget, but does NOT pretend to be a security gate.
	 *
	 * @param string $tier    User's current tier.
	 * @param string $message User message.
	 * @param PressArk_Usage_Tracker $tracker Tracker instance.
	 * @return WP_REST_Response|null Null = allowed, response = blocked.
	 */
	private function quick_write_check( string $tier, string $message, PressArk_Usage_Tracker $tracker ): ?WP_REST_Response {
		// Local write access is open; this remains as a defensive compatibility path.
		if ( PressArk_Entitlements::can_write( $tier ) ) {
			return null;
		}

		// Legacy fallback if a downstream filter disables writes.
		$write_patterns = '/\b(edit|update|change|modify|delete|fix|create|add|remove|publish|replace|rewrite|apply|set|enable|disable|install|activate|deactivate|send|moderate|assign|switch|toggle|cleanup|optimize|rebuild|clear)\b/i';

		if ( preg_match( $write_patterns, $message ) ) {
			$limit_msg = PressArk_Entitlements::limit_message( 'write_limit', $tier );
			$permission = array(
				'allowed'     => false,
				'behavior'    => 'ask',
				'reason'      => $limit_msg,
				'message'     => $limit_msg,
				'ui_action'   => 'none',
				'error'       => 'entitlement_denied',
				'basis'       => 'local_write_unavailable',
			);

			return new WP_REST_Response(
				array_merge(
					PressArk_Pipeline::build_permission_response( $permission ),
					array(
						'usage'     => $tracker->get_usage_data(),
						'plan_info' => PressArk_Entitlements::get_plan_info( $tier ),
					)
				),
				200
			);
		}

		return null; // Reads are always allowed.
	}

	/**
	 * Handle a chat message.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
		if ( defined( 'PRESSARK_DEBUG_ROUTE' ) && PRESSARK_DEBUG_ROUTE && class_exists( 'PressArk_Planning_Policy' ) && PressArk_Planning_Policy::route_debug_env_ok() ) {
			$log_path = defined( 'PRESSARK_DEBUG_ROUTE_LOG' ) ? (string) PRESSARK_DEBUG_ROUTE_LOG : '/tmp/pressark-route.log';
			@file_put_contents(
				$log_path,
				sprintf(
					"[%s] INVOKE handle_chat route=%s referer=%s msg=%.60s\n",
					gmdate( 'H:i:s' ),
					(string) $request->get_route(),
					(string) ( $request->get_header( 'referer' ) ?: '' ),
					preg_replace( '/\s+/', ' ', (string) $request->get_param( 'message' ) )
				),
				FILE_APPEND
			);
		}
		$compiled_request = $this->request_compiler->compile_rest_request( $request );
		if ( $compiled_request instanceof WP_REST_Response ) {
			return $compiled_request;
		}

		try {
			$response = $this->process_chat( $compiled_request );
			$data     = $response->get_data();

			if ( is_array( $data ) && 'permission_required' === ( $data['type'] ?? '' ) ) {
				$response->set_status( 200 );
			}

			return $response;
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Chat request failed', array( 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ) );
			return rest_ensure_response( array(
				'success'           => false,
				'reply'             => __( 'PressArk encountered an internal error and could not complete your request. Please try again. If this keeps happening, check Settings > PressArk > API.', 'pressark' ),
				'actions_performed' => array(),
				'pending_actions'   => array(),
				'is_error'          => true,
			) );
		} finally {
			$this->release_chat_lock();
		}
	}

	public function handle_plan_execute( WP_REST_Request $request ): WP_REST_Response {
		$this->log_plan_invoke( 'plan_execute', $request );
		return $this->handle_plan_approve( $request );
	}

	public function handle_plan_execute_stream( WP_REST_Request $request ): void {
		$this->log_plan_invoke( 'plan_execute_stream', $request );
		$this->handle_plan_approve_stream( $request );
	}

	private function log_plan_invoke( string $tag, WP_REST_Request $request ): void {
		if ( ! defined( 'PRESSARK_DEBUG_ROUTE' ) || ! PRESSARK_DEBUG_ROUTE ) {
			return;
		}
		if ( ! class_exists( 'PressArk_Planning_Policy' ) || ! PressArk_Planning_Policy::route_debug_env_ok() ) {
			return;
		}
		$log_path = defined( 'PRESSARK_DEBUG_ROUTE_LOG' ) ? (string) PRESSARK_DEBUG_ROUTE_LOG : '/tmp/pressark-route.log';
		@file_put_contents(
			$log_path,
			sprintf(
				"[%s] INVOKE %s route=%s run_id=%s referer=%s\n",
				gmdate( 'H:i:s' ),
				$tag,
				(string) $request->get_route(),
				(string) $request->get_param( 'run_id' ),
				(string) ( $request->get_header( 'referer' ) ?: '' )
			),
			FILE_APPEND
		);
	}

	private function prepare_plan_execution_request( WP_REST_Request $request ): WP_REST_Request|WP_REST_Response {
		$this->ensure_plan_mode_loaded();

		$loaded = $this->load_plan_action_run( (string) $request->get_param( 'run_id' ) );
		if ( $loaded instanceof WP_REST_Response ) {
			return $loaded;
		}

		$run = $loaded;
		$this->hydrate_trace_context_from_run( $run );

		$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
		$artifact   = $this->resolve_run_plan_artifact( $run, $checkpoint );
		if ( empty( $artifact ) ) {
			return new WP_REST_Response( array(
				'error'   => 'missing_plan',
				'message' => __( 'This run does not have a saved plan to execute.', 'pressark' ),
			), 409 );
		}

		$plan_context = $this->resolve_plan_context( $run, $checkpoint );
		$artifact     = $checkpoint->approve_plan_artifact( $artifact );
		if ( class_exists( 'PressArk_Plan_Artifact' ) ) {
			$checkpoint->set_execution( PressArk_Plan_Artifact::seed_execution_ledger( $artifact, $checkpoint->get_execution() ) );
			$checkpoint->set_plan_artifact( PressArk_Plan_Artifact::sync_step_statuses( $artifact, $checkpoint->get_execution() ) );
		}

		$this->persist_run_checkpoint( $run, $checkpoint );
		PressArk_Plan_Mode::exit( (string) ( $run['run_id'] ?? '' ), (string) ( $checkpoint->get_plan_text() ?: ( class_exists( 'PressArk_Plan_Artifact' ) ? PressArk_Plan_Artifact::to_markdown( $artifact ) : '' ) ) );

		$this->publish_plan_trace_event(
			$run,
			'plan.approved',
			'approved',
			'user_approved',
			'The plan was approved for execution.',
			array(
				'plan_id'        => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
				'plan_version'   => (int) ( $artifact['version'] ?? 1 ),
				'approval_level' => sanitize_key( (string) ( $artifact['approval_level'] ?? 'hard' ) ),
			)
		);
		$this->publish_plan_trace_event(
			$run,
			'plan.execution_started',
			'running',
			'approved_plan_execution',
			'Execution started from the approved plan artifact.',
			array(
				'plan_id'      => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
				'plan_version' => (int) ( $artifact['version'] ?? 1 ),
			)
		);

		$execute_request = $this->build_plan_execute_request( $run, $plan_context, $checkpoint );
		$execute_request->set_param( 'plan_execute', true );

		return $execute_request;
	}

	public function handle_plan_approve( WP_REST_Request $request ): WP_REST_Response {
		$this->log_plan_invoke( 'plan_approve', $request );
		$execute_request = $this->prepare_plan_execution_request( $request );
		if ( $execute_request instanceof WP_REST_Response ) {
			return $execute_request;
		}

		return $this->handle_chat( $execute_request );
	}

	public function handle_plan_approve_stream( WP_REST_Request $request ): void {
		$this->log_plan_invoke( 'plan_approve_stream', $request );
		$execute_request = $this->prepare_plan_execution_request( $request );
		if ( $execute_request instanceof WP_REST_Response ) {
			$status = $execute_request->get_status();
			$data   = $execute_request->get_data();

			if ( 200 === $status ) {
				$emitter = new PressArk_SSE_Emitter();
				$emitter->start();
				$emitter->emit(
					'done',
					is_array( $data )
						? $data
						: array( 'type' => 'final_response', 'message' => (string) $data )
				);
				$emitter->close();
				exit;
			}

			wp_send_json( $data, $status );
			return;
		}

		$this->handle_chat_stream( $execute_request );
	}

	public function handle_plan_revise( WP_REST_Request $request ): WP_REST_Response {
		$this->ensure_plan_mode_loaded();

		$loaded = $this->load_plan_action_run( (string) $request->get_param( 'run_id' ) );
		if ( $loaded instanceof WP_REST_Response ) {
			return $loaded;
		}

		$revision_note = sanitize_text_field( (string) $request->get_param( 'revision_note' ) );
		if ( '' === $revision_note ) {
			return new WP_REST_Response( array(
				'error'   => 'missing_revision_note',
				'message' => __( 'Add a revision note so I know how to update the plan.', 'pressark' ),
			), 400 );
		}

		$run = $loaded;
		$this->hydrate_trace_context_from_run( $run );

		$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
		$artifact   = $this->resolve_run_plan_artifact( $run, $checkpoint );
		if ( empty( $artifact ) ) {
			return new WP_REST_Response( array(
				'error'   => 'missing_plan',
				'message' => __( 'There is no active plan to revise.', 'pressark' ),
			), 409 );
		}

		$checkpoint->set_plan_artifact( $artifact );
		$checkpoint->queue_plan_revision( $revision_note );
		$checkpoint->set_plan_phase( 'planning' );
		if ( method_exists( $checkpoint, 'set_plan_status' ) ) {
			$checkpoint->set_plan_status( 'revising' );
		}
		$this->persist_run_checkpoint( $run, $checkpoint );

		$this->publish_plan_trace_event(
			$run,
			'plan.revised',
			'queued',
			'user_revised',
			'The plan was sent back for revision.',
			array(
				'plan_id'        => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
				'prior_version'  => (int) ( $artifact['version'] ?? 1 ),
				'revision_note'  => $revision_note,
				'next_version'   => method_exists( $checkpoint, 'get_next_plan_version' ) ? (int) $checkpoint->get_next_plan_version() : 0,
			)
		);

		$plan_context    = $this->resolve_plan_context( $run, $checkpoint );
		$base_message    = PressArk_Plan_Mode::strip_plan_directive( (string) ( $plan_context['message'] ?? $run['message'] ?? '' ) );
		$revised_message = trim( $base_message . "\nRevision note: " . $revision_note );
		$revised_request = new WP_REST_Request( 'POST', '/pressark/v1/chat' );
		$revised_request->set_param( 'message', '/plan ' . $revised_message );
		$revised_request->set_param( 'conversation', $plan_context['conversation'] ?? array() );
		$revised_request->set_param( 'screen', $plan_context['screen'] ?? '' );
		$revised_request->set_param( 'post_id', (int) ( $plan_context['post_id'] ?? 0 ) );
		$revised_request->set_param( 'deep_mode', ! empty( $plan_context['deep_mode'] ) );
		$revised_request->set_param( 'loaded_groups', $plan_context['loaded_groups'] ?? array() );
		$revised_request->set_param( 'chat_id', (int) ( $plan_context['chat_id'] ?? ( $run['chat_id'] ?? 0 ) ) );
		$revised_request->set_param( 'checkpoint', $checkpoint->to_array() );

		return $this->handle_chat( $revised_request );
	}

	public function handle_plan_reject( WP_REST_Request $request ): WP_REST_Response {
		$this->ensure_plan_mode_loaded();

		$loaded = $this->load_plan_action_run( (string) $request->get_param( 'run_id' ) );
		if ( $loaded instanceof WP_REST_Response ) {
			return $loaded;
		}

		$run    = $loaded;
		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$this->hydrate_trace_context_from_run( $run );

		$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
		$artifact   = $this->resolve_run_plan_artifact( $run, $checkpoint );
		if ( empty( $artifact ) ) {
			return new WP_REST_Response( array(
				'error'   => 'missing_plan',
				'message' => __( 'There is no active plan to reject.', 'pressark' ),
			), 409 );
		}

		$checkpoint->reject_plan_artifact( $reason );
		$this->persist_run_checkpoint( $run, $checkpoint );
		PressArk_Plan_Mode::abort( (string) ( $run['run_id'] ?? '' ) );

		$this->publish_plan_trace_event(
			$run,
			'plan.rejected',
			'cancelled',
			'user_rejected',
			'The plan was cancelled before execution.',
			array(
				'plan_id'      => sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) ),
				'plan_version' => (int) ( $artifact['version'] ?? 1 ),
				'reason'       => $reason,
			)
		);

		$result = array(
			'success'        => true,
			'type'           => 'final_response',
			'message'        => __( 'Plan cancelled. No changes were made.', 'pressark' ),
			'reply'          => __( 'Plan cancelled. No changes were made.', 'pressark' ),
			'plan_rejected'  => true,
			'run_id'         => (string) ( $run['run_id'] ?? '' ),
			'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
			'checkpoint'     => $checkpoint->to_array(),
		);
		$this->persist_run_detail_snapshot( $run, $result, $checkpoint );

		return rest_ensure_response( $result );
	}

	/**
	 * Pre-flight steps 1-6: sanitize, write check, throttle, route/permission gate, reserve, acquire slot.
	 *
	 * Shared by process_chat() and process_chat_stream() to avoid duplicating ~200 lines.
	 *
	 * @since 4.4.0
	 * @return array|WP_REST_Response Returns the context array on success, or a WP_REST_Response on early exit.
	 */
	/**
	 * v5.0.1: Per-chat advisory lock prevents concurrent requests from creating
	 * duplicate runs, double-reserving tokens, or corrupting conversation state.
	 * Stored as instance var so it can be released in finally blocks.
	 *
	 * @var string
	 */
	private string $chat_lock_name = '';

	/**
	 * Acquire an advisory lock for a chat session.
	 *
	 * Uses MySQL GET_LOCK for true mutual exclusion. Falls back to a transient-
	 * based guard on environments where GET_LOCK is unavailable (e.g. Galera).
	 *
	 * @param int $user_id User ID (used when chat_id is 0 ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â new conversation).
	 * @param int $chat_id Chat ID (0 for new conversations).
	 * @return bool True if lock acquired, false if another request holds it.
	 */
	private function acquire_chat_lock( int $user_id, int $chat_id ): bool {
		global $wpdb;

		// For new conversations (chat_id=0), lock on user_id to prevent concurrent new-chat creation.
		$lock_key = $chat_id > 0
			? 'pressark_chat_' . $chat_id
			: 'pressark_newchat_' . $user_id;

		$this->chat_lock_name = $lock_key;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock acquisition; side-effecting operation that cannot be cached.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_key ) );

		return (int) $acquired === 1;
	}

	/**
	 * Release the advisory lock acquired by acquire_chat_lock().
	 */
	private function release_chat_lock(): void {
		if ( '' === $this->chat_lock_name ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock release; side-effecting operation that cannot be cached.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->chat_lock_name ) );
		$this->chat_lock_name = '';
	}

	private function message_looks_like_clarification_answer( string $message ): bool {
		return $this->request_compiler->message_looks_like_clarification_answer( $message );
	}

	private function maybe_expand_pending_plan_followup( string $message, array $checkpoint_data, array &$loaded_groups ): string {
		return $this->request_compiler->maybe_expand_pending_plan_followup( $message, $checkpoint_data, $loaded_groups );
	}

	/**
	 * Mirror unresolved confirm-card actions into checkpoint pending markers.
	 *
	 * @param PressArk_Checkpoint          $checkpoint      Checkpoint to update.
	 * @param array<int,array<string,mixed>> $pending_actions Raw pending_actions rows.
	 */
	private function sync_checkpoint_pending_confirms( PressArk_Checkpoint $checkpoint, array $pending_actions ): void {
		$checkpoint->clear_pending();

		foreach ( $pending_actions as $pending_action ) {
			if ( ! is_array( $pending_action ) || ! empty( $pending_action['resolved'] ) ) {
				continue;
			}

			$action = is_array( $pending_action['action'] ?? null )
				? (array) $pending_action['action']
				: $pending_action;
			$name   = sanitize_text_field( (string) ( $action['name'] ?? $action['type'] ?? 'unknown_action' ) );
			$args   = is_array( $action['arguments'] ?? null )
				? (array) $action['arguments']
				: ( is_array( $action['params'] ?? null ) ? (array) $action['params'] : array() );
			$target = '';

			if ( ! empty( $args['post_id'] ) ) {
				$target = 'post #' . absint( $args['post_id'] );
			} elseif ( ! empty( $args['title'] ) ) {
				$target = '"' . sanitize_text_field( (string) $args['title'] ) . '"';
			}

			$checkpoint->add_pending(
				'' !== $name ? $name : 'unknown_action',
				'' !== $target ? $target : 'site',
				'NOT YET APPLIED - awaiting user approval'
			);
		}
	}

	private function preflight( WP_REST_Request $request ): array|WP_REST_Response {
		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [1] Sanitize ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$message      = $request->get_param( 'message' );
		$conversation = $this->sanitize_conversation( $request->get_param( 'conversation' ) );
		$screen       = $request->get_param( 'screen' );
		$post_id      = (int) $request->get_param( 'post_id' );
		$suppress_plan = (bool) $request->get_param( 'suppress_plan' );
		$plan_execute  = (bool) $request->get_param( 'plan_execute' );
		$this->ensure_plan_mode_loaded();
		$original_message = (string) $message;
		$execution_message = ( $suppress_plan || PressArk_Plan_Mode::message_requests_plan( $original_message ) )
			? PressArk_Plan_Mode::strip_plan_directive( $original_message )
			: $original_message;

		$tracker = new PressArk_Usage_Tracker();
		$user_id = get_current_user_id();

		$license          = new PressArk_License();
		$tier             = $license->get_tier();
		$client_deep_mode = (bool) $request->get_param( 'deep_mode' );
		$deep_mode        = $client_deep_mode && PressArk_Entitlements::can_use_feature( $tier, 'deep_mode' );

		$loaded_groups = $request->get_param( 'loaded_groups' );
		if ( ! is_array( $loaded_groups ) ) {
			$loaded_groups = array();
		}
		$valid_groups  = PressArk_Operation_Registry::group_names();
		$loaded_groups = array_values( array_intersect(
			array_map( 'sanitize_text_field', $loaded_groups ),
			$valid_groups
		) );

		$chat_id = (int) $request->get_param( 'chat_id' );
		if ( $chat_id <= 0 && ! empty( $conversation ) ) {
			$chat_history = new PressArk_Chat_History();
			$chat_title   = PressArk_Chat_History::generate_title( $execution_message );
			$created_chat = $chat_history->create_chat( $chat_title, $conversation );
			if ( $created_chat ) {
				$chat_id = (int) $created_chat;
			}
		}
		$checkpoint_data = $request->get_param( 'checkpoint' );
		if ( ! is_array( $checkpoint_data ) ) {
			$checkpoint_data = array();
		}

		$server_checkpoint = PressArk_Checkpoint::load( $chat_id, $user_id );
		if ( $server_checkpoint && ! empty( $checkpoint_data ) ) {
			$client_checkpoint = PressArk_Checkpoint::from_array( $checkpoint_data );
			$merged            = PressArk_Checkpoint::merge( $server_checkpoint, $client_checkpoint );
			$checkpoint_data   = $merged->to_array();
		} elseif ( $server_checkpoint ) {
			$checkpoint_data = $server_checkpoint->to_array();
		}

		// v5.2.0: Sync pending confirm actions into checkpoint so the model
		// knows prior proposed writes were NOT applied when the user sends
		// a follow-up message instead of clicking Approve.
		// Also clears stale pending entries when the run has been settled
		// (user clicked Approve since the last message).
		if ( $chat_id > 0 && ! empty( $checkpoint_data ) ) {
			$run_store_preflight = new PressArk_Run_Store();
			$pending_confirm     = $run_store_preflight->get_pending_confirm_actions( $user_id, $chat_id );
			$cp                  = PressArk_Checkpoint::from_array( $checkpoint_data );

			if ( ! empty( $pending_confirm ) ) {
				// Active awaiting_confirm run ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â populate pending entries.
				$cp->clear_pending();
				foreach ( $pending_confirm as $pa ) {
					$name   = $pa['name'] ?? $pa['type'] ?? 'unknown_action';
					$target = '';
					$args   = $pa['arguments'] ?? $pa['params'] ?? array();
					if ( ! empty( $args['post_id'] ) ) {
						$target = 'post #' . $args['post_id'];
					} elseif ( ! empty( $args['title'] ) ) {
						$target = '"' . $args['title'] . '"';
					}
					$cp->add_pending( $name, $target ?: 'site', 'NOT YET APPLIED ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â awaiting user approval' );
				}
				$this->sync_checkpoint_pending_confirms( $cp, $pending_confirm );
			} elseif ( $cp->has_unapplied_confirms() ) {
				// No awaiting_confirm run but stale entries remain (user
				// clicked Approve since last message) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â clear them.
				$cp->clear_pending();
			}

			$checkpoint_data = $cp->to_array();
		}

		if ( $chat_id > 0 ) {
			$chat_history  = new PressArk_Chat_History();
			$stored_chat   = $chat_history->get_chat( $chat_id );
			$server_messages = ( $stored_chat && is_array( $stored_chat['messages'] ) )
				? $stored_chat['messages']
				: array();

			if ( ! empty( $server_messages ) ) {
				$server_count = count( $server_messages );
				$client_count = count( $conversation );

				if ( $client_count > $server_count ) {
					$new_messages = array_slice( $conversation, $server_count );
					$conversation = array_merge( $server_messages, $new_messages );
				} else {
					$conversation = $server_messages;
				}
			}
		}

		$effective_message = $this->maybe_expand_pending_plan_followup( $execution_message, $checkpoint_data, $loaded_groups );
		if ( '' !== trim( $effective_message ) && $effective_message !== $execution_message ) {
			$execution_message = $effective_message;
			$original_message  = $effective_message;
		}

		if ( '__pressark_mark_onboarded__' === $message ) {
			update_user_meta( $user_id, 'pressark_onboarded', '1' );
			return new WP_REST_Response( array(
				'reply'             => '',
				'actions_performed' => array(),
				'pending_actions'   => array(),
				'usage'             => $tracker->get_usage_data(),
			), 200 );
		}

		if ( empty( $message ) ) {
			return new WP_REST_Response( array( 'error' => 'Empty message' ), 400 );
		}

		$post_id           = $this->resolve_effective_post_id( $execution_message, $post_id, $checkpoint_data );
		// v5.8.13 (2026-05-14): mirror compiler scoping for streaming preflight.
		$loaded_groups     = $this->request_compiler->scope_loaded_groups_for_contextual_followup( $execution_message, $checkpoint_data, $loaded_groups, $post_id );
		$loaded_groups     = $this->request_compiler->load_groups_for_numbered_read_followup( $execution_message, $checkpoint_data, $loaded_groups, $post_id );
		$continuation_mode = $this->resolve_continuation_mode( $execution_message, $checkpoint_data, $plan_execute );

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [1b] Chat-level mutex ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		// Prevents concurrent requests for the same chat from creating
		// duplicate runs, double-reserving tokens, or corrupting state.
		if ( ! $this->acquire_chat_lock( $user_id, $chat_id ) ) {
			return new WP_REST_Response( array(
				'error'       => 'concurrent_chat',
				'message'     => __( 'Another request for this conversation is already in progress. Please wait a moment.', 'pressark' ),
				'retry_after' => 3,
			), 429 );
		}

		$correlation_id = $this->begin_trace_context( $user_id, $chat_id );

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [2] Pre-flight write check ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$connector     = new PressArk_AI_Connector( $tier );
		$logger        = new PressArk_Action_Logger();
		$engine        = new PressArk_Action_Engine( $logger );
		$route_context = PressArk_Request_Context::from_array(
			array(
				'message'           => $execution_message,
				'original_message'  => $original_message,
				'conversation'      => $conversation,
				'tier'              => $tier,
				'deep_mode'         => $deep_mode,
				'screen'            => $screen,
				'post_id'           => $post_id,
				'continuation_mode' => $continuation_mode,
				'suppress_plan'     => $suppress_plan,
				'plan_execute'      => $plan_execute,
			)
		);
		$routing       = PressArk_Router::resolve_context( $route_context, $connector );
		$permission    = is_array( $routing['meta']['permission'] ?? null ) ? $routing['meta']['permission'] : array();
		$loaded_groups = $this->merge_routing_preload_groups( $loaded_groups, $routing );
		$is_plan_run = $this->is_plan_route( $routing );

		if ( ! $is_plan_run ) {
			$write_block = $this->quick_write_check( $tier, $execution_message, $tracker );
			if ( $write_block ) {
				return $write_block;
			}
		}

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [3] Throttle check ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$throttle  = new PressArk_Throttle();
		$plan_info = PressArk_Entitlements::get_plan_info( $tier );

		$throttle_result = $throttle->check( $user_id, $tier, $this->get_client_ip() );
		if ( is_wp_error( $throttle_result ) ) {
			$error_data = $throttle_result->get_error_data();
			return new WP_REST_Response( array(
				'error'       => 'rate_limit',
				'message'     => $throttle_result->get_error_message(),
				'retry_after' => $error_data['retry_after'] ?? 60,
			), $error_data['status'] ?? 429 );
		}

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [4] Estimate + reserve tokens ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		if ( ! $is_plan_run && ! $plan_execute && 'ask' === ( $permission['behavior'] ?? '' ) ) {
			return new WP_REST_Response(
				array_merge(
					PressArk_Pipeline::build_permission_response( $permission ),
					array(
						'usage'          => $tracker->get_usage_data(),
						'plan_info'      => $plan_info,
						'chat_id'        => $chat_id,
						'correlation_id' => $correlation_id,
					)
				),
				200
			);
		}

		if ( ! $is_plan_run && 'block' === ( $permission['behavior'] ?? '' ) ) {
			return new WP_REST_Response(
				array(
					'error'           => 'permission_blocked',
					'message'         => sanitize_text_field( (string) ( $permission['reason'] ?? __( 'You do not have permission to perform this action.', 'pressark' ) ) ),
					'permission'      => $permission,
					'permission_tool' => sanitize_key( (string) ( $permission['tool_name'] ?? '' ) ),
					'usage'           => $tracker->get_usage_data(),
					'plan_info'       => $plan_info,
					'chat_id'         => $chat_id,
					'correlation_id'  => $correlation_id,
				),
				403
			);
		}

		$reservation    = new PressArk_Reservation();
		$pipeline       = new PressArk_Pipeline( $reservation, $tracker, $throttle, $tier, $plan_info );
		$reserve_model  = $is_plan_run
			? PressArk_Model_Policy::for_phase( 'plan_mode', $tier, array( 'deep_mode' => $deep_mode ) )
			: PressArk_Model_Policy::resolve( $tier, $deep_mode );
		$estimated_raw  = $reservation->estimate_tokens( $execution_message, $conversation, $tier );
		$estimated      = $reservation->estimate_icus( $execution_message, $conversation, $tier, $reserve_model );
		$reserve_result = $is_plan_run
			? $pipeline->reserve_for_plan( $user_id, $estimated, $reserve_model, $estimated_raw )
			: $pipeline->reserve_full( $user_id, $estimated, (string) ( $routing['route'] ?? 'pending' ), $reserve_model, $estimated_raw );

		if ( ! $reserve_result['ok'] ) {
			if ( 'token_limit_reached' === ( $reserve_result['error'] ?? '' ) ) {
				$token_bank = new PressArk_Token_Bank();
				$status     = $token_bank->get_status();
				return new WP_REST_Response( array(
					'error'            => 'token_limit_reached',
					'message'          => PressArk_Entitlements::limit_message( 'token_budget', $tier ),
					'percent_used'     => $status['percent_used'] ?? 100,
					'upgrade_url'      => pressark_get_upgrade_url(),
					'credit_store_url' => pressark_get_upgrade_url(),
					'usage'            => $status,
					'plan_info'        => PressArk_Entitlements::get_plan_info( $tier ),
				), 429 );
			}
			return new WP_REST_Response( array(
				'error'   => 'reservation_failed',
				'message' => $reserve_result['error'] ?? 'Failed to reserve tokens.',
			), 500 );
		}

		$reservation_id = $reserve_result['reservation_id'];
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => $correlation_id,
				'reservation_id' => $reservation_id,
				'route'          => (string) ( $routing['route'] ?? '' ),
			)
		);

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [5] Unified routing ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		// [5a] Async early return.
		if ( PressArk_Router::ROUTE_ASYNC === $routing['route'] ) {
			$queue            = new PressArk_Task_Queue();
			$handoff_capsule  = PressArk_Task_Queue::build_handoff_capsule(
				$execution_message,
				$conversation,
				$loaded_groups,
				$checkpoint_data ?: null,
				array(
					'screen'  => $screen,
					'post_id' => $post_id,
					'user_id' => $user_id,
					'chat_id' => $chat_id,
				),
				'chat_async'
			);
			$run_store        = new PressArk_Run_Store();
			$lineage          = $run_store->create_background_family(
				'async',
				array(
					'user_id'         => $user_id,
					'chat_id'         => $chat_id,
					'message'         => $execution_message,
					'reservation_id'  => $reservation_id,
					'correlation_id'  => $correlation_id,
					'tier'            => $tier,
					'handoff_capsule' => $handoff_capsule,
				)
			);
			$parent_run_id    = (string) ( $lineage['parent_run_id'] ?? '' );
			$run_id           = (string) ( $lineage['run_id'] ?? '' );

			PressArk_Activity_Trace::set_current_context(
				array(
					'correlation_id' => $correlation_id,
					'run_id'         => $run_id,
					'reservation_id' => $reservation_id,
					'route'          => 'async',
				)
			);

			$queued = $queue->enqueue(
				$execution_message,
				$conversation,
				array(),
				$user_id,
				$deep_mode,
				$reservation_id,
				$loaded_groups,
				$checkpoint_data ?: null,
				$run_id,
				array(
					'screen'  => $screen,
					'post_id' => $post_id,
					'user_id' => $user_id,
					'chat_id' => $chat_id,
				),
				'',
				array(
					'parent_run_id'   => $parent_run_id,
					'root_run_id'     => (string) ( $lineage['root_run_id'] ?? $run_id ),
					'handoff_capsule' => $handoff_capsule,
				)
			);

			if ( 'error' === ( $queued['type'] ?? '' ) ) {
				$reservation->fail( $reservation_id, $queued['message'] ?? 'Async task queueing failed.' );
				PressArk_Pipeline::fail_run( $parent_run_id, $queued['message'] ?? 'Async task queueing failed.' );
				PressArk_Pipeline::fail_run( $run_id, $queued['message'] ?? 'Async task queueing failed.' );

				return new WP_REST_Response( array_merge( $queued, array(
					'usage'     => $tracker->get_usage_data(),
					'plan_info' => $plan_info,
					'run_id'    => $run_id,
					'parent_run_id' => $parent_run_id,
					'correlation_id' => $correlation_id,
					'chat_id'   => $chat_id,
				) ), 500 );
			}

			if ( ! empty( $queued['reused_existing'] ) && ! empty( $queued['run_id'] ) && (string) $queued['run_id'] !== $run_id ) {
				PressArk_Pipeline::fail_run( $run_id, 'Background handoff reused an existing queued task.' );
				$run_id = (string) $queued['run_id'];
			}

			PressArk_Pipeline::settle_run(
				$parent_run_id,
				array(
					'type'           => 'handoff',
					'message'        => ! empty( $queued['reused_existing'] )
						? 'Background handoff reused an existing queued task.'
						: 'Background handoff accepted and linked to a worker run.',
					'task_id'        => (string) ( $queued['task_id'] ?? '' ),
					'child_run_id'   => $run_id,
					'root_run_id'    => (string) ( $queued['root_run_id'] ?? ( $lineage['root_run_id'] ?? $run_id ) ),
					'handoff_capsule' => $handoff_capsule,
				)
			);

			return new WP_REST_Response( array_merge( $queued, array(
				'usage'     => $tracker->get_usage_data(),
				'plan_info' => $plan_info,
				'run_id'    => $run_id,
				'parent_run_id' => $parent_run_id,
				'correlation_id' => $correlation_id,
				'chat_id'   => $chat_id,
			) ) );
		}

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [6] Acquire concurrency slot ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$slot_id = $throttle->acquire_slot( $user_id, $tier );
		if ( ! $slot_id ) {
			$reservation->fail( $reservation_id, 'Concurrency limit reached' );
			return new WP_REST_Response( array(
				'error'       => 'concurrent_limit',
				'message'     => __( 'You already have a request in progress. Please wait for it to complete.', 'pressark' ),
				'retry_after' => 10,
			), 429 );
		}

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Build pipeline + run record ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$pipeline->register_resources( $reservation_id, $user_id, true, $slot_id );

		$stored_message = $this->resolve_run_storage_message(
			$is_plan_run,
			$original_message,
			$execution_message,
			$conversation,
			$checkpoint_data,
			$plan_execute
		);
		$run_store = new PressArk_Run_Store();
		$run_id    = $run_store->create( array(
			'user_id'        => $user_id,
			'chat_id'        => $chat_id,
			'route'          => $routing['route'],
			'message'        => $stored_message,
			'reservation_id' => $reservation_id,
			'correlation_id' => $correlation_id,
			'tier'           => $tier,
		) );
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => $correlation_id,
				'run_id'         => $run_id,
				'reservation_id' => $reservation_id,
				'route'          => (string) ( $routing['route'] ?? '' ),
			)
		);

		return array(
			'message'         => $execution_message,
			'original_message' => $original_message,
			'execution_message' => $execution_message,
			'conversation'    => $conversation,
			'screen'          => $screen,
			'post_id'         => $post_id,
			'continuation_mode' => $continuation_mode,
			'deep_mode'       => $deep_mode,
			'loaded_groups'   => $loaded_groups,
			'checkpoint_data' => $checkpoint_data,
			'chat_id'         => $chat_id,
			'tier'            => $tier,
			'plan_info'       => $plan_info,
			'reservation'     => $reservation,
			'reservation_id'  => $reservation_id,
			'connector'       => $connector,
			'engine'          => $engine,
			'routing'         => $routing,
			'slot_id'         => $slot_id,
			'pipeline'        => $pipeline,
			'run_id'          => $run_id,
			'correlation_id'  => $correlation_id,
			'tracker'         => $tracker,
			'user_id'         => $user_id,
		);
	}

	/**
	 * Handle SSE streaming chat requests.
	 *
	 * @since 4.4.0
	 */
	public function handle_chat_stream( WP_REST_Request $request ): void {
		$this->log_plan_invoke( 'chat_stream', $request );
		$compiled_request = $this->request_compiler->compile_rest_request( $request );
		if ( $compiled_request instanceof WP_REST_Response ) {
			$status = $compiled_request->get_status();
			$data   = $compiled_request->get_data();

			if ( 200 === $status ) {
				$emitter = new PressArk_SSE_Emitter();
				$emitter->start();
				$emitter->emit(
					'done',
					is_array( $data )
						? $data
						: array( 'type' => 'final_response', 'message' => (string) $data )
				);
				$emitter->close();
				return;
			}

			wp_send_json( $data, $status );
			return;
		}

		$preflight = $this->preflight( $compiled_request );

		if ( $preflight instanceof WP_REST_Response ) {
			// Preflight failed ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â send as JSON (headers not committed yet).
			$status = $preflight->get_status();
			$data   = $preflight->get_data();
			$this->release_chat_lock();

			if ( 200 === $status ) {
				$emitter = new PressArk_SSE_Emitter();
				$emitter->start();
				$emitter->emit(
					'done',
					is_array( $data )
						? $data
						: array( 'type' => 'final_response', 'message' => (string) $data )
				);
				$emitter->close();
				return;
			}

			wp_send_json( $data, $status );
			return;
		}

		$emitter = new PressArk_SSE_Emitter();
		$emitter->start();

		try {
			$this->process_chat_stream( $preflight, $emitter );
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Stream request failed', array( 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ) );
			$emitter->emit(
				'error',
				array(
					'message'        => __( 'PressArk hit an internal error before finishing this response. The reply above may be incomplete. Please try again.', 'pressark' ),
					'run_id'         => (string) ( $preflight['run_id'] ?? '' ),
					'correlation_id' => (string) ( $preflight['correlation_id'] ?? '' ),
					'code'           => 'stream_internal_error',
					'retryable'      => true,
				)
			);
			PressArk_Pipeline::fail_run( $preflight['run_id'], $e->getMessage() );
			$preflight['pipeline']->cleanup( $e->getMessage() );
		} finally {
			$emitter->close();

			// Safety net: if the client disconnected and the run was not
			// transitioned by process_chat_stream (e.g. exception path),
			// ensure the run is not stuck in 'running'.  Transition guards
			// in fail() make this idempotent ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â already-settled/failed runs
			// are silently ignored.
			if ( connection_aborted() ) {
				PressArk_Pipeline::fail_run( $preflight['run_id'], 'Client disconnected' );
			}

			$this->release_chat_lock();

			// Terminate immediately so the REST API framework does not
			// append its own JSON envelope (e.g. "null") to the SSE stream.
			// Some hosting environments treat the extra output as a protocol
			// violation and reset the connection, causing "Couldn't reach
			// PressArk" errors on the client side.
			exit;
		}
	}

	/**
	 * Build a throttled cancellation probe for a durable run.
	 *
	 * Docker/proxy/debug setups do not always surface browser disconnects
	 * promptly, so long-lived requests must also consult server-owned run
	 * state after an explicit /cancel request arrives.
	 *
	 * @param PressArk_Run_Store $run_store Run store instance.
	 * @param string             $run_id    Run ID to watch.
	 * @return callable
	 */
	private function build_run_cancel_check( PressArk_Run_Store $run_store, string $run_id ): callable {
		$last_checked_at = 0.0;
		$is_cancelled    = false;

		return static function () use ( $run_store, $run_id, &$last_checked_at, &$is_cancelled ): bool {
			$now = microtime( true );

			if ( ( $now - $last_checked_at ) >= 0.25 ) {
				$is_cancelled    = $run_store->is_cancelled( $run_id );
				$last_checked_at = $now;
			}

			return $is_cancelled;
		};
	}

	/**
	 * Normalize an execution result into a cancelled terminal payload.
	 *
	 * Preserves telemetry already collected while stripping approval-boundary
	 * fields so cancelled runs cannot reappear as pending confirmation.
	 *
	 * @param array $result Partial or full execution result.
	 * @return array
	 */
	private function mark_result_cancelled( array $result, string $status = 'cancelled', array $outcome_overrides = array() ): array {
		unset(
			$result['pending_actions'],
			$result['preview_session_id'],
			$result['preview_url'],
			$result['diff'],
			$result['workflow_state']
		);

		$message            = sanitize_text_field( (string) ( $outcome_overrides['message'] ?? $result['message'] ?? '' ) );
		$result['success']  = false;
		$result['is_error'] = true;
		$result['type']     = 'final_response';
		$result['message']  = $message;

		$result = $this->attach_approval_outcome(
			$result,
			$status,
			array_merge(
				array(
					'message' => $message,
					'source'  => 'chat',
				),
				$outcome_overrides
			)
		);

		return $result;
	}

	/**
	 * Stage 3 compatibility bridge:
	 * prefer typed plan-state hydration over direct checkpoint blob peeks.
	 */
	private function extract_plan_artifact_from_checkpoint_data( array $checkpoint_data ): array {
		if ( class_exists( 'PressArk_Plan_State_Store' ) ) {
			return PressArk_Plan_State_Store::from_checkpoint_array( $checkpoint_data )->get_plan_artifact();
		}

		return is_array( $checkpoint_data['plan_state']['current_artifact'] ?? null )
			? (array) $checkpoint_data['plan_state']['current_artifact']
			: array();
	}

	/**
	 * Process a streaming chat request.
	 *
	 * Uses SSE to stream tokens and step events in real-time.
	 * Falls back to non-streaming for legacy routes.
	 *
	 * @since 4.4.0
	 */
	private function process_chat_stream( array $ctx, PressArk_SSE_Emitter $emitter ): void {
		$routing   = $ctx['routing'];
		$run_store = new PressArk_Run_Store();
		$run_cancel_check = $this->build_run_cancel_check( $run_store, $ctx['run_id'] );
		$is_plan_run = $this->is_plan_route( $routing );

		// Emit run metadata immediately so the frontend can cancel by run_id
		// even on the first message (before it knows the chat_id).
		$emitter->emit( 'run_started', array(
			'run_id'         => $ctx['run_id'],
			'correlation_id' => $ctx['correlation_id'] ?? '',
			'chat_id'        => $ctx['chat_id'],
		) );

		try {
			if ( ! $this->is_legacy_route( $routing ) ) {
				// Compatibility only: the new route arbiter no longer emits
				// legacy, but keep the fallback for older injected route payloads.
				// Agent path ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â full streaming support.
				$stream_connector = new PressArk_Stream_Connector( $ctx['connector'], $emitter, $run_cancel_check );
				$agent = new PressArk_Agent( $ctx['connector'], $ctx['engine'], $ctx['tier'] );
				$agent->set_run_context( $ctx['run_id'], (int) $ctx['chat_id'] );
				$agent->set_mode( $is_plan_run ? 'plan' : 'execute' );
				$agent->set_planning_context(
					array(
						'planning_mode'     => (string) ( $routing['meta']['planning_mode'] ?? ( $is_plan_run ? 'hard_plan' : 'none' ) ),
						'planning_decision' => is_array( $routing['meta']['planning_decision'] ?? null ) ? (array) $routing['meta']['planning_decision'] : array(),
						'max_discover_calls' => (int) ( $routing['meta']['max_discover_calls'] ?? PressArk_Agent::MAX_DISCOVER_CALLS ),
						'plan_artifact'     => $this->extract_plan_artifact_from_checkpoint_data(
							is_array( $ctx['checkpoint_data'] ?? null ) ? $ctx['checkpoint_data'] : array()
						),
					)
				);

				if ( $is_plan_run ) {
					$this->ensure_plan_mode_loaded();
					PressArk_Plan_Mode::enter(
						(string) $ctx['run_id'],
						(string) ( $ctx['original_message'] ?? $ctx['message'] ),
						array(
							'conversation'   => is_array( $ctx['conversation'] ?? null ) ? $ctx['conversation'] : array(),
							'screen'         => sanitize_text_field( (string) ( $ctx['screen'] ?? '' ) ),
							'post_id'        => (int) ( $ctx['post_id'] ?? 0 ),
							'deep_mode'      => ! empty( $ctx['deep_mode'] ),
							'loaded_groups'  => is_array( $ctx['loaded_groups'] ?? null ) ? $ctx['loaded_groups'] : array(),
							'checkpoint'     => is_array( $ctx['checkpoint_data'] ?? null ) ? $ctx['checkpoint_data'] : array(),
							'chat_id'        => (int) ( $ctx['chat_id'] ?? 0 ),
							'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
							'correlation_id' => (string) ( $ctx['correlation_id'] ?? '' ),
							'permission'     => is_array( $routing['meta']['permission'] ?? null ) ? $routing['meta']['permission'] : array(),
							'approval_level' => 'hard',
							'planning_mode'  => (string) ( $routing['meta']['planning_mode'] ?? 'hard_plan' ),
							'policy'         => is_array( $routing['meta']['planning_decision'] ?? null ) ? (array) $routing['meta']['planning_decision'] : array(),
							'phase'          => 'planning',
						)
					);
				}

				$result = $agent->run_streaming(
					$ctx['message'],
					$ctx['conversation'],
					$ctx['deep_mode'],
					$ctx['screen'],
					$ctx['post_id'],
					$ctx['loaded_groups'],
					$ctx['checkpoint_data'],
					$stream_connector,
					$emitter,
					static function () use ( $emitter, $run_cancel_check ): bool {
						if ( ! $emitter->check_connection() ) {
							return true;
						}

						return $run_cancel_check();
					}
				);
			} else {
				// Legacy path ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â no streaming, emit full result at end.
				$result = $this->run_legacy_raw(
					$ctx['message'],
					$ctx['conversation'],
					$ctx['deep_mode'],
					$ctx['post_id'],
					$ctx['screen'],
					$ctx['connector']
				);
			}

			if ( ! empty( $result['cancelled'] ) || $run_cancel_check() ) {
				$result = $this->mark_result_cancelled(
					$result,
					'cancelled',
					array(
						'actor'       => 'user',
						'scope'       => 'run',
						'reason_code' => 'user_cancelled',
					)
				);
			}

			// Handle confirm_card pending actions.
			if ( 'confirm_card' === ( $result['type'] ?? '' ) && ! empty( $result['pending_actions'] ) ) {
				$result['pending_actions'] = $ctx['pipeline']->build_pending_actions(
					$result['pending_actions'],
					array( $this, 'generate_preview' )
				);
			}

			$result['run_id'] = $ctx['run_id'];
			$result['correlation_id'] = $ctx['correlation_id'] ?? '';

			if ( empty( $result['cancelled'] ) && 'plan_ready' === ( $result['type'] ?? '' ) ) {
				$result = $this->decorate_plan_ready_result( $result, $ctx );
			} elseif ( $is_plan_run ) {
				PressArk_Plan_Mode::abort( (string) $ctx['run_id'] );
			}

			// Persist run state on approval boundaries.
			$result_type = $result['type'] ?? 'final_response';
			// PATRACE
			pressark_trace(
				'STREAM_RESULT_TYPE',
				array(
					'run_id'             => sanitize_text_field( (string) ( $ctx['run_id'] ?? '' ) ),
					'chat_id'            => (int) ( $ctx['chat_id'] ?? 0 ),
					'result_type'        => sanitize_key( (string) $result_type ),
					'preview_session_id' => sanitize_text_field( (string) ( $result['preview_session_id'] ?? '' ) ),
					'pending_actions'    => count( (array) ( $result['pending_actions'] ?? array() ) ),
				)
			);

			if ( 'preview' === $result_type ) {
				$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
				$run_store->pause_for_preview(
					$ctx['run_id'],
					$result['preview_session_id'] ?? '',
					$pause_state
				);
			} elseif ( 'confirm_card' === $result_type ) {
				$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
				$run_store->pause_for_confirm(
					$ctx['run_id'],
					$result['pending_actions'] ?? array(),
					$pause_state
				);
			}

			// Persist checkpoint.
			if ( $ctx['chat_id'] > 0 && ! empty( $result['checkpoint'] ) ) {
				$cp = PressArk_Checkpoint::from_array( $result['checkpoint'] );
				$this->maybe_complete_plan_execution(
					array(
						'run_id'         => (string) ( $ctx['run_id'] ?? '' ),
						'correlation_id' => (string) ( $ctx['correlation_id'] ?? '' ),
						'chat_id'        => (int) ( $ctx['chat_id'] ?? 0 ),
						'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
						'route'          => (string) ( $routing['route'] ?? '' ),
					),
					$cp
				);
				$result['checkpoint'] = $cp->to_array();
				$cp->touch();
				$cp->save( $ctx['chat_id'], $ctx['user_id'] );
			}

			// Finalize (settle tokens, track, release slot).
			$finalized = $ctx['pipeline']->finalize( $result, $routing['route'], $is_plan_run ? 'plan' : 'execute' );
			$finalized_response_data = $finalized['response']->get_data();
			if ( is_array( $finalized_response_data ) ) {
				if ( ! empty( $finalized_response_data['budget'] ) ) {
					$result['budget'] = $finalized_response_data['budget'];
				}
				if ( ! empty( $finalized_response_data['run_surface'] ) ) {
					$result['run_surface'] = $finalized_response_data['run_surface'];
				}
				if ( ! empty( $finalized_response_data['activity_strip'] ) ) {
					$result['activity_strip'] = $finalized_response_data['activity_strip'];
				}
				$result['token_status'] = $finalized['token_status'] ?? array();
			}

			// Determine run outcome ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â cancelled beats settled.
			$user_cancelled = ! empty( $result['cancelled'] );
			// v5.8.12 (2026-05-16): broaden the connection_aborted exemption to
			// cover post-Keep wrap rounds. Symptom: panel shows the yellow
			// "PressArk lost the stream" warning at the end of a successful
			// Plan Mode chain — both pages 162/163 had landed in wp_posts, but
			// the wrap round's terminal `done` event was suppressed and the JS
			// finalizeStream() handler interpreted the clean byte-stream EOF as
			// a dropped connection. Root cause: under wp-now / php-wasm,
			// `connection_aborted()` can mis-report after `PHP.rotateRuntime`
			// hot-swaps the underlying WASM instance during a long SSE request
			// (recurring `ENOENT` from rotateRuntime in playground-server.err.log
			// confirms the runtime cycles mid-stream). plan_ready was already
			// exempt; the wrap round emits `final_response` and was not. Post-
			// Keep wrap rounds (1) carry only the model's summary text, not new
			// tool work, (2) follow a `[Continue]` envelope from the panel-side
			// auto-resume at pressark-chat.js:2421, and (3) the actual writes
			// landed in earlier rounds via /confirm-stream — losing the wrap
			// `done` to a false-positive disconnect detector trades a real
			// "stream lost" warning for nothing. The cancel_check inside the
			// agent loop already kills long-running rounds early if the panel
			// is genuinely gone, so this exemption only affects the final emit.
			$is_post_keep_wrap = is_string( $ctx['message'] ?? null )
				&& preg_match( '/^\s*\[Continue\]/', (string) $ctx['message'] ) === 1;
			$client_aborted = ! $user_cancelled
				&& connection_aborted()
				&& 'plan_ready' !== $result_type
				&& ! $is_post_keep_wrap;
			$cancelled      = $user_cancelled || $client_aborted;

			if ( $user_cancelled ) {
				if ( $is_plan_run ) {
					PressArk_Plan_Mode::abort( (string) $ctx['run_id'] );
				}
				$run_store->mark_cancelled( $ctx['run_id'] );
				$run_store->persist_detail_snapshot( $ctx['run_id'], null, $result );
			} elseif ( $client_aborted ) {
				if ( $is_plan_run ) {
					PressArk_Plan_Mode::abort( (string) $ctx['run_id'] );
				}
				$run_store->fail(
					$ctx['run_id'],
					'Client disconnected',
					$this->mark_result_cancelled(
						$result,
						'aborted',
						array(
							'actor'       => 'system',
							'scope'       => 'run',
							'source'      => 'stream',
							'reason_code' => 'client_disconnected',
							'message'     => __( 'The response ended before confirmation or completion could be recorded.', 'pressark' ),
						)
					)
				);
			} elseif ( ! empty( $result['is_error'] ) ) {
				if ( $is_plan_run ) {
					PressArk_Plan_Mode::abort( (string) $ctx['run_id'] );
				}
				$failure_message = sanitize_text_field(
					(string) ( $result['message'] ?? $result['reply'] ?? __( 'The assistant response failed before completion.', 'pressark' ) )
				);
				if ( '' === $failure_message ) {
					$failure_message = __( 'The assistant response failed before completion.', 'pressark' );
				}
				$run_store->fail( $ctx['run_id'], $failure_message, $result );
			} elseif ( ! in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
				$run_store->settle( $ctx['run_id'], $result );
			}

			// Build the final done payload (same shape as /chat JSON response).
			$response_data = $finalized['response']->get_data();
			$response_data['chat_id'] = $ctx['chat_id'];

			// Only emit if the client is still listening.
			if ( ! $cancelled ) {
				if ( 'plan_ready' === $result_type ) {
					$emitter->emit( 'plan', array(
						'type'             => 'plan',
						'status'           => 'ready',
						'steps'            => $result['plan_steps'] ?? array(),
						'run_id'           => $ctx['run_id'],
						'plan_markdown'    => $result['plan_markdown'] ?? '',
						'plan_phase'       => $result['plan_phase'] ?? '',
						'approval_level'   => $result['approval_level'] ?? '',
						'execute_endpoint' => $result['execute_endpoint'] ?? '',
						'approve_endpoint' => $result['approve_endpoint'] ?? '',
						'revise_endpoint'  => $result['revise_endpoint'] ?? '',
						'reject_endpoint'  => $result['reject_endpoint'] ?? '',
						'plan_artifact'    => $result['plan_artifact'] ?? array(),
						'reply'            => $result['reply'] ?? '',
					) );
				}
				// PATRACE
				pressark_trace(
					'STREAM_DONE_EMIT',
					array(
						'run_id'             => sanitize_text_field( (string) ( $ctx['run_id'] ?? '' ) ),
						'chat_id'            => (int) ( $ctx['chat_id'] ?? 0 ),
						'result_type'        => sanitize_key( (string) ( $response_data['type'] ?? 'final_response' ) ),
						'preview_session_id' => sanitize_text_field( (string) ( $response_data['preview_session_id'] ?? '' ) ),
						'pending_actions'    => count( (array) ( $response_data['pending_actions'] ?? array() ) ),
						'cancelled'          => ! empty( $response_data['cancelled'] ),
					)
				);
				$emitter->emit( 'done', $response_data );
			}

		} catch ( \Throwable $e ) {
			if ( $is_plan_run ) {
				PressArk_Plan_Mode::abort( (string) $ctx['run_id'] );
			}
			PressArk_Pipeline::fail_run( $ctx['run_id'], $e->getMessage() );
			$ctx['pipeline']->cleanup( $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Process chat message ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â delegates to preflight() then executes steps 7-12.
	 *
	 * v4.4.0: Refactored to share preflight with process_chat_stream().
	 */
	private function process_chat( WP_REST_Request $request ): WP_REST_Response {
		$ctx = $this->preflight( $request );

		// Preflight returned an early response (error, async, onboarding).
		if ( $ctx instanceof WP_REST_Response ) {
			return $ctx;
		}

		$routing   = $ctx['routing'];
		$run_id    = $ctx['run_id'];
		$chat_id   = $ctx['chat_id'];
		$pipeline  = $ctx['pipeline'];
		$run_store = new PressArk_Run_Store();
		$run_cancel_check = $this->build_run_cancel_check( $run_store, $run_id );
		$is_plan_run = $this->is_plan_route( $routing );

		try {
			$agent = new PressArk_Agent( $ctx['connector'], $ctx['engine'], $ctx['tier'] );
			$agent->set_run_context( $run_id, (int) $chat_id );
			$agent->set_mode( $is_plan_run ? 'plan' : 'execute' );
			$agent->set_planning_context(
				array(
					'planning_mode'     => (string) ( $routing['meta']['planning_mode'] ?? ( $is_plan_run ? 'hard_plan' : 'none' ) ),
					'planning_decision' => is_array( $routing['meta']['planning_decision'] ?? null ) ? (array) $routing['meta']['planning_decision'] : array(),
					'max_discover_calls' => (int) ( $routing['meta']['max_discover_calls'] ?? PressArk_Agent::MAX_DISCOVER_CALLS ),
					'plan_artifact'     => $this->extract_plan_artifact_from_checkpoint_data(
						is_array( $ctx['checkpoint_data'] ?? null ) ? $ctx['checkpoint_data'] : array()
					),
				)
			);

			if ( $is_plan_run ) {
				$this->ensure_plan_mode_loaded();
				PressArk_Plan_Mode::enter(
					$run_id,
					(string) ( $ctx['original_message'] ?? $ctx['message'] ),
					array(
						'conversation'   => is_array( $ctx['conversation'] ?? null ) ? $ctx['conversation'] : array(),
						'screen'         => sanitize_text_field( (string) ( $ctx['screen'] ?? '' ) ),
						'post_id'        => (int) ( $ctx['post_id'] ?? 0 ),
						'deep_mode'      => ! empty( $ctx['deep_mode'] ),
						'loaded_groups'  => is_array( $ctx['loaded_groups'] ?? null ) ? $ctx['loaded_groups'] : array(),
						'checkpoint'     => is_array( $ctx['checkpoint_data'] ?? null ) ? $ctx['checkpoint_data'] : array(),
						'chat_id'        => (int) $chat_id,
						'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
						'correlation_id' => (string) ( $ctx['correlation_id'] ?? '' ),
						'permission'     => is_array( $routing['meta']['permission'] ?? null ) ? $routing['meta']['permission'] : array(),
						'approval_level' => 'hard',
						'planning_mode'  => (string) ( $routing['meta']['planning_mode'] ?? 'hard_plan' ),
						'policy'         => is_array( $routing['meta']['planning_decision'] ?? null ) ? (array) $routing['meta']['planning_decision'] : array(),
						'phase'          => 'planning',
					)
				);
			}

			if ( $this->is_legacy_route( $routing ) ) {
				// Compatibility only: the new route arbiter no longer emits
				// legacy, but keep the branch for older injected route payloads.
				$result = $this->run_legacy_raw(
					$ctx['message'],
					$ctx['conversation'],
					$ctx['deep_mode'],
					$ctx['post_id'],
					$ctx['screen'],
					$ctx['connector']
				);
			} else {
				$result = $agent->run(
					$ctx['message'],
					$ctx['conversation'],
					$ctx['deep_mode'],
					$ctx['screen'],
					$ctx['post_id'],
					$ctx['loaded_groups'],
					$ctx['checkpoint_data'],
					$run_cancel_check
				);
			}

			if ( ! empty( $result['cancelled'] ) || $run_cancel_check() ) {
				$result = $this->mark_result_cancelled(
					$result,
					'cancelled',
					array(
						'actor'       => 'user',
						'scope'       => 'run',
						'reason_code' => 'user_cancelled',
					)
				);
			}

			// Handle confirm_card pending actions (any path may produce these).
			if ( 'confirm_card' === ( $result['type'] ?? '' ) && ! empty( $result['pending_actions'] ) ) {
				$result['pending_actions'] = $pipeline->build_pending_actions(
					$result['pending_actions'],
					array( $this, 'generate_preview' )
				);
			}

			// Attach run_id to result so pipeline passes it to the response.
			$result['run_id'] = $run_id;
			$result['correlation_id'] = $ctx['correlation_id'] ?? '';

			if ( empty( $result['cancelled'] ) && 'plan_ready' === ( $result['type'] ?? '' ) ) {
				$result = $this->decorate_plan_ready_result( $result, $ctx );
			} elseif ( $is_plan_run ) {
				PressArk_Plan_Mode::abort( $run_id );
			}

			// Persist run state on approval boundaries.
			$result_type = $result['type'] ?? 'final_response';

			if ( 'preview' === $result_type ) {
				$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
				$run_store->pause_for_preview(
					$run_id,
					$result['preview_session_id'] ?? '',
					$pause_state
				);
			} elseif ( 'confirm_card' === $result_type ) {
				$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
				$run_store->pause_for_confirm(
					$run_id,
					$result['pending_actions'] ?? array(),
					$pause_state
				);
			}

			// Persist checkpoint server-side.
			if ( $chat_id > 0 && ! empty( $result['checkpoint'] ) ) {
				$cp = PressArk_Checkpoint::from_array( $result['checkpoint'] );
				$this->maybe_complete_plan_execution(
					array(
						'run_id'         => (string) $run_id,
						'correlation_id' => (string) ( $ctx['correlation_id'] ?? '' ),
						'chat_id'        => (int) $chat_id,
						'user_id'        => (int) ( $ctx['user_id'] ?? 0 ),
						'route'          => (string) ( $routing['route'] ?? '' ),
					),
					$cp
				);
				$result['checkpoint'] = $cp->to_array();
				$cp->touch();
				$cp->save( $chat_id, $ctx['user_id'] );
			}

			// Finalize (settle tokens, track, release slot).
			$finalized = $pipeline->finalize( $result, $routing['route'], $is_plan_run ? 'plan' : 'execute' );
			$finalized_response_data = $finalized['response']->get_data();
			if ( is_array( $finalized_response_data ) ) {
				if ( ! empty( $finalized_response_data['budget'] ) ) {
					$result['budget'] = $finalized_response_data['budget'];
				}
				if ( ! empty( $finalized_response_data['run_surface'] ) ) {
					$result['run_surface'] = $finalized_response_data['run_surface'];
				}
				if ( ! empty( $finalized_response_data['activity_strip'] ) ) {
					$result['activity_strip'] = $finalized_response_data['activity_strip'];
				}
				$result['token_status'] = $finalized['token_status'] ?? array();
			}

			if ( ! empty( $result['cancelled'] ) ) {
				if ( $is_plan_run ) {
					PressArk_Plan_Mode::abort( $run_id );
				}
				$run_store->mark_cancelled( $run_id );
				$run_store->persist_detail_snapshot( $run_id, null, $result );
			} elseif ( ! empty( $result['is_error'] ) ) {
				if ( $is_plan_run ) {
					PressArk_Plan_Mode::abort( $run_id );
				}
				$failure_message = sanitize_text_field(
					(string) ( $result['message'] ?? $result['reply'] ?? __( 'The assistant response failed before completion.', 'pressark' ) )
				);
				if ( '' === $failure_message ) {
					$failure_message = __( 'The assistant response failed before completion.', 'pressark' );
				}
				$run_store->fail( $run_id, $failure_message, $result );
			} elseif ( ! in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
				$run_store->settle( $run_id, $result );
			}

			$response = $finalized['response'];
			$data     = $response->get_data();
			$data['chat_id'] = $chat_id;
			$response->set_data( $data );

			return $response;
		} catch ( \Throwable $e ) {
			if ( $is_plan_run ) {
				PressArk_Plan_Mode::abort( $run_id );
			}
			PressArk_Pipeline::fail_run( $run_id, $e->getMessage() );
			$pipeline->cleanup( $e->getMessage() );
			throw $e; // Re-throw for handle_chat() to catch.
		}
	}

	/**
	 * Legacy single-shot path for models without native tool calling.
	 *
	 * Returns a raw result array (settlement/response handled by pipeline).
	 *
	 * @since 3.0.0 Refactored from run_legacy() ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â no longer settles or builds WP_REST_Response.
	 */
	private function accumulate_context_metrics( array $totals, PressArk_AI_Connector $connector ): array {
		$metrics = $connector->get_last_context_metrics();
		if ( empty( $metrics['context_used'] ) ) {
			return $totals;
		}

		$totals['context_used'] = true;
		$totals['context_tokens'] = max( 0, (int) ( $totals['context_tokens'] ?? 0 ) ) + max( 0, (int) ( $metrics['context_tokens'] ?? 0 ) );
		$totals['user_context_tokens'] = max( 0, (int) ( $totals['user_context_tokens'] ?? 0 ) ) + max( 0, (int) ( $metrics['user_context_tokens'] ?? 0 ) );
		$totals['system_context_tokens'] = max( 0, (int) ( $totals['system_context_tokens'] ?? 0 ) ) + max( 0, (int) ( $metrics['system_context_tokens'] ?? 0 ) );

		return $totals;
	}

	private function append_context_metrics_to_result( array $result, array $metrics ): array {
		if ( empty( $metrics['context_used'] ) ) {
			return $result;
		}

		$result['context_used']          = true;
		$result['context_tokens']        = max( 0, (int) ( $metrics['context_tokens'] ?? 0 ) );
		$result['user_context_tokens']   = max( 0, (int) ( $metrics['user_context_tokens'] ?? 0 ) );
		$result['system_context_tokens'] = max( 0, (int) ( $metrics['system_context_tokens'] ?? 0 ) );

		return $result;
	}

	private function run_legacy_raw(
		string                $message,
		array                 $conversation,
		bool                  $deep_mode,
		int                   $post_id,
		string                $screen,
		PressArk_AI_Connector $connector
	): array {
		$task_type = PressArk_Agent::classify_task( $message, $conversation );
		$connector->resolve_for_task( $task_type, $deep_mode );
		$tracker            = new PressArk_Usage_Tracker();
		$effective_provider = $tracker->is_byok() ? $tracker->get_byok_provider() : $connector->get_provider();
		$effective_model    = $tracker->is_byok()
			? (string) get_option( 'pressark_byok_model', $connector->get_model() )
			: $connector->get_model();
		$context_totals     = array();

		if ( PressArk_Agent::is_lightweight_chat_request( $message, $conversation ) ) {
			$ai_result       = $connector->send_lightweight_chat( $message, $conversation, $deep_mode );
			$context_totals  = $this->accumulate_context_metrics( $context_totals, $connector );
			$usage_breakdown = $this->extract_usage_breakdown( $ai_result );
			$routing_decision = (array) ( $ai_result['routing_decision'] ?? $connector->get_last_routing_decision() );

			if ( ! empty( $ai_result['error'] ) ) {
				return $this->append_context_metrics_to_result( array(
					'type'              => 'final_response',
					'message'           => $ai_result['error'],
					'tokens_used'       => $usage_breakdown['total_tokens'],
					'input_tokens'      => $usage_breakdown['input_tokens'],
					'output_tokens'     => $usage_breakdown['output_tokens'],
					'cache_read_tokens' => $usage_breakdown['cache_read_tokens'],
					'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
					'provider'          => $effective_provider,
					'model'             => $effective_model,
					'task_type'         => 'chat',
					'usage'             => $ai_result['usage'] ?? array(),
					'actions_performed' => array(),
					'pending_actions'   => array(),
					'routing_decision'  => $routing_decision,
					'is_error'          => true,
				), $context_totals );
			}

			return $this->append_context_metrics_to_result( array(
				'type'              => 'final_response',
				'message'           => $ai_result['message'] ?? '',
				'tokens_used'       => $usage_breakdown['total_tokens'],
				'input_tokens'      => $usage_breakdown['input_tokens'],
				'output_tokens'     => $usage_breakdown['output_tokens'],
				'cache_read_tokens' => $usage_breakdown['cache_read_tokens'],
				'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
				'provider'          => $effective_provider,
				'model'             => $effective_model,
				'task_type'         => 'chat',
				'usage'             => $ai_result['usage'] ?? array(),
				'actions_performed' => array(),
				'pending_actions'   => array(),
				'routing_decision'  => $routing_decision,
			), $context_totals );
		}

		// Build compact context ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â dynamic part only (cached part in connector).
		$context      = new PressArk_Context();
		$context_text = $context->build( $screen, $post_id );
		$context_text .= PressArk_Handler_Discovery::format_site_notes_basic();
		if ( class_exists( 'PressArk_Site_Playbook' ) ) {
			$context_text .= (string) ( PressArk_Site_Playbook::resolve_prompt_context( $task_type, array(), $message )['text'] ?? '' );
		}

		// v3.3.0: Inject retrieval-grounded content from the content index.
		$content_index     = new PressArk_Content_Index();
		$retrieval_context = $content_index->get_relevant_context( $message );
		if ( ! empty( $retrieval_context ) ) {
			$context_text .= "\n\n" . $retrieval_context;
		}

		$compressed_history = PressArk_History_Manager::prepare( $conversation, $deep_mode );

		$ai_result       = $connector->send_message( $message, $context_text, $compressed_history, $deep_mode );
		$context_totals  = $this->accumulate_context_metrics( $context_totals, $connector );
		$usage_breakdown = $this->extract_usage_breakdown( $ai_result );
		$routing_decision = (array) ( $ai_result['routing_decision'] ?? $connector->get_last_routing_decision() );

		if ( ! empty( $ai_result['error'] ) ) {
			return $this->append_context_metrics_to_result( array(
				'type'              => 'final_response',
				'message'           => $ai_result['error'],
				'tokens_used'       => $usage_breakdown['total_tokens'],
				'input_tokens'      => $usage_breakdown['input_tokens'],
				'output_tokens'     => $usage_breakdown['output_tokens'],
				'cache_read_tokens' => $usage_breakdown['cache_read_tokens'],
				'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
				'provider'          => $effective_provider,
				'model'             => $effective_model,
				'task_type'         => $task_type,
				'usage'             => $ai_result['usage'] ?? array(),
				'actions_performed' => array(),
				'pending_actions'   => array(),
				'routing_decision'  => $routing_decision,
				'is_error'          => true,
			), $context_totals );
		}

		// If AI returned parsed actions, separate reads from writes.
		$pending          = array();
		$performed        = array();
		$preview_actions  = array();
		$read_supplements = '';
		$scanner_types    = array();
		$invalid_action_message = '';

		if ( ! empty( $ai_result['actions'] ) && is_array( $ai_result['actions'] ) ) {
			$logger_legacy = new PressArk_Action_Logger();
			$engine_legacy = new PressArk_Action_Engine( $logger_legacy );

			// Normalize hallucinated tool names.
			static $tool_aliases = array(
				'get_posts'      => 'list_posts',
				'get_pages'      => 'list_posts',
				'get_products'   => 'list_posts',
				'list_products'  => 'list_posts',
				'get_content'    => 'read_content',
				'get_post'       => 'read_content',
				'search_posts'   => 'search_content',
				'find_content'   => 'search_content',
				'get_plugins'    => 'list_plugins',
				'get_themes'     => 'list_themes',
				'get_comments'   => 'list_comments',
				'get_orders'     => 'list_orders',
				'get_customers'  => 'list_customers',
				'seo_analysis'   => 'analyze_seo',
				'security_scan'  => 'scan_security',
			);

			foreach ( $ai_result['actions'] as $action ) {
				$action_type = $action['type'] ?? '';
				$action_type = $tool_aliases[ $action_type ] ?? $action_type;
				$action_type = class_exists( 'PressArk_Operation_Registry' )
					? PressArk_Operation_Registry::resolve_alias( (string) $action_type )
					: (string) $action_type;
				$action_params = is_array( $action['params'] ?? null ) ? $action['params'] : array();

				if ( class_exists( 'PressArk_Operation_Registry' ) && '' !== $action_type ) {
					$validation = PressArk_Operation_Registry::validate_input( $action_type, $action_params );
					if ( ! ( $validation['valid'] ?? true ) ) {
						$invalid_action_message = sanitize_text_field(
							(string) (
								$validation['message']
								?? __( 'One of the generated actions had invalid input and could not be prepared.', 'pressark' )
							)
						);
						break;
					}

					if ( isset( $validation['params'] ) && is_array( $validation['params'] ) ) {
						$action_params = $validation['params'];
					}
				}

				$action_class = PressArk_Agent::classify_tool( $action_type, $action_params );

				if ( 'read' === $action_class ) {
					$read_result = $engine_legacy->execute_read( $action_type, $action_params );

					$labels = array(
						'analyze_seo'    => 'SEO analysis complete',
						'scan_security'  => 'Security scan complete',
						'read_content'   => 'Content retrieved',
						'list_posts'     => 'Posts fetched',
						'search_content' => 'Search complete',
						'analyze_store'  => 'Store analysis complete',
					);
					$label = $labels[ $action_type ] ?? ucwords( str_replace( '_', ' ', $action_type ) ) . ' complete';

					$performed[] = array(
						'success' => true,
						'message' => $label,
						'action'  => $action,
						'data'    => $read_result,
					);

					$read_supplements .= "\n\n" . wp_json_encode( $read_result );

					if ( 'analyze_seo' === $action_type ) {
						$scanner_types[] = 'SEO';
					} elseif ( 'scan_security' === $action_type ) {
						$scanner_types[] = 'security';
					} elseif ( 'analyze_store' === $action_type ) {
						$scanner_types[] = 'store';
					}
				} elseif ( 'preview' === $action_class ) {
					$preview_actions[] = array(
						'name'      => $action_type,
						'arguments' => $action_params,
					);
				} else {
					// Write actions ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â pending_actions raw format for pipeline.
					$pending[] = array(
						'name'      => $action_type,
						'arguments' => $action_params,
					);
				}
			}
		}

		$reply_text = $ai_result['message'] ?? '';

		// When legacy execution gathered read results but the first pass produced
		// no user-facing reply, run a lightweight follow-up synthesis step.
		if ( ! empty( $read_supplements ) ) {
			$followup_result = array();

			if ( ! empty( $scanner_types ) ) {
				$scan_label      = implode( ' and ', array_unique( $scanner_types ) );
				$followup_result = $connector->send_scanner_followup(
					trim( $read_supplements ),
					$scan_label,
					$compressed_history
				);
				$context_totals = $this->accumulate_context_metrics( $context_totals, $connector );
			}

			if ( '' === trim( $reply_text ) && ( empty( $followup_result['message'] ) || ! empty( $followup_result['error'] ) ) ) {
				$followup_result = $connector->send_read_followup(
					$message,
					trim( $read_supplements ),
					$compressed_history
				);
				$context_totals = $this->accumulate_context_metrics( $context_totals, $connector );
			}

			if ( empty( $followup_result['error'] ) && ! empty( $followup_result['message'] ) ) {
				$reply_text         = $followup_result['message'];
				$followup_breakdown = $this->extract_usage_breakdown( $followup_result );
				$usage_breakdown['total_tokens']      += $followup_breakdown['total_tokens'];
				$usage_breakdown['input_tokens']      += $followup_breakdown['input_tokens'];
				$usage_breakdown['output_tokens']     += $followup_breakdown['output_tokens'];
				$usage_breakdown['cache_read_tokens'] += $followup_breakdown['cache_read_tokens'];
				$usage_breakdown['cache_write_tokens']  += $followup_breakdown['cache_write_tokens'];
			}
		}

		// Previewable writes ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ live preview session.
		if ( '' !== $invalid_action_message ) {
			return $this->append_context_metrics_to_result( array(
				'type'               => 'final_response',
				'message'            => $invalid_action_message,
				'tokens_used'        => $usage_breakdown['total_tokens'],
				'input_tokens'       => $usage_breakdown['input_tokens'],
				'output_tokens'      => $usage_breakdown['output_tokens'],
				'cache_read_tokens'  => $usage_breakdown['cache_read_tokens'],
				'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
				'provider'           => $effective_provider,
				'model'              => $effective_model,
				'task_type'          => $task_type,
				'usage'              => $ai_result['usage'] ?? array(),
				'actions_performed'  => $performed,
				'pending_actions'    => array(),
				'routing_decision'   => $routing_decision,
				'is_error'           => true,
			), $context_totals );
		}

		if ( ! empty( $preview_actions ) ) {
			$preview = new PressArk_Preview();
			$session = $preview->create_session( $preview_actions, $preview_actions[0]['arguments'] ?? array() );
			if ( empty( $session['success'] ) && isset( $session['message'] ) ) {
				return $this->append_context_metrics_to_result( array(
					'type'               => 'final_response',
					'message'            => sanitize_text_field( (string) $session['message'] ),
					'tokens_used'        => $usage_breakdown['total_tokens'],
					'input_tokens'       => $usage_breakdown['input_tokens'],
					'output_tokens'      => $usage_breakdown['output_tokens'],
					'cache_read_tokens'  => $usage_breakdown['cache_read_tokens'],
					'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
					'provider'           => $effective_provider,
					'model'              => $effective_model,
					'task_type'          => $task_type,
					'usage'              => $ai_result['usage'] ?? array(),
					'actions_performed'  => $performed,
					'pending_actions'    => array(),
					'routing_decision'   => $routing_decision,
					'is_error'           => true,
				), $context_totals );
			}
			$preview_actions = $preview->get_session_tool_calls( $session['session_id'] );

			return $this->append_context_metrics_to_result( array(
				'type'               => 'preview',
				'message'            => $reply_text,
				'tokens_used'        => $usage_breakdown['total_tokens'],
				'input_tokens'       => $usage_breakdown['input_tokens'],
				'output_tokens'      => $usage_breakdown['output_tokens'],
				'cache_read_tokens'  => $usage_breakdown['cache_read_tokens'],
				'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
				'provider'           => $effective_provider,
				'model'              => $effective_model,
				'task_type'          => $task_type,
				'usage'              => $ai_result['usage'] ?? array(),
				'actions_performed'  => $performed,
				'pending_actions'    => array(),
				'routing_decision'   => $routing_decision,
				'preview_actions'    => $preview_actions,
				'preview_session_id' => $session['session_id'],
				'preview_url'        => $session['signed_url'],
				'diff'               => $session['diff'],
			), $context_totals );
		}

		$response_type = ! empty( $pending ) ? 'confirm_card' : 'final_response';

		return $this->append_context_metrics_to_result( array(
			'type'              => $response_type,
			'message'           => $reply_text,
			'tokens_used'       => $usage_breakdown['total_tokens'],
			'input_tokens'      => $usage_breakdown['input_tokens'],
			'output_tokens'     => $usage_breakdown['output_tokens'],
			'cache_read_tokens' => $usage_breakdown['cache_read_tokens'],
			'cache_write_tokens' => $usage_breakdown['cache_write_tokens'],
			'provider'          => $effective_provider,
			'model'             => $effective_model,
			'task_type'         => $task_type,
			'usage'             => $ai_result['usage'] ?? array(),
			'actions_performed' => $performed,
			'pending_actions'   => $pending,
			'routing_decision'  => $routing_decision,
		), $context_totals );
	}

	/**
	 * Handle action confirmation (approve or cancel).
	 *
	 * v3.1.0: Run-aware. If run_id is provided, looks up the originating run,
	 * restores the persisted pause snapshot, and executes the post-apply verify phase.
	 * Falls back to legacy behavior if run_id is empty (backward compat).
	 *
	 * v3.7.2: Server-authoritative action loading. When run_id is provided,
	 * the action is loaded from the run's persisted pending_actions instead
	 * of trusting client-supplied action_data. This prevents a malicious
	 * client from executing arbitrary actions the AI never proposed.
	 */
	public function handle_confirm_stream( WP_REST_Request $request ): void {
		$confirmed    = (bool) $request->get_param( 'confirmed' );
		$run_id       = sanitize_text_field( (string) $request->get_param( 'run_id' ) );
		$action_index = (int) $request->get_param( 'action_index' );

		if ( ! $confirmed ) {
			$response = $this->handle_confirm( $request );
			wp_send_json( $response->get_data(), $response->get_status() );
			return;
		}

		$tracker = new PressArk_Usage_Tracker();
		$license = new PressArk_License();
		$tier    = $license->get_tier();

		if ( empty( $run_id ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'A run_id is required to confirm actions. Please refresh the page and try again.', 'pressark' ),
				),
				400
			);
			return;
		}

		if ( ! PressArk_Entitlements::can_write( $tier ) ) {
			wp_send_json(
				array(
					'success'        => false,
					'message'        => PressArk_Entitlements::limit_message( 'write_limit', $tier ),
					'upgrade_prompt' => false,
					'plan_info'      => PressArk_Entitlements::get_plan_info( $tier ),
				),
				403
			);
			return;
		}

		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'Run not found. The confirmation may have expired.', 'pressark' ),
				),
				404
			);
			return;
		}

		if ( (int) $run['user_id'] !== get_current_user_id() ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'You do not own this run.', 'pressark' ),
				),
				403
			);
			return;
		}

		if ( ! in_array( $run['status'], array( 'awaiting_confirm', 'partially_confirmed' ), true ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: current run status */
						__( 'This run is in "%s" state and cannot be confirmed. Only runs awaiting confirmation can be approved.', 'pressark' ),
						$run['status']
					),
				),
				409
			);
			return;
		}

		$pending = $run['pending_actions'] ?? array();
		if ( ! isset( $pending[ $action_index ] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'Invalid action index. The requested action does not exist in this run.', 'pressark' ),
				),
				404
			);
			return;
		}
		if ( ! empty( $pending[ $action_index ]['resolved'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => __( 'This action has already been confirmed.', 'pressark' ),
				),
				409
			);
			return;
		}

		$action  = $pending[ $action_index ]['action'] ?? $pending[ $action_index ];
		$emitter = new PressArk_SSE_Emitter();
		$emitter->start();

		try {
			$this->hydrate_trace_context_from_run( $run );

			if ( $emitter->is_connected() ) {
				$emitter->emit(
					'run_started',
					array(
						'run_id'         => (string) ( $run['run_id'] ?? $run_id ),
						'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
					)
				);
			}

			$result = $this->execute_confirmed_action( $run, $pending, $action, $action_index, $tracker, $tier, $emitter );

			if ( $emitter->is_connected() ) {
				$emitter->emit( 'done', $result );
			}
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error(
				'Chat',
				'Confirm stream failed',
				array(
					'run_id' => $run_id,
					'error'  => $e->getMessage(),
				)
			);

			if ( $emitter->is_connected() ) {
				$emitter->emit(
					'done',
					array(
						'success' => false,
						'message' => __( 'The changes could not be applied due to an unexpected error. No modifications were made. Please try again.', 'pressark' ),
					)
				);
			}
		} finally {
			$emitter->close();
			exit;
		}
	}

	/**
	 * Handle action confirmation (approve or cancel).
	 *
	 * v3.1.0: Run-aware. If run_id is provided, looks up the originating run,
	 * restores the persisted pause snapshot, and executes the post-apply verify phase.
	 * Falls back to legacy behavior if run_id is empty (backward compat).
	 *
	 * v3.7.2: Server-authoritative action loading. When run_id is provided,
	 * the action is loaded from the run's persisted pending_actions instead
	 * of trusting client-supplied action_data. This prevents a malicious
	 * client from executing arbitrary actions the AI never proposed.
	 */
	public function handle_confirm( WP_REST_Request $request ): WP_REST_Response {
		try {
			$confirmed    = (bool) $request->get_param( 'confirmed' );
			$run_id       = $request->get_param( 'run_id' );
			$action_index = (int) $request->get_param( 'action_index' );
			$run          = null;

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Cancellation ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			if ( ! $confirmed ) {
				$declined_action = 'confirmed_action';
				$declined_result = $this->build_terminal_outcome_result(
					'declined',
					__( 'No changes were made. You declined the confirmation.', 'pressark' ),
					array(),
					array(
						'actor'       => 'user',
						'scope'       => 'confirm',
						'source'      => 'approval',
						'reason_code' => 'user_declined',
					)
				);

				if ( ! empty( $run_id ) ) {
					$run_store = new PressArk_Run_Store();
					$run       = $run_store->get( $run_id );
					if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
						$this->hydrate_trace_context_from_run( $run );
						$pending = $run['pending_actions'] ?? array();
						if ( isset( $pending[ $action_index ] ) ) {
							$pending_action   = $pending[ $action_index ]['action'] ?? $pending[ $action_index ];
							$declined_action = (string) ( $pending_action['type'] ?? 'confirmed_action' );
						}

						$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
						$this->remember_approval_outcome(
							$checkpoint,
							$declined_action,
							'declined',
							array(
								'scope'       => 'confirm',
								'source'      => 'approval',
								'actor'       => 'user',
								'reason_code' => 'user_declined',
							)
						);
						$checkpoint->clear_pending();
						$checkpoint->clear_plan_state();
						$this->replace_checkpoint_replay_tool_results(
							$checkpoint,
							$this->build_resolved_write_replay_tool_results(
								isset( $pending_action ) && is_array( $pending_action ) ? array( $pending_action ) : array(),
								array(
									'success'          => false,
									'message'          => __( 'No changes were made. You declined the confirmation.', 'pressark' ),
									'approval_outcome' => array(
										'recorded_at' => gmdate( 'c' ),
									),
								),
								'confirm_card',
								'declined'
							)
						);
						$this->persist_run_checkpoint( $run, $checkpoint );

						$declined_result = $this->build_terminal_outcome_result(
							'declined',
							__( 'No changes were made. You declined the confirmation.', 'pressark' ),
							array(
								'run_id'          => (string) ( $run['run_id'] ?? '' ),
								'checkpoint'     => $checkpoint->to_array(),
								'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
							),
							array(
								'action'      => $declined_action,
								'actor'       => 'user',
								'scope'       => 'confirm',
								'source'      => 'approval',
								'reason_code' => 'user_declined',
							)
						);
						$declined_result = $this->hydrate_approval_receipt(
							$declined_result,
							array(
								'run_status' => 'settled',
							)
						);

						$run_store->settle( $run_id, $declined_result );
						$this->persist_run_detail_snapshot( $run, $declined_result, $checkpoint );
					}
				}

				$declined_route  = is_array( $run ) ? (string) ( $run['route'] ?? 'agent' ) : 'agent';
				$declined_result = $this->hydrate_chat_surfaces( $declined_result, $declined_route );
				return rest_ensure_response( $declined_result );
			}

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v4.5.0: run_id is now mandatory ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			// Legacy path that accepted client-supplied action_data
			// without a durable run_id has been removed. A malicious
			// client could execute arbitrary actions the AI never
			// proposed. All confirm cards issued since v3.1.0 include
			// a run_id; any client still sending action_data without
			// run_id is unsupported.
			if ( empty( $run_id ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'A run_id is required to confirm actions. Please refresh the page and try again.', 'pressark' ),
				) );
			}

			$tracker = new PressArk_Usage_Tracker();
			$license = new PressArk_License();
			$tier    = $license->get_tier();

			// v3.5.0: Unified write-limit check via entitlements.
			if ( ! PressArk_Entitlements::can_write( $tier ) ) {
				return rest_ensure_response( array(
					'success'        => false,
					'message'        => PressArk_Entitlements::limit_message( 'write_limit', $tier ),
					'upgrade_prompt' => false,
					'plan_info'      => PressArk_Entitlements::get_plan_info( $tier ),
				) );
			}

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v3.7.2: Server-authoritative action loading ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			// Load the action from the run's persisted pending_actions.
			// Client-supplied action_data is never trusted.
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get( $run_id );

			if ( ! $run ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'Run not found. The confirmation may have expired.', 'pressark' ),
				) );
			}

			if ( (int) $run['user_id'] !== get_current_user_id() ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'You do not own this run.', 'pressark' ),
				) );
			}

			$this->hydrate_trace_context_from_run( $run );

			if ( ! in_array( $run['status'], array( 'awaiting_confirm', 'partially_confirmed' ), true ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: current run status */
						__( 'This run is in "%s" state and cannot be confirmed. Only runs awaiting confirmation can be approved.', 'pressark' ),
						$run['status']
					),
				) );
			}

			$pending = $run['pending_actions'] ?? array();
			if ( ! isset( $pending[ $action_index ] ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'Invalid action index. The requested action does not exist in this run.', 'pressark' ),
				) );
			}
			if ( ! empty( $pending[ $action_index ]['resolved'] ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'This action has already been confirmed.', 'pressark' ),
				) );
			}

			$action = $pending[ $action_index ]['action'] ?? $pending[ $action_index ];
			$result = $this->execute_confirmed_action( $run, $pending, $action, $action_index, $tracker, $tier );
			return rest_ensure_response( $result );
			$action_meta = is_array( $action['meta'] ?? null ) ? $action['meta'] : array();
			$permission_meta = is_array( $action_meta['permission_meta'] ?? null ) ? $action_meta['permission_meta'] : array();
			$permission_meta['run_id']       = sanitize_text_field( (string) $run_id );
			$permission_meta['action_index'] = $action_index;
			$permission_meta['source']       = 'confirm';
			$action_meta['approval_granted'] = true;
			$action_meta['permission_meta']  = $permission_meta;
			if ( empty( $action_meta['permission_context'] ) ) {
				$action_meta['permission_context'] = 'interactive';
			}
			$action['meta'] = $action_meta;
			$action_name    = (string) ( $action['type'] ?? 'confirmed_action' );

			// Execute the action.
			$logger = new PressArk_Action_Logger();
			$engine = new PressArk_Action_Engine( $logger );
			$result = $engine->execute_single( $action );
			$result = $this->attach_approval_outcome(
				$result,
				'approved',
				array(
					'action'      => $action_name,
					'actor'       => 'user',
					'scope'       => 'confirm',
					'source'      => 'approval',
					'reason_code' => 'user_confirmed',
				)
			);

			// Increment usage if successful.
			if ( ! empty( $result['success'] ) ) {
				$tracker->increment_if_write( $action['type'] ?? '' );
			}

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Settle durable run via pipeline authority ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			$all_resolved = false;

			if ( ! empty( $result['success'] ) ) {
				if ( ! is_array( $pending[ $action_index ] ) ) {
					$pending[ $action_index ] = array(
						'action' => $action,
					);
				}
				$pending[ $action_index ]['resolved'] = true;
				$all_resolved                          = true;

				foreach ( $pending as $pending_action ) {
					if ( empty( $pending_action['resolved'] ) ) {
						$all_resolved = false;
						break;
					}
				}

				if ( $all_resolved ) {
					$result        = PressArk_Pipeline::settle_run( $run_id, $result );
					$run['status'] = 'settled';
				} else {
					$run_store->update_pending( $run_id, $pending, 'partially_confirmed' );
					$run['pending_actions'] = $pending;
					$run['status']          = 'partially_confirmed';
					$result['run_id']       = $run_id;
					$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
				}
			} else {
				$result['run_id']         = $run_id;
				$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
			}

			$action_args = is_array( $action['params'] ?? null ) ? $action['params'] : array();
			if ( empty( $action_args ) ) {
				$action_args = $action;
				unset( $action_args['type'], $action_args['description'], $action_args['meta'] );
			}

			$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
			$this->remember_approval_outcome(
				$checkpoint,
				$action_name,
				'approved',
				array(
					'scope'       => 'confirm',
					'source'      => 'approval',
					'actor'       => 'user',
					'reason_code' => 'user_confirmed',
				)
			);
			$checkpoint->record_execution_write( (string) ( $action['type'] ?? '' ), $action_args, $result );
			if ( ! empty( $result['success'] ) ) {
				$checkpoint->clear_blockers();
				$checkpoint->add_approval( $action_name );
				if ( $all_resolved ) {
					$checkpoint->set_workflow_stage( 'settled' );
					$checkpoint->clear_plan_state();
				}

				// v5.4.0: Evidence-based verification for confirmed actions.
				if ( class_exists( 'PressArk_Verification' ) ) {
					$tool_name = (string) ( $action['type'] ?? '' );
					$policy    = PressArk_Verification::get_policy( $tool_name, $result );
					if ( $policy && 'none' !== ( $policy['strategy'] ?? 'none' ) ) {
						$readback_call = PressArk_Verification::build_readback( $policy, $result, $action_args );
						if ( $readback_call ) {
							try {
								$readback_result = $engine->execute_read( $readback_call['name'], $readback_call['arguments'] );
								$eval = PressArk_Verification::evaluate( $policy, $action_args, $readback_result, $result );
								$checkpoint->record_verification(
									$tool_name,
									$readback_result,
									$eval['passed'],
									$eval['evidence'],
									array(
										'policy'     => $policy,
										'readback'   => array( 'tool' => $readback_call['name'] ?? '' ),
										'mismatches' => $eval['mismatches'] ?? array(),
									)
								);
								$result['verification'] = array(
									'passed'   => $eval['passed'],
									'evidence' => $eval['evidence'],
									'status'   => $eval['passed'] ? 'verified' : 'uncertain',
								);
							} catch ( \Throwable $ve ) {
								$readback_error = 'Read-back failed: ' . sanitize_text_field( $ve->getMessage() );
								$checkpoint->record_verification(
									$tool_name,
									array(
										'success' => false,
										'message' => $readback_error,
									),
									false,
									$readback_error,
									array(
										'policy'          => $policy,
										'readback'        => array( 'tool' => $readback_call['name'] ?? '' ),
										'readback_failed' => true,
									)
								);
								$result['verification'] = array(
									'passed'   => false,
									'evidence' => $readback_error,
									'status'   => 'uncertain',
								);
								// Read-back failed - degrade gracefully; do not block the confirm.
							}
						}
					}
					// Nudge for model continuation (even if no read-back).
					$nudge = PressArk_Verification::build_nudge( $tool_name, $result, $result['verification'] ?? null );
					if ( $nudge ) {
						$result['verification_nudge'] = $nudge;
					}
				}
			} else {
				$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Confirmed action failed.' ) );
			}
			$this->persist_run_checkpoint( $run, $checkpoint );
			$result['checkpoint'] = $checkpoint->to_array();
			$result = $this->attach_continuation_context( $result, $run, $checkpoint );
			$this->persist_run_detail_snapshot( $run, $result, $checkpoint );

			// Include fresh usage data so the client can update display.
			$result['usage']     = $tracker->get_usage_data();
			$result['plan_info'] = PressArk_Entitlements::get_plan_info( $tier );
			$result              = $this->hydrate_approval_receipt(
				$result,
				array(
					'run_status' => sanitize_key( (string) ( $run['status'] ?? '' ) ),
				)
			);
			$result              = $this->hydrate_chat_surfaces( $result, (string) ( $run['route'] ?? 'agent' ) );

			return rest_ensure_response( $result );

		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Confirm failed', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'The changes could not be applied due to an unexpected error. No modifications were made. Please try again.', 'pressark' ),
			) );
		}
	}

	/**
	 * Handle preview keep ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â promote staged changes to live.
	 *
	 * v3.1.0: Run-aware. Looks up the originating run by preview session ID,
	 * restores the persisted pause snapshot, and executes the post-apply verify phase.
	 * Falls back to legacy behavior if no run record exists (backward compat).
	 */
	// v5.6.0: Streaming progress via on_progress callback (inspired by Claude Code Tool.ts pattern).
	private function execute_confirmed_action(
		array $run,
		array $pending,
		array $action,
		int $action_index,
		PressArk_Usage_Tracker $tracker,
		string $tier,
		?PressArk_SSE_Emitter $emitter = null
	): array {
		$run_id          = sanitize_text_field( (string) ( $run['run_id'] ?? '' ) );
		$action_meta     = is_array( $action['meta'] ?? null ) ? $action['meta'] : array();
		$permission_meta = is_array( $action_meta['permission_meta'] ?? null ) ? $action_meta['permission_meta'] : array();
		$permission_meta['run_id']       = $run_id;
		$permission_meta['action_index'] = $action_index;
		$permission_meta['source']       = 'confirm';
		$action_meta['approval_granted'] = true;
		$action_meta['permission_meta']  = $permission_meta;
		if ( empty( $action_meta['permission_context'] ) ) {
			$action_meta['permission_context'] = 'interactive';
		}
		$action['meta'] = $action_meta;
		$action_name    = (string) ( $action['type'] ?? 'confirmed_action' );

		$operation            = PressArk_Operation_Registry::resolve( $action_name );
		$tool_progress_key    = $this->confirm_tool_progress_key( $action );
		$max_chunk_tokens     = max(
			1,
			(int) ( $operation instanceof PressArk_Operation ? $operation->max_stream_chunk_tokens : 500 )
		);
		$streamed_chunks      = array();
		$streamed_token_count = 0;
		$progress_sequence    = 0;
		$stream_limit_hit     = false;

		if ( $emitter && $emitter->is_connected() ) {
			$emitter->emit(
				'tool_call',
				array(
					'name'     => $action_name,
					'tool'     => $action_name,
					'tool_key' => $tool_progress_key,
				)
			);
		}

		$progress_callback = function ( $chunk ) use (
			$emitter,
			$action_name,
			$tool_progress_key,
			$max_chunk_tokens,
			&$streamed_chunks,
			&$streamed_token_count,
			&$progress_sequence,
			&$stream_limit_hit
		): void {
			try {
				foreach ( $this->build_confirm_stream_progress_packets( $chunk, $max_chunk_tokens ) as $packet ) {
					$chunk_tokens = (int) ( $packet['estimated_tokens'] ?? 0 );
					if ( $chunk_tokens <= 0 ) {
						continue;
					}

					if ( ( $streamed_token_count + $chunk_tokens ) > self::MAX_STREAM_PROGRESS_TOKENS ) {
						if ( ! $stream_limit_hit ) {
							$stream_limit_hit = true;
							PressArk_Error_Tracker::warning(
								'Chat',
								'Stopped confirm progress streaming after reaching the token ceiling',
								array(
									'tool'       => $action_name,
									'max_tokens' => self::MAX_STREAM_PROGRESS_TOKENS,
								)
							);
						}
						break;
					}

					$progress_sequence++;
					$streamed_chunks[] = array(
						'sequence'    => $progress_sequence,
						'data'        => $packet['data'],
						'token_count' => $chunk_tokens,
					);
					$streamed_token_count += $chunk_tokens;

					if ( $emitter && $emitter->is_connected() ) {
						$emitter->emit(
							'tool_progress',
							array(
								'name'     => $action_name,
								'tool'     => $action_name,
								'tool_key' => $tool_progress_key,
								'sequence' => $progress_sequence,
								'progress' => $packet['data'],
							)
						);
					}
				}
			} catch ( \Throwable $e ) {
				PressArk_Error_Tracker::warning(
					'Chat',
					'Confirm progress callback failed but execution continued',
					array(
						'tool'  => $action_name,
						'error' => $e->getMessage(),
					)
				);
			}
		};

		$logger = new PressArk_Action_Logger();
		$engine = new PressArk_Action_Engine( $logger );
		$result = $engine->execute_single( $action, false, $progress_callback );

		if ( ! empty( $streamed_chunks ) ) {
			$result['streamed_chunks'] = array(
				array(
					'tool'        => sanitize_key( $action_name ),
					'tool_key'    => $tool_progress_key,
					'chunk_count' => count( $streamed_chunks ),
					'token_count' => $streamed_token_count,
					'progress'    => $this->merge_confirm_stream_progress_chunks( $streamed_chunks ),
				),
			);
			$result['streamed_token_count'] = $streamed_token_count;
		}

		if ( $emitter && $emitter->is_connected() ) {
			$emitter->emit(
				'tool_result',
				array(
					'name'     => $action_name,
					'tool'     => $action_name,
					'tool_key' => $tool_progress_key,
					'success'  => ! empty( $result['success'] ),
					'summary'  => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
				)
			);
		}

		$result = $this->attach_approval_outcome(
			$result,
			'approved',
			array(
				'action'      => $action_name,
				'actor'       => 'user',
				'scope'       => 'confirm',
				'source'      => 'approval',
				'reason_code' => 'user_confirmed',
			)
		);

		if ( ! empty( $result['success'] ) ) {
			$tracker->increment_if_write( $action['type'] ?? '' );
		}

		$run_store    = new PressArk_Run_Store();
		$all_resolved = false;

		if ( ! empty( $result['success'] ) ) {
			if ( ! is_array( $pending[ $action_index ] ) ) {
				$pending[ $action_index ] = array(
					'action' => $action,
				);
			}
			$pending[ $action_index ]['resolved'] = true;
			$all_resolved                          = true;

			foreach ( $pending as $pending_action ) {
				if ( empty( $pending_action['resolved'] ) ) {
					$all_resolved = false;
					break;
				}
			}

			if ( $all_resolved ) {
				$result        = PressArk_Pipeline::settle_run( $run_id, $result );
				$run['status'] = 'settled';
			} else {
				$run_store->update_pending( $run_id, $pending, 'partially_confirmed' );
				$run['pending_actions']   = $pending;
				$run['status']            = 'partially_confirmed';
				$result['run_id']         = $run_id;
				$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
			}
		} else {
			$result['run_id']         = $run_id;
			$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
		}

		$action_args = is_array( $action['params'] ?? null ) ? $action['params'] : array();
		if ( empty( $action_args ) ) {
			$action_args = $action;
			unset( $action_args['type'], $action_args['description'], $action_args['meta'] );
		}

		$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
		$this->remember_approval_outcome(
			$checkpoint,
			$action_name,
			'approved',
			array(
				'scope'       => 'confirm',
				'source'      => 'approval',
				'actor'       => 'user',
				'reason_code' => 'user_confirmed',
			)
		);
		$checkpoint->record_execution_write( (string) ( $action['type'] ?? '' ), $action_args, $result );
		$this->sync_checkpoint_pending_confirms( $checkpoint, $pending );

		if ( ! empty( $result['success'] ) ) {
			$checkpoint->clear_blockers();
			$checkpoint->add_approval( $action_name );
			if ( method_exists( $checkpoint, 'record_plan_apply_success' ) ) {
				$checkpoint->record_plan_apply_success( $action_name, $action_args, $result );
			}
			$this->advance_plan_execution_state( $checkpoint, array( 'confirm' ), $action_name . ':confirm' );
			$this->advance_plan_execution_state( $checkpoint, array( 'write' ), $action_name );
			if ( $all_resolved ) {
				$checkpoint->set_workflow_stage( 'settled' );
			}

			if ( class_exists( 'PressArk_Verification' ) ) {
				$tool_name = (string) ( $action['type'] ?? '' );
				$policy    = PressArk_Verification::get_policy( $tool_name, $result );
				if ( $policy && 'none' !== ( $policy['strategy'] ?? 'none' ) ) {
					$readback_call = PressArk_Verification::build_readback( $policy, $result, $action_args );
					if ( $readback_call ) {
						try {
							$readback_result = $engine->execute_read( $readback_call['name'], $readback_call['arguments'] );
							$eval            = PressArk_Verification::evaluate( $policy, $action_args, $readback_result, $result );
							$checkpoint->record_verification(
								$tool_name,
								$readback_result,
								$eval['passed'],
								$eval['evidence'],
								array(
									'policy'     => $policy,
									'readback'   => array( 'tool' => $readback_call['name'] ?? '' ),
									'mismatches' => $eval['mismatches'] ?? array(),
								)
							);
							$result['verification'] = array(
								'passed'   => $eval['passed'],
								'evidence' => $eval['evidence'],
								'status'   => $eval['passed'] ? 'verified' : 'uncertain',
							);
						} catch ( \Throwable $ve ) {
							$readback_error = 'Read-back failed: ' . sanitize_text_field( $ve->getMessage() );
							$checkpoint->record_verification(
								$tool_name,
								array(
									'success' => false,
									'message' => $readback_error,
								),
								false,
								$readback_error,
								array(
									'policy'          => $policy,
									'readback'        => array( 'tool' => $readback_call['name'] ?? '' ),
									'readback_failed' => true,
								)
							);
							$result['verification'] = array(
								'passed'   => false,
								'evidence' => $readback_error,
								'status'   => 'uncertain',
							);
						}
					}
				}

				$nudge = PressArk_Verification::build_nudge( $tool_name, $result, $result['verification'] ?? null );
				if ( $nudge ) {
					$result['verification_nudge'] = $nudge;
				}
			}
			if ( ! empty( $result['verification'] ) ) {
				$this->advance_plan_execution_state( $checkpoint, array( 'verify' ), $action_name . ':verify' );
			}
			$this->maybe_complete_plan_execution( $run, $checkpoint );
		} else {
			$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Confirmed action failed.' ) );
		}

		$this->replace_checkpoint_replay_tool_results(
			$checkpoint,
			$this->build_resolved_write_replay_tool_results(
				array( $action ),
				$result,
				'confirm_card'
			)
		);
		$this->persist_run_checkpoint( $run, $checkpoint );
		$result['checkpoint'] = $checkpoint->to_array();
		$result               = $this->attach_continuation_context( $result, $run, $checkpoint );
		$this->persist_run_detail_snapshot( $run, $result, $checkpoint );

		$result['usage']     = $tracker->get_usage_data();
		$result['plan_info'] = PressArk_Entitlements::get_plan_info( $tier );
		$result              = $this->hydrate_approval_receipt(
			$result,
			array(
				'run_status' => sanitize_key( (string) ( $run['status'] ?? '' ) ),
			)
		);

		return $this->hydrate_chat_surfaces( $result, (string) ( $run['route'] ?? 'agent' ) );
	}

	private function confirm_tool_progress_key( array $action ): string {
		$id = sanitize_text_field( (string) ( $action['id'] ?? '' ) );
		if ( '' !== $id ) {
			return $id;
		}

		$name      = sanitize_key( (string) ( $action['type'] ?? $action['name'] ?? 'tool' ) );
		$arguments = wp_json_encode( $action['params'] ?? $action['arguments'] ?? array() );
		if ( ! is_string( $arguments ) ) {
			$arguments = '';
		}

		return $name . ':' . substr( md5( $arguments ), 0, 12 );
	}

	private function build_confirm_stream_progress_packets( $chunk, int $max_chunk_tokens ): array {
		$data = $this->normalize_confirm_stream_chunk( $chunk );
		if ( empty( $data ) ) {
			return array();
		}

		$estimated = $this->estimate_stream_payload_tokens( $data );
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

			if ( ! empty( $current ) && $this->estimate_stream_payload_tokens( $candidate ) > $max_chunk_tokens ) {
				$packets[] = array(
					'data'             => $current,
					'estimated_tokens' => $this->estimate_stream_payload_tokens( $current ),
				);
				$current = $is_list ? array( $value ) : array( $key => $value );
				continue;
			}

			$current = $candidate;
		}

		if ( ! empty( $current ) ) {
			$packets[] = array(
				'data'             => $current,
				'estimated_tokens' => $this->estimate_stream_payload_tokens( $current ),
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

	private function normalize_confirm_stream_chunk( $chunk ): array {
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

		$sanitized = $this->sanitize_confirm_stream_value( $data );
		if ( ! is_array( $sanitized ) ) {
			return array();
		}

		return array_filter(
			$sanitized,
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

	private function sanitize_confirm_stream_value( $value, ?string $key = null ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $child_key => $child_value ) {
				$normalized_key               = is_string( $child_key ) ? $child_key : (string) $child_key;
				$sanitized[ $normalized_key ] = $this->sanitize_confirm_stream_value( $child_value, $normalized_key );
			}
			return $sanitized;
		}

		if ( is_object( $value ) ) {
			return $this->sanitize_confirm_stream_value( (array) $value, $key );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$text     = sanitize_text_field( (string) $value );
		$key_name = strtolower( (string) $key );

		if ( '' === $text ) {
			return '';
		}

		if ( '' !== $key_name && preg_match( '/(email|e-mail|api[_-]?key|token|secret|password|authorization|auth)/i', $key_name ) ) {
			return '[redacted]';
		}

		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text ) ) {
			return '[redacted-email]';
		}

		if ( preg_match( '/\b(?:sk|pk|rk|api)[-_][A-Za-z0-9_-]{8,}\b/', $text ) ) {
			return '[redacted-secret]';
		}

		if ( mb_strlen( $text ) > 240 ) {
			$text = mb_substr( $text, 0, 240 ) . '...';
		}

		return $text;
	}

	private function estimate_stream_payload_tokens( $value ): int {
		$serialized = wp_json_encode( $value );
		if ( ! is_string( $serialized ) || '' === $serialized ) {
			return 0;
		}

		return (int) ceil( mb_strlen( $serialized ) / 4 );
	}

	private function merge_confirm_stream_progress_chunks( array $chunks ): array {
		if ( empty( $chunks ) ) {
			return array();
		}

		$last = end( $chunks );
		reset( $chunks );

		return is_array( $last['data'] ?? null ) ? (array) $last['data'] : array();
	}

	/**
	 * Handle preview keep.
	 */
	public function handle_preview_keep( WP_REST_Request $request ): WP_REST_Response {
		try {
			$session_id = $request->get_param( 'session_id' );

			if ( empty( $session_id ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'Missing session ID.', 'pressark' ),
				) );
			}

			$preview       = new PressArk_Preview();
			$session_calls = $preview->get_session_tool_calls( $session_id );
			$result        = $preview->keep( $session_id );

			if ( ! $result['success'] ) {
				return rest_ensure_response( $result );
			}

			$result = $this->attach_approval_outcome(
				$result,
				'approved',
				array(
					'action'      => 'preview_apply',
					'actor'       => 'user',
					'scope'       => 'preview',
					'source'      => 'approval',
					'reason_code' => 'preview_kept',
					'meta'        => array(
						'call_count' => is_array( $session_calls ) ? count( $session_calls ) : 0,
					),
				)
			);

			// Track write usage.
			$tracker = new PressArk_Usage_Tracker();
			$tracker->increment_if_write( 'preview_apply' );

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v3.1.0: Settle durable run via pipeline authority ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get_by_preview_session( $session_id );

			if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
				$this->hydrate_trace_context_from_run( $run );
				$result = PressArk_Pipeline::settle_run( $run['run_id'], $result );
				$run['status'] = 'settled';

				$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
				$checkpoint->record_execution_preview( $session_calls, $result );
				if ( ! empty( $session_calls ) ) {
					foreach ( $session_calls as $call ) {
						$call_name = (string) ( $call['name'] ?? $call['type'] ?? 'preview_apply' );
						$this->remember_approval_outcome(
							$checkpoint,
							$call_name,
							'approved',
							array(
								'scope'       => 'preview',
								'source'      => 'approval',
								'actor'       => 'user',
								'reason_code' => 'preview_kept',
							)
						);
						$checkpoint->add_approval( $call_name );
					}
				} else {
					$this->remember_approval_outcome(
						$checkpoint,
						'preview_apply',
						'approved',
						array(
							'scope'       => 'preview',
							'source'      => 'approval',
							'actor'       => 'user',
							'reason_code' => 'preview_kept',
						)
					);
					$checkpoint->add_approval( 'preview_apply' );
				}
				if ( ! empty( $result['success'] ) ) {
					$checkpoint->clear_blockers();
					$checkpoint->set_workflow_stage( 'settled' );

					// Mirror the confirm-apply path: record per-call apply
					// success on the active plan step so preview-required
					// steps can be marked completed by subsequent update_plan
					// calls. Without this, validate_update_plan_tool_call
					// rejects "Preview-required steps can only be marked
					// completed after the apply step actually succeeded"
					// and the plan stalls forever after the first preview.
					if ( method_exists( $checkpoint, 'record_plan_apply_success' ) && ! empty( $session_calls ) ) {
						foreach ( $session_calls as $applied_call ) {
							$applied_name = (string) ( $applied_call['name'] ?? $applied_call['type'] ?? '' );
							$applied_args = is_array( $applied_call['arguments'] ?? null )
								? $applied_call['arguments']
								: ( is_array( $applied_call['params'] ?? null ) ? $applied_call['params'] : array() );
							if ( '' !== $applied_name ) {
								$checkpoint->record_plan_apply_success( $applied_name, $applied_args, $result );
							}
						}
					}

					$this->advance_plan_execution_state( $checkpoint, array( 'preview' ), 'preview_apply:preview' );
					$advance_count = max( 1, is_array( $session_calls ) ? count( $session_calls ) : 0 );
					for ( $advance_index = 0; $advance_index < $advance_count; $advance_index++ ) {
						$this->advance_plan_execution_state( $checkpoint, array( 'write' ), 'preview_apply' );
					}

					// v5.4.0: Evidence-based verification for preview-applied writes.
					if ( class_exists( 'PressArk_Verification' ) && ! empty( $session_calls ) ) {
						$logger_v = new PressArk_Action_Logger();
						$engine_v = new PressArk_Action_Engine( $logger_v );
						foreach ( $session_calls as $call ) {
							$tool_name_v = (string) ( $call['name'] ?? $call['type'] ?? '' );
							$args_v      = is_array( $call['arguments'] ?? null ) ? $call['arguments'] : ( $call['params'] ?? array() );
							$policy_v    = PressArk_Verification::get_policy( $tool_name_v, $result );
							if ( ! $policy_v || 'none' === ( $policy_v['strategy'] ?? 'none' ) ) {
								continue;
							}
							$readback_call_v = PressArk_Verification::build_readback( $policy_v, $result, $args_v );
							if ( ! $readback_call_v ) {
								continue;
							}
							try {
								$readback_result_v = $engine_v->execute_read( $readback_call_v['name'], $readback_call_v['arguments'] );
								$eval_v = PressArk_Verification::evaluate( $policy_v, $args_v, $readback_result_v, $result );
								$checkpoint->record_verification(
									$tool_name_v,
									$readback_result_v,
									$eval_v['passed'],
									$eval_v['evidence'],
									array(
										'policy'     => $policy_v,
										'readback'   => array( 'tool' => $readback_call_v['name'] ?? '' ),
										'mismatches' => $eval_v['mismatches'] ?? array(),
									)
								);
								if ( ! isset( $result['verification'] ) ) {
									$result['verification'] = array();
								}
								$result['verification'][ $tool_name_v ] = array(
									'passed'   => $eval_v['passed'],
									'evidence' => $eval_v['evidence'],
									'status'   => $eval_v['passed'] ? 'verified' : 'uncertain',
								);
							} catch ( \Throwable $ve ) {
								$readback_error_v = 'Read-back failed: ' . sanitize_text_field( $ve->getMessage() );
								$checkpoint->record_verification(
									$tool_name_v,
									array(
										'success' => false,
										'message' => $readback_error_v,
									),
									false,
									$readback_error_v,
									array(
										'policy'          => $policy_v,
										'readback'        => array( 'tool' => $readback_call_v['name'] ?? '' ),
										'readback_failed' => true,
									)
								);
								if ( ! isset( $result['verification'] ) ) {
									$result['verification'] = array();
								}
								$result['verification'][ $tool_name_v ] = array(
									'passed'   => false,
									'evidence' => $readback_error_v,
									'status'   => 'uncertain',
								);
								// Read-back failed - degrade gracefully.
							}
						}
					}
					if ( ! empty( $result['verification'] ) ) {
						$verification_count = is_array( $result['verification'] ) ? count( $result['verification'] ) : 1;
						for ( $verify_index = 0; $verify_index < max( 1, $verification_count ); $verify_index++ ) {
							$this->advance_plan_execution_state( $checkpoint, array( 'verify' ), 'preview_apply:verify' );
						}
					}
					$this->maybe_complete_plan_execution( $run, $checkpoint );
				} else {
					$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Preview apply failed.' ) );
				}
				$this->replace_checkpoint_replay_tool_results(
					$checkpoint,
					$this->build_resolved_write_replay_tool_results(
						is_array( $session_calls ) ? $session_calls : array(),
						$result,
						'preview'
					)
				);
				$this->persist_run_checkpoint( $run, $checkpoint );
				$result['checkpoint'] = $checkpoint->to_array();
				$result = $this->attach_continuation_context( $result, $run, $checkpoint );
				$this->persist_run_detail_snapshot( $run, $result, $checkpoint );
			}

			$tier = ( new PressArk_License() )->get_tier();
			$result['usage']     = $tracker->get_usage_data();
			$result['plan_info'] = PressArk_Entitlements::get_plan_info( $tier );
			$result              = $this->hydrate_approval_receipt(
				$result,
				array(
					'run_status' => sanitize_key( (string) ( $run['status'] ?? '' ) ),
				)
			);
			$result              = $this->hydrate_chat_surfaces( $result, (string) ( $run['route'] ?? 'agent' ) );
			return rest_ensure_response( $result );

		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Preview keep failed', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'The preview changes could not be applied. The original content is unchanged. Please try again.', 'pressark' ),
			) );
		}
	}

	/**
	 * Handle preview discard ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â delete staged changes.
	 *
	 * v3.1.0: Settles the originating run as discarded.
	 */
	public function handle_preview_discard( WP_REST_Request $request ): WP_REST_Response {
		try {
			$session_id = $request->get_param( 'session_id' );

			if ( empty( $session_id ) ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => __( 'Missing session ID.', 'pressark' ),
				) );
			}

			$preview       = new PressArk_Preview();
			$session_calls = $preview->get_session_tool_calls( $session_id );
			$result        = $preview->discard( $session_id );
			$discarded_message = sanitize_text_field( (string) ( $result['message'] ?? __( 'No changes were applied. The preview was discarded.', 'pressark' ) ) );
			if ( '' === $discarded_message ) {
				$discarded_message = __( 'No changes were applied. The preview was discarded.', 'pressark' );
			}
			$result['message'] = $discarded_message;
			if ( empty( $result['success'] ) ) {
				return rest_ensure_response( $result );
			}
			$result            = $this->attach_approval_outcome(
				$result,
				'discarded',
				array(
					'action'      => 'preview_apply',
					'actor'       => 'user',
					'scope'       => 'preview',
					'source'      => 'approval',
					'reason_code' => 'preview_discarded',
					'message'     => $discarded_message,
				)
			);

			// v3.1.0: Settle the run as discarded.
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get_by_preview_session( $session_id );
			if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
				$this->hydrate_trace_context_from_run( $run );
				$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
				if ( ! empty( $session_calls ) ) {
					foreach ( $session_calls as $call ) {
						$this->remember_approval_outcome(
							$checkpoint,
							(string) ( $call['name'] ?? $call['type'] ?? 'preview_apply' ),
							'discarded',
							array(
								'scope'       => 'preview',
								'source'      => 'approval',
								'actor'       => 'user',
								'reason_code' => 'preview_discarded',
							)
						);
					}
				} else {
					$this->remember_approval_outcome(
						$checkpoint,
						'preview_apply',
						'discarded',
						array(
							'scope'       => 'preview',
							'source'      => 'approval',
							'actor'       => 'user',
							'reason_code' => 'preview_discarded',
						)
					);
				}
				$checkpoint->clear_plan_state();
				$this->replace_checkpoint_replay_tool_results(
					$checkpoint,
					$this->build_resolved_write_replay_tool_results(
						is_array( $session_calls ) ? $session_calls : array(),
						$result,
						'preview',
						'discarded'
					)
				);
				$this->persist_run_checkpoint( $run, $checkpoint );
				$result['checkpoint'] = $checkpoint->to_array();
				$result['run_id'] = (string) ( $run['run_id'] ?? '' );
				$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
				$run['status'] = 'settled';
				$run_store->settle( $run['run_id'], $result );
				$this->persist_run_detail_snapshot( $run, $result, $checkpoint );
			}

			$result = $this->hydrate_approval_receipt(
				$result,
				array(
					'run_status' => sanitize_key( (string) ( $run['status'] ?? '' ) ),
				)
			);
			$result = $this->hydrate_chat_surfaces( $result, is_array( $run ) ? (string) ( $run['route'] ?? 'agent' ) : 'agent' );
			return rest_ensure_response( $result );

		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Preview discard failed', array( 'error' => $e->getMessage() ) );
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'Could not discard the staged preview. The live content was never modified. You can safely ignore this.', 'pressark' ),
			) );
		}
	}

	// v3.1.0: Run settlement continuation logic lives in PressArk_Pipeline::settle_run().

	/**
	 * Load the checkpoint for a durable run, or bootstrap a new one.
	 *
	 * Approval handlers use this after keep/confirm so continuation state is
	 * updated even if the frontend never sends a checkpoint back.
	 */
	private function load_or_bootstrap_run_checkpoint( array $run ): PressArk_Checkpoint {
		$chat_id    = (int) ( $run['chat_id'] ?? 0 );
		$user_id    = (int) ( $run['user_id'] ?? get_current_user_id() );
		$checkpoint = $chat_id > 0
			? PressArk_Checkpoint::load( $chat_id, $user_id )
			: null;

		if ( ! $checkpoint ) {
			$checkpoint = PressArk_Checkpoint::from_array( array() );
		}

		if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
			$checkpoint->absorb_run_snapshot( $run['workflow_state'] );
		}
		$checkpoint->sync_execution_goal( (string) ( $run['message'] ?? '' ) );
		return $checkpoint;
	}

	/**
	 * Persist a run checkpoint back to the owning chat when possible.
	 */
	private function persist_run_checkpoint( array $run, PressArk_Checkpoint $checkpoint ): void {
		$checkpoint->touch();
		$chat_id = (int) ( $run['chat_id'] ?? 0 );
		$user_id = (int) ( $run['user_id'] ?? get_current_user_id() );

		if ( $chat_id > 0 ) {
			$checkpoint->save( $chat_id, $user_id );
		}

		if ( ! empty( $run['run_id'] ) ) {
			$run_store = new PressArk_Run_Store();
			$run_store->persist_detail_snapshot( (string) $run['run_id'], $checkpoint->to_array(), null );
		}
	}

	/**
	 * Persist the latest result plus checkpoint snapshot back onto the run row.
	 */
	private function persist_run_detail_snapshot( array $run, array $result, PressArk_Checkpoint $checkpoint ): void {
		if ( empty( $run['run_id'] ) ) {
			return;
		}

		$run_store = new PressArk_Run_Store();
		$run_store->persist_detail_snapshot( (string) $run['run_id'], $checkpoint->to_array(), $result );
	}

	/**
	 * Attach continuation metadata grounded in the updated execution ledger.
	 *
	 * v3.7.3 introduced continuation context for post-approval resumption.
	 * v3.7.5 hardens it with the durable execution ledger and target replay guard.
	 */
	private function attach_continuation_context(
		array $result,
		array $run,
		?PressArk_Checkpoint $checkpoint = null
	): array {
		$result['continuation'] = array(
			'original_message' => $run['message'] ?? '',
		);

		foreach ( array( 'post_id', 'post_title', 'url', 'post_type', 'post_status', 'targets' ) as $field ) {
			if ( isset( $result[ $field ] ) ) {
				$result['continuation'][ $field ] = $result[ $field ];
			}
		}

		if ( $checkpoint ) {
			$execution = $checkpoint->get_execution();
			$progress  = PressArk_Execution_Ledger::progress_snapshot( $execution );
			$blockers  = $checkpoint->get_blockers();
			$replay    = $checkpoint->get_replay_sidecar();

			// v5.6.6 (2026-05-12): Auto-resume also fires when the model's plan
			// artifact has unfinished steps, even if the ledger is currently
			// task-empty. Multi-step plans (e.g., "create page A then page B")
			// have one create_post tool_call per round, so the ledger only
			// reflects the step currently being executed — the remaining steps
			// live in plan_artifact. Without this check, two-step chains break
			// after the first preview-keep: ledger says done, auto-resume
			// returns false, and step 2 never runs. The model's plan is the
			// authoritative source of "are we done with the user's request?".
			//
			// v5.7.6 (2026-05-12): ALSO check `plan_steps` — the storage location
			// for model-emitted update_plan steps when no Plan Mode pre-seeded a
			// structured artifact. Observed on "Create About + Shipping Policy
			// pages, add both to main menu" chain: model emitted update_plan with
			// 5 steps via the executor path (Soft Plan). Those steps populated
			// `plan_steps` (sanitize_plan_steps), NOT `plan_artifact.steps` (which
			// only carries Plan-Mode-seeded structured steps + dynamically-inserted
			// ones from actual tool_calls). After step 3 Keep, plan_artifact had
			// only one auto-generated `dynamic_create_post_2` step (completed) →
			// has_remaining_plan_steps=false → no auto-resume → steps 4-5 silently
			// skipped. plan_steps had the canonical pending list; reading from
			// both sources closes the gap. The artifact remains primary; plan_steps
			// is the fallback when no artifact exists or its steps are exhausted
			// but the model's plan is not.
			$has_remaining_plan_steps = false;
			if ( method_exists( $checkpoint, 'get_plan_artifact' ) ) {
				$artifact = (array) $checkpoint->get_plan_artifact();
				$steps    = is_array( $artifact['steps'] ?? null ) ? $artifact['steps'] : array();
				foreach ( $steps as $step ) {
					$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
					if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
						$has_remaining_plan_steps = true;
						break;
					}
				}
			}
			if ( ! $has_remaining_plan_steps && method_exists( $checkpoint, 'get_plan_steps' ) ) {
				$plan_steps = (array) $checkpoint->get_plan_steps();
				foreach ( $plan_steps as $step ) {
					if ( ! is_array( $step ) ) {
						continue;
					}
					$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
					if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
						$has_remaining_plan_steps = true;
						break;
					}
				}
			}

			$result['continuation']['execution']          = $execution;
			$result['continuation']['progress']           = $progress;
			$result['continuation']['blockers']           = $blockers;
			$result['continuation']['should_auto_resume'] = empty( $blockers )
				&& ( ! empty( $progress['should_auto_resume'] ) || $has_remaining_plan_steps );
			if ( ! empty( $replay ) ) {
				$result['continuation']['replay'] = $replay;
				$result['replay']                 = $replay;
			}

			if ( ! empty( $progress['is_complete'] ) ) {
				$result['continuation']['completion_message'] = 'All requested steps are complete. Do not continue automatically.';
			} elseif ( ! empty( $blockers ) ) {
				$result['continuation']['pause_message'] = 'Auto-resume paused because unresolved blockers remain.';
			}

			$v_summary = PressArk_Execution_Ledger::verification_summary( $execution );
			if ( $v_summary['verified'] > 0 || $v_summary['uncertain'] > 0 || $v_summary['unverified'] > 0 ) {
				$result['continuation']['verification_summary'] = $v_summary;
			}
			$evidence_receipts = PressArk_Execution_Ledger::evidence_receipts( $execution );
			if ( ! empty( $evidence_receipts ) ) {
				$result['continuation']['evidence_receipts'] = $evidence_receipts;
			}
			if ( class_exists( 'PressArk_Run_Trust_Surface' ) ) {
				$result['trust_surface'] = PressArk_Run_Trust_Surface::build(
					array_merge( $run, array( 'result' => $result ) ),
					array(),
					$execution
				);
			}
		}

		// v5.7.13 (2026-05-13): Phase 3a — read-only continuation-evaluator
		// adapter. Attaches a typed decision struct under
		// result.continuation.evaluator for A/B against the legacy fields above.
		// Phase 3b: attach_to_result now overwrites canonical continuation
		// fields from the evaluator; evaluator_* mirrors remain for debugging.
		if ( class_exists( 'PressArk_Continuation_Service' ) ) {
			$result = PressArk_Continuation_Service::attach_to_result( $result, $checkpoint );
		}

		return $result;
	}

	/**
	 * Build replay-facing tool results that replace pending approval placeholders
	 * with the actual settled outcome before any continuation resumes.
	 *
	 * @param array<int,array<string,mixed>> $tool_calls      Original tool calls.
	 * @param array<string,mixed>            $result          Settled approval result.
	 * @param string                         $approval_ui     preview|confirm_card.
	 * @param string                         $resolved_status applied|apply_failed|discarded|declined.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_resolved_write_replay_tool_results(
		array $tool_calls,
		array $result,
		string $approval_ui,
		string $resolved_status = ''
	): array {
		$tool_count = count( $tool_calls );
		$resolved_status = sanitize_key( $resolved_status );
		if ( '' === $resolved_status ) {
			$resolved_status = ! empty( $result['success'] ) ? 'applied' : 'apply_failed';
		}

		$message = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
		if ( '' === $message ) {
			$message = match ( $resolved_status ) {
				'applied'      => __( 'Approved write was applied.', 'pressark' ),
				'discarded'    => __( 'Preview was discarded. No changes were applied.', 'pressark' ),
				'declined'     => __( 'Confirmation was declined. No changes were applied.', 'pressark' ),
				default        => __( 'Approved write failed to apply.', 'pressark' ),
			};
		}

		$recorded_at = sanitize_text_field(
			(string) (
				$result['approval_outcome']['recorded_at']
				?? $result['approval_receipt']['recorded_at']
				?? gmdate( 'c' )
			)
		);

		$target = array_filter(
			array(
				'post_id'     => absint( $result['post_id'] ?? 0 ),
				'post_title'  => sanitize_text_field( (string) ( $result['post_title'] ?? '' ) ),
				'post_type'   => sanitize_key( (string) ( $result['post_type'] ?? '' ) ),
				'post_status' => sanitize_key( (string) ( $result['post_status'] ?? '' ) ),
				'url'         => esc_url_raw( (string) ( $result['url'] ?? '' ) ),
			),
			static function ( $value ): bool {
				return '' !== (string) $value && null !== $value && 0 !== $value;
			}
		);

		$replay_results = array();
		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$tool_call_id = sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) );
			if ( '' === $tool_call_id ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			$verification = $this->extract_replay_tool_verification( $result, $tool_name, $tool_count );

			$replay_results[] = array(
				'tool_use_id' => $tool_call_id,
				'result'      => array_filter(
					array(
						'success' => 'applied' === $resolved_status,
						'message' => $message,
						'data'    => array_filter(
							array(
								'status'       => $resolved_status,
								'approval_ui'  => sanitize_key( $approval_ui ),
								'tool_name'    => $tool_name,
								'approved_at'  => $recorded_at,
								'write_applied'=> 'applied' === $resolved_status,
								'target'       => $target,
								'verification' => $verification,
							),
							static function ( $value ): bool {
								if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
									return true;
								}
								return is_array( $value ) ? ! empty( $value ) : '' !== (string) $value;
							}
						),
					),
					static function ( $value ): bool {
						if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
							return true;
						}
						return is_array( $value ) ? ! empty( $value ) : '' !== (string) $value;
					}
				),
			);
		}

		return $replay_results;
	}

	/**
	 * Extract compact verification state for a replayed write result.
	 *
	 * @param array<string,mixed> $result     Settled approval result.
	 * @param string              $tool_name  Tool name for the write.
	 * @param int                 $tool_count Number of tool calls in the approval batch.
	 * @return array<string,string>
	 */
	private function extract_replay_tool_verification( array $result, string $tool_name, int $tool_count ): array {
		$verification = is_array( $result['verification'] ?? null ) ? (array) $result['verification'] : array();
		$row          = array();

		if ( isset( $verification[ $tool_name ] ) && is_array( $verification[ $tool_name ] ) ) {
			$row = (array) $verification[ $tool_name ];
		} elseif ( 1 === $tool_count && isset( $verification['status'] ) ) {
			$row = $verification;
		}

		return array_filter(
			array(
				'status'   => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'evidence' => sanitize_text_field( (string) ( $row['evidence'] ?? '' ) ),
			),
			static function ( $value ): bool {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Replace pending replay tool results with their settled outcome.
	 *
	 * @param PressArk_Checkpoint              $checkpoint    Run checkpoint.
	 * @param array<int,array<string,mixed>>   $tool_results  Replay tool-result replacements.
	 */
	private function replace_checkpoint_replay_tool_results( PressArk_Checkpoint $checkpoint, array $tool_results ): void {
		if ( empty( $tool_results ) ) {
			return;
		}

		$messages = $checkpoint->get_replay_messages();
		if ( empty( $messages ) ) {
			return;
		}

		$canonical = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::canonicalize_messages( $messages )
			: $messages;
		if ( empty( $canonical ) ) {
			return;
		}

		$replacement_map = array();
		foreach ( $tool_results as $tool_result ) {
			if ( ! is_array( $tool_result ) ) {
				continue;
			}

			$tool_call_id = sanitize_text_field( (string) ( $tool_result['tool_use_id'] ?? '' ) );
			if ( '' === $tool_call_id ) {
				continue;
			}

			$replacement_map[ $tool_call_id ] = array(
				'role'         => 'tool',
				'tool_call_id' => $tool_call_id,
				'content'      => is_string( $tool_result['result'] ?? '' )
					? (string) $tool_result['result']
					: wp_json_encode( $tool_result['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			);
		}

		if ( empty( $replacement_map ) ) {
			return;
		}

		$changed = false;
		foreach ( $canonical as $index => $message ) {
			if ( 'tool' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$tool_call_id = sanitize_text_field( (string) ( $message['tool_call_id'] ?? '' ) );
			if ( '' === $tool_call_id || ! isset( $replacement_map[ $tool_call_id ] ) ) {
				continue;
			}

			$canonical[ $index ] = $replacement_map[ $tool_call_id ];
			unset( $replacement_map[ $tool_call_id ] );
			$changed = true;
		}

		if ( ! empty( $replacement_map ) ) {
			$rebuilt = array();
			foreach ( $canonical as $message ) {
				$rebuilt[] = $message;

				if ( 'assistant' !== ( $message['role'] ?? '' ) || empty( $message['tool_calls'] ) || ! is_array( $message['tool_calls'] ) ) {
					continue;
				}

				foreach ( (array) $message['tool_calls'] as $tool_call ) {
					$tool_call_id = sanitize_text_field( (string) ( $tool_call['id'] ?? '' ) );
					if ( '' === $tool_call_id || ! isset( $replacement_map[ $tool_call_id ] ) ) {
						continue;
					}

					$rebuilt[] = $replacement_map[ $tool_call_id ];
					unset( $replacement_map[ $tool_call_id ] );
					$changed = true;
				}
			}

			$canonical = $rebuilt;
		}

		if ( $changed ) {
			$checkpoint->set_replay_messages( $canonical );
		}
	}

	/**
	 * Continuation requests must inherit the execution target from server-owned
	 * checkpoint state instead of relying on the current editor screen.
	 */
	private function resolve_effective_post_id( string $message, int $post_id, array $checkpoint_data ): int {
		return $this->request_compiler->resolve_effective_post_id( $message, $post_id, $checkpoint_data );
	}

	private function resolve_continuation_mode( string $message, array $checkpoint_data, bool $plan_execute = false ): string {
		return $this->request_compiler->resolve_continuation_mode( $message, $checkpoint_data, $plan_execute );
	}

	private function resolve_run_storage_message(
		bool $is_plan_run,
		string $original_message,
		string $execution_message,
		array $conversation,
		array $checkpoint_data,
		bool $plan_execute = false
	): string {
		return $this->request_compiler->resolve_run_storage_message(
			$is_plan_run,
			$original_message,
			$execution_message,
			$conversation,
			$checkpoint_data,
			$plan_execute
		);
	}

	private function find_prior_user_goal_message( array $conversation ): string {
		return $this->request_compiler->find_prior_user_goal_message( $conversation );
	}

	private function strip_continuation_envelope( string $message ): string {
		return $this->request_compiler->strip_continuation_envelope( $message );
	}

	private function is_continuation_message( string $message ): bool {
		return $this->request_compiler->is_continuation_message( $message );
	}

	/**
	 * Extract total token count from raw AI response.
	 */
	private function extract_token_count( array $raw_response ): int {
		// OpenAI/OpenRouter/DeepSeek format
		if ( isset( $raw_response['usage']['total_tokens'] ) ) {
			return (int) $raw_response['usage']['total_tokens'];
		}
		// Anthropic format
		if ( isset( $raw_response['usage'] ) ) {
			$input  = (int) ( $raw_response['usage']['input_tokens']  ?? 0 );
			$output = (int) ( $raw_response['usage']['output_tokens'] ?? 0 );
			return $input + $output;
		}
		// Fallback estimate based on message length
		$msg_len = mb_strlen( $raw_response['message'] ?? '' );
		return max( 1500, (int) ceil( $msg_len / 4 ) + 1000 );
	}

	/**
	 * Extract normalized token usage details from an AI response.
	 *
	 * @param array $raw_response Raw or normalized AI response payload.
	 * @return array{total_tokens:int,input_tokens:int,output_tokens:int,cache_read_tokens:int,cache_write_tokens:int}
	 */
	private function extract_usage_breakdown( array $raw_response ): array {
		$usage = is_array( $raw_response['usage'] ?? null ) ? $raw_response['usage'] : array();

		return array(
			'total_tokens'      => $this->extract_token_count( $raw_response ),
			'input_tokens'      => (int) ( $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0 ),
			'output_tokens'     => (int) ( $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0 ),
			'cache_read_tokens' => (int) ( $usage['cache_read_tokens'] ?? $usage['cache_read'] ?? 0 ),
			'cache_write_tokens' => (int) ( $usage['cache_write_tokens'] ?? $usage['cache_write'] ?? 0 ),
		);
	}

	/**
	 * Get the client's IP address, accounting for reverse proxies.
	 */
	private function get_client_ip(): string {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = strtok( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), ',' );
				if ( filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ) {
					return trim( $ip );
				}
			}
		}
		return '127.0.0.1';
	}

	/**
	 * Generate preview data for a write action.
	 *
	 * Delegates to the owning handler via PressArk_Preview_Builder.
	 * Kept as a public method for backward compatibility (callable reference).
	 *
	 * @since 4.5.0 Delegates to Preview_Builder instead of inline switch.
	 */
	public function generate_preview( array $action ): array {
		return PressArk_Preview_Builder::instance()->build( $action );
	}

	/**
	 * Handle onboarded flag.
	 */
	public function handle_onboarded( WP_REST_Request $request ): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'pressark_onboarded', '1' );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Handle undo request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_undo( WP_REST_Request $request ): WP_REST_Response {
		$log_id = (int) $request->get_param( 'log_id' );

		$logger = new PressArk_Action_Logger();
		$result = $logger->undo( $log_id );

		return new WP_REST_Response( $result, 200 );
	}

	// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Chat History Handlers ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

	/**
	 * List all chats for the current user.
	 */
	public function handle_list_chats( WP_REST_Request $request ): WP_REST_Response {
		$history = new PressArk_Chat_History();
		return rest_ensure_response( $history->list_chats() );
	}

	/**
	 * Save (create or update) a chat.
	 */
	public function handle_save_chat( WP_REST_Request $request ): WP_REST_Response {
		$history  = new PressArk_Chat_History();
		$chat_id  = absint( $request->get_param( 'chat_id' ) ?? 0 );
		$title    = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
		$messages = $request->get_param( 'messages' );

		if ( ! is_array( $messages ) ) {
			$messages = array();
		}

		// Sanitize saved messages with the same role/content discipline
		// as live chat requests to prevent stored XSS.
		$messages = PressArk_Chat_History::sanitize_messages( $messages );

		if ( $chat_id > 0 ) {
			// Update existing chat.
			$success = $history->update_chat( $chat_id, $messages, $title ?: null );
			return rest_ensure_response( array(
				'success' => $success,
				'chat_id' => $chat_id,
			) );
		}

		// Create new chat.
		if ( empty( $title ) && ! empty( $messages ) ) {
			$first_user = '';
			foreach ( $messages as $m ) {
				if ( 'user' === ( $m['role'] ?? '' ) ) {
					$first_user = $m['content'] ?? '';
					break;
				}
			}
			$title = PressArk_Chat_History::generate_title( $first_user );
		}

		$new_id = $history->create_chat( $title ?: __( 'New Chat', 'pressark' ), $messages );

		return rest_ensure_response( array(
			'success' => (bool) $new_id,
			'chat_id' => $new_id ?: 0,
		) );
	}

	/**
	 * Get a single chat by ID.
	 */
	public function handle_get_chat( WP_REST_Request $request ): WP_REST_Response {
		$history = new PressArk_Chat_History();
		$chat    = $history->get_chat( absint( $request['id'] ) );

		if ( ! $chat ) {
			return new WP_REST_Response( array( 'error' => 'Chat not found' ), 404 );
		}

		return rest_ensure_response( $chat );
	}

	/**
	 * Delete a chat by ID.
	 */
	public function handle_delete_chat( WP_REST_Request $request ): WP_REST_Response {
		$history = new PressArk_Chat_History();
		$deleted = $history->delete_chat( absint( $request['id'] ) );
		if ( $deleted && class_exists( 'PressArk_Context_Collector' ) ) {
			PressArk_Context_Collector::clear_cache();
		}

		return rest_ensure_response( array( 'success' => $deleted ) );
	}

	/**
	 * Fallback formatter when AI interpretation fails.
	 */
	private function format_scanner_fallback( array $data, string $type ): string {
		if ( 'seo' === $type ) {
			if ( isset( $data['average_score'] ) ) {
				// Site-wide scan.
				$lines = array();
				$lines[] = sprintf(
					/* translators: 1: SEO letter grade, 2: SEO score out of 100. */
					__( 'SEO Site Scan: %1$s (%2$d/100)', 'pressark' ),
					$data['average_grade'] ?? '?',
					$data['average_score'] ?? 0
				);
				$lines[] = sprintf(
					/* translators: 1: total scanned pages, 2: number of critical issues. */
					__( 'Pages scanned: %1$d | Critical issues: %2$d', 'pressark' ),
					$data['total_pages'] ?? 0,
					$data['critical_issues'] ?? 0
				);

				if ( ! empty( $data['top_issues'] ) ) {
					$lines[] = '';
					$lines[] = __( 'Top issues:', 'pressark' );
					foreach ( $data['top_issues'] as $issue => $count ) {
						$lines[] = sprintf( '  - %s: %d page(s)', $issue, $count );
					}
				}

				return implode( "\n", $lines );
			} else {
				// Single page scan.
				$lines   = array();
				$lines[] = sprintf(
					/* translators: 1: SEO letter grade, 2: SEO score out of 100, 3: page title. */
					__( 'SEO Score: %1$s (%2$d/100) for "%3$s"', 'pressark' ),
					$data['grade'] ?? '?',
					$data['score'] ?? 0,
					$data['post_title'] ?? ''
				);

				foreach ( $data['checks'] ?? array() as $check ) {
					$icon    = match ( $check['status'] ?? '' ) {
						'pass'    => "\xE2\x9C\x85",
						'warning' => "\xE2\x9A\xA0\xEF\xB8\x8F",
						'fail'    => "\xE2\x9D\x8C",
						default   => '-',
					};
					$lines[] = sprintf( '%s %s: %s', $icon, $check['name'] ?? '', $check['message'] ?? '' );
				}

				return implode( "\n", $lines );
			}
		}

		if ( 'security' === $type ) {
			$lines   = array();
			$lines[] = sprintf(
				/* translators: 1: security letter grade, 2: security score out of 100. */
				__( 'Security Score: %1$s (%2$d/100)', 'pressark' ),
				$data['grade'] ?? '?',
				$data['score'] ?? 0
			);
			$lines[] = sprintf(
				/* translators: 1: passed checks, 2: warnings, 3: failed checks. */
				__( 'Passed: %1$d | Warnings: %2$d | Failed: %3$d', 'pressark' ),
				$data['passed'] ?? 0,
				$data['warnings'] ?? 0,
				$data['failed'] ?? 0
			);

			foreach ( $data['checks'] ?? array() as $check ) {
				$icon    = match ( $check['status'] ?? '' ) {
					'pass'    => "\xE2\x9C\x85",
					'warning' => "\xE2\x9A\xA0\xEF\xB8\x8F",
					'fail'    => "\xE2\x9D\x8C",
					default   => '-',
				};
				$lines[] = sprintf( '%s %s: %s', $icon, $check['name'] ?? '', $check['message'] ?? '' );
			}

			if ( ! empty( $data['summary'] ) ) {
				$lines[] = '';
				$lines[] = $data['summary'];
			}

			return implode( "\n", $lines );
		}

		return wp_json_encode( $data );
	}
}
