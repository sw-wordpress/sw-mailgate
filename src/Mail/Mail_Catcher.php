<?php
/**
 * Replaces the global $phpmailer instance used by wp_mail().
 *
 * WordPress' wp_mail() fills this object as usual (all wp_mail_* filters and phpmailer_init run), then calls send().
 * Instead of sending directly, send() hands the populated object to the dispatcher, which clones it per
 * connection attempt. Without an active connection the native WordPress behaviour is kept.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

use PHPMailer\PHPMailer\Exception;

final class Mail_Catcher extends \WP_PHPMailer {

	private ?Dispatcher $dispatcher = null;

	public function set_dispatcher( Dispatcher $dispatcher ): void {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * @throws Exception When every connection failed and exceptions are enabled (as in wp_mail()).
	 */
	public function send() {
		if ( null === $this->dispatcher || ! $this->dispatcher->is_active() ) {
			return parent::send();
		}

		try {
			return $this->dispatcher->dispatch( $this );
		} catch ( Exception $e ) {
			$this->setError( $e->getMessage() );
			if ( $this->exceptions ) {
				throw $e;
			}
			return false;
		}
	}

	/**
	 * Each attempt works on a clone: it gets its own SMTP connection and never dispatches again.
	 */
	public function __clone() {
		$this->smtp       = null;
		$this->dispatcher = null;
	}

	/**
	 * Bcc header line for transports that read recipients from the MIME message (Gmail, Graph).
	 * PHPMailer omits it in SMTP mode, which is what SMTP and Mailgun need.
	 */
	public function bcc_header(): string {
		return count( $this->bcc ) > 0 ? $this->addrAppend( 'Bcc', $this->bcc ) : '';
	}
}
