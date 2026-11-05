<?php
/** Local DNS first; optional fixed-endpoint HTTPS fallback.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Dns;

class Dns_Resolver {
	/** @return list<string>|null Null means unavailable, an empty list is authoritative absence. */
	public function lookup( string $name, string $type, bool $doh = false ): ?array {
		if ( ! self::valid_name( $name ) || ! in_array( $type, array( 'TXT', 'CNAME', 'MX', 'A', 'AAAA', 'PTR' ), true ) ) {
			return null;
		}
		$records = $this->native( $name, $type );
		if ( null !== $records || ! $doh ) {
			return $records;
		}
		$response = wp_remote_get(
			'https://cloudflare-dns.com/dns-query?' . http_build_query(
				array(
					'name' => $name,
					'type' => $type,
				)
			),
			array(
				'headers'             => array( 'Accept' => 'application/dns-json' ),
				'timeout'             => 5,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65536,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! in_array( $data['Status'] ?? null, array( 0, 3 ), true ) ) {
			return null;
		}
		$result = array();
		$types  = array(
			'TXT'   => 16,
			'CNAME' => 5,
			'MX'    => 15,
			'A'     => 1,
			'AAAA'  => 28,
			'PTR'   => 12,
		);
		foreach ( (array) ( $data['Answer'] ?? array() ) as $answer ) {
			if ( is_array( $answer ) && ( $answer['type'] ?? null ) === $types[ $type ] && is_string( $answer['data'] ?? null ) ) {
				$value = $answer['data'];
				if ( 'TXT' === $type ) {
					preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $value, $chunks );
					$value = implode( '', array_map( 'stripcslashes', $chunks[1] ) );
				}
				if ( 'MX' === $type ) {
					$value = preg_replace( '/^[0-9]+\s+/', '', $value ) ?? $value;
				}
				$result[] = 'TXT' === $type ? $value : rtrim( $value, '.' );
			}
		}
		return $result;
	}

	/** @return list<string>|null */
	protected function native( string $name, string $type ): ?array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failures are reported as an unavailable check.
		$records = @dns_get_record( $name, constant( 'DNS_' . $type ) );
		if ( false === $records ) {
			return null;
		}
		$result = array();
		foreach ( $records as $record ) {
			$value = match ( $type ) {
				'TXT' => isset( $record['entries'] ) ? implode( '', $record['entries'] ) : ( $record['txt'] ?? '' ),
				'A' => $record['ip'] ?? '',
				'AAAA' => $record['ipv6'] ?? '',
				default => $record['target'] ?? '',
			};
			if ( '' !== $value ) {
				if ( 'MX' === $type ) {
					$value = preg_replace( '/^[0-9]+\s+/', '', $value ) ?? $value;
				}
				$result[] = 'TXT' === $type ? $value : rtrim( $value, '.' );
			}
		}
		return $result;
	}

	public static function valid_name( string $name ): bool {
		return strlen( $name ) <= 253 && 1 === preg_match( '/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9_-]{2,63}$/iD', $name );
	}
}
