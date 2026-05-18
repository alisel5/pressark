<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin management for PressArk.
 * Lists WordPress plugins.
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
			$row        = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => $data['AuthorName'] ?? $data['Author'],
				'description'      => wp_strip_all_tags( $data['Description'] ),
				'active'           => $is_active,
				'update_available' => $has_update,
				'new_version'      => $has_update ? $updates[ $file ]->update->new_version : null,
			);
			if ( class_exists( 'PressArk_Extension_Manifests' ) ) {
				$extension_summary = PressArk_Extension_Manifests::plugin_summary( $file, $data );
				if ( ! empty( $extension_summary['detected'] ) ) {
					$row['pressark_extension'] = $extension_summary;
				}
			}
			$results[] = $row;
		}

		return $results;
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
		if ( class_exists( 'PressArk_Extension_Manifests' ) ) {
			$extension_summary = PressArk_Extension_Manifests::plugin_summary( $plugin_file, $data );
			if ( ! empty( $extension_summary['detected'] ) ) {
				$data['pressark_extension'] = $extension_summary;
			}
		}
		return $data;
	}
}
