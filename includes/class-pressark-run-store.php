<?php
/**
 * PressArk Run Store — Durable execution record persistence.
 *
 * Every sync chat request creates a "run" that owns the full lifecycle:
 * routing, execution, approval boundaries (preview/confirm), post-apply
 * verification, and settlement. The run record is server-owned truth —
 * the frontend never holds state that the server cannot recover.
 *
 * Status transitions:
 *   running → awaiting_preview → settled|failed
 *   running → awaiting_confirm → partially_confirmed → settled|failed
 *   running → settled|failed
 *
 * @package PressArk
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Run_Store {

	/**
	 * Get the fully-prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_runs';
	}

	/**
	 * DDL for dbDelta (called from pressark_activate and upgrade migration).
	 */
	public static function get_schema(): string {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(64) NOT NULL,
			parent_run_id VARCHAR(64) DEFAULT NULL,
			root_run_id VARCHAR(64) DEFAULT NULL,
			correlation_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL,
			chat_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			task_id VARCHAR(64) DEFAULT NULL,
			route VARCHAR(20) NOT NULL DEFAULT 'agent',
			status VARCHAR(30) NOT NULL DEFAULT 'running',
			message TEXT NOT NULL,
			handoff_capsule LONGTEXT DEFAULT NULL,
			reservation_id VARCHAR(64) NOT NULL DEFAULT '',
			workflow_class VARCHAR(100) DEFAULT NULL,
			workflow_state LONGTEXT DEFAULT NULL,
			preview_session_id VARCHAR(64) DEFAULT NULL,
			pending_actions LONGTEXT DEFAULT NULL,
			result LONGTEXT DEFAULT NULL,
			error_summary VARCHAR(255) DEFAULT NULL,
			tier VARCHAR(20) NOT NULL DEFAULT 'free',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			settled_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_run_id (run_id),
			KEY idx_parent_run_created (parent_run_id, created_at),
			KEY idx_root_run_created (root_run_id, created_at),
			KEY idx_correlation_id (correlation_id),
			KEY idx_user_status (user_id, status),
			KEY idx_chat_id (chat_id),
			KEY idx_task_id (task_id),
			KEY idx_preview_session (preview_session_id),
			KEY idx_created (created_at)
		) {$charset_collate};";
	}

	// ── CRUD ─────────────────────────────────────────────────────────

	/**
	 * Insert a new run row.
	 *
	 * @param array $data Keys: run_id, user_id, route, message, reservation_id, tier.
	 * @return string The run_id.
	 */
	public function create( array $data ): string {
		global $wpdb;

		$run_id         = $data['run_id'] ?? wp_generate_uuid4();
		$parent_run_id  = sanitize_text_field( (string) ( $data['parent_run_id'] ?? '' ) );
		$root_run_id    = sanitize_text_field( (string) ( $data['root_run_id'] ?? '' ) );
		$correlation_id = ! empty( $data['correlation_id'] )
			? PressArk_Activity_Trace::normalize_correlation_id( (string) $data['correlation_id'] )
			: PressArk_Activity_Trace::new_correlation_id();
		$handoff_capsule = ! empty( $data['handoff_capsule'] ) && is_array( $data['handoff_capsule'] )
			? wp_json_encode( $data['handoff_capsule'] )
			: '';

		if ( '' === $root_run_id ) {
			$root_run_id = '' !== $parent_run_id ? $parent_run_id : $run_id;
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'run_id'         => $run_id,
				'parent_run_id'  => $parent_run_id,
				'root_run_id'    => $root_run_id,
				'correlation_id' => $correlation_id,
				'user_id'        => absint( $data['user_id'] ?? get_current_user_id() ),
				'chat_id'        => absint( $data['chat_id'] ?? 0 ),
				'route'          => sanitize_key( $data['route'] ?? 'agent' ),
				'status'         => 'running',
				'message'        => $data['message'] ?? '',
				'handoff_capsule' => $handoff_capsule,
				'reservation_id' => sanitize_text_field( $data['reservation_id'] ?? '' ),
				'tier'           => sanitize_key( $data['tier'] ?? 'free' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'run.started',
				'phase'      => 'run',
				'status'     => 'started',
				'reason'     => 'request_started',
				'summary'    => 'Durable run created.',
				'payload'    => array(
					'route'          => sanitize_key( (string) ( $data['route'] ?? 'agent' ) ),
					'chat_id'        => absint( $data['chat_id'] ?? 0 ),
					'tier'           => sanitize_key( (string) ( $data['tier'] ?? 'free' ) ),
					'parent_run_id'  => $parent_run_id,
					'root_run_id'    => $root_run_id,
					'has_handoff'    => '' !== $handoff_capsule,
				),
			),
			array(
				'correlation_id' => $correlation_id,
				'run_id'         => $run_id,
				'reservation_id' => sanitize_text_field( (string) ( $data['reservation_id'] ?? '' ) ),
				'chat_id'        => absint( $data['chat_id'] ?? 0 ),
				'user_id'        => absint( $data['user_id'] ?? get_current_user_id() ),
				'route'          => sanitize_key( (string) ( $data['route'] ?? 'agent' ) ),
			)
		);

		return $run_id;
	}

	/**
	 * Create a queue-native parent/child run family for background work.
	 *
	 * The parent handoff run is intentionally lightweight and hidden from the
	 * main activity list; the child run remains the durable execution unit that
	 * the worker settles, pauses, or fails.
	 *
	 * @param string $worker_route Child worker route (for example async/automation).
	 * @param array  $data         Shared run creation data.
	 * @return array{parent_run_id: string, run_id: string, root_run_id: string}
	 */
	public function create_background_family( string $worker_route, array $data ): array {
		$parent_run_id = $this->create(
			array_merge(
				$data,
				array(
					'route' => 'handoff',
				)
			)
		);

		$child_run_id = $this->create(
			array_merge(
				$data,
				array(
					'route'         => sanitize_key( $worker_route ),
					'parent_run_id' => $parent_run_id,
					'root_run_id'   => $parent_run_id,
				)
			)
		);

		return array(
			'parent_run_id' => $parent_run_id,
			'run_id'        => $child_run_id,
			'root_run_id'   => $parent_run_id,
		);
	}

	/**
	 * Read a run by run_id. Decodes JSON columns.
	 *
	 * @return array|null The run row, or null if not found.
	 */
	public function get( string $run_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE run_id = %s",
				$run_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	/**
	 * Get the current status for a run without decoding large JSON columns.
	 *
	 * Useful for hot cancellation checks inside long-lived requests.
	 *
	 * @param string $run_id Run ID.
	 * @return string|null Current status, or null if the run is missing.
	 */
	public function get_status( string $run_id ): ?string {
		global $wpdb;
		$table = self::table_name();

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE run_id = %s",
				$run_id
			)
		);

		return is_string( $status ) && '' !== $status ? $status : null;
	}

	/**
	 * Find the newest cancellable run for a user, optionally scoped to a chat.
	 *
	 * Cancellable means the run is still in a pre-terminal state, including
	 * late approval-boundary races where the UI stop arrives just after the
	 * run transitions from running to awaiting_preview/awaiting_confirm/
	 * partially_confirmed.
	 *
	 * @param int $user_id User ID.
	 * @param int $chat_id Optional chat ID.
	 * @return string|null Matching run ID, or null when none exists.
	 */
	public function find_latest_cancellable_run_id( int $user_id, int $chat_id = 0 ): ?string {
		global $wpdb;
		$table = self::table_name();

		if ( $chat_id > 0 ) {
			$run_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT run_id FROM {$table}
				 WHERE user_id = %d
				 AND chat_id = %d
				 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')
				 ORDER BY created_at DESC
				 LIMIT 1",
				$user_id,
				$chat_id
			) );
		} else {
			$run_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT run_id FROM {$table}
				 WHERE user_id = %d
				 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')
				 ORDER BY created_at DESC
				 LIMIT 1",
				$user_id
			) );
		}

		return is_string( $run_id ) && '' !== $run_id ? $run_id : null;
	}

	/**
	 * Look up an awaiting-preview run by its preview session ID.
	 *
	 * This is the server-owned lookup that eliminates the need for
	 * the frontend to send back workflow state.
	 *
	 * @param string $session_id Preview session ID.
	 * @return array|null The run row, or null if not found.
	 */
	public function get_by_preview_session( string $session_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE preview_session_id = %s AND status = 'awaiting_preview'",
				$session_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	/**
	 * Find the most recent run awaiting confirmation for a chat.
	 *
	 * Returns the pending_actions array if found, null otherwise.
	 * Used to inject pending-confirm context into follow-up messages
	 * so the model knows actions were NOT yet applied.
	 *
	 * @since 5.2.0
	 * @param int $user_id User ID.
	 * @param int $chat_id Chat ID.
	 * @return array|null Decoded pending_actions, or null.
	 */
	public function get_pending_confirm_actions( int $user_id, int $chat_id ): ?array {
		if ( $chat_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = self::table_name();

		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT pending_actions FROM {$table}
			 WHERE user_id = %d
			 AND chat_id = %d
			 AND status IN ('awaiting_confirm', 'partially_confirmed')
			 ORDER BY created_at DESC
			 LIMIT 1",
			$user_id,
			$chat_id
		) );

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) && ! empty( $decoded ) ? $decoded : null;
	}

	/**
	 * Build the approval-boundary snapshot stored on a run.
	 *
	 * New agent-owned runs persist a full checkpoint snapshot here so preview
	 * and confirm handlers can recover continuation state even when the chat has
	 * not been saved yet. Legacy workflow-era payloads are passed through as-is.
	 *
	 * @param array  $result Execution result about to pause.
	 * @param string $stage  Stage to stamp when the snapshot lacks one.
	 * @return array|null
	 */
	public static function build_pause_state( array $result, string $stage = 'preview' ): ?array {
		$state = is_array( $result['checkpoint'] ?? null ) ? $result['checkpoint'] : array();

		if ( empty( $state ) ) {
			$state = is_array( $result['workflow_state'] ?? null ) ? $result['workflow_state'] : array();
		}

		if ( empty( $state ) ) {
			return null;
		}

		if ( '' !== $stage && empty( $state['workflow_stage'] ) ) {
			$state['workflow_stage'] = sanitize_key( $stage );
		}

		if ( empty( $state['loaded_tool_groups'] ) && ! empty( $result['loaded_groups'] ) && is_array( $result['loaded_groups'] ) ) {
			$state['loaded_tool_groups'] = array_values( array_unique( array_filter(
				array_map( 'sanitize_text_field', $result['loaded_groups'] )
			) ) );
		}

		if ( empty( $state['effective_visible_tools'] ) && ! empty( $result['effective_visible_tools'] ) && is_array( $result['effective_visible_tools'] ) ) {
			$state['effective_visible_tools'] = array_values( array_unique( array_filter(
				array_map( 'sanitize_text_field', $result['effective_visible_tools'] )
			) ) );
		}

		if ( empty( $state['permission_surface'] ) && ! empty( $result['permission_surface'] ) && is_array( $result['permission_surface'] ) ) {
			$state['permission_surface'] = $result['permission_surface'];
		}

		return $state;
	}

	/**
	 * Persist an additive workflow/result snapshot for Activity detail surfaces.
	 *
	 * @param string     $run_id         Run ID.
	 * @param array|null $workflow_state Latest checkpoint or workflow snapshot.
	 * @param array|null $result         Latest result snapshot.
	 * @return bool True when a row was updated.
	 */
	public function persist_detail_snapshot( string $run_id, ?array $workflow_state = null, ?array $result = null ): bool {
		global $wpdb;

		$run_id = sanitize_text_field( $run_id );
		if ( '' === $run_id ) {
			return false;
		}

		$data = array(
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s' );

		if ( null !== $workflow_state ) {
			$data['workflow_state'] = wp_json_encode( $workflow_state );
			$formats[]              = '%s';
		}

		if ( null !== $result ) {
			$data['result'] = wp_json_encode( $result );
			$formats[]      = '%s';
		}

		$rows = $wpdb->update(
			self::table_name(),
			$data,
			array( 'run_id' => $run_id ),
			$formats,
			array( '%s' )
		);

		return $rows >= 1;
	}

	// ── Lifecycle Transitions ────────────────────────────────────────

	/**
	 * Pause at preview boundary: running → awaiting_preview.
	 *
	 * Persists the pause snapshot and preview session ID so the server can
	 * recover continuation state when the user clicks Keep.
	 *
	 * @param string      $run_id             Run ID.
	 * @param string      $preview_session_id Preview session ID from PressArk_Preview.
	 * @param array|null  $pause_state         Serialized pause snapshot.
	 * @param string|null $legacy_workflow_class Legacy workflow class name for historical rows.
	 * @return bool True if transition succeeded.
	 */
	public function pause_for_preview(
		string  $run_id,
		string  $preview_session_id,
		?array  $pause_state = null,
		?string $legacy_workflow_class = null
	): bool {
		global $wpdb;

		$data    = array(
			'status'             => 'awaiting_preview',
			'preview_session_id' => $preview_session_id,
			'updated_at'         => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $pause_state !== null ) {
			$data['workflow_state'] = wp_json_encode( $pause_state );
			$formats[]              = '%s';
		}
		if ( $legacy_workflow_class !== null ) {
			$data['workflow_class'] = $legacy_workflow_class;
			$formats[]              = '%s';
		}

		$rows = $wpdb->update(
			self::table_name(),
			$data,
			array( 'run_id' => $run_id, 'status' => 'running' ),
			$formats,
			array( '%s', '%s' )
		);

		if ( $rows >= 1 ) {
			$this->publish_transition_event(
				$run_id,
				'run.transition',
				'approval',
				'waiting',
				'approval_wait_preview',
				array(
					'status'             => 'awaiting_preview',
					'preview_session_id' => sanitize_text_field( $preview_session_id ),
				),
				'Run paused for preview.'
			);
			return true;
		}

		return false;
	}

	/**
	 * Pause at confirm boundary: running → awaiting_confirm.
	 *
	 * v3.7.2: Added status transition guard — only transitions from 'running'.
	 * Prevents double-pause races and invalid state transitions.
	 *
	 * @param string      $run_id          Run ID.
	 * @param array       $pending_actions Pending actions awaiting confirmation.
	 * @param array|null  $pause_state      Serialized pause snapshot.
	 * @param string|null $legacy_workflow_class Legacy workflow class name for historical rows.
	 * @return bool True if transition succeeded.
	 */
	public function pause_for_confirm(
		string  $run_id,
		array   $pending_actions,
		?array  $pause_state = null,
		?string $legacy_workflow_class = null
	): bool {
		global $wpdb;

		$data    = array(
			'status'          => 'awaiting_confirm',
			'pending_actions' => wp_json_encode( $pending_actions ),
			'updated_at'      => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $pause_state !== null ) {
			$data['workflow_state'] = wp_json_encode( $pause_state );
			$formats[]              = '%s';
		}
		if ( $legacy_workflow_class !== null ) {
			$data['workflow_class'] = $legacy_workflow_class;
			$formats[]              = '%s';
		}

		// v3.7.2: Require status = 'running' (same guard as pause_for_preview).
		$rows = $wpdb->update(
			self::table_name(),
			$data,
			array( 'run_id' => $run_id, 'status' => 'running' ),
			$formats,
			array( '%s', '%s' )
		);

		if ( $rows >= 1 ) {
			$this->publish_transition_event(
				$run_id,
				'run.transition',
				'approval',
				'waiting',
				'approval_wait_confirm',
				array(
					'status'               => 'awaiting_confirm',
					'pending_action_count' => count( $pending_actions ),
				),
				'Run paused for confirmation.'
			);
			return true;
		}

		return false;
	}

	/**
	 * Update the stored pending actions without settling the run.
	 *
	 * Used after one action in a multi-confirm run has been resolved but
	 * other actions are still awaiting user approval.
	 *
	 * @param string $run_id  Run ID.
	 * @param array  $pending Pending actions to persist.
	 * @param string $status  Status to apply while the run remains open.
	 * @return bool True if the update succeeded.
	 */
	public function update_pending( string $run_id, array $pending, string $status = 'partially_confirmed' ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array(
				'pending_actions' => wp_json_encode( $pending ),
				'status'          => $status,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'run_id' => $run_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( $rows ) {
			$this->publish_transition_event(
				$run_id,
				'run.transition',
				'approval',
				'in_progress',
				'approval_partial_progress',
				array(
					'status'               => sanitize_key( $status ),
					'pending_action_count' => count( $pending ),
				),
				'Run pending actions updated.'
			);
			return true;
		}

		return false;
	}

	/**
	 * Mark a run as settled (complete).
	 *
	 * v3.7.2: Transition guard — only settles from valid pre-terminal states
	 * (running, awaiting_preview, awaiting_confirm, partially_confirmed).
	 * Already-settled or
	 * failed runs are silently ignored (idempotent). Uses raw SQL because
	 * wpdb::update() only supports equality WHERE conditions.
	 *
	 * @param string     $run_id Run ID.
	 * @param array|null $result Final result to persist (optional).
	 * @return bool True if transition succeeded.
	 */
	public function settle( string $run_id, ?array $result = null ): bool {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		$result = $this->normalize_terminal_result( $result );

		if ( $result !== null ) {
			$rows = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'settled', updated_at = %s, settled_at = %s, result = %s
				 WHERE run_id = %s
				 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')",
				$now,
				$now,
				wp_json_encode( $result ),
				$run_id
			) );
		} else {
			$rows = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'settled', updated_at = %s, settled_at = %s
				 WHERE run_id = %s
				 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')",
				$now,
				$now,
				$run_id
			) );
		}

		if ( (int) $rows >= 1 ) {
			$reason = is_array( $result )
				? PressArk_Activity_Trace::infer_terminal_reason( $result )
				: 'completed';
			$outcome = $this->result_approval_outcome( $result );

			$this->publish_transition_event(
				$run_id,
				'run.completed',
				'run',
				'succeeded',
				$reason,
				array(
					'status'                 => 'settled',
					'result_type'            => is_array( $result ) ? (string) ( $result['type'] ?? 'final_response' ) : '',
					'approval_outcome'       => (string) ( $outcome['status'] ?? '' ),
					'approval_outcome_actor' => (string) ( $outcome['actor'] ?? '' ),
				),
				'Run settled.'
			);
			return true;
		}

		return false;
	}

	/**
	 * Mark a run as failed.
	 *
	 * v3.7.2: Transition guard — only fails from non-terminal states.
	 * Already-settled or already-failed runs are silently ignored.
	 *
	 * @param string $run_id Run ID.
	 * @param string $reason Failure reason.
	 * @return bool True if transition succeeded.
	 */
	public function fail( string $run_id, string $reason = '', ?array $result = null ): bool {
		global $wpdb;
		$table = self::table_name();

		// Truncate reason for the indexed summary column.
		$summary = mb_substr( $reason, 0, 255 );
		$result  = $this->normalize_terminal_result( $result );
		if ( null === $result ) {
			$result = array( 'fail_reason' => $reason );
		} elseif ( '' !== $reason && ! isset( $result['fail_reason'] ) ) {
			$result['fail_reason'] = $reason;
		}

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'failed', result = %s, error_summary = %s, updated_at = %s
			 WHERE run_id = %s
			 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')",
			wp_json_encode( $result ),
			$summary,
			current_time( 'mysql', true ),
			$run_id
		) );

		if ( (int) $rows >= 1 ) {
			$outcome = $this->result_approval_outcome( $result );
			$reason_key = ! empty( $outcome )
				? PressArk_Activity_Trace::infer_terminal_reason( $result )
				: PressArk_Activity_Trace::infer_failure_reason( $reason );
			$this->publish_transition_event(
				$run_id,
				'run.completed',
				'run',
				'failed',
				$reason_key,
				array(
					'status'                 => 'failed',
					'error_summary'          => $summary,
					'approval_outcome'       => (string) ( $outcome['status'] ?? '' ),
					'approval_outcome_actor' => (string) ( $outcome['actor'] ?? '' ),
				),
				'Run failed.'
			);
			return true;
		}

		return false;
	}

	// ── Linkage ─────────────────────────────────────────────────────

	/**
	 * Link an async task to this run for cross-referencing.
	 *
	 * @since 4.2.0
	 * @param string $run_id  Run ID.
	 * @param string $task_id Task ID.
	 * @return bool
	 */
	public function link_task( string $run_id, string $task_id ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array( 'task_id' => $task_id, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'run_id' => $run_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return $rows !== false;
	}

	/**
	 * Get the runs in a lineage family.
	 *
	 * @param string $root_run_id Root run ID for the family.
	 * @param int    $limit       Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_family( string $root_run_id, int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' === $root_run_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT run_id, parent_run_id, root_run_id, task_id, route, status, message,
				        created_at, updated_at, settled_at
				 FROM {$table}
				 WHERE root_run_id = %s OR run_id = %s
				 ORDER BY created_at ASC
				 LIMIT %d",
				$root_run_id,
				$root_run_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$row['parent_run_id'] = (string) ( $row['parent_run_id'] ?? '' );
				$row['root_run_id']   = (string) ( $row['root_run_id'] ?? '' );
				return $row;
			},
			$rows
		);
	}

	// ── Activity Queries ────────────────────────────────────────────

	/**
	 * Get recent runs for a user, ordered by most recent first.
	 *
	 * Returns a lightweight projection suitable for the admin activity page.
	 * Does NOT include the full result or workflow_state (they can be huge).
	 *
	 * @since 4.2.0
	 * @param int    $user_id     User ID (0 = all users, requires manage_options).
	 * @param int    $limit       Max rows.
	 * @param int    $offset      Pagination offset.
	 * @param string $status_filter Optional status filter (empty = all).
	 * @return array Array of run summary rows.
	 */
	public function get_user_activity(
		int    $user_id = 0,
		int    $limit = 25,
		int    $offset = 0,
		string $status_filter = ''
	): array {
		global $wpdb;
		$table = self::table_name();

		$where = array();
		$args  = array();

		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}

		if ( '' !== $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $status_filter );
		}

		$where[] = "route <> 'handoff'";

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$args[] = max( 1, min( 100, $limit ) );
		$args[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT run_id, user_id, chat_id, task_id, route, status, message,
				        error_summary, tier, created_at, updated_at, settled_at
				 FROM {$table}
				 {$where_sql}
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map( static function ( array $row ): array {
			$row['user_id'] = (int) $row['user_id'];
			$row['chat_id'] = (int) $row['chat_id'];
			return $row;
		}, $rows );
	}

	/**
	 * Count runs matching filters.
	 *
	 * @since 4.2.0
	 */
	public function count_activity( int $user_id = 0, string $status_filter = '' ): int {
		global $wpdb;
		$table = self::table_name();

		$where = array();
		$args  = array();

		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}

		if ( '' !== $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $status_filter );
		}

		$where[] = "route <> 'handoff'";

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		if ( ! empty( $args ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} {$where_sql}",
				...$args
			) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * Status counts for the activity dashboard.
	 *
	 * @since 4.2.0
	 */
	public function status_counts( int $user_id = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$where_parts = array( "route <> 'handoff'" );
		if ( $user_id > 0 ) {
			$where_parts[] = $wpdb->prepare( 'user_id = %d', $user_id );
		}
		$where = 'WHERE ' . implode( ' AND ', $where_parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, $where is either empty or already prepared.
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$table} {$where} GROUP BY status",
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ $row['status'] ] = (int) $row['cnt'];
		}
		return $counts;
	}

	// ── Cleanup ──────────────────────────────────────────────────────

	/**
	 * Delete expired runs.
	 *
	 * v4.2.0: Extended retention for settled runs from 24h to 14 days
	 * so the Activity page has meaningful history. Stale awaiting runs
	 * are now failed (not deleted) so they appear in the activity feed.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_expired(): int {
		global $wpdb;
		$table   = self::table_name();
		$deleted = 0;

		// Settled runs older than 14 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, status is a hardcoded constant.
		$deleted += (int) $wpdb->query(
			"DELETE FROM {$table}
			 WHERE status = 'settled'
			 AND settled_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 14 DAY )"
		);

		// Failed runs older than 30 days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, status is a hardcoded constant.
		$deleted += (int) $wpdb->query(
			"DELETE FROM {$table}
			 WHERE status = 'failed'
			 AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY )"
		);

		// Stale awaiting runs older than 2 hours — fail them individually so the
		// stored result and activity trace preserve a typed expired outcome.
		$expired_run_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, all values are hardcoded constants.
			"SELECT run_id FROM {$table}
			 WHERE status IN ('awaiting_preview', 'awaiting_confirm', 'partially_confirmed')
			 AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 2 HOUR )"
		);
		foreach ( (array) $expired_run_ids as $expired_run_id ) {
			$this->fail(
				(string) $expired_run_id,
				'Preview or confirm boundary expired after 2 hours',
				array(
					'success'          => false,
					'type'             => 'final_response',
					'is_error'         => true,
					'message'          => 'Preview or confirmation expired before any changes were applied.',
					'approval_outcome' => class_exists( 'PressArk_Permission_Decision' )
						? PressArk_Permission_Decision::approval_outcome(
							PressArk_Permission_Decision::OUTCOME_EXPIRED,
							array(
								'source'      => 'run_cleanup',
								'actor'       => 'system',
								'reason_code' => 'approval_expired',
							)
						)
						: array(),
				)
			);
		}

		return $deleted;
	}

	// ── Internal ─────────────────────────────────────────────────────

	/**
	 * Normalize settlement results so terminal rows share one typed outcome shape.
	 *
	 * @param array|null $result Terminal result payload.
	 * @return array|null
	 */
	private function normalize_terminal_result( ?array $result ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}

		if ( ! empty( $result['approval_outcome'] ) && is_array( $result['approval_outcome'] ) && class_exists( 'PressArk_Permission_Decision' ) ) {
			$result['approval_outcome'] = PressArk_Permission_Decision::normalize_approval_outcome( $result['approval_outcome'] );
		} elseif ( ! empty( $result['cancelled'] ) && class_exists( 'PressArk_Permission_Decision' ) ) {
			$result['approval_outcome'] = PressArk_Permission_Decision::approval_outcome(
				PressArk_Permission_Decision::OUTCOME_CANCELLED,
				array(
					'source'      => 'run_store',
					'actor'       => 'user',
					'reason_code' => 'user_cancelled',
				)
			);
		} elseif ( ! empty( $result['discarded'] ) && class_exists( 'PressArk_Permission_Decision' ) ) {
			$result['approval_outcome'] = PressArk_Permission_Decision::approval_outcome(
				PressArk_Permission_Decision::OUTCOME_DISCARDED,
				array(
					'source'      => 'run_store',
					'actor'       => 'user',
					'reason_code' => 'user_discarded',
				)
			);
		}

		return $result;
	}

	/**
	 * Extract the normalized approval outcome from a terminal result.
	 *
	 * @param array|null $result Terminal result payload.
	 * @return array
	 */
	private function result_approval_outcome( ?array $result ): array {
		if ( ! is_array( $result ) || empty( $result['approval_outcome'] ) || ! is_array( $result['approval_outcome'] ) ) {
			return array();
		}

		if ( class_exists( 'PressArk_Permission_Decision' ) ) {
			return PressArk_Permission_Decision::normalize_approval_outcome( $result['approval_outcome'] );
		}

		return $result['approval_outcome'];
	}

	/**
	 * Decode JSON columns in a run row.
	 */
	private function decode_row( array $row ): array {
		$row['parent_run_id']  = (string) ( $row['parent_run_id'] ?? '' );
		$row['root_run_id']    = (string) ( $row['root_run_id'] ?? '' );
		$row['handoff_capsule'] = json_decode( $row['handoff_capsule'] ?? 'null', true );
		$row['workflow_state']  = json_decode( $row['workflow_state'] ?? 'null', true );
		$row['pending_actions'] = json_decode( $row['pending_actions'] ?? 'null', true );
		$row['result']          = json_decode( $row['result'] ?? 'null', true );
		$row['correlation_id']  = (string) ( $row['correlation_id'] ?? '' );
		$row['user_id']         = (int) $row['user_id'];
		$row['chat_id']         = (int) $row['chat_id'];

		return $row;
	}

	/**
	 * Publish a sanitized transition event for a stored run.
	 *
	 * @param string              $run_id     Run ID.
	 * @param string              $event_type Canonical event type.
	 * @param string              $phase      Canonical phase.
	 * @param string              $status     Canonical status.
	 * @param string              $reason     Canonical reason.
	 * @param array<string,mixed> $payload    Safe payload.
	 * @param string              $summary    Summary text.
	 */
	private function publish_transition_event(
		string $run_id,
		string $event_type,
		string $phase,
		string $status,
		string $reason,
		array $payload,
		string $summary
	): void {
		$run = $this->get( $run_id );
		if ( ! $run ) {
			return;
		}

		if ( ! empty( $run['parent_run_id'] ) && ! isset( $payload['parent_run_id'] ) ) {
			$payload['parent_run_id'] = (string) $run['parent_run_id'];
		}
		if ( ! empty( $run['root_run_id'] ) && ! isset( $payload['root_run_id'] ) ) {
			$payload['root_run_id'] = (string) $run['root_run_id'];
		}

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => $event_type,
				'phase'      => $phase,
				'status'     => $status,
				'reason'     => $reason,
				'summary'    => $summary,
				'payload'    => $payload,
			),
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
}
