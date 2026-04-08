<?php
/**
 * Targeted verification for shared billing-state contract behavior.
 *
 * Run: php pressark/tests/test-billing-state-contract.php
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
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $key ) ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) {
		unset( $type, $gmt );
		return '2026-04-04 12:00:00';
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
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['pressark_test_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		unset( $key );
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration ) {
		unset( $key, $value, $expiration );
		return true;
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
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 7;
	}
}

function pressark_get_upgrade_url() {
	return 'https://example.com/upgrade';
}

class PressArk_AI_Connector {
	public static function is_proxy_mode(): bool {
		return false;
	}
}

class PressArk_Activity_Trace {
	public static function current_context(): array {
		return array();
	}

	public static function normalize_correlation_id( string $correlation_id ): string {
		return $correlation_id;
	}

	public static function fetch_bank_trace( array $filters = array(), int $limit = 120 ): array {
		unset( $filters, $limit );
		return $GLOBALS['pressark_test_bank_trace'] ?? array();
	}
}

class PressArk_Model_Policy {
	public static function resolve( string $tier ): string {
		unset( $tier );
		return 'openai/gpt-test';
	}

	public static function get_model_class( string $model ): string {
		unset( $model );
		return 'standard';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( $data = null, int $status = 200 ) {
			unset( $data, $status );
		}
	}
}

if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
	class PressArk_Usage_Tracker {
		public function get_usage_data(): array {
			return array();
		}
	}
}

if ( ! class_exists( 'PressArk_Throttle' ) ) {
	class PressArk_Throttle {
		public function release_slot( int $user_id, string $slot_id = '' ): void {
			unset( $user_id, $slot_id );
		}
	}
}

require_once __DIR__ . '/../includes/class-pressark-entitlements.php';
require_once __DIR__ . '/../includes/class-pressark-cost-ledger.php';
require_once __DIR__ . '/../includes/class-pressark-token-bank.php';
require_once __DIR__ . '/../includes/class-pressark-reservation.php';
require_once __DIR__ . '/../includes/class-pressark-pipeline.php';

class PressArk_Test_Token_Bank_Double extends PressArk_Token_Bank {
	public array $status;
	public array $resolved;

	public function __construct( array $status = array(), array $resolved = array() ) {
		parent::__construct();
		$this->status = $status;
		$this->resolved = $resolved ?: array(
			'icu_total'   => 0,
			'model_class' => 'standard',
			'multiplier'  => array(
				'input'  => 10,
				'output' => 30,
			),
		);
	}

	public function get_status(): array {
		return $this->status;
	}

	public function resolve_icus( array $usage ): array {
		unset( $usage );
		return $this->resolved;
	}

	public function settle( string $reservation_id, array|int $actual_usage, string $tier = 'free' ): array {
		unset( $reservation_id, $actual_usage, $tier );
		return $this->status;
	}
}

class PressArk_Test_Cost_Ledger_Double extends PressArk_Cost_Ledger {
	public array $reservation;
	public array $settled_payload = array();

	public function __construct( array $reservation = array() ) {
		$this->reservation = $reservation;
	}

	public function get_by_reservation( string $reservation_id ): array {
		unset( $reservation_id );
		return $this->reservation;
	}

	public function settle( string $reservation_id, array $actual ): bool {
		unset( $reservation_id );
		$this->settled_payload = $actual;
		return true;
	}
}

$passed = 0;
$failed = 0;

function assert_same_billing( string $label, $expected, $actual ): void {
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

function assert_true_billing( string $label, bool $condition ): void {
	assert_same_billing( $label, true, $condition );
}

function invoke_private( object $object, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function inject_private_property( object $object, string $property, $value ): void {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );
	$reflection->setValue( $object, $value );
}

function find_receipt_stage( array $stages, string $key ): array {
	foreach ( $stages as $stage ) {
		if ( ! is_array( $stage ) ) {
			continue;
		}
		if ( $key === (string) ( $stage['key'] ?? '' ) ) {
			return $stage;
		}
	}

	return array();
}

function find_surface_detail( array $rows, string $label ): array {
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( $label === (string) ( $row['label'] ?? '' ) ) {
			return $row;
		}
	}

	return array();
}

function find_surface_notice( array $rows, string $label ): array {
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		if ( $label === (string) ( $row['label'] ?? '' ) ) {
			return $row;
		}
	}

	return array();
}

echo "=== Billing State Contract Tests ===\n\n";

$GLOBALS['pressark_test_options'] = array(
	'pressark_byok_enabled'       => false,
	'pressark_handshake_verified' => true,
);

echo "--- Verified and provisional authority modes stay distinct ---\n";
$bank = new PressArk_Token_Bank();
$verified_status = invoke_private(
	$bank,
	'normalize_status',
	array(
		array(
			'tier'                       => 'pro',
			'verified'                   => true,
			'monthly_icu_budget'         => 5000000,
			'monthly_included_remaining' => 4000000,
			'total_remaining'            => 4000000,
		),
		'pro',
	)
);
$GLOBALS['pressark_test_options']['pressark_handshake_verified'] = false;
$provisional_status = invoke_private(
	$bank,
	'normalize_status',
	array(
		array(
			'tier'                       => 'pro',
			'verified'                   => false,
			'monthly_icu_budget'         => 5000000,
			'monthly_included_remaining' => 0,
			'purchased_credits_remaining'=> 800000,
			'total_remaining'            => 800000,
		),
		'pro',
	)
);

assert_same_billing( 'Verified authority mode is bank_verified', 'bank_verified', $verified_status['billing_state']['authority_mode'] ?? '' );
assert_same_billing( 'Verified handshake state is verified', 'verified', $verified_status['billing_state']['handshake_state'] ?? '' );
assert_same_billing( 'Verified spend source stays monthly included', 'monthly_included', $verified_status['billing_state']['spend_source'] ?? '' );
assert_same_billing( 'Legacy flat authority follows verified mode', 'token_bank_verified', $verified_status['billing_authority'] ?? '' );
assert_same_billing( 'Provisional authority mode is bank_provisional', 'bank_provisional', $provisional_status['billing_state']['authority_mode'] ?? '' );
assert_same_billing( 'Provisional handshake state is provisional', 'provisional', $provisional_status['billing_state']['handshake_state'] ?? '' );
assert_same_billing( 'Purchased-credit spend source is explicit', 'purchased_credits', $provisional_status['billing_state']['spend_source'] ?? '' );

echo "\n--- Offline-assisted mode preserves bank authority semantics ---\n";
$GLOBALS['pressark_test_options']['pressark_handshake_verified'] = true;
$offline_status = invoke_private(
	$bank,
	'normalize_status',
	array(
		array(
			'tier'                       => 'pro',
			'verified'                   => true,
			'offline'                    => true,
			'monthly_icu_budget'         => 5000000,
			'monthly_included_remaining' => 1250000,
			'total_remaining'            => 1250000,
		),
		'pro',
	)
);

assert_same_billing( 'Offline-assisted service state is explicit', 'offline_assisted', $offline_status['billing_state']['service_state'] ?? '' );
assert_same_billing( 'Offline-assisted path keeps verified authority mode', 'bank_verified', $offline_status['billing_state']['authority_mode'] ?? '' );
assert_same_billing( 'Offline-assisted path maps legacy authority to cached', 'token_bank_cached', $offline_status['billing_authority'] ?? '' );

echo "\n--- Reduced-certainty reserve envelopes stay explicit offline ---\n";
$offline_emergency = invoke_private(
	$bank,
	'offline_reserve',
	array(
		2000,
		'pro',
	)
);
$stale_envelope = invoke_private(
	$bank,
	'build_offline_reserve_envelope',
	array(
		5000000,
		20000,
		3000,
		'2026-04-04 11:40:00',
	)
);

assert_same_billing( 'Offline reserve marks reduced certainty', 'reduced', $offline_emergency['reserve_certainty'] ?? '' );
assert_same_billing( 'Offline reserve estimate mode is explicit', 'offline_emergency_envelope', $offline_emergency['billing_state']['estimate_mode'] ?? '' );
assert_true_billing( 'Offline reserve notice mentions the reduced-certainty cap', false !== strpos( (string) ( $offline_emergency['billing_state']['estimate_notice'] ?? '' ), 'Reduced-certainty reserve' ) );
assert_same_billing( 'Stale snapshot reserve keeps a snapshot-specific mode', 'offline_snapshot_envelope', $stale_envelope['mode'] ?? '' );
assert_true_billing( 'Stale snapshot reserve stays below snapshot remaining', (int) ( $stale_envelope['limit_icus'] ?? 0 ) < 20000 );

echo "\n--- BYOK remains a separate authority mode ---\n";
$GLOBALS['pressark_test_options']['pressark_byok_enabled'] = true;
$GLOBALS['pressark_test_options']['pressark_handshake_verified'] = true;
$GLOBALS['pressark_test_options']['pressark_last_token_status_7'] = array(
	'billing_authority' => 'token_bank_verified',
	'billing_state'     => array(
		'authority_mode' => 'bank_verified',
		'handshake_state'=> 'verified',
		'service_state'  => 'normal',
		'spend_source'   => 'monthly_included',
	),
);
$byok_bank = new PressArk_Token_Bank();
$byok_snapshot = $byok_bank->get_financial_snapshot();
$byok_status   = $byok_bank->get_status();

assert_same_billing( 'BYOK authority mode is distinct', 'byok', $byok_snapshot['billing_state']['authority_mode'] ?? '' );
assert_same_billing( 'BYOK handshake state is not bank provisional', 'byok', $byok_snapshot['billing_state']['handshake_state'] ?? '' );
assert_same_billing( 'BYOK spend source is distinct', 'byok', $byok_snapshot['billing_state']['spend_source'] ?? '' );
assert_same_billing( 'BYOK keeps flat authority distinct too', 'byok', $byok_snapshot['billing_authority'] ?? '' );
assert_same_billing( 'BYOK does not pretend to have a verified bank handshake', false, $byok_snapshot['verified_handshake'] ?? true );
assert_same_billing( 'BYOK status ignores stale cached bank authority', 'byok', $byok_status['billing_authority'] ?? '' );
assert_same_billing( 'BYOK status keeps authority mode canonical', 'byok', $byok_status['billing_state']['authority_mode'] ?? '' );
assert_same_billing( 'BYOK transport mode is explicit', 'direct', $byok_status['transport_mode'] ?? '' );

echo "\n--- Settlement delta explains estimate versus settled charge ---\n";
$GLOBALS['pressark_test_options']['pressark_byok_enabled'] = false;
$GLOBALS['pressark_test_options']['pressark_handshake_verified'] = true;
$ledger = new PressArk_Test_Cost_Ledger_Double(
	array(
		'reservation_id'   => 'reservation_1',
		'estimated_icus'   => 1200,
		'estimated_tokens' => 512,
	)
);
$bank_double = new PressArk_Test_Token_Bank_Double(
	array(
		'actual_icus'      => 1500,
		'raw_actual_tokens'=> 640,
		'billing_state'    => array(
			'authority_mode' => 'bank_verified',
			'handshake_state'=> 'verified',
			'service_state'  => 'normal',
			'spend_source'   => 'monthly_included',
		),
	),
	array(
		'icu_total'   => 1500,
		'model_class' => 'standard',
		'multiplier'  => array(
			'input'  => 10,
			'output' => 30,
		),
	)
);
$reservation = new PressArk_Reservation();
inject_private_property( $reservation, 'ledger', $ledger );
inject_private_property( $reservation, 'token_bank', $bank_double );
$settled = $reservation->settle(
	'reservation_1',
	array(
		'model'         => 'openai/gpt-test',
		'input_tokens'  => 20,
		'output_tokens' => 10,
	),
	'pro'
);

assert_same_billing( 'Settlement delta keeps plugin estimate advisory', 'plugin_local_advisory', $settled['settlement_delta']['estimate_authority'] ?? '' );
assert_same_billing( 'Settlement delta settles against bank authority', 'bank', $settled['settlement_delta']['settlement_authority'] ?? '' );
assert_same_billing( 'Settlement delta carries the estimated ICU hold', 1200, $settled['settlement_delta']['estimated_icus'] ?? 0 );
assert_same_billing( 'Settlement delta carries the settled ICU charge', 1500, $settled['settlement_delta']['settled_icus'] ?? 0 );
assert_same_billing( 'Settlement delta shows the upward adjustment', 300, $settled['settlement_delta']['delta_icus'] ?? 0 );
assert_true_billing( 'Settlement delta summary explains the change', false !== strpos( (string) ( $settled['settlement_delta']['summary'] ?? '' ), 'bank settled more ICUs' ) );

echo "\n--- Per-run receipt keeps authority tags and settlement seams explicit ---\n";
$GLOBALS['pressark_test_bank_trace'] = array(
	array(
		'event_type'     => 'bank.reserve',
		'status'         => 'held',
		'reservation_id' => 'reservation_1',
		'payload'        => array(
			'requested_icus'       => 1200,
			'estimated_raw_tokens' => 512,
		),
	),
	array(
		'event_type'     => 'bank.settle',
		'status'         => 'applied',
		'reservation_id' => 'reservation_1',
		'payload'        => array(
			'raw_actual_tokens' => 640,
			'actual_icus'       => 1500,
		),
	),
);
$pipeline_reflection = new ReflectionClass( 'PressArk_Pipeline' );
$pipeline            = $pipeline_reflection->newInstanceWithoutConstructor();
$receipt             = invoke_private(
	$pipeline,
	'build_billing_receipt',
	array(
		array(
			'billing_state'          => array(
				'authority_label'  => 'Bank verified',
				'authority_notice' => 'The bank is authoritative for bundled billing.',
				'service_label'    => 'Normal service',
				'spend_label'      => 'Monthly included',
				'estimate_notice'  => 'Plugin estimates stay advisory until the bank settles the run.',
			),
			'estimated_request_icus' => 1100,
			'estimated_prompt_tokens'=> 480,
			'settlement_delta'       => array(
				'estimated_icus'       => 1200,
				'estimated_raw_tokens' => 512,
				'settled_icus'         => 1500,
				'delta_icus'           => 300,
				'summary'              => 'The bank settled more ICUs after provider usage was reported.',
			),
			'raw_actual_tokens'      => 640,
			'actual_icus'            => 1500,
		),
		array(
			'correlation_id' => 'corr_1',
			'reservation_id' => 'reservation_1',
		),
		array(
			'raw_actual_tokens' => 640,
			'actual_icus'       => 1500,
		),
	)
);
$planned_stage = find_receipt_stage( (array) ( $receipt['stages'] ?? array() ), 'planned' );
$settled_stage = find_receipt_stage( (array) ( $receipt['stages'] ?? array() ), 'bank_settled' );
$surface       = PressArk_Pipeline::build_run_surface(
	array(
		'reservation_id' => 'reservation_1',
	),
	'agent',
	array(),
	array(
		'billing_receipt' => $receipt,
	)
);

assert_same_billing( 'Receipt contract is explicit', 'billing_receipt', $receipt['contract'] ?? '' );
assert_same_billing( 'Receipt exposes the authority label', 'Bank verified', $receipt['authority_label'] ?? '' );
assert_same_billing( 'Planned stage keeps plugin-plan authority', 'Plugin plan', $planned_stage['authority_tag'] ?? '' );
assert_same_billing( 'Bank-settled stage shows the estimate delta', '+300 ICUs vs estimate', $settled_stage['secondary'] ?? '' );
assert_true_billing( 'Run surface carries the receipt authority badge', in_array( 'Bank verified', array_column( (array) ( $surface['badges'] ?? array() ), 'label' ), true ) );

echo "\n--- Run surface keeps enforced versus downgraded request contracts explicit ---\n";
$contract_surface = PressArk_Pipeline::build_run_surface(
	array(
		'provider'          => 'deepseek',
		'model'             => 'deepseek/deepseek-chat',
		'context_inspector' => array(
			'provider_request' => array(
				'transport_provider' => 'deepseek',
				'model'              => 'deepseek/deepseek-chat',
				'transport_contract' => array(
					'tool_choice' => array(
						'requested'  => 'required',
						'effective'  => 'restricted_auto',
						'transport'  => 'omitted',
						'downgraded' => true,
						'reason'     => 'provider_cannot_require_tools',
					),
					'structured_output' => array(
						'requested'  => 'strict',
						'effective'  => 'prompt_only',
						'transport'  => 'prompt_only',
						'downgraded' => true,
						'reason'     => 'provider_model_lacks_native_structured_outputs',
					),
				),
			),
		),
	),
	'agent'
);
$tool_choice_row      = find_surface_detail( (array) ( $contract_surface['detail_rows'] ?? array() ), 'Tool choice' );
$structured_output_row = find_surface_detail( (array) ( $contract_surface['detail_rows'] ?? array() ), 'Structured output' );

assert_true_billing(
	'Run surface shows downgraded tool-choice truthfully',
	false !== strpos( (string) ( $tool_choice_row['value'] ?? '' ), 'Restricted auto tools' )
		&& false !== strpos( (string) ( $tool_choice_row['value'] ?? '' ), 'downgraded' )
);
assert_true_billing(
	'Run surface shows downgraded structured output truthfully',
	false !== strpos( (string) ( $structured_output_row['value'] ?? '' ), 'Prompt-only schema guidance' )
		&& false !== strpos( (string) ( $structured_output_row['value'] ?? '' ), 'downgraded' )
);
assert_true_billing(
	'Run surface degraded summary includes request-contract downgrades',
	false !== strpos( (string) ( $contract_surface['summary'] ?? '' ), 'Tool policy downgraded' )
		&& false !== strpos( (string) ( $contract_surface['summary'] ?? '' ), 'Structured output downgraded' )
);

echo "\n--- Run surface shows compact harness-state chips and recovery notices ---\n";
$harness_surface = PressArk_Pipeline::build_run_surface(
	array(
		'provider'       => 'anthropic',
		'model'          => 'claude-sonnet',
		'harness_state'  => array(
			'loaded_groups'  => array( 'content', 'settings' ),
			'deferred_groups'=> array( 'seo' ),
			'blocked_groups' => array( 'security' ),
			'hidden_reasons' => array(
				array(
					'label'   => 'Quota reached',
					'summary' => 'SEO tools stayed hidden because the current quota window is exhausted.',
					'hint'    => 'Retry after the quota resets or upgrade the plan, then rerun.',
					'groups'  => array( 'seo' ),
					'count'   => 1,
				),
			),
			'context_trim' => array(
				'count'  => 1,
				'label'  => 'Context trimmed',
				'detail' => 'Earlier context was compacted to keep the run moving.',
				'hint'   => 'If something seems missing, continue from the latest result or rerun with a narrower scope.',
			),
			'route_status' => array(
				'label'  => 'Fallback route active',
				'detail' => 'Route degraded after provider policy.',
				'hint'   => 'If you need the strongest tool or format support, retry in Deep Mode or rerun later.',
			),
		),
		'context_inspector' => array(
			'provider_request' => array(
				'transport_provider' => 'anthropic',
				'model'              => 'claude-sonnet',
			),
		),
		'routing_decision' => array(
			'fallback' => array(
				'used'          => true,
				'failure_class' => 'provider_policy',
			),
		),
	),
	'agent'
);
$quota_notice = find_surface_notice( (array) ( $harness_surface['notices'] ?? array() ), 'Quota reached' );
assert_true_billing(
	'Run surface emits compact state chips for loaded and deferred groups',
	in_array( 'Loaded', array_column( (array) ( $harness_surface['metric_chips'] ?? array() ), 'label' ), true )
		&& in_array( 'Deferred', array_column( (array) ( $harness_surface['metric_chips'] ?? array() ), 'label' ), true )
);
assert_true_billing(
	'Run surface carries actionable recovery text for quota-driven visibility loss',
	false !== strpos( (string) ( $quota_notice['hint'] ?? '' ), 'Retry after the quota resets' )
);
assert_true_billing(
	'Run surface summary keeps deferred and blocked state visible',
	false !== strpos( (string) ( $harness_surface['summary'] ?? '' ), 'deferred' )
		&& false !== strpos( (string) ( $harness_surface['summary'] ?? '' ), 'blocked' )
);

echo "\n--- BYOK settlement stays out of bundled credit authority ---\n";
$GLOBALS['pressark_test_options']['pressark_byok_enabled'] = true;
$byok_ledger = new PressArk_Test_Cost_Ledger_Double(
	array(
		'reservation_id'   => 'reservation_2',
		'estimated_icus'   => 900,
		'estimated_tokens' => 300,
	)
);
$byok_bank_double = new PressArk_Test_Token_Bank_Double(
	array(
		'billing_state' => array(
			'authority_mode' => 'byok',
			'handshake_state'=> 'byok',
			'service_state'  => 'normal',
			'spend_source'   => 'byok',
		),
	),
	array(
		'icu_total'   => 900,
		'model_class' => 'standard',
		'multiplier'  => array(
			'input'  => 10,
			'output' => 30,
		),
	)
);
$byok_reservation = new PressArk_Reservation();
inject_private_property( $byok_reservation, 'ledger', $byok_ledger );
inject_private_property( $byok_reservation, 'token_bank', $byok_bank_double );
$byok_settled = $byok_reservation->settle(
	'reservation_2',
	array(
		'model'         => 'openai/gpt-test',
		'input_tokens'  => 10,
		'output_tokens' => 10,
		'settled_tokens'=> 40,
	),
	'pro'
);

assert_same_billing( 'BYOK settlement authority stays provider usage', 'provider_usage', $byok_settled['settlement_delta']['settlement_authority'] ?? '' );
assert_same_billing( 'BYOK delta still compares estimate and actual usage', 0, $byok_settled['settlement_delta']['delta_icus'] ?? -1 );

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
