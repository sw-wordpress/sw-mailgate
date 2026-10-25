<?php
/**
 * Uninstall handler. Data is only removed when the user explicitly enabled "delete data on uninstall".
 *
 * @package SWMailgate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sw_mailgate_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! is_readable( $sw_mailgate_autoload ) ) {
	// Source checkouts use Composer directly; releases ship Mozart's loader.
	$sw_mailgate_autoload = __DIR__ . '/vendor/autoload.php';
}
$sw_mailgate_loader = require $sw_mailgate_autoload;
// Mozart's loader only knows the dependencies, not the plugin's own classes.
$sw_mailgate_loader->addPsr4( 'SWMailgate\\', __DIR__ . '/src/' );
unset( $sw_mailgate_autoload, $sw_mailgate_loader );

\SWMailgate\Installer::uninstall();
