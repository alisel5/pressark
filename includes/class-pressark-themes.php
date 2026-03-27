<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme management for PressArk.
 * Lists themes, reads/updates customizer settings, switches themes.
 */
class PressArk_Themes {

	/**
	 * List all installed themes with rich metadata.
	 */
	public function list_all(): array {
		$themes  = wp_get_themes();
		$active  = wp_get_theme();
		$results = array();

		foreach ( $themes as $slug => $theme ) {
			$entry = array(
				'slug'           => $slug,
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'author'         => $theme->get( 'Author' ),
				'description'    => wp_trim_words( $theme->get( 'Description' ), 20 ),
				'tags'           => $theme->get( 'Tags' ) ?: array(),
				'requires_wp'    => $theme->get( 'RequiresWP' ),
				'requires_php'   => $theme->get( 'RequiresPHP' ),
				'is_block_theme' => $theme->is_block_theme(),
				'is_child'       => (bool) $theme->parent(),
				'parent'         => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'active'         => ( $slug === $active->get_stylesheet() ),
				'screenshot'     => $theme->get_screenshot(),
			);

			// Flag version incompatibilities.
			$entry['compatible_php'] = ! $theme->get( 'RequiresPHP' )
				|| version_compare( PHP_VERSION, $theme->get( 'RequiresPHP' ), '>=' );
			$entry['compatible_wp']  = ! $theme->get( 'RequiresWP' )
				|| version_compare( get_bloginfo( 'version' ), $theme->get( 'RequiresWP' ), '>=' );

			// Include page templates for the active theme.
			if ( $slug === $active->get_stylesheet() ) {
				$templates = $theme->get_page_templates( null, 'page' );
				if ( ! empty( $templates ) ) {
					$entry['page_templates'] = array_values( $templates );
				}
			}

			$results[] = $entry;
		}

		return $results;
	}

	/**
	 * Get current theme's customizer settings.
	 */
	public function get_customizer_settings(): array {
		$mods      = get_theme_mods();
		$safe_mods = array();

		foreach ( $mods as $key => $value ) {
			if ( str_starts_with( $key, 'nav_menu_locations' ) ) {
				continue;
			}
			if ( is_scalar( $value ) || is_array( $value ) ) {
				$safe_mods[ $key ] = $value;
			}
		}

		return $safe_mods;
	}

	/**
	 * Update a single customizer setting.
	 */
	public function update_customizer_setting( string $key, $value ): array {
		// Safety whitelist — block dangerous keys.
		$blocked = array( 'nav_menu_locations', 'sidebars_widgets' );
		if ( in_array( $key, $blocked, true ) ) {
			return array( 'success' => false, 'message' => "Cannot modify \"{$key}\" through this tool." );
		}

		$previous = get_theme_mod( $key );
		set_theme_mod( $key, $value );

		return array( 'success' => true, 'previous' => $previous );
	}

	/**
	 * Switch the active theme.
	 */
	public function switch_theme( string $stylesheet ): array {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return array( 'success' => false, 'message' => "Theme \"{$stylesheet}\" not found." );
		}

		// Check PHP version requirement.
		$requires_php = $theme->get( 'RequiresPHP' );
		if ( $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					'Cannot activate "%s" — it requires PHP %s but your server runs PHP %s.',
					$theme->get( 'Name' ),
					$requires_php,
					PHP_VERSION
				),
			);
		}

		// Check WordPress version requirement.
		$requires_wp = $theme->get( 'RequiresWP' );
		if ( $requires_wp && version_compare( get_bloginfo( 'version' ), $requires_wp, '<' ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					'Cannot activate "%s" — it requires WordPress %s but you have %s.',
					$theme->get( 'Name' ),
					$requires_wp,
					get_bloginfo( 'version' )
				),
			);
		}

		// Collect warnings about potential data loss.
		$warnings = array();

		$current_menus    = get_nav_menu_locations();
		$current_sidebars = wp_get_sidebars_widgets();
		$has_menus        = ! empty( array_filter( $current_menus ) );
		$has_widgets      = ! empty( array_filter( $current_sidebars, fn( $w ) => ! empty( $w ) ) );

		if ( $has_menus ) {
			$warnings[] = 'Your current menu assignments may need to be remapped to the new theme\'s menu locations.';
		}
		if ( $has_widgets ) {
			$warnings[] = 'Your current widget configuration may not transfer to the new theme\'s sidebar areas.';
		}
		if ( $theme->is_block_theme() && ! wp_is_block_theme() ) {
			$warnings[] = 'This is a block theme. It uses the Site Editor instead of the Customizer. Your current theme customizations will not transfer.';
		}

		$previous = get_stylesheet();
		switch_theme( $stylesheet );

		$result = array(
			'success'        => true,
			'message'        => "Switched theme from \"{$previous}\" to \"{$stylesheet}\".",
			'previous_theme' => $previous,
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}
}
