<?php
/**
 * Holds the available providers.
 *
 * Third-party code adds providers via the `sw_mailgate_providers` filter:
 *     add_filter( 'sw_mailgate_providers', function ( array $providers ) {
 *         $providers[] = new My_Provider();
 *         return $providers;
 *     } );
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Providers;

final class Provider_Registry {

	/** @var array<string, Provider>|null */
	private ?array $providers = null;

	/**
	 * @param Provider[] $defaults
	 */
	public function __construct( private readonly array $defaults ) {
	}

	public function get( string $id ): ?Provider {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * @return array<string, Provider>
	 */
	public function all(): array {
		if ( null === $this->providers ) {
			$this->providers = array();
			/**
			 * Filters the available mail providers.
			 *
			 * @param Provider[] $providers
			 */
			$providers = apply_filters( 'sw_mailgate_providers', $this->defaults );
			foreach ( (array) $providers as $provider ) {
				if ( $provider instanceof Provider ) {
					$this->providers[ $provider->id() ] = $provider;
				}
			}
		}
		return $this->providers;
	}
}
