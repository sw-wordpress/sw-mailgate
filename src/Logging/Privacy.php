<?php
/**
 * WordPress personal data export and erasure for stored mail attempts.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Logging;

final class Privacy {

	public function __construct( private readonly Log_Repository $logs ) {
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	public function exporters( array $exporters ): array {
		$exporters['sw-mailgate'] = array(
			'exporter_friendly_name' => __( 'SW Mailgate mail log', 'sw-mailgate' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function erasers( array $erasers ): array {
		$erasers['sw-mailgate'] = array(
			'eraser_friendly_name' => __( 'SW Mailgate mail log', 'sw-mailgate' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	public function export( string $email, int $page = 1 ): array {
		$rows = $this->logs->personal( $email, 100, ( max( 1, $page ) - 1 ) * 100 );
		$data = array();
		foreach ( $rows as $row ) {
			$fields = array();
			// Other recipients and message content can identify third parties, so do not export them.
			foreach ( array( 'created_at', 'status', 'connection_id', 'provider', 'attempt_group', 'attempt_number', 'duration' ) as $key ) {
				$fields[] = array(
					'name'  => $key,
					'value' => (string) $row[ $key ],
				);
			}
			$fields[] = array(
				'name'  => __( 'Email address', 'sw-mailgate' ),
				'value' => $email,
			);
			$data[]   = array(
				'group_id'    => 'sw-mailgate',
				'group_label' => __( 'Mail log', 'sw-mailgate' ),
				'item_id'     => 'sw-mailgate-' . $row['id'],
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required WordPress callback signature.
		// Deletion changes the result set, so always take the first batch.
		$rows     = $this->logs->personal( $email );
		$removed  = false;
		$retained = false;
		foreach ( $rows as $row ) {
			if ( $this->logs->delete( (int) $row['id'] ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Some mail log entries could not be deleted. Please retry.', 'sw-mailgate' ) ) : array(),
			'done'           => $retained || count( $rows ) < 100,
		);
	}
}
