<?php
/**
 * SEO Impact Analyzer — warns users when proposed changes touch SEO-sensitive fields.
 *
 * Stateless static class. Each method returns array<array{type:string, label:string, detail:string}>.
 * Severity levels: 'warning' (red), 'caution' (amber), 'info' (blue).
 *
 * @since 4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_SEO_Impact_Analyzer {

	/** Minimum word count before "thin content" fires. */
	const THIN_CONTENT_THRESHOLD = 300;

	/** Maximum acceptable keyword density (3%). */
	const KEYWORD_DENSITY_MAX = 0.03;

	/** Recommended max SEO title length. */
	const TITLE_MAX_LENGTH = 60;

	/** Recommended max meta description length. */
	const DESC_MAX_LENGTH = 160;

	// ── Public API ─────────────────────────────────────────────────────

	/**
	 * Analyze a single-post edit for SEO impact.
	 *
	 * @param int   $post_id Post being edited.
	 * @param array $changes Associative array of proposed field changes.
	 * @return array<array{type:string, label:string, detail:string}>
	 */
	public static function analyze_edit( int $post_id, array $changes ): array {
		$warnings = array();
		$post     = get_post( $post_id );

		if ( ! $post ) {
			return $warnings;
		}

		// ── Title changed ────────────────────────────────────────────
		if ( isset( $changes['title'] ) && $changes['title'] !== $post->post_title ) {
			$has_explicit_seo_title = (bool) PressArk_SEO_Resolver::read( $post_id, 'meta_title' );

			if ( $has_explicit_seo_title ) {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'H1 Change', 'pressark' ),
					'detail' => __( 'Custom SEO title overrides — only the on-page H1 is affected.', 'pressark' ),
				);
			} else {
				$warnings[] = array(
					'type'   => 'caution',
					'label'  => __( 'SEO Title Impact', 'pressark' ),
					'detail' => __( 'No custom SEO title set — auto-generated SERP title will change.', 'pressark' ),
				);
			}

			// Title length check.
			if ( mb_strlen( $changes['title'] ) > self::TITLE_MAX_LENGTH ) {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'Title Length', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: character count */
						__( 'New title is %d chars — may be truncated in search results.', 'pressark' ),
						mb_strlen( $changes['title'] )
					),
				);
			}
		}

		// ── Slug changed ─────────────────────────────────────────────
		if ( isset( $changes['slug'] ) && $changes['slug'] !== $post->post_name ) {
			if ( 'publish' === $post->post_status ) {
				$warnings[] = array(
					'type'   => 'warning',
					'label'  => __( 'URL Change', 'pressark' ),
					'detail' => sprintf(
						/* translators: 1: old slug 2: new slug */
						__( 'Changing slug from "%1$s" to "%2$s" on a published post — breaks existing backlinks. Consider a 301 redirect.', 'pressark' ),
						$post->post_name,
						$changes['slug']
					),
				);
			} else {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'URL Change', 'pressark' ),
					'detail' => sprintf(
						/* translators: 1: old slug 2: new slug */
						__( 'Slug will change from "%1$s" to "%2$s" — post is not published, so no indexed URLs affected.', 'pressark' ),
						$post->post_name,
						$changes['slug']
					),
				);
			}
		}

		// ── Content checks ───────────────────────────────────────────
		if ( isset( $changes['content'] ) ) {
			$old_content = $post->post_content;
			$new_content = $changes['content'];

			// H1 changed.
			$old_h1 = self::extract_h1( $old_content );
			$new_h1 = self::extract_h1( $new_content );

			if ( $old_h1 && $new_h1 && $old_h1 !== $new_h1 ) {
				$warnings[] = array(
					'type'   => 'caution',
					'label'  => __( 'H1 Changed', 'pressark' ),
					'detail' => __( 'Primary heading changed — this is a key content signal for search engines.', 'pressark' ),
				);
			}

			// Thin content.
			if ( self::word_count( $new_content ) < self::THIN_CONTENT_THRESHOLD ) {
				$warnings[] = array(
					'type'   => 'caution',
					'label'  => __( 'Thin Content', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: word count */
						__( 'New content is only %d words — may rank poorly in search results.', 'pressark' ),
						self::word_count( $new_content )
					),
				);
			}

			// Internal links removed.
			$old_links = self::extract_internal_links( $old_content );
			$new_links = self::extract_internal_links( $new_content );

			$removed_urls = array_diff_key( $old_links, $new_links );
			if ( ! empty( $removed_urls ) ) {
				$warnings[] = array(
					'type'   => 'caution',
					'label'  => __( 'Internal Links Removed', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: number of links */
						__( '%d internal link(s) removed — reduces internal link equity flow.', 'pressark' ),
						count( $removed_urls )
					),
				);
			}

			// Anchor text changed on surviving links.
			$common_urls = array_intersect_key( $old_links, $new_links );
			$anchor_changed = 0;
			foreach ( $common_urls as $url => $old_anchor ) {
				if ( $old_anchor !== $new_links[ $url ] ) {
					$anchor_changed++;
				}
			}
			if ( $anchor_changed > 0 ) {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'Anchor Text Changed', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: number of links */
						__( 'Anchor text changed on %d internal link(s) — affects link context signals.', 'pressark' ),
						$anchor_changed
					),
				);
			}
		}

		// ── Status change ────────────────────────────────────────────
		if ( isset( $changes['status'] ) ) {
			$unpublish_statuses = array( 'draft', 'private', 'pending' );
			if ( 'publish' === $post->post_status && in_array( $changes['status'], $unpublish_statuses, true ) ) {
				$warnings[] = array(
					'type'   => 'warning',
					'label'  => __( 'Unpublishing', 'pressark' ),
					'detail' => __( 'Post will be removed from search indexes — re-ranking after republishing can take weeks.', 'pressark' ),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Analyze meta field updates for SEO impact.
	 *
	 * @param int   $post_id      Post being updated.
	 * @param array $meta_changes Key-value pairs of meta changes.
	 * @return array<array{type:string, label:string, detail:string}>
	 */
	public static function analyze_meta_update( int $post_id, array $meta_changes ): array {
		$warnings = array();

		foreach ( $meta_changes as $key => $value ) {
			if ( ! self::is_seo_meta_key( $key ) ) {
				continue;
			}

			$normalized = self::normalize_meta_key( $key );

			// SEO title length.
			if ( 'meta_title' === $normalized && mb_strlen( (string) $value ) > self::TITLE_MAX_LENGTH ) {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'SEO Title Length', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: character count */
						__( 'SEO title is %d chars — may be truncated in search results (recommended: ≤60).', 'pressark' ),
						mb_strlen( (string) $value )
					),
				);
			}

			// Meta description length.
			if ( 'meta_description' === $normalized && mb_strlen( (string) $value ) > self::DESC_MAX_LENGTH ) {
				$warnings[] = array(
					'type'   => 'info',
					'label'  => __( 'Meta Description Length', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: character count */
						__( 'Meta description is %d chars — may be truncated in search results (recommended: ≤160).', 'pressark' ),
						mb_strlen( (string) $value )
					),
				);
			}

			// Canonical change.
			if ( 'canonical' === $normalized ) {
				$warnings[] = array(
					'type'   => 'caution',
					'label'  => __( 'Canonical URL Changed', 'pressark' ),
					'detail' => __( 'Changing canonical URL affects which version search engines index — ensure the target URL is correct.', 'pressark' ),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Analyze a find-and-replace operation for SEO impact.
	 *
	 * @param string $find      The search string.
	 * @param string $replace   The replacement string.
	 * @param string $search_in Scope: title, content, both, all.
	 * @param array  $matches   Array of ['post_id' => int, 'title' => string] for title-scope matches.
	 * @return array<array{type:string, label:string, detail:string}>
	 */
	public static function analyze_find_replace( string $find, string $replace, string $search_in, array $matches ): array {
		$warnings = array();

		$affects_titles = in_array( $search_in, array( 'title', 'both', 'all' ), true );

		// Title SERP impact.
		if ( $affects_titles && count( $matches ) > 0 ) {
			$warnings[] = array(
				'type'   => 'caution',
				'label'  => __( 'Title SERP Impact', 'pressark' ),
				'detail' => sprintf(
					/* translators: %d: number of posts */
					__( '%d post title(s) will change — SERP appearance may be affected.', 'pressark' ),
					count( $matches )
				),
			);
		}

		// Duplicate title detection.
		if ( $affects_titles && count( $matches ) > 1 ) {
			$resulting_titles = array();
			foreach ( $matches as $match ) {
				$new_title = str_ireplace( $find, $replace, $match['title'] );
				$resulting_titles[ $new_title ][] = $match['post_id'];
			}

			$duplicates = array_filter( $resulting_titles, function ( $ids ) {
				return count( $ids ) > 1;
			} );

			if ( ! empty( $duplicates ) ) {
				$dup_count  = count( $duplicates );
				$warnings[] = array(
					'type'   => 'warning',
					'label'  => __( 'Duplicate Titles', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: number of duplicate title groups */
						__( 'Replacement creates %d group(s) of identical titles — duplicate titles harm SEO.', 'pressark' ),
						$dup_count
					),
				);
			}
		}

		// Content removal (empty replacement).
		if ( '' === $replace && '' !== $find ) {
			$warnings[] = array(
				'type'   => 'caution',
				'label'  => __( 'Content Removal', 'pressark' ),
				'detail' => __( 'Replacement is empty — matched text will be deleted entirely.', 'pressark' ),
			);
		}

		// Large-scale operation.
		if ( count( $matches ) > 10 ) {
			$warnings[] = array(
				'type'   => 'info',
				'label'  => __( 'Large-Scale Change', 'pressark' ),
				'detail' => sprintf(
					/* translators: %d: match count */
					__( '%d matches found — review carefully before applying.', 'pressark' ),
					count( $matches )
				),
			);
		}

		return $warnings;
	}

	/**
	 * Analyze a bulk edit operation for SEO impact.
	 *
	 * @param array $post_ids Array of post IDs being edited.
	 * @param array $changes  Associative array of changes applied to all posts.
	 * @return array<array{type:string, label:string, detail:string}>
	 */
	public static function analyze_bulk_edit( array $post_ids, array $changes ): array {
		$warnings = array();

		// Mass unpublish.
		if ( isset( $changes['status'] ) && in_array( $changes['status'], array( 'draft', 'private', 'pending' ), true ) ) {
			$published_count = 0;
			foreach ( $post_ids as $pid ) {
				$p = get_post( intval( $pid ) );
				if ( $p && 'publish' === $p->post_status ) {
					$published_count++;
				}
			}

			if ( $published_count > 0 ) {
				$warnings[] = array(
					'type'   => 'warning',
					'label'  => __( 'Mass Unpublish', 'pressark' ),
					'detail' => sprintf(
						/* translators: %d: number of published posts */
						__( '%d currently published post(s) will be removed from search indexes — re-ranking takes weeks.', 'pressark' ),
						$published_count
					),
				);
			}
		}

		// Category reassignment.
		if ( isset( $changes['categories'] ) || isset( $changes['category'] ) ) {
			$warnings[] = array(
				'type'   => 'info',
				'label'  => __( 'Category Change', 'pressark' ),
				'detail' => sprintf(
					/* translators: %d: number of posts */
					__( 'Categories will be reassigned on %d post(s) — may affect category archive pages and URL structure.', 'pressark' ),
					count( $post_ids )
				),
			);
		}

		return $warnings;
	}

	// ── Private Helpers ────────────────────────────────────────────────

	/**
	 * Extract the inner text of the first <h1> element.
	 *
	 * @param string $html HTML content.
	 * @return string H1 text or empty string.
	 */
	private static function extract_h1( string $html ): string {
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Extract internal links as [url => anchor_text].
	 *
	 * @param string $html HTML content.
	 * @return array<string, string>
	 */
	private static function extract_internal_links( string $html ): array {
		$links    = array();
		$site_url = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $site_url ) {
			return $links;
		}

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$href   = $m[1];
				$anchor = trim( wp_strip_all_tags( $m[2] ) );
				$host   = wp_parse_url( $href, PHP_URL_HOST );

				// Internal if same host or relative URL.
				if ( ! $host || $host === $site_url ) {
					$links[ $href ] = $anchor;
				}
			}
		}

		return $links;
	}

	/**
	 * Check if a meta key corresponds to a known SEO field.
	 *
	 * @param string $key Meta key to check.
	 * @return bool
	 */
	private static function is_seo_meta_key( string $key ): bool {
		$semantic_names = array(
			'meta_title', 'meta_description', 'canonical',
			'og_title', 'og_description', 'og_image', 'focus_keyword',
		);

		// Direct semantic name match.
		if ( in_array( $key, $semantic_names, true ) ) {
			return true;
		}

		// Check via SEO Resolver — if it resolves to a non-empty key, it's SEO-related.
		if ( class_exists( 'PressArk_SEO_Resolver' ) ) {
			$resolved = PressArk_SEO_Resolver::resolve_key( $key );
			if ( $resolved && $resolved !== $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a meta key to its semantic name.
	 *
	 * @param string $key Raw or semantic meta key.
	 * @return string Semantic name (e.g. 'meta_title') or original key.
	 */
	private static function normalize_meta_key( string $key ): string {
		$semantic_names = array(
			'meta_title', 'meta_description', 'canonical',
			'og_title', 'og_description', 'og_image', 'focus_keyword',
		);

		if ( in_array( $key, $semantic_names, true ) ) {
			return $key;
		}

		// Try to reverse-resolve via known patterns.
		$normalized = strtolower( trim( $key ) );
		$normalized = preg_replace( '/^_?(pressark|yoast_wpseo|rank_math|aioseo|seopress_titles|seopress_social|genesis)_?/', '', $normalized );
		$normalized = str_replace( '-', '_', $normalized );

		if ( in_array( $normalized, $semantic_names, true ) ) {
			return $normalized;
		}

		// Common aliases.
		$aliases = array(
			'title'       => 'meta_title',
			'description' => 'meta_description',
			'metadesc'    => 'meta_description',
			'desc'        => 'meta_description',
		);

		return $aliases[ $normalized ] ?? $key;
	}

	/**
	 * Count words in text content.
	 *
	 * @param string $text HTML or plain text.
	 * @return int Word count.
	 */
	private static function word_count( string $text ): int {
		return str_word_count( wp_strip_all_tags( $text ) );
	}
}
