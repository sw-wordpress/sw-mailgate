<?php
/**
 * Local plugin identities. No requests or option reads.
 *
 * @package sw-mailgate
 */

return array(
	// uninstall.php runs without the plugin's constants.
	defined( 'SW_MAILGATE_FILE' ) ? plugin_basename( SW_MAILGATE_FILE ) : 'sw-mailgate/sw-mailgate.php' => array(
		'slug'    => 'sw-mailgate',
		'version' => defined( 'SW_MAILGATE_VERSION' ) ? SW_MAILGATE_VERSION : '0',
	),
);
