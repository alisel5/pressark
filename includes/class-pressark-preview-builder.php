<?php
/**
 * PressArk Preview Builder — Dispatches preview generation to handlers.
 *
 * Replaces the monolithic generate_preview() switch in PressArk_Chat by
 * routing each action type to a preview_{method}() on the owning handler.
 * Falls back to PressArk_Handler_Base::default_preview() when a handler
 * does not implement a specific preview method.
 *
 * @package PressArk
 * @since   4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Preview_Builder {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** @var array<string, PressArk_Handler_Base> Lazily-created handler instances. */
	private array $handlers = array();

	/** @var PressArk_Action_Logger Logger shared by handler instances. */
	private PressArk_Action_Logger $logger;

	/** @var array<string, string> Handler key → class name map. */
	private const HANDLER_MAP = array(
		'content'     => 'PressArk_Handler_Content',
		'seo'         => 'PressArk_Handler_SEO',
		'system'      => 'PressArk_Handler_System',
		'media'       => 'PressArk_Handler_Media',
		'diagnostics' => 'PressArk_Handler_Diagnostics',
		'woo'         => 'PressArk_Handler_WooCommerce',
		'elementor'   => 'PressArk_Handler_Elementor',
		'discovery'   => 'PressArk_Handler_Discovery',
		'automation'  => 'PressArk_Handler_Automation',
	);

	public function __construct( ?PressArk_Action_Logger $logger = null ) {
		$this->logger = $logger ?? new PressArk_Action_Logger();
	}

	/**
	 * Get or create the singleton instance.
	 */
	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset singleton (testing only).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Build preview data for a write action.
	 *
	 * @param array $action Action array with 'type' and 'params'.
	 * @return array Preview data including 'type' and 'changes'.
	 */
	public function build( array $action ): array {
		$type    = $action['type'] ?? '';
		$preview = array( 'type' => $type );

		// Normalize params — support both nested and flat formats.
		$params = $action['params'] ?? $action;

		// Look up the operation in the registry.
		$op = PressArk_Operation_Registry::resolve( $type );

		if ( ! $op ) {
			$handler = $this->get_handler( 'content' );
			return array_merge( $preview, $handler->default_preview( $type, $params ) );
		}

		$handler = $this->get_handler( $op->handler );
		$method  = 'preview_' . $op->method;

		if ( method_exists( $handler, $method ) ) {
			return array_merge( $preview, $handler->$method( $params, $action ) );
		}

		return array_merge( $preview, $handler->default_preview( $type, $params ) );
	}

	/**
	 * Get or lazily create a handler instance by key.
	 *
	 * @param string $key Handler key (e.g. 'content', 'seo').
	 * @return PressArk_Handler_Base
	 */
	private function get_handler( string $key ): PressArk_Handler_Base {
		if ( ! isset( $this->handlers[ $key ] ) ) {
			$class = self::HANDLER_MAP[ $key ] ?? 'PressArk_Handler_Content';
			$this->handlers[ $key ] = new $class( $this->logger );
		}
		return $this->handlers[ $key ];
	}
}
