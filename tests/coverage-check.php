<?php
/**
 * Boundary regression for the strict > 80 % coverage gate (no WordPress needed).
 * Usage: php tests/coverage-check.php
 *
 * @package SWMailgate
 */

declare(strict_types=1);

require __DIR__ . '/coverage/check.php';

$sw_mailgate_dir = sys_get_temp_dir() . '/swmg-coverage-' . bin2hex( random_bytes( 6 ) );
mkdir( $sw_mailgate_dir, 0700 );
$sw_mailgate_fixture = static function ( string $name, string $metrics ) use ( $sw_mailgate_dir ): string {
	$file = $sw_mailgate_dir . '/' . $name . '.xml';
	file_put_contents( $file, '<?xml version="1.0"?><coverage><project><file name="a"><metrics statements="1" coveredstatements="0"/></file><metrics ' . $metrics . '/></project></coverage>' );
	return $file;
};
$sw_mailgate_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
};
$sw_mailgate_cli = static function ( string $file ): int {
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/coverage/check.php', $file, 'Fixture' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	return proc_close( $process );
};

try {
	// Exactly 80 % fails; the smallest step above passes; values below fail.
	foreach ( array( array( 80, 100, false ), array( 800, 1000, false ), array( 801, 1000, true ), array( 79, 100, false ), array( 100, 100, true ), array( 0, 5, false ) ) as [ $covered, $total, $expected ] ) {
		$result = sw_mailgate_coverage_check( $sw_mailgate_fixture( "c{$covered}-{$total}", "statements=\"{$total}\" coveredstatements=\"{$covered}\"" ) );
		$sw_mailgate_assert( $expected === $result['passed'] && $covered === $result['covered'] && $total === $result['total'], "{$covered}/{$total} must " . ( $expected ? 'pass' : 'fail' ) . '.' );
	}
	// Only project metrics count, not the per-file metrics placed before them.
	$sw_mailgate_assert( sw_mailgate_coverage_check( $sw_mailgate_fixture( 'project', 'statements="10" coveredstatements="9"' ) )['passed'], 'Project metrics must be used.' );

	$invalid = array(
		'missing' => $sw_mailgate_dir . '/missing.xml',
		'empty'   => $sw_mailgate_fixture( 'empty', 'statements="0" coveredstatements="0"' ),
		'absent'  => $sw_mailgate_fixture( 'absent', 'elements="5"' ),
		'excess'  => $sw_mailgate_fixture( 'excess', 'statements="5" coveredstatements="6"' ),
		'negative' => $sw_mailgate_fixture( 'negative', 'statements="5" coveredstatements="-1"' ),
	);
	file_put_contents( $sw_mailgate_dir . '/broken.xml', '<coverage><project>' );
	$invalid['broken'] = $sw_mailgate_dir . '/broken.xml';
	file_put_contents( $sw_mailgate_dir . '/nometrics.xml', '<?xml version="1.0"?><coverage><project/></coverage>' );
	$invalid['nometrics'] = $sw_mailgate_dir . '/nometrics.xml';
	foreach ( $invalid as $case => $file ) {
		try {
			sw_mailgate_coverage_check( $file );
			$sw_mailgate_assert( false, "Invalid report '{$case}' must be rejected." );
		} catch ( RuntimeException $error ) {
			unset( $error );
		}
		$sw_mailgate_assert( 1 === $sw_mailgate_cli( $file ), "CLI must fail for '{$case}'." );
	}

	// The CLI exit code is what CI and Composer rely on.
	$sw_mailgate_assert( 1 === $sw_mailgate_cli( $sw_mailgate_dir . '/c80-100.xml' ), 'CLI must fail at exactly 80 %.' );
	$sw_mailgate_assert( 1 === $sw_mailgate_cli( $sw_mailgate_dir . '/c800-1000.xml' ), 'CLI must fail at exactly 80.0 %.' );
	$sw_mailgate_assert( 0 === $sw_mailgate_cli( $sw_mailgate_dir . '/c801-1000.xml' ), 'CLI must pass at 80.1 %.' );
} finally {
	array_map( 'unlink', glob( $sw_mailgate_dir . '/*' ) ?: array() );
	rmdir( $sw_mailgate_dir );
}
echo 'PASS: Coverage gate boundaries.' . PHP_EOL;
