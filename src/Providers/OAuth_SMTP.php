<?php
/**
 * Require XOAUTH2 explicitly: PHPMailer otherwise falls back to password mechanisms.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

class OAuth_SMTP extends Tracked_SMTP {

	private ?int $authentication_error = null;

	/** Preserve authentication failure across PHPMailer's QUIT/close cleanup. */
	public function authentication_error(): ?int {
		return $this->authentication_error;
	}

	public function authenticate( $username, $password, $authtype = null, $OAuth = null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PHPMailer signature.
		$this->authentication_error = null;
		$methods                    = $this->getServerExt( 'AUTH' );
		if ( 'XOAUTH2' !== $authtype || ! is_array( $methods ) || ! in_array( 'XOAUTH2', $methods, true ) ) {
			$this->setError( 'AUTH XOAUTH2 is not supported by this SMTP server' );
			$this->authentication_error = 0;
			return false;
		}
		$result = parent::authenticate( $username, '', 'XOAUTH2', $OAuth ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- PHPMailer signature.
		if ( ! $result ) {
			$this->authentication_error = (int) ( $this->getError()['smtp_code'] ?? 0 );
		}
		if ( ! $result && str_starts_with( $this->getLastReply(), '334' ) ) {
			// Gmail requires an empty response to finish a rejected SASL exchange.
			$this->sendCommand( 'AUTH CANCEL', '', 535 );
			$this->setError( 'AUTH XOAUTH2 failed', '', '535' );
		}
		return $result;
	}
}
