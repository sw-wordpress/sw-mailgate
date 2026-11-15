<?php
/**
 * Signed, repository-bound release metadata. No WordPress state or HTTP.
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

namespace SWMailgate\Update;

use RuntimeException;
use Throwable;
use WP_Error;

final class Manifest {

	public const MAX_BYTES = 262144;

	/**
	 * Locally fixed release identity.
	 *
	 * @return array{repository:string,repository_id:int,main_slug:string,state_key:string,lock_key:string,allowed_slugs:list<string>}
	 */
	public static function source(): array {
		/** @var array{repository:string,repository_id:int,main_slug:string,state_key:string,lock_key:string,allowed_slugs:list<string>} $source */
		$source = require __DIR__ . '/Config/source.php';
		return $source;
	}

	/**
	 * Verifies signature, identity and schema.
	 *
	 * @param string               $raw      Exact signed bytes.
	 * @param string               $envelope Signature metadata (JSON).
	 * @param array<string,string> $keys     Trusted public keys by key ID.
	 * @param array<string,mixed>  $release  GitHub release object the manifest must be bound to.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verify( string $raw, string $envelope, array $keys, array $release ): array|WP_Error {
		try {
			return self::verified( $raw, $envelope, $keys, $release );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'untrusted_release',
				__( 'Release verification failed. The release was not accepted.', 'sw-mailgate' ),
				array( 'reason' => $error->getMessage() )
			);
		}
	}

	/**
	 * Throwing implementation of verify().
	 *
	 * @param string               $raw      Exact signed bytes.
	 * @param string               $envelope Signature metadata (JSON).
	 * @param array<string,string> $keys     Trusted public keys by key ID.
	 * @param array<string,mixed>  $release  GitHub release object.
	 * @return array<string,mixed>
	 * @throws RuntimeException On any violation; converted into a WP_Error by verify().
	 */
	private static function verified( string $raw, string $envelope, array $keys, array $release ): array {
		if ( strlen( $raw ) > self::MAX_BYTES || strlen( $envelope ) > 1024 || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			throw new RuntimeException( 'Signature check unavailable or metadata too large.' );
		}
		self::unique_keys( $envelope );
		$sig = json_decode( $envelope, true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $sig ) ) {
			throw new RuntimeException( 'Invalid signature envelope.' );
		}
		$key_id = is_string( $sig['key_id'] ?? null ) ? $sig['key_id'] : '';
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding raw Ed25519 key/signature bytes.
		$key       = base64_decode( $keys[ $key_id ] ?? '', true );
		$signature = base64_decode( is_string( $sig['signature'] ?? null ) ? $sig['signature'] : '', true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_string( $key ) || 32 !== strlen( $key ) || ! is_string( $signature ) || 64 !== strlen( $signature ) || ! sodium_crypto_sign_verify_detached( $signature, $raw, $key ) ) {
			throw new RuntimeException( 'Unknown key or invalid release signature.' );
		}
		$data = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
		self::unique_keys( $raw );
		$source        = self::source();
		$schema        = is_array( $data ) ? ( $data['schema'] ?? null ) : null;
		$repository    = is_array( $data ) ? ( $data['repository'] ?? '' ) : '';
		$repository_id = is_array( $data ) ? ( $data['repository_id'] ?? null ) : null;
		$release_id    = is_array( $data ) ? ( $data['release_id'] ?? null ) : null;
		$tag_name      = is_array( $data ) ? ( $data['tag'] ?? null ) : null;
		$github_id     = $release['id'] ?? null;
		$github_tag    = $release['tag_name'] ?? null;
		if ( ! is_array( $data ) || 1 !== $schema || $source['repository'] !== $repository || $source['repository_id'] !== $repository_id || ! is_int( $release_id ) || $release_id < 1 || $release_id !== $github_id || ! is_string( $tag_name ) || $github_tag !== $tag_name || false !== ( $release['draft'] ?? null ) || false !== ( $release['prerelease'] ?? null ) ) {
			throw new RuntimeException( 'Release identity does not match the signature.' );
		}
		$tag = $data['tag'];
		if ( ! is_string( $data['commit'] ?? null ) || ! preg_match( '/^[a-f0-9]{40}$/D', $data['commit'] ) || ! preg_match( '/^v?\d+(?:\.\d+){2,3}(?:-\d{2})?$/D', $tag ) || ! is_int( $data['issued_at'] ?? null ) || $data['issued_at'] > time() + 300 || $data['issued_at'] <= 0 || ! is_array( $data['packages'] ?? null ) || ! array_is_list( $data['packages'] ) || count( $data['packages'] ) > 20 ) {
			throw new RuntimeException( 'Invalid manifest format.' );
		}
		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$ids    = array();
		$names  = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! is_int( $asset['id'] ?? null ) || $asset['id'] < 1 || ! is_string( $asset['name'] ?? null ) || isset( $ids[ $asset['id'] ] ) || isset( $names[ $asset['name'] ] ) ) {
				throw new RuntimeException( 'Ambiguous release assets.' );
			}
			$ids[ $asset['id'] ]     = true;
			$names[ $asset['name'] ] = true;
		}
		$seen = array();
		foreach ( $data['packages'] as $package ) {
			self::check_package( $package, $assets, $seen, $source['allowed_slugs'] );
		}
		$main        = array_values(
			array_filter(
				$data['packages'],
				static fn ( $p ) => is_array( $p ) && $source['main_slug'] === $p['slug']
			)
		);
		$tag_version = preg_replace( '/-\d{2}$/', '', ltrim( $tag, 'v' ) );
		if ( 1 !== count( $main ) || $main[0]['version'] !== $tag_version ) {
			throw new RuntimeException( 'Main plugin and release tag contradict each other.' );
		}
		return $data;
	}

	/**
	 * Validates one package entry against schema, allow-list and release assets.
	 *
	 * @param mixed              $package One manifest package entry.
	 * @param array<mixed>       $assets  Release assets.
	 * @param array<string,true> $seen    Slugs already seen.
	 * @param array<int,string>  $allowed Slugs this installation manages.
	 * @throws RuntimeException On any violation.
	 */
	private static function check_package( mixed $package, array $assets, array &$seen, array $allowed ): void {
		if ( ! is_array( $package ) ) {
			throw new RuntimeException( 'Invalid package entry.' );
		}
		$slug    = is_string( $package['slug'] ?? null ) ? $package['slug'] : '';
		$version = is_string( $package['version'] ?? null ) ? $package['version'] : '';
		$sha     = is_string( $package['sha256'] ?? null ) ? $package['sha256'] : '';
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) || isset( $seen[ $slug ] ) || ! in_array( $slug, $allowed, true ) || ! preg_match( '/^\d+(?:\.\d+){2,3}$/D', $version ) || ( $package['filename'] ?? '' ) !== "$slug-$version.zip" || ( $package['main_file'] ?? '' ) !== "$slug.php" || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! is_int( $package['asset_id'] ?? null ) || $package['asset_id'] <= 0 || ! is_int( $package['bytes'] ?? null ) || $package['bytes'] < 1 || $package['bytes'] > 52428800 ) {
			throw new RuntimeException( 'Invalid or duplicate package entry.' );
		}
		foreach ( array( 'requires_php', 'requires_wp' ) as $field ) {
			if ( ! is_string( $package[ $field ] ?? null ) || ! preg_match( '/^\d+(?:\.\d+){1,3}$/D', $package[ $field ] ) ) {
				throw new RuntimeException( 'Minimum version missing.' );
			}
		}
		$matching = array_filter(
			$assets,
			static fn ( $asset ) => is_array( $asset ) && ( $asset['id'] ?? null ) === $package['asset_id'] && ( $asset['name'] ?? '' ) === $package['filename'] && ( $asset['size'] ?? null ) === $package['bytes']
		);
		if ( 1 !== count( $matching ) ) {
			throw new RuntimeException( 'Release asset does not match the signed package.' );
		}
		$seen[ $slug ] = true;
	}

	/**
	 * Rejects duplicate object keys before json_decode()'s last-key-wins interpretation is used.
	 *
	 * @throws RuntimeException For duplicate keys.
	 */
	private static function unique_keys( string $json ): void {
		preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches );
		$tokens = $matches[0];
		$stack  = array();
		foreach ( $tokens as $index => $token ) {
			if ( '{' === $token || '[' === $token ) {
				$stack[] = array();
			} elseif ( '}' === $token || ']' === $token ) {
				array_pop( $stack );
			} elseif ( str_starts_with( $token, '"' ) && ( $tokens[ $index + 1 ] ?? '' ) === ':' ) {
				$key = (string) json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
				$top = count( $stack ) - 1;
				if ( isset( $stack[ $top ][ $key ] ) ) {
					throw new RuntimeException( 'Duplicate JSON key.' );
				}
				$stack[ $top ][ $key ] = true;
			}
		}
	}

	/**
	 * Package entry for a slug.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @return array<string,mixed>|null
	 */
	public static function package( array $manifest, string $slug ): ?array {
		$packages = is_array( $manifest['packages'] ?? null ) ? $manifest['packages'] : array();
		foreach ( $packages as $package ) {
			if ( is_array( $package ) && ( $package['slug'] ?? null ) === $slug ) {
				return $package;
			}
		}
		return null;
	}

	/**
	 * Download URL derived from the local identity, never from API-provided URLs.
	 *
	 * @param array<string,mixed> $manifest Verified manifest.
	 * @param array<string,mixed> $package  Package entry.
	 */
	public static function url( array $manifest, array $package ): string {
		return 'https://github.com/' . self::source()['repository'] . '/releases/download/' . rawurlencode( (string) $manifest['tag'] ) . '/' . rawurlencode( (string) $package['filename'] );
	}
}
