<?php
/**
 * Plugin Name: PressArk
 * Plugin URI:  https://pressark.com
 * Description: AI co-pilot for WordPress. Requires the PressArk AI service or your own supported AI provider key.
 * Version:     5.8.16
 * Author:      PressArk
 * Author URI:  https://pressark.com/docs
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pressark
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSARK_VERSION', '5.8.16' );
define( 'PRESSARK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRESSARK_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSARK_BASENAME', plugin_basename( __FILE__ ) );

// PATRACE: remove this helper and all pressark_trace() call sites before production release.
if ( ! function_exists( 'pressark_trace' ) ) {
	function pressark_trace( string $tag, array $data = array() ): void {
		if ( ! defined( 'PRESSARK_DEBUG_TRACE' ) || ! PRESSARK_DEBUG_TRACE ) {
			return;
		}

		$parts = array();
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$parts[] = $key . '=' . ( null === $value ? 'null' : (string) $value );
			} else {
				$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$parts[] = $key . '=' . ( is_string( $json ) ? $json : '' );
			}
		}

		$line = '[' . gmdate( 'H:i:s' ) . '] PATRACE ' . $tag;
		if ( ! empty( $parts ) ) {
			$line .= ' ' . implode( ' ', array_filter( $parts, static fn( $part ) => '' !== $part ) );
		}

		@file_put_contents( '/tmp/pressark-trace.log', $line . "\n", FILE_APPEND );
	}
}

// ---------------------------------------------------------------------------
// DEBUG CODE — HTTP request/response logger for pressark/v1/* REST routes.
// Remove this whole block (along with pressark_trace helper above) when
// investigation closes. Gated by PRESSARK_DEBUG_TRACE.
//
// Writes to /tmp/pressark-http.log. Each REST call emits two lines — HTTP_REQ
// on pre-dispatch and HTTP_RES on post-dispatch. If the request 500s from a
// PHP Fatal that bypasses the handler's try/catch, HTTP_FATAL is emitted
// from shutdown() with error_get_last() details — this is what catches the
// "Unexpected end of JSON input" truncation-class bugs.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'pressark_http_trace_write' ) ) {
	function pressark_http_trace_write( string $line ): void {
		if ( ! defined( 'PRESSARK_DEBUG_TRACE' ) || ! PRESSARK_DEBUG_TRACE ) {
			return;
		}
		@file_put_contents( '/tmp/pressark-http.log', $line . "\n", FILE_APPEND );
	}

	function pressark_http_trace_is_ours( string $route ): bool {
		return str_starts_with( ltrim( $route, '/' ), 'pressark/v1' );
	}

	if ( defined( 'PRESSARK_DEBUG_TRACE' ) && PRESSARK_DEBUG_TRACE ) {
		// Incoming request — before any PressArk handler runs.
		add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
			if ( ! ( $request instanceof WP_REST_Request ) ) {
				return $result;
			}
			$route = (string) $request->get_route();
			if ( ! pressark_http_trace_is_ours( $route ) ) {
				return $result;
			}
			$body       = (string) $request->get_body();
			$body_bytes = strlen( $body );
			$preview    = $body_bytes > 512 ? substr( $body, 0, 512 ) . '…' : $body;
			$preview    = str_replace( array( "\r", "\n" ), ' ', $preview );
			pressark_http_trace_write( sprintf(
				'[%s] PATRACE HTTP_REQ method=%s route=%s body_bytes=%d body=%s',
				gmdate( 'H:i:s' ),
				(string) $request->get_method(),
				$route,
				$body_bytes,
				$preview
			) );
			$GLOBALS['pressark_http_trace_active_route'] = $route;
			return $result;
		}, 10, 3 );

		// Outgoing response — after the handler returns, before WP serializes.
		add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
			if ( ! ( $request instanceof WP_REST_Request ) ) {
				return $response;
			}
			$route = (string) $request->get_route();
			if ( ! pressark_http_trace_is_ours( $route ) ) {
				return $response;
			}
			$status     = 0;
			$data_bytes = 0;
			$data_body  = '';
			if ( $response instanceof WP_REST_Response ) {
				$status = (int) $response->get_status();
				$data   = $response->get_data();
				$json   = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $json ) {
					$data_body  = '<JSON_ENCODE_FAILED err=' . json_last_error_msg() . '>';
					$data_bytes = -1;
				} else {
					$data_bytes = strlen( $json );
					$data_body  = $data_bytes > 1024 ? substr( $json, 0, 1024 ) . '…' : $json;
				}
			} elseif ( is_wp_error( $response ) ) {
				$err_data = $response->get_error_data();
				$status   = is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 500;
				$data_body = sprintf(
					'WP_ERROR code=%s message=%s',
					(string) $response->get_error_code(),
					(string) $response->get_error_message()
				);
				$data_bytes = strlen( $data_body );
			} else {
				$data_body  = '<unknown response type ' . gettype( $response ) . '>';
				$data_bytes = -1;
			}
			pressark_http_trace_write( sprintf(
				'[%s] PATRACE HTTP_RES route=%s status=%d body_bytes=%d body=%s',
				gmdate( 'H:i:s' ),
				$route,
				$status,
				$data_bytes,
				$data_body
			) );
			unset( $GLOBALS['pressark_http_trace_active_route'] );
			return $response;
		}, 10, 3 );

		// Fatal canary — bypasses WP's do_action('shutdown') chain entirely
		// because that chain can be short-circuited by WP's fatal-error
		// recovery mode (wp_die → die), leaving add_action('shutdown') hooks
		// at high priorities unfired. register_shutdown_function is PHP-level
		// and always runs as long as the PHP process itself doesn't segfault.
		register_shutdown_function( function () {
			// Unconditional ping — proves the shutdown function itself is running.
			// If /tmp/pressark-shutdown.ping doesn't grow on a /preview/keep crash,
			// PHP never reached shutdown (segfault, SIGKILL, or a more severe
			// termination path that skips register_shutdown_function).
			@file_put_contents(
				'/tmp/pressark-shutdown.ping',
				gmdate( 'H:i:s' ) . ' uri=' . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '?' ) ) . ' active_route=' . ( $GLOBALS['pressark_http_trace_active_route'] ?? '<unset>' ) . "\n",
				FILE_APPEND
			);

			$err = error_get_last();
			$active_route = isset( $GLOBALS['pressark_http_trace_active_route'] )
				? (string) $GLOBALS['pressark_http_trace_active_route']
				: '';
			if ( '' === $active_route ) {
				// Not a pressark route (or already fully handled) — skip.
				return;
			}
			if ( ! $err ) {
				// Request started but rest_post_dispatch never logged HTTP_RES
				// and no PHP error was captured. Most likely an exit()/die()
				// without a real error, but we still want the trace entry.
				pressark_http_trace_write( sprintf(
					'[%s] PATRACE HTTP_SHUTDOWN_NO_ERR route=%s — no error_get_last(), HTTP_RES missing (exit/die without fatal?)',
					gmdate( 'H:i:s' ),
					$active_route
				) );
				return;
			}
			$fatal_types = array(
				E_ERROR,
				E_PARSE,
				E_CORE_ERROR,
				E_COMPILE_ERROR,
				E_USER_ERROR,
				E_RECOVERABLE_ERROR,
			);
			$is_fatal = in_array( (int) $err['type'], $fatal_types, true );
			pressark_http_trace_write( sprintf(
				'[%s] PATRACE %s route=%s type=%d message=%s file=%s line=%d',
				gmdate( 'H:i:s' ),
				$is_fatal ? 'HTTP_FATAL' : 'HTTP_SHUTDOWN_NONFATAL',
				$active_route,
				(int) $err['type'],
				str_replace( array( "\r", "\n" ), ' ', (string) $err['message'] ),
				(string) $err['file'],
				(int) $err['line']
			) );
		} );
	}
}

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

// Freemius sandbox: opt-in via wp-config. Define both WP_FS__DEV_MODE=true
// and PRESSARK_FS_SECRET_KEY='sk_...' to enable Freemius hosted sandbox
// checkouts. Production builds leave both undefined and ship without a
// secret_key, keeping is_payments_sandbox() false.

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

		$pressark_fs_args = array(
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
			'menu'                => array(
				'slug'    => 'pressark',
				// Account page must remain enabled — Freemius routes the
				// post-purchase redirect through it to sync the install/
				// license/plan into the local SDK state. Disabling it
				// breaks the upgrade flow: webhooks reach the bank but
				// the plugin's fs_accounts stays empty, so is_paying_or_trial()
				// keeps returning false and the UI shows Free indefinitely.
				'account' => true,
				'support' => false,
			),
		);

		if (
			defined( 'WP_FS__DEV_MODE' ) && WP_FS__DEV_MODE
			&& defined( 'PRESSARK_FS_SECRET_KEY' ) && PRESSARK_FS_SECRET_KEY
		) {
			$pressark_fs_args['secret_key'] = PRESSARK_FS_SECRET_KEY;
		}

		$pressark_fs = fs_dynamic_init( $pressark_fs_args );

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
		'PressArk_Activity_Event_Store'  => 'includes/class-pressark-activity-event-store.php',
		'PressArk_Activity_Trace'        => 'includes/class-pressark-activity-trace.php',
		'PressArk_Agent'                 => 'includes/class-pressark-agent.php',
		'PressArk_AI_Connector'          => 'includes/class-ai-connector.php',
		'PressArk_Automation_Dispatcher' => 'includes/class-pressark-automation-dispatcher.php',
		'PressArk_Automation_Policy'     => 'includes/class-pressark-automation-policy.php',
		'PressArk_Policy_Engine'         => 'includes/class-pressark-policy-engine.php',
		'PressArk_Automation_Recurrence' => 'includes/class-pressark-automation-recurrence.php',
		'PressArk_Automation_Service'    => 'includes/class-pressark-automation-service.php',
		'PressArk_Automation_Store'      => 'includes/class-pressark-automation-store.php',
		'PressArk_Blocks'                => 'includes/class-pressark-blocks.php',
		'PressArk_Capabilities'          => 'includes/class-pressark-capabilities.php',
		'PressArk_Capability_Health'     => 'includes/class-pressark-capability-health.php',
		'PressArk_Chat'                  => 'includes/class-chat.php',
		'PressArk_Chat_Controller'       => 'includes/chat/class-chat-controller.php',
		'PressArk_Chat_History'          => 'includes/class-chat-history.php',
		'PressArk_Conversation_Checkpoint_Store' => 'includes/state/class-pressark-conversation-checkpoint-store.php',
		'PressArk_Checkpoint'            => 'includes/class-pressark-checkpoint.php',
		'PressArk_Approval_State_Store'  => 'includes/state/class-pressark-approval-state-store.php',
		'PressArk_CLI'                   => 'includes/class-pressark-cli.php',
		'PressArk_Content_Index'         => 'includes/class-pressark-content-index.php',
		'PressArk_Context'               => 'includes/class-pressark-context.php',
		'PressArk_Context_Collector'     => 'includes/class-pressark-context-collector.php',
		'PressArk_Continuation_Service'  => 'includes/class-pressark-continuation-service.php',
		'PressArk_Cost_Ledger'           => 'includes/class-pressark-cost-ledger.php',
		'PressArk_Cron_Manager'          => 'includes/class-pressark-cron-manager.php',
		'PressArk_Dashboard'             => 'includes/class-dashboard.php',
		'PressArk_Diagnostics'           => 'includes/class-pressark-diagnostics.php',
		'PressArk_Elementor'             => 'includes/class-pressark-elementor.php',
		'PressArk_Entitlements'          => 'includes/class-pressark-entitlements.php',
		'PressArk_Error_Tracker'         => 'includes/class-pressark-error-tracker.php',
		'PressArk_Extension_Manifests'   => 'includes/class-pressark-ext-manifests.php',
		'PressArk_Execution_Ledger'      => 'includes/class-pressark-execution-ledger.php',
		'PressArk_Evidence_Receipt'      => 'includes/class-pressark-evidence-receipt.php',
		'PressArk_Verification'          => 'includes/class-pressark-verification.php',
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
		'PressArk_Harness_Readiness'     => 'includes/class-pressark-harness-readiness.php',
		'PressArk_History_Manager'       => 'includes/class-pressark-history-manager.php',
		'PressArk_Insights'              => 'includes/class-pressark-insights.php',
		'PressArk_License'               => 'includes/class-pressark-license.php',
		'PressArk_Log_Analyzer'          => 'includes/class-pressark-log-analyzer.php',
		'PressArk_Migrator'              => 'includes/class-pressark-migrator.php',
		'PressArk_Model_Policy'          => 'includes/class-pressark-model-policy.php',
		'PressArk_Notification_Email'    => 'includes/class-pressark-notification-email.php',
		'PressArk_Notification_Manager'  => 'includes/class-pressark-notification-manager.php',
		'PressArk_Notification_Telegram' => 'includes/class-pressark-notification-telegram.php',
		'PressArk_Onboarding'            => 'includes/class-pressark-onboarding.php',
		'PressArk_Operation'             => 'includes/class-pressark-operation.php',
		'PressArk_Operation_Registry'    => 'includes/class-pressark-operation-registry.php',
		'PressArk_Operational_Search'    => 'includes/class-pressark-operational-search.php',
		'PressArk_Orchestration_Service' => 'includes/chat/class-pressark-orchestration-service.php',
		'PressArk_Read_Orchestrator'     => 'includes/class-pressark-read-orchestrator.php',
		'PressArk_Read_Metadata'         => 'includes/class-pressark-read-metadata.php',
		'PressArk_Resource_Registry'     => 'includes/class-pressark-resource-registry.php',
		'PressArk_Capability_Bridge'     => 'includes/class-pressark-capability-bridge.php',
		'PressArk_PAL_Parser'            => 'includes/class-pressark-pal-parser.php',
		'PressArk_Permission_Decision'   => 'includes/class-pressark-permission-decision.php',
		'PressArk_Policy_Diagnostics'    => 'includes/class-pressark-policy-diagnostics.php',
		'PressArk_Permission_Service'    => 'includes/class-pressark-permission-service.php',
		'PressArk_Pipeline'              => 'includes/class-pressark-pipeline.php',
		'PressArk_Plan_Artifact'         => 'includes/class-pressark-plan-artifact.php',
		'PressArk_Plan_State_Store'      => 'includes/state/class-pressark-plan-state-store.php',
		'PressArk_Planning_Policy'       => 'includes/class-pressark-planning-policy.php',
		'PressArk_Plugins'               => 'includes/class-pressark-plugins.php',
		'PressArk_Preflight'             => 'includes/class-pressark-preflight.php',
		'PressArk_Preview'               => 'includes/class-pressark-preview.php',
		'PressArk_Preview_Builder'       => 'includes/class-pressark-preview-builder.php',
		'PressArk_Privacy'               => 'includes/class-pressark-privacy.php',
		'PressArk_Queue_Action_Scheduler' => 'includes/class-pressark-queue-action-scheduler.php',
		'PressArk_Queue_Backend'         => 'includes/class-pressark-queue-backend.php',
		'PressArk_Queue_Cron'            => 'includes/class-pressark-queue-cron.php',
		'PressArk_Request_Compiler'      => 'includes/chat/class-pressark-request-compiler.php',
		'PressArk_Request_Context'       => 'includes/routing/class-pressark-request-context.php',
		'PressArk_Reservation'           => 'includes/class-pressark-reservation.php',
		'PressArk_Replay_Integrity'      => 'includes/class-pressark-replay-integrity.php',
		'PressArk_Retention'             => 'includes/class-pressark-retention.php',
		'PressArk_Permission_Probe'      => 'includes/routing/class-pressark-permission-probe.php',
		'PressArk_Router'                => 'includes/class-pressark-router.php',
		'PressArk_Route_Arbiter'         => 'includes/routing/class-pressark-route-arbiter.php',
		'PressArk_Route_Decision'        => 'includes/routing/class-pressark-route-decision.php',
		'PressArk_Run_Approval_Service'  => 'includes/class-pressark-run-approval-service.php',
		'PressArk_Run_Store'             => 'includes/class-pressark-run-store.php',
		'PressArk_Run_Trust_Surface'     => 'includes/class-pressark-run-trust-surface.php',
		'PressArk_SSE_Emitter'           => 'includes/class-pressark-sse-emitter.php',
		'PressArk_Stream_Connector'      => 'includes/class-pressark-stream-connector.php',
		'PressArk_Security_Scanner'      => 'includes/class-security-scanner.php',
		'PressArk_SEO_Impact_Analyzer'   => 'includes/class-pressark-seo-impact-analyzer.php',
		'PressArk_SEO_Resolver'          => 'includes/class-pressark-seo-resolver.php',
		'PressArk_SEO_Scanner'           => 'includes/class-seo-scanner.php',
		'PressArk_Site_Playbook'         => 'includes/class-pressark-site-playbook.php',
		'PressArk_Site_Profile'          => 'includes/class-pressark-site-profile.php',
		'PressArk_Skills'                => 'includes/class-pressark-skills.php',
		'PressArk_Task_Queue'            => 'includes/class-pressark-task-queue.php',
		'PressArk_Task_Store'            => 'includes/class-pressark-task-store.php',
		'PressArk_Themes'                => 'includes/class-pressark-themes.php',
		'PressArk_Throttle'              => 'includes/class-pressark-throttle.php',
		'PressArk_Token_Bank'            => 'includes/class-pressark-token-bank.php',
		'PressArk_Token_Budget_Manager'  => 'includes/class-pressark-token-budget-manager.php',
		'PressArk_Tool_Catalog'          => 'includes/class-pressark-tool-catalog.php',
		'PressArk_Tool_Session_State_Store' => 'includes/state/class-pressark-tool-session-state-store.php',
		'PressArk_Tool_Preload_Planner'  => 'includes/routing/class-pressark-tool-preload-planner.php',
		'PressArk_Tool_Loader'           => 'includes/class-pressark-tool-loader.php',
		'PressArk_Tool_Result_Artifacts' => 'includes/class-pressark-tool-result-artifacts.php',
		'PressArk_Tools'                 => 'includes/class-pressark-tools.php',
		'PressArk_Usage_Tracker'         => 'includes/class-usage-tracker.php',
		'PressArk_WC_Events'             => 'includes/class-pressark-wc-events.php',
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
 * Return an inline SVG icon wrapped in a .pw-icon span.
 *
 * All icons use stroke-based design with currentColor for consistency.
 *
 * @param string $name  Icon key (e.g. 'zap', 'shield', 'check').
 * @param int    $size  Icon pixel size (default 16).
 * @return string HTML string.
 */
function pressark_icon( string $name, int $size = 16 ): string {
	static $icons = null;
	if ( null === $icons ) {
		$s = 'xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"';
		$icons = array(
			'zap'         => '<svg %s viewBox="0 0 16 16"><path d="M8.5 1.5L3 9h4.5l-.5 5.5L13 7H8.5l.5-5.5z"/></svg>',
			'moon'        => '<svg %s viewBox="0 0 16 16"><path d="M13.5 8.5a5.5 5.5 0 1 1-6-6 4.5 4.5 0 0 0 6 6z"/></svg>',
			'pen'         => '<svg %s viewBox="0 0 16 16"><path d="M11.2 2.3a1.6 1.6 0 0 1 2.5 2l-8 8L2.5 13l.7-3.2z"/></svg>',
			'search'      => '<svg %s viewBox="0 0 16 16"><circle cx="7" cy="7" r="4.5"/><path d="m13.5 13.5-3-3"/></svg>',
			'shield'      => '<svg %s viewBox="0 0 16 16"><path d="M8 1.5L2.5 4v3.5c0 3.5 2.3 6 5.5 7 3.2-1 5.5-3.5 5.5-7V4z"/></svg>',
			'barChart'    => '<svg %s viewBox="0 0 16 16"><path d="M6 13.5V7M10 13.5V2.5M2.5 13.5v-3M13.5 13.5V5"/></svg>',
			'sparkles'    => '<svg %s viewBox="0 0 16 16"><path d="M8 1.5l1 3.5 3.5 1-3.5 1-1 3.5-1-3.5L3.5 6l3.5-1z"/><path d="M12 10l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5L10 12l1.5-.5z"/></svg>',
			'check'       => '<svg %s viewBox="0 0 16 16"><path d="M3.5 8.5l3 3 6-6"/></svg>',
			'x'           => '<svg %s viewBox="0 0 16 16"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
			'warning'     => '<svg %s viewBox="0 0 16 16"><path d="M7.13 2.5L1.5 12.5h13L8.87 2.5a1 1 0 0 0-1.74 0z"/><path d="M8 6.5v2.5"/><circle cx="8" cy="11" r=".5" fill="currentColor" stroke="none"/></svg>',
			'lock'        => '<svg %s viewBox="0 0 16 16"><rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg>',
			'mail'        => '<svg %s viewBox="0 0 16 16"><rect x="1.5" y="3.5" width="13" height="9" rx="1.5"/><path d="M1.5 5l6.5 4 6.5-4"/></svg>',
			'send'        => '<svg %s viewBox="0 0 16 16"><path d="M14.5 1.5l-6 13-2.5-5.5L1.5 6.5z"/><path d="M14.5 1.5L6 9"/></svg>',
			'house'       => '<svg %s viewBox="0 0 16 16"><path d="M2.5 6.5L8 2l5.5 4.5V13a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1z"/><path d="M6 14V9h4v5"/></svg>',
			'checkCircle' => '<svg %s viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5"/><path d="M5.5 8l2 2 3.5-3.5"/></svg>',
			'xCircle'     => '<svg %s viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5"/></svg>',
			'dollar'      => '<svg %s viewBox="0 0 16 16"><path d="M8 1.5v13"/><path d="M11 4.5H6.5a2 2 0 0 0 0 4h3a2 2 0 0 1 0 4H5"/></svg>',
			'package'     => '<svg %s viewBox="0 0 16 16"><path d="M2 4.5L8 1.5l6 3v7l-6 3-6-3z"/><path d="M2 4.5L8 8l6-3.5"/><path d="M8 8v6.5"/></svg>',
			'alertCircle' => '<svg %s viewBox="0 0 16 16"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5"/><circle cx="8" cy="11" r=".5" fill="currentColor" stroke="none"/></svg>',
			'star'        => '<svg %s viewBox="0 0 16 16"><path d="M8 1.5l1.9 4 4.4.6-3.2 3 .8 4.4L8 11.3 4.1 13.5l.8-4.4-3.2-3 4.4-.6z"/></svg>',
			'gift'        => '<svg %s viewBox="0 0 16 16"><rect x="1.5" y="6" width="13" height="3" rx="1"/><rect x="2.5" y="9" width="11" height="5" rx="1"/><path d="M8 6v8"/><path d="M8 6C6.5 6 4 4.5 4 3a2 2 0 0 1 4 0"/><path d="M8 6c1.5 0 4-1.5 4-3a2 2 0 0 0-4 0"/></svg>',
			'statusDot'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="currentColor"/></svg>',
		);
	}

	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}

	$attrs = sprintf(
		'xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"',
		$size
	);

	$svg = sprintf( $icons[ $name ], $attrs );

	return '<span class="pw-icon" style="display:inline-flex;align-items:center;vertical-align:middle;">' . $svg . '</span>';
}

/**
 * Allowed HTML for inline PressArk SVG icons.
 *
 * @return array<string, array<string, bool>>
 */
function pressark_icon_allowed_html(): array {
	return array(
		'span'   => array(
			'class' => true,
			'style' => true,
		),
		'svg'    => array(
			'xmlns'           => true,
			'viewbox'         => true,
			'viewBox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'path'   => array(
			'd'      => true,
			'fill'   => true,
			'stroke' => true,
		),
		'circle' => array(
			'cx'     => true,
			'cy'     => true,
			'r'      => true,
			'fill'   => true,
			'stroke' => true,
		),
		'rect'   => array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'fill'   => true,
			'stroke' => true,
		),
	);
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
	$pack_catalog = PressArk_Entitlements::get_credit_pack_catalog();
	$pack_types   = array_keys( $pack_catalog );

	// 1. Match by Freemius pricing_id (most reliable).
	$pricing_id = 0;
	if ( isset( $payment->pricing_id ) ) {
		$pricing_id = (int) $payment->pricing_id;
	}
	if ( $pricing_id > 0 ) {
		foreach ( $pack_catalog as $pack_type => $pack ) {
			if ( (int) ( $pack['pricing_id'] ?? $pack['freemius_pricing_id'] ?? 0 ) === $pricing_id ) {
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
			$label = strtolower( (string) ( $pack_catalog[ $pack_type ]['label'] ?? '' ) );
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

	foreach ( $pack_catalog as $pack_type => $pack ) {
		if ( (int) ( $pack['price_cents'] ?? 0 ) === $gross ) {
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
 * Local helper for plugin-side purchase dedup after bank confirmation.
 */
function pressark_credit_payment_dedup_key( string $payment_id ): string {
	return 'pressark_credit_payment_' . sanitize_key( $payment_id );
}

/**
 * Check whether the current user may manage PressArk billing/settings.
 */
function pressark_current_user_can_manage_settings(): bool {
	if (
		class_exists( 'PressArk_Capabilities' )
		&& method_exists( 'PressArk_Capabilities', 'current_user_can_manage_settings' )
	) {
		return PressArk_Capabilities::current_user_can_manage_settings();
	}

	return current_user_can( 'manage_options' );
}

/**
 * Apply a credit purchase through the bank without trusting local tier claims.
 *
 * The bank remains authoritative for subscription eligibility, payment
 * verification, pricing, and idempotency. The local dedup option is only
 * written after the bank returns success or idempotent success.
 *
 * @param int    $user_id User receiving credits.
 * @param string $pack_type Resolved pack type.
 * @param string $payment_id Freemius payment ID.
 * @param string $checkout_intent_id Optional non-secret checkout correlation ID.
 * @return array
 */
function pressark_apply_credit_purchase( int $user_id, string $pack_type, string $payment_id, string $checkout_intent_id = '' ): array {
	$payment_id         = sanitize_text_field( $payment_id );
	$pack_type          = sanitize_key( $pack_type );
	$checkout_intent_id = sanitize_text_field( $checkout_intent_id );

	if ( $user_id <= 0 || '' === $pack_type || '' === $payment_id ) {
		return array(
			'success' => false,
			'error'   => 'missing_purchase_context',
		);
	}

	$dedup_key = pressark_credit_payment_dedup_key( $payment_id );
	if ( get_option( $dedup_key ) ) {
		return array(
			'success'       => true,
			'idempotent'    => true,
			'already_applied' => true,
			'local_dedup'   => true,
		);
	}

	$bank   = new PressArk_Token_Bank();
	$result = $bank->purchase_credits( $user_id, $pack_type, $payment_id, $checkout_intent_id );
	$success = ! empty( $result['success'] );
	$idempotent = ! empty( $result['idempotent'] );

	if ( ! $success && ! $idempotent ) {
		$error_message = '';
		if ( is_string( $result['error'] ?? null ) ) {
			$error_message = $result['error'];
		} elseif ( is_array( $result['error'] ?? null ) ) {
			$error_message = (string) ( $result['error']['message'] ?? $result['error']['code'] ?? '' );
		}

		return array_merge(
			array(
				'success' => false,
				'error'   => $error_message ?: 'credit_purchase_failed',
			),
			is_array( $result ) ? $result : array()
		);
	}

	update_option(
		$dedup_key,
		array(
			'applied_at' => time(),
			'pack_type'  => $pack_type,
		),
		false
	);
	delete_transient( 'pressark_token_status_' . $user_id );

	return array_merge(
		is_array( $result ) ? $result : array(),
		array(
			'success'         => true,
			'idempotent'      => $idempotent,
			'already_applied' => $idempotent || ! empty( $result['already_applied'] ),
		)
	);
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

	$payment_id = isset( $payment->id ) ? (string) $payment->id : '';
	if ( '' === $payment_id ) {
		return;
	}

	$result = pressark_apply_credit_purchase( $user_id, $pack_type, $payment_id );
	if ( empty( $result['success'] ) ) {
		PressArk_Error_Tracker::warning(
			'Billing',
			'Credit purchase confirmation was rejected by the token bank.',
			array(
				'payment_id' => $payment_id,
				'pack_type'  => $pack_type,
				'error'      => (string) ( $result['error'] ?? 'unknown' ),
			)
		);
	}
}

/**
 * Re-sync tier after every Freemius account sync (fires after SDK refreshes local data).
 *
 * Unlike after_license_change which fires during sync, this fires AFTER the SDK
 * has fully updated local plan/license data, making it more reliable for detecting
 * the current billing state.
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
	$credit_nonce  = wp_create_nonce( 'pressark_credit_purchase' );
	$billing_nonce = wp_create_nonce( 'pressark_billing_refresh' );

	return 'function (purchaseData) {
		return new Promise(function(resolve) {
			if (!purchaseData || !purchaseData.payment) {
				resolve();
				return;
			}
			var ajaxUrl = window.ajaxurl || "/wp-admin/admin-ajax.php";
			var confirmCredits = jQuery.post(ajaxUrl, {
				action: "pressark_confirm_credit_purchase",
				_ajax_nonce: ' . wp_json_encode( $credit_nonce ) . ',
				payment_id: String(purchaseData.payment.id || ""),
				pricing_id: String(purchaseData.payment.pricing_id || purchaseData.pricing_id || ""),
				gross: String(purchaseData.payment.gross || 0),
				plan_name: (purchaseData.plan && purchaseData.plan.name) ? purchaseData.plan.name : "",
				product_name: (purchaseData.plan && purchaseData.plan.title) ? purchaseData.plan.title : ""
			});
			confirmCredits.always(function() {
				jQuery.post(ajaxUrl, {
					action: "pressark_refresh_billing_from_bank",
					_ajax_nonce: ' . wp_json_encode( $billing_nonce ) . ',
					trigger: "purchase_completed"
				}).always(function() { resolve(); });
			});
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
add_action( 'wp_ajax_pressark_prepare_credit_checkout', 'pressark_ajax_prepare_credit_checkout' );
add_action( 'wp_ajax_pressark_refresh_billing_from_bank', 'pressark_ajax_refresh_billing_from_bank' );

/**
 * Create a short-lived checkout intent before opening Freemius Checkout.
 */
function pressark_ajax_prepare_credit_checkout(): void {
	check_ajax_referer( 'pressark_credit_purchase' );

	if ( ! pressark_current_user_can_manage_settings() ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Not authenticated' ), 401 );
	}

	$pack_type  = sanitize_key( wp_unslash( $_POST['pack_type'] ?? '' ) );
	$pricing_id = absint( $_POST['pricing_id'] ?? 0 );
	if ( '' === $pack_type || $pricing_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Missing checkout context' ), 400 );
	}

	$pack_catalog = PressArk_Entitlements::get_credit_pack_catalog();
	$pack         = (array) ( $pack_catalog[ $pack_type ] ?? array() );
	$expected_id  = (int) ( $pack['pricing_id'] ?? $pack['freemius_pricing_id'] ?? 0 );
	if ( empty( $pack ) || ( $expected_id > 0 && $pricing_id !== $expected_id ) ) {
		wp_send_json_error( array( 'message' => 'Invalid credit pack' ), 400 );
	}

	$bank   = new PressArk_Token_Bank();
	$result = $bank->create_credit_checkout_intent( $user_id, $pack_type, $pricing_id );

	if ( empty( $result['success'] ) || empty( $result['checkout_intent'] ) ) {
		wp_send_json_error(
			array(
				'message' => (string) ( $result['error'] ?? 'Could not prepare checkout.' ),
			),
			503
		);
	}

	wp_send_json_success( array(
		'checkout_intent' => (string) $result['checkout_intent'],
		'expires_at'      => (string) ( $result['expires_at'] ?? '' ),
	) );
}

function pressark_ajax_confirm_credit_purchase(): void {
	check_ajax_referer( 'pressark_credit_purchase' );

	if ( ! pressark_current_user_can_manage_settings() ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( 'Not authenticated' );
	}

	$payment_id = sanitize_text_field( wp_unslash( $_POST['payment_id'] ?? '' ) );
	$checkout_intent_id = sanitize_text_field(
		wp_unslash( $_POST['checkout_intent'] ?? $_POST['checkout_intent_id'] ?? '' )
	);
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

	$result = pressark_apply_credit_purchase( $user_id, $pack_type, $payment_id, $checkout_intent_id );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			array(
				'pack_type' => $pack_type,
				'message'   => (string) ( $result['error'] ?? 'Credit purchase could not be confirmed.' ),
			),
			400
		);
	}

	wp_send_json_success( array(
		'pack_type'       => $pack_type,
		'already_applied' => ! empty( $result['already_applied'] ),
		'idempotent'      => ! empty( $result['idempotent'] ),
	) );
}

/**
 * Explicitly refresh billing/Freemius state from the token bank.
 *
 * This is used after Freemius checkout returns to wp-admin. Freemius webhooks
 * can arrive after the page is rendered, so the browser polls this endpoint
 * briefly until the bank has the verified install snapshot. The endpoint does
 * not grant entitlements directly; it runs the same bank-verified handshake
 * path that hydrates the local Freemius SDK state.
 */
function pressark_ajax_refresh_billing_from_bank(): void {
	check_ajax_referer( 'pressark_billing_refresh' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}

	$attempt = max( 1, absint( $_POST['attempt'] ?? 1 ) );
	if ( 1 === $attempt ) {
		pressark_clear_billing_refresh_transients();
	}

	$result = array();
	if ( class_exists( 'PressArk_Token_Bank' ) ) {
		$bank   = new PressArk_Token_Bank();
		$result = $bank->handshake();
	}
	set_transient( 'pressark_pending_activation_bank_refresh_' . md5( (string) home_url() ), 1, MINUTE_IN_SECONDS );

	$license = class_exists( 'PressArk_License' ) ? new PressArk_License() : null;
	$tier    = $license ? $license->get_tier() : PressArk_Entitlements::normalize_tier( (string) get_option( 'pressark_cached_tier', 'free' ) );
	$fs      = function_exists( 'pressark_fs' ) ? pressark_fs() : null;
	$site    = $fs && method_exists( $fs, 'get_site' ) ? $fs->get_site() : null;
	$plan    = $fs && method_exists( $fs, 'get_plan' ) ? $fs->get_plan() : null;

	$is_paying = $fs && method_exists( $fs, 'is_paying_or_trial' ) ? (bool) $fs->is_paying_or_trial() : false;
	$is_pending = $fs && method_exists( $fs, 'is_pending_activation' ) ? (bool) $fs->is_pending_activation() : false;
	$verified = ! empty( $result['verified'] ) || (bool) get_option( 'pressark_handshake_verified', false );

	wp_send_json_success(
		array(
			'handshake_success' => ! empty( $result['success'] ),
			'handshake_error'   => (string) ( $result['error'] ?? '' ),
			'bank_tier'         => (string) ( $result['tier'] ?? '' ),
			'tier'              => $tier,
			'verified'          => $verified,
			'paying'            => $is_paying,
			'pending'           => $is_pending,
			'site_id'           => $site && ! empty( $site->id ) ? (string) $site->id : '',
			'plan_name'         => $plan && ! empty( $plan->name ) ? (string) $plan->name : '',
			'should_reload'     => $verified && $is_paying && PressArk_Entitlements::is_paid_tier( $tier ),
		)
	);
}

function pressark_clear_billing_refresh_transients(): void {
	delete_transient( 'pressark_pending_activation_bank_refresh_' . md5( (string) home_url() ) );

	if ( class_exists( 'PressArk_Token_Bank' ) ) {
		$identity = PressArk_Token_Bank::ensure_installation_uuid();
		if ( '' === $identity ) {
			$identity = PressArk_Token_Bank::current_site_identity();
		}

		if ( '' !== $identity ) {
			delete_transient( 'pressark_handshake_attempted_' . md5( $identity ) );
			delete_transient( 'pressark_handshake_upgrade_' . md5( $identity ) );
		}
	}

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( '' !== $host ) {
		delete_transient( 'pressark_handshake_attempted_' . md5( $host ) );
		delete_transient( 'pressark_handshake_upgrade_' . md5( $host ) );
	}
}

add_action( 'admin_footer', 'pressark_print_billing_refresh_poller' );

function pressark_print_billing_refresh_poller(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$checkout_keys = array(
		'_fs_checkout_action',
		'_fs_checkout_data',
		'process_redirect',
		'pending_activation',
		'purchase_completed',
	);

	$has_checkout_signal = false;
	foreach ( $checkout_keys as $key ) {
		if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$has_checkout_signal = true;
			break;
		}
	}

	if ( ! $has_checkout_signal ) {
		return;
	}

	$config = array(
		'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
		'nonce'             => wp_create_nonce( 'pressark_billing_refresh' ),
		'hasCheckoutSignal' => $has_checkout_signal,
		'maxAttempts'       => 6,
		'intervalMs'        => 5000,
	);
	?>
	<script>
	(function(config) {
		if (!config || !config.ajaxUrl || !config.nonce) {
			return;
		}

		var storageKey = 'pressarkBillingRefreshDone:' + window.location.pathname + window.location.search;
		try {
			if (config.hasCheckoutSignal && window.sessionStorage && sessionStorage.getItem(storageKey) === '1') {
				return;
			}
		} catch (e) {}

		var attempt = 0;
		var poll = function() {
			attempt += 1;
			var body = new window.URLSearchParams();
			body.set('action', 'pressark_refresh_billing_from_bank');
			body.set('_ajax_nonce', config.nonce);
			body.set('trigger', config.hasCheckoutSignal ? 'checkout_return' : 'pending_activation');
			body.set('attempt', String(attempt));

			window.fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
				.then(function(response) { return response.json(); })
				.then(function(payload) {
					var data = payload && payload.success ? payload.data : null;
					if (data && data.should_reload) {
						try {
							if (window.sessionStorage) {
								sessionStorage.setItem(storageKey, '1');
							}
						} catch (e) {}
						window.setTimeout(function() { window.location.reload(); }, 300);
						return;
					}

					if (attempt < config.maxAttempts) {
						window.setTimeout(poll, config.intervalMs);
					}
				})
				.catch(function() {
					if (attempt < config.maxAttempts) {
						window.setTimeout(poll, config.intervalMs);
					}
				});
		};

		window.setTimeout(poll, 600);
	})(<?php echo wp_json_encode( $config ); ?>);
	</script>
	<?php
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
	PressArk_Handler_Content::cleanup_legacy_public_report_exports();

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

	add_action( 'wp_ajax_pressark_download_report', array( 'PressArk_Handler_Content', 'ajax_download_report' ) );

	if ( ! get_option( 'pressark_legacy_report_exports_cleaned_v1', false ) ) {
		PressArk_Handler_Content::cleanup_legacy_public_report_exports();
		update_option( 'pressark_legacy_report_exports_cleaned_v1', true, false );
	}

	// Watchdog feature removed — clear any orphan flush schedule from prior versions.
	if ( ! get_option( 'pressark_watchdog_flush_cleared', false ) ) {
		wp_clear_scheduled_hook( 'pressark_flush_alert_batch' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'pressark_flush_alert_batch' );
		}
		update_option( 'pressark_watchdog_flush_cleared', true, false );
	}

	// Cache invalidation hooks.
	add_action( 'after_switch_theme', static function (): void {
		delete_transient( 'pressark_customizer_schema_' . get_stylesheet() );
	} );
	add_action( 'upgrader_process_complete', static function (): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Upgrade-time transient invalidation removes plugin-owned schema cache rows by prefix; caching is not relevant.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_pressark_widget_schemas_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pressark_widget_schemas_' ) . '%'
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
