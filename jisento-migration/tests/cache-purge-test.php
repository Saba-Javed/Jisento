<?php
/**
 * Post-import LiteSpeed purge header and safe Hostinger purge.
 *
 * Run: php tests/cache-purge-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Cleanup;

// Exercise purge flag before any check() output so headers_sent() stays false when possible.
$GLOBALS['jisento_test_options'] = array();
Cleanup::arm_litespeed_purge();
$flag_set = ! empty( $GLOBALS['jisento_test_options']['jisento_litespeed_purge'] );
$first    = Cleanup::maybe_send_litespeed_purge_header();
$flag_cleared = empty( $GLOBALS['jisento_test_options']['jisento_litespeed_purge'] );
$second   = Cleanup::maybe_send_litespeed_purge_header();

check( 'arm sets purge flag', $flag_set );
check( 'first call consumes the purge flag', $flag_cleared );
check( 'second call does not send again', false === $second );
check( 'first call sends header when headers not yet sent', true === $first || headers_sent(), 'first=' . var_export( $first, true ) . ' headers_sent=' . ( headers_sent() ? '1' : '0' ) );

$host = Cleanup::purge_hostinger();
check( 'missing Hostinger plugin returns empty string', '' === $host, (string) $host );

if ( ! function_exists( 'hostinger_purge_cache' ) ) {
	function hostinger_purge_cache() {
		$GLOBALS['jisento_hostinger_purged'] = true;
	}
}
$GLOBALS['jisento_hostinger_purged'] = false;
$host = Cleanup::purge_hostinger();
check( 'Hostinger function path is used when present', 'hostinger_purge_cache' === $host && ! empty( $GLOBALS['jisento_hostinger_purged'] ) );

$src = file_get_contents( JISENTO_PATH . 'includes/Core/Cleanup.php' );
check( 'X-LiteSpeed-Purge header string present', false !== strpos( $src, 'X-LiteSpeed-Purge: *' ) );
check( 'purge_hostinger catches Throwable', false !== strpos( $src, 'purge_hostinger' ) && false !== strpos( $src, 'Throwable' ) );

$js = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check(
	'completion screen mentions hosting cache/CDN',
	false !== strpos( $js, 'clear your hosting cache/CDN and your browser cache' )
);

$plugin = file_get_contents( JISENTO_PATH . 'includes/Plugin.php' );
check( 'send_headers hooks LiteSpeed purge', false !== strpos( $plugin, 'maybe_send_litespeed_purge_header' ) );

jisento_test_finish();
