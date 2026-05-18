<?php
/**
 * PressArk Continuation Service — read-only adapter for continuation decisions.
 *
 * v5.7.13 (2026-05-13, iter-33, Phase 3a of REFACTOR_SESSION.md):
 *
 * One pure function returning the typed continuation decision struct. Mirrors
 * the legacy decisions made today in three places:
 *   - PressArk_Orchestration_Service::attach_continuation_context (3 call sites)
 *   - PressArk_Orchestration_Service::should_clear_plan_state_after_execution
 *   - PressArk_Run_Approval_Service::attach_continuation_context (3 call sites)
 *   - PressArk_Run_Approval_Service::apply_preview_keep inline clear gate
 *
 * Phase 3a (this iter): adapter only. Legacy logic remains the source of truth.
 * Each call site attaches the evaluator's output to result['continuation']
 * under evaluator_* keys so we can audit reason_code drift against the legacy
 * decision in captured envelopes. NO call site switches to evaluator-as-truth.
 *
 * Phase 3b: call sites consume evaluator-as-truth. The evaluator_* mirrors
 * remain for forensic grep, while canonical continuation fields are populated
 * from this decision.
 *
 * @package PressArk
 * @since   5.7.13
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Continuation_Service {

	/**
	 * Evaluate continuation state for a checkpoint after a write or approval
	 * settlement.
	 *
	 * Pure function — does NOT mutate the checkpoint, ledger, or result.
	 *
	 * @param ?PressArk_Checkpoint     $checkpoint The run checkpoint (post-mutation).
	 * @param array<string,mixed>      $execution  Ledger array (typically $checkpoint->get_execution()).
	 * @param array<string,mixed>|null $last_emission Optional metadata about the last model emission
	 *                                                or settlement that triggered evaluation. Reserved
	 *                                                for Phase 3b; not consumed yet.
	 *
	 * @return array{
	 *   should_resume: bool,
	 *   should_clear_plan: bool,
	 *   should_emit_wrap_round: bool,
	 *   reason_code: string,
	 *   blockers: array<int,string>,
	 *   inputs: array<string,mixed>
	 * }
	 */
	public static function evaluate(
		?PressArk_Checkpoint $checkpoint,
		array $execution,
		?array $last_emission = null
	): array {
		unset( $last_emission ); // reserved for Phase 3b.

		$decision = array(
			'should_resume'          => false,
			'should_clear_plan'      => true,
			'should_emit_wrap_round' => false,
			'reason_code'            => 'no_checkpoint',
			'blockers'               => array(),
			'inputs'                 => array(
				'has_execution_ledger'    => class_exists( 'PressArk_Execution_Ledger' ),
				'has_checkpoint'          => null !== $checkpoint,
				'ledger_task_count'       => is_array( $execution['tasks'] ?? null ) ? count( $execution['tasks'] ) : 0,
				'artifact_step_count'     => 0,
				'plan_steps_count'        => 0,
				'remaining_in_artifact'   => 0,
				'remaining_in_plan_steps' => 0,
				'model_plan_task_count'    => 0,
				'progress_remaining'      => 0,
				'progress_is_complete'    => false,
				'progress_should_resume'  => false,
				'blocker_count'           => 0,
			),
		);

		if ( null === $checkpoint || ! class_exists( 'PressArk_Execution_Ledger' ) ) {
			return $decision;
		}

		$progress = PressArk_Execution_Ledger::progress_snapshot( $execution );
		$blockers = method_exists( $checkpoint, 'get_blockers' ) ? (array) $checkpoint->get_blockers() : array();

		$decision['blockers']                 = array_values( array_map( 'strval', $blockers ) );
		$decision['inputs']['blocker_count']  = count( $blockers );
		$decision['inputs']['progress_remaining']     = (int) ( $progress['remaining_count'] ?? 0 );
		$decision['inputs']['progress_is_complete']   = ! empty( $progress['is_complete'] );
		$decision['inputs']['progress_should_resume'] = ! empty( $progress['should_auto_resume'] );

		// Plan artifact walk (canonical post-Phase-1).
		$artifact_remaining = 0;
		$artifact_count     = 0;
		if ( method_exists( $checkpoint, 'get_plan_artifact' ) ) {
			$artifact = (array) $checkpoint->get_plan_artifact();
			$steps    = is_array( $artifact['steps'] ?? null ) ? $artifact['steps'] : array();
			$artifact_count = count( $steps );
			foreach ( $steps as $step ) {
				$status = sanitize_key( (string) ( ( is_array( $step ) ? $step['status'] : null ) ?? 'pending' ) );
				if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
					$artifact_remaining++;
				}
			}
		}

		// Plan steps walk (compatibility projection — kept as defense-in-depth).
		$plan_steps_remaining = 0;
		$plan_steps_count     = 0;
		if ( method_exists( $checkpoint, 'get_plan_steps' ) ) {
			$plan_steps = (array) $checkpoint->get_plan_steps();
			$plan_steps_count = count( $plan_steps );
			foreach ( $plan_steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
				if ( ! in_array( $status, array( 'completed', 'verified', 'skipped' ), true ) ) {
					$plan_steps_remaining++;
				}
			}
		}

		$decision['inputs']['artifact_step_count']     = $artifact_count;
		$decision['inputs']['plan_steps_count']        = $plan_steps_count;
		$decision['inputs']['remaining_in_artifact']   = $artifact_remaining;
		$decision['inputs']['remaining_in_plan_steps'] = $plan_steps_remaining;

		$has_remaining_plan = $artifact_remaining > 0 || $plan_steps_remaining > 0;
		$ledger_has_more    = PressArk_Execution_Ledger::has_remaining_tasks( $execution );
		$model_plan_task_count = 0;
		foreach ( (array) ( $execution['tasks'] ?? array() ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$metadata = is_array( $task['metadata'] ?? null ) ? $task['metadata'] : array();
			if ( in_array( sanitize_key( (string) ( $metadata['origin'] ?? '' ) ), array( 'model_plan', 'dynamic_execution' ), true ) ) {
				$model_plan_task_count++;
			}
		}
		$decision['inputs']['model_plan_task_count'] = $model_plan_task_count;

		// ── should_resume ─────────────────────────────────────────────────
		//
		// Mirrors orchestration-service::attach_continuation_context (line ~4959):
		//   empty($blockers) && ( $progress['should_auto_resume'] || $has_remaining_plan_steps )
		//
		// Run-approval-service's three sites currently use:
		//   $progress['should_auto_resume'] && empty($blockers)
		// — which Phase 3b will need to reconcile. The evaluator returns the
		// orchestration-service shape (the more comprehensive of the two).
		if ( ! empty( $blockers ) ) {
			$decision['reason_code'] = 'blocked';
		} elseif ( ! empty( $progress['should_auto_resume'] ) ) {
			$decision['should_resume'] = true;
			$decision['reason_code']   = 'ledger_should_resume';
		} elseif ( $has_remaining_plan ) {
			$decision['should_resume'] = true;
			if ( $artifact_remaining > 0 && $plan_steps_remaining > 0 ) {
				$decision['reason_code'] = 'plan_remaining_both';
			} elseif ( $artifact_remaining > 0 ) {
				$decision['reason_code'] = 'plan_remaining_artifact';
			} else {
				$decision['reason_code'] = 'plan_remaining_steps_only';
			}
		} elseif ( ! empty( $progress['is_complete'] ) ) {
			$decision['reason_code'] = 'progress_complete';
		} else {
			$decision['reason_code'] = 'no_remaining_work';
		}

		// ── should_clear_plan ─────────────────────────────────────────────
		//
		// Mirrors orchestration-service::should_clear_plan_state_after_execution
		// (line 1154). Clear is safe only when:
		//   - the ledger has no remaining tasks AND
		//   - the plan artifact has no unfinished steps AND
		//   - plan_steps has no unfinished steps.
		//
		// Note: the run-approval-service apply_preview_keep inline gate at
		// line 521-535 checks only the artifact; the evaluator returns the
		// stricter dual-source decision. Phase 3b will need to reconcile.
		if ( ! empty( $blockers ) ) {
			$decision['should_clear_plan'] = false;
		} elseif ( $ledger_has_more || $has_remaining_plan ) {
			$decision['should_clear_plan'] = false;
		} else {
			$decision['should_clear_plan'] = true;
		}

		// ── should_emit_wrap_round ───────────────────────────────────────
		//
		// "Emit a wrap" semantically means: tracked plan work has hit a
		// stopping point AND there is no further work-resume coming. The ledger
		// keeps model_plan task rows even after plan_state is cleared, so this
		// remains true at the final post-Keep boundary where the client needs
		// one cheap summary round.
		$has_model_plan_context = $artifact_count > 0 || $plan_steps_count > 0 || $model_plan_task_count > 0;
		$decision['should_emit_wrap_round'] = ! $decision['should_resume']
			&& empty( $blockers )
			&& $has_model_plan_context
			&& ( ! empty( $progress['is_complete'] ) || $decision['should_clear_plan'] );

		return $decision;
	}

	/**
	 * Phase 3a helper — attach the evaluator's typed struct under
	 * result['continuation']['evaluator'] for forensic A/B against the legacy
	 * decision attached by attach_continuation_context.
	 *
	 * Side-effect free with respect to the checkpoint. Returns a new result
	 * array; never mutates the input.
	 */
	public static function attach_to_result(
		array $result,
		?PressArk_Checkpoint $checkpoint,
		?array $last_emission = null
	): array {
		$execution = $checkpoint && method_exists( $checkpoint, 'get_execution' )
			? (array) $checkpoint->get_execution()
			: array();
		$decision  = self::evaluate( $checkpoint, $execution, $last_emission );

		if ( ! isset( $result['continuation'] ) || ! is_array( $result['continuation'] ) ) {
			$result['continuation'] = array();
		}
		$result['continuation']['evaluator'] = $decision;
		// Top-level mirrors for easy grep in captured envelopes / logs.
		$result['continuation']['evaluator_should_resume']     = $decision['should_resume'];
		$result['continuation']['evaluator_should_clear_plan'] = $decision['should_clear_plan'];
		$result['continuation']['evaluator_should_emit_wrap']  = $decision['should_emit_wrap_round'];
		$result['continuation']['evaluator_reason_code']       = $decision['reason_code'];
		$result['continuation']['should_resume']               = $decision['should_resume'];
		$result['continuation']['should_clear_plan']           = $decision['should_clear_plan'];
		$result['continuation']['should_emit_wrap_round']      = $decision['should_emit_wrap_round'];
		$result['continuation']['reason_code']                 = $decision['reason_code'];
		$result['continuation']['blockers']                    = $decision['blockers'];
		$result['continuation']['should_auto_resume']          = $decision['should_resume'] || $decision['should_emit_wrap_round'];

		return $result;
	}
}
