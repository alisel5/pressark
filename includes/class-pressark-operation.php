<?php
/**
 * PressArk Operation — Value object representing a single operation contract.
 *
 * Every tool the AI can invoke is defined as an Operation with all its
 * runtime semantics in one place: capability level, handler routing,
 * preview strategy, group membership, plugin requirements, discovery
 * metadata, risk classification, and execution policy.
 *
 * v5.3.0: Extended execution contract inspired by Claude Code's Tool.ts
 * model — adds search hints, interrupt behavior, cache/output policies,
 * resumability, deferred loading intent, pre-permission validation, and
 * policy hook extension points. All new fields are optional with smart
 * defaults derived from existing fields (full backward compatibility).
 *
 * @package PressArk
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Operation {

	/** @var string[] Tool names classified as meta-tools (loader/discovery). */
	private const META_TOOLS = array( 'discover_tools', 'load_tools', 'load_tool_group' );

	// ── Execution contract defaults ────────────────────────────────

	/** @var string Default interrupt behavior. */
	private const DEFAULT_INTERRUPT = 'block';

	/** @var int Default cache TTL (0 = no caching). */
	private const DEFAULT_CACHE_TTL = 0;

	/** @var string Default output size policy. */
	private const DEFAULT_OUTPUT_POLICY = 'standard';

	/** @var string Default loading intent. */
	private const DEFAULT_DEFER = 'auto';

	/**
	 * Constructor — core contract (backward-compatible with v3.4.0).
	 *
	 * @param string      $name             Tool name (e.g., 'edit_content').
	 * @param string      $group            Group name (e.g., 'core', 'seo', 'woocommerce').
	 * @param string      $capability       'read' | 'preview' | 'confirm'.
	 * @param string      $handler          Handler key: 'discovery', 'content', 'seo', 'system',
	 *                                      'media', 'diagnostics', 'elementor', 'woo', 'automation'.
	 * @param string      $method           Method name on the handler (e.g., 'read_content').
	 * @param string      $preview_strategy Staging strategy: 'none', 'post_edit', 'new_post',
	 *                                      'meta_update', 'option_update', 'block_edit',
	 *                                      'elementor_widget', 'elementor_page'.
	 * @param string|null $requires         Plugin requirement: 'woocommerce', 'elementor', or null.
	 * @param string      $label            Human-readable short name (e.g., 'Edit Content').
	 * @param string      $description      One-liner for compact discovery.
	 * @param string      $risk             'safe' | 'moderate' | 'destructive'.
	 * @param bool        $concurrency_safe Whether this read tool can execute in a batch.
	 */
	public function __construct(
		public string  $name,
		public string  $group,
		public string  $capability,
		public string  $handler,
		public string  $method,
		public string  $preview_strategy = 'none',
		public ?string $requires = null,
		public string  $label = '',
		public string  $description = '',
		public string  $risk = 'safe',
		public bool    $concurrency_safe = true,
	) {}

	// ── Extended execution contract (v5.3.0) ───────────────────────
	//
	// New fields use a separate array to preserve constructor backward
	// compat. Populated via apply_contract() after construction.

	/**
	 * Search keywords for tool discovery (3-10 words).
	 * Like Claude's searchHint — supplements tool name for native search.
	 *
	 * @var string
	 */
	public string $search_hint = '';

	/**
	 * How to handle user interrupts while this tool is running.
	 * 'cancel' = abort immediately; 'block' = finish current operation.
	 *
	 * @var string 'cancel'|'block'
	 */
	public string $interrupt = self::DEFAULT_INTERRUPT;

	/**
	 * Suggested cache TTL in seconds for read results.
	 * 0 = no caching (default). Positive value = seconds.
	 * Only meaningful for read-capability tools.
	 *
	 * @var int
	 */
	public int $cache_ttl = self::DEFAULT_CACHE_TTL;

	/**
	 * Output size policy — hints how the agent should handle results.
	 * 'compact'  = truncate aggressively, prefer summaries.
	 * 'standard' = default handling.
	 * 'large'    = expect big output, persist to temp if needed.
	 *
	 * @var string 'compact'|'standard'|'large'
	 */
	public string $output_policy = self::DEFAULT_OUTPUT_POLICY;

	/**
	 * Whether this operation can resume after interruption.
	 * When true, partial results are preserved and the operation
	 * can restart from where it left off.
	 *
	 * @var bool
	 */
	public bool $resumable = false;

	/**
	 * Loading intent — controls when schemas are sent to the model.
	 * 'auto'        = loader decides based on groups (current behavior).
	 * 'always_load' = always include in initial schema set.
	 * 'deferred'    = only load via discover_tools/load_tools.
	 *
	 * @var string 'auto'|'always_load'|'deferred'
	 */
	public string $defer = self::DEFAULT_DEFER;

	/**
	 * Whether this tool is safe to retry on failure (idempotent).
	 * Reads default to true; writes default to false.
	 *
	 * @var bool|null null = auto-derive from capability.
	 */
	public ?bool $idempotent = null;

	/**
	 * Callable for pre-permission input validation.
	 * Signature: fn(array $params): array{valid: bool, message?: string}
	 * Runs BEFORE capability/approval checks (fail-fast on bad input).
	 * null = no pre-validation (default).
	 *
	 * @var callable|null
	 */
	public mixed $validate = null;

	/**
	 * WordPress filter names to fire during execution lifecycle.
	 * Each entry maps a phase to one or more filter names:
	 *   'pre_execute'  => fires before handler dispatch.
	 *   'post_execute' => fires after handler dispatch with result.
	 *   'pre_approve'  => fires before approval UI is shown.
	 *
	 * @var array<string, string|string[]>
	 */
	public array $policy_hooks = array();

	/**
	 * Tags for categorization and future native search.
	 * Short lowercase tokens: ['woocommerce', 'revenue', 'analytics'].
	 *
	 * @var string[]
	 */
	public array $tags = array();

	/**
	 * Verification contract — declares how to prove a write succeeded.
	 *
	 * Keys:
	 *   'strategy'     => 'none'|'read_back'|'field_check'|'existence_check'
	 *   'read_tool'    => Tool name to call for read-back (e.g. 'read_content').
	 *   'read_args'    => Default args merged into the read-back call.
	 *   'check_fields' => Fields to verify match between write intent and read-back.
	 *   'intensity'    => 'light'|'standard'|'thorough'
	 *   'nudge'        => bool — append evidence nudge to tool result for the AI model.
	 *
	 * Empty array = no verification (default for reads and low-risk writes).
	 *
	 * @since 5.4.0
	 * @var array
	 */
	public array $verification = array();

	/**
	 * Read invalidation contract for write operations.
	 *
	 * Keys:
	 *   'scope'           => 'target_posts'|'site_content'|'resource'|'site'
	 *   'resource_groups' => string[]
	 *   'resource_uris'   => string[]
	 *   'reason'          => short human-readable invalidation note
	 *
	 * Empty array means the registry-level fallback invalidation applies.
	 *
	 * @var array
	 */
	public array $read_invalidation = array();

	// ── Contract application ───────────────────────────────────────

	/**
	 * Apply extended contract fields from an associative array.
	 *
	 * Only sets recognized fields; ignores unknowns silently.
	 * This is the migration-safe way to populate new fields without
	 * changing the constructor signature.
	 *
	 * @since 5.3.0
	 * @param array $contract Associative array of contract overrides.
	 * @return static For chaining.
	 */
	public function apply_contract( array $contract ): static {
		$allowed = array(
			'search_hint', 'interrupt', 'cache_ttl', 'output_policy',
			'resumable', 'defer', 'idempotent', 'validate',
			'policy_hooks', 'tags', 'verification', 'read_invalidation',
		);

		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $contract ) ) {
				$this->{$field} = $contract[ $field ];
			}
		}

		return $this;
	}

	// ── Computed accessors ─────────────────────────────────────────

	public function is_read(): bool {
		return 'read' === $this->capability;
	}

	public function is_preview(): bool {
		return 'preview' === $this->capability;
	}

	public function is_confirm(): bool {
		return 'confirm' === $this->capability;
	}

	public function is_write(): bool {
		return 'read' !== $this->capability;
	}

	public function needs_staging(): bool {
		return 'none' !== $this->preview_strategy;
	}

	public function is_meta(): bool {
		return in_array( $this->name, self::META_TOOLS, true );
	}

	public function is_destructive(): bool {
		return 'destructive' === $this->risk;
	}

	/**
	 * Whether this tool is read-only (no state mutations).
	 *
	 * @since 5.3.0
	 */
	public function is_read_only(): bool {
		return $this->is_read();
	}

	/**
	 * Whether this tool is safe to retry on failure.
	 * Returns the explicit idempotent flag, or auto-derives from capability.
	 *
	 * @since 5.3.0
	 */
	public function is_idempotent(): bool {
		if ( null !== $this->idempotent ) {
			return $this->idempotent;
		}
		return $this->is_read();
	}

	/**
	 * Whether this tool can safely execute in a batched group of reads.
	 *
	 * Returns true only for read-capability tools that are explicitly
	 * marked concurrency-safe. Meta-tools always return false because
	 * they mutate shared tool_set/tool_defs state.
	 */
	public function is_concurrency_safe(): bool {
		return $this->is_read() && ! $this->is_meta() && $this->concurrency_safe;
	}

	/**
	 * Whether this tool should always be loaded in the initial schema set.
	 *
	 * @since 5.3.0
	 */
	public function is_always_load(): bool {
		return 'always_load' === $this->defer;
	}

	/**
	 * Whether this tool should only be loaded via discovery meta-tools.
	 *
	 * @since 5.3.0
	 */
	public function is_deferred(): bool {
		return 'deferred' === $this->defer;
	}

	/**
	 * Whether this tool can be safely interrupted (cancelled).
	 *
	 * @since 5.3.0
	 */
	public function is_cancellable(): bool {
		return 'cancel' === $this->interrupt;
	}

	/**
	 * Whether results from this tool can be cached.
	 *
	 * @since 5.3.0
	 */
	public function is_cacheable(): bool {
		return $this->cache_ttl > 0;
	}

	/**
	 * Whether this operation has a verification contract.
	 *
	 * @since 5.4.0
	 */
	public function has_verification(): bool {
		return ! empty( $this->verification )
			&& isset( $this->verification['strategy'] )
			&& 'none' !== $this->verification['strategy'];
	}

	/**
	 * Get the verification contract, or null if none.
	 *
	 * @since 5.4.0
	 * @return array|null
	 */
	public function get_verification(): ?array {
		return $this->has_verification() ? $this->verification : null;
	}

	/**
	 * Run pre-permission validation if a validator is set.
	 *
	 * @since 5.3.0
	 * @param array $params Tool parameters from the AI.
	 * @return array{valid: bool, message?: string}
	 */
	public function validate_input( array $params ): array {
		if ( null === $this->validate || ! is_callable( $this->validate ) ) {
			return array( 'valid' => true );
		}
		$result = call_user_func( $this->validate, $params );
		if ( ! is_array( $result ) || ! isset( $result['valid'] ) ) {
			return array( 'valid' => true );
		}
		return $result;
	}

	/**
	 * Get the full execution contract as a flat array.
	 *
	 * Returns all operation semantics — both legacy and extended — in one
	 * associative array. Useful for serialization, debugging, and passing
	 * the full contract to filters.
	 *
	 * @since 5.3.0
	 * @return array<string, mixed>
	 */
	public function execution_contract(): array {
		return array(
			// ── Identity ──
			'name'             => $this->name,
			'group'            => $this->group,
			'label'            => $this->label,
			'description'      => $this->description,
			'search_hint'      => $this->search_hint,
			'tags'             => $this->tags,

			// ── Routing ──
			'handler'          => $this->handler,
			'method'           => $this->method,
			'requires'         => $this->requires,

			// ── Capability & Risk ──
			'capability'       => $this->capability,
			'risk'             => $this->risk,
			'read_only'        => $this->is_read_only(),
			'destructive'      => $this->is_destructive(),
			'idempotent'       => $this->is_idempotent(),

			// ── Preview & Staging ──
			'preview_strategy' => $this->preview_strategy,
			'needs_staging'    => $this->needs_staging(),

			// ── Execution Semantics ──
			'concurrency_safe' => $this->is_concurrency_safe(),
			'interrupt'        => $this->interrupt,
			'resumable'        => $this->resumable,

			// ── Caching & Output ──
			'cache_ttl'        => $this->cache_ttl,
			'output_policy'    => $this->output_policy,

			// ── Loading Intent ──
			'defer'            => $this->defer,

			// ── Policy Hooks ──
			'policy_hooks'     => $this->policy_hooks,

			// ── Verification ──
			'verification'     => $this->verification,
			'read_invalidation'=> $this->read_invalidation,
		);
	}
}
