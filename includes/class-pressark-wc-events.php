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
	}

	public static function handle_low_stock( $product ): void {
		self::log_event( 'low_stock', $product->get_id(), array(
			'name'      => $product->get_name(),
			'stock'     => $product->get_stock_quantity(),
			'threshold' => $product->get_low_stock_amount() ?: get_option( 'woocommerce_notify_low_stock_amount', 2 ),
		) );
	}

	public static function handle_no_stock( $product ): void {
		self::log_event( 'out_of_stock', $product->get_id(), array(
			'name' => $product->get_name(),
		) );
	}

	public static function handle_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		if ( ! in_array( $new_status, array( 'failed', 'cancelled' ), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::log_event( 'order_' . $new_status, $order_id, array(
			'number'     => $order->get_order_number(),
			'total'      => (float) $order->get_total(),
			'customer'   => $order->get_billing_email(),
			'old_status' => $old_status,
		) );
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
	 * Get unread WC events for context injection.
	 */
	public static function get_unread_events( int $limit = 5 ): array {
		$event_types = array( 'low_stock', 'out_of_stock', 'order_failed', 'order_cancelled' );
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
		foreach ( array( 'low_stock', 'out_of_stock', 'order_failed', 'order_cancelled' ) as $type ) {
			$events = get_option( 'pressark_events_' . $type, array() );
			foreach ( $events as &$e ) {
				$e['read'] = true;
			}
			update_option( 'pressark_events_' . $type, $events, false );
		}
	}
}
