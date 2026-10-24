<?php
/**
 * Contract for a mail transport. Register additional providers with the `sw_mailgate_providers` filter.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use SWMailgate\Connections\Connection;
use SWMailgate\Mail\Envelope;
use SWMailgate\Mail\Mail_Catcher;

interface Provider {

	/** Stable machine name stored with each connection, e.g. "smtp". */
	public function id(): string;

	public function label(): string;

	/**
	 * @return Field[]
	 */
	public function fields(): array;

	/**
	 * Configures the mailer before the MIME message is built. HTTP API providers usually do nothing here;
	 * SMTP switches the mailer to SMTP and sets the credentials.
	 */
	public function prepare( Mail_Catcher $mailer, Connection $connection ): void;

	/**
	 * Sends the prepared message. Must not throw; failures are returned as Send_Result.
	 */
	public function send( Envelope $envelope, Connection $connection ): Send_Result;

	/**
	 * Checks credentials and reachability without sending a message.
	 */
	public function test_connection( Connection $connection ): Send_Result;
}
