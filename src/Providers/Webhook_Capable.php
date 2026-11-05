<?php
/** Webhook transport contract.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;

interface Webhook_Capable {
	public function register_webhook( Connection $connection, string $url ): Send_Result;
	/** Returns a verified, opaque replay identity, or false on authentication failure. */
	public function verify_request( Connection $connection, \WP_REST_Request $request ): string|false;
	/** @return list<array{id: string, message_id: string, status: string}> */
	public function parse_events( \WP_REST_Request $request ): array;
}
