<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$icon_url = JISENTO_URL . 'assets/icons/menu-icon.svg';
?>
<div class="wrap jisento-wrap">
	<h1 class="jisento-page-title">
		<img class="jisento-page-icon" src="<?php echo esc_url( $icon_url ); ?>" width="32" height="32" alt="">
		<?php esc_html_e( 'Jisento Migration', 'jisento-migration' ); ?>
	</h1>
	<p class="jisento-lead"><?php esc_html_e( 'Export, import, or migrate a complete WordPress site using .jisento packages or a short-lived migration key.', 'jisento-migration' ); ?></p>
	<div id="jisento-app" data-page="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'jisento' ); ?>">
