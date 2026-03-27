<?php
/**
 * PressArk Automation Service — Post-execution handler for automation runs.
 *
 * Called after an async task completes when the task was dispatched by
 * an automation. Handles:
 *   - Unattended preview/confirm auto-approval via policy
 *   - Notification dispatch (success/failure/policy-block)
 *   - Execution hints update (recent targets, loaded groups)
 *   - Automation record update (success/failure tracking)
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Automation_Service {

	/**
	 * Handle post-execution for an automation-triggered task.
	 *
	 * Called from the async task processor after the AI run completes,
	 * when the resolved_context contains an automation_id.
	 *
	 * @param string $automation_id Automation ID.
	 * @param string $run_id        Run ID.
	 * @param string $task_id       Task ID.
	 * @param array  $result        AI execution result.
	 */
	public static function handle_completion( string $automation_id, string $run_id, string $task_id, array $result ): array {
		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation ) {
			return array(
				'success' => false,
				'result'  => $result,
				'error'   => 'Automation not found.',
			);
		}

		$user_id = $automation['user_id'];
		$policy  = $automation['approval_policy'] ?? PressArk_Automation_Policy::POLICY_EDITORIAL;

		$result_type = $result['type'] ?? 'final_response';

		try {
			// Handle preview results — auto-apply via policy.
			if ( 'preview' === $result_type ) {
				$apply_result = PressArk_Run_Approval_Service::apply_preview_keep( $run_id, $user_id, $policy );

				if ( ! empty( $apply_result['policy_blocked'] ) ) {
					$error = 'Policy blocked: ' . implode( '; ', $apply_result['policy_blocked'] );
					$store->record_failure( $automation_id, $run_id, $error, $task_id );
					PressArk_Notification_Manager::notify_automation_policy_block( $automation, implode( '; ', $apply_result['policy_blocked'] ) );
					$apply_result['is_error'] = true;
					$apply_result['message']  = $error;
					return array(
						'success' => false,
						'result'  => array_merge( $result, $apply_result ),
						'error'   => $error,
					);
				}

				if ( empty( $apply_result['success'] ) ) {
					$error = $apply_result['message'] ?? 'Preview apply failed.';
					$store->record_failure( $automation_id, $run_id, $error, $task_id );
					PressArk_Notification_Manager::notify_automation_failure( $automation, $error );
					$apply_result['is_error'] = true;
					return array(
						'success' => false,
						'result'  => array_merge( $result, $apply_result ),
						'error'   => $error,
					);
				}

				// Merge apply result into final result for notification.
				$result = array_merge( $result, $apply_result );
			}

			// Handle confirm card results — auto-apply via policy.
			elseif ( 'confirm_card' === $result_type ) {
				$apply_result = PressArk_Run_Approval_Service::apply_all_confirms( $run_id, $user_id, $policy );

				if ( ! empty( $apply_result['policy_blocked'] ) ) {
					$error = $apply_result['message'] ?? 'Automation actions were blocked by policy.';
					$store->record_failure( $automation_id, $run_id, $error, $task_id );
					PressArk_Notification_Manager::notify_automation_policy_block( $automation, implode( '; ', $apply_result['policy_blocked'] ) );
					$result['actions_performed'] = $apply_result['results'];
					$result['policy_blocked']    = $apply_result['policy_blocked'];
					$result['message']           = $error;
					$result['is_error']          = true;
					return array(
						'success' => false,
						'result'  => $result,
						'error'   => $error,
					);
				}

				if ( ! $apply_result['success'] ) {
					$error = $apply_result['message'] ?? 'Failed to apply confirmed actions.';
					$store->record_failure( $automation_id, $run_id, $error, $task_id );
					PressArk_Notification_Manager::notify_automation_failure( $automation, $error );
					$result['actions_performed'] = $apply_result['results'];
					$result['message']           = $error;
					$result['is_error']          = true;
					return array(
						'success' => false,
						'result'  => $result,
						'error'   => $error,
					);
				}

				$result['actions_performed'] = $apply_result['results'];
				$result['message']           = $apply_result['message'] ?? ( $result['message'] ?? $result['reply'] ?? 'Task completed.' );
			}

			// Handle errors.
			elseif ( ! empty( $result['is_error'] ) ) {
				$error = $result['message'] ?? $result['reply'] ?? 'Unknown error.';
				$store->record_failure( $automation_id, $run_id, $error, $task_id );
				PressArk_Notification_Manager::notify_automation_failure( $automation, $error );
				return array(
					'success' => false,
					'result'  => $result,
					'error'   => $error,
				);
			}

			// Success path.
			$store->record_success( $automation_id, $run_id, $task_id );

			// Update execution hints.
			self::update_execution_hints( $automation, $result );

			// Save conversation to the automation's chat.
			self::update_automation_conversation( $automation, $result );

			// Send success notification.
			$notify_result = PressArk_Notification_Manager::notify_automation_success( $automation, $result );

			// Notification failure does NOT fail the automation run.
			if ( ! $notify_result['success'] ) {
				PressArk_Error_Tracker::warning( 'AutomationService', 'Automation notification failed', array( 'automation_id' => $automation_id, 'error' => $notify_result['error'] ?? 'unknown' ) );
			}

			return array(
				'success' => true,
				'result'  => $result,
			);

		} catch ( \Throwable $e ) {
			$store->record_failure( $automation_id, $run_id, $e->getMessage(), $task_id );
			PressArk_Notification_Manager::notify_automation_failure( $automation, $e->getMessage() );

			PressArk_Error_Tracker::error( 'AutomationService', 'Automation execution failed', array( 'automation_id' => $automation_id, 'error' => $e->getMessage() ) );

			return array(
				'success' => false,
				'result'  => array_merge( $result, array(
					'is_error' => true,
					'message'  => $e->getMessage(),
				) ),
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Handle permanent failure of an automation task.
	 */
	public static function handle_failure( string $automation_id, string $run_id, string $error, string $task_id = '' ): void {
		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation ) {
			return;
		}

		$store->record_failure( $automation_id, $run_id, $error, $task_id );
		PressArk_Notification_Manager::notify_automation_failure( $automation, $error );
	}

	/**
	 * Update execution hints from a completed run.
	 *
	 * Tracks recent targets and loaded groups so future runs can:
	 * - Avoid repetition (e.g. "pick a random product" won't pick the same one)
	 * - Reduce tool discovery waste (pre-load known groups)
	 */
	private static function update_execution_hints( array $automation, array $result ): void {
		$store = new PressArk_Automation_Store();
		$hints = $automation['execution_hints'] ?? array();

		// Track loaded groups.
		if ( ! empty( $result['loaded_groups'] ) ) {
			$hints['loaded_groups'] = $result['loaded_groups'];
		}

		// Track recent targets from actions performed.
		$recent = $hints['recent_targets'] ?? array();
		foreach ( $result['actions_performed'] ?? array() as $action ) {
			$target = '';
			if ( ! empty( $action['target_id'] ) ) {
				$target = ( $action['target_type'] ?? 'post' ) . '#' . $action['target_id'];
			} elseif ( ! empty( $action['post_id'] ) ) {
				$target = 'post#' . $action['post_id'];
			}
			if ( $target && ! in_array( $target, $recent, true ) ) {
				$recent[] = $target;
			}
		}
		// Keep last 20 targets.
		$hints['recent_targets'] = array_slice( $recent, -20 );

		// Track last task type.
		if ( ! empty( $result['steps'] ) ) {
			$last_step = end( $result['steps'] );
			$hints['last_task_type'] = $last_step['tool'] ?? '';
		}

		// Track a compact receipt summary.
		$summary = $result['message'] ?? $result['reply'] ?? '';
		if ( mb_strlen( $summary ) > 200 ) {
			$summary = mb_substr( $summary, 0, 197 ) . '...';
		}
		$hints['last_receipt_summary'] = $summary;
		$hints['last_run_at'] = current_time( 'mysql', true );

		$store->update( $automation['automation_id'], array( 'execution_hints' => $hints ) );
	}

	/**
	 * Update the automation's chat with the latest exchange.
	 */
	private static function update_automation_conversation( array $automation, array $result ): void {
		$chat_id = $automation['chat_id'];
		if ( $chat_id <= 0 ) {
			return;
		}

		$chat_history = new PressArk_Chat_History();
		$stored_chat  = $chat_history->get_chat( $chat_id );
		$messages     = ( $stored_chat && is_array( $stored_chat['messages'] ) )
			? $stored_chat['messages']
			: array();

		// Append the user prompt.
		$messages[] = array( 'role' => 'user', 'content' => $automation['prompt'] );

		// Append the assistant reply.
		$reply = $result['message'] ?? $result['reply'] ?? 'Task completed.';
		$messages[] = array( 'role' => 'assistant', 'content' => $reply );

		// Keep last 20 messages to prevent unbounded growth.
		if ( count( $messages ) > 20 ) {
			$messages = array_slice( $messages, -20 );
		}

		$chat_history->update_chat( $chat_id, $messages );
	}
}
