<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep SEO analysis for individual pages and site-wide scanning.
 *
 * v4.5.0: Template-aware checks — when a supported SEO plugin is active,
 * blank per-post meta is not penalized (the plugin template renders valid
 * output). Noindex pages are excluded from scoring. Length gates softened
 * to 30-70 (title) and 50-200 (description) to match real SERP display.
 *
 * v5.0.0: Subscore architecture — 4 weighted category subscores
 * (indexing_health, search_appearance, content_quality, social_sharing).
 * Canonical URL, robots meta, and noindex/nofollow conflict checks added.
 * Content length, external links, featured image demoted to observations.
 * Multiple H1s demoted to info (not penalized).
 */
class PressArk_SEO_Scanner {

	/**
	 * Subscore category weights (must sum to 1.0).
	 */
	private const WEIGHTS = array(
		'indexing_health'   => 0.30,
		'search_appearance' => 0.30,
		'content_quality'   => 0.25,
		'social_sharing'    => 0.15,
	);

	/**
	 * Scan a single page/post for SEO issues.
	 *
	 * @param int $post_id Post ID to scan.
	 * @return array SEO report with score, grade, subscores, checks, observations, and quick fixes.
	 */
	public function scan_page( int $post_id ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array( 'error' => __( 'Post not found.', 'pressark' ) );
		}

		$content    = $post->post_content;
		$plain_text = wp_strip_all_tags( $content );
		$title      = $post->post_title;
		$url        = get_permalink( $post_id );

		// Detect SEO plugin once for all template-aware checks.
		$seo_plugin = PressArk_SEO_Resolver::detect();

		// Noindex early return — page is intentionally excluded from search.
		if ( PressArk_SEO_Resolver::is_noindex( $post_id ) ) {
			$plugin_label = PressArk_SEO_Resolver::label( $seo_plugin );
			return array(
				'score'        => null,
				'grade'        => 'N/A',
				'post_id'      => $post_id,
				'post_title'   => $title,
				'url'          => $url,
				'noindex'      => true,
				'checks'       => array(
					array(
						'name'     => __( 'Noindex', 'pressark' ),
						'status'   => 'info',
						'category' => 'indexing_health',
						'message'  => sprintf(
							/* translators: %s: SEO plugin name */
							__( 'Set to noindex by %s. Skipped.', 'pressark' ),
							$plugin_label
						),
					),
				),
				'quick_fixes'  => array(),
				'subscores'    => null,
				'observations' => array(),
			);
		}

		// Run each category.
		$indexing    = $this->check_indexing_health( $post_id, $post, $content, $seo_plugin );
		$appearance = $this->check_search_appearance( $post_id, $post, $content, $seo_plugin, $plain_text );
		$quality    = $this->check_content_quality( $post, $content );
		$social     = $this->check_social_sharing( $post_id );
		$obs        = $this->collect_observations( $post_id, $post, $content, $plain_text );

		// Build subscores.
		$subscores = array(
			'indexing_health'   => array(
				'score'  => $indexing['score'],
				'grade'  => $this->score_to_grade( $indexing['score'] ),
				'weight' => self::WEIGHTS['indexing_health'],
			),
			'search_appearance' => array(
				'score'  => $appearance['score'],
				'grade'  => $this->score_to_grade( $appearance['score'] ),
				'weight' => self::WEIGHTS['search_appearance'],
			),
			'content_quality'   => array(
				'score'  => $quality['score'],
				'grade'  => $this->score_to_grade( $quality['score'] ),
				'weight' => self::WEIGHTS['content_quality'],
			),
			'social_sharing'    => array(
				'score'  => $social['score'],
				'grade'  => $this->score_to_grade( $social['score'] ),
				'weight' => self::WEIGHTS['social_sharing'],
			),
		);

		// Weighted overall score.
		$total_score = 0;
		foreach ( $subscores as $cat => $data ) {
			$total_score += $data['score'] * self::WEIGHTS[ $cat ];
		}
		$total_score = (int) round( $total_score );

		// Merge all checks, quick_fixes, observations.
		$checks      = array_merge( $indexing['checks'], $appearance['checks'], $quality['checks'], $social['checks'] );
		$quick_fixes = array_merge( $indexing['quick_fixes'], $appearance['quick_fixes'], $quality['quick_fixes'], $social['quick_fixes'] );

		// Add observation checks to checks[] with status:info for backward compat.
		foreach ( $obs as $ob ) {
			$checks[] = $ob;
		}

		$grade = $this->score_to_grade( $total_score );

		return array(
			'score'        => $total_score,
			'grade'        => $grade,
			'post_id'      => $post_id,
			'post_title'   => $title,
			'url'          => $url,
			'noindex'      => false,
			'checks'       => $checks,
			'quick_fixes'  => $quick_fixes,
			'subscores'    => $subscores,
			'observations' => $obs,
		);
	}

	/**
	 * Scan ALL published pages and posts.
	 *
	 * Noindex pages are included for visibility but excluded from the
	 * average score calculation — they are intentionally out of search.
	 *
	 * @return array Site-wide SEO report.
	 */
	public function scan_site( int $limit = 50, int $offset = 0 ): array {
		$limit = min( max( $limit, 1 ), 100 );
		$all_content = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$pages           = array();
		$total_score     = 0;
		$scored_count    = 0;
		$noindex_count   = 0;
		$critical_issues = 0;
		$issue_counts    = array();

		// Accumulate subscores for averaging.
		$subscore_sums = array();
		foreach ( self::WEIGHTS as $cat => $w ) {
			$subscore_sums[ $cat ] = 0;
		}

		foreach ( $all_content as $content_item ) {
			$result  = $this->scan_page( $content_item->ID );
			$pages[] = $result;

			// Noindex pages: visible in results but excluded from scoring average.
			if ( ! empty( $result['noindex'] ) ) {
				$noindex_count++;
				continue;
			}

			$total_score += $result['score'] ?? 0;
			$scored_count++;

			// Accumulate subscores.
			if ( ! empty( $result['subscores'] ) ) {
				foreach ( $result['subscores'] as $cat => $data ) {
					$subscore_sums[ $cat ] += $data['score'];
				}
			}

			foreach ( $result['checks'] ?? array() as $check ) {
				if ( 'fail' === ( $check['status'] ?? '' ) ) {
					$critical_issues++;
					$name = $check['name'] ?? __( 'Unknown', 'pressark' );
					if ( ! isset( $issue_counts[ $name ] ) ) {
						$issue_counts[ $name ] = 0;
					}
					$issue_counts[ $name ]++;
				}
			}
		}

		$average_score = $scored_count > 0 ? (int) round( $total_score / $scored_count ) : 0;

		// Compute average subscores.
		$average_subscores = array();
		if ( $scored_count > 0 ) {
			foreach ( self::WEIGHTS as $cat => $w ) {
				$avg = (int) round( $subscore_sums[ $cat ] / $scored_count );
				$average_subscores[ $cat ] = array(
					'score'  => $avg,
					'grade'  => $this->score_to_grade( $avg ),
					'weight' => $w,
				);
			}
		}

		// Sort pages worst to best (noindex pages sort to end with null score).
		usort( $pages, function ( $a, $b ) {
			$sa = $a['score'] ?? PHP_INT_MAX;
			$sb = $b['score'] ?? PHP_INT_MAX;
			return $sa <=> $sb;
		} );

		// Sort issue counts descending.
		arsort( $issue_counts );

		// Total published content for pagination.
		$total_published = array_sum( array_map( 'intval', array(
			wp_count_posts( 'post' )->publish ?? 0,
			wp_count_posts( 'page' )->publish ?? 0,
		) ) );

		return array(
			'total_pages'       => count( $pages ),
			'scored_pages'      => $scored_count,
			'noindex_count'     => $noindex_count,
			'average_score'     => $average_score,
			'average_grade'     => $this->score_to_grade( $average_score ),
			'critical_issues'   => $critical_issues,
			'pages'             => $pages,
			'top_issues'        => $issue_counts,
			'average_subscores' => $average_subscores,
			'_pagination'       => array(
				'total'    => $total_published,
				'offset'   => $offset,
				'limit'    => $limit,
				'has_more' => ( $offset + $limit ) < $total_published,
			),
		);
	}

	// ── Category: Indexing Health (30%, 0-100) ───────────────────────

	/**
	 * @return array{score: int, checks: array, observations: array, quick_fixes: array}
	 */
	private function check_indexing_health( int $post_id, \WP_Post $post, string $content, ?string $seo_plugin ): array {
		$checks      = array();
		$quick_fixes = array();
		$score       = 0;

		// 1. Canonical URL (30 pts).
		$canonical = PressArk_SEO_Resolver::read( $post_id, 'canonical' );
		$permalink = get_permalink( $post_id );

		if ( ! empty( $canonical ) ) {
			if ( untrailingslashit( $canonical ) === untrailingslashit( $permalink ) ) {
				// Self-referencing canonical — perfect.
				$score   += 30;
				$checks[] = array(
					'name'     => __( 'Canonical URL', 'pressark' ),
					'status'   => 'pass',
					'category' => 'indexing_health',
					'message'  => __( 'Self-referencing canonical URL set.', 'pressark' ),
				);
			} else {
				// Differs from permalink — might be intentional (consolidation) but worth a warning.
				$score   += 20;
				$checks[] = array(
					'name'     => __( 'Canonical URL', 'pressark' ),
					'status'   => 'warning',
					'category' => 'indexing_health',
					'message'  => sprintf(
						__( 'Canonical URL differs from permalink: %s', 'pressark' ),
						$canonical
					),
				);
			}
		} elseif ( $seo_plugin ) {
			// Plugin is handling canonical via template.
			$score   += 25;
			$checks[] = array(
				'name'     => __( 'Canonical URL', 'pressark' ),
				'status'   => 'info',
				'category' => 'indexing_health',
				'message'  => sprintf(
					/* translators: %s: SEO plugin name */
					__( 'Canonical URL handled by %s.', 'pressark' ),
					PressArk_SEO_Resolver::label( $seo_plugin )
				),
			);
		} else {
			// No canonical set, no plugin.
			$checks[] = array(
				'name'     => __( 'Canonical URL', 'pressark' ),
				'status'   => 'fail',
				'category' => 'indexing_health',
				'message'  => __( 'No canonical URL set. Risk of duplicate content.', 'pressark' ),
				'fix'      => __( 'Set a canonical URL or install an SEO plugin.', 'pressark' ),
			);
		}

		// 2. Robots Meta (25 pts).
		$is_nofollow = PressArk_SEO_Resolver::is_nofollow( $post_id );

		if ( ! $is_nofollow ) {
			$score   += 25;
			$checks[] = array(
				'name'     => __( 'Robots Meta', 'pressark' ),
				'status'   => 'pass',
				'category' => 'indexing_health',
				'message'  => __( 'Robots meta: index, follow — optimal for search.', 'pressark' ),
			);
		} else {
			// nofollow-only (not noindex, since noindex would have triggered early return).
			$score   += 15;
			$checks[] = array(
				'name'     => __( 'Robots Meta', 'pressark' ),
				'status'   => 'warning',
				'category' => 'indexing_health',
				'message'  => __( 'Page is set to nofollow. Links on this page will not pass authority.', 'pressark' ),
			);
		}

		// 3. Noindex/Nofollow Conflicts (20 pts).
		$has_conflict = false;
		$conflicts    = array();

		// Conflict: canonical set + noindex (outside early return — shouldn't happen, but defensive).
		// Since we already returned early for noindex, this checks for subtle plugin misconfigs.
		if ( ! empty( $canonical ) && PressArk_SEO_Resolver::is_noindex( $post_id ) ) {
			$has_conflict = true;
			$conflicts[]  = __( 'canonical URL set on a noindex page', 'pressark' );
		}

		// Conflict: check if page appears in sitemap while nofollow.
		// (Lightweight check — just flag nofollow as potential conflict signal.)
		if ( $is_nofollow && ! empty( $canonical ) ) {
			// Nofollow + explicit canonical is unusual.
			$has_conflict = true;
			$conflicts[]  = __( 'nofollow with explicit canonical URL', 'pressark' );
		}

		if ( ! $has_conflict ) {
			$score   += 20;
			$checks[] = array(
				'name'     => __( 'Indexing Conflicts', 'pressark' ),
				'status'   => 'pass',
				'category' => 'indexing_health',
				'message'  => __( 'No indexing directive conflicts detected.', 'pressark' ),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Indexing Conflicts', 'pressark' ),
				'status'   => 'fail',
				'category' => 'indexing_health',
				'message'  => sprintf(
					__( 'Indexing conflict detected: %s.', 'pressark' ),
					implode( '; ', $conflicts )
				),
				'fix'      => __( 'Review robots directives and canonical URL for consistency.', 'pressark' ),
			);
		}

		// 4. Schema Markup (25 pts).
		$has_schema = false;
		if ( preg_match( '/<script[^>]+type\s*=\s*["\']application\/ld\+json["\']/i', $content ) ) {
			$has_schema = true;
		}
		if ( ! $has_schema ) {
			$schema_plugins = array(
				'wordpress-seo/wp-seo.php',
				'seo-by-rank-math/rank-math.php',
				'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'wp-seopress/seopress.php',
				'autodescription/autodescription.php',
			);
			foreach ( $schema_plugins as $plugin_file ) {
				if ( is_plugin_active( $plugin_file ) ) {
					$has_schema = true;
					break;
				}
			}
		}

		if ( $has_schema ) {
			$score   += 25;
			$checks[] = array(
				'name'     => __( 'Schema Markup', 'pressark' ),
				'status'   => 'pass',
				'category' => 'indexing_health',
				'message'  => __( 'Schema markup detected (via plugin or JSON-LD).', 'pressark' ),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Schema Markup', 'pressark' ),
				'status'   => 'fail',
				'category' => 'indexing_health',
				'message'  => __( 'No schema markup detected.', 'pressark' ),
				'fix'      => __( 'Consider adding JSON-LD schema or installing an SEO plugin.', 'pressark' ),
			);
		}

		return array(
			'score'        => min( 100, $score ),
			'checks'       => $checks,
			'observations' => array(),
			'quick_fixes'  => $quick_fixes,
		);
	}

	// ── Category: Search Appearance (30%, 0-100) ─────────────────────

	/**
	 * @return array{score: int, checks: array, observations: array, quick_fixes: array}
	 */
	private function check_search_appearance( int $post_id, \WP_Post $post, string $content, ?string $seo_plugin, string $plain_text ): array {
		$checks      = array();
		$quick_fixes = array();
		$score       = 0;

		$title     = $post->post_title;
		$site_name = get_bloginfo( 'name' );
		$slug      = $post->post_name;

		// 1. Meta Title (40 pts) — template-aware resolution chain.
		$meta_title   = PressArk_SEO_Resolver::read( $post_id, 'meta_title' );
		$title_source = 'missing';

		if ( $meta_title ) {
			$title_source  = 'custom';
			$display_title = $meta_title;
		} else {
			$rendered_title = PressArk_SEO_Resolver::rendered_title( $post_id );
			if ( '' !== $rendered_title ) {
				$title_source  = 'rendered';
				$display_title = $rendered_title;
			} elseif ( $seo_plugin ) {
				$title_source  = 'template';
				$display_title = '';
			} else {
				$display_title = $title . ' - ' . $site_name;
			}
		}

		if ( 'custom' === $title_source || 'rendered' === $title_source ) {
			$title_len = mb_strlen( $display_title );
			if ( $title_len >= 30 && $title_len <= 70 ) {
				$score   += 40;
				$checks[] = array(
					'name'     => __( 'Meta Title', 'pressark' ),
					'status'   => 'pass',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Meta title set (%d chars): "%s"', 'pressark' ), $title_len, $display_title ),
				);
			} else {
				$score   += 20;
				$checks[] = array(
					'name'     => __( 'Meta Title', 'pressark' ),
					'status'   => 'warning',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Meta title is %d chars. Ideal range: 30-70 characters (sweet spot: ~55).', 'pressark' ), $title_len ),
					'fix'      => __( 'Adjust meta title length to 30-70 characters.', 'pressark' ),
				);
			}
		} elseif ( 'template' === $title_source ) {
			$score   += 32;
			$plugin_label = PressArk_SEO_Resolver::label( $seo_plugin );
			$checks[] = array(
				'name'     => __( 'Meta Title', 'pressark' ),
				'status'   => 'info',
				'category' => 'search_appearance',
				'message'  => sprintf(
					/* translators: %s: SEO plugin name */
					__( 'Meta title handled by %s template. A custom title is recommended for best results.', 'pressark' ),
					$plugin_label
				),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Meta Title', 'pressark' ),
				'status'   => 'fail',
				'category' => 'search_appearance',
				'message'  => sprintf( __( 'No meta title set. Using default: "%s"', 'pressark' ), $display_title ),
				'fix'      => __( 'Set a custom meta title (30-70 characters).', 'pressark' ),
			);
			$suggested_title = mb_substr( $title . ' | ' . $site_name, 0, 60 );
			$quick_fixes[]   = array(
				'type'            => 'update_meta',
				'post_id'         => $post_id,
				'key'             => '_pressark_meta_title',
				'suggested_value' => $suggested_title,
			);
		}

		// 2. Meta Description (35 pts) — template-aware resolution chain.
		$meta_desc   = PressArk_SEO_Resolver::read( $post_id, 'meta_description' );
		$desc_source = 'missing';

		if ( $meta_desc ) {
			$desc_source  = 'custom';
			$display_desc = $meta_desc;
		} else {
			$rendered_desc = PressArk_SEO_Resolver::rendered_description( $post_id );
			if ( '' !== $rendered_desc ) {
				$desc_source  = 'rendered';
				$display_desc = $rendered_desc;
			} elseif ( $seo_plugin ) {
				$desc_source  = 'template';
				$display_desc = '';
			} else {
				$display_desc = '';
			}
		}

		if ( 'custom' === $desc_source || 'rendered' === $desc_source ) {
			$desc_len = mb_strlen( $display_desc );
			if ( $desc_len >= 50 && $desc_len <= 200 ) {
				$score   += 35;
				$checks[] = array(
					'name'     => __( 'Meta Description', 'pressark' ),
					'status'   => 'pass',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Meta description set (%d chars).', 'pressark' ), $desc_len ),
				);
			} else {
				$score   += 18;
				$checks[] = array(
					'name'     => __( 'Meta Description', 'pressark' ),
					'status'   => 'warning',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Meta description is %d chars. Ideal range: 50-200 characters (sweet spot: ~155).', 'pressark' ), $desc_len ),
					'fix'      => __( 'Adjust meta description to 50-200 characters.', 'pressark' ),
				);
			}
		} elseif ( 'template' === $desc_source ) {
			$score   += 28;
			$plugin_label = PressArk_SEO_Resolver::label( $seo_plugin );
			$checks[] = array(
				'name'     => __( 'Meta Description', 'pressark' ),
				'status'   => 'info',
				'category' => 'search_appearance',
				'message'  => sprintf(
					/* translators: %s: SEO plugin name */
					__( 'Meta description handled by %s template. A custom description is recommended for best results.', 'pressark' ),
					$plugin_label
				),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Meta Description', 'pressark' ),
				'status'   => 'fail',
				'category' => 'search_appearance',
				'message'  => __( 'No meta description found.', 'pressark' ),
				'fix'      => __( 'Add a meta description (50-200 characters).', 'pressark' ),
			);
			$suggested_desc = mb_substr( wp_trim_words( $plain_text, 25, '...' ), 0, 160 );
			if ( mb_strlen( $suggested_desc ) > 20 ) {
				$quick_fixes[] = array(
					'type'            => 'update_meta',
					'post_id'         => $post_id,
					'key'             => '_pressark_meta_description',
					'suggested_value' => $suggested_desc,
				);
			}
		}

		// 3. URL Slug (25 pts).
		if ( ! empty( $slug ) ) {
			$stop_words    = array( 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'a', 'an', 'is', 'it' );
			$slug_parts    = explode( '-', $slug );
			$has_stop      = ! empty( array_intersect( $slug_parts, $stop_words ) );
			$slug_too_long = mb_strlen( $slug ) > 75;

			if ( ! $has_stop && ! $slug_too_long ) {
				$score   += 25;
				$checks[] = array(
					'name'     => __( 'URL Slug', 'pressark' ),
					'status'   => 'pass',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Clean slug: /%s', 'pressark' ), $slug ),
				);
			} else {
				$score  += 10;
				$issues  = array();
				if ( $has_stop ) {
					$issues[] = __( 'contains stop words', 'pressark' );
				}
				if ( $slug_too_long ) {
					$issues[] = __( 'too long', 'pressark' );
				}
				$checks[] = array(
					'name'     => __( 'URL Slug', 'pressark' ),
					'status'   => 'warning',
					'category' => 'search_appearance',
					'message'  => sprintf( __( 'Slug "/%s" %s.', 'pressark' ), $slug, implode( ', ', $issues ) ),
					'fix'      => __( 'Shorten the slug and remove stop words.', 'pressark' ),
				);
			}
		} else {
			$checks[] = array(
				'name'     => __( 'URL Slug', 'pressark' ),
				'status'   => 'fail',
				'category' => 'search_appearance',
				'message'  => __( 'No slug set.', 'pressark' ),
				'fix'      => __( 'Set a clean, keyword-focused URL slug.', 'pressark' ),
			);
		}

		return array(
			'score'        => min( 100, $score ),
			'checks'       => $checks,
			'observations' => array(),
			'quick_fixes'  => $quick_fixes,
		);
	}

	// ── Category: Content Quality (25%, 0-100) ──────────────────────

	/**
	 * @return array{score: int, checks: array, observations: array, quick_fixes: array}
	 */
	private function check_content_quality( \WP_Post $post, string $content ): array {
		$checks      = array();
		$quick_fixes = array();
		$score       = 0;
		$title       = $post->post_title;

		// 1. H1 Tag (30 pts) — presence only, multiple H1 = info (not penalized).
		$h1_count = 0;
		$h1_text  = '';
		if ( preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $h1_matches ) ) {
			$h1_count = count( $h1_matches[1] );
			$h1_text  = wp_strip_all_tags( $h1_matches[1][0] ?? '' );
		}
		if ( preg_match_all( '/<!-- wp:heading \{"level":1\} -->/i', $content, $block_h1 ) ) {
			$h1_count += count( $block_h1[0] );
		}

		if ( $h1_count >= 1 ) {
			$score   += 30;
			$checks[] = array(
				'name'     => __( 'H1 Tag', 'pressark' ),
				'status'   => 'pass',
				'category' => 'content_quality',
				'message'  => sprintf( __( 'H1 tag found: "%s"', 'pressark' ), $h1_text ?: $title ),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'H1 Tag', 'pressark' ),
				'status'   => 'fail',
				'category' => 'content_quality',
				'message'  => __( 'No H1 tag found in content.', 'pressark' ),
				'fix'      => __( 'Add one H1 heading to the page content.', 'pressark' ),
			);
		}

		// 2. Heading Hierarchy (25 pts).
		preg_match_all( '/<h([1-6])[^>]*>/i', $content, $heading_matches );
		if ( ! empty( $heading_matches[1] ) ) {
			$levels         = array_map( 'intval', $heading_matches[1] );
			$good_hierarchy = true;
			for ( $i = 1, $count = count( $levels ); $i < $count; $i++ ) {
				if ( $levels[ $i ] > $levels[ $i - 1 ] + 1 ) {
					$good_hierarchy = false;
					break;
				}
			}
			if ( $good_hierarchy ) {
				$score   += 25;
				$checks[] = array(
					'name'     => __( 'Heading Hierarchy', 'pressark' ),
					'status'   => 'pass',
					'category' => 'content_quality',
					'message'  => sprintf( __( 'Heading hierarchy is correct (H%s).', 'pressark' ), implode( '→H', $levels ) ),
				);
			} else {
				$score   += 10;
				$checks[] = array(
					'name'     => __( 'Heading Hierarchy', 'pressark' ),
					'status'   => 'warning',
					'category' => 'content_quality',
					'message'  => __( 'Heading levels skip (e.g., H1 to H3 without H2).', 'pressark' ),
					'fix'      => __( 'Ensure headings follow H1→H2→H3 order without gaps.', 'pressark' ),
				);
			}
		} else {
			$checks[] = array(
				'name'     => __( 'Heading Hierarchy', 'pressark' ),
				'status'   => 'fail',
				'category' => 'content_quality',
				'message'  => __( 'No headings found in content.', 'pressark' ),
				'fix'      => __( 'Add structured headings (H1, H2, H3) to organize content.', 'pressark' ),
			);
		}

		// 3. Image Alt Text (25 pts).
		preg_match_all( '/<img[^>]*>/i', $content, $img_matches );
		$total_images = count( $img_matches[0] ?? array() );
		$missing_alt  = 0;

		if ( $total_images > 0 ) {
			foreach ( $img_matches[0] as $img ) {
				if ( ! preg_match( '/alt\s*=\s*["\'][^"\']+["\']/i', $img ) ) {
					$missing_alt++;
				}
			}
			if ( 0 === $missing_alt ) {
				$score   += 25;
				$checks[] = array(
					'name'     => __( 'Image Alt Text', 'pressark' ),
					'status'   => 'pass',
					'category' => 'content_quality',
					'message'  => sprintf( __( 'All %d images have alt text.', 'pressark' ), $total_images ),
				);
			} else {
				$checks[] = array(
					'name'     => __( 'Image Alt Text', 'pressark' ),
					'status'   => 'fail',
					'category' => 'content_quality',
					'message'  => sprintf( __( '%d of %d images missing alt text.', 'pressark' ), $missing_alt, $total_images ),
					'fix'      => __( 'Add descriptive alt text to all images.', 'pressark' ),
				);
			}
		} else {
			$score   += 12;
			$checks[] = array(
				'name'     => __( 'Image Alt Text', 'pressark' ),
				'status'   => 'warning',
				'category' => 'content_quality',
				'message'  => __( 'No images in content. Consider adding relevant images.', 'pressark' ),
			);
		}

		// 4. Internal Links (20 pts).
		$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal_count = 0;
		preg_match_all( '/<a[^>]+href\s*=\s*["\']([^"\']+)["\']/i', $content, $link_matches );
		foreach ( $link_matches[1] ?? array() as $href ) {
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( null === $link_host || $link_host === $site_host ) {
				$internal_count++;
			}
		}
		if ( $internal_count > 0 ) {
			$score   += 20;
			$checks[] = array(
				'name'     => __( 'Internal Links', 'pressark' ),
				'status'   => 'pass',
				'category' => 'content_quality',
				'message'  => sprintf( __( '%d internal link(s) found.', 'pressark' ), $internal_count ),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Internal Links', 'pressark' ),
				'status'   => 'fail',
				'category' => 'content_quality',
				'message'  => __( 'No internal links found.', 'pressark' ),
				'fix'      => __( 'Add links to related content on your site.', 'pressark' ),
			);
		}

		return array(
			'score'        => min( 100, $score ),
			'checks'       => $checks,
			'observations' => array(),
			'quick_fixes'  => $quick_fixes,
		);
	}

	// ── Category: Social Sharing (15%, 0-100) ───────────────────────

	/**
	 * @return array{score: int, checks: array, observations: array, quick_fixes: array}
	 */
	private function check_social_sharing( int $post_id ): array {
		$checks      = array();
		$quick_fixes = array();
		$score       = 0;

		// Open Graph Tags (100 pts within category).
		$og_title = PressArk_SEO_Resolver::read( $post_id, 'og_title' );
		$og_desc  = PressArk_SEO_Resolver::read( $post_id, 'og_description' );
		$og_image = PressArk_SEO_Resolver::read( $post_id, 'og_image' );

		$og_set = 0;
		if ( $og_title ) {
			$og_set++;
		}
		if ( $og_desc ) {
			$og_set++;
		}
		if ( $og_image || has_post_thumbnail( $post_id ) ) {
			$og_set++;
		}

		if ( 3 === $og_set ) {
			$score   += 100;
			$checks[] = array(
				'name'     => __( 'Open Graph Tags', 'pressark' ),
				'status'   => 'pass',
				'category' => 'social_sharing',
				'message'  => __( '3/3 Open Graph tags set.', 'pressark' ),
			);
		} elseif ( 2 === $og_set ) {
			$score   += 65;
			$checks[] = array(
				'name'     => __( 'Open Graph Tags', 'pressark' ),
				'status'   => 'warning',
				'category' => 'social_sharing',
				'message'  => __( '2/3 Open Graph tags set.', 'pressark' ),
				'fix'      => __( 'Add og:title, og:description, and og:image for social sharing.', 'pressark' ),
			);
		} elseif ( 1 === $og_set ) {
			$score   += 30;
			$checks[] = array(
				'name'     => __( 'Open Graph Tags', 'pressark' ),
				'status'   => 'warning',
				'category' => 'social_sharing',
				'message'  => __( '1/3 Open Graph tags set.', 'pressark' ),
				'fix'      => __( 'Add og:title, og:description, and og:image for social sharing.', 'pressark' ),
			);
		} else {
			$checks[] = array(
				'name'     => __( 'Open Graph Tags', 'pressark' ),
				'status'   => 'fail',
				'category' => 'social_sharing',
				'message'  => __( 'No Open Graph tags set.', 'pressark' ),
				'fix'      => __( 'Add Open Graph tags for better social media previews.', 'pressark' ),
			);
		}

		return array(
			'score'        => min( 100, $score ),
			'checks'       => $checks,
			'observations' => array(),
			'quick_fixes'  => $quick_fixes,
		);
	}

	// ── Observations (info-only, not scored) ─────────────────────────

	/**
	 * Collect demoted checks that are informational only.
	 *
	 * These appear in both checks[] (backward compat) and observations[].
	 *
	 * @return array Observation items with status:'info'.
	 */
	private function collect_observations( int $post_id, \WP_Post $post, string $content, string $plain_text ): array {
		$observations = array();

		// 1. Content Length (demoted — arbitrary thresholds).
		$word_count = str_word_count( $plain_text );
		if ( $word_count >= 1000 ) {
			$observations[] = array(
				'name'     => __( 'Content Length', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => sprintf( __( 'Content has %d words.', 'pressark' ), $word_count ),
			);
		} elseif ( $word_count >= 300 ) {
			$observations[] = array(
				'name'     => __( 'Content Length', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => sprintf( __( 'Content has %d words. Longer content may perform better for competitive topics.', 'pressark' ), $word_count ),
			);
		} else {
			$observations[] = array(
				'name'     => __( 'Content Length', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => sprintf( __( 'Content has %d words. Consider whether more depth is needed for this topic.', 'pressark' ), $word_count ),
			);
		}

		// 2. External Links (demoted — not a ranking factor).
		$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$external_count = 0;
		preg_match_all( '/<a[^>]+href\s*=\s*["\']([^"\']+)["\']/i', $content, $link_matches );
		foreach ( $link_matches[1] ?? array() as $href ) {
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( null !== $link_host && $link_host !== $site_host ) {
				$external_count++;
			}
		}
		if ( $external_count > 0 ) {
			$observations[] = array(
				'name'     => __( 'External Links', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => sprintf( __( '%d external link(s) found.', 'pressark' ), $external_count ),
			);
		} else {
			$observations[] = array(
				'name'     => __( 'External Links', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => __( 'No external links. Consider linking to authoritative sources where relevant.', 'pressark' ),
			);
		}

		// 3. Featured Image (demoted — not an SEO factor).
		if ( has_post_thumbnail( $post_id ) ) {
			$observations[] = array(
				'name'     => __( 'Featured Image', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => __( 'Featured image is set.', 'pressark' ),
			);
		} else {
			$observations[] = array(
				'name'     => __( 'Featured Image', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => __( 'No featured image set. Consider adding one for social sharing and visual appeal.', 'pressark' ),
			);
		}

		// 4. Multiple H1s (demoted — HTML5 allows multiple).
		$h1_count = 0;
		if ( preg_match_all( '/<h1[^>]*>(.*?)<\/h1>/is', $content, $h1_matches ) ) {
			$h1_count = count( $h1_matches[1] );
		}
		if ( preg_match_all( '/<!-- wp:heading \{"level":1\} -->/i', $content, $block_h1 ) ) {
			$h1_count += count( $block_h1[0] );
		}
		if ( $h1_count > 1 ) {
			$observations[] = array(
				'name'     => __( 'Multiple H1 Tags', 'pressark' ),
				'status'   => 'info',
				'category' => 'observation',
				'message'  => sprintf( __( '%d H1 tags found. HTML5 allows multiple, but a single H1 is conventional.', 'pressark' ), $h1_count ),
			);
		}

		return $observations;
	}

	// ── Private Helpers ──────────────────────────────────────────────

	/**
	 * Convert a numeric score (0-100) to a letter grade.
	 */
	private function score_to_grade( int $score ): string {
		return match ( true ) {
			$score >= 90 => 'A',
			$score >= 80 => 'B',
			$score >= 70 => 'C',
			$score >= 60 => 'D',
			default      => 'F',
		};
	}
}
