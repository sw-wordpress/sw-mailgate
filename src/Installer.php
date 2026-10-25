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

final class Installer {

	/** Bump when the schema or stored data changes; upgrade() runs once per change. */
	public const DB_VERSION = 2;

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify the schema exists before marking the migration complete.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
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
	}

	public static function uninstall(): void {
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove the owned table only after the user enabled data deletion.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Log_Repository::table() ) );
	}
}
