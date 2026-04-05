<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main PressArk plugin class. Singleton that wires up all components.
 */
class PressArk {

	private static ?PressArk $instance = null;

	private PressArk_Admin $admin;
	private PressArk_Admin_Automations $admin_automations;
	private PressArk_Admin_Activity $admin_activity;
	private PressArk_Admin_Watchdog $admin_watchdog;
	private PressArk_Chat $chat;
	private PressArk_Dashboard $dashboard;
	private PressArk_Harness_Readiness $harness_readiness;
	private PressArk_Onboarding $onboarding;
	private PressArk_Policy_Diagnostics $policy_diagnostics;

	public static function get_instance(): PressArk {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->admin              = new PressArk_Admin();
		$this->admin_automations  = new PressArk_Admin_Automations();
		$this->admin_activity     = new PressArk_Admin_Activity();
		$this->admin_watchdog     = new PressArk_Admin_Watchdog();
		$this->chat               = new PressArk_Chat();
		$this->policy_diagnostics = new PressArk_Policy_Diagnostics();

		// Watchdog onboarding nudge for WooCommerce sites.
		PressArk_Watchdog_Templates::register_nudge_hooks();
		$this->dashboard          = new PressArk_Dashboard();
		$this->harness_readiness  = new PressArk_Harness_Readiness();
		$this->onboarding         = new PressArk_Onboarding();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_chat_panel' ) );

		// Frontend: show the chat widget for logged-in users with PressArk access.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_frontend_chat_panel' ) );
	}

	/**
	 * Determine whether PressArk chat assets should load on the current screen.
	 *
	 * Excludes screens where a floating chat panel causes conflicts:
	 * - Customizer (runs in an iframe with its own JS context)
	 * - Media upload modals / async-upload
	 * - Plugin/theme file editors (CodeMirror keyboard conflicts)
	 * - Admin screens inside iframes (e.g. theme install preview)
	 */
	private function should_load_on_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( ! PressArk_Capabilities::current_user_can_use() ) {
			return false;
		}

		// Never load inside the Customizer pane.
		if ( is_customize_preview() ) {
			return false;
		}

		// Screen APIs are not available during early admin bootstrap.
		if ( ! function_exists( 'get_current_screen' ) || ! did_action( 'current_screen' ) ) {
			return false;
		}

		// get_current_screen() is null during AJAX and iframe contexts.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// Screens where the chat panel conflicts or adds no value.
		$excluded_screens = array(
			'customize',          // Customizer
			'plugin-editor',      // Plugin file editor
			'theme-editor',       // Theme file editor
			'async-upload',       // Media upload iframe
			'media-upload',       // Legacy media upload
			'plugin-install',     // Plugin install (uses iframes for preview)
			'import',             // WP importer screens
			'site-health',        // Site Health (own diagnostics UI, JS conflicts)
			'export',             // WP export screen (no chat value)
			'nav-menus',          // Menu editor (complex drag-drop UI conflicts)
			'update-core',        // Core update screen (no chat value)
		);

		if ( in_array( $screen->base, $excluded_screens, true ) ) {
			return false;
		}

		// Never load on network-admin screens (multisite).
		if ( is_multisite() && is_network_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Bust admin asset caches when local files change.
	 *
	 * Keeping the query string tied only to PRESSARK_VERSION can leave old JS
	 * running against newer PHP responses during active development.
	 */
	private function asset_version( string $relative_path ): string {
		$full_path  = PRESSARK_PATH . ltrim( $relative_path, '/\\' );
		$file_mtime = file_exists( $full_path ) ? (string) filemtime( $full_path ) : '';

		return '' !== $file_mtime
			? PRESSARK_VERSION . '.' . $file_mtime
			: PRESSARK_VERSION;
	}

	/**
	 * Resolve the chat script path.
	 *
	 * Prefers the minified bundle in normal mode, but automatically falls back
	 * to the source file when the bundle is stale. This keeps hotfixes live
	 * even if `pressark-chat.min.js` has not been rebuilt yet.
	 */
	private function resolve_chat_script_path(): string {
		$source_rel = 'assets/js/pressark-chat.js';
		$min_rel    = 'assets/js/pressark-chat.min.js';

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return $source_rel;
		}

		$source_path = PRESSARK_PATH . $source_rel;
		$min_path    = PRESSARK_PATH . $min_rel;

		if ( ! file_exists( $min_path ) ) {
			return $source_rel;
		}

		if ( file_exists( $source_path ) && filemtime( $source_path ) > filemtime( $min_path ) ) {
			return $source_rel;
		}

		return $min_rel;
	}

	/**
	 * Enqueue CSS and JS on admin pages where the chat panel is active.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_load_on_screen() ) {
			return;
		}

		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$chat_script = $this->resolve_chat_script_path();

		wp_enqueue_style(
			'pressark-panel',
			PRESSARK_URL . "assets/css/pressark-panel{$suffix}.css",
			array(),
			$this->asset_version( "assets/css/pressark-panel{$suffix}.css" )
		);

		wp_enqueue_script(
			'pressark-chat',
			PRESSARK_URL . $chat_script,
			array( 'jquery' ),
			$this->asset_version( $chat_script ),
			true
		);

		$screen  = get_current_screen();
		$post_id = 0;

		if ( $screen && 'post' === $screen->base ) {
			global $post;
			if ( $post ) {
				$post_id = $post->ID;
			}
		}

		$tracker   = new PressArk_Usage_Tracker();
		$onboarded = get_user_meta( get_current_user_id(), 'pressark_onboarded', true );
		$task_store = new PressArk_Task_Store();
		$initial_unread = $task_store->unread_count( get_current_user_id() );

		$usage = $tracker->get_usage_data();

		$credit_packs = array();
		foreach ( PressArk_Entitlements::get_credit_pack_catalog() as $pack_type => $pack ) {
			$credit_packs[] = array(
				'pack_type'           => $pack_type,
				'icus'                => (int) ( $pack['icus'] ?? 0 ),
				'price_cents'         => (int) ( $pack['price_cents'] ?? 0 ),
				'label'               => (string) ( $pack['label'] ?? '' ),
				'freemius_pricing_id' => (int) ( $pack['pricing_id'] ?? $pack['freemius_pricing_id'] ?? 0 ),
				'checkoutUrl'         => pressark_credit_pack_checkout_url( $pack_type ),
			);
		}
		$checkout_config = PressArk_Entitlements::get_credit_checkout_config();

		wp_localize_script( 'pressark-chat', 'pressarkData', array(
			'restUrl'        => esc_url_raw( rest_url( 'pressark/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'screenId'       => $screen ? $screen->id : '',
			'screenBase'     => $screen ? $screen->base : '',
			'postId'         => $post_id,
			'pageTitle'      => get_admin_page_title() ?: ( $screen ? $screen->id : '' ),
			'hasApiKey'      => PressArk_AI_Connector::is_proxy_mode() || (bool) get_option( 'pressark_byok_enabled', false ) || ! empty( get_option( 'pressark_api_key', '' ) ),
			'hasWooCommerce' => class_exists( 'WooCommerce' ),
			'isOnboarded'    => ! empty( $onboarded ),
			'usage'          => $usage,
			'upgradeUrl'     => pressark_get_upgrade_url(),
			'settingsUrl'    => admin_url( 'admin.php?page=pressark' ),
			'isPro'          => $usage['is_pro'],
			'imgUrl'         => PRESSARK_URL . 'assets/imgs/',
			'images'         => $this->get_brand_images(),
			'upgrade_url'    => pressark_get_upgrade_url(),
			'settings_url'   => admin_url( 'admin.php?page=pressark' ),
			'creditStoreUrl' => admin_url( 'admin.php?page=pressark#pressark-credit-store' ),
			'creditPacks'    => $credit_packs,
			'creditsProduct' => array(
				'product_id'    => (int) $checkout_config['product_id'],
				'plan_id'       => (int) $checkout_config['plan_id'],
				'public_key'    => (string) $checkout_config['public_key'],
				'contract_hash' => (string) ( $checkout_config['contract_hash'] ?? '' ),
			),
			'activity_url'   => PressArk_Chat::get_activity_url( $initial_unread > 0 ),
			'initial_unread_count' => $initial_unread,
			'is_byok'        => (bool) get_option( 'pressark_byok_enabled', false ),
			'plan_info'      => PressArk_Entitlements::get_plan_info( ( new PressArk_License() )->get_tier() ),
			'streamingEnabled' => (bool) get_option( 'pressark_streaming_enabled', true ),
		) );
	}

	/**
	 * Render the chat panel HTML template in admin footer.
	 * Only renders on screens where assets were enqueued.
	 */
	public function render_chat_panel(): void {
		if ( ! $this->should_load_on_screen() ) {
			return;
		}
		include PRESSARK_PATH . 'templates/chat-panel.php';
	}

	/**
	 * Whether to load PressArk on the frontend for the current user.
	 */
	private function should_load_on_frontend(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( ! PressArk_Capabilities::current_user_can_use() ) {
			return false;
		}

		if ( is_customize_preview() ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue CSS and JS on the frontend for logged-in users with access.
	 */
	public function enqueue_frontend_assets(): void {
		if ( ! $this->should_load_on_frontend() ) {
			return;
		}

		$suffix      = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$chat_script = $this->resolve_chat_script_path();

		wp_enqueue_style(
			'pressark-panel',
			PRESSARK_URL . "assets/css/pressark-panel{$suffix}.css",
			array(),
			$this->asset_version( "assets/css/pressark-panel{$suffix}.css" )
		);

		wp_enqueue_script(
			'pressark-chat',
			PRESSARK_URL . $chat_script,
			array( 'jquery' ),
			$this->asset_version( $chat_script ),
			true
		);

		$tracker   = new PressArk_Usage_Tracker();
		$onboarded = get_user_meta( get_current_user_id(), 'pressark_onboarded', true );
		$task_store = new PressArk_Task_Store();
		$initial_unread = $task_store->unread_count( get_current_user_id() );

		$usage = $tracker->get_usage_data();

		$credit_packs = array();
		foreach ( PressArk_Entitlements::get_credit_pack_catalog() as $pack_type => $pack ) {
			$credit_packs[] = array(
				'pack_type'           => $pack_type,
				'icus'                => (int) ( $pack['icus'] ?? 0 ),
				'price_cents'         => (int) ( $pack['price_cents'] ?? 0 ),
				'label'               => (string) ( $pack['label'] ?? '' ),
				'freemius_pricing_id' => (int) ( $pack['pricing_id'] ?? $pack['freemius_pricing_id'] ?? 0 ),
				'checkoutUrl'         => pressark_credit_pack_checkout_url( $pack_type ),
			);
		}
		$checkout_config = PressArk_Entitlements::get_credit_checkout_config();

		$is_byok = (bool) get_option( 'pressark_byok_enabled', false );

		wp_localize_script( 'pressark-chat', 'pressarkData', array(
			'restUrl'        => esc_url_raw( rest_url( 'pressark/v1/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'screenId'       => 'frontend',
			'screenBase'     => 'frontend',
			'postId'         => is_singular() ? get_the_ID() : 0,
			'pageTitle'      => is_singular() ? get_the_title() : wp_get_document_title(),
			'hasApiKey'      => PressArk_AI_Connector::is_proxy_mode() || $is_byok || ! empty( get_option( 'pressark_api_key', '' ) ),
			'hasWooCommerce' => class_exists( 'WooCommerce' ),
			'isOnboarded'    => ! empty( $onboarded ),
			'usage'          => $usage,
			'upgradeUrl'     => pressark_get_upgrade_url(),
			'settingsUrl'    => admin_url( 'admin.php?page=pressark' ),
			'isPro'          => $usage['is_pro'],
			'imgUrl'         => PRESSARK_URL . 'assets/imgs/',
			'images'         => $this->get_brand_images(),
			'upgrade_url'    => pressark_get_upgrade_url(),
			'settings_url'   => admin_url( 'admin.php?page=pressark' ),
			'creditStoreUrl' => admin_url( 'admin.php?page=pressark#pressark-credit-store' ),
			'creditPacks'    => $credit_packs,
			'creditsProduct' => array(
				'product_id'    => (int) $checkout_config['product_id'],
				'plan_id'       => (int) $checkout_config['plan_id'],
				'public_key'    => (string) $checkout_config['public_key'],
				'contract_hash' => (string) ( $checkout_config['contract_hash'] ?? '' ),
			),
			'activity_url'   => PressArk_Chat::get_activity_url( $initial_unread > 0 ),
			'initial_unread_count' => $initial_unread,
			'is_byok'        => $is_byok,
			'plan_info'      => PressArk_Entitlements::get_plan_info( ( new PressArk_License() )->get_tier() ),
			'streamingEnabled' => (bool) get_option( 'pressark_streaming_enabled', true ),
			'isFrontend'     => true,
		) );
	}

	/**
	 * Render the chat panel HTML template on the frontend.
	 */
	public function render_frontend_chat_panel(): void {
		if ( ! $this->should_load_on_frontend() ) {
			return;
		}
		include PRESSARK_PATH . 'templates/chat-panel.php';
	}

	/**
	 * Scan the imgs folder and return an associative array of image URLs.
	 */
	private function get_brand_images(): array {
		$imgs_dir = PRESSARK_PATH . 'assets/imgs/';
		$imgs_url = PRESSARK_URL . 'assets/imgs/';
		$images   = array();

		if ( ! is_dir( $imgs_dir ) ) {
			return $images;
		}

		$extensions = array( 'png', 'jpg', 'jpeg', 'svg', 'ico', 'webp' );
		$files      = array();

		foreach ( $extensions as $ext ) {
			$found = glob( $imgs_dir . '*.' . $ext );
			if ( $found ) {
				$files = array_merge( $files, $found );
			}
		}

		foreach ( $files as $file ) {
			$name             = pathinfo( $file, PATHINFO_FILENAME );
			$images[ $name ]  = $imgs_url . basename( $file );
		}

		return $images;
	}
}
