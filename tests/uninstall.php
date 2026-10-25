<?php
/**
 * Smoke-test the real uninstall entry point without a WordPress database.
 * Usage: php tests/uninstall.php [plugin-directory] [delete|preserve|default]
 * Run each scenario in a separate PHP process to isolate the autoloader.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

define( 'WP_UNINSTALL_PLUGIN', true );

$sw_mailgate_test_root = $argv[1] ?? dirname( __DIR__ );
$sw_mailgate_test_mode = $argv[2] ?? 'delete';
if ( ! in_array( $sw_mailgate_test_mode, array( 'delete', 'preserve', 'default' ), true ) ) {
	throw new InvalidArgumentException( 'Expected delete, preserve or default.' );
}

// Keep these literal: the test must not preload plugin classes or its autoloader.
$sw_mailgate_test_options = array(
	'sw_mailgate_connections' => array( 'smtp' => array( 'enabled' => true ) ),
	'sw_mailgate_secrets'     => array( 'smtp_password' => 'encrypted-test-secret' ),
	'sw_mailgate_db_version'  => 1,
	'unrelated_option'       => 'keep',
);
if ( 'default' !== $sw_mailgate_test_mode ) {
	$sw_mailgate_test_options['sw_mailgate_delete_data'] = 'delete' === $sw_mailgate_test_mode;
}
$sw_mailgate_test_original = $sw_mailgate_test_options;

$sw_mailgate_test_role = new class() {
	public array $capabilities = array( 'manage_sw_mailgate' => true, 'read' => true );

	public function remove_cap( string $capability ): void {
		unset( $this->capabilities[ $capability ] );
	}
};

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['sw_mailgate_test_options'][ $name ] ?? $default;
}

function delete_option( string $name ): bool {
	unset( $GLOBALS['sw_mailgate_test_options'][ $name ] );
	return true;
}

function wp_roles(): object {
	return (object) array( 'role_objects' => array( $GLOBALS['sw_mailgate_test_role'] ) );
}

require $sw_mailgate_test_root . '/uninstall.php';

$sw_mailgate_test_expected = 'delete' === $sw_mailgate_test_mode
	? array( 'unrelated_option' => 'keep' )
	: $sw_mailgate_test_original;
if ( $sw_mailgate_test_options !== $sw_mailgate_test_expected ) {
	throw new RuntimeException( 'Uninstall did not respect the data deletion preference.' );
}
$sw_mailgate_test_expected_caps = 'delete' === $sw_mailgate_test_mode
	? array( 'read' => true )
	: array( 'manage_sw_mailgate' => true, 'read' => true );
if ( $sw_mailgate_test_role->capabilities !== $sw_mailgate_test_expected_caps ) {
	throw new RuntimeException( 'Uninstall did not respect the capability deletion preference.' );
}

echo 'PASS: Uninstall (' . $sw_mailgate_test_mode . ').' . PHP_EOL;
