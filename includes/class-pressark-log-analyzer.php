<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and parses WordPress log files (debug.log, PHP error log, server logs,
 * WooCommerce logs) for AI-powered debugging assistance through chat.
 */
class PressArk_Log_Analyzer {

	/**
	 * Get available log files and their status.
	 */
	public function get_available_logs(): array {
		$logs = array();

		// WordPress debug.log.
		$debug_log = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $debug_log ) ) {
			$logs[] = array(
				'name'     => 'WordPress Debug Log',
				'path'     => 'debug.log',
				'size'     => size_format( filesize( $debug_log ) ),
				'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $debug_log ) ),
				'readable' => is_readable( $debug_log ),
			);
		} else {
			$logs[] = array(
				'name'   => 'WordPress Debug Log',
				'path'   => 'debug.log',
				'status' => 'not_found',
				'note'   => defined( 'WP_DEBUG' ) && WP_DEBUG
					? 'WP_DEBUG is on but no log file found'
					: 'WP_DEBUG is disabled — enable it in wp-config.php to start logging',
			);
		}

		// PHP error log.
		$php_error_log = ini_get( 'error_log' );
		if ( $php_error_log && file_exists( $php_error_log ) ) {
			$logs[] = array(
				'name'     => 'PHP Error Log',
				'path'     => 'php',
				'size'     => size_format( filesize( $php_error_log ) ),
				'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $php_error_log ) ),
				'readable' => is_readable( $php_error_log ),
			);
		}

		// WooCommerce logs.
		if ( class_exists( 'WooCommerce' ) && defined( 'WC_LOG_DIR' ) ) {
			$wc_log_dir = WC_LOG_DIR;
			if ( is_dir( $wc_log_dir ) ) {
				$wc_logs = glob( $wc_log_dir . '*.log' );
				if ( $wc_logs ) {
					foreach ( array_slice( $wc_logs, -5 ) as $log_file ) {
						$logs[] = array(
							'name'     => 'WooCommerce: ' . basename( $log_file, '.log' ),
							'path'     => 'wc/' . basename( $log_file ),
							'size'     => size_format( filesize( $log_file ) ),
							'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $log_file ) ),
							'readable' => is_readable( $log_file ),
						);
					}
				}
			}
		}

		// Server access log (common locations).
		$access_log_paths = array(
			'/var/log/apache2/access.log',
			'/var/log/nginx/access.log',
			'/var/log/httpd/access_log',
		);
		foreach ( $access_log_paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$logs[] = array(
					'name'     => 'Server Access Log',
					'path'     => 'access.log',
					'size'     => size_format( filesize( $path ) ),
					'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
					'readable' => true,
				);
				break;
			}
		}

		// Server error log (common locations).
		$error_log_paths = array(
			'/var/log/apache2/error.log',
			'/var/log/nginx/error.log',
			'/var/log/httpd/error_log',
		);
		foreach ( $error_log_paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$logs[] = array(
					'name'     => 'Server Error Log',
					'path'     => 'error.log',
					'size'     => size_format( filesize( $path ) ),
					'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
					'readable' => true,
				);
				break;
			}
		}

		return $logs;
	}

	/**
	 * Read the last N lines of a log file.
	 */
	public function read_log( string $log_identifier, int $lines = 50, ?string $filter = null ): array {
		$filepath = $this->resolve_log_path( $log_identifier );

		if ( ! $filepath || ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return array(
				'success' => false,
				'message' => "Log file not found or not readable: {$log_identifier}",
			);
		}

		$content   = $this->tail( $filepath, $lines );
		$log_lines = explode( "\n", $content );
		$log_lines = array_filter( $log_lines );

		if ( $filter ) {
			$log_lines = array_filter( $log_lines, function ( $line ) use ( $filter ) {
				return stripos( $line, $filter ) !== false;
			} );
		}

		$parsed  = $this->parse_entries( $log_lines );
		$summary = $this->summarize_errors( $parsed );

		return array(
			'success' => true,
			'message' => count( $log_lines ) . ' log entries' . ( $filter ? " matching \"{$filter}\"" : '' ) . '.',
			'data'    => array(
				'file'    => basename( $filepath ),
				'size'    => size_format( filesize( $filepath ) ),
				'entries' => array_values( array_slice( $parsed, -$lines ) ),
				'summary' => $summary,
			),
		);
	}

	/**
	 * Analyze logs and return actionable insights.
	 */
	public function analyze( string $log_identifier = 'debug.log' ): array {
		$result = $this->read_log( $log_identifier, 200 );
		if ( ! $result['success'] ) {
			return $result;
		}

		$entries  = $result['data']['entries'];
		$analysis = array(
			'total_entries'  => count( $entries ),
			'errors'         => 0,
			'warnings'       => 0,
			'notices'        => 0,
			'fatal'          => 0,
			'by_source'      => array(),
			'by_type'        => array(),
			'most_frequent'  => array(),
			'recent_fatals'  => array(),
		);

		$error_messages = array();

		foreach ( $entries as $entry ) {
			$level = $entry['level'] ?? 'unknown';

			if ( stripos( $level, 'fatal' ) !== false ) {
				$analysis['fatal']++;
				$analysis['recent_fatals'][] = $entry;
			} elseif ( stripos( $level, 'error' ) !== false ) {
				$analysis['errors']++;
			} elseif ( stripos( $level, 'warning' ) !== false ) {
				$analysis['warnings']++;
			} elseif ( stripos( $level, 'notice' ) !== false || stripos( $level, 'deprecated' ) !== false ) {
				$analysis['notices']++;
			}

			$source                           = $entry['source'] ?? 'unknown';
			$analysis['by_source'][ $source ] = ( $analysis['by_source'][ $source ] ?? 0 ) + 1;

			$msg_key                    = substr( $entry['message'] ?? '', 0, 100 );
			$error_messages[ $msg_key ] = ( $error_messages[ $msg_key ] ?? 0 ) + 1;
		}

		arsort( $analysis['by_source'] );
		$analysis['by_source'] = array_slice( $analysis['by_source'], 0, 10 );

		arsort( $error_messages );
		$analysis['most_frequent'] = array_slice( $error_messages, 0, 5, true );

		$analysis['recent_fatals'] = array_slice( $analysis['recent_fatals'], -3 );

		return array(
			'success' => true,
			'message' => "Log analysis: {$analysis['fatal']} fatal, {$analysis['errors']} errors, {$analysis['warnings']} warnings, {$analysis['notices']} notices.",
			'data'    => $analysis,
		);
	}

	// === PRIVATE HELPERS ===

	/**
	 * Scrub sensitive data from log content before sending to AI.
	 * Strips file paths, DB connection strings, passwords, API keys, emails, IPs.
	 */
	public function scrub_log_content( string $text ): string {
		// File system paths.
		$text = preg_replace( '#(/var/www|/home/\w+|/srv|[A-Z]:\\\\)[^\s:]+#', '[path]', $text );
		// DB connection strings.
		$text = preg_replace( '/mysql:\/\/[^\s]+/', '[db-uri]', $text );
		$text = preg_replace( '/DB_(PASSWORD|USER|HOST|NAME)\s*[=:]\s*\S+/i', '$1=[redacted]', $text );
		// API keys / tokens.
		$text = preg_replace( '/(key|token|secret|password|api_key|apikey)\s*[=:]\s*\S+/i', '$1=[redacted]', $text );
		// Emails.
		$text = preg_replace( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[email]', $text );
		// IP addresses.
		$text = preg_replace( '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[ip]', $text );
		return $text;
	}

	private function resolve_log_path( string $identifier ): ?string {
		switch ( $identifier ) {
			case 'debug.log':
			case 'debug':
				return WP_CONTENT_DIR . '/debug.log';

			case 'php':
			case 'php_error':
				$path = ini_get( 'error_log' );
				return ( $path && file_exists( $path ) ) ? $path : null;

			default:
				// WooCommerce logs.
				if ( str_starts_with( $identifier, 'wc/' ) && class_exists( 'WooCommerce' ) && defined( 'WC_LOG_DIR' ) ) {
					$filename = basename( $identifier );
					$path     = WC_LOG_DIR . $filename;
					if ( file_exists( $path ) ) {
						return $path;
					}
				}

				// Server logs.
				$server_paths = array(
					'access.log' => array( '/var/log/apache2/access.log', '/var/log/nginx/access.log' ),
					'error.log'  => array( '/var/log/apache2/error.log', '/var/log/nginx/error.log' ),
				);
				if ( isset( $server_paths[ $identifier ] ) ) {
					foreach ( $server_paths[ $identifier ] as $path ) {
						if ( file_exists( $path ) && is_readable( $path ) ) {
							return $path;
						}
					}
				}

				return null;
		}
	}

	private function tail( string $filepath, int $lines = 50 ): string {
		$file = new SplFileObject( $filepath, 'r' );
		$file->seek( PHP_INT_MAX );
		$total_lines = $file->key();

		$start  = max( 0, $total_lines - $lines );
		$output = '';
		$file->seek( $start );

		while ( ! $file->eof() ) {
			$output .= $file->fgets();
		}

		return $output;
	}

	private function parse_entries( array $lines ): array {
		$entries = array();

		foreach ( $lines as $line ) {
			$entry = array(
				'raw'     => $line,
				'level'   => 'info',
				'message' => $line,
				'source'  => 'unknown',
			);

			// Parse WordPress debug.log format: [DD-Mon-YYYY HH:MM:SS UTC] PHP Warning: ...
			if ( preg_match( '/^\[([^\]]+)\]\s*(?:PHP\s+)?(\w+):\s*(.+)$/i', $line, $matches ) ) {
				$entry['timestamp'] = $matches[1];
				$entry['level']     = strtolower( $matches[2] );
				$entry['message']   = $matches[3];
			}

			// Detect source from file path in the message.
			if ( preg_match( '/(?:\/plugins\/([^\/]+)\/)/', $line, $plugin_match ) ) {
				$entry['source'] = 'plugin: ' . $plugin_match[1];
			} elseif ( preg_match( '/(?:\/themes\/([^\/]+)\/)/', $line, $theme_match ) ) {
				$entry['source'] = 'theme: ' . $theme_match[1];
			} elseif ( strpos( $line, '/wp-includes/' ) !== false ) {
				$entry['source'] = 'wordpress-core';
			} elseif ( strpos( $line, '/wp-admin/' ) !== false ) {
				$entry['source'] = 'wordpress-admin';
			}

			// Extract file and line.
			if ( preg_match( '/in\s+(\S+\.php)\s+on\s+line\s+(\d+)/i', $line, $file_match ) ) {
				$entry['file'] = $file_match[1];
				$entry['line'] = (int) $file_match[2];
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	private function summarize_errors( array $entries ): array {
		$summary = array();
		$sources = array();

		foreach ( $entries as $entry ) {
			$level             = $entry['level'] ?? 'unknown';
			$summary[ $level ] = ( $summary[ $level ] ?? 0 ) + 1;
			if ( 'unknown' !== $entry['source'] ) {
				$sources[ $entry['source'] ] = ( $sources[ $entry['source'] ] ?? 0 ) + 1;
			}
		}

		arsort( $sources );

		return array(
			'by_level'    => $summary,
			'top_sources' => array_slice( $sources, 0, 5, true ),
		);
	}
}
