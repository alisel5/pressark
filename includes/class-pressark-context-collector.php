<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v5.6.0: Memoized context with cache boundaries (Claude Code pattern:
 * getUserContext memoized, system-reminder in user turn).
 */
class PressArk_Context_Collector {

	private const CACHE_GROUP          = 'pressark';
	private const USER_CONTEXT_TTL     = 300;
	private const MEMORY_FILE_TTL      = 3600;
	private const SITE_IDENTITY_TTL    = 3600;
	private const CACHE_VERSION_OPTION = 'pressark_context_cache_version';

	private static ?array $cached_context = null;
	private static string $cache_key      = '';

	/**
	 * Get user context – memoized, stable until chat cleared.
	 * Mirrors Claude Code getUserContext() – goes in USER message, not system.
	 */
	public function get_user_context( int $chat_id, int $user_id ): array {
		$cache_key = sprintf(
			'pressark_ctx_v%d_%d_%d',
			self::cache_version(),
			max( 0, $chat_id ),
			max( 0, $user_id )
		);

		// Check memory cache first (same request).
		if ( null !== self::$cached_context && self::$cache_key === $cache_key ) {
			return self::$cached_context;
		}

		// Check WP object cache (5 minute TTL).
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached && is_array( $cached ) ) {
			self::$cached_context = $cached;
			self::$cache_key      = $cache_key;
			return $cached;
		}

		$context = array();

		// 1. Memory file (.pressark.md) – READ ONCE, CACHE.
		$memory = $this->get_memory_file();
		if ( '' !== $memory ) {
			$context['pressark_md'] = $memory;
		}

		// 2. Current date – STABLE for session.
		$context['current_date'] = "Today's date is " . current_time( 'Y-m-d' ) . '.';

		// 3. Site identity – STABLE, changes rarely.
		$context['site_identity'] = $this->get_stable_site_identity();

		// Cache for 5 minutes.
		wp_cache_set( $cache_key, $context, self::CACHE_GROUP, self::USER_CONTEXT_TTL );
		self::$cached_context = $context;
		self::$cache_key      = $cache_key;

		$this->debug_log( sprintf( 'PressArk: Built memoized user context for chat %d user %d.', $chat_id, $user_id ) );

		return $context;
	}

	/**
	 * Get system context – ONLY for truly stable data.
	 * Mirrors Claude Code getSystemContext() – git status only.
	 * WARNING: This breaks prompt cache if it changes.
	 */
	public function get_system_context(): array {
		// ONLY include data that changes < once per day.
		return array(
			'wordpress_version' => get_bloginfo( 'version' ), // Changes quarterly.
			'php_version'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, // Changes rarely.
		);
	}

	/**
	 * DANGEROUS: Volatile context that breaks cache.
	 * Must provide reason why this can't be memoized.
	 * Mirrors DANGEROUS_uncachedSystemPromptSection().
	 */
	public function get_volatile_context( string $reason ): array {
		if ( '' === trim( $reason ) ) {
			_doing_it_wrong( __METHOD__, 'Volatile context requires reason', '5.6.0' );
			return array();
		}

		$this->debug_log( "PressArk: Using volatile context – {$reason}" );

		// Only allow specific volatile data with justification.
		return array(
			'_volatile_reason' => sanitize_text_field( $reason ),
			// Add volatile data here ONLY if reason is valid.
		);
	}

	private function get_memory_file(): string {
		$version = self::cache_version();
		$paths   = array(
			ABSPATH . '.pressark.md',
			WP_CONTENT_DIR . '/.pressark.md',
		);

		foreach ( $paths as $path ) {
			$cache_key = 'pressark_md_v' . $version . '_' . md5( $path );
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
			$now       = time();

			if ( false !== $cached && is_array( $cached ) ) {
				$checked_at = (int) ( $cached['checked_at'] ?? 0 );
				$is_fresh   = $checked_at > 0 && ( $now - $checked_at ) < self::USER_CONTEXT_TTL;

				if ( ! empty( $cached['missing'] ) && $is_fresh ) {
					continue;
				}

				if ( isset( $cached['content'] ) && $is_fresh ) {
					return (string) $cached['content'];
				}
			}

			if ( ! file_exists( $path ) ) {
				wp_cache_set(
					$cache_key,
					array(
						'missing'    => true,
						'checked_at' => $now,
					),
					self::CACHE_GROUP,
					self::USER_CONTEXT_TTL
				);
				continue;
			}

			$mtime = (int) filemtime( $path );

			if ( false !== $cached
				&& is_array( $cached )
				&& (int) ( $cached['mtime'] ?? -1 ) === $mtime
			) {
				wp_cache_set(
					$cache_key,
					array(
						'mtime'      => $mtime,
						'content'    => (string) ( $cached['content'] ?? '' ),
						'checked_at' => $now,
					),
					self::CACHE_GROUP,
					self::MEMORY_FILE_TTL
				);
				return (string) ( $cached['content'] ?? '' );
			}

			$content = file_get_contents( $path, false, null, 0, 2000 );
			if ( false === $content ) {
				continue;
			}

			$content = sanitize_textarea_field( $content );

			wp_cache_set(
				$cache_key,
				array(
					'mtime'      => $mtime,
					'content'    => $content,
					'checked_at' => $now,
				),
				self::CACHE_GROUP,
				self::MEMORY_FILE_TTL
			);

			$this->debug_log( sprintf( 'PressArk: Read memoized memory file from %s.', $path ) );

			return $content;
		}

		return '';
	}

	private function get_stable_site_identity(): string {
		$cache_key = 'pressark_site_identity_v' . self::cache_version();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}

		$identity = sprintf(
			'WordPress site "%s" (%s)',
			get_bloginfo( 'name' ),
			home_url()
		);

		wp_cache_set( $cache_key, $identity, self::CACHE_GROUP, self::SITE_IDENTITY_TTL );

		return $identity;
	}

	/**
	 * Clear all context caches – call on /clear or /compact.
	 */
	public static function clear_cache(): void {
		self::$cached_context = null;
		self::$cache_key      = '';

		update_option( self::CACHE_VERSION_OPTION, self::cache_version() + 1, false );

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) && function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		} elseif ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	private static function cache_version(): int {
		return max( 1, (int) get_option( self::CACHE_VERSION_OPTION, 1 ) );
	}

	private function debug_log( string $message ): void {
		if ( ( defined( 'PRESSARK_DEBUG' ) && PRESSARK_DEBUG )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )
		) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is the gated debug logger; the surrounding `if` already requires WP_DEBUG/WP_DEBUG_LOG or PRESSARK_DEBUG.
			error_log( $message );
		}
	}
}
