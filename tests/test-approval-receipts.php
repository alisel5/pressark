<?php
/**
 * Approval receipt settlement tests.
 *
 * Run: php pressark/tests/test-approval-receipts.php
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
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 5;
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ) {
		if ( $value instanceof WP_REST_Response ) {
			return $value;
		}
		return new WP_REST_Response( $value, 200 );
	}
}

class WP_REST_Request {
	private array $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	private array $data;
	private int $status;

	public function __construct( array $data = array(), int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data(): array {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}
}

class PressArk_Preview {
	public static array $discard_result = array();
	public static array $session_calls  = array();

	public function get_session_tool_calls( string $session_id ): array {
		unset( $session_id );
		return self::$session_calls;
	}

	public function discard( string $session_id ): array {
		unset( $session_id );
		return self::$discard_result;
	}
}

class PressArk_Run_Store {
	public function get_by_preview_session( string $session_id ) {
		unset( $session_id );
		return null;
	}

	public function settle( string $run_id, array $result ): void {
		unset( $run_id, $result );
	}
}

class PressArk_Error_Tracker {
	public static function error( string $component, string $message, array $context = array() ): void {
		unset( $component, $message, $context );
	}
}

require_once __DIR__ . '/../includes/class-pressark-permission-decision.php';
require_once __DIR__ . '/../includes/class-chat.php';

$passed = 0;
$failed = 0;

function assert_same_receipt( string $label, $expected, $actual ): void {
	global $passed, $failed;

	if ( $expected === $actual ) {
		$passed++;
		echo "  PASS: {$label}\n";
		return;
	}

	$failed++;
	echo "  FAIL: {$label}\n";
	echo '    Expected: ' . var_export( $expected, true ) . "\n";
	echo '    Actual:   ' . var_export( $actual, true ) . "\n";
}

function assert_true_receipt( string $label, bool $condition ): void {
	assert_same_receipt( $label, true, $condition );
}

$chat = new PressArk_Chat();

echo "=== Approval Receipt Tests ===\n\n";

echo "--- Failed preview discard does not claim settlement ---\n";
PressArk_Preview::$session_calls  = array(
	array(
		'name'      => 'update_post',
		'arguments' => array( 'post_id' => 42 ),
	),
);
PressArk_Preview::$discard_result = array(
	'success' => false,
	'message' => 'Discard failed.',
);

$failed_response = $chat->handle_preview_discard( new WP_REST_Request( array( 'session_id' => 'preview_fail' ) ) );
$failed_data     = $failed_response->get_data();

assert_same_receipt( 'failed discard keeps success false', false, (bool) ( $failed_data['success'] ?? true ) );
assert_same_receipt( 'failed discard keeps original message', 'Discard failed.', $failed_data['message'] ?? '' );
assert_true_receipt( 'failed discard omits approval outcome', ! array_key_exists( 'approval_outcome', $failed_data ) );
assert_true_receipt( 'failed discard omits approval receipt', ! array_key_exists( 'approval_receipt', $failed_data ) );
assert_true_receipt( 'failed discard omits legacy discarded flag', ! array_key_exists( 'discarded', $failed_data ) );

echo "\n--- Successful preview discard returns an acknowledged receipt ---\n";
PressArk_Preview::$discard_result = array(
	'success' => true,
	'message' => 'Preview discarded.',
);

$success_response = $chat->handle_preview_discard( new WP_REST_Request( array( 'session_id' => 'preview_ok' ) ) );
$success_data     = $success_response->get_data();
$receipt          = is_array( $success_data['approval_receipt'] ?? null ) ? $success_data['approval_receipt'] : array();
$outcome          = is_array( $success_data['approval_outcome'] ?? null ) ? $success_data['approval_outcome'] : array();

assert_same_receipt( 'successful discard returns success true', true, (bool) ( $success_data['success'] ?? false ) );
assert_same_receipt( 'successful discard outcome status', 'discarded', $outcome['status'] ?? '' );
assert_same_receipt( 'successful discard receipt contract', 'approval_receipt', $receipt['contract'] ?? '' );
assert_same_receipt( 'successful discard receipt status', 'discarded', $receipt['status'] ?? '' );
assert_same_receipt( 'successful discard receipt action', 'preview_apply', $receipt['action'] ?? '' );
assert_same_receipt( 'successful discard receipt scope', 'preview', $receipt['scope'] ?? '' );
assert_same_receipt( 'successful discard receipt acknowledged flag', true, (bool) ( $receipt['acknowledged'] ?? false ) );
assert_same_receipt( 'successful discard receipt settled flag', true, (bool) ( $receipt['settled'] ?? false ) );

echo "\n--- Cancelled runs drop approval-boundary state and settle as cancelled ---\n";
$mark_cancelled = new ReflectionMethod( PressArk_Chat::class, 'mark_result_cancelled' );
$mark_cancelled->setAccessible( true );
$cancelled = $mark_cancelled->invoke(
	$chat,
	array(
		'success'            => true,
		'message'            => 'Still running.',
		'type'               => 'preview',
		'pending_actions'    => array( array( 'action' => 'fix_seo' ) ),
		'preview_session_id' => 'preview_123',
		'preview_url'        => 'https://example.com/preview',
		'diff'               => array( 'changed' => true ),
		'workflow_state'     => array( 'stage' => 'confirm' ),
		'telemetry'          => array( 'token_count' => 12 ),
	),
	'cancelled',
	array(
		'action'      => 'preview_apply',
		'scope'       => 'preview',
		'source'      => 'chat',
		'actor'       => 'user',
		'reason_code' => 'user_cancelled',
		'message'     => 'The request was cancelled before completion.',
	)
);
$cancelled_outcome = (array) ( $cancelled['approval_outcome'] ?? array() );
$cancelled_receipt = (array) ( $cancelled['approval_receipt'] ?? array() );

assert_same_receipt( 'cancelled run returns final_response payload', 'final_response', $cancelled['type'] ?? '' );
assert_same_receipt( 'cancelled run flips success false', false, (bool) ( $cancelled['success'] ?? true ) );
assert_same_receipt( 'cancelled run keeps canonical cancelled flag', true, (bool) ( $cancelled['cancelled'] ?? false ) );
assert_same_receipt( 'cancelled run settles a typed cancelled outcome', 'cancelled', $cancelled_outcome['status'] ?? '' );
assert_same_receipt( 'cancelled run settles a cancelled receipt', 'cancelled', $cancelled_receipt['status'] ?? '' );
assert_true_receipt( 'cancelled run strips pending approval state', ! isset( $cancelled['pending_actions'] ) && ! isset( $cancelled['preview_session_id'] ) && ! isset( $cancelled['preview_url'] ) );
assert_true_receipt( 'cancelled run strips preview diff and workflow state', ! isset( $cancelled['diff'] ) && ! isset( $cancelled['workflow_state'] ) );
assert_true_receipt( 'cancelled run preserves unrelated telemetry', isset( $cancelled['telemetry']['token_count'] ) && 12 === (int) $cancelled['telemetry']['token_count'] );

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
