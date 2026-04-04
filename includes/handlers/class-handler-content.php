<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Content domain action handlers.
 *
 * Handles: read_content, search_content, edit_content, update_meta,
 * create_post, delete_content, list_posts, get_random_content, generate_content,
 * rewrite_content, generate_bulk_meta, bulk_edit, find_and_replace,
 * export_report, search_knowledge, index_status, rebuild_index.
 *
 * @since 2.7.0
 */

class PressArk_Handler_Content extends PressArk_Handler_Base {

	/**
	 * Read a post/page with mode-aware content reading.
	 */
	public function read_content( array $params ): array {
		$post_id = absint( $params['post_id'] ?? $params['id'] ?? 0 );

		// Resolve from URL if provided.
		if ( empty( $post_id ) && ! empty( $params['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( $params['url'] ) );
			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => __( 'Could not resolve URL to a post ID. Try providing post_id directly.', 'pressark' ) );
			}
		}

		// Resolve from slug if provided.
		if ( empty( $post_id ) && ! empty( $params['slug'] ) ) {
			$post_type = sanitize_text_field( $params['post_type'] ?? 'page' );
			$found     = get_page_by_path(
				sanitize_text_field( $params['slug'] ),
				OBJECT,
				$post_type
			);
			if ( $found ) {
				$post_id = $found->ID;
			} else {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: WordPress post type, 2: requested slug */
						__( 'No %1$s found with slug "%2$s".', 'pressark' ),
						$post_type,
						$params['slug']
					),
				);
			}
		}

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid post ID.', 'pressark' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'pressark' ) );
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to read this post.', 'pressark' ) );
		}

		// v2.4.0: mode-aware content reading.
		// v5.4.0: expose summary/detail/raw aliases without breaking the
		// existing light/structured/full contract.
		$requested_mode = sanitize_text_field( $params['mode'] ?? 'light' );
		$mode_aliases   = array(
			'summary' => 'light',
			'detail'  => 'structured',
			'raw'     => 'full',
		);
		$mode          = $mode_aliases[ $requested_mode ] ?? $requested_mode;
		if ( ! in_array( $mode, array( 'light', 'structured', 'full' ), true ) ) {
			$requested_mode = 'light';
			$mode           = 'light';
		}

		$section    = sanitize_text_field( $params['section'] ?? '' );
		$paragraphs = absint( $params['paragraphs'] ?? 5 );

		$result = match ( $mode ) {
			'light'      => $this->read_content_light( $post ),
			'structured' => $this->read_content_structured( $post ),
			'full'       => $this->read_content_full( $post ),
		};

		if ( ! empty( $result['success'] ) && isset( $result['data'] ) && is_array( $result['data'] ) && $requested_mode !== $mode ) {
			$result['data']['mode']           = $requested_mode;
			$result['data']['_resolved_mode'] = $mode;
		}

		// Section trimming for full mode — reduces token usage on long content.
		if ( $result['success'] && 'full' === $mode && $section && isset( $result['data']['content'] ) ) {
			$html = $result['data']['content'];
			switch ( $section ) {
				case 'head':
					$result['data']['content'] = mb_substr( $html, 0, 3000 );
					$result['data']['_section'] = 'head (first 3000 chars)';
					break;
				case 'tail':
					$result['data']['content'] = mb_substr( $html, -3000 );
					$result['data']['_section'] = 'tail (last 3000 chars)';
					break;
				case 'first_n_paragraphs':
					$count = max( 1, min( $paragraphs, 20 ) );
					if ( preg_match_all( '/<p[^>]*>.*?<\/p>/si', $html, $m ) ) {
						$result['data']['content'] = implode( "\n", array_slice( $m[0], 0, $count ) );
						$result['data']['_section'] = sprintf( 'first %d paragraph(s)', $count );
					}
					break;
			}
		}

		return $result;
	}

	/**
	 * Light mode: metadata only, no raw content. ~200 tokens.
	 *
	 * @since 2.4.0
	 */
	private function read_content_light( WP_Post $post ): array {
		$post_id = $post->ID;

		// SEO meta.
		$seo_title = PressArk_SEO_Resolver::read( $post_id, 'meta_title' );
		$seo_desc  = PressArk_SEO_Resolver::read( $post_id, 'meta_description' );

		// Word count from stripped content.
		$content    = wp_strip_all_tags( $post->post_content );
		$word_count = str_word_count( $content );

		// Readability estimate.
		$sentences   = max( 1, preg_match_all( '/[.!?]/', $content, $m ) );
		$words       = max( 1, $word_count );
		$syllables   = max( 1, (int) ( $words * 1.5 ) );
		$fk_grade    = round( 0.39 * ( $words / $sentences ) + 11.8 * ( $syllables / $words ) - 15.59 );
		$readability = $fk_grade <= 6 ? 'very easy' : ( $fk_grade <= 10 ? 'easy' : ( $fk_grade <= 14 ? 'moderate' : 'difficult' ) );

		// Excerpt: first 300 chars of content (or actual excerpt).
		$excerpt = $post->post_excerpt;
		if ( empty( $excerpt ) ) {
			$excerpt = mb_substr( $content, 0, 300 );
			if ( mb_strlen( $content ) > 300 ) {
				$excerpt .= '...';
			}
		}

		// Index status.
		global $wpdb;
		$indexed = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}pressark_content_index WHERE post_id = %d",
			$post_id
		) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: post title */
				__( 'Read metadata of "%s" (light mode).', 'pressark' ),
				$post->post_title
			),
			'data'    => array(
				'id'            => $post_id,
				'title'         => $post->post_title,
				'type'          => $post->post_type,
				'status'        => $post->post_status,
				'url'           => get_permalink( $post_id ),
				'excerpt'       => $excerpt,
				'word_count'    => $word_count,
				'reading_level' => $readability,
				'last_modified' => human_time_diff( strtotime( $post->post_modified ) ) . ' ago',
				'seo'           => array(
					'title_ok' => strlen( $seo_title ) >= 30 && strlen( $seo_title ) <= 60,
					'desc_ok'  => strlen( $seo_desc ) >= 120 && strlen( $seo_desc ) <= 160,
				),
				'indexed'       => $indexed,
				'flags'         => array_values( array_filter( array(
					$word_count < 300          ? 'thin_content: under 300 words'      : null,
					! $seo_title               ? 'missing_seo_title'                   : null,
					! $seo_desc                ? 'missing_seo_description'             : null,
					strlen( $seo_title ) > 60  ? 'seo_title_too_long: over 60 chars'  : null,
					strlen( $seo_desc )  > 160 ? 'seo_desc_too_long: over 160 chars'  : null,
				) ) ),
				'mode'          => 'light',
			),
		);
	}

	/**
	 * Structured mode: light + heading outline + section summaries + internal links. ~500 tokens.
	 *
	 * @since 2.4.0
	 */
	private function read_content_structured( WP_Post $post ): array {
		// Start with light data.
		$light = $this->read_content_light( $post );
		if ( ! $light['success'] ) {
			return $light;
		}

		$data         = $light['data'];
		$data['mode'] = 'structured';

		// Extract headings from raw HTML.
		$headings = array();
		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$headings[] = array(
					'level' => (int) $match[1],
					'text'  => wp_strip_all_tags( $match[2] ),
				);
			}
		}
		$data['headings'] = $headings;

		// Extract first sentence per section (split on headings).
		$sections          = preg_split( '/<h[1-6][^>]*>/i', $post->post_content );
		$section_summaries = array();
		foreach ( $sections as $section ) {
			$clean = wp_strip_all_tags( $section );
			$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );
			if ( empty( $clean ) ) {
				continue;
			}
			// First sentence (up to first period, exclamation, or question mark).
			if ( preg_match( '/^(.+?[.!?])\s/', $clean, $sm ) ) {
				$section_summaries[] = mb_substr( $sm[1], 0, 200 );
			} else {
				$section_summaries[] = mb_substr( $clean, 0, 200 );
			}
		}
		$data['section_summaries'] = $section_summaries;

		// Internal links.
		$data['internal_links'] = substr_count( $post->post_content, home_url() );

		// Full SEO data (upgrade from light booleans).
		$seo_title = PressArk_SEO_Resolver::read( $post->ID, 'meta_title' );
		$seo_desc  = PressArk_SEO_Resolver::read( $post->ID, 'meta_description' );

		$data['seo'] = array(
			'title'        => $seo_title ?: '(not set)',
			'description'  => $seo_desc  ?: '(not set)',
			'title_length' => strlen( $seo_title ),
			'desc_length'  => strlen( $seo_desc ),
			'title_ok'     => strlen( $seo_title ) >= 30 && strlen( $seo_title ) <= 60,
			'desc_ok'      => strlen( $seo_desc ) >= 120 && strlen( $seo_desc ) <= 160,
		);

		$light['data']    = $data;
		$light['message'] = sprintf(
			/* translators: %s: post title */
			__( 'Read outline of "%s" (structured mode).', 'pressark' ),
			$post->post_title
		);

		return $light;
	}

	/**
	 * Full mode: current behavior with raw HTML content included. Unlimited tokens.
	 *
	 * @since 2.4.0
	 */
	private function read_content_full( WP_Post $post ): array {
		$post_id = $post->ID;

		// SEO meta.
		$seo_title = PressArk_SEO_Resolver::read( $post_id, 'meta_title' );
		$seo_desc  = PressArk_SEO_Resolver::read( $post_id, 'meta_description' );

		// Content signals.
		$content        = wp_strip_all_tags( $post->post_content );
		$word_count     = str_word_count( $content );
		$internal_links = substr_count( $post->post_content, home_url() );

		// Readability estimate (Flesch-Kincaid approximation).
		$sentences  = max( 1, preg_match_all( '/[.!?]/', $content, $m ) );
		$words      = max( 1, $word_count );
		$syllables  = max( 1, (int) ( $words * 1.5 ) );
		$fk_grade   = round( 0.39 * ( $words / $sentences ) + 11.8 * ( $syllables / $words ) - 15.59 );
		$readability = $fk_grade <= 6 ? 'very easy' : ( $fk_grade <= 10 ? 'easy' : ( $fk_grade <= 14 ? 'moderate' : 'difficult' ) );

		// Index status.
		global $wpdb;
		$indexed = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}pressark_content_index WHERE post_id = %d",
			$post_id
		) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: post title */
				__( 'Read content of "%s" (full mode).', 'pressark' ),
				$post->post_title
			),
			'data'    => array(
				'id'             => $post_id,
				'title'          => $post->post_title,
				'type'           => $post->post_type,
				'status'         => $post->post_status,
				'url'            => get_permalink( $post_id ),
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'word_count'     => $word_count,
				'reading_level'  => $readability,
				'internal_links' => $internal_links,
				'last_modified'  => human_time_diff( strtotime( $post->post_modified ) ) . ' ago',
				'seo' => array(
					'title'        => $seo_title ?: '(not set)',
					'description'  => $seo_desc  ?: '(not set)',
					'title_length' => strlen( $seo_title ),
					'desc_length'  => strlen( $seo_desc ),
					'title_ok'     => strlen( $seo_title ) >= 30 && strlen( $seo_title ) <= 60,
					'desc_ok'      => strlen( $seo_desc ) >= 120 && strlen( $seo_desc ) <= 160,
				),
				'sticky'         => is_sticky( $post_id ),
				'post_format'    => get_post_format( $post_id ) ?: 'standard',
				'indexed'        => $indexed,
				'uses_gutenberg' => has_blocks( $post->post_content ),
				'uses_elementor' => ! empty( get_post_meta( $post_id, '_elementor_data', true ) ),
				'flags'          => array_values( array_filter( array(
					$word_count < 300          ? 'thin_content: under 300 words'      : null,
					! $seo_title               ? 'missing_seo_title'                   : null,
					! $seo_desc                ? 'missing_seo_description'             : null,
					strlen( $seo_title ) > 60  ? 'seo_title_too_long: over 60 chars'  : null,
					strlen( $seo_desc )  > 160 ? 'seo_desc_too_long: over 160 chars'  : null,
					$internal_links === 0      ? 'no_internal_links'                   : null,
				) ) ),
				'mode'           => 'full',
			),
		);
	}

	/**
	 * Search posts/pages by keyword.
	 */
	public function search_content( array $params ): array {
		$query     = sanitize_text_field( $params['query'] ?? '' );
		$post_type = sanitize_text_field( $params['post_type'] ?? 'any' );
		$limit     = min( (int) ( $params['limit'] ?? 20 ), 100 );
		$offset    = absint( $params['offset'] ?? 0 );

		if ( empty( $query ) ) {
			return array( 'success' => false, 'message' => __( 'Search query is required.', 'pressark' ) );
		}

		$args = array(
			's'              => $query,
			'post_type'      => $post_type ?: 'any',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'relevance',
		);

		// Date filtering: after/before accept any strtotime-compatible string.
		$date_query = array();
		if ( ! empty( $params['after'] ) ) {
			$date_query['after'] = sanitize_text_field( $params['after'] );
		}
		if ( ! empty( $params['before'] ) ) {
			$date_query['before'] = sanitize_text_field( $params['before'] );
		}
		if ( ! empty( $date_query ) ) {
			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		// Meta filtering: find posts with/without a specific meta key or value.
		if ( ! empty( $params['meta_key'] ) ) {
			$compare = strtoupper( $params['meta_compare'] ?? 'EXISTS' );
			$allowed = array( '=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE',
			                  'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS', 'BETWEEN' );
			if ( ! in_array( $compare, $allowed, true ) ) {
				$compare = 'EXISTS';
			}
			$meta_clause = array(
				'key'     => sanitize_key( $params['meta_key'] ),
				'compare' => $compare,
			);
			if ( ! in_array( $compare, array( 'EXISTS', 'NOT EXISTS' ), true ) ) {
				$meta_clause['value'] = $params['meta_value'] ?? '';
			}
			$args['meta_query'] = array( $meta_clause );
		}

		$wp_query = new WP_Query( $args );
		$posts    = $wp_query->posts;

		$list = array();
		foreach ( $posts as $p ) {
			$excerpt = wp_strip_all_tags( $p->post_content );
			$pos     = mb_stripos( $excerpt, $query );
			$snippet = '';
			if ( false !== $pos ) {
				$start   = max( 0, $pos - 40 );
				$snippet = mb_substr( $excerpt, $start, 120 );
				if ( $start > 0 ) {
					$snippet = '...' . $snippet;
				}
				if ( mb_strlen( $excerpt ) > $start + 120 ) {
					$snippet .= '...';
				}
			} else {
				$snippet = mb_substr( $excerpt, 0, 100 ) . ( mb_strlen( $excerpt ) > 100 ? '...' : '' );
			}

			$list[] = array(
				'id'      => $p->ID,
				'title'   => $p->post_title,
				'type'    => $p->post_type,
				'status'  => $p->post_status,
				'snippet' => $snippet,
			);
		}

		return array(
			'success'     => true,
			'data'        => $list,
			'_pagination' => array(
				'total'    => $wp_query->found_posts,
				'offset'   => $offset,
				'limit'    => $limit,
				'has_more' => ( $offset + $limit ) < $wp_query->found_posts,
			),
			'message'     => sprintf(
				/* translators: 1: total found 2: search query 3: optional "showing first N" */
				__( 'Found %1$d result(s) for "%2$s"%3$s.', 'pressark' ),
				$wp_query->found_posts,
				$query,
				$wp_query->found_posts > count( $list )
					? sprintf(
						/* translators: %d: number of results shown */
						__( ', showing %d', 'pressark' ),
						count( $list )
					)
					: ''
			),
		);
	}

	/**
	 * Edit a post/page content.
	 */
	public function edit_content( array $params ): array {
		$post_id = absint( $params['post_id'] ?? ( $params['changes']['post_id'] ?? 0 ) );

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

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid post ID.', 'pressark' ) );
		}

		// Defense-in-depth: WC product rerouting.
		// Primary guard is now PressArk_Preflight::rule_wc_product_content_edit (v5.5.0).
		if ( get_post_type( $post_id ) === 'product' ) {
			return $this->edit_product( $params );
		}
		if ( get_post_type( $post_id ) === 'product_variation' ) {
			return $this->edit_variation( $params );
		}

		// Defense-in-depth: Elementor content guard.
		// Primary guard is now PressArk_Preflight::rule_elementor_content_edit (v5.5.0).
		$changes = $params['changes'] ?? $params;
		if ( ! empty( get_post_meta( $post_id, '_elementor_data', true ) ) && isset( $changes['content'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'This page uses Elementor. Use elementor_edit_widget to modify widget content, or elementor_find_replace for text changes. Editing post_content directly has no visible effect on Elementor pages.', 'pressark' ),
				'hint'    => __( 'Read the page first with read_content(mode=structured) to see the Elementor widget tree.', 'pressark' ),
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this post.', 'pressark' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'pressark' ) );
		}

		// Create a pre-edit revision checkpoint for undo support.
		$pre_rev_id = $this->create_checkpoint( $post_id, 'edit_content' );

		$old_value = array(
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => $post->post_excerpt,
			'revision_id' => $pre_rev_id,
		);

		$update_args  = array( 'ID' => $post_id );
		$changed_list = array();

		if ( isset( $changes['content'] ) ) {
			$update_args['post_content'] = wp_kses_post( $changes['content'] );
			$changed_list[] = __( 'content', 'pressark' );
		}

		if ( isset( $changes['title'] ) ) {
			$update_args['post_title'] = sanitize_text_field( $changes['title'] );
			$changed_list[] = __( 'title', 'pressark' );
		}

		if ( isset( $changes['excerpt'] ) ) {
			$update_args['post_excerpt'] = sanitize_textarea_field( $changes['excerpt'] );
			$changed_list[] = __( 'excerpt', 'pressark' );
		}

		if ( isset( $changes['slug'] ) ) {
			$update_args['post_name'] = sanitize_title( $changes['slug'] );
			$changed_list[] = __( 'slug', 'pressark' );
		}

		if ( isset( $changes['status'] ) && in_array( $changes['status'], array( 'publish', 'draft', 'private', 'pending', 'future' ), true ) ) {
			$update_args['post_status'] = $changes['status'];
			$changed_list[] = __( 'status', 'pressark' );

			if ( 'future' === $changes['status'] && ! empty( $changes['scheduled_date'] ) ) {
				$update_args['post_date']     = sanitize_text_field( $changes['scheduled_date'] );
				$update_args['post_date_gmt'] = get_gmt_from_date( $changes['scheduled_date'] );
				$changed_list[] = __( 'scheduled date', 'pressark' );
			}
		}

		// Sticky toggle — handled outside wp_update_post.
		$sticky_changed = false;
		if ( isset( $changes['sticky'] ) ) {
			if ( $changes['sticky'] ) {
				stick_post( $post_id );
			} else {
				unstick_post( $post_id );
			}
			$changed_list[] = __( 'sticky', 'pressark' );
			$sticky_changed = true;
		}

		// Post format — handled outside wp_update_post.
		$format_changed = false;
		if ( isset( $changes['post_format'] ) ) {
			$format = sanitize_text_field( $changes['post_format'] );
			if ( $format === 'standard' ) {
				$format = '';
			}
			set_post_format( $post_id, $format );
			$changed_list[] = __( 'post format', 'pressark' );
			$format_changed = true;
		}

		if ( count( $update_args ) <= 1 && ! $sticky_changed && ! $format_changed ) {
			return array( 'success' => false, 'message' => __( 'No changes specified.', 'pressark' ) );
		}

		$new_value = array(
			'title'   => $update_args['post_title'] ?? $post->post_title,
			'content' => $update_args['post_content'] ?? $post->post_content,
		);

		// Only call wp_update_post if there are core field changes.
		if ( count( $update_args ) <= 1 ) {
			// Only sticky/format changed — no core fields to update.
			$result = $post_id;
		} else {
			$result = wp_update_post( wp_slash( $update_args ), true );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to update post: %s', 'pressark' ),
					$result->get_error_message()
				),
			);
		}

		$log_id = $this->logger->log(
			'edit_content',
			$post_id,
			$post->post_type,
			wp_json_encode( $old_value ),
			wp_json_encode( $new_value )
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: post title 2: changed fields */
				__( 'Updated "%1$s" — changed: %2$s.', 'pressark' ),
				$post->post_title,
				implode( ', ', $changed_list )
			),
			'log_id'  => $log_id,
		);
	}

	/**
	 * Update post meta. Supports multiple input formats:
	 * - Single key/value: {post_id, meta_key, meta_value}
	 * - Object of changes: {post_id, changes: {meta_title: "...", meta_description: "..."}}
	 * - Meta object: {post_id, meta: {meta_title: "...", meta_description: "..."}}
	 */
	public function update_meta( array $params ): array {
		$post_id = absint( $params['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid post ID.', 'pressark' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this post.', 'pressark' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'pressark' ) );
		}

		// ── WC Product Guard (defense-in-depth) ─────────────────────────
		// Primary guard is now PressArk_Preflight::rule_wc_guarded_meta (v5.5.0).
		// Writing these keys via raw update_post_meta() on products bypasses
		// WC's lookup table, transient busting, stock hooks, and price sync.
		// Route through edit_product instead.
		$post_type = get_post_type( $post_id );
		if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			$wc_guarded_keys = array(
				'_price', '_regular_price', '_sale_price',
				'_sale_price_dates_from', '_sale_price_dates_to',
				'_sku', '_stock', '_stock_status', '_manage_stock',
				'_backorders', '_low_stock_amount',
				'_weight', '_length', '_width', '_height',
				'_virtual', '_downloadable', '_featured',
				'_tax_status', '_tax_class', '_shipping_class_id',
				'total_sales',
			);

			// Collect all keys being written to check against guard list.
			$keys_to_check = array();
			$bulk = $params['changes'] ?? ( $params['meta'] ?? null );
			if ( is_array( $bulk ) ) {
				foreach ( $bulk as $k => $v ) {
					if ( 'post_id' !== $k ) {
						$keys_to_check[] = $this->resolve_meta_key( sanitize_text_field( $k ) );
					}
				}
			}
			if ( empty( $keys_to_check ) ) {
				$single_key = $params['meta_key'] ?? ( $params['key'] ?? '' );
				if ( ! empty( $single_key ) ) {
					$keys_to_check[] = $this->resolve_meta_key( sanitize_text_field( $single_key ) );
				}
			}

			foreach ( $keys_to_check as $resolved_key ) {
				if ( in_array( $resolved_key, $wc_guarded_keys, true ) ) {
					return array(
						'success' => false,
						'message' => sprintf(
							/* translators: %s: meta key name */
							__( 'Cannot update "%s" via raw meta on a WooCommerce product. Use edit_product instead — it keeps WC price lookup tables, stock caches, and inventory hooks in sync.', 'pressark' ),
							$resolved_key
						),
						'hint'    => __( 'edit_product supports: name, regular_price, sale_price, sku, stock_quantity, stock_status, manage_stock, weight, and more.', 'pressark' ),
					);
				}
			}
		}

		// Build a key => value map from the various input formats.
		$meta_updates = array();

		// Format 1: {changes: {key: value, ...}} or {meta: {key: value, ...}}.
		$bulk = $params['changes'] ?? ( $params['meta'] ?? null );
		if ( is_array( $bulk ) ) {
			foreach ( $bulk as $k => $v ) {
				if ( 'post_id' === $k ) {
					continue;
				}
				$meta_updates[ $k ] = $v;
			}
		}

		// Format 2: Single key/value.
		if ( empty( $meta_updates ) ) {
			$key   = $params['meta_key'] ?? ( $params['key'] ?? '' );
			$value = $params['meta_value'] ?? ( $params['value'] ?? '' );
			if ( ! empty( $key ) ) {
				$meta_updates[ $key ] = $value;
			}
		}

		if ( empty( $meta_updates ) ) {
			return array( 'success' => false, 'message' => __( 'No meta key/value provided.', 'pressark' ) );
		}

		$updated_keys = array();
		$last_log_id  = null;

		foreach ( $meta_updates as $raw_key => $new_value ) {
			$resolved_key = $this->resolve_meta_key( sanitize_text_field( $raw_key ) );
			$new_value    = sanitize_text_field( $new_value );
			$old_value    = get_post_meta( $post_id, $resolved_key, true );

			$last_log_id = $this->logger->log(
				'update_meta',
				$post_id,
				$post->post_type,
				wp_json_encode( array( 'key' => $resolved_key, 'value' => $old_value ) ),
				wp_json_encode( array( 'key' => $resolved_key, 'value' => $new_value ) )
			);

			update_post_meta( $post_id, $resolved_key, $new_value );
			$updated_keys[] = $resolved_key;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: meta keys 2: post title */
				__( 'Updated %1$s on "%2$s".', 'pressark' ),
				implode( ', ', $updated_keys ),
				$post->post_title
			),
			'log_id'  => $last_log_id,
		);
	}

	/**
	 * Create a new post or page.
	 */
	public function create_post( array $params ): array {
		$post_type = sanitize_text_field( $params['post_type'] ?? 'post' );
		$title     = sanitize_text_field( $params['title'] ?? '' );
		$content   = wp_kses_post( $params['content'] ?? '' );
		$status    = sanitize_text_field( $params['status'] ?? 'draft' );

		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid post type. Use "post" or "page".', 'pressark' ) );
		}

		if ( ! in_array( $status, array( 'draft', 'publish', 'future' ), true ) ) {
			$status = 'draft';
		}

		if ( empty( $title ) ) {
			return array( 'success' => false, 'message' => __( 'Title is required.', 'pressark' ) );
		}

		$cap = 'post' === $post_type ? 'publish_posts' : 'publish_pages';
		if ( ! current_user_can( $cap ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to create this content.', 'pressark' ) );
		}

		$insert_args = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_author'  => get_current_user_id(),
		);

		// Optional slug.
		if ( ! empty( $params['slug'] ) ) {
			$insert_args['post_name'] = sanitize_title( $params['slug'] );
		}

		// Optional excerpt.
		if ( ! empty( $params['excerpt'] ) ) {
			$insert_args['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
		}

		// Handle scheduled date for future posts.
		if ( 'future' === $status ) {
			$scheduled = $params['scheduled_date'] ?? '';
			if ( ! empty( $scheduled ) ) {
				$insert_args['post_date']     = sanitize_text_field( $scheduled );
				$insert_args['post_date_gmt'] = get_gmt_from_date( $scheduled );
			} else {
				// No date provided — default to draft.
				$insert_args['post_status'] = 'draft';
				$status = 'draft';
			}
		}

		$post_id = wp_insert_post( wp_slash( $insert_args ), true );

		if ( is_wp_error( $post_id ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to create post: %s', 'pressark' ),
					$post_id->get_error_message()
				),
			);
		}

		// Set page template if specified.
		if ( 'page' === $post_type && ! empty( $params['page_template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $params['page_template'] ) );
		}

		// Set SEO/social meta if provided.
		foreach ( array( 'meta_title', 'meta_description', 'og_title', 'og_description', 'focus_keyword' ) as $seo_key ) {
			if ( ! empty( $params[ $seo_key ] ) ) {
				PressArk_SEO_Resolver::write(
					(int) $post_id,
					$seo_key,
					sanitize_text_field( (string) $params[ $seo_key ] )
				);
			}
		}
		if ( ! empty( $params['og_image'] ) ) {
			PressArk_SEO_Resolver::write(
				(int) $post_id,
				'og_image',
				esc_url_raw( (string) $params['og_image'] )
			);
		}

		$log_id = $this->logger->log(
			'create_post',
			$post_id,
			$post_type,
			null,
			wp_json_encode( array( 'title' => $title, 'status' => $status ) )
		);

		$edit_link = get_edit_post_link( $post_id, 'raw' );

		$return = array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: post type 2: post title 3: status 4: edit URL */
				__( 'Created %1$s "%2$s" as %3$s. Edit: %4$s', 'pressark' ),
				$post_type,
				$title,
				$status,
				$edit_link
			),
			'log_id'  => $log_id,
			'post_id' => (int) $post_id,
			'post_title' => $title,
			'post_type'  => $post_type,
			'post_status'=> $status,
		);

		$preview_link = 'publish' === $status
			? (string) get_permalink( $post_id )
			: (string) get_preview_post_link( $post_id );
		if ( empty( $preview_link ) ) {
			$preview_link = (string) $edit_link;
		}
		$return['url'] = $preview_link;

		// Show available page templates when creating pages.
		if ( 'page' === $post_type ) {
			$templates = wp_get_theme()->get_page_templates( null, 'page' );
			if ( ! empty( $templates ) ) {
				$return['available_templates'] = array_map(
					fn( $filename, $name ) => array( 'file' => $filename, 'name' => $name ),
					array_keys( $templates ),
					array_values( $templates )
				);
				$return['template_hint'] = __( 'Set page_template to one of the available template filenames if needed.', 'pressark' );
			}
		}

		return $return;
	}

	/**
	 * Move a post/page to trash.
	 */
	public function delete_content( array $params ): array {
		$post_id = absint( $params['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid post ID.', 'pressark' ) );
		}

		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to delete this post.', 'pressark' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'pressark' ) );
		}

		$title  = $post->post_title;
		$log_id = $this->logger->log(
			'delete_content',
			$post_id,
			$post->post_type,
			wp_json_encode( array( 'title' => $title, 'status' => $post->post_status ) ),
			null
		);

		$result = wp_trash_post( $post_id );

		if ( ! $result ) {
			return array( 'success' => false, 'message' => __( 'Failed to move post to trash.', 'pressark' ) );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: post title */
				__( 'Moved "%s" to trash.', 'pressark' ),
				$title
			),
			'log_id'  => $log_id,
		);
	}

	/**
	 * Move multiple posts/pages to trash at once.
	 */
	public function bulk_delete( array $params ): array {
		$post_ids = $params['post_ids'] ?? array();

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return array( 'success' => false, 'message' => __( 'Post IDs array is required.', 'pressark' ) );
		}

		// Cap to prevent timeouts.
		$post_ids = array_slice( $post_ids, 0, 50 );

		$trashed       = 0;
		$already_trash = 0;
		$errors        = array();

		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $post_ids as $pid ) {
			$pid  = absint( $pid );
			$post = get_post( $pid );

			if ( ! $post ) {
				$errors[] = sprintf(
					/* translators: %d: post ID */
					__( 'Post #%d not found', 'pressark' ),
					$pid
				);
				continue;
			}

			if ( ! current_user_can( 'delete_post', $pid ) ) {
				$errors[] = sprintf(
					/* translators: %s: post title */
					__( '"%s": no permission', 'pressark' ),
					$post->post_title
				);
				continue;
			}

			if ( 'trash' === $post->post_status ) {
				$already_trash++;
				continue;
			}

			$this->logger->log(
				'delete_content',
				$pid,
				$post->post_type,
				wp_json_encode( array( 'title' => $post->post_title, 'status' => $post->post_status ) ),
				null
			);

			$result = wp_trash_post( $pid );
			if ( $result ) {
				$trashed++;
			} else {
				$errors[] = sprintf(
					/* translators: %s: post title */
					__( '"%s": trash failed', 'pressark' ),
					$post->post_title
				);
			}
		}

		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
		} else {
			wp_cache_flush();
		}

		$message = sprintf(
			/* translators: 1: trashed count 2: total count */
			__( 'Moved %1$d of %2$d items to trash.', 'pressark' ),
			$trashed,
			count( $post_ids )
		);
		if ( $already_trash > 0 ) {
			$message .= sprintf(
				/* translators: %d: count of already-trashed items */
				' ' . __( '%d items were already in trash — use empty_trash to permanently delete them.', 'pressark' ),
				$already_trash
			);
		}
		if ( ! empty( $errors ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: semicolon-separated list of error messages. */
				__( 'Errors: %s', 'pressark' ),
				implode( '; ', $errors )
			);
		}

		return array( 'success' => $trashed > 0 || $already_trash > 0, 'message' => $message );
	}

	/**
	 * Permanently delete posts/pages from trash.
	 */
	public function empty_trash( array $params ): array {
		$post_type = sanitize_text_field( $params['post_type'] ?? 'any' );
		$post_ids  = $params['post_ids'] ?? array();

		// If specific IDs given, use those. Otherwise query all trashed posts.
		if ( ! empty( $post_ids ) && is_array( $post_ids ) ) {
			$post_ids = array_map( 'absint', $post_ids );
		} else {
			$query_types = 'any' === $post_type
				? array( 'post', 'page' )
				: array( $post_type );

			$trashed = get_posts( array(
				'post_type'      => $query_types,
				'post_status'    => 'trash',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			$post_ids = $trashed;
		}

		if ( empty( $post_ids ) ) {
			return array( 'success' => true, 'message' => __( 'Trash is already empty.', 'pressark' ) );
		}

		// Cap to prevent timeouts.
		$post_ids = array_slice( $post_ids, 0, 100 );

		$deleted = 0;
		$skipped = 0;
		$errors  = array();

		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );

			if ( ! $post ) {
				$errors[] = sprintf(
					/* translators: %d: WordPress post ID. */
					__( 'Post #%d not found', 'pressark' ),
					$pid
				);
				continue;
			}

			if ( ! current_user_can( 'delete_post', $pid ) ) {
				$errors[] = sprintf(
					/* translators: %s: WordPress post title. */
					__( '"%s": no permission', 'pressark' ),
					$post->post_title
				);
				continue;
			}

			// Only permanently delete posts that are in trash.
			if ( 'trash' !== $post->post_status ) {
				$skipped++;
				continue;
			}

			$this->logger->log(
				'empty_trash',
				$pid,
				$post->post_type,
				wp_json_encode( array( 'title' => $post->post_title ) ),
				null
			);

			$result = wp_delete_post( $pid, true );
			if ( $result ) {
				$deleted++;
			} else {
				$errors[] = sprintf(
					/* translators: %s: post title */
					__( '"%s": delete failed', 'pressark' ),
					$post->post_title
				);
			}
		}

		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
		} else {
			wp_cache_flush();
		}

		$message = sprintf(
			/* translators: 1: deleted count 2: total count */
			__( 'Permanently deleted %1$d of %2$d items from trash.', 'pressark' ),
			$deleted,
			count( $post_ids )
		);
		if ( $skipped > 0 ) {
			$message .= sprintf(
				/* translators: %d: count of skipped non-trash items */
				' ' . __( '%d items were not in trash and were skipped.', 'pressark' ),
				$skipped
			);
		}
		if ( ! empty( $errors ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: semicolon-separated list of error messages. */
				__( 'Errors: %s', 'pressark' ),
				implode( '; ', $errors )
			);
		}

		return array( 'success' => $deleted > 0, 'message' => $message );
	}

	/**
	 * List posts/pages with filters.
	 */
	public function list_posts( array $params ): array {
		$post_type   = sanitize_text_field( $params['post_type'] ?? 'any' );
		$post_status = sanitize_text_field( $params['status'] ?? 'any' );
		$count       = absint( $params['count'] ?? 20 );
		$count       = min( $count, 50 );
		$offset      = absint( $params['offset'] ?? 0 );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $count,
			'offset'         => $offset,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'perm'           => 'readable',
		);

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		// B2: needs_seo filter — posts missing SEO title.
		if ( ! empty( $params['needs_seo'] ) ) {
			$title_key = PressArk_SEO_Resolver::resolve_key( 'meta_title' );
			$args['meta_query'] = array(
				array(
					'key'     => $title_key,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		// B2: modified_after filter.
		if ( ! empty( $params['modified_after'] ) ) {
			$args['date_query'] = array(
				array(
					'after'    => sanitize_text_field( $params['modified_after'] ),
					'column'   => 'post_modified',
					'inclusive' => true,
				),
			);
		}

		$wp_query = new WP_Query( $args );
		$posts    = $wp_query->posts;
		$list     = array();

		foreach ( $posts as $p ) {
			$content    = wp_strip_all_tags( $p->post_content );
			$word_count = str_word_count( $content );

			// B2: min_words / max_words post-filters.
			if ( ! empty( $params['min_words'] ) && $word_count < (int) $params['min_words'] ) {
				continue;
			}
			if ( ! empty( $params['max_words'] ) && $word_count > (int) $params['max_words'] ) {
				continue;
			}

			$entry = array(
				'id'         => $p->ID,
				'title'      => $p->post_title,
				'type'       => $p->post_type,
				'status'     => $p->post_status,
				'slug'       => $p->post_name,
				'modified'   => $p->post_modified,
				'word_count' => $word_count,
			);

			if ( is_sticky( $p->ID ) ) {
				$entry['sticky'] = true;
			}

			$format = get_post_format( $p->ID );
			if ( $format ) {
				$entry['post_format'] = $format;
			}

			// C2: Product-specific flags.
			if ( $p->post_type === 'product' && class_exists( 'WooCommerce' ) ) {
				$flags = array();
				if ( empty( $p->post_content ) ) {
					$flags[] = 'no_description';
				}
				if ( ! has_post_thumbnail( $p->ID ) ) {
					$flags[] = 'no_image';
				}
				$stock_status = get_post_meta( $p->ID, '_stock_status', true );
				if ( $stock_status === 'outofstock' ) {
					$flags[] = 'out_of_stock';
				}
				$manage_stock = get_post_meta( $p->ID, '_manage_stock', true );
				if ( $manage_stock === 'yes' ) {
					$stock = (int) get_post_meta( $p->ID, '_stock', true );
					if ( $stock < 5 && $stock_status !== 'outofstock' ) {
						$flags[] = 'low_stock: ' . $stock . ' remaining';
					}
				}
				if ( ! empty( $flags ) ) {
					$entry['flags'] = $flags;
				}
			}

			$list[] = $entry;
		}

		$return = array(
			'success'     => true,
			'data'        => $list,
			'_pagination' => array(
				'total'    => $wp_query->found_posts,
				'offset'   => $offset,
				'limit'    => $count,
				'has_more' => ( $offset + $count ) < $wp_query->found_posts,
			),
			'message'     => sprintf(
				/* translators: 1: total post count 2: optional "showing N" */
				__( 'Found %1$d post(s)%2$s.', 'pressark' ),
				$wp_query->found_posts,
				$wp_query->found_posts > count( $list )
					? sprintf(
						/* translators: %d: number of posts included in the current response. */
						__( ', showing %d', 'pressark' ),
						count( $list )
					)
					: ''
			),
		);

		// When no specific status filter, expose all registered statuses so the AI knows what options exist.
		if ( empty( $params['status'] ) || $params['status'] === 'any' ) {
			$return['available_statuses'] = $this->get_available_post_statuses();
		}

		return $return;
	}

	/**
	 * Pick one random post/page/product matching filters.
	 *
	 * @since 4.5.0
	 */
	public function get_random_content( array $params ): array {
		$post_type = sanitize_text_field( $params['post_type'] ?? 'any' );
		$status    = sanitize_text_field( $params['status'] ?? 'publish' );
		$mode      = sanitize_text_field( $params['mode'] ?? 'light' );

		if ( ! in_array( $mode, array( 'light', 'structured' ), true ) ) {
			$mode = 'light';
		}

		// Whitelist post_type to prevent querying internal types (revisions, attachments, nav items).
		$allowed_types = array( 'any', 'post', 'page', 'product' );
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'any';
		}

		// Whitelist status to prevent querying trash, auto-draft, inherit, etc.
		if ( ! in_array( $status, array( 'publish', 'draft', 'private', 'any' ), true ) ) {
			$status = 'publish';
		}

		// Guard: product type requires WooCommerce.
		if ( $post_type === 'product' && ! class_exists( 'WooCommerce' ) ) {
			return array( 'success' => false, 'message' => __( 'WooCommerce is not active. Cannot fetch random product.', 'pressark' ) );
		}

		// Build query args — use SQL RAND() to pick at the DB level.
		$args = array(
			'post_type'      => $post_type === 'any' ? array( 'post', 'page' ) : $post_type,
			'post_status'    => $status === 'any' ? array( 'publish', 'draft', 'private' ) : $status,
			'posts_per_page' => 1,
			'orderby'        => 'rand',
			'perm'           => 'readable',
			'no_found_rows'  => true,
		);

		// Include products in 'any' when WooCommerce is active.
		if ( $post_type === 'any' && class_exists( 'WooCommerce' ) ) {
			$args['post_type'][] = 'product';
		}

		// Exclude IDs (cap at 50 to prevent bloated NOT IN queries).
		if ( ! empty( $params['exclude_ids'] ) ) {
			$exclude = is_array( $params['exclude_ids'] ) ? $params['exclude_ids'] : array( $params['exclude_ids'] );
			$args['post__not_in'] = array_map( 'absint', array_slice( $exclude, 0, 50 ) );
		}

		$query = new WP_Query( $args );

		if ( empty( $query->posts ) ) {
			return array( 'success' => false, 'message' => __( 'No matching content found for the given filters.', 'pressark' ) );
		}

		$post = $query->posts[0];

		// Delegate to existing read mode helpers.
		if ( $mode === 'structured' ) {
			$result = $this->read_content_structured( $post );
		} else {
			$result = $this->read_content_light( $post );
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		// Enrich with slug (not included by read helpers).
		$data         = $result['data'];
		$data['slug'] = $post->post_name;

		// Compact WooCommerce product block when applicable.
		if ( $post->post_type === 'product' && class_exists( 'WooCommerce' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$category_names = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
				if ( is_wp_error( $category_names ) ) {
					$category_names = array();
				}

				$tag_names = wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) );
				if ( is_wp_error( $tag_names ) ) {
					$tag_names = array();
				}

				$image_url = '';
				$image_id  = $product->get_image_id();
				if ( $image_id ) {
					$image_url = (string) wp_get_attachment_url( $image_id );
				}

				$data['product'] = array(
					'sku'               => $product->get_sku(),
					'price'             => $product->get_price(),
					'regular_price'     => $product->get_regular_price(),
					'sale_price'        => $product->get_sale_price(),
					'stock_status'      => $product->get_stock_status(),
					'stock_quantity'    => $product->get_stock_quantity(),
					'on_sale'           => $product->is_on_sale(),
					'short_description' => mb_substr( trim( wp_strip_all_tags( (string) $product->get_short_description() ) ), 0, 500 ),
					'description'       => mb_substr( trim( wp_strip_all_tags( (string) $product->get_description() ) ), 0, 800 ),
					'categories'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) $category_names ) ) ),
					'tags'              => array_values( array_filter( array_map( 'sanitize_text_field', (array) $tag_names ) ) ),
					'image'             => esc_url_raw( $image_url ),
				);
			}
		}

		$result['data']    = $data;
		$result['message'] = sprintf(
			/* translators: 1: post title, 2: mode */
			__( 'Random "%1$s" selected (%2$s mode).', 'pressark' ),
			$post->post_title,
			$mode
		);

		return $result;
	}

	/**
	 * Get all registered post statuses, excluding internal/system ones.
	 */
	private function get_available_post_statuses(): array {
		$all      = get_post_stati( array(), 'objects' );
		$statuses = array();
		$skip     = array( 'auto-draft', 'inherit' );

		foreach ( $all as $name => $status_obj ) {
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			if ( $status_obj->internal && ! in_array( $name, array( 'trash' ), true ) ) {
				continue;
			}

			$statuses[] = array(
				'name'      => $name,
				'label'     => $status_obj->label,
				'public'    => $status_obj->public,
				'protected' => $status_obj->protected,
			);
		}

		return $statuses;
	}

	/**
	 * Generate content — returns data for AI to generate.
	 */
	public function generate_content( array $params ): array {
		$type  = $params['type'] ?? 'custom';
		$topic = $params['topic'] ?? '';

		// AI sometimes puts topic in alternative fields — try common fallbacks.
		if ( empty( $topic ) ) {
			$topic = $params['title'] ?? $params['subject'] ?? $params['description'] ?? $params['prompt'] ?? '';
		}

		if ( empty( $topic ) ) {
			return array( 'success' => false, 'message' => __( 'Topic is required for content generation.', 'pressark' ) );
		}

		// If reference post, get its content.
		$reference_content = '';
		if ( ! empty( $params['reference_post_id'] ) ) {
			$ref_post = get_post( intval( $params['reference_post_id'] ) );
			if ( $ref_post ) {
				$reference_content = wp_strip_all_tags( $ref_post->post_content );
			}
		}

		return array(
			'success'  => true,
			'generate' => true,
			'message'  => __( 'Generating content...', 'pressark' ),
			'data'     => array(
				'type'                    => $type,
				'topic'                   => $topic,
				'tone'                    => $params['tone'] ?? 'professional',
				'length'                  => $params['length'] ?? 'medium',
				'keywords'                => $params['keywords'] ?? array(),
				'target_audience'         => $params['target_audience'] ?? '',
				'reference_content'       => $reference_content ? mb_substr( $reference_content, 0, 1000 ) : '',
				'additional_instructions' => $params['additional_instructions'] ?? '',
			),
		);
	}

	/**
	 * Rewrite existing content — returns current content for AI to rewrite.
	 */
	public function rewrite_content( array $params ): array {
		$post_id = intval( $params['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'success' => false, 'message' => __( 'Post not found.', 'pressark' ) );
		}

		// Checkpoint before AI rewrites the content.
		$this->create_checkpoint( $post_id, 'rewrite_content' );

		$current_content = $post->post_content;
		$word_count      = str_word_count( wp_strip_all_tags( $current_content ) );

		return array(
			'success'  => true,
			'generate' => true,
			'message'  => sprintf(
				/* translators: 1: post title 2: word count */
				__( 'Reading content from "%1$s" (%2$d words)...', 'pressark' ),
				$post->post_title,
				$word_count
			),
			'data'     => array(
				'post_id'            => $post_id,
				'post_title'         => $post->post_title,
				'current_content'    => $current_content,
				'instructions'       => $params['instructions'] ?? 'improve',
				'tone'               => $params['tone'] ?? '',
				'keywords'           => $params['keywords'] ?? array(),
				'preserve_structure' => $params['preserve_structure'] ?? true,
			),
		);
	}

	/**
	 * Generate bulk meta — returns page data for AI to generate meta tags.
	 */
	public function generate_bulk_meta( array $params ): array {
		$post_ids = $params['post_ids'] ?? array();
		$style    = $params['style'] ?? 'descriptive';

		// If no IDs specified, find all published pages/posts missing meta.
		if ( empty( $post_ids ) ) {
			$all_posts = get_posts( array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			) );

			foreach ( $all_posts as $p ) {
				if ( ! PressArk_SEO_Resolver::read( $p->ID, 'meta_title' ) ) {
					$post_ids[] = $p->ID;
				}
			}
		}

		if ( empty( $post_ids ) ) {
			return array( 'success' => true, 'message' => __( 'All published pages already have meta titles. Nothing to generate.', 'pressark' ) );
		}

		// Gather content summaries for each page.
		$pages_data = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( intval( $pid ) );
			if ( ! $post ) {
				continue;
			}
			$pages_data[] = array(
				'post_id'                  => $post->ID,
				'title'                    => $post->post_title,
				'content_preview'          => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 300 ),
				'current_meta_title'       => get_post_meta( $post->ID, '_pressark_meta_title', true ) ?: '(empty)',
				'current_meta_description' => get_post_meta( $post->ID, '_pressark_meta_description', true ) ?: '(empty)',
			);
		}

		return array(
			'success'  => true,
			'generate' => true,
			'message'  => sprintf(
				/* translators: %d: number of pages */
				__( '%d pages need meta tags.', 'pressark' ),
				count( $pages_data )
			),
			'data'     => array(
				'pages' => $pages_data,
				'style' => $style,
			),
		);
	}

	/**
	 * Bulk edit multiple posts/pages.
	 */
	public function bulk_edit( array $params ): array {
		$post_ids = $params['post_ids'] ?? array();
		$changes  = $params['changes'] ?? array();

		if ( empty( $post_ids ) || empty( $changes ) ) {
			return array( 'success' => false, 'message' => __( 'Post IDs and changes are required.', 'pressark' ) );
		}

		$updated = 0;
		$errors  = array();

		// Suspend cache invalidation and defer term counting for bulk performance.
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $post_ids as $pid ) {
			$pid  = intval( $pid );
			$post = get_post( $pid );
			if ( ! $post ) {
				$errors[] = sprintf(
					/* translators: %d: WordPress post ID. */
					__( 'Post #%d not found', 'pressark' ),
					$pid
				);
				continue;
			}

			if ( ! current_user_can( 'edit_post', $pid ) ) {
				$errors[] = sprintf(
					/* translators: %s: WordPress post title. */
					__( '"%s": no permission', 'pressark' ),
					$post->post_title
				);
				continue;
			}

			$update_data  = array( 'ID' => $pid );

			// Create a pre-edit revision checkpoint for undo support.
			$pre_rev_id   = $this->create_checkpoint( $pid, 'bulk_edit' );
			$log_previous = array(
				'status'      => $post->post_status,
				'revision_id' => $pre_rev_id,
			);

			if ( isset( $changes['status'] ) ) {
				$update_data['post_status'] = sanitize_text_field( $changes['status'] );
			}
			if ( isset( $changes['author'] ) ) {
				$update_data['post_author'] = intval( $changes['author'] );
			}

			if ( count( $update_data ) > 1 ) {
				$result = wp_update_post( wp_slash( $update_data ), true );
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: post title, 2: WordPress error message */
						__( '"%1$s": %2$s', 'pressark' ),
						$post->post_title,
						$result->get_error_message()
					);
					continue;
				}
			}

			// Categories.
			if ( isset( $changes['categories'] ) && is_array( $changes['categories'] ) ) {
				$cat_ids = $this->resolve_term_ids( $changes['categories'], 'category' );
				wp_set_post_categories( $pid, $cat_ids, true ); // append.
			}

			// Tags.
			if ( isset( $changes['tags'] ) && is_array( $changes['tags'] ) ) {
				wp_set_post_tags( $pid, array_map( 'sanitize_text_field', $changes['tags'] ), true ); // append.
			}

			// Log.
			$this->logger->log(
				'bulk_edit',
				$pid,
				get_post_type( $pid ),
				wp_json_encode( $log_previous ),
				wp_json_encode( $changes )
			);

			$updated++;
		}

		// Restore normal cache and term behavior.
		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
		} else {
			wp_cache_flush();
		}

		$message = sprintf(
			/* translators: 1: updated count 2: total count */
			__( 'Updated %1$d of %2$d items.', 'pressark' ),
			$updated,
			count( $post_ids )
		);
		if ( ! empty( $errors ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: semicolon-separated list of error messages. */
				__( 'Errors: %s', 'pressark' ),
				implode( '; ', $errors )
			);
		}

		return array( 'success' => true, 'message' => $message );
	}

	/**
	 * Find and replace text across posts.
	 */
	public function find_and_replace( array $params ): array {
		$find    = $params['find'] ?? '';
		$replace = $params['replace'] ?? '';
		$dry_run = $params['dry_run'] ?? true;

		if ( empty( $find ) ) {
			return array( 'success' => false, 'message' => __( 'Search text is required.', 'pressark' ) );
		}

		$post_type_arg = ( $params['post_type'] ?? 'any' );
		$args          = array(
			'post_type'      => 'any' === $post_type_arg ? array( 'post', 'page', 'product' ) : array( $post_type_arg ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
		);

		$posts     = get_posts( $args );
		$matches   = array();
		$search_in = $params['search_in'] ?? 'content';

		foreach ( $posts as $post ) {
			$found_in = array();

			if ( in_array( $search_in, array( 'content', 'both', 'all' ), true ) ) {
				if ( stripos( $post->post_content, $find ) !== false ) {
					$count      = substr_count( strtolower( $post->post_content ), strtolower( $find ) );
					$found_in[] = "content ({$count}x)";
				}
			}
			if ( in_array( $search_in, array( 'title', 'both', 'all' ), true ) ) {
				if ( stripos( $post->post_title, $find ) !== false ) {
					$found_in[] = 'title';
				}
			}
			if ( 'all' === $search_in ) {
				if ( stripos( $post->post_excerpt, $find ) !== false ) {
					$found_in[] = 'excerpt';
				}
			}

			if ( ! empty( $found_in ) ) {
				$matches[] = array(
					'post_id'  => $post->ID,
					'title'    => $post->post_title,
					'type'     => $post->post_type,
					'found_in' => implode( ', ', $found_in ),
				);
			}
		}

		if ( empty( $matches ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: text being searched for */
					__( 'No matches found for "%s".', 'pressark' ),
					$find
				),
			);
		}

		if ( $dry_run ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: search text 2: match count 3: replace text */
					__( 'Found "%1$s" in %2$d items. Run again with dry_run=false to replace with "%3$s".', 'pressark' ),
					$find,
					count( $matches ),
					$replace
				),
				'data'    => $matches,
			);
		}

		// Actually replace.
		$replaced_count = 0;

		// Suspend cache invalidation and defer term counting for bulk performance.
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		foreach ( $matches as $match ) {
			$post = get_post( $match['post_id'] );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			$update_data = array( 'ID' => $post->ID );
			$changed     = false;

			// Create a pre-edit revision checkpoint for undo support.
			$pre_rev_id = $this->create_checkpoint( $post->ID, 'find_and_replace' );

			$previous = array(
				'title'       => $post->post_title,
				'content'     => mb_substr( $post->post_content, 0, 500 ),
				'excerpt'     => $post->post_excerpt,
				'revision_id' => $pre_rev_id,
			);

			if ( in_array( $search_in, array( 'content', 'both', 'all' ), true ) ) {
				$new_content = str_ireplace( $find, $replace, $post->post_content );
				if ( $new_content !== $post->post_content ) {
					$update_data['post_content'] = $new_content;
					$changed = true;
				}
			}
			if ( in_array( $search_in, array( 'title', 'both', 'all' ), true ) ) {
				$new_title = str_ireplace( $find, $replace, $post->post_title );
				if ( $new_title !== $post->post_title ) {
					$update_data['post_title'] = $new_title;
					$changed = true;
				}
			}
			if ( 'all' === $search_in ) {
				$new_excerpt = str_ireplace( $find, $replace, $post->post_excerpt );
				if ( $new_excerpt !== $post->post_excerpt ) {
					$update_data['post_excerpt'] = $new_excerpt;
					$changed = true;
				}
			}

			if ( $changed ) {
				wp_update_post( wp_slash( $update_data ) );
				$this->logger->log(
					'find_and_replace',
					$post->ID,
					get_post_type( $post->ID ),
					wp_json_encode( $previous ),
					wp_json_encode( array( 'find' => $find, 'replace' => $replace ) )
				);
				$replaced_count++;
			}
		}

		// Restore normal cache and term behavior.
		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
		} else {
			wp_cache_flush();
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: find text 2: replace text 3: count */
				__( 'Replaced "%1$s" with "%2$s" in %3$d items.', 'pressark' ),
				$find,
				$replace,
				$replaced_count
			),
		);
	}

	/**
	 * Generate an exportable HTML report.
	 */
	public function export_report( array $params ): array {
		$report_type  = $params['report_type'] ?? 'site_overview';
		$site_name    = get_bloginfo( 'name' );
		$site_url     = home_url();
		$date         = current_time( 'F j, Y' );

		$report_html  = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>PressArk Report — " . esc_html( $site_name ) . "</title>";
		$report_html .= "<style>
			body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; color: #1d2327; }
			h1 { color: #1a1a2e; border-bottom: 3px solid #e94560; padding-bottom: 10px; }
			h2 { color: #0f3460; margin-top: 30px; }
			.report-header { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
			.report-header p { margin: 4px 0; color: #666; }
			.score-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: bold; font-size: 18px; }
			.score-good { background: #d4edda; color: #155724; }
			.score-warn { background: #fff3cd; color: #856404; }
			.score-bad { background: #f8d7da; color: #721c24; }
			table { width: 100%; border-collapse: collapse; margin: 15px 0; }
			th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; }
			th { background: #f8f9fa; font-weight: 600; }
			.pass { color: #28a745; } .fail { color: #dc3545; } .warn { color: #ffc107; }
			.footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px; text-align: center; }
			@media print { body { padding: 20px; } }
		</style></head><body>";

		$report_html .= "<div class='report-header'><h1>PressArk Report</h1>";
		$report_html .= "<p><strong>Site:</strong> " . esc_html( $site_name ) . "</p>";
		$report_html .= "<p><strong>URL:</strong> " . esc_html( $site_url ) . "</p>";
		$report_html .= "<p><strong>Generated:</strong> {$date}</p></div>";

		switch ( $report_type ) {
			case 'seo':
				$scanner = new PressArk_SEO_Scanner();
				$scan    = $scanner->scan_site();
				$score   = $scan['data']['overall_score'] ?? ( $scan['data']['average_score'] ?? 0 );
				$grade   = $scan['data']['grade'] ?? ( $scan['data']['average_grade'] ?? 'F' );
				$sc_cls  = $score >= 70 ? 'score-good' : ( $score >= 40 ? 'score-warn' : 'score-bad' );

				$report_html .= "<h2>SEO Audit Report</h2>";
				$report_html .= "<p>Overall Score: <span class='score-badge {$sc_cls}'>{$score}/100 ({$grade})</span></p>";

				$report_html .= "<h2>Page-by-Page Results</h2>";
				$report_html .= "<table><tr><th>Page</th><th>Score</th><th>Grade</th><th>Issues</th></tr>";

				$pages = $scan['data']['pages'] ?? array();
				foreach ( $pages as $page ) {
					$pclass = ( $page['score'] ?? 0 ) >= 70 ? 'pass' : ( ( $page['score'] ?? 0 ) >= 40 ? 'warn' : 'fail' );
					$issues = array();
					if ( ! empty( $page['checks'] ) ) {
						foreach ( $page['checks'] as $check ) {
							if ( ( $check['status'] ?? '' ) !== 'pass' ) {
								$issues[] = $check['label'] ?? ( $check['name'] ?? 'Issue' );
							}
						}
					}
					$issue_str    = ! empty( $issues ) ? esc_html( implode( ', ', array_slice( $issues, 0, 5 ) ) ) : 'No issues';
					$report_html .= "<tr><td>" . esc_html( $page['title'] ?? '' ) . "</td><td class='{$pclass}'>{$page['score']}</td><td>{$page['grade']}</td><td>{$issue_str}</td></tr>";
				}
				$report_html .= "</table>";
				break;

			case 'security':
				$scanner = new PressArk_Security_Scanner();
				$scan    = $scanner->scan();
				$score   = $scan['score'] ?? 0;
				$grade   = $scan['grade'] ?? 'F';
				$sc_cls  = $score >= 70 ? 'score-good' : ( $score >= 40 ? 'score-warn' : 'score-bad' );

				$report_html .= "<h2>Security Audit Report</h2>";
				$report_html .= "<p>Overall Score: <span class='score-badge {$sc_cls}'>{$score}/100 ({$grade})</span></p>";

				$report_html .= "<table><tr><th>Check</th><th>Status</th><th>Severity</th><th>Details</th></tr>";
				foreach ( $scan['checks'] ?? array() as $check ) {
					$status = $check['status'] ?? '';
					if ( 'pass' === $status ) {
						$status_icon = "<span class='pass'>" . pressark_icon( 'check' ) . " Pass</span>";
					} elseif ( 'warning' === $status ) {
						$status_icon = "<span class='warn'>" . pressark_icon( 'warning' ) . " Warning</span>";
					} else {
						$status_icon = "<span class='fail'>" . pressark_icon( 'x' ) . " Fail</span>";
					}
					$report_html .= "<tr><td>" . esc_html( $check['label'] ?? ( $check['name'] ?? '' ) ) . "</td><td>{$status_icon}</td><td>" . esc_html( $check['severity'] ?? '' ) . "</td><td>" . esc_html( $check['message'] ?? '' ) . "</td></tr>";
				}
				$report_html .= "</table>";
				break;

			case 'site_overview':
				$pages_count    = wp_count_posts( 'page' );
				$posts_count    = wp_count_posts( 'post' );
				$comments_count = wp_count_comments();
				$users_count    = count_users();
				$media_count    = wp_count_attachments();
				$total_media    = 0;
				foreach ( (array) $media_count as $mtype => $mcount ) {
					if ( 'trash' !== $mtype ) {
						$total_media += $mcount;
					}
				}

				$report_html .= "<h2>Site Overview</h2>";
				$report_html .= "<table>";
				$report_html .= "<tr><td>WordPress Version</td><td>" . esc_html( get_bloginfo( 'version' ) ) . "</td></tr>";
				$report_html .= "<tr><td>PHP Version</td><td>" . esc_html( phpversion() ) . "</td></tr>";
				$report_html .= "<tr><td>Active Theme</td><td>" . esc_html( wp_get_theme()->get( 'Name' ) ) . "</td></tr>";
				$report_html .= "<tr><td>Active Plugins</td><td>" . count( get_option( 'active_plugins', array() ) ) . "</td></tr>";
				$report_html .= "<tr><td>Pages (Published/Draft)</td><td>{$pages_count->publish} / {$pages_count->draft}</td></tr>";
				$report_html .= "<tr><td>Posts (Published/Draft)</td><td>{$posts_count->publish} / {$posts_count->draft}</td></tr>";
				$report_html .= "<tr><td>Comments</td><td>{$comments_count->approved} approved, {$comments_count->moderated} pending</td></tr>";
				$report_html .= "<tr><td>Users</td><td>{$users_count['total_users']}</td></tr>";
				$report_html .= "<tr><td>Media Files</td><td>{$total_media}</td></tr>";
				$report_html .= "</table>";
				break;

			case 'woocommerce':
				if ( ! class_exists( 'WooCommerce' ) ) {
					return array( 'success' => false, 'message' => __( 'WooCommerce is not active.', 'pressark' ) );
				}

				$products   = wp_count_posts( 'product' );
				$orders_30d = wc_get_orders( array(
					'status'       => array( 'wc-completed', 'wc-processing' ),
					'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					'return'       => 'ids',
				) );
				$revenue_30d = 0;
				$all_orders  = wc_get_orders( array(
					'status'       => array( 'wc-completed', 'wc-processing' ),
					'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					'limit'        => -1,
				) );
				foreach ( $all_orders as $order ) {
					$revenue_30d += (float) $order->get_total();
				}
				$customers = count( get_users( array( 'role' => 'customer', 'fields' => 'ID' ) ) );

				$report_html .= "<h2>WooCommerce Summary</h2>";
				$report_html .= "<table>";
				$report_html .= "<tr><td>WooCommerce Version</td><td>" . esc_html( WC()->version ) . "</td></tr>";
				$report_html .= "<tr><td>Products</td><td>{$products->publish} published, {$products->draft} draft</td></tr>";
				$report_html .= "<tr><td>Orders (30 days)</td><td>" . count( $orders_30d ) . "</td></tr>";
				$report_html .= "<tr><td>Revenue (30 days)</td><td>" . wp_strip_all_tags( wc_price( $revenue_30d ) ) . "</td></tr>";
				$report_html .= "<tr><td>Customers</td><td>{$customers}</td></tr>";
				$report_html .= "<tr><td>Currency</td><td>" . esc_html( get_woocommerce_currency() . ' (' . get_woocommerce_currency_symbol() . ')' ) . "</td></tr>";
				$report_html .= "<tr><td>Active Gateways</td><td>" . count( WC()->payment_gateways()->get_available_payment_gateways() ) . "</td></tr>";
				$report_html .= "</table>";
				break;

			default:
				return array( 'success' => false, 'message' => __( 'Invalid report type.', 'pressark' ) );
		}

		$report_html .= "<div class='footer'>Generated by PressArk &mdash; AI Site Management for WordPress &middot; pressark.ai</div>";
		$report_html .= "</body></html>";

		// Save to a temp file for download.
		$upload_dir = wp_upload_dir();
		$report_dir = $upload_dir['basedir'] . '/pressark-reports/';

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! file_exists( $report_dir ) ) {
			wp_mkdir_p( $report_dir );
			$wp_filesystem->put_contents( $report_dir . '.htaccess', "Options -Indexes\n", FS_CHMOD_FILE );
		}

		$filename = 'pressark-' . sanitize_file_name( $report_type ) . '-report-' . gmdate( 'Y-m-d-His' ) . '.html';
		$filepath = $report_dir . $filename;
		$wp_filesystem->put_contents( $filepath, $report_html, FS_CHMOD_FILE );

		$download_url = $upload_dir['baseurl'] . '/pressark-reports/' . $filename;

		// Clean up old reports (keep last 10).
		$existing = glob( $report_dir . '*.html' );
		if ( is_array( $existing ) && count( $existing ) > 10 ) {
			usort( $existing, function ( $a, $b ) {
				return filemtime( $a ) - filemtime( $b );
			} );
			$to_delete = array_slice( $existing, 0, count( $existing ) - 10 );
			foreach ( $to_delete as $f ) {
				wp_delete_file( $f );
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: report type name */
				__( '%s report generated.', 'pressark' ),
				ucfirst( $report_type )
			),
			'data'    => array(
				'download_url' => $download_url,
				'filename'     => $filename,
			),
		);
	}

	/**
	 * Search the content index for relevant knowledge chunks.
	 */
	public function search_knowledge( array $params ): array {
		$index = new PressArk_Content_Index();
		$query = sanitize_text_field( $params['query'] ?? '' );

		if ( empty( $query ) ) {
			return array( 'success' => false, 'message' => __( 'Search query is required.', 'pressark' ) );
		}

		$post_type = ! empty( $params['post_type'] ) ? sanitize_text_field( $params['post_type'] ) : null;
		$limit     = min( intval( $params['limit'] ?? 5 ), 10 );

		$results = $index->search( $query, $limit, $post_type );

		if ( empty( $results ) ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: search query */
					__( 'No content found matching "%s". The index may need rebuilding, or there\'s no content about this topic.', 'pressark' ),
					$query
				),
				'data'    => array(),
			);
		}

		$formatted = array();
		foreach ( $results as $r ) {
			$age_hours = isset( $r['age_hours'] ) ? (float) $r['age_hours'] : null;
			$formatted[] = array(
				'post_id'         => $r['post_id'],
				'title'           => $r['title'],
				'type'            => $r['post_type'],
				'relevance'       => $r['relevance'],
				'content_preview' => mb_substr( $r['content'], 0, 500 ) . ( strlen( $r['content'] ) > 500 ? '...' : '' ),
				'word_count'      => $r['meta']['word_count'] ?? str_word_count( $r['content'] ),
				'is_homepage'     => $r['meta']['is_homepage'] ?? false,
				'indexed_at'      => $r['indexed_at'] ?? null,
				'age_hours'       => $age_hours,
				'age_label'       => null !== $age_hours && class_exists( 'PressArk_Content_Index' )
					? PressArk_Content_Index::human_age( $age_hours )
					: '',
				'is_stale'        => ! empty( $r['is_stale'] ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: number of chunks 2: search query */
				__( '%1$d relevant content chunks found for "%2$s".', 'pressark' ),
				count( $formatted ),
				$query
			),
			'data'    => $formatted,
		);
	}

	/**
	 * Get content index statistics.
	 */
	public function index_status( array $params ): array {
		$index = new PressArk_Content_Index();
		$stats = $index->get_stats();

		$type_summary = array();
		foreach ( $stats['by_type'] ?? array() as $type => $counts ) {
			$type_summary[] = $counts['posts'] . ' ' . $type . 's (' . $counts['chunks'] . ' chunks)';
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: posts indexed 2: chunks 3: words 4: last sync time 5: breakdown */
				__( 'Content Index: %1$d posts indexed, %2$d chunks, ~%3$d words. Last sync: %4$s. Breakdown: %5$s', 'pressark' ),
				$stats['total_posts_indexed'] ?? 0,
				$stats['total_chunks'] ?? 0,
				$stats['total_words'] ?? 0,
				$stats['last_sync'] ?? __( 'Never', 'pressark' ),
				implode( ', ', $type_summary )
			),
			'data' => $stats,
		);
	}

	/**
	 * Rebuild the content index.
	 */
	public function rebuild_index( array $params ): array {
		$index = new PressArk_Content_Index();

		if ( ! $index->is_indexing_enabled() ) {
			return array(
				'success' => false,
				'message' => __( 'Content indexing is disabled. Select at least one indexed post type in PressArk settings before rebuilding.', 'pressark' ),
			);
		}

		$index->schedule_full_rebuild();

		return array(
			'success' => true,
			'message' => __( 'Content index rebuild scheduled. It will run in the background.', 'pressark' ),
			'data'    => $index->get_runtime_status(),
		);
	}

	// ── Private Helpers ────────────────────────────────────────────────

	/**
	 * Resolve an array of term names/IDs to term IDs,
	 * creating non-hierarchical terms on the fly if needed.
	 *
	 * @param array  $terms    Array of term names or IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Array of term IDs.
	 */
	private function resolve_term_ids( array $terms, string $taxonomy ): array {
		$tax_obj  = get_taxonomy( $taxonomy );
		$term_ids = array();

		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$term_ids[] = absint( $term );
				continue;
			}

			$name = sanitize_text_field( $term );
			$existing = get_term_by( 'name', $name, $taxonomy );

			if ( $existing ) {
				$term_ids[] = $existing->term_id;
			} elseif ( ! $tax_obj->hierarchical ) {
				// Create non-hierarchical terms (like tags) on the fly.
				$new = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $new ) ) {
					$term_ids[] = $new['term_id'];
				}
			} else {
				// For hierarchical (categories), try slug match.
				$by_slug = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
				if ( $by_slug ) {
					$term_ids[] = $by_slug->term_id;
				}
			}
		}

		return $term_ids;
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	/**
	 * Preview for edit_content.
	 */
	public function preview_edit_content( array $params, array $action ): array {
		$post_id = absint( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$changes = $params['changes'] ?? ( $action['changes'] ?? array() );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		$preview = array(
			'post_title' => $post->post_title,
			'post_id'    => $post_id,
			'changes'    => array(),
		);

		if ( isset( $changes['title'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Title', 'pressark' ),
				'before' => $post->post_title,
				'after'  => $changes['title'],
			);
		}
		if ( isset( $changes['content'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Content', 'pressark' ),
				'before' => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 150 ) . ( mb_strlen( wp_strip_all_tags( $post->post_content ) ) > 150 ? '...' : '' ),
				'after'  => mb_substr( wp_strip_all_tags( $changes['content'] ), 0, 150 ) . ( mb_strlen( wp_strip_all_tags( $changes['content'] ) ) > 150 ? '...' : '' ),
			);
		}
		if ( isset( $changes['excerpt'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Excerpt', 'pressark' ),
				'before' => $post->post_excerpt ?: __( '(empty)', 'pressark' ),
				'after'  => $changes['excerpt'],
			);
		}
		if ( isset( $changes['status'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Status', 'pressark' ),
				'before' => $post->post_status,
				'after'  => $changes['status'],
			);
		}

		$preview['seo_warnings'] = PressArk_SEO_Impact_Analyzer::analyze_edit( $post_id, $changes );

		return $preview;
	}

	/**
	 * Preview for update_meta.
	 */
	public function preview_update_meta( array $params, array $action ): array {
		$post_id = absint( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$changes = $params['changes'] ?? ( $action['changes'] ?? array() );
		$post    = get_post( $post_id );

		$preview = array(
			'post_title' => $post ? $post->post_title : __( 'Unknown', 'pressark' ),
			'post_id'    => $post_id,
			'changes'    => array(),
		);

		$meta_changes = $changes ?: ( $params['meta'] ?? array() );
		foreach ( $meta_changes as $key => $value ) {
			if ( 'post_id' === $key ) {
				continue;
			}
			$current = get_post_meta( $post_id, $key, true );
			$preview['changes'][] = array(
				'field'  => $this->humanize_meta_key( $key ),
				'before' => $current ?: __( '(empty)', 'pressark' ),
				'after'  => $value,
			);
		}

		$preview['seo_warnings'] = PressArk_SEO_Impact_Analyzer::analyze_meta_update( $post_id, $meta_changes );

		return $preview;
	}

	/**
	 * Preview for create_post.
	 */
	public function preview_create_post( array $params, array $action ): array {
		$title     = $params['title'] ?? ( ( $params['changes'] ?? array() )['title'] ?? __( 'Untitled', 'pressark' ) );
		$post_type = $params['post_type'] ?? 'post';
		$status    = $params['status'] ?? ( $action['status'] ?? 'draft' );

		return array(
			'changes' => array(
				array(
					'field'  => sprintf(
						/* translators: %s: post type */
						__( 'New %s', 'pressark' ),
						$post_type
					),
					'before' => __( '(does not exist)', 'pressark' ),
					/* translators: 1: post title, 2: post status */
					'after'  => sprintf( __( '%1$s (status: %2$s)', 'pressark' ), $title, $status ),
				),
			),
		);
	}

	/**
	 * Preview for delete_content.
	 */
	public function preview_delete_content( array $params, array $action ): array {
		$post_id = absint( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return array( 'changes' => array() );
		}

		return array(
			'post_title' => $post->post_title,
			'post_id'    => $post_id,
			'changes'    => array(
				array(
					'field'  => __( 'Delete', 'pressark' ),
					'before' => sprintf(
						/* translators: 1: post title, 2: post status. */
						__( '"%1$s" (%2$s)', 'pressark' ),
						$post->post_title,
						$post->post_status
					),
					'after'  => __( 'Moved to trash', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Preview for bulk_edit.
	 */
	public function preview_bulk_edit( array $params, array $action ): array {
		$bulk_ids     = $params['post_ids'] ?? ( $action['post_ids'] ?? array() );
		$bulk_changes = $params['changes'] ?? ( $action['changes'] ?? array() );

		$bulk_titles = array();
		foreach ( array_slice( $bulk_ids, 0, 5 ) as $bpid ) {
			$bp = get_post( intval( $bpid ) );
			if ( $bp ) {
				$bulk_titles[] = "\"{$bp->post_title}\"";
			}
		}
		$bulk_title_str = implode( ', ', $bulk_titles );
		if ( count( $bulk_ids ) > 5 ) {
			$bulk_title_str .= sprintf(
				/* translators: %d: number of additional items */
				__( ' +%d more', 'pressark' ),
				count( $bulk_ids ) - 5
			);
		}

		$preview = array(
			'post_title' => sprintf(
				/* translators: %d: number of items in the bulk edit */
				__( 'Bulk Edit — %d items', 'pressark' ),
				count( $bulk_ids )
			),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Affected Items', 'pressark' ),
					'before' => $bulk_title_str,
					'after'  => sprintf(
						/* translators: %d: number of items that will be modified */
						__( '%d items will be modified', 'pressark' ),
						count( $bulk_ids )
					),
				),
			),
		);

		foreach ( $bulk_changes as $bkey => $bvalue ) {
			$readable = is_array( $bvalue ) ? implode( ', ', $bvalue ) : (string) $bvalue;
			$preview['changes'][] = array(
				'field'  => ucfirst( $bkey ),
				'before' => __( '(various)', 'pressark' ),
				'after'  => $readable,
			);
		}

		$preview['seo_warnings'] = PressArk_SEO_Impact_Analyzer::analyze_bulk_edit( $bulk_ids, $bulk_changes );

		return $preview;
	}

	/**
	 * Preview for find_and_replace.
	 */
	public function preview_find_and_replace( array $params, array $action ): array {
		if ( $params['dry_run'] ?? ( $action['dry_run'] ?? true ) ) {
			return array( 'changes' => array() );
		}

		$find      = $params['find'] ?? ( $action['find'] ?? '' );
		$replace   = $params['replace'] ?? ( $action['replace'] ?? '' );
		$search_in = $params['search_in'] ?? ( $action['search_in'] ?? 'content' );

		$preview = array(
			'post_title' => __( 'Find & Replace', 'pressark' ),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Find', 'pressark' ),
					'before' => $find,
					'after'  => $replace,
				),
				array(
					'field'  => __( 'Scope', 'pressark' ),
					'before' => sprintf(
						/* translators: %s: search scope */
						__( 'Searching in: %s', 'pressark' ),
						$search_in
					),
					'after'  => __( 'Will replace in all matching posts', 'pressark' ),
				),
			),
		);

		$fr_matches = array();
		if ( in_array( $search_in, array( 'title', 'both', 'all' ), true ) && '' !== $find ) {
			$posts = get_posts( array(
				'post_type'      => array( 'post', 'page', 'product' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			foreach ( $posts as $pid ) {
				$p = get_post( $pid );
				if ( $p && false !== stripos( $p->post_title, $find ) ) {
					$fr_matches[] = array( 'post_id' => $p->ID, 'title' => $p->post_title );
				}
			}
		}
		$preview['seo_warnings'] = PressArk_SEO_Impact_Analyzer::analyze_find_replace( $find, $replace, $search_in, $fr_matches );

		return $preview;
	}

	/**
	 * Preview for bulk_delete.
	 */
	public function preview_bulk_delete( array $params, array $action ): array {
		$bulk_ids = $params['post_ids'] ?? ( $action['post_ids'] ?? array() );

		$bulk_titles = array();
		foreach ( array_slice( $bulk_ids, 0, 10 ) as $bpid ) {
			$bp = get_post( intval( $bpid ) );
			if ( $bp ) {
				$bulk_titles[] = "\"{$bp->post_title}\" ({$bp->post_status})";
			}
		}
		$bulk_title_str = implode( ', ', $bulk_titles );
		if ( count( $bulk_ids ) > 10 ) {
			$bulk_title_str .= sprintf(
				/* translators: %d: number of additional items */
				__( ' +%d more', 'pressark' ),
				count( $bulk_ids ) - 10
			);
		}

		return array(
			'post_title' => sprintf(
				/* translators: %d: number of items in the bulk delete */
				__( 'Bulk Delete — %d items', 'pressark' ),
				count( $bulk_ids )
			),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Items to Trash', 'pressark' ),
					'before' => $bulk_title_str,
					'after'  => sprintf(
						/* translators: %d: number of items that will be moved to trash */
						__( '%d items will be moved to trash', 'pressark' ),
						count( $bulk_ids )
					),
				),
			),
		);
	}

	/**
	 * Preview for empty_trash.
	 */
	public function preview_empty_trash( array $params, array $action ): array {
		$trash_ids  = $params['post_ids'] ?? ( $action['post_ids'] ?? array() );
		$trash_type = $params['post_type'] ?? ( $action['post_type'] ?? 'any' );

		if ( empty( $trash_ids ) ) {
			$query_types   = 'any' === $trash_type ? array( 'post', 'page' ) : array( $trash_type );
			$trashed_posts = get_posts( array(
				'post_type'             => $query_types,
				'post_status'           => 'trash',
				'posts_per_page'        => 100,
				'fields'                => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			$trash_ids = $trashed_posts;
		}

		$trash_count = count( $trash_ids );
		$trash_titles = array();
		foreach ( array_slice( $trash_ids, 0, 10 ) as $tpid ) {
			$tp = get_post( intval( $tpid ) );
			if ( $tp ) {
				$trash_titles[] = "\"{$tp->post_title}\" ({$tp->post_type})";
			}
		}
		$trash_title_str = implode( ', ', $trash_titles );
		if ( $trash_count > 10 ) {
			$trash_title_str .= sprintf(
				/* translators: %d: number of additional items */
				__( ' +%d more', 'pressark' ),
				$trash_count - 10
			);
		}

		return array(
			'post_title' => sprintf(
				/* translators: %d: number of items in the trash */
				__( 'Empty Trash — %d items', 'pressark' ),
				$trash_count
			),
			'post_id'    => 0,
			'changes'    => array(
				array(
					'field'  => __( 'Permanent Delete', 'pressark' ),
					'before' => $trash_title_str ?: __( '(empty trash)', 'pressark' ),
					'after'  => sprintf(
						/* translators: %d: number of items that will be permanently deleted */
						__( '%d items will be permanently deleted — this cannot be undone', 'pressark' ),
						$trash_count
					),
				),
			),
		);
	}
}
