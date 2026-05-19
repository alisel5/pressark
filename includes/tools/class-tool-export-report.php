<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Export_Report extends PressArk_Tool_Base {

	protected function default_capability(): string {
		return 'confirm';
	}

	protected function definition(): array {
		return array(
			'name'        => 'export_report',
			'description' => 'Generate an authenticated HTML report. Returns a short-lived download link.',
			'params'      => array(
				array( 'name' => 'report_type', 'required' => true, 'desc' => 'seo|security|site_overview|woocommerce' ),
				array( 'name' => 'include_recommendations', 'required' => false, 'desc' => 'Default: true' ),
			),
		);
	
	}
}
