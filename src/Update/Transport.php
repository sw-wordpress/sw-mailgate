<?php
/**
 * Bounded HTTPS requests. No authentication headers are forwarded.
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

namespace SWMailgate\Update;

use WP_Error;

final class Transport {

	/**
	 * Fetches a URL with host/redirect/size limits.
	 *
	 * @param string $url   HTTPS URL.
	 * @param int    $limit Maximum body size in bytes.
	 * @param string $file  Stream the body into this file instead of memory.
	 * @return string|WP_Error Body, or the streamed file path.
	 */
	public static function fetch( string $url, int $limit, string $file = '' ): string|WP_Error {
		for ( $redirect = 0; $redirect <= 4; ++$redirect ) {
			if ( ! self::allowed( $url ) ) {
				return new WP_Error( 'unsafe_update_url', __( 'Update address not allowed.', 'sw-mailgate' ) );
			}
			$args = array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => $limit + 1,
				'headers'             => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'SW-BackupIt-Verified-Updates',
				),
			);
			if ( '' !== $file ) {
				$args['stream']            = true;
				$args['filename']          = $file;
				$args['timeout']           = 60;
				$args['headers']['Accept'] = 'application/octet-stream';
			}
			$response = wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'update_network_error', __( 'GitHub could not be reached.', 'sw-mailgate' ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || '' === $location ) {
					return new WP_Error( 'unsafe_update_url', __( 'Invalid redirect.', 'sw-mailgate' ) );
				}
				$url = $location;
				continue;
			}
			if ( 200 !== $code ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'update_http_error', sprintf( __( 'GitHub responded with HTTP %d.', 'sw-mailgate' ), $code ) );
			}
			$body  = '' === $file ? wp_remote_retrieve_body( $response ) : '';
			$bytes = '' === $file ? strlen( $body ) : filesize( $file );
			if ( false === $bytes || $bytes > $limit ) {
				return new WP_Error( 'update_too_large', __( 'The update response exceeds the size limit.', 'sw-mailgate' ) );
			}
			return '' === $file ? $body : $file;
		}
		return new WP_Error( 'update_redirect_limit', __( 'Too many update redirects.', 'sw-mailgate' ) );
	}

	/**
	 * Whether the URL targets the pinned repository on GitHub's own hosts (HTTPS, no credentials, default port).
	 */
	public static function allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || isset( $parts['user'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		$host     = strtolower( $parts['host'] ?? '' );
		$path     = $parts['path'] ?? '';
		$decoded  = rawurldecode( $path );
		$segments = explode( '/', $decoded );
		if ( str_contains( $decoded, '\\' ) || str_contains( $decoded, chr( 0 ) ) || in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
			return false;
		}
		$repository = Manifest::source()['repository'];
		if ( 'api.github.com' === $host ) {
			return str_starts_with( $path, '/repos/' . $repository . '/' );
		}
		if ( 'github.com' === $host ) {
			return str_starts_with( $path, '/' . $repository . '/releases/download/' );
		}
		return in_array( $host, array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true );
	}
}
