<?php
/**
 * Bounded queries for the mail log. All timestamps are UTC.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Logging;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This repository owns a custom table; reads must reflect delivery, retention and privacy changes immediately.

final class Log_Repository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sw_mailgate_log';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function insert( array $row ): void {
		global $wpdb;
		$wpdb->insert( self::table(), $row );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function attempts( string $group ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE attempt_group = %s ORDER BY attempt_number, id', self::table(), $group ), ARRAY_A );
	}

	/**
	 * Filters are shared by the list and CSV export.
	 *
	 * @param array<string, string> $filters
	 * @return array{string, list<string>}
	 */
	private function where( array $filters ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$values = array();
		foreach ( array( 'status', 'connection_id' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[]  = $field . ' = %s';
				$values[] = $filters[ $field ];
			}
		}
		foreach ( array(
			'date_from' => '>=',
			'date_to'   => '<=',
		) as $field => $operator ) {
			$date = (string) ( $filters[ $field ] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) {
				$where[]  = 'created_at ' . $operator . ' %s';
				$values[] = $date . ( 'date_from' === $field ? ' 00:00:00' : ' 23:59:59' );
			}
		}
		if ( ! empty( $filters['s'] ) ) {
			$where[] = '(subject LIKE %s OR recipients LIKE %s OR from_email LIKE %s)';
			$search  = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			array_push( $values, $search, $search, $search );
		}
		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * @param array<string, string> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters = array(), int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		list( $where, $values ) = $this->where( $filters );
		return $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The complete placeholder values are passed as one array.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where() builds fixed clauses; all values use placeholders.
				"SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( array( self::table() ), $values, array( max( 1, min( 500, $limit ) ), max( 0, $offset ) ) )
			),
			ARRAY_A
		);
	}

	/**
	 * @param array<string, string> $filters
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;
		list( $where, $values ) = $this->where( $filters );
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed clauses and placeholders from where().
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( array( self::table() ), $values ) )
		);
	}

	/**
	 * Count the final outcome of each mail, rather than counting failed failover attempts.
	 *
	 * @return array<string, int>
	 */
	public function totals( int $days ): array {
		global $wpdb;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.status, COUNT(*) AS total FROM %i l INNER JOIN (SELECT MAX(id) AS id FROM %i WHERE created_at >= %s GROUP BY attempt_group) latest ON l.id = latest.id GROUP BY l.status',
				self::table(),
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$totals = array(
			'sent'   => 0,
			'failed' => 0,
		);
		foreach ( $rows as $row ) {
			$status            = 'redirected' === $row['status'] ? 'sent' : $row['status'];
			$totals[ $status ] = ( $totals[ $status ] ?? 0 ) + (int) $row['total'];
		}
		return $totals;
	}

	/** @return array{accepted: int, bounced: int} */
	public function tracking_totals( int $days ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS accepted, COALESCE(SUM(l.status = 'bounced' OR EXISTS (SELECT 1 FROM %i e WHERE e.log_id = l.id AND e.status = 'bounced')), 0) AS bounced FROM %i l INNER JOIN (SELECT MAX(id) AS id FROM %i WHERE created_at >= %s GROUP BY attempt_group) latest ON l.id = latest.id WHERE l.status IN ('sent', 'redirected', 'delivered', 'bounced', 'complained')",
				\SWMailgate\Tracking\Event_Repository::table(),
				self::table(),
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		return array(
			'accepted' => (int) ( $row['accepted'] ?? 0 ),
			'bounced'  => (int) ( $row['bounced'] ?? 0 ),
		);
	}

	public function retain(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get()['retention'] * DAY_IN_SECONDS ) )
		);
	}

	public static function message_hash( string $id ): string {
		return '' === $id ? '' : hash_hmac( 'sha256', trim( $id, '<>' ), wp_salt( 'auth' ) );
	}

	/** HMACs keep privacy requests possible even when addresses are masked. */
	public static function address_hash( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function personal( string $email, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE address_hashes LIKE %s ORDER BY id LIMIT %d OFFSET %d', self::table(), '%' . $wpdb->esc_like( self::address_hash( $email ) ) . '%', $limit, $offset ),
			ARRAY_A
		);
	}

	public function delete( int $id ): bool {
		global $wpdb;
		if ( false === $wpdb->delete( \SWMailgate\Tracking\Event_Repository::table(), array( 'log_id' => $id ), array( '%d' ) ) ) {
			return false;
		}
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}
}
