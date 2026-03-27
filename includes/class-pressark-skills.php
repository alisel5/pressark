<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Skills System — expert domain knowledge injected into the cached prompt.
 *
 * v3.6.0: Conditional scoping. Universal skills always included;
 * feature-specific skills (WooCommerce, Elementor) only when active.
 * Niche domains condensed into a single reference block.
 *
 * FUTURE (task-scoped skills): Current scoping is site-level — all
 * universal skills ship on every request regardless of task type.
 * A future pass could scope skills to the routed task/domain (e.g.
 * only SEO skill for analyze_seo tasks, only generation skill for
 * content creation). This would further reduce prompt ballast and
 * avoid instruction collisions. Requires integration with the task
 * classifier in PressArk_Agent::classify_task().
 *
 * Usage:
 *   $skills = PressArk_Skills::get_scoped( $flags );
 *   $system_prompt .= "\n\n" . $skills;
 */
class PressArk_Skills {

	/**
	 * Get skills scoped to active site features.
	 *
	 * v3.6.0: Replaces get_all(). Universal skills always included;
	 * WooCommerce/Elementor skills only when those features are active.
	 * Niche domains (plugins, themes, database, logs, settings, users,
	 * comments, media, profile, export, health) condensed into a single
	 * reference block saving ~1300 tokens.
	 *
	 * @param array $flags { has_woo: bool, has_elementor: bool }
	 * @return string Concatenated skill blocks.
	 */
	public static function get_scoped( array $flags = array() ): string {
		// ── Always-on: universal WordPress skills ──
		$blocks = array(
			self::core(),
			self::seo(),
			self::security(),
			self::generation_guidance(),
			self::content_guidance(),
			self::bulk_guidance(),
			self::index_guidance(),
			self::diagnostics(),
		);

		// ── Conditional: only when the feature is active ──
		if ( ! empty( $flags['has_woo'] ) ) {
			$blocks[] = self::woocommerce();
		}

		if ( ! empty( $flags['has_elementor'] ) ) {
			$blocks[] = self::elementor();
		}

		// Block editor guidance for sites NOT using Elementor as primary builder.
		if ( empty( $flags['has_elementor'] ) ) {
			$blocks[] = self::block_editor();
		}

		// ── Condensed reference for secondary domains ──
		$blocks[] = self::reference();

		return implode( "\n\n---\n\n", $blocks );
	}

	/**
	 * Get all skills combined for the cached prompt block.
	 *
	 * v3.6.0: Delegates to get_scoped() with auto-detected flags.
	 * Retained for backward compatibility.
	 *
	 * @return string All applicable skills concatenated.
	 */
	public static function get_all(): string {
		return self::get_scoped( array(
			'has_woo'       => class_exists( 'WooCommerce' ),
			'has_elementor' => defined( 'ELEMENTOR_VERSION' ),
		) );
	}

	/**
	 * Task-category → skill map for dynamic injection.
	 *
	 * v3.7.2: Maps PressArk_Agent::classify_task() categories to the
	 * subset of universal skills relevant to that task type. This allows
	 * the agent to inject only the skills that matter for the current
	 * request into the dynamic prompt block, while the cached block
	 * retains only core + reference for stable prefix caching.
	 *
	 * @since 3.7.2
	 */
	private const TASK_SKILL_MAP = array(
		'diagnose' => array( 'diagnostics', 'security' ),
		'analyze'  => array( 'seo', 'security', 'diagnostics' ),
		'generate' => array( 'generation', 'content', 'index', 'bulk' ),
		'edit'     => array( 'content', 'index' ),
		'code'     => array(),          // Core + reference is sufficient.
		'chat'     => array(),          // Core + reference is sufficient.
		'classify' => array(),          // Minimal — just routing.
	);

	/**
	 * Get skills scoped to a specific task category.
	 *
	 * v3.7.2: Intended for injection into the agent's dynamic prompt
	 * block. Returns only the universal skills relevant to the detected
	 * task type, plus WooCommerce/Elementor when active. Core and
	 * reference are always included.
	 *
	 * @since 3.7.2
	 * @param string $task_category Task category from classify_task().
	 * @param array  $flags         { has_woo: bool, has_elementor: bool }
	 * @return string Concatenated skill blocks.
	 */
	public static function get_task_scoped( string $task_category, array $flags = array() ): string {
		return implode( "\n\n---\n\n", self::task_blocks( $task_category, $flags, true ) );
	}

	/**
	 * Get only the dynamic task-specific skills that are NOT already present
	 * in the cached prompt block.
	 *
	 * v3.7.6: Prevents duplicated core/reference instructions on every agent
	 * round while preserving get_task_scoped() for tests and backward compat.
	 *
	 * @since 3.7.6
	 * @param string $task_category Task category from classify_task().
	 * @param array  $flags         { has_woo: bool, has_elementor: bool }
	 * @return string Concatenated skill blocks or empty string.
	 */
	public static function get_dynamic_task_scoped( string $task_category, array $flags = array() ): string {
		return implode( "\n\n---\n\n", self::task_blocks( $task_category, $flags, false ) );
	}

	/**
	 * Get the list of skill names that would be included for a task category.
	 * Useful for testing and diagnostics.
	 *
	 * @since 3.7.2
	 * @param string $task_category Task category.
	 * @return array Skill names (e.g. ['diagnostics', 'security']).
	 */
	public static function skills_for_task( string $task_category ): array {
		return self::TASK_SKILL_MAP[ $task_category ] ?? array();
	}

	/**
	 * Resolve the ordered skill blocks for a task category.
	 *
	 * @param string $task_category  Task category from classify_task().
	 * @param array  $flags          { has_woo: bool, has_elementor: bool }
	 * @param bool   $include_shared Whether to include core + reference.
	 * @return array
	 */
	private static function task_blocks( string $task_category, array $flags, bool $include_shared ): array {
		$blocks = array();

		if ( $include_shared ) {
			$blocks[] = self::core();
		}

		$relevant = self::TASK_SKILL_MAP[ $task_category ] ?? array();

		$skill_methods = array(
			'seo'         => 'seo',
			'security'    => 'security',
			'generation'  => 'generation_guidance',
			'content'     => 'content_guidance',
			'bulk'        => 'bulk_guidance',
			'index'       => 'index_guidance',
			'diagnostics' => 'diagnostics',
		);

		foreach ( $relevant as $skill_name ) {
			if ( isset( $skill_methods[ $skill_name ] ) ) {
				$blocks[] = call_user_func( array( self::class, $skill_methods[ $skill_name ] ) );
			}
		}

		// WC/Elementor skills live in the cached block (build_cached_system_prompt).
		// Only include them when building a standalone prompt (include_shared=true).
		// The dynamic path (include_shared=false) skips them to avoid duplication.
		if ( $include_shared ) {
			if ( ! empty( $flags['has_woo'] ) ) {
				$blocks[] = self::woocommerce();
			}

			if ( ! empty( $flags['has_elementor'] ) ) {
				$blocks[] = self::elementor();
			}
		}

		// Block editor guidance for non-Elementor sites on content-related tasks.
		if ( empty( $flags['has_elementor'] ) && in_array( $task_category, array( 'generate', 'edit' ), true ) ) {
			$blocks[] = self::block_editor();
		}

		if ( $include_shared ) {
			$blocks[] = self::reference();
		}

		return $blocks;
	}

	// ── Core WordPress Knowledge ──────────────────────────────────────

	public static function core(): string {
		return <<<'SKILL'
WORDPRESS EXPERT KNOWLEDGE:

Content: posts/pages/CPTs identified by ID. post_status: publish|draft|trash|future|private. Meta in wp_postmeta (key-value). Taxonomies: categories (hierarchical), tags (flat). Discover CPTs/custom taxonomies via get_site_overview.

Blocks: HTML comment delimiters <!-- wp:type {"attrs"} -->content<!-- /wp:type -->. NEVER strip block markers — causes "unexpected content" errors. parse_blocks() → modify → serialize_blocks(). Classic sites store raw HTML without delimiters. Check context "editor:" field.

Themes: block/FSE (theme.json + Site Editor), classic (PHP templates + Customizer), hybrid. Check context "theme_type:" field. wp_get_global_settings() for design tokens.

Menus: wp_navigation CPT (FSE) vs wp_nav_menus (classic) — different systems, check context "menus:" field.

Infrastructure: Options in wp_options (autoloaded). Transients bypass DB when object cache active. REST API at /wp-json/wp/v2/. wp-cron is pseudo-cron (page-visit triggered). Rewrite rules cached in options — flush after permalink/CPT changes. Media = 'attachment' post type.
SKILL;
	}

	// ── SEO Knowledge ─────────────────────────────────────────────────

	public static function seo(): string {
		return <<<'SKILL'
SEO EXPERT KNOWLEDGE:
Scanner uses 4 weighted subscores: Indexing Health (30%), Search Appearance (30%), Content Quality (25%), Social Sharing (15%).

Indexing Health: canonical URL (self-ref=best, plugin-handled=good, missing=fail), robots meta (index+follow=optimal, nofollow=warning), indexing conflicts (canonical+noindex, nofollow+canonical), schema markup.
Search Appearance: meta title 30-70 chars (sweet spot ~55, keyword near start), meta description 50-200 chars (sweet spot ~155, include CTA), URL slug (short, no stop words).
Content Quality: H1 presence (multiple H1 is info-only, not penalized — HTML5 allows it), heading hierarchy (no level skips), image alt text, internal links.
Social Sharing: Open Graph tags (og:title, og:description, og:image).
Observations (info-only, not scored): content length, external links, featured image.

- When an SEO plugin is active and per-post meta is blank, the plugin template renders valid output — not a problem
- Pages set to noindex intentionally do not need meta optimization
- Canonical URLs prevent duplicate content — check for conflicts with noindex directives
- Use semantic write keys: meta_title, meta_description, og_title, og_description, og_image, focus_keyword, canonical
- The system maps those semantic keys to the active SEO plugin automatically
- Never propose raw plugin storage keys in actions — use the semantic keys above
SKILL;
	}

	// ── Security Knowledge ────────────────────────────────────────────

	public static function security(): string {
		return <<<'SKILL'
SECURITY EXPERT KNOWLEDGE:
- readme.html and license.txt expose WordPress version — should be deleted
- wp-config-sample.php is unnecessary on production — delete it
- XML-RPC (xmlrpc.php) is a common brute-force vector — disable unless needed for Jetpack/mobile apps
- Directory listing should be disabled (Options -Indexes in .htaccess)
- File editing in admin should be disabled: define('DISALLOW_FILE_EDIT', true)
- Database prefix should not be default 'wp_'
- Debug mode (WP_DEBUG) should be off in production
- Security headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
- Strong passwords and 2FA for admin accounts
- Keep WordPress core, themes, and plugins updated
- Auto-fixable items: delete exposed files, disable XML-RPC via mu-plugin, add security headers
SKILL;
	}

	// ── WooCommerce Knowledge ─────────────────────────────────────────

	public static function woocommerce(): string {
		return <<<'SKILL'
WOOCOMMERCE KNOWLEDGE:
- Products: regular_price, sale_price, sku, stock_status, manage_stock. Types: simple|variable|grouped|external
- Variable products have variations with own prices/SKUs/stock
- Orders: pending|processing|on-hold|completed|cancelled|refunded|failed. WC REST: wc/v3
- HPOS: orders in wc_orders table, NOT wp_posts. Use wc_get_orders(), $order->get_meta() — never get_post_meta() on orders
- Stock: use wc_update_product_stock(), not direct meta. wc_product_meta_lookup for queries
- Coupons: fixed_cart|percent|fixed_product. Shipping zones contain methods
- For product-led content, ground the draft on a real WooCommerce product read before writing
- For product CTAs, use the real product permalink returned by WooCommerce data â€” never invent product URLs
SKILL;
	}

	// ── Content Generation Knowledge ──────────────────────────────────
	// v3.6.0: Enhanced with content gen flow (was in SYSTEM_PROMPT_BASE).

	public static function generation(): string {
		return <<<'SKILL'
CONTENT GENERATION KNOWLEDGE:
- Use current context, the site profile, and any RELEVANT SITE CONTENT first to match tone, vocabulary, and style
- If style evidence is still thin or the request is net-new, call search_knowledge before drafting
- Generated content should feel like it belongs on this site, not generic AI output
- Match the site's dominant writing voice, heading style, CTAs, and average length from the site profile
- Indexed snippets are style evidence, not guaranteed live truth â€” verify recent factual claims with read_content when accuracy matters
- Site or brand profile is style guidance only, not proof of products, URLs, prices, or inventory
- For product-led WooCommerce content, resolve a real product first and use its actual URL/details in the draft and CTA
- When creating blog posts: include featured image suggestion, categories, tags, meta title/description
- When rewriting: preserve the original meaning and key information
- For bulk meta generation: title ~55 chars (30-70 range), description ~155 chars (50-200 range)
- When generate_content/rewrite_content/generate_bulk_meta returns "generate: true":
  1. Generate the content using the returned data
  2. Present a brief summary of what you wrote, then propose it as a write action
  The preview system lets the user review, edit, or discard — do not ask separately
- For bulk meta: after generating, offer to apply all via fix_seo with specific per-page fixes
SKILL;
	}

	// ── Content Management Knowledge ──────────────────────────────────

	/**
	 * Runtime generation guidance with fresher anti-rot language.
	 *
	 * v3.7.6: Uses grounded context first so generation tasks do not pay
	 * for redundant retrieval when the needed style evidence is already present.
	 */
	public static function generation_guidance(): string {
		return <<<'SKILL'
CONTENT GENERATION KNOWLEDGE:
- Use current context, the site profile, and any RELEVANT SITE CONTENT first to match tone, vocabulary, and style
- If style evidence is still thin or the request is net-new, call search_knowledge before drafting
- Generated content should feel like it belongs on this site, not generic AI output
- Match the site's dominant writing voice, heading style, CTAs, and average length from the site profile
- Indexed snippets are style evidence, not guaranteed live truth - verify recent factual claims with read_content when accuracy matters
- Site or brand profile is style guidance only, not proof of products, URLs, prices, or inventory
- For product-led WooCommerce content, resolve a real product first and use its actual URL/details in the draft and CTA
- When creating blog posts: include featured image suggestion, categories, tags, meta title/description
- When rewriting: preserve the original meaning and key information
- For bulk meta generation: title ~55 chars (30-70 range), description ~155 chars (50-200 range)
- When generate_content/rewrite_content/generate_bulk_meta returns "generate: true":
  1. Generate the content using the returned data
  2. Present a brief summary of what you wrote, then propose it as a write action
  The preview system lets the user review, edit, or discard - do not ask separately
- For bulk meta: after generating, offer to apply all via fix_seo with specific per-page fixes
SKILL;
	}

	public static function content(): string {
		return <<<'SKILL'
CONTENT MANAGEMENT KNOWLEDGE:
- When editing content, propose previewable write actions â€” the system renders the before/after preview
- If the target object is unclear, resolve it with read/search tools before asking the user
- Post statuses: publish, draft, pending, private, future (scheduled), trash
- Scheduling: set status to 'future' with a scheduled_date in 'Y-m-d H:i:s' format
- Slug (post_name) affects the URL — changing it can break existing links
- Featured images (post thumbnails) are stored as attachment IDs in _thumbnail_id meta
- Post revisions are automatically saved — users can restore previous versions
- Excerpts are optional manual summaries; auto-generated from content if empty
- When listing posts, include: ID, title, status, date, type for easy reference
SKILL;
	}

	// ── Bulk Operations Knowledge ─────────────────────────────────────

	/**
	 * Runtime content guidance with clean preview/ambiguity language.
	 *
	 * v3.7.6: Avoids encoding drift in the legacy content() block while
	 * keeping the live prompt aligned with the approval contract.
	 */
	public static function content_guidance(): string {
		return <<<'SKILL'
CONTENT MANAGEMENT KNOWLEDGE:
- When editing content, propose previewable write actions - the system renders the before/after preview
- If the target object is unclear, resolve it with read/search tools before asking the user
- Post statuses: publish, draft, pending, private, future (scheduled), trash
- Scheduling: set status to 'future' with a scheduled_date in 'Y-m-d H:i:s' format
- Slug (post_name) affects the URL - changing it can break existing links
- Featured images (post thumbnails) are stored as attachment IDs in _thumbnail_id meta
- Post revisions are automatically saved - users can restore previous versions
- Excerpts are optional manual summaries; auto-generated from content if empty
- When listing posts, include: ID, title, status, date, type for easy reference
- BLOCK CONTENT SAFETY: When editing block-editor content, preserve ALL <!-- wp:* --> comment delimiters
- Modifying HTML between block markers is safe; removing or altering the markers causes "unexpected content" errors
- If you need to change block type or attributes, modify the JSON in the opening comment, not the HTML structure
- When creating new content for block editor sites, use proper block markup - not raw HTML
- Homepage modes: show_on_front='page' means static page (check context for page ID); show_on_front='posts' means blog listing
- "Edit the homepage" on a static-page site = edit the page_on_front; on a blog-listing site = edit theme/settings, not a page
- The context block shows "Homepage: static page #ID" or "Homepage: latest posts" - use this to resolve homepage requests
SKILL;
	}

	public static function bulk(): string {
		return <<<'SKILL'
BULK OPERATIONS KNOWLEDGE:
- Find and replace: show dry_run results before proposing writes
- Bulk edits affect multiple posts — ALWAYS confirm with the user before executing
- If affecting more than 3 items, list exactly what will change
- Bulk meta generation should process posts in batches
- Show a summary of changes before and after bulk operations
- Each bulk change should be individually undoable when possible
SKILL;
	}

	// ── Elementor Knowledge ───────────────────────────────────────────

	/**
	 * Runtime bulk guidance aligned with the approval UI contract.
	 *
	 * v3.7.6: Keeps the live prompt free of manual-confirm language without
	 * breaking callers that may still reference bulk() directly.
	 */
	public static function bulk_guidance(): string {
		return <<<'SKILL'
BULK OPERATIONS KNOWLEDGE:
- Find and replace: show dry_run results before proposing writes
- Bulk edits affect multiple posts - they must go through the approval UI before execution
- If affecting more than 3 items, list exact targets and deltas before proposing
- Bulk meta generation should process posts in batches
- Show a summary of changes before and after bulk operations
- Each bulk change should be individually undoable when possible
SKILL;
	}

	public static function elementor(): string {
		return <<<'SKILL'
ELEMENTOR KNOWLEDGE:
- Content stored as structured JSON in _elementor_data post meta
- Modern: containers hold widgets. Legacy: sections → columns → widgets
- Global widgets shared across pages. Templates reusable. CSS regenerates on change
- Target widgets by element ID within page structure
SKILL;
	}

	// ── Block Editor Knowledge ───────────────────────────────────────

	/**
	 * Block Editor (Gutenberg) knowledge - injected for non-Elementor sites.
	 *
	 * @since 4.2.x
	 */
	public static function block_editor(): string {
		return <<<'SKILL'
BLOCK EDITOR (GUTENBERG) KNOWLEDGE:
- Blocks are the fundamental content unit in modern WordPress
- Stored as HTML comments in post_content: <!-- wp:paragraph {"align":"center"} --><p>text</p><!-- /wp:paragraph -->
- Block attributes stored as JSON in the opening comment
- Nested blocks: <!-- wp:group --> contains inner blocks <!-- /wp:group -->
- Common blocks: paragraph, heading, image, list, quote, columns, group, cover, buttons
- When editing: NEVER strip block delimiters - parse_blocks() -> modify -> serialize_blocks()
- Block patterns: pre-designed layouts registered by themes/plugins, inserted as regular blocks
- Synced patterns (formerly reusable blocks): stored as wp_block CPT, shared across pages
- Block template parts: header, footer, sidebar - stored as wp_template_part CPT in FSE themes
- Global styles: wp_get_global_styles() returns active design configuration from theme.json
- Block categories: text, media, design, widgets, theme, embed
- Block variations: same block type with different default attributes (e.g., Row vs Stack are both Group)
- Classic blocks: <!-- wp:freeform --> wraps legacy HTML content within block editor
- If post_content contains NO block delimiters, it's classic editor content - treat as raw HTML
SKILL;
	}

	// ── Content Index Knowledge ───────────────────────────────────────
	// v3.6.0: Enhanced with usage guidance (was in SYSTEM_PROMPT_BASE).

	public static function index(): string {
		return <<<'SKILL'
CONTENT INDEX KNOWLEDGE:
- Full-text search index of ALL site content (MySQL FULLTEXT, ~800-word overlapping chunks)
- When relevant content appears in context as "RELEVANT SITE CONTENT", use it to:
  reference actual site text, match tone/style for new content, avoid contradicting existing content
- Use search_knowledge to find content not auto-included in context
- Index auto-syncs on publish/update/delete. Full rebuild via WP Cron
- Includes: title, content, excerpt, SEO meta, categories, tags. WooCommerce: price, SKU, short description
- Index data may lag behind recent edits — note this when accuracy matters
SKILL;
	}

	// ── Diagnostics Knowledge ────────────────────────────────────────

	/**
	 * Runtime index guidance with explicit anti-rot language.
	 *
	 * v3.7.6: Keeps the live prompt honest about freshness without
	 * changing the legacy index() method shape.
	 */
	public static function index_guidance(): string {
		return <<<'SKILL'
CONTENT INDEX KNOWLEDGE:
- Full-text search index of ALL site content (MySQL FULLTEXT, ~800-word overlapping chunks)
- When relevant content appears in context as "RELEVANT SITE CONTENT", use it to:
  reference actual site text, match tone/style for new content, avoid contradicting existing content
- Use search_knowledge to find content not auto-included in context
- Index auto-syncs on publish/update/delete. Full rebuild via WP Cron
- Includes: title, content, excerpt, SEO meta, categories, tags. WooCommerce: price, SKU, short description
- Index data may lag behind recent edits - treat it as retrieval context, not live truth, and verify with read_content when recency matters
SKILL;
	}

	public static function diagnostics(): string {
		return <<<'SKILL'
DIAGNOSTICS EXPERT KNOWLEDGE:
You have real measurement tools. Use them before advising.

Performance diagnosis flow:
1. measure_page_speed → get actual load time + cache status
2. If slow: inspect_hooks('wp_head') → find bloated hooks
3. If no cache hit: look at cache_status field for which plugin to configure
4. Give specific numbers in your response: "3.2 seconds" not "slow"

SEO diagnosis flow:
1. check_crawlability → verify Google can see the site at all
2. analyze_seo on the specific page mentioned
3. Most common finding: WordPress set to "discourage search engines" in Settings → Reading

Email diagnosis flow:
1. check_email_delivery → check SMTP plugin + hooks
2. If no SMTP plugin: explain the site is relying on host mail and recommend adding a reputable SMTP integration
3. Most email failures: wp_mail() works but PHP mail() is blocked by host

Plugin conflict flow:
1. inspect_hooks on the hook that's misbehaving
2. Look for duplicate callbacks or priority conflicts
3. Known conflict patterns: two SEO plugins both on wp_head, two caching plugins both on init

Always say what you measured:
Good: "I measured your homepage — it loaded in 4.1 seconds and the cache showed MISS."
Bad: "Your site might be slow because of too many plugins."
SKILL;
	}

	// ── v3.6.0: Condensed Reference Block ─────────────────────────────
	// Replaces 11 separate skill blocks (plugins, themes, database, logs,
	// settings, users, comments, media, profile, export, health) with a
	// single compact block. Saves ~1300 tokens while retaining all
	// critical facts the model needs for these secondary domains.

	public static function reference(): string {
		return <<<'SKILL'
REFERENCE (use when relevant tools are loaded):
- Plugins: deactivate preserves settings, delete removes all. mu-plugins always active
- Themes: switching may reset menus/widgets. Child themes safe. Block themes use FSE
- Database: cleanup targets revisions, auto-drafts, trash, spam, transients, orphaned meta. Backup first
- Logs: needs WP_DEBUG + WP_DEBUG_LOG. Fatal = highest priority. Plugin path identifies source
- Settings: permalink changes break links. Timezone affects scheduling
- Users: admin > editor > author > contributor > subscriber
- Comments: approved|pending|spam|trash. Bulk moderation available
- Media: attachment CPT. Alt text in _wp_attachment_image_alt (SEO-critical). Featured via _thumbnail_id
- Site profile: tone/voice/style guide — not proof of latest content
- Reports: downloadable HTML (SEO, security, content)
- Site health: PHP, HTTPS, debug, updates, REST, loopback, cron checks
SKILL;
	}

	// ── Legacy individual skill methods (retained for backward compat) ──
	// v3.6.0: These are no longer included in the cached prompt block
	// individually. Their content is preserved in the reference() block.
	// Retained in case any external code calls them directly.

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function plugins(): string {
		return <<<'SKILL'
PLUGIN MANAGEMENT KNOWLEDGE:
- Activating/deactivating plugins can affect site functionality — warn the user
- Some plugins have dependencies on others
- Deactivating a plugin preserves its settings; deleting removes everything
- Must-use plugins (mu-plugins) are always active and can't be deactivated from admin
- Plugin updates should be tested on staging first when possible
- Check for plugin conflicts when troubleshooting issues
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function themes(): string {
		return <<<'SKILL'
THEME MANAGEMENT KNOWLEDGE:
- Switching themes can change site appearance dramatically — always warn
- Theme Customizer settings are theme-specific and may be lost on switch
- Child themes inherit from parent themes and are safe for customization
- Block themes use Full Site Editing (FSE) with template parts
- Classic themes use template files (header.php, footer.php, etc.)
- Theme settings and widget areas may differ between themes
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function database(): string {
		return <<<'SKILL'
DATABASE MANAGEMENT KNOWLEDGE:
- Cleanup targets: post revisions, auto-drafts, trashed posts, spam comments, transients, orphaned meta
- Optimize tables reclaims space after deletions (OPTIMIZE TABLE)
- Always backup before any database operations
- Transients are temporary cached data — safe to delete
- Orphaned meta has no parent post — safe to clean up
- Post revisions can be limited with WP_POST_REVISIONS constant
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function logs(): string {
		return <<<'SKILL'
LOG ANALYSIS KNOWLEDGE:
- WordPress debug.log requires WP_DEBUG and WP_DEBUG_LOG in wp-config.php
- Common error levels: Fatal, Error, Warning, Notice, Deprecated
- Errors from plugins appear with plugin directory name in the path
- Theme errors show the theme directory name
- PHP Fatal errors prevent the page from loading — highest priority
- Repeated errors suggest a persistent issue vs. one-time problems
- Log file size matters — very large logs may indicate excessive error generation
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function settings(): string {
		return <<<'SKILL'
WORDPRESS SETTINGS KNOWLEDGE:
- Site title (blogname) and tagline (blogdescription) are core branding
- Permalink structure affects all URLs — changes can break existing links and SEO
- Timezone affects scheduled posts and displayed times
- Reading settings control the front page display and posts per page
- Discussion settings control comments (open/closed by default, moderation, etc.)
- Menu locations are defined by the theme — not all themes have the same locations
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function users(): string {
		return <<<'SKILL'
USER MANAGEMENT KNOWLEDGE:
- Role capabilities: administrator (all), editor (publish/manage all posts), author (own posts only), contributor (write but not publish), subscriber (profile only)
- Changing a user's role immediately affects their permissions
- Admin email is used for WordPress notifications — important to keep current
- User meta stores additional profile data
- Email notifications can be sent for password resets, new user registration, etc.
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function comments(): string {
		return <<<'SKILL'
COMMENT MANAGEMENT KNOWLEDGE:
- Comment statuses: approved (1), pending (0), spam, trash
- Moderation queue holds comments waiting for approval
- Replying to comments creates threaded discussions
- Bulk moderation can approve, spam, or trash multiple comments at once
- Comment meta can store additional data per comment
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function media(): string {
		return <<<'SKILL'
MEDIA MANAGEMENT KNOWLEDGE:
- Media items are stored as 'attachment' post type
- Each upload generates multiple sizes defined by the theme and settings
- Alt text is stored in _wp_attachment_image_alt post meta — critical for accessibility and SEO
- Image titles, captions, and descriptions are stored in post fields
- Featured images link posts to media items via _thumbnail_id meta
- Media library supports images, documents, audio, and video files
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function profile(): string {
		return <<<'SKILL'
SITE PROFILE KNOWLEDGE:
- The site profile captures business identity, content DNA, structure, and technical details
- Content DNA includes: tone, voice, average length, heading style, CTA patterns
- Profile auto-refreshes weekly but can be manually regenerated
- The AI uses profile data to match content style when generating new content
- Profile data is stored in wp_options as a serialized array
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function export(): string {
		return <<<'SKILL'
EXPORT/REPORT KNOWLEDGE:
- Reports can be generated as downloadable HTML files
- SEO reports include per-page scores and recommendations
- Security reports list all checks and their pass/fail status
- Content exports can include all posts, pages, and their meta data
SKILL;
	}

	/** @deprecated v3.6.0 — Use reference() instead. */
	public static function health(): string {
		return <<<'SKILL'
SITE HEALTH KNOWLEDGE:
- WordPress Site Health checks: PHP version, HTTPS, debug mode, plugin/theme updates, REST API, loopback
- WP-Cron handles scheduled tasks: post scheduling, plugin updates, content index sync
- Cron events can be one-time (single) or recurring (hourly, daily, weekly)
- Missing or stuck cron events can cause scheduled tasks to not run
- Server cron (system crontab) is more reliable than WP-Cron for high-traffic sites
SKILL;
	}
}
