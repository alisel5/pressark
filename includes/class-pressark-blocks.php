<?php
/**
 * PressArk Gutenberg Block Tools
 *
 * Provides block-level read/edit/insert using WordPress native
 * parse_blocks() and serialize_blocks() functions.
 * No third-party dependencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Blocks {

	/**
	 * Get block schema from the WordPress Block Type Registry.
	 * Replaces the hardcoded BLOCK_SCHEMA constant.
	 * Covers every registered block: core, plugin, theme, custom.
	 * Cached per-request in a static variable.
	 *
	 * @return array Associative: block_name => schema data
	 */
	public static function get_block_schema(): array {
		static $cached = null;
		if ( $cached !== null ) return $cached;

		$registry = WP_Block_Type_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$schema   = array();

		foreach ( $all as $name => $block_type ) {
			$schema[ $name ] = array(
				'label'         => $block_type->title ?: ucwords( str_replace( array( '/', '-' ), ' ', $name ) ),
				'category'      => $block_type->category ?? 'unknown',
				'description'   => $block_type->description ?? '',
				'attributes'    => $block_type->attributes ?: array(),
				'supports'      => $block_type->supports   ?: array(),
				'is_dynamic'    => $block_type->is_dynamic(),
				'parent'        => $block_type->parent,
				'ancestor'      => $block_type->ancestor,
				'content_field' => self::detect_content_field( $block_type->attributes ?? array() ),
			);
		}

		$cached = $schema;
		return $schema;
	}

	/**
	 * Detect which attribute holds the main text content of a block.
	 * Used to determine what to show in previews and what to update on text edits.
	 */
	private static function detect_content_field( array $attrs ): ?string {
		foreach ( $attrs as $key => $def ) {
			$type   = $def['type']   ?? '';
			$source = $def['source'] ?? '';
			if ( $type === 'rich-text' || $source === 'rich-text' || $source === 'html' ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Read a post's blocks as a structured, human-readable tree.
	 * Returns block index (0-based), type, label, content preview,
	 * inner blocks, and any issues flagged inline.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public function read_blocks( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		if ( ! has_blocks( $post->post_content ) ) {
			return array(
				'post_id'      => $post_id,
				'is_gutenberg' => false,
				'message'      => 'This post uses the Classic Editor, not Gutenberg blocks.',
				'content'      => wp_trim_words( $post->post_content, 50 ),
			);
		}

		$raw_blocks = parse_blocks( $post->post_content );
		$blocks     = array();
		$index      = 0;
		$issues     = array();
		$word_count = 0;

		foreach ( $raw_blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue; // skip whitespace blocks
			}

			$parsed   = $this->parse_block( $block, $index, $issues, $word_count );
			$blocks[] = $parsed;
			$index++;
		}

		return array(
			'post_id'      => $post_id,
			'title'        => $post->post_title,
			'is_gutenberg' => true,
			'block_count'  => count( $blocks ),
			'word_count'   => $word_count,
			'blocks'       => $blocks,
			'issues'       => $issues,
			'edit_hint'    => 'Use block index (0-based) with edit_block to modify a specific block.',
		);
	}

	/**
	 * Parse a single block into a readable structure.
	 */
	private function parse_block(
		array $block,
		$index,
		array &$issues,
		int   &$word_count
	): array {
		$name   = $block['blockName'] ?? 'core/freeform';
		$schema = self::get_block_schema()[ $name ] ?? null;

		$label      = $schema['label']      ?? ucwords( str_replace( array( 'core/', '/' ), array( '', ' ' ), $name ) );
		$is_dynamic = $schema['is_dynamic'] ?? false;
		$inner      = $block['innerContent'] ?? array();
		$attrs      = $block['attrs'] ?? array();

		// Build preview from inner HTML.
		$html    = implode( '', array_filter( $inner, 'is_string' ) );
		$text    = wp_strip_all_tags( $html );
		$preview = mb_substr( trim( $text ), 0, 100 );
		if ( strlen( $text ) > 100 ) {
			$preview .= '…';
		}

		$word_count += str_word_count( $text );

		// Build node.
		$node = array(
			'index'   => $index,
			'name'    => $name,
			'label'   => $label,
			'preview' => $preview,
			'attrs'   => $attrs,
			'flags'   => array(),
		);

		// Flag dynamic (server-rendered) blocks.
		if ( $is_dynamic ) {
			$node['is_dynamic']    = true;
			$node['dynamic_note']  = 'This block renders server-side. Attribute changes take effect; innerHTML changes do not.';
		}

		// Per-type enrichment and issue detection.
		switch ( $name ) {
			case 'core/heading':
				$level           = $attrs['level'] ?? 2;
				$node['label']   = 'H' . $level . ' Heading';
				$node['preview'] = '"' . $preview . '"';
				break;

			case 'core/image':
				$alt             = $attrs['alt'] ?? '';
				$node['preview'] = ( $attrs['url'] ?? '(no url)' ) . ' | alt: "' . $alt . '"';
				if ( empty( $alt ) ) {
					$node['flags'][] = 'missing_alt';
					$issues[] = array(
						'index'   => $index,
						'type'    => 'missing_alt',
						'message' => "Image block at index {$index} has no alt text.",
					);
				}
				break;

			case 'core/button':
				$url = $attrs['url'] ?? '';
				if ( empty( $url ) ) {
					$node['flags'][] = 'missing_link';
					$issues[] = array(
						'index'   => $index,
						'type'    => 'missing_link',
						'message' => "Button block at index {$index} has no URL.",
					);
				}
				break;
		}

		// Recurse into inner blocks (columns, groups, etc.).
		if ( ! empty( $block['innerBlocks'] ) ) {
			$node['inner_blocks'] = array();
			$inner_index          = 0;
			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( empty( $inner_block['blockName'] ) ) {
					continue;
				}
				$node['inner_blocks'][] = $this->parse_block(
					$inner_block,
					$index . '.' . $inner_index,
					$issues,
					$word_count
				);
				$inner_index++;
			}
		}

		return $node;
	}

	/**
	 * Edit a specific block by index.
	 * Supports: content (innerHTML), attrs, and natural-language field names.
	 *
	 * @param int   $post_id
	 * @param mixed $block_index  Integer index (from read_blocks). Supports "2.1" for inner blocks.
	 * @param array $updates      Associative: ['content' => 'new text', 'level' => 3, 'url' => '...']
	 * @return array
	 */
	public function edit_block( int $post_id, $block_index, array $updates ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		if ( ! has_blocks( $post->post_content ) ) {
			return array( 'error' => 'This post uses Classic Editor, not Gutenberg blocks.' );
		}

		$blocks  = parse_blocks( $post->post_content );
		$changed = array();
		$success = $this->apply_block_edit( $blocks, $block_index, $updates, $changed );

		if ( ! $success ) {
			return array( 'error' => "Block at index {$block_index} not found." );
		}

		// Serialize back to post content.
		$new_content = serialize_blocks( $blocks );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		) );

		return array(
			'success'     => true,
			'post_id'     => $post_id,
			'block_index' => $block_index,
			'changes'     => $changed,
		);
	}

	/**
	 * Recursively find and edit a block by index.
	 */
	private function apply_block_edit(
		array &$blocks,
		$target_index,
		array  $updates,
		array &$changed
	): bool {
		$index = 0;
		foreach ( $blocks as &$block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( (string) $index === (string) $target_index ) {
				// Apply updates.
				foreach ( $updates as $key => $value ) {
					if ( $key === 'content' ) {
						// Guard: dynamic blocks render server-side — innerHTML edits are no-ops.
						$schema     = self::get_block_schema()[ $block['blockName'] ?? '' ] ?? null;
						$is_dynamic = $schema['is_dynamic'] ?? false;

						if ( $is_dynamic ) {
							$changed[] = array(
								'field'   => 'content',
								'warning' => 'This block (' . $block['blockName'] . ') renders server-side. '
								           . 'Content is controlled by PHP — innerHTML edits have no effect. '
								           . 'Update block attributes instead.',
								'skipped' => true,
							);
							continue;
						}

						// Replace inner HTML content.
						$old_html = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
						$name     = $block['blockName'];

						// Wrap in appropriate tag based on block type.
						$tag_map = array(
							'core/paragraph' => 'p',
							'core/heading'   => 'h' . ( $block['attrs']['level'] ?? 2 ),
							'core/button'    => 'a',
							'core/list-item' => 'li',
						);
						$tag = $tag_map[ $name ] ?? 'div';

						// Preserve existing attributes from old HTML.
						preg_match( '/<' . $tag . '([^>]*)>/', $old_html, $attr_match );
						$attrs_str = $attr_match[1] ?? '';
						$new_html  = '<' . $tag . $attrs_str . '>' . $value . '</' . $tag . '>';

						if ( empty( $block['innerBlocks'] ) ) {
							$block['innerContent'] = array( $new_html );
						}
						$changed[] = array( 'field' => 'content', 'before' => wp_strip_all_tags( $old_html ), 'after' => $value );

					} else {
						// Update block attribute.
						$old = $block['attrs'][ $key ] ?? null;
						$block['attrs'][ $key ] = $value;
						$changed[] = array( 'field' => $key, 'before' => $old, 'after' => $value );
					}
				}
				return true;
			}

			// Recurse into inner blocks (e.g., index "2.1").
			if ( ! empty( $block['innerBlocks'] ) ) {
				$prefix = $index . '.';
				if ( str_starts_with( (string) $target_index, $prefix ) ) {
					$inner_index = substr( (string) $target_index, strlen( $prefix ) );
					if ( $this->apply_block_edit( $block['innerBlocks'], $inner_index, $updates, $changed ) ) {
						return true;
					}
				}
			}

			$index++;
		}
		return false;
	}

	/**
	 * Insert a new block at a specific position.
	 *
	 * @param int    $post_id
	 * @param string $block_type  e.g., 'core/paragraph', 'core/heading'
	 * @param array  $attrs       Block attributes.
	 * @param string $content     Inner HTML content.
	 * @param int    $position    Insert before this index. -1 = append at end.
	 * @return array
	 */
	public function insert_block(
		int    $post_id,
		string $block_type,
		array  $attrs   = array(),
		string $content = '',
		int    $position = -1
	): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found.' );
		}

		$blocks = has_blocks( $post->post_content )
			? parse_blocks( $post->post_content )
			: array();

		// Build the new block.
		$tag_map = array(
			'core/paragraph' => 'p',
			'core/heading'   => 'h' . ( $attrs['level'] ?? 2 ),
			'core/list-item' => 'li',
			'core/button'    => 'a',
		);
		$tag = $tag_map[ $block_type ] ?? 'div';

		// Build only valid HTML classes from block attributes.
		// Block attributes (level, textAlign, etc.) belong in the JSON comment
		// delimiter handled by serialize_blocks(), NOT in the innerHTML.
		$classes = array();
		if ( ! empty( $attrs['className'] ) ) {
			$classes[] = $attrs['className'];
		}
		if ( ! empty( $attrs['textAlign'] ) ) {
			$classes[] = 'has-text-align-' . $attrs['textAlign'];
		}
		if ( ! empty( $attrs['align'] ) ) {
			$classes[] = 'align' . $attrs['align'];
		}
		$class_attr = ! empty( $classes ) ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';
		$inner_html = ! empty( $content ) ? "<{$tag}{$class_attr}>{$content}</{$tag}>" : '';

		$new_block = array(
			'blockName'    => $block_type,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_html ? array( $inner_html ) : array(),
		);

		// Filter out null blocks.
		$blocks = array_values( array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );

		if ( $position === -1 || $position >= count( $blocks ) ) {
			$blocks[] = $new_block;
		} else {
			array_splice( $blocks, $position, 0, array( $new_block ) );
		}

		$new_content = serialize_blocks( $blocks );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );

		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'block_type' => $block_type,
			'position'   => $position === -1 ? count( $blocks ) - 1 : $position,
			'content'    => $content,
		);
	}

	/**
	 * Build valid HTML class attribute string from block attributes.
	 *
	 * Only maps className, textAlign, and align to CSS classes.
	 * All other block attributes belong in the JSON comment delimiter
	 * (handled by serialize_blocks()), not in innerHTML.
	 */
	private function build_attr_string( array $attrs ): string {
		$classes = array();
		if ( ! empty( $attrs['className'] ) ) {
			$classes[] = $attrs['className'];
		}
		if ( ! empty( $attrs['textAlign'] ) ) {
			$classes[] = 'has-text-align-' . $attrs['textAlign'];
		}
		if ( ! empty( $attrs['align'] ) ) {
			$classes[] = 'align' . $attrs['align'];
		}
		if ( empty( $classes ) ) {
			return '';
		}
		return 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
	}

	private function rebuild_inner_content( array $block ): array {
		// Preserve the original innerContent structure.
		// String entries are the container's own wrapper markup;
		// null entries mark where each inner block is rendered.
		// Only replace the non-null (string) entries if needed;
		// the null placeholders must remain intact.
		if ( ! empty( $block['innerContent'] ) ) {
			return $block['innerContent'];
		}

		// Fallback: build a minimal null-interleaved array.
		$content = array();
		foreach ( $block['innerBlocks'] as $i => $inner ) {
			$content[] = null;
		}
		return $content;
	}
}
