<?php
/**
 * Skips connections that keep failing, so every mail does not wait for the same timeout again.
 *
 * Only transient and login errors count: they describe the connection. Rejected, partial or uncertain
 * messages say nothing about its availability. After `breaker_threshold` consecutive failures the
 * connection is skipped for `breaker_cooldown` minutes; afterwards the next mail tries it again and
 * a single further failure pauses it again. A success closes the breaker.
 *
 * The breaker never blocks sending: when every connection is paused, the whole chain is tried.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Routing;

use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Attempt;
use SWMailgate\Providers\Send_Result;

final class Circuit_Breaker {

	public const OPTION = 'sw_mailgate_health';

	/** Error messages are shown in admin notices and alert mails; keep them short. */
	private const MAX_ERROR_LENGTH = 300;

	/**
	 * @param Connection[] $chain
	 * @return Connection[] The chain without paused connections, or the full chain if all are paused.
	 */
	public function filter( array $chain ): array {
		$available = array_values( array_filter( $chain, fn( Connection $connection ): bool => ! $this->is_open( $connection->id() ) ) );
		return array() === $available ? $chain : $available;
	}

	public function is_open( string $id ): bool {
		return $this->threshold() > 0 && $this->state( $id )['open_until'] > time();
	}

	/**
	 * True while a connection has failed at least `breaker_threshold` times in a row, even after the
	 * pause ended: it has not delivered since.
	 */
	public function is_failing( string $id ): bool {
		$threshold = $this->threshold();
		return $threshold > 0 && $this->state( $id )['failures'] >= $threshold;
	}

	/**
	 * @return array{failures: int, open_until: int, since: int, last_error: string, last_class: string, last_failure: int}
	 */
	public function state( string $id ): array {
		$states = $this->states();
		$state  = isset( $states[ $id ] ) && is_array( $states[ $id ] ) ? $states[ $id ] : array();
		return array(
			'failures'     => (int) ( $state['failures'] ?? 0 ),
			'open_until'   => (int) ( $state['open_until'] ?? 0 ),
			'since'        => (int) ( $state['since'] ?? 0 ),
			'last_error'   => (string) ( $state['last_error'] ?? '' ),
			'last_class'   => (string) ( $state['last_class'] ?? '' ),
			'last_failure' => (int) ( $state['last_failure'] ?? 0 ),
		);
	}

	/**
	 * Hooked to `sw_mailgate_attempt`. Writes only when the state changes, so regular successful
	 * mails cost no database write.
	 */
	public function record( Attempt $attempt ): void {
		$id     = $attempt->connection->id();
		$result = $attempt->result;

		if ( $result->is_success() ) {
			$this->close( $attempt->connection );
			return;
		}
		if ( ! in_array( $result->error_class(), array( Send_Result::TRANSIENT, Send_Result::AUTH ), true ) ) {
			return;
		}

		$threshold = $this->threshold();
		if ( $threshold <= 0 ) {
			return;
		}
		$state    = $this->state( $id );
		$was_down = $state['failures'] >= $threshold;
		$now      = time();

		++$state['failures'];
		$state['since']        = 1 === $state['failures'] ? $now : $state['since'];
		$state['last_error']   = mb_substr( $result->message(), 0, self::MAX_ERROR_LENGTH );
		$state['last_class']   = $result->error_class();
		$state['last_failure'] = $now;
		if ( $state['failures'] >= $threshold ) {
			$state['open_until'] = $now + $this->cooldown();
		}
		$this->store( $id, $state );

		if ( ! $was_down && $state['failures'] >= $threshold ) {
			/**
			 * Fires when a connection reached the failure threshold and is paused.
			 *
			 * @param Connection $connection
			 * @param array      $state      See state().
			 */
			do_action( 'sw_mailgate_connection_down', $attempt->connection, $state );
		}
	}

	/**
	 * Forgets the failures of a connection, e.g. after a successful connection test or a changed configuration.
	 */
	public function close( Connection $connection ): void {
		$state = $this->state( $connection->id() );
		if ( 0 === $state['failures'] && 0 === $state['open_until'] ) {
			return;
		}
		$threshold = $this->threshold();
		$this->forget( $connection->id() );
		if ( $threshold > 0 && $state['failures'] >= $threshold ) {
			/**
			 * Fires when a paused connection delivered again or was reset.
			 *
			 * @param Connection $connection
			 * @param array      $state      The state before recovery, see state().
			 */
			do_action( 'sw_mailgate_connection_recovered', $connection, $state );
		}
	}

	public function forget( string $id ): void {
		$states = $this->states();
		if ( isset( $states[ $id ] ) ) {
			unset( $states[ $id ] );
			update_option( self::OPTION, $states, false );
		}
	}

	private function store( string $id, array $state ): void {
		$states        = $this->states();
		$states[ $id ] = $state;
		update_option( self::OPTION, $states, false );
	}

	private function states(): array {
		$states = get_option( self::OPTION, array() );
		return is_array( $states ) ? $states : array();
	}

	private function threshold(): int {
		return (int) Failover_Settings::get()['breaker_threshold'];
	}

	private function cooldown(): int {
		return (int) Failover_Settings::get()['breaker_cooldown'] * MINUTE_IN_SECONDS;
	}
}
