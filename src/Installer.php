<?php
/**
 * Activation, upgrades and uninstall.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate;

use SWMailgate\Connections\Connection_Repository;
use SWMailgate\Security\Secret_Store;
use SWMailgate\Logging\Logger;
use SWMailgate\Logging\Log_Repository;
use SWMailgate\Logging\Settings;
use SWMailgate\Mail\Redirection_Settings;
use SWMailgate\Routing\Circuit_Breaker;
use SWMailgate\Routing\Failover_Settings;
use SWMailgate\Routing\Retry_Queue;

final class Installer {

	/** Bump when the schema or stored data changes; upgrade() runs once per change. */
	public const DB_VERSION = 3;

	private const VERSION_OPTION = 'sw_mailgate_db_version';

	/** When true, uninstall removes every option, secret and table of the plugin. */
	public const DELETE_DATA_OPTION = 'sw_mailgate_delete_data';

	public static function activate(): void {
		self::upgrade();
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::upgrade();
		}
	}

	private static function upgrade(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = Log_Repository::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL,
			connection_id varchar(191) NOT NULL,
			provider varchar(64) NOT NULL,
			attempt_group varchar(36) NOT NULL,
			attempt_number int unsigned NOT NULL,
			from_email varchar(320) NOT NULL,
			recipients longtext NOT NULL,
			original_to longtext NOT NULL,
			subject text NOT NULL,
			error_class varchar(20) NOT NULL,
			error_message text NOT NULL,
			duration decimal(12,6) NOT NULL DEFAULT 0,
			provider_message_id text NOT NULL,
			caller varchar(255) NOT NULL,
			address_hashes longtext NOT NULL,
			payload longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY attempt_group (attempt_group),
			KEY status_created (status,created_at),
			KEY connection_id (connection_id)
			) $collate;"
		);
		$queue = Retry_Queue::table();
		dbDelta(
			"CREATE TABLE $queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			next_run_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			retries int unsigned NOT NULL DEFAULT 0,
			attempt_group varchar(36) NOT NULL,
			attempt_number int unsigned NOT NULL,
			locked_until datetime NULL,
			last_error text NOT NULL,
			address_hashes longtext NOT NULL,
			payload longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY next_run_at (next_run_at)
			) $collate;"
		);
		foreach ( array( $table, $queue ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the schema exists before marking the migration complete.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
				return;
			}
		}
		$role = get_role( 'administrator' );
		if ( null !== $role && ! $role->has_cap( Capabilities::MANAGE ) ) {
			$role->add_cap( Capabilities::MANAGE );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		Logger::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Logger::CRON_HOOK );
		wp_clear_scheduled_hook( Retry_Queue::CRON_HOOK );
	}

	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}
		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => 100,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					self::uninstall_site();
				} finally {
					restore_current_blog();
				}
			}
			$offset     += 100;
			$batch_count = count( $sites );
		} while ( 100 === $batch_count );
	}

	private static function uninstall_site(): void {
		global $wpdb;
		self::deactivate();
		if ( ! get_option( self::DELETE_DATA_OPTION ) ) {
			return;
		}

		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( Capabilities::MANAGE );
		}

		delete_option( Connection_Repository::OPTION );
		delete_option( Secret_Store::OPTION );
		delete_option( self::VERSION_OPTION );
		delete_option( self::DELETE_DATA_OPTION );
		delete_option( Settings::OPTION );
		delete_option( Redirection_Settings::OPTION );
		delete_option( Failover_Settings::OPTION );
		delete_option( Circuit_Breaker::OPTION );
		foreach ( array( Log_Repository::table(), Retry_Queue::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove the owned tables only after the user enabled data deletion.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
