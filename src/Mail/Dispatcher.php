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

final class Dispatcher {

	/** @var string|null Restricts sending to one connection (test mails); null uses the whole chain. */
	private ?string $only = null;

	/** @var list<Attempt> */
	private array $attempts = array();

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
				$mailer->Subject
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
		$is_default    = '' === $original || str_starts_with( strtolower( $original ), 'wordpress@' );
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
