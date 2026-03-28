<?php
/**
 * PressArk Insights
 *
 * Analytics query engine and admin sub-page. All queries hit the existing
 * pressark_cost_ledger table using its idx_status_created compound index.
 *
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Insights {

	public function __construct() {
		// Priority 20 ensures the parent menu (registered at 10 by PressArk_Admin) exists
		// before this submenu registers. Without this, the hookname computed by
		// add_submenu_page() won't match what user_can_access_admin_page() resolves.
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 20 );
	}

	/**
	 * Register the Insights sub-menu page under the PressArk parent menu.
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'pressark',
			__( 'PressArk Insights', 'pressark' ),
			__( 'Insights', 'pressark' ),
			'pressark_manage_settings',
			'pressark-insights',
			array( $this, 'render_page' )
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Return the DB column name for the selected metric.
	 */
	private static function metric_column( string $metric ): string {
		return 'icu' === $metric ? 'settled_icus' : 'settled_tokens';
	}

	/**
	 * Human-readable label for the active metric.
	 */
	private static function metric_label( string $metric ): string {
		return 'icu' === $metric ? __( 'ICU', 'pressark' ) : __( 'Tokens', 'pressark' );
	}

	// ── Query Methods ────────────────────────────────────────────────

	/**
	 * Total summary stats for the header.
	 *
	 * @return array{total_requests: int, total_metric: int, total_input: int,
	 *               total_output: int, total_cache_read: int, failed_count: int}
	 */
	public function summary( string $since, string $metric = 'tokens' ): array {
		global $wpdb;
		$table  = PressArk_Cost_Ledger::table_name();
		$column = self::metric_column( $metric );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS total_requests,
				COALESCE(SUM({$column}), 0) AS total_metric,
				COALESCE(SUM(input_tokens), 0) AS total_input,
				COALESCE(SUM(output_tokens), 0) AS total_output,
				COALESCE(SUM(cache_read_tokens), 0) AS total_cache_read,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
			 FROM {$table}
			 WHERE created_at >= %s",
			$since
		), ARRAY_A );

		return $row ? array_map( 'intval', $row ) : array(
			'total_requests'   => 0,
			'total_metric'     => 0,
			'total_input'      => 0,
			'total_output'     => 0,
			'total_cache_read' => 0,
			'failed_count'     => 0,
		);
	}

	/**
	 * Usage settled by provider within the date range.
	 *
	 * @return array<array{provider: string, total_metric: int, request_count: int}>
	 */
	public function by_provider( string $since, string $metric = 'tokens' ): array {
		global $wpdb;
		$table  = PressArk_Cost_Ledger::table_name();
		$column = self::metric_column( $metric );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT
				provider,
				COALESCE(SUM({$column}), 0) AS total_metric,
				COUNT(*) AS request_count
			 FROM {$table}
			 WHERE status = 'settled' AND created_at >= %s
			 GROUP BY provider
			 ORDER BY total_metric DESC",
			$since
		), ARRAY_A ) ?: array();
	}

	/**
	 * Usage settled by model within the date range.
	 *
	 * @return array<array{model: string, total_metric: int, request_count: int}>
	 */
	public function by_model( string $since, string $metric = 'tokens' ): array {
		global $wpdb;
		$table  = PressArk_Cost_Ledger::table_name();
		$column = self::metric_column( $metric );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT
				model,
				COALESCE(SUM({$column}), 0) AS total_metric,
				COUNT(*) AS request_count
			 FROM {$table}
			 WHERE status = 'settled' AND created_at >= %s
			 GROUP BY model
			 ORDER BY total_metric DESC",
			$since
		), ARRAY_A ) ?: array();
	}

	/**
	 * Cache hit rate.
	 *
	 * @return float 0.0–1.0
	 */
	public function cache_hit_rate( string $since ): float {
		global $wpdb;
		$table = PressArk_Cost_Ledger::table_name();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COALESCE(SUM(cache_read_tokens), 0) AS cache_read,
				COALESCE(SUM(input_tokens), 0) AS input_total
			 FROM {$table}
			 WHERE status = 'settled' AND created_at >= %s",
			$since
		), ARRAY_A );

		if ( ! $row ) {
			return 0.0;
		}

		$cache_read  = (int) $row['cache_read'];
		$input_total = (int) $row['input_total'];
		$denominator = $input_total + $cache_read;

		return $denominator > 0 ? round( $cache_read / $denominator, 4 ) : 0.0;
	}

	/**
	 * Usage burn by route within the date range.
	 *
	 * @return array<array{route: string, total_metric: int, request_count: int}>
	 */
	public function by_route( string $since, string $metric = 'tokens' ): array {
		global $wpdb;
		$table  = PressArk_Cost_Ledger::table_name();
		$column = self::metric_column( $metric );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT
				route,
				COALESCE(SUM({$column}), 0) AS total_metric,
				COUNT(*) AS request_count
			 FROM {$table}
			 WHERE status = 'settled' AND created_at >= %s
			 GROUP BY route
			 ORDER BY total_metric DESC",
			$since
		), ARRAY_A ) ?: array();
	}

	/**
	 * Average agent rounds for multi-step requests.
	 *
	 * @return float
	 */
	public function avg_agent_rounds( string $since ): float {
		global $wpdb;
		$table = PressArk_Cost_Ledger::table_name();

		$avg = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(agent_rounds)
			 FROM {$table}
			 WHERE route = 'agent' AND agent_rounds > 0 AND status = 'settled' AND created_at >= %s",
			$since
		) );

		return $avg !== null ? round( (float) $avg, 1 ) : 0.0;
	}

	/**
	 * Top failure reasons ranked by count.
	 *
	 * @return array<array{fail_reason: string, count: int}>
	 */
	public function top_failures( string $since ): array {
		global $wpdb;
		$table = PressArk_Cost_Ledger::table_name();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT
				COALESCE(fail_reason, 'Unknown') AS fail_reason,
				COUNT(*) AS cnt
			 FROM {$table}
			 WHERE status = 'failed' AND created_at >= %s
			 GROUP BY fail_reason
			 ORDER BY cnt DESC
			 LIMIT 10",
			$since
		), ARRAY_A ) ?: array();
	}

	/**
	 * Daily request volume.
	 *
	 * @return array<array{day: string, total: int, settled: int, failed: int}>
	 */
	public function daily_volume( string $since ): array {
		global $wpdb;
		$table = PressArk_Cost_Ledger::table_name();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT
				DATE(created_at) AS day,
				COUNT(*) AS total,
				SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) AS settled,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
			 FROM {$table}
			 WHERE created_at >= %s
			 GROUP BY day
			 ORDER BY day ASC",
			$since
		), ARRAY_A ) ?: array();
	}

	// ── Render ───────────────────────────────────────────────────────

	/**
	 * Render the full insights page.
	 */
	public function render_page(): void {
		if ( ! PressArk_Capabilities::current_user_can_manage_settings() ) {
			return;
		}

		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30;
		if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) {
			$range = 30;
		}

		$metric = isset( $_GET['metric'] ) ? sanitize_key( $_GET['metric'] ) : 'tokens';
		if ( ! in_array( $metric, array( 'tokens', 'icu' ), true ) ) {
			$metric = 'tokens';
		}

		$since   = gmdate( 'Y-m-d H:i:s', time() - ( $range * DAY_IN_SECONDS ) );
		$summary = $this->summary( $since, $metric );
		$cache   = $this->cache_hit_rate( $since );
		$rounds  = $this->avg_agent_rounds( $since );
		$label   = self::metric_label( $metric );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PressArk Insights', 'pressark' ); ?></h1>

			<div style="display:flex;gap:16px;align-items:center;margin:16px 0;">
				<?php $this->render_range_selector( $range, $metric ); ?>
				<?php $this->render_metric_selector( $metric, $range ); ?>
			</div>

			<!-- Summary Cards -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
				<?php
				/* translators: %s: metric label (Tokens or ICU) */
				$this->render_card( sprintf( __( 'Total %s', 'pressark' ), $label ), self::format_tokens( $summary['total_metric'] ) );
				$this->render_card( __( 'Total Requests', 'pressark' ), number_format( $summary['total_requests'] ) );
				$this->render_card( __( 'Cache Hit Rate', 'pressark' ), round( $cache * 100, 1 ) . '%' );
				$this->render_card( __( 'Failed Requests', 'pressark' ), number_format( $summary['failed_count'] ) );
				?>
			</div>

			<!-- Provider Breakdown -->
			<div class="postbox" style="margin:20px 0;padding:0;">
				<h2 class="hndle" style="padding:12px 16px;margin:0;"><span><?php
					/* translators: %s: metric label */
					printf( esc_html__( '%s by Provider', 'pressark' ), esc_html( $label ) );
				?></span></h2>
				<div class="inside" style="padding:0 16px 16px;">
					<?php
					$providers = $this->by_provider( $since, $metric );
					if ( empty( $providers ) ) {
						echo '<p>' . esc_html__( 'No data for this period.', 'pressark' ) . '</p>';
					} else {
						$total = array_sum( array_column( $providers, 'total_metric' ) );
						$this->render_table(
							array( __( 'Provider', 'pressark' ), __( 'Requests', 'pressark' ), $label, __( '% of Total', 'pressark' ) ),
							array_map( function( $row ) use ( $total ) {
								$pct = $total > 0 ? round( ( (int) $row['total_metric'] / $total ) * 100, 1 ) : 0;
								return array(
									esc_html( $row['provider'] ?: '(empty)' ),
									number_format( (int) $row['request_count'] ),
									self::format_tokens( (int) $row['total_metric'] ),
									$pct . '%',
								);
							}, $providers )
						);
					}
					?>
				</div>
			</div>

			<!-- Model Breakdown -->
			<div class="postbox" style="margin:20px 0;padding:0;">
				<h2 class="hndle" style="padding:12px 16px;margin:0;"><span><?php
					printf(
						/* translators: %s: selected metric label. */
						esc_html__( '%s by Model', 'pressark' ),
						esc_html( $label )
					);
				?></span></h2>
				<div class="inside" style="padding:0 16px 16px;">
					<?php
					$models = $this->by_model( $since, $metric );
					if ( empty( $models ) ) {
						echo '<p>' . esc_html__( 'No data for this period.', 'pressark' ) . '</p>';
					} else {
						$total = array_sum( array_column( $models, 'total_metric' ) );
						$this->render_table(
							array( __( 'Model', 'pressark' ), __( 'Requests', 'pressark' ), $label, __( '% of Total', 'pressark' ) ),
							array_map( function( $row ) use ( $total ) {
								$pct = $total > 0 ? round( ( (int) $row['total_metric'] / $total ) * 100, 1 ) : 0;
								return array(
									esc_html( $row['model'] ?: '(empty)' ),
									number_format( (int) $row['request_count'] ),
									self::format_tokens( (int) $row['total_metric'] ),
									$pct . '%',
								);
							}, $models )
						);
					}
					?>
				</div>
			</div>

			<!-- Route Breakdown -->
			<div class="postbox" style="margin:20px 0;padding:0;">
				<h2 class="hndle" style="padding:12px 16px;margin:0;"><span><?php
					printf(
						/* translators: %s: selected metric label. */
						esc_html__( '%s by Route', 'pressark' ),
						esc_html( $label )
					);
				?></span></h2>
				<div class="inside" style="padding:0 16px 16px;">
					<?php
					$routes = $this->by_route( $since, $metric );
					if ( empty( $routes ) ) {
						echo '<p>' . esc_html__( 'No data for this period.', 'pressark' ) . '</p>';
					} else {
						$this->render_table(
							array( __( 'Route', 'pressark' ), __( 'Requests', 'pressark' ), $label ),
							array_map( function( $row ) {
								return array(
									esc_html( $row['route'] ),
									number_format( (int) $row['request_count'] ),
									self::format_tokens( (int) $row['total_metric'] ),
								);
							}, $routes )
						);
					}
					?>
				</div>
			</div>

			<!-- Agent Performance -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
				<?php $this->render_card( __( 'Avg Agent Rounds', 'pressark' ), $rounds > 0 ? number_format( $rounds, 1 ) : '—' ); ?>
			</div>

			<!-- Top Failures -->
			<div class="postbox" style="margin:20px 0;padding:0;">
				<h2 class="hndle" style="padding:12px 16px;margin:0;"><span><?php esc_html_e( 'Top Failure Reasons', 'pressark' ); ?></span></h2>
				<div class="inside" style="padding:0 16px 16px;">
					<?php
					$failures = $this->top_failures( $since );
					if ( empty( $failures ) ) {
						echo '<p>' . esc_html__( 'No failures in this period.', 'pressark' ) . '</p>';
					} else {
						$this->render_table(
							array( __( 'Reason', 'pressark' ), __( 'Count', 'pressark' ) ),
							array_map( function( $row ) {
								return array(
									esc_html( mb_strimwidth( $row['fail_reason'], 0, 120, '...' ) ),
									number_format( (int) $row['cnt'] ),
								);
							}, $failures )
						);
					}
					?>
				</div>
			</div>

			<!-- Daily Volume -->
			<div class="postbox" style="margin:20px 0;padding:0;">
				<h2 class="hndle" style="padding:12px 16px;margin:0;"><span><?php esc_html_e( 'Daily Request Volume', 'pressark' ); ?></span></h2>
				<div class="inside" style="padding:0 16px 16px;">
					<?php
					$volume = $this->daily_volume( $since );
					if ( empty( $volume ) ) {
						echo '<p>' . esc_html__( 'No data for this period.', 'pressark' ) . '</p>';
					} else {
						$this->render_table(
							array( __( 'Date', 'pressark' ), __( 'Total', 'pressark' ), __( 'Settled', 'pressark' ), __( 'Failed', 'pressark' ) ),
							array_map( function( $row ) {
								return array(
									esc_html( $row['day'] ),
									number_format( (int) $row['total'] ),
									number_format( (int) $row['settled'] ),
									number_format( (int) $row['failed'] ),
								);
							}, $volume )
						);
					}
					?>
				</div>
			</div>

			<!-- Retention Info -->
			<div style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:6px;padding:16px;margin:20px 0;">
				<strong><?php esc_html_e( 'Data Retention', 'pressark' ); ?></strong>
				<?php
				$ret = PressArk_Retention::get_all();
				?>
				<p style="margin:8px 0 0;">
					<?php
					printf(
						/* translators: 1: log days 2: chat days 3: ledger days */
						esc_html__( 'Action log: %1$d days · Chat history: %2$d days · Cost telemetry: %3$d days', 'pressark' ),
						$ret['log'],
						$ret['chats'],
						$ret['ledger']
					);
					?>
					&middot;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pressark' ) ); ?>"><?php esc_html_e( 'Change', 'pressark' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	// ── Render Helpers ───────────────────────────────────────────────

	/**
	 * Render the time range selector.
	 */
	private function render_range_selector( int $current_range, string $metric ): void {
		$ranges = array( 7, 30, 90 );
		$labels = array(
			7  => __( 'Last 7 days', 'pressark' ),
			30 => __( 'Last 30 days', 'pressark' ),
			90 => __( 'Last 90 days', 'pressark' ),
		);

		echo '<div>';
		foreach ( $ranges as $r ) {
			$url   = admin_url( 'admin.php?page=pressark-insights&range=' . $r . '&metric=' . $metric );
			$class = $r === $current_range ? 'button button-primary' : 'button';
			printf(
				'<a href="%s" class="%s" style="margin-right:4px;">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $labels[ $r ] )
			);
		}
		echo '</div>';
	}

	/**
	 * Render the Tokens / ICU metric selector.
	 */
	private function render_metric_selector( string $current_metric, int $range ): void {
		$options = array(
			'tokens' => __( 'Tokens', 'pressark' ),
			'icu'    => __( 'ICU', 'pressark' ),
		);

		echo '<div>';
		foreach ( $options as $key => $label ) {
			$url   = admin_url( 'admin.php?page=pressark-insights&range=' . $range . '&metric=' . $key );
			$class = $key === $current_metric ? 'button button-primary' : 'button';
			printf(
				'<a href="%s" class="%s" style="margin-right:4px;">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Render a single stat card.
	 */
	private function render_card( string $title, string $value ): void {
		?>
		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;">
			<div style="color:#64748b;font-size:13px;margin-bottom:4px;"><?php echo esc_html( $title ); ?></div>
			<div style="font-size:24px;font-weight:600;"><?php echo esc_html( $value ); ?></div>
		</div>
		<?php
	}

	/**
	 * Render a simple HTML table.
	 *
	 * @param array $headers Column headers.
	 * @param array $rows    Array of row arrays (already escaped).
	 */
	private function render_table( array $headers, array $rows ): void {
		echo '<table class="widefat striped" style="margin-top:8px;">';
		echo '<thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $cell ) {
				echo '<td>' . wp_kses_post( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Format a token/ICU count for display.
	 *
	 * @param int $tokens Raw count.
	 * @return string Formatted string.
	 */
	public static function format_tokens( int $tokens ): string {
		if ( $tokens >= 1000000 ) {
			return round( $tokens / 1000000, 1 ) . 'M';
		}
		return number_format( $tokens );
	}
}
