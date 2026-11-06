<?php
/**
 * Issue #9: inherited SMTPOptions must obey the selected TLS verification policy.
 * Usage: WP_ROOT=/tmp/wordpress php tests/smtp-tls-policy.php
 * Real STARTTLS and implicit TLS handshakes use a disposable loopback server.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

if ( '--server' === ( $argv[1] ?? '' ) ) {
	$context = stream_context_create( array( 'ssl' => array( 'local_cert' => $argv[2], 'local_pk' => $argv[3] ) ) );
	$server = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context );
	echo stream_socket_get_name( $server, false ) . PHP_EOL;
	flush();
	$client = stream_socket_accept( $server, 10 );
	if ( false === $client ) { exit( 1 ); }
	stream_set_timeout( $client, 5 );
	$tls_ready = 'ssl' === $argv[4];
	if ( 'ssl' === $argv[4] && ! @stream_socket_enable_crypto( $client, true, STREAM_CRYPTO_METHOD_TLS_SERVER ) ) { exit; }
	// A client rejecting this certificate can close after the server handshake succeeds.
	// Treat its disconnect as normal fixture I/O, including TLS alert/broken-pipe warnings.
	if ( false === @fwrite( $client, "220 localhost fixture\r\n" ) ) { exit; }
	while ( false !== ( $line = @fgets( $client ) ) ) {
		$command = trim( $line );
		if ( 'STARTTLS' === $command ) {
			if ( false === @fwrite( $client, "220 Ready for TLS\r\n" ) ) { break; }
			if ( ! @stream_socket_enable_crypto( $client, true, STREAM_CRYPTO_METHOD_TLS_SERVER ) ) { break; }
			$tls_ready = true;
		} elseif ( str_starts_with( $command, 'AUTH ' ) ) {
			$expected = 'AUTH XOAUTH2 ' . base64_encode( "user=login@example.test\x01auth=Bearer local-fixture-token\x01\x01" );
			if ( ! $tls_ready || $command !== $expected ) { fwrite( STDERR, 'Unexpected or unencrypted authentication.' ); exit( 1 ); }
			echo 'AUTH_RECEIVED' . PHP_EOL;
			@fwrite( $client, "235 authenticated\r\n" );
		} elseif ( 'QUIT' === $command ) {
			@fwrite( $client, "221 goodbye\r\n" );
			break;
		} else {
			if ( false === @fwrite( $client, "250-localhost\r\n250-STARTTLS\r\n250 AUTH XOAUTH2 LOGIN PLAIN\r\n" ) ) { break; }
		}
	}
	fclose( $client );
	fclose( $server );
	exit;
}

function __( string $message, string $domain = '' ): string { return $message; }
function get_option( string $key, mixed $default = false ): mixed { return $default; }
function sw_mailgate_tls_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

$wp_root = rtrim( getenv( 'WP_ROOT' ) ?: '/tmp/wordpress', '/' );
define( 'ABSPATH', $wp_root . '/' );
define( 'WPINC', 'wp-includes' );
require $wp_root . '/wp-includes/PHPMailer/Exception.php';
require $wp_root . '/wp-includes/PHPMailer/PHPMailer.php';
require $wp_root . '/wp-includes/PHPMailer/SMTP.php';
require $wp_root . '/wp-includes/class-wp-phpmailer.php';
require dirname( __DIR__ ) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/sw-mailgate-tls-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$certificate = $directory . '/certificate.pem';
$key_file = $directory . '/key.pem';
$config_file = $directory . '/openssl.cnf';
file_put_contents( $config_file, "[req]\ndistinguished_name=dn\nx509_extensions=extensions\n[dn]\n[extensions]\nsubjectAltName=DNS:localhost,DNS:smtp.gmail.com\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n" );
$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
$csr = openssl_csr_new( array( 'commonName' => 'localhost' ), $key, array( 'config' => $config_file, 'digest_alg' => 'sha256' ) );
$cert = openssl_csr_sign( $csr, null, $key, 1, array( 'config' => $config_file, 'x509_extensions' => 'extensions', 'digest_alg' => 'sha256' ) );
openssl_x509_export_to_file( $cert, $certificate );
openssl_pkey_export_to_file( $key, $key_file );
$provider = new \SWMailgate\Providers\Smtp_Provider();
$cases = array(
	'untrusted certificate' => array( 'verify' => '1', 'peer' => 'localhost', 'trust' => false, 'success' => false ),
	'host mismatch and inherited peer name' => array( 'verify' => '1', 'peer' => '', 'trust' => true, 'success' => false ),
	'configured peer name and trust store' => array( 'verify' => '1', 'peer' => 'localhost', 'trust' => true, 'success' => true ),
	'explicit verification opt-out' => array( 'verify' => '0', 'peer' => 'wrong.example.test', 'trust' => false, 'success' => true ),
);
try {
	foreach ( array( 'ssl', 'tls' ) as $encryption ) {
		foreach ( $cases as $label => $case ) {
			$process = proc_open( array( PHP_BINARY, __FILE__, '--server', $certificate, $key_file, $encryption ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			try {
				stream_set_timeout( $pipes[1], 10 );
				$address = trim( (string) fgets( $pipes[1] ) );
				if ( ! preg_match( '/^127\.0\.0\.1:(\d+)$/D', $address, $match ) ) { throw new RuntimeException( 'TLS fixture failed to start.' ); }
				$connection = new \SWMailgate\Connections\Connection( 'tls', 'TLS', 'smtp', true, 'sender@example.test', '', true, false,
					array( 'host' => '127.0.0.1', 'port' => $match[1], 'encryption' => $encryption, 'auth' => '0', 'tls_verify' => $case['verify'], 'tls_peer_name' => $case['peer'] ),
					new \SWMailgate\Security\Secret_Store() );
				$template = new \SWMailgate\Mail\Mail_Catcher( true );
				$template->SMTPOptions = array( 'ssl' => array( 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'peer_name' => 'localhost', 'ciphers' => 'DEFAULT' ), 'socket' => array( 'tcp_nodelay' => true ) );
				if ( $case['trust'] ) { $template->SMTPOptions['ssl']['cafile'] = $certificate; }
				$mailer = clone $template;
				$provider->prepare( $mailer, $connection );
				$ssl = $mailer->SMTPOptions['ssl'];
				$verify = '0' !== $case['verify'];
				sw_mailgate_tls_check( $ssl['verify_peer'] === $verify && $ssl['verify_peer_name'] === $verify && $ssl['allow_self_signed'] === ! $verify, $encryption . ': connection policy replaces inherited verification flags (' . $label . ').' );
				sw_mailgate_tls_check( ( $ssl['peer_name'] ?? '' ) === $case['peer'] && 'DEFAULT' === $ssl['ciphers'] && $mailer->SMTPOptions['socket']['tcp_nodelay'] && ( ! $case['trust'] || $ssl['cafile'] === $certificate ), $encryption . ': peer name is deliberate and compatible context/trust options are retained.' );
				$connected = false;
				try { $connected = $mailer->smtpConnect(); }
				catch ( \PHPMailer\PHPMailer\Exception $error ) {}
				finally { $mailer->smtpClose(); }
				sw_mailgate_tls_check( $connected === $case['success'], $encryption . ': actual handshake enforces ' . $label . '.' );
				$error = stream_get_contents( $pipes[2] );
				foreach ( $pipes as $pipe ) { fclose( $pipe ); }
				sw_mailgate_tls_check( 0 === proc_close( $process ) && '' === $error, 'TLS fixture completed without errors. ' . $error );
				$process = null;
			} finally {
				if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
			}
		}
	}

	// Route only socket I/O to loopback, keeping the real provider's TLS identity check.
	\SWMailgate\Mail\Mailer_Factory::load_oauth();
	foreach ( array( array( true, true, true ), array( true, false, false ), array( false, true, false ) ) as [ $google, $trusted, $expected_success ] ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, '--server', $certificate, $key_file, 'tls' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		try {
			stream_set_timeout( $pipes[1], 10 );
			$address = trim( (string) fgets( $pipes[1] ) );
			if ( ! preg_match( '/^127\.0\.0\.1:(\d+)$/D', $address, $match ) ) { throw new RuntimeException( 'OAuth TLS fixture failed to start.' ); }
			$connection = new \SWMailgate\Connections\Connection( 'oauth-tls', 'OAuth TLS', $google ? 'google_smtp' : 'office365_smtp', true, 'sender@example.test', '', true, false, array( 'username' => 'login@example.test', 'tls_verify' => '0', 'encryption' => 'none' ), new \SWMailgate\Security\Secret_Store() );
			$mailer = \SWMailgate\Mail\Mailer_Factory::create();
			( new \SWMailgate\Providers\Smtp_OAuth_Provider( $google ) )->prepare( $mailer, $connection );
			if ( $trusted ) { $mailer->SMTPOptions['ssl']['cafile'] = $certificate; }
			$mailer->setSMTPInstance( new class( (int) $match[1] ) extends \SWMailgate\Providers\OAuth_SMTP {
				public function __construct( private int $fixture_port ) {}
				public function connect( $host, $port = null, $timeout = 30, $options = array() ) {
					$options['ssl']['peer_name'] = $host;
					return parent::connect( '127.0.0.1', $this->fixture_port, $timeout, $options );
				}
			} );
			$mailer->setOAuth( new \SWMailgate\OAuth\SMTP_Token_Provider( 'login@example.test', 'local-fixture-token' ) );
			$result = $provider->test_prepared_connection( $mailer, $connection );
			sw_mailgate_tls_check( $expected_success === $result->is_success(), 'Real OAuth SMTP TLS rejects untrusted certificates and mismatched provider names.' );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			foreach ( $pipes as $pipe ) { fclose( $pipe ); }
			sw_mailgate_tls_check( 0 === proc_close( $process ) && '' === $error && $output === ( $expected_success ? 'AUTH_RECEIVED' . PHP_EOL : '' ), 'Real SMTP sends XOAUTH2 only after a verified TLS handshake; failed verification sends no bearer credential.' );
			$process = null;
		} finally {
			if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
		}
	}
} finally {
	foreach ( array( $certificate, $key_file, $config_file ) as $file ) { unlink( $file ); }
	rmdir( $directory );
}
