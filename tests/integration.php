<?php
/**
 * WordPress + MariaDB integration tests. Use a dedicated, disposable database.
 * WP_ROOT and WP_DB_* select the test installation; see tests/README.md.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

$sw_mailgate_test_complete = false;
register_shutdown_function( static function () use ( &$sw_mailgate_test_complete ): void {
	if ( ! $sw_mailgate_test_complete ) {
		fwrite( STDERR, 'FAIL: Integration suite did not complete.' . PHP_EOL );
		exit( 1 );
	}
} );

use SWMailgate\Admin\Log_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Logging\Logger;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Privacy;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;
use SWMailgate\Providers\Provider;
use SWMailgate\Providers\Send_Result;
use SWMailgate\Routing\Circuit_Breaker;
use SWMailgate\Routing\Failover_Settings;
use SWMailgate\Routing\Retry_Queue;
use SWMailgate\Security\Secret_Store;

$issue_mode = ( $argv[2] ?? '' ) === 'issues' ? ( $argv[3] ?? 'smtp' ) : null;
if ( in_array( $issue_mode, array( 'editor', 'editor-false' ), true ) ) {
	define( 'SW_MAILGATE_PRIMARY_HOST', 'effective.example.test' );
	define( 'SW_MAILGATE_PRIMARY_PORT', 2525 );
	define( 'SW_MAILGATE_PRIMARY_ENCRYPTION', 'ssl' );
	define( 'SW_MAILGATE_PRIMARY_AUTH', 'editor' === $issue_mode ? 'yes' : false );
	define( 'SW_MAILGATE_PRIMARY_TLS_VERIFY', 'editor' === $issue_mode ? '0' : false );
	define( 'SW_MAILGATE_PRIMARY_PASSWORD', 'private-config-password-fixture' );
	define( 'SW_MAILGATE_MAILGUN_REGION', 'eu' );
	define( 'SW_MAILGATE_MAILGUN_API_KEY', 'private-config-api-fixture' );
}
$multisite_mode = ( $argv[2] ?? '' ) === 'multisite' ? ( $argv[3] ?? 'run' ) : null;
if ( 'run' === $multisite_mode ) {
	define( 'MULTISITE', true );
	define( 'SUBDOMAIN_INSTALL', false );
	define( 'DOMAIN_CURRENT_SITE', 'mailgate.test' );
	define( 'PATH_CURRENT_SITE', '/' );
	define( 'SITE_ID_CURRENT_SITE', 1 );
	define( 'BLOG_ID_CURRENT_SITE', 1 );
}

$failover_mode = ( $argv[2] ?? '' ) === 'failover' ? ( $argv[3] ?? 'run' ) : null;
if ( null !== $failover_mode && 'run' !== $failover_mode ) {
	throw new InvalidArgumentException( 'Invalid failover test mode.' );
}

$mailgun_mode = ( $argv[2] ?? '' ) === 'mailgun' ? ( $argv[3] ?? 'api' ) : null;
if ( 'constant' === $mailgun_mode ) {
	define( 'SW_MAILGATE_MAILGUN_DOMAIN', 'mg.config.example.test' );
	define( 'SW_MAILGATE_MAILGUN_REGION', 'eu' );
	define( 'SW_MAILGATE_MAILGUN_API_KEY', 'key-config-example-mailgun' );
	define( 'SW_MAILGATE_MAILGUN_TIMEOUT', 999 );
} elseif ( null !== $mailgun_mode && 'api' !== $mailgun_mode ) {
	throw new InvalidArgumentException( 'Invalid Mailgun test mode.' );
}

$redirection_mode = ( $argv[2] ?? '' ) === 'redirection' ? ( $argv[3] ?? 'ui' ) : null;
if ( null !== $redirection_mode ) {
	define( 'WP_ENVIRONMENT_TYPE', 'production' === $redirection_mode ? 'production' : 'staging' );
	if ( 'constant' === $redirection_mode ) {
		define( 'SW_MAILGATE_REDIRECT_TO', 'config-sink@example.test' );
	} elseif ( 'empty' === $redirection_mode ) {
		define( 'SW_MAILGATE_REDIRECT_TO', '' );
	} elseif ( 'invalid' === $redirection_mode ) {
		define( 'SW_MAILGATE_REDIRECT_TO', "bad@example.test\r\nBcc: victim@example.test" );
	} elseif ( 'invalid-type' === $redirection_mode ) {
		define( 'SW_MAILGATE_REDIRECT_TO', true );
	} elseif ( ! in_array( $redirection_mode, array( 'ui', 'production' ), true ) ) {
		throw new InvalidArgumentException( 'Invalid redirection test mode.' );
	}
}

// PHP constants are immutable: exercise each configuration in a separate process.
$logging_mode = $argv[1] ?? 'enabled';
switch ( $logging_mode ) {
	case 'enabled':
		define( 'SW_MAILGATE_LOG_ENABLED', true );
		break;
	case 'disabled':
		define( 'SW_MAILGATE_LOG_ENABLED', false );
		break;
	case 'string':
		define( 'SW_MAILGATE_LOG_ENABLED', 'true' );
		break;
	case 'integer':
		define( 'SW_MAILGATE_LOG_ENABLED', 1 );
		break;
	case 'unset':
		break;
	default:
		throw new InvalidArgumentException( 'Expected enabled, disabled, unset, string or integer.' );
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
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_ADMIN', true );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_CONTENT_URL', 'https://mailgate.test/wp-content' );
define( 'WP_HOME', 'https://mailgate.test' );
define( 'WP_SITEURL', 'https://mailgate.test' );
$_SERVER['HTTP_HOST'] = 'mailgate.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$table_prefix = null !== $multisite_mode ? 'swmg_multisite_' : 'swmg_test_';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
ini_set( 'display_errors', '1' );
error_reporting( E_ALL );

function sw_mailgate_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}

if ( ! is_blog_installed() ) {
	add_filter( 'pre_wp_mail', '__return_true' );
	wp_install( 'Mailgate tests', 'testadmin', 'tests@example.test', true, '', wp_generate_password( 32 ) );
	remove_filter( 'pre_wp_mail', '__return_true' );
}
wp_set_current_user( (int) get_user_by( 'login', 'testadmin' )->ID );
require ( getenv( 'SW_MAILGATE_TEST_PLUGIN' ) ?: dirname( __DIR__ ) ) . '/sw-mailgate.php';
// Simulate an existing v3 installation: the value must change to migrate autoload.
update_option( 'sw_mailgate_db_version', 3, false );
wp_set_option_autoload( 'sw_mailgate_db_version', false );
Installer::maybe_upgrade();
sw_mailgate_check( Installer::DB_VERSION === (int) get_option( 'sw_mailgate_db_version' ), 'Existing v3 installations upgrade without reactivation.' );
sw_mailgate_check( isset( wp_load_alloptions( true )['sw_mailgate_db_version'] ), 'Upgraded version marker is autoloaded.' );
$sw_mailgate_was_installing = wp_installing( false );
wp_cache_flush();
// Populate alloptions as a normal WordPress request does, then measure the version check.
wp_load_alloptions();
$sw_mailgate_queries_before = $wpdb->num_queries;
Installer::maybe_upgrade();
sw_mailgate_check( $sw_mailgate_queries_before === $wpdb->num_queries, 'Steady-state version check performs no SQL query without a persistent object cache.' );
wp_installing( $sw_mailgate_was_installing );
delete_option( 'sw_mailgate_db_version' );
Installer::activate();
sw_mailgate_check( isset( wp_load_alloptions( true )['sw_mailgate_db_version'] ), 'Fresh installations autoload the version marker.' );
Installer::activate();
Installer::activate();
Installer::maybe_upgrade();
sw_mailgate_check( isset( wp_load_alloptions( true )['sw_mailgate_db_version'] ), 'Repeated activation preserves version-marker autoload.' );
// A prior run may have removed the capability; refresh the already loaded WP_User.
wp_set_current_user( 0 );
wp_set_current_user( (int) get_user_by( 'login', 'testadmin' )->ID );
sw_mailgate_check( Installer::DB_VERSION === (int) get_option( 'sw_mailgate_db_version' ), 'Schema upgrade is idempotent.' );
sw_mailgate_check( Retry_Queue::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Retry_Queue::table() ) ) ), 'Schema upgrade creates the retry queue table.' );
sw_mailgate_check( (bool) wp_next_scheduled( Logger::CRON_HOOK ), 'Retention cron is scheduled.' );

$logs = new Log_Repository();
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Log_Repository::table() ) );
delete_option( Settings::OPTION );
delete_option( \SWMailgate\Mail\Redirection_Settings::OPTION );
delete_option( Connection_Repository::OPTION );
delete_option( Circuit_Breaker::OPTION );
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Retry_Queue::table() ) );
// The deterministic 'first' connection always fails; M1–M4 suites expect it in every attempt group.
update_option( Failover_Settings::OPTION, array( 'breaker_threshold' => 0 ) );
$connections = new Connection_Repository( new Secret_Store() );
foreach ( array( 'first', 'second' ) as $id ) {
	$connections->save( array( 'id' => $id, 'provider' => 'test', 'enabled' => true, 'from_email' => 'sender@example.test' ) );
}

$provider = new class() implements Provider {
	public array $messages = array();
	public string $mode = 'failover';
	public function id(): string { return 'test'; }
	public function label(): string { return 'Test'; }
	public function fields(): array { return array(); }
	public function prepare( Mail_Catcher $mailer, Connection $connection ): void {}
	public function test_connection( Connection $connection ): Send_Result { return Send_Result::success(); }
	public function send( Envelope $envelope, Connection $connection ): Send_Result {
		$this->messages[] = array(
			'to' => $envelope->to(), 'cc' => $envelope->cc(), 'bcc' => $envelope->bcc(),
			'body' => $envelope->mailer()->Body, 'alt' => $envelope->mailer()->AltBody,
			'reply_to' => $envelope->mailer()->getReplyToAddresses(), 'type' => $envelope->mailer()->ContentType,
			'subject' => $envelope->subject(), 'mime' => $envelope->mime(), 'connection' => $connection->id(),
		);
		if ( 'permanent' === $this->mode ) {
			return Send_Result::failure( Send_Result::PERMANENT, 'Rejected user@example.test' );
		}
		return 'first' === $connection->id() ? Send_Result::failure( Send_Result::TRANSIENT, 'Unavailable user@example.test' ) : Send_Result::success( 'test-message-id' );
	}
};
add_filter( 'sw_mailgate_providers', static fn( array $providers ): array => array_merge( $providers, array( $provider ) ) );
\SWMailgate\Plugin::instance()->install_mailer();
$headers = array( 'Cc: cc@example.test', 'Bcc: bcc@example.test', 'Reply-To: reply@example.test', 'Content-Type: text/html; charset=UTF-8' );
if ( 'oauth' === ( $argv[2] ?? '' ) ) {
	include __DIR__ . '/oauth.php';
	$sw_mailgate_test_complete = true;
	exit;
}
if ( null !== $issue_mode ) {
	include __DIR__ . match ( $issue_mode ) {
		'smtp' => '/smtp-delivery.php',
		'secrets' => '/secret-values.php',
		default => '/editor-overrides.php',
	};
	$sw_mailgate_test_complete = true;
	exit;
}
if ( null !== $multisite_mode ) {
	include __DIR__ . '/multisite.php';
	$sw_mailgate_test_complete = true;
	exit;
}
if ( null !== $failover_mode ) {
	include __DIR__ . '/failover.php';
	$sw_mailgate_test_complete = true;
	exit;
}
if ( null !== $mailgun_mode ) {
	include __DIR__ . '/mailgun.php';
	$sw_mailgate_test_complete = true;
	exit;
}
if ( null !== $redirection_mode ) {
	include __DIR__ . '/redirection.php';
	$sw_mailgate_test_complete = true;
	exit;
}
if ( 'enabled' !== $logging_mode ) {
	update_option( Settings::OPTION, array( 'enabled' => true, 'store_body' => true ) );
	sw_mailgate_check( false === Settings::get()['enabled'], 'Stored settings cannot enable logging (' . $logging_mode . ').' );
	$attempts = array();
	add_action( 'sw_mailgate_attempt', static function ( $attempt ) use ( &$attempts ): void { $attempts[] = $attempt; } );
	sw_mailgate_check( wp_mail( 'user@example.test', 'Logging disabled', 'Do not retain', $headers ), 'Delivery succeeds with logging disabled.' );
	sw_mailgate_check( 0 === $logs->count() && 2 === count( $attempts ) && null === $attempts[0]->payload && null === $attempts[1]->payload, 'Disabled logging writes no rows and captures no bodies.' );
	Settings::save( array( 'enabled' => true, 'store_body' => true, 'retention' => 30 ) );
	sw_mailgate_check( ! array_key_exists( 'enabled', get_option( Settings::OPTION ) ) && ! Settings::get()['enabled'], 'Saving preferences cannot enable logging.' );
	wp_mail( 'user@example.test', 'Still disabled', 'Do not retain', $headers );
	sw_mailgate_check( 0 === $logs->count(), 'Submitted enabled values cannot bypass wp-config.php.' );
	$page = new Log_Page( $logs, $connections );
	ob_start();
	$page->settings();
	$html = ob_get_clean();
	sw_mailgate_check( ! str_contains( $html, 'name="enabled"' ) && str_contains( $html, 'SW_MAILGATE_LOG_ENABLED' ), 'Settings show configuration instructions without an enable control.' );
	update_option( Installer::DELETE_DATA_OPTION, true );
	Installer::uninstall();
	$sw_mailgate_test_complete = true;
	echo 'PASS: Logging configuration (' . $logging_mode . ').' . PHP_EOL;
	exit;
}
update_option( Settings::OPTION, array( 'enabled' => false ) );
sw_mailgate_check( true === Settings::get()['enabled'], 'Only the explicit boolean true constant enables logging; stale options are ignored.' );
sw_mailgate_check( wp_mail( 'user@example.test', 'First mail', '<b>Private body</b>', $headers ), 'Failover delivers successfully.' );
$rows = $logs->search();
sw_mailgate_check( 2 === count( $rows ) && 'sent' === $rows[0]['status'] && 'failed' === $rows[1]['status'], 'All connection attempts are logged in order.' );
sw_mailgate_check( $rows[0]['attempt_group'] === $rows[1]['attempt_group'] && 2 === (int) $rows[0]['attempt_number'], 'Attempts share a message group.' );
sw_mailgate_check( null === $rows[0]['payload'] && ! str_contains( wp_json_encode( $rows ), 'Private body' ), 'Bodies are not retained by default.' );
sw_mailgate_check( array( 'sent' => 1, 'failed' => 0 ) === $logs->totals( 7 ), 'Dashboard counts final message outcomes.' );
sw_mailgate_check( 2 === count( $logs->personal( 'BCC@example.test' ) ) && 2 === count( $logs->personal( 'reply@example.test' ) ), 'Privacy lookup includes Bcc, Reply-To and case normalization.' );

$provider->mode = 'permanent';
sw_mailgate_check( ! wp_mail( 'user@example.test', 'Permanent failure', 'Body' ), 'Permanent errors stop failover.' );
sw_mailgate_check( 3 === $logs->count() && array( 'sent' => 1, 'failed' => 1 ) === $logs->totals( 30 ), 'Failed messages are counted once.' );
$provider->mode = 'failover';

Settings::save( array( 'store_body' => true, 'retention' => 30 ) );
wp_mail( 'user@example.test', 'Stored mail', '<b>Stored body</b>', $headers );
$stored = $logs->search()[0];
$payload = json_decode( $stored['payload'], true );
sw_mailgate_check( '<b>Stored body</b>' === $payload['body'] && ! isset( $payload['Password'] ), 'Content opt-in stores message data without SMTP secrets.' );
$page = new Log_Page( $logs, $connections );
sw_mailgate_check( $page->send_again( (int) $stored['id'] ), 'Stored messages can be resent.' );
$resent = end( $provider->messages );
sw_mailgate_check( array( 'cc@example.test' ) === $resent['cc'] && array( 'bcc@example.test' ) === $resent['bcc'] && '<b>Stored body</b>' === $resent['body'] && 'text/html' === $resent['type'] && in_array( 'reply@example.test', array_column( $resent['reply_to'], 0 ), true ), 'Resend preserves Cc, Bcc, Reply-To and HTML content.' );
sw_mailgate_check( false === has_action( 'phpmailer_init' ), 'Resend removes its temporary mail hook.' );
try {
	$page->send_again( (int) $rows[0]['id'] );
	throw new LogicException( 'Resend without a body should fail.' );
} catch ( RuntimeException $error ) {
	sw_mailgate_check( true, 'Resend without stored content is rejected.' );
}

Settings::save( array( 'store_body' => true, 'mask_recipients' => true, 'retention' => 30 ) );
wp_mail( 'masked@example.test', 'Contact masked@example.test', 'Never retain this body', array( 'Bcc: hidden@example.test' ) );
$masked = $logs->search()[0];
sw_mailgate_check( null === $masked['payload'] && ! str_contains( wp_json_encode( $masked ), '@example.test' ), 'Masking redacts metadata and suppresses content storage.' );
$privacy = new Privacy( $logs );
$export = $privacy->export( 'hidden@example.test' );
sw_mailgate_check( 2 === count( $export['data'] ) && ! str_contains( wp_json_encode( $export ), 'masked@example.test' ), 'Privacy export works for masked Bcc without leaking other recipients.' );
$erased = $privacy->erase( 'hidden@example.test' );
sw_mailgate_check( $erased['items_removed'] && $erased['done'] && array() === $logs->personal( 'hidden@example.test' ), 'Privacy erasure removes masked entries.' );

$batch = $masked;
unset( $batch['id'] );
$batch['address_hashes'] = wp_json_encode( array( Log_Repository::address_hash( 'batch@example.test' ) ) );
for ( $i = 0; $i < 205; ++$i ) {
	$batch['attempt_group'] = wp_generate_uuid4();
	$logs->insert( $batch );
}
$batch_first = $privacy->export( 'batch@example.test', 1 );
$batch_last = $privacy->export( 'batch@example.test', 3 );
sw_mailgate_check( 100 === count( $batch_first['data'] ) && ! $batch_first['done'] && 5 === count( $batch_last['data'] ) && $batch_last['done'], 'Privacy export paginates more than 200 entries.' );
$erase_first = $privacy->erase( 'batch@example.test', 1 );
$erase_second = $privacy->erase( 'batch@example.test', 2 );
$erase_last = $privacy->erase( 'batch@example.test', 3 );
sw_mailgate_check( ! $erase_first['done'] && ! $erase_second['done'] && $erase_last['done'] && array() === $logs->personal( 'batch@example.test' ), 'Batched privacy erasure does not skip entries after deletion.' );

$before = $logs->count();
Settings::save( array( 'enabled' => false, 'retention' => 30 ) );
sw_mailgate_check( Settings::get()['enabled'] && ! array_key_exists( 'enabled', get_option( Settings::OPTION ) ), 'Database preferences cannot override the wp-config.php constant.' );
$wpdb->update( Log_Repository::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $stored['id'] ) );
do_action( Logger::CRON_HOOK );
sw_mailgate_check( null === $logs->find( (int) $stored['id'] ) && $before - 1 === $logs->count(), 'Retention removes expired entries.' );

sw_mailgate_check( 1 === $logs->count( array( 'status' => 'sent', 's' => 'First mail', 'date_from' => gmdate( 'Y-m-d' ), 'date_to' => gmdate( 'Y-m-d' ) ) ), 'Status, search and UTC date filters combine correctly.' );
sw_mailgate_check( 0 === $logs->count( array( 'connection_id' => "' OR 1=1 --" ) ), 'Query filters escape SQL input.' );
sw_mailgate_check( "'=1+1" === Log_Page::csv_cell( '=1+1' ) && "'  @SUM(1)" === Log_Page::csv_cell( '  @SUM(1)' ) && 'Normal' === Log_Page::csv_cell( 'Normal' ), 'CSV export neutralizes spreadsheet formulas.' );

require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
set_current_screen( 'mailgate_page_sw-mailgate-log' );
$_GET = array();
ob_start();
$page->render();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'First mail' ) && str_contains( $html, 'Export filtered log' ), 'Native WordPress log list renders.' );
$_GET = array( 'log_id' => $rows[0]['id'] );
ob_start();
$page->render();
$html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'second (sent)' ) && str_contains( $html, 'first (failed)' ), 'Detail view renders the entire failover group.' );

$die_handler = static fn() => static function (): never { throw new RuntimeException( 'test-wp-die' ); };
add_filter( 'wp_die_handler', $die_handler );
wp_set_current_user( 0 );
try {
	$page->export();
	throw new LogicException( 'Unauthorized log export should stop.' );
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-wp-die' === $error->getMessage(), 'CSV export rejects users without the management capability.' );
}
wp_set_current_user( (int) get_user_by( 'login', 'testadmin' )->ID );
$_REQUEST = array();
try {
	$page->resend();
	throw new LogicException( 'Resend without nonce should stop.' );
} catch ( RuntimeException $error ) {
	sw_mailgate_check( 'test-wp-die' === $error->getMessage(), 'Resend rejects requests without a valid nonce.' );
}
remove_filter( 'wp_die_handler', $die_handler );

Settings::save( array() );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', Log_Repository::table() ) );
ob_start();
$sent = wp_mail( 'user@example.test', 'Broken log database', 'Body' );
$output = ob_get_clean();
sw_mailgate_check( $sent && '' === $output && ! $wpdb->suppress_errors, 'Database logging failures cannot break sending or expose database errors.' );
Installer::activate();
wp_mail( 'user@example.test', 'Preserve me', 'Body' );
update_option( Installer::DELETE_DATA_OPTION, false );
Installer::uninstall();
sw_mailgate_check( 2 === $logs->count() && false === wp_next_scheduled( Logger::CRON_HOOK ), 'Preserving uninstall retains logs and clears cron.' );
update_option( Installer::DELETE_DATA_OPTION, true );
Installer::uninstall();
sw_mailgate_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Log_Repository::table() ) ) ) && false === get_option( Settings::OPTION ), 'Deleting uninstall removes the log table and preferences.' );
echo 'PASS: WordPress/MariaDB integration completed.' . PHP_EOL;
$sw_mailgate_test_complete = true;
