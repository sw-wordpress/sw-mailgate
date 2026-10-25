<?php
/**
 * Sends a message through the failover chain.
 *
 * Every connection gets its own clone of the populated mailer, so its sender can be rewritten and the
 * MIME message is rebuilt for it. The chain stops at the first success or at a permanent error, which
 * concerns the message itself and would fail (or duplicate) on every other connection.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

use PHPMailer\PHPMailer\Exception;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Providers\Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Logging\Logger;
use SWMailgate\Logging\Settings;

final class Dispatcher {

	/** @var string|null Restricts sending to one connection (test mails); null uses the whole chain. */
	private ?string $only = null;

	/** @var list<Attempt> */
	private array $attempts = array();

	/** @var string|null Sender put in place of the WordPress default by filter_from() for the current mail. */
	private ?string $substituted_from = null;

	public function __construct(
		private readonly Connection_Repository $connections,
		private readonly Provider_Registry $providers
	) {
	}

	public function is_active(): bool {
		return null !== $this->only || array() !== $this->connections->chain();
	}

	public function restrict_to( ?string $connection_id ): void {
		$this->only = $connection_id;
	}

	/**
	 * Hooked to the `wp_mail` filter, which runs first in every wp_mail() call: forgets the state of the
	 * previous mail.
	 *
	 * @param array<string, mixed> $atts
	 * @return array<string, mixed>
	 */
	public function begin_mail( $atts ) {
		$this->substituted_from = null;
		return $atts;
	}

	/**
	 * Hooked late to `wp_mail_from`. wp_mail() passes the sender to setFrom() before send() runs, and
	 * the default "wordpress@<host>" is rejected there on hosts without a dot (e.g. "localhost"). So
	 * the default is replaced with the sender of the first connection already here. apply_sender()
	 * still sets the sender of each connection that is tried.
	 *
	 * @param string $from
	 * @return string
	 */
	public function filter_from( $from ) {
		$from = (string) $from;
		if ( ! $this->is_active() || ( ! self::is_default_from( $from ) && is_email( $from ) ) ) {
			return $from;
		}
		foreach ( $this->resolve_chain() as $connection ) {
			$candidate = $connection->from_email();
			if ( '' !== $candidate && is_email( $candidate ) ) {
				$this->substituted_from = $candidate;
				return $candidate;
			}
		}
		return $from;
	}

	private static function is_default_from( string $from ): bool {
		return '' === $from || str_starts_with( strtolower( $from ), 'wordpress@' );
	}

	/**
	 * Attempts of the most recent dispatch() call.
	 *
	 * @return list<Attempt>
	 */
	public function last_attempts(): array {
		return $this->attempts;
	}

	/**
	 * @throws Exception When no connection delivered the message.
	 */
	public function dispatch( Mail_Catcher $template ): bool {
		$this->attempts = array();
		$group          = wp_generate_uuid4();
		$last           = null;
		$caller         = Settings::get()['enabled'] ? Logger::caller() : 'WordPress';

		foreach ( $this->resolve_chain() as $connection ) {
			$mailer   = clone $template;
			$provider = $this->providers->get( $connection->provider() );
			$result   = null === $provider
				/* translators: %s: provider ID */
				? Send_Result::failure( Send_Result::AUTH, sprintf( __( 'Unknown provider "%s".', 'sw-mailgate' ), $connection->provider() ) )
				: $this->attempt( $mailer, $connection, $provider );

			$attempt          = new Attempt(
				$group,
				count( $this->attempts ) + 1,
				$connection,
				$result,
				$mailer->From,
				( new Envelope( $mailer ) )->recipients(),
				$mailer->Subject,
				array(),
				$caller,
				$this->payload( $mailer ),
				array_column( $mailer->getReplyToAddresses(), 0 )
			);
			$this->attempts[] = $attempt;

			/**
			 * Fires after every connection attempt, successful or not.
			 *
			 * @param Attempt $attempt
			 */
			do_action( 'sw_mailgate_attempt', $attempt );

			if ( $result->is_success() ) {
				return true;
			}
			$last = $result;
			if ( ! $result->allows_failover() ) {
				break;
			}
		}

		throw new Exception(
			null === $last
				? esc_html__( 'No enabled mail connection is available.', 'sw-mailgate' )
				: esc_html( $last->message() )
		);
	}

	/**
	 * @return Connection[]
	 */
	private function resolve_chain(): array {
		if ( null === $this->only ) {
			return $this->connections->chain();
		}
		$connection = $this->connections->find( $this->only );
		return null === $connection ? array() : array( $connection );
	}

	/** Store only message fields, never SMTP configuration or arbitrary custom headers. */
	private function payload( Mail_Catcher $mailer ): ?array {
		$settings = Settings::get();
		if ( ! $settings['enabled'] || ! $settings['store_body'] || $settings['mask_recipients'] ) {
			return null;
		}
		return array(
			'to'           => $mailer->getToAddresses(),
			'cc'           => $mailer->getCcAddresses(),
			'bcc'          => $mailer->getBccAddresses(),
			'reply_to'     => array_values( $mailer->getReplyToAddresses() ),
			'from'         => $mailer->From,
			'from_name'    => $mailer->FromName,
			'body'         => $mailer->Body,
			'alt_body'     => $mailer->AltBody,
			'content_type' => $mailer->ContentType,
			'charset'      => $mailer->CharSet,
			'attachments'  => array() !== $mailer->getAttachments(),
		);
	}

	private function attempt( Mail_Catcher $mailer, Connection $connection, Provider $provider ): Send_Result {
		$start = microtime( true );
		try {
			$result = $this->apply_sender( $mailer, $connection );
			if ( null === $result ) {
				// SMTP mode yields CRLF line endings and a complete header set (To, Subject, no Bcc).
				// HTTP providers only take the MIME message and never call postSend().
				$mailer->isSMTP();
				$provider->prepare( $mailer, $connection );
				$mailer->preSend();
				$result = $provider->send( new Envelope( $mailer ), $connection );
			}
		} catch ( Exception $e ) {
			// preSend() rejects the message itself: no recipient, invalid address, empty body.
			$result = Send_Result::failure( Send_Result::PERMANENT, $e->getMessage() );
		} catch ( \Throwable $e ) {
			// A provider bug must not block the next connection.
			$result = Send_Result::failure( Send_Result::TRANSIENT, $e->getMessage() );
		}
		return $result->with_duration( microtime( true ) - $start );
	}

	/**
	 * Rewrites the sender to the connection's verified address. Providers usually only accept senders of
	 * their own domain, so a fallback connection needs its own From address.
	 *
	 * @return Send_Result|null A failure when the configured sender is invalid, null otherwise.
	 */
	private function apply_sender( Mail_Catcher $mailer, Connection $connection ): ?Send_Result {
		$from = $connection->from_email();
		if ( '' === $from ) {
			return null;
		}
		if ( ! is_email( $from ) ) {
			return Send_Result::failure(
				Send_Result::AUTH,
				/* translators: %s: email address */
				sprintf( __( 'The sender address "%s" of this connection is invalid.', 'sw-mailgate' ), $from )
			);
		}

		$original      = $mailer->From;
		$original_name = $mailer->FromName;
		$is_default    = self::is_default_from( $original )
			|| ( null !== $this->substituted_from && 0 === strcasecmp( $original, $this->substituted_from ) );
		if ( ! $is_default && ! $connection->forces_from() ) {
			return null;
		}

		$name = '' !== $connection->from_name() ? $connection->from_name() : $original_name;
		$mailer->setFrom( $from, $name, false );

		if (
			! $is_default
			&& $connection->reply_to_original()
			&& 0 !== strcasecmp( $original, $from )
			&& array() === $mailer->getReplyToAddresses()
		) {
			$mailer->addReplyTo( $original, $original_name );
		}
		return null;
	}
}
