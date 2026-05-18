<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized harness-native capability and provider health graph.
 *
 * This is a read-only model that translates existing readiness, registry, and
 * integration signals into stable states that discovery, readiness, and admin
 * surfaces can consume without each one re-deriving its own health logic.
 */
class PressArk_Capability_Health {

	private static ?array $cached_snapshot = null;

	/**
	 * Build the current capability graph.
	 *
	 * @param array<string,mixed>|null $readiness_snapshot Optional readiness snapshot.
	 * @return array<string,mixed>
	 */
	public static function get_snapshot( ?array $readiness_snapshot = null ): array {
		if ( null === $readiness_snapshot && null !== self::$cached_snapshot ) {
			return self::$cached_snapshot;
		}

		if ( null === $readiness_snapshot ) {
			$readiness_snapshot = class_exists( 'PressArk_Harness_Readiness' )
				? PressArk_Harness_Readiness::get_snapshot( false )
				: array();
		}

		$facets          = (array) ( $readiness_snapshot['facets'] ?? array() );
		$tool_groups     = (array) ( $readiness_snapshot['tool_groups'] ?? array() );
		$resource_groups = class_exists( 'PressArk_Resource_Registry' )
			? PressArk_Resource_Registry::get_group_health()
			: array();

		$nodes = array(
			'bank'               => self::build_bank_node( (array) ( $facets['billing'] ?? array() ) ),
			'provider_transport' => self::build_provider_node( (array) ( $facets['provider'] ?? array() ) ),
			'site_profile'       => self::build_site_profile_node( (array) ( $facets['site_profile'] ?? array() ) ),
			'content_index'      => self::build_content_index_node( (array) ( $facets['content_index'] ?? array() ) ),
			'woocommerce'        => self::build_plugin_node(
				'woocommerce',
				'WooCommerce',
				class_exists( 'WooCommerce' ),
				'WooCommerce-backed tools and resources are available.',
				'WooCommerce is not active, so store-specific capability remains hidden.'
			),
			'elementor'          => self::build_plugin_node(
				'elementor',
				'Elementor',
				defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ),
				'Elementor-backed tools and resources are available.',
				'Elementor is not active, so builder-specific capability remains hidden.'
			),
			'seo_integrations'   => self::build_seo_node(),
		);

		$tool_group_nodes     = self::build_tool_group_nodes( $tool_groups );
		$resource_group_nodes = self::build_resource_group_nodes( $resource_groups );
		$hidden_tool_groups   = array_values(
			array_filter(
				$tool_group_nodes,
				static function ( array $row ): bool {
					return ! empty( $row['hidden'] );
				}
			)
		);
		$hidden_resource_groups = array_values(
			array_filter(
				$resource_group_nodes,
				static function ( array $row ): bool {
					return ! empty( $row['hidden'] );
				}
			)
		);

		$graph = array(
			'contract'    => 'CapabilityHealthGraph',
			'version'     => 1,
			'generated_at'=> gmdate( 'c' ),
			'state'       => self::overall_state( $nodes ),
			'nodes'       => $nodes,
			'tool_groups' => $tool_group_nodes,
			'resource_groups' => $resource_group_nodes,
			'hidden'      => array(
				'tool_groups'     => $hidden_tool_groups,
				'resource_groups' => $hidden_resource_groups,
			),
			'counts'      => self::count_states(
				array_merge(
					array_values( $nodes ),
					array_values( $tool_group_nodes ),
					array_values( $resource_group_nodes )
				)
			),
		);

		$graph['summary'] = self::overall_summary( $graph );
		$graph['notices'] = self::build_admin_notices( $graph );

		if ( null === $readiness_snapshot || ! empty( $readiness_snapshot ) ) {
			self::$cached_snapshot = $graph;
		}

		return $graph;
	}

	/**
	 * Get normalized health for one tool group.
	 *
	 * @param string                    $group Group name.
	 * @param array<string,mixed>|null  $graph Optional capability graph.
	 * @return array<string,mixed>
	 */
	public static function get_tool_group_state( string $group, ?array $graph = null ): array {
		$group = sanitize_key( $group );
		$graph = self::resolve_graph( $graph );

		return is_array( $graph['tool_groups'][ $group ] ?? null )
			? $graph['tool_groups'][ $group ]
			: self::surface_row(
				$group,
				ucwords( str_replace( array( '_', '-' ), ' ', $group ) ),
				'tool_group',
				'healthy',
				'available',
				'Tool group is available.',
				array(
					'available' => true,
					'hidden'    => false,
					'visible'   => true,
				)
			);
	}

	/**
	 * Get normalized health for one resource group.
	 *
	 * @param string                    $group Group name.
	 * @param array<string,mixed>|null  $graph Optional capability graph.
	 * @return array<string,mixed>
	 */
	public static function get_resource_group_state( string $group, ?array $graph = null ): array {
		$group = sanitize_key( $group );
		$graph = self::resolve_graph( $graph );

		return is_array( $graph['resource_groups'][ $group ] ?? null )
			? $graph['resource_groups'][ $group ]
			: self::surface_row(
				$group,
				ucwords( str_replace( array( '_', '-' ), ' ', $group ) ),
				'resource_group',
				'healthy',
				'available',
				'Resource group is available.',
				array(
					'available' => true,
					'hidden'    => false,
					'visible'   => true,
				)
			);
	}

	/**
	 * Score adjustment for discovery results based on health state.
	 *
	 * @param array<string,mixed> $surface Tool-group or resource-group health row.
	 * @param string              $kind    tool|resource
	 * @return int
	 */
	public static function discovery_score_adjustment( array $surface, string $kind = 'tool' ): int {
		$kind   = sanitize_key( $kind );
		$state  = sanitize_key( (string) ( $surface['state'] ?? 'healthy' ) );
		$hidden = ! empty( $surface['hidden'] );

		if ( $hidden ) {
			return 'resource' === $kind ? -26 : -22;
		}

		return match ( $state ) {
			'degraded'     => -8,
			'auth_blocked' => -18,
			'absent'       => -16,
			default        => 0,
		};
	}

	/**
	 * Convert graph state into concise admin notices.
	 *
	 * @param array<string,mixed>|null $graph Capability graph.
	 * @return array<int,array<string,string>>
	 */
	public static function collect_admin_notices( ?array $graph = null ): array {
		$graph = self::resolve_graph( $graph );

		return array_values(
			array_map(
				static function ( array $notice ): array {
					return array(
						'severity' => sanitize_key( (string) ( $notice['severity'] ?? 'warning' ) ),
						'title'    => sanitize_text_field( (string) ( $notice['title'] ?? '' ) ),
						'summary'  => sanitize_text_field( (string) ( $notice['summary'] ?? '' ) ),
					);
				},
				(array) ( $graph['notices'] ?? array() )
			)
		);
	}

	/**
	 * @param array<string,mixed>|null $graph Capability graph.
	 * @return array<string,mixed>
	 */
	private static function resolve_graph( ?array $graph ): array {
		return is_array( $graph ) && ! empty( $graph )
			? $graph
			: self::get_snapshot();
	}

	/**
	 * @param array<string,mixed> $billing
	 * @return array<string,mixed>
	 */
	private static function build_bank_node( array $billing ): array {
		$mode            = sanitize_key( (string) ( $billing['mode'] ?? '' ) );
		$service_state   = sanitize_key( (string) ( $billing['service_state'] ?? '' ) );
		$handshake_state = sanitize_key( (string) ( $billing['handshake_state'] ?? '' ) );
		$at_limit        = ! empty( $billing['at_limit'] );

		if ( 'byok' === $mode ) {
			return self::surface_row(
				'bank',
				'Bank',
				'provider',
				'healthy',
				'bypassed',
				'Bundled bank dependency is bypassed because BYOK mode is enabled.',
				array(
					'available' => true,
					'visible'   => true,
					'mode'      => 'byok',
				)
			);
		}

		$state   = 'healthy';
		$status  = 'reachable';
		$summary = 'Token bank is reachable and serving live bundled billing state.';

		if ( 'offline_assisted' === $service_state ) {
			$state   = 'degraded';
			$status  = 'offline_assisted';
			$summary = 'Token bank is offline-assisted and the harness is relying on cached bank truth.';
		} elseif ( 'degraded' === $service_state ) {
			$state   = 'degraded';
			$status  = 'degraded';
			$summary = 'Token bank reachability is degraded, so freshness may lag.';
		} elseif ( ! empty( $billing['issues'] ) && 'verified' !== $handshake_state && 'provisional' !== $handshake_state ) {
			$state   = 'auth_blocked';
			$status  = 'auth_blocked';
			$summary = 'Token bank handshake is incomplete, so bundled billing cannot fully initialize.';
		} elseif ( 'provisional' === $handshake_state ) {
			$state   = 'degraded';
			$status  = 'provisional';
			$summary = 'Token bank is reachable, but the handshake is still provisional.';
		}

		if ( $at_limit ) {
			$state   = 'degraded';
			$status  = 'limit_reached';
			$summary = 'Token bank is reachable, but bundled spend is exhausted.';
		}

		return self::surface_row(
			'bank',
			'Bank',
			'provider',
			$state,
			$status,
			$summary,
			array(
				'available'                 => ! in_array( $status, array( 'auth_blocked', 'limit_reached' ), true ),
				'visible'                   => true,
				'handshake_state'           => $handshake_state,
				'service_state'             => $service_state,
				'last_successful_contact_at'=> sanitize_text_field( (string) ( $billing['last_successful_contact_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $provider
	 * @return array<string,mixed>
	 */
	private static function build_provider_node( array $provider ): array {
		$mode    = sanitize_key( (string) ( $provider['mode'] ?? '' ) );
		$label   = 'proxy' === $mode ? 'Managed Proxy' : 'Provider Transport';
		$summary = 'proxy' === $mode
			? 'AI requests route through the managed PressArk transport.'
			: 'Provider transport is configured and usable.';
		$status  = 'proxy' === $mode ? 'managed' : 'configured';
		$state   = 'healthy';

		if ( 'blocked' === sanitize_key( (string) ( $provider['state'] ?? '' ) ) ) {
			$state   = 'auth_blocked';
			$status  = 'auth_blocked';
			$summary = sanitize_text_field( (string) ( $provider['summary'] ?? 'Provider credentials need attention.' ) );
		}

		return self::surface_row(
			'provider_transport',
			$label,
			'provider',
			$state,
			$status,
			$summary,
			array(
				'available'             => 'auth_blocked' !== $state,
				'visible'               => true,
				'mode'                  => $mode,
				'provider'              => sanitize_key( (string) ( $provider['provider'] ?? '' ) ),
				'model'                 => sanitize_text_field( (string) ( $provider['model'] ?? '' ) ),
				'supports_native_tools' => ! empty( $provider['supports_native_tools'] ),
				'supports_tool_search'  => ! empty( $provider['supports_tool_search'] ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $site_profile
	 * @return array<string,mixed>
	 */
	private static function build_site_profile_node( array $site_profile ): array {
		$exists        = ! empty( $site_profile['exists'] );
		$needs_refresh = ! empty( $site_profile['needs_refresh'] );

		if ( ! $exists ) {
			return self::surface_row(
				'site_profile',
				'Site Profile',
				'context',
				'absent',
				'missing',
				'Site profile is missing, so brand and context grounding are limited.',
				array(
					'available' => false,
					'visible'   => true,
				)
			);
		}

		if ( $needs_refresh || 'degraded' === sanitize_key( (string) ( $site_profile['state'] ?? '' ) ) ) {
			return self::surface_row(
				'site_profile',
				'Site Profile',
				'context',
				'degraded',
				'stale',
				'Site profile exists but is stale, so prompt guidance may lag behind the site.',
				array(
					'available'    => true,
					'visible'      => true,
					'generated_at' => sanitize_text_field( (string) ( $site_profile['generated_at'] ?? '' ) ),
				)
			);
		}

		return self::surface_row(
			'site_profile',
			'Site Profile',
			'context',
			'healthy',
			'fresh',
			'Site profile is fresh enough to ground prompt context.',
			array(
				'available'    => true,
				'visible'      => true,
				'generated_at' => sanitize_text_field( (string) ( $site_profile['generated_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $content_index
	 * @return array<string,mixed>
	 */
	private static function build_content_index_node( array $content_index ): array {
		$enabled      = ! empty( $content_index['enabled'] );
		$running      = ! empty( $content_index['running'] );
		$total_chunks = (int) ( $content_index['total_chunks'] ?? 0 );
		$stale_pct    = (float) ( $content_index['stale_percent'] ?? 0 );

		if ( ! $enabled || $total_chunks <= 0 ) {
			return self::surface_row(
				'content_index',
				'Content Index',
				'context',
				'absent',
				'unavailable',
				'Content index is unavailable, so retrieval grounding cannot contribute yet.',
				array(
					'available'    => false,
					'visible'      => true,
					'total_chunks' => $total_chunks,
				)
			);
		}

		if ( $running ) {
			return self::surface_row(
				'content_index',
				'Content Index',
				'context',
				'degraded',
				'rebuilding',
				'Content index is rebuilding, so freshness is temporarily degraded.',
				array(
					'available'    => true,
					'visible'      => true,
					'total_chunks' => $total_chunks,
				)
			);
		}

		if ( 'degraded' === sanitize_key( (string) ( $content_index['state'] ?? '' ) ) || $stale_pct >= 5.0 ) {
			return self::surface_row(
				'content_index',
				'Content Index',
				'context',
				'degraded',
				'stale',
				'Content index is available but stale enough to affect retrieval confidence.',
				array(
					'available'     => true,
					'visible'       => true,
					'total_chunks'  => $total_chunks,
					'stale_percent' => $stale_pct,
				)
			);
		}

		return self::surface_row(
			'content_index',
			'Content Index',
			'context',
			'healthy',
			'ready',
			'Content index is ready for retrieval grounding.',
			array(
				'available'     => true,
				'visible'       => true,
				'total_chunks'  => $total_chunks,
				'stale_percent' => $stale_pct,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_seo_node(): array {
		$provider = class_exists( 'PressArk_SEO_Resolver' )
			? (string) PressArk_SEO_Resolver::detect()
			: '';

		if ( '' === $provider ) {
			return self::surface_row(
				'seo_integrations',
				'SEO Integrations',
				'domain',
				'absent',
				'absent',
				'No supported SEO integration plugin is active. PressArk will rely on fallback SEO storage where needed.',
				array(
					'available' => false,
					'visible'   => true,
				)
			);
		}

		$provider_label = class_exists( 'PressArk_SEO_Resolver' )
			? PressArk_SEO_Resolver::label( $provider )
			: $provider;

		return self::surface_row(
			'seo_integrations',
			'SEO Integrations',
			'domain',
			'healthy',
			'available',
			sprintf( '%s integration is active for SEO reads and writes.', $provider_label ),
			array(
				'available' => true,
				'visible'   => true,
				'provider'  => sanitize_key( $provider ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_plugin_node(
		string $id,
		string $label,
		bool $available,
		string $healthy_summary,
		string $absent_summary
	): array {
		return self::surface_row(
			$id,
			$label,
			'domain',
			$available ? 'healthy' : 'absent',
			$available ? 'available' : 'absent',
			$available ? $healthy_summary : $absent_summary,
			array(
				'available' => $available,
				'visible'   => true,
			)
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $tool_groups
	 * @return array<string,array<string,mixed>>
	 */
	private static function build_tool_group_nodes( array $tool_groups ): array {
		$rows = array();

		foreach ( $tool_groups as $group => $row ) {
			$group = sanitize_key( (string) $group );
			$row   = is_array( $row ) ? $row : array();
			$label = ucwords( str_replace( array( '_', '-' ), ' ', $group ) );
			$state = 'healthy';
			$status = 'available';
			$hidden = false;
			$visible = true;
			$available = true;

			if ( ! empty( $row['requires'] ) && empty( $row['relevant'] ) && empty( $row['available'] ) ) {
				$state     = 'absent';
				$status    = 'hidden';
				$hidden    = true;
				$visible   = false;
				$available = false;
			} elseif ( 'blocked' === sanitize_key( (string) ( $row['state'] ?? '' ) ) ) {
				$state     = 'auth_blocked';
				$status    = 'blocked';
				$available = false;
			} elseif ( 'degraded' === sanitize_key( (string) ( $row['state'] ?? '' ) ) ) {
				$state = 'degraded';
				$status = 'degraded';
			}

			$rows[ $group ] = self::surface_row(
				$group,
				$label,
				'tool_group',
				$state,
				$status,
				(string) ( $row['summary'] ?? 'Tool group health is available.' ),
				array(
					'available'         => $available,
					'visible'           => $visible,
					'hidden'            => $hidden,
					'requires'          => array_values( array_map( 'sanitize_key', (array) ( $row['requires'] ?? array() ) ) ),
					'dependency_issues' => array_values( array_map( 'sanitize_text_field', (array) ( $row['dependency_issues'] ?? array() ) ) ),
					'tool_count'        => (int) ( $row['tool_count'] ?? 0 ),
				)
			);
		}

		ksort( $rows );
		return $rows;
	}

	/**
	 * @param array<string,array<string,mixed>> $resource_groups
	 * @return array<string,array<string,mixed>>
	 */
	private static function build_resource_group_nodes( array $resource_groups ): array {
		$rows = array();

		foreach ( $resource_groups as $group => $row ) {
			$group = sanitize_key( (string) $group );
			$row   = is_array( $row ) ? $row : array();
			$rows[ $group ] = self::surface_row(
				$group,
				sanitize_text_field( (string) ( $row['label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $group ) ) ) ),
				'resource_group',
				sanitize_key( (string) ( $row['state'] ?? 'healthy' ) ),
				sanitize_key( (string) ( $row['status'] ?? 'available' ) ),
				(string) ( $row['summary'] ?? 'Resource group health is available.' ),
				array(
					'available'    => ! empty( $row['available'] ),
					'visible'      => ! empty( $row['visible'] ),
					'hidden'       => ! empty( $row['hidden'] ),
					'visible_count'=> (int) ( $row['visible_count'] ?? 0 ),
					'hidden_count' => (int) ( $row['hidden_count'] ?? 0 ),
					'requires'     => array_values( array_map( 'sanitize_key', (array) ( $row['requires'] ?? array() ) ) ),
					'hidden_reasons' => array_values( array_map( 'sanitize_text_field', (array) ( $row['hidden_reasons'] ?? array() ) ) ),
				)
			);
		}

		ksort( $rows );
		return $rows;
	}

	/**
	 * @param array<string,mixed> $graph
	 * @return array<int,array<string,string>>
	 */
	private static function build_admin_notices( array $graph ): array {
		$notices = array();
		$nodes   = (array) ( $graph['nodes'] ?? array() );

		foreach ( array( 'provider_transport', 'bank', 'content_index', 'site_profile' ) as $node_id ) {
			$node = is_array( $nodes[ $node_id ] ?? null ) ? $nodes[ $node_id ] : array();
			$state  = sanitize_key( (string) ( $node['state'] ?? 'healthy' ) );
			$status = sanitize_key( (string) ( $node['status'] ?? '' ) );
			if ( in_array( $state, array( 'healthy' ), true ) ) {
				continue;
			}
			if ( ( 'bank' === $node_id && 'provisional' === $status ) || ( 'content_index' === $node_id && 'unavailable' === $status ) ) {
				continue;
			}

			$notices[] = array(
				'severity' => in_array( $state, array( 'auth_blocked' ), true ) ? 'error' : 'warning',
				'title'    => sanitize_text_field( (string) ( $node['label'] ?? ucfirst( $node_id ) ) ),
				'summary'  => sanitize_text_field( (string) ( $node['summary'] ?? '' ) ),
			);
		}

		return array_slice( $notices, 0, 5 );
	}

	/**
	 * @param array<string,array<string,mixed>> $nodes
	 * @return string
	 */
	private static function overall_state( array $nodes ): string {
		$core = array( 'provider_transport', 'bank', 'content_index', 'site_profile' );
		$state = 'healthy';

		foreach ( $core as $node_id ) {
			$node_state = sanitize_key( (string) ( $nodes[ $node_id ]['state'] ?? 'healthy' ) );
			if ( self::state_weight( $node_state ) > self::state_weight( $state ) ) {
				$state = $node_state;
			}
		}

		return $state;
	}

	/**
	 * @param array<string,mixed> $graph
	 * @return string
	 */
	private static function overall_summary( array $graph ): string {
		$state = sanitize_key( (string) ( $graph['state'] ?? 'healthy' ) );
		$nodes = (array) ( $graph['nodes'] ?? array() );
		$hidden_tool_groups = (array) ( $graph['hidden']['tool_groups'] ?? array() );
		$hidden_resource_groups = (array) ( $graph['hidden']['resource_groups'] ?? array() );

		if ( 'auth_blocked' === $state ) {
			foreach ( array( 'provider_transport', 'bank' ) as $node_id ) {
				$node = is_array( $nodes[ $node_id ] ?? null ) ? $nodes[ $node_id ] : array();
				if ( 'auth_blocked' === sanitize_key( (string) ( $node['state'] ?? '' ) ) ) {
					return sanitize_text_field( (string) ( $node['summary'] ?? 'Core AI capability is auth-blocked.' ) );
				}
			}

			return 'Core AI capability is blocked and needs operator attention.';
		}

		if ( 'degraded' === $state || 'absent' === $state ) {
			foreach ( array( 'bank', 'content_index', 'site_profile' ) as $node_id ) {
				$node = is_array( $nodes[ $node_id ] ?? null ) ? $nodes[ $node_id ] : array();
				if ( in_array( sanitize_key( (string) ( $node['state'] ?? '' ) ), array( 'degraded', 'absent' ), true ) ) {
					return sanitize_text_field( (string) ( $node['summary'] ?? 'Core capability is degraded.' ) );
				}
			}
		}

		if ( ! empty( $hidden_tool_groups ) || ! empty( $hidden_resource_groups ) ) {
			return 'Core capability is healthy. Some optional domains remain hidden until their prerequisites are available.';
		}

		return 'Core AI capability is healthy across bank, provider transport, profile grounding, and retrieval.';
	}

	/**
	 * @param array<int,array<string,mixed>> $surfaces
	 * @return array<string,int>
	 */
	private static function count_states( array $surfaces ): array {
		$counts = array(
			'healthy'      => 0,
			'degraded'     => 0,
			'absent'       => 0,
			'auth_blocked' => 0,
		);

		foreach ( $surfaces as $surface ) {
			$state = sanitize_key( (string) ( $surface['state'] ?? 'healthy' ) );
			if ( isset( $counts[ $state ] ) ) {
				++$counts[ $state ];
			}
		}

		return $counts;
	}

	/**
	 * @param string               $id
	 * @param string               $label
	 * @param string               $category
	 * @param string               $state
	 * @param string               $status
	 * @param string               $summary
	 * @param array<string,mixed>  $extra
	 * @return array<string,mixed>
	 */
	private static function surface_row(
		string $id,
		string $label,
		string $category,
		string $state,
		string $status,
		string $summary,
		array $extra = array()
	): array {
		return array_merge(
			array(
				'id'       => sanitize_key( $id ),
				'label'    => sanitize_text_field( $label ),
				'category' => sanitize_key( $category ),
				'state'    => sanitize_key( $state ),
				'status'   => sanitize_key( $status ),
				'summary'  => sanitize_text_field( $summary ),
			),
			$extra
		);
	}

	private static function state_weight( string $state ): int {
		return match ( sanitize_key( $state ) ) {
			'auth_blocked' => 4,
			'absent'       => 3,
			'degraded'     => 2,
			default        => 1,
		};
	}
}
