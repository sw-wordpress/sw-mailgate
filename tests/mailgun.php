<?php
/**
 * Mailgun integration cases: integration.php [enabled|unset] mailgun [api|constant].
 * WordPress HTTP interception prevents every external request and captures wire payloads.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Redirection_Settings;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Security\Secret_Store;

/** An independent reader for the multipart/form-data submitted to the HTTP API. */
function sw_mailgate_read_upload( array $request ): array {
	sw_mailgate_check( 1 === preg_match( '/boundary=([^;\s]+)/', $request['headers']['Content-Type'], $matches ), 'HTTP request declares its multipart boundary.' );
	$parts = explode( '--' . $matches[1], $request['body'] );
	$fields = array();
	foreach ( $parts as $part ) {
		if ( ! str_contains( $part, "\r\n\r\n" ) ) { continue; }
		list( $headers, $value ) = explode( "\r\n\r\n", $part, 2 );
		if ( preg_match( '/Content-Disposition: form-data; name="([^"]+)"/', $headers, $name ) ) {
			$fields[ $name[1] ] = array( 'headers' => $headers, 'value' => substr( $value, 0, -2 ) );
		}
	}
	sw_mailgate_check( array( 'to', 'message' ) === array_keys( $fields ) && str_ends_with( $request['body'], '--' . $matches[1] . "--\r\n" ), 'Wire upload has complete recipient and MIME file parts.' );
	return $fields;
}

function sw_mailgate_http_response( int $code, mixed $data ): array {
	return array( 'response' => array( 'code' => $code, 'message' => 'fixture' ), 'body' => is_string( $data ) ? $data : wp_json_encode( $data ), 'headers' => array(), 'cookies' => array() );
}

$mailgun = new Mailgun_Provider();
$logging = Settings::get()['enabled'];
$secret_store = new Secret_Store();
delete_option( Connection_Repository::OPTION );
$stored_key = 'key-stored-example-mailgun';
$key = 'constant' === $mailgun_mode ? SW_MAILGATE_MAILGUN_API_KEY : $stored_key;
$domain = 'constant' === $mailgun_mode ? SW_MAILGATE_MAILGUN_DOMAIN : 'mg.example.test';
$host = 'constant' === $mailgun_mode ? 'api.eu.mailgun.net' : 'api.mailgun.net';
$message_id = '<accepted-message@example.test>';
$data = array( 'id' => 'mailgun', 'label' => 'Mailgun fixture', 'provider' => 'mailgun', 'enabled' => true, 'from_email' => 'verified@example.test', 'settings' => array( 'domain' => 'mg.example.test', 'region' => 'us', 'timeout' => '15' ) );
$connections->save( $data, array( 'api_key' => $stored_key ) );
$connection = $connections->find( 'mailgun' );
$connections->save( array( 'id' => 'second', 'provider' => 'test', 'enabled' => true, 'from_email' => 'fallback@example.test' ) );
$fields = array_column( $mailgun->fields(), null, 'key' );
sw_mailgate_check( isset( $fields['domain'], $fields['region'], $fields['timeout'] ) && $fields['api_key']->secret, 'Mailgun exposes declarative settings with an encrypted secret field.' );
sw_mailgate_check( $key === $connection->get( 'api_key' ) && ! str_contains( wp_json_encode( get_option( Secret_Store::OPTION ) ), $stored_key ) && ! str_contains( wp_json_encode( get_option( Connection_Repository::OPTION ) ), $stored_key ), 'API key is retrieved from encrypted storage or wp-config and is absent from plain settings.' );

$requests = array();
$reply = sw_mailgate_http_response( 200, array( 'id' => $message_id, 'message' => 'Queued. Thank you.' ) );
$intercept = static function ( $preempt, $args, $url ) use ( &$requests, &$reply ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( $reply instanceof Throwable ) { throw $reply; }
	return $reply;
};
add_filter( 'pre_http_request', $intercept, 10, 3 );

$attachment = tempnam( sys_get_temp_dir(), 'mailgate-attachment-' );
$attachment_content = "Attachment fixture\x00\xff";
file_put_contents( $attachment, $attachment_content );
$alt_body = static function ( $mailer ): void { $mailer->AltBody = 'Plain alternative fixture'; };
add_action( 'phpmailer_init', $alt_body );
try {
	sw_mailgate_check( wp_mail( 'to@example.test', 'Mailgun MIME fixture', '<b>HTML fixture</b>', $headers, array( $attachment ) ), 'WordPress dispatches through the registered Mailgun provider.' );
} finally {
	remove_action( 'phpmailer_init', $alt_body );
	unlink( $attachment );
}
sw_mailgate_check( 1 === count( $requests ) && array() === $provider->messages, 'Successful Mailgun submission stops failover and does not use SMTP.' );
$request = $requests[0];
sw_mailgate_check( 'https://' . $host . '/v3/' . $domain . '/messages.mime' === $request['url'] && 'POST' === $request['args']['method'], 'MIME submission uses the configured regional API endpoint.' );
sw_mailgate_check( 'Basic ' . base64_encode( 'api:' . $key ) === $request['args']['headers']['Authorization'] && ! str_contains( $request['url'], $key ), 'Authentication uses an HTTP Basic header with the correct stored/overridden key.' );
sw_mailgate_check( true === $request['args']['sslverify'] && true === $request['args']['reject_unsafe_urls'] && 0 === $request['args']['redirection'] && 65536 === $request['args']['limit_response_size'] && ( 'constant' === $mailgun_mode ? 60 : 15 ) === $request['args']['timeout'], 'HTTP enforces TLS verification, bounded timeouts/responses and no credential-bearing redirects.' );
$upload = sw_mailgate_read_upload( $request['args'] );
sw_mailgate_check( 'to@example.test,cc@example.test,bcc@example.test' === $upload['to']['value'], 'Explicit API envelope includes every To, Cc and Bcc recipient.' );
$mime = $upload['message']['value'];
sw_mailgate_check( str_contains( $upload['message']['headers'], 'filename="message.eml"' ) && str_contains( $upload['message']['headers'], 'Content-Type: message/rfc822' ), 'MIME is submitted as a file upload rather than a regular text field.' );
sw_mailgate_check( 1 === preg_match( '/^From:.*verified@example\.test/m', $mime ) && str_contains( $mime, 'Reply-To: reply@example.test' ) && str_contains( $mime, 'Cc: cc@example.test' ) && ! preg_match( '/^Bcc:/mi', $mime ) && ! str_contains( $mime, 'bcc@example.test' ), 'Sender rules, Cc and Reply-To are preserved without exposing Bcc in the MIME headers.' );
sw_mailgate_check( str_contains( $mime, '<b>HTML fixture</b>' ) && str_contains( $mime, 'Plain alternative fixture' ) && str_contains( $mime, base64_encode( $attachment_content ) ) && ! str_contains( $mime, $key ), 'HTML, plain alternative and binary attachment survive the MIME upload without API credentials.' );
if ( $logging ) {
	$row = $logs->search()[0];
	sw_mailgate_check( 'mailgun' === $row['provider'] && $message_id === $row['provider_message_id'] && null === $row['payload'], 'The Mailgun message ID is logged while bodies remain off by default.' );
}

sw_mailgate_check( wp_mail( array(), 'Bcc-only MIME fixture', 'Body', array( 'Bcc: only-hidden@example.test' ) ), 'Mailgun can send a message with only Bcc recipients.' );
$bcc_only = sw_mailgate_read_upload( end( $requests )['args'] );
sw_mailgate_check( 'only-hidden@example.test' === $bcc_only['to']['value'] && ! str_contains( $bcc_only['message']['value'], 'only-hidden@example.test' ), 'Bcc-only recipients are included in the API envelope without appearing in MIME.' );

// Both regions are fixed hosts; a read-only connection test never submits a message.
$reply = sw_mailgate_http_response( 200, array( 'domain' => array( 'name' => 'mg.regional.example.test', 'state' => 'active', 'smtp_password' => 'response-secret-never-exposed' ) ) );
foreach ( array( 'us' => 'api.mailgun.net', 'eu' => 'api.eu.mailgun.net' ) as $region => $regional_host ) {
	$regional = $data;
	$regional['id'] = 'region_' . $region;
	$regional['enabled'] = false;
	$regional['settings'] = array( 'domain' => 'mg.regional.example.test', 'region' => $region, 'timeout' => '1' );
	$connections->save( $regional, array( 'api_key' => $stored_key ) );
	$result = $mailgun->test_connection( $connections->find( $regional['id'] ) );
	$last = end( $requests );
	sw_mailgate_check( $result->is_success() && 'https://' . $regional_host . '/v4/domains/mg.regional.example.test' === $last['url'] && 'GET' === $last['args']['method'] && '' === $last['args']['body'] && 5 === $last['args']['timeout'], 'Read-only domain test supports region ' . $region . ' and clamps the minimum timeout.' );
	sw_mailgate_check( ! str_contains( wp_json_encode( $result->debug() ), 'response-secret-never-exposed' ), 'Domain diagnostics never expose raw domain response data.' );
}

$reply = sw_mailgate_http_response( 200, array( 'domain' => array( 'name' => $domain, 'state' => 'unverified' ) ) );
sw_mailgate_check( Send_Result::AUTH === $mailgun->test_connection( $connection )->error_class(), 'An unverified domain is reported as a configuration problem.' );
$reply = sw_mailgate_http_response( 200, array( 'domain' => array( 'name' => 'wrong.example.test', 'state' => 'active' ) ) );
sw_mailgate_check( ! $mailgun->test_connection( $connection )->is_success(), 'A successful HTTP response for a different domain cannot pass the connection test.' );

$sensitive = $key . ' ' . base64_encode( 'api:' . $key ) . ' PRIVATE-BODY-MARKER <script>alert(1)</script>';
foreach ( array( 400, 401, 403, 404, 408, 413, 429, 500, 503, 302 ) as $status ) {
	$reply = sw_mailgate_http_response( $status, array( 'message' => $sensitive, 'request' => $mime ) );
	$before_http = count( $requests );
	$before_fallback = count( $provider->messages );
	$sent = wp_mail( 'to@example.test', 'Status fixture ' . $status, 'Body' );
	$classification = Send_Result::class_for_http_status( $status );
	$can_failover = Send_Result::PERMANENT !== $classification;
	sw_mailgate_check( $sent === $can_failover && count( $requests ) === $before_http + 1 && count( $provider->messages ) === $before_fallback + ( $can_failover ? 1 : 0 ), 'HTTP ' . $status . ' obeys the shared error classification and failover policy.' );
	if ( $logging ) {
		$rows = $logs->search( array( 'connection_id' => 'mailgun' ), 1 );
		sw_mailgate_check( $classification === $rows[0]['error_class'] && ! str_contains( wp_json_encode( $rows ), $key ) && ! str_contains( wp_json_encode( $rows ), 'PRIVATE-BODY-MARKER' ), 'HTTP failure logs contain neither API secrets nor response bodies.' );
	}
	$check = $mailgun->test_connection( $connection );
	sw_mailgate_check( $classification === $check->error_class() && ! str_contains( wp_json_encode( array( $check->message(), $check->debug() ) ), $key ) && ! str_contains( wp_json_encode( $check->debug() ), 'PRIVATE-BODY-MARKER' ), 'Connection-test diagnostics are safe for HTTP ' . $status . '.' );
}

foreach ( array( new WP_Error( 'http_request_failed', $sensitive ), new RuntimeException( $sensitive ) ) as $transport_error ) {
	$reply = $transport_error;
	$before_fallback = count( $provider->messages );
	sw_mailgate_check( wp_mail( 'to@example.test', 'Transport failure fixture', 'Body' ) && count( $provider->messages ) === $before_fallback + 1, 'Transport errors and exceptions allow the fallback connection.' );
	$check = $mailgun->test_connection( $connection );
	sw_mailgate_check( Send_Result::TRANSIENT === $check->error_class() && ! str_contains( wp_json_encode( array( $check->message(), $check->debug() ) ), $key ), 'Transport diagnostics do not expose exception or WP_Error text.' );
}

foreach ( array( 'not JSON', array( 'message' => 'Queued without ID' ), array( 'id' => "invalid\r\nID" ), array( 'id' => $key ) ) as $malformed ) {
	$reply = sw_mailgate_http_response( 200, $malformed );
	$before_fallback = count( $provider->messages );
	sw_mailgate_check( ! wp_mail( 'to@example.test', 'Uncertain acceptance fixture', 'Body' ) && count( $provider->messages ) === $before_fallback, 'Ambiguous successful responses never trigger a potentially duplicate failover.' );
}

// Validate before any request, including values supplied outside the admin form.
$invalid_cases = array(
	array( 'domain' => 'https://evil.example/path' ),
	array( 'domain' => '127.0.0.1' ),
	array( 'domain' => '../another-domain' ),
	array( 'domain' => "bad.example\r\nInjected: value" ),
	array( 'region' => 'unknown' ),
	array( 'api_key' => '' ),
	array( 'api_key' => "key\ninvalid" ),
);
foreach ( $invalid_cases as $index => $invalid ) {
	$bad = $data;
	$bad['id'] = 'invalid_' . $index;
	$bad['enabled'] = false;
	$bad['settings'] = array_merge( $bad['settings'], array_diff_key( $invalid, array( 'api_key' => '' ) ) );
	$connections->save( $bad, array( 'api_key' => $invalid['api_key'] ?? $stored_key ) );
	$before = count( $requests );
	$check = $mailgun->test_connection( $connections->find( $bad['id'] ) );
	sw_mailgate_check( Send_Result::AUTH === $check->error_class() && $before === count( $requests ), 'Invalid Mailgun configuration is rejected before HTTP (case ' . $index . ').' );
}

$reply = sw_mailgate_http_response( 200, array( 'id' => $message_id, 'message' => 'Queued' ) );
Redirection_Settings::save( 'safe-sink@example.test', '' );
sw_mailgate_check( wp_mail( 'original@example.test', 'Redirection with Mailgun', 'Body', $headers ), 'Mailgun works with the M3 redirection pipeline.' );
$upload = sw_mailgate_read_upload( end( $requests )['args'] );
sw_mailgate_check( 'safe-sink@example.test' === $upload['to']['value'] && str_contains( $upload['message']['value'], '[Redirected] Redirection with Mailgun' ) && ! str_contains( $upload['message']['value'], 'bcc@example.test' ), 'Mailgun receives only the redirected envelope and never the original Bcc header.' );
Redirection_Settings::save( '', '' );

// Provider fields integrate with the real generic admin editor and connection-test action.
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
set_current_screen( 'toplevel_page_sw-mailgate' );
$registry = new Provider_Registry( array( $mailgun, $provider ) );
$admin = new Admin_Page( $connections, $registry, $secret_store, new Dispatcher( $connections, $registry ), new Redirector() );
$_GET = array( 'view' => 'edit', 'connection' => 'mailgun' );
ob_start();
$admin->render_connections();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'secrets[api_key]' ) && str_contains( $html, 'settings[region]' ) && ! str_contains( $html, $stored_key ) && ! str_contains( $html, $key ), 'Generic editor renders Mailgun fields without printing API key values.' );
sw_mailgate_check( 'constant' !== $mailgun_mode || $connection->is_locked( 'api_key' ) && $connection->is_locked( 'domain' ) && $connection->is_locked( 'region' ), 'wp-config provider overrides are locked in the editor.' );

$reply = sw_mailgate_http_response( 200, array( 'domain' => array( 'name' => $domain, 'state' => 'active' ) ) );
$_POST = array( 'connection' => 'mailgun' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_connection_mailgun' );
$stop_redirect = static function (): never { throw new RuntimeException( 'test-result-redirect' ); };
add_filter( 'wp_redirect', $stop_redirect );
$before = count( $requests );
try {
	$admin->handle_test_connection();
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-result-redirect' === $error->getMessage(), 'Admin connection test reaches its result redirect.' );
} finally {
	remove_filter( 'wp_redirect', $stop_redirect );
}
sw_mailgate_check( count( $requests ) === $before + 1 && 'GET' === end( $requests )['args']['method'], 'Admin connection test is read-only and sends no email.' );
if ( ! $logging ) {
	sw_mailgate_check( 0 === $logs->count(), 'Mailgun requests and failures never enable logging without wp-config opt-in.' );
}
remove_filter( 'pre_http_request', $intercept, 10 );
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
sw_mailgate_check( false === get_option( Secret_Store::OPTION ), 'Opt-in uninstall removes Mailgun credentials.' );
echo 'PASS: Mailgun integration completed.' . PHP_EOL;
