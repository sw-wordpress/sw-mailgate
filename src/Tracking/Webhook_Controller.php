<?php
/** Verified, bounded webhook intake.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Tracking;

use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Webhook_Capable;

final class Webhook_Controller {
	public function __construct( private readonly Connection_Repository $connections, private readonly Provider_Registry $providers ) {}

	public static function url( string $id ): string {
		return rest_url( 'sw-mailgate/v1/webhook/' . rawurlencode( $id ) );
	}

	public function register(): void {
		register_rest_route(
			'sw-mailgate/v1',
			'/webhook/(?P<connection>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true', // Authentication uses the provider's HMAC, never cookies.
			)
		);
	}

	public function receive( \WP_REST_Request $request ): \WP_REST_Response {
		if ( strlen( $request->get_body() ) > 65536 ) {
			return new \WP_REST_Response( array( 'error' => 'Payload too large.' ), 413 );
		}
		$connection = $this->connections->find( (string) $request['connection'] );
		$provider   = null === $connection ? null : $this->providers->get( $connection->provider() );
		$token      = null !== $connection && $provider instanceof Webhook_Capable ? $provider->verify_request( $connection, $request ) : false;
		if ( null === $connection || ! $provider instanceof Webhook_Capable || false === $token ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid webhook.' ), 403 );
		}
		$stored = ( new Event_Repository() )->accept( $connection, $token, $provider->parse_events( $request ) );
		return new \WP_REST_Response( array( 'accepted' => $stored ), $stored ? 200 : 503 );
	}
}
