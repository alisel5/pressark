<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Onboarding wizard — full-page takeover on the PressArk admin page
 * until the user completes setup or skips.
 *
 * @since 4.3.0
 */
class PressArk_Onboarding {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Whether the onboarding wizard should be displayed.
	 */
	public static function should_show(): bool {
		return empty( get_user_meta( get_current_user_id(), 'pressark_onboarded', true ) );
	}

	/**
	 * Register onboarding endpoints.
	 */
	public function register_routes(): void {
		register_rest_route( 'pressark/v1', '/setup', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_setup' ),
			'permission_callback' => function () {
				return current_user_can( 'pressark_manage_settings' );
			},
			'args'                => array(
				'provider' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'api_key'  => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'model'    => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'api_url'  => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/claim-credits', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_claim_credits' ),
			'permission_callback' => function () {
				return current_user_can( 'pressark_manage_settings' );
			},
		) );

		register_rest_route( 'pressark/v1', '/sync-profile', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_sync_profile' ),
			'permission_callback' => function () {
				return current_user_can( 'pressark_manage_settings' );
			},
			'args'                => array(
				'generate_profile' => array(
					'type'              => 'boolean',
					'required'          => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'rebuild_index'    => array(
					'type'              => 'boolean',
					'required'          => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			),
		) );

		register_rest_route( 'pressark/v1', '/onboarded', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_onboarded' ),
			'permission_callback' => function () {
				return current_user_can( 'pressark_manage_settings' );
			},
		) );
	}

	/**
	 * Handle the claim-credits request: ensure proxy mode and trigger handshake.
	 */
	public function handle_claim_credits( WP_REST_Request $request ): WP_REST_Response {
		// Clear any BYOK settings to ensure managed credit mode is active.
		update_option( 'pressark_byok_enabled', false, false );
		delete_option( 'pressark_byok_api_key' );
		delete_option( 'pressark_api_provider' );
		delete_option( 'pressark_api_key' );
		$this->persist_summarize_preferences( $request );

		// Trigger token bank handshake (provisional or verified).
		$bank   = new PressArk_Token_Bank();
		$result = $bank->handshake();
		$index  = new PressArk_Content_Index();
		if ( $index->is_indexing_enabled() ) {
			$index->schedule_full_rebuild();
		}

		$verified = ! empty( $result['verified'] );
		$tier     = $result['tier'] ?? 'free';

		// v5.2.0: Always succeed — provisional tokens are fine for free tier.
		return new WP_REST_Response( array(
			'success'            => true,
			'tier'               => $tier,
			'verified'           => $verified,
			'message'            => $verified
				? 'Credits claimed! You\'re all set.'
				: 'Free credits activated! Connect your Freemius account later to manage billing.',
			'bank'               => $result,
			'harness_readiness'  => PressArk_Harness_Readiness::get_snapshot(),
		), 200 );
	}

	/**
	 * Save provider and encrypted API key during onboarding.
	 *
	 * After saving, makes a minimal API call (~$0.001) to validate the key
	 * so users get immediate feedback on typos or expired keys.
	 */
	public function handle_setup( WP_REST_Request $request ): WP_REST_Response {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		$api_key  = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
		$model    = sanitize_text_field( (string) $request->get_param( 'model' ) );
		$api_url  = esc_url_raw( (string) $request->get_param( 'api_url' ) );

		$valid = array( 'openrouter', 'openai', 'anthropic', 'deepseek', 'gemini', 'other' );
		if ( ! in_array( $provider, $valid, true ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid provider.', 'pressark' ) ), 400 );
		}

		if ( empty( $api_key ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'API key is required.', 'pressark' ) ), 400 );
		}

		if ( 'other' === $provider && empty( $api_url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Custom endpoint URL is required.', 'pressark' ) ), 400 );
		}

		if ( empty( $model ) ) {
			$model = $this->default_model_for_provider( $provider );
		}

		update_option( 'pressark_byok_enabled', true, false );
		update_option( 'pressark_byok_provider', $provider, false );
		update_option( 'pressark_byok_api_key', PressArk_Usage_Tracker::encrypt_value( $api_key ), false );
		update_option( 'pressark_byok_model', $model, false );
		if ( 'other' === $provider ) {
			update_option( 'pressark_byok_api_url', $api_url, false );
		} else {
			delete_option( 'pressark_byok_api_url' );
		}
		delete_option( 'pressark_api_provider' );
		delete_option( 'pressark_api_key' );
		$this->persist_summarize_preferences( $request );
		$index = new PressArk_Content_Index();
		if ( $index->is_indexing_enabled() ) {
			$index->schedule_full_rebuild();
		}

		// Validate the key with a minimal API call.
		$validation = $this->validate_api_key( $provider, $api_key, $model, $api_url );

		return new WP_REST_Response( array(
			'success'            => true,
			'provider'           => $provider,
			'model'              => $model,
			'validation_status'  => $validation['status'],  // 'valid', 'invalid', 'rate_limited', 'network_error'
			'validation_message' => $validation['message'],
			'harness_readiness'  => PressArk_Harness_Readiness::get_snapshot(),
		), 200 );
	}

	/**
	 * Generate local site context and schedule indexing from the sync step.
	 */
	public function handle_sync_profile( WP_REST_Request $request ): WP_REST_Response {
		$generate_profile = rest_sanitize_boolean( $request->get_param( 'generate_profile' ) );
		$rebuild_index    = rest_sanitize_boolean( $request->get_param( 'rebuild_index' ) );
		$profile          = array();
		$generated_at     = '';
		$index_scheduled  = false;

		if ( $generate_profile && class_exists( 'PressArk_Site_Profile' ) ) {
			$profile      = ( new PressArk_Site_Profile() )->generate();
			$generated_at = $profile['generated_at'] ?? '';
		} elseif ( class_exists( 'PressArk_Site_Profile' ) ) {
			$generated_at = (string) get_option( PressArk_Site_Profile::LAST_GENERATED_KEY, '' );
		}

		if ( $rebuild_index && class_exists( 'PressArk_Content_Index' ) ) {
			$index = new PressArk_Content_Index();
			if ( $index->is_indexing_enabled() ) {
				$index->schedule_full_rebuild();
				$index_scheduled = true;
			}
		}

		return new WP_REST_Response( array(
			'success'              => true,
			'profile_generated'    => ! empty( $profile ),
			'profile_generated_at' => $generated_at,
			'index_scheduled'      => $index_scheduled,
			'harness_readiness'    => PressArk_Harness_Readiness::get_snapshot(),
		), 200 );
	}

	/**
	 * Mark onboarding as complete for the current admin user.
	 */
	public function handle_onboarded(): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'pressark_onboarded', '1' );

		return new WP_REST_Response( array(
			'success'           => true,
			'harness_readiness' => PressArk_Harness_Readiness::get_snapshot(),
		), 200 );
	}

	/**
	 * Make a minimal API call to verify the key works.
	 *
	 * @return array{status: string, message: string}
	 */
	private function validate_api_key( string $provider, string $api_key, string $model = '', string $api_url = '' ): array {
		$endpoints = array(
			'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
			'openai'     => 'https://api.openai.com/v1/chat/completions',
			'anthropic'  => 'https://api.anthropic.com/v1/messages',
			'deepseek'   => 'https://api.deepseek.com/v1/chat/completions',
			'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
			'other'      => $api_url,
		);

		$endpoint = $endpoints[ $provider ];
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );
		if ( empty( $model ) ) {
			$model = $this->default_model_for_provider( $provider );
		}

		if ( 'anthropic' === $provider ) {
			$headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			);
			$body = array(
				'model'      => $model,
				'messages'   => $messages,
				'max_tokens' => 5,
			);
		} else {
			$headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			);
			if ( 'openrouter' === $provider ) {
				$headers['HTTP-Referer'] = home_url();
				$headers['X-Title']      = 'PressArk';
			}
			$body = array(
				'model'      => $model,
				'messages'   => $messages,
				'max_tokens' => 5,
			);
		}

		$response = wp_safe_remote_post( $endpoint, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'network_error',
				'message' => __( 'Could not verify the API key (network error). The key has been saved — PressArk will try again when you send your first message.', 'pressark' ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'status'  => 'valid',
				'message' => __( 'API key is valid.', 'pressark' ),
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return array(
				'status'  => 'invalid',
				'message' => __( 'API key is invalid or expired. Please check and try again.', 'pressark' ),
			);
		}

		if ( 429 === $code ) {
			return array(
				'status'  => 'rate_limited',
				'message' => __( 'API key is valid but rate-limited. You can proceed — PressArk will retry automatically.', 'pressark' ),
			);
		}

		// Any other error — treat as network-level uncertainty.
		return array(
			'status'  => 'network_error',
			'message' => __( 'Could not verify the API key (network error). The key has been saved — PressArk will try again when you send your first message.', 'pressark' ),
		);
	}

	private function default_model_for_provider( string $provider ): string {
		$models = array(
			'openrouter' => 'openai/gpt-5.4-mini',
			'openai'     => 'gpt-5.4-mini',
			'anthropic'  => 'claude-haiku-4-5-20251001',
			'deepseek'   => 'deepseek-chat',
			'gemini'     => 'gemini-2.5-flash',
			'other'      => 'gpt-5.4-mini',
		);

		return $models[ $provider ] ?? $models['openrouter'];
	}

	private function persist_summarize_preferences( WP_REST_Request $request ): void {
		$model = sanitize_text_field( (string) $request->get_param( 'summarize_model' ) );
		$allowed = array(
			'auto',
			'deepseek/deepseek-v3.2',
			'minimax/minimax-m2.7',
			'anthropic/claude-haiku-4.5',
			'moonshotai/kimi-k2.5',
			'z-ai/glm-5',
			'openai/gpt-5.4-mini',
			'anthropic/claude-sonnet-4.6',
			'anthropic/claude-opus-4.6',
			'openai/gpt-5.3-codex',
			'openai/gpt-5.4',
			'custom',
		);

		if ( '' === $model ) {
			return;
		}

		if ( ! in_array( $model, $allowed, true ) ) {
			$model = 'auto';
		}

		update_option( 'pressark_summarize_model', $model, false );

		if ( 'custom' === $model ) {
			update_option(
				'pressark_summarize_custom_model',
				sanitize_text_field( (string) $request->get_param( 'summarize_custom_model' ) ),
				false
			);
			return;
		}

		delete_option( 'pressark_summarize_custom_model' );
	}

	/**
	 * Render the full-page onboarding wizard.
	 */
	public static function render(): void {
		self::render_activation_page();
	}

	private static function render_activation_page(): void {
		$logo_url = '';
		$imgs_dir = PRESSARK_PATH . 'assets/imgs/';
		$imgs_url = PRESSARK_URL . 'assets/imgs/';
		foreach ( array( 'WHITE-APP-LOGO', 'logo', 'icon', 'pressark-logo' ) as $name ) {
			foreach ( array( 'png', 'svg', 'webp', 'jpg' ) as $ext ) {
				if ( file_exists( $imgs_dir . $name . '.' . $ext ) ) {
					$logo_url = $imgs_url . $name . '.' . $ext;
					break 2;
				}
			}
		}

		$rest_url    = esc_url_raw( rest_url( 'pressark/v1/' ) );
		$nonce       = wp_create_nonce( 'wp_rest' );
		$docs_url    = 'https://pressark.com/docs';
		$support_url = 'https://pressark.com/contact';

		$key_urls = array(
			'openrouter' => 'https://openrouter.ai/keys',
			'openai'     => 'https://platform.openai.com/api-keys',
			'anthropic'  => 'https://console.anthropic.com/settings/keys',
			'deepseek'   => 'https://platform.deepseek.com/api_keys',
			'gemini'     => 'https://aistudio.google.com/apikey',
			'other'      => $docs_url,
		);

		$provider_models = array(
			'openrouter' => 'openai/gpt-5.4-mini',
			'openai'     => 'gpt-5.4-mini',
			'anthropic'  => 'claude-haiku-4-5-20251001',
			'deepseek'   => 'deepseek-chat',
			'gemini'     => 'gemini-2.5-flash',
			'other'      => 'gpt-5.4-mini',
		);

		$provider_labels = array(
			'openrouter' => __( 'Get an OpenRouter key', 'pressark' ),
			'openai'     => __( 'Get an OpenAI key', 'pressark' ),
			'anthropic'  => __( 'Get an Anthropic key', 'pressark' ),
			'deepseek'   => __( 'Get a DeepSeek key', 'pressark' ),
			'gemini'     => __( 'Get a Gemini key', 'pressark' ),
			'other'      => __( 'Read BYOK setup docs', 'pressark' ),
		);
		?>
		<style>
			.pressark-activation {
				min-height: calc(100vh - 96px);
				margin: 0 -20px -65px;
				padding: 48px 24px 60px;
				background:
					radial-gradient(circle at 15% 0%, rgba(52, 87, 255, 0.12), transparent 34%),
					radial-gradient(circle at 90% 100%, rgba(91, 227, 255, 0.10), transparent 30%),
					linear-gradient(180deg, #f6f8fc 0%, #e9edf5 100%);
				color: #0b1220;
				font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}

			.pressark-activation * {
				box-sizing: border-box;
			}

			.pa-shell {
				width: min(760px, 100%);
				margin: 0 auto;
			}

			.pa-top {
				display: flex;
				flex-direction: column;
				align-items: center;
				gap: 18px;
				text-align: center;
				margin-bottom: 24px;
			}

			.pa-mark {
				width: 64px;
				height: 64px;
				border-radius: 18px;
				display: grid;
				place-items: center;
				position: relative;
				background: linear-gradient(135deg, #1a2d8a 0%, #2547d6 60%, #3457ff 100%);
				box-shadow: 0 18px 40px -16px rgba(26, 45, 138, 0.65);
				overflow: hidden;
			}

			.pa-mark::after {
				content: "";
				position: absolute;
				top: -3px;
				right: -3px;
				width: 16px;
				height: 16px;
				border-radius: 999px;
				background: #5be3ff;
				box-shadow: 0 0 0 4px #fff, 0 0 18px rgba(91, 227, 255, 0.8);
			}

			.pa-mark img {
				width: 42px;
				height: 42px;
				object-fit: contain;
			}

			.pa-mark-fallback {
				color: #fff;
				font-size: 22px;
				font-weight: 800;
				letter-spacing: 0;
			}

			.pa-stepper {
				display: inline-flex;
				align-items: center;
				gap: 10px;
				padding: 6px 10px;
				background: rgba(255, 255, 255, 0.72);
				border: 1px solid rgba(10, 22, 64, 0.08);
				border-radius: 999px;
				box-shadow: 0 1px 0 rgba(255, 255, 255, 0.9) inset;
			}

			.pa-step-pill {
				display: inline-flex;
				align-items: center;
				gap: 7px;
				border-radius: 999px;
				padding: 4px 10px;
				font-size: 12px;
				font-weight: 700;
				color: #64748b;
			}

			.pa-step-pill .pa-num {
				width: 18px;
				height: 18px;
				border-radius: 999px;
				display: inline-grid;
				place-items: center;
				background: #e2e8f0;
				color: #475569;
				font-size: 10px;
			}

			.pa-step-pill.is-active {
				color: #0b1220;
				background: rgba(52, 87, 255, 0.09);
			}

			.pa-step-pill.is-active .pa-num,
			.pa-step-pill.is-done .pa-num {
				background: linear-gradient(135deg, #1a2d8a 0%, #3457ff 100%);
				color: #fff;
			}

			.pa-step-line {
				width: 30px;
				height: 1px;
				background: #cbd5e1;
			}

			.pa-title h1 {
				margin: 0;
				color: #0b1220;
				font-size: 34px;
				line-height: 1.12;
				font-weight: 700;
				letter-spacing: 0;
			}

			.pa-title em {
				font-family: Georgia, "Times New Roman", serif;
				font-weight: 400;
				color: #2547d6;
			}

			.pa-title p {
				max-width: 520px;
				margin: 10px auto 0;
				color: #475569;
				font-size: 15px;
				line-height: 1.55;
			}

			.pa-card {
				background: #fff;
				border: 1px solid rgba(10, 22, 64, 0.08);
				border-radius: 20px;
				box-shadow: 0 30px 80px -32px rgba(10, 22, 64, 0.38);
				overflow: hidden;
			}

			.pa-card-body {
				padding: 30px 32px;
			}

			.pa-intro {
				display: grid;
				grid-template-columns: 46px 1fr;
				gap: 16px;
				margin-bottom: 22px;
			}

			.pa-tile {
				width: 46px;
				height: 46px;
				border-radius: 13px;
				display: grid;
				place-items: center;
				background: linear-gradient(135deg, #2547d6, #3457ff);
				color: #fff;
				font-weight: 800;
				box-shadow: 0 12px 26px -16px rgba(26, 45, 138, 0.7);
			}

			.pa-tile.teal {
				background: linear-gradient(135deg, #0a8aa0, #14b8c6);
			}

			.pa-kicker {
				margin-bottom: 3px;
				color: #2547d6;
				font-size: 11px;
				font-weight: 800;
				letter-spacing: 0.10em;
				text-transform: uppercase;
			}

			.pa-intro h2 {
				margin: 0;
				font-size: 19px;
				line-height: 1.3;
			}

			.pa-intro p {
				margin: 4px 0 0;
				color: #64748b;
				font-size: 13px;
				line-height: 1.55;
			}

			.pa-modes {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 14px;
				margin-bottom: 18px;
			}

			.pa-mode {
				border: 1px solid #e2e8f0;
				border-radius: 16px;
				background: #fbfbfd;
				padding: 18px;
				text-align: left;
				cursor: pointer;
				transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
			}

			.pa-mode:hover,
			.pa-mode.is-selected {
				border-color: #5e7bff;
				box-shadow: 0 16px 34px -26px rgba(26, 45, 138, 0.55);
				transform: translateY(-1px);
			}

			.pa-mode h3 {
				margin: 0 0 8px;
				color: #0b1220;
				font-size: 15px;
				font-weight: 800;
			}

			.pa-mode p {
				margin: 0;
				color: #475569;
				font-size: 13px;
				line-height: 1.5;
			}

			.pa-mode small {
				display: block;
				margin-top: 12px;
				color: #64748b;
				font-size: 12px;
				line-height: 1.45;
			}

			.pa-byok-panel {
				display: none;
				margin-top: 16px;
				padding: 18px;
				border: 1px solid #e2e8f0;
				border-radius: 16px;
				background: #f8fafc;
			}

			.pa-byok-panel.is-open {
				display: block;
			}

			.pa-field {
				margin-bottom: 14px;
				text-align: left;
			}

			.pa-field label {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 7px;
				color: #1b2740;
				font-size: 12px;
				font-weight: 800;
			}

			.pa-field input,
			.pa-field select {
				width: 100%;
				min-height: 42px;
				border: 1px solid #cbd5e1;
				border-radius: 12px;
				background: #fff;
				color: #0b1220;
				padding: 9px 12px;
				font-size: 14px;
				box-shadow: none;
			}

			.pa-field input:focus,
			.pa-field select:focus {
				border-color: #5e7bff;
				box-shadow: 0 0 0 4px rgba(52, 87, 255, 0.10);
				outline: 0;
			}

			.pa-key-link {
				color: #2547d6;
				font-size: 12px;
				font-weight: 700;
				text-decoration: none;
			}

			.pa-key-link:hover {
				text-decoration: underline;
			}

			.pa-actions {
				display: flex;
				align-items: center;
				justify-content: flex-end;
				gap: 10px;
				margin-top: 18px;
			}

			.pa-button {
				min-height: 42px;
				border: 0;
				border-radius: 12px;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 7px;
				padding: 0 18px;
				font-family: inherit;
				font-size: 13px;
				font-weight: 800;
				cursor: pointer;
				transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
			}

			.pa-button.primary {
				color: #fff;
				background: linear-gradient(135deg, #1a2d8a 0%, #2547d6 58%, #3457ff 100%);
				box-shadow: 0 14px 28px -18px rgba(26, 45, 138, 0.75);
			}

			.pa-button.secondary {
				color: #0b1220;
				background: #f1f5f9;
				border: 1px solid #e2e8f0;
			}

			.pa-button:hover {
				transform: translateY(-1px);
			}

			.pa-button:disabled {
				cursor: wait;
				opacity: .58;
				transform: none;
			}

			.pa-status {
				display: none;
				margin-top: 14px;
				border-radius: 12px;
				border: 1px solid #dbeafe;
				background: #eff6ff;
				color: #1d4ed8;
				padding: 11px 13px;
				font-size: 13px;
				line-height: 1.45;
				text-align: left;
			}

			.pa-status.is-visible {
				display: block;
			}

			.pa-status.is-error {
				border-color: #fee2e2;
				background: #fef2f2;
				color: #b91c1c;
			}

			.pa-status.is-success {
				border-color: #bbf7d0;
				background: #f0fdf4;
				color: #166534;
			}

			.pa-sync-list {
				display: grid;
				gap: 10px;
				margin: 18px 0;
			}

			.pa-check-row {
				display: grid;
				grid-template-columns: 20px 1fr;
				gap: 10px;
				padding: 13px;
				border: 1px solid #e2e8f0;
				border-radius: 13px;
				background: #fbfbfd;
				color: #1b2740;
			}

			.pa-check-row input {
				margin-top: 2px;
			}

			.pa-check-row strong {
				display: block;
				font-size: 13px;
			}

			.pa-check-row span {
				display: block;
				margin-top: 3px;
				color: #64748b;
				font-size: 12px;
				line-height: 1.45;
			}

			.pa-footer {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				margin-top: 16px;
				color: #64748b;
				font-size: 12px;
			}

			.pa-footer-links {
				display: inline-flex;
				gap: 14px;
			}

			.pa-footer a {
				color: #2547d6;
				font-weight: 700;
				text-decoration: none;
			}

			.pa-footer a:hover {
				text-decoration: underline;
			}

			.pa-panel {
				display: none;
			}

			.pa-panel.is-active {
				display: block;
			}

			@media (max-width: 720px) {
				.pressark-activation {
					margin-left: -10px;
					padding: 36px 14px 48px;
				}

				.pa-card-body {
					padding: 24px 20px;
				}

				.pa-modes {
					grid-template-columns: 1fr;
				}

				.pa-footer,
				.pa-actions {
					align-items: stretch;
					flex-direction: column;
				}

				.pa-button {
					width: 100%;
				}
			}
		</style>

		<div class="wrap">
			<div class="pressark-activation">
				<div class="pa-shell">
					<div class="pa-top">
						<div class="pa-mark" aria-hidden="true">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
							<?php else : ?>
								<span class="pa-mark-fallback">P</span>
							<?php endif; ?>
						</div>

						<div class="pa-stepper" aria-label="<?php esc_attr_e( 'Activation progress', 'pressark' ); ?>">
							<div class="pa-step-pill is-active" data-pa-step-pill="1"><span class="pa-num">1</span><?php esc_html_e( 'Connect', 'pressark' ); ?></div>
							<div class="pa-step-line" aria-hidden="true"></div>
							<div class="pa-step-pill" data-pa-step-pill="2"><span class="pa-num">2</span><?php esc_html_e( 'Sync', 'pressark' ); ?></div>
						</div>

						<div class="pa-title">
							<h1><?php esc_html_e( 'Activate your', 'pressark' ); ?> <em><?php esc_html_e( 'WordPress copilot', 'pressark' ); ?></em></h1>
							<p><?php esc_html_e( 'Choose how PressArk should power AI, then let it build private site context for better answers.', 'pressark' ); ?></p>
						</div>
					</div>

					<div class="pa-card">
						<div class="pa-card-body">
							<section class="pa-panel is-active" id="pa-step-connect">
								<div class="pa-intro">
									<div class="pa-tile">1</div>
									<div>
										<div class="pa-kicker"><?php esc_html_e( 'Connection', 'pressark' ); ?></div>
										<h2><?php esc_html_e( 'Pick your AI setup', 'pressark' ); ?></h2>
										<p><?php esc_html_e( 'Start with PressArk credits or connect your own API issuer. BYOK keys are encrypted and stored on this WordPress site.', 'pressark' ); ?></p>
									</div>
								</div>

								<div class="pa-modes">
									<button type="button" class="pa-mode" data-pa-mode="managed">
										<h3><?php esc_html_e( 'Use PressArk credits', 'pressark' ); ?></h3>
										<p><?php esc_html_e( 'No API key needed. Start free with 100K credits while PressArk handles the AI infrastructure.', 'pressark' ); ?></p>
										<small><?php esc_html_e( 'Free credit to try real PressArk workflows before you choose a plan.', 'pressark' ); ?></small>
									</button>

									<button type="button" class="pa-mode" data-pa-mode="byok">
										<h3><?php esc_html_e( 'Bring your own key', 'pressark' ); ?></h3>
										<p><?php esc_html_e( 'Choose OpenRouter, OpenAI, Anthropic, DeepSeek, Gemini, or a custom OpenAI-compatible endpoint.', 'pressark' ); ?></p>
										<small><?php esc_html_e( 'You pay your provider directly and PressArk uses your selected issuer for AI requests.', 'pressark' ); ?></small>
									</button>
								</div>

								<div class="pa-byok-panel" id="pa-byok-panel">
									<div class="pa-field">
										<label for="pa-provider"><?php esc_html_e( 'API issuer', 'pressark' ); ?></label>
										<select id="pa-provider">
											<option value="openrouter"><?php esc_html_e( 'OpenRouter', 'pressark' ); ?></option>
											<option value="openai"><?php esc_html_e( 'OpenAI', 'pressark' ); ?></option>
											<option value="anthropic"><?php esc_html_e( 'Anthropic', 'pressark' ); ?></option>
											<option value="deepseek"><?php esc_html_e( 'DeepSeek', 'pressark' ); ?></option>
											<option value="gemini"><?php esc_html_e( 'Google Gemini', 'pressark' ); ?></option>
											<option value="other"><?php esc_html_e( 'Custom OpenAI-compatible', 'pressark' ); ?></option>
										</select>
									</div>

									<div class="pa-field" id="pa-api-url-wrap" style="display:none">
										<label for="pa-api-url"><?php esc_html_e( 'Chat completions endpoint', 'pressark' ); ?></label>
										<input type="url" id="pa-api-url" placeholder="<?php esc_attr_e( 'https://example.com/v1/chat/completions', 'pressark' ); ?>" autocomplete="off">
									</div>

									<div class="pa-field">
										<label for="pa-model"><?php esc_html_e( 'Model', 'pressark' ); ?></label>
										<input type="text" id="pa-model" value="<?php echo esc_attr( $provider_models['openrouter'] ); ?>" autocomplete="off">
									</div>

									<div class="pa-field">
										<label for="pa-api-key">
											<span><?php esc_html_e( 'API key', 'pressark' ); ?></span>
											<a class="pa-key-link" id="pa-key-link" href="<?php echo esc_url( $key_urls['openrouter'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get an OpenRouter key', 'pressark' ); ?></a>
										</label>
										<input type="password" id="pa-api-key" placeholder="<?php esc_attr_e( 'Paste your API key', 'pressark' ); ?>" autocomplete="off">
									</div>
								</div>

								<div class="pa-status" id="pa-connect-status" role="status"></div>

								<div class="pa-actions">
									<button type="button" class="pa-button secondary" id="pa-skip"><?php esc_html_e( 'Skip for now', 'pressark' ); ?></button>
									<button type="button" class="pa-button primary" id="pa-connect-button"><?php esc_html_e( 'Continue', 'pressark' ); ?></button>
								</div>
							</section>

							<section class="pa-panel" id="pa-step-sync">
								<div class="pa-intro">
									<div class="pa-tile teal">2</div>
									<div>
										<div class="pa-kicker"><?php esc_html_e( 'Site context', 'pressark' ); ?></div>
										<h2><?php esc_html_e( 'Sync PressArk with this site', 'pressark' ); ?></h2>
										<p><?php esc_html_e( 'PressArk can generate a local site profile now and schedule your content index so answers are grounded in your real WordPress content.', 'pressark' ); ?></p>
									</div>
								</div>

								<div class="pa-sync-list">
									<label class="pa-check-row">
										<input type="checkbox" id="pa-generate-profile" checked>
										<span><strong><?php esc_html_e( 'Generate site profile', 'pressark' ); ?></strong><?php esc_html_e( 'Build the private site summary PressArk injects into AI requests.', 'pressark' ); ?></span>
									</label>
									<label class="pa-check-row">
										<input type="checkbox" id="pa-rebuild-index" checked>
										<span><strong><?php esc_html_e( 'Schedule content index', 'pressark' ); ?></strong><?php esc_html_e( 'Queue posts, pages, products, and metadata for retrieval grounding.', 'pressark' ); ?></span>
									</label>
								</div>

								<div class="pa-status is-visible" id="pa-sync-status" role="status"><?php esc_html_e( 'Sync will start automatically on this step.', 'pressark' ); ?></div>

								<div class="pa-actions">
									<button type="button" class="pa-button secondary" id="pa-run-sync"><?php esc_html_e( 'Run sync again', 'pressark' ); ?></button>
									<button type="button" class="pa-button primary" id="pa-open-pressark"><?php esc_html_e( 'Open PressArk', 'pressark' ); ?></button>
								</div>
							</section>
						</div>
					</div>

					<div class="pa-footer">
						<span><?php esc_html_e( 'Your key is encrypted on this server. AI requests run through PressArk managed credits or the provider you select.', 'pressark' ); ?></span>
						<span class="pa-footer-links">
							<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'pressark' ); ?></a>
							<a href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'pressark' ); ?></a>
						</span>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function () {
			var state = {
				mode: '',
				syncStarted: false
			};
			var restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var keyUrls = <?php echo wp_json_encode( $key_urls ); ?>;
			var modelDefaults = <?php echo wp_json_encode( $provider_models ); ?>;
			var keyLabels = <?php echo wp_json_encode( $provider_labels ); ?>;

			function get(id) {
				return document.getElementById(id);
			}

			function setStatus(id, message, type) {
				var el = get(id);
				if (!el) {
					return;
				}
				el.textContent = message;
				el.className = 'pa-status is-visible' + (type ? ' is-' + type : '');
			}

			function request(path, payload) {
				return fetch(restUrl + path, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					body: JSON.stringify(payload || {})
				}).then(function (response) {
					return response.json().then(function (data) {
						if (!response.ok || !data.success) {
							throw new Error(data.error || data.message || <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'pressark' ) ); ?>);
						}
						return data;
					});
				});
			}

			function setButtonBusy(button, busy, label) {
				button.disabled = busy;
				if (label) {
					button.textContent = label;
				}
			}

			function selectMode(mode) {
				state.mode = mode;
				document.querySelectorAll('[data-pa-mode]').forEach(function (card) {
					card.classList.toggle('is-selected', card.getAttribute('data-pa-mode') === mode);
				});
				get('pa-byok-panel').classList.toggle('is-open', mode === 'byok');
			}

			function showStep(step) {
				get('pa-step-connect').classList.toggle('is-active', step === 1);
				get('pa-step-sync').classList.toggle('is-active', step === 2);
				document.querySelectorAll('[data-pa-step-pill]').forEach(function (pill) {
					var value = parseInt(pill.getAttribute('data-pa-step-pill'), 10);
					pill.classList.toggle('is-active', value === step);
					pill.classList.toggle('is-done', value < step);
				});
				window.scrollTo({ top: 0, behavior: 'smooth' });
				if (step === 2 && !state.syncStarted) {
					runSync();
				}
			}

			function connectManaged() {
				var button = get('pa-connect-button');
				setButtonBusy(button, true, <?php echo wp_json_encode( __( 'Activating credits...', 'pressark' ) ); ?>);
				setStatus('pa-connect-status', <?php echo wp_json_encode( __( 'Claiming your PressArk starter credits...', 'pressark' ) ); ?>);
				request('claim-credits', {})
					.then(function () {
						setStatus('pa-connect-status', <?php echo wp_json_encode( __( 'Credits activated. Moving to site sync...', 'pressark' ) ); ?>, 'success');
						showStep(2);
					})
					.catch(function (error) {
						setStatus('pa-connect-status', error.message, 'error');
					})
					.finally(function () {
						setButtonBusy(button, false, <?php echo wp_json_encode( __( 'Continue', 'pressark' ) ); ?>);
					});
			}

			function connectByok() {
				var button = get('pa-connect-button');
				var provider = get('pa-provider').value;
				var apiKey = get('pa-api-key').value.trim();
				var model = get('pa-model').value.trim();
				var apiUrl = get('pa-api-url').value.trim();

				if (!apiKey) {
					setStatus('pa-connect-status', <?php echo wp_json_encode( __( 'Paste your API key first.', 'pressark' ) ); ?>, 'error');
					return;
				}

				if (provider === 'other' && !apiUrl) {
					setStatus('pa-connect-status', <?php echo wp_json_encode( __( 'Enter the custom chat completions endpoint first.', 'pressark' ) ); ?>, 'error');
					return;
				}

				setButtonBusy(button, true, <?php echo wp_json_encode( __( 'Saving key...', 'pressark' ) ); ?>);
				setStatus('pa-connect-status', <?php echo wp_json_encode( __( 'Saving your encrypted key and validating it with the selected provider...', 'pressark' ) ); ?>);
				request('setup', {
					provider: provider,
					api_key: apiKey,
					model: model,
					api_url: apiUrl
				}).then(function (data) {
					if (data.validation_status === 'invalid') {
						throw new Error(data.validation_message || <?php echo wp_json_encode( __( 'The provider rejected that key.', 'pressark' ) ); ?>);
					}
					setStatus('pa-connect-status', data.validation_message || <?php echo wp_json_encode( __( 'BYOK is connected. Moving to site sync...', 'pressark' ) ); ?>, data.validation_status === 'valid' ? 'success' : '');
					showStep(2);
				}).catch(function (error) {
					setStatus('pa-connect-status', error.message, 'error');
				}).finally(function () {
					setButtonBusy(button, false, <?php echo wp_json_encode( __( 'Continue', 'pressark' ) ); ?>);
				});
			}

			function runSync() {
				var button = get('pa-run-sync');
				var generateProfile = get('pa-generate-profile').checked;
				var rebuildIndex = get('pa-rebuild-index').checked;
				state.syncStarted = true;

				if (!generateProfile && !rebuildIndex) {
					setStatus('pa-sync-status', <?php echo wp_json_encode( __( 'Sync skipped. You can generate the profile later from settings.', 'pressark' ) ); ?>);
					return;
				}

				setButtonBusy(button, true, <?php echo wp_json_encode( __( 'Syncing...', 'pressark' ) ); ?>);
				setStatus('pa-sync-status', <?php echo wp_json_encode( __( 'Generating site profile and scheduling indexing...', 'pressark' ) ); ?>);
				request('sync-profile', {
					generate_profile: generateProfile,
					rebuild_index: rebuildIndex
				}).then(function (data) {
					var message = <?php echo wp_json_encode( __( 'Site profile generated. Content indexing has been scheduled in the background.', 'pressark' ) ); ?>;
					if (data.profile_generated && !data.index_scheduled) {
						message = <?php echo wp_json_encode( __( 'Site profile generated. Content indexing was already disabled, so no rebuild was scheduled.', 'pressark' ) ); ?>;
					} else if (!data.profile_generated && data.index_scheduled) {
						message = <?php echo wp_json_encode( __( 'Content indexing has been scheduled in the background.', 'pressark' ) ); ?>;
					}
					setStatus('pa-sync-status', message, 'success');
				}).catch(function (error) {
					setStatus('pa-sync-status', error.message, 'error');
				}).finally(function () {
					setButtonBusy(button, false, <?php echo wp_json_encode( __( 'Run sync again', 'pressark' ) ); ?>);
				});
			}

			function finish(openPanel) {
				request('onboarded', {}).finally(function () {
					if (openPanel) {
						sessionStorage.setItem('pressark_panel_open', 'open');
					}
					window.location.reload();
				});
			}

			document.querySelectorAll('[data-pa-mode]').forEach(function (card) {
				card.addEventListener('click', function () {
					selectMode(card.getAttribute('data-pa-mode'));
				});
			});

			get('pa-provider').addEventListener('change', function () {
				var provider = this.value;
				get('pa-model').value = modelDefaults[provider] || modelDefaults.openrouter;
				get('pa-api-url-wrap').style.display = provider === 'other' ? 'block' : 'none';
				get('pa-key-link').href = keyUrls[provider] || keyUrls.openrouter;
				get('pa-key-link').textContent = keyLabels[provider] || keyLabels.openrouter;
			});

			get('pa-connect-button').addEventListener('click', function () {
				if (!state.mode) {
					selectMode('managed');
				}
				if (state.mode === 'byok') {
					connectByok();
					return;
				}
				connectManaged();
			});

			get('pa-run-sync').addEventListener('click', runSync);
			get('pa-open-pressark').addEventListener('click', function () {
				finish(true);
			});
			get('pa-skip').addEventListener('click', function () {
				finish(true);
			});
		}());
		</script>
		<?php
	}
}
