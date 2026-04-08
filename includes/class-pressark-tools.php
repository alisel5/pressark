<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured tool definitions for the AI system prompt.
 * Each tool is a clear contract the AI uses to construct actions.
 */
class PressArk_Tools {

	/**
	 * Get all tool definitions.
	 *
	 * @param bool $has_woo       Whether WooCommerce is active.
	 * @param bool $has_elementor Whether Elementor is active.
	 * @return array
	 */
	public static function get_all( bool $has_woo = false, bool $has_elementor = false ): array {
		$tools = array(
			// Content tools.
			self::read_content(),
			self::search_content(),
			self::edit_content(),
			self::update_meta(),
			self::create_post(),
			self::delete_content(),
			self::list_posts(),
			self::get_random_content(),
			// SEO & Security.
			self::analyze_seo(),
			self::fix_seo(),
			self::scan_security(),
			self::fix_security(),
			// Site Settings (Part A).
			self::get_site_settings(),
			self::update_site_settings(),
			// Navigation Menus (Part B).
			self::get_menus(),
			self::update_menu(),
			// Media Library (Part C).
			self::list_media(),
			self::get_media(),
			self::update_media(),
			self::delete_media(),
			self::regenerate_thumbnails(),
			// Comments (Part D).
			self::list_comments(),
			self::moderate_comments(),
			self::reply_comment(),
			// Taxonomies (Part E).
			self::list_taxonomies(),
			self::manage_taxonomy(),
			self::assign_terms(),
		);

		// Email Tools (Prompt 7 Part A).
		$tools[] = self::get_email_log();
		// User Management (Prompt 7 Part B).
		$tools[] = self::list_users();
		$tools[] = self::get_user();
		$tools[] = self::update_user();
		// Site Health (Prompt 7 Part C).
		$tools[] = self::site_health();
		// Scheduled Tasks (Prompt 7 Part D).
		$tools[] = self::list_scheduled_tasks();
		$tools[] = self::manage_scheduled_task();

		// Content Generation (Prompt 8 Part C).
		$tools[] = self::generate_content();
		$tools[] = self::rewrite_content();
		$tools[] = self::generate_bulk_meta();
		// Bulk Operations (Prompt 8 Part D).
		$tools[] = self::bulk_delete();
		$tools[] = self::empty_trash();
		$tools[] = self::bulk_delete_media();
		$tools[] = self::bulk_edit();
		$tools[] = self::find_and_replace();
		// Export Reports (Prompt 8 Part E).
		$tools[] = self::export_report();
		// Site Profile (Prompt 9 Part A).
		$tools[] = self::view_site_profile();
		$tools[] = self::refresh_site_profile();
		// Log Analysis (Prompt 9 Part C).
		$tools[] = self::list_logs();
		$tools[] = self::read_log();
		$tools[] = self::analyze_logs();
		$tools[] = self::clear_log();
		// Content Index (Prompt 11).
		$tools[] = self::search_knowledge();
		$tools[] = self::index_status();
		$tools[] = self::rebuild_index();

		if ( $has_woo ) {
			$tools[] = self::get_product();
			$tools[] = self::edit_product();
			$tools[] = self::create_product();
			$tools[] = self::bulk_edit_products();
			$tools[] = self::analyze_store();
			// WooCommerce Deep Tools (Part F).
			$tools[] = self::list_orders();
			$tools[] = self::get_order();
			$tools[] = self::update_order();
			$tools[] = self::manage_coupon();
			$tools[] = self::inventory_report();
			$tools[] = self::sales_summary();
			// WooCommerce Customers (Prompt 7 Part E).
			$tools[] = self::list_customers();
			$tools[] = self::get_customer();
			$tools[] = self::email_customer();
			// WooCommerce Shipping & Tax (Prompt 7 Part F).
			$tools[] = self::get_shipping_zones();
			$tools[] = self::get_tax_settings();
			$tools[] = self::get_payment_gateways();
			$tools[] = self::get_wc_settings();
			$tools[] = self::get_wc_emails();
			$tools[] = self::get_wc_status();
			// WooCommerce Product Reviews (Prompt 7 Part G).
			$tools[] = self::list_reviews();
			$tools[] = self::moderate_review();
			$tools[] = self::reply_review();
			$tools[] = self::bulk_reply_reviews();
			// WooCommerce Variations & Orders (Prompt 12 Part E).
			$tools[] = self::list_variations();
			$tools[] = self::edit_variation();
			$tools[] = self::create_variation();
			$tools[] = self::bulk_edit_variations();
			$tools[] = self::list_product_attributes();
			$tools[] = self::create_refund();
			$tools[] = self::get_top_sellers();
			$tools[] = self::create_order();
			// Prompt 26B: WooCommerce New Capabilities.
			$tools[] = self::trigger_wc_email();
			$tools[] = self::get_order_statuses();
			$tools[] = self::get_products_on_sale();
			$tools[] = self::customer_insights();
			$tools[] = self::category_report();
			// Prompt 28B: WooCommerce Revenue, Stock, Webhooks, Events.
			$tools[] = self::revenue_report();
			$tools[] = self::stock_report();
			$tools[] = self::manage_webhooks();
			$tools[] = self::get_wc_alerts();
			// WooCommerce Health (Prompt 19 Part C).
			$tools[] = self::store_health();
		}

		// Elementor Tools (Prompt 12 Part A + Prompt 19 Part A).
		if ( $has_elementor ) {
			$tools[] = self::elementor_read_page();
			$tools[] = self::elementor_find_widgets();
			$tools[] = self::elementor_edit_widget();
			$tools[] = self::elementor_add_widget();
			$tools[] = self::elementor_add_container();
			$tools[] = self::elementor_list_templates();
			$tools[] = self::elementor_create_from_template();
			$tools[] = self::elementor_get_styles();
			$tools[] = self::elementor_find_replace();
			$tools[] = self::elementor_audit_page();
			$tools[] = self::elementor_site_pages();
			$tools[] = self::elementor_global_styles();
			$tools[] = self::elementor_create_page();
			$tools[] = self::elementor_get_widget_schema();
			$tools[] = self::elementor_get_breakpoints();
			$tools[] = self::elementor_clone_page();
			$tools[] = self::elementor_manage_conditions();
			$tools[] = self::elementor_list_dynamic_tags();
			$tools[] = self::elementor_set_dynamic_tag();
			$tools[] = self::elementor_read_form();
			$tools[] = self::elementor_edit_form_field();
			$tools[] = self::elementor_set_visibility();
			$tools[] = self::elementor_list_popups();
			$tools[] = self::elementor_edit_popup_trigger();
		}

		// Automation Tools (v4.0.0) — loaded via 'automations' group, not unconditionally.

		// Composite Tools (Prompt 19 Part D).
		$tools[] = self::site_brief();
		$tools[] = self::page_audit();

		// Diagnostic Tools (Prompt 17).
		$tools[] = self::inspect_hooks();
		$tools[] = self::measure_page_speed();
		$tools[] = self::check_crawlability();
		$tools[] = self::check_email_delivery();
		$tools[] = self::profile_queries();
		$tools[] = self::get_revision_history();

		// REST & Cache Diagnostics (Prompt 23B).
		$tools[] = self::discover_rest_routes();
		$tools[] = self::call_rest_endpoint();
		$tools[] = self::diagnose_cache();
		$tools[] = self::analyze_comment_moderation();

		// Discovery Tools (Prompt 13 — on-demand context).
		$tools[] = self::get_site_overview();
		$tools[] = self::get_site_map();
		$tools[] = self::get_brand_profile();
		$tools[] = self::get_available_tools();
		$tools[] = self::site_note();
		// Resource Bridge (v5.1.0).
		$tools[] = self::list_resources();
		$tools[] = self::read_resource();

		// Plugin Management (Prompt 12 Part B).
		$tools[] = self::list_plugins();
		$tools[] = self::toggle_plugin();
		// Theme Management (Prompt 12 Part C).
		$tools[] = self::list_themes();
		$tools[] = self::get_theme_settings();
		$tools[] = self::get_customizer_schema();
		$tools[] = self::update_theme_setting();
		$tools[] = self::switch_theme();
		// Database Maintenance (Prompt 12 Part D).
		$tools[] = self::database_stats();
		$tools[] = self::cleanup_database();
		$tools[] = self::optimize_database();

		// Gutenberg Block Tools (Prompt 20 Part A).
		$tools[] = self::read_blocks();
		$tools[] = self::edit_block();
		$tools[] = self::insert_block();
		// ACF / Custom Fields (Prompt 20 Part B).
		$tools[] = self::get_custom_fields();
		$tools[] = self::update_custom_field();
		// Forms Detection (Prompt 20 Part D).
		$tools[] = self::list_forms();

		// FSE Templates, Design System, Patterns, Multisite (Prompt 25B).
		$tools[] = self::get_templates();
		$tools[] = self::edit_template();
		$tools[] = self::get_design_system();
		$tools[] = self::list_patterns();
		$tools[] = self::insert_pattern();
		$tools[] = self::network_overview();

		return $tools;
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
		$all           = self::get_all( $has_woo, $has_elementor );
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
		$tools = self::get_all( $has_woo, $has_elementor );
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
			'list_plugins', 'toggle_plugin',
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
		$all_tools = self::get_all( $has_woo, $has_elementor );
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
		$total_available = count( self::get_all( $has_woo, $has_elementor ) );
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
		$all_tools = self::get_all( $has_woo, $has_elementor );
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
		$description = trim( (string) ( $tool['description'] ?? '' ) );
		$name        = (string) ( $tool['name'] ?? '' );

		if ( '' === $description && class_exists( 'PressArk_Operation_Registry' ) ) {
			$contract = PressArk_Operation_Registry::get_contract( $name );
			$description = trim( (string) ( $contract['description'] ?? '' ) );
		}

		$guidance = array();
		$contract = self::get_tool_parameter_contract( $tool );
		if ( is_array( $contract ) ) {
			$guidance = array_merge( $guidance, self::summarize_contract_rules( $contract ) );
		}
		if ( class_exists( 'PressArk_Operation_Registry' ) ) {
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
		$schema = self::compile_contract_node( $contract, true );
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}
		if ( ! isset( $schema['required'] ) ) {
			$schema['required'] = array();
		}

		// OpenRouter-backed tool transports can reject top-level oneOf/allOf/anyOf
		// clauses on custom input schemas. Keep those rules in runtime validation
		// and in the tool description guidance, but omit them from the provider-
		// facing root schema for broad transport compatibility.
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

	private static function read_content(): array {
		return array(
			'name'        => 'read_content',
			'description' => 'Read post/page by ID, URL, or slug. Modes: summary/light (default), detail/structured, raw/full. Use section to trim raw reads.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false ),
				array( 'name' => 'url', 'required' => false ),
				array( 'name' => 'slug', 'required' => false ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|detail|raw or light|structured|full (default: summary/light)' ),
				array( 'name' => 'section', 'required' => false, 'desc' => 'head|tail|first_n_paragraphs — trim full-mode content to reduce size' ),
				array( 'name' => 'paragraphs', 'required' => false, 'desc' => 'Number of paragraphs for first_n_paragraphs section (default: 5)' ),
			),
		);
	}

	private static function search_content(): array {
		return array(
			'name'        => 'search_content',
			'description' => 'Search posts/pages by keyword. Supports date/meta filtering and pagination via offset. Returns _pagination metadata.',
			'params'      => array(
				array( 'name' => 'query', 'required' => true ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'post|page|any (default: any)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max results (default: 20, max: 100)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N results for pagination (default: 0)' ),
				array( 'name' => 'after', 'required' => false, 'desc' => 'Published after date (strtotime-compatible)' ),
				array( 'name' => 'before', 'required' => false, 'desc' => 'Published before date (strtotime-compatible)' ),
				array( 'name' => 'meta_key', 'required' => false ),
				array( 'name' => 'meta_value', 'required' => false ),
				array( 'name' => 'meta_compare', 'required' => false, 'desc' => '=|!=|>|<|LIKE|EXISTS|NOT EXISTS|IN|BETWEEN' ),
			),
		);
	}

	private static function edit_content(): array {
		return array(
			'name'        => 'edit_content',
			'description' => 'Edit post/page: title, content, excerpt, slug, status, sticky, post_format.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false ),
				array( 'name' => 'url', 'required' => false ),
				array( 'name' => 'slug', 'required' => false ),
				array( 'name' => 'changes', 'required' => true, 'desc' => 'Fields: title, content, excerpt, slug, status, sticky, post_format' ),
			),
		);
	}

	private static function update_meta(): array {
		return array(
			'name'        => 'update_meta',
			'description' => 'Update post meta. Keys: meta_title, meta_description, og_title, og_description, og_image, focus_keyword.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'changes', 'required' => true, 'desc' => 'Object of meta key/value pairs' ),
			),
		);
	}

	private static function create_post(): array {
		return array(
			'name'        => 'create_post',
			'description' => 'Create a new post or page with optional slug, excerpt, SEO, and social metadata in one write. Supports scheduling via status "future" + scheduled_date.',
			'params'      => array(
				array( 'name' => 'title', 'required' => true, 'desc' => 'Post or page title' ),
				array( 'name' => 'content', 'required' => false, 'desc' => 'HTML content' ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'post|page (default: post)' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'draft|publish|future (default: draft)' ),
				array( 'name' => 'scheduled_date', 'required' => false, 'desc' => 'Y-m-d H:i:s, server timezone' ),
				array( 'name' => 'slug', 'required' => false, 'desc' => 'Clean URL slug' ),
				array( 'name' => 'excerpt', 'required' => false, 'desc' => 'Manual excerpt or short summary' ),
				array( 'name' => 'meta_title', 'required' => false, 'desc' => 'SEO title using semantic key routing' ),
				array( 'name' => 'meta_description', 'required' => false, 'desc' => 'SEO meta description using semantic key routing' ),
				array( 'name' => 'og_title', 'required' => false, 'desc' => 'Open Graph title' ),
				array( 'name' => 'og_description', 'required' => false, 'desc' => 'Open Graph description' ),
				array( 'name' => 'og_image', 'required' => false, 'desc' => 'Open Graph image URL' ),
				array( 'name' => 'focus_keyword', 'required' => false, 'desc' => 'Primary SEO keyword/keyphrase' ),
				array( 'name' => 'page_template', 'required' => false, 'desc' => 'Template filename for pages' ),
			),
		);
	}

	private static function delete_content(): array {
		return array(
			'name'        => 'delete_content',
			'description' => 'Move a post/page to trash.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
			),
		);
	}

	private static function list_posts(): array {
		return array(
			'name'        => 'list_posts',
			'description' => 'Query posts/pages with filters. Includes word count, sticky flag, post format. Supports pagination via offset. Returns _pagination metadata.',
			'params'      => array(
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'post|page|product|any (default: any)' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'publish|draft|any (default: any)' ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'count', 'required' => false, 'desc' => 'Max results (default: 20, max: 50)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N results for pagination (default: 0)' ),
				array( 'name' => 'needs_seo', 'required' => false, 'desc' => 'true = only posts missing SEO title' ),
				array( 'name' => 'min_words', 'required' => false ),
				array( 'name' => 'max_words', 'required' => false ),
				array( 'name' => 'modified_after', 'required' => false, 'desc' => 'Y-m-d date filter' ),
			),
		);
	}

	private static function get_random_content(): array {
		return array(
			'name'        => 'get_random_content',
			'description' => 'Pick one random post, page, or product for writing, auditing, or structure analysis. Modes: light (compact summary), structured (headings + section summaries). For product-led content, use post_type="product"; if the result is not rich enough for grounded details, follow with get_product.',
			'params'      => array(
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'post|page|product|any (default: any)' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'publish|draft|private|any (default: publish)' ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'light|structured (default: light)' ),
				array( 'name' => 'exclude_ids', 'required' => false, 'desc' => 'Array of post IDs to skip' ),
			),
		);
	}

	// ── SEO & Security ────────────────────────────────────────────────

	private static function analyze_seo(): array {
		return array(
			'name'        => 'analyze_seo',
			'description' => 'Deep SEO analysis with subscores (indexing_health, search_appearance, content_quality, social_sharing) for a single page or full site. Use limit/offset to paginate site-wide scans.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true, 'desc' => 'Post ID or "all" for site-wide scan' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max pages to scan in site-wide mode (default: 50, max: 100)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N pages in site-wide scan (default: 0)' ),
			),
		);
	}

	private static function fix_seo(): array {
		return array(
			'name'        => 'fix_seo',
			'description' => 'Apply SEO fixes. Prefer passing specific fixes so user sees exact changes in preview.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false, 'desc' => 'Post ID or "all" (default: "all")' ),
				array( 'name' => 'fixes', 'required' => false, 'desc' => 'Array of {post_id, meta_title, meta_description, og_title} objects' ),
				array( 'name' => 'force', 'required' => false, 'desc' => 'Set true to overwrite existing manual/template meta. Default: false (only fills empty fields).' ),
			),
		);
	}

	private static function scan_security(): array {
		return array(
			'name'        => 'scan_security',
			'description' => 'Run a site security audit. Returns per-check status and auto_fixable flags. Only propose fix_security for issues marked auto_fixable in this scan.',
			'params'      => array(
				array( 'name' => 'severity', 'required' => false, 'desc' => 'critical|high|medium|low — filter to only issues at this severity or higher' ),
			),
		);
	}

	private static function fix_security(): array {
		return array(
			'name'        => 'fix_security',
			'description' => 'Apply auto-fixable security fixes. Only pass fix IDs returned by the latest scan as auto_fixable findings. Never pass both by default. If the scan shows none, do not call this tool.',
			'params'      => array(
				array( 'name' => 'fixes', 'required' => true, 'desc' => 'Array of fix IDs: "delete_exposed_files", "disable_xmlrpc"' ),
			),
		);
	}

	// ── Part A: Site Settings ─────────────────────────────────────────

	private static function get_site_settings(): array {
		return array(
			'name'        => 'get_site_settings',
			'description' => 'Read WordPress site settings. Pass discover=true to list all registered options by page. Use section to filter discover results to a specific settings group.',
			'params'      => array(
				array( 'name' => 'keys', 'required' => false, 'desc' => 'Array of option names (default: common settings)' ),
				array( 'name' => 'discover', 'type' => 'boolean', 'required' => false, 'desc' => 'true = list all registered settings grouped by page' ),
				array( 'name' => 'section', 'required' => false, 'desc' => 'Filter discover results to a specific group (e.g. general, reading, writing, discussion, media, permalink)' ),
			),
		);
	}

	private static function update_site_settings(): array {
		return array(
			'name'        => 'update_site_settings',
			'description' => 'Update WordPress site settings. Supports undo.',
			'params'      => array(
				array( 'name' => 'changes', 'required' => true, 'desc' => 'Allowed keys: blogname, blogdescription, timezone_string, date_format, time_format, posts_per_page, permalink_structure, default_comment_status, show_on_front, page_on_front, page_for_posts' ),
			),
		);
	}

	// ── Part B: Navigation Menus ──────────────────────────────────────

	private static function get_menus(): array {
		return array(
			'name'        => 'get_menus',
			'description' => 'List navigation menus, items, and theme locations. Auto-detects FSE vs classic menus. Use mode=summary to get menu names and item counts without full item lists.',
			'params'      => array(
				array( 'name' => 'menu_id', 'required' => false, 'desc' => 'Specific menu ID for details (default: list all)' ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|full — summary omits item details (default: full)' ),
			),
		);
	}

	private static function update_menu(): array {
		return array(
			'name'        => 'update_menu',
			'description' => 'Create, modify, or assign navigation menus. Handles FSE and classic menus.',
			'params'      => array(
				array( 'name' => 'operation', 'required' => true, 'desc' => 'create_menu|add_item|remove_item|assign_location|rename_menu|delete_menu' ),
				array( 'name' => 'menu_id', 'required' => false ),
				array( 'name' => 'name', 'required' => false, 'desc' => 'For create_menu and rename_menu' ),
				array( 'name' => 'item', 'required' => false, 'desc' => '{title, url, type, object_id, position}' ),
				array( 'name' => 'item_id', 'required' => false, 'desc' => 'For remove_item on classic menus' ),
				array( 'name' => 'location', 'required' => false, 'desc' => 'Theme location slug for assign_location' ),
			),
		);
	}

	// ── Part C: Media Library ─────────────────────────────────────────

	private static function list_media(): array {
		return array(
			'name'        => 'list_media',
			'description' => 'List media library attachments with optional filters. Supports pagination via offset. Returns _pagination metadata.',
			'params'      => array(
				array( 'name' => 'mime_type', 'required' => false, 'desc' => 'image|video|audio|application (default: all)' ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'count', 'required' => false, 'desc' => 'Max results (default: 20, max: 50)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N results for pagination (default: 0)' ),
				array( 'name' => 'post_id', 'required' => false, 'desc' => 'Filter to media attached to this post' ),
			),
		);
	}

	private static function get_media(): array {
		return array(
			'name'        => 'get_media',
			'description' => 'Get full details of a media attachment: EXIF, thumbnails, attached post.',
			'params'      => array(
				array( 'name' => 'attachment_id', 'required' => true ),
			),
		);
	}

	private static function update_media(): array {
		return array(
			'name'        => 'update_media',
			'description' => 'Update media attachment: alt, title, caption, description, or set as featured image.',
			'params'      => array(
				array( 'name' => 'attachment_id', 'required' => true ),
				array( 'name' => 'changes', 'required' => true, 'desc' => '{alt, title, caption, description, set_featured_for}' ),
			),
		);
	}

	private static function delete_media(): array {
		return array(
			'name'        => 'delete_media',
			'description' => 'Permanently delete a media attachment.',
			'params'      => array(
				array( 'name' => 'attachment_id', 'required' => true ),
			),
		);
	}

	private static function regenerate_thumbnails(): array {
		return array(
			'name'        => 'regenerate_thumbnails',
			'description' => 'Regenerate thumbnail sizes for images. Max 20 per call.',
			'params'      => array(
				array( 'name' => 'media_id', 'required' => false, 'desc' => 'Single attachment ID' ),
				array( 'name' => 'media_ids', 'required' => false, 'desc' => 'Array of attachment IDs' ),
				array( 'name' => 'post_id', 'required' => false, 'desc' => 'Regenerate all images on this post' ),
			),
		);
	}

	// ── Part D: Comments ──────────────────────────────────────────────

	private static function list_comments(): array {
		return array(
			'name'        => 'list_comments',
			'description' => 'List comments with filters and aggregate counts. Pingbacks excluded by default.',
			'params'      => array(
				array( 'name' => 'status', 'required' => false, 'desc' => 'approve|hold|spam|trash|all (default: all)' ),
				array( 'name' => 'post_id', 'required' => false ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'author_email', 'required' => false ),
				array( 'name' => 'include_pingbacks', 'required' => false, 'desc' => 'true to include pingbacks (default: false)' ),
				array( 'name' => 'count', 'required' => false, 'desc' => 'Max results (default: 20, max: 50)' ),
			),
		);
	}

	private static function moderate_comments(): array {
		return array(
			'name'        => 'moderate_comments',
			'description' => 'Moderate one or more comments. Spam/unspam notifies Akismet.',
			'params'      => array(
				array( 'name' => 'comment_ids', 'required' => true, 'desc' => 'Array of comment IDs' ),
				array( 'name' => 'action', 'required' => true, 'desc' => 'approve|unapprove|spam|unspam|trash|untrash' ),
			),
		);
	}

	private static function reply_comment(): array {
		return array(
			'name'        => 'reply_comment',
			'description' => 'Reply to a comment as the current admin user.',
			'params'      => array(
				array( 'name' => 'comment_id', 'required' => true, 'desc' => 'Parent comment ID' ),
				array( 'name' => 'content', 'required' => true, 'desc' => 'Reply text' ),
			),
		);
	}

	// ── Part E: Taxonomies ────────────────────────────────────────────

	private static function list_taxonomies(): array {
		return array(
			'name'        => 'list_taxonomies',
			'description' => 'List all registered taxonomies and their terms.',
			'params'      => array(
				array( 'name' => 'taxonomy', 'required' => false, 'desc' => 'Specific slug (default: list all)' ),
				array( 'name' => 'hide_empty', 'required' => false, 'desc' => 'Hide terms with no posts (default: false)' ),
			),
		);
	}

	private static function manage_taxonomy(): array {
		return array(
			'name'        => 'manage_taxonomy',
			'description' => 'Create, edit, or delete taxonomy terms.',
			'params'      => array(
				array( 'name' => 'operation', 'required' => true, 'desc' => 'create|edit|delete' ),
				array( 'name' => 'taxonomy', 'required' => true, 'desc' => 'Taxonomy slug' ),
				array( 'name' => 'term_id', 'required' => false, 'desc' => 'Required for edit/delete' ),
				array( 'name' => 'name', 'required' => false, 'desc' => 'Required for create' ),
				array( 'name' => 'slug', 'required' => false ),
				array( 'name' => 'description', 'required' => false ),
				array( 'name' => 'parent', 'required' => false, 'desc' => 'Parent term ID for hierarchical taxonomies' ),
			),
		);
	}

	private static function assign_terms(): array {
		return array(
			'name'        => 'assign_terms',
			'description' => 'Assign taxonomy terms to a post. Accepts names or IDs.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'taxonomy', 'required' => true, 'desc' => 'Taxonomy slug' ),
				array( 'name' => 'terms', 'required' => true, 'desc' => 'Array of term names or IDs' ),
				array( 'name' => 'append', 'required' => false, 'desc' => 'true = add to existing, false = replace (default: false)' ),
			),
		);
	}

	// ── WooCommerce Tools ─────────────────────────────────────────────

	private static function get_product(): array {
		return array(
			'name'        => 'get_product',
			'description' => 'Get full WooCommerce product data: permalink, descriptions, price, stock, categories, attributes, images, SKU. Use this when a lighter product read is not rich enough for grounded content or a valid CTA.',
			'params'      => array(
				array( 'name' => 'product_id', 'required' => true, 'desc' => 'WooCommerce product ID' ),
			),
		);
	}

	private static function edit_product(): array {
		return array(
			'name'        => 'edit_product',
			'description' => 'Update a WooCommerce product. Supports 30+ fields via WC object model.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'changes', 'required' => true, 'desc' => 'Fields: name, description, short_description, regular_price (plain "price" maps here), sale_price, price_delta, price_adjust_pct, sku, stock_quantity (plain "stock" maps here), stock_adjust, status, category_ids, tag_ids, image_id, weight, dimensions, featured, virtual, and more' ),
			),
		);
	}

	private static function create_product(): array {
		return array(
			'name'        => 'create_product',
			'description' => 'Create a WooCommerce product. All edit_product fields accepted.',
			'params'      => array(
				array( 'name' => 'name', 'required' => true ),
				array( 'name' => 'type', 'required' => false, 'desc' => 'simple|variable|grouped|external (default: simple)' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'draft|publish|pending|private (default: draft)' ),
				array( 'name' => 'regular_price', 'required' => false, 'desc' => 'Regular price; plain "price" also maps here' ),
				array( 'name' => 'description', 'required' => false ),
				array( 'name' => 'short_description', 'required' => false ),
			),
		);
	}

	private static function bulk_edit_products(): array {
		return array(
			'name'        => 'bulk_edit_products',
			'description' => 'Update multiple WooCommerce products at once.',
			'params'      => array(
				array( 'name' => 'products', 'required' => false, 'desc' => 'Array of objects, each with post_id (int) and a changes object: [{post_id: 10, changes: {description: "New text"}}, {post_id: 11, changes: {short_description: "...", regular_price: "19.99"}}]. Use this when each product gets different values.' ),
				array( 'name' => 'scope', 'required' => false, 'desc' => 'Use "all" or "matching" to target many products without enumerating them one by one' ),
				array( 'name' => 'changes', 'required' => false, 'desc' => 'Shared changes for scope-based bulk updates. Supports price_delta and price_adjust_pct for relative price changes.' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'Optional product status filter for scope-based bulk updates. Default: publish' ),
				array( 'name' => 'search', 'required' => false, 'desc' => 'Optional search filter for scope-based bulk updates' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Optional batch size cap for scope-based bulk updates. Max: 50' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Optional offset for paginating large scope-based bulk updates' ),
			),
		);
	}

	private static function analyze_store(): array {
		return array(
			'name'        => 'analyze_store',
			'description' => 'WooCommerce health check: missing descriptions, images, categories, prices, low stock.',
			'params'      => array(),
		);
	}

	// ── Part F: WooCommerce Deep Tools ────────────────────────────────

	private static function list_orders(): array {
		return array(
			'name'        => 'list_orders',
			'description' => 'List WooCommerce orders with filters. Supports pagination via offset. Returns _pagination metadata.',
			'params'      => array(
				array( 'name' => 'status', 'required' => false, 'desc' => 'pending|processing|on-hold|completed|cancelled|refunded|failed|any (default: any)' ),
				array( 'name' => 'count', 'required' => false, 'desc' => 'Max results (default: 20, max: 50)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N results for pagination (default: 0)' ),
				array( 'name' => 'date_after', 'required' => false, 'desc' => 'Y-m-d' ),
				array( 'name' => 'date_before', 'required' => false, 'desc' => 'Y-m-d' ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'customer_id', 'required' => false ),
				array( 'name' => 'customer_email', 'required' => false ),
				array( 'name' => 'payment_method', 'required' => false, 'desc' => 'Gateway ID' ),
			),
		);
	}

	private static function get_order(): array {
		return array(
			'name'        => 'get_order',
			'description' => 'Get full details of a WooCommerce order.',
			'params'      => array(
				array( 'name' => 'order_id', 'required' => true ),
			),
		);
	}

	private static function update_order(): array {
		return array(
			'name'        => 'update_order',
			'description' => 'Update order status or add a note.',
			'params'      => array(
				array( 'name' => 'order_id', 'required' => true ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'New order status' ),
				array( 'name' => 'note', 'required' => false ),
				array( 'name' => 'customer_note', 'required' => false, 'desc' => 'true = note visible to customer (default: false)' ),
			),
		);
	}

	private static function manage_coupon(): array {
		return array(
			'name'        => 'manage_coupon',
			'description' => 'Get, list, create, edit, or delete WooCommerce coupons.',
			'params'      => array(
				array( 'name' => 'operation', 'required' => true, 'desc' => 'get|list|create|edit|delete' ),
				array( 'name' => 'coupon_id', 'required' => false, 'desc' => 'Required for get/edit/delete' ),
				array( 'name' => 'code', 'required' => false, 'desc' => 'Required for create' ),
				array( 'name' => 'discount_type', 'required' => false, 'desc' => 'percent|fixed_cart|fixed_product (default: percent)' ),
				array( 'name' => 'amount', 'required' => false ),
				array( 'name' => 'usage_limit', 'required' => false, 'desc' => '0 = unlimited' ),
				array( 'name' => 'expiry_date', 'required' => false, 'desc' => 'Y-m-d' ),
				array( 'name' => 'minimum_amount', 'required' => false ),
				array( 'name' => 'individual_use', 'required' => false, 'desc' => 'true = cannot combine with other coupons' ),
			),
		);
	}

	private static function inventory_report(): array {
		return array(
			'name'        => 'inventory_report',
			'description' => 'Get inventory status: low stock, out of stock, stock levels.',
			'params'      => array(
				array( 'name' => 'threshold', 'required' => false, 'desc' => 'Low stock threshold (default: 5)' ),
			),
		);
	}

	private static function sales_summary(): array {
		return array(
			'name'        => 'sales_summary',
			'description' => 'Sales summary: revenue, order count, average order value for a date range.',
			'params'      => array(
				array( 'name' => 'period', 'required' => false, 'desc' => 'today|week|month|year|custom (default: month)' ),
				array( 'name' => 'date_from', 'required' => false, 'desc' => 'Y-m-d for custom period' ),
				array( 'name' => 'date_to', 'required' => false, 'desc' => 'Y-m-d for custom period' ),
			),
		);
	}

	// ── Prompt 7 Part A: Email Tools ─────────────────────────────────

	private static function get_email_log(): array {
		return array(
			'name'        => 'get_email_log',
			'description' => 'Check recently sent emails: recipient, subject, status, timestamp.',
			'params'      => array(
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max results (default: 20, max: 50)' ),
			),
		);
	}

	// ── Prompt 7 Part B: User Management ─────────────────────────────

	private static function list_users(): array {
		return array(
			'name'        => 'list_users',
			'description' => 'List WordPress users with roles, email, registration date. Includes security notices.',
			'params'      => array(
				array( 'name' => 'role', 'required' => false, 'desc' => 'Single role filter' ),
				array( 'name' => 'roles', 'required' => false, 'desc' => 'Array of roles (overrides role)' ),
				array( 'name' => 'exclude_roles', 'required' => false ),
				array( 'name' => 'capability', 'required' => false, 'desc' => 'Filter by WP capability' ),
				array( 'name' => 'has_published_posts', 'required' => false, 'desc' => 'true = only authors with posts' ),
				array( 'name' => 'registered_after', 'required' => false ),
				array( 'name' => 'registered_before', 'required' => false ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20' ),
			),
		);
	}

	private static function get_user(): array {
		return array(
			'name'        => 'get_user',
			'description' => 'Get detailed user info by ID. Includes WooCommerce data for customers.',
			'params'      => array(
				array( 'name' => 'user_id', 'required' => true ),
			),
		);
	}

	private static function update_user(): array {
		return array(
			'name'        => 'update_user',
			'description' => 'Update a WordPress user profile. Cannot change passwords.',
			'params'      => array(
				array( 'name' => 'user_id', 'required' => true ),
				array( 'name' => 'display_name', 'required' => false ),
				array( 'name' => 'role', 'required' => false ),
				array( 'name' => 'first_name', 'required' => false ),
				array( 'name' => 'last_name', 'required' => false ),
				array( 'name' => 'description', 'required' => false, 'desc' => 'Bio' ),
				array( 'name' => 'url', 'required' => false, 'desc' => 'Website URL' ),
			),
		);
	}

	// ── Prompt 7 Part C: Site Health ──────────────────────────────────

	private static function site_health(): array {
		return array(
			'name'        => 'site_health',
			'description' => 'Hint: Use include_debug=true for server config, PHP, memory, disk, plugin versions. WordPress Site Health status with optional debug data. Use checks param to run specific checks only.',
			'params'      => array(
				array( 'name' => 'section', 'required' => false, 'desc' => 'status|full (default: status)' ),
				array( 'name' => 'include_debug', 'required' => false, 'desc' => 'true = include server config, PHP version, memory, disk, plugins' ),
				array( 'name' => 'checks', 'required' => false, 'desc' => 'Array of specific test names to run (e.g. ["php_version","ssl_support"]). Default: all tests.' ),
				array( 'name' => 'category', 'required' => false, 'desc' => 'critical|recommended|good — filter results to only this status category' ),
			),
		);
	}

	// ── Prompt 7 Part D: Scheduled Tasks ─────────────────────────────

	private static function list_scheduled_tasks(): array {
		return array(
			'name'        => 'list_scheduled_tasks',
			'description' => 'List all WordPress WP-Cron scheduled tasks.',
			'params'      => array(),
		);
	}

	private static function manage_scheduled_task(): array {
		return array(
			'name'        => 'manage_scheduled_task',
			'description' => 'Run, remove, or remove_all instances of a WP-Cron hook.',
			'params'      => array(
				array( 'name' => 'action', 'required' => true, 'desc' => 'run|remove|remove_all' ),
				array( 'name' => 'hook', 'required' => true ),
				array( 'name' => 'timestamp', 'required' => false, 'desc' => 'Target timestamp for remove (default: next occurrence)' ),
			),
		);
	}

	// ── Prompt 7 Part E: WooCommerce Customers ───────────────────────

	private static function list_customers(): array {
		return array(
			'name'        => 'list_customers',
			'description' => 'List WooCommerce customers with order history and total spent. Supports pagination via offset. Returns _pagination metadata.',
			'params'      => array(
				array( 'name' => 'search', 'required' => false, 'desc' => 'Search by name or email' ),
				array( 'name' => 'orderby', 'required' => false, 'desc' => 'date_registered|total_spent|order_count (default: total_spent)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max results (default: 20, max: 100)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N results for pagination (default: 0)' ),
			),
		);
	}

	private static function get_customer(): array {
		return array(
			'name'        => 'get_customer',
			'description' => 'Get full customer profile: addresses, order history, total spent.',
			'params'      => array(
				array( 'name' => 'customer_id', 'required' => true ),
			),
		);
	}

	private static function email_customer(): array {
		return array(
			'name'        => 'email_customer',
			'description' => 'Send a personalized email to a WooCommerce customer.',
			'params'      => array(
				array( 'name' => 'customer_id', 'required' => true ),
				array( 'name' => 'subject', 'required' => true ),
				array( 'name' => 'body', 'required' => true, 'desc' => 'HTML supported' ),
			),
		);
	}

	// ── Prompt 7 Part F: WooCommerce Shipping & Tax ──────────────────

	private static function get_shipping_zones(): array {
		return array(
			'name'        => 'get_shipping_zones',
			'description' => 'List WooCommerce shipping zones with methods, costs, and free shipping thresholds.',
			'params'      => array(),
		);
	}

	private static function get_tax_settings(): array {
		return array(
			'name'        => 'get_tax_settings',
			'description' => 'Get WooCommerce tax configuration: rates, calculation, display options.',
			'params'      => array(),
		);
	}

	private static function get_payment_gateways(): array {
		return array(
			'name'        => 'get_payment_gateways',
			'description' => 'List WooCommerce payment gateways with availability and test mode flags.',
			'params'      => array(
				array( 'name' => 'enabled_only', 'required' => false, 'desc' => 'true = only enabled gateways (default: false)' ),
			),
		);
	}

	private static function get_wc_settings(): array {
		return array(
			'name'        => 'get_wc_settings',
			'description' => 'Get WooCommerce store settings: currency, address, measurements, checkout.',
			'params'      => array(
				array( 'name' => 'section', 'required' => false, 'desc' => 'general|products|accounts (default: general)' ),
			),
		);
	}

	private static function get_wc_emails(): array {
		return array(
			'name'        => 'get_wc_emails',
			'description' => 'List all WooCommerce email notifications with enabled/disabled status.',
			'params'      => array(),
		);
	}

	private static function get_wc_status(): array {
		return array(
			'name'        => 'get_wc_status',
			'description' => 'Full WooCommerce system status: environment, DB health, plugins, template overrides, HPOS.',
			'params'      => array(),
		);
	}

	// ── Prompt 7 Part G: WooCommerce Product Reviews ─────────────────

	private static function list_reviews(): array {
		return array(
			'name'        => 'list_reviews',
			'description' => 'List WooCommerce product reviews with filters.',
			'params'      => array(
				array( 'name' => 'product_id', 'required' => false ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'approved|pending|spam|trash|all (default: all)' ),
				array( 'name' => 'rating', 'required' => false, 'desc' => '1-5' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20' ),
			),
		);
	}

	private static function moderate_review(): array {
		return array(
			'name'        => 'moderate_review',
			'description' => 'Approve, unapprove, spam, trash, or reply to a product review.',
			'params'      => array(
				array( 'name' => 'review_id', 'required' => true ),
				array( 'name' => 'action', 'required' => true, 'desc' => 'approve|unapprove|spam|trash|reply' ),
				array( 'name' => 'reply_content', 'required' => false, 'desc' => 'Required when action=reply' ),
			),
		);
	}

	private static function reply_review(): array {
		return array(
			'name'        => 'reply_review',
			'description' => 'Reply to a WooCommerce product review.',
			'params'      => array(
				array( 'name' => 'review_id', 'required' => true ),
				array( 'name' => 'reply_content', 'required' => true, 'desc' => 'Reply text to post under the review' ),
			),
		);
	}

	private static function bulk_reply_reviews(): array {
		return array(
			'name'        => 'bulk_reply_reviews',
			'description' => 'Reply to multiple WooCommerce product reviews in one action.',
			'params'      => array(
				array( 'name' => 'reviews', 'required' => true, 'desc' => 'Array of {review_id, reply_content} objects' ),
			),
		);
	}

	// ── Prompt 8 Part C: Content Generation ──────────────────────────

	private static function generate_content(): array {
		return array(
			'name'        => 'generate_content',
			'description' => 'Generate AI content for review. Does NOT publish — use edit_content/create_post to apply.',
			'params'      => array(
				array( 'name' => 'type', 'required' => true, 'desc' => 'blog_post|product_description|page_content|email_draft|social_media|meta_tags|custom' ),
				array( 'name' => 'topic', 'required' => true ),
				array( 'name' => 'tone', 'required' => false, 'desc' => 'professional|casual|friendly|formal|humorous|technical|persuasive (default: professional)' ),
				array( 'name' => 'length', 'required' => false, 'desc' => 'short|medium|long|detailed (default: medium)' ),
				array( 'name' => 'keywords', 'required' => false, 'desc' => 'Array of SEO keywords' ),
				array( 'name' => 'target_audience', 'required' => false ),
				array( 'name' => 'reference_post_id', 'required' => false, 'desc' => 'Post ID for style reference' ),
				array( 'name' => 'additional_instructions', 'required' => false ),
			),
		);
	}

	private static function rewrite_content(): array {
		return array(
			'name'        => 'rewrite_content',
			'description' => 'Rewrite or improve existing post content. Returns new version for review.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'instructions', 'required' => false, 'desc' => 'improve|expand|simplify|seo_optimize|change_tone|custom (default: improve)' ),
				array( 'name' => 'tone', 'required' => false ),
				array( 'name' => 'keywords', 'required' => false, 'desc' => 'SEO keywords to weave in' ),
				array( 'name' => 'preserve_structure', 'required' => false, 'desc' => 'Keep headings/sections (default: true)' ),
			),
		);
	}

	private static function generate_bulk_meta(): array {
		return array(
			'name'        => 'generate_bulk_meta',
			'description' => 'Generate SEO meta for multiple pages. Apply results via fix_seo.',
			'params'      => array(
				array( 'name' => 'post_ids', 'required' => false, 'desc' => 'Omit for all pages missing meta' ),
				array( 'name' => 'style', 'required' => false, 'desc' => 'descriptive|action-oriented|question-based|benefit-focused (default: descriptive)' ),
			),
		);
	}

	// ── Prompt 8 Part D: Bulk Operations ─────────────────────────────

	private static function bulk_delete(): array {
		return array(
			'name'        => 'bulk_delete',
			'description' => 'Move multiple posts/pages to trash at once. Restorable.',
			'params'      => array(
				array( 'name' => 'post_ids', 'required' => true, 'desc' => 'Array of IDs to trash' ),
			),
		);
	}

	private static function empty_trash(): array {
		return array(
			'name'        => 'empty_trash',
			'description' => 'Permanently delete trashed posts/pages. Irreversible.',
			'params'      => array(
				array( 'name' => 'post_ids', 'required' => false, 'desc' => 'Specific IDs, or omit for all trash' ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'post|page|product|any (default: any)' ),
			),
		);
	}

	private static function bulk_delete_media(): array {
		return array(
			'name'        => 'bulk_delete_media',
			'description' => 'Permanently delete multiple media attachments at once.',
			'params'      => array(
				array( 'name' => 'attachment_ids', 'required' => true, 'desc' => 'Array of attachment IDs' ),
			),
		);
	}

	private static function bulk_edit(): array {
		return array(
			'name'        => 'bulk_edit',
			'description' => 'Apply the same change to multiple posts/pages. Individually logged and undoable.',
			'params'      => array(
				array( 'name' => 'post_ids', 'required' => true, 'desc' => 'Array of IDs' ),
				array( 'name' => 'changes', 'required' => true, 'desc' => '{status, categories, tags, author}' ),
			),
		);
	}

	private static function find_and_replace(): array {
		return array(
			'name'        => 'find_and_replace',
			'description' => 'Find and replace text across posts/pages. Dry run first, then apply.',
			'params'      => array(
				array( 'name' => 'find', 'required' => true, 'desc' => 'Case-insensitive text to find' ),
				array( 'name' => 'replace', 'required' => true ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'Default: any' ),
				array( 'name' => 'search_in', 'required' => false, 'desc' => 'content|title|both|all (default: content)' ),
				array( 'name' => 'dry_run', 'required' => false, 'desc' => 'true = preview only (default: true)' ),
			),
		);
	}

	// ── Prompt 8 Part E: Export Reports ───────────────────────────────

	private static function export_report(): array {
		return array(
			'name'        => 'export_report',
			'description' => 'Generate an exportable HTML report. Returns download link.',
			'params'      => array(
				array( 'name' => 'report_type', 'required' => true, 'desc' => 'seo|security|site_overview|woocommerce' ),
				array( 'name' => 'include_recommendations', 'required' => false, 'desc' => 'Default: true' ),
			),
		);
	}

	// ── Prompt 9 Part A: Site Profile ─────────────────────────────────

	private static function view_site_profile(): array {
		return array(
			'name'        => 'view_site_profile',
			'description' => 'View auto-generated site profile: industry, style, tone, topics.',
			'params'      => array(),
		);
	}

	private static function refresh_site_profile(): array {
		return array(
			'name'        => 'refresh_site_profile',
			'description' => 'Regenerate the site profile by re-analyzing all content.',
			'params'      => array(),
		);
	}

	// ── Prompt 9 Part C: Log Analysis ─────────────────────────────────

	private static function list_logs(): array {
		return array(
			'name'        => 'list_logs',
			'description' => 'List available log files with sizes and last modified dates.',
			'params'      => array(),
		);
	}

	private static function read_log(): array {
		return array(
			'name'        => 'read_log',
			'description' => 'Read recent log entries with parsed error levels and timestamps.',
			'params'      => array(
				array( 'name' => 'log', 'required' => false, 'desc' => 'debug.log|php|wc/{name}.log|error.log|access.log (default: debug.log)' ),
				array( 'name' => 'lines', 'required' => false, 'desc' => 'Max 200 (default: 50)' ),
				array( 'name' => 'filter', 'required' => false, 'desc' => 'Keyword filter' ),
			),
		);
	}

	private static function analyze_logs(): array {
		return array(
			'name'        => 'analyze_logs',
			'description' => 'Analyze a log file: error counts, frequent errors, problematic plugins, fatals.',
			'params'      => array(
				array( 'name' => 'log', 'required' => false, 'desc' => 'Default: debug.log' ),
			),
		);
	}

	private static function clear_log(): array {
		return array(
			'name'        => 'clear_log',
			'description' => 'Clear/truncate a log file. Only debug.log supported.',
			'params'      => array(
				array( 'name' => 'log', 'required' => true, 'desc' => 'Only "debug.log" supported' ),
			),
		);
	}

	// ── Prompt 11: Content Index ─────────────────────────────────────

	private static function search_knowledge(): array {
		return array(
			'name'        => 'search_knowledge',
			'description' => 'Search the site content index. Faster than read_content for finding information.',
			'params'      => array(
				array( 'name' => 'query', 'required' => true ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'page|post|product (default: all)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => '1-10 (default: 5)' ),
			),
		);
	}

	private static function index_status(): array {
		return array(
			'name'        => 'index_status',
			'description' => 'Check content index status: pages indexed, last sync, total words.',
			'params'      => array(),
		);
	}

	private static function rebuild_index(): array {
		return array(
			'name'        => 'rebuild_index',
			'description' => 'Force a full rebuild of the content index.',
			'params'      => array(),
		);
	}

	// ── Elementor Tools (Prompt 12 Part A) ────────────────────────────

	private static function elementor_read_page(): array {
		return array(
			'name'        => 'elementor_read_page',
			'description' => 'Read Elementor page structure with widget IDs needed for editing. Use widget_type to filter output. Use max_depth to limit nesting depth for large pages.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_type', 'required' => false, 'desc' => 'heading|button|image|text-editor|etc. — show only this widget type' ),
				array( 'name' => 'max_depth', 'required' => false, 'desc' => 'Max nesting depth to return (default: unlimited)' ),
			),
		);
	}

	private static function elementor_find_widgets(): array {
		return array(
			'name'        => 'elementor_find_widgets',
			'description' => 'Find Elementor widgets by type, content, or section. Returns IDs and settings.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_type', 'required' => false, 'desc' => 'heading|button|image|text-editor|etc.' ),
				array( 'name' => 'search', 'required' => false ),
				array( 'name' => 'section_id', 'required' => false ),
			),
		);
	}

	private static function elementor_edit_widget(): array {
		return array(
			'name'        => 'elementor_edit_widget',
			'description' => 'Edit an Elementor widget. Accepts natural language field names. Supports responsive and repeater edits.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_id', 'required' => true, 'desc' => 'From elementor_read_page' ),
				array( 'name' => 'changes', 'required' => false, 'desc' => 'Settings to change (natural language or Elementor keys)' ),
				array( 'name' => 'device', 'required' => false, 'desc' => 'desktop|tablet|mobile (default: desktop)' ),
				array( 'name' => 'field', 'required' => false, 'desc' => 'Repeater field name for item_index edits' ),
				array( 'name' => 'item_index', 'required' => false, 'desc' => '0-based repeater item index' ),
				array( 'name' => 'item_fields', 'required' => false, 'desc' => 'Fields for the repeater item' ),
			),
		);
	}

	private static function elementor_add_widget(): array {
		return array(
			'name'        => 'elementor_add_widget',
			'description' => 'Add a widget to an Elementor page. Types: heading, text-editor, button, image, spacer, divider, video, etc. Prefer sending final settings in this call instead of relying on a later edit.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_type', 'required' => true ),
				array( 'name' => 'settings', 'required' => false, 'desc' => 'Natural language or Elementor keys' ),
				array( 'name' => 'container_id', 'required' => false, 'desc' => 'Target container (default: first found)' ),
				array( 'name' => 'position', 'required' => false, 'desc' => '0-based index, -1 = append' ),
			),
		);
	}

	private static function elementor_add_container(): array {
		return array(
			'name'        => 'elementor_add_container',
			'description' => 'Add a container/section to an Elementor page. Returns widget_target_id for adding widgets.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'layout', 'required' => false, 'desc' => 'boxed|full_width (default: boxed)' ),
				array( 'name' => 'direction', 'required' => false, 'desc' => 'column|row (default: column)' ),
				array( 'name' => 'position', 'required' => false, 'desc' => '-1 = end (default), 0 = top' ),
				array( 'name' => 'parent_id', 'required' => false, 'desc' => 'Nest inside another container' ),
			),
		);
	}

	private static function elementor_list_templates(): array {
		return array(
			'name'        => 'elementor_list_templates',
			'description' => 'List all saved Elementor templates with types.',
			'params'      => array(),
		);
	}

	private static function elementor_create_from_template(): array {
		return array(
			'name'        => 'elementor_create_from_template',
			'description' => 'Create a new page from an Elementor template as draft.',
			'params'      => array(
				array( 'name' => 'template_id', 'required' => true, 'desc' => 'From elementor_list_templates' ),
				array( 'name' => 'title', 'required' => true ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'page|post (default: page)' ),
			),
		);
	}

	private static function elementor_get_styles(): array {
		return array(
			'name'        => 'elementor_get_styles',
			'description' => 'Get Elementor global styles: colors, typography, container width, spacing.',
			'params'      => array(),
		);
	}

	private static function elementor_find_replace(): array {
		return array(
			'name'        => 'elementor_find_replace',
			'description' => 'Find and replace text across all Elementor pages, templates, and headers/footers.',
			'params'      => array(
				array( 'name' => 'find', 'required' => true, 'desc' => 'Case-insensitive text' ),
				array( 'name' => 'replace', 'required' => true ),
				array( 'name' => 'post_id', 'required' => false, 'desc' => 'Limit to one page (default: all)' ),
			),
		);
	}

	private static function elementor_audit_page(): array {
		return array(
			'name'        => 'elementor_audit_page',
			'description' => 'Audit an Elementor page for issues: alt text, buttons, headings, thin content. Scored report.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
			),
		);
	}

	private static function elementor_site_pages(): array {
		return array(
			'name'        => 'elementor_site_pages',
			'description' => 'List all Elementor pages across the site with metadata.',
			'params'      => array(
				array( 'name' => 'post_type', 'required' => false ),
				array( 'name' => 'with_issues', 'required' => false, 'desc' => 'true = include issue counts (slower)' ),
			),
		);
	}

	private static function elementor_global_styles(): array {
		return array(
			'name'        => 'elementor_global_styles',
			'description' => 'Read or update Elementor global design system. Updating affects every page.',
			'params'      => array(
				array(
					'name'     => 'updates',
					'required' => false,
					'desc'     => 'Omit to read. Format: {colors: {primary: "#FF0000"}, typography: {primary: {font_family, font_weight}}, theme_style: {h1_color, body_color, link_color, button_background_color, ...}, layout: {content_width, container_width}}',
				),
			),
		);
	}

	private static function elementor_create_page(): array {
		return array(
			'name'        => 'elementor_create_page',
			'description' => 'Create an Elementor page or post with widgets. Set post_type to "post" for blog posts/articles, "page" for pages. Prefer including a fully populated widgets array so content is written in one pass.',
			'params'      => array(
				array( 'name' => 'title', 'required' => true ),
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'page|post (default: page)' ),
				array( 'name' => 'template', 'required' => false, 'desc' => 'default|canvas|full-width' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'draft|publish (default: draft)' ),
				array( 'name' => 'parent', 'required' => false, 'desc' => 'Parent page ID (pages only)' ),
				array( 'name' => 'widgets', 'required' => false, 'desc' => 'Array of {type, settings}. Types: heading, text-editor, button, image, spacer, divider' ),
			),
		);
	}

	private static function elementor_get_widget_schema(): array {
		return array(
			'name'        => 'elementor_get_widget_schema',
			'description' => 'Discover fields for any Elementor widget type including third-party. Omit type for summary; use mode=detail for the full schema of one widget.',
			'params'      => array(
				array( 'name' => 'widget_type', 'required' => false, 'desc' => 'Omit for all widgets summary' ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|detail (default: summary without widget_type, detail with widget_type)' ),
			),
		);
	}

	// ── Prompt 32B: New Elementor Tools ──────────────────────────────

	private static function elementor_get_breakpoints(): array {
		return array(
			'name'        => 'elementor_get_breakpoints',
			'description' => 'Get active Elementor breakpoints with pixel thresholds and device labels.',
			'params'      => array(),
		);
	}

	private static function elementor_clone_page(): array {
		return array(
			'name'        => 'elementor_clone_page',
			'description' => 'Duplicate an Elementor page with all content and settings. IDs regenerated.',
			'params'      => array(
				array( 'name' => 'source_id', 'required' => true ),
				array( 'name' => 'title', 'required' => false, 'desc' => 'Default: "[Original] (Copy)"' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'draft|publish|private (default: draft)' ),
			),
		);
	}

	private static function elementor_manage_conditions(): array {
		return array(
			'name'        => 'elementor_manage_conditions',
			'description' => 'Read display conditions on an Elementor Pro theme builder template.',
			'params'      => array(
				array( 'name' => 'template_id', 'required' => true, 'desc' => 'From elementor_list_templates' ),
			),
		);
	}

	// ── Prompt 32C: Dynamic Tags, Forms, Visibility, Popups ─────────

	private static function elementor_list_dynamic_tags(): array {
		return array(
			'name'        => 'elementor_list_dynamic_tags',
			'description' => 'List available Elementor dynamic tags grouped by category.',
			'params'      => array(),
		);
	}

	private static function elementor_set_dynamic_tag(): array {
		return array(
			'name'        => 'elementor_set_dynamic_tag',
			'description' => 'Connect a widget field to an Elementor dynamic tag (post title, ACF, date, etc.).',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_id', 'required' => true, 'desc' => 'From elementor_read_page' ),
				array( 'name' => 'field', 'required' => true, 'desc' => 'Widget field to connect' ),
				array( 'name' => 'tag_name', 'required' => true, 'desc' => 'From elementor_list_dynamic_tags' ),
				array( 'name' => 'tag_settings', 'required' => false, 'desc' => 'Tag config object' ),
			),
		);
	}

	private static function elementor_read_form(): array {
		return array(
			'name'        => 'elementor_read_form',
			'description' => 'Read an Elementor Pro Form: fields, email settings, success message, redirect.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_id', 'required' => true, 'desc' => 'Form widget ID from elementor_read_page' ),
			),
		);
	}

	private static function elementor_edit_form_field(): array {
		return array(
			'name'        => 'elementor_edit_form_field',
			'description' => 'Edit a field in an Elementor Pro Form by index from elementor_read_form.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'widget_id', 'required' => true ),
				array( 'name' => 'field_index', 'required' => true, 'desc' => '0-based from elementor_read_form' ),
				array( 'name' => 'changes', 'required' => true, 'desc' => '{label, placeholder, required, type, options, width, id}' ),
			),
		);
	}

	private static function elementor_set_visibility(): array {
		return array(
			'name'        => 'elementor_set_visibility',
			'description' => 'Control Elementor element visibility by device or condition.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'element_id', 'required' => true, 'desc' => 'From elementor_read_page tree' ),
				array( 'name' => 'action', 'required' => false, 'desc' => 'show|hide|show_all (default: show)' ),
				array( 'name' => 'hide_on', 'required' => false, 'desc' => 'Array: ["desktop"], ["tablet"], ["mobile"]' ),
			),
		);
	}

	private static function elementor_list_popups(): array {
		return array(
			'name'        => 'elementor_list_popups',
			'description' => 'List Elementor Pro popups with trigger config and display conditions.',
			'params'      => array(
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20, max: 50' ),
			),
		);
	}

	private static function elementor_edit_popup_trigger(): array {
		return array(
			'name'        => 'elementor_edit_popup_trigger',
			'description' => 'Edit trigger settings on an Elementor Pro popup.',
			'params'      => array(
				array( 'name' => 'popup_id', 'required' => true, 'desc' => 'From elementor_list_popups' ),
				array( 'name' => 'trigger_type', 'required' => true, 'desc' => 'page_load|scroll_depth|click|inactivity|exit_intent' ),
				array( 'name' => 'trigger_settings', 'required' => false, 'desc' => 'Config object: {delay} for page_load, {scroll_depth} for scroll, etc.' ),
			),
		);
	}

	// ── Prompt 19: Composite + WooCommerce Health Tools ──────────────

	private static function store_health(): array {
		return array(
			'name'        => 'store_health',
			'description' => 'WooCommerce store health: orders, revenue, stuck orders, stock issues, health score.',
			'params'      => array(),
		);
	}

	private static function site_brief(): array {
		return array(
			'name'        => 'site_brief',
			'description' => 'Fast site overview: content counts, activity, pending updates, integrations.',
			'params'      => array(),
		);
	}

	private static function page_audit(): array {
		return array(
			'name'        => 'page_audit',
			'description' => 'Comprehensive page audit: content + SEO + Elementor. Returns score and fix suggestions.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
			),
		);
	}

	// ── Discovery Tools (Prompt 13 — On-demand context) ──────────────

	private static function get_site_overview(): array {
		return array(
			'name'        => 'get_site_overview',
			'description' => 'Compact site overview: name, URL, WP version, theme, content counts, plugins.',
			'params'      => array(),
		);
	}

	private static function get_site_map(): array {
		return array(
			'name'        => 'get_site_map',
			'description' => 'Full site structure: all pages, recent posts, homepage config, blog page. Use post_type to limit output to a specific content type.',
			'params'      => array(
				array( 'name' => 'post_type', 'required' => false, 'desc' => 'page|post|product — show only this type (default: all types)' ),
			),
		);
	}

	private static function get_brand_profile(): array {
		return array(
			'name'        => 'get_brand_profile',
			'description' => 'AI-generated site profile: brand voice, content DNA, audience, WC details.',
			'params'      => array(),
		);
	}

	private static function get_available_tools(): array {
		return array(
			'name'        => 'get_available_tools',
			'description' => 'List available tools beyond current set. Use when needing unlisted capabilities.',
			'params'      => array(
				array( 'name' => 'category', 'required' => false, 'desc' => 'media|comments|users|email|health|scheduled|generation|bulk|export|profile|logs|index|plugins|themes|database|woocommerce|elementor' ),
			),
		);
	}

	private static function site_note(): array {
		return array(
			'name'        => 'site_note',
			'description' => 'Record a site observation for future conversations. Use when discovering patterns, preferences, or issues.',
			'params'      => array(
				array( 'name' => 'note', 'required' => true, 'desc' => 'Observation to record (max 200 chars)' ),
				array( 'name' => 'category', 'required' => true, 'desc' => 'content|products|technical|preferences|issues' ),
			),
		);
	}

	// ── Resource Bridge (v5.1.0) ──────────────────────────────────────

	private static function list_resources(): array {
		return array(
			'name'        => 'list_resources',
			'description' => 'List available site resources (design tokens, templates, REST routes, schemas). Each resource has a URI you can pass to read_resource.',
			'params'      => array(
				array( 'name' => 'group', 'required' => false, 'desc' => 'Filter by resource group: site, design, templates, schema, rest, woocommerce, elementor' ),
			),
		);
	}

	private static function read_resource(): array {
		return array(
			'name'        => 'read_resource',
			'description' => 'Read a site resource by URI. Default is summary mode; use detail for structured JSON or raw for the unformatted payload. Use list_resources first to see available URIs.',
			'params'      => array(
				array( 'name' => 'uri', 'required' => true, 'desc' => 'Resource URI from list_resources (e.g., pressark://design/theme-json, pressark://schema/post-types)' ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|detail|raw (default: summary)' ),
			),
		);
	}

	// ── Plugin Management (Prompt 12 Part B) ──────────────────────────

	private static function list_plugins(): array {
		return array(
			'name'        => 'list_plugins',
			'description' => 'List installed WordPress plugins with status, version, and updates.',
			'params'      => array(),
		);
	}

	private static function toggle_plugin(): array {
		return array(
			'name'        => 'toggle_plugin',
			'description' => 'Activate or deactivate a WordPress plugin.',
			'params'      => array(
				array( 'name' => 'plugin_file', 'required' => true, 'desc' => 'From list_plugins' ),
				array( 'name' => 'activate', 'required' => true, 'desc' => 'true|false' ),
			),
		);
	}

	// ── Theme Management (Prompt 12 Part C) ───────────────────────────

	private static function list_themes(): array {
		return array(
			'name'        => 'list_themes',
			'description' => 'List installed themes with active status, version, block theme flag, compatibility.',
			'params'      => array(),
		);
	}

	private static function get_theme_settings(): array {
		return array(
			'name'        => 'get_theme_settings',
			'description' => 'Read current theme design settings. Block themes: global styles. Classic: customizer.',
			'params'      => array(),
		);
	}

	private static function get_customizer_schema(): array {
		return array(
			'name'        => 'get_customizer_schema',
			'description' => 'Discover all Customizer settings with labels, types, choices. Classic themes only.',
			'params'      => array(
				array( 'name' => 'refresh', 'required' => false, 'desc' => 'true = bypass cache' ),
			),
		);
	}

	private static function update_theme_setting(): array {
		return array(
			'name'        => 'update_theme_setting',
			'description' => 'Update a theme customizer setting by key.',
			'params'      => array(
				array( 'name' => 'setting_name', 'required' => true, 'desc' => 'Theme mod key from get_theme_settings' ),
				array( 'name' => 'value', 'required' => true ),
			),
		);
	}

	private static function switch_theme(): array {
		return array(
			'name'        => 'switch_theme',
			'description' => 'Hint: May reset menus/widgets -- warn user first. Switch the active theme with compatibility check.',
			'params'      => array(
				array( 'name' => 'theme_slug', 'required' => true, 'desc' => 'From list_themes' ),
			),
		);
	}

	// ── Database Maintenance (Prompt 12 Part D) ───────────────────────

	private static function database_stats(): array {
		return array(
			'name'        => 'database_stats',
			'description' => 'Database statistics: total size, table sizes, row counts, large tables.',
			'params'      => array(),
		);
	}

	private static function cleanup_database(): array {
		return array(
			'name'        => 'cleanup_database',
			'description' => 'Clean WordPress database: revisions, auto-drafts, spam, transients, orphaned meta.',
			'params'      => array(
				array( 'name' => 'items', 'required' => false, 'desc' => 'Array: revisions|auto_drafts|trashed|spam_comments|expired_transients|orphaned_meta. Omit = all.' ),
			),
		);
	}

	private static function optimize_database(): array {
		return array(
			'name'        => 'optimize_database',
			'description' => 'Run OPTIMIZE TABLE on WordPress database tables.',
			'params'      => array(),
		);
	}

	// ── WooCommerce Variations & Orders (Prompt 12 Part E) ────────────

	private static function list_variations(): array {
		return array(
			'name'        => 'list_variations',
			'description' => 'List all variations for a variable product: attributes, price, stock, status.',
			'params'      => array(
				array( 'name' => 'product_id', 'required' => true ),
			),
		);
	}

	private static function edit_variation(): array {
		return array(
			'name'        => 'edit_variation',
			'description' => 'Edit a product variation: price, stock, status. Plain "price" maps to regular_price.',
			'params'      => array(
				array( 'name' => 'variation_id', 'required' => true ),
				array( 'name' => 'regular_price', 'required' => false, 'desc' => 'Variation regular price; plain "price" also maps here' ),
				array( 'name' => 'sale_price', 'required' => false, 'desc' => 'Empty to remove sale' ),
				array( 'name' => 'stock_quantity', 'required' => false, 'desc' => 'Absolute stock quantity; plain "stock" also maps here' ),
				array( 'name' => 'stock_status', 'required' => false, 'desc' => 'instock|outofstock|onbackorder' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'publish|private' ),
			),
		);
	}

	private static function create_variation(): array {
		return array(
			'name'        => 'create_variation',
			'description' => 'Create a variation on a variable product. Auto-syncs parent price range.',
			'params'      => array(
				array( 'name' => 'product_id', 'required' => true, 'desc' => 'Parent variable product ID' ),
				array( 'name' => 'attributes', 'required' => true, 'desc' => '{attribute: value} pairs' ),
				array( 'name' => 'regular_price', 'required' => false, 'desc' => 'Variation regular price; plain "price" also maps here' ),
				array( 'name' => 'sale_price', 'required' => false ),
				array( 'name' => 'sku', 'required' => false ),
				array( 'name' => 'stock_quantity', 'required' => false ),
				array( 'name' => 'manage_stock', 'required' => false ),
				array( 'name' => 'weight', 'required' => false ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'Default: publish' ),
			),
		);
	}

	private static function bulk_edit_variations(): array {
		return array(
			'name'        => 'bulk_edit_variations',
			'description' => 'Bulk edit all variations of a variable product.',
			'params'      => array(
				array( 'name' => 'product_id', 'required' => true ),
				array( 'name' => 'changes', 'required' => true, 'desc' => '{price_adjust_pct, regular_price, sale_price, clear_sale, sale_from, sale_to, stock_status, manage_stock, status}; plain price maps to regular_price' ),
			),
		);
	}

	private static function list_product_attributes(): array {
		return array(
			'name'        => 'list_product_attributes',
			'description' => 'List global WooCommerce product attributes and their terms.',
			'params'      => array(),
		);
	}

	private static function category_report(): array {
		return array(
			'name'        => 'category_report',
			'description' => 'Sales breakdown by product category: items sold, revenue, orders.',
			'params'      => array(
				array( 'name' => 'days', 'required' => false, 'desc' => 'Default: 30, max: 365' ),
			),
		);
	}

	private static function create_refund(): array {
		return array(
			'name'        => 'create_refund',
			'description' => 'Issue a full or partial refund for a WooCommerce order.',
			'params'      => array(
				array( 'name' => 'order_id', 'required' => true ),
				array( 'name' => 'amount', 'required' => false, 'desc' => 'Omit for full refund' ),
				array( 'name' => 'reason', 'required' => false ),
				array( 'name' => 'process_payment', 'required' => false, 'desc' => 'true = refund via gateway (default: false, WC record only)' ),
				array( 'name' => 'restock', 'required' => false, 'desc' => 'Default: true' ),
			),
		);
	}

	private static function get_top_sellers(): array {
		return array(
			'name'        => 'get_top_sellers',
			'description' => 'Top selling products by revenue or quantity for a period.',
			'params'      => array(
				array( 'name' => 'days', 'required' => false, 'desc' => 'Default: 30' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 10' ),
			),
		);
	}

	private static function create_order(): array {
		return array(
			'name'        => 'create_order',
			'description' => 'Create a manual WooCommerce order. Supports variations, billing/shipping, coupons.',
			'params'      => array(
				array( 'name' => 'products', 'required' => true, 'desc' => 'Array of {product_id, quantity, variation_id?}' ),
				array( 'name' => 'billing', 'required' => false, 'desc' => '{first_name, last_name, email, phone, address_1, city, state, postcode, country}' ),
				array( 'name' => 'shipping', 'required' => false, 'desc' => '{first_name, last_name, address_1, city, state, postcode, country}' ),
				array( 'name' => 'customer_email', 'required' => false ),
				array( 'name' => 'customer_id', 'required' => false ),
				array( 'name' => 'payment_method', 'required' => false, 'desc' => 'Gateway ID' ),
				array( 'name' => 'coupon_code', 'required' => false ),
				array( 'name' => 'customer_note', 'required' => false ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'Default: pending' ),
				array( 'name' => 'note', 'required' => false, 'desc' => 'Admin note' ),
			),
		);
	}

	// ── Prompt 26B: WooCommerce New Capabilities ────────────────────

	private static function trigger_wc_email(): array {
		return array(
			'name'        => 'trigger_wc_email',
			'description' => 'Trigger a WooCommerce email using WC templates. Omit email_type to list all types.',
			'params'      => array(
				array( 'name' => 'email_type', 'required' => false, 'desc' => 'WC email class name. Omit to list all.' ),
				array( 'name' => 'order_id', 'required' => false, 'desc' => 'Required for order-related emails' ),
			),
		);
	}

	private static function get_order_statuses(): array {
		return array(
			'name'        => 'get_order_statuses',
			'description' => 'List all registered WooCommerce order statuses including custom ones.',
			'params'      => array(),
		);
	}

	private static function get_products_on_sale(): array {
		return array(
			'name'        => 'get_products_on_sale',
			'description' => 'Products on sale with discount % and end dates. Sorted by soonest expiring.',
			'params'      => array(
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20, max: 100' ),
			),
		);
	}

	private static function customer_insights(): array {
		return array(
			'name'        => 'customer_insights',
			'description' => 'Customer RFM segmentation: active, cooling, at-risk, churned with revenue data.',
			'params'      => array(),
		);
	}

	// ── Prompt 28B: WooCommerce Revenue, Stock, Webhooks, Events ─────

	private static function revenue_report(): array {
		return array(
			'name'        => 'revenue_report',
			'description' => 'Revenue report with period-over-period comparison via WC Analytics.',
			'params'      => array(
				array( 'name' => 'days', 'required' => false, 'desc' => 'Default: 30, max: 365' ),
				array( 'name' => 'interval', 'required' => false, 'desc' => 'day|week|month (default: day)' ),
				array( 'name' => 'compare', 'required' => false, 'desc' => 'false to skip comparison (default: true)' ),
			),
		);
	}

	private static function stock_report(): array {
		return array(
			'name'        => 'stock_report',
			'description' => 'Inventory overview grouped by stock status with valuation. HPOS-safe.',
			'params'      => array(
				array( 'name' => 'status', 'required' => false, 'desc' => 'outofstock|lowstock|instock|all (default: all)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 30, max: 100' ),
			),
		);
	}

	private static function manage_webhooks(): array {
		return array(
			'name'        => 'manage_webhooks',
			'description' => 'List, pause, activate, disable, or delete WooCommerce webhooks with health status.',
			'params'      => array(
				array( 'name' => 'action', 'required' => false, 'desc' => 'list|pause|activate|disable|delete (default: list)' ),
				array( 'name' => 'webhook_id', 'required' => false, 'desc' => 'Required for pause/activate/disable/delete' ),
				array( 'name' => 'status', 'required' => false, 'desc' => 'active|paused|disabled|all (default: all)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20' ),
			),
		);
	}

	private static function get_wc_alerts(): array {
		return array(
			'name'        => 'get_wc_alerts',
			'description' => 'WooCommerce alerts: low stock, out of stock, failed/cancelled orders.',
			'params'      => array(
				array( 'name' => 'peek', 'required' => false, 'desc' => 'true = view without marking as read' ),
			),
		);
	}

	// ── Diagnostic Tools (Prompt 17) ─────────────────────────────────

	private static function inspect_hooks(): array {
		return array(
			'name'        => 'inspect_hooks',
			'description' => 'Inspect what is hooked to a WordPress action/filter for diagnosing conflicts. Use pattern to filter callbacks and limit to cap results.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'hook_name', 'type' => 'string', 'required' => true ),
				array( 'name' => 'pattern', 'required' => false, 'desc' => 'Filter callbacks by name pattern (substring match)' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max callbacks to return (default: 50)' ),
			),
		);
	}

	private static function measure_page_speed(): array {
		return array(
			'name'        => 'measure_page_speed',
			'description' => 'Measure actual page performance: load time (ms), TTFB, page size (KB), DOM element count, resource count (scripts, styles, images), cache status, and recommendations.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'url', 'type' => 'string', 'required' => false, 'desc' => 'Default: homepage' ),
			),
		);
	}

	private static function check_crawlability(): array {
		return array(
			'name'        => 'check_crawlability',
			'description' => 'Check search engine crawlability: robots.txt, visibility, SSL, sitemap.',
			'type'        => 'read',
			'params'      => array(),
		);
	}

	private static function check_email_delivery(): array {
		return array(
			'name'        => 'check_email_delivery',
			'description' => 'Check email delivery config: SMTP plugins, wp_mail hooks.',
			'type'        => 'read',
			'params'      => array(),
		);
	}

	private static function profile_queries(): array {
		return array(
			'name'        => 'profile_queries',
			'description' => 'Profile DB queries: slowest, duplicates, total stats. Requires SAVEQUERIES.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'url', 'type' => 'string', 'required' => false ),
			),
		);
	}

	private static function get_revision_history(): array {
		return array(
			'name'        => 'get_revision_history',
			'description' => 'Post edit history. Pass compare_to for field-level diff against a revision.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'post_id', 'type' => 'integer', 'required' => true ),
				array( 'name' => 'limit', 'type' => 'integer', 'required' => false, 'desc' => 'Default: 10, max: 20' ),
				array( 'name' => 'compare_to', 'type' => 'integer', 'required' => false, 'desc' => 'Revision ID to diff against current' ),
			),
		);
	}

	// ── Prompt 23B: REST, Cache & Comment Tools ─────────────────────────

	private static function discover_rest_routes(): array {
		return array(
			'name'        => 'discover_rest_routes',
			'description' => 'Hint: Call before call_rest_endpoint. Discover REST API endpoints grouped by namespace.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'namespace', 'required' => false, 'desc' => 'Filter to namespace (default: all summary)' ),
			),
		);
	}

	private static function call_rest_endpoint(): array {
		return array(
			'name'        => 'call_rest_endpoint',
			'description' => 'Hint: POST/PUT/DELETE goes through confirm card. Call any WP REST API endpoint internally.',
			'params'      => array(
				array( 'name' => 'route', 'required' => true, 'desc' => 'REST route from discover_rest_routes' ),
				array( 'name' => 'method', 'required' => false, 'desc' => 'GET|POST|PUT|PATCH|DELETE (default: GET)' ),
				array( 'name' => 'params', 'required' => false, 'desc' => 'Query params for GET, body for POST/PUT/PATCH' ),
			),
		);
	}

	private static function diagnose_cache(): array {
		return array(
			'name'        => 'diagnose_cache',
			'description' => 'Hint: Detects Redis/Memcached with specific recommendation. Diagnose object cache setup.',
			'type'        => 'read',
			'params'      => array(),
		);
	}

	private static function analyze_comment_moderation(): array {
		return array(
			'name'        => 'analyze_comment_moderation',
			'description' => 'Hint: Use to explain why a comment is held. Analyzes hold reasons via check_comment().',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'comment_id', 'required' => true ),
			),
		);
	}

	// ── Prompt 20 Part A: Gutenberg Block Tools ─────────────────────────

	private static function read_blocks(): array {
		return array(
			'name'        => 'read_blocks',
			'description' => 'Hint: Call before editing blocks; edit individual blocks, never rewrite post_content. Read Gutenberg block tree with indexes.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
			),
		);
	}

	private static function edit_block(): array {
		return array(
			'name'        => 'edit_block',
			'description' => 'Hint: Dynamic blocks (is_dynamic=true): only change attributes, not content. Surgical Gutenberg block edit by index.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false ),
				array( 'name' => 'url', 'required' => false ),
				array( 'name' => 'slug', 'required' => false ),
				array( 'name' => 'block_index', 'required' => true, 'desc' => 'From read_blocks. "2.1" for inner blocks.' ),
				array( 'name' => 'updates', 'required' => true, 'desc' => '"content" for text, or attribute names' ),
			),
		);
	}

	private static function insert_block(): array {
		return array(
			'name'        => 'insert_block',
			'description' => 'Insert a Gutenberg block at a specific position without touching existing content.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'block_type', 'required' => true, 'desc' => 'core/paragraph|core/heading|core/image|core/button|core/list|core/separator|core/spacer|core/html' ),
				array( 'name' => 'attrs', 'required' => false, 'desc' => 'Block attributes' ),
				array( 'name' => 'content', 'required' => false, 'desc' => 'Inner HTML' ),
				array( 'name' => 'position', 'required' => false, 'desc' => '-1 = append at end' ),
			),
		);
	}

	// ── Prompt 20 Part B: ACF / Custom Fields ───────────────────────────

	private static function get_custom_fields(): array {
		return array(
			'name'        => 'get_custom_fields',
			'description' => 'Hint: Call to discover field names/types before updating -- never guess ACF keys. Summary mode is default; use detail to include current values.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|detail (default: summary)' ),
			),
		);
	}

	private static function update_custom_field(): array {
		return array(
			'name'        => 'update_custom_field',
			'description' => 'Hint: Use this (not update_meta) when ACF is active. Update a custom field value.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'key', 'required' => true, 'desc' => 'From get_custom_fields' ),
				array( 'name' => 'value', 'required' => true, 'desc' => 'String, number, or array' ),
			),
		);
	}

	// ── Prompt 20 Part D: Forms Detection ────────────────────────────────

	private static function list_forms(): array {
		return array(
			'name'        => 'list_forms',
			'description' => 'Hint: Call first -- detects which form plugin is active. Lists forms with email config and SMTP status.',
			'params'      => array(),
		);
	}

	// ── Prompt 25B: FSE Templates, Design System, Patterns, Multisite ──

	private static function get_templates(): array {
		return array(
			'name'        => 'get_templates',
			'description' => 'Read FSE block templates and parts. Lists summaries by default; use slug with mode=detail or mode=raw for one template.',
			'params'      => array(
				array( 'name' => 'type', 'required' => false, 'desc' => 'wp_template|wp_template_part (default: wp_template)' ),
				array( 'name' => 'slug', 'required' => false, 'desc' => 'Omit to list all' ),
				array( 'name' => 'mode', 'required' => false, 'desc' => 'summary|detail|raw (default: summary for lists, detail with slug)' ),
			),
		);
	}

	private static function edit_template(): array {
		return array(
			'name'        => 'edit_template',
			'description' => 'Edit a block within an FSE template. Creates user override if from theme. Block themes only.',
			'params'      => array(
				array( 'name' => 'slug', 'required' => true ),
				array( 'name' => 'type', 'required' => false, 'desc' => 'wp_template|wp_template_part (default: wp_template)' ),
				array( 'name' => 'block_index', 'required' => true, 'desc' => 'From get_templates, supports "2.1" for inner blocks' ),
				array( 'name' => 'updates', 'required' => true, 'desc' => 'Same format as edit_block' ),
			),
		);
	}

	private static function get_design_system(): array {
		return array(
			'name'        => 'get_design_system',
			'description' => 'Read theme.json design system: colors, typography, spacing, layout. Requires theme.json.',
			'params'      => array(
				array( 'name' => 'section', 'required' => false, 'desc' => 'all|colors|typography|spacing|layout|elements (default: all)' ),
			),
		);
	}

	private static function list_patterns(): array {
		return array(
			'name'        => 'list_patterns',
			'description' => 'List registered block patterns with names, categories, and composition.',
			'params'      => array(
				array( 'name' => 'category', 'required' => false, 'desc' => 'Pattern category slug filter' ),
				array( 'name' => 'search', 'required' => false ),
			),
		);
	}

	private static function insert_pattern(): array {
		return array(
			'name'        => 'insert_pattern',
			'description' => 'Insert a block pattern into a post at a specified position.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'pattern', 'required' => true, 'desc' => 'Pattern name from list_patterns' ),
				array( 'name' => 'position', 'required' => false, 'desc' => '-1 = append at end' ),
			),
		);
	}

	private static function network_overview(): array {
		return array(
			'name'        => 'network_overview',
			'description' => 'Hint: If is_multisite=true, use for subsites, themes, plugins, content stats. Multisite network overview.',
			'params'      => array(
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Default: 20' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Default: 0' ),
			),
		);
	}

	// ── Automation Tools (v4.0.0) ──────────────────────────────────

	private static function list_automations(): array {
		return array(
			'name'        => 'list_automations',
			'description' => 'List scheduled automations: ID, name, status, cadence, next run.',
			'params'      => array(
				array( 'name' => 'status', 'required' => false, 'desc' => 'active|paused|failed (default: all)' ),
			),
		);
	}

	private static function create_automation(): array {
		return array(
			'name'        => 'create_automation',
			'description' => 'Create a scheduled automation that runs a prompt on a recurring schedule. Pro+ required.',
			'params'      => array(
				array( 'name' => 'prompt', 'required' => true ),
				array( 'name' => 'name', 'required' => false ),
				array( 'name' => 'cadence_type', 'required' => false, 'desc' => 'once|hourly|daily|weekly|monthly|yearly (default: once)' ),
				array( 'name' => 'cadence_value', 'required' => false, 'desc' => 'Hours between runs (hourly only)' ),
				array( 'name' => 'first_run_at', 'required' => false, 'desc' => 'UTC, Y-m-d H:i:s (default: now)' ),
				array( 'name' => 'timezone', 'required' => false, 'desc' => 'IANA timezone (default: site timezone)' ),
				array( 'name' => 'approval_policy', 'required' => false, 'desc' => 'editorial|merchandising|full (default: editorial)' ),
			),
		);
	}

	private static function update_automation(): array {
		return array(
			'name'        => 'update_automation',
			'description' => 'Update an existing automation. Only provided fields change.',
			'params'      => array(
				array( 'name' => 'automation_id', 'required' => true ),
				array( 'name' => 'name', 'required' => false ),
				array( 'name' => 'prompt', 'required' => false ),
				array( 'name' => 'cadence_type', 'required' => false ),
				array( 'name' => 'cadence_value', 'required' => false ),
				array( 'name' => 'timezone', 'required' => false ),
			),
		);
	}

	private static function toggle_automation(): array {
		return array(
			'name'        => 'toggle_automation',
			'description' => 'Pause or resume a scheduled automation.',
			'params'      => array(
				array( 'name' => 'automation_id', 'required' => true ),
				array( 'name' => 'action', 'required' => true, 'desc' => 'pause|resume' ),
			),
		);
	}

	private static function run_automation_now(): array {
		return array(
			'name'        => 'run_automation_now',
			'description' => 'Trigger an immediate run of an automation regardless of schedule.',
			'params'      => array(
				array( 'name' => 'automation_id', 'required' => true ),
			),
		);
	}

	private static function delete_automation(): array {
		return array(
			'name'        => 'delete_automation',
			'description' => 'Permanently delete a scheduled automation.',
			'params'      => array(
				array( 'name' => 'automation_id', 'required' => true ),
			),
		);
	}

	private static function inspect_automation(): array {
		return array(
			'name'        => 'inspect_automation',
			'description' => 'Get automation details: prompt, schedule, last run result, and failure history.',
			'params'      => array(
				array( 'name' => 'automation_id', 'required' => true ),
			),
		);
	}
}
