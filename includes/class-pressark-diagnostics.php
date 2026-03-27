<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressArk Diagnostics
 * Real evidence-based site analysis using WordPress internals.
 * These methods power the new diagnostic AI tools.
 */
class PressArk_Diagnostics {

	/**
	 * Routes that call_rest_endpoint must never proxy.
	 * Class constant — not filterable — to prevent plugins from removing entries.
	 */
	private const BLOCKED_ROUTE_PREFIXES = array(
		'/pressark/',
		'/wp/v2/settings',
		'/wp/v2/users',
	);

	/**
	 * Inspect WordPress hooks to find what's hooked to a given action.
	 * Returns a clean, AI-readable summary of callbacks and their source plugins.
	 */
	public function inspect_hooks( string $hook_name ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return [
				'hook'      => $hook_name,
				'callbacks' => [],
				'summary'   => "No callbacks registered on {$hook_name}.",
			];
		}

		$hook      = $wp_filter[ $hook_name ];
		$callbacks = [];

		foreach ( $hook->callbacks as $priority => $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$func    = $callback['function'];
				$trace   = $this->trace_callback_source( $func );
				$name    = $this->get_callback_name( $func );

				$callbacks[] = [
					'priority'      => $priority,
					'name'          => $name,
					'source'        => $trace['source'],
					'file'          => $trace['file'],
					'line'          => $trace['line'],
					'accepted_args' => $callback['accepted_args'],
				];
			}
		}

		// Group by source plugin for AI readability
		$by_source = [];
		foreach ( $callbacks as $cb ) {
			$detail = $cb['name'] . ' (priority ' . $cb['priority'] . ')';
			if ( $cb['file'] && $cb['line'] ) {
				$detail .= ' @ ' . $cb['file'] . ':' . $cb['line'];
			}
			$by_source[ $cb['source'] ][] = $detail;
		}

		return [
			'hook'        => $hook_name,
			'total'       => count( $callbacks ),
			'fired_count' => did_action( $hook_name ),
			'is_running'  => doing_action( $hook_name ) || doing_filter( $hook_name ),
			'callbacks'   => $callbacks,
			'by_plugin'   => $by_source,
			'summary'     => $this->summarize_hooks( $hook_name, $by_source ),
			'diagnosis'   => $this->get_hook_diagnosis( $hook_name, $callbacks ),
		];
	}

	/**
	 * Find which plugins are slowing down a specific hook
	 * by checking callback count and known heavy plugins.
	 */
	public function diagnose_slow_hook( string $hook_name ): array {
		$data       = $this->inspect_hooks( $hook_name );
		$warnings   = [];

		// Known heavy callbacks
		$heavy_patterns = [
			'woocommerce' => 'WooCommerce adds significant overhead to this hook.',
			'elementor'   => 'Elementor is registered on this hook.',
			'wpml'        => 'WPML (translation plugin) is hooked here — can cause slowdowns.',
			'acf'         => 'Advanced Custom Fields is hooked here.',
			'yoast'       => 'Yoast SEO is hooked here.',
		];

		foreach ( $data['by_plugin'] as $plugin => $callbacks ) {
			$plugin_lower = strtolower( $plugin );
			foreach ( $heavy_patterns as $pattern => $warning ) {
				if ( str_contains( $plugin_lower, $pattern ) ) {
					$warnings[] = $warning;
				}
			}
		}

		if ( $data['total'] > 20 ) {
			$warnings[] = "This hook has {$data['total']} callbacks — unusually high, may impact performance.";
		}

		return array_merge( $data, [ 'warnings' => $warnings ] );
	}

	/**
	 * Find plugins that are hooking into wp_head (adding to <head>).
	 * Too many <head> additions = slow page load.
	 */
	public function audit_head_bloat(): array {
		$data       = $this->inspect_hooks( 'wp_head' );
		$total      = $data['total'];
		$by_plugin  = $data['by_plugin'];

		$assessment = $total > 30
			? 'High — ' . $total . ' callbacks on wp_head. This is likely slowing your frontend.'
			: ( $total > 15
				? 'Moderate — ' . $total . ' callbacks on wp_head.'
				: 'Normal — ' . $total . ' callbacks on wp_head.' );

		return [
			'total_callbacks' => $total,
			'by_plugin'       => $by_plugin,
			'assessment'      => $assessment,
		];
	}

	/**
	 * Real-time homepage performance measurement.
	 * Measures actual TTFB and load time from the server's perspective.
	 *
	 * Restricted to same-host URLs to prevent SSRF.
	 * Results are cached for 15 minutes (site_brief reads this transient).
	 */
	public function measure_page_speed( string $url = '' ): array {
		if ( empty( $url ) ) {
			$url = home_url( '/' );
		}

		// SSRF guard: only allow URLs on the same host as this WordPress install.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$req_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $req_host || strcasecmp( $req_host, $site_host ) !== 0 ) {
			return [
				'url'   => $url,
				'error' => 'measure_page_speed only tests pages on this site (' . $site_host . ').',
			];
		}

		// Single HTTP request — prefer cURL for TTFB timing, fall back to wp_remote_get.
		$fetch = $this->timed_fetch( $url );
		if ( isset( $fetch['error'] ) ) {
			return [ 'url' => $url, 'error' => $fetch['error'] ];
		}

		$elapsed = $fetch['total_ms'];
		$ttfb_ms = $fetch['ttfb_ms'];
		$code    = $fetch['http_code'];
		$body    = $fetch['body'];
		$h       = $fetch['headers']; // all keys lowercase

		// Report redirects without following them (SSRF: redirect chain could escape same-host check).
		if ( $code >= 300 && $code < 400 ) {
			$location = $h['location'] ?? '';
			return array(
				'url'       => $url,
				'redirect'  => $location,
				'http_code' => $code,
				'note'      => 'Page redirects. Speed test measures the redirect response, not the destination.',
			);
		}

		// Detect caching
		$cache_status = 'none detected';
		if ( isset( $h['x-cache'] ) )           $cache_status = $h['x-cache'];
		if ( isset( $h['cf-cache-status'] ) )    $cache_status = 'Cloudflare: ' . $h['cf-cache-status'];
		if ( isset( $h['x-wp-cache'] ) )         $cache_status = $h['x-wp-cache'];
		if ( isset( $h['x-litespeed-cache'] ) )  $cache_status = 'LiteSpeed: ' . $h['x-litespeed-cache'];

		// Page size
		$page_size_kb = round( strlen( $body ) / 1024, 1 );

		// Resource counting
		$script_count = substr_count( $body, '<script' );
		$style_count  = substr_count( $body, '<link rel="stylesheet"' );

		// DOM element estimate (opening tags).
		$dom_elements  = preg_match_all( '/<[a-zA-Z][a-zA-Z0-9]*/', $body );

		// External resource references.
		$ext_scripts     = preg_match_all( '/<script[^>]+src\s*=/i', $body );
		$ext_styles      = preg_match_all( '/<link[^>]+stylesheet/i', $body );
		$img_count       = preg_match_all( '/<img[^>]+src\s*=/i', $body );
		$total_resources = $ext_scripts + $ext_styles + $img_count;

		// Assessment
		$speed_note = $elapsed < 500  ? 'Fast (under 500ms)'
					: ( $elapsed < 1500 ? 'Acceptable (under 1.5s)'
					: ( $elapsed < 3000 ? 'Slow — consider enabling page caching'
					:                     'Very slow — caching or server issue likely' ) );

		$result = [
			'url'            => $url,
			'response_code'  => $code,
			'load_time_ms'   => $elapsed,
			'load_time_s'    => round( $elapsed / 1000, 2 ),
			'ttfb_ms'        => $ttfb_ms,
			'assessment'     => $speed_note,
			'page_size_kb'   => $page_size_kb,
			'dom_elements'   => $dom_elements,
			'resources'      => [
				'external_scripts' => $ext_scripts,
				'stylesheets'      => $ext_styles,
				'images'           => $img_count,
				'total'            => $total_resources,
			],
			'cache_status'   => $cache_status,
			'script_tags'    => $script_count,
			'style_tags'     => $style_count,
			'recommendations' => $this->speed_recommendations( $elapsed, $cache_status, $script_count ),
		];

		// Cache for site_brief() and repeat calls (15 min).
		set_transient( 'pressark_speed_check', $result, 15 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Fetch a URL with timing data. Uses cURL directly when available for
	 * TTFB (CURLINFO_STARTTRANSFER_TIME), otherwise falls back to wp_remote_get.
	 *
	 * @param string $url Same-site URL to fetch.
	 * @return array{total_ms:int,ttfb_ms:?int,http_code:int,body:string,headers:array<string,string>}|array{error:string}
	 */
	private function timed_fetch( string $url ): array {
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_USERAGENT      => 'PressArk-Diagnostics/1.0',
				CURLOPT_FOLLOWLOCATION => false,
			] );

			$start = microtime( true );
			$raw   = curl_exec( $ch );
			$total = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( curl_errno( $ch ) ) {
				$err = curl_error( $ch );
				curl_close( $ch );
				return [ 'error' => 'Request failed: ' . $err ];
			}

			$http_code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$ttfb        = (int) round( curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME ) * 1000 );
			$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
			curl_close( $ch );

			$headers = [];
			foreach ( explode( "\r\n", substr( $raw, 0, $header_size ) ) as $line ) {
				if ( str_contains( $line, ':' ) ) {
					[ $key, $val ] = explode( ':', $line, 2 );
					$headers[ strtolower( trim( $key ) ) ] = trim( $val );
				}
			}

			return [
				'total_ms'  => $total,
				'ttfb_ms'   => $ttfb,
				'http_code' => $http_code,
				'body'      => substr( $raw, $header_size ),
				'headers'   => $headers,
			];
		}

		// Fallback: wp_remote_get (no TTFB available).
		$start = microtime( true );
		// wp_remote_get intentional: diagnostics hit the site's own URLs (loopback).
		// wp_safe_remote_get blocks loopback by design, so it cannot be used here.
		$response = wp_remote_get( $url, [
			'timeout'     => 15,
			'sslverify'   => false,
			'redirection' => 0,
			'user-agent'  => 'PressArk-Diagnostics/1.0',
		] );
		$total = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $key => $val ) {
			$headers[ strtolower( $key ) ] = is_array( $val ) ? end( $val ) : $val;
		}

		return [
			'total_ms'  => $total,
			'ttfb_ms'   => null,
			'http_code' => (int) wp_remote_retrieve_response_code( $response ),
			'body'      => wp_remote_retrieve_body( $response ),
			'headers'   => $headers,
		];
	}

	/**
	 * Check if search engines can crawl the site.
	 *
	 * Makes several same-site HTTP requests. Bounded by a 20-second
	 * aggregate wall-clock limit — remaining checks are skipped if exceeded.
	 */
	public function check_crawlability(): array {
		$results    = [];
		$deadline   = microtime( true ) + $this->crawlability_budget_seconds();
		$per_req_to = 5.0;

		// Check robots.txt
		$robots_url  = home_url( '/robots.txt' );
		$robots_to   = $this->remaining_http_budget( $deadline, $per_req_to );
		// wp_remote_get intentional: robots.txt is fetched from the site's own URL (loopback).
		$robots_resp = $robots_to > 0
			? wp_remote_get( $robots_url, [ 'timeout' => $robots_to ] )
			: new WP_Error( 'pressark_budget_exhausted', 'Crawlability budget exhausted before robots.txt check.' );
		$robots_body = is_wp_error( $robots_resp ) ? '' : wp_remote_retrieve_body( $robots_resp );

		$is_blocking    = str_contains( $robots_body, 'Disallow: /' )
		                  && ! str_contains( $robots_body, 'Disallow: /wp-' );
		$results['robots_txt'] = [
			'url'         => $robots_url,
			'blocking_all'=> $is_blocking,
			'content'     => $is_blocking
				? 'WARNING: robots.txt is blocking all crawlers with "Disallow: /"'
				: 'OK — robots.txt is not blocking search engines',
		];

		// Check WordPress search engine visibility setting
		$discourage = get_option( 'blog_public' ) === '0';
		$results['wp_visibility'] = [
			'discouraging_search_engines' => $discourage,
			'status' => $discourage
				? 'WARNING: WordPress is set to discourage search engines (Settings → Reading)'
				: 'OK — WordPress is visible to search engines',
		];

		// Check SSL (skip if deadline exceeded)
		if ( microtime( true ) < $deadline ) {
			$is_https    = str_starts_with( home_url(), 'https' );
			$ssl_to      = $this->remaining_http_budget( $deadline, $per_req_to );
			// wp_remote_get intentional: SSL check hits the site's own URL (loopback).
			$ssl_check   = ( $is_https && $ssl_to > 0 )
				? wp_remote_get( home_url('/'), [ 'timeout' => $ssl_to, 'sslverify' => true ] )
				: null;
			$ssl_working = $ssl_check && ! is_wp_error( $ssl_check );

			$results['ssl'] = [
				'https_configured' => $is_https,
				'ssl_valid'        => $ssl_working,
				'status'           => ! $is_https
					? 'WARNING: Site is not using HTTPS'
					: ( ! $ssl_working
						? 'WARNING: HTTPS is configured but SSL certificate may be invalid'
						: 'OK — HTTPS is working correctly' ),
			];
		}

		// Check XML sitemap (skip if deadline exceeded)
		if ( microtime( true ) < $deadline ) {
			$sitemap_urls = [
				home_url( '/sitemap.xml' ),
				home_url( '/sitemap_index.xml' ),
				home_url( '/?sitemap=1' ),
			];

			$sitemap_found = false;
			foreach ( $sitemap_urls as $url ) {
				if ( microtime( true ) >= $deadline ) break;
				$sitemap_to = $this->remaining_http_budget( $deadline, $per_req_to );
				if ( $sitemap_to <= 0 ) {
					break;
				}
				// wp_remote_head intentional: sitemap probe hits the site's own URLs (loopback).
				$resp = wp_remote_head( $url, [ 'timeout' => $sitemap_to ] );
				if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
					$sitemap_found = $url;
					break;
				}
			}

			$results['sitemap'] = [
				'found' => (bool) $sitemap_found,
				'url'   => $sitemap_found ?: null,
				'status'=> $sitemap_found
					? 'OK — XML sitemap found at ' . $sitemap_found
					: 'WARNING: No XML sitemap found. Install Yoast SEO or RankMath to generate one.',
			];
		}

		return $results;
	}

	/**
	 * Aggregate HTTP budget for crawlability probes.
	 */
	private function crawlability_budget_seconds(): float {
		$budget = (float) apply_filters( 'pressark_diagnostics_crawlability_budget', 20.0 );
		return $budget > 0 ? $budget : 20.0;
	}

	/**
	 * Cap a single HTTP timeout to the remaining aggregate budget.
	 */
	private function remaining_http_budget( float $deadline, float $default_timeout ): float {
		$remaining = max( 0.0, $deadline - microtime( true ) );
		if ( $remaining <= 0.0 ) {
			return 0.0;
		}

		return min( $default_timeout, $remaining );
	}

	/**
	 * Check WordPress email delivery.
	 */
	public function check_email_delivery(): array {
		// Check if SMTP plugin is active
		$smtp_plugins = [
			'wp-mail-smtp/wp_mail_smtp.php'         => 'WP Mail SMTP',
			'post-smtp/postman-smtp.php'             => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'          => 'Easy WP SMTP',
			'smtp-mailer/main.php'                   => 'SMTP Mailer',
			'fluent-smtp/fluent-smtp.php'            => 'FluentSMTP',
		];

		$active_plugins = get_option( 'active_plugins', [] );
		$smtp_active    = null;

		foreach ( $smtp_plugins as $plugin_file => $plugin_name ) {
			if ( in_array( $plugin_file, $active_plugins, true ) ) {
				$smtp_active = $plugin_name;
				break;
			}
		}

		// Check what's hooked to wp_mail
		$mail_hooks = $this->inspect_hooks( 'wp_mail' );
		$phpmailer_hooks = $this->inspect_hooks( 'phpmailer_init' );

		// Check admin email
		$admin_email = get_option( 'admin_email' );
		$from_email  = get_option( 'wpmailsmtp_mail_from', get_option( 'admin_email' ) );

		return [
			'smtp_plugin_active' => $smtp_active,
			'admin_email'        => $admin_email,
			'from_email'         => $from_email,
			'wp_mail_hooks'      => count( $mail_hooks['callbacks'] ),
			'phpmailer_hooks'    => count( $phpmailer_hooks['callbacks'] ),
			'assessment'         => $smtp_active
				? "SMTP configured via {$smtp_active}. Email delivery should work."
				: 'WARNING: No SMTP plugin detected. Email delivery may fail on shared hosting. Install WP Mail SMTP.',
			'recommendation'     => $smtp_active
				? 'Run a test email from ' . $smtp_active . ' settings to verify delivery.'
				: 'Install and configure WP Mail SMTP with your email provider (Gmail, SendGrid, Mailgun, etc.)',
		];
	}

	// ── D1: Site Brief (Prompt 19) ────────────────────────────────────

	/**
	 * Fast site overview for the AI to orient itself.
	 * Combines site stats + health signals + recent activity in one call.
	 * Use at the start of complex tasks instead of multiple discovery calls.
	 */
	public function site_brief(): array {
		global $wpdb;

		// Post counts.
		$counts = array();
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			$count = wp_count_posts( $type );
			if ( $count && $count->publish > 0 ) {
				$counts[ $type ] = (int) $count->publish;
			}
		}

		// Recent activity (last 5 published/modified posts).
		$recent = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_modified
			 FROM {$wpdb->posts}
			 WHERE post_status = %s
			   AND post_type IN (%s, %s, %s)
			 ORDER BY post_modified DESC
			 LIMIT 5",
			'publish', 'post', 'page', 'product'
		) );

		// Quick health signals.
		$update_count = 0;
		if ( function_exists( 'wp_get_update_data' ) ) {
			$update_data  = wp_get_update_data();
			$update_count = $update_data['counts']['total'] ?? 0;
		}

		$comment_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s", '0'
		) );

		// Page speed (cached from previous diagnostic if available).
		$cached_speed = get_transient( 'pressark_speed_check' );
		$speed_note   = $cached_speed
			? $cached_speed['assessment'] ?? ''
			: 'Run measure_page_speed for current data.';

		return array(
			'site_name'        => get_bloginfo( 'name' ),
			'url'              => home_url(),
			'content_counts'   => $counts,
			'pending_updates'  => $update_count,
			'pending_comments' => $comment_count,
			'recent_activity'  => array_map( fn( $p ) => array(
				'id'       => (int) $p->ID,
				'title'    => $p->post_title,
				'type'     => $p->post_type,
				'modified' => human_time_diff( strtotime( $p->post_modified ) ) . ' ago',
			), $recent ),
			'speed_note'       => $speed_note,
			'integrations'     => array_values( array_filter( array(
				class_exists( 'WooCommerce' )   ? 'WooCommerce'  : null,
				defined( 'ELEMENTOR_VERSION' )  ? 'Elementor'    : null,
				class_exists( 'WPSEO_Options' ) ? 'Yoast SEO'   : null,
				class_exists( 'RankMath' )      ? 'RankMath SEO' : null,
			) ) ),
			'flags'            => array_values( array_filter( array(
				$update_count  > 0 ? "{$update_count} pending updates"              : null,
				$comment_count > 0 ? "{$comment_count} comments awaiting moderation" : null,
			) ) ),
		);
	}

	// ── D2: Page Audit (Prompt 19) ────────────────────────────────────

	/**
	 * Comprehensive audit for any page/post.
	 * Combines content signals + SEO signals + Elementor audit if applicable.
	 * Single call gives the AI everything needed to propose fixes.
	 */
	public function page_audit( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		// Content signals.
		$content        = wp_strip_all_tags( $post->post_content );
		$word_count     = str_word_count( $content );
		$internal_links = substr_count( $post->post_content, home_url() );

		// SEO plugin detection for template awareness.
		$seo_plugin = PressArk_SEO_Resolver::detect();

		// SEO signals — read via resolver (handles all plugins + AIOSEO table).
		$seo_title = PressArk_SEO_Resolver::read( $post_id, 'meta_title' );
		$seo_desc  = PressArk_SEO_Resolver::read( $post_id, 'meta_description' );

		// Determine source: custom (has per-post meta), template (plugin active, no meta), or missing.
		$title_source = $seo_title ? 'custom' : ( $seo_plugin ? 'template' : 'missing' );
		$desc_source  = $seo_desc  ? 'custom' : ( $seo_plugin ? 'template' : 'missing' );

		$result = array(
			'id'      => $post_id,
			'title'   => $post->post_title,
			'type'    => $post->post_type,
			'url'     => get_permalink( $post_id ),
			'content' => array(
				'word_count'     => $word_count,
				'internal_links' => $internal_links,
				'last_modified'  => human_time_diff( strtotime( $post->post_modified ) ) . ' ago',
			),
			'seo'     => array(
				'title'        => $seo_title ?: '(not set)',
				'description'  => $seo_desc  ?: '(not set)',
				'title_length' => strlen( $seo_title ),
				'desc_length'  => strlen( $seo_desc ),
				'title_source' => $title_source,
				'desc_source'  => $desc_source,
				'seo_plugin'   => $seo_plugin,
			),
			'flags'   => array(),
		);

		// Content flags.
		if ( $word_count < 300 ) {
			$result['flags'][] = 'thin_content: ' . $word_count . ' words';
		}

		// SEO title flags — only 'missing' penalizes; 'template' is informational.
		if ( 'missing' === $title_source ) {
			$result['flags'][] = 'missing_seo_title';
		} elseif ( 'template' === $title_source ) {
			$result['flags'][] = 'seo_title_templated';
		}

		// SEO description flags.
		if ( 'missing' === $desc_source ) {
			$result['flags'][] = 'missing_seo_description';
		} elseif ( 'template' === $desc_source ) {
			$result['flags'][] = 'seo_desc_templated';
		}

		if ( $internal_links === 0 ) {
			$result['flags'][] = 'no_internal_links';
		}

		// Softened length thresholds (was >60 / >160).
		if ( strlen( $seo_title ) > 70 ) {
			$result['flags'][] = 'seo_title_too_long';
		}
		if ( strlen( $seo_desc ) > 200 ) {
			$result['flags'][] = 'seo_desc_too_long';
		}

		// Elementor audit if applicable.
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! empty( $elementor_data ) && defined( 'ELEMENTOR_VERSION' ) ) {
			$elementor           = new PressArk_Elementor();
			$el_audit            = $elementor->audit_page( $post_id );
			$result['elementor'] = array(
				'score'  => $el_audit['score']  ?? null,
				'issues' => $el_audit['issues'] ?? array(),
				'stats'  => $el_audit['stats']  ?? array(),
			);
			// Add Elementor flags to main flags list.
			foreach ( $el_audit['issues']['high'] ?? array() as $issue ) {
				$result['flags'][] = 'elementor_' . $issue['type'];
			}
		}

		// Score: only 'missing_' prefixed flags subtract 15 pts; 'templated' flags don't penalize.
		$result['score'] = max( 0, 100
			- ( substr_count( implode( ' ', $result['flags'] ), 'missing' ) * 15 )
			- ( $word_count < 300 ? 20 : 0 )
			- ( $internal_links === 0 ? 10 : 0 )
		);

		return $result;
	}

	// ── C3: Store Health (Prompt 19) ──────────────────────────────────

	/**
	 * One-call WooCommerce store health overview.
	 * Returns business metrics + flagged issues.
	 */
	public function store_health(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'error' => 'WooCommerce is not active.' );
		}

		global $wpdb;

		// Order counts by status.
		$statuses     = wc_get_order_statuses();
		$order_counts = array();
		foreach ( array_keys( $statuses ) as $status ) {
			$slug = str_replace( 'wc-', '', $status );
			$order_counts[ $slug ] = wc_orders_count( $slug );
		}

		// Revenue (last 30 days) — HPOS-safe via wc_order_stats.
		// wc_order_stats is populated by WC Analytics regardless of HPOS mode.
		// Single aggregate query — no object hydration needed.
		$revenue = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM( total_sales )
			 FROM {$wpdb->prefix}wc_order_stats
			 WHERE status = 'wc-completed'
			   AND date_created >= %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
		) );

		// Also get all-time revenue in same pattern.
		$revenue_alltime = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM( total_sales )
			 FROM {$wpdb->prefix}wc_order_stats
			 WHERE status = %s",
			'wc-completed'
		) );

		// Product issues.
		$no_image = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = %s
			   AND pm.meta_value IS NULL",
			'_thumbnail_id', 'product', 'publish'
		) );

		$out_of_stock = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = %s",
			'_stock_status', 'outofstock'
		) );

		// Stuck orders — HPOS-safe via wc_order_stats.
		$stuck = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}wc_order_stats
			 WHERE status = 'wc-processing'
			   AND date_created < %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-48 hours' ) )
		) );

		$flags = array_values( array_filter( array(
			$stuck             > 0 ? "{$stuck} orders stuck in processing >48h"  : null,
			(int) $out_of_stock > 0 ? "{$out_of_stock} products out of stock"    : null,
			(int) $no_image    > 0 ? "{$no_image} products missing images"       : null,
		) ) );

		// Detect HPOS status.
		$hpos_enabled = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		$hpos_sync    = 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

		$result = array(
			'orders'            => $order_counts,
			'revenue_30d'       => strip_tags( wc_price( $revenue ) ),
			'revenue_alltime'   => strip_tags( wc_price( $revenue_alltime ) ),
			'stuck_orders'      => $stuck,
			'out_of_stock'      => (int) $out_of_stock,
			'products_no_image' => (int) $no_image,
			'flags'             => $flags,
			'health_score'      => max( 0, 100 - ( $stuck * 10 )
			                                   - ( min( (int) $out_of_stock, 5 ) * 5 )
			                                   - ( min( (int) $no_image, 5 ) * 3 ) ),
			'order_storage'     => array(
				'mode'         => $hpos_enabled ? 'hpos' : 'legacy',
				'label'        => $hpos_enabled ? 'HPOS (Custom Tables)' : 'Legacy (wp_posts)',
				'sync_enabled' => $hpos_sync,
				'note'         => $hpos_enabled
					? 'High Performance Order Storage is active. Order data lives in wp_wc_orders.'
					: 'Legacy post-based order storage. Consider enabling HPOS for better performance.',
			),
		);

		// Action Scheduler diagnostics.
		if ( class_exists( 'ActionScheduler' ) ) {
			$as_table = $wpdb->prefix . 'actionscheduler_actions';

			$pending = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$as_table} WHERE status = %s", 'pending'
			) );
			$failed  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$as_table} WHERE status = %s", 'failed'
			) );
			$oldest  = $wpdb->get_var( $wpdb->prepare(
				"SELECT MIN(scheduled_date_gmt) FROM {$as_table} WHERE status = %s", 'pending'
			) );

			$result['action_scheduler'] = array(
				'pending'        => $pending,
				'failed'         => $failed,
				'oldest_pending' => $oldest,
				'queue_healthy'  => $pending < 1000 && $failed < 50,
			);

			if ( $failed > 50 ) {
				$result['flags'][] = "{$failed} failed Action Scheduler tasks — possible webhook or WC sync issues. Check WooCommerce → Status → Action Scheduler.";
			}
			if ( $pending > 1000 ) {
				$result['flags'][] = "{$pending} pending scheduled tasks — queue may be stuck. Check if WP-Cron is running.";
			}
		}

		return $result;
	}

	// ── Enhancement 23B-1: REST Route Discovery ─────────────────────

	/**
	 * Discover all registered REST API routes on this WordPress installation.
	 * Groups by namespace to show which plugins expose REST APIs.
	 *
	 * @param array $params Optional: 'namespace' to filter (e.g. 'wc/v3', 'wp/v2')
	 * @return array
	 */
	public function discover_rest_routes( array $params = array() ): array {
		$server    = rest_get_server();
		$routes    = $server->get_routes();
		$filter_ns = trim( $params['namespace'] ?? '', '/' );

		$by_namespace = array();
		$total        = 0;

		foreach ( $routes as $route => $handlers ) {
			if ( $route === '/' ) continue;

			preg_match( '|^/([^/]+/v[^/]+)|', $route, $ns_match );
			$namespace = $ns_match[1] ?? 'other';

			if ( $filter_ns && $namespace !== $filter_ns ) continue;

			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ?? array() ) as $method ) {
					if ( ! in_array( $method, $methods, true ) ) {
						$methods[] = $method;
					}
				}
			}

			$by_namespace[ $namespace ][] = array(
				'route'   => $route,
				'methods' => $methods,
			);
			$total++;
		}

		$summary = array();
		foreach ( $by_namespace as $ns => $ns_routes ) {
			$summary[] = array(
				'namespace'   => $ns,
				'route_count' => count( $ns_routes ),
				'plugin_hint' => $this->guess_namespace_owner( $ns ),
			);
		}

		usort( $summary, fn( $a, $b ) => $b['route_count'] <=> $a['route_count'] );

		return array(
			'total_routes'   => $total,
			'namespace_count'=> count( $by_namespace ),
			'summary'        => $summary,
			'routes'         => $filter_ns ? ( $by_namespace[ $filter_ns ] ?? array() ) : array(),
			'hint'           => 'Use namespace parameter to explore a specific API. '
			                  . 'Use call_rest_endpoint to call any route internally.',
		);
	}

	private function guess_namespace_owner( string $ns ): string {
		$map = array(
			'wp/v2'          => 'WordPress Core',
			'wc/v1'          => 'WooCommerce',
			'wc/v2'          => 'WooCommerce',
			'wc/v3'          => 'WooCommerce',
			'wc/store'       => 'WooCommerce Blocks',
			'yoast/v1'       => 'Yoast SEO',
			'rank-math/v1'   => 'RankMath SEO',
			'contact-form-7' => 'Contact Form 7',
			'wpforms/v1'     => 'WPForms',
			'gf/v2'          => 'Gravity Forms',
			'elementor/v1'   => 'Elementor',
			'acf/v3'         => 'Advanced Custom Fields',
			'learndash/v1'   => 'LearnDash',
			'buddypress/v1'  => 'BuddyPress',
			'jetpack/v4'     => 'Jetpack',
		);
		return $map[ $ns ] ?? 'Unknown plugin';
	}

	// ── Enhancement 23B-2: Internal REST Dispatch ────────────────────

	/**
	 * Call any registered REST API endpoint internally.
	 * No HTTP request — runs through WP REST infrastructure at near-zero latency.
	 * Full permission checks apply (runs as current user).
	 *
	 * @param array $params route, method, params
	 * @return array
	 */
	public function call_rest_endpoint( array $params ): array {
		$route  = sanitize_text_field( $params['route'] ?? '' );
		$method = strtoupper( sanitize_text_field( $params['method'] ?? 'GET' ) );
		$body   = $params['params'] ?? array();

		if ( empty( $route ) ) {
			return array( 'error' => 'route is required. Use discover_rest_routes to find available routes.' );
		}

		if ( ! str_starts_with( $route, '/' ) ) {
			$route = '/' . $route;
		}

		// Block sensitive routes.
		foreach ( self::BLOCKED_ROUTE_PREFIXES as $prefix ) {
			if ( str_starts_with( $route, $prefix ) ) {
				return array( 'error' => 'This route is not available through call_rest_endpoint.' );
			}
		}

		$allowed_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
		if ( ! in_array( $method, $allowed_methods, true ) ) {
			return array( 'error' => 'Invalid method. Use GET, POST, PUT, PATCH, or DELETE.' );
		}

		$request = new \WP_REST_Request( $method, $route );

		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body_params( $body );
			if ( ! empty( $body ) ) {
				$request->set_header( 'content-type', 'application/json' );
			}
		}

		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();

		if ( $status >= 400 ) {
			$error_message = is_array( $data ) ? ( $data['message'] ?? 'REST error' ) : 'REST error';
			return array(
				'success' => false,
				'status'  => $status,
				'error'   => $error_message,
				'data'    => $data,
			);
		}

		if ( is_array( $data ) && isset( $data[0] ) && count( $data ) > 20 ) {
			$trimmed = array_slice( $data, 0, 20 );
			return array(
				'success'  => true,
				'status'   => $status,
				'data'     => $trimmed,
				'total'    => count( $data ),
				'trimmed'  => true,
				'note'     => 'Response trimmed to 20 items. Use per_page/offset params to paginate.',
			);
		}

		return array(
			'success' => true,
			'status'  => $status,
			'data'    => $data,
		);
	}

	// ── Enhancement 23B-3: Object Cache Diagnostic ───────────────────

	/**
	 * Diagnose the WordPress object cache setup.
	 * Detects Redis/Memcached/APCu and provides performance recommendations.
	 */
	public function diagnose_cache(): array {
		$ext_cache = wp_using_ext_object_cache();
		$drop_in   = file_exists( WP_CONTENT_DIR . '/object-cache.php' );

		$provider = 'None';
		if ( $drop_in ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read-only diagnostics on a local drop-in file.
			$content = (string) file_get_contents( WP_CONTENT_DIR . '/object-cache.php', false, null, 0, 2048 );
			if ( str_contains( $content, 'Redis' ) )          $provider = 'Redis';
			elseif ( str_contains( $content, 'Memcached' ) )   $provider = 'Memcached';
			elseif ( str_contains( $content, 'APCu' ) )        $provider = 'APCu';
			else                                                $provider = 'Unknown drop-in';
		}

		$supports = array();
		if ( function_exists( 'wp_cache_supports' ) ) {
			foreach ( array( 'flush_runtime', 'flush_group', 'get_multiple', 'set_multiple' ) as $feature ) {
				$supports[ $feature ] = wp_cache_supports( $feature );
			}
		}

		$stats = array();
		if ( $ext_cache && function_exists( 'wp_cache_get_stats' ) ) {
			$stats = wp_cache_get_stats();
		}

		return array(
			'persistent_cache' => $ext_cache,
			'drop_in_exists'   => $drop_in,
			'provider'         => $provider,
			'supports'         => $supports,
			'stats'            => $stats,
			'assessment'       => $ext_cache
				? "Persistent object cache active via {$provider}. Admin and queries benefit from in-memory caching."
				: 'No persistent object cache. Every request rebuilds options/meta from MySQL.',
			'recommendation'   => ! $ext_cache
				? 'Install Redis Object Cache plugin with Redis server for significant performance improvement. Typical impact: 50-80% faster admin page loads.'
				: null,
		);
	}

	// --- Private helpers ---

	/**
	 * Trace a callback to its source plugin/theme/core AND specific file + line.
	 *
	 * @return array{source: string, file: ?string, line: ?int}
	 */
	private function trace_callback_source( $func ): array {
		$default = array( 'source' => 'Unknown', 'file' => null, 'line' => null );

		try {
			if ( is_array( $func ) ) {
				$ref = new ReflectionMethod( $func[0], $func[1] );
			} elseif ( is_string( $func ) && function_exists( $func ) ) {
				$ref = new ReflectionFunction( $func );
			} elseif ( $func instanceof Closure ) {
				$ref = new ReflectionFunction( $func );
			} elseif ( is_object( $func ) ) {
				$ref = new ReflectionMethod( $func, '__invoke' );
			} else {
				return array( 'source' => 'WordPress Core', 'file' => null, 'line' => null );
			}

			$file = $ref->getFileName();
			$line = $ref->getStartLine();

			if ( ! $file ) {
				return array( 'source' => 'WordPress Core', 'file' => null, 'line' => null );
			}

			// Build a short relative path for readability.
			$rel_file    = $file;
			$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			if ( str_contains( $file, $content_dir ) ) {
				$rel_file = str_replace( $content_dir, 'wp-content', $file );
			} elseif ( str_contains( $file, ABSPATH ) ) {
				$rel_file = str_replace( ABSPATH, '', $file );
			}

			// Map file path to plugin/theme/core name.
			$source = basename( dirname( $file ) );
			if ( str_contains( $file, '/plugins/' ) ) {
				preg_match( '|/plugins/([^/]+)/|', $file, $m );
				$source = $m[1] ?? 'Unknown Plugin';
			} elseif ( str_contains( $file, '/themes/' ) ) {
				preg_match( '|/themes/([^/]+)/|', $file, $m );
				$source = 'Theme: ' . ( $m[1] ?? 'Unknown' );
			} elseif ( str_contains( $file, '/wp-includes/' ) || str_contains( $file, '/wp-admin/' ) ) {
				$source = 'WordPress Core';
			} elseif ( str_contains( $file, '/mu-plugins/' ) ) {
				$source = 'MU-Plugin: ' . basename( $file, '.php' );
			}

			return array(
				'source' => $source,
				'file'   => $rel_file,
				'line'   => $line ?: null,
			);

		} catch ( \ReflectionException $e ) {
			return $default;
		}
	}

	private function get_callback_name( $func ): string {
		if ( is_string( $func ) ) return $func;
		if ( is_array( $func ) ) {
			$class  = is_object( $func[0] ) ? get_class( $func[0] ) : $func[0];
			return $class . '::' . $func[1];
		}
		if ( $func instanceof Closure ) return 'anonymous function';
		return 'unknown';
	}

	private function summarize_hooks( string $hook, array $by_plugin ): string {
		$plugin_list = implode( ', ', array_keys( $by_plugin ) );
		$total       = array_sum( array_map( 'count', $by_plugin ) );
		return "{$total} callbacks on {$hook} from: {$plugin_list}.";
	}

	private function get_hook_diagnosis( string $hook, array $callbacks ): ?string {
		$fired = did_action( $hook );
		if ( $fired === 0 ) {
			return "'{$hook}' has not fired yet in this request. Callbacks registered but not yet executed.";
		}
		if ( count( $callbacks ) === 0 ) {
			return "'{$hook}' has fired {$fired} time(s) but has no callbacks registered.";
		}
		return "'{$hook}' has fired {$fired} time(s) with " . count( $callbacks ) . " registered callback(s).";
	}

	private function speed_recommendations( int $ms, string $cache, int $scripts ): array {
		$recs = [];
		if ( $ms > 1500 && str_contains( strtolower( $cache ), 'miss' ) ) {
			$recs[] = 'Page cache is not serving this request. Check your caching plugin configuration.';
		}
		if ( $ms > 3000 ) {
			$recs[] = 'Response time exceeds 3 seconds. Check server resources and database query times.';
		}
		if ( $scripts > 15 ) {
			$recs[] = "Found {$scripts} script tags. Consider combining/minifying JavaScript.";
		}
		if ( empty( $recs ) && $ms < 800 ) {
			$recs[] = 'Performance looks good from the server perspective.';
		}
		return $recs;
	}

	/**
	 * Profile queries for a given URL by loading the page with SAVEQUERIES.
	 * Returns the slowest queries, duplicates, and total stats.
	 *
	 * @param string $url Relative or absolute URL to profile.
	 * @return array Query profiling results.
	 */
	public function profile_page_queries( string $url = '' ): array {
		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			return array(
				'error' => 'SAVEQUERIES is not enabled. Add define( \'SAVEQUERIES\', true ); to wp-config.php to use query profiling.',
			);
		}

		if ( empty( $wpdb->queries ) ) {
			return array(
				'total_queries'   => 0,
				'total_time_ms'   => 0,
				'slow_queries'    => array(),
				'duplicates'      => array(),
				'summary'         => 'No queries captured. SAVEQUERIES is on but no queries have run yet.',
			);
		}

		$queries    = $wpdb->queries;
		$total_time = 0;
		$by_sql     = array();
		$all        = array();

		foreach ( $queries as $q ) {
			$sql      = $q[0];
			$time_s   = (float) $q[1];
			$caller   = $q[2] ?? '';
			$time_ms  = round( $time_s * 1000, 2 );
			$total_time += $time_ms;

			// Normalize SQL for duplicate detection (strip varying values).
			$normalized = preg_replace( '/\b\d+\b/', '?', $sql );
			$normalized = preg_replace( "/'.+?'/", "'?'", $normalized );

			if ( ! isset( $by_sql[ $normalized ] ) ) {
				$by_sql[ $normalized ] = array( 'count' => 0, 'total_ms' => 0, 'example' => $sql );
			}
			$by_sql[ $normalized ]['count']++;
			$by_sql[ $normalized ]['total_ms'] += $time_ms;

			$all[] = array(
				'sql'     => mb_substr( $sql, 0, 300 ),
				'time_ms' => $time_ms,
				'caller'  => $this->simplify_caller( $caller ),
			);
		}

		// Top 10 slowest queries.
		usort( $all, fn( $a, $b ) => $b['time_ms'] <=> $a['time_ms'] );
		$slow = array_slice( $all, 0, 10 );

		// Duplicates (run 3+ times).
		$duplicates = array();
		foreach ( $by_sql as $norm => $info ) {
			if ( $info['count'] >= 3 ) {
				$duplicates[] = array(
					'count'    => $info['count'],
					'total_ms' => round( $info['total_ms'], 2 ),
					'example'  => mb_substr( $info['example'], 0, 200 ),
				);
			}
		}
		usort( $duplicates, fn( $a, $b ) => $b['count'] <=> $a['count'] );
		$duplicates = array_slice( $duplicates, 0, 10 );

		return array(
			'total_queries'   => count( $queries ),
			'total_time_ms'   => round( $total_time, 1 ),
			'unique_queries'  => count( $by_sql ),
			'slow_queries'    => $slow,
			'duplicates'      => $duplicates,
			'summary'         => sprintf(
				'%d queries (%.1f ms total), %d unique patterns, %d duplicate patterns.',
				count( $queries ), $total_time, count( $by_sql ), count( $duplicates )
			),
		);
	}

	/**
	 * Simplify a query caller string for readability.
	 */
	private function simplify_caller( string $caller ): string {
		// Take the last meaningful frame.
		$parts = explode( ', ', $caller );
		$last  = end( $parts );
		return mb_substr( trim( $last ), 0, 100 );
	}
}
