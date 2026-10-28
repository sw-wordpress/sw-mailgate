<?php
/**
 * Server-side OAuth with fixed endpoints, PKCE, session-bound state and encrypted tokens.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\OAuth;

use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Http\Http_Client;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Security\Secret_Store;

final class OAuth_Client {

	public const GOOGLE_SCOPE  = 'https://www.googleapis.com/auth/gmail.send';
	public const GRAPH_SCOPE   = 'https://graph.microsoft.com/Mail.Send';
	private const STATE_PREFIX = 'sw_mailgate_oauth_state_';
	private const LOCK_PREFIX  = '_transient_sw_mailgate_oauth_lock_';

	public function __construct(
		private readonly Http_Client $http = new Http_Client(),
		private readonly Secret_Store $secrets = new Secret_Store()
	) {}

	public static function supports( string $provider ): bool {
		return in_array( $provider, array( 'google', 'office365' ), true );
	}

	public static function delegated( Connection $connection ): bool {
		return 'google' === $connection->provider() || 'delegated' === $connection->get( 'mode', 'delegated' );
	}

	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=sw_mailgate_oauth_callback', 'https' );
	}

	/** @return array|Send_Result Validated configuration, never rendered or logged. */
	private function configuration( Connection $connection ): array|Send_Result {
		$id     = $connection->get( 'client_id' );
		$secret = $connection->get( 'client_secret' );
		if ( ! self::supports( $connection->provider() ) || '' === $id || strlen( $id ) > 512 || preg_match( '/[\x00-\x20\x7f]/', $id ) || '' === $secret || preg_match( '/[\x00-\x1f\x7f]/', $secret ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Save a valid OAuth client ID and client secret first.', 'sw-mailgate' ) );
		}
		$google = 'google' === $connection->provider();
		$tenant = $connection->get( 'tenant_id' );
		if ( ! $google && ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $tenant ) || ! in_array( $connection->get( 'mode', 'delegated' ), array( 'delegated', 'application' ), true ) ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Microsoft 365 requires a directory (tenant) UUID and a valid authorization mode.', 'sw-mailgate' ) );
		}
		if ( ! $google && ! self::delegated( $connection ) && ! is_email( $connection->get( 'mailbox' ) ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Enter the Microsoft 365 mailbox address for application access.', 'sw-mailgate' ) );
		}
		$base = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/';
		return array(
			'client_id'     => $id,
			'client_secret' => $secret,
			'authorize'     => $google ? 'https://accounts.google.com/o/oauth2/v2/auth' : $base . 'authorize',
			'token'         => $google ? 'https://oauth2.googleapis.com/token' : $base . 'token',
			'scope'         => $google ? self::GOOGLE_SCOPE : ( self::delegated( $connection ) ? self::GRAPH_SCOPE . ' offline_access' : 'https://graph.microsoft.com/.default' ),
		);
	}

	/** Bind credentials, tenant, mode, mailbox, site and disconnect generation. */
	private function fingerprint( Connection $connection ): string {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					get_current_blog_id(),
					self::redirect_uri(),
					$connection->id(),
					$connection->provider(),
					$connection->get( 'client_id' ),
					$connection->get( 'client_secret' ),
					$connection->get( 'tenant_id' ),
					$connection->get( 'mode', 'delegated' ),
					$connection->get( 'mailbox' ),
					$this->secrets->get( Connection::secret_name( $connection->id(), 'oauth_generation' ) ),
				)
			)
		);
	}

	private function unchanged( Connection $connection, string $fingerprint ): bool {
		wp_cache_delete( Connection_Repository::OPTION, 'options' );
		wp_cache_delete( Secret_Store::OPTION, 'options' );
		$fresh = ( new Connection_Repository( $this->secrets ) )->find( $connection->id() );
		return null !== $fresh && hash_equals( $fingerprint, $this->fingerprint( $fresh ) );
	}

	public function connected( Connection $connection ): bool {
		$tokens = $this->stored( $connection );
		return null !== $tokens && ( ! empty( $tokens['refresh_token'] ) || (int) ( $tokens['expires_at'] ?? 0 ) > time() );
	}

	private function stored( Connection $connection ): ?array {
		$tokens = json_decode( $this->secrets->get( Connection::secret_name( $connection->id(), 'oauth_tokens' ) ) ?? '', true );
		return is_array( $tokens ) && isset( $tokens['fingerprint'] ) && hash_equals( $this->fingerprint( $connection ), (string) $tokens['fingerprint'] ) ? $tokens : null;
	}

	public function disconnect( Connection $connection ): void {
		// Rotating the generation also invalidates in-flight callbacks and refreshes.
		$this->secrets->set( Connection::secret_name( $connection->id(), 'oauth_generation' ), bin2hex( random_bytes( 16 ) ) );
		$this->secrets->delete( Connection::secret_name( $connection->id(), 'oauth_tokens' ) );
	}

	/** Returns a provider URL or a safe configuration failure. Called only after capability/nonce checks. */
	public function authorize( Connection $connection ): string|Send_Result {
		$config = $this->configuration( $connection );
		if ( $config instanceof Send_Result ) {
			return $config;
		}
		if ( ! self::delegated( $connection ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Application mode uses client credentials; no browser authorization is needed.', 'sw-mailgate' ) );
		}
		$state    = bin2hex( random_bytes( 32 ) );
		$verifier = bin2hex( random_bytes( 32 ) );
		$payload  = array(
			'connection'  => $connection->id(),
			'user'        => get_current_user_id(),
			'session'     => hash( 'sha256', wp_get_session_token() ),
			'fingerprint' => $this->fingerprint( $connection ),
			'verifier'    => $verifier,
		);
		if ( ! set_transient( self::STATE_PREFIX . hash( 'sha256', $state ), $this->secrets->seal( wp_json_encode( $payload ) ), 10 * MINUTE_IN_SECONDS ) ) {
			return Send_Result::failure( Send_Result::TRANSIENT, __( 'OAuth authorization could not be started. Try again.', 'sw-mailgate' ) );
		}
		$params = array(
			'client_id'             => $config['client_id'],
			'redirect_uri'          => self::redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => $config['scope'],
			'state'                 => $state,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth PKCE S256 encoding.
			'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
			'code_challenge_method' => 'S256',
		);
		if ( 'google' === $connection->provider() ) {
			$params['access_type'] = 'offline';
			$params['prompt']      = 'consent';
		} else {
			$params['response_mode'] = 'query';
			$params['prompt']        = 'select_account';
		}
		return $config['authorize'] . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/** @return array{connection: ?Connection, result: Send_Result} */
	public function callback( string $state, string $code, bool $denied = false ): array {
		$invalid = Send_Result::failure( Send_Result::AUTH, __( 'OAuth authorization expired or did not match this signed-in session. Start again from the connection editor.', 'sw-mailgate' ) );
		$failure = array(
			'connection' => null,
			'result'     => $invalid,
		);
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $state ) ) {
			return $failure;
		}
		$key     = self::STATE_PREFIX . hash( 'sha256', $state );
		$sealed  = get_transient( $key );
		$payload = is_string( $sealed ) ? json_decode( $this->secrets->open( $sealed ) ?? '', true ) : null;
		if ( ! is_array( $payload ) || get_current_user_id() !== ( $payload['user'] ?? null ) || ! hash_equals( (string) ( $payload['session'] ?? '' ), hash( 'sha256', wp_get_session_token() ) ) ) {
			return $failure;
		}
		$lock = $this->lock( 'state-' . $state );
		if ( null === $lock ) {
			return $failure;
		}
		try {
			// Recheck after the atomic claim so two callbacks cannot consume one state.
			if ( get_transient( $key ) !== $sealed ) {
				return $failure;
			}
			delete_transient( $key );
			$connection = ( new Connection_Repository( $this->secrets ) )->find( (string) $payload['connection'] );
			if ( null === $connection || ! hash_equals( (string) $payload['fingerprint'], $this->fingerprint( $connection ) ) ) {
				return $failure;
			}
			if ( $denied || '' === $code || strlen( $code ) > 4096 || preg_match( '/[\x00-\x1f\x7f]/', $code ) ) {
				return array(
					'connection' => $connection,
					'result'     => Send_Result::failure( Send_Result::AUTH, __( 'Authorization was cancelled or the provider did not return a valid code.', 'sw-mailgate' ) ),
				);
			}
			$config = $this->configuration( $connection );
			if ( $config instanceof Send_Result ) {
				return array(
					'connection' => $connection,
					'result'     => $config,
				);
			}
			$result = $this->exchange(
				$connection,
				$config,
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
					'code_verifier' => $payload['verifier'],
				),
				null,
				true
			);
			return array(
				'connection' => $connection,
				'result'     => is_string( $result ) ? Send_Result::success() : $result,
			);
		} finally {
			$this->unlock( $lock );
		}
	}

	/** Returns an access token only to a provider, never to an admin result or log. */
	public function access_token( Connection $connection, bool $force = false ): string|Send_Result {
		$config = $this->configuration( $connection );
		if ( $config instanceof Send_Result ) {
			return $config;
		}
		$tokens = $this->stored( $connection );
		if ( ! $force && null !== $tokens && (int) ( $tokens['expires_at'] ?? 0 ) > time() + 60 ) {
			return $tokens['access_token'];
		}
		if ( self::delegated( $connection ) && empty( $tokens['refresh_token'] ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'Connect this mailbox in the connection editor before sending.', 'sw-mailgate' ) );
		}
		$lock = $this->lock( 'refresh-' . $connection->id() );
		if ( null === $lock ) {
			return Send_Result::failure( Send_Result::TRANSIENT, __( 'Another request is renewing this OAuth token. Try again shortly.', 'sw-mailgate' ) );
		}
		try {
			// Another worker may have rotated the refresh token before this claim.
			wp_cache_delete( Secret_Store::OPTION, 'options' );
			$fresh = $this->stored( $connection );
			if ( null !== $fresh && (int) $fresh['expires_at'] > time() + 60 && ( ! $force || ! hash_equals( (string) ( $tokens['access_token'] ?? '' ), $fresh['access_token'] ) ) ) {
				return $fresh['access_token'];
			}
			$tokens = $fresh;
			if ( self::delegated( $connection ) && empty( $tokens['refresh_token'] ) ) {
				return Send_Result::failure( Send_Result::AUTH, __( 'Reconnect this mailbox before sending.', 'sw-mailgate' ) );
			}
			$grant = self::delegated( $connection )
				? array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $tokens['refresh_token'],
				)
				: array(
					'grant_type' => 'client_credentials',
					'scope'      => $config['scope'],
				);
			return $this->exchange( $connection, $config, $grant, $tokens );
		} finally {
			$this->unlock( $lock );
		}
	}

	private function exchange( Connection $connection, array $config, array $grant, ?array $previous, bool $require_refresh = false ): string|Send_Result {
		$fingerprint = $this->fingerprint( $connection );
		$response    = $this->http->request(
			'POST',
			$config['token'],
			array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			http_build_query(
				$grant + array(
					'client_id'     => $config['client_id'],
					'client_secret' => $config['client_secret'],
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);
		$data        = $response['data'] ?? array();
		if ( 200 !== $response['status'] ) {
			$class = 0 === $response['status'] || 429 === $response['status'] || $response['status'] >= 500 ? Send_Result::TRANSIENT : Send_Result::AUTH;
			if ( 'refresh_token' === $grant['grant_type'] && 'invalid_grant' === ( $data['error'] ?? '' ) && $this->unchanged( $connection, $fingerprint ) ) {
				$this->disconnect( $connection );
			}
			return Send_Result::failure( $class, __( 'OAuth token request failed. Check client credentials, consent and secret expiry; reconnect the mailbox if access was revoked.', 'sw-mailgate' ), $response['debug'] );
		}
		$access  = $data['access_token'] ?? null;
		$refresh = $data['refresh_token'] ?? ( $previous['refresh_token'] ?? '' );
		if ( ! self::delegated( $connection ) ) {
			$refresh = '';
		}
		$expires = $data['expires_in'] ?? null;
		if ( ! is_string( $access ) || '' === $access || strlen( $access ) > 32768 || preg_match( '/[\x00-\x20\x7f]/', $access ) || ! is_numeric( $expires ) || (int) $expires <= 60 || (int) $expires > YEAR_IN_SECONDS ||
			! is_string( $refresh ) || strlen( $refresh ) > 32768 || preg_match( '/[\x00-\x1f\x7f]/', $refresh ) ||
			! in_array( $data['token_type'] ?? null, array( 'Bearer', 'bearer' ), true ) || ( $require_refresh && '' === $refresh ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'The provider returned incomplete OAuth credentials. Reconnect and allow offline access.', 'sw-mailgate' ) );
		}
		if ( isset( $data['scope'] ) && self::delegated( $connection ) ) {
			$required = 'google' === $connection->provider() ? self::GOOGLE_SCOPE : self::GRAPH_SCOPE;
			$scopes   = explode( ' ', (string) $data['scope'] );
			if ( ! in_array( $required, $scopes, true ) && ! ( 'office365' === $connection->provider() && in_array( 'Mail.Send', $scopes, true ) ) ) {
				return Send_Result::failure( Send_Result::AUTH, __( 'The required mail sending permission was not granted. Reconnect and approve it.', 'sw-mailgate' ) );
			}
		}
		if ( ! $this->unchanged( $connection, $fingerprint ) ) {
			return Send_Result::failure( Send_Result::AUTH, __( 'The connection changed during authorization. Start again from its editor.', 'sw-mailgate' ) );
		}
		$this->secrets->set(
			Connection::secret_name( $connection->id(), 'oauth_tokens' ),
			wp_json_encode(
				array(
					'fingerprint'   => $fingerprint,
					'access_token'  => $access,
					'refresh_token' => $refresh,
					'expires_at'    => time() + (int) $expires,
				)
			)
		);
		if ( ( $this->stored( $connection )['access_token'] ?? null ) !== $access ) {
			return Send_Result::failure( Send_Result::TRANSIENT, __( 'OAuth credentials could not be stored. Check the WordPress database and encryption key.', 'sw-mailgate' ) );
		}
		return $access;
	}

	/**
	 * Atomic expiring transient claim. DB uniqueness also works across PHP workers
	 * with persistent object caches; an expired owner cannot release a newer claim.
	 *
	 * @return array|null Name/value pair for conditional release.
	 */
	private function lock( string $name ): ?array {
		$key   = self::LOCK_PREFIX . hash( 'sha256', $name );
		$value = array(
			'owner' => bin2hex( random_bytes( 16 ) ),
			'until' => time() + 90,
		);
		$old   = get_option( $key );
		if ( is_array( $old ) && (int) ( $old['until'] ?? 0 ) < time() ) {
			$this->unlock( array( $key, $old ) );
		}
		if ( ! add_option( $key, $value, '', false ) ) {
			return null;
		}
		// WordPress can garbage-collect an abandoned claim after its TTL.
		update_option( str_replace( '_transient_', '_transient_timeout_', $key ), $value['until'], false );
		return array( $key, $value );
	}

	private function unlock( array $lock ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-delete protects a newer lock owner.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $lock[0], maybe_serialize( $lock[1] ) ) );
		wp_cache_delete( $lock[0], 'options' );
		if ( $deleted ) {
			$timeout = str_replace( '_transient_', '_transient_timeout_', $lock[0] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only remove this claim's expiry metadata.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $timeout, (string) $lock[1]['until'] ) );
			wp_cache_delete( $timeout, 'options' );
		}
	}
}
