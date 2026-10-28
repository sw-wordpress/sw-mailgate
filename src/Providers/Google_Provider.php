<?php
/**
 * Gmail send-only API; larger MIME messages use the media upload endpoint.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Envelope;

final class Google_Provider extends OAuth_Provider {

	public function id(): string {
		return 'google';
	}
	public function label(): string {
		return __( 'Google / Gmail', 'sw-mailgate' );
	}
	public function fields(): array {
		return $this->client_fields();
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$mime = $envelope->mime( true );
		if ( strlen( $mime ) > 35 * MB_IN_BYTES ) {
			return Send_Result::failure( Send_Result::PERMANENT, __( 'The MIME message exceeds the Gmail API limit of 35 MB.', 'sw-mailgate' ) );
		}
		$upload = strlen( $mime ) > 5 * MB_IN_BYTES;
		$url    = $upload ? 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media' : 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Gmail API wire format.
		$body     = $upload ? $mime : wp_json_encode( array( 'raw' => rtrim( strtr( base64_encode( $mime ), '+/', '-_' ), '=' ) ) );
		$response = $this->request( $connection, $url, $body, $upload ? 'message/rfc822' : 'application/json' );
		if ( $response instanceof Send_Result ) {
			return $response;
		}
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return $this->failure( $response );
		}
		$id = $response['data']['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $id ) ) {
			return Send_Result::failure( Send_Result::UNCERTAIN, __( 'Gmail returned success without a valid message ID. Automatic failover was stopped to avoid duplicates.', 'sw-mailgate' ), $response['debug'] )->with_duration( $response['duration'] );
		}
		return Send_Result::success( $id, $response['debug'] )->with_duration( $response['duration'] );
	}
}
