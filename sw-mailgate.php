<?php
/**
 * Plugin Name:       SW Mailgate
 * Plugin URI:        https://stefanwarnat.de
 * Description:       Send WordPress mail through SMTP, Mailgun, Google or Microsoft 365 with failover, redirection, logging and DNS checks.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Stefan Warnat
 * Author URI:        https://stefanwarnat.de
 * License:           MIT
 * Text Domain:       sw-mailgate
 * Domain Path:       /languages
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;

define( 'SW_MAILGATE_VERSION', '0.2.0' );
define( 'SW_MAILGATE_FILE', __FILE__ );
define( 'SW_MAILGATE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SW_MAILGATE_URL', plugin_dir_url( __FILE__ ) );
define( 'SW_MAILGATE_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SW_MAILGATE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'SW Mailgate requires PHP %1$s or newer. You are running PHP %2$s.', 'sw-mailgate' ),
						SW_MAILGATE_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

$sw_mailgate_autoload = SW_MAILGATE_DIR . 'vendor-prefixed/autoload.php';
if ( ! is_readable( $sw_mailgate_autoload ) ) {
	// Source checkouts can use Composer directly; release ZIPs only ship Mozart's loader.
	$sw_mailgate_autoload = SW_MAILGATE_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $sw_mailgate_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SW Mailgate: the vendor directory is missing. Install the release package or run "composer install".', 'sw-mailgate' )
			);
		}
	);
	return;
}
$sw_mailgate_loader = require $sw_mailgate_autoload;
// Mozart's generated loader knows the dependencies; add the plugin's own code too.
$sw_mailgate_loader->addPsr4( 'SWMailgate\\', SW_MAILGATE_DIR . 'src/' );
unset( $sw_mailgate_autoload, $sw_mailgate_loader );

register_activation_hook( __FILE__, array( \SWMailgate\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SWMailgate\Installer::class, 'deactivate' ) );

\SWMailgate\Plugin::instance()->boot();
