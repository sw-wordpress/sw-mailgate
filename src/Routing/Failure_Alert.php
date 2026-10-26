<?php
/**
 * Optional alert mails when a connection is paused, recovers, or a queued message is given up.
 *
 * Alerts are sent at the end of the request: the events fire while the dispatcher is still working on
 * the current mail, and wp_mail() would reuse (and reset) the global mailer it is sending. A paused
 * connection is left out of the chain for its own alert.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Routing;

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Dispatcher;

final class Failure_Alert {

	/** @var list<array{subject: string, body: string, exclude: list<string>}> */
	private array $pending = array();

	/** @var bool Failures while sending alerts must not queue further alerts. */
	private bool $sending = false;

	public function __construct( private readonly Dispatcher $dispatcher ) {
	}

	public function register(): void {
		add_action( 'sw_mailgate_connection_down', array( $this, 'connection_down' ), 10, 2 );
		add_action( 'sw_mailgate_connection_recovered', array( $this, 'connection_recovered' ) );
		add_action( 'sw_mailgate_queue_expired', array( $this, 'queue_expired' ) );
	}

	public function connection_down( Connection $connection, array $state ): void {
		$this->add(
			/* translators: 1: site name, 2: connection label */
			sprintf( __( '[%1$s] Mail connection "%2$s" is failing', 'sw-mailgate' ), $this->site(), $connection->label() ),
			array(
				/* translators: 1: connection label, 2: number of failures, 3: date and time */
				sprintf( __( 'The mail connection "%1$s" failed %2$d times in a row and is skipped until %3$s. Other connections in the failover chain send the mail meanwhile.', 'sw-mailgate' ), $connection->label(), $state['failures'], wp_date( 'Y-m-d H:i', $state['open_until'] ) ),
				/* translators: %s: error message */
				sprintf( __( 'Last error: %s', 'sw-mailgate' ), $state['last_error'] ),
			),
			array( $connection->id() )
		);
	}

	public function connection_recovered( Connection $connection ): void {
		$this->add(
			/* translators: 1: site name, 2: connection label */
			sprintf( __( '[%1$s] Mail connection "%2$s" works again', 'sw-mailgate' ), $this->site(), $connection->label() ),
			/* translators: %s: connection label */
			array( sprintf( __( 'The mail connection "%s" delivered a message again (or was reset) and is back in the failover chain.', 'sw-mailgate' ), $connection->label() ) ),
			array()
		);
	}

	public function queue_expired( array $summary ): void {
		$this->add(
			/* translators: %s: site name */
			sprintf( __( '[%s] A queued mail could not be delivered', 'sw-mailgate' ), $this->site() ),
			array(
				/* translators: 1: subject, 2: number of recipients, 3: date and time (UTC) */
				sprintf( __( 'The message "%1$s" to %2$d recipient(s), queued at %3$s UTC, could not be delivered by any connection and was discarded.', 'sw-mailgate' ), $summary['subject'], $summary['recipients'], $summary['created_at'] ),
				/* translators: %s: error message */
				sprintf( __( 'Last error: %s', 'sw-mailgate' ), $summary['last_error'] ),
			),
			array()
		);
	}

	/**
	 * Sends the collected alerts. Hooked to `shutdown`; also callable directly (tests, cron).
	 */
	public function flush(): void {
		$recipient     = (string) Failover_Settings::get()['alert_email'];
		$pending       = $this->pending;
		$this->pending = array();
		if ( '' === $recipient || array() === $pending ) {
			return;
		}
		$this->sending = true;
		try {
			foreach ( $pending as $alert ) {
				$this->dispatcher->without(
					$alert['exclude'],
					static fn(): bool => wp_mail( $recipient, $alert['subject'], $alert['body'] )
				);
			}
		} finally {
			$this->sending = false;
		}
	}

	/**
	 * @param list<string> $lines
	 * @param list<string> $exclude
	 */
	private function add( string $subject, array $lines, array $exclude ): void {
		if ( $this->sending || '' === Failover_Settings::get()['alert_email'] ) {
			return;
		}
		$lines[] = '';
		/* translators: %s: site URL */
		$lines[] = sprintf( __( 'Site: %s', 'sw-mailgate' ), home_url() );
		/* translators: %s: admin URL */
		$lines[] = sprintf( __( 'Connections: %s', 'sw-mailgate' ), Admin_Page::url() );

		if ( array() === $this->pending ) {
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
		$this->pending[] = array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ),
			'exclude' => $exclude,
		);
	}

	private function site(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
