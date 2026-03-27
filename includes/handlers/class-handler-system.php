<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * System domain action handlers.
 *
 * Covers site settings, navigation menus, email, users, site health,
 * scheduled tasks, plugins, themes, database maintenance, site profile,
 * and log analysis.
 *
 * @since 2.7.0
 */

class PressArk_Handler_System extends PressArk_Handler_Base {

	/**
	 * Allowed site settings that can be read/written.
	 */
	public const ALLOWED_SETTINGS = array(
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'timezone_string',
		'date_format',
		'time_format',
		'posts_per_page',
		'permalink_structure',
		'default_comment_status',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'admin_email',
		'start_of_week',
		'blog_public',
		'default_category',
		'default_post_format',
		'WPLANG',
	);

	/**
	 * Read-only settings (cannot be updated via update_site_settings).
	 */
	public const READONLY_SETTINGS = array( 'siteurl', 'home', 'admin_email' );

	// ── Site Settings ────────────────────────────────────────────────────

	/**
	 * Get site settings.
	 */
	public function get_site_settings( array $params ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to view site settings.', 'pressark' ) );
		}

		// Discover mode — list all registered settings grouped by page.
		if ( ! empty( $params['discover'] ) ) {
			$all_registered = get_registered_settings();
			$grouped        = array();
			foreach ( $all_registered as $name => $schema ) {
				$group = $schema['group'] ?? 'general';
				$grouped[ $group ][] = array(
					'name'        => $name,
					'type'        => $schema['type'] ?? 'string',
					'description' => $schema['description'] ?? '',
					'default'     => $schema['default'] ?? null,
				);
			}

			// Section filter — return only a specific group.
			$section = sanitize_text_field( $params['section'] ?? '' );
			if ( $section ) {
				if ( isset( $grouped[ $section ] ) ) {
					$grouped = array( $section => $grouped[ $section ] );
				} else {
					return array(
						'success'          => false,
						'message'          => sprintf( __( 'Section "%s" not found.', 'pressark' ), $section ),
						'available_groups' => array_keys( $grouped ),
					);
				}
			}

			return array(
				'success'          => true,
				'message'          => sprintf( __( 'Found %d registered settings across %d groups.', 'pressark' ), count( $all_registered ), count( $grouped ) ),
				'data'             => $grouped,
				'available_groups' => array_keys( $grouped ),
				'hint'             => __( 'These are Settings-API-registered options. Use keys param to read specific values.', 'pressark' ),
			);
		}

		$keys = $params['keys'] ?? array();
		if ( empty( $keys ) || ! is_array( $keys ) ) {
			$keys = self::ALLOWED_SETTINGS;
		}

		$settings = array();
		foreach ( $keys as $key ) {
			$key = sanitize_text_field( $key );
			if ( in_array( $key, self::ALLOWED_SETTINGS, true ) ) {
				$settings[ $key ] = get_option( $key, '' );
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Site settings retrieved.', 'pressark' ),
			'data'    => $settings,
		);
	}

	/**
	 * Update site settings with undo support.
	 */
	public function update_site_settings( array $params ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to update site settings.', 'pressark' ) );
		}

		$changes = $params['changes'] ?? array();
		if ( empty( $changes ) || ! is_array( $changes ) ) {
			return array( 'success' => false, 'message' => __( 'No settings changes specified.', 'pressark' ) );
		}

		$updated = array();
		$old_values = array();

		foreach ( $changes as $key => $value ) {
			$key = sanitize_text_field( $key );

			if ( ! in_array( $key, self::ALLOWED_SETTINGS, true ) ) {
				continue;
			}
			if ( in_array( $key, self::READONLY_SETTINGS, true ) ) {
				continue;
			}

			$old_values[ $key ] = get_option( $key, '' );
			update_option( $key, sanitize_text_field( $value ), false );
			$updated[] = $key;
		}

		if ( empty( $updated ) ) {
			return array( 'success' => false, 'message' => __( 'No valid settings to update.', 'pressark' ) );
		}

		// Flush rewrite rules if permalink changed.
		$permalink_notice = '';
		if ( in_array( 'permalink_structure', $updated, true ) ) {
			global $wp_rewrite;
			$new_structure = get_option( 'permalink_structure' );
			$wp_rewrite->set_permalink_structure( $new_structure );
			flush_rewrite_rules( false );
			$permalink_notice = ' ' . __( 'Permalink structure updated and rewrite rules flushed. All URLs are now active.', 'pressark' );
		}

		$log_id = $this->logger->log(
			'update_site_settings',
			null,
			'settings',
			wp_json_encode( $old_values ),
			wp_json_encode( $changes )
		);

		$result = array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: list of updated settings */
				__( 'Updated settings: %s.', 'pressark' ),
				implode( ', ', $updated )
			) . $permalink_notice,
			'log_id'  => $log_id,
		);

		if ( $permalink_notice ) {
			$result['notice'] = __( 'Permalink structure updated and rewrite rules flushed. All URLs are now active.', 'pressark' );
		}

		return $result;
	}

	// ── Navigation Menus ─────────────────────────────────────────────────

	/**
	 * Get navigation menus.
	 * Automatically detects FSE (wp_navigation CPT) vs classic (wp_nav_menus).
	 */
	public function get_menus( array $params ): array {
		// Detect menu system — FSE sites use wp_navigation CPT.
		$is_fse        = wp_is_block_theme();
		$fse_nav_count = $is_fse ? (int) wp_count_posts( 'wp_navigation' )->publish : 0;

		if ( $is_fse && $fse_nav_count > 0 ) {
			return $this->get_fse_navigation( $params );
		}

		// Classic menu path.
		$menu_id = absint( $params['menu_id'] ?? 0 );
		$mode    = sanitize_text_field( $params['mode'] ?? 'full' );

		// Get theme locations.
		$locations     = get_nav_menu_locations();
		$theme_locs    = get_registered_nav_menus();

		if ( $menu_id > 0 ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( ! $menu ) {
				return array( 'success' => false, 'message' => __( 'Menu not found.', 'pressark' ) );
			}

			$items = wp_get_nav_menu_items( $menu_id );

			$assigned = array();
			foreach ( $locations as $loc => $mid ) {
				if ( (int) $mid === $menu_id ) {
					$assigned[] = $loc;
				}
			}

			$data = array(
				'id'         => $menu->term_id,
				'name'       => $menu->name,
				'item_count' => $items ? count( $items ) : 0,
				'locations'  => $assigned,
			);

			// Only include full item details when mode is not summary.
			if ( 'summary' !== $mode ) {
				$item_list = array();
				if ( $items ) {
					foreach ( $items as $item ) {
						$item_list[] = array(
							'id'        => $item->ID,
							'title'     => $item->title,
							'url'       => $item->url,
							'type'      => $item->type,
							'object'    => $item->object,
							'object_id' => (int) $item->object_id,
							'parent'    => (int) $item->menu_item_parent,
							'position'  => (int) $item->menu_order,
						);
					}
				}
				$data['items'] = $item_list;
			}

			return array(
				'success' => true,
				'message' => sprintf( __( 'Menu "%s" has %d items.', 'pressark' ), $menu->name, $data['item_count'] ),
				'data'    => $data,
			);
		}

		// List all menus.
		$menus = wp_get_nav_menus();
		$list  = array();

		foreach ( $menus as $menu ) {
			$items_count = 0;
			$items       = wp_get_nav_menu_items( $menu->term_id );
			if ( $items ) {
				$items_count = count( $items );
			}

			$assigned = array();
			foreach ( $locations as $loc => $mid ) {
				if ( (int) $mid === $menu->term_id ) {
					$assigned[] = $loc;
				}
			}

			$list[] = array(
				'id'          => $menu->term_id,
				'name'        => $menu->name,
				'item_count'  => $items_count,
				'locations'   => $assigned,
			);
		}

		return array(
			'success'     => true,
			'menu_system' => 'classic',
			'message'     => sprintf( __( 'Found %d menu(s).', 'pressark' ), count( $list ) ),
			'data'        => array(
				'menus'            => $list,
				'theme_locations'  => $theme_locs,
			),
		);
	}

	/**
	 * Read FSE block-based navigation (wp_navigation CPT).
	 */
	private function get_fse_navigation( array $params ): array {
		$menu_id = absint( $params['menu_id'] ?? 0 );

		// Single navigation by ID.
		if ( $menu_id > 0 ) {
			$nav = get_post( $menu_id );
			if ( ! $nav || $nav->post_type !== 'wp_navigation' ) {
				return array( 'success' => false, 'message' => __( 'FSE navigation not found.', 'pressark' ) );
			}

			$blocks = parse_blocks( $nav->post_content );
			$items  = array();
			foreach ( $blocks as $block ) {
				if ( empty( $block['blockName'] ) ) continue;
				$items[] = $this->nav_block_to_item( $block );
			}

			return array(
				'success'     => true,
				'menu_system' => 'fse_navigation',
				'message'     => sprintf( __( 'Navigation "%s" has %d items.', 'pressark' ), $nav->post_title ?: __( '(untitled)', 'pressark' ), count( $items ) ),
				'data'        => array(
					'id'         => $nav->ID,
					'name'       => $nav->post_title ?: __( '(untitled navigation)', 'pressark' ),
					'type'       => 'fse_navigation',
					'items'      => $items,
					'edit_url'   => admin_url( 'site-editor.php?path=%2Fwp_navigation' ),
				),
			);
		}

		// List all FSE navigations.
		$navigations = get_posts( array(
			'post_type'              => 'wp_navigation',
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$menus = array();
		foreach ( $navigations as $nav ) {
			$blocks = parse_blocks( $nav->post_content );
			$items  = array();

			foreach ( $blocks as $block ) {
				if ( empty( $block['blockName'] ) ) continue;
				$items[] = $this->nav_block_to_item( $block );
			}

			$menus[] = array(
				'id'         => $nav->ID,
				'name'       => $nav->post_title ?: __( '(untitled navigation)', 'pressark' ),
				'type'       => 'fse_navigation',
				'item_count' => count( $items ),
				'items'      => $items,
				'edit_url'   => admin_url( 'site-editor.php?path=%2Fwp_navigation' ),
			);
		}

		return array(
			'success'     => true,
			'menu_system' => 'fse_navigation',
			'count'       => count( $menus ),
			'menus'       => $menus,
			'note'        => __( 'This site uses block-based navigation (FSE theme). Navigation is stored as wp_navigation posts containing blocks. Use update_menu with a navigation post ID to modify.', 'pressark' ),
			'message'     => sprintf( __( 'Found %d FSE navigation menu(s).', 'pressark' ), count( $menus ) ),
		);
	}

	/**
	 * Convert a navigation block into a readable menu item structure.
	 */
	private function nav_block_to_item( array $block ): array {
		$attrs = $block['attrs'] ?? array();
		$item  = array(
			'label'    => $attrs['label']   ?? '',
			'url'      => $attrs['url']     ?? '',
			'kind'     => $attrs['kind']    ?? 'custom',
			'post_id'  => $attrs['id']      ?? null,
			'type'     => $block['blockName'],
			'children' => array(),
		);

		// core/page-list generates navigation from page hierarchy automatically.
		if ( $block['blockName'] === 'core/page-list' ) {
			$item['label'] = __( '(Auto-generated page list)', 'pressark' );
			$item['note']  = __( 'This item automatically shows all published pages.', 'pressark' );
		}

		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			if ( ! empty( $inner['blockName'] ) ) {
				$item['children'][] = $this->nav_block_to_item( $inner );
			}
		}

		return $item;
	}

	/**
	 * Update navigation menus.
	 * Automatically routes to FSE or classic path based on the target.
	 */
	public function update_menu( array $params ): array {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to manage menus.', 'pressark' ) );
		}

		$menu_id = absint( $params['menu_id'] ?? 0 );

		// Check if this is an FSE wp_navigation post.
		if ( $menu_id > 0 ) {
			$nav_post = get_post( $menu_id );
			if ( $nav_post && $nav_post->post_type === 'wp_navigation' ) {
				return $this->update_fse_navigation( $nav_post, $params );
			}
		}

		$operation = sanitize_text_field( $params['operation'] ?? '' );

		switch ( $operation ) {
			case 'create_menu':
				$name = sanitize_text_field( $params['name'] ?? '' );
				if ( empty( $name ) ) {
					return array( 'success' => false, 'message' => __( 'Menu name is required.', 'pressark' ) );
				}
				$new_menu_id = wp_create_nav_menu( $name );
				if ( is_wp_error( $new_menu_id ) ) {
					return array( 'success' => false, 'message' => $new_menu_id->get_error_message() );
				}
				$this->logger->log( 'update_menu', $new_menu_id, 'menu', null, wp_json_encode( array( 'operation' => 'create', 'name' => $name ) ) );
				return array(
					'success' => true,
					'message' => sprintf( __( 'Created menu "%s" (ID: %d).', 'pressark' ), $name, $new_menu_id ),
					'data'    => array( 'menu_id' => $new_menu_id ),
				);

			case 'add_item':
				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => __( 'Menu ID is required.', 'pressark' ) );
				}
				$item = $params['item'] ?? array();
				$item_data = array(
					'menu-item-title'  => sanitize_text_field( $item['title'] ?? '' ),
					'menu-item-status' => 'publish',
				);

				$type = sanitize_text_field( $item['type'] ?? 'custom' );
				if ( 'custom' === $type ) {
					$item_data['menu-item-type'] = 'custom';
					$item_data['menu-item-url']  = esc_url_raw( $item['url'] ?? '#' );
				} else {
					$item_data['menu-item-type']      = 'post_type';
					$item_data['menu-item-object']     = $type; // page, post.
					$item_data['menu-item-object-id']  = absint( $item['object_id'] ?? 0 );
				}

				if ( ! empty( $item['position'] ) ) {
					$item_data['menu-item-position'] = absint( $item['position'] );
				}

				$new_item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );
				if ( is_wp_error( $new_item_id ) ) {
					return array( 'success' => false, 'message' => $new_item_id->get_error_message() );
				}
				$this->logger->log( 'update_menu', $menu_id, 'menu', null, wp_json_encode( array( 'operation' => 'add_item', 'item_id' => $new_item_id ) ) );
				return array(
					'success' => true,
					'message' => sprintf( __( 'Added item "%s" to menu (item ID: %d).', 'pressark' ), $item_data['menu-item-title'], $new_item_id ),
				);

			case 'remove_item':
				$item_id = absint( $params['item_id'] ?? 0 );
				if ( ! $item_id ) {
					return array( 'success' => false, 'message' => __( 'Item ID is required.', 'pressark' ) );
				}
				$deleted = wp_delete_post( $item_id, true );
				if ( ! $deleted ) {
					return array( 'success' => false, 'message' => __( 'Failed to remove menu item.', 'pressark' ) );
				}
				return array( 'success' => true, 'message' => sprintf( __( 'Removed menu item #%d.', 'pressark' ), $item_id ) );

			case 'assign_location':
				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => __( 'Menu ID is required.', 'pressark' ) );
				}
				$location = sanitize_text_field( $params['location'] ?? '' );
				if ( empty( $location ) ) {
					return array( 'success' => false, 'message' => __( 'Location slug is required.', 'pressark' ) );
				}
				$locations = get_nav_menu_locations();
				$locations[ $location ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $locations );
				return array( 'success' => true, 'message' => sprintf( __( 'Assigned menu #%d to location "%s".', 'pressark' ), $menu_id, $location ) );

			case 'rename_menu':
				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => __( 'Menu ID is required.', 'pressark' ) );
				}
				$name = sanitize_text_field( $params['name'] ?? '' );
				if ( empty( $name ) ) {
					return array( 'success' => false, 'message' => __( 'New name is required.', 'pressark' ) );
				}
				$result = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}
				return array( 'success' => true, 'message' => sprintf( __( 'Renamed menu to "%s".', 'pressark' ), $name ) );

			case 'delete_menu':
				if ( ! $menu_id ) {
					return array( 'success' => false, 'message' => __( 'Menu ID is required.', 'pressark' ) );
				}
				$result = wp_delete_nav_menu( $menu_id );
				if ( is_wp_error( $result ) ) {
					return array( 'success' => false, 'message' => $result->get_error_message() );
				}
				return array( 'success' => true, 'message' => sprintf( __( 'Deleted menu #%d.', 'pressark' ), $menu_id ) );

			default:
				return array( 'success' => false, 'message' => sprintf( __( 'Unknown menu operation: %s', 'pressark' ), $operation ) );
		}
	}

	/**
	 * Update FSE block-based navigation (wp_navigation CPT).
	 * Supports add_item, remove_item operations.
	 */
	private function update_fse_navigation( WP_Post $nav_post, array $params ): array {
		$action = sanitize_text_field( $params['operation'] ?? $params['action'] ?? 'add_item' );

		$blocks = parse_blocks( $nav_post->post_content );

		if ( $action === 'add_item' ) {
			$item    = $params['item'] ?? $params;
			$label   = sanitize_text_field( $item['label'] ?? $item['title'] ?? '' );
			$url     = esc_url_raw( $item['url'] ?? '' );
			$post_id = absint( $item['post_id'] ?? $item['object_id'] ?? 0 );

			if ( empty( $label ) || empty( $url ) ) {
				return array( 'success' => false, 'message' => __( 'label and url are required to add a navigation item.', 'pressark' ) );
			}

			// Resolve URL kind and ID.
			$kind = 'custom';
			if ( $post_id ) {
				$linked_post = get_post( $post_id );
				if ( $linked_post ) {
					$kind = 'post-type';
					$url  = get_permalink( $post_id );
				}
			}

			$new_block = array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => array_filter( array(
					'label' => $label,
					'url'   => $url,
					'kind'  => $kind,
					'id'    => $post_id ?: null,
				) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);

			$blocks[] = $new_block;
		}

		if ( $action === 'remove_item' ) {
			$item         = $params['item'] ?? $params;
			$remove_label = sanitize_text_field( $item['label'] ?? $item['title'] ?? $params['label'] ?? '' );
			$remove_id    = absint( $params['item_id'] ?? 0 );

			if ( empty( $remove_label ) && ! $remove_id ) {
				return array( 'success' => false, 'message' => __( 'label or item_id is required to remove a navigation item.', 'pressark' ) );
			}

			$blocks = array_filter( $blocks, function( $b ) use ( $remove_label, $remove_id ) {
				if ( empty( $b['blockName'] ) ) return true;
				$attrs = $b['attrs'] ?? array();
				if ( $remove_label && ( $attrs['label'] ?? '' ) === $remove_label ) return false;
				if ( $remove_id && ( $attrs['id'] ?? 0 ) === $remove_id ) return false;
				return true;
			} );
			$blocks = array_values( $blocks );
		}

		if ( $action === 'rename_menu' ) {
			$name = sanitize_text_field( $params['name'] ?? '' );
			if ( empty( $name ) ) {
				return array( 'success' => false, 'message' => __( 'New name is required.', 'pressark' ) );
			}
			$result = wp_update_post( array(
				'ID'         => $nav_post->ID,
				'post_title' => $name,
			), true );
			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}
			return array(
				'success'   => true,
				'menu_id'   => $nav_post->ID,
				'message'   => sprintf( __( 'Renamed FSE navigation to "%s".', 'pressark' ), $name ),
			);
		}

		$new_content = serialize_blocks( $blocks );
		$result = wp_update_post( array(
			'ID'           => $nav_post->ID,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		$this->logger->log( 'update_menu', $nav_post->ID, 'fse_navigation', null, wp_json_encode( array( 'operation' => $action ) ) );

		return array(
			'success'   => true,
			'menu_id'   => $nav_post->ID,
			'menu_name' => $nav_post->post_title,
			'action'    => $action,
			'message'   => __( 'FSE navigation updated successfully.', 'pressark' ),
		);
	}

	// ── Email ────────────────────────────────────────────────────────────

	public function get_email_log( array $params ): array {
		$limit = min( intval( $params['limit'] ?? 20 ), 50 );
		$log   = get_option( 'pressark_email_log', array() );
		$log   = array_slice( array_reverse( $log ), 0, $limit );

		return array(
			'success' => true,
			'message' => sprintf( __( '%d emails in log.', 'pressark' ), count( $log ) ),
			'data'    => $log,
		);
	}

	// ── User Management ──────────────────────────────────────────────────

	public function list_users( array $params ): array {
		$args = array(
			'number'  => min( intval( $params['limit'] ?? 20 ), 100 ),
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( ! empty( $params['roles'] ) && is_array( $params['roles'] ) ) {
			$args['role__in'] = array_map( 'sanitize_key', $params['roles'] );
		} elseif ( ! empty( $params['exclude_roles'] ) ) {
			$args['role__not_in'] = array_map( 'sanitize_key', (array) $params['exclude_roles'] );
		} elseif ( ! empty( $params['role'] ) && 'all' !== $params['role'] ) {
			$args['role'] = sanitize_text_field( $params['role'] );
		}

		if ( ! empty( $params['capability'] ) ) {
			$args['capability'] = sanitize_key( $params['capability'] );
		}

		if ( ! empty( $params['has_published_posts'] ) ) {
			$args['has_published_posts'] = true;
		}

		if ( ! empty( $params['registered_after'] ) || ! empty( $params['registered_before'] ) ) {
			$date_q = array();
			if ( ! empty( $params['registered_after'] ) ) {
				$date_q['after'] = sanitize_text_field( $params['registered_after'] );
			}
			if ( ! empty( $params['registered_before'] ) ) {
				$date_q['before'] = sanitize_text_field( $params['registered_before'] );
			}
			$args['date_query'] = array( $date_q );
		}

		if ( ! empty( $params['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $params['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$user_query = new \WP_User_Query( $args );
		$users      = $user_query->get_results();
		$total      = $user_query->get_total();
		$results    = array();

		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'role'         => implode( ', ', $user->roles ),
				'registered'   => $user->user_registered,
				'posts_count'  => count_user_posts( $user->ID ),
			);
		}

		$counts      = count_users();
		$role_names  = wp_roles()->get_names();
		$role_summary = array();

		foreach ( $counts['avail_roles'] as $role_key => $count ) {
			$label            = translate_user_role( $role_names[ $role_key ] ?? $role_key );
			$role_summary[]   = array(
				'role'  => $role_key,
				'label' => $label,
				'count' => $count,
			);
		}

		$return = array(
			'success'      => true,
			'message'      => sprintf( __( '%1$d users found. %2$d total on site.', 'pressark' ), $total, $counts['total_users'] ),
			'data'         => $results,
			'total_users'  => $counts['total_users'],
			'role_summary' => $role_summary,
		);

		$no_role_users = wp_get_users_with_no_role();
		if ( ! empty( $no_role_users ) ) {
			$return['security_notice'] = sprintf(
				__( '%d user(s) with no role assigned — possible orphaned accounts.', 'pressark' ),
				count( $no_role_users )
			);
			$return['no_role_users'] = array_map(
				fn( $u ) => array(
					'id'    => $u->ID,
					'login' => $u->user_login,
					'email' => $u->user_email,
				),
				$no_role_users
			);
		}

		return $return;
	}

	public function get_user( array $params ): array {
		$user_id = intval( $params['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'success' => false, 'message' => __( 'User not found.', 'pressark' ) );
		}

		$data = array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'role'         => implode( ', ', $user->roles ),
			'bio'          => $user->description,
			'url'          => $user->user_url,
			'registered'   => $user->user_registered,
			'posts_count'  => count_user_posts( $user->ID ),
		);

		$key_capabilities = array(
			'manage_options', 'edit_posts', 'publish_posts',
			'edit_pages', 'publish_pages', 'moderate_comments',
			'install_plugins', 'activate_plugins', 'edit_theme_options',
			'manage_woocommerce', 'create_users', 'delete_users',
		);

		$has_caps = array();
		foreach ( $key_capabilities as $cap ) {
			if ( user_can( $user->ID, $cap ) ) {
				$has_caps[] = $cap;
			}
		}

		$data['capabilities'] = $has_caps;
		$data['locale']       = get_user_locale( $user->ID );
		$data['can_login']    = ! $user->has_prop( 'spam' ) && ! $user->has_prop( 'deleted' );

		if ( class_exists( 'WooCommerce' ) && in_array( 'customer', $user->roles, true ) ) {
			$customer            = new \WC_Customer( $user_id );
			$data['wc_orders_count'] = $customer->get_order_count();
			$data['wc_total_spent']  = $customer->get_total_spent();
			$last_order              = $customer->get_last_order();
			$data['wc_last_order']   = $last_order ? $last_order->get_id() : null;
			$data['billing_phone']   = $customer->get_billing_phone();
			$data['billing_city']    = $customer->get_billing_city();
			$data['billing_country'] = $customer->get_billing_country();
		}

		return array(
			'success' => true,
			'message' => sprintf( __( 'User profile for "%s"', 'pressark' ), $user->display_name ),
			'data'    => $data,
		);
	}

	public function update_user( array $params ): array {
		$user_id = intval( $params['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return array( 'success' => false, 'message' => __( 'User not found.', 'pressark' ) );
		}

		// Require edit_user capability on the target.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this user.', 'pressark' ) );
		}

		if ( $user_id === get_current_user_id() && ! empty( $params['role'] ) && 'administrator' !== $params['role'] ) {
			return array( 'success' => false, 'message' => __( 'Cannot change your own admin role for safety.', 'pressark' ) );
		}

		$update_data = array( 'ID' => $user_id );
		$changes     = array();

		if ( isset( $params['display_name'] ) ) {
			$update_data['display_name'] = sanitize_text_field( $params['display_name'] );
			$changes[] = __( 'display name', 'pressark' );
		}
		if ( isset( $params['first_name'] ) ) {
			$update_data['first_name'] = sanitize_text_field( $params['first_name'] );
			$changes[] = __( 'first name', 'pressark' );
		}
		if ( isset( $params['last_name'] ) ) {
			$update_data['last_name'] = sanitize_text_field( $params['last_name'] );
			$changes[] = __( 'last name', 'pressark' );
		}
		if ( isset( $params['description'] ) ) {
			$update_data['description'] = sanitize_textarea_field( $params['description'] );
			$changes[] = __( 'bio', 'pressark' );
		}
		if ( isset( $params['url'] ) ) {
			$update_data['user_url'] = esc_url_raw( $params['url'] );
			$changes[] = __( 'website', 'pressark' );
		}
		if ( ! empty( $params['role'] ) ) {
			if ( ! current_user_can( 'promote_user', $user_id ) ) {
				return array( 'success' => false, 'message' => __( 'You do not have permission to change user roles.', 'pressark' ) );
			}

			$requested_role = sanitize_key( $params['role'] );
			$editable_roles = get_editable_roles();

			if ( ! isset( $editable_roles[ $requested_role ] ) ) {
				$all_roles = wp_roles()->get_names();
				if ( ! isset( $all_roles[ $requested_role ] ) ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: 1: role slug, 2: list of available roles */
							__( 'Role "%1$s" does not exist. Available roles: %2$s', 'pressark' ),
							$requested_role,
							implode( ', ', array_keys( get_editable_roles() ) )
						),
					);
				}
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: role slug */
						__( 'You don\'t have permission to assign the "%s" role.', 'pressark' ),
						$requested_role
					),
				);
			}

			$update_data['role'] = $requested_role;
			/* translators: %s: role name */
			$changes[] = sprintf( __( 'role → %s', 'pressark' ), $editable_roles[ $requested_role ]['name'] );
		}

		if ( empty( $changes ) ) {
			return array( 'success' => false, 'message' => __( 'No changes specified.', 'pressark' ) );
		}

		$old_data = array(
			'display_name' => $user->display_name,
			'role'         => implode( ',', $user->roles ),
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
		);

		$result = wp_update_user( $update_data );
		if ( is_wp_error( $result ) ) {
			return array( 'success' => false, 'message' => $result->get_error_message() );
		}

		$log_id = $this->logger->log(
			'update_user',
			$user_id,
			'user',
			wp_json_encode( $old_data ),
			wp_json_encode( $update_data )
		);

		return array(
			'success' => true,
			'message' => sprintf(
				__( 'Updated user "%1$s": %2$s', 'pressark' ),
				$user->display_name,
				implode( ', ', $changes )
			),
			'log_id'  => $log_id,
		);
	}

	// ── Site Health ──────────────────────────────────────────────────────

	public function site_health( array $params ): array {
		$include_debug = ! empty( $params['include_debug'] ) || ( $params['section'] ?? '' ) === 'full';

		// Bootstrap WP_Site_Health if not already loaded.
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		// WP_Site_Health requires these files.
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'wp_check_php_version' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$health  = \WP_Site_Health::get_instance();
		$issues  = array( 'critical' => array(), 'recommended' => array(), 'good' => array() );

		// Run all direct tests synchronously.
		$tests = \WP_Site_Health::get_tests();

		// Filter to specific checks if requested.
		$check_filter = $params['checks'] ?? array();
		if ( ! empty( $check_filter ) && is_array( $check_filter ) ) {
			$tests['direct'] = array_intersect_key( $tests['direct'], array_flip( $check_filter ) );
		}

		foreach ( $tests['direct'] as $test_name => $test ) {
			try {
				if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$method = 'get_test_' . $test['test'];
					$result = $health->$method();
				} elseif ( is_callable( $test['test'] ) ) {
					$result = call_user_func( $test['test'] );
				} else {
					continue;
				}

				if ( empty( $result ) ) {
					continue;
				}

				$status = $result['status']          ?? 'good';
				$label  = $result['label']            ?? $test_name;
				$badge  = $result['badge']['label']   ?? '';
				$desc   = wp_strip_all_tags( $result['description'] ?? '' );

				$entry = array(
					'test'        => $test_name,
					'label'       => $label,
					'status'      => $status,
					'category'    => $badge,
					'description' => mb_substr( $desc, 0, 200 ),
				);

				if ( ! empty( $result['actions'] ) ) {
					$entry['action'] = wp_strip_all_tags( $result['actions'] );
				}

				$issues[ $status ][] = $entry;

			} catch ( \Throwable $e ) {
				// Skip failed tests silently.
			}
		}

		$critical_count    = count( $issues['critical'] );
		$recommended_count = count( $issues['recommended'] );
		$good_count        = count( $issues['good'] );

		$score = $critical_count === 0 && $recommended_count === 0
			? 100
			: max( 0, 100 - ( $critical_count * 25 ) - ( $recommended_count * 5 ) );

		// Category filter — return only issues at the requested severity.
		$cat_filter = sanitize_text_field( $params['category'] ?? '' );
		if ( $cat_filter && isset( $issues[ $cat_filter ] ) ) {
			$issues = array( $cat_filter => $issues[ $cat_filter ] );
		}

		$result = array(
			'score'       => $score,
			'summary'     => $critical_count > 0
				? sprintf( __( '%d critical issue(s) need attention.', 'pressark' ), $critical_count )
				: ( $recommended_count > 0
					? sprintf( __( 'No critical issues. %d improvements recommended.', 'pressark' ), $recommended_count )
					: __( 'All site health checks passed.', 'pressark' ) ),
			'critical'    => $issues['critical'] ?? array(),
			'recommended' => $issues['recommended'] ?? array(),
			'good_count'  => $cat_filter ? ( count( $issues['good'] ?? array() ) ) : $good_count,
			'environment' => function_exists( 'wp_get_environment_type' )
				? wp_get_environment_type()
				: 'production',
			'powered_by'  => 'WordPress Site Health (WP_Site_Health)',
		);

		if ( $cat_filter ) {
			$result['_category_filter'] = $cat_filter;
		}

		if ( ! $include_debug ) {
			return $result;
		}

		// Load debug data for full system info.
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		$debug = \WP_Debug_Data::debug_data();

		// Flatten to AI-friendly structure, prioritizing useful sections.
		$priority_sections = array(
			'wp-core'           => 'wordpress',
			'wp-server'         => 'server',
			'wp-database'       => 'database',
			'wp-active-theme'   => 'theme',
			'wp-plugins-active' => 'active_plugins',
			'wp-paths-sizes'    => 'disk',
			'wp-constants'      => 'constants',
			'wp-filesystem'     => 'filesystem',
			'wp-media'          => 'media',
		);

		$system_info = array();

		foreach ( $priority_sections as $section_key => $alias ) {
			if ( ! isset( $debug[ $section_key ] ) ) continue;

			$fields = $debug[ $section_key ]['fields'] ?? array();
			$flat   = array();

			foreach ( $fields as $key => $field ) {
				// Skip overly verbose fields.
				if ( in_array( $key, array( 'database_tables', 'db_queries' ), true ) ) continue;
				$value = $field['value'] ?? '';
				// Skip empty values.
				if ( $value === '' || $value === 'undefined' ) continue;
				$flat[ $key ] = $value;
			}

			if ( ! empty( $flat ) ) {
				$system_info[ $alias ] = $flat;
			}
		}

		$result['system_info'] = $system_info;
		$result['debug_note']  = __( 'system_info contains server config, plugin versions, disk usage, PHP limits, and more.', 'pressark' );

		return $result;
	}

	private function get_db_size(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- information_schema query, no user-controllable parameters.
		$size = $wpdb->get_var( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()" );
		return $size ? size_format( (int) $size ) : __( 'Unknown', 'pressark' );
	}

	// ── Scheduled Tasks ──────────────────────────────────────────────────

	public function list_scheduled_tasks( array $params ): array {
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			return array( 'success' => true, 'message' => __( 'No scheduled tasks found.', 'pressark' ), 'data' => array() );
		}

		$tasks     = array();
		$schedules = wp_get_schedules();

		foreach ( $crons as $timestamp => $cron_hooks ) {
			foreach ( $cron_hooks as $hook => $events ) {
				foreach ( $events as $key => $event ) {
					$schedule = $event['schedule'] ?? 'single';
					$interval = '';
					if ( $schedule && isset( $schedules[ $schedule ] ) ) {
						$interval = $schedules[ $schedule ]['display'];
					} elseif ( 'single' === $schedule || ! $schedule ) {
						$interval = __( 'One-time', 'pressark' );
					}

					$tasks[] = array(
						'hook'           => $hook,
						'timestamp'      => $timestamp,
						'next_run'       => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'next_run_human' => $timestamp > time()
							? sprintf( __( '%s from now', 'pressark' ), human_time_diff( $timestamp ) )
							: sprintf( __( '%s ago (overdue)', 'pressark' ), human_time_diff( $timestamp ) ),
						'schedule'       => $schedule ?: 'single',
						'interval'       => $interval,
						'args'           => $event['args'] ?? array(),
					);
				}
			}
		}

		usort( $tasks, function ( $a, $b ) {
			return $a['timestamp'] - $b['timestamp'];
		} );

		$overdue = count( array_filter( $tasks, function ( $t ) {
			return $t['timestamp'] < time();
		} ) );

		$cron_lock     = (float) get_transient( 'doing_cron' );
		$lock_timeout  = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? WP_CRON_LOCK_TIMEOUT : 60;
		$lock_stuck    = $cron_lock > 0 && ( $cron_lock + $lock_timeout < microtime( true ) );

		$return = array(
			'success' => true,
			'message' => $overdue > 0
				? sprintf( __( '%1$d scheduled tasks. %2$d overdue.', 'pressark' ), count( $tasks ), $overdue )
				: sprintf( __( '%d scheduled tasks. All on schedule.', 'pressark' ), count( $tasks ) ),
			'data'    => $tasks,
		);

		$all_schedules = array();
		foreach ( wp_get_schedules() as $key => $schedule ) {
			$all_schedules[] = array(
				'name'     => $key,
				'label'    => $schedule['display'],
				'interval' => $schedule['interval'],
				'every'    => $this->humanize_interval( $schedule['interval'] ),
			);
		}
		usort( $all_schedules, fn( $a, $b ) => $a['interval'] <=> $b['interval'] );
		$return['registered_schedules'] = $all_schedules;

		$return['cron_health'] = array(
			'currently_running' => wp_doing_cron(),
			'lock_stuck'        => $lock_stuck,
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alt_cron'          => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'diagnosis'         => array_values( array_filter( array(
				( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
					? __( 'DISABLE_WP_CRON is true — WordPress cron requires a system-level cron job (crontab) to run.', 'pressark' )
					: null,
				$lock_stuck
					? __( 'Cron lock is stuck. WP-Cron is locked but the process appears to have died. Run wp_unschedule_hook to clear specific stuck hooks.', 'pressark' )
					: null,
				( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON )
					? __( 'ALTERNATE_WP_CRON is enabled — cron runs on redirect instead of background HTTP request.', 'pressark' )
					: null,
			) ) ),
		);

		return $return;
	}

	public function manage_scheduled_task( array $params ): array {
		$task_action = $params['action'] ?? '';
		$hook        = sanitize_text_field( $params['hook'] ?? '' );

		if ( empty( $hook ) ) {
			return array( 'success' => false, 'message' => __( 'Hook name is required.', 'pressark' ) );
		}

		if ( 'run' === $task_action ) {
			do_action( $hook );
			return array( 'success' => true, 'message' => sprintf( __( 'Executed scheduled task "%s" immediately.', 'pressark' ), $hook ) );
		}

		if ( 'remove' === $task_action ) {
			$timestamp = intval( $params['timestamp'] ?? 0 );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			} else {
				wp_clear_scheduled_hook( $hook );
			}
			return array( 'success' => true, 'message' => sprintf( __( 'Unscheduled task "%s".', 'pressark' ), $hook ) );
		}

		if ( 'remove_all' === $task_action ) {
			$cleared = wp_unschedule_hook( sanitize_key( $params['hook'] ) );
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: number of cleared instances, 2: hook name */
					__( 'Cleared %1$d scheduled instance(s) of "%2$s".', 'pressark' ),
					(int) $cleared,
					$params['hook']
				),
			);
		}

		return array( 'success' => false, 'message' => __( 'Invalid action. Use "run", "remove", or "remove_all".', 'pressark' ) );
	}

	private function humanize_interval( int $seconds ): string {
		if ( $seconds < 60 )           return sprintf( __( '%d seconds', 'pressark' ), $seconds );
		if ( $seconds < 3600 )         return sprintf( __( '%d minutes', 'pressark' ), round( $seconds / 60 ) );
		if ( $seconds < 86400 )        return sprintf( __( '%s hours', 'pressark' ), round( $seconds / 3600, 1 ) );
		return sprintf( __( '%s days', 'pressark' ), round( $seconds / 86400, 1 ) );
	}

	// ── Plugins ──────────────────────────────────────────────────────────

	public function list_plugins( array $params ): array {
		$manager = new PressArk_Plugins();
		$plugins = $manager->list_all();

		$active   = count( array_filter( $plugins, fn( $p ) => $p['active'] ) );
		$inactive = count( $plugins ) - $active;
		$updates  = count( array_filter( $plugins, fn( $p ) => $p['update_available'] ) );

		return array(
			'success' => true,
			'message' => $updates
				? sprintf( __( '%1$d plugins installed (%2$d active, %3$d inactive, %4$d updates available).', 'pressark' ), count( $plugins ), $active, $inactive, $updates )
				: sprintf( __( '%1$d plugins installed (%2$d active, %3$d inactive).', 'pressark' ), count( $plugins ), $active, $inactive ),
			'data'    => $plugins,
		);
	}

	public function toggle_plugin( array $params ): array {
		$manager     = new PressArk_Plugins();
		$plugin_file = sanitize_text_field( $params['plugin_file'] ?? '' );
		$activate    = (bool) ( $params['activate'] ?? true );

		if ( empty( $plugin_file ) ) {
			return array( 'success' => false, 'message' => __( 'Plugin file path is required.', 'pressark' ) );
		}

		// Prevent PressArk from deactivating itself.
		if ( ! $activate && defined( 'PRESSARK_FILE' ) && plugin_basename( PRESSARK_FILE ) === $plugin_file ) {
			return array(
				'success' => false,
				'message' => __( 'PressArk cannot deactivate itself. Deactivate it from the Plugins page.', 'pressark' ),
			);
		}

		$result = $manager->toggle( $plugin_file, $activate );

		if ( $result['success'] ) {
			$this->logger->log(
				'toggle_plugin',
				null,
				'plugin',
				wp_json_encode( array( 'file' => $plugin_file, 'was_active' => ! $activate ) ),
				wp_json_encode( array( 'file' => $plugin_file, 'active' => $activate ) )
			);
		}

		return $result;
	}

	// ── Themes ───────────────────────────────────────────────────────────

	public function list_themes( array $params ): array {
		$manager = new PressArk_Themes();
		$themes  = $manager->list_all();
		$active  = wp_get_theme();
		return array(
			'success' => true,
			'message' => sprintf( __( '%1$d themes installed. Active: "%2$s".', 'pressark' ), count( $themes ), $active->get( 'Name' ) ),
			'data'    => $themes,
		);
	}

	public function get_theme_settings( array $params ): array {
		$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

		if ( $is_block_theme ) {
			$global_settings = wp_get_global_settings();
			$global_styles   = wp_get_global_styles();

			$color_palette = wp_get_global_settings( array( 'color', 'palette' ) );
			$font_sizes    = wp_get_global_settings( array( 'typography', 'fontSizes' ) );
			$spacing       = wp_get_global_settings( array( 'spacing', 'spacingSizes' ) );

			$colors = array();
			$theme_palette = $color_palette['theme'] ?? array();
			foreach ( $theme_palette as $color ) {
				$colors[] = array(
					'slug'  => $color['slug'],
					'name'  => $color['name'],
					'color' => $color['color'],
				);
			}
			$custom_palette = $color_palette['custom'] ?? array();
			foreach ( $custom_palette as $color ) {
				$colors[] = array(
					'slug'   => $color['slug'],
					'name'   => $color['name'],
					'color'  => $color['color'],
					'custom' => true,
				);
			}

			$fonts = array();
			foreach ( ( $font_sizes['theme'] ?? array() ) as $size ) {
				$fonts[] = array(
					'slug' => $size['slug'],
					'name' => $size['name'],
					'size' => $size['size'],
				);
			}

			return array(
				'success'       => true,
				'theme_type'    => 'block',
				'editor'        => __( 'Site Editor (not Customizer)', 'pressark' ),
				'edit_url'      => admin_url( 'site-editor.php' ),
				'color_palette' => $colors,
				'font_sizes'    => $fonts,
				'spacing_sizes' => $spacing['theme'] ?? array(),
				'note'          => __( 'This is a block theme. Settings are managed via the Site Editor, not the Customizer. Use the Site Editor for design changes.', 'pressark' ),
				'raw_settings'  => array_keys( $global_settings ),
				'message'       => sprintf( __( 'Block theme detected. %1$d colors, %2$d font sizes. Use Site Editor for customization.', 'pressark' ), count( $colors ), count( $fonts ) ),
			);
		}

		// Classic theme path.
		$manager  = new PressArk_Themes();
		$settings = $manager->get_customizer_settings();

		$supported_features = array();
		$features_to_check  = array(
			'post-thumbnails', 'post-formats', 'custom-header', 'custom-background',
			'custom-logo', 'editor-color-palette', 'editor-font-sizes',
			'align-wide', 'responsive-embeds',
		);
		foreach ( $features_to_check as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$support = get_theme_support( $feature );
				$supported_features[ $feature ] = is_array( $support ) && count( $support ) === 1
					? $support[0]
					: ( $support ?: true );
			}
		}

		return array(
			'success'            => true,
			'theme_type'         => 'classic',
			'supported_features' => $supported_features,
			'is_child_theme'     => is_child_theme(),
			'parent_theme'       => is_child_theme() ? get_template() : null,
			'data'               => $settings,
			'message'            => sprintf( __( '%d theme customizer settings.', 'pressark' ), count( $settings ) ),
		);
	}

	/**
	 * Discover all Customizer settings for the active theme.
	 * Returns panels, sections, controls with labels, types, and current values.
	 * Cached for 1 hour. Only for classic (non-block) themes.
	 */
	public function get_customizer_schema( array $params = array() ): array {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return array(
				'success' => false,
				'message' => __( 'This is a block theme. Use get_theme_settings to see design settings via the Site Editor.', 'pressark' ),
			);
		}

		$cache_key = 'pressark_customizer_schema_' . get_stylesheet();
		$cached    = get_transient( $cache_key );
		if ( $cached && empty( $params['refresh'] ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		try {
			if ( ! class_exists( 'WP_Customize_Manager' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
			}

			$wp_customize = new \WP_Customize_Manager( array(
				'messenger_channel' => false,
			) );

			do_action( 'customize_register', $wp_customize );

			$panels = array();
			foreach ( $wp_customize->panels() as $panel ) {
				$panels[ $panel->id ] = $panel->title;
			}

			$sections_map = array();
			foreach ( $wp_customize->sections() as $section ) {
				$sections_map[ $section->id ] = array(
					'title' => $section->title,
					'panel' => $panels[ $section->panel ] ?? null,
				);
			}

			$settings = array();
			foreach ( $wp_customize->controls() as $control ) {
				$setting    = $control->settings['default'] ?? null;
				$setting_id = $setting ? $setting->id : $control->id;
				$section    = $sections_map[ $control->section ] ?? null;

				if ( str_starts_with( $setting_id, 'nav_menu' ) ) continue;
				if ( str_starts_with( $setting_id, 'widget_' ) ) continue;

				$settings[] = array(
					'id'           => $setting_id,
					'label'        => $control->label ?: $setting_id,
					'description'  => $control->description ?: null,
					'type'         => $control->type,
					'section'      => $section['title'] ?? $control->section,
					'panel'        => $section['panel'] ?? null,
					'choices'      => ! empty( $control->choices ) ? $control->choices : null,
					'current'      => get_theme_mod( $setting_id ) ?: ( $setting ? $setting->default : null ),
					'default'      => $setting ? $setting->default : null,
					'live_preview' => $setting && $setting->transport === 'postMessage',
				);
			}

			$grouped = array();
			foreach ( $settings as $s ) {
				$group_key              = $s['panel'] ?: $s['section'] ?: __( 'General', 'pressark' );
				$grouped[ $group_key ][] = $s;
			}

			$result = array(
				'success'       => true,
				'theme'         => wp_get_theme()->get( 'Name' ),
				'setting_count' => count( $settings ),
				'panels'        => array_values( $panels ),
				'grouped'       => $grouped,
				'hint'          => __( 'Use update_theme_setting with the setting id to change any value.', 'pressark' ),
				'from_cache'    => false,
			);

			set_transient( $cache_key, $result, HOUR_IN_SECONDS );

			return $result;

		} catch ( \Throwable $e ) {
			return array(
				'success'  => false,
				'error'    => sprintf( __( 'Could not load Customizer schema: %s', 'pressark' ), $e->getMessage() ),
				'fallback' => __( 'Use get_theme_settings to see current theme_mods values.', 'pressark' ),
			);
		}
	}

	public function update_theme_setting( array $params ): array {
		$manager = new PressArk_Themes();
		$key     = sanitize_text_field( $params['setting_name'] ?? '' );
		$value   = $params['value'] ?? '';

		if ( empty( $key ) ) {
			return array( 'success' => false, 'message' => __( 'Setting name is required.', 'pressark' ) );
		}

		$result = $manager->update_customizer_setting( $key, $value );

		if ( $result['success'] ) {
			$this->logger->log(
				'update_theme_setting',
				null,
				'theme_mod',
				wp_json_encode( array( 'key' => $key, 'value' => $result['previous'] ) ),
				wp_json_encode( array( 'key' => $key, 'value' => $value ) )
			);
			$result['message'] = sprintf( __( 'Updated theme setting "%s".', 'pressark' ), $key );
		}

		return $result;
	}

	public function switch_theme_action( array $params ): array {
		$manager   = new PressArk_Themes();
		$slug      = sanitize_text_field( $params['theme_slug'] ?? '' );

		if ( empty( $slug ) ) {
			return array( 'success' => false, 'message' => __( 'Theme slug is required.', 'pressark' ) );
		}

		$result = $manager->switch_theme( $slug );

		if ( $result['success'] ) {
			$this->logger->log(
				'switch_theme',
				null,
				'theme',
				wp_json_encode( array( 'stylesheet' => $result['previous_theme'] ) ),
				wp_json_encode( array( 'stylesheet' => $slug ) )
			);
		}

		return $result;
	}

	// ── Database Maintenance ─────────────────────────────────────────────

	public function database_stats( array $params ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- information_schema query, no user-controllable parameters.
		$tables = $wpdb->get_results(
			"SELECT TABLE_NAME AS tbl_name, TABLE_ROWS AS tbl_rows, ROUND(data_length/1024/1024, 2) AS data_mb, ROUND(index_length/1024/1024, 2) AS index_mb FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY data_length DESC"
		);

		$total_size = 0;
		$formatted  = array();
		foreach ( $tables as $t ) {
			$size        = (float) $t->data_mb + (float) $t->index_mb;
			$total_size += $size;
			$formatted[] = array(
				'table'   => $t->tbl_name,
				'rows'    => (int) $t->tbl_rows,
				'size_mb' => round( $size, 2 ),
			);
		}

		// Cleanup candidates.
		$revisions   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision'
		) );
		$auto_drafts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'auto-draft'
		) );
		$trashed     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash'
		) );
		$spam        = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'spam'
		) );
		$transients = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < UNIX_TIMESTAMP()",
			$wpdb->esc_like( '_transient_timeout_' ) . '%'
		) );

		// Revision intelligence — check limits and PressArk checkpoints.
		$sample_post   = get_posts( array( 'numberposts' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids' ) );
		$rev_limit     = $sample_post ? wp_revisions_to_keep( get_post( $sample_post[0] ) ) : -1;
		$pw_checkpoints = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_pressark_checkpoint'
		) );

		$revision_info = array(
			'total'            => $revisions,
			'pressark'         => $pw_checkpoints,
			'rev_limit'        => $rev_limit,
			'rev_limit_label'  => $rev_limit === -1 ? __( 'unlimited', 'pressark' ) : (string) $rev_limit,
		);

		if ( $rev_limit !== -1 && $rev_limit < 10 ) {
			$revision_info['warning'] = sprintf(
				__( 'Revision limit is %d. AI checkpoints may be pruned quickly — consider increasing WP_POST_REVISIONS.', 'pressark' ),
				$rev_limit
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total size in MB, 2: table count, 3: revisions, 4: auto-drafts, 5: trashed, 6: spam comments, 7: expired transients */
				__( 'Database: %1$s MB total, %2$d tables. Cleanup candidates: %3$d revisions, %4$d auto-drafts, %5$d trashed, %6$d spam comments, %7$d expired transients.', 'pressark' ),
				round( $total_size, 1 ),
				count( $formatted ),
				$revisions,
				$auto_drafts,
				$trashed,
				$spam,
				$transients
			),
			'data'    => array(
				'total_size_mb'      => round( $total_size, 1 ),
				'tables'             => array_slice( $formatted, 0, 20 ),
				'cleanup_candidates' => compact( 'revisions', 'auto_drafts', 'trashed', 'spam', 'transients' ),
				'revision_info'      => $revision_info,
			),
		);
	}

	public function cleanup_database( array $params ): array {
		$cap_error = $this->require_cap( 'manage_options' );
		if ( $cap_error ) {
			return $cap_error;
		}

		global $wpdb;
		$items   = $params['items'] ?? array( 'revisions', 'auto_drafts', 'trashed', 'spam_comments', 'expired_transients', 'orphaned_meta' );
		$cleaned = array();

		if ( in_array( 'revisions', $items, true ) ) {
			$revision_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 500", 'revision'
			) );
			$deleted = 0;
			foreach ( $revision_ids as $rev_id ) {
				if ( wp_delete_post_revision( (int) $rev_id ) ) {
					$deleted++;
				}
			}
			$remaining = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision'
			) );
			$cleaned[] = array(
				'item'      => 'revisions',
				'deleted'   => $deleted,
				'remaining' => $remaining,
				'note'      => $remaining > 0
					? sprintf( __( 'Deleted %d (batched to 500 max). Run again for more.', 'pressark' ), $deleted )
					: __( 'All revisions deleted.', 'pressark' ),
			);
		}
		if ( in_array( 'auto_drafts', $items, true ) ) {
			$auto_draft_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT 500",
				'auto-draft'
			) );
			$deleted = 0;
			foreach ( $auto_draft_ids as $ad_id ) {
				if ( wp_delete_post( (int) $ad_id, true ) ) {
					$deleted++;
				}
			}
			$cleaned[] = array( 'item' => 'auto_drafts', 'deleted' => $deleted );
		}
		if ( in_array( 'trashed', $items, true ) ) {
			$trashed_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT 500",
				'trash'
			) );
			$deleted = 0;
			foreach ( $trashed_ids as $tr_id ) {
				if ( wp_delete_post( (int) $tr_id, true ) ) {
					$deleted++;
				}
			}
			$remaining = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash'
			) );
			$cleaned[] = array(
				'item'      => 'trashed',
				'deleted'   => $deleted,
				'remaining' => $remaining,
				'note'      => $remaining > 0
					? sprintf( 'Deleted %d (batched to 500). Run again for more.', $deleted )
					: 'All trashed posts permanently deleted.',
			);
		}
		if ( in_array( 'spam_comments', $items, true ) ) {
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_approved = %s", 'spam' ) );
			if ( false === $count || $wpdb->last_error ) {
				$cleaned[] = array( 'item' => 'spam_comments', 'error' => $wpdb->last_error ?: __( 'Query returned false', 'pressark' ) );
			} else {
				$cleaned[] = array( 'item' => 'spam_comments', 'deleted' => $count );
			}
		}
		if ( in_array( 'expired_transients', $items, true ) ) {
			$count_before = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()",
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			) );
			delete_expired_transients( true );
			$cleaned[] = array(
				'item'    => 'expired_transients',
				'deleted' => $count_before,
				'message' => sprintf( __( '%d expired transients deleted.', 'pressark' ), $count_before ),
			);
		}
		if ( in_array( 'orphaned_meta', $items, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- JOIN on core tables, no user-controllable parameters.
			$count = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
			if ( false === $count || $wpdb->last_error ) {
				$cleaned[] = array( 'item' => 'orphaned_meta', 'error' => $wpdb->last_error ?: __( 'Query returned false', 'pressark' ) );
			} else {
				$cleaned[] = array( 'item' => 'orphaned_meta', 'deleted' => $count );
			}
		}

		// Build human-readable summary.
		$summary_parts = array();
		foreach ( $cleaned as $entry ) {
			if ( is_array( $entry ) ) {
				if ( ! empty( $entry['error'] ) ) {
					/* translators: 1: cleanup item name, 2: error message */
					$summary_parts[] = sprintf( __( '%1$s: ERROR — %2$s', 'pressark' ), $entry['item'], $entry['error'] );
				} elseif ( ! empty( $entry['note'] ) ) {
					$summary_parts[] = $entry['note'];
				} elseif ( isset( $entry['message'] ) ) {
					$summary_parts[] = $entry['message'];
				} else {
					/* translators: 1: number deleted, 2: item type name */
					$summary_parts[] = sprintf( __( '%1$d %2$s', 'pressark' ), $entry['deleted'] ?? 0, $entry['item'] ?? __( 'items', 'pressark' ) );
				}
			} else {
				$summary_parts[] = $entry;
			}
		}

		return array(
			'success' => true,
			'data'    => $cleaned,
			'message' => sprintf( __( 'Database cleaned: %s.', 'pressark' ), implode( ', ', $summary_parts ) ),
		);
	}

	public function optimize_database( array $params ): array {
		global $wpdb;

		$table_engines = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME, ENGINE, DATA_LENGTH, INDEX_LENGTH, DATA_FREE
			 FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = %s
			 ORDER BY DATA_FREE DESC",
			DB_NAME
		), OBJECT_K );

		$optimized = array();
		$errors    = array();

		foreach ( $table_engines as $table_name => $info ) {
			$tname  = esc_sql( $table_name );
			$engine = strtolower( $info->ENGINE ?? '' );

			if ( $engine === 'myisam' ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on esc_sql'd identifier.
				$wpdb->query( "OPTIMIZE TABLE `{$tname}`" );
				$action = 'optimized';
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL on esc_sql'd identifier.
				$wpdb->query( "ANALYZE TABLE `{$tname}`" );
				$action = 'analyzed';
			}

			if ( $wpdb->last_error ) {
				$errors[] = $table_name . ': ' . $wpdb->last_error;
				continue;
			}

			$freed_kb = round( ( (int) $info->DATA_FREE ) / 1024, 1 );
			if ( $freed_kb > 0 ) {
				$optimized[] = array(
					'table'  => $table_name,
					'action' => $action,
					'freed'  => sprintf( __( '%sKB reclaimable', 'pressark' ), $freed_kb ),
				);
			}
		}

		return array(
			'success'   => empty( $errors ),
			'optimized' => $optimized,
			'errors'    => $errors,
			'message'   => sprintf(
				/* translators: 1: total tables, 2: MyISAM count, 3: InnoDB count */
				__( 'Processed %1$d tables (%2$d MyISAM optimized, %3$d InnoDB analyzed).', 'pressark' ),
				count( $table_engines ),
				count( array_filter( $optimized, fn( $t ) => $t['action'] === 'optimized' ) ),
				count( array_filter( $optimized, fn( $t ) => $t['action'] === 'analyzed' ) )
			),
		);
	}

	// ── Site Profile ─────────────────────────────────────────────────────

	public function view_site_profile( array $params ): array {
		$profiler = new PressArk_Site_Profile();
		$profile  = $profiler->get();
		if ( ! $profile ) {
			return array( 'success' => false, 'message' => __( 'Site profile not yet generated. Generating now...', 'pressark' ) );
		}
		return array(
			'success' => true,
			'message' => sprintf( __( 'Site profile generated on %s', 'pressark' ), $profile['generated_at'] ),
			'data'    => $profile,
		);
	}

	public function refresh_site_profile( array $params ): array {
		$profiler = new PressArk_Site_Profile();
		$profile  = $profiler->generate();
		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: detected industry, 2: tone, 3: number of pages */
				__( 'Site profile regenerated. Detected industry: %1$s, tone: %2$s, %3$d pages analyzed.', 'pressark' ),
				$profile['identity']['detected_industry'],
				$profile['content_dna']['tone'],
				$profile['content_dna']['total_pages']
			),
		);
	}

	// ── Log Analysis ─────────────────────────────────────────────────────

	public function list_logs( array $params ): array {
		$analyzer = new PressArk_Log_Analyzer();
		$logs     = $analyzer->get_available_logs();
		return array(
			'success' => true,
			'message' => sprintf( __( '%d log files found.', 'pressark' ), count( $logs ) ),
			'data'    => $logs,
		);
	}

	public function read_log_action( array $params ): array {
		$analyzer = new PressArk_Log_Analyzer();
		$log      = sanitize_text_field( $params['log'] ?? 'debug.log' );
		$lines    = min( intval( $params['lines'] ?? 50 ), 200 );
		$filter   = ! empty( $params['filter'] ) ? sanitize_text_field( $params['filter'] ) : null;
		return $analyzer->read_log( $log, $lines, $filter );
	}

	public function analyze_logs( array $params ): array {
		$analyzer = new PressArk_Log_Analyzer();
		$log      = sanitize_text_field( $params['log'] ?? 'debug.log' );
		return $analyzer->analyze( $log );
	}

	public function clear_log( array $params ): array {
		$log = sanitize_text_field( $params['log'] ?? '' );
		if ( 'debug.log' !== $log ) {
			return array( 'success' => false, 'message' => __( 'Only debug.log can be cleared through PressArk for safety.', 'pressark' ) );
		}
		$filepath = WP_CONTENT_DIR . '/debug.log';
		if ( ! file_exists( $filepath ) ) {
			return array( 'success' => false, 'message' => __( 'debug.log does not exist.', 'pressark' ) );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		$wp_filesystem->put_contents( $filepath, '', FS_CHMOD_FILE );
		return array( 'success' => true, 'message' => __( 'debug.log cleared.', 'pressark' ) );
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	/**
	 * Preview for update_site_settings.
	 */
	public function preview_update_site_settings( array $params, array $action ): array {
		$changes  = array();
		$settings = $params['changes'] ?? ( $action['changes'] ?? array() );
		foreach ( $settings as $key => $value ) {
			$changes[] = array(
				'field'  => ucfirst( str_replace( '_', ' ', $key ) ),
				'before' => get_option( $key, __( '(empty)', 'pressark' ) ),
				'after'  => $value,
			);
		}
		return array( 'changes' => $changes );
	}

	/**
	 * Preview for update_menu.
	 */
	public function preview_update_menu( array $params, array $action ): array {
		$op        = $params['operation'] ?? '';
		$menu_name = '';
		if ( ! empty( $params['menu_id'] ) ) {
			$menu      = wp_get_nav_menu_object( absint( $params['menu_id'] ) );
			$menu_name = $menu ? $menu->name : '#' . $params['menu_id'];
		}
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Menu:', 'pressark' ) . ' ' . ( $menu_name ?: __( 'New Menu', 'pressark' ) ),
					'before' => __( 'Current state', 'pressark' ),
					'after'  => ucfirst( str_replace( '_', ' ', $op ) ) . ( ! empty( $params['name'] ) ? ': ' . $params['name'] : '' ),
				),
			),
		);
	}

	/**
	 * Preview for update_user.
	 */
	public function preview_update_user( array $params, array $action ): array {
		$u_id     = intval( $params['user_id'] ?? 0 );
		$u_data   = get_userdata( $u_id );
		$u_name   = $u_data ? $u_data->display_name : '#' . $u_id;
		$u_fields = array( 'display_name', 'first_name', 'last_name', 'role', 'description', 'url' );
		$changes  = array();

		foreach ( $u_fields as $f ) {
			if ( isset( $params[ $f ] ) ) {
				$current = $u_data ? ( $u_data->$f ?? '' ) : '';
				if ( 'role' === $f && $u_data ) {
					$current = implode( ', ', $u_data->roles );
				}
				$changes[] = array(
					'field'  => ucfirst( str_replace( '_', ' ', $f ) ),
					'before' => $current ?: __( '(empty)', 'pressark' ),
					'after'  => $params[ $f ],
				);
			}
		}

		if ( empty( $changes ) ) {
			$changes[] = array( 'field' => __( 'User', 'pressark' ), 'before' => $u_name, 'after' => __( 'Updated', 'pressark' ) );
		}

		return array( 'changes' => $changes );
	}

	/**
	 * Preview for manage_scheduled_task.
	 */
	public function preview_manage_scheduled_task( array $params, array $action ): array {
		$task_action = $params['action'] ?? '';
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Scheduled Task', 'pressark' ),
					'before' => $params['hook'] ?? __( 'unknown', 'pressark' ),
					'after'  => 'run' === $task_action ? __( 'Execute immediately', 'pressark' ) : __( 'Remove from schedule', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for toggle_plugin.
	 */
	public function preview_toggle_plugin( array $params, array $action ): array {
		$pf = $params['plugin_file'] ?? ( $action['plugin_file'] ?? '' );
		$pa = (bool) ( $params['activate'] ?? ( $action['activate'] ?? true ) );
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Plugin', 'pressark' ),
					'before' => $pf,
					'after'  => $pa ? __( 'Activate', 'pressark' ) : __( 'Deactivate', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for update_theme_setting.
	 */
	public function preview_update_theme_setting( array $params, array $action ): array {
		$ts_key = $params['setting_name'] ?? ( $action['setting_name'] ?? '' );
		$ts_val = $params['value'] ?? ( $action['value'] ?? '' );
		return array(
			'changes' => array(
				array(
					'field'  => sprintf( __( 'Theme Setting: %s', 'pressark' ), $ts_key ),
					'before' => (string) get_theme_mod( $ts_key, __( '(empty)', 'pressark' ) ),
					'after'  => (string) $ts_val,
				),
			),
		);
	}

	/**
	 * Preview for switch_theme_action.
	 */
	public function preview_switch_theme_action( array $params, array $action ): array {
		$new_theme = $params['theme_slug'] ?? ( $action['theme_slug'] ?? '' );
		return array(
			'changes' => array(
				array(
					'field'  => __( 'Switch Theme', 'pressark' ),
					'before' => wp_get_theme()->get( 'Name' ),
					'after'  => $new_theme,
				),
			),
		);
	}

	/**
	 * Preview for cleanup_database.
	 */
	public function preview_cleanup_database( array $params, array $action ): array {
		$db_items_raw = $params['items'] ?? ( $action['items'] ?? array( 'revisions', 'auto_drafts', 'trashed', 'spam_comments', 'expired_transients', 'orphaned_meta' ) );
		$db_items = array();
		foreach ( $db_items_raw as $k => $v ) {
			$db_items[] = is_string( $k ) ? $k : $v;
		}

		global $wpdb;
		$cleanup_counts = array(
			'revisions'          => array( 'label' => __( 'Post Revisions', 'pressark' ), 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'revision' ) ), 'unit' => __( 'revisions', 'pressark' ) ),
			'auto_drafts'        => array( 'label' => __( 'Auto-Drafts', 'pressark' ), 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'auto-draft' ) ), 'unit' => __( 'auto-drafts', 'pressark' ) ),
			'trashed'            => array( 'label' => __( 'Trashed Posts', 'pressark' ), 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash' ) ), 'unit' => __( 'trashed posts', 'pressark' ) ),
			'spam_comments'      => array( 'label' => __( 'Spam Comments', 'pressark' ), 'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", 'spam' ) ), 'unit' => __( 'spam comments', 'pressark' ) ),
			'expired_transients' => array(
				'label' => __( 'Expired Transients', 'pressark' ),
				'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP()", $wpdb->esc_like( '_transient_timeout_' ) . '%' ) ),
				'unit'  => __( 'expired transients', 'pressark' ),
			),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- JOIN on core tables, no user-controllable parameters.
			'orphaned_meta'      => array( 'label' => __( 'Orphaned Post Meta', 'pressark' ), 'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" ), 'unit' => __( 'orphaned meta rows', 'pressark' ) ),
		);

		$changes = array();
		foreach ( $db_items as $db_item ) {
			$info = $cleanup_counts[ $db_item ] ?? null;
			if ( ! $info ) {
				continue;
			}
			$count     = $info['count'];
			$changes[] = array(
				'field'  => $info['label'],
				'before' => $count > 0
					? sprintf( __( '%1$d %2$s found', 'pressark' ), $count, $info['unit'] )
					: sprintf( __( '0 %s', 'pressark' ), $info['unit'] ),
				'after'  => $count > 0 ? __( 'Will be deleted', 'pressark' ) : __( 'Nothing to clean', 'pressark' ),
			);
		}

		return array( 'post_title' => __( 'Database Cleanup', 'pressark' ), 'post_id' => 0, 'changes' => $changes );
	}

	/**
	 * Preview for optimize_database.
	 */
	public function preview_optimize_database( array $params, array $action ): array {
		return array(
			'changes' => array(
				array( 'field' => __( 'Optimize Database', 'pressark' ), 'before' => __( 'All tables', 'pressark' ), 'after' => __( 'OPTIMIZE TABLE on all tables', 'pressark' ) ),
			),
		);
	}
}
