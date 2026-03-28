<?php
/**
 * Diagnostics, blocks, templates, patterns, and multisite action handlers.
 *
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Handler_Diagnostics extends PressArk_Handler_Base {

	// ── Composite Diagnostics ───────────────────────────────────────────

	public function handle_store_health( array $params ): array {
		$cap_error = $this->require_cap( 'manage_woocommerce' );
		if ( $cap_error ) {
			return $cap_error;
		}

		$diagnostics = new PressArk_Diagnostics();
		$result      = $diagnostics->store_health();
		if ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}
		return array( 'success' => true, 'message' => __( 'Store health report.', 'pressark' ), 'data' => $result );
	}

	public function handle_site_brief( array $params ): array {
		$diagnostics = new PressArk_Diagnostics();
		$result      = $diagnostics->site_brief();
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $result['pending_updates'], $result['integrations'] );
		}
		return array( 'success' => true, 'message' => __( 'Site brief.', 'pressark' ), 'data' => $result );
	}

	public function handle_page_audit( array $params ): array {
		$post_id = (int) ( $params['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Post ID is required.', 'pressark' ) );
		}
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return $this->error( __( 'You do not have permission to audit this post.', 'pressark' ) );
		}
		$diagnostics = new PressArk_Diagnostics();
		$result      = $diagnostics->page_audit( $post_id );
		if ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}
		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: audited page title */
				__( 'Page audit for "%s".', 'pressark' ),
				$result['title'] ?? ''
			),
			'data'    => $result,
		);
	}

	// ── Evidence-Based Diagnostics ──────────────────────────────────────

	public function handle_inspect_hooks( array $params ): array {
		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->diagnose_slow_hook( $params['hook_name'] ?? 'wp_head' );
		if ( isset( $data['error'] ) ) {
			return array( 'success' => false, 'message' => $data['error'], 'data' => $data );
		}

		// Filter callbacks by pattern.
		$pattern = sanitize_text_field( $params['pattern'] ?? '' );
		$limit   = min( absint( $params['limit'] ?? 50 ), 200 );

		if ( ! empty( $data['callbacks'] ) && is_array( $data['callbacks'] ) ) {
			$total = count( $data['callbacks'] );
			if ( $pattern ) {
				$data['callbacks'] = array_values( array_filter( $data['callbacks'], function ( $cb ) use ( $pattern ) {
					$name = $cb['function'] ?? $cb['callback'] ?? $cb['name'] ?? '';
					return stripos( $name, $pattern ) !== false;
				} ) );
				$data['_pattern_filter'] = $pattern;
			}
			$data['callbacks']  = array_slice( $data['callbacks'], 0, $limit );
			$data['_pagination'] = array(
				'total'    => $total,
				'shown'    => count( $data['callbacks'] ),
				'limit'    => $limit,
				'has_more' => $total > $limit,
			);
		}

		return array( 'success' => true, 'message' => __( 'Hook inspection complete.', 'pressark' ), 'data' => $data );
	}

	public function handle_measure_page_speed( array $params ): array {
		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->measure_page_speed( $params['url'] ?? '' );
		if ( isset( $data['error'] ) ) {
			return array( 'success' => false, 'message' => $data['error'], 'data' => $data );
		}
		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: page load time in milliseconds, 2: performance assessment */
				__( 'Page speed: %1$dms (%2$s).', 'pressark' ),
				$data['load_time_ms'] ?? 0,
				$data['assessment'] ?? 'unknown'
			),
			'data'    => $data,
		);
	}

	public function handle_check_crawlability( array $params ): array {
		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->check_crawlability();
		if ( isset( $data['error'] ) ) {
			return array( 'success' => false, 'message' => $data['error'], 'data' => $data );
		}
		return array( 'success' => true, 'message' => __( 'Crawlability check complete.', 'pressark' ), 'data' => $data );
	}

	public function handle_check_email_delivery( array $params ): array {
		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->check_email_delivery();
		if ( isset( $data['error'] ) ) {
			return array( 'success' => false, 'message' => $data['error'], 'data' => $data );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $data['admin_email'], $data['from_email'] );
		}
		return array( 'success' => true, 'message' => __( 'Email delivery check complete.', 'pressark' ), 'data' => $data );
	}

	public function handle_profile_queries( array $params ): array {
		$cap_error = $this->require_cap( 'manage_options' );
		if ( $cap_error ) {
			return $cap_error;
		}

		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->profile_page_queries( $params['url'] ?? '' );
		if ( isset( $data['error'] ) ) {
			return array( 'success' => false, 'message' => $data['error'], 'data' => $data );
		}
		return array( 'success' => true, 'message' => $data['summary'] ?? __( 'Query profiling complete.', 'pressark' ), 'data' => $data );
	}

	public function handle_get_revision_history( array $params ): array {
		$post_id    = (int) ( $params['post_id'] ?? 0 );
		$limit      = min( (int) ( $params['limit'] ?? 10 ), 20 );
		$compare_to = (int) ( $params['compare_to'] ?? 0 );
		$post       = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		$revisions = wp_get_post_revisions( $post_id, array(
			'numberposts' => $limit,
			'orderby'     => 'date',
			'order'       => 'DESC',
		));

		$history = array();
		$prev    = null;

		foreach ( $revisions as $rev ) {
			$author   = get_userdata( $rev->post_author );
			$time_ago = human_time_diff( strtotime( $rev->post_modified ), time() );

			$changes = array();
			if ( $prev ) {
				if ( $rev->post_title   !== $prev->post_title )   $changes[] = 'title';
				if ( $rev->post_content !== $prev->post_content ) $changes[] = 'content';
				if ( $rev->post_excerpt !== $prev->post_excerpt ) $changes[] = 'excerpt';
			}

			$entry = array(
				'revision_id' => $rev->ID,
				'time_ago'    => sprintf(
					/* translators: %s: human-readable elapsed time */
					__( '%s ago', 'pressark' ),
					$time_ago
				),
				'author'      => $author ? $author->display_name : __( 'Unknown', 'pressark' ),
				'changed'     => $changes ?: array( __( 'initial version', 'pressark' ) ),
				'title_then'  => $rev->post_title,
				'is_pressark' => (bool) get_metadata( 'post', $rev->ID, '_pressark_checkpoint', true ),
			);

			// If compare_to matches this revision, include actual diff.
			if ( $compare_to && $compare_to === $rev->ID ) {
				if ( ! function_exists( 'wp_get_revision_ui_diff' ) ) {
					require_once ABSPATH . 'wp-admin/includes/revision.php';
				}

				$diffs = wp_get_revision_ui_diff( $post, $compare_to, $post_id );
				$entry['diff'] = array();
				foreach ( $diffs as $field_diff ) {
					if ( ! empty( $field_diff['diff'] ) ) {
						$entry['diff'][] = array(
							'field'    => $field_diff['name'],
							'has_diff' => true,
							'summary'  => sprintf(
								/* translators: %s: revision field name */
								__( 'Changes detected in %s', 'pressark' ),
								$field_diff['name']
							),
						);
					}
				}
			}

			$history[] = $entry;
			$prev      = $rev;
		}

		return array(
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
			'revisions'  => $history,
			'rev_limit'  => wp_revisions_to_keep( $post ),
			'hint'       => __( 'Use revision_id with compare_to parameter to see what changed.', 'pressark' ),
		);
	}

	// ── REST & Cache Diagnostics ────────────────────────────────────────

	public function handle_discover_rest_routes( array $params ): array {
		$cap_error = $this->require_cap( 'manage_options' );
		if ( $cap_error ) {
			return $cap_error;
		}

		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->discover_rest_routes( $params );
		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of REST routes, 2: number of namespaces */
				__( '%1$d REST routes across %2$d namespaces.', 'pressark' ),
				$data['total_routes'],
				$data['namespace_count']
			),
			'data'    => $data,
		);
	}

	public function handle_call_rest_endpoint( array $params ): array {
		$cap_error = $this->require_cap( 'manage_options' );
		if ( $cap_error ) {
			return $cap_error;
		}

		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->call_rest_endpoint( $params );
		if ( isset( $data['error'] ) && ! isset( $data['status'] ) ) {
			return array( 'success' => false, 'message' => $data['error'] );
		}
		$success = ( $data['success'] ?? false );
		return array(
			'success' => $success,
			'message' => $success
				/* translators: 1: HTTP method, 2: REST route, 3: HTTP status code */
				? sprintf(
					/* translators: 1: HTTP method, 2: REST route, 3: HTTP status code */
					__( 'REST %1$s %2$s → %3$d', 'pressark' ),
					strtoupper( $params['method'] ?? 'GET' ),
					$params['route'] ?? '',
					$data['status'] ?? 200
				)
				: ( $data['error'] ?? __( 'REST request failed.', 'pressark' ) ),
			'data'    => $data,
		);
	}

	public function handle_diagnose_cache( array $params ): array {
		$cap_error = $this->require_cap( 'manage_options' );
		if ( $cap_error ) {
			return $cap_error;
		}

		$diagnostics = new PressArk_Diagnostics();
		$data = $diagnostics->diagnose_cache();
		return array(
			'success' => true,
			'message' => $data['assessment'],
			'data'    => $data,
		);
	}

	// ── Comment Moderation Analysis ─────────────────────────────────────

	/**
	 * Analyze why a comment is held for moderation.
	 * Uses WP's native check_comment() plus blocklist analysis.
	 */
	public function analyze_comment_moderation( array $params ): array {
		$comment_id = (int) ( $params['comment_id'] ?? 0 );
		$c          = get_comment( $comment_id );

		if ( ! $c ) return array( 'success' => false, 'message' => __( 'Comment not found.', 'pressark' ) );

		$would_approve = check_comment(
			$c->comment_author,
			$c->comment_author_email,
			$c->comment_author_url,
			$c->comment_content,
			$c->comment_author_IP,
			$c->comment_agent,
			$c->comment_type
		);

		$reasons      = array();
		$link_limit   = (int) get_option( 'comment_max_links', 2 );
		$link_count   = (int) preg_match_all( '/https?:\/\//i', $c->comment_content );
		$blocklist    = get_option( 'disallowed_keys', '' );
		$mod_keys     = get_option( 'moderation_keys', '' );

		if ( $link_count >= $link_limit ) {
			/* translators: 1: number of links found, 2: configured comment link limit */
			$reasons[] = sprintf( __( 'Too many links (%1$d — limit is %2$d)', 'pressark' ), $link_count, $link_limit );
		}
		if ( $blocklist && $c->comment_content ) {
			$pattern = implode( '|', array_map( 'preg_quote', array_filter( explode( "\n", $blocklist ) ) ) );
			if ( $pattern && preg_match( "/$pattern/iu", $c->comment_content ) ) {
				$reasons[] = __( 'Matches a word in the comment blocklist (Settings → Discussion)', 'pressark' );
			}
		}
		if ( $mod_keys ) {
			$pattern = implode( '|', array_map( 'preg_quote', array_filter( explode( "\n", $mod_keys ) ) ) );
			if ( $pattern && preg_match( "/$pattern/iu", $c->comment_content ) ) {
				$reasons[] = __( 'Matches a moderation keyword (Settings → Discussion)', 'pressark' );
			}
		}
		if ( ! get_option( 'comment_whitelist' ) && ! $would_approve ) {
			$reasons[] = __( 'Comment author has no previously approved comments', 'pressark' );
		}
		if ( empty( $reasons ) && ! $would_approve ) {
			$reasons[] = __( 'Unknown — may be Akismet or another spam plugin', 'pressark' );
		}

		return array(
			'success'       => true,
			'message'       => $would_approve
				? __( 'Comment would be auto-approved.', 'pressark' )
				: sprintf(
					/* translators: %s: semicolon-separated list of moderation reasons */
					__( 'Comment held: %s', 'pressark' ),
					implode( '; ', $reasons )
				),
			'data'          => array(
				'comment_id'    => $comment_id,
				'author'        => $c->comment_author,
				'content'       => mb_substr( $c->comment_content, 0, 200 ),
				'status'        => $c->comment_approved,
				'would_approve' => $would_approve,
				'hold_reasons'  => $would_approve ? array() : $reasons,
				'link_count'    => $link_count,
				'link_limit'    => $link_limit,
			),
		);
	}

	// ── Gutenberg Blocks ────────────────────────────────────────────────

	public function handle_read_blocks( array $params ): array {
		$blocks = new PressArk_Blocks();
		return $blocks->read_blocks( (int) ( $params['post_id'] ?? 0 ) );
	}

	public function handle_edit_block( array $params ): array {
		$post_id = (int) ( $params['post_id'] ?? 0 );

		// Resolve from URL if provided.
		if ( empty( $post_id ) && ! empty( $params['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( $params['url'] ) );
		}

		// Resolve from slug if provided.
		if ( empty( $post_id ) && ! empty( $params['slug'] ) ) {
			$post_type = sanitize_text_field( $params['post_type'] ?? 'page' );
			$found     = get_page_by_path( sanitize_text_field( $params['slug'] ), OBJECT, $post_type );
			if ( $found ) {
				$post_id = $found->ID;
			}
		}

		$blocks = new PressArk_Blocks();
		return $blocks->edit_block(
			$post_id,
			$params['block_index'] ?? 0,
			(array) ( $params['updates'] ?? array() )
		);
	}

	public function handle_insert_block( array $params ): array {
		$blocks = new PressArk_Blocks();
		return $blocks->insert_block(
			(int)    ( $params['post_id']    ?? 0 ),
			(string) ( $params['block_type'] ?? 'core/paragraph' ),
			(array)  ( $params['attrs']      ?? array() ),
			(string) ( $params['content']    ?? '' ),
			(int)    ( $params['position']   ?? -1 )
		);
	}

	// ── FSE Templates ───────────────────────────────────────────────────

	/**
	 * Read FSE block templates and template parts.
	 */
	public function get_templates( array $params ): array {
		if ( ! wp_is_block_theme() ) {
			return array( 'error' => __( 'This site does not use a block theme. FSE templates are not available.', 'pressark' ) );
		}

		$type = $params['type'] ?? 'wp_template';
		$slug = $params['slug'] ?? '';

		// Single template by slug.
		if ( ! empty( $slug ) ) {
			$theme    = get_stylesheet();
			$template = get_block_template( $theme . '//' . $slug, $type );

			if ( ! $template ) {
				return array(
					'error' => sprintf(
						/* translators: %s: template slug */
						__( 'Template "%s" not found.', 'pressark' ),
						$slug
					),
				);
			}

			$blocks_handler = new PressArk_Blocks();
			$raw_blocks     = parse_blocks( $template->content );
			$block_tree     = array();
			$index          = 0;
			$issues         = array();
			$word_count     = 0;

			foreach ( $raw_blocks as $block ) {
				if ( empty( $block['blockName'] ) ) {
					continue;
				}
				$block_tree[] = $this->parse_template_block( $block, $index, $issues );
				$index++;
			}

			return array(
				'slug'        => $template->slug,
				'title'       => $template->title,
				'type'        => $type,
				'source'      => $template->source,
				'origin'      => $template->origin ?? 'theme',
				'has_custom'  => $template->source === 'custom',
				'description' => $template->description,
				'block_count' => count( $block_tree ),
				'blocks'      => $block_tree,
				'issues'      => $issues,
				'edit_hint'   => __( 'Use edit_template with slug and block_index to modify a specific block.', 'pressark' ),
			);
		}

		// List all templates of this type.
		$templates = get_block_templates( array(), $type );
		$result    = array();

		foreach ( $templates as $template ) {
			$raw_blocks  = parse_blocks( $template->content );
			$block_count = count( array_filter( $raw_blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

			$result[] = array(
				'slug'        => $template->slug,
				'title'       => $template->title,
				'source'      => $template->source,
				'origin'      => $template->origin ?? 'theme',
				'has_custom'  => $template->source === 'custom',
				'description' => $template->description,
				'block_count' => $block_count,
			);
		}

		return array(
			'type'      => $type,
			'count'     => count( $result ),
			'templates' => $result,
			'hint'      => __( 'Use get_templates with slug for block-level detail on a specific template.', 'pressark' ),
		);
	}

	/**
	 * Parse a template block into a readable structure.
	 */
	private function parse_template_block( array $block, $index, array &$issues ): array {
		$name   = $block['blockName'] ?? 'core/freeform';
		$schema = PressArk_Blocks::get_block_schema()[ $name ] ?? null;

		$label      = $schema['label'] ?? ucwords( str_replace( array( 'core/', '/' ), array( '', ' ' ), $name ) );
		$is_dynamic = $schema['is_dynamic'] ?? false;
		$inner      = $block['innerContent'] ?? array();
		$attrs      = $block['attrs'] ?? array();

		$html    = implode( '', array_filter( $inner, 'is_string' ) );
		$text    = wp_strip_all_tags( $html );
		$preview = mb_substr( trim( $text ), 0, 80 );
		if ( strlen( $text ) > 80 ) {
			$preview .= '…';
		}

		$node = array(
			'index' => $index,
			'name'  => $name,
			'label' => $label,
			'attrs' => $attrs,
		);

		if ( ! empty( $preview ) ) {
			$node['preview'] = $preview;
		}

		if ( $is_dynamic ) {
			$node['is_dynamic'] = true;
		}

		// Template part reference.
		if ( $name === 'core/template-part' && ! empty( $attrs['slug'] ) ) {
			$node['template_part_slug'] = $attrs['slug'];
			$node['hint'] = sprintf(
				/* translators: %s: template part slug */
				__( 'Use get_templates with type=wp_template_part and slug=%s to inspect this part.', 'pressark' ),
				$attrs['slug']
			);
		}

		// Recurse into inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$node['inner_blocks'] = array();
			$inner_index          = 0;
			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( empty( $inner_block['blockName'] ) ) {
					continue;
				}
				$node['inner_blocks'][] = $this->parse_template_block(
					$inner_block,
					$index . '.' . $inner_index,
					$issues
				);
				$inner_index++;
			}
		}

		return $node;
	}

	/**
	 * Edit a specific block within an FSE template.
	 */
	public function edit_template( array $params ): array {
		if ( ! wp_is_block_theme() ) {
			return array( 'error' => __( 'This site does not use a block theme.', 'pressark' ) );
		}

		$slug        = $params['slug']        ?? '';
		$type        = $params['type']        ?? 'wp_template';
		$block_index = $params['block_index'] ?? null;
		$updates     = $params['updates']     ?? array();

		if ( empty( $slug ) ) {
			return array( 'error' => __( 'slug is required.', 'pressark' ) );
		}

		if ( $block_index === null || empty( $updates ) ) {
			return array( 'error' => __( 'block_index and updates are required.', 'pressark' ) );
		}

		$theme    = get_stylesheet();
		$template = get_block_template( $theme . '//' . $slug, $type );

		if ( ! $template ) {
			return array(
				'error' => sprintf(
					/* translators: %s: template slug */
					__( 'Template "%s" not found.', 'pressark' ),
					$slug
				),
			);
		}

		// If the template comes from the theme, we need to create a user override.
		if ( $template->source !== 'custom' ) {
			$post_id = wp_insert_post( array(
				'post_type'    => $type,
				'post_name'    => $slug,
				'post_title'   => $template->title,
				'post_content' => $template->content,
				'post_status'  => 'publish',
				'tax_input'    => array(
					'wp_theme' => array( get_stylesheet() ),
				),
			) );

			if ( is_wp_error( $post_id ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: WordPress error message */
						__( 'Failed to create template override: %s', 'pressark' ),
						$post_id->get_error_message()
					),
				);
			}

			wp_set_object_terms( $post_id, get_stylesheet(), 'wp_theme' );
		} else {
			$post_id = $template->wp_id;
		}

		// Use PressArk_Blocks to edit the block at the given index.
		$blocks_handler = new PressArk_Blocks();
		$result         = $blocks_handler->edit_block( $post_id, $block_index, $updates );

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$result['template_slug'] = $slug;
		$result['template_type'] = $type;
		if ( $template->source !== 'custom' ) {
			$result['note'] = __( 'Created a user override for this theme template. The original theme template is preserved.', 'pressark' );
		}

		return $result;
	}

	// ── Design System & Patterns ────────────────────────────────────────

	/**
	 * Read the site's design system from theme.json global settings and styles.
	 */
	public function get_design_system( array $params ): array {
		if ( ! wp_theme_has_theme_json() ) {
			return array(
				'error'   => __( 'This theme does not use theme.json. Design tokens are not available.', 'pressark' ),
				'hint'    => __( 'Use get_customizer_schema to see available design settings for classic themes.', 'pressark' ),
			);
		}

		$section = $params['section'] ?? 'all';

		$settings = wp_get_global_settings();
		$styles   = wp_get_global_styles();

		$result = array(
			'has_theme_json' => true,
			'theme'          => wp_get_theme()->get( 'Name' ),
		);

		$include_all = ( $section === 'all' );

		// Color palette.
		if ( $include_all || $section === 'colors' ) {
			$palette = $settings['color']['palette']['theme'] ?? $settings['color']['palette']['default'] ?? array();
			$custom  = $settings['color']['palette']['custom'] ?? array();
			$result['colors'] = array(
				'palette'        => array_map( fn( $c ) => array(
					'slug'  => $c['slug'],
					'name'  => $c['name'],
					'color' => $c['color'],
				), $palette ),
				'custom_palette' => $custom,
				'gradients'      => $settings['color']['gradients']['theme'] ?? array(),
				'background'     => $styles['color']['background'] ?? null,
				'text'           => $styles['color']['text'] ?? null,
			);
		}

		// Typography.
		if ( $include_all || $section === 'typography' ) {
			$font_families = $settings['typography']['fontFamilies']['theme']
				?? $settings['typography']['fontFamilies']['default']
				?? array();
			$font_sizes = $settings['typography']['fontSizes']['theme']
				?? $settings['typography']['fontSizes']['default']
				?? array();

			$result['typography'] = array(
				'font_families' => array_map( fn( $f ) => array(
					'slug'       => $f['slug'],
					'name'       => $f['name'],
					'fontFamily' => $f['fontFamily'] ?? '',
				), $font_families ),
				'font_sizes'    => array_map( fn( $s ) => array(
					'slug' => $s['slug'],
					'name' => $s['name'],
					'size' => $s['size'],
				), $font_sizes ),
				'line_height'   => $styles['typography']['lineHeight'] ?? null,
				'font_style'    => $styles['typography']['fontStyle'] ?? null,
			);
		}

		// Spacing.
		if ( $include_all || $section === 'spacing' ) {
			$result['spacing'] = array(
				'units'          => $settings['spacing']['units'] ?? array(),
				'block_gap'      => $styles['spacing']['blockGap'] ?? null,
				'padding'        => $styles['spacing']['padding'] ?? null,
				'margin'         => $styles['spacing']['margin'] ?? null,
				'spacing_scale'  => $settings['spacing']['spacingSizes']['theme'] ?? array(),
			);
		}

		// Layout.
		if ( $include_all || $section === 'layout' ) {
			$result['layout'] = array(
				'content_size' => $settings['layout']['contentSize'] ?? null,
				'wide_size'    => $settings['layout']['wideSize'] ?? null,
			);
		}

		// Element styles (links, headings, buttons, captions).
		if ( $include_all || $section === 'elements' ) {
			$result['elements'] = $styles['elements'] ?? array();
		}

		return $result;
	}

	/**
	 * List registered block patterns, optionally filtered by category.
	 */
	public function list_patterns( array $params ): array {
		$category = $params['category'] ?? '';
		$search   = $params['search']   ?? '';

		$registry = WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();

		// Also get categories for reference.
		$cat_registry  = WP_Block_Pattern_Categories_Registry::get_instance();
		$all_cats      = $cat_registry->get_all_registered();
		$cat_names     = array_map( fn( $c ) => array(
			'name'  => $c['name'],
			'label' => $c['label'],
		), $all_cats );

		$patterns = array();
		foreach ( $all as $pattern ) {
			// Filter by category.
			if ( ! empty( $category ) ) {
				$cats = $pattern['categories'] ?? array();
				if ( ! in_array( $category, $cats, true ) ) {
					continue;
				}
			}

			// Filter by search term.
			if ( ! empty( $search ) ) {
				$haystack = strtolower( ( $pattern['title'] ?? '' ) . ' ' . ( $pattern['description'] ?? '' ) );
				if ( strpos( $haystack, strtolower( $search ) ) === false ) {
					continue;
				}
			}

			$raw_blocks  = parse_blocks( $pattern['content'] );
			$block_count = count( array_filter( $raw_blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );
			$block_types = array_unique( array_filter( array_column(
				array_filter( $raw_blocks, fn( $b ) => ! empty( $b['blockName'] ) ),
				'blockName'
			) ) );

			$patterns[] = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'] ?? '',
				'description' => $pattern['description'] ?? '',
				'categories'  => $pattern['categories'] ?? array(),
				'block_count' => $block_count,
				'block_types' => array_values( $block_types ),
				'viewport_width' => $pattern['viewportWidth'] ?? null,
			);
		}

		return array(
			'count'      => count( $patterns ),
			'categories' => $cat_names,
			'patterns'   => $patterns,
			'hint'       => __( 'Use insert_pattern with pattern name and post_id to insert a pattern into a post.', 'pressark' ),
		);
	}

	/**
	 * Insert a block pattern into a post at a specified position.
	 */
	public function insert_pattern( array $params ): array {
		$post_id      = (int) ( $params['post_id'] ?? 0 );
		$pattern_name = $params['pattern'] ?? '';
		$position     = (int) ( $params['position'] ?? -1 );

		if ( $post_id <= 0 ) {
			return array( 'error' => __( 'post_id is required.', 'pressark' ) );
		}
		if ( empty( $pattern_name ) ) {
			return array( 'error' => __( 'pattern name is required.', 'pressark' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		$registry = WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( $pattern_name );

		if ( ! $pattern ) {
			return array(
				'error' => sprintf(
					/* translators: %s: block pattern name */
					__( 'Pattern "%s" not found.', 'pressark' ),
					$pattern_name
				),
			);
		}

		$pattern_blocks = parse_blocks( $pattern['content'] );
		$pattern_blocks = array_values( array_filter( $pattern_blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

		if ( empty( $pattern_blocks ) ) {
			return array( 'error' => __( 'Pattern contains no blocks.', 'pressark' ) );
		}

		$existing_blocks = has_blocks( $post->post_content )
			? parse_blocks( $post->post_content )
			: array();
		$existing_blocks = array_values( array_filter( $existing_blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

		if ( $position === -1 || $position >= count( $existing_blocks ) ) {
			$merged = array_merge( $existing_blocks, $pattern_blocks );
		} else {
			array_splice( $existing_blocks, $position, 0, $pattern_blocks );
			$merged = $existing_blocks;
		}

		$new_content = serialize_blocks( $merged );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'pattern'        => $pattern_name,
			'pattern_title'  => $pattern['title'] ?? '',
			'blocks_added'   => count( $pattern_blocks ),
			'position'       => $position === -1 ? count( $existing_blocks ) : $position,
			'total_blocks'   => count( $merged ),
		);
	}

	// ── Multisite ───────────────────────────────────────────────────────

	public function network_overview( array $params ): array {
		if ( ! is_multisite() ) {
			return array(
				'is_multisite' => false,
				'message'      => __( 'This is a single-site WordPress installation. Multisite is not enabled.', 'pressark' ),
			);
		}

		$limit  = (int) ( $params['limit'] ?? 20 );
		$offset = (int) ( $params['offset'] ?? 0 );

		$sites = get_sites( array(
			'number' => $limit,
			'offset' => $offset,
			'orderby' => 'id',
		) );

		$total  = (int) get_sites( array( 'count' => true ) );
		$result = array();

		foreach ( $sites as $site ) {
			switch_to_blog( $site->blog_id );

			$theme       = wp_get_theme();
			$plugin_count = count( get_option( 'active_plugins', array() ) );
			$post_count  = (int) wp_count_posts()->publish;
			$page_count  = (int) wp_count_posts( 'page' )->publish;

			$result[] = array(
				'blog_id'      => (int) $site->blog_id,
				'domain'       => $site->domain,
				'path'         => $site->path,
				'blogname'     => get_option( 'blogname' ),
				'siteurl'      => get_option( 'siteurl' ),
				'registered'   => $site->registered,
				'last_updated' => $site->last_updated,
				'public'       => (bool) $site->public,
				'archived'     => (bool) $site->archived,
				'deleted'      => (bool) $site->deleted,
				'theme'        => $theme->get( 'Name' ),
				'active_plugins' => $plugin_count,
				'posts'        => $post_count,
				'pages'        => $page_count,
			);

			restore_current_blog();
		}

		return array(
			'is_multisite'  => true,
			'network_name'  => get_network()->site_name,
			'total_sites'   => $total,
			'showing'       => count( $result ),
			'offset'        => $offset,
			'sites'         => $result,
		);
	}
}
