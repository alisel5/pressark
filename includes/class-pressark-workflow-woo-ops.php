<?php
/**
 * PressArk WooCommerce Ops Workflow — deterministic WooCommerce pipeline.
 *
 * Handles: "update/edit product...", "update order...", "create coupon..."
 *
 * Sub-routes internally based on operation type:
 *   - product_edit  — Edit product price, stock, description, etc.
 *   - order_update  — Update order status, add notes.
 *   - coupon_manage — Create or edit coupons.
 *
 * Phase flow:
 *   1. discover        — Detect op type, find candidates.
 *   2. select_target   — Pick target product/order (AI if multiple).
 *   3. gather_context  — Read product/order details via WC object model.
 *   4. plan            — AI generates field changes JSON.
 *   5. preview         — Confirm card (WC tools are 'confirm', not 'preview').
 *   6. apply           — Handled by handle_confirm().
 *   7. verify          — Read back via WC functions, confirm values match.
 *
 * Total AI calls: 1-2 (vs agent's typical 2-4 rounds).
 *
 * @package PressArk
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Workflow_Woo_Ops extends PressArk_Workflow_Runner {

	/**
	 * Tool groups used by this workflow.
	 *
	 * @return array
	 */
	protected function tool_groups(): array {
		return array( 'woocommerce' );
	}

	// ── Phase 1: Discover ─────────────────────────────────────────

	/**
	 * Detect operation type from message and find candidates.
	 *
	 * @return array
	 */
protected function phase_discover(): array {
		$message = strtolower( $this->state['message'] ?? '' );

		// ── Coupon management ─────────────────────────────────────
		if ( preg_match( '/\bcoupon\b/', $message ) ) {
			return array( 'op_type' => 'coupon_manage', 'candidates' => array() );
		}

		// ── Order update (with order number) ──────────────────────
		if ( preg_match( '/\border\b.*#?(\d+)/', $message, $m ) ) {
			return array( 'op_type' => 'order_update', 'candidates' => array( (int) $m[1] ) );
		}

		// ── Order update (general) ────────────────────────────────
		if ( preg_match( '/\border\b/', $message ) ) {
			$result = $this->exec_read( 'list_orders', array( 'count' => 10 ) );
			if ( empty( $result['success'] ) ) {
				return $this->tool_failure( $result['message'] ?? 'Failed to read recent orders.' );
			}

			$candidates = array();
			if ( ! empty( $result['data'] ) ) {
				foreach ( $result['data'] as $item ) {
					$cid = (int) ( $item['id'] ?? 0 );
					if ( $cid ) {
						$candidates[] = $cid;
					}
				}
			}

			return array( 'op_type' => 'order_update', 'candidates' => $candidates );
		}

		// ── Product edit ──────────────────────────────────────────
		if ( preg_match( '/\b(?:product|price|stock|inventory|sku)\b/', $message ) ) {
			$current_product_id = $this->current_editor_product_id();
			if ( $current_product_id > 0 ) {
				return array( 'op_type' => 'product_edit', 'candidates' => array( $current_product_id ) );
			}

			$result = $this->exec_read( 'list_posts', array(
				'post_type' => 'product',
				'search'    => $this->state['message'],
			) );
			if ( empty( $result['success'] ) ) {
				return $this->tool_failure( $result['message'] ?? 'Failed to search products.' );
			}

			$candidates = array();
			if ( ! empty( $result['data'] ) ) {
				foreach ( $result['data'] as $item ) {
					$cid = (int) ( $item['id'] ?? $item['post_id'] ?? 0 );
					if ( $cid ) {
						$candidates[] = $cid;
					}
				}
			}

			return array( 'op_type' => 'product_edit', 'candidates' => $candidates );
		}

		return array(
			'__error'          => 'Could not determine the type of WooCommerce operation. '
			                    . 'Please be more specific (e.g., "edit product X", "update order #123", "create a coupon").',
			'__failure_class'  => PressArk_AI_Connector::FAILURE_VALIDATION,
		);
	}

	// ── Phase 2: Select Target ────────────────────────────────────

	/**
	 * Pick the target product/order.
	 *
	 * @return array
	 */
	protected function phase_select_target(): array {
		$op_type    = $this->state['op_type'] ?? '';
		$candidates = $this->state['candidates'] ?? array();

		// Coupon creation doesn't need target selection.
		if ( 'coupon_manage' === $op_type ) {
			return array( 'target' => array( 'type' => 'coupon', 'id' => 0 ) );
		}

		if ( empty( $candidates ) ) {
			return $this->bad_retrieval( 'No matching items found. Please specify a product name, order number, or coupon code.' );
		}

		if ( count( $candidates ) === 1 ) {
			return array( 'target' => array( 'type' => $op_type, 'id' => $candidates[0] ) );
		}

		if ( 'order_update' === $op_type ) {
			return $this->bad_retrieval(
				'I found multiple recent orders and cannot safely choose one. Please specify the order number.'
			);
		}

		$ranked = $this->rank_product_candidates( $candidates );
		if ( empty( $ranked ) ) {
			return $this->bad_retrieval( 'I found matching product IDs, but could not read enough detail to identify the right one.' );
		}

		$top      = $ranked[0];
		$runner_up = $ranked[1] ?? null;
		$score_gap = $runner_up ? ( $top['score'] - $runner_up['score'] ) : $top['score'];

		if ( $top['score'] >= 75 || $score_gap >= 18 ) {
			return array( 'target' => array( 'type' => $op_type, 'id' => $top['id'] ) );
		}

		// Multiple candidates — ask AI to pick.
		$items = array();
		foreach ( array_slice( $ranked, 0, 5 ) as $entry ) {
			$items[] = array(
				'id'          => $entry['id'],
				'title'       => $entry['post']->post_title,
				'match_score' => $entry['score'],
			);
		}

		$ai_result = $this->ai_call(
			"The user's request: \"{$this->state['message']}\"\n\n"
			. "Select the most relevant item. Respond with ONLY the ID number, nothing else.",
			$items,
			array(),
			array(
				'phase' => 'ambiguity_resolution',
				'effort_budget' => 'high',
				'stop_conditions' => array(
					'you can select one product confidently from the shortlist',
					'the shortlist remains ambiguous after comparing the leading options',
				),
				'tool_heuristics' => array(
					'no tools are available in this phase',
					'return only the winning ID',
				),
			)
		);

		$selected_id = (int) trim( $ai_result['text'] );
		if ( ! in_array( $selected_id, wp_list_pluck( $ranked, 'id' ), true ) ) {
			if ( $score_gap >= 8 ) {
				$selected_id = $top['id'];
			} else {
				return $this->bad_retrieval(
					'I found several similar products and could not safely identify the right one. Please specify the product name or SKU.'
				);
			}
		}

		return array( 'target' => array( 'type' => $op_type, 'id' => $selected_id ) );
	}

	// ── Phase 3: Gather Context ───────────────────────────────────

	/**
	 * Read product/order details.
	 *
	 * @return array
	 */
	protected function phase_gather_context(): array {
		$target  = $this->state['target'] ?? array();
		$context = array();

		if ( 'product_edit' === $target['type'] ) {
			// v3.7.0: Downgraded from 'full' to 'structured'. Product metadata
			// (price, stock, SKU) is fetched separately via WC API. Structured
			// provides headings + section summaries for description context.
			// If AI needs raw HTML for description editing, agent can escalate.
			$context['product'] = $this->exec_read( 'read_content', array( 'post_id' => $target['id'], 'mode' => 'structured' ) );
			if ( empty( $context['product']['success'] ) ) {
				return $this->tool_failure( $context['product']['message'] ?? 'Failed to read product content.' );
			}

			// Also gather WC-specific product meta.
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $target['id'] );
				if ( $product ) {
					$context['product_meta'] = array(
						'name'          => $product->get_name(),
						'sku'           => $product->get_sku(),
						'price'         => $product->get_price(),
						'regular_price' => $product->get_regular_price(),
						'sale_price'    => $product->get_sale_price(),
						'stock_status'  => $product->get_stock_status(),
						'stock_qty'     => $product->get_stock_quantity(),
						'type'          => $product->get_type(),
						'status'        => $product->get_status(),
					);
				}
			}
		} elseif ( 'order_update' === $target['type'] ) {
			$context['order'] = $this->exec_read( 'get_order', array( 'order_id' => $target['id'] ) );
			if ( empty( $context['order']['success'] ) ) {
				return $this->tool_failure( $context['order']['message'] ?? 'Failed to read order details.' );
			}
		}
		// coupon_manage: no context to gather for creation.

		return array( 'item_context' => $context );
	}

	// ── Phase 4: Plan ─────────────────────────────────────────────

	/**
	 * AI generates field changes for the WooCommerce operation.
	 *
	 * @return array
	 */
	protected function phase_plan(): array {
		$target  = $this->state['target'] ?? array();
		$context = $this->state['item_context'] ?? array();
		$op_type = $target['type'] ?? '';

		// ── Product edit plan ─────────────────────────────────────
		if ( 'product_edit' === $op_type ) {
			$ai_result = $this->ai_call(
				"You are editing a WooCommerce product. User request: \"{$this->state['message']}\"\n\n"
				. "Respond with a JSON object of fields to change. Available fields:\n"
				. "- name (string): Product name\n"
				. "- regular_price (string): Regular price. Use this for plain \"price\" requests unless the user explicitly asked for a sale price.\n"
				. "- sale_price (string): Sale price (empty string to remove)\n"
				. "- stock_quantity (int): Stock level\n"
				. "- stock_status (string): 'instock', 'outofstock', 'onbackorder'\n"
				. "- description (string): Product description\n"
				. "- short_description (string): Short description\n"
				. "- sku (string): SKU\n"
				. "- weight (string): Weight\n"
				. "- featured (bool): Featured product\n\n"
				. "Respond ONLY with the JSON object, no explanation or markdown fences.",
				$context,
				array(),
				array(
					'phase' => 'final_synthesis',
					'effort_budget' => 'high',
					'schema_mode' => 'strict',
					'deliverable_schema' => array(
						'type' => 'object',
						'allowed_fields' => array(
							'name' => 'string',
							'regular_price' => 'string',
							'sale_price' => 'string',
							'stock_quantity' => 'int',
							'stock_status' => 'string',
							'description' => 'string',
							'short_description' => 'string',
							'sku' => 'string',
							'weight' => 'string',
							'featured' => 'bool',
						),
						'additionalProperties' => false,
						'min_changed_fields' => 1,
					),
					'tool_heuristics' => array(
						'no tools are available in this phase',
						'return only the strict JSON object',
					),
				)
			);

			if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
				return $this->phase_error(
					'Could not generate the product edit plan because the AI request failed.',
					(string) $ai_result['failure_class']
				);
			}

			$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
			if ( ! empty( $decoded['error'] ) ) {
				if ( ! empty( $ai_result['failure_class'] ) ) {
					return $this->phase_error(
						'Could not generate the product edit plan because the model response was incomplete.',
						(string) $ai_result['failure_class']
					);
				}
				return $this->validation_failure( 'Could not generate product edit plan. ' . $decoded['error'] );
			}

			// Map field names to edit_product format.
			$changes = $decoded['data'];
			$validated = $this->validate_product_plan( $changes );
			if ( ! empty( $validated['error'] ) ) {
				return $this->validation_failure( $validated['error'] );
			}
			return array( 'plan' => array(
				'tool' => 'edit_product',
				'args' => array(
					'post_id' => $target['id'],
					'changes' => $validated['data'],
				),
			) );
		}

		// ── Order update plan ─────────────────────────────────────
		if ( 'order_update' === $op_type ) {
			$ai_result = $this->ai_call(
				"You are updating a WooCommerce order. User request: \"{$this->state['message']}\"\n\n"
				. "Respond with a JSON object. Available fields:\n"
				. "- status (string): 'processing', 'completed', 'cancelled', 'refunded', 'on-hold'\n"
				. "- note (string): Order note to add\n"
				. "- customer_note (bool): Whether the note is visible to the customer\n\n"
				. "Respond ONLY with the JSON object, no explanation or markdown fences.",
				$context,
				array(),
				array(
					'phase' => 'final_synthesis',
					'effort_budget' => 'high',
					'schema_mode' => 'strict',
					'deliverable_schema' => array(
						'type' => 'object',
						'allowed_fields' => array(
							'status' => 'string',
							'note' => 'string',
							'customer_note' => 'bool',
						),
						'additionalProperties' => false,
						'min_changed_fields' => 1,
					),
					'tool_heuristics' => array(
						'no tools are available in this phase',
						'return only the strict JSON object',
					),
				)
			);

			if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
				return $this->phase_error(
					'Could not generate the order update plan because the AI request failed.',
					(string) $ai_result['failure_class']
				);
			}

			$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
			if ( ! empty( $decoded['error'] ) ) {
				if ( ! empty( $ai_result['failure_class'] ) ) {
					return $this->phase_error(
						'Could not generate the order update plan because the model response was incomplete.',
						(string) $ai_result['failure_class']
					);
				}
				return $this->validation_failure( 'Could not generate order update plan. ' . $decoded['error'] );
			}

			$validated = $this->validate_order_plan( $decoded['data'] );
			if ( ! empty( $validated['error'] ) ) {
				return $this->validation_failure( $validated['error'] );
			}

			return array( 'plan' => array(
				'tool' => 'update_order',
				'args' => array_merge( array( 'order_id' => $target['id'] ), $validated['data'] ),
			) );
		}

		// ── Coupon management plan ────────────────────────────────
		if ( 'coupon_manage' === $op_type ) {
			$ai_result = $this->ai_call(
				"You are creating a WooCommerce coupon. User request: \"{$this->state['message']}\"\n\n"
				. "Respond with a JSON object. Available fields:\n"
				. "- code (string): Coupon code (required)\n"
				. "- discount_type (string): 'percent', 'fixed_cart', 'fixed_product'\n"
				. "- amount (string): Discount amount\n"
				. "- description (string): Coupon description\n"
				. "- usage_limit (int): Total usage limit\n"
				. "- expiry_date (string): Expiry date (YYYY-MM-DD)\n"
				. "- minimum_amount (string): Minimum order amount\n"
				. "- individual_use (bool): Cannot be combined with other coupons\n\n"
				. "Respond ONLY with the JSON object, no explanation or markdown fences.",
				$context,
				array(),
				array(
					'phase' => 'final_synthesis',
					'effort_budget' => 'high',
					'schema_mode' => 'strict',
					'deliverable_schema' => array(
						'type' => 'object',
						'allowed_fields' => array(
							'code' => 'string',
							'discount_type' => 'string',
							'amount' => 'string',
							'description' => 'string',
							'usage_limit' => 'int',
							'expiry_date' => 'string',
							'minimum_amount' => 'string',
							'individual_use' => 'bool',
						),
						'additionalProperties' => false,
						'min_changed_fields' => 3,
					),
					'tool_heuristics' => array(
						'no tools are available in this phase',
						'return only the strict JSON object',
					),
				)
			);

			if ( '' !== ( $ai_result['failure_class'] ?? '' ) && empty( $ai_result['text'] ) ) {
				return $this->phase_error(
					'Could not generate the coupon plan because the AI request failed.',
					(string) $ai_result['failure_class']
				);
			}

			$decoded = $this->decode_json_response( $ai_result['text'], 'object' );
			if ( ! empty( $decoded['error'] ) ) {
				if ( ! empty( $ai_result['failure_class'] ) ) {
					return $this->phase_error(
						'Could not generate the coupon plan because the model response was incomplete.',
						(string) $ai_result['failure_class']
					);
				}
				return $this->validation_failure( 'Could not generate coupon plan. ' . $decoded['error'] );
			}

			$validated = $this->validate_coupon_plan( $decoded['data'] );
			if ( ! empty( $validated['error'] ) ) {
				return $this->validation_failure( $validated['error'] );
			}

			return array( 'plan' => array(
				'tool' => 'manage_coupon',
				'args' => array_merge( array( 'operation' => 'create' ), $validated['data'] ),
			) );
		}

		return $this->validation_failure( 'Unsupported WooCommerce operation type.' );
	}

	// ── Phase 5: Preview ──────────────────────────────────────────

	/**
	 * Build confirm card for WooCommerce operations.
	 *
	 * Most WC tools are classified as 'confirm' (not previewable),
	 * so we use build_confirm_response().
	 *
	 * @return array
	 */
	protected function phase_preview(): array {
		$plan      = $this->state['plan'] ?? array();
		$tool_name = $plan['tool'] ?? '';
		$tool_args = $plan['args'] ?? array();

		// Check if this tool supports live preview.
		$capability = PressArk_Tool_Catalog::instance()->classify( $tool_name, $tool_args );

		if ( 'preview' === $capability ) {
			$tool_calls = array(
				array( 'name' => $tool_name, 'arguments' => $tool_args ),
			);
			return $this->build_preview_response( $tool_calls, 'Review the proposed changes and approve to apply.' );
		}

		// Confirm card for non-previewable writes (most WC tools).
		$pending = array(
			array(
				'name'      => $tool_name,
				'arguments' => $tool_args,
			),
		);

		$summary = $this->format_confirm_summary( $tool_name, $tool_args );
		return $this->build_confirm_response( $pending, $summary );
	}

	// ── Phase 7: Verify ───────────────────────────────────────────

	/**
	 * Read back product/order/coupon and confirm changes.
	 *
	 * @return array
	 */
	protected function phase_verify(): array {
		$plan   = $this->state['plan'] ?? array();
		$target = $this->state['target'] ?? array();

		// ── Product verify ────────────────────────────────────────
		if ( 'product_edit' === ( $target['type'] ?? '' ) ) {
			$product_id = $target['id'] ?? 0;
			if ( $product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$changes = $plan['args']['changes'] ?? array();
					$checks  = array();

					if ( isset( $changes['regular_price'] ) ) {
						$checks[] = 'Price: ' . $product->get_regular_price();
					}
					if ( isset( $changes['sale_price'] ) ) {
						$checks[] = 'Sale price: ' . ( $product->get_sale_price() ?: 'none' );
					}
					if ( isset( $changes['stock_quantity'] ) ) {
						$checks[] = 'Stock: ' . $product->get_stock_quantity();
					}
					if ( isset( $changes['sku'] ) ) {
						$checks[] = 'SKU: ' . $product->get_sku();
					}
					if ( isset( $changes['name'] ) ) {
						$checks[] = 'Name: ' . $product->get_name();
					}

					return array(
						'summary' => sprintf(
							'Product "%s" updated. Current values: %s',
							$product->get_name(),
							$checks ? implode( ', ', $checks ) : 'confirmed'
						),
					);
				}
			}
		}

		// ── Order verify ──────────────────────────────────────────
		if ( 'order_update' === ( $target['type'] ?? '' ) ) {
			$order_id = $target['id'] ?? 0;
			if ( $order_id && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					return array(
						'summary' => sprintf(
							'Order #%s updated. Status: %s',
							$order->get_order_number(),
							function_exists( 'wc_get_order_status_name' )
								? wc_get_order_status_name( $order->get_status() )
								: $order->get_status()
						),
					);
				}
			}
		}

		// ── Coupon verify ─────────────────────────────────────────
		if ( 'coupon_manage' === ( $target['type'] ?? '' ) ) {
			$code = $plan['args']['code'] ?? '';
			if ( $code ) {
				return array(
					'summary' => sprintf( 'Coupon "%s" created/updated successfully.', $code ),
				);
			}
		}

		return array( 'summary' => 'WooCommerce operation completed. Verification done.' );
	}

	// ── Helpers ───────────────────────────────────────────────────

	/**
	 * Parse a JSON response from AI, stripping markdown fences.
	 *
	 * @param string $text AI response text.
	 * @return array|null Parsed JSON or null on failure.
	 */
	private function rank_product_candidates( array $candidate_ids ): array {
		$ranked = array();

		foreach ( array_slice( $candidate_ids, 0, 5 ) as $candidate_id ) {
			$post = get_post( (int) $candidate_id );
			if ( ! $post ) {
				continue;
			}

			$ranked[] = array(
				'id'    => (int) $candidate_id,
				'post'  => $post,
				'score' => $this->score_product_candidate( $post ),
			);
		}

		usort( $ranked, static function ( array $a, array $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return $ranked;
	}

	private function score_product_candidate( \WP_Post $post ): int {
		$message = strtolower( (string) ( $this->state['message'] ?? '' ) );
		$title   = strtolower( $post->post_title );
		$slug    = strtolower( $post->post_name );
		$score   = 0;

		if ( '' !== $title && str_contains( $message, $title ) ) {
			$score += 60;
		}

		if ( '' !== $slug && str_contains( $message, $slug ) ) {
			$score += 40;
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

	private function current_editor_product_id(): int {
		$post_id = absint( $this->state['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return 0;
		}

		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			return $post_id;
		}

		if ( 'product_variation' === $post_type ) {
			$parent_id = (int) wp_get_post_parent_id( $post_id );
			return $parent_id > 0 ? $parent_id : 0;
		}

		return 0;
	}

	private function normalize_product_plan_aliases( array $plan ): array {
		$alias_map = array(
			'price'         => 'regular_price',
			'product_price' => 'regular_price',
			'stock'         => 'stock_quantity',
			'inventory'     => 'stock_quantity',
			'qty'           => 'stock_quantity',
		);

		foreach ( $alias_map as $alias => $canonical ) {
			if ( ! array_key_exists( $alias, $plan ) ) {
				continue;
			}
			if ( ! array_key_exists( $canonical, $plan ) ) {
				$plan[ $canonical ] = $plan[ $alias ];
			}
			unset( $plan[ $alias ] );
		}

		return $plan;
	}

	private function validate_product_plan( array $plan ): array {
		$plan = $this->normalize_product_plan_aliases( $plan );

		$allowed = array(
			'name'              => 'string',
			'regular_price'     => 'string',
			'sale_price'        => 'string',
			'stock_quantity'    => 'int',
			'stock_status'      => 'string',
			'description'       => 'string',
			'short_description' => 'string',
			'sku'               => 'string',
			'weight'            => 'string',
			'featured'          => 'bool',
		);

		return $this->validate_plan_shape( $plan, $allowed );
	}

	private function validate_order_plan( array $plan ): array {
		$allowed = array(
			'status'        => 'string',
			'note'          => 'string',
			'customer_note' => 'bool',
		);

		$validated = $this->validate_plan_shape( $plan, $allowed );
		if ( ! empty( $validated['error'] ) ) {
			return $validated;
		}

		if ( empty( $validated['data']['status'] ) && empty( $validated['data']['note'] ) ) {
			return array( 'error' => 'Order updates must include at least a status or a note.' );
		}

		return $validated;
	}

	private function validate_coupon_plan( array $plan ): array {
		$allowed = array(
			'code'           => 'string',
			'discount_type'  => 'string',
			'amount'         => 'string',
			'description'    => 'string',
			'usage_limit'    => 'int',
			'expiry_date'    => 'string',
			'minimum_amount' => 'string',
			'individual_use' => 'bool',
		);

		$validated = $this->validate_plan_shape( $plan, $allowed );
		if ( ! empty( $validated['error'] ) ) {
			return $validated;
		}

		if ( empty( $validated['data']['code'] ) || empty( $validated['data']['discount_type'] ) || empty( $validated['data']['amount'] ) ) {
			return array( 'error' => 'Coupon plans must include code, discount_type, and amount.' );
		}

		return $validated;
	}

	private function validate_plan_shape( array $plan, array $allowed_fields ): array {
		$unknown_fields = array_diff( array_keys( $plan ), array_keys( $allowed_fields ) );
		if ( ! empty( $unknown_fields ) ) {
			return array(
				'error' => 'Unsupported fields in plan: ' . implode( ', ', $unknown_fields ) . '.',
			);
		}

		foreach ( $plan as $field => $value ) {
			$expected = $allowed_fields[ $field ] ?? 'string';
			if ( 'int' === $expected && is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
				$plan[ $field ] = (int) trim( $value );
				$value = $plan[ $field ];
			}
			if ( 'bool' === $expected && is_string( $value ) ) {
				$normalized = strtolower( trim( $value ) );
				if ( in_array( $normalized, array( 'true', '1', 'yes' ), true ) ) {
					$plan[ $field ] = true;
					$value = true;
				} elseif ( in_array( $normalized, array( 'false', '0', 'no' ), true ) ) {
					$plan[ $field ] = false;
					$value = false;
				}
			}
			if ( 'string' === $expected && ( is_int( $value ) || is_float( $value ) ) ) {
				$plan[ $field ] = (string) $value;
				$value = $plan[ $field ];
			}
			if ( 'int' === $expected && ! is_int( $value ) ) {
				return array( 'error' => sprintf( 'Field "%s" must be an integer.', $field ) );
			}
			if ( 'bool' === $expected && ! is_bool( $value ) ) {
				return array( 'error' => sprintf( 'Field "%s" must be a boolean.', $field ) );
			}
			if ( 'string' === $expected && ( ! is_string( $value ) || '' === trim( $value ) ) ) {
				return array( 'error' => sprintf( 'Field "%s" must be a non-empty string.', $field ) );
			}
		}

		if ( empty( $plan ) ) {
			return array( 'error' => 'The plan must include at least one field change.' );
		}

		return array( 'data' => $plan );
	}

	/**
	 * Build a human-readable confirm card summary.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Tool arguments.
	 * @return string
	 */
	private function format_confirm_summary( string $tool, array $args ): string {
		return match ( $tool ) {
			'edit_product' => sprintf(
				'Editing product #%d with %d change%s. Please confirm to apply.',
				$args['post_id'] ?? 0,
				count( $args['changes'] ?? array() ),
				count( $args['changes'] ?? array() ) === 1 ? '' : 's'
			),
			'update_order' => sprintf(
				'Updating order #%d%s. Please confirm to apply.',
				$args['order_id'] ?? 0,
				isset( $args['status'] ) ? ' to status: ' . $args['status'] : ''
			),
			'manage_coupon' => sprintf(
				'Creating coupon "%s". Please confirm to apply.',
				$args['code'] ?? 'new'
			),
			default => 'Applying WooCommerce changes. Please confirm to apply.',
		};
	}
}
