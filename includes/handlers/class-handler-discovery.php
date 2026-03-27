<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery and meta-tool handler.
 *
 * Owns site-context and tool-discovery actions so the engine stays focused on
 * orchestration rather than becoming a second handler domain.
 *
 * @since 4.1.2
 */
class PressArk_Handler_Discovery extends PressArk_Handler_Base {

	/** @var array<string, string[]> */
	private const AVAILABLE_TOOL_CATEGORIES = array(
		'content'    => array( 'read_content', 'search_content', 'edit_content', 'update_meta', 'create_post', 'delete_content', 'list_posts' ),
		'seo'        => array( 'analyze_seo', 'fix_seo' ),
		'security'   => array( 'scan_security', 'fix_security' ),
		'settings'   => array( 'get_site_settings', 'update_site_settings' ),
		'menus'      => array( 'get_menus', 'update_menu' ),
		'media'      => array( 'list_media', 'get_media', 'update_media', 'delete_media', 'regenerate_thumbnails' ),
		'comments'   => array( 'list_comments', 'moderate_comments', 'reply_comment' ),
		'taxonomy'   => array( 'list_taxonomies', 'manage_taxonomy', 'assign_terms' ),
		'email'      => array( 'get_email_log' ),
		'users'      => array( 'list_users', 'get_user', 'update_user' ),
		'health'     => array( 'site_health' ),
		'scheduled'  => array( 'list_scheduled_tasks', 'manage_scheduled_task' ),
		'generation' => array( 'generate_content', 'rewrite_content', 'generate_bulk_meta' ),
		'bulk'       => array( 'bulk_edit', 'find_and_replace' ),
		'export'     => array( 'export_report' ),
		'profile'    => array( 'view_site_profile', 'refresh_site_profile' ),
		'logs'       => array( 'list_logs', 'read_log', 'analyze_logs', 'clear_log' ),
		'index'      => array( 'search_knowledge', 'index_status', 'rebuild_index' ),
		'plugins'    => array( 'list_plugins', 'toggle_plugin' ),
		'themes'     => array( 'list_themes', 'get_theme_settings', 'get_customizer_schema', 'update_theme_setting', 'switch_theme' ),
		'database'   => array( 'database_stats', 'cleanup_database', 'optimize_database' ),
		'templates'  => array( 'get_templates', 'edit_template' ),
		'design'     => array( 'get_design_system' ),
		'patterns'   => array( 'list_patterns', 'insert_pattern' ),
		'multisite'  => array( 'network_overview' ),
	);

	/**
	 * On-demand site overview: name, URL, WP version, theme, counts, plugins, user.
	 */
	public function get_site_overview( array $params ): array {
		$page_count    = wp_count_posts( 'page' );
		$post_count    = wp_count_posts( 'post' );
		$comment_count = wp_count_comments();
		$front_id      = (int) get_option( 'page_on_front' );
		$theme         = wp_get_theme();
		$user          = wp_get_current_user();

		$lines = array();
		$lines[] = sprintf( __( 'Site: %s (%s)', 'pressark' ), get_bloginfo( 'name' ), home_url() );
		$lines[] = sprintf( __( 'Tagline: %s', 'pressark' ), get_bloginfo( 'description' ) );
		$lines[] = sprintf( __( 'WordPress %1$s | PHP %2$s', 'pressark' ), get_bloginfo( 'version' ), phpversion() );
		$lines[] = sprintf( __( 'Theme: %1$s v%2$s', 'pressark' ), $theme->get( 'Name' ), $theme->get( 'Version' ) );
		$lines[] = sprintf( __( 'Pages: %1$d | Posts: %2$d | Comments: %3$d', 'pressark' ), $page_count->publish ?? 0, $post_count->publish ?? 0, $comment_count->total_comments ?? 0 );
		$lines[] = sprintf( __( 'Front page ID: %1$d | Timezone: %2$s', 'pressark' ), $front_id, wp_timezone_string() );

		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_names   = array();
		foreach ( $active_plugins as $plugin_file ) {
			$data           = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$plugin_names[] = $data['Name'] ?? basename( $plugin_file, '.php' );
		}
		$lines[] = sprintf( __( 'Active plugins (%1$d): %2$s', 'pressark' ), count( $plugin_names ), implode( ', ', $plugin_names ) );
		$lines[] = sprintf( __( 'User: %1$s (%2$s)', 'pressark' ), $user->user_login, implode( ', ', $user->roles ) );

		$flags = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$product_count = wp_count_posts( 'product' );
			$flags[]       = sprintf( __( 'WooCommerce(%d products)', 'pressark' ), $product_count->publish ?? 0 );
		}
		if ( PressArk_Elementor::is_active() ) {
			$flags[] = 'Elementor';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$flags[] = 'Yoast SEO';
		} elseif ( class_exists( 'RankMath' ) ) {
			$flags[] = 'RankMath';
		}
		if ( ! empty( $flags ) ) {
			$lines[] = __( 'Integrations: ', 'pressark' ) . implode( ', ', $flags );
		}

		// Custom post types (beyond built-in post/page/attachment).
		$public_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$cpt_list = array();
		foreach ( $public_types as $pt ) {
			// Skip WooCommerce products if WC section handles them.
			if ( in_array( $pt->name, array( 'product' ), true ) && class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$count      = wp_count_posts( $pt->name );
			$cpt_list[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'count'        => isset( $count->publish ) ? (int) $count->publish : 0,
				'hierarchical' => $pt->hierarchical,
				'has_archive'  => (bool) $pt->has_archive,
				'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
			);
		}
		if ( ! empty( $cpt_list ) ) {
			$cpt_names = array_map( function ( $cpt ) {
				return $cpt['label'] . '(' . $cpt['count'] . ')';
			}, $cpt_list );
			$lines[] = __( 'Custom post types: ', 'pressark' ) . implode( ', ', $cpt_names );
		}

		// Custom taxonomies.
		$public_taxos = get_taxonomies( array( 'public' => true, '_builtin' => false ), 'objects' );
		$taxo_list = array();
		foreach ( $public_taxos as $tax ) {
			if ( in_array( $tax->name, array( 'product_cat', 'product_tag' ), true ) && class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$taxo_list[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
				'object_types' => $tax->object_type,
				'count'        => (int) wp_count_terms( array( 'taxonomy' => $tax->name ) ),
			);
		}
		if ( ! empty( $taxo_list ) ) {
			$taxo_names = array_map( function ( $t ) {
				return $t['label'] . '(' . $t['count'] . ')';
			}, $taxo_list );
			$lines[] = __( 'Custom taxonomies: ', 'pressark' ) . implode( ', ', $taxo_names );
		}

		// Theme capabilities.
		$theme_features = array(
			'title-tag', 'custom-logo', 'post-thumbnails', 'custom-header',
			'custom-background', 'menus', 'widgets', 'editor-styles',
			'responsive-embeds', 'align-wide', 'wp-block-styles',
		);
		$supported = array();
		foreach ( $theme_features as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supported[] = $feature;
			}
		}
		if ( ! empty( $supported ) ) {
			$lines[] = __( 'Theme supports: ', 'pressark' ) . implode( ', ', $supported );
		}

		return array(
			'success' => true,
			'message' => implode( "\n", $lines ),
			'data'    => array(
				'name'              => get_bloginfo( 'name' ),
				'url'               => home_url(),
				'wp_version'        => get_bloginfo( 'version' ),
				'theme'             => $theme->get( 'Name' ),
				'pages'             => $page_count->publish ?? 0,
				'posts'             => $post_count->publish ?? 0,
				'plugins'           => count( $active_plugins ),
				'custom_post_types' => $cpt_list,
				'custom_taxonomies' => $taxo_list,
				'theme_supports'    => $supported,
			),
		);
	}

	/**
	 * On-demand site map: full page listing + recent posts.
	 */
	public function get_site_map( array $params ): array {
		$show_on_front = get_option( 'show_on_front' );
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$filter_type   = sanitize_text_field( $params['post_type'] ?? '' );

		$lines = array();

		if ( 'page' === $show_on_front && $front_page_id ) {
			$front_page = get_post( $front_page_id );
			if ( $front_page ) {
				$lines[] = sprintf( __( 'Homepage: "%1$s" (ID:%2$d) [static page]', 'pressark' ), $front_page->post_title, $front_page_id );
			}
		} else {
			$lines[] = __( 'Homepage: Blog listing (no static page)', 'pressark' );
		}

		if ( $blog_page_id && $blog_page_id !== $front_page_id ) {
			$blog_page = get_post( $blog_page_id );
			if ( $blog_page ) {
				$lines[] = sprintf( __( 'Blog page: "%1$s" (ID:%2$d)', 'pressark' ), $blog_page->post_title, $blog_page_id );
			}
		}

		if ( ! $filter_type || 'page' === $filter_type ) {
			$pages = get_posts( array(
				'post_type'              => 'page',
				'posts_per_page'         => 50,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( ! empty( $pages ) ) {
				$lines[] = '';
				$lines[] = sprintf( __( 'PAGES (%d):', 'pressark' ), count( $pages ) );
				foreach ( $pages as $page ) {
					$flags = '';
					if ( $page->ID === $front_page_id && 'page' === $show_on_front ) {
						$flags .= ' ★HOME';
					}
					if ( $page->ID === $blog_page_id ) {
						$flags .= ' ★BLOG';
					}
					$lines[] = sprintf( '- ID:%d "%s" /%s [%s]%s', $page->ID, $page->post_title, $page->post_name, $page->post_status, $flags );
				}
			}
		}

		if ( ! $filter_type || 'post' === $filter_type ) {
			$posts = get_posts( array(
				'post_type'              => 'post',
				'posts_per_page'         => 15,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( ! empty( $posts ) ) {
				$total_posts = wp_count_posts( 'post' );
				$lines[]     = '';
				$lines[]     = sprintf( __( 'RECENT POSTS (showing %1$d of %2$d):', 'pressark' ), count( $posts ), $total_posts->publish ?? 0 );
				foreach ( $posts as $post ) {
					$lines[] = sprintf( '- ID:%d "%s" [%s] %s', $post->ID, $post->post_title, $post->post_status, wp_date( 'Y-m-d', strtotime( $post->post_date ) ) );
				}
			}
		}

		if ( ( ! $filter_type || 'product' === $filter_type ) && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products( array( 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
			if ( ! empty( $products ) ) {
				$product_count = wp_count_posts( 'product' );
				$currency_sym  = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
				$lines[]       = '';
				$lines[]       = sprintf( __( 'PRODUCTS (showing %1$d of %2$d):', 'pressark' ), count( $products ), $product_count->publish ?? 0 );
				foreach ( $products as $product ) {
					$lines[] = sprintf( '- ID:%d "%s" %s%s [%s]', $product->get_id(), $product->get_name(), $currency_sym, $product->get_price() ?: '0', $product->get_stock_status() );
				}
			}
		}

		return array(
			'success' => true,
			'message' => implode( "\n", $lines ),
		);
	}

	/**
	 * On-demand brand profile: AI-generated site identity, voice, content DNA.
	 */
	public function get_brand_profile( array $params ): array {
		$profiler   = new PressArk_Site_Profile();
		$profile    = $profiler->get();
		$ai_summary = $profiler->get_ai_summary();

		if ( empty( $profile ) ) {
			return array(
				'success' => true,
				'message' => __( 'Site profile has not been generated yet. It will be auto-generated soon, or you can use refresh_site_profile to trigger it now.', 'pressark' ),
			);
		}

		$lines = array();

		if ( ! empty( $ai_summary ) ) {
			$lines[] = $ai_summary;
		} else {
			$identity = $profile['identity'] ?? array();
			$dna      = $profile['content_dna'] ?? array();

			if ( ! empty( $identity['name'] ) ) {
				$lines[] = __( 'Business: ', 'pressark' ) . $identity['name'];
			}
			if ( ! empty( $identity['industry'] ) ) {
				$lines[] = __( 'Industry: ', 'pressark' ) . $identity['industry'];
			}
			if ( ! empty( $dna['tone'] ) ) {
				$lines[] = __( 'Tone: ', 'pressark' ) . $dna['tone'];
			}
			if ( ! empty( $dna['topics'] ) && is_array( $dna['topics'] ) ) {
				$lines[] = __( 'Topics: ', 'pressark' ) . implode( ', ', array_slice( $dna['topics'], 0, 10 ) );
			}
		}

		$generated_at = $profile['generated_at'] ?? '';
		if ( $generated_at ) {
			$lines[] = "\n" . __( 'Profile generated: ', 'pressark' ) . $generated_at;
		}

		return array(
			'success' => true,
			'message' => ! empty( $lines ) ? implode( "\n", $lines ) : __( 'Profile data is available but empty. Try using refresh_site_profile to regenerate it.', 'pressark' ),
		);
	}

	/**
	 * On-demand tool listing: shows tools not currently loaded.
	 */
	public function get_available_tools( array $params ): array {
		$category      = sanitize_text_field( $params['category'] ?? '' );
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = PressArk_Elementor::is_active();
		$all_tools     = PressArk_Tools::get_all( $has_woo, $has_elementor );
		$categories    = $this->get_available_tool_categories( $has_woo, $has_elementor );

		$tool_map = array();
		foreach ( $all_tools as $tool ) {
			$tool_map[ $tool['name'] ] = $tool['description'];
		}

		$lines = array();

		if ( ! empty( $category ) && isset( $categories[ $category ] ) ) {
			$lines[] = sprintf( __( 'TOOLS - %s:', 'pressark' ), strtoupper( $category ) );
			foreach ( $categories[ $category ] as $tool_name ) {
				$desc    = $tool_map[ $tool_name ] ?? '';
				$lines[] = sprintf( '- %s - %s', $tool_name, $desc );
			}
		} else {
			$lines[] = sprintf( __( 'AVAILABLE TOOLS (%d total):', 'pressark' ), count( $all_tools ) );
			$lines[] = '';
			foreach ( $categories as $cat_name => $cat_tools ) {
				$tool_names = array();
				foreach ( $cat_tools as $tool_name ) {
					if ( isset( $tool_map[ $tool_name ] ) ) {
						$tool_names[] = $tool_name;
					}
				}
				if ( ! empty( $tool_names ) ) {
					$lines[] = sprintf( '%s (%d): %s', ucfirst( $cat_name ), count( $tool_names ), implode( ', ', $tool_names ) );
				}
			}
			$lines[] = '';
			$lines[] = __( 'Use get_available_tools with a category parameter to see full descriptions for any group.', 'pressark' );
		}

		return array(
			'success' => true,
			'message' => implode( "\n", $lines ),
		);
	}

	/**
	 * Load additional tool schemas by group name.
	 */
	public function load_tool_group( array $params ): array {
		$group = sanitize_text_field( $params['group'] ?? '' );

		if ( empty( $group ) ) {
			return $this->error( __( 'Missing "group" parameter. Available groups: ', 'pressark' ) . implode( ', ', PressArk_Operation_Registry::group_names() ) );
		}

		if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
			return $this->error( sprintf( __( 'Unknown tool group: %1$s. Available groups: %2$s', 'pressark' ), $group, implode( ', ', PressArk_Operation_Registry::group_names() ) ) );
		}

		return $this->success(
			sprintf(
				/* translators: %1$s: tool group name, %2$s: comma-separated tool names */
				__( 'Tool group "%1$s" loaded. Available tools: %2$s', 'pressark' ),
				$group,
				implode( ', ', PressArk_Operation_Registry::tool_names_for_group( $group ) )
			)
		);
	}

	/**
	 * Handle discover_tools for non-agent execution paths.
	 */
	public function handle_discover_tools( array $params ): array {
		$query = sanitize_text_field( $params['query'] ?? '' );

		if ( empty( $query ) ) {
			return $this->error( __( 'Missing "query" parameter. Describe what tools you need (e.g., "SEO analysis", "media management").', 'pressark' ) );
		}

		$catalog = PressArk_Tool_Catalog::instance();
		$results = $catalog->discover( $query );

		if ( empty( $results ) ) {
			return $this->success( sprintf( __( 'No tools found matching "%s". Try different search terms or use get_available_tools for a full listing.', 'pressark' ), $query ) );
		}

		return $this->success( wp_json_encode( $results ) );
	}

	/**
	 * Handle load_tools for non-agent execution paths.
	 */
	public function handle_load_tools( array $params ): array {
		$group = sanitize_text_field( $params['group'] ?? '' );
		$tools = $params['tools'] ?? array();

		if ( empty( $group ) && empty( $tools ) ) {
			return $this->error( __( 'Provide "group" (e.g., "seo") or "tools" (e.g., ["analyze_seo", "fix_seo"]). Available groups: ', 'pressark' ) . implode( ', ', PressArk_Operation_Registry::group_names() ) );
		}

		$messages = array();

		if ( ! empty( $group ) ) {
			if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
				return $this->error( sprintf( __( 'Unknown group: %1$s. Available: %2$s', 'pressark' ), $group, implode( ', ', PressArk_Operation_Registry::group_names() ) ) );
			}
			$messages[] = sprintf( __( 'Group "%1$s" loaded: %2$s', 'pressark' ), $group, implode( ', ', PressArk_Operation_Registry::tool_names_for_group( $group ) ) );
		}

		if ( ! empty( $tools ) && is_array( $tools ) ) {
			$messages[] = __( 'Tools loaded: ', 'pressark' ) . implode( ', ', array_map( 'sanitize_text_field', $tools ) );
		}

		return $this->success( implode( ' ', $messages ) );
	}

	/**
	 * Build the category map used by get_available_tools.
	 *
	 * @return array<string, string[]>
	 */
	private function get_available_tool_categories( bool $has_woo, bool $has_elementor ): array {
		$categories = self::AVAILABLE_TOOL_CATEGORIES;

		if ( $has_woo ) {
			$categories['woocommerce'] = array(
				'edit_product', 'create_product', 'bulk_edit_products', 'analyze_store', 'list_orders', 'get_order',
				'update_order', 'manage_coupon', 'inventory_report', 'sales_summary',
				'list_customers', 'get_customer', 'email_customer',
				'get_shipping_zones', 'get_tax_settings', 'get_payment_gateways',
				'get_wc_settings', 'get_wc_emails', 'get_wc_status',
				'list_reviews', 'moderate_review', 'list_variations', 'edit_variation',
				'create_variation', 'bulk_edit_variations', 'list_product_attributes',
				'create_refund', 'get_top_sellers', 'create_order',
				'category_report',
			);
		}

		if ( $has_elementor ) {
			$categories['elementor'] = array(
				'elementor_read_page', 'elementor_find_widgets', 'elementor_edit_widget',
				'elementor_add_widget', 'elementor_add_container',
				'elementor_list_templates', 'elementor_create_from_template',
				'elementor_get_styles', 'elementor_find_replace',
				'elementor_create_page', 'elementor_get_widget_schema',
			);
		}

		return $categories;
	}
}
