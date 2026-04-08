<?php
/**
 * Focused regression tests for PressArk Elementor save/create flows.
 *
 * Run: php pressark/tests/test-elementor-save-pipeline.php
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	}

	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		define( 'ELEMENTOR_VERSION', '3.28.0' );
	}

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	$GLOBALS['pressark_test_meta']                 = array();
	$GLOBALS['pressark_test_posts']                = array();
	$GLOBALS['pressark_test_transients']           = array();
	$GLOBALS['pressark_test_css_updates']          = array();
	$GLOBALS['pressark_test_document_transforms']  = array();
	$GLOBALS['pressark_test_next_post_id']         = 100;

	function pressark_test_unslash( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = pressark_test_unslash( $item );
			}
			return $value;
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	class WP_Error {
		private $message;

		public function __construct( $code = '', $message = '' ) {
			unset( $code );
			$this->message = (string) $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return $thing instanceof WP_Error;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ) {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $text ) {
			return trim( strip_tags( (string) $text ) );
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $text ) {
			$text = strtolower( trim( strip_tags( (string) $text ) ) );
			$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
			return trim( (string) $text, '-' );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
	}

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$value[ $key ] = wp_slash( $item );
				}
				return $value;
			}

			return is_string( $value ) ? addslashes( $value ) : $value;
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text ) {
			return trim( strip_tags( (string) $text ) );
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $post_id, $key, $single = true ) {
			unset( $single );
			return $GLOBALS['pressark_test_meta'][ $post_id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $key, $value ) {
			$GLOBALS['pressark_test_meta'][ $post_id ][ $key ] = pressark_test_unslash( $value );
			return true;
		}
	}

	if ( ! function_exists( 'clean_post_cache' ) ) {
		function clean_post_cache( $post_id ) {
			unset( $post_id );
		}
	}

	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			return $GLOBALS['pressark_test_transients'][ $key ] ?? false;
		}
	}

	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $key, $value, $expiration ) {
			unset( $expiration );
			$GLOBALS['pressark_test_transients'][ $key ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( $args ) {
			$id = $GLOBALS['pressark_test_next_post_id']++;
			$args['ID'] = $id;
			$GLOBALS['pressark_test_posts'][ $id ] = $args;

			foreach ( (array) ( $args['meta_input'] ?? array() ) as $meta_key => $meta_value ) {
				update_post_meta( $id, $meta_key, $meta_value );
			}

			return $id;
		}
	}

	if ( ! function_exists( 'get_post_status' ) ) {
		function get_post_status( $post_id ) {
			return $GLOBALS['pressark_test_posts'][ $post_id ]['post_status'] ?? 'draft';
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $post_id ) {
			if ( ! isset( $GLOBALS['pressark_test_posts'][ $post_id ] ) ) {
				return null;
			}

			return (object) $GLOBALS['pressark_test_posts'][ $post_id ];
		}
	}

	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post_id ) {
			return 'https://example.test/?p=' . (int) $post_id;
		}
	}

	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( $path = '' ) {
			return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
		}
	}

	class PressArk_Error_Tracker {
		public static $errors = array();

		public static function error( $source, $message, array $context = array() ) {
			self::$errors[] = array(
				'source'  => $source,
				'message' => $message,
				'context' => $context,
			);
		}
	}

	class PressArk_Test_WPDB {
		public $posts   = 'wp_posts';
		public $updates = array();

		public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
			unset( $formats, $where_formats );
			$this->updates[] = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);
			if ( isset( $where['ID'] ) ) {
				$GLOBALS['pressark_test_posts'][ (int) $where['ID'] ]['post_content'] = $data['post_content'] ?? '';
			}
			return 1;
		}
	}

	$wpdb = new PressArk_Test_WPDB();
}

namespace Elementor {
	class Plugin {
		public static $instance;
	}

	class Widget_Base {
		private $name;
		private $title;
		private $controls;

		public function __construct( string $name = '', string $title = '', array $controls = array() ) {
			$this->name     = $name;
			$this->title    = $title ?: $name;
			$this->controls = $controls;
		}

		public function get_controls() {
			return $this->controls;
		}

		public function get_title() {
			return $this->title;
		}

		public function get_icon() {
			return 'eicon-test';
		}

		public function get_categories() {
			return array( 'basic' );
		}

		public function get_keywords() {
			return array();
		}

		public function get_name() {
			return $this->name;
		}
	}
}

namespace Elementor\Core\Files\CSS {
	class Post {
		private $post_id;

		private function __construct( int $post_id ) {
			$this->post_id = $post_id;
		}

		public static function create( $post_id ) {
			return new self( (int) $post_id );
		}

		public function update() {
			$GLOBALS['pressark_test_css_updates'][] = $this->post_id;
		}
	}
}

namespace {
	class PressArk_Test_Element_Instance {
		private $element;

		public function __construct( array $element ) {
			$this->element = $element;
		}

		public function get_data_for_save() {
			return $this->element;
		}
	}

	class PressArk_Test_Elements_Manager {
		public function create_element_instance( array $element, array $args = array(), $element_type = null ) {
			unset( $args, $element_type );
			return new PressArk_Test_Element_Instance( $element );
		}
	}

	class PressArk_Test_Experiments {
		public function is_feature_active( $feature ) {
			return 'container' === $feature;
		}
	}

	class PressArk_Test_Document {
		private $post_id;

		public function __construct( int $post_id ) {
			$this->post_id = $post_id;
		}

		public function save( $data ) {
			$elements = $data['elements'] ?? array();
			$transform = $GLOBALS['pressark_test_document_transforms'][ $this->post_id ] ?? null;
			if ( is_callable( $transform ) ) {
				$elements = $transform( $elements );
			}

			update_post_meta( $this->post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
			update_post_meta( $this->post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $this->post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		public function get_settings() {
			return array();
		}

		public function get_main_id() {
			return $this->post_id;
		}
	}

	class PressArk_Test_Documents_Manager {
		public $documents    = array();
		public $create_calls = array();

		public function create( $type, $post_data = array(), $meta_data = array() ) {
			$this->create_calls[] = array(
				'type'      => $type,
				'post_data' => $post_data,
				'meta_data' => $meta_data,
			);

			$post_data['meta_input'] = $meta_data;
			$post_id                 = wp_insert_post( $post_data );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$document = new PressArk_Test_Document( (int) $post_id );
			$this->documents[ (int) $post_id ] = $document;
			$document->save( array() );

			return $document;
		}

		public function get( $post_id ) {
			if ( ! isset( $this->documents[ $post_id ] ) ) {
				$this->documents[ $post_id ] = new PressArk_Test_Document( (int) $post_id );
			}

			return $this->documents[ $post_id ];
		}
	}

	class PressArk_Test_Widgets_Manager {
		private $widgets;

		public function __construct() {
			$this->widgets = array(
				'video'       => new \Elementor\Widget_Base(
					'video',
					'Video',
					array(
						'youtube_url' => array(
							'type'    => 'url',
							'label'   => 'URL',
							'default' => '',
							'tab'     => 'content',
						),
					)
				),
				'text-editor' => new \Elementor\Widget_Base(
					'text-editor',
					'Text Editor',
					array(
						'editor' => array(
							'type'    => 'wysiwyg',
							'label'   => 'Text Editor',
							'default' => '<p>Placeholder</p>',
							'tab'     => 'content',
						),
					)
				),
				'heading'     => new \Elementor\Widget_Base(
					'heading',
					'Heading',
					array(
						'title'       => array(
							'type'    => 'text',
							'label'   => 'Title',
							'default' => 'Add Your Heading Text Here',
							'tab'     => 'content',
						),
						'header_size' => array(
							'type'    => 'select',
							'label'   => 'HTML Tag',
							'default' => 'h2',
							'tab'     => 'content',
						),
						'align'       => array(
							'type'    => 'choose',
							'label'   => 'Alignment',
							'default' => 'left',
							'tab'     => 'content',
						),
					)
				),
			);
		}

		public function get_widget_types() {
			return $this->widgets;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-pressark-elementor.php';

	\Elementor\Plugin::$instance = (object) array(
		'documents'       => new PressArk_Test_Documents_Manager(),
		'elements_manager' => new PressArk_Test_Elements_Manager(),
		'widgets_manager' => new PressArk_Test_Widgets_Manager(),
		'experiments'     => new PressArk_Test_Experiments(),
	);

	$passed = 0;
	$failed = 0;

	function assert_true_elementor( string $label, bool $condition, string $detail = '' ): void {
		global $passed, $failed;

		if ( $condition ) {
			$passed++;
			echo "  PASS: {$label}\n";
			return;
		}

		$failed++;
		echo "  FAIL: {$label}\n";
		if ( '' !== $detail ) {
			echo "    {$detail}\n";
		}
	}

	function assert_same_elementor( string $label, $expected, $actual ): void {
		assert_true_elementor(
			$label,
			$expected === $actual,
			'Expected: ' . var_export( $expected, true ) . ' | Actual: ' . var_export( $actual, true )
		);
	}

	function pressark_test_collect_titles( array $elements ): array {
		$titles = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings']['title'] ) ) {
				$titles[] = $element['settings']['title'];
			}

			if ( ! empty( $element['elements'] ) ) {
				$titles = array_merge( $titles, pressark_test_collect_titles( $element['elements'] ) );
			}
		}

		return $titles;
	}

	echo "=== Elementor Save Pipeline Tests ===\n\n";

	echo "--- Test 1: Fallback restores tree when Document::save drops elements ---\n";
	$elementor = new PressArk_Elementor();
	$post_id   = wp_insert_post(
		array(
			'post_title'  => 'Fallback Target',
			'post_status' => 'draft',
			'post_type'   => 'page',
		)
	);
	$full_tree  = array(
		array(
			'id'       => 'root-1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'widget-1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Saved heading' ),
					'elements'   => array(),
				),
			),
			'isInner'  => false,
		),
	);
	$GLOBALS['pressark_test_document_transforms'][ $post_id ] = static function( array $elements ): array {
		if ( empty( $elements[0] ) ) {
			return $elements;
		}

		$elements[0]['elements'] = array();
		return $elements;
	};

	$elementor->save_elementor_data( $post_id, $full_tree );
	$saved_tree = $elementor->get_elementor_data( $post_id );

	assert_same_elementor( 'fallback keeps the full element tree', $full_tree, $saved_tree );
	assert_true_elementor(
		'fallback logs the dropped-element condition',
		! empty( PressArk_Error_Tracker::$errors ),
		'Expected at least one logged fallback error.'
	);

	echo "\n--- Test 2: Live schema wins over stale manual alias mapping ---\n";
	assert_same_elementor(
		'video url alias resolves to youtube_url',
		'youtube_url',
		PressArk_Elementor::resolve_field_key( 'video', 'url' )
	);

	echo "\n--- Test 3: Manual aliases still work when they match live controls ---\n";
	assert_same_elementor(
		'text-editor content alias resolves to editor',
		'editor',
		PressArk_Elementor::resolve_field_key( 'text-editor', 'content' )
	);

	echo "\n--- Test 4: create_page uses Elementor documents manager and inserts real widget content ---\n";
	$GLOBALS['pressark_test_document_transforms'] = array();
	\Elementor\Plugin::$instance->documents->create_calls = array();

	$result = $elementor->create_page(
		'Launch Landing Page',
		'canvas',
		'draft',
		0,
		array(
			array(
				'type'     => 'heading',
				'settings' => array( 'title' => 'Hero headline' ),
			),
		),
		'page'
	);

	$page_tree = $elementor->get_elementor_data( (int) $result['post_id'] );
	$titles    = pressark_test_collect_titles( $page_tree );
	$create_calls = \Elementor\Plugin::$instance->documents->create_calls;

	assert_true_elementor( 'create_page succeeds', ! empty( $result['success'] ), var_export( $result, true ) );
	assert_same_elementor( 'documents manager create uses wp-page', 'wp-page', $create_calls[0]['type'] ?? null );
	assert_true_elementor(
		'created page stores requested heading content',
		in_array( 'Hero headline', $titles, true ),
		var_export( $page_tree, true )
	);
	assert_true_elementor(
		'created page does not keep placeholder heading copy',
		! in_array( 'Your Heading Here', $titles, true ) && ! in_array( 'Add Your Heading Text Here', $titles, true ),
		var_export( $titles, true )
	);
	assert_same_elementor( 'create_page reports inserted widget count', 1, (int) ( $result['widgets_inserted'] ?? 0 ) );
	assert_same_elementor( 'create_page reports no widget errors', array(), $result['widget_errors'] ?? array() );
	assert_same_elementor(
		'canvas template is stored on the created page',
		'elementor_canvas',
		get_post_meta( (int) $result['post_id'], '_wp_page_template', true )
	);

	echo "\n--- Test 5: read_page rejects invalid Elementor payloads without warnings ---\n";
	$invalid_post_id = wp_insert_post(
		array(
			'post_title'  => 'Broken Elementor Payload',
			'post_status' => 'draft',
			'post_type'   => 'page',
		)
	);
	update_post_meta( $invalid_post_id, '_elementor_data', 'not-json' );

	$invalid_result = $elementor->read_page( $invalid_post_id );
	assert_same_elementor(
		'read_page returns a clear parse error for invalid Elementor data',
		'Elementor data is invalid or could not be parsed.',
		$invalid_result['error'] ?? null
	);

	echo "\nResults: {$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
