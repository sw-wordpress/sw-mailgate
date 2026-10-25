<?php
/**
 * Mail log, preferences, CSV export, resend and dashboard totals.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Redirection_Settings;

final class Log_Page {

	public const SLUG          = 'sw-mailgate-log';
	public const SETTINGS_SLUG = 'sw-mailgate-settings';

	public function __construct( private readonly Log_Repository $logs, private readonly Connection_Repository $connections ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sw_mailgate_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_sw_mailgate_export_log', array( $this, 'export' ) );
		add_action( 'admin_post_sw_mailgate_resend', array( $this, 'resend' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ) );
		add_action( 'admin_notices', array( $this, 'redirection_notice' ) );
	}

	public function menu(): void {
		add_submenu_page( Admin_Page::SLUG, __( 'Mail log', 'sw-mailgate' ), __( 'Mail log', 'sw-mailgate' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ) );
		add_submenu_page( Admin_Page::SLUG, __( 'Mailgate settings', 'sw-mailgate' ), __( 'Settings', 'sw-mailgate' ), Capabilities::MANAGE, self::SETTINGS_SLUG, array( $this, 'settings' ) );
	}

	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sw-mailgate' ), '', array( 'response' => 403 ) );
		}
	}

	public function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list/export filters; export verifies a nonce separately.
		$filters = array();
		foreach ( array( 'status', 'connection_id', 'date_from', 'date_to', 's' ) as $key ) {
			$filters[ $key ] = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		// phpcs:enable
		if ( ! in_array( $filters['status'], array( 'sent', 'failed', 'redirected' ), true ) ) {
			$filters['status'] = '';
		}
		return $filters;
	}

	public function render(): void {
		$this->authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only detail navigation.
		$id = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0;
		if ( $id ) {
			$row = $this->logs->find( $id );
			if ( null === $row ) {
				wp_die( esc_html__( 'Log entry not found.', 'sw-mailgate' ) );
			}
			$attempts = $this->logs->attempts( $row['attempt_group'] );
			include SW_MAILGATE_DIR . 'templates/admin/log-detail.php';
			return;
		}
		$filters     = $this->filters();
		$connections = $this->connections->all();
		$table       = new Log_Table( $this->logs, $filters );
		$table->prepare_items();
		$export_url = wp_nonce_url( add_query_arg( array_merge( $filters, array( 'action' => 'sw_mailgate_export_log' ) ), admin_url( 'admin-post.php' ) ), 'sw_mailgate_export_log' );
		include SW_MAILGATE_DIR . 'templates/admin/log.php';
	}

	public function settings(): void {
		$this->authorize();
		$settings    = Settings::get();
		$delete_data = (bool) get_option( Installer::DELETE_DATA_OPTION );
		$redirection = Redirection_Settings::get();
		include SW_MAILGATE_DIR . 'templates/admin/settings.php';
	}

	public function save_settings(): void {
		$this->authorize();
		check_admin_referer( 'sw_mailgate_save_settings' );
		if ( ( isset( $_POST['redirect_to'] ) && ! is_string( $_POST['redirect_to'] ) ) || ( isset( $_POST['redirect_allowlist'] ) && ! is_string( $_POST['redirect_allowlist'] ) ) ) {
			wp_die( esc_html__( 'Invalid redirection settings.', 'sw-mailgate' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Redirection_Settings validates every complete address/domain and rejects invalid input without coercion.
		$target = isset( $_POST['redirect_to'] ) && is_string( $_POST['redirect_to'] ) ? trim( wp_unslash( $_POST['redirect_to'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every rule is validated by Redirection_Settings::save().
		$allowlist = isset( $_POST['redirect_allowlist'] ) && is_string( $_POST['redirect_allowlist'] ) ? wp_unslash( $_POST['redirect_allowlist'] ) : '';
		$error     = Redirection_Settings::save( $target, $allowlist );
		if ( null !== $error ) {
			wp_die( esc_html( $error->get_error_message() ) );
		}
		Settings::save(
			array(
				'store_body'      => isset( $_POST['store_body'] ),
				'mask_recipients' => isset( $_POST['mask_recipients'] ),
				'retention'       => isset( $_POST['retention'] ) ? absint( $_POST['retention'] ) : 30,
			)
		);
		update_option( Installer::DELETE_DATA_OPTION, isset( $_POST['delete_data'] ), false );
		wp_safe_redirect( Admin_Page::url( array( 'saved' => '1' ), self::SETTINGS_SLUG ) );
		exit;
	}

	public function redirection_notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$settings = Redirection_Settings::get();
		if ( ! $settings['valid'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'SW Mailgate: the redirection address is invalid. Mail sending is blocked until the configuration is corrected.', 'sw-mailgate' ) . '</p></div>';
		} elseif ( 'production' !== wp_get_environment_type() && '' === $settings['target'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'SW Mailgate: this is a non-production site and mail redirection is disabled. Emails will reach their real recipients.', 'sw-mailgate' ) . '</p></div>';
		}
	}

	/** Prefix cells that spreadsheet software could interpret as formulas. */
	public static function csv_cell( mixed $value ): string {
		$text = (string) $value;
		return preg_match( '/^[\s\x00-\x1f]*[=+@-]/u', $text ) || preg_match( '/^[\t\r\n]/', $text ) ? "'" . $text : $text;
	}

	public function export(): void {
		$this->authorize();
		check_admin_referer( 'sw_mailgate_export_log' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="sw-mailgate-log.csv"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Output stream, no filesystem access.
		$stream = fopen( 'php://output', 'w' );
		$fields = array( 'id', 'created_at', 'status', 'connection_id', 'provider', 'attempt_group', 'attempt_number', 'from_email', 'recipients', 'original_to', 'subject', 'error_class', 'error_message', 'duration', 'provider_message_id', 'caller' );
		fputcsv( $stream, $fields, ',', '"', '' );
		$filters = $this->filters();
		for ( $offset = 0; ; $offset += 500 ) {
			$rows = $this->logs->search( $filters, 500, $offset );
			foreach ( $rows as $row ) {
				fputcsv( $stream, array_map( static fn( string $key ): string => self::csv_cell( $row[ $key ] ), $fields ), ',', '"', '' );
			}
			if ( count( $rows ) < 500 ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream.
		fclose( $stream );
		exit;
	}

	public function resend(): void {
		$this->authorize();
		$id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		check_admin_referer( 'sw_mailgate_resend_' . $id );
		try {
			$sent = $this->send_again( $id );
		} catch ( \RuntimeException $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		wp_safe_redirect(
			Admin_Page::url(
				array(
					'log_id' => $id,
					'resent' => $sent ? 'sent' : 'failed',
				),
				self::SLUG
			)
		);
		exit;
	}

	/**
	 * Resends through the current failover chain, preserving recipient roles.
	 *
	 * @throws \RuntimeException When content or an active connection is unavailable.
	 */
	public function send_again( int $id ): bool {
		$row     = $this->logs->find( $id );
		$payload = null === $row ? null : json_decode( (string) $row['payload'], true );
		if ( ! is_array( $payload ) || ! empty( $payload['attachments'] ) || array() === $this->connections->chain() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The form handler escapes the message at the output boundary.
			throw new \RuntimeException( __( 'This message cannot be resent. Stored content and an active connection are required; attachments are not retained.', 'sw-mailgate' ) );
		}
		$restore = static function ( $mailer ) use ( $payload ): void {
			$mailer->clearAllRecipients();
			$mailer->clearReplyTos();
			foreach ( array(
				'to'       => 'addAddress',
				'cc'       => 'addCC',
				'bcc'      => 'addBCC',
				'reply_to' => 'addReplyTo',
			) as $key => $method ) {
				foreach ( $payload[ $key ] as $address ) {
					$mailer->$method( $address[0], $address[1] ?? '' );
				}
			}
			$mailer->setFrom( $payload['from'], $payload['from_name'], false );
			$mailer->Body        = $payload['body'];
			$mailer->AltBody     = $payload['alt_body'];
			$mailer->ContentType = $payload['content_type'];
			$mailer->CharSet     = $payload['charset'];
		};
		add_action( 'phpmailer_init', $restore, PHP_INT_MAX );
		try {
			$sent = wp_mail( array_column( $payload['to'], 0 ), $row['subject'], $payload['body'] );
		} finally {
			remove_action( 'phpmailer_init', $restore, PHP_INT_MAX );
		}
		return $sent;
	}

	public function dashboard(): void {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			wp_add_dashboard_widget( 'sw_mailgate_totals', __( 'Mailgate delivery', 'sw-mailgate' ), array( $this, 'widget' ) );
		}
	}

	public function widget(): void {
		$this->authorize();
		echo '<p>' . esc_html__( 'Final outcomes per message. Only retained log entries are counted.', 'sw-mailgate' ) . '</p>';
		foreach ( array( 7, 30 ) as $days ) {
			$totals = $this->logs->totals( $days );
			printf(
				'<p>%s</p>',
				/* translators: 1: days, 2: sent messages, 3: failed messages */
				esc_html( sprintf( __( 'Last %1$d days: %2$d sent, %3$d failed.', 'sw-mailgate' ), $days, $totals['sent'], $totals['failed'] ) )
			);
		}
		echo '<a href="' . esc_url( Admin_Page::url( array(), self::SLUG ) ) . '">' . esc_html__( 'View mail log', 'sw-mailgate' ) . '</a>';
	}
}
