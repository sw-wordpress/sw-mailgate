<?php
/**
 * Every attempt for a message; stored bodies are displayed as escaped source.
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;
$payload = json_decode( (string) $row['payload'], true );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Mail details', 'sw-mailgate' ); ?></h1>
	<p><a href="<?php echo esc_url( \SWMailgate\Admin\Admin_Page::url( array(), \SWMailgate\Admin\Log_Page::SLUG ) ); ?>"><?php esc_html_e( 'Back to mail log', 'sw-mailgate' ); ?></a></p>
	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Displays only allowlisted result text.
	if ( isset( $_GET['resent'] ) && in_array( $_GET['resent'], array( 'sent', 'failed' ), true ) ) :
		?>
		<div class="notice notice-info"><p><?php echo esc_html( 'sent' === $_GET['resent'] ? __( 'The message was sent again.', 'sw-mailgate' ) : __( 'The message could not be sent.', 'sw-mailgate' ) ); ?></p></div>
	<?php endif; // phpcs:enable ?>
	<h2><?php echo esc_html( $row['subject'] ); ?></h2>
	<?php foreach ( $attempts as $attempt ) : ?>
		<h3><?php echo esc_html( $attempt['attempt_number'] . ': ' . $attempt['connection_id'] . ' (' . $attempt['status'] . ')' ); ?></h3>
		<table class="widefat striped">
			<tbody>
			<?php
			$labels = array(
				'created_at'          => __( 'Time (UTC)', 'sw-mailgate' ),
				'provider'            => __( 'Provider', 'sw-mailgate' ),
				'from_email'          => __( 'From', 'sw-mailgate' ),
				'recipients'          => __( 'Recipients', 'sw-mailgate' ),
				'original_to'         => __( 'Original recipients', 'sw-mailgate' ),
				'error_class'         => __( 'Error class', 'sw-mailgate' ),
				'error_message'       => __( 'Error', 'sw-mailgate' ),
				'duration'            => __( 'Duration (s)', 'sw-mailgate' ),
				'provider_message_id' => __( 'Provider message ID', 'sw-mailgate' ),
				'caller'              => __( 'Caller', 'sw-mailgate' ),
			);
			foreach ( $labels as $key => $label ) :
				?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $attempt[ $key ] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
	<?php if ( is_array( $payload ) ) : ?>
		<h2><?php esc_html_e( 'Stored message source', 'sw-mailgate' ); ?></h2>
		<pre><?php echo esc_html( $payload['body'] ); ?></pre>
		<?php if ( empty( $payload['attachments'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sw_mailgate_resend_' . $row['id'] ); ?>
				<input type="hidden" name="action" value="sw_mailgate_resend">
				<input type="hidden" name="log_id" value="<?php echo esc_attr( $row['id'] ); ?>">
				<p><?php esc_html_e( 'Send another copy to the original To, Cc and Bcc recipients through the current failover chain.', 'sw-mailgate' ); ?></p>
				<?php submit_button( __( 'Resend message', 'sw-mailgate' ) ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'Attachments are not retained. This message cannot be resent from the log.', 'sw-mailgate' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<p><?php esc_html_e( 'No message content was stored. Resending is unavailable.', 'sw-mailgate' ); ?></p>
	<?php endif; ?>
</div>
