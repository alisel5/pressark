<?php
/**
 * Targeted verification for typed read metadata, invalidation, and trust strata.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-read-metadata-and-trust.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$pressark_test_options    = array();
$pressark_test_transients = array();
$pressark_test_cache      = array();
$pressark_test_actions    = array();
$pressark_uuid_counter    = 0;

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
		return abs( intval( $value ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $callback = false ) {
		unset( $tag, $callback );
		return false;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $pressark_test_options;
		return array_key_exists( $key, $pressark_test_options ) ? $pressark_test_options[ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		global $pressark_test_options;
		$pressark_test_options[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 99;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		global $pressark_test_transients;
		unset( $ttl );
		$pressark_test_transients[ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $pressark_test_transients;
		return array_key_exists( $key, $pressark_test_transients ) ? $pressark_test_transients[ $key ] : false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		global $pressark_test_transients;
		unset( $pressark_test_transients[ $key ] );
		return true;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
		global $pressark_test_cache;
		unset( $ttl );
		$pressark_test_cache[ $group . '|' . $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		global $pressark_test_cache;
		$cache_key = $group . '|' . $key;
		return array_key_exists( $cache_key, $pressark_test_cache ) ? $pressark_test_cache[ $cache_key ] : false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10 ) {
		global $pressark_test_actions;
		$pressark_test_actions[ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
		);
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		global $pressark_test_actions;
		if ( empty( $pressark_test_actions[ $hook ] ) ) {
			return;
		}
		foreach ( $pressark_test_actions[ $hook ] as $listener ) {
			if ( is_callable( $listener['callback'] ) ) {
				call_user_func( $listener['callback'] );
			}
		}
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		global $pressark_uuid_counter;
		$pressark_uuid_counter++;
		return sprintf( '00000000-0000-4000-8000-%012d', $pressark_uuid_counter );
	}
}

require_once __DIR__ . '/../includes/class-pressark-read-metadata.php';
require_once __DIR__ . '/../includes/class-pressark-operation.php';
require_once __DIR__ . '/../includes/class-pressark-operation-registry.php';
require_once __DIR__ . '/../includes/class-pressark-verification.php';
require_once __DIR__ . '/../includes/class-pressark-evidence-receipt.php';
require_once __DIR__ . '/../includes/class-pressark-execution-ledger.php';
require_once __DIR__ . '/../includes/class-pressark-checkpoint.php';
require_once __DIR__ . '/../includes/class-pressark-run-trust-surface.php';
require_once __DIR__ . '/../includes/class-pressark-site-playbook.php';
require_once __DIR__ . '/../includes/class-pressark-tool-result-artifacts.php';
require_once __DIR__ . '/../includes/class-pressark-resource-registry.php';
require_once __DIR__ . '/helpers/harness-fixtures.php';

if ( ! class_exists( 'PressArk_AI_Connector' ) ) {
	class PressArk_AI_Connector {
		public static function build_automation_addendum( array $automation ): string {
			unset( $automation );
			return '';
		}

		public static function get_conditional_blocks( string $task_type, string $screen, array $groups ): string {
			unset( $task_type, $screen, $groups );
			return '';
		}

		public static function join_prompt_sections( array $sections ): string {
			$sections = array_values( array_filter( array_map( 'trim', $sections ) ) );
			return implode( "\n\n", $sections );
		}
	}
}
if ( ! class_exists( 'PressArk_Action_Engine' ) ) {
	class PressArk_Action_Engine {}
}
if ( ! class_exists( 'PressArk_Context' ) ) {
	class PressArk_Context {
		public function build( string $screen, int $post_id ): string {
			return "Screen: {$screen}\nPost ID: {$post_id}";
		}
	}
}
if ( ! class_exists( 'PressArk_Token_Budget_Manager' ) ) {
	class PressArk_Token_Budget_Manager {}
}
if ( ! class_exists( 'PressArk_Skills' ) ) {
	class PressArk_Skills {
		public static function get_dynamic_task_scoped( string $task_type, array $flags = array() ): string {
			unset( $task_type, $flags );
			return '';
		}
	}
}

require_once __DIR__ . '/../includes/class-pressark-agent.php';

$passed = 0;
$failed = 0;

function assert_true_rmt( string $label, bool $condition ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$label}\n";
	} else {
		$failed++;
		echo "  FAIL: {$label}\n";
	}
}

function assert_eq_rmt( string $label, $expected, $actual ): void {
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

function assert_contains_rmt( string $label, string $needle, string $haystack ): void {
	assert_true_rmt( $label, false !== strpos( $haystack, $needle ) );
}

function assert_fixture_rmt( string $label, bool $condition, string $detail = '' ): void {
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

function reset_runtime_state_rmt(): void {
	global $pressark_test_options, $pressark_test_transients, $pressark_test_cache, $pressark_test_actions, $pressark_uuid_counter;
	$pressark_test_options    = array();
	$pressark_test_transients = array();
	$pressark_test_cache      = array();
	$pressark_test_actions    = array();
	$pressark_uuid_counter    = 0;
	PressArk_Resource_Registry::reset();
}

function find_snapshot_by_tool_rmt( array $snapshots, string $tool_name ): array {
	foreach ( $snapshots as $snapshot ) {
		if ( $tool_name === (string) ( $snapshot['tool_name'] ?? '' ) ) {
			return $snapshot;
		}
	}
	return array();
}

echo "=== Typed Read Metadata + Trust Tests ===\n\n";

reset_runtime_state_rmt();

PressArk_Resource_Registry::register( array(
	'uri'         => 'pressark://tests/sample',
	'name'        => 'Sample Test Resource',
	'description' => 'Synthetic schema payload for testing',
	'group'       => 'schema',
	'resolver'    => static function () {
		return array( 'ok' => true, 'version' => 1 );
	},
	'ttl'         => 60,
) );

$first_resource_read = PressArk_Resource_Registry::read( 'pressark://tests/sample' );
$second_resource_read = PressArk_Resource_Registry::read( 'pressark://tests/sample' );

assert_true_rmt( 'First resource read succeeds', ! empty( $first_resource_read['success'] ) );
assert_eq_rmt( 'Fresh resource read labeled fresh', 'fresh', $first_resource_read['meta']['freshness'] ?? '' );
assert_eq_rmt( 'Fresh resource read labeled complete', 'complete', $first_resource_read['meta']['completeness'] ?? '' );
assert_eq_rmt( 'Fresh resource read labeled trusted system', 'trusted_system', $first_resource_read['meta']['trust_class'] ?? '' );
assert_true_rmt( 'Resource read gets a fingerprint', '' !== (string) ( $first_resource_read['meta']['query_fingerprint'] ?? '' ) );
assert_true_rmt( 'Second resource read comes from cache', ! empty( $second_resource_read['cached'] ) );
assert_eq_rmt( 'Cached resource read labeled cached', 'cached', $second_resource_read['meta']['freshness'] ?? '' );
assert_eq_rmt(
	'Resource fingerprint stays stable across cached reads',
	$first_resource_read['meta']['query_fingerprint'] ?? '',
	$second_resource_read['meta']['query_fingerprint'] ?? ''
);

$artifact_store = new PressArk_Tool_Result_Artifacts( 'run-test', 11, 99, 1 );
$large_site_health = PressArk_Read_Metadata::annotate_tool_result(
	'site_health',
	array(),
	array(
		'success' => true,
		'message' => 'Health report generated.',
		'data'    => array(
			'report' => str_repeat( 'A', 6000 ),
			'issues' => array_map(
				static function ( int $i ): array {
					return array(
						'title'    => 'Issue ' . $i,
						'severity' => 'warning',
						'message'  => 'Synthetic issue for artifact preview coverage.',
					);
				},
				range( 1, 40 )
			),
		),
	)
);

$prepared = $artifact_store->prepare_batch( array(
	array(
		'tool_name'   => 'site_health',
		'tool_use_id' => 'toolu_1',
		'result'      => $large_site_health,
	),
) );

$artifact_result = $prepared[0]['result'] ?? array();
$artifact_uri    = (string) ( $artifact_result['data']['artifact']['uri'] ?? '' );
$artifact_read   = PressArk_Tool_Result_Artifacts::read_resource( $artifact_uri, 99 );
$artifact_list   = PressArk_Tool_Result_Artifacts::list_resource_entries( 99, 1 );

assert_true_rmt( 'Large result is artifactized', ! empty( $artifact_result['_artifactized'] ) );
assert_eq_rmt( 'Artifact preview downgrades completeness to preview', 'preview', $artifact_result['read_meta']['completeness'] ?? '' );
assert_eq_rmt( 'Artifact preview uses artifact_store provider', 'artifact_store', $artifact_result['read_meta']['provider'] ?? '' );
assert_true_rmt( 'Artifact URI is emitted in prompt preview', '' !== $artifact_uri );
assert_eq_rmt( 'Stored artifact preserves original completeness', 'complete', $artifact_read['meta']['completeness'] ?? '' );
assert_eq_rmt( 'Stored artifact preserves derived trust class', 'derived_summary', $artifact_read['meta']['trust_class'] ?? '' );
assert_eq_rmt( 'Artifact listing exposes trust class', 'derived_summary', $artifact_list[0]['trust_class'] ?? '' );

$checkpoint = new PressArk_Checkpoint();
$checkpoint->record_read_snapshot(
	PressArk_Read_Metadata::snapshot_from_tool_result(
		'read_content',
		array( 'post_id' => 42, 'mode' => 'structured' ),
		array(
			'success' => true,
			'message' => 'Alpha page content loaded',
			'data'    => array(
				'id'      => 42,
				'title'   => 'Alpha',
				'content' => 'Full body',
				'mode'    => 'structured',
			),
		)
	)
);
$checkpoint->record_read_snapshot(
	PressArk_Read_Metadata::snapshot_from_tool_result(
		'search_knowledge',
		array( 'query' => 'Alpha' ),
		array(
			'success' => true,
			'message' => 'Knowledge summary for Alpha',
			'data'    => array(
				array(
					'post_id'         => 42,
					'title'           => 'Alpha',
					'type'            => 'page',
					'content_preview' => 'Alpha excerpt',
					'is_stale'        => false,
				),
			),
		)
	)
);

$snapshots_before = $checkpoint->get_read_state();
$content_snapshot = find_snapshot_by_tool_rmt( $snapshots_before, 'read_content' );
$search_snapshot  = find_snapshot_by_tool_rmt( $snapshots_before, 'search_knowledge' );

assert_eq_rmt( 'Structured read_content is labeled partial', 'partial', $content_snapshot['completeness'] ?? '' );
assert_eq_rmt( 'read_content is labeled untrusted content', 'untrusted_content', $content_snapshot['trust_class'] ?? '' );
assert_eq_rmt( 'search_knowledge is labeled derived summary', 'derived_summary', $search_snapshot['trust_class'] ?? '' );

$checkpoint->record_execution_write(
	'edit_content',
	array( 'post_id' => 42 ),
	array(
		'success' => true,
		'data'    => array( 'id' => 42 ),
	)
);

$snapshots_after = $checkpoint->get_read_state();
$content_after   = find_snapshot_by_tool_rmt( $snapshots_after, 'read_content' );
$search_after    = find_snapshot_by_tool_rmt( $snapshots_after, 'search_knowledge' );
$invalidations   = $checkpoint->get_read_invalidation_log();
$header          = $checkpoint->to_context_header();

assert_eq_rmt( 'Write invalidates prior read_content snapshot', 'stale', $content_after['freshness'] ?? '' );
assert_eq_rmt( 'Write invalidates prior derived summary snapshot', 'stale', $search_after['freshness'] ?? '' );
assert_true_rmt( 'Invalidation log captures matched handles', count( $invalidations[0]['matched_handles'] ?? array() ) >= 2 );
assert_contains_rmt( 'Checkpoint header reports read state', 'READ STATE:', $header );
assert_contains_rmt( 'Checkpoint header reports stale reads', 'STALE READS:', $header );

$dry_run_invalidation = PressArk_Read_Metadata::build_invalidation_from_write(
	'find_and_replace',
	array( 'dry_run' => true ),
	array( 'success' => true, 'dry_run' => true )
);
assert_eq_rmt( 'Dry-run writes do not emit read invalidation descriptors', array(), $dry_run_invalidation );

$bulk_delete_invalidation = PressArk_Read_Metadata::build_invalidation_from_write(
	'bulk_delete',
	array( 'post_ids' => array( 42, 84 ) ),
	array( 'success' => true )
);
assert_eq_rmt(
	'Bulk delete invalidation keeps explicit target post ids',
	array( 42, 84 ),
	$bulk_delete_invalidation['post_ids'] ?? array()
);

$toggle_policy = PressArk_Verification::get_policy( 'toggle_plugin', array( 'success' => true ) );
assert_eq_rmt(
	'Explicit nudge-only verification policy is surfaced for toggle_plugin',
	'none',
	$toggle_policy['strategy'] ?? ''
);
assert_true_rmt(
	'Nudge-only verification policy keeps the manual verification reminder',
	! empty( $toggle_policy['nudge'] )
);

$bulk_product_readback = PressArk_Verification::build_readback(
	array(
		'strategy'  => 'field_check',
		'read_tool' => 'get_product',
		'read_args' => array(),
	),
	array(
		'success'     => true,
		'product_ids' => array( 311, 312 ),
	),
	array()
);
assert_eq_rmt(
	'Bulk product verification reads back the first updated product',
	311,
	(int) ( $bulk_product_readback['arguments']['product_id'] ?? 0 )
);

$prompt_checkpoint = new PressArk_Checkpoint();
$prompt_checkpoint->record_read_snapshot(
	PressArk_Read_Metadata::snapshot_from_tool_result(
		'get_site_overview',
		array(),
		array(
			'success' => true,
			'message' => 'Trusted site overview snapshot',
			'data'    => array( 'name' => 'Demo Site' ),
		)
	)
);
$prompt_checkpoint->record_read_snapshot(
	PressArk_Read_Metadata::snapshot_from_tool_result(
		'search_knowledge',
		array( 'query' => 'Alpha' ),
		array(
			'success' => true,
			'message' => 'Knowledge summary for Alpha',
			'data'    => array(
				array(
					'post_id'         => 42,
					'title'           => 'Alpha',
					'type'            => 'page',
					'content_preview' => 'Alpha excerpt',
				),
			),
		)
	)
);
$prompt_checkpoint->record_read_snapshot(
	PressArk_Read_Metadata::snapshot_from_tool_result(
		'read_content',
		array( 'post_id' => 42, 'mode' => 'structured' ),
		array(
			'success' => true,
			'message' => 'Alpha page content loaded',
			'data'    => array(
				'id'      => 42,
				'title'   => 'Alpha',
				'content' => 'Full body',
				'mode'    => 'structured',
			),
		)
	)
);

$ledger = PressArk_Execution_Ledger::record_write(
	array(),
	'edit_content',
	array( 'post_id' => 42 ),
	array( 'success' => true, 'data' => array( 'id' => 42 ) )
);
$ledger['receipts'][0]['verification'] = array(
	'status'   => 'verified',
	'evidence' => 'Read-back matched title.',
);
$prompt_checkpoint->set_execution( $ledger );
$pressark_test_options[ PressArk_Site_Playbook::OPTION_KEY ] = array(
	array(
		'id'          => 'playbook_brand',
		'title'       => 'Brand guardrails',
		'body'        => 'Avoid the phrase "cheap plan"; use "entry plan" instead.',
		'task_types'  => array( 'analyze', 'generate', 'edit' ),
		'tool_groups' => array( 'generation', 'core' ),
		'updated_at'  => '2026-04-05 09:00:00',
	),
	array(
		'id'          => 'playbook_checkout',
		'title'       => 'Checkout approvals',
		'body'        => 'Never modify checkout, pricing, or shipping workflows without explicit human approval.',
		'task_types'  => array( 'edit' ),
		'tool_groups' => array( 'woocommerce', 'settings' ),
		'updated_at'  => '2026-04-05 08:00:00',
	),
);

$agent           = new PressArk_Agent( new PressArk_AI_Connector(), new PressArk_Action_Engine(), 'free' );
$sections_method = new ReflectionMethod( PressArk_Agent::class, 'build_round_prompt_sections' );
$sections_method->setAccessible( true );
$compose_method = new ReflectionMethod( PressArk_Agent::class, 'compose_round_prompt_sections' );
$compose_method->setAccessible( true );
$assembly_method = new ReflectionMethod( PressArk_Agent::class, 'describe_round_prompt_assembly' );
$assembly_method->setAccessible( true );

$sections = $sections_method->invoke(
	$agent,
	'post.php',
	42,
	'Summarize the Alpha page',
	'analyze',
	array( 'groups' => array( 'generation', 'core' ) ),
	$prompt_checkpoint
);
$prompt = $compose_method->invoke( $agent, $sections );
$assembly = $assembly_method->invoke( $agent, $sections );

$trusted_pos   = strpos( $prompt, '## Trusted System Facts' );
$verified_pos  = strpos( $prompt, '## Verified Evidence' );
$derived_pos   = strpos( $prompt, '## Derived Summaries' );
$untrusted_pos = strpos( $prompt, '## Untrusted Site Content' );
$trusted_slice = false !== $trusted_pos && false !== $verified_pos
	? substr( $prompt, $trusted_pos, $verified_pos - $trusted_pos )
	: '';
$untrusted_slice = false !== $untrusted_pos ? substr( $prompt, $untrusted_pos ) : '';

assert_true_rmt(
	'Prompt strata appear in stable trust order',
	false !== $trusted_pos
		&& false !== $verified_pos
		&& false !== $derived_pos
		&& false !== $untrusted_pos
		&& $trusted_pos < $verified_pos
		&& $verified_pos < $derived_pos
		&& $derived_pos < $untrusted_pos
);
assert_true_rmt(
	'Untrusted content is not merged into trusted system facts',
	false === strpos( $trusted_slice, 'Alpha page content loaded' )
);
assert_contains_rmt(
	'Untrusted content appears in the untrusted section',
	'Alpha page content loaded',
	$untrusted_slice
);
assert_contains_rmt(
	'Relevant Site Playbook entry is injected into the prompt',
	'Brand guardrails: Avoid the phrase "cheap plan"; use "entry plan" instead.',
	$prompt
);
assert_true_rmt(
	'Irrelevant Site Playbook entry is not injected for non-matching task/group scope',
	false === strpos( $prompt, 'Checkout approvals' )
);
assert_true_rmt(
	'Prompt inspector reports Site Playbook inclusion',
	! empty( $sections['inspector']['site_playbook_included'] )
);
assert_eq_rmt(
	'Prompt inspector exposes selected Site Playbook title',
	'Brand guardrails',
	$sections['inspector']['site_playbook_titles'][0] ?? ''
);
assert_true_rmt(
	'Prompt assembly snapshot keeps stable and volatile section ids separate',
	in_array( 'site_playbook', (array) ( $assembly['stable_sections'] ?? array() ), true )
		&& in_array( 'trusted_system_facts', (array) ( $assembly['volatile_sections'] ?? array() ), true )
);
assert_true_rmt(
	'Prompt assembly snapshot describes blocks with token and char counts',
	! empty( $assembly['stable_blocks'][0]['tokens'] )
		&& ! empty( $assembly['stable_blocks'][0]['chars'] )
		&& ! empty( $assembly['volatile_blocks'][0]['tokens'] )
		&& ! empty( $assembly['volatile_blocks'][0]['chars'] )
);
assert_true_rmt(
	'Prompt assembly snapshot stays lighter than the full composed prompt',
	array_sum( array_map( static fn( array $block ): int => (int) ( $block['chars'] ?? 0 ), (array) ( $assembly['stable_blocks'] ?? array() ) ) )
		+ array_sum( array_map( static fn( array $block ): int => (int) ( $block['chars'] ?? 0 ), (array) ( $assembly['volatile_blocks'] ?? array() ) ) )
		<= strlen( $prompt ),
	'Assembly: ' . var_export( $assembly, true )
);

$evidence_ledger = PressArk_Execution_Ledger::record_write(
	array(),
	'edit_content',
	array( 'post_id' => 42, 'title' => 'Alpha', 'post_status' => 'publish' ),
	array(
		'success'    => true,
		'data'       => array(
			'id'          => 42,
			'title'       => 'Alpha',
			'post_status' => 'publish',
		),
		'post_title' => 'Alpha',
	)
);
$evidence_ledger = PressArk_Execution_Ledger::record_verification(
	$evidence_ledger,
	'edit_content',
	array(
		'success'   => true,
		'data'      => array(
			'id'          => 42,
			'title'       => 'Alpha',
			'post_status' => 'publish',
		),
		'read_meta' => array(
			'freshness'    => 'fresh',
			'completeness' => 'complete',
			'trust_class'  => 'trusted_system',
		),
	),
	true,
	'#42 title=Alpha status=publish',
	array(
		'policy'     => array(
			'strategy'     => 'field_check',
			'read_tool'    => 'read_content',
			'check_fields' => array( 'title', 'post_status' ),
		),
		'readback'   => array( 'tool' => 'read_content' ),
		'mismatches' => array(),
	)
);
$evidence_rows = PressArk_Execution_Ledger::evidence_receipts( $evidence_ledger );
$evidence_receipt = $evidence_rows[0]['evidence_receipt'] ?? array();

assert_eq_rmt( 'Evidence receipt is attached to write rows', 1, count( $evidence_rows ) );
assert_eq_rmt( 'Field-check verification maps to strong confidence', 'strong', $evidence_receipt['confidence'] ?? '' );
assert_eq_rmt( 'Evidence receipt preserves verified status', 'verified', $evidence_receipt['status'] ?? '' );

$trust_surface = PressArk_Run_Trust_Surface::build(
	array(
		'status'         => 'settled',
		'route'          => 'chat',
		'workflow_state' => array(
			'workflow_stage' => 'verify',
		),
		'result'         => array(
			'budget' => array(
				'billing_state'    => array(
					'authority_mode'  => 'bank_verified',
					'authority_label' => 'Bank verified',
					'service_state'   => 'degraded',
					'service_label'   => 'Degraded',
					'spend_source'    => 'purchased_credits',
					'spend_label'     => 'Purchased credits',
				),
				'settlement_delta' => array(
					'estimate_authority'   => 'plugin_local_advisory',
					'settlement_authority' => 'bank',
					'estimated_icus'       => 1200,
					'settled_icus'         => 1500,
					'delta_icus'           => 300,
					'summary'              => 'The bank settled more ICUs than the advisory estimate.',
				),
			),
		),
	),
	array(
		array(
			'source'      => 'plugin',
			'reason'      => 'fallback_model_policy',
			'status'      => 'succeeded',
			'summary'     => 'The run fell back to another model candidate.',
			'occurred_at' => '2026-04-05 09:00:00',
		),
	),
	$evidence_ledger
);

assert_eq_rmt(
	'Trust surface keeps evidence quality on the verified example',
	'strong',
	$trust_surface['evidence']['verified_example']['evidence_receipt']['confidence'] ?? ''
);
assert_eq_rmt(
	'Trust surface keeps billing authority separate and explicit',
	'Bank verified',
	$trust_surface['billing']['authority_label'] ?? ''
);
assert_eq_rmt(
	'Trust surface carries a fallback example from the event spine',
	'fallback_model_policy',
	$trust_surface['fallback']['example']['reason'] ?? ''
);
assert_contains_rmt(
	'Trust surface explains the evidence versus authority boundary',
	'Evidence quality',
	(string) ( $trust_surface['authority_boundary_note'] ?? '' )
);

function find_snapshot_by_tool_fixture_rmt( array $snapshots, string $tool_name ): array {
	foreach ( $snapshots as $snapshot ) {
		if ( $tool_name === (string) ( $snapshot['tool_name'] ?? '' ) ) {
			return $snapshot;
		}
	}
	return array();
}

function run_trust_fixture_scenario_rmt( array $fixture ): array {
	$input = (array) ( $fixture['input'] ?? array() );
	reset_runtime_state_rmt();

	$checkpoint = new PressArk_Checkpoint();
	foreach ( (array) ( $input['reads'] ?? array() ) as $read ) {
		$checkpoint->record_read_snapshot(
			PressArk_Read_Metadata::snapshot_from_tool_result(
				(string) ( $read['tool_name'] ?? '' ),
				(array) ( $read['args'] ?? array() ),
				(array) ( $read['result'] ?? array() )
			)
		);
	}

	$before_snapshots = $checkpoint->get_read_state();
	if ( ! empty( $input['write'] ) ) {
		$write = (array) $input['write'];
		$checkpoint->record_execution_write(
			(string) ( $write['tool_name'] ?? '' ),
			(array) ( $write['args'] ?? array() ),
			(array) ( $write['result'] ?? array() )
		);
	}

	$after_snapshots = $checkpoint->get_read_state();
	$invalidations   = $checkpoint->get_read_invalidation_log();

	return array(
		'before' => array(
			'read_content'      => find_snapshot_by_tool_fixture_rmt( $before_snapshots, 'read_content' ),
			'search_knowledge'  => find_snapshot_by_tool_fixture_rmt( $before_snapshots, 'search_knowledge' ),
		),
		'after' => array(
			'read_content'      => find_snapshot_by_tool_fixture_rmt( $after_snapshots, 'read_content' ),
			'search_knowledge'  => find_snapshot_by_tool_fixture_rmt( $after_snapshots, 'search_knowledge' ),
		),
		'header'             => $checkpoint->to_context_header(),
		'invalidations_count'=> count( $invalidations ),
	);
}

foreach ( pressark_test_load_json_fixtures( 'tests/fixtures/harness/trust' ) as $fixture ) {
	$actual = run_trust_fixture_scenario_rmt( $fixture );
	pressark_test_assert_fixture_expectations(
		'assert_fixture_rmt',
		'Fixture trust - ' . (string) ( $fixture['name'] ?? $fixture['_fixture_file'] ?? 'scenario' ),
		$actual,
		(array) ( $fixture['expect'] ?? array() )
	);
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
