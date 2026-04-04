<?php
/**
 * PressArk Operation Registry — Single source of truth for operation semantics.
 *
 * v3.4.0: Replaces scattered tool metadata with one central contract layer.
 * v3.4.1: Becomes the authoritative source — all group membership, entitlements,
 *         discovery, and loading derive from this registry. TOOL_GROUPS is deprecated.
 * v5.3.0: Extended execution contract — richer metadata (search hints, interrupt
 *         behavior, cache policies, output policies, resumability, deferred loading,
 *         pre-permission validation, policy hooks). Old tuple format still works.
 *         New fields set via contract array in 9th tuple position or via filter.
 *
 * Adding a new tool:
 *   1. Add one line to boot() below.
 *   2. Add the handler method to the appropriate handler class.
 *   3. Add the tool definition (params) to class-pressark-tools.php.
 *   That's it — dispatch, preview, entitlements, discovery, and loading all derive
 *   from the registry automatically.
 *
 * @package PressArk
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Operation_Registry {

	/** @var array<string, PressArk_Operation> name → Operation */
	private static array $operations = array();

	/** @var array<string, string> alias → canonical name */
	private static array $aliases = array();

	/** @var bool Whether boot() has been called. */
	private static bool $booted = false;

	/** @var array<string, array{groups: string[], tools: string[]}> virtual group aliases */
	private static array $virtual_groups = array();

	/** @var array<string, string[]>|null Cached group → tool names index. */
	private static ?array $group_index = null;

	// ── Public API ──────────────────────────────────────────────────

	/**
	 * Look up an operation by exact name.
	 */
	public static function get( string $name ): ?PressArk_Operation {
		self::ensure_booted();
		return self::$operations[ $name ] ?? null;
	}

	/**
	 * Look up an operation by name or alias.
	 */
	public static function resolve( string $name ): ?PressArk_Operation {
		self::ensure_booted();
		$canonical = self::$aliases[ $name ] ?? $name;
		return self::$operations[ $canonical ] ?? null;
	}

	/**
	 * Classify a tool's capability, handling dynamic overrides.
	 *
	 * @param string $name Tool name (or alias).
	 * @param array  $args Tool arguments (for dynamic tools).
	 * @return string 'read' | 'preview' | 'confirm'
	 */
	public static function classify( string $name, array $args = array() ): string {
		// Dynamic capability: call_rest_endpoint routes on HTTP method.
		if ( 'call_rest_endpoint' === $name ) {
			$http = strtoupper( $args['method'] ?? 'GET' );
			return 'GET' === $http ? 'read' : 'confirm';
		}

		// Dynamic capability: manage_webhooks routes on action.
		if ( 'manage_webhooks' === $name ) {
			$action = $args['action'] ?? 'list';
			return 'list' === $action ? 'read' : 'confirm';
		}

		$op = self::resolve( $name );
		return $op ? $op->capability : 'read';
	}

	/**
	 * Get the handler key and method for dispatching an operation.
	 *
	 * @return array{handler: string, method: string}|null
	 */
	public static function get_dispatch( string $name ): ?array {
		$op = self::resolve( $name );
		if ( ! $op ) {
			return null;
		}
		return array(
			'handler' => $op->handler,
			'method'  => $op->method,
		);
	}

	/**
	 * Get the preview strategy for a tool.
	 */
	public static function get_preview_strategy( string $name ): string {
		$op = self::resolve( $name );
		return $op ? $op->preview_strategy : 'none';
	}

	/**
	 * Get the group a tool belongs to.
	 */
	public static function get_group( string $name ): string {
		$op = self::resolve( $name );
		return $op ? $op->group : '';
	}

	/**
	 * Check if a read tool is safe for batched execution.
	 *
	 * Returns true when the tool can execute in a group of reads without
	 * depending on the results of other tools in the same group and without
	 * mutating shared state (tool_set, tool_defs, checkpoint dependencies).
	 *
	 * Dynamic tools (call_rest_endpoint GET, manage_webhooks list) are
	 * treated as concurrency-safe since the read path has no side effects.
	 *
	 * @since 5.2.0
	 */
	public static function is_concurrency_safe( string $name, array $args = array() ): bool {
		// Dynamic tools: only the read variant is safe.
		if ( 'call_rest_endpoint' === $name ) {
			return 'GET' === strtoupper( $args['method'] ?? 'GET' );
		}
		if ( 'manage_webhooks' === $name ) {
			return 'list' === ( $args['action'] ?? 'list' );
		}

		$op = self::resolve( $name );
		if ( ! $op ) {
			// Unknown tool — fail closed (serial).
			return false;
		}

		return $op->is_concurrency_safe();
	}

	/**
	 * Check if a tool requires a specific plugin.
	 */
	public static function get_requires( string $name ): ?string {
		$op = self::resolve( $name );
		return $op ? $op->requires : null;
	}

	/**
	 * Get all registered operations.
	 *
	 * @return PressArk_Operation[]
	 */
	public static function all(): array {
		self::ensure_booted();
		return self::$operations;
	}

	/**
	 * Get all operations in a specific group.
	 *
	 * @return PressArk_Operation[]
	 */
	public static function by_group( string $group ): array {
		self::ensure_booted();
		$result = array();
		foreach ( self::$operations as $op ) {
			if ( $op->group === $group ) {
				$result[ $op->name ] = $op;
			}
		}
		return $result;
	}

	/**
	 * Get all group names that have registered operations.
	 *
	 * @return string[]
	 */
	public static function groups(): array {
		self::ensure_booted();
		$groups = array();
		foreach ( self::$operations as $op ) {
			$groups[ $op->group ] = true;
		}
		return array_keys( $groups );
	}

	// ── v3.4.1 Group API (replaces TOOL_GROUPS) ────────────────────

	/**
	 * Get all valid group names including virtual aliases.
	 * Replaces array_keys( PressArk_Tools::TOOL_GROUPS ).
	 *
	 * @since 3.4.1
	 * @return string[]
	 */
	public static function group_names(): array {
		self::ensure_booted();
		self::build_group_index();
		$names = array_keys( self::$group_index );
		foreach ( array_keys( self::$virtual_groups ) as $vg ) {
			if ( ! in_array( $vg, $names, true ) ) {
				$names[] = $vg;
			}
		}
		return $names;
	}

	/**
	 * Get tool names belonging to a group (or virtual group alias).
	 * Replaces PressArk_Tools::TOOL_GROUPS[ $group ].
	 *
	 * @since 3.4.1
	 * @return string[]
	 */
	public static function tool_names_for_group( string $group ): array {
		self::ensure_booted();

		// Virtual group alias?
		if ( isset( self::$virtual_groups[ $group ] ) ) {
			return self::resolve_virtual_group( $group );
		}

		self::build_group_index();
		return self::$group_index[ $group ] ?? array();
	}

	/**
	 * Check if a group name is valid (real or virtual).
	 * Replaces isset( PressArk_Tools::TOOL_GROUPS[ $group ] ).
	 *
	 * @since 3.4.1
	 */
	public static function is_valid_group( string $group ): bool {
		if ( isset( self::$virtual_groups[ $group ] ) ) {
			return true;
		}
		self::ensure_booted();
		self::build_group_index();
		return isset( self::$group_index[ $group ] );
	}

	/**
	 * Check if a tool is a meta-tool (discover/load).
	 *
	 * @since 3.4.1
	 */
	public static function is_meta_tool( string $name ): bool {
		$op = self::resolve( $name );
		return $op ? $op->is_meta() : false;
	}

	/**
	 * Get names of all meta-tools.
	 *
	 * @since 3.4.1
	 * @return string[]
	 */
	public static function meta_tool_names(): array {
		self::ensure_booted();
		$names = array();
		foreach ( self::$operations as $op ) {
			if ( $op->is_meta() ) {
				$names[] = $op->name;
			}
		}
		return $names;
	}

	// ── Alias API ──────────────────────────────────────────────────

	/**
	 * Resolve an alias to the canonical tool name.
	 * Returns the input name if it's not an alias.
	 */
	public static function resolve_alias( string $name ): string {
		self::ensure_booted();
		return self::$aliases[ $name ] ?? $name;
	}

	/**
	 * Get all registered aliases.
	 *
	 * @return array<string, string> alias → canonical name
	 */
	public static function get_aliases(): array {
		self::ensure_booted();
		return self::$aliases;
	}

	/**
	 * Check whether a name (or alias) is registered.
	 */
	public static function exists( string $name ): bool {
		self::ensure_booted();
		$canonical = self::$aliases[ $name ] ?? $name;
		return isset( self::$operations[ $canonical ] );
	}

	// ── Execution Contract API (v5.3.0) ────────────────────────────

	/**
	 * Get the full execution contract for a tool.
	 *
	 * Returns an associative array with all operation semantics — both
	 * legacy and extended. Passes through the 'pressark_operation_contract'
	 * filter so third parties can augment metadata.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return array|null Execution contract or null if not found.
	 */
	public static function get_contract( string $name ): ?array {
		$op = self::resolve( $name );
		if ( ! $op ) {
			return null;
		}
		return $op->execution_contract();
	}

	/**
	 * Run pre-permission validation for a tool.
	 *
	 * If the operation has a validate callable, it runs BEFORE the
	 * capability/approval flow. Returns early with a failure result
	 * if validation fails — preventing unnecessary permission prompts.
	 *
	 * @since 5.3.0
	 * @param string $name   Tool name (or alias).
	 * @param array  $params Tool parameters from the AI.
	 * @return array{valid: bool, message?: string}
	 */
	public static function validate_input( string $name, array $params ): array {
		$op = self::resolve( $name );
		if ( ! $op ) {
			return array( 'valid' => true );
		}
		return $op->validate_input( $params );
	}

	/**
	 * Get the cache TTL hint for a tool.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return int Cache TTL in seconds (0 = no caching).
	 */
	public static function get_cache_ttl( string $name ): int {
		$op = self::resolve( $name );
		return $op ? $op->cache_ttl : 0;
	}

	/**
	 * Get the interrupt behavior for a tool.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return string 'cancel'|'block'
	 */
	public static function get_interrupt( string $name ): string {
		$op = self::resolve( $name );
		return $op ? $op->interrupt : 'block';
	}

	/**
	 * Get the output size policy for a tool.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return string 'compact'|'standard'|'large'
	 */
	public static function get_output_policy( string $name ): string {
		$op = self::resolve( $name );
		return $op ? $op->output_policy : 'standard';
	}

	/**
	 * Check if a tool is resumable after interruption.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return bool
	 */
	public static function is_resumable( string $name ): bool {
		$op = self::resolve( $name );
		return $op ? $op->resumable : false;
	}

	/**
	 * Get the search hint for a tool.
	 *
	 * @since 5.3.0
	 * @param string $name Tool name (or alias).
	 * @return string Search keywords (empty string = none).
	 */
	public static function get_search_hint( string $name ): string {
		$op = self::resolve( $name );
		return $op ? $op->search_hint : '';
	}

	/**
	 * Get policy hooks for a specific execution phase.
	 *
	 * @since 5.3.0
	 * @param string $name  Tool name (or alias).
	 * @param string $phase Hook phase: 'pre_execute', 'post_execute', 'pre_approve'.
	 * @return string[] Filter names to fire.
	 */
	public static function get_policy_hooks( string $name, string $phase ): array {
		$op = self::resolve( $name );
		if ( ! $op || ! isset( $op->policy_hooks[ $phase ] ) ) {
			return array();
		}
		$hooks = $op->policy_hooks[ $phase ];
		return is_array( $hooks ) ? $hooks : array( $hooks );
	}

	/**
	 * Apply a contract overlay to an already-registered operation.
	 *
	 * Useful for third-party plugins that want to enrich metadata
	 * for existing tools without re-registering them.
	 *
	 * @since 5.3.0
	 * @param string $name     Tool name (or alias).
	 * @param array  $contract Contract fields to overlay.
	 * @return bool True if the operation was found and updated.
	 */
	public static function apply_contract( string $name, array $contract ): bool {
		$canonical = self::$aliases[ $name ] ?? $name;
		if ( ! isset( self::$operations[ $canonical ] ) ) {
			return false;
		}
		self::$operations[ $canonical ]->apply_contract( $contract );
		return true;
	}

	/**
	 * Get all operations matching a set of tags.
	 *
	 * @since 5.3.0
	 * @param string[] $tags Tags to match (OR logic).
	 * @return PressArk_Operation[]
	 */
	public static function by_tags( array $tags ): array {
		self::ensure_booted();
		$result = array();
		foreach ( self::$operations as $op ) {
			if ( array_intersect( $tags, $op->tags ) ) {
				$result[ $op->name ] = $op;
			}
		}
		return $result;
	}

	// ── Registration ────────────────────────────────────────────────

	/**
	 * Register a single operation.
	 */
	public static function register( PressArk_Operation $op ): void {
		self::$operations[ $op->name ] = $op;
		self::$group_index = null; // Invalidate cache.
	}

	/**
	 * Register an alias (backward-compat tool name → canonical name).
	 */
	public static function alias( string $alias, string $canonical ): void {
		self::$aliases[ $alias ] = $canonical;
	}

	/**
	 * Register a virtual group alias (cross-group superset).
	 *
	 * @since 3.4.1
	 * @param string   $alias         Virtual group name.
	 * @param string[] $source_groups Real groups to merge.
	 * @param string[] $extra_tools   Additional tool names from other groups.
	 */
	public static function register_virtual_group( string $alias, array $source_groups, array $extra_tools = array() ): void {
		self::$virtual_groups[ $alias ] = array(
			'groups' => $source_groups,
			'tools'  => $extra_tools,
		);
	}

	/**
	 * Reset the registry (for testing only).
	 */
	public static function reset(): void {
		self::$operations    = array();
		self::$aliases       = array();
		self::$virtual_groups = array();
		self::$group_index   = null;
		self::$booted        = false;
	}

	// ── Boot ────────────────────────────────────────────────────────

	private static function ensure_booted(): void {
		if ( ! self::$booted ) {
			self::boot();
		}
	}

	/**
	 * Build the group → tool names index on demand.
	 */
	private static function build_group_index(): void {
		if ( null !== self::$group_index ) {
			return;
		}
		self::$group_index = array();
		foreach ( self::$operations as $op ) {
			self::$group_index[ $op->group ][] = $op->name;
		}
	}

	/**
	 * Resolve a virtual group alias to its tool names.
	 *
	 * @return string[]
	 */
	private static function resolve_virtual_group( string $alias ): array {
		$spec = self::$virtual_groups[ $alias ] ?? null;
		if ( ! $spec ) {
			return array();
		}
		self::build_group_index();
		$names = array();
		foreach ( $spec['groups'] as $g ) {
			if ( isset( self::$group_index[ $g ] ) ) {
				$names = array_merge( $names, self::$group_index[ $g ] );
			}
		}
		foreach ( $spec['tools'] as $t ) {
			if ( isset( self::$operations[ $t ] ) ) {
				$names[] = $t;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Auto-derive risk from capability and name.
	 */
	private static function derive_risk( string $name, string $capability ): string {
		if ( 'read' === $capability ) {
			return 'safe';
		}
		if ( 'confirm' === $capability ) {
			foreach ( array( 'delete_', 'cleanup_', 'clear_' ) as $pat ) {
				if ( str_starts_with( $name, $pat ) ) {
					return 'destructive';
				}
			}
			if ( 'fix_security' === $name ) {
				return 'destructive';
			}
		}
		return 'moderate';
	}

	/**
	 * Register all operations. Called once on first access.
	 *
	 * Extended tuple format (v5.2.0):
	 *   [ name, group, capability, handler, method, preview_strategy?, requires?, description?, concurrency_safe? ]
	 *
	 * preview_strategy defaults to 'none'.
	 * requires defaults to null.
	 * description defaults to ''.
	 * concurrency_safe defaults to true (only meaningful for reads).
	 * label auto-generated from name.
	 * risk auto-derived from capability + name.
	 */
	public static function boot(): void {
		self::$operations     = array();
		self::$aliases        = array();
		self::$virtual_groups = array();
		self::$group_index    = null;
		self::$booted         = true;

		// ── Discovery ───────────────────────────────────────────
		self::register_all( array(
			array( 'get_site_overview',   'discovery', 'read', 'discovery', 'get_site_overview',   'none', null, 'Compact site overview: name, URL, WP version, theme, content counts, plugins' ),
			array( 'get_site_map',        'discovery', 'read', 'discovery', 'get_site_map',        'none', null, 'Full site structure: pages, posts, homepage config, blog page' ),
			array( 'get_brand_profile',   'discovery', 'read', 'discovery', 'get_brand_profile',   'none', null, 'AI-generated site profile: identity, voice, content DNA, audience' ),
			array( 'get_available_tools', 'discovery', 'read', 'discovery', 'get_available_tools', 'none', null, 'List all available tools beyond those currently loaded' ),
			array( 'site_note',           'discovery', 'read', 'discovery', 'site_note',           'none', null, 'Record a site observation for future conversations. Use when discovering patterns, preferences, or issues.' ),
		) );

		// ── Meta-tools (NOT concurrency-safe: mutate tool_set/tool_defs) ──
		self::register_all( array(
			array( 'load_tool_group', 'discovery', 'read', 'discovery', 'load_tool_group',      'none', null, 'Load tool schemas by group name', false ),
			array( 'discover_tools',  'discovery', 'read', 'discovery', 'handle_discover_tools', 'none', null, 'Search for tools by natural-language query', false ),
			array( 'load_tools',      'discovery', 'read', 'discovery', 'handle_load_tools',     'none', null, 'Load tool schemas by group or specific tool names', false ),
		) );

		// ── Resource Bridge (v5.1.0) ────────────────────────────
		self::register_all( array(
			array( 'list_resources', 'discovery', 'read', 'discovery', 'list_resources', 'none', null, 'List available site resources (design tokens, templates, REST routes, schemas). Use read_resource to fetch.' ),
			array( 'read_resource',  'discovery', 'read', 'discovery', 'read_resource',  'none', null, 'Read a specific resource by URI. Returns cached site data (design system, templates, post type schema, etc.).' ),
		) );

		// ── Content — reads ─────────────────────────────────────
		self::register_all( array(
			array( 'read_content',        'core', 'read', 'content', 'read_content',        'none', null, 'Read post/page by ID, URL, or slug (light/structured/full mode)' ),
			array( 'search_content',      'core', 'read', 'content', 'search_content',      'none', null, 'Search posts/pages by keyword with date and meta filters' ),
			array( 'list_posts',          'core', 'read', 'content', 'list_posts',          'none', null, 'List posts/pages with filters for type, status, date, author' ),
			array( 'get_random_content',  'core', 'read', 'content', 'get_random_content',  'none', null, 'Pick one random post, page, or product; for product-led content, use it directly when rich enough, otherwise follow with get_product' ),
		) );

		// ── Content — writes ────────────────────────────────────
		self::register_all( array(
			array( 'edit_content',   'core', 'preview', 'content', 'edit_content',   'post_edit',   null, 'Edit post/page: title, content, excerpt, slug, status' ),
			array( 'update_meta',    'core', 'preview', 'content', 'update_meta',    'meta_update', null, 'Update post meta: SEO title, description, OG tags, custom fields' ),
			array( 'create_post',    'core', 'preview', 'content', 'create_post',    'new_post',    null, 'Create a new post or page with optional slug, excerpt, SEO, social metadata, and scheduling' ),
			array( 'delete_content', 'core', 'confirm', 'content', 'delete_content', 'none',        null, 'Move a post or page to trash' ),
		) );

		// ── SEO ─────────────────────────────────────────────────
		self::register_all( array(
			array( 'analyze_seo',        'seo', 'read',    'seo',         'analyze_seo',                'none',       null, 'Deep SEO analysis for a single page or full site scan' ),
			array( 'fix_seo',            'seo', 'preview', 'seo',         'fix_seo',                    'meta_update', null, 'Fix SEO meta titles and descriptions with preview' ),
			array( 'check_crawlability', 'seo', 'read',    'diagnostics', 'handle_check_crawlability',  'none',       null, 'Check robots.txt, visibility, SSL, and XML sitemap' ),
		) );

		// ── Security ────────────────────────────────────────────
		self::register_all( array(
			array( 'scan_security', 'security', 'read',    'seo', 'scan_security', 'none', null, 'Full site security audit' ),
			array( 'fix_security',  'security', 'confirm', 'seo', 'fix_security',  'none', null, 'Apply auto-fixable security fixes (exposed files, XML-RPC)' ),
		) );

		// ── Settings ────────────────────────────────────────────
		self::register_all( array(
			array( 'get_site_settings',    'settings', 'read',    'system',      'get_site_settings',              'none',          null, 'Read WordPress site settings: name, URL, timezone, permalinks, SMTP' ),
			array( 'update_site_settings', 'settings', 'preview', 'system',      'update_site_settings',           'option_update', null, 'Update WordPress site settings with undo support' ),
			array( 'check_email_delivery', 'settings', 'read',    'diagnostics', 'handle_check_email_delivery',    'none',          null, 'Check WordPress email delivery config and SMTP plugins' ),
		) );

		// ── Menus ───────────────────────────────────────────────
		self::register_all( array(
			array( 'get_menus',   'menus', 'read',    'system', 'get_menus',   'none', null, 'List navigation menus, items, and theme locations (classic + FSE)' ),
			array( 'update_menu', 'menus', 'confirm', 'system', 'update_menu', 'none', null, 'Create menu, add/remove items, or assign to locations' ),
		) );

		// ── Media ───────────────────────────────────────────────
		self::register_all( array(
			array( 'list_media',            'media', 'read',    'media', 'list_media',            'none', null, 'List media attachments with filters' ),
			array( 'get_media',             'media', 'read',    'media', 'get_media',             'none', null, 'Full media details: EXIF, type, URL, attached post' ),
			array( 'update_media',          'media', 'confirm', 'media', 'update_media',          'none', null, 'Update media: alt text, title, caption, featured image' ),
			array( 'delete_media',          'media', 'confirm', 'media', 'delete_media',          'none', null, 'Permanently delete a media attachment' ),
			array( 'regenerate_thumbnails', 'media', 'confirm', 'media', 'regenerate_thumbnails', 'none', null, 'Regenerate thumbnail sizes for images (max 20)' ),
		) );

		// ── Comments ────────────────────────────────────────────
		self::register_all( array(
			array( 'list_comments',     'comments', 'read',    'media', 'list_comments',     'none', null, 'List comments with filters and aggregate counts' ),
			array( 'moderate_comments', 'comments', 'confirm', 'media', 'moderate_comments', 'none', null, 'Approve, spam, trash, or untrash comments' ),
			array( 'reply_comment',     'comments', 'confirm', 'media', 'reply_comment',     'none', null, 'Reply to a comment as the admin user' ),
		) );

		// ── Taxonomy ────────────────────────────────────────────
		self::register_all( array(
			array( 'list_taxonomies', 'taxonomy', 'read',    'media', 'list_taxonomies', 'none', null, 'List all taxonomies and their terms' ),
			array( 'manage_taxonomy', 'taxonomy', 'confirm', 'media', 'manage_taxonomy', 'none', null, 'Create, edit, or delete taxonomy terms' ),
			array( 'assign_terms',    'taxonomy', 'confirm', 'media', 'assign_terms',    'none', null, 'Assign taxonomy terms to a post' ),
		) );

		// ── Email ───────────────────────────────────────────────
		self::register_all( array(
			array( 'get_email_log', 'email', 'read',    'system', 'get_email_log', 'none', null, 'Check recently sent PressArk emails (last 20)' ),
		) );

		// ── Users ───────────────────────────────────────────────
		self::register_all( array(
			array( 'list_users',  'users', 'read',    'system', 'list_users',  'none', null, 'List users with roles, email, and registration date' ),
			array( 'get_user',    'users', 'read',    'system', 'get_user',    'none', null, 'Detailed user info by ID, includes WooCommerce data' ),
			array( 'update_user', 'users', 'confirm', 'system', 'update_user', 'none', null, 'Update user profile: display name, role, bio' ),
		) );

		// ── Health & Diagnostics ────────────────────────────────
		self::register_all( array(
			array( 'site_health',                'health', 'read', 'system',      'site_health',                       'none', null, 'Site Health status: issues, recommendations, environment' ),
			array( 'inspect_hooks',              'health', 'read', 'diagnostics', 'handle_inspect_hooks',              'none', null, 'Inspect plugin/theme hooks on a WordPress action' ),
			array( 'measure_page_speed',         'health', 'read', 'diagnostics', 'handle_measure_page_speed',         'none', null, 'Measure page load time: TTFB, cache, script count' ),
			array( 'profile_queries',            'health', 'read', 'diagnostics', 'handle_profile_queries',            'none', null, 'Profile DB queries: slowest, duplicates, totals' ),
			array( 'get_revision_history',       'health', 'read', 'diagnostics', 'handle_get_revision_history',       'none', null, 'Post edit history with field-level diffs for undo/revert' ),
			array( 'discover_rest_routes',       'health', 'read', 'diagnostics', 'handle_discover_rest_routes',       'none', null, 'List all REST API endpoints grouped by plugin namespace' ),
			array( 'call_rest_endpoint',         'health', 'confirm', 'diagnostics', 'handle_call_rest_endpoint',         'none', null, 'Call any WordPress REST API endpoint internally' ),
			array( 'diagnose_cache',             'health', 'read', 'diagnostics', 'handle_diagnose_cache',             'none', null, 'Diagnose object cache: Redis/Memcached/APCu detection' ),
			array( 'analyze_comment_moderation', 'health', 'read', 'diagnostics', 'analyze_comment_moderation',        'none', null, 'Analyze why a comment is held for moderation' ),
			array( 'store_health',               'health', 'read', 'diagnostics', 'handle_store_health',               'none', null, 'WooCommerce store health: orders, revenue, stock, score' ),
			array( 'site_brief',                 'health', 'read', 'diagnostics', 'handle_site_brief',                 'none', null, 'Fast site overview: counts, activity, updates, speed note' ),
			array( 'page_audit',                 'health', 'read', 'diagnostics', 'handle_page_audit',                 'none', null, 'Comprehensive page audit: content + SEO + Elementor signals' ),
		) );

		// ── Scheduled Tasks ─────────────────────────────────────
		self::register_all( array(
			array( 'list_scheduled_tasks',  'scheduled', 'read',    'system', 'list_scheduled_tasks',  'none', null, 'List WP-Cron tasks with next run time and frequency' ),
			array( 'manage_scheduled_task', 'scheduled', 'confirm', 'system', 'manage_scheduled_task', 'none', null, 'Run, remove, or reschedule a cron task' ),
		) );

		// ── Content Generation ───────────────────────────────────
		self::register_all( array(
			array( 'generate_content',   'generation', 'read', 'content', 'generate_content',   'none', null, 'Generate content with AI: posts, descriptions, emails, social' ),
			array( 'rewrite_content',    'generation', 'read', 'content', 'rewrite_content',    'none', null, 'Rewrite or improve existing post/page content' ),
			array( 'generate_bulk_meta', 'generation', 'read', 'content', 'generate_bulk_meta', 'none', null, 'Generate SEO meta for multiple pages at once' ),
		) );

		// ── Bulk Operations ─────────────────────────────────────
		self::register_all( array(
			array( 'bulk_delete',       'bulk', 'confirm', 'content', 'bulk_delete',       'none', null, 'Move multiple posts/pages to trash at once' ),
			array( 'empty_trash',       'bulk', 'confirm', 'content', 'empty_trash',       'none', null, 'Permanently delete posts/pages from trash (irreversible)' ),
			array( 'bulk_delete_media', 'bulk', 'confirm', 'media',   'bulk_delete_media', 'none', null, 'Permanently delete multiple media attachments at once' ),
			array( 'bulk_edit',         'bulk', 'confirm', 'content', 'bulk_edit',         'none', null, 'Bulk status, category, or author changes across posts' ),
			array( 'find_and_replace',  'bulk', 'confirm', 'content', 'find_and_replace',  'none', null, 'Find and replace text across multiple posts/pages' ),
		) );

		// ── Export ───────────────────────────────────────────────
		self::register_all( array(
			array( 'export_report', 'export', 'read', 'content', 'export_report', 'none', null, 'Generate HTML report: SEO, security, site overview, WooCommerce' ),
		) );

		// ── Site Profile ────────────────────────────────────────
		self::register_all( array(
			array( 'view_site_profile',    'profile', 'read',    'system', 'view_site_profile',    'none', null, 'View auto-generated site profile: industry, style, tone' ),
			array( 'refresh_site_profile', 'profile', 'confirm', 'system', 'refresh_site_profile', 'none', null, 'Regenerate site profile by re-analyzing all content' ),
		) );

		// ── Logs ────────────────────────────────────────────────
		self::register_all( array(
			array( 'list_logs',    'logs', 'read',    'system', 'list_logs',        'none', null, 'List all log files: debug.log, PHP errors, WooCommerce, server' ),
			array( 'read_log',     'logs', 'read',    'system', 'read_log_action',  'none', null, 'Read recent log entries with keyword filtering' ),
			array( 'analyze_logs', 'logs', 'read',    'system', 'analyze_logs',     'none', null, 'Analyze log for actionable insights: error counts, sources' ),
			array( 'clear_log',    'logs', 'confirm', 'system', 'clear_log',        'none', null, 'Clear/truncate a log file (debug.log only)' ),
		) );

		// ── Content Index ───────────────────────────────────────
		self::register_all( array(
			array( 'search_knowledge', 'index', 'read',    'content', 'search_knowledge', 'none', null, 'Search indexed site content by keyword (up to 20 snippets)' ),
			array( 'index_status',     'index', 'read',    'content', 'index_status',     'none', null, 'Content index status: pages indexed, last sync, word count' ),
			array( 'rebuild_index',    'index', 'confirm', 'content', 'rebuild_index',    'none', null, 'Force full rebuild of the content index' ),
		) );

		// ── Plugins ─────────────────────────────────────────────
		self::register_all( array(
			array( 'list_plugins',  'plugins', 'read',    'system', 'list_plugins',  'none', null, 'List installed plugins with status, version, updates' ),
			array( 'toggle_plugin', 'plugins', 'confirm', 'system', 'toggle_plugin', 'none', null, 'Activate or deactivate a plugin' ),
		) );

		// ── Themes ──────────────────────────────────────────────
		self::register_all( array(
			array( 'list_themes',           'themes', 'read',    'system', 'list_themes',           'none',          null, 'List installed themes with active status and compatibility' ),
			array( 'get_theme_settings',    'themes', 'read',    'system', 'get_theme_settings',    'none',          null, 'Read current theme customizer settings and values' ),
			array( 'get_customizer_schema', 'themes', 'read',    'system', 'get_customizer_schema', 'none',          null, 'Discover all Customizer panels, sections, and controls' ),
			array( 'update_theme_setting',  'themes', 'preview', 'system', 'update_theme_setting',  'option_update', null, 'Update a theme customizer setting with preview' ),
			array( 'switch_theme',          'themes', 'confirm', 'system', 'switch_theme_action',   'none',          null, 'Switch the active theme with compatibility check' ),
		) );

		// ── Database ────────────────────────────────────────────
		self::register_all( array(
			array( 'database_stats',    'database', 'read',    'system', 'database_stats',    'none', null, 'Database statistics: sizes, row counts, large tables' ),
			array( 'cleanup_database',  'database', 'confirm', 'system', 'cleanup_database',  'none', null, 'Clean up revisions, auto-drafts, spam, expired transients' ),
			array( 'optimize_database', 'database', 'confirm', 'system', 'optimize_database', 'none', null, 'Optimize database tables to reclaim space' ),
		) );

		// ── Gutenberg Blocks ────────────────────────────────────
		self::register_all( array(
			array( 'read_blocks',  'blocks', 'read',    'diagnostics', 'handle_read_blocks',  'none',       null, 'Read Gutenberg block tree with indexes and issue flags' ),
			array( 'edit_block',   'blocks', 'preview', 'diagnostics', 'handle_edit_block',   'block_edit', null, 'Edit a Gutenberg block by index: content or attributes' ),
			array( 'insert_block', 'blocks', 'preview', 'diagnostics', 'handle_insert_block', 'block_edit', null, 'Insert a new Gutenberg block at a specific position' ),
		) );

		// ── Custom Fields ───────────────────────────────────────
		self::register_all( array(
			array( 'get_custom_fields',   'custom_fields', 'read',    'media', 'get_custom_fields',   'none', null, 'Get custom fields/ACF data for a post with types and values' ),
			array( 'update_custom_field', 'custom_fields', 'confirm', 'media', 'update_custom_field', 'none', null, 'Update a custom field value (ACF-aware)' ),
		) );

		// ── Forms ───────────────────────────────────────────────
		self::register_all( array(
			array( 'list_forms', 'forms', 'read', 'media', 'list_forms', 'none', null, 'List contact forms: CF7, WPForms, Gravity Forms, Fluent Forms' ),
		) );

		// ── Templates & FSE ─────────────────────────────────────
		self::register_all( array(
			array( 'get_templates', 'templates', 'read',    'diagnostics', 'get_templates', 'none',      null, 'Read FSE block templates and template parts (block themes)' ),
			array( 'edit_template', 'templates', 'preview', 'diagnostics', 'edit_template', 'post_edit', null, 'Edit a block within an FSE template with user override' ),
		) );

		// ── Design System ───────────────────────────────────────
		self::register_all( array(
			array( 'get_design_system', 'design', 'read', 'diagnostics', 'get_design_system', 'none', null, 'Typography, spacing, colors, borders, shadows design tokens' ),
		) );

		// ── Patterns ────────────────────────────────────────────
		self::register_all( array(
			array( 'list_patterns',  'patterns', 'read',    'diagnostics', 'list_patterns',  'none',      null, 'List registered block patterns with categories' ),
			array( 'insert_pattern', 'patterns', 'preview', 'diagnostics', 'insert_pattern', 'post_edit', null, 'Insert a block pattern into a post at a position' ),
		) );

		// ── Multisite ───────────────────────────────────────────
		self::register_all( array(
			array( 'network_overview', 'multisite', 'read', 'diagnostics', 'network_overview', 'none', null, 'Multisite network overview: all sites with stats' ),
		) );

		// ── WooCommerce — reads ─────────────────────────────────
		self::register_all( array(
			array( 'get_product',             'woocommerce', 'read', 'woo', 'get_product',             'none', 'woocommerce', 'Full product data including permalink, descriptions, price, stock, categories, attributes, images, and SKU' ),
			array( 'analyze_store',           'woocommerce', 'read', 'woo', 'analyze_store',           'none', 'woocommerce', 'WooCommerce health: missing descriptions, images, prices, stock' ),
			array( 'list_orders',             'woocommerce', 'read', 'woo', 'list_orders',             'none', 'woocommerce', 'List WooCommerce orders with filters' ),
			array( 'get_order',               'woocommerce', 'read', 'woo', 'get_order',               'none', 'woocommerce', 'Full details of a single WooCommerce order' ),
			array( 'list_customers',          'woocommerce', 'read', 'woo', 'list_customers',          'none', 'woocommerce', 'List customers with order history and total spent' ),
			array( 'get_customer',            'woocommerce', 'read', 'woo', 'get_customer',            'none', 'woocommerce', 'Full customer profile: contact, addresses, orders, spend' ),
			array( 'get_shipping_zones',      'woocommerce', 'read', 'woo', 'get_shipping_zones',      'none', 'woocommerce', 'Shipping zones with methods, costs, and free shipping rules' ),
			array( 'get_tax_settings',        'woocommerce', 'read', 'woo', 'get_tax_settings',        'none', 'woocommerce', 'Tax config: rates, calculation settings, display options' ),
			array( 'get_payment_gateways',    'woocommerce', 'read', 'woo', 'get_payment_gateways',    'none', 'woocommerce', 'Payment gateways with availability and test mode status' ),
			array( 'get_wc_settings',         'woocommerce', 'read', 'woo', 'get_wc_settings',         'none', 'woocommerce', 'Store settings: currency, address, inventory, checkout' ),
			array( 'get_wc_emails',           'woocommerce', 'read', 'woo', 'get_wc_emails',           'none', 'woocommerce', 'WooCommerce email notifications and enabled status' ),
			array( 'get_wc_status',           'woocommerce', 'read', 'woo', 'get_wc_status',           'none', 'woocommerce', 'Full WC system status: environment, DB, plugins, HPOS' ),
			array( 'list_reviews',            'woocommerce', 'read', 'woo', 'list_reviews',            'none', 'woocommerce', 'Product reviews filtered by rating, status, or product' ),
			array( 'inventory_report',        'woocommerce', 'read', 'woo', 'inventory_report',        'none', 'woocommerce', 'Inventory status: low stock, out of stock, levels by product' ),
			array( 'sales_summary',           'woocommerce', 'read', 'woo', 'sales_summary',           'none', 'woocommerce', 'Sales: revenue, order count, average order value for period' ),
			array( 'list_variations',         'woocommerce', 'read', 'woo', 'list_variations',         'none', 'woocommerce', 'All variations for a variable product with prices and stock' ),
			array( 'get_top_sellers',         'woocommerce', 'read', 'woo', 'get_top_sellers',         'none', 'woocommerce', 'Top selling products by revenue or quantity for a period' ),
			array( 'get_order_statuses',      'woocommerce', 'read', 'woo', 'get_order_statuses',      'none', 'woocommerce', 'All registered order statuses including custom ones' ),
			array( 'get_products_on_sale',    'woocommerce', 'read', 'woo', 'get_products_on_sale',    'none', 'woocommerce', 'Products on sale with discount percentages and end dates' ),
			array( 'customer_insights',       'woocommerce', 'read', 'woo', 'customer_insights',       'none', 'woocommerce', 'Customer segmentation via RFM: active, cooling, at-risk, churned' ),
			array( 'list_product_attributes', 'woocommerce', 'read', 'woo', 'list_product_attributes', 'none', 'woocommerce', 'Global product attributes (Color, Size) and their terms' ),
			array( 'category_report',         'woocommerce', 'read', 'woo', 'category_report',         'none', 'woocommerce', 'Sales performance by product category' ),
			array( 'revenue_report',          'woocommerce', 'read', 'woo', 'revenue_report',          'none', 'woocommerce', 'Revenue report with period-over-period comparison' ),
			array( 'stock_report',            'woocommerce', 'read', 'woo', 'stock_report',            'none', 'woocommerce', 'Inventory overview by stock status with valuation' ),
			array( 'manage_webhooks',         'woocommerce', 'read', 'woo', 'manage_webhooks',         'none', 'woocommerce', 'List, audit, pause, or delete WooCommerce webhooks' ),
			array( 'get_wc_alerts',           'woocommerce', 'read', 'woo', 'get_wc_alerts',           'none', 'woocommerce', 'Proactive alerts: low stock, failed orders, cancellations' ),
		) );

		// ── WooCommerce — writes ────────────────────────────────
		self::register_all( array(
			array( 'edit_product',         'woocommerce', 'confirm', 'woo', 'edit_product',         'none', 'woocommerce', 'Update a product: 30+ fields via WC object model' ),
			array( 'create_product',       'woocommerce', 'confirm', 'woo', 'create_product',       'none', 'woocommerce', 'Create product: simple, variable, grouped, or external' ),
			array( 'bulk_edit_products',   'woocommerce', 'confirm', 'woo', 'bulk_edit_products',   'none', 'woocommerce', 'Bulk update multiple products at once' ),
			array( 'update_order',         'woocommerce', 'confirm', 'woo', 'update_order',         'none', 'woocommerce', 'Update order status or add a note' ),
			array( 'manage_coupon',        'woocommerce', 'confirm', 'woo', 'manage_coupon',        'none', 'woocommerce', 'Create, edit, delete, or list coupons' ),
			array( 'email_customer',       'woocommerce', 'confirm', 'woo', 'email_customer',       'none', 'woocommerce', 'Send personalized email to a customer' ),
			array( 'moderate_review',      'woocommerce', 'confirm', 'woo', 'moderate_review',      'none', 'woocommerce', 'Approve, reject, spam, trash, or reply to a review' ),
			array( 'reply_review',         'woocommerce', 'confirm', 'woo', 'reply_review',         'none', 'woocommerce', 'Reply to a product review as the current admin user' ),
			array( 'bulk_reply_reviews',   'woocommerce', 'confirm', 'woo', 'bulk_reply_reviews',   'none', 'woocommerce', 'Reply to multiple product reviews in one confirmable action' ),
			array( 'edit_variation',       'woocommerce', 'confirm', 'woo', 'edit_variation',       'none', 'woocommerce', 'Edit a product variation: price, stock, status' ),
			array( 'create_variation',     'woocommerce', 'confirm', 'woo', 'create_variation',     'none', 'woocommerce', 'Create a new variation with attributes and pricing' ),
			array( 'bulk_edit_variations', 'woocommerce', 'confirm', 'woo', 'bulk_edit_variations', 'none', 'woocommerce', 'Bulk edit all variations: prices, stock, status' ),
			array( 'create_refund',        'woocommerce', 'confirm', 'woo', 'create_refund',        'none', 'woocommerce', 'Issue full or partial refund for an order' ),
			array( 'create_order',         'woocommerce', 'confirm', 'woo', 'create_order',         'none', 'woocommerce', 'Create manual order with addresses and line items' ),
			array( 'trigger_wc_email',     'woocommerce', 'confirm', 'woo', 'trigger_wc_email',     'none', 'woocommerce', 'Trigger any WooCommerce email programmatically' ),
		) );

		// ── Elementor — reads ───────────────────────────────────
		self::register_all( array(
			array( 'elementor_read_page',         'elementor', 'read', 'elementor', 'elementor_read_page',         'none', 'elementor', 'Read Elementor page structure: sections, columns, widgets' ),
			array( 'elementor_find_widgets',      'elementor', 'read', 'elementor', 'elementor_find_widgets',      'none', 'elementor', 'Find widgets by type, content, or section with IDs' ),
			array( 'elementor_list_templates',    'elementor', 'read', 'elementor', 'elementor_list_templates',    'none', 'elementor', 'List saved Elementor templates by type' ),
			array( 'elementor_get_styles',        'elementor', 'read', 'elementor', 'elementor_get_styles',        'none', 'elementor', 'Global styles: colors, typography, container width, spacing' ),
			array( 'elementor_audit_page',        'elementor', 'read', 'elementor', 'elementor_audit_page',        'none', 'elementor', 'Audit page: missing alt text, broken buttons, heading issues' ),
			array( 'elementor_site_pages',        'elementor', 'read', 'elementor', 'elementor_site_pages',        'none', 'elementor', 'List all Elementor pages across the site with metadata' ),
			array( 'elementor_get_widget_schema', 'elementor', 'read', 'elementor', 'elementor_get_widget_schema', 'none', 'elementor', 'Discover fields for any widget type including third-party' ),
			array( 'elementor_get_breakpoints',   'elementor', 'read', 'elementor', 'elementor_get_breakpoints',   'none', 'elementor', 'Active breakpoints with pixel thresholds and device labels' ),
			array( 'elementor_manage_conditions', 'elementor', 'read', 'elementor', 'elementor_manage_conditions', 'none', 'elementor', 'Read display conditions on theme builder templates' ),
			array( 'elementor_list_dynamic_tags', 'elementor', 'read', 'elementor', 'elementor_list_dynamic_tags', 'none', 'elementor', 'List available dynamic tags grouped by category' ),
			array( 'elementor_read_form',         'elementor', 'read', 'elementor', 'elementor_read_form',         'none', 'elementor', 'Read Elementor Pro Form config: fields, actions, logic' ),
			array( 'elementor_list_popups',       'elementor', 'read', 'elementor', 'elementor_list_popups',       'none', 'elementor', 'List Elementor Pro popups with trigger configuration' ),
		) );

		// ── Elementor — writes (preview) ────────────────────────
		self::register_all( array(
			array( 'elementor_edit_widget',     'elementor', 'preview', 'elementor', 'elementor_edit_widget',     'elementor_widget', 'elementor', 'Edit widget by ID with natural language field names' ),
			array( 'elementor_add_widget',      'elementor', 'preview', 'elementor', 'elementor_add_widget',      'elementor_widget', 'elementor', 'Add a new widget to an Elementor page' ),
			array( 'elementor_add_container',   'elementor', 'preview', 'elementor', 'elementor_add_container',   'elementor_widget', 'elementor', 'Add a new section/container to hold widgets' ),
			array( 'elementor_create_page',     'elementor', 'preview', 'elementor', 'elementor_create_page',     'elementor_page',   'elementor', 'Create a new Elementor page or post from scratch or template' ),
			array( 'elementor_find_replace',    'elementor', 'preview', 'elementor', 'elementor_find_replace',    'elementor_widget', 'elementor', 'Find and replace text across all Elementor pages' ),
			array( 'elementor_set_dynamic_tag', 'elementor', 'preview', 'elementor', 'elementor_set_dynamic_tag', 'elementor_widget', 'elementor', 'Connect a widget field to dynamic data' ),
		) );

		// ── Elementor — writes (confirm) ────────────────────────
		self::register_all( array(
			array( 'elementor_create_from_template', 'elementor', 'confirm', 'elementor', 'elementor_create_from_template', 'none', 'elementor', 'Create page from an existing Elementor template' ),
			array( 'elementor_edit_form_field',      'elementor', 'confirm', 'elementor', 'elementor_edit_form_field',      'none', 'elementor', 'Edit a specific field in an Elementor Pro Form' ),
			array( 'elementor_set_visibility',       'elementor', 'confirm', 'elementor', 'elementor_set_visibility',       'none', 'elementor', 'Control element visibility conditions' ),
			array( 'elementor_global_styles',        'elementor', 'confirm', 'elementor', 'elementor_global_styles',        'none', 'elementor', 'Read or update Elementor global design system' ),
			array( 'elementor_clone_page',           'elementor', 'confirm', 'elementor', 'elementor_clone_page',           'none', 'elementor', 'Duplicate an Elementor page with all content and meta' ),
			array( 'elementor_edit_popup_trigger',   'elementor', 'confirm', 'elementor', 'elementor_edit_popup_trigger',   'none', 'elementor', 'Edit trigger settings on an Elementor Pro popup' ),
		) );

		// ── Automations ────────────────────────────────────────
		self::register_all( array(
			array( 'list_automations',   'automations', 'read',    'automation', 'list_automations',   'none', null, 'List all scheduled prompt automations with status and next run' ),
			array( 'create_automation',  'automations', 'confirm', 'automation', 'create_automation',  'none', null, 'Create a new scheduled prompt automation' ),
			array( 'update_automation',  'automations', 'confirm', 'automation', 'update_automation',  'none', null, 'Update an existing automation: prompt, schedule, or policy' ),
			array( 'toggle_automation',  'automations', 'confirm', 'automation', 'toggle_automation',  'none', null, 'Pause or resume a scheduled automation' ),
			array( 'run_automation_now', 'automations', 'confirm', 'automation', 'run_automation_now', 'none', null, 'Trigger an immediate run of an automation' ),
			array( 'delete_automation',  'automations', 'confirm', 'automation', 'delete_automation',  'none', null, 'Permanently delete a scheduled automation' ),
			array( 'inspect_automation', 'automations', 'read',    'automation', 'inspect_automation', 'none', null, 'View automation history, last result, and execution hints' ),
		) );

		// ── Aliases (hallucinated / legacy tool names) ──────────
		self::alias( 'get_posts',     'list_posts' );
		self::alias( 'get_pages',     'list_posts' );
		self::alias( 'get_products',  'list_posts' );
		self::alias( 'list_products', 'list_posts' );
		self::alias( 'get_content',   'read_content' );
		self::alias( 'get_post',      'read_content' );
		self::alias( 'search_posts',  'search_content' );
		self::alias( 'find_content',  'search_content' );
		self::alias( 'get_plugins',   'list_plugins' );
		self::alias( 'get_themes',    'list_themes' );
		self::alias( 'get_comments',  'list_comments' );
		self::alias( 'get_orders',    'list_orders' );
		self::alias( 'get_customers', 'list_customers' );
		self::alias( 'reply_to_review', 'reply_review' );
		self::alias( 'bulk_moderate_reviews', 'bulk_reply_reviews' );
		self::alias( 'seo_analysis',  'analyze_seo' );
		self::alias( 'security_scan', 'scan_security' );

		// ── Virtual group aliases (cross-group supersets) ────────
		// 'content' = core tools + get_revision_history (health) + search_knowledge (index)
		self::register_virtual_group( 'content', array( 'core' ), array( 'get_revision_history', 'search_knowledge' ) );

		// ── v5.3.0: Apply extended contracts for high-risk/important tools ──
		self::apply_execution_contracts();

		// ── v5.3.0: Allow third-party contract enrichment ──
		self::apply_contract_filters();
	}

	/**
	 * Apply execution contracts to high-risk and high-value operations.
	 *
	 * Called after all operations are registered in boot(). Populates the
	 * richer contract metadata for the most important tools first — bulk
	 * destructive ops, financial writes, system mutations, and high-traffic
	 * reads that benefit from caching.
	 *
	 * @since 5.3.0
	 */
	private static function apply_execution_contracts(): void {

		$contracts = array(

			// ── Destructive / high-risk operations ──────────────────

			'delete_content' => array(
				'search_hint'   => 'trash remove delete post page',
				'interrupt'     => 'cancel',
				'tags'          => array( 'destructive', 'content' ),
				'idempotent'    => false,
				'policy_hooks'  => array(
					'pre_execute' => 'pressark_before_delete_content',
				),
			),

			'bulk_delete' => array(
				'search_hint'   => 'bulk trash remove delete multiple posts',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'output_policy' => 'compact',
				'tags'          => array( 'destructive', 'bulk', 'content' ),
				'idempotent'    => false,
				'policy_hooks'  => array(
					'pre_execute'  => 'pressark_before_bulk_delete',
					'post_execute' => 'pressark_after_bulk_delete',
				),
			),

			'empty_trash' => array(
				'search_hint'   => 'permanently delete empty trash irreversible',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'tags'          => array( 'destructive', 'bulk', 'irreversible' ),
				'idempotent'    => true,
				'policy_hooks'  => array(
					'pre_approve' => 'pressark_confirm_empty_trash',
				),
			),

			'bulk_delete_media' => array(
				'search_hint'   => 'permanently delete media images attachments',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'tags'          => array( 'destructive', 'bulk', 'media', 'irreversible' ),
				'idempotent'    => false,
			),

			'find_and_replace' => array(
				'search_hint'   => 'find replace text across multiple posts bulk',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'output_policy' => 'large',
				'tags'          => array( 'bulk', 'content', 'dangerous' ),
				'idempotent'    => false,
				'policy_hooks'  => array(
					'pre_execute' => 'pressark_before_find_replace',
				),
			),

			'fix_security' => array(
				'search_hint'   => 'security fix remediate exposed files xmlrpc',
				'interrupt'     => 'block',
				'tags'          => array( 'destructive', 'security', 'system' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'scan_security',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'fix_seo' => array(
				'search_hint'   => 'fix seo meta title description schema',
				'interrupt'     => 'block',
				'tags'          => array( 'seo', 'write' ),
				'read_invalidation' => array(
					'scope'  => 'target_posts',
					'reason' => 'SEO edits stale prior reads of the affected content.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'read_content',
					'read_args'    => array( 'mode' => 'structured' ),
					'check_fields' => array( 'seo_title', 'seo_description' ),
					'intensity'    => 'standard',
					'nudge'        => false,
				),
			),

			'update_meta' => array(
				'search_hint'   => 'update post meta seo fields custom',
				'interrupt'     => 'block',
				'tags'          => array( 'content', 'meta', 'write' ),
				'read_invalidation' => array(
					'scope'  => 'target_posts',
					'reason' => 'Meta updates stale prior reads of the affected content.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'read_content',
					'read_args'    => array( 'mode' => 'structured' ),
					'check_fields' => array(),
					'intensity'    => 'standard',
					'nudge'        => false,
				),
			),

			'edit_template' => array(
				'search_hint'   => 'edit FSE block template part',
				'interrupt'     => 'block',
				'tags'          => array( 'templates', 'design', 'write' ),
				'read_invalidation' => array(
					'scope'           => 'resource',
					'resource_groups' => array( 'templates' ),
					'reason'          => 'Template edits stale template resource snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'get_templates',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'update_theme_setting' => array(
				'search_hint'   => 'update theme customizer setting',
				'interrupt'     => 'block',
				'tags'          => array( 'themes', 'design', 'system' ),
				'read_invalidation' => array(
					'scope'           => 'resource',
					'resource_groups' => array( 'design' ),
					'reason'          => 'Theme setting changes stale design resource snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'get_theme_settings',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'cleanup_database' => array(
				'search_hint'   => 'cleanup revisions drafts spam transients database',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'tags'          => array( 'destructive', 'database', 'maintenance' ),
				'idempotent'    => true,
			),

			'optimize_database' => array(
				'search_hint'   => 'optimize tables reclaim space database',
				'interrupt'     => 'block',
				'tags'          => array( 'database', 'maintenance' ),
				'idempotent'    => true,
			),

			// ── WooCommerce financial / customer-facing ─────────────

			'create_refund' => array(
				'search_hint'   => 'refund order money return payment',
				'interrupt'     => 'cancel',
				'tags'          => array( 'woocommerce', 'financial', 'irreversible' ),
				'idempotent'    => false,
				'policy_hooks'  => array(
					'pre_approve' => 'pressark_confirm_refund',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'get_order',
					'read_args'    => array(),
					'check_fields' => array( 'status', 'total', 'refunds' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'email_customer' => array(
				'search_hint'   => 'email customer send message notification',
				'interrupt'     => 'cancel',
				'tags'          => array( 'woocommerce', 'communication', 'irreversible' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'none',
					'read_tool'    => '',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'light',
					'nudge'        => true,
				),
			),

			'trigger_wc_email' => array(
				'search_hint'   => 'trigger woocommerce email notification',
				'interrupt'     => 'cancel',
				'tags'          => array( 'woocommerce', 'communication' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'none',
					'read_tool'    => '',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'light',
					'nudge'        => true,
				),
			),

			'bulk_edit_products' => array(
				'search_hint'   => 'bulk update products price stock status',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'output_policy' => 'compact',
				'tags'          => array( 'woocommerce', 'bulk', 'products' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'get_product',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'edit_product' => array(
				'search_hint'   => 'edit update product price stock description',
				'interrupt'     => 'block',
				'tags'          => array( 'woocommerce', 'products', 'write' ),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'get_product',
					'read_args'    => array(),
					'check_fields' => array( 'name', 'regular_price', 'stock_quantity', 'status' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'create_product' => array(
				'search_hint'   => 'create new product simple variable',
				'interrupt'     => 'block',
				'tags'          => array( 'woocommerce', 'products', 'write' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'existence_check',
					'read_tool'    => 'get_product',
					'read_args'    => array(),
					'check_fields' => array( 'name', 'status', 'type' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'create_order' => array(
				'search_hint'   => 'create manual order billing shipping',
				'interrupt'     => 'cancel',
				'tags'          => array( 'woocommerce', 'orders', 'financial' ),
				'idempotent'    => false,
				'verification'  => array(
					'strategy'     => 'existence_check',
					'read_tool'    => 'get_order',
					'read_args'    => array(),
					'check_fields' => array( 'status', 'total' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			// ── Content writes (preview flow) ───────────────────────

			'edit_content' => array(
				'search_hint'   => 'edit update post page title content excerpt',
				'interrupt'     => 'block',
				'tags'          => array( 'content', 'write' ),
				'read_invalidation' => array(
					'scope'           => 'target_posts',
					'resource_groups' => array( 'site' ),
					'reason'          => 'Content edits stale prior reads of that content and site snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'read_content',
					'read_args'    => array( 'mode' => 'structured' ),
					'check_fields' => array( 'title', 'status' ),
					'intensity'    => 'standard',
					'nudge'        => false,
				),
			),

			'create_post' => array(
				'search_hint'   => 'create new post page draft publish',
				'interrupt'     => 'block',
				'tags'          => array( 'content', 'write' ),
				'idempotent'    => false,
				'read_invalidation' => array(
					'scope'           => 'site_content',
					'resource_groups' => array( 'site' ),
					'reason'          => 'New content can stale prior search results and site snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'existence_check',
					'read_tool'    => 'read_content',
					'read_args'    => array( 'mode' => 'structured' ),
					'check_fields' => array( 'title', 'status' ),
					'intensity'    => 'standard',
					'nudge'        => false,
				),
			),

			'delete_content' => array(
				'search_hint'   => 'delete remove post page content',
				'interrupt'     => 'block',
				'tags'          => array( 'content', 'write', 'destructive' ),
				'read_invalidation' => array(
					'scope'           => 'site_content',
					'resource_groups' => array( 'site' ),
					'reason'          => 'Deleted content invalidates prior content reads and site snapshots.',
				),
			),

			'bulk_edit' => array(
				'search_hint'   => 'bulk edit status category author posts',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'tags'          => array( 'bulk', 'content' ),
				'idempotent'    => false,
			),

			// ── System mutations ────────────────────────────────────

			'update_site_settings' => array(
				'search_hint'   => 'update site settings name url timezone',
				'interrupt'     => 'block',
				'tags'          => array( 'settings', 'system' ),
				'read_invalidation' => array(
					'scope'  => 'site',
					'reason' => 'Site setting changes can stale broad site context.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'get_site_settings',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'switch_theme' => array(
				'search_hint'   => 'switch activate theme',
				'interrupt'     => 'cancel',
				'tags'          => array( 'themes', 'system', 'dangerous' ),
				'idempotent'    => true,
				'read_invalidation' => array(
					'scope'  => 'site',
					'reason' => 'Theme switches stale all previously captured site state.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'list_themes',
					'read_args'    => array(),
					'check_fields' => array( 'active' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'toggle_plugin' => array(
				'search_hint'   => 'activate deactivate plugin enable disable',
				'interrupt'     => 'cancel',
				'tags'          => array( 'plugins', 'system' ),
				'idempotent'    => true,
				'read_invalidation' => array(
					'scope'  => 'site',
					'reason' => 'Plugin changes can stale broad site context and resources.',
				),
				'verification'  => array(
					'strategy'     => 'field_check',
					'read_tool'    => 'list_plugins',
					'read_args'    => array(),
					'check_fields' => array( 'status' ),
					'intensity'    => 'standard',
					'nudge'        => true,
				),
			),

			'manage_scheduled_task' => array(
				'search_hint'   => 'cron scheduled task run remove reschedule',
				'interrupt'     => 'cancel',
				'tags'          => array( 'system', 'cron' ),
			),

			'clear_log' => array(
				'search_hint'   => 'clear truncate log debug',
				'interrupt'     => 'cancel',
				'tags'          => array( 'logs', 'maintenance' ),
				'idempotent'    => true,
			),

			// ── High-traffic reads (cache candidates) ───────────────

			'read_content' => array(
				'search_hint'   => 'read view post page content by ID URL slug',
				'cache_ttl'     => 300,
				'tags'          => array( 'content', 'read' ),
				'defer'         => 'always_load',
			),

			'search_content' => array(
				'search_hint'   => 'search find posts pages keyword',
				'cache_ttl'     => 120,
				'tags'          => array( 'content', 'search', 'read' ),
				'defer'         => 'always_load',
			),

			'list_posts' => array(
				'search_hint'   => 'list posts pages filter type status date',
				'cache_ttl'     => 120,
				'tags'          => array( 'content', 'read' ),
				'defer'         => 'always_load',
			),

			'get_site_overview' => array(
				'search_hint'   => 'site overview info wordpress version theme plugins',
				'cache_ttl'     => 600,
				'tags'          => array( 'discovery', 'read' ),
			),

			'get_site_map' => array(
				'search_hint'   => 'site map structure pages navigation',
				'cache_ttl'     => 600,
				'tags'          => array( 'discovery', 'read' ),
			),

			'analyze_seo' => array(
				'search_hint'   => 'seo analysis audit score meta title description',
				'cache_ttl'     => 300,
				'output_policy' => 'large',
				'tags'          => array( 'seo', 'analysis', 'read' ),
			),

			'scan_security' => array(
				'search_hint'   => 'security scan audit vulnerabilities check',
				'cache_ttl'     => 300,
				'output_policy' => 'large',
				'tags'          => array( 'security', 'analysis', 'read' ),
			),

			'site_health' => array(
				'search_hint'   => 'site health status issues recommendations',
				'cache_ttl'     => 300,
				'output_policy' => 'large',
				'tags'          => array( 'health', 'diagnostics', 'read' ),
			),

			'search_knowledge' => array(
				'search_hint'   => 'search indexed content knowledge base keyword',
				'cache_ttl'     => 60,
				'tags'          => array( 'content', 'search', 'index' ),
			),

			// ── Discovery meta-tools ────────────────────────────────

			'discover_tools' => array(
				'search_hint'   => 'find search available tools capabilities',
				'cache_ttl'     => 0,
				'defer'         => 'always_load',
				'tags'          => array( 'meta', 'discovery' ),
			),

			'load_tools' => array(
				'search_hint'   => 'load activate tool schemas group',
				'cache_ttl'     => 0,
				'defer'         => 'always_load',
				'tags'          => array( 'meta', 'discovery' ),
			),

			'load_tool_group' => array(
				'search_hint'   => 'load tool group schemas by name',
				'cache_ttl'     => 0,
				'defer'         => 'always_load',
				'tags'          => array( 'meta', 'discovery' ),
			),

			// ── WooCommerce analytics (large output, cacheable) ─────

			'sales_summary' => array(
				'search_hint'   => 'sales revenue orders average value period report',
				'cache_ttl'     => 180,
				'output_policy' => 'large',
				'tags'          => array( 'woocommerce', 'analytics', 'read' ),
			),

			'inventory_report' => array(
				'search_hint'   => 'inventory stock levels low out report',
				'cache_ttl'     => 180,
				'output_policy' => 'large',
				'tags'          => array( 'woocommerce', 'inventory', 'read' ),
			),

			'customer_insights' => array(
				'search_hint'   => 'customer segmentation RFM active churn analysis',
				'cache_ttl'     => 300,
				'output_policy' => 'large',
				'tags'          => array( 'woocommerce', 'analytics', 'customers' ),
			),

			'revenue_report' => array(
				'search_hint'   => 'revenue report comparison period growth',
				'cache_ttl'     => 180,
				'output_policy' => 'large',
				'tags'          => array( 'woocommerce', 'analytics', 'financial' ),
			),

			// ── Automations ────────────────────────────────────────

			'delete_automation' => array(
				'search_hint'   => 'delete remove automation permanently',
				'interrupt'     => 'cancel',
				'tags'          => array( 'automations', 'destructive' ),
				'idempotent'    => false,
			),

			'run_automation_now' => array(
				'search_hint'   => 'trigger run automation immediately now',
				'interrupt'     => 'block',
				'tags'          => array( 'automations' ),
				'idempotent'    => true,
			),

			// ── Elementor writes ───────────────────────────────────

			'elementor_edit_widget' => array(
				'search_hint'   => 'elementor edit widget text heading image button',
				'interrupt'     => 'block',
				'tags'          => array( 'elementor', 'write' ),
				'read_invalidation' => array(
					'scope'           => 'target_posts',
					'resource_groups' => array( 'elementor' ),
					'reason'          => 'Elementor widget edits stale prior page-builder reads.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'elementor_read_page',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'elementor_add_widget' => array(
				'search_hint'   => 'elementor add new widget section',
				'interrupt'     => 'block',
				'tags'          => array( 'elementor', 'write' ),
				'read_invalidation' => array(
					'scope'           => 'target_posts',
					'resource_groups' => array( 'elementor' ),
					'reason'          => 'Elementor widget insertion stale prior page-builder reads.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'elementor_read_page',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'standard',
					'nudge'        => true,
				),
			),

			'elementor_add_container' => array(
				'search_hint'   => 'elementor add container section',
				'interrupt'     => 'block',
				'tags'          => array( 'elementor', 'write' ),
				'read_invalidation' => array(
					'scope'           => 'target_posts',
					'resource_groups' => array( 'elementor' ),
					'reason'          => 'Elementor container changes stale prior page-builder reads.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'elementor_read_page',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'standard',
					'nudge'        => true,
				),
			),

			'elementor_create_page' => array(
				'search_hint'   => 'elementor create new page template',
				'interrupt'     => 'block',
				'tags'          => array( 'elementor', 'write' ),
				'idempotent'    => false,
				'read_invalidation' => array(
					'scope'           => 'site_content',
					'resource_groups' => array( 'elementor', 'site' ),
					'reason'          => 'New Elementor pages can stale prior searches and site snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'existence_check',
					'read_tool'    => 'read_content',
					'read_args'    => array( 'mode' => 'structured' ),
					'check_fields' => array( 'title', 'status' ),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'elementor_find_replace' => array(
				'search_hint'   => 'elementor find replace text across pages',
				'interrupt'     => 'cancel',
				'resumable'     => true,
				'tags'          => array( 'elementor', 'bulk', 'dangerous' ),
				'idempotent'    => false,
				'read_invalidation' => array(
					'scope'           => 'site_content',
					'resource_groups' => array( 'elementor' ),
					'reason'          => 'Bulk Elementor replacements stale prior page-builder and content reads.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'elementor_read_page',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),

			'elementor_global_styles' => array(
				'search_hint'   => 'elementor global design system colors typography',
				'interrupt'     => 'block',
				'tags'          => array( 'elementor', 'design', 'system' ),
				'read_invalidation' => array(
					'scope'           => 'resource',
					'resource_groups' => array( 'elementor', 'design' ),
					'reason'          => 'Elementor global style updates stale design resource snapshots.',
				),
				'verification'  => array(
					'strategy'     => 'read_back',
					'read_tool'    => 'elementor_get_styles',
					'read_args'    => array(),
					'check_fields' => array(),
					'intensity'    => 'thorough',
					'nudge'        => true,
				),
			),
		);

		foreach ( $contracts as $name => $contract ) {
			if ( isset( self::$operations[ $name ] ) ) {
				self::$operations[ $name ]->apply_contract( $contract );
			}
		}
	}

	/**
	 * Bulk register from compact tuple arrays.
	 *
	 * v3.4.1 format: [ name, group, cap, handler, method, strategy?, requires?, description? ]
	 * v5.2.0 format: [ ..., concurrency_safe? ] (bool at position 8)
	 * v5.3.0 format: [ ..., contract? ] (assoc array at position 8 or 9)
	 *
	 * The contract array is detected by type: if position 8 is an array,
	 * it's treated as the execution contract (concurrency_safe defaults true).
	 * If position 8 is a bool, it's concurrency_safe and position 9 is the
	 * optional contract array.
	 *
	 * label auto-generated from name. risk auto-derived from cap + name.
	 */
	private static function register_all( array $rows ): void {
		foreach ( $rows as $row ) {
			$name = $row[0];
			$cap  = $row[2];

			// Detect contract array position (backward-compatible).
			$concurrency_safe = true;
			$contract         = array();

			if ( isset( $row[8] ) ) {
				if ( is_array( $row[8] ) ) {
					// v5.3.0: position 8 is the contract array.
					$contract = $row[8];
				} else {
					// v5.2.0: position 8 is concurrency_safe bool.
					$concurrency_safe = (bool) $row[8];
					if ( isset( $row[9] ) && is_array( $row[9] ) ) {
						$contract = $row[9];
					}
				}
			}

			$op = new PressArk_Operation(
				name:             $name,
				group:            $row[1],
				capability:       $cap,
				handler:          $row[3],
				method:           $row[4],
				preview_strategy: $row[5] ?? 'none',
				requires:         $row[6] ?? null,
				label:            ucwords( str_replace( '_', ' ', $name ) ),
				description:      $row[7] ?? '',
				risk:             self::derive_risk( $name, $cap ),
				concurrency_safe: $concurrency_safe,
			);

			if ( ! empty( $contract ) ) {
				$op->apply_contract( $contract );
			}

			self::register( $op );
		}
	}

	/**
	 * Fire the contract filter hook after boot.
	 *
	 * Allows third-party plugins and Pressark extensions to enrich
	 * execution contracts for any registered operation. The filter
	 * receives each operation's contract array and can return a
	 * modified version.
	 *
	 * @since 5.3.0
	 */
	private static function apply_contract_filters(): void {
		if ( ! has_filter( 'pressark_operation_contract' ) ) {
			return;
		}

		foreach ( self::$operations as $op ) {
			/**
			 * Filter the execution contract for a registered operation.
			 *
			 * @since 5.3.0
			 * @param array             $contract The full execution contract array.
			 * @param PressArk_Operation $op       The operation object.
			 * @return array Modified contract (only extended fields are applied).
			 */
			$filtered = apply_filters( 'pressark_operation_contract', $op->execution_contract(), $op );

			if ( is_array( $filtered ) ) {
				$op->apply_contract( $filtered );
			}
		}
	}
}
