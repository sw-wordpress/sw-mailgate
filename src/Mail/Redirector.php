<?php
/**
 * Redirect non-allowlisted recipients before any transport can send a message.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

use PHPMailer\PHPMailer\Exception;

final class Redirector {

	private bool $bypass = false;

	/** Authenticated test mails can temporarily bypass redirection. Always restores the previous state. */
	public function without_redirection( callable $send ): mixed {
		$previous     = $this->bypass;
		$this->bypass = true;
		try {
			return $send();
		} finally {
			$this->bypass = $previous;
		}
	}

	/**
	 * @return list<string> All original recipients when any recipient was redirected; otherwise empty.
	 * @throws Exception When redirection is misconfigured. Never fall back to real recipients.
	 */
	public function apply( Mail_Catcher $mailer ): array {
		if ( $this->bypass ) {
			return array();
		}
		$settings = Redirection_Settings::get();
		if ( ! $settings['valid'] ) {
			throw new Exception( esc_html__( 'Mail redirection is misconfigured. Sending was blocked.', 'sw-mailgate' ) );
		}
		if ( '' === $settings['target'] ) {
			return array();
		}
		$groups   = $mailer->recipient_groups();
		$original = array();
		$visible  = array();
		$allowed  = array();
		$changed  = false;
		foreach ( $groups as $kind => $addresses ) {
			$allowed[ $kind ] = array();
			foreach ( $addresses as $address ) {
				$email      = (string) $address[0];
				$original[] = $email;
				if ( 'bcc' !== $kind ) {
					$visible[] = $email;
				}
				$normalized = strtolower( $mailer->punyencodeAddress( $email ) );
				$domain     = substr( $normalized, (int) strrpos( $normalized, '@' ) + 1 );
				if ( in_array( $normalized, $settings['allowlist'], true ) || in_array( $domain, $settings['allowlist'], true ) ) {
					$allowed[ $kind ][] = $address;
				} else {
					$changed = true;
				}
			}
		}
		if ( ! $changed ) {
			return array();
		}
		// This also clears deferred IDN recipients, which PHPMailer validates only in preSend().
		$mailer->clearAllRecipients();
		$mailer->addAddress( $settings['target'] );
		foreach ( array(
			'to'  => 'addAddress',
			'cc'  => 'addCC',
			'bcc' => 'addBCC',
		) as $kind => $method ) {
			foreach ( $allowed[ $kind ] as $address ) {
				$mailer->$method( $address[0], $address[1] ?? '' );
			}
		}
		$mailer->clearCustomHeader( 'X-Original-To' );
		// Never disclose Bcc addresses to the redirection target or allowlisted recipients.
		$mailer->addCustomHeader( 'X-Original-To', array() !== $visible ? implode( ', ', array_unique( $visible ) ) : 'undisclosed-recipients:;' );
		if ( ! str_starts_with( $mailer->Subject, '[Redirected] ' ) ) {
			$mailer->Subject = '[Redirected] ' . $mailer->Subject;
		}
		return array_values( array_unique( $original ) );
	}
}
