<?php
/**
 * PressArk plan-mode lifecycle helpers.
 *
 * @package PressArk
 * @since   5.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Plan_Mode {

	public const MODE_PLAN = 'plan';

	/**
	 * Persist plan-mode context for the run.
	 *
	 * @param string $run_id   Durable run ID.
	 * @param string $message  Original user message.
	 * @param array  $context  Optional execution context snapshot.
	 * @return array<string,mixed>
	 */
	public static function enter( string $run_id, string $message, array $context = array() ): array {
		$run_id   = sanitize_text_field( $run_id );
		$message  = wp_check_invalid_utf8( $message );
		$prepared = self::prepare_context_for_plan_mode( $context );
		$payload  = array_merge(
			array(
				'mode'            => self::MODE_PLAN,
				'started'         => time(),
				'message'         => $message,
				'execute_message' => self::strip_plan_directive( $message ),
				'phase'           => sanitize_key( (string) ( $prepared['phase'] ?? 'exploring' ) ),
			),
			$prepared
		);

		set_site_transient( self::transient_key( $run_id ), $payload, HOUR_IN_SECONDS );

		if ( class_exists( 'PressArk_Activity_Trace' ) ) {
			PressArk_Activity_Trace::publish(
				array(
					'event_type' => 'plan.entered',
					'phase'      => 'plan',
					'status'     => 'started',
					'reason'     => 'plan_entered',
					'summary'    => 'Planning entered a read-only exploration phase.',
					'payload'    => array(
						'approval_level' => sanitize_key( (string) ( $payload['approval_level'] ?? 'hard' ) ),
						'planning_mode'  => sanitize_key( (string) ( $payload['planning_mode'] ?? 'hard_plan' ) ),
						'reason_codes'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $payload['policy']['reason_codes'] ?? array() ) ) ) ),
					),
				),
				array(
					'run_id' => $run_id,
				)
			);
		}

		return $payload;
	}

	/**
	 * Whether a run still has active plan-mode context.
	 */
	public static function is_active( string $run_id ): bool {
		return is_array( get_site_transient( self::transient_key( $run_id ) ) );
	}

	/**
	 * Read plan-mode context without mutating it.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_context( string $run_id ): array {
		$stored = get_site_transient( self::transient_key( $run_id ) );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Clear active plan-mode context without publishing an approval event.
	 */
	public static function abort( string $run_id ): void {
		delete_site_transient( self::transient_key( $run_id ) );
	}

	/**
	 * Exit plan mode and publish an approval-boundary trace event.
	 *
	 * @param string $run_id         Durable run ID.
	 * @param string $plan_markdown  Final checklist plan.
	 * @return array<string,mixed>   Previous transient context.
	 */
	public static function exit( string $run_id, string $plan_markdown ): array {
		$run_id        = sanitize_text_field( $run_id );
		$plan_markdown = sanitize_textarea_field( $plan_markdown );
		$context       = self::get_context( $run_id );

		delete_site_transient( self::transient_key( $run_id ) );

		$steps = self::extract_steps( $plan_markdown );
		if ( class_exists( 'PressArk_Activity_Trace' ) ) {
			PressArk_Activity_Trace::publish(
				array(
					'event_type' => 'run.plan_exit',
					'phase'      => 'plan',
					'status'     => 'running',
					'reason'     => 'state_change',
					'summary'    => 'Plan approved for execution.',
					'payload'    => array(
						'type'          => 'plan_ready',
						'step_count'    => count( $steps ),
						'steps'         => $steps,
						'permission_mode' => self::MODE_PLAN,
						'plan_markdown' => $plan_markdown,
					),
				),
				array(
					'run_id' => $run_id,
				)
			);
		}

		return $context;
	}

	/**
	 * Prompt addendum injected into the agent while plan mode is active.
	 */
	public static function get_system_prompt(): string {
		return implode(
			"\n",
			array(
				'You are in PLAN MODE.',
				'Research only using read-only tools and plan-safe discovery helpers.',
				'Do not make changes, do not propose previews or confirmations, and do not execute write tools.',
				'Act like a senior WordPress operator: resolve exact targets, IDs, current state, and native domain APIs before proposing steps.',
				'Plans must name the objects and fields affected, the main risk checks, and the verification reads you will run after execution.',
				'Prefer WordPress-native tools over raw content/meta edits when a dedicated domain tool exists.',
				'For WooCommerce price or sale work, plan to read the product first. Do not use plain price for writes: choose regular_price for the base price, sale_price for a sale amount, or clear_sale=true to remove a sale. If the request explicitly says sale, plan around sale_price. If it says increase, decrease, raise, lower, or current/regular price, plan around regular_price or a relative regular_price adjustment. If the wording is ambiguous about sale price vs regular price, ask a clarification in the plan instead of guessing. Empty sale_price is legacy compatibility only, not the planned path. Preserve regular_price unless explicitly changing it, and never set prices to 0 unless the goal is a free product.',
				'If a prior attempt or hypothesis already failed, do not restate the same fix; revise the plan with a different diagnostic step or safer alternative.',
				'If you need more context, keep exploring with read tools only.',
				'Do not output the checklist until you have first used the relevant read-only tools and incorporated those read results into the plan.',
				'When you are ready, output a numbered checklist plan and stop so the user can explicitly approve execution.',
				'When you submit the plan, call the `update_plan` tool with EXACTLY this shape: { "steps": [ { "content": "<imperative step text>", "activeForm": "<present-progress rendering>", "status": "pending" | "in_progress" | "completed" } ] }.',
				'Use the field name "steps" (not "tasks", "todos", "items"). Use "content" (not "title" or "description"). Keep exactly one step with status "in_progress" at a time. Submit the full ordered list on the first call.',
			)
		);
	}

	/**
	 * Normalize request context for plan-mode permission handling.
	 *
	 * @return array<string,mixed>
	 */
	public static function prepare_context_for_plan_mode( array $context = array() ): array {
		$context['permission_mode'] = self::MODE_PLAN;
		$context['mode']            = self::MODE_PLAN;
		$context['approval_level']  = in_array( (string) ( $context['approval_level'] ?? '' ), array( 'soft', 'hard' ), true )
			? sanitize_key( (string) $context['approval_level'] )
			: 'hard';
		$context['planning_mode']   = sanitize_key( (string) ( $context['planning_mode'] ?? 'hard_plan' ) );

		if ( ! empty( $context['policy'] ) && is_array( $context['policy'] ) ) {
			$context['policy'] = array(
				'mode'              => sanitize_key( (string) ( $context['policy']['mode'] ?? '' ) ),
				'reason_codes'      => array_values( array_filter( array_map( 'sanitize_key', (array) ( $context['policy']['reason_codes'] ?? array() ) ) ) ),
				'complexity_score'  => max( 0, absint( $context['policy']['complexity_score'] ?? 0 ) ),
				'risk_score'        => max( 0, absint( $context['policy']['risk_score'] ?? 0 ) ),
				'breadth_score'     => max( 0, absint( $context['policy']['breadth_score'] ?? 0 ) ),
				'uncertainty_score' => max( 0, absint( $context['policy']['uncertainty_score'] ?? 0 ) ),
				'destructive_score' => max( 0, absint( $context['policy']['destructive_score'] ?? 0 ) ),
			);
		}

		return $context;
	}

	/**
	 * Check whether the user explicitly requested plan mode.
	 */
	public static function message_requests_plan( string $message ): bool {
		return 1 === preg_match( '/^\s*\/plan(?:\s+|$)/i', (string) $message );
	}

	/**
	 * Strip the leading /plan directive while preserving the remainder.
	 */
	public static function strip_plan_directive( string $message ): string {
		$stripped = preg_replace( '/^\s*\/plan(?:\s+|$)/i', '', (string) $message, 1 );
		$stripped = is_string( $stripped ) ? trim( $stripped ) : '';

		return '' !== $stripped ? $stripped : trim( (string) $message );
	}

	/**
	 * Parse numbered or bulleted checklist items into UI rows.
	 *
	 * @param string   $plan_markdown Checklist markdown.
	 * @param string[] $fallback      Fallback plain-text steps.
	 * @return array<int,array<string,mixed>>
	 */
	public static function extract_steps( string $plan_markdown, array $fallback = array() ): array {
		$steps = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $plan_markdown );

		foreach ( (array) $lines as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ) );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^(?:\d+[\.\)]|[-*])\s+(.+)$/', $line, $matches ) ) {
				$text = sanitize_text_field( trim( (string) $matches[1] ) );
				if ( '' !== $text ) {
					$steps[] = $text;
				}
			}
		}

		if ( empty( $steps ) ) {
			foreach ( $fallback as $row ) {
				if ( is_array( $row ) ) {
					$row = (string) ( $row['text'] ?? '' );
				}
				$text = sanitize_text_field( trim( (string) $row ) );
				if ( '' !== $text ) {
					$steps[] = $text;
				}
			}
		}

		$rows = array();
		foreach ( array_slice( array_values( array_unique( $steps ) ), 0, 8 ) as $index => $text ) {
			$rows[] = array(
				'index'  => $index + 1,
				'text'   => $text,
				'status' => 'pending',
			);
		}

		return $rows;
	}

	/**
	 * Message returned when a non-readonly tool is attempted in plan mode.
	 */
	public static function permission_denied_message(): string {
		return __( 'Plan mode only allows read-only research. Review the plan first, then approve execution before any write previews or mutations can run.', 'pressark' );
	}

	/**
	 * Build the site-scoped transient key used for plan runs.
	 */
	private static function transient_key( string $run_id ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$slug    = strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $run_id ) );

		return sprintf( 'pressark_site_%d_plan_%s', max( 0, $blog_id ), $slug );
	}
}
