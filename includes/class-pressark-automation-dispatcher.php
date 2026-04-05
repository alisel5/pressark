<?php
/**
 * PressArk Automation Dispatcher — Finds and fires due automations.
 *
 * Called by cron (Action Scheduler preferred, WP-Cron fallback).
 * Does NOT do heavy AI work in the scheduler callback — enqueues
 * a normal async task instead.
 *
 * Missed-run policy: if a site wakes up late, run once, record overdue,
 * then compute the next future occurrence cleanly. Never flood backlog.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Automation_Dispatcher {

	/**
	 * Check for due automations and dispatch them.
	 *
	 * Called by the cron hook `pressark_dispatch_automations`.
	 * Processes up to 5 automations per invocation to avoid
	 * hogging the cron worker.
	 */
	public static function dispatch(): void {
		$store = new PressArk_Automation_Store();
		$due   = $store->find_due( 5 );

		if ( empty( $due ) ) {
			return;
		}

		foreach ( $due as $automation ) {
			self::dispatch_one( $automation, $store );
		}
	}

	/**
	 * Dispatch a single automation.
	 *
	 * 1. Atomically claim (skipped for event-triggered dispatches)
	 * 2. Validate entitlements
	 * 3. Create durable run
	 * 4. Enqueue async task
	 * 5. Compute and persist next occurrence
	 *
	 * @param array                    $automation     Automation row.
	 * @param PressArk_Automation_Store $store         Store instance.
	 * @param bool                     $skip_claim     Skip atomic claim (event triggers).
	 * @param string|null              $prompt_override When set, use this prompt instead of stored.
	 */
	public static function dispatch_one( array $automation, PressArk_Automation_Store $store, bool $skip_claim = false, ?string $prompt_override = null ): void {
		$automation_id = $automation['automation_id'];
		$claim_token   = wp_generate_uuid4();

		// For event-triggered dispatches, use a time-bucketed slot to enable
		// idempotency dedup within the cooldown window (60-second buckets).
		// For scheduled dispatches, use the scheduled next_run_at slot.
		if ( $skip_claim ) {
			$bucket = (int) floor( time() / 60 );
			$scheduled_slot = 'event_' . $bucket;
		} else {
			$scheduled_slot = $automation['next_run_at'] ?? current_time( 'mysql', true );
		}

		// Atomic claim — prevents double-dispatch (skipped for event triggers).
		if ( ! $skip_claim && ! $store->claim( $automation_id, $claim_token ) ) {
			PressArk_Error_Tracker::debug( 'AutomationDispatcher', 'Dispatch skipped: claim failed (already claimed)', array( 'automation_id' => $automation_id ) );
			return;
		}

		$user_id = $automation['user_id'];

		// MUST set user before constructing License — its constructor
		// reads get_current_user_id() for the cache key. If we construct
		// License first, it uses the wrong user (0 in cron, or the admin
		// viewer instead of the automation owner) and the tier resolves
		// to "free", silently blocking every scheduled dispatch.
		wp_set_current_user( $user_id );

		$task_store = new PressArk_Task_Store();
		$task_idempotency_key = self::build_task_idempotency_key( $automation_id, $scheduled_slot );

		// Validate entitlements.
		$license = new PressArk_License();
		$tier = $license->get_tier();

		if ( ! PressArk_Entitlements::can_use_feature( $tier, 'automations' ) ) {
			$store->update( $automation_id, array(
				'last_run_id'  => '',
				'last_task_id' => '',
			) );
			$store->record_failure( $automation_id, '', 'Automation feature not available on ' . PressArk_Entitlements::tier_label( $tier ) . ' plan.' );
			if ( ! $skip_claim ) {
				self::compute_and_persist_next( $automation, $store );
				$store->release_claim( $automation_id );
			}
			PressArk_Notification_Manager::notify_automation_failure( $automation, 'Your plan does not include scheduled automations. Upgrade to continue.' );
			return;
		}

		// Token budget check.
		if ( ! PressArk_Entitlements::can_write( $tier ) ) {
			$store->update( $automation_id, array(
				'last_run_id'  => '',
				'last_task_id' => '',
			) );
			$store->record_failure( $automation_id, '', 'Write quota exhausted.' );
			if ( ! $skip_claim ) {
				self::compute_and_persist_next( $automation, $store );
				$store->release_claim( $automation_id );
			}
			return;
		}

		$existing_task_id = $task_store->find_in_flight_by_idempotency_key( $task_idempotency_key );
		if ( '' !== $existing_task_id ) {
			$store->update( $automation_id, array( 'last_task_id' => $existing_task_id ) );
			self::compute_and_persist_next( $automation, $store );
			$store->release_claim( $automation_id );

			PressArk_Error_Tracker::debug( 'AutomationDispatcher', 'Skipped duplicate dispatch', array( 'automation_id' => $automation_id, 'slot' => $scheduled_slot, 'task_id' => $existing_task_id ) );
			return;
		}

		try {
			// Create the automation's hidden conversation if needed.
			$chat_id = $automation['chat_id'];
			if ( $chat_id <= 0 ) {
				$chat_history = new PressArk_Chat_History();
				$chat_id = (int) $chat_history->create_chat(
					'[Automation] ' . ( $automation['name'] ?: 'Scheduled Prompt' ),
					array()
				);
				if ( $chat_id > 0 ) {
					$store->update( $automation_id, array( 'chat_id' => $chat_id ) );
				}
			}

			// Resolve the effective prompt (may be overridden for event triggers).
			$effective_prompt = $prompt_override ?? $automation['prompt'];

			// Build conversation from automation memory.
			$conversation = self::build_automation_conversation( $automation, $chat_id, $user_id );
			$correlation_id = PressArk_Activity_Trace::new_correlation_id();

			PressArk_Activity_Trace::clear_current_context();
			PressArk_Activity_Trace::set_current_context(
				array(
					'correlation_id' => $correlation_id,
					'user_id'        => $user_id,
					'chat_id'        => $chat_id,
					'route'          => 'automation',
				)
			);
			PressArk_Activity_Trace::publish(
				array(
					'event_type' => 'request.started',
					'phase'      => 'request',
					'status'     => 'started',
					'reason'     => 'request_started',
					'summary'    => 'Automation dispatch accepted and awaiting reservation/run creation.',
					'payload'    => array(
						'automation_id' => $automation_id,
						'scheduled_slot' => (string) $scheduled_slot,
						'trigger_mode'  => $skip_claim ? 'event' : 'schedule',
					),
				)
			);

			// Create durable run.
			$reservation    = new PressArk_Reservation();
			$estimated      = $reservation->estimate_tokens( $effective_prompt, $conversation, $tier );
			$reserve_result = $reservation->reserve( $user_id, $estimated, 'pending', $tier );

			if ( empty( $reserve_result['ok'] ) ) {
				$store->update( $automation_id, array(
					'last_run_id'  => '',
					'last_task_id' => '',
				) );
				$store->record_failure( $automation_id, '', 'Token reservation failed: ' . ( $reserve_result['error'] ?? 'insufficient budget' ) );
				if ( ! $skip_claim ) {
					self::compute_and_persist_next( $automation, $store );
					$store->release_claim( $automation_id );
				}
				PressArk_Notification_Manager::notify_automation_failure( $automation, 'Token reservation failed. Your token budget may be exhausted.' );
				return;
			}

			$reservation_id  = $reserve_result['reservation_id'];
			$resolved_context = array(
				'screen'        => '',
				'post_id'       => 0,
				'chat_id'       => $chat_id,
				'automation_id' => $automation_id,
			);

			// Build loaded groups from execution hints.
			$hints         = $automation['execution_hints'] ?? array();
			$loaded_groups = $hints['loaded_groups'] ?? array();

			// Build checkpoint from automation memory.
			$checkpoint_data = null;
			if ( $chat_id > 0 ) {
				$server_cp = PressArk_Checkpoint::load( $chat_id, $user_id );
				if ( $server_cp && ! $server_cp->is_empty() ) {
					$checkpoint_data = $server_cp->to_array();
				}
			}

			$handoff_capsule = PressArk_Task_Queue::build_handoff_capsule(
				$effective_prompt,
				$conversation,
				$loaded_groups,
				$checkpoint_data,
				$resolved_context,
				'automation_dispatch'
			);
			$run_store       = new PressArk_Run_Store();
			$lineage         = $run_store->create_background_family(
				'automation',
				array(
					'user_id'         => $user_id,
					'chat_id'         => $chat_id,
					'message'         => $effective_prompt,
					'reservation_id'  => $reservation_id,
					'correlation_id'  => $correlation_id,
					'tier'            => $tier,
					'handoff_capsule' => $handoff_capsule,
				)
			);
			$parent_run_id   = (string) ( $lineage['parent_run_id'] ?? '' );
			$run_id          = (string) ( $lineage['run_id'] ?? '' );
			$root_run_id     = (string) ( $lineage['root_run_id'] ?? $parent_run_id );

			PressArk_Activity_Trace::set_current_context(
				array(
					'correlation_id' => $correlation_id,
					'user_id'        => $user_id,
					'chat_id'        => $chat_id,
					'route'          => 'automation',
					'run_id'         => $run_id,
					'reservation_id' => $reservation_id,
				)
			);

			// Enqueue as async task — DO NOT run AI here in the cron callback.
			$queue  = new PressArk_Task_Queue();
			$queued = $queue->enqueue(
				$effective_prompt,
				$conversation,
				array(),
				$user_id,
				false,            // deep_mode — automations use standard routing
				$reservation_id,
				$loaded_groups,
				$checkpoint_data,
				$run_id,
				$resolved_context,
				$task_idempotency_key,
				array(
					'parent_run_id'   => $parent_run_id,
					'root_run_id'     => $root_run_id,
					'handoff_capsule' => $handoff_capsule,
				)
			);

			$task_id = $queued['task_id'] ?? '';

			if ( 'error' === ( $queued['type'] ?? '' ) ) {
				$error_message = $queued['message'] ?? 'Automation task queueing failed.';
				$reservation->fail( $reservation_id, $error_message );
				if ( '' !== $parent_run_id ) {
					PressArk_Pipeline::fail_run( $parent_run_id, $error_message );
				}
				if ( '' !== $run_id ) {
					PressArk_Pipeline::fail_run( $run_id, $error_message );
				}
				$store->update( $automation_id, array(
					'last_run_id'  => '',
					'last_task_id' => '',
				) );
				$store->record_failure( $automation_id, $run_id, $error_message, $task_id );
				if ( ! $skip_claim ) {
					self::compute_and_persist_next( $automation, $store );
					$store->release_claim( $automation_id );
				}
				PressArk_Notification_Manager::notify_automation_failure( $automation, $error_message );
				return;
			}

			if ( ! empty( $queued['reused_existing'] ) ) {
				$reservation->fail( $reservation_id, 'Duplicate automation dispatch reused the already active task.' );
				if ( ! empty( $queued['run_id'] ) && (string) $queued['run_id'] !== $run_id ) {
					PressArk_Pipeline::fail_run( $run_id, 'Duplicate automation dispatch reused the already active task.' );
					$run_id = (string) $queued['run_id'];
				}

				if ( '' !== $parent_run_id ) {
					PressArk_Pipeline::settle_run(
						$parent_run_id,
						array(
							'type'            => 'handoff',
							'message'         => 'Automation handoff reused the already active queued task.',
							'task_id'         => $task_id,
							'child_run_id'    => $run_id,
							'root_run_id'     => (string) ( $queued['root_run_id'] ?? $root_run_id ),
							'handoff_capsule' => $handoff_capsule,
						)
					);
				}

				$store->update(
					$automation_id,
					array(
						'last_run_id'  => $run_id,
						'last_task_id' => $task_id,
					)
				);
				if ( ! $skip_claim ) {
					self::compute_and_persist_next( $automation, $store );
					$store->release_claim( $automation_id );
				}

				PressArk_Error_Tracker::debug( 'AutomationDispatcher', 'Collapsed duplicate queue request', array( 'automation_id' => $automation_id, 'task_id' => $task_id ) );
				return;
			}

			if ( ! empty( $queued['run_id'] ) ) {
				$run_id = (string) $queued['run_id'];
			}

			if ( '' !== $parent_run_id ) {
				PressArk_Pipeline::settle_run(
					$parent_run_id,
					array(
						'type'            => 'handoff',
						'message'         => 'Automation handoff accepted and linked to a worker run.',
						'task_id'         => $task_id,
						'child_run_id'    => $run_id,
						'root_run_id'     => (string) ( $queued['root_run_id'] ?? $root_run_id ),
						'handoff_capsule' => $handoff_capsule,
					)
				);
			}

			// Update automation with run/task linkage.
			$store->update( $automation_id, array(
				'last_run_id'  => $run_id,
				'last_task_id' => $task_id,
			) );

			// Compute and persist next occurrence BEFORE the task runs.
			// Skip for event-triggered dispatches — they don't advance the schedule.
			if ( ! $skip_claim ) {
				self::compute_and_persist_next( $automation, $store );

				// Release the dispatch claim immediately so the automation is
				// visible to the next sweep cycle without waiting for the
				// 10-minute stale-claim timeout.
				$store->release_claim( $automation_id );
			}

			PressArk_Error_Tracker::info( 'AutomationDispatcher', 'Automation dispatched', array( 'automation_id' => $automation_id, 'run_id' => $run_id, 'task_id' => $task_id, 'next_run_at' => $automation['next_run_at'] ?? 'none' ) );

		} catch ( \Throwable $e ) {
			$store->update( $automation_id, array(
				'last_run_id'  => '',
				'last_task_id' => '',
			) );
			$store->record_failure( $automation_id, '', $e->getMessage() );

			if ( ! $skip_claim ) {
				self::compute_and_persist_next( $automation, $store );
				$store->release_claim( $automation_id );
			}

			PressArk_Notification_Manager::notify_automation_failure( $automation, $e->getMessage() );

			PressArk_Error_Tracker::error( 'AutomationDispatcher', 'Automation dispatch failed', array( 'automation_id' => $automation_id, 'error' => $e->getMessage() ) );
		} finally {
			PressArk_Activity_Trace::clear_current_context();
		}
	}

	/**
	 * Compute and persist the next run time.
	 */
	private static function compute_and_persist_next( array $automation, PressArk_Automation_Store $store ): void {
		// Use the automation's scheduled slot (not "now") to prevent permanent drift
		// when runs are late or manually triggered.
		$from = $automation['next_run_at'] ?? current_time( 'mysql', true );
		$next = PressArk_Automation_Recurrence::compute_next(
			$automation['cadence_type'],
			$automation['cadence_value'],
			$automation['timezone'],
			$automation['first_run_at'],
			$from
		);

		if ( null === $next ) {
			$store->update( $automation['automation_id'], array(
				'next_run_at' => null,
			) );
			// Cancel stale wake event — prevents duplicate dispatch for
			// one-shot automations that were dispatched immediately.
			self::cancel_wake( $automation['automation_id'] );
		} else {
			$store->update( $automation['automation_id'], array( 'next_run_at' => $next ) );

			// Schedule a single-fire cron for the exact next occurrence.
			self::schedule_next_wake( $automation['automation_id'], $next );
		}
	}

	/**
	 * Schedule a cron wake-up for a specific automation's next run.
	 *
	 * Uses Action Scheduler when available, WP-Cron single event fallback.
	 * Schedules only the NEXT fire — not a recurring interval.
	 */
	public static function schedule_next_wake( string $automation_id, string $next_run_utc ): void {
		$timestamp = strtotime( $next_run_utc );
		if ( ! $timestamp ) {
			return;
		}

		// Ensure the event is always in the future so the scheduler accepts
		// it. Previously, already-due times were silently dropped with the
		// assumption that the 5-minute sweep cron would catch them — but the
		// sweep itself depends on WP-Cron which fails in Docker, firewalled
		// hosts, and low-traffic sites. A 5-second minimum delay ensures the
		// wake-up is always registered.
		$timestamp = max( $timestamp, time() + 5 );

		$hook = 'pressark_automation_wake';
		$args = array( $automation_id );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Cancel any existing wake for this automation.
			as_unschedule_action( $hook, $args, 'pressark' );
			as_schedule_single_action( $timestamp, $hook, $args, 'pressark' );
		} else {
			// WP-Cron: clear previous, schedule new.
			wp_clear_scheduled_hook( $hook, $args );
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	/**
	 * Cancel any scheduled wake for an automation.
	 *
	 * Called when an automation has no next run (one-shot completed,
	 * archived, etc.) to prevent stale wake events from firing and
	 * causing duplicate dispatch attempts.
	 *
	 * @since 4.3.1
	 */
	private static function cancel_wake( string $automation_id ): void {
		$hook = 'pressark_automation_wake';
		$args = array( $automation_id );

		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( $hook, $args, 'pressark' );
		}
		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Handle a wake-up for a specific automation.
	 * Called by the `pressark_automation_wake` hook.
	 */
	public static function handle_wake( string $automation_id ): void {
		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation || 'active' !== $automation['status'] ) {
			return;
		}

		self::dispatch_one( $automation, $store );
	}

	/**
	 * Build a compact conversation for automation execution.
	 *
	 * Uses the automation's own chat history (trimmed) plus
	 * execution hints to avoid repetition.
	 */
	private static function build_automation_conversation( array $automation, int $chat_id, int $user_id ): array {
		$conversation = array();

		// Load recent conversation from the automation's chat.
		if ( $chat_id > 0 ) {
			$chat_history = new PressArk_Chat_History();
			$stored_chat  = $chat_history->get_chat( $chat_id );
			if ( $stored_chat && is_array( $stored_chat['messages'] ) ) {
				// Keep only last 6 messages to control token usage.
				$conversation = array_slice( $stored_chat['messages'], -6 );
			}
		}

		// Inject execution hints as a system-like context message.
		$hints = $automation['execution_hints'] ?? array();
		if ( ! empty( $hints['recent_targets'] ) ) {
			$targets_text = implode( ', ', array_slice( $hints['recent_targets'], -10 ) );
			$hint_msg = "[Automation Context] Recent targets from previous runs: {$targets_text}. Try to avoid obvious repetition.";
			array_unshift( $conversation, array(
				'role'    => 'assistant',
				'content' => $hint_msg,
			) );
		}

		return $conversation;
	}

	/**
	 * Manually trigger a "run now" for an automation.
	 *
	 * @param string $automation_id Automation ID.
	 * @param int    $user_id       User ID.
	 * @return array { success: bool, run_id?: string, task_id?: string, error?: string }
	 */
	public static function run_now( string $automation_id, int $user_id ): array {
		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation ) {
			return array( 'success' => false, 'error' => 'Automation not found.' );
		}

		if ( (int) $automation['user_id'] !== $user_id ) {
			return array( 'success' => false, 'error' => 'You do not own this automation.' );
		}

		if ( ! in_array( $automation['status'], array( 'active', 'paused', 'failed' ), true ) ) {
			return array( 'success' => false, 'error' => 'Automation is archived and cannot be run.' );
		}

		// Temporarily set next_run_at to now to make it due.
		$store->update( $automation_id, array(
			'next_run_at' => current_time( 'mysql', true ),
			'status'      => 'active', // Reactivate if paused/failed.
		) );

		// Refresh and dispatch immediately.
		$previous_run_id  = (string) ( $automation['last_run_id'] ?? '' );
		$previous_task_id = (string) ( $automation['last_task_id'] ?? '' );
		$automation = $store->get( $automation_id );
		self::dispatch_one( $automation, $store );

		$automation = $store->get( $automation_id );
		$current_run_id  = (string) ( $automation['last_run_id'] ?? '' );
		$current_task_id = (string) ( $automation['last_task_id'] ?? '' );
		if (
			'' === $current_run_id
			|| '' === $current_task_id
			|| (
				$current_run_id === $previous_run_id
				&& $current_task_id === $previous_task_id
			)
		) {
			return array(
				'success' => false,
				'error'   => $automation['last_error'] ?? 'Failed to dispatch automation.',
			);
		}

		return array(
			'success' => true,
			'run_id'  => $current_run_id,
			'task_id' => $current_task_id,
		);
	}

	/**
	 * Attempt immediate dispatch if the automation is currently due.
	 *
	 * Called after creating or resuming an automation to bypass the
	 * cron/scheduler path entirely. This is the primary fix for Docker
	 * and firewalled environments where WP-Cron's loopback fails and
	 * the cron lock blocks the sidecar.
	 *
	 * Best-effort — if this fails, the scheduled wake or rescue sweep
	 * will catch it on the next admin page load.
	 *
	 * @since 4.3.1
	 */
	public static function dispatch_if_due( string $automation_id ): void {
		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation || 'active' !== $automation['status'] ) {
			return;
		}

		$next = $automation['next_run_at'];
		if ( ! $next || strtotime( $next ) > time() + 60 ) {
			return; // Not due within the next 60 seconds.
		}

		// Save and restore current user since dispatch_one() calls
		// wp_set_current_user() which would pollute the admin context.
		$previous_user = get_current_user_id();
		try {
			self::dispatch_one( $automation, $store );
		} catch ( \Throwable $e ) {
			PressArk_Error_Tracker::error( 'AutomationDispatcher', 'dispatch_if_due failed', array( 'automation_id' => $automation_id, 'error' => $e->getMessage() ) );
		} finally {
			if ( $previous_user > 0 ) {
				wp_set_current_user( $previous_user );
			}
		}
	}

	/**
	 * Compute the next run when a paused/failed automation is resumed.
	 *
	 * One-shot automations are special: if they have never succeeded, they
	 * should keep their original future slot or run immediately when overdue.
	 */
	public static function next_run_for_resume( array $automation, ?string $reference_utc = null ): ?string {
		$reference = $reference_utc ?: current_time( 'mysql', true );

		if ( 'once' === ( $automation['cadence_type'] ?? '' ) ) {
			if ( ! empty( $automation['last_success_at'] ) ) {
				return null;
			}

			$first_run_at = (string) ( $automation['first_run_at'] ?? $reference );
			return strtotime( $first_run_at ) > strtotime( $reference ) ? $first_run_at : $reference;
		}

		return PressArk_Automation_Recurrence::compute_next(
			$automation['cadence_type'],
			$automation['cadence_value'],
			$automation['timezone'],
			$automation['first_run_at'],
			$reference
		);
	}

	/**
	 * Build a task idempotency key scoped to one automation occurrence.
	 */
	private static function build_task_idempotency_key( string $automation_id, string $scheduled_slot ): string {
		return 'automation_' . md5( $automation_id . '|' . $scheduled_slot );
	}

	// ── Hook Registration ─────────────────────────────────────────────

	/**
	 * Register automation dispatch WordPress hooks.
	 *
	 * @since 4.2.0
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'schedule_dispatch' ) );
		add_action( 'pressark_dispatch_automations', array( self::class, 'dispatch' ) );
		add_action( 'pressark_automation_wake', array( self::class, 'handle_wake' ) );

		// AS queue runner safety net (cron-based — works when loopback is OK).
		add_action( 'init', array( self::class, 'schedule_as_runner_kick' ) );
		add_action( 'pressark_kick_as_runner', array( PressArk_Cron_Manager::class, 'maybe_kick_as_runner' ) );

		// Inline rescue: fire due automations on admin page loads when
		// WP-Cron is broken (Docker, firewalled hosts, low-traffic sites).
		// Dispatch is lightweight (enqueues tasks, doesn't run AI), so
		// running inline is safe and adds negligible latency.
		add_action( 'admin_init', array( self::class, 'maybe_rescue_dispatch' ), 99 );

		// Inline AS kick: process stuck Action Scheduler actions on admin
		// page loads. AS depends on HTTP loopback for its queue runner,
		// which fails in Docker and firewalled hosts. This kicks the runner
		// directly — no loopback needed. Separate transient from dispatch
		// rescue so it fires even when dispatch has no work to do.
		add_action( 'admin_init', array( self::class, 'maybe_kick_as_runner_inline' ), 100 );
	}

	/**
	 * Inline rescue: dispatch due automations on admin page loads.
	 *
	 * WP-Cron depends on HTTP loopback to wp-cron.php, which fails in
	 * Docker (port mapping mismatch), firewalled hosts, and low-traffic
	 * sites. This fallback runs directly during admin_init — no loopback
	 * needed. Throttled via transient to once every 15 seconds.
	 *
	 * @since 4.3.0
	 */
	public static function maybe_rescue_dispatch(): void {
		$transient_key = 'pressark_dispatch_rescue';
		if ( get_transient( $transient_key ) ) {
			return;
		}
		// 15-second throttle (was 120s). The find_due() query uses an index
		// and is sub-millisecond, so frequent checks are safe. The shorter
		// window ensures automations fire within seconds of becoming due on
		// any admin page load — critical in Docker where WP-Cron's loopback
		// fails and the cron lock can block the sidecar.
		set_transient( $transient_key, 1, 15 );

		$previous_user = get_current_user_id();
		self::dispatch();
		if ( $previous_user > 0 ) {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Kick Action Scheduler runner directly on admin page loads.
	 *
	 * Action Scheduler depends on HTTP loopback (async request to
	 * admin-ajax.php) for its queue runner. This fails in Docker
	 * (port mapping mismatch), firewalled hosts, and reverse proxies,
	 * causing all AS actions to pile up in 'pending' status forever.
	 *
	 * This calls ActionScheduler::runner()->run() inline to process
	 * pending pressark actions. Throttled to every 90 seconds. Uses
	 * its own transient so it fires independently of the dispatch rescue.
	 *
	 * @since 4.3.0
	 */
	public static function maybe_kick_as_runner_inline(): void {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		$transient_key = 'pressark_as_kick_inline';
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 90 );

		PressArk_Cron_Manager::maybe_kick_as_runner();
	}

	/**
	 * @since 4.2.0
	 */
	public static function schedule_dispatch(): void {
		PressArk_Cron_Manager::schedule_automation_dispatch();
	}

	/**
	 * Schedule AS runner kick via WP-Cron as fallback for AS loopback failures.
	 *
	 * @since 4.2.0
	 */
	public static function schedule_as_runner_kick(): void {
		if ( class_exists( 'ActionScheduler' ) && ! wp_next_scheduled( 'pressark_kick_as_runner' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'pressark_kick_as_runner' );
		}
	}
}
