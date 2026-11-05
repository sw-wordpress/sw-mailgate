<?php
/** Atomic replay claims, durable delivery events and log correlation.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Tracking;

use SWMailgate\Connections\Connection;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Owned event table; atomic replay checks cannot use an object cache.
final class Event_Repository {
	public const CRON_HOOK = 'sw_mailgate_reconcile_events';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sw_mailgate_events';
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/** @param list<array{id: string, message_id: string, status: string}> $events */
	public function accept( Connection $connection, string $token, array $events ): bool {
		global $wpdb;
		$stored   = array();
		$accepted = $this->transaction(
			function () use ( $connection, $token, $events, &$stored, $wpdb ): void {
				$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (token_hash, created_at, connection_id, provider, provider_message_hash, log_id, status) VALUES (%s, %s, %s, %s, %s, 0, %s)', self::table(), $token, gmdate( 'Y-m-d H:i:s' ), $connection->id(), '', '', '' ) );
				$this->require_success( $insert );
				if ( 1 !== $insert || ! Settings::get()['enabled'] ) {
						return;
				}
				foreach ( $events as $event ) {
					$key    = hash( 'sha256', 'event:' . $connection->id() . ':' . $connection->provider() . ':' . $event['id'] );
					$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (token_hash, created_at, connection_id, provider, provider_message_hash, log_id, status) VALUES (%s, %s, %s, %s, %s, 0, %s)', self::table(), $key, gmdate( 'Y-m-d H:i:s' ), $connection->id(), $connection->provider(), Log_Repository::message_hash( $event['message_id'] ), $event['status'] ) );
					$this->require_success( $insert );
					if ( 1 === $insert ) {
						$stored[] = $event;
					}
				}
			}
		);
		if ( ! $accepted ) {
			return false;
		}
		// Commit before correlation: delivery webhooks are not retried by Mailgun.
		foreach ( $stored as $event ) {
			$this->reconcile( $connection->id(), $connection->provider(), Log_Repository::message_hash( $event['message_id'] ), $event['message_id'] );
		}
		return true;
	}

	/** Link pending events after either side of the webhook/log race commits. */
	public function reconcile( string $connection, string $provider, string $hash, string $message_id = '' ): bool {
		global $wpdb;
		if ( '' === $hash || ! Settings::get()['enabled'] ) {
			return true;
		}
		$previous = $wpdb->suppress_errors( true );
		try {
			$id  = trim( $message_id, '<>' );
			$log = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM %i WHERE connection_id = %s AND provider = %s AND (provider_message_hash = %s OR (%s <> '' AND provider_message_id IN (%s, %s))) ORDER BY id DESC LIMIT 1", Log_Repository::table(), $connection, $provider, $hash, $id, $id, '<' . $id . '>' ), ARRAY_A );
			$this->require_success( '' === $wpdb->last_error );
			if ( null === $log ) {
				return true;
			}
			$pending = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM %i WHERE connection_id = %s AND provider = %s AND provider_message_hash = %s AND log_id = 0 AND status <> '' ORDER BY id LIMIT 500", self::table(), $connection, $provider, $hash ), ARRAY_A );
			$this->require_success( '' === $wpdb->last_error );
			foreach ( $pending as $event ) {
				// Each statement updates the event and its log atomically; never commit a mail caller's transaction.
				$this->require_success(
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE %i e INNER JOIN %i l ON l.id = %d SET e.log_id = l.id, l.status = CASE WHEN l.status = 'complained' OR e.status = 'complained' THEN 'complained' WHEN l.status = 'bounced' OR e.status = 'bounced' THEN 'bounced' WHEN e.status = 'delivered' THEN 'delivered' ELSE l.status END WHERE e.id = %d AND e.log_id = 0",
							self::table(),
							Log_Repository::table(),
							$log['id'],
							$event['id']
						)
					)
				);
			}
			return true;
		} catch ( \Throwable $error ) {
			return false; // Durable pending metadata remains available for the next correlation attempt.
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Bounded recovery for a failed correlation or an overlapping log insert. */
	public function reconcile_pending(): void {
		global $wpdb;
		if ( ! Settings::get()['enabled'] ) {
			return;
		}
		$previous = $wpdb->suppress_errors( true );
		try {
			$ready = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT e.connection_id, e.provider, e.provider_message_hash FROM %i e INNER JOIN %i l ON l.connection_id = e.connection_id AND l.provider = e.provider AND l.provider_message_hash = e.provider_message_hash WHERE e.log_id = 0 AND e.status <> '' LIMIT 100", self::table(), Log_Repository::table() ), ARRAY_A );
			foreach ( $ready as $row ) {
				$this->reconcile( $row['connection_id'], $row['provider'], $row['provider_message_hash'] );
			}
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** @param callable(): void $work */
	private function transaction( callable $work ): bool {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$this->require_success( $wpdb->query( 'START TRANSACTION' ) );
			$work();
			$this->require_success( $wpdb->query( 'COMMIT' ) );
			return true;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** @throws \RuntimeException Caught locally; never exposed in mail or webhook output. */
	private function require_success( int|bool $result ): void {
		if ( false === $result ) {
			throw new \RuntimeException( 'Event storage failed.' );
		}
	}

	public static function retain(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE e FROM %i e LEFT JOIN %i l ON e.log_id = l.id WHERE (e.log_id <> 0 AND l.id IS NULL) OR e.created_at < %s OR (e.log_id = 0 AND e.created_at < %s)', self::table(), Log_Repository::table(), gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) Settings::get()['retention'] ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}
}
