<?php
/**
 * Non-autoloaded, network-wide state and compare-and-delete locks.
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

namespace SWMailgate\Update;

final class Store {

	public const STATE = 'swmg_verified_github_updates_v1';
	public const LOCK  = 'swmg_verified_github_updates_lock_v1';

	private static function name( bool $lock = false ): string {
		return Manifest::source()[ $lock ? 'lock_key' : 'state_key' ];
	}

	/**
	 * Persistent state (survives transient and object-cache flushes).
	 *
	 * @return array<string,mixed>
	 */
	public static function read(): array {
		$value = get_site_option( self::name(), array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $state New state.
	 */
	public static function write( array $state ): void {
		if ( is_multisite() ) {
			update_site_option( self::name(), $state );
		} else {
			update_option( self::name(), $state, false );
		}
	}

	/**
	 * Name of the database-wide mutex that makes lease acquisition atomic (GET_LOCK names are server wide).
	 */
	public static function guard_name(): string {
		global $wpdb;
		return 'swmg_upd_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->base_prefix . ':' . ( is_multisite() ? get_current_network_id() : $wpdb->prefix ) . ':' . self::name( true ) ), 0, 32 );
	}

	/**
	 * Drops cached copies so lease decisions always use the database row, not a request-local object cache.
	 */
	private static function flush_cache(): void {
		if ( is_multisite() ) {
			wp_cache_delete( get_current_network_id() . ':' . self::name( true ), 'site-options' );
			wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
		} else {
			wp_cache_delete( self::name( true ), 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Takes the lease unless another unexpired owner holds it.
	 *
	 * WordPress options have no unique key (sitemeta not at all) and add_site_option() reads before it inserts, so
	 * check-then-insert is serialised with a short MySQL/MariaDB named lock. Servers without GET_LOCK() support
	 * (NULL result, e.g. some proxies) fail closed.
	 *
	 * @return array{token:string,expires:int}|false
	 */
	public static function acquire(): array|false {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Named lock; no WordPress API exists for it.
		$mutex = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 2 )', self::guard_name() ) );
		if ( 1 !== (int) $mutex ) {
			return false;
		}
		try {
			self::flush_cache();
			$previous = get_site_option( self::name( true ), false );
			if ( is_array( $previous ) && ( $previous['expires'] ?? PHP_INT_MAX ) < time() ) {
				self::release( $previous );
			}
			$lock  = array(
				'token'   => wp_generate_password( 32, false ),
				'expires' => time() + 120,
			);
			$added = is_multisite() ? add_site_option( self::name( true ), $lock ) : add_option( self::name( true ), $lock, '', false );
			return $added ? $lock : false;
		} finally {
			if ( null !== $mutex ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::guard_name() ) );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether this request still owns an unexpired lease (read fresh from the database).
	 *
	 * @param array<string,mixed> $lock Exact lease acquired by this request.
	 */
	public static function owns( array $lock ): bool {
		self::flush_cache();
		return get_site_option( self::name( true ), false ) === $lock && time() < (int) $lock['expires'];
	}

	/**
	 * Atomic ownership check: an expired request must never delete its successor's lock.
	 * Uses conditional WordPress option SQL because the option API has no compare-and-delete.
	 *
	 * @param array<string,mixed> $lock Exact value originally acquired.
	 */
	public static function release( array $lock ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic platform lock; values are prepared, table identifiers are WordPress-owned.
		if ( is_multisite() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s AND meta_value = %s", get_current_network_id(), self::name( true ), maybe_serialize( $lock ) ) );
			wp_cache_delete( get_current_network_id() . ':' . self::name( true ), 'site-options' );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::name( true ), maybe_serialize( $lock ) ) );
			wp_cache_delete( self::name( true ), 'options' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
