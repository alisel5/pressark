<?php
/**
 * Focused verification for authoritative operation parameter contracts.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-operation-parameter-contracts.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
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

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		unset( $tag, $args );
		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag ) {
		unset( $tag );
		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		unset( $tag, $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-operation.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-operation-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-pressark-tools.php';
if ( ! class_exists( 'PressArk_Action_Logger' ) ) {
	class PressArk_Action_Logger {}
}
if ( ! class_exists( 'PressArk_Handler_Registry' ) ) {
	class PressArk_Handler_Registry {
		public function __construct( $logger ) {
			unset( $logger );
		}
	}
}
require_once dirname( __DIR__ ) . '/includes/class-action-engine.php';

$passed = 0;
$failed = 0;

function assert_true_contract( string $label, bool $condition, string $detail = '' ): void {
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

function assert_eq_contract( string $label, $expected, $actual ): void {
	$detail = '';
	if ( $expected !== $actual ) {
		$detail = 'Expected: ' . var_export( $expected, true ) . ' | Actual: ' . var_export( $actual, true );
	}
	assert_true_contract( $label, $expected === $actual, $detail );
}

function assert_contains_contract( string $label, string $needle, string $haystack ): void {
	assert_true_contract(
		$label,
		false !== strpos( $haystack, $needle ),
		'Missing substring: ' . $needle
	);
}

function find_tool_contract( string $name ): array {
	$tools = PressArk_Tools::get_all( false, false );
	foreach ( $tools as $tool ) {
		if ( $name === ( $tool['name'] ?? '' ) ) {
			return $tool;
		}
	}

	return array();
}

PressArk_Operation_Registry::reset();
PressArk_Operation_Registry::boot();

echo "=== Operation Parameter Contract Tests ===\n\n";

$read_tool   = find_tool_contract( 'read_content' );
$read_schema = PressArk_Tools::tool_to_schema( $read_tool );
$read_params = $read_schema['function']['parameters'] ?? array();

assert_true_contract(
	'read_content schema is strict',
	false === ( $read_params['additionalProperties'] ?? true ),
	'additionalProperties should be false on the root schema.'
);
assert_true_contract(
	'read_content provider schema omits top-level composite keywords for transport compatibility',
	empty( $read_params['oneOf'] ?? array() )
		&& empty( $read_params['allOf'] ?? array() )
		&& empty( $read_params['anyOf'] ?? array() ),
	var_export( $read_params, true )
);
assert_eq_contract(
	'read_content mode enum is structural',
	array( 'summary', 'detail', 'raw', 'light', 'structured', 'full' ),
	$read_params['properties']['mode']['enum'] ?? null
);
assert_eq_contract(
	'read_content section enum is structural',
	array( 'head', 'tail', 'first_n_paragraphs' ),
	$read_params['properties']['section']['enum'] ?? null
);
assert_contains_contract(
	'read_content description carries operation-local guidance',
	'Provide exactly one target identifier',
	(string) ( $read_schema['function']['description'] ?? '' )
);

$read_validation = PressArk_Operation_Registry::validate_input(
	'read_content',
	array(
		'post_id' => 12,
		'url'     => 'https://example.com/about',
	)
);
assert_true_contract(
	'read_content rejects mutually exclusive identifiers at runtime',
	false === ( $read_validation['valid'] ?? true ),
	(string) ( $read_validation['message'] ?? '' )
);

$search_tool   = find_tool_contract( 'search_content' );
$search_schema = PressArk_Tools::tool_to_schema( $search_tool );
$search_params = $search_schema['function']['parameters'] ?? array();

assert_true_contract(
	'search_content meta_compare enum includes NOT EXISTS',
	in_array( 'NOT EXISTS', (array) ( $search_params['properties']['meta_compare']['enum'] ?? array() ), true ),
	implode( ', ', (array) ( $search_params['properties']['meta_compare']['enum'] ?? array() ) )
);
assert_true_contract(
	'search_content provider schema omits top-level composite keywords for transport compatibility',
	empty( $search_params['oneOf'] ?? array() )
		&& empty( $search_params['allOf'] ?? array() )
		&& empty( $search_params['anyOf'] ?? array() ),
	var_export( $search_params, true )
);

$search_validation = PressArk_Operation_Registry::validate_input(
	'search_content',
	array(
		'query'        => 'pricing',
		'meta_compare' => 'LIKE',
	)
);
assert_true_contract(
	'search_content rejects meta_compare without meta_key',
	false === ( $search_validation['valid'] ?? true ),
	(string) ( $search_validation['message'] ?? '' )
);

$edit_validation = PressArk_Operation_Registry::validate_input(
	'edit_content',
	array(
		'changes' => array(
			'post_id' => 77,
			'title'   => 'Updated title',
		),
	)
);
assert_true_contract(
	'edit_content keeps legacy changes.post_id alias working',
	true === ( $edit_validation['valid'] ?? false )
		&& 77 === (int) ( $edit_validation['params']['post_id'] ?? 0 ),
	var_export( $edit_validation, true )
);

$bulk_tool   = find_tool_contract( 'bulk_edit' );
$bulk_schema = PressArk_Tools::tool_to_schema( $bulk_tool );
$bulk_params = $bulk_schema['function']['parameters'] ?? array();

assert_eq_contract(
	'bulk_edit post_ids item type is explicit',
	'integer',
	$bulk_params['properties']['post_ids']['items']['type'] ?? null
);
assert_true_contract(
	'bulk_edit changes object is strict and shaped',
	false === ( $bulk_params['properties']['changes']['additionalProperties'] ?? true )
		&& ! empty( $bulk_params['properties']['changes']['properties']['status'] ),
	var_export( $bulk_params['properties']['changes'] ?? null, true )
);

$settings_tool   = find_tool_contract( 'update_site_settings' );
$settings_schema = PressArk_Tools::tool_to_schema( $settings_tool );
$settings_params = $settings_schema['function']['parameters'] ?? array();

assert_true_contract(
	'update_site_settings changes object is strict and excludes readonly settings',
	false === ( $settings_params['properties']['changes']['additionalProperties'] ?? true )
		&& empty( $settings_params['properties']['changes']['properties']['siteurl'] ?? null ),
	var_export( $settings_params['properties']['changes'] ?? null, true )
);

$settings_validation = PressArk_Operation_Registry::validate_input(
	'update_site_settings',
	array(
		'changes' => array(
			'siteurl' => 'https://example.com',
		),
	)
);
assert_true_contract(
	'update_site_settings rejects readonly legacy settings at runtime',
	false === ( $settings_validation['valid'] ?? true ),
	var_export( $settings_validation, true )
);

$edit_product_validation = PressArk_Operation_Registry::validate_input(
	'edit_product',
	array(
		'product_id' => 55,
		'price'      => '19.99',
	)
);
assert_true_contract(
	'edit_product keeps top-level product aliases working under the authoritative contract',
	true === ( $edit_product_validation['valid'] ?? false )
		&& 55 === (int) ( $edit_product_validation['params']['post_id'] ?? 0 )
		&& '19.99' === (string) ( $edit_product_validation['params']['changes']['regular_price'] ?? '' )
		&& ! array_key_exists( 'price', (array) ( $edit_product_validation['params'] ?? array() ) ),
	var_export( $edit_product_validation, true )
);

$bulk_products_validation = PressArk_Operation_Registry::validate_input(
	'bulk_edit_products',
	array(
		'products' => '[{"id":12,"price":"29.99"},{"product_id":14,"changes":{"stock":5}}]',
	)
);
assert_true_contract(
	'bulk_edit_products decodes JSON arrays and normalizes nested legacy aliases',
	true === ( $bulk_products_validation['valid'] ?? false )
		&& 12 === (int) ( $bulk_products_validation['params']['products'][0]['post_id'] ?? 0 )
		&& '29.99' === (string) ( $bulk_products_validation['params']['products'][0]['changes']['regular_price'] ?? '' )
		&& 5 === (int) ( $bulk_products_validation['params']['products'][1]['changes']['stock_quantity'] ?? 0 ),
	var_export( $bulk_products_validation, true )
);

$create_order_validation = PressArk_Operation_Registry::validate_input(
	'create_order',
	array(
		'items' => array(
			array(
				'id'       => 91,
				'quantity' => 2,
			),
		),
	)
);
assert_true_contract(
	'create_order keeps items/id legacy aliases working',
	true === ( $create_order_validation['valid'] ?? false )
		&& 91 === (int) ( $create_order_validation['params']['products'][0]['product_id'] ?? 0 ),
	var_export( $create_order_validation, true )
);

$rest_tool   = find_tool_contract( 'call_rest_endpoint' );
$rest_schema = PressArk_Tools::tool_to_schema( $rest_tool );
$rest_params = $rest_schema['function']['parameters'] ?? array();

assert_eq_contract(
	'call_rest_endpoint method enum is structural',
	array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
	$rest_params['properties']['method']['enum'] ?? null
);
assert_true_contract(
	'call_rest_endpoint schema stays strict at the root',
	false === ( $rest_params['additionalProperties'] ?? true ),
	var_export( $rest_params, true )
);
assert_contains_contract(
	'call_rest_endpoint description carries operation-local guidance',
	'Prefer GET for reads',
	(string) ( $rest_schema['function']['description'] ?? '' )
);

$load_tools_guidance = PressArk_Operation_Registry::get_model_guidance( 'load_tools' );
$load_tools_prompt_weight = strlen( implode( ' ', $load_tools_guidance ) );
assert_true_contract(
	'load_tools exposes narrow-load guidance locally on the operation contract',
	in_array( 'Load only the narrowest set of tools or groups needed for the next step.', $load_tools_guidance, true ),
	var_export( $load_tools_guidance, true )
);
assert_true_contract(
	'load_tools operation guidance stays compact for prompt weight',
	$load_tools_prompt_weight <= 180,
	'Chars=' . $load_tools_prompt_weight . ' Guidance=' . var_export( $load_tools_guidance, true )
);

$engine = new PressArk_Action_Engine( new PressArk_Action_Logger() );
$extract_method = new ReflectionMethod( PressArk_Action_Engine::class, 'extract_action_params' );
$extract_method->setAccessible( true );
$merged_action_params = $extract_method->invoke(
	$engine,
	array(
		'type'   => 'edit_product',
		'params' => array(
			'product_id' => 77,
		),
		'price'  => '42.00',
	)
);
$merged_action_validation = PressArk_Operation_Registry::validate_input( 'edit_product', (array) $merged_action_params );
assert_true_contract(
	'action engine merges top-level legacy fields before authoritative validation',
	true === ( $merged_action_validation['valid'] ?? false )
		&& '42.00' === (string) ( $merged_action_validation['params']['changes']['regular_price'] ?? '' ),
	var_export( $merged_action_validation, true )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
