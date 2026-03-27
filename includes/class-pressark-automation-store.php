<?php
/**
 * PressArk Automation Store — Persistence for scheduled prompt automations.
 *
 * Dedicated custom table for first-class automation records.
 * NOT stored as JSON blobs in wp_options.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Automation_Store {

	/**
	 * Get the fully-prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_automations';
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
			automation_id VARCHAR(64) NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			chat_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(255) NOT NULL DEFAULT '',
			prompt LONGTEXT NOT NULL,
			timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
			cadence_type VARCHAR(20) NOT NULL DEFAULT 'once',
			cadence_value INT UNSIGNED NOT NULL DEFAULT 0,
			first_run_at DATETIME NOT NULL,
			next_run_at DATETIME DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			approval_policy VARCHAR(20) NOT NULL DEFAULT 'editorial',
			allowed_groups TEXT DEFAULT NULL,
			last_run_id VARCHAR(64) DEFAULT NULL,
			last_task_id VARCHAR(64) DEFAULT NULL,
			last_success_at DATETIME DEFAULT NULL,
			last_failure_at DATETIME DEFAULT NULL,
			last_error TEXT DEFAULT NULL,
			failure_streak TINYINT UNSIGNED NOT NULL DEFAULT 0,
			claimed_at DATETIME DEFAULT NULL,
			claimed_by VARCHAR(64) DEFAULT NULL,
			execution_hints LONGTEXT DEFAULT NULL,
			notification_channel VARCHAR(20) NOT NULL DEFAULT 'telegram',
			notification_target VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_automation_id (automation_id),
			KEY idx_user_status (user_id, status),
			KEY idx_next_run (status, next_run_at),
			KEY idx_claimed (claimed_at)
		) {$charset_collate};";
	}

	// ── CRUD ─────────────────────────────────────────────────────────

	/**
	 * Insert a new automation.
	 *
	 * @param array $data Automation fields.
	 * @return string The automation_id.
	 */
	public function create( array $data ): string {
		global $wpdb;

		$automation_id = $data['automation_id'] ?? wp_generate_uuid4();
		$first_run_at  = $data['first_run_at'] ?? current_time( 'mysql', true );

		$wpdb->insert(
			self::table_name(),
			array(
				'automation_id'        => $automation_id,
				'user_id'              => absint( $data['user_id'] ?? get_current_user_id() ),
				'chat_id'              => absint( $data['chat_id'] ?? 0 ),
				'name'                 => sanitize_text_field( $data['name'] ?? '' ),
				'prompt'               => wp_kses_post( $data['prompt'] ?? '' ),
				'timezone'             => sanitize_text_field( $data['timezone'] ?? 'UTC' ),
				'cadence_type'         => sanitize_key( $data['cadence_type'] ?? 'once' ),
				'cadence_value'        => absint( $data['cadence_value'] ?? 0 ),
				'first_run_at'         => $first_run_at,
				'next_run_at'          => $data['next_run_at'] ?? $first_run_at,
				'status'               => 'active',
				'approval_policy'      => sanitize_key( $data['approval_policy'] ?? 'editorial' ),
				'allowed_groups'       => ! empty( $data['allowed_groups'] ) ? wp_json_encode( $data['allowed_groups'] ) : null,
				'notification_channel' => sanitize_key( $data['notification_channel'] ?? 'telegram' ),
				'notification_target'  => sanitize_text_field( $data['notification_target'] ?? '' ),
				'execution_hints'      => ! empty( $data['execution_hints'] ) ? wp_json_encode( $data['execution_hints'] ) : null,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $automation_id;
	}

	/**
	 * Read an automation by automation_id.
	 *
	 * @return array|null The automation row, or null.
	 */
	public function get( string $automation_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table_name() . " WHERE automation_id = %s",
				$automation_id
			),
			ARRAY_A
		);

		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * List automations for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Filter by status (empty = all).
	 * @return array
	 */
	public function list_for_user( int $user_id, string $status = '' ): array {
		global $wpdb;
		$table = self::table_name();

		if ( $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY created_at DESC",
				$user_id,
				$status
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND status != 'archived' ORDER BY created_at DESC",
				$user_id
			), ARRAY_A );
		}

		return array_map( array( $this, 'decode_row' ), $rows ?: array() );
	}

	/**
	 * Update specific fields on an automation.
	 *
	 * @param string $automation_id Automation ID.
	 * @param array  $data          Fields to update.
	 * @return bool
	 */
	public function update( string $automation_id, array $data ): bool {
		global $wpdb;

		$allowed_fields = array(
			'name', 'prompt', 'timezone', 'cadence_type', 'cadence_value',
			'first_run_at', 'next_run_at', 'status', 'approval_policy',
			'allowed_groups', 'last_run_id', 'last_task_id', 'last_success_at',
			'last_failure_at', 'last_error', 'failure_streak', 'claimed_at',
			'claimed_by', 'execution_hints', 'notification_channel',
			'notification_target', 'chat_id',
		);

		$update = array( 'updated_at' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed_fields, true ) ) {
				continue;
			}

			if ( in_array( $key, array( 'allowed_groups', 'execution_hints' ), true ) && is_array( $value ) ) {
				$update[ $key ] = wp_json_encode( $value );
			} else {
				$update[ $key ] = $value;
			}
			$formats[] = '%s';
		}

		$rows = $wpdb->update(
			self::table_name(),
			$update,
			array( 'automation_id' => $automation_id ),
			$formats,
			array( '%s' )
		);

		return $rows !== false;
	}

	/**
	 * Delete an automation permanently.
	 */
	public function delete( string $automation_id ): bool {
		global $wpdb;
		$rows = $wpdb->delete(
			self::table_name(),
			array( 'automation_id' => $automation_id ),
			array( '%s' )
		);
		return $rows > 0;
	}

	// ── Dispatch Queries ────────────────────────────────────────────

	/**
	 * Find due automations that need dispatching.
	 *
	 * Returns automations where:
	 * - status = 'active'
	 * - next_run_at <= NOW
	 * - not currently claimed (claimed_at is null or stale > 30 min)
	 *
	 * @param int $limit Max automations to return.
	 * @return array
	 */
	public function find_due( int $limit = 10 ): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE status = 'active'
			 AND next_run_at IS NOT NULL
			 AND next_run_at <= UTC_TIMESTAMP()
			 AND (claimed_at IS NULL OR claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))
			 ORDER BY next_run_at ASC
			 LIMIT %d",
			$limit
		), ARRAY_A );

		return array_map( array( $this, 'decode_row' ), $rows ?: array() );
	}

	/**
	 * Atomically claim an automation for dispatch.
	 * Uses UPDATE ... WHERE to prevent double-dispatch.
	 *
	 * @param string $automation_id Automation ID.
	 * @param string $claim_token   Unique claim identifier.
	 * @return bool True if this caller won the claim.
	 */
	public function claim( string $automation_id, string $claim_token ): bool {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET claimed_at = UTC_TIMESTAMP(),
			     claimed_by = %s,
			     updated_at = UTC_TIMESTAMP()
			 WHERE automation_id = %s
			 AND status = 'active'
			 AND (claimed_at IS NULL OR claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))",
			$claim_token,
			$automation_id
		) );

		return (int) $rows === 1;
	}

	/**
	 * Release a claim after dispatch completes.
	 */
	public function release_claim( string $automation_id ): bool {
		global $wpdb;

		$rows = $wpdb->update(
			self::table_name(),
			array( 'claimed_at' => null, 'claimed_by' => null, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'automation_id' => $automation_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return $rows !== false;
	}

	/**
	 * Record a successful run.
	 */
	public function record_success( string $automation_id, string $run_id, string $task_id = '' ): void {
		$automation = $this->get( $automation_id );
		$update     = array(
			'last_run_id'    => $run_id,
			'last_task_id'   => $task_id,
			'last_success_at' => current_time( 'mysql', true ),
			'last_error'     => null,
			'failure_streak' => 0,
			'claimed_at'     => null,
			'claimed_by'     => null,
		);

		if ( $automation && 'once' === ( $automation['cadence_type'] ?? '' ) ) {
			$update['status'] = 'archived';
		}

		$this->update( $automation_id, $update );
	}

	/**
	 * Record a failed run.
	 */
	public function record_failure( string $automation_id, string $run_id, string $error, string $task_id = '' ): void {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET last_run_id     = %s,
			     last_task_id    = %s,
			     last_failure_at = UTC_TIMESTAMP(),
			     last_error      = %s,
			     failure_streak  = failure_streak + 1,
			     claimed_at      = NULL,
			     claimed_by      = NULL,
			     updated_at      = UTC_TIMESTAMP()
			 WHERE automation_id = %s",
			$run_id,
			$task_id,
			$error,
			$automation_id
		) );

		// Auto-pause after 3 consecutive failures.
		$automation = $this->get( $automation_id );
		if ( ! $automation ) {
			return;
		}

		if ( 'once' === ( $automation['cadence_type'] ?? '' ) ) {
			$this->update( $automation_id, array(
				'status'     => 'failed',
				'next_run_at' => null,
				'last_error' => $error,
			) );
			return;
		}

		if ( $automation['failure_streak'] >= 3 ) {
			$this->update( $automation_id, array(
				'status'     => 'failed',
				'last_error' => 'Auto-paused: 3 consecutive failures. Last: ' . $error,
			) );
		}
	}

	/**
	 * Count automations toward the user's quota.
	 *
	 * Only 'active' automations count. Failed/paused automations do NOT
	 * consume quota — users should be able to create replacements without
	 * having to manually clean up auto-paused entries first.
	 */
	public function count_active( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'active'",
			$user_id
		) );
	}

	// ── Cleanup ──────────────────────────────────────────────────────

	/**
	 * Delete automations for a user (privacy eraser).
	 */
	public function delete_for_user( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			self::table_name(),
			array( 'user_id' => $user_id ),
			array( '%d' )
		);
	}

	// ── Internal ─────────────────────────────────────────────────────

	private function decode_row( array $row ): array {
		$row['user_id']         = (int) $row['user_id'];
		$row['chat_id']         = (int) $row['chat_id'];
		$row['cadence_value']   = (int) $row['cadence_value'];
		$row['failure_streak']  = (int) $row['failure_streak'];
		$row['allowed_groups']  = json_decode( $row['allowed_groups'] ?? 'null', true );
		$row['execution_hints'] = json_decode( $row['execution_hints'] ?? 'null', true );
		return $row;
	}
}
