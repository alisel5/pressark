<?php
/**
 * PressArk Notification Telegram — Telegram Bot API delivery channel.
 *
 * Bot token stored encrypted in wp_options (same pattern as API keys).
 * Per-user chat ID stored in user meta.
 *
 * @package PressArk
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Notification_Telegram {

	private const API_BASE = 'https://api.telegram.org/bot';

	/**
	 * Send a Telegram message.
	 *
	 * @param string $chat_id  Telegram chat ID.
	 * @param string $subject  Subject line (prepended as bold).
	 * @param string $body     Message body.
	 * @param array  $metadata Extra data (admin_url, etc.).
	 * @return array { success: bool, error?: string }
	 */
	public static function send( string $chat_id, string $subject, string $body, array $metadata = array() ): array {
		$bot_token = self::get_bot_token();

		if ( empty( $bot_token ) ) {
			return array( 'success' => false, 'error' => 'Telegram bot token not configured in PressArk settings.' );
		}

		if ( empty( $chat_id ) ) {
			return array( 'success' => false, 'error' => 'Telegram chat ID not configured.' );
		}

		if ( ! preg_match( '/^-?\d+$/', $chat_id ) ) {
			return array( 'success' => false, 'error' => 'Invalid chat ID format.' );
		}

		// Build message with Markdown formatting.
		$text = "*{$subject}*\n\n{$body}";

		if ( ! empty( $metadata['admin_url'] ) ) {
			$text .= "\n\n[Open in wp-admin]({$metadata['admin_url']})";
		}

		// Telegram message limit is 4096 chars.
		if ( mb_strlen( $text ) > 4000 ) {
			$text = mb_substr( $text, 0, 3997 ) . '...';
		}

		$url = self::API_BASE . $bot_token . '/sendMessage';

		$response = wp_safe_remote_post( $url, array(
			'timeout' => 15,
			'body'    => array(
				'chat_id'    => $chat_id,
				'text'       => $text,
				'parse_mode' => 'Markdown',
				// Disable link previews to keep messages clean.
				'disable_web_page_preview' => true,
			),
		) );

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			PressArk_Error_Tracker::warning( 'Telegram', 'Send failed', array( 'error' => $error ) );
			return array( 'success' => false, 'error' => "Telegram delivery failed: {$error}" );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body_response = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body_response['ok'] ) ) {
			$telegram_error = $body_response['description'] ?? 'Unknown error';
			PressArk_Error_Tracker::warning( 'Telegram', 'API error', array( 'status' => $status, 'error' => $telegram_error ) );
			return array( 'success' => false, 'error' => "Telegram API error: {$telegram_error}" );
		}

		return array( 'success' => true );
	}

	/**
	 * Get the decrypted bot token from settings.
	 */
	public static function get_bot_token(): string {
		$encrypted = get_option( 'pressark_telegram_bot_token', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}

		// Fail-closed: no encryption class = no token.
		if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
			return '';
		}

		$decrypted = PressArk_Usage_Tracker::decrypt_value( $encrypted );
		return $decrypted ?: '';
	}

	/**
	 * Store the bot token encrypted.
	 */
	public static function set_bot_token( string $token ): void {
		if ( empty( $token ) ) {
			delete_option( 'pressark_telegram_bot_token' );
			return;
		}

		// Fail-closed: no encryption class = refuse to store.
		if ( ! class_exists( 'PressArk_Usage_Tracker' ) ) {
			return;
		}

		$encrypted = PressArk_Usage_Tracker::encrypt_value( $token );
		update_option( 'pressark_telegram_bot_token', $encrypted, false );
	}

	/**
	 * Validate a bot token by calling getMe.
	 *
	 * @return array { valid: bool, bot_name?: string, error?: string }
	 */
	public static function validate_token( string $token ): array {
		if ( empty( $token ) ) {
			return array( 'valid' => false, 'error' => 'Token is empty.' );
		}

		$url = self::API_BASE . $token . '/getMe';

		$response = wp_safe_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'valid' => false, 'error' => $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['ok'] ) ) {
			return array( 'valid' => false, 'error' => $body['description'] ?? 'Invalid token.' );
		}

		return array(
			'valid'    => true,
			'bot_name' => $body['result']['username'] ?? 'unknown',
		);
	}
}
