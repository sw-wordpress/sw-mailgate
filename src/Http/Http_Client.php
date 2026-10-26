<?php
/**
 * Small WordPress HTTP adapter. Diagnostics never include headers or response bodies.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Http;

final class Http_Client {

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, data: ?array, debug: list<string>, duration: float, ambiguous: bool}
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 15 ): array {
		$start     = microtime( true );
		$status    = 0;
		$data      = null;
		$ambiguous = false;
		try {
			$response = wp_remote_request(
				$url,
				array(
					'method'              => $method,
					'headers'             => $headers,
					'body'                => $body,
					'timeout'             => max( 5, min( 60, $timeout ) ),
					'redirection'         => 0,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
					'blocking'            => true,
					'limit_response_size' => 65536,
					'data_format'         => 'body',
				)
			);
			if ( is_wp_error( $response ) ) {
				$ambiguous = self::is_ambiguous( $response->get_error_message() );
			} else {
				$status  = (int) wp_remote_retrieve_response_code( $response );
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$data    = is_array( $decoded ) ? $decoded : null;
			}
		} catch ( \Throwable $error ) {
			// Transport exceptions can contain credentials, URLs or request content. Do not expose them.
			$status = 0;
		}
		$duration = microtime( true ) - $start;
		return array(
			'status'    => $status,
			'data'      => $data,
			'duration'  => $duration,
			'ambiguous' => $ambiguous,
			'debug'     => array( sprintf( '%s %s: %s (%.3f s)', $method, (string) wp_parse_url( $url, PHP_URL_HOST ), $status ? 'HTTP ' . $status : ( $ambiguous ? 'no response (timeout)' : 'transport error' ), $duration ) ),
		);
	}

	/**
	 * True when the transport failed after the request may have reached the server: a read timeout or a
	 * lost connection. DNS, connect and TLS handshake errors happen before anything is sent.
	 */
	public static function is_ambiguous( string $error ): bool {
		if ( preg_match( '/Could not resolve|Resolving timed out|Connection timed out after|Failed to connect|Connection refused|SSL certificate|SSL connect error/i', $error ) ) {
			return false;
		}
		return (bool) preg_match( '/Operation timed out|timed out after|Empty reply from server|Recv failure|Send failure|Connection reset|connection was closed|fsocket timed out/i', $error );
	}

	/**
	 * Build a multipart upload in memory, without cURL-specific objects or temporary files.
	 *
	 * @param array<string, string>                                                  $fields
	 * @param array<string, array{filename: string, type: string, contents: string}> $files
	 * @return array{headers: array<string, string>, body: string}
	 */
	public function multipart( array $fields, array $files ): array {
		$boundary = 'sw-mailgate-' . bin2hex( random_bytes( 24 ) );
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$name  = sanitize_key( $name );
			$body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
		}
		foreach ( $files as $name => $file ) {
			$name     = sanitize_key( $name );
			$filename = sanitize_file_name( $file['filename'] );
			$type     = preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD', $file['type'] ) ? $file['type'] : 'application/octet-stream';
			$body    .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . "\r\n";
			$body    .= 'Content-Type: ' . $type . "\r\n\r\n" . $file['contents'] . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";
		return array(
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
		);
	}
}
