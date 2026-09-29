<?php
/**
 * Diagnostics: marker-based loopback, disk label, per-check API.
 *
 * Run: php tests/diagnostics-test.php
 */

$root = sys_get_temp_dir() . '/jisento-diag-' . getmypid();
@mkdir( $root, 0777, true );
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $root );
}
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Diagnostics;

$src = file_get_contents( JISENTO_PATH . 'includes/Core/Diagnostics.php' );
check( 'loopback uses Job_Scheduler::probe_loopback', false !== strpos( $src, 'Job_Scheduler::probe_loopback' ) );
check( 'loopback blocked copy is present', false !== strpos( $src, 'Background processing is blocked by the server' ) );
check( 'disk label mentions reported by server', false !== strpos( $src, 'Free disk space (reported by server)' ) );
check( 'disk note about hosting plan limit', false !== strpos( $src, 'hosting plan may have a lower limit' ) );

$d = new Diagnostics();
$disk = $d->run_check( 'disk' );
check( 'disk check returns warn note', is_array( $disk ) && ! empty( $disk['note'] ) && ! empty( $disk['warn'] ), json_encode( $disk ) );
check( 'disk label is Free disk space (reported by server)', is_array( $disk ) && 'Free disk space (reported by server)' === $disk['label'] );

$php = $d->run_check( 'php' );
check( 'single php check works', is_array( $php ) && 'php' === $php['id'] && ! empty( $php['ok'] ) );

$all = $d->run( 'php' );
check( 'run(php) returns one item', 1 === count( $all['items'] ) && 'php' === $all['items'][0]['id'] );

$js = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check( 'diagnostics use 10s timeout per check', false !== strpos( $js, 'timeout: 10000' ) && false !== strpos( $js, 'diagnostics?check=' ) );
check( 'Timed out copy present', false !== strpos( $js, 'Timed out' ) );
check( 'Could not run diagnostics message present', false !== strpos( $js, 'Could not run diagnostics (HTTP ' ) );
check( 'Retry button for diagnostics', false !== strpos( $js, 'Retry' ) && false !== strpos( $js, 'runDiagnostics' ) );
check( 'Copy results button wired', false !== strpos( $js, 'jisento-copy-diagnostics' ) && false !== strpos( $js, 'Copy results' ) );

$view = file_get_contents( JISENTO_PATH . 'admin/views/settings.php' );
check( 'settings view has Copy results button', false !== strpos( $view, 'jisento-copy-diagnostics' ) );

$rest = file_get_contents( JISENTO_PATH . 'includes/Api/Rest_Controller.php' );
check( 'REST diagnostics accepts check arg', false !== strpos( $rest, "'check'" ) && false !== strpos( $rest, 'get_param( \'check\' )' ) );

// Loopback without WP HTTP will fail probe — expect warn, not a false ✓ HTTP 403 style.
if ( ! function_exists( 'wp_remote_get' ) ) {
	$GLOBALS['jisento_stub_fns']['wp_remote_get'] = function () {
		return new WP_Error( 'http_request_failed', 'blocked' );
	};
}
// probe_loopback needs storage — may return false; that is the correct non-✓ outcome.
$loop = $d->run_check( 'loopback' );
check( 'loopback failure is warn not HTTP 403 success', is_array( $loop ) && empty( $loop['ok'] ) && ! empty( $loop['warn'] ), json_encode( $loop ) );
check( 'loopback message mentions keep this tab open', is_array( $loop ) && false !== strpos( (string) $loop['value'], 'keep this tab open' ) );

jisento_test_finish();
