<?php
/**
 * SSE (Server-Sent Events) output wrapper.
 *
 * Handles headers, output buffering, event formatting, keep-alive,
 * and client disconnect detection for streaming AI responses.
 *
 * @since 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_SSE_Emitter {

	private bool  $started         = false;
	private float $last_event_time = 0.0;

	/**
	 * Send SSE headers and disable all output buffering.
	 */
	public function start(): void {
		if ( $this->started ) {
			return;
		}

		// Keep the script running after client disconnect so that cleanup
		// code (run-store fail, reservation release, slot release) always
		// executes.  connection_aborted() is still checked explicitly to
		// detect disconnects and exit early where appropriate.
		ignore_user_abort( true );

		// Prevent WP from sending default headers.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' ); // Nginx proxy buffering.
		}

		// Clear all PHP/WP output buffers so events flush immediately.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$this->started         = true;
		$this->last_event_time = microtime( true );

		$this->emit( 'status', array( 'message' => 'Connected' ) );
	}

	/**
	 * Send a named SSE event.
	 *
	 * @param string       $type Event name (token, step, tool_call, tool_result, done, error).
	 * @param array|string $data Event payload — arrays are JSON-encoded.
	 */
	public function emit( string $type, $data ): void {
		if ( ! $this->started ) {
			return;
		}

		$json = is_string( $data ) ? $data : wp_json_encode( $data );

		echo "event: {$type}\ndata: {$json}\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();

		$this->last_event_time = microtime( true );
	}

	/**
	 * Send a comment line to prevent proxy/server timeouts.
	 *
	 * Should be called during long-running operations (e.g. tool execution)
	 * when no token events are being emitted.
	 */
	public function keep_alive(): void {
		if ( ! $this->started ) {
			return;
		}

		// Only send keep-alive if no event was sent in the last 10 seconds.
		if ( ( microtime( true ) - $this->last_event_time ) < 10.0 ) {
			return;
		}

		echo ": keep-alive\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();

		$this->last_event_time = microtime( true );
	}

	/**
	 * Check if the client is still connected.
	 */
	public function is_connected(): bool {
		return ! connection_aborted();
	}

	/**
	 * Force a connection liveness check.
	 *
	 * Writes an SSE comment (invisible to clients) and flushes, which
	 * causes PHP to update its internal connection_aborted() state.
	 * Call this between long operations (e.g. agent rounds) to get an
	 * accurate disconnect reading.
	 *
	 * @return bool True if the client is still connected.
	 */
	public function check_connection(): bool {
		if ( ! $this->started ) {
			return false;
		}

		echo ": heartbeat\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();

		return ! connection_aborted();
	}

	/**
	 * Final flush and close the stream.
	 */
	public function close(): void {
		if ( ! $this->started ) {
			return;
		}

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();

		$this->started = false;
	}
}
