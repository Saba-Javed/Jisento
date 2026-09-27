<?php
/**
 * Download nonce URL must stay raw for JS (never HTML-escaped &amp;).
 *
 * Run: php tests/download-nonce-test.php
 */

require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		return 'nonce_' . preg_replace( '/[^a-z0-9_]/', '', (string) $action );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://destination.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

// Same construction as admin/views/backups.php (must not use wp_nonce_url for JS).
$download_base = add_query_arg(
	array(
		'action'   => 'jisento_download',
		'_wpnonce' => wp_create_nonce( 'jisento_download' ),
	),
	admin_url( 'admin-post.php' )
);
$json = wp_json_encode( $download_base );

check( 'download base has no HTML-escaped ampersands', false === strpos( $download_base, '&amp;' ) && false === strpos( (string) $json, '&amp;' ), $download_base );
check( 'JSON for JS still has no amp entities', false === strpos( (string) $json, '\\u0026amp;' ) );

$decoded = json_decode( $json );
check( 'JSON round-trip keeps a string URL', is_string( $decoded ) && $decoded === $download_base );

$query = array();
parse_str( (string) parse_url( $decoded, PHP_URL_QUERY ), $query );
check( '_wpnonce is a real query parameter', isset( $query['_wpnonce'] ) && 'nonce_jisento_download' === $query['_wpnonce'], wp_json_encode( $query ) );
check( 'action is jisento_download', isset( $query['action'] ) && 'jisento_download' === $query['action'] );

// Contrast: wp_nonce_url HTML-escapes and would poison JS.
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}
}
$poisoned = esc_html( $download_base );
check( 'esc_html would introduce amp (why wp_nonce_url must not feed JS)', false !== strpos( $poisoned, '&amp;' ) );

$backups = file_get_contents( JISENTO_PATH . 'admin/views/backups.php' );
check( 'backups.php does not feed wp_nonce_url into JS', false === strpos( $backups, 'wp_nonce_url' ) );
check( 'backups.php builds the base with add_query_arg + wp_create_nonce', false !== strpos( $backups, 'add_query_arg' ) && false !== strpos( $backups, 'wp_create_nonce' ) );

$admin = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check( 'admin.js builds download links with the URL API', false !== strpos( $admin, 'function buildDownloadUrl' ) && false !== strpos( $admin, 'searchParams.set' ) );
check( 'admin.js no longer concatenates &file= onto the nonce base', false === strpos( $admin, "jisentoDownloadBase || '') + '&file='" ) && false === strpos( $admin, "jisentoDownloadBase + '&id='" ) );

jisento_test_finish();
