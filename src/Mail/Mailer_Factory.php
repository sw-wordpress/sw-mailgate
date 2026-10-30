<?php
/**
 * Loads WordPress's mailer dependencies only when a mail operation needs them.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

final class Mailer_Factory {

	/** Older WordPress releases omit this PHPMailer interface from their bundled files. */
	public static function load_oauth(): void {
		if ( ! interface_exists( 'PHPMailer\\PHPMailer\\OAuthTokenProvider' ) ) {
			$file = ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
			require_once is_readable( $file ) ? $file : dirname( __DIR__, 2 ) . '/compat/PHPMailer/OAuthTokenProvider.php';
		}
	}

	public static function create(): Mail_Catcher {
		foreach ( array(
			'PHPMailer\\PHPMailer\\PHPMailer' => '/PHPMailer/PHPMailer.php',
			'PHPMailer\\PHPMailer\\SMTP'      => '/PHPMailer/SMTP.php',
			'PHPMailer\\PHPMailer\\Exception' => '/PHPMailer/Exception.php',
			'WP_PHPMailer'                   => '/class-wp-phpmailer.php',
		) as $class => $file ) {
			if ( ! class_exists( $class, false ) ) {
				require_once ABSPATH . WPINC . $file;
			}
		}

		$mailer = new Mail_Catcher( true );
		if ( function_exists( 'is_email' ) ) {
			$mailer::$validator = static function ( $email ) {
				return (bool) is_email( $email );
			};
		}
		return $mailer;
	}
}
