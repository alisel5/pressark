<?php
/**
 * PressArk Content Create Workflow — deterministic content creation pipeline.
 *
 * Handles: "create/write/draft/generate a post/page/article..."
 *
 * Phase flow:
 *   1. discover        — Infer creation target type from the request.
 *   2. select_target   — Bind a virtual target shape (new post/page).
 *   3. gather_context  — Read brand profile + small knowledge samples.
 *   4. plan            — AI generates strict JSON for the new content.
 *   5. preview         — Stage create_post via PressArk_Preview.
 *   6. apply           — Handled by PressArk_Preview::keep().
 *   7. verify          — Read back the created post and confirm it exists.
 *
 * Total AI calls: 1 (vs agent's typical 3-5 rounds for creation flows).
 *
 * @package PressArk
 * @since   4.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Workflow_Content_Create extends PressArk_Workflow_Runner {

	/**
	 * Tool groups used by this workflow.
	 *
	 * @return array
	 */
	protected function tool_groups(): array {
		return array( 'content', 'discovery' );
	}

	protected function workflow_task_type(): string {
		return 'generate';
	}

	/**
	 * Infer the content type we should create.
	 *
	 * @return array
	 */
	protected function phase_discover(): array {
		$message   = (string) ( $this->state['message'] ?? '' );
		$post_type = $this->infer_post_type( $message );

		return array(
			'creation' => array(
				'post_type'      => $post_type,
				'use_elementor'  => $this->should_use_elementor( $post_type ),
			),
		);
	}

	/**
	 * Bind a virtual target summary for the new content.
	 *
	 * @return array
	 */
	protected function phase_select_target(): array {
		$post_type = (string) ( $this->state['creation']['post_type'] ?? 'post' );

		return array(
			'target' => array(
				'post_id' => 0,
				'title'   => '',
				'type'    => $post_type,
			),
		);
	}

	/**
	 * Gather small grounding context for style/tone.
	 *
	 * @return array
	 */
	protected function phase_gather_context(): array {
		$message   = (string) ( $this->state['message'] ?? '' );
		$post_type = (string) ( $this->state['creation']['post_type'] ?? 'post' );
		$loaded_groups = (array) ( $this->state['loaded_tool_groups'] ?? $this->tool_groups() );

		$brand = $this->exec_read( 'get_brand_profile', array() );

		$knowledge = array(
			'success' => true,
			'data'    => array(),
			'message' => '',
		);
		$product_context = array();

		if ( $this->should_ground_on_random_product( $message ) ) {
			$product_context = $this->pick_random_product_context();
			if ( empty( $product_context['id'] ) ) {
				return $this->bad_retrieval( 'Could not select a real WooCommerce product to ground this post. Make sure your store has at least one published product.' );
			}
			$loaded_groups[] = 'woocommerce';
		}

		if ( str_word_count( $message ) >= 3 ) {
			$knowledge_result = $this->exec_read( 'search_knowledge', array(
				'query'     => $message,
				'post_type' => $post_type,
				'limit'     => 3,
			) );

			if ( ! empty( $knowledge_result['success'] ) && ! empty( $knowledge_result['data'] ) ) {
				$knowledge = $knowledge_result;
			}
		}

		return array(
			'brand_profile'      => $brand['data'] ?? $brand['message'] ?? '',
			'knowledge_samples'  => $knowledge['data'] ?? array(),
			'product_context'    => $product_context,
			'loaded_tool_groups' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $loaded_groups ) ) ) ),
		);
	}

	/**
	 * AI plans the new content in a strict JSON shape.
	 *
	 * @return array
	 */
	protected function phase_plan(): array {
		$message   = (string) ( $this->state['message'] ?? '' );
		$post_type = (string) ( $this->state['creation']['post_type'] ?? 'post' );
		$product_context  = (array) ( $this->state['product_context'] ?? array() );
		$seo_requested    = $this->user_requested_seo( $message );
		$publish_requested = $this->user_requested_publish( $message );

		$context_data = array(
			'preferred_post_type' => $post_type,
			'brand_profile'       => $this->compact_brand_profile( $this->state['brand_profile'] ?? '' ),
			'knowledge_examples'  => $this->compact_knowledge_examples( (array) ( $this->state['knowledge_samples'] ?? array() ) ),
			'user_requested_publish' => $publish_requested,
			'user_requested_seo'     => $seo_requested,
		);
		if ( ! empty( $product_context ) ) {
			$context_data['selected_product'] = $product_context;
		}

		$ai_result = $this->ai_call(
			"You are preparing a new WordPress {$post_type}. "
			. "User request: \"{$message}\"\n\n"
			. "Return ONLY a JSON object with this shape:\n"
			. "{\n"
			. "  \"title\": string,\n"
			. "  \"content\": string,\n"
			. "  \"slug\": string (optional, clean URL slug),\n"
			. "  \"excerpt\": string (optional, 1-2 sentence summary),\n"
			. "  \"meta_title\": string (optional, ~55 chars, 30-70 range for SEO),\n"
			. "  \"meta_description\": string (optional, ~155 chars, 50-200 range for SEO),\n"
			. "  \"og_title\": string (optional),\n"
			. "  \"og_description\": string (optional),\n"
			. "  \"og_image\": string (optional, absolute image URL),\n"
			. "  \"focus_keyword\": string (optional),\n"
			. "  \"status\": \"draft\"|\"publish\"|\"future\" (optional),\n"
			. "  \"scheduled_date\": \"Y-m-d H:i:s\" (optional, only if status is \"future\")\n"
			. "}\n\n"
			. "Rules:\n"
			. "- Write ready-to-use HTML in content.\n"
			. "- Always include slug (lowercase, hyphenated, no stop words).\n"
			. "- Always include excerpt.\n"
			. "- Brand/site profile and knowledge examples are style guidance only, not factual proof.\n"
			. ( ! empty( $product_context )
				? "- This request is grounded on selected_product. Write about that product only.\n"
				  . "- Use selected_product.url exactly for the CTA link.\n"
				  . "- Do not invent product names, URLs, prices, stock, or availability beyond selected_product.\n"
				  . "- Highlight pain points the selected product plausibly solves using the provided product descriptions, attributes, and categories.\n"
				: '' )
			. ( $seo_requested
				? "- SEO is requested: include meta_description and focus_keyword. Include og_title, og_description, and og_image when they can be grounded from the provided data.\n"
				: "- Include meta_description when it fits naturally.\n" )
			. "- Include meta_title only if different from title.\n"
			. "- Default to status \"draft\" unless the user explicitly asked to publish or schedule it.\n"
			. "- Only use \"future\" when the user clearly asked for scheduling and you can provide a concrete date/time.\n"
			. ( $publish_requested ? "- The user explicitly asked to publish: return status \"publish\" unless they asked for scheduling instead.\n" : '' )
			. "- Do not include unsupported fields.\n"
			. "- Do not wrap the JSON in markdown fences.",
			$context_data,
			array(),
			array(
				'phase' => 'final_synthesis',
				'effort_budget' => 'medium',
				'schema_mode' => 'strict',
				'deliverable_schema' => array(
					'type' => 'object',
					'allowed_fields' => array(
						'title' => 'string',
						'content' => 'string',
						'slug' => 'string',
						'excerpt' => 'string',
						'meta_title' => 'string',
						'meta_description' => 'string',
						'og_title' => 'string',
						'og_description' => 'string',
						'og_image' => 'string',
						'focus_keyword' => 'string',
						'status' => 'string',
						'scheduled_date' => 'string',
					),
					'additionalProperties' => false,
					'required' => array( 'title', 'content' ),
				),
				'stop_conditions' => array(
					'the new content is complete and fits the schema exactly',
					'the request is too ambiguous to produce safe content without unsupported assumptions',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the strict JSON object',
				),
			)
		);

		if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
			return $this->phase_error(
				'Could not generate the new content because the AI request failed.',
				(string) $ai_result['failure_class']
			);
		}

		$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
		if ( ! empty( $decoded['error'] ) ) {
			if ( ! empty( $ai_result['failure_class'] ) ) {
				return $this->phase_error(
					'Could not generate the new content because the model response was incomplete.',
					(string) $ai_result['failure_class']
				);
			}
			return $this->validation_failure( 'Could not generate a creation plan. ' . $decoded['error'] );
		}

		$plan = $decoded['data'];

		$allowed_fields = array( 'title', 'content', 'slug', 'excerpt', 'meta_title', 'meta_description', 'og_title', 'og_description', 'og_image', 'focus_keyword', 'status', 'scheduled_date' );
		$unknown_fields = array_diff( array_keys( $plan ), $allowed_fields );
		if ( ! empty( $unknown_fields ) ) {
			return $this->validation_failure(
				'The creation plan included unsupported fields: ' . implode( ', ', $unknown_fields ) . '.'
			);
		}

		$title = trim( (string) ( $plan['title'] ?? '' ) );
		if ( '' === $title ) {
			return $this->validation_failure( 'The creation plan must include a non-empty title.' );
		}

		if ( ! isset( $plan['content'] ) || ! is_string( $plan['content'] ) ) {
			return $this->validation_failure( 'The creation plan must include string content.' );
		}

		$status = sanitize_key( (string) ( $plan['status'] ?? 'draft' ) );
		if ( ! in_array( $status, array( 'draft', 'publish', 'future' ), true ) ) {
			$status = 'draft';
		}

		$scheduled_date = '';
		if ( 'future' === $status ) {
			$scheduled_date = sanitize_text_field( (string) ( $plan['scheduled_date'] ?? '' ) );
			if ( '' === $scheduled_date ) {
				$status = 'draft';
			}
		}

		$result_plan = array(
			'title'          => $title,
			'content'        => $this->normalize_product_cta_content( (string) $plan['content'], $product_context ),
			'post_type'      => $post_type,
			'status'         => $status,
			'scheduled_date' => $scheduled_date,
		);

		// Optional richer fields.
		if ( ! empty( $plan['slug'] ) && is_string( $plan['slug'] ) ) {
			$result_plan['slug'] = sanitize_title( $plan['slug'] );
		}
		if ( ! empty( $plan['excerpt'] ) && is_string( $plan['excerpt'] ) ) {
			$result_plan['excerpt'] = sanitize_textarea_field( mb_substr( $plan['excerpt'], 0, 500 ) );
		}
		if ( ! empty( $plan['meta_title'] ) && is_string( $plan['meta_title'] ) ) {
			$result_plan['meta_title'] = sanitize_text_field( mb_substr( $plan['meta_title'], 0, 70 ) );
		}
		if ( ! empty( $plan['meta_description'] ) && is_string( $plan['meta_description'] ) ) {
			$result_plan['meta_description'] = sanitize_text_field( mb_substr( $plan['meta_description'], 0, 170 ) );
		}
		if ( ! empty( $plan['og_title'] ) && is_string( $plan['og_title'] ) ) {
			$result_plan['og_title'] = sanitize_text_field( mb_substr( $plan['og_title'], 0, 70 ) );
		}
		if ( ! empty( $plan['og_description'] ) && is_string( $plan['og_description'] ) ) {
			$result_plan['og_description'] = sanitize_text_field( mb_substr( $plan['og_description'], 0, 170 ) );
		}
		if ( ! empty( $plan['og_image'] ) && is_string( $plan['og_image'] ) ) {
			$result_plan['og_image'] = esc_url_raw( $plan['og_image'] );
		}
		if ( ! empty( $plan['focus_keyword'] ) && is_string( $plan['focus_keyword'] ) ) {
			$result_plan['focus_keyword'] = sanitize_text_field( mb_substr( $plan['focus_keyword'], 0, 120 ) );
		}

		if ( empty( $result_plan['excerpt'] ) && ! empty( $product_context['short_description'] ) ) {
			$result_plan['excerpt'] = sanitize_textarea_field( mb_substr( (string) $product_context['short_description'], 0, 500 ) );
		}

		if ( $publish_requested ) {
			$result_plan['status'] = 'publish';
			$result_plan['scheduled_date'] = '';
		}

		if ( $seo_requested ) {
			if ( empty( $result_plan['meta_description'] ) ) {
				$result_plan['meta_description'] = $this->build_default_meta_description( $result_plan, $product_context );
			}
			if ( empty( $result_plan['focus_keyword'] ) ) {
				$result_plan['focus_keyword'] = $this->build_default_focus_keyword( $result_plan, $product_context );
			}
			if ( empty( $result_plan['og_title'] ) ) {
				$result_plan['og_title'] = sanitize_text_field( mb_substr( (string) ( $result_plan['meta_title'] ?? $result_plan['title'] ), 0, 70 ) );
			}
			if ( empty( $result_plan['og_description'] ) ) {
				$result_plan['og_description'] = sanitize_text_field( mb_substr( (string) ( $result_plan['meta_description'] ?? '' ), 0, 170 ) );
			}
			if ( empty( $result_plan['og_image'] ) && ! empty( $product_context['image'] ) ) {
				$result_plan['og_image'] = esc_url_raw( (string) $product_context['image'] );
			}
		}

		return array( 'plan' => $result_plan );
	}

	/**
	 * Stage the new post/page via preview.
	 *
	 * @return array
	 */
	protected function phase_preview(): array {
		$plan          = $this->state['plan'] ?? array();
		$use_elementor = ! empty( $this->state['creation']['use_elementor'] );

		if ( $use_elementor ) {
			return $this->build_elementor_preview( $plan );
		}

		return $this->build_standard_preview( $plan );
	}

	/**
	 * Build a standard create_post preview.
	 *
	 * @param array $plan AI-generated creation plan.
	 * @return array Phase result with __return key.
	 */
	private function build_standard_preview( array $plan ): array {
		$args = array(
			'title'            => $plan['title'] ?? '',
			'content'          => $plan['content'] ?? '',
			'post_type'        => $plan['post_type'] ?? 'post',
			'status'           => $plan['status'] ?? 'draft',
			'scheduled_date'   => $plan['scheduled_date'] ?? '',
			'slug'             => $plan['slug'] ?? '',
			'excerpt'          => $plan['excerpt'] ?? '',
			'meta_title'       => $plan['meta_title'] ?? '',
			'meta_description' => $plan['meta_description'] ?? '',
			'og_title'         => $plan['og_title'] ?? '',
			'og_description'   => $plan['og_description'] ?? '',
			'og_image'         => $plan['og_image'] ?? '',
			'focus_keyword'    => $plan['focus_keyword'] ?? '',
		);

		$tool_calls = array(
			array(
				'name'      => 'create_post',
				'arguments' => array_filter( $args, static function ( $value ) {
					return null !== $value && '' !== $value;
				} ),
			),
		);

		$type_label = 'page' === ( $plan['post_type'] ?? 'post' ) ? 'page' : 'post';
		$summary = sprintf(
			'I\'ve prepared a new %1$s titled "%2$s". Review the preview and approve to create it.',
			$type_label,
			(string) ( $plan['title'] ?? 'Untitled' )
		);

		return $this->build_preview_response( $tool_calls, $summary );
	}

	/**
	 * Build an Elementor-native page preview.
	 *
	 * Converts the AI-generated HTML content to Elementor widgets, then emits
	 * an elementor_create_page tool call instead of create_post.
	 *
	 * @param array $plan AI-generated creation plan.
	 * @return array Phase result with __return key.
	 */
	private function build_elementor_preview( array $plan ): array {
		$widgets = $this->html_to_elementor_widgets( (string) ( $plan['content'] ?? '' ) );

		$extra_meta = array();
		if ( ! empty( $plan['slug'] ) ) {
			$extra_meta['slug'] = $plan['slug'];
		}
		if ( ! empty( $plan['excerpt'] ) ) {
			$extra_meta['excerpt'] = $plan['excerpt'];
		}
		if ( ! empty( $plan['meta_title'] ) ) {
			$extra_meta['meta_title'] = $plan['meta_title'];
		}
		if ( ! empty( $plan['meta_description'] ) ) {
			$extra_meta['meta_description'] = $plan['meta_description'];
		}
		if ( ! empty( $plan['og_title'] ) ) {
			$extra_meta['og_title'] = $plan['og_title'];
		}
		if ( ! empty( $plan['og_description'] ) ) {
			$extra_meta['og_description'] = $plan['og_description'];
		}
		if ( ! empty( $plan['og_image'] ) ) {
			$extra_meta['og_image'] = $plan['og_image'];
		}
		if ( ! empty( $plan['focus_keyword'] ) ) {
			$extra_meta['focus_keyword'] = $plan['focus_keyword'];
		}

		$post_type = $this->state['creation']['post_type'] ?? 'page';

		$args = array(
			'title'     => $plan['title'] ?? '',
			'post_type' => $post_type,
			'status'    => $plan['status'] ?? 'draft',
			'widgets'   => $widgets,
		);

		if ( ! empty( $extra_meta ) ) {
			$args['extra_meta'] = $extra_meta;
		}

		if ( ! empty( $plan['scheduled_date'] ) ) {
			$args['scheduled_date'] = $plan['scheduled_date'];
		}

		$tool_calls = array(
			array(
				'name'      => 'elementor_create_page',
				'arguments' => $args,
			),
		);

		$type_label = 'post' === $post_type ? 'post' : 'page';
		$summary = sprintf(
			'I\'ve prepared a new Elementor %s titled "%s" with %d widget(s). Review the preview and approve to create it.',
			$type_label,
			(string) ( $plan['title'] ?? 'Untitled' ),
			count( $widgets )
		);

		return $this->build_preview_response( $tool_calls, $summary );
	}

	/**
	 * Verify the created content after preview approval.
	 *
	 * @return array
	 */
	protected function phase_verify(): array {
		$applied        = (array) ( $this->state['applied'] ?? array() );
		$post_id        = (int) ( $applied['post_id'] ?? 0 );
		$plan           = (array) ( $this->state['plan'] ?? array() );
		$use_elementor  = ! empty( $this->state['creation']['use_elementor'] );

		if ( $post_id <= 0 ) {
			return array( 'summary' => 'Content created, but verification could not identify the new post ID.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'summary' => 'Content was created, but the new post could not be read back for verification.' );
		}

		$result = $use_elementor
			? $this->verify_elementor_page( $post, $plan )
			: $this->verify_standard_post( $post, $plan );

		// Append draft notice so the user knows how to publish.
		if ( 'draft' === $post->post_status ) {
			$result['summary'] .= sprintf(
				' Your %s is currently saved as a draft — just say "publish it" whenever you\'re ready.',
				$post->post_type
			);
		}

		return $result;
	}

	/**
	 * Standard post/page verification.
	 *
	 * @param \WP_Post $post Created post object.
	 * @param array    $plan AI-generated creation plan.
	 * @return array
	 */
	private function verify_standard_post( \WP_Post $post, array $plan ): array {
		$checks = array();

		if ( ! empty( $plan['title'] ) ) {
			$checks[] = ( $post->post_title === $plan['title'] )
				? 'Title verified.'
				: 'Title may differ from the planned draft.';
		}

		$checks[] = ! empty( $post->post_content )
			? 'Content body exists.'
			: 'Content body appears empty.';

		if ( ! empty( $plan['status'] ) ) {
			$checks[] = ( $post->post_status === $plan['status'] )
				? 'Status verified.'
				: 'Status differs from the requested plan.';
		}

		if ( ! empty( $plan['slug'] ) ) {
			$checks[] = ( $post->post_name === $plan['slug'] )
				? 'Slug verified.'
				: 'Slug may differ from planned value.';
		}

		if ( ! empty( $plan['excerpt'] ) ) {
			$checks[] = ! empty( $post->post_excerpt )
				? 'Excerpt set.'
				: 'Excerpt was not applied.';
		}

		$product_url = (string) ( $this->state['product_context']['url'] ?? '' );
		if ( '' !== $product_url ) {
			$checks[] = false !== stripos( (string) $post->post_content, $product_url )
				? 'CTA points to the selected product.'
				: 'CTA link may differ from the selected product URL.';
		}

		$seo_check = $this->build_seo_verification_check( $post->ID, $plan );
		if ( '' !== $seo_check ) {
			$checks[] = $seo_check;
		}

		return array(
			'summary' => sprintf(
				'Created %1$s "%2$s". Verification: %3$s',
				$post->post_type,
				$post->post_title ?: "Post #{$post->ID}",
				implode( ' ', $checks )
			),
		);
	}

	/**
	 * Elementor page verification — checks Elementor-specific meta.
	 *
	 * @param \WP_Post $post Created page object.
	 * @param array    $plan AI-generated creation plan.
	 * @return array
	 */
	private function verify_elementor_page( \WP_Post $post, array $plan ): array {
		$checks = array();

		if ( ! empty( $plan['title'] ) ) {
			$checks[] = ( $post->post_title === $plan['title'] )
				? 'Title verified.'
				: 'Title may differ from the planned draft.';
		}

		// Check Elementor data presence.
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		$checks[] = ! empty( $elementor_data )
			? 'Elementor data present.'
			: 'Elementor data missing — page may not render in the builder.';

		// Check edit mode.
		$edit_mode = get_post_meta( $post->ID, '_elementor_edit_mode', true );
		$checks[] = ( 'builder' === $edit_mode )
			? 'Edit mode set to builder.'
			: 'Edit mode not set — page may open in the default editor instead.';

		// Count widgets.
		if ( ! empty( $elementor_data ) ) {
			$decoded = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;
			if ( is_array( $decoded ) ) {
				$widget_count = $this->count_elementor_widgets( $decoded );
				$checks[] = $widget_count > 0
					? sprintf( '%d widget(s) found.', $widget_count )
					: 'No widgets found in the page structure.';
			}
		}

		if ( ! empty( $plan['status'] ) ) {
			$checks[] = ( $post->post_status === $plan['status'] )
				? 'Status verified.'
				: 'Status differs from the requested plan.';
		}

		$seo_check = $this->build_seo_verification_check( $post->ID, $plan );
		if ( '' !== $seo_check ) {
			$checks[] = $seo_check;
		}

		return array(
			'summary' => sprintf(
				'Created Elementor page "%s". Verification: %s',
				$post->post_title ?: "Page #{$post->ID}",
				implode( ' ', $checks )
			),
		);
	}

	/**
	 * Infer whether the user wants a post or page.
	 *
	 * Explicit "page" keyword always wins. Then check for common evergreen
	 * page types. Then check for blog/article/news signals → post.
	 * Ambiguous requests default to "post".
	 */
	private function infer_post_type( string $message ): string {
		$lower = strtolower( $message );

		// Explicit page keyword (including compound forms like "landing page").
		if ( preg_match( '/\bpage\b/i', $lower ) ) {
			return 'page';
		}

		// Explicit post/article/blog/content signals — checked before page patterns
		// so "a post about team building" stays a post.
		if ( preg_match( '/\b(post|blog\s*post|article|news|editorial|opinion|tutorial|guide|how[\s-]?to|listicle|review|roundup|recap|weekly|monthly|daily)\b/', $lower ) ) {
			return 'post';
		}

		// Unambiguous page types — these words rarely appear as post topics.
		$safe_page_patterns = array(
			'about\s+us',
			'contact\s+us',
			'faq',
			'frequently\s+asked\s+questions',
			'pricing',
			'our\s+services',
			'privacy\s+policy',
			'terms\s+(of\s+service|and\s+conditions|of\s+use)',
			'disclaimer',
			'refund\s+policy',
			'return\s+policy',
			'shipping\s+policy',
			'cookie\s+policy',
			'our\s+team',
			'meet\s+the\s+team',
			'careers',
			'portfolio',
			'testimonials',
			'case\s+stud(y|ies)',
			'how\s+it\s+works',
			'homepage',
			'landing',
			'thank\s+you',
			'coming\s+soon',
			'under\s+construction',
			'404',
			'sitemap',
		);

		$safe_pattern = '/\b(' . implode( '|', $safe_page_patterns ) . ')\b/';
		if ( preg_match( $safe_pattern, $lower ) ) {
			return 'page';
		}

		return 'post';
	}

	/**
	 * Trim brand profile data so the planning prompt stays compact.
	 *
	 * @param mixed $brand_profile Brand profile payload from the discovery tool.
	 * @return string|array
	 */
	private function compact_brand_profile( $brand_profile ) {
		if ( is_array( $brand_profile ) ) {
			$allowed = array();
			foreach ( array( 'industry', 'tone', 'style', 'voice', 'topics', 'audience' ) as $key ) {
				if ( isset( $brand_profile[ $key ] ) ) {
					$allowed[ $key ] = $brand_profile[ $key ];
				}
			}
			return $allowed;
		}

		$text = trim( wp_strip_all_tags( (string) $brand_profile ) );
		return '' === $text ? '' : mb_substr( $text, 0, 600 );
	}

	private function should_ground_on_random_product( string $message ): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		$lower = strtolower( $message );
		if ( ! preg_match( '/\bproduct\b/', $lower ) ) {
			return false;
		}

		if ( preg_match( '/\brandom\s+product\b|\bproduct\b.*\brandom\b/', $lower ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:choose|chose|pick|select)\b.*\bproduct\b/', $lower ) && preg_match( '/\b(?:store|shop)\b/', $lower ) ) {
			return true;
		}

		return (bool) preg_match( '/\ba\s+product\s+from\s+my\s+(?:store|shop)\b|\bone\s+of\s+my\s+products\b/', $lower );
	}

	private function pick_random_product_context(): array {
		$random_result = $this->exec_read( 'get_random_content', array(
			'post_type' => 'product',
			'status'    => 'publish',
			'mode'      => 'light',
		) );

		$random_data      = (array) ( $random_result['data'] ?? array() );
		$product_id = (int) ( $random_result['data']['id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return array();
		}

		$fallback_context = array(
			'id'                => $product_id,
			'name'              => sanitize_text_field( (string) ( $random_data['title'] ?? '' ) ),
			'url'               => esc_url_raw( (string) ( $random_data['url'] ?? '' ) ),
			'price'             => sanitize_text_field( (string) ( $random_data['product']['price'] ?? '' ) ),
			'regular_price'     => sanitize_text_field( (string) ( $random_data['product']['regular_price'] ?? '' ) ),
			'sale_price'        => sanitize_text_field( (string) ( $random_data['product']['sale_price'] ?? '' ) ),
			'stock_status'      => sanitize_text_field( (string) ( $random_data['product']['stock_status'] ?? '' ) ),
			'stock_quantity'    => isset( $random_data['product']['stock_quantity'] ) ? (int) $random_data['product']['stock_quantity'] : null,
			'on_sale'           => ! empty( $random_data['product']['on_sale'] ),
			'short_description' => $this->truncate_plain_text( (string) ( $random_data['product']['short_description'] ?? $random_data['excerpt'] ?? '' ), 500 ),
			'description'       => $this->truncate_plain_text( (string) ( $random_data['product']['description'] ?? $random_data['excerpt'] ?? '' ), 800 ),
			'categories'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $random_data['product']['categories'] ?? array() ) ) ) ),
			'tags'              => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $random_data['product']['tags'] ?? array() ) ) ) ),
			'image'             => esc_url_raw( (string) ( $random_data['product']['image'] ?? '' ) ),
		);

		$has_grounded_url   = '' !== ( $fallback_context['url'] ?? '' );
		$has_grounded_copy  = '' !== ( $fallback_context['short_description'] ?? '' ) || '' !== ( $fallback_context['description'] ?? '' );
		$has_grounded_shape = ! empty( $fallback_context['categories'] ) || '' !== ( $fallback_context['image'] ?? '' ) || '' !== ( $fallback_context['price'] ?? '' );
		if ( $has_grounded_url && $has_grounded_copy && $has_grounded_shape ) {
			return $fallback_context;
		}

		$product_result = $this->exec_read( 'get_product', array(
			'product_id' => $product_id,
		) );

		if ( ! empty( $product_result['success'] ) && ! empty( $product_result['data'] ) ) {
			return $this->compact_product_context( (array) $product_result['data'] );
		}

		return $fallback_context;
	}

	private function compact_product_context( array $product ): array {
		$attributes = array();
		foreach ( array_slice( (array) ( $product['attributes'] ?? array() ), 0, 5 ) as $attribute ) {
			if ( ! is_array( $attribute ) ) {
				continue;
			}

			$attributes[] = array(
				'name'   => sanitize_text_field( (string) ( $attribute['name'] ?? '' ) ),
				'values' => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $attribute['values'] ?? array() ), 0, 5 ) ) ) ),
			);
		}

		$images = array_values( array_filter( array_map( 'esc_url_raw', array_slice( (array) ( $product['images'] ?? array() ), 0, 3 ) ) ) );

		return array(
			'id'                => (int) ( $product['id'] ?? 0 ),
			'name'              => sanitize_text_field( (string) ( $product['name'] ?? '' ) ),
			'url'               => esc_url_raw( (string) ( $product['url'] ?? '' ) ),
			'sku'               => sanitize_text_field( (string) ( $product['sku'] ?? '' ) ),
			'price'             => sanitize_text_field( (string) ( $product['price'] ?? '' ) ),
			'regular_price'     => sanitize_text_field( (string) ( $product['regular_price'] ?? '' ) ),
			'sale_price'        => sanitize_text_field( (string) ( $product['sale_price'] ?? '' ) ),
			'stock_status'      => sanitize_text_field( (string) ( $product['stock_status'] ?? '' ) ),
			'stock_quantity'    => isset( $product['stock_quantity'] ) ? (int) $product['stock_quantity'] : null,
			'on_sale'           => ! empty( $product['on_sale'] ),
			'short_description' => $this->truncate_plain_text( (string) ( $product['short_description'] ?? '' ), 500 ),
			'description'       => $this->truncate_plain_text( (string) ( $product['description'] ?? '' ), 700 ),
			'categories'        => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $product['categories'] ?? array() ), 0, 5 ) ) ) ),
			'tags'              => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $product['tags'] ?? array() ), 0, 5 ) ) ) ),
			'attributes'        => $attributes,
			'image'             => $images[0] ?? '',
			'images'            => $images,
		);
	}

	private function user_requested_publish( string $message ): bool {
		$lower = strtolower( $message );

		if ( ! preg_match( '/\bpublish(?:\s+it|\s+this)?\b/', $lower ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:schedule|scheduled|tomorrow|next\s+(?:week|month)|future)\b/', $lower ) ) {
			return false;
		}

		return ! preg_match( '/\b(?:draft|save\s+as\s+draft|keep\s+(?:it|this)\s+as\s+draft|do\s+not\s+publish|don\'t\s+publish|dont\s+publish)\b/', $lower );
	}

	private function user_requested_seo( string $message ): bool {
		return (bool) preg_match( '/\bseo\b|\bmeta\s+(?:title|description|tags?)\b|\bfocus\s+keyword\b|\bopen\s+graph\b|\bog:(?:title|description|image)\b|\bsearch\s+(?:snippet|ranking|rankings)\b/i', $message );
	}

	private function normalize_product_cta_content( string $html, array $product_context ): string {
		$product_url = esc_url_raw( (string) ( $product_context['url'] ?? '' ) );
		if ( '' === $product_url || '' === trim( $html ) ) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/<a\b([^>]*)href=(["\'])(.*?)\2([^>]*)>(.*?)<\/a>/is',
			function ( array $matches ) use ( $product_url ) {
				$link_text = strtolower( trim( wp_strip_all_tags( (string) ( $matches[5] ?? '' ) ) ) );
				if ( ! $this->looks_like_cta_anchor( $link_text ) ) {
					return $matches[0];
				}

				$current_href = html_entity_decode( (string) ( $matches[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
				if ( $this->normalize_url_for_compare( $current_href ) === $this->normalize_url_for_compare( $product_url ) ) {
					return $matches[0];
				}

				return '<a' . $matches[1] . 'href="' . esc_url( $product_url ) . '"' . $matches[4] . '>' . $matches[5] . '</a>';
			},
			$html
		);

		if ( ! is_string( $rewritten ) ) {
			$rewritten = $html;
		}

		if ( false === stripos( $rewritten, $product_url ) ) {
			$cta_label = ! empty( $product_context['name'] )
				? 'Shop ' . (string) $product_context['name']
				: 'View the product';
			$rewritten = rtrim( $rewritten ) . "\n<p><a href=\"" . esc_url( $product_url ) . "\"><strong>" . esc_html( $cta_label ) . "</strong></a></p>";
		}

		return $rewritten;
	}

	private function looks_like_cta_anchor( string $link_text ): bool {
		if ( '' === $link_text ) {
			return false;
		}

		return (bool) preg_match( '/\b(shop|buy|get|order|view|see|discover|learn\s+more|check\s+it\s+out|grab|browse)\b/', $link_text );
	}

	private function normalize_url_for_compare( string $url ): string {
		return untrailingslashit( strtolower( trim( $url ) ) );
	}

	private function build_default_meta_description( array $plan, array $product_context ): string {
		$summary = trim( (string) ( $plan['excerpt'] ?? '' ) );
		if ( '' === $summary ) {
			$summary = trim( (string) ( $product_context['short_description'] ?? $product_context['description'] ?? '' ) );
		}

		$summary = $this->truncate_plain_text( $summary, 170 );
		if ( '' !== $summary ) {
			return sanitize_text_field( $summary );
		}

		$name = trim( (string) ( $product_context['name'] ?? $plan['title'] ?? 'this product' ) );
		return sanitize_text_field( mb_substr( sprintf( 'Discover how %s helps solve common customer pain points.', $name ), 0, 170 ) );
	}

	private function build_default_focus_keyword( array $plan, array $product_context ): string {
		$keyword = trim( (string) ( $product_context['name'] ?? '' ) );
		if ( '' === $keyword ) {
			$keyword = trim( (string) ( $plan['title'] ?? '' ) );
		}

		return sanitize_text_field( mb_substr( $keyword, 0, 120 ) );
	}

	private function build_seo_verification_check( int $post_id, array $plan ): string {
		$fields = array( 'meta_title', 'meta_description', 'og_title', 'og_description', 'og_image', 'focus_keyword' );
		$expected = array_values( array_filter( $fields, static function ( string $field ) use ( $plan ): bool {
			return ! empty( $plan[ $field ] );
		} ) );

		if ( empty( $expected ) ) {
			return '';
		}

		$applied = 0;
		foreach ( $expected as $field ) {
			if ( '' !== PressArk_SEO_Resolver::read( $post_id, $field ) ) {
				$applied++;
			}
		}

		if ( $applied === count( $expected ) ) {
			return 'SEO metadata verified.';
		}

		if ( $applied > 0 ) {
			return 'SEO metadata partially verified.';
		}

		return 'SEO metadata may not have been applied.';
	}

	private function truncate_plain_text( string $text, int $limit ): string {
		$text = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $text ) ) );
		return '' === $text ? '' : mb_substr( $text, 0, $limit );
	}

	/**
	 * Determine whether the workflow should use Elementor for content creation.
	 *
	 * Returns true only when the user explicitly mentions Elementor in their
	 * message. Standard WordPress create_post is used by default — it produces
	 * cleaner pages/posts that work everywhere without builder lock-in.
	 *
	 * @param string $post_type The inferred post type.
	 * @return bool
	 */
	private function should_use_elementor( string $post_type ): bool {
		if ( ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
			return false;
		}

		if ( ! PressArk_Elementor::is_active() ) {
			return false;
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, '3.16.0', '<' ) ) {
			return false;
		}

		// Only use Elementor when the user explicitly asks for it.
		$message = strtolower( (string) ( $this->state['message'] ?? '' ) );
		return (bool) preg_match( '/\b(elementor|page\s*builder|with\s+widgets?)\b/', $message );
	}

	/**
	 * Convert HTML content to an array of Elementor widget definitions.
	 *
	 * Splits at heading boundaries:
	 * - <h1>–<h6> → heading widget (title + header_size)
	 * - All other block content → accumulated into text-editor widget (editor = HTML)
	 *
	 * @param string $html HTML content from the AI plan.
	 * @return array Array of widget definitions [{type, settings}, ...].
	 */
	private function html_to_elementor_widgets( string $html ): array {
		$html = trim( $html );
		if ( '' === $html ) {
			return array();
		}

		// Wrap in a root element for DOMDocument parsing.
		$wrapped = '<div>' . $html . '</div>';

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="UTF-8">' . $wrapped,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		// Find the wrapper div.
		$root = $doc->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $root || ! $root->hasChildNodes() ) {
			// Fallback: treat entire HTML as a single text-editor widget.
			return array( array(
				'type'     => 'text-editor',
				'settings' => array( 'editor' => $html ),
			) );
		}

		$widgets      = array();
		$html_buffer  = '';

		foreach ( $root->childNodes as $node ) {
			// Skip whitespace-only text nodes.
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$html_buffer .= $doc->saveHTML( $node );
				}
				continue;
			}

			if ( $node->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );

			// Heading tags → flush buffer then emit heading widget.
			if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				// Flush accumulated HTML buffer as text-editor widget.
				if ( '' !== trim( $html_buffer ) ) {
					$widgets[]   = array(
						'type'     => 'text-editor',
						'settings' => array( 'editor' => trim( $html_buffer ) ),
					);
					$html_buffer = '';
				}

				$widgets[] = array(
					'type'     => 'heading',
					'settings' => array(
						'title'       => $this->dom_inner_html( $doc, $node ),
						'header_size' => $tag,
					),
				);
				continue;
			}

			// Everything else → accumulate into HTML buffer.
			$html_buffer .= $doc->saveHTML( $node );
		}

		// Flush remaining buffer.
		if ( '' !== trim( $html_buffer ) ) {
			$widgets[] = array(
				'type'     => 'text-editor',
				'settings' => array( 'editor' => trim( $html_buffer ) ),
			);
		}

		// Safety net: if parsing produced nothing, fall back to single widget.
		if ( empty( $widgets ) && '' !== $html ) {
			return array( array(
				'type'     => 'text-editor',
				'settings' => array( 'editor' => $html ),
			) );
		}

		return $widgets;
	}

	/**
	 * Get the inner HTML of a DOM node.
	 *
	 * @param \DOMDocument $doc  The document.
	 * @param \DOMNode     $node The node.
	 * @return string Inner HTML content.
	 */
	private function dom_inner_html( \DOMDocument $doc, \DOMNode $node ): string {
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}
		return trim( $inner );
	}

	/**
	 * Recursively count widgets in an Elementor data structure.
	 *
	 * @param array $elements Elementor elements array.
	 * @return int Widget count.
	 */
	private function count_elementor_widgets( array $elements ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				$count++;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elementor_widgets( $element['elements'] );
			}
		}
		return $count;
	}

	private function compact_knowledge_examples( array $knowledge_samples ): array {
		$examples = array();

		foreach ( array_slice( $knowledge_samples, 0, 3 ) as $sample ) {
			$examples[] = array(
				'title'   => sanitize_text_field( (string) ( $sample['title'] ?? '' ) ),
				'type'    => sanitize_text_field( (string) ( $sample['type'] ?? '' ) ),
				'preview' => mb_substr(
					trim( wp_strip_all_tags( (string) ( $sample['content_preview'] ?? '' ) ) ),
					0,
					220
				),
			);
		}

		return array_values( array_filter( $examples, static function ( array $example ): bool {
			return '' !== ( $example['preview'] ?? '' );
		} ) );
	}
}
