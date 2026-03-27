<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for async task queue backends.
 *
 * Implementations schedule/cancel task execution via different mechanisms
 * (Action Scheduler, WP-Cron) while the PressArk_Task_Queue class handles
 * persistence and business logic through PressArk_Task_Store.
 *
 * @package PressArk
 * @since   2.5.0
 */
abstract class PressArk_Queue_Backend {

	/**
	 * Schedule a task for execution after a delay.
	 *
	 * @param string $task_id Unique task identifier (UUID).
	 * @param int    $delay   Seconds to wait before execution (default 5).
	 * @return bool True if scheduled successfully.
	 */
	abstract public function schedule( string $task_id, int $delay = 5 ): bool;

	/**
	 * Cancel a scheduled task (if not yet executed).
	 *
	 * @param string $task_id Unique task identifier.
	 * @return bool True if cancelled (or was not scheduled).
	 */
	abstract public function cancel( string $task_id ): bool;

	/**
	 * Backend identifier for logging and diagnostics.
	 *
	 * @return string Backend name (e.g. 'action_scheduler', 'wp_cron').
	 */
	abstract public function get_name(): string;
}
