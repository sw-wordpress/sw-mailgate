<?php
/**
 * Failover preferences: circuit breaker, timeout policy, alert address and retry queue.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Routing;

final class Failover_Settings {

	public const OPTION = 'sw_mailgate_failover';

	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'breaker_threshold' => 3,
				'breaker_cooldown'  => 10,
				'timeout_failover'  => true,
				'alert_email'       => '',
				'queue_enabled'     => false,
				'queue_hours'       => 24,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/** Invalid input leaves the stored preferences unchanged. */
	public static function save( array $values ): ?\WP_Error {
		$alert = trim( (string) ( $values['alert_email'] ?? '' ) );
		if ( '' !== $alert && ! is_email( $alert ) ) {
			return new \WP_Error( 'invalid_alert_email', __( 'Enter one valid alert address, or leave it empty to disable alert mails.', 'sw-mailgate' ) );
		}
		update_option(
			self::OPTION,
			array(
				'breaker_threshold' => max( 0, min( 20, (int) ( $values['breaker_threshold'] ?? 3 ) ) ),
				'breaker_cooldown'  => max( 1, min( 1440, (int) ( $values['breaker_cooldown'] ?? 10 ) ) ),
				'timeout_failover'  => ! empty( $values['timeout_failover'] ),
				'alert_email'       => $alert,
				'queue_enabled'     => ! empty( $values['queue_enabled'] ),
				'queue_hours'       => max( 1, min( 72, (int) ( $values['queue_hours'] ?? 24 ) ) ),
			),
			false
		);
		return null;
	}
}
