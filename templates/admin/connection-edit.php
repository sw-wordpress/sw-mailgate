<?php
/**
 * Create or edit one connection. Provider fields are rendered from Provider::fields().
 *
 * @package SWMailgate
 *
 * @var array<string, mixed> $data
 */

use SWMailgate\Admin\Admin_Page;
use SWMailgate\Connections\Connection;
use SWMailgate\Providers\Field;

defined( 'ABSPATH' ) || exit;

/** @var Connection|null $connection */
$connection = $data['connection'];
/** @var SWMailgate\Providers\Provider $provider */
$provider = $data['provider'];
$is_new   = null === $connection;
$settings = $is_new ? array() : $connection->settings();

/**
 * Hint shown for values defined in wp-config.php; the field is then read-only.
 */
$locked_hint = static function ( ?Connection $connection, string $key ): string {
	if ( null === $connection || ! $connection->is_locked( $key ) ) {
		return '';
	}
	return sprintf(
		/* translators: %s: constant name */
		__( 'Defined in wp-config.php as %s.', 'sw-mailgate' ),
		$connection->constant_name( $key )
	);
};
?>
<div class="wrap swmg">
	<h1>
		<?php
		echo esc_html(
			$is_new
				/* translators: %s: provider name */
				? sprintf( __( 'Add %s connection', 'sw-mailgate' ), $provider->label() )
				/* translators: %s: connection label */
				: sprintf( __( 'Edit connection "%s"', 'sw-mailgate' ), $connection->label() )
		);
		?>
	</h1>
	<p><a href="<?php echo esc_url( Admin_Page::url() ); ?>">&larr; <?php esc_html_e( 'Back to connections', 'sw-mailgate' ); ?></a></p>

	<?php require __DIR__ . '/messages.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
		<?php wp_nonce_field( 'sw_mailgate_save_connection' ); ?>
		<input type="hidden" name="action" value="sw_mailgate_save_connection">
		<input type="hidden" name="provider" value="<?php echo esc_attr( $provider->id() ); ?>">
		<?php if ( ! $is_new ) : ?>
			<input type="hidden" name="connection" value="<?php echo esc_attr( $connection->id() ); ?>">
		<?php endif; ?>

		<h2><?php esc_html_e( 'General', 'sw-mailgate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swmg-label"><?php esc_html_e( 'Name', 'sw-mailgate' ); ?></label></th>
				<td>
					<input type="text" id="swmg-label" name="label" class="regular-text" value="<?php echo esc_attr( $is_new ? $provider->label() : $connection->label() ); ?>">
					<?php if ( ! $is_new ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: constant prefix */
								esc_html__( 'Every value can be set in wp-config.php instead, using a constant with the prefix %s followed by the field key in upper case.', 'sw-mailgate' ),
								'<code>' . esc_html( Connection::build_constant_name( $connection->id(), '' ) ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'sw-mailgate' ); ?></th>
				<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $is_new || $connection->is_enabled() ); ?>> <?php esc_html_e( 'Use this connection', 'sw-mailgate' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Sender', 'sw-mailgate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="swmg-from-email"><?php esc_html_e( 'From address', 'sw-mailgate' ); ?></label></th>
				<td>
					<input type="email" id="swmg-from-email" name="from_email" class="regular-text" value="<?php echo esc_attr( $is_new ? '' : $connection->from_email() ); ?>" <?php disabled( '' !== $locked_hint( $connection, 'from_email' ) ); ?>>
					<p class="description"><?php echo esc_html( $locked_hint( $connection, 'from_email' ) ?: __( 'An address this provider may send from (verified domain or mailbox). Leave empty to keep the sender set by WordPress or the sending plugin.', 'sw-mailgate' ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="swmg-from-name"><?php esc_html_e( 'From name', 'sw-mailgate' ); ?></label></th>
				<td><input type="text" id="swmg-from-name" name="from_name" class="regular-text" value="<?php echo esc_attr( $is_new ? '' : $connection->from_name() ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Rules', 'sw-mailgate' ); ?></th>
				<td>
					<label><input type="checkbox" name="force_from" value="1" <?php checked( $is_new || $connection->forces_from() ); ?>> <?php esc_html_e( 'Always use this sender (otherwise only the WordPress default "wordpress@…" is replaced)', 'sw-mailgate' ); ?></label><br>
					<label><input type="checkbox" name="reply_to_original" value="1" <?php checked( $is_new || $connection->reply_to_original() ); ?>> <?php esc_html_e( 'Use the original sender as Reply-To when it is replaced', 'sw-mailgate' ); ?></label>
				</td>
			</tr>
		</table>

		<h2><?php echo esc_html( $provider->label() ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $provider->fields() as $field ) : ?>
				<?php
				$input_id = 'swmg-field-' . $field->key;
				$hint     = $locked_hint( $connection, $field->key );
				$locked   = '' !== $hint;
				$value    = $field->secret ? '' : ( $locked ? $connection->get( $field->key, $field->default ) : (string) ( $settings[ $field->key ] ?? $field->default ) );
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field->label ); ?><?php echo $field->required ? ' *' : ''; ?></label></th>
					<td>
						<?php if ( $field->secret ) : ?>
							<?php $has_secret = ! $is_new && $connection->has_secret( $field->key ); ?>
							<input type="password" id="<?php echo esc_attr( $input_id ); ?>" name="secrets[<?php echo esc_attr( $field->key ); ?>]" class="regular-text" value="" autocomplete="new-password"
								placeholder="<?php echo esc_attr( $has_secret ? __( '•••••••• (stored, leave empty to keep)', 'sw-mailgate' ) : '' ); ?>" <?php disabled( $locked ); ?>>
							<?php if ( $has_secret && ! $locked ) : ?>
								<label class="swmg-clear"><input type="checkbox" name="clear_secrets[<?php echo esc_attr( $field->key ); ?>]" value="1"> <?php esc_html_e( 'Delete stored value', 'sw-mailgate' ); ?></label>
							<?php endif; ?>
						<?php elseif ( Field::SELECT === $field->type ) : ?>
							<select id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $field->key ); ?>]" <?php disabled( $locked ); ?>>
								<?php foreach ( $field->options as $option_value => $option_label ) : ?>
									<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( Field::CHECKBOX === $field->type ) : ?>
							<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $field->key ); ?>]" value="1" <?php checked( $field->is_checked( $value ) ); ?> <?php disabled( $locked ); ?>>
						<?php else : ?>
							<input type="<?php echo esc_attr( $field->type ); ?>" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $field->key ); ?>]"
								class="<?php echo esc_attr( Field::NUMBER === $field->type ? 'small-text' : 'regular-text' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php disabled( $locked ); ?>>
						<?php endif; ?>
						<?php if ( $locked || '' !== $field->description ) : ?>
							<p class="description"><?php echo esc_html( $locked ? $hint : $field->description ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php submit_button( $is_new ? __( 'Add connection', 'sw-mailgate' ) : __( 'Save connection', 'sw-mailgate' ) ); ?>
	</form>

	<?php if ( ! $is_new ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sw_mailgate_connection_' . $connection->id() ); ?>
			<input type="hidden" name="action" value="sw_mailgate_test_connection">
			<input type="hidden" name="connection" value="<?php echo esc_attr( $connection->id() ); ?>">
			<?php submit_button( __( 'Test connection (no mail is sent)', 'sw-mailgate' ), 'secondary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( Admin_Page::url( array( 'connection' => $connection->id() ), Admin_Page::TEST_SLUG ) ); ?>"><?php esc_html_e( 'Send test mail', 'sw-mailgate' ); ?></a>
		</form>
	<?php endif; ?>
</div>
