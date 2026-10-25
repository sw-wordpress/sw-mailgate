<?php
/**
 * Recipient redirection preferences and wp-config override.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

final class Redirection_Settings {

	public const OPTION = 'sw_mailgate_redirection';

	public static function get(): array {
		$stored = (array) get_option( self::OPTION, array() );
		$locked = defined( 'SW_MAILGATE_REDIRECT_TO' );
		$target = $locked ? constant( 'SW_MAILGATE_REDIRECT_TO' ) : ( $stored['target'] ?? '' );
		$valid  = is_string( $target ) && ( '' === trim( $target ) || (bool) is_email( trim( $target ) ) );
		return array(
			'target'    => is_string( $target ) ? trim( $target ) : '',
			'valid'     => $valid,
			'locked'    => $locked,
			'allowlist' => array_values( array_filter( (array) ( $stored['allowlist'] ?? array() ), 'is_string' ) ),
		);
	}

	/** Invalid input leaves the entire stored redirection configuration unchanged. */
	public static function save( string $target, string $allowlist ): ?\WP_Error {
		$stored = (array) get_option( self::OPTION, array() );
		$target = defined( 'SW_MAILGATE_REDIRECT_TO' ) ? (string) ( $stored['target'] ?? '' ) : trim( $target );
		if ( '' !== $target && ! is_email( $target ) ) {
			return new \WP_Error( 'invalid_redirect', __( 'Enter one valid redirection address, or leave it empty to disable redirection.', 'sw-mailgate' ) );
		}
		$rules = array();
		foreach ( preg_split( '/[\s,;]+/', trim( $allowlist ), -1, PREG_SPLIT_NO_EMPTY ) as $rule ) {
			$rule  = strtolower( str_starts_with( $rule, '@' ) ? substr( $rule, 1 ) : $rule );
			$valid = str_contains( $rule, '@' ) ? is_email( $rule ) : is_email( 'mailgate@' . $rule );
			if ( ! $valid || str_contains( $rule, '*' ) ) {
				return new \WP_Error( 'invalid_allowlist', __( 'The allowlist accepts exact email addresses or domains, without wildcards.', 'sw-mailgate' ) );
			}
			$rules[] = $rule;
		}
		update_option(
			self::OPTION,
			array(
				'target'    => $target,
				'allowlist' => array_values( array_unique( $rules ) ),
			),
			false
		);
		return null;
	}
}
