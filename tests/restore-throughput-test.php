<?php
/**
 * Phase 2.4: large INSERT flush, batch commits, and max_allowed_packet splits.
 *
 * Run: php tests/restore-throughput-test.php
 * Needs MariaDB when exercising the live import paths (JISENTO_TEST_DB_*).
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Database\Sql_Scanner;

$exporter_src = file_get_contents( JISENTO_PATH . 'includes/Database/Database_Exporter.php' );
$importer_src = file_get_contents( JISENTO_PATH . 'includes/Database/Database_Importer.php' );

check( 'exporter defines ~1.5 MiB INSERT flush', false !== strpos( $exporter_src, 'INSERT_FLUSH_BYTES = 1572864' ) );
check( 'exporter no longer flushes at 40 rows / 256 KB', false === strpos( $exporter_src, 'count( $group ) >= 40' ) && false === strpos( $exporter_src, '$size >= 262144' ) );
check( 'importer commits DML in ~2 s / 8 MB batches', false !== strpos( $importer_src, 'BATCH_TIME_SEC = 2.0' ) && false !== strpos( $importer_src, 'BATCH_MAX_BYTES = 8388608' ) );
check( 'importer flushes batch before DDL', false !== strpos( $importer_src, 'flush_batch' ) && false !== strpos( $importer_src, 'DDL is never part of a DML batch' ) );
check( 'importer reads @@max_allowed_packet', false !== strpos( $importer_src, '@@max_allowed_packet' ) && false !== strpos( $importer_src, 'refresh_packet_limit' ) );
check( 'importer splits inserts with packet/4 limit', false !== strpos( $importer_src, 'split_insert( $plan[\'sql\'], $limit )' ) || false !== strpos( $importer_src, 'split_insert( $plan["sql"], $limit )' ) || ( false !== strpos( $importer_src, 'Sql_Scanner::split_insert' ) && false !== strpos( $importer_src, 'insert_byte_limit' ) ) );

/* --- split_insert respects a tiny packet-derived limit (whole rows only) --- */
$rows = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$rows[] = "($i,'" . str_repeat( 'x', 80 ) . "')";
}
$wide = 'INSERT INTO `wp_postmeta` (`meta_id`,`meta_value`) VALUES ' . implode( ',', $rows ) . ';';
$limit = 400; // simulate max_allowed_packet/4 being small
$split = Sql_Scanner::split_insert( $wide, $limit );
check( 'small max_allowed_packet/4 splits a multi-row INSERT', count( $split ) > 1, 'pieces=' . count( $split ) );
$rebuilt = 0;
foreach ( $split as $i => $piece ) {
	$stmts = Sql_Scanner::statements( $piece );
	check( 'packet-split piece ' . $i . ' is one statement', 1 === count( $stmts ) );
	check( 'packet-split piece ' . $i . ' stays under limit (or is a single oversize row)', strlen( $piece ) <= $limit || 1 === count( Sql_Scanner::value_tuples( preg_replace( '/^.*VALUES\s*/is', '', rtrim( $piece, "; \n" ) ) ) ) );
	$rebuilt += count( Sql_Scanner::value_tuples( preg_replace( '/^.*VALUES\s*/is', '', rtrim( $piece, "; \n" ) ) ) );
}
check( 'packet-split keeps every row', 20 === $rebuilt, 'rows=' . $rebuilt );

/* --- live import with a forced tiny insert limit --- */
$db = jisento_test_wpdb( 'jisento_throughput', 'wp_', 'dest' );
if ( ! $db ) {
	echo "SKIP restore-throughput live import (no database server)\n";
	jisento_test_finish();
}

$GLOBALS['wpdb'] = $db;
$dir = sys_get_temp_dir() . '/jisento-throughput-' . getmypid();
@mkdir( $dir, 0777, true );
$segment = $dir . '/part-00001.sql';

$values = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$values[] = '(' . $i . ",'" . str_repeat( 'y', 120 ) . "')";
}
$sql  = "SET NAMES utf8mb4;\n";
$sql .= "DROP TABLE IF EXISTS `wp_tt`;\n";
$sql .= "CREATE TABLE `wp_tt` (`id` int NOT NULL, `msg` varchar(200) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n";
$sql .= 'INSERT INTO `wp_tt` (`id`,`msg`) VALUES ' . implode( ',', $values ) . ";\n";
file_put_contents( $segment, $sql );

$importer = new Database_Importer( 'wp_', 'wp_', array( 'job_id' => 'tt_packet' ) );
$importer->set_insert_byte_limit( 500 ); // well under a multi-row INSERT
$started = $importer->start_segment( 0 );
check( 'throughput test segment started', ! is_wp_error( $started ), is_wp_error( $started ) ? $started->get_error_message() : '' );
check( 'forced insert byte limit is active', 500 === $importer->insert_byte_limit() );

$chunk = $importer->import_chunk( 0, $segment, 30, 500 );
check( 'import with small packet limit succeeds', ! is_wp_error( $chunk ) && ! empty( $chunk['done'] ), is_wp_error( $chunk ) ? $chunk->get_error_message() : json_encode( $chunk ) );

$swap = $importer->swap_shadows( array( 'wp_tt' ) );
check( 'swap after packet-split import', ! is_wp_error( $swap ), is_wp_error( $swap ) ? $swap->get_error_message() : '' );
$count = (int) $db->get_var( 'SELECT COUNT(*) FROM wp_tt' );
check( 'all rows restored under small packet limit', 40 === $count, 'count=' . $count );

/* --- batch ledger is one row per committed batch, not per INSERT piece --- */
Database_Importer::forget_job( 'tt_packet' );
$importer2 = new Database_Importer( 'wp_', 'wp_', array( 'job_id' => 'tt_batch' ) );
$importer2->set_insert_byte_limit( 800 );
$db->query( 'DROP TABLE IF EXISTS wp_tt' );
$started = $importer2->start_segment( 0 );
$chunk   = $importer2->import_chunk( 0, $segment, 30, 500 );
check( 'batch import completes', ! is_wp_error( $chunk ) && ! empty( $chunk['done'] ), is_wp_error( $chunk ) ? $chunk->get_error_message() : '' );
$applied = (int) $db->get_var( "SELECT COUNT(*) FROM wp_jisento_import_applied WHERE job_id = 'tt_batch'" );
// CREATE + DROP ledger rows are per-DDL; INSERT pieces share batch ledger rows (far fewer than 40).
$insert_ledgers = (int) $db->get_var( "SELECT COUNT(*) FROM wp_jisento_import_applied WHERE job_id = 'tt_batch' AND piece_index > 0" );
check( 'applied ledger uses batch granularity for multi-piece inserts', $applied > 0 && $applied < 40, 'applied=' . $applied . ' insert_ledgers=' . $insert_ledgers );

Database_Importer::forget_job( 'tt_batch' );
foreach ( (array) glob( $dir . '/*' ) as $f ) {
	@unlink( $f );
}
@rmdir( $dir );

check( 'exporter INSERT_FLUSH_BYTES constant readable', Database_Exporter::INSERT_FLUSH_BYTES >= 1048576 && Database_Exporter::INSERT_FLUSH_BYTES <= 2097152 );

jisento_test_finish();
