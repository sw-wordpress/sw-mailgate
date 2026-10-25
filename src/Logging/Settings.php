<?php
/**
 * Log preferences. Logging requires explicit wp-config.php authorization.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Logging;

final class Settings {

	public const OPTION = 'sw_mailgate_logging';

	public static function get(): array {
		return array_merge(
			array(
				'store_body'      => false,
				'retention'       => 30,
				'mask_recipients' => false,
			),
			(array) get_option( self::OPTION, array() ),
			// Stored options and submitted form values must never enable logging.
			array( 'enabled' => defined( 'SW_MAILGATE_LOG_ENABLED' ) && true === SW_MAILGATE_LOG_ENABLED )
		);
	}

	public static function save( array $values ): void {
		update_option(
			self::OPTION,
			array(
				'store_body'      => ! empty( $values['store_body'] ),
				'retention'       => max( 1, min( 3650, (int) ( $values['retention'] ?? 30 ) ) ),
				'mask_recipients' => ! empty( $values['mask_recipients'] ),
			),
			false
		);
	}
}
