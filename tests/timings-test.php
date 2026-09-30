<?php
/**
 * Phase 2.5: per-stage timings and human-readable durations.
 *
 * Run: php tests/timings-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Timings;
use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Database\Dump_Writer;

check( 'format_duration under a minute', '12 s' === Timings::format_duration( 12.4 ) || '12 s' === Timings::format_duration( 12 ) );
check( 'format_duration minutes and seconds', '1 min 20 s' === Timings::format_duration( 80 ) );
check( 'format_duration hours', false !== strpos( Timings::format_duration( 3725 ), 'h' ) );

$line = Timings::human_line( 'importing_database', array( 'seconds' => 80, 'bytes' => 1000, 'throughput' => 12.5 ) );
check( 'human line for database restore', 'Database restored in 1 min 20 s' === $line, $line );

$state = array();
Timings::begin( $state, 'packaging', 100 );
usleep( 20000 );
Timings::end( $state, 'packaging', 5000 );
check( 'begin/end stores structured timing', isset( $state['timings']['packaging']['seconds'] ) && $state['timings']['packaging']['seconds'] > 0 );
check( 'begin/end records bytes and throughput', 5000 === (int) $state['timings']['packaging']['bytes'] && $state['timings']['packaging']['throughput'] > 0 );

Timings::add( $state, 'importing_database', 1.5, 100 );
Timings::add( $state, 'importing_database', 0.5, 50 );
check( 'add accumulates seconds and bytes', abs( (float) $state['timings']['importing_database']['seconds'] - 2.0 ) < 0.01 && 150 === (int) $state['timings']['importing_database']['bytes'] );

Timings::set_transfer( $state, 4, 2.0, 8000 );
check( 'transfer records requests and average chunk time', 4 === (int) $state['timings']['transfer']['requests'] && abs( (float) $state['timings']['transfer']['average_chunk_seconds'] - 0.5 ) < 0.01 );

$list = Timings::readable_list( $state['timings'] );
check( 'readable list is non-empty', count( $list ) >= 2 );
check( 'readable list mentions database or packaging', (bool) preg_grep( '/Database restored|Package built|Package transferred/', $list ) );

$legacy = Timings::normalize_entry( 3.25 );
check( 'legacy float normalizes to seconds', abs( $legacy['seconds'] - 3.25 ) < 0.001 );

$exporter = file_get_contents( JISENTO_PATH . 'includes/Export/Exporter.php' );
$importer = file_get_contents( JISENTO_PATH . 'includes/Import/Importer.php' );
$admin    = file_get_contents( JISENTO_PATH . 'includes/Admin/Admin.php' );
$js       = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check( 'export records packaging/checksum/finalize timings', false !== strpos( $exporter, "Timings::begin( \$state, 'packaging'" ) && false !== strpos( $exporter, "Timings::begin( \$state, 'checksum'" ) && false !== strpos( $exporter, "Timings::begin( \$state, 'finalize'" ) );
check( 'export logs timing lines', false !== strpos( $exporter, "'timing'" ) && false !== strpos( $exporter, 'readable_list' ) );
check( 'import logs timing lines on completion', false !== strpos( $importer, "'timing'" ) && false !== strpos( $importer, 'timing_lines' ) );
check( 'history details use Timings::readable_list', false !== strpos( $admin, 'Timings::readable_list' ) );
check( 'completion UI says Database restored in', false !== strpos( $js, 'Database restored' ) && false !== strpos( $js, 'formatDuration' ) );

/* Mini export/import against MariaDB when available. */
$db = jisento_test_wpdb( 'jisento_timings', 'wp_', 'dest' );
if ( ! $db ) {
	echo "SKIP timings mini export/import (no database server)\n";
	jisento_test_finish();
}

$GLOBALS['wpdb'] = $db;
$db->query( 'CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, PRIMARY KEY (option_id)) ENGINE=InnoDB' );
$db->query( "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl','https://example.test'),('home','https://example.test')" );

$job_state = array(
	'tables'     => array( 'wp_options' ),
	'table_meta' => array( 'wp_options' => array( 'data' => 200 ) ),
	'db_size'    => 200,
	'dump_dir'   => sys_get_temp_dir() . '/jisento-timings-dump-' . getmypid(),
	'options'    => array( 'mode' => 'database' ),
);
@mkdir( $job_state['dump_dir'], 0777, true );

$exporter_obj = new Database_Exporter();
$writer       = new Dump_Writer( $job_state['dump_dir'], $exporter_obj );
$dump         = Dump_Writer::initial_state( $job_state['tables'] );
Timings::begin( $job_state, 'exporting_database', 200 );
while ( empty( $dump['done'] ) ) {
	$dump = $writer->step( $dump, 5, 500 );
	if ( is_wp_error( $dump ) ) {
		check( 'mini export step', false, $dump->get_error_message() );
		jisento_test_finish();
	}
}
Timings::end( $job_state, 'exporting_database', isset( $dump['segments'][0]['bytes'] ) ? (int) $dump['segments'][0]['bytes'] : 0 );
check( 'mini export recorded exporting_database timing', ! empty( $job_state['timings']['exporting_database']['seconds'] ) );

$dst             = jisento_test_wpdb( 'jisento_timings_dst', 'wp_', 'dest' );
$GLOBALS['wpdb'] = $dst;
$imp             = new Database_Importer( 'wp_', 'wp_', array( 'job_id' => 'tm_mini' ) );
$imp->start_segment( 0 );
$path = $job_state['dump_dir'] . '/' . basename( $dump['segments'][0]['entry'] );
Timings::begin( $job_state, 'importing_database', filesize( $path ) );
$chunk = $imp->import_chunk( 0, $path, 30, 500 );
Timings::end( $job_state, 'importing_database', filesize( $path ) );
check( 'mini import succeeded', ! is_wp_error( $chunk ) && ! empty( $chunk['done'] ), is_wp_error( $chunk ) ? $chunk->get_error_message() : '' );
check( 'mini import recorded importing_database timing', ! empty( $job_state['timings']['importing_database']['seconds'] ) );
$lines = Timings::readable_list( $job_state['timings'] );
check( 'mini run produces human timing lines', (bool) preg_grep( '/Database (exported|restored) in /', $lines ), json_encode( $lines ) );

foreach ( (array) glob( $job_state['dump_dir'] . '/*' ) as $f ) {
	@unlink( $f );
}
@rmdir( $job_state['dump_dir'] );
Database_Importer::forget_job( 'tm_mini' );

jisento_test_finish();
