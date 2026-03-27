<?php
/**
 * Local cost ledger for reservation telemetry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Cost_Ledger {

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressark_cost_ledger';
	}

	public static function get_schema(): string {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			reservation_id VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'reserved',
			estimated_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			estimated_icus INT UNSIGNED NOT NULL DEFAULT 0,
			settled_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			settled_icus INT UNSIGNED NOT NULL DEFAULT 0,
			input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			route VARCHAR(20) NOT NULL DEFAULT 'legacy',
			provider VARCHAR(50) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			model_class VARCHAR(20) NOT NULL DEFAULT '',
			model_multiplier_input INT UNSIGNED NOT NULL DEFAULT 0,
			model_multiplier_output INT UNSIGNED NOT NULL DEFAULT 0,
			is_byok TINYINT(1) NOT NULL DEFAULT 0,
			agent_rounds INT UNSIGNED NOT NULL DEFAULT 0,
			fail_reason TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			settled_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_reservation_id (reservation_id),
			KEY idx_user_status (user_id, status),
			KEY idx_user_date (user_id, created_at),
			KEY idx_status_created (status, created_at)
		) {$charset_collate};";
	}

	public function insert( array $data ) {
		global $wpdb;

		$defaults = array(
			'user_id'                => get_current_user_id(),
			'reservation_id'         => '',
			'status'                 => 'reserved',
			'estimated_tokens'       => 0,
			'estimated_icus'         => 0,
			'settled_tokens'         => 0,
			'settled_icus'           => 0,
			'input_tokens'           => 0,
			'output_tokens'          => 0,
			'cache_read_tokens'      => 0,
			'cache_write_tokens'     => 0,
			'route'                  => 'legacy',
			'provider'               => '',
			'model'                  => '',
			'model_class'            => '',
			'model_multiplier_input' => 0,
			'model_multiplier_output'=> 0,
			'is_byok'                => 0,
			'agent_rounds'           => 0,
			'fail_reason'            => null,
			'created_at'             => current_time( 'mysql' ),
			'settled_at'             => null,
		);

		$row    = array_merge( $defaults, $data );
		$result = $wpdb->insert( self::table_name(), $row );

		return $result ? $wpdb->insert_id : false;
	}

	public function settle( string $reservation_id, array $actual ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table_name(),
			array(
				'status'                 => 'settled',
				'settled_tokens'         => (int) ( $actual['settled_tokens'] ?? 0 ),
				'settled_icus'           => (int) ( $actual['settled_icus'] ?? 0 ),
				'input_tokens'           => (int) ( $actual['input_tokens'] ?? 0 ),
				'output_tokens'          => (int) ( $actual['output_tokens'] ?? 0 ),
				'cache_read_tokens'      => (int) ( $actual['cache_read_tokens'] ?? 0 ),
				'cache_write_tokens'     => (int) ( $actual['cache_write_tokens'] ?? 0 ),
				'agent_rounds'           => (int) ( $actual['agent_rounds'] ?? 0 ),
				'provider'               => (string) ( $actual['provider'] ?? '' ),
				'model'                  => (string) ( $actual['model'] ?? '' ),
				'model_class'            => (string) ( $actual['model_class'] ?? '' ),
				'model_multiplier_input' => (int) ( $actual['model_multiplier_input'] ?? 0 ),
				'model_multiplier_output'=> (int) ( $actual['model_multiplier_output'] ?? 0 ),
				'settled_at'             => current_time( 'mysql' ),
			),
			array(
				'reservation_id' => $reservation_id,
				'status'         => 'reserved',
			)
		);
	}

	public function fail( string $reservation_id, string $reason = '' ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table_name(),
			array(
				'status'      => 'failed',
				'fail_reason' => $reason,
				'settled_at'  => current_time( 'mysql' ),
			),
			array(
				'reservation_id' => $reservation_id,
				'status'         => 'reserved',
			)
		);
	}

	public function expire_stale( int $minutes = 5 ): int {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );

		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'expired', settled_at = %s, fail_reason = 'auto-expired after timeout'
			 WHERE status = 'reserved' AND created_at < %s",
			current_time( 'mysql' ),
			$cutoff
		) );
	}

	public function get_active_reserved_tokens( int $user_id ): int {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(estimated_icus), 0)
			 FROM {$table}
			 WHERE user_id = %d AND status = 'reserved'",
			$user_id
		) );
	}

	public function get_settled_icus_since( int $user_id, string $since ): int {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(settled_icus), 0)
			 FROM {$table}
			 WHERE user_id = %d
			   AND status = 'settled'
			   AND settled_at IS NOT NULL
			   AND settled_at >= %s",
			$user_id,
			$since
		) );
	}

	public function get_user_stats( int $user_id, string $since = '' ): array {
		global $wpdb;
		$table = self::table_name();

		if ( empty( $since ) ) {
			$since = gmdate( 'Y-m-01 00:00:00' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS total_requests,
				SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) AS settled_count,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
				SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_count,
				COALESCE(SUM(settled_tokens), 0) AS total_settled_tokens,
				COALESCE(SUM(settled_icus), 0) AS total_settled_icus,
				COALESCE(SUM(input_tokens), 0) AS total_input_tokens,
				COALESCE(SUM(output_tokens), 0) AS total_output_tokens,
				COALESCE(SUM(cache_read_tokens), 0) AS total_cache_read,
				COALESCE(SUM(cache_write_tokens), 0) AS total_cache_write
			 FROM {$table}
			 WHERE user_id = %d AND created_at >= %s",
			$user_id,
			$since
		), ARRAY_A );

		return $row ?: array();
	}
}
