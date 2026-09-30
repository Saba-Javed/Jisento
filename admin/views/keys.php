<?php
/**
 * Legacy Migration Keys slug — redirects to key send.
 *
 * @package Jisento\Migration
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! headers_sent() ) {
	wp_safe_redirect( admin_url( 'admin.php?page=jisento-key-send' ) );
	exit;
}
?>
<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=jisento-key-send' ) ); ?>"><?php esc_html_e( 'Continue to migrate using migration key', 'jisento' ); ?></a></p>
