<?php
/**
 * Log preferences. Message content is opt-in.
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
				'enabled'         => true,
				'store_body'      => false,
				'retention'       => 30,
				'mask_recipients' => false,
			),
			(array) get_option( self::OPTION, array() )
		);
	}

	public static function save( array $values ): void {
		update_option(
			self::OPTION,
			array(
				'enabled'         => ! empty( $values['enabled'] ),
				'store_body'      => ! empty( $values['store_body'] ),
				'retention'       => max( 1, min( 3650, (int) ( $values['retention'] ?? 30 ) ) ),
				'mask_recipients' => ! empty( $values['mask_recipients'] ),
			),
			false
		);
	}
}
