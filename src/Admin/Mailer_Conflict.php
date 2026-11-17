<?php
/**
 * Warn about competing transports without loading PHPMailer on idle requests.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Capabilities;
use SWMailgate\Mail\Mail_Catcher;

final class Mailer_Conflict {
	public function notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$known   = array( 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php', 'post-smtp/postman-smtp.php', 'fluent-smtp/fluent-smtp.php', 'easy-wp-smtp/easy-wp-smtp.php', 'smtp-mailer/main.php' );
		$active  = (array) get_option( 'active_plugins', array() );
		$active  = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$foreign = isset( $GLOBALS['phpmailer'] ) && is_object( $GLOBALS['phpmailer'] ) && ! $GLOBALS['phpmailer'] instanceof Mail_Catcher;
		if ( ! $foreign && ! array_intersect( $known, $active ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'SW Mailgate detected another mailer plugin or a replaced mailer. Disable competing mailer plugins to avoid conflicting transport settings and unreliable delivery.', 'sw-mailgate' ) . '</p></div>';
	}
}
