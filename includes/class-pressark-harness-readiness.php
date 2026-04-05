<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical readiness snapshot for the local PressArk harness.
 */
class PressArk_Harness_Readiness {

	private const REST_NAMESPACE = 'pressark/v1';
	private const REST_ROUTE     = '/harness-readiness';

	/**
	 * Optional plugin dependencies keyed by operation registry slug.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const OPTIONAL_DEPENDENCIES = array(
		'woocommerce' => array(
			'label'   => 'WooCommerce',
			'summary' => 'WooCommerce-powered tools are unavailable until WooCommerce is active.',
		),
		'elementor'   => array(
			'label'   => 'Elementor',
			'summary' => 'Elementor-specific tools are unavailable until Elementor is active.',
		),
	);

	/**
	 * Local resource dependencies grouped by tool family.
	 *
	 * @var array<string,string[]>
	 */
	private const GROUP_RESOURCE_DEPENDENCIES = array(
		'content'      => array( 'site_profile', 'content_index' ),
		'generation'   => array( 'site_profile', 'content_index' ),
		'seo'          => array( 'site_profile', 'content_index' ),
		'index'        => array( 'content_index' ),
		'profile'      => array( 'site_profile' ),
		'woocommerce'  => array( 'site_profile', 'content_index' ),
		'elementor'    => array( 'site_profile' ),
		'design'       => array( 'site_profile' ),
		'templates'    => array( 'site_profile' ),
		'scheduled'    => array( 'background' ),
	);

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_snapshot' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'pressark_manage_settings' );
				},
			)
		);
	}

	public function handle_get_snapshot(): WP_REST_Response {
		return new WP_REST_Response( self::get_snapshot(), 200 );
	}

	/**
	 * Build the current harness readiness snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_snapshot(): array {
		$license        = new PressArk_License();
		$tier           = $license->get_tier();
		$is_byok        = PressArk_Entitlements::is_byok();
		$transport_mode = $is_byok
			? 'byok'
			: ( PressArk_AI_Connector::is_proxy_mode() ? 'proxy' : 'direct' );

		$billing      = self::build_billing_facet( $tier, $transport_mode, $is_byok );
		$provider     = self::build_provider_facet( $tier, $transport_mode, $is_byok );
		$ai_core      = self::build_ai_core_facet( $billing, $provider );
		$site_profile = self::build_site_profile_facet();
		$content_index = self::build_content_index_facet();
		$background   = self::build_background_facet();
		$tool_groups  = self::build_tool_group_facets( $ai_core, $site_profile, $content_index, $background );

		$overall_state = $ai_core['state'];
		foreach ( array( $site_profile, $content_index, $background ) as $facet ) {
			if ( 'blocked' === $facet['state'] || 'degraded' === $facet['state'] ) {
				$overall_state = self::worst_state( $overall_state, 'degraded' );
			}
		}

		$issues = array();
		foreach ( array( $ai_core, $site_profile, $content_index, $background ) as $facet ) {
			foreach ( (array) ( $facet['issues'] ?? array() ) as $issue ) {
				$issues[] = $issue;
			}
		}
		$issues = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $issues ) ) ) );

		return array(
			'contract'       => 'HarnessReadiness',
			'version'        => 1,
			'generated_at'   => gmdate( 'c' ),
			'state'          => $overall_state,
			'label'          => self::state_label( $overall_state ),
			'is_ready'       => 'ready' === $overall_state,
			'usable'         => 'blocked' !== $overall_state,
			'blocked'        => 'blocked' === $overall_state,
			'summary'        => self::overall_summary( $overall_state, $ai_core, $site_profile, $content_index, $background ),
			'issues'         => $issues,
			'transport_mode' => $transport_mode,
			'tier'           => sanitize_key( (string) $tier ),
			'facets'         => array(
				'ai_core'       => $ai_core,
				'billing'       => $billing,
				'provider'      => $provider,
				'site_profile'  => $site_profile,
				'content_index' => $content_index,
				'background'    => $background,
			),
			'tool_groups'    => $tool_groups,
		);
	}

	/**
	 * Render a compact list of notable tool-group dependency issues.
	 *
	 * @param array<string,mixed> $snapshot Snapshot from get_snapshot().
	 * @param int                 $limit    Maximum rows to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function summarize_problem_groups( array $snapshot, int $limit = 8 ): array {
		$rows = array();
		foreach ( (array) ( $snapshot['tool_groups'] ?? array() ) as $group => $data ) {
			if ( 'ready' === ( $data['state'] ?? 'ready' ) ) {
				continue;
			}
			if ( empty( $data['requires'] ) && empty( $data['dependency_issues'] ) ) {
				continue;
			}
			$rows[] = array(
				'group'   => sanitize_key( (string) $group ),
				'label'   => sanitize_text_field( (string) ( $data['label'] ?? ucfirst( (string) $group ) ) ),
				'state'   => sanitize_key( (string) ( $data['state'] ?? 'degraded' ) ),
				'summary' => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$state_cmp = self::state_weight( $right['state'] ) <=> self::state_weight( $left['state'] );
				if ( 0 !== $state_cmp ) {
					return $state_cmp;
				}

				return strcmp( (string) $left['group'], (string) $right['group'] );
			}
		);

		return array_slice( $rows, 0, max( 0, $limit ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_ai_core_facet( array $billing, array $provider ): array {
		$state = self::worst_state( (string) $billing['state'], (string) $provider['state'] );
		$issues = array_values(
			array_unique(
				array_merge(
					(array) ( $billing['issues'] ?? array() ),
					(array) ( $provider['issues'] ?? array() )
				)
			)
		);

		$summary = 'ready' === $state
			? 'The AI transport, credentials, and billing path are ready.'
			: ( 'blocked' === $state
				? 'The AI transport is blocked by billing or provider configuration.'
				: 'The AI transport is usable but one or more core dependencies are degraded.' );

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'transport_mode' => sanitize_key( (string) ( $provider['mode'] ?? '' ) ),
				'billing_mode'   => sanitize_key( (string) ( $billing['mode'] ?? '' ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_billing_facet( string $tier, string $transport_mode, bool $is_byok ): array {
		if ( $is_byok ) {
			return self::facet(
				'ready',
				'Bundled billing is bypassed because BYOK mode is enabled.',
				array(),
				array(
					'mode'                        => 'byok',
					'handshake_state'             => 'byok',
					'service_state'               => 'local',
					'billing_authority'           => 'byok',
					'last_successful_contact_at'  => '',
					'last_successful_handshake_at'=> '',
					'at_limit'                    => false,
				)
			);
		}

		$bank             = new PressArk_Token_Bank();
		$status           = $bank->get_status();
		$contact_snapshot = $bank->get_contact_snapshot();
		$handshake        = $bank->get_handshake_snapshot();
		$site_token       = (string) get_option( 'pressark_site_token', '' );
		$verified_option  = (bool) get_option( 'pressark_handshake_verified', false );

		$handshake_state = sanitize_key(
			(string) ( $status['billing_handshake_state']
				?? $handshake['handshake_state']
				?? ( '' !== $site_token ? ( $verified_option ? 'verified' : 'provisional' ) : 'missing' ) )
		);
		if ( '' === $handshake_state ) {
			$handshake_state = '' !== $site_token ? ( $verified_option ? 'verified' : 'provisional' ) : 'missing';
		}

		$service_state = sanitize_key(
			(string) ( $status['billing_service_state']
				?? $status['service_state']
				?? ( $contact_snapshot['last_failure_at'] ? 'degraded' : 'normal' ) )
		);

		$issues = array();
		$state  = 'ready';

		if ( '' === $site_token ) {
			$state    = 'blocked';
			$issues[] = 'No token-bank site token is stored locally yet.';
		}

		if ( ! empty( $status['at_limit'] ) ) {
			$state    = 'blocked';
			$issues[] = 'Bundled credits are exhausted, so metered AI work is blocked.';
		}

		if ( in_array( $service_state, array( 'degraded', 'offline_assisted' ), true ) ) {
			$state = self::worst_state( $state, 'degraded' );
			$issues[] = 'Token-bank connectivity is degraded, so PressArk is relying on cached billing state.';
		}

		if ( empty( $contact_snapshot['last_successful_contact_at'] ) ) {
			$state = self::worst_state( $state, '' !== $site_token ? 'degraded' : 'blocked' );
			$issues[] = 'PressArk has not recorded a successful recent contact with the token bank.';
		}

		$summary = 'Verified token-bank handshake and live bundled billing are ready.';
		if ( 'provisional' === $handshake_state ) {
			$summary = 'A provisional token-bank handshake is active; bundled billing is usable but not Freemius-verified yet.';
		} elseif ( 'blocked' === $state && ! empty( $status['at_limit'] ) ) {
			$summary = 'Bundled billing is blocked because no spendable bundled credits remain.';
		} elseif ( 'blocked' === $state ) {
			$summary = 'Bundled billing is blocked because the token-bank handshake is incomplete.';
		} elseif ( 'degraded' === $state ) {
			$summary = 'Bundled billing is usable, but token-bank freshness or reachability is degraded.';
		}

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'mode'                         => 'bundled',
				'transport_mode'               => sanitize_key( $transport_mode ),
				'tier'                         => sanitize_key( (string) ( $status['tier'] ?? $tier ) ),
				'handshake_state'              => $handshake_state,
				'service_state'                => $service_state,
				'billing_authority'            => sanitize_key( (string) ( $status['billing_authority'] ?? 'token_bank' ) ),
				'last_successful_contact_at'   => sanitize_text_field( (string) ( $contact_snapshot['last_successful_contact_at'] ?? '' ) ),
				'last_contact_path'            => sanitize_text_field( (string) ( $contact_snapshot['last_successful_contact_path'] ?? '' ) ),
				'last_failure_at'              => sanitize_text_field( (string) ( $contact_snapshot['last_failure_at'] ?? '' ) ),
				'last_failure_error'           => sanitize_text_field( (string) ( $contact_snapshot['last_failure_error'] ?? '' ) ),
				'last_successful_handshake_at' => sanitize_text_field( (string) ( $handshake['last_successful_handshake_at'] ?? '' ) ),
				'last_successful_handshake'    => array(
					'tier'        => sanitize_key( (string) ( $handshake['tier'] ?? '' ) ),
					'verified'    => ! empty( $handshake['verified'] ),
					'provisional' => ! empty( $handshake['provisional'] ),
				),
				'site_token_present'           => '' !== $site_token,
				'verified_handshake'           => ! empty( $status['verified_handshake'] ),
				'provisional_handshake'        => ! empty( $status['provisional_handshake'] ),
				'at_limit'                     => ! empty( $status['at_limit'] ),
				'total_remaining'              => (int) ( $status['total_remaining'] ?? 0 ),
				'budget_pressure_state'        => sanitize_key( (string) ( $status['budget_pressure_state'] ?? 'normal' ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_provider_facet( string $tier, string $transport_mode, bool $is_byok ): array {
		$tracker   = new PressArk_Usage_Tracker();
		$connector = new PressArk_AI_Connector( $tier );
		$issues    = array();
		$state     = 'ready';
		$provider  = '';
		$model     = '';
		$key_saved = false;
		$key_valid = true;

		$allowed_providers = array( 'openrouter', 'openai', 'anthropic', 'deepseek', 'gemini' );

		if ( $is_byok ) {
			$provider      = (string) $tracker->get_byok_provider();
			$model         = (string) get_option( 'pressark_byok_model', 'gpt-5.4-mini' );
			$encrypted_key = (string) get_option( 'pressark_byok_api_key', '' );
			$decrypted_key = $tracker->get_byok_api_key();
			$key_saved     = '' !== $encrypted_key;
			$key_valid     = '' !== $decrypted_key;

			if ( ! in_array( $provider, $allowed_providers, true ) ) {
				$state    = 'blocked';
				$issues[] = 'BYOK provider is missing or invalid.';
			}
			if ( ! $key_saved ) {
				$state    = 'blocked';
				$issues[] = 'BYOK is enabled but no API key is stored.';
			} elseif ( ! $key_valid ) {
				$state    = 'blocked';
				$issues[] = get_transient( 'pressark_auth_key_rotated' )
					? 'The stored BYOK key can no longer be decrypted after an AUTH_KEY rotation.'
					: 'The stored BYOK key could not be decrypted and must be re-entered.';
			}
			if ( '' === trim( $model ) ) {
				$state    = 'blocked';
				$issues[] = 'BYOK model selection is empty.';
			}
		} elseif ( 'direct' === $transport_mode ) {
			$provider      = (string) get_option( 'pressark_api_provider', 'openrouter' );
			$model         = $connector->get_model();
			$encrypted_key = (string) get_option( 'pressark_api_key', '' );
			$decrypted_key = $encrypted_key ? PressArk_Usage_Tracker::decrypt_value( $encrypted_key ) : '';
			$key_saved     = '' !== $encrypted_key;
			$key_valid     = '' !== $decrypted_key;

			if ( ! in_array( $provider, $allowed_providers, true ) ) {
				$state    = 'blocked';
				$issues[] = 'Direct provider selection is missing or invalid.';
			}
			if ( ! $key_saved ) {
				$state    = 'blocked';
				$issues[] = 'Direct provider mode is enabled but no API key is stored.';
			} elseif ( ! $key_valid ) {
				$state    = 'blocked';
				$issues[] = get_transient( 'pressark_auth_key_rotated' )
					? 'The stored direct-provider key can no longer be decrypted after an AUTH_KEY rotation.'
					: 'The stored direct-provider key could not be decrypted and must be re-entered.';
			}
			if ( '' === trim( $model ) ) {
				$state    = 'blocked';
				$issues[] = 'Direct provider model selection is empty.';
			}
		} else {
			$provider = 'pressark_proxy';
			$model    = $connector->get_model();
		}

		$supports_native_tools = $connector->supports_native_tools();
		$supports_tool_search  = $connector->supports_tool_search();

		$summary = 'AI requests will route through the bundled PressArk proxy.';
		if ( $is_byok ) {
			$summary = 'BYOK transport is configured and ready.';
			if ( 'blocked' === $state ) {
				$summary = 'BYOK transport is blocked by local provider or credential configuration.';
			}
		} elseif ( 'direct' === $transport_mode ) {
			$summary = 'Direct provider transport is configured and ready.';
			if ( 'blocked' === $state ) {
				$summary = 'Direct provider transport is blocked by local provider or credential configuration.';
			}
		}

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'mode'                  => sanitize_key( $transport_mode ),
				'provider'              => sanitize_key( (string) $provider ),
				'model'                 => sanitize_text_field( (string) $model ),
				'key_saved'             => $key_saved,
				'key_valid'             => $key_valid,
				'supports_native_tools' => $supports_native_tools,
				'supports_tool_search'  => $supports_tool_search,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_site_profile_facet(): array {
		$profiler      = new PressArk_Site_Profile();
		$profile       = $profiler->get();
		$generated_at  = (string) get_option( PressArk_Site_Profile::LAST_GENERATED_KEY, '' );
		$needs_refresh = $profiler->needs_refresh();
		$age_hours     = self::hours_since( $generated_at );
		$issues        = array();
		$state         = 'ready';

		if ( ! is_array( $profile ) || empty( $profile['ai_summary'] ) ) {
			$state    = 'degraded';
			$issues[] = 'Site profile has not been generated yet.';
		} elseif ( $needs_refresh ) {
			$state    = 'degraded';
			$issues[] = 'Site profile exists but is older than the weekly freshness target.';
		}

		$summary = 'Site profile is present and fresh enough for prompt guidance.';
		if ( 'degraded' === $state && empty( $profile ) ) {
			$summary = 'Site profile is missing, so prompt grounding is limited.';
		} elseif ( 'degraded' === $state ) {
			$summary = 'Site profile is present but stale, so brand/context guidance may lag behind the site.';
		}

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'exists'                    => is_array( $profile ),
				'generated_at'              => sanitize_text_field( $generated_at ),
				'age_hours'                 => $age_hours,
				'needs_refresh'             => $needs_refresh,
				'refresh_scheduled_at'      => self::format_scheduled_hook( 'pressark_refresh_profile' ),
				'initial_generation_pending'=> false !== wp_next_scheduled( 'pressark_generate_profile' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_content_index_facet(): array {
		$index      = new PressArk_Content_Index();
		$stats      = $index->get_stats();
		$freshness  = $index->get_freshness_stats();
		$runtime    = (array) ( $stats['runtime'] ?? array() );
		$issues     = array();
		$state      = 'ready';
		$enabled    = ! empty( $stats['index_enabled'] );
		$total      = (int) ( $stats['total_chunks'] ?? 0 );
		$running    = ! empty( $runtime['running'] );
		$last_sync  = (string) get_option( 'pressark_index_last_sync', '' );

		if ( ! $enabled ) {
			$state    = 'degraded';
			$issues[] = 'Content index is disabled.';
		} elseif ( $running ) {
			$state    = 'degraded';
			$issues[] = 'Content index work is still running in the background.';
		} elseif ( $total <= 0 ) {
			$state    = 'degraded';
			$issues[] = 'Content index is enabled but no chunks are available yet.';
		} elseif ( (int) ( $freshness['stale'] ?? 0 ) > 0 && (float) ( $freshness['stale_percent'] ?? 0 ) >= 5.0 ) {
			$state    = 'degraded';
			$issues[] = sprintf(
				'%s%% of indexed chunks are stale.',
				number_format_i18n( (float) ( $freshness['stale_percent'] ?? 0 ), 1 )
			);
		}

		$summary = 'Content index is available and fresh enough for retrieval.';
		if ( ! $enabled ) {
			$summary = 'Content index is disabled, so retrieval grounding is unavailable.';
		} elseif ( $running ) {
			$summary = 'Content index is rebuilding or syncing, so retrieval freshness is temporarily degraded.';
		} elseif ( $total <= 0 ) {
			$summary = 'Content index is empty, so retrieval grounding is not available yet.';
		} elseif ( 'degraded' === $state ) {
			$summary = 'Content index is present but lagging behind site changes.';
		}

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'enabled'          => $enabled,
				'running'          => $running,
				'total_chunks'     => $total,
				'total_posts'      => (int) ( $stats['total_posts_indexed'] ?? 0 ),
				'total_words'      => (int) ( $stats['total_words'] ?? 0 ),
				'last_sync'        => sanitize_text_field( (string) ( $stats['last_sync'] ?? '' ) ),
				'last_sync_raw'    => sanitize_text_field( $last_sync ),
				'last_sync_age_hours' => self::hours_since( $last_sync ),
				'stale_chunks'     => (int) ( $freshness['stale'] ?? 0 ),
				'stale_percent'    => (float) ( $freshness['stale_percent'] ?? 0 ),
				'behind_source'    => (int) ( $freshness['behind_source'] ?? 0 ),
				'older_48h'        => (int) ( $freshness['older_48h'] ?? 0 ),
				'indexed_types'    => array_values( array_map( 'sanitize_key', (array) ( $stats['indexed_types'] ?? array() ) ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_background_facet(): array {
		global $wpdb;

		$queue       = new PressArk_Task_Queue();
		$store       = new PressArk_Task_Store();
		$counts      = $store->status_counts( 0 );
		$table       = PressArk_Task_Store::table_name();
		$queued      = (int) ( $counts['queued'] ?? 0 );
		$running     = (int) ( $counts['running'] ?? 0 );
		$overdue     = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE status = 'queued'
			 AND created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 2 MINUTE )"
		);
		$stale_running = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status = 'running'
				 AND started_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MINUTE )",
				PressArk_Task_Store::STALE_TIMEOUT_MINUTES
			)
		);

		$action_scheduler = self::get_action_scheduler_stats();
		$scheduled_hooks  = array(
			'cleanup_tasks'         => self::format_scheduled_hook( 'pressark_cleanup_tasks' ),
			'refresh_profile'       => self::format_scheduled_hook( 'pressark_refresh_profile' ),
			'daily_index_sync'      => self::format_scheduled_hook( 'pressark_daily_index_sync' ),
			'dispatch_automations'  => self::format_scheduled_hook( 'pressark_dispatch_automations' ),
			'kick_as_runner'        => self::format_scheduled_hook( 'pressark_kick_as_runner' ),
		);
		$missing_hooks = array_keys(
			array_filter(
				$scheduled_hooks,
				static function ( string $timestamp ): bool {
					return '' === $timestamp;
				}
			)
		);

		$issues = array();
		$state  = 'ready';

		if ( $overdue > 0 ) {
			$state    = 'degraded';
			$issues[] = sprintf( '%d queued task(s) have been overdue for more than two minutes.', $overdue );
		}

		if ( $stale_running > 0 ) {
			$state    = 'degraded';
			$issues[] = sprintf( '%d running task(s) have exceeded the stale timeout.', $stale_running );
		}

		if ( ! empty( $action_scheduler['enabled'] ) && (int) ( $action_scheduler['pending_pressark'] ?? 0 ) > 25 ) {
			$state    = 'degraded';
			$issues[] = sprintf(
				'%d pending PressArk Action Scheduler job(s) are waiting to run.',
				(int) $action_scheduler['pending_pressark']
			);
		}

		if ( ! empty( $missing_hooks ) ) {
			$state    = 'degraded';
			$issues[] = 'One or more recurring PressArk scheduler hooks are not currently scheduled.';
		}

		$summary = 'Background queue and scheduler backends are healthy.';
		if ( 'degraded' === $state ) {
			$summary = 'Background execution is usable, but the queue or scheduler needs attention.';
		}

		return self::facet(
			$state,
			$summary,
			$issues,
			array(
				'backend'           => sanitize_key( $queue->get_backend_name() ),
				'queued_tasks'      => $queued,
				'running_tasks'     => $running,
				'overdue_queued'    => $overdue,
				'stale_running'     => $stale_running,
				'scheduled_hooks'   => $scheduled_hooks,
				'missing_hooks'     => array_values( array_map( 'sanitize_key', $missing_hooks ) ),
				'action_scheduler'  => $action_scheduler,
			)
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function build_tool_group_facets( array $ai_core, array $site_profile, array $content_index, array $background ): array {
		$result = array();
		$groups = PressArk_Operation_Registry::group_names();
		sort( $groups );

		$facet_map = array(
			'site_profile'  => $site_profile,
			'content_index' => $content_index,
			'background'    => $background,
		);

		foreach ( $groups as $group ) {
			$tool_names = PressArk_Operation_Registry::tool_names_for_group( $group );
			$requires   = array();
			foreach ( $tool_names as $tool_name ) {
				$required = PressArk_Operation_Registry::get_requires( $tool_name );
				if ( $required ) {
					$requires[ $required ] = true;
				}
			}

			$requires_list     = array_keys( $requires );
			$dependency_issues = array();
			$state             = $ai_core['state'];
			$summary           = 'This tool group is ready.';
			$relevant          = true;

			if ( ! empty( $requires_list ) ) {
				foreach ( $requires_list as $required ) {
					if ( self::dependency_available( $required ) ) {
						continue;
					}

					$state    = 'blocked';
					$relevant = false;
					$meta     = self::OPTIONAL_DEPENDENCIES[ $required ] ?? array(
						'label'   => ucfirst( $required ),
						'summary' => ucfirst( $required ) . ' is not active.',
					);
					$dependency_issues[] = sanitize_text_field( (string) $meta['summary'] );
				}
			}

			if ( 'blocked' !== $state && isset( self::GROUP_RESOURCE_DEPENDENCIES[ $group ] ) ) {
				foreach ( self::GROUP_RESOURCE_DEPENDENCIES[ $group ] as $facet_key ) {
					$facet = $facet_map[ $facet_key ] ?? array();
					if ( empty( $facet ) || 'ready' === ( $facet['state'] ?? 'ready' ) ) {
						continue;
					}

					$state = self::worst_state( $state, (string) $facet['state'] );
					$dependency_issues[] = sanitize_text_field( (string) ( $facet['summary'] ?? '' ) );
				}
			}

			if ( 'ready' === $state ) {
				$summary = 'This tool group is ready.';
			} elseif ( 'blocked' === $state ) {
				$summary = ! empty( $dependency_issues )
					? $dependency_issues[0]
					: 'This tool group is blocked by an unmet dependency.';
			} else {
				$summary = ! empty( $dependency_issues )
					? $dependency_issues[0]
					: 'This tool group is usable but degraded.';
			}

			$result[ $group ] = array(
				'state'             => $state,
				'label'             => self::state_label( $state ),
				'summary'           => $summary,
				'available'         => 'blocked' !== $state,
				'relevant'          => $relevant,
				'requires'          => array_values( array_map( 'sanitize_key', $requires_list ) ),
				'dependency_issues' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $dependency_issues ) ) ) ),
				'tool_count'        => count( $tool_names ),
			);
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_action_scheduler_stats(): array {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return array(
				'enabled'                 => false,
				'pending_pressark'        => 0,
				'oldest_pending_pressark' => '',
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array(
				'enabled'                 => false,
				'pending_pressark'        => 0,
				'oldest_pending_pressark' => '',
			);
		}

		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE hook LIKE %s
				 AND status = %s
				 AND scheduled_date_gmt <= UTC_TIMESTAMP()",
				'pressark%',
				'pending'
			)
		);
		$oldest = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(scheduled_date_gmt) FROM {$table}
				 WHERE hook LIKE %s
				 AND status = %s",
				'pressark%',
				'pending'
			)
		);

		return array(
			'enabled'                 => true,
			'pending_pressark'        => $pending,
			'oldest_pending_pressark' => sanitize_text_field( $oldest ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function facet( string $state, string $summary, array $issues = array(), array $data = array() ): array {
		return array_merge(
			array(
				'state'   => $state,
				'label'   => self::state_label( $state ),
				'usable'  => 'blocked' !== $state,
				'summary' => sanitize_text_field( $summary ),
				'issues'  => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $issues ) ) ) ),
			),
			$data
		);
	}

	private static function dependency_available( string $dependency ): bool {
		return match ( $dependency ) {
			'woocommerce' => class_exists( 'WooCommerce' ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ),
			default       => false,
		};
	}

	private static function format_scheduled_hook( string $hook ): string {
		$next = wp_next_scheduled( $hook );
		if ( false === $next ) {
			return '';
		}

		return gmdate( 'c', (int) $next );
	}

	private static function hours_since( string $value ): float {
		$value = trim( $value );
		if ( '' === $value || '1970-01-01 00:00:00' === $value || 'Never' === $value || 'Disabled' === $value ) {
			return 0.0;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return 0.0;
		}

		return round( max( 0, time() - $timestamp ) / HOUR_IN_SECONDS, 1 );
	}

	private static function overall_summary( string $state, array $ai_core, array $site_profile, array $content_index, array $background ): string {
		if ( 'blocked' === $state ) {
			return sanitize_text_field( (string) ( $ai_core['summary'] ?? 'Harness is blocked.' ) );
		}

		if ( 'degraded' === $state ) {
			foreach ( array( $site_profile, $content_index, $background ) as $facet ) {
				if ( 'ready' !== ( $facet['state'] ?? 'ready' ) ) {
					return 'Harness is usable but degraded: ' . sanitize_text_field( (string) ( $facet['summary'] ?? '' ) );
				}
			}

			return 'Harness is usable but one or more dependencies are degraded.';
		}

		return 'Harness is ready for normal AI operation.';
	}

	private static function worst_state( string $left, string $right ): string {
		return self::state_weight( $left ) >= self::state_weight( $right ) ? $left : $right;
	}

	private static function state_label( string $state ): string {
		return match ( $state ) {
			'blocked'  => 'Blocked',
			'degraded' => 'Degraded',
			default    => 'Ready',
		};
	}

	private static function state_weight( string $state ): int {
		return match ( $state ) {
			'blocked'  => 3,
			'degraded' => 2,
			default    => 1,
		};
	}
}
