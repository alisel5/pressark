<?php
/**
 * Targeted verification for replay repair, round compaction, and artifact replay.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-replay-integrity.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
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
	function absint( $v ) {
		return abs( intval( $v ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$values = array();
		foreach ( (array) $list as $item ) {
			if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
				$values[] = $item[ $field ];
			}
		}
		return $values;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		static $counter = 0;
		$counter++;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter );
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
		$GLOBALS['pressark_test_cache'][ $group . '|' . $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		$cache_key = $group . '|' . $key;
		return array_key_exists( $cache_key, $GLOBALS['pressark_test_cache'] ?? array() )
			? $GLOBALS['pressark_test_cache'][ $cache_key ]
			: false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['pressark_test_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['pressark_test_transients'] ?? array() )
			? $GLOBALS['pressark_test_transients'][ $key ]
			: false;
	}
}

require_once __DIR__ . '/../includes/class-pressark-replay-integrity.php';
require_once __DIR__ . '/../includes/class-pressark-execution-ledger.php';
require_once __DIR__ . '/../includes/class-pressark-checkpoint.php';
require_once __DIR__ . '/../includes/class-pressark-tool-result-artifacts.php';
require_once __DIR__ . '/helpers/harness-fixtures.php';

$passed = 0;
$failed = 0;

function assert_true_ri( string $label, bool $condition ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$label}\n";
	} else {
		$failed++;
		echo "  FAIL: {$label}\n";
	}
}

function assert_eq_ri( string $label, $expected, $actual ): void {
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

function assert_fixture_ri( string $label, bool $condition, string $detail = '' ): void {
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

echo "=== Replay Integrity Tests ===\n\n";

$malformed_resume = array(
	array(
		'role'       => 'assistant',
		'content'    => '',
		'tool_calls' => array(
			array(
				'id'       => 'toolu_missing',
				'type'     => 'function',
				'function' => array(
					'name'      => 'read_content',
					'arguments' => '{}',
				),
			),
		),
	),
	array(
		'role'    => 'assistant',
		'content' => 'Waiting on the next step.',
	),
);
$repair = PressArk_Replay_Integrity::repair_messages( $malformed_resume, 'resume_boundary' );

assert_true_ri( 'Malformed resume transcript is repaired', ! empty( $repair['changed'] ) );
assert_eq_ri( 'Repair records one inserted placeholder result', 1, (int) ( $repair['event']['inserted_missing_results'] ?? 0 ) );
assert_eq_ri( 'Repair inserts a tool result message', 'tool', $repair['messages'][1]['role'] ?? '' );
assert_eq_ri( 'Repair preserves the missing tool call id', 'toolu_missing', $repair['messages'][1]['tool_call_id'] ?? '' );

$round_messages = array(
	array( 'role' => 'user', 'content' => 'Inspect the site.' ),
	array(
		'role'       => 'assistant',
		'content'    => '',
		'tool_calls' => array(
			array(
				'id'       => 'toolu_r1',
				'type'     => 'function',
				'function' => array(
					'name'      => 'site_health',
					'arguments' => '{}',
				),
			),
		),
	),
	array( 'role' => 'tool', 'tool_call_id' => 'toolu_r1', 'content' => 'round 1 result' ),
	array( 'role' => 'assistant', 'content' => 'Round 1 complete.' ),
	array(
		'role'       => 'assistant',
		'content'    => '',
		'tool_calls' => array(
			array(
				'id'       => 'toolu_r2',
				'type'     => 'function',
				'function' => array(
					'name'      => 'site_health',
					'arguments' => '{"scope":"full"}',
				),
			),
		),
	),
	array( 'role' => 'tool', 'tool_call_id' => 'toolu_r2', 'content' => 'round 2 result' ),
	array( 'role' => 'assistant', 'content' => 'Round 2 complete.' ),
);
$window = PressArk_Replay_Integrity::select_round_compaction_window( $round_messages, 2, 2 );

assert_eq_ri( 'Round-aware compaction keeps the last tool round plus trailing assistant round', 3, count( $window['recent_messages'] ) );
assert_eq_ri( 'Compaction window begins with the assistant tool-call batch', 'assistant', $window['recent_messages'][0]['role'] ?? '' );
assert_eq_ri( 'Compaction window keeps the matching tool result group', 'toolu_r2', $window['recent_messages'][1]['tool_call_id'] ?? '' );
assert_eq_ri( 'Compaction reports the earlier assistant rounds as dropped', 2, (int) ( $window['dropped_rounds'] ?? 0 ) );

$large_entry = array(
	'tool_use_id' => 'toolu_artifact',
	'tool_name'   => 'site_health',
	'result'      => array(
		'success' => true,
		'message' => 'Large site health report ready.',
		'data'    => array(
			'report' => str_repeat( 'Replay integrity report line. ', 600 ),
		),
	),
);

$first_artifacts = new PressArk_Tool_Result_Artifacts( 'run_replay_test', 77, 1, 1 );
$first_prepared  = $first_artifacts->prepare_batch( array( $large_entry ) );
$first_journal   = $first_artifacts->get_replacement_journal();
$first_result    = $first_prepared[0]['result'] ?? array();

assert_true_ri(
	'Large tool result is artifactized on first pass',
	! empty( $first_prepared[0]['_artifactized'] ) || ! empty( $first_result['_artifactized'] )
);

$second_artifacts = new PressArk_Tool_Result_Artifacts( 'run_replay_test', 77, 1, 2 );
$second_prepared  = $second_artifacts->prepare_batch( array( $large_entry ), $first_journal );
$reuse_events     = $second_artifacts->get_replacement_events();

assert_eq_ri( 'Frozen replacement is reused verbatim across resume', $first_prepared[0]['result'], $second_prepared[0]['result'] );
assert_eq_ri( 'Replacement reuse is observable in replay events', 'reused', $reuse_events[0]['mode'] ?? '' );

$checkpoint = PressArk_Checkpoint::from_array( array() );
$checkpoint->set_last_replay_resume( array(
	'type'                   => 'resume',
	'phase'                  => 'resume_boundary',
	'source'                 => 'checkpoint_replay',
	'used_checkpoint_replay' => true,
	'repaired'               => true,
	'at'                     => '2026-04-04T00:00:00Z',
) );
$checkpoint->merge_replay_replacements( $first_journal );
foreach ( $reuse_events as $event ) {
	$checkpoint->add_replay_event( $event );
}
$sidecar = $checkpoint->get_replay_sidecar();

assert_eq_ri( 'Replay sidecar reports checkpoint-backed resume source', 'checkpoint_replay', $sidecar['last_resume']['source'] ?? '' );
assert_eq_ri( 'Replay sidecar counts frozen replacements', 1, (int) ( $sidecar['replacement_count'] ?? 0 ) );
assert_true_ri( 'Replay sidecar keeps the latest replay event', ! empty( $sidecar['last_event'] ) );

$checkpoint_with_execution = PressArk_Checkpoint::from_array( array(
	'execution' => array(
		'source_message' => 'Create one replay test post.',
		'goal_hash'      => md5( 'Create one replay test post.' ),
		'request_counts' => array( 'create_post' => 1 ),
		'receipts'       => array(
			array(
				'tool'       => 'create_post',
				'summary'    => 'Created "Replay Test" (#42)',
				'post_id'    => 42,
				'post_title' => 'Replay Test',
				'url'        => 'https://example.com/replay-test',
			),
		),
		'current_target' => array(
			'post_id'    => 42,
			'post_title' => 'Replay Test',
			'post_status'=> 'draft',
		),
	),
	'replay_state' => array(
		'messages'   => $repair['messages'],
		'updated_at' => '2026-04-04T00:00:00Z',
	),
) );
$roundtrip = PressArk_Checkpoint::from_array( $checkpoint_with_execution->to_array() );

assert_true_ri(
	'Duplicate suppression survives replay checkpoint round-trip',
	PressArk_Execution_Ledger::should_skip_duplicate(
		$roundtrip->get_execution(),
		'create_post',
		array( 'title' => 'Replay Test' )
	)
);

function run_replay_fixture_scenario_ri( array $fixture ): array {
	$input = (array) ( $fixture['input'] ?? array() );

	switch ( (string) ( $fixture['kind'] ?? '' ) ) {
		case 'repair_messages':
			return PressArk_Replay_Integrity::repair_messages(
				(array) ( $input['messages'] ?? array() ),
				(string) ( $input['phase'] ?? 'provider_call' )
			);

		case 'round_compaction_window':
			$result = PressArk_Replay_Integrity::select_round_compaction_window(
				(array) ( $input['messages'] ?? array() ),
				(int) ( $input['keep_rounds'] ?? 2 ),
				(int) ( $input['fallback_tail'] ?? 4 )
			);
			$result['recent_messages_count']  = count( (array) ( $result['recent_messages'] ?? array() ) );
			$result['dropped_messages_count'] = count( (array) ( $result['dropped_messages'] ?? array() ) );
			return $result;

		case 'artifact_reuse':
			$report = str_repeat(
				(string) ( $input['result']['report_line'] ?? 'Report line. ' ),
				max( 1, (int) ( $input['result']['report_repeat'] ?? 1 ) )
			);
			$entry = array(
				'tool_use_id' => (string) ( $input['tool_use_id'] ?? 'toolu_fixture' ),
				'tool_name'   => (string) ( $input['tool_name'] ?? 'site_health' ),
				'result'      => array(
					'success' => ! empty( $input['result']['success'] ),
					'message' => (string) ( $input['result']['message'] ?? '' ),
					'data'    => array(
						'report' => $report,
					),
				),
			);

			$first_artifacts = new PressArk_Tool_Result_Artifacts(
				(string) ( $input['run_id'] ?? 'run_fixture' ),
				(int) ( $input['site_id'] ?? 1 ),
				(int) ( $input['user_id'] ?? 1 ),
				(int) ( $input['first_round'] ?? 1 )
			);
			$first_prepared = $first_artifacts->prepare_batch( array( $entry ) );
			$first_journal  = $first_artifacts->get_replacement_journal();

			$second_artifacts = new PressArk_Tool_Result_Artifacts(
				(string) ( $input['run_id'] ?? 'run_fixture' ),
				(int) ( $input['site_id'] ?? 1 ),
				(int) ( $input['user_id'] ?? 1 ),
				(int) ( $input['second_round'] ?? 2 )
			);
			$second_prepared = $second_artifacts->prepare_batch( array( $entry ), $first_journal );
			$reuse_events    = $second_artifacts->get_replacement_events();

			$checkpoint = PressArk_Checkpoint::from_array( array() );
			$checkpoint->set_last_replay_resume( (array) ( $input['resume'] ?? array() ) );
			$checkpoint->merge_replay_replacements( $first_journal );
			foreach ( $reuse_events as $event ) {
				$checkpoint->add_replay_event( $event );
			}

			return array(
				'first_artifactized'   => ! empty( $first_prepared[0]['_artifactized'] ?? false ) || ! empty( $first_prepared[0]['result']['_artifactized'] ?? false ),
				'second_matches_first' => ( $first_prepared[0]['result'] ?? array() ) === ( $second_prepared[0]['result'] ?? array() ),
				'reuse_event'          => $reuse_events[0] ?? array(),
				'sidecar'              => $checkpoint->get_replay_sidecar(),
			);
	}

	return array();
}

foreach ( pressark_test_load_json_fixtures( 'tests/fixtures/harness/replay' ) as $fixture ) {
	$actual = run_replay_fixture_scenario_ri( $fixture );
	pressark_test_assert_fixture_expectations(
		'assert_fixture_ri',
		'Fixture replay - ' . (string) ( $fixture['name'] ?? $fixture['_fixture_file'] ?? 'scenario' ),
		$actual,
		(array) ( $fixture['expect'] ?? array() )
	);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
