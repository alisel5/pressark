<?php
/**
 * Targeted verification for loader capability-state tracking and deferred hydration.
 *
 * Run: C:\xampp\php\php.exe pressark/tests/test-tool-loading-state.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $key ) ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! class_exists( 'PressArk_Operation' ) ) {
	class PressArk_Operation {
		public string $name = '';
		public string $group = 'core';
		public string $defer = 'auto';

		public function is_always_load(): bool {
			return 'always_load' === $this->defer;
		}
	}
}

if ( ! class_exists( 'PressArk_Operation_Registry' ) ) {
	class PressArk_Operation_Registry {
		public static array $ops = array();

		public static function all(): array {
			$all = array();
			foreach ( self::$ops as $name => $schema ) {
				$op = new PressArk_Operation();
				$op->name  = $name;
				$op->group = (string) ( $schema['group'] ?? 'core' );
				$op->defer = (string) ( $schema['defer'] ?? 'auto' );
				$all[]     = $op;
			}
			return $all;
		}

		public static function group_names(): array {
			return array_values( array_unique( array_map(
				static function ( array $schema ): string {
					return (string) ( $schema['group'] ?? '' );
				},
				self::$ops
			) ) );
		}

		public static function is_valid_group( string $group ): bool {
			return in_array( $group, self::group_names(), true );
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

		public static function get_group( string $name ): string {
			return (string) ( self::$ops[ $name ]['group'] ?? '' );
		}
	}
}

if ( ! class_exists( 'PressArk_Tool_Catalog' ) ) {
	class PressArk_Tool_Catalog {
		private static ?self $instance = null;

		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_tool_names_for_groups( array $groups ): array {
			$names = array();
			foreach ( $groups as $group ) {
				$names = array_merge( $names, PressArk_Operation_Registry::tool_names_for_group( (string) $group ) );
			}
			return array_values( array_unique( $names ) );
		}

		public function get_schemas( array $tool_names ): array {
			$schemas = array();
			foreach ( array_values( array_unique( array_map( 'sanitize_key', $tool_names ) ) ) as $tool_name ) {
				$schemas[] = array(
					'type'     => 'function',
					'function' => array(
						'name'       => $tool_name,
						'parameters' => array(
							'type'       => 'object',
							'properties' => array(),
						),
					),
				);
			}
			return $schemas;
		}

		public function get_capability_maps( array $groups, array $visible_tool_names = array() ): array {
			unset( $visible_tool_names );
			$summary = implode( ',', array_values( array_unique( array_map( 'sanitize_key', $groups ) ) ) );
			return array(
				'full'    => $summary,
				'compact' => $summary,
				'minimal' => $summary,
			);
		}

		public function find_group_for_tool( string $tool_name ): string {
			return PressArk_Operation_Registry::get_group( $tool_name );
		}

		public function get_all_tool_names( bool $include_meta = true ): array {
			$names = array_keys( PressArk_Operation_Registry::$ops );
			if ( ! $include_meta ) {
				$names = array_values( array_filter(
					$names,
					static fn( string $name ): bool => ! in_array( $name, array( 'discover_tools', 'load_tools', 'load_tool_group' ), true )
				) );
			}
			return array_values( array_map( 'sanitize_key', $names ) );
		}
	}
}

if ( ! class_exists( 'PressArk_Permission_Service' ) ) {
	class PressArk_Permission_Service {
		public static array $blocked = array();

		public static function evaluate_tool_set( array $tool_names, string $context, array $meta = array() ): array {
			unset( $meta );
			$visible        = array();
			$hidden         = array();
			$visible_groups = array();
			$hidden_summary = array();
			$decisions      = array();

			foreach ( array_values( array_unique( array_map( 'sanitize_key', $tool_names ) ) ) as $tool_name ) {
				if ( in_array( $tool_name, self::$blocked, true ) ) {
					$hidden[]                  = $tool_name;
					$hidden_summary['policy'] = (int) ( $hidden_summary['policy'] ?? 0 ) + 1;
					$decisions[ $tool_name ]   = array(
						'verdict'    => 'deny',
						'visibility' => array(
							'reason_codes' => array( 'policy' ),
						),
					);
					continue;
				}

				$visible[] = $tool_name;
				$group     = PressArk_Operation_Registry::get_group( $tool_name );
				if ( '' !== $group ) {
					$visible_groups[ $group ] = true;
				}
				$decisions[ $tool_name ] = array(
					'verdict'    => 'allow',
					'visibility' => array(
						'reason_codes' => array(),
					),
				);
			}

			return array(
				'context'            => $context,
				'visible_tool_names' => $visible,
				'hidden_tool_names'  => $hidden,
				'visible_groups'     => array_keys( $visible_groups ),
				'decisions'          => $decisions,
				'hidden_summary'     => $hidden_summary,
			);
		}

		public static function build_surface_snapshot( array $visibility, array $requested_groups = array() ): array {
			$hidden_reason_rows = array();
			if ( ! empty( $visibility['hidden_tool_names'] ) ) {
				$hidden_reason_rows[] = array(
					'kind'    => 'policy',
					'label'   => 'Blocked by policy',
					'summary' => 'Settings tools were hidden by the current policy for this run.',
					'hint'    => 'Use an allowed tool group or adjust policy and approval settings before retrying.',
					'groups'  => array( 'settings' ),
					'count'   => count( (array) ( $visibility['hidden_tool_names'] ?? array() ) ),
				);
			}

			return array(
				'context'          => (string) ( $visibility['context'] ?? '' ),
				'requested_groups' => array_values( array_map( 'sanitize_key', $requested_groups ) ),
				'visible_groups'   => array_values( array_map( 'sanitize_key', (array) ( $visibility['visible_groups'] ?? array() ) ) ),
				'visible_tools'    => array_values( array_map( 'sanitize_key', (array) ( $visibility['visible_tool_names'] ?? array() ) ) ),
				'hidden_tools'     => array_values( array_map( 'sanitize_key', (array) ( $visibility['hidden_tool_names'] ?? array() ) ) ),
				'hidden_summary'   => (array) ( $visibility['hidden_summary'] ?? array() ),
				'hidden_decisions' => array_intersect_key(
					(array) ( $visibility['decisions'] ?? array() ),
					array_flip( (array) ( $visibility['hidden_tool_names'] ?? array() ) )
				),
				'hidden_reason_rows' => $hidden_reason_rows,
			);
		}
	}
}

if ( ! class_exists( 'PressArk_Token_Budget_Manager' ) ) {
	class PressArk_Token_Budget_Manager {}
}

require_once dirname( __DIR__ ) . '/includes/class-pressark-tool-loader.php';

$passed = 0;
$failed = 0;

function assert_tool_state( string $label, bool $condition, string $detail = '' ): void {
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

function find_tool_state_row( array $rows, string $tool_name ): array {
	foreach ( $rows as $row ) {
		if ( $tool_name === (string) ( $row['name'] ?? '' ) ) {
			return (array) $row;
		}
	}

	return array();
}

PressArk_Operation_Registry::$ops = array(
	'discover_tools'       => array( 'group' => 'discovery', 'defer' => 'always_load' ),
	'load_tools'           => array( 'group' => 'discovery', 'defer' => 'always_load' ),
	'read_content'         => array( 'group' => 'content', 'defer' => 'always_load' ),
	'search_content'       => array( 'group' => 'content', 'defer' => 'always_load' ),
	'list_posts'           => array( 'group' => 'content', 'defer' => 'always_load' ),
	'get_site_settings'    => array( 'group' => 'settings', 'defer' => 'auto' ),
	'update_site_settings' => array( 'group' => 'settings', 'defer' => 'auto' ),
	'fix_seo'              => array( 'group' => 'seo', 'defer' => 'deferred' ),
	'scan_security'        => array( 'group' => 'security', 'defer' => 'deferred' ),
	'delete_site_settings' => array( 'group' => 'settings', 'defer' => 'auto' ),
);
PressArk_Permission_Service::$blocked = array( 'delete_site_settings' );

echo "=== Tool Loading State Tests ===\n\n";

$loader   = new PressArk_Tool_Loader( PressArk_Tool_Catalog::instance() );
$tool_set = $loader->resolve_native_search(
	'pro',
	array(
		'loaded_groups'    => array( 'settings' ),
		'permission_context' => 'interactive',
		'permission_meta'  => array( 'tier' => 'pro' ),
	)
);
$tool_state = (array) ( $tool_set['tool_state'] ?? array() );
$permission_surface = (array) ( $tool_set['permission_surface'] ?? array() );
$fix_seo_row = find_tool_state_row( (array) ( $tool_state['tools'] ?? array() ), 'fix_seo' );

echo '  Native-search loaded: ' . implode( ', ', (array) ( $tool_state['loaded_tools'] ?? array() ) ) . "\n";
echo '  Native-search searchable: ' . implode( ', ', (array) ( $tool_state['searchable_tools'] ?? array() ) ) . "\n";

assert_tool_state(
	'Native-search keeps visible and loaded tools distinct',
	count( (array) ( $tool_state['visible_tools'] ?? array() ) ) > count( (array) ( $tool_state['loaded_tools'] ?? array() ) ),
	'Visible=' . count( (array) ( $tool_state['visible_tools'] ?? array() ) ) . ' Loaded=' . count( (array) ( $tool_state['loaded_tools'] ?? array() ) )
);
assert_tool_state(
	'Native-search does not hydrate a visible deferred tool by default',
	in_array( 'fix_seo', (array) ( $tool_state['searchable_tools'] ?? array() ), true )
		&& ! in_array( 'fix_seo', (array) ( $tool_state['loaded_tools'] ?? array() ), true ),
	'State: ' . var_export( $tool_state, true )
);
assert_tool_state(
	'Blocked tools are tracked separately from searchable and loaded tools',
	in_array( 'delete_site_settings', (array) ( $tool_state['blocked_tools'] ?? array() ), true )
		&& ! in_array( 'delete_site_settings', (array) ( $tool_state['loaded_tools'] ?? array() ), true )
		&& ! in_array( 'delete_site_settings', (array) ( $tool_state['searchable_tools'] ?? array() ), true ),
	'State: ' . var_export( $tool_state, true )
);
assert_tool_state(
	'Permission surface adds compact hidden-reason rows for blocked tools',
	'policy' === ( $permission_surface['hidden_reason_rows'][0]['kind'] ?? '' )
		&& 'Blocked by policy' === ( $permission_surface['hidden_reason_rows'][0]['label'] ?? '' ),
	'Surface: ' . var_export( $permission_surface, true )
);
assert_tool_state(
	'State rows expose visible-but-not-loaded tools as searchable',
	'searchable' === ( $fix_seo_row['state'] ?? '' ) && empty( $fix_seo_row['loaded'] ),
	'Row: ' . var_export( $fix_seo_row, true )
);

$tool_set = $loader->mark_discovered_tools(
	$tool_set,
	array( 'fix_seo', 'delete_site_settings' ),
	array(
		'permission_context' => 'interactive',
		'permission_meta'    => array( 'tier' => 'pro' ),
	)
);
$discovered_state = (array) ( $tool_set['tool_state'] ?? array() );
$discovered_fix_seo_row = find_tool_state_row( (array) ( $discovered_state['tools'] ?? array() ), 'fix_seo' );

echo '  After discovery: ' . implode( ', ', (array) ( $discovered_state['discovered_tools'] ?? array() ) ) . "\n";

assert_tool_state(
	'Discovery moves a visible tool out of the searchable pool before it is loaded',
	in_array( 'fix_seo', (array) ( $discovered_state['discovered_tools'] ?? array() ), true )
		&& ! in_array( 'fix_seo', (array) ( $discovered_state['searchable_tools'] ?? array() ), true ),
	'State: ' . var_export( $discovered_state, true )
);
assert_tool_state(
	'Blocked tools are not promoted into the discovered bucket',
	! in_array( 'delete_site_settings', (array) ( $discovered_state['discovered_tools'] ?? array() ), true ),
	'State: ' . var_export( $discovered_state, true )
);
assert_tool_state(
	'State rows flip to discovered before hydration',
	'discovered' === ( $discovered_fix_seo_row['state'] ?? '' ) && empty( $discovered_fix_seo_row['loaded'] ),
	'Row: ' . var_export( $discovered_fix_seo_row, true )
);

$tool_set = $loader->expand_tools(
	$tool_set,
	array( 'fix_seo' ),
	array(
		'permission_context' => 'interactive',
		'permission_meta'    => array( 'tier' => 'pro' ),
	)
);
$expanded_state = (array) ( $tool_set['tool_state'] ?? array() );
$expanded_fix_seo_row = find_tool_state_row( (array) ( $expanded_state['tools'] ?? array() ), 'fix_seo' );

echo '  After load_tools: ' . implode( ', ', (array) ( $expanded_state['loaded_tools'] ?? array() ) ) . "\n";

assert_tool_state(
	'Loading a discovered tool hydrates it and clears the discovered bucket',
	in_array( 'fix_seo', (array) ( $expanded_state['loaded_tools'] ?? array() ), true )
		&& ! in_array( 'fix_seo', (array) ( $expanded_state['discovered_tools'] ?? array() ), true ),
	'State: ' . var_export( $expanded_state, true )
);
assert_tool_state(
	'Discovery history survives the later load so run details can explain the path',
	in_array( 'fix_seo', (array) ( $expanded_state['discovered_history'] ?? array() ), true ),
	'State: ' . var_export( $expanded_state, true )
);
assert_tool_state(
	'State rows flip to loaded after hydration without losing visibility history',
	'loaded' === ( $expanded_fix_seo_row['state'] ?? '' ) && ! empty( $expanded_fix_seo_row['loaded'] ),
	'Row: ' . var_export( $expanded_fix_seo_row, true )
);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
