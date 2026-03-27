<?php
/**
 * PressArk Content Edit Workflow — deterministic edit pipeline.
 *
 * Handles: "edit/update/improve/rewrite the content/post/page..."
 *
 * Phase flow:
 *   1. discover        — Resolve target post (screen context or search).
 *   2. select_target   — Single candidate → use it. Multiple → AI picks.
 *   3. gather_context  — Read light, then structured, then full only when needed.
 *   4. plan            — AI generates JSON field changes (title, content, excerpt, slug).
 *   5. preview         — Stage edit_content via PressArk_Preview.
 *   6. apply           — Handled by PressArk_Preview::keep().
 *   7. verify          — Read content back, compare against plan.
 *
 * Total AI calls: 1-2 (vs agent's typical 3-5 rounds).
 *
 * @package PressArk
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Workflow_Content_Edit extends PressArk_Workflow_Runner {

	/**
	 * Tool groups used by this workflow.
	 *
	 * @return array
	 */
	protected function tool_groups(): array {
		return array( 'content', 'generation' );
	}

	// ── Phase 1: Discover ─────────────────────────────────────────

	/**
	 * Find candidate posts to edit.
	 *
	 * If user is on a post editor screen, the post_id is already known.
	 * Otherwise, search by the user's message.
	 *
	 * @return array
	 */
	protected function phase_discover(): array {
		$post_id = $this->state['post_id'] ?? 0;

		// If user is on a post editor screen, we already have the target.
		if ( $post_id > 0 ) {
			return array( 'candidates' => array( $post_id ) );
		}

		// Search by message content.
		$search_result = $this->exec_read( 'search_content', array(
			'query' => $this->state['message'],
			'limit' => 5,
		) );

		$candidates = array();
		if ( ! empty( $search_result['data'] ) ) {
			foreach ( $search_result['data'] as $item ) {
				$cid = $item['id'] ?? $item['post_id'] ?? 0;
				if ( $cid ) {
					$candidates[] = (int) $cid;
				}
			}
		}

		// Fallback: list posts.
		if ( empty( $candidates ) ) {
			$list_result = $this->exec_read( 'list_posts', array(
				'post_type' => 'any',
				'search'    => $this->state['message'],
			) );
			if ( ! empty( $list_result['data'] ) ) {
				foreach ( $list_result['data'] as $item ) {
					$cid = $item['id'] ?? $item['post_id'] ?? 0;
					if ( $cid ) {
						$candidates[] = (int) $cid;
					}
				}
			}
		}

		if ( empty( $candidates ) ) {
			return $this->bad_retrieval(
				'Could not find any content matching your request. Please specify a post title or navigate to the post editor.'
			);
		}

		return array( 'candidates' => $candidates );
	}

	// ── Phase 2: Select Target ────────────────────────────────────

	/**
	 * Pick the best candidate from the list.
	 *
	 * Single candidate: use it directly (no AI call).
	 * Multiple candidates: ask AI to pick the most relevant one.
	 *
	 * @return array
	 */
	protected function phase_select_target(): array {
		$candidates = $this->state['candidates'] ?? array();
		$ranked     = $this->rank_candidates( $candidates );

		if ( 1 === count( $ranked ) ) {
			$post = $ranked[0]['post'];
			return array( 'target' => array(
				'post_id' => $ranked[0]['id'],
				'title'   => $post ? $post->post_title : '',
				'type'    => $post ? $post->post_type : 'post',
			) );
		}

		if ( empty( $ranked ) ) {
			return $this->bad_retrieval( 'I found candidate IDs, but could not read enough detail to identify the right post.' );
		}

		$top = $ranked[0];
		$runner_up = $ranked[1] ?? null;
		$score_gap = $runner_up ? ( $top['score'] - $runner_up['score'] ) : $top['score'];

		if ( $top['score'] >= 80 || $score_gap >= 20 ) {
			return array( 'target' => array(
				'post_id' => $top['id'],
				'title'   => $top['post']->post_title,
				'type'    => $top['post']->post_type,
			) );
		}

		// Only escalate when the deterministic ranking remains ambiguous.
		$candidate_info = array();
		foreach ( array_slice( $ranked, 0, 5 ) as $entry ) {
			$post = $entry['post'];
			$candidate_info[] = array(
				'post_id' => $entry['id'],
				'title'   => $post->post_title,
				'type'    => $post->post_type,
				'status'  => $post->post_status,
				'excerpt' => wp_trim_words( $post->post_content, 30 ),
				'match_score' => $entry['score'],
			);
		}

		$ai_result = $this->ai_call(
			'The user wants to edit content. Their request: "' . $this->state['message'] . "\"\n\n"
			. "Select the most relevant post to edit. Respond with ONLY the post_id number, nothing else.",
			$candidate_info,
			array(),
			array(
				'phase' => 'ambiguity_resolution',
				'effort_budget' => 'high',
				'stop_conditions' => array(
					'you can select one candidate with clear evidence from the shortlist',
					'the target remains ambiguous after comparing the top candidates',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the winning post_id',
				),
			)
		);

		$selected_id = (int) trim( $ai_result['text'] );
		if ( ! in_array( $selected_id, wp_list_pluck( $ranked, 'id' ), true ) ) {
			if ( $score_gap >= 8 ) {
				$selected_id = $top['id'];
			} else {
				return $this->bad_retrieval(
					'I found multiple similar posts and could not safely identify the right one. Please specify the title or open the target in the editor.'
				);
			}
		}

		$selected_post = get_post( $selected_id );

		return array( 'target' => array(
			'post_id' => $selected_id,
			'title'   => $selected_post ? $selected_post->post_title : '',
			'type'    => $selected_post ? $selected_post->post_type : 'post',
		) );
	}

	// ── Phase 3: Gather Context ───────────────────────────────────

	/**
	 * Read light first, then structured, and fetch full HTML only when the
	 * request needs body-level editing context.
	 *
	 * @return array
	 */
	protected function phase_gather_context(): array {
		$post_id = $this->state['target']['post_id'];

		// Detect Elementor page.
		$is_elementor = get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';

		if ( $is_elementor ) {
			$elementor_result = $this->exec_read( 'elementor_read_page', array(
				'post_id' => $post_id,
			) );

			if ( empty( $elementor_result['success'] ) ) {
				return $this->tool_failure( $elementor_result['message'] ?? 'Failed to read Elementor page structure.' );
			}

			$brand = $this->exec_read( 'get_brand_profile', array() );

			return array(
				'editing'        => array( 'is_elementor' => true ),
				'elementor_page' => $elementor_result['data'] ?? $elementor_result,
				'brand_profile'  => $brand['data'] ?? $brand['message'] ?? '',
			);
		}

		$light_result = $this->exec_read( 'read_content', array(
			'post_id' => $post_id,
			'mode'    => 'light',
		) );

		if ( empty( $light_result['success'] ) ) {
			return $this->tool_failure( $light_result['message'] ?? 'Failed to read content.' );
		}

		$structured_result = $this->exec_read( 'read_content', array(
			'post_id' => $post_id,
			'mode'    => 'structured',
		) );

		if ( empty( $structured_result['success'] ) ) {
			return $this->tool_failure( $structured_result['message'] ?? 'Failed to read structured content.' );
		}

		$full_result = null;
		if ( $this->needs_full_content() ) {
			$full_result = $this->exec_read( 'read_content', array(
				'post_id' => $post_id,
				'mode'    => 'full',
			) );

			if ( empty( $full_result['success'] ) ) {
				return $this->tool_failure( $full_result['message'] ?? 'Failed to read full content.' );
			}
		}

		// Read brand profile for tone/voice matching.
		$brand = $this->exec_read( 'get_brand_profile', array() );

		return array(
			'content_light' => $light_result,
			'content'       => $structured_result,
			'content_full'  => $full_result,
			'brand_profile' => $brand['data'] ?? $brand['message'] ?? '',
		);
	}

	// ── Phase 4: Plan ─────────────────────────────────────────────

	/**
	 * AI plans what to change, constrained to content editing.
	 *
	 * Scoped prompt: given current content and user request, generate
	 * a JSON object of field changes.
	 *
	 * @return array
	 */
	protected function phase_plan(): array {
		if ( ! empty( $this->state['editing']['is_elementor'] ) ) {
			return $this->phase_plan_elementor();
		}

		$post_id       = $this->state['target']['post_id'];
		$post          = get_post( $post_id );
		$content_light = $this->state['content_light']['data'] ?? array();
		$content_data  = $this->state['content']['data'] ?? array();
		$content_full  = $this->state['content_full']['data'] ?? array();

		if ( ! $post ) {
			return $this->bad_retrieval( 'Post not found.' );
		}

		$brand_text = '';
		if ( ! empty( $this->state['brand_profile'] ) ) {
			$brand_text = is_array( $this->state['brand_profile'] )
				? wp_json_encode( $this->state['brand_profile'] )
				: (string) $this->state['brand_profile'];
		}

		$content_snapshot = $this->build_content_snapshot( $content_light, $content_data, $content_full );

		$ai_result = $this->ai_call(
			"You are editing a WordPress post. The user requested: \"{$this->state['message']}\"\n\n"
			. "Current post:\n"
			. "- Title: {$post->post_title}\n"
			. "- Status: {$post->post_status}\n"
			. "- Type: {$post->post_type}\n\n"
			. $content_snapshot
			. ( $brand_text ? "Brand/tone guidance:\n{$brand_text}\n\n" : '' )
			. "Respond with a JSON object describing the changes to make. "
			. "Only include fields that need changing. Available fields:\n"
			. "- title (string): New post title\n"
			. "- content (string): New full post content (HTML)\n"
			. "- excerpt (string): New excerpt\n"
			. "- slug (string): New URL slug\n\n"
			. "Respond ONLY with the JSON object, no explanation or markdown fences.",
			array(),
			array(
				'phase' => 'final_synthesis',
				'effort_budget' => 'high',
				'schema_mode' => 'strict',
				'deliverable_schema' => array(
					'type' => 'object',
					'allowed_fields' => array(
						'title' => 'string',
						'content' => 'string',
						'excerpt' => 'string',
						'slug' => 'string',
					),
					'additionalProperties' => false,
					'min_changed_fields' => 1,
				),
				'stop_conditions' => array(
					'all requested changes fit the schema exactly',
					'one or more requested fields cannot be supported by the current content',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the strict JSON object',
				),
			)
		);

		if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
			return $this->phase_error(
				'Could not generate an edit plan because the AI request failed.',
				(string) $ai_result['failure_class']
			);
		}

		$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
		if ( ! empty( $decoded['error'] ) ) {
			if ( ! empty( $ai_result['failure_class'] ) ) {
				return $this->phase_error(
					'Could not generate an edit plan because the model response was incomplete.',
					(string) $ai_result['failure_class']
				);
			}
			return $this->validation_failure( 'Could not generate an edit plan. ' . $decoded['error'] );
		}

		$plan = $decoded['data'];
		$allowed_fields = array( 'title', 'content', 'excerpt', 'slug' );
		$unknown_fields = array_diff( array_keys( $plan ), $allowed_fields );
		if ( ! empty( $unknown_fields ) ) {
			return $this->validation_failure(
				'The edit plan included unsupported fields: ' . implode( ', ', $unknown_fields ) . '.'
			);
		}

		foreach ( $plan as $field => $value ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return $this->validation_failure(
					sprintf( 'The edit plan field "%s" must be a non-empty string.', $field )
				);
			}
		}

		return array( 'plan' => $plan );
	}

	// ── Phase 5: Preview ──────────────────────────────────────────

	/**
	 * Stage the changes via PressArk_Preview.
	 *
	 * @return array
	 */
	protected function phase_preview(): array {
		$post_id = $this->state['target']['post_id'];
		$plan    = $this->state['plan'];

		if ( ! empty( $this->state['editing']['is_elementor'] ) ) {
			return $this->phase_preview_elementor( $post_id, $plan );
		}

		$tool_calls = array(
			array(
				'name'      => 'edit_content',
				'arguments' => array(
					'post_id' => $post_id,
					'changes' => $plan,
				),
			),
		);

		$post = get_post( $post_id );
		$summary = sprintf(
			'I\'ve prepared edits for "%s". Please review the preview and approve to apply.',
			$post ? $post->post_title : "Post #{$post_id}"
		);

		return $this->build_preview_response( $tool_calls, $summary );
	}

	// ── Phase 7: Verify ───────────────────────────────────────────

	/**
	 * Read content back after apply, confirm changes landed.
	 *
	 * @return array
	 */
	protected function phase_verify(): array {
		$post_id = $this->state['target']['post_id'] ?? $this->state['post_id'] ?? 0;

		if ( ! $post_id ) {
			return array( 'summary' => 'Changes applied. Verification skipped (no target post).' );
		}

		// v3.7.0: Removed unused exec_read('read_content', mode='full') here.
		// The verify phase only uses get_post() to check field values —
		// the exec_read result was never assigned or consumed.

		$post = get_post( $post_id );
		$plan = $this->state['plan'] ?? array();

		if ( ! $post ) {
			return array( 'summary' => 'Changes applied but post could not be read back for verification.' );
		}

		if ( ! empty( $this->state['editing']['is_elementor'] ) ) {
			return $this->phase_verify_elementor( $post, $plan );
		}

		$checks = array();

		if ( isset( $plan['title'] ) ) {
			$matches  = ( $post->post_title === $plan['title'] );
			$checks[] = $matches ? 'Title updated correctly.' : 'Title may not have updated as expected.';
		}

		if ( isset( $plan['content'] ) ) {
			$has_content = ! empty( $post->post_content );
			$checks[]    = $has_content ? 'Content updated.' : 'Content may be empty after update.';
		}

		if ( isset( $plan['excerpt'] ) ) {
			$matches  = ( $post->post_excerpt === $plan['excerpt'] );
			$checks[] = $matches ? 'Excerpt updated correctly.' : 'Excerpt may not have updated as expected.';
		}

		if ( isset( $plan['slug'] ) ) {
			$matches  = ( $post->post_name === $plan['slug'] );
			$checks[] = $matches ? 'Slug updated correctly.' : 'Slug may not have updated as expected.';
		}

		$check_summary = implode( ' ', $checks );

		return array(
			'summary' => sprintf(
				'Changes applied to "%s". Verification: %s',
				$post->post_title,
				$check_summary ?: 'All fields confirmed.'
			),
		);
	}

	private function needs_full_content(): bool {
		$message = strtolower( (string) ( $this->state['message'] ?? '' ) );

		$metadata_only = (bool) preg_match(
			'/\b(?:title|headline|slug|permalink|excerpt|summary|teaser)\b/',
			$message
		);

		$body_edit = (bool) preg_match(
			'/\b(?:rewrite|reword|improve|expand|shorten|trim|refresh|update content|edit content|body|paragraph|section|intro|outro|copy|tone|voice|cta|call to action|faq|article|post text|page text)\b/',
			$message
		);

		if ( $body_edit ) {
			return true;
		}

		return ! $metadata_only;
	}

	private function build_content_snapshot( array $light, array $structured, array $full ): string {
		$parts = array();

		if ( ! empty( $light['excerpt'] ) ) {
			$parts[] = "Current excerpt:\n" . $light['excerpt'];
		}
		if ( ! empty( $light['word_count'] ) ) {
			$parts[] = 'Word count: ' . (int) $light['word_count'];
		}
		if ( ! empty( $structured['headings'] ) && is_array( $structured['headings'] ) ) {
			$heading_lines = array();
			foreach ( array_slice( $structured['headings'], 0, 8 ) as $heading ) {
				$heading_lines[] = str_repeat( '#', max( 1, (int) ( $heading['level'] ?? 2 ) ) ) . ' ' . ( $heading['text'] ?? '' );
			}
			if ( ! empty( $heading_lines ) ) {
				$parts[] = "Heading outline:\n" . implode( "\n", $heading_lines );
			}
		}
		if ( ! empty( $structured['section_summaries'] ) && is_array( $structured['section_summaries'] ) ) {
			$parts[] = "Section summaries:\n- " . implode( "\n- ", array_slice( $structured['section_summaries'], 0, 6 ) );
		}
		if ( ! empty( $full['content'] ) ) {
			$parts[] = "Current full content (first 2000 chars):\n" . mb_substr( (string) $full['content'], 0, 2000 );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( "\n\n", $parts ) . "\n\n";
	}

	private function rank_candidates( array $candidate_ids ): array {
		$ranked = array();

		foreach ( array_slice( $candidate_ids, 0, 5 ) as $candidate_id ) {
			$post = get_post( (int) $candidate_id );
			if ( ! $post ) {
				continue;
			}

			$ranked[] = array(
				'id'    => (int) $candidate_id,
				'post'  => $post,
				'score' => $this->score_candidate( $post ),
			);
		}

		usort( $ranked, static function ( array $a, array $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return $ranked;
	}

	private function score_candidate( \WP_Post $post ): int {
		$message    = strtolower( (string) ( $this->state['message'] ?? '' ) );
		$title      = strtolower( $post->post_title );
		$slug       = strtolower( $post->post_name );
		$type       = strtolower( $post->post_type );
		$front_page = (int) get_option( 'page_on_front', 0 );
		$score      = 0;

		if ( $front_page > 0 && $post->ID === $front_page && preg_match( '/\b(homepage|home page|front page)\b/', $message ) ) {
			$score += 80;
		}

		if ( '' !== $title && str_contains( $message, $title ) ) {
			$score += 60;
		}

		if ( '' !== $slug && str_contains( $message, $slug ) ) {
			$score += 40;
		}

		if ( 'page' === $type && preg_match( '/\b(page|homepage|landing page)\b/', $message ) ) {
			$score += 10;
		}

		if ( 'post' === $type && preg_match( '/\b(post|article|blog)\b/', $message ) ) {
			$score += 10;
		}

		$tokens = preg_split( '/[^a-z0-9]+/', $message ) ?: array();
		$tokens = array_filter( array_unique( $tokens ), static function ( string $token ): bool {
			return strlen( $token ) >= 4;
		} );
		foreach ( $tokens as $token ) {
			if ( str_contains( $title, $token ) ) {
				$score += 8;
			}
			if ( str_contains( $slug, $token ) ) {
				$score += 4;
			}
		}

		return $score;
	}

	// ── Elementor helpers ────────────────────────────────────────

	/**
	 * Plan phase for Elementor pages — produces widget-level edits.
	 *
	 * @return array
	 */
	private function phase_plan_elementor(): array {
		$post_id = $this->state['target']['post_id'];
		$post    = get_post( $post_id );
		$widgets = $this->state['elementor_page'] ?? array();

		if ( ! $post ) {
			return $this->bad_retrieval( 'Post not found.' );
		}

		$brand_text = '';
		if ( ! empty( $this->state['brand_profile'] ) ) {
			$brand_text = is_array( $this->state['brand_profile'] )
				? wp_json_encode( $this->state['brand_profile'] )
				: (string) $this->state['brand_profile'];
		}

		$widget_snapshot = $this->build_widget_snapshot( $widgets );

		$ai_result = $this->ai_call(
			"You are editing an Elementor page. The user requested: \"{$this->state['message']}\"\n\n"
			. "Current post:\n"
			. "- Title: {$post->post_title}\n"
			. "- Status: {$post->post_status}\n"
			. "- Type: {$post->post_type}\n\n"
			. $widget_snapshot
			. ( $brand_text ? "Brand/tone guidance:\n{$brand_text}\n\n" : '' )
			. "Respond with a JSON object. Include only fields that need changing.\n"
			. "Available top-level fields:\n"
			. "- title (string): New post title\n"
			. "- excerpt (string): New excerpt\n"
			. "- slug (string): New URL slug\n"
			. "- widget_edits (array): Each object has:\n"
			. "  - widget_id (string, required): ID from the widget list above\n"
			. "  - changes (object, required): Settings to change (use natural language field names)\n\n"
			. "Respond ONLY with the JSON object, no explanation or markdown fences.",
			array(),
			array(
				'phase'         => 'final_synthesis',
				'effort_budget' => 'high',
				'schema_mode'   => 'strict',
				'stop_conditions' => array(
					'all requested changes fit the schema exactly',
					'one or more requested widgets cannot be identified',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the strict JSON object',
				),
			)
		);

		if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
			return $this->phase_error(
				'Could not generate an edit plan because the AI request failed.',
				(string) $ai_result['failure_class']
			);
		}

		$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
		if ( ! empty( $decoded['error'] ) ) {
			if ( ! empty( $ai_result['failure_class'] ) ) {
				return $this->phase_error(
					'Could not generate an edit plan because the model response was incomplete.',
					(string) $ai_result['failure_class']
				);
			}
			return $this->validation_failure( 'Could not generate an edit plan. ' . $decoded['error'] );
		}

		$plan = $decoded['data'];

		// Validate widget_edits.
		if ( isset( $plan['widget_edits'] ) ) {
			if ( ! is_array( $plan['widget_edits'] ) ) {
				return $this->validation_failure( 'widget_edits must be an array.' );
			}
			foreach ( $plan['widget_edits'] as $i => $edit ) {
				if ( empty( $edit['widget_id'] ) || ! is_string( $edit['widget_id'] ) ) {
					return $this->validation_failure( sprintf( 'widget_edits[%d] is missing a valid widget_id.', $i ) );
				}
				if ( empty( $edit['changes'] ) || ! is_array( $edit['changes'] ) ) {
					return $this->validation_failure( sprintf( 'widget_edits[%d] is missing valid changes.', $i ) );
				}
			}
		}

		// Validate post-level fields.
		$post_fields = array( 'title', 'excerpt', 'slug' );
		foreach ( $post_fields as $field ) {
			if ( isset( $plan[ $field ] ) && ( ! is_string( $plan[ $field ] ) || '' === trim( $plan[ $field ] ) ) ) {
				return $this->validation_failure(
					sprintf( 'The edit plan field "%s" must be a non-empty string.', $field )
				);
			}
		}

		$has_post_changes = ! empty( array_intersect_key( $plan, array_flip( $post_fields ) ) );
		$has_widget_edits = ! empty( $plan['widget_edits'] );
		if ( ! $has_post_changes && ! $has_widget_edits ) {
			return $this->validation_failure( 'The edit plan must include at least one change.' );
		}

		return array( 'plan' => $plan );
	}

	/**
	 * Preview phase for Elementor pages — builds elementor_edit_widget tool calls.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $plan    Edit plan with widget_edits and/or post-level fields.
	 * @return array
	 */
	private function phase_preview_elementor( int $post_id, array $plan ): array {
		$tool_calls = array();

		// Widget-level edits.
		if ( ! empty( $plan['widget_edits'] ) ) {
			foreach ( $plan['widget_edits'] as $edit ) {
				$tool_calls[] = array(
					'name'      => 'elementor_edit_widget',
					'arguments' => array(
						'post_id'   => $post_id,
						'widget_id' => $edit['widget_id'],
						'changes'   => $edit['changes'],
					),
				);
			}
		}

		// Post-level field changes (title, excerpt, slug).
		$post_fields = array_intersect_key( $plan, array_flip( array( 'title', 'excerpt', 'slug' ) ) );
		if ( ! empty( $post_fields ) ) {
			$tool_calls[] = array(
				'name'      => 'edit_content',
				'arguments' => array(
					'post_id' => $post_id,
					'changes' => $post_fields,
				),
			);
		}

		$post    = get_post( $post_id );
		$summary = sprintf(
			'I\'ve prepared Elementor edits for "%s". Please review the preview and approve to apply.',
			$post ? $post->post_title : "Post #{$post_id}"
		);

		return $this->build_preview_response( $tool_calls, $summary );
	}

	/**
	 * Verify phase for Elementor pages — checks _elementor_data was modified.
	 *
	 * @param \WP_Post $post Verified post object.
	 * @param array    $plan Edit plan.
	 * @return array
	 */
	private function phase_verify_elementor( \WP_Post $post, array $plan ): array {
		$checks = array();

		if ( isset( $plan['title'] ) ) {
			$checks[] = ( $post->post_title === $plan['title'] )
				? 'Title updated correctly.'
				: 'Title may not have updated as expected.';
		}

		if ( isset( $plan['excerpt'] ) ) {
			$checks[] = ( $post->post_excerpt === $plan['excerpt'] )
				? 'Excerpt updated correctly.'
				: 'Excerpt may not have updated as expected.';
		}

		if ( isset( $plan['slug'] ) ) {
			$checks[] = ( $post->post_name === $plan['slug'] )
				? 'Slug updated correctly.'
				: 'Slug may not have updated as expected.';
		}

		if ( ! empty( $plan['widget_edits'] ) ) {
			$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
			$checks[]       = ! empty( $elementor_data )
				? 'Elementor data updated.'
				: 'Elementor data may not have been updated.';
		}

		$check_summary = implode( ' ', $checks );

		return array(
			'summary' => sprintf(
				'Changes applied to "%s". Verification: %s',
				$post->post_title,
				$check_summary ?: 'All fields confirmed.'
			),
		);
	}

	/**
	 * Build a text snapshot of Elementor widgets for the AI prompt.
	 *
	 * @param mixed $widgets Widget data from elementor_read_page.
	 * @return string
	 */
	private function build_widget_snapshot( $widgets ): string {
		if ( empty( $widgets ) || ! is_array( $widgets ) ) {
			return '';
		}

		// If the tool returned a pre-formatted summary, use it directly.
		if ( isset( $widgets['summary'] ) && is_string( $widgets['summary'] ) ) {
			return "Elementor page structure:\n" . $widgets['summary'] . "\n\n";
		}

		$elements = $widgets['elements'] ?? $widgets['widgets'] ?? $widgets;
		if ( ! is_array( $elements ) || empty( $elements ) ) {
			return '';
		}

		$lines = array();
		$this->walk_widget_tree( $elements, $lines, 0 );

		if ( empty( $lines ) ) {
			return '';
		}

		return "Elementor page widgets:\n" . implode( "\n", $lines ) . "\n\n";
	}

	/**
	 * Recursively walk the Elementor element tree to build a text snapshot.
	 *
	 * @param array $elements Element array.
	 * @param array $lines    Output lines (by reference).
	 * @param int   $depth    Current nesting depth.
	 */
	private function walk_widget_tree( array $elements, array &$lines, int $depth ): void {
		$indent = str_repeat( '  ', $depth );

		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$type = $el['widgetType'] ?? $el['elType'] ?? 'unknown';
			$id   = $el['id'] ?? '';

			$text_preview = '';
			if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( array( 'editor', 'title', 'text', 'heading_title', 'description', 'html' ) as $key ) {
					if ( ! empty( $el['settings'][ $key ] ) && is_string( $el['settings'][ $key ] ) ) {
						$text_preview = wp_trim_words( wp_strip_all_tags( $el['settings'][ $key ] ), 15 );
						break;
					}
				}
			}

			$line = sprintf( '%s- [%s] %s', $indent, $id, $type );
			if ( '' !== $text_preview ) {
				$line .= ': "' . $text_preview . '"';
			}
			$lines[] = $line;

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk_widget_tree( $el['elements'], $lines, $depth + 1 );
			}
		}
	}
}
