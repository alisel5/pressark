<?php
/**
 * WooCommerce event listeners and rolling event log.
 *
 * @since 4.3.0 Extracted from pressark.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_WC_Events {

	/**
	 * Register WC event listeners (only when WC is active).
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'woocommerce_low_stock', array( self::class, 'handle_low_stock' ) );
		add_action( 'woocommerce_no_stock', array( self::class, 'handle_no_stock' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'handle_order_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'handle_refund' ), 10, 2 );
		add_action( 'comment_post', array( self::class, 'handle_new_comment' ), 20, 2 );
		add_action( 'transition_comment_status', array( self::class, 'handle_comment_approved' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'handle_high_value_order' ), 10, 3 );
	}

	public static function handle_low_stock( $product ): void {
		$data = array(
			'name'      => sanitize_text_field( $product->get_name() ),
			'stock'     => (int) $product->get_stock_quantity(),
			'threshold' => (int) ( $product->get_low_stock_amount() ?: get_option( 'woocommerce_notify_low_stock_amount', 2 ) ),
		);
		self::log_event( 'low_stock', $product->get_id(), $data );
	}

	public static function handle_no_stock( $product ): void {
		$data = array(
			'name' => sanitize_text_field( $product->get_name() ),
		);
		self::log_event( 'out_of_stock', $product->get_id(), $data );
	}

	public static function handle_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$type = 'order_' . $new_status;
		$data = array(
			'number'     => sanitize_text_field( $order->get_order_number() ),
			'total'      => (float) $order->get_total(),
			'customer'   => sanitize_email( $order->get_billing_email() ),
			'old_status' => sanitize_text_field( $old_status ),
			'customer_ip' => sanitize_text_field( $order->get_customer_ip_address() ),
		);

		// Enrich failed orders with payment diagnostics.
		if ( 'failed' === $new_status ) {
			$data['payment_method'] = sanitize_text_field( $order->get_payment_method_title() );
			$data['gateway_error']  = self::extract_gateway_error( $order );
		}

		self::log_event( $type, $order_id, $data );
	}

	/**
	 * Extract the payment gateway error from order notes.
	 *
	 * WooCommerce payment gateways typically add an order note when payment fails.
	 * This extracts the most recent system note that mentions payment/declined/failed.
	 *
	 * @param WC_Order $order The order object.
	 * @return string The gateway error message, or empty string.
	 */
	private static function extract_gateway_error( $order ): string {
		$notes = wc_get_order_notes( array(
			'order_id' => $order->get_id(),
			'type'     => 'internal',
			'limit'    => 5,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		) );

		foreach ( $notes as $note ) {
			if ( preg_match( '/(?:declined|failed|error|refused|insufficient|expired|fraud|velocity|authentication|3ds|blocked)/i', $note->content ) ) {
				return sanitize_text_field( mb_substr( wp_strip_all_tags( $note->content ), 0, 200 ) );
			}
		}

		return '';
	}

	/**
	 * Handle refund issued.
	 *
	 * @param int $order_id  The order ID.
	 * @param int $refund_id The refund ID.
	 */
	public static function handle_refund( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		$data = array(
			'number'   => sanitize_text_field( $order->get_order_number() ),
			'amount'   => (float) abs( $refund->get_total() ),
			'reason'   => sanitize_text_field( $refund->get_reason() ),
			'customer' => sanitize_email( $order->get_billing_email() ),
		);
		self::log_event( 'refund_issued', $order_id, $data );
	}

	/**
	 * Handle new comment — log negative product reviews (rating <= 2).
	 *
	 * @param int    $comment_id       The comment ID.
	 * @param string $comment_approved Approval status.
	 */
	public static function handle_new_comment( int $comment_id, string $comment_approved ): void {
		// Only process approved comments.
		if ( '1' !== (string) $comment_approved && 'approve' !== $comment_approved ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		// Only process product reviews.
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		$rating = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( $rating < 1 || $rating > 2 ) {
			return;
		}

		$excerpt = sanitize_text_field( mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 100 ) );
		$data    = array(
			'product_name' => sanitize_text_field( $post->post_title ),
			'product_id'   => $post->ID,
			'rating'       => $rating,
			'excerpt'      => $excerpt,
			'reviewer'     => sanitize_text_field( $comment->comment_author ),
			'review_id'    => $comment_id,
		);
		self::log_event( 'negative_review', $comment_id, $data );
	}

	/**
	 * Handle comment status transition — catches reviews approved after moderation.
	 *
	 * The `comment_post` hook only fires on initial creation. Reviews that
	 * require moderation arrive with $comment_approved = 0 and are skipped.
	 * This handler catches the approval transition so negative reviews
	 * are detected regardless of moderation workflow.
	 *
	 * @param string      $new_status New comment status ('approved', 'unapproved', 'spam', 'trash').
	 * @param string      $old_status Previous comment status.
	 * @param \WP_Comment $comment    The comment object.
	 */
	public static function handle_comment_approved( string $new_status, string $old_status, $comment ): void {
		// Only process transitions TO approved from a non-approved state.
		if ( 'approved' !== $new_status || 'approved' === $old_status ) {
			return;
		}

		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$rating     = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( $rating < 1 || $rating > 2 ) {
			return;
		}

		$excerpt = sanitize_text_field( mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 100 ) );
		$data    = array(
			'product_name' => sanitize_text_field( $post->post_title ),
			'product_id'   => $post->ID,
			'rating'       => $rating,
			'excerpt'      => $excerpt,
			'reviewer'     => sanitize_text_field( $comment->comment_author ),
			'review_id'    => $comment_id,
		);
		self::log_event( 'negative_review', $comment_id, $data );
	}

	/**
	 * Handle high-value orders — triggered on status change to processing/completed
	 * so all order data (totals, billing, items) is fully populated.
	 *
	 * We log all orders >= $500 so the event is available for any user
	 * whose threshold qualifies. The alerter checks per-user thresholds.
	 *
	 * @param int    $order_id   The order ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 */
	public static function handle_high_value_order( int $order_id, string $old_status, string $new_status ): void {
		// Only trigger on first transition to processing or completed.
		if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		// Avoid duplicate alerts: only fire when coming from an initial status.
		if ( in_array( $old_status, array( 'processing', 'completed' ), true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$total = (float) $order->get_total();
		if ( $total < 500 ) {
			return;
		}

		$data = array(
			'number'      => sanitize_text_field( $order->get_order_number() ),
			'total'       => $total,
			'customer'    => sanitize_email( $order->get_billing_email() ),
			'items_count' => $order->get_item_count(),
		);
		self::log_event( 'high_value_order', $order_id, $data );
	}

	/**
	 * Log a WC event for proactive surfacing.
	 * Uses wp_options with a rolling 50-event buffer per event type.
	 */
	public static function log_event( string $type, int $object_id, array $data ): void {
		$option_key = 'pressark_events_' . $type;
		$events     = get_option( $option_key, array() );

		array_unshift( $events, array(
			'object_id' => $object_id,
			'data'      => $data,
			'time'      => time(),
			'read'      => false,
		) );

		$events = array_slice( $events, 0, 50 );
		update_option( $option_key, $events, false );
	}

	/**
	 * Get WC events across all types, with optional read-state filter.
	 *
	 * @param int  $limit        Maximum events to return.
	 * @param bool $include_read Whether to include already-read events.
	 * @return array Events sorted newest first, each with 'type' key.
	 */
	public static function get_events( int $limit = 20, bool $include_read = true ): array {
		$event_types = array( 'low_stock', 'out_of_stock', 'order_failed', 'order_cancelled', 'refund_issued', 'negative_review', 'high_value_order' );
		$all_events  = array();

		foreach ( $event_types as $type ) {
			$events = get_option( 'pressark_events_' . $type, array() );
			foreach ( $events as $event ) {
				if ( $include_read || ! $event['read'] ) {
					$all_events[] = array_merge( $event, array( 'type' => $type ) );
				}
			}
		}

		usort( $all_events, fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return array_slice( $all_events, 0, $limit );
	}

	/**
	 * Get unread WC events for context injection.
	 */
	public static function get_unread_events( int $limit = 5 ): array {
		$event_types = array( 'low_stock', 'out_of_stock', 'order_failed', 'order_cancelled', 'refund_issued', 'negative_review', 'high_value_order' );
		$all_events  = array();

		foreach ( $event_types as $type ) {
			$events = get_option( 'pressark_events_' . $type, array() );
			foreach ( $events as $event ) {
				if ( ! $event['read'] ) {
					$all_events[] = array_merge( $event, array( 'type' => $type ) );
				}
			}
		}

		usort( $all_events, fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return array_slice( $all_events, 0, $limit );
	}

	/**
	 * Mark all WC events as read (call after surfacing them to user).
	 */
	public static function mark_all_read(): void {
		foreach ( array( 'low_stock', 'out_of_stock', 'order_failed', 'order_cancelled', 'refund_issued', 'negative_review', 'high_value_order' ) as $type ) {
			$events = get_option( 'pressark_events_' . $type, array() );
			foreach ( $events as &$e ) {
				$e['read'] = true;
			}
			update_option( 'pressark_events_' . $type, $events, false );
		}
	}
}
