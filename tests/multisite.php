<?php
/**
 * Issue #12: real WordPress network cleanup. Run setup, then run in separate processes.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Installer;
use SWMailgate\Logging\Logger;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Redirection_Settings;
use SWMailgate\Security\Secret_Store;

if ( 'setup' === $multisite_mode ) {
	foreach ( $wpdb->tables( 'ms_global' ) as $table => $name ) { $wpdb->$table = $name; }
	install_network();
	if ( ! $wpdb->get_var( "SELECT id FROM {$wpdb->site} WHERE id = 1" ) ) {
		$result = populate_network( 1, 'mailgate.test', 'tests@example.test', 'Mailgate test network', '/', false );
		sw_mailgate_check( ! is_wp_error( $result ), 'Disposable WordPress network is installed.' );
	}
	echo 'PASS: Multisite setup completed.' . PHP_EOL;
	return;
}
sw_mailgate_check( is_multisite(), 'WordPress loaded in real multisite mode.' );
$user_id = get_current_user_id();
add_filter( 'pre_wp_mail', '__return_true' );
$sites = array( 1 );
foreach ( array( 'delete-one', 'delete-two', 'preserve' ) as $path ) {
	$existing = get_sites( array( 'domain' => 'mailgate.test', 'path' => '/' . $path . '/', 'fields' => 'ids', 'number' => 1 ) );
	$id = $existing[0] ?? wpmu_create_blog( 'mailgate.test', '/' . $path . '/', $path, $user_id, array(), 1 );
	sw_mailgate_check( ! is_wp_error( $id ), 'Disposable subsite is available: ' . $path . '.' );
	$sites[] = (int) $id;
}
remove_filter( 'pre_wp_mail', '__return_true' );
$preserved = array( $sites[0], $sites[3] );
$snapshots = array();
foreach ( $sites as $id ) {
	switch_to_blog( $id );
	try {
		Installer::activate();
		$site_connections = new Connection_Repository( new Secret_Store() );
		delete_option( Connection_Repository::OPTION );
		$site_connections->save( array( 'id' => 'second', 'provider' => 'test', 'from_email' => 'sender@example.test' ), array( 'password' => 'secret-for-site-' . $id ) );
		Settings::save( array( 'retention' => 45 ) );
		Redirection_Settings::save( '', 'safe.example.test' );
		update_option( Installer::DELETE_DATA_OPTION, ! in_array( $id, $preserved, true ) );
		update_option( 'unrelated_fixture_option', 'keep-' . $id );
		sw_mailgate_check( wp_mail( 'recipient@example.test', 'Site fixture ' . $id, 'Body' ), 'Subsite mail data is seeded in its own context.' );
		$snapshots[ $id ] = array( 'connections' => get_option( Connection_Repository::OPTION ), 'secrets' => get_option( Secret_Store::OPTION ), 'logging' => get_option( Settings::OPTION ), 'redirection' => get_option( Redirection_Settings::OPTION ), 'count' => ( new Log_Repository() )->count() );
	} finally { restore_current_blog(); }
}
// Start inside an already-switched context, and prove restoration even if a site hook throws.
switch_to_blog( $sites[3] );
$original_blog = get_current_blog_id();
$original_prefix = $wpdb->prefix;
$original_stack = $GLOBALS['_wp_switched_stack'];
$fail_cleanup = static function ( $pre, $hook ) {
	if ( Logger::CRON_HOOK === $hook ) { throw new RuntimeException( 'cleanup-fixture-error' ); }
	return $pre;
};
add_filter( 'pre_clear_scheduled_hook', $fail_cleanup, 10, 2 );
try { Installer::uninstall(); }
catch ( RuntimeException $error ) { sw_mailgate_check( 'cleanup-fixture-error' === $error->getMessage(), 'Site hook failure is reported.' ); }
finally { remove_filter( 'pre_clear_scheduled_hook', $fail_cleanup, 10 ); }
sw_mailgate_check( $original_blog === get_current_blog_id() && $original_prefix === $wpdb->prefix && $original_stack === $GLOBALS['_wp_switched_stack'], 'Failed uninstall restores the caller blog, database prefix and switch stack.' );
Installer::uninstall();
sw_mailgate_check( $original_blog === get_current_blog_id() && $original_prefix === $wpdb->prefix && $original_stack === $GLOBALS['_wp_switched_stack'], 'Successful network uninstall also restores the caller context.' );
restore_current_blog();

foreach ( $sites as $id ) {
	switch_to_blog( $id );
	try {
		sw_mailgate_check( false === wp_next_scheduled( Logger::CRON_HOOK ), 'Plugin cron is cleared on site ' . $id . '.' );
		sw_mailgate_check( 'keep-' . $id === get_option( 'unrelated_fixture_option' ) && get_role( 'administrator' )->has_cap( 'read' ), 'Unrelated options and capabilities survive cleanup.' );
		if ( in_array( $id, $preserved, true ) ) {
			$snapshot = $snapshots[ $id ];
			sw_mailgate_check( $snapshot['connections'] === get_option( Connection_Repository::OPTION ) && $snapshot['secrets'] === get_option( Secret_Store::OPTION ) && $snapshot['logging'] === get_option( Settings::OPTION ) && $snapshot['redirection'] === get_option( Redirection_Settings::OPTION ) && $snapshot['count'] === ( new Log_Repository() )->count() && get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'Opt-out site retains its own credentials, options, roles and log table.' );
		} else {
			sw_mailgate_check( false === get_option( Connection_Repository::OPTION ) && false === get_option( Secret_Store::OPTION ) && false === get_option( Settings::OPTION ) && false === get_option( Redirection_Settings::OPTION ) && false === get_option( Installer::DELETE_DATA_OPTION ) && ! get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'Opt-in site removes its own credentials, options and plugin capability.' );
			sw_mailgate_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Log_Repository::table() ) ) ), 'Opt-in site drops its own log table.' );
		}
	} finally { restore_current_blog(); }
}
echo 'PASS: Real multisite uninstall regressions completed.' . PHP_EOL;
