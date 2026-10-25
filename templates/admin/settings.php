<?php
/**
 * Logging and uninstall preferences.
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Mailgate settings', 'sw-mailgate' ); ?></h1>
	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Static success notice. ?>
		<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'sw-mailgate' ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'sw_mailgate_save_settings' ); ?>
		<input type="hidden" name="action" value="sw_mailgate_save_settings">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logging', 'sw-mailgate' ); ?></th>
				<td>
					<strong><?php echo esc_html( $settings['enabled'] ? __( 'Enabled in wp-config.php', 'sw-mailgate' ) : __( 'Disabled', 'sw-mailgate' ) ); ?></strong>
					<p class="description"><?php esc_html_e( 'Logging is off by default and can only be enabled in wp-config.php:', 'sw-mailgate' ); ?></p>
					<code>define( 'SW_MAILGATE_LOG_ENABLED', true );</code>
				</td>
			</tr>
			<tr><th scope="row"><?php esc_html_e( 'Message content', 'sw-mailgate' ); ?></th><td><label><input type="checkbox" name="store_body" value="1" <?php checked( $settings['store_body'] ); ?>> <?php esc_html_e( 'Store message bodies and recipient headers for resending', 'sw-mailgate' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. Content may contain personal data or password reset links. Attachments are never stored.', 'sw-mailgate' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Mask addresses', 'sw-mailgate' ); ?></th><td><label><input type="checkbox" name="mask_recipients" value="1" <?php checked( $settings['mask_recipients'] ); ?>> <?php esc_html_e( 'Mask email addresses in new log entries', 'sw-mailgate' ); ?></label><p class="description"><?php esc_html_e( 'Also disables content storage. Existing entries are unchanged; privacy erasure remains available for masked entries.', 'sw-mailgate' ); ?></p></td></tr>
			<tr><th scope="row"><label for="swmg-retention"><?php esc_html_e( 'Retention (days)', 'sw-mailgate' ); ?></label></th><td><input type="number" id="swmg-retention" name="retention" min="1" max="3650" value="<?php echo esc_attr( $settings['retention'] ); ?>"><p class="description"><?php esc_html_e( 'Expired entries are deleted daily by WordPress cron, even while logging is disabled.', 'sw-mailgate' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'sw-mailgate' ); ?></th><td><label><input type="checkbox" name="delete_data" value="1" <?php checked( $delete_data ); ?>> <?php esc_html_e( 'Delete connections, credentials, settings and logs when uninstalling', 'sw-mailgate' ); ?></label></td></tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
