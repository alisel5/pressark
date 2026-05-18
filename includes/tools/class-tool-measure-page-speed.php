<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Measure_Page_Speed extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'measure_page_speed',
			'description' => 'Measure actual page performance: load time (ms), page size (KB), DOM element count, resource count (scripts, styles, images), cache status, and recommendations.',
			'type'        => 'read',
			'params'      => array(
				array( 'name' => 'url', 'type' => 'string', 'required' => false, 'desc' => 'Default: homepage' ),
			),
		);
	
	}
}
