<?php
/**
 * Targeted verification for reroute telemetry and repeated discovery misfires.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-reroute-and-misfire.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $key ) ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $type, $gmt );
		return '2026-04-05 12:00:00';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		static $counter = 1;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter++ );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! class_exists( 'PressArk_Action_Logger' ) ) {
	class PressArk_Action_Logger {}
}

if ( ! class_exists( 'PressArk_AI_Connector' ) ) {
	class PressArk_AI_Connector {}
}

if ( ! class_exists( 'PressArk_Token_Budget_Manager' ) ) {
	class PressArk_Token_Budget_Manager {}
}

if ( ! class_exists( 'PressArk_Activity_Event_Store' ) ) {
	class PressArk_Activity_Event_Store {
		public static array $events = array();

		public function record( array $event ): bool {
			self::$events[] = $event;
			return true;
		}
	}
}

if ( ! class_exists( 'PressArk_Error_Tracker' ) ) {
	class PressArk_Error_Tracker {
		public static array $logs = array();

		public static function info( string $component, string $message, array $context = array() ): void {
			self::$logs[] = compact( 'component', 'message', 'context' );
		}

		public static function error( string $component, string $message, array $context = array() ): void {
			self::$logs[] = compact( 'component', 'message', 'context' );
		}

		public static function critical( string $component, string $message, array $context = array() ): void {
			self::$logs[] = compact( 'component', 'message', 'context' );
		}
	}
}

if ( ! class_exists( 'PressArk_Operation' ) ) {
	class PressArk_Operation {
		public string $name = '';
		public string $group = 'content';
		public string $risk = 'safe';
	}
}

if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
	class PressArk_Operation_Registry {
		public static function resolve_alias( string $name ): string {
			return $name;
		}

		public static function validate_input( string $name, array $params ): array {
			unset( $name, $params );
			return array( 'valid' => true );
		}

		public static function get_group( string $name ): string {
			return match ( $name ) {
				'search_content', 'read_content' => 'content',
				default => 'content',
			};
		}

		public static function classify( string $name, array $args = array() ): string {
			unset( $args );
			return 'read_content' === $name ? 'read' : 'preview';
		}

		public static function resolve( string $name ): ?PressArk_Operation {
			$op = new PressArk_Operation();
			$op->name = $name;
			$op->group = self::get_group( $name );
			$op->risk = 'read_content' === $name ? 'safe' : 'moderate';
			return $op;
		}

		public static function get_policy_hooks( string $name, string $phase ): array {
			unset( $name, $phase );
			return array();
		}

		public static function group_names(): array {
			return array( 'content', 'settings', 'seo' );
		}
	}
}

if ( ! class_exists( 'PressArk_Handler_Registry' ) ) {
	class PressArk_Handler_Registry {
		public static array $dispatches = array();

		public function __construct( PressArk_Action_Logger $logger ) {
			unset( $logger );
		}

		public function set_async_context( string $task_id ): void {
			unset( $task_id );
		}

		public function dispatch( PressArk_Operation $operation, array $params ): array {
			self::$dispatches[] = array(
				'tool'   => $operation->name,
				'params' => $params,
			);

			return array(
				'success' => true,
				'message' => 'Executed ' . $operation->name,
				'data'    => array( 'tool' => $operation->name ),
			);
		}
	}
}

if ( ! class_exists( 'PressArk_License' ) ) {
	class PressArk_License {
		public function get_tier(): string {
			return 'pro';
		}
	}
}

if ( ! class_exists( 'PressArk_Entitlements' ) ) {
	class PressArk_Entitlements {
		public static function check_group_usage( string $tier, string $group, string $tool_capability ): array {
			unset( $tier, $group, $tool_capability );
			return array( 'allowed' => true );
		}

		public static function record_group_usage( string $group ): void {
			unset( $group );
		}
	}
}

if ( ! class_exists( 'PressArk_Permission_Service' ) ) {
	class PressArk_Permission_Service {
		public static function build_entitlement_denial(
			string $type,
			string $context,
			array $meta,
			string $group,
			string $tool_capability,
			string $risk,
			string $tier,
			array $usage_check
		): array {
			unset( $type, $context, $meta, $group, $tool_capability, $risk, $tier, $usage_check );
			return array( 'verdict' => 'deny' );
		}
	}
}

if ( ! class_exists( 'PressArk_Policy_Engine' ) ) {
	class PressArk_Policy_Engine {
		public const CONTEXT_AGENT_READ  = 'agent_read';
		public const CONTEXT_INTERACTIVE = 'interactive';

		public static function evaluate( string $type, array $params = array(), string $context = self::CONTEXT_INTERACTIVE ): array {
			unset( $type, $params, $context );
			return array( 'verdict' => 'allow', 'reasons' => array() );
		}

		public static function is_denied( array $verdict ): bool {
			return 'deny' === ( $verdict['verdict'] ?? '' );
		}

		public static function is_ask( array $verdict ): bool {
			return 'ask' === ( $verdict['verdict'] ?? '' );
		}

		public static function pre_operation( string $type, array $params, string $context ): array {
			unset( $type, $context );
			return array(
				'proceed' => true,
				'params'  => $params,
			);
		}

		public static function post_operation( string $type, array $result, array $params, string $context ): array {
			unset( $type, $params, $context );
			return $result;
		}
	}
}

if ( ! class_exists( 'PressArk_Preflight' ) ) {
	class PressArk_Preflight {
		public const ACTION_PROCEED = 'proceed';
		public const ACTION_BLOCK   = 'block';
		public const ACTION_REROUTE = 'reroute';
		public const ACTION_REWRITE = 'rewrite';

		public static array $next = array(
			'action' => self::ACTION_PROCEED,
		);

		public static function check( string $type, array $params ): array {
			unset( $params );
			if ( 'search_content' === $type ) {
				return self::$next;
			}
			return array( 'action' => self::ACTION_PROCEED );
		}
	}
}

if ( ! class_exists( 'PressArk_Tool_Catalog' ) ) {
	class PressArk_Tool_Catalog {
		private static ?self $instance = null;

		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function discover( string $query, array $loaded_names = array(), array $options = array() ): array {
			unset( $query, $loaded_names, $options );
			return array();
		}

		public function match_groups( string $query, array $conversation = array() ): array {
			unset( $conversation );
			return false !== strpos( $query, 'billing' ) ? array( 'settings' ) : array( 'content' );
		}
	}
}

if ( ! class_exists( 'PressArk_Tool_Loader' ) ) {
	class PressArk_Tool_Loader {
		public function expand( array $tool_set, string $group, array $options = array() ): array {
			unset( $group, $options );
			return $tool_set;
		}

		public function expand_tools( array $tool_set, array $tools, array $options = array() ): array {
			unset( $tools, $options );
			return $tool_set;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-activity-trace.php';
require_once dirname( __DIR__ ) . '/includes/class-action-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-agent.php';

$passed = 0;
$failed = 0;

function assert_telemetry( string $label, bool $condition, string $detail = '' ): void {
	global $passed, $failed;

	if ( $condition ) {
		$passed++;
		echo "  PASS: {$label}\n";
		return;
	}

	$failed++;
	echo "  FAIL: {$label}\n";
	if ( '' !== $detail ) {
		echo "    {$detail}\n";
	}
}

function get_private_property_value( object $object, string $property ) {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );
	return $reflection->getValue( $object );
}

echo "=== Reroute And Misfire Tests ===\n\n";

PressArk_Activity_Event_Store::$events = array();
PressArk_Handler_Registry::$dispatches = array();
PressArk_Preflight::$next = array(
	'action' => PressArk_Preflight::ACTION_REROUTE,
	'tool'   => 'read_content',
	'params' => array( 'post_id' => 42 ),
	'reason' => 'Canonical content reads should use read_content.',
	'hint'   => 'Use read_content for evidence-first reads.',
	'family' => 'content',
);

$engine = new PressArk_Action_Engine( new PressArk_Action_Logger() );
$result = $engine->execute_single(
	array(
		'type'   => 'search_content',
		'params' => array( 'query' => 'alpha' ),
	)
);

$reroute_event = PressArk_Activity_Event_Store::$events[0] ?? array();

assert_telemetry(
	'Preflight reroute preserves execution and annotates the result',
	! empty( $result['success'] )
		&& 'read_content' === ( $result['action_type'] ?? '' )
		&& 'search_content' === ( $result['preflight_reroute']['original_tool'] ?? '' )
		&& 'read_content' === ( $result['preflight_reroute']['rerouted_to'] ?? '' ),
	'Result: ' . var_export( $result, true )
);
assert_telemetry(
	'Reroute telemetry is durably published',
	'tool.rerouted' === ( $reroute_event['event_type'] ?? '' )
		&& 'preflight_reroute' === ( $reroute_event['reason'] ?? '' )
		&& 'search_content' === ( $reroute_event['payload']['from'] ?? '' )
		&& 'read_content' === ( $reroute_event['payload']['to'] ?? '' ),
	'Event: ' . var_export( $reroute_event, true )
);

$agent = new PressArk_Agent( new PressArk_AI_Connector(), $engine, 'pro' );
$loader = new PressArk_Tool_Loader();
$tool_set = array(
	'tool_names' => array(),
	'groups'     => array(),
	'schemas'    => array(),
);
$tool_defs = array();

$handle_meta_tool = new ReflectionMethod( PressArk_Agent::class, 'handle_meta_tool' );
$handle_meta_tool->setAccessible( true );

$last_result = null;
for ( $i = 1; $i <= 3; $i++ ) {
	$last_result = $handle_meta_tool->invokeArgs(
		$agent,
		array(
			array(
				'id'        => 'toolu_' . $i,
				'name'      => 'discover_tools',
				'arguments' => array(
					'query' => 'hidden billing authority',
				),
			),
			$loader,
			&$tool_set,
			&$tool_defs,
		)
	);
}

$activity_events = (array) get_private_property_value( $agent, 'activity_events' );
$last_event      = $activity_events[ count( $activity_events ) - 1 ] ?? array();

assert_telemetry(
	'Repeated zero-hit discovery upgrades to repeated-misfire telemetry',
	'discover_repeated_misfire' === ( $last_event['reason'] ?? '' )
		&& 3 === (int) ( $last_event['payload']['zero_hit_count'] ?? 0 ),
	'Activity events: ' . var_export( $activity_events, true )
);
assert_telemetry(
	'Repeated misfires return the operator-facing load-groups hint',
	false !== strpos( (string) ( $last_result['result']['message'] ?? '' ), 'Available tool groups you can load directly' ),
	'Last result: ' . var_export( $last_result, true )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
