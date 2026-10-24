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

final class Installer {

	/** Bump when the schema or stored data changes; upgrade() runs once per change. */
	public const DB_VERSION = 1;

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
		$role = get_role( 'administrator' );
		if ( null !== $role && ! $role->has_cap( Capabilities::MANAGE ) ) {
			$role->add_cap( Capabilities::MANAGE );
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function uninstall(): void {
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
	}
}
