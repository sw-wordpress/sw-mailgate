<?php
/**
 * Issue #8: send and connection-test diagnostics without mbstring.
 * Usage: WP_ROOT=/tmp/wordpress php tests/smtp-diagnostics.php
 * Only connects to a disposable SMTP fixture on loopback.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

if ( '--server' === ( $argv[1] ?? '' ) ) {
	$server = stream_socket_server( 'tcp://127.0.0.1:0' );
	echo stream_socket_get_name( $server, false ) . PHP_EOL;
	flush();
	for ( $connection = 0; $connection < 2; ++$connection ) {
		$client = stream_socket_accept( $server, 10 );
		if ( false === $client ) { exit( 1 ); }
		stream_set_timeout( $client, 5 );
		fwrite( $client, "220 Grüße 東京\r\n" );
		$data = false;
		$auth = 0;
		while ( false !== ( $line = fgets( $client ) ) ) {
			$command = trim( $line );
			if ( $data ) {
				if ( '.' !== $command ) { continue; }
				$data = false;
				fwrite( $client, "250 accepted\r\n" );
			} elseif ( $auth ) {
				fwrite( $client, 1 === $auth++ ? "334 UGFzc3dvcmQ6\r\n" : "235 authenticated\r\n" );
				if ( 3 === $auth ) { $auth = 0; }
			} elseif ( str_starts_with( $command, 'EHLO' ) ) {
				fwrite( $client, "250-\xC3\x28 invalid UTF-8\r\n250 AUTH LOGIN\r\n" );
			} elseif ( 'AUTH LOGIN' === $command ) {
				$auth = 1;
				fwrite( $client, "334 control\x01data\r\n" );
			} elseif ( 'DATA' === $command ) {
				$data = true;
				fwrite( $client, "354 send content\r\n" );
			} elseif ( 'QUIT' === $command ) {
				fwrite( $client, "221 goodbye\r\n" );
				break;
			} else {
				fwrite( $client, "250 OK\r\n" );
			}
		}
		fclose( $client );
	}
	fclose( $server );
	exit;
}

function __( string $message, string $domain = '' ): string { return $message; }
function get_option( string $key, mixed $default = false ): mixed { return $default; }
function sw_mailgate_diagnostic_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

sw_mailgate_diagnostic_check( ! function_exists( 'mb_check_encoding' ), 'mb_check_encoding is unavailable in this test process.' );
$wp_root = rtrim( getenv( 'WP_ROOT' ) ?: '/tmp/wordpress', '/' );
require $wp_root . '/wp-includes/PHPMailer/Exception.php';
require $wp_root . '/wp-includes/PHPMailer/PHPMailer.php';
require $wp_root . '/wp-includes/PHPMailer/SMTP.php';
require $wp_root . '/wp-includes/class-wp-phpmailer.php';
require dirname( __DIR__ ) . '/vendor/autoload.php';

$process = proc_open( array( PHP_BINARY, __FILE__, '--server' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
try {
	stream_set_timeout( $pipes[1], 10 );
	$address = trim( (string) fgets( $pipes[1] ) );
	if ( ! preg_match( '/^127\.0\.0\.1:(\d+)$/D', $address, $match ) ) { throw new RuntimeException( 'SMTP fixture failed to start.' ); }
	$connection = new \SWMailgate\Connections\Connection( 'diagnostics', 'Diagnostics', 'smtp', true, 'sender@example.test', '', true, false,
		array( 'host' => '127.0.0.1', 'port' => $match[1], 'encryption' => 'none', 'auth' => '1', 'username' => 'fixture-user', 'password' => 'fixture-password' ),
		new \SWMailgate\Security\Secret_Store() );
	$provider = new \SWMailgate\Providers\Smtp_Provider();
	$mailer = new \SWMailgate\Mail\Mail_Catcher( true );
	$provider->prepare( $mailer, $connection );
	$mailer->setFrom( 'sender@example.test' );
	$mailer->addAddress( 'recipient@example.test' );
	$mailer->Subject = 'Diagnostic fixture';
	$mailer->Body = 'private-message-body';
	$mailer->preSend();
	$results = array(
		'send' => $provider->send( new \SWMailgate\Mail\Envelope( $mailer ), $connection ),
		'connection test' => $provider->test_connection( $connection ),
	);
	foreach ( $results as $label => $result ) {
		sw_mailgate_diagnostic_check( $result->is_success(), $label . ' succeeds without mbstring.' );
		$debug = implode( "\n", $result->debug() );
		sw_mailgate_diagnostic_check( str_contains( $debug, 'Grüße 東京' ), $label . ' retains valid UTF-8.' );
		sw_mailgate_diagnostic_check( substr_count( $debug, '[binary data omitted]' ) >= 2 && ! str_contains( $debug, "\xC3\x28" ) && ! str_contains( $debug, "\x01" ) && 1 === preg_match( '//u', $debug ), $label . ' safely omits invalid UTF-8 and control characters.' );
		sw_mailgate_diagnostic_check( ! str_contains( $debug, 'fixture-password' ) && ! str_contains( $debug, base64_encode( 'fixture-password' ) ) && ! str_contains( $debug, 'private-message-body' ), $label . ' keeps credentials and message content out of diagnostics.' );
	}
	foreach ( $pipes as $pipe ) { fclose( $pipe ); }
	sw_mailgate_diagnostic_check( 0 === proc_close( $process ), 'SMTP fixture completed both sessions.' );
	$process = null;
} finally {
	if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
}
