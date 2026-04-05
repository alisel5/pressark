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
	 * Valid task statuses.
	 *
	 * `done` is accepted on read for backward compatibility and normalized to
	 * `completed` so callers only see the canonical set.
	 *
	 * @since 5.3.0
	 */
	private const VALID_STATUSES = array( 'pending', 'in_progress', 'completed', 'blocked', 'verified', 'uncertain' );

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

			// v5.3.0: Backward compat â€” normalize legacy `done` to `completed`.
			if ( 'done' === $status ) {
				$status = 'completed';
			}
			if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
				$status = 'pending';
			}

			// v5.3.0: Sanitize depends_on edges.
			$depends_on = array();
			foreach ( (array) ( $task['depends_on'] ?? array() ) as $dep ) {
				$dep = sanitize_key( (string) $dep );
				if ( '' !== $dep ) {
					$depends_on[] = $dep;
				}
			}

			// v5.3.0: Sanitize metadata bag.
			$metadata = array();
			if ( is_array( $task['metadata'] ?? null ) ) {
				foreach ( $task['metadata'] as $mk => $mv ) {
					$mk = sanitize_key( (string) $mk );
					if ( '' === $mk ) {
						continue;
					}
					$metadata[ $mk ] = is_scalar( $mv ) ? $mv : wp_json_encode( $mv );
				}
			}

			$tasks[] = array(
				'key'        => sanitize_key( $task['key'] ?? '' ),
				'label'      => sanitize_text_field( $task['label'] ?? '' ),
				'status'     => $status,
				'evidence'   => sanitize_text_field( $task['evidence'] ?? '' ),
				'depends_on' => array_unique( array_slice( $depends_on, 0, self::MAX_TASKS ) ),
				'metadata'   => $metadata,
			);
		}

		$receipts = array();
		foreach ( array_slice( $raw['receipts'] ?? array(), 0, self::MAX_RECEIPTS ) as $receipt ) {
			if ( ! is_array( $receipt ) ) {
				continue;
			}

			$entry = array(
				'tool'      => sanitize_key( $receipt['tool'] ?? '' ),
				'summary'   => sanitize_text_field( $receipt['summary'] ?? '' ),
				'post_id'   => absint( $receipt['post_id'] ?? 0 ),
				'post_title'=> sanitize_text_field( $receipt['post_title'] ?? '' ),
				'url'       => esc_url_raw( $receipt['url'] ?? '' ),
			);

			// v5.4.0: Preserve verification evidence attached by record_verification().
			if ( is_array( $receipt['verification'] ?? null ) ) {
				$v = $receipt['verification'];
				$entry['verification'] = array(
					'status'     => sanitize_key( $v['status'] ?? '' ),
					'evidence'   => sanitize_text_field( $v['evidence'] ?? '' ),
					'checked_at' => sanitize_text_field( $v['checked_at'] ?? '' ),
				);
			}

			if ( class_exists( 'PressArk_Evidence_Receipt' ) ) {
				if ( is_array( $receipt['evidence_receipt'] ?? null ) ) {
					$entry['evidence_receipt'] = PressArk_Evidence_Receipt::sanitize( $receipt['evidence_receipt'] );
				} elseif ( ! empty( $entry['verification'] ) ) {
					$entry['evidence_receipt'] = PressArk_Evidence_Receipt::from_legacy_verification(
						$entry['tool'],
						$entry['verification'],
						$entry['summary']
					);
				} else {
					$op = class_exists( 'PressArk_Operation_Registry' )
						? PressArk_Operation_Registry::resolve( $entry['tool'] )
						: null;
					if ( $op && $op->is_write() ) {
						$entry['evidence_receipt'] = PressArk_Evidence_Receipt::for_unchecked_write(
							$entry['tool'],
							$entry['summary']
						);
					}
				}
			}

			$receipts[] = $entry;
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

			// v5.3.0: Status priority â€” completed wins over any other state.
			if ( 'completed' === $task['status'] ) {
				$task_map[ $task['key'] ]['status'] = 'completed';
				if ( ! empty( $task['evidence'] ) ) {
					$task_map[ $task['key'] ]['evidence'] = $task['evidence'];
				}
			}

			// Merge depends_on (union) and metadata (client wins per key).
			if ( ! empty( $task['depends_on'] ) ) {
				$existing_deps = $task_map[ $task['key'] ]['depends_on'] ?? array();
				$task_map[ $task['key'] ]['depends_on'] = array_values( array_unique( array_merge( $existing_deps, $task['depends_on'] ) ) );
			}
			if ( ! empty( $task['metadata'] ) ) {
				$task_map[ $task['key'] ]['metadata'] = array_merge(
					$task_map[ $task['key'] ]['metadata'] ?? array(),
					$task['metadata']
				);
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

		// v5.3.0: Resolve blocked states before building context.
		$ledger  = self::resolve_blocked( $ledger );
		$done      = self::task_labels( $ledger, 'completed' );
		$active    = self::task_labels_by_status( $ledger, 'in_progress' );
		$pending   = self::task_labels_by_status( $ledger, 'pending' );
		$blocked   = self::task_labels_by_status( $ledger, 'blocked' );
		$verified  = self::task_labels_by_status( $ledger, 'verified' );
		$uncertain = self::task_labels_by_status( $ledger, 'uncertain' );

		if ( ! empty( $verified ) ) {
			$lines[] = 'VERIFIED TASKS: ' . implode( '; ', $verified );
		}
		if ( ! empty( $done ) ) {
			$lines[] = 'COMPLETED TASKS: ' . implode( '; ', $done );
		}
		if ( ! empty( $active ) ) {
			$lines[] = 'ACTIVE TASK: ' . implode( '; ', $active );
		}
		if ( ! empty( $pending ) ) {
			$lines[] = 'REMAINING TASKS: ' . implode( '; ', $pending );
		}
		if ( ! empty( $blocked ) ) {
			$lines[] = 'BLOCKED TASKS: ' . implode( '; ', $blocked );
		}
		if ( ! empty( $uncertain ) ) {
			$lines[] = 'UNCERTAIN TASKS: ' . implode( '; ', $uncertain ) . ' â€” verify with a read tool before reporting completion.';
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

		$ledger  = self::resolve_blocked( $ledger );
		$done    = self::task_labels( $ledger, 'completed' );
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
		$ledger           = self::resolve_blocked( $ledger );
		$completed_tasks  = array();
		$remaining_tasks  = array();
		$blocked_tasks    = array();
		$in_progress      = array();
		$next_task        = null;

		$uncertain_tasks = array();

		foreach ( $ledger['tasks'] as $task ) {
			$status = $task['status'] ?? '';

			// v5.4.0: `verified` counts as completed; `uncertain` counts as remaining.
			if ( in_array( $status, array( 'completed', 'verified' ), true ) ) {
				$completed_tasks[] = $task;
				continue;
			}

			$remaining_tasks[] = $task;

			if ( 'blocked' === $status ) {
				$blocked_tasks[] = $task;
			} elseif ( 'uncertain' === $status ) {
				$uncertain_tasks[] = $task;
			} elseif ( 'in_progress' === $status ) {
				$in_progress[] = $task;
				if ( null === $next_task ) {
					$next_task = $task;
				}
			} else {
				// pending â€” candidate for next.
				if ( null === $next_task ) {
					$next_task = $task;
				}
			}
		}

		$is_complete = ! empty( $ledger['tasks'] ) && empty( $remaining_tasks );

		return array(
			'total_tasks'        => count( $ledger['tasks'] ),
			'completed_count'    => count( $completed_tasks ),
			'remaining_count'    => count( $remaining_tasks ),
			'blocked_count'      => count( $blocked_tasks ),
			'uncertain_count'    => count( $uncertain_tasks ),
			'in_progress_count'  => count( $in_progress ),
			'completed_labels'   => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$completed_tasks
			) ) ),
			'remaining_labels'   => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$remaining_tasks
			) ) ),
			'blocked_labels'     => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$blocked_tasks
			) ) ),
			'next_task_key'      => sanitize_key( $next_task['key'] ?? '' ),
			'next_task_label'    => sanitize_text_field( $next_task['label'] ?? '' ),
			'is_complete'        => $is_complete,
			'should_auto_resume' => ! $is_complete && ! empty( $remaining_tasks ) && count( $blocked_tasks ) < count( $remaining_tasks ),
		);
	}

	public static function has_remaining_tasks( array $ledger ): bool {
		$progress = self::progress_snapshot( $ledger );
		return (int) ( $progress['remaining_count'] ?? 0 ) > 0;
	}

	// â”€â”€ Task Graph: Dependency Resolution (v5.3.0) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	/**
	 * Resolve blocked status from dependency edges.
	 *
	 * A task is `blocked` when any key in its `depends_on` array references a
	 * task that is not yet `completed`. Blocked tasks that become unblocked are
	 * returned to `pending`. This is a pure function â€” it returns a new ledger.
	 *
	 * @since 5.3.0
	 */
	public static function resolve_blocked( array $ledger ): array {
		$ledger = self::sanitize( $ledger );
		if ( empty( $ledger['tasks'] ) ) {
			return $ledger;
		}

		// Build a set of completed task keys (verified counts as completed).
		$completed_keys = array();
		foreach ( $ledger['tasks'] as $task ) {
			if ( in_array( $task['status'], array( 'completed', 'verified' ), true ) ) {
				$completed_keys[ $task['key'] ] = true;
			}
		}

		foreach ( $ledger['tasks'] as &$task ) {
			// Don't touch completed, verified, uncertain, or in_progress tasks.
			if ( in_array( $task['status'], array( 'completed', 'verified', 'uncertain', 'in_progress' ), true ) ) {
				continue;
			}

			$deps = $task['depends_on'] ?? array();
			if ( empty( $deps ) ) {
				// No deps â€” should be pending, not blocked.
				if ( 'blocked' === $task['status'] ) {
					$task['status'] = 'pending';
				}
				continue;
			}

			$all_met = true;
			foreach ( $deps as $dep_key ) {
				if ( empty( $completed_keys[ $dep_key ] ) ) {
					$all_met = false;
					break;
				}
			}

			$task['status'] = $all_met ? 'pending' : 'blocked';
		}
		unset( $task );

		return $ledger;
	}

	/**
	 * Mark a task as in_progress.
	 *
	 * @since 5.3.0
	 */
	public static function mark_task_in_progress( array $ledger, string $key ): array {
		$ledger = self::sanitize( $ledger );
		$key    = sanitize_key( $key );

		foreach ( $ledger['tasks'] as &$task ) {
			if ( $key === ( $task['key'] ?? '' ) && 'pending' === $task['status'] ) {
				$task['status'] = 'in_progress';
				break;
			}
		}
		unset( $task );

		$ledger['updated_at'] = gmdate( 'c' );
		return $ledger;
	}

	/**
	 * Create a task with dependency edges.
	 *
	 * @since 5.3.0
	 *
	 * @param array    $ledger     Current ledger.
	 * @param string   $key        Task key.
	 * @param string   $label      Human-readable label.
	 * @param string[] $depends_on Keys of tasks this one depends on.
	 * @param array    $metadata   Optional metadata bag.
	 * @return array Updated ledger.
	 */
	public static function add_task( array $ledger, string $key, string $label, array $depends_on = array(), array $metadata = array() ): array {
		$ledger = self::sanitize( $ledger );
		$key    = sanitize_key( $key );

		// Don't add duplicates.
		foreach ( $ledger['tasks'] as $task ) {
			if ( $key === ( $task['key'] ?? '' ) ) {
				return $ledger;
			}
		}

		$deps = array();
		foreach ( $depends_on as $dep ) {
			$dep = sanitize_key( (string) $dep );
			if ( '' !== $dep ) {
				$deps[] = $dep;
			}
		}

		$ledger['tasks'][] = array(
			'key'        => $key,
			'label'      => sanitize_text_field( $label ),
			'status'     => empty( $deps ) ? 'pending' : 'blocked',
			'evidence'   => '',
			'depends_on' => array_unique( array_slice( $deps, 0, self::MAX_TASKS ) ),
			'metadata'   => $metadata,
		);
		$ledger['tasks']   = array_slice( self::dedupe_tasks( $ledger['tasks'] ), 0, self::MAX_TASKS );
		$ledger['updated_at'] = gmdate( 'c' );

		return self::resolve_blocked( $ledger );
	}

	/**
	 * Get the next actionable task â€” first `in_progress`, then first `pending`.
	 *
	 * @since 5.3.0
	 * @return array|null Task array or null if none actionable.
	 */
	public static function next_actionable_task( array $ledger ): ?array {
		$ledger = self::resolve_blocked( $ledger );

		// Prefer already in-progress task.
		foreach ( $ledger['tasks'] as $task ) {
			if ( 'in_progress' === $task['status'] ) {
				return $task;
			}
		}

		// Then first pending.
		foreach ( $ledger['tasks'] as $task ) {
			if ( 'pending' === $task['status'] ) {
				return $task;
			}
		}

		return null;
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

	// â”€â”€ Verification (v5.4.0) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	/**
	 * Record a verification result for a write tool.
	 *
	 * Finds the most recent receipt matching the tool, attaches evidence,
	 * and updates the corresponding task status to `verified` or `uncertain`.
	 *
	 * @since 5.4.0
	 *
	 * @param array  $ledger          Current ledger.
	 * @param string $tool_name       The write tool that was verified.
	 * @param array  $readback_result Read-back tool result.
	 * @param bool   $passed          Whether verification passed.
	 * @param string $evidence        Compact human-readable evidence string.
	 * @return array Updated ledger.
	 */
	public static function record_verification(
		array $ledger,
		string $tool_name,
		array $readback_result,
		bool $passed,
		string $evidence = '',
		array $meta = array()
	): array {
		$ledger    = self::sanitize( $ledger );
		$tool_name = sanitize_key( $tool_name );
		$evidence  = sanitize_text_field( $evidence );
		$new_status = $passed ? 'verified' : 'uncertain';
		$checked_at = gmdate( 'c' );
		$evidence_receipt = class_exists( 'PressArk_Evidence_Receipt' )
			? PressArk_Evidence_Receipt::from_verification( $tool_name, $readback_result, $passed, $evidence, $meta )
			: array();

		// Attach evidence to the most recent matching receipt.
		$found_receipt = false;
		for ( $i = count( $ledger['receipts'] ) - 1; $i >= 0; $i-- ) {
			if ( $tool_name === ( $ledger['receipts'][ $i ]['tool'] ?? '' ) ) {
				$ledger['receipts'][ $i ]['verification'] = array(
					'status'   => $new_status,
					'evidence' => $evidence,
					'checked_at' => $checked_at,
				);
				if ( ! empty( $evidence_receipt ) ) {
					$ledger['receipts'][ $i ]['evidence_receipt'] = $evidence_receipt;
				}
				$found_receipt = true;
				break;
			}
		}

		// If no receipt found, append a verification-only receipt.
		if ( ! $found_receipt ) {
			$ledger['receipts'][] = array(
				'tool'         => $tool_name,
				'summary'      => $evidence ?: ( $tool_name . ' verification: ' . $new_status ),
				'post_id'      => 0,
				'post_title'   => '',
				'url'          => '',
				'verification' => array(
					'status'     => $new_status,
					'evidence'   => $evidence,
					'checked_at' => $checked_at,
				),
			);
			if ( ! empty( $evidence_receipt ) ) {
				$ledger['receipts'][ count( $ledger['receipts'] ) - 1 ]['evidence_receipt'] = $evidence_receipt;
			}
			$ledger['receipts'] = array_slice( $ledger['receipts'], -self::MAX_RECEIPTS );
		}

		// Update the matching task status if we can find one.
		// Map tool names to task keys for common write patterns.
		$task_key_map = array(
			'create_post'            => 'create_post',
			'elementor_create_page'  => 'create_post',
			'edit_content'           => 'fulfill_request',
			'fix_seo'               => 'optimize_seo',
			'update_meta'           => 'optimize_seo',
		);
		$task_key = $task_key_map[ $tool_name ] ?? '';

		if ( $task_key ) {
			foreach ( $ledger['tasks'] as &$task ) {
				if ( $task_key === ( $task['key'] ?? '' ) && in_array( $task['status'], array( 'completed', 'in_progress' ), true ) ) {
					$task['status']   = $new_status;
					$task['evidence'] = $evidence ?: $task['evidence'];
					break;
				}
			}
			unset( $task );
		}

		$ledger['updated_at'] = gmdate( 'c' );
		return $ledger;
	}

	/**
	 * Get a compact verification summary for continuation prompts.
	 *
	 * @since 5.4.0
	 * @return array{verified: int, uncertain: int, unverified: int, details: string[]}
	 */
	public static function verification_summary( array $ledger ): array {
		$ledger     = self::sanitize( $ledger );
		$verified   = 0;
		$uncertain  = 0;
		$unverified = 0;
		$details    = array();

		foreach ( $ledger['receipts'] as $receipt ) {
			$evidence_receipt = is_array( $receipt['evidence_receipt'] ?? null ) ? $receipt['evidence_receipt'] : array();
			$v_status         = $evidence_receipt['status'] ?? ( $receipt['verification']['status'] ?? '' );
			if ( 'verified' === $v_status ) {
				$verified++;
				$evidence   = $evidence_receipt['evidence'] ?? ( $receipt['verification']['evidence'] ?? '' );
				$confidence = $evidence_receipt['confidence_label'] ?? '';
				if ( $evidence ) {
					$details[] = $receipt['tool'] . ': VERIFIED'
						. ( $confidence ? ' (' . $confidence . ')' : '' )
						. ' - ' . $evidence;
				}
				continue;
			} elseif ( 'uncertain' === $v_status ) {
				$uncertain++;
				$evidence = $evidence_receipt['evidence'] ?? ( $receipt['verification']['evidence'] ?? '' );
				$details[] = $receipt['tool'] . ': UNCERTAIN' . ( $evidence ? ' - ' . $evidence : '' );
				continue;
			} else {
				// Write receipt with no verification.
				$op = PressArk_Operation_Registry::resolve( $receipt['tool'] ?? '' );
				if ( $op && $op->is_write() ) {
					$unverified++;
				}
			}
		}

		return array(
			'verified'   => $verified,
			'uncertain'  => $uncertain,
			'unverified' => $unverified,
			'details'    => $details,
		);
	}

	/**
	 * Return normalized evidence receipts for persisted write operations.
	 *
	 * @param array<string,mixed> $ledger Current ledger.
	 * @return array<int,array<string,mixed>>
	 */
	public static function evidence_receipts( array $ledger ): array {
		$ledger = self::sanitize( $ledger );
		$rows   = array();

		foreach ( $ledger['receipts'] as $receipt ) {
			$op = PressArk_Operation_Registry::resolve( $receipt['tool'] ?? '' );
			if ( ! $op || ! $op->is_write() ) {
				continue;
			}

			$rows[] = array(
				'tool'             => $receipt['tool'] ?? '',
				'summary'          => $receipt['summary'] ?? '',
				'post_id'          => (int) ( $receipt['post_id'] ?? 0 ),
				'post_title'       => $receipt['post_title'] ?? '',
				'url'              => $receipt['url'] ?? '',
				'verification'     => is_array( $receipt['verification'] ?? null ) ? $receipt['verification'] : array(),
				'evidence_receipt' => is_array( $receipt['evidence_receipt'] ?? null ) ? $receipt['evidence_receipt'] : array(),
			);
		}

		return $rows;
	}

	/**
	 * Whether all write tasks in the ledger are in `verified` status.
	 *
	 * Returns true when there are no uncertain or unverified write receipts.
	 * Ledgers with no write receipts also return true (nothing to verify).
	 *
	 * @since 5.4.0
	 */
	public static function is_fully_verified( array $ledger ): bool {
		$summary = self::verification_summary( $ledger );
		return 0 === $summary['uncertain'] && 0 === $summary['unverified'];
	}

	private static function extract_tasks( string $message ): array {
		$msg   = strtolower( $message );
		$tasks = array();

		// v5.3.0: Tasks now carry dependency edges. We track which keys have been
		// added so later tasks can declare depends_on references.
		$added_keys = array();

		if ( preg_match( '/\b(random|pick|select|choose)\b.*\bproduct\b/', $msg ) ) {
			$tasks[]      = self::task( 'select_source', 'Select a source product to feature' );
			$added_keys[] = 'select_source';
		}

		if ( preg_match( '/\b(create|write|draft|generate|compose|publish)\b.*\b(blog post|post|article|page)\b/', $msg )
			|| preg_match( '/\bblog post\b/', $msg ) ) {
			// create_post depends on select_source if present.
			$deps         = in_array( 'select_source', $added_keys, true ) ? array( 'select_source' ) : array();
			$tasks[]      = self::task( 'create_post', 'Create the requested blog post or page', $deps );
			$added_keys[] = 'create_post';
		}

		if ( preg_match( '/\b(call to action|cta)\b/', $msg )
			|| preg_match( '/\b(link|url)\b.*\bproduct\b/', $msg ) ) {
			// CTA depends on create_post if present.
			$deps         = in_array( 'create_post', $added_keys, true ) ? array( 'create_post' ) : array();
			$tasks[]      = self::task( 'add_cta', 'Add a call to action with the requested link', $deps );
			$added_keys[] = 'add_cta';
		}

		if ( preg_match( '/\bseo\b|\bmeta\b|\bslug\b|\bsearch engine\b|\brank\b/', $msg ) ) {
			// SEO depends on create_post if present.
			$deps         = in_array( 'create_post', $added_keys, true ) ? array( 'create_post' ) : array();
			$tasks[]      = self::task( 'optimize_seo', 'Optimize the content for SEO', $deps );
			$added_keys[] = 'optimize_seo';
		}

		if ( preg_match( '/\bpublish\b|\blive\b/', $msg ) ) {
			// Publish depends on create_post (and optionally SEO/CTA if present).
			$deps = array();
			if ( in_array( 'create_post', $added_keys, true ) ) {
				$deps[] = 'create_post';
			}
			if ( in_array( 'optimize_seo', $added_keys, true ) ) {
				$deps[] = 'optimize_seo';
			}
			if ( in_array( 'add_cta', $added_keys, true ) ) {
				$deps[] = 'add_cta';
			}
			$tasks[]      = self::task( 'publish_content', 'Publish the content', $deps );
			$added_keys[] = 'publish_content';
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

	private static function task( string $key, string $label, array $depends_on = array(), array $metadata = array() ): array {
		return array(
			'key'        => sanitize_key( $key ),
			'label'      => sanitize_text_field( $label ),
			'status'     => empty( $depends_on ) ? 'pending' : 'blocked',
			'evidence'   => '',
			'depends_on' => $depends_on,
			'metadata'   => $metadata,
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
			$task['status']   = 'completed';
			$task['evidence'] = sanitize_text_field( $evidence );

			// v5.3.0: Completing a task may unblock dependents.
			return self::resolve_blocked( $ledger );
		}
		unset( $task );

		$ledger = self::ensure_task( $ledger, $key, $key );
		return self::mark_task_done( $ledger, $key, $evidence );
	}

	private static function task_labels( array $ledger, string $status ): array {
		// v5.3.0: Accept legacy `done` as alias for `completed`.
		// v5.4.0: 'verified' counts as completed for task label queries.
		$match_statuses = array( $status );
		if ( 'done' === $status ) {
			$match_statuses[] = 'completed';
			$match_statuses[] = 'verified';
		} elseif ( 'completed' === $status ) {
			$match_statuses[] = 'done';
			$match_statuses[] = 'verified';
		} elseif ( 'pending' === $status ) {
			$match_statuses[] = 'uncertain';
		}

		$labels = array();
		foreach ( $ledger['tasks'] as $task ) {
			if ( ! in_array( $task['status'] ?? '', $match_statuses, true ) ) {
				continue;
			}
			$label = $task['label'] ?? '';
			if ( in_array( $task['status'] ?? '', array( 'done', 'completed' ), true ) && ! empty( $task['evidence'] ) ) {
				$label .= ' (' . $task['evidence'] . ')';
			}
			if ( 'blocked' === ( $task['status'] ?? '' ) ) {
				$label .= ' [blocked]';
			}
			if ( 'in_progress' === ( $task['status'] ?? '' ) ) {
				$label .= ' [active]';
			}
			if ( $label ) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	/**
	 * Get task labels for a single exact status (no aliasing).
	 *
	 * @since 5.3.0
	 */
	private static function task_labels_by_status( array $ledger, string $status ): array {
		$labels = array();
		foreach ( $ledger['tasks'] as $task ) {
			if ( $status !== ( $task['status'] ?? '' ) ) {
				continue;
			}
			$label = $task['label'] ?? '';
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
		if ( class_exists( 'PressArk_Evidence_Receipt' ) ) {
			$op = class_exists( 'PressArk_Operation_Registry' ) ? PressArk_Operation_Registry::resolve( $tool ) : null;
			if ( $op && $op->is_write() ) {
				$ledger['receipts'][ count( $ledger['receipts'] ) - 1 ]['evidence_receipt'] =
					PressArk_Evidence_Receipt::for_unchecked_write( $tool, $summary );
			}
		}
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
		// v5.4.0: 'verified' counts as completed.
		$done_statuses = array( 'done', 'completed', 'verified' );
		foreach ( $ledger['tasks'] as $task ) {
			if ( 'optimize_seo' === ( $task['key'] ?? '' ) && in_array( $task['status'] ?? '', $done_statuses, true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_pending_task( array $ledger, string $key ): bool {
		$key = sanitize_key( $key );
		// v5.4.0: 'verified' counts as completed (not pending).
		$done_statuses = array( 'done', 'completed', 'verified' );
		foreach ( $ledger['tasks'] as $task ) {
			if ( $key === ( $task['key'] ?? '' ) && ! in_array( $task['status'] ?? '', $done_statuses, true ) ) {
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