<?php
/**
 * PressArk Router — Unified routing decision.
 *
 * Decides whether a request goes to: async queue, workflow, agent, or legacy.
 * Composes PressArk_Task_Queue::should_queue() + PressArk_Workflow_Router::route()
 * + PressArk_Model_Policy::supports_tools() into a single routing call.
 *
 * v3.2.0: Returns route metadata for enforcement (approval_mode, reads_first).
 *
 * @package PressArk
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Router {

	public const ROUTE_ASYNC    = 'async';
	public const ROUTE_WORKFLOW = 'workflow';
	public const ROUTE_AGENT    = 'agent';
	public const ROUTE_LEGACY   = 'legacy';

	/**
	 * Determine the execution route for a request.
	 *
	 * Evaluation order:
	 * 1. Async (long-running pattern match via PressArk_Task_Queue)
	 * 2. Workflow (deterministic state machine via PressArk_Workflow_Router)
	 * 3. Agent (model supports native tool calling)
	 * 4. Legacy (text-only fallback)
	 *
	 * @param string                 $message      User message.
	 * @param array                  $conversation Conversation history.
	 * @param PressArk_AI_Connector  $connector    AI connector instance.
	 * @param PressArk_Action_Engine $engine       Action engine instance.
	 * @param string                 $tier         User's plan tier.
	 * @param bool                   $deep_mode    Whether deep mode is active.
	 * @param string                 $screen       Current admin screen slug.
	 * @param int                    $post_id      Current post ID.
	 * @return array{route: string, handler: ?PressArk_Workflow_Runner, meta: array}
	 */
	public static function resolve(
		string                 $message,
		array                  $conversation,
		PressArk_AI_Connector  $connector,
		PressArk_Action_Engine $engine,
		string                 $tier,
		bool                   $deep_mode = false,
		string                 $screen = '',
		int                    $post_id = 0
	): array {
		// 1. Async — long-running tasks go to background queue.
		$queue = new PressArk_Task_Queue();
		$async_score = $queue->async_score( $message );
		if ( $async_score >= PressArk_Task_Queue::ASYNC_THRESHOLD ) {
			return array(
				'route'   => self::ROUTE_ASYNC,
				'handler' => null,
				'meta'    => array(
					'approval_mode' => 'confirm',
					'reads_first'   => false,
					'async_score'   => $async_score,
					'route_reason'  => 'async_threshold',
					'phase_route'   => 'retrieval_planning',
				),
			);
		}

		// Cheap chat path: greetings / acknowledgements / capability smalltalk.
		// Keeps "hello" away from the full agent prompt + tool payload.
		if ( PressArk_Agent::is_lightweight_chat_request( $message, $conversation ) ) {
			return array(
				'route'   => self::ROUTE_LEGACY,
				'handler' => null,
				'meta'    => array(
					'approval_mode' => 'none',
					'reads_first'   => false,
					'route_reason'  => 'lightweight_chat',
					'phase_route'   => 'classification',
				),
			);
		}

		// 2. Workflow — deterministic state machines for known patterns.
		$workflow_router = new PressArk_Workflow_Router();
		$workflow_decision = $workflow_router->route_decision(
			$message, $conversation, $connector, $engine, $tier, $screen, $post_id
		);
		$workflow = $workflow_decision['workflow'];

		if ( $workflow ) {
			return array(
				'route'   => self::ROUTE_WORKFLOW,
				'handler' => $workflow,
				'meta'    => array(
					'approval_mode' => 'preview',
					'reads_first'   => true,
					'workflow_class' => $workflow_decision['class'] ?? '',
					'workflow_score' => $workflow_decision['score'] ?? 0,
					'workflow_scores' => $workflow_decision['scores'] ?? array(),
					'route_reason'   => $workflow_decision['reason'] ?? 'workflow_match',
					'phase_route'    => 'classification',
				),
			);
		}

		// 3. Agent — models with native tool calling.
		if ( $connector->supports_native_tools( $deep_mode ) ) {
			$route_reason = ! empty( $workflow_decision['ambiguous'] )
				? 'workflow_ambiguity'
				: ( ! empty( $workflow_decision['multi_intent'] ) ? 'multi_intent' : 'native_tools' );
			return array(
				'route'   => self::ROUTE_AGENT,
				'handler' => null,
				'meta'    => array(
					'approval_mode' => 'mixed',
					'reads_first'   => true,
					'route_reason'  => $route_reason,
					'workflow_score' => $workflow_decision['score'] ?? 0,
					'workflow_scores' => $workflow_decision['scores'] ?? array(),
					'premium_phase' => ! empty( $workflow_decision['needs_premium'] ),
					'phase_route'   => ! empty( $workflow_decision['needs_premium'] ) ? 'ambiguity_resolution' : 'classification',
				),
			);
		}

		// 4. Legacy — text-only fallback.
		return array(
			'route'   => self::ROUTE_LEGACY,
			'handler' => null,
				'meta'    => array(
					'approval_mode' => 'none',
					'reads_first'   => false,
					'route_reason'  => 'no_native_tools',
					'phase_route'   => 'classification',
				),
			);
	}
}
