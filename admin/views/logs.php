<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Migration history', 'jisento-migration' ); ?></h2>
	<?php if ( empty( $jisento_history ) ) : ?>
		<p><?php esc_html_e( 'No migrations yet.', 'jisento-migration' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" id="jisento-history-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'jisento-migration' ); ?></th>
					<th><?php esc_html_e( 'Type', 'jisento-migration' ); ?></th>
					<th><?php esc_html_e( 'Result', 'jisento-migration' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'jisento-migration' ); ?></th>
					<th><?php esc_html_e( 'Details', 'jisento-migration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $jisento_history as $jisento_row ) : ?>
					<tr>
						<td><?php echo esc_html( $jisento_row['date'] ); ?></td>
						<td><?php echo esc_html( $jisento_row['type_label'] ); ?></td>
						<td><?php echo esc_html( $jisento_row['result'] ); ?></td>
						<td><?php echo esc_html( $jisento_row['duration'] ); ?></td>
						<td>
							<details class="jisento-history-details">
								<summary><?php esc_html_e( 'Details', 'jisento-migration' ); ?></summary>
								<?php if ( ! empty( $jisento_row['details_lines'] ) ) : ?>
									<ul class="jisento-history-lines">
										<?php foreach ( $jisento_row['details_lines'] as $jisento_line ) : ?>
											<li><?php echo esc_html( $jisento_line ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<p><?php esc_html_e( 'No extra details recorded.', 'jisento-migration' ); ?></p>
								<?php endif; ?>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<details class="jisento-advanced jisento-card" id="jisento-logs-advanced">
	<summary><?php esc_html_e( 'Advanced', 'jisento-migration' ); ?></summary>
	<div class="jisento-advanced-body">
		<h3><?php esc_html_e( 'Debug log', 'jisento-migration' ); ?></h3>
		<p><?php esc_html_e( 'Download a plain-text debug log for a migration. Secrets are redacted.', 'jisento-migration' ); ?></p>
		<?php if ( empty( $jisento_history ) ) : ?>
			<p><?php esc_html_e( 'No jobs available.', 'jisento-migration' ); ?></p>
		<?php else : ?>
			<ul class="jisento-debug-downloads">
				<?php foreach ( $jisento_history as $jisento_row ) : ?>
					<li>
						<span><?php echo esc_html( $jisento_row['date'] . ' — ' . $jisento_row['type_label'] ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( $jisento_row['log_url'] ); ?>"><?php esc_html_e( 'Download debug log', 'jisento-migration' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</details>
