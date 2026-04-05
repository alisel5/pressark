<?php
/**
 * Durable storage for canonical activity events.
 *
 * @package PressArk
 * @since   5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Activity_Event_Store {

	/**
	 * Get the fully-prefixed table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_activity_events';
	}

	/**
	 * DDL for dbDelta.
	 */
	public static function get_schema(): string {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			correlation_id VARCHAR(64) NOT NULL DEFAULT '',
			run_id VARCHAR(64) DEFAULT NULL,
			task_id VARCHAR(64) DEFAULT NULL,
			reservation_id VARCHAR(64) DEFAULT NULL,
			source VARCHAR(32) NOT NULL DEFAULT 'plugin',
			event_type VARCHAR(80) NOT NULL,
			phase VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(24) NOT NULL DEFAULT '',
			reason VARCHAR(64) NOT NULL DEFAULT '',
			summary VARCHAR(255) DEFAULT NULL,
			payload LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_event_id (event_id),
			KEY idx_correlation (correlation_id),
			KEY idx_event_type_created (event_type, created_at),
			KEY idx_reason_created (reason, created_at),
			KEY idx_run_created (run_id, created_at),
			KEY idx_task_created (task_id, created_at),
			KEY idx_reservation_created (reservation_id, created_at)
		) {$charset_collate};";
	}

	/**
	 * Persist one canonical event envelope.
	 *
	 * @param array $event Canonical event envelope.
	 * @return bool
	 */
	public function record( array $event ): bool {
		global $wpdb;

		$payload = wp_json_encode( is_array( $event['payload'] ?? null ) ? $event['payload'] : array() );

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'event_id'       => sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ),
				'correlation_id' => sanitize_text_field( (string) ( $event['correlation_id'] ?? '' ) ),
				'run_id'         => sanitize_text_field( (string) ( $event['run_id'] ?? '' ) ),
				'task_id'        => sanitize_text_field( (string) ( $event['task_id'] ?? '' ) ),
				'reservation_id' => sanitize_text_field( (string) ( $event['reservation_id'] ?? '' ) ),
				'source'         => sanitize_key( (string) ( $event['source'] ?? 'plugin' ) ),
				'event_type'     => sanitize_key( str_replace( '.', '_', (string) ( $event['event_type'] ?? '' ) ) ),
				'phase'          => sanitize_key( (string) ( $event['phase'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $event['status'] ?? '' ) ),
				'reason'         => sanitize_key( (string) ( $event['reason'] ?? '' ) ),
				'summary'        => mb_substr( sanitize_text_field( (string) ( $event['summary'] ?? '' ) ), 0, 255 ),
				'payload'        => $payload,
				'created_at'     => sanitize_text_field( (string) ( $event['occurred_at'] ?? current_time( 'mysql', true ) ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Read the newest events for a correlation ID.
	 *
	 * @param string $correlation_id Correlation ID.
	 * @param int    $limit          Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_correlation( string $correlation_id, int $limit = 120 ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' === $correlation_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE correlation_id = %s
				 ORDER BY created_at ASC, id ASC
				 LIMIT %d",
				$correlation_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $this->decode_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Read the newest events for a run ID.
	 *
	 * @param string $run_id Run ID.
	 * @param int    $limit  Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_run( string $run_id, int $limit = 120 ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' === $run_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE run_id = %s
				 ORDER BY created_at ASC, id ASC
				 LIMIT %d",
				$run_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $this->decode_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Read the newest events for a task ID.
	 *
	 * @param string $task_id Task ID.
	 * @param int    $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_task( string $task_id, int $limit = 120 ): array {
		global $wpdb;
		$table = self::table_name();

		if ( '' === $task_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE task_id = %s
				 ORDER BY created_at ASC, id ASC
				 LIMIT %d",
				$task_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $this->decode_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Query recent events for diagnostics dashboards.
	 *
	 * Supported filters: event_types, reasons, since, run_id, task_id.
	 *
	 * @param array<string,mixed> $filters Query filters.
	 * @param int                 $limit   Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function query_recent( array $filters = array(), int $limit = 250 ): array {
		global $wpdb;

		$table   = self::table_name();
		$sql     = "SELECT * FROM {$table} WHERE 1=1";
		$params  = array();

		$event_types = array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return sanitize_key( str_replace( '.', '_', (string) $value ) );
					},
					(array) ( $filters['event_types'] ?? array() )
				)
			)
		);
		if ( ! empty( $event_types ) ) {
			$sql     .= ' AND event_type IN (' . implode( ',', array_fill( 0, count( $event_types ), '%s' ) ) . ')';
			$params   = array_merge( $params, $event_types );
		}

		$reasons = array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return sanitize_key( (string) $value );
					},
					(array) ( $filters['reasons'] ?? array() )
				)
			)
		);
		if ( ! empty( $reasons ) ) {
			$sql    .= ' AND reason IN (' . implode( ',', array_fill( 0, count( $reasons ), '%s' ) ) . ')';
			$params  = array_merge( $params, $reasons );
		}

		$since = sanitize_text_field( (string) ( $filters['since'] ?? '' ) );
		if ( '' !== $since ) {
			$sql     .= ' AND created_at >= %s';
			$params[] = $since;
		}

		$run_id = sanitize_text_field( (string) ( $filters['run_id'] ?? '' ) );
		if ( '' !== $run_id ) {
			$sql     .= ' AND run_id = %s';
			$params[] = $run_id;
		}

		$task_id = sanitize_text_field( (string) ( $filters['task_id'] ?? '' ) );
		if ( '' !== $task_id ) {
			$sql     .= ' AND task_id = %s';
			$params[] = $task_id;
		}

		$sql     .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$params[] = max( 1, $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$params ),
			ARRAY_A
		);

		return $this->decode_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Decode JSON payloads and normalize row fields.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw DB rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function decode_rows( array $rows ): array {
		return array_map(
			static function ( array $row ): array {
				$row['payload'] = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
				$row['event_type'] = str_replace( '_', '.', (string) ( $row['event_type'] ?? '' ) );
				return $row;
			},
			$rows
		);
	}
}
