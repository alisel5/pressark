<?php
/**
 * Compact typed metadata for reusable reads.
 *
 * @package PressArk
 * @since   5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Read_Metadata {

	private const MAX_SNAPSHOTS      = 18;
	private const MAX_SECTION_LINES  = 4;
	private const MAX_PROVENANCE     = 6;
	private const TRUST_ORDER        = array(
		'trusted_system'   => 0,
		'verified_evidence'=> 1,
		'derived_summary'  => 2,
		'untrusted_content'=> 3,
	);
	private const CONTENT_TOOLS      = array(
		'read_content',
		'read_blocks',
		'get_templates',
		'elementor_read_page',
		'get_product',
		'get_order',
		'get_media',
		'read_log',
	);
	private const DERIVED_TOOLS      = array(
		'search_content',
		'list_posts',
		'search_knowledge',
		'analyze_seo',
		'scan_security',
		'site_health',
		'page_audit',
		'customer_insights',
		'inventory_report',
		'revenue_report',
		'sales_summary',
	);
	private const TRUSTED_SYSTEM_TOOLS = array(
		'get_site_overview',
		'get_site_map',
		'get_site_settings',
		'get_theme_settings',
		'get_customizer_schema',
		'get_design_system',
		'elementor_get_styles',
		'list_plugins',
		'list_themes',
		'discover_rest_routes',
		'list_resources',
		'read_resource',
		'index_status',
	);

	public static function annotate_tool_result( string $tool_name, array $args, array $result, array $options = array() ): array {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$result['read_meta'] = self::snapshot_from_tool_result( $tool_name, $args, $result, $options );
		return $result;
	}

	public static function annotate_resource_result( string $uri, array $definition, array $result ): array {
		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$cached            = ! empty( $result['cached'] );
		$stored_at         = sanitize_text_field( (string) ( $result['stored_at'] ?? '' ) );
		$trust_class       = sanitize_key( (string) ( $definition['trust_class'] ?? self::resource_trust_class( $definition ) ) );
		$provider          = sanitize_key( (string) ( $definition['provider'] ?? 'resource_registry' ) );
		$query_fingerprint = self::build_query_fingerprint( 'resource', $uri, array() );
		$meta              = array(
			'handle'            => $query_fingerprint,
			'query_fingerprint' => $query_fingerprint,
			'kind'              => 'resource',
			'resource_uri'      => $uri,
			'resource_group'    => sanitize_key( (string) ( $definition['group'] ?? '' ) ),
			'freshness'         => $cached ? 'cached' : 'fresh',
			'completeness'      => 'complete',
			'trust_class'       => $trust_class ?: 'trusted_system',
			'provider'          => $provider,
			'summary'           => sanitize_text_field( (string) ( $definition['name'] ?? $uri ) ),
			'captured_at'       => '' !== $stored_at ? $stored_at : gmdate( 'c' ),
			'provenance'        => array_filter( array(
				'group'     => sanitize_key( (string) ( $definition['group'] ?? '' ) ),
				'mime_type' => sanitize_text_field( (string) ( $definition['mime_type'] ?? '' ) ),
				'cached'    => $cached ? 'yes' : 'no',
				'stored_at' => $stored_at,
			) ),
			'resource_uris'     => array( $uri ),
			'resource_groups'   => array_filter( array( sanitize_key( (string) ( $definition['group'] ?? '' ) ) ) ),
		);

		$result['meta'] = self::sanitize_snapshot( $meta );
		return $result;
	}

	public static function snapshot_from_tool_result( string $tool_name, array $args, array $result, array $options = array() ): array {
		$existing = self::sanitize_snapshot( $result['read_meta'] ?? array() );
		if ( ! empty( $existing['handle'] ) ) {
			if ( ! empty( $options['freshness'] ) ) {
				$existing['freshness'] = sanitize_key( (string) $options['freshness'] );
			}
			if ( ! empty( $options['completeness'] ) ) {
				$existing['completeness'] = sanitize_key( (string) $options['completeness'] );
			}
			if ( ! empty( $options['captured_at'] ) ) {
				$existing['captured_at'] = sanitize_text_field( (string) $options['captured_at'] );
			}
			if ( ! empty( $options['provider'] ) ) {
				$existing['provider'] = sanitize_key( (string) $options['provider'] );
			}
			if ( ! empty( $options['stored_at'] ) ) {
				$existing['provenance']['stored_at'] = sanitize_text_field( (string) $options['stored_at'] );
			}
			return self::sanitize_snapshot( $existing );
		}

		$query_fingerprint = self::build_query_fingerprint( 'tool', $tool_name, $args );
		$targets           = self::extract_targets( $tool_name, $args, $result );
		$summary           = self::build_summary( $tool_name, $args, $result );
		$captured_at       = sanitize_text_field( (string) ( $options['captured_at'] ?? gmdate( 'c' ) ) );
		$meta              = array(
			'handle'            => $query_fingerprint,
			'query_fingerprint' => $query_fingerprint,
			'kind'              => 'tool',
			'tool_name'         => sanitize_key( $tool_name ),
			'freshness'         => sanitize_key( (string) ( $options['freshness'] ?? self::infer_freshness( $tool_name, $result ) ) ),
			'completeness'      => sanitize_key( (string) ( $options['completeness'] ?? self::infer_completeness( $tool_name, $args, $result ) ) ),
			'trust_class'       => sanitize_key( (string) ( $options['trust_class'] ?? self::infer_trust_class( $tool_name ) ) ),
			'provider'          => sanitize_key( (string) ( $options['provider'] ?? 'tool_result' ) ),
			'summary'           => $summary,
			'captured_at'       => $captured_at,
			'provenance'        => self::build_provenance( $tool_name, $args, $result, $options ),
			'target_post_ids'   => $targets['post_ids'],
			'resource_uris'     => $targets['resource_uris'],
			'resource_groups'   => $targets['resource_groups'],
		);

		return self::sanitize_snapshot( $meta );
	}

	public static function preview_meta( array $meta, array $extra = array() ): array {
		$meta = self::sanitize_snapshot( $meta );
		if ( empty( $meta ) ) {
			return $meta;
		}

		$source = $meta['completeness'] ?? 'complete';
		$meta['completeness'] = sanitize_key( (string) ( $extra['completeness'] ?? 'preview' ) );
		$meta['provider']     = sanitize_key( (string) ( $extra['provider'] ?? 'artifact_store' ) );
		$meta['provenance']['source_completeness'] = $source;
		if ( ! empty( $extra['artifact_uri'] ) ) {
			$meta['provenance']['artifact_uri'] = sanitize_text_field( (string) $extra['artifact_uri'] );
		}
		if ( ! empty( $extra['reason'] ) ) {
			$meta['provenance']['preview_reason'] = sanitize_key( (string) $extra['reason'] );
		}

		return self::sanitize_snapshot( $meta );
	}

	public static function sanitize_snapshot_collection( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $snapshot ) {
			$clean = self::sanitize_snapshot( $snapshot );
			if ( empty( $clean['handle'] ) ) {
				continue;
			}
			$items[ $clean['handle'] ] = $clean;
		}

		return array_slice( array_values( $items ), -self::MAX_SNAPSHOTS );
	}

	public static function sanitize_snapshot( $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$query_fingerprint = sanitize_text_field( (string) ( $raw['query_fingerprint'] ?? '' ) );
		$handle            = sanitize_text_field( (string) ( $raw['handle'] ?? $query_fingerprint ) );
		$clean             = array(
			'handle'            => $handle,
			'query_fingerprint' => $query_fingerprint,
			'kind'              => sanitize_key( (string) ( $raw['kind'] ?? '' ) ),
			'tool_name'         => sanitize_key( (string) ( $raw['tool_name'] ?? '' ) ),
			'resource_uri'      => sanitize_text_field( (string) ( $raw['resource_uri'] ?? '' ) ),
			'resource_group'    => sanitize_key( (string) ( $raw['resource_group'] ?? '' ) ),
			'freshness'         => self::allowed_value( $raw['freshness'] ?? 'fresh', array( 'fresh', 'cached', 'stale' ), 'fresh' ),
			'completeness'      => self::allowed_value( $raw['completeness'] ?? 'complete', array( 'complete', 'partial', 'filtered', 'summary', 'preview' ), 'complete' ),
			'trust_class'       => self::allowed_value( $raw['trust_class'] ?? 'derived_summary', array_keys( self::TRUST_ORDER ), 'derived_summary' ),
			'provider'          => sanitize_key( (string) ( $raw['provider'] ?? '' ) ),
			'summary'           => self::compact_text( (string) ( $raw['summary'] ?? '' ), 160 ),
			'captured_at'       => sanitize_text_field( (string) ( $raw['captured_at'] ?? '' ) ),
			'stale_at'          => sanitize_text_field( (string) ( $raw['stale_at'] ?? '' ) ),
			'stale_reason'      => self::compact_text( (string) ( $raw['stale_reason'] ?? '' ), 120 ),
			'invalidated_by'    => sanitize_text_field( (string) ( $raw['invalidated_by'] ?? '' ) ),
			'provenance'        => self::sanitize_provenance( $raw['provenance'] ?? array() ),
			'target_post_ids'   => self::sanitize_int_list( $raw['target_post_ids'] ?? array(), 8 ),
			'resource_uris'     => self::sanitize_text_list( $raw['resource_uris'] ?? array(), 6 ),
			'resource_groups'   => self::sanitize_key_list( $raw['resource_groups'] ?? array(), 6 ),
		);

		if ( '' === $clean['resource_uri'] && ! empty( $clean['resource_uris'][0] ) ) {
			$clean['resource_uri'] = $clean['resource_uris'][0];
		}
		if ( '' === $clean['resource_group'] && ! empty( $clean['resource_groups'][0] ) ) {
			$clean['resource_group'] = $clean['resource_groups'][0];
		}

		return array_filter(
			$clean,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	public static function build_query_fingerprint( string $kind, string $identity, array $args ): string {
		$payload = array(
			'kind'     => sanitize_key( $kind ),
			'identity' => sanitize_text_field( $identity ),
			'args'     => self::normalize_for_hash( $args ),
		);
		$json = wp_json_encode( $payload );
		return sanitize_text_field( $payload['kind'] . ':' . substr( sha1( is_string( $json ) ? $json : serialize( $payload ) ), 0, 12 ) );
	}

	private static function normalize_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : (string) wp_json_encode( $value );
		}
		if ( self::is_list_array( $value ) ) {
			return array_map( array( __CLASS__, 'normalize_for_hash' ), $value );
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize_for_hash( $item );
		}
		return $value;
	}

	private static function is_list_array( array $value ): bool {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}

	public static function sanitize_invalidation_log( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $entry ) {
			$clean = self::sanitize_invalidation( $entry );
			if ( empty( $clean['id'] ) ) {
				continue;
			}
			$items[ $clean['id'] ] = $clean;
		}
		return array_slice( array_values( $items ), -12 );
	}

	public static function sanitize_invalidation( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array(
			'id'              => sanitize_text_field( (string) ( $raw['id'] ?? '' ) ),
			'tool_name'       => sanitize_key( (string) ( $raw['tool_name'] ?? '' ) ),
			'scope'           => self::allowed_value( $raw['scope'] ?? 'target_posts', array( 'target_posts', 'site_content', 'resource', 'site' ), 'target_posts' ),
			'post_ids'        => self::sanitize_int_list( $raw['post_ids'] ?? array(), 12 ),
			'resource_uris'   => self::sanitize_text_list( $raw['resource_uris'] ?? array(), 8 ),
			'resource_groups' => self::sanitize_key_list( $raw['resource_groups'] ?? array(), 8 ),
			'reason'          => self::compact_text( (string) ( $raw['reason'] ?? '' ), 140 ),
			'at'              => sanitize_text_field( (string) ( $raw['at'] ?? '' ) ),
			'matched_handles' => self::sanitize_text_list( $raw['matched_handles'] ?? array(), 12 ),
		);

		return array_filter(
			$clean,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	public static function build_invalidation_from_write( string $tool_name, array $args, array $result ): array {
		$contract = class_exists( 'PressArk_Operation_Registry' )
			? ( PressArk_Operation_Registry::get_contract( $tool_name )['read_invalidation'] ?? array() )
			: array();
		$targets  = self::extract_targets( $tool_name, $args, $result );
		$scope    = sanitize_key( (string) ( $contract['scope'] ?? ( ! empty( $targets['post_ids'] ) ? 'target_posts' : 'site_content' ) ) );
		$payload  = array(
			'tool'   => sanitize_key( $tool_name ),
			'scope'  => $scope,
			'posts'  => $targets['post_ids'],
			'uris'   => array_values( array_unique( array_merge( $targets['resource_uris'], self::sanitize_text_list( $contract['resource_uris'] ?? array(), 8 ) ) ) ),
			'groups' => array_values( array_unique( array_merge( $targets['resource_groups'], self::sanitize_key_list( $contract['resource_groups'] ?? array(), 8 ) ) ) ),
		);
		$json     = wp_json_encode( $payload );

		return self::sanitize_invalidation( array(
			'id'              => 'inv:' . substr( sha1( is_string( $json ) ? $json . '|' . gmdate( 'c' ) : serialize( $payload ) ), 0, 12 ),
			'tool_name'       => $tool_name,
			'scope'           => $scope,
			'post_ids'        => $payload['posts'],
			'resource_uris'   => $payload['uris'],
			'resource_groups' => $payload['groups'],
			'reason'          => sanitize_text_field( (string) ( $contract['reason'] ?? ( $tool_name . ' changed live state' ) ) ),
			'at'              => gmdate( 'c' ),
		) );
	}

	public static function apply_invalidation( array $snapshots, array $descriptor ): array {
		$snapshots = self::sanitize_snapshot_collection( $snapshots );
		$entry     = self::sanitize_invalidation( $descriptor );
		if ( empty( $entry['id'] ) || empty( $snapshots ) ) {
			return array(
				'snapshots'    => $snapshots,
				'invalidation' => $entry,
			);
		}

		$matched = array();
		foreach ( $snapshots as &$snapshot ) {
			if ( self::snapshot_matches_invalidation( $snapshot, $entry ) ) {
				$snapshot['freshness']      = 'stale';
				$snapshot['stale_at']       = $entry['at'] ?? gmdate( 'c' );
				$snapshot['stale_reason']   = $entry['reason'] ?? 'Invalidated by a later write.';
				$snapshot['invalidated_by'] = $entry['id'];
				$matched[]                  = $snapshot['handle'];
			}
		}
		unset( $snapshot );

		$entry['matched_handles'] = $matched;
		return array(
			'snapshots'    => self::sanitize_snapshot_collection( $snapshots ),
			'invalidation' => self::sanitize_invalidation( $entry ),
		);
	}

	public static function build_prompt_strata( array $snapshots, array $verification = array(), string $site_notes = '' ): array {
		$snapshots = self::sanitize_snapshot_collection( $snapshots );
		usort( $snapshots, array( __CLASS__, 'compare_snapshots' ) );

		$groups = array(
			'trusted_system'    => array(),
			'derived_summary'   => array(),
			'untrusted_content' => array(),
		);
		foreach ( $snapshots as $snapshot ) {
			$trust = $snapshot['trust_class'] ?? 'derived_summary';
			if ( isset( $groups[ $trust ] ) ) {
				$groups[ $trust ][] = self::describe_snapshot( $snapshot );
			}
		}

		if ( '' !== trim( $site_notes ) ) {
			$groups['derived_summary'][] = 'Site Notes: ' . trim( preg_replace( '/^\s*Site Notes:\s*/i', '', trim( $site_notes ) ) );
		}

		$verification_lines = array();
		foreach ( array_slice( (array) ( $verification['details'] ?? array() ), 0, self::MAX_SECTION_LINES ) as $detail ) {
			$verification_lines[] = sanitize_text_field( (string) $detail );
		}
		if ( empty( $verification_lines ) && ! empty( $verification['unverified'] ) ) {
			$verification_lines[] = sprintf( '%d recent write(s) still need read-back verification.', (int) $verification['unverified'] );
		}

		return array(
			'trusted_system'    => self::section_block( 'Trusted System Facts', $groups['trusted_system'] ),
			'verified_evidence' => self::section_block( 'Verified Evidence', $verification_lines ),
			'derived_summaries' => self::section_block( 'Derived Summaries', $groups['derived_summary'] ),
			'untrusted_content' => self::section_block( 'Untrusted Site Content', $groups['untrusted_content'] ),
		);
	}

	public static function build_checkpoint_lines( array $snapshots, array $verification = array() ): array {
		$snapshots = self::sanitize_snapshot_collection( $snapshots );
		if ( empty( $snapshots ) && empty( $verification['details'] ) ) {
			return array();
		}

		$count_by_trust = array(
			'trusted_system'    => 0,
			'derived_summary'   => 0,
			'untrusted_content' => 0,
			'stale'             => 0,
		);
		foreach ( $snapshots as $snapshot ) {
			$trust = $snapshot['trust_class'] ?? 'derived_summary';
			if ( isset( $count_by_trust[ $trust ] ) ) {
				++$count_by_trust[ $trust ];
			}
			if ( 'stale' === ( $snapshot['freshness'] ?? '' ) ) {
				++$count_by_trust['stale'];
			}
		}

		$lines   = array();
		$lines[] = sprintf(
			'READ STATE: trusted=%d, derived=%d, untrusted=%d, stale=%d',
			$count_by_trust['trusted_system'],
			$count_by_trust['derived_summary'],
			$count_by_trust['untrusted_content'],
			$count_by_trust['stale']
		);

		$stale_lines = array();
		foreach ( $snapshots as $snapshot ) {
			if ( 'stale' !== ( $snapshot['freshness'] ?? '' ) ) {
				continue;
			}
			$stale_lines[] = self::describe_snapshot( $snapshot );
			if ( count( $stale_lines ) >= 2 ) {
				break;
			}
		}
		if ( ! empty( $stale_lines ) ) {
			$lines[] = 'STALE READS: ' . implode( ' | ', $stale_lines );
		}
		if ( ! empty( $verification['details'][0] ) ) {
			$lines[] = 'VERIFIED: ' . sanitize_text_field( (string) $verification['details'][0] );
		}

		return $lines;
	}

	private static function infer_trust_class( string $tool_name ): string {
		$tool_name = sanitize_key( $tool_name );
		if ( in_array( $tool_name, self::CONTENT_TOOLS, true ) ) {
			return 'untrusted_content';
		}
		if ( in_array( $tool_name, self::TRUSTED_SYSTEM_TOOLS, true ) ) {
			return 'trusted_system';
		}
		return in_array( $tool_name, self::DERIVED_TOOLS, true ) ? 'derived_summary' : 'derived_summary';
	}

	private static function resource_trust_class( array $definition ): string {
		$group = sanitize_key( (string) ( $definition['group'] ?? '' ) );
		return in_array( $group, array( 'tool-results' ), true ) ? 'derived_summary' : 'trusted_system';
	}

	private static function infer_freshness( string $tool_name, array $result ): string {
		if ( ! empty( $result['cached'] ) || ! empty( $result['data']['bundle_hit'] ) ) {
			return 'cached';
		}
		if ( self::has_stale_signal( $result['data'] ?? $result ) ) {
			return 'stale';
		}
		return 'fresh';
	}

	private static function infer_completeness( string $tool_name, array $args, array $result ): string {
		$tool_name = sanitize_key( $tool_name );
		$data      = is_array( $result['data'] ?? null ) ? $result['data'] : array();

		if ( 'read_content' === $tool_name ) {
			$mode = sanitize_key( (string) ( $data['mode'] ?? $args['mode'] ?? 'light' ) );
			if ( 'full' === $mode || 'raw' === $mode ) {
				return ! empty( $data['_section'] ) ? 'filtered' : 'complete';
			}
			return 'structured' === $mode || 'detail' === $mode ? 'partial' : 'summary';
		}

		if ( in_array( $tool_name, array( 'search_content', 'list_posts', 'search_knowledge' ), true ) ) {
			$pagination = is_array( $result['_pagination'] ?? null ) ? $result['_pagination'] : ( is_array( $data['_pagination'] ?? null ) ? $data['_pagination'] : array() );
			if ( ! empty( $pagination['has_more'] ) || ! empty( $result['has_more'] ) || ! empty( $result['total'] ) || ! empty( $result['shown'] ) ) {
				return 'partial';
			}
			return 'search_knowledge' === $tool_name ? 'partial' : 'complete';
		}

		if ( in_array( $tool_name, array( 'elementor_read_page', 'get_design_system' ), true ) ) {
			return ( ! empty( $args['section'] ) || ! empty( $args['widget_type'] ) || ! empty( $args['max_depth'] ) ) ? 'filtered' : 'complete';
		}

		return 'complete';
	}

	private static function build_provenance( string $tool_name, array $args, array $result, array $options ): array {
		$data       = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$provenance = array(
			'mode'       => sanitize_key( (string) ( $data['mode'] ?? $args['mode'] ?? '' ) ),
			'resolved'   => sanitize_key( (string) ( $data['_resolved_mode'] ?? '' ) ),
			'section'    => sanitize_key( (string) ( $data['_section'] ?? $args['section'] ?? '' ) ),
			'cached'     => ! empty( $options['freshness'] ) && 'cached' === $options['freshness'] ? 'yes' : '',
			'bundle_hit' => ! empty( $data['bundle_hit'] ) ? 'yes' : '',
			'stored_at'  => sanitize_text_field( (string) ( $options['stored_at'] ?? '' ) ),
			'total'      => isset( $result['total'] ) ? (int) $result['total'] : '',
			'shown'      => isset( $result['shown'] ) ? (int) $result['shown'] : '',
		);
		return self::sanitize_provenance( array_filter( $provenance, static fn( $value ) => '' !== (string) $value && null !== $value ) );
	}

	private static function extract_targets( string $tool_name, array $args, array $result ): array {
		$post_ids = array();
		foreach ( array( 'post_id', 'id' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$post_ids[] = absint( $args[ $key ] );
			}
		}

		$data = $result['data'] ?? null;
		if ( is_array( $data ) ) {
			if ( isset( $data['id'] ) || isset( $data['post_id'] ) ) {
				$post_ids[] = absint( $data['id'] ?? $data['post_id'] );
			}
			foreach ( array_slice( self::is_list_array( $data ) ? $data : array(), 0, 8 ) as $item ) {
				if ( is_array( $item ) && ( isset( $item['id'] ) || isset( $item['post_id'] ) ) ) {
					$post_ids[] = absint( $item['id'] ?? $item['post_id'] );
				}
			}
		}

		$resource_uris   = array();
		$resource_groups = self::tool_resource_groups( $tool_name );
		if ( 'read_resource' === sanitize_key( $tool_name ) && ! empty( $args['uri'] ) ) {
			$resource_uris[] = sanitize_text_field( (string) $args['uri'] );
		}

		return array(
			'post_ids'        => self::sanitize_int_list( $post_ids, 12 ),
			'resource_uris'   => self::sanitize_text_list( $resource_uris, 8 ),
			'resource_groups' => self::sanitize_key_list( $resource_groups, 8 ),
		);
	}

	private static function has_stale_signal( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( ! empty( $value['is_stale'] ) || ! empty( $value['stale'] ) ) {
			return true;
		}
		foreach ( $value as $item ) {
			if ( is_array( $item ) && self::has_stale_signal( $item ) ) {
				return true;
			}
		}
		return false;
	}

	private static function build_summary( string $tool_name, array $args, array $result ): string {
		$message = self::compact_text( (string) ( $result['message'] ?? '' ), 120 );
		if ( '' !== $message ) {
			return $message;
		}
		if ( 'read_content' === $tool_name ) {
			$title = sanitize_text_field( (string) ( $result['data']['title'] ?? '' ) );
			$id    = absint( $result['data']['id'] ?? $args['post_id'] ?? 0 );
			return trim( 'read_content ' . ( $title ? '"' . $title . '"' : '' ) . ( $id > 0 ? ' #' . $id : '' ) );
		}
		return sanitize_text_field( $tool_name );
	}

	private static function compare_snapshots( array $left, array $right ): int {
		$l_trust = self::TRUST_ORDER[ $left['trust_class'] ?? 'derived_summary' ] ?? 9;
		$r_trust = self::TRUST_ORDER[ $right['trust_class'] ?? 'derived_summary' ] ?? 9;
		if ( $l_trust !== $r_trust ) {
			return $l_trust <=> $r_trust;
		}
		return strcmp( (string) ( $left['handle'] ?? '' ), (string) ( $right['handle'] ?? '' ) );
	}

	private static function snapshot_matches_invalidation( array $snapshot, array $entry ): bool {
		$scope = $entry['scope'] ?? 'target_posts';
		if ( 'site' === $scope ) {
			return true;
		}
		if ( ! empty( $entry['post_ids'] ) && array_intersect( $entry['post_ids'], $snapshot['target_post_ids'] ?? array() ) ) {
			return true;
		}
		if ( ! empty( $entry['resource_uris'] ) ) {
			$snapshot_uris = array_filter( array_merge( array( $snapshot['resource_uri'] ?? '' ), (array) ( $snapshot['resource_uris'] ?? array() ) ) );
			if ( array_intersect( $entry['resource_uris'], $snapshot_uris ) ) {
				return true;
			}
		}
		if ( ! empty( $entry['resource_groups'] ) ) {
			$snapshot_groups = array_filter( array_merge( array( $snapshot['resource_group'] ?? '' ), (array) ( $snapshot['resource_groups'] ?? array() ) ) );
			if ( array_intersect( $entry['resource_groups'], $snapshot_groups ) ) {
				return true;
			}
		}
		if ( 'site_content' === $scope ) {
			return in_array( $snapshot['trust_class'] ?? '', array( 'derived_summary', 'untrusted_content' ), true );
		}
		return false;
	}

	private static function describe_snapshot( array $snapshot ): string {
		$label = $snapshot['summary'] ?? ( $snapshot['tool_name'] ?? $snapshot['resource_uri'] ?? 'read' );
		$bits  = array();
		$bits[] = $snapshot['freshness'] ?? 'fresh';
		$bits[] = $snapshot['completeness'] ?? 'complete';
		if ( ! empty( $snapshot['handle'] ) ) {
			$bits[] = $snapshot['handle'];
		}
		return self::compact_text( $label, 90 ) . ' [' . implode( ', ', array_filter( $bits ) ) . ']';
	}

	private static function section_block( string $title, array $lines ): string {
		$lines = array_values( array_filter( array_map( 'trim', $lines ) ) );
		if ( empty( $lines ) ) {
			return '';
		}
		$lines = array_slice( $lines, 0, self::MAX_SECTION_LINES );
		return "## {$title}\n- " . implode( "\n- ", $lines );
	}

	private static function sanitize_provenance( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || count( $clean ) >= self::MAX_PROVENANCE ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value ? 'yes' : 'no';
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = self::compact_text( (string) $value, 80 );
			}
		}
		return array_filter( $clean, static fn( $value ) => '' !== (string) $value );
	}

	private static function sanitize_text_list( $raw, int $max ): array {
		$items = array();
		foreach ( array_slice( (array) $raw, 0, $max ) as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$items[] = $value;
			}
		}
		return array_values( array_unique( $items ) );
	}

	private static function sanitize_key_list( $raw, int $max ): array {
		$items = array();
		foreach ( array_slice( (array) $raw, 0, $max ) as $value ) {
			$value = sanitize_key( (string) $value );
			if ( '' !== $value ) {
				$items[] = $value;
			}
		}
		return array_values( array_unique( $items ) );
	}

	private static function sanitize_int_list( $raw, int $max ): array {
		$items = array();
		foreach ( array_slice( (array) $raw, 0, $max ) as $value ) {
			$value = absint( $value );
			if ( $value > 0 ) {
				$items[] = $value;
			}
		}
		return array_values( array_unique( $items ) );
	}

	private static function allowed_value( $value, array $allowed, string $default ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function compact_text( string $text, int $max_chars ): string {
		$text = trim( sanitize_text_field( $text ) );
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, max( 0, $max_chars - 3 ) ) ) . '...';
	}

	private static function tool_resource_groups( string $tool_name ): array {
		$tool_name = sanitize_key( $tool_name );

		return match ( $tool_name ) {
			'get_site_overview', 'get_site_map', 'get_site_settings', 'list_plugins', 'list_themes' => array( 'site' ),
			'get_theme_settings', 'get_customizer_schema', 'get_design_system' => array( 'design' ),
			'get_templates' => array( 'templates' ),
			'discover_rest_routes' => array( 'rest' ),
			'elementor_get_styles' => array( 'elementor', 'design' ),
			default => array(),
		};
	}
}
