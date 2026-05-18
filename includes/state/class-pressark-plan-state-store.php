<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plan and execution-scoped checkpoint state.
 *
 * Stage 3 compatibility note:
 * This store keeps the legacy flat execution/plan keys on export, but treats
 * them as one typed domain behind the checkpoint facade.
 */
class PressArk_Plan_State_Store {

	private array  $selected_target = array();
	private string $workflow_stage  = '';
	private array  $execution       = array();
	private array  $replay_state    = array();
	private array  $plan_state      = array();
	private array  $plan_steps      = array();

	public static function from_checkpoint_array( array $data ): self {
		$store                  = new self();
		$store->selected_target = self::sanitize_selected_target( $data['selected_target'] ?? array() );
		$store->workflow_stage  = self::sanitize_stage( $data['workflow_stage'] ?? '' );
		$store->execution       = PressArk_Execution_Ledger::sanitize( $data['execution'] ?? array() );
		$store->replay_state    = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_state( $data['replay_state'] ?? array() )
			: array();
		$store->plan_state      = self::sanitize_plan_state( $data['plan_state'] ?? array() );
		$store->plan_steps      = self::sanitize_plan_steps( $data['plan_steps'] ?? array(), $store->plan_state );

		if ( PressArk_Execution_Ledger::is_empty( $store->execution ) ) {
			$store->execution = array();
		}

		return $store;
	}

	public function to_checkpoint_array(): array {
		return array(
			'selected_target' => $this->selected_target,
			'workflow_stage'  => $this->workflow_stage,
			'execution'       => $this->execution,
			'replay_state'    => $this->replay_state,
			'plan_state'      => $this->plan_state,
			'plan_steps'      => $this->plan_steps,
		);
	}

	public function is_empty(): bool {
		return empty( $this->selected_target )
			&& empty( $this->workflow_stage )
			&& PressArk_Execution_Ledger::is_empty( $this->execution )
			&& empty( $this->replay_state )
			&& self::plan_state_is_empty( $this->plan_state );
	}

	public function set_execution( array $execution ): void {
		$this->execution = PressArk_Execution_Ledger::sanitize( $execution );
	}

	public function get_execution(): array {
		return $this->execution;
	}

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

	public function set_replay_state( array $state ): void {
		$this->replay_state = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_state( $state )
			: array();
	}

	public function get_replay_state(): array {
		return $this->replay_state;
	}

	public function set_replay_messages( array $messages ): void {
		$state               = $this->replay_state;
		$state['messages']   = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_messages( $messages )
			: array();
		$state['updated_at'] = gmdate( 'c' );
		$this->set_replay_state( $state );
	}

	public function get_replay_messages(): array {
		return (array) ( $this->replay_state['messages'] ?? array() );
	}

	public function merge_replay_replacements( array $entries ): void {
		$state                        = $this->replay_state;
		$state['replacement_journal'] = class_exists( 'PressArk_Replay_Integrity' )
			? PressArk_Replay_Integrity::sanitize_replacement_journal(
				array_merge(
					(array) ( $state['replacement_journal'] ?? array() ),
					$entries
				)
			)
			: array();
		$state['updated_at']          = gmdate( 'c' );
		$this->set_replay_state( $state );
	}

	public function get_replay_replacements(): array {
		return (array) ( $this->replay_state['replacement_journal'] ?? array() );
	}

	public function add_replay_event( array $event ): void {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return;
		}

		$event = PressArk_Replay_Integrity::sanitize_event( $event );
		if ( empty( $event['type'] ) ) {
			return;
		}

		$state           = $this->replay_state;
		$events          = (array) ( $state['events'] ?? array() );
		$events[]        = $event;
		$state['events'] = array_slice( $events, -16 );
		$state['updated_at'] = gmdate( 'c' );
		$this->set_replay_state( $state );
	}

	public function set_last_replay_resume( array $resume ): void {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return;
		}

		$resume = PressArk_Replay_Integrity::sanitize_event( $resume, 'resume' );
		if ( empty( $resume['type'] ) ) {
			return;
		}

		$state                = $this->replay_state;
		$state['last_resume'] = $resume;
		$state['updated_at']  = gmdate( 'c' );
		$this->set_replay_state( $state );
	}

	public function get_replay_sidecar(): array {
		if ( ! class_exists( 'PressArk_Replay_Integrity' ) ) {
			return array();
		}

		return PressArk_Replay_Integrity::debug_sidecar( $this->replay_state );
	}

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

	public function set_plan_text( string $text ): void {
		$this->plan_state['plan_text'] = sanitize_textarea_field( mb_substr( $text, 0, 4000 ) );
	}

	public function get_plan_text(): string {
		return (string) ( $this->plan_state['plan_text'] ?? '' );
	}

	public function get_plan_state(): array {
		return $this->plan_state;
	}

	public function replace_plan_snapshot( array $plan_state, array $plan_steps ): void {
		$this->plan_state = self::sanitize_plan_state( $plan_state );
		$this->plan_steps = self::sanitize_plan_steps( $plan_steps, $this->plan_state );
	}

	public function get_plan_phase(): string {
		return (string) ( $this->plan_state['phase'] ?? '' );
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

			$status    = sanitize_key( (string) ( $step['status'] ?? 'pending' ) );
			$summary[] = sprintf( '%d[%s] %s', $index + 1, $status, $content );
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

			$steps[ $index ]['apply_succeeded']   = ! empty( $result['success'] );
			$steps[ $index ]['applied_tool_name'] = $tool_name;
			$steps[ $index ]['updated_at']        = gmdate( 'c' );

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
			'screen'          => sanitize_text_field( (string) ( $context['screen'] ?? '' ) ),
			'post_id'         => absint( $context['post_id'] ?? 0 ),
			'deep_mode'       => ! empty( $context['deep_mode'] ),
			'loaded_groups'   => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $context['loaded_groups'] ?? array() ) ) ) ),
			'chat_id'         => absint( $context['chat_id'] ?? 0 ),
			'message'         => sanitize_textarea_field( mb_substr( (string) ( $context['message'] ?? '' ), 0, 4000 ) ),
			'execute_message' => sanitize_textarea_field( mb_substr( (string) ( $context['execute_message'] ?? '' ), 0, 4000 ) ),
			'conversation'    => $conversation,
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
		$artifact                           = $this->get_plan_artifact();
		$this->plan_state['revision_note']  = $revision_note;
		$this->plan_state['next_version']   = max( 1, absint( $artifact['version'] ?? 0 ) + 1 );
		$this->plan_state['status']         = 'revising';
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

	public function is_exploring(): bool {
		return 'exploring' === ( $this->plan_state['phase'] ?? '' );
	}

	public function is_plan_executing(): bool {
		return 'executing' === ( $this->plan_state['phase'] ?? '' );
	}

	public function has_active_plan_gate(): bool {
		return in_array( $this->get_plan_phase(), array( 'exploring', 'planning' ), true )
			&& ! empty( $this->get_plan_artifact() );
	}

	public function clear_plan_state(): void {
		$this->archive_current_plan_artifact( 'cleared' );
		$history          = $this->get_plan_history();
		$this->plan_state = empty( $history ) ? array() : array( 'history' => $history );
		$this->plan_steps = array();
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
			'phase'            => $phase,
			'plan_text'        => sanitize_textarea_field( mb_substr( (string) ( $raw['plan_text'] ?? '' ), 0, 4000 ) ),
			'entered_at'       => sanitize_text_field( $raw['entered_at'] ?? '' ),
			'approved_at'      => sanitize_text_field( $raw['approved_at'] ?? '' ),
			'status'           => sanitize_key( (string) ( $raw['status'] ?? '' ) ),
			'approval_level'   => sanitize_key( (string) ( $raw['approval_level'] ?? '' ) ),
			'current_artifact' => $artifact,
			'history'          => $history,
			'policy'           => is_array( $raw['policy'] ?? null ) ? array(
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
			'request_context'  => is_array( $raw['request_context'] ?? null ) ? array(
				'screen'          => sanitize_text_field( (string) ( $raw['request_context']['screen'] ?? '' ) ),
				'post_id'         => absint( $raw['request_context']['post_id'] ?? 0 ),
				'deep_mode'       => ! empty( $raw['request_context']['deep_mode'] ),
				'loaded_groups'   => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $raw['request_context']['loaded_groups'] ?? array() ) ) ) ),
				'chat_id'         => absint( $raw['request_context']['chat_id'] ?? 0 ),
				'message'         => sanitize_textarea_field( mb_substr( (string) ( $raw['request_context']['message'] ?? '' ), 0, 4000 ) ),
				'execute_message' => sanitize_textarea_field( mb_substr( (string) ( $raw['request_context']['execute_message'] ?? '' ), 0, 4000 ) ),
				'conversation'    => array_values(
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
			'revision_note'    => sanitize_text_field( (string) ( $raw['revision_note'] ?? '' ) ),
			'next_version'     => max( 0, absint( $raw['next_version'] ?? 0 ) ),
		) );
	}

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
			$kind     = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			$status   = self::sanitize_plan_step_status( (string) ( $row['status'] ?? 'pending' ) );
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

			$status   = self::sanitize_plan_step_status( (string) ( $step['status'] ?? 'pending' ) );
			$steps[] = array(
				'id'                => sanitize_key( (string) ( $step['id'] ?? '' ) ),
				'content'           => $content,
				'activeForm'        => sanitize_text_field( (string) ( $step['activeForm'] ?? $content ) ),
				'status'            => $status,
				'post_id'           => absint( $step['post_id'] ?? 0 ),
				'tool_name'         => sanitize_key( (string) ( $step['tool_name'] ?? '' ) ),
				'preview_required'  => ! empty( $step['preview_required'] ),
				'apply_succeeded'   => ! empty( $step['apply_succeeded'] ),
				'applied_tool_name' => sanitize_key( (string) ( $step['applied_tool_name'] ?? '' ) ),
				'updated_at'        => sanitize_text_field( (string) ( $step['updated_at'] ?? '' ) ),
				'kind'              => sanitize_key( (string) ( $step['kind'] ?? '' ) ),
				'group'             => sanitize_key( (string) ( $step['group'] ?? '' ) ),
				'metadata'          => is_array( $step['metadata'] ?? null ) ? array_map( 'sanitize_text_field', (array) $step['metadata'] ) : array(),
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
}
