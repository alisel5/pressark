<?php
/**
 * PressArk WP-CLI Commands.
 *
 * Provides CLI access to status, quota, diagnostics, cache, and index management.
 *
 * @package PressArk
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_CLI extends WP_CLI_Command {

	/**
	 * Show tier, billing status, model, provider, and BYOK status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark status
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$license  = new PressArk_License();
		$tier     = $license->get_tier();
		$is_byok  = PressArk_Entitlements::is_byok();
		$provider = get_option( 'pressark_api_provider', 'openrouter' );

		$ai    = new PressArk_AI_Connector( $tier );
		$model = $ai->get_model();

		$rows = array(
			array( 'Field' => 'Tier',     'Value' => PressArk_Entitlements::tier_label( $tier ) . " ({$tier})" ),
			array( 'Field' => 'Provider', 'Value' => $provider ),
			array( 'Field' => 'Model',    'Value' => $model ),
			array( 'Field' => 'BYOK',     'Value' => $is_byok ? 'Enabled' : 'Disabled' ),
		);

		if ( ! $is_byok ) {
			$plan_info = PressArk_Entitlements::get_plan_info( $tier );
			$rows[] = array( 'Field' => 'Billing Tier', 'Value' => PressArk_Entitlements::tier_label( (string) ( $plan_info['billing_tier'] ?? $tier ) ) . ' (' . (string) ( $plan_info['billing_tier'] ?? $tier ) . ')' );
			$rows[] = array( 'Field' => 'Billing Authority', 'Value' => (string) ( $plan_info['billing_authority'] ?? 'token_bank' ) );
			$rows[] = array( 'Field' => 'Monthly Included ICU Budget', 'Value' => number_format( (int) ( $plan_info['monthly_included_icu_budget'] ?? $plan_info['monthly_icu_budget'] ?? 0 ) ) );
			$rows[] = array( 'Field' => 'Monthly Included Remaining', 'Value' => number_format( (int) ( $plan_info['monthly_included_remaining'] ?? $plan_info['monthly_remaining'] ?? 0 ) ) );
			$rows[] = array( 'Field' => 'Purchased Credits Remaining', 'Value' => number_format( (int) ( $plan_info['purchased_credits_remaining'] ?? $plan_info['credits_remaining'] ?? 0 ) ) );
			if ( ! empty( $plan_info['legacy_bonus_remaining'] ) ) {
				$rows[] = array( 'Field' => 'Legacy Bonus Remaining', 'Value' => number_format( (int) $plan_info['legacy_bonus_remaining'] ) );
			}
			$rows[] = array( 'Field' => 'Total Spendable Remaining', 'Value' => number_format( (int) ( $plan_info['total_remaining'] ?? 0 ) ) );
			$rows[] = array( 'Field' => 'Budget Pressure', 'Value' => (string) ( $plan_info['budget_pressure_state'] ?? 'normal' ) );
			$rows[] = array( 'Field' => 'Using Purchased Credits', 'Value' => ! empty( $plan_info['using_purchased_credits'] ) ? 'Yes' : 'No' );
			if ( ! empty( $plan_info['billing_contract_mismatch'] ) ) {
				$rows[] = array( 'Field' => 'Billing Contract', 'Value' => 'Mismatch (bank catalog authoritative)' );
			}
		}

		$rows[] = array( 'Field' => 'Plugin Version', 'Value' => PRESSARK_VERSION );

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Show detailed ICU, planning-token, and action quota breakdown.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark quota
	 *
	 * @subcommand quota
	 */
	public function quota( $args, $assoc_args ) {
		$license = new PressArk_License();
		$tier    = $license->get_tier();
		$is_byok = PressArk_Entitlements::is_byok();

		// -- Billing usage --
		WP_CLI::log( '── Billing Usage ──' );

		if ( $is_byok ) {
			WP_CLI::log( 'BYOK enabled — bundled ICU credits are bypassed.' );
		} else {
			$plan_info = PressArk_Entitlements::get_plan_info( $tier );
			$budget    = (int) ( $plan_info['monthly_included_icu_budget'] ?? $plan_info['monthly_icu_budget'] ?? 0 );
			$used      = (int) ( $plan_info['icus_used'] ?? 0 );
			$remain    = (int) ( $plan_info['total_remaining'] ?? 0 );
			$pct       = $budget > 0 ? round( ( $used / $budget ) * 100, 1 ) : 0;

			WP_CLI::log( sprintf( '  Billing Tier:                %s', (string) ( $plan_info['billing_tier'] ?? $tier ) ) );
			WP_CLI::log( sprintf( '  Billing Authority:           %s', (string) ( $plan_info['billing_authority'] ?? 'token_bank' ) ) );
			WP_CLI::log( sprintf( '  Monthly Included ICU Budget: %s', number_format( $budget ) ) );
			WP_CLI::log( sprintf( '  ICUs Used This Cycle:        %s (%s%%)', number_format( $used ), $pct ) );
			WP_CLI::log( sprintf( '  Monthly Included Remaining:  %s', number_format( (int) ( $plan_info['monthly_included_remaining'] ?? $plan_info['monthly_remaining'] ?? 0 ) ) ) );
			WP_CLI::log( sprintf( '  Purchased Credits Remaining: %s', number_format( (int) ( $plan_info['purchased_credits_remaining'] ?? $plan_info['credits_remaining'] ?? 0 ) ) ) );
			if ( ! empty( $plan_info['legacy_bonus_remaining'] ) ) {
				WP_CLI::log( sprintf( '  Legacy Bonus Remaining:      %s', number_format( (int) $plan_info['legacy_bonus_remaining'] ) ) );
			}
			WP_CLI::log( sprintf( '  Total Spendable Remaining:   %s', number_format( $remain ) ) );
			WP_CLI::log( sprintf( '  Budget Pressure:             %s', (string) ( $plan_info['budget_pressure_state'] ?? 'normal' ) ) );
			WP_CLI::log( sprintf( '  Using Purchased Credits:     %s', ! empty( $plan_info['using_purchased_credits'] ) ? 'Yes' : 'No' ) );
			if ( ! empty( $plan_info['billing_contract_mismatch'] ) ) {
				WP_CLI::log( '  Billing Contract:            Mismatch (bank catalog authoritative)' );
			}
		}

		// -- Local Provider Tokens --
		$token_stats = PressArk_Usage_Tracker::get_token_stats();
		if ( $token_stats['request_count'] > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::log( '── Local Provider Tokens ──' );
			WP_CLI::log( sprintf( '  Requests:   %s', number_format( $token_stats['request_count'] ) ) );
			WP_CLI::log( sprintf( '  Input:      %s tokens', number_format( $token_stats['total_input'] ) ) );
			WP_CLI::log( sprintf( '  Output:     %s tokens', number_format( $token_stats['total_output'] ) ) );
			WP_CLI::log( sprintf( '  Avg Input:  %s tokens/req', number_format( $token_stats['avg_input'] ) ) );
			WP_CLI::log( sprintf( '  Avg Output: %s tokens/req', number_format( $token_stats['avg_output'] ) ) );

			if ( ! empty( $token_stats['models'] ) ) {
				WP_CLI::log( '  Models:     ' . implode( ', ', array_keys( $token_stats['models'] ) ) );
			}
		}

		// -- Local action access --
		if ( false ) {
			WP_CLI::log( '' );
			WP_CLI::log( '── Legacy Action Telemetry ──' );

			$plan_info = PressArk_Entitlements::get_plan_info( $tier );
			if ( ! empty( $plan_info['group_usage']['per_group'] ) ) {
				$rows = array();
				foreach ( (array) $plan_info['group_usage']['per_group'] as $group => $used ) {
					$rows[] = array(
						'Group'     => $group,
						'Used'      => (int) $used,
						'Limit'     => (int) ( $plan_info['group_usage']['total_limit'] ?? 0 ),
						'Remaining' => (int) ( $plan_info['group_usage']['total_remaining'] ?? 0 ),
					);
				}
				WP_CLI\Utils\format_items( 'table', $rows, array( 'Group', 'Used', 'Limit', 'Remaining' ) );
			}
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( '── Local Action Access ──' );
			WP_CLI::log( '  Local plugin actions are not plan-gated; remote AI usage is metered by credits.' );
		}

		// -- Tier limits summary --
		WP_CLI::log( '' );
		WP_CLI::log( '── Tier Limits ──' );
		$config = PressArk_Entitlements::tier_config( $tier );
		$limits = array(
			array( 'Limit' => 'Max Agent Rounds',       'Value' => (string) $config['max_agent_rounds'] ),
			array( 'Limit' => 'Agent Prompt Budget',    'Value' => number_format( $config['agent_token_budget'] ) . ' planning tokens' ),
			array( 'Limit' => 'Workflow Prompt Budget', 'Value' => number_format( $config['workflow_token_budget'] ) . ' planning tokens' ),
			array( 'Limit' => 'Burst/min',              'Value' => (string) $config['burst_per_min'] ),
			array( 'Limit' => 'Hourly Limit',           'Value' => (string) $config['hourly_limit'] ),
			array( 'Limit' => 'Concurrency',            'Value' => (string) $config['concurrency'] ),
			array( 'Limit' => 'Max Sites',              'Value' => $config['max_sites'] === -1 ? 'Unlimited' : (string) $config['max_sites'] ),
			array( 'Limit' => 'Max Automations',        'Value' => $config['max_automations'] === -1 ? 'Unlimited' : (string) $config['max_automations'] ),
			array( 'Limit' => 'Deep Mode',              'Value' => $config['deep_mode'] ? 'Yes' : 'No' ),
		);
		WP_CLI\Utils\format_items( 'table', $limits, array( 'Limit', 'Value' ) );
	}

	/**
	 * Run a test AI call and report latency, tokens, and cost.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark test
	 *
	 * @subcommand test
	 */
	public function test( $args, $assoc_args ) {
		$license = new PressArk_License();
		$tier    = $license->get_tier();
		$ai      = new PressArk_AI_Connector( $tier );
		$model   = $ai->get_model();

		WP_CLI::log( "Testing AI connectivity..." );
		WP_CLI::log( "  Provider: " . get_option( 'pressark_api_provider', 'openrouter' ) );
		WP_CLI::log( "  Model:    {$model}" );
		WP_CLI::log( '' );

		$start  = microtime( true );
		$result = $ai->send_lightweight_chat( 'Reply with exactly: PressArk CLI test OK' );
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		if ( ! empty( $result['error'] ) ) {
			WP_CLI::error( "AI call failed: {$result['error']}" );
			return;
		}

		WP_CLI::success( "Response received in {$elapsed}ms" );
		WP_CLI::log( "  Reply:  " . trim( $result['message'] ?? '(empty)' ) );

		// Show token stats from this request if tracked.
		$token_stats = PressArk_Usage_Tracker::get_token_stats();
		if ( $token_stats['request_count'] > 0 ) {
			WP_CLI::log( sprintf( '  Tokens this month: %s in / %s out (%s requests)',
				number_format( $token_stats['total_input'] ),
				number_format( $token_stats['total_output'] ),
				number_format( $token_stats['request_count'] )
			) );
		}
	}

	/**
	 * Clear all PressArk transients and cached data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark cache:clear
	 *
	 * @subcommand cache:clear
	 */
	public function cache_clear( $args, $assoc_args ) {
		$cleared = 0;

		// Global transients.
		$global_transients = array(
			'pressark_site_context',
			'pressark_plugin_list',
			'pressark_speed_check',
		);

		foreach ( $global_transients as $key ) {
			if ( delete_transient( $key ) ) {
				++$cleared;
			}
		}

		// Theme-specific customizer schema.
		$stylesheet = get_stylesheet();
		if ( delete_transient( 'pressark_customizer_schema_' . $stylesheet ) ) {
			++$cleared;
		}

		// User-specific transients.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $users as $user_id ) {
			$user_transients = array(
				'pressark_license_cache_' . $user_id,
				'pressark_token_status_' . $user_id,
				'pressark_concurrent_' . $user_id,
			);
			foreach ( $user_transients as $key ) {
				if ( delete_transient( $key ) ) {
					++$cleared;
				}
			}
		}

		WP_CLI::success( "Cleared {$cleared} transient(s)." );
	}

	/**
	 * Rebuild the content index.
	 *
	 * By default schedules an async rebuild via WP-Cron. Use --sync to
	 * run the full rebuild synchronously (blocks until complete).
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Run the rebuild synchronously instead of via WP-Cron.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark index:rebuild
	 *     wp pressark index:rebuild --sync
	 *
	 * @subcommand index:rebuild
	 */
	public function index_rebuild( $args, $assoc_args ) {
		$index = new PressArk_Content_Index();

		if ( ! $index->is_indexing_enabled() ) {
			WP_CLI::error( 'Content indexing is disabled. Enable it in PressArk settings first.' );
			return;
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'sync', false ) ) {
			WP_CLI::log( 'Rebuilding content index synchronously...' );

			// Reset watermark so sync_changed walks all posts.
			$index->schedule_full_rebuild();
			wp_clear_scheduled_hook( 'pressark_index_batch' );

			$total_indexed = 0;
			$total_skipped = 0;
			$batch         = 0;

			do {
				++$batch;
				$result = $index->sync_changed( 100 );
				$total_indexed += $result['indexed'];
				$total_skipped += $result['skipped'];

				if ( $batch % 5 === 0 ) {
					WP_CLI::log( sprintf( '  Batch %d: %d indexed, %d skipped so far...',
						$batch, $total_indexed, $total_skipped ) );
				}
			} while ( $result['has_more'] );

			$stats = $index->get_stats();
			WP_CLI::success( sprintf(
				'Rebuild complete. %d posts indexed, %d skipped. %d chunks in index.',
				$total_indexed,
				$total_skipped,
				$stats['total_chunks'] ?? 0
			) );
		} else {
			$index->schedule_full_rebuild();
			WP_CLI::success( 'Full index rebuild scheduled via WP-Cron.' );
		}
	}

	/**
	 * Run the full diagnostics suite.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Run a specific diagnostic instead of the full suite.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - speed
	 *   - crawlability
	 *   - email
	 *   - cache
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressark diagnose
	 *     wp pressark diagnose --type=speed
	 *     wp pressark diagnose --format=json
	 *
	 * @subcommand diagnose
	 */
	public function diagnose( $args, $assoc_args ) {
		$diagnostics = new PressArk_Diagnostics();
		$type        = $assoc_args['type'] ?? 'all';
		$format      = $assoc_args['format'] ?? 'yaml';

		WP_CLI::log( 'Running diagnostics...' );
		WP_CLI::log( '' );

		switch ( $type ) {
			case 'speed':
				$result = $diagnostics->measure_page_speed();
				break;
			case 'crawlability':
				$result = $diagnostics->check_crawlability();
				break;
			case 'email':
				$result = $diagnostics->check_email_delivery();
				break;
			case 'cache':
				$result = $diagnostics->diagnose_cache();
				break;
			default:
				$result = $diagnostics->site_brief();
				break;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			$this->print_yaml( $result );
		}
	}

	/**
	 * Recursively print an array as indented YAML-like output.
	 */
	private function print_yaml( $data, int $indent = 0 ): void {
		$pad = str_repeat( '  ', $indent );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				WP_CLI::log( "{$pad}{$key}:" );
				$this->print_yaml( $value, $indent + 1 );
			} else {
				$display = $this->format_scalar( $value );
				WP_CLI::log( "{$pad}{$key}: {$display}" );
			}
		}
	}

	/**
	 * Format a scalar value for CLI display.
	 */
	private function format_scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return 'null';
		}
		return (string) $value;
	}
}
