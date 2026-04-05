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
	 * Register the /setup and /claim-credits endpoints for onboarding.
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
			),
		) );

		register_rest_route( 'pressark/v1', '/claim-credits', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_claim_credits' ),
			'permission_callback' => function () {
				return current_user_can( 'pressark_manage_settings' );
			},
		) );
	}

	/**
	 * Handle the claim-credits request: ensure proxy mode and trigger handshake.
	 */
	public function handle_claim_credits( WP_REST_Request $request ): WP_REST_Response {
		// Clear any BYOK settings to ensure proxy mode is active.
		delete_option( 'pressark_api_provider' );
		delete_option( 'pressark_api_key' );
		$this->persist_summarize_preferences( $request );

		// Trigger token bank handshake (provisional or verified).
		$bank   = new PressArk_Token_Bank();
		$result = $bank->handshake();

		$verified = ! empty( $result['verified'] );
		$tier     = $result['tier'] ?? 'free';

		// v5.2.0: Always succeed — provisional tokens are fine for free tier.
		return new WP_REST_Response( array(
			'success'            => true,
			'tier'               => $tier,
			'verified'           => $verified,
			'message'            => $verified
				? 'Credits claimed! You\'re all set.'
				: 'Free credits activated! Connect your Freemius account later to unlock paid plans.',
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
		$provider = $request->get_param( 'provider' );
		$api_key  = $request->get_param( 'api_key' );

		$valid = array( 'openrouter', 'openai', 'anthropic', 'deepseek', 'gemini' );
		if ( ! in_array( $provider, $valid, true ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'Invalid provider.', 'pressark' ) ), 400 );
		}

		if ( empty( $api_key ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => __( 'API key is required.', 'pressark' ) ), 400 );
		}

		update_option( 'pressark_api_provider', $provider, false );
		update_option( 'pressark_api_key', PressArk_Usage_Tracker::encrypt_value( $api_key ), false );
		$this->persist_summarize_preferences( $request );

		// Validate the key with a minimal API call.
		$validation = $this->validate_api_key( $provider, $api_key );

		return new WP_REST_Response( array(
			'success'            => true,
			'validation_status'  => $validation['status'],  // 'valid', 'invalid', 'rate_limited', 'network_error'
			'validation_message' => $validation['message'],
			'harness_readiness'  => PressArk_Harness_Readiness::get_snapshot(),
		), 200 );
	}

	/**
	 * Make a minimal API call to verify the key works.
	 *
	 * @return array{status: string, message: string}
	 */
	private function validate_api_key( string $provider, string $api_key ): array {
		$endpoints = array(
			'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
			'openai'     => 'https://api.openai.com/v1/chat/completions',
			'anthropic'  => 'https://api.anthropic.com/v1/messages',
			'deepseek'   => 'https://api.deepseek.com/v1/chat/completions',
			'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
		);

		$endpoint = $endpoints[ $provider ];
		$messages = array( array( 'role' => 'user', 'content' => 'Hi' ) );

		if ( 'anthropic' === $provider ) {
			$headers = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			);
			$body = array(
				'model'      => 'claude-haiku-4-5-20251001',
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

			$models = array(
				'openrouter' => 'openai/gpt-5.4-mini',
				'openai'     => 'gpt-5.4-mini',
				'deepseek'   => 'deepseek-chat',
			);
			$body = array(
				'model'      => $models[ $provider ],
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

		$rest_url = esc_url_raw( rest_url( 'pressark/v1/' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		$key_urls = array(
			'openrouter' => 'https://openrouter.ai/keys',
			'openai'     => 'https://platform.openai.com/api-keys',
			'anthropic'  => 'https://console.anthropic.com/settings/keys',
			'deepseek'   => 'https://platform.deepseek.com/api_keys',
			'gemini'     => 'https://aistudio.google.com/apikey',
		);
		?>
		<style>
			/* Hide the normal settings page footer/notices when wizard is active */
			.pressark-onboarding-wrap {
				max-width: 640px;
				margin: 40px auto;
				font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				color: #0F172A;
			}

			/* ── Step indicator ── */
			.pw-ob-steps {
				display: flex;
				align-items: center;
				justify-content: center;
				gap: 0;
				margin-bottom: 40px;
			}
			.pw-ob-step {
				width: 36px;
				height: 36px;
				border-radius: 50%;
				background: #F1F5F9;
				color: #94A3B8;
				display: flex;
				align-items: center;
				justify-content: center;
				font-weight: 600;
				font-size: 14px;
				transition: all 0.3s ease;
				flex-shrink: 0;
			}
			.pw-ob-step-active {
				background: #0F766E;
				color: #fff;
				box-shadow: 0 0 0 4px rgba(15, 118, 110, 0.15);
			}
			.pw-ob-step-done {
				background: #D1FAE5;
				color: #059669;
			}
			.pw-ob-step-line {
				width: 48px;
				height: 2px;
				background: #E2E8F0;
				flex-shrink: 0;
			}

			/* ── Panel card ── */
			.pw-ob-panel {
				background: #fff;
				border: 1px solid rgba(226, 232, 240, 0.8);
				border-radius: 16px;
				padding: 48px 40px;
				box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
				text-align: center;
			}
			.pw-ob-panel h1 {
				font-size: 28px;
				font-weight: 700;
				margin: 16px 0 8px;
				color: #0F172A;
			}
			.pw-ob-panel h2 {
				font-size: 22px;
				font-weight: 700;
				margin: 0 0 8px;
				color: #0F172A;
			}
			.pw-ob-panel > p {
				font-size: 15px;
				color: #64748B;
				line-height: 1.6;
				margin: 0 0 28px;
			}

			/* ── Logo ── */
			.pw-ob-logo {
				width: 64px;
				height: 64px;
				border-radius: 16px;
			}

			/* ── Buttons ── */
			.pw-ob-btn-primary {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
				background: #0F766E;
				color: #fff;
				border: none;
				border-radius: 10px;
				padding: 12px 32px;
				font-size: 15px;
				font-weight: 600;
				cursor: pointer;
				transition: background 0.2s;
				font-family: inherit;
			}
			.pw-ob-btn-primary:hover {
				background: #115E59;
			}
			.pw-ob-btn-primary:disabled {
				background: #CBD5E1;
				cursor: not-allowed;
			}
			.pw-ob-btn-secondary {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				gap: 6px;
				background: #F1F5F9;
				color: #0F172A;
				border: 1px solid #E2E8F0;
				border-radius: 10px;
				padding: 10px 24px;
				font-size: 14px;
				font-weight: 500;
				cursor: pointer;
				transition: all 0.2s;
				font-family: inherit;
			}
			.pw-ob-btn-secondary:hover {
				background: #E2E8F0;
			}
			.pw-ob-btn-secondary:disabled {
				opacity: 0.5;
				cursor: not-allowed;
			}

			/* ── Form fields ── */
			.pw-ob-field {
				text-align: left;
				margin-bottom: 20px;
			}
			.pw-ob-field label {
				display: block;
				font-size: 13px;
				font-weight: 600;
				color: #475569;
				margin-bottom: 6px;
			}
			.pw-ob-field select,
			.pw-ob-field input[type="password"],
			.pw-ob-field input[type="text"] {
				width: 100%;
				padding: 10px 14px;
				border: 1px solid #E2E8F0;
				border-radius: 8px;
				font-size: 14px;
				font-family: inherit;
				color: #0F172A;
				background: #fff;
				transition: border-color 0.2s;
				box-sizing: border-box;
			}
			.pw-ob-field select:focus,
			.pw-ob-field input:focus {
				outline: none;
				border-color: #0F766E;
				box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1);
			}
			.pw-ob-key-link {
				display: inline-block;
				font-size: 13px;
				color: #0F766E;
				text-decoration: none;
				margin-bottom: 20px;
			}
			.pw-ob-key-link:hover {
				text-decoration: underline;
			}

			/* ── Test result ── */
			.pw-ob-result {
				padding: 10px 14px;
				border-radius: 8px;
				font-size: 13px;
				font-weight: 500;
				margin-bottom: 16px;
				text-align: left;
			}
			.pw-ob-result-success {
				background: #ECFDF5;
				color: #059669;
				border: 1px solid #D1FAE5;
			}
			.pw-ob-result-error {
				background: #FEF2F2;
				color: #DC2626;
				border: 1px solid #FEE2E2;
			}
			.pw-ob-result-info {
				background: #F0F9FF;
				color: #0284C7;
				border: 1px solid #E0F2FE;
			}

			/* ── Step 2 button row ── */
			.pw-ob-btn-row {
				display: flex;
				gap: 12px;
				justify-content: center;
				margin-top: 8px;
			}

			/* ── Quick actions ── */
			.pw-ob-quick-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
				justify-content: center;
				margin-bottom: 28px;
			}
			.pw-ob-quick-actions button {
				background: #F0FDFA;
				border: 1px solid #CCFBF1;
				border-radius: 8px;
				padding: 10px 16px;
				font-size: 13px;
				font-weight: 500;
				color: #0F766E;
				cursor: pointer;
				transition: all 0.2s;
				font-family: inherit;
			}
			.pw-ob-quick-actions button:hover {
				background: #CCFBF1;
				border-color: #99F6E4;
			}

			/* ── Skip link ── */
			.pw-ob-skip {
				display: block;
				text-align: center;
				margin-top: 20px;
				font-size: 13px;
				color: #94A3B8;
				text-decoration: none;
			}
			.pw-ob-skip:hover {
				color: #64748B;
				text-decoration: underline;
			}
		</style>

		<div class="wrap">
			<div class="pressark-onboarding-wrap">

				<!-- Step indicator -->
				<div class="pw-ob-steps">
					<div class="pw-ob-step pw-ob-step-active" data-step="1"><span>1</span></div>
					<div class="pw-ob-step-line"></div>
					<div class="pw-ob-step" data-step="2"><span>2</span></div>
					<div class="pw-ob-step-line"></div>
					<div class="pw-ob-step" data-step="3"><span>3</span></div>
				</div>

				<!-- Step 1: Welcome -->
				<div class="pw-ob-panel" id="pw-ob-step-1">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="PressArk" class="pw-ob-logo" />
					<?php endif; ?>
					<h1><?php esc_html_e( 'Welcome to PressArk', 'pressark' ); ?></h1>
					<p><?php esc_html_e( "PressArk is your AI co-pilot for WordPress. Let's get you set up in 60 seconds.", 'pressark' ); ?></p>
					<button type="button" class="pw-ob-btn-primary" onclick="pwOnboarding.goTo(2)">
						<?php esc_html_e( 'Get Started', 'pressark' ); ?>
					</button>
				</div>

				<!-- Step 2: Choose AI Setup -->
				<div class="pw-ob-panel" id="pw-ob-step-2" style="display:none">
					<h2><?php esc_html_e( 'Choose your AI setup', 'pressark' ); ?></h2>
					<p><?php esc_html_e( 'Start with free credits or connect your own API key.', 'pressark' ); ?></p>

					<button type="button" id="pw-ob-claim-btn" class="pw-ob-btn-primary" style="padding:14px 36px;font-size:16px;width:100%;margin-bottom:12px;" onclick="pwOnboarding.claimCredits()">
						<?php esc_html_e( 'Claim free 100K credits', 'pressark' ); ?>
					</button>
					<p style="font-size:13px;color:#94A3B8;margin:0 0 24px;"><?php esc_html_e( 'No API key needed — start using PressArk right away.', 'pressark' ); ?></p>

					<div id="pw-ob-claim-result" style="display:none"></div>

					<a href="#" id="pw-ob-byok-toggle" style="display:inline-block;font-size:13px;color:#64748B;text-decoration:none;cursor:pointer;" onclick="pwOnboarding.toggleByok(); return false;">
						<?php esc_html_e( 'Or bring your own API key', 'pressark' ); ?> &rsaquo;
					</a>

					<div id="pw-ob-byok-section" style="display:none;margin-top:20px;border-top:1px solid #E2E8F0;padding-top:20px;">
						<div class="pw-ob-field">
							<label for="pw-ob-provider"><?php esc_html_e( 'Provider', 'pressark' ); ?></label>
							<select id="pw-ob-provider">
								<option value="openrouter" selected><?php esc_html_e( 'OpenRouter (Recommended for beginners)', 'pressark' ); ?></option>
								<option value="openai"><?php esc_html_e( 'OpenAI', 'pressark' ); ?></option>
								<option value="anthropic"><?php esc_html_e( 'Anthropic', 'pressark' ); ?></option>
								<option value="deepseek"><?php esc_html_e( 'DeepSeek', 'pressark' ); ?></option>
								<option value="gemini"><?php esc_html_e( 'Google Gemini', 'pressark' ); ?></option>
							</select>
						</div>

						<div class="pw-ob-field">
							<label for="pw-ob-api-key"><?php esc_html_e( 'API Key', 'pressark' ); ?></label>
							<input type="password" id="pw-ob-api-key" placeholder="<?php esc_attr_e( 'Paste your API key here', 'pressark' ); ?>" />
						</div>

						<a href="<?php echo esc_url( $key_urls['openrouter'] ); ?>" target="_blank" rel="noopener" id="pw-ob-key-link" class="pw-ob-key-link">
							<?php esc_html_e( 'Get a free OpenRouter key', 'pressark' ); ?> &rarr;
						</a>

						<div id="pw-ob-test-result" style="display:none"></div>

						<div class="pw-ob-btn-row">
							<button type="button" id="pw-ob-test-btn" class="pw-ob-btn-secondary" onclick="pwOnboarding.testConnection()">
								<?php esc_html_e( 'Test Connection', 'pressark' ); ?>
							</button>
							<button type="button" id="pw-ob-next-2" class="pw-ob-btn-primary" disabled onclick="pwOnboarding.goTo(3)">
								<?php esc_html_e( 'Next', 'pressark' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Step 3: Done -->
				<div class="pw-ob-panel" id="pw-ob-step-3" style="display:none">
					<h2><?php esc_html_e( "You're all set!", 'pressark' ); ?></h2>
					<p><?php esc_html_e( 'Here are 5 things you can try:', 'pressark' ); ?></p>

					<div class="pw-ob-quick-actions">
						<button type="button" data-message="<?php esc_attr_e( 'Run a security check on my site and fix any issues', 'pressark' ); ?>">
							<?php echo pressark_icon( 'shield' ); ?> <?php esc_html_e( 'Security Scan', 'pressark' ); ?>
						</button>
						<button type="button" data-message="<?php esc_attr_e( "Scan my site's SEO and suggest improvements", 'pressark' ); ?>">
							<?php echo pressark_icon( 'barChart' ); ?> <?php esc_html_e( 'SEO Audit', 'pressark' ); ?>
						</button>
						<button type="button" data-message="<?php esc_attr_e( "Write a new blog post that matches my site's tone and style", 'pressark' ); ?>">
							<?php echo pressark_icon( 'pen' ); ?> <?php esc_html_e( 'Write a Blog Post', 'pressark' ); ?>
						</button>
						<button type="button" data-message="<?php esc_attr_e( 'Rewrite my homepage content to be more engaging', 'pressark' ); ?>">
							<?php echo pressark_icon( 'house' ); ?> <?php esc_html_e( 'Update Homepage', 'pressark' ); ?>
						</button>
						<button type="button" data-message="<?php esc_attr_e( "Check my site's performance and loading speed", 'pressark' ); ?>">
							<?php echo pressark_icon( 'zap' ); ?> <?php esc_html_e( 'Check Speed', 'pressark' ); ?>
						</button>
					</div>

					<button type="button" class="pw-ob-btn-primary" id="pw-ob-finish" onclick="pwOnboarding.finish()">
						<?php esc_html_e( 'Open PressArk', 'pressark' ); ?>
					</button>
				</div>

				<a href="#" id="pw-ob-skip" class="pw-ob-skip" onclick="pwOnboarding.skip(); return false;">
					<?php esc_html_e( 'Skip setup', 'pressark' ); ?>
				</a>

			</div>
		</div>

		<script>
		var pwOnboarding = {
			restUrl: <?php echo wp_json_encode( $rest_url ); ?>,
			nonce: <?php echo wp_json_encode( $nonce ); ?>,
			keyUrls: <?php echo wp_json_encode( $key_urls ); ?>,

			getSummarizePrefs: function () {
				var modelInput = document.getElementById('pw-ob-summarize-model');
				var customInput = document.getElementById('pw-ob-summarize-custom');
				return {
					summarize_model: modelInput ? modelInput.value : 'auto',
					summarize_custom_model: customInput ? customInput.value.trim() : ''
				};
			},

			goTo: function (step) {
				var panels = document.querySelectorAll('.pw-ob-panel');
				for (var i = 0; i < panels.length; i++) {
					panels[i].style.display = 'none';
				}
				var target = document.getElementById('pw-ob-step-' + step);
				if (target) {
					target.style.display = 'block';
				}
				var indicators = document.querySelectorAll('.pw-ob-step');
				for (var j = 0; j < indicators.length; j++) {
					var s = parseInt(indicators[j].dataset.step, 10);
					indicators[j].classList.remove('pw-ob-step-active', 'pw-ob-step-done');
					if (s === step) {
						indicators[j].classList.add('pw-ob-step-active');
					} else if (s < step) {
						indicators[j].classList.add('pw-ob-step-done');
					}
				}
				window.scrollTo({ top: 0, behavior: 'smooth' });
			},

			toggleByok: function () {
				var section = document.getElementById('pw-ob-byok-section');
				var toggle  = document.getElementById('pw-ob-byok-toggle');
				if (section.style.display === 'none') {
					section.style.display = 'block';
					toggle.innerHTML = <?php echo wp_json_encode( __( 'Or bring your own API key', 'pressark' ) ); ?> + ' &lsaquo;';
				} else {
					section.style.display = 'none';
					toggle.innerHTML = <?php echo wp_json_encode( __( 'Or bring your own API key', 'pressark' ) ); ?> + ' &rsaquo;';
				}
			},

			claimCredits: function () {
				var btn      = document.getElementById('pw-ob-claim-btn');
				var resultEl = document.getElementById('pw-ob-claim-result');
				var self     = this;

				btn.disabled = true;
				btn.textContent = <?php echo wp_json_encode( __( 'Claiming credits...', 'pressark' ) ); ?>;

				// Claim credits via handshake.
				fetch(self.restUrl + 'claim-credits', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': self.nonce },
					body: JSON.stringify(self.getSummarizePrefs())
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) {
						fetch(self.restUrl + 'onboarded', {
							method: 'POST',
							headers: { 'X-WP-Nonce': self.nonce }
						}).finally(function () {
							self.goTo(3);
						});
					} else {
						btn.disabled = false;
						btn.textContent = <?php echo wp_json_encode( __( 'Claim free 100K credits', 'pressark' ) ); ?>;
						resultEl.style.display = 'block';
						resultEl.className = 'pw-ob-result pw-ob-result-error';
						resultEl.textContent = data.error || <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'pressark' ) ); ?>;
					}
				})
				.catch(function () {
					// Even if handshake fails, proceed — credits can be claimed later.
					btn.disabled = false;
					btn.textContent = <?php echo wp_json_encode( __( 'Claim free 100K credits', 'pressark' ) ); ?>;
					resultEl.style.display = 'block';
					resultEl.className = 'pw-ob-result pw-ob-result-error';
					resultEl.textContent = <?php echo wp_json_encode( __( 'Could not reach PressArk right now. Please try again in a moment.', 'pressark' ) ); ?>;
				});
			},

			testConnection: function () {
				var provider = document.getElementById('pw-ob-provider').value;
				var apiKey   = document.getElementById('pw-ob-api-key').value.trim();
				var resultEl = document.getElementById('pw-ob-test-result');
				var testBtn  = document.getElementById('pw-ob-test-btn');
				var nextBtn  = document.getElementById('pw-ob-next-2');
				var self     = this;

				if (!apiKey) {
					resultEl.style.display = 'block';
					resultEl.className = 'pw-ob-result pw-ob-result-error';
					resultEl.textContent = <?php echo wp_json_encode( __( 'Please enter your API key.', 'pressark' ) ); ?>;
					return;
				}

				testBtn.disabled = true;
				testBtn.textContent = <?php echo wp_json_encode( __( 'Testing...', 'pressark' ) ); ?>;
				resultEl.style.display = 'block';
				resultEl.className = 'pw-ob-result pw-ob-result-info';
				resultEl.textContent = <?php echo wp_json_encode( __( 'Saving key and validating with provider...', 'pressark' ) ); ?>;

				fetch(self.restUrl + 'setup', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': self.nonce },
					body: JSON.stringify(Object.assign({ provider: provider, api_key: apiKey }, self.getSummarizePrefs()))
				})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					testBtn.disabled = false;
					testBtn.textContent = <?php echo wp_json_encode( __( 'Test Connection', 'pressark' ) ); ?>;

					if (!data.success) {
						throw new Error(data.error || 'Failed to save key.');
					}

					var status = data.validation_status;

					if (status === 'valid') {
						resultEl.className = 'pw-ob-result pw-ob-result-success';
						resultEl.textContent = <?php echo wp_json_encode( __( 'Connected successfully!', 'pressark' ) ); ?>;
						nextBtn.disabled = false;
						// Mark onboarded for BYOK path.
						fetch(self.restUrl + 'onboarded', {
							method: 'POST',
							headers: { 'X-WP-Nonce': self.nonce }
						});
					} else if (status === 'invalid') {
						resultEl.className = 'pw-ob-result pw-ob-result-error';
						resultEl.textContent = data.validation_message;
					} else if (status === 'rate_limited') {
						resultEl.className = 'pw-ob-result pw-ob-result-info';
						resultEl.textContent = data.validation_message;
						nextBtn.disabled = false;
					} else {
						// network_error — key saved, user can proceed.
						resultEl.className = 'pw-ob-result pw-ob-result-info';
						resultEl.textContent = data.validation_message;
						nextBtn.disabled = false;
					}
				})
				.catch(function (err) {
					testBtn.disabled = false;
					testBtn.textContent = <?php echo wp_json_encode( __( 'Test Connection', 'pressark' ) ); ?>;
					resultEl.className = 'pw-ob-result pw-ob-result-error';
					resultEl.textContent = err.message || <?php echo wp_json_encode( __( 'Connection failed.', 'pressark' ) ); ?>;
				});
			},

			skip: function () {
				var self = this;
				fetch(self.restUrl + 'onboarded', {
					method: 'POST',
					headers: { 'X-WP-Nonce': self.nonce }
				}).then(function () {
					sessionStorage.setItem('pressark_panel_open', 'open');
					window.location.reload();
				});
			},

			finish: function (message) {
				if (message) {
					sessionStorage.setItem('pressark_auto_message', message);
				}
				sessionStorage.setItem('pressark_panel_open', 'open');
				window.location.reload();
			}
		};

		// Bind provider change to update key link.
		document.getElementById('pw-ob-provider').addEventListener('change', function () {
			var provider = this.value;
			var linkEl   = document.getElementById('pw-ob-key-link');
			var urls     = pwOnboarding.keyUrls;
			var labels   = {
				openrouter: <?php echo wp_json_encode( __( 'Get a free OpenRouter key', 'pressark' ) ); ?>,
				openai:     <?php echo wp_json_encode( __( 'Get an OpenAI key', 'pressark' ) ); ?>,
				anthropic:  <?php echo wp_json_encode( __( 'Get an Anthropic key', 'pressark' ) ); ?>,
				deepseek:   <?php echo wp_json_encode( __( 'Get a DeepSeek key', 'pressark' ) ); ?>,
				gemini:     <?php echo wp_json_encode( __( 'Get a Gemini key', 'pressark' ) ); ?>
			};
			if (urls[provider]) {
				linkEl.href = urls[provider];
				linkEl.textContent = (labels[provider] || 'Get an API key') + ' \u2192';
			}
		});

		var summarizeModel = document.getElementById('pw-ob-summarize-model');
		if (summarizeModel) {
			summarizeModel.addEventListener('change', function () {
				var customWrap = document.getElementById('pw-ob-summarize-custom-wrap');
				if (customWrap) {
					customWrap.style.display = this.value === 'custom' ? 'block' : 'none';
				}
			});
		}

		// Bind quick-action buttons.
		var quickBtns = document.querySelectorAll('.pw-ob-quick-actions button');
		for (var q = 0; q < quickBtns.length; q++) {
			quickBtns[q].addEventListener('click', function () {
				var msg = this.getAttribute('data-message');
				if (msg) {
					sessionStorage.setItem('pressark_auto_message', msg);
				}
				pwOnboarding.finish(msg);
			});
		}
		</script>
		<?php
	}
}
