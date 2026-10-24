<?php
/**
 * Send a test mail through the chain or one connection.
 *
 * @package SWMailgate
 *
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

/** @var SWMailgate\Connections\Connection[] $connections */
$connections = $data['connections'];
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preselects a connection only.
$preselected = isset( $_GET['connection'] ) ? sanitize_key( wp_unslash( $_GET['connection'] ) ) : '';
?>
<div class="wrap swmg">
	<h1><?php esc_html_e( 'Send test mail', 'sw-mailgate' ); ?></h1>

	<?php require __DIR__ . '/messages.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'sw_mailgate_send_test' ); ?>
		<input type="hidden" name="action" value="sw_mailgate_send_test">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swmg-recipient"><?php esc_html_e( 'Recipient', 'sw-mailgate' ); ?></label></th>
				<td><input type="email" id="swmg-recipient" name="recipient" class="regular-text" required value="<?php echo esc_attr( $data['recipient'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="swmg-connection"><?php esc_html_e( 'Send through', 'sw-mailgate' ); ?></label></th>
				<td>
					<select id="swmg-connection" name="connection">
						<option value=""><?php esc_html_e( 'Failover chain (like regular mails)', 'sw-mailgate' ); ?></option>
						<?php foreach ( $connections as $connection ) : ?>
							<option value="<?php echo esc_attr( $connection->id() ); ?>" <?php selected( $preselected, $connection->id() ); ?>>
								<?php
								echo esc_html(
									$connection->is_enabled()
										? $connection->label()
										/* translators: %s: connection label */
										: sprintf( __( '%s (disabled)', 'sw-mailgate' ), $connection->label() )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A single connection is used even if it is disabled, so you can test it before enabling.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Send test mail', 'sw-mailgate' ) ); ?>
	</form>
</div>
