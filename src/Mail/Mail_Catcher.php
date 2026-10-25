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
	private ?Redirector $redirector = null;

	public function set_dispatcher( Dispatcher $dispatcher ): void {
		$this->dispatcher = $dispatcher;
	}

	public function set_redirector( Redirector $redirector ): void {
		$this->redirector = $redirector;
	}

	/** Includes addresses deferred by PHPMailer until preSend(), especially IDN recipients. */
	public function recipient_groups(): array {
		$groups = array(
			'to'  => $this->getToAddresses(),
			'cc'  => $this->getCcAddresses(),
			'bcc' => $this->getBccAddresses(),
		);
		foreach ( $this->RecipientsQueue as $queued ) {
			$groups[ $queued[0] ][] = array( $queued[1], $queued[2] );
		}
		return $groups;
	}

	/**
	 * @throws Exception When every connection failed and exceptions are enabled (as in wp_mail()).
	 */
	public function send() {
		try {
			$original_to = null === $this->redirector ? array() : $this->redirector->apply( $this );
			if ( null === $this->dispatcher || ! $this->dispatcher->is_active() ) {
				return parent::send();
			}
			return $this->dispatcher->dispatch( $this, $original_to );
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
		$this->redirector = null;
	}

	/**
	 * Bcc header line for transports that read recipients from the MIME message (Gmail, Graph).
	 * PHPMailer omits it in SMTP mode, which is what SMTP and Mailgun need.
	 */
	public function bcc_header(): string {
		return count( $this->bcc ) > 0 ? $this->addrAppend( 'Bcc', $this->bcc ) : '';
	}
}
