<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks free-tier usage. Only WRITE actions count toward the limit.
 * Read actions (scans, listing, reading) are always unlimited.
 *
 * v3.5.0: Delegates tier/BYOK/write-policy checks to PressArk_Entitlements.
 * Local is_pro() HMAC check retained only as offline fallback — the license
 * server (via PressArk_License) is the canonical authority.
 */
class PressArk_Usage_Tracker {

	/**
	 * Actions that are always free (read-only).
	 */
	const FREE_ACTIONS = array(
		'read_content',
		'search_content',
		'list_posts',
		'analyze_seo',
		'scan_security',
		'analyze_store',
		// Prompt 13 discovery reads.
		'get_site_overview',
		'get_site_map',
		'get_brand_profile',
		'get_available_tools',
		// Prompt 6 reads.
		'get_site_settings',
		'get_menus',
		'list_media',
		'get_media',
		'list_comments',
		'list_taxonomies',
		'list_orders',
		'get_order',
		'inventory_report',
		'sales_summary',
		// Prompt 7 reads.
		'get_email_log',
		'list_users',
		'get_user',
		'site_health',
		'list_scheduled_tasks',
		'list_customers',
		'get_customer',
		'get_shipping_zones',
		'get_tax_settings',
		'get_payment_gateways',
		'get_wc_settings',
		'get_wc_emails',
		'get_wc_status',
		'list_reviews',
		// Prompt 8 reads.
		'generate_content',
		'rewrite_content',
		'generate_bulk_meta',
		'export_report',
		// Prompt 9 reads.
		'view_site_profile',
		'list_logs',
		'read_log',
		'analyze_logs',
		// Prompt 11 reads.
		'search_knowledge',
		'index_status',
		// Prompt 12 reads.
		'elementor_read_page',
		'elementor_find_widgets',
		'elementor_list_templates',
		'elementor_get_styles',
		'list_plugins',
		'list_themes',
		'get_theme_settings',
		'database_stats',
		'list_variations',
		'get_top_sellers',
	);

	/**
	 * Actions that count toward the monthly limit (write operations).
	 */
	const PAID_ACTIONS = array(
		'edit_content',
		'update_meta',
		'create_post',
		'delete_content',
		'fix_seo',
		'fix_security',
		'edit_product',
		'bulk_edit_products',
		// Prompt 6 writes.
		'update_site_settings',
		'update_menu',
		'update_media',
		'delete_media',
		'moderate_comments',
		'reply_comment',
		'manage_taxonomy',
		'assign_terms',
		'update_order',
		'manage_coupon',
		// Prompt 7 writes.
		'update_user',
		'manage_scheduled_task',
		'email_customer',
		'moderate_review',
		// Prompt 8 writes.
		'bulk_edit',
		'find_and_replace',
		// Prompt 9 writes.
		'refresh_site_profile',
		'clear_log',
		// Prompt 11 writes.
		'rebuild_index',
		// Prompt 12 writes.
		'elementor_edit_widget',
		'elementor_create_from_template',
		'elementor_find_replace',
		'toggle_plugin',
		'update_theme_setting',
		'switch_theme',
		'cleanup_database',
		'optimize_database',
		'edit_variation',
		'create_refund',
		'create_order',
		// Preview system: applying preview counts as a write.
		'preview_apply',
	);

	/**
	 * Get the option key for the current user and month.
	 */
	private function get_option_key(): string {
		$user_id    = get_current_user_id();
		$year_month = gmdate( 'Y_m' );
		return "pressark_usage_{$user_id}_{$year_month}";
	}

	/**
	 * Check if an action type is a write (paid) action.
	 */
	public function is_write_action( string $action_type ): bool {
		return in_array( $action_type, self::PAID_ACTIONS, true );
	}

	/**
	 * Get the filterable free-tier write limit.
	 *
	 * v3.5.0: Delegates to PressArk_Entitlements for the canonical value.
	 */
	public function get_free_limit(): int {
		return PressArk_Entitlements::write_limit( 'free' );
	}

	/**
	 * Check if user can perform a write action.
	 *
	 * v3.5.0: Single delegation to PressArk_Entitlements::can_write().
	 *
	 * @param string $tier Optional tier override. If empty, resolves from license.
	 */
	public function can_perform_write( string $tier = '' ): bool {
		if ( empty( $tier ) ) {
			$tier = ( new PressArk_License() )->get_tier();
		}
		return PressArk_Entitlements::can_write( $tier );
	}

	/**
	 * Increment usage counter only for write actions (atomic SQL).
	 */
	public function increment_if_write( string $action_type ): void {
		if ( ! $this->is_write_action( $action_type ) ) {
			return;
		}

		global $wpdb;
		$key = $this->get_option_key();

		// Atomic increment — avoids race condition between concurrent requests.
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, 1, 'no')
			 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
			$key
		) );

		// Clear WP object cache for this key so subsequent reads are fresh.
		wp_cache_delete( $key, 'options' );
	}

	/**
	 * Get number of write actions used this month.
	 */
	public function get_write_count(): int {
		return (int) get_option( $this->get_option_key(), 0 );
	}

	/**
	 * Get remaining write actions.
	 *
	 * v3.5.0: Delegates to PressArk_Entitlements for tier check.
	 * v3.5.1: Reports from the entitlement model (group-quota based) rather
	 *         than the legacy flat write counter, so the number the user sees
	 *         matches the number that actually gates their access.
	 */
	public function get_writes_remaining( string $tier = '' ): int {
		if ( empty( $tier ) ) {
			$tier = ( new PressArk_License() )->get_tier();
		}
		if ( PressArk_Entitlements::is_paid_tier( $tier ) ) {
			return PHP_INT_MAX;
		}

		// Report from the real entitlement model: min remaining across groups.
		return PressArk_Entitlements::min_remaining_across_groups( $tier );
	}

	/**
	 * Check if user is on a paid plan.
	 *
	 * v3.5.0: Delegates to PressArk_License::get_tier() →
	 * PressArk_Entitlements::is_paid_tier(). The Freemius SDK handles
	 * offline caching internally — no separate HMAC check is needed.
	 */
	public function is_pro(): bool {
		return PressArk_Entitlements::is_paid_tier( ( new PressArk_License() )->get_tier() );
	}

	/**
	 * Back-compat shim for older callers that expected a manual activation step.
	 */
	public function activate_license( string $key ): bool {
		unset( $key );
		delete_option( 'pressark_license_sig' );
		delete_option( 'pressark_license_key' );
		return PressArk_Entitlements::is_paid_tier( ( new PressArk_License() )->get_tier() );
	}

	/**
	 * Get full usage data for display.
	 *
	 * v3.5.1: Reports from the real entitlement model (group-quota based)
	 * so the user sees the same numbers that actually gate their access.
	 * The legacy flat write counter is still tracked for telemetry but no
	 * longer drives the user-facing "remaining" number.
	 */
	public function get_usage_data(): array {
		$tier   = ( new PressArk_License() )->get_tier();
		$is_pro = PressArk_Entitlements::is_paid_tier( $tier );

		if ( $is_pro ) {
			return array(
				'writes_used'      => 0,
				'writes_limit'     => 0,
				'writes_remaining' => PHP_INT_MAX,
				'is_pro'           => true,
				'reads'            => 'unlimited',
			);
		}

		$group_limit = (int) PressArk_Entitlements::tier_value( $tier, 'group_limit' );
		$max_used    = PressArk_Entitlements::max_used_across_groups( $tier );
		$remaining   = PressArk_Entitlements::min_remaining_across_groups( $tier );

		return array(
			'writes_used'      => $max_used,
			'writes_limit'     => $group_limit,
			'writes_remaining' => $remaining,
			'is_pro'           => false,
			'reads'            => 'unlimited',
		);
	}

	/**
	 * Get usage stats for settings page.
	 *
	 * v3.5.1: edits_used reports from entitlement model (group-based) not
	 * the legacy flat counter, so settings page matches real gating.
	 */
	public function get_monthly_stats(): array {
		global $wpdb;

		$user_id     = get_current_user_id();
		$table       = $wpdb->prefix . 'pressark_log';
		$month_start = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name.
		$total_actions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$month_start
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name.
		$seo_scans = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s AND action_type = %s",
				$user_id,
				$month_start,
				'analyze_seo'
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded prefixed table name.
		$security_scans = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s AND action_type = %s",
				$user_id,
				$month_start,
				'scan_security'
			)
		);

		$tier   = ( new PressArk_License() )->get_tier();
		$is_pro = PressArk_Entitlements::is_paid_tier( $tier );

		return array(
			'edits_used'     => $is_pro ? 0 : PressArk_Entitlements::max_used_across_groups( $tier ),
			'edits_limit'    => (int) PressArk_Entitlements::tier_value( $tier, 'group_limit' ),
			'seo_scans'      => $seo_scans,
			'security_scans' => $security_scans,
			'total_actions'  => $total_actions,
			'is_pro'         => $is_pro,
		);
	}

	// ── Token Estimation ──────────────────────────────────────────────

	/**
	 * Estimate total input tokens for an API request.
	 * Uses ~4 chars per token heuristic.
	 *
	 * @param string $system_context Combined tools + context string.
	 * @param array  $history        Compressed conversation history.
	 * @param string $user_message   Current user message.
	 * @return int Estimated token count.
	 */
	public static function estimate_request_tokens( string $system_context, array $history, string $user_message ): int {
		// Base system prompt is ~1,240 tokens (constant, added by AI connector).
		$base_prompt_tokens = 1240;

		// System context (tools + site context).
		$context_tokens = (int) ceil( mb_strlen( $system_context ) / 4 );

		// Conversation history.
		$history_tokens = PressArk_History_Manager::count_tokens( $history );

		// Current user message.
		$message_tokens = (int) ceil( mb_strlen( $user_message ) / 4 );

		// Overhead: message structure, role tokens, separators (~50 tokens).
		$overhead = 50 + ( count( $history ) * 4 );

		return $base_prompt_tokens + $context_tokens + $history_tokens + $message_tokens + $overhead;
	}

	// ── Token Tracking ───────────────────────────────────────────────

	/**
	 * Track token usage for a request (for cost monitoring).
	 * Stores cumulative per-user, per-month totals in wp_options.
	 *
	 * @param int    $input_tokens  Estimated input tokens.
	 * @param int    $output_tokens Estimated output tokens (from API response).
	 * @param string $model         Model used (e.g., 'gpt-4o-mini').
	 * @param array  $meta          Optional metadata: intent, tool_count, context_level.
	 */
	public static function track_tokens( int $input_tokens, int $output_tokens = 0, string $model = '', array $meta = array() ): void {
		$user_id    = get_current_user_id();
		$year_month = gmdate( 'Y_m' );
		$key        = "pressark_tokens_{$user_id}_{$year_month}";

		$data = get_option( $key, array(
			'total_input'    => 0,
			'total_output'   => 0,
			'request_count'  => 0,
			'models'         => array(),
		) );

		$data['total_input']   += $input_tokens;
		$data['total_output']  += $output_tokens;
		$data['request_count'] += 1;

		if ( ! empty( $model ) ) {
			if ( ! isset( $data['models'][ $model ] ) ) {
				$data['models'][ $model ] = 0;
			}
			$data['models'][ $model ] += 1;
		}

		update_option( $key, $data, false );

		// Also log individual request for debugging (rotates via daily cron if needed).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			PressArk_Error_Tracker::debug( 'UsageTracker', 'Token usage recorded', array( 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'model' => $model ?: 'unknown', 'intent' => $meta['intent'] ?? '-', 'tool_count' => $meta['tool_count'] ?? '-', 'context_level' => $meta['context_level'] ?? '-' ) );
		}
	}

	/**
	 * Get token usage stats for the current user and month.
	 *
	 * @return array Token usage data.
	 */
	public static function get_token_stats(): array {
		$user_id    = get_current_user_id();
		$year_month = gmdate( 'Y_m' );
		$key        = "pressark_tokens_{$user_id}_{$year_month}";

		$data = get_option( $key, array(
			'total_input'    => 0,
			'total_output'   => 0,
			'request_count'  => 0,
			'models'         => array(),
		) );

		$data['avg_input']  = $data['request_count'] > 0 ? (int) ( $data['total_input'] / $data['request_count'] ) : 0;
		$data['avg_output'] = $data['request_count'] > 0 ? (int) ( $data['total_output'] / $data['request_count'] ) : 0;

		return $data;
	}

	// ── BYOK (Bring Your Own Key) ───────────────────────────────────────

	public function is_byok(): bool {
		return PressArk_Entitlements::is_byok();
	}

	public function get_byok_provider(): string {
		return get_option( 'pressark_byok_provider', 'openrouter' );
	}

	public function get_byok_api_key(): string {
		// Decrypt stored key
		$encrypted = get_option( 'pressark_byok_api_key', '' );
		return $encrypted ? self::decrypt_value( $encrypted ) : '';
	}

	/**
	 * Authenticated encryption using Sodium (XSalsa20-Poly1305).
	 * Public static so both bundled and BYOK key paths can share it.
	 *
	 * @since 4.3.0
	 */
	public static function encrypt_value( string $value ): string {
		$key   = sodium_crypto_generichash( AUTH_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$enc   = sodium_crypto_secretbox( $value, $nonce, $key );

		// v5.0.1: Store a fingerprint of AUTH_KEY so we can detect key rotation.
		update_option( 'pressark_auth_key_fingerprint', self::auth_key_fingerprint(), false );

		return base64_encode( $nonce . $enc );
	}

	public static function decrypt_value( string $value ): string {
		$key  = sodium_crypto_generichash( AUTH_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$data = base64_decode( $value, true );
		if ( false === $data || strlen( $data ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}
		$nonce  = substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$result = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		if ( false === $result ) {
			// v5.0.1: Decryption failed — check if AUTH_KEY was rotated.
			self::maybe_flag_key_rotation();
			return '';
		}

		return $result;
	}

	/**
	 * Generate a short fingerprint of the current AUTH_KEY.
	 *
	 * @since 5.0.0
	 * @return string 16-char hex fingerprint.
	 */
	public static function auth_key_fingerprint(): string {
		return substr( hash( 'sha256', AUTH_KEY . 'pressark_fp' ), 0, 16 );
	}

	/**
	 * Detect AUTH_KEY rotation and set a transient to surface an admin notice.
	 *
	 * Called when Sodium decryption fails. Compares the stored fingerprint
	 * against the current AUTH_KEY to distinguish "wrong key" from "corrupted data".
	 *
	 * @since 5.0.0
	 */
	private static function maybe_flag_key_rotation(): void {
		$stored_fp  = get_option( 'pressark_auth_key_fingerprint', '' );
		$current_fp = self::auth_key_fingerprint();

		if ( '' !== $stored_fp && $stored_fp !== $current_fp ) {
			// AUTH_KEY was rotated — flag for admin notice.
			set_transient( 'pressark_auth_key_rotated', '1', WEEK_IN_SECONDS );
			PressArk_Error_Tracker::warning(
				'Encryption',
				'AUTH_KEY rotation detected — BYOK API keys and encrypted secrets are no longer decryptable. Users must re-enter their API keys.',
				array( 'stored_fp' => $stored_fp, 'current_fp' => $current_fp )
			);
		}
	}

	/**
	 * Check whether a value is already Sodium-encrypted with the current AUTH_KEY.
	 *
	 * Used by sanitize callbacks to prevent double encryption when WordPress's
	 * update_option() → add_option() path calls sanitize_option() twice.
	 *
	 * @since 4.3.1
	 */
	public static function is_sodium_encrypted( string $value ): bool {
		$data = base64_decode( $value, true );
		if ( false === $data || strlen( $data ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return false;
		}
		$key    = sodium_crypto_generichash( AUTH_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce  = substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return false !== sodium_crypto_secretbox_open( $cipher, $nonce, $key );
	}

	/**
	 * Store an encrypted BYOK API key.
	 */
	public function save_byok_api_key( string $raw_key ): void {
		if ( empty( $raw_key ) ) {
			delete_option( 'pressark_byok_api_key' );
			return;
		}
		update_option( 'pressark_byok_api_key', self::encrypt_value( $raw_key ), false );
	}

	// ── Back-compat shims ──────────────────────────────────────────────

	/**
	 * @deprecated Use can_perform_write() instead.
	 */
	public function can_perform_action(): bool {
		return $this->can_perform_write();
	}

	/**
	 * @deprecated Use get_writes_remaining() instead.
	 */
	public function get_remaining(): int {
		return $this->get_writes_remaining();
	}

	/**
	 * @deprecated Use increment_if_write() instead.
	 */
	public function increment(): void {
		$key   = $this->get_option_key();
		$count = (int) get_option( $key, 0 );
		update_option( $key, $count + 1, false );
	}

	/**
	 * @deprecated Use get_write_count() instead.
	 */
	public function get_usage(): int {
		return $this->get_write_count();
	}

	/**
	 * @deprecated 3.5.0 Hardcoded constant removed. Use get_free_limit() instead.
	 */
	private const FREE_LIMIT_DEFAULT = 5;
}
