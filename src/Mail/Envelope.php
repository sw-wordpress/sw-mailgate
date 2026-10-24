<?php
/**
 * A message prepared for one connection attempt: the complete MIME message plus envelope data.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Mail;

final class Envelope {

	public function __construct( private readonly Mail_Catcher $mailer ) {
	}

	/**
	 * The mailer after preSend(). SMTP sends it directly via postSend().
	 */
	public function mailer(): Mail_Catcher {
		return $this->mailer;
	}

	/**
	 * Complete RFC 5322 message with CRLF line endings.
	 *
	 * @param bool $include_bcc Add the Bcc header, for APIs that take recipients from the message itself.
	 */
	public function mime( bool $include_bcc = false ): string {
		$mime = $this->mailer->getSentMIMEMessage();
		return $include_bcc ? $this->mailer->bcc_header() . $mime : $mime;
	}

	public function from(): string {
		return $this->mailer->From;
	}

	public function from_name(): string {
		return $this->mailer->FromName;
	}

	public function subject(): string {
		return $this->mailer->Subject;
	}

	public function message_id(): string {
		return $this->mailer->getLastMessageID();
	}

	/**
	 * @return list<string>
	 */
	public function to(): array {
		return self::emails( $this->mailer->getToAddresses() );
	}

	/**
	 * @return list<string>
	 */
	public function cc(): array {
		return self::emails( $this->mailer->getCcAddresses() );
	}

	/**
	 * @return list<string>
	 */
	public function bcc(): array {
		return self::emails( $this->mailer->getBccAddresses() );
	}

	/**
	 * @return list<string> To, Cc and Bcc without duplicates.
	 */
	public function recipients(): array {
		return array_values( array_unique( array_merge( $this->to(), $this->cc(), $this->bcc() ) ) );
	}

	/**
	 * @param array<int, array{0: string, 1?: string}> $addresses
	 * @return list<string>
	 */
	private static function emails( array $addresses ): array {
		return array_values( array_map( static fn( array $address ): string => (string) $address[0], $addresses ) );
	}
}
