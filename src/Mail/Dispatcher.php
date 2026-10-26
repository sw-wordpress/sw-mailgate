<?php
/**
 * Sends a message through the failover chain.
 *
 * Every connection gets its own clone of the populated mailer, so its sender can be rewritten and the
 * MIME message is rebuilt for it. The chain stops at the first success or at a permanent error, which
 * concerns the message itself and would fail (or duplicate) on every other connection. Connections paused
 * by the circuit breaker are skipped; when the chain is exhausted, the retry queue may take the message.
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
use SWMailgate\Routing\Circuit_Breaker;
use SWMailgate\Routing\Failover_Settings;
use SWMailgate\Routing\Retry_Queue;

final class Dispatcher {

	/** @var string|null Restricts sending to one connection (test mails); null uses the whole chain. */
	private ?string $only = null;

	/** @var list<Attempt> */
	private array $attempts = array();

	/** @var string|null Sender put in place of the WordPress default by filter_from() for the current mail. */
	private ?string $substituted_from = null;

	/** @var list<string> Connections left out of the chain, e.g. the failed one while sending its alert. */
	private array $excluded = array();

	/** @var array<string, mixed>|null Queue entry being retried, see retry(). */
	private ?array $retry = null;

	/** @var string Result of the last dispatch(): sent, queued or failed. */
	private string $outcome = '';

	public function __construct(
		private readonly Connection_Repository $connections,
		private readonly Provider_Registry $providers,
		private readonly ?Circuit_Breaker $breaker = null,
		private readonly ?Retry_Queue $queue = null
	) {
	}

	public function is_active(): bool {
		return null !== $this->only || array() !== $this->connections->chain();
	}

	public function restrict_to( ?string $connection_id ): void {
		$this->only = $connection_id;
	}

	/**
	 * Runs the callback with some connections left out of the chain.
	 *
	 * @param list<string> $connection_ids
	 * @return mixed The callback's return value.
	 */
	public function without( array $connection_ids, callable $callback ): mixed {
		$previous       = $this->excluded;
		$this->excluded = array_values( array_unique( array_merge( $previous, $connection_ids ) ) );
		try {
			return $callback();
		} finally {
			$this->excluded = $previous;
		}
	}

	public function last_outcome(): string {
		return $this->outcome;
	}

	/**
	 * Sends a message from the retry queue again, continuing its attempt group in the log.
	 *
	 * @param array{entry_id: int, group: string, number: int, original_to: array, caller: string, substituted_from: ?string} $context
	 * @return string sent, queued (another retry follows) or failed.
	 */
	public function retry( Mail_Catcher $mailer, array $context ): string {
		$previous_only          = $this->only;
		$previous_from          = $this->substituted_from;
		$this->only             = null;
		$this->retry            = $context;
		$this->substituted_from = $context['substituted_from'] ?? null;
		try {
			$this->dispatch( $mailer, $context['original_to'] );
		} catch ( Exception $e ) {
			$this->outcome = 'failed';
		} finally {
			$this->retry            = null;
			$this->only             = $previous_only;
			$this->substituted_from = $previous_from;
		}
		return $this->outcome;
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
		$this->attempts         = array();
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
	public function dispatch( Mail_Catcher $template, array $original_to = array() ): bool {
		$this->attempts = array();
		$this->outcome  = 'failed';
		$group          = $this->retry['group'] ?? wp_generate_uuid4();
		$offset         = (int) ( $this->retry['number'] ?? 0 );
		$last           = null;
		$caller         = $this->retry['caller'] ?? ( Settings::get()['enabled'] ? Logger::caller() : 'WordPress' );
		$chain          = $this->resolve_chain();
		$last_index     = count( $chain ) - 1;

		foreach ( $chain as $index => $connection ) {
			$mailer   = clone $template;
			$provider = $this->providers->get( $connection->provider() );
			$result   = null === $provider
				/* translators: %s: provider ID */
				? Send_Result::failure( Send_Result::AUTH, sprintf( __( 'Unknown provider "%s".', 'sw-mailgate' ), $connection->provider() ) )
				: $this->attempt( $mailer, $connection, $provider );
			$number = $offset + count( $this->attempts ) + 1;
			$queued = $index === $last_index && $result->allows_failover() && $this->defer( $template, $group, $number, $original_to, $caller, $result->message() );

			$attempt          = new Attempt(
				$group,
				$number,
				$connection,
				$result,
				$mailer->From,
				( new Envelope( $mailer ) )->recipients(),
				$mailer->Subject,
				$original_to,
				$caller,
				$this->payload( $mailer ),
				array_column( $mailer->getReplyToAddresses(), 0 ),
				$queued
			);
			$this->attempts[] = $attempt;

			/**
			 * Fires after every connection attempt, successful or not.
			 *
			 * @param Attempt $attempt
			 */
			do_action( 'sw_mailgate_attempt', $attempt );

			if ( $result->is_success() || $queued ) {
				$this->outcome = $result->is_success() ? 'sent' : 'queued';
				return true;
			}
			$last = $result;
			if ( ! $result->allows_failover() ) {
				break;
			}
		}

		$message = null === $last ? __( 'No enabled mail connection is available.', 'sw-mailgate' ) : $last->message();
		if ( null === $last && null !== $this->retry && $this->defer( $template, $group, $offset, $original_to, $caller, $message ) ) {
			// Every connection was disabled meanwhile: keep the queued message until it expires.
			$this->outcome = 'queued';
			return true;
		}
		throw new Exception( esc_html( $message ) );
	}

	/**
	 * Hands an undeliverable message to the retry queue. Test mails to one connection are never queued.
	 */
	private function defer( Mail_Catcher $template, string $group, int $number, array $original_to, string $caller, string $error ): bool {
		if ( null === $this->queue || ( null !== $this->only && null === $this->retry ) ) {
			return false;
		}
		return $this->queue->defer(
			$template,
			array(
				'group'            => $group,
				'number'           => $number,
				'original_to'      => $original_to,
				'caller'           => $caller,
				'substituted_from' => $this->substituted_from,
				'error'            => $error,
			),
			isset( $this->retry['entry_id'] ) ? (int) $this->retry['entry_id'] : null
		);
	}

	/**
	 * @return Connection[]
	 */
	private function resolve_chain(): array {
		if ( null !== $this->only ) {
			// An explicitly selected connection is used even when it is disabled or paused.
			$connection = $this->connections->find( $this->only );
			return null === $connection ? array() : array( $connection );
		}
		$chain = array_values(
			array_filter( $this->connections->chain(), fn( Connection $connection ): bool => ! in_array( $connection->id(), $this->excluded, true ) )
		);
		return null === $this->breaker ? $chain : $this->breaker->filter( $chain );
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
		if ( $result->is_ambiguous() && $result->allows_failover() && ! Failover_Settings::get()['timeout_failover'] ) {
			$result = $result->reclassify(
				Send_Result::UNCERTAIN,
				$result->message() . ' ' . __( 'The provider did not answer after the message was sent, so it may have been accepted. Automatic failover was stopped to avoid duplicates.', 'sw-mailgate' )
			);
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
