<?php
/**
 * Phase 2.3: upload ranges, chunk sizing, and complete coverage checks.
 *
 * Run: php tests/upload-session-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Package\Upload_Session;

check( 'default chunk is 8 MiB', Upload_Session::DEFAULT_CHUNK === 8388608 );
check( 'clamp raises below 1 MiB', Upload_Session::MIN_CHUNK === Upload_Session::clamp_chunk( 100 ) );
check( 'clamp caps above 32 MiB', Upload_Session::MAX_CHUNK === Upload_Session::clamp_chunk( 64 * 1048576 ) );
check( 'clamp keeps 16 MiB', 16 * 1048576 === Upload_Session::clamp_chunk( 16 * 1048576 ) );

$merged = Upload_Session::merge_ranges( array( array( 0, 100 ), array( 100, 250 ), array( 50, 100 ) ) );
check( 'adjacent and contained ranges merge', ! is_wp_error( $merged ) && 1 === count( $merged ) && 0 === $merged[0][0] && 250 === $merged[0][1] );

$overlap = Upload_Session::merge_ranges( array( array( 0, 100 ), array( 50, 150 ) ) );
check( 'partial overlapping ranges are rejected', is_wp_error( $overlap ) );

check( 'covers_exactly accepts a single full range', Upload_Session::covers_exactly( array( array( 0, 1000 ) ), 1000 ) );
check( 'covers_exactly rejects a gap', ! Upload_Session::covers_exactly( array( array( 0, 400 ), array( 500, 1000 ) ), 1000 ) );
check( 'covers_exactly rejects a short range', ! Upload_Session::covers_exactly( array( array( 0, 999 ) ), 1000 ) );
check( 'covers_exactly rejects an overshoot', ! Upload_Session::covers_exactly( array( array( 0, 1001 ) ), 1000 ) );
check( 'covered_bytes sums unique bytes', 250 === Upload_Session::covered_bytes( array( array( 0, 100 ), array( 100, 250 ) ) ) );

$src = file_get_contents( JISENTO_PATH . 'includes/Api/Rest_Controller.php' );
check( 'upload_chunk reads the raw request body', false !== strpos( $src, 'get_body()' ) && false !== strpos( $src, "php://input" ) );
check( 'upload_chunk verifies X-Jisento-Chunk-SHA256', false !== strpos( $src, 'jisento_chunk_sha256' ) || false !== strpos( $src, 'X_JISENTO_CHUNK_SHA256' ) );
check( 'upload_complete requires exact range cover', false !== strpos( $src, 'covers_exactly' ) );
check( 'upload_complete does not hash the whole file', false === strpos( $src, 'hash_file( $dest )' ) );
check( 'Server-Timing is emitted for chunks', false !== strpos( $src, 'Server-Timing:' ) );
check( 'free package name is used on complete', false !== strpos( $src, 'Upload_Session::free_package_name' ) );

$js = file_get_contents( JISENTO_PATH . 'admin/js/file-picker.js' );
check( 'client sends application/octet-stream', false !== strpos( $js, 'application/octet-stream' ) );
check( 'client sends chunk SHA-256 header', false !== strpos( $js, 'X-Jisento-Chunk-SHA256' ) );
check( 'client halves chunk size on HTTP 413', false !== strpos( $js, '=== 413' ) );
check( 'client runs two concurrent chunks', false !== strpos( $js, 'MAX_CONCURRENT = 2' ) );
check( 'client shows Resume after interrupt', false !== strpos( $js, "textContent = state.resumable" ) );

jisento_test_finish();
