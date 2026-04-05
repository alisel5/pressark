<?php
/**
 * PressArk Policy Engine — WordPress-native policy hook layer.
 *
 * Provides a composable, plugin-extensible policy framework with allow/deny/ask
 * rule semantics, global pre/post-operation hooks, and structured verdicts.
 *
 * Inspired by Claude Code's permission model, adapted for PHP/WordPress:
 *   - Rules are registered via `pressark_policy_rules` filter
 *   - Evaluation is deny-first, fail-closed on ambiguous writes
 *   - Verdicts carry structured reasons for audit and UI
 *   - Global hooks fire for EVERY operation (not just per-operation config)
 *   - Compatible with existing approvals, automations, previews, confirms
 *
 * @package PressArk
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Policy_Engine {

	// ── Verdict behaviors ───────────────────────────────────────────

	/** Operation is explicitly allowed — skip interactive approval. */
	public const ALLOW = 'allow';

	/** Operation is explicitly denied — do not execute. */
	public const DENY = 'deny';

	/** Operation requires human confirmation before execution. */
	public const ASK = 'ask';

	// ── Rule match types ────────────────────────────────────────────

	/** Match a specific operation by name. */
	public const MATCH_OPERATION = 'operation';

	/** Match all operations in a group. */
	public const MATCH_GROUP = 'group';

	/** Match by capability class (read/preview/confirm). */
	public const MATCH_CAPABILITY = 'capability';

	/** Match by risk level (safe/moderate/destructive). */
	public const MATCH_RISK = 'risk';

	/** Match via a callable that receives operation context. */
	public const MATCH_CALLABLE = 'callable';

	// ── Execution contexts ──────────────────────────────────────────

	/** User-initiated interactive execution (browser chat). */
	public const CONTEXT_INTERACTIVE = 'interactive';

	/** Automation dispatcher (scheduled/unattended). */
	public const CONTEXT_AUTOMATION = 'automation';

	/** Agentic loop read-tool execution. */
	public const CONTEXT_AGENT_READ = 'agent_read';

	/** Preview-keep application. */
	public const CONTEXT_PREVIEW = 'preview';

	// ── Internals ───────────────────────────────────────────────────

	/** @var array[]|null Cached compiled rules (null = not yet loaded). */
	private static ?array $rules = null;

	/** @var bool Prevent recursive evaluation. */
	private static bool $evaluating = false;

	// ═══════════════════════════════════════════════════════════════
	//  PUBLIC API
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Evaluate policy for an operation before execution.
	 *
	 * Returns a structured verdict that callers use to decide whether to
	 * execute, block, or pause for human confirmation.
	 *
	 * @param string $operation_name Canonical operation name.
	 * @param array  $params         Operation arguments.
	 * @param string $context        Execution context (CONTEXT_* constant).
	 * @param array  $meta           Additional context (user_id, run_id, etc.).
	 * @return array Canonical permission decision with legacy behavior/source keys.
	 */
	public static function evaluate(
		string $operation_name,
		array $params = array(),
		string $context = self::CONTEXT_INTERACTIVE,
		array $meta = array()
	): array {
		// Guard against recursive policy evaluation (e.g., a filter
		// callback that triggers another operation).
		if ( self::$evaluating ) {
			return PressArk_Permission_Decision::with_visibility(
				self::verdict( self::ALLOW, 'Recursive policy evaluation — passthrough.', 'recursion_guard' ),
				true
			);
		}

		self::$evaluating = true;

		try {
			$operation = PressArk_Operation_Registry::resolve( $operation_name );

			// Build the operation context that rules and filters receive.
			$op_context = self::build_context( $operation_name, $operation, $params, $context, $meta );

			// ── Phase 1: Hard deny rules (evaluated first, deny wins). ──
			$deny_verdict = self::evaluate_rules( self::DENY, $op_context );
			if ( null !== $deny_verdict ) {
				$deny_verdict = self::finalize_verdict( $deny_verdict, $op_context );
				return $deny_verdict;
			}

			// ── Phase 2: Ask rules (force human confirmation). ──────────
			$ask_verdict = self::evaluate_rules( self::ASK, $op_context );

			// ── Phase 3: Allow rules (explicit green-light). ────────────
			$allow_verdict = self::evaluate_rules( self::ALLOW, $op_context );

			// ── Phase 4: Compose the final verdict. ─────────────────────
			$verdict = self::compose_verdict( $allow_verdict, $ask_verdict, $op_context );

			$verdict = self::finalize_verdict( $verdict, $op_context );

			return $verdict;

		} finally {
			self::$evaluating = false;
		}
	}

	/**
	 * Fire the global pre-operation hook.
	 *
	 * Called by Action Engine before dispatch. Returns potentially modified
	 * params (filters can transform input) and a go/no-go signal.
	 *
	 * @param string $operation_name Canonical operation name.
	 * @param array  $params         Operation arguments.
	 * @param string $context        Execution context.
	 * @return array{ proceed: bool, params: array, reason?: string }
	 */
	public static function pre_operation(
		string $operation_name,
		array $params,
		string $context = self::CONTEXT_INTERACTIVE
	): array {
		/**
		 * Fires before any PressArk operation executes.
		 *
		 * Returning a modified $params array transforms the input.
		 * Returning null or false blocks the operation.
		 *
		 * @since 5.4.0
		 *
		 * @param array  $params         Operation arguments.
		 * @param string $operation_name Canonical operation name.
		 * @param string $context        Execution context.
		 */
		$filtered = apply_filters( 'pressark_pre_operation', $params, $operation_name, $context );

		if ( null === $filtered || false === $filtered ) {
			return array(
				'proceed' => false,
				'params'  => $params,
				'reason'  => sprintf( 'Blocked by pressark_pre_operation filter for "%s".', $operation_name ),
			);
		}

		return array(
			'proceed' => true,
			'params'  => is_array( $filtered ) ? $filtered : $params,
		);
	}

	/**
	 * Fire the global post-operation hook.
	 *
	 * Called by Action Engine after dispatch. Returns potentially modified result.
	 *
	 * @param string $operation_name Canonical operation name.
	 * @param array  $result         Execution result.
	 * @param array  $params         Original operation arguments.
	 * @param string $context        Execution context.
	 * @return array Filtered result.
	 */
	public static function post_operation(
		string $operation_name,
		array $result,
		array $params,
		string $context = self::CONTEXT_INTERACTIVE
	): array {
		/**
		 * Fires after any PressArk operation executes.
		 *
		 * @since 5.4.0
		 *
		 * @param array  $result         Execution result.
		 * @param string $operation_name Canonical operation name.
		 * @param array  $params         Operation arguments.
		 * @param string $context        Execution context.
		 */
		$filtered = apply_filters( 'pressark_post_operation', $result, $operation_name, $params, $context );

		return is_array( $filtered ) ? $filtered : $result;
	}

	/**
	 * Register a policy rule at runtime.
	 *
	 * Convenience wrapper — most rules should be registered via the
	 * `pressark_policy_rules` filter, but this allows imperative registration
	 * for plugins that initialize late.
	 *
	 * @param array $rule Rule definition (see build_rule()).
	 */
	public static function add_rule( array $rule ): void {
		self::load_rules();
		$compiled = self::compile_rule( $rule );
		if ( null !== $compiled ) {
			self::$rules[] = $compiled;
			// Re-sort by priority.
			usort( self::$rules, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		}
	}

	/**
	 * Clear cached rules (useful for testing or dynamic reconfiguration).
	 */
	public static function flush_rules(): void {
		self::$rules = null;
	}

	/**
	 * Return the compiled rule set for operator diagnostics.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_compiled_rules(): array {
		self::load_rules();

		return array_values(
			array_map(
				static function ( array $rule, int $index ): array {
					return self::export_rule( $rule, $index );
				},
				self::$rules ?? array(),
				array_keys( self::$rules ?? array() )
			)
		);
	}

	/**
	 * Inspect which rules match one operation/context surface.
	 *
	 * Intended for admin diagnostics. This does not execute the operation.
	 *
	 * @param string $operation_name Canonical operation name.
	 * @param array  $params         Operation arguments.
	 * @param string $context        Execution context.
	 * @param array  $meta           Additional context.
	 * @return array<string,mixed>
	 */
	public static function inspect(
		string $operation_name,
		array $params = array(),
		string $context = self::CONTEXT_INTERACTIVE,
		array $meta = array()
	): array {
		self::load_rules();

		$operation  = PressArk_Operation_Registry::resolve( $operation_name );
		$op_context = self::build_context( $operation_name, $operation, $params, $context, $meta );
		$matched    = array(
			self::DENY  => array(),
			self::ASK   => array(),
			self::ALLOW => array(),
		);

		foreach ( self::$rules as $index => $rule ) {
			if ( ! empty( $rule['contexts'] ) && ! in_array( $op_context['context'], $rule['contexts'], true ) ) {
				continue;
			}

			if ( self::rule_matches( $rule, $op_context ) ) {
				$matched[ $rule['behavior'] ][] = self::export_rule( $rule, (int) $index );
			}
		}

		return array(
			'operation'     => $operation_name,
			'context'       => $context,
			'meta'          => $meta,
			'op_context'    => $op_context,
			'decision'      => self::evaluate( $operation_name, $params, $context, $meta ),
			'matched_rules' => $matched,
		);
	}

	// ═══════════════════════════════════════════════════════════════
	//  RULE LOADING & COMPILATION
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Load and compile rules from the filter.
	 */
	private static function load_rules(): void {
		if ( null !== self::$rules ) {
			return;
		}

		/**
		 * Collect policy rules from plugins and themes.
		 *
		 * Each rule is an associative array:
		 *   - behavior  (string, required): 'allow' | 'deny' | 'ask'
		 *   - match     (string, required): Match type (MATCH_* constant)
		 *   - value     (mixed, required):  Value to match against (operation name,
		 *                                    group name, capability, risk, or callable)
		 *   - priority  (int, optional):    Lower = evaluated first. Default 100.
		 *   - source    (string, optional): Identifier for who registered this rule.
		 *   - reason    (string, optional): Human-readable explanation.
		 *   - contexts  (array, optional):  Limit to specific execution contexts.
		 *                                    Empty = all contexts.
		 *
		 * @since 5.4.0
		 *
		 * @param array[] $rules Array of rule definitions.
		 */
		$raw_rules = apply_filters( 'pressark_policy_rules', array() );

		self::$rules = array();

		if ( ! is_array( $raw_rules ) ) {
			return;
		}

		foreach ( $raw_rules as $rule ) {
			$compiled = self::compile_rule( $rule );
			if ( null !== $compiled ) {
				self::$rules[] = $compiled;
			}
		}

		// Sort by priority (lower first).
		usort( self::$rules, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	}

	/**
	 * Validate and normalize a single rule definition.
	 *
	 * @param array $rule Raw rule definition.
	 * @return array|null Compiled rule, or null if invalid.
	 */
	private static function compile_rule( array $rule ): ?array {
		$behavior = $rule['behavior'] ?? '';
		if ( ! in_array( $behavior, array( self::ALLOW, self::DENY, self::ASK ), true ) ) {
			return null;
		}

		$match = $rule['match'] ?? '';
		$valid_matches = array(
			self::MATCH_OPERATION,
			self::MATCH_GROUP,
			self::MATCH_CAPABILITY,
			self::MATCH_RISK,
			self::MATCH_CALLABLE,
		);
		if ( ! in_array( $match, $valid_matches, true ) ) {
			return null;
		}

		$value = $rule['value'] ?? null;
		if ( null === $value ) {
			return null;
		}

		// Callable rules must actually be callable.
		if ( self::MATCH_CALLABLE === $match && ! is_callable( $value ) ) {
			return null;
		}

		$contexts = $rule['contexts'] ?? array();
		if ( ! is_array( $contexts ) ) {
			$contexts = array( $contexts );
		}

		return array(
			'behavior' => $behavior,
			'match'    => $match,
			'value'    => $value,
			'priority' => (int) ( $rule['priority'] ?? 100 ),
			'source'   => (string) ( $rule['source'] ?? 'custom' ),
			'reason'   => (string) ( $rule['reason'] ?? '' ),
			'contexts' => $contexts,
		);
	}

	/**
	 * Export one compiled rule into an admin-safe diagnostics row.
	 *
	 * @param array $rule  Compiled rule.
	 * @param int   $index Stable in-request index.
	 * @return array<string,mixed>
	 */
	private static function export_rule( array $rule, int $index ): array {
		$value = $rule['value'] ?? '';
		if ( is_callable( $value ) ) {
			$value = '{callable}';
		} elseif ( is_scalar( $value ) ) {
			$value = sanitize_text_field( (string) $value );
		} else {
			$value = sanitize_text_field( wp_json_encode( $value ) ?: '' );
		}

		return array(
			'index'    => $index,
			'behavior' => sanitize_key( (string) ( $rule['behavior'] ?? '' ) ),
			'match'    => sanitize_key( (string) ( $rule['match'] ?? '' ) ),
			'value'    => (string) $value,
			'priority' => (int) ( $rule['priority'] ?? 100 ),
			'source'   => sanitize_key( (string) ( $rule['source'] ?? '' ) ),
			'reason'   => sanitize_text_field( (string) ( $rule['reason'] ?? '' ) ),
			'contexts' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $rule['contexts'] ?? array() ) ) ) ),
		);
	}

	// ═══════════════════════════════════════════════════════════════
	//  RULE EVALUATION
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Build the operation context passed to rules and filters.
	 */
	private static function build_context(
		string $operation_name,
		?PressArk_Operation $operation,
		array $params,
		string $context,
		array $meta
	): array {
		$capability = 'unknown';
		$group      = '';
		$risk       = 'moderate';

		if ( $operation ) {
			$capability = $operation->capability;
			$group      = $operation->group;
			$risk       = $operation->risk ?? 'moderate';
		} elseif ( PressArk_Operation_Registry::exists( $operation_name ) ) {
			$capability = PressArk_Operation_Registry::classify( $operation_name, $params );
			$group      = PressArk_Operation_Registry::get_group( $operation_name );
		}

		return array(
			'operation'  => $operation_name,
			'params'     => $params,
			'context'    => $context,
			'capability' => $capability,
			'group'      => $group,
			'risk'       => $risk,
			'registered' => null !== $operation,
			'meta'       => $meta,
		);
	}

	/**
	 * Evaluate all rules of a specific behavior and return the first match.
	 *
	 * @param string $behavior Target behavior (ALLOW, DENY, ASK).
	 * @param array  $op_context Operation context.
	 * @return array|null Verdict array if a rule matched, null otherwise.
	 */
	private static function evaluate_rules( string $behavior, array $op_context ): ?array {
		self::load_rules();

		foreach ( self::$rules as $rule ) {
			if ( $rule['behavior'] !== $behavior ) {
				continue;
			}

			// Context filter: skip rules that don't apply to this context.
			if ( ! empty( $rule['contexts'] ) && ! in_array( $op_context['context'], $rule['contexts'], true ) ) {
				continue;
			}

			if ( self::rule_matches( $rule, $op_context ) ) {
				$reason = $rule['reason'] ?: sprintf(
					'Matched %s rule: %s=%s (source: %s)',
					$behavior,
					$rule['match'],
					is_callable( $rule['value'] ) ? '{callable}' : $rule['value'],
					$rule['source']
				);

				return self::verdict( $behavior, $reason, $rule['source'] );
			}
		}

		return null;
	}

	/**
	 * Test whether a single rule matches the operation context.
	 */
	private static function rule_matches( array $rule, array $op_context ): bool {
		switch ( $rule['match'] ) {
			case self::MATCH_OPERATION:
				// Support wildcard suffix: "wc_*" matches "wc_edit_product".
				$value = $rule['value'];
				if ( str_ends_with( $value, '*' ) ) {
					$prefix = substr( $value, 0, -1 );
					return str_starts_with( $op_context['operation'], $prefix );
				}
				return $op_context['operation'] === $value;

			case self::MATCH_GROUP:
				return $op_context['group'] === $rule['value'];

			case self::MATCH_CAPABILITY:
				return $op_context['capability'] === $rule['value'];

			case self::MATCH_RISK:
				return $op_context['risk'] === $rule['value'];

			case self::MATCH_CALLABLE:
				try {
					return (bool) call_user_func( $rule['value'], $op_context );
				} catch ( \Throwable $e ) {
					// Fail closed: callable errors → no match (rule is skipped).
					return false;
				}

			default:
				return false;
		}
	}

	// ═══════════════════════════════════════════════════════════════
	//  VERDICT COMPOSITION
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Compose the final verdict when no deny rule matched.
	 *
	 * Priority:
	 *   1. If an ask rule matched → ask (even if allow also matched).
	 *   2. If an allow rule matched → allow.
	 *   3. Fall back to default behavior based on context and operation metadata.
	 */
	private static function compose_verdict(
		?array $allow_verdict,
		?array $ask_verdict,
		array $op_context
	): array {
		// Ask takes precedence over allow.
		if ( null !== $ask_verdict ) {
			return $ask_verdict;
		}

		// Explicit allow.
		if ( null !== $allow_verdict ) {
			return $allow_verdict;
		}

		// ── No custom rules matched — apply defaults. ───────────────
		return self::default_verdict( $op_context );
	}

	/**
	 * Determine the default verdict when no custom rules apply.
	 *
	 * Preserves current PressArk behavior:
	 *   - Reads are always allowed.
	 *   - Unregistered operations are denied.
	 *   - In automation context, delegate to Automation_Policy.
	 *   - In interactive context, writes go to ask (the existing
	 *     preview/confirm flow handles this).
	 *   - Destructive operations always ask.
	 */
	private static function default_verdict( array $op_context ): array {
		// Reads are always safe.
		if ( 'read' === $op_context['capability'] ) {
			return self::verdict( self::ALLOW, 'Read operations are always allowed.', 'default' );
		}

		// Unregistered operations: fail closed.
		if ( ! $op_context['registered'] ) {
			return self::verdict(
				self::DENY,
				sprintf( 'Operation "%s" is not registered.', $op_context['operation'] ),
				'default'
			);
		}

		// Destructive operations always require human confirmation.
		if ( 'destructive' === $op_context['risk'] ) {
			return self::verdict(
				self::ASK,
				sprintf( 'Operation "%s" is destructive and requires confirmation.', $op_context['operation'] ),
				'default'
			);
		}

		// Automation context: delegate to existing Automation_Policy for
		// backward compatibility.
		if ( self::CONTEXT_AUTOMATION === $op_context['context'] ) {
			$policy = $op_context['meta']['policy'] ?? 'editorial';
			$check  = PressArk_Automation_Policy::check(
				$op_context['operation'],
				$policy,
				$op_context['params']
			);

			if ( $check['allowed'] ) {
				return PressArk_Permission_Decision::normalize(
					$check['permission_decision']
						?? self::verdict( self::ALLOW, 'Allowed by automation policy.', 'automation_policy' )
				);
			}

			return PressArk_Permission_Decision::normalize(
				$check['permission_decision']
					?? self::verdict(
						self::DENY,
						$check['reason'] ?? sprintf( 'Blocked by "%s" automation policy.', $policy ),
						'automation_policy'
					)
			);
		}

		// Interactive context: writes go through the existing preview/confirm
		// flow, which is effectively "ask". No change to current behavior.
		if ( 'confirm' === $op_context['capability'] || 'preview' === $op_context['capability'] ) {
			return self::verdict(
				self::ASK,
				'Write operations require approval in interactive mode.',
				'default'
			);
		}

		// Fail-closed fallback for anything we can't classify.
		return self::verdict(
			self::ASK,
			sprintf( 'Operation "%s" has ambiguous capability — requiring confirmation.', $op_context['operation'] ),
			'default'
		);
	}

	/**
	 * Run the verdict through the final filter and fire denial action.
	 */
	private static function finalize_verdict( array $verdict, array $op_context ): array {
		$verdict = PressArk_Permission_Decision::normalize(
			array_merge(
				$verdict,
				array(
					'operation' => $op_context['operation'],
					'context'   => $op_context['context'],
					'meta'      => $op_context['meta'],
					'debug'     => array_merge(
						(array) ( $verdict['debug'] ?? array() ),
						array(
							'registered' => ! empty( $op_context['registered'] ),
							'capability' => (string) ( $op_context['capability'] ?? '' ),
							'group'      => (string) ( $op_context['group'] ?? '' ),
							'risk'       => (string) ( $op_context['risk'] ?? '' ),
						)
					),
				)
			)
		);
		$verdict = self::attach_approval_semantics( $verdict, $op_context );

		/**
		 * Filter the policy verdict before it takes effect.
		 *
		 * Last chance for plugins to override a verdict. Use with care —
		 * this runs AFTER rule evaluation, so it can override deny rules.
		 * Implementations MUST NOT weaken deny verdicts for destructive
		 * operations unless they have a very good reason.
		 *
		 * @since 5.4.0
		 *
		 * @param array $verdict    The computed verdict.
		 * @param array $op_context Full operation context.
		 */
		$verdict = apply_filters( 'pressark_policy_verdict', $verdict, $op_context );
		$verdict = is_array( $verdict )
			? PressArk_Permission_Decision::normalize( $verdict )
			: $verdict;

		// Ensure the verdict is still valid after filtering.
		if ( ! is_array( $verdict ) || ! isset( $verdict['verdict'] ) ) {
			$verdict = PressArk_Permission_Decision::normalize(
				array_merge(
					self::verdict( self::DENY, 'Invalid verdict from pressark_policy_verdict filter — fail closed.', 'safety' ),
					array(
						'operation' => $op_context['operation'],
						'context'   => $op_context['context'],
						'meta'      => $op_context['meta'],
					)
				)
			);
			$verdict = self::attach_approval_semantics( $verdict, $op_context );
		}

		$verdict = self::attach_visibility_defaults( $verdict, $op_context );

		// Fire denial action for observability.
		if ( self::DENY === ( $verdict['verdict'] ?? '' ) ) {
			/**
			 * Fires when an operation is denied by policy.
			 *
			 * Useful for logging, alerting, or audit trails.
			 *
			 * @since 5.4.0
			 *
			 * @param array $verdict    The deny verdict.
			 * @param array $op_context Full operation context.
			 */
			do_action( 'pressark_operation_denied', $verdict, $op_context );
		}

		return $verdict;
	}

	// ═══════════════════════════════════════════════════════════════
	//  HELPERS
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Build a structured verdict array.
	 */
	private static function verdict( string $behavior, string $reason, string $source ): array {
		return PressArk_Permission_Decision::create(
			$behavior,
			$reason,
			$source,
			array(
				'provenance' => array(
					'authority' => 'policy_engine',
					'source'    => $source,
					'kind'      => 'policy',
				),
			)
		);
	}

	/**
	 * Attach approval semantics without changing existing verdict logic.
	 *
	 * @param array $verdict    Canonical permission decision.
	 * @param array $op_context Operation context.
	 * @return array
	 */
	private static function attach_approval_semantics( array $verdict, array $op_context ): array {
		$mode      = PressArk_Permission_Decision::APPROVAL_NONE;
		$required  = PressArk_Permission_Decision::is_ask( $verdict );
		$available = true;

		if ( $required ) {
			if ( self::CONTEXT_INTERACTIVE === $op_context['context'] ) {
				if ( 'preview' === ( $op_context['capability'] ?? '' ) ) {
					$mode = PressArk_Permission_Decision::APPROVAL_PREVIEW;
				} elseif ( 'confirm' === ( $op_context['capability'] ?? '' ) ) {
					$mode = PressArk_Permission_Decision::APPROVAL_CONFIRM;
				} else {
					$mode = PressArk_Permission_Decision::APPROVAL_HUMAN;
				}
			} else {
				$mode      = PressArk_Permission_Decision::APPROVAL_UNAVAILABLE;
				$available = false;
			}
		}

		return PressArk_Permission_Decision::with_approval( $verdict, $required, $mode, $available );
	}

	/**
	 * Attach default model visibility without leaking policy details.
	 *
	 * @param array $verdict    Canonical permission decision.
	 * @param array $op_context Operation context.
	 * @return array
	 */
	private static function attach_visibility_defaults( array $verdict, array $op_context ): array {
		$reason_codes = array();
		$visible      = true;

		if ( PressArk_Permission_Decision::is_denied( $verdict ) ) {
			$visible      = false;
			$reason_codes = array( 'denied' );
		} elseif ( PressArk_Permission_Decision::is_ask( $verdict ) && empty( $verdict['approval']['available'] ) ) {
			$visible      = false;
			$reason_codes = array( 'approval_blocked' );
		}

		return PressArk_Permission_Decision::with_visibility( $verdict, $visible, $reason_codes );
	}

	/**
	 * Check if a verdict allows execution.
	 *
	 * Convenience for callers that need a simple bool.
	 *
	 * @param array $verdict Result from evaluate().
	 * @return bool
	 */
	public static function is_allowed( array $verdict ): bool {
		return PressArk_Permission_Decision::is_allowed( $verdict );
	}

	/**
	 * Check if a verdict requires human confirmation.
	 *
	 * @param array $verdict Result from evaluate().
	 * @return bool
	 */
	public static function is_ask( array $verdict ): bool {
		return PressArk_Permission_Decision::is_ask( $verdict );
	}

	/**
	 * Check if a verdict denies execution.
	 *
	 * @param array $verdict Result from evaluate().
	 * @return bool
	 */
	public static function is_denied( array $verdict ): bool {
		return PressArk_Permission_Decision::is_denied( $verdict );
	}

	/**
	 * Get a human-readable summary of the verdict.
	 *
	 * @param array $verdict Result from evaluate().
	 * @return string
	 */
	public static function verdict_summary( array $verdict ): string {
		$normalized = PressArk_Permission_Decision::normalize( $verdict );
		$behavior  = strtoupper( $normalized['verdict'] ?? 'UNKNOWN' );
		$operation = $normalized['operation'] ?? 'unknown';
		$reasons   = implode( '; ', $normalized['reasons'] ?? array() );

		return sprintf( '[%s] %s — %s', $behavior, $operation, $reasons );
	}
}
