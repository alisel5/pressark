<?php
/**
 * PressArk Activity - Durable inbox for async jobs, runs, and history.
 *
 * Provides a persistent wp-admin page where users can:
 * - See async task results even if the browser tab was closed
 * - Review run history with status, timing, and error summaries
 * - View result details for completed tasks
 * - Access run/task lineage for support and debugging
 *
 * @package PressArk
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Admin_Activity {

	private const PAGE_SLUG  = 'pressark-activity';
	private const VIEW_RUNS  = 'runs';
	private const VIEW_TASKS = 'tasks';
	private const SCOPE_ALL  = 'all';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Register the Activity page.
	 */
	public function add_submenu(): void {
		if ( ! PressArk_Capabilities::current_user_can_use() ) {
			return;
		}

		$task_store = new PressArk_Task_Store();
		$unread     = $task_store->unread_count( get_current_user_id() );
		$badge      = $unread > 0 ? sprintf( ' <span class="awaiting-mod">%d</span>', $unread ) : '';
		$parent     = PressArk_Capabilities::current_user_can_manage_settings() ? 'pressark' : 'index.php';
		$menu_title = PressArk_Capabilities::current_user_can_manage_settings()
			? __( 'Activity', 'pressark' )
			: __( 'PressArk Activity', 'pressark' );

		add_submenu_page(
			$parent,
			__( 'Activity', 'pressark' ),
			$menu_title . $badge,
			'pressark_use',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue minimal inline styles on the Activity page.
	 */
	public function enqueue_styles( string $hook ): void {
		if ( ! in_array( $hook, array( 'pressark_page_pressark-activity', 'dashboard_page_pressark-activity' ), true ) ) {
			return;
		}

		wp_add_inline_style( 'common', $this->get_inline_css() );
	}

	/**
	 * Render the Activity page.
	 */
	public function render_page(): void {
		if ( ! PressArk_Capabilities::current_user_can_use() ) {
			wp_die( esc_html__( 'You are not allowed to access PressArk Activity.', 'pressark' ) );
		}

		$viewer_user_id = get_current_user_id();
		$support_mode   = $this->is_support_mode();
		$activity_user  = $support_mode ? 0 : $viewer_user_id;
		$status_filter  = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$page_num       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page       = 25;
		$offset         = ( $page_num - 1 ) * $per_page;
		$task_store     = new PressArk_Task_Store();
		$unread         = $task_store->unread_count( $activity_user );
		$requested_view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$view           = in_array( $requested_view, array( self::VIEW_RUNS, self::VIEW_TASKS ), true )
			? $requested_view
			: ( $unread > 0 ? self::VIEW_TASKS : self::VIEW_RUNS );

		$detail_task_id = sanitize_text_field( wp_unslash( $_GET['task_id'] ?? '' ) );
		if ( '' !== $detail_task_id ) {
			$this->render_task_detail( $detail_task_id, $viewer_user_id, $support_mode );
			return;
		}

		$detail_run_id = sanitize_text_field( wp_unslash( $_GET['run_id'] ?? '' ) );
		if ( '' !== $detail_run_id ) {
			$this->render_run_detail( $detail_run_id, $viewer_user_id, $support_mode );
			return;
		}

		echo '<div class="wrap pressark-activity">';
		echo '<h1>' . esc_html__( 'Activity', 'pressark' ) . '</h1>';

		if ( $this->can_support_all() ) {
			$this->render_scope_tabs( $support_mode );
		}

		if ( $unread > 0 ) {
			$label = $support_mode
				? sprintf(
					/* translators: %d: unread result count */
					_n( '%d unread async result in this inbox.', '%d unread async results in this inbox.', $unread, 'pressark' ),
					$unread
				)
				: sprintf(
					/* translators: %d: unread result count */
					_n( '%d unread async result waiting for you.', '%d unread async results waiting for you.', $unread, 'pressark' ),
					$unread
				);

			echo '<div class="notice notice-info inline"><p>' . esc_html( $label ) . '</p></div>';
		}

		echo '<ul class="subsubsub">';
		echo '<li><a href="' . esc_url( $this->activity_url( array( 'view' => self::VIEW_RUNS ), $support_mode ) ) . '"'
			. ( self::VIEW_RUNS === $view ? ' class="current"' : '' ) . '>'
			. esc_html__( 'Runs', 'pressark' ) . '</a> | </li>';
		echo '<li><a href="' . esc_url( $this->activity_url( array( 'view' => self::VIEW_TASKS ), $support_mode ) ) . '"'
			. ( self::VIEW_TASKS === $view ? ' class="current"' : '' ) . '>'
			. esc_html__( 'Async Tasks', 'pressark' ) . '</a></li>';
		echo '</ul>';
		echo '<br class="clear" />';

		if ( self::VIEW_TASKS === $view ) {
			$this->render_tasks_table( $activity_user, $status_filter, $per_page, $offset, $page_num, $support_mode );
		} else {
			$this->render_runs_table( $activity_user, $status_filter, $per_page, $offset, $page_num, $support_mode );
		}

		echo '</div>';
	}

	/**
	 * Render the runs table.
	 */
	private function render_runs_table(
		int $user_id,
		string $status_filter,
		int $per_page,
		int $offset,
		int $page_num,
		bool $support_mode
	): void {
		$run_store = new PressArk_Run_Store();
		$counts    = $run_store->status_counts( $user_id );
		$rows      = $run_store->get_user_activity( $user_id, $per_page, $offset, $status_filter );
		$total     = $run_store->count_activity( $user_id, $status_filter );

		$this->render_status_filters( $counts, $status_filter, self::VIEW_RUNS, $support_mode );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No runs found.', 'pressark' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped pressark-runs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'pressark' ) . '</th>';
		if ( $support_mode ) {
			echo '<th>' . esc_html__( 'User', 'pressark' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Route', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'IDs', 'pressark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$status_class  = $this->status_class( $row['status'] );
			$message_short = mb_substr( $row['message'], 0, 80 );
			if ( mb_strlen( $row['message'] ) > 80 ) {
				$message_short .= '...';
			}

			$duration = '';
			if ( $row['settled_at'] && $row['created_at'] ) {
				$dur_secs = strtotime( $row['settled_at'] ) - strtotime( $row['created_at'] );
				$duration = $this->format_duration( $dur_secs );
			} elseif ( 'running' === $row['status'] ) {
				$dur_secs = time() - strtotime( $row['created_at'] );
				$duration = $this->format_duration( $dur_secs ) . ' ...';
			}

			$detail_url = $this->activity_url(
				array(
					'view'   => self::VIEW_RUNS,
					'run_id' => $row['run_id'],
				),
				$support_mode
			);

			echo '<tr>';
			echo '<td><span class="pressark-status ' . esc_attr( $status_class ) . '">'
				. esc_html( $row['status'] ) . '</span></td>';
			echo '<td><a href="' . esc_url( $detail_url ) . '">'
				. esc_html( $message_short ) . '</a></td>';
			if ( $support_mode ) {
				echo '<td>' . esc_html( $this->user_summary( (int) $row['user_id'] ) ) . '</td>';
			}
			echo '<td>' . esc_html( $row['route'] ) . '</td>';
			echo '<td>' . esc_html( $this->relative_time( $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $duration ) . '</td>';
			echo '<td>' . esc_html( $row['error_summary'] ?? '' ) . '</td>';
			echo '<td class="pressark-ids">';
			echo '<code title="run_id">' . esc_html( substr( $row['run_id'], 0, 8 ) ) . '</code>';
			if ( $row['task_id'] ) {
				echo ' <code title="task_id">' . esc_html( substr( $row['task_id'], 0, 8 ) ) . '</code>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->render_pagination( $total, $per_page, $page_num );
	}

	/**
	 * Render the async tasks table.
	 */
	private function render_tasks_table(
		int $user_id,
		string $status_filter,
		int $per_page,
		int $offset,
		int $page_num,
		bool $support_mode
	): void {
		$task_store = new PressArk_Task_Store();
		$counts     = $task_store->status_counts( $user_id );
		$rows       = $task_store->get_activity( $user_id, $per_page, $offset, $status_filter );
		$total      = $task_store->count_activity( $user_id, $status_filter );

		$this->render_status_filters( $counts, $status_filter, self::VIEW_TASKS, $support_mode );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No async tasks found.', 'pressark' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped pressark-tasks-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'pressark' ) . '</th>';
		if ( $support_mode ) {
			echo '<th>' . esc_html__( 'User', 'pressark' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Retries', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Completed', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Read', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'ID', 'pressark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$status_class  = $this->status_class( $row['status'] );
			$message_short = mb_substr( $row['message'], 0, 80 );
			if ( mb_strlen( $row['message'] ) > 80 ) {
				$message_short .= '...';
			}

			$has_result = in_array( $row['status'], array( 'complete', 'delivered', 'undelivered' ), true );
			$detail_url = $has_result
				? $this->activity_url(
					array(
						'view'    => self::VIEW_TASKS,
						'task_id' => $row['task_id'],
					),
					$support_mode
				)
				: '';

			$unread = $has_result && empty( $row['read_at'] );

			echo '<tr' . ( $unread ? ' class="pressark-unread"' : '' ) . '>';
			echo '<td><span class="pressark-status ' . esc_attr( $status_class ) . '">'
				. esc_html( $row['status'] ) . '</span></td>';
			echo '<td>';
			if ( $detail_url ) {
				echo '<a href="' . esc_url( $detail_url ) . '">' . esc_html( $message_short ) . '</a>';
			} else {
				echo esc_html( $message_short );
			}
			echo '</td>';
			if ( $support_mode ) {
				echo '<td>' . esc_html( $this->user_summary( (int) $row['user_id'] ) ) . '</td>';
			}
			echo '<td>' . esc_html( $row['retries'] . '/' . $row['max_retries'] ) . '</td>';
			echo '<td>' . esc_html( $this->relative_time( $row['created_at'] ) ) . '</td>';
			echo '<td>' . ( $row['completed_at'] ? esc_html( $this->relative_time( $row['completed_at'] ) ) : '-' ) . '</td>';
			echo '<td>' . ( $row['read_at'] ? esc_html( $this->relative_time( $row['read_at'] ) ) : '-' ) . '</td>';
			echo '<td>' . esc_html( mb_substr( $row['fail_reason'] ?? '', 0, 60 ) ) . '</td>';
			echo '<td class="pressark-ids"><code>' . esc_html( substr( $row['task_id'], 0, 8 ) ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$this->render_pagination( $total, $per_page, $page_num );
	}

	/**
	 * Render task detail view.
	 */
	private function render_task_detail( string $task_id, int $viewer_user_id, bool $support_mode ): void {
		$task_store = new PressArk_Task_Store();
		$task       = $task_store->get_result_for_user( $task_id, $viewer_user_id, $support_mode );
		$back_url   = $this->activity_url( array( 'view' => self::VIEW_TASKS ), $support_mode );

		echo '<div class="wrap pressark-activity">';
		echo '<h1><a href="' . esc_url( $back_url ) . '">&larr; '
			. esc_html__( 'Activity', 'pressark' ) . '</a></h1>';

		if ( ! $task ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Task not found or access denied.', 'pressark' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="pressark-detail-card">';
		echo '<h2>' . esc_html__( 'Async Task Detail', 'pressark' ) . '</h2>';
		echo '<table class="form-table">';
		$this->detail_row( __( 'Task ID', 'pressark' ), $task['task_id'] );
		if ( $support_mode ) {
			$this->detail_row( __( 'User', 'pressark' ), $this->user_summary( (int) $task['user_id'] ) );
		}
		$this->detail_row( __( 'Status', 'pressark' ), $task['status'] );
		$this->detail_row( __( 'Message', 'pressark' ), $task['message'] );
		$this->detail_row( __( 'Created', 'pressark' ), $task['created_at'] );
		$this->detail_row( __( 'Started', 'pressark' ), $task['started_at'] ?? '-' );
		$this->detail_row( __( 'Completed', 'pressark' ), $task['completed_at'] ?? '-' );
		$this->detail_row( __( 'Read', 'pressark' ), $task['read_at'] ?? '-' );
		$this->detail_row( __( 'Retries', 'pressark' ), $task['retries'] . '/' . $task['max_retries'] );

		if ( ! empty( $task['fail_reason'] ) ) {
			$this->detail_row( __( 'Failure Reason', 'pressark' ), $task['fail_reason'] );
		}

		echo '</table>';

		if ( ! empty( $task['result'] ) && is_array( $task['result'] ) ) {
			echo '<h3>' . esc_html__( 'Result', 'pressark' ) . '</h3>';

			$result         = $task['result'];
			$result_message = $result['message'] ?? '';

			if ( $result_message ) {
				echo '<div class="pressark-result-message">'
					. wp_kses_post( wpautop( $result_message ) )
					. '</div>';
			}

			if ( ! empty( $result['actions_applied'] ) ) {
				echo '<h4>' . esc_html__( 'Actions Applied', 'pressark' ) . '</h4>';
				echo '<ul class="pressark-actions-list">';
				foreach ( $result['actions_applied'] as $action ) {
					$desc = is_array( $action ) ? ( $action['description'] ?? wp_json_encode( $action ) ) : (string) $action;
					echo '<li>' . esc_html( $desc ) . '</li>';
				}
				echo '</ul>';
			}
		}

		$receipts = $task_store->get_receipts( $task_id );
		if ( ! empty( $receipts ) ) {
			echo '<h3>' . esc_html__( 'Operation Receipts', 'pressark' ) . '</h3>';
			echo '<table class="widefat fixed striped">';
			echo '<thead><tr><th>' . esc_html__( 'Operation', 'pressark' ) . '</th><th>' . esc_html__( 'Timestamp', 'pressark' ) . '</th><th>' . esc_html__( 'Summary', 'pressark' ) . '</th></tr></thead><tbody>';
			foreach ( $receipts as $key => $receipt ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $key ) . '</code></td>';
				echo '<td>' . esc_html( $receipt['ts'] ?? '' ) . '</td>';
				echo '<td>' . esc_html( $receipt['summary'] ?? '' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div></div>';
	}

	/**
	 * Render run detail view.
	 */
	private function render_run_detail( string $run_id, int $viewer_user_id, bool $support_mode ): void {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );
		$back_url  = $this->activity_url( array( 'view' => self::VIEW_RUNS ), $support_mode );

		echo '<div class="wrap pressark-activity">';
		echo '<h1><a href="' . esc_url( $back_url ) . '">&larr; '
			. esc_html__( 'Activity', 'pressark' ) . '</a></h1>';

		if ( ! $run || ( ! $support_mode && (int) $run['user_id'] !== $viewer_user_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Run not found or access denied.', 'pressark' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="pressark-detail-card">';
		echo '<h2>' . esc_html__( 'Run Detail', 'pressark' ) . '</h2>';
		echo '<table class="form-table">';
		$this->detail_row( __( 'Run ID', 'pressark' ), $run['run_id'] );
		if ( $support_mode ) {
			$this->detail_row( __( 'User', 'pressark' ), $this->user_summary( (int) $run['user_id'] ) );
		}

		if ( ! empty( $run['task_id'] ) ) {
			$task_url = $this->activity_url(
				array(
					'view'    => self::VIEW_TASKS,
					'task_id' => $run['task_id'],
				),
				$support_mode
			);
			$this->detail_row_html(
				__( 'Linked Task', 'pressark' ),
				'<a href="' . esc_url( $task_url ) . '"><code>' . esc_html( $run['task_id'] ) . '</code></a>'
			);
		}

		$this->detail_row( __( 'Status', 'pressark' ), $run['status'] );
		$this->detail_row( __( 'Route', 'pressark' ), $run['route'] );
		$this->detail_row( __( 'Tier', 'pressark' ), $run['tier'] );
		$this->detail_row( __( 'Message', 'pressark' ), $run['message'] );
		$this->detail_row( __( 'Created', 'pressark' ), $run['created_at'] );
		$this->detail_row( __( 'Updated', 'pressark' ), $run['updated_at'] );
		$this->detail_row( __( 'Settled', 'pressark' ), $run['settled_at'] ?? '-' );

		if ( $run['chat_id'] > 0 ) {
			$this->detail_row( __( 'Chat ID', 'pressark' ), (string) $run['chat_id'] );
		}
		if ( ! empty( $run['workflow_class'] ) ) {
			$this->detail_row( __( 'Workflow', 'pressark' ), $run['workflow_class'] );
		}
		if ( ! empty( $run['reservation_id'] ) ) {
			$this->detail_row( __( 'Reservation', 'pressark' ), $run['reservation_id'] );
		}

		if ( 'failed' === $run['status'] ) {
			$fail_reason = '';
			if ( is_array( $run['result'] ) && isset( $run['result']['fail_reason'] ) ) {
				$fail_reason = $run['result']['fail_reason'];
			}
			if ( $fail_reason ) {
				$this->detail_row( __( 'Failure Reason', 'pressark' ), $fail_reason );
			}
		}

		echo '</table>';

		if ( ! empty( $run['pending_actions'] ) && is_array( $run['pending_actions'] ) ) {
			echo '<h3>' . esc_html__( 'Pending Actions', 'pressark' ) . '</h3>';
			echo '<pre class="pressark-json">'
				. esc_html( wp_json_encode( $run['pending_actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
				. '</pre>';
		}

		echo '</div></div>';
	}

	private function can_support_all(): bool {
		return PressArk_Capabilities::current_user_can_manage_settings();
	}

	private function is_support_mode(): bool {
		return $this->can_support_all() && self::SCOPE_ALL === sanitize_key( wp_unslash( $_GET['scope'] ?? '' ) );
	}

	private function activity_url( array $args = array(), bool $support_mode = false ): string {
		$base_args = array( 'page' => self::PAGE_SLUG );

		if ( $support_mode ) {
			$base_args['scope'] = self::SCOPE_ALL;
		}

		return add_query_arg( array_merge( $base_args, $args ), admin_url( 'admin.php' ) );
	}

	private function render_scope_tabs( bool $support_mode ): void {
		echo '<ul class="subsubsub pressark-scope-tabs">';
		echo '<li><a href="' . esc_url( $this->activity_url() ) . '"'
			. ( ! $support_mode ? ' class="current"' : '' ) . '>'
			. esc_html__( 'My Activity', 'pressark' ) . '</a> | </li>';
		echo '<li><a href="' . esc_url( $this->activity_url( array( 'scope' => self::SCOPE_ALL ) , true ) ) . '"'
			. ( $support_mode ? ' class="current"' : '' ) . '>'
			. esc_html__( 'All Users (Support)', 'pressark' ) . '</a></li>';
		echo '</ul>';
		echo '<br class="clear" />';
	}

	private function detail_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th>'
			. '<td>' . esc_html( $value ) . '</td></tr>';
	}

	private function detail_row_html( string $label, string $html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th>'
			. '<td>' . $html . '</td></tr>';
	}

	private function render_status_filters( array $counts, string $active, string $view, bool $support_mode ): void {
		$base_url = $this->activity_url( array( 'view' => $view ), $support_mode );
		$total    = array_sum( $counts );

		echo '<ul class="subsubsub" style="margin-top:8px;clear:both;">';
		echo '<li><a href="' . esc_url( $base_url ) . '"'
			. ( '' === $active ? ' class="current"' : '' ) . '>'
			. esc_html__( 'All', 'pressark' ) . ' <span class="count">(' . number_format_i18n( $total ) . ')</span></a></li>';

		foreach ( $counts as $status => $count ) {
			echo ' | <li><a href="' . esc_url( add_query_arg( 'status', $status, $base_url ) ) . '"'
				. ( $status === $active ? ' class="current"' : '' ) . '>'
				. esc_html( $status ) . ' <span class="count">(' . number_format_i18n( $count ) . ')</span></a></li>';
		}

		echo '</ul>';
		echo '<br class="clear" />';
	}

	private function render_pagination( int $total, int $per_page, int $current_page ): void {
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo '<span class="displaying-num">'
			. sprintf( esc_html__( '%s items', 'pressark' ), number_format_i18n( $total ) )
			. '</span>';

		$page_links = paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'total'     => $total_pages,
			'current'   => $current_page,
		) );

		if ( $page_links ) {
			echo '<span class="pagination-links">' . wp_kses_post( $page_links ) . '</span>';
		}

		echo '</div></div>';
	}

	private function status_class( string $status ): string {
		return match ( $status ) {
			'settled'                               => 'pressark-status--settled',
			'complete', 'delivered', 'undelivered' => 'pressark-status--complete',
			'running', 'queued'                    => 'pressark-status--running',
			'failed', 'dead_letter'                => 'pressark-status--failed',
			'awaiting_preview', 'awaiting_confirm' => 'pressark-status--awaiting',
			default                                => '',
		};
	}

	private function relative_time( string $datetime ): string {
		if ( ! $datetime || '-' === $datetime ) {
			return '-';
		}

		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return $datetime;
		}

		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s ago', 'pressark' ), human_time_diff( $timestamp, time() ) );
	}

	private function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}
		if ( $seconds < 3600 ) {
			return floor( $seconds / 60 ) . 'm ' . ( $seconds % 60 ) . 's';
		}
		return floor( $seconds / 3600 ) . 'h ' . floor( ( $seconds % 3600 ) / 60 ) . 'm';
	}

	private function user_summary( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return '#' . $user_id;
		}

		$label = $user->display_name ?: $user->user_login;
		if ( ! empty( $user->user_email ) ) {
			$label .= ' (' . $user->user_email . ')';
		}

		return $label;
	}

	private function get_inline_css(): string {
		return '
		.pressark-scope-tabs { margin-bottom: 6px; }
		.pressark-status {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 500;
			line-height: 1.4;
		}
		.pressark-status--settled { background: #d4edda; color: #155724; }
		.pressark-status--complete { background: #cce5ff; color: #004085; }
		.pressark-status--running { background: #fff3cd; color: #856404; }
		.pressark-status--failed { background: #f8d7da; color: #721c24; }
		.pressark-status--awaiting { background: #e2e3e5; color: #383d41; }
		.pressark-ids code {
			font-size: 11px;
			background: #f0f0f1;
			padding: 1px 4px;
			border-radius: 2px;
		}
		.pressark-unread td { font-weight: 600; }
		.pressark-detail-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 16px 20px;
			margin-top: 16px;
		}
		.pressark-detail-card .form-table th { width: 150px; }
		.pressark-result-message {
			background: #f6f7f7;
			border-left: 4px solid #2271b1;
			padding: 12px 16px;
			margin: 8px 0;
		}
		.pressark-json {
			background: #f0f0f1;
			padding: 12px;
			overflow-x: auto;
			max-height: 300px;
			font-size: 12px;
		}
		.pressark-actions-list li {
			padding: 2px 0;
		}
		.pressark-runs-table .column-status,
		.pressark-tasks-table .column-status { width: 100px; }
		';
	}
}
