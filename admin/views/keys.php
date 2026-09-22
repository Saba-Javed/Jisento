<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Migration Key', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'Generate a cryptographically random, short-lived key. The key is not a password and never contains database credentials.', 'jisento' ); ?></p>
	<button type="button" class="button button-primary" id="jisento-generate-key"><?php esc_html_e( 'Generate Migration Key', 'jisento' ); ?></button>
	<div id="jisento-key-display" class="jisento-key-box" hidden></div>
</section>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Receive Migration', 'jisento' ); ?></h2>
	<label><?php esc_html_e( 'Source Site URL', 'jisento' ); ?>
		<input type="url" id="jisento-connect-url" class="regular-text" placeholder="https://oldsite.com"></label>
	<label><?php esc_html_e( 'Enter Migration Key', 'jisento' ); ?>
		<input type="text" id="jisento-connect-key" class="regular-text" placeholder="JIS-8F92-KD71-XXXX"></label>
	<p>
		<button type="button" class="button" id="jisento-test-connection"><?php esc_html_e( 'Test Connection', 'jisento' ); ?></button>
		<button type="button" class="button button-primary" id="jisento-connect"><?php esc_html_e( 'Connect', 'jisento' ); ?></button>
	</p>
	<div id="jisento-test-results" hidden></div>
	<div id="jisento-source-info" hidden></div>
</section>
<section class="jisento-card">
	<h2><?php esc_html_e( 'Recent Keys', 'jisento' ); ?></h2>
	<table class="widefat striped" id="jisento-keys-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Hint', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Status', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'jisento' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'jisento' ); ?></th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>
</section>
<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
