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

	/**
	 * Authoritative parameter contract for provider schemas and validation.
	 *
	 * Shape:
	 * - properties: field => schema
	 * - required: string[]
	 * - one_of: array<int, array{fields: string[], mode?: string, message?: string}>
	 * - dependencies: array<int, array{field: string, values?: array, requires?: string[], field_values?: array, message?: string}>
	 * - compatibility_aliases: array<string, string[]>
	 * - strict/additionalProperties: provider-facing schema tightening hints
	 *
	 * Property schemas support JSON-Schema-like keys such as type,
	 * description, enum, default, items, properties, minimum, maximum,
	 * minLength, maxLength, minItems, maxItems, minProperties,
	 * maxProperties, and format.
	 *
	 * @var array
	 */
	public array $parameter_contract = array();

	/**
	 * Compact tool-local guidance that should stay close to the operation.
	 *
	 * @var string[]
	 */
	public array $model_guidance = array();

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
			'parameter_contract', 'model_guidance',
		);

		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $contract ) ) {
				$this->{$field} = $contract[ $field ];
			}
		}

		return $this;
	}

	/**
	 * Whether this operation has an authoritative parameter contract.
	 *
	 * @since 5.5.0
	 */
	public function has_parameter_contract(): bool {
		return ! empty( $this->parameter_contract['properties'] )
			&& is_array( $this->parameter_contract['properties'] );
	}

	/**
	 * Get the parameter contract, or null when this operation still uses
	 * the legacy flat param compatibility path.
	 *
	 * @since 5.5.0
	 * @return array|null
	 */
	public function get_parameter_contract(): ?array {
		return $this->has_parameter_contract() ? $this->parameter_contract : null;
	}

	/**
	 * Get compact model guidance lines attached to this operation.
	 *
	 * @since 5.5.0
	 * @return string[]
	 */
	public function get_model_guidance(): array {
		return array_values( array_filter( array_map(
			'sanitize_text_field',
			(array) $this->model_guidance
		) ) );
	}

	/**
	 * Whether this operation declares any verification policy, including an
	 * explicit "none + nudge" contract for high-risk writes.
	 *
	 * @since 5.5.0
	 */
	public function has_verification_policy(): bool {
		return ! empty( $this->verification ) && is_array( $this->verification );
	}

	/**
	 * Whether this operation declares an explicit read invalidation policy.
	 *
	 * @since 5.5.0
	 */
	public function has_read_invalidation_policy(): bool {
		return ! empty( $this->read_invalidation ) && is_array( $this->read_invalidation );
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
		$params = $this->normalize_contract_params( $params );

		$contract_validation = $this->validate_parameter_contract( $params );
		if ( ! ( $contract_validation['valid'] ?? true ) ) {
			return $contract_validation;
		}

		if ( null === $this->validate || ! is_callable( $this->validate ) ) {
			return array(
				'valid'  => true,
				'params' => $params,
			);
		}
		$result = call_user_func( $this->validate, $params );
		if ( ! is_array( $result ) || ! isset( $result['valid'] ) ) {
			return array(
				'valid'  => true,
				'params' => $params,
			);
		}
		if ( ! empty( $result['valid'] ) && ! isset( $result['params'] ) ) {
			$result['params'] = $params;
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
			'parameter_contract' => $this->parameter_contract,
			'model_guidance'     => $this->model_guidance,
		);
	}

	/**
	 * Apply compatibility aliases before validation so older tool payloads
	 * can still be normalized onto the canonical contract shape.
	 *
	 * @since 5.5.0
	 * @param array $params Tool parameters.
	 * @return array
	 */
	private function normalize_contract_params( array $params ): array {
		if ( ! $this->has_parameter_contract() ) {
			return $params;
		}

		$params = self::apply_contract_aliases( $params, $this->parameter_contract );
		$params = self::coerce_contract_value( $params, $this->parameter_contract );
		$params = self::apply_contract_aliases( $params, $this->parameter_contract );

		return self::coerce_contract_value( $params, $this->parameter_contract );
	}

	/**
	 * Apply compatibility aliases recursively so strict schemas can still
	 * normalize legacy payload shapes before validation.
	 *
	 * @since 5.5.0
	 * @param mixed $value  Incoming value.
	 * @param array $schema Schema fragment.
	 * @return mixed
	 */
	private static function apply_contract_aliases( mixed $value, array $schema ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_list_array( $value ) ) {
			if ( is_array( $schema['items'] ?? null ) ) {
				foreach ( $value as $index => $item ) {
					$value[ $index ] = self::apply_contract_aliases( $item, $schema['items'] );
				}
			}
			return $value;
		}

		$aliases = $schema['compatibility_aliases'] ?? array();
		if ( is_array( $aliases ) ) {
			foreach ( $aliases as $canonical => $paths ) {
				$canonical = (string) $canonical;
				if ( '' === $canonical || self::has_value_at_path( $value, $canonical ) ) {
					continue;
				}

				foreach ( (array) $paths as $path ) {
					if ( ! is_string( $path ) || '' === $path || ! self::has_value_at_path( $value, $path ) ) {
						continue;
					}

					self::set_value_at_path( $value, $canonical, self::get_value_at_path( $value, $path ) );
					if ( $path !== $canonical ) {
						self::unset_value_at_path( $value, $path );
					}
					break;
				}
			}
		}

		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		foreach ( $properties as $field => $property_schema ) {
			$field = (string) $field;
			if ( '' === $field || ! array_key_exists( $field, $value ) ) {
				continue;
			}

			$value[ $field ] = self::apply_contract_aliases(
				$value[ $field ],
				is_array( $property_schema ) ? $property_schema : array()
			);
		}

		return $value;
	}

	/**
	 * Coerce common legacy payload shapes onto the authoritative contract.
	 *
	 * This preserves compatibility for older callers that send JSON-encoded
	 * arrays/objects or boolean-like strings while still letting the runtime
	 * validate the canonical typed contract.
	 *
	 * @since 5.5.0
	 * @param mixed $value  Incoming value.
	 * @param array $schema Schema fragment.
	 * @return mixed
	 */
	private static function coerce_contract_value( mixed $value, array $schema ): mixed {
		$types = self::contract_schema_types( $schema );

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );

			if ( self::schema_accepts_contract_type( $types, 'boolean' ) && ! self::schema_accepts_contract_type( $types, 'string' ) ) {
				$bool = self::coerce_contract_boolean_string( $trimmed );
				if ( null !== $bool ) {
					$value = $bool;
				}
			}

			if ( self::schema_accepts_contract_type( $types, 'array' ) || self::schema_accepts_contract_type( $types, 'object' ) ) {
				$decoded = self::decode_contract_json_string( $trimmed );
				if ( is_array( $decoded ) ) {
					$value = $decoded;
				}
			}
		}

		if ( is_array( $value ) ) {
			$is_list = self::is_list_array( $value );

			if ( $is_list ) {
				if ( is_array( $schema['items'] ?? null ) ) {
					foreach ( $value as $index => $item ) {
						$value[ $index ] = self::coerce_contract_value( $item, $schema['items'] );
					}
				}

				return $value;
			}

			$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
			foreach ( $properties as $field => $property_schema ) {
				$field = (string) $field;
				if ( '' === $field || ! array_key_exists( $field, $value ) ) {
					continue;
				}

				$value[ $field ] = self::coerce_contract_value(
					$value[ $field ],
					is_array( $property_schema ) ? $property_schema : array()
				);
			}
		}

		return $value;
	}

	/**
	 * Normalize schema types into a flat string list.
	 *
	 * @since 5.5.0
	 * @param array $schema Schema fragment.
	 * @return string[]
	 */
	private static function contract_schema_types( array $schema ): array {
		$types = $schema['type'] ?? null;
		if ( null === $types ) {
			if ( isset( $schema['properties'] ) ) {
				return array( 'object' );
			}
			if ( isset( $schema['items'] ) ) {
				return array( 'array' );
			}
			return array();
		}

		$types = is_array( $types ) ? $types : array( $types );
		return array_values( array_filter( array_map( 'strval', $types ) ) );
	}

	/**
	 * Whether a schema accepts a specific type.
	 *
	 * @since 5.5.0
	 * @param string[] $types Allowed schema types.
	 * @param string   $type  Type to test.
	 */
	private static function schema_accepts_contract_type( array $types, string $type ): bool {
		return in_array( $type, $types, true );
	}

	/**
	 * Decode JSON strings used for legacy array/object payloads.
	 *
	 * @since 5.5.0
	 */
	private static function decode_contract_json_string( string $value ): mixed {
		if ( '' === $value ) {
			return null;
		}

		$first = substr( $value, 0, 1 );
		if ( ! in_array( $first, array( '{', '[' ), true ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	/**
	 * Coerce common boolean-like strings.
	 *
	 * @since 5.5.0
	 */
	private static function coerce_contract_boolean_string( string $value ): ?bool {
		$normalized = strtolower( $value );
		if ( in_array( $normalized, array( 'true', '1', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $normalized, array( 'false', '0', 'no' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Whether an array is a list rather than an associative object.
	 *
	 * @since 5.5.0
	 */
	private static function is_list_array( array $value ): bool {
		return array_values( $value ) === $value;
	}

	/**
	 * Validate tool input against the authoritative parameter contract.
	 *
	 * @since 5.5.0
	 * @param array $params Tool parameters.
	 * @return array{valid: bool, message?: string, params?: array}
	 */
	private function validate_parameter_contract( array $params ): array {
		if ( ! $this->has_parameter_contract() ) {
			return array(
				'valid'  => true,
				'params' => $params,
			);
		}

		$contract   = $this->parameter_contract;
		$properties = is_array( $contract['properties'] ?? null ) ? $contract['properties'] : array();
		$required   = array_values( array_filter( array_map( 'strval', (array) ( $contract['required'] ?? array() ) ) ) );

		$unknown_validation = self::validate_unknown_contract_fields( $params, $contract );
		if ( ! ( $unknown_validation['valid'] ?? false ) ) {
			return $unknown_validation;
		}

		foreach ( $required as $field ) {
			if ( self::has_value_at_path( $params, $field ) ) {
				continue;
			}

			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: %s: required field name */
					__( 'Missing required field: %s.', 'pressark' ),
					self::humanize_contract_path( $field )
				),
			);
		}

		foreach ( $properties as $field => $schema ) {
			if ( ! self::has_value_at_path( $params, (string) $field ) ) {
				continue;
			}

			$validation = self::validate_schema_node(
				self::get_value_at_path( $params, (string) $field ),
				is_array( $schema ) ? $schema : array(),
				(string) $field
			);

			if ( ! ( $validation['valid'] ?? false ) ) {
				return $validation;
			}
		}

		$group_validation = self::validate_contract_groups(
			$params,
			is_array( $contract['one_of'] ?? null ) ? $contract['one_of'] : array()
		);
		if ( ! ( $group_validation['valid'] ?? false ) ) {
			return $group_validation;
		}

		$dependency_validation = self::validate_contract_dependencies(
			$params,
			is_array( $contract['dependencies'] ?? null ) ? $contract['dependencies'] : array()
		);
		if ( ! ( $dependency_validation['valid'] ?? false ) ) {
			return $dependency_validation;
		}

		return array(
			'valid'  => true,
			'params' => $params,
		);
	}

	/**
	 * Validate a single schema node.
	 *
	 * @since 5.5.0
	 * @param mixed  $value Current value.
	 * @param array  $schema Schema fragment.
	 * @param string $path Dot-path for messages.
	 * @return array{valid: bool, message?: string}
	 */
	private static function validate_schema_node( mixed $value, array $schema, string $path ): array {
		$types = $schema['type'] ?? 'string';
		$types = is_array( $types ) ? array_values( $types ) : array( $types );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );

		if ( ! empty( $types ) && ! self::value_matches_any_type( $value, $types ) ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: 1: field name, 2: allowed type list */
					__( '%1$s must be %2$s.', 'pressark' ),
					self::humanize_contract_path( $path ),
					implode( ' or ', $types )
				),
			);
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: 1: field name, 2: allowed enum values */
					__( '%1$s must be one of: %2$s.', 'pressark' ),
					self::humanize_contract_path( $path ),
					implode( ', ', array_map( 'strval', $schema['enum'] ) )
				),
			);
		}

		if ( is_string( $value ) ) {
			$length = mb_strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: 1: field name, 2: minimum string length */
						__( '%1$s must be at least %2$d characters.', 'pressark' ),
						self::humanize_contract_path( $path ),
						(int) $schema['minLength']
					),
				);
			}
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: 1: field name, 2: maximum string length */
						__( '%1$s must be at most %2$d characters.', 'pressark' ),
						self::humanize_contract_path( $path ),
						(int) $schema['maxLength']
					),
				);
			}
			if ( ! empty( $schema['pattern'] ) && false === @preg_match( '/' . $schema['pattern'] . '/', $value ) ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: %s: field name */
						__( '%s has an invalid format.', 'pressark' ),
						self::humanize_contract_path( $path )
					),
				);
			}
			if ( 'uri' === ( $schema['format'] ?? '' ) && false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: %s: field name */
						__( '%s must be a valid URL.', 'pressark' ),
						self::humanize_contract_path( $path )
					),
				);
			}
		}

		if ( self::is_numeric_contract_value( $value ) ) {
			$numeric = self::coerce_numeric_contract_value( $value );
			if ( isset( $schema['minimum'] ) && $numeric < (float) $schema['minimum'] ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: 1: field name, 2: minimum numeric value */
						__( '%1$s must be at least %2$s.', 'pressark' ),
						self::humanize_contract_path( $path ),
						(string) $schema['minimum']
					),
				);
			}
			if ( isset( $schema['maximum'] ) && $numeric > (float) $schema['maximum'] ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: 1: field name, 2: maximum numeric value */
						__( '%1$s must be at most %2$s.', 'pressark' ),
						self::humanize_contract_path( $path ),
						(string) $schema['maximum']
					),
				);
			}
		}

		if ( is_array( $value ) ) {
			$is_list = array_values( $value ) === $value;

			if ( $is_list ) {
				$count = count( $value );
				if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
					return array(
						'valid'   => false,
						'message' => sprintf(
							/* translators: 1: field name, 2: minimum item count */
							__( '%1$s must include at least %2$d item(s).', 'pressark' ),
							self::humanize_contract_path( $path ),
							(int) $schema['minItems']
						),
					);
				}
				if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
					return array(
						'valid'   => false,
						'message' => sprintf(
							/* translators: 1: field name, 2: maximum item count */
							__( '%1$s must include at most %2$d item(s).', 'pressark' ),
							self::humanize_contract_path( $path ),
							(int) $schema['maxItems']
						),
					);
				}

				if ( is_array( $schema['items'] ?? null ) ) {
					foreach ( $value as $index => $item ) {
						$item_validation = self::validate_schema_node(
							$item,
							$schema['items'],
							$path . '.' . $index
						);
						if ( ! ( $item_validation['valid'] ?? false ) ) {
							return $item_validation;
						}
					}
				}
			} else {
				$required = array_values( array_filter( array_map( 'strval', (array) ( $schema['required'] ?? array() ) ) ) );
				foreach ( $required as $field ) {
					if ( self::has_value_at_path( $value, $field ) ) {
						continue;
					}

					return array(
						'valid'   => false,
						'message' => sprintf(
							/* translators: 1: object field, 2: nested required field */
							__( '%1$s requires %2$s.', 'pressark' ),
							self::humanize_contract_path( $path ),
							self::humanize_contract_path( $path . '.' . $field )
						),
					);
				}

				$property_count = count( $value );
				if ( isset( $schema['minProperties'] ) && $property_count < (int) $schema['minProperties'] ) {
					return array(
						'valid'   => false,
						'message' => sprintf(
							/* translators: 1: field name, 2: minimum property count */
							__( '%1$s must include at least %2$d field(s).', 'pressark' ),
							self::humanize_contract_path( $path ),
							(int) $schema['minProperties']
						),
					);
				}
				if ( isset( $schema['maxProperties'] ) && $property_count > (int) $schema['maxProperties'] ) {
					return array(
						'valid'   => false,
						'message' => sprintf(
							/* translators: 1: field name, 2: maximum property count */
							__( '%1$s must include at most %2$d field(s).', 'pressark' ),
							self::humanize_contract_path( $path ),
							(int) $schema['maxProperties']
						),
					);
				}

				$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
				$unknown_validation = self::validate_unknown_contract_fields( $value, $schema, $path );
				if ( ! ( $unknown_validation['valid'] ?? false ) ) {
					return $unknown_validation;
				}
				foreach ( $properties as $name => $property_schema ) {
					$name = (string) $name;
					if ( '' === $name || ! self::has_value_at_path( $value, $name ) ) {
						continue;
					}

					$property_validation = self::validate_schema_node(
						self::get_value_at_path( $value, $name ),
						is_array( $property_schema ) ? $property_schema : array(),
						$path . '.' . $name
					);
					if ( ! ( $property_validation['valid'] ?? false ) ) {
						return $property_validation;
					}
				}

				$group_validation = self::validate_contract_groups(
					$value,
					is_array( $schema['one_of'] ?? null ) ? $schema['one_of'] : array(),
					$path
				);
				if ( ! ( $group_validation['valid'] ?? false ) ) {
					return $group_validation;
				}

				$dependency_validation = self::validate_contract_dependencies(
					$value,
					is_array( $schema['dependencies'] ?? null ) ? $schema['dependencies'] : array(),
					$path
				);
				if ( ! ( $dependency_validation['valid'] ?? false ) ) {
					return $dependency_validation;
				}
			}
		}

		return array( 'valid' => true );
	}

	/**
	 * Enforce strict/additionalProperties=false object contracts.
	 *
	 * @since 5.5.0
	 * @param array  $value Current object value.
	 * @param array  $schema Schema fragment.
	 * @param string $path Parent path for messages.
	 * @return array{valid: bool, message?: string}
	 */
	private static function validate_unknown_contract_fields( array $value, array $schema, string $path = '' ): array {
		if ( ! self::contract_disallows_additional_properties( $schema ) ) {
			return array( 'valid' => true );
		}

		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		foreach ( $value as $field => $ignored ) {
			$field = (string) $field;
			if ( '' !== $field && array_key_exists( $field, $properties ) ) {
				continue;
			}

			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: 1: field name, 2: parent object label */
					__( 'Unexpected field %1$s in %2$s.', 'pressark' ),
					self::humanize_contract_path( self::join_contract_paths( $path, $field ) ),
					'' === $path ? __( 'tool input', 'pressark' ) : self::humanize_contract_path( $path )
				),
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Whether a schema node explicitly disallows unknown object properties.
	 *
	 * @since 5.5.0
	 */
	private static function contract_disallows_additional_properties( array $schema ): bool {
		if ( array_key_exists( 'strict', $schema ) ) {
			return ! empty( $schema['strict'] );
		}

		return array_key_exists( 'additionalProperties', $schema ) && false === $schema['additionalProperties'];
	}

	/**
	 * Validate one-of/mutual-exclusion rules for a contract node.
	 *
	 * @since 5.5.0
	 * @param array  $params    Current object value.
	 * @param array  $groups    One-of groups.
	 * @param string $base_path Parent path for error messages.
	 * @return array{valid: bool, message?: string}
	 */
	private static function validate_contract_groups( array $params, array $groups, string $base_path = '' ): array {
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$fields = array_values( array_filter( array_map( 'strval', (array) ( $group['fields'] ?? array() ) ) ) );
			if ( empty( $fields ) ) {
				continue;
			}

			$present = 0;
			foreach ( $fields as $field ) {
				if ( self::has_value_at_path( $params, $field ) ) {
					$present++;
				}
			}

			$mode = sanitize_key( (string) ( $group['mode'] ?? 'exactly_one' ) );
			$okay = match ( $mode ) {
				'at_least_one' => $present >= 1,
				'at_most_one', 'mutually_exclusive' => $present <= 1,
				default => 1 === $present,
			};

			if ( $okay ) {
				continue;
			}

			$message = sanitize_text_field( (string) ( $group['message'] ?? '' ) );
			if ( '' === $message ) {
				$display_fields = array_map(
					static fn( string $field ): string => self::humanize_contract_path( self::join_contract_paths( $base_path, $field ) ),
					$fields
				);
				$message = match ( $mode ) {
					'at_least_one' => sprintf(
						/* translators: %s: comma separated field names */
						__( 'Provide at least one of: %s.', 'pressark' ),
						implode( ', ', $display_fields )
					),
					'at_most_one', 'mutually_exclusive' => sprintf(
						/* translators: %s: comma separated field names */
						__( 'Do not combine these fields: %s.', 'pressark' ),
						implode( ', ', $display_fields )
					),
					default => sprintf(
						/* translators: %s: comma separated field names */
						__( 'Provide exactly one of: %s.', 'pressark' ),
						implode( ', ', $display_fields )
					),
				};
			}

			return array(
				'valid'   => false,
				'message' => $message,
			);
		}

		return array( 'valid' => true );
	}

	/**
	 * Validate dependency rules for a contract node.
	 *
	 * @since 5.5.0
	 * @param array  $params        Current object value.
	 * @param array  $dependencies  Dependency rules.
	 * @param string $base_path     Parent path for error messages.
	 * @return array{valid: bool, message?: string}
	 */
	private static function validate_contract_dependencies( array $params, array $dependencies, string $base_path = '' ): array {
		foreach ( $dependencies as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$field = (string) ( $rule['field'] ?? '' );
			if ( '' === $field || ! self::has_value_at_path( $params, $field ) ) {
				continue;
			}

			$value = self::get_value_at_path( $params, $field );
			$gate  = array_values( (array) ( $rule['values'] ?? array() ) );
			if ( ! empty( $gate ) && ! in_array( $value, $gate, true ) ) {
				continue;
			}

			foreach ( (array) ( $rule['requires'] ?? array() ) as $required_field ) {
				$required_field = (string) $required_field;
				if ( '' === $required_field || self::has_value_at_path( $params, $required_field ) ) {
					continue;
				}

				return array(
					'valid'   => false,
					'message' => sanitize_text_field( (string) ( $rule['message'] ?? '' ) )
						?: sprintf(
							/* translators: 1: field name, 2: required companion field */
							__( '%1$s requires %2$s.', 'pressark' ),
							self::humanize_contract_path( self::join_contract_paths( $base_path, $field ) ),
							self::humanize_contract_path( self::join_contract_paths( $base_path, $required_field ) )
						),
				);
			}

			$field_values = is_array( $rule['field_values'] ?? null ) ? $rule['field_values'] : array();
			foreach ( $field_values as $other_field => $allowed_values ) {
				$other_field = (string) $other_field;
				if ( '' === $other_field || ! self::has_value_at_path( $params, $other_field ) ) {
					return array(
						'valid'   => false,
						'message' => sanitize_text_field( (string) ( $rule['message'] ?? '' ) )
							?: sprintf(
								/* translators: 1: field name, 2: companion field */
								__( '%1$s requires %2$s.', 'pressark' ),
								self::humanize_contract_path( self::join_contract_paths( $base_path, $field ) ),
								self::humanize_contract_path( self::join_contract_paths( $base_path, $other_field ) )
							),
					);
				}

				if ( ! in_array( self::get_value_at_path( $params, $other_field ), array_values( (array) $allowed_values ), true ) ) {
					return array(
						'valid'   => false,
						'message' => sanitize_text_field( (string) ( $rule['message'] ?? '' ) )
							?: sprintf(
								/* translators: 1: field name, 2: companion field */
								__( '%1$s is incompatible with %2$s.', 'pressark' ),
								self::humanize_contract_path( self::join_contract_paths( $base_path, $field ) ),
								self::humanize_contract_path( self::join_contract_paths( $base_path, $other_field ) )
							),
					);
				}
			}
		}

		return array( 'valid' => true );
	}

	/**
	 * Join a parent contract path with a relative field path.
	 *
	 * @since 5.5.0
	 */
	private static function join_contract_paths( string $base_path, string $field ): string {
		if ( '' === $base_path ) {
			return $field;
		}
		if ( '' === $field ) {
			return $base_path;
		}

		return $base_path . '.' . $field;
	}

	/**
	 * Check whether a value matches any allowed contract type.
	 *
	 * @since 5.5.0
	 * @param mixed    $value Value to check.
	 * @param string[] $types Allowed types.
	 * @return bool
	 */
	private static function value_matches_any_type( mixed $value, array $types ): bool {
		foreach ( $types as $type ) {
			if ( self::value_matches_contract_type( $value, $type ) ) {
				return true;
			}
		}

		return empty( $types );
	}

	/**
	 * Check whether a value matches one contract type.
	 *
	 * @since 5.5.0
	 * @param mixed  $value Value to check.
	 * @param string $type Allowed type.
	 * @return bool
	 */
	private static function value_matches_contract_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'integer' => is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ),
			'number'  => self::is_numeric_contract_value( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_values( $value ) === $value,
			'object'  => is_array( $value ) && array_values( $value ) !== $value,
			'string'  => is_string( $value ),
			default   => true,
		};
	}

	/**
	 * Whether a value can be treated as numeric for contract checks.
	 *
	 * @since 5.5.0
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private static function is_numeric_contract_value( mixed $value ): bool {
		return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
	}

	/**
	 * Coerce a numeric contract value to float for min/max checks.
	 *
	 * @since 5.5.0
	 * @param mixed $value Value to coerce.
	 * @return float
	 */
	private static function coerce_numeric_contract_value( mixed $value ): float {
		return (float) $value;
	}

	/**
	 * Check whether a dot-path exists and contains a meaningful value.
	 *
	 * @since 5.5.0
	 * @param array  $data Source array.
	 * @param string $path Dot-path.
	 * @return bool
	 */
	private static function has_value_at_path( array $data, string $path ): bool {
		$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		if ( empty( $segments ) ) {
			return false;
		}

		$current = $data;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return false;
			}
			$current = $current[ $segment ];
		}

		if ( null === $current ) {
			return false;
		}
		if ( is_string( $current ) ) {
			return '' !== trim( $current );
		}
		if ( is_array( $current ) ) {
			return ! empty( $current );
		}

		return true;
	}

	/**
	 * Get a value from a dot-path.
	 *
	 * @since 5.5.0
	 * @param array  $data Source array.
	 * @param string $path Dot-path.
	 * @return mixed
	 */
	private static function get_value_at_path( array $data, string $path ): mixed {
		$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		$current  = $data;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Set a dot-path value.
	 *
	 * @since 5.5.0
	 * @param array  $data  Target array.
	 * @param string $path  Dot-path.
	 * @param mixed  $value Value to set.
	 */
	private static function set_value_at_path( array &$data, string $path, mixed $value ): void {
		$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		if ( empty( $segments ) ) {
			return;
		}

		$current =& $data;
		foreach ( $segments as $index => $segment ) {
			if ( $index === count( $segments ) - 1 ) {
				$current[ $segment ] = $value;
				return;
			}

			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				$current[ $segment ] = array();
			}

			$current =& $current[ $segment ];
		}
	}

	/**
	 * Remove a dot-path value after a compatibility alias has been normalized.
	 *
	 * @since 5.5.0
	 * @param array  $data Source array.
	 * @param string $path Dot-path to remove.
	 */
	private static function unset_value_at_path( array &$data, string $path ): void {
		$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		if ( empty( $segments ) ) {
			return;
		}

		$current =& $data;
		foreach ( $segments as $index => $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return;
			}

			if ( $index === count( $segments ) - 1 ) {
				unset( $current[ $segment ] );
				return;
			}

			$current =& $current[ $segment ];
		}
	}

	/**
	 * Human-readable field label for contract messages.
	 *
	 * @since 5.5.0
	 * @param string $path Dot-path field reference.
	 * @return string
	 */
	private static function humanize_contract_path( string $path ): string {
		return str_replace( '.', ' -> ', $path );
	}
}
