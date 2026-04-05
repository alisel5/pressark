<?php
/**
 * PressArk Resource Registry — Typed, cached, URI-addressed resource reads.
 *
 * WordPress analog of MCP's resources/list + resources/read pattern.
 * Resources are READ-ONLY site data snapshots (design tokens, templates,
 * REST routes, schema, etc.) cached via transients with hook-based
 * invalidation.
 *
 * URI scheme: pressark://group/identifier
 *
 * @package PressArk
 * @since   5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Resource_Registry {

	/** @var array<string, array> uri → resource definition */
	private static array $resources = array();

	/** @var bool Whether boot() has been called. */
	private static bool $booted = false;

	/** @var bool Whether invalidation hooks have been wired. */
	private static bool $hooks_wired = false;

	/** Transient key prefix for cached resource data. */
	private const CACHE_PREFIX = 'pressark_res_';

	/** Maximum transient TTL (24 hours). */
	private const MAX_TTL = DAY_IN_SECONDS;

	// ── Registration ────────────────────────────────────────────────

	/**
	 * Register a resource definition.
	 *
	 * @param array $def {
	 *     @type string   $uri         Unique URI (pressark://group/id).
	 *     @type string   $name        Human-readable name.
	 *     @type string   $description Brief description (~15 words).
	 *     @type string   $group       Logical group (design, schema, site, etc.).
	 *     @type string   $mime_type   Content type (default: application/json).
	 *     @type callable $resolver    Callback that returns array data.
	 *     @type int      $ttl         Cache TTL in seconds (0 = no cache).
	 *     @type string[] $invalidate_on WordPress action hooks that clear this cache.
	 *     @type string   $requires    Plugin dependency (e.g., 'woocommerce', 'elementor').
	 * }
	 */
	public static function register( array $def ): void {
		$uri = $def['uri'] ?? '';
		if ( empty( $uri ) || ! str_starts_with( $uri, 'pressark://' ) ) {
			return;
		}

		self::$resources[ $uri ] = wp_parse_args( $def, array(
			'uri'           => $uri,
			'name'          => '',
			'description'   => '',
			'group'         => 'general',
			'mime_type'     => 'application/json',
			'resolver'      => null,
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array(),
			'requires'      => null,
			'trust_class'   => 'trusted_system',
			'provider'      => 'resource_registry',
			'provenance'    => array(),
		) );
	}

	/**
	 * List all registered resources, optionally filtered by group.
	 *
	 * @param string|null $group Filter by group name (null = all).
	 * @return array[] Array of { uri, name, description, group, mime_type }.
	 */
	public static function list( ?string $group = null ): array {
		self::ensure_booted();

		if ( 'tool-results' === $group ) {
			return PressArk_Tool_Result_Artifacts::list_resource_entries( get_current_user_id() );
		}

		$result = array();
		foreach ( self::$resources as $uri => $def ) {
			// Skip resources whose plugin dependency is not met.
			if ( ! self::dependency_met( $def ) ) {
				continue;
			}
			if ( null !== $group && $def['group'] !== $group ) {
				continue;
			}
			$result[] = array(
				'uri'         => $uri,
				'name'        => $def['name'],
				'description' => $def['description'],
				'group'       => $def['group'],
				'mime_type'   => $def['mime_type'],
				'trust_class' => $def['trust_class'],
				'provider'    => $def['provider'],
			);
		}

		if ( null === $group ) {
			$result = array_merge(
				$result,
				PressArk_Tool_Result_Artifacts::list_resource_entries( get_current_user_id(), 8 )
			);
		}

		return $result;
	}

	/**
	 * Read a resource by URI. Returns cached data when available.
	 *
	 * @param string $uri Resource URI.
	 * @return array { success: bool, data: mixed, cached: bool, uri: string }
	 */
	public static function read( string $uri ): array {
		self::ensure_booted();

		if ( PressArk_Tool_Result_Artifacts::is_tool_result_uri( $uri ) ) {
			return PressArk_Tool_Result_Artifacts::read_resource( $uri, get_current_user_id() );
		}

		if ( ! isset( self::$resources[ $uri ] ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Unknown resource URI: %s', $uri ),
				'uri'     => $uri,
			);
		}

		$def = self::$resources[ $uri ];

		// Check plugin dependency.
		if ( ! self::dependency_met( $def ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Resource requires plugin: %s', $def['requires'] ),
				'uri'     => $uri,
			);
		}

		// Check transient cache.
		$cache_key = self::cache_key( $uri );
		if ( $def['ttl'] > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				$cached_payload = self::normalize_cached_payload( $cached );
				$payload        = array(
					'success'   => true,
					'data'      => $cached_payload['data'],
					'cached'    => true,
					'uri'       => $uri,
					'stored_at' => $cached_payload['stored_at'],
				);
				if ( class_exists( 'PressArk_Read_Metadata' ) ) {
					$payload = PressArk_Read_Metadata::annotate_resource_result( $uri, $def, $payload );
				}
				return $payload;
			}
		}

		// Resolve fresh data.
		$resolver = $def['resolver'];
		if ( ! is_callable( $resolver ) ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Resource resolver not callable for: %s', $uri ),
				'uri'     => $uri,
			);
		}

		$data = call_user_func( $resolver );

		// Cache if TTL > 0.
		if ( $def['ttl'] > 0 && false !== $data ) {
			$ttl = min( $def['ttl'], self::MAX_TTL );
			set_transient( $cache_key, self::cache_payload( $data ), $ttl );
		}

		$payload = array(
			'success' => true,
			'data'    => $data,
			'cached'  => false,
			'uri'     => $uri,
			'stored_at' => gmdate( 'c' ),
		);
		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$payload = PressArk_Read_Metadata::annotate_resource_result( $uri, $def, $payload );
		}
		return $payload;
	}

	/**
	 * Invalidate cached data for a specific resource URI.
	 */
	public static function invalidate( string $uri ): void {
		delete_transient( self::cache_key( $uri ) );
	}

	/**
	 * Invalidate all cached resources in a group.
	 */
	public static function invalidate_group( string $group ): void {
		self::ensure_booted();

		foreach ( self::$resources as $uri => $def ) {
			if ( $def['group'] === $group ) {
				delete_transient( self::cache_key( $uri ) );
			}
		}
	}

	public static function apply_invalidation( array $descriptor ): void {
		self::ensure_booted();
		$uris   = array_map( 'sanitize_text_field', (array) ( $descriptor['resource_uris'] ?? array() ) );
		$groups = array_map( 'sanitize_key', (array) ( $descriptor['resource_groups'] ?? array() ) );
		$scope  = sanitize_key( (string) ( $descriptor['scope'] ?? '' ) );

		foreach ( array_filter( $uris ) as $uri ) {
			self::invalidate( $uri );
		}
		foreach ( array_filter( $groups ) as $group ) {
			self::invalidate_group( $group );
		}
		if ( 'site' === $scope ) {
			foreach ( array_keys( self::$resources ) as $uri ) {
				self::invalidate( $uri );
			}
		}
	}

	/**
	 * Get all registered group names.
	 *
	 * @return string[]
	 */
	public static function group_names(): array {
		self::ensure_booted();

		$groups = array();
		foreach ( self::$resources as $def ) {
			if ( self::dependency_met( $def ) ) {
				$groups[ $def['group'] ] = true;
			}
		}
		if ( PressArk_Tool_Result_Artifacts::has_resource_entries( get_current_user_id() ) ) {
			$groups['tool-results'] = true;
		}
		return array_keys( $groups );
	}

	/**
	 * Check if a resource URI exists.
	 */
	public static function exists( string $uri ): bool {
		self::ensure_booted();
		if ( PressArk_Tool_Result_Artifacts::is_tool_result_uri( $uri ) ) {
			return (bool) ( PressArk_Tool_Result_Artifacts::read_resource( $uri, get_current_user_id() )['success'] ?? false );
		}
		return isset( self::$resources[ $uri ] ) && self::dependency_met( self::$resources[ $uri ] );
	}

	/**
	 * Search resources by keyword (for discover_tools integration).
	 *
	 * @param string $query Natural-language query.
	 * @return array[] Matching resources with score.
	 */
	public static function search( string $query ): array {
		self::ensure_booted();

		$query_lower = strtolower( trim( $query ) );
		if ( '' === $query_lower ) {
			return array();
		}

		$words  = preg_split( '/\s+/', $query_lower );
		$scored = array();

		foreach ( self::$resources as $uri => $def ) {
			if ( ! self::dependency_met( $def ) ) {
				continue;
			}

			$name_lower = strtolower( $def['name'] );
			$desc_lower = strtolower( $def['description'] );
			$uri_lower  = strtolower( $uri );
			$score      = 0;

			// Exact substring in name or URI.
			if ( str_contains( $name_lower, $query_lower ) || str_contains( $uri_lower, $query_lower ) ) {
				$score += 80;
			}

			// Word-level matching.
			foreach ( $words as $word ) {
				if ( strlen( $word ) < 2 ) {
					continue;
				}
				if ( str_contains( $name_lower, $word ) || str_contains( $uri_lower, $word ) ) {
					$score += 15;
				}
				if ( str_contains( $desc_lower, $word ) ) {
					$score += 8;
				}
			}

			if ( $score > 0 ) {
				$scored[] = array(
					'uri'         => $uri,
					'name'        => $def['name'],
					'description' => $def['description'],
					'group'       => $def['group'],
					'type'        => 'resource',
					'score'       => $score,
				);
			}
		}

		// Sort by score descending.
		usort( $scored, static fn( $a, $b ) => $b['score'] - $a['score'] );

		return array_slice( $scored, 0, 10 );
	}

	// ── Boot ────────────────────────────────────────────────────────

	/**
	 * Register all built-in resources and wire invalidation hooks.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		self::register_builtin_resources();
		self::wire_invalidation_hooks();

		/**
		 * Fires after built-in resources are registered.
		 * Third-party code can add custom resources here.
		 *
		 * @since 5.1.0
		 */
		do_action( 'pressark_register_resources' );
	}

	/**
	 * Reset registry state (for testing).
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$resources   = array();
		self::$booted      = false;
		self::$hooks_wired = false;
	}

	// ── Built-in Resources ──────────────────────────────────────────

	private static function register_builtin_resources(): void {

		// ── Site ──

		self::register( array(
			'uri'           => 'pressark://site/overview',
			'name'          => 'Site Overview',
			'description'   => 'Site identity, versions, theme, content counts, active plugins, integrations',
			'group'         => 'site',
			'resolver'      => array( __CLASS__, 'resolve_site_overview' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array( 'switch_theme', 'activated_plugin', 'deactivated_plugin' ),
		) );

		self::register( array(
			'uri'           => 'pressark://site/harness-readiness',
			'name'          => 'Harness Readiness',
			'description'   => 'Canonical PressArk readiness across billing, providers, profiles, indexing, and background work',
			'group'         => 'site',
			'resolver'      => array( 'PressArk_Harness_Readiness', 'get_snapshot' ),
			'ttl'           => MINUTE_IN_SECONDS,
			'invalidate_on' => array(),
		) );

		// ── Design ──

		self::register( array(
			'uri'           => 'pressark://design/theme-json',
			'name'          => 'Theme Design Tokens',
			'description'   => 'Colors, typography, spacing, layout from theme.json / global styles',
			'group'         => 'design',
			'resolver'      => array( __CLASS__, 'resolve_design_tokens' ),
			'ttl'           => DAY_IN_SECONDS,
			'invalidate_on' => array( 'switch_theme', 'save_post_wp_global_styles' ),
		) );

		self::register( array(
			'uri'           => 'pressark://design/customizer',
			'name'          => 'Customizer Schema',
			'description'   => 'Customizer panels, sections, controls with current values',
			'group'         => 'design',
			'resolver'      => array( __CLASS__, 'resolve_customizer_schema' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array( 'customize_save_after' ),
		) );

		// ── Templates ──

		self::register( array(
			'uri'           => 'pressark://templates/list',
			'name'          => 'Block Templates',
			'description'   => 'FSE template hierarchy — all wp_template and wp_template_part entries',
			'group'         => 'templates',
			'resolver'      => array( __CLASS__, 'resolve_templates_list' ),
			'ttl'           => 6 * HOUR_IN_SECONDS,
			'invalidate_on' => array( 'save_post_wp_template', 'save_post_wp_template_part' ),
		) );

		self::register( array(
			'uri'           => 'pressark://blocks/patterns',
			'name'          => 'Block Patterns',
			'description'   => 'Registered block patterns with categories and metadata',
			'group'         => 'templates',
			'resolver'      => array( __CLASS__, 'resolve_block_patterns' ),
			'ttl'           => 12 * HOUR_IN_SECONDS,
			'invalidate_on' => array(),
		) );

		// ── Schema ──

		self::register( array(
			'uri'           => 'pressark://schema/post-types',
			'name'          => 'Post Type Schema',
			'description'   => 'All public post types with supports, meta keys, taxonomies',
			'group'         => 'schema',
			'resolver'      => array( __CLASS__, 'resolve_post_type_schema' ),
			'ttl'           => 6 * HOUR_IN_SECONDS,
			'invalidate_on' => array(),
		) );

		self::register( array(
			'uri'           => 'pressark://schema/taxonomies',
			'name'          => 'Taxonomy Schema',
			'description'   => 'All public taxonomies with term counts and associated post types',
			'group'         => 'schema',
			'resolver'      => array( __CLASS__, 'resolve_taxonomy_schema' ),
			'ttl'           => 6 * HOUR_IN_SECONDS,
			'invalidate_on' => array(),
		) );

		// ── REST ──

		self::register( array(
			'uri'           => 'pressark://rest/routes',
			'name'          => 'REST Route Inventory',
			'description'   => 'All REST API routes grouped by namespace with HTTP methods',
			'group'         => 'rest',
			'resolver'      => array( __CLASS__, 'resolve_rest_routes' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array(),
		) );

		self::register( array(
			'uri'           => 'pressark://plugins/namespaces',
			'name'          => 'Plugin Namespaces',
			'description'   => 'Active plugins with their REST API namespaces',
			'group'         => 'rest',
			'resolver'      => array( __CLASS__, 'resolve_plugin_namespaces' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array( 'activated_plugin', 'deactivated_plugin' ),
		) );

		// ── Conditional: WooCommerce ──

		self::register( array(
			'uri'           => 'pressark://woo/store-config',
			'name'          => 'WooCommerce Store Config',
			'description'   => 'Store currency, payment gateways, shipping zones, tax settings',
			'group'         => 'woocommerce',
			'requires'      => 'woocommerce',
			'resolver'      => array( __CLASS__, 'resolve_woo_store_config' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array( 'woocommerce_settings_saved' ),
		) );

		// ── Conditional: Elementor ──

		self::register( array(
			'uri'           => 'pressark://elementor/kit',
			'name'          => 'Elementor Kit Settings',
			'description'   => 'Global colors, fonts, breakpoints, typography from Elementor kit',
			'group'         => 'elementor',
			'requires'      => 'elementor',
			'resolver'      => array( __CLASS__, 'resolve_elementor_kit' ),
			'ttl'           => HOUR_IN_SECONDS,
			'invalidate_on' => array(),
		) );
	}

	// ── Resolvers ───────────────────────────────────────────────────

	/**
	 * Resolve site overview data.
	 */
	public static function resolve_site_overview(): array {
		$page_count    = wp_count_posts( 'page' );
		$post_count    = wp_count_posts( 'post' );
		$comment_count = wp_count_comments();
		$theme         = wp_get_theme();

		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_names   = array();
		foreach ( $active_plugins as $plugin_file ) {
			$data           = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$plugin_names[] = $data['Name'] ?? basename( $plugin_file, '.php' );
		}

		$public_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$cpt_list     = array();
		foreach ( $public_types as $pt ) {
			$count      = wp_count_posts( $pt->name );
			$cpt_list[] = array(
				'name'  => $pt->name,
				'label' => $pt->label,
				'count' => (int) ( $count->publish ?? 0 ),
			);
		}

		return array(
			'name'              => get_bloginfo( 'name' ),
			'tagline'           => get_bloginfo( 'description' ),
			'url'               => home_url(),
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'theme'             => $theme->get( 'Name' ),
			'theme_version'     => $theme->get( 'Version' ),
			'is_block_theme'    => wp_is_block_theme(),
			'has_theme_json'    => wp_theme_has_theme_json(),
			'pages'             => (int) ( $page_count->publish ?? 0 ),
			'posts'             => (int) ( $post_count->publish ?? 0 ),
			'comments'          => (int) ( $comment_count->total_comments ?? 0 ),
			'active_plugins'    => $plugin_names,
			'custom_post_types' => $cpt_list,
			'timezone'          => wp_timezone_string(),
			'permalink'         => get_option( 'permalink_structure', '' ),
			'environment'       => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);
	}

	/**
	 * Resolve theme design tokens from theme.json / global styles.
	 */
	public static function resolve_design_tokens(): array {
		if ( ! wp_theme_has_theme_json() ) {
			return array(
				'available' => false,
				'reason'    => 'Theme does not use theme.json',
			);
		}

		$settings = wp_get_global_settings();
		$styles   = wp_get_global_styles();

		return array(
			'available'  => true,
			'theme'      => wp_get_theme()->get( 'Name' ),
			'colors'     => array(
				'palette'   => $settings['color']['palette']['theme'] ?? array(),
				'gradients' => $settings['color']['gradients']['theme'] ?? array(),
				'custom'    => $settings['color']['custom'] ?? true,
			),
			'typography' => array(
				'font_families' => $settings['typography']['fontFamilies']['theme'] ?? array(),
				'font_sizes'    => $settings['typography']['fontSizes']['theme'] ?? array(),
			),
			'spacing'    => array(
				'units'    => $settings['spacing']['units'] ?? array(),
				'padding'  => $styles['spacing']['padding'] ?? array(),
				'margin'   => $styles['spacing']['margin'] ?? array(),
				'block_gap' => $styles['spacing']['blockGap'] ?? null,
			),
			'layout'     => array(
				'content_size' => $settings['layout']['contentSize'] ?? '',
				'wide_size'    => $settings['layout']['wideSize'] ?? '',
			),
		);
	}

	/**
	 * Resolve customizer schema (classic themes only).
	 */
	public static function resolve_customizer_schema(): array {
		if ( wp_is_block_theme() ) {
			return array(
				'available' => false,
				'reason'    => 'Block theme — use pressark://design/theme-json instead',
			);
		}

		// Use existing transient from Handler_System if available.
		$stylesheet = get_stylesheet();
		$cached     = get_transient( 'pressark_customizer_schema_' . $stylesheet );
		if ( false !== $cached ) {
			return $cached;
		}

		// Build schema via Customizer API (expensive — requires full manager init).
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$wp_customize = new WP_Customize_Manager();
		do_action( 'customize_register', $wp_customize );

		$schema = array();
		foreach ( $wp_customize->sections() as $section ) {
			$controls = array();
			foreach ( $wp_customize->controls() as $control ) {
				if ( $control->section !== $section->id ) {
					continue;
				}
				// Skip nav menus and widgets.
				if ( str_starts_with( $control->id, 'nav_menu' ) || str_starts_with( $control->id, 'widget_' ) ) {
					continue;
				}
				$controls[] = array(
					'id'      => $control->id,
					'type'    => $control->type,
					'label'   => $control->label,
					'value'   => get_theme_mod( $control->id, $control->setting->default ?? '' ),
					'choices' => $control->choices ?? array(),
				);
			}
			if ( ! empty( $controls ) ) {
				$schema[] = array(
					'section'  => $section->id,
					'title'    => $section->title,
					'controls' => $controls,
				);
			}
		}

		set_transient( 'pressark_customizer_schema_' . $stylesheet, $schema, HOUR_IN_SECONDS );

		return $schema;
	}

	/**
	 * Resolve block templates list.
	 */
	public static function resolve_templates_list(): array {
		if ( ! wp_is_block_theme() ) {
			return array(
				'available' => false,
				'reason'    => 'Not a block theme — no block templates available',
			);
		}

		$types  = array( 'wp_template', 'wp_template_part' );
		$result = array();

		foreach ( $types as $type ) {
			$templates = get_block_templates( array(), $type );
			foreach ( $templates as $tpl ) {
				$result[] = array(
					'id'          => $tpl->id,
					'slug'        => $tpl->slug,
					'type'        => $type,
					'title'       => $tpl->title,
					'description' => $tpl->description,
					'source'      => $tpl->source,
					'has_content' => ! empty( $tpl->content ),
					'area'        => $tpl->area ?? '',
				);
			}
		}

		return $result;
	}

	/**
	 * Resolve block patterns.
	 */
	public static function resolve_block_patterns(): array {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$patterns = $registry->get_all_registered();

		$result = array();
		foreach ( $patterns as $pattern ) {
			$result[] = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'] ?? '',
				'description' => $pattern['description'] ?? '',
				'categories'  => $pattern['categories'] ?? array(),
				'block_types' => $pattern['blockTypes'] ?? array(),
			);
		}

		return $result;
	}

	/**
	 * Resolve post type schema.
	 */
	public static function resolve_post_type_schema(): array {
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		$result       = array();

		foreach ( $public_types as $pt ) {
			$supports = get_all_post_type_supports( $pt->name );
			$taxos    = get_object_taxonomies( $pt->name );
			$count    = wp_count_posts( $pt->name );

			$result[] = array(
				'name'          => $pt->name,
				'label'         => $pt->label,
				'hierarchical'  => $pt->hierarchical,
				'has_archive'   => (bool) $pt->has_archive,
				'supports'      => array_keys( $supports ),
				'taxonomies'    => $taxos,
				'rest_base'     => $pt->rest_base ?: $pt->name,
				'count'         => (int) ( $count->publish ?? 0 ),
				'menu_icon'     => $pt->menu_icon ?? '',
			);
		}

		return $result;
	}

	/**
	 * Resolve taxonomy schema.
	 */
	public static function resolve_taxonomy_schema(): array {
		$public_taxos = get_taxonomies( array( 'public' => true ), 'objects' );
		$result       = array();

		foreach ( $public_taxos as $tax ) {
			$count    = (int) wp_count_terms( array( 'taxonomy' => $tax->name ) );
			$result[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
				'object_types' => $tax->object_type,
				'rest_base'    => $tax->rest_base ?: $tax->name,
				'count'        => $count,
			);
		}

		return $result;
	}

	/**
	 * Resolve REST route inventory.
	 */
	public static function resolve_rest_routes(): array {
		$server = rest_get_server();
		$routes = $server->get_routes();

		$namespaces = array();
		foreach ( $routes as $route => $handlers ) {
			// Extract namespace from route: /namespace/v1/resource → namespace/v1.
			if ( preg_match( '#^/([^/]+(?:/v\d+)?)#', $route, $m ) ) {
				$ns = $m[1];
			} else {
				$ns = 'root';
			}

			$methods = array();
			foreach ( $handlers as $handler ) {
				if ( isset( $handler['methods'] ) ) {
					$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
				}
			}

			if ( ! isset( $namespaces[ $ns ] ) ) {
				$namespaces[ $ns ] = array(
					'namespace'   => $ns,
					'route_count' => 0,
					'routes'      => array(),
				);
			}
			$namespaces[ $ns ]['route_count']++;
			// Cap routes per namespace to avoid huge payloads.
			if ( count( $namespaces[ $ns ]['routes'] ) < 30 ) {
				$namespaces[ $ns ]['routes'][] = array(
					'path'    => $route,
					'methods' => array_values( array_unique( $methods ) ),
				);
			}
		}

		return array_values( $namespaces );
	}

	/**
	 * Resolve active plugins with REST namespaces.
	 */
	public static function resolve_plugin_namespaces(): array {
		$active_plugins = get_option( 'active_plugins', array() );
		$server         = rest_get_server();
		$all_namespaces = $server->get_namespaces();

		// Known plugin → namespace hints.
		$ns_hints = array(
			'woocommerce'       => 'wc/v3',
			'jetpack'           => 'jetpack/v4',
			'akismet'           => 'akismet/v1',
			'contact-form-7'    => 'contact-form-7/v1',
			'yoast'             => 'yoast/v1',
			'elementor'         => 'elementor/v1',
			'wp-graphql'        => 'graphql/v1',
			'gravityforms'      => 'gf/v2',
			'wpforms'           => 'wpforms/v1',
		);

		$result = array();
		foreach ( $active_plugins as $plugin_file ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}

			// Match namespace by slug hint or prefix match.
			$matched_ns = array();
			foreach ( $ns_hints as $hint_slug => $ns ) {
				if ( str_contains( strtolower( $slug ), $hint_slug ) && in_array( $ns, $all_namespaces, true ) ) {
					$matched_ns[] = $ns;
				}
			}
			// Also try slug-based namespace matching.
			foreach ( $all_namespaces as $ns ) {
				if ( str_starts_with( $ns, $slug . '/' ) && ! in_array( $ns, $matched_ns, true ) ) {
					$matched_ns[] = $ns;
				}
			}

			$result[] = array(
				'slug'       => $slug,
				'name'       => $data['Name'] ?? $slug,
				'version'    => $data['Version'] ?? '',
				'namespaces' => $matched_ns,
			);
		}

		return $result;
	}

	/**
	 * Resolve WooCommerce store configuration.
	 */
	public static function resolve_woo_store_config(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'available' => false );
		}

		$gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gw ) {
				$gateways[] = array(
					'id'      => $gw->id,
					'title'   => $gw->get_title(),
					'enabled' => 'yes' === $gw->enabled,
				);
			}
		}

		return array(
			'currency'         => get_woocommerce_currency(),
			'currency_symbol'  => get_woocommerce_currency_symbol(),
			'weight_unit'      => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'   => get_option( 'woocommerce_dimension_unit' ),
			'calc_taxes'       => 'yes' === get_option( 'woocommerce_calc_taxes' ),
			'store_address'    => get_option( 'woocommerce_store_address' ),
			'store_city'       => get_option( 'woocommerce_store_city' ),
			'store_country'    => get_option( 'woocommerce_default_country' ),
			'payment_gateways' => $gateways,
			'product_count'    => (int) ( wp_count_posts( 'product' )->publish ?? 0 ),
		);
	}

	/**
	 * Resolve Elementor kit settings.
	 */
	public static function resolve_elementor_kit(): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array( 'available' => false );
		}

		$kit_id = get_option( 'elementor_active_kit' );
		if ( ! $kit_id ) {
			return array( 'available' => false, 'reason' => 'No active Elementor kit' );
		}

		$kit_meta = get_post_meta( $kit_id );
		$settings = array();

		// Extract key design settings from kit meta.
		$settings_json = $kit_meta['_elementor_page_settings'][0] ?? '';
		if ( is_string( $settings_json ) ) {
			$decoded = json_decode( $settings_json, true );
			if ( is_array( $decoded ) ) {
				$settings = $decoded;
			}
		}

		// Get Elementor-serialized settings via its internal API if available.
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit ) {
				$settings = array(
					'global_colors'     => $kit->get_settings( 'custom_colors' ) ?: array(),
					'global_fonts'      => $kit->get_settings( 'custom_typography' ) ?: array(),
					'system_colors'     => $kit->get_settings( 'system_colors' ) ?: array(),
					'system_typography' => $kit->get_settings( 'system_typography' ) ?: array(),
				);

				// Breakpoints.
				$breakpoints_manager = \Elementor\Plugin::$instance->breakpoints->get_breakpoints();
				$bp_data             = array();
				foreach ( $breakpoints_manager as $bp ) {
					$bp_data[] = array(
						'name'    => $bp->get_name(),
						'label'   => $bp->get_label(),
						'value'   => $bp->get_value(),
						'enabled' => $bp->is_enabled(),
					);
				}
				$settings['breakpoints'] = $bp_data;
			}
		}

		return array(
			'available' => true,
			'kit_id'    => $kit_id,
			'settings'  => $settings,
		);
	}

	// ── Internals ───────────────────────────────────────────────────

	/**
	 * Generate transient cache key from URI.
	 */
	private static function cache_key( string $uri ): string {
		// Transient keys max 172 chars. Use hash for safety.
		return self::CACHE_PREFIX . substr( md5( $uri ), 0, 16 );
	}

	private static function cache_payload( $data ): array {
		return array(
			'__pressark_cached_resource' => true,
			'data'                      => $data,
			'stored_at'                 => gmdate( 'c' ),
		);
	}

	private static function normalize_cached_payload( $cached ): array {
		if ( is_array( $cached ) && ! empty( $cached['__pressark_cached_resource'] ) ) {
			return array(
				'data'      => $cached['data'] ?? array(),
				'stored_at' => sanitize_text_field( (string) ( $cached['stored_at'] ?? '' ) ),
			);
		}

		return array(
			'data'      => $cached,
			'stored_at' => '',
		);
	}

	/**
	 * Check if a resource's plugin dependency is met.
	 */
	private static function dependency_met( array $def ): bool {
		$requires = $def['requires'] ?? null;
		if ( null === $requires ) {
			return true;
		}
		return match ( $requires ) {
			'woocommerce' => class_exists( 'WooCommerce' ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ),
			default       => true,
		};
	}

	/**
	 * Ensure boot() has been called.
	 */
	private static function ensure_booted(): void {
		if ( ! self::$booted ) {
			self::boot();
		}
	}

	/**
	 * Wire WordPress action hooks for cache invalidation.
	 */
	private static function wire_invalidation_hooks(): void {
		if ( self::$hooks_wired ) {
			return;
		}
		self::$hooks_wired = true;

		// Collect all unique hooks → URIs mapping.
		$hook_map = array();
		foreach ( self::$resources as $uri => $def ) {
			foreach ( $def['invalidate_on'] as $hook ) {
				$hook_map[ $hook ][] = $uri;
			}
		}

		// Register one callback per hook.
		foreach ( $hook_map as $hook => $uris ) {
			add_action( $hook, static function () use ( $uris ) {
				foreach ( $uris as $uri ) {
					self::invalidate( $uri );
				}
			}, 999 ); // Late priority — after the actual save completes.
		}
	}
}
