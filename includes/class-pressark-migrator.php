<?php
/**
 * PressArk Schema Migrator.
 *
 * Single authority for schema evolution and migration drift recovery.
 *
 * @package PressArk
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Migrator {

	/**
	 * Highest schema version in the migration chain.
	 */
	const LATEST = 16;

	/**
	 * Option key where the current schema version is stored.
	 */
	const OPTION_KEY = 'pressark_schema_version';

	/**
	 * Map of plugin tables to the version that introduced them.
	 */
	const TABLE_VERSIONS = array(
		'pressark_log'           => 1,
		'pressark_chats'         => 1,
		'pressark_content_index' => 1,
		'pressark_cost_ledger'   => 1,
		'pressark_tasks'         => 2,
		'pressark_runs'          => 3,
		'pressark_automations'   => 5,
		'pressark_alert_batches' => 12,
		'pressark_activity_events' => 14,
	);

	/**
	 * Run all pending migrations and return the highest verified version.
	 */
	public static function run_all(): int {
		$current = self::get_version();
		$health  = self::schema_health();

		if ( $current >= self::LATEST && $health['healthy'] ) {
			self::cleanup_legacy_options();
			return self::LATEST;
		}

		if ( $health['required_version'] < $current ) {
			$current = $health['required_version'];
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$achieved = $current;

		for ( $version = $current + 1; $version <= self::LATEST; $version++ ) {
			$method = 'migrate_to_' . $version;
			if ( ! method_exists( self::class, $method ) ) {
				self::log_migration_failure( $version, 'Migration method is missing.' );
				break;
			}

			if ( ! self::$method() ) {
				self::log_migration_failure( $version, 'Migration step returned failure.' );
				break;
			}

			$verification = self::verify_version( $version );
			if ( '' !== $verification ) {
				self::log_migration_failure( $version, $verification );
				break;
			}

			update_option( self::OPTION_KEY, $version, false );
			$achieved = $version;
		}

		if ( $achieved === self::LATEST && self::schema_health()['healthy'] ) {
			self::cleanup_legacy_options();
		}

		return $achieved;
	}

	/**
	 * Upgrade on normal plugin load when activation was skipped.
	 */
	public static function maybe_upgrade(): void {
		$current = self::get_version();
		$health  = self::schema_health();

		if ( $current < self::LATEST || ! $health['healthy'] ) {
			self::run_all();
			return;
		}

		self::cleanup_legacy_options();
	}

	/**
	 * Get the stored schema version.
	 */
	public static function get_version(): int {
		return (int) get_option( self::OPTION_KEY, 0 );
	}

	/**
	 * Public schema health report for diagnostics and tests.
	 *
	 * @return array{healthy: bool, required_version: int, issues: array<int, string>}
	 */
	public static function schema_health(): array {
		global $wpdb;

		$issues = array();

		foreach ( self::TABLE_VERSIONS as $short_name => $introduced_at ) {
			$table = $wpdb->prefix . $short_name;
			if ( ! self::table_exists( $table ) ) {
				$issues[] = array(
					'version' => $introduced_at,
					'message' => sprintf( 'Missing table %s.', $table ),
				);
			}
		}

		$index_table = $wpdb->prefix . 'pressark_content_index';
		if ( self::table_exists( $index_table ) && ! self::table_has_index( $index_table, 'ft_content' ) ) {
			$issues[] = array(
				'version' => 1,
				'message' => sprintf( 'Missing FULLTEXT index ft_content on %s.', $index_table ),
			);
		}

		$tasks_table = $wpdb->prefix . 'pressark_tasks';
		if ( self::table_exists( $tasks_table ) && ! self::table_has_column( $tasks_table, 'idempotency_key' ) ) {
			$issues[] = array(
				'version' => 2,
				'message' => sprintf( 'Missing idempotency_key column on %s.', $tasks_table ),
			);
		}
		$runs_table = $wpdb->prefix . 'pressark_runs';
		if ( self::table_exists( $runs_table ) && ! self::table_has_column( $runs_table, 'chat_id' ) ) {
			$issues[] = array(
				'version' => 4,
				'message' => sprintf( 'Missing chat_id column on %s.', $runs_table ),
			);
		}
		if ( self::table_exists( $runs_table ) && ! self::table_has_index( $runs_table, 'idx_chat_id' ) ) {
			$issues[] = array(
				'version' => 4,
				'message' => sprintf( 'Missing idx_chat_id index on %s.', $runs_table ),
			);
		}

		if ( ! self::bundled_key_migration_satisfied() ) {
			$issues[] = array(
				'version' => 6,
				'message' => 'Bundled API key migration is incomplete.',
			);
		}

		// v7: Activity infrastructure columns.
		if ( self::table_exists( $runs_table ) && ! self::table_has_column( $runs_table, 'task_id' ) ) {
			$issues[] = array(
				'version' => 7,
				'message' => sprintf( 'Missing task_id column on %s.', $runs_table ),
			);
		}
		if ( self::table_exists( $tasks_table ) && ! self::table_has_column( $tasks_table, 'read_at' ) ) {
			$issues[] = array(
				'version' => 7,
				'message' => sprintf( 'Missing read_at column on %s.', $tasks_table ),
			);
		}

		if ( self::table_exists( $tasks_table ) && ! self::table_has_column( $tasks_table, 'idempotency_active' ) ) {
			$issues[] = array(
				'version' => 8,
				'message' => sprintf( 'Missing idempotency_active column on %s.', $tasks_table ),
			);
		}
		if ( self::table_exists( $tasks_table ) && ! self::table_has_index( $tasks_table, 'uniq_idempotency_active' ) ) {
			$issues[] = array(
				'version' => 8,
				'message' => sprintf( 'Missing uniq_idempotency_active index on %s.', $tasks_table ),
			);
		}
		if ( self::table_exists( $tasks_table ) && self::table_has_index( $tasks_table, 'idx_idempotency' ) && self::table_index_is_unique( $tasks_table, 'idx_idempotency' ) ) {
			$issues[] = array(
				'version' => 8,
				'message' => sprintf( 'Legacy unique idx_idempotency is still present on %s.', $tasks_table ),
			);
		}

		$ledger_table = $wpdb->prefix . 'pressark_cost_ledger';
		foreach ( array( 'estimated_icus', 'settled_icus', 'model_class', 'model_multiplier_input', 'model_multiplier_output' ) as $column ) {
			if ( self::table_exists( $ledger_table ) && ! self::table_has_column( $ledger_table, $column ) ) {
				$issues[] = array(
					'version' => 11,
					'message' => sprintf( 'Missing %1$s column on %2$s.', $column, $ledger_table ),
				);
			}
		}

		// v13: Event trigger columns on automations.
		$automations_table = $wpdb->prefix . 'pressark_automations';
		if ( self::table_exists( $automations_table ) && ! self::table_has_column( $automations_table, 'event_trigger' ) ) {
			$issues[] = array(
				'version' => 13,
				'message' => sprintf( 'Missing event_trigger column on %s.', $automations_table ),
			);
		}
		if ( self::table_exists( $automations_table ) && ! self::table_has_index( $automations_table, 'idx_event_trigger' ) ) {
			$issues[] = array(
				'version' => 13,
				'message' => sprintf( 'Missing idx_event_trigger index on %s.', $automations_table ),
			);
		}

		// v15: Queue-native lineage and handoff capsules for runs/tasks.
		foreach ( array( 'parent_run_id', 'root_run_id', 'handoff_capsule' ) as $column ) {
			if ( self::table_exists( $runs_table ) && ! self::table_has_column( $runs_table, $column ) ) {
				$issues[] = array(
					'version' => 15,
					'message' => sprintf( 'Missing %1$s column on %2$s.', $column, $runs_table ),
				);
			}
		}
		if ( self::table_exists( $runs_table ) && ! self::table_has_index( $runs_table, 'idx_parent_run_created' ) ) {
			$issues[] = array(
				'version' => 15,
				'message' => sprintf( 'Missing idx_parent_run_created index on %s.', $runs_table ),
			);
		}
		if ( self::table_exists( $runs_table ) && ! self::table_has_index( $runs_table, 'idx_root_run_created' ) ) {
			$issues[] = array(
				'version' => 15,
				'message' => sprintf( 'Missing idx_root_run_created index on %s.', $runs_table ),
			);
		}

		foreach ( array( 'run_id', 'parent_run_id', 'root_run_id', 'handoff_capsule' ) as $column ) {
			if ( self::table_exists( $tasks_table ) && ! self::table_has_column( $tasks_table, $column ) ) {
				$issues[] = array(
					'version' => 15,
					'message' => sprintf( 'Missing %1$s column on %2$s.', $column, $tasks_table ),
				);
			}
		}
		foreach ( array( 'idx_run_created', 'idx_parent_run_created', 'idx_root_run_created' ) as $index_name ) {
			if ( self::table_exists( $tasks_table ) && ! self::table_has_index( $tasks_table, $index_name ) ) {
				$issues[] = array(
					'version' => 15,
					'message' => sprintf( 'Missing %1$s index on %2$s.', $index_name, $tasks_table ),
				);
			}
		}

		$events_table = $wpdb->prefix . 'pressark_activity_events';
		foreach ( array( 'idx_event_type_created', 'idx_reason_created' ) as $index_name ) {
			if ( self::table_exists( $events_table ) && ! self::table_has_index( $events_table, $index_name ) ) {
				$issues[] = array(
					'version' => 16,
					'message' => sprintf( 'Missing %1$s index on %2$s.', $index_name, $events_table ),
				);
			}
		}

		usort( $issues, static function ( array $a, array $b ): int {
			return $a['version'] <=> $b['version'];
		} );

		$required_version = self::LATEST;
		if ( ! empty( $issues ) ) {
			$required_version = max( 0, (int) $issues[0]['version'] - 1 );
		}

		return array(
			'healthy'          => empty( $issues ),
			'required_version' => $required_version,
			'issues'           => array_map(
				static function ( array $issue ): string {
					return $issue['message'];
				},
				$issues
			),
		);
	}

	/**
	 * Detect the earliest version that needs to be re-run.
	 */
	private static function detect_drift(): int {
		$health = self::schema_health();
		return $health['required_version'];
	}

	/**
	 * v1: Core tables and initial FULLTEXT index.
	 */
	private static function migrate_to_1(): bool {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$log_table = $wpdb->prefix . 'pressark_log';
		dbDelta( "CREATE TABLE {$log_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			action_type VARCHAR(50) NOT NULL,
			target_id BIGINT(20) UNSIGNED DEFAULT NULL,
			target_type VARCHAR(50) DEFAULT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			undone TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY action_type (action_type),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};" );

		$chats_table = $wpdb->prefix . 'pressark_chats';
		dbDelta( "CREATE TABLE {$chats_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			messages LONGTEXT DEFAULT NULL,
			checkpoint LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) {$charset_collate};" );

		$index_table = $wpdb->prefix . 'pressark_content_index';
		dbDelta( "CREATE TABLE {$index_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(50) NOT NULL DEFAULT 'post',
			chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(500) NOT NULL DEFAULT '',
			content LONGTEXT NOT NULL,
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			word_count INT UNSIGNED NOT NULL DEFAULT 0,
			meta_data TEXT,
			indexed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_post_type (post_type),
			KEY idx_content_hash (content_hash)
		) {$charset_collate};" );

		dbDelta( PressArk_Cost_Ledger::get_schema() );

		return self::ensure_fulltext_index( $index_table, 'ft_content', 'title, content' );
	}

	/**
	 * v2: Tasks table.
	 */
	private static function migrate_to_2(): bool {
		dbDelta( PressArk_Task_Store::get_schema() );
		return true;
	}

	/**
	 * v3: Runs table.
	 */
	private static function migrate_to_3(): bool {
		dbDelta( PressArk_Run_Store::get_schema() );
		return true;
	}

	/**
	 * v4: Runs table chat_id column and index.
	 */
	private static function migrate_to_4(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pressark_runs';

		dbDelta( PressArk_Run_Store::get_schema() );

		return self::ensure_index( $table, 'idx_chat_id', 'chat_id' );
	}

	/**
	 * v5: Automations table.
	 */
	private static function migrate_to_5(): bool {
		dbDelta( PressArk_Automation_Store::get_schema() );
		return true;
	}

	/**
	 * v6: One-time bundled API key encryption migration.
	 */
	private static function migrate_to_6(): bool {
		if ( self::bundled_key_migration_satisfied() ) {
			return true;
		}

		$value = (string) get_option( 'pressark_api_key', '' );
		if ( '' === $value ) {
			update_option( 'pressark_bundled_key_encrypted', '1', false );
			return true;
		}

		self::load_usage_tracker();
		if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
			return false;
		}

		if ( self::looks_encrypted( $value ) ) {
			update_option( 'pressark_bundled_key_encrypted', '1', false );
			return true;
		}

		$encrypted = PressArk_Usage_Tracker::encrypt_value( $value );
		if ( '' === $encrypted ) {
			return false;
		}

		if ( PressArk_Usage_Tracker::decrypt_value( $encrypted ) !== $value ) {
			return false;
		}

		update_option( 'pressark_api_key', $encrypted, false );
		update_option( 'pressark_bundled_key_encrypted', '1', false );

		return true;
	}

	/**
	 * v7: Activity infrastructure — add task_id + error_summary to runs,
	 * read_at to tasks. Extends retention and enables cross-referencing.
	 */
	private static function migrate_to_7(): bool {
		global $wpdb;

		// dbDelta handles additive column changes.
		dbDelta( PressArk_Run_Store::get_schema() );
		dbDelta( PressArk_Task_Store::get_schema() );

		$runs_table  = $wpdb->prefix . 'pressark_runs';
		$tasks_table = $wpdb->prefix . 'pressark_tasks';

		// Ensure indexes exist (dbDelta can miss index-only changes).
		self::ensure_index( $runs_table, 'idx_task_id', 'task_id' );

		return true;
	}

	/**
	 * v8: Active-slot idempotency for tasks.
	 *
	 * Replaces the legacy globally unique idempotency_key constraint with a
	 * composite unique index that only applies while a task is in-flight.
	 */
	private static function migrate_to_8(): bool {
		global $wpdb;

		$tasks_table = $wpdb->prefix . 'pressark_tasks';

		dbDelta( PressArk_Task_Store::get_schema() );

		if ( self::table_has_index( $tasks_table, 'idx_idempotency' ) ) {
			self::drop_index( $tasks_table, 'idx_idempotency' );
		}

		dbDelta( PressArk_Task_Store::get_schema() );
		self::ensure_unique_index( $tasks_table, 'uniq_idempotency_active', 'idempotency_key, idempotency_active' );

		if ( self::table_has_column( $tasks_table, 'idempotency_active' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL migration on hardcoded prefixed table, no user input.
			$wpdb->query(
				"UPDATE {$tasks_table}
				 SET idempotency_active = CASE
				 	WHEN idempotency_key IS NULL OR idempotency_key = '' THEN NULL
				 	WHEN status IN ('queued', 'running') THEN 1
				 	ELSE NULL
				 END"
			);
		}

		return true;
	}

	/**
	 * v9: Verify all secrets are Sodium-encrypted.
	 *
	 * The original OpenSSL → Sodium re-encryption ran in prior releases.
	 * Any values still not Sodium-decryptable are unrecoverable and skipped.
	 */
	private static function migrate_to_9(): bool {
		$options = array(
			'pressark_api_key',
			'pressark_byok_api_key',
			'pressark_telegram_bot_token',
		);

		foreach ( $options as $option_key ) {
			$stored = (string) get_option( $option_key, '' );
			if ( '' === $stored ) {
				continue;
			}

			// Already Sodium-encrypted — nothing to do.
			if ( '' !== PressArk_Usage_Tracker::decrypt_value( $stored ) ) {
				continue;
			}

			// Not decryptable — clear the stale value so the user re-enters it.
			delete_option( $option_key );
		}

		return true;
	}

	/**
	 * v10: Set autoload=false on options that don't need to load on every page.
	 */
	private static function migrate_to_10(): bool {
		global $wpdb;
		$options = array(
			'pressark_api_provider',
			'pressark_api_key',
			'pressark_byok_api_key',
			'pressark_byok_provider',
			'pressark_byok_model',
			'pressark_byok_enabled',
			'pressark_license_key',
			'pressark_upgrade_url',
			'pressark_custom_model',
		);
		$placeholders = implode( ',', array_fill( 0, count( $options ), '%s' ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($placeholders)",
			...$options
		) );
		return true;
	}

	/**
	 * v11: Refresh the local cost ledger schema for ICU tracking columns.
	 */
	private static function migrate_to_11(): bool {
		dbDelta( PressArk_Cost_Ledger::get_schema() );
		return true;
	}

	/**
	 * v12: Alert batches table for Watchdog atomic batching.
	 */
	private static function migrate_to_12(): bool {
		dbDelta( PressArk_Watchdog_Alerter::get_schema() );
		return true;
	}

	/**
	 * v13: Event trigger columns on the automations table.
	 *
	 * Adds event_trigger, event_trigger_cooldown, and last_triggered_at
	 * columns to support event-driven automation dispatch.
	 */
	private static function migrate_to_13(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'pressark_automations';

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		if ( ! self::table_has_column( $table, 'event_trigger' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_trigger VARCHAR(64) DEFAULT NULL AFTER cadence_value" );
		}

		if ( ! self::table_has_column( $table, 'event_trigger_cooldown' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_trigger_cooldown INT UNSIGNED DEFAULT 3600 AFTER event_trigger" );
		}

		if ( ! self::table_has_column( $table, 'last_triggered_at' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN last_triggered_at DATETIME DEFAULT NULL AFTER event_trigger_cooldown" );
		}

		self::ensure_index( $table, 'idx_event_trigger', 'status, event_trigger' );

		return true;
	}

	/**
	 * v14: Canonical activity events and correlation IDs.
	 */
	private static function migrate_to_14(): bool {
		dbDelta( PressArk_Run_Store::get_schema() );
		dbDelta( PressArk_Activity_Event_Store::get_schema() );
		return true;
	}

	/**
	 * v15: Queue-native lineage and handoff capsule columns on runs/tasks.
	 */
	private static function migrate_to_15(): bool {
		global $wpdb;

		dbDelta( PressArk_Run_Store::get_schema() );
		dbDelta( PressArk_Task_Store::get_schema() );

		$runs_table  = $wpdb->prefix . 'pressark_runs';
		$tasks_table = $wpdb->prefix . 'pressark_tasks';

		self::ensure_index( $runs_table, 'idx_parent_run_created', 'parent_run_id, created_at' );
		self::ensure_index( $runs_table, 'idx_root_run_created', 'root_run_id, created_at' );
		self::ensure_index( $tasks_table, 'idx_run_created', 'run_id, created_at' );
		self::ensure_index( $tasks_table, 'idx_parent_run_created', 'parent_run_id, created_at' );
		self::ensure_index( $tasks_table, 'idx_root_run_created', 'root_run_id, created_at' );

		return true;
	}

	/**
	 * v16: Activity-event indexes for policy diagnostics queries.
	 */
	private static function migrate_to_16(): bool {
		global $wpdb;

		dbDelta( PressArk_Activity_Event_Store::get_schema() );

		$events_table = $wpdb->prefix . 'pressark_activity_events';
		self::ensure_index( $events_table, 'idx_event_type_created', 'event_type, created_at' );
		self::ensure_index( $events_table, 'idx_reason_created', 'reason, created_at' );

		return true;
	}

	/**
	 * Ensure a FULLTEXT index exists on a table.
	 */
	private static function ensure_fulltext_index( string $table, string $index_name, string $columns ): bool {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		if ( ! self::table_has_index( $table, $index_name ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT {$index_name} ({$columns})" );
		}

		return self::table_has_index( $table, $index_name );
	}

	/**
	 * Ensure a regular index exists on a table.
	 */
	private static function ensure_index( string $table, string $index_name, string $columns ): bool {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		if ( ! self::table_has_index( $table, $index_name ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$index_name} ({$columns})" );
		}

		return self::table_has_index( $table, $index_name );
	}

	/**
	 * Ensure a unique index exists on a table.
	 */
	private static function ensure_unique_index( string $table, string $index_name, string $columns ): bool {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		if ( ! self::table_has_index( $table, $index_name ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE INDEX {$index_name} ({$columns})" );
		}

		return self::table_has_index( $table, $index_name ) && self::table_index_is_unique( $table, $index_name );
	}

	/**
	 * Drop an index if it exists.
	 */
	private static function drop_index( string $table, string $index_name ): bool {
		global $wpdb;

		if ( ! self::table_exists( $table ) || ! self::table_has_index( $table, $index_name ) ) {
			return true;
		}

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$index_name}" );

		return ! self::table_has_index( $table, $index_name );
	}

	/**
	 * Verify postconditions for a migration version.
	 *
	 * Returns an empty string when the version is healthy.
	 */
	private static function verify_version( int $version ): string {
		global $wpdb;

		switch ( $version ) {
			case 1:
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_log' ) ) {
					return 'Core log table is missing.';
				}
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_chats' ) ) {
					return 'Core chats table is missing.';
				}
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_content_index' ) ) {
					return 'Content index table is missing.';
				}
				if ( ! self::table_has_index( $wpdb->prefix . 'pressark_content_index', 'ft_content' ) ) {
					return 'Content index FULLTEXT index is missing.';
				}
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_cost_ledger' ) ) {
					return 'Cost ledger table is missing.';
				}
				return '';

			case 2:
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_tasks' ) ) {
					return 'Tasks table is missing.';
				}
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_tasks', 'idempotency_key' ) ) {
					return 'Tasks table is missing idempotency_key.';
				}
				return '';

			case 3:
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_runs' ) ) {
					return 'Runs table is missing.';
				}
				return '';

			case 4:
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_runs', 'chat_id' ) ) {
					return 'Runs table is missing chat_id.';
				}
				if ( ! self::table_has_index( $wpdb->prefix . 'pressark_runs', 'idx_chat_id' ) ) {
					return 'Runs table is missing idx_chat_id.';
				}
				return '';

			case 5:
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_automations' ) ) {
					return 'Automations table is missing.';
				}
				return '';

			case 6:
				if ( ! self::bundled_key_migration_satisfied() ) {
					return 'Bundled API key migration is incomplete.';
				}
				return '';

			case 7:
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_runs', 'task_id' ) ) {
					return 'Runs table is missing task_id column.';
				}
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_runs', 'error_summary' ) ) {
					return 'Runs table is missing error_summary column.';
				}
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_tasks', 'read_at' ) ) {
					return 'Tasks table is missing read_at column.';
				}
				return '';

			case 8:
				if ( ! self::table_has_column( $wpdb->prefix . 'pressark_tasks', 'idempotency_active' ) ) {
					return 'Tasks table is missing idempotency_active.';
				}
				if ( ! self::table_has_index( $wpdb->prefix . 'pressark_tasks', 'uniq_idempotency_active' ) ) {
					return 'Tasks table is missing uniq_idempotency_active.';
				}
				if ( self::table_has_index( $wpdb->prefix . 'pressark_tasks', 'idx_idempotency' ) && self::table_index_is_unique( $wpdb->prefix . 'pressark_tasks', 'idx_idempotency' ) ) {
					return 'Tasks table still has the legacy unique idx_idempotency.';
				}
				return '';

			case 9:
				// Verify all encrypted options are readable with Sodium.
				foreach ( array( 'pressark_api_key', 'pressark_byok_api_key', 'pressark_telegram_bot_token' ) as $opt ) {
					$stored = (string) get_option( $opt, '' );
					if ( '' !== $stored && '' === PressArk_Usage_Tracker::decrypt_value( $stored ) ) {
						return "Option {$opt} is not decryptable with Sodium.";
					}
				}
				return '';

			case 10:
				// No strict postcondition — the UPDATE is best-effort for existing rows.
				return '';

			case 12:
				if ( ! self::table_exists( $wpdb->prefix . 'pressark_alert_batches' ) ) {
					return 'Alert batches table is missing.';
				}
				return '';

			case 13:
				$auto_table = $wpdb->prefix . 'pressark_automations';
				if ( ! self::table_has_column( $auto_table, 'event_trigger' ) ) {
					return 'Automations table is missing event_trigger column.';
				}
				if ( ! self::table_has_column( $auto_table, 'event_trigger_cooldown' ) ) {
					return 'Automations table is missing event_trigger_cooldown column.';
				}
				if ( ! self::table_has_column( $auto_table, 'last_triggered_at' ) ) {
					return 'Automations table is missing last_triggered_at column.';
				}
				if ( ! self::table_has_index( $auto_table, 'idx_event_trigger' ) ) {
					return 'Automations table is missing idx_event_trigger index.';
				}
				return '';

			case 14:
				$events_table = $wpdb->prefix . 'pressark_activity_events';
				$runs_table   = $wpdb->prefix . 'pressark_runs';
				if ( ! self::table_exists( $events_table ) ) {
					return 'Activity events table is missing.';
				}
				if ( ! self::table_has_column( $runs_table, 'correlation_id' ) ) {
					return 'Runs table is missing correlation_id column.';
				}
				if ( ! self::table_has_index( $runs_table, 'idx_correlation_id' ) ) {
					return 'Runs table is missing idx_correlation_id.';
				}
				if ( ! self::table_has_index( $events_table, 'idx_correlation' ) ) {
					return 'Activity events table is missing idx_correlation.';
				}
				return '';

			case 16:
				$events_table = $wpdb->prefix . 'pressark_activity_events';
				if ( ! self::table_has_index( $events_table, 'idx_event_type_created' ) ) {
					return 'Activity events table is missing idx_event_type_created.';
				}
				if ( ! self::table_has_index( $events_table, 'idx_reason_created' ) ) {
					return 'Activity events table is missing idx_reason_created.';
				}
				return '';
		}

		return '';
	}

	/**
	 * Load the usage tracker class for migration verification.
	 *
	 * @deprecated 4.2.0 Autoloader handles class loading. Retained for back-compat.
	 */
	private static function load_usage_tracker(): void {
		// No-op: autoloader handles class loading.
	}

	/**
	 * Check whether the bundled-key migration is actually complete.
	 */
	private static function bundled_key_migration_satisfied(): bool {
		if ( ! get_option( 'pressark_bundled_key_encrypted' ) ) {
			return false;
		}

		$value = (string) get_option( 'pressark_api_key', '' );
		if ( '' === $value ) {
			return true;
		}

		return self::looks_encrypted( $value );
	}

	/**
	 * Heuristic check for values encrypted by PressArk_Usage_Tracker.
	 */
	private static function looks_encrypted( string $value ): bool {
		self::load_usage_tracker();
		if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
			return false;
		}

		return PressArk_Usage_Tracker::is_sodium_encrypted( $value );
	}

	/**
	 * Check whether a table exists.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Check whether a column exists on a table.
	 */
	private static function table_has_column( string $table, string $column ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT COLUMN_NAME
			 FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND TABLE_NAME = %s
			 AND COLUMN_NAME = %s
			 LIMIT 1",
			$table,
			$column
		) ) === $column;
	}

	/**
	 * Check whether an index exists on a table.
	 */
	private static function table_has_index( string $table, string $index_name ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare(
			"SELECT INDEX_NAME
			 FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND TABLE_NAME = %s
			 AND INDEX_NAME = %s
			 LIMIT 1",
			$table,
			$index_name
		) ) === $index_name;
	}

	/**
	 * Check whether an index is unique.
	 */
	private static function table_index_is_unique( string $table, string $index_name ): bool {
		global $wpdb;

		return '0' === (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT NON_UNIQUE
			 FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			 AND TABLE_NAME = %s
			 AND INDEX_NAME = %s
			 LIMIT 1",
			$table,
			$index_name
		) );
	}

	/**
	 * Emit a concise migration failure log.
	 */
	private static function log_migration_failure( int $version, string $message ): void {
		PressArk_Error_Tracker::error( 'Migrator', 'Migration failed verification', array( 'version' => $version, 'reason' => $message ) );
	}

	/**
	 * Remove the old maybe_create_* gate options.
	 */
	public static function cleanup_legacy_options(): void {
		$legacy = array(
			'pressark_index_table_created',
			'pressark_tasks_table_created',
			'pressark_runs_table_created',
			'pressark_runs_schema_version',
			'pressark_automations_table_created',
			'pressark_db_version',
		);

		foreach ( $legacy as $option_name ) {
			delete_option( $option_name );
		}
	}
}
