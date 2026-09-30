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
	<h2><?php esc_html_e( 'Backups', 'jisento-migration' ); ?></h2>
	<p><?php esc_html_e( 'Every .jisento package saved in the Jisento storage folder is listed here, including manual full, database, and wp-content backups. A backup is marked Completed when the file exists, is larger than 0 bytes, and matches its registry record. A backup created in the last hour is never removed by retention.', 'jisento-migration' ); ?></p>
	<p><button type="button" class="button button-primary" id="jisento-open-backup"><?php esc_html_e( 'Create New Backup', 'jisento-migration' ); ?></button></p>
	<table class="widefat striped" id="jisento-backups-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'jisento-migration' ); ?></th>
				<th><?php esc_html_e( 'Type', 'jisento-migration' ); ?></th>
				<th><?php esc_html_e( 'Size', 'jisento-migration' ); ?></th>
				<th><?php esc_html_e( 'Created', 'jisento-migration' ); ?></th>
				<th><?php esc_html_e( 'Status', 'jisento-migration' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'jisento-migration' ); ?></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>
</section>
<div id="jisento-backup-modal" class="jisento-modal" hidden>
	<div class="jisento-modal-inner">
		<h2><?php esc_html_e( 'Create Backup', 'jisento-migration' ); ?></h2>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="full" checked> <?php esc_html_e( 'Full Site', 'jisento-migration' ); ?></label>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="database"> <?php esc_html_e( 'Database Only', 'jisento-migration' ); ?></label>
		<label class="jisento-choice"><input type="radio" name="jisento_backup_mode" value="files"> <?php esc_html_e( 'wp-content Only', 'jisento-migration' ); ?></label>
		<p>
			<button type="button" class="button button-primary" id="jisento-start-backup"><?php esc_html_e( 'Create Backup', 'jisento-migration' ); ?></button>
			<button type="button" class="button jisento-close"><?php esc_html_e( 'Cancel', 'jisento-migration' ); ?></button>
		</p>
	</div>
</div>
<script>window.jisentoDownloadBase = <?php echo wp_json_encode( $download_base ); ?>;</script>
<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
