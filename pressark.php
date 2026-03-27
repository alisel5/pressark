<?php
/**
 * Plugin Name: PressArk
 * Plugin URI:  https://pressark.com
 * Description: AI co-pilot for WordPress. Requires the PressArk AI service or your own supported AI provider key.
 * Version:     5.1.0
 * Author:      PressArk
 * Author URI:  https://pressark.com/docs
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pressark
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSARK_VERSION', '5.1.0' );
define( 'PRESSARK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRESSARK_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSARK_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Only auto-enable Freemius sandbox defaults on local/dev installs.
 *
 * Hosted sites must opt in explicitly from wp-config.php so sandbox-only
 * shortcuts do not affect normal Freemius account flows.
 */
function pressark_should_enable_freemius_sandbox_defaults(): bool {
	if ( defined( 'PRESSARK_FREEMIUS_SANDBOX_DEFAULTS' ) ) {
		return (bool) PRESSARK_FREEMIUS_SANDBOX_DEFAULTS;
	}

	if ( function_exists( 'wp_get_environment_type' ) ) {
		$environment = (string) wp_get_environment_type();
		if ( in_array( $environment, array( 'local', 'development' ), true ) ) {
			return true;
		}
	}

	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
		return true;
	}

	return str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' );
}

// Freemius sandbox removed for production release (v5.1.0).

if ( ! function_exists( 'pressark_fs' ) ) {
	function pressark_fs() {
		global $pressark_fs;

		if ( isset( $pressark_fs ) ) {
			return $pressark_fs;
		}

		$sdk_path = PRESSARK_PATH . 'vendor/freemius/start.php';
		if ( ! file_exists( $sdk_path ) ) {
			$pressark_fs = null;
			return $pressark_fs;
		}

		require_once $sdk_path;

		$pressark_fs = fs_dynamic_init( array(
			'id'                  => '26376',
			'slug'                => 'pressark',
			'type'                => 'plugin',
			'public_key'          => 'pk_0cdb1d8317e0409eea0ac19e586b6',
			'is_premium'          => false,
			'has_addons'          => false,
			'has_paid_plans'      => true,
			// PressArk is serviceware: bundled credits require a real Freemius
			// install, so the connection step cannot be skipped.
			'enable_anonymous'    => true,
			'is_org_compliant'    => true,
			'trial'               => array(
				'days'               => 7,
				'is_require_payment' => false,
			),
			'menu'                => array(
				'slug'    => 'pressark',
				'account' => false,
				'support' => false,
			),
		) );

		return $pressark_fs;
	}

	pressark_fs();
	do_action( 'pressark_fs_loaded' );
}

/**
 * Classmap autoloader — lazy-loads plugin classes on first use.
 *
 * @since 4.2.0
 */
spl_autoload_register( static function ( string $class ): void {
	static $classmap = array(
		'PressArk_Uninstall_Helper'      => 'includes/class-pressark-uninstall-helper.php',
		'PressArk_Action_Engine'         => 'includes/class-action-engine.php',
		'PressArk_Action_Logger'         => 'includes/class-action-logger.php',
		'PressArk_Admin'                 => 'includes/class-admin.php',
		'PressArk_Admin_Activity'        => 'includes/class-admin-activity.php',
		'PressArk_Admin_Automations'     => 'includes/class-admin-automations.php',
		'PressArk_Agent'                 => 'includes/class-pressark-agent.php',
		'PressArk_AI_Connector'          => 'includes/class-ai-connector.php',
		'PressArk_Automation_Dispatcher' => 'includes/class-pressark-automation-dispatcher.php',
		'PressArk_Automation_Policy'     => 'includes/class-pressark-automation-policy.php',
		'PressArk_Automation_Recurrence' => 'includes/class-pressark-automation-recurrence.php',
		'PressArk_Automation_Service'    => 'includes/class-pressark-automation-service.php',
		'PressArk_Automation_Store'      => 'includes/class-pressark-automation-store.php',
		'PressArk_Blocks'                => 'includes/class-pressark-blocks.php',
		'PressArk_Capabilities'          => 'includes/class-pressark-capabilities.php',
		'PressArk_Chat'                  => 'includes/class-chat.php',
		'PressArk_Chat_History'          => 'includes/class-chat-history.php',
		'PressArk_Checkpoint'            => 'includes/class-pressark-checkpoint.php',
		'PressArk_CLI'                   => 'includes/class-pressark-cli.php',
		'PressArk_Content_Index'         => 'includes/class-pressark-content-index.php',
		'PressArk_Context'               => 'includes/class-pressark-context.php',
		'PressArk_Cost_Ledger'           => 'includes/class-pressark-cost-ledger.php',
		'PressArk_Cron_Manager'          => 'includes/class-pressark-cron-manager.php',
		'PressArk_Dashboard'             => 'includes/class-dashboard.php',
		'PressArk_Diagnostics'           => 'includes/class-pressark-diagnostics.php',
		'PressArk_Elementor'             => 'includes/class-pressark-elementor.php',
		'PressArk_Entitlements'          => 'includes/class-pressark-entitlements.php',
		'PressArk_Error_Tracker'         => 'includes/class-pressark-error-tracker.php',
		'PressArk_Execution_Ledger'      => 'includes/class-pressark-execution-ledger.php',
		'PressArk_Frontend_SEO'          => 'includes/class-pressark-frontend-seo.php',
		'PressArk_Handler_Automation'    => 'includes/handlers/class-handler-automation.php',
		'PressArk_Handler_Base'          => 'includes/handlers/class-handler-base.php',
		'PressArk_Handler_Content'       => 'includes/handlers/class-handler-content.php',
		'PressArk_Handler_Diagnostics'   => 'includes/handlers/class-handler-diagnostics.php',
		'PressArk_Handler_Discovery'     => 'includes/handlers/class-handler-discovery.php',
		'PressArk_Handler_Elementor'     => 'includes/handlers/class-handler-elementor.php',
		'PressArk_Handler_Media'         => 'includes/handlers/class-handler-media.php',
		'PressArk_Handler_Registry'      => 'includes/class-pressark-handler-registry.php',
		'PressArk_Handler_SEO'           => 'includes/handlers/class-handler-seo.php',
		'PressArk_Handler_System'        => 'includes/handlers/class-handler-system.php',
		'PressArk_Handler_WooCommerce'   => 'includes/handlers/class-handler-woocommerce.php',
		'PressArk_History_Manager'       => 'includes/class-pressark-history-manager.php',
		'PressArk_Insights'              => 'includes/class-pressark-insights.php',
		'PressArk_License'               => 'includes/class-pressark-license.php',
		'PressArk_Log_Analyzer'          => 'includes/class-pressark-log-analyzer.php',
		'PressArk_Migrator'              => 'includes/class-pressark-migrator.php',
		'PressArk_Model_Policy'          => 'includes/class-pressark-model-policy.php',
		'PressArk_Notification_Manager'  => 'includes/class-pressark-notification-manager.php',
		'PressArk_Onboarding'            => 'includes/class-pressark-onboarding.php',
		'PressArk_Notification_Telegram' => 'includes/class-pressark-notification-telegram.php',
		'PressArk_Operation'             => 'includes/class-pressark-operation.php',
		'PressArk_Operation_Registry'    => 'includes/class-pressark-operation-registry.php',
		'PressArk_PAL_Parser'            => 'includes/class-pressark-pal-parser.php',
		'PressArk_Pipeline'              => 'includes/class-pressark-pipeline.php',
		'PressArk_Plugins'               => 'includes/class-pressark-plugins.php',
		'PressArk_Preview'               => 'includes/class-pressark-preview.php',
		'PressArk_Preview_Builder'       => 'includes/class-pressark-preview-builder.php',
		'PressArk_Privacy'               => 'includes/class-pressark-privacy.php',
		'PressArk_Queue_Action_Scheduler' => 'includes/class-pressark-queue-action-scheduler.php',
		'PressArk_Queue_Backend'         => 'includes/class-pressark-queue-backend.php',
		'PressArk_Queue_Cron'            => 'includes/class-pressark-queue-cron.php',
		'PressArk_Reservation'           => 'includes/class-pressark-reservation.php',
		'PressArk_Retention'             => 'includes/class-pressark-retention.php',
		'PressArk_Router'                => 'includes/class-pressark-router.php',
		'PressArk_Run_Approval_Service'  => 'includes/class-pressark-run-approval-service.php',
		'PressArk_Run_Store'             => 'includes/class-pressark-run-store.php',
		'PressArk_SSE_Emitter'           => 'includes/class-pressark-sse-emitter.php',
		'PressArk_Stream_Connector'      => 'includes/class-pressark-stream-connector.php',
		'PressArk_Security_Scanner'      => 'includes/class-security-scanner.php',
		'PressArk_SEO_Impact_Analyzer'   => 'includes/class-pressark-seo-impact-analyzer.php',
		'PressArk_SEO_Resolver'          => 'includes/class-pressark-seo-resolver.php',
		'PressArk_SEO_Scanner'           => 'includes/class-seo-scanner.php',
		'PressArk_Site_Profile'          => 'includes/class-pressark-site-profile.php',
		'PressArk_Skills'                => 'includes/class-pressark-skills.php',
		'PressArk_Task_Queue'            => 'includes/class-pressark-task-queue.php',
		'PressArk_Task_Store'            => 'includes/class-pressark-task-store.php',
		'PressArk_Themes'                => 'includes/class-pressark-themes.php',
		'PressArk_Throttle'              => 'includes/class-pressark-throttle.php',
		'PressArk_Token_Bank'            => 'includes/class-pressark-token-bank.php',
		'PressArk_Tool_Catalog'          => 'includes/class-pressark-tool-catalog.php',
		'PressArk_Tool_Loader'           => 'includes/class-pressark-tool-loader.php',
		'PressArk_Tools'                 => 'includes/class-pressark-tools.php',
		'PressArk_Usage_Tracker'         => 'includes/class-usage-tracker.php',
		'PressArk_WC_Events'             => 'includes/class-pressark-wc-events.php',
		'PressArk_Workflow_Content_Create' => 'includes/class-pressark-workflow-content-create.php',
		'PressArk_Workflow_Content_Edit' => 'includes/class-pressark-workflow-content-edit.php',
		'PressArk_Workflow_Router'       => 'includes/class-pressark-workflow-router.php',
		'PressArk_Workflow_Runner'       => 'includes/class-pressark-workflow-runner.php',
		'PressArk_Workflow_SEO_Fix'      => 'includes/class-pressark-workflow-seo-fix.php',
		'PressArk_Workflow_Woo_Ops'      => 'includes/class-pressark-workflow-woo-ops.php',
		'PressArk'                       => 'includes/class-pressark.php',
	);

	if ( isset( $classmap[ $class ] ) ) {
		require_once PRESSARK_PATH . $classmap[ $class ];
	}
} );

// Service URLs — production defaults, overridable in wp-config.php for self-hosted dev.
if ( ! defined( 'PRESSARK_TOKEN_BANK_URL' ) ) {
	define( 'PRESSARK_TOKEN_BANK_URL', get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' ) );
}
if ( ! defined( 'PRESSARK_TOOL_LOAD_FALLBACK' ) ) {
	define( 'PRESSARK_TOOL_LOAD_FALLBACK', true );
}

/**
 * Resolve the upgrade URL, falling back to the pricing screen when no
 * external checkout URL has been stored yet.
 */
function pressark_get_upgrade_url(): string {
	$fallback = admin_url( 'admin.php?page=pressark-pricing' );
	$url      = (string) get_option( 'pressark_upgrade_url', $fallback );

	return '' !== $url ? $url : $fallback;
}

/**
 * Resolve the current checkout URL for a credit pack.
 */
function pressark_credit_pack_checkout_url( string $pack_type ): string {
	$urls = defined( 'PRESSARK_CREDIT_PACK_URLS' ) && is_array( PRESSARK_CREDIT_PACK_URLS )
		? PRESSARK_CREDIT_PACK_URLS
		: array();

	$url = (string) ( $urls[ $pack_type ] ?? '' );
	$url = (string) apply_filters( 'pressark_credit_pack_checkout_url', $url, $pack_type );

	if ( '' === $url ) {
		return admin_url( 'admin.php?page=pressark#pressark-credit-store' );
	}

	return $url;
}

/**
 * Infer the purchased credit pack from a Freemius payment object.
 */
function pressark_resolve_pack_type( $payment ): string {
	$pack_types = array_keys( PressArk_Entitlements::CREDIT_PACKS );

	// 1. Match by Freemius pricing_id (most reliable).
	$pricing_id = 0;
	if ( isset( $payment->pricing_id ) ) {
		$pricing_id = (int) $payment->pricing_id;
	}
	if ( $pricing_id > 0 ) {
		foreach ( PressArk_Entitlements::CREDIT_PACKS as $pack_type => $pack ) {
			if ( (int) ( $pack['freemius_pricing_id'] ?? 0 ) === $pricing_id ) {
				return $pack_type;
			}
		}
	}

	// 2. Match by pack type name in plan_name / product_name fields.
	foreach ( array( 'plan_name', 'product_name', 'title' ) as $field ) {
		if ( empty( $payment->$field ) ) {
			continue;
		}

		$value = strtolower( (string) $payment->$field );
		foreach ( $pack_types as $pack_type ) {
			$label = strtolower( (string) PressArk_Entitlements::CREDIT_PACKS[ $pack_type ]['label'] );
			if ( str_contains( $value, strtolower( $pack_type ) ) || str_contains( $value, $label ) ) {
				return $pack_type;
			}
		}
	}

	// 3. Match by gross price.
	$gross = 0;
	if ( isset( $payment->gross ) ) {
		$gross = (int) round( (float) $payment->gross * 100 );
	} elseif ( isset( $payment->amount ) ) {
		$gross = (int) round( (float) $payment->amount * 100 );
	} elseif ( isset( $payment->total ) ) {
		$gross = (int) round( (float) $payment->total * 100 );
	}

	foreach ( PressArk_Entitlements::CREDIT_PACKS as $pack_type => $pack ) {
		if ( (int) $pack['price_cents'] === $gross ) {
			return $pack_type;
		}
	}

	// Log unmatched payments so silent credit failures are debuggable.
	if ( $gross > 0 ) {
		PressArk_Error_Tracker::warning(
			'Billing',
			'Could not resolve a credit pack from the completed payment.',
			array(
				'payment_id'  => isset( $payment->id ) ? (string) $payment->id : '',
				'pricing_id'  => $pricing_id,
				'gross_cents' => $gross,
			)
		);
	}

	return '';
}

/**
 * Sync tier with the Token Bank via Freemius-verified handshake.
 *
 * The handshake sends our Freemius install_id to the bank, which verifies it
 * against the Freemius Developer API and determines the tier server-side.
 * This prevents tier escalation — the plugin never claims its own tier.
 *
 * @since 5.0.0 Replaces direct set_tier() calls with handshake().
 */
function pressark_sync_token_bank_tier(): void {
	$bank   = new PressArk_Token_Bank();
	$result = $bank->handshake();

	if ( ! empty( $result['success'] ) && empty( $result['byok'] ) ) {
		// Handshake succeeded - tier is now set by the bank.
		PressArk_Error_Tracker::info(
			'Billing',
			'Token bank handshake succeeded.',
			array(
				'tier'     => (string) ( $result['tier'] ?? 'unknown' ),
				'verified' => ! empty( $result['verified'] ),
			)
		);
		return;
	}

	// Handshake failed - log the reason for debugging.
	$error = $result['error'] ?? 'unknown';
	PressArk_Error_Tracker::warning(
		'Billing',
		'Token bank handshake failed.',
		array(
			'error'          => (string) $error,
			'has_install_id' => (bool) ( pressark_fs() && pressark_fs()->get_site() ),
		)
	);
}

/**
 * Handle Freemius license changes (covers upgrades, downgrades, cancellations, expirations).
 *
 * The Freemius SDK fires 'after_license_change' with a $plan_change string:
 *   upgraded | downgraded | activated | extended | cancelled | expired | trial_started | trial_expired
 *
 * @param string $plan_change One of the plan-change identifiers above.
 * @param object $plan        The Freemius plan object (unused, kept for signature compat).
 */
function pressark_on_license_change( string $plan_change, $plan = null ): void {
	unset( $plan );

	if ( in_array( $plan_change, array( 'cancelled', 'expired', 'trial_expired' ), true ) ) {
		pressark_on_cancellation();
		return;
	}

	// Clear handshake + upgrade transients so re-sync isn't blocked.
	$domain_hash = md5( wp_parse_url( home_url(), PHP_URL_HOST ) );
	delete_transient( 'pressark_handshake_attempted_' . $domain_hash );
	delete_transient( 'pressark_handshake_upgrade_' . $domain_hash );

	// upgraded | downgraded | activated | extended | trial_started → sync tier.
	pressark_sync_token_bank_tier();
}

/**
 * Handle Freemius account connection.
 *
 * Clears any failed-handshake transient first so the handshake isn't
 * blocked by a prior lazy attempt that ran before opt-in completed.
 *
 * @param \FS_User|null    $user    Freemius user object (unused).
 * @param \FS_Site|null    $install Freemius install object (unused).
 */
function pressark_on_activation( $user = null, $install = null ): void {
	// Clear handshake + upgrade transients so the handshake runs immediately.
	$domain_hash = md5( wp_parse_url( home_url(), PHP_URL_HOST ) );
	delete_transient( 'pressark_handshake_attempted_' . $domain_hash );
	delete_transient( 'pressark_handshake_upgrade_' . $domain_hash );

	pressark_sync_token_bank_tier();
}

/**
 * Handle subscription cancellation / license deactivation.
 *
 * Re-runs handshake which will verify the now-cancelled state via Freemius
 * and downgrade to free tier. Also clears local caches immediately.
 *
 * @since 5.0.0 Uses handshake() for server-verified tier sync.
 */
function pressark_on_cancellation(): void {
	$user_id = get_current_user_id();

	delete_option( 'pressark_cached_tier' );

	// Re-handshake — Freemius will report the cancelled state, bank will set free tier.
	$bank   = new PressArk_Token_Bank();
	$result = $bank->handshake();

	if ( empty( $result['success'] ) && $user_id > 0 ) {
		// Handshake failed — force free tier locally as safety net.
		$bank->set_tier( $user_id, 'free' );
	}

	if ( $user_id > 0 ) {
		delete_transient( 'pressark_token_status_' . $user_id );
		delete_transient( 'pressark_license_cache_' . $user_id );
	}
}

/**
 * Handle explicit license deactivation from the Freemius UI.
 *
 * The SDK fires 'after_license_deactivation' when a user deactivates their
 * license through the account page. Treat identically to cancellation.
 *
 * @param object $license The deactivated FS_Plugin_License object.
 */
function pressark_on_license_deactivation( $license = null ): void {
	unset( $license );
	pressark_on_cancellation();
}

/**
 * Handle Freemius one-time payments that correspond to credit packs.
 */
function pressark_on_credit_purchase( $payment ): void {
	$pack_type = pressark_resolve_pack_type( $payment );
	if ( '' === $pack_type ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$tier = ( new PressArk_License() )->get_tier();
	if ( ! PressArk_Entitlements::is_paid_tier( $tier ) ) {
		return;
	}

	$payment_id = isset( $payment->id ) ? (string) $payment->id : '';
	if ( '' === $payment_id ) {
		return;
	}

	// v5.0.1: Idempotency guard — Freemius webhooks can retry on network failure.
	// Prevent double-crediting the same payment by checking a dedup option first.
	$dedup_key = 'pressark_credit_payment_' . $payment_id;
	if ( get_option( $dedup_key ) ) {
		return; // Already processed.
	}
	// Mark as processed BEFORE calling the bank to prevent race conditions.
	update_option( $dedup_key, time(), false );

	$bank = new PressArk_Token_Bank();
	$bank->purchase_credits( $user_id, $pack_type, $payment_id, $tier );
	delete_transient( 'pressark_token_status_' . $user_id );
}

/**
 * Re-sync tier after every Freemius account sync (fires after SDK refreshes local data).
 *
 * Unlike after_license_change which fires during sync, this fires AFTER the SDK
 * has fully updated local plan/license data, making it more reliable for detecting
 * the current plan state.
 *
 * @param string $plan_name Current plan name after sync.
 */
function pressark_on_plan_sync( string $plan_name = '' ): void {
	$cached_tier = (string) get_option( 'pressark_cached_tier', 'free' );
	$current_tier = ( new PressArk_License() )->get_tier();

	// Only re-handshake if tier actually changed (avoid unnecessary API calls).
	if ( $current_tier !== $cached_tier ) {
		pressark_sync_token_bank_tier();
	}
}

if ( function_exists( 'pressark_fs' ) ) {
	$pressark_freemius = pressark_fs();
	if ( $pressark_freemius ) {
		$pressark_freemius->add_action( 'after_license_change', 'pressark_on_license_change' );
		$pressark_freemius->add_action( 'after_account_connection', 'pressark_on_activation' );
		$pressark_freemius->add_action( 'after_license_deactivation', 'pressark_on_license_deactivation' );
		$pressark_freemius->add_action( 'after_account_plan_sync', 'pressark_on_plan_sync' );

		// Credit purchases: intercept Freemius checkout success via JS callback.
		$pressark_freemius->add_filter( 'checkout/purchaseCompleted', 'pressark_checkout_completed_js' );
	}
}

/**
 * Return a JavaScript function for the Freemius checkout/purchaseCompleted filter.
 *
 * When a Freemius checkout completes (plan upgrade or credit pack purchase), the
 * SDK calls this JS function with the purchase data. We fire an AJAX request to
 * apply credits if the payment matches a credit pack. The function returns a
 * Promise so Freemius waits for our request before redirecting.
 *
 * @return string JavaScript function body.
 */
function pressark_checkout_completed_js(): string {
	$nonce = wp_create_nonce( 'pressark_credit_purchase' );

	return 'function (purchaseData) {
		return new Promise(function(resolve) {
			if (!purchaseData || !purchaseData.payment) {
				resolve();
				return;
			}
			jQuery.post(window.ajaxurl || "/wp-admin/admin-ajax.php", {
				action: "pressark_confirm_credit_purchase",
				_ajax_nonce: ' . wp_json_encode( $nonce ) . ',
				payment_id: String(purchaseData.payment.id || ""),
				pricing_id: String(purchaseData.payment.pricing_id || purchaseData.pricing_id || ""),
				gross: String(purchaseData.payment.gross || 0),
				plan_name: (purchaseData.plan && purchaseData.plan.name) ? purchaseData.plan.name : "",
				product_name: (purchaseData.plan && purchaseData.plan.title) ? purchaseData.plan.title : ""
			}).always(function() { resolve(); });
		});
	}';
}

/**
 * AJAX handler for confirming credit purchases after Freemius checkout.
 *
 * Called from the Freemius checkout/purchaseCompleted JS callback. Resolves
 * the credit pack from payment amount/name and applies it to the bank.
 * Non-credit payments (plan upgrades) are silently skipped.
 */
add_action( 'wp_ajax_pressark_confirm_credit_purchase', 'pressark_ajax_confirm_credit_purchase' );

function pressark_ajax_confirm_credit_purchase(): void {
	check_ajax_referer( 'pressark_credit_purchase' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( 'Not authenticated' );
	}

	$payment_id = sanitize_text_field( wp_unslash( $_POST['payment_id'] ?? '' ) );
	if ( '' === $payment_id ) {
		wp_send_json_error( 'Missing payment_id' );
	}

	// Build a payment-like object for pressark_resolve_pack_type().
	$payment = (object) array(
		'id'           => $payment_id,
		'gross'        => floatval( wp_unslash( $_POST['gross'] ?? 0 ) ),
		'pricing_id'   => absint( $_POST['pricing_id'] ?? 0 ),
		'plan_name'    => sanitize_text_field( wp_unslash( $_POST['plan_name'] ?? '' ) ),
		'product_name' => sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) ),
	);

	$pack_type = pressark_resolve_pack_type( $payment );
	if ( '' === $pack_type ) {
		// Not a credit pack purchase — likely a plan upgrade/downgrade.
		wp_send_json_success( array( 'skipped' => true ) );
	}

	$tier = ( new PressArk_License() )->get_tier();
	if ( ! PressArk_Entitlements::is_paid_tier( $tier ) ) {
		wp_send_json_error( 'Credit packs require a paid plan' );
	}

	// v5.0.1: Idempotency guard — prevent double-crediting on JS retry.
	$dedup_key = 'pressark_credit_payment_' . $payment_id;
	if ( get_option( $dedup_key ) ) {
		wp_send_json_success( array( 'pack_type' => $pack_type, 'already_applied' => true ) );
	}
	update_option( $dedup_key, time(), false );

	$bank = new PressArk_Token_Bank();
	$bank->purchase_credits( $user_id, $pack_type, $payment_id, $tier );
	delete_transient( 'pressark_token_status_' . $user_id );

	wp_send_json_success( array( 'pack_type' => $pack_type ) );
}

// Register WP-CLI commands when running in CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'pressark', 'PressArk_CLI' );
}

/**
 * Block network-wide activation on multisite.
 *
 * @since 4.1.1
 */
function pressark_block_network_activation( bool $network_wide ): void {
	if ( PressArk_Uninstall_Helper::should_block_network_activation( $network_wide ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ), false, true );
		wp_die(
			esc_html__(
				'PressArk cannot be network-activated. Please activate it on each site individually.',
				'pressark'
			),
			esc_html__( 'Plugin Activation Error', 'pressark' ),
			array( 'back_link' => true )
		);
	}
}

/**
 * Activate plugin — run schema migrations and schedule initial tasks.
 */
function pressark_activate( bool $network_wide = false ): void {
	pressark_block_network_activation( $network_wide );

	PressArk_Capabilities::bootstrap();
	PressArk_Migrator::run_all();
	PressArk_Capabilities::register();
	PressArk_Capabilities::migrate_existing_users();
	PressArk_Uninstall_Helper::remember_activated_site();

	PressArk_Cron_Manager::activate();

	// v5.2.0: Generate a site_nonce for provisional handshake identity.
	// Uses wp_hash (deterministic HMAC) instead of random UUID so that
	// concurrent requests in separate PHP processes produce the same nonce.
	if ( ! get_option( 'pressark_site_nonce' ) ) {
		update_option( 'pressark_site_nonce', wp_hash( 'pressark_site_nonce::' . home_url() ), false );
	}
	PressArk_Token_Bank::ensure_installation_uuid();

	// v5.2.0: Attempt immediate handshake. On fresh installs without Freemius,
	// the bank issues a provisional (unverified, free-tier) token so the plugin
	// works right away. When Freemius connects later, the token gets upgraded.
	$bank   = new PressArk_Token_Bank();
	$result = $bank->handshake();
	if ( ! empty( $result['success'] ) ) {
		PressArk_Error_Tracker::info(
			'Billing',
			'Activation handshake completed.',
			array(
				'tier'     => (string) ( $result['tier'] ?? 'unknown' ),
				'verified' => ! empty( $result['verified'] ),
			)
		);
	} else {
		PressArk_Error_Tracker::warning(
			'Billing',
			'Activation handshake failed and will retry on first use.',
			array(
				'error' => (string) ( $result['error'] ?? 'unknown' ),
			)
		);
	}
}
register_activation_hook( __FILE__, 'pressark_activate' );

/**
 * Cleanup transients and cron on deactivation (keep DB tables).
 */
function pressark_deactivate( bool $network_wide = false ): void {
	if ( is_multisite() && ! $network_wide ) {
		PressArk_Uninstall_Helper::remember_activated_site();
	}

	delete_transient( 'pressark_site_context' );
	delete_transient( 'pressark_plugin_list' );

	PressArk_Cron_Manager::deactivate();
}
register_deactivation_hook( __FILE__, 'pressark_deactivate' );

/**
 * Render the recovery notice for legacy network-active installs.
 */
function pressark_render_network_activation_notice(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__(
		'PressArk detected a legacy network-wide activation from an older release. PressArk is disabled in this state. Deactivate it network-wide, then activate it individually on each site that should use PressArk.',
		'pressark'
	) . '</p></div>';
}

/**
 * Hard-disable legacy network-active installs that predate the activation guard.
 */
if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'PressArk requires the Sodium cryptography extension. Please contact your hosting provider to enable it.', 'pressark' );
		echo '</p></div>';
	} );
	return;
}

if ( PressArk_Uninstall_Helper::should_disable_runtime_for_network_active_install(
	is_multisite(),
	PressArk_Uninstall_Helper::is_network_active( __FILE__ )
) ) {
	add_action( 'network_admin_notices', 'pressark_render_network_activation_notice' );
	add_action( 'admin_notices', 'pressark_render_network_activation_notice' );
	return;
}

/**
 * Load plugin classes and initialize.
 */
function pressark_init(): void {
	PressArk_Uninstall_Helper::remember_activated_site();
	PressArk_Uninstall_Helper::maybe_seed_activated_sites( __FILE__ );

	PressArk_Capabilities::bootstrap();
	PressArk_Migrator::maybe_upgrade();
	PressArk_Capabilities::maybe_upgrade();

	// v5.0.1: Admin notice when AUTH_KEY rotation makes BYOK keys unrecoverable.
	if ( is_admin() && get_transient( 'pressark_auth_key_rotated' ) ) {
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error is-dismissible"><p><strong>PressArk:</strong> ';
			echo esc_html__( 'Your WordPress security keys have changed. Any previously saved API keys in PressArk need to be re-entered. Please update your API key in Settings > PressArk.', 'pressark' );
			echo '</p></div>';
		} );
	}

	new PressArk_Privacy();
	new PressArk_Insights();

	PressArk_Preview::register_hooks();
	PressArk_Content_Index::register_hooks();
	PressArk_Reservation::register_hooks();
	PressArk_Site_Profile::register_hooks();
	PressArk_Retention::register_hooks();
	PressArk_Task_Queue::register_hooks();
	PressArk_Automation_Dispatcher::register_hooks();
	PressArk_Cron_Manager::register_hooks();
	PressArk_Frontend_SEO::register_hooks();
	PressArk_WC_Events::register_hooks();

	// Cache invalidation hooks.
	add_action( 'after_switch_theme', static function (): void {
		delete_transient( 'pressark_customizer_schema_' . get_stylesheet() );
	} );
	add_action( 'upgrader_process_complete', static function (): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_pressark_widget_schemas_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pressark_widget_schemas_' ) . '%'
		) );
	} );

	PressArk::get_instance();
}
add_action( 'plugins_loaded', 'pressark_init' );

/**
 * Helper to get configured agent instance for async processing.
 */
function pressark_get_agent( int $user_id = 0 ): PressArk_Agent {
	$license   = new PressArk_License();
	$tier      = $license->get_tier();
	$connector = new PressArk_AI_Connector( $tier );
	$logger    = new PressArk_Action_Logger();
	$engine    = new PressArk_Action_Engine( $logger );
	return new PressArk_Agent( $connector, $engine, $tier );
}
