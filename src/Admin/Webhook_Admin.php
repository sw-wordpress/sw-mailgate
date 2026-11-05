<?php
/** Explicit, authorized provider-side webhook registration.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Webhook_Capable;
use SWMailgate\Tracking\Webhook_Controller;

final class Webhook_Admin {
	public function __construct( private readonly Connection_Repository $connections, private readonly Provider_Registry $providers ) {}
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}
	public function menu(): void {
		add_submenu_page( '', __( 'Register webhooks', 'sw-mailgate' ), __( 'Register webhooks', 'sw-mailgate' ), Capabilities::MANAGE, 'sw-mailgate-webhooks', array( $this, 'render' ) );
	}
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sw-mailgate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'sw_mailgate_webhooks' );
		$id         = isset( $_POST['connection'] ) && is_string( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		$connection = $this->connections->find( $id );
		$provider   = null === $connection ? null : $this->providers->get( $connection->provider() );
		if ( null === $connection || ! $provider instanceof Webhook_Capable ) {
			wp_die( esc_html__( 'This connection does not support webhooks.', 'sw-mailgate' ) );
		}
		$result = $provider->register_webhook( $connection, Webhook_Controller::url( $id ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Register webhooks', 'sw-mailgate' ) . '</h1><p>' . esc_html( $result->is_success() ? __( 'Webhooks registered.', 'sw-mailgate' ) : $result->message() ) . '</p><a href="' . esc_url(
			Admin_Page::url(
				array(
					'view'       => 'edit',
					'connection' => $id,
				)
			)
		) . '">' . esc_html__( 'Back to connection', 'sw-mailgate' ) . '</a></div>';
	}
}
