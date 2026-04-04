<?php
/**
 * Persist large read results outside the live prompt.
 *
 * @package PressArk
 * @since   5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Result_Artifacts {

	private const CACHE_GROUP                  = 'pressark_tool_results';
	private const RECORD_PREFIX                = 'pressark_tr_artifact_';
	private const USER_INDEX_PREFIX            = 'pressark_tr_user_';
	private const MAX_INDEX_ITEMS              = 40;
	private const DEFAULT_TTL                  = 2 * DAY_IN_SECONDS;
	private const DEFAULT_INLINE_THRESHOLD     = 1200;
	private const PRIORITY_INLINE_THRESHOLD    = 900;
	private const DEFAULT_TURN_BUDGET          = 2600;
	private const DEFAULT_AGGREGATE_MIN_TOKENS = 350;

	private const PHASE_ONE_TOOLS = array(
		'analyze_seo',
		'customer_insights',
		'discover_rest_routes',
		'elementor_get_styles',
		'elementor_read_page',
		'get_design_system',
		'get_product',
		'get_templates',
		'inspect_hooks',
		'inventory_report',
		'page_audit',
		'profile_queries',
		'read_blocks',
		'revenue_report',
		'sales_summary',
		'scan_security',
		'site_health',
		'stock_report',
		'store_health',
	);

	private string $run_id = '';
	private int    $chat_id = 0;
	private int    $user_id = 0;
	private int    $round = 0;
	private array  $replacement_journal = array();
	private array  $replacement_events  = array();

	public function __construct( string $run_id = '', int $chat_id = 0, int $user_id = 0, int $round = 0 ) {
		$this->run_id  = self::normalize_run_id( $run_id, $chat_id );
		$this->chat_id = max( 0, $chat_id );
		$this->user_id = $user_id > 0 ? $user_id : max( 0, get_current_user_id() );
		$this->round   = max( 0, $round );
	}

	public static function should_preserve_full_result( string $tool_name, array $result ): bool {
		return self::artifact_priority_for( $tool_name, $result ) >= 2;
	}

	public static function is_tool_result_uri( string $uri ): bool {
		return 0 === strpos( $uri, 'pressark://tool-results/' );
	}

	/**
	 * @param array[] $tool_results
	 * @return array[]
	 */
	public function prepare_batch( array $tool_results, array $replacement_journal = array() ): array {
		$this->replacement_journal = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_replacement_journal( $replacement_journal )
			: array();
		$this->replacement_events  = array();

		$items = array();
		$total = 0;

		foreach ( $tool_results as $index => $entry ) {
			$result      = isset( $entry['result'] ) && is_array( $entry['result'] ) ? $entry['result'] : array();
			$tool_name   = sanitize_key( (string) ( $entry['tool_name'] ?? $result['_tool_name'] ?? '' ) );
			$inline_cost = $this->estimate_tokens( $result );
			$priority    = self::artifact_priority_for( $tool_name, $result );

			$items[ $index ] = array(
				'entry'         => $entry,
				'tool_name'     => $tool_name,
				'result'        => $result,
				'priority'      => $priority,
				'eligible'      => $this->is_artifact_candidate( $tool_name, $result, $inline_cost, $priority ),
				'inline_tokens' => $inline_cost,
				'prompt_tokens' => $inline_cost,
				'artifactized'  => false,
			);

			$total += $inline_cost;
		}

		foreach ( array_keys( $items ) as $index ) {
			$frozen = $this->reuse_frozen_replacement( $items[ $index ] );
			if ( null === $frozen ) {
				continue;
			}

			$total          += $frozen['prompt_tokens'] - $items[ $index ]['prompt_tokens'];
			$items[ $index ] = $frozen;
		}

		foreach ( array_keys( $items ) as $index ) {
			$item = $items[ $index ];
			if ( $item['artifactized'] || ! $item['eligible'] || $item['inline_tokens'] < $this->single_inline_threshold( $item['priority'] ) ) {
				continue;
			}

			$items[ $index ] = $this->artifactize_item( $item, 'single_result_budget' );
			$total += $items[ $index ]['prompt_tokens'] - $item['prompt_tokens'];
		}

		if ( $total > $this->turn_inline_budget() ) {
			$candidates = array();
			foreach ( $items as $index => $item ) {
				if ( $item['artifactized'] || ! $item['eligible'] ) {
					continue;
				}
				if ( $item['inline_tokens'] < $this->aggregate_candidate_min_tokens() ) {
					continue;
				}
				$candidates[] = $index;
			}

			usort(
				$candidates,
				function ( int $left, int $right ) use ( $items ): int {
					if ( $items[ $left ]['priority'] !== $items[ $right ]['priority'] ) {
						return $items[ $right ]['priority'] <=> $items[ $left ]['priority'];
					}
					return $items[ $right ]['inline_tokens'] <=> $items[ $left ]['inline_tokens'];
				}
			);

			foreach ( $candidates as $index ) {
				if ( $total <= $this->turn_inline_budget() ) {
					break;
				}
				$item          = $items[ $index ];
				if ( $item['artifactized'] ) {
					continue;
				}

				$items[ $index ] = $this->artifactize_item( $item, 'aggregate_turn_budget' );
				$total += $items[ $index ]['prompt_tokens'] - $item['prompt_tokens'];
			}
		}

		return array_values(
			array_map(
				static fn( array $item ): array => $item['entry'],
				$items
			)
		);
	}

	public function get_replacement_journal(): array {
		return $this->replacement_journal;
	}

	public function get_replacement_events(): array {
		return $this->replacement_events;
	}

	/**
	 * @return array[]
	 */
	public static function list_resource_entries( int $user_id = 0, int $limit = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : max( 0, get_current_user_id() );
		if ( $user_id <= 0 ) {
			return array();
		}

		$index   = self::load_user_index( $user_id, true );
		$entries = array();

		foreach ( $index as $meta ) {
			$read_meta = class_exists( 'PressArk_Read_Metadata' )
				? PressArk_Read_Metadata::sanitize_snapshot( $meta['read_meta'] ?? array() )
				: array();
			$entries[] = array(
				'uri'         => (string) ( $meta['uri'] ?? '' ),
				'name'        => self::resource_name_from_meta( $meta ),
				'description' => self::resource_description_from_meta( $meta ),
				'group'       => 'tool-results',
				'mime_type'   => 'application/json',
				'trust_class' => sanitize_key( (string) ( $read_meta['trust_class'] ?? 'derived_summary' ) ),
				'provider'    => sanitize_key( (string) ( $read_meta['provider'] ?? 'artifact_store' ) ),
			);
		}

		return $limit > 0 ? array_slice( $entries, 0, $limit ) : $entries;
	}

	public static function has_resource_entries( int $user_id = 0 ): bool {
		return ! empty( self::list_resource_entries( $user_id, 1 ) );
	}

	public static function read_resource( string $uri, int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : max( 0, get_current_user_id() );
		$parts   = self::parse_uri( $uri );

		if ( empty( $parts ) ) {
			return array( 'success' => false, 'error' => sprintf( 'Invalid tool-result resource URI: %s', $uri ), 'uri' => $uri );
		}

		$record = self::get_record( $parts['artifact_id'] );
		if ( null === $record ) {
			return array( 'success' => false, 'error' => sprintf( 'Tool-result artifact expired or was not found: %s', $uri ), 'uri' => $uri );
		}

		if ( $user_id > 0 && (int) ( $record['user_id'] ?? 0 ) !== $user_id ) {
			return array( 'success' => false, 'error' => __( 'You do not have access to this tool-result artifact.', 'pressark' ), 'uri' => $uri );
		}

		$read_meta = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::sanitize_snapshot( $record['read_meta'] ?? array() )
			: array();

		return array(
			'success' => true,
			'cached'  => false,
			'uri'     => $uri,
			'data'    => array(
				'resource_type'           => 'tool_result_artifact',
				'artifact_id'             => (string) ( $record['artifact_id'] ?? '' ),
				'tool_name'               => (string) ( $record['tool_name'] ?? '' ),
				'tool_use_id'             => (string) ( $record['tool_use_id'] ?? '' ),
				'run_id'                  => (string) ( $record['run_id'] ?? '' ),
				'chat_id'                 => (int) ( $record['chat_id'] ?? 0 ),
				'round'                   => (int) ( $record['round'] ?? 0 ),
				'reason'                  => (string) ( $record['reason'] ?? '' ),
				'stored_at'               => gmdate( 'c', (int) ( $record['created_at'] ?? time() ) ),
				'stored_bytes'            => (int) ( $record['bytes'] ?? 0 ),
				'estimated_inline_tokens' => (int) ( $record['estimated_inline_tokens'] ?? 0 ),
				'meta'                    => $read_meta,
				'result'                  => $record['result'] ?? array(),
			),
			'meta'    => $read_meta,
			'read_meta' => $read_meta,
		);
	}

	private function artifactize_item( array $item, string $reason ): array {
		$record                       = $this->store_artifact( $item['tool_name'], (string) ( $item['entry']['tool_use_id'] ?? '' ), $item['result'], $reason, $item['inline_tokens'] );
		$item['entry']['result']      = $this->build_prompt_result( $item['tool_name'], $item['result'], $record, $reason );
		$item['entry']['tool_name']   = $item['tool_name'];
		$item['entry']['_artifactized'] = true;
		$item['artifactized']         = true;
		$item['prompt_tokens']        = $this->estimate_tokens( $item['entry']['result'] );
		$this->remember_replacement(
			$item['tool_name'],
			(string) ( $item['entry']['tool_use_id'] ?? '' ),
			$item['result'],
			$item['entry']['result'],
			$record,
			$reason,
			$item['inline_tokens'],
			'artifactized'
		);

		return $item;
	}

	private function reuse_frozen_replacement( array $item ): ?array {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return null;
		}

		$tool_use_id = (string) ( $item['entry']['tool_use_id'] ?? '' );
		if ( '' === $tool_use_id ) {
			return null;
		}

		$entry = PressArk_Replay_Integrity::find_replacement_entry( $this->replacement_journal, $tool_use_id );
		if ( empty( $entry ) ) {
			return null;
		}

		$current_hash = PressArk_Replay_Integrity::result_hash( $item['result'] );
		$stored_hash  = (string) ( $entry['result_hash'] ?? '' );
		if ( '' !== $stored_hash && $stored_hash !== $current_hash ) {
			return null;
		}

		$item['entry']['result']        = $entry['replacement'] ?? $item['entry']['result'];
		$item['entry']['tool_name']     = $item['tool_name'];
		$replacement = $item['entry']['result'];
		$is_artifact = ! empty( $entry['artifact_uri'] )
			|| ( is_array( $replacement ) && ! empty( $replacement['_artifactized'] ) );
		$item['entry']['_artifactized'] = $is_artifact;
		$item['artifactized']           = ! empty( $item['entry']['_artifactized'] );
		$item['prompt_tokens']          = $this->estimate_tokens( $item['entry']['result'] );

		$this->replacement_events[] = array_filter( array(
			'type'         => 'replacement',
			'mode'         => 'reused',
			'tool_use_id'  => $tool_use_id,
			'tool_name'    => $item['tool_name'],
			'reason'       => sanitize_key( (string) ( $entry['reason'] ?? '' ) ),
			'inline_tokens'=> $item['inline_tokens'],
			'artifact_uri' => (string) ( $entry['artifact_uri'] ?? '' ),
			'round'        => $this->round,
			'at'           => gmdate( 'c' ),
		) );

		return $item;
	}

	private function remember_replacement(
		string $tool_name,
		string $tool_use_id,
		array $result,
		$replacement,
		array $record,
		string $reason,
		int $inline_tokens,
		string $mode
	): void {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) || '' === $tool_use_id ) {
			return;
		}

		$this->replacement_journal[] = array(
			'tool_use_id'   => $tool_use_id,
			'tool_name'     => $tool_name,
			'result_hash'   => PressArk_Replay_Integrity::result_hash( $result ),
			'artifact_uri'  => (string) ( $record['uri'] ?? '' ),
			'reason'        => $reason,
			'round'         => $this->round,
			'inline_tokens' => $inline_tokens,
			'stored_at'     => gmdate( 'c' ),
			'replacement'   => $replacement,
		);
		$this->replacement_journal = PressArk_Replay_Integrity::sanitize_replacement_journal( $this->replacement_journal );

		$this->replacement_events[] = array_filter( array(
			'type'         => 'replacement',
			'mode'         => sanitize_key( $mode ),
			'tool_use_id'  => $tool_use_id,
			'tool_name'    => sanitize_key( $tool_name ),
			'reason'       => sanitize_key( $reason ),
			'inline_tokens'=> $inline_tokens,
			'artifact_uri' => (string) ( $record['uri'] ?? '' ),
			'round'        => $this->round,
			'at'           => gmdate( 'c' ),
		) );
	}

	private function build_prompt_result( string $tool_name, array $result, array $record, string $reason ): array {
		$preview = $this->build_preview( $tool_name, $result );
		$message = $this->trim_message( (string) ( $result['message'] ?? '' ), 180 );
		$preview_meta = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::preview_meta(
				$record['read_meta'] ?? ( $result['read_meta'] ?? array() ),
				array(
					'artifact_uri' => $record['uri'] ?? '',
					'reason'       => $reason,
					'provider'     => 'artifact_store',
				)
			)
			: array();
		if ( '' === $message ) {
			$message = $this->fallback_message( $tool_name, $preview );
		}

		$message .= ' ' . (
			'aggregate_turn_budget' === $reason
				? sprintf( 'Full payload stored as %s to stay within the turn budget.', $record['uri'] )
				: sprintf( 'Full payload stored as %s to keep the live context compact.', $record['uri'] )
		);

		return array(
			'success' => array_key_exists( 'success', $result ) ? (bool) $result['success'] : true,
			'message' => trim( $message ),
			'data'    => array(
				'preview'  => $preview,
				'artifact' => array(
					'uri'                     => $record['uri'],
					'tool'                    => $tool_name,
					'run_id'                  => $record['run_id'],
					'chat_id'                 => $record['chat_id'],
					'reason'                  => $reason,
					'stored_bytes'            => $record['bytes'],
					'estimated_inline_tokens' => $record['estimated_inline_tokens'],
					'read_state'              => array_filter( array(
						'trust_class'       => $preview_meta['trust_class'] ?? '',
						'freshness'         => $preview_meta['freshness'] ?? '',
						'completeness'      => $preview_meta['completeness'] ?? '',
						'query_fingerprint' => $preview_meta['query_fingerprint'] ?? '',
					) ),
					'browse_hint'             => 'Use read_resource with this URI to inspect the full payload. Use list_resources with group \"tool-results\" to browse stored artifacts.',
				),
			),
			'read_meta' => $preview_meta,
			'_artifactized' => true,
			'_artifact_uri' => $record['uri'],
			'_tool_name'    => $tool_name,
		);
	}

	private function store_artifact( string $tool_name, string $tool_use_id, array $result, string $reason, int $inline_tokens ): array {
		$artifact_id = wp_generate_uuid4();
		$record      = array(
			'artifact_id'             => $artifact_id,
			'uri'                     => self::build_uri( $this->run_id, $artifact_id ),
			'user_id'                 => $this->user_id,
			'run_id'                  => $this->run_id,
			'chat_id'                 => $this->chat_id,
			'round'                   => $this->round,
			'tool_name'               => $tool_name,
			'tool_use_id'             => $tool_use_id,
			'reason'                  => $reason,
			'created_at'              => time(),
			'bytes'                   => $this->estimate_bytes( $result ),
			'estimated_inline_tokens' => $inline_tokens,
			'message'                 => $this->trim_message( (string) ( $result['message'] ?? '' ), 140 ),
			'read_meta'               => class_exists( 'PressArk_Read_Metadata' )
				? PressArk_Read_Metadata::sanitize_snapshot( $result['read_meta'] ?? array() )
				: array(),
			'result'                  => $result,
		);

		self::persist_record( $record, $this->artifact_ttl() );
		self::append_to_user_index( $this->user_id, $record, $this->artifact_ttl() );

		return $record;
	}

	private function build_preview( string $tool_name, array $result ): array {
		$data = ( isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : $result;

		if ( 'discover_rest_routes' === $tool_name ) {
			return array(
				'total_routes'    => (int) ( $data['total_routes'] ?? 0 ),
				'namespace_count' => (int) ( $data['namespace_count'] ?? 0 ),
				'namespaces'      => $this->summarize_preview_list( $data['summary'] ?? array(), array( 'namespace', 'route_count', 'plugin_hint' ), 8 ),
				'route_sample'    => $this->summarize_preview_list( $data['routes'] ?? array(), array( 'route', 'methods' ), 5 ),
			);
		}

		if ( in_array( $tool_name, array( 'read_blocks', 'get_templates' ), true ) ) {
			return array(
				'title'       => sanitize_text_field( (string) ( $data['title'] ?? $data['slug'] ?? '' ) ),
				'type'        => sanitize_text_field( (string) ( $data['type'] ?? '' ) ),
				'count'       => (int) ( $data['block_count'] ?? $data['count'] ?? 0 ),
				'issue_count' => is_array( $data['issues'] ?? null ) ? count( $data['issues'] ) : 0,
				'items'       => $this->summarize_preview_list( $data['blocks'] ?? $data['templates'] ?? array(), array( 'index', 'name', 'label', 'preview', 'slug', 'title', 'source', 'origin', 'block_count' ), 8 ),
			);
		}

		if ( in_array( $tool_name, array( 'get_design_system', 'elementor_get_styles' ), true ) ) {
			return array(
				'theme'       => sanitize_text_field( (string) ( $data['theme'] ?? '' ) ),
				'layout'      => $this->summarize_assoc_scalars( $data['layout'] ?? array(), 4 ),
				'colors'      => is_array( $data['colors']['palette'] ?? null ) ? count( $data['colors']['palette'] ) : 0,
				'fonts'       => is_array( $data['typography']['font_families'] ?? null ) ? count( $data['typography']['font_families'] ) : 0,
				'font_sizes'  => is_array( $data['typography']['font_sizes'] ?? null ) ? count( $data['typography']['font_sizes'] ) : 0,
				'elements'    => is_array( $data['elements'] ?? null ) ? array_slice( array_keys( $data['elements'] ), 0, 8 ) : array(),
			);
		}

		if ( 'elementor_read_page' === $tool_name ) {
			return array(
				'title'     => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'post_id'   => (int) ( $data['post_id'] ?? 0 ),
				'stats'     => $this->summarize_assoc_scalars( $data['stats'] ?? array(), 6 ),
				'structure' => $this->summarize_preview_list( $data['structure'] ?? array(), array( 'id', 'elType', 'widgetType', 'widget_type', 'title' ), 8 ),
			);
		}

		if ( 'get_product' === $tool_name ) {
			return array(
				'id'                   => (int) ( $data['id'] ?? 0 ),
				'name'                 => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'type'                 => sanitize_key( (string) ( $data['type'] ?? '' ) ),
				'status'               => sanitize_key( (string) ( $data['status'] ?? '' ) ),
				'sku'                  => sanitize_text_field( (string) ( $data['sku'] ?? '' ) ),
				'price'                => sanitize_text_field( (string) ( $data['price'] ?? '' ) ),
				'stock_status'         => sanitize_key( (string) ( $data['stock_status'] ?? '' ) ),
				'stock_quantity'       => $data['stock_quantity'] ?? null,
				'categories'           => array_slice( array_values( array_filter( (array) ( $data['categories'] ?? array() ), 'is_scalar' ) ), 0, 6 ),
				'attribute_count'      => is_array( $data['attributes'] ?? null ) ? count( $data['attributes'] ) : 0,
				'image_count'          => is_array( $data['images'] ?? null ) ? count( $data['images'] ) : 0,
				'description_length'   => mb_strlen( (string) ( $data['description'] ?? '' ) ),
			);
		}

		if ( in_array( $tool_name, array( 'inventory_report', 'sales_summary', 'revenue_report', 'customer_insights', 'stock_report', 'store_health', 'site_health', 'analyze_seo', 'scan_security', 'page_audit', 'inspect_hooks', 'profile_queries' ), true ) ) {
			return array(
				'fields'      => $this->summarize_assoc_scalars( $data, 10 ),
				'collections' => array(
					'issues'       => is_array( $data['issues'] ?? null ) ? $this->summarize_preview_list( $data['issues'], array( 'title', 'label', 'severity', 'message' ), 5 ) : array(),
					'flags'        => is_array( $data['flags'] ?? null ) ? array_slice( array_values( array_filter( $data['flags'], 'is_scalar' ) ), 0, 6 ) : array(),
					'callbacks'    => is_array( $data['callbacks'] ?? null ) ? $this->summarize_preview_list( $data['callbacks'], array( 'priority', 'function', 'source', 'file', 'line' ), 5 ) : array(),
					'slow_queries' => is_array( $data['slow_queries'] ?? null ) ? $this->summarize_preview_list( $data['slow_queries'], array( 'sql', 'time_ms', 'caller' ), 5 ) : array(),
					'products'     => is_array( $data['products'] ?? null ) ? $this->summarize_preview_list( $data['products'], array( 'id', 'name', 'sku', 'stock', 'status', 'price' ), 5 ) : array(),
				),
			);
		}

		return array(
			'fields'      => $this->summarize_assoc_scalars( is_array( $data ) ? $data : array(), 10 ),
			'collections' => array(
				'items' => is_array( $data ) ? $this->summarize_preview_list( $data, array(), 5 ) : array(),
			),
		);
	}

	private function fallback_message( string $tool_name, array $preview ): string {
		$label = ucwords( str_replace( '_', ' ', sanitize_key( $tool_name ) ) );
		if ( ! empty( $preview['title'] ) ) {
			return sprintf( '%s result for "%s".', $label, $preview['title'] );
		}
		if ( ! empty( $preview['name'] ) ) {
			return sprintf( '%s result for "%s".', $label, $preview['name'] );
		}
		return sprintf( '%s result ready.', $label );
	}

	private function trim_message( string $message, int $limit ): string {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( mb_strlen( $message ) <= $limit ) {
			return $message;
		}
		return mb_substr( $message, 0, $limit - 3 ) . '...';
	}

	private function summarize_preview_list( $items, array $preferred_fields = array(), int $limit = 5 ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$summary = array();
		foreach ( array_slice( $items, 0, $limit ) as $item ) {
			if ( is_scalar( $item ) || null === $item ) {
				$summary[] = $this->trim_message( (string) $item, 100 );
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$fields = ! empty( $preferred_fields ) ? $preferred_fields : array_slice( array_keys( $item ), 0, 5 );
			$row    = array();
			foreach ( $fields as $field ) {
				if ( ! array_key_exists( $field, $item ) ) {
					continue;
				}
				$value = $item[ $field ];
				if ( is_scalar( $value ) || null === $value ) {
					$row[ $field ] = is_string( $value ) ? $this->trim_message( $value, 100 ) : $value;
				} elseif ( is_array( $value ) ) {
					$row[ $field ] = array_slice( array_values( array_filter( $value, 'is_scalar' ) ), 0, 5 );
				}
			}
			if ( ! empty( $row ) ) {
				$summary[] = $row;
			}
		}

		return $summary;
	}

	private function summarize_assoc_scalars( $data, int $limit = 8 ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$summary = array();
		foreach ( $data as $key => $value ) {
			if ( count( $summary ) >= $limit ) {
				break;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$summary[ $key ] = is_string( $value ) ? $this->trim_message( $value, 120 ) : $value;
		}

		return $summary;
	}

	private function is_artifact_candidate( string $tool_name, array $result, int $inline_tokens, int $priority ): bool {
		if ( '' === $tool_name || 0 === $priority || 'read_resource' === $tool_name ) {
			return false;
		}

		if ( ! empty( $result['_artifactized'] ) || ! empty( $result['_tool_output_limit_exceeded'] ) ) {
			return false;
		}

		$default = $inline_tokens >= $this->aggregate_candidate_min_tokens();
		return (bool) apply_filters( 'pressark_tool_result_should_artifactize', $default, $tool_name, $result, $inline_tokens, $priority );
	}

	private function single_inline_threshold( int $priority ): int {
		$default = $priority >= 3 ? self::PRIORITY_INLINE_THRESHOLD : self::DEFAULT_INLINE_THRESHOLD;
		return (int) apply_filters( 'pressark_tool_result_inline_threshold', $default, $priority );
	}

	private function turn_inline_budget(): int {
		return (int) apply_filters( 'pressark_tool_result_turn_budget', self::DEFAULT_TURN_BUDGET );
	}

	private function aggregate_candidate_min_tokens(): int {
		return (int) apply_filters( 'pressark_tool_result_aggregate_min_tokens', self::DEFAULT_AGGREGATE_MIN_TOKENS );
	}

	private function artifact_ttl(): int {
		return (int) apply_filters( 'pressark_tool_result_artifact_ttl', self::DEFAULT_TTL );
	}

	private function estimate_tokens( $value ): int {
		$json = is_string( $value ) ? $value : wp_json_encode( $value );
		return (int) ceil( max( 0, is_string( $json ) ? mb_strlen( $json ) : 0 ) / 4 );
	}

	private function estimate_bytes( $value ): int {
		$json = is_string( $value ) ? $value : wp_json_encode( $value );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	private static function artifact_priority_for( string $tool_name, array $result ): int {
		$tool_name = sanitize_key( $tool_name );
		if ( in_array( $tool_name, self::PHASE_ONE_TOOLS, true ) ) {
			return 3;
		}
		if ( 'large' === PressArk_Operation_Registry::get_output_policy( $tool_name ) ) {
			return 2;
		}
		return self::looks_large( $result ) ? 2 : 0;
	}

	private static function looks_large( array $result ): bool {
		$data = $result['data'] ?? $result;
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( array( 'routes', 'blocks', 'structure', 'templates', 'callbacks', 'slow_queries', 'duplicates', 'segments', 'products', 'issues' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_run_id( string $run_id, int $chat_id ): string {
		$clean = sanitize_key( $run_id );
		if ( '' !== $clean ) {
			return $clean;
		}
		return $chat_id > 0 ? 'chat-' . $chat_id : 'adhoc';
	}

	private static function build_uri( string $run_id, string $artifact_id ): string {
		return sprintf( 'pressark://tool-results/%s/%s', sanitize_key( $run_id ), sanitize_key( $artifact_id ) );
	}

	private static function parse_uri( string $uri ): array {
		$matches = array();
		if ( ! preg_match( '#^pressark://tool-results/([^/]+)/([a-z0-9-]+)$#', $uri, $matches ) ) {
			return array();
		}
		return array( 'run_id' => sanitize_key( $matches[1] ), 'artifact_id' => sanitize_key( $matches[2] ) );
	}

	private static function persist_record( array $record, int $ttl ): void {
		$artifact_id = (string) ( $record['artifact_id'] ?? '' );
		if ( '' === $artifact_id ) {
			return;
		}
		wp_cache_set( self::record_cache_key( $artifact_id ), $record, self::CACHE_GROUP, $ttl );
		set_transient( self::record_transient_key( $artifact_id ), $record, $ttl );
	}

	private static function get_record( string $artifact_id ): ?array {
		$cached = wp_cache_get( self::record_cache_key( $artifact_id ), self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$record = get_transient( self::record_transient_key( $artifact_id ) );
		if ( ! is_array( $record ) ) {
			return null;
		}
		wp_cache_set( self::record_cache_key( $artifact_id ), $record, self::CACHE_GROUP, self::DEFAULT_TTL );
		return $record;
	}

	private static function append_to_user_index( int $user_id, array $record, int $ttl ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$index = self::load_user_index( $user_id, false );
		$meta  = array(
			'artifact_id'             => (string) ( $record['artifact_id'] ?? '' ),
			'uri'                     => (string) ( $record['uri'] ?? '' ),
			'user_id'                 => $user_id,
			'run_id'                  => (string) ( $record['run_id'] ?? '' ),
			'tool_name'               => (string) ( $record['tool_name'] ?? '' ),
			'created_at'              => (int) ( $record['created_at'] ?? time() ),
			'bytes'                   => (int) ( $record['bytes'] ?? 0 ),
			'estimated_inline_tokens' => (int) ( $record['estimated_inline_tokens'] ?? 0 ),
			'message'                 => (string) ( $record['message'] ?? '' ),
			'read_meta'               => class_exists( 'PressArk_Read_Metadata' )
				? PressArk_Read_Metadata::sanitize_snapshot( $record['read_meta'] ?? array() )
				: array(),
		);

		$index = array_values( array_filter( $index, static fn( array $item ): bool => (string) ( $item['artifact_id'] ?? '' ) !== $meta['artifact_id'] ) );
		array_unshift( $index, $meta );
		$index = array_slice( $index, 0, self::MAX_INDEX_ITEMS );

		wp_cache_set( self::user_index_cache_key( $user_id ), $index, self::CACHE_GROUP, $ttl );
		set_transient( self::user_index_transient_key( $user_id ), $index, $ttl );
	}

	private static function load_user_index( int $user_id, bool $prune ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$index = wp_cache_get( self::user_index_cache_key( $user_id ), self::CACHE_GROUP );
		if ( ! is_array( $index ) ) {
			$index = get_transient( self::user_index_transient_key( $user_id ) );
		}
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		if ( $prune ) {
			$index = array_values(
				array_filter(
					$index,
					static function ( array $item ) use ( $user_id ): bool {
						return $user_id === (int) ( $item['user_id'] ?? 0 ) && null !== self::get_record( (string) ( $item['artifact_id'] ?? '' ) );
					}
				)
			);
			wp_cache_set( self::user_index_cache_key( $user_id ), $index, self::CACHE_GROUP, self::DEFAULT_TTL );
			set_transient( self::user_index_transient_key( $user_id ), $index, self::DEFAULT_TTL );
		}

		return $index;
	}

	private static function resource_name_from_meta( array $meta ): string {
		$tool = ucwords( str_replace( '_', ' ', sanitize_key( (string) ( $meta['tool_name'] ?? 'tool_result' ) ) ) );
		$run  = substr( (string) ( $meta['run_id'] ?? '' ), 0, 8 );
		return '' !== $run ? sprintf( '%s artifact (%s)', $tool, $run ) : sprintf( '%s artifact', $tool );
	}

	private static function resource_description_from_meta( array $meta ): string {
		$message = trim( sanitize_text_field( (string) ( $meta['message'] ?? '' ) ) );
		$size_kb = round( ( (int) ( $meta['bytes'] ?? 0 ) ) / 1024, 1 );
		$read_meta = class_exists( 'PressArk_Read_Metadata' )
			? PressArk_Read_Metadata::sanitize_snapshot( $meta['read_meta'] ?? array() )
			: array();
		$tags      = array_filter( array(
			sanitize_key( (string) ( $read_meta['freshness'] ?? '' ) ),
			sanitize_key( (string) ( $read_meta['completeness'] ?? '' ) ),
			sanitize_key( (string) ( $read_meta['trust_class'] ?? '' ) ),
		) );
		$tag_text  = empty( $tags ) ? '' : ' [' . implode( ', ', $tags ) . ']';
		return sprintf( '%s [%s KB]%s', '' !== $message ? $message : 'Stored tool result kept off-prompt.', $size_kb, $tag_text );
	}

	private static function record_cache_key( string $artifact_id ): string {
		return self::RECORD_PREFIX . sanitize_key( $artifact_id );
	}

	private static function record_transient_key( string $artifact_id ): string {
		return self::RECORD_PREFIX . sanitize_key( $artifact_id );
	}

	private static function user_index_cache_key( int $user_id ): string {
		return self::USER_INDEX_PREFIX . $user_id;
	}

	private static function user_index_transient_key( int $user_id ): string {
		return self::USER_INDEX_PREFIX . $user_id;
	}
}
