<?php
/**
 * Redirection, logging, failover and uninstall preferences; retry queue status.
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
	<?php if ( isset( $_GET['queue'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Static success notice. ?>
		<div class="notice notice-success"><p><?php echo 'retried' === $_GET['queue'] ? esc_html__( 'Waiting messages were retried.', 'sw-mailgate' ) : esc_html__( 'Waiting messages were discarded.', 'sw-mailgate' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compared with a literal only. ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'sw_mailgate_save_settings' ); ?>
		<input type="hidden" name="action" value="sw_mailgate_save_settings">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swmg-redirect-to"><?php esc_html_e( 'Redirect mail to', 'sw-mailgate' ); ?></label></th>
				<td>
					<input type="email" name="redirect_to" id="swmg-redirect-to" class="regular-text" value="<?php echo esc_attr( $redirection['target'] ); ?>" <?php disabled( $redirection['locked'] ); ?>>
					<p class="description"><?php esc_html_e( 'Redirect recipients to this address; leave empty to disable. Allowlisted recipients keep receiving mail directly.', 'sw-mailgate' ); ?></p>
					<?php if ( $redirection['locked'] ) : ?>
						<p class="description"><?php esc_html_e( 'The target is controlled by SW_MAILGATE_REDIRECT_TO in wp-config.php.', 'sw-mailgate' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swmg-redirect-allowlist"><?php esc_html_e( 'Do not redirect', 'sw-mailgate' ); ?></label></th>
				<td>
					<textarea name="redirect_allowlist" id="swmg-redirect-allowlist" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", $redirection['allowlist'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One email address or exact domain per line. Domain rules include every mailbox at that domain, but no subdomains. Wildcards are not supported.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
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
		</table>

		<h2><?php esc_html_e( 'Failover', 'sw-mailgate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swmg-breaker-threshold"><?php esc_html_e( 'Pause failing connections', 'sw-mailgate' ); ?></label></th>
				<td>
					<?php
					printf(
						/* translators: 1: number input, 2: number input */
						esc_html__( 'After %1$s failures in a row, skip the connection for %2$s minutes.', 'sw-mailgate' ),
						'<input type="number" id="swmg-breaker-threshold" name="breaker_threshold" min="0" max="20" class="small-text" value="' . esc_attr( (string) $failover['breaker_threshold'] ) . '">',
						'<input type="number" id="swmg-breaker-cooldown" name="breaker_cooldown" min="1" max="1440" class="small-text" value="' . esc_attr( (string) $failover['breaker_cooldown'] ) . '" aria-label="' . esc_attr__( 'Pause in minutes', 'sw-mailgate' ) . '">'
					);
					?>
					<p class="description"><?php esc_html_e( 'Only temporary and login errors count. Mails then go straight to the next connection instead of waiting for the same timeout again. After the pause, the next mail tries the connection again. If every connection is paused, the whole chain is tried anyway. 0 disables pausing.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Timeouts', 'sw-mailgate' ); ?></th>
				<td>
					<label><input type="checkbox" name="timeout_failover" value="1" <?php checked( $failover['timeout_failover'] ); ?>> <?php esc_html_e( 'Fail over when an API provider does not answer after the message was sent', 'sw-mailgate' ); ?></label>
					<p class="description"><?php esc_html_e( 'The provider may have accepted the message anyway, so recipients can get it twice. Uncheck to prefer no duplicates: the mail is then reported as "delivery uncertain" instead. Connection and DNS errors always fail over. SMTP always stops when the server does not confirm a submitted message.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swmg-alert-email"><?php esc_html_e( 'Alert mail', 'sw-mailgate' ); ?></label></th>
				<td>
					<input type="email" id="swmg-alert-email" name="alert_email" class="regular-text" value="<?php echo esc_attr( $failover['alert_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Notify this address when a connection is paused, when it works again and when a queued mail is given up. Alerts are sent through the other connections. Leave empty to disable.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Retry queue', 'sw-mailgate' ); ?></th>
				<td>
					<label><input type="checkbox" name="queue_enabled" value="1" <?php checked( $failover['queue_enabled'] ); ?>> <?php esc_html_e( 'Keep mails that no connection could send and retry them later', 'sw-mailgate' ); ?></label>
					<p>
						<label for="swmg-queue-hours"><?php esc_html_e( 'Give up after (hours)', 'sw-mailgate' ); ?></label>
						<input type="number" id="swmg-queue-hours" name="queue_hours" min="1" max="72" class="small-text" value="<?php echo esc_attr( (string) $failover['queue_hours'] ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Off by default. Only temporary and login errors are queued; wp_mail() then reports success to the calling plugin. The message, including attachments up to 5 MB, is stored encrypted until it is delivered or given up. Retries run via WordPress cron after 5, 15, 30, 60 minutes and then every 2–4 hours. Settings that other plugins apply during sending (e.g. DKIM signing via phpmailer_init) are not applied again on retries.', 'sw-mailgate' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Data', 'sw-mailgate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'sw-mailgate' ); ?></th><td><label><input type="checkbox" name="delete_data" value="1" <?php checked( $delete_data ); ?>> <?php esc_html_e( 'Delete connections, credentials, settings and logs when uninstalling', 'sw-mailgate' ); ?></label></td></tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php if ( $queue_total > 0 ) : ?>
		<h2><?php esc_html_e( 'Waiting in the retry queue', 'sw-mailgate' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subject', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Recipients', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Queued (UTC)', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Next retry (UTC)', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Retries', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Last error', 'sw-mailgate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $queued as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( '' === $entry['subject'] ? __( '(no subject)', 'sw-mailgate' ) : $entry['subject'] ); ?></td>
						<td><?php echo esc_html( (string) $entry['recipients'] ); ?></td>
						<td><?php echo esc_html( $entry['created_at'] ); ?></td>
						<td><?php echo esc_html( $entry['next_run_at'] ); ?></td>
						<td><?php echo esc_html( (string) $entry['retries'] ); ?></td>
						<td><?php echo esc_html( $entry['last_error'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $queue_total > count( $queued ) ) : ?>
			<?php /* translators: %d: number of messages */ ?>
			<p><?php echo esc_html( sprintf( __( 'Showing the next %d messages.', 'sw-mailgate' ), count( $queued ) ) ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sw_mailgate_queue_action' ); ?>
			<input type="hidden" name="action" value="sw_mailgate_queue_action">
			<p>
				<button type="submit" name="queue_action" value="retry" class="button"><?php esc_html_e( 'Retry now', 'sw-mailgate' ); ?></button>
				<button type="submit" name="queue_action" value="discard" class="button button-link-delete" onclick="return window.confirm('<?php echo esc_js( __( 'Discard all waiting messages? They will not be sent.', 'sw-mailgate' ) ); ?>');"><?php esc_html_e( 'Discard all', 'sw-mailgate' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
