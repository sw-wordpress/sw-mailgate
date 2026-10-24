<?php
/**
 * Encrypted storage for credentials (passwords, API keys, OAuth tokens).
 *
 * Values are encrypted with libsodium secretbox. The key comes from the SW_MAILGATE_KEY constant in
 * wp-config.php; without it the key is derived from the WordPress salts, which breaks when the salts
 * are rotated. Every ciphertext carries a key fingerprint, so a changed key is detected and reported
 * instead of producing garbage.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Security;

final class Secret_Store {

	public const OPTION = 'sw_mailgate_secrets';

	private const FORMAT = 'v1';

	private ?string $key = null;

	private bool $key_mismatch = false;

	public function get( string $name ): ?string {
		$stored = $this->stored();
		if ( ! isset( $stored[ $name ] ) || ! is_string( $stored[ $name ] ) ) {
			return null;
		}

		$value = $this->decrypt( $stored[ $name ] );
		if ( null === $value ) {
			$this->key_mismatch = true;
		}
		return $value;
	}

	public function has( string $name ): bool {
		return isset( $this->stored()[ $name ] );
	}

	public function set( string $name, string $value ): void {
		$stored          = $this->stored();
		$stored[ $name ] = $this->encrypt( $value );
		update_option( self::OPTION, $stored, false );
	}

	public function delete( string $name ): void {
		$stored = $this->stored();
		if ( ! isset( $stored[ $name ] ) ) {
			return;
		}
		unset( $stored[ $name ] );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Removes every secret whose name starts with the prefix (e.g. all secrets of one connection).
	 */
	public function delete_prefix( string $prefix ): void {
		$stored = $this->stored();
		$kept   = array_filter(
			$stored,
			static fn( $name ): bool => ! str_starts_with( (string) $name, $prefix ),
			ARRAY_FILTER_USE_KEY
		);
		if ( count( $kept ) !== count( $stored ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	/**
	 * True when at least one stored secret was encrypted with a different key than the current one.
	 */
	public function has_key_mismatch(): bool {
		if ( $this->key_mismatch ) {
			return true;
		}
		$fingerprint = $this->fingerprint();
		foreach ( $this->stored() as $value ) {
			$parts = explode( ':', (string) $value, 3 );
			if ( count( $parts ) !== 3 || $parts[1] !== $fingerprint ) {
				return true;
			}
		}
		return false;
	}

	public static function uses_dedicated_key(): bool {
		return defined( 'SW_MAILGATE_KEY' ) && is_string( SW_MAILGATE_KEY ) && strlen( SW_MAILGATE_KEY ) >= 32;
	}

	/**
	 * @return array<string, string>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private function encrypt( string $value ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $value, $nonce, $this->key() );
		return self::FORMAT . ':' . $this->fingerprint() . ':' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext storage.
	}

	private function decrypt( string $stored ): ?string {
		$parts = explode( ':', $stored, 3 );
		if ( count( $parts ) !== 3 || self::FORMAT !== $parts[0] || $parts[1] !== $this->fingerprint() ) {
			return null;
		}

		$raw = base64_decode( $parts[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);
		return false === $plain ? null : $plain;
	}

	private function key(): string {
		if ( null === $this->key ) {
			$material  = self::uses_dedicated_key()
				? SW_MAILGATE_KEY
				: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
			$this->key = sodium_crypto_generichash( 'sw-mailgate|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return $this->key;
	}

	private function fingerprint(): string {
		return substr( hash( 'sha256', 'sw-mailgate-fingerprint|' . $this->key() ), 0, 12 );
	}
}
