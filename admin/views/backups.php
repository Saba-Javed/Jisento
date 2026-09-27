<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$download_base = add_query_arg(
	array(
		'action'   => 'jisento_download',
		'_wpnonce' => wp_create_nonce( 'jisento_download' ),
	),
	admin_url( 'admin-post.php' )
);
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Backups', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'Every .jisento package saved in wp-content/jisento is listed here, including manual full, database, and wp-content backups. A backup is marked Completed when the file exists, is larger than 0 bytes, and matches its registry record. A backup created in the last hour is never removed by retention.', 'jisento' ); ?></p>
	<p><button type="button" class="button button-primary" id="jisento-open-backup"><?php esc_html_e( 'Create New Backup', 'jisento' ); ?></button></p>
	<table class="widefat striped" id="jisento-backups-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Type', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Size', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Created', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Status', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'jisento' ); ?></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>
</section>
<div id="jisento-backup-modal" class="jisento-modal" hidden>
	<div class="jisento-modal-inner">
		<h2><?php esc_html_e( 'Create Backup', 'jisento' ); ?></h2>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="full" checked> <?php esc_html_e( 'Full Site', 'jisento' ); ?></label>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="database"> <?php esc_html_e( 'Database Only', 'jisento' ); ?></label>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="files"> <?php esc_html_e( 'wp-content Only', 'jisento' ); ?></label>
		<p>
			<button type="button" class="button button-primary" id="jisento-start-backup"><?php esc_html_e( 'Create Backup', 'jisento' ); ?></button>
			<button type="button" class="button jisento-close"><?php esc_html_e( 'Cancel', 'jisento' ); ?></button>
		</p>
	</div>
</div>
<script>window.jisentoDownloadBase = <?php echo wp_json_encode( $download_base ); ?>;</script>
<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
