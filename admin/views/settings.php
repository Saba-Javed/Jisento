<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Settings', 'jisento' ); ?></h2>
	<form id="jisento-settings-form">
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Keep last backups', 'jisento' ); ?></th>
				<td><input type="number" min="1" name="keep_backups" value="<?php echo esc_attr( $settings['keep_backups'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Chunk size (bytes)', 'jisento' ); ?></th>
				<td><input type="number" min="65536" name="chunk_size" value="<?php echo esc_attr( $settings['chunk_size'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Migration key lifetime (seconds)', 'jisento' ); ?></th>
				<td><input type="number" min="60" name="key_ttl" value="<?php echo esc_attr( $settings['key_ttl'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Single-use keys', 'jisento' ); ?></th>
				<td><label><input type="checkbox" name="key_single_use" <?php checked( $settings['key_single_use'] ); ?>> <?php esc_html_e( 'Keys cannot be reused', 'jisento' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Require HTTPS for remote migration', 'jisento' ); ?></th>
				<td><label><input type="checkbox" name="https_required" <?php checked( $settings['https_required'] ); ?>></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Skip cache files by default', 'jisento' ); ?></th>
				<td><label><input type="checkbox" name="skip_cache" <?php checked( $settings['skip_cache'] ); ?>></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default plugin conflict strategy', 'jisento' ); ?></th>
				<td>
					<select name="default_plugin_strategy">
						<option value="replace_matching" <?php selected( $settings['default_plugin_strategy'], 'replace_matching' ); ?>><?php esc_html_e( 'Replace matching', 'jisento' ); ?></option>
						<option value="keep_destination" <?php selected( $settings['default_plugin_strategy'], 'keep_destination' ); ?>><?php esc_html_e( 'Keep destination', 'jisento' ); ?></option>
						<option value="install_missing" <?php selected( $settings['default_plugin_strategy'], 'install_missing' ); ?>><?php esc_html_e( 'Install missing', 'jisento' ); ?></option>
						<option value="skip" <?php selected( $settings['default_plugin_strategy'], 'skip' ); ?>><?php esc_html_e( 'Skip source plugins', 'jisento' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default theme conflict strategy', 'jisento' ); ?></th>
				<td>
					<select name="default_theme_strategy">
						<option value="keep_destination" <?php selected( $settings['default_theme_strategy'], 'keep_destination' ); ?>><?php esc_html_e( 'Keep destination', 'jisento' ); ?></option>
						<option value="replace_matching" <?php selected( $settings['default_theme_strategy'], 'replace_matching' ); ?>><?php esc_html_e( 'Replace matching', 'jisento' ); ?></option>
						<option value="install_missing" <?php selected( $settings['default_theme_strategy'], 'install_missing' ); ?>><?php esc_html_e( 'Install missing', 'jisento' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'jisento' ); ?></button></p>
	</form>
</section>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Diagnostics', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'Hosting checks used when a migration fails before it starts.', 'jisento' ); ?></p>
	<button type="button" class="button" id="jisento-run-diagnostics"><?php esc_html_e( 'Run Diagnostics', 'jisento' ); ?></button>
	<div id="jisento-diagnostics"></div>
</section>
