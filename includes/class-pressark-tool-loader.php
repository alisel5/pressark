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
	 * Universal high-ROI tools always sent as schemas, regardless of
	 * which groups are loaded. These are the most commonly needed read
	 * tools across all task types. Avoids a discover→load round-trip
	 * for 80%+ of requests.
	 *
	 * @since 3.8.0
	 */
	/**
	 * v4.3.3: Trimmed to 3 core reads only. search_knowledge now loads with
	 * content/index flows instead of every agent request. analyze_seo and
	 * get_custom_fields remain discoverable via meta-tools and load
	 * automatically when their group is activated. Saves additional schema
	 * overhead on non-content turns.
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
	 * v2.3.1: Accepts pre-loaded groups from conversation state.
	 * v3.8.0: Always includes UNIVERSAL_TOOLS. Returns capability_map
	 *         instead of per-tool descriptors.
	 *
	 * @param string   $message       The user's current message (unused in v2.3.1, kept for compat).
	 * @param array    $conversation  Conversation history (unused in v2.3.1, kept for compat).
	 * @param string   $tier          User's tier (reserved for future tier-based limits).
	 * @param string[] $loaded_groups Groups already loaded in this conversation.
	 * @return array {
	 *     schemas:        array,   OpenAI function schemas to send.
	 *     descriptors:    string,  @deprecated — empty string (backward compat).
	 *     capability_map: string,  Compact group→capability text (~50 tokens).
	 *     groups:         array,   Group names currently loaded.
	 *     strategy:       string,  'filtered' or 'full'.
	 *     tool_count:     int,     Number of schemas loaded.
	 *     tool_names:     array,   Tool names currently loaded.
	 * }
	 */
	public function resolve( string $message, array $conversation, string $tier, array $loaded_groups = array() ): array {
		// Start from the minimal base set, then layer in conversation-scoped groups.
		$groups = array_values( array_unique(
			array_merge( self::BASE_GROUPS, $loaded_groups )
		) );
		// Get tool names from loaded groups + universal tools.
		$tool_names = array_values( array_unique(
			array_merge(
				$this->catalog->get_tool_names_for_groups( $groups ),
				self::UNIVERSAL_TOOLS
			)
		) );

		$schemas        = $this->catalog->get_schemas( $tool_names );
		$capability_map = $this->catalog->get_capability_map( $groups );

		return array(
			'schemas'        => $schemas,
			'descriptors'    => '', // Deprecated — kept for backward compat.
			'capability_map' => $capability_map,
			'groups'         => $groups,
			'strategy'       => 'filtered',
			'tool_count'     => count( $schemas ),
			'tool_names'     => $tool_names,
		);
	}

	/**
	 * Resolve for models with native tool search (GPT-5.4-class).
	 *
	 * Skips local discovery scaffolding (discover_tools, load_tools meta-tools)
	 * and sends ALL tool schemas directly — these models handle large tool sets
	 * natively with built-in search/selection.
	 *
	 * @since 3.8.0
	 *
	 * @param string $tier User's tier.
	 * @return array Same shape as resolve().
	 */
	public function resolve_native_search( string $tier ): array {
		$has_woo       = class_exists( 'WooCommerce' );
		$has_elementor = class_exists( '\\Elementor\\Plugin' );
		$all_tools     = PressArk_Tools::get_all( $has_woo, $has_elementor );

		$schemas = array();
		foreach ( $all_tools as $tool ) {
			$schemas[] = PressArk_Tools::tool_to_schema( $tool );
		}

		// No meta-tools needed — model searches natively.

		// Canonical sort for cache stability.
		usort( $schemas, function ( $a, $b ) {
			return strcmp( $a['function']['name'] ?? '', $b['function']['name'] ?? '' );
		} );

		$tool_names = array();
		foreach ( $schemas as $s ) {
			$tool_names[] = $s['function']['name'] ?? '';
		}

		return array(
			'schemas'        => $schemas,
			'descriptors'    => '',
			'capability_map' => '',
			'groups'         => PressArk_Operation_Registry::group_names(),
			'strategy'       => 'native_search',
			'tool_count'     => count( $schemas ),
			'tool_names'     => $tool_names,
		);
	}

	/**
	 * Expand the current tool set with an additional group.
	 * Called when the AI invokes the load_tools or load_tool_group meta-tool.
	 *
	 * @param array  $current Current result from resolve() or a previous expand().
	 * @param string $group   Group name to add.
	 * @return array Updated result (same shape as resolve()).
	 */
	public function expand( array $current, string $group ): array {
		$groups = $current['groups'] ?? array();

		// Already loaded — no-op.
		if ( in_array( $group, $groups, true ) ) {
			return $current;
		}

		// Validate group exists.
		if ( ! PressArk_Operation_Registry::is_valid_group( $group ) ) {
			return $current;
		}

		$groups[]   = $group;
		$tool_names = array_values( array_unique(
			array_merge(
				$this->catalog->get_tool_names_for_groups( $groups ),
				self::UNIVERSAL_TOOLS
			)
		) );
		$schemas        = $this->catalog->get_schemas( $tool_names );
		$capability_map = $this->catalog->get_capability_map( $groups );

		return array(
			'schemas'        => $schemas,
			'descriptors'    => '',
			'capability_map' => $capability_map,
			'groups'         => $groups,
			'strategy'       => 'filtered',
			'tool_count'     => count( $schemas ),
			'tool_names'     => $tool_names,
		);
	}

	/**
	 * Expand the current tool set by loading specific tools (by name).
	 * Resolves each tool to its parent group and loads the full group.
	 *
	 * @since 2.3.1
	 *
	 * @param array    $current    Current result from resolve() or a previous expand.
	 * @param string[] $tool_names Specific tool names to add.
	 * @return array Updated result (same shape as resolve()).
	 */
	public function expand_tools( array $current, array $tool_names ): array {
		$groups    = $current['groups'] ?? array();
		$has_woo   = class_exists( 'WooCommerce' );
		$has_elem  = class_exists( '\\Elementor\\Plugin' );
		$changed   = false;

		// Find parent group for each tool and add it.
		foreach ( $tool_names as $name ) {
			$group = $this->catalog->find_group_for_tool( $name );
			if ( ! $group || in_array( $group, $groups, true ) ) {
				continue;
			}
			// Conditional plugin checks.
			if ( 'woocommerce' === $group && ! $has_woo ) {
				continue;
			}
			if ( 'elementor' === $group && ! $has_elem ) {
				continue;
			}
			$groups[] = $group;
			$changed  = true;
		}

		if ( ! $changed ) {
			return $current;
		}

		$groups      = array_values( array_unique( $groups ) );
		$all_names   = array_values( array_unique(
			array_merge(
				$this->catalog->get_tool_names_for_groups( $groups ),
				self::UNIVERSAL_TOOLS
			)
		) );
		$schemas        = $this->catalog->get_schemas( $all_names );
		$capability_map = $this->catalog->get_capability_map( $groups );

		return array(
			'schemas'        => $schemas,
			'descriptors'    => '',
			'capability_map' => $capability_map,
			'groups'         => $groups,
			'strategy'       => 'filtered',
			'tool_count'     => count( $schemas ),
			'tool_names'     => $all_names,
		);
	}

	/**
	 * Load all tools (bypass filtering).
	 * Used as fallback or for debugging.
	 *
	 * @return array Same shape as resolve() but with all tools loaded.
	 */
	public function resolve_full(): array {
		$schemas = ( new PressArk_Tools() )->get_all_tools();

		// Add meta-tool schemas (v2.3.1).
		$meta_schemas = $this->catalog->get_meta_tools_schemas();
		foreach ( $meta_schemas as $ms ) {
			$schemas[] = $ms;
		}

		// Sort for cache stability.
		usort( $schemas, function ( $a, $b ) {
			return strcmp( $a['function']['name'] ?? '', $b['function']['name'] ?? '' );
		} );

		// Collect all tool names.
		$tool_names = array();
		foreach ( $schemas as $s ) {
			$tool_names[] = $s['function']['name'] ?? '';
		}

		return array(
			'schemas'        => $schemas,
			'descriptors'    => '',
			'capability_map' => '',
			'groups'         => PressArk_Operation_Registry::group_names(),
			'strategy'       => 'full',
			'tool_count'     => count( $schemas ),
			'tool_names'     => $tool_names,
		);
	}
}
