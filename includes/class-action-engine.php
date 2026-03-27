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

			$tool_group      = PressArk_Operation_Registry::get_group( $type );
			$tool_capability = PressArk_Operation_Registry::classify( $type, $params );

			if ( ! empty( $tool_group ) ) {
				$current_tier = ( new PressArk_License() )->get_tier();
				$usage_check  = PressArk_Entitlements::check_group_usage( $current_tier, $tool_group, $tool_capability );
				if ( ! $usage_check['allowed'] ) {
					$usage_check['action_type'] = $type;
					return $usage_check;
				}
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

			$result = $this->dispatch( $type, $params );

			$result['action_type'] = $type;

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
