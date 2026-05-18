<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Fix_Seo extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'fix_seo',
			'description' => 'Apply SEO fixes. Prefer passing specific fixes so user sees exact changes in preview.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false, 'desc' => 'Post ID or "all" (default: "all")' ),
				array( 'name' => 'fixes', 'required' => false, 'desc' => 'Either a list of per-post objects [{post_id, meta_title, meta_description, og_title, og_description, og_image, focus_keyword}, ...] OR (when calling once per post) a flat field map {meta_title, meta_description, og_title, og_description, og_image, focus_keyword} that inherits the top-level post_id.' ),
				array( 'name' => 'force', 'required' => false, 'desc' => 'Set true to overwrite existing manual/template meta. Default: false (only fills empty fields).' ),
			),
		);
	
	}

	protected function default_capability(): string {
		return 'preview';
	}
}
