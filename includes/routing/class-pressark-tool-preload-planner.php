<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Predicts likely tool groups that should be preloaded for the request.
 */
class PressArk_Tool_Preload_Planner {

	/**
	 * Plan preload groups and related heuristics for the request.
	 *
	 * @param array<string,mixed> $permission
	 * @return array<string,mixed>
	 */
	public function plan( PressArk_Request_Context $context, array $permission = array() ): array {
		$predicted_tools = $this->predict_tool_candidates( $context->message );
		$task_type       = $this->classify_preload_task_type( $context->message, $permission, $predicted_tools );
		$groups          = $this->preload_groups_for_task_type( $task_type );

		return array(
			'task_type'          => $task_type,
			'predicted_tools'    => $predicted_tools,
			'groups'             => $groups,
			'reason_codes'       => ! empty( $groups ) && '' !== $task_type ? array( 'router_preload_' . $task_type ) : array(),
			'requires_hard_plan' => ! empty( $groups ),
			'reads_first'        => ! empty( $groups ),
			'max_discover_calls' => ! empty( $groups ) ? 0 : self::default_max_discover_calls(),
		);
	}

	/**
	 * Apply preload-planning advice onto the planning decision without having the
	 * route arbiter mutate that policy state itself.
	 *
	 * @param array<string,mixed> $planning_decision
	 * @param array<string,mixed> $preload_plan
	 * @return array<string,mixed>
	 */
	public function apply_planning_advisory( array $planning_decision, array $preload_plan ): array {
		$groups = $this->normalize_groups( (array) ( $preload_plan['groups'] ?? array() ) );
		if ( empty( $groups ) ) {
			return $planning_decision;
		}

		$task_type    = sanitize_key( (string) ( $preload_plan['task_type'] ?? '' ) );
		$reason_codes = array_values(
			array_unique(
				array_merge(
					(array) ( $planning_decision['reason_codes'] ?? array() ),
					(array) ( $preload_plan['reason_codes'] ?? array() )
				)
			)
		);

		$existing_mode = sanitize_key( (string) ( $planning_decision['mode'] ?? '' ) );
		$preserve_direct_execution = in_array( 'continuation_execute_resume', $reason_codes, true )
			|| in_array( 'approved_plan_execution', $reason_codes, true )
			// v5.8.13 (2026-05-14): preload advisory must not re-escalate resolved single-target status writes.
			|| in_array( 'resolved_single_target_write', $reason_codes, true );
		$escalated     = false;
		if ( ! $preserve_direct_execution && ( '' === $existing_mode || 'none' === $existing_mode ) ) {
			$planning_decision['mode']              = 'hard_plan';
			$planning_decision['approval_required'] = true;
			$escalated                              = true;
		}
		$planning_decision['reads_first']       = true;
		$planning_decision['reason_codes']      = $reason_codes;
		$planning_decision['router_task_type']  = $task_type;
		$planning_decision['preloaded_groups']  = $groups;
		$planning_decision['max_discover_calls']= 0;

		if ( defined( 'PRESSARK_DEBUG_ROUTE' ) && PRESSARK_DEBUG_ROUTE && class_exists( 'PressArk_Planning_Policy' ) && PressArk_Planning_Policy::route_debug_env_ok() ) {
			$log_path = defined( 'PRESSARK_DEBUG_ROUTE_LOG' ) ? (string) PRESSARK_DEBUG_ROUTE_LOG : '/tmp/pressark-route.log';
			@file_put_contents(
				$log_path,
				sprintf(
					"[%s] ADVISORY prior=%s final=%s escalated=%d task=%s groups=%s\n",
					gmdate( 'H:i:s' ),
					'' === $existing_mode ? 'none' : $existing_mode,
					$planning_decision['mode'],
					$escalated ? 1 : 0,
					$task_type,
					implode( ',', $groups )
				),
				FILE_APPEND
			);
		}

		return $planning_decision;
	}

	public static function default_max_discover_calls(): int {
		return class_exists( 'PressArk_Agent' ) ? PressArk_Agent::MAX_DISCOVER_CALLS : 5;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function predict_tool_candidates( string $message ): array {
		if ( ! class_exists( 'PressArk_Tool_Catalog' ) ) {
			return array();
		}

		$matches = PressArk_Tool_Catalog::instance()->discover( $message );
		if ( empty( $matches ) || ! is_array( $matches ) ) {
			return array();
		}

		return array_values( array_slice( $matches, 0, 6 ) );
	}

	/**
	 * @param array<string,mixed>               $permission
	 * @param array<int,array<string,mixed>>    $predicted_tools
	 */
	private function classify_preload_task_type( string $message, array $permission, array $predicted_tools ): string {
		$normalized       = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $message ) ) );
		$permission_tool  = sanitize_key( (string) ( $permission['tool_name'] ?? '' ) );
		$permission_group = sanitize_key( (string) ( $permission['group'] ?? '' ) );
		$predicted_names  = array();
		$predicted_groups = array();

		foreach ( $predicted_tools as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$predicted_names[]  = sanitize_key( (string) ( $candidate['name'] ?? '' ) );
			$predicted_groups[] = sanitize_key( (string) ( $candidate['group'] ?? '' ) );
		}

		$predicted_names  = array_values( array_filter( array_unique( $predicted_names ) ) );
		$predicted_groups = array_values( array_filter( array_unique( $predicted_groups ) ) );

		$looks_like_woo = in_array( 'woocommerce', $predicted_groups, true )
			|| 'woocommerce' === $permission_group
			|| preg_match( '/\b(?:product|products|catalog|catalogue|store|shop|woo|woocommerce|coupon|inventory|stock|variation|order|orders)\b/i', $normalized );
		if ( $looks_like_woo ) {
			return 'woo_ops';
		}

		$looks_like_seo_fix = (
			in_array( 'seo', $predicted_groups, true )
			|| 'seo' === $permission_group
			|| in_array( $permission_tool, array( 'fix_seo', 'update_meta' ), true )
			|| preg_match( '/\b(?:seo|meta\s*title|meta\s*description|meta\s*desc|canonical|schema|robots(?:\.txt)?|crawlability|search engine)\b/i', $normalized )
		) && preg_match( '/\b(?:fix|update|change|edit|rewrite|improve|optimi[sz]e|set|refresh)\b/i', $normalized );
		if ( $looks_like_seo_fix ) {
			return 'seo_fix';
		}

		$looks_like_content_edit = (
			in_array( 'core', $predicted_groups, true )
			|| in_array( $permission_tool, array( 'edit_content', 'create_post', 'update_meta' ), true )
			|| preg_match( '/\b(?:post|page|article|homepage|home page|landing page|content|copy|headline|excerpt|slug|hero|section|banner|cta|button|header|footer|layout)\b/i', $normalized )
		) && preg_match( '/\b(?:update|change|edit|modify|rewrite|replace|fix|refresh|polish|adjust|set)\b/i', $normalized );
		if ( $looks_like_content_edit ) {
			return 'content_edit';
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private function preload_groups_for_task_type( string $task_type ): array {
		return match ( sanitize_key( $task_type ) ) {
			'content_edit' => array( 'core' ),
			'woo_ops'      => array( 'woocommerce' ),
			'seo_fix'      => array( 'seo', 'core' ),
			default        => array(),
		};
	}

	/**
	 * @param array<int,string> $groups
	 * @return array<int,string>
	 */
	private function normalize_groups( array $groups ): array {
		return array_values(
			array_filter(
				array_unique(
					array_map( 'sanitize_text_field', $groups )
				)
			)
		);
	}
}
