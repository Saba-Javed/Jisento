<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Settings', 'jisento-migration' ); ?></h2>
	<form id="jisento-settings-form">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Keep last backups', 'jisento-migration' ); ?></th>
				<td><input type="number" min="1" name="keep_backups" value="<?php echo esc_attr( $jisento_settings['keep_backups'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Chunk size (bytes)', 'jisento-migration' ); ?></th>
				<td><input type="number" min="65536" name="chunk_size" value="<?php echo esc_attr( $jisento_settings['chunk_size'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Migration key lifetime (seconds)', 'jisento-migration' ); ?></th>
				<td><input type="number" min="60" name="key_ttl" value="<?php echo esc_attr( $jisento_settings['key_ttl'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Single-use keys', 'jisento-migration' ); ?></th>
				<td><label><input type="checkbox" name="key_single_use" <?php checked( $jisento_settings['key_single_use'] ); ?>> <?php esc_html_e( 'Keys cannot be reused', 'jisento-migration' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Require HTTPS for remote migration', 'jisento-migration' ); ?></th>
				<td><label><input type="checkbox" name="https_required" <?php checked( $jisento_settings['https_required'] ); ?>></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Skip cache files by default', 'jisento-migration' ); ?></th>
				<td><label><input type="checkbox" name="skip_cache" <?php checked( $jisento_settings['skip_cache'] ); ?>></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Delete backups on uninstall', 'jisento-migration' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="delete_backups_on_uninstall" <?php checked( ! empty( $jisento_settings['delete_backups_on_uninstall'] ) ); ?>>
						<?php esc_html_e( 'Remove the Jisento storage folder when the plugin is deleted (default: keep backups).', 'jisento-migration' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default plugin conflict strategy', 'jisento-migration' ); ?></th>
				<td>
					<select name="default_plugin_strategy">
						<option value="replace_matching" <?php selected( $jisento_settings['default_plugin_strategy'], 'replace_matching' ); ?>><?php esc_html_e( 'Replace matching', 'jisento-migration' ); ?></option>
						<option value="keep_destination" <?php selected( $jisento_settings['default_plugin_strategy'], 'keep_destination' ); ?>><?php esc_html_e( 'Keep destination', 'jisento-migration' ); ?></option>
						<option value="install_missing" <?php selected( $jisento_settings['default_plugin_strategy'], 'install_missing' ); ?>><?php esc_html_e( 'Install missing', 'jisento-migration' ); ?></option>
						<option value="skip" <?php selected( $jisento_settings['default_plugin_strategy'], 'skip' ); ?>><?php esc_html_e( 'Skip source plugins', 'jisento-migration' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default theme conflict strategy', 'jisento-migration' ); ?></th>
				<td>
					<select name="default_theme_strategy">
						<option value="keep_destination" <?php selected( $jisento_settings['default_theme_strategy'], 'keep_destination' ); ?>><?php esc_html_e( 'Keep destination', 'jisento-migration' ); ?></option>
						<option value="replace_matching" <?php selected( $jisento_settings['default_theme_strategy'], 'replace_matching' ); ?>><?php esc_html_e( 'Replace matching', 'jisento-migration' ); ?></option>
						<option value="install_missing" <?php selected( $jisento_settings['default_theme_strategy'], 'install_missing' ); ?>><?php esc_html_e( 'Install missing', 'jisento-migration' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'jisento-migration' ); ?></button></p>
	</form>
</section>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Diagnostics', 'jisento-migration' ); ?></h2>
	<p><?php esc_html_e( 'Hosting checks used when a migration fails before it starts.', 'jisento-migration' ); ?></p>
	<button type="button" class="button" id="jisento-run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'jisento-migration' ); ?></button>
	<button type="button" class="button" id="jisento-copy-diagnostics" hidden><?php esc_html_e( 'Copy results', 'jisento-migration' ); ?></button>
	<div id="jisento-diagnostics" role="status"></div>
</section>
