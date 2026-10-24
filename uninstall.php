<?php
/**
 * Uninstall handler. Data is only removed when the user explicitly enabled "delete data on uninstall".
 *
 * @package SWMailgate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

\SWMailgate\Installer::uninstall();
