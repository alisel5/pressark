<?php
/**
 * PressArk structured plan artifacts.
 *
 * @package PressArk
 * @since   5.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Plan_Artifact {

	private const MAX_STEPS = 12;

	/**
	 * Build a structured artifact from planner output.
	 *
	 * @param array $plan    Planner output.
	 * @param array $context Build context.
	 * @return array<string,mixed>
	 */
	public static function build( array $plan, array $context = array() ): array {
		$prior           = self::sanitize( $context['prior_artifact'] ?? array() );
		$planner_artifact = is_array( $plan['artifact'] ?? null ) ? $plan['artifact'] : array();
		$approval_level  = in_array( (string) ( $context['approval_level'] ?? '' ), array( 'soft', 'hard' ), true )
			? (string) $context['approval_level']
			: ( ! empty( $prior['approval_level'] ) ? (string) $prior['approval_level'] : 'soft' );
		$execute_message = sanitize_textarea_field(
			mb_substr(
				(string) (
					$context['execute_message']
					?? $prior['execute_message']
					?? $context['request_message']
					?? ''
				),
				0,
				4000
			)
		);
		$summary = sanitize_text_field(
			(string) (
				$context['request_summary']
				?? $prior['request_summary']
				?? self::compact_text( $execute_message ?: (string) ( $context['request_message'] ?? '' ), 180 )
			)
		);

		$artifact = array(
			'plan_id'            => sanitize_text_field( (string) ( $context['plan_id'] ?? $prior['plan_id'] ?? self::generate_plan_id() ) ),
			'version'            => max( 1, absint( $context['version'] ?? $prior['version'] ?? 1 ) ),
			'run_id'             => sanitize_text_field( (string) ( $context['run_id'] ?? '' ) ),
			'request_summary'    => $summary,
			'execute_message'    => $execute_message,
			'approval_level'     => $approval_level,
			'assumptions'        => self::sanitize_text_list( $planner_artifact['assumptions'] ?? $context['assumptions'] ?? array() ),
			'constraints'        => self::sanitize_text_list( $planner_artifact['constraints'] ?? $context['constraints'] ?? array() ),
			'affected_entities'  => self::sanitize_entity_list( $planner_artifact['affected_entities'] ?? $context['affected_entities'] ?? array() ),
			'risks'              => self::sanitize_text_list( $planner_artifact['risks'] ?? $context['risks'] ?? array() ),
			'verification_steps' => self::sanitize_text_list( $planner_artifact['verification_steps'] ?? $context['verification_steps'] ?? array() ),
			'steps'              => self::normalize_planner_steps(
				$planner_artifact['steps'] ?? array(),
				(array) ( $plan['steps'] ?? array() ),
				(array) ( $plan['groups'] ?? array() ),
				$approval_level
			),
		);

		if ( empty( $artifact['verification_steps'] ) ) {
			$artifact['verification_steps'] = self::derive_verification_steps( $artifact['steps'] );
		}

		return self::sanitize( $artifact );
	}

	/**
	 * Ensure an artifact exists, synthesizing one from legacy plan text if
	 * needed.
	 *
	 * @param array|string $artifact     Existing artifact or raw plan text.
	 * @param array        $legacy_steps Legacy plan steps.
	 * @param array        $context      Build context.
	 * @return array<string,mixed>
	 */
	public static function ensure( $artifact, array $legacy_steps = array(), array $context = array() ): array {
		$clean = self::sanitize( is_array( $artifact ) ? $artifact : array() );
		if ( ! self::is_empty( $clean ) ) {
			return $clean;
		}

		$plan_markdown = is_string( $artifact ) ? $artifact : (string) ( $context['plan_markdown'] ?? '' );

		return self::synthesize_from_legacy( $plan_markdown, $legacy_steps, $context );
	}

	/**
	 * Synthesize a minimal artifact from legacy markdown and step rows.
	 *
	 * @param string $plan_markdown Legacy markdown.
	 * @param array  $legacy_steps  Legacy steps.
	 * @param array  $context       Build context.
	 * @return array<string,mixed>
	 */
	public static function synthesize_from_legacy( string $plan_markdown, array $legacy_steps = array(), array $context = array() ): array {
		$step_texts = array();

		foreach ( $legacy_steps as $step ) {
			if ( is_array( $step ) ) {
				$text = sanitize_text_field( (string) ( $step['text'] ?? $step['title'] ?? $step['label'] ?? '' ) );
			} else {
				$text = sanitize_text_field( (string) $step );
			}
			if ( '' !== $text ) {
				$step_texts[] = $text;
			}
		}

		if ( empty( $step_texts ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $plan_markdown ) as $line ) {
				$line = trim( wp_strip_all_tags( (string) $line ) );
				if ( '' === $line ) {
					continue;
				}
				if ( preg_match( '/^(?:\d+[\.\)]|[-*])\s+(.+)$/', $line, $matches ) ) {
					$step_texts[] = sanitize_text_field( (string) $matches[1] );
				}
			}
		}

		return self::build(
			array(
				'steps'  => $step_texts,
				'groups' => is_array( $context['groups'] ?? null ) ? $context['groups'] : array(),
			),
			$context
		);
	}

	/**
	 * Sanitize a stored artifact.
	 *
	 * @param mixed $raw Raw artifact.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$approval_level = in_array( (string) ( $raw['approval_level'] ?? '' ), array( 'soft', 'hard' ), true )
			? (string) $raw['approval_level']
			: 'soft';
		$steps = array();

		foreach ( array_slice( (array) ( $raw['steps'] ?? array() ), 0, self::MAX_STEPS ) as $index => $step ) {
			if ( is_string( $step ) ) {
				$step = array( 'title' => $step );
			}
			if ( ! is_array( $step ) ) {
				continue;
			}

			$id          = sanitize_key( (string) ( $step['id'] ?? 'step_' . ( $index + 1 ) ) );
			$title       = sanitize_text_field( (string) ( $step['title'] ?? $step['text'] ?? $step['label'] ?? '' ) );
			$description = sanitize_textarea_field( mb_substr( (string) ( $step['description'] ?? $title ), 0, 400 ) );
			if ( '' === $id || '' === $title ) {
				continue;
			}

			$depends_on = array();
			foreach ( (array) ( $step['depends_on'] ?? array() ) as $dependency ) {
				$dependency = sanitize_key( (string) $dependency );
				if ( '' !== $dependency ) {
					$depends_on[] = $dependency;
				}
			}

			$steps[] = array(
				'id'            => $id,
				'title'         => $title,
				'description'   => $description,
				'kind'          => self::sanitize_kind( (string) ( $step['kind'] ?? '' ), $title ),
				'group'         => self::sanitize_group( (string) ( $step['group'] ?? '' ), $step['metadata'] ?? array() ),
				'depends_on'    => array_values( array_unique( $depends_on ) ),
				'status'        => self::sanitize_status( (string) ( $step['status'] ?? 'pending' ) ),
				'metadata'      => self::sanitize_metadata( $step['metadata'] ?? array() ),
				'verification'  => sanitize_text_field( (string) ( $step['verification'] ?? '' ) ),
				'rollback_hint' => sanitize_text_field( (string) ( $step['rollback_hint'] ?? '' ) ),
			);
		}

		return array(
			'plan_id'            => sanitize_text_field( (string) ( $raw['plan_id'] ?? '' ) ),
			'version'            => max( 1, absint( $raw['version'] ?? 1 ) ),
			'run_id'             => sanitize_text_field( (string) ( $raw['run_id'] ?? '' ) ),
			'request_summary'    => sanitize_text_field( (string) ( $raw['request_summary'] ?? '' ) ),
			'execute_message'    => sanitize_textarea_field( mb_substr( (string) ( $raw['execute_message'] ?? '' ), 0, 4000 ) ),
			'approval_level'     => $approval_level,
			'assumptions'        => self::sanitize_text_list( $raw['assumptions'] ?? array() ),
			'constraints'        => self::sanitize_text_list( $raw['constraints'] ?? array() ),
			'affected_entities'  => self::sanitize_entity_list( $raw['affected_entities'] ?? array() ),
			'risks'              => self::sanitize_text_list( $raw['risks'] ?? array() ),
			'verification_steps' => self::sanitize_text_list( $raw['verification_steps'] ?? array() ),
			'steps'              => $steps,
		);
	}

	/**
	 * Convert an artifact into legacy markdown.
	 */
	public static function to_markdown( array $artifact ): string {
		$artifact = self::sanitize( $artifact );
		$lines    = array();

		foreach ( $artifact['steps'] as $index => $step ) {
			$title = sanitize_text_field( (string) ( $step['title'] ?? '' ) );
			if ( '' !== $title ) {
				$lines[] = sprintf( '%d. %s', $index + 1, $title );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert an artifact into the legacy plan_steps row format.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function to_plan_steps( array $artifact ): array {
		$artifact = self::sanitize( $artifact );
		$rows     = array();

		foreach ( $artifact['steps'] as $index => $step ) {
			$text = sanitize_text_field( (string) ( $step['title'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$metadata = is_array( $step['metadata'] ?? null ) ? (array) $step['metadata'] : array();
			$rows[] = array(
				'index'             => $index + 1,
				'text'              => $text,
				'content'           => $text,
				'activeForm'        => sanitize_text_field( (string) ( $metadata['active_form'] ?? $metadata['activeForm'] ?? $text ) ),
				'status'            => self::step_row_status( (string) ( $step['status'] ?? 'pending' ) ),
				'id'                => sanitize_key( (string) ( $step['id'] ?? '' ) ),
				'kind'              => sanitize_key( (string) ( $step['kind'] ?? '' ) ),
				'group'             => sanitize_key( (string) ( $step['group'] ?? '' ) ),
				'post_id'           => absint( $metadata['post_id'] ?? 0 ),
				'tool_name'         => sanitize_key( (string) ( $metadata['tool_name'] ?? '' ) ),
				'preview_required'  => ! empty( $metadata['preview_required'] ),
				'apply_succeeded'   => ! empty( $metadata['apply_succeeded'] ),
				'applied_tool_name' => sanitize_key( (string) ( $metadata['applied_tool_name'] ?? '' ) ),
				'updated_at'        => sanitize_text_field( (string) ( $metadata['updated_at'] ?? '' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Build or refresh a structured artifact from model-emitted update_plan rows.
	 *
	 * @param array $steps   Flat update_plan rows.
	 * @param array $context Build context.
	 * @return array<string,mixed>
	 */
	public static function from_plan_steps( array $steps, array $context = array() ): array {
		$prior          = self::sanitize( $context['prior_artifact'] ?? array() );
		$approval_level = in_array( (string) ( $context['approval_level'] ?? '' ), array( 'soft', 'hard' ), true )
			? (string) $context['approval_level']
			: ( ! empty( $prior['approval_level'] ) ? (string) $prior['approval_level'] : 'hard' );
		$execute_message = sanitize_textarea_field(
			mb_substr(
				(string) (
					$context['execute_message']
					?? $prior['execute_message']
					?? $context['request_message']
					?? ''
				),
				0,
				4000
			)
		);
		$request_summary = sanitize_text_field(
			(string) (
				$context['request_summary']
				?? $prior['request_summary']
				?? self::compact_text( $execute_message, 180 )
			)
		);

		$artifact_steps = array();
		$used_ids       = array();
		foreach ( array_slice( $steps, 0, self::MAX_STEPS ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $step['content'] ?? $step['text'] ?? $step['title'] ?? $step['label'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
			$base_id   = sanitize_key( (string) ( $step['id'] ?? '' ) );
			if ( '' === $base_id ) {
				$base_id = '' !== $tool_name ? $tool_name . '_' . ( $index + 1 ) : 'step_' . ( $index + 1 );
			}
			$step_id = self::dedupe_step_id( $base_id, $used_ids );

			$metadata = self::sanitize_metadata( $step['metadata'] ?? array() );
			$metadata = array_filter(
				array_merge(
					$metadata,
					array(
						'tool_name'         => $tool_name,
						'post_id'           => absint( $step['post_id'] ?? 0 ),
						'preview_required'  => ! empty( $step['preview_required'] ),
						'apply_succeeded'   => ! empty( $step['apply_succeeded'] ),
						'applied_tool_name' => sanitize_key( (string) ( $step['applied_tool_name'] ?? '' ) ),
						'updated_at'        => sanitize_text_field( (string) ( $step['updated_at'] ?? '' ) ),
						'active_form'       => sanitize_text_field( (string) ( $step['activeForm'] ?? $title ) ),
					)
				),
				static function ( $value ): bool {
					return is_bool( $value ) || is_int( $value ) || ( is_string( $value ) && '' !== $value );
				}
			);

			$depends_on = array();
			foreach ( (array) ( $step['depends_on'] ?? array() ) as $dependency ) {
				$dependency = sanitize_key( (string) $dependency );
				if ( '' !== $dependency ) {
					$depends_on[] = $dependency;
				}
			}
			if ( empty( $depends_on ) && $index > 0 && ! empty( $artifact_steps[ $index - 1 ]['id'] ) ) {
				$depends_on[] = sanitize_key( (string) $artifact_steps[ $index - 1 ]['id'] );
			}

			$group = sanitize_key( (string) ( $step['group'] ?? '' ) );
			if ( '' === $group && '' !== $tool_name && class_exists( 'PressArk_Operation_Registry' ) ) {
				$group = sanitize_key( (string) PressArk_Operation_Registry::get_group( $tool_name ) );
			}

			$kind = sanitize_key( (string) ( $step['kind'] ?? '' ) );
			if ( '' === $kind && ! empty( $metadata['preview_required'] ) ) {
				$kind = 'preview';
			}

			$artifact_steps[] = array(
				'id'            => $step_id,
				'title'         => $title,
				'description'   => sanitize_textarea_field( mb_substr( (string) ( $step['description'] ?? $title ), 0, 400 ) ),
				'kind'          => self::sanitize_kind( $kind, $title ),
				'group'         => self::sanitize_group( $group, $metadata ),
				'depends_on'    => array_values( array_unique( array_filter( $depends_on ) ) ),
				'status'        => self::sanitize_status( (string) ( $step['status'] ?? 'pending' ) ),
				'metadata'      => $metadata,
				'verification'  => sanitize_text_field( (string) ( $step['verification'] ?? '' ) ),
				'rollback_hint' => sanitize_text_field( (string) ( $step['rollback_hint'] ?? self::default_rollback_hint( self::sanitize_kind( $kind, $title ), $approval_level ) ) ),
			);
		}

		if ( empty( $artifact_steps ) ) {
			return array();
		}

		$artifact = array(
			'plan_id'            => sanitize_text_field( (string) ( $context['plan_id'] ?? $prior['plan_id'] ?? self::generate_plan_id() ) ),
			'version'            => max( 1, absint( $context['version'] ?? $prior['version'] ?? 1 ) ),
			'run_id'             => sanitize_text_field( (string) ( $context['run_id'] ?? $prior['run_id'] ?? '' ) ),
			'request_summary'    => $request_summary,
			'execute_message'    => $execute_message,
			'approval_level'     => $approval_level,
			'assumptions'        => self::sanitize_text_list( $prior['assumptions'] ?? array() ),
			'constraints'        => self::sanitize_text_list( $prior['constraints'] ?? array() ),
			'affected_entities'  => self::sanitize_entity_list( $prior['affected_entities'] ?? array() ),
			'risks'              => self::sanitize_text_list( $prior['risks'] ?? array() ),
			'verification_steps' => self::sanitize_text_list( $prior['verification_steps'] ?? array() ),
			'steps'              => $artifact_steps,
		);

		if ( empty( $artifact['verification_steps'] ) ) {
			$artifact['verification_steps'] = self::derive_verification_steps( $artifact_steps );
		}

		return self::sanitize( $artifact );
	}

	/**
	 * Seed or refresh an execution ledger from an approved artifact.
	 *
	 * @param array $artifact Structured artifact.
	 * @param array $ledger   Existing ledger.
	 * @return array<string,mixed>
	 */
	public static function seed_execution_ledger( array $artifact, array $ledger = array() ): array {
		$artifact = self::sanitize( $artifact );
		$ledger   = is_array( $ledger ) ? $ledger : array();
		if ( empty( $artifact['steps'] ) ) {
			return $ledger;
		}

		$tasks = array();
		foreach ( $artifact['steps'] as $step ) {
			$tasks[] = array(
				'key'        => sanitize_key( (string) ( $step['id'] ?? '' ) ),
				'label'      => sanitize_text_field( (string) ( $step['title'] ?? '' ) ),
				'status'     => self::sanitize_status( (string) ( $step['status'] ?? 'pending' ) ),
				'evidence'   => '',
				'depends_on' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $step['depends_on'] ?? array() ) ) ) ),
				'metadata'   => array_merge(
					array(
						'kind'          => sanitize_key( (string) ( $step['kind'] ?? '' ) ),
						'group'         => sanitize_key( (string) ( $step['group'] ?? '' ) ),
						'verification'  => sanitize_text_field( (string) ( $step['verification'] ?? '' ) ),
						'rollback_hint' => sanitize_text_field( (string) ( $step['rollback_hint'] ?? '' ) ),
						'plan_step_id'  => sanitize_key( (string) ( $step['id'] ?? '' ) ),
					),
					is_array( $step['metadata'] ?? null ) ? (array) $step['metadata'] : array()
				),
			);
		}

		$seeded = array(
			'source_message' => sanitize_text_field( (string) ( $artifact['execute_message'] ?? '' ) ),
			'goal_hash'      => ! empty( $artifact['execute_message'] ) ? md5( (string) $artifact['execute_message'] ) : '',
			'request_counts' => is_array( $ledger['request_counts'] ?? null ) ? $ledger['request_counts'] : array(),
			'tasks'          => $tasks,
			'receipts'       => is_array( $ledger['receipts'] ?? null ) ? $ledger['receipts'] : array(),
			'current_target' => is_array( $ledger['current_target'] ?? null ) ? $ledger['current_target'] : array(),
			'updated_at'     => gmdate( 'c' ),
		);

		return class_exists( 'PressArk_Execution_Ledger' )
			? PressArk_Execution_Ledger::sanitize( $seeded )
			: $seeded;
	}

	/**
	 * Render a compact prompt block for an approved artifact.
	 */
	public static function to_prompt_block( array $artifact ): string {
		$artifact = self::sanitize( $artifact );
		if ( self::is_empty( $artifact ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = '## Approved Plan Artifact';
		$lines[] = 'Plan ID: ' . sanitize_text_field( (string) ( $artifact['plan_id'] ?? '' ) );
		$lines[] = 'Version: ' . (int) ( $artifact['version'] ?? 1 );
		$lines[] = 'Approval level: ' . sanitize_key( (string) ( $artifact['approval_level'] ?? 'soft' ) );
		if ( ! empty( $artifact['request_summary'] ) ) {
			$lines[] = 'Request summary: ' . sanitize_text_field( (string) $artifact['request_summary'] );
		}
		// v5.6.8 (2026-05-12): Include the live step status in each artifact
		// line. `to_plan_steps()` already collects status from the step row;
		// emitting it lets us drop the duplicate "Current plan: …" prose
		// block that build_plan_prompt_summary_block produced (these were two
		// representations of the same data in the same system message, with
		// real risk of divergence after a sync_step_statuses race). One source
		// of truth, model still sees [IN PROGRESS] / [COMPLETED] markers.
		foreach ( self::to_plan_steps( $artifact ) as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
			$status_label = 'in_progress' === $status ? 'IN PROGRESS' : strtoupper( str_replace( '_', ' ', $status ) );
			$lines[] = sprintf(
				'%d. [%s/%s] %s [%s]',
				(int) ( $row['index'] ?? 0 ),
				sanitize_key( (string) ( $row['kind'] ?? 'analyze' ) ),
				sanitize_key( (string) ( $row['group'] ?? 'general' ) ),
				sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
				$status_label
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Mirror execution-ledger task statuses back into the artifact step list.
	 *
	 * @param array $artifact Structured artifact.
	 * @param array $ledger   Execution ledger.
	 * @return array<string,mixed>
	 */
	public static function sync_step_statuses( array $artifact, array $ledger ): array {
		$artifact = self::sanitize( $artifact );
		if ( empty( $artifact['steps'] ) ) {
			return $artifact;
		}

		$task_map = array();
		if ( class_exists( 'PressArk_Execution_Ledger' ) ) {
			$ledger = PressArk_Execution_Ledger::sanitize( $ledger );
		}

		foreach ( (array) ( $ledger['tasks'] ?? array() ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$key = sanitize_key( (string) ( $task['key'] ?? '' ) );
			if ( '' !== $key ) {
				$task_map[ $key ] = $task;
			}
		}

		foreach ( $artifact['steps'] as &$step ) {
			$step_id = sanitize_key( (string) ( $step['id'] ?? '' ) );
			if ( '' === $step_id || empty( $task_map[ $step_id ] ) ) {
				continue;
			}

			$task           = $task_map[ $step_id ];
			$step['status'] = self::sanitize_status( (string) ( $task['status'] ?? $step['status'] ?? 'pending' ) );
			if ( ! empty( $task['metadata'] ) && is_array( $task['metadata'] ) ) {
				$step['metadata'] = array_merge(
					self::sanitize_metadata( $step['metadata'] ?? array() ),
					self::sanitize_metadata( $task['metadata'] )
				);
			}
		}
		unset( $step );

		return self::sanitize( $artifact );
	}

	/**
	 * Append dynamically discovered steps onto an existing artifact.
	 *
	 * @param array $artifact Existing artifact.
	 * @param array $steps    Additional step rows.
	 * @return array<string,mixed>
	 */
	public static function append_steps( array $artifact, array $steps ): array {
		$artifact = self::sanitize( $artifact );
		if ( empty( $artifact ) || empty( $steps ) ) {
			return $artifact;
		}

		$existing_ids = array();
		foreach ( $artifact['steps'] as $step ) {
			$step_id = sanitize_key( (string) ( $step['id'] ?? '' ) );
			if ( '' !== $step_id ) {
				$existing_ids[ $step_id ] = true;
			}
		}

		foreach ( $steps as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$step_id = sanitize_key( (string) ( $step['id'] ?? 'dynamic_step_' . ( $index + 1 ) ) );
			$title   = sanitize_text_field( (string) ( $step['title'] ?? $step['text'] ?? '' ) );
			if ( '' === $step_id || '' === $title || ! empty( $existing_ids[ $step_id ] ) ) {
				continue;
			}

			$depends_on = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						(array) ( $step['depends_on'] ?? array() )
					)
				)
			);

			$artifact['steps'][] = array(
				'id'            => $step_id,
				'title'         => $title,
				'description'   => sanitize_textarea_field( mb_substr( (string) ( $step['description'] ?? $title ), 0, 400 ) ),
				'kind'          => self::sanitize_kind( (string) ( $step['kind'] ?? '' ), $title ),
				'group'         => self::sanitize_group( (string) ( $step['group'] ?? '' ), $step['metadata'] ?? array() ),
				'depends_on'    => $depends_on,
				'status'        => empty( $depends_on ) ? 'pending' : 'blocked',
				'metadata'      => self::sanitize_metadata( $step['metadata'] ?? array() ),
				'verification'  => sanitize_text_field( (string) ( $step['verification'] ?? '' ) ),
				'rollback_hint' => sanitize_text_field( (string) ( $step['rollback_hint'] ?? '' ) ),
			);
			$existing_ids[ $step_id ] = true;
		}

		return self::sanitize( $artifact );
	}

	/**
	 * Whether the artifact is empty.
	 */
	public static function is_empty( array $artifact ): bool {
		return empty( $artifact )
			|| empty( $artifact['plan_id'] )
			|| empty( $artifact['steps'] );
	}

	/**
	 * Normalize planner steps into structured artifact steps.
	 *
	 * @param array  $structured_steps Planner-provided structured steps.
	 * @param array  $fallback_steps   Fallback plain steps.
	 * @param array  $groups           Predicted groups.
	 * @param string $approval_level   soft|hard.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_planner_steps( array $structured_steps, array $fallback_steps, array $groups, string $approval_level ): array {
		$steps = array();

		foreach ( array_slice( $structured_steps, 0, self::MAX_STEPS ) as $index => $step ) {
			if ( is_string( $step ) ) {
				$step = array( 'title' => $step );
			}
			if ( ! is_array( $step ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $step['title'] ?? $step['text'] ?? $step['label'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}

			$depends_on = array();
			foreach ( (array) ( $step['depends_on'] ?? array() ) as $dependency ) {
				$dependency = sanitize_key( (string) $dependency );
				if ( '' !== $dependency ) {
					$depends_on[] = $dependency;
				}
			}
			if ( empty( $depends_on ) && $index > 0 ) {
				$depends_on[] = sanitize_key( (string) ( $steps[ $index - 1 ]['id'] ?? '' ) );
			}

			$kind = self::sanitize_kind( (string) ( $step['kind'] ?? '' ), $title );
			$group = self::sanitize_group(
				(string) ( $step['group'] ?? '' ),
				array( 'fallback_group' => $groups[ min( $index, max( 0, count( $groups ) - 1 ) ) ] ?? ( $groups[0] ?? 'content' ) )
			);

			$steps[] = array(
				'id'            => sanitize_key( (string) ( $step['id'] ?? 'step_' . ( $index + 1 ) ) ),
				'title'         => $title,
				'description'   => sanitize_textarea_field( mb_substr( (string) ( $step['description'] ?? $title ), 0, 400 ) ),
				'kind'          => $kind,
				'group'         => $group,
				'depends_on'    => array_values( array_filter( $depends_on ) ),
				'status'        => 0 === $index ? 'pending' : ( empty( $depends_on ) ? 'pending' : 'blocked' ),
				'metadata'      => self::sanitize_metadata( $step['metadata'] ?? array() ),
				'verification'  => sanitize_text_field( (string) ( $step['verification'] ?? self::default_verification_text( $kind, $group ) ) ),
				'rollback_hint' => sanitize_text_field( (string) ( $step['rollback_hint'] ?? self::default_rollback_hint( $kind, $approval_level ) ) ),
			);
		}

		if ( ! empty( $steps ) ) {
			return $steps;
		}

		foreach ( array_slice( $fallback_steps, 0, self::MAX_STEPS ) as $index => $step ) {
			$title = sanitize_text_field( is_array( $step ) ? (string) ( $step['text'] ?? $step['title'] ?? '' ) : (string) $step );
			if ( '' === $title ) {
				continue;
			}

			$kind = self::sanitize_kind( '', $title );
			$group = self::sanitize_group( '', array( 'fallback_group' => $groups[ min( $index, max( 0, count( $groups ) - 1 ) ) ] ?? ( $groups[0] ?? 'content' ) ) );
			$depends_on = $index > 0 ? array( 'step_' . $index ) : array();

			$steps[] = array(
				'id'            => 'step_' . ( $index + 1 ),
				'title'         => $title,
				'description'   => $title,
				'kind'          => $kind,
				'group'         => $group,
				'depends_on'    => $depends_on,
				'status'        => empty( $depends_on ) ? 'pending' : 'blocked',
				'metadata'      => array(),
				'verification'  => self::default_verification_text( $kind, $group ),
				'rollback_hint' => self::default_rollback_hint( $kind, $approval_level ),
			);
		}

		return $steps;
	}

	/**
	 * Sanitize artifact text lists.
	 *
	 * @param mixed $rows Rows to sanitize.
	 * @return string[]
	 */
	private static function sanitize_text_list( $rows ): array {
		$clean = array();
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 12 ) as $row ) {
			$text = sanitize_text_field( is_array( $row ) ? (string) ( $row['text'] ?? $row['label'] ?? '' ) : (string) $row );
			if ( '' !== $text ) {
				$clean[] = $text;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize mixed affected-entity rows.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_entity_list( $rows ): array {
		$clean = array();
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 12 ) as $row ) {
			if ( is_string( $row ) ) {
				$row = array( 'label' => $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? $row['title'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$clean[] = array(
				'type'  => sanitize_key( (string) ( $row['type'] ?? '' ) ),
				'id'    => absint( $row['id'] ?? 0 ),
				'label' => $label,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize metadata bags.
	 */
	private static function sanitize_metadata( $metadata ): array {
		$clean = array();
		if ( ! is_array( $metadata ) ) {
			return $clean;
		}

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = is_scalar( $value ) ? $value : wp_json_encode( $value );
		}

		return $clean;
	}

	private static function dedupe_step_id( string $base_id, array &$used_ids ): string {
		$base_id = sanitize_key( $base_id );
		if ( '' === $base_id ) {
			$base_id = 'step';
		}

		$step_id = $base_id;
		$suffix  = 2;
		while ( isset( $used_ids[ $step_id ] ) ) {
			$step_id = $base_id . '_' . $suffix;
			$suffix++;
		}

		$used_ids[ $step_id ] = true;
		return $step_id;
	}

	/**
	 * Sanitize step kind.
	 */
	private static function sanitize_kind( string $kind, string $fallback_text = '' ): string {
		$kind = sanitize_key( $kind );
		if ( in_array( $kind, array( 'read', 'analyze', 'preview', 'confirm', 'write', 'verify' ), true ) ) {
			return $kind;
		}

		$fallback_text = strtolower( $fallback_text );
		if ( preg_match( '/\b(?:inspect|read|gather|review|check|identify)\b/', $fallback_text ) ) {
			return 'read';
		}
		if ( preg_match( '/\b(?:analy[sz]e|audit|compare|evaluate|diagnose)\b/', $fallback_text ) ) {
			return 'analyze';
		}
		if ( preg_match( '/\b(?:preview|stage|draft)\b/', $fallback_text ) ) {
			return 'preview';
		}
		if ( preg_match( '/\b(?:confirm|approve)\b/', $fallback_text ) ) {
			return 'confirm';
		}
		if ( preg_match( '/\b(?:verify|validate|test|read back)\b/', $fallback_text ) ) {
			return 'verify';
		}

		return 'write';
	}

	/**
	 * Sanitize group names.
	 */
	private static function sanitize_group( string $group, array $metadata = array() ): string {
		$group = sanitize_key( $group );
		if ( '' !== $group ) {
			return $group;
		}

		$fallback_group = sanitize_key( (string) ( $metadata['fallback_group'] ?? '' ) );
		return '' !== $fallback_group ? $fallback_group : 'content';
	}

	/**
	 * Sanitize step status.
	 */
	private static function sanitize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( 'done' === $status ) {
			$status = 'completed';
		}

		return in_array( $status, array( 'pending', 'blocked', 'in_progress', 'completed', 'verified', 'uncertain' ), true )
			? $status
			: 'pending';
	}

	/**
	 * Convert internal status values to legacy plan-card row statuses.
	 */
	private static function step_row_status( string $status ): string {
		$status = self::sanitize_status( $status );
		return match ( $status ) {
			'in_progress' => 'active',
			'verified', 'completed' => 'done',
			default => $status,
		};
	}

	/**
	 * Derive verification lines from explicit verify steps.
	 *
	 * @param array<int,array<string,mixed>> $steps Sanitized steps.
	 * @return string[]
	 */
	private static function derive_verification_steps( array $steps ): array {
		$lines = array();
		foreach ( $steps as $step ) {
			if ( 'verify' !== ( $step['kind'] ?? '' ) ) {
				continue;
			}
			$text = sanitize_text_field( (string) ( $step['verification'] ?? $step['title'] ?? '' ) );
			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Default verification guidance.
	 */
	private static function default_verification_text( string $kind, string $group ): string {
		return match ( $kind ) {
			'read', 'analyze' => 'Confirm the discovery results match the requested scope.',
			'preview'         => 'Review the staged diff before approving changes.',
			'confirm'         => 'Check the pending mutation details before confirming.',
			'verify'          => 'Use a read-back check to confirm the change is live and correct.',
			default           => 'Verify the applied ' . sanitize_text_field( $group ) . ' change with a read-back step.',
		};
	}

	/**
	 * Default rollback guidance.
	 */
	private static function default_rollback_hint( string $kind, string $approval_level ): string {
		if ( in_array( $kind, array( 'read', 'analyze', 'verify' ), true ) ) {
			return '';
		}

		return 'Re-run the relevant preview/confirm flow before reversing this ' . sanitize_text_field( $approval_level ) . '-approved step.';
	}

	/**
	 * Generate a durable plan id.
	 */
	private static function generate_plan_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return sanitize_text_field( wp_generate_uuid4() );
		}

		return 'plan_' . sanitize_text_field( uniqid( '', true ) );
	}

	/**
	 * Compact a message into a short summary.
	 */
	private static function compact_text( string $text, int $max = 180 ): string {
		$text = sanitize_text_field( trim( $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, max( 1, $max - 1 ) ) ) . '…';
	}
}
