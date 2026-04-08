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
	private const VIEW_POLICY = 'policy';
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
		$search_query   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$page_num       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page       = 25;
		$offset         = ( $page_num - 1 ) * $per_page;
		$task_store     = new PressArk_Task_Store();
		$unread         = $task_store->unread_count( $activity_user );
		$requested_view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );
		$allowed_views  = array( self::VIEW_RUNS, self::VIEW_TASKS );
		if ( $this->can_view_policy_diagnostics() ) {
			$allowed_views[] = self::VIEW_POLICY;
		}
		$view           = in_array( $requested_view, $allowed_views, true )
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
		if ( $this->can_view_policy_diagnostics() ) {
			echo ' | <li><a href="' . esc_url( $this->activity_url( array( 'view' => self::VIEW_POLICY ), $support_mode ) ) . '"'
				. ( self::VIEW_POLICY === $view ? ' class="current"' : '' ) . '>'
				. esc_html__( 'Policy Diagnostics', 'pressark' ) . '</a></li>';
		}
		echo '</ul>';
		echo '<br class="clear" />';

		if ( self::VIEW_POLICY !== $view ) {
			$this->render_operational_search_form( $view, $support_mode, $search_query );
		}

		if ( self::VIEW_POLICY === $view && $this->can_view_policy_diagnostics() ) {
			$this->render_policy_diagnostics();
		} elseif ( '' !== $search_query ) {
			$this->render_operational_search_results( $search_query, $activity_user, $support_mode );
		} elseif ( self::VIEW_TASKS === $view ) {
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

		$progress_snapshots = $task_store->get_progress_snapshots(
			array_map(
				static function ( array $row ): string {
					return (string) ( $row['task_id'] ?? '' );
				},
				$rows
			)
		);

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
			$progress      = is_array( $progress_snapshots[ $row['task_id'] ] ?? null ) ? $progress_snapshots[ $row['task_id'] ] : array();
			if ( mb_strlen( $row['message'] ) > 80 ) {
				$message_short .= '...';
			}

			$detail_url = $this->activity_url(
				array(
					'view'    => self::VIEW_TASKS,
					'task_id' => $row['task_id'],
				),
				$support_mode
			);

			$unread = in_array( $row['status'], array( 'complete', 'delivered', 'undelivered' ), true ) && empty( $row['read_at'] );

			echo '<tr' . ( $unread ? ' class="pressark-unread"' : '' ) . '>';
			echo '<td><span class="pressark-status ' . esc_attr( $status_class ) . '">'
				. esc_html( $row['status'] ) . '</span></td>';
			echo '<td>';
			echo '<a href="' . esc_url( $detail_url ) . '">' . esc_html( $message_short ) . '</a>';
			if ( ! empty( $progress['headline'] ) ) {
				echo '<div class="pressark-progress-headline pressark-progress-headline--compact">'
					. esc_html( (string) $progress['headline'] ) . '</div>';
			}
			if ( ! empty( $progress['summary'] ) ) {
				echo '<div class="pressark-progress-meta">' . esc_html( (string) $progress['summary'] ) . '</div>';
			}
			if ( ! empty( $progress['milestone_summary'] ) ) {
				echo '<div class="pressark-progress-milestone">' . esc_html( (string) $progress['milestone_summary'] ) . '</div>';
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
	 * Render the operator-facing cross-run search form.
	 */
	private function render_operational_search_form( string $view, bool $support_mode, string $query ): void {
		echo '<div class="pressark-operational-search-card">';
		echo '<form class="pressark-operational-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
		if ( $support_mode ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( self::SCOPE_ALL ) . '" />';
		}
		echo '<label class="screen-reader-text" for="pressark-operational-search-input">' . esc_html__( 'Search operational history', 'pressark' ) . '</label>';
		echo '<input id="pressark-operational-search-input" class="regular-text" type="search" name="q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'Search page, product, template, fallback, approval, receipt...', 'pressark' ) . '" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Search History', 'pressark' ) . '</button>';
		if ( '' !== $query ) {
			echo '<a class="button button-secondary" href="' . esc_url( $this->activity_url( array( 'view' => $view ), $support_mode ) ) . '">' . esc_html__( 'Clear', 'pressark' ) . '</a>';
		}
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Searches recent runs, tasks, traces, receipts, and site notes for support, debug, and resume workflows.', 'pressark' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render cross-run operational search results.
	 */
	private function render_operational_search_results( string $query, int $user_id, bool $support_mode ): void {
		$search = ( new PressArk_Operational_Search() )->search(
			array(
				'query'   => $query,
				'user_id' => $user_id,
				'limit'   => 24,
			)
		);

		echo '<div class="pressark-detail-card pressark-operational-results-card">';
		echo '<h2>' . esc_html__( 'Operational Search', 'pressark' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Operator-first recall across durable runs, tasks, traces, receipts, and site notes. Results are shown for debugging and resume work, not for automatic prompt stuffing.', 'pressark' ) . '</p>';
		echo '<p class="pressark-operational-query"><strong>' . esc_html__( 'Query:', 'pressark' ) . '</strong> ' . esc_html( $query ) . '</p>';

		if ( ! empty( $search['counts'] ) ) {
			echo '<div class="pressark-operational-chip-row">';
			foreach ( $search['counts'] as $kind => $count ) {
				echo '<span class="pressark-operational-chip"><strong>' . esc_html( $this->operational_result_kind_label( (string) $kind ) ) . '</strong> ' . esc_html( number_format_i18n( (int) $count ) ) . '</span>';
			}
			echo '</div>';
		}

		if ( ! empty( $search['signals'] ) ) {
			echo '<div class="pressark-operational-chip-row">';
			foreach ( $search['signals'] as $signal => $count ) {
				echo '<span class="pressark-operational-chip pressark-operational-chip--signal"><strong>' . esc_html( $this->operational_signal_label( (string) $signal ) ) . '</strong> ' . esc_html( number_format_i18n( (int) $count ) ) . '</span>';
			}
			echo '</div>';
		}

		if ( empty( $search['results'] ) ) {
			echo '<p>' . esc_html__( 'No matching operational history found in the recent scan window.', 'pressark' ) . '</p>';
			echo '</div>';
			return;
		}

		$this->render_operational_results_table( (array) $search['results'], $support_mode, true );
		echo '</div>';
	}

	/**
	 * Render related operational history for one detail page.
	 *
	 * @param array<string,mixed> $search Search payload from PressArk_Operational_Search.
	 */
	private function render_related_operational_history( array $search, bool $support_mode ): void {
		$results = is_array( $search['results'] ?? null ) ? (array) $search['results'] : array();
		if ( empty( $results ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Related Operational History', 'pressark' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Nearby history surfaced from shared targets, receipts, approvals, fallbacks, and failure reasons.', 'pressark' ) . '</p>';
		$this->render_operational_results_table( $results, $support_mode, true );
	}

	/**
	 * Render a table of operational search results.
	 *
	 * @param array<int,array<string,mixed>> $results Search results.
	 */
	private function render_operational_results_table( array $results, bool $support_mode, bool $show_matched_on ): void {
		echo '<table class="wp-list-table widefat striped pressark-operational-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Type', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'pressark' ) . '</th>';
		if ( $support_mode ) {
			echo '<th>' . esc_html__( 'User', 'pressark' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Summary', 'pressark' ) . '</th>';
		if ( $show_matched_on ) {
			echo '<th>' . esc_html__( 'Matched On', 'pressark' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Signals', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'pressark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $results as $result ) {
			$url       = $this->operational_result_url( $result, $support_mode );
			$title     = sanitize_text_field( (string) ( $result['title'] ?? '' ) );
			$summary   = sanitize_text_field( (string) ( $result['summary'] ?? '' ) );
			$created_at = sanitize_text_field( (string) ( $result['created_at'] ?? '' ) );
			$meta_bits = array();

			if ( ! empty( $result['run_id'] ) ) {
				$meta_bits[] = '<code title="run_id">' . esc_html__( 'Run', 'pressark' ) . ' ' . esc_html( $this->short_identifier( (string) $result['run_id'] ) ) . '</code>';
			}
			if ( ! empty( $result['task_id'] ) ) {
				$meta_bits[] = '<code title="task_id">' . esc_html__( 'Task', 'pressark' ) . ' ' . esc_html( $this->short_identifier( (string) $result['task_id'] ) ) . '</code>';
			}

			echo '<tr>';
			echo '<td><span class="pressark-operational-kind">' . esc_html( $this->operational_result_kind_label( (string) ( $result['kind'] ?? '' ) ) ) . '</span></td>';
			echo '<td>';
			if ( '' !== $url ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title ?: __( 'Operational result', 'pressark' ) );
			}
			if ( ! empty( $meta_bits ) ) {
				echo '<div class="pressark-ids pressark-operational-meta">' . wp_kses_post( implode( ' ', $meta_bits ) ) . '</div>';
			}
			echo '</td>';
			if ( $support_mode ) {
				$user_id = (int) ( $result['user_id'] ?? 0 );
				echo '<td>' . esc_html( $user_id > 0 ? $this->user_summary( $user_id ) : '-' ) . '</td>';
			}
			echo '<td>' . esc_html( '' !== $summary ? $summary : '-' ) . '</td>';
			if ( $show_matched_on ) {
				$matched_on = array_values(
					array_filter(
						array_map(
							static function ( $label ): string {
								return sanitize_text_field( (string) $label );
							},
							(array) ( $result['matched_on'] ?? array() )
						)
					)
				);
				echo '<td>' . esc_html( ! empty( $matched_on ) ? implode( ', ', $matched_on ) : '-' ) . '</td>';
			}
			echo '<td>';
			$signals = array_values(
				array_filter(
					array_map(
						function ( $signal ): string {
							return $this->operational_signal_label( (string) $signal );
						},
						(array) ( $result['signals'] ?? array() )
					)
				)
			);
			if ( empty( $signals ) ) {
				echo '-';
			} else {
				foreach ( $signals as $signal ) {
					echo '<span class="pressark-operational-chip pressark-operational-chip--signal">' . esc_html( $signal ) . '</span> ';
				}
			}
			echo '</td>';
			echo '<td title="' . esc_attr( $created_at ) . '">' . esc_html( $created_at ? $this->relative_time( $created_at ) : '-' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render task detail view.
	 */
	private function render_task_detail( string $task_id, int $viewer_user_id, bool $support_mode ): void {
		$task_store = new PressArk_Task_Store();
		$task       = $task_store->get_result_for_user( $task_id, $viewer_user_id, $support_mode );
		$run_store  = new PressArk_Run_Store();
		$event_store = new PressArk_Activity_Event_Store();
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
		if ( ! empty( $task['run_id'] ) ) {
			$this->detail_row_html(
				__( 'Worker Run', 'pressark' ),
				$this->run_link_html( (string) $task['run_id'], $support_mode )
			);
		}
		if ( ! empty( $task['parent_run_id'] ) ) {
			$this->detail_row_html(
				__( 'Parent Handoff', 'pressark' ),
				$this->run_link_html( (string) $task['parent_run_id'], $support_mode )
			);
		}
		if ( ! empty( $task['root_run_id'] ) ) {
			$this->detail_row_html(
				__( 'Root Run', 'pressark' ),
				$this->run_link_html( (string) $task['root_run_id'], $support_mode )
			);
		}

		if ( ! empty( $task['fail_reason'] ) ) {
			$this->detail_row( __( 'Failure Reason', 'pressark' ), $task['fail_reason'] );
		}

		echo '</table>';

		if ( ! empty( $task['progress'] ) && is_array( $task['progress'] ) ) {
			$this->render_task_progress_card( $task['progress'] );
		}

		if ( ! empty( $task['handoff_capsule'] ) && is_array( $task['handoff_capsule'] ) ) {
			$this->render_json_block( __( 'Handoff Capsule', 'pressark' ), $task['handoff_capsule'] );
		}

		$family_root_id = (string) ( $task['root_run_id'] ?: $task['parent_run_id'] ?: $task['run_id'] );
		$family         = '' !== $family_root_id ? $run_store->get_family( $family_root_id, 20 ) : array();
		if ( count( $family ) > 1 ) {
			echo '<h3>' . esc_html__( 'Related Runs', 'pressark' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Queue-native handoff and worker runs that share this lineage root.', 'pressark' ) . '</p>';
			$this->render_lineage_family( $family, $support_mode );
		}

		$related_history = ( new PressArk_Operational_Search() )->related_for_task(
			$task,
			$support_mode ? 0 : $viewer_user_id
		);
		$this->render_related_operational_history( $related_history, $support_mode );

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

		$worker_events = $event_store->get_by_task( $task_id, 120 );
		if ( ! empty( $worker_events ) ) {
			echo '<h3>' . esc_html__( 'Task Timeline', 'pressark' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Queue handoff, worker lifecycle, approval pauses, retries, and task-linked execution events for this task.', 'pressark' ) . '</p>';
			$this->render_trace_table( $worker_events );
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
		if ( ! empty( $run['correlation_id'] ) ) {
			$this->detail_row( __( 'Correlation ID', 'pressark' ), $run['correlation_id'] );
		}
		if ( ! empty( $run['parent_run_id'] ) ) {
			$this->detail_row_html(
				__( 'Parent Handoff', 'pressark' ),
				$this->run_link_html( (string) $run['parent_run_id'], $support_mode )
			);
		}
		if ( ! empty( $run['root_run_id'] ) ) {
			$this->detail_row_html(
				__( 'Root Run', 'pressark' ),
				$this->run_link_html( (string) $run['root_run_id'], $support_mode )
			);
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

		$local_trace  = PressArk_Activity_Trace::get_local_trace( $run, 80 );
		$remote_trace = PressArk_Activity_Trace::fetch_bank_trace( $run, 80 );
		$joined_trace = PressArk_Activity_Trace::merge_traces( $local_trace, $remote_trace );
		if ( class_exists( 'PressArk_Run_Trust_Surface' ) ) {
			$trust_surface = PressArk_Run_Trust_Surface::build( $run, $joined_trace );
			$this->render_trust_surface( $trust_surface );
		}

		$result_budget = is_array( $run['result'] ?? null ) && is_array( $run['result']['budget'] ?? null )
			? (array) $run['result']['budget']
			: array();
		$billing_state = is_array( $result_budget['billing_state'] ?? null ) ? (array) $result_budget['billing_state'] : array();
		$settlement_delta = is_array( $result_budget['settlement_delta'] ?? null ) ? (array) $result_budget['settlement_delta'] : array();

		if ( ! empty( $billing_state ) || ! empty( $settlement_delta ) ) {
			echo '<h3>' . esc_html__( 'Billing Detail', 'pressark' ) . '</h3>';
			echo '<table class="form-table">';

			if ( ! empty( $billing_state ) ) {
				$this->detail_row( __( 'Authority', 'pressark' ), (string) ( $billing_state['authority_label'] ?? $billing_state['authority_mode'] ?? '' ) );
				$this->detail_row( __( 'Service State', 'pressark' ), (string) ( $billing_state['service_label'] ?? $billing_state['service_state'] ?? '' ) );
				$this->detail_row( __( 'Spend Source', 'pressark' ), (string) ( $billing_state['spend_label'] ?? $billing_state['spend_source'] ?? '' ) );
				$this->detail_row( __( 'Estimate Mode', 'pressark' ), (string) ( $billing_state['estimate_mode'] ?? '' ) );

				if ( ! empty( $billing_state['authority_notice'] ) ) {
					$this->detail_row( __( 'Authority Note', 'pressark' ), (string) $billing_state['authority_notice'] );
				}
				if ( ! empty( $billing_state['service_notice'] ) ) {
					$this->detail_row( __( 'Service Note', 'pressark' ), (string) $billing_state['service_notice'] );
				}
				if ( ! empty( $billing_state['estimate_notice'] ) ) {
					$this->detail_row( __( 'Estimate Note', 'pressark' ), (string) $billing_state['estimate_notice'] );
				}
			}

			if ( ! empty( $settlement_delta ) ) {
				$delta_icus = (int) ( $settlement_delta['delta_icus'] ?? 0 );
				$delta_prefix = $delta_icus > 0 ? '+' : '';
				$this->detail_row( __( 'Estimate Authority', 'pressark' ), (string) ( $settlement_delta['estimate_authority'] ?? '' ) );
				$this->detail_row( __( 'Settlement Authority', 'pressark' ), (string) ( $settlement_delta['settlement_authority'] ?? '' ) );
				$this->detail_row(
					__( 'Estimated ICUs', 'pressark' ),
					number_format_i18n( (int) ( $settlement_delta['estimated_icus'] ?? 0 ) )
				);
				$this->detail_row(
					__( 'Settled ICUs', 'pressark' ),
					number_format_i18n( (int) ( $settlement_delta['settled_icus'] ?? 0 ) )
				);
				$this->detail_row(
					__( 'Settlement Delta', 'pressark' ),
					$delta_prefix . number_format_i18n( $delta_icus ) . ' ICUs'
				);
				$this->detail_row(
					__( 'Estimated Raw Tokens', 'pressark' ),
					number_format_i18n( (int) ( $settlement_delta['estimated_raw_tokens'] ?? 0 ) )
				);
				$this->detail_row(
					__( 'Actual Raw Tokens', 'pressark' ),
					number_format_i18n( (int) ( $settlement_delta['actual_raw_tokens'] ?? 0 ) )
				);
				if ( ! empty( $settlement_delta['summary'] ) ) {
					$this->detail_row( __( 'Settlement Summary', 'pressark' ), (string) $settlement_delta['summary'] );
				}
			}

			echo '</table>';
		}

		if ( ! empty( $run['pending_actions'] ) && is_array( $run['pending_actions'] ) ) {
			echo '<h3>' . esc_html__( 'Pending Actions', 'pressark' ) . '</h3>';
			echo '<pre class="pressark-json">'
				. esc_html( wp_json_encode( $run['pending_actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
				. '</pre>';
		}

		$permission_surface = array();
		if ( is_array( $run['result'] ?? null ) && ! empty( $run['result']['permission_surface'] ) ) {
			$permission_surface = (array) $run['result']['permission_surface'];
		} elseif ( is_array( $run['workflow_state'] ?? null ) && ! empty( $run['workflow_state']['permission_surface'] ) ) {
			$permission_surface = (array) $run['workflow_state']['permission_surface'];
		}

		$context_inspector = array();
		if ( is_array( $run['result'] ?? null ) && ! empty( $run['result']['context_inspector'] ) ) {
			$context_inspector = (array) $run['result']['context_inspector'];
		}

		if ( ! empty( $context_inspector ) ) {
			$this->render_context_inspector( $context_inspector );
		} elseif ( ! empty( $permission_surface ) ) {
			echo '<h3>' . esc_html__( 'Permission Surface', 'pressark' ) . '</h3>';
			echo '<pre class="pressark-json">'
				. esc_html( wp_json_encode( $permission_surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
				. '</pre>';
		}

		if ( empty( $context_inspector ) && is_array( $run['result'] ?? null ) && ! empty( $run['result']['routing_decision'] ) ) {
			$this->render_json_block( __( 'Routing Decision', 'pressark' ), (array) $run['result']['routing_decision'] );
		}

		if ( ! empty( $run['handoff_capsule'] ) && is_array( $run['handoff_capsule'] ) ) {
			$this->render_json_block( __( 'Handoff Capsule', 'pressark' ), $run['handoff_capsule'] );
		}

		$family_root_id = (string) ( $run['root_run_id'] ?: $run['run_id'] );
		$family         = '' !== $family_root_id ? $run_store->get_family( $family_root_id, 20 ) : array();
		if ( count( $family ) > 1 ) {
			echo '<h3>' . esc_html__( 'Related Runs', 'pressark' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Sibling and child runs grouped under the same queue-native lineage root.', 'pressark' ) . '</p>';
			$this->render_lineage_family( $family, $support_mode );
		}

		$related_history = ( new PressArk_Operational_Search() )->related_for_run(
			$run,
			$support_mode ? 0 : $viewer_user_id
		);
		$this->render_related_operational_history( $related_history, $support_mode );

		if ( ! empty( $joined_trace ) ) {
			echo '<h3>' . esc_html__( 'Joined Trace', 'pressark' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Merged plugin and token-bank activity for this correlation spine.', 'pressark' ) . '</p>';
			$this->render_trace_table( $joined_trace );
		}

		echo '</div></div>';
	}

	/**
	 * Render the operator-facing Context Inspector surface.
	 *
	 * @param array<string,mixed> $inspector Normalized inspector payload.
	 */
	private function render_context_inspector( array $inspector ): void {
		$prompt           = is_array( $inspector['prompt'] ?? null ) ? $inspector['prompt'] : array();
		$tool_surface     = is_array( $inspector['tool_surface'] ?? null ) ? $inspector['tool_surface'] : array();
		$reads            = is_array( $inspector['reads'] ?? null ) ? $inspector['reads'] : array();
		$replay           = is_array( $inspector['replay'] ?? null ) ? $inspector['replay'] : array();
		$messages         = is_array( $inspector['messages'] ?? null ) ? $inspector['messages'] : array();
		$replacements     = is_array( $inspector['replacements'] ?? null ) ? $inspector['replacements'] : array();
		$token_footprint  = is_array( $inspector['token_footprint'] ?? null ) ? $inspector['token_footprint'] : array();
		$provider_request = is_array( $inspector['provider_request'] ?? null ) ? $inspector['provider_request'] : array();
		$routing          = is_array( $inspector['routing'] ?? null ) ? $inspector['routing'] : array();
		$visible_tools    = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['visible_tools'] ?? array() ) ) ) );
		$loaded_tools     = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['loaded_tools'] ?? array() ) ) ) );
		$searchable_tools = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['searchable_tools'] ?? array() ) ) ) );
		$discovered_tools = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['discovered_tools'] ?? array() ) ) ) );
		$blocked_tools    = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['blocked_tools'] ?? array() ) ) ) );
		$hidden_tools     = array_values( array_filter( array_map( 'strval', (array) ( $tool_surface['hidden_tools'] ?? array() ) ) ) );

		echo '<h3>' . esc_html__( 'Context Inspector', 'pressark' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Shows the composed context shape that reached the model, plus the replay/read sidecars that currently govern future rounds.', 'pressark' ) . '</p>';

		$summary_rows = array(
			array(
				'label' => __( 'Round', 'pressark' ),
				'value' => (string) ( $inspector['round'] ?? '-' ),
			),
			array(
				'label' => __( 'Task Type', 'pressark' ),
				'value' => (string) ( $inspector['task_type'] ?? '-' ),
			),
			array(
				'label' => __( 'Provider Format', 'pressark' ),
				'value' => (string) ( $provider_request['provider_format'] ?? '-' ),
			),
			array(
				'label' => __( 'Transport Mode', 'pressark' ),
				'value' => (string) ( $provider_request['transport_mode'] ?? '-' ),
			),
			array(
				'label' => __( 'Transport Provider', 'pressark' ),
				'value' => (string) ( $provider_request['transport_provider'] ?? '-' ),
			),
			array(
				'label' => __( 'Model', 'pressark' ),
				'value' => (string) ( $provider_request['model'] ?? '-' ),
			),
			array(
				'label' => __( 'Visible Tools', 'pressark' ),
				'value' => number_format_i18n( count( $visible_tools ) ),
			),
			array(
				'label' => __( 'Loaded Tools', 'pressark' ),
				'value' => number_format_i18n( count( $loaded_tools ) ),
			),
			array(
				'label' => __( 'Searchable Tools', 'pressark' ),
				'value' => number_format_i18n( count( $searchable_tools ) ),
			),
			array(
				'label' => __( 'Discovered Tools', 'pressark' ),
				'value' => number_format_i18n( count( $discovered_tools ) ),
			),
			array(
				'label' => __( 'Blocked Tools', 'pressark' ),
				'value' => number_format_i18n( count( $blocked_tools ) ),
			),
			array(
				'label' => __( 'Read Snapshots', 'pressark' ),
				'value' => number_format_i18n( count( (array) ( $reads['snapshots'] ?? array() ) ) ),
			),
			array(
				'label' => __( 'Replacement Entries', 'pressark' ),
				'value' => number_format_i18n( count( $replacements ) ),
			),
			array(
				'label' => __( 'Estimated Prompt Tokens', 'pressark' ),
				'value' => isset( $token_footprint['estimated_prompt_tokens'] )
					? number_format_i18n( (int) $token_footprint['estimated_prompt_tokens'] )
					: '-',
			),
		);

		echo '<div class="pressark-inspector-grid">';
		foreach ( $summary_rows as $row ) {
			echo '<div class="pressark-inspector-card">';
			echo '<h4>' . esc_html( (string) $row['label'] ) . '</h4>';
			echo '<p class="pressark-inspector-value">' . esc_html( (string) $row['value'] ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		echo '<details class="pressark-inspector-section" open><summary>' . esc_html__( 'Prompt Composition', 'pressark' ) . '</summary>';
		$capability_variant = (string) ( $prompt['capability_map_variant'] ?? '' );
		$site_playbook      = is_array( $prompt['site_playbook'] ?? null ) ? $prompt['site_playbook'] : array();
		$site_notes         = is_array( $prompt['site_notes'] ?? null ) ? $prompt['site_notes'] : array();
		$dynamic_skills     = array_values( array_filter( array_map( 'strval', (array) ( $prompt['dynamic_skill_names'] ?? array() ) ) ) );
		$conditional_blocks = array_values( array_filter( array_map( 'strval', (array) ( $prompt['conditional_blocks'] ?? array() ) ) ) );
		$site_profiles      = is_array( $prompt['site_profiles'] ?? null ) ? $prompt['site_profiles'] : array();
		$stable_blocks      = is_array( $provider_request['cached_blocks'] ?? null ) ? $provider_request['cached_blocks'] : array();
		$dynamic_blocks     = array();
		foreach ( (array) ( $prompt['stable_blocks'] ?? array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block['bucket'] = 'stable_run_prefix';
			$dynamic_blocks[] = $block;
		}
		foreach ( (array) ( $prompt['volatile_blocks'] ?? array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$block['bucket'] = 'volatile_run_state';
			$dynamic_blocks[] = $block;
		}

		echo '<div class="pressark-inspector-meta">';
		echo '<p><strong>' . esc_html__( 'Capability map variant', 'pressark' ) . ':</strong> ' . esc_html( $capability_variant ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Dynamic skills', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $dynamic_skills ) ? implode( ', ', $dynamic_skills ) : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Conditional blocks', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $conditional_blocks ) ? implode( ', ', $conditional_blocks ) : '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Site playbook included', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $site_playbook['included'] ) ? __( 'Yes', 'pressark' ) : __( 'No', 'pressark' ) ) . '</p>';
		if ( ! empty( $site_playbook['titles'] ) ) {
			echo '<p class="pressark-inspector-subtle">' . esc_html( implode( ', ', array_map( 'strval', (array) $site_playbook['titles'] ) ) ) . '</p>';
		}
		if ( ! empty( $site_playbook['preview'] ) ) {
			echo '<p class="pressark-inspector-subtle">' . esc_html( (string) $site_playbook['preview'] ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Site notes included', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $site_notes['included'] ) ? __( 'Yes', 'pressark' ) : __( 'No', 'pressark' ) ) . '</p>';
		if ( ! empty( $site_notes['preview'] ) ) {
			echo '<p class="pressark-inspector-subtle">' . esc_html( (string) $site_notes['preview'] ) . '</p>';
		}
		echo '</div>';

		if ( ! empty( $stable_blocks ) ) {
			echo '<h4>' . esc_html__( 'Stable Prompt Blocks', 'pressark' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Block', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Tokens', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Chars', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $stable_blocks as $block ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $block['label'] ?? $block['id'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $block['tokens'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $block['chars'] ?? 0 ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $dynamic_blocks ) ) {
			echo '<h4>' . esc_html__( 'Run-Specific Prompt Blocks', 'pressark' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Block', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Bucket', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Tokens', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Lines', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Preview', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $dynamic_blocks as $block ) {
				$bucket = 'stable_run_prefix' === ( $block['bucket'] ?? '' )
					? __( 'Stable Run Prefix', 'pressark' )
					: __( 'Volatile Run State', 'pressark' );
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $block['label'] ?? $block['id'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $bucket ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $block['tokens'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $block['lines'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $block['preview'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $site_profiles ) ) {
			echo '<h4>' . esc_html__( 'Included Site Profiles', 'pressark' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Tool', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Summary', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Freshness', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Captured', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $site_profiles as $profile ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $profile['tool_name'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $profile['summary'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $profile['freshness'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $profile['captured_at'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Routing And Request Shape', 'pressark' ) . '</summary>';
		$selection      = is_array( $routing['selection'] ?? null ) ? $routing['selection'] : array();
		$fallback       = is_array( $routing['fallback'] ?? null ) ? $routing['fallback'] : array();
		$request_shape  = is_array( $provider_request['request_shape'] ?? null ) ? $provider_request['request_shape'] : array();
		$phase_addendum = is_array( $provider_request['phase_addendum'] ?? null ) ? $provider_request['phase_addendum'] : array();
		$dynamic_prompt = is_array( $provider_request['dynamic_prompt'] ?? null ) ? $provider_request['dynamic_prompt'] : array();

		if ( ! empty( $routing ) ) {
			echo '<div class="pressark-inspector-meta">';
			echo '<p><strong>' . esc_html__( 'Routing basis', 'pressark' ) . ':</strong> ' . esc_html( (string) ( $selection['basis'] ?? '-' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Selection mode', 'pressark' ) . ':</strong> ' . esc_html( (string) ( $selection['mode'] ?? '-' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Fallback used', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $fallback['used'] ) ? __( 'Yes', 'pressark' ) : __( 'No', 'pressark' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Fallback attempts', 'pressark' ) . ':</strong> ' . esc_html( number_format_i18n( (int) ( $fallback['attempts'] ?? 0 ) ) ) . '</p>';
			echo '</div>';
		}

		if ( ! empty( $dynamic_prompt ) ) {
			echo '<p><strong>' . esc_html__( 'Dynamic prompt', 'pressark' ) . ':</strong> '
				. esc_html(
					sprintf(
						'base=%d tok / augmented=%d tok',
						(int) ( $dynamic_prompt['tokens'] ?? 0 ),
						(int) ( $dynamic_prompt['augmented_tokens'] ?? 0 )
					)
				)
				. '</p>';
		}

		if ( ! empty( $phase_addendum ) ) {
			echo '<p><strong>' . esc_html__( 'Phase addendum', 'pressark' ) . ':</strong> '
				. esc_html( $this->format_trace_value( $phase_addendum ) ) . '</p>';
		}

		if ( ! empty( $request_shape ) ) {
			$message_roles = array();
			foreach ( (array) ( $request_shape['message_roles'] ?? array() ) as $role => $count ) {
				$message_roles[] = sanitize_key( (string) $role ) . ': ' . number_format_i18n( (int) $count );
			}
			echo '<div class="pressark-inspector-meta">';
			echo '<p><strong>' . esc_html__( 'System blocks', 'pressark' ) . ':</strong> ' . esc_html( number_format_i18n( (int) ( $request_shape['system_block_count'] ?? 0 ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Messages', 'pressark' ) . ':</strong> ' . esc_html( number_format_i18n( (int) ( $request_shape['message_count'] ?? 0 ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Tool schemas', 'pressark' ) . ':</strong> ' . esc_html( number_format_i18n( (int) ( $request_shape['tool_schema_count'] ?? 0 ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Parallel tool calls', 'pressark' ) . ':</strong> ' . esc_html( ! empty( $request_shape['parallel_tool_calls'] ) ? __( 'Yes', 'pressark' ) : __( 'No', 'pressark' ) ) . '</p>';
			if ( ! empty( $message_roles ) ) {
				echo '<p><strong>' . esc_html__( 'Message roles', 'pressark' ) . ':</strong> ' . esc_html( implode( ' | ', $message_roles ) ) . '</p>';
			}
			echo '</div>';
		}

		if ( ! empty( $provider_request['allowed_tools'] ) ) {
			echo '<p><strong>' . esc_html__( 'Allowed tools', 'pressark' ) . ':</strong> '
				. esc_html( implode( ', ', array_map( 'strval', (array) $provider_request['allowed_tools'] ) ) ) . '</p>';
		}
		echo '</details>';

		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Tool Surface', 'pressark' ) . '</summary>';
		echo '<p><strong>' . esc_html__( 'Visible capability pool', 'pressark' ) . ':</strong> '
			. esc_html(
				sprintf(
					/* translators: %d: tool count */
					_n( '%d visible tool', '%d visible tools', count( $visible_tools ), 'pressark' ),
					count( $visible_tools )
				)
			)
			. '</p>';
		echo '<p><strong>' . esc_html__( 'Loaded now', 'pressark' ) . ':</strong> ' . esc_html( $this->format_capped_list( $loaded_tools ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Searchable on demand', 'pressark' ) . ':</strong> ' . esc_html( $this->format_capped_list( $searchable_tools ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Discovered but not yet loaded', 'pressark' ) . ':</strong> ' . esc_html( $this->format_capped_list( $discovered_tools ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Blocked or hidden', 'pressark' ) . ':</strong> ' . esc_html( $this->format_capped_list( $blocked_tools ) ) . '</p>';
		if ( ! empty( $hidden_tools ) ) {
			echo '<p><strong>' . esc_html__( 'Hidden from this request surface', 'pressark' ) . ':</strong> ' . esc_html( $this->format_capped_list( $hidden_tools ) ) . '</p>';
		}
		if ( ! empty( $tool_surface['blocked_summary'] ) ) {
			$blocked_summary = array();
			foreach ( (array) $tool_surface['blocked_summary'] as $reason => $count ) {
				$blocked_summary[] = $reason . ': ' . number_format_i18n( (int) $count );
			}
			echo '<p><strong>' . esc_html__( 'Blocked summary', 'pressark' ) . ':</strong> ' . esc_html( implode( ' | ', $blocked_summary ) ) . '</p>';
		}
		if ( ! empty( $tool_surface['hidden_summary'] ) ) {
			$hidden_summary = array();
			foreach ( (array) $tool_surface['hidden_summary'] as $reason => $count ) {
				$hidden_summary[] = $reason . ': ' . number_format_i18n( (int) $count );
			}
			echo '<p><strong>' . esc_html__( 'Hidden summary', 'pressark' ) . ':</strong> ' . esc_html( implode( ' | ', $hidden_summary ) ) . '</p>';
		}
		$hidden_decisions = is_array( $tool_surface['hidden_decisions'] ?? null ) ? $tool_surface['hidden_decisions'] : array();
		if ( ! empty( $hidden_decisions ) ) {
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Tool', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Verdict', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason Codes', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Reasons', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $hidden_decisions as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['tool'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['verdict'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', (array) ( $row['reason_codes'] ?? array() ) ) ) . '</td>';
				echo '<td>' . esc_html( implode( ' | ', (array) ( $row['reasons'] ?? array() ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Read Snapshots', 'pressark' ) . '</summary>';
		$read_summary = is_array( $reads['summary'] ?? null ) ? $reads['summary'] : array();
		if ( ! empty( $read_summary ) ) {
			echo '<p><strong>' . esc_html__( 'Trust summary', 'pressark' ) . ':</strong> '
				. esc_html(
					sprintf(
						'trusted=%d | derived=%d | untrusted=%d | stale=%d',
						(int) ( $read_summary['trusted_system'] ?? 0 ),
						(int) ( $read_summary['derived_summary'] ?? 0 ),
						(int) ( $read_summary['untrusted_content'] ?? 0 ),
						(int) ( $read_summary['stale'] ?? 0 )
					)
				)
				. '</p>';
		}
		$read_rows = is_array( $reads['snapshots'] ?? null ) ? $reads['snapshots'] : array();
		if ( ! empty( $read_rows ) ) {
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Source', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Summary', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Freshness', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Completeness', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Trust', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Provenance', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $read_rows as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['tool_name'] ?? ( $row['resource_uri'] ?? '' ) ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['summary'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['freshness'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['completeness'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['trust_class'] ?? '' ) ) . '</td>';
				echo '<td class="pressark-trace-details">' . esc_html( $this->format_trace_value( (array) ( $row['provenance'] ?? array() ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Replay And Compaction', 'pressark' ) . '</summary>';
		$compaction = is_array( $replay['compaction'] ?? null ) ? $replay['compaction'] : array();
		if ( ! empty( $compaction ) ) {
			echo '<p><strong>' . esc_html__( 'Compaction', 'pressark' ) . ':</strong> '
				. esc_html( $this->format_trace_value( $compaction ) ) . '</p>';
		}
		$replay_events = is_array( $replay['events'] ?? null ) ? $replay['events'] : array();
		if ( ! empty( $replay_events ) ) {
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Type', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Phase', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Round', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Details', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $replay_events as $event ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $event['type'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $event['phase'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $event['round'] ?? 0 ) ) ) . '</td>';
				echo '<td class="pressark-trace-details">' . esc_html( $this->format_trace_value( $event ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		$message_rows = is_array( $messages['items'] ?? null ) ? $messages['items'] : array();
		if ( ! empty( $message_rows ) ) {
			echo '<h4>' . esc_html__( 'Model-Facing Message Surface', 'pressark' ) . '</h4>';
			echo '<p class="pressark-inspector-subtle">'
				. esc_html(
					sprintf(
						'%d messages after replay repair and compaction.',
						(int) ( $messages['total_messages'] ?? count( $message_rows ) )
					)
				)
				. '</p>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Role', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Kind', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Preview', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $message_rows as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['role'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['kind'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['preview'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		if ( ! empty( $replacements ) ) {
			echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Replacement Journal', 'pressark' ) . '</summary>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Tool', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Round', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Artifact', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Inline Tokens', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $replacements as $entry ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $entry['tool_name'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $entry['round'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $entry['reason'] ?? '' ) ) . '</td>';
				echo '<td class="pressark-trace-details">' . esc_html( (string) ( $entry['artifact_uri'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $entry['inline_tokens'] ?? 0 ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</details>';
		}

		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Token Footprint', 'pressark' ) . '</summary>';
		if ( ! empty( $token_footprint ) ) {
			echo '<p><strong>' . esc_html__( 'Estimated prompt tokens', 'pressark' ) . ':</strong> '
				. esc_html( number_format_i18n( (int) ( $token_footprint['estimated_prompt_tokens'] ?? 0 ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Remaining headroom', 'pressark' ) . ':</strong> '
				. esc_html( number_format_i18n( (int) ( $token_footprint['remaining_tokens'] ?? 0 ) ) ) . '</p>';

			$segments = is_array( $token_footprint['segments'] ?? null ) ? $token_footprint['segments'] : array();
			if ( ! empty( $segments ) ) {
				echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Segment', 'pressark' ) . '</th>';
				echo '<th>' . esc_html__( 'Tokens', 'pressark' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $segments as $segment ) {
					if ( ! is_array( $segment ) ) {
						continue;
					}
					echo '<tr>';
					echo '<td>' . esc_html( (string) ( $segment['label'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( number_format_i18n( (int) ( $segment['tokens'] ?? 0 ) ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}
		echo '</details>';
	}

	/**
	 * Render the site-wide policy diagnostics view.
	 */
	private function render_policy_diagnostics(): void {
		if ( ! class_exists( 'PressArk_Policy_Diagnostics' ) ) {
			echo '<p>' . esc_html__( 'Policy diagnostics are not available.', 'pressark' ) . '</p>';
			return;
		}

		$report = PressArk_Policy_Diagnostics::build_report( 14 );
		$totals = is_array( $report['totals'] ?? null ) ? $report['totals'] : array();

		echo '<div class="pressark-detail-card">';
		echo '<h2>' . esc_html__( 'Policy Diagnostics', 'pressark' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Site-wide friction analytics built from recent hidden-tool surfaces, denied operations, and discovery dead-ends. Shadowed rules and dead automation surfaces are computed from the current live policy state.', 'pressark' ) . '</p>';

		echo '<div class="pressark-inspector-grid">';
		foreach ( array(
			array(
				'label' => __( 'Lookback', 'pressark' ),
				'value' => sprintf( __( '%d days', 'pressark' ), (int) ( $report['lookback_days'] ?? 14 ) ),
			),
			array(
				'label' => __( 'Hidden Surfaces', 'pressark' ),
				'value' => number_format_i18n( (int) ( $totals['surface_events'] ?? 0 ) ),
			),
			array(
				'label' => __( 'Execution Denials', 'pressark' ),
				'value' => number_format_i18n( (int) ( $totals['denial_events'] ?? 0 ) ),
			),
			array(
				'label' => __( 'Never-Visible Groups', 'pressark' ),
				'value' => number_format_i18n( (int) ( $totals['requested_never_visible'] ?? 0 ) ),
			),
			array(
				'label' => __( 'Shadowed Rules', 'pressark' ),
				'value' => number_format_i18n( (int) ( $totals['shadowed_rules'] ?? 0 ) ),
			),
			array(
				'label' => __( 'Dead Automation Surfaces', 'pressark' ),
				'value' => number_format_i18n( (int) ( $totals['dead_group_combinations'] ?? 0 ) ),
			),
		) as $card ) {
			echo '<div class="pressark-inspector-card">';
			echo '<h4>' . esc_html( (string) $card['label'] ) . '</h4>';
			echo '<p class="pressark-inspector-value">' . esc_html( (string) $card['value'] ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		$hidden_tools = is_array( $report['top_hidden_tools'] ?? null ) ? $report['top_hidden_tools'] : array();
		if ( ! empty( $hidden_tools ) ) {
			echo '<h3>' . esc_html__( 'Repeatedly Hidden Tools', 'pressark' ) . '</h3>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Tool', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Group', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Hidden', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary Reason', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Contexts', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $hidden_tools as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['tool'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['group'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $row['count'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['primary_reason'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', (array) ( $row['contexts'] ?? array() ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$denied_operations = is_array( $report['top_denied_operations'] ?? null ) ? $report['top_denied_operations'] : array();
		if ( ! empty( $denied_operations ) ) {
			echo '<h3>' . esc_html__( 'Repeatedly Denied Operations', 'pressark' ) . '</h3>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Operation', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Group', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Denied', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary Source', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary Reason', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $denied_operations as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['operation'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['group'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $row['count'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['primary_source'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['primary_reason'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$never_visible = is_array( $report['requested_never_visible_groups'] ?? null ) ? $report['requested_never_visible_groups'] : array();
		if ( ! empty( $never_visible ) ) {
			echo '<h3>' . esc_html__( 'Groups Requested But Never Visible', 'pressark' ) . '</h3>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Group', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Requests', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Contexts', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $never_visible as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['group'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $row['requested_count'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', (array) ( $row['contexts'] ?? array() ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$dead_ends = is_array( $report['discovery_dead_ends'] ?? null ) ? $report['discovery_dead_ends'] : array();
		if ( ! empty( $dead_ends ) ) {
			echo '<h3>' . esc_html__( 'Discovery Dead Ends', 'pressark' ) . '</h3>';
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Query', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Misses', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Requested Families', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary Reason', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $dead_ends as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['query'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $row['count'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', (array) ( $row['requested_families'] ?? array() ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['primary_reason'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$shadowed_rules = is_array( $report['shadowed_rules'] ?? null ) ? $report['shadowed_rules'] : array();
		echo '<details class="pressark-inspector-section" open><summary>' . esc_html__( 'Shadowed Rule Diagnostics', 'pressark' ) . '</summary>';
		if ( empty( $shadowed_rules ) ) {
			echo '<p>' . esc_html__( 'No unreachable allow/ask rules detected in the current policy set.', 'pressark' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Rule', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Shadow Type', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Shadowed By', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Fix', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $shadowed_rules as $row ) {
				$rule        = is_array( $row['rule'] ?? null ) ? $row['rule'] : array();
				$shadowed_by = is_array( $row['shadowed_by'] ?? null ) ? $row['shadowed_by'] : array();
				echo '<tr>';
				echo '<td><code>' . esc_html( $this->format_policy_rule_label( $rule ) ) . '</code><div class="pressark-inspector-subtle">'
					. esc_html( (string) ( $rule['source'] ?? '' ) ) . '</div></td>';
				echo '<td>' . esc_html( (string) ( $row['shadow_type'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( $this->format_policy_rule_label( $shadowed_by ) ) . '</code><div class="pressark-inspector-subtle">'
					. esc_html( (string) ( $shadowed_by['source'] ?? '' ) ) . '</div></td>';
				echo '<td>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['fix'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		$dead_groups = is_array( $report['dead_group_combinations'] ?? null ) ? $report['dead_group_combinations'] : array();
		echo '<details class="pressark-inspector-section"><summary>' . esc_html__( 'Dead Automation Surfaces', 'pressark' ) . '</summary>';
		if ( empty( $dead_groups ) ) {
			echo '<p>' . esc_html__( 'Every automation policy still exposes at least one tool in every group for the current tier.', 'pressark' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped pressark-inspector-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Policy', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Tier', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Group', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Tools Hidden', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary Reason', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $dead_groups as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['policy'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['tier'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['group'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( $row['tool_count'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['primary_reason'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';

		echo '</div>';
	}

	private function format_policy_rule_label( array $rule ): string {
		$match = (string) ( $rule['match'] ?? '' );
		$value = (string) ( $rule['value'] ?? '' );

		if ( '' === $match ) {
			return $value;
		}

		return $match . '=' . ( '' !== $value ? $value : '{callable}' );
	}

	private function can_support_all(): bool {
		return PressArk_Capabilities::current_user_can_manage_settings();
	}

	private function can_view_policy_diagnostics(): bool {
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
			. '<td>' . wp_kses_post( $html ) . '</td></tr>';
	}

	private function render_task_progress_card( array $progress ): void {
		$headline = sanitize_text_field( (string) ( $progress['headline'] ?? '' ) );
		$summary  = sanitize_text_field( (string) ( $progress['summary'] ?? '' ) );
		$milestone = sanitize_text_field( (string) ( $progress['milestone_summary'] ?? '' ) );
		$facts    = array(
			__( 'State', 'pressark' )  => sanitize_text_field( (string) ( $progress['state_label'] ?? '' ) ),
			__( 'Stage', 'pressark' )  => sanitize_text_field( (string) ( $progress['stage_label'] ?? '' ) ),
			__( 'Latest', 'pressark' ) => sanitize_text_field( (string) ( $progress['event_label'] ?? '' ) ),
			__( 'Target', 'pressark' ) => sanitize_text_field( (string) ( $progress['target_label'] ?? '' ) ),
			__( 'Completed', 'pressark' ) => $this->format_progress_labels( (array) ( $progress['completed_labels'] ?? array() ) ),
			__( 'Remaining', 'pressark' ) => $this->format_progress_labels( (array) ( $progress['remaining_labels'] ?? array() ) ),
			__( 'Blocked', 'pressark' ) => $this->format_progress_labels( (array) ( $progress['blocked_labels'] ?? array() ) ),
		);

		echo '<div class="pressark-progress-card">';
		echo '<h3>' . esc_html__( 'Progress Headline', 'pressark' ) . '</h3>';
		if ( '' !== $headline ) {
			echo '<p class="pressark-progress-headline">' . esc_html( $headline ) . '</p>';
		}
		if ( '' !== $summary ) {
			echo '<p class="pressark-progress-meta">' . esc_html( $summary ) . '</p>';
		}
		if ( '' !== $milestone ) {
			echo '<p class="pressark-progress-milestone">' . esc_html( $milestone ) . '</p>';
		}

		echo '<div class="pressark-progress-grid">';
		foreach ( $facts as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<div class="pressark-progress-fact">';
			echo '<h4>' . esc_html( $label ) . '</h4>';
			echo '<p>' . esc_html( $value ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	private function format_progress_labels( array $labels, int $limit = 3 ): string {
		$labels = array_values(
			array_filter(
				array_map( 'sanitize_text_field', array_slice( $labels, 0, $limit ) ),
				static function ( string $label ): bool {
					return '' !== $label;
				}
			)
		);

		return implode( ', ', $labels );
	}

	private function run_link_html( string $run_id, bool $support_mode, string $label = '' ): string {
		if ( '' === $run_id ) {
			return '-';
		}

		$display = '' !== $label ? $label : $run_id;
		$url     = $this->activity_url(
			array(
				'view'   => self::VIEW_RUNS,
				'run_id' => $run_id,
			),
			$support_mode
		);

		return '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $display ) . '</code></a>';
	}

	private function task_link_html( string $task_id, bool $support_mode, string $label = '' ): string {
		if ( '' === $task_id ) {
			return '-';
		}

		$display = '' !== $label ? $label : $task_id;
		$url     = $this->activity_url(
			array(
				'view'    => self::VIEW_TASKS,
				'task_id' => $task_id,
			),
			$support_mode
		);

		return '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $display ) . '</code></a>';
	}

	private function operational_result_url( array $result, bool $support_mode ): string {
		$task_id = sanitize_text_field( (string) ( $result['task_id'] ?? '' ) );
		$run_id  = sanitize_text_field( (string) ( $result['run_id'] ?? '' ) );
		$kind    = sanitize_key( (string) ( $result['kind'] ?? '' ) );

		if ( in_array( $kind, array( 'task', 'receipt' ), true ) && '' !== $task_id ) {
			return $this->activity_url(
				array(
					'view'    => self::VIEW_TASKS,
					'task_id' => $task_id,
				),
				$support_mode
			);
		}

		if ( in_array( $kind, array( 'run', 'decision', 'trace' ), true ) && '' !== $run_id ) {
			return $this->activity_url(
				array(
					'view'   => self::VIEW_RUNS,
					'run_id' => $run_id,
				),
				$support_mode
			);
		}

		if ( '' !== $task_id ) {
			return $this->activity_url(
				array(
					'view'    => self::VIEW_TASKS,
					'task_id' => $task_id,
				),
				$support_mode
			);
		}

		if ( '' !== $run_id ) {
			return $this->activity_url(
				array(
					'view'   => self::VIEW_RUNS,
					'run_id' => $run_id,
				),
				$support_mode
			);
		}

		return '';
	}

	private function operational_result_kind_label( string $kind ): string {
		return match ( sanitize_key( $kind ) ) {
			'run'       => __( 'Run', 'pressark' ),
			'task'      => __( 'Task', 'pressark' ),
			'decision'  => __( 'Decision', 'pressark' ),
			'receipt'   => __( 'Receipt', 'pressark' ),
			'trace'     => __( 'Trace', 'pressark' ),
			'site_note' => __( 'Site Note', 'pressark' ),
			default     => __( 'Result', 'pressark' ),
		};
	}

	private function operational_signal_label( string $signal ): string {
		return match ( sanitize_key( $signal ) ) {
			'approval' => __( 'Approval', 'pressark' ),
			'billing'  => __( 'Billing', 'pressark' ),
			'failure'  => __( 'Failure', 'pressark' ),
			'fallback' => __( 'Fallback', 'pressark' ),
			'note'     => __( 'Note', 'pressark' ),
			'pending'  => __( 'Pending', 'pressark' ),
			'receipt'  => __( 'Receipt', 'pressark' ),
			default    => ucfirst( sanitize_text_field( $signal ) ),
		};
	}

	private function short_identifier( string $value ): string {
		return '' === $value ? '' : substr( $value, 0, 8 );
	}

	private function render_json_block( string $title, array $data ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<pre class="pressark-json">'
			. esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) )
			. '</pre>';
	}

	/**
	 * Render the joined trust surface for one run.
	 *
	 * @param array<string,mixed> $surface Normalized trust surface.
	 */
	private function render_trust_surface( array $surface ): void {
		if ( empty( $surface ) ) {
			return;
		}

		$phase    = is_array( $surface['phase'] ?? null ) ? $surface['phase'] : array();
		$evidence = is_array( $surface['evidence'] ?? null ) ? $surface['evidence'] : array();
		$fallback = is_array( $surface['fallback'] ?? null ) ? $surface['fallback'] : array();
		$billing  = is_array( $surface['billing'] ?? null ) ? $surface['billing'] : array();

		echo '<h3>' . esc_html__( 'Trust Surface', 'pressark' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Evidence quality is plugin-local. Billing authority is shown separately so operators can see what was checked versus what settled spend.', 'pressark' ) . '</p>';

		echo '<div class="pressark-trust-grid">';

		echo '<div class="pressark-trust-card">';
		echo '<h4>' . esc_html__( 'Phase', 'pressark' ) . '</h4>';
		echo '<p class="pressark-trust-value">' . esc_html( (string) ( $phase['stage_label'] ?? $phase['run_label'] ?? 'Run' ) ) . '</p>';
		echo '<p class="pressark-trust-detail">' . esc_html( (string) ( $phase['description'] ?? '' ) ) . '</p>';
		echo '</div>';

		echo '<div class="pressark-trust-card">';
		echo '<h4>' . esc_html__( 'Confidence Ladder', 'pressark' ) . '</h4>';
		echo '<p class="pressark-trust-value">' . esc_html( (string) ( $evidence['headline'] ?? __( 'No write evidence', 'pressark' ) ) ) . '</p>';
		echo '<p class="pressark-trust-detail">' . esc_html( (string) ( $evidence['summary'] ?? '' ) ) . '</p>';
		echo '</div>';

		echo '<div class="pressark-trust-card">';
		echo '<h4>' . esc_html__( 'Fallback Path', 'pressark' ) . '</h4>';
		echo '<p class="pressark-trust-value">' . esc_html( (string) ( $fallback['headline'] ?? __( 'No fallback or degraded path', 'pressark' ) ) ) . '</p>';
		echo '<p class="pressark-trust-detail">' . esc_html( (string) ( $fallback['summary'] ?? '' ) ) . '</p>';
		echo '</div>';

		echo '<div class="pressark-trust-card">';
		echo '<h4>' . esc_html__( 'Billing Authority', 'pressark' ) . '</h4>';
		echo '<p class="pressark-trust-value">' . esc_html( (string) ( $billing['authority_label'] ?? __( 'Not recorded', 'pressark' ) ) ) . '</p>';
		echo '<p class="pressark-trust-detail">' . esc_html( implode( ' | ', array_filter( array(
			(string) ( $billing['service_label'] ?? '' ),
			(string) ( $billing['spend_label'] ?? '' ),
			(string) ( $billing['settlement_authority'] ?? '' ),
		) ) ) ) . '</p>';
		echo '</div>';

		echo '</div>';

		if ( ! empty( $surface['authority_boundary_note'] ) ) {
			echo '<p class="pressark-trust-note">' . esc_html( (string) $surface['authority_boundary_note'] ) . '</p>';
		}

		$receipts = is_array( $evidence['receipts'] ?? null ) ? $evidence['receipts'] : array();
		if ( ! empty( $receipts ) ) {
			echo '<h4>' . esc_html__( 'Evidence Receipts', 'pressark' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped pressark-trust-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Write', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Confidence', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Evidence', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Checked', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $receipts as $row ) {
				$receipt = is_array( $row['evidence_receipt'] ?? null ) ? $row['evidence_receipt'] : array();
				$status  = (string) ( $receipt['status_label'] ?? __( 'Not checked', 'pressark' ) );
				$confidence = (string) ( $receipt['confidence_label'] ?? __( 'No evidence', 'pressark' ) );
				$evidence_text = (string) ( $receipt['evidence'] ?? $row['summary'] ?? '' );
				$target = (string) ( $row['post_title'] ?? '' );
				if ( '' === $target && ! empty( $row['post_id'] ) ) {
					$target = '#' . (int) $row['post_id'];
				}

				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) ( $row['tool'] ?? '' ) ) . '</strong>'
					. ( $target ? '<div class="pressark-trust-subtle">' . esc_html( $target ) . '</div>' : '' )
					. '</td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td>' . esc_html( $confidence ) . '</td>';
				echo '<td>' . esc_html( $evidence_text ?: '-' ) . '</td>';
				echo '<td>' . esc_html( (string) ( $receipt['checked_at'] ?? '-' ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		$fallback_events = is_array( $fallback['events'] ?? null ) ? $fallback['events'] : array();
		if ( ! empty( $fallback_events ) ) {
			echo '<h4>' . esc_html__( 'Fallback And Degraded Events', 'pressark' ) . '</h4>';
			echo '<table class="wp-list-table widefat striped pressark-trust-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'When', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Source', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
			echo '<th>' . esc_html__( 'Summary', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $fallback_events as $event ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $event['when'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $event['source'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $event['reason'] ?? '' ) ) . '</code>'
					. '<div class="pressark-trust-subtle">' . esc_html( (string) ( $event['reason_label'] ?? '' ) ) . '</div></td>';
				echo '<td>' . esc_html( (string) ( $event['status'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $event['summary'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $family Runs in one lineage group.
	 */
	private function render_lineage_family( array $family, bool $support_mode ): void {
		echo '<table class="widefat fixed striped pressark-family-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Run', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Parent', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Route', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Task', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'pressark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $family as $row ) {
			echo '<tr>';
			echo '<td>' . wp_kses_post( $this->run_link_html( (string) $row['run_id'], $support_mode, $this->short_identifier( (string) $row['run_id'] ) ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->run_link_html( (string) ( $row['parent_run_id'] ?? '' ), $support_mode, $this->short_identifier( (string) ( $row['parent_run_id'] ?? '' ) ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['route'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->task_link_html( (string) ( $row['task_id'] ?? '' ), $support_mode, $this->short_identifier( (string) ( $row['task_id'] ?? '' ) ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->relative_time( (string) ( $row['created_at'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) ( $row['message'] ?? '' ), 0, 80 ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a compact joined trace table.
	 *
	 * @param array<int,array<string,mixed>> $events Ordered trace events.
	 */
	private function render_trace_table( array $events ): void {
		echo '<table class="wp-list-table widefat striped pressark-trace-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Summary', 'pressark' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'pressark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$when    = (string) ( $event['occurred_at'] ?? $event['created_at'] ?? '' );
			$source  = (string) ( $event['source'] ?? 'unknown' );
			$type    = (string) ( $event['event_type'] ?? '' );
			$reason  = (string) ( $event['reason'] ?? '' );
			$status  = (string) ( $event['status'] ?? '' );
			$summary = (string) ( $event['summary'] ?? '' );
			$payload = is_array( $event['payload'] ?? null ) ? (array) $event['payload'] : array();
			$details = $this->format_trace_details( $payload );

			echo '<tr>';
			echo '<td>' . esc_html( $when ) . '</td>';
			echo '<td><code>' . esc_html( $source ) . '</code></td>';
			echo '<td><code>' . esc_html( $type ) . '</code></td>';
			echo '<td><code>' . esc_html( $reason ) . '</code></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( $summary ) . '</td>';
			echo '<td class="pressark-trace-details">' . esc_html( $details ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function format_trace_details( array $payload ): string {
		if ( empty( $payload ) ) {
			return '';
		}

		$details = array();
		$map     = array(
			'attempt'         => 'Attempt',
			'delay_seconds'   => 'Delay',
			'defer_count'     => 'Defers',
			'active_slots'    => 'Slots',
			'backend'         => 'Backend',
			'provider'        => 'Provider',
			'model'           => 'Model',
			'routing_basis'   => 'Basis',
			'failure_class'   => 'Failure',
			'result_type'     => 'Result',
			'workflow_stage'  => 'Stage',
			'run_status'      => 'Run',
			'handoff_summary' => 'Handoff',
			'from'            => 'From',
			'to'              => 'To',
			'from_group'      => 'From Group',
			'to_group'        => 'To Group',
			'query'           => 'Query',
			'zero_hit_count'  => 'Misses',
			'discover_calls'  => 'Discover Calls',
			'error'           => 'Error',
		);

		foreach ( $map as $key => $label ) {
			if ( isset( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				$details[] = $label . ': ' . $this->format_trace_value( $payload[ $key ] );
			}
		}

		foreach ( array( 'parent_run_id' => 'Parent', 'root_run_id' => 'Root', 'child_run_id' => 'Child', 'task_id' => 'Task' ) as $key => $label ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$details[] = $label . ': ' . $this->short_identifier( (string) $payload[ $key ] );
			}
		}

		foreach ( array(
			'loaded_groups'      => 'Groups',
			'bundle_ids'         => 'Bundles',
			'fallback_candidates'=> 'Fallbacks',
			'requested_families' => 'Families',
		) as $key => $label ) {
			if ( ! empty( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$details[] = $label . ': ' . $this->format_trace_value( $payload[ $key ] );
			}
		}

		if ( empty( $details ) && ! empty( $payload['batch_provenance'] ) ) {
			$details[] = 'Batch: ' . $this->format_trace_value( $payload['batch_provenance'] );
		}

		if ( empty( $details ) ) {
			$json = wp_json_encode( $payload );
			if ( ! is_string( $json ) ) {
				return '';
			}
			return mb_strlen( $json ) > 180 ? mb_substr( $json, 0, 177 ) . '...' : $json;
		}

		return implode( ' | ', $details );
	}

	private function format_trace_value( $value ): string {
		if ( is_array( $value ) ) {
			$all_scalar = true;
			foreach ( $value as $item ) {
				if ( is_array( $item ) || is_object( $item ) ) {
					$all_scalar = false;
					break;
				}
			}

			if ( $all_scalar ) {
				$items = array_map(
					static function ( $item ): string {
						if ( is_bool( $item ) ) {
							return $item ? 'true' : 'false';
						}
						return (string) $item;
					},
					$value
				);
				$text = implode(
					', ',
					array_filter(
						$items,
						static function ( string $item ): bool {
							return '' !== $item;
						}
					)
				);
				return mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 117 ) . '...' : $text;
			}

			$json = wp_json_encode( $value );
			return is_string( $json ) && mb_strlen( $json ) > 120 ? mb_substr( $json, 0, 117 ) . '...' : (string) $json;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		$text = (string) $value;
		if ( preg_match( '/^[a-f0-9-]{12,}$/', $text ) ) {
			return $this->short_identifier( $text );
		}

		return mb_strlen( $text ) > 80 ? mb_substr( $text, 0, 77 ) . '...' : $text;
	}

	/**
	 * Render a capped comma-separated list for dense inspector surfaces.
	 *
	 * @param string[] $items Item labels.
	 * @param int      $limit Maximum items to render before collapsing.
	 * @return string
	 */
	private function format_capped_list( array $items, int $limit = 18 ): string {
		$items = array_values( array_filter( array_map(
			static function ( $item ): string {
				return sanitize_text_field( (string) $item );
			},
			$items
		) ) );

		if ( empty( $items ) ) {
			return '-';
		}

		$visible = array_slice( $items, 0, $limit );
		if ( count( $items ) > count( $visible ) ) {
			$visible[] = '+' . ( count( $items ) - count( $visible ) ) . ' more';
		}

		return implode( ', ', $visible );
	}

	private function render_status_filters( array $counts, string $active, string $view, bool $support_mode ): void {
		$base_url = $this->activity_url( array( 'view' => $view ), $support_mode );
		$total    = array_sum( $counts );

		echo '<ul class="subsubsub" style="margin-top:8px;clear:both;">';
		echo '<li><a href="' . esc_url( $base_url ) . '"'
			. ( '' === $active ? ' class="current"' : '' ) . '>'
			. esc_html__( 'All', 'pressark' ) . ' <span class="count">(' . esc_html( number_format_i18n( $total ) ) . ')</span></a></li>';

		foreach ( $counts as $status => $count ) {
			echo ' | <li><a href="' . esc_url( add_query_arg( 'status', $status, $base_url ) ) . '"'
				. ( $status === $active ? ' class="current"' : '' ) . '>'
				. esc_html( $status ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a></li>';
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
			. sprintf(
				/* translators: %s: total number of items. */
				esc_html__( '%s items', 'pressark' ),
				esc_html( number_format_i18n( $total ) )
			)
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
		.pressark-operational-search-card {
			margin: 12px 0 16px;
			padding: 12px 16px;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 6px;
		}
		.pressark-operational-search {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			align-items: center;
		}
		.pressark-operational-search .regular-text {
			flex: 1 1 360px;
			max-width: 720px;
		}
		.pressark-operational-results-card {
			margin-top: 0;
		}
		.pressark-operational-query {
			margin: 10px 0 12px;
		}
		.pressark-operational-chip-row {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin: 8px 0;
		}
		.pressark-operational-chip,
		.pressark-operational-kind {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 3px 9px;
			border-radius: 999px;
			background: #eef2ff;
			color: #1d2327;
			font-size: 12px;
			line-height: 1.4;
			white-space: nowrap;
		}
		.pressark-operational-chip--signal {
			background: #f0f6fc;
		}
		.pressark-operational-table td {
			vertical-align: top;
		}
		.pressark-operational-meta {
			margin-top: 6px;
		}
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
		.pressark-progress-card {
			margin: 16px 0;
			padding: 16px;
			border: 1px solid #dcdcde;
			border-radius: 6px;
			background: #f6f7f7;
		}
		.pressark-progress-card h3 {
			margin: 0 0 8px;
		}
		.pressark-progress-headline {
			margin: 6px 0;
			font-size: 15px;
			font-weight: 600;
			color: #1d2327;
		}
		.pressark-progress-headline--compact {
			font-size: 13px;
			margin-top: 6px;
		}
		.pressark-progress-meta,
		.pressark-progress-milestone {
			margin: 4px 0 0;
			font-size: 12px;
			color: #50575e;
		}
		.pressark-progress-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
			gap: 10px;
			margin-top: 12px;
		}
		.pressark-progress-fact {
			padding: 10px 12px;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			background: #fff;
		}
		.pressark-progress-fact h4 {
			margin: 0 0 4px;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			color: #50575e;
		}
		.pressark-progress-fact p {
			margin: 0;
			font-size: 13px;
			color: #1d2327;
		}
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
		.pressark-family-table code,
		.pressark-trace-table code {
			font-size: 11px;
		}
		.pressark-trace-details {
			color: #50575e;
			font-size: 12px;
		}
		.pressark-trust-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 12px;
			margin: 12px 0 16px;
		}
		.pressark-trust-card {
			background: #f6f7f7;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 12px;
		}
		.pressark-trust-card h4 {
			margin: 0 0 6px;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			color: #50575e;
		}
		.pressark-trust-value {
			margin: 0 0 4px;
			font-size: 16px;
			font-weight: 600;
			color: #1d2327;
		}
		.pressark-trust-detail,
		.pressark-trust-note,
		.pressark-trust-subtle {
			color: #50575e;
			font-size: 12px;
		}
		.pressark-trust-note {
			margin: 0 0 16px;
			padding: 10px 12px;
			background: #fff8e5;
			border-left: 4px solid #dba617;
		}
		.pressark-trust-table {
			margin-bottom: 16px;
		}
		.pressark-inspector-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
			gap: 12px;
			margin: 12px 0 16px;
		}
		.pressark-inspector-card {
			background: #f6f7f7;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 12px;
		}
		.pressark-inspector-card h4 {
			margin: 0 0 6px;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
			color: #50575e;
		}
		.pressark-inspector-value {
			margin: 0;
			font-size: 16px;
			font-weight: 600;
			color: #1d2327;
		}
		.pressark-inspector-section {
			margin: 12px 0;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			background: #fff;
		}
		.pressark-inspector-section summary {
			cursor: pointer;
			padding: 12px 14px;
			font-weight: 600;
		}
		.pressark-inspector-section > *:not(summary) {
			margin-left: 14px;
			margin-right: 14px;
		}
		.pressark-inspector-section > table,
		.pressark-inspector-section > .wp-list-table {
			margin-bottom: 14px;
		}
		.pressark-inspector-meta {
			margin: 0 0 12px;
		}
		.pressark-inspector-meta p {
			margin: 6px 0;
		}
		.pressark-inspector-subtle {
			color: #50575e;
			font-size: 12px;
		}
		.pressark-inspector-table {
			margin-bottom: 14px;
		}
		.pressark-actions-list li {
			padding: 2px 0;
		}
		.pressark-runs-table .column-status,
		.pressark-tasks-table .column-status { width: 100px; }
		';
	}
}
