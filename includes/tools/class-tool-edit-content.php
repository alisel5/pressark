<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Tool_Edit_Content extends PressArk_Tool_Base {

	protected function definition(): array {
		return array(
			'name'        => 'edit_content',
			'description' => 'RECOMMENDED: Edit native WordPress content with full block support. Best choice for design changes.',
			'params'      => array(
				array( 'name' => 'post_id', 'required' => false ),
				array( 'name' => 'url', 'required' => false ),
				array( 'name' => 'slug', 'required' => false ),
				array( 'name' => 'changes', 'required' => true, 'desc' => 'Fields: title, content (full replace), append_content (add after existing), prepend_content (add before existing), excerpt, slug, status, sticky, post_format. To add a paragraph without rewriting the whole post, use append_content/prepend_content with valid wp:* block markup.' ),
			),
		);
	
	}

	protected function default_capability(): string {
		return 'preview';
	}

	protected function prompt_weight(): int {
		return 10;
	}
}
