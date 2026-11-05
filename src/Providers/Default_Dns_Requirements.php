<?php
/** Provider guidance without guessing tenant-specific DKIM keys.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;

trait Default_Dns_Requirements {
	public function dns_requirements( Connection $connection ): array {
		$domain = \SWMailgate\Dns\Dns_Checker::sender_domain( $connection );
		return match ( $this->id() ) {
			'google', 'google_smtp' => array(
				'spf_include' => '_spf.google.com',
				'dkim_domain' => $domain,
			),
			'office365', 'office365_smtp' => array(
				'spf_include' => 'spf.protection.outlook.com',
				'dkim_domain' => $domain,
			),
			default => array(),
		};
	}
}
