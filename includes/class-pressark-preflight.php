<?php
/**
 * PressArk Preflight — Central validation, canonicalization, and reroute layer.
 *
 * Runs AFTER alias resolution and input validation but BEFORE entitlements,
 * policy evaluation, and handler dispatch.  Detects "wrong tool for this
 * object / context" situations and returns structured reroute hints so the
 * AI model is guided into the correct native WordPress operation instead of
 * hitting dead-end error messages deep inside a handler.
 *
 * Design philosophy (inspired by Claude Code's validateInput + updatedInput):
 *
 *   Claude Code ─ validateInput is guard-only (bool + message).
 *     Input rewriting lives in hooks / permissions via `updatedInput`.
 *     No built-in reroute to a different tool.
 *
 *   PressArk Preflight ─ goes further:
 *     1. Guard    — block with reason (same as Claude's validateInput).
 *     2. Reroute  — suggest (or silently apply) a different canonical tool
 *                   with rewritten params. Preserves intent, fixes path.
 *     3. Rewrite  — same tool, adjusted params (canonicalization).
 *     4. Proceed  — no intervention needed.
 *
 * Domain rules are registered as callables.  Each rule receives the tool
 * name, params, and a lightweight site-context snapshot and returns a
 * PreflightResult or null (= no opinion).  First non-null result wins.
 *
 * @package PressArk
 * @since   5.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Preflight {

	// ── Result actions ────────────────────────────────────────────────

	/** No intervention — proceed with the original tool and params. */
	const ACTION_PROCEED = 'proceed';

	/** Wrong tool — reroute to a different canonical tool. */
	const ACTION_REROUTE = 'reroute';

	/** Same tool, adjusted params (canonicalization / safe rewrite). */
	const ACTION_REWRITE = 'rewrite';

	/** Hard block — operation is unsafe or impossible in this context. */
	const ACTION_BLOCK = 'block';

	// ── Rule storage ──────────────────────────────────────────────────

	/**
	 * Registered preflight rules.
	 *
	 * Each rule is [ callable, priority ].
	 * Signature: fn(string $tool, array $params, array $context): ?array
	 *
	 * @var array<int, array{callable, int}>
	 */
	private static array $rules = array();

	/** @var bool Whether built-in rules have been registered. */
	private static bool $booted = false;

	// ── Public API ────────────────────────────────────────────────────

	/**
	 * Run preflight checks on a tool call.
	 *
	 * @param string $tool   Canonical tool name (after alias resolution).
	 * @param array  $params Tool parameters from the AI.
	 * @return array Preflight result.
	 */
	public static function check( string $tool, array $params ): array {
		self::ensure_booted();

		$context = self::build_context( $tool, $params );

		// Rules are sorted by priority (lower = earlier).
		foreach ( self::$rules as $entry ) {
			$result = call_user_func( $entry[0], $tool, $params, $context );
			if ( null !== $result && is_array( $result ) ) {
				$result['original_tool'] = $tool;
				return $result;
			}
		}

		return self::proceed();
	}

	/**
	 * Register a preflight rule.
	 *
	 * @param callable $rule     fn(string $tool, array $params, array $context): ?array
	 * @param int      $priority Lower = runs earlier. Default 100.
	 */
	public static function register_rule( callable $rule, int $priority = 100 ): void {
		self::$rules[] = array( $rule, $priority );

		// Re-sort by priority.
		usort( self::$rules, function ( $a, $b ) {
			return $a[1] <=> $b[1];
		} );
	}

	/**
	 * Reset rules (testing only).
	 */
	public static function reset(): void {
		self::$rules  = array();
		self::$booted = false;
	}

	// ── Result constructors ──────────────────────────────────────────

	/**
	 * No intervention needed.
	 */
	public static function proceed(): array {
		return array( 'action' => self::ACTION_PROCEED );
	}

	/**
	 * Reroute to a different tool with rewritten params.
	 *
	 * @param string $target_tool Canonical tool name to use instead.
	 * @param array  $new_params  Rewritten parameters for the target tool.
	 * @param string $reason      Human-readable explanation for the model.
	 * @param string $hint        Additional guidance (e.g. "read first with…").
	 */
	public static function reroute( string $target_tool, array $new_params, string $reason, string $hint = '' ): array {
		return array(
			'action'  => self::ACTION_REROUTE,
			'tool'    => $target_tool,
			'params'  => $new_params,
			'reason'  => $reason,
			'hint'    => $hint,
		);
	}

	/**
	 * Same tool, adjusted params.
	 *
	 * @param array  $new_params Canonicalized params.
	 * @param string $reason     Why params were adjusted.
	 */
	public static function rewrite( array $new_params, string $reason ): array {
		return array(
			'action' => self::ACTION_REWRITE,
			'params' => $new_params,
			'reason' => $reason,
		);
	}

	/**
	 * Block execution entirely.
	 *
	 * @param string $reason Why this is blocked.
	 * @param string $hint   What the model should do instead.
	 */
	public static function block( string $reason, string $hint = '' ): array {
		return array(
			'action' => self::ACTION_BLOCK,
			'reason' => $reason,
			'hint'   => $hint,
		);
	}

	// ── Built-in domain rules ────────────────────────────────────────

	private static function ensure_booted(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Priority 50: Domain-specific reroutes.
		self::register_rule( array( __CLASS__, 'rule_elementor_content_edit' ), 50 );
		self::register_rule( array( __CLASS__, 'rule_wc_product_content_edit' ), 50 );
		self::register_rule( array( __CLASS__, 'rule_wc_guarded_meta' ), 50 );
		self::register_rule( array( __CLASS__, 'rule_fse_template_mismatch' ), 50 );
		self::register_rule( array( __CLASS__, 'rule_fse_customizer_on_block_theme' ), 50 );
		self::register_rule( array( __CLASS__, 'rule_elementor_raw_meta_edit' ), 50 );

		/**
		 * Allow third parties to register preflight rules.
		 *
		 * @since 5.5.0
		 */
		do_action( 'pressark_preflight_boot' );
	}

	// ── Rule: Elementor content edit ─────────────────────────────────

	/**
	 * edit_content on an Elementor page → reroute to elementor tools.
	 *
	 * Direct post_content edits are invisible on Elementor pages because
	 * Elementor renders from _elementor_data, not post_content.
	 */
	public static function rule_elementor_content_edit( string $tool, array $params, array $context ): ?array {
		if ( 'edit_content' !== $tool ) {
			return null;
		}

		$post_id = $context['post_id'];
		if ( ! $post_id ) {
			return null;
		}

		// Check if this post uses Elementor.
		$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return null;
		}

		$changes      = $params['changes'] ?? $params;
		// v5.6.1: append/prepend verbs hit the same post_content path as
		// 'content' and are equally invisible on Elementor pages.
		$touches_body = isset( $changes['content'] )
			|| isset( $changes['append_content'] ) || isset( $changes['content_append'] ) || isset( $changes['append'] )
			|| isset( $changes['prepend_content'] ) || isset( $changes['content_prepend'] ) || isset( $changes['prepend'] );
		if ( ! $touches_body ) {
			return null; // Not touching content — title/status/etc changes are fine.
		}

		return self::reroute(
			'elementor_find_replace',
			array( 'post_id' => $post_id ),
			__( 'This page uses Elementor. Direct post_content edits have no visible effect — Elementor renders from its own data structure.', 'pressark' ),
			__( 'Use elementor_edit_widget to modify widget content, or elementor_find_replace for text changes. Read the page first with read_content(mode=structured) to see the Elementor widget tree.', 'pressark' )
		);
	}

	// ── Rule: Elementor raw _elementor_data meta edit ────────────────

	/**
	 * update_meta targeting _elementor_data → block.
	 *
	 * Raw writes to _elementor_data bypass Elementor's internal consistency
	 * checks and can corrupt the page.
	 */
	public static function rule_elementor_raw_meta_edit( string $tool, array $params, array $context ): ?array {
		if ( 'update_meta' !== $tool ) {
			return null;
		}

		$keys = self::extract_meta_keys( $params );
		$dangerous_keys = array( '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode' );

		foreach ( $keys as $key ) {
			if ( in_array( $key, $dangerous_keys, true ) ) {
				return self::block(
					sprintf(
						/* translators: %s: Elementor meta key. */
						__( 'Cannot write raw "%s" meta — this would bypass Elementor\'s internal consistency checks and may corrupt the page.', 'pressark' ),
						$key
					),
					__( 'Use elementor_edit_widget, elementor_add_widget, or elementor_add_container to modify Elementor page structure safely.', 'pressark' )
				);
			}
		}

		return null;
	}

	// ── Rule: WooCommerce product content edit ───────────────────────

	/**
	 * edit_content on a WooCommerce product → reroute to edit_product.
	 *
	 * wp_update_post() on products bypasses WooCommerce hooks
	 * (woocommerce_update_product) breaking price lookups, stock caches, etc.
	 */
	public static function rule_wc_product_content_edit( string $tool, array $params, array $context ): ?array {
		if ( 'edit_content' !== $tool ) {
			return null;
		}

		$post_id = $context['post_id'];
		if ( ! $post_id ) {
			return null;
		}

		$post_type = get_post_type( $post_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return null;
		}

		$target_tool = 'product_variation' === $post_type ? 'edit_variation' : 'edit_product';

		// Rewrite params: map common edit_content fields to edit_product fields.
		$new_params = array( 'product_id' => $post_id );
		$changes    = $params['changes'] ?? $params;

		$field_map = array(
			'title'   => 'name',
			'content' => 'description',
			'excerpt' => 'short_description',
		);

		foreach ( $field_map as $content_key => $product_key ) {
			if ( isset( $changes[ $content_key ] ) ) {
				$new_params[ $product_key ] = $changes[ $content_key ];
			}
		}

		// Pass through any other fields that edit_product supports directly.
		$passthrough = array( 'regular_price', 'sale_price', 'sku', 'stock_quantity', 'stock_status', 'manage_stock', 'weight', 'status' );
		foreach ( $passthrough as $key ) {
			if ( isset( $changes[ $key ] ) ) {
				$new_params[ $key ] = $changes[ $key ];
			}
		}

		return self::reroute(
			$target_tool,
			$new_params,
			sprintf(
				/* translators: %s: WooCommerce post type slug. */
				__( 'This is a WooCommerce %s. Using edit_content would bypass WooCommerce hooks, breaking price lookups and stock caches.', 'pressark' ),
				$post_type
			),
			sprintf(
				/* translators: %s: Safe WooCommerce tool name. */
				__( 'Rerouted to %s which maintains all WooCommerce state sync.', 'pressark' ),
				$target_tool
			)
		);
	}

	// ── Rule: WooCommerce guarded meta keys ──────────────────────────

	/**
	 * update_meta with WC-guarded keys on products → reroute to edit_product.
	 *
	 * Raw update_post_meta on these keys bypasses WC's lookup table,
	 * transient busting, stock hooks, and price sync.
	 */
	public static function rule_wc_guarded_meta( string $tool, array $params, array $context ): ?array {
		if ( 'update_meta' !== $tool ) {
			return null;
		}

		$post_id = $context['post_id'];
		if ( ! $post_id ) {
			return null;
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return null;
		}

		$wc_guarded_keys = array(
			'_price', '_regular_price', '_sale_price',
			'_sale_price_dates_from', '_sale_price_dates_to',
			'_sku', '_stock', '_stock_status', '_manage_stock',
			'_backorders', '_low_stock_amount',
			'_weight', '_length', '_width', '_height',
			'_virtual', '_downloadable', '_featured',
			'_tax_status', '_tax_class', '_shipping_class_id',
			'total_sales',
		);

		$keys = self::extract_meta_keys( $params );

		foreach ( $keys as $key ) {
			if ( in_array( $key, $wc_guarded_keys, true ) ) {
				if ( '_price' === $key ) {
					return self::block(
						__( 'Cannot update raw "_price" on a WooCommerce product or variation — it is the active/computed price, so remapping it would be ambiguous.', 'pressark' ),
						__( 'Use edit_product or edit_variation with one explicit field instead: regular_price for the base price, sale_price for a sale amount, or clear_sale=true to remove a sale. clear_sale is the canonical sale-removal path.', 'pressark' )
					);
				}

				$target_tool = 'product_variation' === $post_type ? 'edit_variation' : 'edit_product';

				// Build rewritten params mapping meta keys to edit_product fields.
				$new_params = array( 'product_id' => $post_id );
				$meta_to_product = array(
					'_regular_price' => 'regular_price',
					'_sale_price'    => 'sale_price',
					'_price'         => 'regular_price',
					'_sku'           => 'sku',
					'_stock'         => 'stock_quantity',
					'_stock_status'  => 'stock_status',
					'_manage_stock'  => 'manage_stock',
					'_weight'        => 'weight',
					'_length'        => 'length',
					'_width'         => 'width',
					'_height'        => 'height',
				);

				// Map the values being written.
				$bulk = $params['changes'] ?? ( $params['meta'] ?? array() );
				if ( is_array( $bulk ) ) {
					foreach ( $bulk as $mk => $mv ) {
						$normalized = self::normalize_meta_key( $mk );
						if ( isset( $meta_to_product[ $normalized ] ) ) {
							$new_params[ $meta_to_product[ $normalized ] ] = $mv;
						}
					}
				}

				// Single-key form.
				$single_key = $params['meta_key'] ?? ( $params['key'] ?? '' );
				if ( ! empty( $single_key ) ) {
					$normalized = self::normalize_meta_key( $single_key );
					if ( isset( $meta_to_product[ $normalized ] ) && isset( $params['value'] ) ) {
						$new_params[ $meta_to_product[ $normalized ] ] = $params['value'];
					}
				}

				return self::reroute(
					$target_tool,
					$new_params,
					sprintf(
						/* translators: %s: WooCommerce product meta key. */
						__( 'Cannot update "%s" via raw meta on a WooCommerce product — it bypasses WC\'s price lookup tables, stock caches, and inventory hooks.', 'pressark' ),
						$key
					),
					sprintf(
						/* translators: %s: Safe WooCommerce tool name. */
						__( 'Rerouted to %s which keeps all WooCommerce state in sync.', 'pressark' ),
						$target_tool
					)
				);
			}
		}

		return null;
	}

	// ── Rule: FSE template tools on classic themes ───────────────────

	/**
	 * FSE-only tools on classic themes → block with hint.
	 * Classic-only tools on block themes → block with hint.
	 */
	public static function rule_fse_template_mismatch( string $tool, array $params, array $context ): ?array {
		$fse_only_tools = array(
			'get_templates', 'edit_template', 'get_design_system', 'edit_design_tokens',
		);
		$classic_only_tools = array(
			'get_customizer_schema', 'update_customizer',
		);

		$is_block_theme = $context['is_block_theme'];

		if ( in_array( $tool, $fse_only_tools, true ) && ! $is_block_theme ) {
			$hint_tool = 'get_customizer_schema';
			if ( in_array( $tool, array( 'edit_design_tokens', 'get_design_system' ), true ) ) {
				$hint_tool = 'get_customizer_schema';
			} else {
				$hint_tool = 'get_theme_settings';
			}

			return self::block(
				sprintf(
					/* translators: %s: Tool name. */
					__( '"%s" requires a block (FSE) theme but this site uses a classic theme.', 'pressark' ),
					$tool
				),
				sprintf(
					/* translators: %s: Recommended tool name for classic themes. */
					__( 'Use %s instead for classic theme design settings.', 'pressark' ),
					$hint_tool
				)
			);
		}

		if ( in_array( $tool, $classic_only_tools, true ) && $is_block_theme ) {
			return self::block(
				sprintf(
					/* translators: %s: Tool name. */
					__( '"%s" is for classic themes but this site uses a block (FSE) theme.', 'pressark' ),
					$tool
				),
				__( 'Use get_design_system to see design tokens, or get_templates for template editing.', 'pressark' )
			);
		}

		return null;
	}

	// ── Rule: Customizer on block themes ─────────────────────────────

	/**
	 * get_customizer_schema on a block theme → reroute to get_theme_settings.
	 */
	public static function rule_fse_customizer_on_block_theme( string $tool, array $params, array $context ): ?array {
		if ( 'get_theme_settings' !== $tool ) {
			// This rule is only for when the handler-level block theme detection
			// should happen centrally. It overlaps with rule_fse_template_mismatch
			// for the customizer tools, but also catches get_theme_settings itself
			// which the system handler already handles correctly — so no-op here.
			return null;
		}

		return null; // get_theme_settings already routes correctly in the handler.
	}

	// ── Context builder ──────────────────────────────────────────────

	/**
	 * Build a lightweight site-context snapshot for rule evaluation.
	 *
	 * Avoids expensive lookups unless actually needed — each value is
	 * computed lazily the first time a rule accesses the context array.
	 * (For simplicity in v1, we compute them eagerly but cheaply.)
	 */
	private static function build_context( string $tool, array $params ): array {
		$post_id = self::resolve_post_id( $params );

		return array(
			'post_id'        => $post_id,
			'post_type'      => $post_id ? get_post_type( $post_id ) : null,
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'has_elementor'  => class_exists( '\\Elementor\\Plugin' ),
			'has_woocommerce' => class_exists( 'WooCommerce' ),
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Resolve a post_id from tool params using common parameter names.
	 */
	private static function resolve_post_id( array $params ): ?int {
		// Direct post_id.
		if ( ! empty( $params['post_id'] ) && is_numeric( $params['post_id'] ) ) {
			return absint( $params['post_id'] );
		}
		// product_id (WooCommerce tools).
		if ( ! empty( $params['product_id'] ) && is_numeric( $params['product_id'] ) ) {
			return absint( $params['product_id'] );
		}
		// page_id (common alias).
		if ( ! empty( $params['page_id'] ) && is_numeric( $params['page_id'] ) ) {
			return absint( $params['page_id'] );
		}
		return null;
	}

	/**
	 * Extract all meta keys being written from tool params.
	 *
	 * Handles both bulk (changes/meta array) and single-key forms.
	 */
	private static function extract_meta_keys( array $params ): array {
		$keys = array();

		// Bulk form: changes or meta array.
		$bulk = $params['changes'] ?? ( $params['meta'] ?? null );
		if ( is_array( $bulk ) ) {
			foreach ( $bulk as $k => $v ) {
				if ( 'post_id' !== $k ) {
					$keys[] = self::normalize_meta_key( sanitize_text_field( $k ) );
				}
			}
		}

		// Single-key form.
		$single_key = $params['meta_key'] ?? ( $params['key'] ?? '' );
		if ( ! empty( $single_key ) ) {
			$keys[] = self::normalize_meta_key( sanitize_text_field( $single_key ) );
		}

		return $keys;
	}

	/**
	 * Normalize a meta key — ensure leading underscore for known WC keys.
	 */
	private static function normalize_meta_key( string $key ): string {
		// Common AI mistake: omitting the leading underscore on WC meta keys.
		$known_wc_keys = array(
			'price', 'regular_price', 'sale_price',
			'sale_price_dates_from', 'sale_price_dates_to',
			'sku', 'stock', 'stock_status', 'manage_stock',
			'backorders', 'low_stock_amount',
			'weight', 'length', 'width', 'height',
			'virtual', 'downloadable', 'featured',
			'tax_status', 'tax_class', 'shipping_class_id',
		);

		if ( in_array( $key, $known_wc_keys, true ) ) {
			return '_' . $key;
		}

		return $key;
	}
}
