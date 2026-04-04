<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates action execution and delegates tool work to domain handlers.
 *
 * The engine intentionally stays small: it normalizes AI actions, resolves the
 * canonical operation, enforces entitlements, and hands execution to the
 * handler registry. Tool implementations live in dedicated handler classes.
 */
class PressArk_Action_Engine {

	private PressArk_Handler_Registry $handlers;

	public function __construct( PressArk_Action_Logger $logger ) {
		$this->handlers = new PressArk_Handler_Registry( $logger );
	}

	/**
	 * v3.7.1: Set the async task context on the engine.
	 *
	 * @param string $task_id The current async task ID.
	 */
	public function set_async_context( string $task_id ): void {
		$this->handlers->set_async_context( $task_id );
	}

	/**
	 * Execute a list of actions returned by the AI.
	 *
	 * @param array $actions Array of action objects with 'type' and 'params'.
	 * @return array Results for each action.
	 */
	public function execute( array $actions ): array {
		$results = array();

		foreach ( $actions as $action ) {
			$results[] = $this->execute_single( $action );
		}

		return $results;
	}

	/**
	 * Execute a single read-only tool call from the agentic loop.
	 *
	 * Validates the tool is in the allowed READ_TOOLS list,
	 * constructs the action array, and executes without logging/usage tracking.
	 *
	 * @param string $tool_name Tool name from AI tool_call.
	 * @param array  $tool_args Arguments from AI tool_call.
	 * @return array Result from the action handler.
	 */
	public function execute_read( string $tool_name, array $tool_args ): array {
		$tracker = new PressArk_Usage_Tracker();

		if ( $tracker->is_write_action( $tool_name ) || PressArk_Tool_Catalog::instance()->classify( $tool_name ) !== 'read' ) {
			return array(
				'success'     => false,
				'message'     => sprintf( 'Tool "%s" is not a read-only tool.', $tool_name ),
				'action_type' => $tool_name,
			);
		}

		$action = array(
			'type'   => $tool_name,
			'params' => $tool_args,
		);

		return $this->execute_single( $action, true );
	}

	/**
	 * Execute a single action with full error handling.
	 *
	 * @param mixed $action   Action data.
	 * @param bool  $skip_log Whether to skip action logging (used by agentic read loop).
	 * @return array Result with success, message, action_type.
	 */
	public function execute_single( $action, bool $skip_log = false ): array {
		try {
			$action = $this->normalize_action( $action );
			$type   = sanitize_text_field( $action['type'] ?? '' );
			$type   = PressArk_Operation_Registry::resolve_alias( $type );

			if ( empty( $type ) || 'unknown' === $type ) {
				return array(
					'success'     => false,
					'message'     => __( 'No action type specified.', 'pressark' ),
					'action_type' => $type,
				);
			}

			$params = $action['params'] ?? array();

			// v5.3.0: Pre-permission input validation (fail-fast before approval).
			$validation = PressArk_Operation_Registry::validate_input( $type, $params );
			if ( ! ( $validation['valid'] ?? true ) ) {
				return array(
					'success'     => false,
					'message'     => $validation['message'] ?? __( 'Invalid input for this tool.', 'pressark' ),
					'action_type' => $type,
				);
			}

			// v5.5.0: Central preflight — canonicalization, rerouting, and guards.
			$preflight = PressArk_Preflight::check( $type, $params );

			switch ( $preflight['action'] ?? PressArk_Preflight::ACTION_PROCEED ) {
				case PressArk_Preflight::ACTION_BLOCK:
					return array(
						'success'     => false,
						'message'     => $preflight['reason'] ?? __( 'Blocked by preflight check.', 'pressark' ),
						'hint'        => $preflight['hint'] ?? '',
						'action_type' => $type,
						'preflight'   => $preflight,
					);

				case PressArk_Preflight::ACTION_REROUTE:
					// Swap tool and params — re-enter execute_single with the canonical tool.
					$rerouted_action = array(
						'type'   => $preflight['tool'],
						'params' => $preflight['params'] ?? array(),
					);

					PressArk_Error_Tracker::info(
						'Preflight',
						'Rerouted tool call',
						array(
							'from'   => $type,
							'to'     => $preflight['tool'],
							'reason' => $preflight['reason'] ?? '',
						)
					);

					$rerouted_result = $this->execute_single( $rerouted_action, $skip_log );

					// Annotate the result so the model knows a reroute happened.
					$rerouted_result['preflight_reroute'] = array(
						'original_tool'  => $type,
						'rerouted_to'    => $preflight['tool'],
						'reason'         => $preflight['reason'] ?? '',
						'hint'           => $preflight['hint'] ?? '',
					);

					return $rerouted_result;

				case PressArk_Preflight::ACTION_REWRITE:
					// Same tool, adjusted params.
					$params = $preflight['params'] ?? $params;
					PressArk_Error_Tracker::info(
						'Preflight',
						'Rewrote tool params',
						array( 'tool' => $type, 'reason' => $preflight['reason'] ?? '' )
					);
					break;

				case PressArk_Preflight::ACTION_PROCEED:
				default:
					// No intervention.
					break;
			}

			if ( empty( $params ) ) {
				$params = $action;
				unset( $params['type'], $params['description'] );
			} else {
				$reserved = array( 'type', 'params', 'description' );
				foreach ( $action as $key => $value ) {
					if ( ! in_array( $key, $reserved, true ) && ! isset( $params[ $key ] ) ) {
						$params[ $key ] = $value;
					}
				}
			}

			$tool_group      = PressArk_Operation_Registry::get_group( $type );
			$tool_capability = PressArk_Operation_Registry::classify( $type, $params );
			$exec_context    = $skip_log
				? PressArk_Policy_Engine::CONTEXT_AGENT_READ
				: PressArk_Policy_Engine::CONTEXT_INTERACTIVE;

			if ( ! empty( $tool_group ) ) {
				$current_tier = ( new PressArk_License() )->get_tier();
				$usage_check  = PressArk_Entitlements::check_group_usage( $current_tier, $tool_group, $tool_capability );
				if ( ! $usage_check['allowed'] ) {
					$usage_check['action_type']         = $type;
					$usage_check['permission_decision'] = PressArk_Permission_Service::build_entitlement_denial(
						$type,
						$exec_context,
						array(
							'tier' => $current_tier,
						),
						$tool_group,
						$tool_capability,
						( PressArk_Operation_Registry::resolve( $type )->risk ?? 'moderate' ),
						$current_tier,
						$usage_check
					);
					return $usage_check;
				}
			}

			// v5.4.0: Policy engine evaluation — deny/ask before execution.
			if ( class_exists( 'PressArk_Policy_Engine' ) ) {
				$verdict = PressArk_Policy_Engine::evaluate( $type, $params, $exec_context );

				if ( PressArk_Policy_Engine::is_denied( $verdict ) ) {
					return array(
						'success'        => false,
						'message'        => implode( ' ', $verdict['reasons'] ?? array( 'Blocked by policy.' ) ),
						'action_type'    => $type,
						'policy_verdict' => $verdict,
						'permission_decision' => $verdict,
					);
				}

				// ASK verdicts in the action engine don't block — the existing
				// preview/confirm flow already handles interactive confirmation.
				// We surface the verdict for callers that need it (e.g., agentic loop).
				if ( PressArk_Policy_Engine::is_ask( $verdict ) && $skip_log ) {
					// In agent-read context, ask = deny (no human to confirm).
					return array(
						'success'        => false,
						'message'        => implode( ' ', $verdict['reasons'] ?? array( 'Requires human confirmation.' ) ),
						'action_type'    => $type,
						'policy_verdict' => $verdict,
						'permission_decision' => $verdict,
					);
				}
			}

			// v5.4.0: Global pre-operation hook (can transform params or block).
			if ( class_exists( 'PressArk_Policy_Engine' ) ) {
				$pre_check = PressArk_Policy_Engine::pre_operation( $type, $params, $exec_context ?? PressArk_Policy_Engine::CONTEXT_INTERACTIVE );
				if ( ! $pre_check['proceed'] ) {
					return array(
						'success'     => false,
						'message'     => $pre_check['reason'] ?? __( 'Blocked by pre-operation filter.', 'pressark' ),
						'action_type' => $type,
					);
				}
				$params = $pre_check['params'];
			}

			// v5.3.0: Fire per-operation pre_execute policy hooks.
			$pre_hooks = PressArk_Operation_Registry::get_policy_hooks( $type, 'pre_execute' );
			foreach ( $pre_hooks as $hook_name ) {
				$params = apply_filters( $hook_name, $params, $type );
			}

			$result = $this->dispatch( $type, $params );

			$result['action_type'] = $type;

			// v5.3.0: Fire per-operation post_execute policy hooks.
			$post_hooks = PressArk_Operation_Registry::get_policy_hooks( $type, 'post_execute' );
			foreach ( $post_hooks as $hook_name ) {
				$result = apply_filters( $hook_name, $result, $type, $params );
			}

			// v5.4.0: Global post-operation hook (can transform result).
			if ( class_exists( 'PressArk_Policy_Engine' ) ) {
				$result = PressArk_Policy_Engine::post_operation(
					$type,
					$result,
					$params,
					$exec_context ?? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
				);
			}

			if ( ( $result['success'] ?? false ) && ! empty( $tool_group ) && 'read' !== $tool_capability ) {
				PressArk_Entitlements::record_group_usage( $tool_group );
			}

			return $result;

		} catch ( \TypeError $e ) {
			PressArk_Error_Tracker::error( 'ActionEngine', 'TypeError executing action', array( 'action_type' => $action['type'] ?? 'unknown', 'error' => $e->getMessage() ) );
			return array(
				'success'     => false,
				'message'     => __( 'Something went wrong executing that action. Please try rephrasing your request.', 'pressark' ),
				'action_type' => $action['type'] ?? 'unknown',
			);
		} catch ( \Exception $e ) {
			PressArk_Error_Tracker::error( 'ActionEngine', 'Exception executing action', array( 'action_type' => $action['type'] ?? 'unknown', 'error' => $e->getMessage() ) );
			return array(
				'success'     => false,
				'message'     => __( 'An unexpected error occurred. Please try again.', 'pressark' ),
				'action_type' => $action['type'] ?? 'unknown',
			);
		} catch ( \Error $e ) {
			PressArk_Error_Tracker::critical( 'ActionEngine', 'Fatal error executing action', array( 'action_type' => $action['type'] ?? 'unknown', 'error' => $e->getMessage() ) );
			return array(
				'success'     => false,
				'message'     => __( 'Something went wrong. Please try again or rephrase your request.', 'pressark' ),
				'action_type' => $action['type'] ?? 'unknown',
			);
		}
	}

	/**
	 * Dispatch an action through the Operation Registry.
	 *
	 * @since 3.4.0
	 *
	 * @param string $type   Canonical tool name.
	 * @param array  $params Tool arguments.
	 * @return array Result with success, message, etc.
	 */
	private function dispatch( string $type, array $params ): array {
		$operation = PressArk_Operation_Registry::resolve( $type );

		if ( ! $operation ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: action type */
					__( 'Unknown action type: %s', 'pressark' ),
					$type
				),
			);
		}

		return $this->handlers->dispatch( $operation, $params );
	}

	/**
	 * Normalize action parameters to handle common AI quirks.
	 */
	private function normalize_action( $action ): array {
		if ( ! is_array( $action ) ) {
			$action = array( 'type' => (string) $action );
		}

		if ( ! isset( $action['type'] ) ) {
			$action['type'] = 'unknown';
		}

		if ( isset( $action['post_id'] ) ) {
			if ( in_array( $action['post_id'], array( 'all', 'site', '*' ), true ) ) {
				$action['post_id'] = 'all';
			} else {
				$action['post_id'] = intval( $action['post_id'] );
			}
		}

		if ( in_array( $action['type'], array( 'analyze_seo', 'scan_security', 'analyze_store' ), true ) ) {
			if ( isset( $action['params'] ) && ! is_array( $action['params'] ) ) {
				$action['params'] = array( 'scope' => $action['params'] );
			}
			if ( ! isset( $action['params'] ) ) {
				$action['params'] = array();
			}
		}

		if ( isset( $action['changes'] ) && ! is_array( $action['changes'] ) ) {
			$action['changes'] = array();
		}

		if ( isset( $action['meta'] ) && ! is_array( $action['meta'] ) ) {
			$action['meta'] = array();
		}

		return $action;
	}
}
