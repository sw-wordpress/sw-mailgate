<?php
/**
 * Paginated attempt list using the native WordPress table UI.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Logging\Log_Repository;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Log_Table extends \WP_List_Table {

	public function __construct( private readonly Log_Repository $logs, private readonly array $filters ) {
		parent::__construct(
			array(
				'singular' => 'mail-attempt',
				'plural'   => 'mail-attempts',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'created_at'    => __( 'Time (UTC)', 'sw-mailgate' ),
			'status'        => __( 'Status', 'sw-mailgate' ),
			'connection_id' => __( 'Connection', 'sw-mailgate' ),
			'recipients'    => __( 'Recipients', 'sw-mailgate' ),
			'subject'       => __( 'Subject', 'sw-mailgate' ),
			'duration'      => __( 'Duration (s)', 'sw-mailgate' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'subject' );
		$this->items           = $this->logs->search( $this->filters, 20, ( $this->get_pagenum() - 1 ) * 20 );
		$this->set_pagination_args(
			array(
				'total_items' => $this->logs->count( $this->filters ),
				'per_page'    => 20,
			)
		);
	}

	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function column_subject( $item ): string {
		return sprintf( '<a href="%s">%s</a>', esc_url( Admin_Page::url( array( 'log_id' => $item['id'] ), Log_Page::SLUG ) ), esc_html( '' === $item['subject'] ? __( '(no subject)', 'sw-mailgate' ) : $item['subject'] ) );
	}

	public function column_status( $item ): string {
		return esc_html(
			match ( $item['status'] ) {
			'sent'       => __( 'Sent', 'sw-mailgate' ),
			'redirected' => __( 'Redirected', 'sw-mailgate' ),
			default      => __( 'Failed', 'sw-mailgate' ),
			}
		);
	}

	public function column_recipients( $item ): string {
		return esc_html( implode( ', ', (array) json_decode( $item['recipients'], true ) ) );
	}
}
