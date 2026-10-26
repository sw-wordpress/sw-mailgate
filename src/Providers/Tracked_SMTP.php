<?php
/**
 * Capture SMTP transaction outcomes before QUIT/RSET can clear PHPMailer's error.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

use PHPMailer\PHPMailer\SMTP;

class Tracked_SMTP extends SMTP {

	private int $accepted   = 0;
	private array $rejected = array();
	private bool $submitted = false;
	private bool $delivered = false;
	private int $data_code  = 0;

	public function reset_tracking(): void {
		$this->accepted  = 0;
		$this->rejected  = array();
		$this->submitted = false;
		$this->delivered = false;
		$this->data_code = 0;
	}

	public function recipient( $address, $dsn = '' ) {
		$result = parent::recipient( $address, $dsn );
		if ( $result ) {
			++$this->accepted;
		} else {
			$this->rejected[] = (int) ( $this->getError()['smtp_code'] ?? 0 );
		}
		return $result;
	}

	protected function sendCommand( $command, $commandstring, $expect ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPMailer override.
		if ( 'DATA END' === $command ) {
			// Mark before writing the terminator: a missing final response is ambiguous.
			$this->submitted = true;
		}
		$result = parent::sendCommand( $command, $commandstring, $expect );
		if ( 'DATA' === $command || 'DATA END' === $command ) {
			$this->data_code = $result ? 0 : (int) ( $this->getError()['smtp_code'] ?? 0 );
			if ( 'DATA END' === $command ) {
				$this->delivered = (bool) $result;
			}
		}
		return $result;
	}

	/** Counts and status codes only; no recipient addresses or message content. */
	public function outcome(): array {
		return array(
			'accepted'  => $this->accepted,
			'rejected'  => $this->rejected,
			'submitted' => $this->submitted,
			'delivered' => $this->delivered,
			'data_code' => $this->data_code,
		);
	}
}
