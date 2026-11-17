<?php
/** Check shipped catalogs, plural forms and interpolation placeholders. */
declare(strict_types=1);
$root = dirname( __DIR__ );
$wp = rtrim( getenv( 'WP_ROOT' ) ?: '/tmp/wordpress', '/' );
require_once $wp . '/wp-includes/pomo/po.php';
require_once $wp . '/wp-includes/pomo/mo.php';
$pot = new PO();
$po = new PO();
$mo = new MO();
if ( ! $pot->import_from_file( $root . '/languages/sw-mailgate.pot' ) || ! $po->import_from_file( $root . '/languages/sw-mailgate-de_DE.po' ) || ! $mo->import_from_file( $root . '/languages/sw-mailgate-de_DE.mo' ) ) {
	throw new RuntimeException( 'Missing or invalid catalog.' );
}
$placeholders = static function ( string $value ): array {
	preg_match_all( '/%(?:\d+\$)?[ds]/', $value, $matches );
	sort( $matches[0] );
	return $matches[0];
};
foreach ( $pot->entries as $key => $entry ) {
	$translated = $po->entries[$key] ?? null;
	$compiled = $mo->entries[$key] ?? null;
	if ( null === $translated || null === $compiled || $compiled->translations !== $translated->translations || in_array( '', $translated->translations, true ) || in_array( 'fuzzy', $translated->flags, true ) ) {
		throw new RuntimeException( 'Incomplete German translation: ' . $entry->singular );
	}
	foreach ( $translated->translations as $index => $text ) {
		if ( $placeholders( $index > 0 ? $entry->plural : $entry->singular ) !== $placeholders( $text ) ) {
			throw new RuntimeException( 'Translation placeholders differ: ' . $entry->singular );
		}
	}
}
if ( '%d Nachrichten warten in der Wiederholungswarteschlange.' !== $mo->translate_plural( '%d message is waiting in the retry queue.', '%d messages are waiting in the retry queue.', 2 ) ) {
	throw new RuntimeException( 'German plural form is incorrect.' );
}
echo 'PASS: All ' . count( $pot->entries ) . ' German translations match the compiled catalog and preserve placeholders/plurals.' . PHP_EOL;
