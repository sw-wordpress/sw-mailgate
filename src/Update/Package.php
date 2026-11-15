<?php
/**
 * Validates the exact file handed to WordPress before any extraction.
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

namespace SWMailgate\Update;

use RuntimeException;
use Throwable;
use WP_Error;
use ZipArchive;

final class Package {

	/**
	 * Verifies hash, size, archive structure and plugin headers.
	 *
	 * @param string              $file    Downloaded ZIP.
	 * @param array<string,mixed> $package Signed package entry.
	 * @return true|WP_Error
	 */
	public static function verify( string $file, array $package ): true|WP_Error {
		clearstatcache( true, $file );
		$hash = is_file( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( ! class_exists( ZipArchive::class ) || ! is_file( $file ) || filesize( $file ) !== $package['bytes'] || ! is_string( $hash ) || ! hash_equals( (string) $package['sha256'], $hash ) ) {
			return new WP_Error( 'invalid_update_file', __( 'ZIP size or SHA-256 does not match, or ZIP verification is unavailable.', 'sw-mailgate' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return new WP_Error( 'invalid_update_zip', __( 'Invalid ZIP archive.', 'sw-mailgate' ) );
		}
		try {
			self::inspect( $zip, $package );
			return true;
		} catch ( Throwable $error ) {
			return new WP_Error( 'unsafe_update_zip', __( 'The update package failed the safety checks.', 'sw-mailgate' ), array( 'reason' => $error->getMessage() ) );
		} finally {
			$zip->close();
		}
	}

	/**
	 * Structural inspection of the archive.
	 *
	 * @param ZipArchive          $zip     Opened archive.
	 * @param array<string,mixed> $package Signed package entry.
	 * @throws RuntimeException On any violation.
	 */
	private static function inspect( ZipArchive $zip, array $package ): void {
		$count = $zip->count();
		if ( $count < 1 || $count > 10000 ) {
			throw new RuntimeException( 'Too many ZIP entries.' );
		}
		$slug  = (string) $package['slug'];
		$total = 0;
		$seen  = array();
		foreach ( range( 0, $count - 1 ) as $index ) {
			$stat = $zip->statIndex( $index );
			if ( false === $stat ) {
				throw new RuntimeException( 'ZIP entry unreadable.' );
			}
			$name       = $stat['name'];
			$parts      = explode( '/', rtrim( $name, '/' ) );
			$key        = strtolower( rtrim( $name, '/' ) );
			$opsys      = 0;
			$attributes = 0;
			if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attributes ) ) {
				throw new RuntimeException( 'ZIP file type unreadable.' );
			}
			$mode = ( $attributes >> 16 ) & 0170000;
			if ( $parts[0] !== $slug || preg_match( '/[\\\\:\x00-\x1f]/', $name ) || in_array( '..', $parts, true ) || in_array( '.', $parts, true ) || in_array( '', $parts, true ) || isset( $seen[ $key ] ) || ( 0 !== $mode && ! in_array( $mode, array( 0100000, 0040000 ), true ) ) || ! empty( $stat['encryption_method'] ) ) {
				throw new RuntimeException( 'Unsafe ZIP path or file type.' );
			}
			$is_directory = str_ends_with( $name, '/' );
			if ( ( $key === $slug && ! $is_directory ) || ( 0040000 === $mode && ! $is_directory ) || ( 0100000 === $mode && $is_directory ) ) {
				throw new RuntimeException( 'Inconsistent ZIP entry type.' );
			}
			$parent = $key;
			while ( str_contains( $parent, '/' ) ) {
				$parent = substr( $parent, 0, (int) strrpos( $parent, '/' ) );
				if ( isset( $seen[ $parent ] ) && ! $seen[ $parent ] ) {
					throw new RuntimeException( 'ZIP file is also a parent directory.' );
				}
			}
			if ( ! $is_directory ) {
				foreach ( array_keys( $seen ) as $previous ) {
					if ( str_starts_with( $previous, $key . '/' ) ) {
						throw new RuntimeException( 'ZIP parent directory is also a file.' );
					}
				}
			}
			$seen[ $key ] = $is_directory;
			$total       += $stat['size'];
			if ( $total > 262144000 || $stat['size'] > max( 1048576, 200 * $stat['comp_size'] ) ) {
				throw new RuntimeException( 'Extraction limit exceeded.' );
			}
		}
		$main = $zip->getFromName( $slug . '/' . $package['main_file'], 16384 );
		if ( ! is_string( $main ) || ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*\S.+$/mi', $main ) ) {
			throw new RuntimeException( 'Plugin main file missing.' );
		}
		foreach ( array(
			'Version'           => 'version',
			'Requires PHP'      => 'requires_php',
			'Requires at least' => 'requires_wp',
		) as $header => $field ) {
			if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $main, $match ) || trim( $match[1], " \t\r\n*/" ) !== $package[ $field ] ) {
				throw new RuntimeException( 'Plugin header contradicts the manifest.' );
			}
		}
	}
}
