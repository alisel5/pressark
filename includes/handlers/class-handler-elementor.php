<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Elementor domain action handlers.
 *
 * Handles: elementor_read_page, elementor_find_widgets, elementor_edit_widget,
 *          elementor_add_widget, elementor_add_container, elementor_list_templates,
 *          elementor_create_from_template, elementor_get_styles, elementor_find_replace,
 *          elementor_audit_page, elementor_site_pages, elementor_global_styles,
 *          elementor_create_page, elementor_get_widget_schema, elementor_clone_page,
 *          elementor_manage_conditions, elementor_get_breakpoints,
 *          elementor_list_dynamic_tags, elementor_set_dynamic_tag,
 *          elementor_read_form, elementor_edit_form_field, elementor_set_visibility,
 *          elementor_list_popups, elementor_edit_popup_trigger.
 *
 * @since 2.7.0
 */

class PressArk_Handler_Elementor extends PressArk_Handler_Base {

	/**
	 * v3.7.0: Minimum Elementor version for write operations.
	 * Below this, read operations still work, but writes are blocked
	 * because the _elementor_data save pipeline is unreliable.
	 */
	const MIN_WRITE_VERSION = '3.16.0';

	/**
	 * v3.7.0: Guard clause for Elementor write operations.
	 * Checks Elementor version compatibility before allowing mutations.
	 *
	 * @return array|null Error array if version too old, null if OK.
	 */
	private function require_elementor_write(): ?array {
		$err = $this->require_elementor();
		if ( $err ) return $err;

		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, self::MIN_WRITE_VERSION, '<' ) ) {
			return $this->error( sprintf(
				/* translators: 1: current Elementor version, 2: minimum required version */
				__( 'Elementor %1$s is too old for safe write operations. Please update to %2$s or newer.', 'pressark' ),
				ELEMENTOR_VERSION, self::MIN_WRITE_VERSION
			) );
		}

		return null;
	}

	/**
	 * v3.7.0: Acquire an advisory lock on an Elementor post to prevent
	 * concurrent edits (PressArk + user in Elementor editor simultaneously).
	 * Uses WordPress post locking mechanism which Elementor respects.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Error if post is locked by another user, null if OK.
	 */
	private function check_elementor_lock( int $post_id ): ?array {
		$lock = wp_check_post_lock( $post_id );
		if ( $lock && $lock !== get_current_user_id() ) {
			$locker = get_userdata( $lock );
			/* translators: %d: user ID number */
			$name   = $locker ? $locker->display_name : sprintf( __( 'user #%d', 'pressark' ), $lock );
			return $this->error( sprintf(
				/* translators: %s: display name of the user currently editing the page */
				__( 'This page is currently being edited by %s in Elementor. Wait for them to finish to avoid data loss.', 'pressark' ),
				$name
			) );
		}
		return null;
	}

	public function elementor_read_page( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor   = new PressArk_Elementor();
		$post_id     = intval( $params['post_id'] ?? 0 );
		$widget_type = sanitize_text_field( $params['widget_type'] ?? '' );
		$max_depth   = absint( $params['max_depth'] ?? 0 );
		$result      = $elementor->read_page( $post_id );
		if ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}

		// Filter by widget_type if specified.
		if ( $widget_type && ! empty( $result['structure'] ) && is_array( $result['structure'] ) ) {
			$result['structure'] = $this->filter_elementor_by_type( $result['structure'], $widget_type );
			$result['_widget_type_filter'] = $widget_type;
		}

		// Limit nesting depth if specified.
		if ( $max_depth > 0 && ! empty( $result['structure'] ) && is_array( $result['structure'] ) ) {
			$result['structure'] = $this->limit_elementor_depth( $result['structure'], $max_depth );
			$result['_max_depth'] = $max_depth;
		}

		$stats = $result['stats'] ?? array();
		return array(
			'success' => true,
			/* translators: 1: page title, 2: widget count, 3: word count */
			'message' => sprintf( __( 'Elementor page "%1$s" — %2$s widgets, %3$s words.', 'pressark' ), $result['title'], $stats['widgets'] ?? 0, $stats['words'] ?? 0 ),
			'data'    => $result,
		);
	}

	/**
	 * Recursively filter Elementor structure to only elements of a given widget type.
	 */
	private function filter_elementor_by_type( array $elements, string $type ): array {
		$filtered = array();
		foreach ( $elements as $el ) {
			if ( ( $el['widgetType'] ?? $el['widget_type'] ?? '' ) === $type ) {
				$filtered[] = $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$child_matches = $this->filter_elementor_by_type( $el['elements'], $type );
				$filtered = array_merge( $filtered, $child_matches );
			}
		}
		return $filtered;
	}

	/**
	 * Recursively limit nesting depth of Elementor structure.
	 */
	private function limit_elementor_depth( array $elements, int $max_depth, int $current = 1 ): array {
		foreach ( $elements as &$el ) {
			if ( $current >= $max_depth ) {
				$child_count = isset( $el['elements'] ) && is_array( $el['elements'] ) ? count( $el['elements'] ) : 0;
				unset( $el['elements'] );
				if ( $child_count > 0 ) {
					$el['_children_truncated'] = $child_count;
				}
			} elseif ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $this->limit_elementor_depth( $el['elements'], $max_depth, $current + 1 );
			}
		}
		return $elements;
	}

	public function elementor_find_widgets( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor   = new PressArk_Elementor();
		$post_id     = intval( $params['post_id'] ?? 0 );
		$widget_type = sanitize_text_field( $params['widget_type'] ?? '' );
		$search      = sanitize_text_field( $params['search'] ?? '' );
		$section_id  = sanitize_text_field( $params['section_id'] ?? '' );
		$result      = $elementor->find_widgets( $post_id, $widget_type, $search, $section_id );
		if ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}
		$count = $result['count'] ?? 0;
		$label = $widget_type ?: __( 'all', 'pressark' );
		return array(
			'success' => true,
			/* translators: 1: number of widgets found, 2: widget type label */
			'message' => sprintf( __( '%1$s %2$s widget(s) found.', 'pressark' ), $count, $label ),
			'data'    => $result,
		);
	}

	public function elementor_edit_widget( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$post_id   = intval( $params['post_id'] ?? 0 );

		// v3.7.0: Check for concurrent Elementor editor sessions.
		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;

		$elementor = new PressArk_Elementor();
		$widget_id = sanitize_text_field( $params['widget_id'] ?? '' );
		$device    = sanitize_text_field( $params['device'] ?? 'desktop' );

		if ( empty( $widget_id ) ) {
			return array( 'success' => false, 'message' => __( 'Widget ID is required.', 'pressark' ) );
		}

		// Store previous data for undo logging.
		$previous_data = get_post_meta( $post_id, '_elementor_data', true );

		// Repeater item edit path.
		if ( isset( $params['item_index'] ) && isset( $params['item_fields'] ) ) {
			$repeater_field = sanitize_text_field( $params['field'] ?? '' );
			if ( empty( $repeater_field ) ) {
				return array( 'success' => false, 'message' => __( 'Repeater field name is required for item edits.', 'pressark' ) );
			}

			$result = $elementor->edit_widget_repeater_item(
				$post_id,
				$widget_id,
				$repeater_field,
				(int) $params['item_index'],
				$params['item_fields']
			);
		} else {
			// Standard field edit path.
			$changes = $params['changes'] ?? array();
			if ( empty( $changes ) ) {
				return array( 'success' => false, 'message' => __( 'Changes are required.', 'pressark' ) );
			}
			$result = $elementor->edit_widget( $post_id, $widget_id, $changes, $device );
		}

		if ( isset( $result['success'] ) && $result['success'] ) {
			$log_id = $this->logger->log(
				'elementor_edit_widget',
				$post_id,
				'post',
				wp_json_encode( array( '_elementor_data' => $previous_data ) ),
				wp_json_encode( array( 'widget_id' => $widget_id, 'params' => $params ) )
			);
			$result['log_id']  = $log_id;
			/* translators: %s: post title */
			$result['message'] = $result['message'] ?? sprintf( __( 'Updated Elementor widget on "%s".', 'pressark' ), get_the_title( $post_id ) );
		} elseif ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}

		return $result;
	}

	public function elementor_add_widget( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$post_id      = intval( $params['post_id'] ?? 0 );
		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;
		$elementor    = new PressArk_Elementor();
		$widget_type  = sanitize_text_field( $params['widget_type'] ?? '' );
		$settings     = $params['settings'] ?? array();
		$container_id = sanitize_text_field( $params['container_id'] ?? '' );
		$position     = intval( $params['position'] ?? -1 );

		if ( ! $post_id || ! $widget_type ) {
			return array( 'success' => false, 'message' => __( 'post_id and widget_type are required.', 'pressark' ) );
		}

		// Store previous data for undo logging.
		$previous_data = get_post_meta( $post_id, '_elementor_data', true );

		$result = $elementor->add_widget( $post_id, $widget_type, $settings, $container_id, $position );

		if ( isset( $result['success'] ) && $result['success'] ) {
			$log_id = $this->logger->log(
				'elementor_add_widget',
				$post_id,
				'post',
				wp_json_encode( array( '_elementor_data' => $previous_data ) ),
				wp_json_encode( array( 'widget_type' => $widget_type, 'settings' => $settings ) )
			);
			$result['log_id'] = $log_id;
		} elseif ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}

		return $result;
	}

	public function elementor_add_container( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$post_id   = intval( $params['post_id'] ?? 0 );
		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;
		$elementor = new PressArk_Elementor();
		$layout    = sanitize_text_field( $params['layout'] ?? 'boxed' );
		$direction = sanitize_text_field( $params['direction'] ?? 'column' );
		$position  = intval( $params['position'] ?? -1 );
		$parent_id = sanitize_text_field( $params['parent_id'] ?? '' );
		$settings  = $params['settings'] ?? array();

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'post_id is required.', 'pressark' ) );
		}

		// Store previous data for undo logging.
		$previous_data = get_post_meta( $post_id, '_elementor_data', true );

		$result = $elementor->add_container( $post_id, $layout, $direction, $position, $parent_id, $settings );

		if ( isset( $result['success'] ) && $result['success'] ) {
			$log_id = $this->logger->log(
				'elementor_add_container',
				$post_id,
				'post',
				wp_json_encode( array( '_elementor_data' => $previous_data ) ),
				wp_json_encode( array( 'layout' => $layout, 'direction' => $direction ) )
			);
			$result['log_id'] = $log_id;
		} elseif ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}

		return $result;
	}

	public function elementor_list_templates( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		$templates = $elementor->list_templates();
		return array(
			'success' => true,
			/* translators: %d: number of templates found */
			'message' => sprintf( __( '%d Elementor templates found.', 'pressark' ), count( $templates ) ),
			'data'    => $templates,
		);
	}

	public function elementor_create_from_template( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		$result    = $elementor->create_from_template(
			intval( $params['template_id'] ?? 0 ),
			sanitize_text_field( $params['title'] ?? 'New Page' ),
			sanitize_text_field( $params['post_type'] ?? 'page' )
		);
		if ( $result['success'] ) {
			$log_id = $this->logger->log(
				'elementor_create_from_template',
				$result['post_id'],
				'post',
				null,
				wp_json_encode( $params )
			);
			$result['log_id'] = $log_id;
		}
		return $result;
	}

	public function elementor_get_styles( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		$styles    = $elementor->global_styles(); // Unified read mode
		if ( isset( $styles['error'] ) ) {
			return array( 'success' => false, 'message' => $styles['error'] );
		}
		$color_count = count( $styles['colors']['system'] ?? array() ) + count( $styles['colors']['custom'] ?? array() );
		$typo_count  = count( $styles['typography']['system'] ?? array() ) + count( $styles['typography']['custom'] ?? array() );
		return array(
			'success' => true,
			/* translators: 1: color count, 2: typography preset count */
			'message' => sprintf( __( '%1$s colors, %2$s typography presets.', 'pressark' ), $color_count, $typo_count ),
			'data'    => $styles,
		);
	}

	public function elementor_find_replace( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		$find      = $params['find'] ?? '';
		$replace   = $params['replace'] ?? '';
		if ( empty( $find ) ) {
			return array( 'success' => false, 'message' => __( 'Search text is required.', 'pressark' ) );
		}

		$post_id = ! empty( $params['post_id'] ) ? intval( $params['post_id'] ) : null;

		// v3.7.0: When targeting a specific page, check the lock.
		if ( $post_id ) {
			$lock_err = $this->check_elementor_lock( $post_id );
			if ( $lock_err ) return $lock_err;
		}

		// v3.8.0: Snapshot _elementor_data for each affected page before mutating,
		// so the action logger has old values for undo/rollback.
		$snapshot_args = array(
			'post_type'      => array( 'page', 'post', 'elementor_library' ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'fields'         => 'ids',
		);
		if ( $post_id ) {
			$snapshot_args['post__in'] = array( $post_id );
		}
		$snap_ids = get_posts( $snapshot_args );

		foreach ( $snap_ids as $snap_id ) {
			$old_data = get_post_meta( $snap_id, '_elementor_data', true );
			if ( $old_data ) {
				$this->logger->log(
					'elementor_find_replace',
					$snap_id,
					get_post_type( $snap_id ),
					$old_data,
					null // new_value filled implicitly after replace runs
				);
			}
		}

		// v3.7.0: Site-wide find/replace is capped at 100 pages to prevent
		// timeout on large Elementor sites. Users should scope to a specific
		// post_id or use the async task queue for truly global operations.
		$result = $elementor->find_replace( $find, $replace, $post_id );

		if ( ( $result['pages_updated'] ?? 0 ) >= 100 ) {
			$result['warning'] = __( 'Find & replace was capped at 100 pages. Provide a post_id or run in batches for larger sites.', 'pressark' );
		}

		/* translators: 1: search text, 2: replacement text, 3: number of pages updated */
		$result['message'] = sprintf( __( 'Replaced "%1$s" with "%2$s" in %3$s Elementor page(s).', 'pressark' ), $find, $replace, $result['pages_updated'] );

		// v3.8.0: Surface locked-page info so the AI can report skipped pages.
		if ( ! empty( $result['pages_locked'] ) ) {
			/* translators: 1: number of locked pages */
			$result['message'] .= ' ' . sprintf( __( '%s page(s) skipped (locked by another user).', 'pressark' ), $result['pages_locked'] );
		}

		return $result;
	}

	public function elementor_audit_page( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		return $elementor->audit_page( (int) ( $params['post_id'] ?? 0 ) );
	}

	public function elementor_site_pages( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		return $elementor->get_site_pages(
			$params['post_type'] ?? '',
			(bool) ( $params['with_issues'] ?? false )
		);
	}

	public function elementor_global_styles( array $params ): array {
		$updates = $params['updates'] ?? null;
		// v3.7.0: Use write guard if updating, read guard if just reading.
		if ( $updates ) {
			$err = $this->require_elementor_write(); if ($err) return $err;
		} else {
			$err = $this->require_elementor(); if ($err) return $err;
		}
		$elementor = new PressArk_Elementor();
		return $elementor->global_styles( $updates );
	}

	public function elementor_create_page( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		$result    = $elementor->create_page(
			(string) ( $params['title']     ?? 'New Page' ),
			(string) ( $params['template']  ?? '' ),
			(string) ( $params['status']    ?? 'draft' ),
			(int)    ( $params['parent']    ?? 0 ),
			(array)  ( $params['widgets']   ?? array() ),
			(string) ( $params['post_type'] ?? 'page' )
		);
		if ( isset( $result['success'] ) && $result['success'] ) {
			$post_id    = (int) ( $result['post_id'] ?? 0 );
			$extra_meta = (array) ( $params['extra_meta'] ?? array() );

			if ( $post_id > 0 && ! empty( $extra_meta ) ) {
				$post_update = array( 'ID' => $post_id );
				$needs_post_update = false;

				if ( ! empty( $extra_meta['slug'] ) ) {
					$post_update['post_name'] = sanitize_title( (string) $extra_meta['slug'] );
					$needs_post_update = true;
				}

				if ( ! empty( $extra_meta['excerpt'] ) ) {
					$post_update['post_excerpt'] = sanitize_textarea_field( (string) $extra_meta['excerpt'] );
					$needs_post_update = true;
				}

				if ( $needs_post_update ) {
					wp_update_post( wp_slash( $post_update ) );
				}

				foreach ( array( 'meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyword' ) as $seo_key ) {
					if ( ! empty( $extra_meta[ $seo_key ] ) ) {
						PressArk_SEO_Resolver::write(
							$post_id,
							$seo_key,
							sanitize_text_field( (string) $extra_meta[ $seo_key ] )
						);
					}
				}

				if ( ! empty( $extra_meta['og_image'] ) ) {
					PressArk_SEO_Resolver::write(
						$post_id,
						'og_image',
						esc_url_raw( (string) $extra_meta['og_image'] )
					);
				}
			}

			if ( $post_id > 0 && 'future' === ( $params['status'] ?? '' ) && ! empty( $params['scheduled_date'] ) ) {
				$scheduled_date = sanitize_text_field( (string) $params['scheduled_date'] );
				wp_update_post( wp_slash( array(
					'ID'            => $post_id,
					'post_status'   => 'future',
					'post_date'     => $scheduled_date,
					'post_date_gmt' => get_gmt_from_date( $scheduled_date ),
				) ) );
			}

			$this->logger->log(
				'elementor_create_page',
				$result['post_id'],
				'post',
				null,
				wp_json_encode( array( 'title' => $params['title'] ?? 'New Page' ) )
			);
		}
		return $result;
	}

	public function elementor_get_widget_schema( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;

		$widget_type = sanitize_text_field( $params['widget_type'] ?? '' );
		$elementor   = new PressArk_Elementor();

		if ( $widget_type ) {
			// Single widget schema.
			$schema = $elementor->get_widget_schema_entry( $widget_type );
			if ( empty( $schema ) ) {
				/* translators: %s: widget type name */
				return array( 'success' => false, 'message' => sprintf( __( "Widget type '%s' not found.", 'pressark' ), $widget_type ) );
			}
			return array(
				'success' => true,
				/* translators: 1: widget type name, 2: content field count, 3: style field count */
				'message' => sprintf( __( "Schema for '%1\$s': %2\$s content fields, %3\$s style fields.", 'pressark' ), $widget_type, count( $schema['content_fields'] ?? array() ), count( $schema['style_fields'] ?? array() ) ),
				'data'    => $schema,
			);
		}

		// All widget types summary (not full schema — too large).
		$all     = PressArk_Elementor::get_all_widget_schemas();
		$summary = array();
		foreach ( $all as $name => $schema ) {
			$summary[] = array(
				'name'           => $name,
				'title'          => $schema['title'] ?? $name,
				'categories'     => $schema['categories'] ?? array(),
				'content_fields' => $schema['content_fields'] ?? array(),
			);
		}

		return array(
			'success' => true,
			/* translators: %d: number of registered widget types */
			'message' => sprintf( __( '%d registered widget types.', 'pressark' ), count( $summary ) ),
			'data'    => array(
				'total'   => count( $summary ),
				'widgets' => $summary,
				'hint'    => __( 'Use widget_type to get full schema with all fields for a specific widget.', 'pressark' ),
			),
		);
	}

	/**
	 * Clone an Elementor page with all its content and settings.
	 * Regenerates element IDs to prevent CSS/JS conflicts.
	 */
	public function elementor_clone_page( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;

		$source_id = absint( $params['source_id'] ?? 0 );
		if ( ! $source_id ) {
			return array( 'success' => false, 'message' => __( 'source_id is required.', 'pressark' ) );
		}

		$source = get_post( $source_id );
		if ( ! $source ) {
			return array( 'success' => false, 'message' => __( 'Source page not found.', 'pressark' ) );
		}

		$elementor_data = get_post_meta( $source_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return array( 'success' => false, 'message' => __( 'Source page is not an Elementor page or has no content.', 'pressark' ) );
		}

		$new_title = sanitize_text_field(
			/* translators: %s: original page title */
			$params['title'] ?? sprintf( __( '%s (Copy)', 'pressark' ), $source->post_title )
		);
		$new_status = in_array( $params['status'] ?? 'draft', array( 'draft', 'publish', 'private' ), true )
			? $params['status']
			: 'draft';

		$new_id = wp_insert_post( array(
			'post_title'  => $new_title,
			'post_status' => $new_status,
			'post_type'   => $source->post_type,
			'post_parent' => $source->post_parent,
		), true );

		if ( is_wp_error( $new_id ) ) {
			return array( 'success' => false, 'message' => $new_id->get_error_message() );
		}

		// Copy Elementor element data with regenerated IDs.
		$elementor = new PressArk_Elementor();
		$decoded   = json_decode( $elementor_data, true );

		if ( is_array( $decoded ) ) {
			$decoded = $elementor->regenerate_element_ids_public( $decoded );
			update_post_meta( $new_id, '_elementor_data', wp_slash( wp_json_encode( $decoded ) ) );
		} else {
			update_post_meta( $new_id, '_elementor_data', wp_slash( $elementor_data ) );
		}

		// Copy all Elementor meta.
		$elementor_meta_keys = array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_page_settings',
			'_wp_page_template',
		);

		foreach ( $elementor_meta_keys as $meta_key ) {
			$meta_value = get_post_meta( $source_id, $meta_key, true );
			if ( $meta_value !== '' && $meta_value !== false ) {
				update_post_meta( $new_id, $meta_key, $meta_value );
			}
		}

		// Regenerate CSS for the new page.
		$elementor->regenerate_post_css_public( $new_id );

		// Log the clone operation.
		$this->logger->log(
			'elementor_clone_page',
			$new_id,
			'post',
			null,
			wp_json_encode( array( 'source_id' => $source_id ) )
		);

		return array(
			'success'   => true,
			'new_id'    => $new_id,
			'title'     => $new_title,
			'status'    => $new_status,
			'source_id' => $source_id,
			'edit_url'  => admin_url( 'post.php?post=' . $new_id . '&action=elementor' ),
			/* translators: 1: new page title, 2: new page ID */
			'message'   => sprintf( __( "Page cloned as '%1\$s' (ID: %2\$d). Element IDs regenerated to prevent conflicts.", 'pressark' ), $new_title, $new_id ),
		);
	}

	/**
	 * Read display conditions on an Elementor template.
	 * Conditions control where theme builder templates are applied.
	 */
	public function elementor_manage_conditions( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;

		$template_id = absint( $params['template_id'] ?? 0 );
		if ( ! $template_id ) {
			return array( 'success' => false, 'message' => __( 'template_id is required.', 'pressark' ) );
		}

		$post = get_post( $template_id );
		if ( ! $post || $post->post_type !== 'elementor_library' ) {
			return array( 'success' => false, 'message' => __( 'Template not found.', 'pressark' ) );
		}

		$raw_conditions = get_post_meta( $template_id, '_elementor_conditions', true );
		$template_type  = get_post_meta( $template_id, '_elementor_template_type', true );

		// Parse condition strings.
		$parsed = array();
		foreach ( (array) $raw_conditions as $condition_str ) {
			$parts           = explode( '/', $condition_str );
			$include_exclude = $parts[0] ?? 'include';
			$type            = $parts[1] ?? '';
			$sub_type        = $parts[2] ?? '';
			$object_id       = $parts[3] ?? '';

			$labels = array(
				'general'         => __( 'Entire Site', 'pressark' ),
				'front_page'      => __( 'Front Page', 'pressark' ),
				'singular'        => __( 'Singular', 'pressark' ),
				'archive'         => __( 'Archive', 'pressark' ),
				'post'            => __( 'Posts', 'pressark' ),
				'page'            => __( 'Pages', 'pressark' ),
				'category'        => __( 'Category', 'pressark' ),
				'tag'             => __( 'Tag', 'pressark' ),
				'product'         => __( 'Products', 'pressark' ),
				'product-archive' => __( 'Product Archive', 'pressark' ),
			);

			$label = $labels[ $type ] ?? $type;
			if ( $sub_type ) {
				$label .= ' → ' . ( $labels[ $sub_type ] ?? $sub_type );
			}
			if ( $object_id ) {
				$obj_title = get_the_title( (int) $object_id );
				if ( $obj_title ) {
					$label .= " ({$obj_title})";
				}
			}

			$parsed[] = array(
				'rule'   => $condition_str,
				'action' => $include_exclude,
				'label'  => ucfirst( $include_exclude ) . ': ' . $label,
			);
		}

		$has_conditions  = ! empty( $raw_conditions );
		$is_pro_template = in_array( $template_type, array(
			'header', 'footer', 'single', 'archive', 'popup', 'error-404', 'product',
		), true );

		return array(
			'success'         => true,
			'template_id'     => $template_id,
			'template_title'  => $post->post_title,
			'template_type'   => $template_type,
			'has_conditions'  => $has_conditions,
			'conditions'      => $parsed,
			'is_pro_template' => $is_pro_template,
			'note'            => $is_pro_template
				? __( 'Display conditions control where this template appears on the site.', 'pressark' )
				: __( 'This is a regular template — display conditions only apply to theme builder templates (Pro).', 'pressark' ),
			'manage_url'      => admin_url( 'post.php?post=' . $template_id . '&action=elementor' ),
		);
	}

	/**
	 * Get active Elementor breakpoints with pixel thresholds.
	 */
	public function elementor_get_breakpoints( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		return array(
			'success'     => true,
			'breakpoints' => $elementor->get_active_breakpoints(),
			'note'        => __( 'Use the device parameter in elementor_edit_widget to target a specific breakpoint.', 'pressark' ),
		);
	}

	public function elementor_list_dynamic_tags( array $params ): array {
		$err = $this->require_elementor(); if ($err) return $err;
		$elementor = new PressArk_Elementor();
		return $elementor->list_dynamic_tags();
	}

	public function elementor_set_dynamic_tag( array $params ): array {
		$err = $this->require_elementor_write(); if ($err) return $err;
		$post_id   = (int) ( $params['post_id'] ?? 0 );
		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;
		$elementor = new PressArk_Elementor();

		// Store previous data for undo logging.
		$previous_data = get_post_meta( $post_id, '_elementor_data', true );

		$result = $elementor->set_dynamic_tag(
			$post_id,
			(string) ( $params['widget_id']    ?? '' ),
			(string) ( $params['field']        ?? '' ),
			(string) ( $params['tag_name']     ?? '' ),
			(array)  ( $params['tag_settings'] ?? array() )
		);

		if ( isset( $result['success'] ) && $result['success'] ) {
			$this->logger->log(
				'elementor_set_dynamic_tag',
				$post_id,
				'post',
				wp_json_encode( array( '_elementor_data' => $previous_data ) ),
				wp_json_encode( $params )
			);
		}

		return $result;
	}

	/**
	 * Read the complete configuration of an Elementor Pro Form widget.
	 */
	public function elementor_read_form( array $params ): array {
		$post_id   = (int)    ( $params['post_id']   ?? 0 );
		$widget_id = (string) ( $params['widget_id'] ?? '' );

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return array( 'error' => __( 'No Elementor data found for this post.', 'pressark' ) );
		}

		$form_widget = $this->find_widget_by_id( $data, $widget_id );

		if ( ! $form_widget ) {
			/* translators: %s: widget ID */
			return array( 'error' => sprintf( __( "Widget '%s' not found.", 'pressark' ), $widget_id ) );
		}

		if ( ( $form_widget['widgetType'] ?? '' ) !== 'form' ) {
			return array(
				/* translators: 1: widget ID, 2: actual widget type */
				'error' => sprintf( __( "Widget '%1\$s' is not a form widget (it is '%2\$s').", 'pressark' ), $widget_id, $form_widget['widgetType'] ),
			);
		}

		$settings = $form_widget['settings'] ?? array();

		// Parse form fields (stored as a repeater).
		$fields = array();
		foreach ( $settings['form_fields'] ?? array() as $i => $field ) {
			$fields[] = array(
				'index'       => $i,
				'id'          => $field['custom_id'] ?? $field['_id'] ?? '',
				'type'        => $field['field_type'] ?? 'text',
				'label'       => $field['field_label'] ?? '',
				'placeholder' => $field['placeholder'] ?? '',
				'required'    => ! empty( $field['required'] ),
				'width'       => $field['width'] ?? '100',
				'options'     => $field['field_options'] ?? '',
			);
		}

		// Parse email actions.
		$email_actions = array();
		foreach ( $settings['submit_actions'] ?? array() as $action ) {
			if ( $action === 'email' || $action === 'email2' ) {
				$prefix = $action === 'email' ? 'email' : 'email_2';
				$email_actions[] = array(
					'type'    => $action,
					'to'      => $settings[ $prefix . '_to' ]       ?? '',
					'subject' => $settings[ $prefix . '_subject' ]  ?? '',
					'from'    => $settings[ $prefix . '_from' ]     ?? '',
					'reply'   => $settings[ $prefix . '_reply_to' ] ?? '',
					'message' => $settings[ $prefix . '_content' ]  ?? '',
				);
			}
		}

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'widget_id'       => $widget_id,
			'form_name'       => $settings['form_name'] ?? '',
			'field_count'     => count( $fields ),
			'fields'          => $fields,
			'submit_label'    => $settings['button_text'] ?? 'Submit',
			'submit_actions'  => $settings['submit_actions'] ?? array(),
			'email_actions'   => $email_actions,
			'redirect_to'     => $settings['redirect_to'] ?? '',
			'success_message' => $settings['success_message'] ?? '',
			'edit_hint'       => __( 'Use elementor_edit_form_field to modify individual fields.', 'pressark' ),
		);
	}

	/**
	 * Edit a specific field in an Elementor Pro Form widget.
	 *
	 * v3.7.1: Added require_elementor_write() + check_elementor_lock()
	 * that were missing from the v3.7.0 hardening pass.
	 */
	public function elementor_edit_form_field( array $params ): array {
		// v3.7.1: Version guard + lock check (was missing in v3.7.0).
		$err = $this->require_elementor_write();
		if ( $err ) return $err;

		$post_id     = (int)    ( $params['post_id']     ?? 0 );
		$widget_id   = (string) ( $params['widget_id']   ?? '' );
		$field_index = (int)    ( $params['field_index'] ?? 0 );
		$changes     = (array)  ( $params['changes']     ?? array() );

		if ( empty( $changes ) ) {
			return array( 'error' => __( 'No changes specified.', 'pressark' ) );
		}

		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return array( 'error' => __( 'No Elementor data found.', 'pressark' ) );
		}

		$previous_data = get_post_meta( $post_id, '_elementor_data', true );
		$updated       = false;
		$changed       = array();

		$data = $this->walk_and_edit_form( $data, $widget_id, $field_index, $changes, $updated, $changed );

		if ( ! $updated ) {
			/* translators: 1: widget ID, 2: field index number */
			return array( 'error' => sprintf( __( "Widget '%1\$s' not found or field index %2\$d out of range.", 'pressark' ), $widget_id, $field_index ) );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

		$elementor = new PressArk_Elementor();
		$elementor->regenerate_post_css_public( $post_id );

		$this->logger->log(
			'elementor_edit_form_field',
			$post_id,
			'post',
			wp_json_encode( array( '_elementor_data' => $previous_data ) ),
			wp_json_encode( $params )
		);

		return array(
			'success'     => true,
			'post_id'     => $post_id,
			'widget_id'   => $widget_id,
			'field_index' => $field_index,
			'changed'     => $changed,
			/* translators: %s: comma-separated list of changed field names */
			'message'     => sprintf( __( 'Form field updated: %s.', 'pressark' ), implode( ', ', array_keys( $changed ) ) ),
		);
	}

	/**
	 * Set visibility / display conditions on an Elementor element.
	 *
	 * v3.7.1: Added require_elementor_write() + check_elementor_lock()
	 * that were missing from the v3.7.0 hardening pass.
	 */
	public function elementor_set_visibility( array $params ): array {
		// v3.7.1: Version guard + lock check (was missing in v3.7.0).
		$err = $this->require_elementor_write();
		if ( $err ) return $err;

		$post_id    = (int)    ( $params['post_id']    ?? 0 );
		$element_id = (string) ( $params['element_id'] ?? '' );

		$lock_err = $this->check_elementor_lock( $post_id );
		if ( $lock_err ) return $lock_err;

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $data ) ) {
			return array( 'error' => __( 'No Elementor data found.', 'pressark' ) );
		}

		$previous_data = get_post_meta( $post_id, '_elementor_data', true );
		$updated       = false;
		$changes       = array();

		$data = $this->walk_and_set_visibility( $data, $element_id, $params, $updated, $changes );

		if ( ! $updated ) {
			/* translators: %s: element ID */
			return array( 'error' => sprintf( __( "Element '%s' not found.", 'pressark' ), $element_id ) );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

		$elementor = new PressArk_Elementor();
		$elementor->regenerate_post_css_public( $post_id );

		$this->logger->log(
			'elementor_set_visibility',
			$post_id,
			'post',
			wp_json_encode( array( '_elementor_data' => $previous_data ) ),
			wp_json_encode( $params )
		);

		return array(
			'success'    => true,
			'element_id' => $element_id,
			'changes'    => $changes,
			'message'    => __( 'Element visibility updated.', 'pressark' ),
			'note'       => __( 'Responsive visibility (hide_on_devices) is always available. Display conditions (login/role/date) require Elementor 3.19+.', 'pressark' ),
		);
	}

	/**
	 * List all Elementor Pro popups with trigger and condition configuration.
	 */
	public function elementor_list_popups( array $params ): array {
		$popups = get_posts( array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => min( absint( $params['limit'] ?? 20 ), 50 ),
			'meta_key'       => '_elementor_template_type',
			'meta_value'     => 'popup',
		) );

		if ( empty( $popups ) ) {
			return array(
				'success' => true,
				'count'   => 0,
				'popups'  => array(),
				'message' => __( 'No Elementor popups found. Popups require Elementor Pro.', 'pressark' ),
			);
		}

		$result = array();
		foreach ( $popups as $popup ) {
			$settings = get_post_meta( $popup->ID, '_elementor_page_settings', true ) ?: array();

			// Parse triggers.
			$triggers    = array();
			$trigger_map = array(
				'page_load'    => __( 'On page load', 'pressark' ),
				'scroll_depth' => __( 'On scroll', 'pressark' ),
				'click'        => __( 'On click', 'pressark' ),
				'inactivity'   => __( 'After inactivity', 'pressark' ),
				'exit_intent'  => __( 'On exit intent', 'pressark' ),
				'page_views'   => __( 'After page views', 'pressark' ),
				'sessions'     => __( 'After sessions', 'pressark' ),
			);

			foreach ( $settings['triggers'] ?? array() as $trigger ) {
				$type   = $trigger['id'] ?? '';
				$label  = $trigger_map[ $type ] ?? $type;
				$detail = '';

				switch ( $type ) {
					case 'page_load':
						$delay  = $trigger['settings']['delay'] ?? 0;
						/* translators: %s: delay in milliseconds */
						$detail = $delay ? sprintf( __( 'after %sms', 'pressark' ), $delay ) : __( 'immediately', 'pressark' );
						break;
					case 'scroll_depth':
						/* translators: %s: scroll depth percentage */
						$detail = sprintf( __( '%s%% scroll', 'pressark' ), $trigger['settings']['scroll_depth'] ?? '50' );
						break;
					case 'click':
						/* translators: %s: CSS selector */
						$detail = sprintf( __( 'selector: %s', 'pressark' ), $trigger['settings']['selector'] ?? '' );
						break;
					case 'inactivity':
						/* translators: %s: inactivity time in seconds */
						$detail = sprintf( __( '%ss inactivity', 'pressark' ), $trigger['settings']['inactivity_time'] ?? '3' );
						break;
				}

				$triggers[] = array(
					'type'   => $type,
					'label'  => $label,
					'detail' => $detail,
				);
			}

			// Parse display conditions.
			$conditions        = get_post_meta( $popup->ID, '_elementor_conditions', true );
			$parsed_conditions = array();
			foreach ( (array) $conditions as $cond ) {
				$parts = explode( '/', $cond );
				$parsed_conditions[] = array(
					'action' => $parts[0] ?? 'include',
					'where'  => implode( ' / ', array_slice( $parts, 1 ) ),
				);
			}

			// Frequency / display settings.
			$display = array(
				'times'         => $settings['times_per_day'] ?? __( 'unlimited', 'pressark' ),
				'show_again_in' => isset( $settings['show_again_delay'] )
					? ( $settings['show_again_delay'] . ' ' . ( $settings['show_again_delay_type'] ?? __( 'days', 'pressark' ) ) )
					: __( 'always', 'pressark' ),
			);

			$result[] = array(
				'id'          => $popup->ID,
				'title'       => $popup->post_title,
				'triggers'    => $triggers,
				'conditions'  => $parsed_conditions,
				'display'     => $display,
				'edit_url'    => admin_url( 'post.php?post=' . $popup->ID . '&action=elementor' ),
				'has_content' => ! empty( get_post_meta( $popup->ID, '_elementor_data', true ) ),
			);
		}

		return array(
			'success' => true,
			'count'   => count( $result ),
			'popups'  => $result,
			'hint'    => __( 'Use elementor_edit_popup_trigger to change when a popup appears.', 'pressark' ),
		);
	}

	/**
	 * Edit the trigger settings on an Elementor Pro popup.
	 *
	 * v3.7.1: Added require_elementor_write() + check_elementor_lock()
	 * that were missing from the v3.7.0 hardening pass.
	 */
	public function elementor_edit_popup_trigger( array $params ): array {
		// v3.7.1: Version guard + lock check (was missing in v3.7.0).
		$err = $this->require_elementor_write();
		if ( $err ) return $err;

		$popup_id         = absint( $params['popup_id'] ?? 0 );
		$trigger_type     = sanitize_text_field( $params['trigger_type'] ?? '' );
		$trigger_settings = (array) ( $params['trigger_settings'] ?? array() );

		if ( ! $popup_id ) {
			return array( 'error' => __( 'popup_id is required.', 'pressark' ) );
		}

		$post = get_post( $popup_id );
		if ( ! $post || $post->post_type !== 'elementor_library' ) {
			return array( 'error' => __( 'Popup not found.', 'pressark' ) );
		}

		$lock_err = $this->check_elementor_lock( $popup_id );
		if ( $lock_err ) return $lock_err;

		$page_settings = get_post_meta( $popup_id, '_elementor_page_settings', true ) ?: array();
		$triggers      = $page_settings['triggers'] ?? array();
		$updated       = false;
		$old_config    = null;

		foreach ( $triggers as &$trigger ) {
			if ( ( $trigger['id'] ?? '' ) === $trigger_type ) {
				$old_config = $trigger['settings'] ?? array();
				foreach ( $trigger_settings as $key => $value ) {
					$trigger['settings'][ sanitize_key( $key ) ] = $value;
				}
				$updated = true;
				break;
			}
		}
		unset( $trigger );

		if ( ! $updated ) {
			// Add new trigger if type not found.
			$triggers[] = array(
				'id'       => $trigger_type,
				'settings' => $trigger_settings,
			);
		}

		$page_settings['triggers'] = $triggers;
		update_post_meta( $popup_id, '_elementor_page_settings', $page_settings );

		return array(
			'success'      => true,
			'popup_id'     => $popup_id,
			'popup_title'  => $post->post_title,
			'trigger_type' => $trigger_type,
			'old_settings' => $old_config,
			'new_settings' => $trigger_settings,
			/* translators: 1: trigger type, 2: popup title */
			'message'      => sprintf( __( "Popup trigger '%1\$s' updated on '%2\$s'.", 'pressark' ), $trigger_type, $post->post_title ),
		);
	}

	// ── Private Helper Methods ──────────────────────────────────────────

	/**
	 * Find a specific widget by ID anywhere in the element tree.
	 */
	private function find_widget_by_id( array $elements, string $widget_id ): ?array {
		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $widget_id ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = $this->find_widget_by_id( $element['elements'], $widget_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	private function walk_and_edit_form(
		array  $elements,
		string $widget_id,
		int    $field_index,
		array  $changes,
		bool   &$updated,
		array  &$changed
	): array {
		foreach ( $elements as &$element ) {
			if ( ( $element['id'] ?? '' ) === $widget_id ) {
				$form_fields = $element['settings']['form_fields'] ?? array();

				if ( ! isset( $form_fields[ $field_index ] ) ) {
					return $elements;
				}

				// Allowed field properties to change.
				$allowed = array(
					'field_label'   => 'label',
					'placeholder'   => 'placeholder',
					'required'      => 'required',
					'field_type'    => 'type',
					'field_options' => 'options',
					'width'         => 'width',
					'custom_id'     => 'id',
				);

				foreach ( $changes as $key => $value ) {
					// Resolve alias (e.g., 'label' → 'field_label').
					$internal_key = array_search( $key, $allowed, true ) ?: $key;

					if ( array_key_exists( $internal_key, $allowed ) || in_array( $key, array_keys( $allowed ), true ) ) {
						$actual_key = in_array( $key, array_keys( $allowed ), true ) ? $key : $internal_key;
						$old = $form_fields[ $field_index ][ $actual_key ] ?? null;
						$form_fields[ $field_index ][ $actual_key ] = $value;
						$changed[ $actual_key ] = array( 'from' => $old, 'to' => $value );
					}
				}

				$element['settings']['form_fields'] = $form_fields;
				$updated = true;
				return $elements;
			}

			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->walk_and_edit_form(
					$element['elements'], $widget_id, $field_index, $changes, $updated, $changed
				);
				if ( $updated ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	private function walk_and_set_visibility(
		array  $elements,
		string $target_id,
		array  $params,
		bool   &$updated,
		array  &$changes
	): array {
		foreach ( $elements as &$element ) {
			if ( ( $element['id'] ?? '' ) === $target_id ) {
				$updated  = true;
				$action   = $params['action'] ?? 'show';
				$settings = &$element['settings'];

				// Simple show/hide (Pro feature — sets _visibility).
				if ( in_array( $action, array( 'show', 'hide' ), true ) ) {
					$settings['_visibility'] = $action === 'hide' ? 'hidden' : 'visible';
					$changes['_visibility']  = $settings['_visibility'];
				}

				// Responsive hide — always available in free.
				if ( ! empty( $params['hide_on'] ) && is_array( $params['hide_on'] ) ) {
					$device_map = array(
						'desktop' => 'hide_desktop',
						'tablet'  => 'hide_tablet',
						'mobile'  => 'hide_mobile',
					);
					// Reset all first.
					foreach ( $device_map as $device => $key ) {
						$settings[ $key ] = '';
					}
					// Apply requested hides.
					foreach ( $params['hide_on'] as $device ) {
						$key = $device_map[ $device ] ?? null;
						if ( $key ) {
							$settings[ $key ]   = 'hidden';
							$changes[ $device ] = 'hidden';
						}
					}
				}

				// Show on all devices (clear responsive hides).
				if ( $action === 'show_all' ) {
					$settings['hide_desktop'] = '';
					$settings['hide_tablet']  = '';
					$settings['hide_mobile']  = '';
					$changes['responsive']    = __( 'visible on all devices', 'pressark' );
				}

				return $elements;
			}

			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->walk_and_set_visibility(
					$element['elements'], $target_id, $params, $updated, $changes
				);
				if ( $updated ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	/**
	 * Count widgets recursively in an Elementor structure.
	 */
	private function count_widgets_recursive( array $elements ): int {
		$count = 0;
		foreach ( $elements as $el ) {
			if ( ( $el['type'] ?? '' ) === 'widget' ) {
				$count++;
			}
			if ( ! empty( $el['children'] ) ) {
				$count += $this->count_widgets_recursive( $el['children'] );
			}
		}
		return $count;
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	/**
	 * Preview for elementor_edit_widget.
	 *
	 * Rich implementation: walks the Elementor DOM tree to show real
	 * before/after values for each changed setting.
	 */
	public function preview_elementor_edit_widget( array $params, array $action ): array {
		$el_post_id   = intval( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$el_widget_id = $params['widget_id'] ?? ( $action['widget_id'] ?? '' );
		$el_post_title = get_the_title( $el_post_id );

		$preview = array(
			/* translators: %s: post title */
			'post_title' => sprintf( __( 'Elementor Widget on "%s"', 'pressark' ), $el_post_title ),
			'post_id'    => $el_post_id,
			'changes'    => array(),
		);

		// Walk the DOM tree to find current widget settings.
		$el_found    = null;
		$elementor   = new PressArk_Elementor();
		$el_elements = $elementor->get_elementor_data( $el_post_id );
		if ( $el_elements ) {
			$el_walk = function ( $els ) use ( $el_widget_id, &$el_found, &$el_walk ) {
				foreach ( $els as $el ) {
					if ( ( $el['id'] ?? '' ) === $el_widget_id ) {
						$el_found = $el;
						return;
					}
					if ( ! empty( $el['elements'] ) ) {
						$el_walk( $el['elements'] );
					}
				}
			};
			$el_walk( $el_elements );
		}

		$el_changes = $params['changes'] ?? ( $action['changes'] ?? array() );
		foreach ( $el_changes as $key => $value ) {
			$current = '';
			if ( $el_found ) {
				$parts = explode( '.', $key );
				$ref   = $el_found['settings'] ?? array();
				foreach ( $parts as $p ) {
					$ref = $ref[ $p ] ?? '';
				}
				$current = is_string( $ref ) ? $ref : wp_json_encode( $ref );
			}
			$display_value = is_string( $value ) ? $value : wp_json_encode( $value );
			$preview['changes'][] = array(
				'field'  => ucfirst( str_replace( array( '.', '_' ), ' ', $key ) ),
				'before' => mb_substr( $current, 0, 200 ) ?: __( '(empty)', 'pressark' ),
				'after'  => mb_substr( $display_value, 0, 200 ),
			);
		}

		return $preview;
	}

	/**
	 * Preview for elementor_add_widget.
	 */
	public function preview_elementor_add_widget( array $params, array $action ): array {
		$eaw_type     = $params['widget_type'] ?? ( $action['widget_type'] ?? 'widget' );
		$eaw_post_id  = intval( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$eaw_settings = $params['settings'] ?? ( $action['settings'] ?? array() );
		$eaw_after    = ucfirst( str_replace( '-', ' ', $eaw_type ) );

		if ( ! empty( $eaw_settings ) ) {
			$eaw_previews = array();
			foreach ( $eaw_settings as $k => $v ) {
				if ( is_string( $v ) && strlen( $v ) <= 60 ) {
					$eaw_previews[] = $k . ': ' . $v;
				}
			}
			if ( $eaw_previews ) {
				$eaw_after .= ' (' . implode( ', ', array_slice( $eaw_previews, 0, 3 ) ) . ')';
			}
		}

		return array(
			'changes' => array(
				array(
					/* translators: %d: post ID */
					'field'  => sprintf( __( 'Add Widget to Page #%d', 'pressark' ), $eaw_post_id ),
					'before' => __( '(no widget)', 'pressark' ),
					'after'  => $eaw_after,
				),
			),
		);
	}

	/**
	 * Preview for elementor_create_page.
	 */
	public function preview_elementor_create_page( array $params, array $action ): array {
		$title     = $params['title'] ?? ( $action['title'] ?? 'Untitled' );
		$template  = $params['template'] ?? ( $action['template'] ?? 'default' );
		$widgets   = $params['widgets'] ?? ( $action['widgets'] ?? array() );
		$post_type = $params['post_type'] ?? ( $action['post_type'] ?? 'page' );
		$status    = $params['status'] ?? ( $action['status'] ?? 'draft' );
		$label     = 'post' === $post_type ? __( 'New Elementor Post', 'pressark' ) : __( 'New Elementor Page', 'pressark' );

		return array(
			'changes' => array(
				array(
					'field'  => $label,
					'before' => __( '(does not exist)', 'pressark' ),
					/* translators: 1: page title, 2: template name, 3: widget count, 4: post status */
					'after'  => sprintf( __( '%1$s — template: %2$s, %3$d widget(s), status: %4$s', 'pressark' ), $title, $template, count( $widgets ), $status ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_find_replace.
	 */
	public function preview_elementor_find_replace( array $params, array $action ): array {
		return array(
			'post_title' => __( 'Elementor Find & Replace', 'pressark' ),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Find & Replace', 'pressark' ),
					'before' => $params['find'] ?? ( $action['find'] ?? '' ),
					'after'  => $params['replace'] ?? ( $action['replace'] ?? '' ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_create_from_template.
	 */
	public function preview_elementor_create_from_template( array $params, array $action ): array {
		$tpl_id    = intval( $params['template_id'] ?? ( $action['template_id'] ?? 0 ) );
		$tpl_title = $tpl_id ? get_the_title( $tpl_id ) : __( 'Unknown', 'pressark' );
		$new_title = $params['title'] ?? ( $action['title'] ?? 'New Page' );

		return array(
			'changes' => array(
				array(
					'field'  => __( 'Create from Template', 'pressark' ),
					/* translators: %s: template title */
					'before' => sprintf( __( 'Template: "%s"', 'pressark' ), $tpl_title ),
					/* translators: %s: new page title */
					'after'  => sprintf( __( 'New page: "%s" (draft)', 'pressark' ), $new_title ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_set_dynamic_tag.
	 */
	public function preview_elementor_set_dynamic_tag( array $params, array $action ): array {
		$widget = $params['widget_id'] ?? ( $action['widget_id'] ?? 'unknown' );
		$field  = $params['setting_key'] ?? ( $action['setting_key'] ?? 'field' );
		$tag    = $params['tag_name'] ?? ( $action['tag_name'] ?? 'dynamic tag' );

		return array(
			'changes' => array(
				array(
					/* translators: %s: widget ID */
					'field'  => sprintf( __( 'Dynamic Tag on Widget %s', 'pressark' ), $widget ),
					/* translators: %s: field name */
					'before' => sprintf( __( 'Static value (%s)', 'pressark' ), $field ),
					/* translators: %s: dynamic tag name */
					'after'  => sprintf( __( 'Dynamic: %s', 'pressark' ), $tag ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_edit_form_field.
	 */
	public function preview_elementor_edit_form_field( array $params, array $action ): array {
		$post_id  = intval( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$field_id = $params['field_id'] ?? ( $action['field_id'] ?? 'unknown' );
		return array(
			'changes' => array(
				array(
					/* translators: 1: field ID, 2: post ID */
					'field'  => sprintf( __( 'Form Field "%1$s" on Page #%2$d', 'pressark' ), $field_id, $post_id ),
					'before' => __( 'Current field settings', 'pressark' ),
					'after'  => __( 'Updated field settings', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_set_visibility.
	 */
	public function preview_elementor_set_visibility( array $params, array $action ): array {
		$widget  = $params['widget_id'] ?? ( $action['widget_id'] ?? 'unknown' );
		$devices = $params['hide_on'] ?? ( $action['hide_on'] ?? array() );
		return array(
			'changes' => array(
				array(
					/* translators: %s: widget ID */
					'field'  => sprintf( __( 'Visibility for Widget %s', 'pressark' ), $widget ),
					'before' => __( 'Current visibility settings', 'pressark' ),
					/* translators: %s: comma-separated list of device names */
					'after'  => ! empty( $devices ) ? sprintf( __( 'Hide on: %s', 'pressark' ), implode( ', ', (array) $devices ) ) : __( 'Show on all devices', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_clone_page.
	 */
	public function preview_elementor_clone_page( array $params, array $action ): array {
		$src    = intval( $params['source_id'] ?? ( $action['source_id'] ?? 0 ) );
		$title  = $params['title'] ?? ( $action['title'] ?? 'Copy' );
		$status = $params['status'] ?? ( $action['status'] ?? 'draft' );
		return array(
			'changes' => array(
				array(
					/* translators: %d: source page ID */
					'field'  => sprintf( __( 'Clone Elementor Page #%d', 'pressark' ), $src ),
					/* translators: %d: source page ID */
					'before' => sprintf( __( 'Source page #%d', 'pressark' ), $src ),
					/* translators: 1: new page title, 2: post status */
					'after'  => sprintf( __( '"%1$s" as %2$s', 'pressark' ), $title, $status ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_edit_popup_trigger.
	 */
	public function preview_elementor_edit_popup_trigger( array $params, array $action ): array {
		$popup_id     = intval( $params['popup_id'] ?? ( $action['popup_id'] ?? 0 ) );
		$trigger_type = $params['trigger_type'] ?? ( $action['trigger_type'] ?? 'unknown' );
		return array(
			'changes' => array(
				array(
					/* translators: %d: popup ID */
					'field'  => sprintf( __( 'Popup #%d Trigger', 'pressark' ), $popup_id ),
					'before' => __( 'Current trigger settings', 'pressark' ),
					/* translators: %s: trigger type */
					'after'  => sprintf( __( 'Set trigger: %s', 'pressark' ), str_replace( '_', ' ', $trigger_type ) ),
				),
			),
		);
	}

	/**
	 * Preview for elementor_global_styles.
	 */
	public function preview_elementor_global_styles( array $params, array $action ): array {
		$colors = $params['colors'] ?? ( $action['colors'] ?? array() );
		$fonts  = $params['fonts'] ?? ( $action['fonts'] ?? array() );
		$parts  = array();

		if ( ! empty( $colors ) ) {
			/* translators: %d: number of colors */
			$parts[] = sprintf( __( '%d color(s)', 'pressark' ), count( $colors ) );
		}
		if ( ! empty( $fonts ) ) {
			/* translators: %d: number of fonts */
			$parts[] = sprintf( __( '%d font(s)', 'pressark' ), count( $fonts ) );
		}

		return array(
			'changes' => array(
				array(
					'field'  => __( 'Elementor Global Styles', 'pressark' ),
					'before' => __( 'Current global styles', 'pressark' ),
					/* translators: %s: list of style types being updated (e.g. "2 color(s), 3 font(s)") */
					'after'  => ! empty( $parts ) ? sprintf( __( 'Update %s', 'pressark' ), implode( ', ', $parts ) ) : __( 'Update global styles', 'pressark' ),
				),
			),
		);
	}
}
