<?php
/**
 * Targeted verification for policy-aware discovery ranking.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-discovery-ranking.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
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

if ( ! class_exists( 'PressArk_Permission_Decision' ) ) {
	class PressArk_Permission_Decision {
		public static function normalize( array $decision ): array {
			return $decision;
		}

		public static function is_visible_to_model( array $decision ): bool {
			return ! empty( $decision['visibility']['visible_to_model'] );
		}
	}
}

if ( ! class_exists( 'PressArk_Operation' ) ) {
	class PressArk_Operation {
		public string $name = '';
		public string $capability = 'read';
		public string $group = 'core';
		public string $risk = 'safe';
		public string $description = '';
		public string $search_hint = '';
		public array $tags = array();
		public string $output_policy = 'compact';
		public bool $cacheable = false;
		public bool $deferred = false;

		public function is_read_only(): bool {
			return 'read' === $this->capability;
		}

		public function is_deferred(): bool {
			return $this->deferred;
		}

		public function is_cacheable(): bool {
			return $this->cacheable;
		}
	}
}

if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
	class PressArk_Operation_Registry {
		public static array $ops = array();

		public static function resolve( string $name ): ?PressArk_Operation {
			if ( ! isset( self::$ops[ $name ] ) ) {
				return null;
			}

			$schema = self::$ops[ $name ];
			$op = new PressArk_Operation();
			foreach ( $schema as $key => $value ) {
				$op->{$key} = $value;
			}
			$op->name = $name;
			return $op;
		}

		public static function exists( string $name ): bool {
			return isset( self::$ops[ $name ] );
		}

		public static function classify( string $name, array $args = array() ): string {
			unset( $args );
			return self::$ops[ $name ]['capability'] ?? 'read';
		}

		public static function get_group( string $name ): string {
			return (string) ( self::$ops[ $name ]['group'] ?? '' );
		}

		public static function all(): array {
			$all = array();
			foreach ( array_keys( self::$ops ) as $name ) {
				$all[ $name ] = self::resolve( $name );
			}
			return $all;
		}

		public static function tool_names_for_group( string $group ): array {
			$names = array();
			foreach ( self::$ops as $name => $schema ) {
				if ( $group === ( $schema['group'] ?? '' ) ) {
					$names[] = $name;
				}
			}
			return $names;
		}

		public static function group_names(): array {
			$groups = array();
			foreach ( self::$ops as $schema ) {
				$groups[] = (string) ( $schema['group'] ?? '' );
			}
			return array_values( array_unique( array_filter( $groups ) ) );
		}

		public static function is_valid_group( string $group ): bool {
			return in_array( $group, self::group_names(), true );
		}

		public static function get_aliases(): array {
			return array();
		}

		public static function resolve_alias( string $name ): string {
			return $name;
		}
	}
}

if ( ! class_exists( 'PressArk_Tools' ) ) {
	class PressArk_Tools {
		public static function get_all( bool $has_woo = false, bool $has_elementor = false ): array {
			unset( $has_woo, $has_elementor );
			$tools = array();
			foreach ( PressArk_Operation_Registry::all() as $op ) {
				$tools[] = array(
					'name'        => $op->name,
					'description' => $op->description,
				);
			}
			return $tools;
		}
	}
}

if ( ! class_exists( 'PressArk_Permission_Service' ) ) {
	class PressArk_Permission_Service {
		public static array $decisions = array();

		public static function evaluate_tool_set( array $tool_names, string $context, array $meta = array() ): array {
			unset( $context, $meta );
			$decisions = array();
			foreach ( $tool_names as $tool_name ) {
				if ( isset( self::$decisions[ $tool_name ] ) ) {
					$decisions[ $tool_name ] = self::$decisions[ $tool_name ];
				}
			}
			return array( 'decisions' => $decisions );
		}

		public static function filter_discovery_results( array $results, string $context, array $meta = array() ): array {
			unset( $context, $meta );
			return array_values( array_filter(
				$results,
				static function ( array $result ): bool {
					$name = (string) ( $result['name'] ?? '' );
					if ( '' === $name || ! isset( self::$decisions[ $name ] ) ) {
						return true;
					}
					return PressArk_Permission_Decision::is_visible_to_model( self::$decisions[ $name ] );
				}
			) );
		}
	}
}

if ( ! class_exists( 'PressArk_Capability_Bridge' ) ) {
	class PressArk_Capability_Bridge {
		public static function get_context_resources( array $loaded_groups = array(), string $detail = 'full' ): string {
			unset( $loaded_groups, $detail );
			return '';
		}
	}
}

if ( ! class_exists( 'PressArk_Resource_Registry' ) ) {
	class PressArk_Resource_Registry {
		public static function search( string $query ): array {
			unset( $query );
			return array();
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-tool-catalog.php';

$passed = 0;
$failed = 0;

function assert_discovery( string $label, bool $condition, string $detail = '' ): void {
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

function decision_row( string $verdict, bool $visible ): array {
	return array(
		'verdict'    => $verdict,
		'visibility' => array(
			'visible_to_model' => $visible,
		),
	);
}

PressArk_Operation_Registry::$ops = array(
	'get_site_settings'    => array(
		'capability'    => 'read',
		'group'         => 'settings',
		'risk'          => 'safe',
		'description'   => 'Read the live site settings and current status.',
		'search_hint'   => 'live site settings configuration status',
		'tags'          => array( 'settings', 'status' ),
		'output_policy' => 'full',
		'cacheable'     => false,
	),
	'settings_summary'     => array(
		'capability'    => 'read',
		'group'         => 'settings',
		'risk'          => 'safe',
		'description'   => 'Read a cached summary of site settings.',
		'search_hint'   => 'cached site settings summary',
		'tags'          => array( 'settings', 'summary' ),
		'output_policy' => 'full',
		'cacheable'     => true,
	),
	'update_site_settings' => array(
		'capability'    => 'preview',
		'group'         => 'settings',
		'risk'          => 'moderate',
		'description'   => 'Update site settings.',
		'search_hint'   => 'change site settings configuration',
		'tags'          => array( 'settings', 'update' ),
		'output_policy' => 'compact',
		'cacheable'     => false,
	),
	'delete_site_settings' => array(
		'capability'    => 'confirm',
		'group'         => 'settings',
		'risk'          => 'destructive',
		'description'   => 'Delete site settings.',
		'search_hint'   => 'delete settings',
		'tags'          => array( 'settings', 'delete' ),
		'output_policy' => 'compact',
		'cacheable'     => false,
	),
	'discover_tools'       => array(
		'capability'    => 'read',
		'group'         => 'discovery',
		'risk'          => 'safe',
		'description'   => 'Search the available tools.',
		'search_hint'   => 'tools discovery',
		'tags'          => array( 'tools' ),
		'output_policy' => 'compact',
		'cacheable'     => false,
	),
);

PressArk_Permission_Service::$decisions = array(
	'get_site_settings'    => decision_row( 'allow', true ),
	'settings_summary'     => decision_row( 'ask', true ),
	'update_site_settings' => decision_row( 'allow', true ),
	'delete_site_settings' => decision_row( 'deny', false ),
	'discover_tools'       => decision_row( 'allow', true ),
);

echo "=== Discovery Ranking Tests ===\n\n";

$catalog = PressArk_Tool_Catalog::instance();
$results = $catalog->discover(
	'show live site settings status',
	array( 'update_site_settings' ),
	array(
		'permission_context' => 'interactive',
		'permission_meta'    => array( 'tier' => 'pro' ),
	)
);
$names = array_values( array_map(
	static function ( array $row ): string {
		return (string) ( $row['name'] ?? '' );
	},
	$results
) );

echo '  Ranked: ' . implode( ' -> ', $names ) . "\n";

$get_index     = array_search( 'get_site_settings', $names, true );
$summary_index = array_search( 'settings_summary', $names, true );
$update_index  = array_search( 'update_site_settings', $names, true );

assert_discovery(
	'Live read ranks ahead of the loaded write candidate',
	0 === $get_index,
	'Expected get_site_settings first, got ' . var_export( $names[0] ?? null, true )
);
assert_discovery(
	'Fresh evidence ranks ahead of cached summary',
	false !== $summary_index && false !== $get_index && $get_index < $summary_index,
	'Order was ' . implode( ', ', $names )
);
assert_discovery(
	'Read intent does not let a write outrank the better read',
	false !== $update_index && false !== $get_index && $get_index < $update_index,
	'Order was ' . implode( ', ', $names )
);
assert_discovery(
	'Denied tools stay hidden from discovery output',
	! in_array( 'delete_site_settings', $names, true ),
	'Unexpected names: ' . implode( ', ', $names )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
