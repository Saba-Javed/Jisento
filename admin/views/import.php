<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<nav class="jisento-wizard-nav" id="jisento-wizard-nav" aria-label="<?php esc_attr_e( 'Import steps', 'jisento' ); ?>">
	<ol class="jisento-wizard-steps">
		<li>
			<button type="button" class="jisento-wizard-step is-current" data-wizard-goto="1" aria-current="step">
				<span class="jisento-wizard-num">1</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Package', 'jisento' ); ?></span>
			</button>
		</li>
		<li>
			<button type="button" class="jisento-wizard-step" data-wizard-goto="2" disabled>
				<span class="jisento-wizard-num">2</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Mode', 'jisento' ); ?></span>
			</button>
		</li>
		<li>
			<button type="button" class="jisento-wizard-step" data-wizard-goto="3" disabled>
				<span class="jisento-wizard-num">3</span>
				<span class="jisento-wizard-label"><?php esc_html_e( 'Review', 'jisento' ); ?></span>
			</button>
		</li>
	</ol>
</nav>

<section class="jisento-wizard-panel" id="jisento-wizard-step-1" data-wizard-step="1">
	<h2><?php esc_html_e( 'Choose a package', 'jisento' ); ?></h2>
	<div class="jisento-grid jisento-package-grid">
		<div class="jisento-card">
			<h3><?php esc_html_e( 'Upload a .jisento file', 'jisento' ); ?></h3>
			<div id="jisento-file-picker" class="jisento-file-picker jisento-dropzone" data-jisento-dropzone>
				<p class="jisento-dropzone-hint"><?php esc_html_e( 'Drag and drop a .jisento file here, or choose a file.', 'jisento' ); ?></p>
				<div class="jisento-file-picker-controls">
					<input type="file" id="jisento-file" class="jisento-file-input" accept=".jisento">
					<button type="button" class="button" id="jisento-choose-file" data-jisento-choose><?php esc_html_e( 'Choose file', 'jisento' ); ?></button>
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
		</div>
		<div class="jisento-card">
			<h3><?php esc_html_e( 'Or pick an existing backup', 'jisento' ); ?></h3>
			<div id="jisento-existing-backups"><p><?php esc_html_e( 'Loading backups…', 'jisento' ); ?></p></div>
			<p><button type="button" class="button button-primary" id="jisento-use-existing"><?php esc_html_e( 'Continue', 'jisento' ); ?></button></p>
		</div>
	</div>
	<p id="jisento-package-summary" class="jisento-package-summary" hidden role="status"></p>
</section>

<?php include JISENTO_PATH . 'admin/views/partials/mode-review.php'; ?>

<div id="jisento-progress" class="jisento-panel" hidden></div>
<div id="jisento-result" class="jisento-panel" hidden></div>
