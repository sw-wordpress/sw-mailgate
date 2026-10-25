<?php
/**
 * Mail log list and filters.
 *
 * @package SWMailgate
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Mail log', 'sw-mailgate' ); ?></h1>
	<p><?php esc_html_e( 'Each row is a connection attempt. Open the subject to see all attempts for that message. Dates are UTC.', 'sw-mailgate' ); ?></p>
	<form method="get">
		<input type="hidden" name="page" value="sw-mailgate-log">
		<label for="swmg-status"><?php esc_html_e( 'Status', 'sw-mailgate' ); ?></label>
		<select name="status" id="swmg-status">
			<option value=""><?php esc_html_e( 'All statuses', 'sw-mailgate' ); ?></option>
			<option value="sent" <?php selected( $filters['status'], 'sent' ); ?>><?php esc_html_e( 'Sent', 'sw-mailgate' ); ?></option>
			<option value="redirected" <?php selected( $filters['status'], 'redirected' ); ?>><?php esc_html_e( 'Redirected', 'sw-mailgate' ); ?></option>
			<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'sw-mailgate' ); ?></option>
		</select>
		<label for="swmg-log-connection"><?php esc_html_e( 'Connection', 'sw-mailgate' ); ?></label>
		<select name="connection_id" id="swmg-log-connection">
			<option value=""><?php esc_html_e( 'All connections', 'sw-mailgate' ); ?></option>
			<?php foreach ( $connections as $connection ) : ?>
				<option value="<?php echo esc_attr( $connection->id() ); ?>" <?php selected( $filters['connection_id'], $connection->id() ); ?>><?php echo esc_html( $connection->label() ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="swmg-date-from"><?php esc_html_e( 'From date', 'sw-mailgate' ); ?></label>
		<input type="date" name="date_from" id="swmg-date-from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
		<label for="swmg-date-to"><?php esc_html_e( 'To date', 'sw-mailgate' ); ?></label>
		<input type="date" name="date_to" id="swmg-date-to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
		<?php submit_button( __( 'Filter', 'sw-mailgate' ), 'secondary', '', false ); ?>
		<?php $table->search_box( __( 'Search mail', 'sw-mailgate' ), 'swmg-search' ); ?>
		<?php $table->display(); ?>
	</form>
	<p><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export filtered log as CSV', 'sw-mailgate' ); ?></a></p>
</div>
