<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap jisento-wrap">
	<h1><?php esc_html_e( 'Jisento Migration', 'jisento' ); ?></h1>
	<p class="jisento-lead"><?php esc_html_e( 'Export, import, or migrate a complete WordPress site using .jisento packages or a short-lived migration key.', 'jisento' ); ?></p>
	<div id="jisento-app" data-page="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'jisento' ); ?>">
