<?php
/** Run the isolated updater suite in-process so the existing coverage runner records it. */
declare(strict_types=1);
putenv( 'WP_MULTISITE=' . ( ( $argv[1] ?? '' ) === 'multisite' ? '1' : '0' ) );
$argv = array( __DIR__ . '/wp/vendor/bin/phpunit', '-c', __DIR__ . '/wp/phpunit.xml.dist' );
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count( $argv );
require __DIR__ . '/wp/vendor/phpunit/phpunit/phpunit';
