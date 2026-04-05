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
		'resources'  => array( 'list_resources', 'read_resource' ),
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
		$lines[] = sprintf(
			/* translators: 1: site name, 2: site home URL */
			__( 'Site: %1$s (%2$s)', 'pressark' ),
			get_bloginfo( 'name' ),
			home_url()
		);
		$lines[] = sprintf(
			/* translators: %s: site tagline */
			__( 'Tagline: %s', 'pressark' ),
			get_bloginfo( 'description' )
		);
		$lines[] = sprintf(
			/* translators: 1: WordPress version, 2: PHP version */
			__( 'WordPress %1$s | PHP %2$s', 'pressark' ),
			get_bloginfo( 'version' ),
			phpversion()
		);
		$lines[] = sprintf(
			/* translators: 1: theme name, 2: theme version */
			__( 'Theme: %1$s v%2$s', 'pressark' ),
			$theme->get( 'Name' ),
			$theme->get( 'Version' )
		);
		$lines[] = sprintf(
			/* translators: 1: published page count, 2: published post count, 3: total comment count */
			__( 'Pages: %1$d | Posts: %2$d | Comments: %3$d', 'pressark' ),
			$page_count->publish ?? 0,
			$post_count->publish ?? 0,
			$comment_count->total_comments ?? 0
		);
		$lines[] = sprintf(
			/* translators: 1: front page ID, 2: site timezone string */
			__( 'Front page ID: %1$d | Timezone: %2$s', 'pressark' ),
			$front_id,
			wp_timezone_string()
		);

		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_names   = array();
		foreach ( $active_plugins as $plugin_file ) {
			$data           = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
			$plugin_names[] = $data['Name'] ?? basename( $plugin_file, '.php' );
		}
		$lines[] = sprintf(
			/* translators: 1: number of active plugins, 2: comma-separated plugin names */
			__( 'Active plugins (%1$d): %2$s', 'pressark' ),
			count( $plugin_names ),
			implode( ', ', $plugin_names )
		);
		$lines[] = sprintf(
			/* translators: 1: user login, 2: comma-separated user roles */
			__( 'User: %1$s (%2$s)', 'pressark' ),
			$user->user_login,
			implode( ', ', $user->roles )
		);

		$flags = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$product_count = wp_count_posts( 'product' );
			$flags[]       = sprintf(
				/* translators: %d: number of published WooCommerce products */
				__( 'WooCommerce(%d products)', 'pressark' ),
				$product_count->publish ?? 0
			);
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
				$lines[] = sprintf(
					/* translators: 1: homepage title, 2: homepage post ID */
					__( 'Homepage: "%1$s" (ID:%2$d) [static page]', 'pressark' ),
					$front_page->post_title,
					$front_page_id
				);
			}
		} else {
			$lines[] = __( 'Homepage: Blog listing (no static page)', 'pressark' );
		}

		if ( $blog_page_id && $blog_page_id !== $front_page_id ) {
			$blog_page = get_post( $blog_page_id );
			if ( $blog_page ) {
				$lines[] = sprintf(
					/* translators: 1: blog page title, 2: blog page post ID */
					__( 'Blog page: "%1$s" (ID:%2$d)', 'pressark' ),
					$blog_page->post_title,
					$blog_page_id
				);
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
				$lines[] = sprintf(
					/* translators: %d: number of pages listed */
					__( 'PAGES (%d):', 'pressark' ),
					count( $pages )
				);
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
				$lines[]     = sprintf(
					/* translators: 1: number of posts shown, 2: total number of published posts */
					__( 'RECENT POSTS (showing %1$d of %2$d):', 'pressark' ),
					count( $posts ),
					$total_posts->publish ?? 0
				);
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
				$lines[]       = sprintf(
					/* translators: 1: number of products shown, 2: total number of published products */
					__( 'PRODUCTS (showing %1$d of %2$d):', 'pressark' ),
					count( $products ),
					$product_count->publish ?? 0
				);
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

		if ( class_exists( 'PressArk_Permission_Service' ) ) {
			$visible = PressArk_Permission_Service::evaluate_tool_set(
				array_map(
					static fn( array $tool ): string => (string) ( $tool['name'] ?? '' ),
					$all_tools
				),
				class_exists( 'PressArk_Policy_Engine' )
					? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
					: 'interactive',
				array(
					'tier'             => class_exists( 'PressArk_License' ) ? ( new PressArk_License() )->get_tier() : 'free',
					'decision_purpose' => 'tool_visibility',
				)
			);
			$visible_set = array_flip( $visible['visible_tool_names'] );
			$all_tools   = array_values( array_filter(
				$all_tools,
				static fn( array $tool ): bool => isset( $visible_set[ sanitize_key( (string) ( $tool['name'] ?? '' ) ) ] )
			) );
			foreach ( $categories as $cat_key => $cat_tools ) {
				$categories[ $cat_key ] = array_values( array_filter(
					(array) $cat_tools,
					static fn( string $tool_name ): bool => isset( $visible_set[ sanitize_key( $tool_name ) ] )
				) );
			}
		}

		$tool_map = array();
		foreach ( $all_tools as $tool ) {
			$tool_map[ $tool['name'] ] = $tool['description'];
		}

		$lines = array();

		if ( ! empty( $category ) && isset( $categories[ $category ] ) ) {
			$lines[] = sprintf(
				/* translators: %s: tool category name */
				__( 'TOOLS - %s:', 'pressark' ),
				strtoupper( $category )
			);
			foreach ( $categories[ $category ] as $tool_name ) {
				$desc    = $tool_map[ $tool_name ] ?? '';
				$lines[] = sprintf( '- %s - %s', $tool_name, $desc );
			}
		} else {
			$lines[] = sprintf(
				/* translators: %d: total number of available tools */
				__( 'AVAILABLE TOOLS (%d total):', 'pressark' ),
				count( $all_tools )
			);
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
			return $this->error(
				sprintf(
					/* translators: 1: requested tool group, 2: comma-separated list of valid groups */
					__( 'Unknown tool group: %1$s. Available groups: %2$s', 'pressark' ),
					$group,
					implode( ', ', PressArk_Operation_Registry::group_names() )
				)
			);
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
		$results = $catalog->discover(
			$query,
			array(),
			array(
				'permission_context' => class_exists( 'PressArk_Policy_Engine' )
					? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
					: 'interactive',
				'permission_meta'    => array(
					'tier' => class_exists( 'PressArk_License' ) ? ( new PressArk_License() )->get_tier() : 'free',
				),
			)
		);

		if ( empty( $results ) ) {
			return $this->success(
				sprintf(
					/* translators: %s: tool discovery search query */
					__( 'No tools found matching "%s". Try different search terms or use get_available_tools for a full listing.', 'pressark' ),
					$query
				)
			);
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
				return $this->error(
					sprintf(
						/* translators: 1: requested tool group, 2: comma-separated list of valid groups */
						__( 'Unknown group: %1$s. Available: %2$s', 'pressark' ),
						$group,
						implode( ', ', PressArk_Operation_Registry::group_names() )
					)
				);
			}
			$messages[] = sprintf(
				/* translators: 1: loaded tool group, 2: comma-separated list of loaded tools */
				__( 'Group "%1$s" loaded: %2$s', 'pressark' ),
				$group,
				implode( ', ', PressArk_Operation_Registry::tool_names_for_group( $group ) )
			);
		}

		if ( ! empty( $tools ) && is_array( $tools ) ) {
			$messages[] = __( 'Tools loaded: ', 'pressark' ) . implode( ', ', array_map( 'sanitize_text_field', $tools ) );
		}

		return $this->success( implode( ' ', $messages ) );
	}

	/**
	 * List available site resources.
	 *
	 * @since 5.1.0
	 */
	public function list_resources( array $params ): array {
		$group = sanitize_text_field( $params['group'] ?? '' );

		$resources = PressArk_Resource_Registry::list(
			'' !== $group ? $group : null
		);

		if ( empty( $resources ) ) {
			if ( '' !== $group ) {
				return $this->success(
					sprintf(
						/* translators: 1: resource group name, 2: available groups */
						__( 'No resources in group "%1$s". Available groups: %2$s', 'pressark' ),
						$group,
						implode( ', ', PressArk_Resource_Registry::group_names() )
					)
				);
			}
			return $this->success( __( 'No resources available.', 'pressark' ) );
		}

		$lines = array();
		$by_group = array();
		foreach ( $resources as $res ) {
			$by_group[ $res['group'] ][] = $res;
		}

		foreach ( $by_group as $grp => $items ) {
			$lines[] = strtoupper( $grp ) . ':';
			foreach ( $items as $res ) {
				$lines[] = sprintf( '  %s — %s (%s)', $res['uri'], $res['name'], $res['description'] );
			}
		}

		return array(
			'success' => true,
			'message' => implode( "\n", $lines ),
			'data'    => $resources,
		);
	}

	/**
	 * Read a specific resource by URI.
	 *
	 * @since 5.1.0
	 */
	public function read_resource( array $params ): array {
		$uri  = sanitize_text_field( $params['uri'] ?? '' );
		$mode = sanitize_key( (string) ( $params['mode'] ?? 'summary' ) );
		if ( ! in_array( $mode, array( 'summary', 'detail', 'raw' ), true ) ) {
			$mode = 'summary';
		}

		if ( '' === $uri ) {
			return $this->error( __( 'Missing "uri" parameter. Use list_resources first to see available resource URIs.', 'pressark' ) );
		}

		$result = PressArk_Resource_Registry::read( $uri );

		if ( ! ( $result['success'] ?? false ) ) {
			return $this->error( $result['error'] ?? __( 'Failed to read resource.', 'pressark' ) );
		}

		$data   = $result['data'];
		$cached = $result['cached'] ?? false;
		$meta   = $this->find_resource_meta( $uri );
		$read_meta = is_array( $result['meta'] ?? null ) ? $result['meta'] : array();

		if ( 'raw' === $mode ) {
			$message = is_string( $data )
				? $data
				: wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $message ) ) {
				$message = '';
			}
		} elseif ( 'detail' === $mode ) {
			$message = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $message ) ) {
				$message = '';
			}
		} else {
			$summary = $this->summarize_resource_payload( $data );
			$lines   = array();
			$label   = $meta['name'] ?? $uri;
			$lines[] = sprintf( 'Resource: %s', $label );
			$lines[] = sprintf( 'URI: %s', $uri );
			if ( ! empty( $meta['group'] ) ) {
				$lines[] = sprintf( 'Group: %s', $meta['group'] );
			}
			if ( ! empty( $meta['description'] ) ) {
				$lines[] = sprintf( 'Description: %s', $meta['description'] );
			}
			if ( ! empty( $read_meta['trust_class'] ) || ! empty( $read_meta['provider'] ) ) {
				$lines[] = sprintf(
					'Trust: %s | Provider: %s',
					$read_meta['trust_class'] ?? 'unknown',
					$read_meta['provider'] ?? 'unknown'
				);
			}
			if ( ! empty( $read_meta['freshness'] ) || ! empty( $read_meta['completeness'] ) ) {
				$lines[] = sprintf(
					'Read state: %s / %s',
					$read_meta['freshness'] ?? 'fresh',
					$read_meta['completeness'] ?? 'complete'
				);
			}
			if ( ! empty( $read_meta['query_fingerprint'] ) ) {
				$lines[] = 'Fingerprint: ' . $read_meta['query_fingerprint'];
			}
			$lines[] = sprintf( 'Shape: %s', $summary['shape'] );
			if ( isset( $summary['count'] ) ) {
				$lines[] = sprintf( 'Count: %d', $summary['count'] );
			}
			if ( ! empty( $summary['keys'] ) ) {
				$lines[] = 'Top-level keys: ' . implode( ', ', $summary['keys'] );
			}
			if ( array_key_exists( 'preview', $summary ) ) {
				$preview = $summary['preview'];
				if ( is_array( $preview ) || is_object( $preview ) ) {
					$preview = wp_json_encode( $preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
				$lines[] = 'Preview: ' . $preview;
			}
			$message = implode( "\n", $lines );
			$data    = array_merge( $summary, array(
				'uri'   => $uri,
				'name'  => $meta['name'] ?? '',
				'group' => $meta['group'] ?? '',
				'mode'  => 'summary',
				'meta'  => $read_meta,
			) );
		}

		if ( $cached ) {
			$message .= ( '' !== $message ? "\n" : '' ) . '(cached)';
		}

		return array(
			'success' => true,
			'message' => $message,
			'data'    => $data,
			'mode'    => $mode,
			'uri'     => $uri,
			'cached'  => $cached,
			'meta'    => $read_meta,
			'read_meta' => $read_meta,
		);
	}

	/**
	 * Look up resource metadata for a URI.
	 *
	 * @param string $uri Resource URI.
	 * @return array
	 */
	private function find_resource_meta( string $uri ): array {
		foreach ( PressArk_Resource_Registry::list() as $resource ) {
			if ( $uri === (string) ( $resource['uri'] ?? '' ) ) {
				return $resource;
			}
		}

		return array();
	}

	/**
	 * Build a compact structural summary for a resource payload.
	 *
	 * @param mixed $data Resource payload.
	 * @return array
	 */
	private function summarize_resource_payload( $data ): array {
		if ( is_array( $data ) ) {
			$is_list = empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 );
			if ( $is_list ) {
				return array(
					'shape'   => 'list',
					'count'   => count( $data ),
					'preview' => array_map(
						array( $this, 'summarize_resource_value' ),
						array_slice( $data, 0, 3 )
					),
				);
			}

			return array(
				'shape'   => 'object',
				'count'   => count( $data ),
				'keys'    => array_slice( array_keys( $data ), 0, 10 ),
				'preview' => array_map(
					array( $this, 'summarize_resource_value' ),
					array_slice( $data, 0, 5, true )
				),
			);
		}

		if ( is_object( $data ) ) {
			return $this->summarize_resource_payload( get_object_vars( $data ) );
		}

		return array(
			'shape'   => gettype( $data ),
			'preview' => $this->summarize_resource_value( $data ),
		);
	}

	/**
	 * Shrink a resource field value into a safe preview.
	 *
	 * @param mixed $value Resource field value.
	 * @return mixed
	 */
	private function summarize_resource_value( $value ) {
		if ( is_array( $value ) ) {
			$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
			return array(
				'shape' => $is_list ? 'list' : 'object',
				'count' => count( $value ),
				'keys'  => $is_list ? array() : array_slice( array_keys( $value ), 0, 5 ),
			);
		}

		if ( is_object( $value ) ) {
			return $this->summarize_resource_value( get_object_vars( $value ) );
		}

		if ( is_string( $value ) ) {
			$clean = preg_replace( '/\s+/', ' ', trim( $value ) );
			if ( ! is_string( $clean ) ) {
				$clean = '';
			}
			return mb_strlen( $clean ) > 160 ? mb_substr( $clean, 0, 160 ) . '…' : $clean;
		}

		return $value;
	}

	/**
	 * Record a site observation for future conversations.
	 *
	 * @param array $params { note: string, category: string }.
	 * @return array Success or error response.
	 */
	public function site_note( array $params ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( __( 'You do not have permission to record site notes.', 'pressark' ) );
		}

		$note = sanitize_text_field( $params['note'] ?? '' );
		if ( '' === $note ) {
			return $this->error( __( 'Missing "note" parameter.', 'pressark' ) );
		}
		if ( mb_strlen( $note ) > 200 ) {
			$note = mb_substr( $note, 0, 200 );
		}

		$allowed_categories = array( 'content', 'products', 'technical', 'preferences', 'issues' );
		$category           = sanitize_text_field( $params['category'] ?? '' );
		if ( ! in_array( $category, $allowed_categories, true ) ) {
			return $this->error(
				__( 'Invalid category. Must be one of: ', 'pressark' ) . implode( ', ', $allowed_categories )
			);
		}

		$raw   = get_option( 'pressark_site_notes', '[]' );
		$notes = json_decode( is_string( $raw ) ? $raw : '[]', true );
		if ( ! is_array( $notes ) ) {
			$notes = array();
		}

		// Dedup: if a very similar note exists in the same category, update it instead.
		foreach ( $notes as $idx => $existing ) {
			if ( ( $existing['category'] ?? '' ) === $category
				&& self::word_similarity( strtolower( $existing['note'] ?? '' ), strtolower( $note ) ) > 0.8
			) {
				$notes[ $idx ]['created_at'] = current_time( 'mysql' );
				$notes[ $idx ]['note']       = $note;
				update_option( 'pressark_site_notes', wp_json_encode( array_values( $notes ) ), false );

				return $this->success(
					sprintf(
						/* translators: 1: category name */
						__( 'Updated existing site note under "%1$s". %2$d notes stored.', 'pressark' ),
						$category,
						count( $notes )
					)
				);
			}
		}

		$notes[] = array(
			'note'       => $note,
			'category'   => $category,
			'created_at' => current_time( 'mysql' ),
		);

		// FIFO: keep max 50 entries.
		if ( count( $notes ) > 50 ) {
			$notes = array_slice( $notes, -50 );
		}

		update_option( 'pressark_site_notes', wp_json_encode( $notes ), false );

		return $this->success(
			sprintf(
				/* translators: 1: category name */
				__( 'Site note recorded under "%1$s". %2$d notes stored.', 'pressark' ),
				$category,
				count( $notes )
			)
		);
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

	// ── Site Notes Utilities ─────────────────────────────────────────

	/**
	 * Jaccard word similarity between two strings.
	 *
	 * @return float 0.0–1.0
	 */
	public static function word_similarity( string $a, string $b ): float {
		$words_a = array_unique( preg_split( '/\s+/', trim( $a ), -1, PREG_SPLIT_NO_EMPTY ) );
		$words_b = array_unique( preg_split( '/\s+/', trim( $b ), -1, PREG_SPLIT_NO_EMPTY ) );

		if ( empty( $words_a ) || empty( $words_b ) ) {
			return 0.0;
		}

		$intersection = count( array_intersect( $words_a, $words_b ) );
		$union        = count( array_unique( array_merge( $words_a, $words_b ) ) );

		return 0 === $union ? 0.0 : (float) $intersection / $union;
	}

	/**
	 * Weekly cron callback: consolidate site notes.
	 *
	 * Deduplicates similar notes in the same category, prunes stale
	 * issues (>90 days), and caps total at 40 entries.
	 */
	public static function consolidate_site_notes(): void {
		$raw   = get_option( 'pressark_site_notes', '[]' );
		$notes = json_decode( is_string( $raw ) ? $raw : '[]', true );

		if ( empty( $notes ) || ! is_array( $notes ) ) {
			return;
		}

		$consolidated = array();

		foreach ( $notes as $note ) {
			$text = strtolower( trim( $note['note'] ?? '' ) );
			$cat  = $note['category'] ?? 'technical';

			if ( '' === $text ) {
				continue;
			}

			// Dedup: if a very similar note exists in the same category, keep the newer one.
			$dominated = false;
			foreach ( $consolidated as $i => $existing ) {
				if ( ( $existing['category'] ?? '' ) === $cat ) {
					if ( self::word_similarity( $text, strtolower( $existing['note'] ?? '' ) ) > 0.8 ) {
						if ( ( $note['created_at'] ?? '' ) > ( $existing['created_at'] ?? '' ) ) {
							$consolidated[ $i ] = $note;
						}
						$dominated = true;
						break;
					}
				}
			}

			if ( ! $dominated ) {
				$consolidated[] = $note;
			}
		}

		// Prune issues older than 90 days.
		$cutoff       = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$consolidated = array_filter( $consolidated, static function ( $n ) use ( $cutoff ) {
			if ( 'issues' === ( $n['category'] ?? '' ) && ( $n['created_at'] ?? '' ) < $cutoff ) {
				return false;
			}
			return true;
		} );

		// Sort by created_at and cap at 40 (headroom below 50 cap).
		$consolidated = array_values( $consolidated );
		usort( $consolidated, static function ( $a, $b ) {
			return strcmp( $a['created_at'] ?? '', $b['created_at'] ?? '' );
		} );

		if ( count( $consolidated ) > 40 ) {
			$consolidated = array_slice( $consolidated, -40 );
		}

		update_option( 'pressark_site_notes', wp_json_encode( $consolidated ), false );
	}

	/**
	 * Lightweight site notes formatter for non-agent callers (chat, workflow).
	 *
	 * Groups notes by category (last 5 per category), applies a 2400-char
	 * budget cap, and returns the formatted string ready to append to context.
	 *
	 * @return string Formatted notes block or empty string.
	 */
	public static function format_site_notes_basic(): string {
		$raw   = get_option( 'pressark_site_notes', '[]' );
		$notes = json_decode( is_string( $raw ) ? $raw : '[]', true );

		if ( empty( $notes ) || ! is_array( $notes ) ) {
			return '';
		}

		$grouped = array();
		foreach ( $notes as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['note'] ) || empty( $entry['category'] ) ) {
				continue;
			}
			$cat = sanitize_text_field( (string) $entry['category'] );
			$grouped[ $cat ][] = sanitize_text_field( (string) $entry['note'] );
		}

		if ( empty( $grouped ) ) {
			return '';
		}

		$note_parts = array();
		foreach ( $grouped as $cat => $items ) {
			$note_parts[] = ucfirst( $cat ) . ': ' . implode( '; ', array_slice( $items, -5 ) );
		}

		$text = "\nSite Notes: " . implode( ' | ', $note_parts );

		// Hard cap: ~600 tokens.
		if ( strlen( $text ) > 2400 ) {
			// Trim categories with most entries first.
			while ( strlen( $text ) > 2400 && count( $note_parts ) > 1 ) {
				array_shift( $note_parts );
				$text = "\nSite Notes: " . implode( ' | ', $note_parts );
			}
		}

		return $text;
	}
}
