<?php
/**
 * PressArk Notification Manager — Channel-based notification dispatch.
 *
 * Abstracts notification delivery so Telegram is a channel, not hardcoded calls.
 * Future channels (email, Slack, webhook) plug in via the same contract.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Notification_Manager {

	/**
	 * Send a notification through the specified channel.
	 *
	 * @param string $channel  Channel name (e.g. 'telegram').
	 * @param string $target   Channel-specific destination (e.g. chat_id).
	 * @param string $subject  Short subject line.
	 * @param string $body     Notification body text.
	 * @param array  $metadata Extra data (admin_url, run_id, etc.).
	 * @return array { success: bool, error?: string }
	 */
	public static function send( string $channel, string $target, string $subject, string $body, array $metadata = array() ): array {
		if ( empty( $target ) ) {
			return array( 'success' => false, 'error' => 'No notification target configured.' );
		}

		switch ( $channel ) {
			case 'telegram':
				return PressArk_Notification_Telegram::send( $target, $subject, $body, $metadata );
			default:
				return array( 'success' => false, 'error' => "Unknown notification channel: {$channel}" );
		}
	}

	/**
	 * Send an automation success notification.
	 */
	public static function notify_automation_success( array $automation, array $result = array() ): array {
		$name    = $automation['name'] ?: 'Unnamed Automation';
		$subject = "PressArk: \"{$name}\" completed";

		$summary = $result['message'] ?? $result['reply'] ?? 'Task completed successfully.';
		// Truncate to avoid Telegram message length limits.
		if ( mb_strlen( $summary ) > 500 ) {
			$summary = mb_substr( $summary, 0, 497 ) . '...';
		}

		$body = "Scheduled prompt \"{$name}\" has completed.\n\n";
		$body .= "Result: {$summary}\n\n";

		$actions = $result['actions_performed'] ?? array();
		if ( ! empty( $actions ) ) {
			$count = count( $actions );
			$body .= "{$count} action(s) performed.\n";
		}

		$body .= 'Review in wp-admin to verify the results.';

		$admin_url = admin_url( 'admin.php?page=pressark&tab=automations' );

		return self::send(
			$automation['notification_channel'] ?? 'telegram',
			self::resolve_automation_target( $automation ),
			$subject,
			$body,
			array( 'admin_url' => $admin_url, 'automation_id' => $automation['automation_id'] )
		);
	}

	/**
	 * Send an automation failure notification.
	 */
	public static function notify_automation_failure( array $automation, string $error ): array {
		$name    = $automation['name'] ?: 'Unnamed Automation';
		$subject = "PressArk: \"{$name}\" failed";

		// Don't leak full error details — keep it concise.
		$safe_error = mb_strlen( $error ) > 300 ? mb_substr( $error, 0, 297 ) . '...' : $error;

		$body = "Scheduled prompt \"{$name}\" has failed.\n\n";
		$body .= "Error: {$safe_error}\n\n";

		if ( $automation['failure_streak'] >= 2 ) {
			$body .= "This is failure #{$automation['failure_streak']}. The automation will be paused after 3 consecutive failures.\n\n";
		}

		$body .= 'Check wp-admin for details and to resume.';

		$admin_url = admin_url( 'admin.php?page=pressark&tab=automations' );

		return self::send(
			$automation['notification_channel'] ?? 'telegram',
			self::resolve_automation_target( $automation ),
			$subject,
			$body,
			array( 'admin_url' => $admin_url, 'automation_id' => $automation['automation_id'] )
		);
	}

	/**
	 * Send an automation policy-block notification.
	 */
	public static function notify_automation_policy_block( array $automation, string $reason ): array {
		$name    = $automation['name'] ?: 'Unnamed Automation';
		$subject = "PressArk: \"{$name}\" policy blocked";

		$body = "Scheduled prompt \"{$name}\" was blocked by policy.\n\n";
		$body .= "Reason: {$reason}\n\n";
		$body .= 'The automation is still active. Edit the prompt or policy to resolve.';

		return self::send(
			$automation['notification_channel'] ?? 'telegram',
			self::resolve_automation_target( $automation ),
			$subject,
			$body,
			array( 'automation_id' => $automation['automation_id'] )
		);
	}

	/**
	 * Resolve the current notification target for an automation.
	 *
	 * Stored targets are a snapshot from creation time; user meta is the live
	 * source of truth when it exists so notification settings changes apply to
	 * existing automations too.
	 */
	public static function resolve_automation_target( array $automation ): string {
		$user_id = (int) ( $automation['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$current_target = self::get_user_telegram_id( $user_id );
			if ( '' !== $current_target ) {
				return $current_target;
			}
		}

		return (string) ( $automation['notification_target'] ?? '' );
	}

	/**
	 * Get the Telegram chat ID for a user.
	 */
	public static function get_user_telegram_id( int $user_id ): string {
		return (string) get_user_meta( $user_id, 'pressark_telegram_chat_id', true );
	}

	/**
	 * Test notification delivery for a channel.
	 */
	public static function test( string $channel, string $target ): array {
		return self::send(
			$channel,
			$target,
			'PressArk Test Notification',
			'This is a test notification from PressArk. If you received this, notifications are working correctly.',
			array()
		);
	}
}
