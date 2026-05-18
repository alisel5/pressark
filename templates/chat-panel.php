<?php
/**
 * PressArk Chat Panel Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pressark_screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
$pressark_page_title = '';

if ( $pressark_screen ) {
	if ( 'post' === $pressark_screen->base ) {
		global $post;
		$pressark_page_title = $post ? $post->post_title : $pressark_screen->id;
	} else {
		$pressark_page_title = $pressark_screen->id;
	}
} elseif ( ! is_admin() ) {
	// Frontend context.
	$pressark_page_title = is_singular() ? get_the_title() : wp_get_document_title();
}

$pressark_is_byok     = (bool) get_option( 'pressark_byok_enabled', false );
$pressark_has_api_key = PressArk_AI_Connector::simulator_active() || PressArk_AI_Connector::is_proxy_mode() || $pressark_is_byok || ! empty( get_option( 'pressark_api_key', '' ) );
?>

<!-- Chat Panel -->
<div id="pressark-panel" class="pressark-panel" data-theme="light" data-density="cozy" role="dialog" aria-label="<?php esc_attr_e( 'PressArk Chat', 'pressark' ); ?>">

	<!-- Header — thin, clean, branded -->
	<div class="pressark-header">
		<div class="pressark-header-left">
			<div class="pressark-header-mark" aria-hidden="true">
				<img id="pressark-header-logo" class="pressark-header-logo" alt="PressArk" width="24" height="24" />
			</div>
			<div class="pressark-header-copy">
				<span class="pressark-header-title"><?php esc_html_e( 'PressArk', 'pressark' ); ?></span>
				<span class="pressark-header-sub">
					<span class="pressark-header-live"></span>
					<?php esc_html_e( 'Working with you', 'pressark' ); ?>
				</span>
			</div>
		</div>
		<div class="pressark-header-actions">
			<button type="button" id="pressark-history-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Chat History', 'pressark' ); ?>" title="<?php esc_attr_e( 'Chat History', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
				</svg>
			</button>
			<button type="button" id="pressark-theme-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Use dark mode', 'pressark' ); ?>" title="<?php esc_attr_e( 'Use dark mode', 'pressark' ); ?>" aria-pressed="false">
				<span class="pressark-theme-icon" aria-hidden="true"></span>
			</button>
			<button type="button" id="pressark-new-chat-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'New Chat', 'pressark' ); ?>" title="<?php esc_attr_e( 'New Chat', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
				</svg>
			</button>
			<button type="button" id="pressark-activity-btn" class="pressark-header-btn pressark-activity-btn" aria-label="<?php esc_attr_e( 'Open Activity', 'pressark' ); ?>" title="<?php esc_attr_e( 'Open Activity', 'pressark' ); ?>">
				<span class="pressark-activity-btn-label"><?php esc_html_e( 'Inbox', 'pressark' ); ?></span>
				<span id="pressark-activity-count" class="pressark-activity-count" hidden>0</span>
			</button>
			<button type="button" id="pressark-close-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Close', 'pressark' ); ?>" title="<?php esc_attr_e( 'Close', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
		</div>
	</div>

	<!-- Context bar — subtle, informational -->
	<div class="pressark-context-bar">
		<span class="pressark-context-icon" aria-hidden="true">
			<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
				<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
				<polyline points="14 2 14 8 20 8"/>
			</svg>
		</span>
		<span class="pressark-context-label"><?php esc_html_e( 'On:', 'pressark' ); ?></span>
		<span id="pressark-context-page" class="pressark-context-page"><?php echo esc_html( $pressark_page_title ); ?></span>
		<span id="pressark-context-chip" class="pressark-context-chip">
			<span class="d"></span>
			<?php esc_html_e( 'Indexed', 'pressark' ); ?>
		</span>
	</div>

	<div id="pressark-plan-restore" class="pressark-plan-restore" hidden>
		<span id="pressark-plan-restore-progress" class="pressark-plan-restore-progress">0 / 0</span>
		<span id="pressark-plan-restore-summary" class="pressark-plan-restore-summary"></span>
		<button type="button" id="pressark-plan-restore-btn" class="pressark-plan-restore-btn"><?php esc_html_e( 'Show plan', 'pressark' ); ?></button>
	</div>

	<div id="pressark-plan-tracker" class="pressark-plan-tracker" hidden>
		<div id="pressark-plan-head" class="pressark-plan-head" tabindex="0" role="button" aria-expanded="true">
			<span class="pressark-plan-spark" aria-hidden="true">
				<span class="pressark-plan-spark-ray pressark-plan-spark-ray-a">
					<svg width="22" height="22" viewBox="0 0 24 24" fill="#EAF4FF"><path d="M12 1.5 13.7 9.3 21.5 11l-7.8 1.7L12 22.5 10.3 12.7 2.5 11l7.8-1.7L12 1.5z"/></svg>
				</span>
				<span class="pressark-plan-spark-core"></span>
			</span>
			<span id="pressark-plan-summary" class="pressark-plan-summary"></span>
			<span id="pressark-plan-progress" class="pressark-plan-progress">0 / 0</span>
			<span id="pressark-plan-dots" class="pressark-plan-dots" aria-hidden="true"></span>
			<button type="button" id="pressark-plan-collapse" class="pressark-plan-iconbtn" aria-label="<?php esc_attr_e( 'Collapse plan', 'pressark' ); ?>" title="<?php esc_attr_e( 'Collapse plan', 'pressark' ); ?>">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
					<polyline points="18 15 12 9 6 15"/>
				</svg>
			</button>
			<button type="button" id="pressark-plan-hide" class="pressark-plan-iconbtn" aria-label="<?php esc_attr_e( 'Hide plan', 'pressark' ); ?>" title="<?php esc_attr_e( 'Hide plan', 'pressark' ); ?>">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
					<line x1="18" y1="6" x2="6" y2="18"/>
					<line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
		</div>
		<div id="pressark-plan-body" class="pressark-plan-body">
			<ol id="pressark-plan-steps" class="pressark-plan-steps" aria-live="polite"></ol>
		</div>
	</div>

	<!-- History panel (hidden by default) -->
	<div id="pressark-history-panel" class="pressark-history-panel" style="display: none;">
		<div class="pressark-history-header">
			<span><?php esc_html_e( 'Chat History', 'pressark' ); ?></span>
			<button type="button" id="pressark-history-close" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Close History', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
			</button>
		</div>
		<div id="pressark-history-list" class="pressark-history-list">
			<div class="pressark-history-empty"><?php esc_html_e( 'No saved chats yet', 'pressark' ); ?></div>
		</div>
	</div>

	<?php if ( ! $pressark_has_api_key ) : ?>
		<!-- No API key warning -->
		<div class="pressark-no-key">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					wp_kses(
						/* translators: %s: settings page URL */
						__( 'Welcome to PressArk! To get started, <a href="%s">add your API key</a> in settings.', 'pressark' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'admin.php?page=pressark' ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Messages area — clean, scrollable -->
	<div id="pressark-messages" class="pressark-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Chat messages', 'pressark' ); ?>"></div>

	<!-- Input area — prominent, centered -->
	<div class="pressark-input-area">
		<div class="pressark-input-wrapper">
			<textarea
				id="pressark-input"
				class="pressark-input"
				rows="1"
				placeholder="<?php esc_attr_e( 'Ask PressArk anything...', 'pressark' ); ?>"
				aria-label="<?php esc_attr_e( 'Type your message to PressArk', 'pressark' ); ?>"
			></textarea>
			<button type="button" id="pressark-send" class="pressark-send-btn" aria-label="<?php esc_attr_e( 'Send', 'pressark' ); ?>" title="<?php esc_attr_e( 'Send', 'pressark' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
					<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
				</svg>
			</button>
		</div>
	</div>

	<!-- Quota bar — usage status -->
	<div id="pressark-quota-bar" class="pressark-quota-bar" role="status" aria-live="polite"></div>

</div>

<!-- Toggle button — uses brand icon -->
<button type="button" id="pressark-toggle" class="pressark-toggle-btn" aria-label="<?php esc_attr_e( 'Open PressArk', 'pressark' ); ?>">
	<span class="pressark-toggle-spark" aria-hidden="true">
		<span class="pressark-toggle-spark-ray pressark-toggle-spark-ray-a">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="#EAF4FF"><path d="M12 1.5 13.7 9.3 21.5 11l-7.8 1.7L12 22.5 10.3 12.7 2.5 11l7.8-1.7L12 1.5z"/></svg>
		</span>
		<span class="pressark-toggle-spark-ray pressark-toggle-spark-ray-b">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="#5BE3FF"><path d="M12 4 13 11l7 1-7 1-1 7-1-7-7-1 7-1 1-7z"/></svg>
		</span>
		<span class="pressark-toggle-spark-core"></span>
	</span>
</button>
