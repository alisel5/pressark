<?php
/**
 * Shared helpers for compact harness-regression fixtures.
 */

if ( ! function_exists( 'pressark_test_load_json_fixtures' ) ) {
	/**
	 * Load JSON fixtures from a relative directory under pressark/tests.
	 *
	 * @param string $relative_dir Relative directory path.
	 * @return array<int, array<string, mixed>>
	 */
	function pressark_test_load_json_fixtures( string $relative_dir ): array {
		$base    = dirname( __DIR__, 2 ) . '/' . trim( $relative_dir, '/\\' );
		$pattern = $base . '/*.json';
		$files   = glob( $pattern ) ?: array();
		sort( $files );

		$fixtures = array();
		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$decoded['_fixture_file'] = basename( $file );
			$fixtures[]               = $decoded;
		}

		return $fixtures;
	}
}

if ( ! function_exists( 'pressark_test_fixture_value' ) ) {
	/**
	 * Read a nested fixture path using dot notation.
	 *
	 * @param mixed  $data Fixture result data.
	 * @param string $path Dot-notated path.
	 * @return mixed|null
	 */
	function pressark_test_fixture_value( $data, string $path ) {
		$current = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $current ) ) {
				if ( ctype_digit( $segment ) ) {
					$index = (int) $segment;
					if ( ! array_key_exists( $index, $current ) ) {
						return null;
					}
					$current = $current[ $index ];
					continue;
				}

				if ( ! array_key_exists( $segment, $current ) ) {
					return null;
				}
				$current = $current[ $segment ];
				continue;
			}

			return null;
		}

		return $current;
	}
}

if ( ! function_exists( 'pressark_test_assert_fixture_expectations' ) ) {
	/**
	 * Assert compact JSON fixture expectations.
	 *
	 * Supported expectation keys:
	 * - paths: exact equality by dot path
	 * - contains: substring or array membership checks
	 * - excludes: inverse of contains
	 *
	 * @param callable $assert_fn Callback `(string $label, bool $condition, string $detail = ''): void`.
	 * @param string   $prefix    Label prefix.
	 * @param array    $actual    Scenario result.
	 * @param array    $expect    Scenario expectations.
	 * @return void
	 */
	function pressark_test_assert_fixture_expectations( callable $assert_fn, string $prefix, array $actual, array $expect ): void {
		foreach ( (array) ( $expect['paths'] ?? array() ) as $path => $expected ) {
			$value = pressark_test_fixture_value( $actual, (string) $path );
			$assert_fn(
				$prefix . ' [' . $path . ']',
				$expected === $value,
				'expected=' . var_export( $expected, true ) . ' actual=' . var_export( $value, true )
			);
		}

		foreach ( (array) ( $expect['contains'] ?? array() ) as $path => $needles ) {
			$value   = pressark_test_fixture_value( $actual, (string) $path );
			$needles = is_array( $needles ) ? $needles : array( $needles );

			foreach ( $needles as $needle ) {
				$passed = false;
				if ( is_string( $value ) ) {
					$passed = false !== strpos( $value, (string) $needle );
				} elseif ( is_array( $value ) ) {
					$passed = in_array( $needle, $value, true );
				}

				$assert_fn(
					$prefix . ' contains [' . $path . ']',
					$passed,
					'needle=' . var_export( $needle, true ) . ' actual=' . var_export( $value, true )
				);
			}
		}

		foreach ( (array) ( $expect['excludes'] ?? array() ) as $path => $needles ) {
			$value   = pressark_test_fixture_value( $actual, (string) $path );
			$needles = is_array( $needles ) ? $needles : array( $needles );

			foreach ( $needles as $needle ) {
				$passed = true;
				if ( is_string( $value ) ) {
					$passed = false === strpos( $value, (string) $needle );
				} elseif ( is_array( $value ) ) {
					$passed = ! in_array( $needle, $value, true );
				}

				$assert_fn(
					$prefix . ' excludes [' . $path . ']',
					$passed,
					'needle=' . var_export( $needle, true ) . ' actual=' . var_export( $value, true )
				);
			}
		}
	}
}
