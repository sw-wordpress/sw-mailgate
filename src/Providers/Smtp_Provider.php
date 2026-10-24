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
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$mailer = $envelope->mailer();
		$debug  = array();
		$this->capture_debug( $mailer, $debug );

		try {
			$mailer->postSend();
			return Send_Result::success( $envelope->message_id(), $debug );
		} catch ( Exception $e ) {
			return Send_Result::failure( $this->classify( $mailer ), $e->getMessage(), $debug );
		} finally {
			$mailer->smtpClose();
		}
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
			return Send_Result::failure( $this->classify( $mailer ), $e->getMessage(), $debug );
		} finally {
			$mailer->smtpClose();
		}
	}

	/**
	 * Maps the last SMTP error to an error class. Recipient and content rejections are permanent;
	 * a refused sender or login is a configuration problem of this connection.
	 */
	private function classify( Mail_Catcher $mailer ): string {
		$error   = $mailer->getSMTPInstance()->getError();
		$command = (string) ( $error['error'] ?? '' );
		$code    = (int) ( $error['smtp_code'] ?? 0 );

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
			$line      = trim( $line );
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
