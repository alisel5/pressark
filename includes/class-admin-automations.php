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
			wp_die( __( 'Security check failed.', 'pressark' ) );
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
		$tier = ( new PressArk_License() )->get_tier();

		if ( ! PressArk_Entitlements::can_use_feature( $tier, 'automations' ) ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Automations require a Pro or higher plan.', 'pressark' ) ), 30 );
			return;
		}

		$max = PressArk_Entitlements::tier_value( $tier, 'max_automations' ) ?? 3;
		$current = $store->count_active( $user_id );
		if ( $max >= 0 && $current >= $max ) {
			set_transient( 'pressark_auto_notice', array( 'error', sprintf(
				/* translators: 1: current active automation count, 2: plan limit. */
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

		$cadence_type  = sanitize_key( wp_unslash( $_POST['cadence_type'] ?? 'daily' ) );
		$cadence_value = absint( wp_unslash( $_POST['cadence_value'] ?? 0 ) );
		$timezone      = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? wp_timezone_string() ) );
		$first_run_at  = sanitize_text_field( wp_unslash( $_POST['first_run_at'] ?? '' ) );

		// Enforce minimum automation interval for the tier.
		$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
		$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $cadence_type, $cadence_value );
		if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
			set_transient( 'pressark_auto_notice', array( 'error', sprintf(
				/* translators: %s: minimum allowed time between automation runs. */
				__( 'Your plan requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
				human_time_diff( 0, $min_interval )
			) ), 30 );
			return;
		}

		if ( empty( $first_run_at ) ) {
			// Default: next occurrence based on cadence from now.
			$first_run_at = current_time( 'mysql', true );
		} else {
			// Convert user-local datetime to UTC.
			try {
				$tz  = new \DateTimeZone( $timezone ?: 'UTC' );
				$utc = new \DateTimeZone( 'UTC' );
				$dt  = new \DateTime( $first_run_at, $tz );
				$dt->setTimezone( $utc );
				$first_run_at = $dt->format( 'Y-m-d H:i:s' );
			} catch ( \Exception $e ) {
				$first_run_at = current_time( 'mysql', true );
			}
		}

		$automation_id = $store->create( array(
			'user_id'              => $user_id,
			'name'                 => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ?: mb_substr( wp_strip_all_tags( $prompt ), 0, 80 ),
			'prompt'               => $prompt,
			'timezone'             => $timezone,
			'cadence_type'         => $cadence_type,
			'cadence_value'        => $cadence_value,
			'first_run_at'         => $first_run_at,
			'approval_policy'      => sanitize_key( wp_unslash( $_POST['approval_policy'] ?? PressArk_Automation_Policy::default_for_tier( $tier ) ) ),
			'notification_channel' => 'telegram',
			'notification_target'  => PressArk_Notification_Manager::get_user_telegram_id( $user_id ),
		) );

		PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $first_run_at );

		// Dispatch immediately if the first run is now or already past.
		// This bypasses the cron/scheduler path entirely for the initial
		// run — critical in Docker where WP-Cron's loopback fails and
		// the cron lock can block the sidecar container.
		PressArk_Automation_Dispatcher::dispatch_if_due( $automation_id );

		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation created successfully.', 'pressark' ) ), 30 );
	}

	private function handle_edit( PressArk_Automation_Store $store, int $user_id ): void {
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
		if ( isset( $_POST['cadence_type'] ) && PressArk_Automation_Recurrence::is_valid_cadence( wp_unslash( $_POST['cadence_type'] ) ) ) {
			$update['cadence_type'] = sanitize_key( wp_unslash( $_POST['cadence_type'] ) );
		}
		if ( isset( $_POST['cadence_value'] ) ) {
			$update['cadence_value'] = absint( wp_unslash( $_POST['cadence_value'] ) );
		}
		if ( isset( $_POST['timezone'] ) ) {
			$update['timezone'] = sanitize_text_field( wp_unslash( $_POST['timezone'] ) );
		}
		if ( isset( $_POST['approval_policy'] ) ) {
			$update['approval_policy'] = sanitize_key( wp_unslash( $_POST['approval_policy'] ) );
		}

		// Recompute next run if cadence changed.
		if ( ! empty( $update['cadence_type'] ) || ! empty( $update['cadence_value'] ) ) {
			$ct = $update['cadence_type'] ?? $automation['cadence_type'];
			$cv = $update['cadence_value'] ?? $automation['cadence_value'];
			$tz = $update['timezone'] ?? $automation['timezone'];

			// Enforce minimum automation interval for the tier.
			$tier            = ( new PressArk_License() )->get_tier();
			$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
			$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $ct, $cv );
			if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
				set_transient( 'pressark_auto_notice', array( 'error', sprintf(
					/* translators: %s: minimum allowed time between automation runs. */
					__( 'Your plan requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
					human_time_diff( 0, $min_interval )
				) ), 30 );
				return;
			}

			$next = PressArk_Automation_Recurrence::compute_next(
				$ct, $cv, $tz,
				$automation['first_run_at'],
				$automation['next_run_at'] ?? null
			);
			if ( $next ) {
				$update['next_run_at'] = $next;
				PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $next );
			}
		}

		if ( ! empty( $update ) ) {
			$store->update( $automation_id, $update );
		}

		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation updated.', 'pressark' ) ), 30 );
	}

	private function handle_delete( PressArk_Automation_Store $store, int $user_id ): void {
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== $user_id ) {
			set_transient( 'pressark_auto_notice', array( 'error', __( 'Automation not found.', 'pressark' ) ), 30 );
			return;
		}

		$store->delete( $automation_id );
		set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation deleted.', 'pressark' ) ), 30 );
	}

	private function handle_toggle( PressArk_Automation_Store $store, int $user_id ): void {
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
	}

	private function handle_run_now( int $user_id ): void {
		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ?? '' ) );
		$result = PressArk_Automation_Dispatcher::run_now( $automation_id, $user_id );

		if ( ! empty( $result['success'] ) ) {
			set_transient( 'pressark_auto_notice', array( 'success', __( 'Automation dispatched. Results will arrive shortly.', 'pressark' ) ), 30 );
		} else {
			set_transient( 'pressark_auto_notice', array( 'error', $result['error'] ?? __( 'Failed to dispatch automation.', 'pressark' ) ), 30 );
		}
	}

	private function handle_save_settings( int $user_id ): void {
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
	}

	// ── Page Renderer ────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! PressArk_Capabilities::current_user_can_manage_automations() ) {
			return;
		}

		$tier    = ( new PressArk_License() )->get_tier();
		$can_use = PressArk_Entitlements::can_use_feature( $tier, 'automations' );
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
						<?php esc_html_e( 'Scheduled automations require a Pro or higher plan.', 'pressark' ); ?>
						<?php $url = pressark_get_upgrade_url(); ?>
						<a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Upgrade', 'pressark' ); ?></a>
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
				/* translators: 1: current automation count, 2: plan limit label, 3: plan name. */
				esc_html__( '%1$d automation(s) (limit: %2$s for your %3$s plan)', 'pressark' ),
				count( $automations ),
				esc_html( $max_label ),
				esc_html( PressArk_Entitlements::tier_label( $tier ) )
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
					<td><?php echo esc_html( PressArk_Automation_Recurrence::label( $a['cadence_type'], $a['cadence_value'] ) ); ?></td>
					<td>
						<span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:600;">
							<?php echo esc_html( ucfirst( $a['status'] ) ); ?>
						</span>
						<?php if ( $a['failure_streak'] > 0 ) : ?>
							<br><small style="color:#dc2626;"><?php printf(
								/* translators: %d: number of consecutive automation failures. */
								esc_html__( '%d failures', 'pressark' ),
								$a['failure_streak']
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
		$policies = PressArk_Automation_Policy::valid_policies();
		$default_policy = PressArk_Automation_Policy::default_for_tier( $tier );
		$tz = wp_timezone_string();
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;max-width:700px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Create Automation', 'pressark' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'pressark_automation_create' ); ?>
				<input type="hidden" name="pressark_automation_action" value="create">

				<table class="form-table">
					<tr>
						<th><label for="pw-auto-name"><?php esc_html_e( 'Name', 'pressark' ); ?></label></th>
						<td>
							<input type="text" id="pw-auto-name" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Weekly SEO Audit', 'pressark' ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Defaults to first 80 chars of prompt.', 'pressark' ); ?></p>
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
						<th><label for="pw-auto-cadence"><?php esc_html_e( 'Schedule', 'pressark' ); ?></label></th>
						<td>
							<select id="pw-auto-cadence" name="cadence_type">
								<option value="once"><?php esc_html_e( 'Once', 'pressark' ); ?></option>
								<option value="hourly"><?php esc_html_e( 'Hourly', 'pressark' ); ?></option>
								<option value="daily" selected><?php esc_html_e( 'Daily', 'pressark' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly', 'pressark' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Monthly', 'pressark' ); ?></option>
								<option value="yearly"><?php esc_html_e( 'Yearly', 'pressark' ); ?></option>
							</select>
							<input type="number" name="cadence_value" value="1" min="1" max="24" class="small-text" id="pw-auto-cadence-val" style="display:none;">
							<span id="pw-auto-cadence-val-label" style="display:none;"><?php esc_html_e( 'hour(s)', 'pressark' ); ?></span>
							<script>
							(function(){
								var sel = document.getElementById('pw-auto-cadence');
								var val = document.getElementById('pw-auto-cadence-val');
								var lbl = document.getElementById('pw-auto-cadence-val-label');
								sel.addEventListener('change', function(){
									var show = this.value === 'hourly';
									val.style.display = show ? 'inline-block' : 'none';
									lbl.style.display = show ? 'inline' : 'none';
								});
							})();
							</script>
						</td>
					</tr>
					<tr>
						<th><label for="pw-auto-first-run"><?php esc_html_e( 'First Run', 'pressark' ); ?></label></th>
						<td>
							<input type="datetime-local" id="pw-auto-first-run" name="first_run_at" class="regular-text">
							<p class="description"><?php printf(
								/* translators: %s: site timezone string. */
								esc_html__( 'Site timezone: %s. Leave empty to start at the next scheduled slot.', 'pressark' ),
								esc_html( $tz )
							); ?></p>
							<input type="hidden" name="timezone" value="<?php echo esc_attr( $tz ); ?>">
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
							<p class="description">
								<?php esc_html_e( 'Editorial: content/SEO only. Merchandising: + WooCommerce products. Full: all except destructive.', 'pressark' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Create Automation', 'pressark' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Edit Tab ─────────────────────────────────────────────────────────

	private function render_edit_form( string $tier ): void {
		$automation_id = sanitize_text_field( wp_unslash( $_GET['id'] ?? '' ) );
		$store         = new PressArk_Automation_Store();
		$a             = $store->get( $automation_id );

		if ( ! $a || (int) $a['user_id'] !== get_current_user_id() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Automation not found.', 'pressark' ) . '</p></div>';
			return;
		}

		$policies = PressArk_Automation_Policy::valid_policies();
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;max-width:700px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Edit Automation', 'pressark' ); ?></h2>

			<form method="post">
				<?php wp_nonce_field( 'pressark_automation_edit' ); ?>
				<input type="hidden" name="pressark_automation_action" value="edit">
				<input type="hidden" name="automation_id" value="<?php echo esc_attr( $automation_id ); ?>">

				<table class="form-table">
					<tr>
						<th><label for="pw-auto-name"><?php esc_html_e( 'Name', 'pressark' ); ?></label></th>
						<td><input type="text" id="pw-auto-name" name="name" class="regular-text" value="<?php echo esc_attr( $a['name'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="pw-auto-prompt"><?php esc_html_e( 'Prompt', 'pressark' ); ?></label></th>
						<td><textarea id="pw-auto-prompt" name="prompt" rows="5" class="large-text"><?php echo esc_textarea( $a['prompt'] ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="pw-auto-cadence"><?php esc_html_e( 'Schedule', 'pressark' ); ?></label></th>
						<td>
							<select id="pw-auto-cadence" name="cadence_type">
								<?php foreach ( PressArk_Automation_Recurrence::CADENCE_TYPES as $ct ) : ?>
									<option value="<?php echo esc_attr( $ct ); ?>" <?php selected( $ct, $a['cadence_type'] ); ?>>
										<?php echo esc_html( ucfirst( $ct ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( 'hourly' === $a['cadence_type'] ) : ?>
								<input type="number" name="cadence_value" value="<?php echo absint( $a['cadence_value'] ); ?>" min="1" max="24" class="small-text">
								<span><?php esc_html_e( 'hour(s)', 'pressark' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="pw-auto-tz"><?php esc_html_e( 'Timezone', 'pressark' ); ?></label></th>
						<td><input type="text" id="pw-auto-tz" name="timezone" class="regular-text" value="<?php echo esc_attr( $a['timezone'] ); ?>"></td>
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

				<?php submit_button( __( 'Save Changes', 'pressark' ) ); ?>
			</form>

			<?php // Show run history context. ?>
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
					<strong style="color:#16a34a;">&#10003; <?php esc_html_e( 'Telegram configured', 'pressark' ); ?></strong>
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
