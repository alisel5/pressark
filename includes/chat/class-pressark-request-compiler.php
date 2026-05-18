<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles incoming chat requests into a normalized orchestration payload.
 */
class PressArk_Request_Compiler {

	/**
	 * Build a normalized request payload for chat orchestration.
	 *
	 * @return array<string,mixed>|WP_REST_Response
	 */
	public function compile_chat_request( WP_REST_Request $request ): array|WP_REST_Response {
		$message       = $request->get_param( 'message' );
		$conversation  = $this->sanitize_conversation( $request->get_param( 'conversation' ) );
		$screen        = $request->get_param( 'screen' );
		$post_id       = (int) $request->get_param( 'post_id' );
		$suppress_plan = (bool) $request->get_param( 'suppress_plan' );
		$plan_execute  = (bool) $request->get_param( 'plan_execute' );

		$this->ensure_plan_mode_loaded();

		$original_message  = (string) $message;
		$execution_message = ( $suppress_plan || PressArk_Plan_Mode::message_requests_plan( $original_message ) )
			? PressArk_Plan_Mode::strip_plan_directive( $original_message )
			: $original_message;

		$tracker = new PressArk_Usage_Tracker();
		$user_id = get_current_user_id();

		$license          = new PressArk_License();
		$tier             = $license->get_tier();
		$client_deep_mode = (bool) $request->get_param( 'deep_mode' );
		$deep_mode        = $client_deep_mode && PressArk_Entitlements::can_use_feature( $tier, 'deep_mode' );

		$loaded_groups = $request->get_param( 'loaded_groups' );
		if ( ! is_array( $loaded_groups ) ) {
			$loaded_groups = array();
		}
		$valid_groups  = PressArk_Operation_Registry::group_names();
		$loaded_groups = array_values(
			array_intersect(
				array_map( 'sanitize_text_field', $loaded_groups ),
				$valid_groups
			)
		);

		$chat_id = (int) $request->get_param( 'chat_id' );
		if ( $chat_id <= 0 && ! empty( $conversation ) ) {
			$chat_history = new PressArk_Chat_History();
			$chat_title   = PressArk_Chat_History::generate_title( $execution_message );
			$created_chat = $chat_history->create_chat( $chat_title, $conversation );
			if ( $created_chat ) {
				$chat_id = (int) $created_chat;
			}
		}

		$checkpoint_data = $request->get_param( 'checkpoint' );
		if ( ! is_array( $checkpoint_data ) ) {
			$checkpoint_data = array();
		}

		$server_checkpoint = PressArk_Checkpoint::load( $chat_id, $user_id );
		if ( $server_checkpoint && ! empty( $checkpoint_data ) ) {
			$client_checkpoint = PressArk_Checkpoint::from_array( $checkpoint_data );
			$merged            = PressArk_Checkpoint::merge( $server_checkpoint, $client_checkpoint );
			$checkpoint_data   = $merged->to_array();
		} elseif ( $server_checkpoint ) {
			$checkpoint_data = $server_checkpoint->to_array();
		}

		if ( $chat_id > 0 && ! empty( $checkpoint_data ) ) {
			$run_store_preflight = new PressArk_Run_Store();
			$pending_confirm     = $run_store_preflight->get_pending_confirm_actions( $user_id, $chat_id );
			$checkpoint          = PressArk_Checkpoint::from_array( $checkpoint_data );

			if ( ! empty( $pending_confirm ) ) {
				$this->sync_checkpoint_pending_confirms( $checkpoint, $pending_confirm );
			} elseif ( $checkpoint->has_unapplied_confirms() ) {
				$checkpoint->clear_pending();
			}

			$checkpoint_data = $checkpoint->to_array();
		}

		if ( $chat_id > 0 ) {
			$chat_history    = new PressArk_Chat_History();
			$stored_chat     = $chat_history->get_chat( $chat_id );
			$server_messages = ( $stored_chat && is_array( $stored_chat['messages'] ) )
				? $stored_chat['messages']
				: array();

			if ( ! empty( $server_messages ) ) {
				$server_count = count( $server_messages );
				$client_count = count( $conversation );

				if ( $client_count > $server_count ) {
					$new_messages = array_slice( $conversation, $server_count );
					$conversation = array_merge( $server_messages, $new_messages );
				} else {
					$conversation = $server_messages;
				}
			}
		}

		$effective_message = $this->maybe_expand_pending_plan_followup( $execution_message, $checkpoint_data, $loaded_groups );
		if ( '' !== trim( $effective_message ) && $effective_message !== $execution_message ) {
			$execution_message = $effective_message;
			$original_message  = $effective_message;
		}

		if ( '__pressark_mark_onboarded__' === $message ) {
			update_user_meta( $user_id, 'pressark_onboarded', '1' );
			return new WP_REST_Response(
				array(
					'reply'             => '',
					'actions_performed' => array(),
					'pending_actions'   => array(),
					'usage'             => $tracker->get_usage_data(),
				),
				200
			);
		}

		if ( empty( $message ) ) {
			return new WP_REST_Response( array( 'error' => 'Empty message' ), 400 );
		}

		$post_id           = $this->resolve_effective_post_id( $execution_message, $post_id, $checkpoint_data );
		$loaded_groups     = $this->scope_loaded_groups_for_contextual_followup( $execution_message, $checkpoint_data, $loaded_groups, $post_id );
		$loaded_groups     = $this->load_groups_for_numbered_read_followup( $execution_message, $checkpoint_data, $loaded_groups, $post_id );
		$continuation_mode = $this->resolve_continuation_mode( $execution_message, $checkpoint_data, $plan_execute );

		return array(
			'message'            => $execution_message,
			'original_message'   => $original_message,
			'execution_message'  => $execution_message,
			'conversation'       => $conversation,
			'screen'             => $screen,
			'post_id'            => $post_id,
			'suppress_plan'      => $suppress_plan,
			'plan_execute'       => $plan_execute,
			'continuation_mode'  => $continuation_mode,
			'deep_mode'          => $deep_mode,
			'loaded_groups'      => $loaded_groups,
			'checkpoint_data'    => $checkpoint_data,
			'chat_id'            => $chat_id,
			'tier'               => $tier,
			'plan_info'          => PressArk_Entitlements::get_plan_info( $tier ),
			'tracker'            => $tracker,
			'user_id'            => $user_id,
		);
	}

	/**
	 * Return a normalized REST request that preserves current endpoint semantics.
	 */
	public function compile_rest_request( WP_REST_Request $request ): WP_REST_Request|WP_REST_Response {
		$compiled = $this->compile_chat_request( $request );
		if ( $compiled instanceof WP_REST_Response ) {
			return $compiled;
		}

		$compiled_request = new WP_REST_Request( $request->get_method(), $request->get_route() );
		foreach ( $request->get_params() as $key => $value ) {
			$compiled_request->set_param( $key, $value );
		}
		$compiled_request->set_param( 'message', (string) ( $compiled['original_message'] ?? '' ) );
		$compiled_request->set_param( 'conversation', is_array( $compiled['conversation'] ?? null ) ? $compiled['conversation'] : array() );
		$compiled_request->set_param( 'screen', sanitize_text_field( (string) ( $compiled['screen'] ?? '' ) ) );
		$compiled_request->set_param( 'post_id', absint( $compiled['post_id'] ?? 0 ) );
		$compiled_request->set_param( 'deep_mode', ! empty( $compiled['deep_mode'] ) );
		$compiled_request->set_param(
			'loaded_groups',
			is_array( $compiled['loaded_groups'] ?? null ) ? $compiled['loaded_groups'] : array()
		);
		$compiled_request->set_param( 'checkpoint', is_array( $compiled['checkpoint_data'] ?? null ) ? $compiled['checkpoint_data'] : array() );
		$compiled_request->set_param( 'chat_id', (int) ( $compiled['chat_id'] ?? 0 ) );
		$compiled_request->set_param( 'suppress_plan', ! empty( $compiled['suppress_plan'] ) );
		$compiled_request->set_param( 'plan_execute', ! empty( $compiled['plan_execute'] ) );

		return $compiled_request;
	}

	/**
	 * Build the replay request used by plan approval / execution.
	 */
	public function build_plan_execute_request( array $run, array $plan_context, PressArk_Checkpoint $checkpoint ): WP_REST_Request {
		$this->ensure_plan_mode_loaded();

		$message = (string) ( $plan_context['execute_message'] ?? $plan_context['message'] ?? $run['message'] ?? '' );
		$message = PressArk_Plan_Mode::strip_plan_directive( $message );
		if ( ! $this->is_continuation_message( $message ) ) {
			$message = '[Continue] ' . ltrim( $message );
		}

		$request = new WP_REST_Request( 'POST', '/pressark/v1/chat' );
		$request->set_param( 'message', $message );
		$request->set_param( 'conversation', is_array( $plan_context['conversation'] ?? null ) ? $plan_context['conversation'] : array() );
		$request->set_param( 'screen', sanitize_text_field( (string) ( $plan_context['screen'] ?? '' ) ) );
		$request->set_param( 'post_id', absint( $plan_context['post_id'] ?? ( $run['post_id'] ?? 0 ) ) );
		$request->set_param( 'deep_mode', ! empty( $plan_context['deep_mode'] ) );
		$context_groups = is_array( $plan_context['loaded_groups'] ?? null ) ? $plan_context['loaded_groups'] : array();
		$plan_groups    = $this->derive_groups_from_plan_steps( $checkpoint );
		$merged_groups  = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', array_merge( $context_groups, $plan_groups ) )
				)
			)
		);
		$request->set_param( 'loaded_groups', $merged_groups );
		$request->set_param( 'chat_id', (int) ( $plan_context['chat_id'] ?? ( $run['chat_id'] ?? 0 ) ) );
		$request->set_param( 'checkpoint', $checkpoint->to_array() );
		$request->set_param( 'suppress_plan', true );

		return $request;
	}

	/**
	 * Resolve the tool groups required by the approved plan's steps.
	 *
	 * On execute-phase replay the agent re-runs its preloader and loses the
	 * groups that were live during planning. Using the plan's own step
	 * tool_names as the authoritative signal guarantees every tool the plan
	 * committed to is loaded before the first execute round.
	 *
	 * @return array<int,string>
	 */
	private function derive_groups_from_plan_steps( PressArk_Checkpoint $checkpoint ): array {
		if ( ! method_exists( $checkpoint, 'get_plan_steps' ) || ! class_exists( 'PressArk_Operation_Registry' ) ) {
			return array();
		}
		$groups = array();
		foreach ( (array) $checkpoint->get_plan_steps() as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$tool_name = sanitize_key( (string) ( $step['tool_name'] ?? '' ) );
			if ( '' === $tool_name ) {
				continue;
			}
			$group = PressArk_Operation_Registry::get_group( $tool_name );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}
		return array_values( array_unique( $groups ) );
	}

	/**
	 * Sanitize a conversation transcript while preserving code / HTML content.
	 *
	 * @param mixed $conversation Raw request value.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_conversation( $conversation ): array {
		if ( ! is_array( $conversation ) ) {
			return array();
		}

		$clean = array();
		foreach ( $conversation as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}
			$role = sanitize_text_field( $msg['role'] ?? '' );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$content = $msg['content'] ?? '';
			if ( ! is_string( $content ) ) {
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => $this->sanitize_conversation_content( $content ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize conversation content preserving code / HTML and long messages.
	 */
	public function sanitize_conversation_content( string $content ): string {
		$content = wp_check_invalid_utf8( $content );
		$content = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );
		if ( mb_strlen( $content ) > 50000 ) {
			$content = mb_substr( $content, 0, 50000 );
		}

		return $content;
	}

	/**
	 * Expand terse clarification replies into a full plan follow-up message.
	 *
	 * @param array<int,string> $loaded_groups Loaded tool groups passed by reference.
	 */
	public function maybe_expand_pending_plan_followup( string $message, array $checkpoint_data, array &$loaded_groups ): string {
		$message = sanitize_textarea_field( (string) $message );
		if ( '' === trim( $message ) || empty( $checkpoint_data ) ) {
			return $message;
		}

		$checkpoint = PressArk_Checkpoint::from_array( $checkpoint_data );
		if ( 'needs_input' !== $checkpoint->get_plan_status() ) {
			return $message;
		}

		if ( ! $this->message_looks_like_clarification_answer( $message ) ) {
			return $message;
		}

		$request_context = $checkpoint->get_plan_request_context();
		$base_message    = trim(
			(string) (
				$request_context['execute_message']
				?? $request_context['message']
				?? $checkpoint->get_goal()
			)
		);
		if ( '' === $base_message ) {
			return $message;
		}

		$base_normalized    = strtolower( preg_replace( '/\s+/', ' ', $base_message ) );
		$message_normalized = strtolower( preg_replace( '/\s+/', ' ', $message ) );
		if ( $base_normalized === $message_normalized ) {
			return $message;
		}

		$prior_groups = array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					array_merge(
						(array) ( $request_context['loaded_groups'] ?? array() ),
						$checkpoint->get_loaded_tool_groups()
					)
				)
			)
		);
		if ( ! empty( $prior_groups ) ) {
			$loaded_groups = array_values( array_unique( array_merge( $loaded_groups, $prior_groups ) ) );
		}

		return sanitize_textarea_field(
			mb_substr(
				sprintf(
					"Original request: %s\nClarification: %s",
					$base_message,
					$message
				),
				0,
				4000
			)
		);
	}

	/**
	 * Resolve the effective target post when continuing a saved execution.
	 */
	public function resolve_effective_post_id( string $message, int $post_id, array $checkpoint_data ): int {
		$explicit_post_id = $this->extract_explicit_post_id_from_message( $message );
		if ( $explicit_post_id > 0 ) {
			return $explicit_post_id;
		}

		if ( $post_id > 0 ) {
			return $post_id;
		}

		if (
			! $this->is_continuation_message( $message )
			&& ! $this->message_looks_like_contextual_target_followup( $message )
		) {
			return $post_id;
		}

		$target_id = $this->checkpoint_current_target_post_id( $checkpoint_data );
		if ( $target_id > 0 ) {
			return $target_id;
		}

		return $post_id;
	}

	/**
	 * Drop stale broad groups for tiny same-target status follow-ups.
	 *
	 * This keeps "publish it" attached to the latest page/product without
	 * dragging a previous multi-domain generation surface into the next turn.
	 *
	 * @param string[] $loaded_groups Existing conversation-scoped groups.
	 * @return string[]
	 */
	public function scope_loaded_groups_for_contextual_followup( string $message, array $checkpoint_data, array $loaded_groups, int $post_id ): array {
		if (
			$post_id <= 0
			|| ! $this->message_looks_like_contextual_status_followup( $message )
			|| $post_id !== $this->checkpoint_current_target_post_id( $checkpoint_data )
		) {
			return $loaded_groups;
		}

		$target    = $this->checkpoint_current_target( $checkpoint_data );
		$post_type = sanitize_key( (string) ( $target['post_type'] ?? '' ) );
		if ( '' === $post_type && function_exists( 'get_post_type' ) ) {
			$post_type = sanitize_key( (string) get_post_type( $post_id ) );
		}

		return in_array( $post_type, array( 'product', 'product_variation' ), true )
			? array( 'woocommerce' )
			: array( 'core' );
	}

	/**
	 * Carry the prior read domain into short numbered follow-ups.
	 *
	 * @param string[] $loaded_groups Existing conversation-scoped groups.
	 * @return string[]
	 */
	public function load_groups_for_numbered_read_followup( string $message, array $checkpoint_data, array $loaded_groups, int $post_id ): array {
		if ( $post_id <= 0 || ! $this->message_looks_like_numbered_action_followup( $message ) ) {
			return $loaded_groups;
		}

		$read_state = is_array( $checkpoint_data['read_state'] ?? null ) ? (array) $checkpoint_data['read_state'] : array();
		if ( empty( $read_state ) ) {
			return $loaded_groups;
		}

		$groups = $loaded_groups;
		foreach ( array_reverse( $read_state ) as $snapshot ) {
			if ( ! is_array( $snapshot ) ) {
				continue;
			}

			$target_ids = array_values( array_filter( array_map( 'absint', (array) ( $snapshot['target_post_ids'] ?? array() ) ) ) );
			if ( ! in_array( $post_id, $target_ids, true ) ) {
				continue;
			}

			$tool_name = sanitize_key( (string) ( $snapshot['tool_name'] ?? '' ) );
			if ( 'analyze_seo' === $tool_name ) {
				$groups = array_merge( $groups, array( 'seo', 'core' ) );
				break;
			}
			if ( in_array( $tool_name, array( 'page_audit', 'elementor_audit_page', 'elementor_read_page' ), true ) ) {
				$groups = array_merge( $groups, array( 'elementor', 'core' ) );
				break;
			}
			if ( in_array( $tool_name, array( 'read_content', 'read_blocks' ), true ) ) {
				$groups[] = 'core';
				break;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $groups ) ) ) );
	}

	/**
	 * Resolve whether a continuation should resume planning or execution.
	 */
	public function resolve_continuation_mode( string $message, array $checkpoint_data, bool $plan_execute = false ): string {
		if ( ! $this->is_continuation_message( $message ) ) {
			return '';
		}

		if ( $plan_execute ) {
			return 'execute';
		}

		if ( empty( $checkpoint_data ) ) {
			return '';
		}

		$checkpoint = PressArk_Checkpoint::from_array( $checkpoint_data );
		if ( in_array( $checkpoint->get_plan_phase(), array( 'exploring', 'planning' ), true ) ) {
			return 'plan';
		}

		if ( method_exists( $checkpoint, 'is_plan_executing' ) && $checkpoint->is_plan_executing() ) {
			return 'execute';
		}

		if ( $checkpoint->has_unapplied_confirms() ) {
			return 'execute';
		}

		$execution = $checkpoint->get_execution();
		if ( ! empty( $execution ) && class_exists( 'PressArk_Execution_Ledger' ) ) {
			$progress = PressArk_Execution_Ledger::progress_snapshot( $execution );
			if ( ! empty( $progress['should_auto_resume'] ) || (int) ( $progress['remaining_count'] ?? 0 ) > 0 ) {
				return 'execute';
			}
			if ( empty( $execution['tasks'] ) && PressArk_Execution_Ledger::current_target_post_id( $execution ) > 0 ) {
				return 'execute';
			}
		}

		return '';
	}

	/**
	 * Resolve the stored run message used for replay / lineage bookkeeping.
	 */
	public function resolve_run_storage_message(
		bool $is_plan_run,
		string $original_message,
		string $execution_message,
		array $conversation,
		array $checkpoint_data,
		bool $plan_execute = false
	): string {
		if ( $is_plan_run ) {
			return $original_message;
		}

		if ( ! $this->is_continuation_message( $execution_message ) ) {
			return $execution_message;
		}

		$candidate = $this->find_prior_user_goal_message( $conversation );
		if ( '' === $candidate && ! empty( $checkpoint_data ) ) {
			$checkpoint      = PressArk_Checkpoint::from_array( $checkpoint_data );
			$request_context = $checkpoint->get_plan_request_context();
			$candidates      = array(
				(string) ( $request_context['execute_message'] ?? '' ),
				(string) ( $request_context['message'] ?? '' ),
				(string) $checkpoint->get_goal(),
			);

			foreach ( $candidates as $value ) {
				$normalized = $this->strip_continuation_envelope( $value );
				if ( '' !== $normalized ) {
					$candidate = $normalized;
					break;
				}
			}
		}

		if ( '' === $candidate && $plan_execute ) {
			$candidate = $this->strip_continuation_envelope( $execution_message );
		}

		if ( '' === $candidate ) {
			$candidate = $this->strip_continuation_envelope( $execution_message );
		}

		return '' !== $candidate ? $candidate : $execution_message;
	}

	/**
	 * Find the last non-continuation user goal from the conversation transcript.
	 */
	public function find_prior_user_goal_message( array $conversation ): string {
		for ( $i = count( $conversation ) - 1; $i >= 0; $i-- ) {
			$message = $conversation[ $i ];
			if ( ! is_array( $message ) || 'user' !== ( $message['role'] ?? '' ) ) {
				continue;
			}

			$content = sanitize_textarea_field( (string) ( $message['content'] ?? '' ) );
			if ( '' === trim( $content ) || $this->is_continuation_message( $content ) ) {
				continue;
			}

			$content = $this->strip_continuation_envelope( $content );
			if ( '' !== $content ) {
				return $content;
			}
		}

		return '';
	}

	/**
	 * Strip internal continuation envelopes from stored request text.
	 */
	public function strip_continuation_envelope( string $message ): string {
		$this->ensure_plan_mode_loaded();

		$normalized = trim( preg_replace( '/^\[(?:Continue|Confirmed)\]\s*/i', '', $message ) );
		$normalized = preg_replace( '/\s*Do not repeat completed steps or recreate completed content\.?/i', '', $normalized );
		$normalized = preg_replace( '/\s*Please continue with the remaining steps from my original request\.?\s*$/i', '', $normalized );
		$normalized = PressArk_Plan_Mode::strip_plan_directive( $normalized );

		return sanitize_textarea_field( trim( (string) $normalized ) );
	}

	/**
	 * Detect whether a message is an internal continuation replay.
	 */
	public function is_continuation_message( string $message ): bool {
		return 1 === preg_match( '/^\[(?:Continue|Confirmed)\]/i', trim( $message ) );
	}

	private function extract_explicit_post_id_from_message( string $message ): int {
		if ( preg_match( '/\bpost_id\s*=\s*(\d+)\b/i', $message, $match ) ) {
			return absint( $match[1] );
		}

		if ( preg_match( '/\b(?:post|page|product)\s*#\s*(\d+)\b/i', $message, $match ) ) {
			return absint( $match[1] );
		}

		return 0;
	}

	private function checkpoint_current_target_post_id( array $checkpoint_data ): int {
		$execution = is_array( $checkpoint_data['execution'] ?? null )
			? $checkpoint_data['execution']
			: array();

		if ( class_exists( 'PressArk_Execution_Ledger' ) ) {
			return PressArk_Execution_Ledger::current_target_post_id( $execution );
		}

		return absint( $execution['current_target']['post_id'] ?? 0 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function checkpoint_current_target( array $checkpoint_data ): array {
		$execution = is_array( $checkpoint_data['execution'] ?? null )
			? $checkpoint_data['execution']
			: array();

		return is_array( $execution['current_target'] ?? null )
			? (array) $execution['current_target']
			: array();
	}

	private function message_looks_like_contextual_target_followup( string $message ): bool {
		$normalized = $this->normalize_followup_message( $message );
		if ( '' === $normalized || mb_strlen( $normalized ) > 160 ) {
			return false;
		}

		$word_count = str_word_count( preg_replace( '/[^\p{L}\p{N}\s%+\-]/u', ' ', $normalized ) );
		if ( $word_count > 14 ) {
			return false;
		}

		if ( $this->message_looks_like_numbered_action_followup( $normalized ) ) {
			return true;
		}

		$has_contextual_target = 1 === preg_match(
			// v5.8.13 (2026-05-14): only inherit ledger targets for singular same-chat follow-ups.
			'/\b(?:it|this|that|the\s+(?:draft|page|post|product|item|content|landing\s+page))\b/i',
			$normalized
		);
		if ( ! $has_contextual_target ) {
			return false;
		}

		return 1 === preg_match(
			'/\b(?:publish|unpublish|draft|move|set|make|put|take|trash|delete|restore|rename|update|change|edit|fix|add|remove|schedule|private|live)\b/i',
			$normalized
		);
	}

	private function message_looks_like_contextual_status_followup( string $message ): bool {
		$normalized = $this->normalize_followup_message( $message );
		if ( ! $this->message_looks_like_contextual_target_followup( $normalized ) ) {
			return false;
		}

		return 1 === preg_match(
			'/\b(?:publish|unpublish|draft|private|schedule|make\s+(?:it|this|that|the\s+\w+)\s+live|put\s+(?:it|this|that|the\s+\w+)\s+live|take\s+(?:it|this|that|the\s+\w+)\s+live|move\s+(?:it|this|that|the\s+\w+)\s+to\s+draft|set\s+(?:it|this|that|the\s+\w+)\s+to\s+(?:publish|published|draft|private))\b/i',
			$normalized
		);
	}

	private function message_looks_like_numbered_action_followup( string $message ): bool {
		$normalized = $this->normalize_followup_message( $message );
		if ( '' === $normalized || mb_strlen( $normalized ) > 80 ) {
			return false;
		}

		// v5.8.15 (2026-05-14): allow terse "fix 3" / "do the third one"
		// follow-ups to inherit the latest structured target from the ledger.
		return 1 === preg_match(
			'/^\s*(?:please\s+)?(?:fix|apply|do|handle|use|run|update|change|make)\s+(?:the\s+)?(?:(?:issue|item|fix)\s+)?(?:#\s*)?(?:\d{1,2}|one|two|three|four|five|first|second|third|fourth|fifth)(?:\s+(?:one|item|issue|fix|result))?\s*$/i',
			$normalized
		) || 1 === preg_match(
			'/^\s*(?:please\s+)?(?:the\s+)?(?:first|second|third|fourth|fifth)\s+(?:one|item|issue|fix|result)\s*$/i',
			$normalized
		);
	}

	private function normalize_followup_message( string $message ): string {
		$message = wp_strip_all_tags( (string) $message );
		$message = preg_replace( '/^\s*["\']|["\']\s*$/', '', (string) $message );
		$message = preg_replace( '/\s+/', ' ', (string) $message );

		return strtolower( trim( (string) $message ) );
	}

	/**
	 * Ensure plan mode helpers are loaded when the compiler runs in isolation.
	 */
	private function ensure_plan_mode_loaded(): void {
		if ( ! class_exists( 'PressArk_Plan_Mode' ) ) {
			require_once __DIR__ . '/../class-pressark-plan-mode.php';
		}
	}

	/**
	 * Detect short replies that are likely answers to a pending plan question.
	 */
	public function message_looks_like_clarification_answer( string $message ): bool {
		$normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $message ) ) );
		if ( '' === $normalized ) {
			return false;
		}

		if ( 1 === preg_match(
			'/\b(?:all(?:\s+of\s+them)?|every(?:thing|one)?|each|both|this one|that one|these|those|current price|regular price|sale price|same as current|use sale price|use regular price|minus\s+\d+(?:\.\d+)?%?|plus\s+\d+(?:\.\d+)?%?|\d+(?:\.\d+)?%\s*off|yes|no)\b/i',
			$normalized
		) ) {
			return true;
		}

		if ( 1 === preg_match( '/(?:^|[\s,])[-+]?\d+(?:\.\d+)?%?(?:$|[\s,])/', $normalized ) ) {
			return true;
		}

		$word_count = str_word_count( preg_replace( '/[^\p{L}\p{N}\s%+\-]/u', ' ', $normalized ) );
		if ( $word_count > 18 ) {
			return false;
		}

		return 0 === preg_match(
			'/\b(?:update|change|edit|modify|rewrite|replace|delete|remove|create|add|set|publish|increase|decrease|raise|lower|append|prepend|rename|move|fix|make|write|generate|draft|review|inspect|analy[sz]e|audit|scan|list|show|read)\b/i',
			$normalized
		);
	}

	/**
	 * Mirror unresolved confirm-card actions into checkpoint pending markers.
	 *
	 * @param array<int,array<string,mixed>> $pending_actions Raw pending action rows.
	 */
	private function sync_checkpoint_pending_confirms( PressArk_Checkpoint $checkpoint, array $pending_actions ): void {
		$checkpoint->clear_pending();

		foreach ( $pending_actions as $pending_action ) {
			if ( ! is_array( $pending_action ) || ! empty( $pending_action['resolved'] ) ) {
				continue;
			}

			$action = is_array( $pending_action['action'] ?? null )
				? (array) $pending_action['action']
				: $pending_action;
			$name   = sanitize_text_field( (string) ( $action['name'] ?? $action['type'] ?? 'unknown_action' ) );
			$args   = is_array( $action['arguments'] ?? null )
				? (array) $action['arguments']
				: ( is_array( $action['params'] ?? null ) ? (array) $action['params'] : array() );
			$target = '';

			if ( ! empty( $args['post_id'] ) ) {
				$target = 'post #' . absint( $args['post_id'] );
			} elseif ( ! empty( $args['title'] ) ) {
				$target = '"' . sanitize_text_field( (string) $args['title'] ) . '"';
			}

			$checkpoint->add_pending(
				'' !== $name ? $name : 'unknown_action',
				'' !== $target ? $target : 'site',
				'NOT YET APPLIED - awaiting user approval'
			);
		}
	}
}
