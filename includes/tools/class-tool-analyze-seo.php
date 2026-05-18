<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Analyze_Seo extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'analyze_seo',
			'description' => 'Deep SEO analysis with subscores (indexing_health, search_appearance, content_quality, social_sharing) for a single page or full site. Use limit/offset to paginate site-wide scans. Each finding with a `fix` text also carries `fix_tool` (which PressArk tool resolves it, or null when theme/SEO-plugin-handled) and `fix_target_keys` (the exact param keys that tool accepts for this finding). Route fixes by reading those two fields — no need to map check names to tool params.',
			'params'      => array(
				array( 'name' => 'post_id', 'type' => array( 'integer', 'string' ), 'required' => false, 'desc' => 'Post ID (integer) for a single-page audit, or "all" / "site" for a site-wide scan. Omit for site-wide.' ),
				array( 'name' => 'limit', 'required' => false, 'desc' => 'Max pages to scan in site-wide mode (default: 50, max: 100)' ),
				array( 'name' => 'offset', 'required' => false, 'desc' => 'Skip first N pages in site-wide scan (default: 0)' ),
			),
		);

	}
}
