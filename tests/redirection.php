<?php
/**
 * M3 cases, included by integration.php [enabled|unset] redirection [ui|constant|empty|invalid|invalid-type].
 * Uses real WordPress/PHPMailer and the integration suite's deterministic transport.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Admin\Log_Page;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Dispatcher;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Mail\Redirection_Settings;
use SWMailgate\Mail\Redirector;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Security\Secret_Store;

$page = new Log_Page( $logs, $connections );
$redirector = new Redirector();
$GLOBALS['phpmailer']->set_redirector( $redirector );
$logging = Settings::get()['enabled'];

function sw_mailgate_finish_redirection(): void {
	update_option( Installer::DELETE_DATA_OPTION, true );
	Installer::uninstall();
	sw_mailgate_check( false === get_option( Redirection_Settings::OPTION ), 'Deleting uninstall removes redirection preferences.' );
	echo 'PASS: Redirection integration completed.' . PHP_EOL;
}

ob_start();
$page->redirection_notice();
$notice = ob_get_clean();
if ( 'production' === $redirection_mode ) {
	sw_mailgate_check( '' === $notice, 'Production does not show a staging warning when redirection is off.' );
	sw_mailgate_finish_redirection();
	return;
}
if ( in_array( $redirection_mode, array( 'invalid', 'invalid-type' ), true ) ) {
	sw_mailgate_check( str_contains( $notice, 'sending is blocked' ), 'Invalid forced configuration shows a blocking notice.' );
	$before = count( $provider->messages );
	sw_mailgate_check( ! wp_mail( 'real@example.test', 'Blocked', 'Body', $headers ), 'Invalid wp-config redirection blocks sending.' );
	sw_mailgate_check( $before === count( $provider->messages ), 'Invalid configuration never reaches a provider.' );
	sw_mailgate_check( $redirector->without_redirection( static fn() => wp_mail( 'explicit@example.test', 'Explicit test bypass', 'Body' ) ), 'Explicit test bypass remains available for invalid configuration.' );
	sw_mailgate_check( ! wp_mail( 'real@example.test', 'Still blocked', 'Body' ), 'Bypass restores blocking after the callback.' );
	sw_mailgate_finish_redirection();
	return;
}

if ( 'empty' === $redirection_mode ) {
	update_option( Redirection_Settings::OPTION, array( 'target' => 'database-sink@example.test' ) );
	sw_mailgate_check( wp_mail( 'real@example.test', 'Not redirected', 'Body', $headers ), 'Empty forced constant disables the database target.' );
	$message = end( $provider->messages );
	sw_mailgate_check( array( 'real@example.test' ) === $message['to'] && array( 'bcc@example.test' ) === $message['bcc'] && 'Not redirected' === $message['subject'], 'Disabled override preserves original recipient roles and subject.' );
	sw_mailgate_check( str_contains( $notice, 'non-production' ), 'Staging warns when a forced empty target disables redirection.' );
	sw_mailgate_finish_redirection();
	return;
}

sw_mailgate_check( 'ui' !== $redirection_mode || str_contains( $notice, 'non-production' ), 'Staging warns when redirection is off.' );
sw_mailgate_check( null === Redirection_Settings::save( 'database-sink@example.test', '' ), 'Valid redirection preferences are saved.' );
$target = 'constant' === $redirection_mode ? 'config-sink@example.test' : 'database-sink@example.test';
sw_mailgate_check( $target === Redirection_Settings::get()['target'], 'wp-config overrides the stored target.' );
ob_start();
$page->redirection_notice();
$notice = ob_get_clean();
sw_mailgate_check( '' === $notice, 'The staging warning disappears while redirection is active.' );

sw_mailgate_check( wp_mail( 'real@example.test', 'Original subject', '<b>Body</b>', $headers ), 'Redirected mail is delivered through failover.' );
$message = end( $provider->messages );
sw_mailgate_check( array( $target ) === $message['to'] && array() === $message['cc'] && array() === $message['bcc'], 'To, Cc and Bcc are replaced by the safe target.' );
sw_mailgate_check( '[Redirected] Original subject' === $message['subject'] && str_contains( $message['mime'], 'X-Original-To: real@example.test, cc@example.test' ), 'Subject prefix and original visible recipients are present.' );
sw_mailgate_check( ! str_contains( $message['mime'], 'bcc@example.test' ) && in_array( 'reply@example.test', array_column( $message['reply_to'], 0 ), true ), 'Bcc is never exposed in MIME and Reply-To is preserved.' );
sw_mailgate_check( 2 === count( $provider->messages ) && array( $target ) === $provider->messages[0]['to'] && '[Redirected] Original subject' === $provider->messages[0]['subject'], 'Every failover attempt uses the redirected envelope and a single prefix.' );
if ( $logging ) {
	$rows = $logs->search();
	sw_mailgate_check( 'redirected' === $rows[0]['status'] && 'failed' === $rows[1]['status'] && in_array( 'bcc@example.test', json_decode( $rows[0]['original_to'], true ), true ), 'Logs distinguish redirected success from failed attempts and retain original recipients.' );
	sw_mailgate_check( array( 'sent' => 1, 'failed' => 0 ) === $logs->totals( 7 ) && 1 === $logs->count( array( 'status' => 'redirected' ) ), 'Redirected successes count once in dashboard totals and can be filtered.' );
	sw_mailgate_check( 2 === count( $logs->personal( 'bcc@example.test' ) ), 'Privacy lookup includes original Bcc recipients after redirection.' );
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	require_once ABSPATH . 'wp-admin/includes/template.php';
	set_current_screen( 'mailgate_page_sw-mailgate-log' );
	$_GET = array( 'status' => 'redirected' );
	ob_start();
	$page->render();
	$html = ob_get_clean();
	sw_mailgate_check( 'redirected' === $page->filters()['status'] && str_contains( $html, 'Redirected' ), 'Log list renders and filters the redirected status.' );
	$_GET = array();
} else {
	sw_mailgate_check( 0 === $logs->count(), 'Redirection never enables logging without wp-config opt-in.' );
}

sw_mailgate_check( null === Redirection_Settings::save( 'database-sink@example.test', "Allowed@Example.test\n@trusted.test" ), 'Address and exact-domain allowlists are accepted and normalized.' );
$mixed_headers = array( 'Cc: cc@trusted.test, cc@sub.trusted.test', 'Bcc: hidden@trusted.test, secret@example.test' );
wp_mail( array( 'Allowed@Example.test', 'outside@example.test' ), 'Mixed', 'Body', $mixed_headers );
$message = end( $provider->messages );
sw_mailgate_check( array( $target, 'Allowed@Example.test' ) === $message['to'] && array( 'cc@trusted.test' ) === $message['cc'] && array( 'hidden@trusted.test' ) === $message['bcc'], 'Allowlisted recipients retain their To/Cc/Bcc roles while others are redirected.' );
sw_mailgate_check( ! str_contains( $message['mime'], 'secret@example.test' ) && ! str_contains( $message['mime'], 'hidden@trusted.test' ), 'Neither redirected nor allowlisted Bcc addresses leak into headers.' );
wp_mail( 'only@trusted.test', 'Allowlisted only', 'Body' );
$message = end( $provider->messages );
sw_mailgate_check( array( 'only@trusted.test' ) === $message['to'] && 'Allowlisted only' === $message['subject'] && ! str_contains( $message['mime'], 'X-Original-To:' ), 'Messages with only allowlisted recipients remain unchanged.' );

$stored_settings = get_option( Redirection_Settings::OPTION );
sw_mailgate_check( null !== Redirection_Settings::save( 'database-sink@example.test', '*.trusted.test' ) && $stored_settings === get_option( Redirection_Settings::OPTION ), 'Invalid wildcard rules cannot broaden the allowlist or overwrite preferences.' );
if ( 'ui' === $redirection_mode ) {
	sw_mailgate_check( null !== Redirection_Settings::save( "bad@example.test\nBcc: leaked@example.test", '' ) && $stored_settings === get_option( Redirection_Settings::OPTION ), 'Invalid targets are rejected without sanitizing them into different addresses.' );
} else {
	Redirection_Settings::save( 'attempted-change@example.test', 'trusted.test' );
	sw_mailgate_check( $target === Redirection_Settings::get()['target'], 'Saving preferences cannot replace a forced target.' );
}

$redirector->without_redirection( static fn() => wp_mail( 'explicit@example.test', 'Bypassed test', 'Body' ) );
$message = end( $provider->messages );
sw_mailgate_check( array( 'explicit@example.test' ) === $message['to'] && 'Bypassed test' === $message['subject'], 'Scoped test bypass sends directly to the entered recipient.' );
try {
	$redirector->without_redirection( static function (): void { throw new RuntimeException( 'test-failure' ); } );
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-failure' === $error->getMessage(), 'Test bypass propagates callback failures.' );
}
wp_mail( 'real@example.test', 'After bypass', 'Body' );
$message = end( $provider->messages );
sw_mailgate_check( array( $target ) === $message['to'], 'Bypass state is restored even after an exception.' );

// Deferred recipient queues must be cleared even if normal recipient getters are empty.
$queued = static function ( $mailer ): void {
	$mailer->clearAllRecipients();
	$property = new ReflectionProperty( Mail_Catcher::class, 'RecipientsQueue' );
	$property->setValue( $mailer, array( array( 'bcc', 'queued@example.test', 'Deferred recipient' ) ) );
};
add_action( 'phpmailer_init', $queued );
wp_mail( 'placeholder@example.test', 'Deferred Bcc', 'Body' );
remove_action( 'phpmailer_init', $queued );
$message = end( $provider->messages );
sw_mailgate_check( array( $target ) === $message['to'] && array() === $message['bcc'] && ! str_contains( $message['mime'], 'queued@example.test' ) && str_contains( $message['mime'], 'X-Original-To: undisclosed-recipients:;' ), 'Deferred and Bcc-only messages cannot escape redirection or leak Bcc headers.' );

if ( $logging ) {
	Settings::save( array( 'store_body' => true, 'mask_recipients' => true, 'retention' => 30 ) );
	wp_mail( 'masked@example.test', 'Masked redirection', 'Private body', array( 'Bcc: original-hidden@example.test' ) );
	$row = $logs->search()[0];
	sw_mailgate_check( 'redirected' === $row['status'] && null === $row['payload'] && ! str_contains( $row['original_to'], '@example.test' ), 'Original recipients obey address masking and body storage stays disabled.' );
	Settings::save( array( 'store_body' => true, 'retention' => 30 ) );
	wp_mail( 'real@example.test', 'Stored redirected', 'Stored body' );
	$row = $logs->search()[0];
	sw_mailgate_check( $page->send_again( (int) $row['id'] ) && array( $target ) === end( $provider->messages )['to'], 'Resend respects current redirection and never restores original delivery addresses.' );
}

// Exercise the real native PHPMailer SMTP path, without any network connection.
$native = new class() extends \PHPMailer\PHPMailer\SMTP {
	public bool $open = false;
	public array $addresses = array();
	public string $message = '';
	public function connect( $host, $port = null, $timeout = 30, $options = array() ) { $this->open = true; return true; }
	public function connected() { return $this->open; }
	public function hello( $host = '' ) { return true; }
	public function getServerExt( $name ) { return false; }
	public function mail( $from ) { return true; }
	public function recipient( $address, $dsn = '' ) { $this->addresses[] = $address; return true; }
	public function data( $msg_data ) { $this->message = $msg_data; return true; }
	public function reset() { return true; }
	public function quit( $close_on_error = true ) { $this->open = false; return true; }
	public function close() { $this->open = false; }
};
$native_hook = static function ( $mailer ) use ( $native ): void {
	$mailer->isSMTP();
	$mailer->SMTPAutoTLS = false;
	$mailer->setSMTPInstance( $native );
};
$connections->set_enabled( 'first', false );
$connections->set_enabled( 'second', false );
add_action( 'phpmailer_init', $native_hook );
sw_mailgate_check( wp_mail( 'native-real@example.test', 'Native', 'Body', array( 'Bcc: native-hidden@example.test' ) ), 'Native WordPress transport still delivers without enabled Mailgate connections.' );
sw_mailgate_check( array( $target ) === $native->addresses && ! str_contains( $native->message, 'native-hidden@example.test' ), 'Native SMTP envelope is redirected and contains no original Bcc header.' );
remove_action( 'phpmailer_init', $native_hook );
$connections->set_enabled( 'first', true );
$connections->set_enabled( 'second', true );

// The authenticated test form scopes the bypass and connection restriction to one send.
$dispatcher = new Dispatcher( $connections, new Provider_Registry( array( $provider ) ) );
$GLOBALS['phpmailer']->set_dispatcher( $dispatcher );
add_filter( 'wp_mail', array( $dispatcher, 'begin_mail' ), 1 );
$admin = new Admin_Page( $connections, new Provider_Registry( array( $provider ) ), new Secret_Store(), $dispatcher, $redirector );
$_POST = array( 'recipient' => 'form@example.test', 'connection' => 'second', 'ignore_redirection' => '1' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_send_test' );
$stop_redirect = static function (): never { throw new RuntimeException( 'test-redirect' ); };
add_filter( 'wp_redirect', $stop_redirect );
try {
	$admin->handle_send_test();
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-redirect' === $error->getMessage(), 'Test form reaches its result redirect.' );
} finally {
	remove_filter( 'wp_redirect', $stop_redirect );
}
$message = end( $provider->messages );
sw_mailgate_check( array( 'form@example.test' ) === $message['to'] && 'second' === $message['connection'], 'Authenticated test form can bypass forced redirection for its selected connection.' );
wp_mail( 'real@example.test', 'After form', 'Body' );
$message = end( $provider->messages );
sw_mailgate_check( array( $target ) === $message['to'] && 'first' === $provider->messages[ count( $provider->messages ) - 2 ]['connection'], 'Normal mail regains redirection and the full failover chain after a test.' );

ob_start();
$page->settings();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'redirect_allowlist' ) && ( 'constant' !== $redirection_mode || str_contains( $html, 'disabled=' ) ), 'Admin settings render allowlist and locked wp-config target.' );

$stored_settings = get_option( Redirection_Settings::OPTION );
$die_handler = static fn() => static function (): never { throw new RuntimeException( 'test-invalid-input' ); };
add_filter( 'wp_die_handler', $die_handler );
$_POST = array( 'redirect_to' => array( 'unexpected' ), 'redirect_allowlist' => '' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_save_settings' );
try {
	$page->save_settings();
	throw new LogicException( 'Malformed redirection inputs should be rejected.' );
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-invalid-input' === $error->getMessage() && $stored_settings === get_option( Redirection_Settings::OPTION ), 'Malformed form inputs cannot silently disable redirection.' );
} finally {
	remove_filter( 'wp_die_handler', $die_handler );
}
$_POST = array( 'redirect_to' => 'from-form@example.test', 'redirect_allowlist' => 'trusted.test' );
add_filter( 'wp_redirect', $stop_redirect );
try {
	$page->save_settings();
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-redirect' === $error->getMessage(), 'Valid settings form reaches its success redirect.' );
} finally {
	remove_filter( 'wp_redirect', $stop_redirect );
}
sw_mailgate_check( ( 'constant' === $redirection_mode ? $target : 'from-form@example.test' ) === Redirection_Settings::get()['target'] && array( 'trusted.test' ) === Redirection_Settings::get()['allowlist'], 'Settings form saves validated values and respects the wp-config lock.' );
update_option( Installer::DELETE_DATA_OPTION, false );
Installer::uninstall();
sw_mailgate_check( false !== get_option( Redirection_Settings::OPTION ), 'Preserving uninstall retains redirection preferences.' );
sw_mailgate_finish_redirection();
