<?php
/**
 * DNS diagnostics.
 *
 * @package SWMailgate
 *
 * @var SWMailgate\Dns\Dns_Monitor $this
 * @var array<mixed> $settings
 * @var SWMailgate\Connections\Connection[] $connections
 * @var bool $refresh
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'DNS checks', 'sw-mailgate' ); ?></h1>
	<form method="post">
		<?php wp_nonce_field( 'sw_mailgate_dns' ); ?>
		<p><label><input type="checkbox" name="doh" value="1" <?php checked( true === ( $settings['doh'] ?? false ) ); ?>> <?php esc_html_e( 'Allow DNS-over-HTTPS fallback through Cloudflare when local DNS fails. This sends queried domain names to Cloudflare.', 'sw-mailgate' ); ?></label></p>
		<?php submit_button( __( 'Save and check again', 'sw-mailgate' ) ); ?>
	</form>
	<p><?php esc_html_e( 'Results are cached for one hour and checked weekly. DNS alone does not prove that outgoing messages pass authentication. Review all fallback senders using received test mails.', 'sw-mailgate' ); ?></p>
	<?php foreach ( $connections as $connection ) : ?>
		<h2><?php echo esc_html( $connection->label() ); ?></h2>
		<table class="widefat striped"><thead><tr>
			<th><?php esc_html_e( 'Check', 'sw-mailgate' ); ?></th><th><?php esc_html_e( 'Status', 'sw-mailgate' ); ?></th><th><?php esc_html_e( 'Expected record', 'sw-mailgate' ); ?></th><th><?php esc_html_e( 'Recommendation', 'sw-mailgate' ); ?></th>
		</tr></thead><tbody>
		<?php foreach ( $this->results( $connection, $refresh ) as $row ) : ?>
			<tr><td><?php echo esc_html( $row['check'] ); ?></td><td>
			<?php
			$symbol = match ( $row['status'] ) {
				'good' => '🟢', 'error' => '🔴', default => '🟡' };
	?>
			<?php echo esc_html( $symbol . ' ' . $row['status'] ); ?></td><td><code><?php echo esc_html( $row['expected'] ); ?></code></td><td><?php echo esc_html( $row['recommendation'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	<?php endforeach; ?>
</div>
