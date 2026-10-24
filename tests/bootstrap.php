<?php
/**
 * Smoke-test a source checkout or staged release without a WordPress database.
 * Usage: php tests/bootstrap.php [plugin-directory]
 *
 * @package SWMailgate
 */

declare(strict_types=1);

define( 'ABSPATH', '/test/wordpress/' );

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/sw-mailgate/';
}

function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['test_actions'][ $hook ][] = $callback;
}

function register_activation_hook( string $file, mixed $callback ): void {
	$GLOBALS['test_activation'] = $callback;
}

function is_admin(): bool {
	return true;
}

$sw_mailgate_test_root = $argv[1] ?? dirname( __DIR__ );
require $sw_mailgate_test_root . '/sw-mailgate.php';

if ( ! class_exists( \SWMailgate\Plugin::class ) || ! is_callable( $GLOBALS['test_activation'] ?? null ) ) {
	throw new RuntimeException( 'Plugin classes or activation callback could not be loaded.' );
}

foreach ( array( 'init', 'plugins_loaded', 'admin_menu', 'admin_post_sw_mailgate_send_test' ) as $sw_mailgate_test_hook ) {
	if ( empty( $GLOBALS['test_actions'][ $sw_mailgate_test_hook ] ) ) {
		throw new RuntimeException( 'Plugin hook was not registered: ' . $sw_mailgate_test_hook );
	}
	foreach ( $GLOBALS['test_actions'][ $sw_mailgate_test_hook ] as $sw_mailgate_test_callback ) {
		if ( ! is_callable( $sw_mailgate_test_callback ) ) {
			throw new RuntimeException( 'Plugin callback could not be loaded: ' . $sw_mailgate_test_hook );
		}
	}
}

echo 'PASS: Plugin bootstrap, activation callback, mailer hooks and admin integration.' . PHP_EOL;
