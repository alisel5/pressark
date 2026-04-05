<?php
/**
 * Targeted verification for queue-native lineage and worker event logging.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-queue-lineage.php
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
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
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
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['pressark_current_user_id'] ?? 0;
	}
}
if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( $user_id ) {
		$GLOBALS['pressark_current_user_id'] = (int) $user_id;
		return true;
	}
}

class PressArk_AI_Connector {
	const FAILURE_PROVIDER_ERROR  = 'provider_error';
	const FAILURE_TRUNCATION      = 'truncation';
	const FAILURE_TOOL_ERROR      = 'tool_error';
	const FAILURE_BAD_RETRIEVAL   = 'bad_retrieval';
	const FAILURE_VALIDATION      = 'validation';
	const FAILURE_SIDE_EFFECT_RISK = 'side_effect_risk';
}

interface PressArk_Queue_Backend {
	public function schedule( string $task_id, int $delay = 0 ): bool;
	public function get_name(): string;
}

class PressArk_Queue_Cron implements PressArk_Queue_Backend {
	public static array $scheduled = array();

	public function schedule( string $task_id, int $delay = 0 ): bool {
		self::$scheduled[] = array(
			'task_id' => $task_id,
			'delay'   => $delay,
		);
		return true;
	}

	public function get_name(): string {
		return 'cron-test';
	}
}

class PressArk_Task_Store {
	public static array $rows = array();

	public function create_record( array $data ): array {
		$idempotency_key = $data['idempotency_key'] ?? null;
		if ( $idempotency_key ) {
			foreach ( self::$rows as $existing ) {
				if (
					( $existing['idempotency_key'] ?? null ) === $idempotency_key
					&& in_array( $existing['status'], array( 'queued', 'running' ), true )
				) {
					return array(
						'task_id'         => $existing['task_id'],
						'created'         => false,
						'reused_existing' => true,
						'error'           => '',
					);
				}
			}
		}

		$task_id = (string) $data['task_id'];
		self::$rows[ $task_id ] = array(
			'task_id'          => $task_id,
			'run_id'           => (string) ( $data['run_id'] ?? '' ),
			'parent_run_id'    => (string) ( $data['parent_run_id'] ?? '' ),
			'root_run_id'      => (string) ( $data['root_run_id'] ?? '' ),
			'user_id'          => (int) ( $data['user_id'] ?? 0 ),
			'message'          => (string) ( $data['message'] ?? '' ),
			'payload'          => is_array( $data['payload'] ?? null ) ? $data['payload'] : array(),
			'handoff_capsule'  => is_array( $data['handoff_capsule'] ?? null ) ? $data['handoff_capsule'] : array(),
			'reservation_id'   => (string) ( $data['reservation_id'] ?? '' ),
			'idempotency_key'  => $idempotency_key,
			'status'           => 'queued',
			'retries'          => 0,
			'max_retries'      => (int) ( $data['max_retries'] ?? 2 ),
			'fail_reason'      => '',
			'result'           => null,
			'created_at'       => current_time( 'mysql', true ),
			'started_at'       => null,
			'completed_at'     => null,
			'read_at'          => null,
		);

		return array(
			'task_id'         => $task_id,
			'created'         => true,
			'reused_existing' => false,
			'error'           => '',
		);
	}

	public function get( string $task_id ): ?array {
		return self::$rows[ $task_id ] ?? null;
	}

	public function claim( string $task_id ): bool {
		if ( empty( self::$rows[ $task_id ] ) || 'queued' !== self::$rows[ $task_id ]['status'] ) {
			return false;
		}
		self::$rows[ $task_id ]['status']     = 'running';
		self::$rows[ $task_id ]['started_at'] = current_time( 'mysql', true );
		return true;
	}

	public function update_payload( string $task_id, array $payload ): bool {
		if ( empty( self::$rows[ $task_id ] ) ) {
			return false;
		}
		self::$rows[ $task_id ]['payload'] = $payload;
		return true;
	}

	public function fail( string $task_id, string $reason ): bool {
		if ( empty( self::$rows[ $task_id ] ) ) {
			return false;
		}
		self::$rows[ $task_id ]['status']       = 'failed';
		self::$rows[ $task_id ]['fail_reason']  = $reason;
		self::$rows[ $task_id ]['completed_at'] = current_time( 'mysql', true );
		return true;
	}

	public function retry( string $task_id ): bool {
		if ( empty( self::$rows[ $task_id ] ) ) {
			return false;
		}
		self::$rows[ $task_id ]['retries']      = (int) self::$rows[ $task_id ]['retries'] + 1;
		self::$rows[ $task_id ]['status']       = 'queued';
		self::$rows[ $task_id ]['started_at']   = null;
		self::$rows[ $task_id ]['completed_at'] = null;
		return true;
	}

	public function defer( string $task_id ): bool {
		if ( empty( self::$rows[ $task_id ] ) || 'running' !== self::$rows[ $task_id ]['status'] ) {
			return false;
		}
		self::$rows[ $task_id ]['status']       = 'queued';
		self::$rows[ $task_id ]['started_at']   = null;
		self::$rows[ $task_id ]['completed_at'] = null;
		self::$rows[ $task_id ]['fail_reason']  = '';
		return true;
	}

	public function complete( string $task_id, array $result ): bool {
		if ( empty( self::$rows[ $task_id ] ) ) {
			return false;
		}
		self::$rows[ $task_id ]['status']       = 'complete';
		self::$rows[ $task_id ]['result']       = $result;
		self::$rows[ $task_id ]['completed_at'] = current_time( 'mysql', true );
		return true;
	}

	public function dead_letter( string $task_id, string $reason ): bool {
		if ( empty( self::$rows[ $task_id ] ) ) {
			return false;
		}
		self::$rows[ $task_id ]['status']       = 'dead_letter';
		self::$rows[ $task_id ]['fail_reason']  = $reason;
		self::$rows[ $task_id ]['completed_at'] = current_time( 'mysql', true );
		return true;
	}

	public function can_retry( array $task ): bool {
		return (int) ( $task['retries'] ?? 0 ) < (int) ( $task['max_retries'] ?? 0 );
	}

	public function fail_queued( string $task_id, string $reason ): bool {
		return $this->fail( $task_id, $reason );
	}
}

class PressArk_Run_Store {
	public static array $rows = array();

	public function get( string $run_id ): ?array {
		return self::$rows[ $run_id ] ?? null;
	}

	public function link_task( string $run_id, string $task_id ): bool {
		if ( empty( self::$rows[ $run_id ] ) ) {
			return false;
		}
		self::$rows[ $run_id ]['task_id'] = $task_id;
		return true;
	}
}

class PressArk_Activity_Trace {
	public static array $context = array();
	public static array $events  = array();

	public static function current_context(): array {
		return self::$context;
	}

	public static function clear_current_context(): void {
		self::$context = array();
	}

	public static function set_current_context( array $context ): void {
		self::$context = $context;
	}

	public static function publish( array $event, array $context = array() ): void {
		self::$events[] = array_merge( self::$context, $context, $event );
	}
}

class PressArk_Throttle {
	public static $next_slot = 'slot-default';
	public static int $active_slots = 1;

	public function acquire_slot( int $user_id, string $tier ) {
		unset( $user_id, $tier );
		return self::$next_slot;
	}

	public function active_slots( int $user_id ): int {
		unset( $user_id );
		return self::$active_slots;
	}
}

class PressArk_License {
	public function get_tier(): string {
		return 'pro';
	}
}

class PressArk_Reservation {
	public static array $failed = array();

	public function fail( string $reservation_id, string $reason ): void {
		self::$failed[] = array(
			'reservation_id' => $reservation_id,
			'reason'         => $reason,
		);
	}
}

class PressArk_Usage_Tracker {
	public function get_usage_data(): array {
		return array( 'icu_spent' => 0 );
	}
}

class PressArk_Pipeline {
	public static array $registered   = array();
	public static array $settled_runs = array();
	public static array $failed_runs  = array();
	public static int $cleanup_calls  = 0;

	public function __construct( $reservation, $tracker, $throttle, $tier ) {
		unset( $reservation, $tracker, $throttle, $tier );
	}

	public function register_resources( string $reservation_id, int $user_id, bool $keep_slot = true, string $slot_id = '' ): void {
		self::$registered[] = array(
			'reservation_id' => $reservation_id,
			'user_id'        => $user_id,
			'keep_slot'      => $keep_slot,
			'slot_id'        => $slot_id,
		);
	}

	public function build_pending_actions( array $pending_actions, callable $builder ): array {
		unset( $builder );
		return $pending_actions;
	}

	public function settle( array $result, string $route ) {
		unset( $result, $route );
		return 'settled';
	}

	public function track_usage( array $result, string $route ): void {
		unset( $result, $route );
	}

	public function cleanup(): void {
		self::$cleanup_calls++;
	}

	public static function settle_run( string $run_id, array $result ): array {
		self::$settled_runs[ $run_id ] = $result;
		if ( isset( PressArk_Run_Store::$rows[ $run_id ] ) ) {
			PressArk_Run_Store::$rows[ $run_id ]['status'] = 'settled';
			PressArk_Run_Store::$rows[ $run_id ]['result'] = $result;
		}
		$result['run_id'] = $run_id;
		return $result;
	}

	public static function fail_run( string $run_id, string $reason = '' ): void {
		self::$failed_runs[ $run_id ] = $reason;
		if ( isset( PressArk_Run_Store::$rows[ $run_id ] ) ) {
			PressArk_Run_Store::$rows[ $run_id ]['status'] = 'failed';
			PressArk_Run_Store::$rows[ $run_id ]['result'] = array( 'fail_reason' => $reason );
		}
	}
}

class PressArk_Chat_History {
	public function get_chat( int $chat_id ) {
		unset( $chat_id );
		return null;
	}
}

class PressArk_Error_Tracker {
	public static array $errors = array();

	public static function error( string $component, string $message, array $context = array() ): void {
		self::$errors[] = array(
			'level'     => 'error',
			'component' => $component,
			'message'   => $message,
			'context'   => $context,
		);
	}
}

class FakePressArkAgent {
	public static array $calls = array();

	public function set_async_context( string $task_id ): void {
		self::$calls[] = array( 'method' => 'set_async_context', 'task_id' => $task_id );
	}

	public function set_automation_context( array $automation ): void {
		self::$calls[] = array( 'method' => 'set_automation_context', 'automation' => $automation );
	}

	public function set_run_context( string $run_id, int $chat_id ): void {
		self::$calls[] = array( 'method' => 'set_run_context', 'run_id' => $run_id, 'chat_id' => $chat_id );
	}

	public function run(
		string $message,
		array $conversation,
		bool $deep_mode,
		string $screen,
		int $post_id,
		array $loaded_groups,
		$checkpoint_data
	): array {
		self::$calls[] = array(
			'method'        => 'run',
			'message'       => $message,
			'conversation'  => $conversation,
			'deep_mode'     => $deep_mode,
			'screen'        => $screen,
			'post_id'       => $post_id,
			'loaded_groups' => $loaded_groups,
			'checkpoint'    => $checkpoint_data,
		);
		return $GLOBALS['pressark_agent_result'];
	}
}

function pressark_get_agent( int $user_id ) {
	unset( $user_id );
	return new FakePressArkAgent();
}

require_once __DIR__ . '/../includes/class-pressark-task-queue.php';

$passed = 0;
$failed = 0;

function assert_same_queue( string $label, $expected, $actual ): void {
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

function assert_true_queue( string $label, bool $condition ): void {
	assert_same_queue( $label, true, $condition );
}

function queue_reset(): void {
	PressArk_Task_Store::$rows      = array();
	PressArk_Run_Store::$rows       = array();
	PressArk_Activity_Trace::$context = array();
	PressArk_Activity_Trace::$events = array();
	PressArk_Queue_Cron::$scheduled = array();
	PressArk_Throttle::$next_slot   = 'slot-default';
	PressArk_Throttle::$active_slots = 1;
	PressArk_Pipeline::$registered  = array();
	PressArk_Pipeline::$settled_runs = array();
	PressArk_Pipeline::$failed_runs = array();
	PressArk_Pipeline::$cleanup_calls = 0;
	PressArk_Reservation::$failed   = array();
	PressArk_Error_Tracker::$errors = array();
	FakePressArkAgent::$calls       = array();
	$GLOBALS['pressark_agent_result']    = array();
	$GLOBALS['pressark_current_user_id'] = 7;
}

function seed_run( string $run_id, string $parent_run_id, string $correlation_id ): void {
	PressArk_Run_Store::$rows[ $run_id ] = array(
		'run_id'         => $run_id,
		'parent_run_id'  => $parent_run_id,
		'root_run_id'    => $parent_run_id,
		'correlation_id' => $correlation_id,
		'user_id'        => 7,
		'chat_id'        => 0,
		'task_id'        => '',
		'route'          => 'async',
		'status'         => 'running',
		'reservation_id' => 'res-1',
	);
	PressArk_Run_Store::$rows[ $parent_run_id ] = array(
		'run_id'         => $parent_run_id,
		'parent_run_id'  => '',
		'root_run_id'    => $parent_run_id,
		'correlation_id' => $correlation_id,
		'user_id'        => 7,
		'chat_id'        => 0,
		'task_id'        => '',
		'route'          => 'handoff',
		'status'         => 'settled',
		'reservation_id' => 'res-1',
	);
}

function find_event( string $event_type ): ?array {
	foreach ( PressArk_Activity_Trace::$events as $event ) {
		if ( $event_type === ( $event['event_type'] ?? '' ) ) {
			return $event;
		}
	}
	return null;
}

echo "=== Queue Lineage And Worker Event Tests ===\n\n";

echo "--- Test 1: Parent-to-child lineage persists through completion ---\n";
queue_reset();
seed_run( 'run-child-1', 'run-parent-1', 'corr-flow-1' );
$queue = new PressArk_Task_Queue();
$queued = $queue->enqueue(
	'Run the audit in the background.',
	array(),
	array(),
	7,
	false,
	'res-1',
	array( 'seo' ),
	null,
	'run-child-1',
	array( 'chat_id' => 0 ),
	'',
	array(
		'parent_run_id'   => 'run-parent-1',
		'root_run_id'     => 'run-parent-1',
		'handoff_capsule' => array(
			'summary'          => 'Audit handoff capsule',
			'workflow_stage'   => 'plan',
			'loaded_groups'    => array( 'seo' ),
			'bundle_ids'       => array( 'bundle-a' ),
			'batch_provenance' => array(
				'loaded_groups' => array( 'seo' ),
				'bundle_ids'    => array( 'bundle-a' ),
			),
		),
	)
);
$task_id = (string) $queued['task_id'];
$GLOBALS['pressark_agent_result'] = array(
	'type'    => 'final_response',
	'message' => 'Audit complete.',
);
$queue->process( $task_id );
$stored_task = PressArk_Task_Store::$rows[ $task_id ];
$handoff_event = find_event( 'worker.handoff' );
$claimed_event = find_event( 'worker.claimed' );
$completed_event = find_event( 'worker.completed' );

assert_same_queue( 'queued response type', 'queued', $queued['type'] );
assert_same_queue( 'task stores worker run id', 'run-child-1', $stored_task['run_id'] );
assert_same_queue( 'task stores parent run id', 'run-parent-1', $stored_task['parent_run_id'] );
assert_same_queue( 'task stores root run id', 'run-parent-1', $stored_task['root_run_id'] );
assert_same_queue( 'handoff capsule summary persisted', 'Audit handoff capsule', $stored_task['handoff_capsule']['summary'] ?? '' );
assert_same_queue( 'child run links back to task', $task_id, PressArk_Run_Store::$rows['run-child-1']['task_id'] ?? '' );
assert_same_queue( 'task completes successfully', 'complete', $stored_task['status'] );
assert_same_queue( 'worker completion result carries run id', 'run-child-1', $stored_task['result']['run_id'] ?? '' );
assert_same_queue( 'handoff event keeps parent run id', 'run-parent-1', $handoff_event['payload']['parent_run_id'] ?? '' );
assert_same_queue( 'claim event keeps root run id', 'run-parent-1', $claimed_event['payload']['root_run_id'] ?? '' );
assert_same_queue( 'completion event is succeeded', 'succeeded', $completed_event['status'] ?? '' );
assert_same_queue( 'completion event records result type', 'final_response', $completed_event['payload']['result_type'] ?? '' );
assert_same_queue( 'pipeline keeps claimed slot', 'slot-default', PressArk_Pipeline::$registered[0]['slot_id'] ?? '' );

echo "\n--- Test 2: Retry scheduling preserves lineage context ---\n";
queue_reset();
seed_run( 'run-child-2', 'run-parent-2', 'corr-retry-2' );
$queue = new PressArk_Task_Queue();
$queued = $queue->enqueue(
	'Retryable background read.',
	array(),
	array(),
	7,
	false,
	'res-2',
	array(),
	null,
	'run-child-2',
	array( 'chat_id' => 0 ),
	'',
	array(
		'parent_run_id'   => 'run-parent-2',
		'root_run_id'     => 'run-parent-2',
		'handoff_capsule' => array( 'summary' => 'Retry handoff capsule' ),
	)
);
$task_id = (string) $queued['task_id'];
$GLOBALS['pressark_agent_result'] = array(
	'is_error'   => true,
	'message'    => 'Tool failed to read the target file.',
	'checkpoint' => array( 'workflow_stage' => 'plan' ),
);
$queue->process( $task_id );
$stored_task = PressArk_Task_Store::$rows[ $task_id ];
$retry_event = find_event( 'worker.retry_scheduled' );

assert_same_queue( 'retry leaves task queued', 'queued', $stored_task['status'] );
assert_same_queue( 'retry increments attempt count', 1, $stored_task['retries'] );
assert_same_queue( 'retry schedules 30 second backoff', 30, PressArk_Queue_Cron::$scheduled[1]['delay'] ?? 0 );
assert_same_queue( 'retry event keeps parent run id', 'run-parent-2', $retry_event['payload']['parent_run_id'] ?? '' );
assert_same_queue( 'retry event keeps failure class', 'tool_error', $retry_event['payload']['failure_class'] ?? '' );

echo "\n--- Test 3: Slot contention defers without losing lineage ---\n";
queue_reset();
seed_run( 'run-child-3', 'run-parent-3', 'corr-defer-3' );
PressArk_Throttle::$next_slot    = false;
PressArk_Throttle::$active_slots = 2;
$queue = new PressArk_Task_Queue();
$queued = $queue->enqueue(
	'Wait for a slot.',
	array(),
	array(),
	7,
	false,
	'res-3',
	array(),
	null,
	'run-child-3',
	array( 'chat_id' => 0 ),
	'',
	array(
		'parent_run_id'   => 'run-parent-3',
		'root_run_id'     => 'run-parent-3',
		'handoff_capsule' => array( 'summary' => 'Defer handoff capsule' ),
	)
);
$task_id = (string) $queued['task_id'];
$GLOBALS['pressark_agent_result'] = array(
	'type'    => 'final_response',
	'message' => 'This should not run while no slot is available.',
);
$queue->process( $task_id );
$stored_task = PressArk_Task_Store::$rows[ $task_id ];
$slot_event  = find_event( 'worker.slot_contention' );
$defer_event = find_event( 'worker.deferred' );

assert_same_queue( 'slot contention re-queues the task', 'queued', $stored_task['status'] );
assert_same_queue( 'defer count stored in payload', 1, $stored_task['payload']['defer_count'] ?? 0 );
assert_same_queue( 'slot contention captures active slots', 2, $slot_event['payload']['active_slots'] ?? 0 );
assert_same_queue( 'defer event records 10 second delay', 10, $defer_event['payload']['delay_seconds'] ?? 0 );
assert_same_queue( 'defer keeps lineage root', 'run-parent-3', $defer_event['payload']['root_run_id'] ?? '' );
assert_true_queue(
	'agent run is skipped while no slot is available',
	0 === count(
		array_filter(
			FakePressArkAgent::$calls,
			static function ( array $call ): bool {
				return 'run' === ( $call['method'] ?? '' );
			}
		)
	)
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
