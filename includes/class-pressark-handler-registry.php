<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy factory and dispatch layer for action handlers.
 *
 * Keeps handler construction and routing out of the action engine so the
 * engine can stay focused on normalization, entitlements, and execution flow.
 *
 * @since 4.1.2
 */
class PressArk_Handler_Registry {

	/** @var array<string, class-string> */
	private const HANDLER_CLASSES = array(
		'discovery'   => PressArk_Handler_Discovery::class,
		'seo'         => PressArk_Handler_SEO::class,
		'diagnostics' => PressArk_Handler_Diagnostics::class,
		'media'       => PressArk_Handler_Media::class,
		'content'     => PressArk_Handler_Content::class,
		'system'      => PressArk_Handler_System::class,
		'elementor'   => PressArk_Handler_Elementor::class,
		'woo'         => PressArk_Handler_WooCommerce::class,
		'automation'  => PressArk_Handler_Automation::class,
	);

	private PressArk_Action_Logger $logger;

	/** @var array<string, object> */
	private array $instances = array();

	private string $async_task_id = '';

	public function __construct( PressArk_Action_Logger $logger ) {
		$this->logger = $logger;
	}

	public function set_async_context( string $task_id ): void {
		$this->async_task_id = $task_id;

		foreach ( $this->instances as $handler ) {
			if ( $handler instanceof PressArk_Handler_Base ) {
				$handler->set_async_context( $task_id );
			}
		}
	}

	public function dispatch( PressArk_Operation $operation, array $params ): array {
		$handler = $this->get( $operation->handler );
		$method  = $operation->method;

		if ( ! is_callable( array( $handler, $method ) ) ) {
			throw new \UnexpectedValueException(
				sprintf(
					'Handler "%s" does not implement callable method "%s".',
					$operation->handler,
					$method
				)
			);
		}

		return $handler->{$method}( $params );
	}

	public function get( string $key ): object {
		if ( ! isset( $this->instances[ $key ] ) ) {
			$this->instances[ $key ] = $this->build_handler( $key );
		}

		return $this->instances[ $key ];
	}

	private function build_handler( string $key ): object {
		$class = self::HANDLER_CLASSES[ $key ] ?? '';

		if ( '' === $class ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown handler key: %s', $key ) );
		}

		$handler = new $class( $this->logger );

		if ( $handler instanceof PressArk_Handler_Base && $this->async_task_id ) {
			$handler->set_async_context( $this->async_task_id );
		}

		return $handler;
	}
}
