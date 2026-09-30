<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="jisento-card" id="jisento-key-send">
	<h2><?php esc_html_e( 'Migrate using migration key', 'jisento' ); ?></h2>
	<p><?php esc_html_e( 'Generate a short-lived key on this site, then paste it on the destination under Import → Receive migration from key.', 'jisento' ); ?></p>
	<p><button type="button" class="button button-primary" id="jisento-generate-key"><?php esc_html_e( 'Generate migration key', 'jisento' ); ?></button></p>
	<div id="jisento-key-display" class="jisento-key-box" hidden></div>
</section>

<section class="jisento-card">
	<h2><?php esc_html_e( 'Active keys', 'jisento' ); ?></h2>
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
