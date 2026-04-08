<?php
/**
 * PressArk Permission Service — unified permission and visibility evaluation.
 *
 * Bridges entitlements, policy, automation policy, and tool exposure so the
 * model only sees tools that are meaningful in the current execution context.
 *
 * @package PressArk
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Permission_Service {

	/** @var array<string, array> */
	private static array $decision_cache = array();

	/**
	 * Clear cached decisions for the current request.
	 */
	public static function flush_cache(): void {
		self::$decision_cache = array();
	}

	/**
	 * Evaluate the full permission decision for an operation.
	 *
	 * @param string $operation_name Operation name.
	 * @param array  $params         Operation arguments.
	 * @param string $context        Execution context.
	 * @param array  $meta           Extra context.
	 * @return array Canonical permission decision.
	 */
	public static function evaluate(
		string $operation_name,
		array $params = array(),
		string $context = '',
		array $meta = array()
	): array {
		$context = '' !== $context
			? $context
			: ( class_exists( 'PressArk_Policy_Engine' )
				? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
				: 'interactive' );

		$cache_key = md5(
			wp_json_encode(
				array(
					$operation_name,
					$params,
					$context,
					$meta,
				)
			)
		);

		if ( isset( self::$decision_cache[ $cache_key ] ) ) {
			return self::$decision_cache[ $cache_key ];
		}

		$canonical = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::resolve_alias( $operation_name )
			: $operation_name;
		$registered = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::exists( $canonical )
			: false;
		$group = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::get_group( $canonical )
			: '';
		$capability = class_exists( 'PressArk_Operation_Registry' )
			? PressArk_Operation_Registry::classify( $canonical, $params )
			: 'read';
		$risk = 'moderate';

		if ( class_exists( 'PressArk_Operation_Registry' ) ) {
			$operation = PressArk_Operation_Registry::resolve( $canonical );
			if ( $operation ) {
				$risk = $operation->risk ?? 'moderate';
			}
		}

		$tier = sanitize_key(
			(string) (
				$meta['tier']
				?? ( class_exists( 'PressArk_License' ) ? ( new PressArk_License() )->get_tier() : 'free' )
			)
		);

		$entitlement_check = self::entitlement_check( $tier, $group, $capability );
		if ( ! empty( $entitlement_check['checked'] ) && false === $entitlement_check['allowed'] ) {
			$decision = self::build_entitlement_denial(
				$canonical,
				$context,
				$meta,
				$group,
				$capability,
				$risk,
				$tier,
				$entitlement_check
			);
			self::$decision_cache[ $cache_key ] = $decision;
			return $decision;
		}

		if ( class_exists( 'PressArk_Policy_Engine' ) ) {
			$decision = PressArk_Policy_Engine::evaluate( $canonical, $params, $context, $meta );
		} else {
			$decision = PressArk_Permission_Decision::create(
				PressArk_Permission_Decision::ALLOW,
				'No policy engine registered. Defaulting to allow.',
				'permission_service',
				array(
					'operation' => $canonical,
					'context'   => $context,
				)
			);
		}

		$decision = PressArk_Permission_Decision::with_entitlement(
			$decision,
			array_merge(
				array(
					'checked'    => ! empty( $entitlement_check['checked'] ),
					'allowed'    => array_key_exists( 'allowed', $entitlement_check ) ? $entitlement_check['allowed'] : null,
					'basis'      => (string) ( $entitlement_check['basis'] ?? '' ),
					'tier'       => $tier,
					'group'      => $group,
					'capability' => $capability,
				),
				array_filter(
					array(
						'remaining' => isset( $entitlement_check['remaining'] ) ? (int) $entitlement_check['remaining'] : null,
						'limit'     => isset( $entitlement_check['limit'] ) ? (int) $entitlement_check['limit'] : null,
						'used'      => isset( $entitlement_check['used'] ) ? (int) $entitlement_check['used'] : null,
					),
					static fn( $value ) => null !== $value
				)
			)
		);

		$debug = is_array( $decision['debug'] ?? null ) ? $decision['debug'] : array();
		$debug['registered'] = $registered;
		$debug['group']      = $group;
		$debug['capability'] = $capability;
		$debug['risk']       = $risk;
		$decision['debug']   = $debug;

		$decision = self::attach_visibility( $decision, $context );
		$decision = PressArk_Permission_Decision::normalize( $decision );

		self::$decision_cache[ $cache_key ] = $decision;
		return $decision;
	}

	/**
	 * Apply the canonical execution gate used by the action engine.
	 *
	 * This keeps execution truth aligned with the same permission decision that
	 * tool visibility, preview/confirm surfaces, and diagnostics consume.
	 *
	 * @param string $operation_name Operation name.
	 * @param array  $params         Operation arguments.
	 * @param string $context        Execution context.
	 * @param array  $meta           Extra execution metadata.
	 * @return array{
	 *   allowed: bool,
	 *   context: string,
	 *   params: array,
	 *   permission_decision: array,
	 *   pre_operation: array
	 * }
	 */
	public static function gate_execution(
		string $operation_name,
		array $params = array(),
		string $context = '',
		array $meta = array()
	): array {
		$context = '' !== $context
			? $context
			: ( class_exists( 'PressArk_Policy_Engine' )
				? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
				: 'interactive' );

		$decision = self::evaluate( $operation_name, $params, $context, $meta );
		$gate     = array(
			'allowed'             => false,
			'context'             => $context,
			'params'              => $params,
			'permission_decision' => $decision,
			'pre_operation'       => array(
				'proceed' => true,
				'params'  => $params,
			),
		);

		$approval_granted = ! empty( $meta['approval_granted'] )
			&& ( ! class_exists( 'PressArk_Policy_Engine' ) || PressArk_Policy_Engine::CONTEXT_INTERACTIVE === $context );

		if ( PressArk_Permission_Decision::is_denied( $decision ) ) {
			return $gate;
		}

		if ( PressArk_Permission_Decision::is_ask( $decision ) && ! $approval_granted ) {
			return $gate;
		}

		if ( class_exists( 'PressArk_Policy_Engine' ) ) {
			$pre_check               = PressArk_Policy_Engine::pre_operation( $operation_name, $params, $context );
			$gate['pre_operation']   = $pre_check;
			$gate['params']          = is_array( $pre_check['params'] ?? null ) ? $pre_check['params'] : $params;

			if ( empty( $pre_check['proceed'] ) ) {
				return $gate;
			}
		}

		$gate['allowed'] = true;
		return $gate;
	}

	/**
	 * Filter a set of tool names down to the effective visible surface.
	 *
	 * @param string[] $tool_names Tool names to evaluate.
	 * @param string   $context    Execution context.
	 * @param array    $meta       Extra context.
	 * @return array
	 */
	public static function evaluate_tool_set( array $tool_names, string $context, array $meta = array() ): array {
		$visible         = array();
		$hidden          = array();
		$decisions       = array();
		$visible_groups  = array();
		$hidden_summary  = array();
		$normalized_names = array_values( array_unique( array_filter( array_map( 'sanitize_key', $tool_names ) ) ) );

		foreach ( $normalized_names as $tool_name ) {
			$decision = self::evaluate( $tool_name, array(), $context, $meta );
			$decisions[ $tool_name ] = $decision;

			if ( PressArk_Permission_Decision::is_visible_to_model( $decision ) ) {
				$visible[] = $tool_name;
				if ( class_exists( 'PressArk_Operation_Registry' ) ) {
					$group = PressArk_Operation_Registry::get_group( $tool_name );
					if ( '' !== $group ) {
						$visible_groups[ $group ] = true;
					}
				}
				continue;
			}

			$hidden[] = $tool_name;
			$codes    = (array) ( $decision['visibility']['reason_codes'] ?? array() );
			if ( empty( $codes ) ) {
				$codes = array( 'hidden' );
			}
			foreach ( $codes as $code ) {
				$hidden_summary[ $code ] = (int) ( $hidden_summary[ $code ] ?? 0 ) + 1;
			}
		}

		return array(
			'context'            => $context,
			'meta'               => $meta,
			'visible_tool_names' => $visible,
			'hidden_tool_names'  => $hidden,
			'visible_groups'     => array_keys( $visible_groups ),
			'decisions'          => $decisions,
			'hidden_summary'     => $hidden_summary,
		);
	}

	/**
	 * Build a compact operator-facing surface snapshot from visibility results.
	 *
	 * @param array    $visibility      Result from evaluate_tool_set().
	 * @param string[] $requested_groups Groups originally requested by the loader.
	 * @return array
	 */
	public static function build_surface_snapshot( array $visibility, array $requested_groups = array() ): array {
		$requested_groups = array_values( array_unique( array_filter( array_map( 'sanitize_key', $requested_groups ) ) ) );
		$visible_groups   = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $visibility['visible_groups'] ?? array() ) ) ) ) );
		$hidden_decisions = array();
		foreach ( (array) ( $visibility['hidden_tool_names'] ?? array() ) as $tool_name ) {
			if ( isset( $visibility['decisions'][ $tool_name ] ) ) {
				$hidden_decisions[ $tool_name ] = $visibility['decisions'][ $tool_name ];
			}
		}
		$hidden_reason_rows = self::summarize_hidden_decisions( $hidden_decisions );
		$hidden_reason_summary = array();
		foreach ( $hidden_reason_rows as $row ) {
			$kind = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			if ( '' === $kind ) {
				continue;
			}
			$hidden_reason_summary[ $kind ] = (int) ( $row['count'] ?? 0 );
		}

		return array(
			'contract'            => 'effective_visible_tools',
			'version'             => PressArk_Permission_Decision::VERSION,
			'context'             => (string) ( $visibility['context'] ?? '' ),
			'requested_groups'    => $requested_groups,
			'visible_groups'      => $visible_groups,
			'missing_requested_groups' => array_values( array_diff( $requested_groups, $visible_groups ) ),
			'all_requested_groups_hidden' => ! empty( $requested_groups ) && empty( array_intersect( $requested_groups, $visible_groups ) ),
			'visible_tools'       => array_values( array_filter( array_map( 'sanitize_key', (array) ( $visibility['visible_tool_names'] ?? array() ) ) ) ),
			'visible_tool_count'  => count( (array) ( $visibility['visible_tool_names'] ?? array() ) ),
			'hidden_tools'        => array_values( array_filter( array_map( 'sanitize_key', (array) ( $visibility['hidden_tool_names'] ?? array() ) ) ) ),
			'hidden_tool_count'   => count( (array) ( $visibility['hidden_tool_names'] ?? array() ) ),
			'hidden_groups'       => self::group_names_for_tools( (array) ( $visibility['hidden_tool_names'] ?? array() ) ),
			'hidden_summary'      => (array) ( $visibility['hidden_summary'] ?? array() ),
			'hidden_decisions'    => $hidden_decisions,
			'hidden_reason_rows'  => $hidden_reason_rows,
			'hidden_reason_summary' => $hidden_reason_summary,
		);
	}

	/**
	 * Build a compact, user-safe summary row for one hidden visibility decision.
	 *
	 * @param array  $decision  Canonical permission decision.
	 * @param string $tool_name Optional tool name.
	 * @return array<string,mixed>
	 */
	public static function summarize_visibility_decision( array $decision, string $tool_name = '' ): array {
		$decision     = PressArk_Permission_Decision::normalize( $decision );
		$tool_name    = sanitize_key( '' !== $tool_name ? $tool_name : (string) ( $decision['operation'] ?? '' ) );
		$group        = sanitize_key( (string) ( $decision['debug']['group'] ?? $decision['entitlement']['group'] ?? '' ) );
		$reason_codes = array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['visibility']['reason_codes'] ?? array() ) ) ) );
		$kind         = 'hidden';

		if ( ! empty( $decision['entitlement']['checked'] ) && false === $decision['entitlement']['allowed'] ) {
			$basis = sanitize_key( (string) ( $decision['entitlement']['basis'] ?? '' ) );
			if (
				in_array( $basis, array( 'group_limit_exhausted', 'weekly_remaining' ), true )
				|| null !== ( $decision['entitlement']['remaining'] ?? null )
				|| null !== ( $decision['entitlement']['limit'] ?? null )
			) {
				$kind = 'quota';
			} else {
				$kind = 'entitlement';
			}
		} elseif ( in_array( 'unregistered', $reason_codes, true ) || empty( $decision['debug']['registered'] ) ) {
			$kind = 'capability';
		} elseif (
			in_array( 'approval_blocked', $reason_codes, true )
			|| ( PressArk_Permission_Decision::is_ask( $decision ) && empty( $decision['approval']['available'] ) )
		) {
			$kind = 'approval';
		} elseif (
			in_array( 'policy_denied', $reason_codes, true )
			|| in_array( 'never_auto_approve', $reason_codes, true )
			|| 'policy' === sanitize_key( (string) ( $decision['provenance']['kind'] ?? '' ) )
			|| in_array( sanitize_key( (string) ( $decision['source'] ?? '' ) ), array( 'policy_engine', 'automation_policy' ), true )
			|| PressArk_Permission_Decision::is_denied( $decision )
		) {
			$kind = 'policy';
		}

		switch ( $kind ) {
			case 'quota':
				$label   = 'Quota reached';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Retry after the quota resets or upgrade the plan, then rerun.';
				break;
			case 'entitlement':
				$label   = 'Plan does not include it';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Upgrade the plan or use a tool group that is included.';
				break;
			case 'approval':
				$label   = 'Needs human approval';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Switch to an interactive run or use a confirmation-capable flow.';
				break;
			case 'capability':
				$label   = 'Capability unavailable';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Enable the needed integration or use a supported tool group before retrying.';
				break;
			case 'policy':
				$label   = 'Blocked by policy';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Use an allowed tool group or adjust policy and approval settings before retrying.';
				break;
			default:
				$label   = 'Hidden for this run';
				$summary = self::build_hidden_reason_summary( $kind, '' !== $group ? array( $group ) : array(), 1 );
				$hint    = 'Try another visible group or rerun with a context that allows it.';
				break;
		}

		return array_filter(
			array(
				'tool'         => $tool_name,
				'group'        => $group,
				'kind'         => $kind,
				'label'        => $label,
				'summary'      => $summary,
				'hint'         => $hint,
				'reason_codes' => $reason_codes,
			),
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	/**
	 * Aggregate hidden decisions into compact, reason-first summary rows.
	 *
	 * @param array<string,mixed> $hidden_decisions Hidden decisions keyed by tool.
	 * @return array<int,array<string,mixed>>
	 */
	public static function summarize_hidden_decisions( array $hidden_decisions ): array {
		$aggregated = array();

		foreach ( $hidden_decisions as $tool_name => $decision ) {
			if ( ! is_array( $decision ) ) {
				continue;
			}

			$row  = self::summarize_visibility_decision( $decision, (string) $tool_name );
			$kind = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			if ( '' === $kind ) {
				continue;
			}

			if ( ! isset( $aggregated[ $kind ] ) ) {
				$aggregated[ $kind ] = array(
					'kind'         => $kind,
					'label'        => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'summary'      => sanitize_text_field( (string) ( $row['summary'] ?? '' ) ),
					'hint'         => sanitize_text_field( (string) ( $row['hint'] ?? '' ) ),
					'count'        => 0,
					'groups'       => array(),
					'tools'        => array(),
					'reason_codes' => array(),
				);
			}

			++$aggregated[ $kind ]['count'];
			if ( ! empty( $row['group'] ) ) {
				$aggregated[ $kind ]['groups'][ sanitize_key( (string) $row['group'] ) ] = true;
			}
			if ( ! empty( $row['tool'] ) ) {
				$aggregated[ $kind ]['tools'][ sanitize_key( (string) $row['tool'] ) ] = true;
			}
			foreach ( (array) ( $row['reason_codes'] ?? array() ) as $reason_code ) {
				$reason_code = sanitize_key( (string) $reason_code );
				if ( '' !== $reason_code ) {
					$aggregated[ $kind ]['reason_codes'][ $reason_code ] = true;
				}
			}
		}

		foreach ( $aggregated as $kind => &$row ) {
			$row['groups']       = array_values( array_keys( (array) ( $row['groups'] ?? array() ) ) );
			$row['tools']        = array_values( array_keys( (array) ( $row['tools'] ?? array() ) ) );
			$row['reason_codes'] = array_values( array_keys( (array) ( $row['reason_codes'] ?? array() ) ) );
			$row['summary'] = self::build_hidden_reason_summary(
				$kind,
				(array) ( $row['groups'] ?? array() ),
				(int) ( $row['count'] ?? 0 )
			);
		}
		unset( $row );

		$rows = array_values( $aggregated );
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$priority_diff = self::visibility_reason_priority( (string) ( $left['kind'] ?? '' ) )
					<=> self::visibility_reason_priority( (string) ( $right['kind'] ?? '' ) );
				if ( 0 !== $priority_diff ) {
					return $priority_diff;
				}

				return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
			}
		);

		return $rows;
	}

	/**
	 * Filter discover_tools matches down to visible tools only.
	 *
	 * Resource matches are preserved as-is.
	 *
	 * @param array  $results Discovery matches.
	 * @param string $context Execution context.
	 * @param array  $meta    Extra context.
	 * @return array
	 */
	public static function filter_discovery_results( array $results, string $context, array $meta = array() ): array {
		if ( ! isset( $meta['decision_purpose'] ) ) {
			$meta['decision_purpose'] = 'tool_discovery';
		}

		$filtered = array();

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $result['name'] ?? '' ) );
			if ( '' === $tool_name || ! class_exists( 'PressArk_Operation_Registry' ) || ! PressArk_Operation_Registry::exists( $tool_name ) ) {
				$filtered[] = $result;
				continue;
			}

			$decision = self::evaluate( $tool_name, array(), $context, $meta );
			if ( PressArk_Permission_Decision::is_visible_to_model( $decision ) ) {
				$filtered[] = $result;
			}
		}

		return $filtered;
	}

	/**
	 * Build a permission decision for an entitlement denial while preserving
	 * the entitlement service's legacy response fields.
	 *
	 * @param string $operation Operation name.
	 * @param string $context   Execution context.
	 * @param array  $meta      Extra context.
	 * @param string $group     Tool group.
	 * @param string $capability Tool capability.
	 * @param string $risk      Tool risk.
	 * @param string $tier      User tier.
	 * @param array  $check     Entitlement check response.
	 * @return array
	 */
	public static function build_entitlement_denial(
		string $operation,
		string $context,
		array $meta,
		string $group,
		string $capability,
		string $risk,
		string $tier,
		array $check
	): array {
		$decision = PressArk_Permission_Decision::create(
			PressArk_Permission_Decision::DENY,
			(string) ( $check['message'] ?? $check['error'] ?? 'Blocked by entitlements.' ),
			'entitlements',
			array(
				'operation'   => $operation,
				'context'     => $context,
				'meta'        => $meta,
				'provenance'  => array(
					'authority' => 'entitlements',
					'source'    => 'group_usage',
					'kind'      => 'entitlement',
				),
				'entitlement' => array(
					'checked'    => true,
					'allowed'    => false,
					'basis'      => (string) ( $check['basis'] ?? 'group_limit_exhausted' ),
					'tier'       => $tier,
					'group'      => $group,
					'capability' => $capability,
					'remaining'  => isset( $check['remaining'] ) ? (int) $check['remaining'] : null,
					'limit'      => isset( $check['limit'] ) ? (int) $check['limit'] : null,
					'used'       => isset( $check['used'] ) ? (int) $check['used'] : null,
				),
				'debug'       => array(
					'registered' => class_exists( 'PressArk_Operation_Registry' ) ? PressArk_Operation_Registry::exists( $operation ) : false,
					'group'      => $group,
					'capability' => $capability,
					'risk'       => $risk,
				),
			)
		);

		return PressArk_Permission_Decision::with_visibility(
			$decision,
			false,
			array( 'entitlement_denied', 'denied' )
		);
	}

	/**
	 * Derive a simple entitlement check facet without changing the public API.
	 *
	 * @param string $tier       User tier.
	 * @param string $group      Tool group.
	 * @param string $capability Tool capability.
	 * @return array
	 */
	private static function entitlement_check( string $tier, string $group, string $capability ): array {
		if ( '' === $group || ! class_exists( 'PressArk_Entitlements' ) ) {
			return array(
				'checked' => false,
				'allowed' => null,
				'basis'   => '',
			);
		}

		$check = PressArk_Entitlements::check_group_usage( $tier, $group, $capability );
		$basis = '';

		if ( 'read' === $capability ) {
			$basis = 'read';
		} elseif ( method_exists( 'PressArk_Entitlements', 'is_paid_tier' ) && PressArk_Entitlements::is_paid_tier( $tier ) ) {
			$basis = 'paid_tier';
		} elseif ( defined( 'PressArk_Entitlements::UNLIMITED_GROUPS' ) && in_array( $group, PressArk_Entitlements::UNLIMITED_GROUPS, true ) ) {
			$basis = 'unlimited_group';
		} elseif ( ! empty( $check['allowed'] ) && isset( $check['remaining'] ) ) {
			$basis = 'weekly_remaining';
		} elseif ( empty( $check['allowed'] ) ) {
			$basis = 'group_limit_exhausted';
		}

		return array(
			'checked'   => true,
			'allowed'   => ! empty( $check['allowed'] ),
			'basis'     => $basis,
			'remaining' => isset( $check['remaining'] ) ? (int) $check['remaining'] : null,
			'limit'     => isset( $check['limit'] ) ? (int) $check['limit'] : null,
			'used'      => isset( $check['used'] ) ? (int) $check['used'] : null,
			'response'  => $check,
		);
	}

	/**
	 * Attach visibility semantics for a given execution context.
	 *
	 * @param array  $decision Decision array.
	 * @param string $context  Execution context.
	 * @return array
	 */
	private static function attach_visibility( array $decision, string $context ): array {
		$decision = PressArk_Permission_Decision::normalize( $decision );
		$codes    = array();
		$visible  = true;

		if ( PressArk_Permission_Decision::is_denied( $decision ) ) {
			$visible = false;
			$codes[] = 'denied';
		} elseif ( PressArk_Permission_Decision::is_ask( $decision ) && empty( $decision['approval']['available'] ) ) {
			$visible = false;
			$codes[] = 'approval_blocked';
		}

		if ( ! empty( $decision['entitlement']['checked'] ) && false === $decision['entitlement']['allowed'] ) {
			$visible = false;
			$codes[] = 'entitlement_denied';
		}

		if ( empty( $decision['debug']['registered'] ) && '' !== (string) ( $decision['operation'] ?? '' ) ) {
			$visible = false;
			$codes[] = 'unregistered';
		}

		if (
			in_array(
				$context,
				array(
					class_exists( 'PressArk_Policy_Engine' ) ? PressArk_Policy_Engine::CONTEXT_AGENT_READ : 'agent_read',
					class_exists( 'PressArk_Policy_Engine' ) ? PressArk_Policy_Engine::CONTEXT_AUTOMATION : 'automation',
					class_exists( 'PressArk_Policy_Engine' ) ? PressArk_Policy_Engine::CONTEXT_PREVIEW : 'preview',
				),
				true
			)
			&& PressArk_Permission_Decision::is_ask( $decision )
		) {
			$visible = false;
			$codes[] = 'approval_blocked';
		}

		return PressArk_Permission_Decision::with_visibility( $decision, $visible, $codes );
	}

	/**
	 * Derive unique groups for a set of tool names.
	 *
	 * @param array $tool_names Tool names.
	 * @return array<int,string>
	 */
	private static function group_names_for_tools( array $tool_names ): array {
		if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return array();
		}

		$groups = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_key', $tool_names ) ) ) ) as $tool_name ) {
			$group = sanitize_key( (string) PressArk_Operation_Registry::get_group( $tool_name ) );
			if ( '' !== $group ) {
				$groups[ $group ] = true;
			}
		}

		return array_values( array_keys( $groups ) );
	}

	/**
	 * Convert one or more groups into a compact scope label.
	 *
	 * @param array $groups      Group names.
	 * @param int   $tool_count  Tool count fallback.
	 * @return string
	 */
	private static function format_group_scope( array $groups, int $tool_count = 0 ): string {
		$groups = array_values( array_unique( array_filter( array_map( 'sanitize_key', $groups ) ) ) );
		if ( 1 === count( $groups ) ) {
			return self::display_group_label( (string) $groups[0] ) . ' tools';
		}
		if ( count( $groups ) > 1 ) {
			return count( $groups ) . ' tool groups';
		}
		if ( $tool_count > 1 ) {
			return $tool_count . ' tools';
		}

		return 'This capability';
	}

	/**
	 * Render a short, grammatical summary for a hidden-reason bucket.
	 *
	 * @param string $kind       Reason kind.
	 * @param array  $groups     Related groups.
	 * @param int    $tool_count Related tool count.
	 * @return string
	 */
	private static function build_hidden_reason_summary( string $kind, array $groups = array(), int $tool_count = 0 ): string {
		$scope       = self::format_group_scope( $groups, $tool_count );
		$is_singular = 'This capability' === $scope;

		return match ( sanitize_key( $kind ) ) {
			'quota'       => $is_singular
				? 'This capability stayed hidden because the current quota window is exhausted.'
				: sprintf( '%s stayed hidden because the current quota window is exhausted.', $scope ),
			'entitlement' => $is_singular
				? 'This capability is not included on the current plan.'
				: sprintf( '%s are not included on the current plan.', $scope ),
			'approval'    => $is_singular
				? 'This capability needs a human-approved route in this context.'
				: sprintf( '%s need a human-approved route in this context.', $scope ),
			'capability'  => $is_singular
				? 'This capability is not available on this site right now.'
				: sprintf( '%s are not available on this site right now.', $scope ),
			'policy'      => $is_singular
				? 'This capability was hidden by the current policy for this run.'
				: sprintf( '%s were hidden by the current policy for this run.', $scope ),
			default       => $is_singular
				? 'This capability stayed hidden for the current run.'
				: sprintf( '%s stayed hidden for the current run.', $scope ),
		};
	}

	/**
	 * Render a compact display label for a tool group.
	 *
	 * @param string $group Group name.
	 * @return string
	 */
	private static function display_group_label( string $group ): string {
		$group = sanitize_key( $group );
		return '' === $group ? 'Tool' : ucwords( str_replace( '_', ' ', $group ) );
	}

	/**
	 * Sort hidden-reason rows by how actionable they are to the user.
	 *
	 * @param string $kind Reason kind.
	 * @return int
	 */
	private static function visibility_reason_priority( string $kind ): int {
		return match ( sanitize_key( $kind ) ) {
			'quota'       => 10,
			'entitlement' => 20,
			'approval'    => 30,
			'policy'      => 40,
			'capability'  => 50,
			default       => 90,
		};
	}
}
