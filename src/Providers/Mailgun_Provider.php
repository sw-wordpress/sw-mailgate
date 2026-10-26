<?php
/**
 * Mailgun MIME API transport with an explicit envelope and regional endpoints.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Http\Http_Client;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;

final class Mailgun_Provider implements Provider {

	public function __construct( private readonly Http_Client $http = new Http_Client() ) {
	}

	public function id(): string {
		return 'mailgun';
	}

	public function label(): string {
		return __( 'Mailgun', 'sw-mailgate' );
	}

	public function fields(): array {
		return array(
			new Field( 'domain', __( 'Sending domain', 'sw-mailgate' ), Field::TEXT, false, '', array(), __( 'The domain configured in Mailgun, e.g. mg.example.com. Enter the domain, not an API URL.', 'sw-mailgate' ), true ),
			new Field(
				'region',
				__( 'Region', 'sw-mailgate' ),
				Field::SELECT,
				false,
				'us',
				array(
					'us' => __( 'US', 'sw-mailgate' ),
					'eu' => __( 'EU', 'sw-mailgate' ),
				),
				__( 'Select the region where this Mailgun domain was created.', 'sw-mailgate' ),
				true
			),
			Field::secret( 'api_key', __( 'API key', 'sw-mailgate' ), __( 'Use a Mailgun API key, not an SMTP password. The connection test also requires permission to read domain details; a sending-only key may not allow that test.', 'sw-mailgate' ) ),
			new Field( 'timeout', __( 'Timeout (seconds)', 'sw-mailgate' ), Field::NUMBER, false, '15', array(), __( 'HTTP timeout, between 5 and 60 seconds.', 'sw-mailgate' ) ),
		);
	}

	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {
		// The dispatcher prepares SMTP-format MIME with CRLF. HTTP never calls postSend().
	}

	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$config = $this->configuration( $connection );
		if ( $config instanceof Send_Result ) {
			return $config;
		}
		try {
			$upload   = $this->http->multipart(
				array( 'to' => implode( ',', $envelope->recipients() ) ),
				array(
					'message' => array(
						'filename' => 'message.eml',
						'type'     => 'message/rfc822',
						'contents' => $envelope->mime(),
					),
				)
			);
			$response = $this->http->request( 'POST', $config['base'] . '/v3/' . rawurlencode( $config['domain'] ) . '/messages.mime', array_merge( $config['headers'], $upload['headers'] ), $upload['body'], $config['timeout'] );
		} catch ( \Throwable $error ) {
			return Send_Result::failure( Send_Result::TRANSIENT, __( 'Mailgun: the MIME request could not be prepared.', 'sw-mailgate' ) );
		}
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return $this->failure( $response );
		}
		$id = $response['data']['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id || strlen( $id ) > 998 || preg_match( '/[\x00-\x20\x7f]/', $id ) || str_contains( $id, $config['key'] ) ) {
			// A successful HTTP response may mean the mail was accepted. Do not duplicate it via failover.
			return Send_Result::failure( Send_Result::PERMANENT, __( 'Mailgun returned a successful HTTP response without a valid message ID. Delivery is uncertain; automatic failover was stopped.', 'sw-mailgate' ), $response['debug'] )->with_duration( $response['duration'] );
		}
		return Send_Result::success( $id, $response['debug'] )->with_duration( $response['duration'] );
	}

	public function test_connection( Connection $connection ): Send_Result {
		$config = $this->configuration( $connection );
		if ( $config instanceof Send_Result ) {
			return $config;
		}
		// Mailgun's current domain-details API is v4; message submission remains v3.
		$response = $this->http->request( 'GET', $config['base'] . '/v4/domains/' . rawurlencode( $config['domain'] ), $config['headers'], '', $config['timeout'] );
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			return $this->failure( $response );
		}
		$domain = $response['data']['domain'] ?? null;
		if ( ! is_array( $domain ) || ! is_string( $domain['name'] ?? null ) || 0 !== strcasecmp( $domain['name'], $config['domain'] ) || ! is_string( $domain['state'] ?? null ) ) {
			return Send_Result::failure( Send_Result::PERMANENT, __( 'Mailgun returned invalid domain details. Check the sending domain and region.', 'sw-mailgate' ), $response['debug'] )->with_duration( $response['duration'] );
		}
		if ( 'active' !== $domain['state'] ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'The Mailgun domain is not active. Check domain verification and account status.', 'sw-mailgate' ), $response['debug'] )->with_duration( $response['duration'] );
		}
		return Send_Result::success( '', $response['debug'] )->with_duration( $response['duration'] );
	}

	/** @return array|Send_Result Validated credentials, never included in diagnostics. */
	private function configuration( Connection $connection ): array|Send_Result {
		$domain = strtolower( trim( $connection->get( 'domain' ) ) );
		$region = $connection->get( 'region', 'us' );
		$key    = $connection->get( 'api_key' );
		if ( strlen( $domain ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain ) || filter_var( $domain, FILTER_VALIDATE_IP ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Enter a valid Mailgun sending domain without a scheme, path or port.', 'sw-mailgate' ) );
		}
		if ( ! in_array( $region, array( 'us', 'eu' ), true ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Select a valid Mailgun region: US or EU.', 'sw-mailgate' ) );
		}
		if ( '' === trim( $key ) || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'The Mailgun API key is missing or invalid. Enter it again or check the wp-config.php override.', 'sw-mailgate' ) );
		}
		return array(
			'base'    => 'eu' === $region ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net',
			'domain'  => $domain,
			'key'     => $key,
			'timeout' => max( 5, min( 60, (int) $connection->get( 'timeout', '15' ) ) ),
			'headers' => array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication, not stored or logged.
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ),
				'Accept'        => 'application/json',
			),
		);
	}

	private function failure( array $response ): Send_Result {
		$status  = $response['status'];
		$message = match ( true ) {
			0 === $status => __( 'Mailgun HTTP request failed. Check connectivity, TLS and timeout settings.', 'sw-mailgate' ),
			401 === $status, 403 === $status => __( 'Mailgun rejected authentication or domain permissions. Check the API key and its permissions.', 'sw-mailgate' ),
			404 === $status => __( 'Mailgun domain or API resource was not found. Check the sending domain and region.', 'sw-mailgate' ),
			429 === $status => __( 'Mailgun rate limit reached. Try again later.', 'sw-mailgate' ),
			default => sprintf( /* translators: %d: HTTP status code */ __( 'Mailgun request failed (HTTP %d).', 'sw-mailgate' ), $status ),
		};
		$result = Send_Result::failure( Send_Result::class_for_http_status( $status ), $message, $response['debug'] )->with_duration( $response['duration'] );
		return empty( $response['ambiguous'] ) ? $result : $result->as_ambiguous();
	}
}
