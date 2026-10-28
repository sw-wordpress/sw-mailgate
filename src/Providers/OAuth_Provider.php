<?php
/**
 * Common configuration, token renewal and safe HTTP outcomes for OAuth mail APIs.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Http\Http_Client;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\OAuth\OAuth_Client;

abstract class OAuth_Provider implements Provider {

	public function __construct(
		protected readonly Http_Client $http = new Http_Client(),
		protected readonly OAuth_Client $oauth = new OAuth_Client()
	) {}

	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {
		// The dispatcher prepares SMTP MIME. HTTP providers never call postSend().
	}

	protected function client_fields(): array {
		return array(
			new Field( 'client_id', __( 'Client ID', 'sw-mailgate' ), required: true ),
			Field::secret( 'client_secret', __( 'Client secret', 'sw-mailgate' ), __( 'The secret value from your OAuth application. Leave blank to keep the stored value.', 'sw-mailgate' ) ),
			new Field( 'timeout', __( 'Timeout (seconds)', 'sw-mailgate' ), Field::NUMBER, default: '15' ),
		);
	}

	/**
	 * A definite 401 can be retried once with a renewed token. Never replay ambiguous
	 * sends or successful submissions, including successful responses without metadata.
	 */
	protected function request( Connection $connection, string $url, string $body, string $content_type ): array|Send_Result {
		$token = $this->oauth->access_token( $connection );
		if ( $token instanceof Send_Result ) {
			return $token;
		}
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$response = $this->http->request(
				'POST',
				$url,
				array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => $content_type,
					'Accept'        => 'application/json',
				),
				$body,
				(int) $connection->get( 'timeout', '15' )
			);
			if ( 401 !== $response['status'] || 1 === $attempt ) {
				$id     = $response['data']['id'] ?? null;
				$secret = $connection->get( 'client_secret' );
				if ( is_string( $id ) && ( str_contains( $id, $token ) || ( '' !== $secret && str_contains( $id, $secret ) ) ) ) {
					$response['data']['id'] = null;
				}
				return $response;
			}
			$token = $this->oauth->access_token( $connection, true );
			if ( $token instanceof Send_Result ) {
				return $token;
			}
		}
		return $response;
	}

	protected function failure( array $response ): Send_Result {
		$status  = $response['status'];
		$class   = in_array( $status, array( 401, 403, 404 ), true ) ? Send_Result::AUTH : Send_Result::class_for_http_status( $status );
		$message = match ( true ) {
			0 === $status => __( 'The mail API could not be reached. Check connectivity, TLS and timeout settings.', 'sw-mailgate' ),
			in_array( $status, array( 401, 403, 404 ), true ) => __( 'The mail API rejected access. Check consent, mailbox permissions, sender aliases and the client secret expiry.', 'sw-mailgate' ),
			429 === $status => __( 'The mail API rate limit was reached. Try again later.', 'sw-mailgate' ),
			default => sprintf( /* translators: %d: HTTP status */ __( 'The mail API rejected the request (HTTP %d).', 'sw-mailgate' ), $status ),
		};
		$result = Send_Result::failure( $class, $message, $response['debug'] )->with_duration( $response['duration'] );
		return $response['ambiguous'] ? $result->as_ambiguous() : $result;
	}

	public function test_connection( Connection $connection ): Send_Result {
		$token = $this->oauth->access_token( $connection, true );
		return $token instanceof Send_Result ? $token : Send_Result::success()->reclassify(
			'',
			__( 'OAuth token acquired. This does not verify mailbox Send As permissions or delivery; use Send test mail to check those.', 'sw-mailgate' )
		);
	}

	abstract public function send( Envelope $envelope, Connection $connection ): Send_Result;
}
