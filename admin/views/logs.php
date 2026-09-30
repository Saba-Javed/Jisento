<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Migration history', 'jisento' ); ?></h2>
	<?php if ( empty( $history ) ) : ?>
		<p><?php esc_html_e( 'No migrations yet.', 'jisento' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" id="jisento-history-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'jisento' ); ?></th>
					<th><?php esc_html_e( 'Type', 'jisento' ); ?></th>
					<th><?php esc_html_e( 'Result', 'jisento' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'jisento' ); ?></th>
					<th><?php esc_html_e( 'Details', 'jisento' ); ?></th>
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
								<summary><?php esc_html_e( 'Details', 'jisento' ); ?></summary>
								<?php if ( ! empty( $row['details_lines'] ) ) : ?>
									<ul class="jisento-history-lines">
										<?php foreach ( $row['details_lines'] as $line ) : ?>
											<li><?php echo esc_html( $line ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<p><?php esc_html_e( 'No extra details recorded.', 'jisento' ); ?></p>
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
	<summary><?php esc_html_e( 'Advanced', 'jisento' ); ?></summary>
	<div class="jisento-advanced-body">
		<h3><?php esc_html_e( 'Debug log', 'jisento' ); ?></h3>
		<p><?php esc_html_e( 'Download a plain-text debug log for a migration. Secrets are redacted.', 'jisento' ); ?></p>
		<?php if ( empty( $history ) ) : ?>
			<p><?php esc_html_e( 'No jobs available.', 'jisento' ); ?></p>
		<?php else : ?>
			<ul class="jisento-debug-downloads">
				<?php foreach ( $history as $row ) : ?>
					<li>
						<span><?php echo esc_html( $row['date'] . ' — ' . $row['type_label'] ); ?></span>
						<a class="button button-small" href="<?php echo esc_url( $row['log_url'] ); ?>"><?php esc_html_e( 'Download debug log', 'jisento' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</details>
