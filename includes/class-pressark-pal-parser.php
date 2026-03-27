<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PressArk Action Language (PAL) Parser.
 *
 * Token-efficient compact DSL for AI-generated action proposals.
 * Inspired by OpenUI Lang's positional-argument approach.
 *
 * Format:
 *   @action_name(primary_arg) key=value key2="string" key3={"json": true}
 *
 * Compared to JSON action blocks, PAL uses ~40-60% fewer output tokens
 * by eliminating structural overhead (quoted keys, nested wrappers,
 * "actions"/"type"/"params" boilerplate).
 *
 * @since 4.1.0
 */
class PressArk_PAL_Parser {

	/**
	 * Actions whose flat params should be wrapped into a 'changes' subobject.
	 */
	private const CHANGES_ACTIONS = array(
		'edit_content',
		'create_post',
		'edit_product',
		'create_product',
		'update_media',
		'edit_block',
		'edit_template',
		'edit_variation',
	);

	/**
	 * Actions whose flat params should be wrapped into a 'settings' subobject.
	 */
	private const SETTINGS_ACTIONS = array(
		'update_site_settings',
		'update_theme_setting',
	);

	/**
	 * Actions whose flat params should be wrapped into a 'fixes' subobject.
	 */
	private const FIXES_ACTIONS = array(
		'fix_seo',
		'fix_security',
	);

	/**
	 * Actions whose flat params should be wrapped into an 'item' subobject.
	 */
	private const ITEM_ACTIONS = array(
		'manage_taxonomy',
		'manage_coupon',
		'manage_scheduled_task',
	);

	/**
	 * Keys that remain at the top level even for restructured actions.
	 */
	private const TOP_LEVEL_KEYS = array(
		'post_id',
		'product_id',
		'block_id',
		'block_index',
		'template_id',
		'variation_id',
		'media_id',
		'comment_id',
		'order_id',
		'user_id',
		'menu_id',
		'term_id',
		'coupon_id',
		'zone_id',
		'log_id',
		'mode',
		'scope',
		'status',
		'type',
		'format',
		'action',
		'limit',
		'page',
		'changes',
		'settings',
		'fixes',
		'item',
		'meta',
	);

	/**
	 * Try to parse PAL actions from AI response content.
	 *
	 * Returns null if no PAL patterns are detected (caller should
	 * fall through to JSON parsing).
	 *
	 * @param string $content Raw AI response text.
	 * @return array{message: string, actions: array}|null Parsed result or null.
	 */
	public static function try_parse( string $content ): ?array {
		// Quick bail: no PAL pattern detected.
		if ( ! preg_match( '/(^|\n)\s*@[a-z_]+\s*\(/', $content ) ) {
			return null;
		}

		$actions = array();
		$message_lines = array();

		// Collect all action lines and their positions.
		// An action starts with optional whitespace + @ at line start.
		$lines        = preg_split( '/\r?\n/', $content );
		$action_buffer = '';
		$in_action    = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// New action line starts with @action_name(
			if ( preg_match( '/^@[a-z_]+\s*\(/', $trimmed ) ) {
				// Flush previous action buffer.
				if ( $in_action && '' !== $action_buffer ) {
					$parsed = self::parse_action_line( $action_buffer );
					if ( $parsed ) {
						$actions[] = $parsed;
					}
				}
				$action_buffer = $trimmed;
				$in_action     = true;
				continue;
			}

			// Continuation of a multi-line action (quoted string spanning lines).
			if ( $in_action && '' !== $trimmed && self::has_open_quote( $action_buffer ) ) {
				$action_buffer .= "\n" . $line;
				continue;
			}

			// End of action section if we hit a non-action, non-continuation line.
			if ( $in_action && '' !== $trimmed && ! self::has_open_quote( $action_buffer ) ) {
				// Flush current action.
				$parsed = self::parse_action_line( $action_buffer );
				if ( $parsed ) {
					$actions[] = $parsed;
				}
				$action_buffer = '';
				$in_action     = false;

				// Check if this new line is ALSO an action.
				if ( preg_match( '/^@[a-z_]+\s*\(/', $trimmed ) ) {
					$action_buffer = $trimmed;
					$in_action     = true;
					continue;
				}

				// Otherwise treat as message.
				$message_lines[] = $line;
				continue;
			}

			// Blank line while in action — flush action.
			if ( $in_action && '' === $trimmed ) {
				$parsed = self::parse_action_line( $action_buffer );
				if ( $parsed ) {
					$actions[] = $parsed;
				}
				$action_buffer = '';
				$in_action     = false;
				continue;
			}

			// Regular message line.
			if ( ! $in_action ) {
				$message_lines[] = $line;
			}
		}

		// Flush final action buffer.
		if ( $in_action && '' !== $action_buffer ) {
			$parsed = self::parse_action_line( $action_buffer );
			if ( $parsed ) {
				$actions[] = $parsed;
			}
		}

		if ( empty( $actions ) ) {
			return null;
		}

		return array(
			'message' => trim( implode( "\n", $message_lines ) ),
			'actions' => $actions,
		);
	}

	/**
	 * Parse a single PAL action line into the standard action array.
	 *
	 * Format: @action_name(primary_arg) key=value key2="string" key3={"json": true}
	 *
	 * @param string $line The action line (may span multiple lines for multi-line values).
	 * @return array{type: string, params: array}|null Parsed action or null on failure.
	 */
	public static function parse_action_line( string $line ): ?array {
		// Extract action name and primary arg: @action_name(...)
		if ( ! preg_match( '/^@([a-z_]+)\s*\(/', $line, $name_match ) ) {
			return null;
		}

		$type = $name_match[1];
		$pos  = strlen( $name_match[0] );

		// Read primary argument (everything up to the matching close paren).
		$primary = self::read_primary_arg( $line, $pos );

		// Skip closing paren.
		if ( $pos < strlen( $line ) && ')' === $line[ $pos ] ) {
			$pos++;
		}

		$params = array();

		// Set primary arg (usually post_id).
		if ( '' !== $primary ) {
			if ( in_array( $primary, array( 'all', 'site', '*' ), true ) ) {
				$params['post_id'] = 'all';
			} elseif ( is_numeric( $primary ) ) {
				$params['post_id'] = (int) $primary;
			} else {
				// Quoted or bare string — could be a slug, URL, etc.
				$params['post_id'] = trim( $primary, '"\'' );
			}
		}

		// Parse remaining key=value pairs.
		$rest = substr( $line, $pos );
		if ( is_string( $rest ) && '' !== trim( $rest ) ) {
			$pairs  = self::parse_params( $rest );
			$params = array_merge( $params, $pairs );
		}

		// Restructure flat params into nested subobjects where needed.
		$params = self::restructure_params( $type, $params );

		return array(
			'type'   => $type,
			'params' => $params,
		);
	}

	/**
	 * Read the primary argument from inside parentheses.
	 *
	 * Handles bare values, quoted strings, and nested parens.
	 *
	 * @param string $str Full line.
	 * @param int    $pos Current position (updated by reference).
	 * @return string The primary argument value.
	 */
	private static function read_primary_arg( string $str, int &$pos ): string {
		$len   = strlen( $str );
		$start = $pos;
		$depth = 0;

		while ( $pos < $len ) {
			$ch = $str[ $pos ];

			if ( '"' === $ch || "'" === $ch ) {
				// Skip quoted string entirely.
				$pos++;
				while ( $pos < $len && $str[ $pos ] !== $ch ) {
					if ( '\\' === $str[ $pos ] ) {
						$pos++;
					}
					$pos++;
				}
				if ( $pos < $len ) {
					$pos++; // closing quote
				}
				continue;
			}

			if ( '(' === $ch ) {
				$depth++;
				$pos++;
				continue;
			}

			if ( ')' === $ch ) {
				if ( 0 === $depth ) {
					return trim( substr( $str, $start, $pos - $start ) );
				}
				$depth--;
				$pos++;
				continue;
			}

			$pos++;
		}

		return trim( substr( $str, $start ) );
	}

	/**
	 * Parse key=value pairs from a parameter string.
	 *
	 * @param string $str The parameter portion after @action_name(primary).
	 * @return array Associative array of param name => value.
	 */
	private static function parse_params( string $str ): array {
		$params = array();
		$pos    = 0;
		$len    = strlen( $str );

		while ( $pos < $len ) {
			// Skip whitespace.
			while ( $pos < $len && ctype_space( $str[ $pos ] ) ) {
				$pos++;
			}
			if ( $pos >= $len ) {
				break;
			}

			// Read key (alphanumeric + underscore).
			$key_start = $pos;
			while ( $pos < $len && ( ctype_alnum( $str[ $pos ] ) || '_' === $str[ $pos ] ) ) {
				$pos++;
			}
			$key = substr( $str, $key_start, $pos - $key_start );

			if ( '' === $key ) {
				$pos++;
				continue;
			}

			// Expect '='.
			while ( $pos < $len && ctype_space( $str[ $pos ] ) ) {
				$pos++;
			}
			if ( $pos >= $len || '=' !== $str[ $pos ] ) {
				// Bare flag (no value) — treat as boolean true.
				$params[ $key ] = true;
				continue;
			}
			$pos++; // skip '='

			// Read value.
			$value = self::read_value( $str, $pos );
			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * Read a single value from the parameter string.
	 *
	 * Supports: quoted strings, JSON objects, JSON arrays, numbers, booleans, bare words.
	 *
	 * @param string $str Full string.
	 * @param int    $pos Current position (updated by reference).
	 * @return mixed Parsed value.
	 */
	private static function read_value( string $str, int &$pos ) {
		$len = strlen( $str );

		// Skip whitespace.
		while ( $pos < $len && ctype_space( $str[ $pos ] ) ) {
			$pos++;
		}
		if ( $pos >= $len ) {
			return '';
		}

		$ch = $str[ $pos ];

		// Quoted string.
		if ( '"' === $ch || "'" === $ch ) {
			return self::read_quoted( $str, $pos );
		}

		// JSON object.
		if ( '{' === $ch ) {
			return self::read_balanced( $str, $pos, '{', '}' );
		}

		// JSON array.
		if ( '[' === $ch ) {
			return self::read_balanced( $str, $pos, '[', ']' );
		}

		// Bare value: number, boolean, null, or identifier.
		$start = $pos;
		while ( $pos < $len && ! ctype_space( $str[ $pos ] ) ) {
			$pos++;
		}
		$val = substr( $str, $start, $pos - $start );

		// Type coercion.
		if ( is_numeric( $val ) ) {
			return false !== strpos( $val, '.' ) ? (float) $val : (int) $val;
		}
		if ( 'true' === $val ) {
			return true;
		}
		if ( 'false' === $val ) {
			return false;
		}
		if ( 'null' === $val ) {
			return null;
		}

		return $val;
	}

	/**
	 * Read a quoted string, handling escape sequences.
	 *
	 * @param string $str Full string.
	 * @param int    $pos Current position (updated by reference, starts at opening quote).
	 * @return string The unescaped string content.
	 */
	private static function read_quoted( string $str, int &$pos ): string {
		$quote  = $str[ $pos ];
		$pos++;
		$result = '';
		$len    = strlen( $str );

		while ( $pos < $len ) {
			if ( '\\' === $str[ $pos ] && $pos + 1 < $len ) {
				$next = $str[ $pos + 1 ];
				// Standard escape sequences.
				if ( '"' === $next || "'" === $next || '\\' === $next ) {
					$result .= $next;
					$pos    += 2;
					continue;
				}
				if ( 'n' === $next ) {
					$result .= "\n";
					$pos    += 2;
					continue;
				}
				if ( 't' === $next ) {
					$result .= "\t";
					$pos    += 2;
					continue;
				}
				// Unknown escape — keep as-is.
				$result .= $str[ $pos ];
				$pos++;
				continue;
			}

			if ( $str[ $pos ] === $quote ) {
				$pos++; // consume closing quote
				return $result;
			}

			$result .= $str[ $pos ];
			$pos++;
		}

		// Unterminated string — return what we have.
		return $result;
	}

	/**
	 * Read a balanced bracket expression (JSON object or array).
	 *
	 * Extracts everything between matching open/close brackets and
	 * attempts JSON decode. Falls back to raw string on decode failure.
	 *
	 * @param string $str   Full string.
	 * @param int    $pos   Current position (updated by reference, starts at opening bracket).
	 * @param string $open  Opening bracket character.
	 * @param string $close Closing bracket character.
	 * @return mixed Decoded JSON value (array) or raw string on failure.
	 */
	private static function read_balanced( string $str, int &$pos, string $open, string $close ) {
		$start     = $pos;
		$depth     = 0;
		$len       = strlen( $str );
		$in_string = false;

		while ( $pos < $len ) {
			$ch = $str[ $pos ];

			if ( $in_string ) {
				if ( '\\' === $ch && $pos + 1 < $len ) {
					$pos += 2;
					continue;
				}
				if ( '"' === $ch ) {
					$in_string = false;
				}
				$pos++;
				continue;
			}

			if ( '"' === $ch ) {
				$in_string = true;
				$pos++;
				continue;
			}

			if ( $ch === $open ) {
				$depth++;
				$pos++;
				continue;
			}

			if ( $ch === $close ) {
				$depth--;
				if ( 0 === $depth ) {
					$pos++;
					$json_str = substr( $str, $start, $pos - $start );
					$decoded  = json_decode( $json_str, true );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						return $decoded;
					}
					return $json_str;
				}
				$pos++;
				continue;
			}

			$pos++;
		}

		// Unbalanced — return raw remainder.
		$raw = substr( $str, $start );
		$pos = $len;
		return $raw;
	}

	/**
	 * Check if the buffer has an unclosed quote (for multi-line string detection).
	 *
	 * @param string $buffer The accumulated action text so far.
	 * @return bool True if there's an open quote that hasn't been closed.
	 */
	private static function has_open_quote( string $buffer ): bool {
		$in_quote = false;
		$quote_ch = '';
		$len      = strlen( $buffer );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];

			if ( $in_quote ) {
				if ( '\\' === $ch && $i + 1 < $len ) {
					$i++;
					continue;
				}
				if ( $ch === $quote_ch ) {
					$in_quote = false;
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$in_quote = true;
				$quote_ch = $ch;
			}
		}

		return $in_quote;
	}

	/**
	 * Restructure flat params into nested subobjects for specific action types.
	 *
	 * For edit_content, flat keys like 'title', 'content' get wrapped into
	 * a 'changes' subobject. This allows compact PAL:
	 *   @edit_content(42) title="New Title" content="<p>text</p>"
	 * to produce:
	 *   {"type": "edit_content", "params": {"post_id": 42, "changes": {"title": "New Title", "content": "<p>text</p>"}}}
	 *
	 * @param string $type   Action type name.
	 * @param array  $params Flat params from PAL parsing.
	 * @return array Restructured params.
	 */
	private static function restructure_params( string $type, array $params ): array {
		$wrap_key = null;

		if ( in_array( $type, self::CHANGES_ACTIONS, true ) ) {
			$wrap_key = 'changes';
		} elseif ( in_array( $type, self::SETTINGS_ACTIONS, true ) ) {
			$wrap_key = 'settings';
		} elseif ( in_array( $type, self::FIXES_ACTIONS, true ) ) {
			$wrap_key = 'fixes';
		} elseif ( in_array( $type, self::ITEM_ACTIONS, true ) ) {
			$wrap_key = 'item';
		}

		// update_meta wraps into 'meta'.
		if ( 'update_meta' === $type ) {
			$wrap_key = 'meta';
		}

		if ( null === $wrap_key ) {
			return $params;
		}

		$top    = array();
		$nested = array();

		foreach ( $params as $key => $value ) {
			if ( in_array( $key, self::TOP_LEVEL_KEYS, true ) ) {
				$top[ $key ] = $value;
			} else {
				$nested[ $key ] = $value;
			}
		}

		// If the wrap_key already exists (AI sent it explicitly), merge.
		if ( ! empty( $nested ) ) {
			$existing          = $top[ $wrap_key ] ?? array();
			$existing          = is_array( $existing ) ? $existing : array();
			$top[ $wrap_key ]  = array_merge( $existing, $nested );
		}

		return $top;
	}

	/**
	 * Convert a standard action array back to PAL format.
	 *
	 * Used for system prompt examples and debugging.
	 *
	 * @param array $action Standard action array with 'type' and 'params'.
	 * @return string PAL representation.
	 */
	public static function to_pal( array $action ): string {
		$type   = $action['type'] ?? 'unknown';
		$params = $action['params'] ?? array();

		// Extract primary arg (post_id by default).
		$primary    = '';
		$primary_id = $params['post_id'] ?? $params['product_id'] ?? $params['media_id'] ?? '';
		if ( '' !== $primary_id ) {
			$primary = (string) $primary_id;
		}

		// Flatten nested subobjects for compact representation.
		$flat = array();
		foreach ( $params as $key => $value ) {
			// Skip the primary arg key.
			if ( in_array( $key, array( 'post_id', 'product_id', 'media_id' ), true ) && (string) $value === $primary ) {
				continue;
			}
			// Flatten changes/settings/fixes/meta/item.
			if ( in_array( $key, array( 'changes', 'settings', 'fixes', 'meta', 'item' ), true ) && is_array( $value ) ) {
				foreach ( $value as $sub_key => $sub_value ) {
					$flat[ $sub_key ] = $sub_value;
				}
				continue;
			}
			$flat[ $key ] = $value;
		}

		$parts = array( '@' . $type . '(' . $primary . ')' );

		foreach ( $flat as $key => $value ) {
			$parts[] = $key . '=' . self::format_value( $value );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Format a value for PAL output.
	 *
	 * @param mixed $value The value to format.
	 * @return string PAL-formatted value.
	 */
	private static function format_value( $value ): string {
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		}
		if ( is_string( $value ) && ! preg_match( '/[\s"\'={}\\[\\]]/', $value ) ) {
			return $value; // bare word
		}
		// Quoted string — escape inner quotes.
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
	}
}
