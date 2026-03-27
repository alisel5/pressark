<?php
/**
 * PressArk Model Policy — Task-aware model/provider routing (v3.5.0).
 *
 * Routes requests to the most appropriate model based on:
 *   - Task type (classify, analyze, generate, edit, code, chat)
 *   - User tier and entitlements
 *   - Provider capabilities (tool calling, context window)
 *   - Cost efficiency (cheaper models for simple tasks)
 *
 * All tier defaults sourced from PressArk_Entitlements::TIER_CONFIG.
 *
 * @package PressArk
 * @since   3.0.0
 * @since   3.5.0 Task-aware routing, provider capability matrix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Model_Policy {

	private static ?array $multiplier_cache = null;

	/** Models restricted to Pro+ tiers. */
	private const PRO_MODELS = array(
		'anthropic/claude-haiku-4.5',
		'moonshotai/kimi-k2.5',
		'z-ai/glm-5',
		'openai/gpt-5.4-mini',
		'anthropic/claude-sonnet-4.6',
	);

	/** Models restricted to Team+ tiers (team, agency, enterprise). */
	private const TEAM_MODELS = array(
		'anthropic/claude-opus-4.6',
		'openai/gpt-5.3-codex',
		'openai/gpt-5.4',
	);

	/** Free-tier models eligible for deep_mode upgrade. */
	private const FREE_MODELS = array(
		'deepseek/deepseek-v3.2',
		'minimax/minimax-m2.7',
	);

	/** Model prefixes known to support native tool calling. */
	private const TOOL_CAPABLE_PREFIXES = array(
		'anthropic/claude',
		'openai/gpt',
		'openai/o3',
		'openai/o4',
		'deepseek/',
		'deepseek-',
		'minimax/',
		'moonshotai/',
		'z-ai/',
	);

	/** Providers that always support native tool calling. */
	private const TOOL_CAPABLE_PROVIDERS = array(
		'openai',
		'anthropic',
		'deepseek',
		'minimax',
		'moonshotai',
		'z-ai',
	);

	/** All major providers (for BYOK tool support check). */
	private const ALL_PROVIDERS = array(
		'openai',
		'anthropic',
		'openrouter',
		'deepseek',
		'minimax',
		'moonshotai',
		'z-ai',
	);

	/**
	 * Task type → model routing matrix.
	 *
	 * Maps task categories to model recommendations per cost tier.
	 * 'economy' = cheapest viable model, 'standard' = tier default,
	 * 'premium' = best available for the task.
	 *
	 * Used by for_task() to select the right model without overspending.
	 */
	private const TASK_ROUTING = array(
		// Simple classification, intent detection, keyword extraction.
		'classify' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'deepseek/deepseek-v3.2',
			'premium'  => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => false,
		),
		// Reading, analysis, summarization, SEO/security scans.
		'analyze' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'anthropic/claude-sonnet-4.6',
			'premium'  => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => true,
		),
		// Content generation, rewriting, bulk meta.
		'generate' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'anthropic/claude-sonnet-4.6',
			'premium'  => 'anthropic/claude-opus-4.6',
			'needs_tools' => false,
		),
		// Editing posts, products, settings — write operations.
		'edit' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'anthropic/claude-sonnet-4.6',
			'premium'  => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => true,
		),
		// Code-related: shortcodes, Elementor, theme editing.
		'code' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'anthropic/claude-sonnet-4.6',
			'premium'  => 'anthropic/claude-opus-4.6',
			'needs_tools' => true,
		),
		// General conversation, greetings, help.
		'chat' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'deepseek/deepseek-v3.2',
			'premium'  => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => false,
		),
		// Diagnostics: site health, speed, crawl, email tests.
		'diagnose' => array(
			'economy'  => 'deepseek/deepseek-v3.2',
			'standard' => 'anthropic/claude-sonnet-4.6',
			'premium'  => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => true,
		),
	);

	/**
	 * Deterministic workflow phases routed more cheaply than full task routes.
	 *
	 * classification and retrieval_planning stay on the cheapest viable model.
	 * Premium models are reserved for ambiguity resolution, sparse-signal
	 * diagnosis, and final synthesis.
	 */
	private const PHASE_ROUTING = array(
		'classification' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'deepseek/deepseek-v3.2',
			'premium'     => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => false,
		),
		'retrieval_planning' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'deepseek/deepseek-v3.2',
			'premium'     => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => false,
		),
		// Structured context compression stays on the cheapest model.
		// DeepSeek V3.2 is the cheapest bundled model for compression.
		'summarize' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'deepseek/deepseek-v3.2',
			'premium'     => 'deepseek/deepseek-v3.2',
			'needs_tools' => false,
		),
		'ambiguity_resolution' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'anthropic/claude-sonnet-4.6',
			'premium'     => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => false,
		),
		'diagnosis' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'anthropic/claude-sonnet-4.6',
			'premium'     => 'anthropic/claude-sonnet-4.6',
			'needs_tools' => true,
		),
		'final_synthesis' => array(
			'economy'     => 'deepseek/deepseek-v3.2',
			'standard'    => 'anthropic/claude-sonnet-4.6',
			'premium'     => 'anthropic/claude-opus-4.6',
			'needs_tools' => false,
		),
	);

	/**
	 * Compatible fallback models for auto-routed bundled calls.
	 *
	 * The connector filters these through tool/data-policy checks before use.
	 */
	private const FALLBACK_MODELS = array(
		'deepseek/deepseek-v3.2' => array(
			'minimax/minimax-m2.7',
			'anthropic/claude-haiku-4.5',
		),
		'minimax/minimax-m2.7' => array(
			'deepseek/deepseek-v3.2',
			'anthropic/claude-haiku-4.5',
		),
		'anthropic/claude-haiku-4.5' => array(
			'openai/gpt-5.4-mini',
			'deepseek/deepseek-v3.2',
		),
		'moonshotai/kimi-k2.5' => array(
			'anthropic/claude-haiku-4.5',
			'openai/gpt-5.4-mini',
		),
		'z-ai/glm-5' => array(
			'anthropic/claude-haiku-4.5',
			'openai/gpt-5.4-mini',
		),
		'openai/gpt-5.4-mini' => array(
			'anthropic/claude-haiku-4.5',
			'deepseek/deepseek-v3.2',
		),
		'anthropic/claude-sonnet-4.6' => array(
			'openai/gpt-5.4',
			'openai/gpt-5.3-codex',
		),
		'anthropic/claude-opus-4.6' => array(
			'anthropic/claude-sonnet-4.6',
			'openai/gpt-5.4',
		),
		'openai/gpt-5.4' => array(
			'anthropic/claude-sonnet-4.6',
			'openai/gpt-5.3-codex',
		),
		'openai/gpt-5.3-codex' => array(
			'openai/gpt-5.4',
			'anthropic/claude-sonnet-4.6',
		),
	);

	/**
	 * Provider capability registry.
	 *
	 * prompt_caching      — supports prompt caching (Anthropic, DeepSeek).
	 * tool_calling        — supports native tool calling.
	 * parallel_tool_calls — supports the parallel_tool_calls request parameter
	 *                       (OpenAI-compatible APIs). Anthropic natively returns
	 *                       multiple tool_use blocks without a parameter.
	 * streaming           — supports streaming responses.
	 * max_output          — max output tokens per request.
	 * cost_per_mtok       — approximate cost per million tokens (input, for routing).
	 */
	private const PROVIDER_CAPABILITIES = array(
		'anthropic' => array(
			'prompt_caching'      => true,
			'tool_calling'        => true,
			'parallel_tool_calls' => false, // Native multi-tool — no request param needed.
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 1.0,
		),
		'openai' => array(
			'prompt_caching'      => false,
			'tool_calling'        => true,
			'parallel_tool_calls' => true,
			'streaming'           => true,
			'max_output'          => 16384,
			'cost_per_mtok'       => 0.75,
		),
		'deepseek' => array(
			'prompt_caching'      => true,
			'tool_calling'        => true,
			'parallel_tool_calls' => true,
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 0.26,
		),
		'minimax' => array(
			'prompt_caching'      => false,
			'tool_calling'        => true,
			'parallel_tool_calls' => true,
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 0.30,
		),
		'moonshotai' => array(
			'prompt_caching'      => false,
			'tool_calling'        => true,
			'parallel_tool_calls' => true,
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 0.45,
		),
		'z-ai' => array(
			'prompt_caching'      => false,
			'tool_calling'        => true,
			'parallel_tool_calls' => true,
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 0.72,
		),
		'openrouter' => array(
			'prompt_caching'      => false,
			'tool_calling'        => true,
			'parallel_tool_calls' => true, // Passed through to underlying model.
			'streaming'           => true,
			'max_output'          => 8192,
			'cost_per_mtok'       => 0.0, // Varies by model.
		),
	);

	// ── Model Resolution ───────────────────────────────────────────

	/**
	 * Resolve the model to use for a given request context.
	 *
	 * @param string $tier      User's plan tier.
	 * @param bool   $deep_mode Whether deep mode is active.
	 * @return string Model identifier.
	 */
	public static function resolve( string $tier, bool $deep_mode = false ): string {
		$model  = get_option( 'pressark_model', 'auto' );
		$is_pro = PressArk_Entitlements::is_paid_tier( $tier );

		// Custom model (user-set).
		if ( 'custom' === $model && ! $deep_mode ) {
			$custom = get_option( 'pressark_custom_model', '' );
			return ! empty( $custom ) ? $custom : PressArk_Entitlements::default_model( 'free' );
		}

		// Auto mode — tier-based default.
		if ( 'auto' === $model ) {
			if ( $deep_mode && $is_pro ) {
				return PressArk_Entitlements::default_model( 'pro' );
			}
			return PressArk_Entitlements::default_model( $tier );
		}

		// Deep mode upgrade: free models → premium when user is Pro.
		if ( $deep_mode && $is_pro && in_array( $model, self::FREE_MODELS, true ) ) {
			return PressArk_Entitlements::default_model( 'pro' );
		}

		// Pro model gate: downgrade for free-tier users.
		if ( in_array( $model, self::PRO_MODELS, true ) && ! $is_pro ) {
			return PressArk_Entitlements::default_model( 'free' );
		}

		// Team+ model gate: downgrade for non-team tiers.
		if ( self::is_team_model( $model ) && ! self::is_team_tier( $tier ) ) {
			return PressArk_Entitlements::default_model( PressArk_Entitlements::is_paid_tier( $tier ) ? 'pro' : 'free' );
		}

		return $model;
	}

	// ── Task-Type Routing (v3.5.0) ────────────────────────────────

	/**
	 * Get the recommended model for a specific task type.
	 *
	 * Routes based on task complexity, tier, and cost efficiency:
	 *   - Free tier always gets economy models.
	 *   - Pro tier gets standard models.
	 *   - Deep mode on Pro+ gets premium models.
	 *
	 * @param string $task_type 'classify', 'analyze', 'generate', 'edit', 'code', 'chat', 'diagnose'.
	 * @param string $tier      User's plan tier.
	 * @param bool   $deep_mode Whether deep mode is active.
	 * @return string Model identifier.
	 */
	public static function for_task( string $task_type, string $tier, bool $deep_mode = false ): string {
		$routing = self::TASK_ROUTING[ $task_type ] ?? self::TASK_ROUTING['chat'];
		$is_pro  = PressArk_Entitlements::is_paid_tier( $tier );

		if ( ! $is_pro ) {
			$model = $routing['economy'];
		} elseif ( $deep_mode ) {
			$model = $routing['premium'];
		} else {
			$model = $routing['standard'];
		}

		return (string) apply_filters( 'pressark_model_for_task', $model, $task_type, $tier, $deep_mode );
	}

	/**
	 * Resolve model for a workflow phase.
	 *
	 * @param string $phase   Deterministic phase key.
	 * @param string $tier    User plan tier.
	 * @param array  $context Optional flags: deep_mode, sparse_signal, prefer_premium.
	 * @return string
	 */
	public static function for_phase( string $phase, string $tier, array $context = array() ): string {
		if ( 'summarize' === $phase ) {
			$configured = get_option( 'pressark_summarize_model', 'auto' );
			if ( 'custom' === $configured ) {
				$configured = sanitize_text_field( (string) get_option( 'pressark_summarize_custom_model', '' ) );
			}
			if ( '' !== $configured && 'auto' !== $configured ) {
				if ( in_array( $configured, self::PRO_MODELS, true ) && ! PressArk_Entitlements::is_paid_tier( $tier ) ) {
					return self::PHASE_ROUTING['summarize']['economy'];
				}
				return $configured;
			}
		}

		$configured = get_option( 'pressark_model', 'auto' );
		$deep_mode  = ! empty( $context['deep_mode'] );

		if ( 'auto' !== $configured ) {
			return self::resolve( $tier, $deep_mode );
		}

		$routing = self::PHASE_ROUTING[ $phase ] ?? self::PHASE_ROUTING['final_synthesis'];
		$is_pro  = PressArk_Entitlements::is_paid_tier( $tier );

		if ( ! $is_pro ) {
			$model = $routing['economy'];
		} elseif ( self::phase_uses_premium( $phase, $context ) ) {
			$model = $routing['premium'];
		} else {
			$model = $routing['standard'];
		}

		return (string) apply_filters( 'pressark_model_for_phase', $model, $phase, $tier, $context );
	}

	/**
	 * Check if a task type requires native tool calling.
	 *
	 * @param string $task_type Task category.
	 * @return bool
	 */
	public static function task_needs_tools( string $task_type ): bool {
		return self::TASK_ROUTING[ $task_type ]['needs_tools'] ?? false;
	}

	/**
	 * Check if a workflow phase needs native tool calling.
	 *
	 * @param string $phase Phase key.
	 * @return bool
	 */
	public static function phase_needs_tools( string $phase ): bool {
		return self::PHASE_ROUTING[ $phase ]['needs_tools'] ?? false;
	}

	/**
	 * Whether a phase should use premium routing for paid tiers.
	 *
	 * @param string $phase   Phase key.
	 * @param array  $context Optional routing hints.
	 * @return bool
	 */
	public static function phase_uses_premium( string $phase, array $context = array() ): bool {
		switch ( $phase ) {
			case 'summarize':
				return false;
			case 'ambiguity_resolution':
			case 'final_synthesis':
				return true;
			case 'diagnosis':
				return ! empty( $context['sparse_signal'] ) || ! empty( $context['prefer_premium'] );
			default:
				return ! empty( $context['prefer_premium'] );
		}
	}

	// ── Tool Support ───────────────────────────────────────────────

	/**
	 * Check whether a model supports native tool calling.
	 *
	 * Strict check: requires explicit model prefix match against the
	 * known-good list. Does NOT assume all models on a known provider
	 * support tools — that assumption is wrong for fine-tuned or custom
	 * models on BYOK providers.
	 *
	 * For the bundled (non-BYOK) path where WE control the model list,
	 * use supports_tools_bundled() which includes the provider fallback.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider name (used only for logging, not as fallback).
	 * @return bool
	 */
	public static function supports_tools( string $model, string $provider ): bool {
		// Check model prefix against known tool-capable models.
		foreach ( self::TOOL_CAPABLE_PREFIXES as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}

		// No provider-level fallback — unknown models are assumed
		// tool-incapable until explicitly verified.
		return false;
	}

	/**
	 * Check tool support for bundled (non-BYOK) models.
	 *
	 * This is safe to use when PressArk controls the model selection
	 * (auto mode, task routing), because we know our routing matrix
	 * only selects tool-capable models for tool-needing tasks.
	 *
	 * Includes the provider-level fallback since bundled models on
	 * known providers are always tool-capable.
	 *
	 * @param string $model    Model identifier.
	 * @param string $provider Provider name.
	 * @return bool
	 */
	public static function supports_tools_bundled( string $model, string $provider ): bool {
		// Strict check first.
		if ( self::supports_tools( $model, $provider ) ) {
			return true;
		}

		// Provider-level fallback — safe for bundled models.
		if ( in_array( $provider, self::TOOL_CAPABLE_PROVIDERS, true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check tool support for BYOK users.
	 *
	 * Conservative: only returns true if the provider is a known API
	 * that supports tool calling AND the model matches a known prefix.
	 * Falls back to provider-level check only when no model is specified.
	 *
	 * @param string $provider BYOK provider name.
	 * @param string $model    Optional model identifier for stricter check.
	 * @return bool
	 */
	public static function supports_tools_byok( string $provider, string $model = '' ): bool {
		// If a model is specified, use the strict check.
		if ( ! empty( $model ) ) {
			return self::supports_tools( $model, $provider );
		}

		// No model specified — fall back to provider-level (user explicitly
		// chose this provider, so we trust their API supports tools).
		return in_array( $provider, self::ALL_PROVIDERS, true );
	}

	/**
	 * Check whether bundled routing may attempt a fallback model/provider.
	 *
	 * BYOK, pinned models, and strict data-policy contexts are never changed.
	 *
	 * @param string $transport_provider Current API transport provider.
	 * @param bool   $is_byok            Whether the call uses BYOK credentials.
	 * @param array  $constraints        Optional routing constraints.
	 * @return bool
	 */
	public static function can_use_fallback( string $transport_provider, bool $is_byok, array $constraints = array() ): bool {
		if ( $is_byok ) {
			return false;
		}

		if ( ! empty( $constraints['model_pinned'] ) || ! empty( $constraints['data_policy_locked'] ) ) {
			return false;
		}

		return in_array( $transport_provider, self::ALL_PROVIDERS, true );
	}

	/**
	 * Return compatible fallback candidates for a model.
	 *
	 * @param string $model       Current model.
	 * @param array  $constraints Optional tool/data-policy constraints.
	 * @return array
	 */
	public static function fallback_candidates( string $model, array $constraints = array() ): array {
		$candidates = self::FALLBACK_MODELS[ $model ] ?? array();
		$filtered   = array();

		foreach ( $candidates as $candidate ) {
			if ( self::fallback_model_compatible( $model, $candidate, $constraints ) ) {
				$filtered[] = $candidate;
			}
		}

		return $filtered;
	}

	/**
	 * Check whether a fallback preserves required tool/data-policy behavior.
	 *
	 * @param string $from_model   Current model.
	 * @param string $to_model     Candidate fallback model.
	 * @param array  $constraints  Routing constraints.
	 * @return bool
	 */
	public static function fallback_model_compatible( string $from_model, string $to_model, array $constraints = array() ): bool {
		$transport_provider = (string) ( $constraints['transport_provider'] ?? '' );
		$requires_tools     = ! empty( $constraints['requires_tools'] );

		if ( $requires_tools && ! self::supports_tools( $to_model, $transport_provider ) ) {
			return false;
		}

		if ( ! empty( $constraints['same_vendor_only'] )
			&& self::model_vendor( $from_model ) !== self::model_vendor( $to_model )
		) {
			return false;
		}

		if ( '' !== $transport_provider && 'openrouter' !== $transport_provider ) {
			return self::model_vendor( $to_model ) === $transport_provider;
		}

		return true;
	}

	// ── Provider Capabilities ─────────────────────────────────────

	/**
	 * Get capabilities for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return array Capability map.
	 */
	public static function provider_capabilities( string $provider ): array {
		return self::PROVIDER_CAPABILITIES[ $provider ] ?? self::PROVIDER_CAPABILITIES['openrouter'];
	}

	/**
	 * Check if a provider supports a specific capability.
	 *
	 * @param string $provider   Provider name.
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function provider_supports( string $provider, string $capability ): bool {
		$caps = self::provider_capabilities( $provider );
		return ! empty( $caps[ $capability ] );
	}

	public static function get_model_class( string $model ): string {
		$config        = self::get_multiplier_config();
		$default_class = (string) ( $config['default_class'] ?? 'standard' );
		$model_map     = (array) ( $config['model_to_class'] ?? array() );
		$classes       = (array) ( $config['classes'] ?? array() );
		$model_class   = (string) ( $model_map[ $model ] ?? $default_class );

		return isset( $classes[ $model_class ] ) ? $model_class : $default_class;
	}

	public static function get_model_multiplier( string $model ): array {
		$config        = self::get_multiplier_config();
		$default_class = (string) ( $config['default_class'] ?? 'standard' );
		$classes       = (array) ( $config['classes'] ?? array() );
		$model_class   = self::get_model_class( $model );
		$multiplier    = (array) ( $classes[ $model_class ] ?? $classes[ $default_class ] ?? array() );

		return array(
			'input'  => (int) ( $multiplier['input'] ?? 10 ),
			'output' => (int) ( $multiplier['output'] ?? 30 ),
		);
	}

	private static function get_multiplier_config(): array {
		if ( is_array( self::$multiplier_cache ) ) {
			return self::$multiplier_cache;
		}

		$bank = new PressArk_Token_Bank();
		self::$multiplier_cache = $bank->get_multipliers();

		return is_array( self::$multiplier_cache ) ? self::$multiplier_cache : array(
			'classes' => array(
				'standard' => array(
					'input'  => 10,
					'output' => 30,
				),
			),
			'model_to_class' => array(),
			'default_class'  => 'standard',
			'cache_weights'  => array(
				'cache_read'  => 0.1,
				'cache_write' => 1.25,
			),
		);
	}

	// ── Native Tool Search ────────────────────────────────────────

	/**
	 * Model prefixes that support native tool search (built-in tool
	 * discovery without scaffolding meta-tools like discover_tools/load_tools).
	 *
	 * These models can efficiently handle large tool sets (100+) with
	 * built-in search/selection, so we skip the local discovery layer
	 * and send all tool schemas directly.
	 *
	 * @since 3.8.0
	 */
	private const NATIVE_TOOL_SEARCH_PREFIXES = array(
		'openai/gpt-5',
		'openai/o3-pro',
		'openai/o4-mini',
	);

	/**
	 * Check if a model supports native tool search.
	 *
	 * Models with native tool search handle large tool sets efficiently
	 * and don't need the discover_tools/load_tools scaffolding.
	 *
	 * @since 3.8.0
	 *
	 * @param string $model Model identifier (e.g. 'openai/gpt-5.4').
	 * @return bool
	 */
	public static function has_native_tool_search( string $model ): bool {
		foreach ( self::NATIVE_TOOL_SEARCH_PREFIXES as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Accessors ──────────────────────────────────────────────────

	/**
	 * Get the default model for a tier.
	 *
	 * @param string $tier Plan tier.
	 * @return string Model identifier.
	 */
	public static function get_tier_default( string $tier ): string {
		return PressArk_Entitlements::default_model( $tier );
	}

	/**
	 * Check if a model requires Pro+ tier.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	public static function is_pro_model( string $model ): bool {
		return in_array( $model, self::PRO_MODELS, true ) || in_array( $model, self::TEAM_MODELS, true );
	}

	/**
	 * Check if a model requires Team+ tier.
	 *
	 * @param string $model Model identifier.
	 * @return bool
	 */
	public static function is_team_model( string $model ): bool {
		return in_array( $model, self::TEAM_MODELS, true );
	}

	/**
	 * Check if a tier qualifies for Team+ models.
	 *
	 * @param string $tier Plan tier.
	 * @return bool
	 */
	private static function is_team_tier( string $tier ): bool {
		return in_array( $tier, array( 'team', 'agency', 'enterprise' ), true );
	}

	/**
	 * Get all available task types.
	 *
	 * @return array Task type keys.
	 */
	public static function task_types(): array {
		return array_keys( self::TASK_ROUTING );
	}

	/**
	 * Get supported workflow phase keys.
	 *
	 * @return array
	 */
	public static function phase_types(): array {
		return array_keys( self::PHASE_ROUTING );
	}

	/**
	 * Extract the vendor prefix from a model id.
	 *
	 * @param string $model Model identifier.
	 * @return string
	 */
	private static function model_vendor( string $model ): string {
		$parts = explode( '/', $model, 2 );
		return $parts[0] ?? $model;
	}
}
