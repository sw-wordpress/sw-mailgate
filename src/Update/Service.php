<?php
/**
 * Backend-only release checking, persistent daily throttling and verified downloads.
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

namespace SWMailgate\Update;

use Throwable;
use WP_Error;

final class Service {

	/**
	 * Trusted public keys: plugin-specific constant (never a shared global list) or the shipped key ring.
	 *
	 * @return array<string,string>
	 */
	public static function keys(): array {
		$keys = defined( 'SW_MAILGATE_RELEASE_PUBLIC_KEYS' ) ? SW_MAILGATE_RELEASE_PUBLIC_KEYS : require __DIR__ . '/Config/trusted-keys.php';
		$keys = is_array( $keys ) ? $keys : array();
		/**
		 * Filters the trusted public Ed25519 keys (key ID => Base64). Intended for tests and local operator configuration.
		 *
		 * @param array<string,string> $keys Trusted keys.
		 */
		$keys = apply_filters( 'swmg_verified_release_public_keys', $keys );
		return array_filter( $keys, 'is_string' );
	}

	/**
	 * Authorised, interactive backend session that may install and check updates.
	 */
	public static function interactive(): bool {
		// admin-ajax.php does not set WP_NETWORK_ADMIN, even for network-admin requests.
		$network_context = ! is_multisite() || is_network_admin() || ( wp_doing_ajax() && current_user_can( 'manage_network_plugins' ) );
		return is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && $network_context && current_user_can( 'update_plugins' ) && wp_is_file_mod_allowed( 'plugin_updates' );
	}

	/**
	 * Checks GitHub (at most once per day; manually at most once per minute) and stores the verified result.
	 *
	 * @param bool $manual Explicit operator request.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function check( bool $manual = false ): array|WP_Error {
		if ( ! self::interactive() ) {
			return new WP_Error( 'updates_forbidden', __( 'Update checks are only allowed in an authorised backend session.', 'sw-mailgate' ) );
		}
		if ( ! self::keys() || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_Error( 'updates_not_configured', __( 'No public release key or Sodium is available. GitHub updates are blocked.', 'sw-mailgate' ) );
		}
		$lock = Store::acquire();
		if ( false === $lock ) {
			return new WP_Error( 'updates_busy', __( 'An update check is already running.', 'sw-mailgate' ) );
		}
		$state = array();
		try {
			$state = Store::read();
			if ( time() - (int) ( $state['last_attempt'] ?? 0 ) < ( $manual ? 60 : DAY_IN_SECONDS ) ) {
				return new WP_Error( 'updates_throttled', __( 'The last update check is still within the check interval.', 'sw-mailgate' ) );
			}
			// Failures count as attempts, too: the persistent timestamp is written before any request.
			$state['last_attempt'] = time();
			Store::write( $state );
			$result = self::retrieve( $state );
			if ( ! Store::owns( $lock ) ) {
				return new WP_Error( 'updates_lease_expired', __( 'The check took too long. The result was not adopted.', 'sw-mailgate' ) );
			}
			if ( is_wp_error( $result ) ) {
				$state['error'] = $result->get_error_message();
				Store::write( $state );
				return $result;
			}
			Store::write( $result );
			return array( 'message' => __( 'Signed release information verified. Install updates only through the WordPress plugin list.', 'sw-mailgate' ) );
		} catch ( Throwable $error ) {
			$state['error'] = __( 'Invalid release response.', 'sw-mailgate' );
			if ( Store::owns( $lock ) ) {
				Store::write( $state );
			}
			return new WP_Error( 'invalid_release', $state['error'] );
		} finally {
			Store::release( $lock );
		}
	}

	/**
	 * @param array<string,mixed> $state Current state.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function retrieve( array $state ): array|WP_Error {
		$repository = Manifest::source()['repository'];
		$raw        = Transport::fetch( 'https://api.github.com/repos/' . $repository . '/releases/latest', 262144 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$release = json_decode( $raw, true );
		if ( ! is_array( $release ) || ! is_int( $release['id'] ?? null ) || ! is_string( $release['tag_name'] ?? null ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return new WP_Error( 'invalid_release', __( 'No valid stable release response.', 'sw-mailgate' ) );
		}
		$files = array();
		foreach ( array(
			'sw-plugin-release.json'     => 262144,
			'sw-plugin-release.json.sig' => 1024,
		) as $name => $limit ) {
			$assets = array_values( array_filter( is_array( $release['assets'] ?? null ) ? $release['assets'] : array(), static fn ( $a ) => is_array( $a ) && ( $a['name'] ?? '' ) === $name ) );
			if ( 1 !== count( $assets ) || ! is_int( $assets[0]['id'] ?? null ) ) {
				return new WP_Error( 'missing_signature_asset', __( 'Release manifest or signature is missing.', 'sw-mailgate' ) );
			}
			// Construct from the locally fixed identity, never trust an API-provided download URL.
			$body = Transport::fetch( 'https://github.com/' . $repository . '/releases/download/' . rawurlencode( $release['tag_name'] ) . '/' . $name, $limit );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$files[ $name ] = $body;
		}
		$manifest = Manifest::verify( $files['sw-plugin-release.json'], $files['sw-plugin-release.json.sig'], self::keys(), $release );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$highest = is_array( $state['highest'] ?? null ) ? $state['highest'] : array();
		foreach ( (array) $manifest['packages'] as $package ) {
			if ( version_compare( (string) $package['version'], (string) ( $highest[ $package['slug'] ] ?? '0' ), '<' ) ) {
				return new WP_Error( 'release_rollback', __( 'An already verified newer version must not be rolled back.', 'sw-mailgate' ) );
			}
			$highest[ $package['slug'] ] = $package['version'];
		}
		$state['highest']      = $highest;
		$state['raw']          = $files['sw-plugin-release.json'];
		$state['signature']    = $files['sw-plugin-release.json.sig'];
		$state['release']      = $release;
		$state['manifest']     = $manifest;
		$state['last_success'] = time();
		$state['error']        = '';
		return $state;
	}

	/**
	 * Re-verifies the stored manifest; fails when stale (> 7 days) or rolled back.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function verified_snapshot(): array|WP_Error {
		$state = Store::read();
		if ( empty( $state['last_success'] ) || time() - (int) $state['last_success'] > 7 * DAY_IN_SECONDS ) {
			return new WP_Error( 'stale_update', __( 'Please check for updates again before installing.', 'sw-mailgate' ) );
		}
		$manifest = Manifest::verify( (string) ( $state['raw'] ?? '' ), (string) ( $state['signature'] ?? '' ), self::keys(), is_array( $state['release'] ?? null ) ? $state['release'] : array() );
		if ( ! is_wp_error( $manifest ) ) {
			$highest = is_array( $state['highest'] ?? null ) ? $state['highest'] : array();
			foreach ( (array) $manifest['packages'] as $package ) {
				if ( version_compare( (string) $package['version'], (string) ( $highest[ $package['slug'] ] ?? '0' ), '<' ) ) {
					return new WP_Error( 'release_rollback', __( 'Outdated release offer.', 'sw-mailgate' ) );
				}
			}
		}
		return $manifest;
	}

	/**
	 * Downloads and verifies the package for WordPress' upgrader.
	 *
	 * @param string              $url        Expected package URL.
	 * @param array<string,mixed> $descriptor Installed plugin identity.
	 * @return string|WP_Error Path of the verified temporary ZIP.
	 */
	public static function download( string $url, array $descriptor ): string|WP_Error {
		$manifest = self::verified_snapshot();
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$package = Manifest::package( $manifest, (string) $descriptor['slug'] );
		if ( null === $package || Manifest::url( $manifest, $package ) !== $url || ! self::compatible( $package ) || ! version_compare( (string) $package['version'], (string) $descriptor['version'], '>' ) ) {
			return new WP_Error( 'untrusted_package', __( 'Package, version or system requirements do not match.', 'sw-mailgate' ) );
		}
		$file = wp_tempnam( 'swmg-verified-update.zip' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private mode for a temp file WordPress just created.
		if ( '' === $file || ! chmod( $file, 0600 ) ) {
			if ( '' !== $file ) {
				wp_delete_file( $file );
			}
			return new WP_Error( 'update_tempfile', __( 'Temporary update file unavailable.', 'sw-mailgate' ) );
		}
		// Core consumes the archive during this request. Clean up even on an interrupted upgrade.
		register_shutdown_function(
			static function () use ( $file ): void {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		);
		try {
			$result = Transport::fetch( $url, (int) $package['bytes'], $file );
			if ( ! is_wp_error( $result ) ) {
				$result = Package::verify( $file, $package );
			}
			if ( is_wp_error( $result ) ) {
				wp_delete_file( $file );
				return $result;
			}
		} catch ( Throwable $error ) {
			wp_delete_file( $file );
			return new WP_Error( 'invalid_update_file', __( 'The update download failed.', 'sw-mailgate' ) );
		}
		return $file;
	}

	/**
	 * Whether this system meets the package's minimum PHP and WordPress versions.
	 *
	 * @param array<string,mixed> $package Package entry.
	 */
	public static function compatible( array $package ): bool {
		return version_compare( PHP_VERSION, (string) $package['requires_php'], '>=' ) && version_compare( get_bloginfo( 'version' ), (string) $package['requires_wp'], '>=' );
	}
}
