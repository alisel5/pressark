<?php
/**
 * Cron and scheduler management.
 *
 * Owns custom cron schedules, task/run cleanup, automation dispatch
 * scheduling, and Action Scheduler runner kick.
 *
 * @since 4.3.0 Extracted from pressark.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Cron_Manager {

	/**
	 * Register recurring cron hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) );
		add_action( 'pressark_cleanup_tasks', array( self::class, 'handle_task_cleanup' ) );
	}

	/**
	 * Register custom cron schedules.
	 */
	public static function register_cron_schedules( array $schedules ): array {
		$schedules['every_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'pressark' ),
		);
		return $schedules;
	}

	/**
	 * Clean up expired/stale tasks and run records (daily cron callback).
	 */
	public static function handle_task_cleanup(): void {
		$store = new PressArk_Task_Store();
		$store->cleanup_expired();
		$store->cleanup_stale();

		$run_store = new PressArk_Run_Store();
		$run_store->cleanup_expired();

		// v5.0.1: Clean up expired rate-limit counter rows from wp_options.
		// Atomic counters (pressark_burst_*, pressark_hourly_*, pressark_ip_*)
		// accumulate over time and are never auto-deleted by WordPress.
		PressArk_Throttle::cleanup_expired_counters();
	}

	/**
	 * Schedule the recurring automation dispatcher.
	 *
	 * Action Scheduler is the primary backend when available. WP-Cron runs
	 * as a parallel safety net because AS's self-triggering relies on HTTP
	 * loopback requests which fail in many hosting environments.
	 */
	public static function schedule_automation_dispatch(): void {
		$hook = 'pressark_dispatch_automations';

		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( $hook, null, 'pressark' ) ) {
				as_schedule_recurring_action( time(), 300, $hook, array(), 'pressark' );
			}
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'every_five_minutes', $hook );
		}
	}

	/**
	 * Ensure Action Scheduler processes pending PressArk actions.
	 *
	 * AS relies on HTTP loopback requests to self-trigger its queue runner.
	 * When loopback fails (Docker, reverse proxies, firewalled hosts), all
	 * AS actions remain 'pending' forever. This kicks the AS runner as a
	 * fallback so PressArk tasks actually execute.
	 */
	public static function maybe_kick_as_runner(): void {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hook LIKE %s AND status = %s AND scheduled_date_gmt <= UTC_TIMESTAMP()",
			'pressark%',
			'pending'
		) );

		if ( $pending > 0 ) {
			ActionScheduler::runner()->run();
		}
	}

	/**
	 * Unschedule a PressArk hook from both Action Scheduler and WP-Cron.
	 */
	public static function unschedule_background_hook( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Schedule cron events on plugin activation.
	 */
	public static function activate(): void {
		if ( ! wp_next_scheduled( 'pressark_generate_profile' ) ) {
			wp_schedule_single_event( time() + 10, 'pressark_generate_profile' );
		}
		if ( ! wp_next_scheduled( 'pressark_initial_index' ) ) {
			wp_schedule_single_event( time() + 15, 'pressark_initial_index' );
		}
		if ( ! wp_next_scheduled( 'pressark_cleanup_tasks' ) ) {
			wp_schedule_event( time(), 'daily', 'pressark_cleanup_tasks' );
		}
	}

	/**
	 * Clear all PressArk cron events on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'pressark_generate_profile' );
		wp_clear_scheduled_hook( 'pressark_refresh_profile' );

		wp_clear_scheduled_hook( 'pressark_daily_index_sync' );
		wp_clear_scheduled_hook( 'pressark_initial_index' );
		wp_clear_scheduled_hook( 'pressark_reindex_post' );
		wp_clear_scheduled_hook( 'pressark_index_batch' );
		wp_clear_scheduled_hook( 'pressark_weekly_orphan_cleanup' );

		wp_clear_scheduled_hook( 'pressark_preview_cleanup' );
		wp_clear_scheduled_hook( 'pressark_reconcile_reservations' );

		self::unschedule_background_hook( 'pressark_process_async_task' );
		wp_clear_scheduled_hook( 'pressark_process_task' );
		wp_clear_scheduled_hook( 'pressark_cleanup_tasks' );
		wp_clear_scheduled_hook( 'pressark_retention_cleanup' );

		self::unschedule_background_hook( 'pressark_dispatch_automations' );
		self::unschedule_background_hook( 'pressark_automation_wake' );
		wp_clear_scheduled_hook( 'pressark_kick_as_runner' );
		wp_clear_scheduled_hook( 'pressark_caps_continue_user_migration' );
	}
}
