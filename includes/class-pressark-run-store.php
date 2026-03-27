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
			user_id BIGINT(20) UNSIGNED NOT NULL,
			chat_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			task_id VARCHAR(64) DEFAULT NULL,
			route VARCHAR(20) NOT NULL DEFAULT 'agent',
			status VARCHAR(30) NOT NULL DEFAULT 'running',
			message TEXT NOT NULL,
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

		$run_id = $data['run_id'] ?? wp_generate_uuid4();

		$wpdb->insert(
			self::table_name(),
			array(
				'run_id'         => $run_id,
				'user_id'        => absint( $data['user_id'] ?? get_current_user_id() ),
				'chat_id'        => absint( $data['chat_id'] ?? 0 ),
				'route'          => sanitize_key( $data['route'] ?? 'agent' ),
				'status'         => 'running',
				'message'        => $data['message'] ?? '',
				'reservation_id' => sanitize_text_field( $data['reservation_id'] ?? '' ),
				'tier'           => sanitize_key( $data['tier'] ?? 'free' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $run_id;
	}

	/**
	 * Read a run by run_id. Decodes JSON columns.
	 *
	 * @return array|null The run row, or null if not found.
	 */
	public function get( string $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table_name() . " WHERE run_id = %s",
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

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM " . self::table_name() . " WHERE run_id = %s",
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

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table_name() . " WHERE preview_session_id = %s AND status = 'awaiting_preview'",
				$session_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	// ── Lifecycle Transitions ────────────────────────────────────────

	/**
	 * Pause at preview boundary: running → awaiting_preview.
	 *
	 * Persists the workflow state and preview session ID so the server
	 * can resume execution when the user clicks Keep.
	 *
	 * @param string      $run_id             Run ID.
	 * @param string      $preview_session_id Preview session ID from PressArk_Preview.
	 * @param array|null  $workflow_state      Serialized workflow state.
	 * @param string|null $workflow_class      Workflow class name (e.g. PressArk_Workflow_Content_Edit).
	 * @return bool True if transition succeeded.
	 */
	public function pause_for_preview(
		string  $run_id,
		string  $preview_session_id,
		?array  $workflow_state = null,
		?string $workflow_class = null
	): bool {
		global $wpdb;

		$data    = array(
			'status'             => 'awaiting_preview',
			'preview_session_id' => $preview_session_id,
			'updated_at'         => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $workflow_state !== null ) {
			$data['workflow_state'] = wp_json_encode( $workflow_state );
			$formats[]              = '%s';
		}
		if ( $workflow_class !== null ) {
			$data['workflow_class'] = $workflow_class;
			$formats[]              = '%s';
		}

		$rows = $wpdb->update(
			self::table_name(),
			$data,
			array( 'run_id' => $run_id, 'status' => 'running' ),
			$formats,
			array( '%s', '%s' )
		);

		return $rows >= 1;
	}

	/**
	 * Pause at confirm boundary: running → awaiting_confirm.
	 *
	 * v3.7.2: Added status transition guard — only transitions from 'running'.
	 * Prevents double-pause races and invalid state transitions.
	 *
	 * @param string      $run_id          Run ID.
	 * @param array       $pending_actions Pending actions awaiting confirmation.
	 * @param array|null  $workflow_state   Serialized workflow state.
	 * @param string|null $workflow_class   Workflow class name.
	 * @return bool True if transition succeeded.
	 */
	public function pause_for_confirm(
		string  $run_id,
		array   $pending_actions,
		?array  $workflow_state = null,
		?string $workflow_class = null
	): bool {
		global $wpdb;

		$data    = array(
			'status'          => 'awaiting_confirm',
			'pending_actions' => wp_json_encode( $pending_actions ),
			'updated_at'      => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( $workflow_state !== null ) {
			$data['workflow_state'] = wp_json_encode( $workflow_state );
			$formats[]              = '%s';
		}
		if ( $workflow_class !== null ) {
			$data['workflow_class'] = $workflow_class;
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

		return $rows >= 1;
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

		return (bool) $rows;
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

		return (int) $rows >= 1;
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
	public function fail( string $run_id, string $reason = '' ): bool {
		global $wpdb;
		$table = self::table_name();

		// Truncate reason for the indexed summary column.
		$summary = mb_substr( $reason, 0, 255 );

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'failed', result = %s, error_summary = %s, updated_at = %s
			 WHERE run_id = %s
			 AND status IN ('running', 'awaiting_preview', 'awaiting_confirm', 'partially_confirmed')",
			wp_json_encode( array( 'fail_reason' => $reason ) ),
			$summary,
			current_time( 'mysql', true ),
			$run_id
		) );

		return (int) $rows >= 1;
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

		$where = $user_id > 0
			? $wpdb->prepare( 'WHERE user_id = %d', $user_id )
			: '';

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

		// Stale awaiting runs older than 2 hours — fail them instead of deleting,
		// so they show up in the activity feed as expired.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, all values are hardcoded constants.
		$wpdb->query(
			"UPDATE {$table}
			 SET status = 'failed',
			     error_summary = 'Preview/confirm expired — user did not respond within 2 hours',
			     result = '{\"fail_reason\":\"Preview or confirm boundary expired after 2 hours\"}',
			     updated_at = UTC_TIMESTAMP()
			 WHERE status IN ('awaiting_preview', 'awaiting_confirm', 'partially_confirmed')
			 AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 2 HOUR )"
		);

		return $deleted;
	}

	// ── Internal ─────────────────────────────────────────────────────

	/**
	 * Decode JSON columns in a run row.
	 */
	private function decode_row( array $row ): array {
		$row['workflow_state']  = json_decode( $row['workflow_state'] ?? 'null', true );
		$row['pending_actions'] = json_decode( $row['pending_actions'] ?? 'null', true );
		$row['result']          = json_decode( $row['result'] ?? 'null', true );
		$row['user_id']         = (int) $row['user_id'];
		$row['chat_id']         = (int) $row['chat_id'];

		return $row;
	}
}
