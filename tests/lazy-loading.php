<?php
/**
 * Fresh-process mailer lifecycle regressions. Run after integration.php installed the disposable database.
 * Usage: php tests/lazy-loading.php idle|short-true|short-false|send|foreign|smtp|retry
 *
 * @package SWMailgate
 */
declare(strict_types=1);

use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Providers\Provider;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Routing\Retry_Queue;
use SWMailgate\Security\Secret_Store;

$complete = false;
register_shutdown_function( static function () use ( &$complete ): void {
	if ( ! $complete ) {
		fwrite( STDERR, 'FAIL: Lifecycle scenario did not complete.' . PHP_EOL );
		exit( 1 );
	}
} );
$mode = $argv[1] ?? 'idle';
if ( ! in_array( $mode, array( 'idle', 'short-true', 'short-false', 'send', 'foreign', 'smtp', 'retry' ), true ) ) {
	throw new InvalidArgumentException( 'Unknown lifecycle scenario.' );
}
define( 'ABSPATH', rtrim( getenv( 'WP_ROOT' ) ?: '/tmp/wordpress', '/' ) . '/' );
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'mailgate_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true );
define( 'WP_DEBUG', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'DISABLE_WP_CRON', true );
define( 'SW_MAILGATE_REDIRECT_TO', '' );
$_SERVER['HTTP_HOST'] = 'mailgate.test';
$_SERVER['REQUEST_URI'] = '/';
$table_prefix = 'swmg_test_';
// Register before the real lifecycle: no manual install_mailer() call may mask a regression.
require_once ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_option_active_plugins', static fn() => array() );
add_action( 'muplugins_loaded', static function (): void {
	require ( getenv( 'SW_MAILGATE_TEST_PLUGIN' ) ?: dirname( __DIR__ ) ) . '/sw-mailgate.php';
} );
require ABSPATH . 'wp-settings.php';

function sw_mailgate_lifecycle_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}
sw_mailgate_lifecycle_check( ! class_exists( 'WP_PHPMailer', false ) && ! class_exists( 'PHPMailer\\PHPMailer\\PHPMailer', false ), 'Idle bootstrap leaves PHPMailer unloaded.' );
sw_mailgate_lifecycle_check( ! isset( $GLOBALS['phpmailer'] ), 'Idle bootstrap does not replace the global mailer.' );
if ( 'idle' === $mode ) {
	$complete = true;
	exit;
}
if ( str_starts_with( $mode, 'short-' ) ) {
	$expected = 'short-true' === $mode;
	add_filter( 'pre_wp_mail', static fn() => $expected );
	sw_mailgate_lifecycle_check( $expected === wp_mail( 'recipient@example.test', 'Short circuit', 'Body' ), 'Short-circuit return value is preserved.' );
	sw_mailgate_lifecycle_check( ! class_exists( 'WP_PHPMailer', false ) && ! isset( $GLOBALS['phpmailer'] ), 'Short-circuited mail leaves PHPMailer unloaded.' );
	$complete = true;
	exit;
}
$secrets = new Secret_Store();
if ( 'smtp' === $mode ) {
	// Reserve a local port, then close it: the probe must fail safely, without any external connection.
	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
	if ( false === $socket ) {
		throw new RuntimeException( $error );
	}
	$port = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
	fclose( $socket );
	$connection = new Connection( 'lazy-probe', 'Local fixture', 'smtp', true, 'sender@example.test', '', false, false, array( 'host' => '127.0.0.1', 'port' => (string) $port, 'encryption' => 'none' ), $secrets );
	$result = ( new Smtp_Provider() )->test_connection( $connection );
	sw_mailgate_lifecycle_check( ! $result->is_success(), 'A direct connection test reports connection failure without an unloaded-parent fatal.' );
	sw_mailgate_lifecycle_check( class_exists( 'WP_PHPMailer', false ) && ! isset( $GLOBALS['phpmailer'] ), 'Connection test loads dependencies without installing a global catcher.' );
	$complete = true;
	exit;
}
$provider = new class() implements Provider {
	public array $messages = array();
	public function id(): string { return 'lazy-fixture'; }
	public function label(): string { return 'Lifecycle fixture'; }
	public function fields(): array { return array(); }
	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {}
	public function test_connection( Connection $connection ): Send_Result { return Send_Result::success(); }
	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$this->messages[] = array( 'subject' => $envelope->subject(), 'to' => $envelope->to() );
		return Send_Result::success();
	}
};
add_filter( 'sw_mailgate_providers', static fn( array $providers ): array => array_merge( $providers, array( $provider ) ) );
$previous_connections = get_option( Connection_Repository::OPTION, array() );
$previous_failover = get_option( \SWMailgate\Routing\Failover_Settings::OPTION, array() );
delete_option( Connection_Repository::OPTION );
update_option( \SWMailgate\Routing\Failover_Settings::OPTION, array( 'breaker_threshold' => 0 ) );
( new Connection_Repository( $secrets ) )->save( array( 'id' => 'lazy-fixture', 'provider' => 'lazy-fixture', 'enabled' => true, 'from_email' => 'sender@example.test' ) );
try {
	if ( 'retry' === $mode ) {
		$payload = array( 'to' => array( array( 'recipient@example.test', '' ) ), 'cc' => array(), 'bcc' => array(), 'reply_to' => array(), 'from' => 'sender@example.test', 'from_name' => '', 'sender' => '', 'subject' => 'Queued lifecycle fixture', 'body' => base64_encode( 'Body' ), 'alt_body' => '', 'content_type' => 'text/plain', 'charset' => 'UTF-8', 'encoding' => '8bit', 'headers' => array(), 'attachments' => array(), 'original_to' => array(), 'caller' => 'lifecycle fixture', 'substituted_from' => null );
		$wpdb->insert( Retry_Queue::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'retries' => 0, 'attempt_group' => wp_generate_uuid4(), 'attempt_number' => 1, 'last_error' => '', 'address_hashes' => '[]', 'payload' => $secrets->seal( wp_json_encode( $payload ) ) ) );
		$id = (int) $wpdb->insert_id;
		sw_mailgate_lifecycle_check( $id > 0, 'A due retry fixture was created.' );
		$wp_mail_calls = 0;
		add_filter( 'wp_mail', static function ( $atts ) use ( &$wp_mail_calls ) { ++$wp_mail_calls; return $atts; } );
		try {
			do_action( Retry_Queue::CRON_HOOK );
			sw_mailgate_lifecycle_check( 1 === count( $provider->messages ) && 'Queued lifecycle fixture' === $provider->messages[0]['subject'], 'Fresh retry worker delivers the queued message without a preceding wp_mail().' );
			sw_mailgate_lifecycle_check( 0 === $wp_mail_calls && ! isset( $GLOBALS['phpmailer'] ), 'Retry does not replay wp_mail filters or install a global catcher.' );
			sw_mailgate_lifecycle_check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', Retry_Queue::table(), $id ) ), 'Successful retry removes its queue row.' );
		} finally {
			$wpdb->delete( Retry_Queue::table(), array( 'id' => $id ) );
		}
	} else {
		if ( 'foreign' === $mode ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
			add_filter( 'pre_wp_mail', static function ( $return ) { $GLOBALS['phpmailer'] = new WP_PHPMailer( true ); return $return; }, 100 );
		}
		sw_mailgate_lifecycle_check( wp_mail( 'recipient@example.test', 'First', 'Body' ), 'First send initializes the catcher automatically.' );
		$catcher = $GLOBALS['phpmailer'];
		sw_mailgate_lifecycle_check( $catcher instanceof Mail_Catcher && 1 === count( $provider->messages ), 'Mailgate catches the send, including replacement of a lower-priority foreign mailer.' );
		if ( 'foreign' !== $mode ) {
			sw_mailgate_lifecycle_check( wp_mail( 'second@example.test', 'Second', 'Body' ), 'Second send succeeds.' );
			sw_mailgate_lifecycle_check( $catcher === $GLOBALS['phpmailer'] && 2 === count( $provider->messages ) && array( 'second@example.test' ) === $provider->messages[1]['to'], 'Repeated sends reuse the catcher without leaking recipients.' );
		}
	}
} finally {
	update_option( Connection_Repository::OPTION, $previous_connections );
	update_option( \SWMailgate\Routing\Failover_Settings::OPTION, $previous_failover );
}

$complete = true;
