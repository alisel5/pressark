<?php
/**
 * SEO & Security action handlers.
 *
 * Handles: analyze_seo, fix_seo, scan_security, fix_security.
 *
 * @since 2.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Handler_SEO extends PressArk_Handler_Base {

	/**
	 * Analyse on-page / site-wide SEO via PressArk_SEO_Scanner.
	 *
	 * @param array $params {post_id|scope} — 'all'/'site'/null for site-wide, int for single page.
	 * @return array
	 */
	public function analyze_seo( array $params ): array {
		$scanner = new PressArk_SEO_Scanner();

		// Accept post_id from multiple places the AI might put it.
		$post_id = $params['post_id'] ?? ( $params['scope'] ?? null );

		if ( 'all' === $post_id || 'site' === $post_id || null === $post_id ) {
			$limit  = min( absint( $params['limit'] ?? 50 ), 100 );
			$offset = absint( $params['offset'] ?? 0 );
			$result = $scanner->scan_site( $limit, $offset );
		} else {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				return array( 'success' => false, 'message' => __( 'Invalid page ID for SEO analysis.', 'pressark' ) );
			}
			$result = $scanner->scan_page( $post_id );
		}

		if ( isset( $result['error'] ) ) {
			return array( 'success' => false, 'message' => $result['error'] );
		}

		return array(
			'success'      => true,
			'message'      => '',
			'data'         => $result,
			'scanner_type' => 'seo',
		);
	}

	/**
	 * Apply SEO meta fixes to one or more posts.
	 *
	 * Supports two formats:
	 *   – New: [{post_id, meta_title, meta_description, og_title, og_description}]
	 *   – Legacy: [{type: "update_meta", post_id, key, suggested_value}]
	 *
	 * @param array $params {fixes: array}.
	 * @return array
	 */
	public function fix_seo( array $params ): array {
		$fixes = $params['fixes'] ?? array();
		$force = ! empty( $params['force'] );

		// If specific fixes with the new format are provided, apply them.
		if ( ! empty( $fixes ) && is_array( $fixes ) ) {
			// Detect format: new format has meta_title/meta_description directly, legacy has type: "update_meta".
			$first_fix  = reset( $fixes );
			$is_new_fmt = isset( $first_fix['meta_title'] ) || isset( $first_fix['meta_description'] ) || isset( $first_fix['og_title'] ) || isset( $first_fix['og_description'] );

			if ( $is_new_fmt ) {
				$results     = array();
				$last_log_id = null;

				foreach ( $fixes as $fix ) {
					$pid = intval( $fix['post_id'] ?? 0 );
					if ( $pid <= 0 ) {
						continue;
					}

					$post = get_post( $pid );
					if ( ! $post || ! current_user_can( 'edit_post', $pid ) ) {
						continue;
					}

					$meta_applied = array();
					$previous     = PressArk_SEO_Resolver::read( $pid, 'meta_title' );

					$new_fmt_fields = array(
						'meta_title'       => __( 'title', 'pressark' ),
						'meta_description' => __( 'description', 'pressark' ),
						'og_title'         => __( 'OG title', 'pressark' ),
						'og_description'   => __( 'OG description', 'pressark' ),
						'og_image'         => __( 'OG image', 'pressark' ),
						'focus_keyword'    => __( 'focus keyword', 'pressark' ),
					);

					foreach ( $new_fmt_fields as $field => $label ) {
						if ( empty( $fix[ $field ] ) ) {
							continue;
						}
						// Guard: skip non-empty fields unless force=true.
						if ( ! $force ) {
							$existing = PressArk_SEO_Resolver::read( $pid, $field );
							if ( '' !== $existing ) {
								continue;
							}
						}
						PressArk_SEO_Resolver::write( $pid, $field, sanitize_text_field( $fix[ $field ] ) );
						$meta_applied[] = $label;
					}

					if ( ! empty( $meta_applied ) ) {
						$last_log_id = $this->logger->log(
							'fix_seo',
							$pid,
							get_post_type( $pid ),
							wp_json_encode( array( 'meta_title' => $previous ?? '' ) ),
							wp_json_encode( $fix )
						);
						$results[] = sprintf( __( '"%1$s": updated %2$s', 'pressark' ), $post->post_title, implode( ', ', $meta_applied ) );
					}
				}

				if ( empty( $results ) ) {
					return array( 'success' => false, 'message' => __( 'No valid fixes to apply.', 'pressark' ) );
				}

				return array(
					'success' => true,
					'message' => sprintf( __( 'SEO fixed on %d pages:', 'pressark' ), count( $results ) ) . "\n" . implode( "\n", $results ),
					'log_id'  => $last_log_id,
				);
			}

			// Legacy format: [{type: "update_meta", post_id, key, suggested_value}]
			$results     = array();
			$last_log_id = null;

			foreach ( $fixes as $fix ) {
				$fix_type = sanitize_text_field( $fix['type'] ?? '' );

				if ( 'update_meta' === $fix_type ) {
					$fix_post_id = absint( $fix['post_id'] ?? 0 );
					$fix_key     = sanitize_text_field( $fix['key'] ?? '' );
					$fix_value   = sanitize_text_field( $fix['suggested_value'] ?? ( $fix['value'] ?? '' ) );

					if ( $fix_post_id && $fix_key && current_user_can( 'edit_post', $fix_post_id ) ) {
						$resolved_key = PressArk_SEO_Resolver::resolve_key( $fix_key );
						$old           = PressArk_SEO_Resolver::read( $fix_post_id, $fix_key ) ?: get_post_meta( $fix_post_id, $resolved_key, true );
						$last_log_id   = $this->logger->log(
							'update_meta',
							$fix_post_id,
							get_post_type( $fix_post_id ),
							wp_json_encode( array( 'key' => $resolved_key, 'value' => $old ) ),
							wp_json_encode( array( 'key' => $resolved_key, 'value' => $fix_value ) )
						);
						// Resolver handles known SEO fields (including AIOSEO table writes).
						// Falls back to post_meta for unrecognized keys.
						if ( ! PressArk_SEO_Resolver::write( $fix_post_id, $fix_key, $fix_value ) ) {
							update_post_meta( $fix_post_id, $resolved_key, $fix_value );
						}
						$results[] = sprintf(
							/* translators: 1: meta key 2: post ID */
							__( 'Set %1$s on post #%2$d.', 'pressark' ),
							$resolved_key,
							$fix_post_id
						);
					}
				}
			}

			if ( empty( $results ) ) {
				return array( 'success' => false, 'message' => __( 'No fixes could be applied.', 'pressark' ) );
			}

			return array(
				'success' => true,
				'message' => __( 'SEO fixes applied: ', 'pressark' ) . implode( '; ', $results ),
				'log_id'  => $last_log_id,
			);
		}

		return array( 'success' => false, 'message' => __( 'No fixes provided.', 'pressark' ) );
	}

	/**
	 * Run a security scan.
	 *
	 * @return array
	 */
	public function scan_security( array $params = array() ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to run security scans.', 'pressark' ) );
		}

		$scanner = new PressArk_Security_Scanner();
		$result  = $scanner->scan();

		// Filter by severity when requested.
		$severity = sanitize_text_field( $params['severity'] ?? '' );
		if ( $severity && isset( $result['issues'] ) && is_array( $result['issues'] ) ) {
			$severity_order = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
			$min_level      = $severity_order[ $severity ] ?? 0;
			if ( $min_level > 0 ) {
				$result['issues'] = array_values( array_filter( $result['issues'], function ( $issue ) use ( $severity_order, $min_level ) {
					$level = $severity_order[ $issue['severity'] ?? 'low' ] ?? 1;
					return $level >= $min_level;
				} ) );
				$result['_severity_filter'] = $severity;
			}
		}

		$result['auto_fixable_ids'] = $this->available_security_fix_ids_from_report( $result );

		return array(
			'success'      => true,
			'message'      => '',
			'data'         => $result,
			'scanner_type' => 'security',
		);
	}

	/**
	 * Apply security fixes.
	 *
	 * @param array $params {fixes: array}.
	 * @return array
	 */
	public function fix_security( array $params ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'message' => __( 'You do not have permission to apply security fixes.', 'pressark' ) );
		}

		$scanner = new PressArk_Security_Scanner();
		$report  = $scanner->scan();
		$allowed = $this->available_security_fix_ids_from_report( $report );
		$fixes   = array_values( array_intersect(
			$this->normalize_requested_security_fixes( $params['fixes'] ?? array() ),
			$allowed
		) );

		if ( empty( $fixes ) ) {
			return array(
				'success' => false,
				'message' => empty( $allowed )
					? __( 'No auto-fixable security issues are currently available.', 'pressark' )
					: sprintf(
						/* translators: %s: comma-separated fix IDs */
						__( 'Requested security fixes are not currently applicable. Available fixes: %s', 'pressark' ),
						implode( ', ', $allowed )
					),
			);
		}

		$results = $scanner->apply_fixes( $fixes );

		$messages = array();
		$success  = true;

		foreach ( $results as $r ) {
			$messages[] = $r['message'] ?? '';
			if ( empty( $r['success'] ) ) {
				$success = false;
			}
		}

		return array(
			'success' => $success,
			'message' => implode( '; ', $messages ),
		);
	}

	// ── Preview Methods ─────────────────────────────────────────────────

	/**
	 * Preview for fix_seo.
	 */
	public function preview_fix_seo( array $params, array $action ): array {
		$preview    = array( 'post_title' => __( 'SEO Auto-Fix', 'pressark' ), 'post_id' => 0, 'changes' => array() );
		$seo_target = $params['post_id'] ?? ( $action['post_id'] ?? 'all' );
		$seo_fixes  = $params['fixes'] ?? ( $action['fixes'] ?? array() );

		if ( ! empty( $seo_fixes ) && is_array( $seo_fixes ) ) {
			foreach ( $seo_fixes as $fix ) {
				$fix_pid    = intval( $fix['post_id'] ?? 0 );
				$fix_ptitle = $fix_pid ? get_the_title( $fix_pid ) : __( 'Unknown', 'pressark' );

				$preview_fields = array(
					'meta_title'       => __( 'SEO Title', 'pressark' ),
					'meta_description' => __( 'Meta Description', 'pressark' ),
					'og_title'         => __( 'OG Title', 'pressark' ),
					'og_description'   => __( 'OG Description', 'pressark' ),
					'og_image'         => __( 'OG Image', 'pressark' ),
					'focus_keyword'    => __( 'Focus Keyword', 'pressark' ),
				);

				foreach ( $preview_fields as $field => $label ) {
					if ( ! empty( $fix[ $field ] ) ) {
						$current = $fix_pid ? PressArk_SEO_Resolver::read( $fix_pid, $field ) : '';
						$preview['changes'][] = array(
							/* translators: 1: field label 2: post title */
							'field'  => sprintf( '%1$s — "%2$s"', $label, $fix_ptitle ),
							'before' => $current ?: __( '(empty)', 'pressark' ),
							'after'  => $fix[ $field ],
						);
					}
				}
			}
		} else {
			if ( 'all' === $seo_target || empty( $seo_target ) ) {
				$title_key = PressArk_SEO_Resolver::resolve_key( 'meta_title' );

				$seo_pages = get_posts( array(
					'post_type'             => array( 'post', 'page' ),
					'post_status'           => 'publish',
					'posts_per_page'        => 100,
					'fields'                => 'ids',
					'meta_query'            => array(
						array( 'key' => $title_key, 'compare' => 'NOT EXISTS' ),
					),
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );

				if ( $seo_pages ) {
					update_meta_cache( 'post', $seo_pages );
				}

				foreach ( $seo_pages as $sp_id ) {
					$sp = get_post( $sp_id );
					if ( ! $sp ) {
						continue;
					}
					$preview['changes'][] = array(
						/* translators: %s: post title */
						'field'  => sprintf( __( 'SEO Title — "%s"', 'pressark' ), $sp->post_title ),
						'before' => __( '(empty)', 'pressark' ),
						'after'  => __( '(will be auto-generated from page title)', 'pressark' ),
					);
					if ( ! PressArk_SEO_Resolver::read( $sp_id, 'meta_description' ) ) {
						$preview['changes'][] = array(
							/* translators: %s: post title */
							'field'  => sprintf( __( 'Meta Desc — "%s"', 'pressark' ), $sp->post_title ),
							'before' => __( '(empty)', 'pressark' ),
							'after'  => __( '(will be auto-generated from content)', 'pressark' ),
						);
					}
				}
			} elseif ( absint( $seo_target ) > 0 ) {
				$seo_target = absint( $seo_target );
				$sp         = get_post( $seo_target );
				if ( $sp ) {
					$preview['post_title'] = $sp->post_title;
					$preview['post_id']    = $seo_target;

					$current_title = PressArk_SEO_Resolver::read( $seo_target, 'meta_title' ) ?: __( '(empty)', 'pressark' );
					$current_desc  = PressArk_SEO_Resolver::read( $seo_target, 'meta_description' ) ?: __( '(empty)', 'pressark' );

					$preview['changes'][] = array(
						/* translators: %s: post title */
						'field'  => sprintf( __( 'SEO Title — "%s"', 'pressark' ), $sp->post_title ),
						'before' => $current_title,
						'after'  => __( '(will be refreshed for this post)', 'pressark' ),
					);
					$preview['changes'][] = array(
						/* translators: %s: post title */
						'field'  => sprintf( __( 'Meta Desc — "%s"', 'pressark' ), $sp->post_title ),
						'before' => $current_desc,
						'after'  => __( '(will be refreshed for this post)', 'pressark' ),
					);
				}
			}
		}

		if ( empty( $preview['changes'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'SEO Fix', 'pressark' ),
				'before' => __( 'Missing meta data detected', 'pressark' ),
				'after'  => ( absint( $seo_target ) > 0 )
					? __( 'Refresh SEO metadata for the scoped target only', 'pressark' )
					: __( 'Auto-generate titles and descriptions for all pages without them', 'pressark' ),
			);
		}

		$seo_warnings = array();
		if ( ! empty( $seo_fixes ) && is_array( $seo_fixes ) ) {
			foreach ( $seo_fixes as $fix ) {
				$fix_meta = array_filter( $fix, fn( $k ) => 'post_id' !== $k, ARRAY_FILTER_USE_KEY );
				$seo_warnings = array_merge( $seo_warnings, PressArk_SEO_Impact_Analyzer::analyze_meta_update( intval( $fix['post_id'] ?? 0 ), $fix_meta ) );
			}
		}
		$preview['seo_warnings'] = $seo_warnings;

		return $preview;
	}

	/**
	 * Preview for fix_security.
	 */
	public function preview_fix_security( array $params, array $action ): array {
		$preview = array( 'post_title' => __( 'Security Fixes', 'pressark' ), 'post_id' => 0, 'changes' => array() );
		$scanner = new PressArk_Security_Scanner();
		$report  = $scanner->scan();
		$allowed = $this->available_security_fix_ids_from_report( $report );
		$selected_fixes = $this->normalize_requested_security_fixes( $params['fixes'] ?? ( $action['fixes'] ?? array() ) );
		if ( empty( $selected_fixes ) ) {
			$selected_fixes = $allowed;
		}
		$selected_fixes = array_values( array_intersect( $selected_fixes, $allowed ) );

		$exposed_files  = array();
		$files_to_check = array(
			ABSPATH . 'readme.html',
			ABSPATH . 'license.txt',
			ABSPATH . 'wp-config-sample.php',
		);
		foreach ( $files_to_check as $file ) {
			if ( file_exists( $file ) ) {
				$exposed_files[] = basename( $file );
			}
		}

		if ( in_array( 'delete_exposed_files', $selected_fixes, true ) && ! empty( $exposed_files ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Delete Exposed Files', 'pressark' ),
				'before' => sprintf( __( '%s — publicly accessible (reveals WP version)', 'pressark' ), implode( ', ', $exposed_files ) ),
				'after'  => __( 'Files deleted — no longer accessible', 'pressark' ),
			);
		}

		$xmlrpc_blocked = false;
		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( ABSPATH . 'wp-content/mu-plugins' );
		if ( file_exists( $mu_dir . '/pressark-disable-xmlrpc.php' ) ) {
			$xmlrpc_blocked = true;
		}
		if ( ! $xmlrpc_blocked && apply_filters( 'xmlrpc_enabled', true ) === false ) {
			$xmlrpc_blocked = true;
		}
		if ( in_array( 'disable_xmlrpc', $selected_fixes, true ) && ! $xmlrpc_blocked ) {
			$preview['changes'][] = array(
				'field'  => __( 'Disable XML-RPC', 'pressark' ),
				'before' => __( 'XML-RPC enabled — common brute-force attack vector', 'pressark' ),
				'after'  => __( 'XML-RPC disabled via mu-plugin filter', 'pressark' ),
			);
		}

		if ( empty( $preview['changes'] ) ) {
			$preview['changes'][] = array(
				'field'  => __( 'Security', 'pressark' ),
				'before' => __( 'Current state', 'pressark' ),
				'after'  => __( 'No auto-fixable issues found — all clear!', 'pressark' ),
			);
		}

		return $preview;
	}

	private function normalize_requested_security_fixes( $raw_fixes ): array {
		if ( is_string( $raw_fixes ) && '' !== trim( $raw_fixes ) ) {
			return array( sanitize_text_field( $raw_fixes ) );
		}

		if ( ! is_array( $raw_fixes ) ) {
			return array();
		}

		$normalized = array();

		if ( ! array_is_list( $raw_fixes ) ) {
			foreach ( $raw_fixes as $fix_id => $enabled ) {
				if ( $enabled ) {
					$normalized[] = sanitize_text_field( (string) $fix_id );
				}
			}
		}

		foreach ( $raw_fixes as $fix ) {
			if ( is_string( $fix ) && '' !== trim( $fix ) ) {
				$normalized[] = sanitize_text_field( $fix );
				continue;
			}

			if ( is_array( $fix ) ) {
				$fix_id = $fix['type'] ?? ( $fix['id'] ?? '' );
				if ( is_string( $fix_id ) && '' !== trim( $fix_id ) ) {
					$normalized[] = sanitize_text_field( $fix_id );
				}
			}
		}

		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	private function available_security_fix_ids_from_report( array $report ): array {
		$fix_ids = array();

		foreach ( (array) ( $report['checks'] ?? array() ) as $check ) {
			if ( empty( $check['auto_fixable'] ) ) {
				continue;
			}

			$name = sanitize_text_field( (string) ( $check['name'] ?? '' ) );
			if ( 'Exposed Files' === $name ) {
				$fix_ids[] = 'delete_exposed_files';
			} elseif ( 'XML-RPC' === $name ) {
				$fix_ids[] = 'disable_xmlrpc';
			}
		}

		return array_values( array_unique( $fix_ids ) );
	}
}
