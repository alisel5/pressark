<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured tool definitions for the AI system prompt.
 * Each tool is a clear contract the AI uses to construct actions.
 */
class PressArk_Tools {

	private const DEFAULT_TOOL_NAMES = array(
		'read_content',
		'search_content',
		'edit_content',
		'update_meta',
		'create_post',
		'delete_content',
		'list_posts',
		'get_random_content',
		'analyze_seo',
		'fix_seo',
		'scan_security',
		'fix_security',
		'get_site_settings',
		'update_site_settings',
		'get_menus',
		'update_menu',
		'list_media',
		'get_media',
		'update_media',
		'delete_media',
		'regenerate_thumbnails',
		'list_comments',
		'moderate_comments',
		'reply_comment',
		'list_taxonomies',
		'manage_taxonomy',
		'assign_terms',
		'get_email_log',
		'list_users',
		'get_user',
		'update_user',
		'site_health',
		'list_scheduled_tasks',
		'manage_scheduled_task',
		'generate_content',
		'rewrite_content',
		'generate_bulk_meta',
		'bulk_delete',
		'empty_trash',
		'bulk_delete_media',
		'bulk_edit',
		'find_and_replace',
		'export_report',
		'view_site_profile',
		'refresh_site_profile',
		'list_logs',
		'read_log',
		'analyze_logs',
		'clear_log',
		'search_knowledge',
		'index_status',
		'rebuild_index',
		'get_product',
		'edit_product',
		'create_product',
		'bulk_edit_products',
		'analyze_store',
		'list_orders',
		'get_order',
		'update_order',
		'manage_coupon',
		'inventory_report',
		'sales_summary',
		'list_customers',
		'get_customer',
		'email_customer',
		'get_shipping_zones',
		'get_tax_settings',
		'get_payment_gateways',
		'get_wc_settings',
		'get_wc_emails',
		'get_wc_status',
		'list_reviews',
		'moderate_review',
		'reply_review',
		'bulk_reply_reviews',
		'list_variations',
		'edit_variation',
		'create_variation',
		'bulk_edit_variations',
		'list_product_attributes',
		'create_refund',
		'get_top_sellers',
		'create_order',
		'trigger_wc_email',
		'get_order_statuses',
		'get_products_on_sale',
		'customer_insights',
		'category_report',
		'revenue_report',
		'stock_report',
		'manage_webhooks',
		'get_wc_alerts',
		'store_health',
		'elementor_read_page',
		'elementor_find_widgets',
		'elementor_edit_widget',
		'elementor_add_widget',
		'elementor_add_container',
		'elementor_list_templates',
		'elementor_create_from_template',
		'elementor_get_styles',
		'elementor_find_replace',
		'elementor_audit_page',
		'elementor_site_pages',
		'elementor_global_styles',
		'elementor_create_page',
		'elementor_get_widget_schema',
		'elementor_get_breakpoints',
		'elementor_clone_page',
		'elementor_manage_conditions',
		'elementor_list_dynamic_tags',
		'elementor_set_dynamic_tag',
		'elementor_read_form',
		'elementor_edit_form_field',
		'elementor_set_visibility',
		'elementor_list_popups',
		'elementor_edit_popup_trigger',
		'site_brief',
		'page_audit',
		'inspect_hooks',
		'measure_page_speed',
		'check_crawlability',
		'check_email_delivery',
		'profile_queries',
		'get_revision_history',
		'discover_rest_routes',
		'call_rest_endpoint',
		'diagnose_cache',
		'analyze_comment_moderation',
		'get_site_overview',
		'get_site_map',
		'get_brand_profile',
		'get_available_tools',
		'site_note',
		'list_resources',
		'read_resource',
		'list_plugins',
		'list_themes',
		'get_theme_settings',
		'get_customizer_schema',
		'update_theme_setting',
		'switch_theme',
		'database_stats',
		'cleanup_database',
		'optimize_database',
		'read_blocks',
		'edit_block',
		'insert_block',
		'get_custom_fields',
		'update_custom_field',
		'list_forms',
		'get_templates',
		'edit_template',
		'get_design_system',
		'list_patterns',
		'insert_pattern',
		'network_overview',
	);

	private const REGISTERED_TOOL_NAMES = array(
		'read_content',
		'search_content',
		'edit_content',
		'update_meta',
		'create_post',
		'delete_content',
		'list_posts',
		'get_random_content',
		'analyze_seo',
		'fix_seo',
		'scan_security',
		'fix_security',
		'get_site_settings',
		'update_site_settings',
		'get_menus',
		'update_menu',
		'list_media',
		'get_media',
		'update_media',
		'delete_media',
		'regenerate_thumbnails',
		'list_comments',
		'moderate_comments',
		'reply_comment',
		'list_taxonomies',
		'manage_taxonomy',
		'assign_terms',
		'get_product',
		'edit_product',
		'create_product',
		'bulk_edit_products',
		'analyze_store',
		'list_orders',
		'get_order',
		'update_order',
		'manage_coupon',
		'inventory_report',
		'sales_summary',
		'get_email_log',
		'list_users',
		'get_user',
		'update_user',
		'site_health',
		'list_scheduled_tasks',
		'manage_scheduled_task',
		'list_customers',
		'get_customer',
		'email_customer',
		'get_shipping_zones',
		'get_tax_settings',
		'get_payment_gateways',
		'get_wc_settings',
		'get_wc_emails',
		'get_wc_status',
		'list_reviews',
		'moderate_review',
		'reply_review',
		'bulk_reply_reviews',
		'generate_content',
		'rewrite_content',
		'generate_bulk_meta',
		'bulk_delete',
		'empty_trash',
		'bulk_delete_media',
		'bulk_edit',
		'find_and_replace',
		'export_report',
		'view_site_profile',
		'refresh_site_profile',
		'list_logs',
		'read_log',
		'analyze_logs',
		'clear_log',
		'search_knowledge',
		'index_status',
		'rebuild_index',
		'elementor_read_page',
		'elementor_find_widgets',
		'elementor_edit_widget',
		'elementor_add_widget',
		'elementor_add_container',
		'elementor_list_templates',
		'elementor_create_from_template',
		'elementor_get_styles',
		'elementor_find_replace',
		'elementor_audit_page',
		'elementor_site_pages',
		'elementor_global_styles',
		'elementor_create_page',
		'elementor_get_widget_schema',
		'elementor_get_breakpoints',
		'elementor_clone_page',
		'elementor_manage_conditions',
		'elementor_list_dynamic_tags',
		'elementor_set_dynamic_tag',
		'elementor_read_form',
		'elementor_edit_form_field',
		'elementor_set_visibility',
		'elementor_list_popups',
		'elementor_edit_popup_trigger',
		'store_health',
		'site_brief',
		'page_audit',
		'get_site_overview',
		'get_site_map',
		'get_brand_profile',
		'get_available_tools',
		'site_note',
		'list_resources',
		'read_resource',
		'list_plugins',
		'list_themes',
		'get_theme_settings',
		'get_customizer_schema',
		'update_theme_setting',
		'switch_theme',
		'database_stats',
		'cleanup_database',
		'optimize_database',
		'list_variations',
		'edit_variation',
		'create_variation',
		'bulk_edit_variations',
		'list_product_attributes',
		'category_report',
		'create_refund',
		'get_top_sellers',
		'create_order',
		'trigger_wc_email',
		'get_order_statuses',
		'get_products_on_sale',
		'customer_insights',
		'revenue_report',
		'stock_report',
		'manage_webhooks',
		'get_wc_alerts',
		'inspect_hooks',
		'measure_page_speed',
		'check_crawlability',
		'check_email_delivery',
		'profile_queries',
		'get_revision_history',
		'discover_rest_routes',
		'call_rest_endpoint',
		'diagnose_cache',
		'analyze_comment_moderation',
		'read_blocks',
		'edit_block',
		'insert_block',
		'get_custom_fields',
		'update_custom_field',
		'list_forms',
		'get_templates',
		'edit_template',
		'get_design_system',
		'list_patterns',
		'insert_pattern',
		'network_overview',
		'list_automations',
		'create_automation',
		'update_automation',
		'toggle_automation',
		'run_automation_now',
		'delete_automation',
		'inspect_automation',
	);

	/**
	 * @var array<string, object>
	 */
	private static array $tool_instances = array();

	private static bool $tool_runtime_loaded = false;

	/**
	 * Get all tool definitions.
	 *
	 * @param bool $has_woo       Whether WooCommerce is active.
	 * @param bool $has_elementor Whether Elementor is active.
	 * @return array
	 */
	public static function get_all( bool $has_woo = false, bool $has_elementor = false ): array {
		return self::to_legacy_definitions( self::get_all_tool_objects( $has_woo, $has_elementor ) );
	}

	/**
	 * Get all default tool objects.
	 *
	 * @return array<int,object>
	 */
	public static function get_all_tool_objects( bool $has_woo = false, bool $has_elementor = false ): array {
		return self::get_tools_by_names( self::DEFAULT_TOOL_NAMES, $has_woo, $has_elementor );
	}

	/**
	 * Get every registered tool object, including deferred/on-demand tools.
	 *
	 * @return array<int,object>
	 */
	public static function get_registered_tool_objects( ?bool $has_woo = null, ?bool $has_elementor = null ): array {
		return self::get_tools_by_names( self::REGISTERED_TOOL_NAMES, $has_woo, $has_elementor );
	}

	/**
	 * Get all registered tool names, including on-demand groups.
	 *
	 * @return string[]
	 */
	public static function get_registered_tool_names(): array {
		return self::REGISTERED_TOOL_NAMES;
	}

	/**
	 * Resolve a single tool object by external tool name.
	 */
	public static function get_tool( string $name ) {
		self::ensure_tool_runtime_loaded();

		$name = sanitize_key( $name );
		if ( ! in_array( $name, self::REGISTERED_TOOL_NAMES, true ) ) {
			return null;
		}

		if ( isset( self::$tool_instances[ $name ] ) ) {
			return self::$tool_instances[ $name ];
		}

		$class_name = self::tool_name_to_class_name( $name );
		$file_path  = self::tool_file_path( $name );
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}

		if ( ! class_exists( $class_name ) ) {
			return null;
		}

		$instance = new $class_name();
		if ( ! $instance instanceof PressArk_Tool_Base ) {
			return null;
		}

		self::$tool_instances[ $name ] = $instance;
		return self::$tool_instances[ $name ];
	}

	/**
	 * Resolve an ordered list of tool objects by name.
	 *
	 * @param string[]  $names
	 * @param bool|null $has_woo
	 * @param bool|null $has_elementor
	 * @return array<int,object>
	 */
	public static function get_tools_by_names( array $names, ?bool $has_woo = null, ?bool $has_elementor = null ): array {
		$has_woo       = null === $has_woo ? class_exists( 'WooCommerce' ) : $has_woo;
		$has_elementor = null === $has_elementor ? class_exists( '\Elementor\Plugin' ) : $has_elementor;
		$tools         = array();

		foreach ( array_values( array_unique( array_map( 'sanitize_key', $names ) ) ) as $tool_name ) {
			if ( '' === $tool_name ) {
				continue;
			}

			if ( ! self::is_tool_available( $tool_name, $has_woo, $has_elementor ) ) {
				continue;
			}

			$tool = self::get_tool( $tool_name );
			if ( null !== $tool ) {
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Build the compact prompt descriptors sourced from tool objects.
	 */
	public static function get_prompt_snippets( array $tool_names = array(), ?bool $has_woo = null, ?bool $has_elementor = null ): string {
		$tools = empty( $tool_names )
			? self::get_registered_tool_objects( $has_woo, $has_elementor )
			: self::get_tools_by_names( $tool_names, $has_woo, $has_elementor );
		$snippets = array();

		foreach ( $tools as $tool ) {
			$snippet = is_object( $tool ) && method_exists( $tool, 'get_prompt_snippet' )
				? trim( (string) $tool->get_prompt_snippet() )
				: '';
			if ( '' !== $snippet ) {
				$snippets[] = $snippet;
			}
		}

		return implode( "\n", $snippets );
	}

	/**
	 * Create a tool execution context after ensuring the runtime is loaded.
	 */
	public static function create_tool_context(
		int $user_id = 0,
		string $tier = 'free',
		int $post_id = 0,
		string $screen = '',
		string $permission_context = '',
		$abort_signal = null,
		?PressArk_Action_Engine $action_engine = null,
		$progress_callback = null,
		array $meta = array()
	) {
		self::ensure_tool_runtime_loaded();

		return new PressArk_Tool_Context(
			$user_id,
			$tier,
			$post_id,
			$screen,
			$permission_context,
			$abort_signal,
			$action_engine,
			$progress_callback,
			$meta
		);
	}

	/**
	 * Convert tool objects to their legacy array shape.
	 *
	 * @param array<int,object> $tools
	 * @return array
	 */
	public static function to_legacy_definitions( array $tools ): array {
		$definitions = array();

		foreach ( $tools as $tool ) {
			if ( is_object( $tool ) && method_exists( $tool, 'to_legacy_definition' ) ) {
				$definitions[] = self::normalize_tool_definition( $tool->to_legacy_definition() );
			}
		}

		return $definitions;
	}

	private static function normalize_tool_definition( array $tool ): array {
		if ( 'get_menus' !== (string) ( $tool['name'] ?? '' ) ) {
			return $tool;
		}

		$tool['description'] = 'List classic or FSE menus and theme locations. Without menu_id, full auto-inlines up to 50 items for the only menu or location-assigned menus; use summary for counts only.';

		if ( ! empty( $tool['params'] ) && is_array( $tool['params'] ) ) {
			foreach ( $tool['params'] as $index => $param ) {
				if ( ! is_array( $param ) || 'mode' !== (string) ( $param['name'] ?? '' ) ) {
					continue;
				}

				$tool['params'][ $index ]['desc'] = 'summary|full - summary returns counts only. Without menu_id, full auto-inlines up to 50 items for the only menu or location-assigned menus. (default: full)';
			}
		}

		return $tool;
	}

	public static function describe_tool_definition( array $tool ): string {
		return self::get_tool_description( $tool );
	}

	public static function build_tool_parameters( array $tool ): array {
		return self::build_parameter_schema( $tool );
	}

	public static function build_tool_prompt_params( array $tool ): array {
		return self::get_tool_prompt_params( $tool );
	}

	public static function extract_prompt_weight( string $prompt_snippet ): int {
		if ( preg_match( '/\bweight=(-?\d+)\b/', $prompt_snippet, $matches ) ) {
			return (int) $matches[1];
		}

		return 0;
	}

	private static function ensure_tool_runtime_loaded(): void {
		if ( self::$tool_runtime_loaded ) {
			return;
		}

		require_once __DIR__ . '/interface-pressark-tool.php';
		require_once __DIR__ . '/class-pressark-tool-context.php';
		require_once __DIR__ . '/tools/class-pressark-tool-base.php';

		self::$tool_runtime_loaded = true;
	}

	private static function is_tool_available( string $tool_name, bool $has_woo, bool $has_elementor ): bool {
		if ( ! in_array( $tool_name, self::REGISTERED_TOOL_NAMES, true ) ) {
			return false;
		}

		$group = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::get_group( $tool_name )
			: '';

		if ( 'woocommerce' === $group && ! $has_woo ) {
			return false;
		}

		if ( 'elementor' === $group && ! $has_elementor ) {
			return false;
		}

		return true;
	}

	private static function tool_name_to_class_name( string $tool_name ): string {
		$parts = array_filter( explode( '_', sanitize_key( $tool_name ) ) );
		$parts = array_map( 'ucfirst', $parts );
		return 'PressArk_Tool_' . implode( '_', $parts );
	}

	private static function tool_file_path( string $tool_name ): string {
		$slug = str_replace( '_', '-', sanitize_key( $tool_name ) );
		return __DIR__ . '/tools/class-tool-' . $slug . '.php';
	}

	/**
	 * Get all tool definitions as OpenAI function-calling schemas.
	 * Used by the agent loop — all tools always available.
	 * Caching makes this cost-neutral vs intent-filtered sets.
	 * Sorted alphabetically by name for cache stability.
	 *
	 * @return array All tool definitions in OpenAI function-calling format.
	 */
	public function get_all_tools(): array {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$all           = self::to_legacy_definitions(
			self::get_registered_tool_objects( $has_woo, $has_elementor )
		);
		$schemas       = array();

		foreach ( $all as $tool ) {
			$schemas[] = self::tool_to_schema( $tool );
		}

		// Stable alphabetical sort by tool name — ensures cache hits.
		usort( $schemas, function ( $a, $b ) {
			return strcmp(
				$a['function']['name'] ?? '',
				$b['function']['name'] ?? ''
			);
		} );

		return $schemas;
	}

	/**
	 * Build the tools section of the system prompt.
	 * LEGACY: sends ALL tools.
	 *
	 * @param bool $has_woo       Whether WooCommerce is active.
	 * @param bool $has_elementor Whether Elementor is active.
	 * @return string
	 */
	public static function build_prompt_section( bool $has_woo = false, bool $has_elementor = false ): string {
		$tools = self::to_legacy_definitions(
			self::get_registered_tool_objects( $has_woo, $has_elementor )
		);
		$lines = array( 'AVAILABLE TOOLS:' );

		foreach ( $tools as $i => $tool ) {
			$num = $i + 1;
			$description = self::get_tool_description( $tool );
			$params      = self::get_tool_prompt_params( $tool );
			$tool['description'] = $description;
			$tool['params']      = $params;
			$lines[] = '';
			$lines[] = sprintf( '%d. %s — %s', $num, $tool['name'], $tool['description'] );

			if ( ! empty( $tool['params'] ) ) {
				$lines[] = '   Parameters:';
				foreach ( $tool['params'] as $param ) {
					$req = $param['required'] ? '(required)' : '(optional)';
					$lines[] = sprintf( '   - %s %s: %s', $param['name'], $req, $param['desc'] );
				}
			}

			if ( ! empty( $tool['example'] ) ) {
				// Render examples in PAL format (compact, ~40% fewer tokens than JSON).
				$example_actions = $tool['example']['actions'] ?? array( $tool['example'] );
				$pal_parts = array();
				foreach ( $example_actions as $ex_action ) {
					if ( isset( $ex_action['type'] ) ) {
						$pal_parts[] = PressArk_PAL_Parser::to_pal( $ex_action );
					}
				}
				if ( ! empty( $pal_parts ) ) {
					$lines[] = '   Example: ' . implode( ' ', $pal_parts );
				}
			}
		}

		return implode( "\n", $lines );
	}

	// ── Tool Groups: maps group names to tool method names ────────────

	/**
	 * Tool group definitions. Each group maps to an array of method names.
	 *
	 * @deprecated 3.4.1 Use PressArk_Operation_Registry::group_names(),
	 *             tool_names_for_group(), is_valid_group() instead.
	 *             Kept for third-party backward compatibility.
	 */
	public const TOOL_GROUPS = array(
		'discovery' => array(
			'get_site_overview', 'get_site_map', 'get_brand_profile', 'get_available_tools', 'site_note',
			'list_resources', 'read_resource',
		),
		'core' => array(
			'read_content', 'search_content', 'edit_content', 'update_meta',
			'create_post', 'delete_content', 'list_posts', 'search_knowledge',
		),
		'content' => array(
			'read_content', 'search_content', 'edit_content', 'update_meta',
			'create_post', 'delete_content', 'list_posts', 'get_revision_history', 'search_knowledge',
		),
		'seo' => array(
			'analyze_seo', 'fix_seo', 'check_crawlability',
		),
		'security' => array(
			'scan_security', 'fix_security',
		),
		'settings' => array(
			'get_site_settings', 'update_site_settings', 'check_email_delivery',
		),
		'menus' => array(
			'get_menus', 'update_menu',
		),
		'media' => array(
			'list_media', 'get_media', 'update_media', 'delete_media', 'regenerate_thumbnails',
		),
		'comments' => array(
			'list_comments', 'moderate_comments', 'reply_comment',
		),
		'taxonomy' => array(
			'list_taxonomies', 'manage_taxonomy', 'assign_terms',
		),
		'email' => array(
			'get_email_log',
		),
		'users' => array(
			'list_users', 'get_user', 'update_user',
		),
		'health' => array(
			'site_health', 'inspect_hooks', 'measure_page_speed',
			'store_health', 'site_brief', 'page_audit',
		),
		'scheduled' => array(
			'list_scheduled_tasks', 'manage_scheduled_task',
		),
		'automations' => array(
			'list_automations', 'create_automation', 'update_automation',
			'toggle_automation', 'run_automation_now', 'delete_automation',
			'inspect_automation',
		),
		'generation' => array(
			'generate_content', 'rewrite_content', 'generate_bulk_meta',
		),
		'bulk' => array(
			'bulk_edit', 'find_and_replace',
		),
		'export' => array(
			'export_report',
		),
		'profile' => array(
			'view_site_profile', 'refresh_site_profile',
		),
		'logs' => array(
			'list_logs', 'read_log', 'analyze_logs', 'clear_log',
		),
		'index' => array(
			'search_knowledge', 'index_status', 'rebuild_index',
		),
		'plugins' => array(
			'list_plugins',
		),
		'themes' => array(
			'list_themes', 'get_theme_settings', 'get_customizer_schema', 'update_theme_setting', 'switch_theme',
		),
		'database' => array(
			'database_stats', 'cleanup_database', 'optimize_database',
		),
		'woocommerce' => array(
			'get_product', 'edit_product', 'create_product', 'bulk_edit_products', 'analyze_store',
			'list_orders', 'get_order', 'update_order', 'manage_coupon',
			'inventory_report', 'sales_summary',
			'list_customers', 'get_customer', 'email_customer',
			'get_shipping_zones', 'get_tax_settings', 'get_payment_gateways',
			'get_wc_settings', 'get_wc_emails', 'get_wc_status',
			'list_reviews', 'moderate_review', 'reply_review', 'bulk_reply_reviews',
			'list_variations', 'edit_variation', 'create_variation', 'bulk_edit_variations',
			'list_product_attributes', 'create_refund',
			'get_top_sellers', 'create_order',
			'trigger_wc_email', 'get_order_statuses', 'get_products_on_sale',
			'customer_insights', 'category_report',
			'revenue_report', 'stock_report', 'manage_webhooks', 'get_wc_alerts',
			'store_health',
		),
		'elementor' => array(
			'elementor_read_page', 'elementor_find_widgets', 'elementor_edit_widget',
			'elementor_add_widget', 'elementor_add_container',
			'elementor_list_templates', 'elementor_create_from_template',
			'elementor_get_styles', 'elementor_find_replace',
			'elementor_audit_page', 'elementor_site_pages', 'elementor_global_styles',
			'elementor_create_page', 'elementor_get_widget_schema',
			'elementor_get_breakpoints', 'elementor_clone_page', 'elementor_manage_conditions',
			'elementor_list_dynamic_tags', 'elementor_set_dynamic_tag',
			'elementor_read_form', 'elementor_edit_form_field',
			'elementor_set_visibility', 'elementor_list_popups', 'elementor_edit_popup_trigger',
		),
		'blocks' => array(
			'read_blocks', 'edit_block', 'insert_block',
		),
		'custom_fields' => array(
			'get_custom_fields', 'update_custom_field',
		),
		'forms' => array(
			'list_forms',
		),
		'templates' => array(
			'get_templates', 'edit_template',
		),
		'design' => array(
			'get_design_system',
		),
		'patterns' => array(
			'list_patterns', 'insert_pattern',
		),
		'multisite' => array(
			'network_overview',
		),
	);

	/**
	 * Build a compact tools section for specific tool groups only.
	 * Uses a shortened format: no examples, tighter parameter format.
	 * ~40 tokens per tool vs ~80 tokens for the verbose format.
	 *
	 * @param string[] $groups       Tool group names from the intent classifier.
	 * @param bool     $has_woo      Whether WooCommerce is active.
	 * @param bool     $has_elementor Whether Elementor is active.
	 * @return string Compact tools section for the system prompt.
	 */
	public static function build_compact_section( array $groups, bool $has_woo = false, bool $has_elementor = false ): string {
		if ( empty( $groups ) ) {
			return '';
		}

		// Collect unique tool method names from requested groups.
		$tool_names = array();
		foreach ( $groups as $group ) {
			// Skip conditional groups if plugin not active.
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			if ( PressArk_Operation_Registry::is_valid_group( $group ) ) {
				$tool_names = array_merge( $tool_names, PressArk_Operation_Registry::tool_names_for_group( $group ) );
			}
		}

		$tool_names = array_unique( $tool_names );

		if ( empty( $tool_names ) ) {
			return '';
		}

		// Build all available tools and filter to requested ones.
		$all_tools = self::to_legacy_definitions(
			self::get_registered_tool_objects( $has_woo, $has_elementor )
		);
		$filtered  = array();
		foreach ( $all_tools as $tool ) {
			if ( in_array( $tool['name'], $tool_names, true ) ) {
				$filtered[] = $tool;
			}
		}

		if ( empty( $filtered ) ) {
			return '';
		}

		// Build compact format.
		$lines = array( 'AVAILABLE TOOLS:' );
		foreach ( $filtered as $tool ) {
			$tool['description'] = self::get_tool_description( $tool );
			$tool['params']      = self::get_tool_prompt_params( $tool );
			$params_str = '';
			if ( ! empty( $tool['params'] ) ) {
				$param_parts = array();
				foreach ( $tool['params'] as $param ) {
					$prefix = $param['required'] ? '*' : '';
					$param_parts[] = $prefix . $param['name'];
				}
				$params_str = '(' . implode( ', ', $param_parts ) . ')';
			}
			// Compact one-liner: tool_name(*required, optional) — Description
			$lines[] = sprintf( '- %s%s — %s', $tool['name'], $params_str, $tool['description'] );
		}

		// Add a note about unlisted tools.
		$total_available = count( $all_tools );
		$shown = count( $filtered );
		if ( $shown < $total_available ) {
			$lines[] = '';
			$lines[] = sprintf(
				'NOTE: %d of %d tools shown (most relevant to your request). Other tools for media, comments, menus, plugins, themes, database, users, email, health, logs, exports, scheduled tasks, and site profile are also available — just ask.',
				$shown,
				$total_available
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get available tool group names (for reference/debugging).
	 *
	 * @deprecated 3.4.1 Use PressArk_Operation_Registry::group_names() instead.
	 * @return string[]
	 */
	public static function get_group_names(): array {
		return PressArk_Operation_Registry::group_names();
	}

	/**
	 * Get tool definitions as OpenAI function-calling schemas, filtered by intent.
	 *
	 * @param array $intent_result Result from PressArk_Intent_Classifier::classify().
	 * @return array OpenAI-compatible tool schemas.
	 */
	public static function get_tools_for_intent( array $intent_result ): array {
		$groups       = $intent_result['tool_groups'] ?? array();
		$has_woo      = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );

		if ( empty( $groups ) ) {
			return array();
		}

		// Collect unique tool method names from requested groups.
		$tool_names = array();
		foreach ( $groups as $group ) {
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			if ( PressArk_Operation_Registry::is_valid_group( $group ) ) {
				$tool_names = array_merge( $tool_names, PressArk_Operation_Registry::tool_names_for_group( $group ) );
			}
		}
		$tool_names = array_unique( $tool_names );

		if ( empty( $tool_names ) ) {
			return array();
		}

		// Filter all tools to requested ones.
		$all_tools = self::to_legacy_definitions(
			self::get_registered_tool_objects( $has_woo, $has_elementor )
		);
		$schemas   = array();

		foreach ( $all_tools as $tool ) {
			if ( ! in_array( $tool['name'], $tool_names, true ) ) {
				continue;
			}
			$schemas[] = self::tool_to_schema( $tool );
		}

		return $schemas;
	}

	/**
	 * Convert a PressArk tool definition to an OpenAI function-calling schema.
	 */
	public static function tool_to_schema( array $tool ): array {
		$name = (string) ( $tool['name'] ?? '' );
		if ( '' !== $name && class_exists( 'PressArk_Operation_Registry' ) ) {
			$schema = PressArk_Operation_Registry::build_authoritative_provider_schema( $name );
			if ( is_array( $schema ) ) {
				if ( '' === trim( (string) ( $schema['function']['description'] ?? '' ) ) ) {
					$schema['function']['description'] = self::get_tool_description( $tool );
				}

				return $schema;
			}
		}

		$parameters = self::build_parameter_schema( $tool );

		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $tool['name'],
				'description' => self::get_tool_description( $tool ),
				'parameters'  => $parameters,
			),
		);
	}

	/**
	 * Get the authoritative prompt description for a tool.
	 *
	 * @since 5.5.0
	 */
	private static function get_tool_description( array $tool ): string {
		$tool = self::normalize_tool_definition( $tool );
		$name        = (string) ( $tool['name'] ?? '' );
		if ( 'get_menus' === $name ) {
			return trim( (string) ( $tool['description'] ?? '' ) );
		}

		$metadata    = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::get_authoritative_discovery_metadata( $name )
			: null;
		$description = is_array( $metadata )
			? trim( (string) ( $metadata['description'] ?? '' ) )
			: '';

		if ( '' === $description ) {
			$description = trim( (string) ( $tool['description'] ?? '' ) );
		}

		$guidance = array();
		$contract = self::get_tool_parameter_contract( $tool );
		if ( is_array( $contract ) ) {
			$guidance = array_merge( $guidance, self::summarize_contract_rules( $contract ) );
		}
		if ( is_array( $metadata ) ) {
			$guidance = array_merge( $guidance, (array) ( $metadata['model_guidance'] ?? array() ) );
		} elseif ( class_exists( 'PressArk_Operation_Registry' ) ) {
			$guidance = array_merge( $guidance, PressArk_Operation_Registry::get_model_guidance( $name ) );
		}

		$guidance = array_values( array_unique( array_filter( array_map(
			array( self::class, 'normalize_tool_guidance' ),
			$guidance
		) ) ) );

		if ( empty( $guidance ) ) {
			return $description;
		}

		return trim( $description . ' Guidance: ' . implode( ' ', array_slice( $guidance, 0, 3 ) ) );
	}

	/**
	 * Get prompt-facing parameters for a tool.
	 *
	 * @since 5.5.0
	 * @return array<int, array{name: string, required: bool, desc: string}>
	 */
	private static function get_tool_prompt_params( array $tool ): array {
		$tool = self::normalize_tool_definition( $tool );
		$contract = self::get_tool_parameter_contract( $tool );
		if ( is_array( $contract ) && is_array( $contract['properties'] ?? null ) ) {
			$required = array_values( array_filter( array_map(
				'strval',
				(array) ( $contract['required'] ?? array() )
			) ) );
			$params = array();

			foreach ( $contract['properties'] as $name => $schema ) {
				$name = (string) $name;
				if ( '' === $name ) {
					continue;
				}

				$params[] = array(
					'name'     => $name,
					'required' => in_array( $name, $required, true ),
					'desc'     => self::describe_contract_param( $name, is_array( $schema ) ? $schema : array() ),
				);
			}

			return $params;
		}

		$params = array();
		foreach ( (array) ( $tool['params'] ?? array() ) as $param ) {
			if ( ! is_array( $param ) || empty( $param['name'] ) ) {
				continue;
			}

			$desc = (string) ( $param['desc'] ?? $param['description'] ?? $param['name'] );
			$params[] = array(
				'name'     => (string) $param['name'],
				'required' => ! empty( $param['required'] ),
				'desc'     => $desc,
			);
		}

		return $params;
	}

	/**
	 * Build the provider-facing parameter schema for a tool.
	 *
	 * @since 5.5.0
	 */
	private static function build_parameter_schema( array $tool ): array {
		$tool = self::normalize_tool_definition( $tool );
		$name = (string) ( $tool['name'] ?? '' );
		if ( '' !== $name && class_exists( 'PressArk_Operation_Registry' ) ) {
			$data = PressArk_Operation_Registry::get_authoritative_provider_schema_data( $name );
			if ( is_array( $data ) && is_array( $data['parameters'] ?? null ) ) {
				return $data['parameters'];
			}
		}

		$contract = self::get_tool_parameter_contract( $tool );
		if ( is_array( $contract ) ) {
			return self::compile_contract_schema( $contract );
		}

		return self::compile_legacy_parameter_schema( (array) ( $tool['params'] ?? array() ) );
	}

	/**
	 * Resolve the authoritative operation parameter contract for a tool.
	 *
	 * @since 5.5.0
	 */
	private static function get_tool_parameter_contract( array $tool ): ?array {
		if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return null;
		}

		$name = (string) ( $tool['name'] ?? '' );
		if ( '' === $name ) {
			return null;
		}

		$contract = PressArk_Operation_Registry::get_parameter_contract( $name );
		return is_array( $contract ) ? $contract : null;
	}

	/**
	 * Build a contract coverage inventory for the current tool set.
	 *
	 * This keeps authoritative contracts distinct from legacy flat schemas,
	 * and further separates legacy typed params from params that still rely on
	 * inferred JSON Schema types.
	 *
	 * @since 5.5.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function contract_inventory( bool $has_woo = false, bool $has_elementor = false ): array {
		$rows = array();

		foreach ( self::get_all( $has_woo, $has_elementor ) as $tool ) {
			$name   = (string) ( $tool['name'] ?? '' );
			$source = self::parameter_schema_source( $tool );
			$op     = class_exists( 'PressArk_Operation_Registry' )
				? PressArk_Operation_Registry::resolve( $name )
				: null;

			$rows[] = array(
				'name'                    => $name,
				'group'                   => $op ? $op->group : '',
				'capability'              => $op ? $op->capability : '',
				'parameter_source'        => $source,
				'authoritative_params'    => 'authoritative' === $source,
				'legacy_inferred_params'  => 'legacy_inferred' === $source,
				'verification_policy'     => $op ? $op->has_verification_policy() : false,
				'automated_verification'  => $op ? $op->has_verification() : false,
				'read_invalidation'       => $op ? $op->has_read_invalidation_policy() : false,
				'model_guidance_count'    => $op ? count( $op->get_model_guidance() ) : 0,
			);
		}

		return $rows;
	}

	/**
	 * Describe whether a tool uses authoritative or legacy parameter schemas.
	 *
	 * @since 5.5.0
	 */
	private static function parameter_schema_source( array $tool ): string {
		if ( is_array( self::get_tool_parameter_contract( $tool ) ) ) {
			return 'authoritative';
		}

		$params = (array) ( $tool['params'] ?? array() );
		if ( empty( $params ) ) {
			return 'legacy_empty';
		}

		foreach ( $params as $param ) {
			if ( ! is_array( $param ) ) {
				return 'legacy_inferred';
			}

			if ( self::legacy_fragment_uses_inference( $param, (string) ( $param['name'] ?? '' ) ) ) {
				return 'legacy_inferred';
			}
		}

		return 'legacy_typed';
	}

	/**
	 * Whether a legacy param fragment still depends on inferred typing.
	 *
	 * @since 5.5.0
	 */
	private static function legacy_fragment_uses_inference( array $fragment, string $name = '' ): bool {
		if ( ! array_key_exists( 'type', $fragment ) ) {
			return true;
		}

		if ( is_array( $fragment['items'] ?? null ) && self::legacy_fragment_uses_inference( $fragment['items'], $name . '_item' ) ) {
			return true;
		}

		if ( is_array( $fragment['properties'] ?? null ) ) {
			foreach ( $fragment['properties'] as $property_name => $property_schema ) {
				if ( self::legacy_fragment_uses_inference(
					is_array( $property_schema ) ? $property_schema : array(),
					(string) $property_name
				) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Compile an authoritative parameter contract into provider JSON Schema.
	 *
	 * @since 5.5.0
	 */
	private static function compile_contract_schema( array $contract ): array {
		if ( class_exists( 'PressArk_Operation' ) ) {
			return PressArk_Operation::compile_provider_parameter_contract( $contract );
		}

		$schema = self::compile_contract_node( $contract, true );
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}
		if ( ! isset( $schema['required'] ) ) {
			$schema['required'] = array();
		}

		foreach ( array( 'oneOf', 'allOf', 'anyOf' ) as $keyword ) {
			unset( $schema[ $keyword ] );
		}

		return $schema;
	}

	/**
	 * Compile one contract node into JSON Schema.
	 *
	 * @since 5.5.0
	 */
	private static function compile_contract_node( array $node, bool $is_root = false ): array {
		$schema = array();
		$type   = $node['type'] ?? null;

		if ( null === $type && ( $is_root || isset( $node['properties'] ) ) ) {
			$type = 'object';
		}
		if ( null === $type ) {
			$type = 'string';
		}

		$schema['type'] = $type;

		foreach ( array( 'description', 'enum', 'default', 'format', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties', 'pattern' ) as $key ) {
			if ( array_key_exists( $key, $node ) ) {
				$schema[ $key ] = $node[ $key ];
			}
		}

		if ( is_array( $node['properties'] ?? null ) ) {
			$properties = array();
			foreach ( $node['properties'] as $property_name => $property_schema ) {
				$property_name = (string) $property_name;
				if ( '' === $property_name ) {
					continue;
				}

				$properties[ $property_name ] = self::compile_contract_node(
					is_array( $property_schema ) ? $property_schema : array(),
					false
				);
			}
			$schema['properties'] = empty( $properties ) ? new \stdClass() : $properties;
		}

		if ( is_array( $node['items'] ?? null ) ) {
			$schema['items'] = self::compile_contract_node( $node['items'], false );
		}

		if ( isset( $node['required'] ) && is_array( $node['required'] ) ) {
			$schema['required'] = array_values( array_filter( array_map( 'strval', $node['required'] ) ) );
		}

		if ( array_key_exists( 'additionalProperties', $node ) ) {
			$schema['additionalProperties'] = $node['additionalProperties'];
		} elseif ( ! empty( $node['strict'] ) && self::schema_allows_type( $type, 'object' ) ) {
			$schema['additionalProperties'] = false;
		}

		foreach ( (array) ( $node['one_of'] ?? array() ) as $group ) {
			$compiled = self::compile_contract_group( is_array( $group ) ? $group : array() );
			if ( empty( $compiled['keyword'] ) || empty( $compiled['clauses'] ) ) {
				continue;
			}

			foreach ( $compiled['clauses'] as $clause ) {
				self::append_schema_clause( $schema, $compiled['keyword'], $clause );
			}
		}

		foreach ( (array) ( $node['dependencies'] ?? array() ) as $rule ) {
			$clause = self::compile_contract_dependency( is_array( $rule ) ? $rule : array() );
			if ( null === $clause ) {
				continue;
			}

			self::append_schema_clause( $schema, 'allOf', $clause );
		}

		return $schema;
	}

	/**
	 * Compile top-level one-of style groups into JSON Schema clauses.
	 *
	 * @since 5.5.0
	 * @return array{keyword?: string, clauses?: array}
	 */
	private static function compile_contract_group( array $group ): array {
		$fields = array_values( array_filter( array_map( 'strval', (array) ( $group['fields'] ?? array() ) ) ) );
		if ( empty( $fields ) || ! self::provider_paths_are_top_level( $fields ) ) {
			return array();
		}

		$mode = sanitize_key( (string) ( $group['mode'] ?? 'exactly_one' ) );

		if ( 'at_least_one' === $mode ) {
			return array(
				'keyword' => 'anyOf',
				'clauses' => array_map(
					static fn( string $field ): array => array( 'required' => array( $field ) ),
					$fields
				),
			);
		}

		if ( in_array( $mode, array( 'at_most_one', 'mutually_exclusive' ), true ) ) {
			$clauses = array();
			$field_count = count( $fields );
			for ( $i = 0; $i < $field_count; $i++ ) {
				for ( $j = $i + 1; $j < $field_count; $j++ ) {
					$clauses[] = array(
						'not' => array(
							'allOf' => array(
								array( 'required' => array( $fields[ $i ] ) ),
								array( 'required' => array( $fields[ $j ] ) ),
							),
						),
					);
				}
			}

			return array(
				'keyword' => 'allOf',
				'clauses' => $clauses,
			);
		}

		$alternatives = array();
		foreach ( $fields as $field ) {
			$others = array_values( array_diff( $fields, array( $field ) ) );
			$branch = array(
				'required' => array( $field ),
			);
			if ( ! empty( $others ) ) {
				$branch['not'] = array(
					'anyOf' => array_map(
						static fn( string $other ): array => array( 'required' => array( $other ) ),
						$others
					),
				);
			}
			$alternatives[] = $branch;
		}

		return array(
			'keyword' => 'oneOf',
			'clauses' => $alternatives,
		);
	}

	/**
	 * Compile dependency-style rules into JSON Schema conditionals.
	 *
	 * Dot-path rules still validate at runtime, but provider schemas only
	 * receive direct field conditionals they can express structurally.
	 *
	 * @since 5.5.0
	 */
	private static function compile_contract_dependency( array $rule ): ?array {
		$field = (string) ( $rule['field'] ?? '' );
		if ( '' === $field || ! self::provider_paths_are_top_level( array( $field ) ) ) {
			return null;
		}

		$condition = array(
			'required' => array( $field ),
		);
		$values = array_values( (array) ( $rule['values'] ?? array() ) );
		if ( ! empty( $values ) ) {
			$condition['properties'] = array(
				$field => array( 'enum' => $values ),
			);
		}

		$then = array();
		$requires = array_values( array_filter( array_map( 'strval', (array) ( $rule['requires'] ?? array() ) ) ) );
		$requires = array_values( array_filter(
			$requires,
			static fn( string $path ): bool => false === str_contains( $path, '.' )
		) );
		if ( ! empty( $requires ) ) {
			$then['required'] = $requires;
		}

		foreach ( (array) ( $rule['field_values'] ?? array() ) as $other_field => $allowed_values ) {
			$other_field = (string) $other_field;
			if ( '' === $other_field || ! self::provider_paths_are_top_level( array( $other_field ) ) ) {
				continue;
			}

			$then['required'][] = $other_field;
			$then['properties'][ $other_field ] = array(
				'enum' => array_values( (array) $allowed_values ),
			);
		}

		if ( empty( $then ) ) {
			return null;
		}

		if ( isset( $then['required'] ) ) {
			$then['required'] = array_values( array_unique( $then['required'] ) );
		}

		return array(
			'if'   => $condition,
			'then' => $then,
		);
	}

	/**
	 * Append a JSON Schema composite clause.
	 *
	 * @since 5.5.0
	 */
	private static function append_schema_clause( array &$schema, string $keyword, array $clause ): void {
		if ( empty( $clause ) ) {
			return;
		}
		if ( ! isset( $schema[ $keyword ] ) || ! is_array( $schema[ $keyword ] ) ) {
			$schema[ $keyword ] = array();
		}

		$schema[ $keyword ][] = $clause;
	}

	/**
	 * Compile legacy flat params into JSON Schema.
	 *
	 * This is now the compatibility fallback only.
	 *
	 * @since 5.5.0
	 */
	private static function compile_legacy_parameter_schema( array $params ): array {
		$properties = array();
		$required   = array();

		foreach ( $params as $param ) {
			if ( ! is_array( $param ) ) {
				continue;
			}

			$name = (string) ( $param['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$properties[ $name ] = self::compile_legacy_schema_fragment( $param, $name );
			if ( ! empty( $param['required'] ) ) {
				$required[] = $name;
			}
		}

		return array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? new \stdClass() : $properties,
			'required'   => $required,
		);
	}

	/**
	 * Compile one legacy schema fragment.
	 *
	 * @since 5.5.0
	 */
	private static function compile_legacy_schema_fragment( array $fragment, string $name = '' ): array {
		$description = (string) ( $fragment['desc'] ?? $fragment['description'] ?? $name );
		$type        = $fragment['type'] ?? self::infer_param_type( $name, $description );
		$schema      = array(
			'type'        => $type,
			'description' => $description,
		);

		foreach ( array( 'enum', 'default', 'format', 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties', 'pattern', 'additionalProperties' ) as $key ) {
			if ( array_key_exists( $key, $fragment ) ) {
				$schema[ $key ] = $fragment[ $key ];
			}
		}

		if ( is_array( $fragment['items'] ?? null ) ) {
			$schema['items'] = self::compile_legacy_schema_fragment( $fragment['items'], $name . '_item' );
		}

		if ( is_array( $fragment['properties'] ?? null ) ) {
			$properties = array();
			foreach ( $fragment['properties'] as $property_name => $property_schema ) {
				$property_name = (string) $property_name;
				if ( '' === $property_name ) {
					continue;
				}

				$properties[ $property_name ] = self::compile_legacy_schema_fragment(
					is_array( $property_schema ) ? $property_schema : array(),
					$property_name
				);
			}

			$schema['properties'] = empty( $properties ) ? new \stdClass() : $properties;
		}

		if ( isset( $fragment['required'] ) && is_array( $fragment['required'] ) ) {
			$schema['required'] = array_values( array_filter( array_map( 'strval', $fragment['required'] ) ) );
		}

		return $schema;
	}

	/**
	 * Summarize contract rules as short model-facing sentences.
	 *
	 * @since 5.5.0
	 * @return string[]
	 */
	private static function summarize_contract_rules( array $contract ): array {
		$lines = array();

		foreach ( (array) ( $contract['one_of'] ?? array() ) as $group ) {
			$message = sanitize_text_field( (string) ( $group['message'] ?? '' ) );
			if ( '' !== $message ) {
				$lines[] = $message;
			}
		}

		foreach ( (array) ( $contract['dependencies'] ?? array() ) as $rule ) {
			$message = sanitize_text_field( (string) ( $rule['message'] ?? '' ) );
			if ( '' !== $message ) {
				$lines[] = $message;
			}
		}

		return $lines;
	}

	/**
	 * Normalize one tool guidance line into a sentence.
	 *
	 * @since 5.5.0
	 */
	private static function normalize_tool_guidance( string $line ): string {
		$line = sanitize_text_field( $line );
		if ( '' === $line ) {
			return '';
		}

		if ( ! preg_match( '/[.!?]$/', $line ) ) {
			$line .= '.';
		}

		return $line;
	}

	/**
	 * Build a prompt-friendly description for one contract parameter.
	 *
	 * @since 5.5.0
	 */
	private static function describe_contract_param( string $name, array $schema ): string {
		$description = sanitize_text_field( (string) ( $schema['description'] ?? $name ) );
		$details     = array();

		if ( ! empty( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			$details[] = implode( '|', array_map( 'strval', $schema['enum'] ) );
		}
		if ( array_key_exists( 'default', $schema ) ) {
			$details[] = 'default: ' . self::format_schema_value( $schema['default'] );
		}
		if ( is_array( $schema['properties'] ?? null ) && ! empty( $schema['properties'] ) ) {
			$details[] = 'keys: ' . implode( ', ', array_keys( $schema['properties'] ) );
		}
		if ( is_array( $schema['items'] ?? null ) ) {
			$item_summary = self::summarize_schema_items( $schema['items'] );
			if ( '' !== $item_summary ) {
				$details[] = 'items: ' . $item_summary;
			}
		}

		return empty( $details )
			? $description
			: $description . ' (' . implode( '; ', $details ) . ')';
	}

	/**
	 * Summarize an array item schema for prompt display.
	 *
	 * @since 5.5.0
	 */
	private static function summarize_schema_items( array $schema ): string {
		if ( ! empty( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			return implode( '|', array_map( 'strval', $schema['enum'] ) );
		}

		if ( is_array( $schema['properties'] ?? null ) && ! empty( $schema['properties'] ) ) {
			return 'objects with keys: ' . implode( ', ', array_keys( $schema['properties'] ) );
		}

		$type = $schema['type'] ?? '';
		if ( is_array( $type ) ) {
			return implode( '|', array_map( 'strval', $type ) );
		}

		return (string) $type;
	}

	/**
	 * Render a schema value for prompt descriptions.
	 *
	 * @since 5.5.0
	 */
	private static function format_schema_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}

		return (string) $value;
	}

	/**
	 * Whether a schema type allows a specific JSON type.
	 *
	 * @since 5.5.0
	 */
	private static function schema_allows_type( mixed $schema_type, string $type ): bool {
		if ( is_array( $schema_type ) ) {
			return in_array( $type, $schema_type, true );
		}

		return $schema_type === $type;
	}

	/**
	 * Check whether all provider-facing paths are top-level fields.
	 *
	 * @since 5.5.0
	 * @param string[] $paths Paths to inspect.
	 */
	private static function provider_paths_are_top_level( array $paths ): bool {
		foreach ( $paths as $path ) {
			if ( str_contains( $path, '.' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Infer JSON Schema type from parameter name and description.
	 *
	 * Legacy compatibility fallback only. New authoritative schemas come from
	 * operation-level parameter contracts.
	 */
	private static function infer_param_type( string $name, string $desc ): string {
		// Integer params.
		if ( in_array( $name, array( 'post_id', 'comment_id', 'review_id', 'order_id', 'user_id', 'media_id', 'log_id', 'product_id', 'coupon_id', 'limit', 'page', 'variation_id', 'menu_id', 'term_id', 'zone_id', 'refund_amount' ), true ) ) {
			return 'integer';
		}
		// Object params.
		if ( in_array( $name, array( 'changes', 'fixes', 'meta', 'settings', 'options', 'item', 'fields', 'items', 'data', 'widget_data', 'template_data', 'conditions', 'line_items' ), true ) ) {
			return 'object';
		}
		// Boolean params.
		if ( in_array( $name, array( 'dry_run', 'force', 'confirmed', 'active', 'enabled' ), true ) ) {
			return 'boolean';
		}
		// Array params.
		if ( in_array( $name, array( 'post_ids', 'term_ids', 'categories', 'tags', 'terms', 'exclude_ids', 'reviews' ), true ) ) {
			return 'array';
		}
		// Description hints.
		$desc_lower = strtolower( $desc );
		if ( str_contains( $desc_lower, 'true/false' ) || str_contains( $desc_lower, 'boolean' ) ) {
			return 'boolean';
		}
		if ( str_contains( $desc_lower, 'number' ) || str_contains( $desc_lower, 'count' ) ) {
			return 'integer';
		}

		return 'string';
	}

	// ── Content Tools ─────────────────────────────────────────────────

	
}
