<?php
/**
 * Run all regression scenarios in separate PHP processes, optionally collecting coverage.
 * Usage: WP_ROOT=/tmp/wordpress WP_DB_HOST=127.0.0.1:3306 php tests/run.php [--coverage]
 *
 * @package SWMailgate
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
chdir( $root );
$collect = in_array( '--coverage', $argv, true );
$directory = $root . '/coverage/raw/' . bin2hex( random_bytes( 8 ) );
if ( $collect ) {
	require $root . '/vendor/autoload.php';
	$filter = new \SebastianBergmann\CodeCoverage\Filter();
	// Fail before running any tests if the requested coverage driver is unavailable.
	( new \SebastianBergmann\CodeCoverage\Driver\Selector() )->forLineCoverage( $filter );
	mkdir( $directory, 0777, true );
}

$scenarios = array(
	array( 'tests/bootstrap.php' ),
	array( 'tests/uninstall.php', '.', 'delete' ),
	array( 'tests/uninstall.php', '.', 'preserve' ),
	array( 'tests/uninstall.php', '.', 'default' ),
	array( 'tests/multisite-batches.php' ),
);
foreach ( array( 'unset', 'disabled', 'string', 'integer', 'enabled' ) as $mode ) {
	$scenarios[] = array( 'tests/integration.php', $mode );
}
foreach ( array( 'ui', 'constant', 'empty', 'invalid', 'invalid-type', 'production' ) as $mode ) {
	$scenarios[] = array( 'tests/integration.php', 'enabled', 'redirection', $mode );
}
$scenarios[] = array( 'tests/integration.php', 'unset', 'redirection', 'ui' );
foreach ( array( array( 'enabled', 'api' ), array( 'unset', 'api' ), array( 'enabled', 'constant' ) ) as [ $logging, $mode ] ) {
	$scenarios[] = array( 'tests/integration.php', $logging, 'mailgun', $mode );
}
$scenarios[] = array( 'tests/integration.php', 'enabled', 'failover', 'run' );
foreach ( array( 'smtp', 'editor', 'editor-false', 'secrets' ) as $mode ) {
	$scenarios[] = array( 'tests/integration.php', 'enabled', 'issues', $mode );
}
$scenarios[] = array( 'tests/smtp-tls-policy.php' );
foreach ( array( 'setup', 'run' ) as $mode ) {
	$scenarios[] = array( 'tests/integration.php', 'enabled', 'multisite', $mode );
}
foreach ( array( 'idle', 'short-true', 'short-false', 'send', 'foreign', 'smtp', 'retry' ) as $mode ) {
	$scenarios[] = array( 'tests/lazy-loading.php', $mode );
}

$run = static function ( array $command ): void {
	echo PHP_EOL . 'RUN: ' . implode( ' ', $command ) . PHP_EOL;
	$process = proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start test process.' ); }
	$status = proc_close( $process );
	if ( 0 !== $status ) { exit( $status > 0 ? $status : 1 ); }
};

// Keep the extension-free regression intact. A second run can load the coverage
// driver while still making mb_check_encoding unavailable.
$run( array( PHP_BINARY, '-n', '-d', 'disable_functions=mb_check_encoding', 'tests/smtp-diagnostics.php' ) );
if ( $collect ) {
	$scenarios[] = array( '-d', 'disable_functions=mb_check_encoding', 'tests/smtp-diagnostics.php' );
}
foreach ( $scenarios as $index => $scenario ) {
	$command = array( PHP_BINARY );
	if ( $collect ) {
		putenv( 'SW_MAILGATE_COVERAGE_FILE=' . $directory . '/' . $index . '.cov' );
		putenv( 'SW_MAILGATE_COVERAGE_ID=' . implode( ' ', $scenario ) );
		$command[] = '-d';
		$command[] = 'auto_prepend_file=' . __DIR__ . '/coverage/bootstrap.php';
	}
	$run( array_merge( $command, $scenario ) );
	if ( $collect && ! is_file( $directory . '/' . $index . '.cov' ) ) {
		throw new RuntimeException( 'Test process did not write its coverage report.' );
	}
}
putenv( 'SW_MAILGATE_COVERAGE_FILE' );
putenv( 'SW_MAILGATE_COVERAGE_ID' );
if ( $collect ) {
	$run( array( PHP_BINARY, __DIR__ . '/coverage/report.php', $directory ) );
}
echo PHP_EOL . 'PASS: All regression scenarios completed.' . PHP_EOL;
