<?php
/**
 * PressArk license manager backed by Freemius.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_License {

	private string $cache_key;

	/**
	 * v5.0.1: Per-request static tier cache. Avoids repeated Freemius SDK
	 * lookups and option reads when multiple code paths resolve the tier
	 * in the same HTTP request (preflight, confirm, preview keep, etc.).
	 *
	 * Keyed by user ID to handle edge cases (e.g. cron switching user context).
	 *
	 * @var array<int, string>
	 */
	private static array $tier_cache = array();

	public function __construct() {
		$this->cache_key = 'pressark_license_cache_' . get_current_user_id();
	}

	public function get_license_data(): array {
		$tier = $this->get_tier();

		return array(
			'valid'   => 'free' !== $tier,
			'tier'    => $tier,
			'cached'  => false,
			'offline' => false,
		);
	}

	public function activate( string $key ): array {
		delete_transient( $this->cache_key );
		unset( $key );
		return $this->get_license_data();
	}

	public function deactivate(): void {
		delete_transient( $this->cache_key );
		delete_option( 'pressark_cached_tier' );
	}

	public function is_pro(): bool {
		return PressArk_Entitlements::is_paid_tier( $this->get_tier() );
	}

	public function get_tier(): string {
		$uid = get_current_user_id();

		// v5.0.1: Return cached tier for this request to avoid redundant Freemius lookups.
		if ( isset( self::$tier_cache[ $uid ] ) ) {
			return self::$tier_cache[ $uid ];
		}

		$env_type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( defined( 'PRESSARK_DEV_TIER' ) && PRESSARK_DEV_TIER && in_array( $env_type, array( 'local', 'development' ), true ) ) {
			$tier = PressArk_Entitlements::normalize_tier( (string) PRESSARK_DEV_TIER );
			self::$tier_cache[ $uid ] = $tier;
			return $tier;
		}

		if ( ! function_exists( 'pressark_fs' ) ) {
			$tier = PressArk_Entitlements::normalize_tier( (string) get_option( 'pressark_cached_tier', 'free' ) );
			self::$tier_cache[ $uid ] = $tier;
			return $tier;
		}

		$fs = pressark_fs();
		if ( ! $fs || ! $fs->is_paying_or_trial() ) {
			update_option( 'pressark_cached_tier', 'free', false );
			self::$tier_cache[ $uid ] = 'free';
			return 'free';
		}

		$plan = $fs->get_plan();
		if ( ! $plan || empty( $plan->name ) ) {
			update_option( 'pressark_cached_tier', 'free', false );
			self::$tier_cache[ $uid ] = 'free';
			return 'free';
		}

		$plan_name = strtolower( (string) $plan->name );
		$tier      = PressArk_Entitlements::FREEMIUS_PLAN_MAP[ $plan_name ] ?? 'free';

		update_option( 'pressark_cached_tier', $tier, false );
		self::$tier_cache[ $uid ] = $tier;

		return $tier;
	}

	public function is_byok(): bool {
		return PressArk_Entitlements::is_byok();
	}
}
