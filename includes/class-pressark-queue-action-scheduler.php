<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler queue backend.
 *
 * Used when Action Scheduler is available (e.g. WooCommerce sites).
 * Provides more reliable execution than WP-Cron with built-in logging,
 * concurrency controls, and admin visibility.
 *
 * @package PressArk
 * @since   2.5.0
 */
class PressArk_Queue_Action_Scheduler extends PressArk_Queue_Backend {

	/**
	 * Hook name used for all async task processing.
	 */
	private const HOOK = 'pressark_process_async_task';

	/**
	 * Action Scheduler group for PressArk tasks.
	 */
	private const GROUP = 'pressark';

	/**
	 * Schedule a task via Action Scheduler.
	 */
	public function schedule( string $task_id, int $delay = 5 ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$action_id = as_schedule_single_action(
			time() + $delay,
			self::HOOK,
			array( $task_id ),
			self::GROUP
		);

		return $action_id > 0;
	}

	/**
	 * Cancel a scheduled task via Action Scheduler.
	 */
	public function cancel( string $task_id ): bool {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return false;
		}

		as_unschedule_action(
			self::HOOK,
			array( $task_id ),
			self::GROUP
		);

		return true;
	}

	/**
	 * Backend identifier.
	 */
	public function get_name(): string {
		return 'action_scheduler';
	}
}
