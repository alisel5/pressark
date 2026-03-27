<?php
/**
 * PressArk Capabilities - centralized capability registration and access checks.
 *
 * Introduces three custom capabilities:
 *   pressark_use                - Chat panel, REST API, Activity page
 *   pressark_manage_settings    - Settings page, Insights, Dashboard widget
 *   pressark_manage_automations - Scheduled Prompts CRUD
 *
 * Per-action WordPress capabilities (edit_post, manage_options, etc.) remain
 * in the handler layer. These custom caps control PressArk-specific gateways.
 *
 * @package PressArk
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Capabilities {

	/**
	 * Current capability schema version.
	 * Bump this when role defaults change.
	 */
	private const CAPS_VERSION = 2;

	/**
	 * Current migration version for legacy role/user access upgrades.
	 */
	private const MIGRATION_VERSION = 2;

	private const CAPS_VERSION_OPTION          = 'pressark_caps_version';
	private const MIGRATION_VERSION_OPTION     = 'pressark_caps_migration_version';
	private const LEGACY_MIGRATION_OPTION      = 'pressark_caps_migrated';
	private const USER_MIGRATION_CURSOR_OPTION = 'pressark_caps_user_migration_last_user';
	private const USER_MIGRATION_HOOK          = 'pressark_caps_continue_user_migration';
	private const USER_MIGRATION_BATCH_SIZE    = 200;

	/**
	 * Ensure hooks are only registered once.
	 */
	private static bool $bootstrapped = false;

	/**
	 * All custom capabilities managed by PressArk.
	 */
	const CAPS = array(
		'pressark_use',
		'pressark_manage_settings',
		'pressark_manage_automations',
	);

	/**
	 * Default role -> capability assignments for fresh installs.
	 */
	const ROLE_DEFAULTS = array(
		'administrator' => array( 'pressark_use', 'pressark_manage_settings', 'pressark_manage_automations' ),
		'editor'        => array( 'pressark_use' ),
		'shop_manager'  => array( 'pressark_use' ),
	);

	/**
	 * Capabilities that granted chat access under the old model.
	 * Used by migrate_existing_users() to ensure zero access loss.
	 */
	private const LEGACY_GATE_CAPS = array(
		'manage_options',
		'edit_posts',
		'edit_pages',
		'edit_products',
		'manage_woocommerce',
		'moderate_comments',
	);

	/**
	 * Access filter hooks keyed by the PressArk capability they govern.
	 */
	private const ACCESS_FILTERS = array(
		'pressark_use'                => 'pressark_can_use',
		'pressark_manage_settings'    => 'pressark_can_manage_settings',
		'pressark_manage_automations' => 'pressark_can_manage_automations',
	);

	/**
	 * Register runtime hooks needed for end-to-end capability resolution.
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 20, 4 );
		add_filter( 'option_page_capability_pressark_settings', array( __CLASS__, 'settings_option_capability' ) );
		add_action( self::USER_MIGRATION_HOOK, array( __CLASS__, 'continue_user_migration' ) );
	}

	/**
	 * Resolve PressArk access through WordPress' capability system so admin menus,
	 * options.php, REST, and wrapper checks all share the same authority.
	 *
	 * @param array  $caps    Primitive caps required so far.
	 * @param string $cap     Requested capability.
	 * @param int    $user_id User ID being checked.
	 * @param array  $args    Additional capability arguments.
	 * @return array
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! isset( self::ACCESS_FILTERS[ $cap ] ) ) {
			return $caps;
		}

		$user = get_userdata( $user_id );

		if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
			return array( 'do_not_allow' );
		}

		$base_access = self::user_has_stored_cap( $user, $cap );
		if ( ! $base_access && 'pressark_use' === $cap ) {
			$base_access = self::user_has_legacy_use_access( $user );
		}
		$allowed     = self::apply_access_filter( $cap, $base_access, $user->ID );

		return $allowed ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/**
	 * Allow delegated users to save the PressArk settings form through options.php.
	 */
	public static function settings_option_capability(): string {
		return 'pressark_manage_settings';
	}

	/**
	 * Register capabilities on roles. Idempotent - safe to call multiple times.
	 *
	 * Fires 'pressark_capabilities_registered' after registration completes.
	 */
	public static function register(): void {
		self::bootstrap();
		self::apply_role_defaults();

		update_option( self::CAPS_VERSION_OPTION, self::CAPS_VERSION, false );

		do_action( 'pressark_capabilities_registered' );
	}

	/**
	 * Upgrade path: repair missing defaults every request, and run versioned
	 * upgrades for schema changes or legacy access migrations.
	 *
	 * Called on plugins_loaded to catch FTP/git updates that skip activation.
	 */
	public static function maybe_upgrade(): void {
		self::bootstrap();
		self::apply_role_defaults();

		$stored = (int) get_option( self::CAPS_VERSION_OPTION, 0 );
		if ( $stored < self::CAPS_VERSION ) {
			self::register();
		}

		self::maybe_migrate_existing_users();
	}

	/**
	 * One-time migration for existing sites updating from the pre-capability model.
	 *
	 * Migrates both legacy role grants and explicit user-level legacy caps.
	 * User migration runs in batches so large sites do not have to process every
	 * account in a single request.
	 */
	public static function migrate_existing_users(): void {
		if ( self::get_migration_version() >= self::MIGRATION_VERSION ) {
			return;
		}

		self::migrate_legacy_roles();
		self::migrate_legacy_user_caps_batch();
	}

	/**
	 * Continue a batched legacy user-cap migration via WP-Cron.
	 */
	public static function continue_user_migration(): void {
		if ( self::get_migration_version() >= self::MIGRATION_VERSION ) {
			wp_clear_scheduled_hook( self::USER_MIGRATION_HOOK );
			return;
		}

		self::migrate_legacy_user_caps_batch();
	}

	/**
	 * Remove all PressArk capabilities from every role.
	 * Called from uninstall.php only - not on deactivation (WordPress convention).
	 */
	public static function remove_all(): void {
		$wp_roles = wp_roles();

		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		delete_option( self::CAPS_VERSION_OPTION );
		delete_option( self::MIGRATION_VERSION_OPTION );
		delete_option( self::LEGACY_MIGRATION_OPTION );
		delete_option( self::USER_MIGRATION_CURSOR_OPTION );
		wp_clear_scheduled_hook( self::USER_MIGRATION_HOOK );
	}

	/**
	 * Can the current user use PressArk (chat, REST API, Activity page)?
	 */
	public static function current_user_can_use(): bool {
		self::bootstrap();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'pressark_use' );
	}

	/**
	 * Can the current user manage PressArk settings?
	 */
	public static function current_user_can_manage_settings(): bool {
		self::bootstrap();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'pressark_manage_settings' );
	}

	/**
	 * Can the current user manage PressArk automations?
	 */
	public static function current_user_can_manage_automations(): bool {
		self::bootstrap();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'pressark_manage_automations' );
	}

	/**
	 * Map a log entry's action_type to the WordPress capability required to undo it.
	 *
	 * Returns pressark_use when no narrower WordPress capability is required.
	 *
	 * @param object $entry Log entry with action_type and target_id properties.
	 * @return string
	 */
	public static function cap_for_undo( object $entry ): string {
		return match ( $entry->action_type ) {
			'create_post',
			'elementor_create_from_template'
				=> 'delete_post',

			'edit_content',
			'find_and_replace',
			'bulk_edit',
			'update_meta',
			'update_media',
			'assign_terms',
			'elementor_edit_widget',
			'edit_product'
				=> 'edit_post',

			'update_site_settings',
			'switch_theme',
			'update_theme_setting'
				=> 'manage_options',

			default => 'pressark_use',
		};
	}

	/**
	 * Add any missing default capabilities to roles that now exist.
	 */
	private static function apply_role_defaults(): void {
		$role_defaults = apply_filters( 'pressark_default_caps', self::ROLE_DEFAULTS );

		foreach ( $role_defaults as $role_slug => $caps ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Run a migration batch when an older install still needs legacy access repair.
	 */
	private static function maybe_migrate_existing_users(): void {
		if ( self::get_migration_version() < self::MIGRATION_VERSION ) {
			self::migrate_existing_users();
		}
	}

	/**
	 * Legacy role migration from the pre-capability access model.
	 */
	private static function migrate_legacy_roles(): void {
		$wp_roles = wp_roles();

		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			$has_legacy_gate = false;
			$has_admin       = false;

			foreach ( self::LEGACY_GATE_CAPS as $cap ) {
				if ( ! empty( $role_data['capabilities'][ $cap ] ) ) {
					$has_legacy_gate = true;
					if ( 'manage_options' === $cap ) {
						$has_admin = true;
					}
				}
			}

			if ( $has_legacy_gate && ! $role->has_cap( 'pressark_use' ) ) {
				$role->add_cap( 'pressark_use' );
			}

			if ( $has_admin ) {
				if ( ! $role->has_cap( 'pressark_manage_settings' ) ) {
					$role->add_cap( 'pressark_manage_settings' );
				}
				if ( ! $role->has_cap( 'pressark_manage_automations' ) ) {
					$role->add_cap( 'pressark_manage_automations' );
				}
			}
		}
	}

	/**
	 * Process one batch of users who may have legacy direct user-level caps.
	 */
	private static function migrate_legacy_user_caps_batch(): void {
		global $wpdb;

		$meta_key     = $wpdb->get_blog_prefix() . 'capabilities';
		$last_user_id = (int) get_option( self::USER_MIGRATION_CURSOR_OPTION, 0 );
		$user_ids     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id
				FROM {$wpdb->usermeta}
				WHERE meta_key = %s
					AND user_id > %d
				ORDER BY user_id ASC
				LIMIT %d",
				$meta_key,
				$last_user_id,
				self::USER_MIGRATION_BATCH_SIZE
			)
		);

		if ( empty( $user_ids ) ) {
			self::mark_migration_complete();
			return;
		}

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			self::migrate_legacy_user_caps_for_user( $user_id );
			$last_user_id = $user_id;
		}

		update_option( self::USER_MIGRATION_CURSOR_OPTION, $last_user_id, false );

		if ( count( $user_ids ) < self::USER_MIGRATION_BATCH_SIZE ) {
			self::mark_migration_complete();
			return;
		}

		self::schedule_user_migration();
	}

	/**
	 * Migrate direct user-level legacy caps for one user.
	 */
	private static function migrate_legacy_user_caps_for_user( int $user_id ): void {
		$user = new WP_User( $user_id );
		if ( ! $user->exists() ) {
			return;
		}

		$user_caps = is_array( $user->caps ) ? $user->caps : array();
		if ( empty( $user_caps ) ) {
			return;
		}

		$has_legacy_gate = false;
		$has_admin       = false;

		foreach ( self::LEGACY_GATE_CAPS as $cap ) {
			if ( ! empty( $user_caps[ $cap ] ) ) {
				$has_legacy_gate = true;
				if ( 'manage_options' === $cap ) {
					$has_admin = true;
				}
			}
		}

		if ( $has_legacy_gate && empty( $user_caps['pressark_use'] ) ) {
			$user->add_cap( 'pressark_use' );
		}

		if ( $has_admin ) {
			if ( empty( $user_caps['pressark_manage_settings'] ) ) {
				$user->add_cap( 'pressark_manage_settings' );
			}
			if ( empty( $user_caps['pressark_manage_automations'] ) ) {
				$user->add_cap( 'pressark_manage_automations' );
			}
		}
	}

	/**
	 * Schedule another user-migration batch if one is not already queued.
	 */
	private static function schedule_user_migration(): void {
		if ( ! wp_next_scheduled( self::USER_MIGRATION_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::USER_MIGRATION_HOOK );
		}
	}

	/**
	 * Mark the legacy access migration complete and clear transient state.
	 */
	private static function mark_migration_complete(): void {
		update_option( self::MIGRATION_VERSION_OPTION, self::MIGRATION_VERSION, false );
		update_option( self::LEGACY_MIGRATION_OPTION, 1, false );
		delete_option( self::USER_MIGRATION_CURSOR_OPTION );
		wp_clear_scheduled_hook( self::USER_MIGRATION_HOOK );
	}

	/**
	 * Return the currently stored migration version, honoring the old boolean flag.
	 */
	private static function get_migration_version(): int {
		$stored = (int) get_option( self::MIGRATION_VERSION_OPTION, 0 );

		if ( $stored < 1 && get_option( self::LEGACY_MIGRATION_OPTION ) ) {
			return 1;
		}

		return $stored;
	}

	/**
	 * Check whether the user has the stored PressArk cap via role or direct user cap.
	 */
	private static function user_has_stored_cap( WP_User $user, string $cap ): bool {
		return ! empty( $user->allcaps[ $cap ] );
	}

	/**
	 * Preserve legacy chat access for editor-like custom roles created after the
	 * original migration pass by honoring the old gateway capabilities.
	 */
	private static function user_has_legacy_use_access( WP_User $user ): bool {
		foreach ( self::LEGACY_GATE_CAPS as $legacy_cap ) {
			if ( ! empty( $user->allcaps[ $legacy_cap ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Apply the public PressArk access filter for the given capability.
	 */
	private static function apply_access_filter( string $cap, bool $base_access, int $user_id ): bool {
		$filter = self::ACCESS_FILTERS[ $cap ] ?? '';
		if ( '' === $filter ) {
			return $base_access;
		}

		return (bool) apply_filters( $filter, $base_access, $user_id );
	}
}
