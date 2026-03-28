<?php
/**
 * PressArk Throttle
 *
 * Per-user and per-IP rate limiting with tier-based configuration.
 *
 * Four dimensions:
 * - Burst: requests per minute per user
 * - Hourly: requests per hour per user
 * - IP: requests per minute per IP (catches multi-account abuse)
 * - Concurrent: max in-flight requests per user
 *
 * BYOK users are still subject to rate limits (not token limits).
 *
 * v3.7.0: Replaced transient-based "atomic" counters with real MySQL
 * atomic operations (INSERT ON DUPLICATE KEY UPDATE) to fix race
 * conditions under concurrent load, especially on sites with
 * persistent object caches or high-traffic WooCommerce stores.
 * Concurrency slots now use a per-slot row model to eliminate the
 * get-then-set race that allowed over-admission.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Throttle {

	/**
	 * Concurrency slot TTL in seconds (safety net if release is never called).
	 * v3.7.0: Raised from 5 min to 10 min to cover long AI agent runs.
	 */
	private const SLOT_TTL = 600;

	/**
	 * Get rate limits for a tier from the unified entitlements config.
	 *
	 * @since 3.5.1
	 * @param string $tier Plan tier.
	 * @return array { burst, hourly, ip, concurrent }
	 */
	private static function get_limits( string $tier ): array {
		return array(
			'burst'      => (int) PressArk_Entitlements::tier_value( $tier, 'burst_per_min' ),
			'hourly'     => (int) PressArk_Entitlements::tier_value( $tier, 'hourly_limit' ),
			'ip'         => (int) PressArk_Entitlements::tier_value( $tier, 'ip_per_min' ),
			'concurrent' => (int) PressArk_Entitlements::tier_value( $tier, 'concurrency' ),
		);
	}

	/**
	 * Check all rate limits before allowing a request.
	 *
	 * v3.7.0: Uses atomic MySQL increment instead of get-then-set transients.
	 * v3.7.2: Increment-then-check eliminates the read-before-write race.
	 * Under the old check-then-increment model, two concurrent requests
	 * could both read count=4, both pass the ≥5 check, and both increment
	 * to 6 — admitting one extra request. Now the increment IS the check:
	 * the returned new value is compared against the limit. A rejected
	 * request still consumes a tick (harmless — the counter expires at
	 * bucket boundary and a rejected request should count against the limit
	 * to prevent retry storms).
	 *
	 * @param int    $user_id User ID.
	 * @param string $tier    Plan tier.
	 * @param string $ip      Client IP address.
	 * @return true|WP_Error True if allowed, WP_Error with retry_after on limit hit.
	 */
	public function check( int $user_id, string $tier, string $ip ): bool|WP_Error {
		$limits = self::get_limits( $tier );

		// Burst check (per-minute): increment first, then compare.
		$minute_bucket = (int) floor( time() / 60 );
		$burst_key     = 'pressark_burst_' . $user_id . '_' . $minute_bucket;
		$burst_count   = $this->atomic_increment( $burst_key, 60 );

		if ( $burst_count > $limits['burst'] ) {
			$retry_after = 60 - ( time() % 60 );
			return new WP_Error(
				'pressark_rate_limit',
				sprintf(
					/* translators: %d: number of seconds until the user can retry */
					__( 'Too many requests. Please wait %d seconds and try again.', 'pressark' ),
					$retry_after
				),
				array( 'status' => 429, 'retry_after' => $retry_after )
			);
		}

		// Hourly check (per-hour): increment first, then compare.
		$hour_bucket  = (int) floor( time() / 3600 );
		$hourly_key   = 'pressark_hourly_' . $user_id . '_' . $hour_bucket;
		$hourly_count = $this->atomic_increment( $hourly_key, 3600 );

		if ( $hourly_count > $limits['hourly'] ) {
			$retry_after = 3600 - ( time() % 3600 );
			return new WP_Error(
				'pressark_rate_limit',
				sprintf(
					/* translators: %d: number of minutes until the user can retry */
					__( 'Hourly request limit reached. Please wait %d minutes.', 'pressark' ),
					(int) ceil( $retry_after / 60 )
				),
				array( 'status' => 429, 'retry_after' => $retry_after )
			);
		}

		// IP check (per-minute): increment first, then compare.
		$ip_hash   = substr( md5( $ip ), 0, 12 );
		$ip_key    = 'pressark_ip_' . $ip_hash . '_' . $minute_bucket;
		$ip_count  = $this->atomic_increment( $ip_key, 60 );

		if ( $ip_count > $limits['ip'] ) {
			$retry_after = 60 - ( time() % 60 );
			return new WP_Error(
				'pressark_rate_limit',
				sprintf(
					/* translators: %d: number of seconds until the IP can retry */
					__( 'Too many requests from this IP. Please wait %d seconds.', 'pressark' ),
					$retry_after
				),
				array( 'status' => 429, 'retry_after' => $retry_after )
			);
		}

		return true;
	}

	/**
	 * Acquire a concurrency slot. Returns false if all slots are taken.
	 *
	 * v3.7.0: Slot-based model with UUID per slot.
	 * v3.7.1: Uses MySQL advisory lock (GET_LOCK) to serialize the
	 * entire read-prune-check-write sequence. This prevents the race
	 * where two concurrent requests both read the same slot array,
	 * both see room, and both write — creating one extra slot.
	 * On object-cache sites, uses wp_cache_add as a spin lock.
	 *
	 * @param int    $user_id User ID.
	 * @param string $tier    Plan tier.
	 * @return string|false Slot ID on success (pass to release_slot), false if full.
	 */
	public function acquire_slot( int $user_id, string $tier ): string|false {
		$limits    = self::get_limits( $tier );
		$max_slots = $limits['concurrent'];
		$slot_id   = wp_generate_uuid4();
		$key       = 'pressark_slots_' . $user_id;
		$lock_name = 'pressark_slot_lock_' . $user_id;
		$now       = time();

		// v3.7.1: Acquire advisory lock to serialize slot mutations.
		if ( ! $this->acquire_slot_lock( $lock_name ) ) {
			return false; // Couldn't get lock — treat as "full" (safe failure).
		}

		try {
			// Get current slots, prune expired.
			$slots = get_transient( $key );
			if ( ! is_array( $slots ) ) {
				$slots = array();
			}

			// Prune expired slots (safety net).
			$slots = array_filter( $slots, function ( $expires ) use ( $now ) {
				return $expires > $now;
			} );

			if ( count( $slots ) >= $max_slots ) {
				// Persist pruned version (may have freed a slot for next request).
				if ( ! empty( $slots ) ) {
					set_transient( $key, $slots, self::SLOT_TTL );
				} else {
					delete_transient( $key );
				}
				return false;
			}

			// Add our slot.
			$slots[ $slot_id ] = $now + self::SLOT_TTL;
			set_transient( $key, $slots, self::SLOT_TTL );

			return $slot_id;
		} finally {
			$this->release_slot_lock( $lock_name );
		}
	}

	/**
	 * Release a concurrency slot after request completes.
	 *
	 * v3.7.0: Accepts slot_id for precise release. Falls back to
	 * decrement behavior for backward compat if slot_id is empty.
	 * v3.7.1: Uses advisory lock for serialization consistency.
	 *
	 * @param int    $user_id User ID.
	 * @param string $slot_id Slot ID returned by acquire_slot (optional for back-compat).
	 */
	public function release_slot( int $user_id, string $slot_id = '' ): void {
		$key       = 'pressark_slots_' . $user_id;
		$lock_name = 'pressark_slot_lock_' . $user_id;

		$this->acquire_slot_lock( $lock_name );

		try {
			$slots = get_transient( $key );

			if ( ! is_array( $slots ) ) {
				// Back-compat: clear legacy scalar transient.
				delete_transient( 'pressark_concurrent_' . $user_id );
				return;
			}

			if ( $slot_id && isset( $slots[ $slot_id ] ) ) {
				unset( $slots[ $slot_id ] );
			} else {
				// Back-compat: remove the oldest slot.
				if ( ! empty( $slots ) ) {
					asort( $slots );
					array_shift( $slots );
				}
			}

			if ( empty( $slots ) ) {
				delete_transient( $key );
			} else {
				set_transient( $key, $slots, self::SLOT_TTL );
			}
		} finally {
			$this->release_slot_lock( $lock_name );
		}
	}

	/**
	 * Get number of active concurrency slots for a user.
	 * Useful for diagnostics and observability.
	 *
	 * @since 3.7.0
	 * @param int $user_id User ID.
	 * @return int Number of active (non-expired) slots.
	 */
	public function active_slots( int $user_id ): int {
		$key   = 'pressark_slots_' . $user_id;
		$slots = get_transient( $key );
		if ( ! is_array( $slots ) ) {
			return 0;
		}
		$now = time();
		return count( array_filter( $slots, fn( $exp ) => $exp > $now ) );
	}

	/**
	 * v3.7.1: Acquire a short-lived advisory lock to serialize slot mutations.
	 * Uses MySQL GET_LOCK on DB-backed sites, wp_cache_add on object-cache sites.
	 *
	 * @param string $lock_name Lock name.
	 * @return bool True if lock acquired.
	 */
	private function acquire_slot_lock( string $lock_name ): bool {
		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			// MySQL advisory lock — 2 second timeout (fail fast under contention).
			$got = $wpdb->get_var( $wpdb->prepare(
				"SELECT GET_LOCK(%s, 2)",
				$lock_name
			) );
			return (int) $got === 1;
		}

		// Object cache path: spin with wp_cache_add (atomic on Redis/Memcached).
		// Try up to 10 times with 50ms sleep (500ms total max).
		for ( $i = 0; $i < 10; $i++ ) {
			if ( wp_cache_add( $lock_name, '1', 'pressark_locks', 3 ) ) {
				return true;
			}
			usleep( 50000 ); // 50ms
		}
		return false;
	}

	/**
	 * v3.7.1: Release the advisory lock.
	 *
	 * @param string $lock_name Lock name.
	 */
	private function release_slot_lock( string $lock_name ): void {
		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
			return;
		}
		wp_cache_delete( $lock_name, 'pressark_locks' );
	}

	/**
	 * Atomically increment a transient counter and return the new value.
	 *
	 * v3.7.0: On MySQL-backed transients (no external object cache),
	 * uses INSERT ... ON DUPLICATE KEY UPDATE for true atomicity.
	 * On object-cache sites, uses wp_cache_incr which is atomic in
	 * Redis/Memcached. Falls back to get+set only as last resort.
	 *
	 * v3.7.2: Returns the new counter value so callers can use the
	 * increment-then-check pattern (eliminates read-before-write race).
	 *
	 * @param string $key Transient key.
	 * @param int    $ttl Time-to-live in seconds.
	 * @return int The new counter value after increment.
	 */
	private function atomic_increment( string $key, int $ttl ): int {
		// Path 1: External object cache — use native atomic incr.
		if ( wp_using_ext_object_cache() ) {
			$full_key = '_transient_' . $key;
			$result   = wp_cache_incr( $full_key );
			if ( false === $result ) {
				// Key doesn't exist yet — set it.
				set_transient( $key, 1, $ttl );
				return 1;
			}
			return (int) $result;
		}

		// Path 2: MySQL-backed transients — atomic via SQL.
		global $wpdb;
		$option_name  = '_transient_' . $key;
		$timeout_name = '_transient_timeout_' . $key;
		$expiration   = time() + $ttl;

		// Try atomic increment first (key already exists).
		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options}
			 SET option_value = option_value + 1
			 WHERE option_name = %s",
			$option_name
		) );

		if ( $rows > 0 ) {
			// Read back the new value (single-row SELECT is fast).
			$new_val = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			) );
			return (int) $new_val;
		}

		// Key doesn't exist — insert. Use INSERT IGNORE to handle concurrent inserts.
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, 1, 'no')",
			$option_name
		) );

		// Set/update the timeout transient.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
			$timeout_name,
			$expiration
		) );

		// If INSERT IGNORE was a no-op (concurrent insert won), read + return.
		if ( (int) $wpdb->rows_affected === 0 ) {
			// Another thread inserted first — increment their row.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = option_value + 1
				 WHERE option_name = %s",
				$option_name
			) );
			$new_val = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			) );
			return (int) $new_val;
		}

		return 1;
	}

	/**
	 * Clean up expired rate-limit counter rows from wp_options.
	 *
	 * v5.0.1: Atomic counters create bucket-keyed option rows
	 * (pressark_burst_*, pressark_hourly_*, pressark_ip_*) that accumulate
	 * over time. WordPress only auto-deletes expired transients on access,
	 * so these rows persist indefinitely. This method deletes rows whose
	 * timeout transient has expired, preventing wp_options table bloat.
	 *
	 * Should be called from the daily cleanup cron.
	 */
	public static function cleanup_expired_counters(): void {
		global $wpdb;

		// Delete transient timeouts that have already expired, and their matching transient values.
		// Match patterns: _transient_timeout_pressark_burst_*, _transient_timeout_pressark_hourly_*, _transient_timeout_pressark_ip_*
		$expired = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			   AND option_value < %d
			 LIMIT 500",
			$wpdb->esc_like( '_transient_timeout_pressark_' ) . '%',
			time()
		) );

		if ( empty( $expired ) ) {
			return;
		}

		foreach ( $expired as $timeout_name ) {
			$value_name = str_replace( '_transient_timeout_', '_transient_', $timeout_name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				$timeout_name,
				$value_name
			) );
		}
	}
}
