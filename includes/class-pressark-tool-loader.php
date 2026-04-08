<?php
/**
 * PressArk Tool Loader
 *
 * Centralized loading strategy that decides which tools to send for each
 * AI request. v2.3.1: Replaces keyword-based intent matching with
 * conversation-scoped state — starts with base tools, expands via
 * discover_tools + load_tools meta-tools.
 *
 * v3.8.0: Universal high-ROI tools always loaded. Capability map replaces
 * per-tool descriptor dumping in the hot prompt. Native tool-search path
 * for GPT-5.4-class models. Stable canonical ordering for cache reuse.
 *
 * v5.4.0: Adds provider-aware token-budget planning for adaptive preload
 * hydration. Sticky/user-loaded groups remain authoritative; heuristic
 * candidate groups are admitted only when the remaining prompt budget can
 * afford their schema cost plus response headroom.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Loader {

	private PressArk_Tool_Catalog $catalog;

	/**
	 * Minimal groups loaded for every native-tool agent request.
	 *
	 * Keep discovery/meta-tools available, but avoid shipping content write
	 * schemas unless the planner or heuristics explicitly preload them.
	 *
	 * @since 4.3.3
	 */
	const BASE_GROUPS = array(
		'discovery',
	);

	/**
	 * Universal high-ROI tools always sent as schemas.
	 *
	 * The registry is now the primary source for future always-load tools, but
	 * this constant preserves the historic baseline behavior for the three core
	 * content reads that should remain available even when no extra groups fit.
	 *
	 * @since 3.8.0
	 */
	const UNIVERSAL_TOOLS = array(
		'read_content',
		'search_content',
		'list_posts',
	);

	public function __construct( ?PressArk_Tool_Catalog $catalog = null ) {
		$this->catalog = $catalog ?? PressArk_Tool_Catalog::instance();
	}

	/**
	 * Resolve which tools to load for this request.
	 *
	 * @param string   $message       The user's current message (kept for compat).
	 * @param array    $conversation  Conversation history (kept for compat).
	 * @param string   $tier          User's tier (reserved for future limits).
	 * @param string[] $loaded_groups Groups already loaded in this conversation.
	 * @param array    $options       Optional adaptive hydration options.
	 * @return array
	 */
	public function resolve(
		string $message,
		array $conversation,
		string $tier,
		array $loaded_groups = array(),
		array $options = array()
	): array {
		unset( $message, $conversation );
		return $this->resolve_group_scoped( $tier, $loaded_groups, $options, 'filtered' );
	}

	/**
	 * Resolve for models with native tool search (GPT-5.4-class).
	 *
	 * Keeps the provider-facing schema set tight while preserving a richer
	 * internal distinction between visible, searchable, discovered, loaded,
	 * and blocked tools for operator and future chat-side run details.
	 *
	 * @since 3.8.0
	 *
	 * @param string $tier User's tier.
	 * @return array Same shape as resolve().
	 */
	public function resolve_native_search( string $tier, array $options = array() ): array {
		$loaded_groups = (array) ( $options['loaded_groups'] ?? array() );
		unset( $options['loaded_groups'] );

		return $this->resolve_group_scoped( $tier, $loaded_groups, $options, 'native_search' );
	}

	/**
	 * Resolve a request-scoped tool set while preserving the richer capability
	 * state needed for deferred loading and later run inspection.
	 *
	 * @param string   $tier          User tier.
	 * @param string[] $loaded_groups Groups that should start loaded.
	 * @param array    $options       Loader options.
	 * @param string   $strategy      Strategy label.
	 * @return array
	 */
	private function resolve_group_scoped(
		string $tier,
		array $loaded_groups = array(),
		array $options = array(),
		string $strategy = 'filtered'
	): array {
		$options['tier'] = $tier;

		$required_groups = $this->normalize_groups(
			array_merge( self::BASE_GROUPS, $loaded_groups )
		);
		$candidate_groups = array_values( array_diff(
			$this->normalize_groups( (array) ( $options['candidate_groups'] ?? array() ) ),
			$required_groups
		) );

		$tool_set = $this->build_tool_set( $required_groups, $strategy, $options );

		$budget_manager = $options['budget_manager'] ?? null;
		if ( $budget_manager instanceof PressArk_Token_Budget_Manager && ! empty( $candidate_groups ) ) {
			$group_costs = array();
			foreach ( $candidate_groups as $group ) {
				$group_costs[ $group ] = $this->estimate_group_schema_cost(
					$group,
					$tool_set['tool_names'],
					$budget_manager,
					$options
				);
			}

			$base_ledger = $budget_manager->build_request_ledger( array(
				'dynamic_prompt'      => (string) ( $options['dynamic_prompt'] ?? '' ),
				'loaded_tool_schemas' => $tool_set['schemas'],
				'conversation'        => (array) ( $options['conversation_messages'] ?? array() ),
				'tool_results'        => (array) ( $options['tool_results'] ?? array() ),
				'deferred_candidates' => array(),
			) );
			$hydration  = $budget_manager->plan_group_hydration(
				$required_groups,
				$candidate_groups,
				$group_costs,
				$base_ledger
			);
			$tool_set   = $this->build_tool_set(
				array_merge( $required_groups, (array) ( $hydration['selected_groups'] ?? array() ) ),
				$strategy,
				$options
			);
			$tool_set['group_costs']     = $group_costs;
			$tool_set['hydration_plan']  = $hydration;
			$tool_set['deferred_groups'] = (array) ( $hydration['deferred_groups'] ?? array() );
		}

		return $this->finalize_tool_set_budget( $tool_set, $options );
	}

	/**
	 * Expand the current tool set with an additional group.
	 *
	 * Explicit load requests stay authoritative and bypass adaptive trimming.
	 *
	 * @param array  $current Current result from resolve() or previous expand().
	 * @param string $group   Group name to add.
	 * @return array Updated result.
	 */
	public function expand( array $current, string $group, array $options = array() ): array {
		$groups = $this->normalize_groups( (array) ( $current['groups'] ?? array() ) );

		if ( in_array( $group, $groups, true ) || ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
			return $current;
		}

		$groups[]  = $group;
		$tool_set  = $this->build_tool_set(
			$groups,
			(string) ( $current['strategy'] ?? 'filtered' ),
			$this->merge_state_options( $options, $current )
		);
		$deferred  = array_values( array_filter(
			(array) ( $current['deferred_groups'] ?? array() ),
			static function ( $candidate ) use ( $group ): bool {
				return $group !== (string) ( $candidate['group'] ?? '' );
			}
		) );
		$tool_set['deferred_groups'] = $deferred;

		return $tool_set;
	}

	/**
	 * Expand the current tool set by loading specific tools (by name).
	 * Resolves each tool to its parent group and loads the full group.
	 *
	 * @since 2.3.1
	 *
	 * @param array    $current    Current result from resolve() or previous expand().
	 * @param string[] $tool_names Specific tool names to add.
	 * @return array Updated result.
	 */
	public function expand_tools( array $current, array $tool_names, array $options = array() ): array {
		$groups          = $this->normalize_groups( (array) ( $current['groups'] ?? array() ) );
		$has_woo         = class_exists( 'WooCommerce' );
		$has_elementor   = class_exists( '\\Elementor\\Plugin' );
		$changed         = false;

		foreach ( $tool_names as $name ) {
			$group = $this->catalog->find_group_for_tool( $name );
			if ( ! $group || in_array( $group, $groups, true ) ) {
				continue;
			}
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elementor ) {
				continue;
			}
			$groups[] = $group;
			$changed  = true;
		}

		if ( ! $changed ) {
			return $current;
		}

		$tool_set = $this->build_tool_set(
			$groups,
			(string) ( $current['strategy'] ?? 'filtered' ),
			$this->merge_state_options( $options, $current )
		);
		$tool_set['deferred_groups'] = array_values( array_filter(
			(array) ( $current['deferred_groups'] ?? array() ),
			static function ( $candidate ) use ( $groups ): bool {
				return ! in_array( (string) ( $candidate['group'] ?? '' ), $groups, true );
			}
		) );

		return $tool_set;
	}

	/**
	 * Mark a set of tools as discovered without hydrating their schemas yet.
	 *
	 * The current request keeps them distinct from both the provider-loaded
	 * subset and the wider searchable pool so run details can explain what the
	 * harness surfaced versus what it actually hydrated.
	 *
	 * @param array    $current    Current tool-set payload.
	 * @param string[] $tool_names Discovered tool names.
	 * @param array    $options    Optional loader context.
	 * @return array
	 */
	public function mark_discovered_tools( array $current, array $tool_names, array $options = array() ): array {
		$history = array_values( array_unique( array_merge(
			(array) ( $current['discovered_tool_names'] ?? array() ),
			$this->normalize_tool_names( $tool_names )
		) ) );

		$current['discovered_tool_names'] = $history;
		$current['tool_state']            = $this->build_tool_state(
			(array) ( $current['tool_names'] ?? array() ),
			(array) ( $current['requested_groups'] ?? $current['groups'] ?? array() ),
			(array) ( $current['groups'] ?? array() ),
			(string) ( $current['strategy'] ?? 'filtered' ),
			$this->merge_state_options(
				array_merge(
					$options,
					array(
						'discovered_tool_names' => $history,
					)
				),
				$current
			),
			(array) ( $current['permission_surface'] ?? array() )
		);

		return $current;
	}

	/**
	 * Load all tools (bypass filtering).
	 *
	 * @return array Same shape as resolve() but with all tools loaded.
	 */
	public function resolve_full(): array {
		$schemas = ( new PressArk_Tools() )->get_all_tools();

		foreach ( $this->catalog->get_meta_tools_schemas() as $meta_schema ) {
			$schemas[] = $meta_schema;
		}

		usort( $schemas, function ( $a, $b ) {
			return strcmp( $a['function']['name'] ?? '', $b['function']['name'] ?? '' );
		} );

		$tool_names = array();
		foreach ( $schemas as $schema ) {
			$tool_names[] = $schema['function']['name'] ?? '';
		}

		return array(
			'schemas'                => $schemas,
			'descriptors'            => '',
			'capability_map'         => '',
			'capability_maps'        => array(),
			'capability_map_variant' => '',
			'groups'                 => PressArk_Operation_Registry::group_names(),
			'requested_groups'       => PressArk_Operation_Registry::group_names(),
			'strategy'               => 'full',
			'tool_count'             => count( $schemas ),
			'tool_names'             => $tool_names,
			'discovered_tool_names'  => array(),
			'effective_visible_tools' => $tool_names,
			'permission_surface'     => array(),
			'tool_state'             => $this->build_tool_state(
				$tool_names,
				PressArk_Operation_Registry::group_names(),
				PressArk_Operation_Registry::group_names(),
				'full',
				array(),
				array()
			),
			'deferred_groups'        => array(),
			'hydration_plan'         => array(),
			'budget'                 => array(),
		);
	}

	/**
	 * Build a canonical tool set for a resolved group list.
	 *
	 * @param string[] $groups   Loaded groups.
	 * @param string   $strategy Strategy label.
	 * @return array
	 */
	private function build_tool_set( array $groups, string $strategy, array $options = array() ): array {
		$groups               = $this->normalize_groups( $groups );
		$candidate_tool_names = array_values( array_unique( array_merge(
			$this->catalog->get_tool_names_for_groups( $groups ),
			$this->get_always_load_tool_names()
		) ) );
		$effective_groups     = $groups;
		$permission_surface   = array();

		if ( class_exists( 'PressArk_Permission_Service' ) ) {
			$visibility           = PressArk_Permission_Service::evaluate_tool_set(
				$candidate_tool_names,
				$this->permission_context( $options ),
				$this->permission_meta( $options )
			);
			$candidate_tool_names = $visibility['visible_tool_names'];
			$effective_groups     = $this->visible_groups_from_tool_names( $groups, $candidate_tool_names );
			$permission_surface   = PressArk_Permission_Service::build_surface_snapshot( $visibility, $groups );
			if ( class_exists( 'PressArk_Policy_Diagnostics' ) ) {
				PressArk_Policy_Diagnostics::record_tool_surface(
					$permission_surface,
					array_merge(
						$this->permission_meta( $options ),
						array(
							'strategy' => $strategy,
						)
					)
				);
			}
		}

		$schemas = $this->catalog->get_schemas( $candidate_tool_names );
		$maps    = $this->catalog->get_capability_maps( $effective_groups, $candidate_tool_names );

		return array(
			'schemas'                => $schemas,
			'descriptors'            => '',
			'capability_map'         => $maps['full'] ?? '',
			'capability_maps'        => $maps,
			'capability_map_variant' => 'full',
			'groups'                 => $effective_groups,
			'requested_groups'       => $groups,
			'strategy'               => $strategy,
			'tool_count'             => count( $schemas ),
			'tool_names'             => $candidate_tool_names,
			'discovered_tool_names'  => $this->normalize_tool_names( (array) ( $options['discovered_tool_names'] ?? array() ) ),
			'effective_visible_tools' => $candidate_tool_names,
			'permission_surface'     => $permission_surface,
			'tool_state'             => $this->build_tool_state(
				$candidate_tool_names,
				$groups,
				$effective_groups,
				$strategy,
				$options,
				$permission_surface
			),
			'deferred_groups'        => array(),
			'hydration_plan'         => array(),
			'budget'                 => array(),
		);
	}

	/**
	 * Preserve discovery state when rebuilding a tool set after loads/expands.
	 *
	 * @param array $options Loader options for the next build.
	 * @param array $current Current tool-set payload.
	 * @return array
	 */
	private function merge_state_options( array $options, array $current ): array {
		if ( ! isset( $options['discovered_tool_names'] ) && ! empty( $current['discovered_tool_names'] ) ) {
			$options['discovered_tool_names'] = (array) $current['discovered_tool_names'];
		}

		return $options;
	}

	/**
	 * Build the canonical tool-state model shared by loader results, traces,
	 * and operator-facing run details.
	 *
	 * @param string[] $loaded_tool_names Provider-hydrated tool schemas.
	 * @param string[] $requested_groups  Groups requested by the loader.
	 * @param string[] $loaded_groups     Groups that ended up loaded.
	 * @param string   $strategy          Strategy label.
	 * @param array    $options           Loader options.
	 * @param array    $permission_surface Request-scoped permission snapshot.
	 * @return array
	 */
	private function build_tool_state(
		array $loaded_tool_names,
		array $requested_groups,
		array $loaded_groups,
		string $strategy,
		array $options = array(),
		array $permission_surface = array()
	): array {
		$loaded_tool_names = $this->normalize_tool_names( $loaded_tool_names );
		$visibility        = $this->capture_tool_visibility( $options );
		$visible_tools     = $this->normalize_tool_names( (array) ( $visibility['visible_tool_names'] ?? array() ) );
		$blocked_tools     = $this->normalize_tool_names( (array) ( $visibility['hidden_tool_names'] ?? array() ) );
		$discovered_history = array_values( array_intersect(
			$this->normalize_tool_names( (array) ( $options['discovered_tool_names'] ?? array() ) ),
			$visible_tools
		) );
		$discovered_tools = array_values( array_diff( $discovered_history, $loaded_tool_names ) );
		$searchable_tools = array_values( array_diff( $visible_tools, $loaded_tool_names, $discovered_tools ) );
		$loaded_lookup    = array_flip( $loaded_tool_names );
		$discovered_lookup = array_flip( $discovered_tools );
		$blocked_lookup   = array_flip( $blocked_tools );
		$rows             = array();

		foreach ( array_merge( $loaded_tool_names, $discovered_tools, $searchable_tools, $blocked_tools ) as $tool_name ) {
			$tool_name = sanitize_key( (string) $tool_name );
			if ( '' === $tool_name || isset( $rows[ $tool_name ] ) ) {
				continue;
			}

			$state = isset( $loaded_lookup[ $tool_name ] )
				? 'loaded'
				: ( isset( $discovered_lookup[ $tool_name ] )
					? 'discovered'
					: ( isset( $blocked_lookup[ $tool_name ] ) ? 'blocked' : 'searchable' ) );

			$rows[ $tool_name ] = array(
				'name'   => $tool_name,
				'group'  => $this->catalog->find_group_for_tool( $tool_name ),
				'state'  => $state,
				'loaded' => isset( $loaded_lookup[ $tool_name ] ),
			);
		}

		return array(
			'contract'            => 'tool_state',
			'version'             => 1,
			'strategy'            => sanitize_key( $strategy ),
			'context'             => $this->permission_context( $options ),
			'requested_groups'    => array_values( array_unique( array_filter( array_map( 'sanitize_key', $requested_groups ) ) ) ),
			'loaded_groups'       => array_values( array_unique( array_filter( array_map( 'sanitize_key', $loaded_groups ) ) ) ),
			'visible_groups'      => $this->groups_from_tool_names( $visible_tools ),
			'loaded_groups_visible' => $this->groups_from_tool_names( $loaded_tool_names ),
			'searchable_groups'   => $this->groups_from_tool_names( $searchable_tools ),
			'discovered_groups'   => $this->groups_from_tool_names( $discovered_tools ),
			'blocked_groups'      => $this->groups_from_tool_names( $blocked_tools ),
			'visible_tools'       => $visible_tools,
			'visible_tool_count'  => count( $visible_tools ),
			'loaded_tools'        => $loaded_tool_names,
			'loaded_tool_count'   => count( $loaded_tool_names ),
			'searchable_tools'    => $searchable_tools,
			'searchable_tool_count' => count( $searchable_tools ),
			'discovered_tools'    => $discovered_tools,
			'discovered_tool_count' => count( $discovered_tools ),
			'discovered_history'  => $discovered_history,
			'blocked_tools'       => $blocked_tools,
			'blocked_tool_count'  => count( $blocked_tools ),
			'blocked_summary'     => (array) ( $visibility['hidden_summary'] ?? array() ),
			'request_hidden_tools' => $this->normalize_tool_names( (array) ( $permission_surface['hidden_tools'] ?? array() ) ),
			'request_hidden_summary' => (array) ( $permission_surface['hidden_summary'] ?? array() ),
			'tools'               => array_values( $rows ),
		);
	}

	/**
	 * Resolve the full visible-vs-blocked capability pool for the current site
	 * and permission context.
	 *
	 * @param array $options Loader options.
	 * @return array
	 */
	private function capture_tool_visibility( array $options = array() ): array {
		$all_tool_names = $this->catalog->get_all_tool_names();

		if ( ! class_exists( 'PressArk_Permission_Service' ) ) {
			return array(
				'context'            => $this->permission_context( $options ),
				'visible_tool_names' => $all_tool_names,
				'hidden_tool_names'  => array(),
				'visible_groups'     => $this->groups_from_tool_names( $all_tool_names ),
				'decisions'          => array(),
				'hidden_summary'     => array(),
			);
		}

		return PressArk_Permission_Service::evaluate_tool_set(
			$all_tool_names,
			$this->permission_context( $options ),
			$this->permission_meta( $options )
		);
	}

	/**
	 * Derive unique groups for a set of tool names.
	 *
	 * @param string[] $tool_names Tool names.
	 * @return string[]
	 */
	private function groups_from_tool_names( array $tool_names ): array {
		$groups = array();
		foreach ( $this->normalize_tool_names( $tool_names ) as $tool_name ) {
			$group = $this->catalog->find_group_for_tool( $tool_name );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Apply budget-aware capability support selection when available.
	 *
	 * @param array $tool_set Tool set built by build_tool_set().
	 * @param array $options  Optional context passed from resolve().
	 * @return array
	 */
	private function finalize_tool_set_budget( array $tool_set, array $options = array() ): array {
		$budget_manager = $options['budget_manager'] ?? null;
		if ( ! $budget_manager instanceof PressArk_Token_Budget_Manager ) {
			return $tool_set;
		}

		$capability_maps = (array) ( $tool_set['capability_maps'] ?? array() );
		$base_ledger     = $budget_manager->build_request_ledger( array(
			'dynamic_prompt'      => (string) ( $options['dynamic_prompt'] ?? '' ),
			'loaded_tool_schemas' => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'        => (array) ( $options['conversation_messages'] ?? array() ),
			'tool_results'        => (array) ( $options['tool_results'] ?? array() ),
			'deferred_candidates' => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );
		$variant         = $budget_manager->choose_support_variant( $capability_maps, $base_ledger );
		$capability_map  = '' !== $variant ? (string) ( $capability_maps[ $variant ] ?? '' ) : '';
		$dynamic_prompt  = (string) ( $options['dynamic_prompt'] ?? '' );
		if ( '' !== $capability_map ) {
			$dynamic_prompt = '' !== trim( $dynamic_prompt )
				? trim( $dynamic_prompt ) . "\n\n" . $capability_map
				: $capability_map;
		}

		$tool_set['capability_map_variant'] = $variant;
		$tool_set['capability_map']         = $capability_map;
		$tool_set['budget']                 = $budget_manager->build_request_ledger( array(
			'dynamic_prompt'      => $dynamic_prompt,
			'loaded_tool_schemas' => (array) ( $tool_set['schemas'] ?? array() ),
			'conversation'        => (array) ( $options['conversation_messages'] ?? array() ),
			'tool_results'        => (array) ( $options['tool_results'] ?? array() ),
			'deferred_candidates' => (array) ( $tool_set['deferred_groups'] ?? array() ),
		) );

		return $tool_set;
	}

	/**
	 * Estimate the incremental schema cost of loading a candidate group.
	 *
	 * @param string                        $group              Candidate group name.
	 * @param string[]                      $current_tool_names Tool names already loaded.
	 * @param PressArk_Token_Budget_Manager $budget_manager     Budget estimator.
	 * @return int
	 */
	private function estimate_group_schema_cost(
		string $group,
		array $current_tool_names,
		PressArk_Token_Budget_Manager $budget_manager,
		array $options = array()
	): int {
		$group_tool_names = array_values( array_diff(
			$this->catalog->get_tool_names_for_groups( array( $group ) ),
			$current_tool_names
		) );

		if ( class_exists( 'PressArk_Permission_Service' ) && ! empty( $group_tool_names ) ) {
			$visibility       = PressArk_Permission_Service::evaluate_tool_set(
				$group_tool_names,
				$this->permission_context( $options ),
				$this->permission_meta( $options )
			);
			$group_tool_names = array_values( array_diff(
				$visibility['visible_tool_names'],
				$current_tool_names
			) );
		}

		if ( empty( $group_tool_names ) ) {
			return 0;
		}

		return $budget_manager->estimate_schema_tokens(
			$this->catalog->get_schemas( $group_tool_names )
		);
	}

	/**
	 * Get always-load tool names using the registry as the extension point.
	 *
	 * @return string[]
	 */
	private function get_always_load_tool_names(): array {
		$always = self::UNIVERSAL_TOOLS;

		foreach ( PressArk_Operation_Registry::all() as $op ) {
			if ( ! $op->is_always_load() ) {
				continue;
			}
			if ( in_array( $op->name, array( 'discover_tools', 'load_tools', 'load_tool_group' ), true ) ) {
				continue;
			}
			$always[] = $op->name;
		}

		return array_values( array_unique( $always ) );
	}

	/**
	 * Normalize a list of tool names.
	 *
	 * @param array $tool_names Arbitrary tool identifiers.
	 * @return string[]
	 */
	private function normalize_tool_names( array $tool_names ): array {
		$normalized = array();

		foreach ( $tool_names as $tool_name ) {
			if ( ! is_string( $tool_name ) && ! is_int( $tool_name ) ) {
				continue;
			}

			$name = sanitize_key( (string) $tool_name );
			if ( '' !== $name ) {
				$normalized[] = $name;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize and plugin-filter a group list.
	 *
	 * @param string[] $groups Group names.
	 * @return string[]
	 */
	private function normalize_groups( array $groups ): array {
		$normalized = array();
		foreach ( $groups as $group ) {
			$group = sanitize_key( (string) $group );
			if ( '' === $group || ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
				continue;
			}
			if ( 'woocommerce' === $group && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			if ( 'elementor' === $group && ! class_exists( '\\Elementor\\Plugin' ) ) {
				continue;
			}
			$normalized[] = $group;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Resolve the current permission context for tool exposure.
	 *
	 * @param array $options Loader options.
	 * @return string
	 */
	private function permission_context( array $options ): string {
		return (string) (
			$options['permission_context']
			?? ( class_exists( 'PressArk_Policy_Engine' )
				? PressArk_Policy_Engine::CONTEXT_INTERACTIVE
				: 'interactive' )
		);
	}

	/**
	 * Build permission-evaluation metadata for tool exposure.
	 *
	 * @param array $options Loader options.
	 * @return array
	 */
	private function permission_meta( array $options ): array {
		$meta = (array) ( $options['permission_meta'] ?? array() );
		if ( ! isset( $meta['tier'] ) && isset( $options['tier'] ) ) {
			$meta['tier'] = $options['tier'];
		}
		if ( ! isset( $meta['decision_purpose'] ) ) {
			$meta['decision_purpose'] = 'tool_surface';
		}
		return $meta;
	}

	/**
	 * Keep only groups that still expose at least one visible tool.
	 *
	 * @param string[] $groups     Candidate groups.
	 * @param string[] $tool_names Visible tool names.
	 * @return string[]
	 */
	private function visible_groups_from_tool_names( array $groups, array $tool_names ): array {
		$visible_groups = array();
		foreach ( $groups as $group ) {
			$group_tools = $this->catalog->get_tool_names_for_groups( array( $group ) );
			if ( ! empty( array_intersect( $group_tools, $tool_names ) ) ) {
				$visible_groups[] = $group;
			}
		}

		return array_values( array_unique( $visible_groups ) );
	}
}
