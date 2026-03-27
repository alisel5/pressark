<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron queue backend (fallback).
 *
 * Used on sites where Action Scheduler is not available.
 * Less reliable than AS but works on all WordPress installations.
 *
 * @package PressArk
 * @since   2.5.0
 */
class PressArk_Queue_Cron extends PressArk_Queue_Backend {

	/**
	 * Hook name used for all async task processing.
	 */
	private const HOOK = 'pressark_process_async_task';

	/**
	 * Schedule a task via WP-Cron single event.
	 */
	public function schedule( string $task_id, int $delay = 5 ): bool {
		$result = wp_schedule_single_event(
			time() + $delay,
			self::HOOK,
			array( $task_id )
		);

		// wp_schedule_single_event returns true on success (WP 5.1+) or void (older).
		return $result !== false;
	}

	/**
	 * Cancel a scheduled task via WP-Cron.
	 */
	public function cancel( string $task_id ): bool {
		wp_clear_scheduled_hook(
			self::HOOK,
			array( $task_id )
		);

		return true;
	}

	/**
	 * Backend identifier.
	 */
	public function get_name(): string {
		return 'wp_cron';
	}
}
