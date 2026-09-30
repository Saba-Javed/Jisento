<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Migration history', 'jisento-migration' ); ?></h2>
	<?php if ( empty( $history ) ) : ?>
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
				<?php foreach ( $history as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['date'] ); ?></td>
						<td><?php echo esc_html( $row['type_label'] ); ?></td>
						<td><?php echo esc_html( $row['result'] ); ?></td>
						<td><?php echo esc_html( $row['duration'] ); ?></td>
						<td>
							<details class="jisento-history-details">
								<summary><?php esc_html_e( 'Details', 'jisento-migration' ); ?></summary>
								<?php if ( ! empty( $row['details_lines'] ) ) : ?>
									<ul class="jisento-history-lines">
										<?php foreach ( $row['details_lines'] as $line ) : ?>
											<li><?php echo esc_html( $line ); ?></li>
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
		<?php if ( empty( $history ) ) : ?>
			<p><?php esc_html_e( 'No jobs available.', 'jisento-migration' ); ?></p>
		<?php else : ?>
			<ul class="jisento-debug-downloads">
				<?php foreach ( $history as $row ) : ?>
					<li>
						<span><?php echo esc_html( $row['date'] . ' — ' . $row['type_label'] ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( $row['log_url'] ); ?>"><?php esc_html_e( 'Download debug log', 'jisento-migration' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</details>
