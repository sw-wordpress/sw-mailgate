<?php
/**
 * Stores the ordered list of connections. The order is the failover order.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Connections;

use SWMailgate\Security\Secret_Store;

final class Connection_Repository {

	public const OPTION = 'sw_mailgate_connections';

	public function __construct( private readonly Secret_Store $secrets ) {
	}

	/**
	 * @return Connection[] In failover order.
	 */
	public function all(): array {
		$connections = array();
		foreach ( $this->raw() as $row ) {
			$connections[] = $this->hydrate( $row );
		}
		return $connections;
	}

	/**
	 * @return Connection[] Enabled connections in failover order.
	 */
	public function chain(): array {
		return array_values( array_filter( $this->all(), static fn( Connection $c ): bool => $c->is_enabled() ) );
	}

	public function find( string $id ): ?Connection {
		foreach ( $this->raw() as $row ) {
			if ( $row['id'] === $id ) {
				return $this->hydrate( $row );
			}
		}
		return null;
	}

	/**
	 * Inserts or updates a connection. New connections are appended to the end of the chain.
	 *
	 * @param array<string, mixed>  $data    Connection fields (see Connection::to_array()).
	 * @param array<string, string> $secrets Secret values to store; empty values are ignored (keep current).
	 */
	public function save( array $data, array $secrets = array() ): void {
		$rows    = $this->raw();
		$row     = $this->normalize( $data );
		$updated = false;
		foreach ( $rows as $index => $existing ) {
			if ( $existing['id'] === $row['id'] ) {
				$rows[ $index ] = $row;
				$updated        = true;
			}
		}
		if ( ! $updated ) {
			$rows[] = $row;
		}
		update_option( self::OPTION, array_values( $rows ), false );

		foreach ( $secrets as $key => $value ) {
			if ( '' !== $value ) {
				$this->secrets->set( Connection::secret_name( $row['id'], $key ), $value );
			}
		}
	}

	public function delete_secret( string $id, string $key ): void {
		$this->secrets->delete( Connection::secret_name( $id, $key ) );
	}

	public function delete( string $id ): void {
		$rows = array_filter( $this->raw(), static fn( array $row ): bool => $row['id'] !== $id );
		update_option( self::OPTION, array_values( $rows ), false );
		$this->secrets->delete_prefix( Connection::secret_name( $id, '' ) );
	}

	/**
	 * Moves a connection one position up (-1) or down (+1) in the failover chain.
	 */
	public function move( string $id, int $direction ): void {
		$rows = $this->raw();
		foreach ( $rows as $index => $row ) {
			if ( $row['id'] !== $id ) {
				continue;
			}
			$target = $index + ( $direction < 0 ? -1 : 1 );
			if ( isset( $rows[ $target ] ) ) {
				$rows[ $index ]  = $rows[ $target ];
				$rows[ $target ] = $row;
				update_option( self::OPTION, $rows, false );
			}
			return;
		}
	}

	public function set_enabled( string $id, bool $enabled ): void {
		$rows = $this->raw();
		foreach ( $rows as $index => $row ) {
			if ( $row['id'] === $id ) {
				$rows[ $index ]['enabled'] = $enabled;
			}
		}
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Builds a unique, constant-friendly ID from a label (e.g. "Mailgun EU" → "mailgun_eu").
	 */
	public function unique_id( string $label ): string {
		$base = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( $label ) ) ), '_' );
		$base = '' === $base ? 'connection' : substr( $base, 0, 32 );
		$id   = $base;
		$i    = 2;
		while ( null !== $this->find( $id ) ) {
			$id = $base . '_' . $i;
			++$i;
		}
		return $id;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function raw(): array {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['provider'] ) ) {
				$result[] = $this->normalize( $row );
			}
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		return array(
			'id'                => (string) $row['id'],
			'label'             => (string) ( $row['label'] ?? $row['id'] ),
			'provider'          => (string) $row['provider'],
			'enabled'           => (bool) ( $row['enabled'] ?? true ),
			'from_email'        => (string) ( $row['from_email'] ?? '' ),
			'from_name'         => (string) ( $row['from_name'] ?? '' ),
			'force_from'        => (bool) ( $row['force_from'] ?? true ),
			'reply_to_original' => (bool) ( $row['reply_to_original'] ?? true ),
			'settings'          => is_array( $row['settings'] ?? null ) ? $row['settings'] : array(),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): Connection {
		return new Connection(
			$row['id'],
			$row['label'],
			$row['provider'],
			$row['enabled'],
			$row['from_email'],
			$row['from_name'],
			$row['force_from'],
			$row['reply_to_original'],
			$row['settings'],
			$this->secrets
		);
	}
}
