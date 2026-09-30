<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<nav class="jisento-wizard-nav" id="jisento-wizard-nav" aria-label="<?php esc_attr_e( 'Receive migration steps', 'jisento-migration' ); ?>">
	<ol class="jisento-wizard-steps">
		<li>
			<button type="button" class="jisento-wizard-step is-current" data-wizard-goto="1" aria-current="step">
				<span class="jisento-wizard-num">1</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Key', 'jisento-migration' ); ?></span>
			</button>
		</li>
		<li>
			<button type="button" class="jisento-wizard-step" data-wizard-goto="2" disabled>
				<span class="jisento-wizard-num">2</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Mode', 'jisento-migration' ); ?></span>
			</button>
		</li>
		<li>
			<button type="button" class="jisento-wizard-step" data-wizard-goto="3" disabled>
				<span class="jisento-wizard-num">3</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Review', 'jisento-migration' ); ?></span>
			</button>
		</li>
	</ol>
</nav>

<section class="jisento-wizard-panel jisento-card" id="jisento-wizard-step-1" data-wizard-step="1">
	<h2><?php esc_html_e( 'Receive migration from key', 'jisento-migration' ); ?></h2>
	<p><?php esc_html_e( 'Paste the migration key from the source site (URL#key or the key alone with the source URL).', 'jisento-migration' ); ?></p>
	<label><?php esc_html_e( 'Migration key', 'jisento-migration' ); ?>
		<input type="text" id="jisento-connect-key" class="regular-text" placeholder="https://source.example/#JIS-8F92-KD71-XXXX" autocomplete="off"></label>
	<label><?php esc_html_e( 'Source site URL (if not included in the key)', 'jisento-migration' ); ?>
		<input type="url" id="jisento-connect-url" class="regular-text" placeholder="https://source.example"></label>
	<p><button type="button" class="button button-primary" id="jisento-connect"><?php esc_html_e( 'Connect', 'jisento-migration' ); ?></button></p>
	<div id="jisento-source-info" class="jisento-connection-result" hidden></div>
</section>

<?php include JISENTO_PATH . 'admin/views/partials/mode-review.php'; ?>

<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
