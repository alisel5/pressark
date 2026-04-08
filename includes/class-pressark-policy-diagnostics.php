<?php
/**
 * PressArk Policy Diagnostics.
 *
 * @package PressArk
 * @since   5.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Policy_Diagnostics {

	private const REPORT_VERSION        = 1;
	private const DEFAULT_LOOKBACK_DAYS = 14;
	private const DEFAULT_EVENT_LIMIT   = 600;

	/** @var array<string,bool> */
	private static array $surface_fingerprints = array();

	public function __construct() {
		add_action( 'pressark_operation_denied', array( __CLASS__, 'capture_policy_denial' ), 10, 2 );
	}

	/**
	 * Capture a policy-engine deny verdict unless it came from a visibility probe.
	 *
	 * @param array $verdict    Canonical deny verdict.
	 * @param array $op_context Policy evaluation context.
	 */
	public static function capture_policy_denial( array $verdict, array $op_context ): void {
		$purpose = sanitize_key( (string) ( $op_context['meta']['decision_purpose'] ?? '' ) );
		if ( in_array( $purpose, array( 'tool_surface', 'tool_discovery', 'tool_visibility', 'policy_diagnostics' ), true ) ) {
			return;
		}

		self::record_execution_denial( $verdict, $op_context );
	}

	/**
	 * Record a real execution denial for later admin diagnostics.
	 *
	 * @param array $decision Canonical permission decision.
	 * @param array $context  Optional operation context.
	 */
	public static function record_execution_denial( array $decision, array $context = array() ): void {
		if ( ! class_exists( 'PressArk_Activity_Trace' ) || ! class_exists( 'PressArk_Permission_Decision' ) ) {
			return;
		}

		$decision = PressArk_Permission_Decision::normalize( $decision );
		if ( PressArk_Permission_Decision::ALLOW === ( $decision['verdict'] ?? '' ) ) {
			return;
		}

		$op_context    = is_array( $context ) ? $context : array();
		$meta          = is_array( $op_context['meta'] ?? null ) ? $op_context['meta'] : array();
		$group         = (string) ( $decision['debug']['group'] ?? $op_context['group'] ?? '' );
		$capability    = (string) ( $decision['debug']['capability'] ?? $op_context['capability'] ?? '' );
		$risk          = (string) ( $decision['debug']['risk'] ?? $op_context['risk'] ?? '' );
		$reason_codes  = array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['visibility']['reason_codes'] ?? array() ) ) ) );
		$tier          = sanitize_key(
			(string) (
				$meta['tier']
				?? ( class_exists( 'PressArk_License' ) ? ( new PressArk_License() )->get_tier() : '' )
			)
		);
		$policy        = sanitize_key( (string) ( $meta['policy'] ?? '' ) );
		$summary_op    = (string) ( $decision['operation'] ?? $op_context['operation'] ?? '' );
		$summary_source = (string) ( $decision['source'] ?? '' );
		$reason        = 'denied';

		if ( ! empty( $reason_codes ) ) {
			$reason = (string) reset( $reason_codes );
		} elseif ( ! empty( $decision['entitlement']['checked'] ) && false === $decision['entitlement']['allowed'] ) {
			$reason = 'entitlement_denied';
		} elseif ( PressArk_Permission_Decision::ASK === ( $decision['verdict'] ?? '' ) && empty( $decision['approval']['available'] ) ) {
			$reason = 'approval_blocked';
		}

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'policy.denial',
				'phase'      => 'policy',
				'status'     => PressArk_Permission_Decision::ASK === ( $decision['verdict'] ?? '' ) ? 'blocked' : 'denied',
				'reason'     => $reason,
				'summary'    => sprintf(
					'Permission blocked %s via %s.',
					'' !== $summary_op ? $summary_op : 'an operation',
					'' !== $summary_source ? $summary_source : 'policy'
				),
				'payload'    => array(
					'operation'          => sanitize_key( $summary_op ),
					'context'            => sanitize_key( (string) ( $decision['context'] ?? $op_context['context'] ?? '' ) ),
					'tier'               => $tier,
					'policy'             => $policy,
					'group'              => sanitize_key( $group ),
					'capability'         => sanitize_key( $capability ),
					'risk'               => sanitize_key( $risk ),
					'verdict'            => sanitize_key( (string) ( $decision['verdict'] ?? '' ) ),
					'source'             => sanitize_key( (string) ( $decision['source'] ?? '' ) ),
					'reasons'            => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $decision['reasons'] ?? array() ) ) ) ),
					'reason_codes'       => $reason_codes,
					'approval_mode'      => sanitize_key( (string) ( $decision['approval']['mode'] ?? '' ) ),
					'approval_available' => ! empty( $decision['approval']['available'] ),
					'entitlement_basis'  => sanitize_key( (string) ( $decision['entitlement']['basis'] ?? '' ) ),
				),
			),
			array(
				'route' => sanitize_key( (string) ( PressArk_Activity_Trace::current_context()['route'] ?? '' ) ),
			)
		);
	}

	/**
	 * Record one hidden-tool surface snapshot for operator diagnostics.
	 *
	 * @param array $surface Snapshot from PressArk_Permission_Service::build_surface_snapshot().
	 * @param array $context Extra metadata such as strategy, tier, or policy.
	 */
	public static function record_tool_surface( array $surface, array $context = array() ): void {
		if ( ! class_exists( 'PressArk_Activity_Trace' ) ) {
			return;
		}

		$requested_groups = array_values( array_filter( array_map( 'sanitize_key', (array) ( $surface['requested_groups'] ?? array() ) ) ) );
		$visible_groups   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $surface['visible_groups'] ?? array() ) ) ) );
		$hidden_tools     = array_values( array_filter( array_map( 'sanitize_key', (array) ( $surface['hidden_tools'] ?? array() ) ) ) );
		$missing_groups   = array_values( array_diff( $requested_groups, $visible_groups ) );

		if ( empty( $hidden_tools ) && empty( $missing_groups ) ) {
			return;
		}

		$meta       = is_array( $context ) ? $context : array();
		$tier       = sanitize_key(
			(string) (
				$meta['tier']
				?? ( class_exists( 'PressArk_License' ) ? ( new PressArk_License() )->get_tier() : '' )
			)
		);
		$policy     = sanitize_key( (string) ( $meta['policy'] ?? '' ) );
		$strategy   = sanitize_key( (string) ( $meta['strategy'] ?? '' ) );
		$fingerprint = md5(
			wp_json_encode(
				array(
					'context'        => sanitize_key( (string) ( $surface['context'] ?? '' ) ),
					'tier'           => $tier,
					'policy'         => $policy,
					'strategy'       => $strategy,
					'requested'      => $requested_groups,
					'visible'        => $visible_groups,
					'hidden'         => $hidden_tools,
					'hidden_summary' => (array) ( $surface['hidden_summary'] ?? array() ),
				)
			)
		);

		if ( isset( self::$surface_fingerprints[ $fingerprint ] ) ) {
			return;
		}
		self::$surface_fingerprints[ $fingerprint ] = true;

		$reason = ! empty( $missing_groups ) ? 'requested_group_unreachable' : 'hidden_tool_surface';
		$status = ! empty( $missing_groups ) && count( $missing_groups ) === count( $requested_groups ) ? 'degraded' : 'observed';

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'policy.surface',
				'phase'      => 'policy',
				'status'     => $status,
				'reason'     => $reason,
				'summary'    => ! empty( $missing_groups )
					? 'Requested tool groups were filtered down to an unreachable surface.'
					: 'Hidden tools were removed from the model-visible surface.',
				'payload'    => array(
					'context'                     => sanitize_key( (string) ( $surface['context'] ?? '' ) ),
					'tier'                        => $tier,
					'policy'                      => $policy,
					'strategy'                    => $strategy,
					'requested_groups'            => $requested_groups,
					'visible_groups'              => $visible_groups,
					'missing_requested_groups'    => $missing_groups,
					'all_requested_groups_hidden' => ! empty( $requested_groups ) && empty( array_intersect( $requested_groups, $visible_groups ) ),
					'visible_tools'               => array_values( array_filter( array_map( 'sanitize_key', (array) ( $surface['visible_tools'] ?? array() ) ) ) ),
					'hidden_tools'                => $hidden_tools,
					'hidden_tool_count'           => count( $hidden_tools ),
					'hidden_summary'              => self::sanitize_count_map( (array) ( $surface['hidden_summary'] ?? array() ) ),
					'hidden_decisions'            => self::simplify_hidden_decisions( (array) ( $surface['hidden_decisions'] ?? array() ) ),
				),
			)
		);
	}

	/**
	 * Build a compact, safe harness visibility summary for prompts and chat UI.
	 *
	 * @param array $permission_surface Request-scoped permission surface.
	 * @param array $tool_state         Loader tool-state snapshot.
	 * @param array $context            Optional runtime context.
	 * @return array<string,mixed>
	 */
	public static function build_harness_visibility_summary(
		array $permission_surface,
		array $tool_state = array(),
		array $context = array()
	): array {
		$loaded_groups  = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) ( $context['loaded_groups'] ?? $tool_state['loaded_groups'] ?? array() )
					)
				)
			)
		);
		$blocked_groups = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) ( $tool_state['blocked_groups'] ?? $permission_surface['hidden_groups'] ?? array() )
					)
				)
			)
		);
		$hidden_reasons = array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return is_array( $row ) ? $row : null;
					},
					(array) (
						$permission_surface['hidden_reason_rows']
						?? ( class_exists( 'PressArk_Permission_Service' )
							? PressArk_Permission_Service::summarize_hidden_decisions( (array) ( $permission_surface['hidden_decisions'] ?? array() ) )
							: array()
						)
					)
				)
			)
		);
		$deferred_rows  = self::normalize_deferred_groups( (array) ( $context['deferred_groups'] ?? array() ) );
		$recovery_hints = array();

		foreach ( $hidden_reasons as $row ) {
			$hint = sanitize_text_field( (string) ( $row['hint'] ?? '' ) );
			if ( '' !== $hint ) {
				$recovery_hints[ $hint ] = true;
			}
		}
		foreach ( $deferred_rows as $row ) {
			$hint = sanitize_text_field( (string) ( $row['hint'] ?? '' ) );
			if ( '' !== $hint ) {
				$recovery_hints[ $hint ] = true;
			}
		}

		return array_filter(
			array(
				'loaded_groups'  => $loaded_groups,
				'deferred_groups'=> array_values(
					array_filter(
						array_map(
							static function ( array $row ): string {
								return sanitize_key( (string) ( $row['group'] ?? '' ) );
							},
							$deferred_rows
						)
					)
				),
				'deferred_rows'  => $deferred_rows,
				'blocked_groups' => $blocked_groups,
				'hidden_reasons' => $hidden_reasons,
				'recovery_hints' => array_slice( array_keys( $recovery_hints ), 0, 4 ),
			),
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	/**
	 * Build the latest admin-facing report from activity events and live rules.
	 *
	 * @param int $days Lookback window.
	 * @return array<string,mixed>
	 */
	public static function build_report( int $days = self::DEFAULT_LOOKBACK_DAYS ): array {
		$store = new PressArk_Activity_Event_Store();
		$days  = max( 1, $days );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * self::seconds_per_day() ) );

		$events = $store->query_recent(
			array(
				'event_types' => array(
					'policy.surface',
					'policy.denial',
					'tool.discovery',
				),
				'reasons'     => array(
					'hidden_tool_surface',
					'requested_group_unreachable',
					'denied',
					'entitlement_denied',
					'approval_blocked',
					'discover_no_hits',
					'discover_repeated_misfire',
				),
				'since'       => $since,
			),
			self::DEFAULT_EVENT_LIMIT
		);

		return self::build_report_from_events( $events, $days );
	}

	/**
	 * Build the report from decoded activity events.
	 *
	 * @param array<int,array<string,mixed>> $events Decoded activity events.
	 * @param int                            $days   Lookback window.
	 * @return array<string,mixed>
	 */
	public static function build_report_from_events( array $events, int $days = self::DEFAULT_LOOKBACK_DAYS ): array {
		$hidden_tools      = array();
		$denied_operations = array();
		$requested_groups  = array();
		$discovery_queries = array();
		$hidden_reasons    = array();
		$surface_events    = 0;
		$denial_events     = 0;
		$dead_ends         = 0;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_type = (string) ( $event['event_type'] ?? '' );
			$reason     = sanitize_key( (string) ( $event['reason'] ?? '' ) );
			$payload    = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();

			if ( 'policy.surface' === $event_type ) {
				++$surface_events;
				$context          = sanitize_key( (string) ( $payload['context'] ?? '' ) );
				$visible_groups   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['visible_groups'] ?? array() ) ) ) );
				$requested_list   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['requested_groups'] ?? array() ) ) ) );
				$hidden_summary   = self::sanitize_count_map( (array) ( $payload['hidden_summary'] ?? array() ) );
				$hidden_decisions = array();

				foreach ( (array) ( $payload['hidden_decisions'] ?? array() ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$hidden_decisions[ sanitize_key( (string) ( $row['tool'] ?? '' ) ) ] = $row;
				}

				foreach ( $hidden_summary as $code => $count ) {
					$hidden_reasons[ $code ] = (int) ( $hidden_reasons[ $code ] ?? 0 ) + (int) $count;
				}

				foreach ( $requested_list as $group ) {
					if ( ! isset( $requested_groups[ $group ] ) ) {
						$requested_groups[ $group ] = array(
							'group'           => $group,
							'requested_count' => 0,
							'visible_count'   => 0,
							'contexts'        => array(),
						);
					}
					++$requested_groups[ $group ]['requested_count'];
					$requested_groups[ $group ]['contexts'][ $context ] = true;
					if ( in_array( $group, $visible_groups, true ) ) {
						++$requested_groups[ $group ]['visible_count'];
					}
				}

				foreach ( array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['hidden_tools'] ?? array() ) ) ) ) as $tool_name ) {
					if ( ! isset( $hidden_tools[ $tool_name ] ) ) {
						$group = class_exists( 'PressArk_Operation_Registry' )
							? PressArk_Operation_Registry::get_group( $tool_name )
							: '';
						$hidden_tools[ $tool_name ] = array(
							'tool'     => $tool_name,
							'group'    => sanitize_key( $group ),
							'count'    => 0,
							'contexts' => array(),
							'reasons'  => array(),
						);
					}

					++$hidden_tools[ $tool_name ]['count'];
					$hidden_tools[ $tool_name ]['contexts'][ $context ] = true;

					$decision = $hidden_decisions[ $tool_name ] ?? array();
					foreach ( array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['reason_codes'] ?? array() ) ) ) ) as $code ) {
						$hidden_tools[ $tool_name ]['reasons'][ $code ] = (int) ( $hidden_tools[ $tool_name ]['reasons'][ $code ] ?? 0 ) + 1;
					}
				}

				continue;
			}

			if ( 'policy.denial' === $event_type ) {
				++$denial_events;
				$operation = sanitize_key( (string) ( $payload['operation'] ?? '' ) );
				if ( '' === $operation ) {
					continue;
				}

				if ( ! isset( $denied_operations[ $operation ] ) ) {
					$denied_operations[ $operation ] = array(
						'operation' => $operation,
						'group'     => sanitize_key( (string) ( $payload['group'] ?? '' ) ),
						'count'     => 0,
						'contexts'  => array(),
						'sources'   => array(),
						'reasons'   => array(),
					);
				}

				++$denied_operations[ $operation ]['count'];
				$denied_operations[ $operation ]['contexts'][ sanitize_key( (string) ( $payload['context'] ?? '' ) ) ] = true;
				$source = sanitize_key( (string) ( $payload['source'] ?? '' ) );
				if ( '' !== $source ) {
					$denied_operations[ $operation ]['sources'][ $source ] = (int) ( $denied_operations[ $operation ]['sources'][ $source ] ?? 0 ) + 1;
				}
				foreach ( array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['reason_codes'] ?? array( $reason ) ) ) ) ) as $code ) {
					$denied_operations[ $operation ]['reasons'][ $code ] = (int) ( $denied_operations[ $operation ]['reasons'][ $code ] ?? 0 ) + 1;
				}

				continue;
			}

			if ( 'tool.discovery' === $event_type && in_array( $reason, array( 'discover_no_hits', 'discover_repeated_misfire' ), true ) ) {
				$query = sanitize_text_field( (string) ( $payload['query'] ?? '' ) );
				if ( '' === $query ) {
					continue;
				}

				++$dead_ends;
				if ( ! isset( $discovery_queries[ $query ] ) ) {
					$discovery_queries[ $query ] = array(
						'query'              => $query,
						'count'              => 0,
						'reasons'            => array(),
						'requested_families' => array(),
					);
				}

				++$discovery_queries[ $query ]['count'];
				$discovery_queries[ $query ]['reasons'][ $reason ] = (int) ( $discovery_queries[ $query ]['reasons'][ $reason ] ?? 0 ) + 1;
				foreach ( array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['requested_families'] ?? array() ) ) ) ) as $family ) {
					$discovery_queries[ $query ]['requested_families'][ $family ] = true;
				}
			}
		}

		$requested_never_visible = array_values(
			array_filter(
				array_map(
					static function ( array $row ): array {
						$row['contexts'] = array_keys( $row['contexts'] );
						return $row;
					},
					$requested_groups
				),
				static function ( array $row ): bool {
					return $row['requested_count'] > 0 && 0 === (int) $row['visible_count'];
				}
			)
		);

		usort(
			$requested_never_visible,
			static function ( array $left, array $right ): int {
				return $right['requested_count'] <=> $left['requested_count'];
			}
		);

		$shadowed_rules = self::detect_shadowed_rules();
		$dead_groups    = self::detect_dead_group_combinations();

		return array(
			'contract'                       => 'policy_diagnostics_report',
			'version'                        => self::REPORT_VERSION,
			'generated_at'                   => current_time( 'mysql', true ),
			'lookback_days'                  => max( 1, $days ),
			'current_tier'                   => class_exists( 'PressArk_License' ) ? sanitize_key( ( new PressArk_License() )->get_tier() ) : '',
			'totals'                         => array(
				'surface_events'          => $surface_events,
				'denial_events'           => $denial_events,
				'discovery_dead_ends'     => $dead_ends,
				'requested_never_visible' => count( $requested_never_visible ),
				'shadowed_rules'          => count( $shadowed_rules ),
				'dead_group_combinations' => count( $dead_groups ),
			),
			'top_hidden_tools'               => self::finalize_tool_rows( $hidden_tools ),
			'top_denied_operations'          => self::finalize_operation_rows( $denied_operations ),
			'hidden_reason_summary'          => self::finalize_count_rows( $hidden_reasons ),
			'requested_never_visible_groups' => array_slice( $requested_never_visible, 0, 12 ),
			'discovery_dead_ends'            => self::finalize_discovery_rows( $discovery_queries ),
			'shadowed_rules'                 => $shadowed_rules,
			'dead_group_combinations'        => $dead_groups,
		);
	}

	/**
	 * Detect allow/ask rules that are unreachable under the current site policy.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect_shadowed_rules(): array {
		if ( ! class_exists( 'PressArk_Policy_Engine' ) || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return array();
		}

		$rules = PressArk_Policy_Engine::get_compiled_rules();
		if ( empty( $rules ) ) {
			return array();
		}

		$inspections = self::build_rule_inspection_matrix();
		if ( empty( $inspections ) ) {
			return array();
		}

		$shadowed = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$behavior = (string) ( $rule['behavior'] ?? '' );
			if ( ! in_array( $behavior, array( 'allow', 'ask' ), true ) ) {
				continue;
			}

			$matches    = 0;
			$reachable  = false;
			$blockers   = array();
			$operations = array();

			foreach ( $inspections as $inspection ) {
				if ( ! self::rule_present_in_match_bucket( $rule, $inspection['matched_rules'][ $behavior ] ?? array() ) ) {
					continue;
				}

				++$matches;
				$operations[ sanitize_key( (string) ( $inspection['operation'] ?? '' ) ) ] = true;

				$deny_matches = (array) ( $inspection['matched_rules']['deny'] ?? array() );
				if ( ! empty( $deny_matches ) ) {
					self::increment_shadow_blocker( $blockers, $deny_matches[0], 'deny' );
					continue;
				}

				if ( 'allow' === $behavior ) {
					$ask_matches = (array) ( $inspection['matched_rules']['ask'] ?? array() );
					if ( ! empty( $ask_matches ) ) {
						self::increment_shadow_blocker( $blockers, $ask_matches[0], 'ask' );
						continue;
					}
				}

				$reachable = true;
				break;
			}

			if ( 0 === $matches || $reachable || empty( $blockers ) ) {
				continue;
			}

			usort(
				$blockers,
				static function ( array $left, array $right ): int {
					return $right['count'] <=> $left['count'];
				}
			);

			$primary    = $blockers[0];
			$shadowed[] = array(
				'rule'                => $rule,
				'shadow_type'         => $primary['type'],
				'shadowed_by'         => $primary['rule'],
				'matched_scenarios'   => $matches,
				'affected_operations' => array_slice( array_keys( $operations ), 0, 6 ),
				'reason'              => self::build_shadow_reason( $rule, $primary['rule'], $primary['type'], $matches, count( $operations ) ),
				'fix'                 => self::build_fix_suggestion( $rule, $primary['rule'], $primary['type'] ),
			);
		}

		usort(
			$shadowed,
			static function ( array $left, array $right ): int {
				return (int) ( $right['matched_scenarios'] ?? 0 ) <=> (int) ( $left['matched_scenarios'] ?? 0 );
			}
		);

		return $shadowed;
	}

	/**
	 * Detect automation policies whose current tier makes entire groups invisible.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect_dead_group_combinations(): array {
		if ( ! class_exists( 'PressArk_Permission_Service' ) || ! class_exists( 'PressArk_Operation_Registry' ) || ! class_exists( 'PressArk_Policy_Engine' ) ) {
			return array();
		}

		$tier     = class_exists( 'PressArk_License' ) ? sanitize_key( ( new PressArk_License() )->get_tier() ) : 'free';
		$policies = class_exists( 'PressArk_Automation_Policy' ) && method_exists( 'PressArk_Automation_Policy', 'all_policies' )
			? PressArk_Automation_Policy::all_policies()
			: array( 'editorial', 'merchandising', 'full' );
		$rows     = array();

		foreach ( array_values( array_filter( array_map( 'sanitize_key', $policies ) ) ) as $policy ) {
			foreach ( PressArk_Operation_Registry::group_names() as $group ) {
				$group = sanitize_key( (string) $group );
				if ( '' === $group || 'discovery' === $group ) {
					continue;
				}

				$tool_names = PressArk_Operation_Registry::tool_names_for_group( $group );
				if ( empty( $tool_names ) ) {
					continue;
				}

				$visibility = PressArk_Permission_Service::evaluate_tool_set(
					$tool_names,
					PressArk_Policy_Engine::CONTEXT_AUTOMATION,
					array(
						'tier'             => $tier,
						'policy'           => $policy,
						'decision_purpose' => 'policy_diagnostics',
					)
				);

				if ( count( (array) ( $visibility['visible_tool_names'] ?? array() ) ) > 0 ) {
					continue;
				}

				$hidden_summary = self::sanitize_count_map( (array) ( $visibility['hidden_summary'] ?? array() ) );
				$rows[]         = array(
					'policy'         => $policy,
					'tier'           => $tier,
					'group'          => $group,
					'tool_count'     => count( $tool_names ),
					'primary_reason' => self::primary_key_for_counts( $hidden_summary ),
					'hidden_summary' => $hidden_summary,
				);
			}
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) ( $right['tool_count'] ?? 0 ) <=> (int) ( $left['tool_count'] ?? 0 );
			}
		);

		return array_slice( $rows, 0, 12 );
	}

	/**
	 * Build a deterministic inspection matrix for static rule diagnostics.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function build_rule_inspection_matrix(): array {
		$matrix = array();
		$tier   = class_exists( 'PressArk_License' ) ? sanitize_key( ( new PressArk_License() )->get_tier() ) : 'free';

		foreach ( PressArk_Operation_Registry::all() as $operation ) {
			if ( ! $operation instanceof PressArk_Operation ) {
				continue;
			}

			$matrix[] = self::inspect_rule_surface(
				$operation->name,
				PressArk_Policy_Engine::CONTEXT_INTERACTIVE,
				array( 'tier' => $tier )
			);

			$policies = class_exists( 'PressArk_Automation_Policy' ) && method_exists( 'PressArk_Automation_Policy', 'all_policies' )
				? PressArk_Automation_Policy::all_policies()
				: array( 'editorial', 'merchandising', 'full' );

			foreach ( $policies as $policy ) {
				$matrix[] = self::inspect_rule_surface(
					$operation->name,
					PressArk_Policy_Engine::CONTEXT_AUTOMATION,
					array(
						'tier'   => $tier,
						'policy' => sanitize_key( (string) $policy ),
					)
				);
			}
		}

		return array_values(
			array_filter(
				$matrix,
				static function ( $row ): bool {
					return is_array( $row ) && ! empty( $row );
				}
			)
		);
	}

	/**
	 * Inspect one operation/context surface without mutating execution state.
	 *
	 * @param string $operation_name Operation name.
	 * @param string $context        Execution context.
	 * @param array  $meta           Extra policy meta.
	 * @return array<string,mixed>
	 */
	private static function inspect_rule_surface( string $operation_name, string $context, array $meta ): array {
		$inspection = PressArk_Policy_Engine::inspect(
			$operation_name,
			array(),
			$context,
			array_merge(
				$meta,
				array(
					'decision_purpose' => 'policy_diagnostics',
				)
			)
		);

		$inspection['operation'] = $operation_name;
		$inspection['context']   = $context;
		$inspection['meta']      = $meta;

		return $inspection;
	}

	/**
	 * Increment the count for one shadowing blocker.
	 *
	 * @param array<int,array<string,mixed>> $blockers Existing blocker rows.
	 * @param array<string,mixed>            $rule     Shadowing rule.
	 * @param string                         $type     ask|deny.
	 */
	private static function increment_shadow_blocker( array &$blockers, array $rule, string $type ): void {
		$key = $type . ':' . (string) ( $rule['index'] ?? '' );

		foreach ( $blockers as &$existing ) {
			if ( $key === (string) ( $existing['key'] ?? '' ) ) {
				++$existing['count'];
				return;
			}
		}
		unset( $existing );

		$blockers[] = array(
			'key'   => $key,
			'count' => 1,
			'type'  => $type,
			'rule'  => $rule,
		);
	}

	/**
	 * Check whether the exported rule is present in a matched rule bucket.
	 *
	 * @param array $rule   Exported rule row.
	 * @param array $bucket Matched bucket rows.
	 * @return bool
	 */
	private static function rule_present_in_match_bucket( array $rule, array $bucket ): bool {
		$index = (int) ( $rule['index'] ?? -1 );
		foreach ( $bucket as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( (int) ( $candidate['index'] ?? -2 ) === $index ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce hidden decision payloads to admin-safe rows.
	 *
	 * @param array<string,mixed> $hidden_decisions Hidden decisions keyed by tool.
	 * @return array<int,array<string,mixed>>
	 */
	private static function simplify_hidden_decisions( array $hidden_decisions ): array {
		$rows = array();
		foreach ( $hidden_decisions as $tool_name => $decision ) {
			if ( ! is_array( $decision ) ) {
				continue;
			}

			$rows[] = array(
				'tool'         => sanitize_key( (string) $tool_name ),
				'group'        => sanitize_key( (string) ( $decision['debug']['group'] ?? '' ) ),
				'verdict'      => sanitize_key( (string) ( $decision['verdict'] ?? '' ) ),
				'source'       => sanitize_key( (string) ( $decision['source'] ?? '' ) ),
				'reason_codes' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['visibility']['reason_codes'] ?? array() ) ) ) ),
				'reasons'      => array_slice(
					array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $decision['reasons'] ?? array() ) ) ) ),
					0,
					3
				),
			);
		}

		return $rows;
	}

	/**
	 * Normalize deferred-group rows into a compact, hint-bearing summary.
	 *
	 * @param array $deferred_groups Raw deferred-group payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_deferred_groups( array $deferred_groups ): array {
		$rows = array();

		foreach ( $deferred_groups as $candidate ) {
			$group           = '';
			$tokens          = 0;
			$adjusted_tokens = 0;

			if ( is_array( $candidate ) ) {
				$group           = sanitize_key( (string) ( $candidate['group'] ?? $candidate['name'] ?? '' ) );
				$tokens          = max( 0, (int) ( $candidate['tokens'] ?? 0 ) );
				$adjusted_tokens = max( 0, (int) ( $candidate['adjusted_tokens'] ?? 0 ) );
			} elseif ( is_string( $candidate ) ) {
				$group = sanitize_key( $candidate );
			}

			if ( '' === $group ) {
				continue;
			}

			$rows[] = array_filter(
				array(
					'group'           => $group,
					'label'           => self::display_group_label( $group ),
					'summary'         => 'Deferred to keep this run compact.',
					'hint'            => 'Load ' . $group . ' if the next step needs that capability.',
					'tokens'          => $tokens > 0 ? $tokens : null,
					'adjusted_tokens' => $adjusted_tokens > 0 ? $adjusted_tokens : null,
				),
				static function ( $value ) {
					return null !== $value && '' !== (string) $value;
				}
			);
		}

		return $rows;
	}

	/**
	 * Sanitize a reason/count map for telemetry payloads.
	 *
	 * @param array<string,mixed> $counts Raw counts.
	 * @return array<string,int>
	 */
	private static function sanitize_count_map( array $counts ): array {
		$clean = array();
		foreach ( $counts as $key => $value ) {
			$name = sanitize_key( (string) $key );
			if ( '' === $name ) {
				continue;
			}
			$clean[ $name ] = (int) $value;
		}
		return $clean;
	}

	/**
	 * Convert hidden-tool rows into a sorted report list.
	 *
	 * @param array<string,array<string,mixed>> $rows Tool rows keyed by tool name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function finalize_tool_rows( array $rows ): array {
		$final = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['contexts']       = array_values( array_filter( array_keys( (array) ( $row['contexts'] ?? array() ) ) ) );
			$row['primary_reason'] = self::primary_key_for_counts( (array) ( $row['reasons'] ?? array() ) );
			$row['reason_summary'] = self::finalize_count_rows( (array) ( $row['reasons'] ?? array() ), 3 );
			unset( $row['reasons'] );
			$final[] = $row;
		}

		usort(
			$final,
			static function ( array $left, array $right ): int {
				return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
			}
		);

		return array_slice( $final, 0, 12 );
	}

	/**
	 * Convert denied-operation rows into a sorted report list.
	 *
	 * @param array<string,array<string,mixed>> $rows Operation rows keyed by operation name.
	 * @return array<int,array<string,mixed>>
	 */
	private static function finalize_operation_rows( array $rows ): array {
		$final = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['contexts']       = array_values( array_filter( array_keys( (array) ( $row['contexts'] ?? array() ) ) ) );
			$row['primary_reason'] = self::primary_key_for_counts( (array) ( $row['reasons'] ?? array() ) );
			$row['primary_source'] = self::primary_key_for_counts( (array) ( $row['sources'] ?? array() ) );
			unset( $row['reasons'], $row['sources'] );
			$final[] = $row;
		}

		usort(
			$final,
			static function ( array $left, array $right ): int {
				return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
			}
		);

		return array_slice( $final, 0, 12 );
	}

	/**
	 * Convert discovery dead-end rows into a sorted report list.
	 *
	 * @param array<string,array<string,mixed>> $rows Query rows keyed by query text.
	 * @return array<int,array<string,mixed>>
	 */
	private static function finalize_discovery_rows( array $rows ): array {
		$final = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['requested_families'] = array_values( array_filter( array_keys( (array) ( $row['requested_families'] ?? array() ) ) ) );
			$row['primary_reason']     = self::primary_key_for_counts( (array) ( $row['reasons'] ?? array() ) );
			unset( $row['reasons'] );
			$final[] = $row;
		}

		usort(
			$final,
			static function ( array $left, array $right ): int {
				return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
			}
		);

		return array_slice( $final, 0, 12 );
	}

	/**
	 * Convert a plain count map into sorted rows.
	 *
	 * @param array<string,int> $counts Count map.
	 * @param int               $limit  Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function finalize_count_rows( array $counts, int $limit = 12 ): array {
		$rows = array();
		foreach ( self::sanitize_count_map( $counts ) as $key => $count ) {
			$rows[] = array(
				'key'   => $key,
				'count' => (int) $count,
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
			}
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Pick the highest-frequency key from a count map.
	 *
	 * @param array<string,int> $counts Count map.
	 * @return string
	 */
	private static function primary_key_for_counts( array $counts ): string {
		$counts = self::sanitize_count_map( $counts );
		if ( empty( $counts ) ) {
			return '';
		}

		arsort( $counts );
		return (string) key( $counts );
	}

	/**
	 * Build a readable shadowing explanation.
	 *
	 * @param array  $rule           Shadowed rule.
	 * @param array  $shadowing_rule Blocking rule.
	 * @param string $shadow_type    ask|deny.
	 * @param int    $matches        Scenario count.
	 * @param int    $operations     Unique operation count.
	 * @return string
	 */
	private static function build_shadow_reason( array $rule, array $shadowing_rule, string $shadow_type, int $matches, int $operations ): string {
		return sprintf(
			'%s rule "%s" never wins: %s rule "%s" blocks all %d matched scenarios across %d operations.',
			strtoupper( (string) ( $rule['behavior'] ?? '' ) ),
			self::format_rule_label( $rule ),
			strtoupper( $shadow_type ),
			self::format_rule_label( $shadowing_rule ),
			$matches,
			$operations
		);
	}

	/**
	 * Build a human fix suggestion for one shadowed rule.
	 *
	 * @param array  $rule           Shadowed rule.
	 * @param array  $shadowing_rule Blocking rule.
	 * @param string $shadow_type    ask|deny.
	 * @return string
	 */
	private static function build_fix_suggestion( array $rule, array $shadowing_rule, string $shadow_type ): string {
		return sprintf(
			'Relax or narrow %s rule "%s" from %s, or remove the unreachable %s rule "%s".',
			$shadow_type,
			self::format_rule_label( $shadowing_rule ),
			(string) ( $shadowing_rule['source'] ?? 'custom' ),
			(string) ( $rule['behavior'] ?? 'allow' ),
			self::format_rule_label( $rule )
		);
	}

	/**
	 * Render one concise rule label.
	 *
	 * @param array $rule Exported rule.
	 * @return string
	 */
	private static function format_rule_label( array $rule ): string {
		$match = (string) ( $rule['match'] ?? '' );
		$value = (string) ( $rule['value'] ?? '' );

		if ( '' === $match ) {
			return $value;
		}

		return sprintf( '%s=%s', $match, '' !== $value ? $value : '{callable}' );
	}

	/**
	 * Return the per-day second count without assuming WP constants are loaded.
	 */
	private static function seconds_per_day(): int {
		return defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
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
}
