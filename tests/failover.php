<?php
/**
 * M5 cases, included by integration.php enabled failover run.
 * Circuit breaker, timeout policy, alert mails and the retry queue with real WordPress, PHPMailer and MariaDB.
 * Transports are deterministic test doubles; HTTP is intercepted. No external mail is sent.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Admin\Log_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Http\Http_Client;
use SWMailgate\Installer;
use SWMailgate\Logging\Privacy;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Routing\Circuit_Breaker;
use SWMailgate\Routing\Failover_Settings;
use SWMailgate\Routing\Failure_Alert;
use SWMailgate\Routing\Retry_Queue;
use SWMailgate\Security\Secret_Store;

$scripted = new class() implements Provider {
	/** @var array<string, string> Connection ID => ok|transient|auth|permanent */
	public array $behaviour = array();
	public array $sent      = array();
	public function id(): string { return 'scripted'; }
	public function label(): string { return 'Scripted'; }
	public function fields(): array { return array(); }
	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {}
	public function test_connection( Connection $connection ): Send_Result { return Send_Result::success(); }
	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$mailer       = $envelope->mailer();
		$this->sent[] = array(
			'connection'  => $connection->id(),
			'to'          => $envelope->to(),
			'cc'          => $envelope->cc(),
			'bcc'         => $envelope->bcc(),
			'from'        => $envelope->from(),
			'reply_to'    => array_column( $mailer->getReplyToAddresses(), 0 ),
			'subject'     => $envelope->subject(),
			'body'        => $mailer->Body,
			'type'        => $mailer->ContentType,
			'attachments' => array_map( static fn( array $a ): array => array( $a[2], $a[5] ? $a[0] : (string) file_get_contents( $a[0] ) ), $mailer->getAttachments() ),
			'mime'        => $envelope->mime(),
		);
		return match ( $this->behaviour[ $connection->id() ] ?? 'ok' ) {
			'ok'        => Send_Result::success( 'scripted-id' ),
			'auth'      => Send_Result::failure( Send_Result::AUTH, 'Login rejected for ' . $connection->id() ),
			'permanent' => Send_Result::failure( Send_Result::PERMANENT, 'Recipient rejected' ),
			default     => Send_Result::failure( Send_Result::TRANSIENT, 'Server unavailable at ' . $connection->id() ),
		};
	}
};
add_filter( 'sw_mailgate_providers', static fn( array $providers ): array => array_merge( $providers, array( $scripted ) ) );

$plugin_dispatcher = ( static function () {
	$catcher = $GLOBALS['phpmailer'];
	$read    = Closure::bind( fn() => $this->dispatcher, $catcher, Mail_Catcher::class );
	return $read();
} )();
$breaker  = new Circuit_Breaker();
$queue    = new Retry_Queue( new Secret_Store() );
$alert    = null;
foreach ( $GLOBALS['wp_filter']['sw_mailgate_connection_down']->callbacks ?? array() as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Failure_Alert ) {
			$alert = $callback['function'][0];
		}
	}
}
sw_mailgate_check( $alert instanceof Failure_Alert && $plugin_dispatcher instanceof Dispatcher, 'Plugin wires the failure alert and failover dispatcher.' );

$events = array( 'down' => array(), 'recovered' => array(), 'expired' => array() );
add_action( 'sw_mailgate_connection_down', static function ( Connection $connection, array $state ) use ( &$events ): void { $events['down'][] = array( $connection->id(), $state ); }, 10, 2 );
add_action( 'sw_mailgate_connection_recovered', static function ( Connection $connection ) use ( &$events ): void { $events['recovered'][] = $connection->id(); } );
add_action( 'sw_mailgate_queue_expired', static function ( array $summary ) use ( &$events ): void { $events['expired'][] = $summary; } );

function sw_mailgate_reset_chain( Connection_Repository $connections, array $ids ): void {
	delete_option( Connection_Repository::OPTION );
	delete_option( Circuit_Breaker::OPTION );
	foreach ( $ids as $id ) {
		$connections->save( array( 'id' => $id, 'label' => strtoupper( $id ), 'provider' => 'scripted', 'enabled' => true, 'from_email' => $id . '@sender.example.test' ) );
	}
}

function sw_mailgate_attempted( Dispatcher $dispatcher ): array {
	return array_map( static fn( $attempt ): string => $attempt->connection->id() . ':' . ( $attempt->result->is_success() ? 'ok' : $attempt->result->error_class() ), $dispatcher->last_attempts() );
}

function sw_mailgate_expire_pause( string $id ): void {
	$states                      = get_option( Circuit_Breaker::OPTION );
	$states[ $id ]['open_until'] = time() - 1;
	update_option( Circuit_Breaker::OPTION, $states, false );
}

// --- Circuit breaker -------------------------------------------------------------------------------------
Failover_Settings::save( array( 'breaker_threshold' => 2, 'breaker_cooldown' => 10, 'timeout_failover' => true ) );
sw_mailgate_reset_chain( $connections, array( 'alpha', 'beta' ) );
$scripted->behaviour = array( 'alpha' => 'transient', 'beta' => 'ok' );

sw_mailgate_check( wp_mail( 'user@example.test', 'Breaker 1', 'Body' ) && array( 'alpha:transient', 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'First failure fails over normally.' );
sw_mailgate_check( 1 === $breaker->state( 'alpha' )['failures'] && ! $breaker->is_open( 'alpha' ) && array() === $events['down'], 'One failure below the threshold does not pause the connection.' );
wp_mail( 'user@example.test', 'Breaker 2', 'Body' );
sw_mailgate_check( $breaker->is_open( 'alpha' ) && 1 === count( $events['down'] ) && 'alpha' === $events['down'][0][0], 'Reaching the threshold pauses the connection and fires one down event.' );
$state = $breaker->state( 'alpha' );
sw_mailgate_check( 'Server unavailable at alpha' === $state['last_error'] && 'transient' === $state['last_class'] && $state['open_until'] > time() + 9 * MINUTE_IN_SECONDS, 'Paused state records the last error and the configured cooldown.' );
sw_mailgate_check( wp_mail( 'user@example.test', 'Breaker 3', 'Body' ) && array( 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'A paused connection is skipped.' );
$health_before = get_option( Circuit_Breaker::OPTION );
wp_mail( 'user@example.test', 'Breaker 4', 'Body' );
sw_mailgate_check( $health_before === get_option( Circuit_Breaker::OPTION ), 'Regular successful mails do not rewrite the health state.' );

// Alert for the paused connection goes out through the other connection only.
Failover_Settings::save( array( 'breaker_threshold' => 2, 'breaker_cooldown' => 10, 'timeout_failover' => true, 'alert_email' => 'ops@example.test' ) );
$alert->connection_down( $connections->find( 'alpha' ), $state );
$scripted->sent = array();
$alert->flush();
sw_mailgate_check( 1 === count( $scripted->sent ) && 'beta' === $scripted->sent[0]['connection'] && array( 'ops@example.test' ) === $scripted->sent[0]['to'] && str_contains( $scripted->sent[0]['subject'], 'ALPHA' ) && str_contains( $scripted->sent[0]['body'], 'Server unavailable at alpha' ), 'Alert mail names the failing connection and avoids it.' );
$scripted->sent = array();
$alert->flush();
sw_mailgate_check( array() === $scripted->sent, 'Alerts are sent once.' );

// Admin notice and list status.
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$registry = new Provider_Registry( array( $scripted ) );
$admin    = new Admin_Page( $connections, $registry, new Secret_Store(), $plugin_dispatcher, new Redirector(), $breaker );
ob_start();
$admin->health_notice();
$notice = ob_get_clean();
sw_mailgate_check( str_contains( $notice, 'ALPHA' ) && str_contains( $notice, 'is skipped until' ) && str_contains( $notice, 'Server unavailable at alpha' ) && ! str_contains( $notice, 'BETA' ), 'Admin notice reports the paused connection and its last error.' );
$_GET = array();
ob_start();
$admin->render_connections();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'Paused until' ) && str_contains( $html, 'value="reset"' ) && str_contains( $html, '2 failures in a row' ), 'Connection list shows the pause and a reset action.' );

// Half-open: after the cooldown one more failure pauses again without a second down event.
sw_mailgate_expire_pause( 'alpha' );
sw_mailgate_check( ! $breaker->is_open( 'alpha' ) && $breaker->is_failing( 'alpha' ), 'After the cooldown the connection is failing but no longer skipped.' );
ob_start();
$admin->health_notice();
sw_mailgate_check( str_contains( ob_get_clean(), 'The next mail tries it again' ), 'Notice explains that the next mail retries the connection.' );
wp_mail( 'user@example.test', 'Breaker half-open', 'Body' );
sw_mailgate_check( array( 'alpha:transient', 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ) && $breaker->is_open( 'alpha' ) && 3 === $breaker->state( 'alpha' )['failures'] && 1 === count( $events['down'] ), 'A failed trial pauses again immediately without repeating the alert.' );

// Recovery.
sw_mailgate_expire_pause( 'alpha' );
$scripted->behaviour['alpha'] = 'ok';
wp_mail( 'user@example.test', 'Breaker recovered', 'Body' );
sw_mailgate_check( array( 'alpha:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ) && 0 === $breaker->state( 'alpha' )['failures'] && array( 'alpha' ) === $events['recovered'], 'A success closes the breaker and fires a recovery event.' );
ob_start();
$admin->health_notice();
sw_mailgate_check( '' === ob_get_clean(), 'No notice while every connection works.' );
$scripted->sent = array();
$alert->flush();
sw_mailgate_check( 1 === count( $scripted->sent ) && str_contains( $scripted->sent[0]['subject'], 'works again' ), 'Recovery alert is sent.' );

// Error classes that describe the message do not count.
$scripted->behaviour = array( 'alpha' => 'permanent', 'beta' => 'ok' );
wp_mail( 'user@example.test', 'Permanent', 'Body' );
wp_mail( 'user@example.test', 'Permanent', 'Body' );
sw_mailgate_check( 0 === $breaker->state( 'alpha' )['failures'], 'Permanent errors do not count as connection failures.' );

// Auth errors count; if every connection is paused, the whole chain is still tried.
$scripted->behaviour = array( 'alpha' => 'auth', 'beta' => 'transient' );
wp_mail( 'user@example.test', 'All down 1', 'Body' );
wp_mail( 'user@example.test', 'All down 2', 'Body' );
sw_mailgate_check( $breaker->is_open( 'alpha' ) && $breaker->is_open( 'beta' ), 'Login and temporary errors pause both connections.' );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'All down 3', 'Body' ) && array( 'alpha:auth', 'beta:transient' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'When every connection is paused, the full chain is tried instead of dropping the mail.' );
$plugin_dispatcher->restrict_to( 'alpha' );
$scripted->behaviour['alpha'] = 'ok';
wp_mail( 'user@example.test', 'Explicit test', 'Body' );
$plugin_dispatcher->restrict_to( null );
sw_mailgate_check( array( 'alpha:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ) && ! $breaker->is_failing( 'alpha' ), 'A test mail to a paused connection is sent and closes its breaker.' );

// Reset, save and delete clear the state.
$breaker->close( $connections->find( 'beta' ) );
sw_mailgate_check( ! $breaker->is_failing( 'beta' ) && in_array( 'beta', $events['recovered'], true ), 'Reset closes the breaker and reports the recovery.' );
Failover_Settings::save( array( 'breaker_threshold' => 0 ) );
$scripted->behaviour = array( 'alpha' => 'transient', 'beta' => 'ok' );
for ( $i = 0; $i < 4; ++$i ) {
	wp_mail( 'user@example.test', 'Breaker off', 'Body' );
}
sw_mailgate_check( array( 'alpha:transient', 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ) && ! $breaker->is_failing( 'alpha' ) && array() === (array) get_option( Circuit_Breaker::OPTION, array() ), 'Threshold 0 disables pausing and writes no state.' );

// --- Timeout policy ---------------------------------------------------------------------------------------
sw_mailgate_check(
	Http_Client::is_ambiguous( 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' )
	&& Http_Client::is_ambiguous( 'cURL error 52: Empty reply from server' )
	&& Http_Client::is_ambiguous( 'cURL error 56: Recv failure: Connection reset by peer' )
	&& ! Http_Client::is_ambiguous( 'cURL error 28: Connection timed out after 5001 milliseconds' )
	&& ! Http_Client::is_ambiguous( 'cURL error 7: Failed to connect to api.mailgun.net port 443: Connection refused' )
	&& ! Http_Client::is_ambiguous( 'cURL error 6: Could not resolve host: api.mailgun.net' )
	&& ! Http_Client::is_ambiguous( 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ),
	'Read timeouts and lost responses are ambiguous; DNS, connect and TLS errors are not.'
);
delete_option( Connection_Repository::OPTION );
$connections->save( array( 'id' => 'mailgun', 'label' => 'Mailgun', 'provider' => 'mailgun', 'enabled' => true, 'from_email' => 'mg@sender.example.test', 'settings' => array( 'domain' => 'mg.example.test', 'region' => 'eu' ) ), array( 'api_key' => 'key-timeout-fixture' ) );
$connections->save( array( 'id' => 'beta', 'label' => 'BETA', 'provider' => 'scripted', 'enabled' => true, 'from_email' => 'beta@sender.example.test' ) );
$transport_error = 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received';
$intercept       = static function ( $response, array $args, string $url ) use ( &$transport_error ) {
	return str_contains( $url, 'mailgun.net' ) ? new WP_Error( 'http_request_failed', $transport_error ) : $response;
};
add_filter( 'pre_http_request', $intercept, 10, 3 );
Failover_Settings::save( array( 'breaker_threshold' => 0, 'timeout_failover' => true ) );
sw_mailgate_check( wp_mail( 'user@example.test', 'Timeout failover', 'Body' ) && array( 'mailgun:transient', 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'By default an API timeout fails over.' );
sw_mailgate_check( str_contains( implode( "\n", $plugin_dispatcher->last_attempts()[0]->result->debug() ), 'no response (timeout)' ), 'Diagnostics name the missing response.' );
Failover_Settings::save( array( 'breaker_threshold' => 0, 'timeout_failover' => false ) );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Timeout no failover', 'Body' ) && array( 'mailgun:uncertain' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'With failover on timeouts disabled, an ambiguous timeout stops the chain as uncertain.' );
sw_mailgate_check( str_contains( $plugin_dispatcher->last_attempts()[0]->result->message(), 'may have been accepted' ) && 'uncertain' === $logs->search()[0]['status'], 'Uncertain timeout is explained and logged.' );
$transport_error = 'cURL error 7: Failed to connect to api.eu.mailgun.net port 443: Connection refused';
sw_mailgate_check( wp_mail( 'user@example.test', 'Connect error', 'Body' ) && array( 'mailgun:transient', 'beta:ok' ) === sw_mailgate_attempted( $plugin_dispatcher ), 'Connection errors still fail over when timeouts do not.' );
remove_filter( 'pre_http_request', $intercept, 10 );

// --- Retry queue -----------------------------------------------------------------------------------------
sw_mailgate_reset_chain( $connections, array( 'alpha', 'beta' ) );
Failover_Settings::save( array( 'breaker_threshold' => 0, 'queue_enabled' => false ) );
$scripted->behaviour = array( 'alpha' => 'transient', 'beta' => 'auth' );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Queue off', 'Body' ) && 0 === $queue->count(), 'Without the queue an exhausted chain fails.' );

Failover_Settings::save( array( 'breaker_threshold' => 0, 'queue_enabled' => true, 'queue_hours' => 24 ) );
Settings::save( array( 'store_body' => false, 'retention' => 30 ) );
$file = wp_tempnam( 'sw-mailgate-attachment' );
file_put_contents( $file, "attachment\x00bytes" );
$headers = array( 'Cc: cc@example.test', 'Bcc: bcc@example.test', 'X-Custom: kept', 'Content-Type: text/html; charset=UTF-8' );
sw_mailgate_check( wp_mail( 'queued@example.test', 'Queued mail', '<p>Queued secret body</p>', $headers, array( $file ) ), 'An exhausted chain with temporary errors queues the mail and reports success.' );
unlink( $file );
sw_mailgate_check( 'queued' === $plugin_dispatcher->last_outcome() && 1 === $queue->count() && (bool) wp_next_scheduled( Retry_Queue::CRON_HOOK ), 'Queued outcome is reported and the retry cron is scheduled.' );
$row = $logs->search()[0];
sw_mailgate_check( 'queued' === $row['status'] && 'beta' === $row['connection_id'] && 2 === (int) $row['attempt_number'], 'The last attempt is logged as queued.' );
$group   = $row['attempt_group'];
$stored  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i', Retry_Queue::table() ), ARRAY_A );
sw_mailgate_check( ! str_contains( $stored['payload'], 'Queued secret body' ) && ! str_contains( $stored['payload'], 'queued@example.test' ) && ! str_contains( $stored['payload'], 'bcc@example.test' ), 'Queued message data is encrypted.' );
sw_mailgate_check( strtotime( $stored['next_run_at'] . ' UTC' ) > time() + 4 * MINUTE_IN_SECONDS && strtotime( $stored['expires_at'] . ' UTC' ) > time() + 23 * HOUR_IN_SECONDS, 'First retry waits five minutes; expiry follows the setting.' );

$scripted->sent = array();
do_action( Retry_Queue::CRON_HOOK );
sw_mailgate_check( array() === $scripted->sent && 1 === $queue->count(), 'Cron does not retry before the message is due.' );

$queue->process( $plugin_dispatcher, true );
$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i', Retry_Queue::table() ), ARRAY_A );
sw_mailgate_check( 2 === count( $scripted->sent ) && 1 === (int) $entry['retries'] && 4 === (int) $entry['attempt_number'] && strtotime( $entry['next_run_at'] . ' UTC' ) > time() + 14 * MINUTE_IN_SECONDS && null === $entry['locked_until'], 'A failed retry is rescheduled with backoff and released.' );
$retry_rows = $logs->attempts( $group );
sw_mailgate_check( array( 1, 2, 3, 4 ) === array_map( 'intval', array_column( $retry_rows, 'attempt_number' ) ) && 'queued' === end( $retry_rows )['status'], 'Retries continue the attempt group in the log.' );

$scripted->behaviour['beta'] = 'ok';
$scripted->sent              = array();
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_run_at = %s', Retry_Queue::table(), gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
do_action( Retry_Queue::CRON_HOOK );
$delivered = end( $scripted->sent );
sw_mailgate_check( 0 === $queue->count() && false === wp_next_scheduled( Retry_Queue::CRON_HOOK ), 'A delivered retry leaves the queue and stops the cron.' );
sw_mailgate_check( 'beta' === $delivered['connection'] && array( 'queued@example.test' ) === $delivered['to'] && array( 'cc@example.test' ) === $delivered['cc'] && array( 'bcc@example.test' ) === $delivered['bcc'], 'Retried message keeps To, Cc and Bcc.' );
sw_mailgate_check( '<p>Queued secret body</p>' === $delivered['body'] && 'text/html' === $delivered['type'] && str_contains( $delivered['mime'], 'X-Custom: kept' ), 'Retried message keeps HTML body and custom headers.' );
sw_mailgate_check( array( array( basename( $file ), "attachment\x00bytes" ) ) === $delivered['attachments'], 'Attachments survive the deletion of their temporary file.' );
sw_mailgate_check( 'beta@sender.example.test' === $delivered['from'] && array() === $delivered['reply_to'], 'Each retry applies the connection sender without a Reply-To for the substituted default.' );
$final = $logs->attempts( $group );
sw_mailgate_check( 'sent' === end( $final )['status'] && 6 === (int) end( $final )['attempt_number'], 'The log group ends with the delivery.' );

// Expiry, unreadable entries, privacy, test mails and permanent errors.
$scripted->behaviour = array( 'alpha' => 'transient', 'beta' => 'transient' );
wp_mail( 'expire@example.test', 'Expiring', 'Body' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_run_at = %s, expires_at = %s', Retry_Queue::table(), gmdate( 'Y-m-d H:i:s', time() - 1 ), gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
do_action( Retry_Queue::CRON_HOOK );
sw_mailgate_check( 0 === $queue->count() && 1 === count( $events['expired'] ) && 'Expiring' === $events['expired'][0]['subject'] && 1 === $events['expired'][0]['recipients'], 'An expired message is given up and reported.' );
sw_mailgate_check( 'failed' === $logs->search()[0]['status'], 'The final attempt of an expired message is logged as failed.' );

wp_mail( 'corrupt@example.test', 'Corrupt', 'Body' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload = %s', Retry_Queue::table(), 'v1:other-key:AAAA' ) );
$queue->process( $plugin_dispatcher, true );
sw_mailgate_check( 0 === $queue->count(), 'Entries that cannot be decrypted are removed.' );

wp_mail( 'private@example.test', 'Privacy', 'Body', array( 'Bcc: hidden-queue@example.test' ) );
$erased = ( new Privacy( $logs, $queue ) )->erase( 'hidden-queue@example.test' );
sw_mailgate_check( $erased['items_removed'] && 0 === $queue->count(), 'Privacy erasure removes queued messages of the address.' );

$plugin_dispatcher->restrict_to( 'alpha' );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Test mail', 'Body' ), 'A failing test mail to one connection is reported, not queued.' );
$plugin_dispatcher->restrict_to( null );
$scripted->behaviour = array( 'alpha' => 'permanent' );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Rejected', 'Body' ) && 0 === $queue->count(), 'Permanent errors are never queued.' );

$big = wp_tempnam( 'sw-mailgate-big' );
file_put_contents( $big, str_repeat( 'x', Retry_Queue::MAX_ATTACHMENT_BYTES + 1 ) );
$scripted->behaviour = array( 'alpha' => 'transient', 'beta' => 'transient' );
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Too big', 'Body', array(), array( $big ) ) && 0 === $queue->count(), 'Messages with too large attachments fail instead of being queued.' );
unlink( $big );

// --- Settings ---------------------------------------------------------------------------------------------
$before = get_option( Failover_Settings::OPTION );
sw_mailgate_check( Failover_Settings::save( array( 'alert_email' => "ops@example.test\r\nBcc: x@example.test" ) ) instanceof WP_Error && $before === get_option( Failover_Settings::OPTION ), 'Invalid alert addresses are rejected without changing the settings.' );
Failover_Settings::save( array( 'breaker_threshold' => 99, 'breaker_cooldown' => 0, 'queue_hours' => 500 ) );
$clamped = Failover_Settings::get();
sw_mailgate_check( 20 === $clamped['breaker_threshold'] && 1 === $clamped['breaker_cooldown'] && 72 === $clamped['queue_hours'] && false === $clamped['timeout_failover'] && false === $clamped['queue_enabled'], 'Numeric settings are clamped; unchecked options are stored as off.' );

Failover_Settings::save( array( 'queue_enabled' => true ) );
wp_mail( 'listed@example.test', 'Listed in queue', 'Body' );
$page = new Log_Page( $logs, $connections, $queue, $plugin_dispatcher );
ob_start();
$page->settings();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'name="breaker_threshold"' ) && str_contains( $html, 'name="timeout_failover"' ) && str_contains( $html, 'name="alert_email"' ) && 1 === preg_match( '/name="queue_enabled" value="1"\s+checked=/', $html ), 'Settings page renders the failover options.' );
sw_mailgate_check( str_contains( $html, 'Listed in queue' ) && str_contains( $html, 'value="discard"' ) && ! str_contains( $html, 'listed@example.test' ), 'Settings page lists waiting messages without addresses.' );
ob_start();
$page->widget();
sw_mailgate_check( str_contains( ob_get_clean(), '1 message is waiting in the retry queue' ), 'Dashboard widget reports waiting messages.' );

// --- Uninstall --------------------------------------------------------------------------------------------
update_option( Installer::DELETE_DATA_OPTION, false );
Installer::uninstall();
sw_mailgate_check( 1 === $queue->count() && false === wp_next_scheduled( Retry_Queue::CRON_HOOK ), 'Preserving uninstall keeps the queue but stops its cron.' );
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
sw_mailgate_check(
	null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Retry_Queue::table() ) ) )
	&& false === get_option( Failover_Settings::OPTION )
	&& false === get_option( Circuit_Breaker::OPTION ),
	'Deleting uninstall removes the queue table, failover settings and health state.'
);
echo 'PASS: Failover integration completed.' . PHP_EOL;
