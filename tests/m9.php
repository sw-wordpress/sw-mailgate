<?php
/** M9 acceptance: Site Health, CLI, conflict notices and actual translated catalogs. */
use SWMailgate\Admin\Health_Checks;
use SWMailgate\Admin\Mailer_Conflict;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Security\Secret_Store;

$secrets = new Secret_Store();
$registry = new Provider_Registry( array() );
$health = new Health_Checks( $connections, $registry, $secrets );
$tests = apply_filters( 'site_status_tests', array() );
sw_mailgate_check( isset( $tests['direct']['sw_mailgate_connections'], $tests['direct']['sw_mailgate_oauth'], $tests['direct']['sw_mailgate_dns'] ), 'All three Mailgate Site Health tests are wired.' );
sw_mailgate_check( 'good' === $health->connections()['status'] && 'good' === $health->oauth()['status'], 'Healthy transports and no OAuth grants pass.' );
$connections->save( array( 'id' => 'unknown', 'provider' => 'missing' ) );
sw_mailgate_check( 'recommended' === $health->connections()['status'], 'Unknown enabled provider requires attention.' );
$connections->set_enabled( 'unknown', false );
sw_mailgate_check( 'good' === $health->connections()['status'], 'Disabled connections are not probed.' );
$connections->save( array( 'id' => 'google', 'provider' => 'google' ) );
sw_mailgate_check( 'recommended' === $health->oauth()['status'], 'Missing delegated grant requires attention.' );
$connections->delete( 'google' );
$connections->save( array( 'id' => 'app', 'provider' => 'office365', 'settings' => array( 'mode' => 'application' ) ) );
sw_mailgate_check( 'good' === $health->oauth()['status'], 'Application access does not require a delegated grant.' );
$connections->delete( 'app' );
$throwing = new class() implements \SWMailgate\Providers\Provider {
	public function id(): string { return 'throws'; }
	public function label(): string { return 'throws'; }
	public function fields(): array { return array(); }
	public function prepare( \SWMailgate\Mail\Mail_Catcher $mailer, \SWMailgate\Connections\Connection $connection ): void {}
	public function send( \SWMailgate\Mail\Envelope $envelope, \SWMailgate\Connections\Connection $connection ): \SWMailgate\Providers\Send_Result { throw new RuntimeException( 'private fixture' ); }
	public function test_connection( \SWMailgate\Connections\Connection $connection ): \SWMailgate\Providers\Send_Result { throw new RuntimeException( 'private fixture' ); }
};
$connections->save( array( 'id' => 'throws', 'provider' => 'throws' ) );
$throw_health = new Health_Checks( $connections, new Provider_Registry( array( $throwing ) ), $secrets );
sw_mailgate_check( 'recommended' === $throw_health->connections()['status'] && ! str_contains( json_encode( $throw_health->connections() ), 'private fixture' ), 'Probe exceptions become safe diagnostics.' );
$connections->delete( 'throws' );

$capture = static function ( callable $fn ): string { ob_start(); try { $fn(); return ob_get_contents(); } finally { ob_end_clean(); } };
$expect_error = static function ( callable $fn, string $part ): void {
	try { $fn(); throw new LogicException( 'Expected CLI error.' ); } catch ( RuntimeException $error ) { sw_mailgate_check( str_contains( $error->getMessage(), $part ), 'CLI rejects: ' . $part ); }
};
$cli = WP_CLI::$commands['mailgate'] ?? null;
sw_mailgate_check( $cli instanceof \SWMailgate\Cli\Commands, 'WP-CLI bootstrap registers the command object.' );
$connections->save( array( 'id' => 'secret', 'provider' => 'test', 'enabled' => false, 'settings' => array( 'password' => 'never-print-me' ) ) );
foreach ( array( 'table', 'json', 'csv' ) as $format ) {
	$output = $capture( fn() => $cli->connections( array(), array( 'format' => $format ) ) );
	sw_mailgate_check( str_contains( $output, 'secret' ) && ! str_contains( $output, 'never-print-me' ) && str_contains( $output, $format ), 'Connection output is safe: ' . $format );
}
$expect_error( fn() => $cli->connections( array(), array( 'format' => 'html' ) ), 'Format' );
foreach ( array( '0', '501', 'abc', '-1' ) as $limit ) { $expect_error( fn() => $cli->log( array(), array( 'limit' => $limit ) ), 'Limit' ); }
$expect_error( fn() => $cli->dns( array(), array( 'connection' => 'missing' ) ), 'Unknown connection' );
$expect_error( fn() => $cli->test( array( "invalid\r\nBcc: bad@example.test" ), array() ), 'valid recipient' );
$expect_error( fn() => $cli->test( array( 'recipient@example.test' ), array( 'connection' => 'secret' ) ), 'disabled connection' );
$expect_error( fn() => $cli->test( array( 'recipient@example.test' ), array( 'connection' => 'missing' ) ), 'disabled connection' );
$expect_error( fn() => $cli->test( array( 'recipient@example.test' ), array( 'connection' => 'first' ) ), 'Test mail failed' );
$output = $capture( fn() => $cli->test( array( 'recipient@example.test' ), array( 'connection' => 'second' ) ) );
sw_mailgate_check( str_contains( $output, 'accepted' ), 'CLI test sends through the selected transport.' );
$output = $capture( fn() => $cli->test( array( 'recipient@example.test' ), array() ) );
sw_mailgate_check( str_contains( $output, 'accepted' ), 'CLI test uses the failover chain after a restricted send.' );
$output = $capture( fn() => $cli->log( array(), array( 'limit' => '1', 'status' => 'sent', 'connection' => 'second', 'format' => 'json' ) ) );
$data = json_decode( $output, true );
sw_mailgate_check( 1 === count( $data['items'] ) && 'sent' === $data['items'][0]['status'] && ! str_contains( $output, 'recipient@example.test' ) && ! str_contains( $output, 'subject' ), 'CLI log filters and limits rows without disclosing recipients/content.' );
// A missing From produces deterministic local DNS advice, with no resolver or HTTP call.
$output = $capture( fn() => $cli->dns( array(), array( 'connection' => 'secret', 'refresh' => '' ) ) );
sw_mailgate_check( str_contains( $output, 'secret' ) && str_contains( $output, 'sender' ), 'CLI DNS selects a disabled connection and refreshes its diagnostics.' );
$dns_originals = array();
foreach ( $connections->chain() as $connection ) {
	$row = $connection->to_array();
	$dns_originals[] = $row;
	$row['from_email'] = '';
	$connections->save( $row );
}
$output = $capture( fn() => $cli->dns( array(), array() ) );
sw_mailgate_check( str_contains( $output, 'first' ) && ! str_contains( $output, 'secret' ), 'CLI DNS defaults to enabled connections.' );
foreach ( $dns_originals as $row ) { $connections->save( $row ); }

// Follow normal queue rules, but never claim that a queued mail was accepted remotely.
$connections->set_enabled( 'second', false );
update_option( \SWMailgate\Routing\Failover_Settings::OPTION, array( 'queue_enabled' => true, 'breaker_threshold' => 0 ) );
$output = $capture( fn() => $cli->test( array( 'recipient@example.test' ), array() ) );
$queue = new \SWMailgate\Routing\Retry_Queue( $secrets );
sw_mailgate_check( str_contains( $output, 'queued for retry' ) && 1 === $queue->count(), 'CLI distinguishes queue acceptance from transport acceptance.' );
$queue->clear();
update_option( \SWMailgate\Routing\Failover_Settings::OPTION, array( 'breaker_threshold' => 0 ) );
$connections->set_enabled( 'second', true );
update_option( \SWMailgate\Mail\Redirection_Settings::OPTION, array( 'target' => 'sink@example.test' ) );
$capture( fn() => $cli->test( array( 'recipient@example.test' ), array( 'connection' => 'second' ) ) );
$last = end( $provider->messages );
sw_mailgate_check( array( 'sink@example.test' ) === $last['to'], 'CLI test mail preserves configured redirection.' );
delete_option( \SWMailgate\Mail\Redirection_Settings::OPTION );

$notice = new Mailer_Conflict();
$old_active = get_option( 'active_plugins', array() );
$old_network = get_site_option( 'active_sitewide_plugins', array() );
update_option( 'active_plugins', array() );
update_site_option( 'active_sitewide_plugins', array() );
sw_mailgate_check( '' === $capture( array( $notice, 'notice' ) ), 'Mailgate catcher alone causes no conflict warning.' );
update_option( 'active_plugins', array( 'wp-mail-smtp/wp_mail_smtp.php' ) );
sw_mailgate_check( str_contains( $capture( array( $notice, 'notice' ) ), 'another mailer' ), 'Active competing mailer causes a warning.' );
update_option( 'active_plugins', array() );
update_site_option( 'active_sitewide_plugins', array( 'fluent-smtp/fluent-smtp.php' => time() ) );
sw_mailgate_check( str_contains( $capture( array( $notice, 'notice' ) ), 'another mailer' ), 'Network-activated competing mailer causes a warning.' );
wp_set_current_user( 0 );
sw_mailgate_check( '' === $capture( array( $notice, 'notice' ) ), 'Unauthorized users see no conflict diagnostics.' );
wp_set_current_user( (int) get_user_by( 'login', 'testadmin' )->ID );
update_site_option( 'active_sitewide_plugins', array() );
$catcher = $GLOBALS['phpmailer'];
$GLOBALS['phpmailer'] = new WP_PHPMailer();
sw_mailgate_check( str_contains( $capture( array( $notice, 'notice' ) ), 'another mailer' ), 'Unknown replacement mailer is detected.' );
$GLOBALS['phpmailer'] = $catcher;
update_option( 'active_plugins', $old_active );
update_site_option( 'active_sitewide_plugins', $old_network );

foreach ( array( 'de_DE', 'de_DE_formal' ) as $locale ) {
	$filter = static fn() => $locale;
	add_filter( 'determine_locale', $filter );
	unload_textdomain( 'sw-mailgate', true );
	\SWMailgate\Plugin::instance()->load_textdomain();
	sw_mailgate_check( 'Verbindungen' === __( 'Connections', 'sw-mailgate' ), 'German catalog loads for ' . $locale );
	remove_filter( 'determine_locale', $filter );
}
unload_textdomain( 'sw-mailgate', true );
foreach ( $connections->all() as $connection ) { $connections->delete( $connection->id() ); }
sw_mailgate_check( 'recommended' === $health->connections()['status'], 'Native transport fallback is reported when no connection is enabled.' );
