<?php
/**
 * PressArk Privacy Hooks
 *
 * Implements WordPress privacy policy text, personal data exporter, and eraser.
 *
 * Export: all user-linked personal data across every PressArk table.
 * Erase:  batched deletion of all user-linked rows + per-user options/meta.
 *
 * Data classification:
 *   PERSONAL  — exported + erased (chats, logs, runs, tasks, automations, user meta)
 *   TELEMETRY — erased only (cost_ledger — operational billing data, not exported)
 *   CONTENT   — neither (content_index — site content, not personal data)
 *
 * @since 2.0.1
 * @since 4.1.0 Rewritten: correct per-table pagination, expanded store coverage,
 *              batched eraser, full chat message export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Privacy {

	/**
	 * Max items per export page (per table). WordPress calls the exporter
	 * repeatedly with incrementing $page until done=true.
	 */
	private const EXPORT_PER_PAGE = 50;

	/**
	 * Max rows deleted per eraser pass per table.
	 */
	private const ERASE_BATCH = 500;

	public function __construct() {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add suggested privacy policy text.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>' . __( 'PressArk', 'pressark' ) . '</h2>';

		$content .= '<p>' . __( 'PressArk stores the following personal data in custom database tables on your server:', 'pressark' ) . '</p>';

		$content .= '<ul>';
		$content .= '<li>' . __( '<strong>Chat conversations</strong> — message content, titles, and timestamps.', 'pressark' ) . '</li>';
		$content .= '<li>' . __( '<strong>Action log</strong> — records of actions performed through the assistant (action type, target, timestamp).', 'pressark' ) . '</li>';
		$content .= '<li>' . __( '<strong>Execution runs</strong> — transient server-side state for in-flight requests (route, status, timestamps). Automatically cleaned up within hours to days.', 'pressark' ) . '</li>';
		$content .= '<li>' . __( '<strong>Background tasks</strong> — queued async operations and their results. Automatically cleaned up within hours to days.', 'pressark' ) . '</li>';
		$content .= '<li>' . __( '<strong>Scheduled automations</strong> — automation configurations including prompt text, schedule, execution metadata, and notification destinations.', 'pressark' ) . '</li>';
		$content .= '<li>' . __( '<strong>Notification settings</strong> — Telegram chat ID stored as user meta when Telegram notifications are enabled.', 'pressark' ) . '</li>';
		$content .= '</ul>';

		$content .= '<p>' . __( 'PressArk also stores <strong>operational billing telemetry</strong> (credit usage, raw token usage, cost estimates, provider/model metadata) in a cost ledger table. This data is linked to your user ID and is erased on request, but is not included in personal data exports as it is operational in nature.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'Chat messages, screen context, and content excerpts are sent to the configured AI provider (OpenRouter, OpenAI, Anthropic, DeepSeek, or Google Gemini) to generate responses.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'In bundled billing mode, Freemius handles billing, subscriptions, trials, and site activations. The PressArk token-bank service receives your site domain, a numeric user identifier, plan tier, and credit-usage totals to enforce plan limits and purchased credit balances.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'In BYOK (Bring Your Own Key) mode, AI requests are sent directly to your chosen provider. No bundled credits are consumed and no data is sent to PressArk services for AI billing.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'PressArk builds a local full-text index of published content in a custom database table. This is site content, not personal data, and does not leave your server except as context included in AI requests.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'If Telegram notifications are enabled for automations, your Telegram chat ID and bot token are stored encrypted. Notification messages are sent via the Telegram Bot API and may include summaries of actions performed.', 'pressark' ) . '</p>';

		$content .= '<h3>' . __( 'Data Retention', 'pressark' ) . '</h3>';
		$content .= '<p>' . __( 'PressArk applies configurable retention policies: action logs (default 90 days), chat history (default 180 days), cost telemetry (default 365 days). Execution runs and background tasks are automatically cleaned up on shorter cycles (hours to days). You can adjust retention periods in PressArk Settings.', 'pressark' ) . '</p>';

		$content .= '<p>' . __( 'All API keys are encrypted at rest using Sodium authenticated encryption (XSalsa20-Poly1305). When the plugin is uninstalled, all plugin data (tables, options, transients, user meta, and scheduled events) is removed.', 'pressark' ) . '</p>';

		wp_add_privacy_policy_content( 'PressArk', $content );
	}

	/**
	 * Register personal data exporter.
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['pressark'] = array(
			'exporter_friendly_name' => __( 'PressArk Data', 'pressark' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register personal data eraser.
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['pressark'] = array(
			'eraser_friendly_name' => __( 'PressArk Data', 'pressark' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	// ── Export ───────────────────────────────────────────────────────

	/**
	 * Export personal data for a user.
	 *
	 * Each table is queried independently with the same per-page offset.
	 * `done` is only true when ALL tables are exhausted for this page.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number (1-indexed).
	 * @return array { data: array, done: bool }
	 */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$per_page     = self::EXPORT_PER_PAGE;
		$offset       = ( $page - 1 ) * $per_page;
		$export_items = array();
		$has_more     = false;

		$has_more |= $this->export_chats( $user->ID, $per_page, $offset, $export_items );
		$has_more |= $this->export_logs( $user->ID, $per_page, $offset, $export_items );
		$has_more |= $this->export_runs( $user->ID, $per_page, $offset, $export_items );
		$has_more |= $this->export_tasks( $user->ID, $per_page, $offset, $export_items );
		$has_more |= $this->export_automations( $user->ID, $per_page, $offset, $export_items );

		// User meta / settings — only on the first page.
		if ( 0 === $offset ) {
			$this->export_user_meta( $user->ID, $export_items );
		}

		return array(
			'data' => $export_items,
			'done' => ! $has_more,
		);
	}

	/**
	 * Export chat conversations including message content.
	 *
	 * @return bool True if more rows may exist beyond this page.
	 */
	private function export_chats( int $user_id, int $per_page, int $offset, array &$items ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_chats';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, messages, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$messages_raw = json_decode( $row['messages'] ?? '[]', true );
			$message_text = $this->summarize_messages( $messages_raw );

			$items[] = array(
				'group_id'          => 'pressark-chats',
				'group_label'       => __( 'PressArk Chat History', 'pressark' ),
				'group_description' => __( 'Chat conversations stored by PressArk, including message content.', 'pressark' ),
				'item_id'           => "pressark-chat-{$row['id']}",
				'data'              => array(
					array( 'name' => __( 'Chat Title', 'pressark' ), 'value' => $row['title'] ),
					array( 'name' => __( 'Messages Transcript', 'pressark' ), 'value' => $message_text ),
					array( 'name' => __( 'Messages JSON', 'pressark' ), 'value' => $this->format_export_blob( $messages_raw ) ),
					array( 'name' => __( 'Created', 'pressark' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Last Updated', 'pressark' ), 'value' => $row['updated_at'] ),
				),
			);
		}

		return count( $rows ) >= $per_page;
	}

	/**
	 * Export action log entries.
	 *
	 * @return bool True if more rows may exist.
	 */
	private function export_logs( int $user_id, int $per_page, int $offset, array &$items ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_log';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, action_type, target_id, target_type, old_value, new_value, created_at, undone
			 FROM {$table}
			 WHERE user_id = %d
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'pressark-actions',
				'group_label'       => __( 'PressArk Action Log', 'pressark' ),
				'group_description' => __( 'Actions performed through PressArk.', 'pressark' ),
				'item_id'           => "pressark-action-{$row['id']}",
				'data'              => array(
					array( 'name' => __( 'Log ID', 'pressark' ), 'value' => $row['id'] ),
					array( 'name' => __( 'Action Type', 'pressark' ), 'value' => $row['action_type'] ),
					array( 'name' => __( 'Target', 'pressark' ), 'value' => ( $row['target_type'] ?? '' ) . ' #' . ( $row['target_id'] ?? '' ) ),
					array( 'name' => __( 'Previous Value', 'pressark' ), 'value' => $this->format_export_blob( $row['old_value'] ?? '' ) ),
					array( 'name' => __( 'New Value', 'pressark' ), 'value' => $this->format_export_blob( $row['new_value'] ?? '' ) ),
					array( 'name' => __( 'Undone', 'pressark' ), 'value' => ! empty( $row['undone'] ) ? __( 'Yes', 'pressark' ) : __( 'No', 'pressark' ) ),
					array( 'name' => __( 'Date', 'pressark' ), 'value' => $row['created_at'] ),
				),
			);
		}

		return count( $rows ) >= $per_page;
	}

	/**
	 * Export execution run records.
	 *
	 * @return bool True if more rows may exist.
	 */
	private function export_runs( int $user_id, int $per_page, int $offset, array &$items ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_runs';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT run_id, chat_id, route, status, message, reservation_id, workflow_class, workflow_state,
			        preview_session_id, pending_actions, result, tier, created_at, updated_at, settled_at
			 FROM {$table}
			 WHERE user_id = %d
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'pressark-runs',
				'group_label'       => __( 'PressArk Execution Runs', 'pressark' ),
				'group_description' => __( 'Execution records for PressArk requests.', 'pressark' ),
				'item_id'           => "pressark-run-{$row['run_id']}",
				'data'              => array(
					array( 'name' => __( 'Run ID', 'pressark' ), 'value' => $row['run_id'] ),
					array( 'name' => __( 'Chat ID', 'pressark' ), 'value' => $row['chat_id'] ),
					array( 'name' => __( 'Route', 'pressark' ), 'value' => $row['route'] ),
					array( 'name' => __( 'Status', 'pressark' ), 'value' => $row['status'] ),
					array( 'name' => __( 'Message', 'pressark' ), 'value' => (string) $row['message'] ),
					array( 'name' => __( 'Reservation ID', 'pressark' ), 'value' => $row['reservation_id'] ?? '' ),
					array( 'name' => __( 'Workflow Class', 'pressark' ), 'value' => $row['workflow_class'] ?? '' ),
					array( 'name' => __( 'Workflow State', 'pressark' ), 'value' => $this->format_export_blob( $row['workflow_state'] ?? '' ) ),
					array( 'name' => __( 'Preview Session ID', 'pressark' ), 'value' => $row['preview_session_id'] ?? '' ),
					array( 'name' => __( 'Pending Actions', 'pressark' ), 'value' => $this->format_export_blob( $row['pending_actions'] ?? '' ) ),
					array( 'name' => __( 'Result', 'pressark' ), 'value' => $this->format_export_blob( $row['result'] ?? '' ) ),
					array( 'name' => __( 'Tier', 'pressark' ), 'value' => $row['tier'] ?? '' ),
					array( 'name' => __( 'Created', 'pressark' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Updated', 'pressark' ), 'value' => $row['updated_at'] ),
					array( 'name' => __( 'Settled', 'pressark' ), 'value' => $row['settled_at'] ?: __( 'Not settled', 'pressark' ) ),
				),
			);
		}

		return count( $rows ) >= $per_page;
	}

	/**
	 * Export background task records.
	 *
	 * @return bool True if more rows may exist.
	 */
	private function export_tasks( int $user_id, int $per_page, int $offset, array &$items ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_tasks';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT task_id, message, payload, reservation_id, status, retries, max_retries, fail_reason,
			        result, created_at, started_at, completed_at, expires_at
			 FROM {$table}
			 WHERE user_id = %d
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'pressark-tasks',
				'group_label'       => __( 'PressArk Background Tasks', 'pressark' ),
				'group_description' => __( 'Asynchronous background tasks processed by PressArk.', 'pressark' ),
				'item_id'           => "pressark-task-{$row['task_id']}",
				'data'              => array(
					array( 'name' => __( 'Task ID', 'pressark' ), 'value' => $row['task_id'] ),
					array( 'name' => __( 'Message', 'pressark' ), 'value' => (string) $row['message'] ),
					array( 'name' => __( 'Payload', 'pressark' ), 'value' => $this->format_export_blob( $row['payload'] ?? '' ) ),
					array( 'name' => __( 'Reservation ID', 'pressark' ), 'value' => $row['reservation_id'] ?? '' ),
					array( 'name' => __( 'Status', 'pressark' ), 'value' => $row['status'] ),
					array( 'name' => __( 'Retries', 'pressark' ), 'value' => $row['retries'] ),
					array( 'name' => __( 'Max Retries', 'pressark' ), 'value' => $row['max_retries'] ),
					array( 'name' => __( 'Failure Reason', 'pressark' ), 'value' => (string) ( $row['fail_reason'] ?? '' ) ),
					array( 'name' => __( 'Result', 'pressark' ), 'value' => $this->format_export_blob( $row['result'] ?? '' ) ),
					array( 'name' => __( 'Created', 'pressark' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Started', 'pressark' ), 'value' => $row['started_at'] ?: __( 'Not started', 'pressark' ) ),
					array( 'name' => __( 'Completed', 'pressark' ), 'value' => $row['completed_at'] ?: __( 'Not completed', 'pressark' ) ),
					array( 'name' => __( 'Expires', 'pressark' ), 'value' => $row['expires_at'] ?: __( 'No expiry', 'pressark' ) ),
				),
			);
		}

		return count( $rows ) >= $per_page;
	}

	/**
	 * Export automation records.
	 *
	 * @return bool True if more rows may exist.
	 */
	private function export_automations( int $user_id, int $per_page, int $offset, array &$items ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'pressark_automations';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT automation_id, name, prompt, timezone, cadence_type, cadence_value, first_run_at, next_run_at,
			        approval_policy, allowed_groups, last_run_id, last_task_id, last_success_at, last_failure_at,
			        last_error, failure_streak, execution_hints, notification_channel, notification_target,
			        status, chat_id, claimed_at, claimed_by, created_at, updated_at
			 FROM {$table}
			 WHERE user_id = %d
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$user_id,
			$per_page,
			$offset
		), ARRAY_A );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'pressark-automations',
				'group_label'       => __( 'PressArk Automations', 'pressark' ),
				'group_description' => __( 'Scheduled prompt automations stored by PressArk.', 'pressark' ),
				'item_id'           => "pressark-automation-{$row['automation_id']}",
				'data'              => array(
					array( 'name' => __( 'Automation ID', 'pressark' ), 'value' => $row['automation_id'] ),
					array( 'name' => __( 'Name', 'pressark' ), 'value' => $row['name'] ),
					array( 'name' => __( 'Prompt', 'pressark' ), 'value' => (string) $row['prompt'] ),
					array( 'name' => __( 'Timezone', 'pressark' ), 'value' => $row['timezone'] ?? '' ),
					array( 'name' => __( 'Cadence', 'pressark' ), 'value' => $row['cadence_type'] ),
					array( 'name' => __( 'Cadence Value', 'pressark' ), 'value' => $row['cadence_value'] ?? '' ),
					array( 'name' => __( 'First Run At', 'pressark' ), 'value' => $row['first_run_at'] ?? '' ),
					array( 'name' => __( 'Next Run At', 'pressark' ), 'value' => $row['next_run_at'] ?? '' ),
					array( 'name' => __( 'Approval Policy', 'pressark' ), 'value' => $row['approval_policy'] ?? '' ),
					array( 'name' => __( 'Allowed Groups', 'pressark' ), 'value' => $this->format_export_blob( $row['allowed_groups'] ?? '' ) ),
					array( 'name' => __( 'Last Run ID', 'pressark' ), 'value' => $row['last_run_id'] ?? '' ),
					array( 'name' => __( 'Last Task ID', 'pressark' ), 'value' => $row['last_task_id'] ?? '' ),
					array( 'name' => __( 'Last Success At', 'pressark' ), 'value' => $row['last_success_at'] ?? '' ),
					array( 'name' => __( 'Last Failure At', 'pressark' ), 'value' => $row['last_failure_at'] ?? '' ),
					array( 'name' => __( 'Last Error', 'pressark' ), 'value' => (string) ( $row['last_error'] ?? '' ) ),
					array( 'name' => __( 'Failure Streak', 'pressark' ), 'value' => $row['failure_streak'] ?? '' ),
					array( 'name' => __( 'Notification Channel', 'pressark' ), 'value' => $row['notification_channel'] ?? '' ),
					array( 'name' => __( 'Notification Target', 'pressark' ), 'value' => $row['notification_target'] ?? '' ),
					array( 'name' => __( 'Execution Hints', 'pressark' ), 'value' => $this->format_export_blob( $row['execution_hints'] ?? '' ) ),
					array( 'name' => __( 'Status', 'pressark' ), 'value' => $row['status'] ),
					array( 'name' => __( 'Chat ID', 'pressark' ), 'value' => $row['chat_id'] ?? '' ),
					array( 'name' => __( 'Claimed At', 'pressark' ), 'value' => $row['claimed_at'] ?? '' ),
					array( 'name' => __( 'Claimed By', 'pressark' ), 'value' => $row['claimed_by'] ?? '' ),
					array( 'name' => __( 'Created', 'pressark' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Updated', 'pressark' ), 'value' => $row['updated_at'] ?? '' ),
				),
			);
		}

		return count( $rows ) >= $per_page;
	}

	/**
	 * Export user meta and per-user settings (non-paginated, first page only).
	 */
	private function export_user_meta( int $user_id, array &$items ): void {
		$meta_keys = array(
			'pressark_telegram_chat_id' => __( 'Telegram Chat ID', 'pressark' ),
			'pressark_onboarded'        => __( 'Onboarding Completed', 'pressark' ),
			'pressark_group_usage'      => __( 'Tool Group Usage', 'pressark' ),
		);

		$data = array();
		foreach ( $meta_keys as $key => $label ) {
			$value = get_user_meta( $user_id, $key, true );

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			if ( null === $value || false === $value ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$data[] = array( 'name' => $label, 'value' => (string) $value );
		}

		if ( ! empty( $data ) ) {
			$items[] = array(
				'group_id'          => 'pressark-settings',
				'group_label'       => __( 'PressArk User Settings', 'pressark' ),
				'group_description' => __( 'Per-user settings and notification destinations stored by PressArk.', 'pressark' ),
				'item_id'           => "pressark-usermeta-{$user_id}",
				'data'              => $data,
			);
		}
	}

	// ── Erase ───────────────────────────────────────────────────────

	/**
	 * Erase personal data for a user.
	 *
	 * Processes all tables with batched deletes, plus per-user options,
	 * transients, and user meta. Returns done=false if any table still
	 * has rows remaining (WordPress will call again with $page+1).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number (unused — we batch internally).
	 * @return array WP eraser response.
	 */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$uid           = $user->ID;
		$batch         = self::ERASE_BATCH;
		$items_removed = 0;
		$has_more      = false;

		// Personal data tables (user_id column).
		$tables = array(
			'pressark_chats',
			'pressark_log',
			'pressark_runs',
			'pressark_tasks',
			'pressark_automations',
			'pressark_cost_ledger', // Operational telemetry — erased, not exported.
		);

		foreach ( $tables as $short ) {
			$table = $wpdb->prefix . $short;
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			// Count remaining rows to know if we need another pass.
			$remaining = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$uid
			) );

			if ( $remaining <= 0 ) {
				continue;
			}

			// Batched delete to avoid long table locks.
			$deleted = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
				$uid,
				$batch
			) );
			$items_removed += $deleted;

			if ( $remaining > $batch ) {
				$has_more = true;
			}
		}

		// Per-user options (usage counters, token tracking, token status cache).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'pressark_usage_' . $uid . '_' ) . '%',
			$wpdb->esc_like( 'pressark_tokens_' . $uid . '_' ) . '%',
			$wpdb->esc_like( 'pressark_last_token_status_' . $uid ) . '%'
		) );

		// Per-user transients.
		delete_transient( 'pressark_token_status_' . $uid );
		delete_transient( 'pressark_license_cache_' . $uid );

		// User meta — all pressark keys (wildcard covers current and future keys).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$uid,
			$wpdb->esc_like( 'pressark_' ) . '%'
		) );

		$messages = array();
		if ( $has_more ) {
			$messages[] = __( 'PressArk is still processing data removal. This may take multiple passes for large datasets.', 'pressark' );
		}

		return array(
			'items_removed'  => $items_removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => ! $has_more,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Check if a table exists (cached per request).
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		return $cache[ $table ];
	}

	/**
	 * Convert a chat messages array into a plain-text transcript for export.
	 * Each message is formatted as "role: content" on its own line.
	 */
	private function summarize_messages( ?array $messages ): string {
		if ( empty( $messages ) ) {
			return '';
		}

		$lines = array();
		foreach ( $messages as $msg ) {
			$role    = $msg['role'] ?? 'unknown';
			$content = $msg['content'] ?? '';

			if ( is_array( $content ) ) {
				// Multi-part content (e.g. tool calls) — flatten to text.
				$parts = array();
				foreach ( $content as $part ) {
					if ( is_string( $part ) ) {
						$parts[] = $part;
					} elseif ( isset( $part['text'] ) ) {
						$parts[] = $part['text'];
					}
				}
				$content = implode( "\n", $parts );
			}

			$lines[] = $role . ': ' . wp_strip_all_tags( (string) $content );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Normalize structured values for privacy exports without truncation.
	 *
	 * @param mixed $value Raw value from the database.
	 * @return string
	 */
	private function format_export_blob( $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) {
				return $this->encode_export_json( $decoded );
			}

			return $value;
		}

		return $this->encode_export_json( $value );
	}

	/**
	 * Pretty-print JSON for personal data exports.
	 *
	 * @param mixed $value Structured value.
	 * @return string
	 */
	private function encode_export_json( $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}
}
