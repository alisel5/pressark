<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor page builder integration for PressArk.
 * Reads, edits, and manages Elementor page data stored in post meta.
 * Includes widget settings schema with natural-language aliases for reliable field resolution.
 */
class PressArk_Elementor {

	/**
	 * Check if Elementor is active.
	 */
	public static function is_active(): bool {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Check if Elementor Pro is active.
	 */
	public static function has_pro(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Check if Elementor Flexbox Containers experiment is active.
	 * Containers replace sections/columns in Elementor 3.12+.
	 */
	public static function is_container_active(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		try {
			return \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Check if a post is built with Elementor.
	 */
	public static function is_elementor_page( int $post_id ): bool {
		return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
	}

	/**
	 * Get the raw Elementor data for a post.
	 */
	public function get_elementor_data( int $post_id ): ?array {
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) ) {
			return null;
		}
		$decoded = is_string( $data ) ? json_decode( $data, true ) : $data;
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Save Elementor element data to a post.
	 * Uses Document::save() when available for proper hooks and sanitization.
	 * Falls back to direct meta write for compatibility.
	 *
	 * @param int   $post_id      Post to save to.
	 * @param array $elements     Complete element tree.
	 * @param bool  $use_doc_api  Whether to use Document API (default true).
	 */
	public function save_elementor_data( int $post_id, array $elements, bool $use_doc_api = true ): bool {
		$expected_ids = $this->collect_element_ids( $elements );

		if ( $use_doc_api && class_exists( '\Elementor\Plugin' ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
				if ( $document ) {
					// Document::save() handles: sanitization, hooks, CSS regen, cache.
					$document->save( array(
						'elements' => $elements,
						'settings' => $document->get_settings(),
					) );

					$saved_elements = $this->get_elementor_data( $post_id );
					if ( $this->document_save_preserved_elements( $expected_ids, $saved_elements ) ) {
						return true;
					}

					$missing_ids = array_values( array_diff( $expected_ids, $this->collect_element_ids( is_array( $saved_elements ) ? $saved_elements : array() ) ) );
					PressArk_Error_Tracker::error(
						'Elementor',
						'Document::save() dropped Elementor elements; falling back to direct write',
						array(
							'post_id'        => $post_id,
							'expected_count' => count( $expected_ids ),
							'saved_count'    => is_array( $saved_elements ) ? count( $this->collect_element_ids( $saved_elements ) ) : 0,
							'missing_ids'    => array_slice( $missing_ids, 0, 10 ),
						)
					);
				}
			} catch ( \Throwable $e ) {
				PressArk_Error_Tracker::error( 'Elementor', 'Document::save() failed', array( 'post_id' => $post_id, 'error' => $e->getMessage() ) );
				// Fall through to direct write.
			}
		}

		// Direct write fallback.
		// wp_slash() is required because update_post_meta() runs wp_unslash(),
		// which would strip backslashes from JSON (e.g. \u2014 → u2014).
		// Elementor's own Document::save() does the same (see document.php:1368).
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Save a plain-text version to post_content for SEO / search.
		$this->save_plain_text( $post_id, $elements );

		$this->regenerate_post_css( $post_id );

		return true;
	}

	/**
	 * Collect all element IDs from an Elementor element tree.
	 *
	 * @param array $elements Elementor elements.
	 * @return array<string>
	 */
	private function collect_element_ids( array $elements ): array {
		$ids = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['id'] ) && is_string( $element['id'] ) ) {
				$ids[] = $element['id'];
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$ids = array_merge( $ids, $this->collect_element_ids( $element['elements'] ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Check whether a Document::save() round-trip preserved all expected IDs.
	 *
	 * @param array      $expected_ids  IDs from the input tree.
	 * @param array|null $saved_elements Tree re-read after save.
	 * @return bool
	 */
	private function document_save_preserved_elements( array $expected_ids, $saved_elements ): bool {
		if ( ! is_array( $saved_elements ) ) {
			return false;
		}

		if ( empty( $expected_ids ) ) {
			return empty( $this->collect_element_ids( $saved_elements ) );
		}

		$saved_ids = $this->collect_element_ids( $saved_elements );

		return empty( array_diff( $expected_ids, $saved_ids ) );
	}

	/**
	 * Extract plain text from element tree and save to post_content.
	 * Mirrors Elementor's DB::save_plain_text() for SEO and search.
	 *
	 * Uses $wpdb->update() instead of wp_update_post() to bypass ALL hooks.
	 * This is critical: wp_update_post() fires save_post which Elementor hooks
	 * into to wipe _elementor_edit_mode and _wp_page_template when it detects
	 * a save from outside the Elementor editor. Using $wpdb directly avoids
	 * this entirely while still updating post_content for SEO/search purposes.
	 */
	public function save_plain_text( int $post_id, array $elements ): void {
		global $wpdb;

		$texts = array();
		$this->collect_text_recursive( $elements, $texts );
		$plain = implode( "\n\n", array_filter( $texts ) );

		if ( true ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'post_content' => wp_strip_all_tags( $plain ) ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
			// Clear the post cache so WordPress reads the updated content.
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Recursively collect text content from widgets.
	 */
	private function collect_text_recursive( array $elements, array &$texts ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$settings = $el['settings'] ?? array();
			// Common text fields across widget types.
			foreach ( array( 'title', 'editor', 'text', 'testimonial_content', 'description_text', 'html' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					$texts[] = $settings[ $field ];
				}
			}
			// Icon-list items.
			if ( ! empty( $settings['icon_list'] ) && is_array( $settings['icon_list'] ) ) {
				foreach ( $settings['icon_list'] as $item ) {
					if ( ! empty( $item['text'] ) ) {
						$texts[] = $item['text'];
					}
				}
			}
			// Recurse into children.
			if ( ! empty( $el['elements'] ) ) {
				$this->collect_text_recursive( $el['elements'], $texts );
			}
		}
	}

	// ── A1: Widget Settings Schema ────────────────────────────────────

	/**
	 * Widget settings schema.
	 * Maps widget type -> settings keys + natural language aliases.
	 * The AI uses aliases to find the right key without guessing.
	 */
	public static function get_widget_schema(): array {
		return array(
			'heading' => array(
				'primary_field' => 'title',
				'fields' => array(
					'title'       => array( 'type' => 'text',   'aliases' => array( 'heading', 'headline', 'text', 'content', 'title' ) ),
					'header_size' => array( 'type' => 'select', 'aliases' => array( 'tag', 'level', 'size', 'h1', 'h2', 'h3' ),
					                        'options' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ),
					'link'        => array( 'type' => 'url',    'aliases' => array( 'url', 'href', 'link' ) ),
					'align'       => array( 'type' => 'select', 'aliases' => array( 'alignment', 'align' ),
					                        'options' => array( 'left', 'center', 'right', 'justify' ) ),
				),
			),
			'text-editor' => array(
				'primary_field' => 'editor',
				'fields' => array(
					'editor' => array( 'type' => 'wysiwyg', 'aliases' => array( 'text', 'content', 'body', 'paragraph', 'html' ) ),
				),
			),
			'button' => array(
				'primary_field' => 'text',
				'fields' => array(
					'text'  => array( 'type' => 'text',   'aliases' => array( 'label', 'button text', 'title', 'name', 'text' ) ),
					'link'  => array( 'type' => 'url',    'aliases' => array( 'url', 'href', 'link', 'destination' ) ),
					'align' => array( 'type' => 'select', 'aliases' => array( 'alignment', 'align' ),
					                  'options' => array( 'left', 'center', 'right', 'justify' ) ),
					'size'  => array( 'type' => 'select', 'aliases' => array( 'size', 'padding' ),
					                  'options' => array( 'xs', 'sm', 'md', 'lg', 'xl' ) ),
				),
			),
			'image' => array(
				'primary_field' => 'image',
				'fields' => array(
					'image'   => array( 'type' => 'media',  'aliases' => array( 'image', 'photo', 'picture', 'src' ) ),
					'caption' => array( 'type' => 'text',   'aliases' => array( 'caption', 'description' ) ),
					'link'    => array( 'type' => 'url',    'aliases' => array( 'link', 'url', 'href' ) ),
					'alt'     => array( 'type' => 'text',   'aliases' => array( 'alt', 'alt text', 'alternative text' ) ),
				),
			),
			'video' => array(
				'primary_field' => 'link',
				'fields' => array(
					'link'     => array( 'type' => 'url',  'aliases' => array( 'url', 'src', 'video url', 'link' ) ),
					'autoplay' => array( 'type' => 'bool', 'aliases' => array( 'autoplay', 'auto play', 'auto-play' ) ),
				),
			),
			'icon' => array(
				'primary_field' => 'selected_icon',
				'fields' => array(
					'selected_icon' => array( 'type' => 'icon',   'aliases' => array( 'icon', 'symbol' ) ),
					'align'         => array( 'type' => 'select', 'aliases' => array( 'alignment', 'align' ),
					                          'options' => array( 'left', 'center', 'right' ) ),
					'link'          => array( 'type' => 'url',    'aliases' => array( 'link', 'url' ) ),
					'size'          => array( 'type' => 'text',   'aliases' => array( 'size', 'width' ) ),
				),
			),
			'icon-box' => array(
				'primary_field' => 'title_text',
				'fields' => array(
					'title_text'       => array( 'type' => 'text', 'aliases' => array( 'title', 'heading' ) ),
					'description_text' => array( 'type' => 'text', 'aliases' => array( 'description', 'text', 'content', 'body' ) ),
					'selected_icon'    => array( 'type' => 'icon', 'aliases' => array( 'icon' ) ),
					'link'             => array( 'type' => 'url',  'aliases' => array( 'link', 'url' ) ),
				),
			),
			'image-box' => array(
				'primary_field' => 'title_text',
				'fields' => array(
					'title_text'       => array( 'type' => 'text',  'aliases' => array( 'title', 'heading' ) ),
					'description_text' => array( 'type' => 'text',  'aliases' => array( 'description', 'text', 'content', 'body' ) ),
					'image'            => array( 'type' => 'media', 'aliases' => array( 'image', 'photo' ) ),
					'link'             => array( 'type' => 'url',   'aliases' => array( 'link', 'url' ) ),
				),
			),
			'testimonial' => array(
				'primary_field' => 'testimonial_content',
				'fields' => array(
					'testimonial_content' => array( 'type' => 'textarea', 'aliases' => array( 'content', 'quote', 'text', 'testimonial' ) ),
					'testimonial_name'    => array( 'type' => 'text',     'aliases' => array( 'name', 'author', 'person' ) ),
					'testimonial_job'     => array( 'type' => 'text',     'aliases' => array( 'job', 'title', 'position', 'role' ) ),
					'testimonial_image'   => array( 'type' => 'media',    'aliases' => array( 'image', 'photo', 'avatar' ) ),
				),
			),
			'counter' => array(
				'primary_field' => 'starting_number',
				'fields' => array(
					'starting_number' => array( 'type' => 'number', 'aliases' => array( 'start', 'from', 'starting number' ) ),
					'ending_number'   => array( 'type' => 'number', 'aliases' => array( 'end', 'to', 'number', 'count', 'value' ) ),
					'title'           => array( 'type' => 'text',   'aliases' => array( 'title', 'label', 'suffix text' ) ),
					'prefix'          => array( 'type' => 'text',   'aliases' => array( 'prefix', 'before' ) ),
					'suffix'          => array( 'type' => 'text',   'aliases' => array( 'suffix', 'after', 'unit' ) ),
				),
			),
			'progress' => array(
				'primary_field' => 'percent',
				'fields' => array(
					'percent' => array( 'type' => 'number', 'aliases' => array( 'percent', 'percentage', 'value', 'progress' ) ),
					'title'   => array( 'type' => 'text',   'aliases' => array( 'title', 'label' ) ),
				),
			),
			'divider' => array(
				'primary_field' => 'style',
				'fields' => array(
					'style'  => array( 'type' => 'select', 'aliases' => array( 'style', 'type' ),
					                   'options' => array( 'solid', 'double', 'dotted', 'dashed' ) ),
					'weight' => array( 'type' => 'number', 'aliases' => array( 'weight', 'thickness', 'height' ) ),
					'color'  => array( 'type' => 'color',  'aliases' => array( 'color', 'colour' ) ),
				),
			),
			'spacer' => array(
				'primary_field' => 'space',
				'fields' => array(
					'space' => array( 'type' => 'slider', 'aliases' => array( 'space', 'height', 'size', 'gap' ) ),
				),
			),
			'html' => array(
				'primary_field' => 'html',
				'fields' => array(
					'html' => array( 'type' => 'code', 'aliases' => array( 'html', 'code', 'markup', 'content' ) ),
				),
			),
			'shortcode' => array(
				'primary_field' => 'shortcode',
				'fields' => array(
					'shortcode' => array( 'type' => 'text', 'aliases' => array( 'shortcode', 'code' ) ),
				),
			),
			'google_maps' => array(
				'primary_field' => 'address',
				'fields' => array(
					'address' => array( 'type' => 'text',   'aliases' => array( 'address', 'location', 'place' ) ),
					'zoom'    => array( 'type' => 'number', 'aliases' => array( 'zoom', 'zoom level' ) ),
				),
			),
			'wp-widget-media_image' => array(
				'primary_field' => 'url',
				'fields' => array(
					'url' => array( 'type' => 'url',  'aliases' => array( 'url', 'src', 'image url' ) ),
					'alt' => array( 'type' => 'text', 'aliases' => array( 'alt', 'alt text' ) ),
				),
			),
		);
	}

	/**
	 * Resolve a natural-language field alias to the actual Elementor settings key.
	 * Checks manual aliases first, then auto-discovered schema labels.
	 *
	 * @param string $widget_type  Elementor widget type (e.g., 'heading').
	 * @param string $field_alias  Natural language field name (e.g., 'headline').
	 * @return string|null The actual settings key, or null if not found.
	 */
	public static function resolve_field_key( string $widget_type, string $field_alias ): ?string {
		if ( method_exists( __CLASS__, 'resolve_runtime_field_key' ) ) {
			return self::resolve_runtime_field_key( $widget_type, $field_alias );
		}

		$alias_lower = strtolower( trim( $field_alias ) );

		// 1. Check manual schema aliases first (backward compat).
		$manual_schema = self::get_widget_schema();
		$manual_widget = $manual_schema[ $widget_type ] ?? null;

		if ( $manual_widget ) {
			foreach ( $manual_widget['fields'] as $key => $field_def ) {
				if ( $key === $alias_lower ) {
					return $key;
				}
				if ( in_array( $alias_lower, $field_def['aliases'] ?? array(), true ) ) {
					return $key;
				}
			}
		}

		// 2. Try auto-discovered schema — match against control IDs and labels.
		$instance    = new self();
		$auto_schema = $instance->get_widget_schema_entry( $widget_type );

		foreach ( $auto_schema['fields'] ?? array() as $control_id => $field ) {
			// Exact control ID match.
			if ( strtolower( $control_id ) === $alias_lower ) {
				return $control_id;
			}
			// Label match (human-readable name).
			if ( ! empty( $field['label'] ) && strtolower( $field['label'] ) === $alias_lower ) {
				return $control_id;
			}
		}

		// 3. Return as-is — may be a direct Elementor control ID.
		// Do NOT fall back to primary_field; that silently maps unrecognized aliases
		// to the wrong key (e.g., 'align' → 'selected_icon' for icon widget).
		return $field_alias;
	}

	/**
	 * Runtime-safe alias resolution that prefers Elementor's live control schema.
	 *
	 * @param string $widget_type Elementor widget type.
	 * @param string $field_alias Requested field alias.
	 * @return string|null
	 */
	private static function resolve_runtime_field_key( string $widget_type, string $field_alias ): ?string {
		$alias_lower = strtolower( trim( $field_alias ) );
		$instance    = new self();
		$auto_schema = $instance->get_widget_schema_entry( $widget_type );
		$auto_fields = $auto_schema['fields'] ?? array();

		foreach ( $auto_fields as $control_id => $field ) {
			if ( strtolower( $control_id ) === $alias_lower ) {
				return $control_id;
			}
			if ( ! empty( $field['label'] ) && strtolower( (string) $field['label'] ) === $alias_lower ) {
				return $control_id;
			}
		}

		$manual_schema = self::get_widget_schema();
		$manual_widget = $manual_schema[ $widget_type ] ?? null;

		if ( $manual_widget ) {
			foreach ( $manual_widget['fields'] as $key => $field_def ) {
				$is_known_live_field = empty( $auto_fields ) || isset( $auto_fields[ $key ] );
				if ( $key === $alias_lower && $is_known_live_field ) {
					return $key;
				}
				if ( $is_known_live_field && in_array( $alias_lower, $field_def['aliases'] ?? array(), true ) ) {
					return $key;
				}
			}
		}

		return $field_alias;
	}

	// ── A2: Structured Page Reader ────────────────────────────────────

	/**
	 * Read an Elementor page and return a structured, human-readable tree.
	 * Handles both legacy (section/column) and modern (container) structures.
	 * Includes widget IDs for editing, content summary, and inline issue flags.
	 *
	 * @param int $post_id Post ID.
	 * @return array Structured page data.
	 */
	public function read_page( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return array( 'error' => 'No Elementor data found. This page may not use Elementor.' );
		}

		$data   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			return array( 'error' => 'Elementor data is invalid or could not be parsed.' );
		}

		$stats  = array( 'sections' => 0, 'columns' => 0, 'containers' => 0, 'widgets' => 0, 'words' => 0 );
		$issues = array();
		$tree   = array();

		foreach ( $data as $element ) {
			$parsed = $this->parse_element( $element, $issues, $stats );
			if ( $parsed ) {
				$tree[] = $parsed;
			}
		}

		// Report layout type so the AI knows which structure this site uses.
		$layout_type = ( $stats['containers'] ?? 0 ) > 0 ? 'flexbox_containers' : 'sections';

		return array(
			'post_id'     => $post_id,
			'title'       => $post->post_title,
			'url'         => get_permalink( $post_id ),
			'status'      => $post->post_status,
			'layout_type' => $layout_type,
			'stats'       => $stats,
			'structure'   => $tree,
			'issues'      => $issues,
			'edit_hint'   => 'Use widget IDs from structure to call elementor_edit_widget.',
		);
	}

	/**
	 * Legacy alias for read_page — used by old action engine handler.
	 */
	public function read_page_structure( int $post_id ): array {
		return $this->read_page( $post_id );
	}

	/**
	 * Recursively parse any Elementor element.
	 * Handles both legacy (section/column) and modern (container) structures.
	 *
	 * @param array  $element   Raw element from _elementor_data.
	 * @param array  &$issues   Issues accumulator.
	 * @param array  &$stats    Stats accumulator.
	 * @param int    $depth     Current nesting depth.
	 * @return array|null       Parsed node, or null if invalid.
	 */
	private function parse_element( array $element, array &$issues, array &$stats, int $depth = 0 ): ?array {
		$el_type     = $element['elType']    ?? 'unknown';
		$widget_type = $element['widgetType'] ?? '';
		$settings    = $element['settings']  ?? array();
		$el_id       = $element['id']        ?? '';

		// ── Structural elements ──────────────────────────────────────
		if ( in_array( $el_type, array( 'section', 'column', 'container' ), true ) ) {
			$node = array(
				'id'       => $el_id,
				'type'     => $el_type,
				'label'    => $settings['_title'] ?? $el_type,
				'children' => array(),
			);

			// Type-specific metadata.
			switch ( $el_type ) {
				case 'section':
					$stats['sections'] = ( $stats['sections'] ?? 0 ) + 1;
					$node['stretch']   = ! empty( $settings['stretch_section'] );
					break;

				case 'column':
					$stats['columns'] = ( $stats['columns'] ?? 0 ) + 1;
					$node['width']    = $settings['_column_size'] ?? 100;
					break;

				case 'container':
					$stats['containers'] = ( $stats['containers'] ?? 0 ) + 1;
					$node['direction']   = $settings['flex_direction'] ?? 'column';
					$node['width_type']  = $settings['content_width'] ?? 'boxed';
					break;
			}

			// Element-level display conditions (Elementor 3.19+ free).
			if ( ! empty( $settings['_element_conditions'] ) ) {
				$node['display_conditions']   = $this->parse_display_conditions( $settings['_element_conditions'] );
				$node['has_visibility_rules'] = true;
			}
			if ( ! empty( $settings['_visibility'] ) ) {
				$node['visibility']           = $settings['_visibility'];
				$node['has_visibility_rules'] = true;
			}
			$hidden_on = array();
			if ( ! empty( $settings['hide_desktop'] ) ) {
				$hidden_on[] = 'desktop';
			}
			if ( ! empty( $settings['hide_tablet'] ) ) {
				$hidden_on[] = 'tablet';
			}
			if ( ! empty( $settings['hide_mobile'] ) ) {
				$hidden_on[] = 'mobile';
			}
			if ( ! empty( $hidden_on ) ) {
				$node['hidden_on']            = $hidden_on;
				$node['has_visibility_rules'] = true;
			}

			// Flag empty structural elements.
			if ( empty( $element['elements'] ) ) {
				$issues[] = array(
					'type'    => 'empty_' . $el_type,
					'id'      => $el_id,
					'message' => ucfirst( $el_type ) . " #{$el_id} is empty.",
				);
			}

			// Recurse into children.
			foreach ( $element['elements'] ?? array() as $child ) {
				$parsed = $this->parse_element( $child, $issues, $stats, $depth + 1 );
				if ( $parsed ) {
					$node['children'][] = $parsed;
				}
			}

			return $node;
		}

		// ── Widget elements ──────────────────────────────────────────
		if ( $el_type === 'widget' ) {
			$stats['widgets'] = ( $stats['widgets'] ?? 0 ) + 1;
			return $this->parse_widget_node( $element, $issues, $stats );
		}

		// Unknown element type — pass through.
		return array( 'id' => $el_id, 'type' => $el_type, 'unknown' => true );
	}

	/**
	 * Extract widget data from an element node.
	 */
	private function parse_widget_node( array $widget, array &$issues, array &$stats ): array {
		$type     = $widget['widgetType'] ?? 'unknown';
		$settings = $widget['settings']   ?? array();
		$el_id    = $widget['id']         ?? '';

		// Handle global widget references — actual content lives in a separate template post.
		if ( $type === 'global' && ! empty( $widget['templateID'] ) ) {
			$template_id = (int) $widget['templateID'];
			return array(
				'id'           => $el_id,
				'el_type'      => 'widget',
				'widget_type'  => 'global',
				'label'        => 'Global Widget',
				'template_id'  => $template_id,
				'template_title'=> get_the_title( $template_id ) ?: "Template #{$template_id}",
				'note'         => 'This is a global widget — editing it will update ALL pages that use it. '
				                . 'Use elementor_edit_widget with post_id=' . $template_id . ' to edit.',
				'is_global'    => true,
			);
		}

		$schema   = self::get_widget_schema()[ $type ] ?? null;
		$node     = array(
			'id'       => $el_id,
			'type'     => $type,
			'preview'  => '',
			'settings' => array(),
			'flags'    => array(),
		);

		// Check for dynamic tags (warn AI not to overwrite these).
		$dynamic_map = $settings['__dynamic__'] ?? array();
		$globals_map = $settings['__globals__'] ?? array();

		if ( ! empty( $dynamic_map ) ) {
			$decoded_dynamics = array();

			foreach ( $dynamic_map as $field => $tag_text ) {
				// Decode the tag name from [elementor-tag id="..." name="post-title" settings="..."]
				$tag_name = '';
				if ( preg_match( '/name="([^"]+)"/', $tag_text, $m ) ) {
					$tag_name = $m[1];
				}

				// Human-readable tag labels.
				$tag_labels = array(
					'post-title'          => 'Post Title',
					'post-excerpt'        => 'Post Excerpt',
					'post-content'        => 'Post Content',
					'post-featured-image' => 'Featured Image',
					'post-url'            => 'Post URL',
					'post-date'           => 'Post Date',
					'post-time'           => 'Post Time',
					'author-name'         => 'Author Name',
					'author-meta'         => 'Author Meta',
					'site-logo'           => 'Site Logo',
					'site-title'          => 'Site Title',
					'site-tagline'        => 'Site Tagline',
					'current-date'        => 'Current Date',
					'request-param'       => 'URL Parameter',
					'acf'                 => 'ACF Field',
					'pods'                => 'Pods Field',
					'toolset'             => 'Toolset Field',
					'custom-field'        => 'Custom Field',
				);

				$decoded_dynamics[ $field ] = array(
					'tag'      => $tag_name,
					'label'    => $tag_labels[ $tag_name ] ?? $tag_name,
					'raw'      => $tag_text,
					'editable' => false,
				);
			}

			$node['dynamic_fields']  = $decoded_dynamics;
			$node['dynamic_warning'] = sprintf(
				'%d field(s) use dynamic tags (%s) — editing them replaces dynamic content with static text.',
				count( $decoded_dynamics ),
				implode( ', ', array_column( $decoded_dynamics, 'label' ) )
			);
		}

		// Resolve global color/font references to human-readable names.
		if ( ! empty( $globals_map ) ) {
			$resolved_globals = array();
			foreach ( $globals_map as $field_key => $global_ref ) {
				// Format: "globals/colors?id=primary" or "globals/typography?id=body"
				$query_str = wp_parse_url( $global_ref, PHP_URL_QUERY );
				$query_params = array();
				if ( $query_str ) {
					parse_str( $query_str, $query_params );
				}
				$global_id   = $query_params['id'] ?? '';
				$global_type = str_contains( $global_ref, 'colors' ) ? 'color' : 'typography';
				$resolved_globals[ $field_key ] = array(
					'type'      => $global_type,
					'global_id' => $global_id,
					'note'      => "Uses global {$global_type} '{$global_id}' from kit settings.",
				);
			}
			$node['global_fields'] = $resolved_globals;
		}

		// Detect repeater fields (icon lists, pricing tables, FAQs, etc.).
		$repeaters = array();
		foreach ( $settings as $key => $value ) {
			// Repeater fields are arrays of items, each with an _id key.
			if ( is_array( $value ) && ! empty( $value ) && isset( $value[0] ) && is_array( $value[0] ) && isset( $value[0]['_id'] ) ) {
				$repeaters[ $key ] = array(
					'count'       => count( $value ),
					'item_fields' => array_keys( array_diff_key( $value[0], array( '_id' => '' ) ) ),
					'hint'        => "Use item_index + item_fields to edit individual {$key} items.",
				);
			}
		}
		if ( ! empty( $repeaters ) ) {
			$node['repeaters'] = $repeaters;
		}

		// Build preview text and structured settings based on widget type.
		switch ( $type ) {
			case 'heading':
				$text               = wp_strip_all_tags( $settings['title'] ?? '' );
				$node['preview']    = '"' . mb_substr( $text, 0, 80 ) . '"'
				                    . ' (' . strtoupper( $settings['header_size'] ?? 'h2' ) . ')';
				$node['settings']   = array(
					'title'       => $text,
					'header_size' => $settings['header_size'] ?? 'h2',
					'link'        => $settings['link']['url'] ?? '',
				);
				$stats['words']    += str_word_count( $text );
				break;

			case 'text-editor':
				$text               = wp_strip_all_tags( $settings['editor'] ?? '' );
				$words              = str_word_count( $text );
				$stats['words']    += $words;
				$node['preview']    = mb_substr( $text, 0, 100 ) . ( strlen( $text ) > 100 ? '...' : '' )
				                    . ' (' . $words . ' words)';
				$node['settings']   = array( 'editor' => $settings['editor'] ?? '' );
				if ( $words < 20 ) {
					$node['flags'][] = 'thin_content';
					$issues[] = array(
						'type'    => 'thin_content',
						'id'      => $widget['id'],
						'message' => 'Text widget has fewer than 20 words.',
					);
				}
				break;

			case 'button':
				$label              = $settings['text'] ?? '';
				$url                = $settings['link']['url'] ?? '';
				$node['preview']    = '"' . $label . '" -> ' . ( $url ?: '(no link)' );
				$node['settings']   = array( 'text' => $label, 'link' => $url );
				if ( empty( $url ) ) {
					$node['flags'][] = 'missing_link';
					$issues[] = array(
						'type'    => 'missing_link',
						'id'      => $widget['id'],
						'message' => 'Button widget has no URL.',
					);
				}
				break;

			case 'image':
				$url                = $settings['image']['url'] ?? '';
				$alt                = $settings['image_alt'] ?? $settings['alt'] ?? '';
				$node['preview']    = basename( $url ) . ( $alt ? ' (alt: "' . $alt . '")' : ' — no alt text' );
				$node['settings']   = array( 'image_url' => $url, 'alt' => $alt );
				if ( empty( $alt ) && ! empty( $settings['image']['url'] ) ) {
					$node['flags'][] = 'missing_alt';
					$issues[] = array(
						'type'    => 'missing_alt',
						'id'      => $widget['id'],
						'message' => 'Image widget is missing alt text.',
					);
				}
				break;

			default:
				// For unknown widgets, show the primary field if schema exists.
				if ( $schema ) {
					$primary_key     = $schema['primary_field'];
					$primary_val     = $settings[ $primary_key ] ?? '';
					$node['preview'] = $type . ': ' . mb_substr( wp_strip_all_tags( is_string( $primary_val ) ? $primary_val : '' ), 0, 60 );
				} else {
					$node['preview'] = $type . ' widget';
				}
				$node['settings'] = $settings;
				break;
		}

		// Widget-level display conditions and responsive visibility.
		if ( ! empty( $settings['_element_conditions'] ) ) {
			$node['display_conditions']   = $this->parse_display_conditions( $settings['_element_conditions'] );
			$node['has_visibility_rules'] = true;
		}
		if ( ! empty( $settings['_visibility'] ) ) {
			$node['visibility']           = $settings['_visibility'];
			$node['has_visibility_rules'] = true;
		}
		$hidden_on = array();
		if ( ! empty( $settings['hide_desktop'] ) ) {
			$hidden_on[] = 'desktop';
		}
		if ( ! empty( $settings['hide_tablet'] ) ) {
			$hidden_on[] = 'tablet';
		}
		if ( ! empty( $settings['hide_mobile'] ) ) {
			$hidden_on[] = 'mobile';
		}
		if ( ! empty( $hidden_on ) ) {
			$node['hidden_on']            = $hidden_on;
			$node['has_visibility_rules'] = true;
		}

		return $node;
	}

	// ── A3: Edit Widget with Schema Resolution ────────────────────────

	/**
	 * Edit an Elementor widget by ID.
	 * Accepts natural language field names (e.g., "headline") and
	 * resolves them to actual Elementor settings keys via the schema.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $widget_id  Widget ID from elementor_read_page structure.
	 * @param array  $fields     Associative array of field_alias => new_value.
	 * @param string $device     Target device: 'desktop' (default), 'tablet', or 'mobile'.
	 * @return array Result with before/after for each changed field.
	 */
	public function edit_widget( int $post_id, string $widget_id, array $fields, string $device = 'desktop' ): array {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( empty( $data ) ) {
			return array( 'error' => 'No Elementor data found for this post.' );
		}

		// Guardrail: validate widget ID exists before attempting edit.
		if ( ! $this->widget_id_exists( $data, $widget_id ) ) {
			return array(
				'error' => "Widget ID '{$widget_id}' does not exist on this page. "
				         . 'Call elementor_read_page first to get valid widget IDs.',
			);
		}

		// Apply responsive device suffix to field keys.
		$valid_devices = array(
			'desktop'      => '',
			'widescreen'   => '_widescreen',
			'laptop'       => '_laptop',
			'tablet_extra' => '_tablet_extra',
			'tablet'       => '_tablet',
			'mobile_extra' => '_mobile_extra',
			'mobile'       => '_mobile',
		);

		$device = sanitize_text_field( $device ?? 'desktop' );
		if ( ! isset( $valid_devices[ $device ] ) ) {
			return array(
				'error'         => "Unknown device '{$device}'.",
				'valid_devices' => array_keys( $valid_devices ),
			);
		}

		$device_suffix = $valid_devices[ $device ];

		if ( $device_suffix ) {
			$suffixed_fields = array();
			foreach ( $fields as $key => $value ) {
				// Don't suffix keys that already have a device suffix.
				$already_responsive = false;
				$responsive_suffixes = array( '_tablet', '_mobile', '_widescreen', '_laptop', '_tablet_extra', '_mobile_extra' );
				foreach ( $responsive_suffixes as $suffix ) {
					if ( str_ends_with( $key, $suffix ) ) {
						$already_responsive = true;
						break;
					}
				}
				$suffixed_fields[ $already_responsive ? $key : $key . $device_suffix ] = $value;
			}
			$fields = $suffixed_fields;
		}

		$changed = array();

		// Walk the structure recursively to find and update the widget.
		$data = $this->walk_and_edit( $data, $widget_id, $fields, $changed );

		if ( empty( $changed ) ) {
			return array( 'error' => "Widget ID '{$widget_id}' not found on this page." );
		}

		$this->save_elementor_data( $post_id, $data );

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'widget_id' => $widget_id,
			'device'    => $device,
			'changes'   => $changed,
			'message'   => 'Widget updated' . ( $device !== 'desktop' ? " for {$device}" : '' ) . '.',
		);
	}

	/**
	 * Edit a specific item within a repeater field on an Elementor widget.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $widget_id      Widget ID from elementor_read_page.
	 * @param string $repeater_field Repeater field key (e.g., 'social_icon_list').
	 * @param int    $item_index     0-based index of the item to edit.
	 * @param array  $item_fields    Fields to update within the repeater item.
	 * @return array Result.
	 */
	public function edit_widget_repeater_item(
		int    $post_id,
		string $widget_id,
		string $repeater_field,
		int    $item_index,
		array  $item_fields
	): array {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( empty( $data ) ) {
			return array( 'error' => 'No Elementor data found for this post.' );
		}

		if ( ! $this->widget_id_exists( $data, $widget_id ) ) {
			return array(
				'error' => "Widget ID '{$widget_id}' does not exist on this page.",
			);
		}

		$result = array();
		$data   = $this->walk_and_edit_repeater(
			$data, $widget_id, $repeater_field, $item_index, $item_fields, $result
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$this->save_elementor_data( $post_id, $data );

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'widget_id'      => $widget_id,
			'repeater_field' => $repeater_field,
			'item_index'     => $item_index,
			'updated'        => array_keys( $item_fields ),
			'message'        => "Repeater item {$item_index} in '{$repeater_field}' updated.",
		);
	}

	/**
	 * Walk tree to find widget and edit a specific repeater item.
	 */
	private function walk_and_edit_repeater(
		array  $elements,
		string $target_id,
		string $repeater_field,
		int    $item_index,
		array  $item_fields,
		array  &$result
	): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( ( $el['id'] ?? '' ) === $target_id && isset( $el['widgetType'] ) ) {
				$widget_type   = $el['widgetType'];
				$resolved_key  = self::resolve_field_key( $widget_type, $repeater_field );
				$repeater_data = $el['settings'][ $resolved_key ] ?? null;

				if ( ! is_array( $repeater_data ) ) {
					$result = array( 'error' => "Field '{$repeater_field}' is not a repeater on widget type '{$widget_type}'." );
					return $elements;
				}

				if ( ! isset( $repeater_data[ $item_index ] ) ) {
					$result = array( 'error' => "Repeater item index {$item_index} does not exist (max: " . ( count( $repeater_data ) - 1 ) . ').' );
					return $elements;
				}

				// Merge item fields into the specific repeater item.
				$warnings = array();
				foreach ( $item_fields as $item_field_key => $item_field_value ) {
					// Check and clear __dynamic__ binding on this repeater field.
					if ( isset( $el['settings'][ $resolved_key ][ $item_index ]['__dynamic__'][ $item_field_key ] ) ) {
						$warnings[] = sprintf(
							"Repeater item field '%s' had a dynamic tag binding — it has been replaced with a static value.",
							$item_field_key
						);
						unset( $el['settings'][ $resolved_key ][ $item_index ]['__dynamic__'][ $item_field_key ] );
						if ( empty( $el['settings'][ $resolved_key ][ $item_index ]['__dynamic__'] ) ) {
							unset( $el['settings'][ $resolved_key ][ $item_index ]['__dynamic__'] );
						}
					}

					// Check and clear __globals__ binding on this repeater field.
					if ( isset( $el['settings'][ $resolved_key ][ $item_index ]['__globals__'][ $item_field_key ] ) ) {
						$warnings[] = sprintf(
							"Repeater item field '%s' was linked to a global design token — the link has been removed.",
							$item_field_key
						);
						unset( $el['settings'][ $resolved_key ][ $item_index ]['__globals__'][ $item_field_key ] );
						if ( empty( $el['settings'][ $resolved_key ][ $item_index ]['__globals__'] ) ) {
							unset( $el['settings'][ $resolved_key ][ $item_index ]['__globals__'] );
						}
					}

					// Handle nested link format within repeater items.
					if ( $item_field_key === 'link' && is_string( $item_field_value ) ) {
						$item_field_value = array( 'url' => $item_field_value, 'is_external' => '', 'nofollow' => '' );
					}
					$el['settings'][ $resolved_key ][ $item_index ][ $item_field_key ] = $item_field_value;
				}

				$result = array( 'found' => true );
				if ( ! empty( $warnings ) ) {
					$result['warnings'] = $warnings;
				}
				return $elements;
			}

			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->walk_and_edit_repeater(
					$el['elements'], $target_id, $repeater_field, $item_index, $item_fields, $result
				);
				if ( ! empty( $result ) ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	/**
	 * Recursively walk Elementor data tree to find and edit a widget.
	 */
	private function walk_and_edit(
		array  $elements,
		string $target_id,
		array  $fields,
		array  &$changed
	): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			if ( ( $el['id'] ?? '' ) === $target_id && isset( $el['widgetType'] ) ) {
				$widget_type  = $el['widgetType'];
				$dynamic_map  = $el['settings']['__dynamic__'] ?? array();

				foreach ( $fields as $alias => $new_value ) {
					$key = self::resolve_field_key( $widget_type, $alias );

					if ( ! $key ) {
						$changed[] = array(
							'alias'  => $alias,
							'status' => 'skipped',
							'reason' => "No field '{$alias}' found for widget type '{$widget_type}'.",
						);
						continue;
					}

					// Warn if overwriting a dynamic tag field.
					if ( isset( $dynamic_map[ $key ] ) ) {
						$changed[] = array(
							'field'   => $key,
							'alias'   => $alias,
							'warning' => "Field '{$key}' currently has a dynamic tag. "
								. 'Setting a static value will replace the dynamic tag. '
								. 'The change has been applied — verify the result in preview.',
						);
						// Remove the dynamic entry so it doesn't override the static value.
						unset( $el['settings']['__dynamic__'][ $key ] );
					}

					// Also clear __globals__ link — global token would override static value.
					$globals_map = $el['settings']['__globals__'] ?? array();
					if ( isset( $globals_map[ $key ] ) ) {
						$changed[] = array(
							'field'   => $key,
							'alias'   => $alias ?? $key,
							'warning' => sprintf(
								"Field '%s' was linked to global design token '%s'. "
								. "The global link has been removed to apply the static value. "
								. "If you want to restore the global link, use the Elementor editor.",
								$key,
								$globals_map[ $key ]
							),
						);
						unset( $el['settings']['__globals__'][ $key ] );

						// Clean up empty __globals__ array.
						if ( empty( $el['settings']['__globals__'] ) ) {
							unset( $el['settings']['__globals__'] );
						}
					}

					$old_value = $el['settings'][ $key ] ?? null;

					// Handle nested link format.
					if ( $key === 'link' && is_string( $new_value ) ) {
						$new_value = array( 'url' => $new_value, 'is_external' => '', 'nofollow' => '' );
					}

					$el['settings'][ $key ] = $new_value;

					$changed[] = array(
						'field'  => $key,
						'alias'  => $alias,
						'before' => is_array( $old_value ) ? ( $old_value['url'] ?? wp_json_encode( $old_value ) ) : $old_value,
						'after'  => is_array( $new_value ) ? ( $new_value['url'] ?? wp_json_encode( $new_value ) ) : $new_value,
					);
				}
				continue;
			}

			// Recurse into children.
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->walk_and_edit( $el['elements'], $target_id, $fields, $changed );
			}
		}
		return $elements;
	}

	/**
	 * Legacy alias — old action engine handler calls update_widget().
	 */
	public function update_widget( int $post_id, string $widget_id, array $changes ): array {
		// Store previous data for undo logging.
		$previous_data = get_post_meta( $post_id, '_elementor_data', true );
		$result        = $this->edit_widget( $post_id, $widget_id, $changes );

		if ( isset( $result['success'] ) && $result['success'] ) {
			$result['previous_data'] = $previous_data;
		}

		return $result;
	}

	// ── A3b: Add Widget ──────────────────────────────────────────────

	/**
	 * Add a new widget to an Elementor page.
	 *
	 * Inserts the widget into the first container (Flexbox) or the first
	 * section→column (legacy) found in the page data. Optionally insert
	 * into a specific parent element by passing $container_id.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $widget_type   Widget type name (e.g., 'heading', 'text-editor', 'button').
	 * @param array  $settings      Widget settings (keys can be natural language aliases).
	 * @param string $container_id  Optional parent element ID to insert into.
	 * @param int    $position      Insert position within container (-1 = append).
	 * @return array Result with widget_id on success.
	 */
	public function add_widget(
		int    $post_id,
		string $widget_type,
		array  $settings     = array(),
		string $container_id = '',
		int    $position     = -1
	): array {
		$data = $this->get_elementor_data( $post_id );
		if ( null === $data ) {
			return array( 'success' => false, 'error' => 'No Elementor data found for post #' . $post_id . '.' );
		}

		if ( empty( $data ) ) {
			$data = $this->get_initial_elementor_structure();
		}

		// Resolve natural-language aliases to real Elementor settings keys.
		$resolved = array();
		foreach ( $settings as $alias => $value ) {
			$key = self::resolve_field_key( $widget_type, $alias );
			if ( $key ) {
				// Handle nested link format.
				if ( $key === 'link' && is_string( $value ) ) {
					$value = array( 'url' => $value, 'is_external' => '', 'nofollow' => '' );
				}
				$resolved[ $key ] = $value;
			} else {
				// Pass through as-is (exact Elementor key).
				$resolved[ $alias ] = $value;
			}
		}

		// Build the new widget element node with sensible defaults merged with user settings.
		$new_widget = array(
			'id'         => $this->generate_element_id(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $this->build_widget_settings( $widget_type, $resolved ),
			'elements'   => array(),
		);
		$new_widget = $this->normalize_new_element_data( $new_widget );

		// Find the target container and insert the widget.
		$inserted = false;
		$data     = $this->walk_and_insert( $data, $new_widget, $container_id, $position, $inserted );

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'error'   => $container_id
					? "Container '{$container_id}' not found."
					: 'No container found on this page.',
				'hint'    => $container_id
					? 'Use elementor_read_page to get container IDs. Look for el_type: "container" or "section".'
					: 'Use elementor_add_container to add a container first, or provide container_id.',
			);
		}

		// Save the updated data through Elementor's document pipeline when possible.
		$this->save_elementor_data( $post_id, $data );

		return array(
			'success'      => true,
			'widget_id'    => $new_widget['id'],
			'widget_type'  => $widget_type,
			'container_id' => $container_id ?: '(first available)',
			'position'     => $position === -1 ? 'end' : $position,
			'post_id'      => $post_id,
			'settings'     => $new_widget['settings'],
			'message'      => "Added '{$widget_type}' widget to container. Widget ID: {$new_widget['id']}.",
			'next'         => "Use elementor_edit_widget with widget_id '{$new_widget['id']}' to adjust settings.",
		);
	}

	/**
	 * Walk the element tree and insert a widget into the first valid container,
	 * or into a specific container identified by $container_id.
	 *
	 * Valid insertion targets:
	 *   - 'container' (Flexbox mode)
	 *   - 'column' (Legacy section→column mode)
	 */
	private function walk_and_insert(
		array  $elements,
		array  $new_widget,
		string $container_id,
		int    $position,
		bool   &$inserted
	): array {
		foreach ( $elements as &$el ) {
			if ( $inserted ) {
				break;
			}
			if ( ! is_array( $el ) ) {
				continue;
			}

			$el_type = $el['elType'] ?? '';
			$el_id   = $el['id'] ?? '';

			// Check if this element is a valid container.
			$is_container = ( $el_type === 'container' ) || ( $el_type === 'column' );

			// If we need a specific container, only match by ID.
			if ( $container_id && $el_id === $container_id && $is_container ) {
				$this->insert_at_position( $el['elements'], $new_widget, $position );
				$inserted = true;
				return $elements;
			}

			// If no specific container requested, use first valid one.
			if ( ! $container_id && $is_container ) {
				$this->insert_at_position( $el['elements'], $new_widget, $position );
				$inserted = true;
				return $elements;
			}

			// Recurse into children.
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->walk_and_insert(
					$el['elements'], $new_widget, $container_id, $position, $inserted
				);
			}
		}

		return $elements;
	}

	/**
	 * Insert a widget element at the specified position within a children array.
	 */
	private function insert_at_position( array &$children, array $widget, int $position ): void {
		if ( $position < 0 || $position >= count( $children ) ) {
			$children[] = $widget; // Append.
		} else {
			array_splice( $children, $position, 0, array( $widget ) );
		}
	}

	/**
	 * Build sensible default settings for a widget type, merged with provided overrides.
	 * Ensures widgets are usable out-of-the-box even with no settings specified.
	 */
	private function build_widget_settings( string $widget_type, array $overrides ): array {
		$schema_defaults = $this->extract_safe_widget_defaults_from_schema( $this->get_widget_schema_entry( $widget_type ) );
		$defaults        = array(
			'heading'     => array( 'header_size' => 'h2', 'align' => 'left' ),
			'image'       => array( 'image' => array( 'url' => '', 'id' => '' ), 'image_size' => 'large' ),
			'spacer'      => array( 'space' => array( 'size' => 50, 'unit' => 'px' ) ),
			'divider'     => array( 'style' => 'solid', 'weight' => array( 'size' => 1, 'unit' => 'px' ) ),
			'icon'        => array( 'selected_icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ) ),
			'tabs'        => array( 'tabs' => array(
				array( '_id' => bin2hex( random_bytes( 3 ) ), 'tab_title' => 'Tab 1', 'tab_content' => '' ),
			) ),
			'accordion'   => array( 'tabs' => array(
				array( '_id' => bin2hex( random_bytes( 3 ) ), 'tab_title' => 'Question 1', 'tab_content' => '' ),
			) ),
		);

		$base = array_replace( $schema_defaults, $defaults[ $widget_type ] ?? array() );

		return array_replace_recursive( $base, $overrides );
	}

	/**
	 * Extract non-placeholder defaults from Elementor's live widget schema.
	 *
	 * Content defaults are intentionally skipped so bare widgets do not ship
	 * with fake placeholder copy like "Your Heading Here".
	 *
	 * @param array $schema Widget schema from get_widget_schema_entry().
	 * @return array
	 */
	private function extract_safe_widget_defaults_from_schema( array $schema ): array {
		$defaults = array();

		foreach ( $schema['fields'] ?? array() as $field_id => $field ) {
			if ( str_starts_with( $field_id, '_' ) ) {
				continue;
			}
			if ( ! array_key_exists( 'default', $field ) || null === $field['default'] ) {
				continue;
			}
			if ( ! empty( $field['is_content'] ) ) {
				continue;
			}

			$defaults[ $field_id ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Let Elementor canonicalize a newly created element when possible.
	 *
	 * This reduces malformed raw structures while still letting save fall back
	 * to the direct meta path if Elementor rejects the final tree.
	 *
	 * @param array $element Raw Elementor element data.
	 * @return array
	 */
	private function normalize_new_element_data( array $element ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $element;
		}

		try {
			$instance = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $element );
			if ( $instance && method_exists( $instance, 'get_data_for_save' ) ) {
				$normalized = $instance->get_data_for_save();
				if ( is_array( $normalized ) ) {
					return $normalized;
				}
			}
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error(
				'Elementor',
				'Failed to normalize new Elementor element',
				array(
					'element_type' => $element['elType'] ?? '',
					'widget_type'  => $element['widgetType'] ?? '',
					'error'        => $e->getMessage(),
				)
			);
		}

		return $element;
	}

	/**
	 * Find the ID of the first container/section/column in the element tree.
	 * For legacy sections, returns the first column ID (widgets go into columns).
	 */
	private function find_first_container_id( array $elements ): string {
		foreach ( $elements as $element ) {
			$el_type = $element['elType'] ?? '';
			if ( in_array( $el_type, array( 'container', 'section', 'column' ), true ) ) {
				// For sections, return the first column (that's where widgets go).
				if ( $el_type === 'section' && ! empty( $element['elements'] ) ) {
					return $element['elements'][0]['id'] ?? $element['id'];
				}
				return $element['id'];
			}
			// Recurse.
			if ( ! empty( $element['elements'] ) ) {
				$found = $this->find_first_container_id( $element['elements'] );
				if ( $found ) {
					return $found;
				}
			}
		}
		return '';
	}

	// ── A3b: Add Container ───────────────────────────────────────────

	/**
	 * Add a new container or section to an Elementor page.
	 *
	 * @param int    $post_id    Post to add to.
	 * @param string $layout     'full_width' or 'boxed' (default: boxed).
	 * @param string $direction  'column' (stacked) or 'row' (side by side). Default: column.
	 * @param int    $position   Page-level position. -1 = end (default), 0 = start.
	 * @param string $parent_id  Optional: insert as child of another container.
	 * @param array  $settings   Additional container settings.
	 * @return array
	 */
	public function add_container(
		int    $post_id,
		string $layout    = 'boxed',
		string $direction = 'column',
		int    $position  = -1,
		string $parent_id = '',
		array  $settings  = array()
	): array {
		if ( ! $post_id ) {
			return array( 'success' => false, 'error' => 'post_id is required.' );
		}

		$data = $this->get_elementor_data( $post_id );
		if ( null === $data ) {
			return array( 'success' => false, 'error' => 'No Elementor data found. Use elementor_create_page first.' );
		}

		// Detect whether this site uses containers or legacy sections.
		$use_containers = self::is_container_active();

		if ( $use_containers ) {
			$new_element = array(
				'id'       => $this->generate_element_id(),
				'elType'   => 'container',
				'isInner'  => ! empty( $parent_id ),
				'settings' => array_merge( array(
					'content_width'  => $layout,
					'flex_direction' => $direction,
				), $settings ),
				'elements' => array(),
			);
		} else {
			// Legacy: section + column.
			$col_id      = $this->generate_element_id();
			$new_element = array(
				'id'       => $this->generate_element_id(),
				'elType'   => 'section',
				'isInner'  => ! empty( $parent_id ),
				'settings' => array_merge( array(
					'layout'        => $layout,
					'content_width' => $layout === 'full_width' ? 'full_width' : 'boxed',
				), $settings ),
				'elements' => array( array(
					'id'       => $col_id,
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(),
				) ),
			);
		}
		$new_element = $this->normalize_new_element_data( $new_element );

		$inserted = false;

		if ( ! empty( $parent_id ) ) {
			// Insert as child of a specific container.
			$data = $this->walk_and_insert( $data, $new_element, $parent_id, $position, $inserted );
			if ( ! $inserted ) {
				return array( 'success' => false, 'error' => "Parent container '{$parent_id}' not found." );
			}
		} else {
			// Insert at page level.
			$this->insert_at_position( $data, $new_element, $position );
			$inserted = true;
		}

		// Save through Elementor's document pipeline when available.
		$this->save_elementor_data( $post_id, $data );

		$container_id = $new_element['id'];
		// For legacy sections, AI should target the column for widget insertion.
		$widget_target_id = ( ! $use_containers && isset( $col_id ) ) ? $col_id : $container_id;

		return array(
			'success'          => true,
			'container_id'     => $container_id,
			'widget_target_id' => $widget_target_id,
			'type'             => $use_containers ? 'container' : 'section',
			'layout'           => $layout,
			'direction'        => $direction,
			'position'         => $position === -1 ? 'end' : $position,
			'message'          => 'Container added to page.',
			'next'             => "Use elementor_add_widget with container_id '{$widget_target_id}' to add content.",
		);
	}

	// ── A4: Find Widgets with Filtering ───────────────────────────────

	/**
	 * Find widgets on an Elementor page with filtering.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $widget_type  Filter by widget type (e.g., 'heading', 'button'). Empty = all.
	 * @param string $search       Search within widget content. Empty = no filter.
	 * @param string $section_id   Filter to widgets within a specific section. Empty = all.
	 * @return array List of matching widgets with IDs and current settings.
	 */
	public function find_widgets(
		int    $post_id,
		string $widget_type = '',
		string $search      = '',
		string $section_id  = ''
	): array {
		$raw    = get_post_meta( $post_id, '_elementor_data', true );
		$data   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$found  = array();

		if ( empty( $data ) ) {
			return array( 'error' => 'No Elementor data found.' );
		}

		$this->collect_widgets( $data, $found, $widget_type, $search, $section_id, null );

		return array(
			'post_id' => $post_id,
			'count'   => count( $found ),
			'widgets' => $found,
			'hint'    => 'Use widget id field to call elementor_edit_widget.',
		);
	}

	private function collect_widgets(
		array   $elements,
		array   &$found,
		string  $type_filter,
		string  $search,
		string  $section_filter,
		?string $current_section
	): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$el_type = $el['elType'] ?? '';
			$el_id   = $el['id']     ?? '';

			// Track current top-level structural element (section or container).
			if ( in_array( $el_type, array( 'section', 'container' ), true ) ) {
				$current_section = $el_id;
			}

			// Skip if section filter set and doesn't match.
			if ( ! empty( $section_filter ) && $current_section !== $section_filter ) {
				if ( ! empty( $el['elements'] ) ) {
					$this->collect_widgets( $el['elements'], $found, $type_filter, $search, $section_filter, $current_section );
				}
				continue;
			}

			if ( $el_type === 'widget' ) {
				$widget_type = $el['widgetType'] ?? '';
				$settings    = $el['settings']   ?? array();

				// Apply type filter.
				if ( ! empty( $type_filter ) && $widget_type !== $type_filter ) {
					continue;
				}

				// Apply content search.
				if ( ! empty( $search ) ) {
					$content = strtolower( wp_strip_all_tags( wp_json_encode( $settings ) ) );
					if ( strpos( $content, strtolower( $search ) ) === false ) {
						continue;
					}
				}

				$schema  = self::get_widget_schema()[ $widget_type ] ?? null;
				$preview = '';

				if ( $schema ) {
					$primary = $schema['primary_field'];
					$val     = $settings[ $primary ] ?? '';
					$preview = mb_substr( wp_strip_all_tags( is_array( $val ) ? wp_json_encode( $val ) : $val ), 0, 80 );
				}

				$found[] = array(
					'id'         => $el_id,
					'type'       => $widget_type,
					'preview'    => $preview,
					'section_id' => $current_section,
					'settings'   => $settings,
				);
			}

			if ( ! empty( $el['elements'] ) ) {
				$this->collect_widgets( $el['elements'], $found, $type_filter, $search, $section_filter, $current_section );
			}
		}
	}

	/**
	 * Legacy alias — old handler calls find_widgets_by_type().
	 */
	public function find_widgets_by_type( int $post_id, string $widget_type, int $limit = 0 ): array {
		$result = $this->find_widgets( $post_id, $widget_type );
		$widgets = $result['widgets'] ?? array();
		if ( $limit > 0 ) {
			return array_slice( $widgets, 0, $limit );
		}
		return $widgets;
	}

	// ── A5: Audit Page ────────────────────────────────────────────────

	/**
	 * Audit an Elementor page for common issues.
	 * Returns categorized issues with severity and fix hints.
	 *
	 * @param int $post_id Post ID.
	 * @return array Audit results with issues by category.
	 */
	public function audit_page( int $post_id ): array {
		$page_data = $this->read_page( $post_id );

		if ( isset( $page_data['error'] ) ) {
			return $page_data;
		}

		// Enrich issues with severity and fix hints.
		$enriched = array();
		foreach ( $page_data['issues'] as $issue ) {
			$enriched[] = array_merge( $issue, $this->get_issue_severity( $issue['type'] ) );
		}

		// Additional checks beyond widget-level issues.
		$stats  = $page_data['stats'];
		$checks = array();

		// Word count.
		if ( $stats['words'] < 300 ) {
			$checks[] = array(
				'type'     => 'thin_page',
				'severity' => 'medium',
				'message'  => "Page has only {$stats['words']} words — thin content may hurt SEO.",
				'hint'     => 'Add more descriptive text sections.',
			);
		}

		// Widget density.
		if ( $stats['widgets'] > 60 ) {
			$checks[] = array(
				'type'     => 'heavy_page',
				'severity' => 'medium',
				'message'  => "Page has {$stats['widgets']} widgets — may affect performance.",
				'hint'     => 'Consider simplifying the page structure.',
			);
		}

		// Heading hierarchy — check for H1 presence.
		$headings = $this->extract_headings( $page_data['structure'] );
		$h1_count = count( array_filter( $headings, fn( $h ) => $h['level'] === 'h1' ) );

		if ( $h1_count === 0 ) {
			$checks[] = array(
				'type'     => 'missing_h1',
				'severity' => 'high',
				'message'  => 'No H1 heading found on this page.',
				'hint'     => 'Add one H1 heading widget — the main page title.',
			);
		} elseif ( $h1_count > 1 ) {
			$checks[] = array(
				'type'     => 'multiple_h1',
				'severity' => 'medium',
				'message'  => "Found {$h1_count} H1 headings — pages should have exactly one.",
				'hint'     => 'Change extra H1s to H2 or H3.',
			);
		}

		$all_issues = array_merge( $enriched, $checks );

		// Custom CSS audit — detect per-element and page-level custom CSS.
		$raw_data      = get_post_meta( $post_id, '_elementor_data', true );
		$elements_data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;

		if ( is_array( $elements_data ) ) {
			$custom_css_elements = array();
			$this->walk_tree_collect( $elements_data, function( $element ) use ( &$custom_css_elements ) {
				if ( ! empty( $element['settings']['custom_css'] ) ) {
					$custom_css_elements[] = array(
						'id'         => $element['id'] ?? '',
						'type'       => $element['widgetType'] ?? $element['elType'] ?? 'element',
						'css_length' => strlen( $element['settings']['custom_css'] ),
						'preview'    => substr( $element['settings']['custom_css'], 0, 100 ),
					);
				}
			} );

			if ( ! empty( $custom_css_elements ) ) {
				$all_issues[] = array(
					'type'     => 'custom_css',
					'severity' => 'info',
					'count'    => count( $custom_css_elements ),
					'message'  => count( $custom_css_elements ) . ' element(s) have custom CSS.',
					'elements' => $custom_css_elements,
					'hint'     => 'Custom CSS overrides may conflict with theme updates. Consider moving to kit global CSS.',
				);
			}
		}

		$page_settings   = get_post_meta( $post_id, '_elementor_page_settings', true );
		$page_custom_css = is_array( $page_settings ) ? ( $page_settings['custom_css'] ?? '' ) : '';
		if ( ! empty( $page_custom_css ) ) {
			$all_issues[] = array(
				'type'     => 'page_custom_css',
				'severity' => 'info',
				'message'  => 'This page has page-level custom CSS (' . strlen( $page_custom_css ) . ' chars).',
				'preview'  => substr( $page_custom_css, 0, 150 ),
			);
		}

		$by_severity = array(
			'high'   => array_values( array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'high' ) ),
			'medium' => array_values( array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'medium' ) ),
			'low'    => array_values( array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'low' ) ),
			'info'   => array_values( array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'info' ) ),
		);

		// Design system usage — check if page uses global color/typography tokens.
		$global_color_usage = is_array( $elements_data ) ? $this->count_global_references( $elements_data, 'colors' ) : 0;
		$global_typo_usage  = is_array( $elements_data ) ? $this->count_global_references( $elements_data, 'typography' ) : 0;

		$design_system = array(
			'global_colors_used'     => $global_color_usage,
			'global_typography_used' => $global_typo_usage,
		);
		if ( $global_color_usage === 0 && $global_typo_usage === 0 ) {
			$design_system['note'] = 'This page uses no global design tokens — colors and fonts are hardcoded. '
				. 'Consider using global colors for site-wide consistency.';
		}

		return array(
			'post_id'        => $post_id,
			'title'          => $page_data['title'],
			'stats'          => $stats,
			'issue_count'    => count( $all_issues ),
			'issues'         => $by_severity,
			'headings'       => $headings,
			'design_system'  => $design_system,
			'score'          => max( 0, 100 - ( count( $by_severity['high'] ) * 20 )
			                              - ( count( $by_severity['medium'] ) * 10 )
			                              - ( count( $by_severity['low'] ) * 5 ) ),
		);
	}

	/**
	 * Count how many widgets reference global colors or typography tokens.
	 */
	private function count_global_references( array $elements, string $type ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) continue;

			$globals = $element['settings']['__globals__'] ?? array();
			if ( is_array( $globals ) ) {
				foreach ( $globals as $ref ) {
					if ( is_string( $ref ) && str_contains( $ref, "globals/{$type}" ) ) {
						$count++;
					}
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				$count += $this->count_global_references( $element['elements'], $type );
			}
		}
		return $count;
	}

	private function get_issue_severity( string $type ): array {
		$severities = array(
			'missing_h1'      => array( 'severity' => 'high',   'hint' => 'Add an H1 heading widget.' ),
			'multiple_h1'     => array( 'severity' => 'medium', 'hint' => 'Keep only one H1 per page.' ),
			'missing_alt'     => array( 'severity' => 'medium', 'hint' => 'Add alt text to the image widget.' ),
			'missing_link'    => array( 'severity' => 'medium', 'hint' => 'Add a URL to the button widget.' ),
			'thin_content'    => array( 'severity' => 'low',    'hint' => 'Add more text to the widget.' ),
			'empty_section'   => array( 'severity' => 'low',    'hint' => 'Remove the empty section or add widgets.' ),
			'empty_column'    => array( 'severity' => 'low',    'hint' => 'Remove the empty column or add widgets.' ),
			'empty_container' => array( 'severity' => 'low',    'hint' => 'Remove the empty container or add widgets.' ),
		);
		return $severities[ $type ] ?? array( 'severity' => 'low', 'hint' => '' );
	}

	private function extract_headings( array $structure ): array {
		$headings = array();
		$this->extract_headings_recursive( $structure, $headings );
		return $headings;
	}

	private function extract_headings_recursive( array $nodes, array &$headings ): void {
		foreach ( $nodes as $node ) {
			if ( ( $node['type'] ?? '' ) === 'heading' ) {
				$headings[] = array(
					'id'    => $node['id'],
					'level' => $node['settings']['header_size'] ?? 'h2',
					'text'  => $node['settings']['title'] ?? '',
				);
			}
			// Recurse into children (new unified structure).
			if ( ! empty( $node['children'] ) ) {
				$this->extract_headings_recursive( $node['children'], $headings );
			}
		}
	}

	// ── A6: Site Pages ────────────────────────────────────────────────

	/**
	 * List all pages/posts using Elementor with metadata.
	 *
	 * @param string $post_type Filter by post type. Empty = all.
	 * @param bool   $with_issues Include issue count per page.
	 * @return array List of Elementor pages with metadata.
	 */
	public function get_site_pages( string $post_type = '', bool $with_issues = false ): array {
		global $wpdb;

		// Find all posts that have _elementor_data meta.
		$query = $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_modified
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value != '' AND pm.meta_value != '[]'
			   AND p.post_status IN (%s, %s, %s)",
			'_elementor_data', 'publish', 'draft', 'private'
		);

		if ( ! empty( $post_type ) ) {
			$query .= $wpdb->prepare( ' AND p.post_type = %s', $post_type );
		}

		$query .= ' ORDER BY p.post_modified DESC LIMIT 200';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query built with $wpdb->prepare() above.
	$rows  = $wpdb->get_results( $query );
		$pages = array();

		foreach ( $rows as $row ) {
			$entry = array(
				'id'            => (int) $row->ID,
				'title'         => $row->post_title,
				'type'          => $row->post_type,
				'status'        => $row->post_status,
				'url'           => get_permalink( $row->ID ),
				'last_modified' => human_time_diff( strtotime( $row->post_modified ) ) . ' ago',
			);

			// Detect layout type from first element.
			$first_element = json_decode(
				get_post_meta( $row->ID, '_elementor_data', true ),
				true
			)[0] ?? null;
			$entry['layout_type'] = ( $first_element && ( $first_element['elType'] ?? '' ) === 'container' )
				? 'flexbox_containers'
				: 'sections';

			if ( $with_issues ) {
				$audit          = $this->audit_page( (int) $row->ID );
				$entry['score'] = $audit['score'] ?? 100;
				$entry['issues'] = $audit['issue_count'] ?? 0;
			}

			$pages[] = $entry;
		}

		return array(
			'total' => count( $pages ),
			'pages' => $pages,
			'hint'  => 'Use page IDs with elementor_read_page or elementor_audit_page for details.',
		);
	}

	// ── A7: Global Styles ─────────────────────────────────────────────

	/**
	 * Read or write Elementor global styles (kit settings).
	 * Covers: system colors, custom colors, system typography, custom typography,
	 * theme style (body/headings/links/buttons), and layout settings.
	 *
	 * @param array|null $updates  Null = read mode. Array = write mode with changes.
	 * @return array
	 */
	public function global_styles( ?array $updates = null ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'Elementor is not active.' );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return array( 'error' => 'No active Elementor Kit found.' );
		}

		$settings = $kit->get_settings();

		// ── READ MODE ────────────────────────────────────────────────
		if ( $updates === null ) {
			$result = array(
				'kit_id'      => $kit->get_id(),
				'colors'      => array( 'system' => array(), 'custom' => array() ),
				'typography'  => array( 'system' => array(), 'custom' => array() ),
				'theme_style' => array(),
				'layout'      => array(),
			);

			// System + custom colors
			foreach ( $settings['system_colors'] ?? array() as $c ) {
				$result['colors']['system'][] = array(
					'id' => $c['_id'], 'title' => $c['title'] ?? '', 'color' => $c['color'] ?? ''
				);
			}
			foreach ( $settings['custom_colors'] ?? array() as $c ) {
				$result['colors']['custom'][] = array(
					'id' => $c['_id'], 'title' => $c['title'] ?? '', 'color' => $c['color'] ?? ''
				);
			}

			// System + custom typography
			foreach ( $settings['system_typography'] ?? array() as $t ) {
				$result['typography']['system'][] = array(
					'id'          => $t['_id'],
					'title'       => $t['title'] ?? '',
					'font_family' => $t['typography_font_family'] ?? '',
					'font_weight' => $t['typography_font_weight'] ?? '',
					'font_size'   => $t['typography_font_size']['size'] ?? '',
					'line_height' => $t['typography_line_height']['size'] ?? '',
				);
			}
			foreach ( $settings['custom_typography'] ?? array() as $t ) {
				$result['typography']['custom'][] = array(
					'id'          => $t['_id'],
					'title'       => $t['title'] ?? '',
					'font_family' => $t['typography_font_family'] ?? '',
					'font_weight' => $t['typography_font_weight'] ?? '',
					'font_size'   => $t['typography_font_size']['size'] ?? '',
				);
			}

			// Theme Style — body, headings, links, buttons
			$theme_keys = array(
				'body_color', 'body_typography_font_family', 'body_typography_font_size',
				'h1_color', 'h1_typography_font_family', 'h1_typography_font_size', 'h1_typography_font_weight',
				'h2_color', 'h2_typography_font_family', 'h2_typography_font_size', 'h2_typography_font_weight',
				'h3_color', 'h3_typography_font_family', 'h3_typography_font_size', 'h3_typography_font_weight',
				'h4_color', 'h4_typography_font_family', 'h4_typography_font_size',
				'h5_color', 'h5_typography_font_family', 'h5_typography_font_size',
				'h6_color', 'h6_typography_font_family', 'h6_typography_font_size',
				'link_color', 'link_hover_color',
				'button_text_color', 'button_background_color',
				'button_hover_text_color', 'button_hover_background_color',
				'button_typography_font_family', 'button_typography_font_weight',
			);

			foreach ( $theme_keys as $key ) {
				$val = $settings[ $key ] ?? null;
				if ( $val === null || $val === '' ) continue;

				// Flatten size arrays: ['size' => 16, 'unit' => 'px'] → '16px'
				if ( is_array( $val ) && isset( $val['size'] ) ) {
					$val = $val['size'] . ( $val['unit'] ?? '' );
				} elseif ( is_array( $val ) && isset( $val['top'] ) ) {
					$val = implode( ' ', array_values( $val ) );
				}

				$result['theme_style'][ $key ] = $val;
			}

			// Layout settings
			$result['layout'] = array(
				'content_width'  => $settings['content_width']['size']      ?? null,
				'container_width'=> $settings['container_width']['size']     ?? null,
				'widgets_space'  => $settings['space_between_widgets']['size']
				                 ?? $settings['widgets_space']['size']       ?? null,
			);

			return $result;
		}

		// ── WRITE MODE ───────────────────────────────────────────────
		$update_payload = array();

		// Color updates — match by _id or title (case-insensitive)
		if ( ! empty( $updates['colors'] ) ) {
			foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
				$items = $settings[ $group ] ?? array();
				foreach ( $items as &$color ) {
					// Match by ID
					if ( isset( $updates['colors'][ $color['_id'] ] ) ) {
						$color['color'] = sanitize_hex_color( $updates['colors'][ $color['_id'] ] );
					}
					// Match by title (e.g., 'primary', 'Primary', 'Brand Blue')
					$label = strtolower( $color['title'] ?? '' );
					foreach ( $updates['colors'] as $k => $v ) {
						if ( strtolower( $k ) === $label ) {
							$color['color'] = sanitize_hex_color( $v );
						}
					}
				}
				$update_payload[ $group ] = $items;
			}
		}

		// Typography updates — match by _id or title
		if ( ! empty( $updates['typography'] ) ) {
			foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
				$items = $settings[ $group ] ?? array();
				foreach ( $items as &$typo ) {
					$match = $updates['typography'][ $typo['_id'] ]
					      ?? $updates['typography'][ strtolower( $typo['title'] ?? '' ) ]
					      ?? null;

					if ( ! $match ) continue;

					if ( isset( $match['font_family'] ) ) {
						$typo['typography_typography']  = 'custom';
						$typo['typography_font_family'] = sanitize_text_field( $match['font_family'] );
					}
					if ( isset( $match['font_weight'] ) ) {
						$typo['typography_font_weight'] = sanitize_text_field( $match['font_weight'] );
					}
					if ( isset( $match['font_size'] ) ) {
						$typo['typography_font_size'] = array(
							'unit' => 'px', 'size' => (int) $match['font_size']
						);
					}
					if ( isset( $match['line_height'] ) ) {
						$typo['typography_line_height'] = array(
							'unit' => 'em', 'size' => (float) $match['line_height']
						);
					}
				}
				$update_payload[ $group ] = $items;
			}
		}

		// Theme Style updates — direct key-value (body, headings, links, buttons)
		if ( ! empty( $updates['theme_style'] ) ) {
			foreach ( $updates['theme_style'] as $key => $value ) {
				$update_payload[ sanitize_key( $key ) ] = $value;
			}
		}

		// Layout updates
		if ( ! empty( $updates['layout'] ) ) {
			if ( isset( $updates['layout']['content_width'] ) ) {
				$update_payload['content_width'] = array(
					'unit' => 'px', 'size' => (int) $updates['layout']['content_width']
				);
			}
			if ( isset( $updates['layout']['container_width'] ) ) {
				$update_payload['container_width'] = array(
					'unit' => 'px', 'size' => (int) $updates['layout']['container_width']
				);
			}
		}

		if ( empty( $update_payload ) ) {
			return array( 'error' => 'No updates provided. Specify colors, typography, theme_style, or layout.' );
		}

		// Save via Kit Document API — proper hooks and merging
		$kit->update_settings( $update_payload );

		// CSS regen: clear cache ONCE (files rebuild lazily on next visit)
		\Elementor\Plugin::$instance->files_manager->clear_cache();

		return array(
			'success' => true,
			'updated' => array_keys( $update_payload ),
			'message' => 'Global styles updated. CSS cache cleared — pages will rebuild on next visit.',
		);
	}

	// ── Templates & Utilities ─────────────────────────────────────────

	/**
	 * List all Elementor templates (excluding the kit post).
	 */
	public function list_templates(): array {
		$kit_id = (int) get_option( 'elementor_active_kit' );

		$templates = get_posts( array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'post__not_in'   => $kit_id ? array( $kit_id ) : array(),
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );

		$result = array();
		foreach ( $templates as $t ) {
			$type = get_post_meta( $t->ID, '_elementor_template_type', true );

			$result[] = array(
				'id'          => $t->ID,
				'title'       => $t->post_title,
				'type'        => $type,
				'type_label'  => $this->get_template_type_label( $type ),
				'is_pro'      => in_array( $type, array( 'header', 'footer', 'single', 'archive', 'popup', 'error-404', 'product', 'product-archive' ), true ),
				'date'        => $t->post_date,
				'has_content' => ! empty( get_post_meta( $t->ID, '_elementor_data', true ) ),
			);
		}

		return array(
			'success'   => true,
			'count'     => count( $result ),
			'templates' => $result,
		);
	}

	private function get_template_type_label( string $type ): string {
		$labels = array(
			'page'            => 'Page Template',
			'section'         => 'Section Template',
			'container'       => 'Container Template',
			'loop-item'       => 'Loop Item',
			'header'          => 'Header (Pro)',
			'footer'          => 'Footer (Pro)',
			'single'          => 'Single Post (Pro)',
			'single-post'     => 'Single Post (Pro)',
			'single-page'     => 'Single Page (Pro)',
			'archive'         => 'Archive (Pro)',
			'popup'           => 'Popup (Pro)',
			'error-404'       => '404 Page (Pro)',
			'product'         => 'Product (Pro)',
			'product-archive' => 'Product Archive (Pro)',
		);
		return $labels[ $type ] ?? ucfirst( $type );
	}

	/**
	 * Create a new page from an Elementor template.
	 */
	public function create_from_template( int $template_id, string $title, string $post_type = 'page' ): array {
		$template = get_post( $template_id );
		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return array( 'success' => false, 'message' => 'Template not found.' );
		}

		$new_id = wp_insert_post( array(
			'post_title'   => sanitize_text_field( $title ),
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_content' => '',
		) );

		if ( is_wp_error( $new_id ) ) {
			return array( 'success' => false, 'message' => $new_id->get_error_message() );
		}

		// Copy Elementor data with regenerated element IDs to prevent duplicates.
		$elementor_data  = get_post_meta( $template_id, '_elementor_data', true );
		$page_settings   = get_post_meta( $template_id, '_elementor_page_settings', true );

		$decoded = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
		if ( is_array( $decoded ) ) {
			$decoded        = $this->regenerate_element_ids( $decoded );
			$elementor_data = wp_json_encode( $decoded );
		}

		update_post_meta( $new_id, '_elementor_data', wp_slash( $elementor_data ) );
		update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $new_id, '_elementor_template_type', 'page' === $post_type ? 'wp-page' : 'wp-post' );
		update_post_meta( $new_id, '_elementor_version', ELEMENTOR_VERSION );
		if ( $page_settings ) {
			update_post_meta( $new_id, '_elementor_page_settings', $page_settings );
		}

		$source_tpl = get_post_meta( $template_id, '_wp_page_template', true );
		update_post_meta( $new_id, '_wp_page_template', $source_tpl ?: 'elementor_header_footer' );

		// Regenerate CSS for the new page.
		$this->regenerate_post_css( $new_id );

		return array(
			'success' => true,
			'post_id' => $new_id,
			'message' => "Created \"{$title}\" from template \"{$template->post_title}\" (ID: {$new_id}). Status: draft.",
		);
	}

	/**
	 * Find and replace text across all Elementor pages on the site.
	 * Walks the element tree instead of doing raw JSON string replacement.
	 * Searches element data AND page settings. Includes drafts and templates.
	 */
	public function find_replace( string $find, string $replace, ?int $post_id = null ): array {
		if ( empty( $find ) ) {
			return array( 'error' => 'find string cannot be empty.' );
		}

		$kit_id = (int) get_option( 'elementor_active_kit' );

		$args = array(
			'post_type'      => array( 'page', 'post', 'elementor_library' ), // FIX: includes headers, footers, templates
			'post_status'    => array( 'publish', 'draft', 'private' ),        // FIX: includes drafts
			'posts_per_page' => -1,
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		if ( $post_id ) {
			$args['post__in'] = array( absint( $post_id ) );
		}

		// Exclude the kit post — it has no elements
		if ( $kit_id ) {
			$args['post__not_in'] = array( $kit_id );
		}

		$pages       = get_posts( $args );
		$updated     = 0;
		$updated_ids = array();
		$skipped     = 0;
		$locked_ids  = array();

		foreach ( $pages as $page ) {
			// v3.8.0: Skip pages locked by another user to avoid overwriting active edits.
			if ( wp_check_post_lock( $page->ID ) ) {
				$locked_ids[] = $page->ID;
				continue;
			}

			$changed = false;

			// ── Pass 1: _elementor_data (element tree) ────────────
			$raw = get_post_meta( $page->ID, '_elementor_data', true );

			if ( ! empty( $raw ) ) {
				$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

				if ( is_array( $data ) && $this->json_contains( $raw, $find ) ) {
					$new_data = $this->walk_and_replace( $data, $find, $replace );

					if ( $new_data !== $data ) {
						// FIX: Use wp_slash to prevent double-encoding on save
						update_post_meta( $page->ID, '_elementor_data', wp_slash( wp_json_encode( $new_data ) ) );
						$changed = true;
					}
				}
			}

			// ── Pass 2: _elementor_page_settings (custom CSS, page config) ──
			$page_settings = get_post_meta( $page->ID, '_elementor_page_settings', true );

			if ( is_array( $page_settings ) ) {
				$new_settings = $this->walk_settings_replace( $page_settings, $find, $replace );
				if ( $new_settings !== $page_settings ) {
					update_post_meta( $page->ID, '_elementor_page_settings', $new_settings );
					$changed = true;
				}
			}

			if ( $changed ) {
				$updated++;
				$updated_ids[] = $page->ID;
				$this->regenerate_post_css( $page->ID );
			} else {
				$skipped++;
			}
		}

		return array(
			'success'      => true,
			'find'         => $find,
			'replace'      => $replace,
			'pages_scanned'=> count( $pages ),
			'pages_updated'=> $updated,
			'pages_skipped'=> $skipped,
			'pages_locked' => count( $locked_ids ),
			'locked_ids'   => $locked_ids,
			'post_ids'     => $updated_ids,
			'message'      => "Replaced \"{$find}\" with \"{$replace}\" in {$updated} page(s). CSS regenerated.",
		);
	}

	/**
	 * Quick check if a string is worth walking for replacements.
	 * Avoids decoding large JSON blobs when the find term isn't present.
	 */
	private function json_contains( string $json, string $find ): bool {
		return stripos( $json, $find ) !== false;
	}

	/**
	 * Recursively walk element tree and replace text in string values.
	 * Does NOT replace in JSON keys, __globals__, or __dynamic__ meta.
	 */
	private function walk_and_replace( array $elements, string $find, string $replace ): array {
		foreach ( $elements as &$element ) {
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = $this->walk_settings_replace(
					$element['settings'], $find, $replace
				);
			}
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->walk_and_replace(
					$element['elements'], $find, $replace
				);
			}
		}
		return $elements;
	}

	/**
	 * Recursively replace in settings values.
	 * Only replaces in STRING values, not in array keys.
	 * Skips __globals__ and __dynamic__ meta-keys entirely.
	 */
	private function walk_settings_replace( array $settings, string $find, string $replace ): array {
		foreach ( $settings as $key => &$value ) {
			// Skip meta-keys — never replace in dynamic/global references
			if ( $key === '__globals__' || $key === '__dynamic__' ) continue;

			if ( is_string( $value ) ) {
				$value = str_ireplace( $find, $replace, $value );
			} elseif ( is_array( $value ) ) {
				$value = $this->walk_settings_replace( $value, $find, $replace );
			}
			// Non-string, non-array values (int, bool, null) — skip
		}
		return $settings;
	}

	/**
	 * Count Elementor pages and get summary stats.
	 */
	public function get_stats(): array {
		$elementor_pages = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'fields'         => 'ids',
		) );

		$templates = get_posts( array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$template_types = array();
		foreach ( $templates as $tid ) {
			$type = get_post_meta( $tid, '_elementor_template_type', true ) ?: 'unknown';
			$template_types[ $type ] = ( $template_types[ $type ] ?? 0 ) + 1;
		}

		return array(
			'pages_count'    => count( $elementor_pages ),
			'templates_count' => count( $templates ),
			'template_types' => $template_types,
			'version'        => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'has_pro'        => defined( 'ELEMENTOR_PRO_VERSION' ),
			'pro_version'    => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		);
	}

	// ── A8: Create Page ──────────────────────────────────────────────

	/**
	 * Create a new page initialized for Elementor editing.
	 * Sets all required Elementor meta so the page opens in Elementor editor.
	 *
	 * @param string $title     Page/post title.
	 * @param string $template  'canvas' (blank), 'full-width', or '' (default theme template).
	 * @param string $status    'draft' or 'publish'.
	 * @param int    $parent    Parent page ID (0 = top level, ignored for posts).
	 * @param array  $widgets   Initial widgets to add.
	 * @param string $post_type 'page' or 'post'.
	 * @return array
	 */
	public function create_page(
		string $title,
		string $template = '',
		string $status   = 'draft',
		int    $parent   = 0,
		array  $widgets  = array(),
		string $post_type = 'page'
	): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array( 'error' => 'Elementor is not active on this site.' );
		}

		$post_type = in_array( $post_type, array( 'page', 'post' ), true ) ? $post_type : 'page';

		$template_type = 'page' === $post_type ? 'wp-page' : 'wp-post';
		$insert_args   = array(
			'post_title'  => sanitize_text_field( $title ),
			'post_status' => in_array( $status, array( 'draft', 'publish', 'private' ), true ) ? $status : 'draft',
			'post_type'   => $post_type,
		);
		$meta_input    = array(
			'_elementor_template_type' => $template_type,
			'_elementor_version'       => ELEMENTOR_VERSION,
			'_elementor_edit_mode'     => 'builder',
			'_wp_page_template'        => $this->resolve_template( $template ),
		);

		if ( $parent > 0 && 'page' === $post_type ) {
			$insert_args['post_parent'] = $parent;
		}

		$page_id = $this->create_elementor_document_post( $template_type, $insert_args, $meta_input );

		if ( is_wp_error( $page_id ) ) {
			return array( 'error' => $page_id->get_error_message() );
		}

		$current_data = $this->get_elementor_data( $page_id );
		if ( empty( $this->find_first_container_id( is_array( $current_data ) ? $current_data : array() ) ) ) {
			$this->save_elementor_data( $page_id, $this->get_initial_elementor_structure() );
		}

		// Add initial widgets if provided.
		$created_widgets = array();
		$widget_errors   = array();
		if ( ! empty( $widgets ) ) {
			foreach ( $widgets as $w ) {
				$widget_result = $this->add_widget(
					$page_id,
					$w['type']     ?? $w['widget_type'] ?? 'text-editor',
					$w['settings'] ?? array(),
					$w['container_id'] ?? '',
					(int) ( $w['position'] ?? -1 )
				);
				if ( ! empty( $widget_result['success'] ) ) {
					$created_widgets[] = $widget_result['widget_id'] ?? null;
				} else {
					$widget_errors[] = array(
						'widget_type' => $w['type'] ?? $w['widget_type'] ?? 'text-editor',
						'error'       => $widget_result['error'] ?? $widget_result['message'] ?? 'Unknown widget creation error.',
					);
				}
			}
		}

		$widget_count  = count( $created_widgets );
		$requested_count = count( $widgets );
		$type_label    = 'page' === $post_type ? 'Page' : 'Post';
		$actual_status = get_post_status( $page_id );
		$status_label  = 'publish' === $actual_status ? 'published' : $actual_status;
		$message = $widget_count > 0
			? sprintf( '%s "%s" created as %s with %d widget(s) (post_id: %d).', $type_label, $title, $status_label, $widget_count, $page_id )
			: sprintf( '%s "%s" created as %s (post_id: %d). Use elementor_add_widget with post_id %d to add content widgets.', $type_label, $title, $status_label, $page_id, $page_id );
		if ( ! empty( $widget_errors ) ) {
			$message .= ' ' . sprintf( '%d of %d requested widget(s) failed to insert.', count( $widget_errors ), $requested_count );
		}

		return array(
			'success'           => true,
			'post_id'           => $page_id,
			'title'             => $title,
			'status'            => $status,
			'url'               => get_permalink( $page_id ),
			'edit_url'          => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
			'template'          => $template,
			'widgets_requested' => $requested_count,
			'widgets_inserted'  => $widget_count,
			'widget_errors'     => $widget_errors,
			'message'           => $message,
		);
	}

	/**
	 * Create an Elementor-ready document, preferring Elementor's own document manager.
	 *
	 * @param string $document_type Elementor document type (for example wp-page or wp-post).
	 * @param array  $post_data     wp_insert_post()-style post args.
	 * @param array  $meta_input    Elementor meta to seed at creation time.
	 * @return int|\WP_Error
	 */
	private function create_elementor_document_post( string $document_type, array $post_data, array $meta_input ) {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$documents = \Elementor\Plugin::$instance->documents ?? null;
				if ( $documents && method_exists( $documents, 'create' ) ) {
					$document = $documents->create( $document_type, $post_data, $meta_input );
					if ( is_wp_error( $document ) ) {
						return $document;
					}
					if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
						return (int) $document->get_main_id();
					}
				}
			} catch ( \Throwable $e ) {
				PressArk_Error_Tracker::error(
					'Elementor',
					'Documents_Manager::create() failed; falling back to wp_insert_post',
					array(
						'document_type' => $document_type,
						'error'         => $e->getMessage(),
					)
				);
			}
		}

		$post_data['meta_input'] = $meta_input;

		return wp_insert_post( $post_data );
	}

	/**
	 * Get the initial empty Elementor structure for a new page.
	 * Uses containers on sites with Flexbox Containers active, legacy section+column otherwise.
	 */
	private function get_initial_elementor_structure(): array {
		if ( self::is_container_active() ) {
			return array( array(
				'id'       => $this->generate_element_id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width'  => 'boxed',
					'flex_direction' => 'column',
				),
				'elements' => array(),
				'isInner'  => false,
			) );
		}

		// Legacy section + column.
		return array( array(
			'id'       => $this->generate_element_id(),
			'elType'   => 'section',
			'settings' => new \stdClass(),
			'elements' => array( array(
				'id'       => $this->generate_element_id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 100 ),
				'elements' => array(),
			) ),
			'isInner'  => false,
		) );
	}

	private function generate_element_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/**
	 * Recursively regenerate all element IDs in an Elementor data tree.
	 * Prevents duplicate IDs when creating pages from templates.
	 */
	private function regenerate_element_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			$element['id'] = bin2hex( random_bytes( 4 ) );

			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->regenerate_element_ids( $element['elements'] );
			}
		}
		return $elements;
	}

	private function resolve_template( string $template ): string {
		$map = array(
			'canvas'     => 'elementor_canvas',
			'full-width' => 'elementor_header_footer',
			'default'    => 'default',
			''           => 'default',
		);
		return $map[ $template ] ?? 'default';
	}

	// ── A9: Widget ID Validation ─────────────────────────────────────

	/**
	 * Check if a widget ID exists anywhere in the Elementor data tree.
	 *
	 * @param array  $data Elementor data array.
	 * @param string $id   Widget ID to search for.
	 * @return bool
	 */
	public function widget_id_exists( array $data, string $id ): bool {
		foreach ( $data as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( ( $el['id'] ?? '' ) === $id ) {
				return true;
			}
			if ( ! empty( $el['elements'] ) && $this->widget_id_exists( $el['elements'], $id ) ) {
				return true;
			}
		}
		return false;
	}

	// ── A10: Widget Schema Auto-Discovery ────────────────────────────

	/**
	 * Get auto-discovered schema for all registered Elementor widgets.
	 * Cached in transient keyed by Elementor version + registered widget names hash.
	 *
	 * @return array widget_type => schema data.
	 */
	public static function get_all_widget_schemas(): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		$all_widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();

		// Cache key includes widget names hash — invalidates when plugins add/remove widgets.
		$widget_names_hash = md5( implode( ',', array_keys( $all_widgets ) ) );
		$cache_key         = 'pressark_widget_schemas_' . md5( ELEMENTOR_VERSION . '_' . $widget_names_hash );

		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}
		$schemas     = array();

		foreach ( $all_widgets as $widget_name => $widget_instance ) {
			try {
				$schemas[ $widget_name ] = self::extract_widget_schema( $widget_instance );
			} catch ( \Throwable $e ) {
				// Some widgets may fail to register controls — skip gracefully.
				$schemas[ $widget_name ] = array(
					'name'    => $widget_name,
					'title'   => $widget_instance->get_title(),
					'fields'  => array(),
					'error'   => $e->getMessage(),
				);
			}
		}

		// Cache for 24 hours — invalidated when Elementor updates.
		set_transient( $cache_key, $schemas, DAY_IN_SECONDS );
		return $schemas;
	}

	/**
	 * Extract schema from a single Widget_Base instance.
	 */
	private static function extract_widget_schema( \Elementor\Widget_Base $widget ): array {
		// get_controls() triggers register_controls() on first call.
		$controls = $widget->get_controls();
		$fields   = array();

		foreach ( $controls as $control_id => $control ) {
			// Skip UI-only controls (sections, tabs, headings, dividers).
			$ui_types = array( 'section', 'tab', 'tabs', 'heading', 'divider', 'raw_html', 'notice' );
			if ( in_array( $control['type'] ?? '', $ui_types, true ) ) {
				continue;
			}

			// Skip internal settings (prefixed with _).
			if ( str_starts_with( $control_id, '_' ) ) {
				continue;
			}

			$fields[ $control_id ] = array(
				'type'        => $control['type'] ?? 'text',
				'label'       => $control['label'] ?? '',
				'default'     => $control['default'] ?? null,
				'tab'         => $control['tab'] ?? 'content',
				'section'     => $control['section'] ?? '',
				'description' => $control['description'] ?? '',
				// For select/choose/select2 controls — valid options.
				'options'     => isset( $control['options'] ) ? array_keys( $control['options'] ) : null,
				'is_content'  => ( $control['tab'] ?? '' ) === 'content',
				'is_style'    => ( $control['tab'] ?? '' ) === 'style',
			);
		}

		return array(
			'name'           => $widget->get_name(),
			'title'          => $widget->get_title(),
			'icon'           => $widget->get_icon(),
			'categories'     => $widget->get_categories(),
			'keywords'       => $widget->get_keywords(),
			'fields'         => $fields,
			// Content fields — most useful for AI editing.
			'content_fields' => array_keys( array_filter( $fields, fn( $f ) => $f['is_content'] ) ),
			// Style fields — colors, typography, spacing.
			'style_fields'   => array_keys( array_filter( $fields, fn( $f ) => $f['is_style'] ) ),
		);
	}

	/**
	 * Get schema for a single widget type.
	 * Uses cached all-schemas — efficient for repeated lookups.
	 */
	public function get_widget_schema_entry( string $widget_type ): array {
		static $schemas = null;
		if ( $schemas === null ) {
			$schemas = self::get_all_widget_schemas();
		}
		return $schemas[ $widget_type ] ?? array();
	}

	/**
	 * Get the legacy manual widget schema.
	 * Kept for backward compatibility with existing alias resolution.
	 */
	private function get_legacy_widget_schema( string $widget_type ): array {
		$schema = self::get_widget_schema();
		return $schema[ $widget_type ] ?? array();
	}

	// === HELPERS ===

	/**
	 * Recursively walk the Elementor element tree, applying a callback to each element.
	 */
	private function walk_tree( array $elements, callable $callback ): array {
		foreach ( $elements as &$element ) {
			$element = $callback( $element );
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->walk_tree( $element['elements'], $callback );
			}
		}
		return $elements;
	}

	// ── CSS Regeneration ─────────────────────────────────────────────

	/**
	 * Regenerate Elementor CSS for a single post.
	 * Uses Post_CSS::update() for targeted regeneration instead of
	 * clear_cache() which nukes ALL generated CSS files site-wide.
	 *
	 * @param int $post_id Post ID to regenerate CSS for.
	 */
	private function regenerate_post_css( int $post_id ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		try {
			// Elementor 3.x+ path.
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css_file = \Elementor\Core\Files\CSS\Post::create( $post_id );
				$css_file->update();
				return;
			}

			// Elementor 2.x legacy path.
			if ( class_exists( '\Elementor\Post_CSS_File' ) ) {
				$css_file = new \Elementor\Post_CSS_File( $post_id );
				$css_file->update();
				return;
			}

			// Absolute fallback — global clear if neither class exists.
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		} catch ( \Throwable $e ) {
			// Non-fatal — log but don't break the save operation.
			PressArk_Error_Tracker::warning( 'Elementor', 'CSS regeneration failed', array( 'post_id' => $post_id, 'error' => $e->getMessage() ) );
		}
	}

	// ── Public Helpers (for action-engine access) ────────────────────

	/**
	 * Public wrapper for regenerate_element_ids().
	 */
	public function regenerate_element_ids_public( array $elements ): array {
		return $this->regenerate_element_ids( $elements );
	}

	/**
	 * Public wrapper for regenerate_post_css().
	 */
	public function regenerate_post_css_public( int $post_id ): void {
		$this->regenerate_post_css( $post_id );
	}

	// ── Breakpoints ─────────────────────────────────────────────────

	/**
	 * Get active Elementor breakpoints on this site.
	 * Returns device names and their pixel thresholds.
	 */
	public function get_active_breakpoints(): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		try {
			$breakpoints = \Elementor\Plugin::$instance
				->breakpoints_manager
				->get_active_breakpoints();

			$result = array( 'desktop' => array( 'label' => 'Desktop', 'value' => 'no max' ) );

			foreach ( $breakpoints as $id => $bp ) {
				$result[ $id ] = array(
					'label'     => $bp->get_label(),
					'max_width' => $bp->get_value() . 'px',
					'direction' => $bp->get_direction(),
				);
			}

			return $result;
		} catch ( \Throwable $e ) {
			// Fallback for older Elementor.
			return array(
				'desktop' => array( 'label' => 'Desktop', 'value' => 'no max' ),
				'tablet'  => array( 'label' => 'Tablet',  'value' => '1024px' ),
				'mobile'  => array( 'label' => 'Mobile',  'value' => '767px' ),
			);
		}
	}

	// ── Dynamic Tag Management ──────────────────────────────────────

	/**
	 * List all registered Elementor dynamic tags.
	 * Returns tag name, label, categories, and which control types it supports.
	 */
	public function list_dynamic_tags(): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'Elementor is not active.' );
		}

		try {
			$manager = \Elementor\Plugin::$instance->dynamic_tags;
			$tags    = $manager->get_tags();
			$result  = array();

			foreach ( $tags as $tag_name => $tag_class ) {
				try {
					$instance = $manager->create_tag(
						uniqid(), $tag_name, array()
					);
					if ( ! $instance ) {
						continue;
					}

					$result[] = array(
						'name'       => $tag_name,
						'label'      => $instance->get_title(),
						'group'      => $instance->get_group(),
						'categories' => $instance->get_categories(),
						'editable'   => method_exists( $instance, 'get_settings_fields' )
							&& ! empty( $instance->get_settings_fields() ),
					);
				} catch ( \Throwable $e ) {
					$result[] = array(
						'name'  => $tag_name,
						'label' => $tag_name,
						'error' => $e->getMessage(),
					);
				}
			}

			// Group by category for readability.
			$grouped = array();
			foreach ( $result as $tag ) {
				foreach ( $tag['categories'] ?? array() as $cat ) {
					$grouped[ $cat ][] = array(
						'name'  => $tag['name'],
						'label' => $tag['label'],
					);
				}
			}

			return array(
				'success'     => true,
				'total'       => count( $result ),
				'tags'        => $result,
				'by_category' => $grouped,
				'hint'        => 'Use elementor_set_dynamic_tag with tag name to connect a field to dynamic data.',
			);
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Dynamic tags manager not available: ' . $e->getMessage() );
		}
	}

	/**
	 * Connect a widget field to an Elementor dynamic tag.
	 * Sets __dynamic__ binding without destroying other settings.
	 */
	public function set_dynamic_tag(
		int    $post_id,
		string $widget_id,
		string $field,
		string $tag_name,
		array  $tag_settings = array()
	): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'Elementor is not active.' );
		}

		// Validate tag exists.
		try {
			$manager = \Elementor\Plugin::$instance->dynamic_tags;
			$tags    = $manager->get_tags();
			if ( ! isset( $tags[ $tag_name ] ) ) {
				return array(
					'error' => "Dynamic tag '{$tag_name}' not found.",
					'hint'  => 'Use elementor_list_dynamic_tags to see available tags.',
				);
			}
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Cannot access dynamic tags manager.' );
		}

		// Build the tag shortcode format Elementor expects.
		$tag_id        = uniqid();
		$settings_json = ! empty( $tag_settings )
			? base64_encode( wp_json_encode( $tag_settings ) )
			: base64_encode( '{}' );

		$tag_text = sprintf(
			'[elementor-tag id="%s" name="%s" settings="%s"]',
			esc_attr( $tag_id ),
			esc_attr( $tag_name ),
			esc_attr( $settings_json )
		);

		// Walk the element tree and apply.
		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw_data ) ) {
			return array( 'error' => 'No Elementor data found for this post.' );
		}

		$data  = is_string( $raw_data ) ? json_decode( $raw_data, true ) : $raw_data;
		$found = false;
		$data  = $this->walk_and_set_dynamic_tag( $data, $widget_id, $field, $tag_text, $found );

		if ( ! $found ) {
			return array( 'error' => "Widget '{$widget_id}' not found." );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		$this->regenerate_post_css( $post_id );

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'widget_id' => $widget_id,
			'field'     => $field,
			'tag'       => $tag_name,
			'message'   => "Field '{$field}' now pulls from dynamic tag '{$tag_name}'.",
			'note'      => 'The static value for this field is now used as a fallback only.',
		);
	}

	private function walk_and_set_dynamic_tag(
		array  $elements,
		string $target_id,
		string $field,
		string $tag_text,
		bool   &$found
	): array {
		foreach ( $elements as &$element ) {
			if ( ( $element['id'] ?? '' ) === $target_id ) {
				$found = true;
				$field = self::resolve_field_key( $element['widgetType'] ?? '', $field );
				if ( ! isset( $element['settings']['__dynamic__'] ) ) {
					$element['settings']['__dynamic__'] = array();
				}
				$element['settings']['__dynamic__'][ $field ] = $tag_text;
				// Clear any __globals__ reference on this field.
				if ( isset( $element['settings']['__globals__'][ $field ] ) ) {
					unset( $element['settings']['__globals__'][ $field ] );
				}
				return $elements;
			}
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->walk_and_set_dynamic_tag(
					$element['elements'], $target_id, $field, $tag_text, $found
				);
				if ( $found ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	// ── Display Condition Helpers ────────────────────────────────────

	/**
	 * Parse raw element display conditions into human-readable format.
	 */
	private function parse_display_conditions( $raw_conditions ): array {
		if ( ! is_array( $raw_conditions ) ) {
			return array();
		}

		$parsed = array();
		foreach ( $raw_conditions as $condition ) {
			$type     = $condition['name']     ?? '';
			$operator = $condition['operator'] ?? 'equal';
			$value    = $condition['value']    ?? '';

			$parsed[] = array(
				'type'     => $type,
				'operator' => $operator,
				'value'    => $value,
				'label'    => $this->format_condition_label( $type, $operator, $value ),
			);
		}
		return $parsed;
	}

	private function format_condition_label( string $type, string $operator, $value ): string {
		$type_labels = array(
			'login'         => 'User login status',
			'role'          => 'User role',
			'device'        => 'Device type',
			'date_time'     => 'Date/time',
			'url'           => 'URL parameter',
			'custom_field'  => 'Custom field',
			'dynamic_field' => 'Dynamic field',
		);

		$op_labels = array(
			'equal'     => 'is',
			'not_equal' => 'is not',
			'greater'   => 'is greater than',
			'less'      => 'is less than',
			'contains'  => 'contains',
		);

		$type_label = $type_labels[ $type ] ?? $type;
		$op_label   = $op_labels[ $operator ] ?? $operator;
		$val_label  = is_array( $value ) ? implode( ', ', $value ) : (string) $value;

		return "{$type_label} {$op_label} '{$val_label}'";
	}

	// ── Tree Collection Helper ──────────────────────────────────────

	/**
	 * Walk the Elementor element tree and collect results via callback.
	 */
	private function walk_tree_collect( array $elements, callable $collector ): void {
		foreach ( $elements as $element ) {
			$collector( $element );
			if ( ! empty( $element['elements'] ) ) {
				$this->walk_tree_collect( $element['elements'], $collector );
			}
		}
	}
}
