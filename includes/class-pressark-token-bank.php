<?php
/**
 * PressArk token bank client.
 *
 * ICU-first client for the Node metering service, with token-era aliases kept
 * for backwards compatibility during migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Token_Bank {

	private string $server_url;
	private string $site_token;

	public function __construct() {
		$this->server_url = defined( 'PRESSARK_TOKEN_BANK_URL' )
			? PRESSARK_TOKEN_BANK_URL
			: get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' );
		$this->site_token = (string) get_option( 'pressark_site_token', '' );
	}

	// ── Per-install Handshake ────────────────────────────────────

	/**
	 * Perform a Freemius-verified handshake with the Token Bank.
	 *
	 * Sends this site's Freemius install_id to the bank, which verifies it
	 * against the Freemius Developer API, determines the tier, and returns
	 * a unique per-install site_token.
	 *
	 * Call on: plugin activation, license changes, account connection.
	 *
	 * @since 5.0.0
	 * @return array { success, site_token, tier, icu_budget } or { success: false, error }.
	 */
	public function handshake(): array {
		if ( $this->is_byok() ) {
			return array( 'success' => true, 'byok' => true );
		}

		// Build payload — install_id is OPTIONAL. Without it, the bank
		// issues a provisional (unverified, free-tier) token so the plugin
		// works immediately on fresh installs before Freemius opt-in.
		$install_id = $this->get_freemius_install_id();
		$site_nonce = (string) get_option( 'pressark_site_nonce', '' );

		// Generate site_nonce on the fly if missing (e.g. plugin updated
		// but not re-activated — activation hook didn't fire).
		// Uses wp_hash (HMAC of site keys + salt) instead of wp_generate_uuid4()
		// so that concurrent requests in separate PHP processes all produce the
		// SAME nonce — eliminating the race condition where multiple requests
		// generate different random nonces and only the first one matches on
		// the bank, permanently locking out the site.
		if ( '' === $site_nonce ) {
			$site_nonce = wp_hash( 'pressark_site_nonce::' . home_url() );
			update_option( 'pressark_site_nonce', $site_nonce, false );
		}

		$installation_uuid = $this->installation_uuid();

		$payload = array(
			'site_url'          => home_url(),
			'site_nonce'        => $site_nonce,
			'installation_uuid' => $installation_uuid,
		);
		if ( $install_id ) {
			$payload['install_id'] = $install_id;
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( $this->site_token ) {
			$headers['x-pressark-token'] = $this->site_token;
		}

		// Use wp_remote_post (NOT wp_safe_remote_post) — the bank URL is
		// configured by the site admin, not user input. wp_safe_remote_post
		// blocks private IPs and breaks localhost development.
		$response = wp_remote_post(
			trailingslashit( $this->server_url ) . 'auth/handshake',
			array(
				'timeout' => 10,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || empty( $data['success'] ) ) {
			$error_msg = $data['error']['message'] ?? 'Handshake failed (HTTP ' . $status_code . ')';
			return array( 'success' => false, 'error' => $error_msg );
		}

		// Store the per-install site_token (verified OR provisional).
		$site_token = (string) ( $data['site_token'] ?? '' );
		if ( $site_token ) {
			update_option( 'pressark_site_token', $site_token, false );
			$this->site_token = $site_token;
		}

		// Track verification state so ensure_handshaked() can upgrade later.
		$verified = ! empty( $data['verified'] );
		update_option( 'pressark_handshake_verified', $verified, false );

		// Cache the bank-determined tier.
		$tier = (string) ( $data['tier'] ?? 'free' );
		update_option( 'pressark_cached_tier', $tier, false );
		delete_transient( 'pressark_token_status_' . $this->user_id() );
		delete_transient( 'pressark_license_cache_' . get_current_user_id() );

		return array(
			'success'    => true,
			'site_token' => $site_token,
			'tier'       => $tier,
			'icu_budget' => (int) ( $data['icu_budget'] ?? 0 ),
			'verified'   => $verified,
			'provisional' => ! $verified,
		);
	}

	/**
	 * Ensure this site has a valid site_token.
	 *
	 * If no token exists, triggers a handshake. Uses transient to avoid
	 * repeated handshake attempts on every request.
	 *
	 * @since 5.0.0
	 */
	public function ensure_handshaked(): void {
		if ( $this->site_token ) {
			// Already have a token. Check if we should try upgrading
			// from provisional to verified (Freemius may have connected since).
			$verified = (bool) get_option( 'pressark_handshake_verified', false );
			if ( $verified ) {
				return; // Fully verified — nothing to do.
			}

			// Have a provisional token — try to upgrade if Freemius is now connected.
			$install_id = $this->get_freemius_install_id();
			if ( ! $install_id ) {
				return; // Still no Freemius — provisional token is fine.
			}

			// Freemius connected! Try to upgrade (rate-limited).
			$cache_key = 'pressark_handshake_upgrade_' . md5( $this->installation_uuid() ?: $this->site_domain() );
			if ( false !== get_transient( $cache_key ) ) {
				return;
			}
			$result = $this->handshake();
			$ttl = ! empty( $result['verified'] ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
			set_transient( $cache_key, 1, $ttl );
			return;
		}

		// No token at all — need to handshake (provisional or verified).
		$cache_key = 'pressark_handshake_attempted_' . md5( $this->installation_uuid() ?: $this->site_domain() );
		if ( false !== get_transient( $cache_key ) ) {
			return; // Already attempted recently.
		}

		$result = $this->handshake();

		if ( ! empty( $result['success'] ) ) {
			set_transient( $cache_key, 1, DAY_IN_SECONDS );
		} else {
			// Short retry so the plugin recovers quickly.
			set_transient( $cache_key, 0, 2 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Get the Freemius install ID for this site.
	 *
	 * @since 5.0.0
	 */
	private function get_freemius_install_id(): string {
		if ( ! function_exists( 'pressark_fs' ) ) {
			return '';
		}

		$fs = pressark_fs();
		if ( ! $fs ) {
			return '';
		}

		$site = $fs->get_site();
		if ( ! $site || empty( $site->id ) ) {
			return '';
		}

		return (string) $site->id;
	}

	public function reserve( int $estimated_icus, string $reservation_id, string $tier = 'free', string $model = '' ): array {
		if ( $this->is_byok() || $this->is_proxy_mode() ) {
			return array(
				'ok'             => true,
				'icus_remaining' => PHP_INT_MAX,
				'tokens_remaining' => PHP_INT_MAX,
			);
		}

		$response = $this->post(
			'reserve',
			array(
				'site_domain'    => $this->site_domain(),
				'user_id'        => $this->user_id(),
				'icus_requested' => $estimated_icus,
				'tokens_requested' => $estimated_icus,
				'reservation_id' => $reservation_id,
				'tier'           => PressArk_Entitlements::normalize_tier( $tier ),
				'icu_budget'     => PressArk_Entitlements::icu_budget( $tier ),
				'tokens_limit'   => PressArk_Entitlements::token_budget( $tier ),
				'model'          => $model,
			),
			5
		);

		if ( is_wp_error( $response ) ) {
			return $this->offline_reserve( $estimated_icus, $tier );
		}

		$data        = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Auto-heal: register if unregistered, then retry the reserve once.
		if ( 403 === $status_code && isset( $data['error']['code'] ) && 'unregistered_site' === $data['error']['code'] ) {
			$this->register_site( $tier );
			$response = $this->post(
				'reserve',
				array(
					'site_domain'      => $this->site_domain(),
					'user_id'          => $this->user_id(),
					'icus_requested'   => $estimated_icus,
					'tokens_requested' => $estimated_icus,
					'reservation_id'   => $reservation_id,
					'tier'             => PressArk_Entitlements::normalize_tier( $tier ),
					'icu_budget'       => PressArk_Entitlements::icu_budget( $tier ),
					'tokens_limit'     => PressArk_Entitlements::token_budget( $tier ),
					'model'            => $model,
				),
				5
			);
			if ( is_wp_error( $response ) ) {
				return $this->offline_reserve( $estimated_icus, $tier );
			}
			$data        = json_decode( wp_remote_retrieve_body( $response ), true );
			$status_code = (int) wp_remote_retrieve_response_code( $response );
		}

		$data = $this->normalize_status( is_array( $data ) ? $data : array(), $tier );

		if ( 429 === $status_code || ! empty( $data['at_limit'] ) ) {
			return array(
				'ok'              => false,
				'error'           => 'token_limit_reached',
				'icus_remaining'  => (int) ( $data['icus_remaining'] ?? 0 ),
				'tokens_remaining'=> (int) ( $data['tokens_remaining'] ?? 0 ),
			);
		}

		if ( ! empty( $data ) ) {
			$this->cache_status( $data );
		}

		return array(
			'ok'               => true,
			'icus_remaining'   => (int) ( $data['icus_remaining'] ?? 0 ),
			'tokens_remaining' => (int) ( $data['tokens_remaining'] ?? 0 ),
		);
	}

	public function resolve_icus( array $usage ): array {
		$defaults = array(
			'model'              => '',
			'input_tokens'       => 0,
			'output_tokens'      => 0,
			'cache_read_tokens'  => 0,
			'cache_write_tokens' => 0,
		);
		$usage = array_merge( $defaults, $usage );

		$response = $this->post( 'resolve-icus', $usage, 5 );
		if ( is_wp_error( $response ) ) {
			return $this->resolve_icus_locally( $usage );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->resolve_icus_locally( $usage );
		}

		return $data;
	}

	public function settle( string $reservation_id, array|int $actual_usage, string $tier = 'free' ): array {
		if ( $this->is_byok() || $this->is_proxy_mode() ) {
			return $this->get_status();
		}

		$payload = is_array( $actual_usage )
			? $actual_usage
			: array(
				'actual_icus'  => (int) $actual_usage,
				'actual_tokens'=> (int) $actual_usage,
			);

		$response = $this->post(
			'settle',
			array(
				'site_domain'       => $this->site_domain(),
				'user_id'           => $this->user_id(),
				'reservation_id'    => $reservation_id,
				'actual_icus'       => (int) ( $payload['actual_icus'] ?? 0 ),
				'actual_tokens'     => (int) ( $payload['actual_tokens'] ?? 0 ),
				'input_tokens'      => (int) ( $payload['input_tokens'] ?? 0 ),
				'output_tokens'     => (int) ( $payload['output_tokens'] ?? 0 ),
				'cache_read_tokens' => (int) ( $payload['cache_read_tokens'] ?? 0 ),
				'cache_write_tokens'=> (int) ( $payload['cache_write_tokens'] ?? 0 ),
				'tier'              => PressArk_Entitlements::normalize_tier( $tier ),
				'icu_budget'        => PressArk_Entitlements::icu_budget( $tier ),
				'tokens_limit'      => PressArk_Entitlements::token_budget( $tier ),
				'model'             => (string) ( $payload['model'] ?? '' ),
			),
			5
		);

		if ( is_wp_error( $response ) ) {
			return $this->deduct( (int) ( $payload['actual_icus'] ?? 0 ), $tier );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_status( is_array( $data ) ? $data : array(), $tier );

		if ( $data ) {
			$this->cache_status( $data );
		}

		return $data ?: $this->get_cached_status();
	}

	public function release( string $reservation_id ): void {
		if ( $this->is_byok() || $this->is_proxy_mode() ) {
			return;
		}

		$this->post(
			'release',
			array(
				'site_domain'    => $this->site_domain(),
				'user_id'        => $this->user_id(),
				'reservation_id' => $reservation_id,
			),
			3,
			false
		);
	}

	public function get_status(): array {
		$cached = get_transient( $this->status_cache_key() );
		if ( false !== $cached ) {
			return $this->normalize_status( is_array( $cached ) ? $cached : array() );
		}

		$tier = $this->get_current_tier();
		$data = $this->fetch_status( $tier );

		// Auto-heal: if the bank says we're unregistered, register and retry once.
		if ( isset( $data['error']['code'] ) && 'unregistered_site' === $data['error']['code'] ) {
			$this->register_site( $tier );
			$data = $this->fetch_status( $tier );
		}

		$data = $this->normalize_status( is_array( $data ) ? $data : array(), $tier );

		if ( $data ) {
			$this->cache_status( $data );
		}

		return $data ?: $this->get_cached_status();
	}

	/**
	 * Fetch raw status from the bank's /check endpoint.
	 *
	 * @since 5.0.0
	 */
	private function fetch_status( string $tier ): array {
		// Ensure we have a token before hitting the bank.
		$this->ensure_handshaked();

		if ( ! $this->site_token ) {
			return $this->get_cached_status();
		}

		$url = add_query_arg(
			array(
				'site_domain'       => $this->site_domain(),
				'installation_uuid' => $this->installation_uuid(),
				'user_id'           => $this->user_id(),
				'tier'              => $tier,
				'icu_budget'        => PressArk_Entitlements::icu_budget( $tier ),
				'tokens_limit'      => PressArk_Entitlements::token_budget( $tier ),
			),
			trailingslashit( $this->server_url ) . 'check'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => $this->auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->get_cached_status();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	public function get_remaining_icus(): int {
		if ( $this->is_byok() ) {
			return PHP_INT_MAX;
		}

		$status = $this->get_status();
		return max( 0, (int) ( $status['icus_remaining'] ?? 0 ) );
	}

	public function get_remaining_tokens(): int {
		return $this->get_remaining_icus();
	}

	public function get_credits( int $user_id = 0 ): array {
		if ( $this->is_byok() ) {
			return array(
				'credits'         => array(),
				'total_remaining' => 0,
				'total_purchased' => 0,
			);
		}

		$this->ensure_handshaked();
		if ( ! $this->site_token ) {
			return array(
				'credits'         => array(),
				'total_remaining' => 0,
				'total_purchased' => 0,
			);
		}

		$url = add_query_arg(
			array(
				'site_domain'       => $this->site_domain(),
				'installation_uuid' => $this->installation_uuid(),
				'user_id'           => $user_id > 0 ? $user_id : $this->user_id(),
			),
			trailingslashit( $this->server_url ) . 'credits'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => $this->auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'credits'         => array(),
				'total_remaining' => 0,
				'total_purchased' => 0,
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array(
			'credits'         => array(),
			'total_remaining' => 0,
			'total_purchased' => 0,
		);
	}

	public function purchase_credits( int $user_id, string $pack_type, string $payment_id, string $tier = '' ): array {
		$tier = $tier ?: $this->get_current_tier();

		$response = $this->post(
			'purchase-credits',
			array(
				'site_domain' => $this->site_domain(),
				'user_id'     => $user_id,
				'pack_type'   => $pack_type,
				'tier'        => PressArk_Entitlements::normalize_tier( $tier ),
				'payment_id'  => $payment_id,
			),
			5
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		delete_transient( $this->status_cache_key() );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array( 'success' => false, 'error' => 'Malformed bank response.' );
	}

	public function get_multipliers( bool $force_refresh = false ): array {
		$cache_key = 'pressark_bank_multipliers';

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$this->ensure_handshaked();
		if ( ! $this->site_token ) {
			$cached = get_option( 'pressark_bank_multipliers_cache' );
			return is_array( $cached ) ? $cached : $this->default_multiplier_config();
		}

		$response = wp_remote_get(
			trailingslashit( $this->server_url ) . 'multipliers',
			array(
				'timeout' => 5,
				'headers' => $this->auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$cached = get_option( 'pressark_bank_multipliers_cache' );
			return is_array( $cached ) ? $cached : $this->default_multiplier_config();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : $this->default_multiplier_config();

		set_transient( $cache_key, $data, DAY_IN_SECONDS );
		update_option( 'pressark_bank_multipliers_cache', $data, false );

		return $data;
	}

	public function deduct( int $icus_used, string $tier = 'free' ): array {
		if ( $this->is_byok() ) {
			return $this->get_status();
		}

		$response = $this->post(
			'deduct',
			array(
				'site_domain' => $this->site_domain(),
				'user_id'     => $this->user_id(),
				'icus_used'   => $icus_used,
				'tokens_used' => $icus_used,
				'tier'        => PressArk_Entitlements::normalize_tier( $tier ),
				'icu_budget'  => PressArk_Entitlements::icu_budget( $tier ),
				'tokens_limit'=> PressArk_Entitlements::token_budget( $tier ),
			),
			3
		);

		if ( is_wp_error( $response ) ) {
			return $this->get_cached_status();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_status( is_array( $data ) ? $data : array(), $tier );
		if ( $data ) {
			$this->cache_status( $data );
		}

		return $data ?: $this->get_cached_status();
	}

	public function has_tokens(): bool {
		if ( $this->is_byok() ) {
			return true;
		}

		$status = $this->get_status();
		return empty( $status['at_limit'] );
	}

	public function set_tier( int $user_id, string $tier ): array {
		$response = $this->post(
			'set-tier',
			array(
				'site_domain' => $this->site_domain(),
				'user_id'     => $user_id,
				'tier'        => PressArk_Entitlements::normalize_tier( $tier ),
			),
			5
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		delete_transient( 'pressark_token_status_' . $user_id );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array( 'success' => false, 'error' => 'Malformed bank response.' );
	}

	/**
	 * Send an authenticated AI proxy request through the token bank.
	 *
	 * The bank performs reserve -> forward -> settle atomically, so the plugin
	 * only needs to submit the request payload and the expected ICU hold.
	 *
	 * @since 5.3.0
	 */
	public function proxy_request(
		string $route,
		array $request_body,
		string $tier = 'free',
		string $model = '',
		string $provider = 'openrouter',
		int $estimated_icus = 0,
		int $timeout = 20
	) {
		$route = in_array( $route, array( 'chat', 'summarize' ), true ) ? $route : 'chat';

		if ( $estimated_icus <= 0 ) {
			$estimated_icus = 'summarize' === $route ? 1200 : 5000;
		}

		return $this->post(
			'v1/' . $route,
			array(
				'site_domain'    => $this->site_domain(),
				'user_id'        => $this->user_id(),
				'tier'           => PressArk_Entitlements::normalize_tier( $tier ),
				'model'          => $model,
				'provider'       => $provider,
				'stream'         => false,
				'estimated_icus' => $estimated_icus,
				'icu_budget'     => PressArk_Entitlements::icu_budget( $tier ),
				'request_body'   => $request_body,
			),
			$timeout
		);
	}

	/**
	 * Dedicated summarize proxy helper so context compression can use its own
	 * endpoint and model selection without sharing chat-specific routing.
	 *
	 * @since 5.3.0
	 */
	public function summarize_proxy(
		array $request_body,
		string $tier = 'free',
		string $model = '',
		string $provider = 'openrouter',
		int $estimated_icus = 1200,
		int $timeout = 20
	) {
		return $this->proxy_request( 'summarize', $request_body, $tier, $model, $provider, $estimated_icus, $timeout );
	}

	private function post( string $path, array $payload, int $timeout = 5, bool $blocking = true, bool $is_retry = false ) {
		$payload['installation_uuid'] = $payload['installation_uuid'] ?? $this->installation_uuid();
		$payload['site_domain']       = $payload['site_domain'] ?? $this->site_domain();

		// Ensure we have a site_token before any API call.
		if ( ! $is_retry ) {
			$this->ensure_handshaked();
		}

		// Re-read from DB in case another process just completed handshake.
		if ( ! $this->site_token ) {
			$this->site_token = (string) get_option( 'pressark_site_token', '' );
		}

		// If we still have no token, return a user-friendly error.
		if ( ! $this->site_token ) {
			return new \WP_Error( 'pressark_no_token', __( 'PressArk is still connecting to the credit service. Please try again in a moment.', 'pressark' ) );
		}

		$response = wp_remote_post(
			trailingslashit( $this->server_url ) . ltrim( $path, '/' ),
			array(
				'timeout'  => $timeout,
				'blocking' => $blocking,
				'headers'  => $this->auth_headers(),
				'body'     => wp_json_encode( $payload ),
			)
		);

		// Auto-re-handshake on auth failure (401), but only once.
		if ( ! $is_retry && ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$err  = $body['error']['code'] ?? '';
			if ( 401 === $code && in_array( $err, array( 'invalid_site_token', 'missing_credentials' ), true ) ) {
				// Clear the cached token — it's invalid.
				$this->site_token = '';
				delete_option( 'pressark_site_token' );
				$hs = $this->handshake();
				if ( ! empty( $hs['success'] ) ) {
					return $this->post( $path, $payload, $timeout, $blocking, true );
				}
			}
		}

		return $response;
	}

	/**
	 * Build auth headers for Token Bank requests.
	 *
	 * Uses per-install site_token from Freemius-verified handshake.
	 *
	 * @since 5.0.0
	 */
	private function auth_headers(): array {
		$headers = array( 'Content-Type' => 'application/json' );

		// Re-read in case handshake just stored a new token.
		if ( ! $this->site_token ) {
			$this->site_token = (string) get_option( 'pressark_site_token', '' );
		}

		if ( $this->site_token ) {
			$headers['x-pressark-token'] = $this->site_token;
		}

		return $headers;
	}

	private function is_byok(): bool {
		return PressArk_Entitlements::is_byok();
	}

	/**
	 * Whether the bank proxy handles reserve/settle internally.
	 *
	 * In proxy mode, the bank's /v1/chat endpoint does atomic
	 * reserve → forward → settle. The PHP client skips its own
	 * reserve/settle/release calls to avoid double-counting.
	 *
	 * @since 5.0.0
	 */
	private function is_proxy_mode(): bool {
		return PressArk_AI_Connector::is_proxy_mode();
	}

	private function get_current_tier(): string {
		return PressArk_Entitlements::normalize_tier( (string) get_option( 'pressark_cached_tier', 'free' ) );
	}

	private function cache_status( array $data ): void {
		$data['snapshot_at'] = current_time( 'mysql' );
		set_transient( $this->status_cache_key(), $data, 5 * MINUTE_IN_SECONDS );
		update_option( 'pressark_last_token_status_' . $this->user_id(), $data, false );
	}

	private function get_cached_status(): array {
		$last = get_option( 'pressark_last_token_status_' . $this->user_id() );
		if ( is_array( $last ) ) {
			$last['offline'] = true;
			return $this->normalize_status( $last );
		}

		$tier  = $this->get_current_tier();
		$limit = PressArk_Entitlements::icu_budget( $tier );

		return array(
			'icus_used'         => 0,
			'icus_reserved'     => 0,
			'icu_budget'        => $limit,
			'icus_remaining'    => $limit,
			'credits_remaining' => 0,
			'total_available'   => $limit,
			'total_remaining'   => $limit,
			'next_reset_at'     => '',
			'billing_period_start' => '',
			'billing_period_end'   => '',
			'uses_anniversary_reset' => false,
			'percent_used'      => 0,
			'at_limit'          => false,
			'warn'              => false,
			'tier'              => $tier,
			'offline'           => true,
			'tokens_used'       => 0,
			'tokens_reserved'   => 0,
			'tokens_limit'      => $limit,
			'tokens_remaining'  => $limit,
		);
	}

	private function offline_reserve( int $estimated_icus, string $tier ): array {
		$user_id = $this->user_id();
		$budget  = PressArk_Entitlements::icu_budget( $tier );
		$last    = get_option( 'pressark_last_token_status_' . $user_id );

		if ( is_array( $last ) && isset( $last['snapshot_at'] ) ) {
			$ledger      = new PressArk_Cost_Ledger();
			$local_since = $ledger->get_settled_icus_since( $user_id, (string) $last['snapshot_at'] );
			$remaining   = max( 0, (int) ( $last['icus_remaining'] ?? $budget ) - $local_since );

			if ( $estimated_icus > $remaining ) {
				return array(
					'ok'               => false,
					'offline'          => true,
					'error'            => 'token_limit_reached',
					'icus_remaining'   => $remaining,
					'tokens_remaining' => $remaining,
				);
			}

			return array(
				'ok'               => true,
				'offline'          => true,
				'icus_remaining'   => max( 0, $remaining - $estimated_icus ),
				'tokens_remaining' => max( 0, $remaining - $estimated_icus ),
			);
		}

		$emergency_cap = min( $budget, 50000 );
		if ( $estimated_icus > $emergency_cap ) {
			return array(
				'ok'               => false,
				'offline'          => true,
				'error'            => 'token_limit_reached',
				'icus_remaining'   => 0,
				'tokens_remaining' => 0,
			);
		}

		return array(
			'ok'               => true,
			'offline'          => true,
			'icus_remaining'   => max( 0, $emergency_cap - $estimated_icus ),
			'tokens_remaining' => max( 0, $emergency_cap - $estimated_icus ),
		);
	}

	private function resolve_icus_locally( array $usage ): array {
		$config       = $this->get_multipliers();
		$model        = (string) ( $usage['model'] ?? '' );
		$model_to_map = (array) ( $config['model_to_class'] ?? array() );
		$default_class = (string) ( $config['default_class'] ?? 'standard' );
		$model_class  = (string) ( $model_to_map[ $model ] ?? $default_class );
		$classes      = (array) ( $config['classes'] ?? array() );
		$weights      = (array) ( $config['cache_weights'] ?? array( 'cache_read' => 0.1, 'cache_write' => 1.25 ) );
		$multiplier   = $classes[ $model_class ] ?? $classes[ $default_class ] ?? array( 'input' => 10, 'output' => 30 );
		$input_tokens = max( 0, (int) ( $usage['input_tokens'] ?? 0 ) );
		$output_tokens = max( 0, (int) ( $usage['output_tokens'] ?? 0 ) );
		$cache_read   = max( 0, (int) ( $usage['cache_read_tokens'] ?? 0 ) );
		$cache_write  = max( 0, (int) ( $usage['cache_write_tokens'] ?? 0 ) );
		$non_cached   = max( 0, $input_tokens - $cache_read - $cache_write );

		$breakdown = array(
			'input_icus'       => $this->scale_icu( $non_cached * (int) ( $multiplier['input'] ?? 10 ) ),
			'output_icus'      => $this->scale_icu( $output_tokens * (int) ( $multiplier['output'] ?? 30 ) ),
			'cache_read_icus'  => $this->scale_icu( $cache_read * (int) ( $multiplier['input'] ?? 10 ) * (float) ( $weights['cache_read'] ?? 0.1 ) ),
			'cache_write_icus' => $this->scale_icu( $cache_write * (int) ( $multiplier['input'] ?? 10 ) * (float) ( $weights['cache_write'] ?? 1.25 ) ),
		);

		return array(
			'icu_total'   => array_sum( $breakdown ),
			'model_class' => $model_class,
			'multiplier'  => array(
				'input'  => (int) ( $multiplier['input'] ?? 10 ),
				'output' => (int) ( $multiplier['output'] ?? 30 ),
			),
			'breakdown'   => $breakdown,
			'raw_tokens'  => array(
				'input'       => $input_tokens,
				'output'      => $output_tokens,
				'cache_read'  => $cache_read,
				'cache_write' => $cache_write,
			),
		);
	}

	private function normalize_status( array $data, string $tier = '' ): array {
		$tier           = $tier ? PressArk_Entitlements::normalize_tier( $tier ) : $this->get_current_tier();
		$icu_budget     = (int) ( $data['icu_budget'] ?? $data['tokens_limit'] ?? PressArk_Entitlements::icu_budget( $tier ) );
		$icus_used      = (int) ( $data['icus_used'] ?? $data['tokens_used'] ?? 0 );
		$icus_reserved  = (int) ( $data['icus_reserved'] ?? $data['tokens_reserved'] ?? 0 );
		$monthly_remaining = (int) ( $data['monthly_remaining'] ?? max( 0, $icu_budget - $icus_used ) );
		$credits_remaining = (int) ( $data['credits_remaining'] ?? 0 );
		$icus_remaining = (int) ( $data['icus_remaining'] ?? $data['tokens_remaining'] ?? max( 0, $monthly_remaining + $credits_remaining - $icus_reserved ) );
		$total_remaining = (int) ( $data['total_remaining'] ?? $icus_remaining );

		$data['tier']              = $tier;
		$data['icu_budget']        = $icu_budget;
		$data['icus_used']         = $icus_used;
		$data['icus_reserved']     = $icus_reserved;
		$data['icus_remaining']    = $icus_remaining;
		$data['monthly_remaining'] = $monthly_remaining;
		$data['monthly_exhausted'] = ! empty( $data['monthly_exhausted'] ) || $monthly_remaining <= 0;
		$data['using_purchased_credits'] = ! empty( $data['using_purchased_credits'] ) || ( $monthly_remaining <= 0 && $credits_remaining > 0 );
		$data['credits_remaining'] = $credits_remaining;
		$data['raw_tokens_used']   = (int) ( $data['raw_tokens_used'] ?? $icus_used );
		$data['total_available']   = (int) ( $data['total_available'] ?? ( $icu_budget + $credits_remaining ) );
		$data['total_remaining']   = $total_remaining;
		$data['next_reset_at']     = (string) ( $data['next_reset_at'] ?? '' );
		$data['billing_period_start'] = (string) ( $data['billing_period_start'] ?? '' );
		$data['billing_period_end']   = (string) ( $data['billing_period_end'] ?? '' );
		$data['uses_anniversary_reset'] = ! empty( $data['uses_anniversary_reset'] );
		$data['tokens_used']       = $icus_used;
		$data['tokens_reserved']   = $icus_reserved;
		$data['tokens_limit']      = $icu_budget;
		$data['tokens_remaining']  = $icus_remaining;
		$data['bonus_tokens']      = (int) ( $data['bonus_tokens'] ?? $credits_remaining );

		return $data;
	}

	private function default_multiplier_config(): array {
		return array(
			'classes' => array(
				'standard' => array(
					'input'  => 10,
					'output' => 30,
				),
			),
			'model_to_class' => array(),
			'default_class'  => 'standard',
			'cache_weights'  => array(
				'cache_read'  => 0.1,
				'cache_write' => 1.25,
			),
		);
	}

	private function scale_icu( float $value ): int {
		return $value > 0 ? (int) ceil( $value ) : 0;
	}

	/**
	 * Register (or update) this site with the token bank.
	 *
	 * Called on plugin activation, tier changes, and auto-healed when the bank
	 * returns "unregistered_site". Idempotent — safe to call repeatedly.
	 *
	 * @since 5.0.0
	 */
	public function register_site( string $tier = '' ): array {
		// v5.2.0: Unified through handshake(). The bank determines the tier
		// server-side via Freemius verification (or defaults to free for provisional).
		return $this->handshake();
	}

	/**
	 * Ensure this site is registered with the bank.
	 *
	 * Uses a transient to avoid calling /register on every request.
	 * Automatically called when the bank rejects with "unregistered_site".
	 *
	 * @since 5.0.0
	 */
	public function ensure_registered(): void {
		// v5.2.0: Unified through handshake().
		$this->ensure_handshaked();
	}

	public static function current_site_identity(): string {
		$url = (string) home_url();
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$host = rtrim( $host, '.' );
		if ( '' === $host ) {
			return '';
		}

		$path = trim( $path );
		if ( '' === $path || '/' === $path ) {
			return $host;
		}

		$path = '/' . ltrim( $path, '/' );
		$path = rtrim( $path, '/' );

		return $host . $path;
	}

	public static function ensure_installation_uuid(): string {
		$uuid = strtolower( (string) get_option( 'pressark_installation_uuid', '' ) );
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid ) ) {
			return $uuid;
		}

		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = strtolower( (string) wp_generate_uuid4() );
		} else {
			$uuid = strtolower( self::fallback_uuid4() );
		}

		update_option( 'pressark_installation_uuid', $uuid, false );
		return $uuid;
	}

	private function site_domain(): string {
		return self::current_site_identity();
	}

	private function installation_uuid(): string {
		return self::ensure_installation_uuid();
	}

	private function user_id(): int {
		return (int) get_current_user_id();
	}

	private function status_cache_key(): string {
		return 'pressark_token_status_' . $this->user_id();
	}

	private static function fallback_uuid4(): string {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $bytes ), 4 )
		);
	}
}
