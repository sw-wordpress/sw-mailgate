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
	 * @return array{status: int, data: ?array, debug: list<string>, duration: float}
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 15 ): array {
		$start  = microtime( true );
		$status = 0;
		$data   = null;
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
			if ( ! is_wp_error( $response ) ) {
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
			'status'   => $status,
			'data'     => $data,
			'duration' => $duration,
			'debug'    => array( sprintf( '%s %s: %s (%.3f s)', $method, (string) wp_parse_url( $url, PHP_URL_HOST ), $status ? 'HTTP ' . $status : 'transport error', $duration ) ),
		);
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
