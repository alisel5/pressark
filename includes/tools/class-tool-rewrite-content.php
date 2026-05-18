<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Rewrite_Content extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'rewrite_content',
			// v5.8.4 (2026-05-13, iter-40): description used to claim
			// "Returns new version for review" — but the handler actually
			// returns the SOURCE content + rewrite instructions and expects
			// the MODEL to compose the rewrite, then call edit_content with
			// `changes.content` set to the rewritten markup. Observed Chain
			// "Make about page sound more professional" (2026-05-13): model
			// received the tool result, didn't realize it was a two-step
			// contract, emitted empty reply, chain dead-ended at round 6.
			// The fix: describe the actual two-step contract explicitly.
			'description' => 'Step 1 of a 2-step content rewrite. Reads the source post content and returns it along with rewrite instructions. After receiving the result, YOU compose the rewritten markup (preserving wp:* blocks if preserve_structure=true), then call edit_content with `post_id` and `changes={content: "<your rewrite>"}` to stage the preview. Do not call this tool more than once per post — the second call returns the same source.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => true ),
				array( 'name' => 'instructions', 'required' => false, 'desc' => 'improve|expand|simplify|seo_optimize|change_tone|custom (default: improve)' ),
				array( 'name' => 'tone', 'required' => false ),
				array( 'name' => 'keywords', 'required' => false, 'desc' => 'SEO keywords to weave in' ),
				array( 'name' => 'preserve_structure', 'required' => false, 'desc' => 'Keep headings/sections (default: true)' ),
			),
		);

	}
}
