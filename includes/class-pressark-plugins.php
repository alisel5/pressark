<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin management for PressArk.
 * Lists, activates, and deactivates WordPress plugins.
 */
class PressArk_Plugins {

	/**
	 * List all installed plugins with status and update info.
	 */
	public function list_all(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$all_plugins = get_plugins();
		$active      = get_option( 'active_plugins', array() );
		$updates     = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		$results     = array();

		foreach ( $all_plugins as $file => $data ) {
			$is_active  = in_array( $file, $active, true );
			$has_update = isset( $updates[ $file ] );
			$results[]  = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => $data['AuthorName'] ?? $data['Author'],
				'description'      => wp_strip_all_tags( $data['Description'] ),
				'active'           => $is_active,
				'update_available' => $has_update,
				'new_version'      => $has_update ? $updates[ $file ]->update->new_version : null,
			);
		}

		return $results;
	}

	/**
	 * Activate or deactivate a plugin.
	 */
	public function toggle( string $plugin_file, bool $activate = true ): array {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Safety: don't allow deactivating PressArk itself.
		if ( strpos( $plugin_file, 'pressark' ) !== false ) {
			return array( 'success' => false, 'message' => 'Cannot deactivate PressArk through itself.' );
		}

		if ( $activate ) {
			$result = activate_plugin( $plugin_file );
			if ( is_wp_error( $result ) ) {
				return array( 'success' => false, 'message' => $result->get_error_message() );
			}
			$name = $this->get_plugin_name( $plugin_file );
			return array( 'success' => true, 'message' => "Activated \"{$name}\"." );
		} else {
			deactivate_plugins( $plugin_file );
			$name = $this->get_plugin_name( $plugin_file );
			return array( 'success' => true, 'message' => "Deactivated \"{$name}\"." );
		}
	}

	/**
	 * Get detailed info for a specific plugin.
	 */
	public function get_info( string $plugin_file ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( ! isset( $all[ $plugin_file ] ) ) {
			return null;
		}
		$data           = $all[ $plugin_file ];
		$data['active'] = is_plugin_active( $plugin_file );
		$data['file']   = $plugin_file;
		return $data;
	}

	/**
	 * Get a plugin's display name from its file path.
	 */
	private function get_plugin_name( string $file ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return $all[ $file ]['Name'] ?? $file;
	}
}
