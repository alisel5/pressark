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
			return false;
		}

		public function get_byok_provider(): string {
			return 'openai';
		}

		public function get_byok_api_key(): string {
			return '';
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
			return false;
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
	'Capability assumptions still mark the request as tool-requiring',
	! empty( $routing['capability_assumptions']['requires_tools'] ),
	'Capability assumptions: ' . var_export( $routing['capability_assumptions'] ?? null, true )
);
assert_routing(
	'HTTP retries follow the expected candidate order',
	2 === count( $pressark_http_requests )
		&& 'anthropic/claude-sonnet-4.6' === ( $pressark_http_requests[0]['body']['model'] ?? '' )
		&& 'openai/gpt-5.4' === ( $pressark_http_requests[1]['body']['model'] ?? '' ),
	'Requests: ' . var_export( $pressark_http_requests, true )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
