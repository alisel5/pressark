<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured error logging with rolling buffer and Sentry integration hook.
 *
 * Replaces bare error_log() calls with structured context:
 * [PressArk] [{severity}] [{component}] {message} {json_context}
 *
 * @since 4.2.0
 */
class PressArk_Error_Tracker {

	/** @var string Option key for the rolling error buffer. */
	const OPTION_KEY = 'pressark_error_buffer';

	/** @var int Maximum number of errors stored in the buffer. */
	const BUFFER_SIZE = 50;

	/** @var string[] Severity levels in ascending order. */
	const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/** @var int Index of 'warning' — the threshold for the filter hook. */
	const FILTER_THRESHOLD = 2; // 'warning'

	/**
	 * Log a structured error message.
	 *
	 * @param string $severity  One of: debug, info, warning, error, critical.
	 * @param string $component Short identifier for the subsystem (e.g. 'Chat', 'Agent').
	 * @param string $message   Human-readable description.
	 * @param array  $context   Optional key-value pairs for structured data.
	 */
	public static function log( string $severity, string $component, string $message, array $context = array() ): void {
		$severity = in_array( $severity, self::LEVELS, true ) ? $severity : 'error';
		$trace_context = class_exists( 'PressArk_Activity_Trace' )
			? PressArk_Activity_Trace::current_context()
			: array();

		foreach ( array( 'correlation_id', 'run_id', 'task_id', 'reservation_id', 'route' ) as $key ) {
			if ( empty( $context[ $key ] ) && ! empty( $trace_context[ $key ] ) ) {
				$context[ $key ] = $trace_context[ $key ];
			}
		}

		// Build the formatted log line.
		$log_line = sprintf(
			'[PressArk] [%s] [%s] %s',
			$severity,
			$component,
			$message
		);

		if ( ! empty( $context ) ) {
			$log_line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		$level_index = array_search( $severity, self::LEVELS, true );
		if ( false === $level_index ) {
			$level_index = self::FILTER_THRESHOLD;
		}

		if ( self::should_write_to_php_error_log( $level_index ) ) {
			error_log( $log_line );
		}

		// Store warning+ in rolling buffer for the diagnostics page.
		if ( $level_index >= self::FILTER_THRESHOLD ) {
			self::buffer_error( $severity, $component, $message, $context );
		}

		// Fire filter for warning+ severity — Sentry integration hook.
		if ( $level_index >= self::FILTER_THRESHOLD ) {
			$error_data = array(
				'severity'  => $severity,
				'component' => $component,
				'message'   => $message,
				'context'   => $context,
				'timestamp' => current_time( 'mysql', true ),
			);

			/**
			 * Fires when PressArk logs a warning or higher severity error.
			 *
			 * Integration hook for external error tracking (e.g. Sentry):
			 *
			 *     add_filter( 'pressark_error_logged', function( $error ) {
			 *         \Sentry\captureMessage( $error['message'], \Sentry\Severity::fromError( $error['severity'] ) );
			 *         return $error;
			 *     } );
			 *
			 * @since 4.2.0
			 *
			 * @param array $error_data {
			 *     @type string $severity  Severity level.
			 *     @type string $component Subsystem identifier.
			 *     @type string $message   Error description.
			 *     @type array  $context   Structured context data.
			 *     @type string $timestamp UTC timestamp.
			 * }
			 */
			apply_filters( 'pressark_error_logged', $error_data );
		}
	}

	// ── Convenience methods ──────────────────────────────────────────

	public static function debug( string $component, string $message, array $context = array() ): void {
		self::log( 'debug', $component, $message, $context );
	}

	public static function info( string $component, string $message, array $context = array() ): void {
		self::log( 'info', $component, $message, $context );
	}

	public static function warning( string $component, string $message, array $context = array() ): void {
		self::log( 'warning', $component, $message, $context );
	}

	public static function error( string $component, string $message, array $context = array() ): void {
		self::log( 'error', $component, $message, $context );
	}

	public static function critical( string $component, string $message, array $context = array() ): void {
		self::log( 'critical', $component, $message, $context );
	}

	// ── Rolling buffer ───────────────────────────────────────────────

	/**
	 * Store an error in the rolling wp_option buffer.
	 */
	private static function buffer_error( string $severity, string $component, string $message, array $context ): void {
		$buffer = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		$buffer[] = array(
			'severity'  => $severity,
			'component' => $component,
			'message'   => $message,
			'context'   => $context,
			'timestamp' => current_time( 'mysql', true ),
		);

		// Keep only the last BUFFER_SIZE entries.
		if ( count( $buffer ) > self::BUFFER_SIZE ) {
			$buffer = array_slice( $buffer, -self::BUFFER_SIZE );
		}

		update_option( self::OPTION_KEY, $buffer, false );
	}

	/**
	 * Get the buffered errors (most recent last).
	 *
	 * @param int $limit Max entries to return. 0 = all.
	 * @return array
	 */
	public static function get_recent( int $limit = 0 ): array {
		$buffer = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $buffer ) ) {
			return array();
		}

		if ( $limit > 0 ) {
			return array_slice( $buffer, -$limit );
		}

		return $buffer;
	}

	/**
	 * Get the count of buffered errors.
	 */
	public static function count(): int {
		$buffer = get_option( self::OPTION_KEY, array() );
		return is_array( $buffer ) ? count( $buffer ) : 0;
	}

	/**
	 * Clear the error buffer.
	 */
	public static function clear(): void {
		update_option( self::OPTION_KEY, array(), false );
	}

	/**
	 * Only write info/debug to PHP logs in explicit debug contexts.
	 */
	private static function should_write_to_php_error_log( int $level_index ): bool {
		if ( $level_index >= self::FILTER_THRESHOLD ) {
			return true;
		}

		$debug_enabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );

		/**
		 * Filter whether debug/info tracker messages should be written to the PHP error log.
		 *
		 * @since 5.0.5
		 *
		 * @param bool $debug_enabled Current default decision.
		 */
		return (bool) apply_filters( 'pressark_log_debug_messages', $debug_enabled );
	}
}
