<?php
/** M8 provider requirements, DNS diagnosis, cache, cron and authorization. @package SWMailgate */
declare(strict_types=1);
use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Dns\Dns_Checker;
use SWMailgate\Dns\Dns_Monitor;
use SWMailgate\Dns\Dns_Resolver;
use SWMailgate\Providers\Google_Provider;
use SWMailgate\Providers\Mailgun_Provider;
use SWMailgate\Providers\Office365_Provider;
use SWMailgate\Providers\Provider_Registry;
use SWMailgate\Providers\Smtp_OAuth_Provider;
use SWMailgate\Providers\Smtp_Provider;
use SWMailgate\Security\Secret_Store;

require __DIR__ . '/dns-fixtures.php';
// Exercise bootstrap callback order: WordPress needs the parent before submenu registration.
do_action( 'admin_menu' );
sw_mailgate_check( null !== get_plugin_page_hook( Dns_Monitor::SLUG, Admin_Page::SLUG ), 'The DNS submenu registers a callable page under its actual Mailgate parent.' );
$parent_file = Admin_Page::SLUG;
$submenu_file = Dns_Monitor::SLUG;
$plugin_page = Dns_Monitor::SLUG;
$pagenow = 'admin.php';
$typenow = '';
$_SERVER['PHP_SELF'] = '/wp-admin/admin.php';
ob_start();
require ABSPATH . 'wp-admin/menu-header.php';
$menu_html = ob_get_clean();
sw_mailgate_check( str_contains( $menu_html, "href='admin.php?page=sw-mailgate-dns'" ) && ! str_contains( $menu_html, "href='sw-mailgate-dns'" ), 'WordPress renders the DNS menu link through admin.php?page=sw-mailgate-dns.' );

$resolver = new class() extends Dns_Resolver {
	public array $records = array();
	public array $queries = array();
	public function lookup( string $name, string $type, bool $doh = false ): ?array {
		$this->queries[] = array( $name, $type, $doh );
		return $this->records[ $name ][ $type ] ?? array();
	}
};
$base = array(
	'example.test' => array( 'TXT' => array( 'v=spf1 include:_spf.google.com -all' ), 'MX' => array( 'mx.example.test' ) ),
	'_spf.google.com' => array( 'TXT' => array( 'v=spf1 ip4:192.0.2.1 -all' ) ),
	'google._domainkey.example.test' => array( 'TXT' => array( 'v=DKIM1; k=rsa; p=YWJjZA==' ) ),
	'_dmarc.example.test' => array( 'TXT' => array( 'v=DMARC1; p=reject; rua=mailto:reports@example.test' ) ),
	'smtp.example.test' => array( 'A' => array( '192.0.2.1' ) ),
	'1.2.0.192.in-addr.arpa' => array( 'PTR' => array( 'smtp.example.test' ) ),
);
$resolver->records = $base;
$checker = new Dns_Checker( $resolver );
$create = static function ( array $settings = array(), string $provider = 'google', string $from = 'sender@example.test', bool $force = true ): Connection {
	return new Connection( 'dns', '<script>unsafe</script>', $provider, true, $from, '', $force, true, $settings, new Secret_Store() );
};
$status = static function ( array $rows, string $name ): string {
	foreach ( $rows as $row ) { if ( $name === $row['check'] ) { return $row['status']; } }
	throw new LogicException( 'Missing DNS result: ' . $name );
};
$google = new Google_Provider();
$rows = $checker->check( $create(), $google );
sw_mailgate_check( 'good' === $status( $rows, 'SPF' ) && 'good' === $status( $rows, 'DKIM' ) && 'good' === $status( $rows, 'DMARC' ), 'Valid SPF, provider DKIM and reporting DMARC pass.' );
sw_mailgate_check( 'warning' === $status( $checker->check( $create( from: '' ), $google ), 'Sender' ), 'An unknown dynamic sender cannot pass DNS checks.' );
foreach ( array( array(), array( 'v=spf1 -all', 'v=spf1 include:_spf.google.com -all' ), array( 'v=spf1 include:_spf.google.com +all' ), array( 'v=spf1 include:_spf.google.com ?all' ), array( 'v=spf1 include:_spf.google.com all' ), array( 'v=spf1 -include:_spf.google.com -all' ), array( 'v=spf1 -all include:_spf.google.com' ), array( 'v=spf1 ip4:192.0.2.1 -all' ), array( 'v=spf1 invalid -all' ), array( 'v=spf1 include:%{d}.example.test -all' ) ) as $spf ) {
	$resolver->records = $base; $resolver->records['example.test']['TXT'] = $spf;
	sw_mailgate_check( 'error' === $status( $checker->check( $create(), $google ), 'SPF' ), 'Missing, duplicate, permissive, unauthorized or invalid SPF is not green.' );
}
$resolver->records = $base;
$resolver->records['_spf.google.com']['TXT'] = array( 'v=spf1 include:example.test -all' );
sw_mailgate_check( 'error' === $status( $checker->check( $create(), $google ), 'SPF' ) && count( $resolver->queries ) < 300, 'SPF include loops terminate and fail.' );
$resolver->records = $base;
$resolver->records['_spf.google.com']['TXT'] = array( 'v=spf1 ' . str_repeat( 'a ', 10 ) . '-all' );
sw_mailgate_check( 'error' === $status( $checker->check( $create(), $google ), 'SPF' ), 'More than ten recursive SPF lookups fail.' );
$resolver->records['_spf.google.com']['TXT'] = array( 'v=spf1 ' . str_repeat( 'mx ', 9 ) . '-all' );
sw_mailgate_check( 'good' === $status( $checker->check( $create(), $google ), 'SPF' ), 'Exactly ten recursive SPF lookups are permitted.' );
$resolver->records['_spf.google.com']['TXT'] = array( 'v=spf1 redirect=nested.example.test' );
$resolver->records['nested.example.test']['TXT'] = array( 'v=spf1 ip6:2001:db8::1 -all' );
sw_mailgate_check( 'good' === $status( $checker->check( $create(), $google ), 'SPF' ), 'Nested SPF redirect is followed.' );
$resolver->records = $base;
$resolver->records['custom._domainkey.example.test'] = array( 'CNAME' => array( 'key.provider.test' ) );
$resolver->records['key.provider.test'] = array( 'TXT' => array( 'v=DKIM1; p=YWJjZA==' ) );
sw_mailgate_check( 'good' === $status( $checker->check( $create( array( 'dns_dkim_selector' => 'custom' ) ), $google ), 'DKIM' ), 'A manual selector follows a DKIM CNAME to a public key.' );
$resolver->records['key.provider.test']['TXT'] = array( 'v=DKIM1; p=;' );
sw_mailgate_check( 'error' === $status( $checker->check( $create( array( 'dns_dkim_selector' => 'custom' ) ), $google ), 'DKIM' ), 'Revoked or missing DKIM keys fail.' );
sw_mailgate_check( 'warning' === $status( $checker->check( $create( provider: 'office365' ), new Office365_Provider() ), 'DKIM' ), 'Unknown tenant DKIM selectors require configuration.' );
foreach ( array( array(), array( 'v=DMARC1; p=invalid' ), array( 'v=DMARC1; p=none; p=reject; rua=mailto:reports@example.test' ), array( 'v=DMARC1; p=reject', 'v=DMARC1; p=reject' ) ) as $dmarc ) {
	$resolver->records = $base; $resolver->records['_dmarc.example.test']['TXT'] = $dmarc;
	sw_mailgate_check( 'error' === $status( $checker->check( $create(), $google ), 'DMARC' ), 'Missing, invalid and duplicate DMARC fail.' );
}
foreach ( array( 'v=DMARC1; p=none; rua=mailto:report@example.test', 'v=DMARC1; p=reject', 'v=DMARC1; p=reject; rua=invalid' ) as $dmarc ) {
	$resolver->records['_dmarc.example.test']['TXT'] = array( $dmarc );
	sw_mailgate_check( 'warning' === $status( $checker->check( $create(), $google ), 'DMARC' ), 'Monitoring-only policies and missing aggregate reporting are warnings.' );
}
$resolver->records = $base;
sw_mailgate_check( 'warning' === $status( $checker->check( $create( array( 'dns_dkim_domain' => 'other.test', 'dns_return_path' => 'bounce.other.test' ) ), $google ), 'Alignment' ), 'Unaligned fallback signing and envelope domains warn.' );
sw_mailgate_check( 'good' === $status( $checker->check( $create( array( 'dns_dkim_domain' => 'other.test', 'dns_return_path' => 'example.test' ) ), $google ), 'Alignment' ), 'An exact envelope domain matches configured From alignment.' );
sw_mailgate_check( 'warning' === $status( $checker->check( $create( force: false ), $google ), 'Dynamic From' ), 'Unforced senders carry a dynamic-domain warning.' );
$rows = $checker->check( $create( array( 'dns_optional' => '1', 'host' => 'smtp.example.test' ) ), $google );
sw_mailgate_check( 'good' === $status( $rows, 'MX' ) && 'good' === $status( $rows, 'PTR' ), 'Optional MX and forward-confirmed IPv4 PTR pass.' );
$rows = $checker->check( $create( array( 'dns_optional' => '1', 'host' => 'unknown.test' ) ), $google );
sw_mailgate_check( 'warning' === $status( $rows, 'PTR' ), 'Unresolvable SMTP PTR is a warning.' );
foreach ( array( new Smtp_Provider(), new Smtp_OAuth_Provider(), new Smtp_OAuth_Provider( false ), new Office365_Provider() ) as $provider_dns ) {
	$requirements = $provider_dns->dns_requirements( $create() );
	sw_mailgate_check( 'smtp' === $provider_dns->id() ? array() === $requirements : isset( $requirements['spf_include'] ), 'Each bundled provider supplies its known DNS requirements.' );
}
// Exercise native record normalization and distinguish failed DNS from absent records.
$native = new Dns_Resolver();
$GLOBALS['swmg_native_records'] = array(
	'txt.example.test' => array( DNS_TXT => array( array( 'entries' => array( 'v=spf1 ', '-all' ) ), array( 'txt' => 'fallback TXT' ), array() ) ),
	'cname.example.test' => array( DNS_CNAME => array( array( 'target' => 'key.example.test.' ) ) ),
	'a.example.test' => array( DNS_A => array( array( 'ip' => '192.0.2.1' ) ) ),
	'aaaa.example.test' => array( DNS_AAAA => array( array( 'ipv6' => '2001:db8::1' ) ) ),
	'empty.example.test' => array( DNS_TXT => array() ),
);
sw_mailgate_check( array( 'v=spf1 -all', 'fallback TXT' ) === $native->lookup( 'txt.example.test', 'TXT' ) && array( 'key.example.test' ) === $native->lookup( 'cname.example.test', 'CNAME' ), 'Native DNS combines TXT chunks and normalizes CNAMEs.' );
sw_mailgate_check( array( '192.0.2.1' ) === $native->lookup( 'a.example.test', 'A' ) && array( '2001:db8::1' ) === $native->lookup( 'aaaa.example.test', 'AAAA' ), 'Native DNS handles both address families.' );
sw_mailgate_check( array() === $native->lookup( 'empty.example.test', 'TXT' ) && null === $native->lookup( 'failed.example.test', 'TXT' ) && null === $native->lookup( 'https://invalid/path', 'TXT' ) && null === $native->lookup( 'example.test', 'INVALID' ), 'Absent, failed and invalid DNS queries remain distinct.' );
$rows = ( new Dns_Checker( $native ) )->check( $create(), $google );
sw_mailgate_check( 'warning' === $status( $rows, 'SPF' ) && 'warning' === $status( $rows, 'DMARC' ) && 'warning' === $status( $rows, 'DKIM' ), 'Unavailable local DNS is not reported as healthy or authoritative absence.' );
$http_calls = array(); $http_status = 200; $http_dns_status = 0; $http_error = false;
$http_fixture = static function ( $pre, array $args, string $url ) use ( &$http_calls, &$http_status, &$http_dns_status, &$http_error ) {
	$http_calls[] = array( $url, $args );
	if ( $http_error ) { return new WP_Error( 'unavailable', 'fixture' ); }
	if ( str_contains( $url, 'cloudflare-dns.com' ) ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'Status' => $http_dns_status, 'Answer' => array( array( 'type' => 16, 'data' => '"v=spf1 " "-all"' ), array( 'type' => 5, 'data' => 'key.example.test.' ) ) ) ), 'response' => array( 'code' => $http_status ) );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'sending_dns_records' => array( array( 'name' => 'example.test', 'record_type' => 'TXT', 'value' => 'v=spf1 include:mailgun.org -all' ), array( 'name' => 'smtp._domainkey.example.test', 'record_type' => 'CNAME', 'value' => 'key.mailgun.test' ), array( 'value' => array() ) ) ) ), 'response' => array( 'code' => $http_status ) );
};
add_filter( 'pre_http_request', $http_fixture, 10, 3 );
sw_mailgate_check( null === $native->lookup( 'unavailable.example.test', 'TXT' ) && array() === $http_calls, 'DoH is disabled by default and discloses no domain.' );
sw_mailgate_check( array( 'v=spf1 -all' ) === $native->lookup( 'unavailable.example.test', 'TXT', true ) && array( 'key.example.test' ) === $native->lookup( 'unavailable.example.test', 'CNAME', true ), 'Opted-in DoH fallback parses TXT chunks and CNAME records.' );
sw_mailgate_check( false === $http_calls[0][1]['redirection'] || 0 === $http_calls[0][1]['redirection'], 'DoH fallback uses a fixed TLS endpoint without redirects.' );
$http_status = 503;
sw_mailgate_check( null === $native->lookup( 'unavailable.example.test', 'TXT', true ), 'Failed DoH remains unknown.' );
$http_status = 200; $http_dns_status = 2;
sw_mailgate_check( null === $native->lookup( 'unavailable.example.test', 'TXT', true ), 'DNS SERVFAIL remains unknown.' );
$http_error = true;
sw_mailgate_check( null === $native->lookup( 'unavailable.example.test', 'TXT', true ), 'Transport errors remain unknown.' );
$http_error = false; $http_dns_status = 0;
$mailgun = new Mailgun_Provider();
$mg = $create( array( 'domain' => 'example.test', 'api_key' => 'fixture-key', 'region' => 'eu' ), 'mailgun' );
$requirements = $mailgun->dns_requirements( $mg );
sw_mailgate_check( 2 === count( $requirements['records'] ) && 'mailgun.org' === $requirements['spf_include'], 'Mailgun obtains exact expected sending records through its domain API.' );
$resolver->records['example.test']['TXT'] = array( 'v=spf1 include:mailgun.org -all' );
$resolver->records['mailgun.org']['TXT'] = array( 'v=spf1 ip4:192.0.2.1 -all' );
$resolver->records['smtp._domainkey.example.test']['CNAME'] = array( 'key.mailgun.test' );
$rows = $checker->check( $mg, $mailgun );
sw_mailgate_check( 'good' === $status( $rows, 'TXT example.test' ) && 'good' === $status( $rows, 'CNAME smtp._domainkey.example.test' ), 'Mailgun expected records are compared with resolved values.' );

$resolver->records['example.test']['TXT'] = array( 'v=spf1 include:mailgun.org ip4:192.0.2.2 ~all' );
sw_mailgate_check( 'good' === $status( $checker->check( $mg, $mailgun ), 'TXT example.test' ), 'Mailgun SPF requirements accept a merged provider record instead of requiring a duplicate.' );
$http_status = 401;
sw_mailgate_check( 'warning' === $status( $checker->check( $mg, $mailgun ), 'Provider' ) && isset( $mailgun->dns_requirements( $create( provider: 'mailgun' ) )['error'] ), 'Mailgun API and configuration failures remain actionable warnings.' );
$http_status = 200;
remove_filter( 'pre_http_request', $http_fixture, 10 );
// Cache identity, cron regression notification, real templates and authorization.
delete_option( Connection_Repository::OPTION ); delete_option( Dns_Monitor::OPTION ); delete_option( Dns_Monitor::STATE ); delete_transient( Dns_Monitor::CACHE );
$connections->save( array( 'id' => 'dns', 'label' => '<script>unsafe</script>', 'provider' => 'google', 'from_email' => 'sender@example.test', 'force_from' => true ) );
$registry_dns = new Provider_Registry( array( $google ) );
$monitor = new Dns_Monitor( $connections, $registry_dns, $checker );
$resolver->records = $base; $resolver->queries = array();
$monitor->results( $connections->find( 'dns' ) ); $queries = count( $resolver->queries );
$monitor->results( $connections->find( 'dns' ) );
sw_mailgate_check( $queries === count( $resolver->queries ), 'Unchanged DNS results are cached.' );
update_option( Dns_Monitor::OPTION, array( 'doh' => true ) );
$monitor->results( $connections->find( 'dns' ) );
sw_mailgate_check( count( $resolver->queries ) > $queries && true === end( $resolver->queries )[2], 'Resolver consent changes invalidate cached results.' );
$monitor->schedule(); $monitor->schedule();
sw_mailgate_check( (bool) wp_next_scheduled( Dns_Monitor::CRON_HOOK ), 'Weekly DNS cron is scheduled idempotently.' );
$monitor->weekly();
sw_mailgate_check( ! get_option( Dns_Monitor::STATE )['dns']['worse'] && 'recommended' === $monitor->site_test()['status'], 'First weekly baseline does not trigger a false deterioration notice.' );
$resolver->records['example.test']['TXT'] = array();
$monitor->weekly();
sw_mailgate_check( get_option( Dns_Monitor::STATE )['dns']['worse'] && 'recommended' === $monitor->site_test()['status'], 'Worsened DNS is reported by weekly monitoring and Site Health.' );
ob_start(); $monitor->notice(); $notice = ob_get_clean();
sw_mailgate_check( str_contains( $notice, 'DNS results have worsened' ), 'Managers see a DNS deterioration notice.' );
$monitor->weekly(); sw_mailgate_check( get_option( Dns_Monitor::STATE )['dns']['worse'], 'Unresolved deterioration notice survives subsequent weekly runs.' );
$resolver->records = $base; $monitor->weekly();
sw_mailgate_check( ! get_option( Dns_Monitor::STATE )['dns']['worse'], 'Improved DNS clears the deterioration notice.' );

// A new failure must be noticed even when an unrelated improvement offsets its score.
$previous_state = get_option( Dns_Monitor::STATE );
$previous_state['dns']['checks']['SPF'] = 'error';
$previous_state['dns']['checks']['DMARC'] = 'good';
$previous_state['dns']['worse'] = false;
update_option( Dns_Monitor::STATE, $previous_state );
$resolver->records['_dmarc.example.test']['TXT'] = array();
$monitor->weekly();
sw_mailgate_check( get_option( Dns_Monitor::STATE )['dns']['worse'], 'Per-check deterioration is detected even when aggregate scores offset.' );
$resolver->records = $base; $monitor->weekly();
$tests = $monitor->site_tests( array() );
sw_mailgate_check( is_callable( $tests['direct']['sw_mailgate_dns']['test'] ), 'Site Health registers a callable DNS test.' );
$monitor->menu();
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); $monitor->render(); $html = ob_get_clean();
sw_mailgate_check( str_contains( $html, 'Cloudflare' ) && str_contains( $html, '🟢' ) && ! str_contains( $html, '<script>unsafe</script>' ) && str_contains( $html, '&lt;script&gt;unsafe' ), 'DNS page renders consent, traffic-light results and escaped connection names.' );
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array(); $_REQUEST['_wpnonce'] = wp_create_nonce( 'sw_mailgate_dns' );
ob_start(); $monitor->render(); ob_end_clean();
sw_mailgate_check( false === get_option( Dns_Monitor::OPTION )['doh'], 'An authorized unchecked consent form disables DoH.' );
$_POST = array( 'doh' => '1' );
ob_start(); $monitor->render(); ob_end_clean();
sw_mailgate_check( true === get_option( Dns_Monitor::OPTION )['doh'], 'Explicit consent enables DoH.' );
wp_set_current_user( 0 );
ob_start(); $monitor->notice(); $notice = ob_get_clean();
sw_mailgate_check( '' === $notice, 'DNS notices are restricted to managers.' );
add_filter( 'wp_die_handler', static fn() => static function (): void { throw new RuntimeException( 'denied' ); } );
try { $monitor->render(); throw new LogicException( 'Unauthorized DNS access accepted.' ); } catch ( RuntimeException $error ) { sw_mailgate_check( 'denied' === $error->getMessage(), 'DNS rendering and settings require management capability.' ); }
