<?php
/** Optional provider DNS requirements.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;

interface Dns_Capable {
	/** @return array{spf_include?: string, dkim_domain?: string, return_path?: string, records?: list<array{name: string, type: string, value: string}>, error?: string} */
	public function dns_requirements( Connection $connection ): array;
}
