<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Create_Automation extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'create_automation',
			'description' => 'Create a scheduled automation that runs a prompt on a recurring schedule. AI runs use service credits.',
			'params'      => array(
				array( 'name' => 'prompt', 'required' => true ),
				array( 'name' => 'name', 'required' => false ),
				array( 'name' => 'cadence_type', 'required' => false, 'desc' => 'once|hourly|daily|weekly|monthly|yearly (default: once)' ),
				array( 'name' => 'cadence_value', 'required' => false, 'desc' => 'Hours between runs (hourly only)' ),
				array( 'name' => 'first_run_at', 'required' => false, 'desc' => 'UTC, Y-m-d H:i:s (default: now)' ),
				array( 'name' => 'timezone', 'required' => false, 'desc' => 'IANA timezone (default: site timezone)' ),
				array( 'name' => 'approval_policy', 'required' => false, 'desc' => 'editorial|merchandising|full (default: editorial)' ),
			),
		);
	
	}

	protected function default_capability(): string {
		return 'confirm';
	}
}
