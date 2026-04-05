<?php
/**
 * PressArk policy and permission visibility verification tests.
 *
 * Run with: php pressark/tests/test-policy-engine.php
 *
 * This standalone file exercises:
 *   1. DENY verdict - custom rule blocks an operation
 *   2. ASK verdict - custom rule forces confirmation
 *   3. ALLOW verdict - custom rule explicitly allows
 *   4. Default fallback - no custom rules, reads allowed, unregistered denied
 *   5. Deny-first - deny wins over allow at same priority
 *   6. Ask over allow - ask wins when both match
 *   7. Wildcard matching - operation name prefix matching
 *   8. Callable rules - dynamic context-based rules
 *   9. Context filtering - rules limited to specific contexts
 *  10. Global pre-operation hook blocks execution
 *  11. Default destructive -> ask
 *  12. Automation fallback parity
 *  13. Canonical PermissionDecision shape
 *  14. Effective visible tools hide entitlement-blocked tools early
 *  15. Automation visibility still blocks dangerous operations
 *  16. Run pause snapshots persist permission visibility state
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $type, $gmt );
		return gmdate( 'Y-m-d H:i:s' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-permission-decision.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-policy-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-permission-service.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-policy-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-tool-catalog.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-tool-loader.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-run-store.php';
require_once __DIR__ . '/helpers/harness-fixtures.php';

if ( ! class_exists( 'PressArk_Operation' ) ) {
	class PressArk_Operation {
		public string $name = '';
		public string $capability = 'read';
		public string $group = 'core';
		public string $risk = 'safe';
		public string $description = '';
		public bool $always_load = false;
		public bool $meta = false;

		public function is_always_load(): bool {
			return $this->always_load;
		}

		public function is_meta(): bool {
			return $this->meta;
		}
	}
}

if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
	class PressArk_Operation_Registry {
		private static array $ops = array(
			'read_content'      => array( 'capability' => 'read',    'group' => 'core',       'risk' => 'safe',        'description' => 'Read content',      'always_load' => true ),
			'edit_content'      => array( 'capability' => 'preview', 'group' => 'core',       'risk' => 'moderate',    'description' => 'Edit content' ),
			'delete_content'    => array( 'capability' => 'confirm', 'group' => 'core',       'risk' => 'destructive', 'description' => 'Delete content' ),
			'create_post'       => array( 'capability' => 'preview', 'group' => 'core',       'risk' => 'moderate',    'description' => 'Create post' ),
			'fix_seo'           => array( 'capability' => 'preview', 'group' => 'seo',        'risk' => 'moderate',    'description' => 'Fix SEO' ),
			'edit_product'      => array( 'capability' => 'confirm', 'group' => 'woocommerce','risk' => 'moderate',    'description' => 'Edit product' ),
			'edit_product_meta' => array( 'capability' => 'confirm', 'group' => 'woocommerce','risk' => 'moderate',    'description' => 'Edit product meta' ),
			'list_plugins'      => array( 'capability' => 'read',    'group' => 'plugins',    'risk' => 'safe',        'description' => 'List plugins' ),
			'toggle_plugin'     => array( 'capability' => 'confirm', 'group' => 'plugins',    'risk' => 'moderate',    'description' => 'Toggle plugin' ),
			'discover_tools'    => array( 'capability' => 'read',    'group' => 'discovery',  'risk' => 'safe',        'description' => 'Discover tools',    'always_load' => true, 'meta' => true ),
			'load_tools'        => array( 'capability' => 'read',    'group' => 'discovery',  'risk' => 'safe',        'description' => 'Load tools',        'always_load' => true, 'meta' => true ),
		);

		public static function resolve( string $name ): ?PressArk_Operation {
			if ( ! isset( self::$ops[ $name ] ) ) {
				return null;
			}
			$op = new PressArk_Operation();
			$op->name        = $name;
			$op->capability  = self::$ops[ $name ]['capability'];
			$op->group       = self::$ops[ $name ]['group'];
			$op->risk        = self::$ops[ $name ]['risk'];
			$op->description = self::$ops[ $name ]['description'] ?? '';
			$op->always_load = ! empty( self::$ops[ $name ]['always_load'] );
			$op->meta        = ! empty( self::$ops[ $name ]['meta'] );
			return $op;
		}

		public static function exists( string $name ): bool {
			return isset( self::$ops[ $name ] );
		}

		public static function classify( string $name, array $args = array() ): string {
			unset( $args );
			return self::$ops[ $name ]['capability'] ?? 'unknown';
		}

		public static function get_group( string $name ): string {
			return self::$ops[ $name ]['group'] ?? '';
		}

		public static function all(): array {
			$all = array();
			foreach ( array_keys( self::$ops ) as $name ) {
				$all[ $name ] = self::resolve( $name );
			}
			return $all;
		}

		public static function group_names(): array {
			$groups = array();
			foreach ( self::$ops as $op ) {
				$groups[] = $op['group'];
			}
			return array_values( array_unique( $groups ) );
		}

		public static function tool_names_for_group( string $group ): array {
			$names = array();
			foreach ( self::$ops as $name => $op ) {
				if ( $group === $op['group'] ) {
					$names[] = $name;
				}
			}
			return $names;
		}

		public static function is_valid_group( string $group ): bool {
			return in_array( $group, self::group_names(), true );
		}

		public static function get_aliases(): array {
			return array();
		}

		public static function resolve_alias( string $name ): string {
			return $name;
		}
	}
}

if ( ! class_exists( 'PressArk_Automation_Policy' ) ) {
	class PressArk_Automation_Policy {
		public const POLICY_EDITORIAL = 'editorial';

		public static function all_policies(): array {
			return array(
				self::POLICY_EDITORIAL,
				'merchandising',
				'full',
			);
		}

		public static function check( string $op, string $policy, array $args = array() ): array {
			unset( $args );
			$decision = self::decision( $op, $policy );
			return array(
				'allowed'             => PressArk_Permission_Decision::is_allowed( $decision ),
				'reason'              => implode( ' ', (array) ( $decision['reasons'] ?? array() ) ),
				'permission_decision' => $decision,
			);
		}

		public static function decision( string $op, string $policy, array $args = array() ): array {
			unset( $args );
			if ( self::POLICY_EDITORIAL === $policy && 'edit_content' === $op ) {
				return PressArk_Permission_Decision::with_visibility(
					PressArk_Permission_Decision::create(
						PressArk_Permission_Decision::ALLOW,
						'Allowed by editorial automation policy.',
						'automation_policy',
						array(
							'operation'  => $op,
							'context'    => PressArk_Policy_Engine::CONTEXT_AUTOMATION,
							'provenance' => array(
								'authority' => 'automation_policy',
								'source'    => $policy,
								'kind'      => 'policy',
							),
						)
					),
					true
				);
			}

			$decision = PressArk_Permission_Decision::create(
				PressArk_Permission_Decision::DENY,
				"Blocked by {$policy} policy.",
				'automation_policy',
				array(
					'operation'  => $op,
					'context'    => PressArk_Policy_Engine::CONTEXT_AUTOMATION,
					'provenance' => array(
						'authority' => 'automation_policy',
						'source'    => $policy,
						'kind'      => 'policy',
					),
				)
			);
			$decision = PressArk_Permission_Decision::with_approval(
				$decision,
				true,
				PressArk_Permission_Decision::APPROVAL_UNAVAILABLE,
				false
			);
			return PressArk_Permission_Decision::with_visibility( $decision, false, array( 'approval_blocked', 'denied' ) );
		}
	}
}

if ( ! class_exists( 'PressArk_License' ) ) {
	class PressArk_License {
		public static string $tier = 'free';

		public function get_tier(): string {
			return self::$tier;
		}
	}
}

if ( ! class_exists( 'PressArk_Entitlements' ) ) {
	class PressArk_Entitlements {
		public const UNLIMITED_GROUPS = array( 'discovery', 'core' );
		public static bool $limit_exhausted = false;

		public static function is_paid_tier( string $tier ): bool {
			return in_array( $tier, array( 'pro', 'business', 'enterprise' ), true );
		}

		public static function check_group_usage( string $tier, string $group, string $tool_capability ): array {
			if ( self::is_paid_tier( $tier ) ) {
				return array( 'allowed' => true, 'basis' => 'paid_tier' );
			}
			if ( 'read' === $tool_capability ) {
				return array( 'allowed' => true, 'basis' => 'read' );
			}
			if ( in_array( $group, self::UNLIMITED_GROUPS, true ) ) {
				return array( 'allowed' => true, 'basis' => 'unlimited_group' );
			}
			if ( self::$limit_exhausted ) {
				return array(
					'allowed' => false,
					'message' => 'Weekly non-read tool limit reached.',
					'error'   => 'entitlement_denied',
					'used'    => 6,
					'limit'   => 6,
					'basis'   => 'group_limit_exhausted',
				);
			}
			return array( 'allowed' => true, 'remaining' => 1, 'basis' => 'weekly_remaining' );
		}
	}
}

if ( ! class_exists( 'PressArk_Capability_Bridge' ) ) {
	class PressArk_Capability_Bridge {
		public static function get_context_resources( array $loaded_groups = array(), string $detail = 'full' ): string {
			unset( $loaded_groups, $detail );
			return '';
		}
	}
}

if ( ! class_exists( 'PressArk_Resource_Registry' ) ) {
	class PressArk_Resource_Registry {
		public static function search( string $query ): array {
			unset( $query );
			return array();
		}
	}
}

if ( ! class_exists( 'PressArk_Tools' ) ) {
	class PressArk_Tools {
		public static function get_all( bool $has_woo = false, bool $has_elementor = false ): array {
			unset( $has_woo, $has_elementor );
			$tools = array();
			foreach ( PressArk_Operation_Registry::all() as $op ) {
				$tools[] = array(
					'name'        => $op->name,
					'description' => $op->description,
				);
			}
			return $tools;
		}

		public function get_all_tools(): array {
			return array_map( array( self::class, 'tool_to_schema' ), self::get_all() );
		}

		public static function tool_to_schema( array $tool ): array {
			return array(
				'type'     => 'function',
				'function' => array(
					'name'        => (string) ( $tool['name'] ?? '' ),
					'description' => (string) ( $tool['description'] ?? '' ),
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			);
		}
	}
}

if ( ! class_exists( 'PressArk_Token_Budget_Manager' ) ) {
	class PressArk_Token_Budget_Manager {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	$_test_filters = array();
	function add_filter( string $tag, callable $callback, int $priority = 10, int $args = 1 ): void {
		global $_test_filters;
		$_test_filters[ $tag ][] = array( 'callback' => $callback, 'args' => $args );
	}
	function apply_filters( string $tag, ...$args ) {
		global $_test_filters;
		$value = $args[0] ?? null;
		if ( ! empty( $_test_filters[ $tag ] ) ) {
			foreach ( $_test_filters[ $tag ] as $filter ) {
				$pass_args = array_slice( $args, 0, $filter['args'] );
				$value = call_user_func_array( $filter['callback'], $pass_args );
				$args[0] = $value;
			}
		}
		return $value;
	}
	function do_action( string $tag, ...$args ): void {
		global $_test_filters;
		if ( ! empty( $_test_filters[ $tag ] ) ) {
			foreach ( $_test_filters[ $tag ] as $filter ) {
				call_user_func_array( $filter['callback'], array_slice( $args, 0, $filter['args'] ) );
			}
		}
	}
	function remove_all_filters( string $tag ): void {
		global $_test_filters;
		unset( $_test_filters[ $tag ] );
	}
}

$passed = 0;
$failed = 0;

function assert_test( string $name, bool $condition, string $detail = '' ): void {
	global $passed, $failed;
	if ( $condition ) {
		echo "  PASS: {$name}\n";
		$passed++;
	} else {
		echo "  FAIL: {$name}" . ( $detail ? " - {$detail}" : '' ) . "\n";
		$failed++;
	}
}

echo "=== PressArk Policy Engine Tests ===\n\n";

function reset_test_state(): void {
	PressArk_Policy_Engine::flush_rules();
	PressArk_Permission_Service::flush_cache();
	remove_all_filters( 'pressark_policy_rules' );
	remove_all_filters( 'pressark_pre_operation' );
	remove_all_filters( 'pressark_post_operation' );
	remove_all_filters( 'pressark_policy_verdict' );
	remove_all_filters( 'pressark_operation_denied' );
	PressArk_License::$tier = 'free';
	PressArk_Entitlements::$limit_exhausted = false;
}

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::DENY,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'edit_content',
		'source'   => 'test',
		'reason'   => 'Editing blocked for testing.',
	);
	return $rules;
}, 10, 1 );
$verdict = PressArk_Policy_Engine::evaluate( 'edit_content', array( 'post_id' => 1 ) );
assert_test( '1. DENY - custom rule blocks operation', PressArk_Policy_Engine::is_denied( $verdict ) );
assert_test( '1. DENY - reason is preserved', str_contains( $verdict['reasons'][0], 'blocked for testing' ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ASK,
		'match'    => PressArk_Policy_Engine::MATCH_GROUP,
		'value'    => 'seo',
		'source'   => 'test',
		'reason'   => 'SEO changes need human review.',
	);
	return $rules;
}, 10, 1 );
$verdict = PressArk_Policy_Engine::evaluate( 'fix_seo' );
assert_test( '2. ASK - custom rule forces confirmation', PressArk_Policy_Engine::is_ask( $verdict ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ALLOW,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'create_post',
		'source'   => 'test',
		'reason'   => 'Post creation always allowed.',
	);
	return $rules;
}, 10, 1 );
$verdict = PressArk_Policy_Engine::evaluate( 'create_post' );
assert_test( '3. ALLOW - custom rule explicitly allows', PressArk_Policy_Engine::is_allowed( $verdict ) );

reset_test_state();
$verdict = PressArk_Policy_Engine::evaluate( 'read_content' );
assert_test( '4a. Default - reads are allowed', PressArk_Policy_Engine::is_allowed( $verdict ) );
$verdict = PressArk_Policy_Engine::evaluate( 'totally_unknown_op' );
assert_test( '4b. Default - unregistered ops are denied', PressArk_Policy_Engine::is_denied( $verdict ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ALLOW,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'edit_content',
		'priority' => 100,
		'source'   => 'test',
	);
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::DENY,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'edit_content',
		'priority' => 100,
		'source'   => 'test',
		'reason'   => 'Deny always wins.',
	);
	return $rules;
}, 10, 1 );
$verdict = PressArk_Policy_Engine::evaluate( 'edit_content' );
assert_test( '5. Deny-first - deny wins over allow at same priority', PressArk_Policy_Engine::is_denied( $verdict ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ALLOW,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'fix_seo',
		'priority' => 100,
		'source'   => 'test',
	);
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ASK,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'fix_seo',
		'priority' => 100,
		'source'   => 'test',
	);
	return $rules;
}, 10, 1 );
$verdict = PressArk_Policy_Engine::evaluate( 'fix_seo' );
assert_test( '6. Ask-over-allow - ask wins when both match', PressArk_Policy_Engine::is_ask( $verdict ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::DENY,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'edit_product*',
		'source'   => 'test',
		'reason'   => 'Product ops locked.',
	);
	return $rules;
}, 10, 1 );
$verdict1 = PressArk_Policy_Engine::evaluate( 'edit_product' );
$verdict2 = PressArk_Policy_Engine::evaluate( 'edit_product_meta' );
$verdict3 = PressArk_Policy_Engine::evaluate( 'create_post' );
assert_test( '7a. Wildcard - matches exact prefix', PressArk_Policy_Engine::is_denied( $verdict1 ) );
assert_test( '7b. Wildcard - matches extended name', PressArk_Policy_Engine::is_denied( $verdict2 ) );
assert_test( '7c. Wildcard - does not match unrelated', ! PressArk_Policy_Engine::is_denied( $verdict3 ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::DENY,
		'match'    => PressArk_Policy_Engine::MATCH_CALLABLE,
		'value'    => function ( $ctx ) {
			return ( $ctx['params']['post_id'] ?? 0 ) === 42;
		},
		'source'   => 'test',
		'reason'   => 'Post 42 is protected.',
	);
	return $rules;
}, 10, 1 );
$verdict_blocked = PressArk_Policy_Engine::evaluate( 'edit_content', array( 'post_id' => 42 ) );
$verdict_allowed = PressArk_Policy_Engine::evaluate( 'edit_content', array( 'post_id' => 99 ) );
assert_test( '8a. Callable - denies when condition met', PressArk_Policy_Engine::is_denied( $verdict_blocked ) );
assert_test( '8b. Callable - passes when condition not met', ! PressArk_Policy_Engine::is_denied( $verdict_allowed ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::DENY,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'edit_content',
		'source'   => 'test',
		'reason'   => 'Blocked only in automation.',
		'contexts' => array( PressArk_Policy_Engine::CONTEXT_AUTOMATION ),
	);
	return $rules;
}, 10, 1 );
$v_auto = PressArk_Policy_Engine::evaluate( 'edit_content', array(), PressArk_Policy_Engine::CONTEXT_AUTOMATION );
$v_interactive = PressArk_Policy_Engine::evaluate( 'edit_content', array(), PressArk_Policy_Engine::CONTEXT_INTERACTIVE );
assert_test( '9a. Context - rule applies in matching context', PressArk_Policy_Engine::is_denied( $v_auto ) );
assert_test( '9b. Context - rule skipped in other context', ! PressArk_Policy_Engine::is_denied( $v_interactive ) );

reset_test_state();
add_filter( 'pressark_pre_operation', function ( $params, $op_name ) {
	unset( $op_name );
	if ( ( $params['post_id'] ?? 0 ) === 1 ) {
		return null;
	}
	return $params;
}, 10, 2 );
$result_blocked = PressArk_Policy_Engine::pre_operation( 'edit_content', array( 'post_id' => 1 ) );
$result_ok      = PressArk_Policy_Engine::pre_operation( 'edit_content', array( 'post_id' => 5 ) );
assert_test( '10a. Pre-operation hook - blocks when null returned', ! $result_blocked['proceed'] );
assert_test( '10b. Pre-operation hook - passes when params returned', $result_ok['proceed'] );

reset_test_state();
$verdict = PressArk_Policy_Engine::evaluate( 'delete_content' );
assert_test( '11. Default - destructive operations require confirmation', PressArk_Policy_Engine::is_ask( $verdict ) );

reset_test_state();
$v_allowed = PressArk_Policy_Engine::evaluate(
	'edit_content',
	array(),
	PressArk_Policy_Engine::CONTEXT_AUTOMATION,
	array( 'policy' => 'editorial' )
);
$v_blocked = PressArk_Policy_Engine::evaluate(
	'edit_product',
	array(),
	PressArk_Policy_Engine::CONTEXT_AUTOMATION,
	array( 'policy' => 'editorial' )
);
assert_test( '12a. Automation fallback - editorial allows edit_content', PressArk_Policy_Engine::is_allowed( $v_allowed ) );
assert_test( '12b. Automation fallback - editorial denies edit_product', PressArk_Policy_Engine::is_denied( $v_blocked ) );

reset_test_state();
$decision = PressArk_Permission_Service::evaluate( 'edit_content', array(), PressArk_Policy_Engine::CONTEXT_INTERACTIVE );
assert_test( '13a. Decision contract is canonical', PressArk_Permission_Decision::CONTRACT === ( $decision['contract'] ?? '' ) );
assert_test( '13b. Interactive preview keeps preview approval mode', PressArk_Permission_Decision::APPROVAL_PREVIEW === ( $decision['approval']['mode'] ?? '' ) );
assert_test( '13c. Interactive preview tool remains visible', ! empty( $decision['visibility']['visible_to_model'] ) );

reset_test_state();
PressArk_Entitlements::$limit_exhausted = true;
$loader = new PressArk_Tool_Loader();
$tool_set = $loader->resolve(
	'Fix the SEO metadata',
	array(),
	'free',
	array( 'seo' ),
	array(
		'permission_context' => PressArk_Policy_Engine::CONTEXT_INTERACTIVE,
		'permission_meta'    => array( 'tier' => 'free' ),
	)
);
$decision = PressArk_Permission_Service::evaluate(
	'fix_seo',
	array(),
	PressArk_Policy_Engine::CONTEXT_INTERACTIVE,
	array( 'tier' => 'free' )
);
assert_test( '14a. Entitlement-blocked tool is hidden from effective visible tool set', ! in_array( 'fix_seo', $tool_set['tool_names'], true ) );
assert_test( '14b. Hidden tool still resolves to the same denied permission outcome', PressArk_Permission_Decision::is_denied( $decision ) );

reset_test_state();
PressArk_License::$tier = 'pro';
$automation_set = $loader->resolve(
	'Run unattended maintenance',
	array(),
	'pro',
	array( 'plugins', 'core' ),
	array(
		'permission_context' => PressArk_Policy_Engine::CONTEXT_AUTOMATION,
		'permission_meta'    => array(
			'tier'   => 'pro',
			'policy' => 'editorial',
		),
	)
);
$toggle_decision = PressArk_Permission_Service::evaluate(
	'toggle_plugin',
	array(),
	PressArk_Policy_Engine::CONTEXT_AUTOMATION,
	array(
		'tier'   => 'pro',
		'policy' => 'editorial',
	)
);
assert_test( '15a. Automation hides never-auto-approve plugin toggles before prompt exposure', ! in_array( 'toggle_plugin', $automation_set['tool_names'], true ) );
assert_test( '15b. Automation still blocks the same dangerous plugin toggle at decision time', PressArk_Permission_Decision::is_denied( $toggle_decision ) );
assert_test( '15c. Automation still exposes editorial-safe content edits', in_array( 'edit_content', $automation_set['tool_names'], true ) );

reset_test_state();
$pause_state = PressArk_Run_Store::build_pause_state(
	array(
		'checkpoint'              => array( 'goal' => 'Fix homepage SEO' ),
		'loaded_groups'           => array( 'seo' ),
		'effective_visible_tools' => array( 'read_content', 'discover_tools' ),
		'permission_surface'      => array(
			'visible_tools'     => array( 'read_content', 'discover_tools' ),
			'hidden_tools'      => array( 'fix_seo' ),
			'hidden_tool_count' => 1,
		),
	),
	'preview'
);
assert_test( '16a. Pause snapshot stores effective visible tools for replay/debug', array( 'read_content', 'discover_tools' ) === ( $pause_state['effective_visible_tools'] ?? array() ) );
assert_test( '16b. Pause snapshot stores permission surface for operators', 1 === (int) ( $pause_state['permission_surface']['hidden_tool_count'] ?? 0 ) );

reset_test_state();
add_filter( 'pressark_policy_rules', function ( $rules ) {
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ASK,
		'match'    => PressArk_Policy_Engine::MATCH_GROUP,
		'value'    => 'seo',
		'source'   => 'shared_policy',
	);
	$rules[] = array(
		'behavior' => PressArk_Policy_Engine::ALLOW,
		'match'    => PressArk_Policy_Engine::MATCH_OPERATION,
		'value'    => 'fix_seo',
		'source'   => 'local_exception',
	);
	return $rules;
}, 10, 1 );
$shadowed_rules = PressArk_Policy_Diagnostics::detect_shadowed_rules();
$first_shadow   = $shadowed_rules[0] ?? array();
$dead_groups    = PressArk_Policy_Diagnostics::detect_dead_group_combinations();
$has_dead_seo   = false;
foreach ( $dead_groups as $row ) {
	if ( 'seo' === ( $row['group'] ?? '' ) ) {
		$has_dead_seo = true;
		break;
	}
}
assert_test( '17a. Shadowed rules detect unreachable allow exceptions', ! empty( $shadowed_rules ) );
assert_test( '17b. Shadowed rules report ask-shadowing for the specific allow rule', 'ask' === ( $first_shadow['shadow_type'] ?? '' ) );
assert_test( '17c. Dead group combinations detect automation surfaces with no visible SEO tools', $has_dead_seo );

reset_test_state();
$report = PressArk_Policy_Diagnostics::build_report_from_events(
	array(
		array(
			'event_type' => 'policy.surface',
			'reason'     => 'requested_group_unreachable',
			'payload'    => array(
				'context'          => PressArk_Policy_Engine::CONTEXT_INTERACTIVE,
				'requested_groups' => array( 'seo' ),
				'visible_groups'   => array(),
				'hidden_tools'     => array( 'fix_seo' ),
				'hidden_summary'   => array( 'entitlement_denied' => 1 ),
				'hidden_decisions' => array(
					array(
						'tool'         => 'fix_seo',
						'reason_codes' => array( 'entitlement_denied' ),
					),
				),
			),
		),
		array(
			'event_type' => 'policy.denial',
			'reason'     => 'entitlement_denied',
			'payload'    => array(
				'operation'    => 'fix_seo',
				'group'        => 'seo',
				'context'      => PressArk_Policy_Engine::CONTEXT_INTERACTIVE,
				'source'       => 'entitlements',
				'reason_codes' => array( 'entitlement_denied' ),
			),
		),
		array(
			'event_type' => 'tool.discovery',
			'reason'     => 'discover_repeated_misfire',
			'payload'    => array(
				'query'              => 'fix seo title',
				'requested_families' => array( 'seo' ),
			),
		),
	),
	7
);
assert_test( '18a. Friction report aggregates repeatedly hidden tools', 'fix_seo' === ( $report['top_hidden_tools'][0]['tool'] ?? '' ) );
assert_test( '18b. Friction report aggregates repeatedly denied operations', 'fix_seo' === ( $report['top_denied_operations'][0]['operation'] ?? '' ) );
assert_test( '18c. Friction report tracks groups that were requested but never visible', 'seo' === ( $report['requested_never_visible_groups'][0]['group'] ?? '' ) );
assert_test( '18d. Friction report tracks discovery dead-end queries', 'fix seo title' === ( $report['discovery_dead_ends'][0]['query'] ?? '' ) );

function run_policy_fixture_scenario( array $fixture ): array {
	$input = (array) ( $fixture['input'] ?? array() );
	reset_test_state();

	if ( isset( $input['license_tier'] ) ) {
		PressArk_License::$tier = (string) $input['license_tier'];
	}
	PressArk_Entitlements::$limit_exhausted = ! empty( $input['limit_exhausted'] );

	$loader_input = (array) ( $input['loader'] ?? array() );
	$loader = new PressArk_Tool_Loader();
	$tool_set = $loader->resolve(
		(string) ( $loader_input['message'] ?? '' ),
		array(),
		(string) ( $loader_input['tier'] ?? PressArk_License::$tier ),
		(array) ( $loader_input['groups'] ?? array() ),
		(array) ( $loader_input['options'] ?? array() )
	);

	$decision_input = (array) ( $input['decision'] ?? array() );
	$decision = PressArk_Permission_Service::evaluate(
		(string) ( $decision_input['operation'] ?? '' ),
		(array) ( $decision_input['args'] ?? array() ),
		(string) ( $decision_input['context'] ?? PressArk_Policy_Engine::CONTEXT_INTERACTIVE ),
		(array) ( $decision_input['meta'] ?? array() )
	);

	return array(
		'tool_names'          => (array) ( $tool_set['tool_names'] ?? array() ),
		'permission_surface'  => (array) ( $tool_set['permission_surface'] ?? array() ),
		'decision'            => $decision,
	);
}

foreach ( pressark_test_load_json_fixtures( 'tests/fixtures/harness/permission' ) as $fixture ) {
	$actual = run_policy_fixture_scenario( $fixture );
	pressark_test_assert_fixture_expectations(
		'assert_test',
		'Fixture permission - ' . (string) ( $fixture['name'] ?? $fixture['_fixture_file'] ?? 'scenario' ),
		$actual,
		(array) ( $fixture['expect'] ?? array() )
	);
}

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit( $failed > 0 ? 1 : 0 );
