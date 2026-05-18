<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce domain handler for PressArk Action Engine.
 *
 * Handles all WooCommerce-related actions: products, orders, customers,
 * coupons, variations, shipping, tax, reviews, analytics, and alerts.
 *
 * @since 2.7.0
 */
class PressArk_Handler_WooCommerce extends PressArk_Handler_Base {

	/**
	 * Fast pre-execution permission probe for WooCommerce tools.
	 *
	 * @since 5.6.0
	 */
	public function check_permissions( string $tool_name, array $params, array $context = array() ): array {
		$product_id = absint( $params['product_id'] ?? $params['post_id'] ?? $params['id'] ?? 0 );

		switch ( $tool_name ) {
			case 'get_product':
			case 'list_variations':
			case 'list_product_attributes':
			case 'inventory_report':
			case 'stock_report':
			case 'get_top_sellers':
			case 'get_products_on_sale':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'edit_products',
					null,
					__( 'Insufficient permissions.', 'pressark' )
				);

			case 'edit_product':
				return $product_id > 0
					? $this->permission_require_wc(
						$tool_name,
						$params,
						$context,
						'edit_post',
						$product_id,
						__( 'You do not have permission to edit this product.', 'pressark' )
					)
					: $this->permission_require_wc(
						$tool_name,
						$params,
						$context,
						'edit_products',
						null,
						__( 'You do not have permission to edit products.', 'pressark' )
					);

			case 'create_product':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'publish_products',
					null,
					__( 'You do not have permission to create products.', 'pressark' )
				);

			case 'bulk_edit_products':
			case 'edit_variation':
			case 'create_variation':
			case 'bulk_edit_variations':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'edit_products',
					null,
					__( 'You do not have permission to edit products.', 'pressark' )
				);

			case 'analyze_store':
			case 'sales_summary':
			case 'customer_insights':
			case 'category_report':
			case 'revenue_report':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'manage_woocommerce',
					null,
					__( 'You need the "manage_woocommerce" capability for this operation.', 'pressark' )
				);

			case 'list_orders':
			case 'get_order':
			case 'update_order':
			case 'create_refund':
			case 'create_order':
			case 'get_order_statuses':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'edit_shop_orders',
					null,
					__( 'You need the "edit_shop_orders" capability to modify orders and refunds.', 'pressark' )
				);

			case 'manage_coupon':
			case 'list_customers':
			case 'get_customer':
			case 'get_shipping_zones':
			case 'get_tax_settings':
			case 'get_payment_gateways':
			case 'get_wc_settings':
			case 'get_wc_emails':
			case 'get_wc_status':
			case 'manage_webhooks':
			case 'get_wc_alerts':
			case 'trigger_wc_email':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'manage_woocommerce',
					null,
					__( 'You need the "manage_woocommerce" capability for this operation.', 'pressark' )
				);

			case 'email_customer':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'manage_woocommerce',
					null,
					__( 'You need the "manage_woocommerce" capability to send customer emails.', 'pressark' )
				);

			case 'list_reviews':
			case 'moderate_review':
			case 'reply_review':
			case 'bulk_reply_reviews':
				return $this->permission_require_wc(
					$tool_name,
					$params,
					$context,
					'moderate_comments',
					null,
					__( 'Insufficient permissions.', 'pressark' )
				);
		}

		return $this->permission_require_wc( $tool_name, $params, $context );
	}

	// ── v3.7.1 Capability Gates ──────────────────────────────────────

	/**
	 * v3.7.1: Capability-aware WC guard for order/refund operations.
	 * Chat access (editor/author/shop_manager) is NOT sufficient for
	 * financial mutations — the user must hold edit_shop_orders.
	 *
	 * @return array|null Error array if denied, null if OK.
	 */
	private function require_wc_order_cap(): ?array {
		$err = $this->require_wc();
		if ( $err ) return $err;
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $this->error( __( 'You need the "edit_shop_orders" capability to modify orders and refunds.', 'pressark' ) );
		}
		return null;
	}

	/**
	 * v3.7.1: Capability-aware WC guard for store management operations.
	 * Webhooks, settings, and integration changes require manage_woocommerce.
	 *
	 * @return array|null Error array if denied, null if OK.
	 */
	private function require_wc_manage_cap(): ?array {
		$err = $this->require_wc();
		if ( $err ) return $err;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error( __( 'You need the "manage_woocommerce" capability for this operation.', 'pressark' ) );
		}
		return null;
	}

	/**
	 * v3.7.1: Capability-aware WC guard for customer communication.
	 * Sending emails on behalf of the store requires manage_woocommerce
	 * to prevent editors from impersonating the store in customer comms.
	 *
	 * @return array|null Error array if denied, null if OK.
	 */
	private function require_wc_email_cap(): ?array {
		$err = $this->require_wc();
		if ( $err ) return $err;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error( __( 'You need the "manage_woocommerce" capability to send customer emails.', 'pressark' ) );
		}
		return null;
	}

	/**
	 * Get full WooCommerce product data (read-only).
	 *
	 * Returns WC-specific fields that read_content cannot see:
	 * price, stock, categories, attributes, images, SKU, etc.
	 *
	 * @since 4.3.2
	 */
	public function get_product( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;
		// PressArk v5.1.1 hardening: require product edit capability before exposing product details.
		if ( ! current_user_can( 'edit_products' ) ) {
			return $this->error( __( 'Insufficient permissions.', 'pressark' ) );
		}

		$post_id = absint( $params['product_id'] ?? $params['post_id'] ?? $params['id'] ?? 0 );
		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Missing product_id.', 'pressark' ) );
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			/* translators: %d: WooCommerce product ID. */
			return array( 'success' => false, 'message' => sprintf( __( 'Product #%d not found.', 'pressark' ), $post_id ) );
		}

		// Categories.
		$cats = array();
		foreach ( $product->get_category_ids() as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$cats[] = $term->name;
			}

		}

		// Tags.
		$tags = array();
		foreach ( $product->get_tag_ids() as $tag_id ) {
			$term = get_term( $tag_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$tags[] = $term->name;
			}

		}

		// Attributes.
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			$name = $attr->get_name();
			if ( $attr->is_taxonomy() ) {
				$values = wc_get_product_terms( $post_id, $name, array( 'fields' => 'names' ) );
			} else {
				$values = $attr->get_options();
			}
			$attrs[] = array(
				'name'   => wc_attribute_label( $name ),
				'values' => $values,
			);
		}

		// Images.
		$images    = array();
		$thumb_id  = $product->get_image_id();
		if ( $thumb_id ) {
			$images[] = wp_get_attachment_url( $thumb_id );
		}
		foreach ( $product->get_gallery_image_ids() as $img_id ) {
			$images[] = wp_get_attachment_url( $img_id );
		}

		$data = array_merge(
			$this->get_wc_product_state_snapshot( $product ),
			array(
				'url'               => $product->get_permalink(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'categories'        => $cats,
				'tags'              => $tags,
				'attributes'        => $attrs,
				'featured'          => $product->get_featured(),
				'virtual'           => $product->get_virtual(),
				'downloadable'      => $product->get_downloadable(),
				'weight'            => $product->get_weight(),
				'dimensions'        => array(
					'length' => $product->get_length(),
					'width'  => $product->get_width(),
					'height' => $product->get_height(),
				),
				'images'            => $images,
				'total_sales'       => $product->get_total_sales(),
				'average_rating'    => $product->get_average_rating(),
				'review_count'      => $product->get_review_count(),
			)
		);

		// Variable products: include variation count.
		if ( $product->is_type( 'variable' ) ) {
			$data['variation_count'] = count( $product->get_children() );
		}

		return array(
			'success' => true,
			/* translators: 1: product name, 2: product ID. */
			'message' => sprintf( __( 'Product "%1$s" (#%2$d).', 'pressark' ), $product->get_name(), $post_id ),
			'data'    => $data,
		);
	}

	/**
	 * Edit a WooCommerce product.
	 */
	public function edit_product( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$post_id = absint( $params['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return array( 'success' => false, 'message' => __( 'Invalid product ID.', 'pressark' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit this product.', 'pressark' ) );
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return array( 'success' => false, 'message' => __( 'Product not found.', 'pressark' ) );
		}

		// Save checkpoint before editing (for undo).
		$checkpoint_id = $this->create_checkpoint( $post_id, 'edit_product' );

		$ambiguous_price = $this->ambiguous_wc_price_write_response( $params );
		if ( $ambiguous_price ) {
			return $ambiguous_price;
		}

		$changes = $params['changes'] ?? $params;
		if ( is_array( $changes ) ) {
			$ambiguous_price = $this->ambiguous_wc_price_write_response( $changes );
			if ( $ambiguous_price ) {
				return $ambiguous_price;
			}
		}
		$changes = is_array( $changes ) ? $this->normalize_product_changes( $changes ) : array();
		$changed_list = array();
		$had_sale_schedule = (bool) $product->get_date_on_sale_from() || (bool) $product->get_date_on_sale_to();
		$clear_sale        = ! empty( $changes['clear_sale'] )
			|| ( array_key_exists( 'sale_price', $changes ) && '' === (string) $changes['sale_price'] );

		if ( $clear_sale ) {
			$changes['sale_price'] = '';
			unset( $changes['sale_from'], $changes['sale_to'] );
		}

		// PressArk v5.1.1 hardening: normalize and clamp absolute price fields before setters run.
		$regular_price = isset( $changes['regular_price'] ) ? wc_format_decimal( wc_clean( $changes['regular_price'] ) ) : null;
		if ( null !== $regular_price ) {
			$changes['regular_price'] = $this->normalize_wc_price_for_write( $changes['regular_price'] );
		}
		$sale_price = isset( $changes['sale_price'] ) ? wc_format_decimal( wc_clean( $changes['sale_price'] ) ) : null;
		if ( null !== $sale_price ) {
			$changes['sale_price'] = $this->normalize_wc_price_for_write( $changes['sale_price'] );
		}

		// ── Scalar field map: param key => [setter, sanitizer] ──────────
		$field_map = array(
			// Core content.
			'name'              => array( 'set_name',              'sanitize_text_field' ),
			'description'       => array( 'set_description',       'wp_kses_post' ),
			'short_description' => array( 'set_short_description', 'wp_kses_post' ),
			'slug'              => array( 'set_slug',              'sanitize_title' ),
			'status'            => array( 'set_status',            'sanitize_text_field' ),
			'catalog_visibility'=> array( 'set_catalog_visibility','sanitize_text_field' ),
			'purchase_note'     => array( 'set_purchase_note',     'wp_kses_post' ),
			'menu_order'        => array( 'set_menu_order',        'intval' ),

			// Pricing.
			'regular_price'     => array( 'set_regular_price',     'sanitize_text_field' ),
			'sale_price'        => array( 'set_sale_price',        'sanitize_text_field' ),

			// Stock.
			'sku'               => array( 'set_sku',               'sanitize_text_field' ),
			'manage_stock'      => array( 'set_manage_stock',      'wc_string_to_bool' ),
			'stock_quantity'    => array( 'set_stock_quantity',     'intval' ),
			'stock_status'      => array( 'set_stock_status',      'sanitize_text_field' ),
			'backorders'        => array( 'set_backorders',        'sanitize_text_field' ),
			'low_stock_amount'  => array( 'set_low_stock_amount',  'intval' ),

			// Shipping.
			'weight'            => array( 'set_weight',            'sanitize_text_field' ),
			'length'            => array( 'set_length',            'sanitize_text_field' ),
			'width'             => array( 'set_width',             'sanitize_text_field' ),
			'height'            => array( 'set_height',            'sanitize_text_field' ),

			// Tax.
			'tax_status'        => array( 'set_tax_status',        'sanitize_text_field' ),
			'tax_class'         => array( 'set_tax_class',         'sanitize_text_field' ),

			// Flags.
			'featured'          => array( 'set_featured',          'wc_string_to_bool' ),
			'virtual'           => array( 'set_virtual',           'wc_string_to_bool' ),
			'downloadable'      => array( 'set_downloadable',      'wc_string_to_bool' ),
			'sold_individually' => array( 'set_sold_individually', 'wc_string_to_bool' ),
			'reviews_allowed'   => array( 'set_reviews_allowed',   'wc_string_to_bool' ),

			// External product fields.
			'product_url'       => array( 'set_product_url',       'esc_url_raw' ),
			'button_text'       => array( 'set_button_text',       'sanitize_text_field' ),
		);

		foreach ( $field_map as $key => list( $setter, $sanitizer ) ) {
			if ( ! array_key_exists( $key, $changes ) ) continue;
			try {
				$product->$setter( $sanitizer( $changes[ $key ] ) );
				$changed_list[] = ( 'sale_price' === $key && $clear_sale ) ? 'sale_price (cleared)' : $key;
			} catch ( \WC_Data_Exception $e ) {
				return array(
					/* translators: 1: field key, 2: WooCommerce error message. */
					'error'   => sprintf( __( 'Error setting %1$s: %2$s', 'pressark' ), $key, $e->getMessage() ),
					'field'   => $key,
					'value'   => $changes[ $key ],
				);
			}
		}

		if ( $clear_sale ) {
			$product->set_date_on_sale_from( null );
			$product->set_date_on_sale_to( null );
			if ( $had_sale_schedule ) {
				$changed_list[] = 'sale_schedule (cleared)';
			}
		}

		// ── Scheduled sale dates ─────────────────────────────────────────
		if ( array_key_exists( 'sale_from', $changes ) ) {
			// PressArk v5.1.1 hardening: validate sale dates before persisting them.
			if ( '' === (string) $changes['sale_from'] ) {
				$product->set_date_on_sale_from( null );
			} else {
				$sale_from_ts = wc_string_to_timestamp( sanitize_text_field( (string) $changes['sale_from'] ) );
				if ( ! $sale_from_ts ) {
					return array(
						'success' => false,
						'message' => __( 'Invalid sale start date.', 'pressark' ),
						'error'   => __( 'Invalid sale start date.', 'pressark' ),
					);
				}
				$product->set_date_on_sale_from( $sale_from_ts );
			}
			$changed_list[] = 'sale_from';
		}
		if ( array_key_exists( 'sale_to', $changes ) ) {
			// PressArk v5.1.1 hardening: validate sale dates before persisting them.
			if ( '' === (string) $changes['sale_to'] ) {
				$product->set_date_on_sale_to( null );
			} else {
				$sale_to_ts = wc_string_to_timestamp( sanitize_text_field( (string) $changes['sale_to'] ) );
				if ( ! $sale_to_ts ) {
					return array(
						'success' => false,
						'message' => __( 'Invalid sale end date.', 'pressark' ),
						'error'   => __( 'Invalid sale end date.', 'pressark' ),
					);
				}
				$product->set_date_on_sale_to( $sale_to_ts );
			}
			$changed_list[] = 'sale_to';
		}
		if ( isset( $changes['price_delta'] ) && ! isset( $changes['regular_price'] ) ) {
			$current_price = (float) $product->get_regular_price();
			if ( $current_price <= 0 ) {
				$current_price = (float) $product->get_price();
			}
			// PressArk v5.1.1 hardening: prevent relative price edits from persisting negative values.
			$new_price = max( 0, round( $current_price + (float) $changes['price_delta'], wc_get_price_decimals() ) );
			$product->set_regular_price( (string) $new_price );
			$changed_list[] = sprintf( 'regular_price (%+.2f => %.2f)', (float) $changes['price_delta'], $new_price );
		}
		if ( isset( $changes['price_adjust_pct'] ) && ! isset( $changes['regular_price'] ) && ! isset( $changes['price_delta'] ) ) {
			$current_price = (float) $product->get_regular_price();
			if ( $current_price <= 0 ) {
				$current_price = (float) $product->get_price();
			}
			if ( $current_price > 0 ) {
				// PressArk v5.1.1 hardening: prevent percentage price edits from persisting negative values.
				$new_price = max( 0, round( $current_price * ( 1 + ( (float) $changes['price_adjust_pct'] / 100 ) ), wc_get_price_decimals() ) );
				$product->set_regular_price( (string) $new_price );
				$changed_list[] = sprintf( 'regular_price (%+.2f%% => %.2f)', (float) $changes['price_adjust_pct'], $new_price );
			}
		}

		// `sale_adjust_pct` — apply a percentage-off sale. Sets sale_price
		// to regular_price × (1 + pct/100), keeping regular_price intact.
		// This is the canonical primitive for "apply a 10% sale": the user
		// sees "Sale!" with regular_price crossed out and the discounted
		// price active. Without this, the model had to either reduce
		// regular_price (permanent discount, not a "sale") or compute
		// per-product absolute sale_price values, which drifted in the
		// field combinations that produced the $0.00-everywhere bug.
		if ( isset( $changes['sale_adjust_pct'] ) && ! isset( $changes['sale_price'] ) ) {
			$reg_price = (float) $product->get_regular_price();
			if ( $reg_price <= 0 ) {
				$reg_price = (float) $product->get_price();
			}
			$pct = (float) $changes['sale_adjust_pct'];
			if ( $reg_price > 0 && $pct < 0 && $pct > -100 ) {
				$new_sale = max( 0, round( $reg_price * ( 1 + ( $pct / 100 ) ), wc_get_price_decimals() ) );
				if ( $new_sale > 0 && $new_sale < $reg_price ) {
					$product->set_sale_price( (string) $new_sale );
					$changed_list[] = sprintf( 'sale_price (%+.2f%% of regular_price => %.2f)', $pct, $new_sale );
				}
			}
		}

		// ── Array / taxonomy fields ──────────────────────────────────────
		if ( isset( $changes['category_ids'] ) ) {
			$product->set_category_ids( array_map( 'absint', (array) $changes['category_ids'] ) );
			$changed_list[] = 'category_ids';
		}
		if ( isset( $changes['tag_ids'] ) ) {
			$product->set_tag_ids( array_map( 'absint', (array) $changes['tag_ids'] ) );
			$changed_list[] = 'tag_ids';
		}
		if ( isset( $changes['image_id'] ) ) {
			$product->set_image_id( absint( $changes['image_id'] ) );
			$changed_list[] = 'image_id';
		}
		if ( isset( $changes['gallery_image_ids'] ) ) {
			$product->set_gallery_image_ids( array_map( 'absint', (array) $changes['gallery_image_ids'] ) );
			$changed_list[] = 'gallery_image_ids';
		}
		if ( isset( $changes['upsell_ids'] ) ) {
			$product->set_upsell_ids( array_map( 'absint', (array) $changes['upsell_ids'] ) );
			$changed_list[] = 'upsell_ids';
		}
		if ( isset( $changes['cross_sell_ids'] ) ) {
			$product->set_cross_sell_ids( array_map( 'absint', (array) $changes['cross_sell_ids'] ) );
			$changed_list[] = 'cross_sell_ids';
		}

		// ── Shipping class (accepts slug or ID) ──────────────────────────
		if ( isset( $changes['shipping_class'] ) ) {
			$sc = $changes['shipping_class'];
			if ( is_numeric( $sc ) ) {
				$product->set_shipping_class_id( absint( $sc ) );
			} else {
				$term = get_term_by( 'slug', sanitize_title( $sc ), 'product_shipping_class' );
				if ( $term ) $product->set_shipping_class_id( $term->term_id );
			}
			$changed_list[] = 'shipping_class';
		}

		// ── Relative stock adjustment (atomic via WC function) ───────────
		// Use stock_adjust for "+10" / "-5" relative changes.
		// Use stock_quantity for absolute "set to 100" changes.
		if ( isset( $changes['stock_adjust'] ) && ! isset( $changes['stock_quantity'] ) ) {
			$adjust    = (int) $changes['stock_adjust'];
			$operation = $adjust >= 0 ? 'increase' : 'decrease';
			$new_stock = wc_update_product_stock( $product, abs( $adjust ), $operation );
			$changed_list[] = "stock_quantity ({$operation}d by " . abs( $adjust ) . ", now {$new_stock})";
		}

		if ( empty( $changed_list ) ) {
			return array(
				'success' => false,
				'message' => __( 'No product changes specified.', 'pressark' ),
				'hint'    => __( 'Available fields: name, regular_price, sale_price, sale_adjust_pct (percentage-off sale — canonical "apply N% sale"), clear_sale (canonical sale removal), price_delta, price_adjust_pct (permanent regular_price %), sale_from, sale_to, sku, stock_quantity, stock_adjust, stock_status, manage_stock, description, short_description, weight, length, width, height, tax_status, tax_class, featured, virtual, downloadable, category_ids, tag_ids, image_id, gallery_image_ids, and more.', 'pressark' ),
			);
		}

		// save() → updates postmeta + wc_product_meta_lookup + busts transients + fires hooks.
		// v3.7.0: Wrap in try/catch — WC extension hooks (Subscriptions, Memberships,
		// Points & Rewards) can throw during save. We still want to report what happened.
		try {
			$product->save();
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'post_id' => $post_id,
				/* translators: %s: WooCommerce save error message. */
				'message' => sprintf( __( 'Product save failed: %s', 'pressark' ), $e->getMessage() ),
				'hint'    => __( 'A WooCommerce extension blocked the save. Check if Subscriptions, Memberships, or another plugin is throwing errors.', 'pressark' ),
				'changed_before_error' => $changed_list,
			);
		}

		// v3.7.0: Flush WC transient caches to prevent stale data in admin.
		$this->flush_wc_product_cache( $post_id );

		$saved_product = wc_get_product( $post_id );
		$saved_product = $saved_product ?: $product;
		$data          = $this->get_wc_product_state_snapshot( $saved_product );
		$pricing_state = array(
			'regular_price' => $data['regular_price'],
			'sale_price'    => $data['sale_price'],
			'price'         => $data['price'],
			'on_sale'       => $data['on_sale'],
			'sale_from'     => $data['sale_from'],
			'sale_to'       => $data['sale_to'],
		);

		// Log for undo.
		$this->logger->log(
			'edit_product',
			$post_id,
			'product',
			wp_json_encode( array( 'revision_id' => $checkpoint_id, 'fields' => $changed_list ) ),
			wp_json_encode( array( 'changes' => $changed_list ) )
		);

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'name'           => $saved_product->get_name(),
			'changed'        => $changed_list,
			'regular_price'  => $data['regular_price'],
			'sale_price'     => $data['sale_price'],
			'price'          => $data['price'],
			'on_sale'        => $data['on_sale'],
			'sale_from'      => $data['sale_from'],
			'sale_to'        => $data['sale_to'],
			'stock_quantity' => $data['stock_quantity'],
			'stock_status'   => $data['stock_status'],
			'manage_stock'   => $data['manage_stock'],
			'pricing_state'  => $pricing_state,
			'data'           => $data,
			'message'        => sprintf(
				/* translators: 1: comma-separated list of updated product fields, 2: pricing state summary. */
				__( 'Product updated: %1$s. Pricing now %2$s.', 'pressark' ),
				implode( ', ', $changed_list ),
				$this->summarize_wc_product_pricing_state( $pricing_state )
			),
			'lookup_updated' => true,
		);
	}


	/**
	 * Create a new WooCommerce product using the correct WC object model.
	 * Supports simple, variable, grouped, and external product types.
	 */
	public function create_product( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$ambiguous_price = $this->ambiguous_wc_price_write_response( $params );
		if ( $ambiguous_price ) {
			return $ambiguous_price;
		}

		if ( ! current_user_can( 'publish_products' ) ) {
			return array( 'error' => __( 'Insufficient permissions to create products.', 'pressark' ) );
		}

		$type = sanitize_text_field( $params['type'] ?? 'simple' );

		$class_map = array(
			'simple'   => 'WC_Product_Simple',
			'variable' => 'WC_Product_Variable',
			'grouped'  => 'WC_Product_Grouped',
			'external' => 'WC_Product_External',
		);

		$class = $class_map[ $type ] ?? null;
		if ( ! $class || ! class_exists( $class ) ) {
			return array(
				/* translators: %s: requested WooCommerce product type slug. */
				'error'       => sprintf( __( 'Invalid product type \'%s\'.', 'pressark' ), $type ),
				'valid_types' => array_keys( $class_map ),
			);
		}

		$product = new $class();
		$product->set_name( sanitize_text_field( $params['name'] ?? 'New Product' ) );
		$product->set_status( sanitize_text_field( $params['status'] ?? 'draft' ) );

		// First save to get an ID, then apply all other fields via edit_product.
		$product_id = $product->save();

		if ( ! $product_id ) {
			return array( 'error' => __( 'Failed to create product.', 'pressark' ) );
		}

		// Apply all additional fields through edit_product.
		$additional = array_diff_key( $params, array_flip( array( 'type', 'name', 'status' ) ) );
		if ( ! empty( $additional ) ) {
			$additional['post_id'] = $product_id;
			$edit_result = $this->edit_product( $additional );
			if ( ! empty( $edit_result['error'] ) ) {
				return array(
					'success'    => true,
					'partial'    => true,
					'product_id' => $product_id,
					/* translators: %s: product field update error summary. */
					'warning'    => sprintf( __( 'Product created but some fields failed: %s', 'pressark' ), $edit_result['error'] ),
					'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
				);
			}
		}

		return array(
			'success'    => true,
			'product_id' => $product_id,
			'name'       => $params['name'] ?? 'New Product',
			'type'       => $type,
			'status'     => $params['status'] ?? 'draft',
			'edit_url'   => get_edit_post_link( $product_id, 'raw' ),
			/* translators: 1: product type, 2: product name, 3: product ID, 4: product status. */
			'message'    => sprintf( __( 'Created %1$s product "%2$s" (ID: %3$d) as %4$s.', 'pressark' ), $type, $params['name'] ?? 'New Product', $product_id, $params['status'] ?? 'draft' ),
		);
	}


	/**
	 * Bulk edit multiple WooCommerce products.
	 *
	 * v3.7.0: Added batch size cap, per-item exception isolation, WC cache
	 * flush after batch, and partial-success reporting with error details.
	 */
	public function bulk_edit_products( array $params, ?callable $on_progress = null ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;
		// PressArk v5.1.1 hardening: require product edit capability before bulk mutations.
		if ( ! current_user_can( 'edit_products' ) ) {
			return $this->error( __( 'Insufficient permissions.', 'pressark' ) );
		}

		$resolution = $this->resolve_bulk_product_updates( $params );
		if ( ! empty( $resolution['error'] ) ) {
			return array(
				'success' => false,
				'message' => (string) ( $resolution['message'] ?? __( 'No products to update.', 'pressark' ) ),
				'error'   => (string) $resolution['error'],
				'hint'    => (string) ( $resolution['hint'] ?? '' ),
			);
		}

		$products = $resolution['products'] ?? array();
		if ( empty( $products ) ) {
			return array(
				'success' => false,
				'message' => (string) ( $resolution['message'] ?? __( 'No products to update.', 'pressark' ) ),
			);
		}

		// v3.7.0: Cap batch size to prevent timeout on very large bulk ops.
		$max_batch = 50;
		if ( count( $products ) > $max_batch ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: max batch size 2: actual count sent */
					__( 'Bulk edit limited to %1$d products per request. You sent %2$d. Break into smaller batches.', 'pressark' ),
					$max_batch, count( $products )
				),
			);
		}

		$updated             = 0;
		$errors              = 0;
		$details             = array();
		$product_results     = array();
		$updated_ids         = array();
		$verification_target = array();
		$total               = count( $products );

		foreach ( $products as $i => $product_update ) {
			$product_result = array(
				'post_id'                 => (int) ( $product_update['post_id'] ?? 0 ),
				'name'                    => (string) ( $product_update['name'] ?? '' ),
				'type'                    => (string) ( $product_update['type'] ?? 'unknown' ),
				'status'                  => (string) ( $product_update['status'] ?? '' ),
				'price_affecting_changes' => ! empty( $product_update['price_affecting_changes'] ),
				'success'                 => false,
			);
			if ( isset( $product_update['variation_count'] ) ) {
				$product_result['variation_count'] = (int) $product_update['variation_count'];
			}
			if ( isset( $product_update['child_count'] ) ) {
				$product_result['child_count'] = (int) $product_update['child_count'];
			}
			if ( ! empty( $product_update['warning_flags'] ) ) {
				$product_result['warning_flags'] = array_values( array_unique( array_map( 'strval', (array) $product_update['warning_flags'] ) ) );
			}
			if ( ! empty( $product_update['warnings'] ) ) {
				$product_result['warnings'] = array_values( array_unique( array_map( 'strval', (array) $product_update['warnings'] ) ) );
			}

			// v3.7.0: Isolate each product edit so one failure doesn't abort the batch.
			try {
				$result = $this->edit_product( $product_update );
				if ( $result['success'] ?? false ) {
					$updated++;
					$updated_ids[] = (int) ( $product_update['post_id'] ?? 0 );
					if ( empty( $verification_target ) && ! empty( $result['pricing_state'] ) ) {
						$verification_target = array(
							'product_id'    => (int) ( $result['post_id'] ?? $product_update['post_id'] ?? 0 ),
							'name'          => (string) ( $result['name'] ?? '' ),
							'pricing_state' => (array) $result['pricing_state'],
						);
					}
					$product_result['success'] = true;
					$product_result['name']    = (string) ( $result['name'] ?? $product_result['name'] );
					$product_result['type']    = (string) ( $result['data']['type'] ?? $product_result['type'] );
					$product_result['status']  = (string) ( $result['data']['status'] ?? $product_result['status'] );
					$product_result['message'] = (string) ( $result['message'] ?? '' );
					if ( ! empty( $result['changed'] ) ) {
						$product_result['changed'] = array_values( array_map( 'strval', (array) $result['changed'] ) );
					}
					if ( ! empty( $result['pricing_state'] ) ) {
						$product_result['pricing_state'] = (array) $result['pricing_state'];
					}
					if ( isset( $result['data']['variation_count'] ) ) {
						$product_result['variation_count'] = (int) $result['data']['variation_count'];
					}
					if ( isset( $result['data']['child_count'] ) ) {
						$product_result['child_count'] = (int) $result['data']['child_count'];
					}
				} else {
					$errors++;
					$error_message             = (string) ( $result['message'] ?? __( 'Unknown error', 'pressark' ) );
					$product_result['message'] = $error_message;
					if ( ! empty( $result['error'] ) ) {
						$product_result['error'] = (string) $result['error'];
					}
					if ( ! empty( $result['changed_before_error'] ) ) {
						$product_result['changed_before_error'] = array_values( array_map( 'strval', (array) $result['changed_before_error'] ) );
					}
					$details[] = $this->format_wc_bulk_product_error_detail( $product_result, $error_message, $i );
				}
			} catch ( \Throwable $e ) {
				$errors++;
				$product_result['message'] = sprintf(
					/* translators: %s: exception message. */
					__( 'Exception: %s', 'pressark' ),
					$e->getMessage()
				);
				$product_result['error'] = $e->getMessage();
				$details[] = sprintf(
					/* translators: 1: product ID, 2: exception message. */
					__( '#%1$d: Exception — %2$s', 'pressark' ),
					$product_update['post_id'] ?? $i,
					$e->getMessage()
				);
			}

			$product_results[] = $product_result;
			if ( 0 === ( ( $i + 1 ) % 5 ) || ( $i + 1 ) === $total ) {
				// v5.6.0: Streaming progress via on_progress callback (inspired by Claude Code Tool.ts pattern).
				$this->emit_progress(
					$on_progress,
					array(
						'processed' => $i + 1,
						'total'     => $total,
						'updated'   => $updated,
						'errors'    => $errors,
						'tool'      => 'bulk_edit_products',
					)
				);
			}
		}

		// v3.7.0: Flush WC transient caches once after the entire batch.
		$this->flush_wc_batch_caches();

		$scope_label       = $this->describe_wc_bulk_product_scope( $resolution, count( $products ) );
		$scope_description = $this->describe_wc_bulk_product_scope_sentence( $resolution, count( $products ), $scope_label );

		$response = array(
			'success' => $errors === 0,
			'updated' => $updated,
			'errors'  => $errors,
			'matched' => count( $products ),
			'scope_label' => $scope_label,
			'scope_description' => $scope_description,
			'message' => sprintf(
				/* translators: 1: updated count 2: matched count 3: scope label 4: error count */
				__( 'Bulk update: %1$d of %2$d %3$s updated, %4$d error(s).', 'pressark' ),
				$updated,
				count( $products ),
				$scope_label,
				$errors
			),
		);

		if ( ! empty( $resolution['matched_product_types'] ) ) {
			$response['matched_product_types'] = (array) $resolution['matched_product_types'];
		}
		if ( ! empty( $resolution['price_affecting_changes'] ) ) {
			$response['price_affecting_changes'] = true;
		}
		if ( ! empty( $resolution['warning_flags'] ) ) {
			$response['warning_flags'] = array_values( array_unique( array_map( 'strval', (array) $resolution['warning_flags'] ) ) );
		}
		if ( ! empty( $resolution['warnings'] ) ) {
			$response['warnings'] = array_values( array_unique( array_map( 'strval', (array) $resolution['warnings'] ) ) );
			$response['note']     = __( 'Inspect product_results for per-product types and pricing-scope warnings. Variable parent price edits do not update child variation prices.', 'pressark' );
		}
		if ( ! empty( $resolution['scope'] ) ) {
			$response['scope']  = $resolution['scope'];
			$response['status'] = $resolution['status'] ?? 'publish';
		}
		if ( isset( $resolution['limit'] ) ) {
			$response['limit'] = (int) $resolution['limit'];
		}
		if ( isset( $resolution['offset'] ) ) {
			$response['offset'] = (int) $resolution['offset'];
		}
		if ( ! empty( $resolution['truncated'] ) ) {
			$response['truncated'] = true;
		}
		if ( ! empty( $updated_ids ) ) {
			$response['product_ids'] = array_values( array_filter( array_map( 'absint', $updated_ids ) ) );
		}
		if ( ! empty( $verification_target['product_id'] ) ) {
			$response['product_id'] = (int) $verification_target['product_id'];
		}
		if ( ! empty( $verification_target['name'] ) ) {
			$response['verification_target_name'] = $verification_target['name'];
		}
		if ( ! empty( $verification_target['pricing_state'] ) ) {
			$response['pricing_state'] = $verification_target['pricing_state'];
		}
		if ( ! empty( $product_results ) ) {
			$response['product_results'] = $product_results;
		}
		if ( ! empty( $resolution['price_affecting_changes'] ) && count( $products ) > 1 ) {
			$response['pricing_state_note'] = __( 'Top-level pricing_state reflects a single verification target only. Inspect product_results for per-product pricing truth.', 'pressark' );
		}

		if ( ! empty( $details ) ) {
			$response['error_details'] = $details;
		}

		return $response;
	}

	private function resolve_bulk_product_updates( array $params ): array {
		$products = $params['products'] ?? array();

		// v5.2.0: Models often emit large arrays as JSON-encoded strings
		// instead of native JSON arrays. The string may also contain
		// broken JSON (e.g., unescaped quotes like 15\" for inch marks).
		// Strategy: try json_decode first; on failure, extract objects
		// individually with regex as a robust fallback.
		if ( is_string( $products ) && '' !== $products ) {
			$decoded = json_decode( $products, true );
			if ( is_array( $decoded ) ) {
				$products = $decoded;
			} else {
				// Fallback: extract {post_id, changes} objects individually.
				// Each object is self-contained; one broken description
				// shouldn't prevent the other 7 from being parsed.
				$products = $this->extract_product_objects_from_string( $products );
			}
		}

		if ( ! empty( $products ) && is_array( $products ) ) {
			$normalized = array();
			foreach ( $products as $product_update ) {
				if ( ! is_array( $product_update ) ) {
					continue;
				}

				$ambiguous_price = $this->ambiguous_wc_price_write_response( $product_update );
				if ( $ambiguous_price ) {
					return array(
						'error'   => (string) $ambiguous_price['error'],
						'message' => (string) $ambiguous_price['message'],
						'hint'    => (string) $ambiguous_price['hint'],
					);
				}

				// Accept post_id, id, or product_id — models often use 'id'.
				$post_id = absint( $product_update['post_id'] ?? $product_update['id'] ?? $product_update['product_id'] ?? 0 );
				if ( $post_id <= 0 ) {
					continue;
				}

				$changes = $product_update['changes'] ?? $product_update;
				if ( is_array( $changes ) ) {
					$ambiguous_price = $this->ambiguous_wc_price_write_response( $changes );
					if ( $ambiguous_price ) {
						return array(
							'error'   => (string) $ambiguous_price['error'],
							'message' => (string) $ambiguous_price['message'],
							'hint'    => (string) $ambiguous_price['hint'],
						);
					}
				}
				$changes = is_array( $changes ) ? $this->normalize_product_changes( $changes ) : array();
				unset( $changes['post_id'], $changes['changes'] );

				$normalized[] = $this->enrich_wc_bulk_product_update( $post_id, $changes );
			}

			return array_merge(
				array(
					'products' => $normalized,
					'scope'    => 'explicit',
					'message'  => empty( $normalized ) ? __( 'No valid products were supplied for bulk update.', 'pressark' ) : '',
				),
				$this->summarize_wc_bulk_product_updates( $normalized )
			);
		}

		$scope = sanitize_key( (string) ( $params['scope'] ?? '' ) );
		if ( '' === $scope ) {
			return array(
				'error'   => 'missing_products',
				'message' => __( 'Provide either a products array or a scope such as "all".', 'pressark' ),
			);
		}

		$changes = $params['changes'] ?? array();
		if ( is_string( $changes ) && '' !== $changes ) {
			$decoded = json_decode( $changes, true );
			if ( is_array( $decoded ) ) {
				$changes = $decoded;
			}
		}
		if ( is_array( $changes ) ) {
			$ambiguous_price = $this->ambiguous_wc_price_write_response( $changes );
			if ( $ambiguous_price ) {
				return array(
					'error'   => (string) $ambiguous_price['error'],
					'message' => (string) $ambiguous_price['message'],
					'hint'    => (string) $ambiguous_price['hint'],
				);
			}
		}
		$changes = is_array( $changes ) ? $this->normalize_product_changes( $changes ) : array();
		if ( empty( $changes ) ) {
			return array(
				'error'   => 'missing_changes',
				'message' => __( 'No bulk product changes were specified.', 'pressark' ),
			);
		}

		if ( ! in_array( $scope, array( 'all', 'matching' ), true ) ) {
			return array(
				'error'   => 'unsupported_scope',
				'message' => __( 'Supported product bulk scopes are "all" and "matching".', 'pressark' ),
			);
		}

		$status = sanitize_key( (string) ( $params['status'] ?? 'publish' ) );
		if ( '' === $status ) {
			$status = 'publish';
		}

		$offset      = max( 0, absint( $params['offset'] ?? 0 ) );
		$limit       = max( 1, min( 50, absint( $params['limit'] ?? 50 ) ) );
		$search_term = sanitize_text_field( (string) ( $params['search'] ?? '' ) );

		$query = array(
			'status'  => $status,
			'limit'   => $limit + 1,
			'offset'  => $offset,
			'return'  => 'ids',
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( '' !== $search_term ) {
			$query['search'] = $search_term;
		}

		$ids       = wc_get_products( $query );
		$truncated = count( $ids ) > $limit;
		$ids       = array_slice( array_map( 'absint', $ids ), 0, $limit );

		if ( empty( $ids ) ) {
			return array(
				'products' => array(),
				'scope'    => $scope,
				'status'   => $status,
				'limit'    => $limit,
				'offset'   => $offset,
				'message'  => __( 'No matching products found for this bulk update.', 'pressark' ),
			);
		}

		$resolved = array();
		foreach ( $ids as $product_id ) {
			$resolved[] = $this->enrich_wc_bulk_product_update( $product_id, $changes );
		}

		return array_merge(
			array(
				'products'  => $resolved,
				'scope'     => $scope,
				'status'    => $status,
				'limit'     => $limit,
				'offset'    => $offset,
				'truncated' => $truncated,
				'search'    => $search_term,
			),
			$this->summarize_wc_bulk_product_updates( $resolved )
		);
	}

	private function enrich_wc_bulk_product_update( int $post_id, array $changes ): array {
		$product_update = array(
			'post_id'                 => $post_id,
			'changes'                 => $changes,
			'type'                    => 'unknown',
			'price_affecting_changes' => $this->is_wc_price_affecting_change_set( $changes ),
		);

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return $product_update;
		}

		return array_merge( $product_update, $this->get_wc_bulk_product_target_context( $product, $changes ) );
	}

	private function get_wc_bulk_product_target_context( $product, array $changes = array() ): array {
		$type            = (string) $product->get_type();
		$price_affecting = $this->is_wc_price_affecting_change_set( $changes );
		$warning_flags   = array();
		$warnings        = array();
		$context         = array(
			'name'                    => (string) $product->get_name(),
			'type'                    => $type,
			'status'                  => (string) $product->get_status(),
			'price_affecting_changes' => $price_affecting,
		);

		if ( 'variable' === $type ) {
			$context['variation_count'] = count( $product->get_children() );
			if ( $price_affecting ) {
				$warning_flags[] = 'variable_parent_price_edit';
				$warnings[]      = __( 'Variable parent targeted in a bulk price edit. This updates the parent record only and does not update child variation prices.', 'pressark' );
				$warnings[]      = __( 'Use bulk_edit_variations or edit individual variations if variation prices need to change.', 'pressark' );
			}
		} elseif ( 'grouped' === $type ) {
			$context['child_count'] = count( $product->get_children() );
			if ( $price_affecting ) {
				$warning_flags[] = 'grouped_parent_price_edit';
				$warnings[]      = __( 'Grouped parent targeted in a bulk price edit. Grouped child product prices may still need separate updates.', 'pressark' );
			}
		} elseif ( 'external' === $type && $price_affecting ) {
			$warning_flags[] = 'external_product_price_edit';
			$warnings[]      = __( 'External product targeted in a bulk price edit. Confirm the customer-visible price and outbound link behavior after the write.', 'pressark' );
		}

		if ( ! empty( $warning_flags ) ) {
			$context['warning_flags'] = array_values( array_unique( $warning_flags ) );
		}
		if ( ! empty( $warnings ) ) {
			$context['warnings'] = array_values( array_unique( $warnings ) );
		}

		return $context;
	}

	private function summarize_wc_bulk_product_updates( array $products ): array {
		$matched_product_types  = array();
		$warning_flags          = array();
		$warnings               = array();
		$price_affecting_change = false;

		foreach ( $products as $product_update ) {
			$type = sanitize_key( (string) ( $product_update['type'] ?? 'unknown' ) );
			if ( '' === $type ) {
				$type = 'unknown';
			}

			if ( ! isset( $matched_product_types[ $type ] ) ) {
				$matched_product_types[ $type ] = 0;
			}
			$matched_product_types[ $type ]++;

			if ( ! empty( $product_update['price_affecting_changes'] ) ) {
				$price_affecting_change = true;
			}
			if ( ! empty( $product_update['warning_flags'] ) ) {
				$warning_flags = array_merge( $warning_flags, array_map( 'strval', (array) $product_update['warning_flags'] ) );
			}
			if ( ! empty( $product_update['warnings'] ) ) {
				$warnings = array_merge( $warnings, array_map( 'strval', (array) $product_update['warnings'] ) );
			}
		}

		ksort( $matched_product_types );

		$summary = array(
			'matched_product_types' => $matched_product_types,
		);
		if ( $price_affecting_change ) {
			$summary['price_affecting_changes'] = true;
		}
		if ( ! empty( $warning_flags ) ) {
			$summary['warning_flags'] = array_values( array_unique( $warning_flags ) );
		}
		if ( ! empty( $warnings ) ) {
			$summary['warnings'] = array_values( array_unique( $warnings ) );
		}

		return $summary;
	}

	private function describe_wc_bulk_product_scope( array $resolution, int $matched_count ): string {
		$scope  = sanitize_key( (string) ( $resolution['scope'] ?? 'explicit' ) );
		$status = $this->wc_product_status_label( sanitize_key( (string) ( $resolution['status'] ?? '' ) ) );
		$prefix = '' !== $status ? $status . ' ' : '';

		if ( ! empty( $resolution['truncated'] ) ) {
			return sprintf(
				/* translators: %s: product status label */
				__( 'first matched %sWooCommerce product(s) in this batch', 'pressark' ),
				$prefix
			);
		}

		if ( 'matching' === $scope ) {
			$search = sanitize_text_field( (string) ( $resolution['search'] ?? '' ) );
			if ( '' !== $search ) {
				return sprintf(
					/* translators: 1: product status label 2: search term */
					__( '%1$sWooCommerce product(s) matching "%2$s"', 'pressark' ),
					$prefix,
					$search
				);
			}

			return sprintf(
				/* translators: %s: product status label */
				__( 'matching %sWooCommerce product(s)', 'pressark' ),
				$prefix
			);
		}

		if ( 'all' === $scope ) {
			return sprintf(
				/* translators: %s: product status label */
				__( '%sWooCommerce product(s)', 'pressark' ),
				$prefix
			);
		}

		return __( 'selected WooCommerce product(s)', 'pressark' );
	}

	private function describe_wc_bulk_product_scope_sentence( array $resolution, int $matched_count, string $scope_label ): string {
		$scope = sanitize_key( (string) ( $resolution['scope'] ?? 'explicit' ) );
		if ( 'explicit' === $scope ) {
			return sprintf(
				/* translators: 1: product count 2: scope label */
				__( 'Resolved %1$d %2$s from the supplied product list.', 'pressark' ),
				$matched_count,
				$scope_label
			);
		}

		if ( ! empty( $resolution['truncated'] ) ) {
			return sprintf(
				/* translators: 1: scope name 2: product count 3: scope label */
				__( 'Scope "%1$s" resolved to %2$d %3$s; additional matched products may require another batch.', 'pressark' ),
				$scope,
				$matched_count,
				$scope_label
			);
		}

		// v5.8.16 (2026-05-14): expose the resolved WooCommerce scope/count so
		// wraps do not overclaim "all products" when the tool only matched
		// product posts, not pages/posts or other site content.
		return sprintf(
			/* translators: 1: scope name 2: product count 3: scope label */
			__( 'Scope "%1$s" resolved to %2$d %3$s; this does not include pages, posts, or other non-product content.', 'pressark' ),
			$scope,
			$matched_count,
			$scope_label
		);
	}

	private function wc_product_status_label( string $status ): string {
		if ( '' === $status ) {
			return '';
		}

		$labels = array(
			'publish' => __( 'published', 'pressark' ),
			'draft'   => __( 'draft', 'pressark' ),
			'private' => __( 'private', 'pressark' ),
			'pending' => __( 'pending', 'pressark' ),
			'any'     => __( 'matched', 'pressark' ),
		);

		return $labels[ $status ] ?? str_replace( '_', ' ', $status );
	}

	private function format_wc_bulk_product_error_detail( array $product_result, string $message, int $fallback_index ): string {
		$post_id = (int) ( $product_result['post_id'] ?? $fallback_index );
		$name    = (string) ( $product_result['name'] ?? '' );
		$type    = (string) ( $product_result['type'] ?? '' );

		if ( '' !== $name && '' !== $type ) {
			return sprintf(
				/* translators: 1: product ID 2: product name 3: product type 4: error message */
				__( '#%1$d (%2$s, %3$s): %4$s', 'pressark' ),
				$post_id,
				$name,
				$type,
				$message
			);
		}

		if ( '' !== $name ) {
			return sprintf(
				/* translators: 1: product ID 2: product name 3: error message */
				__( '#%1$d (%2$s): %3$s', 'pressark' ),
				$post_id,
				$name,
				$message
			);
		}

		if ( '' !== $type ) {
			return sprintf(
				/* translators: 1: product ID 2: product type 3: error message */
				__( '#%1$d (%2$s): %3$s', 'pressark' ),
				$post_id,
				$type,
				$message
			);
		}

		return sprintf(
			/* translators: 1: product ID 2: error message */
			__( '#%1$d: %2$s', 'pressark' ),
			$post_id,
			$message
		);
	}


	/**
	 * Analyze WooCommerce store health.
	 */
	public function analyze_store( array $params = array() ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$products = wc_get_products( array(
			'limit'  => 100,
			'status' => 'publish',
		) );

		$issues = array(
			'no_description'       => array(),
			'no_short_description' => array(),
			'no_image'             => array(),
			'no_categories'        => array(),
			'no_price'             => array(),
			'low_stock'            => array(),
		);

		foreach ( $products as $product ) {
			$pid  = $product->get_id();
			$name = $product->get_name();

			if ( empty( trim( $product->get_description() ) ) ) {
				$issues['no_description'][] = array( 'id' => $pid, 'name' => $name );
			}
			if ( empty( trim( $product->get_short_description() ) ) ) {
				$issues['no_short_description'][] = array( 'id' => $pid, 'name' => $name );
			}
			if ( ! $product->get_image_id() ) {
				$issues['no_image'][] = array( 'id' => $pid, 'name' => $name );
			}
			$cats = $product->get_category_ids();
			if ( empty( $cats ) ) {
				$issues['no_categories'][] = array( 'id' => $pid, 'name' => $name );
			}
			if ( '' === $product->get_regular_price() && '' === $product->get_price() ) {
				$issues['no_price'][] = array( 'id' => $pid, 'name' => $name );
			}
			if ( $product->managing_stock() && $product->get_stock_quantity() !== null && $product->get_stock_quantity() <= 3 ) {
				$issues['low_stock'][] = array( 'id' => $pid, 'name' => $name, 'stock' => $product->get_stock_quantity() );
			}
		}

		// ── Additional product quality checks ───────────────────────────

		$flags = array();

		// Sale price higher than regular price (common mistake).
		$inverted_sales = array();
		foreach ( $products as $product ) {
			$reg  = floatval( $product->get_regular_price() );
			$sale = floatval( $product->get_sale_price() );
			if ( $sale > 0 && $reg > 0 && $sale >= $reg ) {
				$inverted_sales[] = array(
					'id'            => $product->get_id(),
					'name'          => $product->get_name(),
					'regular_price' => wc_price( $reg ),
					'sale_price'    => wc_price( $sale ),
				);
			}
		}

		// Expired scheduled sales still showing.
		$expired_sales = array();
		foreach ( $products as $product ) {
			$sale_to = $product->get_date_on_sale_to();
			if ( $sale_to && $sale_to->getTimestamp() < time() && $product->get_sale_price() ) {
				$expired_sales[] = array(
					'id'      => $product->get_id(),
					'name'    => $product->get_name(),
					'expired' => $sale_to->date( 'Y-m-d' ),
				);
			}
		}

		// Variable products with no variations (broken products).
		$no_variations = array();
		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) && empty( $product->get_children() ) ) {
				$no_variations[] = array(
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
				);
			}
		}

		// Physical products missing weight (causes shipping calculation issues).
		$no_weight = array();
		foreach ( $products as $product ) {
			if ( $product->needs_shipping() && ! $product->get_weight() && ! $product->is_type( 'variable' ) ) {
				$no_weight[] = array(
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
				);
			}
		}

		// Duplicate SKUs (data integrity issue).
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only duplicate-SKU diagnostic on a core WooCommerce table for an on-demand admin report.
		$dup_skus_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value as sku, COUNT(*) as count
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value != ''
			 GROUP BY meta_value HAVING count > 1
			 LIMIT %d",
			'_sku', 20
		) );
		$dup_skus = array_map( fn( $r ) => array(
			'sku'   => $r->sku,
			'count' => (int) $r->count,
		), $dup_skus_raw );

		// Lookup table desync (prices differ between postmeta and lookup table).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookup consistency diagnostic on core WooCommerce tables for an on-demand admin report.
		$desync = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title,
					pm.meta_value as meta_price,
					lk.min_price as lookup_price
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 JOIN {$wpdb->prefix}wc_product_meta_lookup lk ON p.ID = lk.product_id
			 WHERE p.post_type = %s
			   AND pm.meta_value != ''
			   AND ABS( CAST(pm.meta_value AS DECIMAL(19,4)) - lk.min_price ) > 0.001
			 LIMIT %d",
			'_price', 'product', 10
		) );

		// Build flags summary.
		if ( ! empty( $inverted_sales ) ) {
			/* translators: %d: number of products with sale price greater than or equal to regular price. */
			$flags[] = sprintf( __( '%d product(s) have sale price >= regular price.', 'pressark' ), count( $inverted_sales ) );
		}
		if ( ! empty( $expired_sales ) ) {
			/* translators: %d: number of products with expired scheduled sales still active. */
			$flags[] = sprintf( __( '%d product(s) have expired scheduled sales still active.', 'pressark' ), count( $expired_sales ) );
		}
		if ( ! empty( $no_variations ) ) {
			/* translators: %d: number of variable products without any variations. */
			$flags[] = sprintf( __( '%d variable product(s) have no variations — they cannot be purchased.', 'pressark' ), count( $no_variations ) );
		}
		if ( ! empty( $dup_skus ) ) {
			/* translators: %d: number of duplicate product SKUs found. */
			$flags[] = sprintf( __( '%d duplicate SKU(s) found — may cause order processing issues.', 'pressark' ), count( $dup_skus ) );
		}
		if ( ! empty( $desync ) ) {
			/* translators: %d: number of products with lookup table price mismatches. */
			$flags[] = sprintf( __( '%d product(s) have price mismatch between postmeta and lookup table. Run WooCommerce → Status → Tools → Update product lookup tables.', 'pressark' ), count( $desync ) );
		}

		// Add detailed results.
		$issues['inverted_sales']  = $inverted_sales;
		$issues['expired_sales']   = $expired_sales;
		$issues['no_variations']   = $no_variations;
		$issues['no_weight']       = $no_weight;
		$issues['duplicate_skus']  = $dup_skus;
		$issues['lookup_desync']   = array_map( fn( $r ) => array(
			'id'           => $r->ID,
			'name'         => $r->post_title,
			'meta_price'   => $r->meta_price,
			'lookup_price' => $r->lookup_price,
			'fix'          => __( 'Run WooCommerce → Status → Tools → Update product lookup tables', 'pressark' ),
		), $desync );

		$total_issues = 0;
		foreach ( $issues as $list ) {
			$total_issues += count( $list );
		}

		$total_products = count( $products );
		$score          = $total_products > 0 ? max( 0, 100 - (int) ( ( $total_issues / $total_products ) * 20 ) ) : 100;

		$report = array(
			'total_products'  => $total_products,
			'total_issues'    => $total_issues,
			'score'           => $score,
			'issues'          => $issues,
			'flags'           => $flags,
			'summary'         => sprintf(
				/* translators: 1: total products 2: total issues 3: issue category count */
				__( 'Analyzed %1$d products, found %2$d issues across %3$d categories.', 'pressark' ),
				$total_products,
				$total_issues,
				count( array_filter( $issues, function ( $list ) {
					return ! empty( $list );
				} ) )
			),
		);

		return array(
			'success'      => true,
			'message'      => '',
			'data'         => $report,
			'scanner_type' => 'store',
		);
	}


	// ── Part F: WooCommerce Deep Tools ────────────────────────────────

	/**
	 * List WooCommerce orders.
	 */
	public function list_orders( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$count  = min( absint( $params['count'] ?? 20 ), 50 );
		$offset = absint( $params['offset'] ?? 0 );
		$status = sanitize_text_field( $params['status'] ?? 'any' );

		$args = array(
			'limit'   => $count,
			'offset'  => $offset,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( 'any' !== $status ) {
			$args['status'] = 'wc-' . ltrim( $status, 'wc-' );
		}
		// Date filtering — WC native range syntax, same dimension (date_created).
		if ( ! empty( $params['date_after'] ) && ! empty( $params['date_before'] ) ) {
			$args['date_created'] = sanitize_text_field( $params['date_after'] )
				. '...'
				. sanitize_text_field( $params['date_before'] );
		} elseif ( ! empty( $params['date_after'] ) ) {
			$args['date_created'] = '>' . sanitize_text_field( $params['date_after'] );
		} elseif ( ! empty( $params['date_before'] ) ) {
			$args['date_created'] = '<' . sanitize_text_field( $params['date_before'] );
		}

		// Always set type explicitly to avoid mixing shop_order_refund objects.
		$args['type'] = 'shop_order';

		// Customer and payment method filtering.
		if ( ! empty( $params['customer_id'] ) ) {
			$args['customer_id'] = absint( $params['customer_id'] );
		}
		if ( ! empty( $params['customer_email'] ) ) {
			$args['billing_email'] = sanitize_email( $params['customer_email'] );
		}
		if ( ! empty( $params['payment_method'] ) ) {
			$args['payment_method'] = sanitize_text_field( $params['payment_method'] );
		}

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		$orders = wc_get_orders( $args );
		$list   = array();

		foreach ( $orders as $order ) {
			$order_data = array(
				'id'        => $order->get_id(),
				'number'    => $order->get_order_number(),
				'status'    => $order->get_status(),
				'total'     => $order->get_total(),
				'currency'  => $order->get_currency(),
				'customer'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email'     => $order->get_billing_email(),
				'items'     => $order->get_item_count(),
				'date'      => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			);

			// C1: Intelligence flags.
			$flags      = array();
			$created    = $order->get_date_created();
			$age_hours  = $created ? ( time() - $created->getTimestamp() ) / 3600 : 0;

			// Stuck in processing.
			if ( $order->get_status() === 'processing' && $age_hours > 48 ) {
				$flags[] = 'stuck_processing: ' . round( $age_hours / 24 ) . ' days old';
			}

			// High value order.
			$total = (float) $order->get_total();
			if ( $total > 200 ) {
				$flags[] = 'high_value: ' . wp_strip_all_tags( wc_price( $total ) );
			}

			// Pending refund.
			if ( $order->get_status() === 'refunded' || count( $order->get_refunds() ) > 0 ) {
				$flags[] = 'has_refund';
			}

			// Failed order.
			if ( $order->get_status() === 'failed' ) {
				$flags[] = 'failed_payment';
			}

			$order_data['flags']     = $flags;
			$order_data['age_hours'] = round( $age_hours );

			$list[] = $order_data;
		}

		// Get total order count for pagination metadata.
		$total_args         = $args;
		$total_args['limit']  = -1;
		$total_args['offset'] = 0;
		$total_args['return'] = 'ids';
		$total_orders         = count( wc_get_orders( $total_args ) );

		return array(
			'success'     => true,
			/* translators: %d: number of WooCommerce orders found. */
			'message'     => sprintf( __( 'Found %d order(s).', 'pressark' ), count( $list ) ),
			'data'        => $list,
			'_pagination' => array(
				'total'    => $total_orders,
				'offset'   => $offset,
				'limit'    => $count,
				'has_more' => ( $offset + $count ) < $total_orders,
			),
		);
	}


	/**
	 * Get single order details.
	 */
	public function get_order( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$order_id = absint( $params['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return array( 'success' => false, 'message' => __( 'Order ID is required.', 'pressark' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'pressark' ) );
		}

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product  = $item->get_product();
			$items[] = array(
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'tax_total'    => $item->get_total_tax(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'sku'          => $product ? $product->get_sku() : '',
				'meta'         => array_map(
					fn( $m ) => array( 'label' => $m->display_key, 'value' => $m->display_value ),
					$item->get_formatted_meta_data( '' )
				),
			);
		}

		// Fee lines.
		$fee_lines = array();
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fee_lines[] = array(
				'name'  => $fee->get_name(),
				'total' => $fee->get_total(),
			);
		}

		// Shipping lines.
		$shipping_lines = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			$shipping_lines[] = array(
				'method' => $shipping->get_method_title(),
				'total'  => $shipping->get_total(),
			);
		}

		// Coupon lines.
		$coupon_lines = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon ) {
			$coupon_lines[] = array(
				'code'     => $coupon->get_code(),
				'discount' => $coupon->get_discount(),
			);
		}

		$notes = array();
		$order_notes = wc_get_order_notes( array( 'order_id' => $order_id, 'limit' => 10 ) );
		foreach ( $order_notes as $note ) {
			$notes[] = array(
				'content'       => $note->content,
				'date'          => $note->date_created->date( 'Y-m-d H:i:s' ),
				'customer_note' => $note->customer_note,
			);
		}

		$data = array(
			'id'               => $order->get_id(),
			'number'           => $order->get_order_number(),
			'status'           => $order->get_status(),
			'total'            => $order->get_total(),
			'formatted_total'  => $order->get_formatted_order_total(),
			'subtotal'         => $order->get_subtotal(),
			'tax'              => $order->get_total_tax(),
			'shipping'         => $order->get_shipping_total(),
			'discount'         => $order->get_discount_total(),
			'total_refunded'   => (float) $order->get_total_refunded(),
			'currency'         => $order->get_currency(),
			'payment'          => $order->get_payment_method_title(),
			'payment_method'   => $order->get_payment_method(),
			'transaction_id'   => $order->get_transaction_id(),
			'needs_payment'    => $order->needs_payment(),
			'needs_processing' => $order->needs_processing(),
			'is_paid'          => $order->is_paid(),
			'customer_id'      => $order->get_customer_id(),
			'customer_note'    => $order->get_customer_note(),
			'coupon_codes'     => $order->get_coupon_codes(),
			'billing'          => array(
				'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email'   => $order->get_billing_email(),
				'phone'   => $order->get_billing_phone(),
				'address' => $order->get_formatted_billing_address(),
			),
			'shipping_address' => $order->get_formatted_shipping_address(),
			'items'            => $items,
			'fee_lines'        => $fee_lines,
			'shipping_lines'   => $shipping_lines,
			'coupon_lines'     => $coupon_lines,
			'downloads'        => array_map(
				fn( $d ) => array(
					'name'                => $d['name'],
					'downloads_remaining' => $d['downloads_remaining'],
					'access_expires'      => $d['access_expires'],
				),
				$order->get_downloadable_items()
			),
			'notes'            => $notes,
			'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			'date_paid'        => $order->get_date_paid()
				? $order->get_date_paid()->date( 'Y-m-d H:i:s' )
				: null,
			'date_completed'   => $order->get_date_completed()
				? $order->get_date_completed()->date( 'Y-m-d H:i:s' )
				: null,
		);

		return array(
			'success' => true,
			/* translators: %s: WooCommerce order number. */
			'message' => sprintf( __( 'Order #%s details.', 'pressark' ), $order->get_order_number() ),
			'data'    => $data,
		);
	}


	/**
	 * Update order status or add note.
	 */
	public function update_order( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to edit orders.', 'pressark' ) );
		}

		$order_id = absint( $params['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return array( 'success' => false, 'message' => __( 'Order ID is required.', 'pressark' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'pressark' ) );
		}

		$changes = array();
		$old_status = $order->get_status();

		if ( ! empty( $params['status'] ) ) {
			$new_status = sanitize_text_field( $params['status'] );
			// Normalize: strip 'wc-' prefix if present (both formats are common).
			$bare_status  = ltrim( $new_status, 'wc-' );
			$valid_keys   = array_keys( wc_get_order_statuses() );
			$valid_bare   = array_map( fn( $s ) => ltrim( $s, 'wc-' ), $valid_keys );
			if ( ! in_array( $bare_status, $valid_bare, true ) ) {
				return array(
					'success'        => false,
					/* translators: 1: requested order status, 2: comma-separated list of valid order statuses. */
					'message'        => sprintf( __( 'Invalid order status: %1$s. Valid statuses: %2$s', 'pressark' ), $new_status, implode( ', ', $valid_bare ) ),
					'valid_statuses' => $valid_bare,
				);
			}
			$order->update_status( $bare_status );
			/* translators: 1: previous order status, 2: new order status. */
			$changes[] = sprintf( __( 'status: %1$s → %2$s', 'pressark' ), $old_status, $bare_status );
		}

		if ( ! empty( $params['note'] ) ) {
			$is_customer = ! empty( $params['customer_note'] );
			$order->add_order_note( sanitize_textarea_field( $params['note'] ), $is_customer ? 1 : 0 );
			$changes[] = __( 'note added', 'pressark' );
		}

		if ( empty( $changes ) ) {
			return array( 'success' => false, 'message' => __( 'No changes specified.', 'pressark' ) );
		}

		$this->logger->log(
			'update_order',
			$order_id,
			'shop_order',
			wp_json_encode( array( 'status' => $old_status ) ),
			wp_json_encode( $params )
		);

		return array(
			'success' => true,
			/* translators: 1: WooCommerce order number, 2: comma-separated list of applied changes. */
			'message' => sprintf( __( 'Updated order #%1$s: %2$s.', 'pressark' ), $order->get_order_number(), implode( ', ', $changes ) ),
		);
	}


	/**
	 * Manage WooCommerce coupons.
	 */
	public function manage_coupon( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to manage coupons.', 'pressark' ) );
		}

		$operation = sanitize_text_field( $params['operation'] ?? $params['action'] ?? '' );

		switch ( $operation ) {
			case 'get':
				$coupon_id = absint( $params['coupon_id'] ?? 0 );

				// Also support lookup by code.
				if ( empty( $coupon_id ) && ! empty( $params['code'] ) ) {
					$coupon_id = wc_get_coupon_id_by_code( sanitize_text_field( $params['code'] ) );
				}
				if ( ! $coupon_id ) {
					return array( 'success' => false, 'message' => __( 'Provide coupon_id or code.', 'pressark' ) );
				}

				$coupon = new \WC_Coupon( $coupon_id );
				if ( ! $coupon->get_id() ) {
					return array( 'success' => false, 'message' => __( 'Coupon not found.', 'pressark' ) );
				}

				return array(
					'success' => true,
					'data'    => $this->coupon_snapshot( $coupon ),
				);

			case 'list':
				$coupons = get_posts( array(
					'post_type'              => 'shop_coupon',
					'post_status'            => 'publish',
					'numberposts'            => min( absint( $params['limit'] ?? 20 ), 50 ),
					'orderby'                => sanitize_key( (string) ( $params['orderby'] ?? 'date' ) ),
					'order'                  => 'DESC',
					'update_post_meta_cache' => false,
				) );

				$list = array();
				foreach ( $coupons as $post ) {
					$c      = new \WC_Coupon( $post->ID );
					$list[] = array(
						'id'         => $c->get_id(),
						'code'       => $c->get_code(),
						'type'       => $c->get_discount_type(),
						'amount'     => $c->get_amount(),
						'usage'      => $c->get_usage_count() . '/' . ( $c->get_usage_limit() ?: "\u{221E}" ),
						'expires'    => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : 'Never',
						'is_expired' => $c->get_date_expires()
							&& $c->get_date_expires()->getTimestamp() < time(),
					);
				}

				return array( 'success' => true, 'count' => count( $list ), 'data' => $list );

			case 'create':
				$code = sanitize_text_field( $params['code'] ?? '' );
				if ( empty( $code ) ) {
					return array( 'success' => false, 'message' => __( 'Coupon code is required.', 'pressark' ) );
				}

				$coupon = new \WC_Coupon();
				$coupon->set_code( $code );
				$this->apply_coupon_changes( $coupon, $params );

				$coupon->save();
				$this->logger->log( 'manage_coupon', $coupon->get_id(), 'shop_coupon', null, wp_json_encode( array( 'code' => $code, 'operation' => 'create' ) ) );

				return array(
					'success'   => true,
					'coupon_id' => $coupon->get_id(),
					'data'      => $this->coupon_snapshot( $coupon ),
					/* translators: 1: coupon code, 2: coupon ID. */
					'message'   => sprintf( __( 'Created coupon "%1$s" (ID: %2$d).', 'pressark' ), $code, $coupon->get_id() ),
				);

			case 'edit':
				$coupon_id = absint( $params['coupon_id'] ?? 0 );
				if ( ! $coupon_id ) {
					return array( 'success' => false, 'message' => __( 'Coupon ID is required.', 'pressark' ) );
				}
				$coupon = new \WC_Coupon( $coupon_id );
				if ( ! $coupon->get_id() ) {
					return array( 'success' => false, 'message' => __( 'Coupon not found.', 'pressark' ) );
				}
				$this->apply_coupon_changes( $coupon, $params );
				$coupon->save();

				return array(
					'success'   => true,
					'coupon_id' => $coupon->get_id(),
					'data'      => $this->coupon_snapshot( $coupon ),
					/* translators: %s: coupon code. */
					'message'   => sprintf( __( 'Updated coupon "%s".', 'pressark' ), $coupon->get_code() ),
				);

			case 'delete':
				$coupon_id = absint( $params['coupon_id'] ?? 0 );
				if ( ! $coupon_id ) {
					return array( 'success' => false, 'message' => __( 'Coupon ID is required.', 'pressark' ) );
				}
				$coupon = new \WC_Coupon( $coupon_id );
				if ( ! $coupon->get_id() ) {
					return array( 'success' => false, 'message' => __( 'Coupon not found.', 'pressark' ) );
				}
				$code = $coupon->get_code();
				$coupon->delete( true );
				return array(
					'success' => true,
					/* translators: %s: deleted coupon code. */
					'message' => sprintf( __( 'Deleted coupon "%s".', 'pressark' ), $code ),
				);

			default:
				/* translators: %s: requested coupon operation name. */
				return array( 'success' => false, 'message' => sprintf( __( 'Unknown coupon operation: %s', 'pressark' ), $operation ) );
		}
	}


	/**
	 * Apply explicit coupon field changes for create/edit flows.
	 */
	private function apply_coupon_changes( \WC_Coupon $coupon, array $params ): void {
		if ( array_key_exists( 'code', $params ) ) {
			$coupon->set_code( sanitize_text_field( (string) $params['code'] ) );
		}
		if ( array_key_exists( 'discount_type', $params ) ) {
			$coupon->set_discount_type( sanitize_text_field( (string) $params['discount_type'] ) );
		}
		if ( array_key_exists( 'amount', $params ) ) {
			$coupon->set_amount( $this->coupon_decimal_value( $params['amount'] ) );
		}
		if ( array_key_exists( 'usage_limit', $params ) ) {
			$coupon->set_usage_limit( $this->coupon_integer_value( $params['usage_limit'] ) );
		}
		if ( ! empty( $params['expiry_date'] ) ) {
			$coupon->set_date_expires( sanitize_text_field( (string) $params['expiry_date'] ) );
		}
		if ( array_key_exists( 'minimum_amount', $params ) ) {
			$coupon->set_minimum_amount( $this->coupon_decimal_value( $params['minimum_amount'] ) );
		}
		if ( array_key_exists( 'maximum_amount', $params ) ) {
			$coupon->set_maximum_amount( $this->coupon_decimal_value( $params['maximum_amount'] ) );
		}
		if ( array_key_exists( 'individual_use', $params ) ) {
			$coupon->set_individual_use( $this->coupon_boolean_value( $params['individual_use'] ) );
		}
		if ( array_key_exists( 'free_shipping', $params ) ) {
			$coupon->set_free_shipping( $this->coupon_boolean_value( $params['free_shipping'] ) );
		}
		if ( array_key_exists( 'exclude_sale_items', $params ) ) {
			$coupon->set_exclude_sale_items( $this->coupon_boolean_value( $params['exclude_sale_items'] ) );
		}
		if ( array_key_exists( 'usage_limit_per_user', $params ) ) {
			$coupon->set_usage_limit_per_user( $this->coupon_integer_value( $params['usage_limit_per_user'] ) );
		}
		if ( array_key_exists( 'product_ids', $params ) ) {
			$coupon->set_product_ids( $this->coupon_normalize_id_list( $params['product_ids'] ) );
		}
		if ( array_key_exists( 'excluded_product_ids', $params ) && method_exists( $coupon, 'set_excluded_product_ids' ) ) {
			$coupon->set_excluded_product_ids( $this->coupon_normalize_id_list( $params['excluded_product_ids'] ) );
		}
		if ( array_key_exists( 'product_categories', $params ) ) {
			$coupon->set_product_categories( $this->coupon_normalize_id_list( $params['product_categories'] ) );
		}
		if ( array_key_exists( 'excluded_product_categories', $params ) && method_exists( $coupon, 'set_excluded_product_categories' ) ) {
			$coupon->set_excluded_product_categories( $this->coupon_normalize_id_list( $params['excluded_product_categories'] ) );
		}
		if ( array_key_exists( 'email_restrictions', $params ) ) {
			$coupon->set_email_restrictions( $this->coupon_normalize_string_list( $params['email_restrictions'] ) );
		}
	}

	/**
	 * Build a stable coupon readback payload.
	 */
	private function coupon_snapshot( \WC_Coupon $coupon ): array {
		$data = array(
			'id'                   => $coupon->get_id(),
			'code'                 => $coupon->get_code(),
			'discount_type'        => $coupon->get_discount_type(),
			'amount'               => $coupon->get_amount(),
			'usage_count'          => $coupon->get_usage_count(),
			'usage_limit'          => $coupon->get_usage_limit() ?: 'unlimited',
			'usage_limit_per_user' => $coupon->get_usage_limit_per_user() ?: 'unlimited',
			'is_expired'           => $coupon->get_date_expires()
				&& $coupon->get_date_expires()->getTimestamp() < time(),
			'date_expires'         => $coupon->get_date_expires()
				? $coupon->get_date_expires()->date( 'Y-m-d' )
				: null,
			'minimum_amount'       => $coupon->get_minimum_amount(),
			'maximum_amount'       => $coupon->get_maximum_amount(),
			'individual_use'       => $coupon->get_individual_use(),
			'free_shipping'        => $coupon->get_free_shipping(),
			'exclude_sale_items'   => $coupon->get_exclude_sale_items(),
			'email_restrictions'   => $coupon->get_email_restrictions(),
			'product_ids'          => $coupon->get_product_ids(),
			'product_categories'   => $coupon->get_product_categories(),
			'used_by_count'        => count( $coupon->get_used_by() ),
		);

		$data['excluded_product_ids'] = method_exists( $coupon, 'get_excluded_product_ids' )
			? array_values( array_map( 'intval', (array) $coupon->get_excluded_product_ids() ) )
			: array();
		$data['excluded_product_categories'] = method_exists( $coupon, 'get_excluded_product_categories' )
			? array_values( array_map( 'intval', (array) $coupon->get_excluded_product_categories() ) )
			: array();

		return $data;
	}

	private function coupon_boolean_value( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return wc_string_to_bool( (string) $value );
	}

	private function coupon_decimal_value( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			return '';
		}

		return (string) wc_format_decimal( wc_clean( $value ) );
	}

	private function coupon_integer_value( $value ): int {
		return absint( $value );
	}

	private function coupon_normalize_id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = null === $value || '' === $value
				? array()
				: preg_split( '/[\s,]+/', (string) $value );
		}

		$ids = array_map( 'absint', (array) $value );
		$ids = array_values( array_filter( $ids ) );

		return array_values( array_unique( $ids ) );
	}

	private function coupon_normalize_string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = null === $value || '' === $value
				? array()
				: preg_split( '/[\r\n,]+/', (string) $value );
		}

		$strings = array_map(
			static function ( $item ): string {
				return sanitize_text_field( (string) $item );
			},
			(array) $value
		);
		$strings = array_values( array_filter( $strings ) );

		return array_values( array_unique( $strings ) );
	}


	/**
	 * Get inventory report: low stock, out of stock products.
	 */
	public function inventory_report( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$threshold = absint( $params['threshold'] ?? 5 );

		// Out of stock products.
		$out_of_stock = wc_get_products( array(
			'stock_status' => 'outofstock',
			'limit'        => 50,
			'return'       => 'objects',
		) );

		$oos_list = array();
		foreach ( $out_of_stock as $p ) {
			$oos_list[] = array(
				'id'    => $p->get_id(),
				'name'  => $p->get_name(),
				'sku'   => $p->get_sku(),
				'stock' => $p->get_stock_quantity(),
			);
		}

		// Low stock products (managing stock and quantity <= threshold).
		$all_managing = wc_get_products( array(
			'stock_status'  => 'instock',
			'manage_stock'  => true,
			'limit'         => 200,
			'return'        => 'objects',
		) );

		$low_list = array();
		foreach ( $all_managing as $p ) {
			$qty = $p->get_stock_quantity();
			if ( null !== $qty && $qty <= $threshold && $qty > 0 ) {
				$low_list[] = array(
					'id'    => $p->get_id(),
					'name'  => $p->get_name(),
					'sku'   => $p->get_sku(),
					'stock' => $qty,
				);
			}
		}

		// ── Variable product variation stock scan ─────────────────────────
		// Variable products manage stock per-variation — these are missed
		// by the simple product query above.
		$variable_ids = wc_get_products( array(
			'type'   => 'variable',
			'limit'  => 200,
			'status' => 'publish',
			'return' => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		foreach ( $variable_ids as $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( ! $parent ) continue;

			foreach ( $parent->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || ! $variation->managing_stock() ) continue;

				$qty         = $variation->get_stock_quantity();
				$attr_label  = implode( ' / ', array_filter( $variation->get_attributes() ) ) ?: 'No attributes';
				$display_name = $parent->get_name() . ' — ' . $attr_label;

				if ( $qty !== null && $qty <= 0 ) {
					$oos_list[] = array(
						'id'         => $child_id,
						'parent_id'  => $parent_id,
						'name'       => $display_name,
						'sku'        => $variation->get_sku(),
						'stock'      => $qty,
						'type'       => 'variation',
					);
				} elseif ( $qty !== null && $qty > 0 && $qty <= $threshold ) {
					$low_list[] = array(
						'id'         => $child_id,
						'parent_id'  => $parent_id,
						'name'       => $display_name,
						'sku'        => $variation->get_sku(),
						'stock'      => $qty,
						'type'       => 'variation',
					);
				}
			}
		}

		// Sort low stock by quantity ascending.
		usort( $low_list, function ( $a, $b ) {
			return ( $a['stock'] ?? 0 ) - ( $b['stock'] ?? 0 );
		} );

		return array(
			'success' => true,
			/* translators: 1: out-of-stock count, 2: low-stock count, 3: low-stock threshold. */
			'message' => sprintf( __( '%1$d out of stock, %2$d low stock (threshold: %3$d).', 'pressark' ), count( $oos_list ), count( $low_list ), $threshold ),
			'data'    => array(
				'out_of_stock'   => $oos_list,
				'low_stock'      => $low_list,
				'threshold'      => $threshold,
			),
		);
	}


	/**
	 * Get sales summary for a period.
	 */
	public function sales_summary( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		global $wpdb;

		$period = sanitize_text_field( $params['period'] ?? 'month' );

		// Determine date range.
		$now = time();
		switch ( $period ) {
			case 'today':
				$date_from = gmdate( 'Y-m-d', $now );
				$date_to   = gmdate( 'Y-m-d', $now );
				break;
			case 'week':
				$date_from = gmdate( 'Y-m-d', strtotime( '-7 days', $now ) );
				$date_to   = gmdate( 'Y-m-d', $now );
				break;
			case 'year':
				$date_from = gmdate( 'Y-01-01', $now );
				$date_to   = gmdate( 'Y-m-d', $now );
				break;
			case 'custom':
				$date_from = sanitize_text_field( $params['date_from'] ?? gmdate( 'Y-m-01', $now ) );
				$date_to   = sanitize_text_field( $params['date_to'] ?? gmdate( 'Y-m-d', $now ) );
				break;
			default: // month.
				$date_from = gmdate( 'Y-m-01', $now );
				$date_to   = gmdate( 'Y-m-d', $now );
				break;
		}

		// Single query — all aggregates at once via WC Analytics table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate analytics on WooCommerce lookup tables for an on-demand admin report.
		$stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) as total_orders,
				SUM( total_sales ) as total_revenue,
				SUM( tax_total ) as total_tax,
				SUM( shipping_total ) as total_shipping,
				SUM( net_total ) as net_revenue,
				AVG( total_sales ) as avg_order_value,
				SUM( num_items_sold ) as total_items
			 FROM {$wpdb->prefix}wc_order_stats
			 WHERE status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold' )
			   AND date_created >= %s
			   AND date_created <= %s",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		// Status breakdown.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate analytics on WooCommerce lookup tables for an on-demand admin report.
		$by_status = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) as count, SUM( total_sales ) as revenue
			 FROM {$wpdb->prefix}wc_order_stats
			 WHERE date_created >= %s AND date_created <= %s
			 GROUP BY status
			 ORDER BY revenue DESC",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		return array(
			'success'          => true,
			'period'           => array( 'from' => $date_from, 'to' => $date_to ),
			'total_orders'     => (int) $stats->total_orders,
			'total_revenue'    => wc_price( $stats->total_revenue ),
			'net_revenue'      => wc_price( $stats->net_revenue ),
			'total_tax'        => wc_price( $stats->total_tax ),
			'total_shipping'   => wc_price( $stats->total_shipping ),
			'avg_order_value'  => wc_price( $stats->avg_order_value ),
			'total_items_sold' => (int) $stats->total_items,
			'by_status'        => $by_status,
		);
	}


	// ── Prompt 7 Part E: WooCommerce Customers ───────────────────────

	/**
	 * List WooCommerce customers with order history.
	 *
	 * @param array $params Filtering options: limit, offset.
	 * @return array Result with customer list.
	 */
	public function list_customers( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to view customer data.', 'pressark' ) );
		}

		$limit   = min( absint( $params['limit'] ?? 20 ), 100 );
		$offset  = absint( $params['offset'] ?? 0 );
		$search  = sanitize_text_field( $params['search'] ?? '' );
		$orderby = sanitize_key( $params['orderby'] ?? 'total_spent' );
		if ( ! in_array( $orderby, array( 'total_spent', 'order_count', 'date_registered' ), true ) ) {
			$orderby = 'total_spent';
		}

		// wc_customer_lookup includes both registered and guest customers
		// and doesn't require N+1 WC_Customer instantiation.
		$customers = $this->query_customer_lookup_rows( $orderby, $search, $limit, $offset );
		$total     = $this->count_customer_lookup_rows( $search );

		$list = array();
		foreach ( $customers as $c ) {
			$list[] = array(
				'customer_id' => (int) $c->customer_id,
				'user_id'     => (int) $c->user_id,
				'is_guest'    => (int) $c->user_id === 0,
				'name'        => trim( $c->first_name . ' ' . $c->last_name ),
				'email'       => $c->email,
				'city'        => $c->city,
				'country'     => $c->country,
				'order_count' => (int) $c->order_count,
				'total_spent' => wc_price( (float) $c->total_spent ),
				'last_active' => $c->date_last_active
					? gmdate( 'Y-m-d', strtotime( $c->date_last_active ) )
					: null,
				'registered'  => $c->date_registered
					? gmdate( 'Y-m-d', strtotime( $c->date_registered ) )
					: null,
			);
		}

		return array(
			'success'     => true,
			'customers'   => $list,
			'_pagination' => array(
				'total'    => $total,
				'offset'   => $offset,
				'limit'    => $limit,
				'has_more' => ( $offset + $limit ) < $total,
			),
		);
	}

	/**
	 * Query WooCommerce customer lookup rows with fixed sort branches.
	 *
	 * @return array<int, object>
	 */
	private function query_customer_lookup_rows( string $orderby, string $search, int $limit, int $offset ): array {
		global $wpdb;

		$rows = array();
		$like = '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only customer analytics on WooCommerce lookup tables for an on-demand admin report.
		if ( '' !== $search ) {
			switch ( $orderby ) {
				case 'order_count':
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 WHERE (cl.first_name LIKE %s OR cl.last_name LIKE %s OR cl.email LIKE %s)
						 GROUP BY cl.customer_id
						 ORDER BY order_count DESC
						 LIMIT %d OFFSET %d",
						$like,
						$like,
						$like,
						$limit,
						$offset
					) );
					break;
				case 'date_registered':
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 WHERE (cl.first_name LIKE %s OR cl.last_name LIKE %s OR cl.email LIKE %s)
						 GROUP BY cl.customer_id
						 ORDER BY cl.date_registered DESC
						 LIMIT %d OFFSET %d",
						$like,
						$like,
						$like,
						$limit,
						$offset
					) );
					break;
				default:
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 WHERE (cl.first_name LIKE %s OR cl.last_name LIKE %s OR cl.email LIKE %s)
						 GROUP BY cl.customer_id
						 ORDER BY total_spent DESC
						 LIMIT %d OFFSET %d",
						$like,
						$like,
						$like,
						$limit,
						$offset
					) );
					break;
			}
		} else {
			switch ( $orderby ) {
				case 'order_count':
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 GROUP BY cl.customer_id
						 ORDER BY order_count DESC
						 LIMIT %d OFFSET %d",
						$limit,
						$offset
					) );
					break;
				case 'date_registered':
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 GROUP BY cl.customer_id
						 ORDER BY cl.date_registered DESC
						 LIMIT %d OFFSET %d",
						$limit,
						$offset
					) );
					break;
				default:
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT
							cl.customer_id, cl.user_id, cl.email,
							cl.first_name, cl.last_name,
							cl.city, cl.state, cl.country,
							cl.date_registered, cl.date_last_active,
							COUNT( os.order_id ) as order_count,
							SUM( os.total_sales ) as total_spent
						 FROM {$wpdb->prefix}wc_customer_lookup cl
						 LEFT JOIN {$wpdb->prefix}wc_order_stats os
							ON cl.customer_id = os.customer_id
							AND os.status IN ( 'wc-completed', 'wc-processing' )
						 GROUP BY cl.customer_id
						 ORDER BY total_spent DESC
						 LIMIT %d OFFSET %d",
						$limit,
						$offset
					) );
					break;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count customer lookup rows for pagination.
	 */
	private function count_customer_lookup_rows( string $search ): int {
		global $wpdb;

		if ( '' === $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only customer count on a WooCommerce core lookup table for admin pagination.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_customer_lookup" );
		}

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only customer count on a WooCommerce core lookup table for admin pagination.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_customer_lookup cl WHERE cl.first_name LIKE %s OR cl.last_name LIKE %s OR cl.email LIKE %s",
			$like,
			$like,
			$like
		) );
	}


	/**
	 * Get single customer profile with recent orders.
	 *
	 * @param array $params Customer ID.
	 * @return array Result with customer profile data.
	 */
	public function get_customer( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to view customer data.', 'pressark' ) );
		}

		$customer_id = intval( $params['customer_id'] ?? 0 );
		$user        = get_userdata( $customer_id );
		if ( ! $user ) {
			return array( 'success' => false, 'message' => __( 'Customer not found.', 'pressark' ) );
		}

		$customer = new \WC_Customer( $customer_id );

		$orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'limit'       => 5,
			'orderby'     => 'date',
			'order'       => 'DESC',
		) );

		$recent_orders = array();
		foreach ( $orders as $order ) {
			$recent_orders[] = array(
				'id'     => $order->get_id(),
				'number' => $order->get_order_number(),
				'total'  => $order->get_total(),
				'status' => $order->get_status(),
				'date'   => $order->get_date_created()->format( 'Y-m-d' ),
				'items'  => $order->get_item_count(),
			);
		}

		$order_count = $customer->get_order_count();
		$total_spent = (float) $customer->get_total_spent();
		$avg_order   = $order_count > 0 ? wc_price( $total_spent / $order_count ) : wc_price( 0 );

		return array(
			'success' => true,
			/* translators: %s: customer full name. */
			'message' => sprintf( __( 'Customer profile for "%s"', 'pressark' ), trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ) ),
			'data'    => array(
				'id'               => $customer_id,
				'name'             => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ),
				'email'            => $customer->get_email(),
				'phone'            => $customer->get_billing_phone(),
				'total_orders'     => $order_count,
				'total_spent'      => wc_price( $total_spent ),
				'average_order'    => $avg_order,
				'registered'       => $user->user_registered,
				'billing_address'  => array(
					'line1'    => $customer->get_billing_address_1(),
					'line2'    => $customer->get_billing_address_2(),
					'city'     => $customer->get_billing_city(),
					'state'    => $customer->get_billing_state(),
					'postcode' => $customer->get_billing_postcode(),
					'country'  => $customer->get_billing_country(),
				),
				'shipping_address' => array(
					'line1'    => $customer->get_shipping_address_1(),
					'city'     => $customer->get_shipping_city(),
					'state'    => $customer->get_shipping_state(),
					'postcode' => $customer->get_shipping_postcode(),
					'country'  => $customer->get_shipping_country(),
				),
				'recent_orders'    => $recent_orders,
			),
		);
	}


	/**
	 * Send an email to a WooCommerce customer.
	 *
	 * @param array $params Customer ID, subject, and body.
	 * @return array Result with send status.
	 */
	public function email_customer( array $params ): array {
		// v3.7.1: Sending store emails requires manage_woocommerce.
		// Editors who can chat should NOT be able to email customers.
		$err = $this->require_wc_email_cap();
		if ( $err ) return $err;

		$customer_id = intval( $params['customer_id'] ?? 0 );
		$customer    = new \WC_Customer( $customer_id );
		if ( ! $customer->get_id() ) {
			return array( 'success' => false, 'message' => __( 'Customer not found.', 'pressark' ) );
		}

		$email = $customer->get_email();
		if ( empty( $email ) ) {
			return array( 'success' => false, 'message' => __( 'Customer has no email address.', 'pressark' ) );
		}

		$subject = sanitize_text_field( $params['subject'] ?? '' );
		$body    = wp_kses_post( $params['body'] ?? '' );

		if ( empty( $subject ) || empty( $body ) ) {
			return array( 'success' => false, 'message' => __( 'Subject and body are required.', 'pressark' ) );
		}

		// v3.7.1: Business idempotency — skip if already sent on a previous retry.
		$receipt_key = 'email_' . $customer_id . '_' . md5( $subject . $body );
		if ( $this->has_operation_receipt( $receipt_key ) ) {
			return array(
				'success' => true,
				/* translators: %d: customer ID. */
				'message' => sprintf( __( 'Email to customer #%d was already sent on a previous attempt (skipped to prevent duplicate).', 'pressark' ), $customer_id ),
				'skipped_duplicate' => true,
			);
		}

		$site_name   = get_bloginfo( 'name' );
		$admin_email = get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			"From: {$site_name} <{$admin_email}>",
		);

		$html_body = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>{$body}</body></html>";
		$mail_error = null;
		$mail_hook  = static function ( $wp_error ) use ( &$mail_error ) {
			if ( $wp_error instanceof \WP_Error ) {
				$mail_error = $wp_error;
			}
		};

		add_action( 'wp_mail_failed', $mail_hook, 10, 1 );
		try {
			$sent = wp_mail( $email, $subject, $html_body, $headers );
		} finally {
			remove_action( 'wp_mail_failed', $mail_hook, 10 );
		}

		$customer_name = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );

		$this->log_email( array(
			'to'            => $email,
			'customer_name' => $customer_name,
			'subject'       => $subject,
			'status'        => $sent ? 'sent' : 'failed',
			'date'          => current_time( 'mysql' ),
		) );

		if ( $sent ) {
			// v3.7.1: Record receipt so retries skip this email.
			$this->record_operation_receipt( $receipt_key, "Email to {$email}: {$subject}" );

			return array(
				'success' => true,
				/* translators: 1: customer name, 2: email address, 3: email subject. */
				'message' => sprintf( __( 'Email sent to %1$s (%2$s): "%3$s"', 'pressark' ), $customer_name, $email, $subject ),
			);
		}

		return $this->build_email_delivery_failure( $mail_error );
	}


	// ── Prompt 7 Part F: WooCommerce Shipping & Tax ──────────────────

	/**
	 * Get configured WooCommerce shipping zones and methods.
	 *
	 * @param array $params Optional parameters.
	 * @return array Result with shipping zone data.
	 */
	public function get_shipping_zones( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$zones_raw = \WC_Shipping_Zones::get_zones();
		$zones     = array();

		$default_zone = new \WC_Shipping_Zone( 0 );
		$zones[]      = $this->format_shipping_zone( $default_zone, true );

		foreach ( $zones_raw as $zone_data ) {
			$zone    = new \WC_Shipping_Zone( $zone_data['id'] );
			$zones[] = $this->format_shipping_zone( $zone, false );
		}

		return array(
			'success' => true,
			/* translators: %d: number of configured shipping zones. */
			'message' => sprintf( __( '%d shipping zones configured.', 'pressark' ), count( $zones ) ),
			'data'    => $zones,
		);
	}


	/**
	 * Get WooCommerce tax configuration and rates.
	 *
	 * @param array $params Optional parameters.
	 * @return array Result with tax settings and rates.
	 */
	public function get_tax_settings( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$tax_enabled = 'yes' === get_option( 'woocommerce_calc_taxes' );

		$data = array(
			'enabled'              => $tax_enabled,
			'prices_include_tax'   => get_option( 'woocommerce_prices_include_tax' ),
			'tax_based_on'         => get_option( 'woocommerce_tax_based_on' ),
			'shipping_tax_class'   => get_option( 'woocommerce_shipping_tax_class' ),
			'tax_round_at_subtotal' => get_option( 'woocommerce_tax_round_at_subtotal' ),
			'tax_display_shop'     => get_option( 'woocommerce_tax_display_shop' ),
			'tax_display_cart'     => get_option( 'woocommerce_tax_display_cart' ),
			'tax_total_display'    => get_option( 'woocommerce_tax_total_display' ),
		);

		if ( $tax_enabled ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only analytics on a WooCommerce core table for an on-demand admin report.
			$rates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order LIMIT %d", 50 ) );
			$data['rates'] = array_map( function ( $rate ) {
				return array(
					'id'       => $rate->tax_rate_id,
					'country'  => $rate->tax_rate_country ?: 'All',
					'state'    => $rate->tax_rate_state ?: 'All',
					'rate'     => $rate->tax_rate . '%',
					'name'     => $rate->tax_rate_name,
					'priority' => $rate->tax_rate_priority,
					'compound' => $rate->tax_rate_compound ? 'Yes' : 'No',
					'shipping' => $rate->tax_rate_shipping ? 'Yes' : 'No',
				);
			}, $rates );

			$data['rate_classes']        = array_merge(
				array( '' => 'Standard Rate' ),
				\WC_Tax::get_tax_rate_classes()
			);
			$data['tax_enabled']         = true;
			$data['prices_include_tax']  = wc_prices_include_tax();
			$data['display_tax_suffix']  = get_option( 'woocommerce_tax_display_suffix' );
		}

		return array(
			'success' => true,
			'message' => $tax_enabled
				? sprintf(
					/* translators: %d: number of configured tax rates. */
					__( 'Tax is enabled with %d rate(s).', 'pressark' ),
					count( $data['rates'] ?? array() )
				)
				: __( 'Tax calculation is disabled.', 'pressark' ),
			'data'    => $data,
		);
	}


	/**
	 * Get WooCommerce payment gateways with availability and test mode flags.
	 *
	 * @param array $params Optional enabled_only filter.
	 * @return array Result with gateway list and flags.
	 */
	public function get_payment_gateways( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$all_gateways = WC()->payment_gateways()->payment_gateways();
		$enabled_only = ! empty( $params['enabled_only'] );
		$results      = array();

		foreach ( $all_gateways as $gw ) {
			if ( $enabled_only && 'yes' !== $gw->enabled ) continue;

			// Test mode detection — varies per gateway
			// Stripe uses 'testmode', PayPal uses 'sandbox', generic uses 'testmode'
			$test_mode = wc_string_to_bool(
				$gw->get_option( 'testmode', $gw->get_option( 'sandbox', 'no' ) )
			);

			$results[] = array(
				'id'               => $gw->id,
				'title'            => $gw->get_title(),
				'method_title'     => $gw->get_method_title(),
				'description'      => $gw->get_description(),
				'enabled'          => 'yes' === $gw->enabled,
				'available'        => $gw->is_available(),
				'supports_refunds' => $gw->supports( 'refunds' ),
				'supports'         => $gw->supports,
				'test_mode'        => $test_mode,
				'order_button_text'=> $gw->order_button_text,
			);
		}

		// Surface critical issues
		$flags = array();
		foreach ( $results as $gw ) {
			if ( $gw['enabled'] && ! $gw['available'] ) {
				/* translators: %s: payment gateway title. */
				$flags[] = sprintf( __( 'Gateway \'%s\' is enabled but not available — check currency, country, or SSL requirements.', 'pressark' ), $gw['title'] );
			}
			if ( $gw['enabled'] && $gw['test_mode'] ) {
				/* translators: %s: payment gateway title. */
				$flags[] = sprintf( __( 'Gateway \'%s\' is in TEST MODE — customers cannot make real payments.', 'pressark' ), $gw['title'] );
			}
		}

		return array(
			'success'        => true,
			'count'          => count( $results ),
			'gateways'       => $results,
			'flags'          => $flags,
			'refund_capable' => array_column(
				array_filter( $results, fn( $g ) => $g['enabled'] && $g['supports_refunds'] ),
				'title'
			),
		);
	}


	/**
	 * Get WooCommerce settings by section (general, products, accounts).
	 *
	 * @param array $params Section name.
	 * @return array Result with settings data.
	 */
	public function get_wc_settings( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$section = $params['section'] ?? 'general';

		switch ( $section ) {
			case 'general':
				$data = array(
					'store_address'      => get_option( 'woocommerce_store_address' ),
					'store_address_2'    => get_option( 'woocommerce_store_address_2' ),
					'store_city'         => get_option( 'woocommerce_store_city' ),
					'store_postcode'     => get_option( 'woocommerce_store_postcode' ),
					'store_country'      => WC()->countries->get_base_country(),
					'store_state'        => WC()->countries->get_base_state(),
					'currency'           => get_woocommerce_currency(),
					'currency_symbol'    => get_woocommerce_currency_symbol(),
					'currency_position'  => get_option( 'woocommerce_currency_pos' ),
					'thousand_separator' => get_option( 'woocommerce_price_thousand_sep' ),
					'decimal_separator'  => get_option( 'woocommerce_price_decimal_sep' ),
					'decimals'           => get_option( 'woocommerce_price_num_decimals' ),
					'selling_locations'  => get_option( 'woocommerce_allowed_countries' ),
					'enable_coupons'     => get_option( 'woocommerce_enable_coupons' ),
					'calc_taxes'         => get_option( 'woocommerce_calc_taxes' ),
				);
				break;

			case 'products':
				$data = array(
					'weight_unit'             => get_option( 'woocommerce_weight_unit' ),
					'dimension_unit'          => get_option( 'woocommerce_dimension_unit' ),
					'enable_reviews'          => get_option( 'woocommerce_enable_reviews' ),
					'review_rating_required'  => get_option( 'woocommerce_review_rating_required' ),
					'manage_stock'            => get_option( 'woocommerce_manage_stock' ),
					'hold_stock_minutes'      => get_option( 'woocommerce_hold_stock_minutes' ),
					'notify_low_stock'        => get_option( 'woocommerce_notify_low_stock_amount' ),
					'notify_no_stock'         => get_option( 'woocommerce_notify_no_stock_amount' ),
					'out_of_stock_visibility' => get_option( 'woocommerce_hide_out_of_stock_items' ),
				);
				break;

			case 'accounts':
				$data = array(
					'enable_guest_checkout'           => get_option( 'woocommerce_enable_guest_checkout' ),
					'enable_checkout_login'           => get_option( 'woocommerce_enable_checkout_login_reminder' ),
					'enable_signup_checkout'          => get_option( 'woocommerce_enable_signup_and_login_from_checkout' ),
					'enable_signup_myaccount'         => get_option( 'woocommerce_enable_signup_and_login_from_my_account' ),
					'registration_generate_username'  => get_option( 'woocommerce_registration_generate_username' ),
					'registration_generate_password'  => get_option( 'woocommerce_registration_generate_password' ),
				);
				break;

			default:
				$data = array( 'message' => __( 'Available sections: general, products, accounts', 'pressark' ) );
		}

		return array(
			'success' => true,
			/* translators: %s: WooCommerce settings section slug. */
			'message' => sprintf( __( 'WooCommerce %s settings retrieved.', 'pressark' ), $section ),
			'data'    => $data,
		);
	}


	/**
	 * Get WooCommerce email notifications and their status.
	 *
	 * @param array $params Optional parameters.
	 * @return array Result with email notification list.
	 */
	public function get_wc_emails( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$mailer  = WC()->mailer();
		$emails  = $mailer->get_emails();
		$results = array();

		foreach ( $emails as $email ) {
			$results[] = array(
				'id'          => $email->id,
				'title'       => $email->get_title(),
				'description' => $email->get_description(),
				'enabled'     => $email->is_enabled(),
				'recipient'   => $email->get_recipient() ?: 'Customer',
				'type'        => ( method_exists( $email, 'is_customer_email' ) ? $email->is_customer_email() : ( ( new ReflectionProperty( $email, 'customer_email' ) )->isPublic() ? $email->customer_email : false ) ) ? 'Customer' : 'Admin',
			);
		}

		$enabled_count = count( array_filter( $results, function ( $e ) {
			return $e['enabled'];
		} ) );

		return array(
			'success' => true,
			/* translators: 1: total email notification count, 2: enabled email notification count. */
			'message' => sprintf( __( '%1$d email notifications (%2$d enabled).', 'pressark' ), count( $results ), $enabled_count ),
			'data'    => $results,
		);
	}


	/**
	 * Get WooCommerce system status (environment, database, theme, plugins).
	 *
	 * @param array $params Optional parameters.
	 * @return array Result with system status data and flags.
	 */
	public function get_wc_status( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		// Use WC's own system_status REST endpoint internally
		// This is the same data shown at WooCommerce → Status
		$request  = new \WP_REST_Request( 'GET', '/wc/v3/system_status' );
		$response = rest_do_request( $request );

		if ( $response->is_error() || $response->get_status() >= 400 ) {
			// Fallback to basic check if REST fails
			return array(
				'wc_version'      => WC()->version,
				'db_version'      => get_option( 'woocommerce_db_version' ),
				'db_update_needed' => version_compare(
					get_option( 'woocommerce_db_version' ), WC()->version, '<'
				),
				'error' => __( 'Could not load full system status.', 'pressark' ),
			);
		}

		$raw = $response->get_data();

		// Flatten into AI-readable structure
		$env    = $raw['environment']    ?? array();
		$db     = $raw['database']       ?? array();
		$active = $raw['active_plugins'] ?? array();
		$theme  = $raw['theme']          ?? array();
		$pages  = $raw['pages']          ?? array();
		$post_t = $raw['post_type_counts'] ?? array();

		// Template overrides with version mismatch detection
		$outdated_templates = array();
		foreach ( $theme['template_overrides'] ?? array() as $t ) {
			if ( ! empty( $t['outdated'] ) ) {
				$outdated_templates[] = array(
					'file'      => $t['file'],
					'theme_ver' => $t['version'],
					'core_ver'  => $t['core_version'],
				);
			}
		}

		// DB update needed check
		$db_update_needed = version_compare(
			get_option( 'woocommerce_db_version' ),
			WC()->version,
			'<'
		);

		// Surface critical flags
		$flags = array();
		if ( $db_update_needed ) {
			$flags[] = __( 'WooCommerce database update required — go to WooCommerce → Status → Tools → Update Database.', 'pressark' );
		}
		if ( ! empty( $outdated_templates ) ) {
			/* translators: %d: number of outdated WooCommerce template overrides. */
			$flags[] = sprintf( __( '%d outdated WC template override(s) — may cause frontend display bugs after WC update.', 'pressark' ), count( $outdated_templates ) );
		}
		if ( ! ( $env['https'] ?? true ) ) {
			$flags[] = __( 'Store is not using HTTPS — payment security risk.', 'pressark' );
		}
		if ( ( $env['wp_memory_limit'] ?? '64M' ) === '64M' ) {
			$flags[] = __( 'WordPress memory limit is 64MB — may cause issues with large stores. Recommend 256MB+.', 'pressark' );
		}

		// Summarize active plugins
		$plugin_list = array_map( fn( $p ) => array(
			'name'    => $p['name'],
			'version' => $p['version'],
			'author'  => $p['author_name'],
			'update'  => ! empty( $p['url'] ),
		), array_slice( $active, 0, 30 ) );

		return array(
			'success'     => true,
			'environment' => array(
				'wc_version'       => $env['version'] ?? WC()->version,
				'wp_version'       => $env['wp_version'] ?? '',
				'php_version'      => $env['php_version'] ?? '',
				'mysql_version'    => $env['mysql_version'] ?? '',
				'server_info'      => $env['server_info'] ?? '',
				'memory_limit'     => $env['wp_memory_limit'] ?? '',
				'max_upload_size'  => $env['max_upload_size'] ?? '',
				'is_https'         => $env['https'] ?? false,
				'is_multisite'     => $env['is_multisite'] ?? false,
				'environment_type' => wp_get_environment_type(),
			),
			'database'    => array(
				'wc_db_version'    => get_option( 'woocommerce_db_version' ),
				'db_update_needed' => $db_update_needed,
				'wc_tables'        => $db['wc_database_tables'] ?? array(),
			),
			'theme'       => array(
				'name'               => $theme['name'] ?? '',
				'version'            => $theme['version'] ?? '',
				'author'             => $theme['author_name'] ?? '',
				'is_child_theme'     => $theme['is_child_theme'] ?? false,
				'template_overrides' => count( $theme['template_overrides'] ?? array() ),
				'outdated_templates' => $outdated_templates,
			),
			'pages'       => array_map( fn( $p ) => array(
				'name'   => $p['page_name'] ?? '',
				'exists' => ! empty( $p['page_id'] ),
				'url'    => $p['page_set'] ?? '',
			), $pages ),
			'active_plugins'    => $plugin_list,
			'post_type_counts'  => $post_t,
			'flags'             => $flags,
			'hpos_enabled'      => 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' ),
			'powered_by'        => 'WooCommerce /wc/v3/system_status',
		);
	}


	// ── Prompt 7 Part G: WooCommerce Product Reviews ─────────────────

	/**
	 * List WooCommerce product reviews with optional filtering.
	 *
	 * @param array $params Filtering options: limit, status, product_id, rating.
	 * @return array Result with review list and optional product summary.
	 */
	public function list_reviews( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$args = array(
			'type'    => 'review',
			'number'  => min( intval( $params['limit'] ?? 20 ), 100 ),
			'orderby' => 'comment_date',
			'order'   => 'DESC',
		);

		$status     = $params['status'] ?? 'all';
		$status_map = array( 'approved' => 'approve', 'pending' => 'hold', 'spam' => 'spam', 'trash' => 'trash', 'all' => 'all' );
		$args['status'] = $status_map[ $status ] ?? 'all';

		if ( ! empty( $params['product_id'] ) ) {
			$args['post_id'] = intval( $params['product_id'] );
		}

		if ( ! empty( $params['rating'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce stores review ratings in comment meta, so this bounded admin filter intentionally uses meta_query.
			$args['meta_query'] = array(
				array( 'key' => 'rating', 'value' => intval( $params['rating'] ), 'compare' => '=' ),
			);
		}

		$reviews = get_comments( $args );
		$results = array();

		foreach ( $reviews as $review ) {
			$rating    = get_comment_meta( $review->comment_ID, 'rating', true );
			$results[] = array(
				'id'                => (int) $review->comment_ID,
				'product'           => get_the_title( $review->comment_post_ID ),
				'product_id'        => (int) $review->comment_post_ID,
				'author'            => $review->comment_author,
				'email'             => $review->comment_author_email,
				'rating'            => $rating ? intval( $rating ) : null,
				'content'           => wp_strip_all_tags( $review->comment_content ),
				'status'            => wp_get_comment_status( $review ),
				'date'              => $review->comment_date,
				'verified_purchase' => (bool) get_comment_meta( $review->comment_ID, 'verified', true ),
			);
		}

		$data = array(
			'success' => true,
			/* translators: %d: number of product reviews found. */
			'message' => sprintf( __( '%d product reviews found.', 'pressark' ), count( $results ) ),
			'data'    => $results,
		);

		// When product_id is specified, add rating breakdown summary
		if ( ! empty( $params['product_id'] ) ) {
			$product = wc_get_product( (int) $params['product_id'] );
			if ( $product ) {
				$rating_counts = method_exists( $product, 'get_rating_counts' ) ? $product->get_rating_counts() : ( function_exists( 'wc_get_rating_counts_for_product' ) ? wc_get_rating_counts_for_product( $product->get_id() ) : array() );
				$total_reviews = array_sum( $rating_counts );

				$data['product_summary'] = array(
					'name'             => $product->get_name(),
					'average_rating'   => $product->get_average_rating(),
					'review_count'     => $product->get_review_count(),
					'rating_breakdown' => array_map(
						fn( $stars, $count ) => array(
							'stars'      => $stars,
							'count'      => $count,
							'percentage' => $total_reviews > 0
								? round( $count / $total_reviews * 100 ) . '%'
								: '0%',
						),
						array_keys( $rating_counts ),
						array_values( $rating_counts )
					),
				);
			}
		}

		return $data;
	}


	/**
	 * Moderate a WooCommerce product review (approve, unapprove, spam, trash, reply).
	 *
	 * @param array $params Review ID and moderation action.
	 * @return array Result with moderation outcome.
	 */
	public function moderate_review( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$review_id = intval( $params['review_id'] ?? 0 );
		$review    = get_comment( $review_id );
		if ( ! $review ) {
			return array( 'success' => false, 'message' => __( 'Review not found.', 'pressark' ) );
		}

		$mod_action = $params['action'] ?? '';

		switch ( $mod_action ) {
			case 'approve':
				wp_set_comment_status( $review_id, 'approve' );
				/* translators: %s: review author name. */
				return array( 'success' => true, 'message' => sprintf( __( 'Review by "%s" approved.', 'pressark' ), $review->comment_author ) );
			case 'unapprove':
				wp_set_comment_status( $review_id, 'hold' );
				/* translators: %s: review author name. */
				return array( 'success' => true, 'message' => sprintf( __( 'Review by "%s" held for moderation.', 'pressark' ), $review->comment_author ) );
			case 'spam':
				wp_spam_comment( $review_id );
				return array( 'success' => true, 'message' => __( 'Review marked as spam.', 'pressark' ) );
			case 'trash':
				wp_trash_comment( $review_id );
				return array( 'success' => true, 'message' => __( 'Review trashed.', 'pressark' ) );
			case 'reply':
				if ( empty( $params['reply_content'] ) ) {
					return array( 'success' => false, 'message' => __( 'Reply content is required.', 'pressark' ) );
				}
				$current_user = wp_get_current_user();
				wp_insert_comment( array(
					'comment_post_ID'  => $review->comment_post_ID,
					'comment_parent'   => $review_id,
					'comment_content'  => sanitize_text_field( $params['reply_content'] ),
					'comment_author'   => $current_user->display_name,
					'comment_author_email' => $current_user->user_email,
					'comment_approved' => 1,
					'user_id'          => $current_user->ID,
					'comment_type'     => 'review',
				) );
				/* translators: %s: review author name. */
				return array( 'success' => true, 'message' => sprintf( __( 'Reply posted to review by "%s".', 'pressark' ), $review->comment_author ) );
			default:
				return array( 'success' => false, 'message' => __( 'Invalid action. Use: approve, unapprove, spam, trash, or reply.', 'pressark' ) );
		}
	}


	// ── Prompt 12 Part E: WooCommerce Variations & Orders ─────────────

	/**
	 * Reply to a single WooCommerce review.
	 *
	 * Thin alias for moderate_review(action=reply) so the model can choose
	 * a review-specific reply tool without changing the existing review flow.
	 *
	 * @param array $params Review ID and reply content.
	 * @return array Result with reply outcome.
	 */
	public function reply_review( array $params ): array {
		if ( empty( $params['reply_content'] ) && ! empty( $params['content'] ) ) {
			$params['reply_content'] = $params['content'];
		}

		$params['action'] = 'reply';

		return $this->moderate_review( $params );
	}

	/**
	 * Reply to multiple WooCommerce reviews in one confirmable action.
	 *
	 * @param array $params Array of {review_id, reply_content} objects.
	 * @return array Result with counts and optional per-item error details.
	 */
	public function bulk_reply_reviews( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$reviews = $params['reviews'] ?? array();
		if ( ! is_array( $reviews ) || empty( $reviews ) ) {
			return array( 'success' => false, 'message' => __( 'No reviews to reply to.', 'pressark' ) );
		}

		$max_batch = 50;
		if ( count( $reviews ) > $max_batch ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: max batch size 2: actual count sent */
					__( 'Bulk replies limited to %1$d reviews per request. You sent %2$d. Break into smaller batches.', 'pressark' ),
					$max_batch,
					count( $reviews )
				),
			);
		}

		$replied = 0;
		$errors  = 0;
		$details = array();

		foreach ( $reviews as $i => $review_reply ) {
			if ( ! is_array( $review_reply ) ) {
				$errors++;
				$details[] = sprintf(
					/* translators: %d: batch position */
					__( 'Item #%d: Invalid review reply payload.', 'pressark' ),
					$i + 1
				);
				continue;
			}

			$review_id = intval( $review_reply['review_id'] ?? 0 );

			try {
				$result = $this->reply_review( array(
					'review_id'     => $review_id,
					'reply_content' => $review_reply['reply_content'] ?? ( $review_reply['content'] ?? '' ),
				) );

				if ( $result['success'] ?? false ) {
					$replied++;
				} else {
					$errors++;
					$details[] = sprintf(
						/* translators: 1: review ID or item number 2: error message */
						__( '#%1$d: %2$s', 'pressark' ),
						$review_id ? $review_id : ( $i + 1 ),
						$result['message'] ?? __( 'Unknown error', 'pressark' )
					);
				}
			} catch ( \Throwable $e ) {
				$errors++;
				$details[] = sprintf(
					/* translators: 1: review ID or item number 2: exception message */
					__( '#%1$d: Exception: %2$s', 'pressark' ),
					$review_id ? $review_id : ( $i + 1 ),
					$e->getMessage()
				);
			}
		}

		$response = array(
			'success' => 0 === $errors,
			'replied' => $replied,
			'errors'  => $errors,
			'message' => sprintf(
				/* translators: 1: replied count 2: error count */
				__( 'Bulk review replies: %1$d review(s) replied to, %2$d error(s).', 'pressark' ),
				$replied,
				$errors
			),
		);

		if ( ! empty( $details ) ) {
			$response['error_details'] = $details;
		}

		return $response;
	}

	/**
	 * List variations of a variable product.
	 *
	 * @param array $params Product ID.
	 * @return array Result with variation list.
	 */
	public function list_variations( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$product_id = intval( $params['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array( 'success' => false, 'message' => __( 'Product not found or not a variable product.', 'pressark' ) );
		}
		$variations = array();
		foreach ( $product->get_children() as $var_id ) {
			$var = wc_get_product( $var_id );
			if ( ! $var ) {
				continue;
			}
			$variations[] = array(
				'id'             => $var_id,
				'attributes'     => $var->get_attributes(),
				'regular_price'  => $var->get_regular_price(),
				'sale_price'     => $var->get_sale_price(),
				'price'          => $var->get_price(),
				'stock_quantity' => $var->get_stock_quantity(),
				'stock_status'   => $var->get_stock_status(),
				'status'         => $var->get_status(),
				'sku'            => $var->get_sku(),
			);
		}
		return array(
			'success' => true,
			/* translators: 1: number of variations, 2: parent product name. */
			'message' => sprintf( __( '%1$d variations for "%2$s".', 'pressark' ), count( $variations ), $product->get_name() ),
			'data'    => $variations,
		);
	}


	/**
	 * Edit a single product variation.
	 *
	 * @param array $params Variation ID and fields to update.
	 * @return array Result with updated fields.
	 */
	public function edit_variation( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$ambiguous_price = $this->ambiguous_wc_price_write_response( $params );
		if ( $ambiguous_price ) {
			return $ambiguous_price;
		}

		$params = $this->normalize_variation_params( $params );
		$var_id           = intval( $params['variation_id'] ?? 0 );
		$variation        = wc_get_product( $var_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return array( 'success' => false, 'message' => __( 'Variation not found.', 'pressark' ) );
		}
		$changes          = array();
		$had_sale_schedule = (bool) $variation->get_date_on_sale_from() || (bool) $variation->get_date_on_sale_to();
		$clear_sale        = ! empty( $params['clear_sale'] )
			|| ( array_key_exists( 'sale_price', $params ) && '' === (string) $params['sale_price'] );
		if ( $clear_sale ) {
			$params['sale_price'] = '';
		}
		if ( isset( $params['price_delta'] ) && ! isset( $params['regular_price'] ) ) {
			$current_price = (float) $variation->get_regular_price();
			if ( $current_price <= 0 ) {
				$current_price = (float) $variation->get_price();
			}
			$new_price = round( $current_price + (float) $params['price_delta'], wc_get_price_decimals() );
			$variation->set_regular_price( (string) $new_price );
			$changes[] = 'regular price';
		}
		if ( isset( $params['price_adjust_pct'] ) && ! isset( $params['regular_price'] ) && ! isset( $params['price_delta'] ) ) {
			$current_price = (float) $variation->get_regular_price();
			if ( $current_price <= 0 ) {
				$current_price = (float) $variation->get_price();
			}
			if ( $current_price > 0 ) {
				$new_price = round( $current_price * ( 1 + ( (float) $params['price_adjust_pct'] / 100 ) ), wc_get_price_decimals() );
				$variation->set_regular_price( (string) $new_price );
				$changes[] = 'regular price';
			}
		}
		if ( isset( $params['regular_price'] ) ) {
			$variation->set_regular_price( $params['regular_price'] );
			$changes[] = 'regular price';
		}
		if ( isset( $params['sale_price'] ) ) {
			$variation->set_sale_price( $params['sale_price'] );
			$changes[] = $clear_sale ? 'sale price (cleared)' : 'sale price';
		}
		if ( $clear_sale ) {
			$variation->set_date_on_sale_from( null );
			$variation->set_date_on_sale_to( null );
			if ( $had_sale_schedule ) {
				$changes[] = 'sale schedule (cleared)';
			}
		}
		if ( isset( $params['stock_quantity'] ) ) {
			$variation->set_stock_quantity( intval( $params['stock_quantity'] ) );
			$changes[] = 'stock';
		}
		if ( isset( $params['stock_status'] ) ) {
			$variation->set_stock_status( $params['stock_status'] );
			$changes[] = 'stock status';
		}
		if ( isset( $params['status'] ) ) {
			$variation->set_status( $params['status'] );
			$changes[] = 'status';
		}
		$variation->save();

		// Sync parent price range after variation edit.
		$parent_id = $variation->get_parent_id();
		if ( $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
		}

		return array(
			'success' => true,
			/* translators: 1: variation ID, 2: comma-separated list of applied changes. */
			'message' => sprintf( __( 'Variation #%1$d updated: %2$s.', 'pressark' ), $var_id, implode( ', ', $changes ) ),
		);
	}


	/**
	 * Create a new variation on an existing variable product.
	 * Automatically syncs the parent's price range after creation.
	 */
	public function create_variation( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$ambiguous_price = $this->ambiguous_wc_price_write_response( $params );
		if ( $ambiguous_price ) {
			return $ambiguous_price;
		}

		$params = $this->normalize_variation_params( $params );
		$product_id = absint( $params['product_id'] ?? 0 );
		if ( ! $product_id ) return array( 'error' => __( 'product_id is required.', 'pressark' ) );

		$parent = wc_get_product( $product_id );
		if ( ! $parent ) return array( 'error' => __( 'Product not found.', 'pressark' ) );
		if ( ! $parent->is_type( 'variable' ) ) {
			/* translators: %d: WooCommerce product ID. */
			return array( 'error' => sprintf( __( 'Product %d is not a variable product.', 'pressark' ), $product_id ) );
		}

		// Read parent attributes to validate against.
		$parent_attributes = $parent->get_attributes();
		$variation_attrs   = array();

		foreach ( (array) ( $params['attributes'] ?? array() ) as $attr_name => $attr_value ) {
			// Normalize slug → taxonomy name (color → pa_color).
			$normalized = strpos( $attr_name, 'pa_' ) === 0
				? $attr_name
				: 'pa_' . sanitize_title( $attr_name );

			// Also accept the human label (e.g., "Color" → pa_color).
			if ( ! isset( $parent_attributes[ $normalized ] ) ) {
				// Try by slug directly (for local/custom attributes).
				$normalized = sanitize_title( $attr_name );
			}

			$variation_attrs[ $normalized ] = sanitize_title( $attr_value );
		}

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( $variation_attrs );
		$variation->set_status( sanitize_text_field( $params['status'] ?? 'publish' ) );

		// Apply pricing and stock fields.
		$scalar_fields = array(
			'regular_price'  => 'set_regular_price',
			'sale_price'     => 'set_sale_price',
			'sku'            => 'set_sku',
			'stock_quantity' => 'set_stock_quantity',
			'manage_stock'   => 'set_manage_stock',
			'stock_status'   => 'set_stock_status',
			'weight'         => 'set_weight',
			'length'         => 'set_length',
			'width'          => 'set_width',
			'height'         => 'set_height',
			'virtual'        => 'set_virtual',
			'downloadable'   => 'set_downloadable',
			'backorders'     => 'set_backorders',
		);

		foreach ( $scalar_fields as $key => $setter ) {
			if ( isset( $params[ $key ] ) ) {
				try {
					$variation->$setter( $params[ $key ] );
				} catch ( \WC_Data_Exception $e ) {
					/* translators: 1: field key, 2: WooCommerce error message. */
					return array( 'error' => sprintf( __( 'Error setting %1$s: %2$s', 'pressark' ), $key, $e->getMessage() ) );
				}
			}
		}

		$variation_id = $variation->save();

		if ( ! $variation_id ) {
			return array( 'error' => __( 'Failed to create variation.', 'pressark' ) );
		}

		// CRITICAL: sync parent's price range after adding variation.
		// Without this the parent shows wrong min/max price.
		WC_Product_Variable::sync( $product_id );

		return array(
			'success'      => true,
			'variation_id' => $variation_id,
			'product_id'   => $product_id,
			'attributes'   => $variation_attrs,
			/* translators: 1: variation ID, 2: parent product name. */
			'message'      => sprintf( __( 'Created variation #%1$d on "%2$s".', 'pressark' ), $variation_id, $parent->get_name() ),
			'note'         => __( 'Parent product price range has been synced.', 'pressark' ),
		);
	}


	/**
	 * Bulk edit all variations of a variable product.
	 * Supports: price_adjust_pct (percentage adjustment), absolute price sets,
	 *           sale price, stock operations, and status changes.
	 */
	public function bulk_edit_variations( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$ambiguous_price = $this->ambiguous_wc_price_write_response( $params );
		if ( $ambiguous_price ) {
			return $ambiguous_price;
		}

		$product_id = absint( $params['product_id'] ?? 0 );
		if ( ! $product_id ) return array( 'error' => __( 'product_id is required.', 'pressark' ) );

		$parent = wc_get_product( $product_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return array( 'error' => __( 'Not a variable product.', 'pressark' ) );
		}

		$children = $parent->get_children();
		if ( empty( $children ) ) {
			return array( 'error' => __( 'This variable product has no variations.', 'pressark' ) );
		}

		$updated   = 0;
		$errors    = array();
		$changes   = $params['changes'] ?? array();
		if ( is_array( $changes ) ) {
			$ambiguous_price = $this->ambiguous_wc_price_write_response( $changes );
			if ( $ambiguous_price ) {
				return $ambiguous_price;
			}
		}
		$changes   = is_array( $changes ) ? $this->normalize_variation_changes( $changes ) : array();
		$clear_sale = $this->is_legacy_clear_sale_request( $changes );

		foreach ( $children as $var_id ) {
			$variation = wc_get_product( $var_id );
			if ( ! $variation ) continue;

			try {
				// Percentage price adjustment.
				if ( isset( $changes['price_adjust_pct'] ) ) {
					$pct     = floatval( $changes['price_adjust_pct'] );
					$current = floatval( $variation->get_regular_price() );
					if ( $current <= 0 ) {
						$current = floatval( $variation->get_price() );
					}
					if ( $current > 0 ) {
						$new_price = round( $current * ( 1 + $pct / 100 ), wc_get_price_decimals() );
						$variation->set_regular_price( (string) $new_price );
					}
				}

				if ( isset( $changes['price_delta'] ) && ! isset( $changes['regular_price'] ) ) {
					$current = floatval( $variation->get_regular_price() );
					if ( $current <= 0 ) {
						$current = floatval( $variation->get_price() );
					}
					$new_price = round( $current + floatval( $changes['price_delta'] ), wc_get_price_decimals() );
					$variation->set_regular_price( (string) $new_price );
				}

				// Absolute price fields.
				foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
					if ( isset( $changes[ $field ] ) ) {
						$setter = 'set_' . $field;
						$variation->$setter( sanitize_text_field( $changes[ $field ] ) );
					}
				}

				// Clear sale price.
				if ( $clear_sale ) {
					$variation->set_sale_price( '' );
					$variation->set_date_on_sale_from( null );
					$variation->set_date_on_sale_to( null );
				}

				// Scheduled sale dates.
				if ( ! $clear_sale && isset( $changes['sale_from'] ) ) $variation->set_date_on_sale_from( $changes['sale_from'] ?: null );
				if ( ! $clear_sale && isset( $changes['sale_to'] ) )   $variation->set_date_on_sale_to( $changes['sale_to'] ?: null );

				// Stock.
				if ( isset( $changes['stock_status'] ) ) {
					$variation->set_stock_status( sanitize_text_field( $changes['stock_status'] ) );
				}
				if ( isset( $changes['manage_stock'] ) ) {
					$variation->set_manage_stock( wc_string_to_bool( $changes['manage_stock'] ) );
				}

				// Status.
				if ( isset( $changes['status'] ) ) {
					$variation->set_status( sanitize_text_field( $changes['status'] ) );
				}

				$variation->save();
				$updated++;

			} catch ( \WC_Data_Exception $e ) {
				$errors[] = "Variation #{$var_id}: " . $e->getMessage();
			}
		}

		// Sync parent price range once after all updates.
		WC_Product_Variable::sync( $product_id );

		return array(
			'success'     => $updated > 0,
			'product_id'  => $product_id,
			'product'     => $parent->get_name(),
			'total'       => count( $children ),
			'updated'     => $updated,
			'errors'      => $errors,
			/* translators: 1: number of updated variations, 2: total variations processed. */
			'message'     => sprintf( __( 'Updated %1$d of %2$d variations.', 'pressark' ), $updated, count( $children ) ),
			'changes'     => array_keys( $changes ),
		);
	}


	// ── Prompt 27B: WooCommerce Product Enhancements ─────────────────

	/**
	 * List all registered global product attributes and their terms.
	 * Global attributes are shared across products and can be used for variations.
	 */
	public function list_product_attributes( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$attr_taxonomies = wc_get_attribute_taxonomies();
		$attributes      = array();

		foreach ( $attr_taxonomies as $attr ) {
			$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name ); // e.g., pa_color
			$terms    = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			) );

			$attributes[] = array(
				'id'       => (int) $attr->attribute_id,
				'name'     => $attr->attribute_label, // Human label: "Color"
				'slug'     => $attr->attribute_name,  // Machine slug: "color"
				'taxonomy' => $taxonomy,               // WP taxonomy: "pa_color"
				'type'     => $attr->attribute_type,  // "select" or "text"
				'terms'    => is_array( $terms )
					? array_map( fn( $t ) => array(
						'id'    => $t->term_id,
						'name'  => $t->name,
						'slug'  => $t->slug,
						'count' => $t->count,
					), $terms )
					: array(),
			);
		}

		return array(
			'success'    => true,
			'count'      => count( $attributes ),
			'attributes' => $attributes,
			'hint'       => __( 'Use taxonomy name (e.g. pa_color) when setting attributes on products or variations.', 'pressark' ),
		);
	}


	/**
	 * Create a refund for a WooCommerce order.
	 *
	 * @param array $params Order ID, amount, reason, and refund options.
	 * @return array Result with refund status.
	 */
	public function create_refund( array $params ): array {
		// v3.7.1: Refunds are financial mutations — require edit_shop_orders.
		$err = $this->require_wc_order_cap();
		if ( $err ) return $err;

		$order_id = intval( $params['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'pressark' ) );
		}

		$amount = $params['amount'] ?? $order->get_total();
		$reason = sanitize_text_field( $params['reason'] ?? '' );

		// v3.7.1: Business idempotency — skip if already refunded on a previous retry.
		$receipt_key = 'refund_order_' . $order_id . '_' . md5( (string) $amount );
		if ( $this->has_operation_receipt( $receipt_key ) ) {
			return array(
				'success' => true,
				/* translators: %d: WooCommerce order ID. */
				'message' => sprintf( __( 'Refund for order #%d was already issued on a previous attempt (skipped to prevent double refund).', 'pressark' ), $order_id ),
				'skipped_duplicate' => true,
			);
		}

		// Check gateway refund capability.
		$payment_method   = $order->get_payment_method();
		$gateways         = WC()->payment_gateways()->payment_gateways();
		$gateway          = $gateways[ $payment_method ] ?? null;
		$supports_gateway_refund = $gateway && $gateway->supports( 'refunds' );
		$process_gateway  = ! empty( $params['process_payment'] ) && $supports_gateway_refund;
		$restock          = (bool) ( $params['restock'] ?? true );

		$refund = wc_create_refund( array(
			'amount'         => floatval( $amount ),
			'reason'         => $reason,
			'order_id'       => $order_id,
			'refund_payment' => $process_gateway,
			'restock_items'  => $restock,
		) );

		if ( is_wp_error( $refund ) ) {
			return array( 'success' => false, 'message' => $refund->get_error_message() );
		}

		// v3.7.1: Record receipt so retries skip this refund.
		$this->record_operation_receipt( $receipt_key, 'Refund ' . wc_price( $amount ) . " on order #{$order_id}" );

		return array(
			'success'                  => true,
			'message'                  => sprintf(
				/* translators: 1: refund amount with currency symbol, 2: WooCommerce order number. */
				__( 'Refund of %1$s issued for order #%2$s.', 'pressark' ),
				wc_price( $amount ),
				$order->get_order_number()
			) . ( $reason
				? ' ' . sprintf(
					/* translators: %s: refund reason text. */
					__( 'Reason: %s', 'pressark' ),
					$reason
				)
				: '' ),
			'gateway_refund_supported' => $supports_gateway_refund,
			'gateway_refund_processed' => $process_gateway,
			'restock_applied'          => $restock,
		);
	}


	/**
	 * Get top-selling products for a given period.
	 *
	 * @param array $params Days and limit.
	 * @return array Result with top seller list.
	 */
	public function get_top_sellers( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		global $wpdb;

		$days  = intval( $params['days'] ?? 30 );
		$limit = min( intval( $params['limit'] ?? 10 ), 50 );

		// Single query via WC Analytics lookup tables — no object hydration.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate analytics on WooCommerce lookup tables for an on-demand admin report.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				opl.product_id,
				SUM( opl.product_qty ) as quantity_sold,
				SUM( opl.product_net_revenue ) as revenue,
				p.post_title as name
			 FROM {$wpdb->prefix}wc_order_product_lookup opl
			 JOIN {$wpdb->prefix}wc_order_stats os ON opl.order_id = os.order_id
			 JOIN {$wpdb->posts} p ON opl.product_id = p.ID
			 WHERE os.status IN ( 'wc-completed', 'wc-processing' )
			   AND os.date_created >= %s
			 GROUP BY opl.product_id
			 ORDER BY revenue DESC
			 LIMIT %d",
			gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
			$limit
		) );

		return array(
			'success'  => true,
			'period'   => $days . ' days',
			'products' => array_map( fn( $r ) => array(
				'product_id'    => (int) $r->product_id,
				'name'          => $r->name,
				'quantity_sold' => (int) $r->quantity_sold,
				'revenue'       => wc_price( $r->revenue ),
			), $results ),
		);
	}


	/**
	 * Create a new WooCommerce order programmatically.
	 *
	 * @param array $params Customer, billing, shipping, products, and order options.
	 * @return array Result with order ID and details.
	 */
	public function create_order( array $params ): array {
		// v3.7.1: Order creation is a financial mutation — require edit_shop_orders.
		$err = $this->require_wc_order_cap();
		if ( $err ) return $err;

		// v3.7.1: Business idempotency — deduplicate order creation on retry.
		$customer_id = absint( $params['customer_id'] ?? 0 );
		$product_sig = md5( wp_json_encode( $params['products'] ?? $params['items'] ?? array() ) );
		$receipt_key = 'create_order_' . $customer_id . '_' . $product_sig;
		if ( $this->has_operation_receipt( $receipt_key ) ) {
			return array(
				'success' => true,
				'message' => __( 'Order creation was already completed on a previous attempt (skipped to prevent duplicate order).', 'pressark' ),
				'skipped_duplicate' => true,
			);
		}

		$order = wc_create_order( array(
			'customer_id' => $customer_id,
			'created_via' => 'pressark',
		) );

		if ( is_wp_error( $order ) ) {
			return array( 'error' => $order->get_error_message() );
		}

		// ── Billing address ──────────────────────────────────────────
		if ( ! empty( $params['billing'] ) ) {
			$billing = $params['billing'];
			$order->set_billing_first_name( sanitize_text_field( $billing['first_name'] ?? '' ) );
			$order->set_billing_last_name(  sanitize_text_field( $billing['last_name']  ?? '' ) );
			$order->set_billing_email(      sanitize_email(      $billing['email']       ?? '' ) );
			$order->set_billing_phone(      sanitize_text_field( $billing['phone']       ?? '' ) );
			$order->set_billing_company(    sanitize_text_field( $billing['company']     ?? '' ) );
			$order->set_billing_address_1(  sanitize_text_field( $billing['address_1']  ?? '' ) );
			$order->set_billing_address_2(  sanitize_text_field( $billing['address_2']  ?? '' ) );
			$order->set_billing_city(       sanitize_text_field( $billing['city']        ?? '' ) );
			$order->set_billing_state(      sanitize_text_field( $billing['state']       ?? '' ) );
			$order->set_billing_postcode(   sanitize_text_field( $billing['postcode']    ?? '' ) );
			$order->set_billing_country(    sanitize_text_field( $billing['country']     ?? '' ) );
		} elseif ( ! empty( $params['customer_email'] ) ) {
			// Backward compat with existing simple email param
			$order->set_billing_email( sanitize_email( $params['customer_email'] ) );
		}

		// ── Shipping address ─────────────────────────────────────────
		if ( ! empty( $params['shipping'] ) ) {
			$shipping = $params['shipping'];
			$order->set_shipping_first_name( sanitize_text_field( $shipping['first_name'] ?? '' ) );
			$order->set_shipping_last_name(  sanitize_text_field( $shipping['last_name']  ?? '' ) );
			$order->set_shipping_address_1(  sanitize_text_field( $shipping['address_1']  ?? '' ) );
			$order->set_shipping_address_2(  sanitize_text_field( $shipping['address_2']  ?? '' ) );
			$order->set_shipping_city(       sanitize_text_field( $shipping['city']        ?? '' ) );
			$order->set_shipping_state(      sanitize_text_field( $shipping['state']       ?? '' ) );
			$order->set_shipping_postcode(   sanitize_text_field( $shipping['postcode']    ?? '' ) );
			$order->set_shipping_country(    sanitize_text_field( $shipping['country']     ?? '' ) );
		}

		// ── Payment method ───────────────────────────────────────────
		if ( ! empty( $params['payment_method'] ) ) {
			$method_id = sanitize_text_field( $params['payment_method'] );
			$order->set_payment_method( $method_id );
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways[ $method_id ] ) ) {
				$order->set_payment_method_title( $gateways[ $method_id ]->get_title() );
			}
		}

		// ── Products ─────────────────────────────────────────────────
		$items_added = 0;
		foreach ( (array) ( $params['products'] ?? $params['items'] ?? array() ) as $item ) {
			$product_id   = absint( $item['product_id'] ?? $item['id'] ?? 0 );
			$variation_id = absint( $item['variation_id'] ?? 0 );
			$quantity     = max( 1, absint( $item['quantity'] ?? 1 ) );

			$product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product ) continue;

			$order->add_product( $product, $quantity );
			$items_added++;
		}

		if ( $items_added === 0 ) {
			$order->delete( true );
			return array( 'error' => __( 'No valid products could be added to the order.', 'pressark' ) );
		}

		// ── Coupon ───────────────────────────────────────────────────
		if ( ! empty( $params['coupon_code'] ) ) {
			$coupon_result = $order->apply_coupon( sanitize_text_field( $params['coupon_code'] ) );
			if ( is_wp_error( $coupon_result ) ) {
				$order->add_order_note( 'Coupon error: ' . $coupon_result->get_error_message() );
			}
		}

		// ── Customer note ────────────────────────────────────────────
		if ( ! empty( $params['customer_note'] ) ) {
			$order->set_customer_note( sanitize_text_field( $params['customer_note'] ) );
		}

		// ── Admin note (backward compat) ─────────────────────────────
		if ( ! empty( $params['note'] ) ) {
			$order->add_order_note( sanitize_text_field( $params['note'] ), false );
		}

		// ── Calculate totals then save ───────────────────────────────
		$order->calculate_totals();
		$order->set_status( sanitize_text_field( $params['status'] ?? 'pending' ) );
		$order->save();

		// v3.7.1: Record receipt so retries skip this order creation.
		$this->record_operation_receipt( $receipt_key, "Order #{$order->get_order_number()} created" );

		return array(
			'success'   => true,
			'order_id'  => $order->get_id(),
			'order_num' => $order->get_order_number(),
			'total'     => $order->get_formatted_order_total(),
			'status'    => $order->get_status(),
			'items'     => $items_added,
			'edit_url'  => get_edit_post_link( $order->get_id(), 'raw' ),
			/* translators: 1: WooCommerce order number, 2: customer first name or guest label. */
			'message'   => sprintf( __( 'Order #%1$s created for %2$s.', 'pressark' ), $order->get_order_number(), $order->get_billing_first_name() ?: __( 'guest', 'pressark' ) ),
		);
	}


	/**
	 * Sales performance breakdown by product category.
	 * Uses WC Analytics API — no equivalent currently exists in PressArk.
	 */
	public function category_report( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$days   = min( absint( $params['days'] ?? 30 ), 365 );
		$after  = gmdate( 'Y-m-d\T00:00:00', strtotime( "-{$days} days" ) );
		$before = gmdate( 'Y-m-d\TH:i:s' );

		$data = $this->wc_analytics_request( 'categories', array(
			'before'   => $before,
			'after'    => $after,
			'orderby'  => 'net_revenue',
			'order'    => 'desc',
			'per_page' => 15,
		) );

		if ( empty( $data ) ) {
			return array(
				'error' => __( 'WC Analytics data unavailable. Ensure WooCommerce analytics are enabled.', 'pressark' ),
				'hint'  => __( 'WooCommerce → Settings → Analytics', 'pressark' ),
			);
		}

		$categories = array_map( fn( $c ) => array(
			'id'           => $c['category_id'],
			'name'         => $c['extended_info']['name'] ?? 'Unknown',
			'items_sold'   => (int) $c['items_sold'],
			'net_revenue'  => wc_price( $c['net_revenue'] ),
			'orders_count' => (int) $c['orders_count'],
			'products'     => (int) $c['products_count'],
		), $data );

		return array(
			'success'    => true,
			'period'     => $days . ' days',
			'categories' => $categories,
		);
	}


	// ── Prompt 28B: WooCommerce Revenue, Stock, Webhooks, Events ─────

	/**
	 * Revenue report with automatic period-over-period comparison.
	 * Uses WC Analytics API — HPOS-safe, single aggregate query per period.
	 *
	 * @param array $params days (default 30), interval (day/week/month), compare (bool)
	 */
	public function revenue_report( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$days     = min( absint( $params['days'] ?? 30 ), 365 );
		$interval = in_array( $params['interval'] ?? 'day', array( 'day', 'week', 'month' ), true )
			? ( $params['interval'] ?? 'day' )
			: 'day';

		// Current period
		$current_end   = gmdate( 'Y-m-d\TH:i:s' );
		$current_start = gmdate( 'Y-m-d\T00:00:00', strtotime( "-{$days} days" ) );

		// Previous period (same length)
		$prev_end   = gmdate( 'Y-m-d\TH:i:s', strtotime( "-{$days} days" ) );
		$prev_start = gmdate( 'Y-m-d\T00:00:00', strtotime( "-" . ( $days * 2 ) . " days" ) );

		$current = $this->wc_analytics_request( 'revenue/stats', array(
			'after'    => $current_start,
			'before'   => $current_end,
			'interval' => $interval,
		) );

		if ( empty( $current['totals'] ) ) {
			return array(
				'error' => __( 'WC Analytics data unavailable.', 'pressark' ),
				'hint'  => __( 'Ensure WooCommerce Analytics is enabled and data has been processed. Check WooCommerce → Settings → Analytics.', 'pressark' ),
			);
		}

		$curr_totals = $current['totals'];

		// Period comparison
		$comparison = null;
		if ( ! isset( $params['compare'] ) || $params['compare'] !== false ) {
			$previous = $this->wc_analytics_request( 'revenue/stats', array(
				'after'    => $prev_start,
				'before'   => $prev_end,
				'interval' => $interval,
			) );

			if ( ! empty( $previous['totals'] ) ) {
				$prev_totals = $previous['totals'];
				$pct = fn( $curr, $prev ) => $prev > 0
					? round( ( ( $curr - $prev ) / $prev ) * 100, 1 )
					: null;

				$comparison = array(
					'period'    => "Previous {$days} days",
					'orders'    => array(
						'prev'   => (int) $prev_totals['orders_count'],
						'change' => $pct( $curr_totals['orders_count'], $prev_totals['orders_count'] ),
					),
					'revenue'   => array(
						'prev'   => wc_price( $prev_totals['total_sales'] ?? 0 ),
						'change' => $pct( $curr_totals['total_sales'] ?? 0, $prev_totals['total_sales'] ?? 0 ),
					),
					'avg_order' => array(
						'prev'   => wc_price( $prev_totals['avg_order_value'] ?? 0 ),
						'change' => $pct( $curr_totals['avg_order_value'] ?? 0, $prev_totals['avg_order_value'] ?? 0 ),
					),
				);
			}
		}

		// Daily/weekly breakdown (intervals from API)
		$breakdown = array();
		foreach ( $current['intervals'] ?? array() as $interval_data ) {
			$breakdown[] = array(
				'period'  => $interval_data['interval'] ?? '',
				'orders'  => $interval_data['subtotals']['orders_count'] ?? 0,
				'revenue' => wc_price( $interval_data['subtotals']['total_sales'] ?? 0 ),
			);
		}

		return array(
			'success'     => true,
			'period'      => "{$days} days (ending now)",
			'totals'      => array(
				'orders'          => (int) ( $curr_totals['orders_count'] ?? 0 ),
				'revenue'         => wc_price( $curr_totals['total_sales'] ?? 0 ),
				'net_revenue'     => wc_price( $curr_totals['net_revenue'] ?? 0 ),
				'avg_order_value' => wc_price( $curr_totals['avg_order_value'] ?? 0 ),
				'items_sold'      => (int) ( $curr_totals['items_sold'] ?? 0 ),
				'refunds'         => wc_price( $curr_totals['refunds'] ?? 0 ),
				'taxes'           => wc_price( $curr_totals['taxes'] ?? 0 ),
				'shipping'        => wc_price( $curr_totals['shipping'] ?? 0 ),
			),
			'vs_previous' => $comparison,
			'breakdown'   => array_slice( $breakdown, -14 ),
			'data_source' => 'WC Analytics (wc-analytics/reports/revenue/stats)',
		);
	}


	/**
	 * Inventory status report.
	 * Returns products by stock status (out of stock, low stock, in stock)
	 * with inventory valuation.
	 *
	 * @param array $params status (outofstock/lowstock/instock/all), limit
	 */
	public function stock_report( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$limit     = min( absint( $params['limit'] ?? 30 ), 100 );
		$status    = sanitize_text_field( $params['status'] ?? 'all' );
		$threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		$products = $this->get_stock_report_products( $status, $threshold, $limit );

		// Group by status
		$grouped = array( 'outofstock' => array(), 'lowstock' => array(), 'instock' => array(), 'onbackorder' => array() );

		foreach ( $products as $p ) {
			$qty       = $p->stock_quantity;
			$is_low    = $p->stock_status === 'instock' && $qty !== null && $qty <= $threshold;
			$group_key = $is_low ? 'lowstock' : $p->stock_status;

			$grouped[ $group_key ][] = array(
				'id'     => (int) $p->product_id,
				'name'   => $p->name,
				'sku'    => $p->sku,
				'stock'  => $qty !== null ? (int) $qty : 'not managed',
				'status' => $p->stock_status,
				'price'  => $p->price ? wc_price( $p->price ) : null,
			);
		}

		global $wpdb;

		// Inventory valuation — total stock value
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only analytics on WooCommerce core tables for an on-demand admin report.
		$valuation = $wpdb->get_var(
			"SELECT SUM( lk.stock_quantity * lk.min_price )
			 FROM {$wpdb->prefix}wc_product_meta_lookup lk
			 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
			 WHERE lk.stock_status = 'instock'
			   AND lk.stock_quantity > 0
			   AND p.post_status = 'publish'"
		);

		// Summary counts
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only analytics on WooCommerce core tables for an on-demand admin report.
		$counts = $wpdb->get_results(
			"SELECT lk.stock_status, COUNT(*) as count
			 FROM {$wpdb->prefix}wc_product_meta_lookup lk
			 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
			 WHERE p.post_status = 'publish'
			 GROUP BY lk.stock_status"
		);
		$count_map = array();
		foreach ( $counts as $c ) {
			$count_map[ $c->stock_status ] = (int) $c->count;
		}

		return array(
			'success'             => true,
			'low_stock_threshold' => $threshold,
			'summary'             => array(
				'out_of_stock' => $count_map['outofstock']  ?? 0,
				'in_stock'     => $count_map['instock']     ?? 0,
				'on_backorder' => $count_map['onbackorder'] ?? 0,
			),
			'inventory_value' => $valuation ? wc_price( (float) $valuation ) : null,
			'products'        => $grouped,
			'filter'          => $status,
		);
	}

	/**
	 * Load stock report rows with fixed SQL branches instead of an interpolated WHERE fragment.
	 *
	 * @return array<int, object>
	 */
	private function get_stock_report_products( string $status, int $threshold, int $limit ): array {
		global $wpdb;

		$products = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only stock analytics on WooCommerce lookup tables for an on-demand admin report.
		switch ( $status ) {
			case 'outofstock':
				$products = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						lk.product_id,
						p.post_title as name,
						lk.sku,
						lk.stock_quantity,
						lk.stock_status,
						lk.min_price as price
					 FROM {$wpdb->prefix}wc_product_meta_lookup lk
					 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
					 WHERE lk.stock_status = 'outofstock'
					   AND p.post_status = 'publish'
					 ORDER BY lk.stock_quantity ASC
					 LIMIT %d",
					$limit
				) );
				break;
			case 'lowstock':
				$products = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						lk.product_id,
						p.post_title as name,
						lk.sku,
						lk.stock_quantity,
						lk.stock_status,
						lk.min_price as price
					 FROM {$wpdb->prefix}wc_product_meta_lookup lk
					 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
					 WHERE lk.stock_status = 'instock'
					   AND lk.stock_quantity IS NOT NULL
					   AND lk.stock_quantity <= %d
					   AND p.post_status = 'publish'
					 ORDER BY lk.stock_quantity ASC
					 LIMIT %d",
					$threshold,
					$limit
				) );
				break;
			case 'instock':
				$products = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						lk.product_id,
						p.post_title as name,
						lk.sku,
						lk.stock_quantity,
						lk.stock_status,
						lk.min_price as price
					 FROM {$wpdb->prefix}wc_product_meta_lookup lk
					 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
					 WHERE lk.stock_status = 'instock'
					   AND p.post_status = 'publish'
					 ORDER BY lk.stock_quantity ASC
					 LIMIT %d",
					$limit
				) );
				break;
			default:
				$products = $wpdb->get_results( $wpdb->prepare(
					"SELECT
						lk.product_id,
						p.post_title as name,
						lk.sku,
						lk.stock_quantity,
						lk.stock_status,
						lk.min_price as price
					 FROM {$wpdb->prefix}wc_product_meta_lookup lk
					 JOIN {$wpdb->posts} p ON lk.product_id = p.ID
					 WHERE lk.stock_status IN ('outofstock', 'instock', 'onbackorder')
					   AND p.post_status = 'publish'
					 ORDER BY lk.stock_quantity ASC
					 LIMIT %d",
					$limit
				) );
				break;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $products ) ? $products : array();
	}


	/**
	 * List, pause, activate, disable, and delete WooCommerce webhooks.
	 * Webhooks connect WC to external integrations — failures break them silently.
	 */
	public function manage_webhooks( array $params ): array {
		// v3.7.1: Webhook management requires manage_woocommerce.
		// List is read-only but still gated because webhook URLs/secrets are sensitive.
		$err = $this->require_wc_manage_cap();
		if ( $err ) return $err;

		$action = sanitize_text_field( $params['action'] ?? 'list' );

		switch ( $action ) {

			case 'list':
				$data_store  = \WC_Data_Store::load( 'webhook' );
				$webhook_ids = $data_store->search_webhooks( array(
					'limit'  => min( absint( $params['limit'] ?? 20 ), 50 ),
					'status' => sanitize_text_field( $params['status'] ?? '' ),
				) );
				$webhooks = array_map( fn( $id ) => new \WC_Webhook( $id ), $webhook_ids );

				$list = array();
				foreach ( $webhooks as $webhook ) {
					$failure_count = $webhook->get_failure_count();
					$list[] = array(
						'id'            => $webhook->get_id(),
						'name'          => $webhook->get_name(),
						'topic'         => $webhook->get_topic(),
						'delivery_url'  => $webhook->get_delivery_url(),
						'status'        => $webhook->get_status(),
						'failure_count' => $failure_count,
						'api_version'   => $webhook->get_api_version(),
						'health'        => $failure_count === 0 ? 'healthy'
						                 : ( $failure_count < 5 ? 'degraded' : 'failing' ),
					);
				}

				// Surface failing webhooks
				$failing = array_filter( $list, fn( $w ) => $w['failure_count'] > 0 );

				return array(
					'success'  => true,
					'count'    => count( $list ),
					'webhooks' => $list,
					'flags'    => ! empty( $failing )
						? array(
							sprintf(
								/* translators: %d: number of webhooks with delivery failures. */
								__( '%d webhook(s) have delivery failures — integrations may be broken.', 'pressark' ),
								count( $failing )
							)
						)
						: array(),
				);

			case 'pause':
			case 'activate':
			case 'disable':
				$webhook_id = absint( $params['webhook_id'] ?? 0 );
				if ( ! $webhook_id ) return array( 'error' => __( 'webhook_id required.', 'pressark' ) );

				$webhook = new \WC_Webhook( $webhook_id );
				if ( ! $webhook->get_id() ) return array( 'error' => __( 'Webhook not found.', 'pressark' ) );

				$new_status = match( $action ) {
					'pause'    => 'paused',
					'activate' => 'active',
					'disable'  => 'disabled',
				};
				$webhook->set_status( $new_status );
				$webhook->save();

				return array(
					'success' => true,
					'id'      => $webhook_id,
					'name'    => $webhook->get_name(),
					'status'  => $new_status,
					/* translators: 1: webhook name, 2: webhook status. */
					'message' => sprintf( __( 'Webhook \'%1$s\' %2$s.', 'pressark' ), $webhook->get_name(), $new_status ),
				);

			case 'delete':
				$webhook_id = absint( $params['webhook_id'] ?? 0 );
				if ( ! $webhook_id ) return array( 'error' => __( 'webhook_id required.', 'pressark' ) );

				// v3.7.1: Business idempotency — skip if already deleted on a previous retry.
				$del_receipt_key = 'webhook_delete_' . $webhook_id;
				if ( $this->has_operation_receipt( $del_receipt_key ) ) {
					return array( 'success' => true, 'message' => __( 'Webhook already deleted on a previous attempt.', 'pressark' ), 'skipped_duplicate' => true );
				}

				$webhook = new \WC_Webhook( $webhook_id );
				if ( ! $webhook->get_id() ) return array( 'error' => __( 'Webhook not found.', 'pressark' ) );

				$name = $webhook->get_name();
				$webhook->delete( true );

				$this->record_operation_receipt( $del_receipt_key, "Webhook '{$name}' deleted" );

				/* translators: %s: deleted webhook name. */
				return array( 'success' => true, 'message' => sprintf( __( 'Webhook \'%s\' deleted.', 'pressark' ), $name ) );

			default:
				/* translators: %s: requested webhook action. */
				return array( 'error' => sprintf( __( 'Unknown action \'%s\'. Use list, pause, activate, disable, delete.', 'pressark' ), $action ) );
		}
	}


	/**
	 * Retrieve and optionally clear proactive WC event alerts.
	 */
	public function get_wc_alerts( array $params ): array {
		$events = PressArk_WC_Events::get_unread_events( 20 );

		if ( empty( $events ) ) {
			return array(
				'success'     => true,
				'alert_count' => 0,
				'message'     => __( 'No new WooCommerce alerts.', 'pressark' ),
			);
		}

		// Group by type
		$grouped = array();
		foreach ( $events as $event ) {
			$type = $event['type'];
			$data = $event['data'];
			$ago  = human_time_diff( $event['time'], time() ) . ' ago';

			$grouped[ $type ][] = match( $type ) {
				'low_stock'       => "{$data['name']} — {$data['stock']} remaining ({$ago})",
				'out_of_stock'    => "{$data['name']} — out of stock ({$ago})",
				'order_failed'    => "Order #{$data['number']} failed — {$data['customer']} ({$ago})",
				'order_cancelled' => "Order #{$data['number']} cancelled — {$data['customer']} ({$ago})",
				default           => wp_json_encode( $data ) . " ({$ago})",
			};
		}

		// Mark as read
		if ( empty( $params['peek'] ) ) {
			PressArk_WC_Events::mark_all_read();
		}

		return array(
			'success'     => true,
			'alert_count' => count( $events ),
			'alerts'      => $grouped,
		);
	}


	// ── Prompt 26B: WooCommerce New Capabilities ─────────────────────

	/**
	 * Trigger any WooCommerce email programmatically.
	 * Uses WC's own email templates, respects store branding.
	 */
	public function trigger_wc_email( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$email_type = sanitize_text_field( $params['email_type'] ?? '' );
		$order_id   = absint( $params['order_id'] ?? 0 );

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		// List available email types if not specified.
		if ( empty( $email_type ) ) {
			$available = array();
			foreach ( $emails as $key => $email ) {
				$available[] = array(
					'type'      => $key,
					'title'     => $email->get_title(),
					'enabled'   => $email->is_enabled(),
					'recipient' => $email->is_customer_email() ? 'customer' : 'admin',
				);
			}
			return array(
				'success'   => false,
				'message'   => __( 'Specify email_type. Available types below.', 'pressark' ),
				'available' => $available,
			);
		}

		if ( ! isset( $emails[ $email_type ] ) ) {
			return array(
				/* translators: %s: requested WooCommerce email type key. */
				'error' => sprintf( __( 'Email type \'%s\' not found.', 'pressark' ), $email_type ),
				'hint'  => __( 'Call trigger_wc_email without email_type to see available types.', 'pressark' ),
			);
		}

		$email = $emails[ $email_type ];

		if ( ! $email->is_enabled() ) {
			return array(
				/* translators: %s: WooCommerce email title. */
				'error' => sprintf( __( 'Email \'%s\' is disabled in WooCommerce settings.', 'pressark' ), $email->get_title() ),
				'hint'  => __( 'Enable it at WooCommerce → Settings → Emails.', 'pressark' ),
			);
		}

		// Emails that require an order.
		$order_required = in_array( $email_type, array(
			'WC_Email_New_Order',
			'WC_Email_Cancelled_Order',
			'WC_Email_Failed_Order',
			'WC_Email_Customer_On_Hold_Order',
			'WC_Email_Customer_Processing_Order',
			'WC_Email_Customer_Completed_Order',
			'WC_Email_Customer_Refunded_Order',
			'WC_Email_Customer_Invoice',
			'WC_Email_Customer_Note',
		), true );

		if ( $order_required && ! $order_id ) {
			/* translators: %s: WooCommerce email type key. */
			return array( 'error' => sprintf( __( 'Email type \'%s\' requires order_id.', 'pressark' ), $email_type ) );
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				/* translators: %d: WooCommerce order ID. */
				return array( 'error' => sprintf( __( 'Order %d not found.', 'pressark' ), $order_id ) );
			}
			$email->trigger( $order_id, $order );
		} else {
			$email->trigger();
		}

		return array(
			'success'    => true,
			'email_type' => $email_type,
			'title'      => $email->get_title(),
			'order_id'   => $order_id ?: null,
			/* translators: %s: WooCommerce email title. */
			'message'    => sprintf( __( '\'%s\' email triggered successfully.', 'pressark' ), $email->get_title() ),
		);
	}


	/**
	 * Get all registered WooCommerce order statuses.
	 * Includes core statuses plus any custom ones registered by plugins.
	 */
	public function get_order_statuses( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$statuses = wc_get_order_statuses();

		$list = array();
		foreach ( $statuses as $key => $label ) {
			$list[] = array(
				'key'   => $key,
				'slug'  => ltrim( $key, 'wc-' ),
				'label' => $label,
			);
		}

		return array(
			'success'  => true,
			'count'    => count( $list ),
			'statuses' => $list,
			'hint'     => __( 'Use the slug (without wc- prefix) in list_orders status filter.', 'pressark' ),
		);
	}


	/**
	 * Get products currently on sale.
	 * Uses WC's cached sale product list — very fast.
	 */
	public function get_products_on_sale( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		$on_sale_ids = wc_get_product_ids_on_sale();
		$limit       = min( absint( $params['limit'] ?? 20 ), 100 );
		$on_sale_ids = array_slice( $on_sale_ids, 0, $limit );
		$price_decimals = wc_get_price_decimals();
		$raw_price = static function ( $value ) use ( $price_decimals ): ?float {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				return null;
			}

			return round( (float) wc_format_decimal( $value ), $price_decimals );
		};
		$format_price = static function ( ?float $value ): ?string {
			return null === $value ? null : wc_price( $value );
		};

		$products = array();
		foreach ( $on_sale_ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) continue;

			$sale_end = $p->get_date_on_sale_to();
			$regular_price    = $raw_price( $p->get_regular_price() );
			$sale_price       = $raw_price( $p->get_sale_price() );
			$price            = $raw_price( $p->get_price() );
			$discount_amount  = null;
			$discount_percent = null;
			$discount_pct     = null;

			if ( null !== $regular_price && null !== $sale_price && $regular_price > 0 && $sale_price < $regular_price ) {
				$discount_amount  = round( $regular_price - $sale_price, $price_decimals );
				$discount_percent = round( ( $discount_amount / $regular_price ) * 100, 2 );
				$discount_pct     = round( $discount_percent ) . '%';
			}

			$products[] = array(
				'id'                => $id,
				'name'              => $p->get_name(),
				'type'              => $p->get_type(),
				'price'             => $price,
				'price_formatted'   => $format_price( $price ),
				'regular_price'     => $format_price( $regular_price ),
				'regular_price_raw' => $regular_price,
				'sale_price'        => $format_price( $sale_price ),
				'sale_price_raw'    => $sale_price,
				'on_sale'           => (bool) $p->is_on_sale(),
				'discount_amount'   => $discount_amount,
				'discount_percent'  => $discount_percent,
				'discount_pct'      => $discount_pct,
				'sale_ends'         => $sale_end ? $sale_end->date( 'Y-m-d' ) : null,
				'days_left'         => $sale_end
					? max( 0, (int) ceil( ( $sale_end->getTimestamp() - time() ) / 86400 ) )
					: null,
			);
		}

		// Sort by days_left ascending (soonest ending first).
		usort( $products, fn( $a, $b ) =>
			( $a['days_left'] ?? PHP_INT_MAX ) <=> ( $b['days_left'] ?? PHP_INT_MAX )
		);

		return array(
			'success'        => true,
			'currency'       => get_woocommerce_currency(),
			'price_decimals' => $price_decimals,
			'total_on_sale'  => count( wc_get_product_ids_on_sale() ),
			'shown'          => count( $products ),
			'products'       => $products,
			'note'          => __( 'Sorted by sale end date — soonest expiring first.', 'pressark' ),
		);
	}


	/**
	 * Customer insights using RFM segmentation.
	 * Segments customers by Recency, Frequency, and Monetary value.
	 */
	public function customer_insights( array $params ): array {
		$err = $this->require_wc();
		if ( $err ) return $err;

		global $wpdb;

		// Check if wc_customer_lookup table exists (WC 4.0+).
		$table = $wpdb->prefix . 'wc_customer_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only existence check on a WooCommerce analytics table for a guarded admin report.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'error' => __( 'WC Analytics tables not found. Run WooCommerce → Status → Tools → Update database.', 'pressark' ) );
		}

		// RFM segmentation — single query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only analytics on WooCommerce core tables for an on-demand admin report.
		$customers = $wpdb->get_results(
			"SELECT
				cl.customer_id,
				cl.user_id,
				cl.email,
				cl.first_name,
				cl.last_name,
				DATEDIFF( NOW(), MAX( os.date_created ) ) as recency_days,
				COUNT( os.order_id ) as frequency,
				SUM( os.total_sales ) as monetary,
				CASE
					WHEN DATEDIFF( NOW(), MAX( os.date_created ) ) <= 30  THEN 'active'
					WHEN DATEDIFF( NOW(), MAX( os.date_created ) ) <= 90  THEN 'cooling'
					WHEN DATEDIFF( NOW(), MAX( os.date_created ) ) <= 180 THEN 'at_risk'
					ELSE 'churned'
				END as segment
			 FROM {$wpdb->prefix}wc_customer_lookup cl
			 JOIN {$wpdb->prefix}wc_order_stats os
				ON cl.customer_id = os.customer_id
				AND os.status IN ( 'wc-completed', 'wc-processing' )
			 GROUP BY cl.customer_id
			 HAVING frequency > 0
			 ORDER BY monetary DESC
			 LIMIT 500"
		);

		// Aggregate into segments.
		$segments = array(
			'active'  => array( 'label' => __( 'Active (bought in last 30 days)', 'pressark' ), 'count' => 0, 'revenue' => 0, 'top' => array() ),
			'cooling' => array( 'label' => __( 'Cooling (31-90 days)', 'pressark' ), 'count' => 0, 'revenue' => 0, 'top' => array() ),
			'at_risk' => array( 'label' => __( 'At Risk (91-180 days)', 'pressark' ), 'count' => 0, 'revenue' => 0, 'top' => array() ),
			'churned' => array( 'label' => __( 'Churned (180+ days)', 'pressark' ), 'count' => 0, 'revenue' => 0, 'top' => array() ),
		);

		foreach ( $customers as $c ) {
			$seg = $c->segment;
			$segments[ $seg ]['count']++;
			$segments[ $seg ]['revenue'] += (float) $c->monetary;

			// Keep top 3 per segment for examples.
			if ( count( $segments[ $seg ]['top'] ) < 3 ) {
				$segments[ $seg ]['top'][] = array(
					'name'        => trim( $c->first_name . ' ' . $c->last_name ) ?: $c->email,
					'email'       => $c->email,
					'total_spent' => wc_price( $c->monetary ),
					'order_count' => (int) $c->frequency,
					'last_seen'   => $c->recency_days . ' days ago',
				);
			}
		}

		// Format revenue in segments.
		foreach ( $segments as &$seg ) {
			$seg['revenue'] = wc_price( $seg['revenue'] );
		}

		// Summary stats.
		$total_customers = count( $customers );
		$total_revenue   = array_sum( array_column( $customers, 'monetary' ) );

		return array(
			'success'         => true,
			'total_customers' => $total_customers,
			'total_revenue'   => wc_price( $total_revenue ),
			'segments'        => $segments,
			'insight'         => array_values( array_filter( array(
				$segments['churned']['count'] > $segments['active']['count']
					? __( 'More churned customers than active — consider a win-back campaign.', 'pressark' )
					: null,
				$segments['at_risk']['count'] > 0
					? sprintf(
						/* translators: %d: number of customers in the at-risk segment. */
						__( '%d customers are at risk of churning.', 'pressark' ),
						$segments['at_risk']['count']
					)
					: null,
			) ) ),
		);
	}


	private function format_shipping_zone( $zone, bool $is_default = false ): array {
		$methods           = $zone->get_shipping_methods();
		$formatted_methods = array();

		foreach ( $methods as $method ) {
			$method_data = array(
				'id'          => $method->id,
				'instance_id' => $method->instance_id,
				'title'       => $method->get_title(),
				'enabled'     => $method->is_enabled(),
				'type'        => $method->id,
			);

			// Expose method-specific settings
			switch ( $method->id ) {
				case 'flat_rate':
					$method_data['cost']       = $method->get_option( 'cost' );
					$method_data['tax_status'] = $method->get_option( 'tax_status' );
					$method_data['cost_note']  = __( 'Cost can include product class surcharges', 'pressark' );
					break;

				case 'free_shipping':
					$requires   = $method->get_option( 'requires' );
					$min_amount = $method->get_option( 'min_amount' );
					$method_data['requires']   = $requires;
					$method_data['min_amount'] = $min_amount;
					$method_data['condition']  = match( $requires ) {
						'min_amount' => sprintf(
							/* translators: %s: minimum order amount formatted with currency. */
							__( 'Free shipping when order >= %s', 'pressark' ),
							$min_amount
						),
						'coupon'     => __( 'Free shipping with valid coupon', 'pressark' ),
						'both'       => sprintf(
							/* translators: %s: minimum order amount formatted with currency. */
							__( 'Free shipping with coupon AND order >= %s', 'pressark' ),
							$min_amount
						),
						'either'     => sprintf(
							/* translators: %s: minimum order amount formatted with currency. */
							__( 'Free shipping with coupon OR order >= %s', 'pressark' ),
							$min_amount
						),
						default      => __( 'Always free shipping', 'pressark' ),
					};
					break;

				case 'local_pickup':
					$method_data['cost']       = $method->get_option( 'cost' ) ?: __( 'Free', 'pressark' );
					$method_data['tax_status'] = $method->get_option( 'tax_status' );
					break;
			}

			$formatted_methods[] = $method_data;
		}

		$locations           = $zone->get_zone_locations();
		$formatted_locations = array();
		foreach ( $locations as $loc ) {
			$formatted_locations[] = array(
				'type' => $loc->type,
				'code' => $loc->code,
			);
		}

		return array(
			'id'         => $zone->get_id(),
			'name'       => $zone->get_zone_name(),
			'is_default' => $is_default,
			'locations'  => $formatted_locations,
			'methods'    => $formatted_methods,
		);
	}


	/**
	 * Fetch WC Analytics data via internal REST dispatch.
	 * HPOS-safe, uses WC's own reporting infrastructure.
	 *
	 * @param string $endpoint e.g. 'revenue/stats', 'products', 'stock'
	 * @param array  $query_params
	 * @return array|null Returns data array or null on failure.
	 */
	private function wc_analytics_request( string $endpoint, array $query_params = array() ): ?array {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/' . $endpoint );
		$request->set_query_params( $query_params );
		$response = rest_do_request( $request );

		if ( $response->is_error() || $response->get_status() >= 400 ) {
			return null; // Caller should fall back to SQL.
		}

		return $this->normalize_wc_analytics_payload( $response->get_data() );
	}

	/**
	 * Normalize WooCommerce Analytics REST payloads to arrays recursively.
	 *
	 * Some installs return nested stdClass rows (for example interval subtotals),
	 * while the reporting helpers expect array access.
	 *
	 * @param mixed $value Raw analytics payload fragment.
	 * @return mixed
	 */
	private function normalize_wc_analytics_payload( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->normalize_wc_analytics_payload( $item );
			}
			return $value;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->normalize_wc_analytics_payload( $item );
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Build a clear environmental failure when WordPress mail transport is unavailable.
	 *
	 * @param \WP_Error|null $mail_error Captured wp_mail_failed error, if any.
	 * @return array<string,mixed>
	 */
	private function build_email_delivery_failure( $mail_error = null ): array {
		$detail = '';
		$code   = 'mail_transport_unavailable';

		if ( $mail_error instanceof \WP_Error ) {
			$detail = trim( (string) $mail_error->get_error_message() );
			$raw    = (string) $mail_error->get_error_code();
			if ( '' !== $raw ) {
				$code = $raw;
			}
		}

		$message = __( 'Email could not be sent because this WordPress environment does not have a working mail transport.', 'pressark' );
		if ( '' !== $detail ) {
			$message .= ' ' . sprintf(
				/* translators: %s: wp_mail failure detail. */
				__( 'wp_mail reported: %s', 'pressark' ),
				$detail
			);
		}

		return array(
			'success'       => false,
			'message'       => $message,
			'hint'          => __( 'Configure SMTP/sendmail for WordPress before retrying email_customer.', 'pressark' ),
			'failure_code'  => $code,
			'environmental' => true,
		);
	}


	private function log_email( array $entry ): void {
		$log = get_option( 'pressark_email_log', array() );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 100 );
		update_option( 'pressark_email_log', $log, false );
	}

	/**
	 * Extract product objects from a string that fails json_decode.
	 *
	 * Models sometimes produce JSON strings with broken escaping (e.g.,
	 * 15\" for an inch mark inside a description). Rather than trying to
	 * repair the full JSON, we isolate each top-level object by tracking
	 * brace depth and string boundaries, then try to decode each one
	 * individually. Objects that fail are skipped with a logged warning.
	 *
	 * @since 5.2.0
	 * @param string $raw The string-encoded products array.
	 * @return array Decoded product objects that parsed successfully.
	 */
	private function extract_product_objects_from_string( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw || '[' !== $raw[0] ) {
			return array();
		}

		// Strip outer brackets.
		$inner = trim( substr( $raw, 1, -1 ) );
		if ( '' === $inner ) {
			return array();
		}

		// Walk the string tracking depth and string state to find
		// the boundaries of each top-level { ... } object.
		$len       = strlen( $inner );
		$depth     = 0;
		$in_string = false;
		$escape    = false;
		$obj_start = null;
		$objects   = array();

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $inner[ $i ];

			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $c && $in_string ) {
				$escape = true;
				continue;
			}
			if ( '"' === $c ) {
				$in_string = ! $in_string;
				continue;
			}
			if ( $in_string ) {
				continue;
			}
			if ( '{' === $c ) {
				if ( 0 === $depth ) {
					$obj_start = $i;
				}
				$depth++;
			}
			if ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth && null !== $obj_start ) {
					$objects[] = substr( $inner, $obj_start, $i - $obj_start + 1 );
					$obj_start = null;
				}
			}
		}

		// Try to decode each object individually.
		$products = array();
		foreach ( $objects as $idx => $obj_str ) {
			$obj = json_decode( $obj_str, true );
			if ( is_array( $obj ) && ! empty( $obj['post_id'] ?? $obj['id'] ?? 0 ) ) {
				$products[] = $obj;
				continue;
			}

			// Last resort: try to at least extract post_id and description
			// from the broken object with regex.
			$pid = 0;
			if ( preg_match( '/"post_id"\s*:\s*(\d+)/', $obj_str, $m ) ) {
				$pid = (int) $m[1];
			} elseif ( preg_match( '/"id"\s*:\s*(\d+)/', $obj_str, $m ) ) {
				$pid = (int) $m[1];
			}

			if ( $pid > 0 ) {
				$changes = array();
				// Extract string values by walking from the key to the closing quote,
				// handling the \" ambiguity by checking if the char after the quote
				// is a JSON structural character (end of value) or content (stray escape).
				foreach ( array( 'description', 'short_description' ) as $field ) {
					$pattern = '/"' . $field . '"\s*:\s*"/';
					if ( preg_match( $pattern, $obj_str, $fm, PREG_OFFSET_CAPTURE ) ) {
						$val_start = $fm[0][1] + strlen( $fm[0][0] );
						$val       = '';
						$j         = $val_start;
						$obj_len   = strlen( $obj_str );
						$esc       = false;

						while ( $j < $obj_len ) {
							$ch = $obj_str[ $j ];

							if ( $esc ) {
								$val .= $ch;
								$esc  = false;
								$j++;
								continue;
							}

							if ( '\\' === $ch && $j + 1 < $obj_len && '"' === $obj_str[ $j + 1 ] ) {
								// Is this \" the JSON string-close or a stray content escape?
								$after = $j + 2 < $obj_len ? $obj_str[ $j + 2 ] : '';
								if ( preg_match( '/[\s,}\]]/', $after ) || '' === $after ) {
									break; // Real end of JSON string value.
								}
								// Stray content escape (e.g. 15\") — keep the quote, drop the backslash.
								$val .= '"';
								$j   += 2;
								continue;
							}

							if ( '\\' === $ch ) {
								$val .= $ch;
								$esc  = true;
								$j++;
								continue;
							}

							if ( '"' === $ch ) {
								break; // Unescaped close quote.
							}

							$val .= $ch;
							$j++;
						}

						if ( '' !== $val ) {
							$changes[ $field ] = $val;
						}
					}
				}

				if ( ! empty( $changes ) ) {
					$products[] = array(
						'post_id' => $pid,
						'changes' => $changes,
					);
				}
			}
		}

		return $products;
	}

	private function normalize_product_changes( array $changes ): array {
		$changes = $this->normalize_price_and_stock_aliases( $changes );

		foreach ( array( 'regular_price', 'sale_price', 'price_delta', 'price_adjust_pct', 'sale_adjust_pct' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$changes[ $field ] = $this->normalize_wc_decimal_value( $changes[ $field ] );
			}
		}

		return $changes;
	}

	private function normalize_variation_params( array $params ): array {
		return $this->normalize_variation_changes( $params );
	}

	private function normalize_variation_changes( array $changes ): array {
		$changes = $this->normalize_price_and_stock_aliases( $changes );

		foreach ( array( 'regular_price', 'sale_price', 'price_delta', 'price_adjust_pct', 'sale_adjust_pct' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				$changes[ $field ] = $this->normalize_wc_decimal_value( $changes[ $field ] );
			}
		}

		return $changes;
	}

	private function normalize_price_and_stock_aliases( array $data ): array {
		$alias_map = array(
			'stock'            => 'stock_quantity',
			'inventory'        => 'stock_quantity',
			'qty'              => 'stock_quantity',
			'clear_sale_price' => 'clear_sale',
			'remove_sale'      => 'clear_sale',
			'end_sale'         => 'clear_sale',
		);

		foreach ( $alias_map as $alias => $canonical ) {
			if ( ! array_key_exists( $alias, $data ) ) {
				continue;
			}
			if ( ! array_key_exists( $canonical, $data ) ) {
				$data[ $canonical ] = $data[ $alias ];
			}
			unset( $data[ $alias ] );
		}

		return $data;
	}

	private function detect_ambiguous_wc_price_write( array $data ): ?array {
		$ambiguous_fields = array();
		foreach ( array( 'price', 'product_price' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$ambiguous_fields[] = $field;
			}
		}

		if ( ! empty( $ambiguous_fields ) ) {
			$field_labels = array_map(
				static function ( string $field ): string {
					return '"' . $field . '"';
				},
				$ambiguous_fields
			);

			return array(
				'code'    => 'ambiguous_price_field',
				'message' => sprintf(
					/* translators: %s: comma-separated unexpected field names */
					__( 'Unexpected WooCommerce price field(s): %s. Do not use plain price for WooCommerce writes.', 'pressark' ),
					implode( ', ', $field_labels )
				),
				'hint'    => __( 'Choose one explicit field instead: regular_price for the base price, sale_price for a sale amount, or clear_sale=true to remove a sale. clear_sale is the canonical sale-removal path.', 'pressark' ),
			);
		}

		// Price fields are mutually exclusive — combining them is how R091
		// produced "sale_price=0 + price_adjust_pct=-10" which gave every
		// product a $0.00 sale price. Reject combos before any writer fires
		// so the model gets a clear error and can retry with one approach.
		$price_fields = array();
		foreach ( array( 'regular_price', 'sale_price', 'price_delta', 'price_adjust_pct', 'sale_adjust_pct' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$price_fields[] = $field;
			}
		}
		if ( count( $price_fields ) > 1 ) {
			return array(
				'code'    => 'conflicting_price_fields',
				'message' => sprintf(
					/* translators: %s: comma-separated price field names */
					__( 'Price fields are mutually exclusive. Got: %s. Pick ONE.', 'pressark' ),
					implode( ', ', $price_fields )
				),
				'hint'    => __( 'For a percentage-off SALE (keeps regular_price intact, shows as "Sale!"), use ONLY sale_adjust_pct (e.g. -10 for 10% off the regular price, stored as sale_price). To permanently change regular price by a percentage, use ONLY price_adjust_pct. For a specific sale amount per product, use ONLY sale_price. To remove an existing sale, use clear_sale=true. Never combine them; never set sale_price=0.', 'pressark' ),
			);
		}

		// sale_price=0 is almost always a mistake — it gives the product away.
		// If the model wants "remove the sale", use clear_sale. If it wants a
		// percentage-off, use price_adjust_pct. Both of those are clearer than
		// zero. Reject and teach the correct pattern.
		if ( array_key_exists( 'sale_price', $data ) ) {
			$raw = $data['sale_price'];
			$is_zero = ( is_numeric( $raw ) && 0.0 === (float) $raw ) || '0' === trim( (string) $raw ) || '0.00' === trim( (string) $raw );
			if ( $is_zero ) {
				return array(
					'code'    => 'sale_price_zero',
					'message' => __( 'sale_price=0 would make the product free. This is almost always a mistake.', 'pressark' ),
					'hint'    => __( 'To remove an existing sale, use clear_sale=true (keeps regular_price intact). For a percentage-off sale across products, use price_adjust_pct (e.g. -10 for 10% off regular_price). For a specific sale amount, use sale_price with the actual target price.', 'pressark' ),
				);
			}
		}

		return null;
	}

	private function ambiguous_wc_price_write_response( array $data ): ?array {
		$issue = $this->detect_ambiguous_wc_price_write( $data );
		if ( null === $issue ) {
			return null;
		}

		return array(
			'success' => false,
			'error'   => (string) ( $issue['code'] ?? 'ambiguous_price_field' ),
			'message' => (string) ( $issue['message'] ?? __( 'Do not use plain price for WooCommerce writes.', 'pressark' ) ),
			'hint'    => (string) ( $issue['hint'] ?? '' ),
		);
	}

	private function normalize_wc_decimal_value( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$raw = trim( wp_strip_all_tags( $value ) );
		if ( '' === $raw ) {
			return '';
		}

		$clean = preg_replace( '/[^\d,\.\-]/u', '', $raw );
		if ( ! is_string( $clean ) || '' === $clean || '-' === $clean ) {
			return sanitize_text_field( $raw );
		}

		$last_dot   = strrpos( $clean, '.' );
		$last_comma = strrpos( $clean, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			if ( $last_dot > $last_comma ) {
				$clean = str_replace( ',', '', $clean );
			} else {
				$clean = str_replace( '.', '', $clean );
				$clean = str_replace( ',', '.', $clean );
			}
		} elseif ( false !== $last_comma && false === $last_dot ) {
			$comma_count = substr_count( $clean, ',' );
			$clean = 1 === $comma_count ? str_replace( ',', '.', $clean ) : str_replace( ',', '', $clean );
		} elseif ( substr_count( $clean, '.' ) > 1 ) {
			$parts    = explode( '.', $clean );
			$decimals = array_pop( $parts );
			$clean    = implode( '', $parts ) . '.' . $decimals;
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			$formatted = wc_format_decimal( $clean, wc_get_price_decimals() );
			if ( '' !== $formatted ) {
				return (string) $formatted;
			}
		}

		return sanitize_text_field( $clean );
	}


	// ── v3.7.0 Cache Flush Helpers ──────────────────────────────────

	/**
	 * Flush WC caches for a single product after edit.
	 * Ensures admin product list, REST API, and front-end product pages
	 * reflect the change immediately on sites with object cache + page cache.
	 *
	 * @since 3.7.0
	 * @param int $product_id Product ID.
	 */
	private function flush_wc_product_cache( int $product_id ): void {
		// WC internal transient cache for product data.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			try { wc_delete_product_transients( $product_id ); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// Clean the object cache for this product.
		try { clean_post_cache( $product_id ); } catch ( \Throwable $e ) { /* non-fatal */ }
	}

	/**
	 * Flush WC batch-level caches after a bulk operation.
	 * Called once after the entire batch instead of per-product for efficiency.
	 *
	 * Each call is isolated in try-catch — a broken cache drop-in must never
	 * prevent a successful product update from being reported as success.
	 *
	 * @since 3.7.0
	 */
	private function flush_wc_batch_caches(): void {
		// Flush WC transients (product counts, widget data, etc).
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			try { wc_delete_product_transients(); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// Flush layered nav / price filter transients.
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			try { wc_delete_shop_order_transients(); } catch ( \Throwable $e ) { /* non-fatal */ }
		}

		// Clear the product query cache if WC 8.0+ HPOS is active.
		// wp_cache_flush_group() requires WP 6.1+ and a drop-in that supports it.
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' )
			&& function_exists( 'wp_cache_supports' )
			&& wp_cache_supports( 'flush_group' )
		) {
			try { wp_cache_flush_group( 'woocommerce' ); } catch ( \Throwable $e ) { /* non-fatal */ }
		}
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	private function is_legacy_clear_sale_request( array $changes ): bool {
		return ! empty( $changes['clear_sale'] )
			|| ( array_key_exists( 'sale_price', $changes ) && '' === (string) $changes['sale_price'] );
	}

	private function normalize_wc_price_for_write( $price ): string {
		$normalized = wc_format_decimal( wc_clean( $price ) );
		if ( '' === $normalized ) {
			return '';
		}

		return wc_format_decimal( max( 0, (float) $normalized ) );
	}

	private function format_wc_sale_datetime( $sale_date ): string {
		if ( ! $sale_date || ! is_object( $sale_date ) || ! method_exists( $sale_date, 'getTimestamp' ) ) {
			return '';
		}

		return wp_date( DATE_ATOM, $sale_date->getTimestamp() );
	}

	private function format_wc_sale_timestamp( ?int $timestamp ): string {
		if ( empty( $timestamp ) ) {
			return '';
		}

		return wp_date( DATE_ATOM, $timestamp );
	}

	private function parse_wc_sale_timestamp( $value ) {
		if ( '' === (string) $value ) {
			return null;
		}

		$timestamp = wc_string_to_timestamp( sanitize_text_field( (string) $value ) );
		return $timestamp ? (int) $timestamp : false;
	}

	private function project_wc_on_sale( string $regular_price, string $sale_price, ?int $sale_from_ts, ?int $sale_to_ts ): bool {
		if ( '' === $sale_price || '' === $regular_price || (float) $regular_price <= (float) $sale_price ) {
			return false;
		}

		if ( null !== $sale_from_ts && $sale_from_ts > time() ) {
			return false;
		}

		if ( null !== $sale_to_ts && $sale_to_ts < time() ) {
			return false;
		}

		return true;
	}

	private function is_wc_price_affecting_change_set( array $changes ): bool {
		foreach ( array( 'regular_price', 'sale_price', 'clear_sale', 'sale_from', 'sale_to', 'price_delta', 'price_adjust_pct' ) as $field ) {
			if ( array_key_exists( $field, $changes ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_wc_product_pricing_state( $product ): array {
		$sale_from = $product->get_date_on_sale_from();
		$sale_to   = $product->get_date_on_sale_to();

		return array(
			'regular_price' => (string) $product->get_regular_price(),
			'sale_price'    => (string) $product->get_sale_price(),
			'price'         => (string) $product->get_price(),
			'on_sale'       => (bool) $product->is_on_sale(),
			'sale_from'     => $this->format_wc_sale_datetime( $sale_from ),
			'sale_to'       => $this->format_wc_sale_datetime( $sale_to ),
		);
	}

	private function get_wc_product_state_snapshot( $product ): array {
		$pricing_state = $this->get_wc_product_pricing_state( $product );

		$state = array(
			'id'             => (int) $product->get_id(),
			'name'           => $product->get_name(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'sku'            => $product->get_sku(),
			'regular_price'  => $pricing_state['regular_price'],
			'sale_price'     => $pricing_state['sale_price'],
			'price'          => $pricing_state['price'],
			'on_sale'        => $pricing_state['on_sale'],
			'sale_from'      => $pricing_state['sale_from'],
			'sale_to'        => $pricing_state['sale_to'],
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'manage_stock'   => $product->get_manage_stock(),
		);

		if ( $product->is_type( 'variable' ) ) {
			$state['variation_count'] = count( $product->get_children() );
		} elseif ( $product->is_type( 'grouped' ) ) {
			$state['child_count'] = count( $product->get_children() );
		}

		return $state;
	}

	private function project_wc_product_pricing_state( $product, array $changes ): array {
		$clear_sale      = $this->is_legacy_clear_sale_request( $changes );
		$regular_price   = (string) $product->get_regular_price();
		$sale_price      = (string) $product->get_sale_price();
		$current_price   = (string) $product->get_price();
		$current_sale_from = $product->get_date_on_sale_from();
		$current_sale_to   = $product->get_date_on_sale_to();
		$sale_from_ts      = $current_sale_from ? $current_sale_from->getTimestamp() : null;
		$sale_to_ts        = $current_sale_to ? $current_sale_to->getTimestamp() : null;

		if ( array_key_exists( 'regular_price', $changes ) ) {
			$regular_price = $this->normalize_wc_price_for_write( $changes['regular_price'] );
		} elseif ( isset( $changes['price_delta'] ) ) {
			$base_price = (float) $product->get_regular_price();
			if ( $base_price <= 0 ) {
				$base_price = (float) $product->get_price();
			}
			$regular_price = wc_format_decimal( max( 0, round( $base_price + (float) $changes['price_delta'], wc_get_price_decimals() ) ) );
		} elseif ( isset( $changes['price_adjust_pct'] ) ) {
			$base_price = (float) $product->get_regular_price();
			if ( $base_price <= 0 ) {
				$base_price = (float) $product->get_price();
			}
			if ( $base_price > 0 ) {
				$regular_price = wc_format_decimal( max( 0, round( $base_price * ( 1 + ( (float) $changes['price_adjust_pct'] / 100 ) ), wc_get_price_decimals() ) ) );
			}
		}

		if ( $clear_sale ) {
			$sale_price   = '';
			$sale_from_ts = null;
			$sale_to_ts   = null;
		} else {
			if ( array_key_exists( 'sale_price', $changes ) ) {
				$sale_price = $this->normalize_wc_price_for_write( $changes['sale_price'] );
			}
			if ( array_key_exists( 'sale_from', $changes ) ) {
				$sale_from_ts = $this->parse_wc_sale_timestamp( $changes['sale_from'] );
				$sale_from_ts = false === $sale_from_ts ? null : $sale_from_ts;
			}
			if ( array_key_exists( 'sale_to', $changes ) ) {
				$sale_to_ts = $this->parse_wc_sale_timestamp( $changes['sale_to'] );
				$sale_to_ts = false === $sale_to_ts ? null : $sale_to_ts;
			}
		}

		$on_sale = $this->project_wc_on_sale( $regular_price, $sale_price, $sale_from_ts, $sale_to_ts );
		$price   = $on_sale ? $sale_price : $regular_price;
		if ( '' === $price ) {
			$price = $current_price;
		}

		return array(
			'regular_price' => $regular_price,
			'sale_price'    => $sale_price,
			'price'         => $price,
			'on_sale'       => $on_sale,
			'sale_from'     => $this->format_wc_sale_timestamp( $sale_from_ts ),
			'sale_to'       => $this->format_wc_sale_timestamp( $sale_to_ts ),
		);
	}

	private function summarize_wc_product_pricing_state( array $pricing_state ): string {
		return sprintf(
			'regular_price=%1$s, sale_price=%2$s, price=%3$s, on_sale=%4$s, sale_from=%5$s, sale_to=%6$s',
			$this->preview_wc_pricing_value( $pricing_state['regular_price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['sale_price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['on_sale'] ?? false ),
			$this->preview_wc_pricing_value( $pricing_state['sale_from'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['sale_to'] ?? '' )
		);
	}

	private function preview_wc_pricing_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '(empty)';
		}

		$string = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		return '' !== $string ? $string : '(empty)';
	}

	private function preview_wc_sale_schedule_from_state( array $pricing_state ): string {
		$window    = array();
		$sale_from = (string) ( $pricing_state['sale_from'] ?? '' );
		$sale_to   = (string) ( $pricing_state['sale_to'] ?? '' );

		if ( '' !== $sale_from ) {
			$window[] = 'from ' . $sale_from;
		}
		if ( '' !== $sale_to ) {
			$window[] = 'to ' . $sale_to;
		}

		return ! empty( $window ) ? implode( ' ', $window ) : '(empty)';
	}

	private function summarize_wc_product_pricing_preview_state( array $pricing_state ): string {
		return sprintf(
			'regular_price=%1$s, sale_price=%2$s, price=%3$s, on_sale=%4$s, sale_schedule=%5$s',
			$this->preview_wc_pricing_value( $pricing_state['regular_price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['sale_price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['price'] ?? '' ),
			$this->preview_wc_pricing_value( $pricing_state['on_sale'] ?? false ),
			$this->preview_wc_sale_schedule_from_state( $pricing_state )
		);
	}

	private function append_wc_pricing_preview_summary( array &$preview_changes, array $before_pricing_state, array $after_pricing_state ): void {
		$preview_changes[] = array(
			'field'  => 'Customer-visible pricing',
			'before' => $this->summarize_wc_product_pricing_preview_state( $before_pricing_state ),
			'after'  => $this->summarize_wc_product_pricing_preview_state( $after_pricing_state ),
		);
	}

	private function format_sale_schedule_preview_value( $sale_from, $sale_to ): string {
		$window = array();
		if ( $sale_from ) {
			$window[] = 'from ' . wp_date( DATE_ATOM, $sale_from->getTimestamp() );
		}
		if ( $sale_to ) {
			$window[] = 'to ' . wp_date( DATE_ATOM, $sale_to->getTimestamp() );
		}

		return ! empty( $window ) ? implode( ' ', $window ) : '(empty)';
	}

	private function append_clear_sale_preview_changes( array &$preview_changes, $product ): void {
		$preview_changes[] = array(
			'field'  => 'Regular price (preserved)',
			'before' => '' !== $product->get_regular_price() ? $product->get_regular_price() : '(empty)',
			'after'  => '' !== $product->get_regular_price() ? $product->get_regular_price() : '(empty)',
		);
		$preview_changes[] = array(
			'field'  => 'Sale price',
			'before' => '' !== $product->get_sale_price() ? $product->get_sale_price() : '(empty)',
			'after'  => '(cleared)',
		);
		$preview_changes[] = array(
			'field'  => 'Sale schedule',
			'before' => $this->format_sale_schedule_preview_value( $product->get_date_on_sale_from(), $product->get_date_on_sale_to() ),
			'after'  => '(cleared)',
		);
	}

	/**
	 * Preview for edit_product.
	 */
	public function preview_edit_product( array $params, array $action ): array {
		$post_id = absint( $params['post_id'] ?? ( $action['post_id'] ?? 0 ) );
		$changes = $params['changes'] ?? ( $action['changes'] ?? array() );
		$changes = is_array( $changes ) ? $this->normalize_product_changes( $changes ) : array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->default_preview( 'edit_product', $params );
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return $this->default_preview( 'edit_product', $params );
		}

		$clear_sale = $this->is_legacy_clear_sale_request( $changes );
		$price_affecting = $this->is_wc_price_affecting_change_set( $changes );

		$preview = array(
			'post_title' => $product->get_name(),
			'post_id'    => $post_id,
			'changes'    => array(),
		);
		$before_pricing_state = $price_affecting ? $this->get_wc_product_pricing_state( $product ) : array();
		$after_pricing_state  = $price_affecting ? $this->project_wc_product_pricing_state( $product, $changes ) : array();

		$field_map = array(
			'name'              => 'get_name',
			'description'       => 'get_description',
			'short_description' => 'get_short_description',
			'regular_price'     => 'get_regular_price',
			'sale_price'        => 'get_sale_price',
		);

		if ( $price_affecting ) {
			$preview['pricing_state'] = array(
				'before' => $before_pricing_state,
				'after'  => $after_pricing_state,
			);
			$this->append_wc_pricing_preview_summary( $preview['changes'], $before_pricing_state, $after_pricing_state );
		}

		if ( $clear_sale ) {
			$this->append_clear_sale_preview_changes( $preview['changes'], $product );
		}

		foreach ( $changes as $key => $value ) {
			if ( 'clear_sale' === $key ) {
				continue;
			}

			if ( 'price_delta' === $key || 'price_adjust_pct' === $key ) {
				$preview['changes'][] = array(
					'field'  => 'Regular price',
					'before' => $this->preview_wc_pricing_value( $product->get_regular_price() ),
					'after'  => $this->preview_wc_pricing_value( $after_pricing_state['regular_price'] ?? '' ),
				);
				continue;
			}

			if ( in_array( $key, array( 'sale_from', 'sale_to' ), true ) ) {
				continue;
			}

			if ( $clear_sale && 'sale_price' === $key ) {
				continue;
			}

			$getter  = $field_map[ $key ] ?? null;
			$current = $getter ? $product->$getter() : '';
			if ( in_array( $key, array( 'description', 'short_description' ), true ) ) {
				$current = mb_substr( wp_strip_all_tags( $current ), 0, 150 ) . ( mb_strlen( wp_strip_all_tags( $current ) ) > 150 ? '...' : '' );
				$value   = mb_substr( wp_strip_all_tags( $value ), 0, 150 ) . ( mb_strlen( wp_strip_all_tags( $value ) ) > 150 ? '...' : '' );
			}
			$preview['changes'][] = array(
				'field'  => ucfirst( str_replace( '_', ' ', $key ) ),
				'before' => $current ?: '(empty)',
				'after'  => $value,
			);
		}

		return $preview;
	}

	public function preview_bulk_edit_products( array $params, array $action ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->default_preview( 'bulk_edit_products', $params );
		}

		$resolution = $this->resolve_bulk_product_updates( $params );
		$products   = is_array( $resolution['products'] ?? null ) ? $resolution['products'] : array();
		if ( empty( $products ) ) {
			return array(
				'changes' => array(
					array(
						'field'  => __( 'Bulk product update', 'pressark' ),
						'before' => __( 'No matching products', 'pressark' ),
						'after'  => (string) ( $resolution['message'] ?? __( 'No changes resolved.', 'pressark' ) ),
					),
				),
			);
		}

		$scope_label       = $this->describe_wc_bulk_product_scope( $resolution, count( $products ) );
		$scope_description = $this->describe_wc_bulk_product_scope_sentence( $resolution, count( $products ), $scope_label );

		$preview = array(
			'title'   => __( 'Bulk product update', 'pressark' ),
			/* translators: 1: number of matched products 2: scope label */
			'summary' => sprintf( __( '%1$d %2$s will be updated.', 'pressark' ), count( $products ), $scope_label ),
			'scope_label' => $scope_label,
			'scope_description' => $scope_description,
			'changes' => array(),
		);
		if ( ! empty( $resolution['matched_product_types'] ) ) {
			$preview['matched_product_types'] = (array) $resolution['matched_product_types'];
		}

		foreach ( array_slice( $products, 0, 5 ) as $product_update ) {
			$product = wc_get_product( absint( $product_update['post_id'] ?? 0 ) );
			if ( ! $product ) {
				continue;
			}
			$product_context = $this->get_wc_bulk_product_target_context( $product, (array) ( $product_update['changes'] ?? array() ) );
			$product_label   = sprintf(
				/* translators: 1: WooCommerce product ID, 2: WooCommerce product type slug. */
				__( 'Product #%1$d (%2$s)', 'pressark' ),
				$product->get_id(),
				$product_context['type'] ?? $product->get_type()
			);

			$item_preview = $this->preview_edit_product(
				array(
					'post_id'  => $product->get_id(),
					'changes'  => $product_update['changes'] ?? array(),
				),
				$action
			);

			$preview['changes'][] = array(
				'field'  => $product_label,
				'before' => $product->get_name(),
				'after'  => $product->get_name(),
			);
			if ( isset( $product_context['variation_count'] ) ) {
				$preview['changes'][] = array(
					'field'  => __( 'Variation count', 'pressark' ),
					'before' => (int) $product_context['variation_count'],
					'after'  => (int) $product_context['variation_count'],
				);
			}
			if ( isset( $product_context['child_count'] ) ) {
				$preview['changes'][] = array(
					'field'  => __( 'Grouped child count', 'pressark' ),
					'before' => (int) $product_context['child_count'],
					'after'  => (int) $product_context['child_count'],
				);
			}
			foreach ( (array) ( $product_context['warnings'] ?? array() ) as $warning ) {
				$preview['changes'][] = array(
					'field'  => __( 'Pricing scope warning', 'pressark' ),
					'before' => __( 'No special product-type warning', 'pressark' ),
					'after'  => $warning,
				);
			}

			foreach ( (array) ( $item_preview['changes'] ?? array() ) as $change ) {
				if ( ! is_array( $change ) ) {
					continue;
				}
				$preview['changes'][] = $change;
			}
		}

		$preview_notes = array();
		if ( '' !== $scope_description ) {
			$preview_notes[] = $scope_description;
		}
		if ( ! empty( $resolution['warnings'] ) ) {
			$preview_notes[] = implode( ' ', array_map( 'strval', (array) $resolution['warnings'] ) );
		}
		if ( ! empty( $resolution['truncated'] ) ) {
			$preview_notes[] = __( 'Only the first batch of matching products is shown in this preview.', 'pressark' );
		}
		if ( ! empty( $preview_notes ) ) {
			$preview['note'] = implode( ' ', array_values( array_unique( $preview_notes ) ) );
		}

		return $preview;
	}

	/**
	 * Preview for bulk_edit_variations.
	 */
	public function preview_bulk_edit_variations( array $params, array $action ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->default_preview( 'bulk_edit_variations', $params );
		}

		$product_id = absint( $params['product_id'] ?? ( $action['product_id'] ?? 0 ) );
		$parent     = wc_get_product( $product_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return $this->default_preview( 'bulk_edit_variations', $params );
		}

		$children = $parent->get_children();
		if ( empty( $children ) ) {
			return array(
				'changes' => array(
					array(
						'field'  => __( 'Bulk variation update', 'pressark' ),
						'before' => __( 'No matching variations', 'pressark' ),
						'after'  => __( 'No changes resolved.', 'pressark' ),
					),
				),
			);
		}

		$changes = $params['changes'] ?? ( $action['changes'] ?? array() );
		$changes = is_array( $changes ) ? $this->normalize_variation_changes( $changes ) : array();
		$preview = array(
			'title'   => __( 'Bulk variation update', 'pressark' ),
			/* translators: %1$d: number of variations that will be updated. */
			'summary' => sprintf( __( '%1$d variation(s) will be updated.', 'pressark' ), count( $children ) ),
			'changes' => array(),
		);

		foreach ( array_slice( $children, 0, 5 ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$item_preview = $this->preview_edit_variation(
				array_merge(
					array(
						'variation_id' => (int) $variation_id,
					),
					$changes
				),
				array(
					'variation_id' => (int) $variation_id,
				)
			);

			$preview['changes'][] = array(
				/* translators: %d: WooCommerce variation ID. */
				'field'  => sprintf( __( 'Variation #%d', 'pressark' ), $variation->get_id() ),
				'before' => $variation->get_name(),
				'after'  => $variation->get_name(),
			);

			foreach ( (array) ( $item_preview['changes'] ?? array() ) as $change ) {
				if ( ! is_array( $change ) ) {
					continue;
				}
				$preview['changes'][] = $change;
			}
		}

		if ( count( $children ) > 5 ) {
			$preview['note'] = __( 'Only the first 5 variations are shown in this preview.', 'pressark' );
		}

		return $preview;
	}

	/**
	 * Preview for update_order.
	 */
	public function preview_update_order( array $params, array $action ): array {
		$changes = array();
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' ) ) {
			$prev_order = wc_get_order( absint( $params['order_id'] ?? 0 ) );
			if ( $prev_order ) {
				if ( ! empty( $params['status'] ) ) {
					$changes[] = array(
						/* translators: %s: WooCommerce order number. */
						'field'  => sprintf( __( 'Order #%s Status', 'pressark' ), $prev_order->get_order_number() ),
						'before' => $prev_order->get_status(),
						'after'  => $params['status'],
					);
				}
				if ( ! empty( $params['note'] ) ) {
					$changes[] = array(
						'field'  => __( 'Order Note', 'pressark' ),
						'before' => '—',
						'after'  => mb_substr( $params['note'], 0, 150 ),
					);
				}
			}
		}
		return array( 'changes' => $changes );
	}

	/**
	 * Preview for manage_coupon.
	 */
	public function preview_manage_coupon( array $params, array $action ): array {
		$coupon_op = $params['operation'] ?? '';
		$changes   = array(
			array(
				'field'  => ucfirst( $coupon_op ) . ' Coupon',
				'before' => 'delete' === $coupon_op ? ( '#' . ( $params['coupon_id'] ?? '?' ) ) : '—',
				'after'  => 'delete' === $coupon_op ? __( 'Deleted', 'pressark' ) : ( $params['code'] ?? __( 'Coupon', 'pressark' ) ) . ( ! empty( $params['amount'] ) ? ' (' . $params['amount'] . ( 'percent' === ( $params['discount_type'] ?? 'percent' ) ? '%' : '' ) . ' off)' : '' ),
			),
		);
		$text_map  = array(
			'minimum_amount'       => __( 'Minimum spend', 'pressark' ),
			'maximum_amount'       => __( 'Maximum spend', 'pressark' ),
			'usage_limit'          => __( 'Usage limit', 'pressark' ),
			'usage_limit_per_user' => __( 'Per-user limit', 'pressark' ),
		);
		$bool_map  = array(
			'individual_use'     => __( 'Individual use only', 'pressark' ),
			'free_shipping'      => __( 'Free shipping', 'pressark' ),
			'exclude_sale_items' => __( 'Exclude sale items', 'pressark' ),
		);
		$list_map  = array(
			'product_ids'                 => __( 'Allowed products', 'pressark' ),
			'excluded_product_ids'        => __( 'Excluded products', 'pressark' ),
			'product_categories'          => __( 'Allowed categories', 'pressark' ),
			'excluded_product_categories' => __( 'Excluded categories', 'pressark' ),
			'email_restrictions'          => __( 'Email restrictions', 'pressark' ),
		);

		if ( in_array( $coupon_op, array( 'create', 'edit' ), true ) ) {
			foreach ( $text_map as $field => $label ) {
				if ( array_key_exists( $field, $params ) ) {
					$changes[] = array(
						'field'  => $label,
						'before' => '—',
						'after'  => (string) $params[ $field ],
					);
				}
			}

			foreach ( $bool_map as $field => $label ) {
				if ( array_key_exists( $field, $params ) ) {
					$changes[] = array(
						'field'  => $label,
						'before' => '—',
						'after'  => $this->coupon_boolean_value( $params[ $field ] ) ? 'true' : 'false',
					);
				}
			}

			foreach ( $list_map as $field => $label ) {
				if ( ! array_key_exists( $field, $params ) ) {
					continue;
				}

				$value = 'email_restrictions' === $field
					? $this->coupon_normalize_string_list( $params[ $field ] )
					: $this->coupon_normalize_id_list( $params[ $field ] );

				$changes[] = array(
					'field'  => $label,
					'before' => '—',
					'after'  => empty( $value ) ? '[]' : implode( ', ', array_map( 'strval', $value ) ),
				);
			}
		}

		return array( 'changes' => $changes );
	}

	/**
	 * Preview for email_customer.
	 */
	public function preview_email_customer( array $params, array $action ): array {
		$cust_id   = intval( $params['customer_id'] ?? 0 );
		$cust_user = get_userdata( $cust_id );
		return array(
			'post_title' => 'Email Customer',
			'post_id'    => 0,
			'changes'    => array(
				array( 'field' => __( 'To', 'pressark' ), 'before' => '—', 'after' => $cust_user ? $cust_user->display_name . ' (' . $cust_user->user_email . ')' : '#' . $cust_id ),
				array( 'field' => __( 'Subject', 'pressark' ), 'before' => '—', 'after' => $params['subject'] ?? '' ),
				array( 'field' => __( 'Body Preview', 'pressark' ), 'before' => '—', 'after' => mb_substr( wp_strip_all_tags( $params['body'] ?? '' ), 0, 200 ) . '...' ),
			),
		);
	}

	/**
	 * Preview for moderate_review.
	 */
	public function preview_moderate_review( array $params, array $action ): array {
		$rev_id     = intval( $params['review_id'] ?? 0 );
		$rev        = get_comment( $rev_id );
		$rev_action = $params['action'] ?? '';

		if ( 'reply' === $rev_action ) {
			return array(
				'changes' => array(
					array(
						'field'  => __( 'Reply to Review', 'pressark' ),
						'before' => $rev ? sprintf(
							/* translators: 1: review author name, 2: review excerpt. */
							__( 'By %1$s: "%2$s"', 'pressark' ),
							$rev->comment_author,
							mb_substr( wp_strip_all_tags( $rev->comment_content ), 0, 80 )
						) : '#' . $rev_id,
						'after'  => mb_substr( $params['reply_content'] ?? '', 0, 150 ),
					),
				),
			);
		}

		return array(
			'changes' => array(
				array(
					'field'  => ucfirst( $rev_action ) . ' Review',
					'before' => $rev ? sprintf(
						/* translators: %s: review author name. */
						__( 'By %s', 'pressark' ),
						$rev->comment_author
					) : '#' . $rev_id,
					'after'  => ucfirst( $rev_action ),
				),
			),
		);
	}

	/**
	 * Preview for reply_review.
	 */
	public function preview_reply_review( array $params, array $action ): array {
		if ( empty( $params['reply_content'] ) && ! empty( $params['content'] ) ) {
			$params['reply_content'] = $params['content'];
		}

		$params['action'] = 'reply';

		return $this->preview_moderate_review( $params, $action );
	}

	/**
	 * Preview for bulk_reply_reviews.
	 */
	public function preview_bulk_reply_reviews( array $params, array $action ): array {
		$review_replies = $params['reviews'] ?? ( $action['reviews'] ?? array() );
		if ( ! is_array( $review_replies ) ) {
			$review_replies = array();
		}

		$changes = array();

		foreach ( array_slice( $review_replies, 0, 10 ) as $i => $review_reply ) {
			if ( ! is_array( $review_reply ) ) {
				$changes[] = array(
					/* translators: %d: sequential review reply number in the preview. */
					'field'  => sprintf( __( 'Review Reply #%d', 'pressark' ), $i + 1 ),
					'before' => __( 'Invalid review payload', 'pressark' ),
					'after'  => __( 'Skipped until corrected', 'pressark' ),
				);
				continue;
			}

			$review_id      = intval( $review_reply['review_id'] ?? 0 );
			$review         = get_comment( $review_id );
			$reply_content  = $review_reply['reply_content'] ?? ( $review_reply['content'] ?? '' );
			$review_excerpt = $review
				? sprintf(
					/* translators: 1: review author name, 2: review excerpt. */
					__( 'By %1$s: "%2$s"', 'pressark' ),
					$review->comment_author,
					mb_substr( wp_strip_all_tags( $review->comment_content ), 0, 80 )
				)
				: '#' . ( $review_id ? $review_id : ( $i + 1 ) );

			$changes[] = array(
				/* translators: %d: WooCommerce review ID or reply sequence number. */
				'field'  => sprintf( __( 'Reply to Review #%d', 'pressark' ), $review_id ? $review_id : ( $i + 1 ) ),
				'before' => $review_excerpt,
				'after'  => mb_substr( $reply_content, 0, 150 ),
			);
		}

		if ( count( $review_replies ) > 10 ) {
			$changes[] = array(
				'field'  => __( 'Additional Replies', 'pressark' ),
				'before' => __( 'More reviews selected', 'pressark' ),
				/* translators: %d: number of additional review replies not shown in the preview. */
				'after'  => sprintf( __( '+%d more review replies', 'pressark' ), count( $review_replies ) - 10 ),
			);
		}

		return array( 'changes' => $changes );
	}

	/**
	 * Preview for edit_variation.
	 */
	public function preview_edit_variation( array $params, array $action ): array {
		$params = $this->normalize_variation_params( $params );
		$action = $this->normalize_variation_params( $action );
		$ev_id  = intval( $params['variation_id'] ?? ( $action['variation_id'] ?? 0 ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->default_preview( 'edit_variation', $params );
		}

		$variation = wc_get_product( $ev_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return $this->default_preview( 'edit_variation', $params );
		}

		$changes = array();
		foreach ( array( 'regular_price', 'sale_price', 'clear_sale', 'price_delta', 'price_adjust_pct', 'stock_quantity', 'stock_status', 'status' ) as $field ) {
			if ( array_key_exists( $field, $action ) ) {
				$changes[ $field ] = $action[ $field ];
			}
			if ( array_key_exists( $field, $params ) ) {
				$changes[ $field ] = $params[ $field ];
			}
		}

		$clear_sale = $this->is_legacy_clear_sale_request( $changes );
		$preview    = array(
			'post_title' => $variation->get_name(),
			'post_id'    => $ev_id,
			'changes'    => array(),
		);
		$field_map  = array(
			'regular_price'  => 'get_regular_price',
			'sale_price'     => 'get_sale_price',
			'stock_quantity' => 'get_stock_quantity',
			'stock_status'   => 'get_stock_status',
			'status'         => 'get_status',
		);

		if ( $clear_sale ) {
			$this->append_clear_sale_preview_changes( $preview['changes'], $variation );
		}

		foreach ( $changes as $key => $value ) {
			if ( 'clear_sale' === $key ) {
				continue;
			}

			if ( 'price_delta' === $key || 'price_adjust_pct' === $key ) {
				$current_price = (float) $variation->get_regular_price();
				if ( $current_price <= 0 ) {
					$current_price = (float) $variation->get_price();
				}
				$after_price = 'price_delta' === $key
					? round( $current_price + (float) $value, wc_get_price_decimals() )
					: round( $current_price * ( 1 + ( (float) $value / 100 ) ), wc_get_price_decimals() );

				$preview['changes'][] = array(
					'field'  => 'Regular price',
					'before' => '' !== $variation->get_regular_price() ? $variation->get_regular_price() : '(empty)',
					'after'  => (string) $after_price,
				);
				continue;
			}

			if ( $clear_sale && 'sale_price' === $key ) {
				continue;
			}

			$getter  = $field_map[ $key ] ?? null;
			$current = $getter ? $variation->$getter() : '';
			$preview['changes'][] = array(
				'field'  => ucfirst( str_replace( '_', ' ', $key ) ),
				'before' => ( null !== $current && '' !== (string) $current ) ? (string) $current : '(empty)',
				'after'  => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value,
			);
		}

		return $preview;
	}

	/**
	 * Preview for create_refund.
	 */
	public function preview_create_refund( array $params, array $action ): array {
		$ref_oid = intval( $params['order_id'] ?? ( $action['order_id'] ?? 0 ) );
		$ref_amt = $params['amount'] ?? ( $action['amount'] ?? 'Full' );
		return array(
			'changes' => array(
				array(
					/* translators: %s: WooCommerce order number or ID. */
					'field'  => sprintf( __( 'Refund Order #%s', 'pressark' ), $ref_oid ),
					'before' => __( 'Order total', 'pressark' ),
					/* translators: %s: refund amount. */
					'after'  => sprintf( __( 'Refund: %s', 'pressark' ), $ref_amt ),
				),
			),
		);
	}

	/**
	 * Preview for create_order.
	 */
	public function preview_create_order( array $params, array $action ): array {
		$co_email = $params['customer_email'] ?? ( $action['customer_email'] ?? '' );
		$co_items = $params['items'] ?? ( $action['items'] ?? array() );
		return array(
			'changes' => array(
				array(
					'field'  => __( 'New Manual Order', 'pressark' ),
					'before' => __( '(does not exist)', 'pressark' ),
					'after'  => $co_email . ' — ' . count( $co_items ) . ' item(s)',
				),
			),
		);
	}
}
