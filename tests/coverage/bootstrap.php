<?php
/**
 * Auto-prepended only to instrumented test processes; never loaded by the plugin.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

// Isolate bookkeeping from globals used by the existing standalone test scripts.
( static function (): void {
	$root = dirname( __DIR__, 2 );
	require_once $root . '/vendor/autoload.php';
	$filter = new \SebastianBergmann\CodeCoverage\Filter();
	$filter->includeDirectory( $root . '/src' );
	$filter->includeDirectory( $root . '/templates' );
	$filter->includeFile( $root . '/sw-mailgate.php' );
	$filter->includeFile( $root . '/uninstall.php' );
	$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage(
		( new \SebastianBergmann\CodeCoverage\Driver\Selector() )->forLineCoverage( $filter ),
		$filter
	);
	$coverage->start( getenv( 'SW_MAILGATE_COVERAGE_ID' ) ?: 'standalone scenario' );
	register_shutdown_function( static function () use ( $coverage ): void {
		$coverage->stop();
		$coverage->clearCache();
		$file = getenv( 'SW_MAILGATE_COVERAGE_FILE' );
		if ( false === $file || false === file_put_contents( $file, serialize( $coverage ) ) ) {
			fwrite( STDERR, 'FAIL: Could not write process coverage.' . PHP_EOL );
			exit( 1 );
		}
	} );
} )();
