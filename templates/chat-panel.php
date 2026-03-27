<?php
/**
 * PressArk Chat Panel Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
$page_title = '';

if ( $screen ) {
	if ( 'post' === $screen->base ) {
		global $post;
		$page_title = $post ? $post->post_title : $screen->id;
	} else {
		$page_title = $screen->id;
	}
} elseif ( ! is_admin() ) {
	// Frontend context.
	$page_title = is_singular() ? get_the_title() : wp_get_document_title();
}

$is_byok     = (bool) get_option( 'pressark_byok_enabled', false );
$has_api_key = PressArk_AI_Connector::is_proxy_mode() || $is_byok || ! empty( get_option( 'pressark_api_key', '' ) );
?>

<!-- Chat Panel -->
<div id="pressark-panel" class="pressark-panel" role="dialog" aria-label="<?php esc_attr_e( 'PressArk Chat', 'pressark' ); ?>">

	<!-- Header — thin, clean, branded -->
	<div class="pressark-header">
		<div class="pressark-header-left">
			<img id="pressark-header-logo" class="pressark-header-logo" alt="PressArk" width="24" height="24" />
			<span class="pressark-header-title"><?php esc_html_e( 'PressArk', 'pressark' ); ?></span>
		</div>
		<div class="pressark-header-actions">
			<button type="button" id="pressark-deep-mode-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Deep Mode', 'pressark' ); ?>" title="<?php esc_attr_e( 'Deep Mode: Use premium AI for complex tasks', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
				</svg>
			</button>
			<button type="button" id="pressark-history-btn" class="pressark-header-btn" aria-label="<?php esc_attr_e( 'Chat History', 'pressark' ); ?>" title="<?php esc_attr_e( 'Chat History', 'pressark' ); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
				</svg>
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
		<span id="pressark-context-text">
			<?php
			printf(
				/* translators: %s: current page/screen title */
				esc_html__( 'Currently viewing: %s', 'pressark' ),
				esc_html( $page_title )
			);
			?>
		</span>
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

	<?php if ( ! $has_api_key ) : ?>
		<!-- No API key warning -->
		<div class="pressark-no-key">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					wp_kses(
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
	<img id="pressark-toggle-logo" alt="PressArk" width="28" height="28" />
</button>
