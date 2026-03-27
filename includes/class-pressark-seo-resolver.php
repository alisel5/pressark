<?php
/**
 * Unified SEO Resolver — single point of truth for all SEO plugin integration.
 *
 * Handles detect, read, write, and monitoring across 5 SEO plugins + PressArk fallback.
 * AIOSEO uses the `aioseo_posts` table (not post_meta) for its canonical storage.
 *
 * @since 4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_SEO_Resolver {

	/**
	 * Supported SEO plugin slugs.
	 */
	public const PLUGINS = array( 'yoast', 'rankmath', 'aioseo', 'seopress', 'seo_framework' );

	/**
	 * Plugin slug → index for FIELD_MAP lookups.
	 */
	private const PLUGIN_INDEX = array(
		'yoast'         => 0,
		'rankmath'      => 1,
		'aioseo'        => 2,
		'seopress'      => 3,
		'seo_framework' => 4,
	);

	/**
	 * Semantic field → [ yoast_key, rankmath_key, aioseo_column, seopress_key, tsf_key, pressark_key ].
	 *
	 * For AIOSEO (index 2): values are column names in the `aioseo_posts` table,
	 * NOT post_meta keys. read() and write() handle this transparently.
	 */
	private const FIELD_MAP = array(
		'meta_title'       => array( '_yoast_wpseo_title', 'rank_math_title', 'title', '_seopress_titles_title', '_genesis_title', '_pressark_meta_title' ),
		'meta_description' => array( '_yoast_wpseo_metadesc', 'rank_math_description', 'description', '_seopress_titles_desc', '_genesis_description', '_pressark_meta_description' ),
		'canonical'        => array( '_yoast_wpseo_canonical', 'rank_math_canonical_url', 'canonical_url', '_seopress_robots_canonical', '_genesis_canonical_uri', '_pressark_canonical' ),
		'og_title'         => array( '_yoast_wpseo_opengraph-title', 'rank_math_facebook_title', 'og_title', '_seopress_social_fb_title', '_open_graph_title', '_pressark_og_title' ),
		'og_description'   => array( '_yoast_wpseo_opengraph-description', 'rank_math_facebook_description', 'og_description', '_seopress_social_fb_desc', '_open_graph_description', '_pressark_og_description' ),
		'og_image'         => array( '_yoast_wpseo_opengraph-image', 'rank_math_facebook_image', 'og_image_custom_url', '_seopress_social_fb_img', '_social_image_url', '_pressark_og_image' ),
		'focus_keyword'    => array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', 'keyphrases', '_seopress_analysis_target_kw', '', '_pressark_focus_keyword' ),
	);

	// ── Detection ─────────────────────────────────────────────────────

	/**
	 * Detect which SEO plugin is active (cached per request).
	 *
	 * @return string|null Plugin slug or null if none detected.
	 */
	public static function detect(): ?string {
		static $cached = null;
		static $ran    = false;

		if ( $ran ) {
			return $cached;
		}
		$ran = true;

		if ( defined( 'WPSEO_VERSION' ) ) {
			$cached = 'yoast';
		} elseif ( class_exists( 'RankMath' ) ) {
			$cached = 'rankmath';
		} elseif ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) ) {
			$cached = 'aioseo';
		} elseif ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) {
			$cached = 'seopress';
		} elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
			$cached = 'seo_framework';
		}

		return $cached;
	}

	/**
	 * Shorthand for detect() !== null.
	 */
	public static function has_plugin(): bool {
		return null !== self::detect();
	}

	/**
	 * Human-readable plugin name.
	 *
	 * @param string|null $plugin Plugin slug (defaults to detected plugin).
	 * @return string
	 */
	public static function label( ?string $plugin = null ): string {
		$plugin = $plugin ?? self::detect();

		return match ( $plugin ) {
			'yoast'         => 'Yoast SEO',
			'rankmath'      => 'Rank Math',
			'aioseo'        => 'All in One SEO',
			'seopress'      => 'SEOPress',
			'seo_framework' => 'The SEO Framework',
			default         => 'SEO plugin',
		};
	}

	// ── Read ──────────────────────────────────────────────────────────

	/**
	 * Read one semantic field from the active plugin (or PressArk fallback).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Semantic field name (meta_title, meta_description, etc.).
	 * @return string Value or empty string.
	 */
	public static function read( int $post_id, string $field ): string {
		if ( ! isset( self::FIELD_MAP[ $field ] ) ) {
			return '';
		}

		$active = self::detect();
		$keys   = self::FIELD_MAP[ $field ];

		// If a plugin is active, read from it first.
		if ( $active && isset( self::PLUGIN_INDEX[ $active ] ) ) {
			$idx   = self::PLUGIN_INDEX[ $active ];
			$key   = $keys[ $idx ];

			if ( '' === $key ) {
				// Plugin doesn't support this field (e.g. TSF focus_keyword).
				return '';
			}

			if ( 'aioseo' === $active ) {
				$val = self::aioseo_read( $post_id, $key );
				if ( 'keyphrases' === $key && '' !== $val ) {
					$val = self::aioseo_extract_focus_keyword( $val );
				}
				return $val;
			}

			$val = get_post_meta( $post_id, $key, true );
			return is_string( $val ) ? $val : '';
		}

		// No plugin active — try PressArk fallback key.
		$val = get_post_meta( $post_id, $keys[5], true );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Read all semantic fields from the active plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string> field => value.
	 */
	public static function read_all( int $post_id ): array {
		$result = array();

		// Pre-fetch AIOSEO row for efficiency.
		$active = self::detect();
		if ( 'aioseo' === $active ) {
			self::aioseo_read_row( $post_id );
		}

		foreach ( self::FIELD_MAP as $field => $keys ) {
			$result[ $field ] = self::read( $post_id, $field );
		}

		return $result;
	}

	// ── Write ─────────────────────────────────────────────────────────

	/**
	 * Write one semantic field to the active plugin (or PressArk fallback).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Semantic field name.
	 * @param string $value   Value to write.
	 * @return bool True on success.
	 */
	public static function write( int $post_id, string $field, string $value ): bool {
		if ( ! isset( self::FIELD_MAP[ $field ] ) ) {
			return false;
		}

		$active = self::detect();
		$keys   = self::FIELD_MAP[ $field ];

		if ( $active && isset( self::PLUGIN_INDEX[ $active ] ) ) {
			$idx = self::PLUGIN_INDEX[ $active ];
			$key = $keys[ $idx ];

			if ( '' === $key ) {
				return false;
			}

			if ( 'aioseo' === $active ) {
				if ( 'keyphrases' === $key ) {
					$value = self::aioseo_wrap_focus_keyword( $value );
				}
				return self::aioseo_write( $post_id, $key, $value );
			}

			return (bool) update_post_meta( $post_id, $key, $value );
		}

		// No plugin — write to PressArk fallback key.
		return (bool) update_post_meta( $post_id, $keys[5], $value );
	}

	// ── Key Resolution (backward compat) ──────────────────────────────

	/**
	 * Resolve a semantic or raw meta key to the correct plugin-specific key.
	 *
	 * Kept for backward compatibility with legacy format handlers.
	 * For AIOSEO, returns the post_meta fallback key since this method
	 * is used in contexts that expect a post_meta key.
	 *
	 * @param string $key Semantic or raw meta key.
	 * @return string Resolved meta key.
	 */
	public static function resolve_key( string $key ): string {
		$active = self::detect();

		// Normalize for matching.
		$normalized = strtolower( trim( $key ) );
		$normalized = preg_replace( '/^_?(pressark|yoast_wpseo|rank_math|aioseo|seopress_titles|seopress_social|genesis)_?/', '', $normalized );
		$normalized = str_replace( '-', '_', $normalized );

		foreach ( self::FIELD_MAP as $semantic => $keys ) {
			$is_match = ( $normalized === $semantic );
			if ( ! $is_match ) {
				foreach ( $keys as $known ) {
					if ( $key === $known ) {
						$is_match = true;
						break;
					}
				}
			}

			if ( $is_match ) {
				if ( $active && isset( self::PLUGIN_INDEX[ $active ] ) ) {
					$idx = self::PLUGIN_INDEX[ $active ];
					// For AIOSEO, resolve_key returns the PressArk fallback because
					// callers expect a post_meta key. Use read()/write() for proper AIOSEO support.
					if ( 'aioseo' === $active ) {
						return $keys[5];
					}
					return $keys[ $idx ];
				}
				return $keys[5]; // PressArk fallback.
			}
		}

		// Not a known SEO key — return as-is.
		return $key;
	}

	// ── Robots ────────────────────────────────────────────────────────

	/**
	 * Check whether a post is set to noindex by the active SEO plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_noindex( int $post_id ): bool {
		$plugin = self::detect();
		if ( ! $plugin ) {
			return false;
		}

		switch ( $plugin ) {
			case 'yoast':
				return '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );

			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( is_array( $robots ) ) {
					return in_array( 'noindex', $robots, true );
				}
				if ( is_string( $robots ) ) {
					return str_contains( $robots, 'noindex' );
				}
				return false;

			case 'aioseo':
				$row = self::aioseo_read_row( $post_id );
				if ( $row ) {
					// robots_default = true means "use global defaults" (not per-post override).
					if ( ! empty( $row->robots_default ) && '0' !== $row->robots_default ) {
						return false;
					}
					return ! empty( $row->robots_noindex );
				}
				// Fallback to post_meta for sites without the table.
				$default = get_post_meta( $post_id, '_aioseo_robots_default', true );
				if ( '0' !== $default && '' !== $default ) {
					return false;
				}
				return (bool) get_post_meta( $post_id, '_aioseo_robots_noindex', true );

			case 'seopress':
				// SEOPress inverts: 'yes' means noindex is ON.
				return 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true );

			case 'seo_framework':
				if ( '1' === get_post_meta( $post_id, '_genesis_noindex', true ) ) {
					return true;
				}
				return '1' === get_post_meta( $post_id, 'exclude_local_search', true );

			default:
				return false;
		}
	}

	/**
	 * Check whether a post is set to nofollow by the active SEO plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_nofollow( int $post_id ): bool {
		$plugin = self::detect();
		if ( ! $plugin ) {
			return false;
		}

		switch ( $plugin ) {
			case 'yoast':
				return '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

			case 'rankmath':
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( is_array( $robots ) ) {
					return in_array( 'nofollow', $robots, true );
				}
				if ( is_string( $robots ) ) {
					return str_contains( $robots, 'nofollow' );
				}
				return false;

			case 'aioseo':
				$row = self::aioseo_read_row( $post_id );
				if ( $row ) {
					if ( ! empty( $row->robots_default ) && '0' !== $row->robots_default ) {
						return false;
					}
					return ! empty( $row->robots_nofollow );
				}
				$default = get_post_meta( $post_id, '_aioseo_robots_default', true );
				if ( '0' !== $default && '' !== $default ) {
					return false;
				}
				return (bool) get_post_meta( $post_id, '_aioseo_robots_nofollow', true );

			case 'seopress':
				return 'yes' === get_post_meta( $post_id, '_seopress_robots_follow', true );

			case 'seo_framework':
				return '1' === get_post_meta( $post_id, '_genesis_nofollow', true );

			default:
				return false;
		}
	}

	// ── Rendered Output ───────────────────────────────────────────────

	/**
	 * Plugin-API-rendered title (with template variable resolution).
	 *
	 * @param int $post_id Post ID.
	 * @return string Rendered title or empty string on failure.
	 */
	public static function rendered_title( int $post_id ): string {
		$plugin = self::detect();
		if ( ! $plugin ) {
			return '';
		}

		try {
			switch ( $plugin ) {
				case 'yoast':
					if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
						$per_post = get_post_meta( $post_id, '_yoast_wpseo_title', true );
						if ( empty( $per_post ) ) {
							$post_type = get_post_type( $post_id );
							$options   = get_option( 'wpseo_titles', array() );
							$per_post  = $options[ "title-{$post_type}" ] ?? '';
						}
						if ( ! empty( $per_post ) ) {
							$replace = new \WPSEO_Replace_Vars();
							return trim( $replace->replace( $per_post, get_post( $post_id ) ) );
						}
					}
					return '';

				case 'rankmath':
					if ( class_exists( 'RankMath\\Paper\\Paper' ) ) {
						$paper = \RankMath\Paper\Paper::get();
						if ( $paper && method_exists( $paper, 'get_title' ) ) {
							return trim( (string) $paper->get_title() );
						}
					}
					return '';

				case 'aioseo':
					if ( function_exists( 'aioseo' ) && is_object( aioseo() ) ) {
						$meta = aioseo()->meta ?? null;
						if ( $meta && isset( $meta->title ) && method_exists( $meta->title, 'getTitle' ) ) {
							return trim( (string) $meta->title->getTitle( $post_id ) );
						}
					}
					return '';

				case 'seopress':
					if ( function_exists( 'seopress_titles_the_title' ) ) {
						ob_start();
						seopress_titles_the_title();
						return trim( (string) ob_get_clean() );
					}
					return '';

				case 'seo_framework':
					if ( function_exists( 'the_seo_framework' ) ) {
						$tsf = the_seo_framework();
						if ( $tsf && method_exists( $tsf, 'get_title' ) ) {
							return trim( (string) $tsf->get_title( array( 'id' => $post_id ) ) );
						}
					}
					return '';

				default:
					return '';
			}
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Plugin-API-rendered description (with template variable resolution).
	 *
	 * @param int $post_id Post ID.
	 * @return string Rendered description or empty string on failure.
	 */
	public static function rendered_description( int $post_id ): string {
		$plugin = self::detect();
		if ( ! $plugin ) {
			return '';
		}

		try {
			switch ( $plugin ) {
				case 'yoast':
					if ( class_exists( 'WPSEO_Replace_Vars' ) ) {
						$per_post = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
						if ( empty( $per_post ) ) {
							$post_type = get_post_type( $post_id );
							$options   = get_option( 'wpseo_titles', array() );
							$per_post  = $options[ "metadesc-{$post_type}" ] ?? '';
						}
						if ( ! empty( $per_post ) ) {
							$replace = new \WPSEO_Replace_Vars();
							return trim( $replace->replace( $per_post, get_post( $post_id ) ) );
						}
					}
					return '';

				case 'rankmath':
					if ( class_exists( 'RankMath\\Paper\\Paper' ) ) {
						$paper = \RankMath\Paper\Paper::get();
						if ( $paper && method_exists( $paper, 'get_description' ) ) {
							return trim( (string) $paper->get_description() );
						}
					}
					return '';

				case 'aioseo':
					if ( function_exists( 'aioseo' ) && is_object( aioseo() ) ) {
						$meta = aioseo()->meta ?? null;
						if ( $meta && isset( $meta->description ) && method_exists( $meta->description, 'getDescription' ) ) {
							return trim( (string) $meta->description->getDescription( $post_id ) );
						}
					}
					return '';

				case 'seopress':
					if ( function_exists( 'seopress_titles_the_description' ) ) {
						ob_start();
						seopress_titles_the_description();
						return trim( (string) ob_get_clean() );
					}
					return '';

				case 'seo_framework':
					if ( function_exists( 'the_seo_framework' ) ) {
						$tsf = the_seo_framework();
						if ( $tsf && method_exists( $tsf, 'get_description' ) ) {
							return trim( (string) $tsf->get_description( array( 'id' => $post_id ) ) );
						}
					}
					return '';

				default:
					return '';
			}
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	// ── Monitoring ────────────────────────────────────────────────────

	/**
	 * All plugin meta keys that should trigger a reindex when changed.
	 *
	 * Includes keys from ALL plugins (not just the active one) so the
	 * content index doesn't miss changes when plugins are switched.
	 *
	 * @return array<string>
	 */
	public static function monitored_meta_keys(): array {
		$keys = array();

		foreach ( self::FIELD_MAP as $field => $plugin_keys ) {
			// Index 0-4 are plugin keys, index 5 is PressArk fallback.
			foreach ( $plugin_keys as $idx => $key ) {
				// Skip AIOSEO column names (index 2) — they're table columns, not meta keys.
				if ( 2 === $idx ) {
					continue;
				}
				if ( '' !== $key ) {
					$keys[] = $key;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	// ── AIOSEO Table Helpers (private) ────────────────────────────────

	/**
	 * Read a single column from the aioseo_posts table.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $column  Column name.
	 * @return string Value or empty string.
	 */
	private static function aioseo_read( int $post_id, string $column ): string {
		$row = self::aioseo_read_row( $post_id );
		if ( $row && isset( $row->$column ) ) {
			return (string) $row->$column;
		}
		return '';
	}

	/**
	 * Write a single column to the aioseo_posts table (upsert).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $column  Column name.
	 * @param string $value   Value to write.
	 * @return bool
	 */
	private static function aioseo_write( int $post_id, string $column, string $value ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		// Check if the table exists.
		if ( ! self::aioseo_table_exists() ) {
			// Fallback to post_meta with _aioseo_ prefix.
			return (bool) update_post_meta( $post_id, '_aioseo_' . $column, $value );
		}

		// Check if row exists.
		$existing = self::aioseo_read_row( $post_id );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update(
				$table,
				array( $column => $value, 'updated' => current_time( 'mysql' ) ),
				array( 'post_id' => $post_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->insert(
				$table,
				array(
					'post_id' => $post_id,
					$column   => $value,
					'created' => current_time( 'mysql' ),
					'updated' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		// Invalidate row cache.
		self::$aioseo_row_cache[ $post_id ] = null;

		return false !== $result;
	}

	/**
	 * Per-request cache for aioseo_posts rows.
	 *
	 * @var array<int, object|null|false>  false = not fetched yet.
	 */
	private static array $aioseo_row_cache = array();

	/**
	 * Read the full aioseo_posts row for a post (cached per request).
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Row object or null if not found.
	 */
	private static function aioseo_read_row( int $post_id ): ?object {
		if ( isset( self::$aioseo_row_cache[ $post_id ] ) ) {
			$cached = self::$aioseo_row_cache[ $post_id ];
			return ( null === $cached ) ? null : $cached;
		}

		if ( ! self::aioseo_table_exists() ) {
			self::$aioseo_row_cache[ $post_id ] = null;
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d LIMIT 1",
			$post_id
		) );

		self::$aioseo_row_cache[ $post_id ] = $row ?: null;
		return self::$aioseo_row_cache[ $post_id ];
	}

	/**
	 * Check if the aioseo_posts table exists (cached per request).
	 *
	 * @return bool
	 */
	private static function aioseo_table_exists(): bool {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME,
			$table
		) );

		return $exists;
	}

	/**
	 * Extract the primary focus keyword from AIOSEO's JSON keyphrases structure.
	 *
	 * AIOSEO stores: {"focus":{"keyphrase":"keyword here","score":0,...},"additional":[...]}
	 *
	 * @param string $json Raw JSON string.
	 * @return string Extracted keyphrase or empty string.
	 */
	private static function aioseo_extract_focus_keyword( string $json ): string {
		if ( '' === $json ) {
			return '';
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return '';
		}

		return (string) ( $data['focus']['keyphrase'] ?? '' );
	}

	/**
	 * Wrap a plain keyword string into AIOSEO's JSON keyphrases structure.
	 *
	 * @param string $keyword Plain keyword.
	 * @return string JSON string.
	 */
	private static function aioseo_wrap_focus_keyword( string $keyword ): string {
		return wp_json_encode( array(
			'focus'      => array(
				'keyphrase' => $keyword,
				'score'     => 0,
				'analysis'  => array(),
			),
			'additional' => array(),
		) );
	}
}
