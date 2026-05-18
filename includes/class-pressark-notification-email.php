<?php
/**
 * PressArk Notification Email — HTML email delivery channel via wp_mail().
 *
 * Branded HTML templates for instant alerts and weekly digest emails.
 * Uses table-based layout with inline styles for maximum email client
 * compatibility (Gmail, Outlook, Apple Mail).
 *
 * @package PressArk
 * @since   5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressArk_Notification_Email {

	/** Brand color from PressArk admin CSS (--pw-primary). */
	private const BRAND_COLOR = '#2563EB';

	/** Light brand tint for backgrounds. */
	private const BRAND_LIGHT = '#EFF6FF';

	/**
	 * Send a branded alert email.
	 *
	 * @param string $to       Recipient email address.
	 * @param string $subject  Email subject line.
	 * @param string $body     Alert body (plain text with newlines).
	 * @param array  $metadata Extra data (admin_url, etc.).
	 * @return array { success: bool, error?: string }
	 */
	public static function send( string $to, string $subject, string $body, array $metadata = array() ): array {
		if ( ! is_email( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid email address.' );
		}

		$html = self::build_alert_html( $subject, $body, $metadata );

		return self::deliver( $to, '[PressArk] ' . $subject, $html );
	}

	/**
	 * Send a digest email with a different template.
	 *
	 * @param string $to       Recipient email address.
	 * @param string $subject  Email subject line.
	 * @param string $body     Digest body (AI-generated summary with section headers).
	 * @param array  $metadata Extra data (admin_url, etc.).
	 * @return array { success: bool, error?: string }
	 */
	public static function send_digest( string $to, string $subject, string $body, array $metadata = array() ): array {
		if ( ! is_email( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid email address.' );
		}

		$html = self::build_digest_html( $subject, $body, $metadata );

		return self::deliver( $to, '[PressArk] ' . $subject, $html );
	}

	/**
	 * Deliver an HTML email via wp_mail, safely managing content-type filters.
	 *
	 * Uses try/finally to guarantee filters are removed even if wp_mail throws.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject line.
	 * @param string $html    HTML body.
	 * @return array { success: bool, error?: string }
	 */
	private static function deliver( string $to, string $subject, string $html ): array {
		$set_html    = function () { return 'text/html'; };
		$set_from    = function () { return 'PressArk'; };

		add_filter( 'wp_mail_content_type', $set_html );
		add_filter( 'wp_mail_from_name', $set_from );

		try {
			$sent = wp_mail( $to, $subject, $html );
		} finally {
			remove_filter( 'wp_mail_content_type', $set_html );
			remove_filter( 'wp_mail_from_name', $set_from );
		}

		if ( ! $sent ) {
			if ( class_exists( 'PressArk_Error_Tracker' ) ) {
				PressArk_Error_Tracker::warning( 'Email', 'wp_mail failed (HTML)', array( 'to' => $to ) );
			}
			return array( 'success' => false, 'error' => 'Email delivery failed via wp_mail.' );
		}

		return array( 'success' => true );
	}

	/**
	 * Build the alert email HTML.
	 */
	private static function build_alert_html( string $subject, string $body, array $metadata ): string {
		$site_name   = esc_html( get_bloginfo( 'name' ) );
		$logo_url    = self::get_logo_url();
		$admin_url   = ! empty( $metadata['admin_url'] ) ? esc_url( $metadata['admin_url'] ) : '';
		$settings_url = esc_url( admin_url( 'admin.php?page=pressark' ) );
		$body_escaped = nl2br( esc_html( $body ) );

		$logo_html = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="PressArk" width="140" style="display:block;margin:0 auto 16px;max-width:140px;height:auto;">'
			: '<div style="text-align:center;font-size:22px;font-weight:700;color:' . self::BRAND_COLOR . ';padding-bottom:16px;">PressArk</div>';

		$button_html = '';
		if ( $admin_url ) {
			$button_html = '
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px auto 0;">
					<tr>
						<td style="background:' . self::BRAND_COLOR . ';border-radius:6px;">
							<a href="' . $admin_url . '" target="_blank" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">View in Dashboard &rarr;</a>
						</td>
					</tr>
				</table>';
		}

		return self::wrap_email( $logo_html . '
			<h2 style="margin:0 0 20px;font-size:18px;font-weight:600;color:#0F172A;text-align:center;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . esc_html( $subject ) . '</h2>
			<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:20px;font-size:14px;line-height:1.6;color:#334155;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
				' . $body_escaped . '
			</div>
			' . $button_html, $site_name, $settings_url );
	}

	/**
	 * Build the digest email HTML.
	 */
	private static function build_digest_html( string $subject, string $body, array $metadata ): string {
		$site_name    = esc_html( get_bloginfo( 'name' ) );
		$logo_url     = self::get_logo_url();
		$admin_url    = ! empty( $metadata['admin_url'] ) ? esc_url( $metadata['admin_url'] ) : '';
		$settings_url = esc_url( admin_url( 'admin.php?page=pressark' ) );

		$logo_html = $logo_url
			? '<img src="' . esc_url( $logo_url ) . '" alt="PressArk" width="140" style="display:block;margin:0 auto 16px;max-width:140px;height:auto;">'
			: '<div style="text-align:center;font-size:22px;font-weight:700;color:#ffffff;padding-bottom:16px;">PressArk</div>';

		// Digest banner.
		$banner = '
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:24px;">
				<tr>
					<td style="background:' . self::BRAND_COLOR . ';border-radius:8px;padding:28px 24px;text-align:center;">
						' . $logo_html . '
						<div style="font-size:16px;font-weight:600;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">Weekly Store Intelligence Report</div>
						<div style="font-size:13px;color:rgba(255,255,255,0.8);margin-top:4px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . esc_html( $site_name ) . '</div>
					</td>
				</tr>
			</table>';

		// Convert the AI body into readable HTML paragraphs.
		$body_html = self::format_digest_body( $body );

		$button_html = '';
		if ( $admin_url ) {
			$button_html = '
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px auto 0;">
					<tr>
						<td style="background:' . self::BRAND_COLOR . ';border-radius:6px;">
							<a href="' . $admin_url . '" target="_blank" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">View in Dashboard &rarr;</a>
						</td>
					</tr>
				</table>';
		}

		return self::wrap_email( $banner . $body_html . $button_html, $site_name, $settings_url, false );
	}

	/**
	 * Convert digest plain text into HTML paragraphs.
	 *
	 * Lines starting with "##" or all-caps become section headers.
	 * Other lines become paragraphs.
	 */
	private static function format_digest_body( string $body ): string {
		$lines  = explode( "\n", $body );
		$html   = '';
		$in_list = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				if ( $in_list ) {
					$in_list = false;
				}
				continue;
			}

			// Section headers (## or lines that are short and all-caps).
			if ( str_starts_with( $trimmed, '## ' ) ) {
				$header = esc_html( ltrim( $trimmed, '# ' ) );
				$html .= '<h3 style="margin:24px 0 8px;font-size:15px;font-weight:600;color:' . self::BRAND_COLOR . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . $header . '</h3>';
			} elseif ( str_starts_with( $trimmed, '- ' ) || str_starts_with( $trimmed, '* ' ) ) {
				$item = esc_html( ltrim( $trimmed, '-* ' ) );
				$html .= '<div style="padding:2px 0 2px 16px;font-size:14px;line-height:1.6;color:#334155;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">&bull; ' . $item . '</div>';
				$in_list = true;
			} else {
				$html .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#334155;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">' . esc_html( $trimmed ) . '</p>';
			}
		}

		return $html;
	}

	/**
	 * Wrap email content in the outer table structure.
	 *
	 * @param string $inner        Inner HTML content.
	 * @param string $site_name    Site name for the footer.
	 * @param string $settings_url URL to PressArk settings page (for the manage-notifications footer link).
	 * @param bool   $show_logo    Whether to show the logo in the header (digest has its own).
	 */
	private static function wrap_email( string $inner, string $site_name, string $settings_url, bool $show_logo = true ): string {
		$logo_section = '';
		if ( $show_logo ) {
			$logo_url = self::get_logo_url();
			if ( $logo_url ) {
				$logo_section = '<img src="' . esc_url( $logo_url ) . '" alt="PressArk" width="120" style="display:block;margin:0 auto 20px;max-width:120px;height:auto;">';
			} else {
				$logo_section = '<div style="text-align:center;font-size:20px;font-weight:700;color:' . self::BRAND_COLOR . ';padding-bottom:20px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">PressArk</div>';
			}
		}

		return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F1F5F9;">
	<tr>
		<td align="center" style="padding:32px 16px;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
				<tr>
					<td style="padding:32px 32px 24px;">
						' . $logo_section . '
						' . $inner . '
					</td>
				</tr>
				<tr>
					<td style="padding:16px 32px 24px;border-top:1px solid #E2E8F0;">
						<p style="margin:0;font-size:12px;line-height:1.5;color:#94A3B8;text-align:center;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
							You\'re receiving this because PressArk is active on ' . $site_name . '.<br>
							<a href="' . $settings_url . '" style="color:' . self::BRAND_COLOR . ';text-decoration:underline;">Manage notifications in PressArk settings</a>
						</p>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>';
	}

	/**
	 * Get the PressArk logo URL from plugin assets.
	 *
	 * @return string Logo URL or empty string.
	 */
	private static function get_logo_url(): string {
		if ( ! function_exists( 'plugin_dir_url' ) ) {
			return '';
		}

		$plugin_dir = dirname( __DIR__ );
		$candidates = array( 'WHITE-APP-LOGO.png', 'DARK-APP-LOGO.png', 'app-icon-rounded-192.png' );

		foreach ( $candidates as $file ) {
			if ( file_exists( $plugin_dir . '/assets/imgs/' . $file ) ) {
				return plugins_url( 'assets/imgs/' . $file, $plugin_dir . '/pressark.php' );
			}
		}

		return '';
	}
}
