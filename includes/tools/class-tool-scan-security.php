<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Scan_Security extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'scan_security',
			'description' => 'Run a site security audit. Each finding carries `auto_fixable`, plus (when actionable) `fix_tool` (which PressArk tool resolves it — typically "fix_security" or null for server/manual fixes) and `fix_target_keys` (the exact fix IDs to pass to fix_security). Use those fields directly — no inference required.',
			'params'      => array(
				array( 'name' => 'severity', 'required' => false, 'desc' => 'critical|high|medium|low — filter to only issues at this severity or higher' ),
			),
		);

	}
}
