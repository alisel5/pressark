<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressArk Dashboard Widget — shows content stats, scan scores, quick actions, recent activity.
 */
class PressArk_Dashboard {

	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes for the dashboard.
	 */
	public function register_rest_routes(): void {
		register_rest_route( 'pressark/v1', '/errors/clear', array(
			'methods'             => 'POST',
			'callback'            => static function () {
				PressArk_Error_Tracker::clear();
				return rest_ensure_response( array( 'success' => true ) );
			},
			'permission_callback' => static function () {
				return PressArk_Capabilities::current_user_can_manage_settings();
			},
		) );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		if ( ! PressArk_Capabilities::current_user_can_manage_settings() ) {
			return;
		}

		wp_add_dashboard_widget(
			'pressark_dashboard',
			__( 'PressArk Overview', 'pressark' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Find a brand image by name from the imgs folder.
	 */
	private function find_brand_image( array $names ): ?string {
		$imgs_dir = PRESSARK_PATH . 'assets/imgs/';
		$imgs_url = PRESSARK_URL . 'assets/imgs/';

		foreach ( $names as $name ) {
			foreach ( array( 'png', 'jpg', 'svg', 'webp' ) as $ext ) {
				if ( file_exists( $imgs_dir . $name . '.' . $ext ) ) {
					return $imgs_url . $name . '.' . $ext;
				}
			}
		}

		foreach ( $names as $name ) {
			$all_files = glob( $imgs_dir . '*' );
			if ( $all_files ) {
				foreach ( $all_files as $file ) {
					$basename = pathinfo( $file, PATHINFO_FILENAME );
					if ( stripos( $basename, $name ) !== false ) {
						return $imgs_url . basename( $file );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Render the widget content.
	 */
	public function render_widget(): void {
		$post_count    = wp_count_posts( 'post' );
		$page_count    = wp_count_posts( 'page' );
		$product_count = class_exists( 'WooCommerce' ) ? wp_count_posts( 'product' ) : null;

		$tracker = new PressArk_Usage_Tracker();
		$usage   = $tracker->get_usage_data();
		$logger  = new PressArk_Action_Logger();
		$recent  = $logger->get_recent( 5 );

		$error_count  = PressArk_Error_Tracker::count();
		$recent_errors = PressArk_Error_Tracker::get_recent( 10 );

		$has_api_key = PressArk_AI_Connector::is_proxy_mode() || (bool) get_option( 'pressark_byok_enabled', false ) || ! empty( get_option( 'pressark_api_key', '' ) );
		$logo_url    = $this->find_brand_image( array( 'WHITE-APP-LOGO', 'icon', 'app-icon', 'favicon' ) );
		?>
		<style>
			.pressark-dash { font-size: 13px; }
			.pressark-dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
			.pressark-dash-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; text-align: center; }
			.pressark-dash-stat-value { font-size: 24px; font-weight: 700; color: #1e293b; line-height: 1.2; }
			.pressark-dash-stat-label { font-size: 11px; color: #64748b; margin-top: 2px; }
			.pressark-dash-section { margin-bottom: 14px; }
			.pressark-dash-section h4 { margin: 0 0 8px; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
			.pressark-dash-actions { display: flex; flex-wrap: wrap; gap: 6px; }
			.pressark-dash-action { background: #3b82f6; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: background 0.15s; }
			.pressark-dash-action:hover { background: #2563eb; }
			.pressark-dash-action:disabled { opacity: 0.5; cursor: not-allowed; }
			.pressark-dash-recent { list-style: none; margin: 0; padding: 0; }
			.pressark-dash-recent li { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; color: #475569; }
			.pressark-dash-recent li:last-child { border-bottom: none; }
			.pressark-dash-recent-time { color: #94a3b8; font-size: 11px; }
			.pressark-dash-usage { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 12px; font-size: 12px; color: #166534; text-align: center; margin-bottom: 12px; }
			.pressark-dash-usage.depleted { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
			.pressark-dash-nokey { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 12px; text-align: center; color: #92400e; }
			.pressark-dash-nokey a { color: #3b82f6; }
			.pressark-dash-errors { margin-bottom: 14px; }
			.pressark-dash-errors summary { cursor: pointer; font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; list-style: none; display: flex; align-items: center; gap: 6px; }
			.pressark-dash-errors summary::-webkit-details-marker { display: none; }
			.pressark-dash-errors summary .pressark-error-badge { background: #ef4444; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 9px; font-weight: 700; }
			.pressark-dash-errors summary .pressark-error-badge.zero { background: #22c55e; }
			.pressark-dash-errors summary::before { content: '\25B6'; font-size: 8px; transition: transform 0.15s; }
			.pressark-dash-errors[open] summary::before { transform: rotate(90deg); }
			.pressark-dash-error-list { list-style: none; margin: 8px 0 0; padding: 0; }
			.pressark-dash-error-list li { padding: 6px 8px; border-left: 3px solid #f97316; background: #fffbeb; margin-bottom: 4px; border-radius: 0 4px 4px 0; font-size: 11px; color: #475569; }
			.pressark-dash-error-list li.severity-critical { border-left-color: #dc2626; background: #fef2f2; }
			.pressark-dash-error-list li.severity-error { border-left-color: #ef4444; background: #fef2f2; }
			.pressark-dash-error-list li.severity-warning { border-left-color: #f59e0b; background: #fffbeb; }
			.pressark-dash-error-meta { display: flex; justify-content: space-between; margin-bottom: 2px; }
			.pressark-dash-error-severity { font-weight: 700; text-transform: uppercase; font-size: 10px; }
			.pressark-dash-error-time { color: #94a3b8; font-size: 10px; }
			.pressark-dash-error-component { color: #6366f1; font-weight: 600; }
			.pressark-dash-error-clear { font-size: 11px; color: #3b82f6; cursor: pointer; background: none; border: none; padding: 4px 0; margin-top: 4px; }
			.pressark-dash-error-clear:hover { text-decoration: underline; }
		</style>

		<div class="pressark-dash">
			<?php if ( $logo_url ) : ?>
			<div class="pressark-dw-header">
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="PressArk">
				<strong><?php esc_html_e( 'Site Overview', 'pressark' ); ?></strong>
			</div>
			<?php endif; ?>
			<?php if ( ! $has_api_key ) : ?>
				<div class="pressark-dash-nokey">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: admin settings URL. */
							__( 'PressArk needs an API key to work. <a href="%s">Configure it in settings</a>.', 'pressark' ),
							esc_url( admin_url( 'admin.php?page=pressark' ) )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</div>
			<?php else : ?>

				<!-- Content Stats -->
				<div class="pressark-dash-grid">
					<div class="pressark-dash-stat">
						<div class="pressark-dash-stat-value"><?php echo esc_html( $page_count->publish ?? 0 ); ?></div>
						<div class="pressark-dash-stat-label"><?php esc_html_e( 'Pages', 'pressark' ); ?></div>
					</div>
					<div class="pressark-dash-stat">
						<div class="pressark-dash-stat-value"><?php echo esc_html( $post_count->publish ?? 0 ); ?></div>
						<div class="pressark-dash-stat-label"><?php esc_html_e( 'Posts', 'pressark' ); ?></div>
					</div>
					<?php if ( $product_count ) : ?>
					<div class="pressark-dash-stat">
						<div class="pressark-dash-stat-value"><?php echo esc_html( $product_count->publish ?? 0 ); ?></div>
						<div class="pressark-dash-stat-label"><?php esc_html_e( 'Products', 'pressark' ); ?></div>
					</div>
					<?php endif; ?>
					<div class="pressark-dash-stat">
						<div class="pressark-dash-stat-value">
							<?php
							$tier       = ( new PressArk_License() )->get_tier();
							$tier_label = PressArk_Entitlements::TIER_LABELS[ $tier ] ?? 'Free';
							echo esc_html( $tier_label );
							?>
						</div>
						<div class="pressark-dash-stat-label">
							<?php esc_html_e( 'Current Plan', 'pressark' ); ?>
						</div>
					</div>
				</div>

				<!-- Quick Actions -->
				<div class="pressark-dash-section">
					<h4><?php esc_html_e( 'Quick Actions', 'pressark' ); ?></h4>
					<div class="pressark-dash-actions">
						<button class="pressark-dash-action" data-action="Scan my entire site's SEO and suggest improvements"><?php esc_html_e( 'SEO Scan', 'pressark' ); ?></button>
						<button class="pressark-dash-action" data-action="Run a security check on my site and fix any issues"><?php esc_html_e( 'Security Check', 'pressark' ); ?></button>
						<button class="pressark-dash-action" data-action="Write a new blog post that matches my site's tone and style"><?php esc_html_e( 'Write Blog Post', 'pressark' ); ?></button>
						<button class="pressark-dash-action" data-action="Generate SEO meta titles and descriptions for all my pages"><?php esc_html_e( 'Generate Meta Tags', 'pressark' ); ?></button>
						<?php if ( $product_count ) : ?>
						<button class="pressark-dash-action" data-action="Analyze my WooCommerce store health and inventory"><?php esc_html_e( 'Store Health', 'pressark' ); ?></button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Recent Activity -->
				<?php if ( ! empty( $recent ) ) : ?>
				<div class="pressark-dash-section">
					<h4><?php esc_html_e( 'Recent Activity', 'pressark' ); ?></h4>
					<ul class="pressark-dash-recent">
						<?php foreach ( $recent as $entry ) : ?>
						<li>
							<span><?php echo esc_html( ucfirst( str_replace( '_', ' ', $entry['action_type'] ) ) ); ?>
								<?php if ( ! empty( $entry['target_id'] ) ) : ?>
									<span style="color:#94a3b8;">#<?php echo esc_html( $entry['target_id'] ); ?></span>
								<?php endif; ?>
							</span>
							<span class="pressark-dash-recent-time"><?php
								/* translators: %s: human-readable time difference */
								printf( esc_html__( '%s ago', 'pressark' ), esc_html( human_time_diff( strtotime( $entry['created_at'] ) ) ) );
							?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

			<!-- Recent Errors -->
				<details class="pressark-dash-errors">
					<summary>
						<?php esc_html_e( 'Recent Errors', 'pressark' ); ?>
						<span class="pressark-error-badge <?php echo esc_attr( $error_count === 0 ? 'zero' : '' ); ?>"><?php echo esc_html( $error_count ); ?></span>
					</summary>
					<?php if ( ! empty( $recent_errors ) ) : ?>
						<ul class="pressark-dash-error-list">
							<?php foreach ( array_reverse( $recent_errors ) as $err ) : ?>
							<li class="severity-<?php echo esc_attr( $err['severity'] ); ?>">
								<div class="pressark-dash-error-meta">
									<span>
										<span class="pressark-dash-error-severity"><?php echo esc_html( $err['severity'] ); ?></span>
										<span class="pressark-dash-error-component"><?php echo esc_html( $err['component'] ); ?></span>
									</span>
									<span class="pressark-dash-error-time"><?php
										/* translators: %s: human-readable time difference */
										printf( esc_html__( '%s ago', 'pressark' ), esc_html( human_time_diff( strtotime( $err['timestamp'] ) ) ) );
									?></span>
								</div>
								<?php echo esc_html( $err['message'] ); ?>
							</li>
							<?php endforeach; ?>
						</ul>
						<button class="pressark-dash-error-clear" id="pressark-clear-errors"><?php esc_html_e( 'Clear all errors', 'pressark' ); ?></button>
					<?php else : ?>
						<p style="font-size: 12px; color: #94a3b8; margin: 8px 0 0;"><?php esc_html_e( 'No errors recorded.', 'pressark' ); ?></p>
					<?php endif; ?>
				</details>

			<?php endif; ?>
		</div>

		<script>
		(function() {
			var buttons = document.querySelectorAll('.pressark-dash-action');
			for (var i = 0; i < buttons.length; i++) {
				buttons[i].addEventListener('click', function() {
					var msg = this.dataset.action;
					if (window.PressArk) {
						if (!window.PressArk.isOpen) window.PressArk.open();
						window.PressArk.inputEl.value = msg;
						window.PressArk.sendMessage();
					}
				});
			}

			var clearBtn = document.getElementById('pressark-clear-errors');
			if (clearBtn) {
				clearBtn.addEventListener('click', function() {
					var btn = this;
					btn.disabled = true;
					btn.textContent = <?php echo wp_json_encode( __( 'Clearing…', 'pressark' ) ); ?>;
					wp.apiRequest({ path: '/pressark/v1/errors/clear', method: 'POST' }).done(function() {
						var list = document.querySelector('.pressark-dash-error-list');
						if (list) list.remove();
						btn.remove();
						var badge = document.querySelector('.pressark-error-badge');
						if (badge) { badge.textContent = '0'; badge.classList.add('zero'); }
						var details = document.querySelector('.pressark-dash-errors');
						if (details) {
							var p = document.createElement('p');
							p.style.cssText = 'font-size:12px;color:#94a3b8;margin:8px 0 0';
							p.textContent = <?php echo wp_json_encode( __( 'No errors recorded.', 'pressark' ) ); ?>;
							details.appendChild(p);
						}
					}).fail(function() {
						btn.disabled = false;
						btn.textContent = <?php echo wp_json_encode( __( 'Clear all errors', 'pressark' ) ); ?>;
					});
				});
			}
		})();
		</script>
		<?php
	}
}
