<?php
/**
 * PressArk Automations Admin Page — Full CRUD management UI.
 *
 * Registered as a submenu under the PressArk top-level menu.
 * Provides: automation list, create form, edit form, toggle, delete,
 * Telegram notification settings, and run-now trigger.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Admin_Automations {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Register the Scheduled Prompts submenu page.
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'pressark',
			__( 'Scheduled Prompts', 'pressark' ),
			__( 'Scheduled Prompts', 'pressark' ),
			'pressark_manage_automations',
			'pressark-automations',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle form submissions (create, edit, delete, toggle, save settings).
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['pressark_automation_action'] ) ) {
			return;
		}

		if ( ! PressArk_Capabilities::current_user_can_manage_automations() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['pressark_automation_action'] ) );

		// Verify nonce for all actions.
		$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'pressark_automation_' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'pressark' ) );
		}

		$store   = new PressArk_Automation_Store();
		$user_id = get_current_user_id();

		switch ( $action ) {
			case 'create':
				$this->handle_create( $store, $user_id );
				break;

			case 'edit':
				$this->handle_edit( $store, $user_id );
				break;

			case 'delete':
				$this->handle_delete( $store, $user_id );
				break;

			case 'toggle':
				$this->handle_toggle( $store, $user_id );
				break;

			case 'run_now':
				$this->handle_run_now( $user_id );
				break;

			case 'save_settings':
				$this->handle_save_settings( $user_id );
				break;
		}

		// Redirect back to prevent re-submission.
		$redirect = admin_url( 'admin.php?page=pressark-automations' );
		if ( ! empty( $_POST['_redirect_tab'] ) ) {
			$redirect = add_query_arg( 'tab', sanitize_key( wp_unslash( $_POST['_redirect_tab'] ) ), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Action Handlers ──────────────────────────────────────────────────

	private function handle_create( PressArk_Automation_Store $store, int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		$tier = ( new PressArk_License() )->get_tier();

		$max     = PressArk_Entitlements::tier_value( $tier, 'max_automations' ) ?? 3;
		$current = $store->count_active( $user_id );
		if ( $max >= 0 && $current >= $max ) {
			set_transient( 'pressark_auto_notice', array( 'error', sprintf(
				/* translators: 1: current active automation count, 2: local limit. */
				__( 'You have %1$d active automations (limit: %2$d). Pause or delete one first.', 'pressark' ),
				$current,
				$max
			) ), 30 );
			return;
		}

		$prompt = wp_kses_post( wp_unslash( $_POST['prompt'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $prompt ) ) ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Prompt is required.', 'pressark' ) ), 30 );
			return;
		}

		$site_timezone = wp_timezone_string();
		$timezone      = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? $site_timezone ) );
		$timezone      = '' !== $timezone ? $timezone : 'UTC';
		$trigger_mode  = sanitize_key( wp_unslash( $_POST['trigger_mode'] ?? 'time' ) );

		if ( ! in_array( $trigger_mode, array( 'time', 'action' ), true ) ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Choose either a time schedule or an action trigger.', 'pressark' ) ), 30 );
			return;
		}

		$cadence_type           = 'once';
		$cadence_value          = 0;
		$first_run_at           = current_time( 'mysql', true );
		$next_run_at            = null;
		$event_trigger          = null;
		$event_trigger_cooldown = max( 300, absint( wp_unslash( $_POST['event_trigger_cooldown'] ?? 60 ) ) * 60 );

		if ( 'action' === $trigger_mode ) {
			// Action-triggered automations are temporarily unavailable (Watchdog feature removed).
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Action-triggered automations are temporarily unavailable. Choose a time schedule.', 'pressark' ) ), 30 );
			return;
		} else {
			$cadence_type  = sanitize_key( wp_unslash( $_POST['cadence_type'] ?? 'daily' ) );
			$cadence_value = absint( wp_unslash( $_POST['cadence_value'] ?? 0 ) );

			if ( ! PressArk_Automation_Recurrence::is_valid_cadence( $cadence_type ) ) {
				set_transient( 'pressark_auto_notice', array( 'error', __( 'Choose a valid time schedule.', 'pressark' ) ), 30 );
				return;
			}

			$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
			$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $cadence_type, $cadence_value );
			if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
				set_transient( 'pressark_auto_notice', array( 'error', sprintf(
					/* translators: %s: minimum allowed time between automation runs. */
					__( 'This site requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
					human_time_diff( 0, $min_interval )
				) ), 30 );
				return;
			}

			$first_run_input = sanitize_text_field( wp_unslash( $_POST['first_run_at'] ?? '' ) );
			$first_run_at    = $this->local_datetime_to_utc( $first_run_input, $timezone, current_time( 'mysql', true ) );
			$next_run_at     = $first_run_at;
		}

		$automation_id = $store->create( array(
			'user_id'                => $user_id,
			'name'                   => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ?: mb_substr( wp_strip_all_tags( $prompt ), 0, 80 ),
			'prompt'                 => $prompt,
			'timezone'               => $timezone,
			'cadence_type'           => $cadence_type,
			'cadence_value'          => $cadence_value,
			'event_trigger'          => $event_trigger,
			'event_trigger_cooldown' => $event_trigger_cooldown,
			'first_run_at'           => $first_run_at,
			'next_run_at'            => $next_run_at,
			'approval_policy'        => sanitize_key( wp_unslash( $_POST['approval_policy'] ?? PressArk_Automation_Policy::default_for_tier( $tier ) ) ),
			'notification_channel'   => 'telegram',
			'notification_target'    => PressArk_Notification_Manager::get_user_telegram_id( $user_id ),
		) );

		if ( 'time' === $trigger_mode && $next_run_at ) {
			PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $next_run_at );

			// Dispatch immediately if the first run is now or already past.
			// This bypasses the cron/scheduler path entirely for the initial
			// run — critical in Docker where WP-Cron's loopback fails and
			// the cron lock can block the sidecar container.
			PressArk_Automation_Dispatcher::dispatch_if_due( $automation_id );
		}

		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation created successfully.', 'pressark' ) ), 30 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handle_edit( PressArk_Automation_Store $store, int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== $user_id ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Automation not found.', 'pressark' ) ), 30 );
			return;
		}

		$update = array();
		if ( isset( $_POST['name'] ) ) {
			$update['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['prompt'] ) && '' !== trim( wp_strip_all_tags( wp_unslash( $_POST['prompt'] ) ) ) ) {
			$update['prompt'] = wp_kses_post( wp_unslash( $_POST['prompt'] ) );
		}
		if ( isset( $_POST['approval_policy'] ) ) {
			$update['approval_policy'] = sanitize_key( wp_unslash( $_POST['approval_policy'] ) );
		}

		$trigger_mode = sanitize_key( wp_unslash( $_POST['trigger_mode'] ?? $this->get_trigger_mode_for_automation( $automation ) ) );
		if ( ! in_array( $trigger_mode, array( 'time', 'action' ), true ) ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Choose either a time schedule or an action trigger.', 'pressark' ) ), 30 );
			return;
		}

		if ( 'action' === $trigger_mode ) {
			// Action-triggered automations are temporarily unavailable (Watchdog feature removed).
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Action-triggered automations are temporarily unavailable. Choose a time schedule.', 'pressark' ) ), 30 );
			return;
		} else {
			$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? ( $automation['timezone'] ?? wp_timezone_string() ) ) );
			$timezone = '' !== $timezone ? $timezone : 'UTC';
			$ct       = sanitize_key( wp_unslash( $_POST['cadence_type'] ?? ( $automation['cadence_type'] ?? 'daily' ) ) );
			$cv       = absint( wp_unslash( $_POST['cadence_value'] ?? ( $automation['cadence_value'] ?? 0 ) ) );

			if ( ! PressArk_Automation_Recurrence::is_valid_cadence( $ct ) ) {
				set_transient( 'pressark_auto_notice', array( 'error', __( 'Choose a valid time schedule.', 'pressark' ) ), 30 );
				return;
			}

			$tier            = ( new PressArk_License() )->get_tier();
			$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
			$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $ct, $cv );
			if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
				set_transient( 'pressark_auto_notice', array( 'error', sprintf(
					/* translators: %s: minimum allowed time between automation runs. */
					__( 'This site requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
					human_time_diff( 0, $min_interval )
				) ), 30 );
				return;
			}

			$first_run_input = sanitize_text_field( wp_unslash( $_POST['first_run_at'] ?? '' ) );
			$first_run_at    = $this->local_datetime_to_utc(
				$first_run_input,
				$timezone,
				(string) ( $automation['first_run_at'] ?? current_time( 'mysql', true ) )
			);
			$next_run_at     = $this->compute_upcoming_time_trigger( $ct, $cv, $timezone, $first_run_at );

			$update['cadence_type']  = $ct;
			$update['cadence_value'] = $cv;
			$update['timezone']      = $timezone;
			$update['first_run_at']  = $first_run_at;
			$update['event_trigger'] = null;
			$update['next_run_at']   = $next_run_at;

			if ( $next_run_at ) {
				PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $next_run_at );
			} else {
				PressArk_Automation_Dispatcher::cancel_wake( $automation_id );
			}
		}

		if ( ! empty( $update ) ) {
			$store->update( $automation_id, $update );
		}

		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation updated.', 'pressark' ) ), 30 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handle_delete( PressArk_Automation_Store $store, int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== $user_id ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Automation not found.', 'pressark' ) ), 30 );
			return;
		}

		$store->delete( $automation_id );
		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation deleted.', 'pressark' ) ), 30 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handle_toggle( PressArk_Automation_Store $store, int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== $user_id ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Automation not found.', 'pressark' ) ), 30 );
			return;
		}

		$new_status = ( 'active' === $automation['status'] ) ? 'paused' : 'active';
		$update     = array( 'status' => $new_status );

		if ( 'active' === $new_status ) {
			$next = PressArk_Automation_Dispatcher::next_run_for_resume( $automation, current_time( 'mysql', true ) );
			$update['next_run_at']    = $next;
			$update['failure_streak'] = 0;
			$update['last_error']     = null;

			if ( $next ) {
				PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $next );
			}
		}

		$store->update( $automation_id, $update );

		// If resuming and the next run is due, dispatch immediately.
		if ( 'active' === $new_status ) {
			PressArk_Automation_Dispatcher::dispatch_if_due( $automation_id );
		}

		$msg = 'active' === $new_status
			? __( 'Automation resumed.', 'pressark' )
			: __( 'Automation paused.', 'pressark' );
		set_transient( 'pressark_auto_notice', array( 'success', $msg ), 30 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handle_run_now( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$result = PressArk_Automation_Dispatcher::run_now( $automation_id, $user_id );

		if ( ! empty( $result['success'] ) ) {
			set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation dispatched. Results will arrive shortly.', 'pressark' ) ), 30 );
		} else {
			set_transient( 'pressark_auto_notice', array( 'error', $result['error'] ?? __( 'Failed to dispatch automation.', 'pressark' ) ), 30 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function handle_save_settings( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- $_POST nonce verified upstream in self::handle_actions() before this private handler is dispatched.
		// Telegram bot token (site-wide, encrypted).
		$token = sanitize_text_field( wp_unslash( $_POST['telegram_bot_token'] ?? '' ) );
		if ( ! empty( $token ) ) {
			$encrypted = PressArk_Usage_Tracker::encrypt_value( $token );
			update_option( 'pressark_telegram_bot_token', $encrypted, false );
		}

		// Telegram chat ID (per-user meta).
		$chat_id = sanitize_text_field( wp_unslash( $_POST['telegram_chat_id'] ?? '' ) );
		if ( $chat_id ) {
			update_user_meta( $user_id, 'pressark_telegram_chat_id', $chat_id );
		} else {
			delete_user_meta( $user_id, 'pressark_telegram_chat_id' );
		}

		set_transient( 'pressark_auto_notice', array( 'success', __( 'Notification settings saved.', 'pressark' ) ), 30 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private function get_trigger_mode_for_automation( array $automation ): string {
		$has_event    = ! empty( $automation['event_trigger'] );
		$has_schedule = ! $has_event
			|| 'once' !== (string) ( $automation['cadence_type'] ?? '' )
			|| ! empty( $automation['next_run_at'] );

		if ( $has_event && $has_schedule ) {
			return 'hybrid';
		}

		return $has_event ? 'action' : 'time';
	}

	private function get_event_trigger_options(): array {
		return array(
			'order_failed'     => __( 'Order Failed', 'pressark' ),
			'order_cancelled'  => __( 'Order Cancelled', 'pressark' ),
			'refund_issued'    => __( 'Refund Issued', 'pressark' ),
			'low_stock'        => __( 'Low Stock', 'pressark' ),
			'out_of_stock'     => __( 'Out of Stock', 'pressark' ),
			'negative_review'  => __( 'Negative Review', 'pressark' ),
			'high_value_order' => __( 'High-Value Order', 'pressark' ),
		);
	}

	private function get_event_trigger_label( string $trigger ): string {
		$options = $this->get_event_trigger_options();
		return $options[ $trigger ] ?? ucwords( str_replace( '_', ' ', $trigger ) );
	}

	private function format_local_datetime_input( ?string $utc_datetime, string $timezone ): string {
		if ( empty( $utc_datetime ) ) {
			return '';
		}

		try {
			$utc = new \DateTimeZone( 'UTC' );
			$tz  = new \DateTimeZone( $timezone ?: 'UTC' );
			$dt  = new \DateTime( $utc_datetime, $utc );
			$dt->setTimezone( $tz );
			return $dt->format( 'Y-m-d\TH:i' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	private function local_datetime_to_utc( string $local_value, string $timezone, string $fallback ): string {
		if ( '' === trim( $local_value ) ) {
			return $fallback;
		}

		try {
			$tz  = new \DateTimeZone( $timezone ?: 'UTC' );
			$utc = new \DateTimeZone( 'UTC' );
			$dt  = new \DateTime( $local_value, $tz );
			$dt->setTimezone( $utc );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $fallback;
		}
	}

	private function compute_upcoming_time_trigger( string $cadence_type, int $cadence_value, string $timezone, string $first_run_at ): ?string {
		$first_timestamp = strtotime( $first_run_at );
		if ( ! $first_timestamp ) {
			return null;
		}

		$now = current_time( 'timestamp', true );
		if ( $first_timestamp > $now ) {
			return $first_run_at;
		}

		if ( 'once' === $cadence_type ) {
			return current_time( 'mysql', true );
		}

		return PressArk_Automation_Recurrence::compute_next(
			$cadence_type,
			$cadence_value,
			$timezone,
			$first_run_at,
			$first_run_at
		);
	}

	private function render_automation_form_assets(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.pressark-trigger-wizard {
				margin-top: 24px;
				border: 1px solid #d0d7de;
				border-radius: 16px;
				background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
				overflow: hidden;
			}
			.pressark-trigger-steps {
				display: flex;
				gap: 10px;
				padding: 18px 20px 0;
			}
			.pressark-trigger-step {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 8px 12px;
				border-radius: 999px;
				background: #e2e8f0;
				color: #475569;
				font-size: 12px;
				font-weight: 600;
			}
			.pressark-trigger-step.is-active {
				background: #dbeafe;
				color: #1d4ed8;
			}
			.pressark-trigger-step.is-done {
				background: #dcfce7;
				color: #15803d;
			}
			.pressark-trigger-step__number {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 20px;
				height: 20px;
				border-radius: 999px;
				background: rgba(255, 255, 255, 0.75);
				font-size: 11px;
			}
			.pressark-trigger-slide {
				padding: 20px;
			}
			.pressark-trigger-slide[hidden] {
				display: none !important;
			}
			.pressark-trigger-slide-header {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 16px;
				margin-bottom: 16px;
			}
			.pressark-trigger-slide-title {
				margin: 0 0 4px;
				font-size: 18px;
				line-height: 1.3;
			}
			.pressark-trigger-slide-copy {
				margin: 0;
				color: #64748b;
				max-width: 560px;
			}
			.pressark-trigger-back {
				border: none;
				background: transparent;
				color: #2563eb;
				cursor: pointer;
				font-weight: 600;
				padding: 0;
			}
			.pressark-trigger-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 14px;
			}
			.pressark-trigger-card {
				position: relative;
				display: flex;
				flex-direction: column;
				gap: 10px;
				padding: 18px;
				border: 1px solid #cbd5e1;
				border-radius: 16px;
				background: #ffffff;
				cursor: pointer;
				transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
			}
			.pressark-trigger-card:hover {
				border-color: #60a5fa;
				box-shadow: 0 10px 28px rgba(37, 99, 235, 0.08);
				transform: translateY(-1px);
			}
			.pressark-trigger-card.is-active {
				border-color: #2563eb;
				box-shadow: 0 0 0 1px #2563eb;
			}
			.pressark-trigger-card.is-disabled {
				cursor: not-allowed;
				opacity: 0.7;
				background: #f8fafc;
				box-shadow: none;
				transform: none;
			}
			.pressark-trigger-card input {
				position: absolute;
				opacity: 0;
				pointer-events: none;
			}
			.pressark-trigger-card-title {
				font-size: 16px;
				font-weight: 700;
				color: #0f172a;
			}
			.pressark-trigger-card-copy {
				color: #475569;
				line-height: 1.5;
			}
			.pressark-trigger-card-cta {
				display: inline-flex;
				align-self: flex-start;
				padding: 6px 10px;
				border-radius: 999px;
				background: #eff6ff;
				color: #1d4ed8;
				font-size: 12px;
				font-weight: 600;
			}
			.pressark-trigger-card-lock {
				display: inline-flex;
				align-self: flex-start;
				padding: 4px 8px;
				border-radius: 999px;
				background: #fff7ed;
				color: #c2410c;
				font-size: 11px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.03em;
			}
			.pressark-trigger-panel {
				padding: 18px;
				border: 1px solid #e2e8f0;
				border-radius: 16px;
				background: #ffffff;
			}
			.pressark-trigger-badge {
				display: inline-flex;
				align-items: center;
				padding: 6px 10px;
				border-radius: 999px;
				background: #eff6ff;
				color: #1d4ed8;
				font-size: 12px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.04em;
			}
			.pressark-trigger-note {
				margin-top: 14px;
				padding: 12px 14px;
				border-radius: 12px;
				border: 1px solid #fde68a;
				background: #fffbeb;
				color: #92400e;
			}
			.pressark-trigger-submit {
				margin-top: 18px;
			}
			.pressark-trigger-cadence-row {
				display: flex;
				align-items: center;
				gap: 8px;
				flex-wrap: wrap;
			}
			.pressark-trigger-cadence-row .small-text {
				min-width: 72px;
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var wizards = document.querySelectorAll('[data-pressark-trigger-wizard]');
				for (var i = 0; i < wizards.length; i++) {
					initWizard(wizards[i]);
				}

				function initWizard(root) {
					var modeInput = root.querySelector('[data-pressark-trigger-mode-input]');
					if (!modeInput) {
						return;
					}

					var chooseSlide = root.querySelector('[data-pressark-slide="choose"]');
					var timeSlide = root.querySelector('[data-pressark-slide="time"]');
					var actionSlide = root.querySelector('[data-pressark-slide="action"]');
					var chooseStep = root.querySelector('[data-pressark-step="choose"]');
					var detailsStep = root.querySelector('[data-pressark-step="details"]');
					var cards = root.querySelectorAll('[data-pressark-trigger-card]');
					var backButtons = root.querySelectorAll('[data-pressark-trigger-back]');

					function normalizeMode(value) {
						return value === 'action' ? 'action' : 'time';
					}

					function updateStepState(activeSlide) {
						if (!chooseStep || !detailsStep) {
							return;
						}

						chooseStep.classList.remove('is-active', 'is-done');
						detailsStep.classList.remove('is-active', 'is-done');

						if (activeSlide === 'choose') {
							chooseStep.classList.add('is-active');
						} else {
							chooseStep.classList.add('is-done');
							detailsStep.classList.add('is-active');
						}
					}

					function updateCards() {
						for (var c = 0; c < cards.length; c++) {
							var card = cards[c];
							card.classList.toggle('is-active', card.getAttribute('data-trigger-mode') === modeInput.value);
							var radio = card.querySelector('input[type="radio"]');
							if (radio) {
								radio.checked = card.classList.contains('is-active');
							}
						}
					}

					function updateCadenceFields() {
						var rows = root.querySelectorAll('.pressark-trigger-cadence-row');
						for (var r = 0; r < rows.length; r++) {
							var row = rows[r];
							var select = row.querySelector('[data-pressark-cadence-select]');
							var value = row.querySelector('[data-pressark-cadence-value]');
							var label = row.querySelector('[data-pressark-cadence-label]');
							if (!select || !value || !label) {
								continue;
							}
							var showHourly = select.value === 'hourly';
							value.style.display = showHourly ? 'inline-block' : 'none';
							label.style.display = showHourly ? 'inline' : 'none';
						}
					}

					function showSlide(slide) {
						var resolved = slide === 'action' ? 'action' : (slide === 'time' ? 'time' : 'choose');
						if (chooseSlide) {
							chooseSlide.hidden = resolved !== 'choose';
						}
						if (timeSlide) {
							timeSlide.hidden = resolved !== 'time';
						}
						if (actionSlide) {
							actionSlide.hidden = resolved !== 'action';
						}
						updateStepState(resolved);
						updateCards();
						updateCadenceFields();
					}

					for (var c = 0; c < cards.length; c++) {
						cards[c].addEventListener('click', function (event) {
							var card = event.currentTarget;
							if (card.classList.contains('is-disabled')) {
								event.preventDefault();
								return;
							}
							modeInput.value = normalizeMode(card.getAttribute('data-trigger-mode'));
							showSlide(modeInput.value);
						});
					}

					for (var b = 0; b < backButtons.length; b++) {
						backButtons[b].addEventListener('click', function (event) {
							event.preventDefault();
							showSlide('choose');
						});
					}

					var cadenceSelects = root.querySelectorAll('[data-pressark-cadence-select]');
					for (var s = 0; s < cadenceSelects.length; s++) {
						cadenceSelects[s].addEventListener('change', updateCadenceFields);
					}

					modeInput.value = normalizeMode(modeInput.value);
					showSlide(root.getAttribute('data-start-slide') || 'choose');
				}
			});
		</script>
		<?php
	}

	// ── Page Renderer ────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! PressArk_Capabilities::current_user_can_manage_automations() ) {
			return;
		}

		$tier    = ( new PressArk_License() )->get_tier();
		$can_use = PressArk_Entitlements::can_use_feature( $tier, 'automations' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab toggle on the automations admin page; capability gated above.
		$tab     = sanitize_key( wp_unslash( $_GET['tab'] ?? 'list' ) );

		// Show transient notices.
		$notice = get_transient( 'pressark_auto_notice' );
		if ( $notice && is_array( $notice ) ) {
			delete_transient( 'pressark_auto_notice' );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice[0] ),
				esc_html( $notice[1] )
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scheduled Prompts', 'pressark' ); ?></h1>

			<?php if ( ! $can_use ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'Scheduled automations are temporarily unavailable.', 'pressark' ); ?>
						<?php $url = pressark_get_upgrade_url(); ?>
						<a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Manage billing', 'pressark' ); ?></a>
					</p>
				</div>
			<?php else : ?>

				<nav class="nav-tab-wrapper">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pressark-automations&tab=list' ) ); ?>"
					   class="nav-tab <?php echo 'list' === $tab || 'edit' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Automations', 'pressark' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pressark-automations&tab=create' ) ); ?>"
					   class="nav-tab <?php echo 'create' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( '+ New Automation', 'pressark' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pressark-automations&tab=settings' ) ); ?>"
					   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Notifications', 'pressark' ); ?>
					</a>
				</nav>

				<div style="margin-top:16px;">
					<?php
					switch ( $tab ) {
						case 'create':
							$this->render_create_form( $tier );
							break;
						case 'edit':
							$this->render_edit_form( $tier );
							break;
						case 'settings':
							$this->render_notification_settings();
							break;
						default:
							$this->render_list();
					}
					?>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}

	// ── List Tab ─────────────────────────────────────────────────────────

	private function render_list(): void {
		$store       = new PressArk_Automation_Store();
		$user_id     = get_current_user_id();
		$automations = $store->list_for_user( $user_id );
		$tier        = ( new PressArk_License() )->get_tier();
		$max         = PressArk_Entitlements::tier_value( $tier, 'max_automations' );
		$max_label   = $max < 0 ? __( 'unlimited', 'pressark' ) : $max;

		if ( empty( $automations ) ) {
			?>
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:40px;text-align:center;margin:20px 0;">
				<h2 style="margin-bottom:8px;"><?php esc_html_e( 'No scheduled prompts yet', 'pressark' ); ?></h2>
				<p style="color:#64748b;margin-bottom:16px;"><?php esc_html_e( 'Create your first automation to run AI prompts on a schedule.', 'pressark' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pressark-automations&tab=create' ) ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Create Automation', 'pressark' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		?>
		<p class="description">
			<?php printf(
				/* translators: 1: current automation count, 2: local automation limit label, 3: credit-metering note. */
				esc_html__( '%1$d automation(s). Local limit: %2$s. %3$s', 'pressark' ),
				count( $automations ),
				esc_html( $max_label ),
				esc_html__( 'AI runs use service credits.', 'pressark' )
			); ?>
		</p>

		<table class="wp-list-table widefat striped" style="margin-top:12px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Prompt', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Schedule', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Status', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Next Run', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Last Result', 'pressark' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'pressark' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $automations as $a ) :
					$status_colors = array(
						'active'  => '#16a34a',
						'paused'  => '#f59e0b',
						'failed'  => '#dc2626',
						'archived' => '#64748b',
					);
					$sc = $status_colors[ $a['status'] ] ?? '#64748b';
				?>
				<tr>
					<td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
					<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $a['prompt'] ); ?>">
						<?php echo esc_html( mb_substr( $a['prompt'], 0, 80 ) ); ?>
					</td>
					<td>
						<?php $trigger_mode = $this->get_trigger_mode_for_automation( $a ); ?>
						<?php if ( 'action' === $trigger_mode ) : ?>
							<strong><?php esc_html_e( 'Action trigger', 'pressark' ); ?></strong>
							<br><small style="color:#6366f1;"><?php echo esc_html( $this->get_event_trigger_label( (string) $a['event_trigger'] ) ); ?></small>
						<?php elseif ( 'hybrid' === $trigger_mode ) : ?>
							<?php echo esc_html( PressArk_Automation_Recurrence::label( $a['cadence_type'], $a['cadence_value'] ) ); ?>
							<br><small style="color:#d97706;"><?php printf(
								/* translators: %s: event trigger label. */
								esc_html__( 'Legacy hybrid: %s', 'pressark' ),
								esc_html( $this->get_event_trigger_label( (string) $a['event_trigger'] ) )
							); ?></small>
						<?php else : ?>
							<?php echo esc_html( PressArk_Automation_Recurrence::label( $a['cadence_type'], $a['cadence_value'] ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:600;">
							<?php echo esc_html( ucfirst( $a['status'] ) ); ?>
						</span>
						<?php if ( $a['failure_streak'] > 0 ) : ?>
							<br><small style="color:#dc2626;"><?php printf(
								/* translators: %s: number of consecutive automation failures. */
								esc_html__( '%s failures', 'pressark' ),
								esc_html( number_format_i18n( absint( $a['failure_streak'] ) ) )
							); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $a['next_run_at'] ) : ?>
							<?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $a['next_run_at'] ) ) ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $a['last_error'] ) : ?>
							<span style="color:#dc2626;" title="<?php echo esc_attr( $a['last_error'] ); ?>">
								<?php esc_html_e( 'Failed', 'pressark' ); ?>
							</span>
						<?php elseif ( $a['last_success_at'] ) : ?>
							<span style="color:#16a34a;">
								<?php echo esc_html( wp_date( 'M j g:i A', strtotime( $a['last_success_at'] ) ) ); ?>
							</span>
						<?php else : ?>
							<span style="color:#64748b;"><?php esc_html_e( 'Never run', 'pressark' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php $this->render_row_actions( $a ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_row_actions( array $a ): void {
		$aid = esc_attr( $a['automation_id'] );
		$edit_url = admin_url( 'admin.php?page=pressark-automations&tab=edit&id=' . urlencode( $a['automation_id'] ) );
		?>
		<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'pressark' ); ?></a>

		<?php // Toggle ?>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'pressark_automation_toggle' ); ?>
			<input type="hidden" name="pressark_automation_action" value="toggle">
			<input type="hidden" name="automation_id" value="<?php echo esc_attr( $aid ); ?>">
			<button type="submit" class="button button-small">
				<?php echo 'active' === $a['status'] ? esc_html__( 'Pause', 'pressark' ) : esc_html__( 'Resume', 'pressark' ); ?>
			</button>
		</form>

		<?php // Run now ?>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'pressark_automation_run_now' ); ?>
			<input type="hidden" name="pressark_automation_action" value="run_now">
			<input type="hidden" name="automation_id" value="<?php echo esc_attr( $aid ); ?>">
			<button type="submit" class="button button-small"><?php esc_html_e( 'Run Now', 'pressark' ); ?></button>
		</form>

		<?php // Delete ?>
		<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this automation?', 'pressark' ) ); ?>');">
			<?php wp_nonce_field( 'pressark_automation_delete' ); ?>
			<input type="hidden" name="pressark_automation_action" value="delete">
			<input type="hidden" name="automation_id" value="<?php echo esc_attr( $aid ); ?>">
			<button type="submit" class="button button-small" style="color:#dc2626;"><?php esc_html_e( 'Delete', 'pressark' ); ?></button>
		</form>
		<?php
	}

	// ── Create Tab ───────────────────────────────────────────────────────

	private function render_create_form( string $tier ): void {
		$policies       = PressArk_Automation_Policy::valid_policies();
		$default_policy = PressArk_Automation_Policy::default_for_tier( $tier );
		$tz             = wp_timezone_string();
		$tz             = '' !== $tz ? $tz : 'UTC';
		$can_trigger    = false; // Action triggers disabled — Watchdog feature removed.
		$trigger_modes  = $this->get_event_trigger_options();

		$this->render_automation_form_assets();
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;max-width:780px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Create Automation', 'pressark' ); ?></h2>

			<form method="post" data-pressark-trigger-wizard data-start-slide="choose">
				<?php wp_nonce_field( 'pressark_automation_create' ); ?>
				<input type="hidden" name="pressark_automation_action" value="create">
				<input type="hidden" name="trigger_mode" value="time" data-pressark-trigger-mode-input>
				<input type="hidden" name="timezone" value="<?php echo esc_attr( $tz ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="pw-auto-name"><?php esc_html_e( 'Name', 'pressark' ); ?></label></th>
						<td>
							<input type="text" id="pw-auto-name" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Weekly SEO Audit', 'pressark' ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Defaults to the first 80 characters of the prompt.', 'pressark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="pw-auto-prompt"><?php esc_html_e( 'Prompt', 'pressark' ); ?> <span style="color:#dc2626;">*</span></label></th>
						<td>
							<textarea id="pw-auto-prompt" name="prompt" rows="5" class="large-text" required placeholder="<?php esc_attr_e( 'e.g., Audit all published posts for SEO issues and fix any missing meta descriptions.', 'pressark' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'The instruction PressArk will execute each time this automation runs.', 'pressark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="pw-auto-policy"><?php esc_html_e( 'Trust Policy', 'pressark' ); ?></label></th>
						<td>
							<select id="pw-auto-policy" name="approval_policy">
								<?php foreach ( $policies as $p ) : ?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $p, $default_policy ); ?>>
										<?php echo esc_html( ucfirst( $p ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Editorial: content/SEO only. Merchandising: + WooCommerce products. Full: all except destructive.', 'pressark' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="pressark-trigger-wizard">
					<div class="pressark-trigger-steps" aria-hidden="true">
						<span class="pressark-trigger-step" data-pressark-step="choose"><span class="pressark-trigger-step__number">1</span><?php esc_html_e( 'Choose Trigger', 'pressark' ); ?></span>
						<span class="pressark-trigger-step" data-pressark-step="details"><span class="pressark-trigger-step__number">2</span><?php esc_html_e( 'Configure Details', 'pressark' ); ?></span>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="choose">
						<div class="pressark-trigger-slide-header">
							<div>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'How should this automation start?', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'Pick exactly one trigger path. Time schedules and action triggers now live on separate slides so the setup stays focused.', 'pressark' ); ?></p>
							</div>
						</div>

						<div class="pressark-trigger-grid">
							<label class="pressark-trigger-card is-active" data-pressark-trigger-card data-trigger-mode="time">
								<input type="radio" name="_pressark_trigger_picker" value="time" checked>
								<span class="pressark-trigger-card-title"><?php esc_html_e( 'Time schedule', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-copy"><?php esc_html_e( 'Run once or on a recurring cadence like hourly, daily, or weekly.', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-cta"><?php esc_html_e( 'Configure schedule', 'pressark' ); ?></span>
							</label>

							<label class="pressark-trigger-card <?php echo $can_trigger ? '' : 'is-disabled'; ?>" data-pressark-trigger-card data-trigger-mode="action">
								<input type="radio" name="_pressark_trigger_picker" value="action" <?php disabled( ! $can_trigger ); ?>>
								<span class="pressark-trigger-card-title"><?php esc_html_e( 'Action trigger', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-copy"><?php esc_html_e( 'Run when a WooCommerce event happens, like low stock or a failed order.', 'pressark' ); ?></span>
								<?php if ( $can_trigger ) : ?>
									<span class="pressark-trigger-card-cta"><?php esc_html_e( 'Configure action trigger', 'pressark' ); ?></span>
								<?php else : ?>
									<span class="pressark-trigger-card-lock"><?php esc_html_e( 'Paused', 'pressark' ); ?></span>
								<?php endif; ?>
							</label>
						</div>

						<?php if ( ! $can_trigger ) : ?>
							<div class="pressark-trigger-note">
								<strong><?php esc_html_e( 'Action triggers are temporarily unavailable.', 'pressark' ); ?></strong>
								<div><?php esc_html_e( 'Time-based automations remain available while event-driven runs are rebuilt.', 'pressark' ); ?></div>
							</div>
						<?php endif; ?>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="time" hidden>
						<div class="pressark-trigger-slide-header">
							<div>
								<span class="pressark-trigger-badge"><?php esc_html_e( 'Time Schedule', 'pressark' ); ?></span>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'Set the schedule', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'Choose when the first run should happen and how often the automation repeats after that.', 'pressark' ); ?></p>
							</div>
							<button type="button" class="pressark-trigger-back" data-pressark-trigger-back><?php esc_html_e( 'Back', 'pressark' ); ?></button>
						</div>

						<div class="pressark-trigger-panel">
							<table class="form-table" style="margin-top:0;">
								<tr>
									<th><label for="pw-auto-cadence"><?php esc_html_e( 'Schedule', 'pressark' ); ?></label></th>
									<td>
										<div class="pressark-trigger-cadence-row">
											<select id="pw-auto-cadence" name="cadence_type" data-pressark-cadence-select>
												<option value="once"><?php esc_html_e( 'Once', 'pressark' ); ?></option>
												<option value="hourly"><?php esc_html_e( 'Hourly', 'pressark' ); ?></option>
												<option value="daily" selected><?php esc_html_e( 'Daily', 'pressark' ); ?></option>
												<option value="weekly"><?php esc_html_e( 'Weekly', 'pressark' ); ?></option>
												<option value="monthly"><?php esc_html_e( 'Monthly', 'pressark' ); ?></option>
												<option value="yearly"><?php esc_html_e( 'Yearly', 'pressark' ); ?></option>
											</select>
											<input type="number" name="cadence_value" value="1" min="1" max="24" class="small-text" data-pressark-cadence-value style="display:none;">
											<span data-pressark-cadence-label style="display:none;"><?php esc_html_e( 'hour(s)', 'pressark' ); ?></span>
										</div>
									</td>
								</tr>
								<tr>
									<th><label for="pw-auto-first-run"><?php esc_html_e( 'First Run', 'pressark' ); ?></label></th>
									<td>
										<input type="datetime-local" id="pw-auto-first-run" name="first_run_at" class="regular-text">
										<p class="description"><?php printf(
											/* translators: %s: site timezone string. */
											esc_html__( 'Site timezone: %s. Leave empty to start as soon as the next slot is available.', 'pressark' ),
											esc_html( $tz )
										); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="pressark-trigger-submit">
							<?php submit_button( __( 'Create Automation', 'pressark' ), 'primary', 'submit', false ); ?>
						</div>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="action" hidden>
						<div class="pressark-trigger-slide-header">
							<div>
								<span class="pressark-trigger-badge"><?php esc_html_e( 'Action Trigger', 'pressark' ); ?></span>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'Choose the event', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'PressArk will wait for the selected store event, then run the automation immediately.', 'pressark' ); ?></p>
							</div>
							<button type="button" class="pressark-trigger-back" data-pressark-trigger-back><?php esc_html_e( 'Back', 'pressark' ); ?></button>
						</div>

						<div class="pressark-trigger-panel">
							<?php if ( ! $can_trigger ) : ?>
								<div class="pressark-trigger-note">
									<strong><?php esc_html_e( 'Action triggers are temporarily unavailable.', 'pressark' ); ?></strong>
								</div>
							<?php else : ?>
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th><label for="pw-auto-event-trigger"><?php esc_html_e( 'Event', 'pressark' ); ?></label></th>
										<td>
											<select id="pw-auto-event-trigger" name="event_trigger">
												<option value=""><?php esc_html_e( 'Choose an event', 'pressark' ); ?></option>
												<?php foreach ( $trigger_modes as $value => $label ) : ?>
													<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'This automation will only run when the selected event occurs.', 'pressark' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label for="pw-auto-event-cooldown"><?php esc_html_e( 'Cooldown', 'pressark' ); ?></label></th>
										<td>
											<input type="number" id="pw-auto-event-cooldown" name="event_trigger_cooldown" value="60" min="5" class="small-text">
											<span><?php esc_html_e( 'minutes between runs', 'pressark' ); ?></span>
											<p class="description"><?php esc_html_e( 'Use a cooldown to prevent repeated runs when the same event fires in bursts.', 'pressark' ); ?></p>
										</td>
									</tr>
								</table>
							<?php endif; ?>
						</div>

						<?php if ( $can_trigger ) : ?>
							<div class="pressark-trigger-submit">
								<?php submit_button( __( 'Create Automation', 'pressark' ), 'primary', 'submit', false ); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	// ── Edit Tab ─────────────────────────────────────────────────────────

	private function render_edit_form( string $tier ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display: $_GET['id'] selects which automation to render in the edit form; ownership is verified below before any mutation, and the actual save handler enforces a nonce.
		$automation_id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
		$store         = new PressArk_Automation_Store();
		$a             = $store->get( $automation_id );

		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Automation not found.', 'pressark' ) . '</p></div>';
			return;
		}

		$policies             = PressArk_Automation_Policy::valid_policies();
		$can_trigger          = false; // Action triggers disabled — Watchdog feature removed.
		$trigger_modes        = $this->get_event_trigger_options();
		$current_mode         = $this->get_trigger_mode_for_automation( $a );
		$selected_mode        = 'hybrid' === $current_mode ? 'time' : $current_mode;
		$show_action_fields   = $can_trigger || 'action' === $selected_mode || 'hybrid' === $current_mode;
		$current_trigger      = (string) ( $a['event_trigger'] ?? '' );
		$current_cooldown     = max( 5, (int) ( (int) ( $a['event_trigger_cooldown'] ?? 3600 ) / 60 ) );
		$first_run_local      = $this->format_local_datetime_input( (string) ( $a['first_run_at'] ?? '' ), (string) ( $a['timezone'] ?? 'UTC' ) );
		$current_cadence_type = (string) ( $a['cadence_type'] ?? 'daily' );
		$current_cadence_val  = max( 1, (int) ( $a['cadence_value'] ?? 1 ) );

		$this->render_automation_form_assets();
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;max-width:780px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Edit Automation', 'pressark' ); ?></h2>

			<form method="post" data-pressark-trigger-wizard data-start-slide="choose">
				<?php wp_nonce_field( 'pressark_automation_edit' ); ?>
				<input type="hidden" name="pressark_automation_action" value="edit">
				<input type="hidden" name="automation_id" value="<?php echo esc_attr( $automation_id ); ?>">
				<input type="hidden" name="trigger_mode" value="<?php echo esc_attr( $selected_mode ); ?>" data-pressark-trigger-mode-input>

				<table class="form-table">
					<tr>
						<th><label for="pw-auto-name"><?php esc_html_e( 'Name', 'pressark' ); ?></label></th>
						<td><input type="text" id="pw-auto-name" name="name" class="regular-text" value="<?php echo esc_attr( $a['name'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="pw-auto-prompt"><?php esc_html_e( 'Prompt', 'pressark' ); ?></label></th>
						<td>
							<textarea id="pw-auto-prompt" name="prompt" rows="5" class="large-text"><?php echo esc_textarea( $a['prompt'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Update the instruction without having to recreate the automation.', 'pressark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="pw-auto-policy"><?php esc_html_e( 'Trust Policy', 'pressark' ); ?></label></th>
						<td>
							<select id="pw-auto-policy" name="approval_policy">
								<?php foreach ( $policies as $p ) : ?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $p, $a['approval_policy'] ?? '' ); ?>>
										<?php echo esc_html( ucfirst( $p ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<div class="pressark-trigger-wizard">
					<div class="pressark-trigger-steps" aria-hidden="true">
						<span class="pressark-trigger-step" data-pressark-step="choose"><span class="pressark-trigger-step__number">1</span><?php esc_html_e( 'Choose Trigger', 'pressark' ); ?></span>
						<span class="pressark-trigger-step" data-pressark-step="details"><span class="pressark-trigger-step__number">2</span><?php esc_html_e( 'Configure Details', 'pressark' ); ?></span>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="choose">
						<div class="pressark-trigger-slide-header">
							<div>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'Keep one trigger path', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'Each automation should now use either a time schedule or an action trigger, not both.', 'pressark' ); ?></p>
							</div>
						</div>

						<div class="pressark-trigger-grid">
							<label class="pressark-trigger-card <?php echo 'time' === $selected_mode ? 'is-active' : ''; ?>" data-pressark-trigger-card data-trigger-mode="time">
								<input type="radio" name="_pressark_trigger_picker" value="time" <?php checked( 'time', $selected_mode ); ?>>
								<span class="pressark-trigger-card-title"><?php esc_html_e( 'Time schedule', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-copy"><?php esc_html_e( 'Use a date/time schedule and recurring cadence.', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-cta"><?php esc_html_e( 'Edit schedule', 'pressark' ); ?></span>
							</label>

							<label class="pressark-trigger-card <?php echo 'action' === $selected_mode ? 'is-active' : ''; ?> <?php echo $show_action_fields ? '' : 'is-disabled'; ?>" data-pressark-trigger-card data-trigger-mode="action">
								<input type="radio" name="_pressark_trigger_picker" value="action" <?php checked( 'action', $selected_mode ); ?> <?php disabled( ! $show_action_fields ); ?>>
								<span class="pressark-trigger-card-title"><?php esc_html_e( 'Action trigger', 'pressark' ); ?></span>
								<span class="pressark-trigger-card-copy"><?php esc_html_e( 'Run whenever a matching WooCommerce event happens.', 'pressark' ); ?></span>
								<?php if ( $show_action_fields ) : ?>
									<span class="pressark-trigger-card-cta"><?php esc_html_e( 'Edit action trigger', 'pressark' ); ?></span>
								<?php else : ?>
									<span class="pressark-trigger-card-lock"><?php esc_html_e( 'Paused', 'pressark' ); ?></span>
								<?php endif; ?>
							</label>
						</div>

						<?php if ( 'hybrid' === $current_mode ) : ?>
							<div class="pressark-trigger-note">
								<strong><?php esc_html_e( 'Legacy hybrid detected.', 'pressark' ); ?></strong>
								<div><?php esc_html_e( 'This automation was created before the new split-flow UX. Saving now will keep only the trigger type you choose above.', 'pressark' ); ?></div>
							</div>
						<?php elseif ( ! $can_trigger && ! $show_action_fields ) : ?>
							<div class="pressark-trigger-note">
								<strong><?php esc_html_e( 'Action triggers are temporarily unavailable.', 'pressark' ); ?></strong>
							</div>
						<?php endif; ?>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="time" hidden>
						<div class="pressark-trigger-slide-header">
							<div>
								<span class="pressark-trigger-badge"><?php esc_html_e( 'Time Schedule', 'pressark' ); ?></span>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'Adjust the schedule', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'Update the cadence, start time, or timezone for this automation.', 'pressark' ); ?></p>
							</div>
							<button type="button" class="pressark-trigger-back" data-pressark-trigger-back><?php esc_html_e( 'Back', 'pressark' ); ?></button>
						</div>

						<div class="pressark-trigger-panel">
							<table class="form-table" style="margin-top:0;">
								<tr>
									<th><label for="pw-auto-cadence"><?php esc_html_e( 'Schedule', 'pressark' ); ?></label></th>
									<td>
										<div class="pressark-trigger-cadence-row">
											<select id="pw-auto-cadence" name="cadence_type" data-pressark-cadence-select>
												<?php foreach ( PressArk_Automation_Recurrence::CADENCE_TYPES as $ct ) : ?>
													<option value="<?php echo esc_attr( $ct ); ?>" <?php selected( $ct, $current_cadence_type ); ?>>
														<?php echo esc_html( ucfirst( $ct ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<input type="number" name="cadence_value" value="<?php echo esc_attr( $current_cadence_val ); ?>" min="1" max="24" class="small-text" data-pressark-cadence-value style="<?php echo 'hourly' === $current_cadence_type ? '' : 'display:none;'; ?>">
											<span data-pressark-cadence-label style="<?php echo 'hourly' === $current_cadence_type ? '' : 'display:none;'; ?>"><?php esc_html_e( 'hour(s)', 'pressark' ); ?></span>
										</div>
									</td>
								</tr>
								<tr>
									<th><label for="pw-auto-first-run"><?php esc_html_e( 'First Run', 'pressark' ); ?></label></th>
									<td>
										<input type="datetime-local" id="pw-auto-first-run" name="first_run_at" class="regular-text" value="<?php echo esc_attr( $first_run_local ); ?>">
										<p class="description"><?php esc_html_e( 'This anchors the recurring schedule. If it is already in the past, PressArk will pick the next future slot.', 'pressark' ); ?></p>
									</td>
								</tr>
								<tr>
									<th><label for="pw-auto-tz"><?php esc_html_e( 'Timezone', 'pressark' ); ?></label></th>
									<td>
										<input type="text" id="pw-auto-tz" name="timezone" class="regular-text" value="<?php echo esc_attr( $a['timezone'] ); ?>">
										<p class="description"><?php esc_html_e( 'Use an IANA timezone like Europe/Paris or America/New_York.', 'pressark' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="pressark-trigger-submit">
							<?php submit_button( __( 'Save Changes', 'pressark' ), 'primary', 'submit', false ); ?>
						</div>
					</div>

					<div class="pressark-trigger-slide" data-pressark-slide="action" hidden>
						<div class="pressark-trigger-slide-header">
							<div>
								<span class="pressark-trigger-badge"><?php esc_html_e( 'Action Trigger', 'pressark' ); ?></span>
								<h3 class="pressark-trigger-slide-title"><?php esc_html_e( 'Adjust the event trigger', 'pressark' ); ?></h3>
								<p class="pressark-trigger-slide-copy"><?php esc_html_e( 'Choose the store event that should wake this automation, then set a cooldown to prevent noisy repeats.', 'pressark' ); ?></p>
							</div>
							<button type="button" class="pressark-trigger-back" data-pressark-trigger-back><?php esc_html_e( 'Back', 'pressark' ); ?></button>
						</div>

						<div class="pressark-trigger-panel">
							<?php if ( ! $show_action_fields ) : ?>
								<div class="pressark-trigger-note">
									<strong><?php esc_html_e( 'Action triggers are temporarily unavailable.', 'pressark' ); ?></strong>
								</div>
							<?php else : ?>
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th><label for="pw-auto-event-trigger"><?php esc_html_e( 'Event', 'pressark' ); ?></label></th>
										<td>
											<select id="pw-auto-event-trigger" name="event_trigger">
												<option value=""><?php esc_html_e( 'Choose an event', 'pressark' ); ?></option>
												<?php foreach ( $trigger_modes as $value => $label ) : ?>
													<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $current_trigger ); ?>><?php echo esc_html( $label ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'This automation will only react to the event selected here.', 'pressark' ); ?></p>
										</td>
									</tr>
									<tr>
										<th><label for="pw-auto-event-cooldown"><?php esc_html_e( 'Cooldown', 'pressark' ); ?></label></th>
										<td>
											<input type="number" id="pw-auto-event-cooldown" name="event_trigger_cooldown" value="<?php echo esc_attr( $current_cooldown ); ?>" min="5" class="small-text">
											<span><?php esc_html_e( 'minutes between runs', 'pressark' ); ?></span>
											<p class="description"><?php esc_html_e( 'Longer cooldowns help tame noisy events on busy stores.', 'pressark' ); ?></p>
										</td>
									</tr>
								</table>

								<?php if ( ! $can_trigger ) : ?>
									<div class="pressark-trigger-note">
										<strong><?php esc_html_e( 'This trigger type is temporarily unavailable.', 'pressark' ); ?></strong>
										<div><?php esc_html_e( 'Switch this automation back to a time schedule before saving it again.', 'pressark' ); ?></div>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<?php if ( $show_action_fields ) : ?>
							<div class="pressark-trigger-submit">
								<?php submit_button( __( 'Save Changes', 'pressark' ), 'primary', 'submit', false ); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</form>

			<?php if ( $a['last_error'] ) : ?>
				<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:12px;margin-top:12px;">
					<strong style="color:#dc2626;"><?php esc_html_e( 'Last Error:', 'pressark' ); ?></strong>
					<p style="margin:4px 0 0;"><?php echo esc_html( $a['last_error'] ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Notifications Settings Tab ───────────────────────────────────────

	private function render_notification_settings(): void {
		$user_id    = get_current_user_id();
		$has_token  = ! empty( get_option( 'pressark_telegram_bot_token', '' ) );
		$chat_id    = get_user_meta( $user_id, 'pressark_telegram_chat_id', true );
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;max-width:600px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Telegram Notifications', 'pressark' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Receive automation results via Telegram. Set up a bot and enter your details below.', 'pressark' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'pressark_automation_save_settings' ); ?>
				<input type="hidden" name="pressark_automation_action" value="save_settings">
				<input type="hidden" name="_redirect_tab" value="settings">

				<table class="form-table">
					<tr>
						<th><label for="pw-tg-token"><?php esc_html_e( 'Bot Token', 'pressark' ); ?></label></th>
						<td>
							<input type="password" id="pw-tg-token" name="telegram_bot_token" class="regular-text" autocomplete="off"
								placeholder="<?php echo $has_token ? esc_attr__( 'Token saved (enter new to replace)', 'pressark' ) : esc_attr__( '123456:ABC-DEF...', 'pressark' ); ?>">
							<p class="description"><?php esc_html_e( 'Create a bot via @BotFather on Telegram. Encrypted before storage.', 'pressark' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="pw-tg-chat"><?php esc_html_e( 'Chat ID', 'pressark' ); ?></label></th>
						<td>
							<input type="text" id="pw-tg-chat" name="telegram_chat_id" class="regular-text"
								value="<?php echo esc_attr( $chat_id ); ?>"
								placeholder="<?php esc_attr_e( '123456789', 'pressark' ); ?>">
							<p class="description"><?php esc_html_e( 'Send /start to @userinfobot on Telegram to get your ID. Each admin sets their own.', 'pressark' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'pressark' ) ); ?>
			</form>

			<?php if ( $has_token && $chat_id ) : ?>
				<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:4px;padding:12px;margin-top:8px;">
					<strong style="color:#16a34a;"><?php echo wp_kses( pressark_icon( 'check' ), pressark_icon_allowed_html() ); ?> <?php esc_html_e( 'Telegram configured', 'pressark' ); ?></strong>
					<span style="color:#64748b;margin-left:8px;"><?php printf(
						/* translators: %s: Telegram chat ID. */
						esc_html__( 'Chat ID: %s', 'pressark' ),
						esc_html( $chat_id )
					); ?></span>
				</div>
			<?php elseif ( ! $has_token ) : ?>
				<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:12px;margin-top:8px;">
					<strong style="color:#d97706;"><?php esc_html_e( 'Bot token not set', 'pressark' ); ?></strong>
					<p style="margin:4px 0 0;color:#64748b;"><?php esc_html_e( 'Automations will still run, but you won\'t receive Telegram notifications.', 'pressark' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
