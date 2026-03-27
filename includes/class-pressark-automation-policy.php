<?php
/**
 * PressArk Automation Policy — Trust envelope for unattended execution.
 *
 * Defines which operations can be auto-approved in unattended runs
 * and which must be blocked with a policy violation.
 *
 * This is NOT a global auto-approve. It's a typed policy that separates
 * safe editorial/content operations from dangerous infrastructure mutations.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Automation_Policy {

	/**
	 * Policy levels — each allows progressively more operations.
	 *
	 * editorial: content + SEO + generation + media alt text
	 * merchandising: editorial + safe WooCommerce product edits
	 * full: all operations (only for enterprise, still blocks destructive)
	 */
	public const POLICY_EDITORIAL      = 'editorial';
	public const POLICY_MERCHANDISING  = 'merchandising';
	public const POLICY_FULL           = 'full';

	/**
	 * Groups that are ALWAYS safe for unattended auto-approval.
	 * These are read-only or produce content that can be reviewed.
	 */
	private const ALWAYS_SAFE_GROUPS = array(
		'discovery',
		'core',        // read_content, search_content, list_posts are reads
		'seo',
		'generation',
		'index',
		'profile',
		'health',
	);

	/**
	 * Specific operations that are safe in editorial policy.
	 * These are write operations within safe groups.
	 */
	private const EDITORIAL_SAFE_OPS = array(
		// Content writes — the core automation use case.
		'edit_content',
		'create_post',
		'update_meta',
		// SEO writes.
		'fix_seo',
		// Media safe writes.
		'update_media',     // alt text, title, caption
		// Taxonomy safe writes.
		'assign_terms',
		'manage_taxonomy',
		// Block editing.
		'edit_block',
		'insert_block',
		// Template editing.
		'edit_template',
		// Pattern insertion.
		'insert_pattern',
	);

	/**
	 * Additional operations safe in merchandising policy.
	 */
	private const MERCHANDISING_SAFE_OPS = array(
		'edit_product',
		'create_product',
		'edit_variation',
		'create_variation',
		'bulk_edit_products',
		'moderate_review',
	);

	/**
	 * Operations that are NEVER auto-approved regardless of policy.
	 * These can cause infrastructure damage or financial harm.
	 */
	private const NEVER_AUTO_APPROVE = array(
		// Plugin/theme infrastructure.
		'toggle_plugin',
		'switch_theme',
		// System settings.
		'update_site_settings',
		'update_theme_setting',
		// User management.
		'update_user',
		// Database destructive.
		'cleanup_database',
		'optimize_database',
		// Email sending.
		'email_customer',
		'trigger_wc_email',
		// Financial operations.
		'create_refund',
		'create_order',
		'update_order',
		'manage_coupon',
		// Webhook mutations.
		'manage_webhooks',
		// Security.
		'fix_security',
		// Bulk destructive.
		'delete_content',
		'delete_media',
		'bulk_edit',       // bulk status changes
		'find_and_replace',
		// Content erasure.
		'clear_log',
		// Index rebuild (expensive).
		'rebuild_index',
		// Cron manipulation.
		'manage_scheduled_task',
		// Site profile regen (expensive).
		'refresh_site_profile',
		// Elementor destructive.
		'elementor_clone_page',
		'elementor_global_styles',
	);

	/**
	 * Check whether an operation can be auto-approved under the given policy.
	 *
	 * @param string $operation_name Tool/operation name.
	 * @param string $policy         Policy level.
	 * @param array  $args           Operation arguments (for dynamic capability checks).
	 * @return array { allowed: bool, reason?: string }
	 */
	public static function check( string $operation_name, string $policy, array $args = array() ): array {
		// Reads are always allowed — but only if the operation is actually
		// registered. Unknown/unregistered operations must not be assumed safe.
		$is_registered = PressArk_Operation_Registry::exists( $operation_name );
		$capability    = null;
		if ( $is_registered ) {
			$capability = PressArk_Operation_Registry::classify( $operation_name, $args );
			if ( 'read' === $capability ) {
				return array( 'allowed' => true );
			}
		}

		// Unregistered operations are never auto-approved regardless of policy.
		if ( ! $is_registered ) {
			return array(
				'allowed' => false,
				'reason'  => sprintf(
					'Operation "%s" is not registered and cannot be auto-approved in automation runs.',
					$operation_name
				),
			);
		}

		// Never-auto-approve list takes precedence.
		if ( in_array( $operation_name, self::NEVER_AUTO_APPROVE, true ) ) {
			return array(
				'allowed' => false,
				'reason'  => sprintf(
					'Operation "%s" is not allowed in unattended automation runs. This action requires human approval due to its potential impact.',
					$operation_name
				),
			);
		}

		// Check against policy level.
		switch ( $policy ) {
			case self::POLICY_EDITORIAL:
				if ( in_array( $operation_name, self::EDITORIAL_SAFE_OPS, true ) ) {
					return array( 'allowed' => true );
				}
				break;

			case self::POLICY_MERCHANDISING:
				if ( in_array( $operation_name, self::EDITORIAL_SAFE_OPS, true )
					|| in_array( $operation_name, self::MERCHANDISING_SAFE_OPS, true ) ) {
					return array( 'allowed' => true );
				}
				break;

			case self::POLICY_FULL:
				// Full policy allows all registered operations except NEVER_AUTO_APPROVE.
				return array( 'allowed' => true );
		}

		// Check if it's in an always-safe group (for reads that slipped through).
		$group = PressArk_Operation_Registry::get_group( $operation_name );
		if ( in_array( $group, self::ALWAYS_SAFE_GROUPS, true ) && 'read' === $capability ) {
			return array( 'allowed' => true );
		}

		return array(
			'allowed' => false,
			'reason'  => sprintf(
				'Operation "%s" is outside the "%s" automation policy. Upgrade the automation policy or perform this action manually.',
				$operation_name,
				$policy
			),
		);
	}

	/**
	 * Get the default policy for a tier.
	 */
	public static function default_for_tier( string $tier ): string {
		if ( in_array( $tier, array( 'enterprise', 'business' ), true ) ) {
			return self::POLICY_MERCHANDISING;
		}
		return self::POLICY_EDITORIAL;
	}

	/**
	 * Get all valid policy levels.
	 */
	public static function valid_policies(): array {
		return array( self::POLICY_EDITORIAL, self::POLICY_MERCHANDISING, self::POLICY_FULL );
	}
}
