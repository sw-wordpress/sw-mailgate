<?php
/**
 * Declarative description of one provider setting. The admin UI renders forms from these.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

final class Field {

	public const TEXT     = 'text';
	public const PASSWORD = 'password';
	public const NUMBER   = 'number';
	public const SELECT   = 'select';
	public const CHECKBOX = 'checkbox';
	public const EMAIL    = 'email';

	/**
	 * @param string                $type    One of the type constants.
	 * @param bool                  $secret  Stored encrypted and never printed back into the form.
	 * @param array<string, string> $options Value => label, for selects.
	 * @param list<string>|null     $false_values When provided, only these exact values disable a checkbox.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type = self::TEXT,
		public readonly bool $secret = false,
		public readonly string $default = '',
		public readonly array $options = array(),
		public readonly string $description = '',
		public readonly bool $required = false,
		public readonly ?array $false_values = null
	) {
	}

	public static function secret( string $key, string $label, string $description = '', bool $required = true ): self {
		return new self( $key, $label, self::PASSWORD, true, '', array(), $description, $required );
	}

	/** @return list<self> */
	public static function dns_fields(): array {
		return array(
			new self( 'dns_dkim_selector', __( 'DNS: DKIM selector', 'sw-mailgate' ), description: __( 'Active selector from your provider (e.g. google or selector1). Mailgun expected records are retrieved automatically.', 'sw-mailgate' ) ),
			new self( 'dns_dkim_domain', __( 'DNS: DKIM signing domain', 'sw-mailgate' ), description: __( 'Actual DKIM d= domain; empty uses the provider default. Verify in a received mail.', 'sw-mailgate' ) ),
			new self( 'dns_return_path', __( 'DNS: Return-Path domain', 'sw-mailgate' ), description: __( 'Actual envelope sender domain from a received test mail.', 'sw-mailgate' ) ),
			new self( 'dns_spf_include', __( 'DNS: provider SPF include', 'sw-mailgate' ), description: __( 'For generic SMTP, enter the include domain supplied by your provider.', 'sw-mailgate' ) ),
			new self( 'dns_optional', __( 'DNS: optional MX and SMTP PTR checks', 'sw-mailgate' ), self::CHECKBOX ),
		);
	}

	public function is_checked( string $value ): bool {
		return null !== $this->false_values
			? ! in_array( $value, $this->false_values, true )
			: in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Sanitizes a submitted value for this field.
	 */
	public function sanitize( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return match ( $this->type ) {
			self::CHECKBOX => '' === $value ? '0' : '1',
			self::NUMBER   => (string) absint( $value ),
			self::EMAIL    => sanitize_email( $value ),
			self::SELECT   => array_key_exists( $value, $this->options ) ? $value : $this->default,
			// Secrets are opaque: preserve every byte after the form handler's wp_unslash().
			self::PASSWORD => $value,
			default        => sanitize_text_field( $value ),
		};
	}
}
