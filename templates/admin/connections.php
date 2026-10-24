<?php
/**
 * Connections list = failover chain.
 *
 * @package SWMailgate
 *
 * @var array<string, mixed> $data
 */

use SWMailgate\Admin\Admin_Page;

defined( 'ABSPATH' ) || exit;

/** @var SWMailgate\Connections\Connection[] $connections */
$connections = $data['connections'];
/** @var array<string, SWMailgate\Providers\Provider> $providers */
$providers = $data['providers'];
$last      = count( $connections ) - 1;

$action_button = static function ( string $id, string $action, string $label, string $css_class = 'button-link' ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swmg-inline-form"
		<?php if ( 'delete' === $action ) : ?>
			onsubmit="return window.confirm('<?php echo esc_js( __( 'Delete this connection and its stored credentials?', 'sw-mailgate' ) ); ?>');"
		<?php endif; ?>
	>
		<?php wp_nonce_field( 'sw_mailgate_connection_' . $id ); ?>
		<input type="hidden" name="action" value="sw_mailgate_connection_action">
		<input type="hidden" name="connection" value="<?php echo esc_attr( $id ); ?>">
		<input type="hidden" name="connection_action" value="<?php echo esc_attr( $action ); ?>">
		<button type="submit" class="<?php echo esc_attr( $css_class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<div class="wrap swmg">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Mail connections', 'sw-mailgate' ); ?></h1>
	<hr class="wp-header-end">

	<?php require __DIR__ . '/messages.php'; ?>

	<p class="description">
		<?php esc_html_e( 'Mails are sent through the first enabled connection. If it fails with a temporary or login error, the next one is tried. Messages rejected for their content or recipients are not retried elsewhere.', 'sw-mailgate' ); ?>
	</p>
	<?php if ( ! $data['chain_active'] ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No connection is enabled. WordPress currently sends mail with PHP mail().', 'sw-mailgate' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped swmg-connections">
		<thead>
			<tr>
				<th class="swmg-col-order"><?php esc_html_e( 'Order', 'sw-mailgate' ); ?></th>
				<th><?php esc_html_e( 'Connection', 'sw-mailgate' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'sw-mailgate' ); ?></th>
				<th><?php esc_html_e( 'Sender', 'sw-mailgate' ); ?></th>
				<th><?php esc_html_e( 'Status', 'sw-mailgate' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'sw-mailgate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $connections ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No connections yet. Add one below.', 'sw-mailgate' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $connections as $index => $connection ) : ?>
				<?php $provider = $providers[ $connection->provider() ] ?? null; ?>
				<tr>
					<td class="swmg-col-order">
						<?php echo esc_html( (string) ( $index + 1 ) ); ?>
						<?php
						if ( $index > 0 ) {
							$action_button( $connection->id(), 'up', '↑' );
						}
						if ( $index < $last ) {
							$action_button( $connection->id(), 'down', '↓' );
						}
						?>
					</td>
					<td>
						<strong><a href="
						<?php
						echo esc_url(
							Admin_Page::url(
								array(
									'view'       => 'edit',
									'connection' => $connection->id(),
								)
							)
						);
						?>
											"><?php echo esc_html( $connection->label() ); ?></a></strong>
						<br><code><?php echo esc_html( $connection->id() ); ?></code>
					</td>
					<td><?php echo esc_html( null === $provider ? $connection->provider() : $provider->label() ); ?></td>
					<td><?php echo esc_html( '' === $connection->from_email() ? '—' : $connection->from_email() ); ?></td>
					<td>
						<?php if ( $connection->is_enabled() ) : ?>
							<span class="swmg-status swmg-status--ok"><?php esc_html_e( 'Enabled', 'sw-mailgate' ); ?></span>
						<?php else : ?>
							<span class="swmg-status"><?php esc_html_e( 'Disabled', 'sw-mailgate' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a href="
						<?php
						echo esc_url(
							Admin_Page::url(
								array(
									'view'       => 'edit',
									'connection' => $connection->id(),
								)
							)
						);
						?>
									"><?php esc_html_e( 'Edit', 'sw-mailgate' ); ?></a> |
						<?php
						$action_button( $connection->id(), $connection->is_enabled() ? 'disable' : 'enable', $connection->is_enabled() ? __( 'Disable', 'sw-mailgate' ) : __( 'Enable', 'sw-mailgate' ) );
						echo ' | ';
						$action_button( $connection->id(), 'delete', __( 'Delete', 'sw-mailgate' ), 'button-link swmg-delete' );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Add connection', 'sw-mailgate' ); ?></h2>
	<p>
		<?php foreach ( $providers as $provider ) : ?>
			<a class="button" href="
			<?php
			echo esc_url(
				Admin_Page::url(
					array(
						'view'     => 'edit',
						'provider' => $provider->id(),
					)
				)
			);
			?>
									">
				<?php echo esc_html( $provider->label() ); ?>
			</a>
		<?php endforeach; ?>
	</p>

	<?php if ( ! $data['dedicated'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'Credentials are encrypted with a key derived from the WordPress salts. If the salts change, stored credentials become unreadable. Add a dedicated key to wp-config.php:', 'sw-mailgate' ); ?>
				<br><code>define( 'SW_MAILGATE_KEY', '<?php echo esc_html( wp_generate_password( 48, false ) ); ?>' );</code>
			</p>
		</div>
	<?php endif; ?>
</div>
