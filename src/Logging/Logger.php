<?php
/**
 * Best-effort logging: a storage failure must never change a delivery result.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Logging;

use SWMailgate\Mail\Attempt;

final class Logger {

	public const CRON_HOOK = 'sw_mailgate_log_retention';

	public function __construct( private readonly Log_Repository $logs ) {
	}

	public function register(): void {
		add_action( 'sw_mailgate_attempt', array( $this, 'record' ) );
		add_action( self::CRON_HOOK, array( $this->logs, 'retain' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public function record( Attempt $attempt ): void {
		global $wpdb;
		$previous = null;
		try {
			$settings = Settings::get();
			if ( ! $settings['enabled'] ) {
				return;
			}
			$mask      = (bool) $settings['mask_recipients'];
			$addresses = array_merge( array( $attempt->from ), $attempt->recipients, $attempt->original_to, $attempt->related_addresses );
			$redact    = static fn( string $text ): string => $mask ? self::mask( $text ) : $text;
			$row       = array(
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
				'status'              => $attempt->result->is_success() ? ( array() !== $attempt->original_to ? 'redirected' : 'sent' ) : 'failed',
				'connection_id'       => $attempt->connection->id(),
				'provider'            => $attempt->connection->provider(),
				'attempt_group'       => $attempt->group,
				'attempt_number'      => $attempt->number,
				'from_email'          => $redact( $attempt->from ),
				'recipients'          => wp_json_encode( array_map( $redact, $attempt->recipients ) ),
				'original_to'         => wp_json_encode( array_map( $redact, $attempt->original_to ) ),
				'subject'             => $redact( $attempt->subject ),
				'error_class'         => $attempt->result->error_class(),
				'error_message'       => $redact( $attempt->result->message() ),
				'duration'            => $attempt->result->duration(),
				'provider_message_id' => $redact( $attempt->result->provider_message_id() ),
				'caller'              => $attempt->caller,
				'address_hashes'      => wp_json_encode( array_values( array_unique( array_map( array( Log_Repository::class, 'address_hash' ), $addresses ) ) ) ),
				'payload'             => $settings['store_body'] && ! $mask && null !== $attempt->payload ? wp_json_encode( $attempt->payload ) : null,
			);
			// Avoid displaying database errors in a mail request, including under WP_DEBUG.
			$previous = $wpdb->suppress_errors( true );
			$this->logs->insert( $row );
		} catch ( \Throwable $error ) {
			// Deliberately do not log the exception: it can contain credentials or message content.
			return;
		} finally {
			if ( null !== $previous ) {
				$wpdb->suppress_errors( $previous );
			}
		}
	}

	public static function mask( string $text ): string {
		return (string) preg_replace( '/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+/i', '***@***', $text );
	}

	/** Record only a relative plugin/theme name, never arguments or absolute server paths. */
	public static function caller(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Arguments are deliberately excluded.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 50 ) as $frame ) {
			$file = wp_normalize_path( $frame['file'] ?? '' );
			foreach ( array(
				'plugin' => WP_PLUGIN_DIR,
				'theme'  => get_theme_root(),
			) as $type => $root ) {
				$root = trailingslashit( wp_normalize_path( $root ) );
				if ( str_starts_with( $file, $root ) && ! str_starts_with( $file, wp_normalize_path( SW_MAILGATE_DIR ) ) ) {
					return $type . ':' . explode( '/', substr( $file, strlen( $root ) ) )[0];
				}
			}
		}
		return 'WordPress';
	}
}
