<?php
/**
 * Targeted verification for RoutingDecision sidecars and safe fallback.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-routing-decision.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'PRESSARK_DISABLE_PROXY' ) ) {
	define( 'PRESSARK_DISABLE_PROXY', true );
}

$pressark_test_options        = array(
	'pressark_api_provider'   => 'openrouter',
	'pressark_api_key'        => 'test-key',
	'pressark_model'          => 'auto',
	'pressark_summarize_model'=> 'auto',
	'pressark_summarize_custom_model' => '',
	'pressark_byok_enabled'   => false,
	'pressark_byok_provider'  => 'openai',
	'pressark_byok_api_key'   => 'byok-test-key',
	'pressark_byok_model'     => 'openai/gpt-5.4',
);
$pressark_http_responses      = array();
$pressark_http_requests       = array();

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

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $callback = false ) {
		unset( $tag, $callback );
		return false;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://example.com';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $pressark_test_options;
		return array_key_exists( $key, $pressark_test_options ) ? $pressark_test_options[ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		global $pressark_test_options;
		unset( $autoload );
		$pressark_test_options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			unset( $code );
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args ) {
		global $pressark_http_responses, $pressark_http_requests;

		$pressark_http_requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
		);

		if ( empty( $pressark_http_responses ) ) {
			return new WP_Error( 'no_response', 'No queued HTTP response.' );
		}

		return array_shift( $pressark_http_responses );
	}
}

if ( ! class_exists( 'PressArk_Skills' ) ) {
	class PressArk_Skills {
		public static function core(): string {
			return 'core';
		}

		public static function woocommerce(): string {
			return 'woocommerce';
		}

		public static function elementor(): string {
			return 'elementor';
		}

		public static function reference(): string {
			return 'reference';
		}
	}
}

if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
	class PressArk_Usage_Tracker {
		public static function decrypt_value( string $value ): string {
			return $value;
		}

		public function is_byok(): bool {
			global $pressark_test_options;
			return ! empty( $pressark_test_options['pressark_byok_enabled'] );
		}

		public function get_byok_provider(): string {
			global $pressark_test_options;
			return (string) ( $pressark_test_options['pressark_byok_provider'] ?? 'openai' );
		}

		public function get_byok_api_key(): string {
			global $pressark_test_options;
			return (string) ( $pressark_test_options['pressark_byok_api_key'] ?? '' );
		}
	}
}

if ( ! class_exists( 'PressArk_Entitlements' ) ) {
	class PressArk_Entitlements {
		public static function is_paid_tier( string $tier ): bool {
			return in_array( $tier, array( 'pro', 'team', 'agency', 'enterprise' ), true );
		}

		public static function default_model( string $tier ): string {
			return self::is_paid_tier( $tier ) ? 'anthropic/claude-sonnet-4.6' : 'deepseek/deepseek-v3.2';
		}

		public static function is_byok(): bool {
			global $pressark_test_options;
			return ! empty( $pressark_test_options['pressark_byok_enabled'] );
		}
	}
}

if ( ! class_exists( 'PressArk_License' ) ) {
	class PressArk_License {
		public function get_tier(): string {
			return 'pro';
		}
	}
}

if ( ! class_exists( 'PressArk_Token_Budget_Manager' ) ) {
	class PressArk_Token_Budget_Manager {}
}

if ( ! class_exists( 'PressArk_Token_Bank' ) ) {
	class PressArk_Token_Bank {
		public function get_multipliers(): array {
			return array(
				'classes' => array(
					'standard' => array(
						'input'  => 10,
						'output' => 30,
					),
				),
				'model_to_class' => array(),
				'default_class'  => 'standard',
			);
		}

		public function resolve_icus( array $usage ): array {
			$input_tokens  = (int) ( $usage['input_tokens'] ?? 0 );
			$output_tokens = (int) ( $usage['output_tokens'] ?? 0 );

			return array(
				'icu_total'   => max( 1, (int) ceil( ( $input_tokens * 10 + $output_tokens * 30 ) / 1000 ) ),
				'model_class' => 'standard',
				'multiplier'  => array(
					'input'  => 10,
					'output' => 30,
				),
			);
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-model-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-connector.php';

$passed = 0;
$failed = 0;

function assert_routing( string $label, bool $condition, string $detail = '' ): void {
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

function invoke_private_routing( object $object, string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $object, $args );
}

function read_private_property_routing( object $object, string $property ) {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );
	return $reflection->getValue( $object );
}

function inject_private_property_routing( object $object, string $property, $value ): void {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );
	$reflection->setValue( $object, $value );
}

echo "=== Routing Decision Tests ===\n\n";

$pressark_http_responses = array(
	array(
		'response' => array( 'code' => 503 ),
		'body'     => wp_json_encode( array(
			'error' => array(
				'message' => 'Upstream unavailable',
			),
		) ),
	),
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Recovered answer',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 120,
				'completion_tokens' => 30,
				'total_tokens'      => 150,
			),
		) ),
	),
);

$connector = new PressArk_AI_Connector( 'pro' );
$result    = $connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Diagnose the site issue.',
		),
	),
	array(),
	'Diagnostic context',
	false,
	array(
		'phase'          => 'diagnosis',
		'requires_tools' => true,
	)
);

$routing = (array) ( $result['routing_decision'] ?? array() );
$trace   = (array) ( $routing['fallback']['trace'] ?? array() );

echo '  HTTP models: ' . implode(
	' -> ',
	array_map(
		static function ( array $request ): string {
			return (string) ( $request['body']['model'] ?? '' );
		},
		$pressark_http_requests
	)
) . "\n";

assert_routing(
	'Fallback preserves a successful final raw response',
	'Recovered answer' === (string) ( $result['raw']['choices'][0]['message']['content'] ?? '' )
);
assert_routing(
	'RoutingDecision contract is attached',
	'RoutingDecision' === ( $routing['contract'] ?? '' ),
	'Actual contract: ' . var_export( $routing['contract'] ?? null, true )
);
assert_routing(
	'Phase-based selection is recorded',
	'phase' === ( $routing['selection']['basis'] ?? '' ) && 'diagnosis' === ( $routing['selection']['phase'] ?? '' ),
	'Selection: ' . var_export( $routing['selection'] ?? null, true )
);
assert_routing(
	'Fallback changes the effective model without changing transport provider',
	'openrouter' === ( $routing['provider'] ?? '' ) && 'openai/gpt-5.4' === ( $routing['model'] ?? '' ),
	'Routing: ' . var_export( $routing, true )
);
assert_routing(
	'Fallback telemetry records the retry path',
	true === ( $routing['fallback']['used'] ?? false )
		&& 2 === (int) ( $routing['fallback']['attempts'] ?? 0 )
		&& 'provider_error' === ( $trace[0]['failure_class'] ?? '' )
		&& '' === ( $trace[1]['failure_class'] ?? '' ),
	'Trace: ' . var_export( $trace, true )
);
assert_routing(
	'Capability assumptions do not pretend tools are required when none were exposed',
	empty( $routing['capability_assumptions']['requires_tools'] ),
	'Capability assumptions: ' . var_export( $routing['capability_assumptions'] ?? null, true )
);
assert_routing(
	'HTTP retries follow the expected candidate order',
	2 === count( $pressark_http_requests )
		&& 'anthropic/claude-sonnet-4.6' === ( $pressark_http_requests[0]['body']['model'] ?? '' )
		&& 'openai/gpt-5.4' === ( $pressark_http_requests[1]['body']['model'] ?? '' ),
	'Requests: ' . var_export( $pressark_http_requests, true )
);

$pressark_http_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array(
		'choices' => array(
			array(
				'message' => array(
					'role'    => 'assistant',
					'content' => 'Snapshot probe',
				),
				'finish_reason' => 'stop',
			),
		),
		'usage'   => array(
			'prompt_tokens'     => 40,
			'completion_tokens' => 10,
			'total_tokens'      => 50,
		),
	) ),
);
$connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Read the next page.',
		),
	),
	array(
		array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'read_content',
				'description' => 'Read one page.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
		),
	),
	'Compact phase context',
	false,
	array(
		'phase' => 'retrieval_planning',
	)
);
$snapshot = $connector->get_last_request_snapshot();
$snapshot_system_prompt = '';
foreach ( (array) ( $pressark_http_requests[2]['body']['messages'] ?? array() ) as $message ) {
	if ( 'system' === ( $message['role'] ?? '' ) ) {
		$snapshot_system_prompt = (string) ( $message['content'] ?? '' );
		break;
	}
}
assert_routing(
	'Prompt snapshot records the compact phase addendum contract',
	'retrieval_planning' === ( $snapshot['phase_addendum']['phase'] ?? '' )
		&& 'restricted_auto' === ( $snapshot['phase_addendum']['tool_choice'] ?? '' ),
	'Snapshot: ' . var_export( $snapshot['phase_addendum'] ?? null, true )
);
assert_routing(
	'Prompt snapshot no longer records default global tool heuristics',
	! array_key_exists( 'tool_heuristics', (array) ( $snapshot['phase_addendum'] ?? array() ) ),
	'Snapshot: ' . var_export( $snapshot['phase_addendum'] ?? null, true )
);
assert_routing(
	'Prompt addendum stays compact even when tools are present',
	(int) ( $snapshot['phase_addendum']['tokens'] ?? 0 ) > 0
		&& (int) ( $snapshot['phase_addendum']['tokens'] ?? 0 ) <= 80,
	'Snapshot: ' . var_export( $snapshot['phase_addendum'] ?? null, true )
);
assert_routing(
	'Provider-facing system prompt omits legacy allowed-tool and heuristic prose',
	false === strpos( $snapshot_system_prompt, 'Allowed tools:' )
		&& false === strpos( $snapshot_system_prompt, 'Heuristics:' ),
	$snapshot_system_prompt
);

echo "\n--- Streaming execution refreshes stale planner contracts before tool-bearing rounds ---\n";

$read_stream_tool = array(
	array(
		'type'     => 'function',
		'function' => array(
			'name'        => 'read_content',
			'description' => 'Read one page.',
			'parameters'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array( 'type' => 'integer' ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
		),
	),
);

$pressark_test_options['pressark_api_provider'] = 'openrouter';
$pressark_test_options['pressark_model']        = 'deepseek/deepseek-v3.2';
$pressark_test_options['pressark_byok_enabled'] = false;
$pressark_http_requests                         = array();
$pressark_http_responses                        = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Planner',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 40,
				'completion_tokens' => 10,
				'total_tokens'      => 50,
			),
		) ),
	),
);

$bank_stream_connector = new PressArk_AI_Connector( 'pro' );
$bank_stream_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Plan the next read.',
		),
	),
	array(),
	'Planner phase context',
	false,
	array(
		'phase' => 'classification',
	)
);
$bank_planner_snapshot = $bank_stream_connector->get_last_request_snapshot();
$bank_stream_request   = $bank_stream_connector->prepare_streaming_request(
	array(
		array(
			'role'    => 'user',
			'content' => 'Read the current page.',
		),
	),
	$read_stream_tool,
	'Execution phase context'
);
$bank_stream_snapshot = $bank_stream_connector->get_last_request_snapshot();

assert_routing(
	'Planner snapshot keeps classification rounds text-only',
	'classification' === ( $bank_planner_snapshot['phase_addendum']['phase'] ?? '' )
		&& 'text_only' === ( $bank_planner_snapshot['phase_addendum']['tool_choice'] ?? '' ),
	'Snapshot: ' . var_export( $bank_planner_snapshot, true )
);
assert_routing(
	'Streaming execution refreshes bank requests to the tool-bearing contract',
	'restricted_auto' === ( $bank_stream_snapshot['phase_addendum']['tool_choice'] ?? '' )
		&& 1 === (int) ( $bank_stream_snapshot['request_shape']['tool_schema_count'] ?? 0 )
		&& 'read_content' === ( $bank_stream_snapshot['allowed_tools'][0] ?? '' )
		&& 'deepseek/deepseek-v3.2' === ( $bank_stream_request['body']['model'] ?? '' ),
	'Snapshot: ' . var_export( $bank_stream_snapshot, true ) . ' Request: ' . var_export( $bank_stream_request, true )
);

$pressark_test_options['pressark_byok_enabled']  = true;
$pressark_test_options['pressark_byok_provider'] = 'openrouter';
$pressark_test_options['pressark_byok_api_key']  = 'byok-test-key';
$pressark_test_options['pressark_byok_model']    = 'deepseek/deepseek-v3.2';
$pressark_http_requests                          = array();
$pressark_http_responses                         = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Planner',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 38,
				'completion_tokens' => 9,
				'total_tokens'      => 47,
			),
		) ),
	),
);

$byok_stream_connector = new PressArk_AI_Connector( 'pro' );
$byok_stream_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Plan the next read.',
		),
	),
	array(),
	'Planner phase context',
	false,
	array(
		'phase' => 'classification',
	)
);
$byok_stream_request  = $byok_stream_connector->prepare_streaming_request(
	array(
		array(
			'role'    => 'user',
			'content' => 'Read the current page.',
		),
	),
	$read_stream_tool,
	'Execution phase context'
);
$byok_stream_snapshot = $byok_stream_connector->get_last_request_snapshot();

assert_routing(
	'BYOK streaming execution uses the same refreshed execution contract as bank',
	'restricted_auto' === ( $byok_stream_snapshot['phase_addendum']['tool_choice'] ?? '' )
		&& ( $byok_stream_snapshot['allowed_tools'] ?? array() ) === ( $bank_stream_snapshot['allowed_tools'] ?? array() )
		&& ( $byok_stream_snapshot['request_shape']['tool_schema_count'] ?? 0 ) === ( $bank_stream_snapshot['request_shape']['tool_schema_count'] ?? 0 )
		&& 'deepseek/deepseek-v3.2' === ( $byok_stream_request['body']['model'] ?? '' ),
	'BYOK snapshot: ' . var_export( $byok_stream_snapshot, true ) . ' Request: ' . var_export( $byok_stream_request, true )
);

$pressark_test_options['pressark_byok_enabled'] = false;

echo "\n--- Truthful provider request shaping stays transport-aligned ---\n";

$strict_tool = array(
	array(
		'type'     => 'function',
		'function' => array(
			'name'        => 'lookup_context',
			'description' => 'Read supporting context.',
			'parameters'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'query' => array( 'type' => 'string' ),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
		),
	),
);
$strict_schema = array(
	'summary'   => 'string',
	'citations' => array( 'string' ),
);

$pressark_test_options['pressark_api_provider'] = 'openai';
$pressark_test_options['pressark_model']        = 'openai/gpt-5.4';
$pressark_http_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array(
		'choices' => array(
			array(
				'message' => array(
					'role'    => 'assistant',
					'content' => '{"summary":"ok","citations":["a"]}',
				),
				'finish_reason' => 'stop',
			),
		),
		'usage'   => array(
			'prompt_tokens'     => 90,
			'completion_tokens' => 20,
			'total_tokens'      => 110,
		),
	) ),
);
$openai_connector = new PressArk_AI_Connector( 'pro' );
inject_private_property_routing( $openai_connector, 'model', 'openai/gpt-5.4' );
$openai_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Return the final deliverable.',
		),
	),
	$strict_tool,
	'Enforce the contract.',
	false,
	array(
		'tool_choice'        => 'required',
		'deliverable_schema' => $strict_schema,
		'schema_mode'        => 'strict',
	)
);
$openai_http_request  = $pressark_http_requests[ count( $pressark_http_requests ) - 1 ]['body'] ?? array();
$openai_snapshot      = $openai_connector->get_last_request_snapshot();
$openai_transport     = (array) ( $openai_snapshot['transport_contract'] ?? array() );
$openai_schema_contract = (array) ( $openai_transport['structured_output'] ?? array() );
$openai_tool_contract   = (array) ( $openai_transport['tool_choice'] ?? array() );

assert_routing(
	'OpenAI strict schema is enforced with response_format json_schema',
	'json_schema' === ( $openai_http_request['response_format']['type'] ?? '' )
		&& true === ( $openai_http_request['response_format']['json_schema']['strict'] ?? false )
		&& is_array( $openai_http_request['response_format']['json_schema']['schema'] ?? null ),
	'Request: ' . var_export( $openai_http_request, true )
);
assert_routing(
	'OpenAI required tool choice is transmitted truthfully',
	'required' === ( $openai_http_request['tool_choice'] ?? '' )
		&& 'required' === ( $openai_tool_contract['effective'] ?? '' )
		&& 'required' === ( $openai_tool_contract['transport'] ?? '' ),
	'Tool contract: ' . var_export( $openai_tool_contract, true )
);
assert_routing(
	'OpenAI snapshot reports native strict enforcement',
	'strict' === ( $openai_snapshot['phase_addendum']['schema_mode'] ?? '' )
		&& ! empty( $openai_snapshot['request_shape']['structured_output_native'] )
		&& 'response_format' === ( $openai_schema_contract['transport'] ?? '' ),
	'Snapshot: ' . var_export( $openai_snapshot, true )
);

$pressark_test_options['pressark_api_provider'] = 'anthropic';
$pressark_test_options['pressark_model']        = 'anthropic/claude-sonnet-4.6';
$pressark_http_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array(
		'content' => array(
			array(
				'type' => 'text',
				'text' => '{"summary":"ok","citations":["a"]}',
			),
		),
		'usage'   => array(
			'input_tokens'  => 120,
			'output_tokens' => 30,
		),
	) ),
);
$anthropic_connector = new PressArk_AI_Connector( 'pro' );
inject_private_property_routing( $anthropic_connector, 'model', 'anthropic/claude-sonnet-4.6' );
$anthropic_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Return the final deliverable.',
		),
	),
	$strict_tool,
	'Enforce the contract.',
	false,
	array(
		'tool_choice'        => 'required',
		'deliverable_schema' => $strict_schema,
		'schema_mode'        => 'strict',
	)
);
$anthropic_http_request    = $pressark_http_requests[ count( $pressark_http_requests ) - 1 ]['body'] ?? array();
$anthropic_snapshot        = $anthropic_connector->get_last_request_snapshot();
$anthropic_transport       = (array) ( $anthropic_snapshot['transport_contract'] ?? array() );
$anthropic_schema_contract = (array) ( $anthropic_transport['structured_output'] ?? array() );
$anthropic_tool_contract   = (array) ( $anthropic_transport['tool_choice'] ?? array() );

assert_routing(
	'Anthropic strict schema is enforced with output_config.format',
	'json_schema' === ( $anthropic_http_request['output_config']['format']['type'] ?? '' )
		&& is_array( $anthropic_http_request['output_config']['format']['schema'] ?? null )
		&& 'output_config.format' === ( $anthropic_schema_contract['transport'] ?? '' ),
	'Request: ' . var_export( $anthropic_http_request, true )
);
assert_routing(
	'Anthropic required tool choice is transmitted with provider-native any mode',
	'any' === ( $anthropic_http_request['tool_choice']['type'] ?? '' )
		&& 'required' === ( $anthropic_tool_contract['effective'] ?? '' )
		&& 'any' === ( $anthropic_tool_contract['transport'] ?? '' ),
	'Tool contract: ' . var_export( $anthropic_tool_contract, true )
);

$pressark_test_options['pressark_api_provider'] = 'deepseek';
$pressark_test_options['pressark_model']        = 'deepseek/deepseek-chat';
$pressark_http_responses[] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array(
		'choices' => array(
			array(
				'message' => array(
					'role'    => 'assistant',
					'content' => '{"summary":"ok","citations":["a"]}',
				),
				'finish_reason' => 'stop',
			),
		),
		'usage'   => array(
			'prompt_tokens'     => 80,
			'completion_tokens' => 20,
			'total_tokens'      => 100,
		),
	) ),
);
$deepseek_connector = new PressArk_AI_Connector( 'pro' );
inject_private_property_routing( $deepseek_connector, 'model', 'deepseek/deepseek-chat' );
$deepseek_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Return the final deliverable.',
		),
	),
	array(),
	'Enforce the contract.',
	false,
	array(
		'deliverable_schema' => $strict_schema,
		'schema_mode'        => 'strict',
	)
);
$deepseek_http_request    = $pressark_http_requests[ count( $pressark_http_requests ) - 1 ]['body'] ?? array();
$deepseek_snapshot        = $deepseek_connector->get_last_request_snapshot();
$deepseek_transport       = (array) ( $deepseek_snapshot['transport_contract'] ?? array() );
$deepseek_schema_contract = (array) ( $deepseek_transport['structured_output'] ?? array() );

assert_routing(
	'Unsupported DeepSeek strict schema downgrades truthfully instead of claiming transport enforcement',
	! isset( $deepseek_http_request['response_format'] )
		&& 'prompt_only' === ( $deepseek_snapshot['phase_addendum']['schema_mode'] ?? '' )
		&& ! empty( $deepseek_schema_contract['downgraded'] )
		&& 'provider_model_lacks_native_structured_outputs' === ( $deepseek_schema_contract['reason'] ?? '' ),
	'Snapshot: ' . var_export( $deepseek_snapshot, true )
);

$deepseek_active_options = (array) read_private_property_routing( $deepseek_connector, 'active_request_options' );
$downgraded_reserve = (array) invoke_private_routing(
	$deepseek_connector,
	'estimate_proxy_reserve',
	array( $deepseek_http_request, 'chat' )
);
$fake_strict_options = $deepseek_active_options;
$fake_strict_options['schema_mode']                 = 'strict';
$fake_strict_options['structured_output_transport'] = 'response_format';
inject_private_property_routing( $deepseek_connector, 'active_request_options', $fake_strict_options );
$fake_strict_reserve = (array) invoke_private_routing(
	$deepseek_connector,
	'estimate_proxy_reserve',
	array( $deepseek_http_request, 'chat' )
);

assert_routing(
	'Reserve estimation does not charge native strictness when the transport body does not enforce it',
	(int) ( $downgraded_reserve['estimated_icus'] ?? -1 ) === (int) ( $fake_strict_reserve['estimated_icus'] ?? -2 )
		&& (int) ( $downgraded_reserve['estimated_raw_tokens'] ?? -1 ) === (int) ( $fake_strict_reserve['estimated_raw_tokens'] ?? -2 ),
	'Downgraded: ' . var_export( $downgraded_reserve, true ) . ' Fake strict: ' . var_export( $fake_strict_reserve, true )
);

echo "\n--- BYOK back-agent routing stays on the user's models ---\n";

$pressark_test_options['pressark_byok_enabled']          = true;
$pressark_test_options['pressark_byok_provider']         = 'openai';
$pressark_test_options['pressark_byok_api_key']          = 'byok-test-key';
$pressark_test_options['pressark_byok_model']            = 'openai/gpt-5.4';
$pressark_test_options['pressark_summarize_model']       = 'auto';
$pressark_test_options['pressark_summarize_custom_model'] = '';
$pressark_http_requests                                  = array();
$pressark_http_responses                                 = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'BYOK auto back-agent',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 60,
				'completion_tokens' => 12,
				'total_tokens'      => 72,
			),
		) ),
	),
);

$byok_connector = new PressArk_AI_Connector( 'pro' );
$byok_auto      = $byok_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Compress the current run context.',
		),
	),
	array(),
	'Back-agent context',
	false,
	array(
		'phase' => 'summarize',
	)
);

$byok_auto_request = $pressark_http_requests[0]['body'] ?? array();
$byok_auto_routing = (array) ( $byok_auto['routing_decision'] ?? array() );
$byok_auto_snapshot = $byok_connector->get_last_request_snapshot();

assert_routing(
	'BYOK Back-Agent auto reuses the main BYOK model',
	'gpt-5.4' === ( $byok_auto_request['model'] ?? '' )
		&& 'openai/gpt-5.4' === ( $byok_auto_routing['model'] ?? '' )
		&& 'byok' === ( $byok_auto_routing['selection']['mode'] ?? '' ),
	'Request: ' . var_export( $byok_auto_request, true ) . ' Routing: ' . var_export( $byok_auto_routing, true )
);
assert_routing(
	'BYOK transport mode is recorded explicitly',
	'direct' === ( $byok_auto_routing['transport_mode'] ?? '' )
		&& 'direct' === ( $byok_auto_snapshot['transport_mode'] ?? '' ),
	'Routing: ' . var_export( $byok_auto_routing, true ) . ' Snapshot: ' . var_export( $byok_auto_snapshot, true )
);

$pressark_test_options['pressark_summarize_model']        = 'custom';
$pressark_test_options['pressark_summarize_custom_model'] = 'openai/gpt-5.4-mini';
$pressark_http_requests                                   = array();
$pressark_http_responses                                  = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'BYOK override back-agent',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 58,
				'completion_tokens' => 11,
				'total_tokens'      => 69,
			),
		) ),
	),
);

$byok_override = $byok_connector->send_message_raw(
	array(
		array(
			'role'    => 'user',
			'content' => 'Plan the next retrieval step.',
		),
	),
	array(),
	'Back-agent context',
	false,
	array(
		'phase' => 'classification',
	)
);

$byok_override_request = $pressark_http_requests[0]['body'] ?? array();
$byok_override_routing = (array) ( $byok_override['routing_decision'] ?? array() );

assert_routing(
	'BYOK Back-Agent override can use a dedicated model without changing the main BYOK model',
	'gpt-5.4-mini' === ( $byok_override_request['model'] ?? '' )
		&& 'openai/gpt-5.4-mini' === ( $byok_override_routing['model'] ?? '' )
		&& 'openai/gpt-5.4' === (string) ( $pressark_test_options['pressark_byok_model'] ?? '' ),
	'Request: ' . var_export( $byok_override_request, true ) . ' Routing: ' . var_export( $byok_override_routing, true )
);

$pressark_test_options['pressark_byok_enabled']          = false;
$pressark_test_options['pressark_summarize_model']       = 'auto';
$pressark_test_options['pressark_summarize_custom_model'] = '';

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
