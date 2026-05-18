<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Update_Menu extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'update_menu',
			'description' => 'Create, modify, or assign navigation menus. Handles FSE and classic menus. For add_item, the simplest shape is `post_id` alone — label and URL resolve from the post automatically. For bulk additions, pass `items` (or `append`/`posts`) as a list of {post_id} entries to add multiple nav items in one call.',
			'params'      => array(
				array( 'name' => 'operation', 'required' => true, 'desc' => 'create_menu|add_item|remove_item|assign_location|rename_menu|delete_menu' ),
				array( 'name' => 'menu_id', 'required' => false ),
				array( 'name' => 'name', 'required' => false, 'desc' => 'For create_menu and rename_menu' ),
				array( 'name' => 'item', 'required' => false, 'desc' => 'Single item to add: `{post_id: 28}` (label/url resolved from post) OR `{label, url}` for an external link. `{title}` accepted as an alias for `label`.' ),
				array( 'name' => 'items', 'required' => false, 'desc' => 'Bulk add: list of items, e.g. `[{post_id: 28}, {post_id: 30}]`. Aliases: `append`, `posts`. `posts` also accepts scalar IDs: `[28, 30]`.' ),
				array( 'name' => 'item_id', 'required' => false, 'desc' => 'For remove_item on classic menus' ),
				array( 'name' => 'location', 'required' => false, 'desc' => 'Theme location slug for assign_location' ),
			),
		);

	}

	protected function default_capability(): string {
		return 'confirm';
	}
}
