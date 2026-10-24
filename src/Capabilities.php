<?php
/**
 * Capabilities used by the plugin.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate;

final class Capabilities {

	/** Configure connections, send test mails and read the log. Granted to administrators on activation. */
	public const MANAGE = 'manage_sw_mailgate';
}
