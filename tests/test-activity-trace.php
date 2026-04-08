<?php
/**
 * Targeted verification for canonical activity trace propagation.
 *
 * Run: php pressark/tests/test-activity-trace.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $key ) ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $type, $gmt );
		return '2026-04-04 12:00:00';
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
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['pressark_test_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['pressark_test_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl ) {
		unset( $key, $value, $ttl );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		unset( $key );
		return false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $key );
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://example.com';
	}
}

class PressArk_Activity_Event_Store {
	public static array $events = array();

	public function record( array $event ): bool {
		self::$events[] = $event;
		return true;
	}

	public function get_by_correlation( string $correlation_id, int $limit = 120 ): array {
		unset( $correlation_id, $limit );
		return array();
	}

	public function get_by_run( string $run_id, int $limit = 120 ): array {
		unset( $run_id, $limit );
		return array();
	}
}

require_once __DIR__ . '/../includes/class-pressark-activity-trace.php';
require_once __DIR__ . '/../includes/class-pressark-permission-decision.php';
require_once __DIR__ . '/../includes/class-pressark-error-tracker.php';
require_once __DIR__ . '/../includes/class-pressark-token-bank.php';

$passed = 0;
$failed = 0;

function assert_same_trace( string $label, $expected, $actual ): void {
	global $passed, $failed;
	if ( $expected === $actual ) {
		$passed++;
		echo "  PASS: {$label}\n";
	} else {
		$failed++;
		echo "  FAIL: {$label}\n";
		echo '    Expected: ' . var_export( $expected, true ) . "\n";
		echo '    Actual:   ' . var_export( $actual, true ) . "\n";
	}
}

function assert_true_trace( string $label, bool $condition ): void {
	assert_same_trace( $label, true, $condition );
}

echo "=== Canonical Activity Trace Tests ===\n\n";

echo "--- Buffered events keep the existing correlation spine ---\n";
PressArk_Activity_Event_Store::$events = array();
PressArk_Activity_Trace::clear_current_context();
PressArk_Activity_Trace::set_current_context(
	array(
		'correlation_id' => 'corr_trace_seed',
		'run_id'         => 'run_123',
		'reservation_id' => 'res_123',
		'route'          => 'agent',
	)
);

PressArk_Activity_Trace::publish_result_events(
	array(
		'run_id' => 'run_123',
		'type'   => 'final_response',
		'activity_events' => array(
			array(
				'event_type' => 'provider.fallback',
				'reason'     => 'fallback_model_policy',
				'status'     => 'degraded',
				'payload'    => array( 'round' => 2 ),
			),
			array(
				'event_type' => 'task.retry_scheduled',
				'reason'     => 'retry_async_failure',
				'status'     => 'retrying',
				'payload'    => array( 'attempt' => 2 ),
			),
		),
	),
	'agent'
);

assert_same_trace( 'Buffered fallback event keeps correlation id', 'corr_trace_seed', PressArk_Activity_Event_Store::$events[0]['correlation_id'] ?? '' );
assert_same_trace( 'Buffered retry event keeps correlation id', 'corr_trace_seed', PressArk_Activity_Event_Store::$events[1]['correlation_id'] ?? '' );
assert_same_trace( 'Phase-end event keeps correlation id', 'corr_trace_seed', PressArk_Activity_Event_Store::$events[2]['correlation_id'] ?? '' );
assert_same_trace( 'Fallback reason preserved', 'fallback_model_policy', PressArk_Activity_Event_Store::$events[0]['reason'] ?? '' );
assert_same_trace( 'Retry reason preserved', 'retry_async_failure', PressArk_Activity_Event_Store::$events[1]['reason'] ?? '' );

echo "\n--- Typed approval outcomes map to canonical terminal reasons ---\n";
$declined_reason = PressArk_Activity_Trace::infer_terminal_reason(
	array(
		'approval_outcome' => PressArk_Permission_Decision::approval_outcome(
			PressArk_Permission_Decision::OUTCOME_DECLINED,
			array( 'action' => 'fix_seo' )
		),
	)
);
$discarded_reason = PressArk_Activity_Trace::infer_terminal_reason(
	array(
		'approval_outcome' => PressArk_Permission_Decision::approval_outcome(
			PressArk_Permission_Decision::OUTCOME_DISCARDED,
			array( 'action' => 'preview_apply' )
		),
	)
);
$expired_reason = PressArk_Activity_Trace::infer_failure_reason( 'Preview or confirmation expired before any changes were applied.' );
assert_same_trace( 'Declined approval maps to approval_declined', 'approval_declined', $declined_reason );
assert_same_trace( 'Discarded preview maps to approval_discarded', 'approval_discarded', $discarded_reason );
assert_same_trace( 'Expired approval failures map to approval_expired', 'approval_expired', $expired_reason );

PressArk_Activity_Event_Store::$events = array();
PressArk_Activity_Trace::set_current_context(
	array(
		'correlation_id' => 'corr_outcome_seed',
		'run_id'         => 'run_outcome',
		'route'          => 'agent',
	)
);
PressArk_Activity_Trace::publish_result_events(
	array(
		'run_id'            => 'run_outcome',
		'type'              => 'final_response',
		'approval_outcome'  => PressArk_Permission_Decision::approval_outcome(
			PressArk_Permission_Decision::OUTCOME_DISCARDED,
			array( 'action' => 'preview_apply', 'actor' => 'user' )
		),
		'activity_events'   => array(),
	),
	'agent'
);
$phase_end = PressArk_Activity_Event_Store::$events[0] ?? array();
assert_same_trace( 'Phase-end reason preserves discarded outcome', 'approval_discarded', $phase_end['reason'] ?? '' );
assert_same_trace( 'Phase-end payload includes approval outcome status', 'discarded', $phase_end['payload']['approval_outcome'] ?? '' );

echo "\n--- Chat-facing summaries preserve richer canonical reasons ---\n";
$fallback_summary = PressArk_Activity_Trace::describe_event_for_chat(
	array(
		'event_type' => 'provider.fallback',
		'reason'     => 'fallback_model_policy',
		'status'     => 'degraded',
		'payload'    => array(
			'provider' => 'anthropic',
			'model'    => 'claude-sonnet',
		),
	)
);
$headroom_summary = PressArk_Activity_Trace::describe_event_for_chat(
	array(
		'event_type' => 'run.transition',
		'reason'     => 'degraded_request_headroom',
		'status'     => 'degraded',
		'summary'    => 'Context compacted after request headroom dropped below threshold.',
	)
);

assert_same_trace( 'fallback chat label', 'Fallback model used', $fallback_summary['label'] ?? '' );
assert_same_trace( 'fallback chat status', 'degraded', $fallback_summary['status'] ?? '' );
assert_same_trace( 'fallback chat detail preserves provider and model', 'anthropic / claude-sonnet', $fallback_summary['detail'] ?? '' );
assert_same_trace( 'headroom chat label', 'Context compacted to continue', $headroom_summary['label'] ?? '' );
assert_same_trace( 'headroom chat status', 'degraded', $headroom_summary['status'] ?? '' );
assert_same_trace( 'headroom chat source', 'trace', $headroom_summary['source'] ?? '' );

echo "\n--- Error tracker inherits correlation context for existing logs ---\n";
$GLOBALS['pressark_test_options'] = array();
PressArk_Error_Tracker::clear();
PressArk_Activity_Trace::set_current_context(
	array(
		'correlation_id' => 'corr_log_seed',
		'run_id'         => 'run_log',
		'task_id'        => 'task_log',
		'reservation_id' => 'res_log',
		'route'          => 'async',
	)
);
PressArk_Error_Tracker::warning( 'TaskQueue', 'Retry scheduled', array( 'failure_class' => 'provider_error' ) );
$buffer = PressArk_Error_Tracker::get_recent( 1 );
$context = $buffer[0]['context'] ?? array();

assert_same_trace( 'Error tracker carries correlation_id', 'corr_log_seed', $context['correlation_id'] ?? '' );
assert_same_trace( 'Error tracker carries run_id', 'run_log', $context['run_id'] ?? '' );
assert_same_trace( 'Error tracker carries task_id', 'task_log', $context['task_id'] ?? '' );
assert_same_trace( 'Error tracker carries route', 'async', $context['route'] ?? '' );

echo "\n--- Token bank headers inherit correlation ids and deterministic fallbacks ---\n";
$GLOBALS['pressark_test_options']['pressark_site_token'] = 'site_token_123';
PressArk_Activity_Trace::clear_current_context();
PressArk_Activity_Trace::set_current_context( array( 'correlation_id' => 'corr_header_seed' ) );
$bank = new PressArk_Token_Bank();
$bank_reflection = new ReflectionClass( $bank );
$headers_method = $bank_reflection->getMethod( 'auth_headers' );
$headers_method->setAccessible( true );
$merge_method = $bank_reflection->getMethod( 'merge_trace_context' );
$merge_method->setAccessible( true );

$headers = $headers_method->invoke( $bank, array() );
PressArk_Activity_Trace::clear_current_context();
$fallback = $merge_method->invoke( $bank, array( 'reservation_id' => 'reservation_seed' ) );

assert_same_trace( 'Auth headers include current correlation id', 'corr_header_seed', $headers['x-pressark-correlation-id'] ?? '' );
assert_same_trace( 'Auth headers preserve site token', 'site_token_123', $headers['x-pressark-token'] ?? '' );
assert_true_trace( 'Reservation fallback produces deterministic correlation id', 0 === strpos( (string) ( $fallback['correlation_id'] ?? '' ), 'corr_' ) );
assert_same_trace( 'Reservation fallback uses deterministic md5 suffix', 'corr_' . substr( md5( 'reservation_seed' ), 0, 32 ), $fallback['correlation_id'] ?? '' );

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
