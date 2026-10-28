<?php
/**
 * M6: real WordPress OAuth state/session, token lifecycle, MIME APIs and admin setup UI.
 * All HTTP is intercepted. No account credentials, external requests or mail are used.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Admin\OAuth_Admin;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mailer_Factory;
use SWMailgate\Mail\Redirector;
use SWMailgate\OAuth\OAuth_Client;
use SWMailgate\Providers\Google_Provider;
use SWMailgate\Providers\Office365_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Routing\Failover_Settings;
use SWMailgate\Security\Secret_Store;

delete_option( Connection_Repository::OPTION );
update_option( Failover_Settings::OPTION, array( 'breaker_threshold' => 0, 'queue_enabled' => false ) );
$settings = array( 'client_id' => 'm6-client', 'tenant_id' => '12345678-1234-1234-1234-123456789abc', 'mode' => 'delegated', 'mailbox' => 'app@example.test' );
foreach ( array( 'google' => 'google', 'delegated' => 'office365', 'application' => 'office365' ) as $id => $kind ) {
	$connections->save( array( 'id' => $id, 'provider' => $kind, 'enabled' => 'google' === $id, 'from_email' => 'sender@example.test', 'settings' => array_merge( $settings, array( 'mode' => 'application' === $id ? 'application' : 'delegated' ) ) ), array( 'client_secret' => 'm6-client-secret' ) );
}
$client = new OAuth_Client();
$secrets = new Secret_Store();
$google = new Google_Provider();
$office = new Office365_Provider();
$requests = array();
$token_count = 0;
$api_status = 200;
$token_error = null;
$token_data = null;
$on_token = null;
$one_unauthorized = false;
$leak_id = false;
$wire = array();
$http = static function ( $pre, array $args, string $url ) use ( &$requests, &$token_count, &$api_status, &$token_error, &$token_data, &$on_token, &$one_unauthorized, &$leak_id, &$wire ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	sw_mailgate_check( true === $args['sslverify'] && 0 === $args['redirection'], 'OAuth/API requests enforce verified HTTPS without redirects.' );
	$is_token = str_ends_with( $url, '/token' );
	$allowed = array( 'oauth2.googleapis.com', 'login.microsoftonline.com', 'gmail.googleapis.com', 'graph.microsoft.com' );
	if ( ! in_array( wp_parse_url( $url, PHP_URL_HOST ), $allowed, true ) ) { throw new RuntimeException( 'Unexpected HTTP target.' ); }
	$status = 200;
	if ( $is_token ) {
		++$token_count;
		if ( null !== $on_token ) { $on_token(); }
		if ( null !== $token_error ) {
			$status = 400;
			$data = array( 'error' => $token_error, 'error_description' => 'm6-sensitive-response m6-client-secret' );
		} else {
			$data = $token_data ?? array( 'access_token' => 'm6-access-' . $token_count, 'refresh_token' => 'm6-refresh-' . $token_count, 'expires_in' => 3600, 'token_type' => 'Bearer' );
		}
	} else {
		$wire[] = array( 'url' => $url, 'args' => $args );
		if ( $one_unauthorized ) { $one_unauthorized = false; $status = 401; }
		else { $status = str_contains( $url, 'graph.microsoft.com' ) && 200 === $api_status ? 202 : $api_status; }
		$data = $leak_id ? array( 'id' => 'm6-client-secret' ) : array( 'id' => '18abcdef123' );
	}
	if ( 0 === $status ) { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15000 milliseconds' ); }
	return array( 'headers' => array(), 'body' => 202 === $status ? '' : wp_json_encode( $data ), 'response' => array( 'code' => $status ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $http, 10, 3 );

$admin_id = get_current_user_id();
$session = WP_Session_Tokens::get_instance( $admin_id )->create( time() + HOUR_IN_SECONDS );
$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in', $session );

function sw_mailgate_oauth_begin( OAuth_Client $client, Connection $connection ): array {
	$url = $client->authorize( $connection );
	sw_mailgate_check( is_string( $url ), 'Authorization begins from saved client credentials.' );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
	sw_mailgate_check( 64 === strlen( $params['state'] ) && 'S256' === $params['code_challenge_method'] && OAuth_Client::redirect_uri() === $params['redirect_uri'], 'Authorization uses unpredictable state, PKCE S256 and the exact site callback.' );
	return $params;
}
function sw_mailgate_oauth_connect( OAuth_Client $client, Connection $connection ): void {
	$params = sw_mailgate_oauth_begin( $client, $connection );
	$result = $client->callback( $params['state'], 'm6-auth-code' );
	sw_mailgate_check( $result['result']->is_success() && $client->connected( $connection ), 'Authorization code exchange stores renewable credentials.' );
}
function sw_mailgate_oauth_expire( string $id ): void {
	$store = new Secret_Store();
	$key = Connection::secret_name( $id, 'oauth_tokens' );
	$data = json_decode( $store->get( $key ), true );
	$data['expires_at'] = 0;
	$store->set( $key, wp_json_encode( $data ) );
}
function sw_mailgate_oauth_envelope( string $body = 'Private MIME body' ): Envelope {
	$mailer = Mailer_Factory::create();
	$mailer->isSMTP();
	$mailer->setFrom( 'sender@example.test' );
	$mailer->addAddress( 'visible@example.test' );
	$mailer->addBCC( 'hidden@example.test' );
	$mailer->addReplyTo( 'reply@example.test' );
	$mailer->Subject = 'M6 MIME fixture';
	$mailer->Body = $body;
	$mailer->preSend();
	return new Envelope( $mailer );
}

$connection = $connections->find( 'google' );
$params = sw_mailgate_oauth_begin( $client, $connection );
sw_mailgate_check( OAuth_Client::GOOGLE_SCOPE === $params['scope'] && 'offline' === $params['access_type'] && 'consent' === $params['prompt'], 'Google requests send-only offline access, without inbox scopes.' );
$before = count( $requests );
$original_cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
$_COOKIE[ LOGGED_IN_COOKIE ] = 'another-session';
sw_mailgate_check( ! $client->callback( $params['state'], 'code' )['result']->is_success() && $before === count( $requests ), 'A different WordPress session cannot exchange the code or consume valid state.' );
$_COOKIE[ LOGGED_IN_COOKIE ] = $original_cookie;
$result = $client->callback( $params['state'], 'm6-code' );
sw_mailgate_check( $result['result']->is_success(), 'The originating session can finish authorization.' );
parse_str( end( $requests )['args']['body'], $grant );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $grant['code_verifier'], true ) ), '+/', '-_' ), '=' );
sw_mailgate_check( $challenge === $params['code_challenge'] && 'm6-code' === $grant['code'] && 'm6-client-secret' === $grant['client_secret'], 'The token exchange uses the original PKCE verifier and opaque code.' );
$before = count( $requests );
sw_mailgate_check( ! $client->callback( $params['state'], 'm6-code' )['result']->is_success() && $before === count( $requests ), 'Consumed state cannot be replayed.' );
sw_mailgate_check( ! str_contains( wp_json_encode( get_option( Secret_Store::OPTION ) ), 'm6-refresh' ) && ! str_contains( wp_json_encode( get_option( Connection_Repository::OPTION ) ), 'm6-client-secret' ), 'Tokens and client secrets remain encrypted outside plain connection settings.' );
$params = sw_mailgate_oauth_begin( $client, $connection );
$before = $token_count;
sw_mailgate_check( ! $client->callback( $params['state'], '', true )['result']->is_success() && $before === $token_count, 'Provider denial consumes state without exchanging a code.' );
sw_mailgate_check( ! $client->callback( $params['state'], 'late-code' )['result']->is_success(), 'Denied authorization cannot be resumed by replay.' );
$params = sw_mailgate_oauth_begin( $client, $connection );
delete_transient( 'sw_mailgate_oauth_state_' . hash( 'sha256', $params['state'] ) );
sw_mailgate_check( ! $client->callback( $params['state'], 'expired' )['result']->is_success(), 'Expired state is rejected before HTTP.' );
$params = sw_mailgate_oauth_begin( $client, $connection );
$original = $connection->to_array();
$changed = $original; $changed['settings']['client_id'] = 'another-client';
$connections->save( $changed );
sw_mailgate_check( ! $client->callback( $params['state'], 'stale-config' )['result']->is_success() && ! $client->connected( $connections->find( 'google' ) ), 'Client configuration changes invalidate cached grants and pending state.' );
$connections->save( $original );
$connection = $connections->find( 'google' );

$before = $token_count;
sw_mailgate_check( is_string( $client->access_token( $connection ) ) && $before === $token_count, 'Unexpired cached access tokens need no HTTP request.' );
sw_mailgate_oauth_expire( 'google' );
$busy = null;
$on_token = static function () use ( &$busy, $client, $connection ): void { $busy = $client->access_token( $connection, true ); };
$token = $client->access_token( $connection );
$on_token = null;
sw_mailgate_check( is_string( $token ) && $busy instanceof Send_Result && Send_Result::TRANSIENT === $busy->error_class() && $before + 1 === $token_count, 'The refresh claim prevents concurrent token exchanges.' );
$stored = json_decode( $secrets->get( Connection::secret_name( 'google', 'oauth_tokens' ) ), true );
sw_mailgate_check( 'm6-refresh-' . $token_count === $stored['refresh_token'], 'Rotated refresh tokens replace the old encrypted value.' );
$token_data = array( 'access_token' => 'm6-without-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
$client->access_token( $connection, true );
$token_data = null;
$stored = json_decode( $secrets->get( Connection::secret_name( 'google', 'oauth_tokens' ) ), true );
sw_mailgate_check( 'm6-refresh-' . ( $token_count - 1 ) === $stored['refresh_token'], 'Omitted refresh tokens retain the existing grant.' );
$original_tokens = $secrets->get( Connection::secret_name( 'google', 'oauth_tokens' ) );
foreach ( array(
	array( 'access_token' => "bad\r\ntoken", 'expires_in' => 3600, 'token_type' => 'Bearer' ),
	array( 'access_token' => 'valid-but-wrong-scope', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'unrelated' ),
	array( 'access_token' => 'missing-expiry', 'token_type' => 'Bearer' ),
) as $invalid ) {
	$token_data = $invalid;
	sw_mailgate_check( $client->access_token( $connection, true ) instanceof Send_Result && $original_tokens === $secrets->get( Connection::secret_name( 'google', 'oauth_tokens' ) ), 'Malformed token responses and missing send scope cannot overwrite a valid grant.' );
}
$token_data = null;
$lock_method = new ReflectionMethod( OAuth_Client::class, 'lock' );
$unlock_method = new ReflectionMethod( OAuth_Client::class, 'unlock' );
$old_claim = $lock_method->invoke( $client, 'stale-lock-fixture' );
$expired = $old_claim[1]; $expired['until'] = time() - 1;
update_option( $old_claim[0], $expired, false );
$new_claim = $lock_method->invoke( $client, 'stale-lock-fixture' );
sw_mailgate_check( is_array( $new_claim ), 'Expired refresh claims can be recovered.' );
$unlock_method->invoke( $client, $old_claim );
sw_mailgate_check( $new_claim[1] === get_option( $new_claim[0] ), 'An old claim owner cannot unlock a newer owner.' );
$unlock_method->invoke( $client, $new_claim );

// A refresh outliving its lease must not restore account A after account B connected.
$pending = sw_mailgate_oauth_begin( $client, $connection );
$replacement = null;
$token_data = array( 'access_token' => 'account-a-stale-access', 'refresh_token' => 'account-a-stale-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
$on_token = static function () use ( &$on_token, &$token_data, &$replacement, $client, $connection, $pending ): void {
	$on_token = null;
	$key = '_transient_sw_mailgate_oauth_lock_' . hash( 'sha256', 'refresh-' . $connection->id() );
	$claim = get_option( $key );
	$claim['until'] = time() - 1;
	update_option( $key, $claim, false );
	$token_data = array( 'access_token' => 'account-b-current-access', 'refresh_token' => 'account-b-current-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
	$replacement = $client->callback( $pending['state'], 'account-b-code' );
	$token_data = array( 'access_token' => 'account-a-stale-access', 'refresh_token' => 'account-a-stale-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
};
$stale_refresh = $client->access_token( $connection, true );
$on_token = null;
$token_data = null;
$current = json_decode( $secrets->get( Connection::secret_name( 'google', 'oauth_tokens' ) ), true );
sw_mailgate_check( $replacement['result']->is_success() && $stale_refresh instanceof Send_Result && 'account-b-current-access' === $current['access_token'] && 'account-b-current-refresh' === $current['refresh_token'], 'An old account refresh finishing after its lease expired cannot overwrite a successful account-B reconnect.' );

// Normal overlap: do not redeem B's code while A owns the refresh claim.
$pending = sw_mailgate_oauth_begin( $client, $connection );
$overlap = null;
$token_data = array( 'access_token' => 'account-a-refreshed', 'refresh_token' => 'account-a-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
$before = $token_count;
$on_token = static function () use ( &$on_token, &$overlap, $client, $pending ): void {
	$on_token = null;
	$overlap = $client->callback( $pending['state'], 'busy-account-b-code' );
};
$refresh = $client->access_token( $connection, true );
$on_token = null;
sw_mailgate_check( is_string( $refresh ) && Send_Result::TRANSIENT === $overlap['result']->error_class() && $token_count === $before + 1, 'A callback overlapping a refresh reports busy without redeeming the new account code.' );
$token_data = array( 'access_token' => 'account-b-after-retry', 'refresh_token' => 'account-b-after-retry-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer' );
sw_mailgate_check( $client->callback( $pending['state'], 'busy-account-b-code' )['result']->is_success() && 'account-b-after-retry' === $client->access_token( $connection ), 'The blocked callback can retry its unconsumed state after the refresh finishes.' );

// Reverse order: a refresh cannot run while the callback exchanges a new code.
$pending = sw_mailgate_oauth_begin( $client, $connection );
$superseded = sw_mailgate_oauth_begin( $client, $connection );
$overlap = null;
$before = $token_count;
$on_token = static function () use ( &$on_token, &$overlap, $client, $connection ): void {
	$on_token = null;
	$overlap = $client->access_token( $connection, true );
};
$replacement = $client->callback( $pending['state'], 'new-account-code' );
$on_token = null;
$token_data = null;
sw_mailgate_check( $replacement['result']->is_success() && $overlap instanceof Send_Result && Send_Result::TRANSIENT === $overlap->error_class() && $token_count === $before + 1, 'A refresh overlapping a callback is serialized by the same connection claim.' );
$before = $token_count;
sw_mailgate_check( ! $client->callback( $superseded['state'], 'older-account-code' )['result']->is_success() && $before === $token_count, 'A successful reconnect invalidates other pending account authorizations.' );

$registry = new Provider_Registry( array( $google, $office ) );
$page = new Admin_Page( $connections, $registry, $secrets, new Dispatcher( $connections, $registry ), new Redirector() );
foreach ( array( 'google', 'delegated', 'application' ) as $id ) {
	$_GET = array( 'view' => 'edit', 'connection' => $id );
	ob_start(); $page->render_connections(); $html = ob_get_clean();
	if ( getenv( 'SW_MAILGATE_TEST_PREVIEW' ) ) {
		file_put_contents( rtrim( getenv( 'SW_MAILGATE_TEST_PREVIEW' ), '/' ) . '/' . $id . '.html', '<!doctype html><html lang="de"><head><meta charset="utf-8"><link rel="stylesheet" href="admin.css"><script defer src="admin.js"></script></head><body>' . $html . '</body></html>' );
	}
	sw_mailgate_check( str_contains( $html, 'swmg-oauth-help' ) && str_contains( $html, esc_html( OAuth_Client::redirect_uri() ) ) && ! str_contains( $html, 'm6-client-secret' ) && ! str_contains( $html, 'm6-access' ) && ! str_contains( $html, 'm6-refresh' ), 'The editor offers a complete accessible setup guide and exact callback without exposing secrets (' . $id . ').' );
	sw_mailgate_check( str_contains( $html, 'sw_mailgate_oauth_connect' ) === ( 'application' !== $id ), 'Only delegated connections offer browser authorization.' );
}
sw_mailgate_check( str_contains( $html, 'Application Mail.Send' ) && str_contains( $html, 'additive' ) && str_contains( $html, 'Secret ID' ), 'Microsoft instructions cover scoped RBAC, additive permissions and the secret Value/ID distinction.' );

$before = $token_count;
sw_mailgate_check( wp_mail( array( 'visible@example.test' ), 'M6 send', 'Private MIME body', array( 'Bcc: hidden@example.test', 'Reply-To: reply@example.test' ) ), 'WordPress dispatch sends through Gmail.' );
$last = end( $wire );
$raw = json_decode( $last['args']['body'], true )['raw'];
$mime = base64_decode( strtr( $raw, '-_', '+/' ) );
sw_mailgate_check( str_contains( $mime, 'Bcc: hidden@example.test' ) && str_contains( $mime, 'Reply-To: reply@example.test' ) && str_contains( $mime, 'Private MIME body' ) && ! preg_match( '/[+\/=]/', $raw ), 'Gmail raw uses base64url MIME preserving Bcc and Reply-To.' );
$one_unauthorized = true;
$before = $token_count;
sw_mailgate_check( $google->send( sw_mailgate_oauth_envelope(), $connection )->is_success() && $token_count === $before + 1, 'A definite 401 renews and retries once.' );
$envelope = sw_mailgate_oauth_envelope( str_repeat( 'x', 6 * MB_IN_BYTES ) );
sw_mailgate_check( $google->send( $envelope, $connection )->is_success(), 'Larger Gmail MIME sends through the upload endpoint.' );
$last = end( $wire );
sw_mailgate_check( str_contains( $last['url'], '/upload/' ) && 'message/rfc822' === $last['args']['headers']['Content-Type'] && str_contains( $last['args']['body'], 'Bcc: hidden@example.test' ), 'Gmail media uploads use raw MIME and the explicit upload type.' );
$leak_id = true;
sw_mailgate_check( Send_Result::UNCERTAIN === $google->send( sw_mailgate_oauth_envelope(), $connection )->error_class(), 'Successful Gmail responses with invalid or sensitive IDs stop failover.' );
$leak_id = false;
foreach ( array( 400 => Send_Result::PERMANENT, 403 => Send_Result::AUTH, 404 => Send_Result::AUTH, 429 => Send_Result::TRANSIENT, 500 => Send_Result::TRANSIENT ) as $status => $class ) {
	$api_status = $status;
	sw_mailgate_check( $class === $google->send( sw_mailgate_oauth_envelope(), $connection )->error_class(), 'Mail API classification for HTTP ' . $status . '.' );
}
$api_status = 0;
sw_mailgate_check( $google->send( sw_mailgate_oauth_envelope(), $connection )->is_ambiguous(), 'OAuth mail API timeouts retain M5 delivery-uncertainty policy.' );
$api_status = 200;

$delegated = $connections->find( 'delegated' );
$params = sw_mailgate_oauth_begin( $client, $delegated );
sw_mailgate_check( str_contains( $params['scope'], OAuth_Client::GRAPH_SCOPE ) && str_contains( $params['scope'], 'offline_access' ) && ! str_contains( $params['scope'], 'User.Read' ), 'Delegated Graph consent requests only Mail.Send and offline access.' );
sw_mailgate_check( $client->callback( $params['state'], 'graph-code' )['result']->is_success(), 'Microsoft delegated authorization succeeds.' );
sw_mailgate_check( $office->send( sw_mailgate_oauth_envelope(), $delegated )->is_success(), 'Graph accepts a 202 response without requiring an invented provider message ID.' );
$last = end( $wire );
sw_mailgate_check( str_ends_with( $last['url'], '/me/sendMail' ) && str_contains( base64_decode( $last['args']['body'] ), 'Bcc: hidden@example.test' ) && 'text/plain' === $last['args']['headers']['Content-Type'], 'Delegated Graph sends base64 MIME through the signed-in mailbox.' );
$application = $connections->find( 'application' );
sw_mailgate_check( $office->send( sw_mailgate_oauth_envelope(), $application )->is_success(), 'Graph application mode obtains client credentials and sends.' );
$last = end( $wire );
sw_mailgate_check( str_ends_with( $last['url'], '/users/app%40example.test/sendMail' ), 'Application Graph explicitly targets the configured mailbox.' );
$token_requests = array_values( array_filter( $requests, static fn( $r ) => str_ends_with( $r['url'], '/token' ) ) );
parse_str( end( $token_requests )['args']['body'], $grant );
sw_mailgate_check( 'client_credentials' === $grant['grant_type'] && 'https://graph.microsoft.com/.default' === $grant['scope'] && ! isset( $grant['refresh_token'] ), 'Application tokens use .default and no delegated refresh flow.' );
$before = count( $wire );
sw_mailgate_check( $office->test_connection( $application )->is_success() && $before === count( $wire ), 'Connection tests only acquire tokens and never submit a message.' );
sw_mailgate_check( Send_Result::PERMANENT === $office->send( $envelope, $application )->error_class() && $before === count( $wire ), 'Oversized Graph messages are rejected before any mail submission.' );

$params = sw_mailgate_oauth_begin( $client, $connection );
$client->disconnect( $connection );
sw_mailgate_check( ! $client->connected( $connection ) && ! $client->callback( $params['state'], 'stale-code' )['result']->is_success(), 'Disconnect invalidates tokens and pending authorization state.' );
sw_mailgate_oauth_connect( $client, $connection );
$on_token = static function () use ( $client, $connection ): void { $client->disconnect( $connection ); };
$result = $client->access_token( $connection, true );
$on_token = null;
sw_mailgate_check( $result instanceof Send_Result && ! $client->connected( $connection ), 'A token refresh finishing after disconnect cannot restore access.' );
sw_mailgate_oauth_connect( $client, $connection );
$token_error = 'invalid_grant';
$result = $client->access_token( $connection, true );
$token_error = null;
sw_mailgate_check( Send_Result::AUTH === $result->error_class() && ! $client->connected( $connection ) && ! str_contains( $result->message(), 'm6-sensitive-response' ) && ! str_contains( implode( ' ', $result->debug() ), 'm6-client-secret' ), 'Revoked refresh grants clear credentials and produce safe reconnect errors.' );

$actions = new OAuth_Admin( $connections );
$die = static fn() => static function (): never { throw new RuntimeException( 'oauth-wp-die' ); };
add_filter( 'wp_die_handler', $die );
$_POST = array( 'connection' => 'google' ); $_REQUEST = array();
try { $actions->connect(); throw new LogicException( 'Missing nonce accepted.' ); }
catch ( RuntimeException $error ) { sw_mailgate_check( 'oauth-wp-die' === $error->getMessage(), 'OAuth start rejects a missing admin nonce.' ); }
wp_set_current_user( 0 );
try { $actions->callback(); throw new LogicException( 'Unauthenticated callback accepted.' ); }
catch ( RuntimeException $error ) { sw_mailgate_check( 'oauth-wp-die' === $error->getMessage(), 'OAuth callback requires the management capability.' ); }
wp_set_current_user( $admin_id );
remove_filter( 'wp_die_handler', $die );
$redirect_url = '';
$stop_redirect = static function ( string $url ) use ( &$redirect_url ): never {
	$redirect_url = $url;
	throw new RuntimeException( 'oauth-intercept-redirect' );
};
add_filter( 'wp_redirect', $stop_redirect );
try {
	$_POST = array( 'connection' => 'google' );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_oauth_google' );
	try { $actions->connect(); }
	catch ( RuntimeException $error ) { if ( 'oauth-intercept-redirect' !== $error->getMessage() ) { throw $error; } }
	sw_mailgate_check( 'accounts.google.com' === wp_parse_url( $redirect_url, PHP_URL_HOST ), 'The nonce-protected admin connect action redirects only to the fixed provider.' );
	parse_str( wp_parse_url( $redirect_url, PHP_URL_QUERY ), $query );
	$_GET = array( 'state' => $query['state'], 'code' => 'admin-callback-code' );
	try { $actions->callback(); }
	catch ( RuntimeException $error ) { if ( 'oauth-intercept-redirect' !== $error->getMessage() ) { throw $error; } }
	$notice = get_transient( 'sw_mailgate_oauth_notice_' . $admin_id );
	sw_mailgate_check( $notice['success'] && $client->connected( $connection ) && ! str_contains( $redirect_url, 'code=' ) && str_contains( $redirect_url, 'connection=google' ), 'A successful admin callback stores the grant and returns to its editor without codes in the redirect.' );
	ob_start(); $actions->notice(); $html_notice = ob_get_clean();
	sw_mailgate_check( str_contains( $html_notice, 'notice-success' ) && ! str_contains( $html_notice, 'm6-access' ), 'OAuth notices render safe results and are consumed once.' );
	try { $actions->disconnect(); }
	catch ( RuntimeException $error ) { if ( 'oauth-intercept-redirect' !== $error->getMessage() ) { throw $error; } }
	sw_mailgate_check( ! $client->connected( $connection ), 'The nonce-protected admin disconnect action clears local credentials.' );
} finally { remove_filter( 'wp_redirect', $stop_redirect ); }

if ( 'enabled' === $logging_mode ) {
	$rows = $logs->search();
	sw_mailgate_check( ! str_contains( wp_json_encode( $rows ), 'm6-access' ) && ! str_contains( wp_json_encode( $rows ), 'm6-refresh' ) && ! str_contains( wp_json_encode( $rows ), 'm6-client-secret' ), 'Stored mail diagnostics contain no OAuth credentials.' );
} else {
	sw_mailgate_check( 0 === $logs->count(), 'OAuth mail does not enable logging without wp-config opt-in.' );
}
remove_filter( 'pre_http_request', $http, 10 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
sw_mailgate_check( false === get_option( Secret_Store::OPTION ), 'Opted-in uninstall removes encrypted OAuth tokens and client credentials.' );
echo 'PASS: M6 OAuth regressions completed.' . PHP_EOL;
