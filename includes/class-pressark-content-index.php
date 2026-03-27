<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Index: automatically indexes site content into searchable chunks.
 * Uses MySQL FULLTEXT search for relevance-ranked results without external services.
 *
 * v4.2.0: Incremental-first sync with cursor-based batching and post-type allowlist.
 */
class PressArk_Content_Index {

	private string $table;
	const MAX_CHUNK_SIZE = 800;
	const OVERLAP_SIZE   = 100;
	const FULL_REBUILD_WATERMARK = '1970-01-01 00:00:00';

	/** Default post types to index when no admin selection exists. */
	const DEFAULT_INDEXED_TYPES = array( 'post', 'page', 'product' );

	/** Post types that must never be indexed, regardless of configuration. */
	const BLOCKED_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'e-landing-page',
		'elementor_library',
		'elementor_snippet',
		'acf-field-group',
		'acf-field',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
	);

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'pressark_content_index';
	}

	// =========================================================================
	// SYNC ENGINE
	// =========================================================================

	/**
	 * Sync posts modified since the last successful sync, using a frozen upper
	 * bound plus modified/ID tuple cursors to avoid importer drift.
	 *
	 * For a full rebuild, set the watermark to epoch first via schedule_full_rebuild().
	 *
	 * @since 4.2.0
	 */
	public function sync_changed( int $batch_size = 50 ): array {
		global $wpdb;

		$post_types = $this->get_indexable_post_types();
		if ( empty( $post_types ) ) {
			$this->disable_indexing();

			return array(
				'total'    => 0,
				'indexed'  => 0,
				'skipped'  => 0,
				'has_more' => false,
			);
		}

		$cursor_id       = (int) get_option( 'pressark_index_cursor', 0 );
		$cursor_modified = (string) get_option( 'pressark_index_cursor_modified', '' );
		$since_modified  = $this->get_last_sync_watermark();
		$since_id        = (int) get_option( 'pressark_index_last_sync_id', 0 );
		$is_rebuild      = ( self::FULL_REBUILD_WATERMARK === $since_modified );
		$is_first_batch  = empty( $cursor_modified );

		if ( $is_first_batch ) {
			$window = $this->prime_sync_window( $post_types, $since_modified, $since_id );
			if ( empty( $window['has_work'] ) ) {
				$this->finish_sync_run( $post_types, $is_rebuild, $since_modified, $since_id );
				$this->update_sync_stats( 0, 0, 0, false, $since_modified, $since_id, true );

				return array(
					'total'    => 0,
					'indexed'  => 0,
					'skipped'  => 0,
					'has_more' => false,
				);
			}
		}

		$upper_modified = (string) get_option( 'pressark_index_run_upper_modified', '' );
		$upper_id       = (int) get_option( 'pressark_index_run_upper_id', 0 );
		$lower_modified = $cursor_modified ?: $since_modified;
		$lower_id       = $cursor_modified ? $cursor_id : $since_id;

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params            = array_merge(
			$post_types,
			array(
				$lower_modified,
				$lower_modified,
				$lower_id,
				$upper_modified,
				$upper_modified,
				$upper_id,
				$batch_size,
			)
		);

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_modified FROM {$wpdb->posts}
			 WHERE post_type IN ({$type_placeholders})
			   AND post_status = 'publish'
			   AND (
					post_modified > %s
					OR ( post_modified = %s AND ID > %d )
			   )
			   AND (
					post_modified < %s
					OR ( post_modified = %s AND ID <= %d )
			   )
			 ORDER BY post_modified ASC, ID ASC
			 LIMIT %d",
			...$params
		) );
		$post_ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );

		$indexed = 0;
		$skipped = 0;

		if ( $post_ids ) {
			update_meta_cache( 'post', $post_ids );
		}

		foreach ( $post_ids as $post_id ) {
			$post   = get_post( $post_id );
			$result = $this->index_post( $post );
			if ( 'indexed' === $result ) {
				$indexed++;
			} elseif ( 'skipped' === $result ) {
				$skipped++;
			}
		}

		$last_processed = ! empty( $rows ) ? end( $rows ) : null;
		$has_more       = count( $post_ids ) === $batch_size;

		if ( $has_more && $last_processed ) {
			update_option( 'pressark_index_cursor', (int) $last_processed->ID, false );
			update_option( 'pressark_index_cursor_modified', $last_processed->post_modified, false );
			wp_schedule_single_event( time() + 15, 'pressark_index_batch', array( $batch_size ) );
		} else {
			$final_modified = $last_processed ? $last_processed->post_modified : $since_modified;
			$final_id       = $last_processed ? (int) $last_processed->ID : $since_id;
			$this->finish_sync_run( $post_types, $is_rebuild, $final_modified, $final_id );
		}

		$this->update_sync_stats(
			count( $post_ids ),
			$indexed,
			$skipped,
			$has_more,
			$last_processed ? $last_processed->post_modified : $since_modified,
			$last_processed ? (int) $last_processed->ID : $since_id,
			$is_first_batch
		);

		return array(
			'total'    => count( $post_ids ),
			'indexed'  => $indexed,
			'skipped'  => $skipped,
			'has_more' => $has_more,
		);
	}

	/**
	 * Schedule a full index rebuild via WP Cron (non-blocking).
	 *
	 * Resets the watermark to epoch so sync_changed() walks all published posts.
	 */
	public function schedule_full_rebuild(): void {
		wp_clear_scheduled_hook( 'pressark_index_batch' );
		$this->reset_active_sync_window();

		if ( ! $this->is_indexing_enabled() ) {
			$this->disable_indexing();
			return;
		}

		update_option( 'pressark_index_last_sync', self::FULL_REBUILD_WATERMARK, false );
		update_option( 'pressark_index_last_sync_id', 0, false );
		$this->update_sync_stats( 0, 0, 0, true, self::FULL_REBUILD_WATERMARK, 0, true );

		wp_schedule_single_event( time() + 2, 'pressark_index_batch', array( 50 ) );
	}

	/**
	 * Schedule an incremental sync via WP Cron (non-blocking).
	 *
	 * Only processes posts modified since the last successful sync.
	 *
	 * @since 4.2.0
	 */
	public function schedule_incremental_sync(): void {
		if ( ! $this->is_indexing_enabled() ) {
			$this->disable_indexing();
			return;
		}

		if ( wp_next_scheduled( 'pressark_index_batch' ) ) {
			return;
		}

		$this->reset_active_sync_window();
		$this->update_sync_stats( 0, 0, 0, true, $this->get_last_sync_watermark(), (int) get_option( 'pressark_index_last_sync_id', 0 ), true );

		wp_schedule_single_event( time() + 2, 'pressark_index_batch', array( 50 ) );
	}

	/**
	 * WP Cron callback: process one batch and auto-schedule next.
	 */
	public function process_index_batch( int $batch_size = 50 ): void {
		$this->sync_changed( $batch_size );
	}

	/**
	 * Run orphan cleanup independently (called by weekly cron).
	 *
	 * @since 4.2.0
	 */
	public function cleanup_orphaned_chunks(): void {
		$this->cleanup_orphans( $this->get_indexable_post_types() );
	}

	/**
	 * Schedule a single-post reindex through one deduped entrypoint.
	 */
	public function schedule_post_reindex( int $post_id, int $delay = 5 ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->remove_post_from_index( $post_id );
			return false;
		}

		if ( ! $this->should_index_post( $post ) ) {
			$this->remove_post_from_index( $post_id );
			return false;
		}

		if ( wp_next_scheduled( 'pressark_reindex_post', array( $post_id ) ) ) {
			return false;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), 'pressark_reindex_post', array( $post_id ) );
		return true;
	}

	/**
	 * Remove every indexed chunk for one post.
	 */
	public function remove_post_from_index( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'post_id' => intval( $post_id ) ) );
	}

	/**
	 * Report current runtime status for UI polling.
	 */
	public function get_runtime_status(): array {
		$stats = get_option( 'pressark_index_stats', array() );

		return array(
			'enabled'              => $this->is_indexing_enabled(),
			'running'              => (bool) ( wp_next_scheduled( 'pressark_index_batch' ) || get_option( 'pressark_index_run_upper_modified', '' ) ),
			'processed_posts'      => (int) ( $stats['total_posts'] ?? 0 ),
			'indexed'              => (int) ( $stats['indexed'] ?? 0 ),
			'skipped'              => (int) ( $stats['skipped'] ?? 0 ),
			'cursor_id'            => (int) get_option( 'pressark_index_cursor', 0 ),
			'cursor_modified'      => (string) get_option( 'pressark_index_cursor_modified', '' ),
			'upper_bound_modified' => (string) get_option( 'pressark_index_run_upper_modified', '' ),
			'upper_bound_id'       => (int) get_option( 'pressark_index_run_upper_id', 0 ),
			'last_sync'            => $this->format_last_sync_for_display(),
		);
	}

	/**
	 * Clear the index and stop all indexing activity.
	 */
	public function disable_indexing(): void {
		wp_clear_scheduled_hook( 'pressark_index_batch' );
		wp_clear_scheduled_hook( 'pressark_reindex_post' );
		$this->reset_active_sync_window();
		update_option( 'pressark_index_last_sync', self::FULL_REBUILD_WATERMARK, false );
		update_option( 'pressark_index_last_sync_id', 0, false );
		$this->clear_index();
		$this->update_sync_stats( 0, 0, 0, false, self::FULL_REBUILD_WATERMARK, 0, true );
	}

	/**
	 * Backward-compatible wrapper for older rebuild callers.
	 */
	public function index_all(): array {
		$this->schedule_full_rebuild();

		return array(
			'scheduled' => true,
			'total'     => 0,
			'indexed'   => 0,
			'skipped'   => 0,
		);
	}

	// =========================================================================
	// POST TYPE MANAGEMENT
	// =========================================================================

	/**
	 * Get the list of post types that should be indexed.
	 *
	 * Resolution: admin option -> defaults (filtered by registered) -> filter hook.
	 * Blocked types are always excluded. An explicit empty selection means indexing
	 * is disabled.
	 *
	 * @since 4.2.0
	 */
	public function get_indexable_post_types(): array {
		$configured = get_option( 'pressark_indexed_post_types', null );

		if ( null === $configured ) {
			$post_types = array_filter( self::DEFAULT_INDEXED_TYPES, 'post_type_exists' );
		} elseif ( is_array( $configured ) ) {
			$post_types = $configured;
		} else {
			$post_types = array();
		}

		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		$post_types = array_diff( $post_types, self::BLOCKED_TYPES );

		/**
		 * Filter the post types included in the content index.
		 *
		 * @since 4.2.0
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = apply_filters( 'pressark_indexed_post_types', $post_types );

		return array_values( array_filter(
			array_diff( (array) $post_types, self::BLOCKED_TYPES ),
			'post_type_exists'
		) );
	}

	/**
	 * Get registered public or UI-exposed post types that could be indexed.
	 *
	 * Returns types not in the blocked list, with labels for display.
	 *
	 * @since 4.2.0
	 * @return array<string, string> slug => label
	 */
	public function get_registered_indexable_types(): array {
		$types = array();
		$all   = array_merge(
			get_post_types( array( 'public' => true ), 'objects' ),
			get_post_types( array( 'show_ui' => true ), 'objects' )
		);

		foreach ( $all as $slug => $obj ) {
			if ( in_array( $slug, self::BLOCKED_TYPES, true ) ) {
				continue;
			}
			$types[ $slug ] = $obj->labels->singular_name ?? $slug;
		}

		return $types;
	}

	/**
	 * Check whether a given post type is currently indexed.
	 *
	 * Used by save_post hooks to avoid scheduling reindex for excluded types.
	 *
	 * @since 4.2.0
	 */
	public function is_type_indexed( string $post_type ): bool {
		return in_array( $post_type, $this->get_indexable_post_types(), true );
	}

	/**
	 * Is indexing enabled for at least one post type.
	 */
	public function is_indexing_enabled(): bool {
		return ! empty( $this->get_indexable_post_types() );
	}

	// =========================================================================
	// SINGLE POST INDEXING
	// =========================================================================

	/**
	 * Index a single post. Skips if content hasn't changed or the post is excluded.
	 */
	public function index_post( $post ): string {
		global $wpdb;

		if ( ! $this->should_index_post( $post ) ) {
			if ( ! empty( $post->ID ) ) {
				$this->remove_post_from_index( (int) $post->ID );
			}

			return 'skipped';
		}

		$full_content = $this->build_indexable_content( $post );
		$content_hash = md5( $full_content );

		$existing_hash = $wpdb->get_var( $wpdb->prepare(
			"SELECT content_hash FROM {$this->table} WHERE post_id = %d AND chunk_index = 0 LIMIT 1",
			$post->ID
		) );

		if ( $existing_hash === $content_hash ) {
			return 'skipped';
		}

		$wpdb->delete( $this->table, array( 'post_id' => $post->ID ) );

		$meta   = $this->build_meta( $post );
		$chunks = $this->chunk_content( $full_content );

		foreach ( $chunks as $i => $chunk ) {
			$result = $wpdb->insert( $this->table, array(
				'post_id'      => $post->ID,
				'post_type'    => $post->post_type,
				'chunk_index'  => $i,
				'title'        => mb_substr( $post->post_title, 0, 500 ),
				'content'      => $chunk,
				'content_hash' => $content_hash,
				'word_count'   => str_word_count( $chunk ),
				'meta_data'    => wp_json_encode( $meta ),
				'indexed_at'   => current_time( 'mysql' ),
			) );

			if ( false === $result || $wpdb->last_error ) {
				PressArk_Error_Tracker::error( 'ContentIndex', 'Index write failed', array( 'post_id' => $post->ID, 'chunk' => $i, 'db_error' => $wpdb->last_error ) );
				continue;
			}
		}

		return 'indexed';
	}

	/**
	 * Re-index a single post (called when content is updated).
	 */
	public function reindex_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $this->should_index_post( $post ) ) {
			$this->index_post( $post );
		} else {
			$this->remove_post_from_index( $post_id );
		}
	}

	// =========================================================================
	// SEARCH
	// =========================================================================

	/**
	 * Search the content index. Returns the most relevant chunks.
	 */
	public function search( string $query, int $limit = 5, ?string $post_type = null ): array {
		global $wpdb;

		if ( empty( $query ) ) {
			return array();
		}

		$query = sanitize_text_field( $query );

		$sql    = "SELECT id, post_id, post_type, chunk_index, title, content, meta_data, indexed_at,
					MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance
				FROM {$this->table}
				WHERE MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE)";
		$params = array( $query, $query );

		if ( $post_type ) {
			$sql     .= ' AND post_type = %s';
			$params[] = sanitize_text_field( $post_type );
		}

		$sql     .= ' ORDER BY relevance DESC LIMIT %d';
		$params[] = intval( $limit );

		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

		if ( empty( $results ) ) {
			return $this->fallback_search( $query, $limit, $post_type );
		}

		return array_map( function ( $row ) {
			$meta = json_decode( $row->meta_data, true );
			return array(
				'post_id'    => (int) $row->post_id,
				'post_type'  => $row->post_type,
				'title'      => $row->title,
				'content'    => $row->content,
				'relevance'  => round( (float) $row->relevance, 4 ),
				'meta'       => $meta,
				'indexed_at' => $row->indexed_at ?? null,
				'age_hours'  => self::compute_age_hours( $row->indexed_at ?? null, $meta['modified'] ?? null ),
				'is_stale'   => self::is_chunk_stale( $row->indexed_at ?? null, $meta['modified'] ?? null ),
			);
		}, $results );
	}

	/**
	 * Get content chunks for a specific post.
	 */
	public function get_post_chunks( int $post_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT content, chunk_index FROM {$this->table} WHERE post_id = %d ORDER BY chunk_index ASC",
			intval( $post_id )
		) );

		return array_map( function ( $row ) {
			return $row->content;
		}, $rows );
	}

	/**
	 * Get relevant context for a user message.
	 * Called by the context builder to enrich AI prompts.
	 */
	public function get_relevant_context( string $message, int $max_tokens = 1500 ): string {
		$message = trim( $message );
		if ( strlen( $message ) < 10 || str_word_count( $message ) < 3 ) {
			return '';
		}

		$skip_patterns = array(
			'/^(hi|hey|hello|thanks|thank you|ok|okay|yes|no|sure|bye|cool|great|perfect|awesome|got it|sounds good|do it|go ahead|proceed|cancel|undo|stop)[\s!?.,]*$/i',
			'/^(how are you|what can you do|who are you|help me)[\s!?]*$/i',
		);
		foreach ( $skip_patterns as $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				return '';
			}
		}

		$results = $this->search( $message, 8 );

		// Filter out posts the current user cannot read.
		$results = array_filter( $results, function ( $result ) {
			return current_user_can( 'read_post', $result['post_id'] );
		} );
		$results = array_values( $results );

		if ( empty( $results ) ) {
			return '';
		}

		$has_stale      = false;
		$context        = "RELEVANT SITE CONTENT (from your indexed pages):\n\n";
		$token_estimate = 0;
		$seen_posts     = array();

		foreach ( $results as $result ) {
			$post_key                = $result['post_id'];
			$seen_posts[ $post_key ] = ( $seen_posts[ $post_key ] ?? 0 ) + 1;
			if ( $seen_posts[ $post_key ] > 2 ) {
				continue;
			}

			$stale_tag = ! empty( $result['is_stale'] ) ? ' [STALE - may have changed since indexing]' : '';
			$age_tag   = '';
			if ( isset( $result['age_hours'] ) && $result['age_hours'] > 0 ) {
				$age_tag = sprintf( ' (indexed %s ago)', self::human_age( $result['age_hours'] ) );
			}
			if ( ! empty( $result['is_stale'] ) ) {
				$has_stale = true;
			}

			$chunk_text = "--- From \"{$result['title']}\" (ID:{$result['post_id']}, {$result['post_type']}){$age_tag}{$stale_tag} ---\n";
			$chunk_text .= $result['content'] . "\n\n";

			$word_count      = str_word_count( $chunk_text );
			$token_estimate += ( $word_count / 0.75 );

			if ( $token_estimate > $max_tokens ) {
				break;
			}

			$context .= $chunk_text;
		}

		if ( $has_stale ) {
			$context = "NOTE: Some indexed content below may be outdated. Use read_content to verify before acting on stale entries.\n\n" . $context;
		}

		return $context;
	}

	// =========================================================================
	// STATS
	// =========================================================================

	/**
	 * Get index statistics.
	 */
	public function get_stats(): array {
		global $wpdb;

		$stats = get_option( 'pressark_index_stats', array() );

		$stats['total_chunks']        = $this->get_total_chunks();
		$stats['total_posts_indexed'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE %d", 1
		) );
		$stats['total_words'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(word_count), 0) FROM {$this->table} WHERE %d", 1
		) );
		$stats['by_type'] = array();

		$types = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_type, COUNT(DISTINCT post_id) as post_count, COUNT(*) as chunk_count
			 FROM {$this->table} WHERE %d GROUP BY post_type", 1
		) );
		foreach ( $types as $type ) {
			$stats['by_type'][ $type->post_type ] = array(
				'posts'  => (int) $type->post_count,
				'chunks' => (int) $type->chunk_count,
			);
		}

		$stats['last_sync']     = $this->format_last_sync_for_display();
		$stats['indexed_types'] = $this->get_indexable_post_types();
		$stats['index_enabled'] = $this->is_indexing_enabled();
		$stats['runtime']       = $this->get_runtime_status();

		return $stats;
	}

	// =========================================================================
	// FRESHNESS HELPERS
	// =========================================================================

	/**
	 * Compute age in hours since indexing.
	 *
	 * @param string|null $indexed_at  MySQL datetime when chunk was indexed.
	 * @param string|null $modified_at MySQL datetime when post was last modified.
	 * @return float Hours since indexing (or modification if newer).
	 */
	public static function compute_age_hours( ?string $indexed_at, ?string $modified_at ): float {
		if ( empty( $indexed_at ) ) {
			return PHP_FLOAT_MAX;
		}

		$indexed_ts = strtotime( $indexed_at );
		$now        = time();

		if ( false === $indexed_ts ) {
			return PHP_FLOAT_MAX;
		}

		if ( ! empty( $modified_at ) ) {
			$modified_ts = strtotime( $modified_at );
			if ( false !== $modified_ts && $modified_ts > $indexed_ts ) {
				return ( $now - $modified_ts ) / 3600.0;
			}
		}

		return ( $now - $indexed_ts ) / 3600.0;
	}

	/**
	 * Determine if a chunk is stale.
	 *
	 * @param string|null $indexed_at  MySQL datetime.
	 * @param string|null $modified_at MySQL datetime.
	 * @return bool
	 */
	public static function is_chunk_stale( ?string $indexed_at, ?string $modified_at ): bool {
		if ( empty( $indexed_at ) ) {
			return true;
		}

		$indexed_ts = strtotime( $indexed_at );
		if ( false === $indexed_ts ) {
			return true;
		}

		if ( ! empty( $modified_at ) ) {
			$modified_ts = strtotime( $modified_at );
			if ( false !== $modified_ts && $modified_ts > $indexed_ts ) {
				return true;
			}
		}

		return ( time() - $indexed_ts ) > ( 48 * 3600 );
	}

	/**
	 * Human-readable age string.
	 */
	public static function human_age( float $hours ): string {
		if ( $hours < 1 ) {
			return round( $hours * 60 ) . 'm';
		}
		if ( $hours < 48 ) {
			return round( $hours ) . 'h';
		}
		$days = $hours / 24;
		if ( $days < 14 ) {
			return round( $days ) . 'd';
		}
		return round( $days / 7 ) . 'w';
	}

	/**
	 * Check how many indexed chunks are currently stale.
	 *
	 * @since 3.3.0
	 * @return array{total:int,stale:int,stale_percent:float}
	 */
	public function get_freshness_stats(): array {
		global $wpdb;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE %d", 1 ) );
		if ( 0 === $total ) {
			return array( 'total' => 0, 'stale' => 0, 'stale_percent' => 0.0 );
		}

		$behind = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table} ci
			 INNER JOIN {$wpdb->posts} p ON ci.post_id = p.ID
			 WHERE p.post_modified > ci.indexed_at AND %d", 1
		) );

		$old = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table} WHERE indexed_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - 48 * 3600 )
		) );

		$stale = max( $behind, $old );

		return array(
			'total'         => $total,
			'stale'         => $stale,
			'stale_percent' => round( ( $stale / $total ) * 100, 1 ),
			'behind_source' => $behind,
			'older_48h'     => $old,
		);
	}

	// =========================================================================
	// PRIVATE HELPERS
	// =========================================================================

	/**
	 * Build the full text to index for a post.
	 */
	private function build_indexable_content( $post ): string {
		$parts = array();

		$parts[] = $post->post_title;

		$content = $post->post_content;
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', $content );
		$parts[] = trim( $content );

		if ( ! empty( $post->post_excerpt ) ) {
			$parts[] = $post->post_excerpt;
		}

		$meta_title = PressArk_SEO_Resolver::read( $post->ID, 'meta_title' );
		if ( $meta_title ) {
			$parts[] = 'SEO Title: ' . $meta_title;
		}

		$meta_desc = PressArk_SEO_Resolver::read( $post->ID, 'meta_description' );
		if ( $meta_desc ) {
			$parts[] = 'Meta Description: ' . $meta_desc;
		}

		if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$short_desc = wp_strip_all_tags( $product->get_short_description() );
				if ( $short_desc ) {
					$parts[] = 'Short Description: ' . $short_desc;
				}
				$parts[] = 'Price: ' . $product->get_price() . ' ' . get_woocommerce_currency();
				$sku     = $product->get_sku();
				if ( $sku ) {
					$parts[] = 'SKU: ' . $sku;
				}

				$cats = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
				if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
					$parts[] = 'Categories: ' . implode( ', ', $cats );
				}

				$tags = wp_get_post_terms( $post->ID, 'product_tag', array( 'fields' => 'names' ) );
				if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
					$parts[] = 'Tags: ' . implode( ', ', $tags );
				}
			}
		}

		if ( 'post' === $post->post_type ) {
			$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
				$parts[] = 'Categories: ' . implode( ', ', $cats );
			}
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
				$tag_names = array_map( function ( $t ) {
					return is_object( $t ) ? $t->name : $t;
				}, $tags );
				$parts[] = 'Tags: ' . implode( ', ', $tag_names );
			}
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	/**
	 * Build metadata for a chunk.
	 */
	private function build_meta( $post ): array {
		$meta = array(
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => get_the_author_meta( 'display_name', $post->post_author ),
		);

		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $post->ID === $front_page_id ) {
			$meta['is_homepage'] = true;
		}

		$meta['word_count']         = str_word_count( wp_strip_all_tags( $post->post_content ) );
		$meta['has_featured_image'] = has_post_thumbnail( $post->ID );

		return $meta;
	}

	/**
	 * Split content into overlapping chunks for better search and context.
	 */
	private function chunk_content( string $text ): array {
		$words       = explode( ' ', $text );
		$total_words = count( $words );

		if ( $total_words <= self::MAX_CHUNK_SIZE ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;

		while ( $start < $total_words ) {
			$end         = min( $start + self::MAX_CHUNK_SIZE, $total_words );
			$chunk_words = array_slice( $words, $start, $end - $start );
			$chunks[]    = implode( ' ', $chunk_words );

			$start += ( self::MAX_CHUNK_SIZE - self::OVERLAP_SIZE );
		}

		return $chunks;
	}

	/**
	 * Fallback search using LIKE when FULLTEXT returns nothing.
	 */
	private function fallback_search( string $query, int $limit, ?string $post_type = null ): array {
		global $wpdb;

		$keywords = array_filter( explode( ' ', $query ), function ( $word ) {
			return strlen( $word ) > 2;
		} );

		if ( empty( $keywords ) ) {
			return array();
		}

		$conditions = array();
		$params     = array();
		foreach ( $keywords as $kw ) {
			$conditions[] = '(title LIKE %s OR content LIKE %s)';
			$like         = '%' . $wpdb->esc_like( $kw ) . '%';
			$params[]     = $like;
			$params[]     = $like;
		}

		$where = implode( ' OR ', $conditions );

		if ( $post_type ) {
			$where    = "($where) AND post_type = %s";
			$params[] = sanitize_text_field( $post_type );
		}

		$params[] = intval( $limit );

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, post_type, title, content, meta_data
			 FROM {$this->table}
			 WHERE $where
			 LIMIT %d",
			...$params
		) );

		return array_map( function ( $row ) {
			$meta = json_decode( $row->meta_data, true );
			return array(
				'post_id'    => (int) $row->post_id,
				'post_type'  => $row->post_type,
				'title'      => $row->title,
				'content'    => $row->content,
				'relevance'  => 0.5,
				'meta'       => $meta,
				'indexed_at' => null,
				'age_hours'  => self::compute_age_hours( null, $meta['modified'] ?? null ),
				'is_stale'   => true,
			);
		}, $results );
	}

	/**
	 * Remove index entries for posts that no longer exist, are unpublished,
	 * or are no longer in the allowlist.
	 */
	private function cleanup_orphans( array $post_types ): void {
		global $wpdb;

		if ( empty( $post_types ) ) {
			$this->clear_index();
			return;
		}

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE ci FROM {$this->table} ci
			 LEFT JOIN {$wpdb->posts} p ON ci.post_id = p.ID
			 WHERE p.ID IS NULL
				 OR p.post_status != 'publish'
				 OR ci.post_type NOT IN ({$type_placeholders})",
			...$post_types
		) );
	}

	/**
	 * Get total number of chunks in the index.
	 */
	private function get_total_chunks(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE %d", 1 ) );
	}

	/**
	 * Decide whether a post is currently indexable.
	 */
	private function should_index_post( $post ): bool {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& $this->is_type_indexed( $post->post_type );
	}

	/**
	 * Freeze the upper bound for the current sync run.
	 *
	 * @return array{has_work:bool,upper_modified?:string,upper_id?:int}
	 */
	private function prime_sync_window( array $post_types, string $since_modified, int $since_id ): array {
		global $wpdb;

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params            = array_merge(
			$post_types,
			array( $since_modified, $since_modified, $since_id )
		);

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ID, post_modified FROM {$wpdb->posts}
			 WHERE post_type IN ({$type_placeholders})
			   AND post_status = 'publish'
			   AND (
					post_modified > %s
					OR ( post_modified = %s AND ID > %d )
			   )
			 ORDER BY post_modified DESC, ID DESC
			 LIMIT 1",
			...$params
		) );

		if ( ! $row ) {
			return array( 'has_work' => false );
		}

		update_option( 'pressark_index_run_upper_modified', $row->post_modified, false );
		update_option( 'pressark_index_run_upper_id', (int) $row->ID, false );

		return array(
			'has_work'       => true,
			'upper_modified' => $row->post_modified,
			'upper_id'       => (int) $row->ID,
		);
	}

	/**
	 * Finish the current sync run and advance the high watermark.
	 */
	private function finish_sync_run( array $post_types, bool $is_rebuild, string $final_modified, int $final_id ): void {
		$this->reset_active_sync_window();

		update_option( 'pressark_index_last_sync', $final_modified, false );
		update_option( 'pressark_index_last_sync_id', $final_id, false );

		if ( $is_rebuild ) {
			$this->cleanup_orphans( $post_types );
		}
	}

	/**
	 * Reset the active run cursor and frozen upper bound.
	 */
	private function reset_active_sync_window(): void {
		$this->reset_sync_progress();
		delete_option( 'pressark_index_run_upper_modified' );
		delete_option( 'pressark_index_run_upper_id' );
	}

	/**
	 * Reset per-run cursor state.
	 */
	private function reset_sync_progress(): void {
		update_option( 'pressark_index_cursor', 0, false );
		delete_option( 'pressark_index_cursor_modified' );
	}

	/**
	 * Update stored sync stats for UI/debugging.
	 */
	private function update_sync_stats( int $processed, int $indexed, int $skipped, bool $running, string $last_sync, int $last_sync_id, bool $reset = false ): void {
		$prev = get_option( 'pressark_index_stats', array(
			'indexed'     => 0,
			'skipped'     => 0,
			'total_posts' => 0,
		) );

		if ( $reset ) {
			$prev = array(
				'indexed'     => 0,
				'skipped'     => 0,
				'total_posts' => 0,
			);
		}

		update_option( 'pressark_index_stats', array(
			'total_posts'  => (int) $prev['total_posts'] + $processed,
			'indexed'      => (int) $prev['indexed'] + $indexed,
			'skipped'      => (int) $prev['skipped'] + $skipped,
			'total_chunks' => $this->get_total_chunks(),
			'last_sync'    => $this->format_last_sync_for_display( $last_sync ),
			'last_sync_id' => $last_sync_id,
			'running'      => $running,
		), false );
	}

	/**
	 * Clear the entire content index table.
	 */
	private function clear_index(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE %d", 1 ) );
	}

	/**
	 * Get the stored high watermark.
	 */
	private function get_last_sync_watermark(): string {
		return (string) get_option( 'pressark_index_last_sync', self::FULL_REBUILD_WATERMARK );
	}

	/**
	 * Format last-sync values for UI output.
	 */
	private function format_last_sync_for_display( ?string $value = null ): string {
		$value = null !== $value ? $value : $this->get_last_sync_watermark();

		if ( ! $this->is_indexing_enabled() ) {
			return __( 'Disabled', 'pressark' );
		}

		if ( empty( $value ) || self::FULL_REBUILD_WATERMARK === $value ) {
			return __( 'Never', 'pressark' );
		}

		return $value;
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Meta keys that trigger a post reindex when updated or added.
	 *
	 * @return array<string>
	 */
	private static function indexed_meta_keys(): array {
		return PressArk_SEO_Resolver::monitored_meta_keys();
	}

	/**
	 * Register all content-index WordPress hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		// Cron scheduling.
		add_action( 'init', array( self::class, 'schedule_cron' ) );

		// Cron callbacks.
		add_action( 'pressark_initial_index', array( self::class, 'handle_initial_index' ) );
		add_action( 'pressark_daily_index_sync', array( self::class, 'handle_daily_sync' ) );
		add_action( 'pressark_weekly_orphan_cleanup', array( self::class, 'handle_orphan_cleanup' ) );
		add_action( 'pressark_index_batch', array( self::class, 'handle_index_batch' ), 10, 1 );
		add_action( 'pressark_reindex_post', array( self::class, 'handle_reindex_post' ) );

		// Post lifecycle hooks.
		add_action( 'save_post', array( self::class, 'handle_save_post' ), 20, 3 );
		add_action( 'trashed_post', array( self::class, 'handle_trashed_post' ) );
		add_action( 'deleted_post', array( self::class, 'handle_deleted_post' ) );

		// Meta change hooks (SEO keys bypass save_post).
		add_action( 'updated_post_meta', array( self::class, 'handle_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'handle_meta_change' ), 10, 4 );

		// WooCommerce product updates.
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_update_product', array( self::class, 'handle_wc_product_update' ) );
		}
	}

	/**
	 * Schedule daily incremental sync and weekly orphan cleanup cron events.
	 *
	 * @since 4.2.0
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'pressark_daily_index_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'pressark_daily_index_sync' );
		}
		if ( ! wp_next_scheduled( 'pressark_weekly_orphan_cleanup' ) ) {
			wp_schedule_event( time(), 'weekly', 'pressark_weekly_orphan_cleanup' );
		}
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_initial_index(): void {
		( new self() )->schedule_full_rebuild();
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_daily_sync(): void {
		( new self() )->schedule_incremental_sync();
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_orphan_cleanup(): void {
		( new self() )->cleanup_orphaned_chunks();
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_index_batch( int $batch_size = 50 ): void {
		( new self() )->process_index_batch( $batch_size );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_reindex_post( int $post_id ): void {
		( new self() )->reindex_post( $post_id );
	}

	/**
	 * Debounced single-post reindex scheduling.
	 *
	 * @since 4.2.0
	 */
	public static function schedule_reindex( int $post_id, int $delay = 5 ): void {
		( new self() )->schedule_post_reindex( $post_id, $delay );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_save_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::schedule_reindex( $post_id );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_trashed_post( int $post_id ): void {
		( new self() )->reindex_post( $post_id );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_deleted_post( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pressark_content_index';
		$wpdb->delete( $table, array( 'post_id' => intval( $post_id ) ) );
	}

	/**
	 * Reindex a post when indexed meta keys are updated or added.
	 *
	 * @since 4.2.0
	 */
	public static function handle_meta_change( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
		if ( in_array( $meta_key, self::indexed_meta_keys(), true ) ) {
			self::schedule_reindex( $post_id );
		}
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_wc_product_update( int $product_id ): void {
		self::schedule_reindex( $product_id );
	}
}
