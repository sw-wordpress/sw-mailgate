<?php
/**
 * Optional retry queue for messages that no connection could deliver because of temporary or login errors.
 *
 * The message data (recipients, headers, body, attachments) is stored encrypted with the Secret_Store key,
 * not a finished MIME message: every retry runs through the chain again, so each connection still applies
 * its own sender. Entries are deleted after delivery, a final failure or expiry.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Routing;

use SWMailgate\Logging\Log_Repository;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Mailer_Factory;
use SWMailgate\Security\Secret_Store;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This class owns a custom table; claims and deletes must take effect immediately.

final class Retry_Queue {

	public const CRON_HOOK = 'sw_mailgate_process_queue';
	public const SCHEDULE  = 'sw_mailgate_five_minutes';

	/** Larger messages are not queued: the encrypted row must stay below common max_allowed_packet limits. */
	public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

	/** Minutes until the next retry, by the number of retries already made. */
	private const BACKOFF = array( 5, 15, 30, 60, 120, 240 );

	private const BATCH = 10;

	/** A worker that crashed releases its claim after this many seconds. */
	private const LOCK_SECONDS = 300;

	public function __construct( private readonly Secret_Store $secrets ) {
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sw_mailgate_queue';
	}

	public function register( Dispatcher $dispatcher ): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minutes is the shortest retry delay; the event only exists while messages wait.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::CRON_HOOK, fn() => $this->process( $dispatcher ) );
	}

	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (SW Mailgate retry queue)', 'sw-mailgate' ),
		);
		return $schedules;
	}

	/**
	 * Called by the dispatcher when the chain is exhausted with errors that allow failover.
	 *
	 * @param array{group: string, number: int, original_to: array, caller: string, substituted_from: ?string, error: string} $meta
	 * @param int|null                                                                                                        $entry_id Set when the message already comes from the queue.
	 * @return bool True when the message waits for another retry.
	 */
	public function defer( Mail_Catcher $template, array $meta, ?int $entry_id ): bool {
		if ( null !== $entry_id ) {
			return $this->reschedule( $entry_id, $meta['number'], $meta['error'] );
		}
		if ( ! Failover_Settings::get()['queue_enabled'] ) {
			return false;
		}
		try {
			return $this->enqueue( $template, $meta );
		} catch ( \Throwable $error ) {
			// Never let the queue change a delivery result; without an entry the mail simply fails.
			return false;
		}
	}

	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * @return array{subject: string, recipients: int, created_at: string, next_run_at: string, retries: int, last_error: string}[]
	 */
	public function overview( int $limit = 20 ): array {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY next_run_at LIMIT %d', self::table(), $limit ), ARRAY_A );
		$entries = array();
		foreach ( $rows as $row ) {
			$payload   = $this->payload( $row );
			$entries[] = array(
				'subject'     => null === $payload ? '' : (string) $payload['subject'],
				'recipients'  => null === $payload ? 0 : count( $payload['to'] ) + count( $payload['cc'] ) + count( $payload['bcc'] ),
				'created_at'  => (string) $row['created_at'],
				'next_run_at' => (string) $row['next_run_at'],
				'retries'     => (int) $row['retries'],
				'last_error'  => (string) $row['last_error'],
			);
		}
		return $entries;
	}

	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Deletes queued messages that contain the address (privacy erasure).
	 */
	public function erase_address( string $email ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE address_hashes LIKE %s', self::table(), '%' . $wpdb->esc_like( Log_Repository::address_hash( $email ) ) . '%' )
		);
	}

	/**
	 * Retries due messages. With $all, waiting messages are retried immediately ("Retry now" in the admin).
	 */
	public function process( Dispatcher $dispatcher, bool $all = false ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ids = $all
			? $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY next_run_at LIMIT %d', self::table(), self::BATCH ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE next_run_at <= %s ORDER BY next_run_at LIMIT %d', self::table(), $now, self::BATCH ) );

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! $this->claim( $id ) ) {
				continue;
			}
			$row     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
			$payload = null === $row ? null : $this->payload( $row );
			if ( null === $payload ) {
				// Unreadable (e.g. the encryption key changed): it can never be delivered.
				$this->delete( $id );
				continue;
			}

			$outcome = 'failed';
			try {
				$outcome = $dispatcher->retry(
					$this->mailer( $payload ),
					array(
						'entry_id'         => $id,
						'group'            => (string) $row['attempt_group'],
						'number'           => (int) $row['attempt_number'],
						'original_to'      => (array) $payload['original_to'],
						'caller'           => (string) $payload['caller'],
						'substituted_from' => $payload['substituted_from'],
					)
				);
			} catch ( \Throwable $error ) {
				$outcome = 'failed';
			}
			if ( 'queued' === $outcome ) {
				continue;
			}
			$this->delete( $id );
			if ( 'sent' !== $outcome ) {
				/**
				 * Fires when a queued message is given up after a final failure or expiry.
				 *
				 * @param array $summary subject, recipients (count), created_at (UTC) and last_error.
				 */
				do_action(
					'sw_mailgate_queue_expired',
					array(
						'subject'    => (string) $payload['subject'],
						'recipients' => count( $payload['to'] ) + count( $payload['cc'] ) + count( $payload['bcc'] ),
						'created_at' => (string) $row['created_at'],
						'last_error' => (string) $row['last_error'],
					)
				);
			}
		}

		if ( 0 === $this->count() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	private function enqueue( Mail_Catcher $template, array $meta ): bool {
		global $wpdb;
		$attachments = $this->attachments( $template );
		if ( null === $attachments ) {
			return false;
		}
		$groups  = $template->recipient_groups();
		$payload = array(
			'to'               => array_values( $groups['to'] ),
			'cc'               => array_values( $groups['cc'] ),
			'bcc'              => array_values( $groups['bcc'] ),
			'reply_to'         => array_values( $template->getReplyToAddresses() ),
			'from'             => $template->From,
			'from_name'        => $template->FromName,
			'sender'           => $template->Sender,
			'subject'          => $template->Subject,
			'body'             => base64_encode( (string) $template->Body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Keeps any charset intact in JSON.
			'alt_body'         => base64_encode( (string) $template->AltBody ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Keeps any charset intact in JSON.
			'content_type'     => $template->ContentType,
			'charset'          => $template->CharSet,
			'encoding'         => $template->Encoding,
			'headers'          => array_values( $template->getCustomHeaders() ),
			'attachments'      => $attachments,
			'original_to'      => array_values( $meta['original_to'] ),
			'caller'           => $meta['caller'],
			'substituted_from' => $meta['substituted_from'],
		);
		$json    = wp_json_encode( $payload );
		if ( false === $json ) {
			return false;
		}

		$addresses = array_merge(
			array( $template->From ),
			array_column( $payload['to'], 0 ),
			array_column( $payload['cc'], 0 ),
			array_column( $payload['bcc'], 0 ),
			array_column( $payload['reply_to'], 0 ),
			$meta['original_to']
		);
		$now       = time();
		$inserted  = $wpdb->insert(
			self::table(),
			array(
				'created_at'     => gmdate( 'Y-m-d H:i:s', $now ),
				'next_run_at'    => gmdate( 'Y-m-d H:i:s', $now + self::BACKOFF[0] * MINUTE_IN_SECONDS ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', $now + (int) Failover_Settings::get()['queue_hours'] * HOUR_IN_SECONDS ),
				'retries'        => 0,
				'attempt_group'  => $meta['group'],
				'attempt_number' => $meta['number'],
				'last_error'     => mb_substr( $meta['error'], 0, 1000 ),
				'address_hashes' => wp_json_encode( array_values( array_unique( array_map( array( Log_Repository::class, 'address_hash' ), array_filter( $addresses ) ) ) ) ),
				'payload'        => $this->secrets->seal( $json ),
			)
		);
		if ( false === $inserted ) {
			return false;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( $now + self::BACKOFF[0] * MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
		}
		return true;
	}

	/**
	 * @return bool False when the entry expired: the caller treats the message as failed.
	 */
	private function reschedule( int $id, int $number, string $error ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT retries, expires_at FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		if ( null === $row ) {
			return false;
		}
		$retries = (int) $row['retries'] + 1;
		$next    = time() + self::BACKOFF[ min( $retries, count( self::BACKOFF ) - 1 ) ] * MINUTE_IN_SECONDS;
		if ( $next > strtotime( $row['expires_at'] . ' UTC' ) ) {
			return false;
		}
		return false !== $wpdb->update(
			self::table(),
			array(
				'retries'        => $retries,
				'attempt_number' => $number,
				'next_run_at'    => gmdate( 'Y-m-d H:i:s', $next ),
				'last_error'     => mb_substr( $error, 0, 1000 ),
				'locked_until'   => null,
			),
			array( 'id' => $id )
		);
	}

	private function claim( int $id ): bool {
		global $wpdb;
		$now = time();
		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET locked_until = %s WHERE id = %d AND (locked_until IS NULL OR locked_until < %s)',
				self::table(),
				gmdate( 'Y-m-d H:i:s', $now + self::LOCK_SECONDS ),
				$id,
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);
	}

	private function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	private function payload( array $row ): ?array {
		$json    = $this->secrets->open( (string) $row['payload'] );
		$payload = null === $json ? null : json_decode( $json, true );
		return is_array( $payload ) && isset( $payload['to'], $payload['cc'], $payload['bcc'], $payload['subject'] ) ? $payload : null;
	}

	/**
	 * Reads file attachments now: WordPress and plugins often delete temporary files after wp_mail().
	 *
	 * @return list<array<string, mixed>>|null Null when an attachment is unreadable or too large.
	 */
	private function attachments( Mail_Catcher $template ): ?array {
		$result = array();
		$total  = 0;
		foreach ( $template->getAttachments() as $attachment ) {
			if ( $attachment[5] ) {
				$data = (string) $attachment[0];
			} else {
				$path = (string) $attachment[0];
				$size = is_readable( $path ) ? filesize( $path ) : false;
				if ( false === $size || $total + $size > self::MAX_ATTACHMENT_BYTES ) {
					return null;
				}
				$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file chosen by the sender.
				if ( false === $data ) {
					return null;
				}
			}
			$total += strlen( $data );
			if ( $total > self::MAX_ATTACHMENT_BYTES ) {
				return null;
			}
			$result[] = array(
				'data'        => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary attachment in JSON.
				'name'        => (string) $attachment[2],
				'encoding'    => (string) $attachment[3],
				'type'        => (string) $attachment[4],
				'disposition' => (string) $attachment[6],
				'cid'         => (string) $attachment[7],
			);
		}
		return $result;
	}

	/**
	 * Rebuilds the message. wp_mail() filters and phpmailer_init already ran when it was queued.
	 */
	private function mailer( array $payload ): Mail_Catcher {
		$mailer              = Mailer_Factory::create();
		$mailer->CharSet     = (string) $payload['charset'];
		$mailer->ContentType = (string) $payload['content_type'];
		$mailer->Encoding    = (string) $payload['encoding'];
		$mailer->setFrom( (string) $payload['from'], (string) $payload['from_name'], false );
		$mailer->Sender  = (string) $payload['sender'];
		$mailer->Subject = (string) $payload['subject'];
		$mailer->Body    = (string) base64_decode( (string) $payload['body'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See enqueue().
		$mailer->AltBody = (string) base64_decode( (string) $payload['alt_body'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See enqueue().
		foreach ( array(
			'to'       => 'addAddress',
			'cc'       => 'addCC',
			'bcc'      => 'addBCC',
			'reply_to' => 'addReplyTo',
		) as $key => $method ) {
			foreach ( (array) $payload[ $key ] as $address ) {
				$mailer->$method( (string) $address[0], (string) ( $address[1] ?? '' ) );
			}
		}
		foreach ( (array) $payload['headers'] as $header ) {
			$mailer->addCustomHeader( (string) $header[0], (string) ( $header[1] ?? '' ) );
		}
		foreach ( (array) $payload['attachments'] as $attachment ) {
			$data = (string) base64_decode( (string) $attachment['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See attachments().
			if ( 'inline' === $attachment['disposition'] ) {
				$mailer->addStringEmbeddedImage( $data, $attachment['cid'], $attachment['name'], $attachment['encoding'], $attachment['type'], 'inline' );
			} else {
				$mailer->addStringAttachment( $data, $attachment['name'], $attachment['encoding'], $attachment['type'], $attachment['disposition'] );
			}
		}
		return $mailer;
	}
}
