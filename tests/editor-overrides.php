<?php
/**
 * Issue #13: effective wp-config values, checkbox policies and secret confidentiality.
 * Included by integration.php enabled issues [editor|editor-false].
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Security\Secret_Store;

function sw_mailgate_editor_node( string $html, string $name ): DOMElement {
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$node = ( new DOMXPath( $document ) )->query( "//*[@name='$name']" )->item( 0 );
	if ( ! $node instanceof DOMElement ) { throw new RuntimeException( 'Missing field: ' . $name ); }
	return $node;
}

delete_option( Connection_Repository::OPTION );
$saved_smtp = array( 'host' => 'stored.example.test', 'port' => '25', 'encryption' => 'tls', 'auth' => '0', 'tls_verify' => '1', 'tls_peer_name' => '', 'username' => 'stored-user', 'timeout' => '15' );
$connections->save( array( 'id' => 'primary', 'label' => 'Primary', 'provider' => 'smtp', 'from_email' => 'sender@example.test', 'settings' => $saved_smtp ), array( 'password' => 'private-stored-password-fixture' ) );
$saved_mailgun = array( 'domain' => 'mg.example.test', 'region' => 'us', 'timeout' => '15' );
$connections->save( array( 'id' => 'mailgun', 'label' => 'Mailgun', 'provider' => 'mailgun', 'from_email' => 'sender@example.test', 'settings' => $saved_mailgun ), array( 'api_key' => 'private-stored-api-fixture' ) );
$secrets = new Secret_Store();
$registry = new Provider_Registry( array( new Smtp_Provider(), new Mailgun_Provider() ) );
$admin = new Admin_Page( $connections, $registry, $secrets, new Dispatcher( $connections, $registry ), new Redirector() );
foreach ( array( 'primary', 'mailgun' ) as $id ) {
	$_GET = array( 'view' => 'edit', 'connection' => $id );
	ob_start();
	$admin->render_connections();
	$html = ob_get_clean();
	foreach ( array( 'private-stored-password-fixture', 'private-config-password-fixture', 'private-stored-api-fixture', 'private-config-api-fixture' ) as $secret ) {
		sw_mailgate_check( ! str_contains( $html, $secret ), 'Secret values are absent from editor HTML (' . $id . ').' );
	}
	if ( 'primary' === $id ) {
		$mailer = new Mail_Catcher( true );
		( new Smtp_Provider() )->prepare( $mailer, $connections->find( 'primary' ) );
		sw_mailgate_check( $mailer->Host === sw_mailgate_editor_node( $html, 'settings[host]' )->getAttribute( 'value' ) && sw_mailgate_editor_node( $html, 'settings[host]' )->hasAttribute( 'disabled' ), 'Locked host displays the effective runtime value and remains disabled.' );
		sw_mailgate_check( (string) $mailer->Port === sw_mailgate_editor_node( $html, 'settings[port]' )->getAttribute( 'value' ), 'Locked numeric fields display the effective value.' );
		$select = sw_mailgate_editor_node( $html, 'settings[encryption]' );
		$selected = array_values( array_filter( iterator_to_array( $select->getElementsByTagName( 'option' ) ), static fn( $option ): bool => $option->hasAttribute( 'selected' ) ) );
		sw_mailgate_check( $mailer->SMTPSecure === $selected[0]->getAttribute( 'value' ) && $select->hasAttribute( 'disabled' ), 'Locked SMTP select displays the effective encryption mode.' );
		sw_mailgate_check( $mailer->SMTPAuth === sw_mailgate_editor_node( $html, 'settings[auth]' )->hasAttribute( 'checked' ), 'Authentication checkbox follows runtime boolean interpretation.' );
		sw_mailgate_check( ( $mailer->SMTPOptions['ssl']['verify_peer'] ?? true ) === sw_mailgate_editor_node( $html, 'settings[tls_verify]' )->hasAttribute( 'checked' ), 'TLS checkbox follows the existing runtime verification policy, including false/zero overrides.' );
		$input = sw_mailgate_editor_node( $html, 'secrets[password]' );
	} else {
		$select = sw_mailgate_editor_node( $html, 'settings[region]' );
		$selected = array_values( array_filter( iterator_to_array( $select->getElementsByTagName( 'option' ) ), static fn( $option ): bool => $option->hasAttribute( 'selected' ) ) );
		sw_mailgate_check( 'eu' === $selected[0]->getAttribute( 'value' ) && $select->hasAttribute( 'disabled' ), 'Locked Mailgun region displays the effective EU override instead of stored US.' );
		$input = sw_mailgate_editor_node( $html, 'secrets[api_key]' );
	}
	sw_mailgate_check( '' === $input->getAttribute( 'value' ) && $input->hasAttribute( 'disabled' ), 'Locked credentials stay blank and disabled.' );
	$original_settings = $connections->find( $id )->settings();
	$original_secrets = get_option( Secret_Store::OPTION );
	$_POST = array( 'connection' => $id, 'label' => 'Renamed', 'enabled' => '1', 'from_email' => 'sender@example.test', 'settings' => array( 'host' => 'forged.example.test', 'port' => '999', 'region' => 'us', 'encryption' => 'none', 'auth' => '0', 'tls_verify' => '1', 'domain' => 'mg.example.test', 'timeout' => '15', 'username' => 'stored-user' ), 'secrets' => array( 'password' => 'forged-secret', 'api_key' => 'forged-secret' ), 'clear_secrets' => array( 'password' => '1', 'api_key' => '1' ) );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_save_connection' );
	$stop_redirect = static function (): never { throw new RuntimeException( 'editor-save-redirect' ); };
	add_filter( 'wp_redirect', $stop_redirect );
	try { $admin->handle_save_connection(); }
	catch ( RuntimeException $error ) { sw_mailgate_check( 'editor-save-redirect' === $error->getMessage(), 'Unrelated save reaches its success redirect.' ); }
	finally { remove_filter( 'wp_redirect', $stop_redirect ); }
	$saved = $connections->find( $id );
	foreach ( $original_settings as $key => $value ) {
		if ( $saved->is_locked( $key ) ) {
			sw_mailgate_check( $value === $saved->settings()[ $key ], 'Locked underlying setting is preserved: ' . $key . '.' );
		}
	}
	sw_mailgate_check( $original_secrets === get_option( Secret_Store::OPTION ), 'Locked stored secrets cannot be overwritten or deleted by submitted form values.' );
}
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
echo 'PASS: Editor override regressions completed.' . PHP_EOL;
