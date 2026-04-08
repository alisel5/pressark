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
	private const CONTACT_STATE_OPTION   = 'pressark_bank_contact_state';
	private const HANDSHAKE_STATE_OPTION = 'pressark_bank_handshake_snapshot';

	public function __construct() {
		$this->server_url = defined( 'PRESSARK_TOKEN_BANK_URL' )
			? PRESSARK_TOKEN_BANK_URL
			: get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' );
		$this->site_token = (string) get_option( 'pressark_site_token', '' );
	}

	public function get_contact_snapshot(): array {
		return $this->normalize_contact_snapshot( get_option( self::CONTACT_STATE_OPTION, array() ) );
	}

	public function get_handshake_snapshot(): array {
		return $this->normalize_handshake_snapshot( get_option( self::HANDSHAKE_STATE_OPTION, array() ) );
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
			$this->note_bank_response( 'auth/handshake', $response );
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || empty( $data['success'] ) ) {
			$this->note_bank_response( 'auth/handshake', $response );
			$error_msg = $data['error']['message'] ?? 'Handshake failed (HTTP ' . $status_code . ')';
			return array( 'success' => false, 'error' => $error_msg );
		}

		$this->note_bank_response( 'auth/handshake', $response );

		// Store the per-install site_token (verified OR provisional).
		$site_token = (string) ( $data['site_token'] ?? '' );
		if ( $site_token ) {
			update_option( 'pressark_site_token', $site_token, false );
			$this->site_token = $site_token;
		}

		// Track verification state so ensure_handshaked() can upgrade later.
		$verified = ! empty( $data['verified'] );
		update_option( 'pressark_handshake_verified', $verified, false );
		$this->record_handshake_success(
			array(
				'tier'        => (string) ( $data['tier'] ?? 'free' ),
				'verified'    => $verified,
				'provisional' => ! $verified,
				'site_token'  => $site_token,
			)
		);

		// Cache the bank-determined tier.
		$tier = (string) ( $data['tier'] ?? 'free' );
		update_option( 'pressark_cached_tier', $tier, false );
		delete_transient( 'pressark_token_status_' . $this->user_id() );
		delete_transient( 'pressark_license_cache_' . get_current_user_id() );

		$billing_state = $this->normalize_billing_state(
			is_array( $data['billing_state'] ?? null ) ? (array) $data['billing_state'] : array(),
			array(
				'verified'    => $verified,
				'provisional' => ! $verified,
			)
		);

		return array(
			'success'                => true,
			'site_token'             => $site_token,
			'tier'                   => $tier,
			'icu_budget'             => (int) ( $data['icu_budget'] ?? 0 ),
			'verified'               => $verified,
			'provisional'            => ! $verified,
			'billing_authority'      => $this->billing_state_to_legacy_authority( $billing_state ),
			'billing_state'          => $billing_state,
			'billing_service_state'  => (string) $billing_state['service_state'],
			'billing_handshake_state'=> (string) $billing_state['handshake_state'],
			'billing_spend_source'   => (string) $billing_state['spend_source'],
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

	public function reserve( int $estimated_icus, string $reservation_id, string $tier = 'free', string $model = '', int $estimated_raw_tokens = 0 ): array {
		$trace_context = $this->merge_trace_context(
			array(
				'reservation_id' => $reservation_id,
				'route'          => 'reserve',
			)
		);

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
				'site_domain'      => $this->site_domain(),
				'user_id'          => $this->user_id(),
				'icus_requested'   => $estimated_icus,
				'tokens_requested' => $estimated_icus,
				'estimated_raw_tokens' => max( 0, $estimated_raw_tokens ),
				'reservation_id'   => $reservation_id,
				'model'            => $model,
			),
			5,
			true,
			false,
			$trace_context
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
					'estimated_raw_tokens' => max( 0, $estimated_raw_tokens ),
					'reservation_id'   => $reservation_id,
					'model'            => $model,
				),
				5,
				true,
				false,
				$trace_context
			);
			if ( is_wp_error( $response ) ) {
				return $this->offline_reserve( $estimated_icus, $tier );
			}
			$data        = json_decode( wp_remote_retrieve_body( $response ), true );
			$status_code = (int) wp_remote_retrieve_response_code( $response );
		}

		$data = $this->normalize_status( is_array( $data ) ? $data : array(), $tier );

		if ( 429 === $status_code || ! empty( $data['at_limit'] ) ) {
			return array_merge(
				array(
				'ok'              => false,
				'error'           => 'token_limit_reached',
				'icus_remaining'  => (int) ( $data['icus_remaining'] ?? 0 ),
				'tokens_remaining'=> (int) ( $data['tokens_remaining'] ?? 0 ),
				),
				$this->billing_state_aliases( is_array( $data['billing_state'] ?? null ) ? (array) $data['billing_state'] : array() )
			);
		}

		if ( ! empty( $data ) ) {
			$this->cache_status( $data );
		}

		return array_merge(
			array(
				'ok'               => true,
				'icus_remaining'   => (int) ( $data['icus_remaining'] ?? 0 ),
				'tokens_remaining' => (int) ( $data['tokens_remaining'] ?? 0 ),
			),
			$this->billing_state_aliases( is_array( $data['billing_state'] ?? null ) ? (array) $data['billing_state'] : array() )
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
		$trace_context = $this->merge_trace_context(
			array(
				'reservation_id' => $reservation_id,
				'route'          => 'settle',
			)
		);

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
				'model'             => (string) ( $payload['model'] ?? '' ),
			),
			5,
			true,
			false,
			$trace_context
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

		$trace_context = $this->merge_trace_context(
			array(
				'reservation_id' => $reservation_id,
				'route'          => 'release',
			)
		);

		$this->post(
			'release',
			array(
				'site_domain'    => $this->site_domain(),
				'user_id'        => $this->user_id(),
				'reservation_id' => $reservation_id,
			),
			3,
			false,
			false,
			$trace_context
		);
	}

	/**
	 * Fetch a sanitized bank-side trace by correlation or reservation.
	 *
	 * @param string $correlation_id Correlation ID.
	 * @param string $reservation_id Reservation ID fallback.
	 * @param int    $limit          Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_trace( string $correlation_id = '', string $reservation_id = '', int $limit = 80 ): array {
		$this->ensure_handshaked();
		if ( ! $this->site_token ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'correlation_id' => sanitize_text_field( $correlation_id ),
				'reservation_id' => sanitize_text_field( $reservation_id ),
				'limit'          => max( 1, min( 200, $limit ) ),
			),
			trailingslashit( $this->server_url ) . 'trace'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => $this->auth_headers(
					$this->merge_trace_context(
						array(
							'correlation_id' => $correlation_id,
							'reservation_id' => $reservation_id,
							'route'          => 'trace',
						)
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->note_bank_response( 'trace', $response );
			return array();
		}

		$this->note_bank_response( 'trace', $response );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data['events'] ?? null ) ? $data['events'] : array();
	}

	public function get_status(): array {
		if ( $this->is_byok() ) {
			return $this->build_byok_status();
		}

		$cached = get_transient( $this->status_cache_key() );
		if ( false !== $cached ) {
			return $this->normalize_status( is_array( $cached ) ? $cached : array() );
		}

		$data = $this->fetch_status();

		// Auto-heal: if the bank says we're unregistered, register and retry once.
		if ( isset( $data['error']['code'] ) && 'unregistered_site' === $data['error']['code'] ) {
			$this->register_site();
			$data = $this->fetch_status();
		}

		$data = $this->normalize_status( is_array( $data ) ? $data : array() );

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
	private function fetch_status(): array {
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
			$this->note_bank_response( 'check', $response );
			return $this->get_cached_status();
		}

		$this->note_bank_response( 'check', $response );

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
			$this->note_bank_response( 'credits', $response );
			return array(
				'credits'         => array(),
				'total_remaining' => 0,
				'total_purchased' => 0,
			);
		}

		$this->note_bank_response( 'credits', $response );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array(
			'credits'         => array(),
			'total_remaining' => 0,
			'total_purchased' => 0,
		);
	}

	public function get_billing_catalog( bool $force_refresh = false ): array {
		$cache_key = 'pressark_bank_billing_catalog';

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$this->ensure_handshaked();
		if ( ! $this->site_token ) {
			return array();
		}

		$response = wp_remote_get(
			trailingslashit( $this->server_url ) . 'catalog',
			array(
				'timeout' => 5,
				'headers' => $this->auth_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->note_bank_response( 'catalog', $response );
			return array();
		}

		$this->note_bank_response( 'catalog', $response );

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_billing_catalog( is_array( $data ) ? $data : array() );
		if ( ! empty( $data ) ) {
			set_transient( $cache_key, $data, DAY_IN_SECONDS );
		}

		return $data;
	}

	public function get_financial_snapshot(): array {
		if ( $this->is_byok() ) {
			return $this->build_byok_status();
		}

		$status = $this->get_status();
		$billing_state = $this->normalize_billing_state(
			is_array( $status['billing_state'] ?? null ) ? (array) $status['billing_state'] : array(),
			array(
				'verified'              => ! empty( $status['verified_handshake'] ),
				'provisional'           => ! empty( $status['provisional_handshake'] ),
				'offline'               => ! empty( $status['offline'] ),
				'monthly_remaining'     => (int) ( $status['monthly_included_remaining'] ?? $status['monthly_remaining'] ?? 0 ),
				'credits_remaining'     => (int) ( $status['purchased_credits_remaining'] ?? $status['credits_remaining'] ?? 0 ),
				'legacy_bonus_remaining'=> (int) ( $status['legacy_bonus_remaining'] ?? 0 ),
				'total_remaining'       => (int) ( $status['total_remaining'] ?? 0 ),
			)
		);

		return array_merge(
			array(
			'transport_mode'              => (string) ( $status['transport_mode'] ?? $this->current_transport_mode() ),
			'billing_authority'           => $this->billing_state_to_legacy_authority( $billing_state ),
			'verified_handshake'          => ! empty( $status['verified_handshake'] ),
			'provisional_handshake'       => ! empty( $status['provisional_handshake'] ),
			'billing_tier'                => (string) ( $status['tier'] ?? $this->get_current_tier() ),
			'monthly_icu_budget'          => (int) ( $status['monthly_icu_budget'] ?? $status['icu_budget'] ?? 0 ),
			'monthly_included_icu_budget' => (int) ( $status['monthly_included_icu_budget'] ?? $status['monthly_icu_budget'] ?? $status['icu_budget'] ?? 0 ),
			'monthly_remaining'           => (int) ( $status['monthly_remaining'] ?? $status['monthly_included_remaining'] ?? 0 ),
			'monthly_included_remaining'  => (int) ( $status['monthly_included_remaining'] ?? $status['monthly_remaining'] ?? 0 ),
			'credits_remaining'           => (int) ( $status['credits_remaining'] ?? $status['purchased_credits_remaining'] ?? 0 ),
			'purchased_credits_remaining' => (int) ( $status['purchased_credits_remaining'] ?? $status['credits_remaining'] ?? 0 ),
			'legacy_bonus_remaining'      => (int) ( $status['legacy_bonus_remaining'] ?? 0 ),
			'total_available'             => (int) ( $status['total_available'] ?? 0 ),
			'total_remaining'             => (int) ( $status['total_remaining'] ?? 0 ),
			'spendable_credits_remaining' => (int) ( $status['spendable_credits_remaining'] ?? $status['total_remaining'] ?? 0 ),
			'spendable_icus_remaining'    => (int) ( $status['spendable_icus_remaining'] ?? $status['total_remaining'] ?? 0 ),
			'using_purchased_credits'     => ! empty( $status['using_purchased_credits'] ),
			'using_legacy_bonus'          => ! empty( $status['using_legacy_bonus'] ),
			'budget_pressure_state'       => (string) ( $status['budget_pressure_state'] ?? 'normal' ),
			'is_byok'                     => false,
			'offline'                     => ! empty( $status['offline'] ),
			),
			$this->billing_state_aliases( $billing_state )
		);
	}

	private function build_byok_status(): array {
		$tier = $this->get_current_tier();

		return $this->normalize_status(
			array(
				'tier'                        => $tier,
				'billing_tier'                => $tier,
				'billing_authority'           => 'byok',
				'billing_state'               => array(
					'authority_mode' => 'byok',
					'handshake_state'=> 'byok',
					'service_state'  => 'normal',
					'spend_source'   => 'byok',
					'estimate_mode'  => 'provider_usage',
				),
				'billing_service_state'       => 'normal',
				'billing_handshake_state'     => 'byok',
				'billing_spend_source'        => 'byok',
				'verified_handshake'          => false,
				'provisional_handshake'       => false,
				'monthly_icu_budget'          => 0,
				'monthly_included_icu_budget' => 0,
				'icu_budget'                  => 0,
				'icus_used'                   => 0,
				'icus_reserved'               => 0,
				'icus_remaining'              => PHP_INT_MAX,
				'monthly_remaining'           => PHP_INT_MAX,
				'monthly_included_remaining'  => PHP_INT_MAX,
				'credits_remaining'           => 0,
				'purchased_credits_remaining' => 0,
				'legacy_bonus_remaining'      => 0,
				'total_available'             => PHP_INT_MAX,
				'total_remaining'             => PHP_INT_MAX,
				'spendable_credits_remaining' => PHP_INT_MAX,
				'spendable_icus_remaining'    => PHP_INT_MAX,
				'budget_pressure_state'       => 'normal',
				'using_purchased_credits'     => false,
				'using_legacy_bonus'          => false,
				'raw_tokens_used'             => 0,
				'tokens_used'                 => 0,
				'tokens_reserved'             => 0,
				'tokens_limit'                => 0,
				'tokens_remaining'            => PHP_INT_MAX,
				'is_byok'                     => true,
				'transport_mode'              => $this->current_transport_mode(),
			),
			$tier
		);
	}

	public function purchase_credits( int $user_id, string $pack_type, string $payment_id, string $tier = '' ): array {
		unset( $tier );
		$response = $this->post(
			'purchase-credits',
			array(
				'site_domain' => $this->site_domain(),
				'user_id'     => $user_id,
				'pack_type'   => $pack_type,
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
			$this->note_bank_response( 'multipliers', $response );
			$cached = get_option( 'pressark_bank_multipliers_cache' );
			return is_array( $cached ) ? $cached : $this->default_multiplier_config();
		}

		$this->note_bank_response( 'multipliers', $response );

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
		int $estimated_raw_tokens = 0,
		int $timeout = 20
	) {
		$route = in_array( $route, array( 'chat', 'summarize' ), true ) ? $route : 'chat';
		$trace_context = $this->merge_trace_context(
			array(
				'route' => $route,
			)
		);

		if ( $estimated_icus <= 0 ) {
			$estimated_icus = 'summarize' === $route ? 1200 : 5000;
		}

		return $this->post(
			'v1/' . $route,
			array(
				'site_domain'    => $this->site_domain(),
				'user_id'        => $this->user_id(),
				'model'          => $model,
				'provider'       => $provider,
				'stream'         => false,
				'estimated_icus' => $estimated_icus,
				'estimated_raw_tokens' => max( 0, $estimated_raw_tokens ),
				'request_body'   => $request_body,
			),
			$timeout,
			true,
			false,
			$trace_context
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
		int $estimated_raw_tokens = 0,
		int $timeout = 20
	) {
		return $this->proxy_request( 'summarize', $request_body, $tier, $model, $provider, $estimated_icus, $estimated_raw_tokens, $timeout );
	}

	private function post( string $path, array $payload, int $timeout = 5, bool $blocking = true, bool $is_retry = false, array $trace_context = array() ) {
		$trace_context = $this->merge_trace_context( $trace_context );
		$payload['installation_uuid'] = $payload['installation_uuid'] ?? $this->installation_uuid();
		$payload['site_domain']       = $payload['site_domain'] ?? $this->site_domain();
		if ( ! empty( $trace_context['correlation_id'] ) ) {
			$payload['correlation_id'] = $trace_context['correlation_id'];
		}
		if ( ! empty( $trace_context['run_id'] ) ) {
			$payload['run_id'] = $trace_context['run_id'];
		}
		if ( ! empty( $trace_context['task_id'] ) ) {
			$payload['task_id'] = $trace_context['task_id'];
		}

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
				'headers'  => $this->auth_headers( $trace_context ),
				'body'     => wp_json_encode( $payload ),
			)
		);

		$this->note_bank_response( $path, $response );

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
					return $this->post( $path, $payload, $timeout, $blocking, true, $trace_context );
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
	private function auth_headers( array $trace_context = array() ): array {
		$headers = array( 'Content-Type' => 'application/json' );
		$trace_context = $this->merge_trace_context( $trace_context );

		// Re-read in case handshake just stored a new token.
		if ( ! $this->site_token ) {
			$this->site_token = (string) get_option( 'pressark_site_token', '' );
		}

		if ( $this->site_token ) {
			$headers['x-pressark-token'] = $this->site_token;
		}
		if ( ! empty( $trace_context['correlation_id'] ) ) {
			$headers['x-pressark-correlation-id'] = $trace_context['correlation_id'];
		}

		return $headers;
	}

	private function note_bank_response( string $path, $response ): void {
		$path = trim( $path );
		if ( is_wp_error( $response ) ) {
			$this->record_contact_failure( $path, $response->get_error_message(), 0 );
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			$this->record_contact_success( $path, $status_code );
			return;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = '';
		if ( is_array( $body ) ) {
			$error = (string) ( $body['error']['message'] ?? $body['error']['code'] ?? '' );
		}

		$this->record_contact_failure( $path, $error, $status_code );
	}

	private function record_contact_success( string $path, int $status_code ): void {
		$current = $this->get_contact_snapshot();
		update_option(
			self::CONTACT_STATE_OPTION,
			array(
				'last_attempt_at'             => current_time( 'mysql' ),
				'last_attempt_path'           => sanitize_text_field( $path ),
				'last_attempt_status'         => max( 0, $status_code ),
				'last_successful_contact_at'  => current_time( 'mysql' ),
				'last_successful_contact_path'=> sanitize_text_field( $path ),
				'last_successful_status'      => max( 0, $status_code ),
				'last_failure_at'             => $current['last_failure_at'] ?? '',
				'last_failure_path'           => $current['last_failure_path'] ?? '',
				'last_failure_status'         => (int) ( $current['last_failure_status'] ?? 0 ),
				'last_failure_error'          => $current['last_failure_error'] ?? '',
			),
			false
		);
	}

	private function record_contact_failure( string $path, string $error, int $status_code ): void {
		$current = $this->get_contact_snapshot();
		update_option(
			self::CONTACT_STATE_OPTION,
			array(
				'last_attempt_at'             => current_time( 'mysql' ),
				'last_attempt_path'           => sanitize_text_field( $path ),
				'last_attempt_status'         => max( 0, $status_code ),
				'last_successful_contact_at'  => $current['last_successful_contact_at'] ?? '',
				'last_successful_contact_path'=> $current['last_successful_contact_path'] ?? '',
				'last_successful_status'      => (int) ( $current['last_successful_status'] ?? 0 ),
				'last_failure_at'             => current_time( 'mysql' ),
				'last_failure_path'           => sanitize_text_field( $path ),
				'last_failure_status'         => max( 0, $status_code ),
				'last_failure_error'          => sanitize_text_field( $error ),
			),
			false
		);
	}

	private function record_handshake_success( array $snapshot ): void {
		update_option(
			self::HANDSHAKE_STATE_OPTION,
			array(
				'last_successful_handshake_at' => current_time( 'mysql' ),
				'tier'                         => sanitize_key( (string) ( $snapshot['tier'] ?? 'free' ) ),
				'verified'                     => ! empty( $snapshot['verified'] ),
				'provisional'                  => ! empty( $snapshot['provisional'] ),
				'handshake_state'              => ! empty( $snapshot['verified'] ) ? 'verified' : 'provisional',
				'site_token_present'           => ! empty( $snapshot['site_token'] ),
			),
			false
		);
	}

	private function normalize_contact_snapshot( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'last_attempt_at'              => sanitize_text_field( (string) ( $value['last_attempt_at'] ?? '' ) ),
			'last_attempt_path'            => sanitize_text_field( (string) ( $value['last_attempt_path'] ?? '' ) ),
			'last_attempt_status'          => (int) ( $value['last_attempt_status'] ?? 0 ),
			'last_successful_contact_at'   => sanitize_text_field( (string) ( $value['last_successful_contact_at'] ?? '' ) ),
			'last_successful_contact_path' => sanitize_text_field( (string) ( $value['last_successful_contact_path'] ?? '' ) ),
			'last_successful_status'       => (int) ( $value['last_successful_status'] ?? 0 ),
			'last_failure_at'              => sanitize_text_field( (string) ( $value['last_failure_at'] ?? '' ) ),
			'last_failure_path'            => sanitize_text_field( (string) ( $value['last_failure_path'] ?? '' ) ),
			'last_failure_status'          => (int) ( $value['last_failure_status'] ?? 0 ),
			'last_failure_error'           => sanitize_text_field( (string) ( $value['last_failure_error'] ?? '' ) ),
		);
	}

	private function normalize_handshake_snapshot( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'last_successful_handshake_at' => sanitize_text_field( (string) ( $value['last_successful_handshake_at'] ?? '' ) ),
			'tier'                         => sanitize_key( (string) ( $value['tier'] ?? '' ) ),
			'verified'                     => ! empty( $value['verified'] ),
			'provisional'                  => ! empty( $value['provisional'] ),
			'handshake_state'              => sanitize_key( (string) ( $value['handshake_state'] ?? '' ) ),
			'site_token_present'           => ! empty( $value['site_token_present'] ),
		);
	}

	/**
	 * Merge explicit trace context with the current request context.
	 *
	 * @param array<string,mixed> $context Partial trace context.
	 * @return array<string,string>
	 */
	private function merge_trace_context( array $context = array() ): array {
		$current = class_exists( 'PressArk_Activity_Trace' )
			? PressArk_Activity_Trace::current_context()
			: array();
		$merged  = array_merge( $current, $context );

		$correlation_id = (string) ( $merged['correlation_id'] ?? '' );
		if ( '' === $correlation_id && ! empty( $merged['reservation_id'] ) ) {
			$correlation_id = 'corr_' . substr( md5( (string) $merged['reservation_id'] ), 0, 32 );
		}

		return array(
			'correlation_id' => '' !== $correlation_id && class_exists( 'PressArk_Activity_Trace' )
				? PressArk_Activity_Trace::normalize_correlation_id( $correlation_id )
				: sanitize_text_field( $correlation_id ),
			'run_id'         => sanitize_text_field( (string) ( $merged['run_id'] ?? '' ) ),
			'task_id'        => sanitize_text_field( (string) ( $merged['task_id'] ?? '' ) ),
			'reservation_id' => sanitize_text_field( (string) ( $merged['reservation_id'] ?? '' ) ),
			'route'          => sanitize_key( (string) ( $merged['route'] ?? '' ) ),
		);
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

	private function current_transport_mode(): string {
		return $this->is_proxy_mode() ? 'proxy' : 'direct';
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

		return $this->normalize_status(
			array(
			'tier'                    => $tier,
			'icus_used'               => 0,
			'icus_reserved'           => 0,
			'icu_budget'              => $limit,
			'monthly_icu_budget'      => $limit,
			'monthly_included_icu_budget' => $limit,
			'icus_remaining'          => $limit,
			'monthly_remaining'       => $limit,
			'monthly_included_remaining' => $limit,
			'credits_remaining'       => 0,
			'purchased_credits_remaining' => 0,
			'legacy_bonus_remaining'  => 0,
			'total_available'         => $limit,
			'total_remaining'         => $limit,
			'spendable_credits_remaining' => $limit,
			'spendable_icus_remaining' => $limit,
			'next_reset_at'           => '',
			'billing_period_start'    => '',
			'billing_period_end'      => '',
			'uses_anniversary_reset' => false,
			'percent_used'            => 0,
			'at_limit'                => false,
			'warn'                    => false,
			'offline'                 => true,
			'billing_authority'       => $this->local_billing_authority( true ),
			'budget_pressure_state'   => 'normal',
			'using_purchased_credits' => false,
			'using_legacy_bonus'      => false,
			'tokens_used'             => 0,
			'tokens_reserved'         => 0,
			'tokens_limit'            => $limit,
			'tokens_remaining'        => $limit,
			)
		);
	}

	private function offline_reserve( int $estimated_icus, string $tier ): array {
		$user_id = $this->user_id();
		$budget  = PressArk_Entitlements::icu_budget( $tier );
		$last    = get_option( 'pressark_last_token_status_' . $user_id );
		$fallback_status = $this->normalize_status(
			is_array( $last )
				? array_merge( $last, array( 'offline' => true ) )
				: array(
					'tier'              => $tier,
					'icus_used'         => 0,
					'icus_reserved'     => 0,
					'icu_budget'        => $budget,
					'icus_remaining'    => $budget,
					'monthly_remaining' => $budget,
					'total_remaining'   => $budget,
					'offline'           => true,
				),
			$tier
		);

		$snapshot_at = is_array( $last ) ? (string) ( $last['snapshot_at'] ?? '' ) : '';
		if ( '' !== $snapshot_at ) {
			$ledger      = new PressArk_Cost_Ledger();
			$local_since = $ledger->get_settled_icus_since( $user_id, $snapshot_at );
			$remaining   = max( 0, (int) ( $last['icus_remaining'] ?? $budget ) - $local_since );
			$envelope    = $this->build_offline_reserve_envelope( $budget, $remaining, $estimated_icus, $snapshot_at );
			$allowed     = max( 0, (int) ( $envelope['limit_icus'] ?? $remaining ) );
			$billing_state = $this->normalize_billing_state(
				(array) ( $fallback_status['billing_state'] ?? array() ),
				array(
					'offline'          => true,
					'monthly_remaining' => (int) ( $fallback_status['monthly_remaining'] ?? 0 ),
					'credits_remaining' => (int) ( $fallback_status['credits_remaining'] ?? 0 ),
					'legacy_bonus_remaining' => (int) ( $fallback_status['legacy_bonus_remaining'] ?? 0 ),
					'total_remaining'  => (int) ( $fallback_status['total_remaining'] ?? $allowed ),
					'reserve_envelope' => $envelope,
				)
			);
			$billing_state['reserve_certainty'] = 'reduced';
			$billing_state['reserve_envelope']  = $envelope;
			$billing_state['estimate_mode']     = sanitize_key( (string) ( $envelope['mode'] ?? 'offline_snapshot_envelope' ) );
			$billing_state['estimate_notice']   = $this->billing_estimate_notice( false, (string) $billing_state['estimate_mode'], $envelope );

			if ( $estimated_icus > $allowed ) {
				return array_merge(
					array(
						'ok'               => false,
						'offline'          => true,
						'error'            => 'token_limit_reached',
						'icus_remaining'   => $allowed,
						'tokens_remaining' => $allowed,
						'reserve_envelope' => $envelope,
						'reserve_certainty' => 'reduced',
					),
					$this->billing_state_aliases( $billing_state )
				);
			}

			return array_merge(
				array(
					'ok'               => true,
					'offline'          => true,
					'icus_remaining'   => max( 0, $allowed - $estimated_icus ),
					'tokens_remaining' => max( 0, $allowed - $estimated_icus ),
					'reserve_envelope' => $envelope,
					'reserve_certainty' => 'reduced',
				),
				$this->billing_state_aliases( $billing_state )
			);
		}

		$envelope = $this->build_offline_reserve_envelope( $budget, $budget, $estimated_icus );
		$allowed  = max( 0, (int) ( $envelope['limit_icus'] ?? 0 ) );
		$billing_state = $this->normalize_billing_state(
			(array) ( $fallback_status['billing_state'] ?? array() ),
			array(
				'offline'          => true,
				'monthly_remaining' => (int) ( $fallback_status['monthly_remaining'] ?? 0 ),
				'credits_remaining' => (int) ( $fallback_status['credits_remaining'] ?? 0 ),
				'legacy_bonus_remaining' => (int) ( $fallback_status['legacy_bonus_remaining'] ?? 0 ),
				'total_remaining'  => (int) ( $fallback_status['total_remaining'] ?? $allowed ),
				'reserve_envelope' => $envelope,
			)
		);
		$billing_state['reserve_certainty'] = 'reduced';
		$billing_state['reserve_envelope']  = $envelope;
		$billing_state['estimate_mode']     = sanitize_key( (string) ( $envelope['mode'] ?? 'offline_emergency_envelope' ) );
		$billing_state['estimate_notice']   = $this->billing_estimate_notice( false, (string) $billing_state['estimate_mode'], $envelope );

		if ( $estimated_icus > $allowed ) {
			return array_merge(
				array(
					'ok'               => false,
					'offline'          => true,
					'error'            => 'token_limit_reached',
					'icus_remaining'   => 0,
					'tokens_remaining' => 0,
					'reserve_envelope' => $envelope,
					'reserve_certainty' => 'reduced',
				),
				$this->billing_state_aliases( $billing_state )
			);
		}

		return array_merge(
			array(
				'ok'               => true,
				'offline'          => true,
				'icus_remaining'   => max( 0, $allowed - $estimated_icus ),
				'tokens_remaining' => max( 0, $allowed - $estimated_icus ),
				'reserve_envelope' => $envelope,
				'reserve_certainty' => 'reduced',
			),
			$this->billing_state_aliases( $billing_state )
		);
	}

	private function build_offline_reserve_envelope( int $budget, int $remaining, int $estimated_icus, string $snapshot_at = '' ): array {
		$remaining = max( 0, $remaining );
		$budget    = max( 0, $budget );
		$floor     = max( max( 1500, $estimated_icus ), min( 12000, max( 2500, (int) round( $budget * 0.03 ) ) ) );

		if ( '' !== $snapshot_at ) {
			$age_seconds = $this->seconds_since_snapshot( $snapshot_at );
			$fraction    = 0.85;
			$label       = 'Snapshot reserve envelope';
			$detail      = 'Reserve uses the last verified bank snapshot with a reduced-certainty cap until live bank checks resume.';
			$mode        = 'offline_snapshot_envelope';

			if ( $age_seconds > 30 * MINUTE_IN_SECONDS ) {
				$fraction = 0.35;
				$label    = 'Stale snapshot reserve';
				$detail   = 'Reserve uses a tighter degraded envelope because the last bank snapshot is stale.';
			} elseif ( $age_seconds > 5 * MINUTE_IN_SECONDS ) {
				$fraction = 0.55;
				$label    = 'Reduced-certainty snapshot reserve';
				$detail   = 'Reserve uses a reduced-certainty envelope because the plugin is relying on a non-live bank snapshot.';
			}

			$limit = min( $remaining, max( $floor, (int) round( $remaining * $fraction ) ) );

			return array(
				'mode'                 => $mode,
				'label'                => $label,
				'detail'               => $detail,
				'limit_icus'           => max( 0, $limit ),
				'basis_remaining_icus' => $remaining,
				'snapshot_at'          => $snapshot_at,
				'snapshot_age_seconds' => $age_seconds,
				'certainty'            => 'reduced',
			);
		}

		$limit = min( $budget, max( 3000, min( 15000, (int) round( $budget * 0.05 ) ) ) );
		return array(
			'mode'                 => 'offline_emergency_envelope',
			'label'                => 'Offline emergency reserve',
			'detail'               => 'Reserve is running inside a temporary degraded envelope until the bank is reachable again.',
			'limit_icus'           => max( 0, $limit ),
			'basis_remaining_icus' => $remaining,
			'snapshot_at'          => '',
			'snapshot_age_seconds' => 0,
			'certainty'            => 'reduced',
		);
	}

	private function seconds_since_snapshot( string $snapshot_at ): int {
		$snapshot_ts = strtotime( $snapshot_at );
		$current_ts  = strtotime( (string) current_time( 'mysql' ) );
		if ( false === $snapshot_ts || false === $current_ts ) {
			return 0;
		}

		return max( 0, $current_ts - $snapshot_ts );
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
		$resolved_tier      = '';
		if ( ! empty( $data['tier'] ) ) {
			$resolved_tier = PressArk_Entitlements::normalize_tier( (string) $data['tier'] );
		} elseif ( $tier ) {
			$resolved_tier = PressArk_Entitlements::normalize_tier( $tier );
		} else {
			$resolved_tier = $this->get_current_tier();
		}
		$is_byok = ! empty( $data['is_byok'] ) || $this->is_byok();

		$icu_budget         = (int) ( $data['monthly_included_icu_budget'] ?? $data['monthly_icu_budget'] ?? $data['icu_budget'] ?? $data['tokens_limit'] ?? PressArk_Entitlements::icu_budget( $resolved_tier ) );
		$icus_used          = (int) ( $data['icus_used'] ?? $data['tokens_used'] ?? 0 );
		$icus_reserved      = (int) ( $data['icus_reserved'] ?? $data['tokens_reserved'] ?? 0 );
		$monthly_remaining  = (int) ( $data['monthly_included_remaining'] ?? $data['monthly_remaining'] ?? max( 0, $icu_budget - $icus_used ) );
		$credits_remaining  = (int) ( $data['purchased_credits_remaining'] ?? $data['credits_remaining'] ?? 0 );
		$legacy_bonus       = (int) ( $data['legacy_bonus_remaining'] ?? 0 );
		$icus_remaining     = (int) ( $data['icus_remaining'] ?? $data['tokens_remaining'] ?? max( 0, $monthly_remaining + $credits_remaining + $legacy_bonus - $icus_reserved ) );
		$total_remaining    = (int) ( $data['total_remaining'] ?? $icus_remaining );
		$total_available    = (int) ( $data['total_available'] ?? ( $icu_budget + $credits_remaining + $legacy_bonus ) );
		$raw_tokens_used    = (int) ( $data['raw_tokens_used'] ?? $icus_used );
		$using_purchased    = ! empty( $data['using_purchased_credits'] ) || ( $monthly_remaining <= 0 && $credits_remaining > 0 );
		$using_legacy_bonus = ! empty( $data['using_legacy_bonus'] ) || ( $monthly_remaining <= 0 && 0 === $credits_remaining && $legacy_bonus > 0 );
		$pressure_state     = (string) ( $data['budget_pressure_state'] ?? $this->calculate_budget_pressure_state( $icu_budget, $monthly_remaining, $total_remaining, $total_available ) );

		if ( $is_byok ) {
			$icu_budget         = 0;
			$icus_used          = 0;
			$icus_reserved      = 0;
			$monthly_remaining  = PHP_INT_MAX;
			$credits_remaining  = 0;
			$legacy_bonus       = 0;
			$icus_remaining     = PHP_INT_MAX;
			$total_remaining    = PHP_INT_MAX;
			$total_available    = PHP_INT_MAX;
			$raw_tokens_used    = 0;
			$using_purchased    = false;
			$using_legacy_bonus = false;
			$pressure_state     = 'normal';
		}

		$verified_handshake = $is_byok
			? false
			: ( array_key_exists( 'verified_handshake', $data )
				? ! empty( $data['verified_handshake'] )
				: ( ! empty( $data['verified'] ) || (bool) get_option( 'pressark_handshake_verified', false ) ) );
		$provisional_handshake = $is_byok
			? false
			: ( array_key_exists( 'provisional_handshake', $data )
				? ! empty( $data['provisional_handshake'] )
				: ! $verified_handshake );
		$billing_state = $this->normalize_billing_state(
			is_array( $data['billing_state'] ?? null ) ? (array) $data['billing_state'] : array(),
			array(
				'is_byok'                => $is_byok,
				'verified'               => $verified_handshake,
				'provisional'            => $provisional_handshake,
				'offline'                => ! empty( $data['offline'] ),
				'service_state'          => (string) ( $data['billing_service_state'] ?? '' ),
				'handshake_state'        => (string) ( $data['billing_handshake_state'] ?? '' ),
				'spend_source'           => (string) ( $data['billing_spend_source'] ?? '' ),
				'monthly_remaining'      => $monthly_remaining,
				'credits_remaining'      => $credits_remaining,
				'legacy_bonus_remaining' => $legacy_bonus,
				'total_remaining'        => $total_remaining,
			)
		);
		$billing_authority = $is_byok ? 'byok' : $this->billing_state_to_legacy_authority( $billing_state );
		$transport_mode    = sanitize_key( (string) ( $data['transport_mode'] ?? $this->current_transport_mode() ) );
		if ( ! in_array( $transport_mode, array( 'direct', 'proxy' ), true ) ) {
			$transport_mode = $this->current_transport_mode();
		}

		$data['tier']              = $resolved_tier;
		$data['billing_tier']      = $resolved_tier;
		$data['icu_budget']        = $icu_budget;
		$data['monthly_icu_budget'] = (int) ( $data['monthly_icu_budget'] ?? $icu_budget );
		$data['monthly_included_icu_budget'] = $icu_budget;
		$data['icus_used']         = $icus_used;
		$data['icus_reserved']     = $icus_reserved;
		$data['icus_remaining']    = $icus_remaining;
		$data['monthly_remaining'] = (int) ( $data['monthly_remaining'] ?? $monthly_remaining );
		$data['monthly_included_remaining'] = $monthly_remaining;
		$data['monthly_exhausted'] = ! empty( $data['monthly_exhausted'] ) || $monthly_remaining <= 0;
		$data['using_purchased_credits'] = $using_purchased;
		$data['using_legacy_bonus'] = $using_legacy_bonus;
		$data['credits_remaining'] = $credits_remaining;
		$data['purchased_credits_remaining'] = $credits_remaining;
		$data['legacy_bonus_remaining'] = $legacy_bonus;
		$data['raw_tokens_used']   = $raw_tokens_used;
		$data['total_available']   = $total_available;
		$data['total_remaining']   = $total_remaining;
		$data['spendable_credits_remaining'] = $total_remaining;
		$data['spendable_icus_remaining'] = $total_remaining;
		$data['next_reset_at']     = (string) ( $data['next_reset_at'] ?? '' );
		$data['billing_period_start'] = (string) ( $data['billing_period_start'] ?? '' );
		$data['billing_period_end']   = (string) ( $data['billing_period_end'] ?? '' );
		$data['uses_anniversary_reset'] = ! empty( $data['uses_anniversary_reset'] );
		$data['budget_pressure_state'] = $pressure_state;
		$data['billing_authority'] = $billing_authority;
		$data['billing_state']     = $billing_state;
		$data['billing_service_state'] = (string) $billing_state['service_state'];
		$data['billing_handshake_state'] = (string) $billing_state['handshake_state'];
		$data['billing_spend_source'] = (string) $billing_state['spend_source'];
		$data['verified_handshake'] = $verified_handshake;
		$data['provisional_handshake'] = $provisional_handshake;
		$data['is_byok']           = $is_byok;
		$data['transport_mode']    = $transport_mode;
		$data['tokens_used']       = $icus_used;
		$data['tokens_reserved']   = $icus_reserved;
		$data['tokens_limit']      = $icu_budget;
		$data['tokens_remaining']  = $icus_remaining;
		$data['bonus_tokens']      = (int) ( $data['bonus_tokens'] ?? ( $credits_remaining + $legacy_bonus ) );

		return $data;
	}

	private function normalize_billing_catalog( array $data ): array {
		if ( empty( $data['credit_packs'] ) || ! is_array( $data['credit_packs'] ) ) {
			return array();
		}

		$credit_packs = array();
		foreach ( $data['credit_packs'] as $pack_type => $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}

			$normalized_type = sanitize_key( (string) ( $pack['pack_type'] ?? $pack_type ) );
			if ( '' === $normalized_type ) {
				continue;
			}

			$credit_packs[ $normalized_type ] = array(
				'pack_type'   => $normalized_type,
				'pricing_id'  => (int) ( $pack['pricing_id'] ?? 0 ),
				'icus'        => (int) ( $pack['icus'] ?? 0 ),
				'price_cents' => (int) ( $pack['price_cents'] ?? 0 ),
				'label'       => sanitize_text_field( (string) ( $pack['label'] ?? '' ) ),
			);
		}

		return array(
			'version'       => (int) ( $data['version'] ?? 1 ),
			'contract_hash' => sanitize_text_field( (string) ( $data['contract_hash'] ?? '' ) ),
			'plan_to_tier'  => is_array( $data['plan_to_tier'] ?? null ) ? $data['plan_to_tier'] : array(),
			'credit_packs'  => $credit_packs,
			'freemius'      => is_array( $data['freemius'] ?? null )
				? array(
					'main_plugin_id'     => sanitize_text_field( (string) ( $data['freemius']['main_plugin_id'] ?? '' ) ),
					'credits_product_id' => sanitize_text_field( (string) ( $data['freemius']['credits_product_id'] ?? '' ) ),
					'credits_plan_id'    => sanitize_text_field( (string) ( $data['freemius']['credits_plan_id'] ?? '' ) ),
				)
				: array(),
		);
	}

	private function calculate_budget_pressure_state( int $monthly_budget, int $monthly_remaining, int $total_remaining, int $total_available ): string {
		$total_ratio        = $total_available > 0 ? ( $total_remaining / $total_available ) : 0.0;
		$has_monthly_budget = $monthly_budget > 0;
		$monthly_ratio      = $has_monthly_budget ? ( $monthly_remaining / $monthly_budget ) : 1.0;
		$monthly_critical   = $has_monthly_budget && $monthly_ratio <= 0.02;
		$monthly_conserve   = $has_monthly_budget && ( $monthly_remaining <= 0 || $monthly_ratio <= 0.1 );

		if ( $total_remaining <= 0 || $total_ratio <= 0.08 || $monthly_critical ) {
			return 'critical';
		}

		if ( $total_ratio <= 0.2 || $monthly_conserve ) {
			return 'conserve';
		}

		return 'normal';
	}

	private function normalize_billing_state( array $state, array $context = array() ): array {
		$is_byok     = ! empty( $context['is_byok'] );
		$verified    = ! empty( $context['verified'] );
		$provisional = array_key_exists( 'provisional', $context ) ? ! empty( $context['provisional'] ) : ! $verified;

		$authority_mode = sanitize_key( (string) ( $state['authority_mode'] ?? $context['authority_mode'] ?? '' ) );
		if ( $is_byok ) {
			$authority_mode = 'byok';
		} elseif ( ! in_array( $authority_mode, array( 'bank_verified', 'bank_provisional', 'byok' ), true ) ) {
			$authority_mode = $is_byok ? 'byok' : ( $verified ? 'bank_verified' : 'bank_provisional' );
		}

		$handshake_state = sanitize_key( (string) ( $state['handshake_state'] ?? $context['handshake_state'] ?? '' ) );
		if ( $is_byok ) {
			$handshake_state = 'byok';
		} elseif ( ! in_array( $handshake_state, array( 'verified', 'provisional', 'byok' ), true ) ) {
			$handshake_state = $is_byok ? 'byok' : ( $verified && ! $provisional ? 'verified' : 'provisional' );
		}

		$service_state = sanitize_key( (string) ( $state['service_state'] ?? $context['service_state'] ?? '' ) );
		if ( ! empty( $context['offline'] ) ) {
			$service_state = 'offline_assisted';
		} elseif ( ! in_array( $service_state, array( 'normal', 'degraded', 'offline_assisted' ), true ) ) {
			$service_state = ! empty( $context['cached'] ) ? 'degraded' : 'normal';
		}

		$spend_source = sanitize_key( (string) ( $state['spend_source'] ?? $context['spend_source'] ?? '' ) );
		if ( $is_byok ) {
			$spend_source = 'byok';
		} elseif ( ! in_array( $spend_source, array( 'monthly_included', 'purchased_credits', 'legacy_bonus', 'mixed', 'depleted', 'byok' ), true ) ) {
			$spend_source = $this->resolve_billing_spend_source(
				(int) ( $context['monthly_remaining'] ?? 0 ),
				(int) ( $context['credits_remaining'] ?? 0 ),
				(int) ( $context['legacy_bonus_remaining'] ?? 0 ),
				(int) ( $context['total_remaining'] ?? 0 ),
				$is_byok
			);
		}

		$estimate_mode = sanitize_key( (string) ( $state['estimate_mode'] ?? '' ) );
		$reserve_envelope = $this->normalize_reserve_envelope(
			is_array( $state['reserve_envelope'] ?? null )
				? (array) $state['reserve_envelope']
				: (array) ( $context['reserve_envelope'] ?? array() )
		);
		if ( ! empty( $reserve_envelope['mode'] ) ) {
			$estimate_mode = sanitize_key( (string) $reserve_envelope['mode'] );
		} elseif ( $is_byok ) {
			$estimate_mode = 'provider_usage';
		} elseif ( '' === $estimate_mode ) {
			$estimate_mode = 'plugin_local_advisory';
		}

		return array(
			'version'           => max( 1, (int) ( $state['version'] ?? 1 ) ),
			'authority_mode'    => $authority_mode,
			'handshake_state'   => $handshake_state,
			'service_state'     => $service_state,
			'spend_source'      => $spend_source,
			'estimate_mode'     => $estimate_mode,
			'reserve_certainty' => ! empty( $reserve_envelope ) ? 'reduced' : 'normal',
			'reserve_envelope'  => $reserve_envelope,
			'authority_label'   => $is_byok
				? $this->billing_authority_label( $authority_mode )
				: (string) ( $state['authority_label'] ?? $this->billing_authority_label( $authority_mode ) ),
			'service_label'     => (string) ( $state['service_label'] ?? $this->billing_service_label( $service_state ) ),
			'spend_label'       => $is_byok
				? $this->billing_spend_label( $spend_source )
				: (string) ( $state['spend_label'] ?? $this->billing_spend_label( $spend_source ) ),
			'authority_notice'  => $is_byok
				? $this->billing_authority_notice( $authority_mode )
				: (string) ( $state['authority_notice'] ?? $this->billing_authority_notice( $authority_mode ) ),
			'service_notice'    => $is_byok
				? $this->billing_service_notice( $service_state, $authority_mode )
				: (string) ( $state['service_notice'] ?? $this->billing_service_notice( $service_state, $authority_mode ) ),
			'estimate_notice'   => $is_byok
				? $this->billing_estimate_notice( $is_byok, $estimate_mode, $reserve_envelope )
				: (string) ( $state['estimate_notice'] ?? $this->billing_estimate_notice( $is_byok, $estimate_mode, $reserve_envelope ) ),
		);
	}

	private function normalize_reserve_envelope( array $envelope = array() ): array {
		if ( empty( $envelope ) ) {
			return array();
		}

		return array(
			'mode'                 => sanitize_key( (string) ( $envelope['mode'] ?? '' ) ),
			'label'                => sanitize_text_field( (string) ( $envelope['label'] ?? '' ) ),
			'detail'               => sanitize_text_field( (string) ( $envelope['detail'] ?? '' ) ),
			'limit_icus'           => max( 0, (int) ( $envelope['limit_icus'] ?? 0 ) ),
			'basis_remaining_icus' => max( 0, (int) ( $envelope['basis_remaining_icus'] ?? 0 ) ),
			'snapshot_at'          => sanitize_text_field( (string) ( $envelope['snapshot_at'] ?? '' ) ),
			'snapshot_age_seconds' => max( 0, (int) ( $envelope['snapshot_age_seconds'] ?? 0 ) ),
			'certainty'            => sanitize_key( (string) ( $envelope['certainty'] ?? '' ) ),
		);
	}

	private function billing_state_aliases( array $billing_state ): array {
		if ( empty( $billing_state ) ) {
			return array();
		}

		return array(
			'billing_authority'       => $this->billing_state_to_legacy_authority( $billing_state ),
			'billing_state'           => $billing_state,
			'billing_service_state'   => (string) ( $billing_state['service_state'] ?? '' ),
			'billing_handshake_state' => (string) ( $billing_state['handshake_state'] ?? '' ),
			'billing_spend_source'    => (string) ( $billing_state['spend_source'] ?? '' ),
			'reserve_certainty'       => (string) ( $billing_state['reserve_certainty'] ?? '' ),
			'reserve_envelope'        => (array) ( $billing_state['reserve_envelope'] ?? array() ),
		);
	}

	private function resolve_billing_spend_source( int $monthly_remaining, int $credits_remaining, int $legacy_bonus_remaining, int $total_remaining, bool $is_byok = false ): string {
		if ( $is_byok ) {
			return 'byok';
		}

		$has_monthly = $monthly_remaining > 0;
		$has_credits = $credits_remaining > 0;
		$has_legacy  = $legacy_bonus_remaining > 0;

		if ( $has_monthly && ! $has_credits && ! $has_legacy ) {
			return 'monthly_included';
		}

		if ( ! $has_monthly && $has_credits && ! $has_legacy ) {
			return 'purchased_credits';
		}

		if ( ! $has_monthly && ! $has_credits && $has_legacy ) {
			return 'legacy_bonus';
		}

		if ( ( $has_monthly && $has_credits ) || ( $has_monthly && $has_legacy ) || ( $has_credits && $has_legacy ) ) {
			return 'mixed';
		}

		return $total_remaining > 0 ? 'monthly_included' : 'depleted';
	}

	private function billing_state_to_legacy_authority( array $billing_state ): string {
		$authority_mode = (string) ( $billing_state['authority_mode'] ?? '' );
		$service_state  = (string) ( $billing_state['service_state'] ?? '' );

		if ( 'byok' === $authority_mode ) {
			return 'byok';
		}

		if ( in_array( $service_state, array( 'degraded', 'offline_assisted' ), true ) ) {
			return 'token_bank_cached';
		}

		return 'bank_verified' === $authority_mode ? 'token_bank_verified' : 'token_bank_provisional';
	}

	private function billing_authority_label( string $authority_mode ): string {
		switch ( $authority_mode ) {
			case 'bank_verified':
				return 'Bank verified';
			case 'byok':
				return 'BYOK';
			default:
				return 'Bank provisional';
		}
	}

	private function billing_service_label( string $service_state ): string {
		switch ( $service_state ) {
			case 'degraded':
				return 'Degraded';
			case 'offline_assisted':
				return 'Offline assisted';
			default:
				return 'Normal';
		}
	}

	private function billing_spend_label( string $spend_source ): string {
		switch ( $spend_source ) {
			case 'purchased_credits':
				return 'Purchased credits';
			case 'legacy_bonus':
				return 'Legacy bonus';
			case 'mixed':
				return 'Mixed sources';
			case 'depleted':
				return 'Depleted';
			case 'byok':
				return 'BYOK';
			default:
				return 'Monthly included';
		}
	}

	private function billing_authority_notice( string $authority_mode ): string {
		if ( 'byok' === $authority_mode ) {
			return 'Bundled credits are bypassed in BYOK mode. Your provider account is authoritative for spend while PressArk still enforces plan entitlements.';
		}

		if ( 'bank_verified' === $authority_mode ) {
			return 'The PressArk bank is authoritative for tier, reservations, credits, and settlement.';
		}

		return 'The PressArk bank remains authoritative, but this installation is still operating on a provisional handshake until verification completes.';
	}

	private function billing_service_notice( string $service_state, string $authority_mode ): string {
		if ( 'offline_assisted' === $service_state ) {
			return 'The plugin is operating from the last bank snapshot plus local settled usage. Bank authority is preserved and final settlement still comes from the bank.';
		}

		if ( 'degraded' === $service_state ) {
			return 'byok' === $authority_mode
				? 'Some billing-side verification data is degraded, but BYOK requests can continue with your provider account.'
				: 'A billing dependency is degraded. Existing bank truth is still being served, but the latest verification data may be delayed.';
		}

		return 'byok' === $authority_mode
			? 'Using your own provider key. Bundled credit accounting is not in play for these requests.'
			: 'The bank is healthy and serving live billing truth for this installation.';
	}

	private function billing_estimate_notice( bool $is_byok, string $estimate_mode = '', array $reserve_envelope = array() ): string {
		return $is_byok
			? 'Provider usage is shown directly in BYOK mode and does not settle against bundled credits.'
			: ( ! empty( $reserve_envelope )
				? $this->offline_estimate_notice( $estimate_mode, $reserve_envelope )
				: 'Plugin-side token and ICU estimates are advisory. Billable ICUs are finalized only when the bank settles provider usage.' );
	}

	private function offline_estimate_notice( string $estimate_mode, array $reserve_envelope ): string {
		$limit = max( 0, (int) ( $reserve_envelope['limit_icus'] ?? 0 ) );
		$limit_label = number_format( $limit );

		if ( 'offline_emergency_envelope' === $estimate_mode ) {
			return sprintf(
				'Reduced-certainty reserve is temporarily capped at %s ICUs while the bank is offline. Final spend still settles at the bank.',
				$limit_label
			);
		}

		return sprintf(
			'Reduced-certainty reserve is capped at %s ICUs from the last verified bank snapshot. Final spend still settles at the bank.',
			$limit_label
		);
	}

	private function local_billing_authority( bool $offline = false ): string {
		return $this->billing_state_to_legacy_authority(
			$this->normalize_billing_state(
				array(),
				array(
					'verified'    => (bool) get_option( 'pressark_handshake_verified', false ),
					'provisional' => ! (bool) get_option( 'pressark_handshake_verified', false ),
					'offline'     => $offline,
				)
			)
		);
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
