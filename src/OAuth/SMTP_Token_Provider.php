<?php
/**
 * PHPMailer adapter for a token acquired through the encrypted OAuth client.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\OAuth;

use PHPMailer\PHPMailer\OAuthTokenProvider;

final class SMTP_Token_Provider implements OAuthTokenProvider {

	public function __construct( private readonly string $username, private readonly string $token ) {}

	public function getOauth64(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPMailer interface.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SASL XOAUTH2 wire format.
		return base64_encode( 'user=' . $this->username . "\x01auth=Bearer " . $this->token . "\x01\x01" );
	}
}
