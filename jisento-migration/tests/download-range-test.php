<?php
/**
 * Backup download Range / streaming helpers.
 *
 * Run: php tests/download-range-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Admin\Admin;

$size = 1000;
$full = Admin::parse_byte_range( '', $size );
check( 'empty Range is a full download', is_array( $full ) && 0 === $full['start'] && 999 === $full['end'] && empty( $full['partial'] ) );

$mid = Admin::parse_byte_range( 'bytes=100-199', $size );
check( 'Range in the middle', is_array( $mid ) && 100 === $mid['start'] && 199 === $mid['end'] && ! empty( $mid['partial'] ) );

$end = Admin::parse_byte_range( 'bytes=900-999', $size );
check( 'Range at the end', is_array( $end ) && 900 === $end['start'] && 999 === $end['end'] && ! empty( $end['partial'] ) );

$open = Admin::parse_byte_range( 'bytes=500-', $size );
check( 'open-ended Range goes to EOF', is_array( $open ) && 500 === $open['start'] && 999 === $open['end'] );

$bad = Admin::parse_byte_range( 'bytes=100-50', $size );
check( 'inverted Range is invalid', is_wp_error( $bad ) );

$past = Admin::parse_byte_range( 'bytes=1000-1005', $size );
check( 'Range past EOF is unsatisfiable (416)', is_wp_error( $past ) );

$multi = Admin::parse_byte_range( 'bytes=0-1,2-3', $size );
check( 'multi-range is rejected', is_wp_error( $multi ) );

$payload = '';
for ( $i = 0; $i < 2500; $i++ ) {
	$payload .= chr( $i % 256 );
}
$path = sys_get_temp_dir() . '/jisento-dl-range-' . getmypid() . '.bin';
file_put_contents( $path, $payload );
$filesize = strlen( $payload );

$fp = fopen( $path, 'rb' );
ob_start();
$sent = Admin::stream_file_range( $fp, 0, $filesize );
$body = ob_get_clean();
fclose( $fp );
check( 'full download body matches the file exactly', $filesize === $sent && $body === $payload );

$fp = fopen( $path, 'rb' );
ob_start();
$sent = Admin::stream_file_range( $fp, 100, 100 );
$body = ob_get_clean();
fclose( $fp );
check( 'middle Range body matches file bytes', 100 === $sent && $body === substr( $payload, 100, 100 ) );

$fp = fopen( $path, 'rb' );
ob_start();
$sent = Admin::stream_file_range( $fp, $filesize - 50, 50 );
$body = ob_get_clean();
fclose( $fp );
check( 'end Range body matches file bytes', 50 === $sent && $body === substr( $payload, -50 ) );

// Over 1 MiB so streaming uses more than one chunk.
$big = str_repeat( 'A', 1048576 ) . str_repeat( 'B', 100 );
$big_path = sys_get_temp_dir() . '/jisento-dl-big-' . getmypid() . '.bin';
file_put_contents( $big_path, $big );
$fp = fopen( $big_path, 'rb' );
ob_start();
$sent = Admin::stream_file_range( $fp, 0, strlen( $big ) );
$body = ob_get_clean();
fclose( $fp );
check( 'chunked stream still matches the whole file', strlen( $big ) === $sent && $body === $big );
check( 'download chunk size is 1 MiB', Admin::DOWNLOAD_CHUNK === 1048576 );

$src = file_get_contents( JISENTO_PATH . 'includes/Admin/Admin.php' );
check( 'download sets no time limit', false !== strpos( $src, 'set_time_limit( 0 )' ) );
check( 'download closes an open session', false !== strpos( $src, 'session_write_close()' ) );
check( 'download stops on connection_aborted', false !== strpos( $src, 'connection_aborted()' ) );
check( 'download sends Accept-Ranges', false !== strpos( $src, "header( 'Accept-Ranges: bytes' )" ) );
check( 'download sends LiteSpeed no-cache', false !== strpos( $src, 'X-LiteSpeed-Cache-Control: no-cache' ) );
check( 'download sends X-Accel-Buffering no', false !== strpos( $src, 'X-Accel-Buffering: no' ) );
check( 'download answers invalid Range with 416', false !== strpos( $src, 'status_header( 416 )' ) && false !== strpos( $src, 'Content-Range: bytes */' ) );
check( 'download sends 206 for partial content', false !== strpos( $src, 'status_header( 206 )' ) );
check( 'download still checks capability and nonce', false !== strpos( $src, 'Capabilities::current_user_can()' ) && false !== strpos( $src, "check_admin_referer( 'jisento_download' )" ) );
check( 'download still calls verify_file', false !== strpos( $src, 'verify_file( $path )' ) );

@unlink( $path );
@unlink( $big_path );
jisento_test_finish();
