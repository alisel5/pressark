<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers REST API endpoints for the PressArk chat, confirm, and undo.
 */
class PressArk_Chat {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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

	/**
	 * Cancel the active run for a given chat.
	 *
	 * Called when the user clicks Stop in the chat panel. Marks the
	 * target run as failed so the Activity page reflects the
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
				$run_store->fail( $run_id_param, 'User cancelled' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}

		// 2. Fallback: lookup by chat_id.
		if ( $chat_id ) {
			$found_run_id = $run_store->find_latest_cancellable_run_id( $user_id, $chat_id );
			if ( $found_run_id ) {
				$run_store->fail( $found_run_id, 'User cancelled' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}

		// 3. Last resort: cancel the user's most recent cancellable run.
		//    Covers very-fast-cancel (Stop pressed before run_started SSE).
		if ( ! $run_id_param && ! $chat_id ) {
			$found_run_id = $run_store->find_latest_cancellable_run_id( $user_id );
			if ( $found_run_id ) {
				$run_store->fail( $found_run_id, 'User cancelled' );
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
		if ( ! is_array( $conversation ) ) {
			return array();
		}

		$clean = array();
		foreach ( $conversation as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}
			$role = sanitize_text_field( $msg['role'] ?? '' );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$content = $msg['content'] ?? '';
			if ( ! is_string( $content ) ) {
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => $this->sanitize_conversation_content( $content ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize conversation content preserving code/HTML.
	 * Strips control characters and invalid UTF-8 but keeps angle brackets,
	 * line breaks, and whitespace ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â same logic as the message input field.
	 */
	private function sanitize_conversation_content( string $content ): string {
		$content = wp_check_invalid_utf8( $content );
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );
		// Cap individual message length for safety.
		if ( mb_strlen( $content ) > 50000 ) {
			$content = mb_substr( $content, 0, 50000 );
		}
		return $content;
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
		// Paid tiers and users with remaining writes always pass.
		if ( PressArk_Entitlements::can_write( $tier ) ) {
			return null;
		}

		// Free tier with exhausted writes: block obvious write-intent messages
		// to save token budget. Reads still pass through.
		$write_patterns = '/\b(edit|update|change|modify|delete|fix|create|add|remove|publish|replace|rewrite|apply|set|enable|disable|install|activate|deactivate|send|moderate|assign|switch|toggle|cleanup|optimize|rebuild|clear)\b/i';

		if ( preg_match( $write_patterns, $message ) ) {
			$limit_msg = PressArk_Entitlements::limit_message( 'write_limit', $tier );
			return new WP_REST_Response( array(
				'reply'             => $limit_msg,
				'actions_performed' => array(
					array(
						'type'           => 'limit_reached',
						'success'        => false,
						'message'        => __( 'Free edit limit reached.', 'pressark' ),
						'upgrade_prompt' => true,
					),
				),
				'pending_actions'   => array(),
				'usage'             => $tracker->get_usage_data(),
				'plan_info'         => PressArk_Entitlements::get_plan_info( $tier ),
			), 200 );
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
		try {
			return $this->process_chat( $request );
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

	/**
	 * Pre-flight steps 1-6: sanitize, write check, throttle, reserve, route, acquire slot.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->chat_lock_name ) );
		$this->chat_lock_name = '';
	}

	private function preflight( WP_REST_Request $request ): array|WP_REST_Response {
		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [1] Sanitize ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$message      = $request->get_param( 'message' );
		$conversation = $this->sanitize_conversation( $request->get_param( 'conversation' ) );
		$screen       = $request->get_param( 'screen' );
		$post_id      = (int) $request->get_param( 'post_id' );

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
			$chat_title   = PressArk_Chat_History::generate_title( (string) $message );
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

		$post_id = $this->resolve_effective_post_id( (string) $message, $post_id, $checkpoint_data );

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
		$write_block = $this->quick_write_check( $tier, $message, $tracker );
		if ( $write_block ) {
			return $write_block;
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
		$reservation    = new PressArk_Reservation();
		$reserve_model  = PressArk_Model_Policy::resolve( $tier, $deep_mode );
		$estimated_raw  = $reservation->estimate_tokens( $message, $conversation, $tier );
		$estimated      = $reservation->estimate_icus( $message, $conversation, $tier, $reserve_model );
		$reserve_result = $reservation->reserve( $user_id, $estimated, 'pending', $tier, $reserve_model, $estimated_raw );

		if ( ! $reserve_result['ok'] ) {
			if ( 'token_limit_reached' === ( $reserve_result['error'] ?? '' ) ) {
				$token_bank = new PressArk_Token_Bank();
				$status     = $token_bank->get_status();
				return new WP_REST_Response( array(
					'error'            => 'token_limit_reached',
					'message'          => PressArk_Entitlements::limit_message( 'token_budget', $tier ),
					'percent_used'     => $status['percent_used'] ?? 100,
					'upgrade_url'      => pressark_get_upgrade_url(),
					'credit_store_url' => admin_url( 'admin.php?page=pressark#pressark-credit-store' ),
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
			)
		);

		// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ [5] Unified routing ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
		$connector = new PressArk_AI_Connector( $tier );
		$logger    = new PressArk_Action_Logger();
		$engine    = new PressArk_Action_Engine( $logger );

		$routing = PressArk_Router::resolve( $message, $conversation, $connector, $engine, $tier, $deep_mode, $screen, $post_id );
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => $correlation_id,
				'reservation_id' => $reservation_id,
				'route'          => (string) ( $routing['route'] ?? '' ),
			)
		);

		// [5a] Async early return.
		if ( PressArk_Router::ROUTE_ASYNC === $routing['route'] ) {
			$queue            = new PressArk_Task_Queue();
			$handoff_capsule  = PressArk_Task_Queue::build_handoff_capsule(
				$message,
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
					'message'         => $message,
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
				$message,
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
		$pipeline = new PressArk_Pipeline( $reservation, $tracker, $throttle, $tier, $plan_info );
		$pipeline->register_resources( $reservation_id, $user_id, true, $slot_id );

		$run_store = new PressArk_Run_Store();
		$run_id    = $run_store->create( array(
			'user_id'        => $user_id,
			'chat_id'        => $chat_id,
			'route'          => $routing['route'],
			'message'        => $message,
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
			'message'         => $message,
			'conversation'    => $conversation,
			'screen'          => $screen,
			'post_id'         => $post_id,
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
		$preflight = $this->preflight( $request );

		if ( $preflight instanceof WP_REST_Response ) {
			// Preflight failed ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â send as JSON (headers not committed yet).
			wp_send_json( $preflight->get_data(), $preflight->get_status() );
			return;
		}

		$emitter = new PressArk_SSE_Emitter();
		$emitter->start();

		try {
			$this->process_chat_stream( $preflight, $emitter );
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'Chat', 'Stream request failed', array( 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ) );
			$emitter->emit( 'error', array( 'message' => 'Internal error. Please try again.' ) );
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
		$last_status     = 'running';

		return static function () use ( $run_store, $run_id, &$last_checked_at, &$last_status ): bool {
			$now = microtime( true );

			if ( ( $now - $last_checked_at ) >= 0.25 ) {
				$status = $run_store->get_status( $run_id );
				if ( is_string( $status ) && '' !== $status ) {
					$last_status = $status;
				}
				$last_checked_at = $now;
			}

			return 'running' !== $last_status;
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
	private function mark_result_cancelled( array $result ): array {
		unset(
			$result['pending_actions'],
			$result['preview_session_id'],
			$result['preview_url'],
			$result['diff'],
			$result['workflow_state']
		);

		$result['type']      = 'final_response';
		$result['message']   = '';
		$result['cancelled'] = true;

		return $result;
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

		// Emit run metadata immediately so the frontend can cancel by run_id
		// even on the first message (before it knows the chat_id).
			$emitter->emit( 'run_started', array(
				'run_id'         => $ctx['run_id'],
				'correlation_id' => $ctx['correlation_id'] ?? '',
				'chat_id'        => $ctx['chat_id'],
			) );

		try {
			if ( PressArk_Router::ROUTE_AGENT === $routing['route'] ) {
				// Agent path ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â full streaming support.
				$stream_connector = new PressArk_Stream_Connector( $ctx['connector'], $emitter, $run_cancel_check );
				$agent = new PressArk_Agent( $ctx['connector'], $ctx['engine'], $ctx['tier'] );
				$agent->set_run_context( $ctx['run_id'], (int) $ctx['chat_id'] );

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
				$result = $this->mark_result_cancelled( $result );
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

			// Persist run state on approval boundaries.
			$result_type = $result['type'] ?? 'final_response';

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
				$cp->touch();
				$cp->save( $ctx['chat_id'], $ctx['user_id'] );
			}

			// Finalize (settle tokens, track, release slot).
			$finalized = $ctx['pipeline']->finalize( $result, $routing['route'] );

			// Determine run outcome ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â cancelled beats settled.
			$cancelled = ! empty( $result['cancelled'] ) || connection_aborted();

			if ( $cancelled ) {
				$run_store->fail( $ctx['run_id'], 'User cancelled' );
			} elseif ( ! in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
				$run_store->settle( $ctx['run_id'], $result );
			}

			// Build the final done payload (same shape as /chat JSON response).
			$response_data = $finalized['response']->get_data();
			$response_data['chat_id'] = $ctx['chat_id'];

			// Only emit if the client is still listening.
			if ( ! $cancelled ) {
				$emitter->emit( 'done', $response_data );
			}

		} catch ( \Throwable $e ) {
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

		try {
			$agent = new PressArk_Agent( $ctx['connector'], $ctx['engine'], $ctx['tier'] );
			$agent->set_run_context( $run_id, (int) $chat_id );

			$result = match ( $routing['route'] ) {
				PressArk_Router::ROUTE_AGENT  => $agent->run(
					$ctx['message'], $ctx['conversation'], $ctx['deep_mode'], $ctx['screen'], $ctx['post_id'], $ctx['loaded_groups'], $ctx['checkpoint_data'], $run_cancel_check ),
				PressArk_Router::ROUTE_LEGACY => $this->run_legacy_raw(
					$ctx['message'], $ctx['conversation'], $ctx['deep_mode'], $ctx['post_id'], $ctx['screen'], $ctx['connector'] ),
			};

			if ( ! empty( $result['cancelled'] ) || $run_cancel_check() ) {
				$result = $this->mark_result_cancelled( $result );
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
				$cp->touch();
				$cp->save( $chat_id, $ctx['user_id'] );
			}

			// Finalize (settle tokens, track, release slot).
			$finalized = $pipeline->finalize( $result, $routing['route'] );

			if ( ! empty( $result['cancelled'] ) ) {
				$run_store->fail( $run_id, 'User cancelled' );
			} elseif ( ! in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
				$run_store->settle( $run_id, $result );
			}

			$response = $finalized['response'];
			$data     = $response->get_data();
			$data['chat_id'] = $chat_id;
			$response->set_data( $data );

			return $response;
		} catch ( \Throwable $e ) {
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

		if ( PressArk_Agent::is_lightweight_chat_request( $message, $conversation ) ) {
			$ai_result       = $connector->send_lightweight_chat( $message, $conversation, $deep_mode );
			$usage_breakdown = $this->extract_usage_breakdown( $ai_result );
			$routing_decision = (array) ( $ai_result['routing_decision'] ?? $connector->get_last_routing_decision() );

			if ( ! empty( $ai_result['error'] ) ) {
				return array(
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
				);
			}

			return array(
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
			);
		}

		// Build compact context ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â dynamic part only (cached part in connector).
		$context      = new PressArk_Context();
		$context_text = $context->build( $screen, $post_id );
		$context_text .= PressArk_Handler_Discovery::format_site_notes_basic();

		// v3.3.0: Inject retrieval-grounded content from the content index.
		$content_index     = new PressArk_Content_Index();
		$retrieval_context = $content_index->get_relevant_context( $message );
		if ( ! empty( $retrieval_context ) ) {
			$context_text .= "\n\n" . $retrieval_context;
		}

		$compressed_history = PressArk_History_Manager::prepare( $conversation, $deep_mode );

		$ai_result       = $connector->send_message( $message, $context_text, $compressed_history, $deep_mode );
		$usage_breakdown = $this->extract_usage_breakdown( $ai_result );
		$routing_decision = (array) ( $ai_result['routing_decision'] ?? $connector->get_last_routing_decision() );

		if ( ! empty( $ai_result['error'] ) ) {
			return array(
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
			);
		}

		// If AI returned parsed actions, separate reads from writes.
		$pending          = array();
		$performed        = array();
		$preview_actions  = array();
		$read_supplements = '';
		$scanner_types    = array();

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

				$action_class = PressArk_Agent::classify_tool( $action_type, $action['params'] ?? array() );

				if ( 'read' === $action_class ) {
					$read_result = $engine_legacy->execute_read( $action_type, $action['params'] ?? array() );

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
						'arguments' => $action['params'] ?? array(),
					);
				} else {
					// Write actions ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â pending_actions raw format for pipeline.
					$pending[] = array(
						'name'      => $action['type'] ?? '',
						'arguments' => $action['params'] ?? array(),
					);
				}
			}
		}

		$reply_text = $ai_result['message'] ?? '';

		// Follow-up call: interpret scan/analysis results.
		if ( ! empty( $read_supplements ) && ! empty( $scanner_types ) ) {
			$scan_label      = implode( ' and ', array_unique( $scanner_types ) );
			$followup_result = $connector->send_scanner_followup(
				trim( $read_supplements ),
				$scan_label,
				$compressed_history
			);

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
		if ( ! empty( $preview_actions ) ) {
			$preview = new PressArk_Preview();
			$session = $preview->create_session( $preview_actions, $preview_actions[0]['arguments'] ?? array() );

			return array(
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
				'preview_session_id' => $session['session_id'],
				'preview_url'        => $session['signed_url'],
				'diff'               => $session['diff'],
			);
		}

		$response_type = ! empty( $pending ) ? 'confirm_card' : 'final_response';

		return array(
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
		);
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

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Cancellation ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			if ( ! $confirmed ) {
				if ( ! empty( $run_id ) ) {
					$run_store = new PressArk_Run_Store();
					$run       = $run_store->get( $run_id );
					if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
						$this->hydrate_trace_context_from_run( $run );
						$run_store->settle( $run_id, array( 'cancelled' => true ) );
					}
				}
				return rest_ensure_response( array(
					'success'        => true,
					'message'        => __( 'No changes were made. The action was cancelled.', 'pressark' ),
					'cancelled'      => true,
					'correlation_id' => (string) ( $run['correlation_id'] ?? '' ),
				) );
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
					'upgrade_prompt' => true,
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

			// Load the action from server-persisted pending_actions.
			$action = $pending[ $action_index ]['action'] ?? $pending[ $action_index ];

			// Execute the action.
			$logger = new PressArk_Action_Logger();
			$engine = new PressArk_Action_Engine( $logger );
			$result = $engine->execute_single( $action );

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
				unset( $action_args['type'], $action_args['description'] );
			}

			$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
			$checkpoint->record_execution_write( (string) ( $action['type'] ?? '' ), $action_args, $result );
			if ( ! empty( $result['success'] ) ) {
				$checkpoint->clear_blockers();
				$checkpoint->add_approval( (string) ( $action['type'] ?? 'confirmed_action' ) );
				if ( $all_resolved ) {
					$checkpoint->set_workflow_stage( 'settled' );
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
								$eval = PressArk_Verification::evaluate( $policy, $action_args, $readback_result );
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

			// Track write usage.
			$tracker = new PressArk_Usage_Tracker();
			$tracker->increment_if_write( 'preview_apply' );

			// ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v3.1.0: Settle durable run via pipeline authority ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get_by_preview_session( $session_id );

			if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
				$this->hydrate_trace_context_from_run( $run );
				$result = PressArk_Pipeline::settle_run( $run['run_id'], $result );

				$checkpoint = $this->load_or_bootstrap_run_checkpoint( $run );
				$checkpoint->record_execution_preview( $session_calls, $result );
				foreach ( $session_calls as $call ) {
					$checkpoint->add_approval( (string) ( $call['name'] ?? $call['type'] ?? 'preview_apply' ) );
				}
				if ( ! empty( $result['success'] ) ) {
					$checkpoint->clear_blockers();
					$checkpoint->set_workflow_stage( 'settled' );

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
								$eval_v = PressArk_Verification::evaluate( $policy_v, $args_v, $readback_result_v );
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
				} else {
					$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Preview apply failed.' ) );
				}
				$this->persist_run_checkpoint( $run, $checkpoint );
				$result['checkpoint'] = $checkpoint->to_array();
				$result = $this->attach_continuation_context( $result, $run, $checkpoint );
				$this->persist_run_detail_snapshot( $run, $result, $checkpoint );
			}

			$tier = ( new PressArk_License() )->get_tier();
			$result['usage']     = $tracker->get_usage_data();
			$result['plan_info'] = PressArk_Entitlements::get_plan_info( $tier );
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

			$preview = new PressArk_Preview();
			$result  = $preview->discard( $session_id );

			// v3.1.0: Settle the run as discarded.
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get_by_preview_session( $session_id );
			if ( $run && (int) $run['user_id'] === get_current_user_id() ) {
				$this->hydrate_trace_context_from_run( $run );
				$run_store->settle( $run['run_id'], array( 'discarded' => true ) );
				$result['correlation_id'] = (string) ( $run['correlation_id'] ?? '' );
			}

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

		$checkpoint->sync_execution_goal( (string) ( $run['message'] ?? '' ) );
		if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
			$checkpoint->absorb_run_snapshot( $run['workflow_state'] );
		}
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

			$result['continuation']['execution']          = $execution;
			$result['continuation']['progress']           = $progress;
			$result['continuation']['blockers']           = $blockers;
			$result['continuation']['should_auto_resume'] = ! empty( $progress['should_auto_resume'] ) && empty( $blockers );
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

		return $result;
	}

	/**
	 * Continuation requests must inherit the execution target from server-owned
	 * checkpoint state instead of relying on the current editor screen.
	 */
	private function resolve_effective_post_id( string $message, int $post_id, array $checkpoint_data ): int {
		if ( ! $this->is_continuation_message( $message ) ) {
			return $post_id;
		}

		$execution = is_array( $checkpoint_data['execution'] ?? null )
			? $checkpoint_data['execution']
			: array();
		$target_id = PressArk_Execution_Ledger::current_target_post_id( $execution );
		if ( $target_id > 0 ) {
			return $target_id;
		}

		if ( preg_match( '/\bpost_id\s*=\s*(\d+)\b/i', $message, $match ) ) {
			return absint( $match[1] );
		}

		return $post_id;
	}

	private function is_continuation_message( string $message ): bool {
		return 1 === preg_match( '/^\[(?:Continue|Confirmed)\]/i', trim( $message ) );
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