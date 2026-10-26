<?php
/**
 * Issue #10: opaque secret bytes through the real form, encryption and retrieval.
 * Included by integration.php enabled issues secrets.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Security\Secret_Store;

delete_option( Connection_Repository::OPTION );
$secrets = new Secret_Store();
$smtp = new Smtp_Provider();
$mailgun = new Mailgun_Provider();
$registry = new Provider_Registry( array( $smtp, $mailgun ) );
$admin = new Admin_Page( $connections, $registry, $secrets, new Dispatcher( $connections, $registry ), new Redirector() );
$connections->save( array( 'id' => 'opaque', 'provider' => 'smtp', 'from_email' => 'sender@example.test', 'settings' => array( 'host' => 'smtp.example.test' ) ), array( 'password' => 'old-fixture' ) );

function sw_mailgate_save_secret_form( Admin_Page $admin, string $id, array $secrets, array $clear = array() ): void {
	// Real WordPress requests are slashed before the handler calls wp_unslash().
	$_POST = wp_slash( array( 'connection' => $id, 'label' => 'Opaque fixture', 'enabled' => '1', 'from_email' => 'sender@example.test',
		'settings' => array( 'host' => 'smtp.example.test', 'domain' => 'mg.example.test', 'region' => 'us' ), 'secrets' => $secrets, 'clear_secrets' => $clear ) );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_save_connection' );
	$redirected = false;
	$stop_redirect = static function ( string $url ) use ( &$redirected ): never {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		sw_mailgate_check( 'saved' === ( $query['swmg_notice'] ?? '' ), 'Form save reaches its success redirect.' );
		$redirected = true;
		throw new RuntimeException( 'secret-form-redirect' );
	};
	add_filter( 'wp_redirect', $stop_redirect );
	try { $admin->handle_save_connection(); }
	catch ( RuntimeException $error ) {
		if ( 'secret-form-redirect' !== $error->getMessage() ) { throw $error; }
	} finally { remove_filter( 'wp_redirect', $stop_redirect ); }
	sw_mailgate_check( $redirected, 'Actual admin save handler completed.' );
}

$values = array(
	'surrounding spaces' => ' secret ',
	'surrounding tabs' => "\tsecret\t",
	'whitespace only' => " \t  ",
	'quotes and backslashes' => " 'quoted\"\\secret\\\\ ",
	'line breaks and opaque bytes' => "\r\nsecret\x00\r\n",
);
foreach ( $values as $label => $value ) {
	sw_mailgate_save_secret_form( $admin, 'opaque', array( 'password' => $value ) );
	$name = Connection::secret_name( 'opaque', 'password' );
	$ciphertext = get_option( Secret_Store::OPTION )[ $name ];
	sw_mailgate_check( $ciphertext !== $value && str_starts_with( $ciphertext, 'v1:' ), $label . ' is stored encrypted.' );
	sw_mailgate_check( $value === ( new Secret_Store() )->get( $name ) && $value === $connections->find( 'opaque' )->get( 'password' ), $label . ' survives form unslashing, encryption and retrieval byte for byte.' );
	$mailer = new Mail_Catcher( true );
	$smtp->prepare( $mailer, $connections->find( 'opaque' ) );
	sw_mailgate_check( $value === $mailer->Password, $label . ' reaches the SMTP transport unchanged.' );
}
$before = get_option( Secret_Store::OPTION );
sw_mailgate_save_secret_form( $admin, 'opaque', array( 'password' => '' ) );
sw_mailgate_check( $before === get_option( Secret_Store::OPTION ), 'Explicit empty input retains the existing ciphertext.' );
sw_mailgate_save_secret_form( $admin, 'opaque', array() );
sw_mailgate_check( $before === get_option( Secret_Store::OPTION ), 'An omitted password also retains the existing secret.' );
sw_mailgate_save_secret_form( $admin, 'opaque', array( 'password' => '' ), array( 'password' => '1' ) );
sw_mailgate_check( ! $connections->find( 'opaque' )->has_secret( 'password' ), 'Explicit deletion removes the encrypted password.' );
sw_mailgate_save_secret_form( $admin, 'opaque', array( 'password' => " \t " ), array( 'password' => '1' ) );
sw_mailgate_check( " \t " === $connections->find( 'opaque' )->get( 'password' ), 'A nonempty whitespace-only replacement takes precedence over deletion.' );

// Provider-specific invalid values are rejected, never silently rewritten into a valid key.
$connections->save( array( 'id' => 'opaque_api', 'provider' => 'mailgun', 'from_email' => 'sender@example.test', 'settings' => array( 'domain' => 'mg.example.test', 'region' => 'us' ) ) );
$requests = 0;
$http = static function () use ( &$requests ): WP_Error { ++$requests; return new WP_Error( 'fixture', 'No external requests allowed.' ); };
add_filter( 'pre_http_request', $http );
try {
	foreach ( array( ' key-invalid ', "\tkey-invalid\t", "key\r\ninvalid" ) as $value ) {
		sw_mailgate_save_secret_form( $admin, 'opaque_api', array( 'api_key' => $value ) );
		$before = get_option( Secret_Store::OPTION );
		$result = $mailgun->test_connection( $connections->find( 'opaque_api' ) );
		sw_mailgate_check( Send_Result::AUTH === $result->error_class() && str_contains( $result->message(), 'API key' ) && 0 === $requests, 'Mailgun reports an invalid key without making an HTTP request.' );
		sw_mailgate_check( $before === get_option( Secret_Store::OPTION ) && $value === $connections->find( 'opaque_api' )->get( 'api_key' ), 'Provider validation leaves the exact stored bytes unchanged.' );
	}
} finally { remove_filter( 'pre_http_request', $http ); }

update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
echo 'PASS: Secret byte preservation regressions completed.' . PHP_EOL;
