<?php
/**
 * PressArk Preview System
 *
 * Creates a sandboxed temp layer for proposed changes.
 * Original site is UNTOUCHED until user clicks Keep.
 *
 * How it works:
 * - Post changes → WordPress draft copies
 * - Option changes → stored in transient, intercepted via pre_option_{name} filter
 * - Meta changes → stored in transient, intercepted via get_post_metadata filter
 * - A signed URL token activates the filters for preview requests only
 * - Keep → promotes temp layer to live
 * - Discard → deletes drafts + transients, clean slate
 *
 * v3.4.0: Staging is driven by the Operation Registry's preview_strategy field.
 * Instead of a tool-name switch, each tool declares its staging type and the
 * preview system routes to the appropriate staging helper. Adding a new
 * previewable tool no longer requires editing this file — just set the
 * preview_strategy in the registry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Preview {

	const TRANSIENT_PREFIX = 'pressark_preview_';
	// v5.0.1: Raised from 1 hour to 4 hours. Users who step away for lunch
	// or get distracted should not silently lose their staged changes.
	const SESSION_EXPIRY   = 4 * HOUR_IN_SECONDS;

	/**
	 * v3.7.0: Minimum Elementor version for safe preview drafting.
	 * Elementor 3.16+ changed the _elementor_data save pipeline;
	 * earlier versions may silently wipe data on draft promotion.
	 */
	const MIN_ELEMENTOR_VERSION = '3.16.0';

	/**
	 * v3.7.0: Transient lock prefix for keep/discard mutual exclusion.
	 * Prevents the race where concurrent keep + discard on the same
	 * session corrupts state (keep promotes half, discard deletes half).
	 */
	const LOCK_PREFIX = 'pressark_pv_lock_';

	/**
	 * Create a preview session from a set of proposed write tool calls.
	 *
	 * @param array $tool_calls Array of AI tool calls (previewable writes only).
	 * @param array $primary_args Args from the triggering tool call.
	 * @return array { session_id, signed_url, diff }
	 */
	public function create_session( array $tool_calls, array $primary_args ): array {
		$session_id = wp_generate_uuid4();
		$user_id    = get_current_user_id();

		$layer = array(
			'user_id'    => $user_id,
			'posts'      => array(),   // post_id => draft_post_id
			'post_blueprints' => array(), // draft_post_id => intended status/schedule
			'options'    => array(),   // option_key => proposed_value
			'meta'       => array(),   // "postID::metaKey" => proposed_value
			'expires'    => time() + self::SESSION_EXPIRY,
			'tool_calls' => $tool_calls,
		);

		$diff = array();

		// v3.4.0: Strategy-driven staging — routes on preview_strategy from
		// the Operation Registry instead of switching on tool names.
		foreach ( $tool_calls as $call ) {
			$name     = $call['name'];
			$args     = $call['arguments'] ?? array();
			$strategy = PressArk_Operation_Registry::get_preview_strategy( $name );

			switch ( $strategy ) {
				case 'post_edit':
					list( $draft_id, $entry ) = $this->stage_post_edit( $args );
					if ( $draft_id ) {
						$layer['posts'][ $args['post_id'] ] = $draft_id;
						$diff[] = $entry;
					}
					break;

				case 'new_post':
					list( $draft_id, $entry, $blueprint ) = $this->stage_new_post( $args );
					if ( $draft_id ) {
						$layer['posts'][ 'new_' . $draft_id ] = $draft_id;
						$layer['post_blueprints'][ $draft_id ] = $blueprint;
						$diff[] = $entry;
					}
					break;

				case 'meta_update':
					$meta_entries = $this->stage_meta_update( $args );
					foreach ( $meta_entries as $key => $entry ) {
						$layer['meta'][ $key ] = $entry['value'];
						$diff[] = $entry['diff'];
					}
					break;

				case 'option_update':
					$option_entries = $this->stage_option_update( $args );
					foreach ( $option_entries as $key => $entry ) {
						$layer['options'][ $key ] = $entry['value'];
						$diff[] = $entry['diff'];
					}
					break;

				case 'block_edit':
					list( $draft_id, $entry ) = $this->stage_post_edit( array(
						'post_id' => $args['post_id'] ?? 0,
						'fields'  => array( 'post_content' => '(block edit — see preview)' ),
					) );
					if ( $draft_id ) {
						$blocks_handler = new PressArk_Blocks();
						if ( $name === 'edit_block' ) {
							$edit_updates = $args['updates'] ?? array();
							if ( ! is_array( $edit_updates ) ) {
								$edit_updates = is_string( $edit_updates ) ? (array) json_decode( $edit_updates, true ) : array();
							}
							$blocks_handler->edit_block( $draft_id, (int) ( $args['block_index'] ?? 0 ), $edit_updates );
						} else {
							$insert_attrs = $args['attrs'] ?? array();
						if ( ! is_array( $insert_attrs ) ) {
							$insert_attrs = is_string( $insert_attrs ) ? (array) json_decode( $insert_attrs, true ) : array();
						}
						$blocks_handler->insert_block( $draft_id, $args['block_type'] ?? 'core/paragraph', $insert_attrs, (string) ( $args['content'] ?? '' ), (int) ( $args['position'] ?? -1 ) );
						}
						$layer['posts'][ $args['post_id'] ] = $draft_id;
						$diff[] = array(
							'type'  => 'block_edit',
							'label' => get_the_title( $args['post_id'] ),
							'items' => array( array(
								'field' => 'Block ' . ( $args['block_index'] ?? '' ),
								'old'   => '(previous content)',
								'new'   => '(see live preview)',
							) ),
						);
					}
					break;

				case 'elementor_widget':
					if ( ! empty( $args['post_id'] ) ) {
						$el_post_id  = (int) $args['post_id'];
						$el_original = get_post( $el_post_id );

						if ( $el_original ) {
							$el_draft_id = $layer['posts'][ $el_post_id ] ?? null;
							if ( ! $el_draft_id ) {
								$el_draft_id = $this->create_elementor_draft( $el_post_id, $el_original );
							}

							if ( $el_draft_id ) {
								$elementor  = new PressArk_Elementor();
								$el_label   = get_the_title( $el_post_id ) ?: "Page #{$el_post_id}";
								$el_field   = ucwords( str_replace( array( 'elementor_', '_' ), array( '', ' ' ), $name ) );

								// Apply the specific Elementor action to the draft copy.
								switch ( $name ) {
									case 'elementor_edit_widget':
										$elementor->edit_widget(
											$el_draft_id,
											$args['widget_id'] ?? '',
											$args['changes'] ?? $args['fields'] ?? array()
										);
										$el_field = 'Edit Widget ' . ( $args['widget_id'] ?? '' );
										break;

									case 'elementor_add_widget':
										$elementor->add_widget(
											$el_draft_id,
											$args['widget_type'] ?? 'widget',
											$args['settings'] ?? array(),
											$args['container_id'] ?? '',
											(int) ( $args['position'] ?? -1 )
										);
										$el_field = 'Add ' . ucfirst( str_replace( '-', ' ', $args['widget_type'] ?? 'widget' ) ) . ' Widget';
										break;

									case 'elementor_add_container':
										$elementor->add_container(
											$el_draft_id,
											(string) ( $args['layout'] ?? 'boxed' ),
											(string) ( $args['direction'] ?? 'column' ),
											(int) ( $args['position'] ?? -1 ),
											(string) ( $args['parent_id'] ?? '' ),
											(array) ( $args['settings'] ?? array() )
										);
										$el_field = 'Add Section';
										break;

									case 'elementor_set_dynamic_tag':
										$elementor->set_dynamic_tag(
											$el_draft_id,
											$args['widget_id'] ?? '',
											$args['setting_key'] ?? '',
											$args['tag_name'] ?? '',
											$args['tag_settings'] ?? array()
										);
										$el_field = 'Dynamic Tag on ' . ( $args['widget_id'] ?? '' );
										break;

									case 'elementor_find_replace':
										$elementor->find_replace(
											$args['find'] ?? '',
											$args['replace'] ?? '',
											$el_draft_id
										);
										$el_field = 'Find & Replace';
										break;
								}

								$elementor->regenerate_post_css_public( $el_draft_id );

								$layer['posts'][ $el_post_id ] = $el_draft_id;
								$diff[] = array(
									'type'  => 'elementor_widget',
									'label' => $el_label,
									'items' => array( array(
										'field' => $el_field,
										'old'   => '(current)',
										'new'   => '(see live preview)',
									) ),
								);
							}
						}
					}
					break;

				case 'elementor_page':
					$elementor = new PressArk_Elementor();
					$create_result = $elementor->create_page(
						(string) ( $args['title']    ?? 'New Page' ),
						(string) ( $args['template'] ?? '' ),
						'draft',
						(int)    ( $args['parent']   ?? 0 ),
						(array)  ( $args['widgets']  ?? array() )
					);

					if ( ! empty( $create_result['success'] ) && ! empty( $create_result['post_id'] ) ) {
						$new_page_id = $create_result['post_id'];
						update_post_meta( $new_page_id, '_pressark_preview_draft', '1' );
						$layer['posts'][ 'new_' . $new_page_id ] = $new_page_id;
						$layer['post_blueprints'][ $new_page_id ] = array(
							'status' => sanitize_key( $args['status'] ?? 'draft' ),
						);

						// Apply extra_meta: slug, excerpt, SEO fields.
						$extra_meta = (array) ( $args['extra_meta'] ?? array() );
						if ( ! empty( $extra_meta['slug'] ) ) {
							wp_update_post( array(
								'ID'        => $new_page_id,
								'post_name' => sanitize_title( $extra_meta['slug'] ),
							) );
						}
						if ( ! empty( $extra_meta['excerpt'] ) ) {
							wp_update_post( array(
								'ID'           => $new_page_id,
								'post_excerpt' => sanitize_textarea_field( $extra_meta['excerpt'] ),
							) );
						}
						if ( ! empty( $extra_meta['meta_title'] ) ) {
							update_post_meta( $new_page_id, '_pressark_meta_title', sanitize_text_field( $extra_meta['meta_title'] ) );
						}
						if ( ! empty( $extra_meta['meta_description'] ) ) {
							update_post_meta( $new_page_id, '_pressark_meta_description', sanitize_text_field( $extra_meta['meta_description'] ) );
						}

						$diff[] = array(
							'type'  => 'elementor_page',
							'label' => $args['title'] ?? 'New Page',
							'items' => array( array(
								'field' => 'Create Elementor Page',
								'old'   => '(none)',
								'new'   => '(see live preview)',
							) ),
						);
					}
					break;

				// 'none' or unrecognized — skip (read tools or confirm-only tools).
			}
		}

		$this->store_preview_layer( $session_id, $layer );

		// Resolve the best URL to preview: use the first edited post's permalink,
		// or fall back to home_url('/') for settings/options changes.
		$preview_base_url = '';

		// 1. Try existing post permalinks from tool call arguments.
		foreach ( $tool_calls as $call ) {
			$call_args = $call['arguments'] ?? array();
			if ( ! empty( $call_args['post_id'] ) && is_numeric( $call_args['post_id'] ) ) {
				$post_id_for_preview = (int) $call_args['post_id'];
				$post_for_preview    = get_post( $post_id_for_preview );
				if ( $post_for_preview ) {
					// get_preview_post_link handles drafts, custom post types, multilingual plugins.
					$preview_link     = get_preview_post_link( $post_for_preview );
					// Strip existing WordPress preview params — we add our own.
					$preview_base_url = remove_query_arg(
						array( 'preview', 'preview_id', 'preview_nonce' ),
						$preview_link
					);
					break;
				}
			}
		}

		// 1b. For fix_seo and similar: post_id may be nested inside a fixes/items array.
		if ( empty( $preview_base_url ) ) {
			foreach ( $tool_calls as $call ) {
				$call_args = $call['arguments'] ?? array();
				if ( ! empty( $call_args['fixes'] ) && is_array( $call_args['fixes'] ) ) {
					foreach ( $call_args['fixes'] as $fix ) {
						if ( ! empty( $fix['post_id'] ) && is_numeric( $fix['post_id'] ) ) {
							$post_for_preview = get_post( (int) $fix['post_id'] );
							if ( $post_for_preview ) {
								$preview_link     = get_preview_post_link( $post_for_preview );
								$preview_base_url = remove_query_arg(
									array( 'preview', 'preview_id', 'preview_nonce' ),
									$preview_link
								);
								break 2;
							}
						}
					}
				}
			}
		}

		// 2. For new posts (no existing post_id), build a URL that WordPress can resolve
		//    regardless of post type (page, post, or CPT).
		if ( empty( $preview_base_url ) ) {
			foreach ( $layer['posts'] as $key => $draft_id ) {
				if ( str_starts_with( (string) $key, 'new_' ) || str_starts_with( (string) $key, 'gen_' ) ) {
					$new_post = get_post( $draft_id );
					if ( $new_post ) {
						if ( $new_post->post_type === 'page' ) {
							// Pages use ?page_id= in WordPress query system.
							$preview_base_url = home_url( '/?page_id=' . $draft_id );
						} else {
							// Posts and CPTs use ?p= (with explicit post_type for CPTs).
							$preview_base_url = home_url( '/?p=' . $draft_id );
							if ( $new_post->post_type !== 'post' ) {
								$preview_base_url = add_query_arg( 'post_type', $new_post->post_type, $preview_base_url );
							}
						}
					} else {
						$preview_base_url = home_url( '/?p=' . $draft_id );
					}
					break;
				}
			}
		}

		return array(
			'session_id' => $session_id,
			'signed_url' => $this->generate_signed_url( $session_id, $user_id, $preview_base_url ),
			'diff'       => $diff,
		);
	}

	/**
	 * Read the staged tool calls for a preview session without applying it.
	 *
	 * Used by the approval handlers to update the execution ledger after
	 * the user keeps a preview. This keeps continuation state server-owned.
	 *
	 * @since 3.7.5
	 *
	 * @param string $session_id Preview session ID.
	 * @return array
	 */
	public function get_session_tool_calls( string $session_id ): array {
		$layer = $this->get_preview_layer( $session_id );
		if ( ! $layer ) {
			return array();
		}
		if ( (int) ( $layer['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return array();
		}

		$tool_calls = $layer['tool_calls'] ?? array();
		return is_array( $tool_calls ) ? $tool_calls : array();
	}

	// ─── Elementor draft helper ──────────────────────────────────────────────

	/**
	 * Create an auto-draft copy of an Elementor post with all its meta.
	 * Used by the preview system to stage Elementor changes on a draft
	 * so the original is untouched until the user clicks "Keep Changes".
	 *
	 * @param int      $post_id  Original post ID.
	 * @param \WP_Post $original The original post object.
	 * @return int|false Draft post ID, or false on failure.
	 */
	private function create_elementor_draft( int $post_id, \WP_Post $original ) {
		// v3.7.0: Elementor version guard — older versions have unreliable
		// save_post hooks that corrupt _elementor_data during draft promotion.
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, self::MIN_ELEMENTOR_VERSION, '<' ) ) {
			PressArk_Error_Tracker::info( 'Preview', 'Elementor preview draft skipped (version too old)', array( 'post_id' => $post_id, 'elementor_version' => ELEMENTOR_VERSION, 'required' => self::MIN_ELEMENTOR_VERSION ) );
			return false;
		}

		$draft_id = wp_insert_post( array(
			'post_title'   => $original->post_title . ' (Preview)',
			'post_content' => $original->post_content,
			'post_status'  => 'auto-draft',
			'post_type'    => $original->post_type,
			'post_parent'  => $post_id,
			'meta_input'   => array(
				'_pressark_preview_draft'  => '1',
				'_elementor_data'          => get_post_meta( $post_id, '_elementor_data', true ),
				'_elementor_template_type' => get_post_meta( $post_id, '_elementor_template_type', true ),
				'_elementor_version'       => get_post_meta( $post_id, '_elementor_version', true ),
				'_elementor_edit_mode'     => get_post_meta( $post_id, '_elementor_edit_mode', true ),
				'_elementor_css'           => get_post_meta( $post_id, '_elementor_css', true ),
				'_wp_page_template'        => get_post_meta( $post_id, '_wp_page_template', true ),
			),
		) );

		if ( ! $draft_id || is_wp_error( $draft_id ) ) {
			return false;
		}

		return $draft_id;
	}

	// ─── Staging helpers ──────────────────────────────────────────────────────

	private function stage_post_edit( array $args ): array {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return array( null, null );
		}

		$original = get_post( $post_id );
		if ( ! $original ) {
			return array( null, null );
		}

		$fields = $args['fields'] ?? $args['changes'] ?? $args;

		$new_data = array(
			'post_status'  => 'auto-draft',
			'post_parent'  => $original->ID,
			'post_type'    => $original->post_type,
			'post_title'   => $fields['title'] ?? $fields['post_title'] ?? $original->post_title,
			'post_content' => $fields['content'] ?? $fields['post_content'] ?? $original->post_content,
			'post_excerpt' => $fields['excerpt'] ?? $fields['post_excerpt'] ?? $original->post_excerpt,
			'meta_input'   => array( '_pressark_preview_draft' => '1' ),
		);

		$draft_id = wp_insert_post( $new_data );

		$diff_entry = array(
			'type'  => 'post',
			'label' => $original->post_title,
			'items' => array(),
		);

		$new_title = $fields['title'] ?? $fields['post_title'] ?? null;
		if ( $new_title !== null && $new_title !== $original->post_title ) {
			$diff_entry['items'][] = array(
				'field' => 'Title',
				'old'   => $original->post_title,
				'new'   => $new_title,
			);
		}

		$new_content = $fields['content'] ?? $fields['post_content'] ?? null;
		if ( $new_content !== null ) {
			$diff_entry['items'][] = array(
				'field' => 'Content',
				'old'   => wp_trim_words( $original->post_content, 20 ),
				'new'   => wp_trim_words( $new_content, 20 ),
			);
		}

		$new_excerpt = $fields['excerpt'] ?? $fields['post_excerpt'] ?? null;
		if ( $new_excerpt !== null && $new_excerpt !== $original->post_excerpt ) {
			$diff_entry['items'][] = array(
				'field' => 'Excerpt',
				'old'   => $original->post_excerpt ?: '(empty)',
				'new'   => $new_excerpt,
			);
		}

		return array( $draft_id, $diff_entry );
	}

	private function stage_meta_update( array $args ): array {
		$entries = array();
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		// fix_seo uses [{ post_id, meta_title, meta_description, ... }] rather than
		// a flat changes/meta payload. Expand that shape into staged meta updates so
		// preview keep can actually persist the approved SEO fixes.
		if ( ! empty( $args['fixes'] ) && is_array( $args['fixes'] ) ) {
			$seo_fields = array(
				'meta_title',
				'meta_description',
				'canonical',
				'og_title',
				'og_description',
				'og_image',
				'focus_keyword',
			);

			foreach ( $args['fixes'] as $fix ) {
				$fix_post_id = isset( $fix['post_id'] ) ? (int) $fix['post_id'] : $post_id;
				if ( $fix_post_id <= 0 ) {
					continue;
				}

				foreach ( $seo_fields as $field ) {
					if ( ! array_key_exists( $field, $fix ) ) {
						continue;
					}

					$entry = $this->build_staged_meta_entry( $fix_post_id, $field, $fix[ $field ], true );
					if ( $entry ) {
						$entries[ $entry['store_key'] ] = array(
							'value' => $entry['value'],
							'diff'  => $entry['diff'],
						);
					}
				}
			}

			return $entries;
		}

		$meta_pairs = $args['changes'] ?? $args['meta'] ?? ( isset( $args['key'] ) ? array( $args['key'] => $args['value'] ) : array() );

		foreach ( $meta_pairs as $meta_key => $new_value ) {
			$is_semantic = $this->is_semantic_seo_field( (string) $meta_key );
			$entry       = $this->build_staged_meta_entry( $post_id, (string) $meta_key, $new_value, $is_semantic );
			if ( $entry ) {
				$entries[ $entry['store_key'] ] = array(
					'value' => $entry['value'],
					'diff'  => $entry['diff'],
				);
			}
		}

		return $entries;
	}

	/**
	 * Build one staged meta entry for preview storage and diff rendering.
	 *
	 * Semantic SEO fields are stored with a `seo:` prefix so keep() can route
	 * them back through PressArk_SEO_Resolver instead of raw update_post_meta().
	 *
	 * @param int    $post_id     Target post ID.
	 * @param string $meta_key    Raw meta key or semantic SEO field.
	 * @param mixed  $new_value   Proposed value.
	 * @param bool   $is_semantic Whether the key is a semantic SEO field.
	 * @return array<string, mixed>|null
	 */
	private function build_staged_meta_entry( int $post_id, string $meta_key, $new_value, bool $is_semantic ): ?array {
		if ( $post_id <= 0 || '' === $meta_key ) {
			return null;
		}

		if ( $is_semantic ) {
			$old_value = PressArk_SEO_Resolver::read( $post_id, $meta_key );
			if ( '' === $old_value ) {
				if ( 'meta_title' === $meta_key ) {
					$old_value = PressArk_SEO_Resolver::rendered_title( $post_id );
				} elseif ( 'meta_description' === $meta_key ) {
					$old_value = PressArk_SEO_Resolver::rendered_description( $post_id );
				}
			}
			$store_key = $post_id . '::seo:' . $meta_key;
		} else {
			$old_value = get_post_meta( $post_id, $meta_key, true );
			$store_key = $post_id . '::' . $meta_key;
		}

		return array(
			'store_key' => $store_key,
			'value'     => $new_value,
			'diff'      => array(
				'type'  => 'meta',
				'label' => $this->humanize_meta_key( $meta_key ),
				'items' => array( array(
					'field' => get_the_title( $post_id ) ?: "Post #{$post_id}",
					'old'   => ( '' !== (string) $old_value && null !== $old_value ) ? ( is_string( $old_value ) ? $old_value : wp_json_encode( $old_value ) ) : '(empty)',
					'new'   => is_string( $new_value ) ? $new_value : wp_json_encode( $new_value ),
				) ),
			),
		);
	}

	/**
	 * Whether a key is one of the semantic SEO fields handled by the resolver.
	 */
	private function is_semantic_seo_field( string $key ): bool {
		return in_array(
			$key,
			array(
				'meta_title',
				'meta_description',
				'canonical',
				'og_title',
				'og_description',
				'og_image',
				'focus_keyword',
			),
			true
		);
	}

	private function stage_option_update( array $args ): array {
		$entries = array();
		$options = $args['options'] ?? $args['settings'] ?? $args['changes'] ?? ( isset( $args['key'] ) ? array( $args['key'] => $args['value'] ) : array() );

		foreach ( $options as $key => $new_value ) {
			$old_value       = get_option( $key );
			$entries[ $key ] = array(
				'value' => $new_value,
				'diff'  => array(
					'type'  => 'option',
					'label' => 'Site Setting',
					'items' => array( array(
						'field' => $key,
						'old'   => is_string( $old_value ) ? $old_value : wp_json_encode( $old_value ),
						'new'   => is_string( $new_value ) ? $new_value : wp_json_encode( $new_value ),
					) ),
				),
			);
		}

		return $entries;
	}

	private function stage_new_post( array $args ): array {
		$status = sanitize_key( $args['status'] ?? 'draft' );
		if ( ! in_array( $status, array( 'draft', 'publish', 'future' ), true ) ) {
			$status = 'draft';
		}

		$scheduled_date = '';
		if ( 'future' === $status ) {
			$scheduled_date = sanitize_text_field( $args['scheduled_date'] ?? '' );
			if ( empty( $scheduled_date ) ) {
				$status = 'draft';
			}
		}

		$draft_id = wp_insert_post( array(
			'post_title'   => $args['title'] ?? $args['post_title'] ?? 'New Post',
			'post_content' => $args['content'] ?? $args['post_content'] ?? '',
			'post_status'  => 'auto-draft',
			'post_type'    => $args['post_type'] ?? 'post',
			'meta_input'   => array( '_pressark_preview_draft' => '1' ),
		) );

		$diff_entry = array(
			'type'  => 'new_post',
			'label' => 'New: ' . ( $args['title'] ?? $args['post_title'] ?? 'Untitled' ),
			'items' => array( array(
				'field' => 'Content preview',
				'old'   => '(new post)',
				'new'   => wp_trim_words( $args['content'] ?? $args['post_content'] ?? '', 30 ),
			) ),
		);

		return array(
			$draft_id,
			$diff_entry,
			array(
				'status'         => $status,
				'scheduled_date' => $scheduled_date,
			),
		);
	}

	private function stage_generated_content( array $args ): array {
		return $this->stage_new_post( $args );
	}

	// ─── Signed URL ──────────────────────────────────────────────────────────

	/**
	 * Generate a signed URL token the frontend page uses to activate filters.
	 * Token = HMAC(session_id + user_id + expiry, AUTH_KEY)
	 */
	public function generate_signed_url( string $session_id, int $user_id, string $base_url = '' ): string {
		$expiry  = time() + self::SESSION_EXPIRY;
		$payload = $session_id . ':' . $user_id . ':' . $expiry;
		$token   = hash_hmac( 'sha256', $payload, AUTH_KEY );

		// Use the target page URL so the iframe previews the actual edited page,
		// not the homepage. Falls back to home_url('/') for new posts or settings.
		if ( empty( $base_url ) ) {
			$base_url = home_url( '/' );
		}

		return add_query_arg( array(
			'pressark_preview' => $session_id,
			'pressark_uid'     => $user_id,
			'pressark_exp'     => $expiry,
			'pressark_token'   => $token,
		), $base_url );
	}

	/**
	 * Validate signed URL token. Called in template_redirect hook.
	 */
	public static function validate_token(): bool {
		$session_id = sanitize_text_field( wp_unslash( $_GET['pressark_preview'] ?? '' ) );
		$user_id    = (int) ( $_GET['pressark_uid'] ?? 0 );
		$expiry     = (int) ( $_GET['pressark_exp'] ?? 0 );
		$token      = sanitize_text_field( wp_unslash( $_GET['pressark_token'] ?? '' ) );

		if ( ! $session_id || ! $user_id || ! $expiry || ! $token ) {
			return false;
		}
		if ( time() > $expiry ) {
			return false;
		}
		if ( get_current_user_id() !== $user_id ) {
			return false;
		}

		$payload  = $session_id . ':' . $user_id . ':' . $expiry;
		$expected = hash_hmac( 'sha256', $payload, AUTH_KEY );

		return hash_equals( $expected, $token );
	}

	/**
	 * Activate preview filters for a validated session.
	 * Called in template_redirect — before any output.
	 */
	public static function activate_for_request(): void {
		if ( empty( $_GET['pressark_preview'] ) ) {
			return;
		}
		if ( ! self::validate_token() ) {
			return;
		}

		$session_id = sanitize_text_field( wp_unslash( $_GET['pressark_preview'] ) );
		$layer      = null;
		if ( wp_using_ext_object_cache() ) {
			try {
				$cached = wp_cache_get( 'preview_' . $session_id, 'pressark_preview' );
				if ( $cached !== false ) $layer = $cached;
			} catch ( \Throwable $e ) {
				// Non-fatal — fall through to transient.
			}
		}
		if ( ! $layer ) {
			$layer = get_transient( self::TRANSIENT_PREFIX . $session_id );
		}
		if ( ! $layer ) {
			return;
		}

		// Mark as preview mode (used by theme/JS to show preview bar).
		add_filter( 'body_class', function ( $c ) {
			$c[] = 'pressark-preview-mode';
			return $c;
		} );

		// Intercept option reads.
		foreach ( $layer['options'] as $key => $value ) {
			add_filter( "pre_option_{$key}", function () use ( $value ) {
				return $value;
			}, 99 );
		}

		// Intercept post meta reads.
		if ( ! empty( $layer['meta'] ) ) {
			add_filter( 'get_post_metadata', function ( $null, $post_id, $meta_key, $single ) use ( $layer ) {
				$store_key = $post_id . '::' . $meta_key;
				if ( isset( $layer['meta'][ $store_key ] ) ) {
					return $single ? $layer['meta'][ $store_key ] : array( $layer['meta'][ $store_key ] );
				}
				return $null;
			}, 99, 4 );
		}

		// Intercept Elementor data reads for draft copies (widget edits).
		if ( ! empty( $layer['posts'] ) ) {
			add_filter( 'get_post_metadata', function ( $null, $post_id, $meta_key, $single ) use ( $layer ) {
				// Only intercept Elementor meta for posts that have draft copies.
				if ( str_starts_with( $meta_key, '_elementor' ) && isset( $layer['posts'][ $post_id ] ) ) {
					$draft_id    = $layer['posts'][ $post_id ];
					$draft_value = get_metadata( 'post', $draft_id, $meta_key, $single );
					if ( $draft_value !== '' && $draft_value !== false ) {
						return $single ? $draft_value : array( $draft_value );
					}
				}
				return $null;
			}, 98, 4 ); // Priority 98 — before generic meta intercept at 99.
		}

		// Intercept post content reads (use draft copy).
		// v3.7.2: Pre-compute the set of affected post IDs for O(1) lookups.
		// Only intercept posts that actually have draft copies, not every post
		// on the page. This prevents unnecessary filter overhead on sites with
		// many posts per page (archives, WooCommerce shop, etc.).
		if ( ! empty( $layer['posts'] ) ) {
			$affected_ids = array_keys( $layer['posts'] );

			add_filter( 'the_title', function ( $title, $post_id ) use ( $layer ) {
				if ( isset( $layer['posts'][ $post_id ] ) ) {
					$draft = get_post( $layer['posts'][ $post_id ] );
					return $draft ? $draft->post_title : $title;
				}
				return $title;
			}, 99, 2 );

			// v3.7.2: Use 'the_content' only when the current post is in
			// our affected set. The global $post check is cheap but the filter
			// itself can conflict with caching plugins that detect callbacks on
			// 'the_content'. By checking early and returning fast, we minimize
			// the observable side effects for cache invalidation heuristics.
			add_filter( 'the_content', function ( $content ) use ( $layer, $affected_ids ) {
				global $post;
				if ( ! $post || ! in_array( $post->ID, $affected_ids, true ) ) {
					return $content; // Fast path: not an affected post.
				}
				$draft = get_post( $layer['posts'][ $post->ID ] );
				return $draft ? $draft->post_content : $content;
			}, 1 ); // Priority 1 to run before wpautop.
		}

		// Inject SEO changes overlay when preview contains SEO meta changes.
		$seo_changes = array();
		if ( ! empty( $layer['meta'] ) ) {
			foreach ( $layer['meta'] as $store_key => $value ) {
				if ( str_contains( $store_key, '::seo:' ) ) {
					$parts     = explode( '::seo:', $store_key, 2 );
					$pid       = (int) $parts[0];
					$field     = $parts[1];
					$seo_changes[] = array(
						'post_id' => $pid,
						'field'   => $field,
						'value'   => $value,
					);
				}
			}
		}

		if ( ! empty( $seo_changes ) ) {
			add_action( 'wp_head', function () use ( $seo_changes ) {
				// Group by post_id.
				$by_post = array();
				foreach ( $seo_changes as $c ) {
					$by_post[ $c['post_id'] ][ $c['field'] ] = $c['value'];
				}

				$panels_html = '';
				foreach ( $by_post as $pid => $fields ) {
					$post_title = get_the_title( $pid ) ?: "Post #{$pid}";
					$serp_title = $fields['meta_title'] ?? $post_title;
					$serp_desc  = $fields['meta_description'] ?? '';
					$serp_url   = wp_make_link_relative( get_permalink( $pid ) );

					// Build field list for non-SERP fields.
					$extra_html = '';
					$extra_fields = array(
						'canonical'      => 'Canonical URL',
						'og_title'       => 'OG Title',
						'og_description' => 'OG Description',
						'og_image'       => 'OG Image',
						'focus_keyword'  => 'Focus Keyword',
					);
					foreach ( $extra_fields as $key => $label ) {
						if ( ! empty( $fields[ $key ] ) ) {
							$val = esc_html( $fields[ $key ] );
							$extra_html .= "<div style='margin:4px 0;font-size:13px;color:#444;'>"
								. "<strong style='color:#666;'>{$label}:</strong> {$val}</div>";
						}
					}

					$panels_html .= "
					<div style='margin-bottom:16px;'>
						<div style='font-size:11px;color:#777;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;'>
							Changes for: " . esc_html( $post_title ) . "
						</div>
						<div style='background:#fff;border:1px solid #dfe1e5;border-radius:8px;padding:16px;max-width:600px;'>
							<div style='font-size:11px;color:#777;margin-bottom:2px;'>Search Preview</div>
							<div style='font-size:20px;color:#1a0dab;line-height:1.3;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>"
								. esc_html( $serp_title ) . "</div>
							<div style='font-size:14px;color:#006621;margin-bottom:4px;'>"
								. esc_html( $serp_url ) . "</div>
							<div style='font-size:14px;color:#545454;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;'>"
								. esc_html( $serp_desc ) . "</div>
						</div>
						{$extra_html}
					</div>";
				}

				echo "<div id='pressark-seo-overlay' style='
					position:fixed; top:0; left:0; right:0; z-index:99998;
					background:linear-gradient(135deg,#f0f4ff 0%,#e8f0fe 100%);
					border-bottom:2px solid #4F8CFF;
					padding:16px 24px;
					font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;
					box-shadow:0 2px 12px rgba(0,0,0,0.1);
					max-height:50vh; overflow-y:auto;'>
					<div style='display:flex;align-items:center;gap:8px;margin-bottom:12px;'>
						<span style='font-size:16px;font-weight:600;color:#1a1a2e;'>SEO Changes Preview</span>
						<span style='font-size:11px;background:#4F8CFF;color:#fff;padding:2px 8px;border-radius:10px;'>Proposed</span>
					</div>
					{$panels_html}
				</div>";
			}, 1 );
		}

		// Inject preview notice bar into frontend.
		add_action( 'wp_footer', function () {
			echo '<div id="pressark-preview-bar" style="
				position:fixed; bottom:0; left:0; right:0; z-index:99999;
				background:#1a1a2e; color:#fff; padding:12px 24px;
				display:flex; align-items:center; justify-content:space-between;
				font-family:Inter,sans-serif; font-size:14px; box-shadow:0 -2px 12px rgba(0,0,0,0.3);">
				<span>
					<span style="color:#4F8CFF; font-weight:600;">PressArk Preview</span>
					&nbsp;— This is a preview. Changes are not live yet.
					<span style="opacity:0.75;">Keep/apply them in WordPress admin to make them live.</span>
				</span>
				<span style="opacity:0.6; font-size:12px;">Close this tab to return to wp-admin</span>
			</div>';
		} );
	}

	// ─── Keep / Discard ──────────────────────────────────────────────────────

	/**
	 * Promote temp layer to live. Called when user clicks "Keep".
	 */
	public function keep( string $session_id ): array {
		// v3.7.0: Acquire a short-lived lock to prevent concurrent keep + discard.
		if ( ! $this->acquire_session_lock( $session_id ) ) {
			return array( 'success' => false, 'message' => 'Another operation is already processing this preview session.' );
		}

		$layer = $this->get_preview_layer( $session_id );
		if ( ! $layer ) {
			$this->release_session_lock( $session_id );
			// v5.0.1: Provide a specific, actionable error so the user
			// understands what happened. Preview sessions expire after 1 hour
			// (transient TTL). Previously the generic message caused confusion.
			return array(
				'success' => false,
				'message' => 'Your preview session has expired. Please send your message again to regenerate the changes.',
				'code'    => 'preview_expired',
			);
		}
		if ( (int) ( $layer['user_id'] ?? 0 ) !== get_current_user_id() ) {
			$this->release_session_lock( $session_id );
			return array( 'success' => false, 'message' => 'Preview session does not belong to this user' );
		}

		$applied         = array();
		$errors          = array();
		$live_post_ids   = array();
		$post_blueprints = $layer['post_blueprints'] ?? array();
		$logger          = new PressArk_Action_Logger();
		$log_ids         = array();

		// Promote draft posts.
		foreach ( $layer['posts'] as $original_id => $draft_id ) {
			if ( str_starts_with( (string) $original_id, 'new_' ) ||
				 str_starts_with( (string) $original_id, 'gen_' ) ) {
				// New post — capability check before publishing.
				$draft_post = get_post( $draft_id );
				$draft_post_type = $draft_post ? $draft_post->post_type : 'post';
				$publish_cap = ( 'page' === $draft_post_type ) ? 'publish_pages' : 'publish_posts';
				if ( ! current_user_can( $publish_cap ) ) {
					$errors[] = 'Capability denied: cannot publish ' . $draft_post_type . ' (draft #' . $draft_id . ')';
					continue;
				}

				// Preserve Elementor data: wp_update_post triggers save_post hooks
				// where Elementor may clear or re-process _elementor_data when it
				// detects a save from outside the Elementor editor.
				$el_meta_backup = array();
				foreach ( array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type',
								 '_elementor_version', '_elementor_css', '_wp_page_template' ) as $mk ) {
					$val = get_post_meta( $draft_id, $mk, true );
					if ( $val !== '' && $val !== false ) {
						$el_meta_backup[ $mk ] = $val;
					}
				}

				$blueprint   = $post_blueprints[ $draft_id ] ?? array();
				$post_status = sanitize_key( $blueprint['status'] ?? 'draft' );
				if ( ! in_array( $post_status, array( 'draft', 'publish', 'future' ), true ) ) {
					$post_status = 'draft';
				}

				$update_args = array(
					'ID'          => $draft_id,
					'post_status' => $post_status,
				);

				if ( 'future' === $post_status ) {
					$scheduled_date = sanitize_text_field( $blueprint['scheduled_date'] ?? '' );
					if ( ! empty( $scheduled_date ) ) {
						$update_args['edit_date']     = true;
						$update_args['post_date']     = $scheduled_date;
						$update_args['post_date_gmt'] = get_gmt_from_date( $scheduled_date );
					} else {
						$update_args['post_status'] = 'draft';
					}
				}

				// v3.7.1: Check return value — wp_update_post returns 0 or WP_Error on failure.
				$update_result = wp_update_post( $update_args, true );
				if ( is_wp_error( $update_result ) ) {
					$errors[] = 'Failed to publish draft #' . $draft_id . ': ' . $update_result->get_error_message();
				}

				// Restore Elementor meta if any was cleared by Elementor's save_post hooks.
				foreach ( $el_meta_backup as $mk => $mv ) {
					$current = get_post_meta( $draft_id, $mk, true );
					if ( $current !== $mv ) {
						// _elementor_data needs wp_slash because update_post_meta runs wp_unslash.
						$save_val = ( $mk === '_elementor_data' ) ? wp_slash( $mv ) : $mv;
						update_post_meta( $draft_id, $mk, $save_val );
					}
				}

				if ( ! empty( $el_meta_backup['_elementor_data'] ) ) {
					// Regenerate CSS for the published URL.
					if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
						try {
							$css_file = \Elementor\Core\Files\CSS\Post::create( $draft_id );
							$css_file->update();
						} catch ( \Throwable $e ) {
							if ( class_exists( '\Elementor\Plugin' ) ) {
								\Elementor\Plugin::$instance->files_manager->clear_cache();
							}
						}
					}
				}

				// Defensive: ensure Elementor rendering meta is set even if
				// save_plain_text (called during create_page) or the status-change
				// hook wiped it. Without _elementor_edit_mode = 'builder',
				// Elementor won't render and the page shows as plain text.
				$final_el_data = get_post_meta( $draft_id, '_elementor_data', true );
				if ( ! empty( $final_el_data ) ) {
					update_post_meta( $draft_id, '_elementor_edit_mode', 'builder' );
					// Also ensure the page template survived.
					$tpl = get_post_meta( $draft_id, '_wp_page_template', true );
					if ( empty( $tpl ) ) {
						update_post_meta( $draft_id, '_wp_page_template', 'elementor_header_footer' );
					}
				}

				// Clean up the preview marker.
				delete_post_meta( $draft_id, '_pressark_preview_draft' );

				$applied[] = 'Created: ' . get_the_title( $draft_id );
				$live_post_ids[] = (int) $draft_id;

				// Audit log: new post created via preview keep.
				$lid = $logger->log( 'create_post', (int) $draft_id, $draft_post_type, null, wp_json_encode( array(
					'title'  => get_the_title( $draft_id ),
					'status' => get_post_status( $draft_id ),
				) ) );
				if ( $lid ) {
					$log_ids[] = $lid;
				}
			} else {
				// Edit — capability check before modifying original.
				if ( ! current_user_can( 'edit_post', (int) $original_id ) ) {
					$errors[] = 'Capability denied: cannot edit post #' . $original_id;
					continue;
				}

				// Copy draft content to original.
				$draft = get_post( $draft_id );
				if ( $draft ) {
					// Check if this is an Elementor-only draft (widget edit).
					// Elementor drafts have the preview marker and should NOT
					// overwrite the original's title (which was appended with "(Preview)").
					$is_elementor_draft = get_post_meta( $draft_id, '_pressark_preview_draft', true ) === '1'
						&& ! empty( get_post_meta( $draft_id, '_elementor_data', true ) );

					if ( ! $is_elementor_draft ) {
						// Standard content edit — copy title/content/excerpt.
						// v3.7.1: Check return value for truthful outcome tracking.
						$edit_result = wp_update_post( array(
							'ID'           => $original_id,
							'post_title'   => $draft->post_title,
							'post_content' => $draft->post_content,
							'post_excerpt' => $draft->post_excerpt,
						), true );
						if ( is_wp_error( $edit_result ) ) {
							$errors[] = 'Failed to update post #' . $original_id . ': ' . $edit_result->get_error_message();
						}
					}

					// Copy Elementor data if the draft has it (widget edits).
					$draft_elementor = get_post_meta( $draft_id, '_elementor_data', true );
					if ( ! empty( $draft_elementor ) ) {
						// wp_slash prevents wp_unslash in update_post_meta from
						// corrupting JSON backslash escapes (e.g. \u2014 → u2014).
						update_post_meta( $original_id, '_elementor_data', wp_slash( $draft_elementor ) );

						// Update post_content with plain text from the Elementor data
						// (SEO / search / block-theme fallback rendering).
						$decoded_elements = json_decode( $draft_elementor, true );
						if ( is_array( $decoded_elements ) && class_exists( 'PressArk_Elementor' ) ) {
							$el_helper = new PressArk_Elementor();
							$el_helper->save_plain_text( $original_id, $decoded_elements );
						}

						// Defensive: save_plain_text calls wp_update_post which fires
						// save_post hooks. Elementor's hook can wipe _elementor_edit_mode
						// when it detects a save from outside the editor. Re-ensure the
						// critical meta that makes Elementor render the page.
						update_post_meta( $original_id, '_elementor_edit_mode', 'builder' );
						// Re-write _elementor_data in case Elementor's hook modified it.
						update_post_meta( $original_id, '_elementor_data', wp_slash( $draft_elementor ) );

						// Regenerate CSS for this specific post only.
						if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
							try {
								$css_file = \Elementor\Core\Files\CSS\Post::create( $original_id );
								$css_file->update();
							} catch ( \Throwable $e ) {
								// Fallback to global clear.
								if ( class_exists( '\Elementor\Plugin' ) ) {
									\Elementor\Plugin::$instance->files_manager->clear_cache();
								}
							}
						} elseif ( class_exists( '\Elementor\Plugin' ) ) {
							\Elementor\Plugin::$instance->files_manager->clear_cache();
						}
					}

					wp_delete_post( $draft_id, true );
					$applied[] = 'Updated: ' . get_the_title( $original_id );
					$live_post_ids[] = (int) $original_id;

					// Audit log: post edit via preview keep (revision-based).
					$lid = $logger->log_post_edit( (int) $original_id, 'edit_content', array( 'source' => 'preview_keep' ) );
					if ( $lid ) {
						$log_ids[] = $lid;
					}
				}
			}
		}

		// Promote options.
		// Capability check: options require manage_options.
		if ( ! empty( $layer['options'] ) && ! current_user_can( 'manage_options' ) ) {
			foreach ( $layer['options'] as $key => $value ) {
				$errors[] = 'Capability denied: cannot update option "' . $key . '" (requires manage_options)';
			}
		} else {
			$options_old = array();
			foreach ( $layer['options'] as $key => $value ) {
				// Validate against the settings allowlist/readonly list.
				if ( ! in_array( $key, PressArk_Handler_System::ALLOWED_SETTINGS, true ) ) {
					$errors[] = 'Disallowed option key: ' . $key;
					continue;
				}
				if ( in_array( $key, PressArk_Handler_System::READONLY_SETTINGS, true ) ) {
					$errors[] = 'Read-only option key: ' . $key;
					continue;
				}

				$options_old[ $key ] = get_option( $key );
				$opt_result = update_option( $key, $value );
				if ( $opt_result ) {
					$applied[] = 'Setting updated: ' . $key;
				} else {
					// update_option returns false for both failure and no-change.
					$current = get_option( $key );
					if ( $current === $value ) {
						$applied[] = 'Setting unchanged (already current): ' . $key;
					} else {
						$errors[] = 'Failed to update option: ' . $key;
						unset( $options_old[ $key ] );
					}
				}
			}

			// Audit log: settings change via preview keep.
			if ( ! empty( $options_old ) ) {
				$options_new = array();
				foreach ( $options_old as $ok => $ov ) {
					$options_new[ $ok ] = get_option( $ok );
				}
				$lid = $logger->log( 'update_site_settings', null, 'settings', wp_json_encode( $options_old ), wp_json_encode( $options_new ) );
				if ( $lid ) {
					$log_ids[] = $lid;
				}
			}
		}

		// Promote meta.
		foreach ( $layer['meta'] as $store_key => $value ) {
			list( $post_id, $meta_key ) = explode( '::', $store_key, 2 );
			$post_id = (int) $post_id;

			// Capability check: must be able to edit the target post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$errors[] = 'Capability denied: cannot edit meta on post #' . $post_id;
				continue;
			}

			$is_semantic_seo = str_starts_with( $meta_key, 'seo:' );
			$meta_post_type  = get_post_type( $post_id ) ?: 'post';

			if ( $is_semantic_seo ) {
				$field    = substr( $meta_key, 4 );
				$meta_old = PressArk_SEO_Resolver::read( $post_id, $field );
				$written  = PressArk_SEO_Resolver::write(
					$post_id,
					$field,
					is_scalar( $value ) ? (string) $value : wp_json_encode( $value )
				);

				// Resolver-backed writes can return false when the value was already
				// current, so confirm the final read before treating it as a failure.
				$meta_new = PressArk_SEO_Resolver::read( $post_id, $field );
				if ( ! $written && (string) $meta_new !== (string) $value ) {
					$errors[] = 'Failed to update SEO field: ' . $field . ' on post #' . $post_id;
					continue;
				}

				$applied[] = 'SEO meta updated: ' . $field;

				$lid = $logger->log(
					'update_meta',
					$post_id,
					$meta_post_type,
					wp_json_encode( array( 'key' => PressArk_SEO_Resolver::resolve_key( $field ), 'value' => $meta_old ) ),
					wp_json_encode( array( 'key' => PressArk_SEO_Resolver::resolve_key( $field ), 'value' => $meta_new ) )
				);
				if ( $lid ) {
					$log_ids[] = $lid;
				}
				continue;
			}

			$meta_old    = get_post_meta( $post_id, $meta_key, true );
			$meta_result = update_post_meta( $post_id, $meta_key, $value );
			if ( false === $meta_result ) {
				$errors[] = 'Failed to update meta: ' . $meta_key . ' on post #' . $post_id;
				continue;
			}

			$applied[] = 'Meta updated: ' . $meta_key;

			// Audit log: meta change via preview keep.
			$lid = $logger->log( 'update_meta', $post_id, $meta_post_type,
				wp_json_encode( array( 'key' => $meta_key, 'value' => $meta_old ) ),
				wp_json_encode( array( 'key' => $meta_key, 'value' => $value ) )
			);
			if ( $lid ) {
				$log_ids[] = $lid;
			}
		}

		$this->delete_preview_layer( $session_id );
		$this->release_session_lock( $session_id );

		// v3.7.0: Flush page caches so promoted changes are visible immediately.
		// Covers WP Super Cache, W3 Total Cache, LiteSpeed, WP Rocket, etc.
		$this->flush_page_caches();

		// v3.7.1: Truthful outcome — success only if zero critical errors.
		// Don't call the result "success" unless the apply ledger agrees.
		$has_errors = ! empty( $errors );
		$result = array(
			'success' => ! $has_errors,
			'applied' => $applied,
		);

		$targets = array();
		foreach ( array_values( array_unique( array_filter( $live_post_ids ) ) ) as $live_post_id ) {
			$target = $this->build_continuation_target( (int) $live_post_id );
			if ( $target ) {
				$targets[] = $target;
			}
		}
		if ( ! empty( $targets ) ) {
			$primary = $targets[0];
			$result['post_id']    = (int) $primary['post_id'];
			$result['post_title'] = $primary['post_title'];
			$result['post_type']  = $primary['post_type'];
			$result['post_status'] = $primary['post_status'];
			if ( ! empty( $primary['url'] ) ) {
				$result['url'] = $primary['url'];
			}
			$result['targets'] = $targets;
		}

		// Attach audit log IDs so the frontend can render the undo button.
		if ( ! empty( $log_ids ) ) {
			$result['log_ids'] = $log_ids;
			$result['log_id']  = $log_ids[0];
		}

		if ( $has_errors ) {
			$result['errors']  = $errors;
			$result['message'] = count( $errors ) . ' step(s) failed during preview promotion. '
				. count( $applied ) . ' step(s) applied successfully.';
		}
		return $result;
	}

	/**
	 * Discard all staged changes. Called when user clicks "Discard".
	 */
	public function discard( string $session_id ): array {
		// v3.7.0: Acquire lock to prevent concurrent keep + discard.
		if ( ! $this->acquire_session_lock( $session_id ) ) {
			return array( 'success' => false, 'message' => 'Another operation is already processing this preview session.' );
		}

		$layer = $this->get_preview_layer( $session_id );
		if ( ! $layer ) {
			$this->release_session_lock( $session_id );
			return array( 'success' => true ); // Already gone.
		}
		if ( (int) ( $layer['user_id'] ?? 0 ) !== get_current_user_id() ) {
			$this->release_session_lock( $session_id );
			return array( 'success' => false, 'message' => 'Preview session does not belong to this user' );
		}

		foreach ( $layer['posts'] as $draft_id ) {
			// v3.7.0: Clean up Elementor CSS artifacts before deleting draft.
			$this->cleanup_elementor_css( (int) $draft_id );
			wp_delete_post( $draft_id, true );
		}

		$this->delete_preview_layer( $session_id );
		$this->release_session_lock( $session_id );
		return array( 'success' => true );
	}

	// ─── Helper ──────────────────────────────────────────────────────────────

	/**
	 * Store preview layer — uses object cache when available,
	 * falls back to transient (which writes to wp_options otherwise).
	 *
	 * Object-cache calls are wrapped in try-catch because some hosting
	 * drop-ins (e.g. broken Redis/Memcached plugins) throw on unknown
	 * cache groups or serialization failures. The transient always
	 * writes regardless, so preview safety is preserved.
	 */
	private function store_preview_layer( string $session_id, array $layer ): void {
		if ( wp_using_ext_object_cache() ) {
			try {
				wp_cache_set(
					'preview_' . $session_id,
					$layer,
					'pressark_preview',
					self::SESSION_EXPIRY
				);
			} catch ( \Throwable $e ) {
				// Non-fatal — transient fallback below covers persistence.
			}
		}
		set_transient( self::TRANSIENT_PREFIX . $session_id, $layer, self::SESSION_EXPIRY );
	}

	/**
	 * Retrieve preview layer — checks object cache first for speed.
	 * Falls back to transient if object cache is unavailable or throws.
	 */
	private function get_preview_layer( string $session_id ): ?array {
		if ( wp_using_ext_object_cache() ) {
			try {
				$cached = wp_cache_get( 'preview_' . $session_id, 'pressark_preview' );
				if ( $cached !== false ) return $cached;
			} catch ( \Throwable $e ) {
				// Non-fatal — fall through to transient.
			}
		}
		$transient = get_transient( self::TRANSIENT_PREFIX . $session_id );
		return $transient ?: null;
	}

	/**
	 * Delete preview layer from both stores.
	 */
	private function delete_preview_layer( string $session_id ): void {
		if ( wp_using_ext_object_cache() ) {
			try {
				wp_cache_delete( 'preview_' . $session_id, 'pressark_preview' );
			} catch ( \Throwable $e ) {
				// Non-fatal — transient is the authoritative store.
			}
		}
		delete_transient( self::TRANSIENT_PREFIX . $session_id );
	}

	private function humanize_meta_key( string $key ): string {
		$map = array(
			'_yoast_wpseo_title'         => 'SEO Title (Yoast)',
			'_yoast_wpseo_metadesc'      => 'SEO Description (Yoast)',
			'rank_math_title'            => 'SEO Title (RankMath)',
			'rank_math_description'      => 'SEO Description (RankMath)',
			'_pressark_meta_title'       => 'SEO Title',
			'_pressark_meta_description' => 'SEO Description',
			'meta_title'                 => 'SEO Title',
			'meta_description'           => 'SEO Description',
		);
		return $map[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', ltrim( $key, '_' ) ) );
	}

	/**
	 * Cleanup expired preview drafts (called via cron).
	 *
	 * v3.7.0: Also cleans up orphaned Elementor CSS artifacts for deleted drafts,
	 * and logs cleanup counts for supportability.
	 */
	public static function cleanup_expired(): void {
		$drafts = get_posts( array(
			'post_status'    => 'auto-draft',
			'post_type'      => 'any',
			'meta_key'       => '_pressark_preview_draft',
			'meta_value'     => '1',
			'posts_per_page' => 50,
			'date_query'     => array(
				array(
					'before' => '2 hours ago',
					'column' => 'post_date',
				),
			),
		) );

		$count = 0;
		foreach ( $drafts as $draft ) {
			// v3.7.0: Cleanup Elementor CSS before deleting.
			( new self() )->cleanup_elementor_css( $draft->ID );
			wp_delete_post( $draft->ID, true );
			$count++;
		}

		if ( $count > 0 ) {
			PressArk_Error_Tracker::info( 'Preview', 'Cleanup removed stale drafts', array( 'count' => $count ) );
		}
	}

	// ─── v3.7.0 Hardening Helpers ───────────────────────────────────────

	/**
	 * Acquire a short-lived advisory lock for a preview session.
	 * Prevents concurrent keep + discard from corrupting state.
	 *
	 * Uses a transient as a spinlock with a 30s TTL safety net.
	 * Only one caller wins — the other gets false.
	 *
	 * @since 3.7.0
	 */
	private function acquire_session_lock( string $session_id ): bool {
		$lock_key = self::LOCK_PREFIX . $session_id;

		// Use wp_options INSERT IGNORE for true atomicity on MySQL.
		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			$option_name = '_transient_' . $lock_key;
			$rows = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'no')",
				$option_name,
				time() + 30
			) );
			if ( $rows > 0 ) {
				// Also set the timeout transient.
				set_transient( $lock_key, '1', 30 );
				return true;
			}
			// Check if lock is stale (> 30s old).
			$existing = get_transient( $lock_key );
			return false;
		}

		// Object cache path: add() is atomic in Redis/Memcached.
		try {
			return wp_cache_add( 'lock_' . $session_id, '1', 'pressark_preview', 30 );
		} catch ( \Throwable $e ) {
			// Drop-in threw — fall back to transient locking.
			return (bool) set_transient( self::LOCK_PREFIX . $session_id, '1', 30 );
		}
	}

	/**
	 * Release the session lock.
	 *
	 * @since 3.7.0
	 */
	private function release_session_lock( string $session_id ): void {
		$lock_key = self::LOCK_PREFIX . $session_id;
		delete_transient( $lock_key );
		if ( wp_using_ext_object_cache() ) {
			try {
				wp_cache_delete( 'lock_' . $session_id, 'pressark_preview' );
			} catch ( \Throwable $e ) {
				// Non-fatal — lock TTL will auto-expire in 30s.
			}
		}
	}

	/**
	 * Clean up Elementor CSS files generated for a preview draft.
	 * Without this, discarded drafts leave orphaned CSS in wp-content/uploads/elementor/css/.
	 *
	 * @since 3.7.0
	 * @param int $draft_id Post ID of the draft being deleted.
	 */
	private function cleanup_elementor_css( int $draft_id ): void {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}

		try {
			$css_file = \Elementor\Core\Files\CSS\Post::create( $draft_id );
			$css_file->delete();
		} catch ( \Throwable $e ) {
			// Non-fatal — CSS file may not exist.
		}
	}

	/**
	 * Flush common WordPress page caches after preview promotion.
	 * Ensures the live site immediately reflects the kept changes
	 * instead of serving stale cached pages.
	 *
	 * @since 3.7.0
	 */
	private function flush_page_caches(): void {
		// Each cache plugin call is isolated in try-catch. A broken cache
		// plugin must never prevent preview promotion from succeeding.

		// WP Super Cache — guard against non-WP-Super-Cache definitions of the same name.
		if ( function_exists( 'wp_cache_clear_cache' ) && defined( 'WPCACHEHOME' ) ) {
			try { wp_cache_clear_cache(); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			try { w3tc_flush_posts(); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// LiteSpeed Cache.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			try { do_action( 'litespeed_purge_all' ); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			try { rocket_clean_domain(); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// Generic WP object cache group flush (WP 6.1+).
		// Not all object-cache drop-ins support group flushing — feature-detect first.
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			try { wp_cache_flush_group( 'posts' ); } catch ( \Throwable $e ) { /* non-fatal */ }
		}
	}

	/**
	 * Build stable target metadata for post-approval continuation.
	 *
	 * This lets the follow-up run stay anchored to the newly created or updated
	 * post instead of asking the model to rediscover the target from scratch.
	 *
	 * @since 3.7.4
	 */
	private function build_continuation_target( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$title = get_the_title( $post );
		if ( '' === $title ) {
			$title = 'Post #' . $post_id;
		}

		$url = '';
		if ( 'publish' === $post->post_status ) {
			$url = (string) get_permalink( $post );
		} else {
			$url = (string) get_preview_post_link( $post );
			if ( empty( $url ) ) {
				$url = (string) get_permalink( $post );
			}
		}

		return array(
			'post_id'     => (int) $post_id,
			'post_title'  => $title,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'url'         => $url,
		);
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Register all preview-related WordPress hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'pre_get_posts', array( self::class, 'handle_pre_get_posts' ) );
		add_action( 'template_redirect', array( self::class, 'handle_template_redirect' ), 5 );
		add_action( 'init', array( self::class, 'schedule_cleanup' ) );
		add_action( 'pressark_preview_cleanup', array( self::class, 'cleanup_expired' ) );
	}

	/**
	 * Allow auto-draft posts to be served when viewing a PressArk preview.
	 *
	 * @since 4.2.0
	 */
	public static function handle_pre_get_posts( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || empty( $_GET['pressark_preview'] ) ) {
			return;
		}
		// Support both ?p=ID (posts/CPTs) and ?page_id=ID (pages).
		$queried_id = (int) ( $_GET['p'] ?? $_GET['page_id'] ?? 0 );
		if ( ! $queried_id ) {
			return;
		}
		if ( ! self::validate_token() ) {
			return;
		}

		$session_id = sanitize_text_field( wp_unslash( $_GET['pressark_preview'] ) );
		$layer      = get_transient( self::TRANSIENT_PREFIX . $session_id );
		if ( ! $layer ) {
			return;
		}

		foreach ( $layer['posts'] as $key => $draft_id ) {
			if ( ( str_starts_with( (string) $key, 'new_' ) || str_starts_with( (string) $key, 'gen_' ) )
				&& (int) $draft_id === $queried_id
			) {
				$query->set( 'post_status', array( 'publish', 'draft', 'auto-draft', 'pending', 'private' ) );
				break;
			}
		}
	}

	/**
	 * Activate preview temp layer when viewing a preview URL.
	 *
	 * @since 4.2.0
	 */
	public static function handle_template_redirect(): void {
		self::activate_for_request();
	}

	/**
	 * Schedule hourly cleanup of expired preview drafts.
	 *
	 * @since 4.2.0
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'pressark_preview_cleanup' ) ) {
			wp_schedule_event( time(), 'hourly', 'pressark_preview_cleanup' );
		}
	}
}
