<?php
/**
 * PHPStan bootstrap: constants defined by sw-mailgate.php at runtime.
 *
 * Optional wp-config constants (SW_MAILGATE_KEY, SW_MAILGATE_LOG_ENABLED, SW_MAILGATE_REDIRECT_TO, ...)
 * are deliberately not declared: the code checks them with defined() first.
 *
 * @package SWMailgate
 */

define( 'SW_MAILGATE_VERSION', '0.0.0' );
define( 'SW_MAILGATE_FILE', __DIR__ . '/../../sw-mailgate.php' );
define( 'SW_MAILGATE_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'SW_MAILGATE_URL', 'https://example.org/wp-content/plugins/sw-mailgate/' );
define( 'SW_MAILGATE_MIN_PHP', '8.2' );
// Defined by wp-settings.php.
define( 'WPINC', 'wp-includes' );
