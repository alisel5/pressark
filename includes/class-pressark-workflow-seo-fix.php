<?php
/**
 * PressArk SEO Fix Workflow — deterministic SEO optimization pipeline.
 *
 * Handles: "fix/improve SEO", "meta title/description", "improve search rankings"
 *
 * Phase flow:
 *   1. discover        — Run analyze_seo (single page or site-wide).
 *   2. select_target   — Pick pages with worst SEO scores (up to 5).
 *   3. gather_context  — Read content for each target page.
 *   4. plan            — AI generates optimized meta_title + meta_description.
 *   5. preview         — Stage fix_seo via PressArk_Preview.
 *   6. apply           — Handled by PressArk_Preview::keep().
 *   7. verify          — Read post meta back, confirm fields were set.
 *
 * Total AI calls: 1 (vs agent's typical 3-4 rounds).
 *
 * @package PressArk
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Workflow_SEO_Fix extends PressArk_Workflow_Runner {

	/**
	 * Tool groups used by this workflow.
	 *
	 * @return array
	 */
	protected function tool_groups(): array {
		return array( 'seo', 'content' );
	}

	// ── Phase 1: Discover ─────────────────────────────────────────

	/**
	 * Run SEO analysis on the target (specific page or site-wide).
	 *
	 * @return array
	 */
	protected function phase_discover(): array {
		$post_id = (int) ( $this->state['post_id'] ?? 0 );
		$message = (string) ( $this->state['message'] ?? '' );

		// Parse force flag from state or user message.
		if ( ! isset( $this->state['force'] ) ) {
			$this->state['force'] = (bool) preg_match( '/\bforce\b/i', $message );
		}

		if ( $post_id <= 0 && preg_match( '/\bpost_id\s*=\s*(\d+)\b/i', $message, $match ) ) {
			$post_id = absint( $match[1] );
		}

		$is_site_wide = $this->explicitly_requests_sitewide_seo( $message );

		if ( $post_id > 0 ) {
			$seo_result = $this->exec_read( 'analyze_seo', array( 'post_id' => $post_id ) );
		} elseif ( $is_site_wide ) {
			$seo_result = $this->exec_read( 'analyze_seo', array( 'post_id' => 'all' ) );
		} else {
			// Try to find the content from the message.
			$search = $this->exec_read( 'search_content', array(
				'query' => $this->state['message'],
				'limit' => 5,
			) );

			$found_id = 0;
			if ( ! empty( $search['data'] ) ) {
				$found_id = (int) ( $search['data'][0]['id'] ?? $search['data'][0]['post_id'] ?? 0 );
			}

			if ( $found_id > 0 ) {
				$post_id    = $found_id;
				$seo_result = $this->exec_read( 'analyze_seo', array( 'post_id' => $post_id ) );
			} else {
				return $this->bad_retrieval(
					'I could not determine which post or page to optimize for SEO. Open the target content or specify it explicitly instead of widening the change to the whole site.'
				);
			}
		}

		if ( empty( $seo_result['success'] ) ) {
			return $this->tool_failure( $seo_result['message'] ?? 'SEO analysis failed.' );
		}

		return array(
			'seo_data'       => $seo_result['data'] ?? $seo_result,
			'analysis_scope' => $is_site_wide ? 'site' : $post_id,
		);
	}

	// ── Phase 2: Select Target ────────────────────────────────────

	/**
	 * From the SEO analysis, identify pages that need fixes.
	 *
	 * Single-page scope: target is already known.
	 * Site-wide: extract pages with worst SEO scores (up to 5).
	 *
	 * @return array
	 */
	protected function phase_select_target(): array {
		$scope    = $this->state['analysis_scope'];
		$seo_data = $this->state['seo_data'];

		// Single page scope.
		if ( 'site' !== $scope && (int) $scope > 0 ) {
			$pid = (int) $scope;

			// Skip noindex pages.
			if ( PressArk_SEO_Resolver::is_noindex( $pid ) ) {
				return array( '__return' => array_merge(
					array(
						'type'    => 'final_response',
						'message' => sprintf(
							'"%s" is set to noindex — search engines are instructed not to index it. Optimizing its meta would have no effect. Remove the noindex directive first if you want this page indexed.',
							get_the_title( $pid )
						),
					),
					$this->telemetry_fields()
				) );
			}

			// Skip cross-canonicalized pages.
			$canonical = PressArk_SEO_Resolver::read( $pid, 'canonical' );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( get_permalink( $pid ) ) ) {
				return array( '__return' => array_merge(
					array(
						'type'    => 'final_response',
						'message' => sprintf(
							'"%s" has a canonical URL pointing to %s — search engines treat the canonical target as the authoritative page. Optimizing meta on this page would have no effect.',
							get_the_title( $pid ),
							$canonical
						),
					),
					$this->telemetry_fields()
				) );
			}

			return array( 'target' => array(
				'post_ids' => array( $pid ),
				'issues'   => $seo_data,
			) );
		}

		// Site-wide: extract pages needing fixes.
		$pages_with_issues = array();

		// The SEO scanner may return various structures.
		$pages = $seo_data['pages'] ?? $seo_data['results'] ?? array();
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		foreach ( $pages as $page ) {
			$pid    = (int) ( $page['post_id'] ?? $page['id'] ?? 0 );
			$issues = $page['issues'] ?? $page['quick_fixes'] ?? array();

			if ( $pid > 0 && ! empty( $issues ) ) {
				$pages_with_issues[] = array(
					'post_id' => $pid,
					'title'   => $page['title'] ?? get_the_title( $pid ),
					'issues'  => $issues,
				);
			}
		}

		// Filter out noindex and cross-canonicalized pages.
		$pages_with_issues = array_filter( $pages_with_issues, function ( $page ) {
			$pid = (int) $page['post_id'];
			if ( PressArk_SEO_Resolver::is_noindex( $pid ) ) {
				return false;
			}
			$canonical = PressArk_SEO_Resolver::read( $pid, 'canonical' );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( get_permalink( $pid ) ) ) {
				return false;
			}
			return true;
		} );
		$pages_with_issues = array_values( $pages_with_issues );

		// Sort by most issues descending, take top 5.
		usort( $pages_with_issues, function ( $a, $b ) {
			return count( $b['issues'] ) - count( $a['issues'] );
		} );
		$top_pages = array_slice( $pages_with_issues, 0, 5 );

		if ( empty( $top_pages ) ) {
			// No issues found — return early with a success message.
			return array( '__return' => array_merge(
				array(
					'type'    => 'final_response',
					'message' => 'SEO analysis complete. No issues found that need fixing. Your pages are looking good!',
				),
				$this->telemetry_fields()
			) );
		}

		return array( 'target' => array(
			'post_ids' => array_column( $top_pages, 'post_id' ),
			'issues'   => $top_pages,
		) );
	}

	// ── Phase 3: Gather Context ───────────────────────────────────

	/**
	 * Read content of each targeted page for AI to generate fixes.
	 *
	 * @return array
	 */
	protected function phase_gather_context(): array {
		$post_ids  = $this->state['target']['post_ids'] ?? array();
		$page_data = array();

		foreach ( $post_ids as $pid ) {
			// v3.7.0: Downgraded from 'full' to 'structured'. SEO fixes only
			// need title, heading outline, section summaries, and SEO metadata —
			// raw HTML is not consumed. Saves ~2-3K tokens per page.
			$page_data[ $pid ] = $this->exec_read( 'read_content', array( 'post_id' => $pid, 'mode' => 'structured' ) );
			if ( empty( $page_data[ $pid ]['success'] ) ) {
				return $this->tool_failure( $page_data[ $pid ]['message'] ?? 'Failed to read page content for SEO planning.' );
			}

			// Read current meta state so phase_plan knows which fields need filling.
			$current_meta = PressArk_SEO_Resolver::read_all( $pid );
			$rendered     = array(
				'meta_title'       => PressArk_SEO_Resolver::rendered_title( $pid ),
				'meta_description' => PressArk_SEO_Resolver::rendered_description( $pid ),
			);

			$needs_fill = array();
			foreach ( array( 'meta_title', 'meta_description' ) as $f ) {
				$has_manual   = '' !== ( $current_meta[ $f ] ?? '' );
				$has_template = '' !== ( $rendered[ $f ] ?? '' );
				$needs_fill[ $f ] = ! $has_manual && ! $has_template;
			}

			$page_data[ $pid ]['current_meta'] = $current_meta;
			$page_data[ $pid ]['needs_fill']   = $needs_fill;
		}

		return array( 'page_data' => $page_data );
	}

	// ── Phase 4: Plan ─────────────────────────────────────────────

	/**
	 * AI generates SEO fixes (meta titles, meta descriptions) for each page.
	 *
	 * @return array
	 */
	protected function phase_plan(): array {
		$target    = $this->state['target'] ?? array();
		$page_data = $this->state['page_data'] ?? array();
		$issues    = $target['issues'] ?? array();
		$force     = ! empty( $this->state['force'] );

		// Build concise context for the AI.
		$pages_for_ai = array();
		foreach ( $target['post_ids'] ?? array() as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			// Find issues for this page.
			$page_issues = array();
			if ( is_array( $issues ) ) {
				foreach ( $issues as $issue_set ) {
					if ( is_array( $issue_set ) && (int) ( $issue_set['post_id'] ?? 0 ) === $pid ) {
						$page_issues = $issue_set['issues'] ?? array();
						break;
					}
				}
			}

			// Determine which fields need generation.
			$needs_fill   = $page_data[ $pid ]['needs_fill'] ?? array( 'meta_title' => true, 'meta_description' => true );
			$current_meta = $page_data[ $pid ]['current_meta'] ?? array();

			$fields_to_generate = array();
			foreach ( array( 'meta_title', 'meta_description' ) as $f ) {
				if ( $force || ! empty( $needs_fill[ $f ] ) ) {
					$fields_to_generate[] = $f;
				}
			}

			// Skip pages where all fields are already filled (unless force).
			if ( empty( $fields_to_generate ) ) {
				continue;
			}

			$pages_for_ai[] = array(
				'post_id'                  => $pid,
				'title'                    => $post->post_title,
				'excerpt'                  => wp_trim_words( $post->post_content, 50 ),
				'issues'                   => $page_issues,
				'fields_to_generate'       => $fields_to_generate,
				'current_meta_title'       => $current_meta['meta_title'] ?? '',
				'current_meta_description' => $current_meta['meta_description'] ?? '',
			);
		}

		// If no pages have fields to generate, return early.
		if ( empty( $pages_for_ai ) ) {
			return array( '__return' => array_merge(
				array(
					'type'    => 'final_response',
					'message' => 'All targeted pages already have meta titles and descriptions (manual or template-driven). Use force=true to regenerate them.',
				),
				$this->telemetry_fields()
			) );
		}

		// Build site/brand context for AI.
		$site_name     = get_bloginfo( 'name' );
		$site_tagline  = get_bloginfo( 'description' );
		$brand_summary = ( new PressArk_Site_Profile() )->get_ai_summary();

		$brand_block = "## Site Context\n"
			. "Site: {$site_name}\n"
			. ( $site_tagline ? "Tagline: {$site_tagline}\n" : '' )
			. ( $brand_summary ? "Brand profile: {$brand_summary}\n" : '' )
			. "\n";

		$ai_result = $this->ai_call(
			$brand_block
			. "You are an SEO specialist. For each page below, generate optimized meta fields listed in its `fields_to_generate` array.\n\n"
			. "Rules:\n"
			. "- Meta title: 30-70 characters (target ~55), include primary keyword near the start\n"
			. "- Meta description: 50-200 characters (target ~155), compelling, include call to action\n"
			. "- Fix the identified issues\n"
			. "- Only generate fields listed in `fields_to_generate` for each page. Omit fields not in that list.\n"
			. "- Incorporate the site name naturally where appropriate (e.g., \"Site Name | Page Topic\")\n"
			. "- Match the brand's tone and voice described above\n"
			. "- Each meta title and meta description must be unique across all pages in the batch. Never duplicate.\n\n"
			. "Respond with a JSON array of objects, one per page. Each object:\n"
			. '{ "post_id": <int>, "meta_title": "...", "meta_description": "..." }' . "\n"
			. "Only include keys for fields you were asked to generate. Respond ONLY with the JSON array, no explanation or markdown fences.",
			$pages_for_ai,
			array(),
			array(
				'phase' => 'final_synthesis',
				'effort_budget' => 'high',
				'schema_mode' => 'strict',
				'deliverable_schema' => array(
					'type' => 'array',
					'item_shape' => array(
						'post_id' => 'int',
						'meta_title' => 'string',
						'meta_description' => 'string',
					),
					'additionalProperties' => false,
					'min_items' => count( $pages_for_ai ),
				),
				'stop_conditions' => array(
					'each page has one compliant meta title and meta description',
					'a required field cannot be supported by the page context',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the strict JSON array',
				),
			)
		);

		if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
			return $this->phase_error(
				'Could not generate SEO fixes because the AI request failed.',
				(string) $ai_result['failure_class']
			);
		}

		$decoded = $this->decode_json_response( $ai_result['text'], 'list' );
		if ( ! empty( $decoded['error'] ) ) {
			if ( ! empty( $ai_result['failure_class'] ) ) {
				return $this->phase_error(
					'Could not generate SEO fixes because the model response was incomplete.',
					(string) $ai_result['failure_class']
				);
			}
			return $this->validation_failure( 'Could not generate SEO fixes. ' . $decoded['error'] );
		}

		$plan = $decoded['data'];
		$target_ids = array_map( 'intval', $target['post_ids'] ?? array() );
		foreach ( $plan as $index => $fix ) {
			$fix_id = (int) ( $fix['post_id'] ?? 0 );
			if ( ! in_array( $fix_id, $target_ids, true ) ) {
				return $this->validation_failure(
					sprintf( 'SEO fix item %d referenced an unexpected post_id.', $index + 1 )
				);
			}
		}

		// Deduplicate: reject AI responses with duplicate titles or descriptions across pages.
		$seen_titles = array();
		$seen_descs  = array();
		$dupes       = array();

		foreach ( $plan as $fix ) {
			$t = strtolower( trim( $fix['meta_title'] ?? '' ) );
			$d = strtolower( trim( $fix['meta_description'] ?? '' ) );

			if ( '' !== $t ) {
				if ( isset( $seen_titles[ $t ] ) ) {
					$dupes[] = sprintf( 'Duplicate meta_title across post %d and %d', $seen_titles[ $t ], (int) $fix['post_id'] );
				}
				$seen_titles[ $t ] = (int) $fix['post_id'];
			}

			if ( '' !== $d ) {
				if ( isset( $seen_descs[ $d ] ) ) {
					$dupes[] = sprintf( 'Duplicate meta_description across post %d and %d', $seen_descs[ $d ], (int) $fix['post_id'] );
				}
				$seen_descs[ $d ] = (int) $fix['post_id'];
			}
		}

		if ( ! empty( $dupes ) ) {
			return $this->validation_failure(
				'AI proposed duplicate meta across pages: ' . implode( '; ', $dupes )
			);
		}

		return array( 'plan' => $plan );
	}

	// ── Phase 5: Preview ──────────────────────────────────────────

	/**
	 * Stage SEO fixes via PressArk_Preview.
	 *
	 * fix_seo is a 'preview'-classified tool in the catalog, so it goes
	 * through the preview system.
	 *
	 * @return array
	 */
	protected function phase_preview(): array {
		$plan = $this->state['plan'];

		$force = ! empty( $this->state['force'] );

		$tool_calls = array(
			array(
				'name'      => 'fix_seo',
				'arguments' => array(
					'fixes' => $plan,
					'force' => $force,
				),
			),
		);

		$page_count = count( $plan );
		if ( 1 === $page_count ) {
			$target_title = get_the_title( (int) ( $plan[0]['post_id'] ?? 0 ) );
			$summary      = $target_title
				? sprintf( 'I\'ve prepared SEO fixes for "%s". Review the changes and approve to apply.', $target_title )
				: 'I\'ve prepared SEO fixes for the scoped target. Review the changes and approve to apply.';
		} else {
			$summary = sprintf(
				'I\'ve prepared SEO fixes for %d page%s. Review the changes and approve to apply.',
				$page_count,
				$page_count > 1 ? 's' : ''
			);
		}

		return $this->build_preview_response( $tool_calls, $summary );
	}

	private function explicitly_requests_sitewide_seo( string $message ): bool {
		$message = strtolower( $message );
		if ( '' === $message || false === strpos( $message, 'seo' ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:site[-\s]?wide|entire site|whole site|all pages|all posts|every page|every post|across the site)\b/',
			$message
		);
	}

	// ── Phase 7: Verify ───────────────────────────────────────────

	/**
	 * Read post meta back after apply, confirm meta fields were set.
	 *
	 * @return array
	 */
	protected function phase_verify(): array {
		$plan   = $this->state['plan'] ?? array();
		$checks = array();

		foreach ( $plan as $fix ) {
			$pid = (int) ( $fix['post_id'] ?? 0 );
			if ( ! $pid ) {
				continue;
			}

			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}

			// Check meta was set by reading from the active SEO plugin.
			$meta_title = PressArk_SEO_Resolver::read( $pid, 'meta_title' );
			$meta_desc  = PressArk_SEO_Resolver::read( $pid, 'meta_description' );

			$title_ok = ! empty( $meta_title );
			$desc_ok  = ! empty( $meta_desc );

			$checks[] = sprintf(
				'"%s": title %s, description %s',
				$post->post_title,
				$title_ok ? 'set' : 'not found',
				$desc_ok ? 'set' : 'not found'
			);
		}

		return array(
			'summary' => 'SEO fixes applied. Verification: '
			           . ( $checks ? implode( '; ', $checks ) : 'completed' ),
		);
	}
}
