<?php
/**
 * Enforce the project rule: line coverage must be strictly greater than 80 %.
 * Usage: php tests/coverage/check.php [clover.xml] [label]
 *
 * Reads the project-level Clover metrics (statements = executable lines in both
 * php-code-coverage and Istanbul/c8 output). Exactly 80 % fails.
 *
 * @package SWMailgate
 */

declare(strict_types=1);

const SW_MAILGATE_COVERAGE_MINIMUM = 80;

/**
 * Return covered/total executable lines and whether the strict gate passes.
 *
 * @return array{covered: int, total: int, passed: bool}
 * @throws RuntimeException When the report is missing, invalid or empty.
 */
function sw_mailgate_coverage_check( string $file ): array {
	if ( ! is_file( $file ) ) { throw new RuntimeException( 'Coverage report not found: ' . $file ); }
	$document = new DOMDocument();
	if ( ! @$document->load( $file, LIBXML_NONET ) ) { throw new RuntimeException( 'Coverage report is not valid XML: ' . $file ); }
	$metrics = ( new DOMXPath( $document ) )->query( '/coverage/project/metrics' );
	if ( false === $metrics || 1 !== $metrics->length ) { throw new RuntimeException( 'Coverage report has no project metrics: ' . $file ); }
	$node = $metrics->item( 0 );
	$total = $node->getAttribute( 'statements' );
	$covered = $node->getAttribute( 'coveredstatements' );
	if ( ! ctype_digit( $total ) || ! ctype_digit( $covered ) || (int) $covered > (int) $total ) {
		throw new RuntimeException( 'Coverage report has invalid line metrics: ' . $file );
	}
	if ( 0 === (int) $total ) { throw new RuntimeException( 'Coverage report contains no executable lines: ' . $file ); }
	// Integer comparison avoids rounding a value such as 80.004 % down to a pass/fail edge.
	$passed = (int) $covered * 100 > (int) $total * SW_MAILGATE_COVERAGE_MINIMUM;
	return array( 'covered' => (int) $covered, 'total' => (int) $total, 'passed' => $passed );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	$sw_mailgate_file = $argv[1] ?? dirname( __DIR__, 2 ) . '/coverage/clover.xml';
	$sw_mailgate_label = $argv[2] ?? 'Line';
	try {
		$sw_mailgate_result = sw_mailgate_coverage_check( $sw_mailgate_file );
	} catch ( RuntimeException $sw_mailgate_error ) {
		fwrite( STDERR, 'FAIL: ' . $sw_mailgate_error->getMessage() . PHP_EOL );
		exit( 1 );
	}
	printf( "%s coverage: %d/%d lines (%.2f%%), required: > %d%%.\n", $sw_mailgate_label,
		$sw_mailgate_result['covered'], $sw_mailgate_result['total'],
		100 * $sw_mailgate_result['covered'] / $sw_mailgate_result['total'], SW_MAILGATE_COVERAGE_MINIMUM );
	if ( ! $sw_mailgate_result['passed'] ) {
		fwrite( STDERR, 'FAIL: Coverage gate not met.' . PHP_EOL );
		exit( 1 );
	}
	echo 'PASS: Coverage gate met.' . PHP_EOL;
}
