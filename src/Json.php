<?php
/**
 * JSON encoding for values that must be serializable.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate;

final class Json {

	/**
	 * Encodes with wp_json_encode(), which already repairs invalid UTF-8.
	 *
	 * @throws \UnexpectedValueException When the value cannot be encoded; never returns an empty fallback.
	 */
	public static function encode( mixed $value ): string {
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			throw new \UnexpectedValueException( 'Value cannot be encoded as JSON.' );
		}
		return $json;
	}
}
