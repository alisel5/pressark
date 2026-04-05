<?php
/**
 * PressArk Token Budget Manager
 *
 * Central, filterable context-budget accounting for adaptive hydration.
 *
 * Tracks the same major buckets that matter in PressArk's request loop:
 * stable prompt prefix, dynamic round prompt, loaded tool schemas, live
 * conversation/history, inline tool-result payloads, deferred candidates,
 * and reserved headroom for the next assistant turn plus follow-up tools.
 *
 * The values here are intentionally conservative planning estimates, not
 * provider-billed token counts. Financial fields are advisory projections
 * derived from bank-authoritative balance snapshots and ICU multipliers.
 *
 * @package PressArk
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Token_Budget_Manager {

	/**
	 * Default planning knobs.
	 *
	 * All values are filterable via `pressark_token_budget_config`.
	 */
	private const DEFAULT_CONFIG = array(
		'chars_per_token'            => 4.0,
		'normal_history_ratio'       => 0.12,
		'deep_history_ratio'         => 0.18,
		'normal_history_min'         => 1200,
		'deep_history_min'           => 2400,
		'normal_history_max'         => 4500,
		'deep_history_max'           => 9000,
		'normal_max_messages'        => 20,
		'deep_max_messages'          => 40,
		'response_reserve_ratio'     => 0.12,
		'response_reserve_min'       => 1024,
		'follow_up_reserve_ratio'    => 0.06,
		'follow_up_reserve_min'      => 512,
		'follow_up_reserve_max'      => 4096,
		'safety_reserve_ratio'       => 0.04,
		'safety_reserve_min'         => 512,
		'safety_reserve_max'         => 2048,
		'support_text_buffer_tokens' => 192,
		'group_fit_buffer_tokens'    => 256,
		'financial_conserve_ratio'   => 0.20,
		'financial_critical_ratio'   => 0.08,
		'monthly_conserve_ratio'     => 0.10,
		'monthly_critical_ratio'     => 0.02,
		'request_conserve_ratio'     => 0.25,
		'request_critical_ratio'     => 0.60,
		'hydration_profiles'         => array(
			'normal' => array(
				'group_cost_multiplier' => 1.0,
				'extra_group_buffer'    => 0,
				'support_preference'    => array( 'full', 'compact', 'minimal' ),
			),
			'conserve' => array(
				'group_cost_multiplier' => 1.15,
				'extra_group_buffer'    => 256,
				'support_preference'    => array( 'compact', 'minimal', 'full' ),
			),
			'critical' => array(
				'group_cost_multiplier' => 1.35,
				'extra_group_buffer'    => 512,
				'support_preference'    => array( 'minimal', 'compact', 'full' ),
			),
		),
	);

	private string $provider;
	private string $model;
	private string $stable_prefix;
	private int $context_window;
	private int $max_output_tokens;
	private array $config;
	private array $financial_snapshot;
	private array $multiplier_config;

	/**
	 * @param array $args {
	 *     @type string $provider            Provider key.
	 *     @type string $model               Resolved model identifier.
	 *     @type string $stable_prefix       Stable cached system prompt text.
	 *     @type int    $context_window      Optional override.
	 *     @type int    $max_output_tokens   Optional override.
	 *     @type array  $financial_snapshot  Authoritative bank snapshot for advisory pressure.
	 *     @type array  $multiplier_config   Token Bank multiplier config for ICU estimates.
	 * }
	 */
	public function __construct( array $args = array() ) {
		$this->provider          = sanitize_key( (string) ( $args['provider'] ?? 'openrouter' ) );
		$this->model             = sanitize_text_field( (string) ( $args['model'] ?? '' ) );
		$this->stable_prefix     = is_string( $args['stable_prefix'] ?? null ) ? (string) $args['stable_prefix'] : '';
		$this->context_window    = max(
			8192,
			(int) ( $args['context_window'] ?? PressArk_Model_Policy::context_window( $this->model, $this->provider ) )
		);
		$this->max_output_tokens = max(
			256,
			(int) ( $args['max_output_tokens'] ?? PressArk_Model_Policy::max_output_tokens( $this->provider ) )
		);
		$this->config            = wp_parse_args(
			(array) apply_filters(
				'pressark_token_budget_config',
				array(),
				$this->provider,
				$this->model
			),
			self::DEFAULT_CONFIG
		);
		$this->financial_snapshot = $this->normalize_financial_snapshot( $args['financial_snapshot'] ?? array() );
		$this->multiplier_config  = $this->normalize_multiplier_config( $args['multiplier_config'] ?? array() );
	}

	/**
	 * Estimate tokens for an arbitrary value using the configured heuristic.
	 *
	 * @param mixed $value Arbitrary value or already-serialized string.
	 * @return int
	 */
	public function estimate_value_tokens( $value ): int {
		if ( is_string( $value ) ) {
			$serialized = $value;
		} else {
			$serialized = wp_json_encode( $value );
			if ( ! is_string( $serialized ) ) {
				$serialized = '';
			}
		}

		if ( '' === $serialized ) {
			return 0;
		}

		$chars_per_token = max( 2.0, (float) ( $this->config['chars_per_token'] ?? 4.0 ) );
		return (int) ceil( mb_strlen( $serialized ) / $chars_per_token );
	}

	/**
	 * Estimate tokens for serialized tool schemas.
	 *
	 * @param array $schemas Tool schema arrays.
	 * @return int
	 */
	public function estimate_schema_tokens( array $schemas ): int {
		return $this->estimate_value_tokens( $schemas );
	}

	/**
	 * Estimate a request's billable ICU projection from planning tokens.
	 *
	 * These are advisory projections only. The bank remains authoritative for
	 * real settlement.
	 *
	 * @param int $prompt_tokens Planning estimate for request input tokens.
	 * @param int $output_tokens Planning estimate for response output tokens.
	 * @return array
	 */
	public function estimate_request_icus( int $prompt_tokens, int $output_tokens = 0 ): array {
		$model_to_class = (array) ( $this->multiplier_config['model_to_class'] ?? array() );
		$classes        = (array) ( $this->multiplier_config['classes'] ?? array() );
		$default_class  = sanitize_key( (string) ( $this->multiplier_config['default_class'] ?? 'standard' ) );
		$model_class    = sanitize_key( (string) ( $model_to_class[ $this->model ] ?? $default_class ) );
		$multiplier     = $classes[ $model_class ] ?? $classes[ $default_class ] ?? array(
			'input'  => 10,
			'output' => 30,
		);

		$input_multiplier  = max( 1, (int) ( $multiplier['input'] ?? 10 ) );
		$output_multiplier = max( 1, (int) ( $multiplier['output'] ?? 30 ) );
		$prompt_tokens     = max( 0, $prompt_tokens );
		$output_tokens     = max( 0, $output_tokens );

		$breakdown = array(
			'input_icus'       => (int) ceil( $prompt_tokens * $input_multiplier ),
			'output_icus'      => (int) ceil( $output_tokens * $output_multiplier ),
			'cache_read_icus'  => 0,
			'cache_write_icus' => 0,
		);

		return array(
			'icu_total'   => array_sum( $breakdown ),
			'model_class' => $model_class ?: $default_class,
			'multiplier'  => array(
				'input'  => $input_multiplier,
				'output' => $output_multiplier,
			),
			'breakdown'   => $breakdown,
			'raw_tokens'  => array(
				'input'       => $prompt_tokens,
				'output'      => $output_tokens,
				'cache_read'  => 0,
				'cache_write' => 0,
				'total'       => $prompt_tokens + $output_tokens,
			),
		);
	}

	/**
	 * Get a dynamic history budget recommendation for the current model.
	 *
	 * Returns both a token budget and a soft max-message count so callers can
	 * keep history proportional to the available planning window.
	 *
	 * @param bool $deep_mode Whether deep mode is active.
	 * @return array{token_budget:int,max_messages:int}
	 */
	public function recommended_history_config( bool $deep_mode = false ): array {
		$reserves          = $this->build_reserves();
		$available_context = max(
			0,
			$this->context_window
			- $reserves['total']
			- $this->estimate_value_tokens( $this->stable_prefix )
		);

		$ratio      = (float) ( $deep_mode ? $this->config['deep_history_ratio'] : $this->config['normal_history_ratio'] );
		$min_budget = (int) ( $deep_mode ? $this->config['deep_history_min'] : $this->config['normal_history_min'] );
		$max_budget = (int) ( $deep_mode ? $this->config['deep_history_max'] : $this->config['normal_history_max'] );
		$budget     = (int) floor( $available_context * max( 0.05, $ratio ) );
		$budget     = max( $min_budget, min( $budget, $max_budget ) );
		$budget     = min( $budget, max( $min_budget, (int) floor( $available_context * 0.5 ) ) );

		$max_messages = (int) ( $deep_mode ? $this->config['deep_max_messages'] : $this->config['normal_max_messages'] );
		$avg_message  = $deep_mode ? 260 : 180;
		$derived_max  = max( 8, (int) floor( max( 1, $budget ) / $avg_message ) );

		return array(
			'token_budget' => max( 800, $budget ),
			'max_messages' => max( 8, min( $max_messages, $derived_max ) ),
		);
	}

	/**
	 * Build a ledger for the current request plan.
	 *
	 * @param array $args {
	 *     @type string $stable_prefix
	 *     @type string $dynamic_prompt
	 *     @type string $dynamic_prompt_stable Stable run-local prompt prefix.
	 *     @type string $dynamic_prompt_volatile Volatile run-local prompt suffix.
	 *     @type array  $loaded_tool_schemas
	 *     @type array  $conversation
	 *     @type array  $tool_results
	 *     @type array  $deferred_candidates Array of { group|name, tokens }.
	 *     @type int    $estimated_output_tokens Optional output planning override.
	 *     @type int    $raw_actual_tokens Optional actual raw-token count for response assembly.
	 *     @type int    $actual_icus Optional actual ICU count for response assembly.
	 * }
	 * @return array
	 */
	public function build_request_ledger( array $args = array() ): array {
		$stable_prefix           = is_string( $args['stable_prefix'] ?? null ) ? (string) $args['stable_prefix'] : $this->stable_prefix;
		$dynamic_prompt          = is_string( $args['dynamic_prompt'] ?? null ) ? (string) $args['dynamic_prompt'] : '';
		$dynamic_prompt_stable   = is_string( $args['dynamic_prompt_stable'] ?? null ) ? trim( (string) $args['dynamic_prompt_stable'] ) : '';
		$dynamic_prompt_volatile = is_string( $args['dynamic_prompt_volatile'] ?? null ) ? trim( (string) $args['dynamic_prompt_volatile'] ) : '';
		if ( '' === $dynamic_prompt_stable && '' === $dynamic_prompt_volatile && '' !== trim( $dynamic_prompt ) ) {
			$dynamic_prompt_volatile = trim( $dynamic_prompt );
		}
		$dynamic_prompt_combined = '' !== $dynamic_prompt
			? $dynamic_prompt
			: trim( implode( "\n\n", array_values( array_filter( array( $dynamic_prompt_stable, $dynamic_prompt_volatile ) ) ) ) );
		$dynamic_prompt_stable_tokens   = $this->estimate_value_tokens( $dynamic_prompt_stable );
		$dynamic_prompt_volatile_tokens = $this->estimate_value_tokens( $dynamic_prompt_volatile );
		$tool_schemas            = is_array( $args['loaded_tool_schemas'] ?? null ) ? $args['loaded_tool_schemas'] : array();
		$conversation            = is_array( $args['conversation'] ?? null ) ? $args['conversation'] : array();
		$tool_results            = is_array( $args['tool_results'] ?? null ) ? $args['tool_results'] : array();
		$deferred                = $this->normalize_deferred_candidates( $args['deferred_candidates'] ?? array() );
		$reserves                = $this->build_reserves();
		$segments        = array(
			'stable_prompt_prefix' => array(
				'label'  => 'Stable prompt prefix',
				'tokens' => $this->estimate_value_tokens( $stable_prefix ),
			),
			'dynamic_prompt' => array(
				'label'  => 'Dynamic round prompt',
				'tokens' => $this->estimate_value_tokens( $dynamic_prompt_combined ),
			),
			'loaded_tool_schemas' => array(
				'label'  => 'Loaded tool schemas',
				'tokens' => $this->estimate_schema_tokens( $tool_schemas ),
			),
			'live_conversation' => array(
				'label'  => 'Live conversation/history',
				'tokens' => $this->estimate_value_tokens( $conversation ),
			),
			'tool_result_payloads' => array(
				'label'  => 'Tool-result payloads',
				'tokens' => $this->estimate_value_tokens( $tool_results ),
			),
		);
		$used_tokens     = array_sum( wp_list_pluck( $segments, 'tokens' ) );
		$input_budget    = max( 0, $this->context_window - $reserves['total'] );
		$remaining_tokens = max( 0, $input_budget - $used_tokens );
		$usage_ratio     = $input_budget > 0 ? ( $used_tokens / $input_budget ) : 1.0;
		$context_pressure = $this->pressure_for_ratio( $usage_ratio );
		$estimated_output_tokens = max(
			0,
			(int) ( $args['estimated_output_tokens'] ?? $reserves['assistant_response'] )
		);
		$request_icus    = $this->estimate_request_icus( $used_tokens, $estimated_output_tokens );
		$financial_pressure = $this->resolve_financial_pressure_state( (int) ( $request_icus['icu_total'] ?? 0 ) );
		$hydration_profile  = $this->select_hydration_profile( $context_pressure, $financial_pressure );
		$credit_budget      = (int) ( $this->financial_snapshot['total_remaining'] ?? 0 );
		$fits_credit_budget = ! empty( $this->financial_snapshot['is_byok'] )
			|| PHP_INT_MAX === $credit_budget
			|| (int) ( $request_icus['icu_total'] ?? 0 ) <= $credit_budget;
		$remaining_credit_after_request = PHP_INT_MAX === $credit_budget
			? PHP_INT_MAX
			: max( 0, $credit_budget - (int) ( $request_icus['icu_total'] ?? 0 ) );
		$legacy_bonus_remaining = (int) ( $this->financial_snapshot['legacy_bonus_remaining'] ?? 0 );
		$deferred_tokens        = array_sum( wp_list_pluck( $deferred, 'tokens' ) );

		return array(
			'provider'                    => $this->provider,
			'model'                       => $this->model,
			'context_window'              => $this->context_window,
			'max_output_tokens'           => $this->max_output_tokens,
			'input_budget'                => $input_budget,
			'segments'                    => $segments,
			'prompt_sections'            => array_filter( array(
				'stable' => $dynamic_prompt_stable_tokens > 0 ? array(
					'label'  => 'Stable run prefix',
					'tokens' => $dynamic_prompt_stable_tokens,
				) : array(),
				'volatile' => $dynamic_prompt_volatile_tokens > 0 ? array(
					'label'  => 'Volatile run state',
					'tokens' => $dynamic_prompt_volatile_tokens,
				) : array(),
			) ),
			'used_tokens'                 => $used_tokens,
			'estimated_prompt_tokens'     => $used_tokens,
			'estimated_output_tokens'     => $estimated_output_tokens,
			'estimated_request_icus'      => (int) ( $request_icus['icu_total'] ?? 0 ),
			'estimated_request_icu_breakdown' => (array) ( $request_icus['breakdown'] ?? array() ),
			'estimated_request_model_class' => (string) ( $request_icus['model_class'] ?? '' ),
			'remaining_tokens'            => $remaining_tokens,
			'usage_ratio'                 => $usage_ratio,
			'pressure'                    => $context_pressure,
			'context_pressure'            => $context_pressure,
			'financial_pressure'          => $financial_pressure,
			'budget_pressure_state'       => $financial_pressure,
			'hydration_profile'           => $hydration_profile,
			'fits_context_window'         => $used_tokens <= $input_budget,
			'fits_credit_budget'          => $fits_credit_budget,
			'remaining_credit_after_request' => $remaining_credit_after_request,
			'deferred_candidates'         => $deferred,
			'deferred_tokens'             => $deferred_tokens,
			'reserved'                    => $reserves,
			'billing_authority'           => (string) ( $this->financial_snapshot['billing_authority'] ?? '' ),
			'billing_state'               => (array) ( $this->financial_snapshot['billing_state'] ?? array() ),
			'billing_service_state'       => (string) ( $this->financial_snapshot['billing_service_state'] ?? '' ),
			'billing_handshake_state'     => (string) ( $this->financial_snapshot['billing_handshake_state'] ?? '' ),
			'billing_spend_source'        => (string) ( $this->financial_snapshot['billing_spend_source'] ?? '' ),
			'billing_tier'                => (string) ( $this->financial_snapshot['billing_tier'] ?? '' ),
			'verified_handshake'          => ! empty( $this->financial_snapshot['verified_handshake'] ),
			'provisional_handshake'       => ! empty( $this->financial_snapshot['provisional_handshake'] ),
			'is_byok'                     => ! empty( $this->financial_snapshot['is_byok'] ),
			'offline'                     => ! empty( $this->financial_snapshot['offline'] ),
			'monthly_icu_budget'          => (int) ( $this->financial_snapshot['monthly_icu_budget'] ?? 0 ),
			'monthly_included_icu_budget' => (int) ( $this->financial_snapshot['monthly_included_icu_budget'] ?? 0 ),
			'monthly_remaining'           => (int) ( $this->financial_snapshot['monthly_remaining'] ?? 0 ),
			'monthly_included_remaining'  => (int) ( $this->financial_snapshot['monthly_included_remaining'] ?? 0 ),
			'credits_remaining'           => (int) ( $this->financial_snapshot['purchased_credits_remaining'] ?? 0 ),
			'purchased_credits_remaining' => (int) ( $this->financial_snapshot['purchased_credits_remaining'] ?? 0 ),
			'legacy_bonus_remaining'      => $legacy_bonus_remaining,
			'total_remaining'             => $credit_budget,
			'total_available'             => (int) ( $this->financial_snapshot['total_available'] ?? 0 ),
			'spendable_credits_remaining' => (int) ( $this->financial_snapshot['spendable_credits_remaining'] ?? $credit_budget ),
			'spendable_icus_remaining'    => (int) ( $this->financial_snapshot['spendable_icus_remaining'] ?? $credit_budget ),
			'using_purchased_credits'     => ! empty( $this->financial_snapshot['using_purchased_credits'] ),
			'using_legacy_bonus'          => ! empty( $this->financial_snapshot['using_legacy_bonus'] ),
			'raw_actual_tokens'           => max( 0, (int) ( $args['raw_actual_tokens'] ?? 0 ) ),
			'actual_icus'                 => max( 0, (int) ( $args['actual_icus'] ?? 0 ) ),
			'financial'                   => $this->financial_snapshot,
		);
	}

	/**
	 * Decide which candidate groups can be hydrated now.
	 *
	 * Candidate ordering matters: callers should pass the highest-value groups
	 * first. Required/sticky groups are never dropped here.
	 *
	 * @param string[] $required_groups Already-required groups (for reporting).
	 * @param string[] $candidate_groups Ordered candidate groups to try hydrating.
	 * @param array    $group_costs Map of group => estimated incremental schema tokens.
	 * @param array    $ledger Current request ledger.
	 * @return array
	 */
	public function plan_group_hydration(
		array $required_groups,
		array $candidate_groups,
		array $group_costs,
		array $ledger = array()
	): array {
		$remaining = max( 0, (int) ( $ledger['remaining_tokens'] ?? 0 ) );
		$profile   = $this->get_hydration_profile_config(
			(string) ( $ledger['hydration_profile'] ?? $this->select_hydration_profile(
				(string) ( $ledger['context_pressure'] ?? 'low' ),
				(string) ( $ledger['financial_pressure'] ?? 'normal' )
			) )
		);
		$buffer    = (int) ( $this->config['group_fit_buffer_tokens'] ?? 256 ) + (int) ( $profile['extra_group_buffer'] ?? 0 );
		$multiplier = max( 1.0, (float) ( $profile['group_cost_multiplier'] ?? 1.0 ) );
		$selected  = array();
		$deferred  = array();

		foreach ( array_values( array_unique( array_map( 'sanitize_key', $candidate_groups ) ) ) as $group ) {
			$base_cost     = max( 0, (int) ( $group_costs[ $group ] ?? 0 ) );
			$adjusted_cost = (int) ceil( $base_cost * $multiplier );

			if ( 0 === $base_cost || $adjusted_cost + $buffer <= $remaining ) {
				$selected[] = $group;
				$remaining  = max( 0, $remaining - $adjusted_cost );
				continue;
			}

			$deferred[] = array(
				'group'          => $group,
				'tokens'         => $base_cost,
				'adjusted_tokens'=> $adjusted_cost,
				'type'           => 'group',
			);
		}

		return array(
			'required_groups'  => array_values( array_unique( array_map( 'sanitize_key', $required_groups ) ) ),
			'selected_groups'  => $selected,
			'deferred_groups'  => $deferred,
			'remaining_tokens' => $remaining,
			'hydration_profile' => (string) ( $ledger['hydration_profile'] ?? 'normal' ),
			'context_pressure' => (string) ( $ledger['context_pressure'] ?? 'low' ),
			'financial_pressure' => (string) ( $ledger['financial_pressure'] ?? 'normal' ),
		);
	}

	/**
	 * Choose the richest support text variant that still fits comfortably.
	 *
	 * @param array    $variants          Map of variant => string payload.
	 * @param array    $ledger            Current request ledger.
	 * @param string[] $preference_order  Preferred variant order.
	 * @return string Selected variant key.
	 */
	public function choose_support_variant(
		array $variants,
		array $ledger = array(),
		array $preference_order = array( 'full', 'compact', 'minimal' )
	): string {
		$available = max( 0, (int) ( $ledger['remaining_tokens'] ?? 0 ) );
		$buffer    = (int) ( $this->config['support_text_buffer_tokens'] ?? 192 );
		$existing  = array();

		foreach ( $variants as $name => $payload ) {
			if ( ! is_string( $payload ) || '' === trim( $payload ) ) {
				continue;
			}
			$existing[ $name ] = $this->estimate_value_tokens( $payload );
		}

		if ( empty( $existing ) ) {
			return '';
		}

		$profile_key = (string) ( $ledger['hydration_profile'] ?? $this->select_hydration_profile(
			(string) ( $ledger['context_pressure'] ?? 'low' ),
			(string) ( $ledger['financial_pressure'] ?? 'normal' )
		) );
		$order = $this->merge_support_preference_order( $profile_key, $preference_order );

		foreach ( $order as $variant ) {
			if ( isset( $existing[ $variant ] ) && $existing[ $variant ] + $buffer <= $available ) {
				return $variant;
			}
		}

		asort( $existing );
		return (string) array_key_first( $existing );
	}

	/**
	 * Get the provider/model planning context window.
	 */
	public function get_context_window(): int {
		return $this->context_window;
	}

	/**
	 * Get the provider/model output reserve cap.
	 */
	public function get_max_output_tokens(): int {
		return $this->max_output_tokens;
	}

	/**
	 * Build reserve buckets that stay out of the input budget.
	 *
	 * @return array{assistant_response:int,follow_up_tools:int,safety_margin:int,total:int}
	 */
	private function build_reserves(): array {
		$response = min(
			$this->max_output_tokens,
			max(
				(int) ( $this->config['response_reserve_min'] ?? 1024 ),
				(int) floor( $this->context_window * (float) ( $this->config['response_reserve_ratio'] ?? 0.12 ) )
			)
		);

		$follow_up = min(
			(int) ( $this->config['follow_up_reserve_max'] ?? 4096 ),
			max(
				(int) ( $this->config['follow_up_reserve_min'] ?? 512 ),
				(int) floor( $this->context_window * (float) ( $this->config['follow_up_reserve_ratio'] ?? 0.06 ) )
			)
		);

		$safety = min(
			(int) ( $this->config['safety_reserve_max'] ?? 2048 ),
			max(
				(int) ( $this->config['safety_reserve_min'] ?? 512 ),
				(int) floor( $this->context_window * (float) ( $this->config['safety_reserve_ratio'] ?? 0.04 ) )
			)
		);

		return array(
			'assistant_response' => $response,
			'follow_up_tools'    => $follow_up,
			'safety_margin'      => $safety,
			'total'              => $response + $follow_up + $safety,
		);
	}

	/**
	 * Normalize deferred candidates into a predictable shape.
	 *
	 * @param mixed $candidates Deferred candidate list.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_deferred_candidates( $candidates ): array {
		if ( ! is_array( $candidates ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$name = sanitize_text_field(
				(string) ( $candidate['group'] ?? $candidate['name'] ?? $candidate['uri'] ?? '' )
			);
			if ( '' === $name ) {
				continue;
			}

			$normalized[] = array(
				'name'   => $name,
				'tokens' => max( 0, (int) ( $candidate['tokens'] ?? $candidate['adjusted_tokens'] ?? 0 ) ),
				'type'   => sanitize_key( (string) ( $candidate['type'] ?? 'group' ) ),
			);
		}

		return $normalized;
	}

	/**
	 * Translate a utilization ratio into a readable pressure level.
	 */
	private function pressure_for_ratio( float $ratio ): string {
		if ( $ratio >= 0.92 ) {
			return 'critical';
		}
		if ( $ratio >= 0.78 ) {
			return 'high';
		}
		if ( $ratio >= 0.58 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Normalize a bank-authoritative financial snapshot into one contract.
	 *
	 * @param mixed $snapshot Snapshot-like data.
	 * @return array
	 */
	private function normalize_financial_snapshot( $snapshot ): array {
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$is_byok              = ! empty( $snapshot['is_byok'] );
		$monthly_budget       = max( 0, (int) ( $snapshot['monthly_icu_budget'] ?? $snapshot['monthly_included_icu_budget'] ?? $snapshot['icu_budget'] ?? 0 ) );
		$monthly_remaining    = $is_byok
			? PHP_INT_MAX
			: max( 0, (int) ( $snapshot['monthly_remaining'] ?? $snapshot['monthly_included_remaining'] ?? $monthly_budget ) );
		$purchased_remaining  = max( 0, (int) ( $snapshot['purchased_credits_remaining'] ?? $snapshot['credits_remaining'] ?? 0 ) );
		$legacy_bonus         = max( 0, (int) ( $snapshot['legacy_bonus_remaining'] ?? 0 ) );
		$total_available      = $is_byok
			? PHP_INT_MAX
			: max( 0, (int) ( $snapshot['total_available'] ?? ( $monthly_budget + $purchased_remaining + $legacy_bonus ) ) );
		$total_remaining      = $is_byok
			? PHP_INT_MAX
			: max( 0, (int) ( $snapshot['total_remaining'] ?? $snapshot['spendable_credits_remaining'] ?? ( $monthly_remaining + $purchased_remaining + $legacy_bonus ) ) );
		$billing_authority    = sanitize_key( (string) ( $snapshot['billing_authority'] ?? ( $is_byok ? 'byok' : 'token_bank_provisional' ) ) );
		$billing_state        = is_array( $snapshot['billing_state'] ?? null ) ? (array) $snapshot['billing_state'] : array();
		$billing_tier         = sanitize_key( (string) ( $snapshot['billing_tier'] ?? $snapshot['tier'] ?? '' ) );
		$budget_pressure      = sanitize_key( (string) ( $snapshot['budget_pressure_state'] ?? '' ) );
		$using_purchased      = ! empty( $snapshot['using_purchased_credits'] ) || ( ! $is_byok && $monthly_remaining <= 0 && $purchased_remaining > 0 );
		$using_legacy_bonus   = ! empty( $snapshot['using_legacy_bonus'] ) || ( ! $is_byok && $monthly_remaining <= 0 && 0 === $purchased_remaining && $legacy_bonus > 0 );
		$verified_handshake   = ! empty( $snapshot['verified_handshake'] );
		$provisional_handshake = array_key_exists( 'provisional_handshake', $snapshot )
			? ! empty( $snapshot['provisional_handshake'] )
			: ( $verified_handshake ? false : ! $is_byok );
		$billing_service_state = sanitize_key( (string) ( $snapshot['billing_service_state'] ?? $billing_state['service_state'] ?? ( ! empty( $snapshot['offline'] ) ? 'offline_assisted' : 'normal' ) ) );
		$billing_handshake_state = sanitize_key( (string) ( $snapshot['billing_handshake_state'] ?? $billing_state['handshake_state'] ?? ( $is_byok ? 'byok' : ( $verified_handshake ? 'verified' : 'provisional' ) ) ) );
		$billing_spend_source = sanitize_key( (string) ( $snapshot['billing_spend_source'] ?? $billing_state['spend_source'] ?? ( $is_byok ? 'byok' : ( $using_purchased ? 'purchased_credits' : ( $using_legacy_bonus ? 'legacy_bonus' : ( $total_remaining > 0 ? 'monthly_included' : 'depleted' ) ) ) ) ) );

		if ( ! in_array( $budget_pressure, array( 'normal', 'conserve', 'critical' ), true ) ) {
			$budget_pressure = $this->calculate_financial_pressure_state(
				$monthly_budget,
				$monthly_remaining,
				$total_available,
				$total_remaining
			);
		}

		if ( empty( $billing_state ) ) {
			$billing_state = array(
				'version'          => 1,
				'authority_mode'   => $is_byok ? 'byok' : ( $verified_handshake ? 'bank_verified' : 'bank_provisional' ),
				'handshake_state'  => $billing_handshake_state,
				'service_state'    => $billing_service_state,
				'spend_source'     => $billing_spend_source,
				'estimate_mode'    => $is_byok ? 'provider_usage' : 'plugin_local_advisory',
				'authority_label'  => $is_byok ? 'BYOK' : ( $verified_handshake ? 'Bank verified' : 'Bank provisional' ),
				'service_label'    => 'offline_assisted' === $billing_service_state ? 'Offline assisted' : ( 'degraded' === $billing_service_state ? 'Degraded' : 'Normal' ),
				'spend_label'      => 'purchased_credits' === $billing_spend_source ? 'Purchased credits' : ( 'legacy_bonus' === $billing_spend_source ? 'Legacy bonus' : ( 'mixed' === $billing_spend_source ? 'Mixed sources' : ( 'depleted' === $billing_spend_source ? 'Depleted' : ( 'byok' === $billing_spend_source ? 'BYOK' : 'Monthly included' ) ) ) ),
			);
		}

		return array(
			'billing_authority'          => $billing_authority,
			'billing_state'              => $billing_state,
			'billing_service_state'      => $billing_service_state,
			'billing_handshake_state'    => $billing_handshake_state,
			'billing_spend_source'       => $billing_spend_source,
			'billing_tier'               => $billing_tier,
			'verified_handshake'         => $verified_handshake,
			'provisional_handshake'      => $provisional_handshake,
			'monthly_icu_budget'         => $monthly_budget,
			'monthly_included_icu_budget' => $monthly_budget,
			'monthly_remaining'          => $monthly_remaining,
			'monthly_included_remaining' => $monthly_remaining,
			'credits_remaining'          => $purchased_remaining,
			'purchased_credits_remaining' => $purchased_remaining,
			'legacy_bonus_remaining'     => $legacy_bonus,
			'total_available'            => $total_available,
			'total_remaining'            => $total_remaining,
			'spendable_credits_remaining' => $total_remaining,
			'spendable_icus_remaining'   => $total_remaining,
			'using_purchased_credits'    => $using_purchased,
			'using_legacy_bonus'         => $using_legacy_bonus,
			'budget_pressure_state'      => $budget_pressure,
			'is_byok'                    => $is_byok,
			'offline'                    => ! empty( $snapshot['offline'] ),
		);
	}

	/**
	 * Normalize multiplier config from the bank.
	 *
	 * @param mixed $config Config-like data.
	 * @return array
	 */
	private function normalize_multiplier_config( $config ): array {
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$classes = array();
		foreach ( (array) ( $config['classes'] ?? array() ) as $class => $weights ) {
			$class = sanitize_key( (string) $class );
			if ( '' === $class || ! is_array( $weights ) ) {
				continue;
			}

			$classes[ $class ] = array(
				'input'  => max( 1, (int) ( $weights['input'] ?? 10 ) ),
				'output' => max( 1, (int) ( $weights['output'] ?? 30 ) ),
			);
		}

		if ( empty( $classes ) ) {
			$classes['standard'] = array(
				'input'  => 10,
				'output' => 30,
			);
		}

		$model_to_class = array();
		foreach ( (array) ( $config['model_to_class'] ?? array() ) as $model => $class ) {
			$model = sanitize_text_field( (string) $model );
			$class = sanitize_key( (string) $class );
			if ( '' === $model || '' === $class ) {
				continue;
			}
			$model_to_class[ $model ] = $class;
		}

		$default_class = sanitize_key( (string) ( $config['default_class'] ?? 'standard' ) );
		if ( '' === $default_class || ! isset( $classes[ $default_class ] ) ) {
			$default_class = (string) array_key_first( $classes );
		}

		return array(
			'classes'        => $classes,
			'model_to_class' => $model_to_class,
			'default_class'  => $default_class,
			'cache_weights'  => array(
				'cache_read'  => max( 0.0, (float) ( $config['cache_weights']['cache_read'] ?? 0.1 ) ),
				'cache_write' => max( 0.0, (float) ( $config['cache_weights']['cache_write'] ?? 1.25 ) ),
			),
		);
	}

	/**
	 * Calculate advisory financial pressure from current balances alone.
	 */
	private function calculate_financial_pressure_state( int $monthly_budget, int $monthly_remaining, int $total_available, int $total_remaining ): string {
		if ( PHP_INT_MAX === $total_remaining ) {
			return 'normal';
		}

		$total_ratio        = $total_available > 0 ? ( $total_remaining / $total_available ) : 0.0;
		$has_monthly_budget = $monthly_budget > 0;
		$monthly_ratio      = $has_monthly_budget ? ( $monthly_remaining / $monthly_budget ) : 1.0;
		$monthly_critical   = $has_monthly_budget
			&& $monthly_ratio <= (float) ( $this->config['monthly_critical_ratio'] ?? 0.02 );
		$monthly_conserve   = $has_monthly_budget
			&& (
				$monthly_remaining <= 0
				|| $monthly_ratio <= (float) ( $this->config['monthly_conserve_ratio'] ?? 0.10 )
			);

		if ( $total_remaining <= 0
			|| $total_ratio <= (float) ( $this->config['financial_critical_ratio'] ?? 0.08 )
			|| $monthly_critical ) {
			return 'critical';
		}

		if ( $total_ratio <= (float) ( $this->config['financial_conserve_ratio'] ?? 0.20 )
			|| $monthly_conserve ) {
			return 'conserve';
		}

		return 'normal';
	}

	/**
	 * Combine bank balance pressure with the size of this specific planned request.
	 */
	private function resolve_financial_pressure_state( int $estimated_request_icus ): string {
		$base_state = (string) ( $this->financial_snapshot['budget_pressure_state'] ?? 'normal' );

		if ( ! in_array( $base_state, array( 'normal', 'conserve', 'critical' ), true ) ) {
			$base_state = 'normal';
		}

		if ( ! empty( $this->financial_snapshot['is_byok'] ) ) {
			return 'normal';
		}

		$total_remaining = (int) ( $this->financial_snapshot['total_remaining'] ?? 0 );
		if ( PHP_INT_MAX === $total_remaining ) {
			return $base_state;
		}

		if ( $total_remaining <= 0 ) {
			return 'critical';
		}

		if ( $estimated_request_icus <= 0 ) {
			return $base_state;
		}

		$request_ratio = $estimated_request_icus / max( 1, $total_remaining );
		if ( $request_ratio >= (float) ( $this->config['request_critical_ratio'] ?? 0.60 ) ) {
			return 'critical';
		}

		if ( $request_ratio >= (float) ( $this->config['request_conserve_ratio'] ?? 0.25 ) ) {
			return $this->max_pressure_state( $base_state, 'conserve' );
		}

		return $base_state;
	}

	/**
	 * Pick the hydration profile from separate context and financial pressures.
	 */
	private function select_hydration_profile( string $context_pressure, string $financial_pressure ): string {
		if ( 'critical' === $financial_pressure || 'critical' === $context_pressure ) {
			return 'critical';
		}

		if ( 'conserve' === $financial_pressure || 'high' === $context_pressure ) {
			return 'conserve';
		}

		return 'normal';
	}

	/**
	 * Return the config block for a named hydration profile.
	 */
	private function get_hydration_profile_config( string $profile_key ): array {
		$profiles = (array) ( $this->config['hydration_profiles'] ?? array() );
		$profile  = isset( $profiles[ $profile_key ] ) && is_array( $profiles[ $profile_key ] )
			? $profiles[ $profile_key ]
			: ( $profiles['normal'] ?? array() );

		return wp_parse_args(
			$profile,
			array(
				'group_cost_multiplier' => 1.0,
				'extra_group_buffer'    => 0,
				'support_preference'    => array( 'full', 'compact', 'minimal' ),
			)
		);
	}

	/**
	 * Merge caller preference with profile preference without duplicates.
	 *
	 * @param string $profile_key Profile key.
	 * @param array  $preference_order Caller preference.
	 * @return string[]
	 */
	private function merge_support_preference_order( string $profile_key, array $preference_order ): array {
		$profile = $this->get_hydration_profile_config( $profile_key );
		$merged  = array_merge(
			(array) ( $profile['support_preference'] ?? array() ),
			$preference_order
		);

		$normalized = array();
		foreach ( $merged as $variant ) {
			$variant = sanitize_key( (string) $variant );
			if ( '' === $variant || in_array( $variant, $normalized, true ) ) {
				continue;
			}
			$normalized[] = $variant;
		}

		return $normalized;
	}

	/**
	 * Return the stricter of two financial pressure states.
	 */
	private function max_pressure_state( string $a, string $b ): string {
		$order = array(
			'normal'   => 0,
			'conserve' => 1,
			'critical' => 2,
		);

		$a = $order[ $a ] ?? 0;
		$b = $order[ $b ] ?? 0;

		return array_search( max( $a, $b ), $order, true ) ?: 'normal';
	}
}
