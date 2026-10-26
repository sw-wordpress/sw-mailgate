<?php
/**
 * Plain SMTP via the PHPMailer bundled with WordPress.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;

final class Smtp_Provider implements Provider {

	/** Keep the transcript short; it is shown in the admin and may end up in the log. */
	private const MAX_DEBUG_LINES = 60;

	public function id(): string {
		return 'smtp';
	}

	public function label(): string {
		return __( 'SMTP', 'sw-mailgate' );
	}

	public function fields(): array {
		return array(
			new Field( 'host', __( 'SMTP host', 'sw-mailgate' ), Field::TEXT, false, '', array(), '', true ),
			new Field( 'port', __( 'Port', 'sw-mailgate' ), Field::NUMBER, false, '587', array(), __( 'Usually 587 (STARTTLS) or 465 (SSL/TLS).', 'sw-mailgate' ), true ),
			new Field(
				'encryption',
				__( 'Encryption', 'sw-mailgate' ),
				Field::SELECT,
				false,
				'tls',
				array(
					'tls'  => __( 'STARTTLS', 'sw-mailgate' ),
					'ssl'  => __( 'SSL/TLS (implicit)', 'sw-mailgate' ),
					'none' => __( 'None (not recommended)', 'sw-mailgate' ),
				)
			),
			new Field(
				'tls_verify',
				__( 'Verify TLS certificate', 'sw-mailgate' ),
				Field::CHECKBOX,
				false,
				'1',
				array(),
				__( 'Recommended. Only disable it for trusted networks (e.g. a VPN to the mail server) when the certificate does not match the host. The connection stays encrypted, but the server identity is not checked.', 'sw-mailgate' ),
				false_values: array( '0' )
			),
			new Field(
				'tls_peer_name',
				__( 'Certificate host name', 'sw-mailgate' ),
				Field::TEXT,
				false,
				'',
				array(),
				__( 'Only needed when the host is an IP address or an alias: the name the server certificate is issued for, e.g. mail.example.com. The certificate is still fully verified against this name.', 'sw-mailgate' )
			),
			new Field( 'auth', __( 'Authentication', 'sw-mailgate' ), Field::CHECKBOX, false, '1' ),
			new Field( 'username', __( 'Username', 'sw-mailgate' ) ),
			Field::secret( 'password', __( 'Password', 'sw-mailgate' ), '', false ),
			new Field( 'timeout', __( 'Timeout (seconds)', 'sw-mailgate' ), Field::NUMBER, false, '15' ),
		);
	}

	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {
		$encryption = $connection->get( 'encryption', 'tls' );

		$mailer->isSMTP();
		$mailer->Host        = $connection->get( 'host' );
		$mailer->Port        = (int) $connection->get( 'port', '587' );
		$mailer->SMTPSecure  = 'none' === $encryption ? '' : $encryption;
		$mailer->SMTPAutoTLS = 'none' !== $encryption;
		$mailer->SMTPAuth    = $connection->bool( 'auth' );
		$mailer->Username    = $connection->get( 'username' );
		$mailer->Password    = $connection->get( 'password' );
		$mailer->Timeout     = max( 5, (int) $connection->get( 'timeout', '15' ) );
		$mailer->setSMTPInstance( new Tracked_SMTP() );

		// Keep trust stores and other compatible context options, but enforce this connection's policy.
		// Connections saved before this option existed have no value: they keep verifying.
		$verify = '0' !== $connection->get( 'tls_verify', '1' );
		$ssl    = $mailer->SMTPOptions['ssl'] ?? array();
		$ssl    = is_array( $ssl ) ? $ssl : array();

		$ssl['verify_peer']       = $verify;
		$ssl['verify_peer_name']  = $verify;
		$ssl['allow_self_signed'] = ! $verify;

		// An empty override uses the SMTP host, never a peer name inherited from another integration.
		unset( $ssl['peer_name'] );
		$peer_name = $connection->get( 'tls_peer_name' );
		if ( '' !== $peer_name ) {
			$ssl['peer_name'] = $peer_name;
		}
		$mailer->SMTPOptions['ssl'] = $ssl;
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$mailer = $envelope->mailer();
		$debug  = array();
		$this->capture_debug( $mailer, $debug );
		$smtp = $mailer->getSMTPInstance();
		if ( $smtp instanceof Tracked_SMTP ) {
			$smtp->reset_tracking();
		}

		try {
			if ( ! $mailer->postSend() ) {
				return $this->delivery_failure( $mailer, $connection, $mailer->ErrorInfo, $debug );
			}
			return Send_Result::success( $envelope->message_id(), $debug );
		} catch ( \Throwable $e ) {
			return $this->delivery_failure( $mailer, $connection, $e->getMessage(), $debug );
		} finally {
			$this->close_mailer( $mailer );
		}
	}

	/** Resolve delivery certainty before examining transient/connection errors. */
	private function delivery_failure( Mail_Catcher $mailer, Connection $connection, string $message, array $debug ): Send_Result {
		$smtp = $mailer->getSMTPInstance();
		if ( ! $smtp instanceof Tracked_SMTP ) {
			return Send_Result::failure( Send_Result::UNCERTAIN, __( 'SMTP delivery could not be tracked. Acceptance is unknown; automatic failover was stopped to avoid duplicates.', 'sw-mailgate' ), $debug );
		}
		$outcome = $smtp->outcome();
		if ( $outcome['delivered'] ) {
			if ( array() !== $outcome['rejected'] ) {
				return Send_Result::failure( Send_Result::PARTIAL, __( 'SMTP accepted the message for some recipients and rejected others. Automatic failover was stopped to avoid duplicate delivery; check recipient outcomes before retrying.', 'sw-mailgate' ), $debug );
			}
			// A callback/cleanup failure after acceptance must not cause a duplicate delivery.
			return Send_Result::success( $mailer->getLastMessageID(), $debug );
		}
		if ( $outcome['submitted'] && ( $outcome['data_code'] < 400 || $outcome['data_code'] >= 600 ) ) {
			return Send_Result::failure( Send_Result::UNCERTAIN, __( 'SMTP did not confirm acceptance after message submission. Delivery is uncertain; automatic failover was stopped to avoid duplicates.', 'sw-mailgate' ), $debug );
		}
		foreach ( $outcome['rejected'] as $code ) {
			if ( $code >= 500 ) {
				return Send_Result::failure( Send_Result::PERMANENT, __( 'SMTP permanently rejected one or more recipients. Automatic failover was stopped.', 'sw-mailgate' ), $debug );
			}
		}
		if ( $outcome['data_code'] >= 400 ) {
			return Send_Result::failure( $outcome['data_code'] >= 500 ? Send_Result::PERMANENT : Send_Result::TRANSIENT, __( 'SMTP rejected message data before accepting delivery.', 'sw-mailgate' ), $debug );
		}
		if ( array() !== $outcome['rejected'] ) {
			return Send_Result::failure( Send_Result::TRANSIENT, __( 'SMTP temporarily rejected the recipients before accepting delivery.', 'sw-mailgate' ), $debug );
		}
		return $this->failure( $mailer, $connection, $message, $debug );
	}

	public function test_connection( Connection $connection ): Send_Result {
		$mailer = new Mail_Catcher( true );
		$this->prepare( $mailer, $connection );
		$debug = array();
		$this->capture_debug( $mailer, $debug );

		try {
			$mailer->smtpConnect();
			return Send_Result::success( '', $debug );
		} catch ( Exception $e ) {
			return $this->failure( $mailer, $connection, $e->getMessage(), $debug );
		} finally {
			$this->close_mailer( $mailer );
		}
	}

	/** Cleanup errors must not replace an already established delivery result. */
	private function close_mailer( Mail_Catcher $mailer ): void {
		try {
			$mailer->smtpClose();
		} catch ( \Throwable $error ) {
			try {
				$mailer->getSMTPInstance()->close();
			} catch ( \Throwable $close_error ) {
				return;
			}
		}
	}

	/**
	 * @param list<string> $debug
	 */
	private function failure( Mail_Catcher $mailer, Connection $connection, string $message, array $debug ): Send_Result {
		if ( str_contains( $message, 'did not match expected CN' ) && '' === $connection->get( 'tls_peer_name' ) ) {
			$message .= ' ' . __( 'The server certificate is issued for a different name than the configured host. Enter that name as "Certificate host name", or disable "Verify TLS certificate" for a trusted network such as a VPN.', 'sw-mailgate' );
		}
		return Send_Result::failure( $this->classify( $mailer, $message ), $message, $debug );
	}

	/**
	 * Maps the last SMTP error to an error class. Recipient and content rejections are permanent;
	 * a refused sender or login and TLS/certificate errors are configuration problems of this connection.
	 */
	private function classify( Mail_Catcher $mailer, string $message ): string {
		$error   = $mailer->getSMTPInstance()->getError();
		$command = (string) ( $error['error'] ?? '' );
		$code    = (int) ( $error['smtp_code'] ?? 0 );

		if ( preg_match( '/certificate|stream_socket_enable_crypto|SSL operation failed|TLS/i', $message ) ) {
			return Send_Result::AUTH;
		}
		if ( $code >= 400 && $code < 500 ) {
			return Send_Result::TRANSIENT;
		}
		if ( str_contains( $command, 'RCPT TO' ) || ( str_contains( $command, 'DATA' ) && $code >= 500 ) ) {
			return Send_Result::PERMANENT;
		}
		if (
			in_array( $code, array( 530, 534, 535, 538 ), true )
			|| str_contains( $command, 'AUTH' )
			|| str_contains( $command, 'MAIL FROM' )
			|| str_contains( $command, 'Password' )
			|| str_contains( $command, 'Username' )
			|| str_contains( $command, 'STARTTLS' )
		) {
			return Send_Result::AUTH;
		}
		return Send_Result::TRANSIENT;
	}

	/**
	 * Collects the SMTP dialogue without credentials (AUTH exchange) and without message content (DATA).
	 *
	 * @param list<string> $lines Receives the transcript.
	 */
	private function capture_debug( Mail_Catcher $mailer, array &$lines ): void {
		$state               = 'normal';
		$mailer->SMTPDebug   = SMTP::DEBUG_SERVER;
		$mailer->Debugoutput = static function ( string $line ) use ( &$lines, &$state ): void {
			$line = trim( $line );
			// After a failed TLS handshake the server may answer with raw TLS records.
			if ( ! mb_check_encoding( $line, 'UTF-8' ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line ) ) {
				$prefix = preg_match( '/^(CLIENT -> SERVER:|SERVER -> CLIENT:|SMTP ERROR: [A-Za-z ]+:)/', $line, $m ) ? $m[1] . ' ' : '';
				$line   = $prefix . '[binary data omitted]';
			}
			$is_client = str_starts_with( $line, 'CLIENT -> SERVER:' );
			$is_server = str_starts_with( $line, 'SERVER -> CLIENT:' );
			$payload   = trim( (string) substr( $line, 17 ) );

			if ( 'auth' === $state && $is_server && preg_match( '/^(235|5\d\d)/', $payload ) ) {
				$state = 'normal';
			} elseif ( 'data' === $state && $is_server && preg_match( '/^\d{3} /', $payload ) && ! str_starts_with( $payload, '354' ) ) {
				$state   = 'normal';
				$lines[] = 'CLIENT -> SERVER: [message content omitted]';
			}

			if ( $is_client && 'auth' === $state ) {
				return;
			}
			if ( $is_client && 'data' === $state ) {
				return;
			}
			if ( $is_client && preg_match( '/^AUTH\s+(\S+)/i', $payload, $m ) ) {
				$line  = 'CLIENT -> SERVER: AUTH ' . $m[1] . ' [credentials omitted]';
				$state = 'auth';
			} elseif ( $is_client && 'DATA' === strtoupper( $payload ) ) {
				$state = 'data';
			}

			if ( count( $lines ) < self::MAX_DEBUG_LINES ) {
				$lines[] = $line;
			}
		};
	}
}
