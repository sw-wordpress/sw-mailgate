<?php
/** Atomic replay claims and minimal delivery metadata.
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
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sw_mailgate_events';
	}

	/**
	 * @param list<array{id: string, message_id: string, status: string}> $events
	 * @throws \RuntimeException Caught locally; failed transactions always request a retry.
	 */
	public function accept( Connection $connection, string $token, array $events ): bool {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return false;
			}
			$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (token_hash, created_at, connection_id, log_id, status) VALUES (%s, %s, %s, 0, %s)', self::table(), $token, gmdate( 'Y-m-d H:i:s' ), $connection->id(), '' ) );
			if ( false === $insert ) {
				throw new \RuntimeException( 'Replay storage failed.' );
			}
			if ( 1 === $insert && Settings::get()['enabled'] ) {
				foreach ( $events as $event ) {
					$id  = trim( $event['message_id'], '<>' );
					$log = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM %i WHERE connection_id = %s AND provider = %s AND (provider_message_hash = %s OR provider_message_id IN (%s, %s)) ORDER BY id DESC LIMIT 1 FOR UPDATE', Log_Repository::table(), $connection->id(), $connection->provider(), Log_Repository::message_hash( $id ), $id, '<' . $id . '>' ), ARRAY_A );
					if ( '' !== $wpdb->last_error ) {
						throw new \RuntimeException( 'Log lookup failed.' );
					}
					if ( null === $log ) {
						// A fast delivery webhook can arrive before Logger writes the attempt.
						throw new \RuntimeException( 'Log entry not yet available.' );
					}
					$event_key    = hash( 'sha256', 'event:' . $connection->id() . ':' . $event['id'] );
					$event_insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (token_hash, created_at, connection_id, log_id, status) VALUES (%s, %s, %s, %d, %s)', self::table(), $event_key, gmdate( 'Y-m-d H:i:s' ), $connection->id(), $log['id'], $event['status'] ) );
					if ( false === $event_insert ) {
						throw new \RuntimeException( 'Event storage failed.' );
					}
					if ( 0 === $event_insert ) {
						continue; // Same provider event with a fresh request token.
					}
					$rank = array(
						'delivered'  => 1,
						'bounced'    => 2,
						'complained' => 3,
					);
					if ( ( $rank[ $log['status'] ] ?? 0 ) < $rank[ $event['status'] ] && false === $wpdb->update( Log_Repository::table(), array( 'status' => $event['status'] ), array( 'id' => $log['id'] ) ) ) {
						throw new \RuntimeException( 'Log update failed.' );
					}
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Commit failed.' );
			}
			return true; // Retries are acknowledged, without replaying their effects.
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function retain(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE e FROM %i e LEFT JOIN %i l ON e.log_id = l.id WHERE (e.log_id <> 0 AND l.id IS NULL) OR e.created_at < %s', self::table(), Log_Repository::table(), gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) Settings::get()['retention'] ) * DAY_IN_SECONDS ) ) );
	}
}
