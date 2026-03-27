<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Abstract base class for domain action handlers.
 *
 * Provides shared helpers for error/success formatting, capability checks,
 * post resolution, plugin-dependency guards, checkpointing, and logging.
 *
 * @since 2.7.0
 */

abstract class PressArk_Handler_Base {

	/**
	 * Action logger instance — shared across all handlers.
	 *
	 * @var PressArk_Action_Logger
	 */
	protected PressArk_Action_Logger $logger;

	/**
	 * v3.7.1: Optional async task context for business idempotency.
	 * When set, destructive operations can check/record receipts
	 * so retries skip already-committed mutations.
	 *
	 * @var string Current async task_id (empty for sync requests).
	 */
	protected string $async_task_id = '';

	/**
	 * @param PressArk_Action_Logger $logger Logger instance (injected by engine).
	 */
	public function __construct( PressArk_Action_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * v3.7.1: Attach async task context for business idempotency.
	 * Called by the task queue before dispatching to the agent/engine.
	 *
	 * @param string $task_id Current async task ID.
	 */
	public function set_async_context( string $task_id ): void {
		$this->async_task_id = $task_id;
	}

	/**
	 * v3.7.1: Check if a destructive operation was already committed
	 * on a previous attempt of the current async task.
	 * Returns false for sync requests (no task context).
	 *
	 * @param string $operation_key Unique operation identifier.
	 * @return bool True if receipt exists (skip this operation).
	 */
	protected function has_operation_receipt( string $operation_key ): bool {
		if ( empty( $this->async_task_id ) ) {
			return false; // Sync request — no receipts.
		}
		$store = new PressArk_Task_Store();
		return $store->has_receipt( $this->async_task_id, $operation_key );
	}

	/**
	 * v3.7.1: Record that a destructive operation succeeded within
	 * the current async task, so retries will skip it.
	 *
	 * @param string $operation_key Unique operation identifier.
	 * @param string $summary       Short description of what was committed.
	 */
	protected function record_operation_receipt( string $operation_key, string $summary = '' ): void {
		if ( empty( $this->async_task_id ) ) {
			return; // Sync request — no receipts needed.
		}
		$store = new PressArk_Task_Store();
		$store->record_receipt( $this->async_task_id, $operation_key, $summary );
	}

	// ── Result Helpers ──────────────────────────────────────────────────

	/**
	 * Build a standardized error response.
	 *
	 * @param string $message Human-readable error message.
	 * @return array{success: false, message: string}
	 */
	protected function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * Build a standardized success response.
	 *
	 * @param string $message Human-readable success message.
	 * @param array  $extra   Additional keys to merge into the response.
	 * @return array{success: true, message: string, ...}
	 */
	protected function success( string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'success' => true,
				'message' => $message,
			),
			$extra
		);
	}

	// ── Capability Helpers ──────────────────────────────────────────────

	/**
	 * Check a WordPress capability, returning an error array on failure.
	 *
	 * Supports both general capabilities ('manage_options') and
	 * object-level capabilities ('edit_post', $post_id).
	 *
	 * @param string   $cap WordPress capability name.
	 * @param int|null $id  Optional object ID for object-level checks.
	 * @return array|null null if the user has the capability, error array otherwise.
	 */
	protected function require_cap( string $cap, ?int $id = null ): ?array {
		$has = $id
			? current_user_can( $cap, $id )
			: current_user_can( $cap );

		if ( ! $has ) {
			return $this->error( __( 'You do not have permission to perform this action.', 'pressark' ) );
		}

		return null;
	}

	// ── Post Helpers ────────────────────────────────────────────────────

	/**
	 * Get a post by ID, or return an error array.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|array WP_Post on success, error array on failure.
	 */
	protected function get_post_or_fail( int $post_id ) {
		if ( ! $post_id ) {
			return $this->error( __( 'Invalid post ID.', 'pressark' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( __( 'Post not found.', 'pressark' ) );
		}

		return $post;
	}

	/**
	 * Resolve a post ID from params, supporting post_id, id, url, and slug.
	 *
	 * @param array $params Action parameters.
	 * @return int|array Post ID on success, error array on failure.
	 */
	protected function resolve_post_id( array $params ) {
		$post_id = absint( $params['post_id'] ?? $params['id'] ?? 0 );

		// Resolve from URL if provided.
		if ( empty( $post_id ) && ! empty( $params['url'] ) ) {
			$post_id = url_to_postid( esc_url_raw( $params['url'] ) );
			if ( ! $post_id ) {
				return $this->error( __( 'Could not resolve URL to a post ID. Try providing post_id directly.', 'pressark' ) );
			}
		}

		// Resolve from slug if provided.
		if ( empty( $post_id ) && ! empty( $params['slug'] ) ) {
			$post_type = sanitize_text_field( $params['post_type'] ?? 'page' );
			$found     = get_page_by_path(
				sanitize_text_field( $params['slug'] ),
				OBJECT,
				$post_type
			);
			if ( $found ) {
				$post_id = $found->ID;
			} else {
				return $this->error( sprintf( __( "No %s found with slug '%s'.", 'pressark' ), $post_type, $params['slug'] ) );
			}
		}

		if ( ! $post_id ) {
			return $this->error( __( 'Invalid post ID.', 'pressark' ) );
		}

		return $post_id;
	}

	// ── Plugin-Dependency Guards ────────────────────────────────────────

	/**
	 * Guard clause: require WooCommerce to be active.
	 *
	 * @return array|null null if WooCommerce is active, error array otherwise.
	 */
	protected function require_wc(): ?array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $this->error( __( 'WooCommerce is not active.', 'pressark' ) );
		}
		return null;
	}

	/**
	 * Guard clause: require Elementor to be active.
	 *
	 * @return array|null null if Elementor is active, error array otherwise.
	 */
	protected function require_elementor(): ?array {
		if ( ! PressArk_Elementor::is_active() ) {
			return $this->error( __( 'Elementor is not active.', 'pressark' ) );
		}
		return null;
	}

	// ── Preview Helpers ─────────────────────────────────────────────────

	/**
	 * Default preview for any action type that lacks a specific preview method.
	 *
	 * @param string $type   Action type name.
	 * @param array  $params Normalized action params.
	 * @return array Preview data (without 'type' key — caller adds that).
	 */
	public function default_preview( string $type, array $params ): array {
		return array(
			'changes' => array(
				array(
					'field'  => ucfirst( str_replace( '_', ' ', $type ?: 'Action' ) ),
					'before' => __( 'Current state', 'pressark' ),
					'after'  => __( 'Will be modified', 'pressark' ),
				),
			),
		);
	}

	/**
	 * Humanize a meta key for display in preview cards.
	 *
	 * @param string $key Raw meta key.
	 * @return string Human-readable label.
	 */
	protected function humanize_meta_key( string $key ): string {
		static $map = null;
		if ( $map === null ) {
			$map = array(
				'meta_title'                 => __( 'SEO Title', 'pressark' ),
				'meta_description'           => __( 'Meta Description', 'pressark' ),
				'og_title'                   => __( 'OG Title', 'pressark' ),
				'og_description'             => __( 'OG Description', 'pressark' ),
				'_pressark_meta_title'       => __( 'SEO Title', 'pressark' ),
				'_pressark_meta_description' => __( 'Meta Description', 'pressark' ),
				'_yoast_wpseo_title'         => __( 'SEO Title (Yoast)', 'pressark' ),
				'_yoast_wpseo_metadesc'      => __( 'Meta Description (Yoast)', 'pressark' ),
				'rank_math_title'            => __( 'SEO Title (RankMath)', 'pressark' ),
				'rank_math_description'      => __( 'Meta Description (RankMath)', 'pressark' ),
			);
		}
		return $map[ $key ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', ltrim( $key, '_' ) ) );
	}

	// ── Checkpoint ──────────────────────────────────────────────────────

	/**
	 * Create a PressArk revision checkpoint for a post.
	 *
	 * Stores a WordPress revision tagged with `_pressark_checkpoint` meta
	 * so it can be identified as a pre-action snapshot.
	 *
	 * @param int    $post_id Post ID to checkpoint.
	 * @param string $action  Action name that triggered the checkpoint.
	 * @return int Revision ID (0 if revision creation failed).
	 */
	protected function create_checkpoint( int $post_id, string $action = '' ): int {
		$rev_id = wp_save_post_revision( $post_id );

		if ( $rev_id && ! is_wp_error( $rev_id ) ) {
			update_metadata( 'post', $rev_id, '_pressark_checkpoint', true );
			if ( $action ) {
				update_metadata( 'post', $rev_id, '_pressark_action', $action );
			}
		}

		return (int) $rev_id;
	}

	// ── SEO Meta Key Resolution ─────────────────────────────────────────

	/**
	 * Resolve a semantic SEO meta key to the correct plugin-specific key.
	 *
	 * Delegates to PressArk_SEO_Resolver. Kept for backward compatibility.
	 *
	 * @param string $key Semantic or raw meta key.
	 * @return string Resolved meta key.
	 */
	protected function resolve_meta_key( string $key ): string {
		return PressArk_SEO_Resolver::resolve_key( $key );
	}

	/**
	 * Detect which SEO plugin is active (cached per request).
	 *
	 * Delegates to PressArk_SEO_Resolver. Kept for backward compatibility.
	 *
	 * @return string|null Plugin slug or null if none detected.
	 */
	public static function detect_seo_plugin(): ?string {
		return PressArk_SEO_Resolver::detect();
	}
}
