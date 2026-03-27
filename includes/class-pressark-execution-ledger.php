<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable execution ledger for multi-step agent runs.
 *
 * The ledger is persisted inside the checkpoint so continuation runs do not
 * have to infer progress from prose alone. It tracks:
 * - the original source request
 * - a compact task list with done/pending state
 * - the current target created or selected during the run
 * - receipts for already-completed side effects
 *
 * @since 3.7.5
 */
class PressArk_Execution_Ledger {

	private const MAX_TASKS    = 8;
	private const MAX_RECEIPTS = 12;

	/**
	 * Normalize and sanitize ledger data from storage.
	 */
	public static function sanitize( array $raw ): array {
		$tasks = array();
		foreach ( array_slice( $raw['tasks'] ?? array(), 0, self::MAX_TASKS ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$status = sanitize_key( $task['status'] ?? 'pending' );
			if ( ! in_array( $status, array( 'pending', 'done' ), true ) ) {
				$status = 'pending';
			}

			$tasks[] = array(
				'key'      => sanitize_key( $task['key'] ?? '' ),
				'label'    => sanitize_text_field( $task['label'] ?? '' ),
				'status'   => $status,
				'evidence' => sanitize_text_field( $task['evidence'] ?? '' ),
			);
		}

		$receipts = array();
		foreach ( array_slice( $raw['receipts'] ?? array(), 0, self::MAX_RECEIPTS ) as $receipt ) {
			if ( ! is_array( $receipt ) ) {
				continue;
			}

			$receipts[] = array(
				'tool'      => sanitize_key( $receipt['tool'] ?? '' ),
				'summary'   => sanitize_text_field( $receipt['summary'] ?? '' ),
				'post_id'   => absint( $receipt['post_id'] ?? 0 ),
				'post_title'=> sanitize_text_field( $receipt['post_title'] ?? '' ),
				'url'       => esc_url_raw( $receipt['url'] ?? '' ),
			);
		}

		$request_counts = array();
		foreach ( (array) ( $raw['request_counts'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( null === $value || '' === $value ) {
				$request_counts[ $key ] = null;
				continue;
			}
			$request_counts[ $key ] = max( 0, absint( $value ) );
		}

		return array(
			'source_message' => sanitize_text_field( $raw['source_message'] ?? '' ),
			'goal_hash'      => sanitize_text_field( $raw['goal_hash'] ?? '' ),
			'request_counts' => $request_counts,
			'tasks'          => $tasks,
			'receipts'       => $receipts,
			'current_target' => self::sanitize_target( $raw['current_target'] ?? array() ),
			'updated_at'     => sanitize_text_field( $raw['updated_at'] ?? '' ),
		);
	}

	/**
	 * Whether the ledger contains any meaningful execution state.
	 */
	public static function is_empty( array $ledger ): bool {
		$ledger = self::sanitize( $ledger );

		return empty( $ledger['source_message'] )
			&& empty( $ledger['goal_hash'] )
			&& self::request_counts_are_empty( $ledger['request_counts'] ?? array() )
			&& empty( $ledger['tasks'] )
			&& empty( $ledger['receipts'] )
			&& self::target_is_empty( $ledger['current_target'] ?? array() )
			&& empty( $ledger['updated_at'] );
	}

	/**
	 * Merge a newer client copy into the server copy without losing receipts.
	 */
	public static function merge( array $server, array $client ): array {
		$server = self::sanitize( $server );
		$client = self::sanitize( $client );

		if ( empty( $server['source_message'] ) ) {
			return $client;
		}
		if ( empty( $client['source_message'] ) ) {
			return $server;
		}
		if ( $server['goal_hash'] && $client['goal_hash'] && $server['goal_hash'] !== $client['goal_hash'] ) {
			return $server;
		}

		$merged = $server;

		if ( empty( $merged['current_target']['post_id'] ) && ! empty( $client['current_target']['post_id'] ) ) {
			$merged['current_target'] = $client['current_target'];
		}

		$task_map = array();
		foreach ( $merged['tasks'] as $task ) {
			$task_map[ $task['key'] ] = $task;
		}
		foreach ( $client['tasks'] as $task ) {
			if ( empty( $task['key'] ) ) {
				continue;
			}

			if ( ! isset( $task_map[ $task['key'] ] ) ) {
				$task_map[ $task['key'] ] = $task;
				continue;
			}

			if ( 'done' === $task['status'] ) {
				$task_map[ $task['key'] ]['status'] = 'done';
				if ( ! empty( $task['evidence'] ) ) {
					$task_map[ $task['key'] ]['evidence'] = $task['evidence'];
				}
			}
		}
		$merged['tasks'] = array_values( $task_map );

		foreach ( $client['receipts'] as $receipt ) {
			$merged = self::append_receipt( $merged, $receipt['tool'], $receipt['summary'], $receipt );
		}

		$merged['updated_at'] = gmdate( 'c' );
		return self::sanitize( $merged );
	}

	/**
	 * Initialize or refresh the ledger for the current request.
	 */
	public static function bootstrap( array $ledger, string $message ): array {
		$ledger        = self::sanitize( $ledger );
		$is_continue   = self::is_continuation_message( $message );
		$source        = self::normalize_source_message( $message );
		$current_hash  = $source ? md5( $source ) : '';
		$existing_hash = $ledger['goal_hash'] ?? '';

		if ( $is_continue && ! empty( $ledger['source_message'] ) ) {
			return $ledger;
		}

		if ( empty( $source ) ) {
			return $ledger;
		}

		if ( empty( $ledger['source_message'] ) || ( $existing_hash && $existing_hash !== $current_hash ) ) {
			$ledger = array(
				'source_message' => $source,
				'goal_hash'      => $current_hash,
				'request_counts' => self::extract_request_counts( $source ),
				'tasks'          => self::extract_tasks( $source ),
				'receipts'       => array(),
				'current_target' => array(),
				'updated_at'     => gmdate( 'c' ),
			);
		} else {
			$ledger['source_message'] = $source;
			$ledger['goal_hash']      = $current_hash;
			if ( empty( $ledger['tasks'] ) ) {
				$ledger['tasks'] = self::extract_tasks( $source );
			}
			if ( empty( $ledger['request_counts'] ) ) {
				$ledger['request_counts'] = self::extract_request_counts( $source );
			}
			$ledger['updated_at'] = gmdate( 'c' );
		}

		return self::sanitize( $ledger );
	}

	/**
	 * Record a read result that meaningfully advances target selection.
	 */
	public static function record_read( array $ledger, string $tool_name, array $args, array $result ): array {
		$ledger = self::sanitize( $ledger );

		if ( empty( $ledger['tasks'] ) ) {
			return $ledger;
		}

		if ( in_array( $tool_name, array( 'read_content', 'search_content', 'list_posts' ), true ) ) {
			$title = '';
			$id    = 0;
			$type  = '';

			if ( ! empty( $result['data']['title'] ) ) {
				$title = (string) $result['data']['title'];
			}
			if ( ! empty( $result['data']['id'] ) ) {
				$id = (int) $result['data']['id'];
			}
			if ( ! empty( $result['data']['type'] ) ) {
				$type = (string) $result['data']['type'];
			}

			if ( $id > 0 || $title ) {
				$evidence = $title ? $title : 'item #' . $id;
				if ( $id > 0 ) {
					$evidence .= ' (#' . $id . ')';
				}
				if ( $type ) {
					$evidence .= ' [' . $type . ']';
				}
				$ledger = self::mark_task_done( $ledger, 'select_source', 'Selected source: ' . $evidence );
			}
		}

		$ledger['updated_at'] = gmdate( 'c' );
		return $ledger;
	}

	/**
	 * Record a completed write or applied preview result.
	 */
	public static function record_write( array $ledger, string $tool_name, array $args, array $result ): array {
		$ledger  = self::sanitize( $ledger );
		$success = ! empty( $result['success'] ) || ! empty( $result['skipped_duplicate'] );

		if ( ! $success ) {
			return $ledger;
		}

		$tool_name = sanitize_key( $tool_name );
		$target    = self::extract_target( $result, $args );
		$receipt   = self::receipt_summary( $tool_name, $target, $result, $args );
		if ( ! empty( $target['post_id'] ) ) {
			$ledger['current_target'] = $target;
		}

		switch ( $tool_name ) {
			case 'create_post':
			case 'elementor_create_page':
				$ledger = self::ensure_task( $ledger, 'create_post', 'Create the requested blog post or page' );
				$ledger = self::mark_task_done(
					$ledger,
					'select_source',
					'Selected source was used to create'
					. ( ! empty( $target['post_title'] ) ? ' "' . $target['post_title'] . '"' : ' the requested content' )
					. ( ! empty( $target['post_id'] ) ? ' (#' . (int) $target['post_id'] . ')' : '' )
				);
				$ledger = self::mark_task_done( $ledger, 'create_post', $receipt );

				if ( self::content_has_cta( $args['content'] ?? '' ) ) {
					$ledger = self::ensure_task( $ledger, 'add_cta', 'Add a call to action with the requested link' );
					$ledger = self::mark_task_done( $ledger, 'add_cta', 'CTA and link were included in the drafted content.' );
				}

				if ( in_array( $args['status'] ?? '', array( 'publish', 'future' ), true )
					|| 'publish' === ( $target['post_status'] ?? '' ) ) {
					$ledger = self::ensure_task( $ledger, 'publish_content', 'Publish the content' );
					$ledger = self::mark_task_done( $ledger, 'publish_content', 'Content status is set to ' . ( $target['post_status'] ?? ( $args['status'] ?? 'publish' ) ) . '.' );
				}

				if ( self::has_inline_seo_payload( $args ) ) {
					$ledger = self::ensure_task( $ledger, 'optimize_seo', 'Optimize the content for SEO' );

					$seo_verified = self::inline_seo_write_verified( $result );
					if ( false !== $seo_verified ) {
						$ledger = self::mark_task_done(
							$ledger,
							'optimize_seo',
							true === $seo_verified
								? 'SEO metadata was verified during content creation.'
								: 'SEO metadata was included in the content creation payload.'
						);
					}
				}
				break;

			case 'edit_content':
				if ( self::content_has_cta( $args['changes']['content'] ?? '' ) || self::content_has_cta( $args['content'] ?? '' ) ) {
					$ledger = self::ensure_task( $ledger, 'add_cta', 'Add a call to action with the requested link' );
					$ledger = self::mark_task_done( $ledger, 'add_cta', 'CTA and link were added to the content.' );
				}

				if ( ! empty( $args['changes']['status'] ) && 'publish' === $args['changes']['status'] ) {
					$ledger = self::ensure_task( $ledger, 'publish_content', 'Publish the content' );
					$ledger = self::mark_task_done( $ledger, 'publish_content', 'Content status updated to publish.' );
				}
				break;

			case 'update_meta':
			case 'fix_seo':
				if ( self::looks_like_seo_write( $args ) ) {
					$ledger = self::ensure_task( $ledger, 'optimize_seo', 'Optimize the content for SEO' );
					$ledger = self::mark_task_done( $ledger, 'optimize_seo', $receipt );
				}
				break;
		}

		// Generic single-step edit requests fall back to fulfill_request. Once a
		// write succeeded, that placeholder task should be considered complete so
		// confirmation flows do not auto-resume into an unrelated second AI turn.
		if ( self::has_pending_task( $ledger, 'fulfill_request' ) ) {
			$ledger = self::mark_task_done( $ledger, 'fulfill_request', $receipt );
		}

		$ledger = self::append_receipt( $ledger, $tool_name, $receipt, $target );
		$ledger['updated_at'] = gmdate( 'c' );

		return $ledger;
	}

	/**
	 * Record all tool calls from a kept preview session.
	 */
	public static function record_preview_result( array $ledger, array $tool_calls, array $result ): array {
		$ledger = self::sanitize( $ledger );

		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}

			$name = sanitize_key( $tool_call['name'] ?? $tool_call['type'] ?? '' );
			$args = is_array( $tool_call['arguments'] ?? null )
				? $tool_call['arguments']
				: ( $tool_call['params'] ?? array() );

			$ledger = self::record_write( $ledger, $name, is_array( $args ) ? $args : array(), $result );
		}

		return $ledger;
	}

	/**
	 * Compact execution context for checkpoint prompt injection.
	 *
	 * @return string[] Text lines ready to append to the checkpoint header.
	 */
	public static function build_context_lines( array $ledger ): array {
		$ledger = self::sanitize( $ledger );
		$lines  = array();

		if ( ! empty( $ledger['source_message'] ) ) {
			$lines[] = 'SOURCE REQUEST: ' . self::compact_text( (string) $ledger['source_message'], 180 );
		}

		if ( ! empty( $ledger['current_target']['post_id'] ) || ! empty( $ledger['current_target']['post_title'] ) ) {
			$target = $ledger['current_target'];
			$label  = trim( ( $target['post_title'] ?? '' ) . ( ! empty( $target['post_id'] ) ? ' (#' . (int) $target['post_id'] . ')' : '' ) );
			$lines[] = 'CURRENT TARGET: ' . $label;
		}

		$done    = self::task_labels( $ledger, 'done' );
		$pending = self::task_labels( $ledger, 'pending' );

		if ( ! empty( $done ) ) {
			$lines[] = 'COMPLETED TASKS: ' . implode( '; ', $done );
		}
		if ( ! empty( $pending ) ) {
			$lines[] = 'REMAINING TASKS: ' . implode( '; ', $pending );
		}
		if ( self::should_scope_seo_to_current_target( $ledger ) ) {
			$lines[] = 'SEO SCOPE: Optimize only the current target unless the user explicitly requested site-wide SEO.';
		}

		if ( ! empty( $ledger['receipts'] ) ) {
			$recent = array_slice( $ledger['receipts'], -3 );
			$parts  = array();
			foreach ( $recent as $receipt ) {
				if ( empty( $receipt['summary'] ) ) {
					continue;
				}
				$parts[] = $receipt['summary'];
			}
			if ( ! empty( $parts ) ) {
				$lines[] = 'RECENT RECEIPTS: ' . implode( '; ', $parts );
			}
		}

		return $lines;
	}

	/**
	 * Stronger server-authored guard for continuation runs.
	 */
	public static function build_runtime_guard( array $ledger ): string {
		$ledger = self::sanitize( $ledger );
		if ( empty( $ledger['tasks'] ) && empty( $ledger['current_target']['post_id'] ) ) {
			return '';
		}

		$done    = self::task_labels( $ledger, 'done' );
		$pending = self::task_labels( $ledger, 'pending' );
		$target  = $ledger['current_target'];

		$lines   = array();
		$lines[] = '## Execution Guard';
		$lines[] = 'This request is already in progress. Resume from the existing state instead of starting over.';
		if ( ! empty( $ledger['source_message'] ) ) {
			$lines[] = 'Original request: ' . self::compact_text( (string) $ledger['source_message'], 180 );
		}
		if ( ! empty( $done ) ) {
			$lines[] = 'Completed: ' . implode( '; ', $done );
		}
		if ( ! empty( $pending ) ) {
			$lines[] = 'Remaining: ' . implode( '; ', $pending );
		}
		if ( ! empty( $target['post_id'] ) ) {
			$lines[] = 'Use the existing target post #' . (int) $target['post_id']
				. ( ! empty( $target['post_title'] ) ? ' "' . $target['post_title'] . '"' : '' )
				. ' for the remaining work.';
		}
		if ( self::has_pending_task( $ledger, 'optimize_seo' ) && ! empty( $target['post_id'] ) ) {
			$lines[] = 'Remaining SEO work is actionable on the existing target. Apply the needed SEO metadata directly with fix_seo or update_meta on that post. Do not run analyze_seo first unless the user explicitly asked to audit, analyze, check, or report on SEO.';
		} elseif ( self::should_scope_seo_to_current_target( $ledger ) ) {
			$lines[] = 'If the remaining work involves SEO, limit it to that target post unless the user explicitly asked for site-wide SEO.';
		}
		$lines[] = 'Do not repeat completed non-idempotent actions such as create_post unless the user explicitly asks for another one.';

		return implode( "\n", $lines );
	}

	/**
	 * Return the current target post ID if the execution ledger has one.
	 */
	public static function current_target_post_id( array $ledger ): int {
		$ledger = self::sanitize( $ledger );
		return (int) ( $ledger['current_target']['post_id'] ?? 0 );
	}

	/**
	 * Return a compact progress snapshot for continuation control.
	 */
	public static function progress_snapshot( array $ledger ): array {
		$ledger           = self::sanitize( $ledger );
		$completed_tasks  = array();
		$remaining_tasks  = array();
		$next_task        = null;

		foreach ( $ledger['tasks'] as $task ) {
			if ( 'done' === ( $task['status'] ?? '' ) ) {
				$completed_tasks[] = $task;
				continue;
			}

			$remaining_tasks[] = $task;
			if ( null === $next_task ) {
				$next_task = $task;
			}
		}

		$is_complete = ! empty( $ledger['tasks'] ) && empty( $remaining_tasks );

		return array(
			'total_tasks'        => count( $ledger['tasks'] ),
			'completed_count'    => count( $completed_tasks ),
			'remaining_count'    => count( $remaining_tasks ),
			'completed_labels'   => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$completed_tasks
			) ) ),
			'remaining_labels'   => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$remaining_tasks
			) ) ),
			'next_task_key'      => sanitize_key( $next_task['key'] ?? '' ),
			'next_task_label'    => sanitize_text_field( $next_task['label'] ?? '' ),
			'is_complete'        => $is_complete,
			'should_auto_resume' => ! $is_complete && ! empty( $remaining_tasks ),
		);
	}

	public static function has_remaining_tasks( array $ledger ): bool {
		$progress = self::progress_snapshot( $ledger );
		return (int) ( $progress['remaining_count'] ?? 0 ) > 0;
	}

	/**
	 * Normalize continuation tool arguments so target-scoped SEO work cannot
	 * silently widen from a single created post into site-wide analysis.
	 */
	public static function normalize_scoped_tool_args( array $ledger, string $tool_name, array $args = array() ): array {
		$ledger    = self::sanitize( $ledger );
		$tool_name = sanitize_key( $tool_name );
		$target_id = self::current_target_post_id( $ledger );

		if ( $target_id <= 0 || ! self::should_scope_seo_to_current_target( $ledger ) ) {
			return $args;
		}

		switch ( $tool_name ) {
			case 'analyze_seo':
				$scope = $args['post_id'] ?? ( $args['scope'] ?? null );
				if ( null === $scope || '' === $scope || in_array( $scope, array( 'all', 'site', '*' ), true ) || ( is_numeric( $scope ) && (int) $scope !== $target_id ) ) {
					$args['post_id'] = $target_id;
					unset( $args['scope'] );
				}
				break;

			case 'read_content':
				if ( ! empty( $args['post_id'] ) && (int) $args['post_id'] !== $target_id ) {
					$args['post_id'] = $target_id;
				}
				break;

			case 'fix_seo':
				if ( isset( $args['post_id'] ) ) {
					$scope = $args['post_id'];
					if ( '' === $scope || in_array( $scope, array( 'all', 'site', '*' ), true ) || ( is_numeric( $scope ) && (int) $scope !== $target_id ) ) {
						$args['post_id'] = $target_id;
					}
				}

				if ( ! empty( $args['fixes'] ) && is_array( $args['fixes'] ) ) {
					$filtered = array();
					foreach ( $args['fixes'] as $fix ) {
						if ( ! is_array( $fix ) ) {
							continue;
						}

						$fix_post_id = (int) ( $fix['post_id'] ?? 0 );
						if ( $fix_post_id > 0 && $fix_post_id !== $target_id ) {
							continue;
						}

						if ( $fix_post_id <= 0 ) {
							$fix['post_id'] = $target_id;
						}

						$filtered[] = $fix;
					}

					$args['fixes'] = $filtered;
				}
				break;
		}

		return $args;
	}

	/**
	 * Decide whether a proposed tool call should be skipped as a duplicate.
	 */
	public static function should_skip_duplicate( array $ledger, string $tool_name, array $args = array() ): bool {
		$ledger = self::sanitize( $ledger );
		$tool_name = sanitize_key( $tool_name );

		if ( in_array( $tool_name, array( 'fix_seo', 'update_meta' ), true )
			&& self::is_seo_task_complete( $ledger ) ) {
			return true;
		}

		if ( 'create_post' === $tool_name ) {
			$requested = $ledger['request_counts']['create_post'] ?? null;
			if ( 1 !== $requested ) {
				return false;
			}

			if ( empty( $ledger['current_target']['post_id'] ) ) {
				return false;
			}

			foreach ( $ledger['receipts'] as $receipt ) {
				if ( 'create_post' === ( $receipt['tool'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build the synthetic tool result used when a duplicate write is skipped.
	 */
	public static function duplicate_skip_result( array $ledger, string $tool_name ): array {
		$ledger = self::sanitize( $ledger );
		$target = $ledger['current_target'];
		$title  = $target['post_title'] ?? '';
		$post_id = (int) ( $target['post_id'] ?? 0 );

		$message = 'Skipped duplicate ' . sanitize_key( $tool_name ) . ' because this request already created a target';
		if ( $title ) {
			$message .= ' ("' . $title . '")';
		}
		if ( $post_id > 0 ) {
			$message .= ' [post_id=' . $post_id . ']';
		}
		$message .= '. Continue from the existing target and finish the remaining tasks.';

		return array(
			'success'          => true,
			'skipped_duplicate'=> true,
			'message'          => $message,
			'data'             => array(
				'existing_target' => $target,
				'remaining_tasks' => self::task_labels( $ledger, 'pending' ),
			),
		);
	}

	private static function extract_tasks( string $message ): array {
		$msg   = strtolower( $message );
		$tasks = array();

		if ( preg_match( '/\b(random|pick|select|choose)\b.*\bproduct\b/', $msg ) ) {
			$tasks[] = self::task( 'select_source', 'Select a source product to feature' );
		}

		if ( preg_match( '/\b(create|write|draft|generate|compose|publish)\b.*\b(blog post|post|article|page)\b/', $msg )
			|| preg_match( '/\bblog post\b/', $msg ) ) {
			$tasks[] = self::task( 'create_post', 'Create the requested blog post or page' );
		}

		if ( preg_match( '/\b(call to action|cta)\b/', $msg )
			|| preg_match( '/\b(link|url)\b.*\bproduct\b/', $msg ) ) {
			$tasks[] = self::task( 'add_cta', 'Add a call to action with the requested link' );
		}

		if ( preg_match( '/\bseo\b|\bmeta\b|\bslug\b|\bsearch engine\b|\brank\b/', $msg ) ) {
			$tasks[] = self::task( 'optimize_seo', 'Optimize the content for SEO' );
		}

		if ( preg_match( '/\bpublish\b|\blive\b/', $msg ) ) {
			$tasks[] = self::task( 'publish_content', 'Publish the content' );
		}

		if ( empty( $tasks ) ) {
			$tasks[] = self::task( 'fulfill_request', 'Complete the requested work without repeating finished steps' );
		}

		return array_slice( self::dedupe_tasks( $tasks ), 0, self::MAX_TASKS );
	}

	private static function extract_request_counts( string $message ): array {
		$msg = strtolower( $message );

		if ( preg_match( '/\bcreate\s+(\d+)\s+(blog posts|posts|articles|pages)\b/', $msg, $m ) ) {
			return array( 'create_post' => (int) $m[1] );
		}

		if ( preg_match( '/\bcreate\s+(two|three|four|five)\s+(blog posts|posts|articles|pages)\b/', $msg, $m ) ) {
			$map = array(
				'two'   => 2,
				'three' => 3,
				'four'  => 4,
				'five'  => 5,
			);
			return array( 'create_post' => $map[ $m[1] ] ?? null );
		}

		if ( preg_match( '/\b(a|an|one)\s+(blog post|post|article|page)\b/', $msg )
			|| preg_match( '/\bcreate\b.*\b(blog post|article|page)\b/', $msg ) ) {
			if ( ! preg_match( '/\b(blog posts|posts|articles|pages)\b/', $msg ) ) {
				return array( 'create_post' => 1 );
			}
		}

		return array();
	}

	private static function normalize_source_message( string $message ): string {
		$message = trim( preg_replace( '/^\[(?:Continue|Confirmed)\]\s*/i', '', $message ) );
		$message = preg_replace( '/Please continue with the remaining steps from my original request\.?$/i', '', $message );
		return trim( sanitize_text_field( $message ) );
	}

	private static function is_continuation_message( string $message ): bool {
		return 1 === preg_match( '/^\[(?:Continue|Confirmed)\]/i', trim( $message ) );
	}

	private static function sanitize_target( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array(
			'post_id'     => absint( $raw['post_id'] ?? 0 ),
			'post_title'  => sanitize_text_field( $raw['post_title'] ?? '' ),
			'post_type'   => sanitize_key( $raw['post_type'] ?? '' ),
			'post_status' => sanitize_key( $raw['post_status'] ?? '' ),
			'url'         => esc_url_raw( $raw['url'] ?? '' ),
		);
	}

	private static function request_counts_are_empty( array $request_counts ): bool {
		foreach ( $request_counts as $value ) {
			if ( null !== $value && absint( $value ) > 0 ) {
				return false;
			}
		}

		return true;
	}

	private static function target_is_empty( array $target ): bool {
		return empty( absint( $target['post_id'] ?? 0 ) )
			&& empty( $target['post_title'] ?? '' )
			&& empty( $target['post_type'] ?? '' )
			&& empty( $target['post_status'] ?? '' )
			&& empty( $target['url'] ?? '' );
	}

	private static function task( string $key, string $label ): array {
		return array(
			'key'      => sanitize_key( $key ),
			'label'    => sanitize_text_field( $label ),
			'status'   => 'pending',
			'evidence' => '',
		);
	}

	private static function dedupe_tasks( array $tasks ): array {
		$deduped = array();
		foreach ( $tasks as $task ) {
			$key = $task['key'] ?? '';
			if ( empty( $key ) || isset( $deduped[ $key ] ) ) {
				continue;
			}
			$deduped[ $key ] = $task;
		}
		return array_values( $deduped );
	}

	private static function ensure_task( array $ledger, string $key, string $label ): array {
		foreach ( $ledger['tasks'] as $task ) {
			if ( $key === ( $task['key'] ?? '' ) ) {
				return $ledger;
			}
		}

		$ledger['tasks'][] = self::task( $key, $label );
		$ledger['tasks']   = array_slice( self::dedupe_tasks( $ledger['tasks'] ), 0, self::MAX_TASKS );
		return $ledger;
	}

	private static function mark_task_done( array $ledger, string $key, string $evidence ): array {
		foreach ( $ledger['tasks'] as &$task ) {
			if ( $key !== ( $task['key'] ?? '' ) ) {
				continue;
			}
			$task['status']   = 'done';
			$task['evidence'] = sanitize_text_field( $evidence );
			return $ledger;
		}
		unset( $task );

		$ledger = self::ensure_task( $ledger, $key, $key );
		return self::mark_task_done( $ledger, $key, $evidence );
	}

	private static function task_labels( array $ledger, string $status ): array {
		$labels = array();
		foreach ( $ledger['tasks'] as $task ) {
			if ( $status !== ( $task['status'] ?? '' ) ) {
				continue;
			}
			$label = $task['label'] ?? '';
			if ( 'done' === $status && ! empty( $task['evidence'] ) ) {
				$label .= ' (' . $task['evidence'] . ')';
			}
			if ( $label ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	private static function append_receipt( array $ledger, string $tool, string $summary, array $target = array() ): array {
		$tool    = sanitize_key( $tool );
		$summary = sanitize_text_field( $summary );

		if ( '' === $tool && '' === $summary ) {
			return $ledger;
		}

		foreach ( $ledger['receipts'] as $receipt ) {
			if ( $receipt['tool'] === $tool && $receipt['summary'] === $summary ) {
				return $ledger;
			}
		}

		$ledger['receipts'][] = array(
			'tool'       => $tool,
			'summary'    => $summary,
			'post_id'    => absint( $target['post_id'] ?? 0 ),
			'post_title' => sanitize_text_field( $target['post_title'] ?? '' ),
			'url'        => esc_url_raw( $target['url'] ?? '' ),
		);
		$ledger['receipts'] = array_slice( $ledger['receipts'], -self::MAX_RECEIPTS );

		return $ledger;
	}

	private static function compact_text( string $text, int $max_chars ): string {
		$text = trim( sanitize_text_field( $text ) );
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, max( 0, $max_chars - 3 ) ) ) . '...';
	}

	private static function extract_target( array $result, array $args ): array {
		$target = self::sanitize_target( $result['current_target'] ?? array() );

		if ( empty( $target['post_id'] ) && ! empty( $result['post_id'] ) ) {
			$target['post_id'] = absint( $result['post_id'] );
		}
		if ( empty( $target['post_title'] ) && ! empty( $result['post_title'] ) ) {
			$target['post_title'] = sanitize_text_field( $result['post_title'] );
		}
		if ( empty( $target['post_type'] ) && ! empty( $result['post_type'] ) ) {
			$target['post_type'] = sanitize_key( $result['post_type'] );
		}
		if ( empty( $target['post_status'] ) && ! empty( $result['post_status'] ) ) {
			$target['post_status'] = sanitize_key( $result['post_status'] );
		}
		if ( empty( $target['url'] ) && ! empty( $result['url'] ) ) {
			$target['url'] = esc_url_raw( $result['url'] );
		}

		if ( empty( $target['post_title'] ) && ! empty( $args['title'] ) ) {
			$target['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( empty( $target['post_type'] ) && ! empty( $args['post_type'] ) ) {
			$target['post_type'] = sanitize_key( $args['post_type'] );
		}
		if ( empty( $target['post_status'] ) && ! empty( $args['status'] ) ) {
			$target['post_status'] = sanitize_key( $args['status'] );
		}
		if ( empty( $target['post_id'] ) && ! empty( $args['post_id'] ) ) {
			$target['post_id'] = absint( $args['post_id'] );
		}

		return self::sanitize_target( $target );
	}

	private static function looks_like_seo_write( array $args ): bool {
		$keys = array();

		foreach ( (array) ( $args['changes'] ?? array() ) as $key => $value ) {
			$keys[] = strtolower( (string) $key );
		}
		foreach ( (array) ( $args['meta'] ?? array() ) as $key => $value ) {
			$keys[] = strtolower( (string) $key );
		}
		foreach ( (array) ( $args['fixes'] ?? array() ) as $fix ) {
			if ( ! is_array( $fix ) ) {
				continue;
			}
			foreach ( array_keys( $fix ) as $key ) {
				$keys[] = strtolower( (string) $key );
			}
		}

		foreach ( $keys as $key ) {
			if ( false !== strpos( $key, 'meta' ) || false !== strpos( $key, 'seo' ) || false !== strpos( $key, 'slug' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_inline_seo_payload( array $args ): bool {
		$seo_keys = array( 'meta_title', 'meta_description', 'og_title', 'og_description', 'og_image', 'focus_keyword', 'slug' );

		foreach ( $seo_keys as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				return true;
			}
		}

		$extra_meta = $args['extra_meta'] ?? array();
		if ( is_array( $extra_meta ) ) {
			foreach ( $seo_keys as $key ) {
				if ( ! empty( $extra_meta[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function inline_seo_write_verified( array $result ): ?bool {
		$message = strtolower( trim( (string) ( $result['verification']['message'] ?? '' ) ) );
		if ( '' === $message || false === strpos( $message, 'seo metadata' ) ) {
			return null;
		}

		if ( false !== strpos( $message, 'seo metadata verified' ) ) {
			return true;
		}

		if ( false !== strpos( $message, 'seo metadata partially verified' )
			|| false !== strpos( $message, 'seo metadata may not have been applied' ) ) {
			return false;
		}

		return null;
	}

	private static function should_scope_seo_to_current_target( array $ledger ): bool {
		$target_id = (int) ( $ledger['current_target']['post_id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return false;
		}

		$requested_create_count = $ledger['request_counts']['create_post'] ?? 1;
		if ( null !== $requested_create_count && $requested_create_count > 1 ) {
			return false;
		}

		if ( self::requested_sitewide_seo( $ledger['source_message'] ?? '' ) ) {
			return false;
		}

		foreach ( $ledger['tasks'] as $task ) {
			if ( 'optimize_seo' === ( $task['key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function requested_sitewide_seo( string $message ): bool {
		$message = strtolower( $message );
		if ( '' === $message || false === strpos( $message, 'seo' ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:site[-\s]?wide|entire site|whole site|all pages|all posts|every page|every post|across the site)\b/',
			$message
		);
	}

	private static function is_seo_task_complete( array $ledger ): bool {
		foreach ( $ledger['tasks'] as $task ) {
			if ( 'optimize_seo' === ( $task['key'] ?? '' ) && 'done' === ( $task['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_pending_task( array $ledger, string $key ): bool {
		$key = sanitize_key( $key );
		foreach ( $ledger['tasks'] as $task ) {
			if ( $key === ( $task['key'] ?? '' ) && 'done' !== ( $task['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function content_has_cta( string $content ): bool {
		if ( '' === $content ) {
			return false;
		}

		return (bool) preg_match(
			'/<a\s[^>]*href=|https?:\/\/|shop now|learn more|buy now|get started|order now|discover more/i',
			$content
		);
	}

	private static function receipt_summary( string $tool_name, array $target, array $result, array $args ): string {
		$tool_name = sanitize_key( $tool_name );
		$title     = $target['post_title'] ?? sanitize_text_field( $args['title'] ?? '' );
		$post_id   = absint( $target['post_id'] ?? 0 );

		return match ( $tool_name ) {
			'create_post', 'elementor_create_page' => 'Created content'
				. ( $title ? ' "' . $title . '"' : '' )
				. ( $post_id > 0 ? ' (#' . $post_id . ')' : '' ),
			'update_meta', 'fix_seo'              => 'Applied SEO updates'
				. ( $title ? ' on "' . $title . '"' : '' )
				. ( $post_id > 0 ? ' (#' . $post_id . ')' : '' ),
			'edit_content'                         => 'Updated content'
				. ( $title ? ' on "' . $title . '"' : '' )
				. ( $post_id > 0 ? ' (#' . $post_id . ')' : '' ),
			default                                => sanitize_text_field( $result['message'] ?? ( $tool_name . ' completed.' ) ),
		};
	}
}
