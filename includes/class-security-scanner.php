<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full security audit scanner for WordPress sites.
 */
class PressArk_Security_Scanner {

	/**
	 * Run a comprehensive security audit.
	 *
	 * @return array Security report with score, grade, and detailed checks.
	 */
	public function scan(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$checks      = array();
		$total_score = 0;
		$max_score   = 0;

		// 1. WordPress Version (15 pts, high severity).
		$max_score  += 15;
		$wp_version  = get_bloginfo( 'version' );
		$update_data = get_site_transient( 'update_core' );
		$wp_current  = true;

		if ( $update_data && ! empty( $update_data->updates ) ) {
			$latest = $update_data->updates[0];
			if ( isset( $latest->response ) && 'upgrade' === $latest->response ) {
				$wp_current = false;
			}
		}

		if ( $wp_current ) {
			$total_score += 15;
			$checks[] = array(
				'name'         => __( 'WordPress Version', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'pass',
				'severity'     => 'high',
				'message'      => sprintf( __( 'WordPress %s is up to date.', 'pressark' ), $wp_version ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'WordPress Version', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'fail',
				'severity'     => 'high',
				'message'      => sprintf( __( 'WordPress %s is outdated. Latest: %s', 'pressark' ), $wp_version, $latest->current ?? 'unknown' ),
				'fix'          => __( 'Update WordPress to the latest version from Dashboard > Updates.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 2. PHP Version (10 pts, medium severity).
		$max_score  += 10;
		$php_version = phpversion();

		if ( version_compare( $php_version, '8.1', '>=' ) ) {
			$total_score += 10;
			$checks[] = array(
				'name'         => __( 'PHP Version', 'pressark' ),
				'category'     => 'server',
				'status'       => 'pass',
				'severity'     => 'medium',
				'message'      => sprintf( __( 'PHP %s (supported and current).', 'pressark' ), $php_version ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} elseif ( version_compare( $php_version, '8.0', '>=' ) ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'PHP Version', 'pressark' ),
				'category'     => 'server',
				'status'       => 'warning',
				'severity'     => 'medium',
				'message'      => sprintf( __( 'PHP %s is nearing end of life. Consider upgrading to 8.1+.', 'pressark' ), $php_version ),
				'fix'          => __( 'Contact your hosting provider to upgrade PHP.', 'pressark' ),
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'PHP Version', 'pressark' ),
				'category'     => 'server',
				'status'       => 'fail',
				'severity'     => 'medium',
				'message'      => sprintf( __( 'PHP %s is unsupported and insecure.', 'pressark' ), $php_version ),
				'fix'          => __( 'Urgently upgrade to PHP 8.1 or later.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 3. SSL/HTTPS (15 pts, high severity).
		$max_score += 15;
		$is_ssl     = is_ssl();
		$site_url   = get_option( 'siteurl' );
		$has_https  = str_starts_with( $site_url, 'https://' );

		if ( $is_ssl && $has_https ) {
			$total_score += 15;
			$checks[] = array(
				'name'         => __( 'SSL/HTTPS', 'pressark' ),
				'category'     => 'encryption',
				'status'       => 'pass',
				'severity'     => 'high',
				'message'      => __( 'SSL is active and site URL uses HTTPS.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} elseif ( $has_https ) {
			$total_score += 8;
			$checks[] = array(
				'name'         => __( 'SSL/HTTPS', 'pressark' ),
				'category'     => 'encryption',
				'status'       => 'warning',
				'severity'     => 'high',
				'message'      => __( 'Site URL uses HTTPS but current connection is not SSL.', 'pressark' ),
				'fix'          => __( 'Ensure your server forces HTTPS on all connections.', 'pressark' ),
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'SSL/HTTPS', 'pressark' ),
				'category'     => 'encryption',
				'status'       => 'fail',
				'severity'     => 'high',
				'message'      => __( 'Site is not using HTTPS. All traffic is unencrypted.', 'pressark' ),
				'fix'          => __( 'Install an SSL certificate and update site URL to HTTPS.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 4. Debug Mode (10 pts, medium severity).
		$max_score     += 10;
		$debug_on       = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$debug_display  = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

		if ( ! $debug_on ) {
			$total_score += 10;
			$checks[] = array(
				'name'         => __( 'Debug Mode', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'pass',
				'severity'     => 'medium',
				'message'      => __( 'WP_DEBUG is disabled.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} elseif ( $debug_on && $debug_display ) {
			$checks[] = array(
				'name'         => __( 'Debug Mode', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'fail',
				'severity'     => 'medium',
				'message'      => __( 'WP_DEBUG and WP_DEBUG_DISPLAY are enabled. Error messages are visible to the public.', 'pressark' ),
				'fix'          => __( 'Set WP_DEBUG to false in wp-config.php, or at minimum set WP_DEBUG_DISPLAY to false.', 'pressark' ),
				'auto_fixable' => false,
			);
		} else {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Debug Mode', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'warning',
				'severity'     => 'medium',
				'message'      => __( 'WP_DEBUG is enabled but display is off. OK for development, disable for production.', 'pressark' ),
				'fix'          => __( 'Set WP_DEBUG to false on production sites.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 5. Database Prefix (5 pts, low severity).
		$max_score += 5;
		global $wpdb;
		if ( 'wp_' !== $wpdb->prefix ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Database Prefix', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'Custom database prefix in use.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Database Prefix', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'Default database prefix "wp_" is in use.', 'pressark' ),
				'fix'          => __( 'Consider using a custom prefix. Changing after setup requires a migration.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 6. Admin Username (5 pts, low severity).
		$max_score  += 5;
		$admin_user  = get_user_by( 'login', 'admin' );

		if ( ! $admin_user ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Admin Username', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'No default "admin" username found.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Admin Username', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'Default "admin" username exists. This is commonly targeted by bots.', 'pressark' ),
				'fix'          => __( 'Create a new admin account with a unique username and delete the "admin" account.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 7. File Permissions (10 pts, medium severity).
		$max_score     += 10;
		$wp_config_path = ABSPATH . 'wp-config.php';

		if ( file_exists( $wp_config_path ) ) {
			$config_perms = fileperms( $wp_config_path ) & 0777;
			if ( $config_perms <= 0644 ) {
				$total_score += 10;
				$checks[] = array(
					'name'         => __( 'File Permissions', 'pressark' ),
					'category'     => 'files',
					'status'       => 'pass',
					'severity'     => 'medium',
					'message'      => sprintf( __( 'wp-config.php permissions: %s (secure).', 'pressark' ), decoct( $config_perms ) ),
					'fix'          => '',
					'auto_fixable' => false,
				);
			} elseif ( $config_perms <= 0664 ) {
				$total_score += 5;
				$checks[] = array(
					'name'         => __( 'File Permissions', 'pressark' ),
					'category'     => 'files',
					'status'       => 'warning',
					'severity'     => 'medium',
					'message'      => sprintf( __( 'wp-config.php permissions: %s (group-readable).', 'pressark' ), decoct( $config_perms ) ),
					'fix'          => __( 'Set wp-config.php to 644 or stricter.', 'pressark' ),
					'auto_fixable' => false,
				);
			} else {
				$checks[] = array(
					'name'         => __( 'File Permissions', 'pressark' ),
					'category'     => 'files',
					'status'       => 'fail',
					'severity'     => 'medium',
					'message'      => sprintf( __( 'wp-config.php permissions: %s (too permissive!).', 'pressark' ), decoct( $config_perms ) ),
					'fix'          => __( 'Set wp-config.php permissions to 644 or 600.', 'pressark' ),
					'auto_fixable' => false,
				);
			}
		} else {
			$total_score += 10;
			$checks[] = array(
				'name'         => __( 'File Permissions', 'pressark' ),
				'category'     => 'files',
				'status'       => 'pass',
				'severity'     => 'medium',
				'message'      => __( 'wp-config.php located above web root (secure).', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		}

		// 8. Exposed Sensitive Files (5 pts, low severity).
		$max_score     += 5;
		$exposed_files  = array();
		$files_to_check = array(
			'readme.html'            => ABSPATH . 'readme.html',
			'wp-config-sample.php'   => ABSPATH . 'wp-config-sample.php',
			'license.txt'            => ABSPATH . 'license.txt',
		);

		foreach ( $files_to_check as $name => $path ) {
			if ( file_exists( $path ) ) {
				$exposed_files[] = $name;
			}
		}

		if ( empty( $exposed_files ) ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Exposed Files', 'pressark' ),
				'category'     => 'files',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'No unnecessary exposed files found.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Exposed Files', 'pressark' ),
				'category'     => 'files',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => sprintf( __( 'Exposed files: %s. These reveal WordPress version info.', 'pressark' ), implode( ', ', $exposed_files ) ),
				'fix'          => __( 'Delete readme.html, license.txt, and wp-config-sample.php from the server.', 'pressark' ),
				'auto_fixable' => true,
			);
		}

		// 9. XML-RPC Status (5 pts, low severity).
		$max_score += 5;
		$xmlrpc_file_exists = file_exists( ABSPATH . 'xmlrpc.php' );
		$xmlrpc_disabled    = apply_filters( 'xmlrpc_enabled', true ) === false;

		if ( ! $xmlrpc_file_exists || $xmlrpc_disabled ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'XML-RPC', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'XML-RPC is disabled or filtered.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'XML-RPC', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'XML-RPC is enabled. This is a common brute-force attack vector.', 'pressark' ),
				'fix'          => __( 'Disable XML-RPC unless needed for Jetpack or mobile apps.', 'pressark' ),
				'auto_fixable' => true,
			);
		}

		// 10. User Enumeration (5 pts, low severity).
		$max_score += 5;
		$checks[] = array(
			'name'         => __( 'User Enumeration', 'pressark' ),
			'category'     => 'authentication',
			'status'       => 'warning',
			'severity'     => 'low',
			'message'      => __( 'Author archives are accessible by default (allows username discovery).', 'pressark' ),
			'fix'          => __( 'Consider disabling author archives or using a security plugin.', 'pressark' ),
			'auto_fixable' => false,
		);

		// 11. Login Security (5 pts, low severity).
		$max_score            += 5;
		$has_login_protection  = false;
		$security_plugins      = array(
			'wordfence/wordfence.php',
			'sucuri-scanner/sucuri.php',
			'better-wp-security/better-wp-security.php',
			'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
			'two-factor/two-factor.php',
			'wp-2fa/wp-2fa.php',
		);

		foreach ( $security_plugins as $plugin_file ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$has_login_protection = true;
				break;
			}
		}

		if ( $has_login_protection ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Login Security', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'Login protection plugin detected.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Login Security', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'No login protection plugin detected (no rate limiting, no 2FA).', 'pressark' ),
				'fix'          => __( 'Install a login security plugin like Limit Login Attempts Reloaded or Two-Factor.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 12. Plugin Updates (10 pts, medium severity).
		$max_score      += 10;
		$update_plugins  = get_site_transient( 'update_plugins' );
		$plugins_need    = isset( $update_plugins->response ) ? count( $update_plugins->response ) : 0;

		if ( 0 === $plugins_need ) {
			$total_score += 10;
			$checks[] = array(
				'name'         => __( 'Plugin Updates', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'pass',
				'severity'     => 'medium',
				'message'      => __( 'All plugins are up to date.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Plugin Updates', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'fail',
				'severity'     => 'medium',
				'message'      => sprintf( __( '%d plugin(s) have updates available.', 'pressark' ), $plugins_need ),
				'fix'          => __( 'Update all plugins from Dashboard > Updates.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 13. Theme Updates (5 pts, low severity).
		$max_score     += 5;
		$update_themes  = get_site_transient( 'update_themes' );
		$themes_need    = isset( $update_themes->response ) ? count( $update_themes->response ) : 0;

		if ( 0 === $themes_need ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Theme Updates', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'All themes are up to date.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Theme Updates', 'pressark' ),
				'category'     => 'updates',
				'status'       => 'fail',
				'severity'     => 'low',
				'message'      => sprintf( __( '%d theme(s) have updates available.', 'pressark' ), $themes_need ),
				'fix'          => __( 'Update themes from Dashboard > Updates.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 14. Inactive Plugins (5 pts, low severity).
		$max_score       += 5;
		$all_plugins      = get_plugins();
		$active_plugins   = get_option( 'active_plugins', array() );
		$inactive_count   = count( $all_plugins ) - count( $active_plugins );

		if ( 0 === $inactive_count ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Inactive Plugins', 'pressark' ),
				'category'     => 'attack_surface',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'No inactive plugins installed.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Inactive Plugins', 'pressark' ),
				'category'     => 'attack_surface',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => sprintf( __( '%d inactive plugin(s) installed. These can still be exploited.', 'pressark' ), $inactive_count ),
				'fix'          => __( 'Delete inactive plugins you no longer need.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 15. Debug Log Exposure (5 pts, low severity).
		$max_score += 5;
		$debug_log_public = defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG;
		$debug_log_file   = WP_CONTENT_DIR . '/debug.log';

		if ( ! $debug_log_public || ! file_exists( $debug_log_file ) ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Debug Log Exposure', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'No publicly accessible debug log found.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'Debug Log Exposure', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'fail',
				'severity'     => 'low',
				'message'      => __( 'Debug log at wp-content/debug.log may be publicly accessible.', 'pressark' ),
				'fix'          => __( 'Move the debug log outside the web root or block access via .htaccess.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 16. File Editor (5 pts, low severity).
		$max_score += 5;
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'File Editor', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'In-admin file editor is disabled.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'File Editor', 'pressark' ),
				'category'     => 'configuration',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'In-admin file editor is enabled. If a hacker gets admin access, they can inject malicious code.', 'pressark' ),
				'fix'          => __( "Add define('DISALLOW_FILE_EDIT', true); to wp-config.php.", 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 17. REST API User Enumeration (5 pts, low severity).
		$max_score    += 5;
		$rest_response = wp_remote_get( rest_url( 'wp/v2/users' ), array( 'timeout' => 5 ) );
		$rest_code     = wp_remote_retrieve_response_code( $rest_response );

		if ( is_wp_error( $rest_response ) || 200 !== $rest_code ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'REST API User Enumeration', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'REST API user endpoint is protected (returns 401/403).', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$checks[] = array(
				'name'         => __( 'REST API User Enumeration', 'pressark' ),
				'category'     => 'authentication',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'REST API exposes usernames at /wp-json/wp/v2/users without authentication.', 'pressark' ),
				'fix'          => __( 'Restrict the users endpoint with a security plugin or custom filter on rest_authentication_errors.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 18. Security Headers (5 pts, low severity).
		$max_score += 5;
		$headers    = null;

		// Reuse speed check transient if available, otherwise make a HEAD request.
		$speed_transient = get_transient( 'pressark_speed_check' );
		if ( $speed_transient && ! empty( $speed_transient['headers'] ) ) {
			$headers = $speed_transient['headers'];
		} else {
			$head_response = wp_remote_get( home_url( '/' ), array(
				'timeout'   => 5,
				'method'    => 'HEAD',
				'sslverify' => false,
			) );
			if ( ! is_wp_error( $head_response ) ) {
				$headers = wp_remote_retrieve_headers( $head_response );
			}
		}

		if ( $headers ) {
			$has_xcto   = ! empty( $headers['x-content-type-options'] );
			$has_xfo    = ! empty( $headers['x-frame-options'] );

			if ( $has_xcto && $has_xfo ) {
				$total_score += 5;
				$checks[] = array(
					'name'         => __( 'Security Headers', 'pressark' ),
					'category'     => 'server',
					'status'       => 'pass',
					'severity'     => 'low',
					'message'      => __( 'X-Content-Type-Options and X-Frame-Options headers are present.', 'pressark' ),
					'fix'          => '',
					'auto_fixable' => false,
				);
			} else {
				$missing = array();
				if ( ! $has_xcto ) {
					$missing[] = 'X-Content-Type-Options';
				}
				if ( ! $has_xfo ) {
					$missing[] = 'X-Frame-Options';
				}
				$checks[] = array(
					'name'         => __( 'Security Headers', 'pressark' ),
					'category'     => 'server',
					'status'       => 'warning',
					'severity'     => 'low',
					'message'      => sprintf( __( 'Missing security header(s): %s.', 'pressark' ), implode( ', ', $missing ) ),
					'fix'          => __( 'Add missing headers via your server config or a security plugin.', 'pressark' ),
					'auto_fixable' => false,
				);
			}
		} else {
			$checks[] = array(
				'name'         => __( 'Security Headers', 'pressark' ),
				'category'     => 'server',
				'status'       => 'warning',
				'severity'     => 'low',
				'message'      => __( 'Could not check security headers (request failed).', 'pressark' ),
				'fix'          => __( 'Ensure X-Content-Type-Options and X-Frame-Options headers are set.', 'pressark' ),
				'auto_fixable' => false,
			);
		}

		// 19. Directory Listing (5 pts, low severity).
		$max_score  += 5;
		$dir_response = wp_remote_get( content_url( 'uploads/' ), array( 'timeout' => 5 ) );

		if ( is_wp_error( $dir_response ) ) {
			$total_score += 5;
			$checks[] = array(
				'name'         => __( 'Directory Listing', 'pressark' ),
				'category'     => 'files',
				'status'       => 'pass',
				'severity'     => 'low',
				'message'      => __( 'Uploads directory is not directly accessible.', 'pressark' ),
				'fix'          => '',
				'auto_fixable' => false,
			);
		} else {
			$dir_body = wp_remote_retrieve_body( $dir_response );
			if ( stripos( $dir_body, 'Index of' ) !== false ) {
				$checks[] = array(
					'name'         => __( 'Directory Listing', 'pressark' ),
					'category'     => 'files',
					'status'       => 'warning',
					'severity'     => 'low',
					'message'      => __( 'Directory listing is enabled for wp-content/uploads/. Attackers can browse uploaded files.', 'pressark' ),
					'fix'          => __( 'Add "Options -Indexes" to .htaccess or disable directory listing in your server config.', 'pressark' ),
					'auto_fixable' => false,
				);
			} else {
				$total_score += 5;
				$checks[] = array(
					'name'         => __( 'Directory Listing', 'pressark' ),
					'category'     => 'files',
					'status'       => 'pass',
					'severity'     => 'low',
					'message'      => __( 'Directory listing is disabled for wp-content/uploads/.', 'pressark' ),
					'fix'          => '',
					'auto_fixable' => false,
				);
			}
		}

		// Calculate final score as percentage.
		$score_pct = $max_score > 0 ? (int) round( ( $total_score / $max_score ) * 100 ) : 0;

		$passed   = 0;
		$warnings = 0;
		$failed   = 0;
		$auto_fix = 0;

		foreach ( $checks as $check ) {
			switch ( $check['status'] ) {
				case 'pass':
					$passed++;
					break;
				case 'warning':
					$warnings++;
					break;
				case 'fail':
					$failed++;
					break;
			}
			if ( ! empty( $check['auto_fixable'] ) ) {
				$auto_fix++;
			}
		}

		$grade = match ( true ) {
			$score_pct >= 90 => 'A',
			$score_pct >= 80 => 'B',
			$score_pct >= 70 => 'C',
			$score_pct >= 60 => 'D',
			default          => 'F',
		};

		$summary = '';
		if ( $failed > 0 ) {
			$summary = sprintf(
				__( 'Your site has %d critical security issue(s) that should be addressed.', 'pressark' ),
				$failed
			);
		} elseif ( $warnings > 0 ) {
			$summary = sprintf(
				__( 'Your site has %d warning(s) to review, but no critical issues.', 'pressark' ),
				$warnings
			);
		} else {
			$summary = __( 'Your site passed all security checks!', 'pressark' );
		}

		return array(
			'score'              => $score_pct,
			'grade'              => $grade,
			'total_checks'       => count( $checks ),
			'passed'             => $passed,
			'warnings'           => $warnings,
			'failed'             => $failed,
			'checks'             => $checks,
			'auto_fixable_count' => $auto_fix,
			'summary'            => $summary,
		);
	}

	/**
	 * Apply auto-fixable security improvements.
	 *
	 * @param array $fixes Array of fix identifiers to apply.
	 * @return array Results of each fix.
	 */
	public function apply_fixes( array $fixes ): array {
		$results = array();

		foreach ( $fixes as $fix ) {
			// Support both string IDs ("disable_xmlrpc") and object format ({"type": "disable_xmlrpc"}).
			if ( is_string( $fix ) ) {
				$fix_type = sanitize_text_field( $fix );
			} else {
				$fix_type = sanitize_text_field( $fix['type'] ?? ( $fix['id'] ?? '' ) );
			}

			switch ( $fix_type ) {
				case 'delete_exposed_files':
					$results[] = $this->fix_delete_exposed_files();
					break;

				case 'disable_xmlrpc':
					$results[] = $this->fix_disable_xmlrpc();
					break;

				default:
					$results[] = array(
						'success' => false,
						'message' => sprintf( __( 'Unknown fix type: %s', 'pressark' ), $fix_type ),
					);
			}
		}

		return $results;
	}

	/**
	 * Delete exposed files (readme.html, license.txt, wp-config-sample.php).
	 */
	private function fix_delete_exposed_files(): array {
		$deleted = array();
		$files   = array(
			'readme.html'          => ABSPATH . 'readme.html',
			'license.txt'          => ABSPATH . 'license.txt',
			'wp-config-sample.php' => ABSPATH . 'wp-config-sample.php',
		);

		foreach ( $files as $name => $path ) {
			if ( file_exists( $path ) && wp_delete_file( $path ) ) {
				$deleted[] = $name;
			} elseif ( file_exists( $path ) ) {
				// wp_delete_file doesn't return value, check again.
				if ( ! file_exists( $path ) ) {
					$deleted[] = $name;
				}
			}
		}

		if ( ! empty( $deleted ) ) {
			return array(
				'success' => true,
				'message' => sprintf( __( 'Deleted: %s', 'pressark' ), implode( ', ', $deleted ) ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'No exposed files to delete.', 'pressark' ),
		);
	}

	/**
	 * Disable XML-RPC by adding a filter.
	 */
	private function fix_disable_xmlrpc(): array {
		$jetpack_active = is_plugin_active( 'jetpack/jetpack.php' );
		if ( $jetpack_active ) {
			return array(
				'success' => false,
				'message' => __( 'Cannot disable XML-RPC: Jetpack is active and requires XML-RPC for its connection to WordPress.com. Disable Jetpack first, or use Jetpack\'s built-in brute force protection instead.', 'pressark' ),
			);
		}

		// We can't permanently modify code, but we can add an mu-plugin.
		$mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( ABSPATH . 'wp-content/mu-plugins' );
		$mu_file = $mu_dir . '/pressark-disable-xmlrpc.php';

		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		$content = "<?php\n// Added by PressArk Security Scanner\n// To re-enable XML-RPC, delete this file.\nadd_filter('xmlrpc_enabled', '__return_false');\n";

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		if ( $wp_filesystem->put_contents( $mu_file, $content, FS_CHMOD_FILE ) ) {
			return array(
				'success' => true,
				'message' => __( 'XML-RPC disabled via mu-plugin.', 'pressark' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Could not create mu-plugin to disable XML-RPC. Check file permissions.', 'pressark' ),
		);
	}
}
