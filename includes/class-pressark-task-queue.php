<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressArk Async Task Queue
 *
 * Long-running AI tasks (full site audits, bulk operations, large content generation)
 * are queued as background jobs instead of blocking the HTTP request.
 *
 * v2.5.0: Refactored to use pluggable backends (Action Scheduler / WP-Cron)
 * and a dedicated database table for task persistence (replaces wp_options).
 *
 * Flow:
 * 1. Chat handler detects long-running intent → creates task → returns immediately
 * 2. Backend fires pressark_process_async_task hook
 * 3. Task runs in background with atomic claim locking
 * 4. Result stored in pressark_tasks table
 * 5. REST poll endpoint delivers result to active admin sessions
 * 6. Daily cleanup cron removes expired/stale tasks
 *
 * @package PressArk
 * @since   2.0.0
 * @since   2.5.0 Refactored: DB-backed persistence, pluggable backends, retry, atomic claim.
 */
class PressArk_Task_Queue {

	private PressArk_Task_Store    $store;
	private PressArk_Queue_Backend $backend;

	// Intents that should be queued rather than run synchronously (backward compat).
	const ASYNC_INTENTS = array(
		'full_site_seo_audit',
		'full_site_security_audit',
		'bulk',
		'export',
	);

	/**
	 * v3.2.0: Weighted async patterns — [regex => weight].
	 * A message qualifies for async when total weight >= ASYNC_THRESHOLD
	 * and no negative pattern fires.
	 */
	const ASYNC_PATTERNS = array(
		// Full-site scope — strong async signals.
		'/\b(?:audit|scan|check)\b.*\b(?:all|every|the (?:entire|whole))\b.*\b(?:site|pages?|posts?)\b/i' => 60,
		'/\b(?:full|complete|comprehensive)\b.*\b(?:site|seo|security)\b.*\b(?:audit|scan|report)\b/i'    => 60,
		// Bulk write operations — high cost, must be async.
		'/\bbulk\b.*\b(?:edit|update|change|replace|delete)\b/i' => 50,
		'/\b(?:set|change|update|increase|decrease|raise|lower|add|remove|rewrite|replace|optimize|fix)\b.*\b(?:all|every|across\s+all|site-?wide)\b.*\b(?:products?|pages?|posts?|titles?|descriptions?|prices?|stock|inventory|seo|meta|headings?)\b/i' => 55,
		'/\b(?:all|every)\b.*\b(?:products?|pages?|posts?|titles?|descriptions?|prices?|stock|inventory|seo|meta|headings?)\b.*\b(?:set|change|update|increase|decrease|raise|lower|add|remove|rewrite|replace|optimize|fix)\b/i' => 55,
		// Export generation.
		'/\b(?:generate|create|build)\b.*\b(?:report|export|csv)\b/i' => 40,
		// Scope amplifiers — boost when combined with an action.
		'/\b(?:all|every)\b.*\b(?:pages?|posts?|products?)\b/i' => 20,
	);

	const ASYNC_THRESHOLD = 50;

	const RETRYABLE_FAILURES = array(
		PressArk_AI_Connector::FAILURE_PROVIDER_ERROR,
		PressArk_AI_Connector::FAILURE_TRUNCATION,
		PressArk_AI_Connector::FAILURE_TOOL_ERROR,
	);

	const NON_RETRYABLE_FAILURES = array(
		PressArk_AI_Connector::FAILURE_BAD_RETRIEVAL,
		PressArk_AI_Connector::FAILURE_VALIDATION,
		PressArk_AI_Connector::FAILURE_SIDE_EFFECT_RISK,
	);

	/**
	 * v3.2.0: Negative patterns that suppress async routing.
	 * Prevents false positives where scope words appear in non-async contexts.
	 */
	const ASYNC_NEGATIVES = array(
		// Singular-target phrasing — not truly bulk.
		'/\b(?:this|the|my|one|a single)\b\s+(?:post|page|product|order)\b/i',
		// Questions about scope — asking, not requesting.
		'/\b(?:how many|do I have|what are|can you tell|show me)\b/i',
		// Already narrowed — a specific ID or title quoted.
		'/\b(?:post|page|product)\s+(?:#?\d+|"[^"]+"|\'[^\']+\')\b/i',
	);

	public function __construct() {
		$this->store = new PressArk_Task_Store();

		if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_schedule_single_action' ) ) {
			$this->backend = new PressArk_Queue_Action_Scheduler();
		} else {
			$this->backend = new PressArk_Queue_Cron();
		}
	}

	/**
	 * Check if this request should be queued async.
	 *
	 * v3.2.0: Confidence-scored. Sums weighted pattern hits, rejects if
	 * any negative pattern fires. Only queues when total >= threshold.
	 */
	public function should_queue( string $message, string $intent = '' ): bool {
		return $this->async_score( $message ) >= self::ASYNC_THRESHOLD;
	}

	/**
	 * Score a message for async routing.
	 * Exposed for testing and router disambiguation.
	 *
	 * @since 3.2.0
	 * @return int 0 if negated or no match, otherwise summed weight.
	 */
	public function async_score( string $message ): int {
		// Negative patterns reject outright.
		foreach ( self::ASYNC_NEGATIVES as $negative ) {
			if ( preg_match( $negative, $message ) ) {
				return 0;
			}
		}

		$score = 0;
		foreach ( self::ASYNC_PATTERNS as $pattern => $weight ) {
			if ( preg_match( $pattern, $message ) ) {
				$score += $weight;
			}
		}

		return $score;
	}

	private function maybe_handle_fast_path_bulk_request( string $message ): ?array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$parsed = $this->parse_bulk_product_price_request( $message );
		if ( empty( $parsed ) ) {
			return null;
		}

		$logger     = new PressArk_Action_Logger();
		$woo        = new PressArk_Handler_WooCommerce( $logger );
		$params     = array(
			'scope'   => 'all',
			'status'  => 'publish',
			'limit'   => 50,
			'changes' => $parsed['changes'],
		);
		$preview    = $woo->preview_bulk_edit_products( $params, array( 'type' => 'bulk_edit_products', 'params' => $params ) );
		$matched    = 0;
		$truncated  = false;

		if ( is_array( $preview['summary'] ?? null ) ) {
			$matched = 0;
		}
		if ( preg_match( '/(\d+)/', (string) ( $preview['summary'] ?? '' ), $count_match ) ) {
			$matched = (int) $count_match[1];
		}
		if ( ! empty( $preview['note'] ) ) {
			$truncated = true;
		}

		$checkpoint = PressArk_Checkpoint::from_array( array() );
		$checkpoint->sync_execution_goal( $message );
		$checkpoint->set_goal( mb_substr( $message, 0, 200 ) );
		$checkpoint->set_loaded_tool_groups( array( 'woocommerce' ) );
		$checkpoint->set_workflow_stage( 'preview' );
		$checkpoint->set_context_capsule( array(
			'summary'   => sanitize_text_field( (string) ( $preview['summary'] ?? 'Prepared a bulk WooCommerce update for confirmation.' ) ),
			'remaining' => array( 'Confirm the bulk WooCommerce update to apply it.' ),
			'scope'     => array( 'woocommerce', 'products', 'bulk' ),
		) );

		$message_text = ! empty( $parsed['description'] )
			? sprintf(
				'Prepared a WooCommerce bulk update to %s for published products. Review the preview and confirm to apply it.',
				$parsed['description']
			)
			: 'Prepared a WooCommerce bulk product update. Review the preview and confirm to apply it.';

		if ( $matched > 0 ) {
			$message_text = sprintf(
				'Prepared a WooCommerce bulk update for %1$d published product(s) to %2$s. Review the preview and confirm to apply it.',
				$matched,
				$parsed['description']
			);
		}
		if ( $truncated ) {
			$message_text .= ' This preview covers the first batch of matching products.';
		}

		return array(
			'type'               => 'confirm_card',
			'message'            => $message_text,
			'pending_actions'    => array(
				array(
					'type'   => 'bulk_edit_products',
					'params' => $params,
				),
			),
			'tokens_used'        => 0,
			'icu_spent'          => 0,
			'input_tokens'       => 0,
			'output_tokens'      => 0,
			'cache_read_tokens'  => 0,
			'cache_write_tokens' => 0,
			'provider'           => 'fast_path',
			'model'              => 'deterministic/bulk-parser',
			'agent_rounds'       => 0,
			'task_type'          => 'edit',
			'loaded_groups'      => array( 'woocommerce' ),
			'tool_loading'       => array(
				'initial_groups' => array( 'woocommerce' ),
				'final_groups'   => array( 'woocommerce' ),
				'discover_calls' => 0,
				'load_calls'     => 0,
				'initial_count'  => 1,
				'final_count'    => 1,
			),
			'checkpoint'         => $checkpoint->to_array(),
			'plan'               => array(
				'steps'        => array( 'prepare a bulk WooCommerce price update', 'wait for user confirmation' ),
				'current_step' => 1,
			),
			'steps'              => array(),
		);
	}

	private function parse_bulk_product_price_request( string $message ): array {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return array();
		}

		if ( ! preg_match( '/\bproducts?\b/', $normalized ) || ! preg_match( '/\b(?:all|every|across all|across all the)\b/', $normalized ) ) {
			return array();
		}

		$number_pattern = '([0-9]+(?:[\\.,][0-9]+)?)';

		if ( preg_match( '/\b(?:add|increase|raise)\b\s+\$?' . $number_pattern . '\s*(?:usd|dollars?)?\b.*\bproducts?\b/i', $normalized, $match ) ) {
			$amount = (float) str_replace( ',', '.', $match[1] );
			return array(
				'changes'     => array( 'price_delta' => (string) $amount ),
				'description' => sprintf( 'add %s to the regular price', $amount ),
			);
		}

		if ( preg_match( '/\b(?:decrease|lower|reduce)\b\s+\$?' . $number_pattern . '\s*(?:usd|dollars?)?\b.*\bproducts?\b/i', $normalized, $match ) ) {
			$amount = (float) str_replace( ',', '.', $match[1] );
			return array(
				'changes'     => array( 'price_delta' => (string) ( 0 - $amount ) ),
				'description' => sprintf( 'lower the regular price by %s', $amount ),
			);
		}

		if ( preg_match( '/\b(?:add|increase|raise)\b\s+' . $number_pattern . '\s*%\b.*\bproducts?\b/i', $normalized, $match ) ) {
			$amount = (float) str_replace( ',', '.', $match[1] );
			return array(
				'changes'     => array( 'price_adjust_pct' => (string) $amount ),
				'description' => sprintf( 'raise regular prices by %s%%', $amount ),
			);
		}

		if ( preg_match( '/\b(?:decrease|lower|reduce)\b\s+' . $number_pattern . '\s*%\b.*\bproducts?\b/i', $normalized, $match ) ) {
			$amount = (float) str_replace( ',', '.', $match[1] );
			return array(
				'changes'     => array( 'price_adjust_pct' => (string) ( 0 - $amount ) ),
				'description' => sprintf( 'lower regular prices by %s%%', $amount ),
			);
		}

		if ( preg_match( '/\b(?:set|change|update)\b.*\bproducts?\b.*\bto\b\s+\$?' . $number_pattern . '\s*(?:usd|dollars?)?\b/i', $normalized, $match ) ) {
			$amount = (float) str_replace( ',', '.', $match[1] );
			return array(
				'changes'     => array( 'regular_price' => (string) $amount ),
				'description' => sprintf( 'set the regular price to %s', $amount ),
			);
		}

		return array();
	}

	/**
	 * Build a compact, inspectable handoff capsule for queued background work.
	 *
	 * @param string     $message          Original request text.
	 * @param array      $conversation     Conversation snapshot at enqueue time.
	 * @param array      $loaded_groups    Loaded tool groups.
	 * @param array|null $checkpoint_data  Checkpoint snapshot, if available.
	 * @param array      $resolved_context Bounded resolved context.
	 * @param string     $source           Handoff source key.
	 * @return array<string,mixed>
	 */
	public static function build_handoff_capsule(
		string $message,
		array $conversation = array(),
		array $loaded_groups = array(),
		?array $checkpoint_data = null,
		array $resolved_context = array(),
		string $source = 'queue_handoff'
	): array {
		$capsule = array(
			'source'             => sanitize_key( $source ),
			'request'            => mb_substr( sanitize_textarea_field( $message ), 0, 240 ),
			'conversation_turns' => max( 0, count( $conversation ) ),
			'loaded_groups'      => array_values( array_unique( array_filter( array_map(
				'sanitize_text_field',
				array_slice( $loaded_groups, 0, 8 )
			) ) ) ),
			'updated_at'         => current_time( 'mysql', true ),
		);

		$context = array();
		if ( ! empty( $resolved_context['chat_id'] ) ) {
			$context['chat_id'] = absint( $resolved_context['chat_id'] );
		}
		if ( ! empty( $resolved_context['automation_id'] ) ) {
			$context['automation_id'] = sanitize_text_field( (string) $resolved_context['automation_id'] );
		}
		if ( ! empty( $resolved_context['post_id'] ) ) {
			$context['post_id'] = absint( $resolved_context['post_id'] );
		}
		if ( ! empty( $resolved_context['screen'] ) ) {
			$context['screen'] = sanitize_key( (string) $resolved_context['screen'] );
		}
		if ( ! empty( $context ) ) {
			$capsule['context'] = $context;
		}

		if ( ! empty( $checkpoint_data ) && class_exists( 'PressArk_Checkpoint' ) ) {
			$checkpoint      = PressArk_Checkpoint::from_array( $checkpoint_data );
			$context_capsule = $checkpoint->get_context_capsule();
			$target          = $checkpoint->get_selected_target();
			$progress        = class_exists( 'PressArk_Execution_Ledger' )
				? PressArk_Execution_Ledger::progress_snapshot( $checkpoint->get_execution() )
				: array();
			$checkpoint_view = $checkpoint->to_array();
			$bundle_ids      = array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( $checkpoint->get_bundle_ids(), 0, 6 )
			) ) );

			if ( ! empty( $context_capsule['summary'] ) ) {
				$capsule['summary'] = sanitize_text_field( (string) $context_capsule['summary'] );
			}
			if ( ! empty( $context_capsule['active_request'] ) || ! empty( $context_capsule['task'] ) ) {
				$capsule['active_request'] = sanitize_text_field(
					(string) ( $context_capsule['active_request'] ?? $context_capsule['task'] )
				);
			}
			if ( ! empty( $target ) ) {
				$capsule['target'] = self::format_handoff_target( $target );
			}
			if ( '' !== $checkpoint->get_workflow_stage() ) {
				$capsule['workflow_stage'] = sanitize_key( $checkpoint->get_workflow_stage() );
			}
			if ( empty( $capsule['loaded_groups'] ) ) {
				$capsule['loaded_groups'] = array_values( array_unique( array_filter( array_map(
					'sanitize_text_field',
					array_slice( $checkpoint->get_loaded_tool_groups(), 0, 8 )
				) ) ) );
			}
			if ( ! empty( $bundle_ids ) ) {
				$capsule['bundle_ids'] = $bundle_ids;
			}
			if ( ! empty( $progress['completed_labels'] ) ) {
				$capsule['completed'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					array_slice( (array) $progress['completed_labels'], 0, 4 )
				) ) );
			}
			if ( ! empty( $progress['remaining_labels'] ) ) {
				$capsule['remaining'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					array_slice( (array) $progress['remaining_labels'], 0, 4 )
				) ) );
			}
			if ( ! empty( $context_capsule['recent_receipts'] ) ) {
				$capsule['recent_receipts'] = array_values( array_filter( array_map(
					'sanitize_text_field',
					array_slice( (array) $context_capsule['recent_receipts'], 0, 4 )
				) ) );
			}
			if ( ! empty( $checkpoint_view['plan_state']['phase'] ) ) {
				$capsule['plan_phase'] = sanitize_key( (string) $checkpoint_view['plan_state']['phase'] );
			}
			if ( ! empty( $checkpoint_view['turn'] ) ) {
				$capsule['checkpoint_turn'] = absint( $checkpoint_view['turn'] );
			}
			if ( ! empty( $capsule['loaded_groups'] ) || ! empty( $bundle_ids ) ) {
				$capsule['batch_provenance'] = array_filter(
					array(
						'loaded_groups'      => (array) ( $capsule['loaded_groups'] ?? array() ),
						'bundle_ids'         => $bundle_ids,
						'conversation_turns' => max( 0, count( $conversation ) ),
					),
					static function ( $value ) {
						return ! ( is_array( $value ) ? empty( $value ) : 0 === (int) $value );
					}
				);
			}
		}

		if ( empty( $capsule['summary'] ) ) {
			$capsule['summary'] = ! empty( $capsule['request'] )
				? sanitize_text_field( (string) $capsule['request'] )
				: 'Background queue handoff persisted.';
		}
		if ( empty( $capsule['active_request'] ) && ! empty( $capsule['request'] ) ) {
			$capsule['active_request'] = sanitize_text_field( (string) $capsule['request'] );
		}

		return array_filter(
			$capsule,
			static function ( $value ) {
				if ( is_array( $value ) ) {
					return ! empty( $value );
				}
				if ( is_int( $value ) ) {
					return $value > 0;
				}
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Render a selected-target snapshot into one compact label.
	 *
	 * @param array<string,mixed> $target Selected target payload.
	 * @return string
	 */
	private static function format_handoff_target( array $target ): string {
		$title = sanitize_text_field( (string) ( $target['title'] ?? '' ) );
		$id    = absint( $target['id'] ?? $target['post_id'] ?? 0 );
		$type  = sanitize_key( (string) ( $target['type'] ?? '' ) );
		$parts = array();

		if ( '' !== $title ) {
			$parts[] = $title;
		}
		if ( $id > 0 ) {
			$parts[] = '#' . $id;
		}
		if ( '' !== $type ) {
			$parts[] = '(' . $type . ')';
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Create and queue a task. Returns immediately with task ID.
	 *
	 * Accepts optional idempotency_key for callers that have real
	 * lineage to preserve (for example automations or explicit retries).
	 *
	 * @since 2.5.0 Added $loaded_groups and $checkpoint_data parameters.
	 * @since 3.3.0 Added $resolved_context for async context fidelity.
	 * @since 3.7.0 Added idempotency_key support.
	 */
	public function enqueue(
		string $message,
		array  $conversation,
		array  $intent_result = array(),
		int    $user_id = 0,
		bool   $deep_mode = false,
		string $reservation_id = '',
		array  $loaded_groups = array(),
		?array $checkpoint_data = null,
		string $run_id = '',
		array  $resolved_context = array(),
		string $idempotency_key = '',
		array  $lineage = array()
	): array {
		$task_id       = wp_generate_uuid4();
		$user_id       = $user_id ?: get_current_user_id();
		$trace_context = PressArk_Activity_Trace::current_context();
		$correlation_id = (string) ( $trace_context['correlation_id'] ?? '' );
		$parent_run_id = sanitize_text_field( (string) ( $lineage['parent_run_id'] ?? '' ) );
		$root_run_id   = sanitize_text_field( (string) ( $lineage['root_run_id'] ?? '' ) );
		$handoff_capsule = ! empty( $lineage['handoff_capsule'] ) && is_array( $lineage['handoff_capsule'] )
			? $lineage['handoff_capsule']
			: self::build_handoff_capsule(
				$message,
				$conversation,
				$loaded_groups,
				$checkpoint_data,
				$resolved_context,
				! empty( $resolved_context['automation_id'] ) ? 'automation_handoff' : 'async_handoff'
			);

		if ( '' === $correlation_id && '' !== $run_id ) {
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get( $run_id );
			if ( $run ) {
				$correlation_id = (string) ( $run['correlation_id'] ?? '' );
			}
		}

		if ( '' === $root_run_id ) {
			if ( '' !== $parent_run_id ) {
				$root_run_id = $parent_run_id;
			} elseif ( '' !== $run_id ) {
				$root_run_id = $run_id;
			}
		}

		$idem_key = ! empty( $idempotency_key )
			? sanitize_text_field( $idempotency_key )
			: null;

		$create = $this->store->create_record( array(
			'task_id'        => $task_id,
			'run_id'         => $run_id,
			'parent_run_id'  => $parent_run_id,
			'root_run_id'    => $root_run_id,
			'user_id'        => $user_id,
			'message'        => $message,
			'payload'        => array(
				'conversation'     => array_slice( $conversation, -10 ),
				'deep_mode'        => $deep_mode,
				'loaded_groups'    => $loaded_groups,
				'checkpoint'       => $checkpoint_data,
				'run_id'           => $run_id,
				'parent_run_id'    => $parent_run_id,
				'root_run_id'      => $root_run_id,
				'handoff_capsule'  => $handoff_capsule,
				'correlation_id'   => $correlation_id,
				// v3.3.0: Resolved context captured at enqueue time so async
				// execution has the same environmental awareness as foreground.
				'resolved_context' => $resolved_context,
			),
			'handoff_capsule' => $handoff_capsule,
			'reservation_id'  => $reservation_id,
			'idempotency_key' => $idem_key,
			'max_retries'     => 2,
		) );
		$task_id = $create['task_id'] ?? '';

		if ( '' === $task_id ) {
			return array(
				'type'    => 'error',
				'error'   => 'task_persist_failed',
				'message' => __( 'PressArk could not queue this background job. Please try again.', 'pressark' ),
			);
		}

		if ( ! empty( $create['created'] ) ) {
			$scheduled = $this->backend->schedule( $task_id, 5 );
			if ( ! $scheduled ) {
				$this->store->fail_queued(
					$task_id,
					sprintf( 'Async backend "%s" could not schedule the task.', $this->backend->get_name() )
				);

				return array(
					'type'    => 'error',
					'error'   => 'task_schedule_failed',
					'message' => __( 'PressArk saved the task but could not schedule it to run. Please try again.', 'pressark' ),
					'task_id' => $task_id,
				);
			}
		}

		$existing_task = null;
		if ( ! empty( $create['reused_existing'] ) ) {
			$existing_task = $this->store->get( $task_id );
		}

		if ( ! empty( $create['created'] ) ) {
			// v4.2.0: Link the task to its durable run for cross-referencing.
			if ( ! empty( $run_id ) ) {
				$run_store = new PressArk_Run_Store();
				$run_store->link_task( $run_id, $task_id );
			}

			PressArk_Activity_Trace::publish(
				array(
					'event_type' => 'worker.handoff',
					'phase'      => 'async',
					'status'     => 'queued',
					'reason'     => 'queue_handoff',
					'summary'    => 'Background worker handoff persisted to the queue.',
					'payload'    => array(
						'max_retries'      => 2,
						'backend'          => $this->backend->get_name(),
						'parent_run_id'    => $parent_run_id,
						'root_run_id'      => $root_run_id,
						'handoff_summary'  => sanitize_text_field( (string) ( $handoff_capsule['summary'] ?? '' ) ),
						'workflow_stage'   => sanitize_key( (string) ( $handoff_capsule['workflow_stage'] ?? '' ) ),
						'loaded_groups'    => (array) ( $handoff_capsule['loaded_groups'] ?? array() ),
						'bundle_ids'       => (array) ( $handoff_capsule['bundle_ids'] ?? array() ),
						'batch_provenance' => (array) ( $handoff_capsule['batch_provenance'] ?? array() ),
					),
				),
				array(
					'correlation_id' => $correlation_id,
					'run_id'         => $run_id,
					'task_id'        => $task_id,
					'reservation_id' => $reservation_id,
					'user_id'        => $user_id,
					'chat_id'        => (int) ( $resolved_context['chat_id'] ?? 0 ),
					'route'          => ! empty( $resolved_context['automation_id'] ) ? 'automation' : 'async',
				)
			);
		}

		if ( ! empty( $create['reused_existing'] ) && is_array( $existing_task ) ) {
			PressArk_Activity_Trace::publish(
				array(
					'event_type' => 'worker.handoff',
					'phase'      => 'async',
					'status'     => 'queued',
					'reason'     => 'queue_handoff',
					'summary'    => 'Background handoff reused the already active queued task.',
					'payload'    => array(
						'reused_existing' => true,
						'backend'         => $this->backend->get_name(),
						'run_id'          => (string) ( $existing_task['run_id'] ?? '' ),
						'parent_run_id'   => (string) ( $existing_task['parent_run_id'] ?? $parent_run_id ),
						'root_run_id'     => (string) ( $existing_task['root_run_id'] ?? $root_run_id ),
					),
				),
				array(
					'correlation_id' => $correlation_id,
					'run_id'         => (string) ( $existing_task['run_id'] ?? '' ),
					'task_id'        => $task_id,
					'reservation_id' => $reservation_id,
					'user_id'        => $user_id,
					'chat_id'        => (int) ( $resolved_context['chat_id'] ?? 0 ),
					'route'          => ! empty( $resolved_context['automation_id'] ) ? 'automation' : 'async',
				)
			);
		}

		return array(
			'type'            => 'queued',
			'task_id'         => $task_id,
			'run_id'          => ! empty( $run_id ) ? $run_id : (string) ( $existing_task['run_id'] ?? '' ),
			'parent_run_id'   => $parent_run_id ?: (string) ( $existing_task['parent_run_id'] ?? '' ),
			'root_run_id'     => $root_run_id ?: (string) ( $existing_task['root_run_id'] ?? '' ),
			'reused_existing' => ! empty( $create['reused_existing'] ),
			'message' => "I'm working on that in the background. You can track progress and view results in the Activity page — even if you close this tab.",
		);
	}

	/**
	 * Process a queued task. Called by WP-Cron or Action Scheduler.
	 *
	 * Uses atomic claim to prevent double-processing, and retries with
	 * exponential backoff on failure (30s, 60s, 120s).
	 */
	public function process( string $task_id ): void {
		$task = $this->store->get( $task_id );

		if ( ! $task ) {
			return;
		}

		// Atomic claim — prevents double-processing.
		if ( ! $this->store->claim( $task_id ) ) {
			return;
		}

		$reservation_id = $task['reservation_id'] ?? '';
		$payload        = $task['payload'] ?? array();
		$run_id         = (string) ( $task['run_id'] ?? ( $payload['run_id'] ?? '' ) );
		$parent_run_id  = (string) ( $task['parent_run_id'] ?? ( $payload['parent_run_id'] ?? '' ) );
		$root_run_id    = (string) ( $task['root_run_id'] ?? ( $payload['root_run_id'] ?? '' ) );
		$correlation_id = (string) ( $payload['correlation_id'] ?? '' );
		$run            = null;
		$pipeline       = null;
		$throttle       = null;

		if ( '' !== $run_id ) {
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get( $run_id );
			if ( $run ) {
				if ( '' === $correlation_id ) {
					$correlation_id = (string) ( $run['correlation_id'] ?? '' );
				}
				if ( empty( $reservation_id ) ) {
					$reservation_id = (string) ( $run['reservation_id'] ?? '' );
				}
				if ( '' === $parent_run_id ) {
					$parent_run_id = (string) ( $run['parent_run_id'] ?? '' );
				}
				if ( '' === $root_run_id ) {
					$root_run_id = (string) ( $run['root_run_id'] ?? '' );
				}
			}
		}

		$resolved_pre = $payload['resolved_context'] ?? array();
		$route        = $run['route'] ?? ( ! empty( $resolved_pre['automation_id'] ) ? 'automation' : 'async' );
		if ( '' === $root_run_id && '' !== $run_id ) {
			$root_run_id = $run_id;
		}

		PressArk_Activity_Trace::clear_current_context();
		PressArk_Activity_Trace::set_current_context(
			array(
				'correlation_id' => $correlation_id,
				'run_id'         => $run_id,
				'task_id'        => $task_id,
				'reservation_id' => $reservation_id,
				'chat_id'        => (int) ( $resolved_pre['chat_id'] ?? ( $run['chat_id'] ?? 0 ) ),
				'user_id'        => (int) ( $task['user_id'] ?? 0 ),
				'route'          => (string) $route,
			)
		);

		PressArk_Activity_Trace::publish(
			array(
				'event_type' => 'worker.claimed',
				'phase'      => 'async',
				'status'     => 'running',
				'reason'     => 'worker_claimed',
				'summary'    => 'Background worker claimed the queued task.',
				'payload'    => array(
					'attempt'       => (int) ( $task['retries'] ?? 0 ) + 1,
					'parent_run_id' => $parent_run_id,
					'root_run_id'   => $root_run_id,
					'backend'       => $this->backend->get_name(),
				),
			)
		);

		try {
			wp_set_current_user( (int) $task['user_id'] );

			$license = new PressArk_License();
			$tier    = $license->get_tier();

			if ( $run && 'running' !== (string) ( $run['status'] ?? 'running' ) ) {
				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.cancelled',
						'phase'      => 'async',
						'status'     => 'cancelled',
						'reason'     => 'worker_cancelled',
						'summary'    => 'Background worker stopped because its parent run was already cancelled or terminal.',
						'payload'    => array(
							'run_status'     => sanitize_key( (string) ( $run['status'] ?? '' ) ),
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);
				$this->store->fail( $task_id, 'Background worker cancelled before execution started.' );
				return;
			}

			$throttle = new PressArk_Throttle();
			$slot_id  = $throttle->acquire_slot( (int) $task['user_id'], $tier );
			if ( ! $slot_id ) {
				$payload['defer_count'] = (int) ( $payload['defer_count'] ?? 0 ) + 1;
				$this->store->update_payload( $task_id, $payload );

				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.slot_contention',
						'phase'      => 'async',
						'status'     => 'waiting',
						'reason'     => 'worker_slot_contention',
						'summary'    => 'Background worker could not acquire a concurrency slot.',
						'payload'    => array(
							'defer_count'    => (int) $payload['defer_count'],
							'active_slots'   => $throttle->active_slots( (int) $task['user_id'] ),
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);

				if ( $this->store->defer( $task_id ) ) {
					$delay = min( 60, 10 + ( ( (int) $payload['defer_count'] ) - 1 ) * 10 );
					PressArk_Activity_Trace::publish(
						array(
							'event_type' => 'worker.deferred',
							'phase'      => 'async',
							'status'     => 'queued',
							'reason'     => 'worker_deferred',
							'summary'    => 'Background worker deferred the task and re-queued it for a later slot.',
							'payload'    => array(
								'delay_seconds'  => $delay,
								'defer_count'    => (int) $payload['defer_count'],
								'parent_run_id'  => $parent_run_id,
								'root_run_id'    => $root_run_id,
							),
						)
					);
					$this->backend->schedule( $task_id, $delay );
				}
				return;
			}

			$agent = pressark_get_agent( (int) $task['user_id'] );

			// v3.7.1: Set async task context for business idempotency.
			// Handlers can now check has_operation_receipt() / record_operation_receipt()
			// to skip already-committed destructive operations on retry.
			$agent->set_async_context( $task_id );

			// v4.0.0: Set automation context for unattended execution.
			if ( ! empty( $resolved_pre['automation_id'] ) ) {
				$auto_store  = new PressArk_Automation_Store();
				$auto_record = $auto_store->get( $resolved_pre['automation_id'] );
				if ( $auto_record ) {
					$agent->set_automation_context( $auto_record );
				}
			}

			// v3.3.0: Recover resolved context captured at enqueue time.
			$resolved = $payload['resolved_context'] ?? array();
			$async_screen  = $resolved['screen']  ?? '';
			$async_post_id = (int) ( $resolved['post_id'] ?? 0 );
			$async_chat_id = (int) ( $resolved['chat_id'] ?? 0 );
			$async_user_id = (int) $task['user_id'];
			$agent->set_run_context( $run_id, $async_chat_id );

			// v3.3.0: Reload fresh conversation + checkpoint from server
			// at execution time. The enqueued snapshot may be stale if the
			// user continued chatting between enqueue and process.
			$async_conversation    = $payload['conversation'] ?? array();
			$async_checkpoint_data = $payload['checkpoint']   ?? null;

			if ( $async_chat_id > 0 ) {
				$chat_history  = new PressArk_Chat_History();
				$stored_chat   = $chat_history->get_chat( $async_chat_id );

				if ( $stored_chat && is_array( $stored_chat['messages'] ) && ! empty( $stored_chat['messages'] ) ) {
					$async_conversation = $stored_chat['messages'];
				}

				$server_cp = PressArk_Checkpoint::load( $async_chat_id, $async_user_id );
				if ( $server_cp && ! $server_cp->is_empty() ) {
					$async_checkpoint_data = $server_cp->to_array();
				}
			}

			$result = $this->maybe_handle_fast_path_bulk_request( (string) $task['message'] );

			if ( null === $result ) {
				$result = $agent->run(
					$task['message'],
					$async_conversation,
					$payload['deep_mode']     ?? false,
					$async_screen,                            // v3.3.0: preserved from foreground
					$async_post_id,                           // v3.3.0: preserved from foreground
					$payload['loaded_groups'] ?? array(),     // v2.3.1: preserved through async
					$async_checkpoint_data                    // v3.3.0: fresh from server
				);
			}

			if ( ! empty( $result['is_error'] ) ) {
				$failure_class = $this->classify_result_failure( $result );
				if ( '' !== $failure_class ) {
					$result['failure_class'] = $failure_class;
				}

				$payload = $this->merge_retry_payload( $payload, $result, $failure_class );
				$this->store->update_payload( $task_id, $payload );

				if ( $this->should_retry_failure( $failure_class, $task, $payload, $result ) ) {
					$this->store->fail(
						$task_id,
						$this->format_failure_reason( $failure_class, (string) ( $result['message'] ?? 'Async task failed.' ) )
					);
					$this->store->retry( $task_id );
					$attempt = (int) $task['retries'];
					$delay   = $this->retry_backoff( $attempt, $failure_class );
					PressArk_Activity_Trace::publish(
						array(
							'event_type' => 'worker.retry_scheduled',
							'phase'      => 'async',
							'status'     => 'retrying',
							'reason'     => 'retry_async_failure',
							'summary'    => 'Async retry scheduled after a retryable failure.',
							'payload'    => array(
								'attempt'        => $attempt + 1,
								'failure_class'  => (string) $failure_class,
								'delay_seconds'  => $delay,
								'parent_run_id'  => $parent_run_id,
								'root_run_id'    => $root_run_id,
							),
						)
					);
					$this->backend->schedule( $task_id, $delay );
					return;
				}
			}

			// v3.0.0: Settle via unified pipeline (same path as sync).
			$reservation = new PressArk_Reservation();
			$tracker     = new PressArk_Usage_Tracker();

			$pipeline = new PressArk_Pipeline( $reservation, $tracker, $throttle, $tier );
			if ( ! empty( $reservation_id ) ) {
				$pipeline->register_resources( $reservation_id, (int) $task['user_id'], true, $slot_id );
			}

			if ( 'confirm_card' === ( $result['type'] ?? '' ) && ! empty( $result['pending_actions'] ) ) {
				$result['pending_actions'] = $pipeline->build_pending_actions(
					$result['pending_actions'],
					array( PressArk_Preview_Builder::instance(), 'build' )
				);
			}

			$token_status = $pipeline->settle( $result, 'async' );
			$pipeline->track_usage( $result, 'async' );

			$result['token_status'] = $token_status;
			$result['usage']        = $tracker->get_usage_data();

			// v3.3.0: Persist checkpoint server-side after async completion.
			if ( $async_chat_id > 0 && ! empty( $result['checkpoint'] ) ) {
				$cp = PressArk_Checkpoint::from_array( $result['checkpoint'] );
				$cp->touch();
				$cp->save( $async_chat_id, $async_user_id );
			}

			// v3.7.2: Async must mirror sync lifecycle — pause runs on
			// preview/confirm_card instead of blindly settling them.
			// The sync path in process_chat() pauses for preview and
			// confirm_card; async must do the same so that user approval
			// is required before the run can be settled.
			if ( ! empty( $run_id ) ) {
				$result_type = $result['type'] ?? 'final_response';
				$run_store   = new PressArk_Run_Store();

				if ( 'preview' === $result_type ) {
					$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
					$run_store->pause_for_preview(
						$run_id,
						$result['preview_session_id'] ?? '',
						$pause_state
					);
				} elseif ( 'confirm_card' === $result_type ) {
					$pause_state = PressArk_Run_Store::build_pause_state( $result, 'preview' );
					$run_store->pause_for_confirm(
						$run_id,
						$result['pending_actions'] ?? array(),
						$pause_state
					);
				} else {
					$result = PressArk_Pipeline::settle_run( $run_id, $result );
				}
			}

			// v4.0.0: Post-execution hook for automation-triggered tasks.
			$automation_id = $resolved['automation_id'] ?? '';
			$task_result   = $result;
			$task_failed   = false;
			$task_error    = '';

			if ( $automation_id && class_exists( 'PressArk_Automation_Service' ) ) {
				$outcome = PressArk_Automation_Service::handle_completion( $automation_id, $run_id, $task_id, $result );
				if ( isset( $outcome['result'] ) && is_array( $outcome['result'] ) ) {
					$task_result = $outcome['result'];
				}
				if ( empty( $outcome['success'] ) ) {
					$outcome_result = ( isset( $outcome['result'] ) && is_array( $outcome['result'] ) ) ? $outcome['result'] : array();
					$task_failed = true;
					$task_error  = (string) ( $outcome['error'] ?? $outcome_result['message'] ?? 'Automation run failed.' );
				}
			}

			if ( $task_failed ) {
				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.completed',
						'phase'      => 'async',
						'status'     => 'failed',
						'reason'     => 'worker_completed',
						'summary'    => 'Background worker completed with a terminal failure.',
						'payload'    => array(
							'result_type'    => (string) ( $task_result['type'] ?? '' ),
							'error'          => $task_error,
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);
				$this->store->fail( $task_id, $task_error );
			} else {
				if ( $async_chat_id > 0 && empty( $task_result['chat_id'] ) ) {
					$task_result['chat_id'] = $async_chat_id;
				}
				if ( ! empty( $run_id ) && empty( $task_result['run_id'] ) ) {
					$task_result['run_id'] = $run_id;
				}
				if ( '' !== $correlation_id && empty( $task_result['correlation_id'] ) ) {
					$task_result['correlation_id'] = $correlation_id;
				}
				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.completed',
						'phase'      => 'async',
						'status'     => 'succeeded',
						'reason'     => 'worker_completed',
						'summary'    => 'Background worker completed and persisted its result.',
						'payload'    => array(
							'result_type'    => (string) ( $task_result['type'] ?? 'final_response' ),
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);
				$this->store->complete( $task_id, $task_result );
			}

		} catch ( \Throwable $e ) {
			$failure_class = $this->classify_throwable_failure( $e );
			$payload       = $this->merge_retry_payload( $payload, array(), $failure_class, $e->getMessage() );
			$this->store->update_payload( $task_id, $payload );
			$this->store->fail( $task_id, $this->format_failure_reason( $failure_class, $e->getMessage() ) );

			// Always log async failures — background tasks have no other
			// observability channel, and support needs these in prod.
			PressArk_Error_Tracker::error( 'TaskQueue', 'Async task failed', array( 'task_id' => $task_id, 'attempt' => ( $task['retries'] ?? 0 ) + 1, 'failure_class' => $failure_class ?: 'unclassified', 'error' => $e->getMessage() ) );

			// Retry only retryable failure classes with a safe checkpoint.
			if ( $this->should_retry_failure( $failure_class, $task, $payload ) ) {
				$this->store->retry( $task_id );
				$attempt = (int) $task['retries'];
				$delay   = $this->retry_backoff( $attempt, $failure_class );
				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.retry_scheduled',
						'phase'      => 'async',
						'status'     => 'retrying',
						'reason'     => 'retry_async_failure',
						'summary'    => 'Async retry scheduled after an exception.',
						'payload'    => array(
							'attempt'        => $attempt + 1,
							'failure_class'  => (string) $failure_class,
							'delay_seconds'  => $delay,
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);
				$this->backend->schedule( $task_id, $delay );
			} else {
				// v3.7.0: Final failure — move to dead_letter for supportability.
				PressArk_Activity_Trace::publish(
					array(
						'event_type' => 'worker.completed',
						'phase'      => 'async',
						'status'     => 'failed',
						'reason'     => 'worker_completed',
						'summary'    => 'Background worker failed permanently and moved the task to dead letter.',
						'payload'    => array(
							'failure_class'  => (string) $failure_class,
							'parent_run_id'  => $parent_run_id,
							'root_run_id'    => $root_run_id,
						),
					)
				);
				$this->store->dead_letter( $task_id, 'All retries exhausted: ' . $this->format_failure_reason( $failure_class, $e->getMessage() ) );

				// Release reservation.
				if ( ! empty( $reservation_id ) ) {
					$reservation = new PressArk_Reservation();
					$reservation->fail( $reservation_id, 'Async task failed permanently: ' . $this->format_failure_reason( $failure_class, $e->getMessage() ) );
				}
				// v3.1.0: Fail the durable run via pipeline authority.
				if ( ! empty( $run_id ) ) {
					PressArk_Pipeline::fail_run( $run_id, 'Async task failed permanently: ' . $this->format_failure_reason( $failure_class, $e->getMessage() ) );
				}

				// v4.0.0: Notify automation of permanent failure.
				$resolved_ctx    = $payload['resolved_context'] ?? array();
				$auto_id_on_fail = $resolved_ctx['automation_id'] ?? '';
				if ( $auto_id_on_fail && class_exists( 'PressArk_Automation_Service' ) ) {
					PressArk_Automation_Service::handle_failure(
						$auto_id_on_fail,
						$run_id,
						$this->format_failure_reason( $failure_class, $e->getMessage() ),
						$task_id
					);
				}
			}
		} finally {
			if ( $pipeline ) {
				$pipeline->cleanup();
			}
			PressArk_Activity_Trace::clear_current_context();
		}
	}

	private function classify_result_failure( array $result ): string {
		$declared = sanitize_key( (string) ( $result['failure_class'] ?? '' ) );
		if ( '' !== $declared ) {
			return $declared;
		}

		if ( empty( $result['is_error'] ) ) {
			return '';
		}

		$message = strtolower( (string) ( $result['message'] ?? '' ) );
		if ( str_contains( $message, 'valid json' ) || str_contains( $message, 'schema' ) || str_contains( $message, 'unsupported field' ) ) {
			return PressArk_AI_Connector::FAILURE_VALIDATION;
		}

		if ( str_contains( $message, 'could not find' ) || str_contains( $message, 'could not determine' ) || str_contains( $message, 'multiple similar' ) || str_contains( $message, 'specify' ) ) {
			return PressArk_AI_Connector::FAILURE_BAD_RETRIEVAL;
		}

		if ( str_contains( $message, 'failed to read' ) || str_contains( $message, 'tool' ) || str_contains( $message, 'failed to search' ) ) {
			return PressArk_AI_Connector::FAILURE_TOOL_ERROR;
		}

		if ( str_contains( $message, 'token budget' ) || str_contains( $message, 'incomplete' ) || str_contains( $message, 'truncated' ) ) {
			return PressArk_AI_Connector::FAILURE_TRUNCATION;
		}

		return PressArk_AI_Connector::FAILURE_PROVIDER_ERROR;
	}

	private function classify_throwable_failure( \Throwable $e ): string {
		$message = strtolower( $e->getMessage() );

		if ( str_contains( $message, 'already applied' ) || str_contains( $message, 'duplicate' ) || str_contains( $message, 'cannot safely retry' ) ) {
			return PressArk_AI_Connector::FAILURE_SIDE_EFFECT_RISK;
		}

		if ( str_contains( $message, 'valid json' ) || str_contains( $message, 'schema' ) || str_contains( $message, 'validation' ) ) {
			return PressArk_AI_Connector::FAILURE_VALIDATION;
		}

		if ( str_contains( $message, 'could not find' ) || str_contains( $message, 'could not determine' ) || str_contains( $message, 'ambiguous' ) ) {
			return PressArk_AI_Connector::FAILURE_BAD_RETRIEVAL;
		}

		if ( str_contains( $message, 'max token' ) || str_contains( $message, 'truncat' ) || str_contains( $message, 'token budget' ) ) {
			return PressArk_AI_Connector::FAILURE_TRUNCATION;
		}

		if ( str_contains( $message, 'read_' ) || str_contains( $message, 'tool' ) || str_contains( $message, 'handler' ) ) {
			return PressArk_AI_Connector::FAILURE_TOOL_ERROR;
		}

		return PressArk_AI_Connector::FAILURE_PROVIDER_ERROR;
	}

	private function should_retry_failure( string $failure_class, array $task, array $payload, array $result = array() ): bool {
		if ( ! in_array( $failure_class, self::RETRYABLE_FAILURES, true ) ) {
			return false;
		}

		if ( ! $this->store->can_retry( $task ) ) {
			return false;
		}

		return $this->can_resume_from_payload( $payload, $result );
	}

	private function can_resume_from_payload( array $payload, array $result = array() ): bool {
		if ( array_key_exists( 'resume_safe', $result ) ) {
			return (bool) $result['resume_safe'];
		}

		$checkpoint = $result['checkpoint'] ?? ( $payload['checkpoint'] ?? array() );
		if ( ! is_array( $checkpoint ) || empty( $checkpoint ) ) {
			return true;
		}

		$stage = sanitize_key( (string) ( $checkpoint['workflow_stage'] ?? '' ) );
		if ( '' === $stage ) {
			return true;
		}

		return ! in_array( $stage, array( 'apply', 'verify', 'settled' ), true );
	}

	private function merge_retry_payload(
		array $payload,
		array $result = array(),
		string $failure_class = '',
		string $failure_message = ''
	): array {
		if ( ! empty( $result['checkpoint'] ) && is_array( $result['checkpoint'] ) && $this->can_resume_from_payload(
			array( 'checkpoint' => $result['checkpoint'] ),
			$result
		) ) {
			$payload['checkpoint'] = $result['checkpoint'];
		}

		if ( ! empty( $result['loaded_groups'] ) ) {
			$payload['loaded_groups'] = array_values( array_unique( array_filter( array_merge(
				(array) ( $payload['loaded_groups'] ?? array() ),
				array_map( 'sanitize_text_field', (array) $result['loaded_groups'] )
			) ) ) );
		}

		$payload['_last_failure'] = array(
			'class'   => sanitize_key( $failure_class ),
			'message' => sanitize_text_field( $failure_message ?: (string) ( $result['message'] ?? '' ) ),
			'at'      => gmdate( 'c' ),
		);

		return $payload;
	}

	private function retry_backoff( int $attempt, string $failure_class ): int {
		$base = PressArk_AI_Connector::FAILURE_PROVIDER_ERROR === $failure_class ? 20 : 30;
		return min( 300, $base * (int) pow( 2, $attempt ) );
	}

	private function format_failure_reason( string $failure_class, string $message ): string {
		$message = trim( $message );
		if ( '' === $failure_class ) {
			return $message;
		}

		return sprintf( '[%s] %s', $failure_class, $message );
	}

	/**
	 * Get completed task result.
	 */
	public function get_result( string $task_id ): ?array {
		$task = $this->store->get( $task_id );

		if ( ! $task ) {
			return null;
		}

		if ( $task['status'] === 'complete' ) {
			return $task['result'];
		}

		return array( 'status' => $task['status'] );
	}

	/**
	 * Get and deliver all pending notifications for a user.
	 * Called by the task poll endpoint. Marks tasks as 'delivered'.
	 */
	public function pop_notifications( int $user_id ): array {
		$tasks = $this->store->get_pending_results( $user_id );

		if ( empty( $tasks ) ) {
			return array();
		}

		$results = array();
		foreach ( $tasks as $task ) {
			$results[] = array(
				'task_id' => $task['task_id'],
				'result'  => $task['result'],
			);
			$this->store->deliver( $task['task_id'] );
		}

		return $results;
	}

	/**
	 * Get the name of the active queue backend (for diagnostics).
	 *
	 * @since 2.5.0
	 */
	public function get_backend_name(): string {
		return $this->backend->get_name();
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Register async task processing hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'pressark_process_async_task', array( self::class, 'handle_async_task' ) );
		// Backward compat: process any lingering pre-2.5.0 tasks.
		add_action( 'pressark_process_task', array( self::class, 'handle_async_task' ) );

		// Inline rescue: re-schedule + kick overdue queued tasks on admin
		// page loads. Catches tasks whose cron events never fired due to
		// broken WP-Cron loopback (Docker, firewalls, low traffic).
		add_action( 'admin_init', array( self::class, 'maybe_rescue_overdue_tasks' ), 99 );
	}

	/**
	 * @since 4.2.0
	 */
	public static function handle_async_task( string $task_id ): void {
		( new self() )->process( $task_id );
	}

	/**
	 * Rescue overdue queued tasks on admin page loads.
	 *
	 * Tasks normally spend < 10 seconds in 'queued' (5-second scheduling
	 * delay + cron pick-up). If a task has been stuck for > 2 minutes,
	 * the cron event that should have processed it likely never fired.
	 *
	 * Re-schedules the task via the backend and kicks cron/AS to trigger
	 * processing. Throttled to once per minute via transient.
	 *
	 * @since 4.3.0
	 */
	public static function maybe_rescue_overdue_tasks(): void {
		$transient_key = 'pressark_task_rescue';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		$store   = new PressArk_Task_Store();
		$overdue = $store->find_oldest_overdue_queued();

		if ( ! $overdue ) {
			// No overdue tasks — check again in 60 seconds.
			set_transient( $transient_key, 1, 60 );
			return;
		}

		// Block rescue attempts for 2 minutes while we try to process.
		set_transient( $transient_key, 1, 120 );

		$task_id = $overdue['task_id'];
		$queue   = new self();

		// Re-schedule via the backend (in case the original event was lost).
		$queue->backend->schedule( $task_id, 0 );

		// Try to trigger the cron runner. This works on production hosts
		// where the loopback is functional; on Docker it will still fail
		// but the cron sidecar handles that case.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// If Action Scheduler is available, kick its runner directly.
		// This bypasses the loopback entirely (runs inline).
		PressArk_Cron_Manager::maybe_kick_as_runner();
	}
}
