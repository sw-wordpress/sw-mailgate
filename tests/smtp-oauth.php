<?php
/**
 * SMTP XOAUTH2 over actual PHPMailer SASL/DATA code with deterministic transport I/O.
 * Included by oauth.php in both logging modes; all token HTTP is already intercepted.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mailer_Factory;
use SWMailgate\Mail\Redirector;
use SWMailgate\OAuth\OAuth_Client;
use SWMailgate\Providers\OAuth_SMTP;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Providers\Smtp_OAuth_Provider;

class SW_Mailgate_OAuth_SMTP_Fixture extends OAuth_SMTP {
	public array $commands = array();
	public array $payloads = array();
	public array $target = array();
	public bool $tls = false;
	public bool $open = false;
	private string $reply = '';
	private int $recipient_index = 0;
	public function __construct( public array $scenario = array() ) {}
	public function connect( $host, $port = null, $timeout = 30, $options = array() ) {
		$this->target = compact( 'host', 'port', 'options' );
		$this->open = true;
		return true;
	}
	public function connected() { return $this->open; }
	public function close() { $this->open = false; parent::close(); }
	public function startTLS() {
		$this->commands[] = 'STARTTLS';
		if ( ! empty( $this->scenario['tls_failure'] ) ) { $this->setError( 'STARTTLS failed' ); return false; }
		$this->tls = true;
		return true;
	}
	protected function get_lines() { return $this->reply; }
	public function client_send( $data, $command = '' ) {
		$this->commands[] = $command;
		$this->payloads[] = $data;
		$this->edebug( 'CLIENT -> SERVER: ' . $data, self::DEBUG_CLIENT );
		$code = 250;
		if ( 'EHLO' === $command ) {
			$this->reply = "250-smtp.fixture.test\r\n250-STARTTLS\r\n250 AUTH " . ( $this->scenario['methods'] ?? 'LOGIN PLAIN XOAUTH2' ) . "\r\n";
			return strlen( $data );
		}
		if ( 'AUTH' === $command ) {
			sw_mailgate_check( $this->tls && str_starts_with( $data, 'AUTH XOAUTH2 ' ), 'SMTP sends only XOAUTH2, after STARTTLS.' );
			$code = $this->scenario['auth_code'] ?? 235;
		} elseif ( 'AUTH CANCEL' === $command ) { $code = 535; }
		elseif ( 'RCPT TO' === $command ) { $code = $this->scenario['recipients'][ $this->recipient_index++ ] ?? 250; }
		elseif ( 'DATA' === $command && "DATA\r\n" === $data ) { $code = 354; }
		elseif ( 'DATA END' === $command ) { $code = $this->scenario['data_end'] ?? 250; }
		elseif ( 'QUIT' === $command ) { $code = 221; }
		$echo = '';
		if ( ! empty( $this->scenario['echo_credentials'] ) && 'AUTH' === $command ) {
			$echo = ' ' . trim( substr( $data, 13 ) ) . ' ' . base64_decode( trim( substr( $data, 13 ) ) );
		}
		$this->reply = 0 === $code ? '' : $code . ' fixture response' . $echo . "\r\n";
		if ( 0 === $code ) { $this->open = false; }
		return strlen( $data );
	}
}

class SW_Mailgate_OAuth_SMTP_Provider_Fixture extends Smtp_OAuth_Provider {
	public array $scenario = array();
	public array $instances = array();
	protected function smtp_instance(): OAuth_SMTP {
		$instance = new SW_Mailgate_OAuth_SMTP_Fixture( $this->scenario );
		$this->instances[] = $instance;
		return $instance;
	}
}

$smtp_google = new SW_Mailgate_OAuth_SMTP_Provider_Fixture();
$smtp_microsoft = new SW_Mailgate_OAuth_SMTP_Provider_Fixture( false );
$smtp_registry = new Provider_Registry( array( $smtp_google, $smtp_microsoft ) );
foreach ( array( 'google_smtp' => $smtp_google, 'office365_smtp' => $smtp_microsoft ) as $smtp_id => $smtp_provider ) {
	$smtp_settings = array( 'client_id' => 'm6-client', 'username' => 'login@example.test', 'tenant_id' => '12345678-1234-1234-1234-123456789abc' );
	// Even an unsafe old setting or constant must not alter the bearer-token destination or TLS policy.
	$smtp_settings += array( 'host' => 'attacker.example.test', 'port' => '25', 'encryption' => 'none', 'tls_verify' => '0', 'tls_peer_name' => 'attacker.example.test', 'auth' => '0' );
	$connections->save( array( 'id' => $smtp_id, 'provider' => $smtp_id, 'enabled' => false, 'from_email' => 'alias@example.test', 'settings' => $smtp_settings ), array( 'client_secret' => 'm6-client-secret', 'password' => 'unused-password' ) );
	$smtp_connection = $connections->find( $smtp_id );
	$expected_scope = 'google_smtp' === $smtp_id ? OAuth_Client::GOOGLE_SMTP_SCOPE : OAuth_Client::MICROSOFT_SMTP_SCOPE;
	$smtp_params = sw_mailgate_oauth_begin( $client, $smtp_connection );
	sw_mailgate_check( $expected_scope . ( 'office365_smtp' === $smtp_id ? ' offline_access' : '' ) === $smtp_params['scope'], 'SMTP consent requests its own resource scope and offline access.' );
	$token_data = array( 'access_token' => 'm6-smtp-access', 'refresh_token' => 'm6-smtp-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $expected_scope );
	sw_mailgate_check( $client->callback( $smtp_params['state'], 'smtp-code' )['result']->is_success(), 'SMTP code exchange accepts the matching sending permission.' );
	$token_data['scope'] = 'google_smtp' === $smtp_id ? OAuth_Client::GOOGLE_SCOPE : OAuth_Client::GRAPH_SCOPE;
	sw_mailgate_check( $client->access_token( $smtp_connection, true ) instanceof Send_Result, 'API sending permission cannot satisfy an SMTP grant.' );
	$token_data['scope'] = $expected_scope;
	sw_mailgate_oauth_expire( $smtp_id );
	$before = $token_count;
	sw_mailgate_check( $smtp_provider->test_connection( $smtp_connection )->is_success() && $token_count === $before + 1, 'SMTP connection test renews an expired token and authenticates.' );
	$smtp_instance = end( $smtp_provider->instances );
	sw_mailgate_check( ! in_array( 'MAIL FROM', $smtp_instance->commands, true ) && ! in_array( 'DATA', $smtp_instance->commands, true ) && ! $smtp_instance->open, 'Connection test submits no mail and closes the authenticated session.' );
	foreach ( array(
		'accepted' => array( 'success' => true, 'class' => '' ),
		'login rejected' => array( 'auth_code' => 535, 'echo_credentials' => true, 'success' => false, 'class' => Send_Result::AUTH ),
		'temporary login failure' => array( 'auth_code' => 454, 'success' => false, 'class' => Send_Result::TRANSIENT ),
		'SASL challenge rejected' => array( 'auth_code' => 334, 'success' => false, 'class' => Send_Result::AUTH ),
		'no XOAUTH2' => array( 'methods' => 'LOGIN PLAIN', 'success' => false, 'class' => Send_Result::AUTH ),
		'TLS failure' => array( 'tls_failure' => true, 'success' => false, 'class' => Send_Result::AUTH ),
		'partial delivery' => array( 'recipients' => array( 250, 550 ), 'success' => false, 'class' => Send_Result::PARTIAL ),
		'lost DATA acknowledgement' => array( 'data_end' => 0, 'success' => false, 'class' => Send_Result::UNCERTAIN ),
	) as $smtp_case => $smtp_scenario ) {
		$smtp_provider->scenario = $smtp_scenario;
		$smtp_envelope = sw_mailgate_oauth_envelope();
		$smtp_mailer = $smtp_envelope->mailer();
		$smtp_mailer->SMTPKeepAlive = true;
		$smtp_mailer->SMTPOptions = array( 'ssl' => array( 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'peer_name' => 'attacker.example.test', 'cafile' => '/fixture/ca.pem' ) );
		$smtp_provider->prepare( $smtp_mailer, $smtp_connection );
		$smtp_mailer->preSend();
		$smtp_result = $smtp_provider->send( new Envelope( $smtp_mailer ), $smtp_connection );
		$smtp_instance = end( $smtp_provider->instances );
		sw_mailgate_check( $smtp_result->is_success() === $smtp_scenario['success'] && $smtp_result->error_class() === $smtp_scenario['class'], $smtp_id . ': ' . $smtp_case . ' preserves SMTP delivery certainty.' );
		sw_mailgate_check( ! $smtp_instance->open && '' === $smtp_mailer->Password && 'XOAUTH2' === $smtp_mailer->AuthType, 'OAuth SMTP never uses a password or retains an open session.' );
		$target = $smtp_instance->target;
		sw_mailgate_check( ( 'google_smtp' === $smtp_id ? 'smtp.gmail.com' : 'smtp.office365.com' ) === $target['host'] && 587 === $target['port'] && true === $target['options']['ssl']['verify_peer'] && true === $target['options']['ssl']['verify_peer_name'] && false === $target['options']['ssl']['allow_self_signed'] && ! isset( $target['options']['ssl']['peer_name'] ) && '/fixture/ca.pem' === $target['options']['ssl']['cafile'], 'Fixed SMTP endpoint and verified STARTTLS override unsafe settings while retaining the trust store.' );
		$auth_commands = array_filter( $smtp_instance->payloads, static fn( $line ) => str_starts_with( $line, 'AUTH ' ) );
		if ( 'no XOAUTH2' === $smtp_case || 'TLS failure' === $smtp_case ) {
			sw_mailgate_check( array() === $auth_commands, 'Missing XOAUTH2 or failed TLS sends no AUTH command and never downgrades to LOGIN/PLAIN.' );
		} else {
			$auth = trim( substr( reset( $auth_commands ), 13 ) );
			sw_mailgate_check( 'user=login@example.test' . "\x01auth=Bearer m6-smtp-access\x01\x01" === base64_decode( $auth ), 'SASL payload uses the login mailbox, not the From alias, and the acquired bearer token.' );
			sw_mailgate_check( ! str_contains( wp_json_encode( array( $smtp_result->message(), $smtp_result->debug() ) ), $auth ), 'Diagnostics omit the encoded SASL payload even if the server echoes it.' );
		}
		sw_mailgate_check( ! str_contains( wp_json_encode( array( $smtp_result->message(), $smtp_result->debug() ) ), 'm6-smtp-access' ), 'Diagnostics omit the raw access token.' );
		if ( 'SASL challenge rejected' === $smtp_case ) { sw_mailgate_check( in_array( 'AUTH CANCEL', $smtp_instance->commands, true ), 'A rejected Gmail SASL challenge is terminated before cleanup.' ); }
	}
	$smtp_provider->scenario = array( 'auth_code' => 535 );
	sw_mailgate_check( Send_Result::AUTH === $smtp_provider->test_connection( $smtp_connection )->error_class(), 'Connection tests report rejected XOAUTH2 login as a connection failure.' );
	$smtp_provider->scenario = array();
	$smtp_missing_login = $smtp_connection->to_array(); $smtp_missing_login['settings']['username'] = '';
	$connections->save( $smtp_missing_login );
	$before = $token_count;
	sw_mailgate_check( Send_Result::AUTH === $smtp_provider->test_connection( $connections->find( $smtp_id ) )->error_class() && $before === $token_count && array() === end( $smtp_provider->instances )->target, 'Invalid login fails before token HTTP or SMTP socket I/O.' );
	$connections->save( $smtp_connection->to_array() );
	$smtp_pending = sw_mailgate_oauth_begin( $client, $smtp_connection );
	$smtp_changed = $smtp_connection->to_array();
	$smtp_changed['settings']['username'] = 'other@example.test';
	$connections->save( $smtp_changed );
	sw_mailgate_check( ! $client->connected( $connections->find( $smtp_id ) ) && ! $client->callback( $smtp_pending['state'], 'stale-mailbox' )['result']->is_success(), 'Changing SMTP login invalidates cached tokens and pending consent.' );
	$connections->save( $smtp_connection->to_array() );
	$smtp_page = new Admin_Page( $connections, $smtp_registry, $secrets, new Dispatcher( $connections, $smtp_registry ), new Redirector() );
	$_GET = array( 'view' => 'edit', 'connection' => $smtp_id );
	ob_start(); $smtp_page->render_connections(); $smtp_html = ob_get_clean();
	sw_mailgate_check( str_contains( $smtp_html, $expected_scope ) && str_contains( $smtp_html, 'Connect mailbox' ) && str_contains( $smtp_html, 'XOAUTH2 login' ) && ! str_contains( $smtp_html, 'm6-client-secret' ), 'SMTP editor includes its consent guide, connection controls and accurate authentication-test help.' );
}

// Exercise real dispatch, failover and the opt-in logger with the SMTP adapter.
foreach ( $connections->all() as $smtp_saved ) {
	$smtp_disabled = $smtp_saved->to_array(); $smtp_disabled['enabled'] = false; $connections->save( $smtp_disabled );
}
$smtp_primary = $connections->find( 'google_smtp' )->to_array(); $smtp_primary['enabled'] = true; $connections->save( $smtp_primary );
$connections->save( array( 'id' => 'smtp_fallback', 'provider' => 'test', 'enabled' => true, 'from_email' => 'fallback@example.test' ) );
$smtp_dispatcher = new Dispatcher( $connections, $smtp_registry );
$token_data = null;
foreach ( array(
	array( 'auth_code' => 535, 'fallback' => true ),
	array( 'data_end' => 0, 'fallback' => false ),
	array( 'recipients' => array( 250, 550 ), 'fallback' => false ),
) as $smtp_scenario ) {
	$smtp_google->scenario = $smtp_scenario;
	$before = count( $provider->messages );
	try { $smtp_dispatcher->dispatch( sw_mailgate_oauth_envelope()->mailer() ); }
	catch ( \PHPMailer\PHPMailer\Exception $error ) {}
	sw_mailgate_check( count( $provider->messages ) === $before + ( $smtp_scenario['fallback'] ? 1 : 0 ), 'SMTP authentication failure permits fallback; partial or uncertain delivery never resubmits via fallback.' );
}
$smtp_invalid = $connections->find( 'office365_smtp' )->to_array();
$smtp_invalid['settings']['mode'] = 'application'; $connections->save( $smtp_invalid );
sw_mailgate_check( $client->authorize( $connections->find( 'office365_smtp' ) ) instanceof Send_Result, 'SMTP rejects unsupported application authorization rather than requesting the wrong grant.' );
if ( 'enabled' === $logging_mode ) {
	sw_mailgate_check( ! str_contains( wp_json_encode( $logs->search() ), 'm6-smtp-access' ) && ! str_contains( wp_json_encode( $logs->search() ), 'm6-smtp-refresh' ), 'SMTP attempt logs never retain bearer or refresh tokens.' );
}
echo 'PASS: SMTP XOAUTH2 regressions completed.' . PHP_EOL;
