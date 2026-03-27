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
			__( 'Context Compression Model', 'pressark' ),
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

		$logo_url = $this->find_brand_image( array( 'WHITE-APP-LOGO', 'logo', 'icon', 'pressark-logo' ) );
		?>
		<div class="wrap">
			<div class="pressark-settings-header">
				<?php if ( $logo_url ) : ?>
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="PressArk">
				<?php endif; ?>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			</div>

			<?php $this->render_usage_stats_box(); ?>
			<?php $this->render_plan_capabilities_section(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'pressark_settings' );
				do_settings_sections( 'pressark' );
				submit_button( __( 'Save Settings', 'pressark' ) );
				?>
			</form>

			<?php $this->render_site_profile_section(); ?>
			<?php $this->render_content_index_section(); ?>
			<?php $this->render_permissions_section(); ?>
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
				<strong style="color:#0f172a;font-size:15px;display:flex;align-items:center;gap:8px;"><span style="color:#10b981;font-size:18px;">&#9889;</span> <?php
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
			echo '<span style="color:#00a32a;">&#9679;</span> ' . esc_html__( 'Connected to PressArk AI service', 'pressark' );
		} else {
			echo '<span style="color:#d63638;">&#9679;</span> ' . esc_html__( 'Unable to reach PressArk AI service', 'pressark' );
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
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about 1× credits.', 'pressark' ), $info['cost_class'] ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Pro Models (License Required)', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'pro' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_pro ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about %s credits.', 'pressark' ), $info['cost_class'], 'Value' === $info['cost_class'] ? '3×' : '8×' ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_pro ? '' : ' &#9889; Pro'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Team+ Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'team' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_team ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about %s credits.', 'pressark' ), $info['cost_class'], 'Premium' === $info['cost_class'] ? '15×' : '8×' ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_team ? '' : ' &#9889; Team+'; ?>
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
			&#128274; <?php
			printf(
				/* translators: %s: upgrade URL */
				wp_kses(
					__( 'Paid models require an active plan. <a href="%s">Upgrade in Freemius</a>', 'pressark' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $upgrade_url )
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
		$descriptions = $this->get_model_option_descriptions(
			__( 'Auto mode uses DeepSeek V3.2 for task-aware context compression. This affects continuation capsules only, not normal chat execution.', 'pressark' )
		);
		?>
		<select name="pressark_summarize_model" id="pressark-summarize-model-select">
			<option value="auto" <?php selected( $model, 'auto' ); ?>><?php esc_html_e( 'Auto (recommended)', 'pressark' ); ?></option>

			<optgroup label="<?php esc_attr_e( 'Free Tier Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'free' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about 1× credits.', 'pressark' ), $info['cost_class'] ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Pro Models (License Required)', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'pro' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_pro ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about %s credits.', 'pressark' ), $info['cost_class'], 'Value' === $info['cost_class'] ? '3×' : '8×' ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_pro ? '' : ' &#9889; Pro'; ?>
					</option>
				<?php endforeach; ?>
			</optgroup>

			<optgroup label="<?php esc_attr_e( 'Team+ Models', 'pressark' ); ?>">
				<?php foreach ( $models as $value => $info ) : ?>
					<?php if ( 'team' !== $info['group'] ) continue; ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $model, $value ); ?> <?php echo $is_team ? '' : 'disabled'; ?> title="<?php echo esc_attr( $info['cost_class'] ? sprintf( __( '%s models use about %s credits.', 'pressark' ), $info['cost_class'], 'Premium' === $info['cost_class'] ? '15×' : '8×' ) : '' ); ?>">
						<?php echo esc_html( $info['label'] ); ?><?php echo $is_team ? '' : ' &#9889; Team+'; ?>
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
			<?php esc_html_e( 'Auto mode uses DeepSeek V3.2 for task-aware context compression. This affects continuation capsules only, not normal chat execution.', 'pressark' ); ?>
		</p>
		<p class="description" style="margin-top:4px;">
			<?php esc_html_e( 'Used only when PressArk compacts a long-running request into a continuation capsule.', 'pressark' ); ?>
		</p>

		<?php if ( ! $is_pro ) : ?>
		<p class="description" style="color: #e94560; margin-top: 4px;">
			&#128274; <?php
			printf(
				/* translators: %s: upgrade URL */
				wp_kses(
					__( 'Paid models require an active plan. <a href="%s">Upgrade in Freemius</a>', 'pressark' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $upgrade_url )
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
			'custom'                      => __( 'Enter any OpenRouter-compatible model identifier below.', 'pressark' ),
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
		$is_byok   = PressArk_Entitlements::is_byok();
		$is_paid   = PressArk_Entitlements::is_paid_tier( $tier );
		$bank      = new PressArk_Token_Bank();
		$credits   = $bank->get_credits();
		$active    = (array) ( $credits['credits'] ?? array() );

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

		echo '<p>' . esc_html__( 'Purchased credits are used after your monthly plan credits are exhausted. Credit packs expire 12 months after purchase.', 'pressark' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 20px;">';
		foreach ( PressArk_Entitlements::CREDIT_PACKS as $pack_type => $pack ) {
			$pricing_id = (int) ( $pack['freemius_pricing_id'] ?? 0 );
			echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">';
			echo '<strong style="display:block;color:#0f172a;margin-bottom:6px;">' . esc_html( $pack['label'] ) . '</strong>';
			echo '<p style="margin:0 0 12px;color:#64748b;">$' . esc_html( number_format( $pack['price_cents'] / 100, 0 ) ) . '</p>';
			printf(
				'<button type="button" class="button button-secondary pressark-buy-credits" data-pricing-id="%d" data-pack="%s">%s</button>',
				$pricing_id,
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
				$product_id    = (string) PressArk_Entitlements::CREDITS_PRODUCT_ID;
				$public_key    = PressArk_Entitlements::CREDITS_PUBLIC_KEY;
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
						product_id:  '<?php echo (int) PressArk_Entitlements::CREDITS_PRODUCT_ID; ?>',
						plan_id:     '<?php echo (int) PressArk_Entitlements::CREDITS_PLAN_ID; ?>',
						public_key:  <?php echo wp_json_encode( PressArk_Entitlements::CREDITS_PUBLIC_KEY ); ?>,
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
			'<input type="number" name="pressark_retention_log_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Action log entries older than this are deleted. Default: 90 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_chat_field(): void {
		$value = (int) get_option( 'pressark_retention_chat_days', 180 );
		printf(
			'<input type="number" name="pressark_retention_chat_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Chat conversations not updated within this period are deleted. Default: 180 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_ledger_field(): void {
		$value = (int) get_option( 'pressark_retention_ledger_days', 365 );
		printf(
			'<input type="number" name="pressark_retention_ledger_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Credit usage records older than this are deleted. In-progress requests are never deleted. Default: 365 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_runs_field(): void {
		$value = (int) get_option( 'pressark_retention_runs_days', 30 );
		printf(
			'<input type="number" name="pressark_retention_runs_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Completed and failed execution runs older than this are deleted. Default: 30 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_tasks_field(): void {
		$value = (int) get_option( 'pressark_retention_tasks_days', 30 );
		printf(
			'<input type="number" name="pressark_retention_tasks_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Completed and failed tasks older than this are deleted. Default: 30 days.', 'pressark' ) . '</p>';
	}

	public function render_retention_automations_field(): void {
		$value = (int) get_option( 'pressark_retention_automations_days', 90 );
		printf(
			'<input type="number" name="pressark_retention_automations_days" value="%d" min="7" step="1" class="small-text" /> %s',
			$value,
			esc_html__( 'days', 'pressark' )
		);
		echo '<p class="description">' . esc_html__( 'Archived one-shot automations older than this are deleted. Active, paused, and failed automations are retained. Default: 90 days.', 'pressark' ) . '</p>';
	}

	/**
	 * Render bundled credit usage (read-only) in the billing section.
	 */
	private function render_token_budget_display(): void {
		$token_bank   = new PressArk_Token_Bank();
		$status       = $token_bank->get_status();
		$is_byok      = (bool) get_option( 'pressark_byok_enabled', false );
		$used         = (int) ( $status['icus_used'] ?? 0 );
		$monthly      = (int) ( $status['icu_budget'] ?? 100000 );
		$total        = (int) ( $status['total_remaining'] ?? $status['icus_remaining'] ?? $monthly );
		$pct          = (int) ( $status['percent_used'] ?? 0 );
		$at_limit     = ! empty( $status['at_limit'] );
		$warn         = ! empty( $status['warn'] );
		$credits_left = (int) ( $status['credits_remaining'] ?? 0 );
		$monthly_left = (int) ( $status['monthly_remaining'] ?? max( 0, $monthly - $used ) );
		$next_reset_at = (string) ( $status['next_reset_at'] ?? '' );
		$reset_label   = $next_reset_at ? wp_date( 'M j', strtotime( $next_reset_at ) ) : '';
		$upgrade_url  = pressark_get_upgrade_url();
		$store_anchor = admin_url( 'admin.php?page=pressark#pressark-credit-store' );

		if ( $is_byok ) {
			echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px;margin-top:12px;">';
			echo '<strong>' . esc_html__( 'BYOK Mode Active', 'pressark' ) . '</strong>';
			echo '<p style="margin:4px 0 0;">' . esc_html__( 'Using your own API key. No bundled credit limits apply.', 'pressark' ) . '</p>';
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
					/* translators: 1: credits used 2: billing-cycle credit budget 3: percentage */
					esc_html__( '%1$s / %2$s credits used this billing cycle (%3$d%%)', 'pressark' ),
					number_format( $used ),
					number_format( $monthly ),
					$pct
				);
				?>
			</p>
			<p style="display:none;margin:10px 0 0;color:#64748b;" aria-hidden="true">
				<?php
				printf(
					/* translators: 1: monthly credits remaining 2: purchased credits remaining 3: total credits available */
					esc_html__( 'Monthly remaining: %1$s · Purchased credits: %2$s · Total available: %3$s', 'pressark' ),
					number_format( $monthly_left ),
					number_format( $credits_left ),
					number_format( $total )
				);
				?>
			</p>
			<p style="margin:10px 0 0;color:#64748b;">
				<?php
				printf(
					/* translators: 1: billing-cycle credits remaining 2: purchased credits remaining 3: total credits remaining */
					esc_html__( 'Allowance remaining: %1$s · Purchased credits: %2$s · Total remaining: %3$s', 'pressark' ),
					number_format( $monthly_left ),
					number_format( $credits_left ),
					number_format( $total )
				);
				?>
			</p>
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
			<?php if ( ! empty( $status['monthly_exhausted'] ) && $credits_left > 0 ) : ?>
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
						number_format( (int) ( $status['raw_tokens_used'] ?? $used ) )
					);
					?>
				</p>
			</details>
			<?php if ( ! empty( $status['offline'] ) ) : ?>
				<p class="description" style="color:#f59e0b; margin-top:12px;"><?php esc_html_e( 'Token bank service unreachable. Showing cached data.', 'pressark' ); ?></p>
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
						<div style="background:<?php echo esc_attr( $exhausted ? '#ef4444' : '#3b82f6' ); ?>;height:100%;border-radius:6px;width:<?php echo $total_limit > 0 ? min( 100, round( $total_used / $total_limit * 100 ) ) : 0; ?>%;"></div>
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
							/* translators: %d: number of edits used */
							printf( esc_html__( '%d (unlimited)', 'pressark' ), $stats['edits_used'] );
						} else {
							/* translators: 1: edits used 2: edits limit */
							printf( esc_html__( '%1$d / %2$d', 'pressark' ), $stats['edits_used'], $stats['edits_limit'] );
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
