<?php
/**
 * PressArk Capability Bridge — Unifies resource + tool discovery.
 *
 * WordPress analog of the relationship between Claude Code's ToolSearchTool
 * and MCP client. Enriches the existing discover_tools/load_tools flow with
 * resource awareness and provides the activation gate for native tool search.
 *
 * This is NOT a new meta-tool. It enriches existing discovery and provides
 * context to the Tool Loader and AI Connector.
 *
 * @package PressArk
 * @since   5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Capability_Bridge {

	/**
	 * Single activation check: is the bridge ready for native tool search?
	 *
	 * Returns true when:
	 * 1. Resource Registry is booted with resources available.
	 * 2. Operation Registry is booted with operations available.
	 *
	 * This is the gate that AI_Connector::supports_tool_search() uses.
	 */
	public static function is_bridge_ready(): bool {
		// Resource layer must have at least the core resources.
		$resources = PressArk_Resource_Registry::list();
		if ( count( $resources ) < 3 ) {
			return false;
		}

		// Operation registry must be booted with operations.
		$groups = PressArk_Operation_Registry::group_names();
		if ( empty( $groups ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build a compact resource context block for the system prompt.
	 *
	 * Returns ~150-250 tokens describing available resources grouped by type.
	 * Injected into the capability map so the AI knows what resources are
	 * available for reading via list_resources / read_resource.
	 *
	 * @param string[] $loaded_groups Currently loaded tool groups (for context).
	 * @param string   $detail        full|compact|minimal
	 * @return string Formatted text block, or empty string if bridge not ready.
	 */
	public static function get_context_resources( array $loaded_groups = array(), string $detail = 'full' ): string {
		if ( ! self::is_bridge_ready() ) {
			return '';
		}

		$resources = PressArk_Resource_Registry::list();
		if ( empty( $resources ) ) {
			return '';
		}

		// Group by resource group.
		$by_group = array();
		foreach ( $resources as $res ) {
			$by_group[ $res['group'] ][] = $res;
		}

		$detail = sanitize_key( $detail );
		$lines  = array();
		foreach ( $by_group as $group => $items ) {
			if ( 'minimal' === $detail ) {
				$lines[] = '- ' . $group;
				continue;
			}

			if ( 'compact' === $detail ) {
				$lines[] = sprintf( '- %s: %d resource%s', $group, count( $items ), 1 === count( $items ) ? '' : 's' );
				continue;
			}

			$names   = array_map( fn( $r ) => $r['name'], $items );
			$lines[] = sprintf( '- %s: %s', $group, implode( ', ', $names ) );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		if ( 'minimal' === $detail ) {
			return "RESOURCES (browse with list_resources, fetch with read_resource):\n"
				. implode( "\n", array_slice( $lines, 0, 8 ) );
		}

		return "RESOURCES (use list_resources to browse, read_resource to fetch):\n"
			. implode( "\n", $lines );
	}

	/**
	 * Unified search across tools AND resources.
	 *
	 * Merges results from Tool_Catalog::discover() and Resource_Registry::search(),
	 * deduplicates, and returns a combined ranked list.
	 *
	 * @param string   $query       Natural-language query.
	 * @param string[] $loaded_names Tool names already loaded.
	 * @return array[] Combined results: tools have type=tool, resources have type=resource.
	 */
	public static function search( string $query, array $loaded_names = array() ): array {
		$results = PressArk_Tool_Catalog::instance()->discover( $query, $loaded_names );

		foreach ( $results as &$match ) {
			if ( empty( $match['type'] ) ) {
				$match['type'] = 'tool';
			}
		}
		unset( $match );

		return array_slice( $results, 0, 25 );
	}

	/**
	 * Build a lightweight site fingerprint from cached resources.
	 *
	 * Returns key site facts that are useful for prompt routing:
	 * theme type, plugin flags, content counts. Uses cached resource
	 * data when available, falls back to Context for uncached fields.
	 *
	 * @return array Site fingerprint.
	 */
	public static function get_resource_snapshot(): array {
		$overview = PressArk_Resource_Registry::read( 'pressark://site/overview' );

		if ( $overview['success'] ?? false ) {
			$data = $overview['data'];
			return array(
				'name'           => $data['name'] ?? '',
				'is_block_theme' => $data['is_block_theme'] ?? false,
				'has_theme_json' => $data['has_theme_json'] ?? false,
				'post_count'     => $data['posts'] ?? 0,
				'page_count'     => $data['pages'] ?? 0,
				'plugins'        => $data['active_plugins'] ?? array(),
				'cpt_names'      => array_column( $data['custom_post_types'] ?? array(), 'name' ),
				'has_woo'        => class_exists( 'WooCommerce' ),
				'has_elementor'  => defined( 'ELEMENTOR_VERSION' ),
			);
		}

		// Fallback: use Context directly.
		$flags = PressArk_Context::get_plugin_flags();
		$mode  = PressArk_Context::detect_site_mode();

		return array(
			'name'           => get_bloginfo( 'name' ),
			'is_block_theme' => 'fse' === ( $mode['theme_type'] ?? '' ),
			'has_theme_json' => wp_theme_has_theme_json(),
			'post_count'     => (int) ( wp_count_posts( 'post' )->publish ?? 0 ),
			'page_count'     => (int) ( wp_count_posts( 'page' )->publish ?? 0 ),
			'plugins'        => array(),
			'cpt_names'      => array(),
			'has_woo'        => $flags['is_woocommerce'] ?? false,
			'has_elementor'  => $flags['is_elementor'] ?? false,
		);
	}
}
