<?php
/**
 * PressArk uninstall helper.
 *
 * Isolates the uninstall scope decision so it can be reused by uninstall.php
 * and exercised directly in tests without triggering destructive cleanup.
 *
 * @since 4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Uninstall_Helper {

	/**
	 * Network option storing every blog ID where PressArk has been activated.
	 *
	 * We keep this as a historical registry instead of "currently active"
	 * state so uninstall can still clean sites that were deactivated before
	 * the plugin zip was deleted.
	 */
	private const ACTIVATED_SITES_OPTION = 'pressark_multisite_activated_blog_ids';

	/**
	 * Network option tracking the PressArk version that last seeded the
	 * activation registry from currently active subsites.
	 */
	private const ACTIVATED_SITES_VERSION_OPTION = 'pressark_multisite_activated_blog_ids_version';

	/**
	 * Pure scope decision for uninstall.
	 *
	 * @param bool $is_multisite      Whether WordPress is running multisite.
	 * @param bool $is_network_active Whether the plugin is network-activated.
	 * @return bool
	 */
	public static function should_uninstall_network_wide( bool $is_multisite, bool $is_network_active ): bool {
		return $is_multisite && $is_network_active;
	}

	/**
	 * Pure decision for blocking activation-time network activation.
	 *
	 * @param bool $network_wide Whether activation was requested network-wide.
	 * @return bool
	 */
	public static function should_block_network_activation( bool $network_wide ): bool {
		return $network_wide;
	}

	/**
	 * Pure decision for disabling legacy runtime network-active installs.
	 *
	 * @param bool $is_multisite      Whether WordPress is running multisite.
	 * @param bool $is_network_active Whether the plugin is network-activated.
	 * @return bool
	 */
	public static function should_disable_runtime_for_network_active_install( bool $is_multisite, bool $is_network_active ): bool {
		return self::should_uninstall_network_wide( $is_multisite, $is_network_active );
	}

	/**
	 * Determine whether the plugin is network-active.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return bool
	 */
	public static function is_network_active( string $plugin_file ): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$plugin_basename = plugin_basename( $plugin_file );

		if ( function_exists( 'is_plugin_active_for_network' ) ) {
			return (bool) is_plugin_active_for_network( $plugin_basename );
		}

		$active_sitewide = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $active_sitewide[ $plugin_basename ] );
	}

	/**
	 * Determine whether uninstall should run network-wide.
	 *
	 * Network-activated legacy installs must still wipe the full network.
	 * Per-site multisite installs are handled separately via the activated-site
	 * registry and footprint scan in site_ids_for_uninstall().
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return bool
	 */
	public static function is_network_uninstall( string $plugin_file ): bool {
		return self::should_uninstall_network_wide(
			is_multisite(),
			self::is_network_active( $plugin_file )
		);
	}

	/**
	 * Record that PressArk has been activated on a site within a multisite network.
	 *
	 * @param int $site_id Blog ID to remember. Defaults to the current blog.
	 * @return void
	 */
	public static function remember_activated_site( int $site_id = 0 ): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( $site_id <= 0 ) {
			$site_id = get_current_blog_id();
		}

		$site_ids = self::tracked_site_ids();
		$site_ids[] = $site_id;
		self::store_tracked_site_ids( $site_ids );
	}

	/**
	 * Seed the activation registry from currently active subsites once per version.
	 *
	 * This backfills older multisite installs that predate the tracking option,
	 * so uninstall can still find individually activated subsites after an update.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return void
	 */
	public static function maybe_seed_activated_sites( string $plugin_file ): void {
		if ( ! is_multisite() || self::is_network_active( $plugin_file ) ) {
			return;
		}

		$current_version = defined( 'PRESSARK_VERSION' ) ? (string) PRESSARK_VERSION : '0';
		$seeded_version  = (string) get_site_option( self::ACTIVATED_SITES_VERSION_OPTION, '' );
		$tracked         = self::tracked_site_ids();

		if ( $seeded_version === $current_version && ! empty( $tracked ) ) {
			return;
		}

		$active_site_ids = self::detect_active_site_ids( $plugin_file );
		if ( ! empty( $active_site_ids ) ) {
			self::store_tracked_site_ids( array_merge( $tracked, $active_site_ids ) );
		}

		update_site_option( self::ACTIVATED_SITES_VERSION_OPTION, $current_version );
	}

	/**
	 * Return the tracked multisite blog IDs where PressArk has been activated.
	 *
	 * @return int[]
	 */
	public static function tracked_site_ids(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$site_ids = get_site_option( self::ACTIVATED_SITES_OPTION, array() );
		if ( ! is_array( $site_ids ) ) {
			return array();
		}

		return self::normalize_site_ids( $site_ids );
	}

	/**
	 * Clear all multisite registry state owned by PressArk.
	 *
	 * @return void
	 */
	public static function clear_multisite_state(): void {
		if ( ! is_multisite() ) {
			return;
		}

		delete_site_option( self::ACTIVATED_SITES_OPTION );
		delete_site_option( self::ACTIVATED_SITES_VERSION_OPTION );
	}

	/**
	 * Resolve which site IDs should be cleaned during uninstall.
	 *
	 * Network-active legacy installs clean the whole network. Per-site multisite
	 * installs clean every tracked site, every currently active site, and every
	 * site with a PressArk data footprint. That makes uninstall durable even for
	 * older installs created before the activation registry existed.
	 *
	 * @param bool   $network_wide Whether uninstall is network-scoped.
	 * @param string $plugin_file  Absolute path to the main plugin file.
	 * @return int[]
	 */
	public static function site_ids_for_uninstall( bool $network_wide, string $plugin_file = '' ): array {
		if ( ! is_multisite() ) {
			return array();
		}

		if ( ! $network_wide ) {

			$site_ids = self::tracked_site_ids();

			if ( '' !== $plugin_file ) {
				$site_ids = array_merge(
					$site_ids,
					self::detect_active_site_ids( $plugin_file ),
					self::detect_footprint_site_ids()
				);
			}

			$site_ids = self::normalize_site_ids( $site_ids );
			return ! empty( $site_ids ) ? $site_ids : array( get_current_blog_id() );
		}

		return self::all_site_ids();
	}

	/**
	 * Return every site ID in the current network.
	 *
	 * @return int[]
	 */
	private static function all_site_ids(): array {
		return self::normalize_site_ids( get_sites( array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		) ) );
	}

	/**
	 * Detect currently active per-site PressArk installations across the network.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return int[]
	 */
	private static function detect_active_site_ids( string $plugin_file ): array {
		if ( ! is_multisite() || '' === $plugin_file ) {
			return array();
		}

		$plugin_basename = plugin_basename( $plugin_file );
		$active_site_ids = array();

		foreach ( self::all_site_ids() as $site_id ) {
			$active_plugins = (array) get_blog_option( $site_id, 'active_plugins', array() );
			if ( in_array( $plugin_basename, $active_plugins, true ) ) {
				$active_site_ids[] = $site_id;
			}
		}

		return $active_site_ids;
	}

	/**
	 * Detect sites that already have PressArk-owned data on disk/DB.
	 *
	 * This is an uninstall-only fallback for legacy multisite installs that were
	 * activated before the tracked-site registry existed.
	 *
	 * @return int[]
	 */
	private static function detect_footprint_site_ids(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$site_ids = array();

		foreach ( self::all_site_ids() as $site_id ) {
			switch_to_blog( $site_id );

			if ( self::current_site_has_pressark_footprint() ) {
				$site_ids[] = $site_id;
			}

			restore_current_blog();
		}

		return $site_ids;
	}

	/**
	 * Determine whether the current blog still has PressArk-owned data.
	 *
	 * @return bool
	 */
	private static function current_site_has_pressark_footprint(): bool {
		global $wpdb;

		$log_table = $wpdb->prefix . 'pressark_log';
		if ( self::table_exists( $log_table ) ) {
			return true;
		}

		$option_name = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
			$wpdb->esc_like( 'pressark_' ) . '%'
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return ! empty( $option_name );
	}

	/**
	 * Store tracked site IDs in normalized form.
	 *
	 * @param array<int, mixed> $site_ids Site IDs to persist.
	 * @return void
	 */
	private static function store_tracked_site_ids( array $site_ids ): void {
		update_site_option( self::ACTIVATED_SITES_OPTION, self::normalize_site_ids( $site_ids ) );
	}

	/**
	 * Normalize site IDs into unique positive integers.
	 *
	 * @param array<int, mixed> $site_ids Raw site IDs.
	 * @return int[]
	 */
	private static function normalize_site_ids( array $site_ids ): array {
		$site_ids = array_map( 'intval', $site_ids );
		$site_ids = array_filter(
			$site_ids,
			static function ( int $site_id ): bool {
				return $site_id > 0;
			}
		);
		$site_ids = array_values( array_unique( $site_ids ) );
		sort( $site_ids, SORT_NUMERIC );

		return $site_ids;
	}

	/**
	 * Check whether a table exists for the current blog.
	 *
	 * @param string $table_name Fully qualified table name.
	 * @return bool
	 */
	private static function table_exists( string $table_name ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}
}
