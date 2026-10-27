<?php
/**
 * Merge trusted coverage fragments produced by this run and export standard Clover XML.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$files = glob( ( $argv[1] ?? '' ) . '/*.cov' );
if ( false === $files || array() === $files ) { throw new RuntimeException( 'No coverage fragments were produced.' ); }
$merged = null;
foreach ( $files as $file ) {
	$coverage = unserialize( file_get_contents( $file ) );
	if ( ! $coverage instanceof CodeCoverage ) { throw new RuntimeException( 'Invalid coverage fragment: ' . $file ); }
	if ( null === $merged ) { $merged = $coverage; }
	else { $merged->merge( $coverage ); }
}
// Include production files never loaded by any scenario in the denominator.
$merged->includeUncoveredFiles();
$target = dirname( __DIR__, 2 ) . '/coverage/clover.xml';
( new Clover() )->process( $merged, $target );

// Relative names survive the transfer from the test job to the scanner's checkout.
$document = new DOMDocument();
$document->load( $target );
$root = dirname( __DIR__, 2 ) . '/';
foreach ( $document->getElementsByTagName( 'file' ) as $file ) {
	$name = $file->getAttribute( 'name' );
	if ( ! str_starts_with( $name, $root ) ) { throw new RuntimeException( 'Coverage escaped the project root: ' . $name ); }
	$file->setAttribute( 'name', substr( $name, strlen( $root ) ) );
}
$document->save( $target );
$report = $merged->getReport();
printf( "Coverage: %d/%d executable lines (%.2f%%), merged from %d processes.\n",
	$report->numberOfExecutedLines(), $report->numberOfExecutableLines(),
	$report->percentageOfExecutedLines()->asFloat(), count( $files ) );
echo 'Clover report: coverage/clover.xml' . PHP_EOL;
