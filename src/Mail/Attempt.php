<?php
/**
 * Record of one connection attempt, passed to the `sw_mailgate_attempt` action (logging, notices).
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

use SWMailgate\Connections\Connection;
use SWMailgate\Providers\Send_Result;

final class Attempt {

	/**
	 * @param string       $group      Shared by all attempts for the same wp_mail() call.
	 * @param list<string> $recipients
	 */
	public function __construct(
		public readonly string $group,
		public readonly int $number,
		public readonly Connection $connection,
		public readonly Send_Result $result,
		public readonly string $from,
		public readonly array $recipients,
		public readonly string $subject
	) {
	}
}
