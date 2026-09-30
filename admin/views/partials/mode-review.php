<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$wizard_prefix = isset( $wizard_prefix ) ? $wizard_prefix : '';
?>
<section class="jisento-wizard-panel" id="jisento-wizard-step-2" data-wizard-step="2" hidden>
	<h2><?php esc_html_e( 'What should happen to this site?', 'jisento' ); ?></h2>
	<p><?php echo esc_html( sprintf( __( 'Current website: %s', 'jisento' ), home_url() ) ); ?></p>
	<div class="jisento-grid" id="jisento-dest-mode">
		<label class="jisento-mode-card">
			<input type="radio" name="jisento_dest_mode" value="replace">
			<strong><?php esc_html_e( 'Replace this site', 'jisento' ); ?></strong>
			<span><?php esc_html_e( 'This site becomes an exact copy of the source. Recommended for moving a site.', 'jisento' ); ?></span>
		</label>
		<label class="jisento-mode-card">
			<input type="radio" name="jisento_dest_mode" value="preserve">
			<strong><?php esc_html_e( 'Keep my logins, themes and plugins', 'jisento' ); ?></strong>
			<span><?php esc_html_e( 'Imports everything, but keeps your users, site address, and the themes and plugins already installed here.', 'jisento' ); ?></span>
		</label>
	</div>
</section>

<section class="jisento-wizard-panel" id="jisento-wizard-step-3" data-wizard-step="3" hidden>
	<h2><?php esc_html_e( 'Review and start', 'jisento' ); ?></h2>
	<table class="widefat striped jisento-review-table" id="jisento-review-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'From', 'jisento' ); ?></th>
				<td id="jisento-review-from">—</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'To', 'jisento' ); ?></th>
				<td id="jisento-review-to"><?php echo esc_html( home_url() ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Size', 'jisento' ); ?></th>
				<td id="jisento-review-size">—</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Links', 'jisento' ); ?></th>
				<td id="jisento-review-links"><?php esc_html_e( 'All links updated to the new address', 'jisento' ); ?></td>
			</tr>
		</tbody>
	</table>
	<p id="jisento-review-warning" class="jisento-inline-warning" hidden></p>

	<details class="jisento-advanced" id="jisento-advanced">
		<summary><?php esc_html_e( 'Advanced settings', 'jisento' ); ?></summary>
		<div class="jisento-advanced-body" id="jisento-url-options">
			<label><?php esc_html_e( 'Source', 'jisento' ); ?> <input type="url" id="jisento-source-url" class="regular-text"></label>
			<label><?php esc_html_e( 'Destination', 'jisento' ); ?> <input type="url" id="jisento-dest-url" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>"></label>
			<label><input type="checkbox" id="jisento-replace-urls" checked> <?php esc_html_e( 'Replace URLs', 'jisento' ); ?></label>
			<label><input type="checkbox" id="jisento-replace-guids"> <?php esc_html_e( 'Replace GUIDs', 'jisento' ); ?></label>
			<label><input type="checkbox" id="jisento-replace-emails" checked> <?php esc_html_e( 'Replace emails', 'jisento' ); ?></label>
			<label><input type="checkbox" id="jisento-replace-paths" checked> <?php esc_html_e( 'Replace server paths', 'jisento' ); ?></label>
			<label><input type="checkbox" id="jisento-repair-placeholders"> <?php esc_html_e( '% repair for old packages', 'jisento' ); ?></label>
			<label><input type="checkbox" id="jisento-restore-engines"> <?php esc_html_e( 'Convert MyISAM back', 'jisento' ); ?></label>
			<input type="checkbox" class="jisento-preserve" data-key="preserve_uploads" checked hidden>
		</div>
	</details>

	<p class="jisento-start-row">
		<label id="jisento-backup-confirm-wrap"><input type="checkbox" id="jisento-confirm-replace"> <?php esc_html_e( 'I have a backup of this site', 'jisento' ); ?></label>
		<button type="button" class="button button-primary" id="jisento-start-import" disabled><?php esc_html_e( 'Start migration', 'jisento' ); ?></button>
	</p>
</section>

<div id="jisento-preserve-modal" class="jisento-modal" hidden>
	<div class="jisento-modal-inner">
		<h2><?php esc_html_e( 'Keep this site\'s logins, themes and plugins', 'jisento' ); ?></h2>
		<p><?php esc_html_e( 'Everything from the source site will be imported. Your users and passwords, site address, and the themes and plugins already installed here are kept. Users and customer accounts from the source site are not imported. If a plugin exists on both sites, this site\'s version is used.', 'jisento' ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="jisento-preserve-ok"><?php esc_html_e( 'OK', 'jisento' ); ?></button>
			<button type="button" class="button jisento-close" id="jisento-preserve-cancel"><?php esc_html_e( 'Cancel', 'jisento' ); ?></button>
		</p>
	</div>
</div>
