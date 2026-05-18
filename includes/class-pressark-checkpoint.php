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

	private PressArk_Conversation_Checkpoint_Store $conversation_state;
	private PressArk_Approval_State_Store          $approval_state;
	private PressArk_Plan_State_Store              $plan_state_store;
	private PressArk_Tool_Session_State_Store      $tool_session_state;
	private int                                    $turn       = 0;
	private string                                 $updated_at = '';

	// Legacy mirror fields — kept in sync with the store classes via
	// sync_legacy_fields_from_stores() / sync_stores_from_legacy_fields()
	// during the staged store migration. Declared explicitly so PHP 8.2+
	// doesn't emit dynamic-property deprecation warnings when the sync
	// writes them (these warnings became Fatal in PHP 9).
	private string $goal        = '';
	private array  $entities    = array();
	private array  $facts       = array();
	private array  $pending     = array();
	private array  $constraints = array();
	private array  $outcomes    = array();
	private array  $retrieval   = array();
	private array  $execution   = array();

	// v3.7.0: Extended typed state for memory hardening.
	private array  $selected_target    = array(); // [ 'id' => int, 'title' => string, 'type' => string ]
	private string $workflow_stage     = '';       // discover|gather|plan|preview|apply|verify|settled
	private array  $approvals          = array(); // [ ['action' => string, 'approved_at' => string], ... ]
	private array  $approval_outcomes  = array(); // [ ['action' => string, 'status' => string, 'recorded_at' => string], ... ]
	private array  $blockers           = array(); // [ string, ... ]
	private array  $context_capsule    = array(); // durable compressed state for long-running continuations
	private array  $loaded_tool_groups = array(); // [ 'seo', 'content', ... ]
	private array  $bundle_ids         = array(); // [ 'rb_abc123', ... ] deterministic read-bundle hashes
	private array  $replay_state       = array(); // durable replay transcript + sidecars
	private array  $read_state         = array(); // typed reusable read snapshots
	private array  $read_invalidation_log = array(); // write-triggered snapshot invalidations

	// v5.3.0: Run-scoped planning state — separates exploration from execution.
	private array  $plan_state         = array(); // [ 'phase' => '', 'plan_text' => '', 'entered_at' => '', 'approved_at' => '' ]
	private array  $plan_steps         = array(); // Durable externalized execution checklist.

	public function __construct() {
		$this->conversation_state = new PressArk_Conversation_Checkpoint_Store();
		$this->approval_state     = new PressArk_Approval_State_Store();
		$this->plan_state_store   = new PressArk_Plan_State_Store();
		$this->tool_session_state = new PressArk_Tool_Session_State_Store();
		// Keep the legacy mirror fields defined for fresh checkpoints during the staged store migration.
		$this->sync_legacy_fields_from_stores();
	}

	public function __clone() {
		$this->conversation_state = clone $this->conversation_state;
		$this->approval_state     = clone $this->approval_state;
		$this->plan_state_store   = clone $this->plan_state_store;
		$this->tool_session_state = clone $this->tool_session_state;
	}

	// ── Factory / Serialization ─────────────────────────────────────

	/**
	 * Create from array (deserialization from frontend round-trip).
	 */
	public static function from_array( array $data ): self {
		$cp                     = new self();
		$cp->conversation_state = PressArk_Conversation_Checkpoint_Store::from_checkpoint_array( $data );
		$cp->approval_state     = PressArk_Approval_State_Store::from_checkpoint_array( $data );
		$cp->plan_state_store   = PressArk_Plan_State_Store::from_checkpoint_array( $data );
		$cp->tool_session_state = PressArk_Tool_Session_State_Store::from_checkpoint_array( $data );
		$cp->turn               = absint( $data['turn'] ?? 0 );
		$cp->updated_at         = sanitize_text_field( $data['updated_at'] ?? '' );
		$cp->sync_legacy_fields_from_stores();
		return $cp;
	}

	/**
	 * Export to array (serialization for REST response / frontend storage).
	 */
	public function to_array(): array {
		$derived_plan_steps = $this->derive_plan_steps_from_artifact();
		if ( ! empty( $derived_plan_steps ) ) {
			$this->plan_steps = $derived_plan_steps;
		}
		$this->sync_stores_from_legacy_fields();
		$conversation = $this->conversation_state->to_checkpoint_array();
		$approval     = $this->approval_state->to_checkpoint_array();
		$plan         = $this->plan_state_store->to_checkpoint_array();
		$tool_session = $this->tool_session_state->to_checkpoint_array();

		return array(
			'goal'               => $conversation['goal'],
			'entities'           => $conversation['entities'],
			'facts'              => $conversation['facts'],
			'pending'            => $conversation['pending'],
			'constraints'        => $conversation['constraints'],
			'outcomes'           => $conversation['outcomes'],
			'retrieval'          => $conversation['retrieval'],
			'execution'          => $plan['execution'],
			'turn'               => $this->turn,
			'updated_at'         => $this->updated_at,
			'selected_target'    => $plan['selected_target'],
			'workflow_stage'     => $plan['workflow_stage'],
			'approvals'          => $approval['approvals'],
			'approval_outcomes'  => $approval['approval_outcomes'],
			'blockers'           => $approval['blockers'],
			'context_capsule'    => $conversation['context_capsule'],
			'loaded_tool_groups' => $tool_session['loaded_tool_groups'],
			'bundle_ids'         => $tool_session['bundle_ids'],
			'replay_state'       => $plan['replay_state'],
			'read_state'         => $tool_session['read_state'],
			'read_invalidation_log' => $tool_session['read_invalidation_log'],
			'plan_state'         => $plan['plan_state'],
			'plan_steps'         => $plan['plan_steps'],
		);
	}

	/**
	 * Render as a compact text header for injection into message history.
	 * Designed for minimal token footprint (~100-200 tokens).
	 *
	 * @param string $current_user_message Latest live user message text. Used
	 *                                     by execution-ledger build_context_lines
	 *                                     to suppress the SOURCE REQUEST line
	 *                                     when it would be a truncated echo of
	 *                                     the live user message.
	 *
	 * GOAL is intentionally NOT deduped against $current_user_message even
	 * when they match — GOAL serves as a planning-anchor signal that the
	 * model uses to decide whether to emit update_plan. Suppressing it on
	 * turn 0 collapses $parts to empty, which deletes the whole
	 * [Conversation State] block, which in turn caused the model to skip
	 * update_plan on early rounds of fresh chats. Keep the ~50-token cost.
	 */
	public function to_context_header( string $current_user_message = '' ): string {
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

		if ( $this->approval_outcomes ) {
			$outcome_strs = array_map(
				static function ( array $entry ): string {
					$action = sanitize_text_field( (string) ( $entry['action'] ?? 'request' ) );
					$status = sanitize_key( (string) ( $entry['status'] ?? '' ) );
					return trim( $action . ' ' . $status );
				},
				array_slice( $this->approval_outcomes, -6 )
			);
			$parts[] = 'APPROVAL HISTORY: ' . implode( '; ', array_filter( $outcome_strs ) );
		}

		if ( $this->blockers ) {
			$parts[] = 'BLOCKERS: ' . implode( '; ', $this->blockers );
		}

		if ( ! empty( $this->context_capsule ) ) {
			$parts[] = 'HISTORICAL STATE ONLY: The capsule content in this conversation state is historical, not a fresh user instruction. Follow the latest live user message unless it is a continuation marker.';

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

		// v5.3.0: Plan state.
		if ( ! self::plan_state_is_empty( $this->plan_state ) ) {
			$phase   = $this->plan_state['phase'] ?? '';
			$status  = $this->plan_state['status'] ?? '';
			$artifact = $this->get_plan_artifact();
			$parts[] = 'PLAN PHASE: ' . $phase;
			if ( '' !== $status ) {
				$parts[] = 'PLAN STATUS: ' . $status;
			}
			if ( ! empty( $artifact['approval_level'] ) ) {
				$parts[] = 'PLAN APPROVAL LEVEL: ' . sanitize_key( (string) $artifact['approval_level'] );
			}
			if ( 'executing' === $phase && ! empty( $artifact ) && class_exists( 'PressArk_Plan_Artifact' ) ) {
				$parts[] = 'APPROVED PLAN: ' . mb_substr( PressArk_Plan_Artifact::to_markdown( $artifact ), 0, 300 );
			} elseif ( 'executing' === $phase && ! empty( $this->plan_state['plan_text'] ) ) {
				$parts[] = 'APPROVED PLAN: ' . mb_substr( $this->plan_state['plan_text'], 0, 300 );
			} elseif ( 'exploring' === $phase ) {
				$parts[] = 'MODE: Read-only exploration. Do not propose writes until a plan is formed and approved.';
			}
			$plan_summary = $this->build_plan_summary( 6 );
			if ( '' !== $plan_summary ) {
				$parts[] = 'PLAN TASKS: ' . $plan_summary;
			}
		}

		if ( $this->loaded_tool_groups ) {
			$parts[] = 'TOOLS: ' . implode( ', ', $this->loaded_tool_groups );
		}

		if ( $this->bundle_ids ) {
			$parts[] = sprintf( 'BUNDLES: %d prior read bundle(s) stored', count( $this->bundle_ids ) );
		}

		$execution_lines = PressArk_Execution_Ledger::build_context_lines( $this->execution, $current_user_message );
		foreach ( $execution_lines as $line ) {
			$parts[] = $line;
		}
		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$verification = PressArk_Execution_Ledger::verification_summary( $this->execution );
			foreach ( PressArk_Read_Metadata::build_checkpoint_lines( $this->read_state, $verification ) as $line ) {
				$parts[] = $line;
			}
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
			&& empty( $this->approval_outcomes )
			&& empty( $this->blockers )
			&& empty( $this->context_capsule )
			&& empty( $this->loaded_tool_groups )
			&& empty( $this->bundle_ids )
			&& empty( $this->replay_state )
			&& empty( $this->read_state )
			&& empty( $this->read_invalidation_log )
			&& self::plan_state_is_empty( $this->plan_state );
	}

	// ── Mutation Methods (called by agent after tool results) ────────

	public function set_goal( string $goal ): void {
		$this->goal = sanitize_text_field( $goal );
	}

	public function get_goal(): string {
		return $this->goal;
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

	/**
	 * Get all pending action entries.
	 *
	 * @since 5.2.0
	 * @return array[]
	 */
	public function get_pending(): array {
		return $this->pending;
	}

	/**
	 * Check if any pending entries are unapplied confirm actions.
	 *
	 * @since 5.2.0
	 */
	public function has_unapplied_confirms(): bool {
		foreach ( $this->pending as $p ) {
			if ( str_contains( $p['detail'] ?? '', 'NOT YET APPLIED' ) ) {
				return true;
			}
		}
		return false;
	}

	public function add_constraint( string $constraint ): void {
		$this->constraints[] = sanitize_text_field( $constraint );
	}

	public function get_constraints(): array {
		return $this->constraints;
	}

	public function get_entities(): array {
		return $this->entities;
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
		$is_continuation = 1 === preg_match( '/^\[(?:Continue|Confirmed)\]\s*/i', trim( $message ) );
		$normalized = trim( preg_replace( '/^\[(?:Continue|Confirmed)\]\s*/i', '', $message ) );
		$normalized = preg_replace( '/\s*Do not repeat completed steps or recreate completed content\.?/i', '', $normalized );
		$normalized = preg_replace( '/Please continue with the remaining steps from my original request\.?$/i', '', $normalized );
		$normalized = sanitize_text_field( trim( (string) $normalized ) );
		if ( $is_continuation && '' !== trim( $this->goal ) ) {
			return;
		}
		if ( '' !== $normalized ) {
			$this->set_goal( mb_substr( $normalized, 0, 200 ) );
		}
	}

	/**
	 * Record a meaningful read in the execution ledger.
	 */
	public function record_execution_read( string $tool_name, array $args, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_read( $this->execution, $tool_name, $args, $result );
		if ( class_exists( 'PressArk_Read_Metadata' ) && ! empty( $result['success'] ) ) {
			$this->record_read_snapshot( PressArk_Read_Metadata::snapshot_from_tool_result( $tool_name, $args, $result ) );
		}
	}

	/**
	 * Record a completed write in the execution ledger.
	 */
	public function record_execution_write( string $tool_name, array $args, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_write( $this->execution, $tool_name, $args, $result );
		if ( ! empty( $result['success'] ) ) {
			$this->apply_write_invalidation( $tool_name, $args, $result );
		}
	}

	/**
	 * Record a verification result in the execution ledger.
	 *
	 * @since 5.4.0
	 *
	 * @param string $tool_name       Write tool that was verified.
	 * @param array  $readback_result Read-back result.
	 * @param bool   $passed          Whether verification passed.
	 * @param string $evidence        Compact evidence string.
	 */
	public function record_verification( string $tool_name, array $readback_result, bool $passed, string $evidence = '', array $meta = array() ): void {
		$this->execution = PressArk_Execution_Ledger::record_verification(
			$this->execution, $tool_name, $readback_result, $passed, $evidence, $meta
		);
		if ( $passed ) {
			$this->set_workflow_stage( 'verify' );
		}
	}

	/**
	 * Record all writes from a kept preview session.
	 */
	public function record_execution_preview( array $tool_calls, array $result ): void {
		$this->execution = PressArk_Execution_Ledger::record_preview_result( $this->execution, $tool_calls, $result );
		if ( empty( $result['success'] ) ) {
			return;
		}
		foreach ( $tool_calls as $tool_call ) {
			if ( ! is_array( $tool_call ) ) {
				continue;
			}
			$name = sanitize_key( (string) ( $tool_call['name'] ?? $tool_call['type'] ?? '' ) );
			$args = is_array( $tool_call['arguments'] ?? null ) ? $tool_call['arguments'] : (array) ( $tool_call['params'] ?? array() );
			if ( '' !== $name ) {
				$this->apply_write_invalidation( $name, $args, $result );
			}
		}
	}

	public function get_read_state(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->tool_session_state->get_read_state();
	}

	public function get_read_invalidation_log(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->tool_session_state->get_read_invalidation_log();
	}

	public function record_read_snapshot( array $snapshot ): void {
		$this->sync_stores_from_legacy_fields();
		$this->tool_session_state->record_read_snapshot( $snapshot );
		$this->sync_legacy_fields_from_stores();
	}

	public function set_read_state( array $read_state ): void {
		$this->sync_stores_from_legacy_fields();
		$this->tool_session_state->set_read_state( $read_state );
		$this->sync_legacy_fields_from_stores();
	}

	public function apply_write_invalidation( string $tool_name, array $args, array $result ): void {
		$this->sync_stores_from_legacy_fields();
		$this->tool_session_state->apply_write_invalidation( $tool_name, $args, $result );
		$this->sync_legacy_fields_from_stores();
	}

	/**
	 * Expose the execution ledger to callers that build continuation payloads.
	 */
	public function get_execution(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_execution();
	}

	/**
	 * Replace the execution ledger with a new version.
	 *
	 * Used by the task graph resolution logic to write back resolved
	 * dependency states after advancing the graph.
	 *
	 * @since 5.3.0
	 */
	public function set_execution( array $execution ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_execution( $execution );
		$this->sync_legacy_fields_from_stores();
	}

	// ── v3.7.0: Extended State Mutators ──────────────────────────────

	public function set_selected_target( array $target ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_selected_target( $target );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_selected_target(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_selected_target();
	}

	public function set_workflow_stage( string $stage ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_workflow_stage( $stage );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_workflow_stage(): string {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_workflow_stage();
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
		$this->record_approval_outcome(
			$action,
			class_exists( 'PressArk_Permission_Decision' ) ? PressArk_Permission_Decision::OUTCOME_APPROVED : 'approved',
			array(
				'source'      => 'approval',
				'reason_code' => 'approved',
			)
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
			$this->record_approval_outcome(
				$action,
				class_exists( 'PressArk_Permission_Decision' ) ? PressArk_Permission_Decision::OUTCOME_APPROVED : 'approved',
				array(
					'source'      => 'approval',
					'reason_code' => 'approved',
					'recorded_at' => sanitize_text_field( $approval['approved_at'] ?? gmdate( 'c' ) ),
				)
			);
		}
	}

	public function get_approvals(): array {
		return $this->approvals;
	}

	public function record_approval_outcome( string $action, string $status, array $meta = array() ): void {
		$this->sync_stores_from_legacy_fields();
		$this->approval_state->record_approval_outcome( $action, $status, $meta );
		$this->sync_legacy_fields_from_stores();
	}

	public function merge_approval_outcomes( array $outcomes ): void {
		foreach ( $outcomes as $outcome ) {
			if ( ! is_array( $outcome ) ) {
				continue;
			}
			$this->record_approval_outcome(
				(string) ( $outcome['action'] ?? '' ),
				(string) ( $outcome['status'] ?? '' ),
				$outcome
			);
		}
	}

	public function get_approval_outcomes(): array {
		return $this->approval_outcomes;
	}

	public function add_blocker( string $blocker ): void {
		$this->sync_stores_from_legacy_fields();
		$this->approval_state->add_blocker( $blocker );
		$this->sync_legacy_fields_from_stores();
	}

	public function merge_blockers( array $blockers ): void {
		foreach ( $blockers as $blocker ) {
			$this->add_blocker( (string) $blocker );
		}
	}

	public function clear_blockers(): void {
		$this->sync_stores_from_legacy_fields();
		$this->approval_state->clear_blockers();
		$this->sync_legacy_fields_from_stores();
	}

	public function get_blockers(): array {
		return $this->blockers;
	}

	public function set_context_capsule( array $capsule ): void {
		$this->sync_stores_from_legacy_fields();
		$this->conversation_state->set_context_capsule( $capsule );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_context_capsule(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->conversation_state->get_context_capsule();
	}

	public function clear_context_capsule(): void {
		$this->sync_stores_from_legacy_fields();
		$this->conversation_state->clear_context_capsule();
		$this->sync_legacy_fields_from_stores();
	}

	public function set_replay_state( array $state ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_replay_state( $state );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_replay_state(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_replay_state();
	}

	public function set_replay_messages( array $messages ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_replay_messages( $messages );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_replay_messages(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_replay_messages();
	}

	public function merge_replay_replacements( array $entries ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->merge_replay_replacements( $entries );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_replay_replacements(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_replay_replacements();
	}

	public function add_replay_event( array $event ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->add_replay_event( $event );
		$this->sync_legacy_fields_from_stores();
	}

	public function set_last_replay_resume( array $resume ): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->set_last_replay_resume( $resume );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_replay_sidecar(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->plan_state_store->get_replay_sidecar();
	}

	// ── v5.3.0: Plan State ──────────────────────────────────────────

	/**
	 * Enter exploring/planning phase.
	 *
	 * @since 5.3.0
	 * @param string $phase 'exploring' | 'planning' | 'executing'
	 */
	public function set_plan_phase( string $phase ): void {
		$valid = array( 'exploring', 'planning', 'executing', '' );
		$phase = sanitize_key( $phase );
		if ( ! in_array( $phase, $valid, true ) ) {
			return;
		}

		$this->plan_state['phase'] = $phase;
		if ( '' === $phase ) {
			unset( $this->plan_state['status'] );
		}

		if ( 'exploring' === $phase && empty( $this->plan_state['entered_at'] ) ) {
			$this->plan_state['entered_at'] = gmdate( 'c' );
			if ( empty( $this->plan_state['status'] ) ) {
				$this->plan_state['status'] = 'exploring';
			}
		}
		if ( 'planning' === $phase ) {
			$this->plan_state['status'] = 'ready';
		}
		if ( 'executing' === $phase && empty( $this->plan_state['approved_at'] ) ) {
			$this->plan_state['approved_at'] = gmdate( 'c' );
			$this->plan_state['status']      = 'approved';
		}
	}

	/**
	 * Store the plan text produced during the planning phase.
	 *
	 * @since 5.3.0
	 */
	public function set_plan_text( string $text ): void {
		$this->plan_state['plan_text'] = sanitize_textarea_field( mb_substr( $text, 0, 4000 ) );
	}

	public function get_plan_text(): string {
		return (string) ( $this->plan_state['plan_text'] ?? '' );
	}

	/**
	 * @since 5.3.0
	 */
	public function get_plan_state(): array {
		return $this->plan_state;
	}

	/**
	 * @since 5.3.0
	 */
	public function get_plan_phase(): string {
		return $this->plan_state['phase'] ?? '';
	}

	public function get_plan_status(): string {
		return sanitize_key( (string) ( $this->plan_state['status'] ?? '' ) );
	}

	public function set_plan_steps( array $steps ): void {
		$this->plan_steps = self::sanitize_plan_steps( $steps, $this->plan_state );
		if ( empty( $this->plan_steps ) || ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return;
		}

		$artifact = PressArk_Plan_Artifact::from_plan_steps(
			$this->plan_steps,
			array(
				'prior_artifact' => $this->get_plan_artifact(),
				'approval_level' => sanitize_key( (string) ( $this->plan_state['approval_level'] ?? '' ) ),
				'execute_message' => sanitize_textarea_field( (string) ( $this->plan_state['request_context']['execute_message'] ?? $this->plan_state['request_context']['message'] ?? '' ) ),
				'request_summary' => sanitize_text_field( (string) ( $this->plan_state['request_context']['message'] ?? '' ) ),
			)
		);
		if ( ! empty( $artifact ) ) {
			$this->set_plan_artifact( $artifact );
		}
	}

	public function get_plan_steps(): array {
		$derived = $this->derive_plan_steps_from_artifact();
		if ( ! empty( $derived ) ) {
			return $derived;
		}

		return $this->plan_steps;
	}

	public function clear_plan_steps(): void {
		$this->plan_steps = array();
	}

	public function get_in_progress_plan_step_count(): int {
		return count(
			array_filter(
				$this->get_plan_steps(),
				static fn( array $step ): bool => 'in_progress' === ( $step['status'] ?? '' )
			)
		);
	}

	public function get_active_plan_step(): array {
		$index = $this->get_active_plan_step_index();
		if ( $index < 0 ) {
			return array();
		}

		$steps = $this->get_plan_steps();
		return is_array( $steps[ $index ] ?? null ) ? $steps[ $index ] : array();
	}

	public function get_active_plan_step_index(): int {
		$steps = $this->get_plan_steps();
		foreach ( $steps as $index => $step ) {
			if ( 'in_progress' === ( $step['status'] ?? '' ) ) {
				return (int) $index;
			}
		}

		foreach ( $steps as $index => $step ) {
			if ( 'pending' === ( $step['status'] ?? '' ) ) {
				return (int) $index;
			}
		}

		return -1;
	}

	public function build_plan_summary( int $limit = 4 ): string {
		$rows = array_slice( $this->get_plan_steps(), 0, max( 1, $limit ) );
		if ( empty( $rows ) ) {
			return '';
		}

		$summary = array();
		foreach ( $rows as $index => $step ) {
			$content = sanitize_text_field( (string) ( $step['content'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}

			$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			$summary[] = sprintf(
				'%d[%s] %s',
				$index + 1,
				$status,
				$content
			);
		}

		return implode( '; ', $summary );
	}

	public function record_plan_apply_success( string $tool_name, array $args = array(), array $result = array() ): void {
		$tool_name = sanitize_key( $tool_name );
		if ( '' === $tool_name ) {
			return;
		}

		$steps   = $this->get_plan_steps();
		$updated = false;

		foreach ( $steps as $index => $step ) {
			if ( ! is_array( $step ) || 'in_progress' !== ( $step['status'] ?? '' ) ) {
				continue;
			}

			if ( ! self::step_matches_plan_target( $step, $tool_name, $args ) ) {
				continue;
			}

			$steps[ $index ]['apply_succeeded'] = ! empty( $result['success'] );
			$steps[ $index ]['applied_tool_name'] = $tool_name;
			$steps[ $index ]['updated_at'] = gmdate( 'c' );

			if ( ! empty( $step['preview_required'] ) && ! empty( $result['success'] ) ) {
				$steps[ $index ]['status'] = 'completed';
			}

			$updated = true;
			break;
		}

		if ( $updated ) {
			$this->set_plan_steps( $steps );
		}
	}

	public function set_plan_status( string $status ): void {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			unset( $this->plan_state['status'] );
			return;
		}

		$this->plan_state['status'] = $status;
	}

	public function set_plan_policy( array $decision ): void {
		$this->plan_state['policy'] = array(
			'mode'              => sanitize_key( (string) ( $decision['mode'] ?? '' ) ),
			'approval_required' => ! empty( $decision['approval_required'] ),
			'reads_first'       => ! empty( $decision['reads_first'] ),
			'reason_codes'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $decision['reason_codes'] ?? array() ) ) ) ),
			'complexity_score'  => max( 0, absint( $decision['complexity_score'] ?? 0 ) ),
			'risk_score'        => max( 0, absint( $decision['risk_score'] ?? 0 ) ),
			'breadth_score'     => max( 0, absint( $decision['breadth_score'] ?? 0 ) ),
			'uncertainty_score' => max( 0, absint( $decision['uncertainty_score'] ?? 0 ) ),
			'destructive_score' => max( 0, absint( $decision['destructive_score'] ?? 0 ) ),
		);
	}

	public function get_plan_policy(): array {
		return is_array( $this->plan_state['policy'] ?? null ) ? $this->plan_state['policy'] : array();
	}

	public function set_plan_request_context( array $context ): void {
		$conversation = array();
		foreach ( array_slice( (array) ( $context['conversation'] ?? array() ), -20 ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
			$content = sanitize_textarea_field( mb_substr( (string) ( $message['content'] ?? '' ), 0, 2000 ) );
			if ( '' === $role || '' === $content ) {
				continue;
			}

			$conversation[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		$this->plan_state['request_context'] = array(
			'screen'        => sanitize_text_field( (string) ( $context['screen'] ?? '' ) ),
			'post_id'       => absint( $context['post_id'] ?? 0 ),
			'deep_mode'     => ! empty( $context['deep_mode'] ),
			'loaded_groups' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $context['loaded_groups'] ?? array() ) ) ) ),
			'chat_id'       => absint( $context['chat_id'] ?? 0 ),
			'message'       => sanitize_textarea_field( mb_substr( (string) ( $context['message'] ?? '' ), 0, 4000 ) ),
			'execute_message' => sanitize_textarea_field( mb_substr( (string) ( $context['execute_message'] ?? '' ), 0, 4000 ) ),
			'conversation'  => $conversation,
		);
	}

	public function get_plan_request_context(): array {
		return is_array( $this->plan_state['request_context'] ?? null ) ? $this->plan_state['request_context'] : array();
	}

	public function set_plan_artifact( array $artifact ): void {
		$clean = class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::sanitize( $artifact )
			: array();

		if ( empty( $clean ) ) {
			unset( $this->plan_state['current_artifact'] );
			return;
		}

		$this->plan_state['current_artifact'] = $clean;
		$this->plan_state['plan_text']        = class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::to_markdown( $clean )
			: (string) ( $this->plan_state['plan_text'] ?? '' );
		$this->plan_state['approval_level']   = sanitize_key( (string) ( $clean['approval_level'] ?? '' ) );
		unset( $this->plan_state['next_version'] );
		$this->plan_steps = $this->derive_plan_steps_from_artifact();
	}

	public function get_plan_artifact(): array {
		$artifact = class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::sanitize( $this->plan_state['current_artifact'] ?? array() )
			: array();
		if ( empty( $artifact ) && ! empty( $this->plan_state['plan_text'] ) && class_exists( 'PressArk_Plan_Artifact' ) ) {
			$artifact = PressArk_Plan_Artifact::synthesize_from_legacy(
				(string) $this->plan_state['plan_text'],
				array(),
				array(
					'approval_level' => (string) ( $this->plan_state['approval_level'] ?? 'hard' ),
				)
			);
		}

		return $artifact;
	}

	public function get_plan_history(): array {
		return is_array( $this->plan_state['history'] ?? null ) ? $this->plan_state['history'] : array();
	}

	public function queue_plan_revision( string $revision_note ): void {
		$revision_note = sanitize_text_field( $revision_note );
		if ( '' === $revision_note ) {
			return;
		}

		$this->archive_current_plan_artifact( 'revised', array( 'revision_note' => $revision_note ) );
		$artifact = $this->get_plan_artifact();
		$this->plan_state['revision_note'] = $revision_note;
		$this->plan_state['next_version']  = max( 1, absint( $artifact['version'] ?? 0 ) + 1 );
		$this->plan_state['status']        = 'revising';
	}

	public function get_plan_revision_note(): string {
		return sanitize_text_field( (string) ( $this->plan_state['revision_note'] ?? '' ) );
	}

	public function get_next_plan_version(): int {
		return max( 0, absint( $this->plan_state['next_version'] ?? 0 ) );
	}

	public function approve_plan_artifact( array $artifact = array() ): array {
		$artifact = ! empty( $artifact ) ? $artifact : $this->get_plan_artifact();
		if ( empty( $artifact ) ) {
			return array();
		}

		$artifact['approval_level'] = 'hard';
		$this->set_plan_artifact( $artifact );
		$this->set_plan_phase( 'executing' );
		$this->plan_state['status'] = 'approved';

		return $artifact;
	}

	public function reject_plan_artifact( string $reason = '' ): void {
		$this->archive_current_plan_artifact(
			'rejected',
			array(
				'reason' => sanitize_text_field( $reason ),
			)
		);
		$this->plan_state['status'] = 'rejected';
		$this->plan_state['phase']  = '';
	}

	/**
	 * Whether the agent is in a read-only exploration phase.
	 *
	 * @since 5.3.0
	 */
	public function is_exploring(): bool {
		return 'exploring' === ( $this->plan_state['phase'] ?? '' );
	}

	/**
	 * Whether the agent has an approved plan and is executing.
	 *
	 * @since 5.3.0
	 */
	public function is_plan_executing(): bool {
		return 'executing' === ( $this->plan_state['phase'] ?? '' );
	}

	public function has_active_plan_gate(): bool {
		return in_array( $this->get_plan_phase(), array( 'exploring', 'planning' ), true )
			&& ! empty( $this->get_plan_artifact() );
	}

	/**
	 * Clear plan state (e.g., on settlement).
	 *
	 * @since 5.3.0
	 */
	public function clear_plan_state(): void {
		$this->sync_stores_from_legacy_fields();
		$this->plan_state_store->clear_plan_state();
		$this->sync_legacy_fields_from_stores();
	}

	private function archive_current_plan_artifact( string $status, array $meta = array() ): void {
		$artifact = $this->get_plan_artifact();
		if ( empty( $artifact ) ) {
			return;
		}

		$history   = $this->get_plan_history();
		$history[] = array(
			'status'      => sanitize_key( $status ),
			'archived_at' => gmdate( 'c' ),
			'meta'        => array_filter(
				array(
					'revision_note' => sanitize_text_field( (string) ( $meta['revision_note'] ?? '' ) ),
					'reason'        => sanitize_text_field( (string) ( $meta['reason'] ?? '' ) ),
				)
			),
			'artifact'    => $artifact,
		);
		$this->plan_state['history'] = array_slice( $history, -8 );
	}

	public function set_loaded_tool_groups( array $groups ): void {
		$this->sync_stores_from_legacy_fields();
		$this->tool_session_state->set_loaded_tool_groups( $groups );
		$this->sync_legacy_fields_from_stores();
	}

	public function get_loaded_tool_groups(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->tool_session_state->get_loaded_tool_groups();
	}

	public function add_bundle_id( string $bundle_id ): void {
		$this->sync_stores_from_legacy_fields();
		if ( $this->tool_session_state->has_bundle( $bundle_id ) ) {
			return;
		}
		if ( count( $this->tool_session_state->get_bundle_ids() ) >= self::MAX_BUNDLES ) {
			$evicted = $this->tool_session_state->remove_oldest_bundle_id();
			if ( $evicted ) {
				self::delete_bundle_payload( $evicted );
			}
		}
		$this->tool_session_state->add_bundle_id( $bundle_id );
		$this->sync_legacy_fields_from_stores();
	}

	public function merge_bundle_ids( array $bundle_ids ): void {
		foreach ( $bundle_ids as $bundle_id ) {
			$this->add_bundle_id( (string) $bundle_id );
		}
	}

	public function get_bundle_ids(): array {
		$this->sync_stores_from_legacy_fields();
		return $this->tool_session_state->get_bundle_ids();
	}

	public function has_bundle( string $bundle_id ): bool {
		$this->sync_stores_from_legacy_fields();
		return $this->tool_session_state->has_bundle( $bundle_id );
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

	/**
	 * Absorb a durable run snapshot into this checkpoint.
	 *
	 * New agent-owned runs persist a full checkpoint snapshot at approval
	 * boundaries. Legacy workflow-era runs persisted a narrower workflow_state
	 * array. This bridge accepts either shape so resume/approval flows keep
	 * working after workflow execution has been removed.
	 *
	 * @param array $snapshot Run-owned pause snapshot.
	 * @param array $fallback Optional fallback values for legacy workflow state.
	 */
	public function absorb_run_snapshot( array $snapshot, array $fallback = array() ): void {
		if ( empty( $snapshot ) ) {
			return;
		}

		if ( self::looks_like_checkpoint_snapshot( $snapshot ) ) {
			$this->replace_with( self::merge( $this, self::from_array( $snapshot ) ) );
			return;
		}

		$this->absorb_workflow_state( $snapshot, $fallback );
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
		if ( ! empty( $workflow_state['approval_outcomes'] ) && is_array( $workflow_state['approval_outcomes'] ) ) {
			$this->merge_approval_outcomes( $workflow_state['approval_outcomes'] );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write to {prefix}pressark_chats; no core API. Caching not appropriate (write path; subsequent reads invalidate).
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is the wpdb-prefixed literal 'pressark_chats'; user data bound via %d placeholders. Custom table; no core API; chat checkpoint reads are short-lived per-session and not worth a cache layer.
		if ( $user_id > 0 ) {
			$json = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT checkpoint FROM {$table} WHERE id = %d AND user_id = %d",
					$chat_id,
					$user_id
				)
			);
		} else {
			$json = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT checkpoint FROM {$table} WHERE id = %d",
					$chat_id
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		$merged->merge_approval_outcomes( $client->approval_outcomes );
		// Blockers: union.
		$merged->merge_blockers( $client->blockers );
		$merged->context_capsule = self::merge_context_capsule_state( $server->context_capsule, $client->context_capsule );
		// Tool groups: server wins (reflects actual loaded state).
		if ( empty( $server->loaded_tool_groups ) && ! empty( $client->loaded_tool_groups ) ) {
			$merged->loaded_tool_groups = $client->loaded_tool_groups;
		}
		// Bundle IDs: union (both sides may have recorded reads).
		$merged->merge_bundle_ids( $client->bundle_ids );
		$merged->replay_state = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::merge_state( $server->replay_state, $client->replay_state )
			: ( ! empty( $server->replay_state ) ? $server->replay_state : $client->replay_state );
		if ( class_exists( 'PressArk_Read_Metadata' ) ) {
			$merged->read_state = PressArk_Read_Metadata::sanitize_snapshot_collection(
				array_merge( $server->read_state, $client->read_state )
			);
			$merged->read_invalidation_log = PressArk_Read_Metadata::sanitize_invalidation_log(
				array_merge( $server->read_invalidation_log, $client->read_invalidation_log )
			);
		}

		// v5.3.0: Plan state — server wins (server-owned truth).
		if ( self::plan_state_is_empty( $server->plan_state ) && ! self::plan_state_is_empty( $client->plan_state ) ) {
			$merged->plan_state = $client->plan_state;
		}
		if ( empty( $server->plan_steps ) && ! empty( $client->plan_steps ) ) {
			$merged->plan_steps = $client->plan_steps;
		}

		$merged->updated_at = gmdate( 'c' );

		return $merged;
	}

	private static function merge_context_capsule_state( array $server, array $client ): array {
		if ( empty( $server ) ) {
			return $client;
		}
		if ( empty( $client ) ) {
			return $server;
		}

		$server_ts = strtotime( (string) ( $server['updated_at'] ?? '' ) );
		$client_ts = strtotime( (string) ( $client['updated_at'] ?? '' ) );

		if ( $server_ts && $client_ts ) {
			return $client_ts >= $server_ts ? $client : $server;
		}
		if ( $client_ts ) {
			return $client;
		}
		if ( $server_ts ) {
			return $server;
		}

		return count( $client ) >= count( $server ) ? $client : $server;
	}

	/**
	 * Replace the in-memory checkpoint state with another checkpoint instance.
	 */
	private function replace_with( self $other ): void {
		$this->goal               = $other->goal;
		$this->entities           = $other->entities;
		$this->facts              = $other->facts;
		$this->pending            = $other->pending;
		$this->constraints        = $other->constraints;
		$this->outcomes           = $other->outcomes;
		$this->retrieval          = $other->retrieval;
		$this->execution          = $other->execution;
		$this->turn               = $other->turn;
		$this->updated_at         = $other->updated_at;
		$this->selected_target    = $other->selected_target;
		$this->workflow_stage     = $other->workflow_stage;
		$this->approvals          = $other->approvals;
		$this->approval_outcomes  = $other->approval_outcomes;
		$this->blockers           = $other->blockers;
		$this->context_capsule    = $other->context_capsule;
		$this->loaded_tool_groups = $other->loaded_tool_groups;
		$this->bundle_ids         = $other->bundle_ids;
		$this->replay_state       = $other->replay_state;
		$this->read_state         = $other->read_state;
		$this->read_invalidation_log = $other->read_invalidation_log;
		$this->plan_state         = $other->plan_state;
		$this->plan_steps         = $other->plan_steps;
		$this->sync_stores_from_legacy_fields();
	}

	/**
	 * Stage 3 compatibility bridge:
	 * Keep the typed stores synchronized with the legacy in-memory fields
	 * until all checkpoint mutation paths are migrated to store-owned writes.
	 */
	private function sync_stores_from_legacy_fields(): void {
		$legacy                   = array(
			'goal'                  => $this->goal,
			'entities'              => $this->entities,
			'facts'                 => $this->facts,
			'pending'               => $this->pending,
			'constraints'           => $this->constraints,
			'outcomes'              => $this->outcomes,
			'retrieval'             => $this->retrieval,
			'execution'             => $this->execution,
			'selected_target'       => $this->selected_target,
			'workflow_stage'        => $this->workflow_stage,
			'approvals'             => $this->approvals,
			'approval_outcomes'     => $this->approval_outcomes,
			'blockers'              => $this->blockers,
			'context_capsule'       => $this->context_capsule,
			'loaded_tool_groups'    => $this->loaded_tool_groups,
			'bundle_ids'            => $this->bundle_ids,
			'replay_state'          => $this->replay_state,
			'read_state'            => $this->read_state,
			'read_invalidation_log' => $this->read_invalidation_log,
			'plan_state'            => $this->plan_state,
			'plan_steps'            => $this->plan_steps,
		);
		$this->conversation_state = PressArk_Conversation_Checkpoint_Store::from_checkpoint_array( $legacy );
		$this->approval_state     = PressArk_Approval_State_Store::from_checkpoint_array( $legacy );
		$this->plan_state_store   = PressArk_Plan_State_Store::from_checkpoint_array( $legacy );
		$this->tool_session_state = PressArk_Tool_Session_State_Store::from_checkpoint_array( $legacy );
	}

	/**
	 * Stage 3 compatibility bridge:
	 * Rehydrate legacy fields from the typed stores so untouched runtime paths
	 * continue to see the historical checkpoint shape during the staged split.
	 */
	private function sync_legacy_fields_from_stores(): void {
		$conversation              = $this->conversation_state->to_checkpoint_array();
		$approval                  = $this->approval_state->to_checkpoint_array();
		$plan                      = $this->plan_state_store->to_checkpoint_array();
		$tool_session              = $this->tool_session_state->to_checkpoint_array();
		$this->goal                = (string) ( $conversation['goal'] ?? '' );
		$this->entities            = (array) ( $conversation['entities'] ?? array() );
		$this->facts               = (array) ( $conversation['facts'] ?? array() );
		$this->pending             = (array) ( $conversation['pending'] ?? array() );
		$this->constraints         = (array) ( $conversation['constraints'] ?? array() );
		$this->outcomes            = (array) ( $conversation['outcomes'] ?? array() );
		$this->retrieval           = (array) ( $conversation['retrieval'] ?? array() );
		$this->execution           = (array) ( $plan['execution'] ?? array() );
		$this->selected_target     = (array) ( $plan['selected_target'] ?? array() );
		$this->workflow_stage      = (string) ( $plan['workflow_stage'] ?? '' );
		$this->approvals           = (array) ( $approval['approvals'] ?? array() );
		$this->approval_outcomes   = (array) ( $approval['approval_outcomes'] ?? array() );
		$this->blockers            = (array) ( $approval['blockers'] ?? array() );
		$this->context_capsule     = (array) ( $conversation['context_capsule'] ?? array() );
		$this->loaded_tool_groups  = (array) ( $tool_session['loaded_tool_groups'] ?? array() );
		$this->bundle_ids          = (array) ( $tool_session['bundle_ids'] ?? array() );
		$this->replay_state        = (array) ( $plan['replay_state'] ?? array() );
		$this->read_state          = (array) ( $tool_session['read_state'] ?? array() );
		$this->read_invalidation_log = (array) ( $tool_session['read_invalidation_log'] ?? array() );
		$this->plan_state          = (array) ( $plan['plan_state'] ?? array() );
		$this->plan_steps          = (array) ( $plan['plan_steps'] ?? array() );
	}

	/**
	 * Detect whether a run snapshot is already shaped like a full checkpoint.
	 */
	private static function looks_like_checkpoint_snapshot( array $snapshot ): bool {
		foreach ( array(
			'goal',
			'entities',
			'facts',
			'pending',
			'constraints',
			'outcomes',
			'retrieval',
			'execution',
			'turn',
			'updated_at',
			'context_capsule',
			'replay_state',
			'read_state',
			'read_invalidation_log',
			'plan_state',
			'plan_steps',
			'approval_outcomes',
		) as $key ) {
			if ( array_key_exists( $key, $snapshot ) ) {
				return true;
			}
		}

		return false;
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
	 * A checkpoint with no `updated_at` (fresh turn 0, never touched) is NOT
	 * stale — we don't know the age, so default to "probably fine" rather than
	 * "definitely ancient". Otherwise `age_seconds()` returns `PHP_INT_MAX`
	 * and every fresh request emits a "state is 2.5e+15h old" CAUTION line.
	 *
	 * @param int $threshold_seconds Max acceptable age (default: 1 hour).
	 * @since 3.3.0
	 */
	public function is_stale( int $threshold_seconds = 3600 ): bool {
		if ( empty( $this->updated_at ) ) {
			return false;
		}
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

	/**
	 * Sanitize plan state array.
	 *
	 * @since 5.3.0
	 */
	private static function sanitize_plan_state( $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$phase = sanitize_key( $raw['phase'] ?? '' );
		if ( ! in_array( $phase, array( 'exploring', 'planning', 'executing', '' ), true ) ) {
			$phase = '';
		}

		$artifact = class_exists( 'PressArk_Plan_Artifact' )
			? PressArk_Plan_Artifact::sanitize( $raw['current_artifact'] ?? array() )
			: array();
		$history  = array();
		foreach ( array_slice( (array) ( $raw['history'] ?? array() ), -8 ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$item = array(
				'status'      => sanitize_key( (string) ( $entry['status'] ?? '' ) ),
				'archived_at' => sanitize_text_field( (string) ( $entry['archived_at'] ?? '' ) ),
				'meta'        => array_filter(
					array(
						'revision_note' => sanitize_text_field( (string) ( $entry['meta']['revision_note'] ?? '' ) ),
						'reason'        => sanitize_text_field( (string) ( $entry['meta']['reason'] ?? '' ) ),
					)
				),
				'artifact'    => class_exists( 'PressArk_Plan_Artifact' )
					? PressArk_Plan_Artifact::sanitize( $entry['artifact'] ?? array() )
					: array(),
			);
			if ( ! empty( $item['artifact'] ) ) {
				$history[] = $item;
			}
		}

		if ( '' === $phase && empty( $artifact ) && empty( $raw['plan_text'] ) && empty( $history ) ) {
			return array();
		}

		return array_filter( array(
			'phase'       => $phase,
			'plan_text'   => sanitize_textarea_field( mb_substr( (string) ( $raw['plan_text'] ?? '' ), 0, 4000 ) ),
			'entered_at'  => sanitize_text_field( $raw['entered_at'] ?? '' ),
			'approved_at' => sanitize_text_field( $raw['approved_at'] ?? '' ),
			'status'      => sanitize_key( (string) ( $raw['status'] ?? '' ) ),
			'approval_level' => sanitize_key( (string) ( $raw['approval_level'] ?? '' ) ),
			'current_artifact' => $artifact,
			'history'     => $history,
			'policy'      => is_array( $raw['policy'] ?? null ) ? array(
				'mode'              => sanitize_key( (string) ( $raw['policy']['mode'] ?? '' ) ),
				'approval_required' => ! empty( $raw['policy']['approval_required'] ),
				'reads_first'       => ! empty( $raw['policy']['reads_first'] ),
				'reason_codes'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $raw['policy']['reason_codes'] ?? array() ) ) ) ),
				'complexity_score'  => max( 0, absint( $raw['policy']['complexity_score'] ?? 0 ) ),
				'risk_score'        => max( 0, absint( $raw['policy']['risk_score'] ?? 0 ) ),
				'breadth_score'     => max( 0, absint( $raw['policy']['breadth_score'] ?? 0 ) ),
				'uncertainty_score' => max( 0, absint( $raw['policy']['uncertainty_score'] ?? 0 ) ),
				'destructive_score' => max( 0, absint( $raw['policy']['destructive_score'] ?? 0 ) ),
			) : array(),
			'request_context' => is_array( $raw['request_context'] ?? null ) ? array(
				'screen'        => sanitize_text_field( (string) ( $raw['request_context']['screen'] ?? '' ) ),
				'post_id'       => absint( $raw['request_context']['post_id'] ?? 0 ),
				'deep_mode'     => ! empty( $raw['request_context']['deep_mode'] ),
				'loaded_groups' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $raw['request_context']['loaded_groups'] ?? array() ) ) ) ),
				'chat_id'       => absint( $raw['request_context']['chat_id'] ?? 0 ),
				'message'       => sanitize_textarea_field( mb_substr( (string) ( $raw['request_context']['message'] ?? '' ), 0, 4000 ) ),
				'execute_message' => sanitize_textarea_field( mb_substr( (string) ( $raw['request_context']['execute_message'] ?? '' ), 0, 4000 ) ),
				'conversation'  => array_values(
					array_filter(
						array_map(
							static function ( $message ) {
								if ( ! is_array( $message ) ) {
									return null;
								}

								$role    = sanitize_key( (string) ( $message['role'] ?? '' ) );
								$content = sanitize_textarea_field( mb_substr( (string) ( $message['content'] ?? '' ), 0, 2000 ) );
								if ( '' === $role || '' === $content ) {
									return null;
								}

								return array(
									'role'    => $role,
									'content' => $content,
								);
							},
							array_slice( (array) ( $raw['request_context']['conversation'] ?? array() ), -20 )
						)
					)
				),
			) : array(),
			'revision_note' => sanitize_text_field( (string) ( $raw['revision_note'] ?? '' ) ),
			'next_version'  => max( 0, absint( $raw['next_version'] ?? 0 ) ),
		) );
	}

	/**
	 * @since 5.3.0
	 */
	private static function plan_state_is_empty( array $state ): bool {
		return empty( $state )
			|| (
				empty( $state['phase'] )
				&& empty( $state['plan_text'] )
				&& empty( $state['current_artifact'] )
			);
	}

	private function derive_plan_steps_from_artifact(): array {
		if ( ! class_exists( 'PressArk_Plan_Artifact' ) ) {
			return array();
		}

		$artifact = $this->get_plan_artifact();
		if ( empty( $artifact ) ) {
			return array();
		}

		$derived = array();
		$legacy_by_id      = array();
		$legacy_by_content = array();
		foreach ( $this->plan_steps as $legacy_step ) {
			if ( ! is_array( $legacy_step ) ) {
				continue;
			}
			$legacy_id = sanitize_key( (string) ( $legacy_step['id'] ?? '' ) );
			if ( '' !== $legacy_id ) {
				$legacy_by_id[ $legacy_id ] = $legacy_step;
			}
			$legacy_content = sanitize_text_field( (string) ( $legacy_step['content'] ?? $legacy_step['text'] ?? '' ) );
			if ( '' !== $legacy_content ) {
				$legacy_by_content[ $legacy_content ] = $legacy_step;
			}
		}
		foreach ( PressArk_Plan_Artifact::to_plan_steps( $artifact ) as $row ) {
			$content = sanitize_text_field( (string) ( $row['content'] ?? $row['text'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}

			$row_id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$legacy = '' !== $row_id && isset( $legacy_by_id[ $row_id ] )
				? $legacy_by_id[ $row_id ]
				: ( $legacy_by_content[ $content ] ?? array() );
			$kind   = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			$status = self::sanitize_plan_step_status( (string) ( $row['status'] ?? 'pending' ) );
			$derived[] = array(
				'id'                => $row_id,
				'content'          => $content,
				'activeForm'       => sanitize_text_field( (string) ( $row['activeForm'] ?? $legacy['activeForm'] ?? ( 'completed' === $status ? 'Completed: ' . $content : ( 'in_progress' === $status ? 'Working on: ' . $content : 'Work on: ' . $content ) ) ) ),
				'status'           => $status,
				'post_id'          => absint( $row['post_id'] ?? $legacy['post_id'] ?? 0 ),
				'tool_name'        => sanitize_key( (string) ( $row['tool_name'] ?? $legacy['tool_name'] ?? '' ) ),
				'preview_required' => array_key_exists( 'preview_required', $row ) ? ! empty( $row['preview_required'] ) : ( array_key_exists( 'preview_required', $legacy ) ? ! empty( $legacy['preview_required'] ) : in_array( $kind, array( 'preview', 'confirm', 'write' ), true ) ),
				'apply_succeeded'  => array_key_exists( 'apply_succeeded', $row ) ? ! empty( $row['apply_succeeded'] ) : ( array_key_exists( 'apply_succeeded', $legacy ) ? ! empty( $legacy['apply_succeeded'] ) : ( in_array( $kind, array( 'preview', 'confirm', 'write' ), true ) ? 'completed' === $status : true ) ),
				'applied_tool_name'=> sanitize_key( (string) ( $row['applied_tool_name'] ?? $legacy['applied_tool_name'] ?? '' ) ),
				'updated_at'       => sanitize_text_field( (string) ( $row['updated_at'] ?? $legacy['updated_at'] ?? '' ) ),
			);
		}

		return array_slice( $derived, 0, 12 );
	}

	private static function sanitize_plan_steps( $raw, array $plan_state = array() ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$steps = array();
		foreach ( array_slice( $raw, 0, 12 ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$content = sanitize_text_field( (string) ( $step['content'] ?? $step['text'] ?? $step['title'] ?? $step['label'] ?? '' ) );
			if ( '' === $content ) {
				continue;
			}

			$status = self::sanitize_plan_step_status( (string) ( $step['status'] ?? 'pending' ) );
			$steps[] = array(
				'id'               => sanitize_key( (string) ( $step['id'] ?? '' ) ),
				'content'          => $content,
				'activeForm'       => sanitize_text_field( (string) ( $step['activeForm'] ?? $content ) ),
				'status'           => $status,
				'post_id'          => absint( $step['post_id'] ?? 0 ),
				'tool_name'        => sanitize_key( (string) ( $step['tool_name'] ?? '' ) ),
				'preview_required' => ! empty( $step['preview_required'] ),
				'apply_succeeded'  => ! empty( $step['apply_succeeded'] ),
				'applied_tool_name'=> sanitize_key( (string) ( $step['applied_tool_name'] ?? '' ) ),
				'updated_at'       => sanitize_text_field( (string) ( $step['updated_at'] ?? '' ) ),
				'kind'             => sanitize_key( (string) ( $step['kind'] ?? '' ) ),
				'group'            => sanitize_key( (string) ( $step['group'] ?? '' ) ),
				'metadata'         => is_array( $step['metadata'] ?? null ) ? array_map( 'sanitize_text_field', (array) $step['metadata'] ) : array(),
			);
		}

		if ( empty( $steps ) ) {
			return array();
		}

		$phase = sanitize_key( (string) ( $plan_state['phase'] ?? '' ) );
		if ( 'executing' === $phase ) {
			$has_in_progress = false;
			foreach ( $steps as $step ) {
				if ( 'in_progress' === ( $step['status'] ?? '' ) ) {
					$has_in_progress = true;
					break;
				}
			}

			if ( ! $has_in_progress ) {
				foreach ( $steps as $index => $step ) {
					if ( 'pending' === ( $step['status'] ?? '' ) ) {
						$steps[ $index ]['status'] = 'in_progress';
						break;
					}
				}
			}
		}

		return $steps;
	}

	private static function sanitize_plan_step_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( 'active' === $status ) {
			$status = 'in_progress';
		}
		if ( in_array( $status, array( 'done', 'verified' ), true ) ) {
			$status = 'completed';
		}

		// v5.8.6 (2026-05-13, post-iter-41): preserve explicit blocked
		// plan steps so a failed diagnostic branch can be skipped cleanly.
		return in_array( $status, array( 'pending', 'blocked', 'in_progress', 'completed' ), true )
			? $status
			: 'pending';
	}

	private static function step_matches_plan_target( array $step, string $tool_name, array $args ): bool {
		$expected_tool = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
		if ( '' !== $expected_tool && $expected_tool !== $tool_name ) {
			return false;
		}

		$expected_post_id = absint( $step['post_id'] ?? 0 );
		if ( $expected_post_id > 0 ) {
			$actual_post_id = absint( $args['post_id'] ?? $args['id'] ?? $args['product_id'] ?? 0 );
			if ( $actual_post_id > 0 && $actual_post_id !== $expected_post_id ) {
				return false;
			}
		}

		return true;
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

	private static function sanitize_approval_outcomes( array $raw ): array {
		if ( ! class_exists( 'PressArk_Permission_Decision' ) ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( $raw, -12 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$outcome = PressArk_Permission_Decision::normalize_approval_outcome( $item );
			if ( ! empty( $outcome ) ) {
				$clean[] = $outcome;
			}
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
			'compaction'        => self::sanitize_context_capsule_compaction( $raw['compaction'] ?? array() ),
			'updated_at'          => sanitize_text_field( (string) ( $raw['updated_at'] ?? '' ) ),
		);

		return array_filter(
			$clean,
			static function ( $value ) {
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			}
		);
	}

	private static function sanitize_context_capsule_compaction( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array(
			'count'                  => max( 0, (int) ( $raw['count'] ?? 0 ) ),
			'last_marker'            => sanitize_key( (string) ( $raw['last_marker'] ?? '' ) ),
			'last_reason'            => sanitize_key( (string) ( $raw['last_reason'] ?? '' ) ),
			'last_round'             => absint( $raw['last_round'] ?? 0 ),
			'last_at'                => sanitize_text_field( (string) ( $raw['last_at'] ?? '' ) ),
			'last_event'             => self::sanitize_compaction_event( $raw['last_event'] ?? array() ),
			'pending_post_compaction' => self::sanitize_compaction_pending( $raw['pending_post_compaction'] ?? array() ),
			'first_post_compaction'  => self::sanitize_compaction_observation( $raw['first_post_compaction'] ?? array() ),
		);

		return array_filter(
			$clean,
			static function ( $value, $key ) {
				if ( 'count' === $key ) {
					return $value > 0;
				}
				return ! ( is_array( $value ) ? empty( $value ) : '' === (string) $value );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	private static function sanitize_compaction_event( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_filter( array(
			'marker'                  => sanitize_key( (string) ( $raw['marker'] ?? '' ) ),
			'reason'                  => sanitize_key( (string) ( $raw['reason'] ?? '' ) ),
			'round'                   => absint( $raw['round'] ?? 0 ),
			'before_messages'         => max( 0, (int) ( $raw['before_messages'] ?? 0 ) ),
			'after_messages'          => max( 0, (int) ( $raw['after_messages'] ?? 0 ) ),
			'dropped_messages'        => max( 0, (int) ( $raw['dropped_messages'] ?? 0 ) ),
			'estimated_tokens_before' => max( 0, (int) ( $raw['estimated_tokens_before'] ?? 0 ) ),
			'estimated_tokens_after'  => max( 0, (int) ( $raw['estimated_tokens_after'] ?? 0 ) ),
			'remaining_tokens'        => max( 0, (int) ( $raw['remaining_tokens'] ?? 0 ) ),
			'context_pressure'        => sanitize_key( (string) ( $raw['context_pressure'] ?? '' ) ),
			'summary_mode'            => sanitize_key( (string) ( $raw['summary_mode'] ?? '' ) ),
			'at'                      => sanitize_text_field( (string) ( $raw['at'] ?? '' ) ),
		), static function ( $value ) {
			return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
		} );
	}

	private static function sanitize_compaction_pending( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_filter( array(
			'marker' => sanitize_key( (string) ( $raw['marker'] ?? '' ) ),
			'reason' => sanitize_key( (string) ( $raw['reason'] ?? '' ) ),
			'round'  => absint( $raw['round'] ?? 0 ),
			'at'     => sanitize_text_field( (string) ( $raw['at'] ?? '' ) ),
		), static function ( $value ) {
			return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
		} );
	}

	private static function sanitize_compaction_observation( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_filter( array(
			'marker'           => sanitize_key( (string) ( $raw['marker'] ?? '' ) ),
			'reason'           => sanitize_key( (string) ( $raw['reason'] ?? '' ) ),
			'observed_round'   => absint( $raw['observed_round'] ?? 0 ),
			'stop_reason'      => sanitize_key( (string) ( $raw['stop_reason'] ?? '' ) ),
			'tool_calls'       => max( 0, (int) ( $raw['tool_calls'] ?? 0 ) ),
			'had_text'         => ! empty( $raw['had_text'] ),
			'healthy'          => ! empty( $raw['healthy'] ),
			'remaining_tokens' => max( 0, (int) ( $raw['remaining_tokens'] ?? 0 ) ),
			'context_pressure' => sanitize_key( (string) ( $raw['context_pressure'] ?? '' ) ),
			'at'               => sanitize_text_field( (string) ( $raw['at'] ?? '' ) ),
		), static function ( $value ) {
			if ( is_bool( $value ) ) {
				return true;
			}
			return ! ( is_int( $value ) ? 0 === $value : '' === (string) $value );
		} );
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
		if ( class_exists( 'PressArk_Read_Metadata' ) && ! empty( $result['read_meta'] ) ) {
			$clean['read_meta'] = PressArk_Read_Metadata::sanitize_snapshot( $result['read_meta'] );
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
