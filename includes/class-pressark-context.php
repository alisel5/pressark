<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressArk Compact Context Builder
 *
 * Builds a small, consistent context block (~150 tokens) for every request.
 * Does NOT vary by intent — the AI fetches what it needs via tool calls.
 *
 * This replaces the layered context engine. All dynamic data the AI needs
 * beyond this compact block is fetched on-demand via the discovery tools:
 *   get_site_overview, get_site_map, get_brand_profile, read_content, etc.
 */
class PressArk_Context {

	/**
	 * Detect the site's editing mode and tooling.
	 * Injected into every AI request context so tools can branch correctly.
	 *
	 * Returns a compact descriptor of the site's architecture:
	 * - editor: what content editor is in use
	 * - theme_type: fse | hybrid | classic
	 * - builder: which page builder (if any)
	 * - menus: which menu system is in use
	 * - templates: PHP templates or block templates
	 * - styles: where design settings live
	 * - widgets: legacy widget areas or block template parts
	 */
	public static function detect_site_mode(): array {
		$is_fse     = wp_is_block_theme();
		$has_json   = wp_theme_has_theme_json();
		$has_elmt   = defined( 'ELEMENTOR_VERSION' );
		$classic_ed = in_array(
			'classic-editor/classic-editor.php',
			get_option( 'active_plugins', array() ),
			true
		);

		// Check if any wp_navigation posts exist (FSE sites may use classic menus as fallback).
		$has_fse_nav = $is_fse && (int) wp_count_posts( 'wp_navigation' )->publish > 0;

		return array(
			'editor'      => $classic_ed ? 'classic' : 'gutenberg',
			'theme_type'  => $is_fse ? 'fse' : ( $has_json ? 'hybrid' : 'classic' ),
			'builder'     => $has_elmt ? 'elementor' : ( $is_fse ? 'site_editor' : 'none' ),
			'menus'       => $has_fse_nav ? 'wp_navigation' : 'wp_nav_menus',
			'templates'   => $is_fse ? 'block_templates' : 'php_templates',
			'styles'      => $has_json ? 'theme_json' : ( $has_elmt ? 'elementor_kit' : 'customizer' ),
			'widgets'     => $is_fse ? 'block_template_parts' : 'widget_areas',
			'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);
	}

	/**
	 * Plugin capability flags — always present (even when false).
	 *
	 * Extensible: add a new entry for each plugin that has its own tool group.
	 * Used by both the context builder (execution model) and the planner prompt.
	 *
	 * @return array<string, bool> Key = flag name, value = active.
	 */
	public static function get_plugin_flags(): array {
		return array(
			'is_woocommerce' => class_exists( 'WooCommerce' ),
			'is_elementor'   => defined( 'ELEMENTOR_VERSION' ),
		);
	}

	/**
	 * Build compact context string.
	 * Always the same fields. Always the same order (aids prompt caching).
	 * Target: ~200 tokens (was ~150, +50 for site_mode).
	 *
	 * @param string $screen   Current admin screen slug.
	 * @param int    $post_id  Current post ID (0 if not on post editor).
	 * @return string
	 */
	public function build( string $screen = '', int $post_id = 0 ): string {
		$lines = array();

		// Site identity.
		$lines[] = 'Site: ' . get_bloginfo( 'name' )
				 . ' | URL: ' . home_url()
				 . ' | WP ' . get_bloginfo( 'version' )
				 . ' | PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		// Theme.
		$theme   = wp_get_theme();
		$lines[] = 'Theme: ' . $theme->get( 'Name' );

		// Permalink structure.
		$permalink = get_option( 'permalink_structure', '' );
		$lines[] = 'Permalinks: ' . ( $permalink ?: 'plain (?p=ID)' );

		// Homepage mode — critical for "edit the homepage" requests.
		$front = get_option( 'show_on_front', 'posts' );
		if ( 'page' === $front ) {
			$front_id    = (int) get_option( 'page_on_front', 0 );
			$blog_id     = (int) get_option( 'page_for_posts', 0 );
			$front_title = $front_id ? get_the_title( $front_id ) : '(not set)';
			$lines[]     = 'Homepage: static page #' . $front_id . ' "' . $front_title . '"'
			             . ( $blog_id ? ' | Blog page: #' . $blog_id : '' );
		} else {
			$lines[] = 'Homepage: latest posts (blog listing)';
		}

		// Site mode — tells the AI what kind of site this is.
		$site_mode = self::detect_site_mode();
		$mode_parts = array();
		foreach ( $site_mode as $key => $val ) {
			$mode_parts[] = $key . ':' . $val;
		}
		$lines[] = 'Site mode: ' . implode( ', ', $mode_parts );

		// Site warnings & constants (only added when true — zero tokens otherwise).
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			$lines[] = 'WARNING: Site is in maintenance mode (.maintenance file exists)';
		}
		if ( function_exists( 'wp_recovery_mode' ) && wp_recovery_mode()->is_active() ) {
			$lines[] = 'WARNING: Recovery mode active — a fatal error was detected';
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$lines[] = 'Locked: DISALLOW_FILE_MODS enabled (no plugin/theme installs via admin)';
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$lines[] = 'Locked: DISALLOW_FILE_EDIT enabled (no code editing in admin)';
		}

		// Drop-ins: invisible to plugin list but critically important.
		$dropins_map = array(
			'advanced-cache.php' => 'page cache',
			'object-cache.php'   => 'object cache',
			'db.php'             => 'custom DB layer',
		);
		$active_dropins = array();
		foreach ( $dropins_map as $file => $desc ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $file ) ) {
				$active_dropins[] = $desc;
			}
		}
		if ( ! empty( $active_dropins ) ) {
			$lines[] = 'Drop-ins: ' . implode( ', ', $active_dropins );
		}

		// Plugin capabilities — always present so the AI knows what's available.
		// When is_woocommerce=true, "products" means WC products, not posts.
		// Extensible: add a new entry for each plugin that has its own tool group.
		$plugin_flags = self::get_plugin_flags();
		$flag_parts = array();
		foreach ( $plugin_flags as $key => $val ) {
			$flag_parts[] = $key . '=' . ( $val ? 'true' : 'false' );
		}
		$lines[] = 'Plugins: ' . implode( ', ', $flag_parts );

		// SEO plugin name (matters for meta key routing).
		$seo_slug = PressArk_SEO_Resolver::detect();
		if ( $seo_slug ) {
			$lines[] = 'SEO plugin: ' . PressArk_SEO_Resolver::label( $seo_slug );
		}

		// Current admin screen.
		if ( ! empty( $screen ) ) {
			$lines[] = 'Screen: ' . $screen;
		}

		// Current post context (if on post editor).
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$lines[] = 'Editing: [' . $post->post_type . ' #' . $post_id . '] '
						 . '"' . $post->post_title . '"'
						 . ' (' . $post->post_status . ')';
			}
		}

		// User.
		$user    = wp_get_current_user();
		$lines[] = 'User: ' . $user->display_name . ' (' . implode( ', ', $user->roles ) . ')';

		// Timestamp (UTC, date only — not time, so it doesn't break caching).
		$lines[] = 'Date: ' . gmdate( 'Y-m-d' );

		// WC event alerts (proactive surfacing).
		if ( class_exists( 'WooCommerce' ) ) {
			$wc_events = PressArk_WC_Events::get_unread_events( 5 );
			if ( ! empty( $wc_events ) ) {
				$alert_parts = array();
				foreach ( $wc_events as $evt ) {
					$type = $evt['type'];
					$data = $evt['data'];
					$alert_parts[] = match( $type ) {
						'low_stock'       => "Low stock: {$data['name']} ({$data['stock']} left)",
						'out_of_stock'    => "Out of stock: {$data['name']}",
						'order_failed'    => "Order #{$data['number']} failed",
						'order_cancelled' => "Order #{$data['number']} cancelled",
						default           => $type,
					};
				}
				$lines[] = 'WC Alerts: ' . implode( '; ', $alert_parts );
			}
		}

		return "## Current Context\n" . implode( "\n", $lines );
	}
}
