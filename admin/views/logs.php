<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$log_url = wp_nonce_url( admin_url( 'admin-post.php?action=jisento_log' ), 'jisento_log' );
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Logs', 'jisento' ); ?></h2>
	<p><a class="button" href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Download Debug Log', 'jisento' ); ?></a></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Migration', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Stage', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Operation', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Item', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Status', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Message', 'jisento' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No log entries yet.', 'jisento' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->migration_id ); ?></td>
						<td><?php echo esc_html( $row->stage ); ?></td>
						<td><?php echo esc_html( $row->operation ); ?></td>
						<td><?php echo esc_html( $row->item ); ?></td>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td><?php echo esc_html( $row->message ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</section>
