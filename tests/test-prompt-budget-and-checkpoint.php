<?php
/**
 * Targeted verification for split prompt budgeting and compaction telemetry.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-prompt-budget-and-checkpoint.php
 *
 * This is a standalone test. It stubs only the minimal WordPress helpers
 * used by the token budget manager, execution ledger, and checkpoint.
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

require_once __DIR__ . '/../includes/class-pressark-execution-ledger.php';
require_once __DIR__ . '/../includes/class-pressark-token-budget-manager.php';
require_once __DIR__ . '/../includes/class-pressark-replay-integrity.php';
require_once __DIR__ . '/../includes/class-pressark-permission-decision.php';
require_once __DIR__ . '/../includes/class-pressark-checkpoint.php';

$passed = 0;
$failed = 0;

function assert_true_pb( string $label, bool $condition ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$label}\n";
	} else {
		$failed++;
		echo "  FAIL: {$label}\n";
	}
}

function assert_eq_pb( string $label, $expected, $actual ): void {
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

echo "=== Split Prompt Budget + Checkpoint Tests ===\n\n";

$budget = new PressArk_Token_Budget_Manager( array(
	'provider'          => 'openrouter',
	'model'             => 'test-model',
	'stable_prefix'     => 'STATIC PREFIX',
	'context_window'    => 20000,
	'max_output_tokens' => 2000,
) );

$ledger = $budget->build_request_ledger( array(
	'dynamic_prompt_stable'   => 'Stable run instructions',
	'dynamic_prompt_volatile' => "Current context\nPending approvals",
	'conversation'            => array(
		array( 'role' => 'user', 'content' => 'Continue the task.' ),
	),
	'tool_results'            => array(),
) );

assert_true_pb(
	'Ledger exposes stable prompt section tokens',
	! empty( $ledger['prompt_sections']['stable']['tokens'] )
);
assert_true_pb(
	'Ledger exposes volatile prompt section tokens',
	! empty( $ledger['prompt_sections']['volatile']['tokens'] )
);
assert_true_pb(
	'Combined dynamic prompt covers the split sections',
	(int) ( $ledger['segments']['dynamic_prompt']['tokens'] ?? 0 )
		>= (int) ( $ledger['prompt_sections']['stable']['tokens'] ?? 0 )
);

$server = PressArk_Checkpoint::from_array( array(
	'context_capsule' => array(
		'summary'    => 'server capsule',
		'updated_at' => '2026-01-01T00:00:00Z',
		'compaction' => array(
			'count' => 1,
			'last_marker' => 'cmp_r2_c1',
			'pending_post_compaction' => array(
				'marker' => 'cmp_r2_c1',
				'reason' => 'reserved_headroom',
				'round'  => 2,
				'at'     => '2026-01-01T00:00:00Z',
			),
		),
	),
) );

$client = PressArk_Checkpoint::from_array( array(
	'context_capsule' => array(
		'summary'    => 'client capsule',
		'updated_at' => '2025-12-31T00:00:00Z',
	),
) );

$merged_capsule = PressArk_Checkpoint::merge( $server, $client )->get_context_capsule();
assert_eq_pb( 'Newer capsule wins merge', 'server capsule', $merged_capsule['summary'] ?? '' );
assert_eq_pb(
	'Compaction telemetry survives checkpoint merge',
	'cmp_r2_c1',
	$merged_capsule['compaction']['pending_post_compaction']['marker'] ?? ''
);

$sanitized_capsule = PressArk_Checkpoint::from_array( array(
	'context_capsule' => array(
		'summary' => 'x',
		'compaction' => array(
			'count'       => 2,
			'last_marker' => 'CMP R3 C2!',
			'first_post_compaction' => array(
				'marker'           => 'CMP R3 C2!',
				'reason'           => 'reserved_headroom',
				'observed_round'   => 3,
				'stop_reason'      => 'tool_use',
				'tool_calls'       => 2,
				'had_text'         => false,
				'healthy'          => true,
				'remaining_tokens' => 512,
				'context_pressure' => 'critical',
			),
		),
	),
) )->get_context_capsule();

assert_eq_pb( 'Compaction marker is sanitized', 'cmpr3c2', $sanitized_capsule['compaction']['last_marker'] ?? '' );
assert_true_pb(
	'False boolean telemetry is preserved',
	array_key_exists( 'had_text', $sanitized_capsule['compaction']['first_post_compaction'] ?? array() )
);

$server_replay = PressArk_Checkpoint::from_array( array(
	'replay_state' => array(
		'messages' => array(
			array( 'role' => 'user', 'content' => 'Continue the run.' ),
		),
		'replacement_journal' => array(
			array(
				'tool_use_id' => 'toolu_1',
				'tool_name'   => 'site_health',
				'result_hash' => 'abc123',
				'replacement' => array( 'message' => 'Stored as artifact.' ),
			),
		),
		'last_resume' => array(
			'type'                   => 'resume',
			'source'                 => 'checkpoint_replay',
			'used_checkpoint_replay' => true,
			'at'                     => '2026-01-02T00:00:00Z',
		),
		'updated_at' => '2026-01-02T00:00:00Z',
	),
) );

$client_replay = PressArk_Checkpoint::from_array( array(
	'replay_state' => array(
		'messages' => array(
			array( 'role' => 'user', 'content' => 'Older local mirror.' ),
		),
		'updated_at' => '2026-01-01T00:00:00Z',
	),
) );

$merged_replay_sidecar = PressArk_Checkpoint::merge( $server_replay, $client_replay )->get_replay_sidecar();
assert_eq_pb( 'Replay merge preserves newer resume source', 'checkpoint_replay', $merged_replay_sidecar['last_resume']['source'] ?? '' );
assert_eq_pb( 'Replay merge preserves replacement journal count', 1, (int) ( $merged_replay_sidecar['replacement_count'] ?? 0 ) );

$approval_checkpoint = PressArk_Checkpoint::from_array( array() );
$approval_checkpoint->record_approval_outcome(
	'fix_seo',
	PressArk_Permission_Decision::OUTCOME_DECLINED,
	array(
		'source'      => 'approval',
		'actor'       => 'user',
		'reason_code' => 'user_declined',
	)
);
$approval_checkpoint->record_approval_outcome(
	'preview_apply',
	PressArk_Permission_Decision::OUTCOME_DISCARDED,
	array(
		'source'      => 'approval',
		'actor'       => 'user',
		'reason_code' => 'preview_discarded',
	)
);
$approval_history = $approval_checkpoint->get_approval_outcomes();
$approval_header  = $approval_checkpoint->to_context_header();
assert_eq_pb( 'Checkpoint keeps declined approval outcome in compact history', 'declined', $approval_history[0]['status'] ?? '' );
assert_eq_pb( 'Checkpoint keeps discarded preview outcome in compact history', 'discarded', $approval_history[1]['status'] ?? '' );
assert_true_pb( 'Checkpoint context header exposes approval history summary', false !== strpos( $approval_header, 'APPROVAL HISTORY:' ) );

$normalized_outcome = PressArk_Permission_Decision::normalize_approval_outcome(
	array(
		'outcome'     => 'CANCELLED',
		'action'      => 'Preview Apply',
		'scope'       => 'Preview',
		'source'      => 'Chat',
		'actor'       => 'User',
		'reason_code' => 'User Cancelled',
		'message'     => 'Cancelled while waiting.',
		'at'          => '2026-04-06T00:00:00Z',
	)
);
$normalized_receipt = PressArk_Permission_Decision::approval_receipt( $normalized_outcome );
assert_eq_pb( 'Typed approval normalization accepts legacy outcome field', 'cancelled', $normalized_outcome['status'] ?? '' );
assert_eq_pb( 'Typed approval normalization sanitizes action and scope', 'previewapply', $normalized_outcome['action'] ?? '' );
assert_eq_pb( 'Approval receipt mirrors the typed cancelled outcome', 'cancelled', $normalized_receipt['status'] ?? '' );
assert_eq_pb( 'Approval receipt keeps the canonical receipt contract', PressArk_Permission_Decision::RECEIPT_CONTRACT, $normalized_receipt['contract'] ?? '' );

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
