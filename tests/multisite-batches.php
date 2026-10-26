<?php
/**
 * Exercise the real uninstall entry point across 205 simulated site contexts.
 * Complements multisite.php's real WordPress/MariaDB integration tests.
 * Usage: php tests/multisite-batches.php [plugin-directory]
 *
 * @package SWMailgate
 */

declare(strict_types=1);

define( 'WP_UNINSTALL_PLUGIN', true );
$sw_mailgate_site = 7;
$sw_mailgate_stack = array( 1 );
$sw_mailgate_options = array();
$sw_mailgate_roles = array();
$sw_mailgate_cleared = array();
$sw_mailgate_batches = array();
for ( $sw_mailgate_i = 1; $sw_mailgate_i <= 205; ++$sw_mailgate_i ) {
	$sw_mailgate_options[ $sw_mailgate_i ] = array(
		'sw_mailgate_connections' => array( 'fixture' ), 'sw_mailgate_secrets' => array( 'ciphertext' ),
		'sw_mailgate_db_version' => 2, 'sw_mailgate_logging' => array( 'retention' => 30 ),
		'sw_mailgate_redirection' => array( 'target' => 'sink@example.test' ),
		'sw_mailgate_delete_data' => 0 === $sw_mailgate_i % 2, 'unrelated_option' => 'keep',
	);
	$sw_mailgate_roles[ $sw_mailgate_i ] = new class() {
		public array $caps = array( 'read' => true, 'manage_sw_mailgate' => true );
		public function remove_cap( string $cap ): void { unset( $this->caps[ $cap ] ); }
	};
}
$sw_mailgate_original = $sw_mailgate_options;
function is_multisite(): bool { return true; }
function get_sites( array $args ): array {
	if ( 100 !== $args['number'] || 'ids' !== $args['fields'] || 'id' !== $args['orderby'] || 'ASC' !== $args['order'] ) { throw new RuntimeException( 'Unbounded or unstable site query.' ); }
	$GLOBALS['sw_mailgate_batches'][] = $args['offset'];
	return array_slice( range( 1, 205 ), $args['offset'], $args['number'] );
}
function switch_to_blog( int $id ): void {
	$GLOBALS['sw_mailgate_stack'][] = $GLOBALS['sw_mailgate_site'];
	$GLOBALS['sw_mailgate_site'] = $id;
	$GLOBALS['wpdb']->prefix = 'wp_' . $id . '_';
}
function restore_current_blog(): void {
	$GLOBALS['sw_mailgate_site'] = array_pop( $GLOBALS['sw_mailgate_stack'] );
	$GLOBALS['wpdb']->prefix = 'wp_' . $GLOBALS['sw_mailgate_site'] . '_';
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['sw_mailgate_options'][ $GLOBALS['sw_mailgate_site'] ][ $key ] ?? $default; }
function delete_option( string $key ): void { unset( $GLOBALS['sw_mailgate_options'][ $GLOBALS['sw_mailgate_site'] ][ $key ] ); }
function wp_clear_scheduled_hook( string $hook ): void {
	if ( 'sw_mailgate_log_retention' !== $hook ) { throw new RuntimeException( 'Unexpected cron hook.' ); }
	$GLOBALS['sw_mailgate_cleared'][] = $GLOBALS['sw_mailgate_site'];
}
function wp_roles(): object { return (object) array( 'role_objects' => array( $GLOBALS['sw_mailgate_roles'][ $GLOBALS['sw_mailgate_site'] ] ) ); }
$wpdb = new class() {
	public string $prefix = 'wp_7_';
	public array $dropped = array();
	public function prepare( string $sql, string $table ): string {
		if ( $this->prefix . 'sw_mailgate_log' !== $table ) { throw new RuntimeException( 'Wrong table context.' ); }
		return str_replace( '%i', '`' . $table . '`', $sql );
	}
	public function query( string $sql ): void {
		if ( 'DROP TABLE IF EXISTS `' . $this->prefix . 'sw_mailgate_log`' !== $sql ) { throw new RuntimeException( 'Wrong cleanup query.' ); }
		$this->dropped[] = $GLOBALS['sw_mailgate_site'];
	}
};
require ( $argv[1] ?? dirname( __DIR__ ) ) . '/uninstall.php';
if ( array( 0, 100, 200 ) !== $sw_mailgate_batches || range( 1, 205 ) !== $sw_mailgate_cleared || 7 !== $sw_mailgate_site || array( 1 ) !== $sw_mailgate_stack || 'wp_7_' !== $wpdb->prefix ) { throw new RuntimeException( 'Batch traversal, cron cleanup or context restoration failed.' ); }
for ( $sw_mailgate_i = 1; $sw_mailgate_i <= 205; ++$sw_mailgate_i ) {
	$delete = 0 === $sw_mailgate_i % 2;
	$expected = $delete ? array( 'unrelated_option' => 'keep' ) : $sw_mailgate_original[ $sw_mailgate_i ];
	$expected_caps = $delete ? array( 'read' => true ) : array( 'read' => true, 'manage_sw_mailgate' => true );
	if ( $expected !== $sw_mailgate_options[ $sw_mailgate_i ] || $expected_caps !== $sw_mailgate_roles[ $sw_mailgate_i ]->caps || $delete !== in_array( $sw_mailgate_i, $wpdb->dropped, true ) ) { throw new RuntimeException( 'Site-specific policy violated for site ' . $sw_mailgate_i ); }
}
echo 'PASS: 205-site uninstall batches, per-site policies, cron, roles, tables and caller context.' . PHP_EOL;
