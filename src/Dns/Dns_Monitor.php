<?php
/** Cached diagnostics, opt-in resolver settings and weekly monitoring.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Dns;

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Capabilities;
use SWMailgate\Connections\Connection;
use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Providers\Provider_Registry;

final class Dns_Monitor {
	public const CRON_HOOK = 'sw_mailgate_dns_weekly';
	public const OPTION    = 'sw_mailgate_dns_settings';
	public const STATE     = 'sw_mailgate_dns_state';
	public const CACHE     = 'sw_mailgate_dns_results';
	public const SLUG      = 'sw-mailgate-dns';

	public function __construct( private readonly Connection_Repository $connections, private readonly Provider_Registry $providers, private readonly Dns_Checker $checker = new Dns_Checker() ) {}

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'weekly' ) );
		add_filter( 'site_status_tests', array( $this, 'site_tests' ) );
		// Register after Admin_Page creates the parent, so WordPress resolves the page hook.
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	public function menu(): void {
		add_submenu_page( Admin_Page::SLUG, __( 'DNS checks', 'sw-mailgate' ), __( 'DNS checks', 'sw-mailgate' ), Capabilities::MANAGE, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * @return list<array{check: string, status: string, expected: string, recommendation: string}> */
	public function results( Connection $connection, bool $refresh = false ): array {
		$settings  = (array) get_option( self::OPTION, array() );
		$doh       = true === ( $settings['doh'] ?? false );
		$effective = array();
		foreach ( array( 'from_email', 'domain', 'region', 'host', 'dns_spf_include', 'dns_dkim_domain', 'dns_dkim_selector', 'dns_return_path', 'dns_optional', 'api_key' ) as $field ) {
			$effective[] = 'from_email' === $field ? $connection->from_email() : $connection->get( $field );
		}
		$key   = hash_hmac( 'sha256', \SWMailgate\Json::encode( array( $connection->to_array(), $effective, $doh ) ), wp_salt( 'auth' ) );
		$cache = get_transient( self::CACHE );
		$cache = is_array( $cache ) ? $cache : array();
		$entry = $cache[ $connection->id() ] ?? null;
		if ( ! $refresh && is_array( $entry ) && ( $entry['key'] ?? '' ) === $key && ( $entry['until'] ?? 0 ) > time() ) {
			return $entry['rows'];
		}
		$rows                       = $this->checker->check( $connection, $this->providers->get( $connection->provider() ), $doh );
		$cache[ $connection->id() ] = array(
			'key'   => $key,
			'until' => time() + HOUR_IN_SECONDS,
			'rows'  => $rows,
		);
		$ids                        = array_map( static fn( Connection $c ): string => $c->id(), $this->connections->all() );
		$cache                      = array_intersect_key( $cache, array_fill_keys( $ids, true ) );
		set_transient( self::CACHE, $cache, HOUR_IN_SECONDS );
		return $rows;
	}

	/** @param list<array{check: string, status: string, expected: string, recommendation: string}> $rows */
	private function score( array $rows ): int {
		$score = 0;
		foreach ( $rows as $row ) {
			$score += match ( $row['status'] ) {
				'error' => 2, 'warning' => 1, default => 0 };
		}
		return $score;
	}

	public function weekly(): void {
		$previous = (array) get_option( self::STATE, array() );
		$state    = array();
		foreach ( $this->connections->chain() as $connection ) {
			$rows      = $this->results( $connection, true );
			$score     = $this->score( $rows );
			$checks    = array_column( $rows, 'status', 'check' );
			$old       = $previous[ $connection->id() ] ?? null;
			$regressed = false;
			$ranks     = array(
				'good'    => 0,
				'warning' => 1,
				'error'   => 2,
			);
			foreach ( $checks as $name => $status ) {
				$before    = is_array( $old ) ? ( $old['checks'][ $name ] ?? $status ) : $status;
				$regressed = $regressed || $ranks[ $status ] > $ranks[ $before ];
			}
			$state[ $connection->id() ] = array(
				'checks' => $checks,
				'score'  => $score,
				'worse'  => is_array( $old ) && ( $regressed || ( ! empty( $old['worse'] ) && $score >= (int) $old['score'] ) ),
			);
		}
		update_option( self::STATE, $state, false );
	}

	public function notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$state = (array) get_option( self::STATE, array() );
		foreach ( $this->connections->chain() as $connection ) {
			if ( ! empty( $state[ $connection->id() ]['worse'] ) ) {
				echo '<div class="notice notice-warning"><p><a href="' . esc_url( Admin_Page::url( array(), self::SLUG ) ) . '">' . esc_html__( 'SW Mailgate: DNS results have worsened. Review sender authentication.', 'sw-mailgate' ) . '</a></p></div>';
				return;
			}
		}
	}

	/** @param array<mixed> $tests
	 * @return array<mixed> */
	public function site_tests( array $tests ): array {
		$tests['direct']['sw_mailgate_dns'] = array(
			'label' => __( 'Mailgate sender DNS', 'sw-mailgate' ),
			'test'  => array( $this, 'site_test' ),
		);
		return $tests;
	}

	/**
	 * @return array<string, mixed> */
	public function site_test(): array {
		$score = 0;
		foreach ( $this->connections->chain() as $connection ) {
			$score += $this->score( $this->results( $connection ) );
		}
		return array(
			'label'       => __( 'Mailgate sender DNS', 'sw-mailgate' ),
			'status'      => $score > 0 ? 'recommended' : 'good',
			'badge'       => array(
				'label' => 'Mailgate',
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Checks configured sender domains. Confirm authentication and fallback alignment in a received test mail.', 'sw-mailgate' ) . '</p>',
			'actions'     => '<a href="' . esc_url( Admin_Page::url( array(), self::SLUG ) ) . '">' . esc_html__( 'Review DNS checks', 'sw-mailgate' ) . '</a>',
			'test'        => 'sw_mailgate_dns',
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sw-mailgate' ), '', array( 'response' => 403 ) );
		}
		$refresh = false;
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			check_admin_referer( 'sw_mailgate_dns' );
			update_option( self::OPTION, array( 'doh' => isset( $_POST['doh'] ) ), false );
			$refresh = true;
		}
		$settings    = (array) get_option( self::OPTION, array() );
		$connections = $this->connections->all();
		include SW_MAILGATE_DIR . 'templates/admin/dns.php';
	}
}
