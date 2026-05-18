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
	private const WC_PRICE_RETRY_LIMIT = 2;
	private const WC_PRICE_RETRY_FIELDS = array(
		'regular_price',
		'sale_price',
		'clear_sale',
		'price_delta',
		'price_adjust_pct',
		'sale_from',
		'sale_to',
	);
	private const WC_PRICE_RETRY_TOOLS = array(
		'edit_product',
		'bulk_edit_products',
	);

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
				// v5.7.10: preserve slug across serialize/deserialize round-trips.
				'slug'      => sanitize_title( (string) ( $receipt['slug'] ?? '' ) ),
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

			if ( is_array( $receipt['retry_guard'] ?? null ) ) {
				$retry_guard = self::sanitize_retry_guard( $receipt['retry_guard'] );
				if ( ! empty( $retry_guard ) ) {
					$entry['retry_guard'] = $retry_guard;
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
		$tool_name = sanitize_key( $tool_name );

		// v5.8.15 (2026-05-14): target-bearing reads are also target-selection events.
		// A diagnostic follow-up like "fix 3" depends on the latest analyze_seo
		// target surviving in structured state, not only in model prose.
		if ( self::read_tool_updates_current_target( $tool_name ) ) {
			$target = self::extract_target( $result, $args );
			if ( ! empty( $target['post_id'] ) ) {
				$ledger['current_target'] = self::merge_current_target( $ledger['current_target'] ?? array(), $target );
			}
		}

		if ( empty( $ledger['tasks'] ) ) {
			$ledger['updated_at'] = gmdate( 'c' );
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
		$context   = $target;
		$retry_guard = self::build_retry_guard_marker( $tool_name, $args, $result );
		if ( ! empty( $retry_guard ) ) {
			$context['retry_guard'] = $retry_guard;
		}
		if ( ! empty( $target['post_id'] ) ) {
			$ledger['current_target'] = $target;
		}

		if ( ! self::has_model_plan_tasks( $ledger ) ) {
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
		}

		// Generic single-step edit requests fall back to fulfill_request. Once a
		// write succeeded, that placeholder task should be considered complete so
		// confirmation flows do not auto-resume into an unrelated second AI turn.
		if ( self::has_pending_task( $ledger, 'fulfill_request' ) ) {
			$ledger = self::mark_task_done( $ledger, 'fulfill_request', $receipt );
		}

		// v5.6.3: Also mark any dynamic execution task whose metadata.tool_name
		// matches this write. Closes the O-2 stale-Remaining gap: model-supplied
		// step labels ("Edit Content", "Update Meta") were lingering as pending
		// even after their write succeeded, polluting the [Continue] envelope.
		$ledger = self::mark_dynamic_task_done_by_tool( $ledger, $tool_name, $receipt );

		$ledger = self::append_receipt( $ledger, $tool_name, $receipt, $context );
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
	public static function build_context_lines( array $ledger, string $current_user_message = '' ): array {
		$ledger = self::sanitize( $ledger );
		$lines  = array();

		if ( ! empty( $ledger['source_message'] ) ) {
			// Skip when the source message is literally the same text as the
			// live user message already in msgs[] — avoids a truncated echo
			// of a prompt the model just received in full.
			$source_trim = trim( (string) $ledger['source_message'] );
			$current_trim = trim( $current_user_message );
			if ( '' === $current_trim || $source_trim !== $current_trim ) {
				$lines[] = 'SOURCE REQUEST: ' . self::compact_text( (string) $ledger['source_message'], 180 );
			}
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
			// Internal status is `blocked` (dependency-waiting per resolve_blocked),
			// but the external label is UPCOMING — "blocked" implies an obstacle
			// the model should investigate, when really these tasks are just
			// queued behind unmet deps.
			$lines[] = 'UPCOMING TASKS: ' . implode( '; ', $blocked );
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

		$is_complete     = ! empty( $ledger['tasks'] ) && empty( $remaining_tasks );
		$blocked_details = array();
		foreach ( $blocked_tasks as $task ) {
			$deps = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						(array) ( $task['depends_on'] ?? array() )
					)
				)
			);
			if ( empty( $deps ) ) {
				continue;
			}

			$blocked_details[] = array(
				'key'          => sanitize_key( (string) ( $task['key'] ?? '' ) ),
				'label'        => sanitize_text_field( (string) ( $task['label'] ?? '' ) ),
				'depends_on'   => $deps,
				'depends_text' => implode( ', ', $deps ),
			);
		}

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
			'in_progress_labels' => array_values( array_filter( array_map(
				fn( $task ) => sanitize_text_field( $task['label'] ?? '' ),
				$in_progress
			) ) ),
			'blocked_details'    => $blocked_details,
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

			// v5.8.6 (2026-05-13, post-iter-41): model-authored blocked
			// means "this branch cannot proceed" (for example a speed redirect
			// loop), not dependency-waiting. Preserve it across ledger sync.
			if ( 'blocked' === $task['status'] && ! empty( $task['metadata']['explicit_blocked'] ) ) {
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
	 * Insert dynamically discovered tasks into an existing ledger.
	 *
	 * @param array $ledger Current ledger.
	 * @param array $tasks  Task rows shaped like add_task() inputs.
	 * @return array Updated ledger.
	 */
	public static function insert_dynamic_tasks( array $ledger, array $tasks ): array {
		$ledger = self::sanitize( $ledger );

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$ledger = self::add_task(
				$ledger,
				(string) ( $task['key'] ?? '' ),
				(string) ( $task['label'] ?? '' ),
				(array) ( $task['depends_on'] ?? array() ),
				is_array( $task['metadata'] ?? null ) ? (array) $task['metadata'] : array()
			);
		}

		return self::resolve_blocked( $ledger );
	}

	/**
	 * Replace extractor-derived task labels with model-authored update_plan steps.
	 *
	 * The regex extractor is still useful before the model has committed to a
	 * plan. Once update_plan exists, the model's labels are the user-facing
	 * contract for continuation and wrap-round state.
	 *
	 * @param array<int,array<string,mixed>> $steps Normalized plan rows.
	 */
	public static function adopt_plan_steps( array $ledger, array $steps ): array {
		$ledger = self::sanitize( $ledger );
		if ( empty( $steps ) ) {
			return $ledger;
		}

		$prior_by_key   = array();
		$prior_by_label = array();
		foreach ( $ledger['tasks'] as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $task['key'] ?? '' ) );
			if ( '' !== $key ) {
				$prior_by_key[ $key ] = $task;
			}
			$label = sanitize_text_field( (string) ( $task['label'] ?? '' ) );
			if ( '' !== $label ) {
				$prior_by_label[ strtolower( $label ) ] = $task;
			}
		}

		$tasks        = array();
		$used_keys    = array();
		$previous_key = '';

		foreach ( array_slice( $steps, 0, self::MAX_TASKS ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) (
				$step['content']
				?? $step['title']
				?? $step['description']
				?? $step['activeForm']
				?? ''
			) );
			if ( '' === $label ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
			$key       = sanitize_key( (string) ( $step['id'] ?? '' ) );
			if ( '' === $key ) {
				$key = '' !== $tool_name ? $tool_name . '_' . ( $index + 1 ) : 'plan_step_' . ( $index + 1 );
			}
			$key_base = $key;
			$suffix   = 2;
			while ( isset( $used_keys[ $key ] ) ) {
				$key = $key_base . '_' . $suffix;
				$suffix++;
			}
			$used_keys[ $key ] = true;

			$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			if ( 'active' === $status ) {
				$status = 'in_progress';
			} elseif ( in_array( $status, array( 'done', 'verified' ), true ) ) {
				$status = 'completed';
			}
			if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
				$status = 'pending';
			}

			$kind = sanitize_key( (string) ( $step['kind'] ?? '' ) );
			if ( '' === $kind ) {
				$kind = self::infer_plan_step_kind( $tool_name, ! empty( $step['preview_required'] ) );
			}

			$group = sanitize_key( (string) ( $step['group'] ?? '' ) );
			if ( '' === $group ) {
				$group = 'system';
			}

			$depends_on = array();
			foreach ( (array) ( $step['depends_on'] ?? array() ) as $dep ) {
				$dep = sanitize_key( (string) $dep );
				if ( '' !== $dep ) {
					$depends_on[] = $dep;
				}
			}
			if ( empty( $depends_on ) && '' !== $previous_key ) {
				$depends_on[] = $previous_key;
			}

			$prior    = $prior_by_key[ $key ] ?? ( $prior_by_label[ strtolower( $label ) ] ?? array() );
			$evidence = sanitize_text_field( (string) ( $prior['evidence'] ?? '' ) );
			if ( '' === $evidence && ! empty( $step['evidence'] ) ) {
				$evidence = sanitize_text_field( (string) $step['evidence'] );
			}

			$metadata = is_array( $step['metadata'] ?? null ) ? (array) $step['metadata'] : array();
			$metadata = array_merge(
				$metadata,
				array(
					'kind'      => $kind,
					'group'     => $group,
					'tool_name' => $tool_name,
					'origin'    => 'model_plan',
				)
			);

			if ( ! empty( $step['post_id'] ) ) {
				$metadata['post_id'] = absint( $step['post_id'] );
			}
			if ( array_key_exists( 'preview_required', $step ) ) {
				$metadata['preview_required'] = ! empty( $step['preview_required'] );
			}
			if ( 'blocked' === $status ) {
				$metadata['explicit_blocked'] = true;
			}

			$tasks[]      = self::task( $key, $label, array_values( array_unique( $depends_on ) ), $metadata );
			$last_index   = count( $tasks ) - 1;
			$tasks[ $last_index ]['status']   = $status;
			$tasks[ $last_index ]['evidence'] = $evidence;
			$previous_key = $key;
		}

		if ( empty( $tasks ) ) {
			return $ledger;
		}

		$ledger['tasks']      = array_slice( self::dedupe_tasks( $tasks ), 0, self::MAX_TASKS );
		$ledger['updated_at'] = gmdate( 'c' );

		return self::resolve_blocked( $ledger );
	}

	/**
	 * Mark a specific task completed.
	 *
	 * @param array  $ledger   Current ledger.
	 * @param string $key      Task key.
	 * @param string $evidence Optional evidence note.
	 * @return array Updated ledger.
	 */
	public static function complete_task( array $ledger, string $key, string $evidence = '' ): array {
		$ledger = self::sanitize( $ledger );
		$key    = sanitize_key( $key );
		if ( '' === $key ) {
			return $ledger;
		}

		$ledger['updated_at'] = gmdate( 'c' );
		return self::mark_task_done( $ledger, $key, $evidence );
	}

	/**
	 * Complete the current in-progress task when it matches the allowed kinds.
	 *
	 * @param array    $ledger        Current ledger.
	 * @param string[] $allowed_kinds Allowed task kinds. Empty means any kind.
	 * @param string   $evidence      Optional evidence note.
	 * @return array Updated ledger.
	 */
	public static function complete_current_task( array $ledger, array $allowed_kinds = array(), string $evidence = '' ): array {
		$ledger        = self::resolve_blocked( $ledger );
		$allowed_kinds = array_values( array_filter( array_map( 'sanitize_key', $allowed_kinds ) ) );

		foreach ( $ledger['tasks'] as $task ) {
			if ( 'in_progress' !== (string) ( $task['status'] ?? '' ) ) {
				continue;
			}

			$kind = sanitize_key( (string) ( $task['metadata']['kind'] ?? '' ) );
			if ( ! empty( $allowed_kinds ) && ! in_array( $kind, $allowed_kinds, true ) ) {
				return $ledger;
			}

			$ledger = self::complete_task( $ledger, (string) ( $task['key'] ?? '' ), $evidence );
			$next   = self::next_actionable_task( $ledger );
			if ( $next && 'pending' === (string) ( $next['status'] ?? '' ) ) {
				$ledger = self::mark_task_in_progress( $ledger, (string) ( $next['key'] ?? '' ) );
			}
			return $ledger;
		}

		return $ledger;
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
	 *
	 * v5.4.0: update_meta is intentionally NOT guarded by is_seo_task_complete()
	 * anymore. The "SEO task complete" flag means the model finished the planned
	 * SEO step — it does not mean every meta field on the target is now locked.
	 * A model following up with og_title / og_description / og_image / custom
	 * meta keys after the SEO step is a legitimate, common path, not a duplicate.
	 * Observed bug 2026-05-12: a follow-up update_meta with two new OG fields and
	 * one duplicate focus_keyword was silently dropped wholesale, and the user
	 * was told (via the model) that the changes had been applied at create-time.
	 * If we ever re-introduce a duplicate check for update_meta, it MUST diff at
	 * the field level against the ledger's known applied meta — not target-level.
	 *
	 * fix_seo remains guarded because it's an aggregate operation that re-runs
	 * the entire SEO task; a second invocation after completion is genuinely a
	 * loop, not new work.
	 */
	public static function should_skip_duplicate( array $ledger, string $tool_name, array $args = array() ): bool {
		$ledger = self::sanitize( $ledger );
		$tool_name = sanitize_key( $tool_name );

		if ( 'fix_seo' === $tool_name && self::is_seo_task_complete( $ledger ) ) {
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

			// v5.7.7 (2026-05-12): Diff at the field level, not the tool level.
			// Observed 2026-05-12 on "Create Shipping Policy page and Returns
			// Policy page, then add both to the main menu" chain: the request
			// extractor counted request_counts['create_post']=1 (it parsed "a
			// Returns Policy page" weakly), so when the model created Shipping
			// Policy first, the guard fired on the second create_post for
			// Returns Policy — even though title/slug were obviously different.
			// The model got back skipped_duplicate=true and built a wrap message
			// saying "only Shipping Policy exists", asking the user to confirm
			// retry — a real UX gap.
			//
			// Fix: only treat as duplicate when the NEW args' title or slug match
			// an existing receipt's recorded values. If either differ, this is a
			// new creation — allow it. iter-19's "diff at field level, not
			// target level" principle, applied to the create_post guard.
			//
			// v5.7.10 (2026-05-13): Also check slug, not just title. Receipts now
			// store slug (iter-30 extension). When the model auto-derives title
			// from slug or vice-versa and one happens to collide with a prior
			// receipt's title, the slug diff still catches the distinct intent.
			$new_title = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
			$new_slug  = sanitize_title( (string) ( $args['slug'] ?? '' ) );
			foreach ( $ledger['receipts'] as $receipt ) {
				if ( 'create_post' !== ( $receipt['tool'] ?? '' ) ) {
					continue;
				}
				$receipt_title = sanitize_text_field( (string) ( $receipt['post_title'] ?? '' ) );
				$receipt_slug  = sanitize_title( (string) ( $receipt['slug'] ?? '' ) );

				// New title differs from a prior create's title → new creation, allow.
				if ( '' !== $new_title && '' !== $receipt_title && $new_title !== $receipt_title ) {
					continue;
				}
				// New slug differs from a prior create's slug → new creation, allow.
				if ( '' !== $new_slug && '' !== $receipt_slug && $new_slug !== $receipt_slug ) {
					continue;
				}

				// Both title and slug effectively match (or aren't supplied to
				// disambiguate). Treat as genuine duplicate.
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the synthetic tool result used when a duplicate write is skipped.
	 *
	 * v5.4.0: The message now names the actual trigger (which guard fired) instead
	 * of hard-coding "already created a target" — the previous wording was wrong
	 * whenever the trigger wasn't a create_post duplicate (e.g. a fix_seo skip
	 * after the SEO task completed), and the model can only report accurately to
	 * the user if the receipt tells the truth.
	 */
	public static function duplicate_skip_result( array $ledger, string $tool_name ): array {
		$ledger     = self::sanitize( $ledger );
		$target     = $ledger['current_target'];
		$title      = $target['post_title'] ?? '';
		$post_id    = (int) ( $target['post_id'] ?? 0 );
		$tool_name  = sanitize_key( $tool_name );

		if ( 'fix_seo' === $tool_name ) {
			$reason = 'because the SEO task for this run is already marked complete';
		} elseif ( 'create_post' === $tool_name ) {
			$reason = 'because this request already created a target';
		} else {
			$reason = 'because this request already recorded an equivalent write';
		}

		$message = 'Skipped duplicate ' . $tool_name . ' ' . $reason;
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

	/**
	 * Block repeated non-converging WooCommerce pricing retries on the same product.
	 *
	 * After two uncertain price-affecting attempts in the current run, the next
	 * price write for that same product is converted into a synthetic blocked
	 * result so the model must explain/escalate instead of writing again.
	 */
	public static function maybe_block_wc_price_retry( array $ledger, string $tool_name, array $args = array() ): ?array {
		$ledger  = self::sanitize( $ledger );
		$attempt = self::build_retry_guard_marker( $tool_name, $args );
		if ( empty( $attempt ) || 'wc_pricing' !== ( $attempt['kind'] ?? '' ) ) {
			return null;
		}

		$matching = array();
		foreach ( $ledger['receipts'] as $receipt ) {
			$guard = is_array( $receipt['retry_guard'] ?? null ) ? $receipt['retry_guard'] : array();
			if ( 'wc_pricing' !== ( $guard['kind'] ?? '' ) ) {
				continue;
			}
			if ( ( $guard['target_key'] ?? '' ) !== ( $attempt['target_key'] ?? '' ) ) {
				continue;
			}

			$status = sanitize_key(
				(string) (
					$receipt['evidence_receipt']['status']
					?? $receipt['verification']['status']
					?? ''
				)
			);
			if ( 'uncertain' !== $status ) {
				continue;
			}

			$matching[] = $receipt;
		}

		if ( count( $matching ) < self::WC_PRICE_RETRY_LIMIT ) {
			return null;
		}

		$recent = array_slice( $matching, -self::WC_PRICE_RETRY_LIMIT );
		$reason_parts = array();
		foreach ( $recent as $receipt ) {
			$evidence = sanitize_text_field(
				(string) (
					$receipt['evidence_receipt']['evidence']
					?? $receipt['verification']['evidence']
					?? $receipt['summary']
					?? ''
				)
			);
			if ( '' !== $evidence ) {
				$reason_parts[] = $evidence;
			}
		}

		$field_text = implode( ', ', array_map( 'sanitize_key', (array) ( $attempt['fields'] ?? array() ) ) );
		$message    = sprintf(
			'Blocked repeated WooCommerce pricing retry for product #%1$d after %2$d non-converging pricing attempt(s). Stop issuing another pricing write for %3$s and explain/escalate from the latest observed state instead.',
			(int) ( $attempt['target_id'] ?? 0 ),
			count( $matching ),
			'' !== $field_text ? $field_text : 'the requested pricing fields'
		);
		if ( ! empty( $reason_parts ) ) {
			$message .= ' Prior uncertain evidence: ' . implode( ' | ', array_slice( $reason_parts, -2 ) );
		}

		return array(
			'success'       => false,
			'retry_blocked' => true,
			'error'         => 'wc_pricing_retry_limit',
			'message'       => $message,
			'guardrail'     => array(
				'kind'                => 'wc_pricing_retry_limit',
				'target_type'         => 'product',
				'target_id'           => (int) ( $attempt['target_id'] ?? 0 ),
				'target_key'          => sanitize_text_field( (string) ( $attempt['target_key'] ?? '' ) ),
				'fields'              => array_values( array_map( 'sanitize_key', (array) ( $attempt['fields'] ?? array() ) ) ),
				'uncertain_attempts'  => count( $matching ),
				'attempt_limit'       => self::WC_PRICE_RETRY_LIMIT,
				'prior_evidence'      => array_slice( $reason_parts, -2 ),
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

		// v5.7.9 (2026-05-13): Count "create A page and B page" / "create a post,
		// another post, and a third post" patterns. Pre-iter-29, these parsed as
		// 1 (the regex below matched "a ... page" but didn't count repeats),
		// which caused iter-27's create_post duplicate guard to fire on the
		// second create. iter-27 made the guard tolerant of extractor mismatches;
		// iter-29 makes the extractor honest so the count itself reflects intent.
		//
		// Heuristic: when the message contains "create" and the tail (text after
		// the first "create" mention) has multiple singular noun mentions
		// (page/post/article/blog post), count them. Cap at 10 to avoid runaway
		// cases. Excludes plural forms (the explicit-numeric regex above handles
		// "two pages" / "3 articles" cleanly).
		$create_pos = stripos( $msg, 'create' );
		if ( false !== $create_pos ) {
			$tail = substr( $msg, $create_pos );
			// Match singular only — \b boundaries + (?!s\b) to exclude trailing s.
			$count = preg_match_all( '/\b(blog post|post|article|page)(?!s)\b/i', $tail, $matches );
			if ( $count >= 2 ) {
				return array( 'create_post' => min( $count, 10 ) );
			}
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

		// v5.7.10 (2026-05-13): Preserve `slug` so iter-27's create_post duplicate
		// guard can diff on slug too (not only post_title). For models that
		// auto-derive title from slug (or vice versa), slug-level disambiguation
		// is the stronger signal.
		return array(
			'post_id'     => absint( $raw['post_id'] ?? 0 ),
			'post_title'  => sanitize_text_field( $raw['post_title'] ?? '' ),
			'post_type'   => sanitize_key( $raw['post_type'] ?? '' ),
			'post_status' => sanitize_key( $raw['post_status'] ?? '' ),
			'url'         => esc_url_raw( $raw['url'] ?? '' ),
			'slug'        => sanitize_title( (string) ( $raw['slug'] ?? $raw['post_name'] ?? '' ) ),
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

	private static function read_tool_updates_current_target( string $tool_name ): bool {
		return in_array(
			sanitize_key( $tool_name ),
			array(
				'read_content',
				'read_blocks',
				'get_product',
				'get_custom_fields',
				'get_revision_history',
				'analyze_seo',
				'page_audit',
				'elementor_audit_page',
				'elementor_read_page',
			),
			true
		);
	}

	private static function merge_current_target( $existing, array $target ): array {
		$existing = self::sanitize_target( $existing );
		$target   = self::sanitize_target( $target );

		if ( empty( $target['post_id'] ) ) {
			return $existing;
		}

		if ( ! empty( $existing['post_id'] ) && (int) $existing['post_id'] === (int) $target['post_id'] ) {
			foreach ( $target as $key => $value ) {
				if ( '' !== (string) $value && 0 !== $value ) {
					$existing[ $key ] = $value;
				}
			}
			return self::sanitize_target( $existing );
		}

		return $target;
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

	private static function infer_plan_step_kind( string $tool_name, bool $preview_required ): string {
		if ( $preview_required ) {
			return 'preview';
		}

		$tool_name = sanitize_key( $tool_name );
		if ( '' === $tool_name ) {
			return 'write';
		}

		if ( class_exists( 'PressArk_Operation_Registry' ) ) {
			$operation = PressArk_Operation_Registry::resolve( $tool_name );
			if ( $operation ) {
				if ( method_exists( $operation, 'is_read' ) && $operation->is_read() ) {
					return 'read';
				}
				if ( 'preview' === (string) ( $operation->capability ?? '' ) ) {
					return 'preview';
				}
			}
		}

		if ( preg_match( '/^(get|list|read|search|analyze|inspect|measure|discover|load)_/', $tool_name ) ) {
			return 'read';
		}

		return 'write';
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

	private static function has_model_plan_tasks( array $ledger ): bool {
		foreach ( (array) ( $ledger['tasks'] ?? array() ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$metadata = is_array( $task['metadata'] ?? null ) ? $task['metadata'] : array();
			if ( 'model_plan' === sanitize_key( (string) ( $metadata['origin'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
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

	/**
	 * Mark every dynamic-execution task whose metadata.tool_name matches $tool_name as completed.
	 *
	 * @since 5.6.3
	 *
	 * Dynamic tasks are inserted by PressArk_Agent::maybe_insert_dynamic_plan_steps
	 * with metadata.tool_name set to the actual tool the model called. Without this
	 * sync, those tasks stay `pending` forever, polluting the [Continue] envelope's
	 * "Remaining: …" list with steps that just completed (O-2). Observed 2026-05-12
	 * on every preview-keep wrap round: Continue listed "Edit Content; Read Content;
	 * Search Knowledge; Update Plan" as remaining even after those tools had run.
	 *
	 * Safe to call multiple times — already-completed tasks are skipped.
	 *
	 * @param array  $ledger    Sanitized execution ledger.
	 * @param string $tool_name Tool that just succeeded (e.g. 'edit_content').
	 * @param string $evidence  Short receipt for the evidence field.
	 * @return array Mutated ledger.
	 */
	public static function mark_dynamic_task_done_by_tool( array $ledger, string $tool_name, string $evidence ): array {
		$tool_name = sanitize_key( $tool_name );
		if ( '' === $tool_name ) {
			return $ledger;
		}
		$mutated = false;
		foreach ( $ledger['tasks'] as &$task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$status = $task['status'] ?? '';
			if ( in_array( $status, array( 'completed', 'verified' ), true ) ) {
				continue;
			}
			$meta = is_array( $task['metadata'] ?? null ) ? $task['metadata'] : array();
			if ( ( $meta['origin'] ?? '' ) !== 'dynamic_execution' ) {
				continue;
			}
			if ( sanitize_key( (string) ( $meta['tool_name'] ?? '' ) ) !== $tool_name ) {
				continue;
			}
			$task['status']   = 'completed';
			$task['evidence'] = sanitize_text_field( $evidence );
			$mutated          = true;
		}
		unset( $task );
		return $mutated ? self::resolve_blocked( $ledger ) : $ledger;
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
		$retry_guard = self::sanitize_retry_guard( $target['retry_guard'] ?? array() );

		if ( '' === $tool && '' === $summary ) {
			return $ledger;
		}

		if ( empty( $retry_guard ) ) {
			foreach ( $ledger['receipts'] as $receipt ) {
				if ( $receipt['tool'] === $tool && $receipt['summary'] === $summary ) {
					return $ledger;
				}
			}
		}

		$ledger['receipts'][] = array(
			'tool'       => $tool,
			'summary'    => $summary,
			'post_id'    => absint( $target['post_id'] ?? 0 ),
			'post_title' => sanitize_text_field( $target['post_title'] ?? '' ),
			'url'        => esc_url_raw( $target['url'] ?? '' ),
			// v5.7.10: store slug for iter-27 guard's slug-level diff fallback.
			'slug'       => sanitize_title( (string) ( $target['slug'] ?? '' ) ),
		);
		if ( ! empty( $retry_guard ) ) {
			$ledger['receipts'][ count( $ledger['receipts'] ) - 1 ]['retry_guard'] = $retry_guard;
		}
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
		$data   = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$nested_sources = array();

		if ( is_array( $data['preview']['fields'] ?? null ) ) {
			$nested_sources[] = (array) $data['preview']['fields'];
		}
		if ( is_array( $result['preview']['fields'] ?? null ) ) {
			$nested_sources[] = (array) $result['preview']['fields'];
		}
		if ( ! empty( $data ) ) {
			$nested_sources[] = $data;
		}

		foreach ( $nested_sources as $source ) {
			if ( empty( $target['post_id'] ) ) {
				foreach ( array( 'post_id', 'id', 'page_id', 'product_id' ) as $key ) {
					if ( ! empty( $source[ $key ] ) ) {
						$target['post_id'] = absint( $source[ $key ] );
						break;
					}
				}
			}
			if ( empty( $target['post_title'] ) ) {
				foreach ( array( 'post_title', 'title', 'name' ) as $key ) {
					if ( ! empty( $source[ $key ] ) ) {
						$target['post_title'] = sanitize_text_field( $source[ $key ] );
						break;
					}
				}
			}
			if ( empty( $target['post_type'] ) && ! empty( $source['post_type'] ) ) {
				$target['post_type'] = sanitize_key( $source['post_type'] );
			}
			if ( empty( $target['post_status'] ) && ! empty( $source['post_status'] ) ) {
				$target['post_status'] = sanitize_key( $source['post_status'] );
			}
			if ( empty( $target['url'] ) ) {
				foreach ( array( 'url', 'permalink' ) as $key ) {
					if ( ! empty( $source[ $key ] ) ) {
						$target['url'] = esc_url_raw( $source[ $key ] );
						break;
					}
				}
			}
			if ( empty( $target['slug'] ) ) {
				foreach ( array( 'slug', 'post_name' ) as $key ) {
					if ( ! empty( $source[ $key ] ) ) {
						$target['slug'] = sanitize_title( (string) $source[ $key ] );
						break;
					}
				}
			}
		}

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
		// v5.7.10: capture slug from result (post_name is WP's term) or from args.
		if ( empty( $target['slug'] ) && ! empty( $result['post_name'] ) ) {
			$target['slug'] = sanitize_title( (string) $result['post_name'] );
		}
		if ( empty( $target['slug'] ) && ! empty( $result['slug'] ) ) {
			$target['slug'] = sanitize_title( (string) $result['slug'] );
		}

		if ( empty( $target['post_title'] ) && ! empty( $args['title'] ) ) {
			$target['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( empty( $target['slug'] ) && ! empty( $args['slug'] ) ) {
			$target['slug'] = sanitize_title( (string) $args['slug'] );
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
		if ( empty( $target['post_id'] ) && ! empty( $args['product_id'] ) ) {
			$target['post_id'] = absint( $args['product_id'] );
		}
		if ( empty( $target['post_id'] ) && ! empty( $args['products'] ) && is_array( $args['products'] ) ) {
			foreach ( $args['products'] as $product_args ) {
				if ( ! is_array( $product_args ) ) {
					continue;
				}
				$product_id = absint( $product_args['post_id'] ?? 0 );
				if ( $product_id > 0 ) {
					$target['post_id'] = $product_id;
					break;
				}
			}
		}

		if ( empty( $target['post_id'] ) && ! empty( $result['read_meta']['target_post_ids'] ) && is_array( $result['read_meta']['target_post_ids'] ) ) {
			$target_ids = array_values( array_filter( array_map( 'absint', $result['read_meta']['target_post_ids'] ) ) );
			if ( 1 === count( $target_ids ) ) {
				$target['post_id'] = (int) $target_ids[0];
			}
		}

		if ( ! empty( $target['post_id'] ) && function_exists( 'get_post' ) ) {
			$post = get_post( (int) $target['post_id'] );
			if ( $post ) {
				if ( empty( $target['post_title'] ) ) {
					$target['post_title'] = (string) $post->post_title;
				}
				if ( empty( $target['post_type'] ) ) {
					$target['post_type'] = (string) $post->post_type;
				}
				if ( empty( $target['post_status'] ) ) {
					$target['post_status'] = (string) $post->post_status;
				}
				if ( empty( $target['url'] ) && function_exists( 'get_permalink' ) ) {
					$target['url'] = (string) get_permalink( $post );
				}
				if ( empty( $target['slug'] ) ) {
					$target['slug'] = (string) $post->post_name;
				}
			}
		}

		return self::sanitize_target( $target );
	}

	private static function sanitize_retry_guard( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$fields = array();
		foreach ( (array) ( $raw['fields'] ?? array() ) as $field ) {
			$field = sanitize_key( (string) $field );
			if ( '' !== $field && in_array( $field, self::WC_PRICE_RETRY_FIELDS, true ) ) {
				$fields[] = $field;
			}
		}

		$kind       = sanitize_key( (string) ( $raw['kind'] ?? '' ) );
		$target_key = sanitize_text_field( (string) ( $raw['target_key'] ?? '' ) );
		$target_id  = absint( $raw['target_id'] ?? 0 );

		if ( 'wc_pricing' !== $kind || '' === $target_key || $target_id <= 0 || empty( $fields ) ) {
			return array();
		}

		return array(
			'kind'       => $kind,
			'target_key' => $target_key,
			'target_id'  => $target_id,
			'fields'     => array_values( array_unique( array_slice( $fields, 0, count( self::WC_PRICE_RETRY_FIELDS ) ) ) ),
		);
	}

	private static function build_retry_guard_marker( string $tool_name, array $args, array $result = array() ): array {
		$tool_name = sanitize_key( $tool_name );
		if ( ! in_array( $tool_name, self::WC_PRICE_RETRY_TOOLS, true ) ) {
			return array();
		}

		$fields = self::extract_wc_pricing_fields_from_args( $tool_name, $args );
		if ( empty( $fields ) ) {
			return array();
		}

		$target_id = self::extract_wc_pricing_target_id( $tool_name, $args, $result );
		if ( $target_id <= 0 ) {
			return array();
		}

		return array(
			'kind'       => 'wc_pricing',
			'target_key' => 'product:' . $target_id,
			'target_id'  => $target_id,
			'fields'     => $fields,
		);
	}

	private static function extract_wc_pricing_fields_from_args( string $tool_name, array $args ): array {
		$changes = array();

		if ( 'edit_product' === $tool_name ) {
			$changes = is_array( $args['changes'] ?? null ) ? $args['changes'] : array();
		} elseif ( 'bulk_edit_products' === $tool_name ) {
			$products = array_values(
				array_filter(
					(array) ( $args['products'] ?? array() ),
					static fn( $item ) => is_array( $item )
				)
			);
			if ( 1 !== count( $products ) ) {
				return array();
			}
			$changes = is_array( $products[0]['changes'] ?? null ) ? $products[0]['changes'] : array();
		}

		$fields = array();
		foreach ( array_keys( $changes ) as $field ) {
			$field = sanitize_key( (string) $field );
			if ( in_array( $field, self::WC_PRICE_RETRY_FIELDS, true ) ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	private static function extract_wc_pricing_target_id( string $tool_name, array $args, array $result = array() ): int {
		if ( 'edit_product' === $tool_name ) {
			return absint(
				$args['post_id']
				?? $result['post_id']
				?? $result['data']['id']
				?? 0
			);
		}

		if ( 'bulk_edit_products' === $tool_name ) {
			$products = array_values(
				array_filter(
					(array) ( $args['products'] ?? array() ),
					static fn( $item ) => is_array( $item )
				)
			);
			if ( 1 !== count( $products ) ) {
				return 0;
			}

			return absint(
				$products[0]['post_id']
				?? $args['post_id']
				?? $result['post_id']
				?? $result['data']['id']
				?? 0
			);
		}

		return 0;
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
