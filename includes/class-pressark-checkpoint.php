<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured conversation checkpoint — captures operational state
 * so older messages can be dropped without losing context.
 *
 * v2.4.0: Stateless round-trip via REST (frontend sends it back each turn).
 * v3.3.0: Server-owned persistence — checkpoint is saved to wp_pressark_chats
 *         and loaded server-side. Frontend copy is a convenience mirror only.
 *
 * @package PressArk
 * @since   2.4.0
 * @since   3.3.0 Server-side persistence via save() / load().
 */
class PressArk_Checkpoint {

	private const BUNDLE_TRANSIENT_PREFIX = 'pressark_bundle_';
	private const MAX_BUNDLES             = 20;
	private const BUNDLE_TTL              = 86400;

	private string $goal        = '';
	private array  $entities    = array(); // [ ['id' => int, 'title' => string, 'type' => string], ... ]
	private array  $facts       = array(); // [ ['key' => string, 'value' => string], ... ]
	private array  $pending     = array(); // [ ['action' => string, 'target' => string, 'detail' => string], ... ]
	private array  $constraints = array(); // [ string, ... ]
	private array  $outcomes    = array(); // [ ['action' => string, 'result' => string], ... ]
	private array  $retrieval   = array(); // [ 'kind' => string, 'query' => string, 'count' => int, 'source_ids' => [], 'source_titles' => [] ]
	private array  $execution   = array(); // Durable task ledger for continuation safety.
	private int    $turn        = 0;
	private string $updated_at  = '';      // v3.3.0: ISO timestamp of last mutation.

	// v3.7.0: Extended typed state for memory hardening.
	private array  $selected_target    = array(); // [ 'id' => int, 'title' => string, 'type' => string ]
	private string $workflow_stage     = '';       // discover|gather|plan|preview|apply|verify|settled
	private array  $approvals          = array(); // [ ['action' => string, 'approved_at' => string], ... ]
	private array  $blockers           = array(); // [ string, ... ]
	private array  $context_capsule    = array(); // durable compressed state for long-running continuations
	private array  $loaded_tool_groups = array(); // [ 'seo', 'content', ... ]
	private array  $bundle_ids         = array(); // [ 'rb_abc123', ... ] deterministic read-bundle hashes

	// ── Factory / Serialization ─────────────────────────────────────

	/**
	 * Create from array (deserialization from frontend round-trip).
	 */
	public static function from_array( array $data ): self {
		$cp              = new self();
		$cp->goal        = sanitize_text_field( $data['goal'] ?? '' );
		$cp->entities    = self::sanitize_entities( $data['entities'] ?? array() );
		$cp->facts       = self::sanitize_key_value_pairs( $data['facts'] ?? array() );
		$cp->pending     = self::sanitize_pending( $data['pending'] ?? array() );
		$cp->constraints = array_map( 'sanitize_text_field', array_slice( $data['constraints'] ?? array(), 0, 20 ) );
		$cp->outcomes    = self::sanitize_outcomes( $data['outcomes'] ?? array() );

		$retrieval       = self::sanitize_retrieval( $data['retrieval'] ?? array() );
		$execution       = PressArk_Execution_Ledger::sanitize( $data['execution'] ?? array() );

		$cp->retrieval  = self::retrieval_is_empty( $retrieval ) ? array() : $retrieval;
		$cp->execution  = PressArk_Execution_Ledger::is_empty( $execution ) ? array() : $execution;
		$cp->turn       = absint( $data['turn'] ?? 0 );
		$cp->updated_at = sanitize_text_field( $data['updated_at'] ?? '' );

		// v3.7.0: Extended state.
		$cp->selected_target    = self::sanitize_selected_target( $data['selected_target'] ?? array() );
		$cp->workflow_stage     = self::sanitize_stage( $data['workflow_stage'] ?? '' );
		$cp->approvals          = self::sanitize_approvals( $data['approvals'] ?? array() );
		$cp->blockers           = array_map( 'sanitize_text_field', array_slice( $data['blockers'] ?? array(), 0, 10 ) );
		$cp->context_capsule    = self::sanitize_context_capsule( $data['context_capsule'] ?? array() );
		$cp->loaded_tool_groups = array_map( 'sanitize_text_field', array_slice( $data['loaded_tool_groups'] ?? array(), 0, 15 ) );
		$cp->bundle_ids         = array_map( 'sanitize_text_field', array_slice( $data['bundle_ids'] ?? array(), 0, 20 ) );
		return $cp;
	}

	/**
	 * Export to array (serialization for REST response / frontend storage).
	 */
	public function to_array(): array {
		return array(
			'goal'               => $this->goal,
			'entities'           => $this->entities,
			'facts'              => $this->facts,
			'pending'            => $this->pending,
			'constraints'        => $this->constraints,
			'outcomes'           => $this->outcomes,
			'retrieval'          => $this->retrieval,
			'execution'          => $this->execution,
			'turn'               => $this->turn,
			'updated_at'         => $this->updated_at,
			'selected_target'    => $this->selected_target,
			'workflow_stage'     => $this->workflow_stage,
			'approvals'          => $this->approvals,
			'blockers'           => $this->blockers,
			'context_capsule'    => $this->context_capsule,
			'loaded_tool_groups' => $this->loaded_tool_groups,
			'bundle_ids'         => $this->bundle_ids,
		);
	}

	/**
	 * Render as a compact text header for injection into message history.
	 * Designed for minimal token footprint (~100-200 tokens).
	 */
	public function to_context_header(): string {
		$parts = array();

		$data_lines = array();

		if ( $this->goal ) {
			$data_lines[] = "GOAL: {$this->goal}";
		}

		if ( $this->entities ) {
			$entity_strs = array_map( function ( $e ) {
				return sprintf( '%s #%d (%s)', $e['title'] ?? '', $e['id'] ?? 0, $e['type'] ?? '' );
			}, $this->entities );
			$data_lines[] = 'ENTITIES: ' . implode( ', ', $entity_strs );
		}

		if ( $this->facts ) {
			$fact_strs = array_map( function ( $f ) {
				return "{$f['key']}: {$f['value']}";
			}, $this->facts );
			$data_lines[] = 'FACTS: ' . implode( '; ', $fact_strs );
		}

		if ( $this->pending ) {
			$pending_strs = array_map( function ( $p ) {
				return "{$p['action']} on {$p['target']}";
			}, $this->pending );
			$data_lines[] = 'PENDING: ' . implode( '; ', $pending_strs );
		}

		if ( $this->constraints ) {
			$data_lines[] = 'CONSTRAINTS: ' . implode( '; ', $this->constraints );
		}

		if ( $this->outcomes ) {
			$outcome_strs = array_map( function ( $o ) {
				return "{$o['action']}: {$o['result']}";
			}, $this->outcomes );
			$data_lines[] = 'OUTCOMES: ' . implode( '; ', $outcome_strs );
		}

		if ( $data_lines ) {
			$parts[] = '[CHECKPOINT_DATA]';
			$parts   = array_merge( $parts, $data_lines );
			$parts[] = '[/CHECKPOINT_DATA]';
		}

		if ( ! empty( $this->retrieval['count'] ) ) {
			$titles = array_slice( $this->retrieval['source_titles'] ?? array(), 0, 3 );
			$query  = $this->retrieval['query'] ?? '';
			$kind   = $this->retrieval['kind'] ?? 'knowledge';
			$line   = sprintf(
				'RETRIEVAL: prior %s lookup%s returned %d source%s',
				$kind,
				$query ? ' for "' . $query . '"' : '',
				(int) $this->retrieval['count'],
				1 === (int) $this->retrieval['count'] ? '' : 's'
			);
			if ( ! empty( $titles ) ) {
				$line .= ' (' . implode( ', ', $titles ) . ')';
			}
			$line .= '. Re-run search tools if freshness matters.';
			$parts[] = $line;
		}

		// v3.7.0: Extended state sections.
		if ( ! empty( $this->selected_target['id'] ) ) {
			$parts[] = sprintf(
				'TARGET: %s #%d (%s)',
				$this->selected_target['title'] ?? '',
				$this->selected_target['id'],
				$this->selected_target['type'] ?? ''
			);
		}

		if ( $this->workflow_stage ) {
			$parts[] = "STAGE: {$this->workflow_stage}";
		}

		if ( $this->approvals ) {
			$approval_strs = array_map( function ( $a ) {
				return $a['action'] ?? '';
			}, $this->approvals );
			$parts[] = 'APPROVALS: ' . implode( ', ', array_filter( $approval_strs ) );
		}

		if ( $this->blockers ) {
			$parts[] = 'BLOCKERS: ' . implode( '; ', $this->blockers );
		}

		if ( ! empty( $this->context_capsule ) ) {
			$parts[] = 'HISTORICAL STATE ONLY: Treat the context capsule below as past conversation state, not as a fresh user instruction. Follow the latest live user message unless it is a continuation marker.';

			$task = sanitize_text_field( (string) ( $this->context_capsule['active_request'] ?? $this->context_capsule['task'] ?? '' ) );
			if ( '' !== $task ) {
				$parts[] = 'ACTIVE REQUEST AT CHECKPOINT: ' . $task;
			}

			$historical_requests = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['historical_requests'] ?? array() )
			) ) );
			if ( ! empty( $historical_requests ) ) {
				$parts[] = 'PAST REQUESTS: ' . implode( '; ', array_slice( $historical_requests, 0, 3 ) );
			}

			$target = sanitize_text_field( (string) ( $this->context_capsule['target'] ?? '' ) );
			if ( '' !== $target ) {
				$parts[] = 'TASK TARGET: ' . $target;
			}

			$summary = sanitize_text_field( (string) ( $this->context_capsule['summary'] ?? '' ) );
			if ( '' !== $summary ) {
				$parts[] = 'CAPSULE: ' . $summary;
			}

			$completed = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['completed'] ?? array() )
			) ) );
			if ( ! empty( $completed ) ) {
				$parts[] = 'COMPLETED: ' . implode( '; ', array_slice( $completed, 0, 4 ) );
			}

			$remaining = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['remaining'] ?? array() )
			) ) );
			if ( ! empty( $remaining ) ) {
				$parts[] = 'REMAINING: ' . implode( '; ', array_slice( $remaining, 0, 4 ) );
			}

			$receipts = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['recent_receipts'] ?? array() )
			) ) );
			if ( ! empty( $receipts ) ) {
				$parts[] = 'RECENT RECEIPTS: ' . implode( '; ', array_slice( $receipts, 0, 4 ) );
			}

			$loaded_groups = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['loaded_groups'] ?? array() )
			) ) );
			if ( ! empty( $loaded_groups ) ) {
				$parts[] = 'LOADED TOOL GROUPS (DO NOT RE-LOAD): ' . implode( ', ', array_slice( $loaded_groups, 0, 8 ) );
			}

			$ai_decisions = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['ai_decisions'] ?? array() )
			) ) );
			if ( ! empty( $ai_decisions ) ) {
				$parts[] = 'AI DECISIONS MADE (DO NOT REDO): ' . implode( '; ', array_slice( $ai_decisions, 0, 5 ) );
			}

			$created_post_ids = array_values( array_filter( array_map(
				'absint',
				(array) ( $this->context_capsule['created_post_ids'] ?? array() )
			) ) );
			if ( ! empty( $created_post_ids ) ) {
				$parts[] = 'POSTS CREATED (DO NOT RECREATE): ' . implode( ', ', array_slice( $created_post_ids, 0, 5 ) );
			}

			$preserved = array_values( array_filter( array_map(
				'sanitize_text_field',
				(array) ( $this->context_capsule['preserved_details'] ?? array() )
			) ) );
			if ( ! empty( $preserved ) ) {
				$parts[] = 'KEEP EXACT: ' . implode( '; ', array_slice( $preserved, 0, 4 ) );
			}
		}

		if ( $this->loaded_tool_groups ) {
			$parts[] = 'TOOLS: ' . implode( ', ', $this->loaded_tool_groups );
		}

		if ( $this->bundle_ids ) {
			$parts[] = sprintf( 'BUNDLES: %d prior read bundle(s) stored', count( $this->bundle_ids ) );
		}

		$execution_lines = PressArk_Execution_Ledger::build_context_lines( $this->execution );
		foreach ( $execution_lines as $line ) {
			$parts[] = $line;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		$header = "[Conversation State (turn {$this->turn})]\n";

		// v3.3.0: Epistemic hedging — flag stale checkpoints so the AI
		// doesn't treat old state as current truth.
		if ( $this->is_stale() ) {
			$age = $this->age_seconds();
			$age_human = $age > 7200 ? round( $age / 3600 ) . 'h' : round( $age / 60 ) . 'm';
			$header .= "CAUTION: This state is {$age_human} old. Entity titles, statuses, and facts may have changed. Verify before acting.\n";
		}

		return $header . implode( "\n", $parts );
	}

	/**
	 * Whether this checkpoint has any meaningful data.
	 */
	public function is_empty(): bool {
		return empty( $this->goal )
			&& empty( $this->entities )
			&& empty( $this->facts )
			&& empty( $this->pending )
			&& empty( $this->constraints )
			&& empty( $this->outcomes )
			&& self::retrieval_is_empty( $this->retrieval )
			&& PressArk_Execution_Ledger::is_empty( $this->execution )
			&& empty( $this->selected_target )
			&& empty( $this->workflow_stage )
			&& empty( $this->approvals )
			&& empty( $this->blockers )
			&& empty( $this->context_capsule )
			&& empty( $this->loaded_tool_groups )
			&& empty( $this->bundle_ids );
	}

	// ── Mutation Methods (called by agent after tool results) ────────

	public function set_goal( string $goal ): void {
		$this->goal = sanitize_text_field( $goal );
	}

	public function add_entity( int $id, string $title, string $type ): void {
		// Deduplicate by ID.
		foreach ( $this->entities as $e ) {
			if ( $e['id'] === $id ) {
				return;
			}
		}
		$this->entities[] = array(
			'id'    => $id,
			'title' => sanitize_text_field( $title ),
			'type'  => sanitize_text_field( $type ),
		);
	}

	public function add_fact( string $key, string $value ): void {
		// Overwrite existing key.
		foreach ( $this->facts as &$f ) {
			if ( $f['key'] === $key ) {
				$f['value'] = sanitize_text_field( $value );
				return;
			}
		}
		$this->facts[] = array(
			'key'   => sanitize_text_field( $key ),
			'value' => sanitize_text_field( $value ),
		);
	}

	public function add_pending( string $action, string $target, string $detail = '' ): void {
		$this->pending[] = array(
			'action' => sanitize_text_field( $action ),
			'target' => sanitize_text_field( $target ),
			'detail' => sanitize_text_field( $detail ),
		);
	}

	public function clear_pending(): void {
		$this->pending = array();
	}

	public function add_constraint( string $constraint ): void {
		$this->constraints[] = sanitize_text_field( $constraint );
	}

	public function add_outcome( string $action, string $result ): void {
		$this->outcomes[] = array(
			'action' => sanitize_text_field( $action ),
			'result' => sanitize_text_field( $result ),
		);
	}

	public function set_retrieval( array $retrieval ): void {
		$retrieval       = self::sanitize_retrieval( $retrieval );
		$this->retrieval = self::retrieval_is_empty( $retrieval ) ? array() : $retrieval;
	}

	public function get_retrieval(): array {
		return $this->retrieval;
	}

	public function set_turn( int $turn ): void {
		$this->turn = $turn;
	}

	public function get_turn(): int {
		return $this->turn;
	}

	/**
	 * Sync the execution ledger with the active request.
	 */
	public function sync_execution_goal( string $message ): void {
		$this->execution = PressArk_Execution_Ledger::bootstrap( $this->execution, $message );
		$normalized = trim( preg_replace( '/^\[(?:Continue|Confirmed)\]\s*/i', '', $message ) );
		$normalized = preg_replace( '/Please continue with the remaining steps from my original request\.?$/i', '', $normalized );
		$normalized = sanitize_text_field( trim( (string) $normalized ) );
		if ( '' !== $normalized ) {
			$this->set_goal( mb_substr( $normalized, 0, 200 ) );
		}
	}

	/**
	 * Record a meaningful read in the execution ledger.
	 */
	public function record_execution_read( string $tool_name, array $args, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_read( $this->execution, $tool_name, $args, $result );
	}

	/**
	 * Record a completed write in the execution ledger.
	 */
	public function record_execution_write( string $tool_name, array $args, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_write( $this->execution, $tool_name, $args, $result );
	}

	/**
	 * Record all writes from a kept preview session.
	 */
	public function record_execution_preview( array $tool_calls, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_preview_result( $this->execution, $tool_calls, $result );
	}

	/**
	 * Expose the execution ledger to callers that build continuation payloads.
	 */
	public function get_execution(): array {
		return $this->execution;
	}

	// ── v3.7.0: Extended State Mutators ──────────────────────────────

	public function set_selected_target( array $target ): void {
		$this->selected_target = self::sanitize_selected_target( $target );
	}

	public function get_selected_target(): array {
		return $this->selected_target;
	}

	public function set_workflow_stage( string $stage ): void {
		$this->workflow_stage = self::sanitize_stage( $stage );
	}

	public function get_workflow_stage(): string {
		return $this->workflow_stage;
	}

	public function add_approval( string $action ): void {
		$action = sanitize_text_field( $action );
		if ( '' === $action || count( $this->approvals ) >= 10 ) {
			return;
		}
		foreach ( $this->approvals as $approval ) {
			if ( ( $approval['action'] ?? '' ) === $action ) {
				return;
			}
		}
		$this->approvals[] = array(
			'action'      => $action,
			'approved_at' => gmdate( 'c' ),
		);
	}

	public function merge_approvals( array $approvals ): void {
		foreach ( $approvals as $approval ) {
			if ( ! is_array( $approval ) ) {
				continue;
			}
			$action = sanitize_text_field( $approval['action'] ?? '' );
			if ( '' === $action ) {
				continue;
			}
			$exists = false;
			foreach ( $this->approvals as $existing ) {
				if ( ( $existing['action'] ?? '' ) === $action ) {
					$exists = true;
					break;
				}
			}
			if ( $exists || count( $this->approvals ) >= 10 ) {
				continue;
			}
			$this->approvals[] = array(
				'action'      => $action,
				'approved_at' => sanitize_text_field( $approval['approved_at'] ?? gmdate( 'c' ) ),
			);
		}
	}

	public function get_approvals(): array {
		return $this->approvals;
	}

	public function add_blocker( string $blocker ): void {
		$blocker = sanitize_text_field( $blocker );
		if ( '' === $blocker || count( $this->blockers ) >= 10 ) {
			return;
		}
		if ( in_array( $blocker, $this->blockers, true ) ) {
			return;
		}
		$this->blockers[] = $blocker;
	}

	public function merge_blockers( array $blockers ): void {
		foreach ( $blockers as $blocker ) {
			$this->add_blocker( (string) $blocker );
		}
	}

	public function clear_blockers(): void {
		$this->blockers = array();
	}

	public function get_blockers(): array {
		return $this->blockers;
	}

	public function set_context_capsule( array $capsule ): void {
		$this->context_capsule = self::sanitize_context_capsule( $capsule );
	}

	public function get_context_capsule(): array {
		return $this->context_capsule;
	}

	public function clear_context_capsule(): void {
		$this->context_capsule = array();
	}

	public function set_loaded_tool_groups( array $groups ): void {
		$clean = array();
		foreach ( array_slice( $groups, 0, 15 ) as $group ) {
			$group = sanitize_text_field( (string) $group );
			if ( '' !== $group && ! in_array( $group, $clean, true ) ) {
				$clean[] = $group;
			}
		}
		$this->loaded_tool_groups = $clean;
	}

	public function get_loaded_tool_groups(): array {
		return $this->loaded_tool_groups;
	}

	public function add_bundle_id( string $bundle_id ): void {
		$bundle_id = sanitize_text_field( $bundle_id );
		if ( in_array( $bundle_id, $this->bundle_ids, true ) ) {
			return;
		}
		if ( count( $this->bundle_ids ) >= self::MAX_BUNDLES ) {
			$evicted = array_shift( $this->bundle_ids ); // Evict oldest.
			if ( $evicted ) {
				self::delete_bundle_payload( $evicted );
			}
		}
		$this->bundle_ids[] = $bundle_id;
	}

	public function merge_bundle_ids( array $bundle_ids ): void {
		foreach ( $bundle_ids as $bundle_id ) {
			$this->add_bundle_id( (string) $bundle_id );
		}
	}

	public function get_bundle_ids(): array {
		return $this->bundle_ids;
	}

	public function has_bundle( string $bundle_id ): bool {
		return in_array( $bundle_id, $this->bundle_ids, true );
	}

	public function remember_bundle( string $tool_name, array $args, array $result ): string {
		$bundle_id = self::compute_bundle_id( $tool_name, $args );
		self::store_bundle_payload( $bundle_id, $tool_name, $args, $result );
		$this->add_bundle_id( $bundle_id );
		return $bundle_id;
	}

	public static function store_bundle_payload( string $bundle_id, string $tool_name, array $args, array $result ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$post_id       = (int) ( $args['post_id'] ?? $result['data']['id'] ?? 0 );
		$post_modified = '';
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_modified = (string) ( $post->post_modified_gmt ?: $post->post_modified );
			}
		}

		$payload = array(
			'tool_name'     => sanitize_key( $tool_name ),
			'args'          => self::sanitize_bundle_args( $args ),
			'result'        => self::sanitize_bundle_result( $result ),
			'post_id'       => $post_id,
			'post_modified' => sanitize_text_field( $post_modified ),
			'stored_at'     => gmdate( 'c' ),
		);

		set_transient( self::bundle_transient_key( $bundle_id ), $payload, self::BUNDLE_TTL );
	}

	public static function get_bundle_payload( string $bundle_id ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$payload = get_transient( self::bundle_transient_key( $bundle_id ) );
		return is_array( $payload ) ? $payload : null;
	}

	public static function delete_bundle_payload( string $bundle_id ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::bundle_transient_key( $bundle_id ) );
		}
	}

	public function absorb_workflow_state( array $workflow_state, array $fallback = array() ): void {
		if ( ! empty( $workflow_state['workflow_stage'] ) ) {
			$this->set_workflow_stage( (string) $workflow_state['workflow_stage'] );
		}

		$target = array();
		if ( ! empty( $workflow_state['selected_target'] ) && is_array( $workflow_state['selected_target'] ) ) {
			$target = $workflow_state['selected_target'];
		} elseif ( ! empty( $workflow_state['target'] ) && is_array( $workflow_state['target'] ) ) {
			$target = self::summarize_workflow_target( $workflow_state['target'] );
		}
		if ( ! empty( $target ) ) {
			$this->set_selected_target( $target );
		}

		if ( ! empty( $workflow_state['loaded_tool_groups'] ) && is_array( $workflow_state['loaded_tool_groups'] ) ) {
			$this->set_loaded_tool_groups( $workflow_state['loaded_tool_groups'] );
		} elseif ( ! empty( $fallback['tool_groups'] ) && is_array( $fallback['tool_groups'] ) ) {
			$this->set_loaded_tool_groups( $fallback['tool_groups'] );
		}

		if ( ! empty( $workflow_state['approvals'] ) && is_array( $workflow_state['approvals'] ) ) {
			$this->merge_approvals( $workflow_state['approvals'] );
		}

		if ( ! empty( $workflow_state['blockers'] ) && is_array( $workflow_state['blockers'] ) ) {
			$this->merge_blockers( $workflow_state['blockers'] );
		}

		if ( ! empty( $workflow_state['retrieval_bundle_ids'] ) && is_array( $workflow_state['retrieval_bundle_ids'] ) ) {
			$this->merge_bundle_ids( $workflow_state['retrieval_bundle_ids'] );
		} elseif ( ! empty( $workflow_state['bundle_ids'] ) && is_array( $workflow_state['bundle_ids'] ) ) {
			$this->merge_bundle_ids( $workflow_state['bundle_ids'] );
		}
	}

	/**
	 * Generate a deterministic bundle ID from tool name and arguments.
	 */
	public static function compute_bundle_id( string $tool_name, array $args ): string {
		return 'rb_' . md5( $tool_name . wp_json_encode( $args ) );
	}

	// ── Server-side Persistence (v3.3.0) ────────────────────────────

	/**
	 * Save checkpoint server-side for a chat session.
	 *
	 * Uses wp_pressark_chats.checkpoint column (added by v3.3.0 migration).
	 * This is the source of truth — the frontend copy is a convenience mirror.
	 *
	 * @param int $chat_id  Chat session ID (0 = ephemeral, skip persist).
	 * @param int $user_id  User ID for ownership check.
	 */
	public function save( int $chat_id, int $user_id = 0 ): bool {
		if ( $chat_id <= 0 ) {
			return false;
		}

		$this->updated_at = gmdate( 'c' );

		global $wpdb;
		$table = $wpdb->prefix . 'pressark_chats';

		$where = array( 'id' => $chat_id );
		if ( $user_id > 0 ) {
			$where['user_id'] = $user_id;
		}

		$rows = $wpdb->update(
			$table,
			array(
				'checkpoint' => wp_json_encode( $this->to_array() ),
				'updated_at' => current_time( 'mysql' ),
			),
			$where,
			array( '%s', '%s' ),
			$user_id > 0 ? array( '%d', '%d' ) : array( '%d' )
		);

		return $rows >= 1;
	}

	/**
	 * Load checkpoint from server for a chat session.
	 *
	 * @param int $chat_id Chat session ID.
	 * @param int $user_id User ID for ownership check.
	 * @return self|null Null if no persisted checkpoint exists.
	 */
	public static function load( int $chat_id, int $user_id = 0 ): ?self {
		if ( $chat_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pressark_chats';

		$sql    = "SELECT checkpoint FROM {$table} WHERE id = %d";
		$params = array( $chat_id );

		if ( $user_id > 0 ) {
			$sql     .= ' AND user_id = %d';
			$params[] = $user_id;
		}

		$json = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );

		if ( empty( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::from_array( $data );
	}

	/**
	 * Merge a client-provided checkpoint with the server-owned one.
	 *
	 * Server wins for structural data (entities, facts, outcomes).
	 * Client wins for ephemeral turn-level data (pending, goal updates
	 * from the most recent turn only).
	 *
	 * @param self $server Server-loaded checkpoint.
	 * @param self $client Client-provided checkpoint.
	 * @return self Merged checkpoint.
	 */
	public static function merge( self $server, self $client ): self {
		// If server is empty, trust client entirely (first persist).
		if ( $server->is_empty() ) {
			return $client;
		}
		// If client is empty, trust server entirely.
		if ( $client->is_empty() ) {
			return $server;
		}

		$merged = clone $server;

		// Client may have advanced the turn — accept if ahead.
		if ( $client->turn > $server->turn ) {
			$merged->turn = $client->turn;
			$merged->goal = $client->goal ?: $server->goal;
		}

		// Merge entities: union by ID, server wins on conflict.
		$server_ids = array_column( $server->entities, 'id' );
		foreach ( $client->entities as $e ) {
			if ( ! in_array( $e['id'], $server_ids, true ) ) {
				$merged->entities[] = $e;
			}
		}

		// Merge facts: server wins on key conflict.
		$server_keys = array_column( $server->facts, 'key' );
		foreach ( $client->facts as $f ) {
			if ( ! in_array( $f['key'], $server_keys, true ) ) {
				$merged->facts[] = $f;
			}
		}

		// Pending: always use client (reflects the user's latest turn).
		$merged->pending = $client->pending;
		if ( empty( $merged->retrieval ) && ! empty( $client->retrieval ) ) {
			$merged->retrieval = $client->retrieval;
		}
		$merged->execution = PressArk_Execution_Ledger::merge( $server->execution, $client->execution );

		// v3.7.0: Extended state — server wins on structural, union on blockers.
		if ( ! empty( $client->selected_target ) && empty( $server->selected_target ) ) {
			$merged->selected_target = $client->selected_target;
		}
		if ( $client->workflow_stage && ! $server->workflow_stage ) {
			$merged->workflow_stage = $client->workflow_stage;
		}
		$merged->merge_approvals( $client->approvals );
		// Blockers: union.
		$merged->merge_blockers( $client->blockers );
		// Context capsule: prefer the newer client capsule when present.
		if ( ! empty( $client->context_capsule ) ) {
			$merged->context_capsule = $client->context_capsule;
		}
		// Tool groups: server wins (reflects actual loaded state).
		if ( empty( $server->loaded_tool_groups ) && ! empty( $client->loaded_tool_groups ) ) {
			$merged->loaded_tool_groups = $client->loaded_tool_groups;
		}
		// Bundle IDs: union (both sides may have recorded reads).
		$merged->merge_bundle_ids( $client->bundle_ids );

		$merged->updated_at = gmdate( 'c' );

		return $merged;
	}

	/**
	 * Get age of checkpoint in seconds.
	 * Returns PHP_INT_MAX if no updated_at is set.
	 *
	 * @since 3.3.0
	 */
	public function age_seconds(): int {
		if ( empty( $this->updated_at ) ) {
			return PHP_INT_MAX;
		}
		$ts = strtotime( $this->updated_at );
		if ( false === $ts ) {
			return PHP_INT_MAX;
		}
		return max( 0, time() - $ts );
	}

	/**
	 * Whether this checkpoint is stale (older than threshold).
	 *
	 * @param int $threshold_seconds Max acceptable age (default: 1 hour).
	 * @since 3.3.0
	 */
	public function is_stale( int $threshold_seconds = 3600 ): bool {
		return $this->age_seconds() > $threshold_seconds;
	}

	/**
	 * Touch updated_at to current time.
	 *
	 * @since 3.3.0
	 */
	public function touch(): void {
		$this->updated_at = gmdate( 'c' );
	}

	// ── Sanitization Helpers ────────────────────────────────────────

	private static function sanitize_entities( array $raw ): array {
		$clean = array();
		foreach ( array_slice( $raw, 0, 50 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'id'    => absint( $item['id'] ?? 0 ),
				'title' => sanitize_text_field( $item['title'] ?? '' ),
				'type'  => sanitize_text_field( $item['type'] ?? '' ),
			);
		}
		return $clean;
	}

	private static function sanitize_key_value_pairs( array $raw ): array {
		$clean = array();
		foreach ( array_slice( $raw, 0, 50 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'key'   => sanitize_text_field( $item['key'] ?? '' ),
				'value' => sanitize_text_field( $item['value'] ?? '' ),
			);
		}
		return $clean;
	}

	private static function sanitize_pending( array $raw ): array {
		$clean = array();
		foreach ( array_slice( $raw, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'action' => sanitize_text_field( $item['action'] ?? '' ),
				'target' => sanitize_text_field( $item['target'] ?? '' ),
				'detail' => sanitize_text_field( $item['detail'] ?? '' ),
			);
		}
		return $clean;
	}

	private static function sanitize_outcomes( array $raw ): array {
		$clean = array();
		foreach ( array_slice( $raw, 0, 30 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'action' => sanitize_text_field( $item['action'] ?? '' ),
				'result' => sanitize_text_field( $item['result'] ?? '' ),
			);
		}
		return $clean;
	}

	private static function sanitize_selected_target( $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
			return array();
		}
		return array(
			'id'    => absint( $raw['id'] ),
			'title' => sanitize_text_field( $raw['title'] ?? '' ),
			'type'  => sanitize_text_field( $raw['type'] ?? '' ),
		);
	}

	private static function sanitize_stage( string $raw ): string {
		$valid = array( 'discover', 'gather', 'plan', 'preview', 'apply', 'verify', 'settled', '' );
		$clean = sanitize_text_field( $raw );
		return in_array( $clean, $valid, true ) ? $clean : '';
	}

	private static function sanitize_approvals( array $raw ): array {
		$clean = array();
		foreach ( array_slice( $raw, 0, 10 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'action'      => sanitize_text_field( $item['action'] ?? '' ),
				'approved_at' => sanitize_text_field( $item['approved_at'] ?? '' ),
			);
		}
		return $clean;
	}

	private static function sanitize_context_capsule( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array(
			'task'                => sanitize_text_field( (string) ( $raw['task'] ?? '' ) ),
			'active_request'      => sanitize_text_field( (string) ( $raw['active_request'] ?? '' ) ),
			'historical_requests' => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['historical_requests'] ?? array() ), 0, 3 )
			) ) ),
			'target'              => sanitize_text_field( (string) ( $raw['target'] ?? '' ) ),
			'summary'             => sanitize_text_field( (string) ( $raw['summary'] ?? '' ) ),
			'completed'           => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['completed'] ?? array() ), 0, 6 )
			) ) ),
			'remaining'           => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['remaining'] ?? array() ), 0, 6 )
			) ) ),
			'recent_receipts'     => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['recent_receipts'] ?? array() ), 0, 6 )
			) ) ),
			'loaded_groups'       => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['loaded_groups'] ?? array() ), 0, 8 )
			) ) ),
			'ai_decisions'        => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['ai_decisions'] ?? array() ), 0, 5 )
			) ) ),
			'created_post_ids'    => array_values( array_filter( array_map(
				'absint',
				array_slice( (array) ( $raw['created_post_ids'] ?? array() ), 0, 5 )
			) ) ),
			'preserved_details' => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['preserved_details'] ?? array() ), 0, 8 )
			) ) ),
			'scope'               => array_values( array_filter( array_map(
				'sanitize_text_field',
				array_slice( (array) ( $raw['scope'] ?? array() ), 0, 6 )
			) ) ),
			'compression_model' => sanitize_text_field( (string) ( $raw['compression_model'] ?? '' ) ),
			'updated_at'          => sanitize_text_field( (string) ( $raw['updated_at'] ?? '' ) ),
		);

		return array_filter(
			$clean,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	private static function sanitize_bundle_args( array $args ): array {
		$clean = array();
		foreach ( $args as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_numeric( $value ) ? $value + 0 : sanitize_text_field( (string) $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_bundle_nested( $value );
			}
		}
		return $clean;
	}

	private static function sanitize_bundle_nested( array $value ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$clean[ $key ] = self::sanitize_bundle_nested( $item );
			} elseif ( is_scalar( $item ) || null === $item ) {
				$clean[ $key ] = is_numeric( $item ) ? $item + 0 : sanitize_text_field( (string) $item );
			}
		}
		return $clean;
	}

	private static function sanitize_bundle_result( array $result ): array {
		$clean = array(
			'success' => ! empty( $result['success'] ),
			'message' => sanitize_text_field( (string) ( $result['message'] ?? '' ) ),
		);
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			$clean['data'] = $result['data'];
		}
		if ( isset( $result['total'] ) ) {
			$clean['total'] = (int) $result['total'];
		}
		if ( isset( $result['shown'] ) ) {
			$clean['shown'] = (int) $result['shown'];
		}
		if ( isset( $result['has_more'] ) ) {
			$clean['has_more'] = (bool) $result['has_more'];
		}
		return $clean;
	}

	private static function bundle_transient_key( string $bundle_id ): string {
		return self::BUNDLE_TRANSIENT_PREFIX . sanitize_key( $bundle_id );
	}

	private static function summarize_workflow_target( array $target ): array {
		if ( ! empty( $target['post_ids'] ) && is_array( $target['post_ids'] ) ) {
			return array();
		}

		$id    = absint( $target['post_id'] ?? $target['id'] ?? 0 );
		$title = sanitize_text_field( $target['title'] ?? '' );
		$type  = sanitize_text_field( $target['type'] ?? '' );

		if ( $id > 0 && ( '' === $title || '' === $type ) ) {
			$post = get_post( $id );
			if ( $post ) {
				$title = $title ?: $post->post_title;
				$type  = $type ?: $post->post_type;
			}
		}

		if ( $id <= 0 ) {
			return array();
		}

		return array(
			'id'    => $id,
			'title' => $title,
			'type'  => $type ?: 'post',
		);
	}

	private static function sanitize_retrieval( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$source_ids = array();
		foreach ( array_slice( $raw['source_ids'] ?? array(), 0, 10 ) as $id ) {
			$source_ids[] = absint( $id );
		}
		$source_ids = array_values( array_filter( $source_ids ) );

		$source_titles = array();
		foreach ( array_slice( $raw['source_titles'] ?? array(), 0, 5 ) as $title ) {
			$title = sanitize_text_field( (string) $title );
			if ( '' !== $title ) {
				$source_titles[] = $title;
			}
		}

		$clean = array(
			'kind'          => sanitize_text_field( $raw['kind'] ?? '' ),
			'query'         => sanitize_text_field( $raw['query'] ?? '' ),
			'count'         => absint( $raw['count'] ?? count( $source_ids ) ),
			'source_ids'    => $source_ids,
			'source_titles' => $source_titles,
			'updated_at'    => sanitize_text_field( $raw['updated_at'] ?? '' ),
		);

		return array_filter(
			$clean,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	private static function retrieval_is_empty( array $retrieval ): bool {
		return empty( $retrieval['kind'] ?? '' )
			&& empty( $retrieval['query'] ?? '' )
			&& empty( absint( $retrieval['count'] ?? 0 ) )
			&& empty( $retrieval['source_ids'] ?? array() )
			&& empty( $retrieval['source_titles'] ?? array() )
			&& empty( $retrieval['updated_at'] ?? '' );
	}
}
