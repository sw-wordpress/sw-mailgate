<?php
/**
 * WP-CLI diagnostics. Output uses explicit safe columns, never connection settings.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Cli;

use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Dns\Dns_Monitor;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Mail\Dispatcher;

final class Commands {
	public function __construct( private readonly Connection_Repository $connections, private readonly Dns_Monitor $dns_monitor, private readonly Log_Repository $logs, private readonly Dispatcher $dispatcher ) {}

	/**
	 * List connections in failover order, without credentials.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * @param list<string>         $args Positional arguments.
	 * @param array<string,string> $options Options.
	 */
	public function connections( array $args, array $options ): void {
		$rows = array();
		foreach ( $this->connections->all() as $connection ) {
			$rows[] = array(
				'id'       => $connection->id(),
				'provider' => $connection->provider(),
				'enabled'  => $connection->is_enabled() ? 'yes' : 'no',
			);
		}
		$this->output( $rows, array( 'id', 'provider', 'enabled' ), $options );
	}

	/**
	 * List recent delivery outcomes, without addresses or message content.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of attempts, 1–500. Default: 20.
	 * [--status=<status>]
	 * : Filter by log status.
	 * [--connection=<id>]
	 * : Filter by connection ID.
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * @param list<string>         $args Positional arguments.
	 * @param array<string,string> $options Options.
	 */
	public function log( array $args, array $options ): void {
		$limit = $options['limit'] ?? '20';
		if ( ! ctype_digit( $limit ) || (int) $limit < 1 || (int) $limit > 500 ) {
			\WP_CLI::error( 'Limit must be between 1 and 500.' );
		}
		$rows = $this->logs->search(
			array(
				'status'        => $options['status'] ?? '',
				'connection_id' => $options['connection'] ?? '',
			),
			(int) $limit
		);
		$this->output( $rows, array( 'id', 'created_at', 'connection_id', 'status', 'error_class' ), $options );
	}

	/**
	 * Check sender DNS for enabled connections, or one selected connection.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Check a specific connection, including disabled connections.
	 * [--refresh]
	 * : Bypass the DNS result cache.
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * @param list<string>         $args Positional arguments.
	 * @param array<string,string> $options Options.
	 */
	public function dns( array $args, array $options ): void {
		$connections = $this->connections->chain();
		if ( isset( $options['connection'] ) ) {
			$connection = $this->connections->find( $options['connection'] );
			if ( null === $connection ) {
				\WP_CLI::error( 'Unknown connection.' );
			}
			$connections = array( $connection );
		}
		$rows = array();
		foreach ( $connections as $connection ) {
			foreach ( $this->dns_monitor->results( $connection, isset( $options['refresh'] ) ) as $row ) {
				$rows[] = array_merge( array( 'connection' => $connection->id() ), $row );
			}
		}
		$this->output( $rows, array( 'connection', 'check', 'status', 'expected', 'recommendation' ), $options );
	}

	/**
	 * Send one test mail through the normal redirection and failover rules.
	 *
	 * ## OPTIONS
	 *
	 * <recipient>
	 * : Recipient email address.
	 * [--connection=<id>]
	 * : Restrict delivery to one enabled connection.
	 *
	 * @param list<string>         $args Positional arguments.
	 * @param array<string,string> $options Options.
	 */
	public function test( array $args, array $options ): void {
		$recipient = $args[0] ?? '';
		if ( ! is_email( $recipient ) ) {
			\WP_CLI::error( 'A valid recipient email address is required.' );
		}
		$id = $options['connection'] ?? null;
		if ( null !== $id ) {
			$connection = $this->connections->find( $id );
			if ( null === $connection || ! $connection->is_enabled() ) {
				\WP_CLI::error( 'Unknown or disabled connection.' );
			}
		}
		$this->dispatcher->restrict_to( $id );
		try {
			$sent = wp_mail( $recipient, __( 'SW Mailgate test mail', 'sw-mailgate' ), __( 'This is a test message from SW Mailgate.', 'sw-mailgate' ) );
		} finally {
			$this->dispatcher->restrict_to( null );
		}
		if ( ! $sent ) {
			\WP_CLI::error( 'Test mail failed. Review the connection settings and delivery log.' );
		}
		if ( 'queued' === $this->dispatcher->last_outcome() ) {
			\WP_CLI::success( 'Test mail queued for retry; it has not been accepted by a transport yet.' );
			return;
		}
		\WP_CLI::success( 'Test mail accepted by the transport; check the recipient inbox.' );
	}

	/**
	 * @param array<array<string, mixed>> $rows Rows.
	 * @param list<string>                $fields Columns.
	 * @param array<string,string>        $options Options.
	 */
	private function output( array $rows, array $fields, array $options ): void {
		$format = $options['format'] ?? 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			\WP_CLI::error( 'Format must be table, json or csv.' );
		}
		// Project rows before formatting: formatters must never receive secrets or mail content.
		$rows = array_map( static fn( array $row ): array => array_intersect_key( $row, array_fill_keys( $fields, true ) ), $rows );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
