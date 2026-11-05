<?php
/** Sender DNS diagnostics; results are guidance, never a delivery gate.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

namespace SWMailgate\Dns;

use SWMailgate\Connections\Connection;
use SWMailgate\Providers\Dns_Capable;
use SWMailgate\Providers\Provider;

final class Dns_Checker {
	public function __construct( private readonly Dns_Resolver $resolver = new Dns_Resolver() ) {}

	public static function sender_domain( Connection $connection ): string {
		$email = $connection->from_email();
		return is_email( $email ) ? strtolower( substr( $email, (int) strrpos( $email, '@' ) + 1 ) ) : '';
	}

	/**
	 * @return list<array{check: string, status: string, expected: string, recommendation: string}> */
	public function check( Connection $connection, ?Provider $provider, bool $doh = false ): array {
		$domain = self::sender_domain( $connection );
		$rows   = array();
		$add    = static function ( string $name, string $status, string $expected, string $tip ) use ( &$rows ): void {
			$rows[] = array(
				'check'          => $name,
				'status'         => $status,
				'expected'       => $expected,
				'recommendation' => $tip,
			);
		};
		if ( ! Dns_Resolver::valid_name( $domain ) ) {
			$add( 'Sender', 'warning', 'From domain', __( 'Configure a sender address. Dynamic senders must be checked separately.', 'sw-mailgate' ) );
			return $rows;
		}
		$requirements = $provider instanceof Dns_Capable ? $provider->dns_requirements( $connection ) : array();
		if ( isset( $requirements['error'] ) ) {
			$add( 'Provider', 'warning', '', $requirements['error'] );
		}
		$txt         = $this->resolver->lookup( $domain, 'TXT', $doh );
		$spf         = $this->spf_records( $txt );
		$include     = $requirements['spf_include'] ?? $connection->get( 'dns_spf_include' );
		$lookups     = 0;
		$seen        = array();
		$includes    = array();
		$valid       = 1 === count( $spf ) && $this->spf_budget( $domain, $spf[0], $doh, $lookups, $seen, $includes );
		$unsafe      = 1 === count( $spf ) && 1 === preg_match( '/(?:^|\s)[+?]?all(?:\s|$)/i', $spf[0] );
		$has_include = '' === $include || in_array( strtolower( $include ), $includes, true );
		$add( 'SPF', null === $txt ? 'warning' : ( $valid && ! $unsafe && $has_include ? 'good' : 'error' ), '' === $include ? 'v=spf1 … -all' : 'include:' . $include, sprintf( /* translators: %d: DNS lookup count */ __( 'Publish exactly one SPF record, authorize this provider, avoid +all/?all and keep recursive DNS lookups at most 10 (observed: %d). DNS errors and macros require manual review.', 'sw-mailgate' ), $lookups ) );
		$dkim_domain = $connection->get( 'dns_dkim_domain' ) ?: ( $requirements['dkim_domain'] ?? '' );
		$selector    = $connection->get( 'dns_dkim_selector' ) ?: ( in_array( $connection->provider(), array( 'google', 'google_smtp' ), true ) ? 'google' : '' );
		if ( '' === $selector ) {
			foreach ( $requirements['records'] ?? array() as $record ) {
				if ( preg_match( '/^([a-z0-9_-]+)\._domainkey\.(.+)$/iD', rtrim( $record['name'], '.' ), $match ) ) {
					$selector    = $match[1];
					$dkim_domain = $connection->get( 'dns_dkim_domain' ) ?: $match[2];
					break;
				}
			}
		}
		if ( '' !== $selector && preg_match( '/^[a-z0-9_-]{1,63}$/iD', $selector ) && Dns_Resolver::valid_name( $dkim_domain ) ) {
			$name   = $selector . '._domainkey.' . $dkim_domain;
			$values = $this->resolver->lookup( $name, 'TXT', $doh );
			$cnames = $this->resolver->lookup( $name, 'CNAME', $doh );
			if ( empty( $values ) && 1 === count( $cnames ?? array() ) ) {
				$values = $this->resolver->lookup( $cnames[0], 'TXT', $doh );
			}
			$keys = array_filter( $values ?? array(), static fn( string $v ): bool => 1 === preg_match( '/(?:^|;)\s*p\s*=\s*[a-z0-9+\/]+=*\s*(?:;|$)/i', $v ) );
			$add( 'DKIM', null === $values ? 'warning' : ( 1 === count( $keys ) ? 'good' : 'error' ), $name . ' TXT or CNAME', __( 'Publish the active selector and a non-empty public key; compare with your provider.', 'sw-mailgate' ) );
		} else {
			$add( 'DKIM', 'warning', 'selector._domainkey.' . $dkim_domain, __( 'Enter the active DKIM selector. Microsoft 365 selectors and SMTP signing settings depend on the tenant/provider.', 'sw-mailgate' ) );
		}
		$dmarc_txt     = $this->resolver->lookup( '_dmarc.' . $domain, 'TXT', $doh );
		$dmarc         = array_values( array_filter( $dmarc_txt ?? array(), static fn( string $v ): bool => 1 === preg_match( '/^v=DMARC1\s*;/i', $v ) ) );
		$policy        = array();
		$duplicate_tag = false;
		if ( 1 === count( $dmarc ) ) {
			foreach ( explode( ';', $dmarc[0] ) as $tag ) {
				$parts = explode( '=', trim( $tag ), 2 );
				if ( 2 === count( $parts ) ) {
					$tag_name            = strtolower( $parts[0] );
					$duplicate_tag       = $duplicate_tag || isset( $policy[ $tag_name ] );
					$policy[ $tag_name ] = trim( $parts[1] );
				}
			}
		}
		$policy_valid = ! $duplicate_tag && in_array( $policy['p'] ?? '', array( 'none', 'quarantine', 'reject' ), true );
		$rua_valid    = ! empty( $policy['rua'] );
		foreach ( explode( ',', $policy['rua'] ?? '' ) as $uri ) {
			$uri       = trim( $uri );
			$address   = explode( '!', substr( $uri, 7 ), 2 )[0];
			$rua_valid = $rua_valid && str_starts_with( $uri, 'mailto:' ) && false !== is_email( $address );
		}
		$add( 'DMARC', null === $dmarc_txt ? 'warning' : ( ! $policy_valid ? 'error' : ( 'none' === $policy['p'] || ! $rua_valid ? 'warning' : 'good' ) ), '_dmarc.' . $domain . ' TXT v=DMARC1; p=…; rua=mailto:…', __( 'Use one DMARC record with an explicit policy and aggregate reporting address. For subdomains, also review inherited organizational-domain policy manually.', 'sw-mailgate' ) );
		$return_path = $connection->get( 'dns_return_path' ) ?: ( $requirements['return_path'] ?? '' );
		// Compare explicit domains only; suffix guesses cannot determine organizational domains.
		$dkim_aligned = 0 === strcasecmp( $domain, $dkim_domain );
		$spf_aligned  = '' !== $return_path && 0 === strcasecmp( $domain, $return_path );
		$add( 'Alignment', ( '' !== $connection->get( 'dns_dkim_domain' ) && $dkim_aligned ) || $spf_aligned ? 'good' : 'warning', 'From: ' . $domain . '; DKIM d=: ' . $dkim_domain . '; Return-Path: ' . $return_path, __( 'Compare actual DKIM d= and envelope Return-Path in a received test mail for every fallback sender. Explicit signing or envelope domain matches indicate configured alignment; relaxed organizational-domain alignment needs manual review.', 'sw-mailgate' ) );
		foreach ( $requirements['records'] ?? array() as $record ) {
			$actual  = $this->resolver->lookup( rtrim( $record['name'], '.' ), $record['type'], $doh );
			$matches = 'TXT' === $record['type']
				? in_array( $record['value'], $actual ?? array(), true )
				: in_array( strtolower( rtrim( $record['value'], '.' ) ), array_map( 'strtolower', $actual ?? array() ), true );
			if ( 'TXT' === $record['type'] && str_starts_with( strtolower( $record['value'] ), 'v=spf1 ' ) && 0 === strcasecmp( rtrim( $record['name'], '.' ), $domain ) ) {
				$matches = $valid && $has_include && ! $unsafe; // Merge provider includes into one SPF record.
			}
			$add( $record['type'] . ' ' . $record['name'], null === $actual ? 'warning' : ( $matches ? 'good' : 'error' ), $record['value'], __( 'Publish the expected record supplied by Mailgun.', 'sw-mailgate' ) );
		}
		if ( $connection->bool( 'dns_optional' ) ) {
			$mx = $this->resolver->lookup( $domain, 'MX', $doh );
			$add( 'MX', ! empty( $mx ) ? 'good' : 'warning', $domain . ' MX', __( 'MX is optional for sending; verify that replies and bounces can be received.', 'sw-mailgate' ) );
			$host    = $connection->get( 'host' );
			$ips     = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? array( $host ) : $this->resolver->lookup( $host, 'A', $doh );
			$ip      = $ips[0] ?? '';
			$reverse = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? implode( '.', array_reverse( explode( '.', $ip ) ) ) . '.in-addr.arpa' : '';
			$ptr     = '' === $reverse ? null : $this->resolver->lookup( $reverse, 'PTR', $doh );
			$forward = ! empty( $ptr ) ? $this->resolver->lookup( $ptr[0], 'A', $doh ) : array();
			$add( 'PTR', in_array( $ip, $forward ?? array(), true ) ? 'good' : 'warning', $host . ' A → PTR → A', __( 'Optional IPv4 forward-confirmed reverse DNS of the SMTP host; this may differ from the provider’s outbound delivery IP.', 'sw-mailgate' ) );
		}
		if ( ! $connection->forces_from() ) {
			$add( 'Dynamic From', 'warning', $domain, __( 'The sender may be changed by another plugin. Check all actual From domains.', 'sw-mailgate' ) );
		}
		return $rows;
	}

	/** @param list<string>|null $txt
	 * @return list<string> */
	private function spf_records( ?array $txt ): array {
		return array_values( array_filter( $txt ?? array(), static fn( string $v ): bool => 1 === preg_match( '/^v=spf1(?:\s|$)/i', $v ) ) );
	}

	/** @param array<string, bool> $seen
	 * @param list<string>        $includes */
	private function spf_budget( string $domain, string $record, bool $doh, int &$count, array &$seen, array &$includes, bool $authorizes = true ): bool {
		if ( isset( $seen[ $domain ] ) || str_contains( $record, '%' ) ) {
			return false;
		}
		$seen[ $domain ] = true;
		foreach ( preg_split( '/\s+/', $record ) ?: array() as $term ) {
			$positive = ! in_array( substr( $term, 0, 1 ), array( '-', '~', '?' ), true );
			$term     = strtolower( ltrim( $term, '+-~?' ) );
			if ( 'all' === $term ) {
				break; // SPF ignores mechanisms after all.
			}
			if ( preg_match( '/^(include:|redirect=)(.+)$/', $term, $match ) ) {
				if ( ++$count > 10 ) {
					return false;
				}
				if ( $authorizes && $positive ) {
					$includes[] = $match[2];
				}
				$nested = $this->spf_records( $this->resolver->lookup( $match[2], 'TXT', $doh ) );
				if ( 1 !== count( $nested ) || ! $this->spf_budget( $match[2], $nested[0], $doh, $count, $seen, $includes, $authorizes && $positive ) ) {
					return false;
				}
			} elseif ( preg_match( '/^(?:a|mx|ptr)(?:[:\/]|$)|^exists:/', $term ) && ++$count > 10 ) {
				return false;
			} elseif ( ! preg_match( '/^(?:v=spf1|all|ip4:[0-9.]+(?:\/[0-9]+)?|ip6:[a-f0-9:]+(?:\/[0-9]+)?|[a-z][a-z0-9_.-]*=.+)$/iD', $term ) && ! preg_match( '/^(?:a|mx|ptr)(?:[:\/]|$)|^exists:/', $term ) ) {
				return false;
			}
		}
		unset( $seen[ $domain ] );
		return true;
	}
}
