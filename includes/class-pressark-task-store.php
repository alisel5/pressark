<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Task persistence layer — CRUD on the pressark_tasks table.
 *
 * Replaces the wp_options-based storage from PressArk_Task_Queue.
 * Follows the PressArk_Cost_Ledger pattern (static schema, instance methods).
 *
 * v3.7.0: Added idempotency_key column, dead_letter status, undelivered
 * task escalation, and improved stale detection with configurable timeout.
 * v4.2.2: Idempotency now reserves only active tasks. Terminal tasks release
 * their key so reruns can create a fresh row without violating schema rules.
 *
 * @package PressArk
 * @since   2.5.0
 * @since   3.7.0 Idempotency, dead-letter, better stale handling.
 */
class PressArk_Task_Store {

	/**
	 * v3.7.0: How long a running task can stay alive before being
	 * considered stale. Matches the maximum realistic agent run time.
	 */
	const STALE_TIMEOUT_MINUTES = 30;

	/**
	 * v4.2.0: Increased from 6h to 72h. Undelivered complete tasks are
	 * no longer dead-lettered — they remain discoverable via the Activity
	 * page. This constant now controls when we stop trying to deliver via
	 * heartbeat (mark as 'undelivered' instead of 'dead_letter').
	 */
	const UNDELIVERED_HOURS = 72;

	/**
	 * Get the fully-prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_tasks';
	}

	/**
	 * DDL for dbDelta (called from pressark_activate and upgrade migration).
	 *
	 * v3.7.0: Added idempotency_key column.
	 * Status enum now includes 'dead_letter' for permanently failed or abandoned tasks.
	 */
	/**
	 * DDL for dbDelta (called from pressark_activate and upgrade migration).
	 *
	 * v3.7.0: Added idempotency_key column.
	 * Status enum now includes 'dead_letter' for permanently failed or abandoned tasks.
	 * v4.2.0: Added read_at column — tracks when user viewed the result in the
	 * Activity page, decoupled from heartbeat delivery. Status 'undelivered' added
	 * for complete tasks whose heartbeat window expired.
	 * v4.2.2: Added idempotency_active so uniqueness only applies while a task is
	 * in-flight. Terminal tasks release the active slot and can be rerun cleanly.
	 */
	public static function get_schema(): string {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			task_id VARCHAR(64) NOT NULL,
			run_id VARCHAR(64) DEFAULT NULL,
			parent_run_id VARCHAR(64) DEFAULT NULL,
			root_run_id VARCHAR(64) DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			message TEXT NOT NULL,
			payload LONGTEXT DEFAULT NULL,
			handoff_capsule LONGTEXT DEFAULT NULL,
			reservation_id VARCHAR(64) NOT NULL DEFAULT '',
			idempotency_key VARCHAR(128) DEFAULT NULL,
			idempotency_active TINYINT(1) UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			retries TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_retries TINYINT UNSIGNED NOT NULL DEFAULT 2,
			fail_reason TEXT DEFAULT NULL,
			result LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			read_at DATETIME DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_task_id (task_id),
			UNIQUE KEY uniq_idempotency_active (idempotency_key, idempotency_active),
			KEY idx_run_created (run_id, created_at),
			KEY idx_parent_run_created (parent_run_id, created_at),
			KEY idx_root_run_created (root_run_id, created_at),
			KEY idx_user_status (user_id, status),
			KEY idx_status_created (status, created_at),
			KEY idx_expires_at (expires_at)
		) {$charset_collate};";
	}

	// ── CRUD ─────────────────────────────────────────────────────────

	/**
	 * Insert a new task row.
	 *
	 * v3.7.0: Supports idempotency_key. If a task with the same key already
	 * has an active queued/running row, returns that task_id instead of
	 * inserting a duplicate. Terminal rows release their key and allow a
	 * fresh task to be created.
	 *
	 * @param array $data Keys: task_id, user_id, message, payload (array),
	 *                    reservation_id, max_retries, idempotency_key.
	 * @return string The task_id (existing or new), or an empty string on insert failure.
	 */
	public function create( array $data ): string {
		$record = $this->create_record( $data );
		return $record['task_id'];
	}

	/**
	 * Insert a task row and report whether a new row was created or an in-flight
	 * row was reused.
	 *
	 * @return array{task_id: string, created: bool, reused_existing: bool, error: string}
	 */
	public function create_record( array $data ): array {
		global $wpdb;

		$task_id         = $data['task_id'] ?? wp_generate_uuid4();
		$run_id          = sanitize_text_field( (string) ( $data['run_id'] ?? '' ) );
		$parent_run_id   = sanitize_text_field( (string) ( $data['parent_run_id'] ?? '' ) );
		$root_run_id     = sanitize_text_field( (string) ( $data['root_run_id'] ?? '' ) );
		$idempotency_key = ! empty( $data['idempotency_key'] )
			? sanitize_text_field( $data['idempotency_key'] )
			: null;
		$handoff_capsule = ! empty( $data['handoff_capsule'] ) && is_array( $data['handoff_capsule'] )
			? wp_json_encode( $data['handoff_capsule'] )
			: '';

		if ( '' === $root_run_id ) {
			if ( '' !== $parent_run_id ) {
				$root_run_id = $parent_run_id;
			} elseif ( '' !== $run_id ) {
				$root_run_id = $run_id;
			}
		}

		// v4.2.0: Idempotency check — return existing in-flight task only.
		// Previously this blocked reruns when a task was 'complete' but not yet
		// delivered, which was product-hostile: users who closed and reopened
		// the tab couldn't re-request the same operation. Now we only dedupe
		// against tasks that are genuinely still in-flight (queued/running).
		// Terminal/settled states (complete, delivered, undelivered, failed,
		// dead_letter) all allow a new task to be created.
		if ( $idempotency_key ) {
			$existing = $this->find_in_flight_by_idempotency_key( $idempotency_key );
			if ( '' !== $existing ) {
				return array(
					'task_id'         => $existing,
					'created'         => false,
					'reused_existing' => true,
					'error'           => '',
				);
			}
		}

		$row = array(
			'task_id'            => $task_id,
			'run_id'             => $run_id,
			'parent_run_id'      => $parent_run_id,
			'root_run_id'        => $root_run_id,
			'user_id'            => absint( $data['user_id'] ?? get_current_user_id() ),
			'message'            => $data['message'] ?? '',
			'payload'            => wp_json_encode( $data['payload'] ?? array() ),
			'handoff_capsule'    => $handoff_capsule,
			'reservation_id'     => sanitize_text_field( $data['reservation_id'] ?? '' ),
			'idempotency_key'    => $idempotency_key,
			'idempotency_active' => $idempotency_key ? 1 : null,
			'max_retries'        => absint( $data['max_retries'] ?? 2 ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		/**
		 * Allow tests to simulate races immediately before the insert.
		 *
		 * @param array               $row  Normalized row about to be inserted.
		 * @param array               $data Original create() payload.
		 * @param PressArk_Task_Store $this Store instance.
		 */
		do_action( 'pressark_task_store_before_insert', $row, $data, $this );

		if ( $this->insert_row( $row, $formats ) ) {
			return array(
				'task_id'         => $task_id,
				'created'         => true,
				'reused_existing' => false,
				'error'           => '',
			);
		}

		if ( $idempotency_key ) {
			$existing = $this->find_in_flight_by_idempotency_key( $idempotency_key );
			if ( '' !== $existing ) {
				return array(
					'task_id'         => $existing,
					'created'         => false,
					'reused_existing' => true,
					'error'           => '',
				);
			}
		}

		$this->log_insert_failure( $task_id, $idempotency_key, $wpdb->last_error );

		return array(
			'task_id'         => '',
			'created'         => false,
			'reused_existing' => false,
			'error'           => $wpdb->last_error ?: 'task_insert_failed',
		);
	}

	/**
	 * Find the active task currently holding an idempotency key.
	 */
	public function find_in_flight_by_idempotency_key( string $idempotency_key ): string {
		global $wpdb;
		$table = self::table_name();

		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( '' === $idempotency_key ) {
			return '';
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT task_id FROM {$table}
				 WHERE idempotency_key = %s
				   AND idempotency_active = 1
				   AND status IN ('queued', 'running')
				 LIMIT 1",
				$idempotency_key
			)
		);

		return is_string( $existing ) ? $existing : '';
	}

	/**
	 * Read a task by task_id. Decodes payload and result JSON.
	 *
	 * @return array|null The task row, or null if not found.
	 */
	public function get( string $task_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE task_id = %s",
				$task_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->decode_row( $row );
	}

	/**
	 * Update the stored payload for a task.
	 *
	 * @param string $task_id  Task ID.
	 * @param array  $payload  Payload to store.
	 * @return bool
	 */
	public function update_payload( string $task_id, array $payload ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'task_id' => $task_id ),
			array( '%s' ),
			array( '%s' )
		);

		return false !== $rows;
	}

	// ── Lifecycle Transitions ────────────────────────────────────────

	/**
	 * Atomic claim: queued → running.
	 *
	 * Uses UPDATE ... WHERE status='queued' to prevent double-processing.
	 * Only the first caller gets rows_affected=1.
	 *
	 * @return bool True if this caller won the race.
	 */
	public function claim( string $task_id ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql', true ),
			),
			array(
				'task_id' => $task_id,
				'status'  => 'queued',
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		return $rows === 1;
	}

	/**
	 * Mark task as complete with result.
	 *
	 * Completed results remain durable until the unified retention policy
	 * removes them, so we no longer stamp a short-lived expires_at here.
	 */
	public function complete( string $task_id, array $result ): bool {
		global $wpdb;
		$table = self::table_name();

		$now = current_time( 'mysql', true );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'complete',
				     result = %s,
				     completed_at = %s,
				     expires_at = NULL,
				     idempotency_active = NULL
				 WHERE task_id = %s
				   AND status = 'running'",
				wp_json_encode( $result ),
				$now,
				$task_id
			)
		);

		return $rows === 1;
	}

	/**
	 * Mark task as failed with reason.
	 */
	public function fail( string $task_id, string $reason ): bool {
		global $wpdb;
		$table = self::table_name();
		$now = current_time( 'mysql', true );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'failed',
				     fail_reason = %s,
				     completed_at = %s,
				     expires_at = NULL,
				     idempotency_active = NULL
				 WHERE task_id = %s
				   AND status = 'running'",
				$reason,
				$now,
				$task_id
			)
		);

		return $rows >= 1;
	}

	/**
	 * Retry a failed task: failed → queued, increment retries, clear state.
	 */
	public function retry( string $task_id ): bool {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status      = 'queued',
				     retries     = retries + 1,
				     started_at  = NULL,
				     completed_at = NULL,
				     expires_at  = NULL,
				     fail_reason = NULL,
				     idempotency_active = CASE
				     	WHEN idempotency_key IS NULL OR idempotency_key = '' THEN NULL
				     	ELSE 1
				     END
				 WHERE task_id = %s AND status = 'failed'",
				$task_id
			)
		);

		return $rows >= 1;
	}

	/**
	 * Defer a running task back into the queue without incrementing retries.
	 *
	 * Used when a worker claimed the task successfully but needs to yield due
	 * to temporary contention, such as an unavailable concurrency slot.
	 */
	public function defer( string $task_id ): bool {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'queued',
				     started_at = NULL,
				     completed_at = NULL,
				     fail_reason = NULL
				 WHERE task_id = %s
				   AND status = 'running'",
				$task_id
			)
		);

		return $rows >= 1;
	}

	/**
	 * Mark a completed task as delivered (result picked up by heartbeat).
	 */
	public function deliver( string $task_id ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array( 'status' => 'delivered' ),
			array( 'task_id' => $task_id, 'status' => 'complete' ),
			array( '%s' ),
			array( '%s', '%s' )
		);

		return $rows === 1;
	}

	/**
	 * v3.7.0: Move a task to dead_letter status.
	 * Used for tasks that exhausted all retries, or completed tasks
	 * that were never delivered (user abandoned the session).
	 *
	 * Dead-letter tasks are retained for 30 days for supportability,
	 * then cleaned up by cleanup_dead_letter().
	 *
	 * @param string $task_id Task ID.
	 * @param string $reason  Why the task was dead-lettered.
	 * @return bool Whether the transition succeeded.
	 */
	public function dead_letter( string $task_id, string $reason = '' ): bool {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status      = 'dead_letter',
			     fail_reason = CONCAT(COALESCE(fail_reason, ''), %s),
			     expires_at  = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY),
			     idempotency_active = NULL
			 WHERE task_id = %s
			 AND status IN ('failed', 'complete', 'undelivered')",
			$reason ? ' [dead-letter: ' . $reason . ']' : '',
			$task_id
		) );

		return $rows >= 1;
	}

	/**
	 * Check if a task can be retried (retries < max_retries).
	 */
	public function can_retry( array $task ): bool {
		return ( (int) $task['retries'] ) < ( (int) $task['max_retries'] );
	}

	// ── Queries ──────────────────────────────────────────────────────

	/**
	 * Get all completed tasks for a user pending delivery via the REST poller.
	 *
	 * @return array Array of task rows with decoded result.
	 */
	public function get_pending_results( int $user_id ): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE user_id = %d
				   AND status = 'complete'
				   AND read_at IS NULL
				 ORDER BY completed_at ASC",
				$user_id
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		return array_map( array( $this, 'decode_row' ), $rows );
	}

	/**
	 * Get the number of currently pending tasks for a user.
	 *
	 * Pending means the task is still queued or actively running and has not yet
	 * produced a deliverable result.
	 */
	public function pending_count( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status IN ('queued', 'running')",
			$user_id
		) );
	}

	/**
	 * v3.7.0: Get task counts by status for a user (diagnostics/observability).
	 *
	 * @param int $user_id User ID (0 = all users).
	 * @return array { status => count }
	 */
	public function status_counts( int $user_id = 0 ): array {
		global $wpdb;
		$table = self::table_name();

		$where = $user_id ? $wpdb->prepare( 'WHERE user_id = %d', $user_id ) : '';

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
	 * Delete expired tasks and normalize abandoned states.
	 *
	 * Delivered/unread results are intentionally left alone here. Durable
	 * inbox retention is handled by PressArk_Retention so a heartbeat touch
	 * does not become a short-retention cleanup state.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_expired(): int {
		global $wpdb;
		$table   = self::table_name();
		$deleted = 0;

		// v3.7.0: Escalate permanently failed tasks to dead_letter instead of deleting.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, all values are constants.
		$wpdb->query(
			"UPDATE {$table}
			 SET status     = 'dead_letter',
			     expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY),
			     idempotency_active = NULL
			 WHERE status = 'failed'
			 AND retries >= max_retries
			 AND created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 24 HOUR )"
		);

		// Mark unread complete tasks as 'undelivered' once the heartbeat
		// handoff window has passed so they stay visible in Activity.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'undelivered'
			 WHERE status = 'complete'
			 AND read_at IS NULL
			 AND completed_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d HOUR )",
			self::UNDELIVERED_HOURS
		) );

		// v3.7.0: Clean up dead_letter tasks past their retention period.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, all values are constants.
		$deleted += (int) $wpdb->query(
			"DELETE FROM {$table}
			 WHERE status = 'dead_letter'
			 AND expires_at < UTC_TIMESTAMP()"
		);

		return $deleted;
	}

	/**
	 * Find the oldest task stuck in 'queued' status for too long.
	 *
	 * Tasks normally spend < 10 seconds in 'queued' (5-second scheduling
	 * delay + cron pick-up). If a task has been queued for > 2 minutes,
	 * the cron event that should have processed it likely never fired
	 * (broken WP-Cron loopback, Docker, firewalled host).
	 *
	 * Used by the inline rescue mechanism to detect and reprocess stuck tasks.
	 *
	 * @since 4.3.0
	 * @return array|null The overdue task row, or null.
	 */
	public function find_oldest_overdue_queued(): ?array {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row(
			"SELECT * FROM {$table}
			 WHERE status = 'queued'
			 AND created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 2 MINUTE )
			 ORDER BY created_at ASC
			 LIMIT 1",
			ARRAY_A
		);

		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * Detect and fail stale running tasks.
	 *
	 * v3.7.0: Reduced from 60 minutes to STALE_TIMEOUT_MINUTES (30).
	 * No real agent run should take 30+ minutes; if it does, the PHP
	 * process likely died and the task will never complete.
	 *
	 * @return int Number of rows affected.
	 */
	public function cleanup_stale(): int {
		global $wpdb;
		$table   = self::table_name();
		$timeout = self::STALE_TIMEOUT_MINUTES;

		$count = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status      = 'failed',
			     fail_reason = %s,
			     completed_at = UTC_TIMESTAMP(),
			     expires_at = NULL,
			     idempotency_active = NULL
			 WHERE status = 'running'
			 AND started_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MINUTE )",
			sprintf( 'Timed out after %d minutes (likely a dead process)', $timeout ),
			$timeout
		) );

		if ( $count > 0 ) {
			PressArk_Error_Tracker::warning( 'TaskStore', 'Cleaned up stale running tasks', array( 'count' => $count ) );
		}

		return $count;
	}

	// ── v4.2.0 Inbox Queries ────────────────────────────────────────

	/**
	 * Get unread completed/undelivered tasks for a user.
	 *
	 * These are tasks whose results exist but the user hasn't seen them —
	 * either because heartbeat didn't pick them up (tab was closed), or
	 * because they were delivered via heartbeat but not yet viewed in the
	 * Activity page.
	 *
	 * @since 4.2.0
	 * @param int $user_id User ID.
	 * @return int Count of unread results.
	 */
	public function unread_count( int $user_id = 0 ): int {
		global $wpdb;
		$table = self::table_name();

		if ( $user_id > 0 ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE user_id = %d
				 AND status IN ('complete', 'undelivered', 'delivered')
				 AND read_at IS NULL",
				$user_id
			) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, statuses are constants.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE status IN ('complete', 'undelivered', 'delivered')
			 AND read_at IS NULL"
		);
	}

	/**
	 * Mark a task as read (user viewed it in Activity page or via heartbeat pop).
	 *
	 * @since 4.2.0
	 * @param string $task_id Task ID.
	 * @return bool
	 */
	public function mark_read( string $task_id ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array( 'read_at' => current_time( 'mysql', true ) ),
			array( 'task_id' => $task_id ),
			array( '%s' ),
			array( '%s' )
		);

		return $rows !== false;
	}

	/**
	 * Get recent tasks for the Activity page.
	 *
	 * Returns a lightweight projection (no full payload/result blobs).
	 *
	 * @since 4.2.0
	 * @param int    $user_id       User ID (0 = all users).
	 * @param int    $limit         Max rows.
	 * @param int    $offset        Pagination offset.
	 * @param string $status_filter Optional status filter.
	 * @return array
	 */
	public function get_activity( int $user_id = 0, int $limit = 25, int $offset = 0, string $status_filter = '' ): array {
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
		$args[]    = max( 1, min( 100, $limit ) );
		$args[]    = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT task_id, user_id, message, status, retries, max_retries,
				        fail_reason, created_at, started_at, completed_at, read_at
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
			$row['user_id']     = (int) $row['user_id'];
			$row['retries']     = (int) $row['retries'];
			$row['max_retries'] = (int) $row['max_retries'];
			return $row;
		}, $rows );
	}

	/**
	 * Count tasks matching Activity filters.
	 *
	 * @since 4.2.1
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name, $where_sql built from hardcoded conditions.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * Get a task's full result for the Activity detail view.
	 *
	 * Marks the task as read as a side effect.
	 *
	 * @since 4.2.0
	 * @param string $task_id         Task ID.
	 * @param int    $user_id         User ID (ownership check).
	 * @param bool   $allow_any_owner Allow support/admin views to bypass ownership.
	 * @return array|null The task with decoded result, or null.
	 */
	public function get_result_for_user( string $task_id, int $user_id, bool $allow_any_owner = false ): ?array {
		$task = $this->get( $task_id );

		if ( ! $task || ( ! $allow_any_owner && $task['user_id'] !== $user_id ) ) {
			return null;
		}

		// Mark as read on view.
		if ( null === $task['read_at'] && in_array( (string) ( $task['status'] ?? '' ), array( 'complete', 'delivered', 'undelivered' ), true ) ) {
			$this->mark_read( $task_id );
			$task['read_at'] = current_time( 'mysql', true );
		}

		$task['progress'] = $this->build_progress_snapshot( $task );

		return $task;
	}

	// ── v3.7.1 Operation Receipts ───────────────────────────────────

	/**
	 * v3.7.1: Record that a specific operation within a task completed
	 * successfully. On retry, the caller can check has_receipt() to skip
	 * already-committed steps (business idempotency, not just task dedup).
	 *
	 * Receipts are stored as a JSON object in the payload under
	 * '_receipts' → { operation_key => { ts, result_summary } }.
	 *
	 * This prevents the double-refund / duplicate-email problem: if a
	 * side effect (refund, email, order create) succeeds but a later
	 * step throws, the retry knows NOT to replay the committed step.
	 *
	 * @param string $task_id       Task ID.
	 * @param string $operation_key Unique key for this operation (e.g. 'refund_123', 'email_456').
	 * @param string $summary       Short human-readable result (e.g. 'Refund $25 issued').
	 * @return bool Whether the receipt was saved.
	 */
	public function record_receipt( string $task_id, string $operation_key, string $summary = '' ): bool {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT payload FROM {$table} WHERE task_id = %s",
			$task_id
		), ARRAY_A );

		if ( ! $row ) return false;

		$payload  = json_decode( $row['payload'] ?? '{}', true ) ?: array();
		$receipts = $payload['_receipts'] ?? array();

		$receipts[ $operation_key ] = array(
			'ts'      => gmdate( 'Y-m-d H:i:s' ),
			'summary' => $summary,
		);

		$payload['_receipts'] = $receipts;

		$rows = $wpdb->update(
			$table,
			array( 'payload' => wp_json_encode( $payload ) ),
			array( 'task_id' => $task_id ),
			array( '%s' ),
			array( '%s' )
		);

		return $rows !== false;
	}

	/**
	 * v3.7.1: Check whether an operation receipt exists for this task.
	 * Returns true if the operation was already committed on a previous
	 * attempt — the caller should skip it.
	 *
	 * @param string $task_id       Task ID.
	 * @param string $operation_key Operation key to check.
	 * @return bool True if receipt exists (operation already committed).
	 */
	public function has_receipt( string $task_id, string $operation_key ): bool {
		$task = $this->get( $task_id );
		if ( ! $task ) return false;

		$receipts = $task['payload']['_receipts'] ?? array();
		return isset( $receipts[ $operation_key ] );
	}

	/**
	 * v3.7.1: Get all receipts for a task (for diagnostics/logging).
	 *
	 * @param string $task_id Task ID.
	 * @return array { operation_key => { ts, summary } }
	 */
	public function get_receipts( string $task_id ): array {
		$task = $this->get( $task_id );
		if ( ! $task ) return array();
		return $task['payload']['_receipts'] ?? array();
	}

	/**
	 * Mark a queued task as failed before it ever reached a worker.
	 */
	public function fail_queued( string $task_id, string $reason ): bool {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'failed',
				     fail_reason = %s,
				     completed_at = %s,
				     expires_at = NULL,
				     idempotency_active = NULL
				 WHERE task_id = %s
				   AND status = 'queued'",
				$reason,
				$now,
				$task_id
			)
		);

		return $rows === 1;
	}

	// ── Private Helpers ──────────────────────────────────────────────

	/**
	 * Insert a normalized task row.
	 */
	protected function insert_row( array $row, array $formats ): bool {
		global $wpdb;

		return false !== $wpdb->insert( self::table_name(), $row, $formats );
	}

	/**
	 * Emit a concise insert failure log for support/debugging.
	 */
	public function get_progress_snapshots( array $task_ids ): array {
		global $wpdb;
		$table = self::table_name();

		$task_ids = array_values(
			array_filter(
				array_map(
					static function ( $task_id ): string {
						return sanitize_text_field( (string) $task_id );
					},
					$task_ids
				),
				static function ( string $task_id ): bool {
					return '' !== $task_id;
				}
			)
		);

		if ( empty( $task_ids ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT task_id, run_id, parent_run_id, root_run_id, user_id, message, status,
				        retries, max_retries, fail_reason, payload, handoff_capsule, result,
				        created_at, started_at, completed_at
				 FROM {$table}
				 WHERE task_id IN (" . implode( ',', array_fill( 0, count( $task_ids ), '%s' ) ) . ')',
				...$task_ids
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$events_by_task = class_exists( 'PressArk_Activity_Event_Store' )
			? ( new PressArk_Activity_Event_Store() )->get_by_tasks( $task_ids, 40 )
			: array();

		$snapshots = array();
		foreach ( $rows as $row ) {
			$task = $this->decode_row( $row );
			$snapshots[ $task['task_id'] ] = $this->build_progress_snapshot(
				$task,
				$events_by_task[ $task['task_id'] ] ?? array()
			);
		}

		return $snapshots;
	}

	/**
	 * Build a deterministic background-progress snapshot from task state + events.
	 *
	 * @param array<string,mixed>                 $task   Decoded task row.
	 * @param array<int,array<string,mixed>>|null $events Optional task-linked events.
	 * @return array<string,mixed>
	 */
	public function build_progress_snapshot( array $task, ?array $events = null ): array {
		$task_id = sanitize_text_field( (string) ( $task['task_id'] ?? '' ) );
		if ( '' === $task_id ) {
			return array();
		}

		if ( null === $events && class_exists( 'PressArk_Activity_Event_Store' ) ) {
			$events = ( new PressArk_Activity_Event_Store() )->get_by_task( $task_id, 40 );
		}
		$events = is_array( $events ) ? $events : array();

		$checkpoints      = $this->progress_checkpoints( $task );
		$plan_phase       = $this->extract_progress_plan_phase( $task, $checkpoints );
		$stage_key        = $this->extract_progress_stage( $task, $events, $checkpoints );
		$stage_label      = $this->progress_stage_label( $stage_key, $plan_phase );
		$stage_action     = $this->progress_stage_action( $stage_key, $plan_phase );
		$target_label     = $this->extract_progress_target( $task, $checkpoints );
		$execution        = $this->extract_execution_progress( $task, $checkpoints );
		$meaningful_event = $this->select_meaningful_progress_event( $events );
		$event_label      = $this->format_progress_event_label( $meaningful_event );
		$result_type      = sanitize_key( (string) ( $task['result']['type'] ?? '' ) );
		$failure          = $this->extract_failure_context( $task );
		$state_key        = $this->determine_progress_state(
			$task,
			$meaningful_event,
			$stage_key,
			$result_type,
			$execution,
			$failure
		);
		$state_label      = $this->progress_state_label( $state_key );

		return array(
			'state_key'         => $state_key,
			'state_label'       => $state_label,
			'stage_key'         => $stage_key,
			'stage_label'       => $stage_label,
			'event_label'       => $event_label,
			'event_type'        => (string) ( $meaningful_event['event_type'] ?? '' ),
			'target_label'      => $target_label,
			'headline'          => $this->build_progress_headline(
				$task,
				$state_key,
				$stage_action,
				$stage_label,
				$target_label,
				$result_type,
				$meaningful_event,
				$failure
			),
			'summary'           => $this->build_progress_summary( $state_label, $stage_label, $event_label, $target_label ),
			'milestone_summary' => $this->build_progress_milestone_summary( $task, $execution, $state_key ),
			'completed_labels'  => $execution['completed_labels'],
			'remaining_labels'  => $execution['remaining_labels'],
			'blocked_labels'    => $execution['blocked_labels'],
			'latest_event_at'   => (string) ( $meaningful_event['occurred_at'] ?? $meaningful_event['created_at'] ?? '' ),
		);
	}

	private function progress_checkpoints( array $task ): array {
		$candidates = array(
			$task['result']['checkpoint'] ?? null,
			$task['result']['workflow_state'] ?? null,
			$task['payload']['checkpoint'] ?? null,
		);

		$checkpoints = array();
		foreach ( $candidates as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate ) ) {
				$checkpoints[] = $candidate;
			}
		}

		return $checkpoints;
	}

	private function extract_progress_stage( array $task, array $events, array $checkpoints ): string {
		$result_type = sanitize_key( (string) ( $task['result']['type'] ?? '' ) );
		if ( in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
			return 'preview';
		}

		foreach ( $checkpoints as $checkpoint ) {
			$stage = sanitize_key( (string) ( $checkpoint['workflow_stage'] ?? '' ) );
			if ( '' !== $stage ) {
				return $stage;
			}
		}

		for ( $index = count( $events ) - 1; $index >= 0; $index-- ) {
			$event = $events[ $index ];
			if ( ! is_array( $event ) ) {
				continue;
			}

			$payload = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
			$stage   = sanitize_key( (string) ( $payload['workflow_stage'] ?? '' ) );
			if ( '' !== $stage ) {
				return $stage;
			}
		}

		return sanitize_key( (string) ( $task['handoff_capsule']['workflow_stage'] ?? '' ) );
	}

	private function extract_progress_plan_phase( array $task, array $checkpoints ): string {
		foreach ( $checkpoints as $checkpoint ) {
			$phase = sanitize_key( (string) ( $checkpoint['plan_state']['phase'] ?? '' ) );
			if ( '' !== $phase ) {
				return $phase;
			}
		}

		return sanitize_key( (string) ( $task['handoff_capsule']['plan_phase'] ?? '' ) );
	}

	private function extract_progress_target( array $task, array $checkpoints ): string {
		foreach ( $checkpoints as $checkpoint ) {
			$selected = is_array( $checkpoint['selected_target'] ?? null ) ? $checkpoint['selected_target'] : array();
			if ( ! empty( $selected ) ) {
				$label = $this->format_progress_target( $selected );
				if ( '' !== $label ) {
					return $label;
				}
			}

			$current = is_array( $checkpoint['execution']['current_target'] ?? null ) ? $checkpoint['execution']['current_target'] : array();
			if ( ! empty( $current ) ) {
				$label = $this->format_progress_target( $current );
				if ( '' !== $label ) {
					return $label;
				}
			}
		}

		return sanitize_text_field( (string) ( $task['handoff_capsule']['target'] ?? '' ) );
	}

	private function format_progress_target( array $target ): string {
		$title = sanitize_text_field( (string) ( $target['title'] ?? $target['post_title'] ?? '' ) );
		$id    = absint( $target['id'] ?? $target['post_id'] ?? 0 );
		$type  = sanitize_key( (string) ( $target['type'] ?? $target['post_type'] ?? '' ) );
		$parts = array();

		if ( '' !== $title ) {
			$parts[] = $title;
		}
		if ( $id > 0 ) {
			$parts[] = '#' . $id;
		}
		if ( '' !== $type ) {
			$parts[] = '(' . $type . ')';
		}

		return trim( implode( ' ', $parts ) );
	}

	private function extract_execution_progress( array $task, array $checkpoints ): array {
		$fallback = array(
			'completed_labels' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $task['handoff_capsule']['completed'] ?? array() ) ) ) ),
			'remaining_labels' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $task['handoff_capsule']['remaining'] ?? array() ) ) ) ),
			'blocked_labels'   => array(),
			'recent_receipts'  => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $task['handoff_capsule']['recent_receipts'] ?? array() ) ) ) ),
		);

		if ( ! class_exists( 'PressArk_Execution_Ledger' ) ) {
			return $fallback;
		}

		foreach ( $checkpoints as $checkpoint ) {
			$execution = is_array( $checkpoint['execution'] ?? null ) ? $checkpoint['execution'] : array();
			if ( empty( $execution ) ) {
				continue;
			}

			$progress = PressArk_Execution_Ledger::progress_snapshot( $execution );
			if ( ! is_array( $progress ) || empty( $progress ) ) {
				continue;
			}

			return array(
				'completed_labels' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $progress['completed_labels'] ?? array() ) ) ) ),
				'remaining_labels' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $progress['remaining_labels'] ?? array() ) ) ) ),
				'blocked_labels'   => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $progress['blocked_labels'] ?? array() ) ) ) ),
				'recent_receipts'  => $fallback['recent_receipts'],
			);
		}

		return $fallback;
	}

	private function select_meaningful_progress_event( array $events ): array {
		$best          = array();
		$best_priority = -1;

		for ( $index = count( $events ) - 1; $index >= 0; $index-- ) {
			$event = $events[ $index ];
			if ( ! is_array( $event ) ) {
				continue;
			}

			$priority = $this->progress_event_priority( $event );
			if ( $priority > $best_priority ) {
				$best          = $event;
				$best_priority = $priority;
			}

			if ( $priority >= 120 ) {
				break;
			}
		}

		return $best;
	}

	private function progress_event_priority( array $event ): int {
		$event_type = (string) ( $event['event_type'] ?? '' );
		$reason     = sanitize_key( (string) ( $event['reason'] ?? '' ) );
		$status     = sanitize_key( (string) ( $event['status'] ?? '' ) );

		if ( in_array( $reason, array( 'approval_wait_preview', 'approval_wait_confirm', 'approval_partial_progress' ), true ) ) {
			return 120;
		}
		if ( in_array( $reason, array( 'approval_declined', 'approval_discarded', 'approval_expired', 'approval_aborted' ), true ) ) {
			return 118;
		}
		if ( 'worker.retry_scheduled' === $event_type ) {
			return 115;
		}
		if ( 'worker.slot_contention' === $event_type ) {
			return 112;
		}
		if ( 'worker.deferred' === $event_type ) {
			return 110;
		}
		if ( 'worker.cancelled' === $event_type ) {
			return 108;
		}
		if ( 'run.completed' === $event_type && 'failed' === $status ) {
			return 106;
		}
		if ( 'worker.completed' === $event_type && 'failed' === $status ) {
			return 104;
		}
		if ( 'blocked' === $status || in_array( $reason, array( 'preflight_blocked', 'entitlement_denied' ), true ) ) {
			return 102;
		}
		if ( 'run.completed' === $event_type ) {
			return 100;
		}
		if ( 'worker.claimed' === $event_type ) {
			return 95;
		}
		if ( 'worker.completed' === $event_type ) {
			return 92;
		}
		if ( 'worker.handoff' === $event_type ) {
			return 85;
		}
		if ( ! empty( $event['summary'] ) && ! in_array( $event_type, array( 'run.started', 'run.phase_completed' ), true ) ) {
			return 70;
		}

		return 0;
	}

	private function format_progress_event_label( array $event ): string {
		if ( empty( $event ) ) {
			return '';
		}

		$event_type  = (string) ( $event['event_type'] ?? '' );
		$reason      = sanitize_key( (string) ( $event['reason'] ?? '' ) );
		$status      = sanitize_key( (string) ( $event['status'] ?? '' ) );
		$payload     = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
		$delay       = absint( $payload['delay_seconds'] ?? 0 );
		$result_type = sanitize_key( (string) ( $payload['result_type'] ?? '' ) );

		return match ( true ) {
			'approval_wait_preview' === $reason => 'Waiting for preview review',
			'approval_wait_confirm' === $reason => 'Waiting for confirmation',
			'approval_partial_progress' === $reason => 'Some confirmations are complete',
			'approval_declined' === $reason => 'Confirmation declined',
			'approval_discarded' === $reason => 'Preview discarded',
			'approval_expired' === $reason => 'Approval expired',
			'approval_aborted' === $reason => 'Approval ended before completion',
			'worker.slot_contention' === $event_type => 'Waiting for a worker slot',
			'worker.deferred' === $event_type && $delay > 0 => 'Re-queued for ' . $delay . 's',
			'worker.deferred' === $event_type => 'Re-queued for the next slot',
			'worker.retry_scheduled' === $event_type && $delay > 0 => 'Retry scheduled in ' . $delay . 's',
			'worker.retry_scheduled' === $event_type => 'Retry scheduled',
			'worker.claimed' === $event_type => 'Worker started',
			'worker.handoff' === $event_type => 'Saved to the queue',
			'worker.cancelled' === $event_type => 'Cancelled before execution',
			'worker.completed' === $event_type && 'failed' === $status => 'Worker finished with a failure',
			'worker.completed' === $event_type && 'preview' === $result_type => 'Preview prepared',
			'worker.completed' === $event_type && 'confirm_card' === $result_type => 'Confirmation prepared',
			'worker.completed' === $event_type => 'Background work completed',
			'run.completed' === $event_type && 'failed' === $status => 'Run failed',
			'run.completed' === $event_type => 'Run settled',
			'blocked' === $status || in_array( $reason, array( 'preflight_blocked', 'entitlement_denied' ), true ) => 'Blocked by safety checks',
			default => rtrim( sanitize_text_field( (string) ( $event['summary'] ?? '' ) ), '.' ),
		};
	}

	private function extract_failure_context( array $task ): array {
		$failure_class   = '';
		$failure_message = '';
		$last_failure    = is_array( $task['payload']['_last_failure'] ?? null ) ? $task['payload']['_last_failure'] : array();

		if ( ! empty( $last_failure['class'] ) ) {
			$failure_class = sanitize_key( (string) $last_failure['class'] );
		}
		if ( ! empty( $last_failure['message'] ) ) {
			$failure_message = sanitize_text_field( (string) $last_failure['message'] );
		}

		$reason = trim( (string) ( $task['fail_reason'] ?? '' ) );
		if ( '' !== $reason && preg_match( '/^\[([a-z0-9_]+)\]\s*(.+)$/i', $reason, $matches ) ) {
			if ( '' === $failure_class ) {
				$failure_class = sanitize_key( (string) $matches[1] );
			}
			$reason = (string) $matches[2];
		}
		if ( '' !== $reason ) {
			$failure_message = sanitize_text_field( $reason );
		}

		return array(
			'class'   => $failure_class,
			'message' => $failure_message,
		);
	}

	private function determine_progress_state(
		array $task,
		array $event,
		string $stage_key,
		string $result_type,
		array $execution,
		array $failure
	): string {
		$task_status  = sanitize_key( (string) ( $task['status'] ?? '' ) );
		$event_reason = sanitize_key( (string) ( $event['reason'] ?? '' ) );
		$event_status = sanitize_key( (string) ( $event['status'] ?? '' ) );

		if ( in_array( $event_reason, array( 'approval_wait_preview', 'approval_wait_confirm', 'approval_partial_progress' ), true )
			|| in_array( $result_type, array( 'preview', 'confirm_card' ), true ) ) {
			return 'waiting';
		}

		if ( 'retry_async_failure' === $event_reason
			|| 'retrying' === $event_status
			|| ( 'queued' === $task_status && (int) ( $task['retries'] ?? 0 ) > 0 && ! empty( $task['payload']['_last_failure'] ) ) ) {
			return 'retrying';
		}

		if ( in_array( $event_reason, array( 'worker_slot_contention', 'worker_deferred' ), true )
			|| ( 'waiting' === $event_status && in_array( $task_status, array( 'queued', 'running' ), true ) ) ) {
			return 'waiting';
		}

		if ( 'worker_cancelled' === $event_reason ) {
			return 'blocked';
		}

		if ( 'verify' === $stage_key && 'running' === $task_status ) {
			return 'verifying';
		}

		if ( 'blocked' === $event_status || ! empty( $execution['blocked_labels'] ) ) {
			return 'blocked';
		}

		if ( in_array( $task_status, array( 'failed', 'dead_letter' ), true ) ) {
			return $this->is_blocked_failure( $failure['class'], $failure['message'] ) ? 'blocked' : 'failed';
		}

		if ( in_array( $task_status, array( 'complete', 'delivered', 'undelivered' ), true ) ) {
			return 'complete';
		}

		if ( 'running' === $task_status ) {
			return 'running';
		}

		return 'queued';
	}

	private function is_blocked_failure( string $failure_class, string $failure_message ): bool {
		if ( in_array( $failure_class, array( 'validation', 'bad_retrieval', 'side_effect_risk' ), true ) ) {
			return true;
		}

		$message = strtolower( $failure_message );
		return str_contains( $message, 'blocked' )
			|| str_contains( $message, 'denied' )
			|| str_contains( $message, 'not allowed' )
			|| str_contains( $message, 'cannot safely retry' )
			|| str_contains( $message, 'cancelled before execution' );
	}

	private function progress_state_label( string $state_key ): string {
		return match ( $state_key ) {
			'queued'    => 'Queued',
			'running'   => 'In Progress',
			'waiting'   => 'Waiting',
			'retrying'  => 'Retrying',
			'verifying' => 'Verifying',
			'blocked'   => 'Blocked',
			'failed'    => 'Failed',
			'complete'  => 'Complete',
			default     => 'Queued',
		};
	}

	private function progress_stage_label( string $stage_key, string $plan_phase = '' ): string {
		if ( 'plan' === $stage_key ) {
			return match ( $plan_phase ) {
				'exploring' => 'Explore',
				'executing' => 'Execute',
				default     => 'Plan',
			};
		}

		return match ( $stage_key ) {
			'discover' => 'Discover',
			'gather'   => 'Gather',
			'preview'  => 'Preview',
			'apply'    => 'Apply',
			'verify'   => 'Verify',
			'settled'  => 'Settled',
			default    => '',
		};
	}

	private function progress_stage_action( string $stage_key, string $plan_phase = '' ): string {
		if ( 'plan' === $stage_key ) {
			return match ( $plan_phase ) {
				'exploring' => 'exploring the request',
				'executing' => 'executing the approved plan',
				default     => 'planning the work',
			};
		}

		return match ( $stage_key ) {
			'discover' => 'discovering the right tools',
			'gather'   => 'gathering context',
			'preview'  => 'preparing a preview',
			'apply'    => 'applying changes',
			'verify'   => 'verifying results',
			'settled'  => 'settling the run',
			default    => 'working through the request',
		};
	}

	private function build_progress_headline(
		array $task,
		string $state_key,
		string $stage_action,
		string $stage_label,
		string $target_label,
		string $result_type,
		array $event,
		array $failure
	): string {
		$event_reason    = sanitize_key( (string) ( $event['reason'] ?? '' ) );
		$target_suffix   = '' !== $target_label ? ' for ' . $target_label : '';
		$failure_summary = $this->summarize_failure_message( $failure['message'] );

		switch ( $state_key ) {
			case 'waiting':
				if ( 'approval_wait_preview' === $event_reason || 'preview' === $result_type ) {
					return 'Waiting for preview review' . $target_suffix . '.';
				}
				if ( 'approval_wait_confirm' === $event_reason || 'confirm_card' === $result_type ) {
					return 'Waiting for confirmation before applying changes' . $target_suffix . '.';
				}
				if ( 'approval_partial_progress' === $event_reason ) {
					return 'Waiting on the remaining confirmations' . $target_suffix . '.';
				}
				if ( in_array( $event_reason, array( 'worker_slot_contention', 'worker_deferred' ), true ) ) {
					return 'Waiting for a worker slot to continue ' . $stage_action . $target_suffix . '.';
				}
				return 'Waiting to continue ' . $stage_action . $target_suffix . '.';

			case 'retrying':
				$headline = 'Retrying ' . $stage_action . $target_suffix;
				if ( '' !== $failure_summary ) {
					$headline .= ' after ' . $failure_summary;
				}
				return $headline . '.';

			case 'verifying':
				return 'Verifying results' . $target_suffix . '.';

			case 'blocked':
				if ( 'worker_cancelled' === $event_reason ) {
					return 'Stopped because the parent run was already closed' . $target_suffix . '.';
				}
				if ( '' !== $failure_summary ) {
					$stage_fragment = '' !== $stage_label ? ' during ' . strtolower( $stage_label ) : '';
					return 'Blocked' . $stage_fragment . $target_suffix . ': ' . $failure_summary . '.';
				}
				return 'Blocked' . $target_suffix . '.';

			case 'failed':
				$headline = 'Background work failed' . $target_suffix;
				if ( '' !== $failure_summary ) {
					$headline .= ': ' . $failure_summary;
				}
				return $headline . '.';

			case 'complete':
				if ( 'verify' === sanitize_key( (string) ( $task['result']['checkpoint']['workflow_stage'] ?? '' ) ) ) {
					return 'Verification completed' . $target_suffix . '.';
				}
				return 'Background work completed' . $target_suffix . '.';

			case 'running':
				return 'Currently ' . $stage_action . $target_suffix . '.';

			case 'queued':
			default:
				return 'Queued to start ' . $stage_action . $target_suffix . '.';
		}
	}

	private function build_progress_summary( string $state_label, string $stage_label, string $event_label, string $target_label ): string {
		$parts = array();

		if ( '' !== $state_label ) {
			$parts[] = 'State: ' . $state_label;
		}
		if ( '' !== $stage_label ) {
			$parts[] = 'Stage: ' . $stage_label;
		}
		if ( '' !== $event_label ) {
			$parts[] = 'Latest: ' . $event_label;
		}
		if ( '' !== $target_label ) {
			$parts[] = 'Target: ' . $target_label;
		}

		return implode( ' • ', $parts );
	}

	private function build_progress_milestone_summary( array $task, array $execution, string $state_key ): string {
		$parts     = array();
		$completed = array_slice( (array) ( $execution['completed_labels'] ?? array() ), 0, 2 );
		$remaining = array_slice( (array) ( $execution['remaining_labels'] ?? array() ), 0, 2 );
		$blocked   = array_slice( (array) ( $execution['blocked_labels'] ?? array() ), 0, 2 );
		$receipts  = array_slice( (array) ( $execution['recent_receipts'] ?? array() ), 0, 1 );

		if ( ! empty( $completed ) ) {
			$parts[] = 'Completed: ' . implode( ', ', $completed );
		}

		if ( ! empty( $blocked ) ) {
			$parts[] = 'Blocked: ' . implode( ', ', $blocked );
		} elseif ( ! empty( $remaining ) ) {
			$prefix  = in_array( $state_key, array( 'waiting', 'retrying' ), true ) ? 'Next when resumed' : 'Next';
			$parts[] = $prefix . ': ' . $remaining[0];
		} elseif ( ! empty( $receipts ) ) {
			$parts[] = 'Latest receipt: ' . $receipts[0];
		} elseif ( ! empty( $task['handoff_capsule']['summary'] ) ) {
			$parts[] = 'Goal: ' . sanitize_text_field( (string) $task['handoff_capsule']['summary'] );
		}

		return implode( ' • ', $parts );
	}

	private function summarize_failure_message( string $message ): string {
		$message = trim( sanitize_text_field( $message ) );
		if ( '' === $message ) {
			return '';
		}

		if ( mb_strlen( $message ) > 96 ) {
			return mb_substr( $message, 0, 93 ) . '...';
		}

		return rtrim( $message, '.' );
	}

	private function log_insert_failure( string $task_id, ?string $idempotency_key, string $db_error ): void {
		PressArk_Error_Tracker::error( 'TaskStore', 'Task insert failed', array( 'task_id' => $task_id, 'idempotency_key' => $idempotency_key ?: 'none', 'db_error' => $db_error ?: 'unknown database error' ) );
	}

	/**
	 * Decode JSON columns and cast types for a task row.
	 */
	private function decode_row( array $row ): array {
		$row['run_id']          = (string) ( $row['run_id'] ?? '' );
		$row['parent_run_id']   = (string) ( $row['parent_run_id'] ?? '' );
		$row['root_run_id']     = (string) ( $row['root_run_id'] ?? '' );
		$row['handoff_capsule'] = json_decode( $row['handoff_capsule'] ?? 'null', true );
		$row['payload']     = json_decode( $row['payload'] ?? '{}', true ) ?: array();
		$row['result']      = json_decode( $row['result'] ?? 'null', true );
		$row['retries']     = (int) $row['retries'];
		$row['max_retries'] = (int) $row['max_retries'];
		$row['user_id']     = (int) $row['user_id'];
		return $row;
	}
}
