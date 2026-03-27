<?php
/**
 * PressArk Entitlements — Unified entitlement service (v3.5.0).
 *
 * Single source of truth for:
 *   - Tier definitions (token budgets, limits, features)
 *   - BYOK mode
 *   - Write policy and group-sampling quotas
 *   - Model access gating
 *   - Plan info payloads
 *
 * All other classes (Token_Bank, Reservation, Usage_Tracker, Model_Policy,
 * Chat pipeline) delegate to this class for tier/grant/quota decisions.
 *
 * @package PressArk
 * @since   2.8.0
 * @since   3.5.0 Promoted to unified entitlement authority.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Entitlements {

	public const ICU_BASE_COST = 0.14;

	public const LEGACY_TIER_MAP = array(
		'business' => 'agency',
	);

	public const FREEMIUS_PLAN_MAP = array(
		'free'       => 'free',
		'pro'        => 'pro',
		'team'       => 'team',
		'agency'     => 'agency',
		'enterprise' => 'enterprise',
	);

	/** Freemius plan IDs — for reference / checkout links. */
	public const FREEMIUS_PLAN_IDS = array(
		'free'       => 43678,
		'pro'        => 43681,
		'team'       => 43682,
		'agency'     => 43683,
		'enterprise' => 43685,
	);

	/** Freemius "PressArk Credits" SaaS product for one-off credit purchases. */
	public const CREDITS_PRODUCT_ID = 26385;
	public const CREDITS_PUBLIC_KEY = 'pk_0ea7686cd81ea4e278f5c183b22cf';
	public const CREDITS_PLAN_ID   = 43699;

	public const CREDIT_PACKS = array(
		'pack_1' => array(
			'icus'               => 800000,
			'price_cents'        => 500,
			'label'              => '800K credits',
			'freemius_pricing_id' => 57278,
		),
		'pack_2' => array(
			'icus'               => 2600000,
			'price_cents'        => 1500,
			'label'              => '2.6M credits',
			'freemius_pricing_id' => 57279,
		),
		'pack_3' => array(
			'icus'               => 6000000,
			'price_cents'        => 3000,
			'label'              => '6M credits',
			'freemius_pricing_id' => 57280,
		),
	);

	// ── Tier Configuration ────────────────────────────────────────
	// ONE place for every tier-specific number in the system.

	/**
	 * Complete tier configuration matrix.
	 *
	 * token_budget    — monthly token allowance for the token-bank service.
	 * output_buffer   — estimated output tokens per request (reservation sizing).
	 * group_limit     — max non-read tool calls per week across ALL groups (0 = unlimited).
	 * write_limit     — max write actions per month via legacy tracker (0 = unlimited).
	 * deep_mode       — whether deep-mode model upgrades are available.
	 * max_agent_rounds — hard agent loop cap (from v3.2.0 orchestration).
	 * max_sites       — license seat count (-1 = unlimited).
	 * concurrency     — max simultaneous AI requests.
	 * default_model   — tier default model identifier.
	 * label           — human-readable display name.
	 */
	public const TIER_CONFIG = array(
		'free' => array(
			'icu_budget'              => 100000,
			'token_budget'            => 100000,
			'output_buffer'           => 3000,
			'group_limit'             => 6,
			'write_limit'             => 5,
			'deep_mode'               => false,
			'max_agent_rounds'        => 8,
			'agent_token_budget'      => 3000,
			'workflow_token_budget'   => 2000,
			'burst_per_min'           => 5,
			'hourly_limit'            => 30,
			'ip_per_min'              => 10,
			'max_sites'               => 1,
			'concurrency'             => 1,
			'default_model'           => 'deepseek/deepseek-v3.2',
			'label'                   => 'Free',
			'max_automations'         => 0,
			'min_automation_interval' => 0,
			'max_request_icus'        => 50000,
		),
		'pro' => array(
			'icu_budget'              => 5000000,
			'token_budget'            => 5000000,
			'output_buffer'           => 8000,
			'group_limit'             => 0,
			'write_limit'             => 0,
			'deep_mode'               => true,
			'max_agent_rounds'        => 30,
			'agent_token_budget'      => 8000,
			'workflow_token_budget'   => 5000,
			'burst_per_min'           => 10,
			'hourly_limit'            => 120,
			'ip_per_min'              => 20,
			'max_sites'               => 1,
			'concurrency'             => 2,
			'default_model'           => 'anthropic/claude-sonnet-4.6',
			'label'                   => 'Pro',
			'max_automations'         => 5,
			'min_automation_interval' => 3600,
			'max_request_icus'        => 300000,
		),
		'team' => array(
			'icu_budget'              => 15000000,
			'token_budget'            => 15000000,
			'output_buffer'           => 15000,
			'group_limit'             => 0,
			'write_limit'             => 0,
			'deep_mode'               => true,
			'max_agent_rounds'        => 30,
			'agent_token_budget'      => 15000,
			'workflow_token_budget'   => 10000,
			'burst_per_min'           => 15,
			'hourly_limit'            => 200,
			'ip_per_min'              => 30,
			'max_sites'               => 5,
			'concurrency'             => 3,
			'default_model'           => 'anthropic/claude-sonnet-4.6',
			'label'                   => 'Team',
			'max_automations'         => 15,
			'min_automation_interval' => 1800,
			'max_request_icus'        => 500000,
		),
		'agency' => array(
			'icu_budget'              => 40000000,
			'token_budget'            => 40000000,
			'output_buffer'           => 20000,
			'group_limit'             => 0,
			'write_limit'             => 0,
			'deep_mode'               => true,
			'max_agent_rounds'        => 30,
			'agent_token_budget'      => 20000,
			'workflow_token_budget'   => 15000,
			'burst_per_min'           => 20,
			'hourly_limit'            => 300,
			'ip_per_min'              => 40,
			'max_sites'               => 25,
			'concurrency'             => 5,
			'default_model'           => 'anthropic/claude-sonnet-4.6',
			'label'                   => 'Agency',
			'max_automations'         => 50,
			'min_automation_interval' => 900,
			'max_request_icus'        => 1000000,
		),
		'enterprise' => array(
			'icu_budget'              => 100000000,
			'token_budget'            => 100000000,
			'output_buffer'           => 40000,
			'group_limit'             => 0,
			'write_limit'             => 0,
			'deep_mode'               => true,
			'max_agent_rounds'        => 30,
			'agent_token_budget'      => 40000,
			'workflow_token_budget'   => 30000,
			'burst_per_min'           => 30,
			'hourly_limit'            => 500,
			'ip_per_min'              => 60,
			'max_sites'               => -1,
			'concurrency'             => 10,
			'default_model'           => 'anthropic/claude-sonnet-4.6',
			'label'                   => 'Enterprise',
			'max_automations'         => -1,
			'min_automation_interval' => 300,
			'max_request_icus'        => 2500000,
		),
	);

	/** Features requiring Pro+ tier. */
	private const PRO_FEATURES = array( 'deep_mode', 'automations' );

	/** Groups whose tools never count toward sampling limits. */
	public const UNLIMITED_GROUPS = array( 'discovery', 'core' );

	/** Tier hierarchy — ordered from lowest to highest. */
	public const TIER_ORDER = array( 'free', 'pro', 'team', 'agency', 'enterprise' );

	// ── Tier Accessors ────────────────────────────────────────────

	/**
	 * Get a tier config value. Returns the free-tier value for unknown tiers.
	 *
	 * @param string $tier Tier name.
	 * @param string $key  Config key from TIER_CONFIG.
	 * @return mixed
	 */
	public static function normalize_tier( string $tier ): string {
		$normalized = strtolower( trim( $tier ) );
		return self::LEGACY_TIER_MAP[ $normalized ]
			?? ( isset( self::TIER_CONFIG[ $normalized ] ) ? $normalized : 'free' );
	}

	public static function tier_value( string $tier, string $key ) {
		$tier = self::normalize_tier( $tier );
		return self::TIER_CONFIG[ $tier ][ $key ]
			?? self::TIER_CONFIG['free'][ $key ]
			?? null;
	}

	/**
	 * Get the full config array for a tier.
	 */
	public static function tier_config( string $tier ): array {
		$tier = self::normalize_tier( $tier );
		return self::TIER_CONFIG[ $tier ] ?? self::TIER_CONFIG['free'];
	}

	/**
	 * Get the human-readable label for a tier.
	 */
	public static function tier_label( string $tier ): string {
		return self::tier_value( $tier, 'label' );
	}

	public static function icu_budget( string $tier ): int {
		return (int) self::tier_value( $tier, 'icu_budget' );
	}

	/**
	 * Get token budget for a tier.
	 *
	 * @deprecated 5.0.0 Use icu_budget().
	 */
	public static function token_budget( string $tier ): int {
		return self::icu_budget( $tier );
	}

	/**
	 * Get output buffer size for reservation estimates.
	 */
	public static function output_buffer( string $tier ): int {
		return (int) self::tier_value( $tier, 'output_buffer' );
	}

	/**
	 * Get the default model for a tier.
	 */
	public static function default_model( string $tier ): string {
		return (string) self::tier_value( $tier, 'default_model' );
	}

	/**
	 * Get max agent rounds for a tier.
	 */
	public static function max_agent_rounds( string $tier ): int {
		return (int) self::tier_value( $tier, 'max_agent_rounds' );
	}

	/**
	 * Check if a tier is Pro or above.
	 */
	public static function is_paid_tier( string $tier ): bool {
		$tier = self::normalize_tier( $tier );
		return 'free' !== $tier && isset( self::TIER_CONFIG[ $tier ] );
	}

	/**
	 * Compare two tiers. Returns -1, 0, or 1.
	 */
	public static function compare_tiers( string $a, string $b ): int {
		$a       = self::normalize_tier( $a );
		$b       = self::normalize_tier( $b );
		$order_a = array_search( $a, self::TIER_ORDER, true );
		$order_b = array_search( $b, self::TIER_ORDER, true );
		if ( false === $order_a ) $order_a = 0;
		if ( false === $order_b ) $order_b = 0;
		return $order_a <=> $order_b;
	}

	// ── BYOK ──────────────────────────────────────────────────────

	/**
	 * Check if the site is in BYOK (Bring Your Own Key) mode.
	 * BYOK bypasses token-bank billing but does NOT change tier entitlements.
	 */
	public static function is_byok(): bool {
		return (bool) get_option( 'pressark_byok_enabled', false );
	}

	// ── Feature Gate ────────────────────────────────────────────────

	/**
	 * Check if a tier can use a specific feature.
	 *
	 * @param string $tier    User's current tier.
	 * @param string $feature Feature key (e.g., 'deep_mode').
	 * @return bool
	 */
	public static function can_use_feature( string $tier, string $feature ): bool {
		if ( in_array( $feature, self::PRO_FEATURES, true ) ) {
			return self::is_paid_tier( $tier );
		}
		// Check tier config for feature-specific flag.
		$val = self::tier_value( $tier, $feature );
		if ( is_bool( $val ) ) {
			return $val;
		}
		return true;
	}

	// ── Write Policy ──────────────────────────────────────────────

	/**
	 * Check if a user on this tier can perform write actions.
	 * Unified check — replaces scattered is_pro() + has_any_remaining() calls.
	 *
	 * @param string $tier Current tier.
	 * @return bool
	 */
	public static function can_write( string $tier ): bool {
		if ( self::is_paid_tier( $tier ) ) {
			return true;
		}
		return self::has_any_remaining();
	}

	/**
	 * Get the free-tier write limit.
	 */
	public static function write_limit( string $tier ): int {
		$limit = (int) self::tier_value( $tier, 'write_limit' );
		return $limit > 0 ? (int) apply_filters( 'pressark_free_limit', $limit ) : 0;
	}

	// ── Group Usage Check ───────────────────────────────────────────

	/**
	 * Check if a tool call is allowed under the entitlement model.
	 *
	 * Free tier: 6 non-read tool calls per week across ALL groups combined.
	 *
	 * @param string $tier            User's current tier.
	 * @param string $group           Tool group name.
	 * @param string $tool_capability Tool classification: 'read', 'preview', or 'confirm'.
	 * @return array { allowed: bool, remaining?: int, error?: string, message?: string, ... }
	 */
	public static function check_group_usage( string $tier, string $group, string $tool_capability ): array {
		// Pro+ users: always allowed.
		if ( self::is_paid_tier( $tier ) ) {
			return array( 'allowed' => true );
		}

		// Read tools: always allowed for everyone.
		if ( 'read' === $tool_capability ) {
			return array( 'allowed' => true );
		}

		// Always-unlimited groups.
		if ( in_array( $group, self::UNLIMITED_GROUPS, true ) ) {
			return array( 'allowed' => true );
		}

		// Free tier: check weekly total across ALL groups.
		$group_limit = (int) self::tier_value( $tier, 'group_limit' );
		if ( $group_limit <= 0 ) {
			return array( 'allowed' => true );
		}

		$total_used = self::get_total_usage();

		if ( $total_used >= $group_limit ) {
			return self::denied_error( $group, $tier, $total_used, $group_limit );
		}

		return array(
			'allowed'   => true,
			'remaining' => $group_limit - $total_used,
		);
	}

	/**
	 * Record a successful non-read tool call for the current user.
	 * Only increments for free-tier users on non-unlimited groups.
	 * Tracks per-group for analytics; enforcement is against the weekly total.
	 *
	 * @param string $group Tool group name.
	 */
	public static function record_group_usage( string $group ): void {
		// Only track for free tier.
		$tier = ( new PressArk_License() )->get_tier();
		if ( self::is_paid_tier( $tier ) ) {
			return;
		}

		// Never count unlimited groups.
		if ( in_array( $group, self::UNLIMITED_GROUPS, true ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$usage = self::get_group_usage();
		$usage['groups'][ $group ] = ( $usage['groups'][ $group ] ?? 0 ) + 1;

		update_user_meta( $user_id, 'pressark_group_usage', $usage );
	}

	/**
	 * Get the total non-read tool calls used this week across all groups.
	 *
	 * @return int Total used count.
	 */
	public static function get_total_usage(): int {
		$usage = self::get_group_usage();
		$total = 0;

		foreach ( ( $usage['groups'] ?? array() ) as $group => $count ) {
			if ( in_array( $group, self::UNLIMITED_GROUPS, true ) ) {
				continue;
			}
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * Get remaining tool calls for the week (total across all groups).
	 *
	 * @param string $tier User tier.
	 * @return int Remaining calls (0 = fully exhausted).
	 */
	public static function min_remaining_across_groups( string $tier = 'free' ): int {
		$group_limit = (int) self::tier_value( $tier, 'group_limit' );
		if ( $group_limit <= 0 ) {
			return PHP_INT_MAX;
		}

		return max( 0, $group_limit - self::get_total_usage() );
	}

	/**
	 * Get total tool calls used this week across all groups.
	 *
	 * @param string $tier User tier.
	 * @return int Total used count.
	 */
	public static function max_used_across_groups( string $tier = 'free' ): int {
		$group_limit = (int) self::tier_value( $tier, 'group_limit' );
		if ( $group_limit <= 0 ) {
			return 0;
		}

		return self::get_total_usage();
	}

	/**
	 * Check if the weekly tool-call total is still under the limit.
	 *
	 * @return bool True if under the weekly limit.
	 */
	public static function has_any_remaining(): bool {
		$group_limit = (int) self::tier_value( 'free', 'group_limit' );
		if ( $group_limit <= 0 ) {
			return true;
		}

		return self::get_total_usage() < $group_limit;
	}

	// ── Plan Info ───────────────────────────────────────────────────

	/**
	 * Build plan_info payload for REST responses and frontend localization.
	 *
	 * @param string $tier Current tier.
	 * @return array Plan info structure.
	 */
	public static function get_plan_info( string $tier ): array {
		$tier   = self::normalize_tier( $tier );
		$config = self::tier_config( $tier );

		$info = array(
			'tier'              => $tier,
			'tier_label'        => $config['label'],
			'icu_budget'        => (int) $config['icu_budget'],
			'token_budget'      => (int) $config['token_budget'],
			'is_byok'           => self::is_byok(),
			'upgrade_url'       => pressark_get_upgrade_url(),
			'can_buy_credits'   => ! self::is_byok() && self::is_paid_tier( $tier ),
			'credits_remaining' => 0,
			'credits_packs'     => array(),
		);

		if ( ! self::is_byok() ) {
			$bank   = new PressArk_Token_Bank();
			$status = $bank->get_status();
			$credits = $bank->get_credits();
			$info['icus_used']         = (int) ( $status['icus_used'] ?? 0 );
			$info['icus_remaining']    = (int) ( $status['icus_remaining'] ?? $config['icu_budget'] );
			$info['monthly_remaining'] = (int) ( $status['monthly_remaining'] ?? max( 0, $config['icu_budget'] - $info['icus_used'] ) );
			$info['monthly_exhausted'] = ! empty( $status['monthly_exhausted'] );
			$info['using_purchased_credits'] = ! empty( $status['using_purchased_credits'] );
			$info['credits_remaining'] = (int) ( $status['credits_remaining'] ?? 0 );
			$info['credits_packs']     = (array) ( $credits['credits'] ?? array() );
			$info['total_available']   = (int) ( $status['total_available'] ?? ( $config['icu_budget'] + $info['credits_remaining'] ) );
			$info['total_remaining']   = (int) ( $status['total_remaining'] ?? $info['icus_remaining'] );
			$info['next_reset_at']     = (string) ( $status['next_reset_at'] ?? '' );
			$info['billing_period_start'] = (string) ( $status['billing_period_start'] ?? '' );
			$info['billing_period_end']   = (string) ( $status['billing_period_end'] ?? '' );
			$info['uses_anniversary_reset'] = ! empty( $status['uses_anniversary_reset'] );
			$info['raw_tokens_used']   = (int) ( $status['raw_tokens_used'] ?? 0 );
			$info['tokens_used']       = $info['icus_used'];
			$info['tokens_remaining']  = $info['icus_remaining'];
		} else {
			$info['icus_used']        = 0;
			$info['icus_remaining']   = 0;
			$info['monthly_remaining'] = 0;
			$info['monthly_exhausted'] = false;
			$info['using_purchased_credits'] = false;
			$info['total_available']  = 0;
			$info['total_remaining']  = 0;
			$info['next_reset_at']    = '';
			$info['billing_period_start'] = '';
			$info['billing_period_end']   = '';
			$info['uses_anniversary_reset'] = false;
			$info['raw_tokens_used']  = 0;
			$info['tokens_used']      = 0;
			$info['tokens_remaining'] = 0;
		}

		if ( 'free' === $tier ) {
			$usage       = self::get_group_usage();
			$group_limit = $config['group_limit'];
			$total_used  = self::get_total_usage();

			// Per-group breakdown for analytics/display.
			$group_breakdown = array();
			$all_groups      = PressArk_Operation_Registry::group_names();

			foreach ( $all_groups as $group ) {
				if ( in_array( $group, self::UNLIMITED_GROUPS, true ) ) {
					continue;
				}
				$group_breakdown[ $group ] = $usage['groups'][ $group ] ?? 0;
			}

			$info['group_usage'] = array(
				'total_used'      => $total_used,
				'total_limit'     => $group_limit,
				'total_remaining' => max( 0, $group_limit - $total_used ),
				'per_group'       => $group_breakdown,
			);
		} else {
			$info['group_usage'] = null; // No limits.
		}

		return $info;
	}

	// ── User-Facing Limit Messages ─────────────────────────────────

	/**
	 * Get an accurate limit-reached message for the user.
	 *
	 * @param string $reason 'token_budget' | 'group_limit' | 'write_limit'.
	 * @param string $tier   Current tier.
	 * @return string
	 */
	public static function limit_message( string $reason, string $tier ): string {
		$tier  = self::normalize_tier( $tier );
		$label = self::tier_label( $tier );

		switch ( $reason ) {
			case 'token_budget':
				if ( 'enterprise' === $tier ) {
					return 'Your Enterprise plan\'s billing-cycle credit budget has been reached (100M credits). Contact support@pressark.io for custom capacity arrangements.';
				}

				if ( 'free' === $tier ) {
					return 'Your monthly credits are used up. Upgrade to Pro for 50x more credits and premium AI models.';
				}

				$bank            = new PressArk_Token_Bank();
				$status          = $bank->get_status();
				$credits         = $bank->get_credits();
				$total_purchased = (int) ( $credits['total_purchased'] ?? 0 );

				if ( ! empty( $status['monthly_exhausted'] ) && $total_purchased > 0 && (int) ( $status['credits_remaining'] ?? 0 ) <= 0 ) {
					return 'All credits used - billing-cycle and purchased. Buy more or wait for your next billing-cycle reset.';
				}

				return sprintf(
					'Your billing-cycle credits are used up on the %s plan. Buy more credits or upgrade your plan for more allowance.',
					$label
				);
			case 'group_limit':
				return sprintf(
					'You\'ve used all %d free tool actions for this week. Scans, analysis, and reading remain unlimited. Your limit resets every Monday. Upgrade to Pro for unlimited access to all tools.',
					self::tier_value( $tier, 'group_limit' )
				);
			case 'write_limit':
				return sprintf(
					'You\'ve used all %d free edits this week. Scans, analysis, and reading remain unlimited. Upgrade to Pro for unlimited edits — plans start at $19/mo.',
					self::tier_value( $tier, 'write_limit' )
				);
			default:
				return 'Usage limit reached for the current billing period. Upgrade for more capacity.';
		}
	}

	// ── Error Response ──────────────────────────────────────────────

	/**
	 * Build a denied-feature error response.
	 *
	 * @param string $group        Tool group that was denied.
	 * @param string $current_tier User's current tier.
	 * @param int    $used         Total calls used this week.
	 * @param int    $limit        Weekly limit.
	 * @return array Structured error with upgrade info.
	 */
	public static function denied_error( string $group, string $current_tier, int $used, int $limit ): array {
		$upgrade_url = pressark_get_upgrade_url();

		return array(
			'allowed'     => false,
			'success'     => false,
			'error'       => 'entitlement_denied',
			'message'     => sprintf(
				'You\'ve used all %d free tool actions this week. Your limit resets every Monday. Upgrade to Pro for unlimited access to all tools.',
				$limit
			),
			'group'       => $group,
			'used'        => $used,
			'limit'       => $limit,
			'upgrade_url' => $upgrade_url,
		);
	}

	// ── Internal ────────────────────────────────────────────────────

	/**
	 * Get the current user's group usage data for this week.
	 * Auto-resets when ISO week changes.
	 *
	 * @return array { week: string, groups: array<string, int> }
	 */
	private static function get_group_usage(): array {
		$user_id      = get_current_user_id();
		$current_week = gmdate( 'o-W' ); // ISO year-week, e.g. "2026-12".

		if ( ! $user_id ) {
			return array( 'week' => $current_week, 'groups' => array() );
		}

		$data = get_user_meta( $user_id, 'pressark_group_usage', true );

		// Reset on new week, or if still using old monthly format (migration).
		if ( ! is_array( $data ) || ( $data['week'] ?? '' ) !== $current_week ) {
			$fresh = array( 'week' => $current_week, 'groups' => array() );
			update_user_meta( $user_id, 'pressark_group_usage', $fresh );
			return $fresh;
		}

		return $data;
	}

	// ── Back-compat ────────────────────────────────────────────────

	/**
	 * @deprecated 3.5.0 Use tier_label() instead.
	 */
	public const TIER_LABELS = array(
		'free'       => 'Free',
		'pro'        => 'Pro',
		'team'       => 'Team',
		'agency'     => 'Agency',
		'business'   => 'Agency',
		'enterprise' => 'Enterprise',
	);

}
