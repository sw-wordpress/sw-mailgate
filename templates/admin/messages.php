<?php
/**
 * Notice and test results shared by all admin views.
 *
 * @package SWMailgate
 *
 * @var array<string, mixed> $data
 */

defined( 'ABSPATH' ) || exit;

if ( ! empty( $data['notice'] ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $data['notice']['type'] ); ?> is-dismissible"><p><?php echo esc_html( $data['notice']['message'] ); ?></p></div>
	<?php
endif;

if ( ! empty( $data['results'] ) ) :
	$classes = array(
		'transient' => __( 'temporary error, failover allowed', 'sw-mailgate' ),
		'auth'      => __( 'login or configuration error, failover allowed', 'sw-mailgate' ),
		'permanent' => __( 'message rejected, no failover', 'sw-mailgate' ),
	);
	?>
	<div class="swmg-results">
		<h2><?php echo esc_html( $data['results']['title'] ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Connection', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Result', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Sender', 'sw-mailgate' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'sw-mailgate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['results']['rows'] as $index => $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td><?php echo esc_html( $row['connection'] ); ?></td>
						<td>
							<?php if ( $row['success'] ) : ?>
								<span class="swmg-status swmg-status--ok"><?php esc_html_e( 'OK', 'sw-mailgate' ); ?></span>
							<?php else : ?>
								<span class="swmg-status swmg-status--error"><?php esc_html_e( 'Failed', 'sw-mailgate' ); ?></span>
								<?php if ( isset( $classes[ $row['class'] ] ) ) : ?>
									<small>(<?php echo esc_html( $classes[ $row['class'] ] ); ?>)</small>
								<?php endif; ?>
								<div class="swmg-error"><?php echo esc_html( $row['message'] ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $row['debug'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'Details', 'sw-mailgate' ); ?></summary>
									<pre class="swmg-debug"><?php echo esc_html( implode( "\n", $row['debug'] ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['from'] ); ?></td>
						<td><?php echo esc_html( $row['duration'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
endif;
