<?php
/**
 * Outcome of one send or connection-test attempt.
 *
 * The error class decides whether the failover chain may try the next connection:
 * transient and auth errors can fail over. Permanent errors concern the message itself;
 * partial and uncertain delivery must never replay the whole message automatically.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

final class Send_Result {

	public const TRANSIENT = 'transient';
	public const AUTH      = 'auth';
	public const PERMANENT = 'permanent';
	public const PARTIAL   = 'partial';
	public const UNCERTAIN = 'uncertain';

	/**
	 * @param list<string> $debug Diagnostic lines without credentials.
	 */
	private function __construct(
		private readonly bool $success,
		private readonly string $error_class,
		private readonly string $message,
		private readonly string $provider_message_id,
		private readonly array $debug,
		private readonly float $duration = 0.0,
		private readonly bool $ambiguous = false
	) {
	}

	/**
	 * @param list<string> $debug
	 */
	public static function success( string $provider_message_id = '', array $debug = array() ): self {
		return new self( true, '', '', $provider_message_id, $debug );
	}

	/**
	 * @param list<string> $debug
	 */
	public static function failure( string $error_class, string $message, array $debug = array() ): self {
		return new self( false, $error_class, $message, '', $debug );
	}

	/**
	 * Classifies an HTTP API response status.
	 */
	public static function class_for_http_status( int $status ): string {
		return match ( true ) {
			401 === $status, 403 === $status => self::AUTH,
			408 === $status, 429 === $status, $status >= 500, 0 === $status => self::TRANSIENT,
			default => self::PERMANENT,
		};
	}

	public function with_duration( float $seconds ): self {
		return new self( $this->success, $this->error_class, $this->message, $this->provider_message_id, $this->debug, $seconds, $this->ambiguous );
	}

	/**
	 * Marks a failure after which the provider may already have accepted the message (e.g. a timeout
	 * while waiting for the HTTP response). The dispatcher decides whether failover is still allowed.
	 */
	public function as_ambiguous(): self {
		return new self( $this->success, $this->error_class, $this->message, $this->provider_message_id, $this->debug, $this->duration, true );
	}

	public function is_ambiguous(): bool {
		return $this->ambiguous;
	}

	public function reclassify( string $error_class, string $message ): self {
		return new self( $this->success, $error_class, $message, $this->provider_message_id, $this->debug, $this->duration, $this->ambiguous );
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function allows_failover(): bool {
		return ! $this->success && in_array( $this->error_class, array( self::TRANSIENT, self::AUTH ), true );
	}

	public function error_class(): string {
		return $this->error_class;
	}

	public function message(): string {
		return $this->message;
	}

	public function provider_message_id(): string {
		return $this->provider_message_id;
	}

	/**
	 * @return list<string>
	 */
	public function debug(): array {
		return $this->debug;
	}

	public function duration(): float {
		return $this->duration;
	}
}
