<?php
/**
 * Microsoft Graph MIME sendMail with delegated or application authorization.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Envelope;
use SWMailgate\OAuth\OAuth_Client;

final class Office365_Provider extends OAuth_Provider {

	public function id(): string {
		return 'office365';
	}
	public function label(): string {
		return __( 'Microsoft 365', 'sw-mailgate' );
	}
	public function fields(): array {
		return array_merge(
			array(
				new Field(
					'mode',
					__( 'Authorization mode', 'sw-mailgate' ),
					Field::SELECT,
					default: 'delegated',
					options: array(
						'delegated'   => __( 'Delegated (sign in to a mailbox)', 'sw-mailgate' ),
						'application' => __( 'Application (client credentials)', 'sw-mailgate' ),
					)
				),
				new Field( 'tenant_id', __( 'Directory (tenant) ID', 'sw-mailgate' ), required: true, description: __( 'Use the UUID from your Microsoft Entra app overview. This connection supports one tenant in the global Microsoft cloud.', 'sw-mailgate' ) ),
				new Field( 'mailbox', __( 'Mailbox address (application mode)', 'sw-mailgate' ), Field::EMAIL, description: __( 'Required in application mode. Delegated mode sends through the signed-in mailbox. From aliases need provider-side Send As permission.', 'sw-mailgate' ) ),
			),
			$this->client_fields()
		);
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Graph requires base64-encoded MIME.
		$body = base64_encode( $envelope->mime( true ) );
		if ( strlen( $body ) > 4 * MB_IN_BYTES ) {
			return Send_Result::failure( Send_Result::PERMANENT, __( 'The encoded MIME message exceeds the 4 MB Graph request limit. Reduce attachments; large attachment upload sessions are not supported.', 'sw-mailgate' ) );
		}
		$target   = OAuth_Client::delegated( $connection ) ? 'me' : 'users/' . rawurlencode( $connection->get( 'mailbox' ) );
		$response = $this->request( $connection, 'https://graph.microsoft.com/v1.0/' . $target . '/sendMail', $body, 'text/plain' );
		if ( $response instanceof Send_Result ) {
			return $response;
		}
		if ( 202 === $response['status'] ) {
			// Graph deliberately returns no message ID; keep the local RFC 5322 ID.
			return Send_Result::success( $envelope->message_id(), $response['debug'] )->with_duration( $response['duration'] );
		}
		if ( $response['status'] >= 200 && $response['status'] < 300 ) {
			return Send_Result::failure( Send_Result::UNCERTAIN, __( 'Graph returned an unexpected success response. Automatic failover was stopped to avoid duplicates.', 'sw-mailgate' ), $response['debug'] );
		}
		return $this->failure( $response );
	}
}
