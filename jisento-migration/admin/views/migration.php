<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="jisento-grid">
	<section class="jisento-card">
		<h2><?php esc_html_e( 'Export / Migrate Site', 'jisento-migration' ); ?></h2>
		<p><?php esc_html_e( 'Package this website into a .jisento backup or generate a key for direct server-to-server transfer.', 'jisento-migration' ); ?></p>
		<div class="jisento-actions">
			<button type="button" class="button button-primary button-hero" id="jisento-open-export"><?php esc_html_e( 'Create .jisento Backup', 'jisento-migration' ); ?></button>
			<a class="button button-hero" id="jisento-open-key-send" href="<?php echo esc_url( admin_url( 'admin.php?page=jisento-key-send' ) ); ?>"><?php esc_html_e( 'Migrate using migration key', 'jisento-migration' ); ?></a>
		</div>
	</section>
	<section class="jisento-card">
		<h2><?php esc_html_e( 'Import / Restore Site', 'jisento-migration' ); ?></h2>
		<p><?php esc_html_e( 'Restore from a .jisento file or receive a migration from another site.', 'jisento-migration' ); ?></p>
		<div class="jisento-actions">
			<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jisento-import' ) ); ?>"><?php esc_html_e( 'Import .jisento File', 'jisento-migration' ); ?></a>
			<a class="button button-hero" id="jisento-open-key-receive" href="<?php echo esc_url( admin_url( 'admin.php?page=jisento-key-receive' ) ); ?>"><?php esc_html_e( 'Receive migration from key', 'jisento-migration' ); ?></a>
		</div>
	</section>
</div>

<div id="jisento-export-modal" class="jisento-modal" hidden>
	<div class="jisento-modal-inner">
		<h2><?php esc_html_e( 'Export Options', 'jisento-migration' ); ?></h2>
		<label class="jisento-choice">
			<input type="radio" name="jisento_export_mode" value="full" checked>
			<strong><?php esc_html_e( 'Full Website', 'jisento-migration' ); ?></strong>
			<span><?php esc_html_e( 'Database, plugins, themes, uploads, and WordPress content.', 'jisento-migration' ); ?></span>
		</label>
		<h3><?php esc_html_e( 'Advanced Options', 'jisento-migration' ); ?></h3>
		<label><input type="checkbox" id="jisento-skip-cache" checked> <?php esc_html_e( 'Skip temporary/cache files', 'jisento-migration' ); ?></label>
		<label><input type="checkbox" id="jisento-skip-backups" checked> <?php esc_html_e( 'Exclude backup files', 'jisento-migration' ); ?></label>
		<label><?php esc_html_e( 'Exclude selected plugins (slugs, comma separated)', 'jisento-migration' ); ?>
			<input type="text" class="regular-text" id="jisento-exclude-plugins"></label>
		<label><?php esc_html_e( 'Exclude selected directories', 'jisento-migration' ); ?>
			<input type="text" class="regular-text" id="jisento-exclude-dirs"></label>
		<label><?php esc_html_e( 'Exclude selected database tables', 'jisento-migration' ); ?>
			<input type="text" class="regular-text" id="jisento-exclude-tables"></label>
		<p>
			<button type="button" class="button button-primary" id="jisento-start-export"><?php esc_html_e( 'Start Export', 'jisento-migration' ); ?></button>
			<button type="button" class="button jisento-close"><?php esc_html_e( 'Cancel', 'jisento-migration' ); ?></button>
		</p>
	</div>
</div>

<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
