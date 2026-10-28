<?php
/**
 * Capability-protected OAuth actions. Provider callbacks never echo codes or errors.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\OAuth\OAuth_Client;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Routing\Circuit_Breaker;

final class OAuth_Admin {

	public function __construct(
		private readonly Connection_Repository $connections,
		private readonly OAuth_Client $client = new OAuth_Client()
	) {}

	public function register(): void {
		add_action( 'admin_post_sw_mailgate_oauth_connect', array( $this, 'connect' ) );
		add_action( 'admin_post_sw_mailgate_oauth_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_sw_mailgate_oauth_callback', array( $this, 'callback' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	private function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage OAuth connections.', 'sw-mailgate' ), 403 );
		}
	}

	public function connect(): void {
		$this->require_capability();
		$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		check_admin_referer( 'sw_mailgate_oauth_' . $id );
		$connection = $this->connections->find( $id );
		if ( null === $connection || ! OAuth_Client::supports( $connection->provider() ) ) {
			$this->redirect();
		}
		$url = $this->client->authorize( $connection );
		if ( $url instanceof Send_Result ) {
			$this->result( $url );
			$this->redirect( $id );
		}
		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Constructed from a fixed provider endpoint.
		wp_redirect( $url );
		exit;
	}

	public function disconnect(): void {
		$this->require_capability();
		$id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		check_admin_referer( 'sw_mailgate_oauth_' . $id );
		$connection = $this->connections->find( $id );
		if ( null !== $connection && OAuth_Client::supports( $connection->provider() ) ) {
			$this->client->disconnect( $connection );
			$this->result( Send_Result::success()->reclassify( '', __( 'Local OAuth credentials were removed. To revoke the app grant, also remove its access in your Google or Microsoft account.', 'sw-mailgate' ) ) );
		}
		$this->redirect( $id );
	}

	public function callback(): void {
		$this->require_capability();
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Session-bound, one-time state replaces a nonce. Codes are opaque and validated by OAuth_Client.
		$state  = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
		$code   = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
		$denied = isset( $_GET['error'] );
		// phpcs:enable
		$outcome = $this->client->callback( $state, $code, $denied );
		$result  = $outcome['result'];
		if ( $result->is_success() ) {
			( new Circuit_Breaker() )->close( $outcome['connection'] );
			$result = $result->reclassify( '', __( 'OAuth mailbox connected. You can now send a test mail.', 'sw-mailgate' ) );
		}
		$this->result( $result );
		$this->redirect( null === $outcome['connection'] ? '' : $outcome['connection']->id() );
	}

	private function result( Send_Result $result ): void {
		set_transient(
			'sw_mailgate_oauth_notice_' . get_current_user_id(),
			array(
				'success' => $result->is_success(),
				'message' => $result->message(),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	public function notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$key    = 'sw_mailgate_oauth_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['success'] ? 'success' : 'error', esc_html( $notice['message'] ) );
	}

	private function redirect( string $id = '' ): never {
		wp_safe_redirect(
			Admin_Page::url(
				'' === $id ? array() : array(
					'view'       => 'edit',
					'connection' => $id,
				)
			)
		);
		exit;
	}
}
