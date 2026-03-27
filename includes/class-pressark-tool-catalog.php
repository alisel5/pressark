<?php
/**
 * PressArk Tool Catalog
 *
 * Tool metadata: capabilities, group membership, keyword matching, and
 * schema generation.
 *
 * v3.4.0: classify() and find_group_for_tool() now delegate to the
 * Operation Registry. The CAPABILITIES constant is kept for backward
 * compatibility but the registry is the source of truth.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Catalog {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Capability Map ──────────────────────────────────────────────────
	// Every tool classified as read | preview | confirm.
	// 'read' = auto-execute, 'preview' = live preview, 'confirm' = confirm card.
	//
	// @deprecated 3.4.1 Use PressArk_Operation_Registry::classify() instead.
	// Kept for backward compatibility with any third-party code.

	private const CAPABILITIES = array(
		// Discovery.
		'get_site_overview'        => 'read',
		'get_site_map'             => 'read',
		'get_brand_profile'        => 'read',
		'get_available_tools'      => 'read',

		// Content — reads.
		'read_content'             => 'read',
		'search_content'           => 'read',
		'list_posts'               => 'read',
		'get_random_content'       => 'read',

		// Content — writes.
		'edit_content'             => 'preview',
		'update_meta'              => 'preview',
		'create_post'              => 'preview',
		'delete_content'           => 'confirm',

		// SEO.
		'analyze_seo'              => 'read',
		'fix_seo'                  => 'preview',
		'check_crawlability'       => 'read',

		// Security.
		'scan_security'            => 'read',
		'fix_security'             => 'confirm',

		// Settings.
		'get_site_settings'        => 'read',
		'update_site_settings'     => 'preview',
		'check_email_delivery'     => 'read',

		// Menus.
		'get_menus'                => 'read',
		'update_menu'              => 'confirm',

		// Media.
		'list_media'               => 'read',
		'get_media'                => 'read',
		'update_media'             => 'confirm',
		'delete_media'             => 'confirm',
		'regenerate_thumbnails'    => 'confirm',

		// Comments.
		'list_comments'            => 'read',
		'moderate_comments'        => 'confirm',
		'reply_comment'            => 'confirm',

		// Taxonomy.
		'list_taxonomies'          => 'read',
		'manage_taxonomy'          => 'confirm',
		'assign_terms'             => 'confirm',

		// Email.
		'get_email_log'            => 'read',

		// Users.
		'list_users'               => 'read',
		'get_user'                 => 'read',
		'update_user'              => 'confirm',

		// Health & Diagnostics.
		'site_health'              => 'read',
		'inspect_hooks'            => 'read',
		'measure_page_speed'       => 'read',
		'profile_queries'          => 'read',
		'get_revision_history'     => 'read',
		'discover_rest_routes'     => 'read',
		'diagnose_cache'           => 'read',
		'analyze_comment_moderation' => 'read',
		'store_health'             => 'read',
		'site_brief'               => 'read',
		'page_audit'               => 'read',

		// Scheduled tasks.
		'list_scheduled_tasks'     => 'read',
		'manage_scheduled_task'    => 'confirm',

		// Content generation.
		'generate_content'         => 'read',
		'rewrite_content'          => 'read',
		'generate_bulk_meta'       => 'read',

		// Bulk operations.
		'bulk_edit'                => 'confirm',
		'find_and_replace'         => 'confirm',

		// Export.
		'export_report'            => 'read',

		// Site profile.
		'view_site_profile'        => 'read',
		'refresh_site_profile'     => 'confirm',

		// Logs.
		'list_logs'                => 'read',
		'read_log'                 => 'read',
		'analyze_logs'             => 'read',
		'clear_log'                => 'confirm',

		// Content index.
		'search_knowledge'         => 'read',
		'index_status'             => 'read',
		'rebuild_index'            => 'confirm',

		// Plugins.
		'list_plugins'             => 'read',
		'toggle_plugin'            => 'confirm',

		// Themes.
		'list_themes'              => 'read',
		'get_theme_settings'       => 'read',
		'get_customizer_schema'    => 'read',
		'update_theme_setting'     => 'preview',
		'switch_theme'             => 'confirm',

		// Database.
		'database_stats'           => 'read',
		'cleanup_database'         => 'confirm',
		'optimize_database'        => 'confirm',

		// WooCommerce — reads.
		'get_product'              => 'read',
		'analyze_store'            => 'read',
		'list_orders'              => 'read',
		'get_order'                => 'read',
		'list_customers'           => 'read',
		'get_customer'             => 'read',
		'get_shipping_zones'       => 'read',
		'get_tax_settings'         => 'read',
		'get_payment_gateways'     => 'read',
		'get_wc_settings'          => 'read',
		'get_wc_emails'            => 'read',
		'get_wc_status'            => 'read',
		'list_reviews'             => 'read',
		'inventory_report'         => 'read',
		'sales_summary'            => 'read',
		'list_variations'          => 'read',
		'get_top_sellers'          => 'read',
		'get_order_statuses'       => 'read',
		'get_products_on_sale'     => 'read',
		'customer_insights'        => 'read',
		'list_product_attributes'  => 'read',
		'category_report'          => 'read',
		'revenue_report'           => 'read',
		'stock_report'             => 'read',
		'get_wc_alerts'            => 'read',

		// WooCommerce — writes.
		'edit_product'             => 'confirm',
		'create_product'           => 'confirm',
		'bulk_edit_products'       => 'confirm',
		'update_order'             => 'confirm',
		'manage_coupon'            => 'confirm',
		'email_customer'           => 'confirm',
		'moderate_review'          => 'confirm',
		'edit_variation'           => 'confirm',
		'create_variation'         => 'confirm',
		'bulk_edit_variations'     => 'confirm',
		'create_refund'            => 'confirm',
		'create_order'             => 'confirm',
		'trigger_wc_email'         => 'confirm',
		'manage_webhooks'          => 'read', // Dynamic — overridden in classify().

		// Elementor — reads.
		'elementor_read_page'      => 'read',
		'elementor_find_widgets'   => 'read',
		'elementor_list_templates' => 'read',
		'elementor_get_styles'     => 'read',
		'elementor_audit_page'     => 'read',
		'elementor_site_pages'     => 'read',
		'elementor_get_widget_schema' => 'read',
		'elementor_get_breakpoints' => 'read',
		'elementor_manage_conditions' => 'read',
		'elementor_list_dynamic_tags' => 'read',
		'elementor_read_form'      => 'read',
		'elementor_list_popups'    => 'read',

		// Elementor — writes.
		'elementor_edit_widget'    => 'preview',
		'elementor_add_widget'     => 'preview',
		'elementor_add_container'  => 'preview',
		'elementor_create_page'    => 'preview',
		'elementor_find_replace'   => 'preview',
		'elementor_set_dynamic_tag' => 'preview',
		'elementor_create_from_template' => 'confirm',
		'elementor_edit_form_field' => 'confirm',
		'elementor_set_visibility' => 'confirm',
		'elementor_global_styles'  => 'confirm',
		'elementor_clone_page'     => 'confirm',
		'elementor_edit_popup_trigger' => 'confirm',

		// Blocks & FSE.
		'read_blocks'              => 'read',
		'edit_block'               => 'preview',
		'insert_block'             => 'preview',

		// Custom fields.
		'get_custom_fields'        => 'read',
		'update_custom_field'      => 'confirm',

		// Forms.
		'list_forms'               => 'read',

		// Templates & design.
		'get_templates'            => 'read',
		'edit_template'            => 'preview',
		'get_design_system'        => 'read',
		'list_patterns'            => 'read',
		'insert_pattern'           => 'preview',

		// Multisite.
		'network_overview'         => 'read',

		// REST endpoint (dynamic — overridden in classify()).
		'call_rest_endpoint'       => 'read',

		// Meta-tools (always loaded).
		'load_tool_group'          => 'read',
		'discover_tools'           => 'read',
		'load_tools'               => 'read',
	);

	// ── Group Keyword Patterns ──────────────────────────────────────────
	// Used to match user messages to tool groups.

	private const GROUP_KEYWORDS = array(
		'seo'           => array( 'seo', 'meta title', 'meta description', 'sitemap', 'search engine', 'ranking', 'crawl', 'canonical', 'robots', 'serp', 'page speed', 'core web vitals' ),
		'security'      => array( 'security', 'vulnerability', 'hack', 'malware', 'ssl', 'firewall', 'secure', 'brute force' ),
		'settings'      => array( 'site settings', 'general settings', 'option', 'configuration', 'blogname', 'tagline', 'timezone', 'permalink' ),
		'menus'         => array( 'menu', 'navigation', 'nav bar', 'nav menu' ),
		'media'         => array( 'image', 'photo', 'thumbnail', 'upload', 'media', 'gallery', 'attachment', 'picture' ),
		'comments'      => array( 'comment', 'reply', 'moderate', 'spam', 'approve comment' ),
		'taxonomy'      => array( 'category', 'tag', 'taxonomy', 'term', 'categories', 'tags' ),
		'email'         => array( 'email', 'mail', 'newsletter', 'smtp', 'send email' ),
		'users'         => array( 'user', 'admin', 'role', 'member', 'profile', 'password', 'subscriber', 'editor', 'author' ),
		'health'        => array( 'health', 'performance', 'speed', 'hook', 'debug', 'diagnostic', 'audit', 'inspect', 'profil' ),
		'scheduled'     => array( 'cron', 'scheduled', 'schedule', 'wp-cron' ),
		'generation'    => array( 'generate', 'write me', 'create content', 'rewrite', 'draft', 'compose', 'write a' ),
		'bulk'          => array( 'bulk', 'find and replace', 'mass edit', 'batch', 'all posts', 'all pages' ),
		'export'        => array( 'export', 'csv', 'download report' ),
		'profile'       => array( 'site profile', 'brand profile', 'voice', 'tone' ),
		'logs'          => array( 'log', 'error log', 'debug log', 'activity log' ),
		'index'         => array( 'content index', 'search index', 'knowledge base', 'rebuild index' ),
		'plugins'       => array( 'plugin', 'extension', 'activate plugin', 'deactivate plugin', 'install plugin' ),
		'themes'        => array( 'theme', 'appearance', 'customizer', 'style', 'layout', 'design system' ),
		'database'      => array( 'database', 'table', 'optimize database', 'cleanup database', 'db' ),
		'woocommerce'   => array( 'product', 'order', 'cart', 'checkout', 'shop', 'store', 'woocommerce', 'woo', 'coupon', 'shipping', 'payment', 'customer', 'inventory', 'variation', 'refund', 'revenue', 'sales' ),
		'elementor'     => array( 'elementor', 'widget', 'section', 'column', 'popup', 'dynamic tag', 'page builder' ),
		'blocks'        => array( 'block', 'gutenberg', 'block editor' ),
		'custom_fields' => array( 'custom field', 'acf', 'meta field', 'post meta', 'advanced custom' ),
		'forms'         => array( 'form', 'contact form', 'gravity form', 'wpforms' ),
		'templates'     => array( 'template', 'full site editing', 'fse', 'template part' ),
		'design'        => array( 'design system', 'global styles', 'style variation' ),
		'patterns'      => array( 'pattern', 'reusable block', 'block pattern' ),
		'multisite'     => array( 'multisite', 'network', 'subsite' ),
		'content'       => array( 'post', 'page', 'content', 'edit', 'create', 'delete', 'revision' ),
	);

	// Groups always included in every request.
	private const ALWAYS_GROUPS = array( 'discovery', 'core' );

	// ── Public API ──────────────────────────────────────────────────────

	/**
	 * Classify a tool's capability (read, preview, or confirm).
	 * Handles dynamic tools (call_rest_endpoint, manage_webhooks).
	 *
	 * v3.4.0: Delegates to Operation Registry as primary source.
	 * v3.4.1: Registry is sole source of truth. CAPABILITIES kept as fallback
	 *         for any third-party tools not in the registry.
	 */
	public function classify( string $tool_name, array $args = array() ): string {
		// v3.4.0: Registry is the primary source of truth.
		if ( PressArk_Operation_Registry::exists( $tool_name ) ) {
			return PressArk_Operation_Registry::classify( $tool_name, $args );
		}

		// Fallback: static CAPABILITIES map (backward compat for any unregistered tools).
		if ( isset( self::CAPABILITIES[ $tool_name ] ) ) {
			return self::CAPABILITIES[ $tool_name ];
		}

		// Fallback: check usage tracker for write detection.
		$tracker = new PressArk_Usage_Tracker();
		if ( $tracker->is_write_action( $tool_name ) ) {
			return 'confirm';
		}

		return 'read';
	}

	/**
	 * Match user message to tool groups via keyword scanning.
	 * Scans message text + last 2 conversation turns for keywords.
	 * Always includes ALWAYS_GROUPS (discovery, core).
	 *
	 * @deprecated 2.3.1 No longer used for tool loading. Use discover_tools meta-tool instead.
	 *             Kept for backward compatibility and tests.
	 *
	 * @param string $message      The user's current message.
	 * @param array  $conversation Previous conversation messages.
	 * @return string[] Matched group names.
	 */
	public function match_groups( string $message, array $conversation = array() ): array {
		$matched = self::ALWAYS_GROUPS;

		// Build searchable text: current message + last 2 user messages from history.
		$search_text = strtolower( $message );
		$history_count = 0;
		for ( $i = count( $conversation ) - 1; $i >= 0 && $history_count < 2; $i-- ) {
			if ( ( $conversation[ $i ]['role'] ?? '' ) === 'user' ) {
				$search_text .= ' ' . strtolower( $conversation[ $i ]['content'] ?? '' );
				$history_count++;
			}
		}

		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );

		foreach ( self::GROUP_KEYWORDS as $group => $keywords ) {
			// Skip conditional groups if plugin not active.
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}

			foreach ( $keywords as $keyword ) {
				if ( str_contains( $search_text, $keyword ) ) {
					$matched[] = $group;
					break;
				}
			}
		}

		return array_values( array_unique( $matched ) );
	}

	/**
	 * Get tool names belonging to given groups.
	 * Delegates to PressArk_Operation_Registry.
	 *
	 * @param string[] $groups Group names.
	 * @return string[] Unique tool names.
	 */
	public function get_tool_names_for_groups( array $groups ): array {
		$tool_names    = array();
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );

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

		// Always include the meta-tools (v2.3.1: discover_tools + load_tools).
		$tool_names[] = 'discover_tools';
		$tool_names[] = 'load_tools';

		return array_values( array_unique( $tool_names ) );
	}

	/**
	 * Get OpenAI function schemas for specific tool names.
	 * Includes the load_tool_group meta-tool.
	 * Sorted alphabetically for cache stability.
	 *
	 * @param string[] $tool_names Tool names to include.
	 * @return array OpenAI-compatible function schemas.
	 */
	public function get_schemas( array $tool_names ): array {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$all_tools     = PressArk_Tools::get_all( $has_woo, $has_elementor );
		$tool_names_set = array_flip( $this->normalize_string_list( $tool_names ) );

		$schemas = array();
		foreach ( $all_tools as $tool ) {
			if ( isset( $tool_names_set[ $tool['name'] ] ) ) {
				$schemas[] = PressArk_Tools::tool_to_schema( $tool );
			}
		}

		// Add meta-tool schemas (v2.3.1: discover_tools + load_tools).
		if ( isset( $tool_names_set['discover_tools'] ) || isset( $tool_names_set['load_tools'] ) ) {
			foreach ( $this->get_meta_tools_schemas() as $meta_schema ) {
				$schemas[] = $meta_schema;
			}
		}

		// Backward compat: old load_tool_group meta-tool.
		if ( isset( $tool_names_set['load_tool_group'] ) && ! isset( $tool_names_set['load_tools'] ) ) {
			$schemas[] = $this->get_meta_tool_schema();
		}

		// Sort alphabetically for cache stability.
		usort( $schemas, function ( $a, $b ) {
			return strcmp( $a['function']['name'] ?? '', $b['function']['name'] ?? '' );
		} );

		return $schemas;
	}

	/**
	 * Build compact text descriptors for tools NOT in the loaded set.
	 * Format: "- tool_name (group): description" (~10 tokens each).
	 *
	 * v3.4.1: Iterates the Operation Registry instead of PressArk_Tools.
	 * Uses $op->description with fallback to PressArk_Tools during transition.
	 *
	 * @deprecated 3.8.0 Use get_capability_map() instead. Descriptor dumping
	 *             wastes ~800 tokens per round on a full site. Kept for
	 *             backward compatibility with third-party code.
	 *
	 * @param string[] $loaded_names Tool names already in schemas.
	 * @return string Compact text for system prompt, or empty if all tools loaded.
	 */
	public function get_unlisted_descriptors( array $loaded_names ): string {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$loaded_set    = array_flip( $this->normalize_string_list( $loaded_names ) );

		// Lazy-load PressArk_Tools descriptions only if registry descriptions are missing.
		$tools_fallback = null;

		$lines = array();
		foreach ( PressArk_Operation_Registry::all() as $op ) {
			// Skip already-loaded tools and meta-tools.
			if ( isset( $loaded_set[ $op->name ] ) || $op->is_meta() ) {
				continue;
			}
			// Skip conditional plugins if not active.
			if ( 'woocommerce' === $op->requires && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $op->requires && ! $has_elementor ) {
				continue;
			}

			$desc = $op->description;
			if ( empty( $desc ) ) {
				// Fallback: pull from PressArk_Tools during transition.
				if ( null === $tools_fallback ) {
					$tools_fallback = array();
					foreach ( PressArk_Tools::get_all( $has_woo, $has_elementor ) as $t ) {
						$tools_fallback[ $t['name'] ] = $t['description'] ?? '';
					}
				}
				$desc = $tools_fallback[ $op->name ] ?? '';
			}

			$lines[] = '- ' . $op->name . ' (' . $op->group . '): ' . $desc;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$header = sprintf(
			"OTHER AVAILABLE TOOLS (%d not currently loaded — use discover_tools to search, then load_tools to activate):\n",
			count( $lines )
		);

		$available_groups = PressArk_Operation_Registry::group_names();
		$footer = "\nTool groups: " . implode( ', ', $available_groups ) . "\nUse discover_tools(query) to search, then load_tools(group/tools) to load.";

		return $header . implode( "\n", $lines ) . $footer;
	}

	/**
	 * Build an enriched capability map for unloaded tool groups (~100 tokens).
	 *
	 * Replaces get_unlisted_descriptors() in the hot prompt path. Each line
	 * includes representative tool names and a brief description so the AI
	 * can route tool discovery faster without needing a discover_tools
	 * round-trip (~7K tokens saved per skipped round-trip).
	 *
	 * @since 4.3.0 Enriched format with tool names and descriptions (was count-based).
	 * @since 3.8.0
	 *
	 * @param string[] $loaded_groups Group names already loaded.
	 * @return string Compact text for system prompt, or empty if all groups loaded.
	 */
	public function get_capability_map( array $loaded_groups ): string {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$loaded_set    = array_flip( $this->normalize_string_list( $loaded_groups ) );

		// v4.3.0: Enriched labels with representative tool names and actions.
		// Adds ~50 tokens but eliminates a discover_tools round-trip (~7K tokens).
		$group_labels = array(
			'seo'           => 'analyze_seo, fix_seo, check_crawlability → SEO audits, meta fixes, robots.txt',
			'security'      => 'scan_security, fix_security → vulnerability scan, auto-fix exposed files/XML-RPC',
			'settings'      => 'get_site_settings, update_site_settings → read/write WP options, SMTP, permalinks',
			'menus'         => 'get_menus, update_menu → navigation read/write',
			'media'         => 'list_media, get_media, update_media, delete_media → library CRUD, thumbnails',
			'comments'      => 'list_comments, moderate_comments, reply_comment → moderation, bulk approve/spam',
			'taxonomy'      => 'list_taxonomies, manage_taxonomy, assign_terms → categories, tags, custom terms',
			'email'         => 'get_email_log → delivery history',
			'users'         => 'list_users, get_user, update_user → user management, roles',
			'health'        => 'site_health, inspect_hooks, measure_page_speed → diagnostics, performance',
			'scheduled'     => 'list_scheduled_tasks, manage_scheduled_task → WP-Cron management',
			'generation'    => 'generate_content, rewrite_content, generate_bulk_meta → AI content creation',
			'bulk'          => 'bulk_edit, find_and_replace → mass post changes, search-replace',
			'export'        => 'export_report → downloadable HTML/CSV reports',
			'profile'       => 'view_site_profile, refresh_site_profile → brand voice, tone analysis',
			'logs'          => 'list_logs, read_log, analyze_logs → debug.log, error analysis',
			'index'         => 'search_knowledge, index_status, rebuild_index → content search index',
			'plugins'       => 'list_plugins, toggle_plugin → activate/deactivate plugins',
			'themes'        => 'list_themes, get_theme_settings, switch_theme → theme management, customizer',
			'database'      => 'database_stats, cleanup_database, optimize_database → DB maintenance',
			'woocommerce'   => 'edit_product, list_orders, analyze_store, inventory_report → full store management',
			'elementor'     => 'elementor_read_page, elementor_edit_widget, elementor_add_widget → page builder',
			'blocks'        => 'read_blocks, edit_block, insert_block → Gutenberg block editing',
			'custom_fields' => 'get_custom_fields, update_custom_field → ACF / post meta',
			'forms'         => 'list_forms → contact form detection (CF7, WPForms, Gravity, Fluent)',
			'templates'     => 'get_templates, edit_template → FSE template hierarchy',
			'design'        => 'get_design_system → theme.json colors, typography, spacing',
			'patterns'      => 'list_patterns, insert_pattern → block patterns, reusable blocks',
			'multisite'     => 'network_overview → subsites, network plugins/themes',
		);

		$lines = array();
		foreach ( $group_labels as $group => $label ) {
			if ( isset( $loaded_set[ $group ] ) ) {
				continue;
			}
			// Skip conditional groups if plugin not active.
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
				continue;
			}

			$lines[] = "- {$group}: {$label}";
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "TOOL GROUPS (discover_tools to search, load_tools to activate):\n"
			. implode( "\n", $lines );
	}

	/**
	 * Build a shorter capability map for follow-up rounds.
	 *
	 * Keeps group intent visible without repeating representative tool lists
	 * on every round.
	 *
	 * @param string[] $loaded_groups Group names already loaded.
	 * @return string Compact text for the system prompt, or empty if all groups loaded.
	 */
	public function get_compact_capability_map( array $loaded_groups ): string {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$loaded_set    = array_flip( $this->normalize_string_list( $loaded_groups ) );
		$group_labels  = array(
			'seo'           => 'meta, crawl, SEO fixes',
			'security'      => 'vulnerability scans and fixes',
			'settings'      => 'site and plugin settings',
			'menus'         => 'navigation management',
			'media'         => 'media library updates',
			'comments'      => 'moderation and replies',
			'taxonomy'      => 'categories, tags, terms',
			'email'         => 'email logs',
			'users'         => 'user management',
			'health'        => 'site health, crawlability, speed',
			'scheduled'     => 'WP-Cron tasks',
			'generation'    => 'AI content generation',
			'bulk'          => 'bulk content changes',
			'export'        => 'report exports',
			'profile'       => 'brand profile and tone',
			'logs'          => 'debug and error logs',
			'index'         => 'knowledge index search',
			'plugins'       => 'plugin activation',
			'themes'        => 'theme management',
			'database'      => 'database cleanup',
			'woocommerce'   => 'store management',
			'elementor'     => 'Elementor editing',
			'blocks'        => 'block editing',
			'custom_fields' => 'ACF and post meta',
			'forms'         => 'form discovery',
			'templates'     => 'template editing',
			'design'        => 'theme design tokens',
			'patterns'      => 'pattern insertion',
			'multisite'     => 'network administration',
		);
		$lines         = array();

		foreach ( $group_labels as $group => $label ) {
			if ( isset( $loaded_set[ $group ] ) ) {
				continue;
			}
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
				continue;
			}

			$lines[] = "- {$group}: {$label}";
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "OTHER GROUPS (use discover_tools or load_tools when needed):\n"
			. implode( "\n", $lines );
	}

	/**
	 * Get the load_tool_group meta-tool as an OpenAI function schema.
	 */
	public function get_meta_tool_schema(): array {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'load_tool_group',
				'description' => 'Load additional tool schemas by group name. Use when you need a tool from a group listed in the capability map. Available groups: ' . implode( ', ', PressArk_Operation_Registry::group_names() ),
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'group' => array(
							'type'        => 'string',
							'description' => 'The tool group name to load (e.g., "seo", "woocommerce", "media").',
						),
					),
					'required' => array( 'group' ),
				),
			),
		);
	}

	/**
	 * Search tool definitions for a natural-language query.
	 * Local text search — no AI call. Searches tool names, descriptions,
	 * and GROUP_KEYWORDS. Returns top 20 matches.
	 *
	 * @since 2.3.1
	 *
	 * @param string   $query        Natural language search query.
	 * @param string[] $loaded_names Tool names already loaded in current session.
	 * @return array[] Array of matches: { name, description, group, loaded }.
	 */
	public function discover( string $query, array $loaded_names = array() ): array {
		$query_lower = strtolower( trim( $query ) );
		if ( empty( $query_lower ) ) {
			return array();
		}

		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$all_tools     = PressArk_Tools::get_all( $has_woo, $has_elementor );
		$loaded_set    = array_flip( $this->normalize_string_list( $loaded_names ) );
		$tool_group_map = $this->build_tool_group_map();

		// Tokenize query into words for multi-word matching.
		$query_words = preg_split( '/\s+/', $query_lower );

		$scored = array();
		$seen   = array();

		foreach ( $all_tools as $tool ) {
			$name       = $tool['name'];
			$desc       = strtolower( $tool['description'] ?? '' );
			$name_lower = strtolower( str_replace( '_', ' ', $name ) );
			$group      = $tool_group_map[ $name ] ?? '';

			$score = 0;

			// Exact substring match on name (highest priority).
			if ( str_contains( $name_lower, $query_lower ) ) {
				$score += 100;
			}

			// Word-level matching against name and description.
			foreach ( $query_words as $word ) {
				if ( strlen( $word ) < 2 ) {
					continue;
				}
				if ( str_contains( $name_lower, $word ) ) {
					$score += 20;
				}
				if ( str_contains( $desc, $word ) ) {
					$score += 10;
				}
			}

			// GROUP_KEYWORDS match for the tool's own group.
			if ( $group && isset( self::GROUP_KEYWORDS[ $group ] ) ) {
				foreach ( self::GROUP_KEYWORDS[ $group ] as $keyword ) {
					if ( str_contains( $query_lower, $keyword ) ) {
						$score += 30;
						break;
					}
				}
			}

			if ( $score > 0 ) {
				$seen[ $name ] = true;
				$scored[]      = array(
					'name'        => $name,
					'description' => $tool['description'] ?? '',
					'group'       => $group,
					'loaded'      => isset( $loaded_set[ $name ] ),
					'score'       => $score,
				);
			}
		}

		// Also check GROUP_KEYWORDS for groups whose tools didn't match directly.
		foreach ( self::GROUP_KEYWORDS as $group => $keywords ) {
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}

			$group_matched = false;
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $query_lower, $keyword ) ) {
					$group_matched = true;
					break;
				}
			}

			if ( ! $group_matched ) {
				continue;
			}

			// Add all tools from this group that weren't already scored.
			$group_tools = PressArk_Operation_Registry::tool_names_for_group( $group );
			foreach ( $group_tools as $tool_name ) {
				if ( isset( $seen[ $tool_name ] ) ) {
					continue;
				}
				$seen[ $tool_name ] = true;

				// Find description from all_tools.
				$desc = '';
				foreach ( $all_tools as $t ) {
					if ( $t['name'] === $tool_name ) {
						$desc = $t['description'] ?? '';
						break;
					}
				}

				$scored[] = array(
					'name'        => $tool_name,
					'description' => $desc,
					'group'       => $group,
					'loaded'      => isset( $loaded_set[ $tool_name ] ),
					'score'       => 25,
				);
			}
		}

		// Sort by score descending.
		usort( $scored, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );

		// Top 20, strip score from output.
		$results = array();
		foreach ( array_slice( $scored, 0, 20 ) as $item ) {
			unset( $item['score'] );
			$results[] = $item;
		}

		return $results;
	}

	/**
	 * Build a reverse map: tool_name → first group it belongs to.
	 *
	 * @since 2.3.1
	 * @return array<string, string>
	 */
	private function build_tool_group_map(): array {
		$map = array();
		foreach ( PressArk_Operation_Registry::all() as $op ) {
			if ( ! isset( $map[ $op->name ] ) ) {
				$map[ $op->name ] = $op->group;
			}
		}
		return $map;
	}

	/**
	 * Find which group a specific tool belongs to.
	 *
	 * v3.4.0: Delegates to Operation Registry (sole source of truth since v3.4.1).
	 *
	 * @since 2.3.1
	 *
	 * @param string $tool_name The tool name.
	 * @return string Group name, or empty string if not found.
	 */
	public function find_group_for_tool( string $tool_name ): string {
		// v3.4.0: Registry is the primary source.
		$group = PressArk_Operation_Registry::get_group( $tool_name );
		if ( '' !== $group ) {
			return $group;
		}

		return '';
	}

	/**
	 * Return valid group names that are not currently loaded.
	 *
	 * @param string[] $loaded_groups Already-loaded group names.
	 * @param int      $limit         Optional cap for the returned list.
	 * @return string[]
	 */
	public function get_remaining_group_names( array $loaded_groups, int $limit = 0 ): array {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$loaded_set    = array_flip( $this->normalize_string_list( $loaded_groups ) );
		$groups        = array();

		foreach ( PressArk_Operation_Registry::group_names() as $group ) {
			if ( isset( $loaded_set[ $group ] ) ) {
				continue;
			}
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
				continue;
			}

			$groups[] = $group;
		}

		return $limit > 0 ? array_slice( $groups, 0, $limit ) : $groups;
	}

	/**
	 * Get the discover_tools + load_tools meta-tool schemas.
	 * Replaces the single load_tool_group meta-tool for v2.3.1.
	 *
	 * @since 2.3.1
	 * @return array[] Two OpenAI function schemas.
	 */
	public function get_meta_tools_schemas(): array {
		return array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'discover_tools',
					'description' => 'Search for available tools by describing what you need. Returns matching tools with names, descriptions, groups, and whether they are already loaded. Use this before load_tools to find the right tools.',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'query' => array(
								'type'        => 'string',
								'description' => 'Natural language description of what tools you need (e.g., "SEO analysis", "edit product prices", "manage media images").',
							),
						),
						'required' => array( 'query' ),
					),
				),
			),
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'load_tools',
					'description' => 'Load additional tool schemas into your active set. Use discover_tools first to find which tools or groups you need, then load them here. Tools persist across conversation turns. Provide either group or tools (or both).',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'group' => array(
								'type'        => 'string',
								'description' => 'Load all tools from a group. Available groups: ' . implode( ', ', PressArk_Operation_Registry::group_names() ),
							),
							'tools' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Load specific tools by name (e.g., ["analyze_seo", "fix_seo"]).',
							),
						),
						'required' => array(),
					),
				),
			),
		);
	}

	/**
	 * Get the list of always-included group names.
	 */
	public function get_always_groups(): array {
		return self::ALWAYS_GROUPS;
	}

	/**
	 * Normalize lists before array_flip() to avoid warnings from non-string values.
	 *
	 * @param array $values Arbitrary values.
	 * @return string[]
	 */
	private function normalize_string_list( array $values ): array {
		$normalized = array();

		foreach ( $values as $value ) {
			if ( is_string( $value ) || is_int( $value ) ) {
				$text = sanitize_key( (string) $value );
				if ( '' !== $text ) {
					$normalized[] = $text;
				}
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
