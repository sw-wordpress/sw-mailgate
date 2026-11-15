<?php
/**
 * Protected release signing CLI (CI only). Never prints private key material.
 *
 *   release-manifest.php public-key <trusted-keys.php>        add the build key's PUBLIC key to the key ring
 *   release-manifest.php sign <release.json> <zip-dir> <sha>  write sw-plugin-release.json(.sig) for the uploaded ZIPs
 *
 * Environment: SW_PLUGIN_RELEASE_SIGNING_KEY (Base64, 64-byte Sodium secret key), SW_PLUGIN_RELEASE_KEY_ID.
 * Intentionally outside PHPStan's scope like all of bin/ (build and dev scripts, never shipped).
 *
 * @package sw-mailgate
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/**
 * Plugin header values of a packaged main file.
 *
 * @param string $main Main plugin file contents.
 * @return array<string,string>
 * @throws RuntimeException When a required header is missing.
 */
function swmg_release_headers( string $main ): array {
	$headers = array();
	foreach ( array( 'Version', 'Requires PHP', 'Requires at least' ) as $header ) {
		if ( ! preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi', $main, $match ) ) {
			throw new RuntimeException( 'Missing plugin header.' );
		}
		$headers[ $header ] = trim( $match[1], " \t\r\n*/" );
	}
	return $headers;
}

/**
 * Sign release assets without loading WordPress.
 *
 * @param array<int,string> $argv Command arguments.
 */
function swmg_sign_release( array $argv ): void {
	try {
		$key    = base64_decode( getenv( 'SW_PLUGIN_RELEASE_SIGNING_KEY' ) ?: '', true );
		$key_id = getenv( 'SW_PLUGIN_RELEASE_KEY_ID' ) ?: '';
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) || ! is_string( $key ) || 64 !== strlen( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $key_id ) ) {
			throw new RuntimeException( 'Release signing key, key ID or Sodium unavailable.' );
		}
		$public = sodium_crypto_sign_publickey_from_secretkey( $key );
		if ( 'public-key' === ( $argv[1] ?? '' ) ) {
			$file = $argv[2] ?? '';
			if ( ! is_file( $file ) ) {
				throw new RuntimeException( 'Existing public keyring path required.' );
			}
			$keys = require $file;
			if ( ! is_array( $keys ) ) {
				throw new RuntimeException( 'Invalid keyring.' );
			}
			if ( isset( $keys[ $key_id ] ) && base64_encode( $public ) !== $keys[ $key_id ] ) {
				throw new RuntimeException( 'Key ID is already used by a different public key.' );
			}
			$keys[ $key_id ] = base64_encode( $public );
			$contents = "<?php\n/**\n * Public Ed25519 verification keys.\n *\n * @package SWMailgate\n */\n\nreturn " . var_export( $keys, true ) . ";\n";
			if ( file_put_contents( $file, $contents, LOCK_EX ) !== strlen( $contents ) ) {
				throw new RuntimeException( 'Cannot write public keyring.' );
			}
			exit( 0 );
		}
		if ( 'sign' !== ( $argv[1] ?? '' ) || 5 !== count( $argv ) ) {
			throw new RuntimeException( 'Usage: release-manifest.php sign <release-json> <zip-directory> <commit-sha>' );
		}
		$release   = json_decode( (string) file_get_contents( $argv[2] ), true, 32, JSON_THROW_ON_ERROR );
		$directory = realpath( $argv[3] );
		$commit    = $argv[4];
		if ( false === $directory || ! preg_match( '/^[a-f0-9]{40}$/D', $commit ) || ! is_array( $release ) || ! is_int( $release['id'] ?? null ) ) {
			throw new RuntimeException( 'Invalid release inputs.' );
		}
		$source   = require dirname( __DIR__ ) . '/src/Update/Config/source.php';
		$packages = array();
		foreach ( glob( $directory . '/*.zip' ) ?: array() as $file ) {
			$name = basename( $file );
			if ( ! preg_match( '/^([a-z0-9]+(?:-[a-z0-9]+)*)-(\d+(?:\.\d+){2,3})\.zip$/D', $name, $parts ) ) {
				throw new RuntimeException( 'Unexpected release ZIP.' );
			}
			if ( ! in_array( $parts[1], $source['allowed_slugs'], true ) ) {
				throw new RuntimeException( 'Unknown plugin identity.' );
			}
			$assets = array_values( array_filter( $release['assets'] ?? array(), static fn ( $a ) => $a['name'] === $name ) );
			if ( 1 !== count( $assets ) || filesize( $file ) !== $assets[0]['size'] ) {
				throw new RuntimeException( 'Upload every ZIP to the draft release before signing.' );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				throw new RuntimeException( 'Cannot read release ZIP.' );
			}
			$main = $zip->getFromName( $parts[1] . '/' . $parts[1] . '.php', 16384 );
			$zip->close();
			$headers = swmg_release_headers( is_string( $main ) ? $main : '' );
			if ( $parts[2] !== $headers['Version'] ) {
				throw new RuntimeException( 'ZIP filename/version mismatch.' );
			}
			$packages[] = array(
				'slug'         => $parts[1],
				'version'      => $parts[2],
				'filename'     => $name,
				'asset_id'     => $assets[0]['id'],
				'bytes'        => filesize( $file ),
				'sha256'       => hash_file( 'sha256', $file ),
				'main_file'    => $parts[1] . '.php',
				'requires_php' => $headers['Requires PHP'],
				'requires_wp'  => $headers['Requires at least'],
			);
		}
		if ( ! $packages ) {
			throw new RuntimeException( 'No packages.' );
		}
		$raw       = json_encode(
			array(
				'schema'        => 1,
				'repository'    => $source['repository'],
				'repository_id' => $source['repository_id'],
				'release_id'    => $release['id'],
				'tag'           => $release['tag_name'],
				'commit'        => $commit,
				'issued_at'     => time(),
				'packages'      => $packages,
			),
			JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
		) . "\n";
		$signature = sodium_crypto_sign_detached( $raw, $key );
		if ( ! sodium_crypto_sign_verify_detached( $signature, $raw, $public ) ) {
			throw new RuntimeException( 'Signing self-check failed.' );
		}
		file_put_contents( $directory . '/sw-plugin-release.json', $raw );
		file_put_contents(
			$directory . '/sw-plugin-release.json.sig',
			json_encode(
				array(
					'key_id'    => $key_id,
					'signature' => base64_encode( $signature ),
				),
				JSON_THROW_ON_ERROR
			) . "\n"
		);
		sodium_memzero( $key );
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'Release signing failed: ' . $error->getMessage() . "\n" );
		exit( 1 );
	}
}

swmg_sign_release( $argv );
