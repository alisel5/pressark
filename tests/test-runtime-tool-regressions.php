<?php
/**
 * Focused regressions for runtime tool edge cases found by the Docker harness.
 *
 * Run: php pressark/tests/test-runtime-tool-regressions.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

$GLOBALS['pressark_test_home_url']        = 'http://localhost:8080/';
$GLOBALS['pressark_test_removed_actions'] = array();
$GLOBALS['wp_filter']                     = array();

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		$base = rtrim( (string) $GLOBALS['pressark_test_home_url'], '/' );
		$path = (string) $path;
		if ( '' === $path || '/' === $path ) {
			return $base . '/';
		}
		return $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		unset( $tag, $args );
		return $value;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $tag, $callback, $priority = 10 ) {
		$GLOBALS['pressark_test_removed_actions'][] = array(
			'tag'      => $tag,
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}

if ( ! class_exists( 'PressArk_Action_Logger' ) ) {
	class PressArk_Action_Logger {}
}

if ( ! class_exists( 'PressArk_Task_Store' ) ) {
	class PressArk_Task_Store {
		public function has_receipt( string $task_id, string $operation_key ): bool {
			unset( $task_id, $operation_key );
			return false;
		}

		public function record_receipt( string $task_id, string $operation_key, string $summary = '' ): void {
			unset( $task_id, $operation_key, $summary );
		}
	}
}

if ( ! class_exists( 'WP_Customize_Manager' ) ) {
	class WP_Customize_Manager {
		public array $calls = array();

		public function setup_theme(): void {
			$this->calls[] = 'setup_theme';
		}

		public function after_setup_theme(): void {
			$this->calls[] = 'after_setup_theme';
		}

		public function register_controls(): void {
			$this->calls[] = 'register_controls';
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/handlers/class-handler-base.php';
require_once dirname( __DIR__ ) . '/includes/handlers/class-handler-system.php';
require_once dirname( __DIR__ ) . '/includes/handlers/class-handler-woocommerce.php';

$passed = 0;
$failed = 0;

function assert_true_runtime( string $label, bool $condition, string $detail = '' ): void {
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

function assert_same_runtime( string $label, $expected, $actual ): void {
	assert_true_runtime(
		$label,
		$expected === $actual,
		'Expected: ' . var_export( $expected, true ) . ' | Actual: ' . var_export( $actual, true )
	);
}

function assert_contains_runtime( string $label, string $needle, string $haystack ): void {
	assert_true_runtime(
		$label,
		false !== strpos( $haystack, $needle ),
		'Missing substring: ' . $needle . ' in ' . var_export( $haystack, true )
	);
}

echo "=== Runtime Tool Regression Tests ===\n\n";

echo "--- Test 1: measure_page_speed builds canonical and internal loopback candidates ---\n";
$diagnostics   = new PressArk_Diagnostics();
$plans_method  = new ReflectionMethod( PressArk_Diagnostics::class, 'build_measurement_fetch_plans' );
$error_method  = new ReflectionMethod( PressArk_Diagnostics::class, 'format_measure_page_speed_fetch_error' );
$plans_method->setAccessible( true );
$error_method->setAccessible( true );

$plans          = $plans_method->invoke( $diagnostics, 'http://localhost/', 'http://localhost:8080/' );
$transport_urls = array_map(
	static function ( array $plan ): string {
		return (string) ( $plan['transport_url'] ?? '' );
	},
	$plans
);

assert_true_runtime(
	'direct request URL remains the first candidate',
	isset( $transport_urls[0] ) && 'http://localhost/' === $transport_urls[0],
	var_export( $plans, true )
);
assert_true_runtime(
	'canonical site URL is included as a candidate',
	in_array( 'http://localhost:8080/', $transport_urls, true ),
	var_export( $plans, true )
);
assert_true_runtime(
	'host.docker.internal fallback is included for loopback sites',
	in_array( 'http://host.docker.internal:8080/', $transport_urls, true ),
	var_export( $plans, true )
);

$docker_plan = array();
foreach ( $plans as $plan ) {
	if ( 'http://host.docker.internal:8080/' === ( $plan['transport_url'] ?? '' ) ) {
		$docker_plan = $plan;
		break;
	}
}

assert_same_runtime(
	'loopback transport preserves the public Host header',
	'localhost:8080',
	$docker_plan['headers']['Host'] ?? null
);
assert_contains_runtime(
	'loopback failures are reported clearly',
	'Loopback request could not reach this site from the current runtime.',
	(string) $error_method->invoke( $diagnostics, 'http://localhost/', 'Request failed: connection refused' )
);

echo "\n--- Test 2: Customizer bootstrap preloads missing support callbacks once ---\n";
$system          = new PressArk_Handler_System( new PressArk_Action_Logger() );
$bootstrap       = new ReflectionMethod( PressArk_Handler_System::class, 'bootstrap_customizer_manager' );
$prime_callbacks = new ReflectionMethod( PressArk_Handler_System::class, 'prime_customizer_support_loaders' );
$bootstrap->setAccessible( true );
$prime_callbacks->setAccessible( true );

$manager = new WP_Customize_Manager();
$bootstrap->invoke( $system, $manager );

assert_same_runtime(
	'bootstrap runs setup hooks before schema discovery',
	array( 'setup_theme', 'after_setup_theme', 'register_controls' ),
	$manager->calls
);
assert_same_runtime(
	'bootstrap removes the duplicate register_controls action',
	'customize_register',
	$GLOBALS['pressark_test_removed_actions'][0]['tag'] ?? null
);

class PressArk_Test_Customizer_Loader {
	public int $include_calls = 0;

	public function customize_register() {}

	public function include_configurations(): void {
		$this->include_calls++;
	}
}

class PressArk_Test_Customizer_Loader_Already_Hooked {
	public int $include_calls = 0;

	public function include_configurations(): void {
		$this->include_calls++;
	}
}

$missing_loader = new PressArk_Test_Customizer_Loader();
$hooked_loader  = new PressArk_Test_Customizer_Loader_Already_Hooked();
$GLOBALS['wp_filter']['customize_register'] = (object) array(
	'callbacks' => array(
		10 => array(
			array( 'function' => array( $missing_loader, 'customize_register' ) ),
			array( 'function' => array( $hooked_loader, 'include_configurations' ) ),
		),
	),
);

$prime_callbacks->invoke( $system, $manager );

assert_same_runtime(
	'missing include_configurations callback is primed exactly once',
	1,
	$missing_loader->include_calls
);
assert_same_runtime(
	'already-hooked include_configurations callbacks are not double-invoked',
	0,
	$hooked_loader->include_calls
);

echo "\n--- Test 3: WooCommerce analytics payloads are normalized recursively ---\n";
$woo              = new PressArk_Handler_WooCommerce( new PressArk_Action_Logger() );
$normalize_method = new ReflectionMethod( PressArk_Handler_WooCommerce::class, 'normalize_wc_analytics_payload' );
$normalize_method->setAccessible( true );

$normalized = $normalize_method->invoke(
	$woo,
	array(
		'totals'    => (object) array( 'orders_count' => 4 ),
		'intervals' => array(
			array(
				'subtotals' => (object) array( 'total_sales' => 99.95 ),
			),
		),
	)
);

assert_same_runtime(
	'totals object becomes an associative array',
	array( 'orders_count' => 4 ),
	$normalized['totals'] ?? null
);
assert_same_runtime(
	'nested subtotals objects also become arrays',
	array( 'total_sales' => 99.95 ),
	$normalized['intervals'][0]['subtotals'] ?? null
);

echo "\n--- Test 4: email_customer surfaces environmental mail transport failures clearly ---\n";
$mail_failure_method = new ReflectionMethod( PressArk_Handler_WooCommerce::class, 'build_email_delivery_failure' );
$mail_failure_method->setAccessible( true );
$mail_failure = $mail_failure_method->invoke(
	$woo,
	new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' )
);

assert_same_runtime(
	'mail transport failures are marked environmental',
	true,
	(bool) ( $mail_failure['environmental'] ?? false )
);
assert_same_runtime(
	'mail transport failures preserve the failure code',
	'wp_mail_failed',
	$mail_failure['failure_code'] ?? null
);
assert_contains_runtime(
	'mail transport failure message explains the environment issue',
	'mail transport',
	(string) ( $mail_failure['message'] ?? '' )
);
assert_contains_runtime(
	'mail transport failure hint tells operators how to recover',
	'Configure SMTP/sendmail',
	(string) ( $mail_failure['hint'] ?? '' )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
