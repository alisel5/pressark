<?php
/**
 * PressArk Operation — Value object representing a single operation contract.
 *
 * Every tool the AI can invoke is defined as an Operation with all its
 * runtime semantics in one place: capability level, handler routing,
 * preview strategy, group membership, plugin requirements, discovery
 * metadata, and risk classification.
 *
 * @package PressArk
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Operation {

	/** @var string[] Tool names classified as meta-tools (loader/discovery). */
	private const META_TOOLS = array( 'discover_tools', 'load_tools', 'load_tool_group' );

	/**
	 * @param string      $name             Tool name (e.g., 'edit_content').
	 * @param string      $group            Group name (e.g., 'core', 'seo', 'woocommerce').
	 * @param string      $capability       'read' | 'preview' | 'confirm'.
	 * @param string      $handler          Handler key: 'discovery', 'content', 'seo', 'system',
	 *                                      'media', 'diagnostics', 'elementor', 'woo', 'automation'.
	 * @param string      $method           Method name on the handler (e.g., 'read_content').
	 * @param string      $preview_strategy Staging strategy: 'none', 'post_edit', 'new_post',
	 *                                      'meta_update', 'option_update', 'block_edit',
	 *                                      'elementor_widget', 'elementor_page'.
	 * @param string|null $requires         Plugin requirement: 'woocommerce', 'elementor', or null.
	 * @param string      $label            Human-readable short name (e.g., 'Edit Content').
	 * @param string      $description      One-liner for compact discovery.
	 * @param string      $risk             'safe' | 'moderate' | 'destructive'.
	 */
	public function __construct(
		public string  $name,
		public string  $group,
		public string  $capability,
		public string  $handler,
		public string  $method,
		public string  $preview_strategy = 'none',
		public ?string $requires = null,
		public string  $label = '',
		public string  $description = '',
		public string  $risk = 'safe',
	) {}

	public function is_read(): bool {
		return 'read' === $this->capability;
	}

	public function is_preview(): bool {
		return 'preview' === $this->capability;
	}

	public function is_confirm(): bool {
		return 'confirm' === $this->capability;
	}

	public function is_write(): bool {
		return 'read' !== $this->capability;
	}

	public function needs_staging(): bool {
		return 'none' !== $this->preview_strategy;
	}

	public function is_meta(): bool {
		return in_array( $this->name, self::META_TOOLS, true );
	}

	public function is_destructive(): bool {
		return 'destructive' === $this->risk;
	}
}
