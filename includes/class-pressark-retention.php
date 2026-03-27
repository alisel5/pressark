<?php
/**
 * PressArk Retention
 *
 * Configurable data-retention policies with batched cleanup for:
 *   - pressark_log            (action audit trail)
 *   - pressark_chats          (chat conversations)
 *   - pressark_cost_ledger    (cost telemetry)
 *   - pressark_runs           (execution runs — terminal states only)
 *   - pressark_tasks          (async tasks — terminal states only)
 *   - pressark_automations    (archived automations)
 *
 * Runs and tasks have their own short-lived cleanup (hours) in their
 * respective Store classes. The retention layer here is a safety net
 * for anything that slips through, using the same configurable window.
 *
 * @since 2.6.0
 * @since 4.1.0 Added runs, tasks, and automations cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Retention {

	/** Option keys stored in wp_options (all values in days). */
	public const OPTION_LOG_DAYS         = 'pressark_retention_log_days';
	public const OPTION_CHAT_DAYS        = 'pressark_retention_chat_days';
	public const OPTION_LEDGER_DAYS      = 'pressark_retention_ledger_days';
	public const OPTION_RUNS_DAYS        = 'pressark_retention_runs_days';
	public const OPTION_TASKS_DAYS       = 'pressark_retention_tasks_days';
	public const OPTION_AUTOMATIONS_DAYS = 'pressark_retention_automations_days';

	/** Default retention periods. */
	private const DEFAULT_LOG_DAYS         = 90;
	private const DEFAULT_CHAT_DAYS        = 180;
	private const DEFAULT_LEDGER_DAYS      = 365;
	private const DEFAULT_RUNS_DAYS        = 30;   // Terminal runs (settled/failed).
	private const DEFAULT_TASKS_DAYS       = 30;   // Terminal tasks (delivered/dead_letter).
	private const DEFAULT_AUTOMATIONS_DAYS = 90;   // Archived automations.

	/** Maximum rows per DELETE pass to avoid long table locks. */
	private const BATCH_SIZE = 1000;

	/**
	 * Get the configured retention period for a given option key.
	 *
	 * @param string $option_key One of the OPTION_* constants.
	 * @return int Days.
	 */
	public static function get_retention_days( string $option_key ): int {
		$defaults = array(
			self::OPTION_LOG_DAYS         => self::DEFAULT_LOG_DAYS,
			self::OPTION_CHAT_DAYS        => self::DEFAULT_CHAT_DAYS,
			self::OPTION_LEDGER_DAYS      => self::DEFAULT_LEDGER_DAYS,
			self::OPTION_RUNS_DAYS        => self::DEFAULT_RUNS_DAYS,
			self::OPTION_TASKS_DAYS       => self::DEFAULT_TASKS_DAYS,
			self::OPTION_AUTOMATIONS_DAYS => self::DEFAULT_AUTOMATIONS_DAYS,
		);

		$default = $defaults[ $option_key ] ?? 90;
		return max( 7, (int) get_option( $option_key, $default ) );
	}

	/**
	 * Get all retention settings as an associative array.
	 *
	 * @return array{log: int, chats: int, ledger: int, runs: int, tasks: int, automations: int}
	 */
	public static function get_all(): array {
		return array(
			'log'         => self::get_retention_days( self::OPTION_LOG_DAYS ),
			'chats'       => self::get_retention_days( self::OPTION_CHAT_DAYS ),
			'ledger'      => self::get_retention_days( self::OPTION_LEDGER_DAYS ),
			'runs'        => self::get_retention_days( self::OPTION_RUNS_DAYS ),
			'tasks'       => self::get_retention_days( self::OPTION_TASKS_DAYS ),
			'automations' => self::get_retention_days( self::OPTION_AUTOMATIONS_DAYS ),
		);
	}

	/**
	 * Run all retention cleanups. Called by the daily cron.
	 *
	 * @return int Total rows deleted across all tables.
	 */
	public function run_all(): int {
		$total  = $this->cleanup_logs();
		$total += $this->cleanup_chats();
		$total += $this->cleanup_ledger();
		$total += $this->cleanup_runs();
		$total += $this->cleanup_tasks();
		$total += $this->cleanup_automations();
		return $total;
	}

	/**
	 * Delete old rows from pressark_log where created_at is older than the retention window.
	 * Uses the existing KEY created_at index.
	 *
	 * @return int Total rows deleted.
	 */
	public function cleanup_logs(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_log';
		$days   = self::get_retention_days( self::OPTION_LOG_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s ORDER BY created_at ASC LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Delete old rows from pressark_chats where updated_at is older than the retention window.
	 * Uses updated_at (not created_at) so recently-used chats are never deleted.
	 * Uses the existing KEY updated_at index.
	 *
	 * @return int Total rows deleted.
	 */
	public function cleanup_chats(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_chats';
		$days   = self::get_retention_days( self::OPTION_CHAT_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Delete old rows from pressark_cost_ledger where created_at is older than the retention window.
	 * Only deletes settled, failed, and expired rows — never touches 'reserved' (in-flight).
	 * Uses the existing KEY idx_status_created (status, created_at).
	 *
	 * @return int Total rows deleted.
	 */
	public function cleanup_ledger(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_cost_ledger';
		$days   = self::get_retention_days( self::OPTION_LEDGER_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE status IN ('settled', 'failed', 'expired')
				   AND created_at < %s
				 ORDER BY created_at ASC
				 LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Delete old terminal runs (settled, failed) past the retention window.
	 *
	 * The Run_Store's own cleanup_expired() handles short-lived cleanup
	 * (settled 24h, failed 7d, stale awaiting 2h). This retention layer
	 * is a configurable safety net for anything that slipped through.
	 *
	 * Uses terminal timestamps rather than created_at so recently-settled or
	 * recently-failed runs are retained correctly even if the run itself is old.
	 *
	 * @since 4.1.0
	 * @return int Total rows deleted.
	 */
	public function cleanup_runs(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_runs';
		$days   = self::get_retention_days( self::OPTION_RUNS_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE (
					(status = 'settled' AND COALESCE(settled_at, updated_at, created_at) < %s)
					OR
					(status = 'failed' AND COALESCE(updated_at, created_at) < %s)
				 )
				 ORDER BY COALESCE(settled_at, updated_at, created_at) ASC
				 LIMIT %d",
				$cutoff,
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Delete old terminal tasks past the retention window.
	 *
	 * The Task_Store's own cleanup_expired() handles state normalization.
	 * This retention layer owns the durable inbox window and uses read_at
	 * before completed_at so recently-viewed results are not purged early.
	 *
	 * @since 4.1.0
	 * @return int Total rows deleted.
	 */
	public function cleanup_tasks(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_tasks';
		$days   = self::get_retention_days( self::OPTION_TASKS_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE (
					(status IN ('complete', 'delivered', 'undelivered', 'failed') AND COALESCE(read_at, completed_at, started_at, created_at) < %s)
					OR
					(status = 'dead_letter' AND COALESCE(expires_at, completed_at, started_at, created_at) < %s)
				 )
				 ORDER BY COALESCE(read_at, completed_at, expires_at, started_at, created_at) ASC
				 LIMIT %d",
				$cutoff,
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Delete old archived automations past the retention window.
	 *
	 * Only removes 'archived' automations (one-shot completed). Active,
	 * paused, and failed automations are never touched by retention.
	 *
	 * @since 4.1.0
	 * @return int Total rows deleted.
	 */
	public function cleanup_automations(): int {
		global $wpdb;

		$table  = $wpdb->prefix . 'pressark_automations';
		$days   = self::get_retention_days( self::OPTION_AUTOMATIONS_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE status = 'archived'
				   AND updated_at < %s
				 ORDER BY updated_at ASC
				 LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			) );
			$total += $deleted;
		} while ( $deleted >= self::BATCH_SIZE );

		return $total;
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Register retention-related WordPress hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'schedule_cleanup' ) );
		add_action( 'pressark_retention_cleanup', array( self::class, 'handle_cleanup' ) );
	}

	/**
	 * @since 4.2.0
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'pressark_retention_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'pressark_retention_cleanup' );
		}
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_cleanup(): void {
		$retention = new self();
		$total     = $retention->run_all();
		if ( $total > 0 ) {
			PressArk_Error_Tracker::info( 'Retention', 'Cleanup completed', array( 'rows_deleted' => $total ) );
		}
	}
}
