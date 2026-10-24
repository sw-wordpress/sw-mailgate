<?php
/**
 * One configured provider instance with its own sender. Several connections form the failover chain.
 *
 * Every value can be overridden by a wp-config.php constant named
 * SW_MAILGATE_<CONNECTION ID>_<FIELD KEY>, e.g. SW_MAILGATE_PRIMARY_PASSWORD.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Connections;

use SWMailgate\Security\Secret_Store;

final class Connection {

	/**
	 * @param array<string, mixed> $settings Non-secret provider settings.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $provider,
		private readonly bool $enabled,
		private readonly string $from_email,
		private readonly string $from_name,
		private readonly bool $force_from,
		private readonly bool $reply_to_original,
		private readonly array $settings,
		private readonly Secret_Store $secrets
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function is_enabled(): bool {
		return $this->enabled;
	}

	public function from_email(): string {
		return (string) ( $this->constant( 'from_email' ) ?? $this->from_email );
	}

	public function from_name(): string {
		return (string) ( $this->constant( 'from_name' ) ?? $this->from_name );
	}

	/** Replace the sender of every mail, not only the WordPress default "wordpress@domain". */
	public function forces_from(): bool {
		return $this->force_from;
	}

	/** Use the original sender as Reply-To when this connection rewrites the From address. */
	public function reply_to_original(): bool {
		return $this->reply_to_original;
	}

	/**
	 * Resolves a provider value: wp-config constant, then plain setting, then encrypted secret.
	 */
	public function get( string $key, string $fallback = '' ): string {
		$constant = $this->constant( $key );
		if ( null !== $constant ) {
			return (string) $constant;
		}
		if ( array_key_exists( $key, $this->settings ) ) {
			return (string) $this->settings[ $key ];
		}
		return $this->secrets->get( self::secret_name( $this->id, $key ) ) ?? $fallback;
	}

	public function bool( string $key ): bool {
		return in_array( strtolower( $this->get( $key ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public function is_locked( string $key ): bool {
		return null !== $this->constant( $key );
	}

	public function has_secret( string $key ): bool {
		return $this->secrets->has( self::secret_name( $this->id, $key ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->settings;
	}

	public function constant_name( string $key ): string {
		return self::build_constant_name( $this->id, $key );
	}

	public static function build_constant_name( string $id, string $key ): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', 'SW_MAILGATE_' . $id . '_' . $key ) );
	}

	public static function secret_name( string $id, string $key ): string {
		return $id . '.' . $key;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'label'             => $this->label,
			'provider'          => $this->provider,
			'enabled'           => $this->enabled,
			'from_email'        => $this->from_email,
			'from_name'         => $this->from_name,
			'force_from'        => $this->force_from,
			'reply_to_original' => $this->reply_to_original,
			'settings'          => $this->settings,
		);
	}

	private function constant( string $key ): string|int|bool|null {
		$name = $this->constant_name( $key );
		if ( ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );
		return is_scalar( $value ) ? $value : null;
	}
}
