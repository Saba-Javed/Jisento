<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Import .jisento File', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'Upload a package or choose an existing backup. Destination handling is never assumed — you must choose replace or preserve before import starts.', 'jisento' ); ?></p>
	<h3><?php esc_html_e( 'Upload Package', 'jisento' ); ?></h3>
	<div id="jisento-file-picker" class="jisento-file-picker">
		<div class="jisento-file-picker-controls">
			<input type="file" id="jisento-file" class="jisento-file-input" accept=".jisento">
			<button type="button" class="button button-primary" id="jisento-upload" data-jisento-upload disabled><?php esc_html_e( 'Upload', 'jisento' ); ?></button>
		</div>
		<div class="jisento-file-selected" data-jisento-selected hidden>
			<p class="jisento-file-selected-label"><?php esc_html_e( 'Selected file:', 'jisento' ); ?></p>
			<p class="jisento-file-selected-name" data-jisento-name></p>
			<p class="jisento-file-selected-size" data-jisento-size></p>
		</div>
		<p id="jisento-upload-status" class="jisento-file-picker-status" data-jisento-status role="status"></p>
		<p class="jisento-file-picker-bytes" data-jisento-bytes hidden></p>
		<div class="jisento-progress-bar" id="jisento-upload-bar" data-jisento-bar hidden><span></span></div>
	</div>
	<h3><?php esc_html_e( 'OR Existing Backups', 'jisento' ); ?></h3>
	<div id="jisento-existing-backups"><p><?php esc_html_e( 'Loading backups…', 'jisento' ); ?></p></div>
	<p><button type="button" class="button button-primary" id="jisento-use-existing"><?php esc_html_e( 'Continue', 'jisento' ); ?></button></p>
</section>

<section class="jisento-card" id="jisento-validation" hidden></section>

<section class="jisento-card" id="jisento-dest-mode" hidden>
	<h2><?php esc_html_e( 'Destination Handling', 'jisento' ); ?></h2>
	<p><?php echo esc_html( sprintf( __( 'Current website detected: %s', 'jisento' ), home_url() ) ); ?></p>
	<div class="jisento-grid">
		<label class="jisento-mode-card">
			<input type="radio" name="jisento_dest_mode" value="replace">
			<strong><?php esc_html_e( 'Replace Destination', 'jisento' ); ?></strong>
			<span><?php esc_html_e( 'Replace existing WordPress data with the source site.', 'jisento' ); ?></span>
		</label>
		<label class="jisento-mode-card">
			<input type="radio" name="jisento_dest_mode" value="preserve">
			<strong><?php esc_html_e( 'Preserve Destination', 'jisento' ); ?></strong>
			<span><?php esc_html_e( 'Keep selected destination components and resolve conflicts.', 'jisento' ); ?></span>
		</label>
	</div>
</section>

<section class="jisento-card jisento-warning" id="jisento-replace-warning" hidden>
	<h2><?php esc_html_e( 'WARNING', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'This operation will replace the existing destination WordPress website. Every table included in the package is replaced (the restore runs into work tables and they are swapped in at the end). Tables that exist only on this site are left in place. Plugins, themes, and uploads from the package are restored. Make sure you have a backup before continuing.', 'jisento' ); ?></p>
	<label><input type="checkbox" id="jisento-confirm-replace"> <?php esc_html_e( 'I understand and want to continue', 'jisento' ); ?></label>
</section>

<section class="jisento-card" id="jisento-preserve-options" hidden>
	<h2><?php esc_html_e( 'Destination Preservation', 'jisento' ); ?></h2>
	<label><input type="checkbox" class="jisento-preserve" data-key="preserve_plugins" checked> <?php esc_html_e( 'Preserve existing plugin files', 'jisento' ); ?></label>
	<label><input type="checkbox" class="jisento-preserve" data-key="preserve_themes" checked> <?php esc_html_e( 'Preserve existing theme files', 'jisento' ); ?></label>
	<label><input type="checkbox" class="jisento-preserve" data-key="preserve_uploads" checked> <?php esc_html_e( 'Preserve existing uploads (keep destination files on conflict)', 'jisento' ); ?></label>
	<h3><?php esc_html_e( 'Database tables', 'jisento' ); ?></h3>
	<p><?php esc_html_e( 'Preserve mode never changes a table that already exists on this site. Tables from the package that do not exist here are added. To replace an existing table, list it below.', 'jisento' ); ?></p>
	<label><?php esc_html_e( 'Existing tables to replace (comma separated, e.g. wp_posts, wp_postmeta)', 'jisento' ); ?> <input type="text" id="jisento-replace-tables" class="regular-text"></label>
	<h3><?php esc_html_e( 'Source Plugin Handling', 'jisento' ); ?></h3>
	<label><input type="radio" name="jisento_plugin_strategy" value="replace_matching"> <?php esc_html_e( 'Replace matching plugins', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_plugin_strategy" value="keep_destination" checked> <?php esc_html_e( 'Keep destination version', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_plugin_strategy" value="install_missing"> <?php esc_html_e( 'Install missing source plugins', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_plugin_strategy" value="skip"> <?php esc_html_e( 'Skip source plugins', 'jisento' ); ?></label>
	<h3><?php esc_html_e( 'Source Theme Handling', 'jisento' ); ?></h3>
	<label><input type="radio" name="jisento_theme_strategy" value="replace_matching"> <?php esc_html_e( 'Replace matching themes', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_theme_strategy" value="keep_destination" checked> <?php esc_html_e( 'Keep destination theme unless explicitly replaced', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_theme_strategy" value="install_missing"> <?php esc_html_e( 'Install missing source themes', 'jisento' ); ?></label>
	<label><input type="radio" name="jisento_theme_strategy" value="skip"> <?php esc_html_e( 'Skip source themes', 'jisento' ); ?></label>
</section>

<section class="jisento-card" id="jisento-url-options" hidden>
	<h2><?php esc_html_e( 'Domain / URL Migration', 'jisento' ); ?></h2>
	<label><?php esc_html_e( 'Source', 'jisento' ); ?> <input type="url" id="jisento-source-url" class="regular-text"></label>
	<label><?php esc_html_e( 'Destination', 'jisento' ); ?> <input type="url" id="jisento-dest-url" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>"></label>
	<label><input type="checkbox" id="jisento-replace-urls" checked> <?php esc_html_e( 'Replace source URLs with destination URLs', 'jisento' ); ?></label>
	<label><input type="checkbox" id="jisento-replace-guids"> <?php esc_html_e( 'Also replace post GUIDs (not recommended; feed readers use them as permanent IDs)', 'jisento' ); ?></label>
	<label><input type="checkbox" id="jisento-replace-emails" checked> <?php esc_html_e( 'Replace email addresses on the source domain (e.g. wordpress@old.example → wordpress@new.example)', 'jisento' ); ?></label>
	<h3><?php esc_html_e( 'Advanced', 'jisento' ); ?></h3>
	<label><input type="checkbox" id="jisento-repair-placeholders"> <?php esc_html_e( 'Repair % characters in packages made by version 1.2.11 or older (only if the import reports placeholder tokens)', 'jisento' ); ?></label>
	<label><input type="checkbox" id="jisento-restore-engines"> <?php esc_html_e( 'Convert MyISAM/Aria tables back to their original engine after the restore', 'jisento' ); ?></label>
	<p><button type="button" class="button button-primary" id="jisento-start-import"><?php esc_html_e( 'Start Import', 'jisento' ); ?></button></p>
</section>

<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
