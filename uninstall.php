<?php
/**
 * PressArk Uninstall
 *
 * Cleans up all plugin data when uninstalled via WordPress admin.
 * Removes: custom tables, options, transients, user meta, and cron events.
 *
 * Multisite-aware: network-active legacy installs wipe every site, while
 * per-site multisite installs wipe every site where PressArk was activated
 * or still has a PressArk-owned data footprint.
 *
 * Note: As of 4.1.2, network activation is blocked at activation time and
 * legacy network-active installs are hard-disabled at runtime. The network
 * cleanup path below remains as a safety net for older installs.
 *
 * @since 2.0.1
 * @since 4.1.0 Multisite support, site transient cleanup, retention option cleanup.
 * @since 4.1.1 Network activation blocked; network cleanup retained as safety net.
 * @since 4.1.2 Per-site multisite uninstall now cleans every activated site.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-pressark-uninstall-helper.php';
require_once dirname( __FILE__ ) . '/includes/class-pressark-capabilities.php';

/**
 * Run cleanup for a single site.
 */
function pressark_uninstall_site(): void {
	global $wpdb;

	// ── Remove custom capabilities from all roles ─────────────────────
	PressArk_Capabilities::remove_all();

	// ── Drop all custom tables ────────────────────────────────────────
	$tables = array(
		'pressark_log',
		'pressark_chats',
		'pressark_content_index',
		'pressark_cost_ledger',
		'pressark_tasks',
		'pressark_runs',
		'pressark_automations',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ── Delete all pressark options ───────────────────────────────────
	// Covers: api keys, license, db_version, byok, model, provider, site profile,
	// usage counters, token tracking, retention settings, index state, etc.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'pressark_' ) . '%'
	) );

	// ── Delete all pressark transients ────────────────────────────────
	// Standard transients: _transient_{name} and _transient_timeout_{name}.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_pressark' ) . '%',
		$wpdb->esc_like( '_transient_timeout_pressark' ) . '%'
	) );

	// ── Delete all pressark user meta ─────────────────────────────────
	// Covers: pressark_onboarded, pressark_telegram_chat_id, pressark_group_usage.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'pressark_' ) . '%'
	) );

	// ── Clear all scheduled cron events ───────────────────────────────
	$cron_hooks = array(
		'pressark_generate_profile',
		'pressark_refresh_profile',
		'pressark_daily_index_sync',
		'pressark_initial_index',
		'pressark_reindex_post',
		'pressark_index_batch',
		'pressark_weekly_orphan_cleanup',
		'pressark_preview_cleanup',
		'pressark_process_task',
		'pressark_process_async_task',
		'pressark_cleanup_tasks',
		'pressark_retention_cleanup',
		'pressark_reconcile_reservations',
		'pressark_dispatch_automations',
		'pressark_automation_wake',
		'pressark_kick_as_runner',
		'pressark_caps_continue_user_migration',
	);

	foreach ( $cron_hooks as $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
		wp_clear_scheduled_hook( $hook );
	}
}

// ── Execute cleanup ──────────────────────────────────────────────────

if ( is_multisite() ) {
	global $wpdb;

	// Clean up network-level site transients.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		$wpdb->esc_like( '_site_transient_pressark' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_pressark' ) . '%'
	) );
}

$plugin_file   = dirname( __FILE__ ) . '/pressark.php';
$network_wide  = PressArk_Uninstall_Helper::is_network_uninstall( $plugin_file );
$site_ids      = PressArk_Uninstall_Helper::site_ids_for_uninstall( $network_wide, $plugin_file );

if ( empty( $site_ids ) ) {
	pressark_uninstall_site();
} else {
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		pressark_uninstall_site();
		restore_current_blog();
	}
}

if ( is_multisite() ) {
	PressArk_Uninstall_Helper::clear_multisite_state();
}
