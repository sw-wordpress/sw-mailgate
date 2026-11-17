<?php
/**
 * On-demand transport probes and local OAuth grant diagnostics for Site Health.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Admin;

use SWMailgate\Connections\Connection_Repository;
use SWMailgate\OAuth\OAuth_Client;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Security\Secret_Store;

final class Health_Checks {
	public function __construct( private readonly Connection_Repository $connections, private readonly Provider_Registry $providers, private readonly Secret_Store $secrets ) {}

	/** @param array<mixed> $tests
	 * @return array<mixed> */
	public function register( array $tests ): array {
		foreach ( array( 'connections', 'oauth' ) as $name ) {
			$tests['direct'][ 'sw_mailgate_' . $name ] = array(
				'label' => 'oauth' === $name ? __( 'Mailgate OAuth authorization', 'sw-mailgate' ) : __( 'Mailgate connections', 'sw-mailgate' ),
				'test'  => array( $this, $name ),
			);
		}
		return $tests;
	}

	/** @return array<string, mixed> */
	public function connections(): array {
		$failed = false;
		foreach ( $this->connections->chain() as $connection ) {
			$provider = $this->providers->get( $connection->provider() );
			try {
				$failed = ( null === $provider || ! $provider->test_connection( $connection )->is_success() ) || $failed;
			} catch ( \Throwable ) {
				$failed = true;
			}
		}
		return $this->result( 'connections', $failed || array() === $this->connections->chain(), __( 'Mailgate connections', 'sw-mailgate' ), __( 'Enabled connections are probed without sending mail. No enabled connection means WordPress uses its native transport. Send a test mail to verify sender permissions and delivery.', 'sw-mailgate' ) );
	}

	/** @return array<string, mixed> */
	public function oauth(): array {
		$client = new OAuth_Client( secrets: $this->secrets );
		$failed = false;
		foreach ( $this->connections->chain() as $connection ) {
			if ( OAuth_Client::supports( $connection->provider() ) && OAuth_Client::delegated( $connection ) && ! $client->connected( $connection ) ) {
				$failed = true;
			}
		}
		return $this->result( 'oauth', $failed, __( 'Mailgate OAuth authorization', 'sw-mailgate' ), __( 'Checks local delegated grants for a valid access token or renewable authorization. Provider revocation is detected by the connection probe. Application credentials are checked there too.', 'sw-mailgate' ) );
	}

	/** @return array<string, mixed> */
	private function result( string $name, bool $failed, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $failed ? 'recommended' : 'good',
			'badge'       => array(
				'label' => 'Mailgate',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '<a href="' . esc_url( Admin_Page::url() ) . '">' . esc_html__( 'Review connections', 'sw-mailgate' ) . '</a>',
			'test'        => 'sw_mailgate_' . $name,
		);
	}
}
