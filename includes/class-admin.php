<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu registration and settings page.
 */
class PressArk_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add top-level admin menu item.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'PressArk Settings', 'pressark' ),
			__( 'PressArk', 'pressark' ),
			'pressark_manage_settings',
			'pressark',
			array( $this, 'render_settings_page' ),
			'dashicons-superhero-alt',
			80
		);
	}

	/**
	 * Register all settings fields using the Settings API.
	 */
	public function register_settings(): void {
		register_setting( 'pressark_settings', 'pressark_api_provider', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'openrouter',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_api_key' ),
			'default'           => '',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'auto',
		) );

		register_setting( 'pressark_settings', 'pressark_custom_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_summarize_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'auto',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_summarize_custom_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_license_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_license_key' ),
			'default'           => '',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', PressArk_Site_Playbook::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_site_playbook' ),
			'default'           => array(),
			'autoload'          => false,
		) );

		// API section.
		add_settings_section(
			'pressark_api_section',
			__( 'API Configuration', 'pressark' ),
			array( $this, 'render_api_section' ),
			'pressark'
		);

		add_settings_field(
			'pressark_api_provider',
			__( 'API Provider', 'pressark' ),
			array( $this, 'render_provider_field' ),
			'pressark',
			'pressark_api_section'
		);

		add_settings_field(
			'pressark_api_key',
			__( 'API Key', 'pressark' ),
			array( $this, 'render_api_key_field' ),
			'pressark',
			'pressark_api_section'
		);

		add_settings_field(
			'pressark_model',
			__( 'Model', 'pressark' ),
			array( $this, 'render_model_field' ),
			'pressark',
			'pressark_api_section'
		);

		add_settings_field(
			'pressark_summarize_model',
			__( 'Back-Agent', 'pressark' ),
			array( $this, 'render_summarize_model_field' ),
			'pressark',
			'pressark_api_section'
		);

		// Billing section.
		add_settings_section(
			'pressark_license_section',
			__( 'Billing & Credits', 'pressark' ),
			array( $this, 'render_license_section' ),
			'pressark'
		);

		add_settings_field(
			'pressark_freemius_account',
			__( 'Account', 'pressark' ),
			array( $this, 'render_freemius_account_field' ),
			'pressark',
			'pressark_license_section'
		);

		add_settings_field(
			'pressark_credit_store',
			__( 'Credit Store', 'pressark' ),
			array( $this, 'render_credit_store_field' ),
			'pressark',
			'pressark_license_section'
		);

		// BYOK section.
		register_setting( 'pressark_settings', 'pressark_byok_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_byok_provider', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'openrouter',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_byok_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_byok_key' ),
			'default'           => '',
			'autoload'          => false,
		) );

		register_setting( 'pressark_settings', 'pressark_byok_model', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'gpt-5.4-mini',
			'autoload'          => false,
		) );

		add_settings_section(
			'pressark_byok_section',
			__( 'Bring Your Own Key (BYOK)', 'pressark' ),
			array( $this, 'render_byok_section' ),
			'pressark'
		);

		add_settings_field(
			'pressark_byok_enabled',
			__( 'Use My Own API Key', 'pressark' ),
			array( $this, 'render_byok_toggle_field' ),
			'pressark',
			'pressark_byok_section'
		);

		add_settings_field(
			'pressark_byok_provider',
			__( 'BYOK Provider', 'pressark' ),
			array( $this, 'render_byok_provider_field' ),
			'pressark',
			'pressark_byok_section'
		);

		add_settings_field(
			'pressark_byok_api_key',
			__( 'BYOK API Key', 'pressark' ),
			array( $this, 'render_byok_api_key_field' ),
			'pressark',
			'pressark_byok_section'
		);

		add_settings_field(
			'pressark_byok_model',
			__( 'BYOK Model', 'pressark' ),
			array( $this, 'render_byok_model_field' ),
			'pressark',
			'pressark_byok_section'
		);

		// Data Retention section (v2.6.0).
		register_setting( 'pressark_settings', 'pressark_retention_log_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 90,
		) );

		register_setting( 'pressark_settings', 'pressark_retention_chat_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 180,
		) );

		register_setting( 'pressark_settings', 'pressark_retention_ledger_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 365,
		) );

		register_setting( 'pressark_settings', 'pressark_retention_runs_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 30,
		) );

		register_setting( 'pressark_settings', 'pressark_retention_tasks_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 30,
		) );

		register_setting( 'pressark_settings', 'pressark_retention_automations_days', array(
			'type'              => 'integer',
			'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
			'default'           => 90,
		) );

		add_settings_section(
			'pressark_retention_section',
			__( 'Data Retention', 'pressark' ),
			array( $this, 'render_retention_section' ),
			'pressark'
		);

		add_settings_field(
			'pressark_retention_log_days',
			__( 'Action Log Retention', 'pressark' ),
			array( $this, 'render_retention_log_field' ),
			'pressark',
			'pressark_retention_section'
		);

		add_settings_field(
			'pressark_retention_chat_days',
			__( 'Chat History Retention', 'pressark' ),
			array( $this, 'render_retention_chat_field' ),
			'pressark',
			'pressark_retention_section'
		);

		add_settings_field(
			'pressark_retention_ledger_days',
			__( 'Cost Tracking History', 'pressark' ),
			array( $this, 'render_retention_ledger_field' ),
			'pressark',
			'pressark_retention_section'
		);

		add_settings_field(
			'pressark_retention_runs_days',
			__( 'Execution History', 'pressark' ),
			array( $this, 'render_retention_runs_field' ),
			'pressark',
			'pressark_retention_section'
		);

		add_settings_field(
			'pressark_retention_tasks_days',
			__( 'Task History', 'pressark' ),
			array( $this, 'render_retention_tasks_field' ),
			'pressark',
			'pressark_retention_section'
		);

		add_settings_field(
			'pressark_retention_automations_days',
			__( 'Archived Automation Retention', 'pressark' ),
			array( $this, 'render_retention_automations_field' ),
			'pressark',
			'pressark_retention_section'
		);

		// Content Index post-type setting (v4.2.0).
		register_setting( 'pressark_settings', 'pressark_indexed_post_types', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_indexed_post_types' ),
			'default'           => array(),
		) );

	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! PressArk_Capabilities::current_user_can_manage_settings() ) {
			return;
		}

		if ( PressArk_Onboarding::should_show() ) {
			PressArk_Onboarding::render();
			return;
		}

		// Handle profile regeneration action (with nonce verification).
		if ( isset( $_GET['action'] ) && 'refresh_profile' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			check_admin_referer( 'pressark_refresh_profile' );
			$profiler = new PressArk_Site_Profile();
			$profiler->generate();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Site profile regenerated successfully.', 'pressark' ) . '</p></div>';
		}

		// Handle content index rebuild action (with nonce verification).
		if ( isset( $_GET['action'] ) && 'rebuild_index' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			check_admin_referer( 'pressark_rebuild_index' );
			$index  = new PressArk_Content_Index();
			$index->schedule_full_rebuild();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Content index rebuild scheduled. It will process in the background.', 'pressark' ) . '</p></div>';
		}

		$harness_snapshot = PressArk_Harness_Readiness::get_snapshot();
		$capability_graph = is_array( $harness_snapshot['capability_graph'] ?? null )
			? (array) $harness_snapshot['capability_graph']
			: ( class_exists( 'PressArk_Capability_Health' )
				? PressArk_Capability_Health::get_snapshot( $harness_snapshot )
				: array() );
		$logo_url = $this->find_brand_image( array( 'WHITE-APP-LOGO', 'logo', 'icon', 'pressark-logo' ) );
		?>
		<div class="wrap">
			<div class="pressark-settings-header">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="PressArk">
				<?php endif; ?>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			</div>

			<?php $this->render_capability_health_notice( $capability_graph ); ?>
			<?php $this->render_extension_manifest_notices(); ?>
			<?php $this->render_usage_stats_box(); ?>
			<?php $this->render_plan_capabilities_section(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'pressark_settings' );
				do_settings_sections( 'pressark' );
				submit_button( __( 'Save Settings', 'pressark' ) );
				?>
			</form>

			<?php $this->render_harness_readiness_section( $harness_snapshot ); ?>
			<?php $this->render_extension_manifest_section(); ?>
			<?php $this->render_site_profile_section(); ?>
			<?php $this->render_site_playbook_section(); ?>
			<?php $this->render_content_index_section(); ?>
			<?php $this->render_permissions_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render harness readiness section on settings page.
	 */
	private function render_capability_health_notice( array $graph ): void {
		if ( empty( $graph ) || ! class_exists( 'PressArk_Capability_Health' ) ) {
			return;
		}

		$notices = PressArk_Capability_Health::collect_admin_notices( $graph );
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$class = match ( sanitize_key( (string) ( $notice['severity'] ?? 'warning' ) ) ) {
				'error'   => 'notice notice-error',
				'success' => 'notice notice-success',
				default   => 'notice notice-warning',
			};
			?>
			<div class="<?php echo esc_attr( $class ); ?>">
				<p>
					<strong><?php echo esc_html( (string) ( $notice['title'] ?? __( 'Capability health', 'pressark' ) ) ); ?>:</strong>
					<?php echo esc_html( (string) ( $notice['summary'] ?? '' ) ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render harness readiness section on settings page.
	 *
	 * @param array<string,mixed>|null $snapshot Optional readiness snapshot.
	 */
	private function render_harness_readiness_section( ?array $snapshot = null ): void {
		$snapshot       = is_array( $snapshot ) ? $snapshot : PressArk_Harness_Readiness::get_snapshot();
		$facets         = (array) ( $snapshot['facets'] ?? array() );
		$capability_graph = is_array( $snapshot['capability_graph'] ?? null ) ? (array) $snapshot['capability_graph'] : array();
		$capability_nodes = (array) ( $capability_graph['nodes'] ?? array() );
		$hidden_capability_rows = array(
			'tool_groups'     => (array) ( $capability_graph['hidden']['tool_groups'] ?? array() ),
			'resource_groups' => (array) ( $capability_graph['hidden']['resource_groups'] ?? array() ),
		);
		$problem_groups = PressArk_Harness_Readiness::summarize_problem_groups( $snapshot, 6 );
		$state          = sanitize_key( (string) ( $snapshot['state'] ?? 'degraded' ) );
		$badge          = $this->get_harness_state_styles( $state );
		$tier           = $this->format_harness_label( (string) ( $snapshot['tier'] ?? 'free' ) );
		$transport      = $this->format_harness_label( (string) ( $snapshot['transport_mode'] ?? 'proxy' ) );
		$rows           = array(
			'ai_core'       => __( 'AI Core', 'pressark' ),
			'billing'       => __( 'Billing', 'pressark' ),
			'provider'      => __( 'Provider', 'pressark' ),
			'site_profile'  => __( 'Site Profile', 'pressark' ),
			'content_index' => __( 'Content Index', 'pressark' ),
			'background'    => __( 'Background', 'pressark' ),
		);
		$capability_rows = array(
			'bank'               => __( 'Bank', 'pressark' ),
			'provider_transport' => __( 'Provider Transport', 'pressark' ),
			'site_profile'       => __( 'Site Profile Freshness', 'pressark' ),
			'content_index'      => __( 'Content Index Freshness', 'pressark' ),
			'woocommerce'        => __( 'WooCommerce Domain', 'pressark' ),
			'elementor'          => __( 'Elementor Domain', 'pressark' ),
			'seo_integrations'   => __( 'SEO Integrations', 'pressark' ),
		);
		?>
		<h2><?php esc_html_e( 'Harness Readiness', 'pressark' ); ?></h2>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
				<div style="max-width:760px;">
					<p style="margin:0 0 8px;color:#64748b;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e( 'Canonical Operator Snapshot', 'pressark' ); ?></p>
					<p style="margin:0;color:#0f172a;font-size:18px;font-weight:700;"><?php echo esc_html( (string) ( $snapshot['summary'] ?? __( 'PressArk readiness is being evaluated.', 'pressark' ) ) ); ?></p>
					<p style="margin:12px 0 0;color:#64748b;font-size:14px;">
						<?php
						printf(
							/* translators: 1: transport mode 2: plan tier 3: snapshot timestamp */
							esc_html__( 'Transport: %1$s · Tier: %2$s · Snapshot: %3$s', 'pressark' ),
							esc_html( $transport ),
							esc_html( $tier ),
							esc_html( $this->format_harness_timestamp( (string) ( $snapshot['generated_at'] ?? '' ) ) )
						);
						?>
					</p>
				</div>
				<span style="display:inline-flex;align-items:center;border:1px solid <?php echo esc_attr( $badge['border'] ); ?>;background:<?php echo esc_attr( $badge['background'] ); ?>;color:<?php echo esc_attr( $badge['text'] ); ?>;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:700;">
					<?php echo esc_html( $this->format_harness_label( $state ) ); ?>
				</span>
			</div>

			<table style="width:100%;border-collapse:collapse;margin-top:24px;">
				<thead>
					<tr>
						<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Surface', 'pressark' ); ?></th>
						<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'State', 'pressark' ); ?></th>
						<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Summary', 'pressark' ); ?></th>
						<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Signals', 'pressark' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $facet_key => $label ) : ?>
						<?php $facet = is_array( $facets[ $facet_key ] ?? null ) ? (array) $facets[ $facet_key ] : array(); ?>
						<?php $facet_state = sanitize_key( (string) ( $facet['state'] ?? 'degraded' ) ); ?>
						<?php $facet_badge = $this->get_harness_state_styles( $facet_state ); ?>
						<tr>
							<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#0f172a;font-size:14px;font-weight:600;"><?php echo esc_html( $label ); ?></td>
							<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;">
								<span style="display:inline-flex;align-items:center;border:1px solid <?php echo esc_attr( $facet_badge['border'] ); ?>;background:<?php echo esc_attr( $facet_badge['background'] ); ?>;color:<?php echo esc_attr( $facet_badge['text'] ); ?>;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;">
									<?php echo esc_html( $this->format_harness_label( $facet_state ) ); ?>
								</span>
							</td>
							<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.5;"><?php echo esc_html( (string) ( $facet['summary'] ?? '' ) ); ?></td>
							<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;line-height:1.5;"><?php echo esc_html( $this->get_harness_facet_detail( $facet_key, $facet ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $capability_nodes ) ) : ?>
				<div style="margin-top:24px;">
					<p style="margin:0 0 10px;color:#0f172a;font-size:14px;font-weight:700;"><?php esc_html_e( 'Capability / Provider Health', 'pressark' ); ?></p>
					<table style="width:100%;border-collapse:collapse;">
						<thead>
							<tr>
								<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Domain', 'pressark' ); ?></th>
								<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'State', 'pressark' ); ?></th>
								<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Status', 'pressark' ); ?></th>
								<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Summary', 'pressark' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $capability_rows as $node_key => $label ) : ?>
								<?php $node = is_array( $capability_nodes[ $node_key ] ?? null ) ? (array) $capability_nodes[ $node_key ] : array(); ?>
								<?php if ( empty( $node ) ) { continue; } ?>
								<?php $node_state = sanitize_key( (string) ( $node['state'] ?? 'healthy' ) ); ?>
								<?php $node_badge = $this->get_harness_state_styles( $node_state ); ?>
								<tr>
									<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#0f172a;font-size:14px;font-weight:600;"><?php echo esc_html( $label ); ?></td>
									<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;">
										<span style="display:inline-flex;align-items:center;border:1px solid <?php echo esc_attr( $node_badge['border'] ); ?>;background:<?php echo esc_attr( $node_badge['background'] ); ?>;color:<?php echo esc_attr( $node_badge['text'] ); ?>;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;">
											<?php echo esc_html( $this->format_harness_label( $node_state ) ); ?>
										</span>
									</td>
									<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#64748b;font-size:13px;line-height:1.5;"><?php echo esc_html( $this->format_harness_label( (string) ( $node['status'] ?? '' ) ) ); ?></td>
									<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.5;"><?php echo esc_html( (string) ( $node['summary'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $problem_groups ) ) : ?>
				<div style="margin-top:24px;padding:16px 18px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
					<p style="margin:0 0 10px;color:#0f172a;font-size:14px;font-weight:700;"><?php esc_html_e( 'Tool Group Dependency Gaps', 'pressark' ); ?></p>
					<ul style="margin:0;padding-left:18px;color:#475569;font-size:13px;line-height:1.6;">
						<?php foreach ( $problem_groups as $problem ) : ?>
							<li>
								<strong><?php echo esc_html( (string) ( $problem['label'] ?? '' ) ); ?>:</strong>
								<?php echo esc_html( (string) ( $problem['summary'] ?? '' ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $hidden_capability_rows['tool_groups'] ) || ! empty( $hidden_capability_rows['resource_groups'] ) ) : ?>
				<div style="margin-top:24px;padding:16px 18px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
					<p style="margin:0 0 10px;color:#0f172a;font-size:14px;font-weight:700;"><?php esc_html_e( 'Hidden Capability Surfaces', 'pressark' ); ?></p>
					<ul style="margin:0;padding-left:18px;color:#475569;font-size:13px;line-height:1.6;">
						<?php foreach ( array_slice( $hidden_capability_rows['tool_groups'], 0, 4 ) as $hidden_group ) : ?>
							<li>
								<strong><?php echo esc_html( (string) ( $hidden_group['label'] ?? __( 'Tool group', 'pressark' ) ) ); ?>:</strong>
								<?php echo esc_html( (string) ( $hidden_group['summary'] ?? '' ) ); ?>
							</li>
						<?php endforeach; ?>
						<?php foreach ( array_slice( $hidden_capability_rows['resource_groups'], 0, 4 ) as $hidden_group ) : ?>
							<li>
								<strong><?php echo esc_html( (string) ( $hidden_group['label'] ?? __( 'Resource group', 'pressark' ) ) ); ?>:</strong>
								<?php echo esc_html( (string) ( $hidden_group['summary'] ?? '' ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_harness_state_styles( string $state ): array {
		return match ( $state ) {
			'auth_blocked',
			'blocked'  => array(
				'background' => '#fef2f2',
				'border'     => '#fecaca',
				'text'       => '#b91c1c',
			),
			'degraded' => array(
				'background' => '#fffbeb',
				'border'     => '#fde68a',
				'text'       => '#b45309',
			),
			'absent'   => array(
				'background' => '#f8fafc',
				'border'     => '#cbd5e1',
				'text'       => '#475569',
			),
			default    => array(
				'background' => '#f0fdf4',
				'border'     => '#86efac',
				'text'       => '#166534',
			),
		};
	}

	private function format_harness_label( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return __( 'Unknown', 'pressark' );
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
	}

	private function format_harness_timestamp( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return __( 'Never', 'pressark' );
		}
		if ( str_starts_with( $value, '1970-01-01' ) ) {
			return __( 'Never', 'pressark' );
		}
		if ( in_array( $value, array( 'Never', 'Disabled' ), true ) ) {
			return $value;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return $value;
		}

		return wp_date( 'M j, Y g:i a', $timestamp );
	}

	private function get_harness_facet_detail( string $facet_key, array $facet ): string {
		switch ( $facet_key ) {
			case 'ai_core':
				return sprintf(
					/* translators: 1: transport mode 2: billing mode */
					__( 'Transport: %1$s · Billing mode: %2$s', 'pressark' ),
					$this->format_harness_label( (string) ( $facet['transport_mode'] ?? '' ) ),
					$this->format_harness_label( (string) ( $facet['billing_mode'] ?? '' ) )
				);

			case 'billing':
				return sprintf(
					/* translators: 1: handshake state 2: service state 3: last bank contact */
					__( 'Handshake: %1$s · Service: %2$s · Last bank contact: %3$s', 'pressark' ),
					$this->format_harness_label( (string) ( $facet['handshake_state'] ?? '' ) ),
					$this->format_harness_label( (string) ( $facet['service_state'] ?? '' ) ),
					$this->format_harness_timestamp( (string) ( $facet['last_successful_contact_at'] ?? '' ) )
				);

			case 'provider':
				if ( 'proxy' === (string) ( $facet['mode'] ?? '' ) ) {
					return sprintf(
						/* translators: 1: provider 2: model */
						__( 'Provider: %1$s · Model: %2$s · Credentials: Managed by PressArk', 'pressark' ),
						$this->format_harness_label( (string) ( $facet['provider'] ?? '' ) ),
						(string) ( $facet['model'] ?? __( 'Auto', 'pressark' ) )
					);
				}

				$key_status = ! empty( $facet['key_saved'] ) && ! empty( $facet['key_valid'] )
					? __( 'Saved', 'pressark' )
					: __( 'Needs attention', 'pressark' );

				return sprintf(
					/* translators: 1: provider 2: model 3: key status */
					__( 'Provider: %1$s · Model: %2$s · Credentials: %3$s', 'pressark' ),
					$this->format_harness_label( (string) ( $facet['provider'] ?? '' ) ),
					(string) ( $facet['model'] ?? __( 'Auto', 'pressark' ) ),
					$key_status
				);

			case 'site_profile':
				return sprintf(
					/* translators: 1: generation timestamp 2: age in hours */
					__( 'Generated: %1$s · Age: %2$s hours', 'pressark' ),
					$this->format_harness_timestamp( (string) ( $facet['generated_at'] ?? '' ) ),
					number_format_i18n( (float) ( $facet['age_hours'] ?? 0 ), 1 )
				);

			case 'content_index':
				return sprintf(
					/* translators: 1: chunk count 2: last sync 3: stale percentage */
					__( 'Chunks: %1$s · Last sync: %2$s · Stale: %3$s%%', 'pressark' ),
					number_format_i18n( (int) ( $facet['total_chunks'] ?? 0 ) ),
					$this->format_harness_timestamp( (string) ( $facet['last_sync_raw'] ?? $facet['last_sync'] ?? '' ) ),
					number_format_i18n( (float) ( $facet['stale_percent'] ?? 0 ), 1 )
				);

			case 'background':
				$missing_hooks = (array) ( $facet['missing_hooks'] ?? array() );
				$hook_summary  = empty( $missing_hooks )
					? __( 'None', 'pressark' )
					: implode( ', ', array_map( array( $this, 'format_harness_label' ), array_map( 'strval', $missing_hooks ) ) );

				return sprintf(
					/* translators: 1: backend name 2: queued task count 3: running task count 4: missing hook summary */
					__( 'Backend: %1$s · Queue: %2$s queued / %3$s running · Missing hooks: %4$s', 'pressark' ),
					$this->format_harness_label( (string) ( $facet['backend'] ?? '' ) ),
					number_format_i18n( (int) ( $facet['queued_tasks'] ?? 0 ) ),
					number_format_i18n( (int) ( $facet['running_tasks'] ?? 0 ) ),
					$hook_summary
				);
		}

		return '';
	}

	/**
	 * Render notices for active extensions that need review.
	 */
	private function render_extension_manifest_notices(): void {
		if ( ! class_exists( 'PressArk_Extension_Manifests' ) ) {
			return;
		}

		$reports = array_filter(
			PressArk_Extension_Manifests::list_installed(),
			static function ( array $report ): bool {
				return ! empty( $report['active'] ) && ( ! empty( $report['errors'] ) || ! empty( $report['warnings'] ) );
			}
		);

		foreach ( array_slice( array_values( $reports ), 0, 3 ) as $report ) {
			$class = ! empty( $report['errors'] ) ? 'notice notice-error' : 'notice notice-warning';
			$issue = sanitize_text_field( (string) ( $report['errors'][0] ?? $report['warnings'][0] ?? '' ) );
			$summary = sanitize_text_field( (string) ( $report['trust_warning'] ?? '' ) );
			?>
			<div class="<?php echo esc_attr( $class ); ?>">
				<p>
					<strong><?php echo esc_html( (string) ( $report['plugin_name'] ?? __( 'Extension manifest', 'pressark' ) ) ); ?>:</strong>
					<?php echo esc_html( $issue ?: $summary ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render the extension manifest overview on the settings page.
	 */
	private function render_extension_manifest_section(): void {
		if ( ! class_exists( 'PressArk_Extension_Manifests' ) ) {
			return;
		}

		$reports = PressArk_Extension_Manifests::list_installed();
		?>
		<h2><?php esc_html_e( 'Extension Manifests', 'pressark' ); ?></h2>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<p style="margin:0 0 18px;color:#475569;font-size:14px;line-height:1.6;">
				<?php esc_html_e( 'Third-party PressArk add-ons can declare operations, resources, trust class, prompt-injection exposure, verification expectations, and required dependencies in a formal manifest. PressArk validates that manifest before enabling the extension through its own plugin controls.', 'pressark' ); ?>
			</p>

			<?php if ( empty( $reports ) ) : ?>
				<p style="margin:0;color:#64748b;font-size:14px;"><?php esc_html_e( 'No PressArk extension manifests were detected in the installed plugin set.', 'pressark' ); ?></p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Extension', 'pressark' ); ?></th>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Status', 'pressark' ); ?></th>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Declared Surface', 'pressark' ); ?></th>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Trust', 'pressark' ); ?></th>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Requirements', 'pressark' ); ?></th>
							<th style="padding:10px 12px;border-bottom:2px solid #e2e8f0;text-align:left;color:#0f172a;font-size:13px;"><?php esc_html_e( 'Validation', 'pressark' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $reports as $report ) : ?>
							<?php
							$manifest = is_array( $report['manifest'] ?? null ) ? $report['manifest'] : array();
							$status   = sanitize_key( (string) ( $report['status'] ?? 'review' ) );
							$badge    = match ( $status ) {
								'blocked' => array(
									'background' => '#fef2f2',
									'border'     => '#fecaca',
									'text'       => '#b91c1c',
								),
								'validated' => array(
									'background' => '#f0fdf4',
									'border'     => '#86efac',
									'text'       => '#166534',
								),
								default => array(
									'background' => '#fffbeb',
									'border'     => '#fde68a',
									'text'       => '#b45309',
								),
							};
							$requires = is_array( $manifest['requires'] ?? null ) ? $manifest['requires'] : array();
							$requirement_bits = array();
							if ( ! empty( $requires['plugins'] ) ) {
								$requirement_bits[] = sprintf(
									/* translators: %d: plugin dependency count */
									_n( '%d plugin dependency', '%d plugin dependencies', count( (array) $requires['plugins'] ), 'pressark' ),
									count( (array) $requires['plugins'] )
								);
							}
							if ( ! empty( $requires['capabilities'] ) ) {
								$requirement_bits[] = sprintf(
									/* translators: %d: capability count */
									_n( '%d capability', '%d capabilities', count( (array) $requires['capabilities'] ), 'pressark' ),
									count( (array) $requires['capabilities'] )
								);
							}
							foreach ( array( 'pressark_min_version', 'wordpress_min_version', 'php_min_version' ) as $version_key ) {
								if ( ! empty( $requires[ $version_key ] ) ) {
									$requirement_bits[] = str_replace( '_', ' ', $version_key ) . ': ' . $requires[ $version_key ];
								}
							}
							$validation_text = sanitize_text_field(
								(string) (
									$report['errors'][0]
									?? $report['warnings'][0]
									?? $report['trust_warning']
									?? __( 'Manifest validated with no review notes.', 'pressark' )
								)
							);
							?>
							<tr>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#0f172a;font-size:14px;line-height:1.5;">
									<strong><?php echo esc_html( (string) ( $report['plugin_name'] ?? '' ) ); ?></strong>
									<div style="color:#64748b;font-size:12px;margin-top:4px;">
										<?php echo esc_html( (string) ( $report['plugin_file'] ?? '' ) ); ?>
									</div>
								</td>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;">
									<span style="display:inline-flex;align-items:center;border:1px solid <?php echo esc_attr( $badge['border'] ); ?>;background:<?php echo esc_attr( $badge['background'] ); ?>;color:<?php echo esc_attr( $badge['text'] ); ?>;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;">
										<?php echo esc_html( ucfirst( $status ) ); ?>
									</span>
									<div style="color:#64748b;font-size:12px;margin-top:6px;">
										<?php echo ! empty( $report['active'] ) ? esc_html__( 'Active', 'pressark' ) : esc_html__( 'Inactive', 'pressark' ); ?>
									</div>
								</td>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.6;">
									<?php
									printf(
										/* translators: 1: operation count 2: resource count */
										esc_html__( '%1$d operations, %2$d resources', 'pressark' ),
										count( (array) ( $manifest['operations'] ?? array() ) ),
										count( (array) ( $manifest['resources'] ?? array() ) )
									);
									?>
									<?php if ( ! empty( $manifest['self_test']['hook'] ) || ! empty( $manifest['self_test']['callback'] ) ) : ?>
										<div style="color:#64748b;font-size:12px;margin-top:4px;"><?php esc_html_e( 'Self-test declared', 'pressark' ); ?></div>
									<?php endif; ?>
								</td>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.6;">
									<?php echo esc_html( (string) ( $manifest['trust']['class'] ?? 'derived_summary' ) ); ?>
									<div style="color:#64748b;font-size:12px;margin-top:4px;">
										<?php echo esc_html( (string) ( $manifest['trust']['prompt_injection_class'] ?? 'guarded' ) ); ?>
										<?php if ( ! empty( $manifest['billing_sensitive'] ) ) : ?>
											<?php esc_html_e( ' | billing-sensitive', 'pressark' ); ?>
										<?php endif; ?>
									</div>
								</td>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.6;">
									<?php echo esc_html( empty( $requirement_bits ) ? __( 'None declared', 'pressark' ) : implode( ' | ', $requirement_bits ) ); ?>
								</td>
								<td style="padding:14px 12px;border-bottom:1px solid #f1f5f9;color:#475569;font-size:13px;line-height:1.6;">
									<?php echo esc_html( $validation_text ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render site profile section on settings page.
	 */
	private function render_site_profile_section(): void {
		$profiler = new PressArk_Site_Profile();
		$profile  = $profiler->get();
		?>
		<h2><?php esc_html_e( 'Site Profile', 'pressark' ); ?></h2>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<?php if ( $profile ) :
				$id  = $profile['identity'];
				$dna = $profile['content_dna'];
			?>
			<table style="max-width: 600px; border-collapse: collapse; width: 100%;">
				<tr><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; width: 220px;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Detected Industry', 'pressark' ); ?></strong></td><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo esc_html( $id['detected_industry'] ); ?></td></tr>
				<tr><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Content Tone', 'pressark' ); ?></strong></td><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo esc_html( $dna['tone'] ); ?></td></tr>
				<tr><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Writing Voice', 'pressark' ); ?></strong></td><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo esc_html( $dna['dominant_voice'] ); ?></td></tr>
				<tr><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Avg Page Length', 'pressark' ); ?></strong></td><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo intval( $dna['avg_length'] ); ?> <?php esc_html_e( 'words', 'pressark' ); ?></td></tr>
				<tr><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Pages Analyzed', 'pressark' ); ?></strong></td><td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo intval( $dna['total_pages'] ); ?></td></tr>
				<tr><td style="padding: 14px 0;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Last Generated', 'pressark' ); ?></strong></td><td style="padding: 14px 0; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo esc_html( $profile['generated_at'] ); ?></td></tr>
			</table>
			<p style="margin-top: 24px;">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pressark&action=refresh_profile' ), 'pressark_refresh_profile' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Regenerate Profile', 'pressark' ); ?></a>
				<span class="description" style="margin-left:12px;"><?php esc_html_e( "Re-scans all content to update the AI's understanding of your site.", 'pressark' ); ?></span>
			</p>
			<?php else : ?>
			<p style="color:#64748b; font-size:14px; margin-bottom:24px;"><?php esc_html_e( 'Site profile has not been generated yet. It will be created automatically, or you can trigger it manually.', 'pressark' ); ?></p>
			<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pressark&action=refresh_profile' ), 'pressark_refresh_profile' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Generate Profile Now', 'pressark' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Site Playbook section on settings page.
	 */
	private function render_site_playbook_section(): void {
		$entries      = PressArk_Site_Playbook::get_all();
		$task_labels  = PressArk_Site_Playbook::task_labels();
		$group_labels = PressArk_Site_Playbook::tool_group_labels();
		?>
		<h2><?php esc_html_e( 'Site Playbook', 'pressark' ); ?></h2>
		<div id="pressark-site-playbook-editor" data-max-entries="<?php echo esc_attr( (string) PressArk_Site_Playbook::MAX_ENTRIES ); ?>" style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<p style="margin-top:0;color:#475569;font-size:14px;max-width:880px;line-height:1.7;">
				<?php esc_html_e( 'Store durable operator-authored instructions here. This layer is separate from the inferred site profile and lightweight site notes, and PressArk only injects the most relevant entries for the current task and tool group.', 'pressark' ); ?>
			</p>
			<p class="description" style="margin-bottom:20px;max-width:880px;">
				<?php esc_html_e( 'Good playbook entries cover brand guardrails, canonical truth sources, high-risk workflows, editorial constraints, and approval preferences. Keep each entry short, durable, and specific.', 'pressark' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'pressark_settings' ); ?>
				<input type="hidden" name="<?php echo esc_attr( PressArk_Site_Playbook::OPTION_KEY ); ?>[_sentinel]" value="1">

				<div data-playbook-empty <?php echo ! empty( $entries ) ? 'hidden' : ''; ?> style="padding:18px 20px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;font-size:14px;margin-bottom:18px;">
					<?php esc_html_e( 'No playbook entries yet. Add durable instructions PressArk should remember across sessions.', 'pressark' ); ?>
				</div>

				<div data-playbook-list>
					<?php foreach ( $entries as $index => $entry ) : ?>
						<?php $this->render_site_playbook_entry_fields( $index, $entry, $task_labels, $group_labels ); ?>
					<?php endforeach; ?>
				</div>

				<p style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:20px 0 0;">
					<button type="button" class="button" data-playbook-add><?php esc_html_e( 'Add Entry', 'pressark' ); ?></button>
					<?php submit_button( __( 'Save Playbook', 'pressark' ), 'primary', 'submit', false ); ?>
					<span class="description">
						<?php
						printf(
							/* translators: %d: number of entries kept in prompt recall at once. */
							esc_html__( 'Prompt recall is capped to the most relevant %d entries per run.', 'pressark' ),
							PressArk_Site_Playbook::MAX_PROMPT_ENTRIES
						);
						?>
					</span>
				</p>
			</form>

			<div id="pressark-site-playbook-template" hidden>
				<?php $this->render_site_playbook_entry_fields( '__INDEX__', array(), $task_labels, $group_labels ); ?>
			</div>

			<script>
			(function() {
				const root = document.getElementById('pressark-site-playbook-editor');
				if (!root) {
					return;
				}

				const list = root.querySelector('[data-playbook-list]');
				const empty = root.querySelector('[data-playbook-empty]');
				const addButton = root.querySelector('[data-playbook-add]');
				const template = root.querySelector('#pressark-site-playbook-template');
				const maxEntries = parseInt(root.getAttribute('data-max-entries') || '12', 10);
				let nextIndex = list ? list.querySelectorAll('[data-playbook-entry]').length : 0;

				const syncState = function() {
					const count = list ? list.querySelectorAll('[data-playbook-entry]').length : 0;
					if (empty) {
						empty.hidden = count > 0;
					}
					if (addButton) {
						addButton.disabled = count >= maxEntries;
					}
				};

				if (addButton && list && template) {
					addButton.addEventListener('click', function() {
						if (list.querySelectorAll('[data-playbook-entry]').length >= maxEntries) {
							return;
						}

						const html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
						list.insertAdjacentHTML('beforeend', html);
						syncState();
					});
				}

				if (list) {
					list.addEventListener('click', function(event) {
						const removeButton = event.target.closest('[data-playbook-remove]');
						if (!removeButton) {
							return;
						}

						event.preventDefault();
						const card = removeButton.closest('[data-playbook-entry]');
						if (card) {
							card.remove();
							syncState();
						}
					});
				}

				syncState();
			})();
			</script>
		</div>
		<?php
	}

	/**
	 * Render one Site Playbook entry editor card.
	 *
	 * @param int|string $index Entry index placeholder.
	 * @param array      $entry Playbook entry data.
	 * @param array      $task_labels Task label map.
	 * @param array      $group_labels Tool group label map.
	 */
	private function render_site_playbook_entry_fields( $index, array $entry, array $task_labels, array $group_labels ): void {
		$name_prefix     = PressArk_Site_Playbook::OPTION_KEY . '[' . $index . ']';
		$selected_tasks  = array_map( 'sanitize_key', (array) ( $entry['task_types'] ?? array( 'all' ) ) );
		$selected_groups = array_map( 'sanitize_key', (array) ( $entry['tool_groups'] ?? array( 'all' ) ) );
		?>
		<div data-playbook-entry style="border:1px solid #e2e8f0;border-radius:14px;padding:20px 20px 18px;margin-bottom:16px;background:#fcfdff;">
			<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:16px;">
				<strong style="color:#0f172a;font-size:14px;"><?php esc_html_e( 'Playbook Entry', 'pressark' ); ?></strong>
				<button type="button" class="button-link-delete" data-playbook-remove><?php esc_html_e( 'Remove', 'pressark' ); ?></button>
			</div>

			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[id]" value="<?php echo esc_attr( (string) ( $entry['id'] ?? '' ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[updated_at]" value="<?php echo esc_attr( (string) ( $entry['updated_at'] ?? '' ) ); ?>">

			<p style="margin:0 0 14px;">
				<label style="display:block;font-weight:600;color:#0f172a;margin-bottom:6px;" for="<?php echo esc_attr( 'pressark-playbook-title-' . $index ); ?>">
					<?php esc_html_e( 'Label', 'pressark' ); ?>
				</label>
				<input
					type="text"
					class="regular-text"
					id="<?php echo esc_attr( 'pressark-playbook-title-' . $index ); ?>"
					name="<?php echo esc_attr( $name_prefix ); ?>[title]"
					value="<?php echo esc_attr( (string) ( $entry['title'] ?? '' ) ); ?>"
					placeholder="<?php esc_attr_e( 'Brand guardrails', 'pressark' ); ?>"
					style="width:100%;max-width:none;"
				>
			</p>

			<p style="margin:0 0 18px;">
				<label style="display:block;font-weight:600;color:#0f172a;margin-bottom:6px;" for="<?php echo esc_attr( 'pressark-playbook-body-' . $index ); ?>">
					<?php esc_html_e( 'Instruction', 'pressark' ); ?>
				</label>
				<textarea
					id="<?php echo esc_attr( 'pressark-playbook-body-' . $index ); ?>"
					name="<?php echo esc_attr( $name_prefix ); ?>[body]"
					rows="4"
					placeholder="<?php esc_attr_e( 'Example: Never change pricing, checkout, or shipping copy without explicit approval from the operator.', 'pressark' ); ?>"
					style="width:100%;max-width:none;"
				><?php echo esc_textarea( (string) ( $entry['body'] ?? '' ) ); ?></textarea>
			</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;">
				<div>
					<strong style="display:block;color:#0f172a;font-size:13px;margin-bottom:8px;"><?php esc_html_e( 'Task Types', 'pressark' ); ?></strong>
					<div style="display:flex;flex-wrap:wrap;gap:8px 14px;">
						<?php foreach ( $task_labels as $task_key => $task_label ) : ?>
							<label style="display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:13px;">
								<input
									type="checkbox"
									name="<?php echo esc_attr( $name_prefix ); ?>[task_types][]"
									value="<?php echo esc_attr( $task_key ); ?>"
									<?php checked( in_array( $task_key, $selected_tasks, true ) ); ?>
								>
								<?php echo esc_html( $task_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div>
					<strong style="display:block;color:#0f172a;font-size:13px;margin-bottom:8px;"><?php esc_html_e( 'Tool Groups', 'pressark' ); ?></strong>
					<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px 14px;max-height:180px;overflow:auto;padding-right:6px;">
						<?php foreach ( $group_labels as $group_key => $group_label ) : ?>
							<label style="display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:13px;">
								<input
									type="checkbox"
									name="<?php echo esc_attr( $name_prefix ); ?>[tool_groups][]"
									value="<?php echo esc_attr( $group_key ); ?>"
									<?php checked( in_array( $group_key, $selected_groups, true ) ); ?>
								>
								<?php echo esc_html( $group_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render content index section on settings page.
	 */
	private function render_content_index_section(): void {
		$index = new PressArk_Content_Index();
		$stats = $index->get_stats();
		?>
		<h2><?php esc_html_e( 'Content Index', 'pressark' ); ?></h2>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<table style="max-width: 600px; border-collapse: collapse; width: 100%;">
				<tr>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; width: 220px;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Posts Indexed', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo intval( $stats['total_posts_indexed'] ?? 0 ); ?></td>
				</tr>
				<tr>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Content Segments', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo intval( $stats['total_chunks'] ?? 0 ); ?></td>
				</tr>
				<tr>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Total Words', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo number_format( intval( $stats['total_words'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Last Sync', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 600; font-size: 14px;"><?php echo esc_html( $stats['last_sync'] ?? __( 'Never', 'pressark' ) ); ?></td>
				</tr>
				<?php if ( ! empty( $stats['by_type'] ) ) : ?>
				<tr>
					<td style="padding: 14px 0; vertical-align: top;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Breakdown', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0; color: #0f172a; font-weight: 600; font-size: 14px;">
						<?php foreach ( $stats['by_type'] as $type => $counts ) : ?>
							<div style="margin-bottom:6px;"><?php echo esc_html( $counts['posts'] ) . ' ' . esc_html( $type ) . 's'; ?></div>
						<?php endforeach; ?>
					</td>
				</tr>
				<?php endif; ?>
			<?php
			// v4.2.0: Post type selection checkboxes.
			$available_types = $index->get_registered_indexable_types();
			$selected_types  = get_option( 'pressark_indexed_post_types', array() );
			$active_types    = $index->get_indexable_post_types();
			if ( ! empty( $available_types ) ) :
			?>
				<tr>
					<td style="padding: 14px 0; vertical-align: top;"><strong style="color: #64748b; font-weight: 500; font-size: 13px;"><?php esc_html_e( 'Indexed Types', 'pressark' ); ?></strong></td>
					<td style="padding: 14px 0;">
						<input type="hidden" name="pressark_indexed_post_types[]" value="">
						<?php foreach ( $available_types as $slug => $label ) :
							$checked = in_array( $slug, $active_types, true );
						?>
							<label style="display: block; margin-bottom: 4px;">
								<input type="checkbox" name="pressark_indexed_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>>
								<?php echo esc_html( $label ); ?> <code style="font-size: 11px; color: #94a3b8;"><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
						<p class="description" style="margin-top: 8px;"><?php esc_html_e( 'Uncheck types you don\'t need in AI context to reduce index size and cron load. Uncheck every type to disable indexing completely. Changes trigger a full rebuild or clear the index if disabled.', 'pressark' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			</table>
			<p style="margin-top: 24px;">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pressark&action=rebuild_index' ), 'pressark_rebuild_index' ) ); ?>"
				   class="button"
				   onclick="return confirm('<?php echo esc_js( __( 'Rebuild the entire content index?', 'pressark' ) ); ?>');">
					<?php esc_html_e( 'Rebuild Index', 'pressark' ); ?>
				</a>
				<span class="description" style="margin-left:12px;"><?php esc_html_e( 'Re-indexes all published content. Usually not needed — the index auto-syncs when content changes.', 'pressark' ); ?></span>
			</p>
			<p class="description" style="margin-top: 12px; max-width: 600px;">
				<?php esc_html_e( 'The content index lets PressArk reference your actual site content when generating replies, creating new content, or answering questions. It syncs incrementally when you publish or edit content.', 'pressark' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Sanitize license key via activate_license() which validates UUID + creates HMAC.
	 */
	public function sanitize_license_key( $value ): string {
		$value = sanitize_text_field( $value );

		if ( empty( $value ) ) {
			$license = new PressArk_License();
			$license->deactivate();
			return '';
		}

		// Must be UUID v4 format.
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value ) ) {
			add_settings_error( 'pressark_license_key', 'license_invalid', __( 'Invalid license key format. Must be a valid UUID.', 'pressark' ), 'error' );
			return get_option( 'pressark_license_key', '' );
		}

		$license = new PressArk_License();
		$data    = $license->activate( $value );

		if ( ! empty( $data['valid'] ) ) {
			add_settings_error( 'pressark_license_key', 'license_activated', __( 'License activated successfully!', 'pressark' ), 'success' );
			return $value;
		}

		add_settings_error( 'pressark_license_key', 'license_invalid', $data['error'] ?? __( 'License validation failed.', 'pressark' ), 'error' );
		return get_option( 'pressark_license_key', '' );
	}

	/**
	 * Sanitize the Site Playbook structured setting.
	 *
	 * @param mixed $value Raw submitted playbook entries.
	 * @return array<int,array<string,mixed>>
	 */
	public function sanitize_site_playbook( $value ): array {
		return PressArk_Site_Playbook::sanitize_option( $value );
	}

	/**
	 * Sanitize bundled API key — encrypt on save, keep existing if empty.
	 *
	 * The is_sodium_encrypted() guard prevents double encryption when WordPress's
	 * update_option() delegates to add_option() for new options — both call
	 * sanitize_option(), so without the guard the value is encrypted twice.
	 */
	public function sanitize_api_key( $value ): string {
		$value = sanitize_text_field( $value );
		if ( empty( $value ) ) {
			// Keep existing encrypted key when field submitted empty.
			return get_option( 'pressark_api_key', '' );
		}
		if ( PressArk_Usage_Tracker::is_sodium_encrypted( $value ) ) {
			return $value;
		}
		return PressArk_Usage_Tracker::encrypt_value( $value );
	}

	public function render_api_section(): void {
		echo '<p>' . esc_html__( 'Configure your AI provider and API credentials.', 'pressark' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'PressArk connects to your chosen AI provider (OpenRouter, OpenAI, Anthropic, DeepSeek, or Gemini) and tracks your credit usage. Use Bring Your Own Key (BYOK) below to use your own API key instead of bundled credits.', 'pressark' ) . '</p>';
	}

	public function render_license_section(): void {
		$license = new PressArk_License();
		$is_pro  = $license->is_pro();
		$tier    = $license->get_tier();

		$tier_label = PressArk_Entitlements::tier_label( $tier );
		?>
		<div style="background:#f8fafc;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin-bottom:20px;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<?php if ( $is_pro ) : ?>
				<strong style="color:#0f172a;font-size:15px;display:flex;align-items:center;gap:8px;"><span style="color:#10b981;font-size:18px;"><?php echo pressark_icon( 'zap', 18 ); ?></span> <?php
				/* translators: %s: plan tier label (e.g., Pro, Agency) */
				printf( esc_html__( '%s Plan Active', 'pressark' ), esc_html( $tier_label ) );
			?></strong>
				<p style="margin:12px 0 0;font-size:14px;color:#475569;"><?php esc_html_e( 'Billing, trials, subscription changes, and site activations are managed through Freemius.', 'pressark' ); ?></p>
			<?php else : ?>
				<strong style="font-size:14px; color:#0f172a;"><?php esc_html_e( 'Plan Pricing', 'pressark' ); ?></strong>
				<p style="margin:8px 0 4px; color:#64748b;"><?php esc_html_e( 'Free: 100K credits/mo · Pro ($19): 5M · Team ($49): 15M · Agency ($99): 40M · Enterprise ($199): 100M', 'pressark' ); ?></p>
				<p style="margin:4px 0 0; color:#64748b;"><?php esc_html_e( 'Upgrade in Freemius to unlock premium models, automations, more sites, and more monthly credits.', 'pressark' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		// Token Budget Display
		$this->render_token_budget_display();
	}

	public function render_provider_field(): void {
		if ( PressArk_AI_Connector::is_proxy_mode() ) {
			echo '<p><strong>' . esc_html__( 'PressArk AI Service', 'pressark' ) . '</strong></p>';
			echo '<p class="description">' . esc_html__( 'AI calls are securely routed through the PressArk service. Use BYOK below to use your own API key instead.', 'pressark' ) . '</p>';
			return;
		}

		$value = get_option( 'pressark_api_provider', 'openrouter' );
		$providers = array(
			'openrouter' => __( 'OpenRouter', 'pressark' ),
			'openai'     => __( 'OpenAI', 'pressark' ),
			'anthropic'  => __( 'Anthropic', 'pressark' ),
			'deepseek'   => __( 'DeepSeek Direct', 'pressark' ),
			'gemini'     => __( 'Google Gemini', 'pressark' ),
		);
		echo '<select name="pressark_api_provider" id="pressark_api_provider">';
		foreach ( $providers as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function render_api_key_field(): void {
		if ( PressArk_AI_Connector::is_proxy_mode() ) {
			$this->render_bank_status();
			return;
		}

		$has_key = ! empty( get_option( 'pressark_api_key', '' ) );
		printf(
			'<input type="password" name="pressark_api_key" id="pressark_api_key" value="" class="regular-text" autocomplete="off" placeholder="%s" />',
			$has_key ? esc_attr__( 'Key saved (enter new to replace)', 'pressark' ) : esc_attr__( 'Enter your API key', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Your API key from the selected provider. Encrypted before storage.', 'pressark' ) . '</p>';
	}

	/**
	 * Render bank connectivity status indicator.
	 *
	 * @since 5.0.0
	 */
	private function render_bank_status(): void {
		$bank_url = defined( 'PRESSARK_TOKEN_BANK_URL' )
			? PRESSARK_TOKEN_BANK_URL
			: get_option( 'pressark_token_bank_url', 'https://tokens.pressark.com' );

		$response   = wp_safe_remote_get( trailingslashit( $bank_url ) . 'health', array( 'timeout' => 3 ) );
		$is_healthy = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );

		echo '<p>';
		if ( $is_healthy ) {
			echo '<span style="color:#00a32a;">' . pressark_icon( 'statusDot', 10 ) . '</span> ' . esc_html__( 'Connected to PressArk AI service', 'pressark' );
		} else {
			echo '<span style="color:#d63638;">' . pressark_icon( 'statusDot', 10 ) . '</span> ' . esc_html__( 'Unable to reach PressArk AI service', 'pressark' );
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'No API key is stored locally. All AI calls are routed through the secure PressArk service.', 'pressark' ) . '</p>';
	}

	public function render_model_field(): void {
		$model         = get_option( 'pressark_model', 'auto' );
		$custom_model  = get_option( 'pressark_custom_model', '' );
		$tracker       = new PressArk_Usage_Tracker();
		$is_pro        = $tracker->is_pro();
		$tier          = ( new PressArk_License() )->get_tier();
		$is_team       = in_array( $tier, array( 'team', 'agency', 'enterprise' ), true );
		$is_byok       = PressArk_Entitlements::is_byok();
		$upgrade_url   = pressark_get_upgrade_url();

		$pro_models = array(
			'anthropic/claude-haiku-4.5',
			'moonshotai/kimi-k2.5',
			'z-ai/glm-5',
			'openai/gpt-5.4-mini',
			'anthropic/claude-sonnet-4.6',
		);

		$team_models = array(
			'anthropic/claude-opus-4.6',
			'openai/gpt-5.3-codex',
			'openai/gpt-5.4',
		);

		$models = array(
			'auto'                       => array( 'label' => __( 'Auto (recommended)', 'pressark' ), 'group' => 'auto', 'cost_class' => '' ),
			'deepseek/deepseek-v3.2'     => array( 'label' => __( 'DeepSeek V3.2 · Economy', 'pressark' ), 'group' => 'free', 'cost_class' => 'Economy' ),
			'minimax/minimax-m2.7'       => array( 'label' => __( 'MiniMax M2.7 · Economy', 'pressark' ), 'group' => 'free', 'cost_class' => 'Economy' ),
			'anthropic/claude-haiku-4.5' => array( 'label' => __( 'Claude Haiku 4.5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'moonshotai/kimi-k2.5'       => array( 'label' => __( 'Kimi K2.5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'z-ai/glm-5'                => array( 'label' => __( 'GLM-5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'openai/gpt-5.4-mini'        => array( 'label' => __( 'GPT-5.4 Mini · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'anthropic/claude-sonnet-4.6' => array( 'label' => __( 'Claude Sonnet 4.6 · Standard', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Standard' ),
			'anthropic/claude-opus-4.6'  => array( 'label' => __( 'Claude Opus 4.6 · Premium', 'pressark' ), 'group' => 'team', 'cost_class' => 'Premium' ),
			'openai/gpt-5.3-codex'       => array( 'label' => __( 'GPT-5.3 Codex · Standard', 'pressark' ), 'group' => 'team', 'cost_class' => 'Standard' ),
			'openai/gpt-5.4'             => array( 'label' => __( 'GPT-5.4 · Standard', 'pressark' ), 'group' => 'team', 'cost_class' => 'Standard' ),
			'custom'                     => array( 'label' => __( 'Custom model...', 'pressark' ), 'group' => 'custom', 'cost_class' => 'Standard' ),
		);
		?>
		<select name="pressark_model" id="pressark-model-select">
			<option value="auto" <?php selected( $model, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'pressark' ); ?></option>

			<optgroup label="<?php esc_attr_e( 'Free Tier Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'free' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: %s: model cost class label, such as Economy or Standard. */
						__( '%s models use about 1× credits.', 'pressark' ),
						$info['cost_class']
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Pro Models (License Required)', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'pro' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_pro ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: 1: model cost class label, 2: approximate credit multiplier. */
						__( '%1$s models use about %2$s credits.', 'pressark' ),
						$info['cost_class'],
						'Value' === $info['cost_class'] ? '3×' : '8×'
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_pro ? '' : ' [Pro]'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Team+ Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'team' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_team ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: 1: model cost class label, 2: approximate credit multiplier. */
						__( '%1$s models use about %2$s credits.', 'pressark' ),
						$info['cost_class'],
						'Premium' === $info['cost_class'] ? '15×' : '8×'
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_team ? '' : ' [Team+]'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<?php if ( $is_byok ) : ?>
			<optgroup label="<?php esc_attr_e( 'Custom', 'pressark' ); ?>">
				<option value="custom" <?php selected( $model, 'custom' ); ?>><?php esc_html_e( 'Custom model...', 'pressark' ); ?></option>
			</optgroup>
			<?php endif; ?>
		</select>

		<input type="text"
			name="pressark_custom_model"
			id="pressark-custom-model"
			value="<?php echo esc_attr( $custom_model ); ?>"
			placeholder="<?php esc_attr_e( 'e.g., meta-llama/llama-3-70b', 'pressark' ); ?>"
			class="regular-text"
			style="display: <?php echo 'custom' === $model ? 'block' : 'none'; ?>; margin-top: 8px;" />

		<p class="description" id="pressark-model-description">
			<?php esc_html_e( 'Auto mode uses DeepSeek V3.2 for free users and Claude Sonnet 4.6 for paid plans. Economy models use about 1× credits, Value about 3×, Standard about 8×, Premium about 15×.', 'pressark' ); ?>
		</p>

		<?php if ( ! $is_pro ) : ?>
		<p class="description" style="color: #e94560; margin-top: 4px;">
			<?php echo pressark_icon( 'lock' ); ?> <?php
			echo wp_kses(
				sprintf(
					/* translators: %s: Freemius upgrade URL. */
					__( 'Paid models require an active plan. <a href="%s">Upgrade in Freemius</a>', 'pressark' ),
					esc_url( $upgrade_url )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
		</p>
		<?php endif; ?>

		<script>
		(function() {
			var select = document.getElementById('pressark-model-select');
			if (!select) return;
			select.addEventListener('change', function() {
				var customInput = document.getElementById('pressark-custom-model');
				customInput.style.display = this.value === 'custom' ? 'block' : 'none';

				var descriptions = {
					'auto': '<?php echo esc_js( __( 'Auto mode uses DeepSeek V3.2 for free users and Claude Sonnet 4.6 for paid plans. Economy models use about 1× credits, Value about 3×, Standard about 8×, Premium about 15×.', 'pressark' ) ); ?>',
					'deepseek/deepseek-v3.2': '<?php echo esc_js( __( 'Fast and affordable Economy model. Great for most tasks at the lowest credit cost.', 'pressark' ) ); ?>',
					'minimax/minimax-m2.7': '<?php echo esc_js( __( 'Economy model with strong multilingual support. Great for quick tasks at low credit cost.', 'pressark' ) ); ?>',
					'anthropic/claude-haiku-4.5': '<?php echo esc_js( __( 'Value class model. Fast and capable at about 3× credits.', 'pressark' ) ); ?>',
					'moonshotai/kimi-k2.5': '<?php echo esc_js( __( 'Value class model. Strong reasoning and long-context support at about 3× credits.', 'pressark' ) ); ?>',
					'z-ai/glm-5': '<?php echo esc_js( __( 'Value class model. Balanced performance at about 3× credits.', 'pressark' ) ); ?>',
					'openai/gpt-5.4-mini': '<?php echo esc_js( __( 'Value class model. Compact and efficient at about 3× credits.', 'pressark' ) ); ?>',
					'anthropic/claude-sonnet-4.6': '<?php echo esc_js( __( 'Standard class model. Excellent writing and analysis at about 8× credits.', 'pressark' ) ); ?>',
					'anthropic/claude-opus-4.6': '<?php echo esc_js( __( 'Premium class model. Most capable option at about 15× credits. Requires Team+ plan.', 'pressark' ) ); ?>',
					'openai/gpt-5.3-codex': '<?php echo esc_js( __( 'Standard class model. Optimized for code generation at about 8× credits. Requires Team+ plan.', 'pressark' ) ); ?>',
					'openai/gpt-5.4': '<?php echo esc_js( __( "Standard class model. OpenAI\'s flagship at about 8× credits. Requires Team+ plan.", 'pressark' ) ); ?>',
					'custom': '<?php echo esc_js( __( 'Enter any OpenRouter-compatible model identifier below.', 'pressark' ) ); ?>'
				};

				var descEl = document.getElementById('pressark-model-description');
				descEl.textContent = descriptions[this.value] || '';
			});
		})();
		</script>
		<?php
	}

	public function render_summarize_model_field(): void {
		$model        = get_option( 'pressark_summarize_model', 'auto' );
		$custom_model = get_option( 'pressark_summarize_custom_model', '' );
		$tracker      = new PressArk_Usage_Tracker();
		$is_pro       = $tracker->is_pro();
		$tier         = ( new PressArk_License() )->get_tier();
		$is_team      = in_array( $tier, array( 'team', 'agency', 'enterprise' ), true );
		$is_byok      = PressArk_Entitlements::is_byok();
		$upgrade_url  = pressark_get_upgrade_url();
		$models       = $this->get_supported_model_options();
		$auto_description = $is_byok
			? __( 'Auto mode reuses your BYOK main model for background tasks like planning, context compression, and memory selection. Choose another model here only if you want a dedicated Back-Agent.', 'pressark' )
			: __( 'Auto mode uses a cheap model for background tasks: planning, context compression, and memory selection. Does not affect chat responses.', 'pressark' );
		$back_agent_summary = $is_byok
			? __( 'Auto mode reuses your BYOK main model for task-aware planning and context compression unless you choose a dedicated Back-Agent override.', 'pressark' )
			: __( 'Auto mode uses DeepSeek V3.2 for task-aware context compression. This affects continuation capsules only, not normal chat execution.', 'pressark' );
		$back_agent_detail = $is_byok
			? __( 'Back-Agent handles task planning, context compression, and memory selection behind the scenes. Leave it on Auto to match your BYOK main model, or override it with a separate model.', 'pressark' )
			: __( 'Handles task planning, context compression, and memory selection behind the scenes. A cheaper model saves credits without affecting response quality.', 'pressark' );
		$descriptions = $this->get_model_option_descriptions(
			$auto_description
		);
		?>
		<select name="pressark_summarize_model" id="pressark-summarize-model-select">
			<option value="auto" <?php selected( $model, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'pressark' ); ?></option>

			<optgroup label="<?php esc_attr_e( 'Free Tier Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'free' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: %s: model cost class label, such as Economy or Standard. */
						__( '%s models use about 1× credits.', 'pressark' ),
						$info['cost_class']
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Pro Models (License Required)', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'pro' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_pro ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: 1: model cost class label, 2: approximate credit multiplier. */
						__( '%1$s models use about %2$s credits.', 'pressark' ),
						$info['cost_class'],
						'Value' === $info['cost_class'] ? '3×' : '8×'
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_pro ? '' : ' [Pro]'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Team+ Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'team' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_team ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf(
						/* translators: 1: model cost class label, 2: approximate credit multiplier. */
						__( '%1$s models use about %2$s credits.', 'pressark' ),
						$info['cost_class'],
						'Premium' === $info['cost_class'] ? '15×' : '8×'
					) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_team ? '' : ' [Team+]'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<?php if ( $is_byok ) : ?>
			<optgroup label="<?php esc_attr_e( 'Custom', 'pressark' ); ?>">
				<option value="custom" <?php selected( $model, 'custom' ); ?>><?php esc_html_e( 'Custom model...', 'pressark' ); ?></option>
			</optgroup>
			<?php endif; ?>
		</select>

		<input type="text"
			name="pressark_summarize_custom_model"
			id="pressark-summarize-custom-model"
			value="<?php echo esc_attr( $custom_model ); ?>"
			placeholder="<?php esc_attr_e( 'e.g., qwen/qwen2.5-7b-instruct', 'pressark' ); ?>"
			class="regular-text"
			style="display: <?php echo 'custom' === $model ? 'block' : 'none'; ?>; margin-top: 8px;" />

		<p class="description" id="pressark-summarize-model-description">
			<?php echo esc_html( $back_agent_summary ); ?>
		</p>
		<p class="description" style="margin-top:4px;">
			<?php echo esc_html( $back_agent_detail ); ?>
		</p>

		<?php if ( ! $is_pro ) : ?>
		<p class="description" style="color: #e94560; margin-top: 4px;">
			<?php echo pressark_icon( 'lock' ); ?> <?php
			echo wp_kses(
				sprintf(
					/* translators: %s: Freemius upgrade URL. */
					__( 'Paid models require an active plan. <a href="%s">Upgrade in Freemius</a>', 'pressark' ),
					esc_url( $upgrade_url )
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
		</p>
		<?php endif; ?>

		<script>
		(function() {
			var select = document.getElementById('pressark-summarize-model-select');
			if (!select) return;
			select.addEventListener('change', function() {
				var customInput = document.getElementById('pressark-summarize-custom-model');
				if (customInput) {
					customInput.style.display = this.value === 'custom' ? 'block' : 'none';
				}

				var descriptions = <?php echo wp_json_encode( $descriptions ); ?>;
				var descEl = document.getElementById('pressark-summarize-model-description');
				if (descEl) {
					descEl.textContent = descriptions[this.value] || '';
				}
			});
		})();
		</script>
		<?php
	}

	private function get_supported_model_options(): array {
		return array(
			'deepseek/deepseek-v3.2'          => array( 'label' => __( 'DeepSeek V3.2 · Economy', 'pressark' ), 'group' => 'free', 'cost_class' => 'Economy' ),
			'minimax/minimax-m2.7'            => array( 'label' => __( 'MiniMax M2.7 · Economy', 'pressark' ), 'group' => 'free', 'cost_class' => 'Economy' ),
			'anthropic/claude-haiku-4.5'      => array( 'label' => __( 'Claude Haiku 4.5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'moonshotai/kimi-k2.5'            => array( 'label' => __( 'Kimi K2.5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'z-ai/glm-5'                     => array( 'label' => __( 'GLM-5 · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'openai/gpt-5.4-mini'             => array( 'label' => __( 'GPT-5.4 Mini · Value', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Value' ),
			'anthropic/claude-sonnet-4.6'     => array( 'label' => __( 'Claude Sonnet 4.6 · Standard', 'pressark' ), 'group' => 'pro', 'cost_class' => 'Standard' ),
			'anthropic/claude-opus-4.6'       => array( 'label' => __( 'Claude Opus 4.6 · Premium', 'pressark' ), 'group' => 'team', 'cost_class' => 'Premium' ),
			'openai/gpt-5.3-codex'            => array( 'label' => __( 'GPT-5.3 Codex · Standard', 'pressark' ), 'group' => 'team', 'cost_class' => 'Standard' ),
			'openai/gpt-5.4'                  => array( 'label' => __( 'GPT-5.4 · Standard', 'pressark' ), 'group' => 'team', 'cost_class' => 'Standard' ),
			'custom'                          => array( 'label' => __( 'Custom model...', 'pressark' ), 'group' => 'custom', 'cost_class' => 'Standard' ),
		);
	}

	private function get_model_option_descriptions( string $auto_description ): array {
		return array(
			'auto'                        => $auto_description,
			'deepseek/deepseek-v3.2'      => __( 'Fast and affordable Economy model. Great for most tasks at the lowest credit cost.', 'pressark' ),
			'minimax/minimax-m2.7'        => __( 'Economy model with strong multilingual support. Great for quick tasks at low credit cost.', 'pressark' ),
			'anthropic/claude-haiku-4.5'  => __( 'Value class model. Fast and capable at about 3× credits.', 'pressark' ),
			'moonshotai/kimi-k2.5'        => __( 'Value class model. Strong reasoning and long-context support at about 3× credits.', 'pressark' ),
			'z-ai/glm-5'                 => __( 'Value class model. Balanced performance at about 3× credits.', 'pressark' ),
			'openai/gpt-5.4-mini'         => __( 'Value class model. Compact and efficient at about 3× credits.', 'pressark' ),
			'anthropic/claude-sonnet-4.6' => __( 'Standard class model. Excellent writing and analysis at about 8× credits.', 'pressark' ),
			'anthropic/claude-opus-4.6'   => __( 'Premium class model. Most capable option at about 15× credits. Requires Team+ plan.', 'pressark' ),
			'openai/gpt-5.3-codex'        => __( 'Standard class model. Optimized for code generation at about 8× credits. Requires Team+ plan.', 'pressark' ),
			'openai/gpt-5.4'              => __( "Standard class model. OpenAI's flagship at about 8× credits. Requires Team+ plan.", 'pressark' ),
			'custom'                      => __( 'Enter any provider-compatible model identifier below.', 'pressark' ),
		);
	}

	public function render_freemius_account_field(): void {
		$fs          = function_exists( 'pressark_fs' ) ? pressark_fs() : null;
		$account_url = admin_url( 'admin.php?page=pressark-account' );

		if ( $fs && method_exists( $fs, 'get_account_url' ) ) {
			$account_url = (string) $fs->get_account_url();
		}

		echo '<p>' . esc_html__( 'PressArk uses Freemius for billing, subscriptions, trials, and site activations.', 'pressark' ) . '</p>';
		printf(
			'<p><a class="button button-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( $account_url ),
			esc_html__( 'Open Billing Account', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Use your Freemius account to upgrade, manage renewals, and activate PressArk on additional sites within your plan limit.', 'pressark' ) . '</p>';
	}

	public function render_credit_store_field(): void {
		$tier      = ( new PressArk_License() )->get_tier();
		$plan_info = PressArk_Entitlements::get_plan_info( $tier );
		$is_byok   = ! empty( $plan_info['is_byok'] );
		$is_paid   = ! empty( $plan_info['can_buy_credits'] );
		$bank      = new PressArk_Token_Bank();
		$credits   = $bank->get_credits();
		$active    = (array) ( $credits['credits'] ?? array() );
		$pack_catalog = PressArk_Entitlements::get_credit_pack_catalog();
		$checkout_config = PressArk_Entitlements::get_credit_checkout_config();

		echo '<div id="pressark-credit-store">';

		if ( $is_byok ) {
			echo '<p class="description">' . esc_html__( 'Credit packs are hidden while BYOK mode is active because bundled credits are not used with your own API key.', 'pressark' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( ! $is_paid ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Credit packs are available on paid plans only.', 'pressark' ),
				esc_url( pressark_get_upgrade_url() ),
				esc_html__( 'Upgrade to unlock the credit store.', 'pressark' )
			);
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'Purchased credits are used after your monthly included allowance is exhausted. Credit packs expire 12 months after purchase.', 'pressark' ) . '</p>';
		if ( ! empty( $plan_info['billing_contract_mismatch'] ) ) {
			echo '<p class="description" style="color:#92400e;">' . esc_html__( 'The bank is using a newer billing catalog than this plugin build. Checkout uses the bank catalog automatically.', 'pressark' ) . '</p>';
		}
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 20px;">';
		foreach ( $pack_catalog as $pack_type => $pack ) {
			$pricing_id = (int) ( $pack['pricing_id'] ?? $pack['freemius_pricing_id'] ?? 0 );
			echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;margin-bottom:6px;">' . esc_html( (string) ( $pack['label'] ?? '' ) ) . '</strong>';
			echo '<p style="margin:0 0 12px;color:#64748b;">$' . esc_html( number_format( (int) ( $pack['price_cents'] ?? 0 ) / 100, 0 ) ) . '</p>';
			printf(
				'<button type="button" class="button button-secondary pressark-buy-credits" data-pricing-id="%s" data-pack="%s">%s</button>',
				esc_attr( (string) $pricing_id ),
				esc_attr( $pack_type ),
				esc_html__( 'Buy Credits', 'pressark' )
			);
			echo '</div>';
		}
		echo '</div>';

		// Freemius Checkout JS for credit purchases.
		$current_user = wp_get_current_user();
		$nonce        = wp_create_nonce( 'pressark_credit_purchase' );
		$install_id   = '';
		if ( function_exists( 'pressark_fs' ) ) {
			$site_obj = pressark_fs()->get_site();
			if ( $site_obj && ! empty( $site_obj->id ) ) {
				$install_id = (string) $site_obj->id;
			}
		}
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST ) ?: '';
		$site_token  = get_option( 'pressark_site_token', '' );

		// Sandbox support for SaaS checkout (Freemius hosted checkout).
		$sandbox_json = 'null';
		if ( defined( 'WP_FS__DEV_MODE' ) && WP_FS__DEV_MODE ) {
			$secret_key = defined( 'PRESSARK_CREDITS_SECRET_KEY' ) ? PRESSARK_CREDITS_SECRET_KEY
			: ( defined( 'WP_FS__pressark_SECRET_KEY' ) ? WP_FS__pressark_SECRET_KEY : '' );
			if ( $secret_key ) {
				$ctx           = (string) time();
				$product_id    = (string) $checkout_config['product_id'];
				$public_key    = (string) $checkout_config['public_key'];
				$sandbox_token = md5( $ctx . $product_id . $secret_key . $public_key . 'checkout' );
				$sandbox_json  = wp_json_encode( array(
					'token' => $sandbox_token,
					'ctx'   => $ctx,
				) );
			}
		}
		?>
		<script src="https://checkout.freemius.com/js/v1/"></script>
		<script>
		(function() {
			var sandboxParams = <?php echo $sandbox_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
			var buttons = document.querySelectorAll('.pressark-buy-credits');
			buttons.forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var pricingId = parseInt(btn.getAttribute('data-pricing-id'), 10);
					var packType  = btn.getAttribute('data-pack');
					if (!pricingId) return;

					var checkoutConfig = {
						product_id:  '<?php echo (int) $checkout_config['product_id']; ?>',
						plan_id:     '<?php echo (int) $checkout_config['plan_id']; ?>',
						public_key:  <?php echo wp_json_encode( $checkout_config['public_key'] ); ?>,
						image:       <?php echo wp_json_encode( PRESSARK_URL . 'assets/imgs/PNG-LOGO.png' ); ?>
					};

					if (sandboxParams) {
						checkoutConfig.sandbox = sandboxParams;
					}

					var handler = new FS.Checkout(checkoutConfig);

					handler.open({
						name:         <?php echo wp_json_encode( $current_user->display_name ); ?>,
						email:        <?php echo wp_json_encode( $current_user->user_email ); ?>,
						pricing_id:   pricingId,
						billing_cycle: 'lifetime',
						licenses:     1,
						title:        'Buy Credits',
						metadata: {
							pressark_install_id: <?php echo wp_json_encode( $install_id ); ?>,
							pressark_site_domain: <?php echo wp_json_encode( $site_domain ); ?>,
							pressark_site_token: <?php echo wp_json_encode( $site_token ); ?>
						},
						purchaseCompleted: function(response) {
							if (!response || !response.payment) return;
							jQuery.post(window.ajaxurl || '/wp-admin/admin-ajax.php', {
								action:       'pressark_confirm_credit_purchase',
								_ajax_nonce:  <?php echo wp_json_encode( $nonce ); ?>,
								payment_id:   String(response.payment.id || ''),
								pricing_id:   pricingId,
								gross:        String(response.payment.gross || 0),
								plan_name:    packType,
								product_name: 'pressark-credits',
								install_id:   <?php echo wp_json_encode( $install_id ); ?>,
								site_domain:  <?php echo wp_json_encode( $site_domain ); ?>,
								site_token:   <?php echo wp_json_encode( $site_token ); ?>
							}).done(function() { location.reload(); });
						},
						success: function() { location.reload(); }
					});
				});
			});
		})();
		</script>
		<?php

		if ( ! empty( $active ) ) {
			echo '<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:20px;">';
			echo '<strong style="display:block;color:#0f172a;margin-bottom:12px;">' . esc_html__( 'Active Credit Packs', 'pressark' ) . '</strong>';
			echo '<table style="width:100%;border-collapse:collapse;">';
			echo '<thead><tr>';
			echo '<th style="text-align:left;padding:0 0 10px;color:#64748b;font-size:12px;">' . esc_html__( 'Pack', 'pressark' ) . '</th>';
			echo '<th style="text-align:left;padding:0 0 10px;color:#64748b;font-size:12px;">' . esc_html__( 'Remaining', 'pressark' ) . '</th>';
			echo '<th style="text-align:left;padding:0 0 10px;color:#64748b;font-size:12px;">' . esc_html__( 'Expires', 'pressark' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $active as $pack ) {
				$remaining = max( 0, (int) ( $pack['icus_remaining'] ?? 0 ) );
				echo '<tr>';
				echo '<td style="padding:10px 0;border-top:1px solid #f1f5f9;">' . esc_html( (string) ( $pack['pack_type'] ?? '' ) ) . '</td>';
				echo '<td style="padding:10px 0;border-top:1px solid #f1f5f9;">' . esc_html( number_format( $remaining ) ) . ' ' . esc_html__( 'credits', 'pressark' ) . '</td>';
				echo '<td style="padding:10px 0;border-top:1px solid #f1f5f9;">' . esc_html( (string) ( $pack['expires_at'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		echo '</div>';
	}

	public function render_license_key_field(): void {
		$value = get_option( 'pressark_license_key', '' );
		printf(
			'<input type="text" name="pressark_license_key" id="pressark_license_key" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
	}

	/**
	 * Find a brand image by name from the imgs folder.
	 */
	private function find_brand_image( array $names ): ?string {
		$imgs_dir = PRESSARK_PATH . 'assets/imgs/';
		$imgs_url = PRESSARK_URL . 'assets/imgs/';

		// Exact match by filename (without extension).
		foreach ( $names as $name ) {
			foreach ( array( 'png', 'jpg', 'svg', 'webp' ) as $ext ) {
				if ( file_exists( $imgs_dir . $name . '.' . $ext ) ) {
					return $imgs_url . $name . '.' . $ext;
				}
			}
		}

		// Partial match — check if any filename contains the search term.
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
	 * Sanitize BYOK API key — encrypt on save, keep existing if empty.
	 *
	 * The is_sodium_encrypted() guard prevents double encryption when WordPress's
	 * update_option() delegates to add_option() for new options — both call
	 * sanitize_option(), so without the guard the value is encrypted twice.
	 */
	public function sanitize_byok_key( $value ): string {
		$value = sanitize_text_field( $value );
		if ( empty( $value ) ) {
			// Keep existing encrypted key when field submitted empty.
			return get_option( 'pressark_byok_api_key', '' );
		}
		if ( PressArk_Usage_Tracker::is_sodium_encrypted( $value ) ) {
			return $value;
		}
		return PressArk_Usage_Tracker::encrypt_value( $value );
	}

	public function render_byok_section(): void {
		echo '<p>' . esc_html__( 'Use your own API key to bypass bundled credit usage. You pay your API provider directly. AI requests are sent directly to your chosen provider; no bundled usage is metered by PressArk.', 'pressark' ) . '</p>';
		if ( 'free' === ( new PressArk_License() )->get_tier() ) {
			echo '<p class="description">' . esc_html__( 'Using your own API key? Great! You will still need to upgrade for unlimited edits, automations, and multi-site support.', 'pressark' ) . '</p>';
		}
	}

	public function render_byok_toggle_field(): void {
		$enabled = get_option( 'pressark_byok_enabled', false );
		printf(
			'<label><input type="checkbox" name="pressark_byok_enabled" value="1" %s /> %s</label>',
			checked( $enabled, true, false ),
			esc_html__( 'Enable BYOK mode', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'When enabled, PressArk uses your API key instead of the bundled one. No bundled credit limits apply.', 'pressark' ) . '</p>';
	}

	public function render_byok_provider_field(): void {
		$value = get_option( 'pressark_byok_provider', 'openrouter' );
		$providers = array(
			'openrouter' => __( 'OpenRouter', 'pressark' ),
			'openai'     => __( 'OpenAI', 'pressark' ),
			'anthropic'  => __( 'Anthropic', 'pressark' ),
			'deepseek'   => __( 'DeepSeek', 'pressark' ),
			'gemini'     => __( 'Google Gemini', 'pressark' ),
		);
		echo '<select name="pressark_byok_provider">';
		foreach ( $providers as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function render_byok_api_key_field(): void {
		$has_key = ! empty( get_option( 'pressark_byok_api_key', '' ) );
		printf(
			'<input type="password" name="pressark_byok_api_key" value="" class="regular-text" autocomplete="off" placeholder="%s" />',
			$has_key ? esc_attr__( 'Key saved (enter new to replace)', 'pressark' ) : esc_attr__( 'Enter your API key', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Your key is encrypted before storage.', 'pressark' ) . '</p>';
	}

	public function render_byok_model_field(): void {
		$model = get_option( 'pressark_byok_model', 'gpt-5.4-mini' );
		printf(
			'<input type="text" name="pressark_byok_model" value="%s" class="regular-text" placeholder="gpt-5.4-mini" />',
			esc_attr( $model )
		);
		echo '<p class="description">' . esc_html__( 'Any model identifier supported by your provider (e.g., gpt-5.4-mini, claude-sonnet-4.6, deepseek-v3.2).', 'pressark' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Leave Back-Agent on Auto to reuse this model for planning, context compression, and memory selection, or choose a separate Back-Agent model above.', 'pressark' ) . '</p>';
	}

	/**
	 * Sanitize retention days: must be integer >= 7 to prevent accidental data loss.
	 */
	public function sanitize_retention_days( $value ): int {
		return max( 7, absint( $value ) );
	}

	/**
	 * Sanitize indexed post types: filter to valid slugs, trigger rebuild on change.
	 *
	 * @since 4.2.0
	 */
	public function sanitize_indexed_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
		$clean = array_diff( $clean, PressArk_Content_Index::BLOCKED_TYPES );

		// If selection changed, trigger a full rebuild to sync the index.
		$previous = get_option( 'pressark_indexed_post_types', array() );
		if ( array_diff( $clean, $previous ) || array_diff( $previous, $clean ) ) {
			$index = new PressArk_Content_Index();
			$index->schedule_full_rebuild();
		}

		return $clean;
	}

	public function render_retention_section(): void {
		echo '<p>' . esc_html__( 'Configure how long PressArk keeps historical data. Data older than the retention period is automatically cleaned up daily. Recently completed work is always kept until it finishes, regardless of when it started.', 'pressark' ) . '</p>';
	}

	public function render_retention_log_field(): void {
		$value = (int) get_option( 'pressark_retention_log_days', 90 );
		printf(
			'<input type="number" name="pressark_retention_log_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Action log entries older than this are deleted. Default: 90 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_chat_field(): void {
		$value = (int) get_option( 'pressark_retention_chat_days', 180 );
		printf(
			'<input type="number" name="pressark_retention_chat_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Chat conversations not updated within this period are deleted. Default: 180 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_ledger_field(): void {
		$value = (int) get_option( 'pressark_retention_ledger_days', 365 );
		printf(
			'<input type="number" name="pressark_retention_ledger_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Credit usage records older than this are deleted. In-progress requests are never deleted. Default: 365 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_runs_field(): void {
		$value = (int) get_option( 'pressark_retention_runs_days', 30 );
		printf(
			'<input type="number" name="pressark_retention_runs_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Completed and failed execution runs older than this are deleted. Default: 30 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_tasks_field(): void {
		$value = (int) get_option( 'pressark_retention_tasks_days', 30 );
		printf(
			'<input type="number" name="pressark_retention_tasks_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Completed and failed tasks older than this are deleted. Default: 30 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_automations_field(): void {
		$value = (int) get_option( 'pressark_retention_automations_days', 90 );
		printf(
			'<input type="number" name="pressark_retention_automations_days" value="%s" min="7" step="1" class="small-text" /> %s',
			esc_attr( (string) $value ),
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Archived one-shot automations older than this are deleted. Active, paused, and failed automations are retained. Default: 90 days.', 'pressark' ) . '</p>';
	}

	/**
	 * Render bundled credit usage (read-only) in the billing section.
	 */
	private function render_token_budget_display(): void {
		$tier         = ( new PressArk_License() )->get_tier();
		$plan_info    = PressArk_Entitlements::get_plan_info( $tier );
		$is_byok      = ! empty( $plan_info['is_byok'] );
		$billing_state = is_array( $plan_info['billing_state'] ?? null ) ? (array) $plan_info['billing_state'] : array();
		$used         = (int) ( $plan_info['icus_used'] ?? 0 );
		$monthly      = (int) ( $plan_info['monthly_included_icu_budget'] ?? $plan_info['monthly_icu_budget'] ?? 100000 );
		$total        = (int) ( $plan_info['total_remaining'] ?? $plan_info['icus_remaining'] ?? $monthly );
		$monthly_left = (int) ( $plan_info['monthly_included_remaining'] ?? $plan_info['monthly_remaining'] ?? max( 0, $monthly - $used ) );
		$credits_left = (int) ( $plan_info['purchased_credits_remaining'] ?? $plan_info['credits_remaining'] ?? 0 );
		$legacy_left  = (int) ( $plan_info['legacy_bonus_remaining'] ?? 0 );
		$monthly_exhausted = ! empty( $plan_info['monthly_exhausted'] );
		$raw_tokens_used = (int) ( $plan_info['raw_tokens_used'] ?? $used );
		$at_limit     = $total <= 0;
		$pct          = $monthly > 0 ? (int) min( 100, round( ( $used / $monthly ) * 100 ) ) : 0;
		$warn         = 'normal' !== (string) ( $plan_info['budget_pressure_state'] ?? 'normal' );
		$next_reset_at = (string) ( $plan_info['next_reset_at'] ?? '' );
		$reset_label   = $next_reset_at ? wp_date( 'M j', strtotime( $next_reset_at ) ) : '';
		$upgrade_url  = pressark_get_upgrade_url();
		$store_anchor = admin_url( 'admin.php?page=pressark#pressark-credit-store' );
		$pressure_label = ucfirst( (string) ( $plan_info['budget_pressure_state'] ?? 'normal' ) );
		$authority_label = (string) ( $billing_state['authority_label'] ?? 'Bank provisional' );
		$service_state = (string) ( $billing_state['service_state'] ?? ( ! empty( $plan_info['offline'] ) ? 'offline_assisted' : 'normal' ) );
		$service_label = (string) ( $billing_state['service_label'] ?? ucfirst( str_replace( '_', ' ', $service_state ) ) );
		$spend_label = (string) ( $billing_state['spend_label'] ?? ( $is_byok ? 'BYOK' : 'Monthly included' ) );
		$authority_notice = (string) ( $billing_state['authority_notice'] ?? '' );
		$service_notice = (string) ( $billing_state['service_notice'] ?? '' );
		$estimate_notice = (string) ( $billing_state['estimate_notice'] ?? '' );
		$service_color = 'normal' === $service_state ? '#64748b' : ( 'degraded' === $service_state ? '#92400e' : '#2563eb' );

		if ( $is_byok ) {
			echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px;margin-top:12px;">';
			echo '<strong>' . esc_html__( 'BYOK Mode Active', 'pressark' ) . '</strong>';
			echo '<p style="margin:4px 0 0;">' . esc_html( $authority_notice ?: 'Using your own API key. Bundled credits are bypassed, but plan entitlements still apply.' ) . '</p>';
			if ( $estimate_notice ) {
				echo '<p style="margin:8px 0 0;color:#166534;">' . esc_html( $estimate_notice ) . '</p>';
			}
			echo '</div>';
			return;
		}

		?>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin-top:20px;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<strong style="color:#0f172a;font-size:15px;font-weight:600;display:block;margin-bottom:16px;"><?php esc_html_e( 'Credit Usage', 'pressark' ); ?></strong>
			<div style="background:#f1f5f9;border-radius:6px;height:12px;margin:0 0 16px 0;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,0.05);">
				<div style="width:<?php echo intval( $pct ); ?>%;height:100%;background:<?php echo esc_attr( $at_limit ? '#ef4444' : ( $warn ? '#f59e0b' : '#2563EB' ) ); ?>;border-radius:6px;transition:width 0.5s ease-out;"></div>
			</div>
			<p style="margin:0;font-size:14px;font-weight:500;color:<?php echo esc_attr( $at_limit ? '#ef4444' : ( $warn ? '#f59e0b' : '#475569' ) ); ?>;">
				<?php
				printf(
					/* translators: 1: included credits used 2: billing-cycle included credit budget 3: percentage */
					esc_html__( '%1$s / %2$s included credits used this billing cycle (%3$s%%)', 'pressark' ),
					esc_html( number_format_i18n( $used ) ),
					esc_html( number_format_i18n( $monthly ) ),
					esc_html( number_format_i18n( $pct ) )
				);
				?>
			</p>
			<p style="margin:10px 0 0;color:#64748b;">
				<?php
				$summary = sprintf(
					/* translators: 1: included credits remaining 2: purchased credits remaining 3: total credits remaining */
					esc_html__( 'Included remaining: %1$s · Purchased credits: %2$s · Total spendable: %3$s', 'pressark' ),
					number_format( $monthly_left ),
					number_format( $credits_left ),
					number_format( $total )
				);
				if ( $legacy_left > 0 ) {
					$summary .= ' · ' . sprintf(
						/* translators: %s: legacy bonus credits remaining */
						esc_html__( 'Legacy bonus: %s', 'pressark' ),
						number_format( $legacy_left )
					);
				}
				echo esc_html( $summary );
				?>
			</p>
			<p style="margin:10px 0 0;color:#64748b;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: pressure label 2: billing authority label 3: service label 4: spend source label */
						__( 'Budget pressure: %1$s · Authority: %2$s · Service: %3$s · Spend source: %4$s', 'pressark' ),
						$pressure_label,
						$authority_label,
						$service_label,
						$spend_label
					)
				);
				?>
			</p>
			<?php if ( $authority_notice ) : ?>
				<p style="margin:10px 0 0;color:#475569;"><?php echo esc_html( $authority_notice ); ?></p>
			<?php endif; ?>
			<?php if ( $estimate_notice ) : ?>
				<p style="margin:10px 0 0;color:#475569;"><?php echo esc_html( $estimate_notice ); ?></p>
			<?php endif; ?>
			<?php if ( $service_notice ) : ?>
				<p style="margin:10px 0 0;color:<?php echo esc_attr( $service_color ); ?>;"><?php echo esc_html( $service_notice ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $plan_info['using_purchased_credits'] ) ) : ?>
				<p style="margin:10px 0 0;color:#92400e;"><?php esc_html_e( 'Purchased credits are currently being used because the monthly included allowance is exhausted.', 'pressark' ); ?></p>
			<?php elseif ( ! empty( $plan_info['using_legacy_bonus'] ) ) : ?>
				<p style="margin:10px 0 0;color:#92400e;"><?php esc_html_e( 'Legacy bonus credits are currently covering usage after the monthly included allowance.', 'pressark' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $plan_info['billing_contract_mismatch'] ) ) : ?>
				<p style="margin:10px 0 0;color:#92400e;"><?php esc_html_e( 'The bank is using a newer billing catalog than this plugin build. Bank values remain authoritative for pricing and balance semantics.', 'pressark' ); ?></p>
			<?php endif; ?>
			<?php if ( $reset_label ) : ?>
				<p style="margin:10px 0 0;color:#64748b;">
					<?php
					printf(
						/* translators: %s: next reset date */
						esc_html__( 'Billing cycle resets %s', 'pressark' ),
						esc_html( $reset_label )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $monthly_exhausted && $credits_left > 0 ) : ?>
				<p style="margin:10px 0 0;color:#2563eb;"><?php esc_html_e( 'Your billing-cycle credits are exhausted. PressArk is now using your purchased credits.', 'pressark' ); ?></p>
			<?php elseif ( $at_limit && PressArk_Entitlements::is_paid_tier( ( new PressArk_License() )->get_tier() ) ) : ?>
				<p style="margin:10px 0 0;"><a href="<?php echo esc_url( $store_anchor ); ?>" style="color:#2563EB;text-decoration:none;font-weight:600;"><?php esc_html_e( 'Buy more credits', 'pressark' ); ?></a></p>
			<?php elseif ( $at_limit ) : ?>
				<p style="margin:10px 0 0;"><a href="<?php echo esc_url( $upgrade_url ); ?>" style="color:#2563EB;text-decoration:none;font-weight:600;"><?php esc_html_e( 'Upgrade to unlock more billing-cycle credits', 'pressark' ); ?></a></p>
			<?php endif; ?>
			<details style="margin-top:14px;">
				<summary style="cursor:pointer;color:#64748b;"><?php esc_html_e( 'Advanced', 'pressark' ); ?></summary>
				<p style="margin:10px 0 0;color:#64748b;">
					<?php
					printf(
						/* translators: 1: raw tokens used */
						esc_html__( 'Raw provider tokens used this billing cycle: %s', 'pressark' ),
						number_format( $raw_tokens_used )
					);
					?>
				</p>
			</details>
			<?php if ( ! empty( $plan_info['offline'] ) ) : ?>
				<p class="description" style="color:#f59e0b; margin-top:12px;"><?php esc_html_e( 'Token bank service unreachable. Showing cached data while preserving bank authority for final settlement.', 'pressark' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render usage statistics box at top of settings page.
	 */
	/**
	 * Render plan capabilities section on settings page (v2.8.0).
	 */
	private function render_plan_capabilities_section(): void {
		$tier      = ( new PressArk_License() )->get_tier();
		$plan_info = PressArk_Entitlements::get_plan_info( $tier );
		?>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<h2 style="margin-top:0; color:#0f172a;">
				<?php esc_html_e( 'Plan Capabilities', 'pressark' ); ?>
				<span style="background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-size:12px;padding:4px 10px;border-radius:12px;margin-left:12px;font-weight:600;vertical-align:middle;">
					<?php echo esc_html( $plan_info['tier_label'] ); ?>
				</span>
			</h2>
			<?php if ( 'free' === $tier ) :
				$total_used      = $plan_info['group_usage']['total_used'] ?? 0;
				$total_limit     = $plan_info['group_usage']['total_limit'] ?? 6;
				$total_remaining = $plan_info['group_usage']['total_remaining'] ?? $total_limit;
				$exhausted       = $total_used >= $total_limit;
				$per_group       = $plan_info['group_usage']['per_group'] ?? array();
			?>
				<p style="color:#64748b;margin-bottom:24px;font-size:14px;max-width:600px;line-height:1.6;">
					<?php
					printf(
						/* translators: %d: weekly tool action limit */
						esc_html__( 'Free plan: read tools are unlimited. You get %d tool actions per week across all tools. Resets every Monday.', 'pressark' ),
						intval( $total_limit )
					);
					?>
				</p>
				<div style="max-width:600px;margin-bottom:20px;">
					<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;">
						<span style="font-size:14px;font-weight:600;color:<?php echo esc_attr( $exhausted ? '#ef4444' : '#0f172a' ); ?>;">
							<?php
							printf(
								/* translators: 1: used count 2: weekly limit */
								esc_html__( '%1$d / %2$d actions used this week', 'pressark' ),
								intval( $total_used ),
								intval( $total_limit )
							);
							?>
						</span>
						<span style="font-size:13px;color:#94a3b8;">
							<?php
							printf(
								/* translators: %d: remaining actions */
								esc_html__( '%d remaining', 'pressark' ),
								intval( $total_remaining )
							);
							?>
						</span>
					</div>
					<div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;">
						<div style="background:<?php echo esc_attr( $exhausted ? '#ef4444' : '#3b82f6' ); ?>;height:100%;border-radius:6px;width:<?php echo esc_attr( (string) ( $total_limit > 0 ? min( 100, round( $total_used / $total_limit * 100 ) ) : 0 ) ); ?>%;"></div>
					</div>
				</div>
				<?php if ( ! empty( $per_group ) ) : ?>
				<details style="margin-top:12px;">
					<summary style="cursor:pointer;color:#64748b;font-size:13px;"><?php esc_html_e( 'Usage breakdown by group', 'pressark' ); ?></summary>
					<table style="max-width:600px; width:100%; border-collapse:collapse; margin-top:8px;">
						<thead>
							<tr>
								<th style="text-align:left; padding:8px 0; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:500; font-size:13px;"><?php esc_html_e( 'Tool Group', 'pressark' ); ?></th>
								<th style="text-align:center; padding:8px 0; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:500; font-size:13px;"><?php esc_html_e( 'Used', 'pressark' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $per_group as $group => $count ) :
								if ( $count <= 0 ) continue;
								$label = ucwords( str_replace( '_', ' ', $group ) );
							?>
							<tr style="color:#0f172a;">
								<td style="padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:13px;"><?php echo esc_html( $label ); ?></td>
								<td style="text-align:center; padding:10px 0; border-bottom:1px solid #f1f5f9; font-weight:600; font-size:13px;"><?php echo intval( $count ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endif; ?>
				<p style="margin-top:24px;">
					<a href="<?php echo esc_url( $plan_info['upgrade_url'] ); ?>" class="button button-primary">
						<?php esc_html_e( 'Upgrade to Pro for Unlimited Access', 'pressark' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p style="color:#10b981;font-weight:600;margin-top:16px;">
					<?php esc_html_e( 'All tools unlimited — no per-group limits on your plan.', 'pressark' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the permissions reference section on the settings page.
	 */
	private function render_permissions_section(): void {
		?>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<h2 style="margin-top:0; color:#0f172a; margin-bottom:8px;"><?php esc_html_e( 'Permissions', 'pressark' ); ?></h2>
			<p style="color:#64748b; font-size:14px; margin-bottom:20px;">
				<?php esc_html_e( 'PressArk uses three custom capabilities to control access. Individual actions (editing posts, managing orders, etc.) are still governed by standard WordPress capabilities.', 'pressark' ); ?>
			</p>
			<table style="border-collapse:collapse; width:100%; max-width:700px;">
				<thead>
					<tr>
						<th style="padding:10px 12px; border-bottom:2px solid #e2e8f0; text-align:left; color:#0f172a; font-size:13px;"><?php esc_html_e( 'Permission', 'pressark' ); ?></th>
						<th style="padding:10px 12px; border-bottom:2px solid #e2e8f0; text-align:left; color:#0f172a; font-size:13px;"><?php esc_html_e( 'Default Roles', 'pressark' ); ?></th>
						<th style="padding:10px 12px; border-bottom:2px solid #e2e8f0; text-align:left; color:#0f172a; font-size:13px;"><?php esc_html_e( 'Controls', 'pressark' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-size:13px; font-weight:600;"><?php esc_html_e( 'Use PressArk', 'pressark' ); ?></td>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:13px;"><?php esc_html_e( 'Administrators, Editors, Shop Managers', 'pressark' ); ?></td>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:13px;"><?php esc_html_e( 'Chat panel, Activity feed', 'pressark' ); ?></td>
					</tr>
					<tr>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#0f172a; font-size:13px; font-weight:600;"><?php esc_html_e( 'Manage Settings', 'pressark' ); ?></td>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:13px;"><?php esc_html_e( 'Administrators', 'pressark' ); ?></td>
						<td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:13px;"><?php esc_html_e( 'Settings, Insights, Dashboard widget', 'pressark' ); ?></td>
					</tr>
					<tr>
						<td style="padding:10px 12px; color:#0f172a; font-size:13px; font-weight:600;"><?php esc_html_e( 'Manage Automations', 'pressark' ); ?></td>
						<td style="padding:10px 12px; color:#64748b; font-size:13px;"><?php esc_html_e( 'Administrators', 'pressark' ); ?></td>
						<td style="padding:10px 12px; color:#64748b; font-size:13px;"><?php esc_html_e( 'Scheduled Prompts', 'pressark' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p style="color:#94a3b8; font-size:12px; margin-top:16px; margin-bottom:0;">
				<?php
				printf(
					/* translators: %1$s, %2$s, %3$s: capability slugs */
					esc_html__( 'To customize, use a role management plugin and assign %1$s, %2$s, or %3$s.', 'pressark' ),
					'<code>pressark_use</code>',
					'<code>pressark_manage_settings</code>',
					'<code>pressark_manage_automations</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	private function render_usage_stats_box(): void {
		$tracker = new PressArk_Usage_Tracker();
		$stats   = $tracker->get_monthly_stats();
		?>
		<div style="background:#fff;border:1px solid rgba(226,232,240,0.8);border-radius:12px;padding:32px;margin:20px 0;box-shadow:0 4px 12px rgba(0,0,0,0.02);">
			<h2 style="margin-top:0; color:#0f172a; margin-bottom: 24px;"><?php esc_html_e( "This Month's Usage", 'pressark' ); ?></h2>
			<table style="border-collapse:collapse; width:100%; max-width:600px;">
				<tr>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:14px; font-weight:500;"><?php esc_html_e( 'Edits used:', 'pressark' ); ?></td>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#0f172a; font-size:14px; font-weight:600;">
						<?php
						if ( $stats['is_pro'] ) {
							printf(
								/* translators: %s: number of edits used. */
								esc_html__( '%s (unlimited)', 'pressark' ),
								esc_html( number_format_i18n( absint( $stats['edits_used'] ) ) )
							);
						} else {
							printf(
								/* translators: 1: edits used, 2: edits limit. */
								esc_html__( '%1$s / %2$s', 'pressark' ),
								esc_html( number_format_i18n( absint( $stats['edits_used'] ) ) ),
								esc_html( number_format_i18n( absint( $stats['edits_limit'] ) ) )
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:14px; font-weight:500;"><?php esc_html_e( 'SEO scans run:', 'pressark' ); ?></td>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#0f172a; font-size:14px; font-weight:600;"><?php echo esc_html( $stats['seo_scans'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#64748b; font-size:14px; font-weight:500;"><?php esc_html_e( 'Security scans run:', 'pressark' ); ?></td>
					<td style="padding:14px 0; border-bottom:1px solid #f1f5f9; color:#0f172a; font-size:14px; font-weight:600;"><?php echo esc_html( $stats['security_scans'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:14px 0; color:#64748b; font-size:14px; font-weight:500;"><?php esc_html_e( 'Total actions:', 'pressark' ); ?></td>
					<td style="padding:14px 0; color:#0f172a; font-size:14px; font-weight:600;"><?php echo esc_html( $stats['total_actions'] ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}
}
