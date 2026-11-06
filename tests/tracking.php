<?php
/** M7 REST, authentication, storage, UI and privacy regressions. @package SWMailgate */
declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Admin\Log_Page;
use SWMailgate\Admin\Webhook_Admin;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Security\Secret_Store;
use SWMailgate\Tracking\Event_Repository;
use SWMailgate\Tracking\Webhook_Controller;


$wpdb->query( 'ALTER TABLE ' . Event_Repository::table() . ' DROP INDEX pending_message, DROP COLUMN provider, DROP COLUMN provider_message_hash' );
$wpdb->insert( Event_Repository::table(), array( 'token_hash' => hash( 'sha256', 'v5-event-fixture' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'connection_id' => 'legacy', 'log_id' => 42, 'status' => 'delivered' ) );
update_option( 'sw_mailgate_db_version', 5 );
\SWMailgate\Installer::maybe_upgrade();
$legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', Event_Repository::table(), hash( 'sha256', 'v5-event-fixture' ) ), ARRAY_A );
sw_mailgate_check( 6 === (int) get_option( 'sw_mailgate_db_version' ) && 'delivered' === $legacy['status'] && '' === $legacy['provider_message_hash'] && '' === $legacy['provider'], 'The v5 schema upgrades with correlation fields while preserving existing events.' );
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Event_Repository::table() ) );
delete_option( Connection_Repository::OPTION );
$connections->save( array( 'id' => 'mailgun', 'provider' => 'mailgun', 'from_email' => 'sender@example.test', 'settings' => array( 'domain' => 'mg.example.test', 'region' => 'eu' ) ), array( 'api_key' => 'private-api-key', 'webhook_signing_key' => 'private-signing-key' ) );
$connections->save( array( 'id' => 'smtp', 'provider' => 'smtp' ) );
$mailgun = new Mailgun_Provider();
$registry = new Provider_Registry( array( $mailgun, new Smtp_Provider() ) );
$controller = new Webhook_Controller( $connections, $registry );
$connection = $connections->find( 'mailgun' );
$fixture = array( 'timestamp' => time(), 'id' => 'event1', 'event' => 'delivered', 'message' => array( 'headers' => array( 'message-id' => 'message@example.test' ) ), 'recipient' => 'secret-recipient@example.test' );
$make = static function ( array $event, ?string $token = null, ?int $time = null ) use ( $fixture ): WP_REST_Request {
	$token ??= bin2hex( random_bytes( 16 ) );
	$timestamp = (string) ( $time ?? time() );
	$request = new WP_REST_Request( 'POST', '/sw-mailgate/v1/webhook/mailgun' );
	$request->set_url_params( array( 'connection' => 'mailgun' ) );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'signature' => array( 'timestamp' => $timestamp, 'token' => $token, 'signature' => hash_hmac( 'sha256', $timestamp . $token, 'private-signing-key' ) ), 'event-data' => array_replace_recursive( $fixture, array( 'id' => bin2hex( random_bytes( 12 ) ) ), $event ) ) ) );
	return $request;
};
$seed = static function ( string $id, string $conn = 'mailgun' ) use ( $logs, $wpdb ): int {
	$logs->insert( array( 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'sent', 'connection_id' => $conn, 'provider' => 'mailgun', 'attempt_group' => wp_generate_uuid4(), 'attempt_number' => 1, 'from_email' => 'sender@example.test', 'recipients' => '["secret-recipient@example.test"]', 'original_to' => '[]', 'subject' => 'Webhook fixture', 'error_class' => '', 'error_message' => '', 'duration' => 0, 'provider_message_id' => $id, 'provider_message_hash' => Log_Repository::message_hash( $id ), 'caller' => '', 'address_hashes' => wp_json_encode( array( Log_Repository::address_hash( 'secret-recipient@example.test' ) ) ), 'payload' => null ) );
	return (int) $wpdb->insert_id;
};
$id = $seed( '<message@example.test>' );
$other = $seed( '<message@example.test>', 'other' );
$req = $make( array() );
sw_mailgate_check( false !== $mailgun->verify_request( $connection, $req ), 'Mailgun HMAC uses the separate signing key.' );
sw_mailgate_check( 200 === $controller->receive( $req )->get_status(), 'Authenticated webhook is accepted.' );
$enabled = Settings::get()['enabled'];
sw_mailgate_check( ( $enabled ? 'delivered' : 'sent' ) === $logs->find( $id )['status'] && 'sent' === $logs->find( $other )['status'], 'Only a matching connection is updated and logging opt-in is respected.' );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() );
$replay = $make( array( 'event' => 'complained' ), json_decode( $req->get_body(), true )['signature']['token'] );
sw_mailgate_check( 200 === $controller->receive( $replay )->get_status() && $count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() ) && ( $enabled ? 'delivered' : 'sent' ) === $logs->find( $id )['status'], 'Replays are acknowledged without new rows or effects, even with a changed payload.' );
foreach ( array( -301, 301, -86400 ) as $offset ) {
	sw_mailgate_check( 403 === $controller->receive( $make( array(), null, time() + $offset ) )->get_status(), 'Timestamps outside the five-minute past/future window are rejected.' );
}
foreach ( array( array( 'token' => array() ), array( 'timestamp' => 'bad' ), array( 'signature' => str_repeat( '0', 64 ) ), array( 'signature' => array() ) ) as $bad ) {
	$r = $make( array() );
	$data = json_decode( $r->get_body(), true );
	$data['signature'] = array_replace( $data['signature'], $bad );
	$r->set_body( wp_json_encode( $data ) );
	sw_mailgate_check( 403 === $controller->receive( $r )->get_status(), 'Malformed or forged signature is rejected.' );
}
foreach ( array( 'missing', 'smtp' ) as $target ) {
	$r = $make( array() ); $r->set_url_params( array( 'connection' => $target ) );
	sw_mailgate_check( 403 === $controller->receive( $r )->get_status(), 'Unknown/non-webhook providers are rejected.' );
}
$r = $make( array() ); $r->set_body( str_repeat( 'x', 65537 ) );
sw_mailgate_check( 413 === $controller->receive( $r )->get_status(), 'Webhook bodies are bounded.' );
$r = $make( array() ); $r->set_body( '{' );
sw_mailgate_check( 403 === $controller->receive( $r )->get_status(), 'Invalid JSON is rejected without a server error.' );
foreach ( array( array( 'event' => 'opened' ), array( 'event' => 'failed', 'severity' => 'temporary' ), array( 'id' => array() ), array( 'timestamp' => array() ), array( 'timestamp' => -1 ), array( 'message' => array( 'headers' => array( 'message-id' => '<>' ) ) ), array( 'message' => array( 'headers' => array( 'message-id' => "bad\r\nid" ) ) ) ) as $event ) {
	sw_mailgate_check( array() === $mailgun->parse_events( $make( $event ) ), 'Unsupported events and malformed identifiers do not alter delivery.' );
}
foreach ( array( array( 'event' => 'failed', 'severity' => 'permanent' ), array( 'event' => 'delivered' ), array( 'event' => 'complained' ), array( 'event' => 'failed', 'severity' => 'permanent' ) ) as $event ) {
	sw_mailgate_check( 200 === $controller->receive( $make( $event ) )->get_status(), 'Delivery lifecycle events are accepted.' );
}
sw_mailgate_check( ( $enabled ? 'complained' : 'sent' ) === $logs->find( $id )['status'], 'Complaint and bounce outcomes cannot regress through late delivery events.' );
$stored = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Event_Repository::table(), ARRAY_A ) );
sw_mailgate_check( ! str_contains( $stored, 'secret-recipient' ) && ! str_contains( $stored, 'message@example.test' ) && ! str_contains( $stored, 'private-' ), 'Event storage contains no addresses, raw payloads, message IDs or credentials.' );
if ( ! $enabled ) {
	sw_mailgate_check( 0 === (int) $wpdb->get_var( 'SELECT SUM(log_id) FROM ' . Event_Repository::table() ), 'Disabled logging stores only replay claims.' );
	$event_queries = array();
	$observe_queries = static function ( string $query ) use ( &$event_queries ): string {
		if ( str_contains( $query, Event_Repository::table() ) ) { $event_queries[] = $query; }
		return $query;
	};
	add_filter( 'query', $observe_queries );
	( new Event_Repository() )->reconcile_pending();
	remove_filter( 'query', $observe_queries );
	sw_mailgate_check( array() === $event_queries, 'Disabled logging never queries pending delivery metadata.' );
}

if ( $enabled ) {
	$early = $make( array( 'message' => array( 'headers' => array( 'message-id' => 'early@example.test' ) ) ) );
	sw_mailgate_check( 200 === $controller->receive( $early )->get_status(), 'A delivery webhook preceding its log is durably accepted without asking Mailgun to retry.' );
	$early_hash = Log_Repository::message_hash( 'early@example.test' );
	$early_pending = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE provider_message_hash = %s AND log_id = 0', Event_Repository::table(), $early_hash ), ARRAY_A );
	sw_mailgate_check( 1 === count( $early_pending ) && 'delivered' === $early_pending[0]['status'], 'Early delivery retains only its opaque message correlation hash and status.' );
	$early_id = $seed( '<early@example.test>' );
	sw_mailgate_check( 'delivered' === $logs->find( $early_id )['status'], 'Inserting the log reconciles early delivery without a second HTTP request.' );
	$dedup = $make( array( 'id' => 'dedup-event' ) );
	$controller->receive( $dedup );
	$duplicate = $make( array( 'id' => 'dedup-event', 'event' => 'complained' ) );
	$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() . " WHERE status <> ''" );
	$controller->receive( $duplicate );
	sw_mailgate_check( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() . " WHERE status <> ''" ), 'The same event ID with a fresh request token is stored only once.' );

	$previous_day = $make( array( 'id' => 'dedup-event', 'timestamp' => time() - DAY_IN_SECONDS ) );
	$controller->receive( $previous_day );
	sw_mailgate_check( $before + 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() . " WHERE status <> ''" ), 'Daily-scoped event IDs do not suppress events from another day.' );
	$tracking = $logs->tracking_totals( 7 );
	sw_mailgate_check( 1 === $tracking['bounced'] && 3 === $tracking['accepted'], 'Bounce statistics retain adverse events when complaint status takes precedence.' );
}

if ( $enabled ) {
	Settings::save( array( 'mask_recipients' => true ) );
	$attempt = new \SWMailgate\Mail\Attempt( wp_generate_uuid4(), 1, $connection, \SWMailgate\Providers\Send_Result::success( '<masked@example.test>' ), 'sender@example.test', array( 'secret-recipient@example.test' ), 'Masked tracking' );
	( new \SWMailgate\Logging\Logger( $logs ) )->record( $attempt );
	$masked_id = (int) $wpdb->insert_id;
	$masked = $logs->find( $masked_id );
	sw_mailgate_check( ! str_contains( $masked['provider_message_id'], 'masked@' ) && Log_Repository::message_hash( 'masked@example.test' ) === $masked['provider_message_hash'], 'Masked logging retains only an opaque correlation hash of the original message ID.' );
	$masked_request = $make( array( 'message' => array( 'headers' => array( 'message-id' => 'masked@example.test' ) ) ) );
	sw_mailgate_check( 200 === $controller->receive( $masked_request )->get_status() && 'delivered' === $logs->find( $masked_id )['status'], 'Webhooks correlate masked logs without restoring personal identifiers.' );
	Settings::save( array() );
	$rollback_id = $seed( '<rollback@example.test>' );
	$rollback = $make( array( 'message' => array( 'headers' => array( 'message-id' => 'rollback@example.test' ) ) ) );
	$break_update = static fn( string $query ): string => str_starts_with( $query, 'UPDATE `' . Event_Repository::table() . '` e INNER JOIN' ) ? 'UPDATE swmg_missing_table SET id = 0' : $query;
	add_filter( 'query', $break_update );
	$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() );
	sw_mailgate_check( 200 === $controller->receive( $rollback )->get_status() && 'sent' === $logs->find( $rollback_id )['status'] && $before + 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() ), 'A failed log update preserves accepted delivery metadata and rolls back correlation only.' );
	remove_filter( 'query', $break_update );
	do_action( Event_Repository::CRON_HOOK );
	sw_mailgate_check( 'delivered' === $logs->find( $rollback_id )['status'], 'Hourly reconciliation recovers a failed log update without another webhook.' );
	$after_recovery = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() );
	sw_mailgate_check( 200 === $controller->receive( $rollback )->get_status() && $after_recovery === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() ), 'Replay claims remain effective after pending-event recovery.' );
}

Event_Repository::schedule(); Event_Repository::schedule();
sw_mailgate_check( 'hourly' === wp_get_schedule( Event_Repository::CRON_HOOK ), 'Pending delivery reconciliation is scheduled idempotently.' );
if ( $enabled ) {
	$pending_id = 'pending-masked@example.test';
	$early_masked = $make( array( 'message' => array( 'headers' => array( 'message-id' => $pending_id ) ) ) );
	$controller->receive( $early_masked );
	// The same account signing key can be shared, but correlation still requires the connection/provider.
	$wrong_connection = $seed( '<' . $pending_id . '>', 'other' );
	sw_mailgate_check( 'sent' === $logs->find( $wrong_connection )['status'], 'Pending events never attach to a different connection with the same message ID.' );
	$other_provider = new \SWMailgate\Connections\Connection( 'mailgun', 'other provider', 'smtp', true, 'sender@example.test', '', true, true, array(), new Secret_Store() );
	$other_attempt = new \SWMailgate\Mail\Attempt( wp_generate_uuid4(), 1, $other_provider, \SWMailgate\Providers\Send_Result::success( '<' . $pending_id . '>' ), 'sender@example.test', array( 'user@example.test' ), 'Wrong provider' );
	( new \SWMailgate\Logging\Logger( $logs ) )->record( $other_attempt );
	$other_log = (int) $wpdb->insert_id;
	sw_mailgate_check( 'sent' === $logs->find( $other_log )['status'], 'Pending events never attach to another provider reusing a connection ID.' );
	Settings::save( array( 'mask_recipients' => true ) );
	$attempt = new \SWMailgate\Mail\Attempt( wp_generate_uuid4(), 1, $connection, \SWMailgate\Providers\Send_Result::success( '<' . $pending_id . '>' ), 'sender@example.test', array( 'user@example.test' ), 'Pending masked delivery' );
	( new \SWMailgate\Logging\Logger( $logs ) )->record( $attempt );
	$pending_log = (int) $wpdb->insert_id;
	sw_mailgate_check( 'delivered' === $logs->find( $pending_log )['status'] && ! str_contains( $logs->find( $pending_log )['provider_message_id'], 'pending-masked' ), 'Real Logger insertion reconciles early events even with masked identifiers.' );
	Settings::save( array() );


	// A separate connection commits a log while the webhook transaction is still invisible.
	$primary_db = $wpdb;
	$overlap_log = 0;
	$overlap_status = '';
	$before_commit = static function ( string $query ) use ( &$before_commit, $primary_db, $connection, &$overlap_log, &$overlap_status, $logs ): string {
		if ( 'COMMIT' !== $query ) { return $query; }
		remove_filter( 'query', $before_commit );
		$writer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$writer->set_prefix( $primary_db->prefix );
		$GLOBALS['wpdb'] = $writer;
		try {
			$attempt = new \SWMailgate\Mail\Attempt( wp_generate_uuid4(), 1, $connection, \SWMailgate\Providers\Send_Result::success( '<overlap@example.test>' ), 'sender@example.test', array( 'user@example.test' ), 'Overlapping log' );
			( new \SWMailgate\Logging\Logger( $logs ) )->record( $attempt );
			$overlap_log = (int) $writer->insert_id;
			$overlap_status = $logs->find( $overlap_log )['status'];
		} finally {
			$GLOBALS['wpdb'] = $primary_db;
			$writer->close();
		}
		return $query;
	};
	add_filter( 'query', $before_commit );
	$overlap_response = $controller->receive( $make( array( 'message' => array( 'headers' => array( 'message-id' => 'overlap@example.test' ) ) ) ) );
	sw_mailgate_check( 200 === $overlap_response->get_status() && 'sent' === $overlap_status && 'delivered' === $logs->find( $overlap_log )['status'], 'Post-commit reconciliation closes the race when a second database connection cannot yet see the webhook event.' );
	$transaction_id = 'transaction@example.test';
	$controller->receive( $make( array( 'message' => array( 'headers' => array( 'message-id' => $transaction_id ) ) ) ) );
	$wpdb->query( 'START TRANSACTION' );
	$transaction_log = $seed( '<' . $transaction_id . '>' );
	sw_mailgate_check( 'delivered' === $logs->find( $transaction_log )['status'], 'Pending events can correlate within a mail caller transaction.' );
	$wpdb->query( 'ROLLBACK' );
	sw_mailgate_check( null === $logs->find( $transaction_log ), 'Log correlation never commits a surrounding mail caller transaction.' );
	$committed_log = $seed( '<' . $transaction_id . '>' );
	sw_mailgate_check( 'delivered' === $logs->find( $committed_log )['status'], 'Rolled-back correlation remains pending until the log is committed later.' );
	$orphan = $make( array( 'message' => array( 'headers' => array( 'message-id' => 'never-logged@example.test' ) ) ) );
	$controller->receive( $orphan );
	$orphan_hash = Log_Repository::message_hash( 'never-logged@example.test' );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s WHERE provider_message_hash = %s', Event_Repository::table(), gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), $orphan_hash ) );
	Event_Repository::retain();
	sw_mailgate_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE provider_message_hash = %s', Event_Repository::table(), $orphan_hash ) ), 'Daily retention expires unmatched correlation metadata older than one day.' );
	$stored = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Event_Repository::table(), ARRAY_A ) );
	sw_mailgate_check( ! str_contains( $stored, '@' ) && ! str_contains( $stored, 'private-' ), 'Pending and correlated event storage contain no raw identifiers or credentials.' );
}
$server = rest_get_server();
$controller->register();
$response = $server->dispatch( $make( array() ) );
sw_mailgate_check( 200 === $response->get_status(), 'Real REST dispatch exposes the signed POST route without login.' );
$requests = array();
$mode = 'create';
$http = static function ( $pre, array $args, string $url ) use ( &$requests, &$mode ) {
	$requests[] = array( $url, $args );
	$status = 'GET' === $args['method'] ? ( 'existing' === $mode ? 200 : 404 ) : ( 'failure' === $mode ? 401 : 200 );
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'webhook' => array( 'urls' => array( Webhook_Controller::url( 'mailgun' ) ) ) ) ), 'response' => array( 'code' => $status ) );
};
add_filter( 'pre_http_request', $http, 10, 3 );
sw_mailgate_check( $mailgun->register_webhook( $connection, Webhook_Controller::url( 'mailgun' ) )->is_success() && 6 === count( $requests ), 'Explicit registration creates three regional event endpoints.' );
sw_mailgate_check( str_starts_with( $requests[0][0], 'https://api.eu.mailgun.net/v3/domains/mg.example.test/webhooks' ) && str_contains( $requests[1][1]['body'], 'delivered' ) && str_contains( $requests[3][1]['body'], 'permanent_fail' ), 'Registration uses regional Mailgun API and the permanent_fail webhook type.' );
$mode = 'existing'; $requests = array();
sw_mailgate_check( $mailgun->register_webhook( $connection, Webhook_Controller::url( 'mailgun' ) )->is_success() && 3 === count( $requests ), 'Repeated registration reads existing endpoints without replacing them.' );
$mode = 'failure';
sw_mailgate_check( ! $mailgun->register_webhook( $connection, Webhook_Controller::url( 'mailgun' ) )->is_success() && ! $mailgun->register_webhook( $connection, 'http://example.test' )->is_success(), 'API failures and insecure webhook URLs are reported.' );
$mode = 'existing';
$admin = new Webhook_Admin( $connections, $registry );
$admin->menu();
$_POST = array( 'connection' => 'mailgun' ); $_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_webhooks' );
ob_start(); $admin->render(); $html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'Webhooks registered.' ) && ! str_contains( $html, 'private-' ), 'Authorized registration renders a safe result.' );
remove_filter( 'pre_http_request', $http, 10 );
$page = new Admin_Page( $connections, $registry, new Secret_Store(), new Dispatcher( $connections, $registry ), new Redirector() );
foreach ( array( 'mailgun', 'smtp' ) as $target ) {
	$_GET = array( 'view' => 'edit', 'connection' => $target );
	ob_start(); $page->render_connections(); $html = ob_get_clean();
	sw_mailgate_check( ( 'mailgun' === $target ) === str_contains( $html, 'Create webhooks at Mailgun' ) && ! str_contains( $html, 'private-signing-key' ), 'Webhook controls appear only for capable providers and never reveal the signing key.' );
}
if ( $enabled ) {
	$bounce = $seed( '<bounce@example.test>' );
	$controller->receive( $make( array( 'event' => 'failed', 'severity' => 'permanent', 'message' => array( 'headers' => array( 'message-id' => 'bounce@example.test' ) ) ) ) );
	sw_mailgate_check( 1 === $logs->totals( 7 )['bounced'], 'Statistics count bounced messages once.' );
	$log_page = new Log_Page( $logs, $connections );
	ob_start(); $log_page->widget(); $html = ob_get_clean();
	sw_mailgate_check( str_contains( $html, 'Message bounce rate:' ) && str_contains( $html, 'complained' ), 'Dashboard displays message bounce rate and tracking limitations.' );
	$_GET = array( 'status' => 'bounced' );
	sw_mailgate_check( 'bounced' === $log_page->filters()['status'], 'Delivery event statuses can be filtered.' );
	$logs->delete( $id );
	sw_mailgate_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE log_id = %d', Event_Repository::table(), $id ) ), 'Privacy deletion removes associated delivery events.' );
}
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', Event_Repository::table(), '2000-01-01 00:00:00' ) );
Event_Repository::retain();
sw_mailgate_check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Event_Repository::table() ), 'Retention deletes expired replay and event metadata.' );
// Force replay-storage failure: fail closed and do not mutate delivery records.
$wpdb->query( 'RENAME TABLE ' . Event_Repository::table() . ' TO ' . Event_Repository::table() . '_fixture' );
$previous = $wpdb->suppress_errors( true );
try { sw_mailgate_check( 503 === $controller->receive( $make( array() ) )->get_status(), 'Storage errors fail closed and ask Mailgun to retry.' ); }
finally { $wpdb->query( 'RENAME TABLE ' . Event_Repository::table() . '_fixture TO ' . Event_Repository::table() ); $wpdb->suppress_errors( $previous ); }
wp_set_current_user( 0 );
add_filter( 'wp_die_handler', static fn() => static function (): void { throw new RuntimeException( 'denied' ); } );
try { $admin->render(); throw new LogicException( 'Unauthorized registration accepted.' ); } catch ( RuntimeException $error ) { sw_mailgate_check( 'denied' === $error->getMessage(), 'Webhook registration requires management capability.' ); }
