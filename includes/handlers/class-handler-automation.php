<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PressArk Automation Handler — Runtime handler for automation domain tools.
 *
 * Provides tool implementations for the AI to manage automations:
 * list, create, update, pause/resume, run now, delete, inspect history.
 *
 * @package PressArk
 * @since   4.0.0
 */

class PressArk_Handler_Automation extends PressArk_Handler_Base {

	/**
	 * List all automations for the current user.
	 */
	public function list_automations( array $params ): array {
		$store = new PressArk_Automation_Store();
		$user_id = get_current_user_id();
		$status  = sanitize_key( $params['status'] ?? '' );

		$automations = $store->list_for_user( $user_id, $status );

		$items = array();
		foreach ( $automations as $a ) {
			$items[] = array(
				'automation_id' => $a['automation_id'],
				'name'          => $a['name'],
				'status'        => $a['status'],
				'cadence'       => PressArk_Automation_Recurrence::label( $a['cadence_type'], $a['cadence_value'] ),
				'next_run_at'   => $a['next_run_at'],
				'last_success'  => $a['last_success_at'],
				'last_failure'  => $a['last_failure_at'],
				'last_error'    => $a['last_error'],
				'failure_streak' => $a['failure_streak'],
			);
		}

		return array(
			'success'     => true,
			'automations' => $items,
			'count'       => count( $items ),
		);
	}

	/**
	 * Create a new automation.
	 */
	public function create_automation( array $params ): array {
		$user_id = get_current_user_id();
		$tier    = ( new PressArk_License() )->get_tier();

		// Entitlement check.
		if ( ! PressArk_Entitlements::can_use_feature( $tier, 'automations' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Scheduled automations require a Pro or higher plan.', 'pressark' ),
			);
		}

		// Active automation limit.
		$store = new PressArk_Automation_Store();
		$max_automations = PressArk_Entitlements::tier_value( $tier, 'max_automations' ) ?? 3;
		$current_count   = $store->count_active( $user_id );
		// -1 means unlimited (enterprise tier).
		if ( $max_automations >= 0 && $current_count >= $max_automations ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: current number of active automations, 2: plan limit */
					__( 'You have %1$d active automations (limit: %2$d for your plan). Pause or delete an existing one.', 'pressark' ),
					$current_count,
					$max_automations
				),
			);
		}

		// Validate cadence.
		$cadence_type  = sanitize_key( $params['cadence_type'] ?? 'once' );
		$cadence_value = absint( $params['cadence_value'] ?? 0 );
		if ( ! PressArk_Automation_Recurrence::is_valid_cadence( $cadence_type ) ) {
			return array( 'success' => false, 'message' => __( 'Invalid cadence type.', 'pressark' ) );
		}

		// Enforce minimum automation interval for the tier.
		$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
		$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $cadence_type, $cadence_value );
		if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: minimum required time interval between automation runs */
					__( 'Your plan requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
					human_time_diff( 0, $min_interval )
				),
			);
		}

		$prompt = $params['prompt'] ?? '';
		if ( empty( $prompt ) ) {
			return array( 'success' => false, 'message' => __( 'Prompt is required.', 'pressark' ) );
		}

		$first_run_at = $params['first_run_at'] ?? current_time( 'mysql', true );
		$timezone     = $params['timezone'] ?? wp_timezone_string();

		$automation_id = $store->create( array(
			'user_id'              => $user_id,
			'name'                 => $params['name'] ?? mb_substr( $prompt, 0, 80 ),
			'prompt'               => $prompt,
			'timezone'             => $timezone,
			'cadence_type'         => $cadence_type,
			'cadence_value'        => absint( $params['cadence_value'] ?? 0 ),
			'first_run_at'         => $first_run_at,
			'approval_policy'      => sanitize_key( $params['approval_policy'] ?? PressArk_Automation_Policy::default_for_tier( $tier ) ),
			'notification_channel' => 'telegram',
			'notification_target'  => PressArk_Notification_Manager::get_user_telegram_id( $user_id ),
		) );

		// Schedule the first wake-up.
		PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $first_run_at );

		return array(
			'success'       => true,
			'automation_id' => $automation_id,
			'message'       => sprintf(
				/* translators: 1: automation name, 2: first scheduled run date/time */
				__( 'Automation "%1$s" created. First run: %2$s.', 'pressark' ),
				$params['name'] ?? __( 'Scheduled Prompt', 'pressark' ),
				$first_run_at
			),
		);
	}

	/**
	 * Update an existing automation.
	 */
	public function update_automation( array $params ): array {
		$automation_id = $params['automation_id'] ?? '';
		if ( empty( $automation_id ) ) {
			return array( 'success' => false, 'message' => __( 'automation_id is required.', 'pressark' ) );
		}

		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== get_current_user_id() ) {
			return array( 'success' => false, 'message' => __( 'Automation not found or access denied.', 'pressark' ) );
		}

		$update = array();
		if ( isset( $params['name'] ) ) $update['name'] = sanitize_text_field( $params['name'] );
		if ( isset( $params['prompt'] ) ) $update['prompt'] = wp_kses_post( $params['prompt'] );
		if ( isset( $params['cadence_type'] ) && PressArk_Automation_Recurrence::is_valid_cadence( $params['cadence_type'] ) ) {
			$update['cadence_type'] = sanitize_key( $params['cadence_type'] );
		}
		if ( isset( $params['cadence_value'] ) ) $update['cadence_value'] = absint( $params['cadence_value'] );
		if ( isset( $params['timezone'] ) ) $update['timezone'] = sanitize_text_field( $params['timezone'] );

		if ( ! empty( $update['cadence_type'] ) || ! empty( $update['cadence_value'] ) ) {
			$ct = $update['cadence_type'] ?? $automation['cadence_type'];
			$cv = $update['cadence_value'] ?? $automation['cadence_value'];
			$tz = $update['timezone'] ?? $automation['timezone'];

			// Enforce minimum automation interval for the tier.
			$tier            = ( new PressArk_License() )->get_tier();
			$min_interval    = PressArk_Entitlements::tier_value( $tier, 'min_automation_interval' ) ?? 0;
			$cadence_seconds = PressArk_Automation_Recurrence::cadence_seconds( $ct, $cv );
			if ( $min_interval > 0 && $cadence_seconds < $min_interval ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: minimum required time interval between automation runs */
						__( 'Your plan requires at least %s between automation runs. Choose a longer interval.', 'pressark' ),
						human_time_diff( 0, $min_interval )
					),
				);
			}

			$next = PressArk_Automation_Recurrence::compute_next(
				$ct, $cv, $tz,
				$automation['first_run_at'],
				$automation['last_success_at'] ?? null
			);
			$update['next_run_at'] = $next;
		}

		$store->update( $automation_id, $update );

		return array( 'success' => true, 'message' => __( 'Automation updated.', 'pressark' ) );
	}

	/**
	 * Pause or resume an automation.
	 */
	public function toggle_automation( array $params ): array {
		$automation_id = $params['automation_id'] ?? '';
		$action        = $params['action'] ?? '';

		$store      = new PressArk_Automation_Store();
		$automation = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== get_current_user_id() ) {
			return array( 'success' => false, 'message' => __( 'Automation not found.', 'pressark' ) );
		}

		if ( 'pause' === $action ) {
			$store->update( $automation_id, array( 'status' => 'paused' ) );
			return array( 'success' => true, 'message' => __( 'Automation paused.', 'pressark' ) );
		}

		if ( 'resume' === $action ) {
			$next = PressArk_Automation_Dispatcher::next_run_for_resume( $automation, current_time( 'mysql', true ) );
			$store->update( $automation_id, array(
				'status'         => 'active',
				'next_run_at'    => $next,
				'failure_streak' => 0,
				'last_error'     => null,
			) );

			if ( $next ) {
				PressArk_Automation_Dispatcher::schedule_next_wake( $automation_id, $next );
			}

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: next scheduled automation run date/time, or "none" */
					__( 'Automation resumed. Next run: %s.', 'pressark' ),
					$next ?: __( 'none', 'pressark' )
				),
			);
		}

		return array( 'success' => false, 'message' => __( 'Invalid action. Use "pause" or "resume".', 'pressark' ) );
	}

	/**
	 * Run an automation immediately.
	 */
	public function run_automation_now( array $params ): array {
		$automation_id = $params['automation_id'] ?? '';
		return PressArk_Automation_Dispatcher::run_now( $automation_id, get_current_user_id() );
	}

	/**
	 * Delete an automation.
	 */
	public function delete_automation( array $params ): array {
		$automation_id = $params['automation_id'] ?? '';
		$store         = new PressArk_Automation_Store();
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== get_current_user_id() ) {
			return array( 'success' => false, 'message' => __( 'Automation not found.', 'pressark' ) );
		}

		$store->delete( $automation_id );

		return array( 'success' => true, 'message' => __( 'Automation deleted.', 'pressark' ) );
	}

	/**
	 * Inspect automation history / last result.
	 */
	public function inspect_automation( array $params ): array {
		$automation_id = $params['automation_id'] ?? '';
		$store         = new PressArk_Automation_Store();
		$automation    = $store->get( $automation_id );

		if ( ! $automation || (int) $automation['user_id'] !== get_current_user_id() ) {
			return array( 'success' => false, 'message' => __( 'Automation not found.', 'pressark' ) );
		}

		$info = array(
			'automation_id'  => $automation['automation_id'],
			'name'           => $automation['name'],
			'prompt'         => mb_substr( $automation['prompt'], 0, 500 ),
			'status'         => $automation['status'],
			'cadence'        => PressArk_Automation_Recurrence::label( $automation['cadence_type'], $automation['cadence_value'] ),
			'timezone'       => $automation['timezone'],
			'next_run_at'    => $automation['next_run_at'],
			'last_success_at' => $automation['last_success_at'],
			'last_failure_at' => $automation['last_failure_at'],
			'last_error'     => $automation['last_error'],
			'failure_streak' => $automation['failure_streak'],
			'created_at'     => $automation['created_at'],
		);

		// Include last run result if available.
		if ( ! empty( $automation['last_run_id'] ) ) {
			$run_store = new PressArk_Run_Store();
			$run       = $run_store->get( $automation['last_run_id'] );
			if ( $run ) {
				$info['last_run'] = array(
					'run_id'  => $run['run_id'],
					'status'  => $run['status'],
					'route'   => $run['route'],
					'message' => mb_substr( $run['message'], 0, 200 ),
				);
			}
		}

		$info['execution_hints'] = $automation['execution_hints'];

		return array( 'success' => true, 'automation' => $info );
	}
}
