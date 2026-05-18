<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Fix_Security extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'fix_security',
			'description' => 'Apply auto-fixable security fixes. Pass the exact IDs the scan named in each finding\'s `fix_target_keys` (only set when fix_tool="fix_security"). If the scan shows no findings with fix_tool="fix_security", do not call this tool.',
			'params'      => array(
				array( 'name' => 'fixes', 'required' => true, 'desc' => 'Array of fix IDs drawn from scan_security findings\' fix_target_keys. Canonical IDs: "delete_exposed_files", "disable_xmlrpc".' ),
			),
		);

	}

	protected function default_capability(): string {
		return 'confirm';
	}
}
