<?php
/**
 * PressArk Run Approval Service — Reusable continuation for preview/confirm.
 *
 * Extracts the approval logic from REST-only handlers (handle_confirm,
 * handle_preview_keep) into a reusable internal service that both:
 *   - Browser-driven approval (REST endpoints call this)
 *   - Unattended automation approval (dispatcher calls this)
 * go through the same run-aware continuation contract.
 *
 * This is NOT a duplication of the existing logic. It's the extracted core
 * that the REST handlers delegate to.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Run_Approval_Service {

	/**
	 * Apply a confirmed action from a run's pending_actions.
	 *
	 * This is the core logic from handle_confirm(), extracted for reuse.
	 *
	 * @param string $run_id       Run ID.
	 * @param int    $action_index Index into pending_actions.
	 * @param int    $user_id      User to execute as.
	 * @return array Execution result.
	 */
	public static function apply_confirm( string $run_id, int $action_index, int $user_id ): array {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run ) {
			return array( 'success' => false, 'message' => 'Run not found.' );
		}

		if ( (int) $run['user_id'] !== $user_id ) {
			return array( 'success' => false, 'message' => 'User does not own this run.' );
		}

		if ( 'awaiting_confirm' !== $run['status'] ) {
			return array( 'success' => false, 'message' => "Run is in \"{$run['status']}\" state, not awaiting_confirm." );
		}

		$pending = $run['pending_actions'] ?? array();
		if ( ! isset( $pending[ $action_index ] ) ) {
			return array( 'success' => false, 'message' => 'Invalid action index.' );
		}

		$action = $pending[ $action_index ]['action'] ?? $pending[ $action_index ];

		if ( empty( $action ) ) {
			return array( 'success' => false, 'message' => 'No action data in pending_actions.' );
		}

		// Execute the action.
		wp_set_current_user( $user_id );
		$logger = new PressArk_Action_Logger();
		$engine = new PressArk_Action_Engine( $logger );
		$result = $engine->execute_single( $action );

		// Track usage.
		if ( ! empty( $result['success'] ) ) {
			$tracker = new PressArk_Usage_Tracker();
			$tracker->increment_if_write( $action['type'] ?? '' );
		}

		// Settle the run via pipeline authority.
		$result = PressArk_Pipeline::settle_run( $run_id, $result );

		// Checkpoint bookkeeping (mirrors class-chat.php handle_confirm).
		$checkpoint = self::load_run_checkpoint( $run );
		if ( $checkpoint ) {
			if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
				$checkpoint->absorb_workflow_state( $run['workflow_state'] );
			}
			$action_args = is_array( $action['params'] ?? null ) ? $action['params'] : array();
			if ( empty( $action_args ) ) {
				$action_args = $action;
				unset( $action_args['type'], $action_args['description'] );
			}
			$checkpoint->record_execution_write( (string) ( $action['type'] ?? '' ), $action_args, $result );
			if ( ! empty( $result['success'] ) ) {
				$checkpoint->clear_blockers();
				$checkpoint->add_approval( (string) ( $action['type'] ?? 'confirmed_action' ) );
				$checkpoint->set_workflow_stage( 'settled' );
			} else {
				$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Confirmed action failed.' ) );
			}
			self::persist_run_checkpoint( $run, $checkpoint );
			$result['checkpoint'] = $checkpoint->to_array();
			$result = self::attach_continuation_context( $result, $run, $checkpoint );
		}

		return $result;
	}

	/**
	 * Apply all pending confirm actions from a run (unattended batch).
	 *
	 * Used by automation dispatcher to auto-approve all pending actions
	 * that pass the policy check.
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $user_id User to execute as.
	 * @param string $policy  Automation policy level.
	 * @return array { success: bool, results: array, policy_blocked: string[] }
	 */
	public static function apply_all_confirms( string $run_id, int $user_id, string $policy ): array {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run || 'awaiting_confirm' !== $run['status'] ) {
			return array( 'success' => false, 'results' => array(), 'policy_blocked' => array() );
		}

		$pending = $run['pending_actions'] ?? array();
		$results           = array();
		$results_by_index  = array();
		$blocked           = array();

		wp_set_current_user( $user_id );
		$logger  = new PressArk_Action_Logger();
		$engine  = new PressArk_Action_Engine( $logger );
		$tracker = new PressArk_Usage_Tracker();

		foreach ( $pending as $idx => $item ) {
			$action = $item['action'] ?? $item;
			$op_name = $action['type'] ?? '';

			// Policy check.
			$check = PressArk_Automation_Policy::check( $op_name, $policy, $action['params'] ?? array() );
			if ( ! $check['allowed'] ) {
				$blocked[] = $check['reason'] ?? "Blocked: {$op_name}";
				continue;
			}

			$result = $engine->execute_single( $action );
			if ( ! empty( $result['success'] ) ) {
				$tracker->increment_if_write( $op_name );
			}
			$results[]              = $result;
			$results_by_index[ $idx ] = $result;
		}

		// If any actions were blocked, fail the run with policy info.
		if ( ! empty( $blocked ) && empty( $results ) ) {
			PressArk_Pipeline::fail_run( $run_id, 'All actions blocked by automation policy: ' . implode( '; ', $blocked ) );
			return array( 'success' => false, 'results' => array(), 'policy_blocked' => $blocked );
		}

		// Check if any executed actions failed.
		$failed_count  = 0;
		$success_count = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['success'] ) ) {
				$success_count++;
			} else {
				$failed_count++;
			}
		}

		$all_succeeded = $failed_count === 0 && $success_count > 0 && empty( $blocked );

		// Settle the merged results.
		$merged = array(
			'success'           => $all_succeeded,
			'actions_performed' => $results,
			'message'           => sprintf( '%d action(s) succeeded, %d failed.', $success_count, $failed_count ),
		);

		if ( ! empty( $blocked ) ) {
			$merged['policy_blocked'] = $blocked;
			$merged['message'] .= ' ' . count( $blocked ) . ' action(s) blocked by policy.';
		}

		if ( $all_succeeded ) {
			$merged = PressArk_Pipeline::settle_run( $run_id, $merged );
		} else {
			PressArk_Pipeline::fail_run( $run_id, $merged['message'] );
		}

		// Checkpoint bookkeeping for each executed action.
		$checkpoint = self::load_run_checkpoint( $run );
		if ( $checkpoint ) {
			if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
				$checkpoint->absorb_workflow_state( $run['workflow_state'] );
			}
			foreach ( $pending as $idx => $item ) {
				$action = $item['action'] ?? $item;
				$op_name = $action['type'] ?? '';
				if ( isset( $results_by_index[ $idx ] ) ) {
					$action_args = is_array( $action['params'] ?? null ) ? $action['params'] : array();
					$checkpoint->record_execution_write( $op_name, $action_args, $results_by_index[ $idx ] );
					if ( ! empty( $results_by_index[ $idx ]['success'] ) ) {
						$checkpoint->add_approval( $op_name ?: 'confirmed_action' );
					} else {
						$checkpoint->add_blocker( (string) ( $results_by_index[ $idx ]['message'] ?? ( 'Confirmed action failed: ' . $op_name ) ) );
					}
				}
			}
			if ( ! empty( $blocked ) ) {
				$checkpoint->merge_blockers( $blocked );
			}
			if ( $all_succeeded ) {
				$checkpoint->clear_blockers();
				$checkpoint->set_workflow_stage( 'settled' );
			}
			self::persist_run_checkpoint( $run, $checkpoint );
			$merged['checkpoint'] = $checkpoint->to_array();
			$merged = self::attach_continuation_context( $merged, $run, $checkpoint );
		}

		return array(
			'success'          => $all_succeeded,
			'results'          => $results,
			'results_by_index' => $results_by_index,
			'policy_blocked'   => $blocked,
			'message'          => $merged['message'],
		);
	}

	/**
	 * Apply a preview-keep from a run (unattended).
	 *
	 * @param string $run_id  Run ID.
	 * @param int    $user_id User to execute as.
	 * @param string $policy  Automation policy level.
	 * @return array Execution result.
	 */
	public static function apply_preview_keep( string $run_id, int $user_id, string $policy ): array {
		$run_store = new PressArk_Run_Store();
		$run       = $run_store->get( $run_id );

		if ( ! $run || 'awaiting_preview' !== $run['status'] ) {
			return array( 'success' => false, 'message' => 'Run not found or not awaiting preview.' );
		}

		if ( (int) $run['user_id'] !== $user_id ) {
			return array( 'success' => false, 'message' => 'User mismatch.' );
		}

		$session_id = $run['preview_session_id'] ?? '';
		if ( empty( $session_id ) ) {
			return array( 'success' => false, 'message' => 'No preview session ID in run.' );
		}

		// Check the preview session's tool calls against policy.
		wp_set_current_user( $user_id );
		$preview       = new PressArk_Preview();
		$session_calls = $preview->get_session_tool_calls( $session_id );

		foreach ( $session_calls as $call ) {
			$op_name = $call['name'] ?? $call['type'] ?? '';
			$check   = PressArk_Automation_Policy::check( $op_name, $policy, $call['arguments'] ?? array() );
			if ( ! $check['allowed'] ) {
				// Discard the preview and fail.
				$preview->discard( $session_id );
				$checkpoint = self::load_run_checkpoint( $run );
				if ( $checkpoint ) {
					if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
						$checkpoint->absorb_workflow_state( $run['workflow_state'] );
					}
					$checkpoint->add_blocker( (string) ( $check['reason'] ?? $op_name ) );
					self::persist_run_checkpoint( $run, $checkpoint );
				}
				PressArk_Pipeline::fail_run( $run_id, 'Preview blocked by automation policy: ' . ( $check['reason'] ?? $op_name ) );
				return array(
					'success'        => false,
					'message'        => 'Preview contained operations outside automation policy.',
					'policy_blocked' => array( $check['reason'] ?? $op_name ),
				);
			}
		}

		// Apply the preview.
		$result = $preview->keep( $session_id );

		if ( ! $result['success'] ) {
			PressArk_Pipeline::fail_run( $run_id, 'Preview apply failed: ' . ( $result['message'] ?? 'unknown' ) );
			return $result;
		}

		// Track usage.
		$tracker = new PressArk_Usage_Tracker();
		$tracker->increment_if_write( 'preview_apply' );

		// Settle the run.
		$result = PressArk_Pipeline::settle_run( $run_id, $result );

		// Checkpoint bookkeeping (mirrors class-chat.php handle_preview_keep).
		$checkpoint = self::load_run_checkpoint( $run );
		if ( $checkpoint ) {
			if ( ! empty( $run['workflow_state'] ) && is_array( $run['workflow_state'] ) ) {
				$checkpoint->absorb_workflow_state( $run['workflow_state'] );
			}
			$checkpoint->record_execution_preview( $session_calls, $result );
			foreach ( $session_calls as $call ) {
				$checkpoint->add_approval( (string) ( $call['name'] ?? $call['type'] ?? 'preview_apply' ) );
			}
			if ( ! empty( $result['success'] ) ) {
				$checkpoint->clear_blockers();
				$checkpoint->set_workflow_stage( 'settled' );
			} else {
				$checkpoint->add_blocker( (string) ( $result['message'] ?? 'Preview apply failed.' ) );
			}
			self::persist_run_checkpoint( $run, $checkpoint );
			$result['checkpoint'] = $checkpoint->to_array();
			$result = self::attach_continuation_context( $result, $run, $checkpoint );
		}

		return $result;
	}

	// ── Checkpoint helpers (mirror class-chat.php private methods) ──────

	/**
	 * Load or bootstrap a checkpoint for a run.
	 */
	private static function load_run_checkpoint( array $run ): ?PressArk_Checkpoint {
		if ( ! class_exists( 'PressArk_Checkpoint' ) ) {
			return null;
		}

		$chat_id = (int) ( $run['chat_id'] ?? 0 );
		$user_id = (int) ( $run['user_id'] ?? 0 );

		$checkpoint = $chat_id > 0
			? PressArk_Checkpoint::load( $chat_id, $user_id )
			: null;

		if ( ! $checkpoint ) {
			$checkpoint = PressArk_Checkpoint::from_array( array() );
		}

		$checkpoint->sync_execution_goal( (string) ( $run['message'] ?? '' ) );
		return $checkpoint;
	}

	/**
	 * Persist a checkpoint back to the owning chat.
	 */
	private static function persist_run_checkpoint( array $run, PressArk_Checkpoint $checkpoint ): void {
		$checkpoint->touch();
		$chat_id = (int) ( $run['chat_id'] ?? 0 );
		$user_id = (int) ( $run['user_id'] ?? 0 );

		if ( $chat_id > 0 ) {
			$checkpoint->save( $chat_id, $user_id );
		}
	}

	/**
	 * Attach continuation metadata grounded in the execution ledger.
	 */
	private static function attach_continuation_context( array $result, array $run, ?PressArk_Checkpoint $checkpoint = null ): array {
		$result['continuation'] = array(
			'original_message' => $run['message'] ?? '',
		);

		foreach ( array( 'post_id', 'post_title', 'url', 'post_type', 'post_status', 'targets' ) as $field ) {
			if ( isset( $result[ $field ] ) ) {
				$result['continuation'][ $field ] = $result[ $field ];
			}
		}

		if ( $checkpoint && class_exists( 'PressArk_Execution_Ledger' ) ) {
			$execution = $checkpoint->get_execution();
			$progress  = PressArk_Execution_Ledger::progress_snapshot( $execution );
			$blockers  = $checkpoint->get_blockers();

			$result['continuation']['execution']          = $execution;
			$result['continuation']['progress']           = $progress;
			$result['continuation']['blockers']           = $blockers;
			$result['continuation']['should_auto_resume'] = ! empty( $progress['should_auto_resume'] ) && empty( $blockers );

			if ( ! empty( $progress['is_complete'] ) ) {
				$result['continuation']['completion_message'] = 'All requested steps are complete. Do not continue automatically.';
			} elseif ( ! empty( $blockers ) ) {
				$result['continuation']['pause_message'] = 'Auto-resume paused because unresolved blockers remain.';
			}
		}

		return $result;
	}
}
