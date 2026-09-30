<?php
/**
 * Export a fixture database with Database_Exporter (segments via Dump_Writer), restore it with
 * Database_Importer into a clean database, and require byte-identical rows.
 *
 * Needs a MariaDB or MySQL server (see tests/docker-compose.yml). Environment:
 *   JISENTO_TEST_DB_HOST / _PORT / _USER / _PASS             one server for source and destination
 *   JISENTO_TEST_SOURCE_DB_HOST ... / JISENTO_TEST_DEST_DB_HOST ...  separate servers
 *   JISENTO_TEST_ROWS                                      postmeta rows (default 100000)
 *
 * Run: php tests/roundtrip-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Database\Dump_Writer;

$rows_target = (int) ( getenv( 'JISENTO_TEST_ROWS' ) ? getenv( 'JISENTO_TEST_ROWS' ) : 100000 );
$src         = jisento_test_wpdb( 'jisento_rt_src', 'wp_', 'source' );
if ( ! $src ) {
	jisento_test_skip( 'no database server (set JISENTO_TEST_DB_HOST/PORT/USER/PASS or start tests/docker-compose.yml)' );
}
$src_version = (string) mysqli_get_server_info( $src->dbh );
$src_mysql8  = false === stripos( $src_version, 'mariadb' ) && version_compare( $src_version, '8.0', '>=' );
echo "Source server: $src_version\n";

function rt_exec( $wpdb, $sql ) {
	if ( false === mysqli_query( $wpdb->dbh, $sql ) ) {
		fwrite( STDERR, 'Fixture SQL failed: ' . mysqli_error( $wpdb->dbh ) . "\n" . substr( $sql, 0, 300 ) . "\n" );
		exit( 2 );
	}
}

function rt_q( $wpdb, $value ) {
	return null === $value ? 'NULL' : "'" . mysqli_real_escape_string( $wpdb->dbh, (string) $value ) . "'";
}

/* ---------------------------------------------------------------- fixture */

$serialized_pct = serialize( array( 'discount' => '50%', 'label' => 'Save 100% now', 'url' => 'https://old.example/a%20b' ) );
$weird          = "zero\0byte ctrl\x01\x02\x1a\x7f quote' dquote\" back\\slash semi; colon; -- not a comment /* nor this */ \r\n tab\t";
$unicode        = "Ünïcödé 日本語 emoji 😀🎉 RTL עברית";

rt_exec( $src, 'CREATE TABLE wp_options (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT \'\', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
$opts = array(
	array( 'siteurl', 'https://old.example' ),
	array( 'home', 'https://old.example' ),
	array( 'pct_plain', '100%' ),
	array( 'pct_url', '%20' ),
	array( 'pct_serialized', $serialized_pct ),
	array( 'pct_many', '%%%% % %s %d {abc} 50%' ),
	array( 'placeholder_lookalike', '{' . str_repeat( 'a', 64 ) . '}' ),
	array( 'json_value', '{"a":{},"b":1.10,"c":"https:\/\/old.example\/x","d":"50%"}' ),
	array( 'weird', $weird ),
	array( 'unicode', $unicode ),
	array( 'empty', '' ),
	array( 'wp_user_roles', serialize( array( 'administrator' => array( 'name' => 'Administrator' ) ) ) ),
);
foreach ( $opts as $o ) {
	rt_exec( $src, 'INSERT INTO wp_options (option_name, option_value) VALUES (' . rt_q( $src, $o[0] ) . ',' . rt_q( $src, $o[1] ) . ')' );
}

rt_exec( $src, 'CREATE TABLE wp_postmeta (meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
$batch = array();
for ( $i = 1; $i <= $rows_target; $i++ ) {
	switch ( $i % 10 ) {
		case 0:
			$value = serialize( array( 'n' => $i, 'pct' => $i . '%', 'nested' => array( 'u' => 'https://old.example/p/' . $i ) ) );
			break;
		case 1:
			$value = null;
			break;
		case 2:
			$value = '';
			break;
		case 3:
			$value = 'Price drop ' . ( $i % 100 ) . '% on item ' . $i;
			break;
		case 4:
			$value = $weird . $i;
			break;
		case 5:
			$value = $unicode . ' #' . $i;
			break;
		case 6:
			$value = '{"elementor":[{"id":"' . dechex( $i ) . '","settings":{"width":{"unit":"%","size":50}}}]}';
			break;
		default:
			$value = str_repeat( chr( 65 + ( $i % 26 ) ), 1 + ( $i % 90 ) );
	}
	$batch[] = '(' . $i . ',' . ( $i % 5000 ) . ',' . rt_q( $src, 'key_' . ( $i % 37 ) ) . ',' . rt_q( $src, $value ) . ')';
	if ( count( $batch ) >= 2000 ) {
		rt_exec( $src, 'INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES ' . implode( ',', $batch ) );
		$batch = array();
	}
}
if ( $batch ) {
	rt_exec( $src, 'INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES ' . implode( ',', $batch ) );
}
rt_exec( $src, 'INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value) VALUES (' . ( $rows_target + 1 ) . ', 1, \'big_text\', ' . rt_q( $src, str_repeat( "Large text % with ; and ' quotes. ", 40000 ) ) . ')' );

rt_exec( $src, 'CREATE TABLE wp_bin (id int NOT NULL, b16 BINARY(16) NULL, b32 BINARY(32) NULL, bl BLOB NULL, bits BIT(8) NULL, vb VARBINARY(64) NULL, s varchar(20) NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
for ( $i = 0; $i < 300; $i++ ) {
	$b16 = substr( hash( 'sha256', 'k' . $i, true ), 0, 16 );
	if ( 0 === $i % 7 ) {
		$b16 = str_repeat( "\0", 15 ) . chr( $i );
	}
	$b32  = hash( 'sha256', 'v' . $i, true );
	$blob = random_bytes( 50 + $i ) . "\0'\\;\x1a";
	rt_exec( $src, sprintf( 'INSERT INTO wp_bin VALUES (%d, 0x%s, 0x%s, 0x%s, b\'%s\', %s, %s)', $i, bin2hex( $b16 ), bin2hex( $b32 ), bin2hex( $blob ), decbin( $i % 256 ), 0 === $i % 3 ? 'NULL' : "X''", 0 === $i % 4 ? 'NULL' : "''" ) );
}

rt_exec( $src, 'CREATE TABLE wp_binkey (k BINARY(16) NOT NULL, v varchar(10) NOT NULL, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
for ( $i = 0; $i < 1200; $i++ ) {
	rt_exec( $src, sprintf( "INSERT INTO wp_binkey VALUES (0x%s, 'v%d')", bin2hex( md5( 'bk' . $i, true ) ), $i ) );
}

rt_exec( $src, 'CREATE TABLE wp_latin (id int NOT NULL AUTO_INCREMENT, t varchar(255) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=latin1' );
mysqli_set_charset( $src->dbh, 'latin1' );
$high = '';
for ( $c = 0x80; $c <= 0xFF; $c++ ) {
	$high .= chr( $c );
}
rt_exec( $src, 'INSERT INTO wp_latin (t) VALUES (' . rt_q( $src, "caf\xE9 na\xEFve \xFCber" ) . '), (' . rt_q( $src, $high ) . '), (' . rt_q( $src, "100% latin \xA3" ) . ')' );
mysqli_set_charset( $src->dbh, 'utf8mb4' );

rt_exec( $src, 'CREATE TABLE wp_composite (a int NOT NULL, b varchar(20) NOT NULL, v text, PRIMARY KEY (a, b)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
$batch = array();
for ( $i = 0; $i < 1500; $i++ ) {
	$batch[] = sprintf( "(%d, 'b%03d', 'val %d%%')", intdiv( $i, 3 ), $i % 3, $i );
}
rt_exec( $src, 'INSERT INTO wp_composite VALUES ' . implode( ',', $batch ) );

rt_exec( $src, 'CREATE TABLE wp_varpk (slug varchar(100) NOT NULL, v int, PRIMARY KEY (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
$batch = array();
for ( $i = 0; $i < 1300; $i++ ) {
	$batch[] = '(' . rt_q( $src, ( 0 === $i % 2 ? 'Slug-' : 'slug_' ) . $i . ( 0 === $i % 5 ? 'ü' : '' ) ) . ',' . $i . ')';
}
rt_exec( $src, 'INSERT INTO wp_varpk VALUES ' . implode( ',', $batch ) );

rt_exec( $src, 'CREATE TABLE wp_nopk (a int, b varchar(20), c text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
$batch = array();
for ( $i = 0; $i < 1100; $i++ ) {
	$batch[] = sprintf( "(%d, 'x%d', %s)", $i % 400, $i % 3, 0 === $i % 11 ? 'NULL' : "'dup row'" );
}
rt_exec( $src, 'INSERT INTO wp_nopk VALUES ' . implode( ',', $batch ) );

rt_exec( $src, 'CREATE TABLE wp_uniq (code varchar(20) NOT NULL, n int, UNIQUE KEY code (code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
$batch = array();
for ( $i = 0; $i < 1200; $i++ ) {
	$batch[] = sprintf( "('c%05d', %d)", $i, $i );
}
rt_exec( $src, 'INSERT INTO wp_uniq VALUES ' . implode( ',', $batch ) );

rt_exec( $src, 'CREATE TABLE wp_zerofill (id int(6) unsigned zerofill NOT NULL, v varchar(10), PRIMARY KEY (id)) ENGINE=InnoDB' );
$batch = array();
for ( $i = 1; $i <= 1400; $i++ ) {
	$batch[] = sprintf( "(%d, 'z%d')", $i * 3, $i );
}
rt_exec( $src, 'INSERT INTO wp_zerofill VALUES ' . implode( ',', $batch ) );

rt_exec( $src, 'CREATE TABLE wp_myisam (id int NOT NULL AUTO_INCREMENT, t varchar(50), PRIMARY KEY (id), FULLTEXT KEY t (t)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC PACK_KEYS=1' );
rt_exec( $src, "INSERT INTO wp_myisam (t) VALUES ('alpha'), ('beta 50%'), ('gamma')" );

rt_exec( $src, 'CREATE TABLE wp_wc_orders (id bigint(20) unsigned NOT NULL, status varchar(20) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
rt_exec( $src, 'CREATE TABLE wp_wc_download_log (download_log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned NOT NULL, ip varchar(100), PRIMARY KEY (download_log_id), KEY order_id (order_id), CONSTRAINT fk_wc_download_log_permission_id FOREIGN KEY (order_id) REFERENCES wp_wc_orders (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
for ( $i = 1; $i <= 50; $i++ ) {
	rt_exec( $src, "INSERT INTO wp_wc_orders VALUES ($i, 'wc-completed')" );
	rt_exec( $src, "INSERT INTO wp_wc_download_log (order_id, ip) VALUES ($i, '10.0.0.$i')" );
}

if ( $src_mysql8 ) {
	rt_exec( $src, 'CREATE TABLE wp_c0900 (id int NOT NULL, t varchar(40) COLLATE utf8mb4_0900_ai_ci, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci' );
	rt_exec( $src, "INSERT INTO wp_c0900 VALUES (1, 'naïve 100%'), (2, 'Ölfarbe')" );
}
$src_uca1400 = false;
$uca_probe   = @mysqli_query( $src->dbh, "SELECT CONVERT('a' USING utf8mb4) COLLATE utf8mb4_uca1400_ai_ci" );
if ( $uca_probe ) {
	$src_uca1400 = true;
	mysqli_free_result( $uca_probe );
}
if ( $src_uca1400 ) {
	rt_exec( $src, 'CREATE TABLE wp_yith_wcwl (id int NOT NULL, t varchar(40) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci' );
	rt_exec( $src, "INSERT INTO wp_yith_wcwl VALUES (1, 'wishlist')" );
	rt_exec( $src, 'CREATE TABLE wp_uca1400_mb4 (id int NOT NULL, t varchar(40) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci' );
	rt_exec( $src, "INSERT INTO wp_uca1400_mb4 VALUES (1, 'mb4')" );
	rt_exec( $src, 'CREATE TABLE wp_uca1400_cs (id int NOT NULL, t varchar(40) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_as_cs' );
	rt_exec( $src, "INSERT INTO wp_uca1400_cs VALUES (1, 'CaseSensitive')" );
	rt_exec( $src, 'CREATE TABLE wp_uca1400_col (id int NOT NULL, t varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
	rt_exec( $src, "INSERT INTO wp_uca1400_col VALUES (1, 'column')" );
}
rt_exec( $src, 'CREATE VIEW wp_some_view AS SELECT option_name FROM wp_options' );

/* ---------------------------------------------------------------- export */

$GLOBALS['wpdb'] = $src;
$exporter        = new Database_Exporter();
$tables          = $exporter->tables();
check( 'views are listed separately and not exported', in_array( 'wp_some_view', $exporter->skipped_views(), true ) && ! in_array( 'wp_some_view', $tables, true ) );

$dump_dir = sys_get_temp_dir() . '/jisento-rt-' . getmypid();
@mkdir( $dump_dir, 0777, true );
foreach ( (array) glob( $dump_dir . '/*' ) as $f ) {
	@unlink( $f );
}
$writer = new Dump_Writer( $dump_dir, $exporter );
$state  = Dump_Writer::initial_state( $tables );
$t0     = microtime( true );
$steps  = 0;
while ( empty( $state['done'] ) ) {
	$state = $writer->step( $state, 0.05, 500 );
	if ( is_wp_error( $state ) ) {
		check( 'export step', false, $state->get_error_message() );
		jisento_test_finish();
	}
	$steps++;
	if ( 1 === $steps && empty( $state['done'] ) ) {
		file_put_contents( $dump_dir . '/' . Dump_Writer::segment_name( $state['seq'] ) . '.partial', 'INSERT INTO half' );
	}
}
file_put_contents( $dump_dir . '/' . Dump_Writer::segment_name( $state['seq'] ) . '.partial', 'INSERT INTO half' );
file_put_contents( $dump_dir . '/' . Dump_Writer::segment_name( $state['seq'] + 1 ), 'orphan segment the state never recorded' );
$writer->recover( $state );
$exporter->restore_connection_charset();
printf( "Exported %d tables in %d segments (%.1fs)\n", count( $tables ), count( $state['segments'] ), microtime( true ) - $t0 );
check( 'export produced more than one segment', count( $state['segments'] ) > 1 );
check( 'a leftover .partial from a killed request is removed', ! glob( $dump_dir . '/*.partial' ) );

$all_sql = '';
foreach ( $state['segments'] as $segment ) {
	$path     = $dump_dir . '/' . basename( $segment['entry'] );
	$all_sql .= file_get_contents( $path );
	check( 'segment hash matches bytes: ' . basename( $path ), hash_file( 'sha256', $path ) === $segment['sha256'] && filesize( $path ) === $segment['bytes'] );
}
check( 'no wpdb placeholder token in the dump', ! preg_match( '/\{[a-f0-9]{64}\}/', str_replace( '{' . str_repeat( 'a', 64 ) . '}', '', $all_sql ) ) );
check( 'dump keeps "100%" literally', false !== strpos( $all_sql, "'100%'" ) );
check( 'dump keeps "%20" literally', false !== strpos( $all_sql, "'%20'" ) );
check( 'no-PK table recorded as not stable', isset( $state['table_stats']['wp_nopk'] ) && false === $state['table_stats']['wp_nopk']['stable'] && 'none' === $state['table_stats']['wp_nopk']['key'] );
check( 'UNIQUE NOT NULL index used as key', isset( $state['table_stats']['wp_uniq'] ) && 'unique' === $state['table_stats']['wp_uniq']['key'] && true === $state['table_stats']['wp_uniq']['stable'] );
check( 'per-table row counts recorded', (int) $state['table_stats']['wp_postmeta']['rows'] === $rows_target + 1 && 1400 === (int) $state['table_stats']['wp_zerofill']['rows'] );

$expected_counts = array();
foreach ( $state['table_stats'] as $table => $stat ) {
	$expected_counts[ $table ] = (int) $stat['rows'];
}

/* ---------------------------------------------------------------- import */

function rt_import( $dbname, array $segments, $dump_dir, array $options, $fault_at = null ) {
	$wpdb            = jisento_test_reconnect( $dbname, 'wp_', 'dest' );
	$GLOBALS['wpdb'] = $wpdb;
	$importer        = new Database_Importer( 'wp_', 'wp_', $options );
	$importer->start_segment( 0 );
	$session = array();
	$stmt_no = 0;
	$killed  = 0;
	foreach ( $segments as $index => $segment ) {
		$path = $dump_dir . '/' . basename( $segment['entry'] );
		while ( true ) {
			$GLOBALS['wpdb'] = $wpdb;
			$importer        = new Database_Importer( 'wp_', 'wp_', $options + array( 'session' => $session, 'statement_no' => $stmt_no ) );
			if ( $fault_at && $killed < $fault_at['times'] ) {
				$importer->set_fault_hook(
					function ( $point ) use ( $fault_at, $importer ) {
						if ( $point === $fault_at['point'] && $importer->statement_no() >= $fault_at['after'] && 0 === ( $importer->statement_no() % $fault_at['every'] ) ) {
							throw new RuntimeException( 'simulated kill at ' . $point );
						}
					}
				);
			}
			try {
				$chunk = $importer->import_chunk( $index, $path, 30, 97 );
			} catch ( RuntimeException $e ) {
				$killed++;
				mysqli_close( $wpdb->dbh );
				$wpdb = jisento_test_reconnect( $dbname, 'wp_', 'dest' );
				continue;
			}
			if ( is_wp_error( $chunk ) ) {
				return $chunk;
			}
			$session = $chunk['session'];
			$stmt_no = $chunk['statement_no'];
			if ( ! empty( $chunk['notes'] ) ) {
				$GLOBALS['rt_notes'] = array_merge_recursive( isset( $GLOBALS['rt_notes'] ) ? $GLOBALS['rt_notes'] : array(), $chunk['notes'] );
			}
			if ( $chunk['done'] ) {
				break;
			}
		}
		if ( isset( $segments[ $index + 1 ] ) ) {
			$importer->start_segment( $index + 1 );
		}
	}
	$GLOBALS['wpdb'] = $wpdb;
	return array(
		'wpdb'     => $wpdb,
		'importer' => $importer,
		'killed'   => $killed,
	);
}

function rt_fingerprint( $wpdb, $table ) {
	$cols = array();
	foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ) as $col ) {
		$cols[] = "IFNULL(HEX(`{$col['Field']}`), 'NULL')";
	}
	$res    = mysqli_query( $wpdb->dbh, 'SELECT CONCAT_WS(\'|\', ' . implode( ', ', $cols ) . ') AS r FROM `' . $table . '`', MYSQLI_USE_RESULT );
	if ( ! $res ) {
		return array( 'error' => mysqli_errno( $wpdb->dbh ) . ' ' . mysqli_error( $wpdb->dbh ) );
	}
	$hashes = array();
	while ( $row = mysqli_fetch_row( $res ) ) {
		$hashes[] = hash( 'sha256', $row[0] );
	}
	mysqli_free_result( $res );
	sort( $hashes );
	return array(
		'rows' => count( $hashes ),
		'hash' => hash( 'sha256', implode( '', $hashes ) ),
	);
}

function rt_compare( $src, $dst, array $tables, $label ) {
	$dst_tables = $dst->get_col( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" );
	$dst_tables = array_values( array_filter( $dst_tables, function ( $t ) { return 0 !== strpos( $t, 'wp_jisento_' ); } ) );
	sort( $dst_tables );
	$want = $tables;
	sort( $want );
	check( "$label: table list identical", $want === $dst_tables, 'got ' . implode( ',', $dst_tables ) );
	$bad = array();
	foreach ( $tables as $table ) {
		$a = rt_fingerprint( $src, $table );
		$b = rt_fingerprint( $dst, $table );
		if ( $a !== $b ) {
			$bad[] = $table . ' (' . json_encode( $a ) . ' vs ' . json_encode( $b ) . ')';
		}
	}
	check( "$label: every row byte-identical (sha256 over HEX of every column)", ! $bad, implode( '; ', $bad ) );
}

$dst = jisento_test_wpdb( 'jisento_rt_dst', 'wp_', 'dest' );
$t0  = microtime( true );
$run = rt_import( 'jisento_rt_dst', $state['segments'], $dump_dir, array( 'job_id' => 'rt_job_1' ) );
if ( is_wp_error( $run ) ) {
	check( 'import', false, $run->get_error_message() );
	jisento_test_finish();
}
$importer = $run['importer'];
$dst      = $run['wpdb'];
$live_before = $dst->get_col( "SHOW TABLES LIKE 'wp\\_%'" );
check( 'nothing is live before the swap (only shadows and ledger)', ! array_filter( $live_before, function ( $t ) { return '__js' !== substr( $t, -4 ) && 0 !== strpos( $t, 'wp_jisento_' ); } ), implode( ',', $live_before ) );
check( 'restored row counts match the export', ! $importer->count_mismatches( $expected_counts ), implode( '; ', $importer->count_mismatches( $expected_counts ) ) );
$swap = $importer->swap_shadows( $tables );
check( 'swap succeeded', ! is_wp_error( $swap ), is_wp_error( $swap ) ? $swap->get_error_message() : '' );
$constraints = isset( $GLOBALS['rt_notes']['constraints'] ) ? $GLOBALS['rt_notes']['constraints'] : array();
$fk          = $importer->repair_foreign_keys( $tables, $constraints );
check( 'foreign keys repaired', ! is_wp_error( $fk ), is_wp_error( $fk ) ? $fk->get_error_message() : '' );
printf( "Imported in %.1fs\n", microtime( true ) - $t0 );

rt_compare( $src, $dst, $tables, 'clean import' );

if ( $src_uca1400 ) {
	$dest_uca = false;
	$dup      = @mysqli_query( $dst->dbh, "SELECT CONVERT('a' USING utf8mb4) COLLATE utf8mb4_uca1400_ai_ci" );
	if ( $dup ) {
		$dest_uca = true;
		mysqli_free_result( $dup );
	}
	$yith = (string) $dst->get_var( "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_yith_wcwl'" );
	$mb4  = (string) $dst->get_var( "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_uca1400_mb4'" );
	$cs   = (string) $dst->get_var( "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_uca1400_cs'" );
	$col  = (string) $dst->get_var( "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_uca1400_col' AND COLUMN_NAME = 't'" );
	if ( $dest_uca ) {
		check( 'uca1400 utf8mb3 table collation unchanged', 'utf8mb3_uca1400_ai_ci' === $yith || 'utf8_uca1400_ai_ci' === $yith, $yith );
		check( 'uca1400 utf8mb4 table collation unchanged', 'utf8mb4_uca1400_ai_ci' === $mb4, $mb4 );
		check( 'uca1400 as_cs table collation unchanged', 'utf8mb4_uca1400_as_cs' === $cs, $cs );
		check( 'uca1400 column collation unchanged', 'utf8mb4_uca1400_ai_ci' === $col, $col );
		check( 'uca1400 kept with no mapping logged', empty( $GLOBALS['rt_notes']['collations']['wp_yith_wcwl'] ) && empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_mb4'] ) && empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_cs'] ) && empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_col'] ) );
		check( 'uca1400 kept decisions logged', ! empty( $GLOBALS['rt_notes']['collations_kept']['wp_yith_wcwl'] ) && ! empty( $GLOBALS['rt_notes']['collations_kept']['wp_uca1400_mb4'] ) );
	} else {
		check( 'uca1400 utf8mb3 mapped away on older dest', false === strpos( $yith, 'uca1400' ) && '' !== $yith, $yith );
		check( 'uca1400 utf8mb4 mapped away on older dest', false === strpos( $mb4, 'uca1400' ) && '' !== $mb4, $mb4 );
		check( 'uca1400 as_cs mapped to bin on older dest', false !== strpos( $cs, '_bin' ), $cs );
		check( 'uca1400 column mapped away on older dest', false === strpos( $col, 'uca1400' ) && '' !== $col, $col );
		check( 'uca1400 mapping recorded for the job log', ! empty( $GLOBALS['rt_notes']['collations']['wp_yith_wcwl'] ) && ! empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_mb4'] ) && ! empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_cs'] ) && ! empty( $GLOBALS['rt_notes']['collations']['wp_uca1400_col'] ) );
	}
	check( 'uca1400 rows restored', 'wishlist' === $dst->get_var( 'SELECT t FROM wp_yith_wcwl WHERE id = 1' ) && 'column' === $dst->get_var( 'SELECT t FROM wp_uca1400_col WHERE id = 1' ) );
}

$fk_row = $dst->get_row( "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_wc_download_log' AND REFERENCED_TABLE_NAME IS NOT NULL", ARRAY_A );
check( 'FK keeps its original name after the swap', $fk_row && 'fk_wc_download_log_permission_id' === $fk_row['CONSTRAINT_NAME'], json_encode( $fk_row ) );
check( 'FK points at the live parent table', $fk_row && 'wp_wc_orders' === $fk_row['REFERENCED_TABLE_NAME'], json_encode( $fk_row ) );
check( 'MyISAM table converted to InnoDB and the conversion noted', 'InnoDB' === $dst->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_myisam'" ) && isset( $GLOBALS['rt_notes']['engines']['wp_myisam'] ) );
check( '"100%" restored exactly', '100%' === $dst->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'pct_plain'" ) );
check( '"%20" restored exactly', '%20' === $dst->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'pct_url'" ) );
$ser = $dst->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'pct_serialized'" );
check( 'serialized value with "%" restored and unserializes', $ser === $serialized_pct && is_array( unserialize( $ser ) ) );
check( 'no shadow or retired tables left', ! $dst->get_col( "SHOW TABLES LIKE '%\\_\\_j%'" ) );
Database_Importer::forget_job( 'rt_job_1' );
check( 'ledger and cursor rows removed for the finished job', 0 === (int) $dst->get_var( "SELECT COUNT(*) FROM wp_jisento_import_applied WHERE job_id = 'rt_job_1'" ) && 0 === (int) $dst->get_var( "SELECT COUNT(*) FROM wp_jisento_import_cursor WHERE job_id = 'rt_job_1'" ) );

/* ---------------------------------------------------------------- crash-safe resume */

foreach ( array( 'before_exec', 'after_exec', 'before_commit', 'after_commit' ) as $point ) {
	$GLOBALS['rt_notes'] = array();
	jisento_test_wpdb( 'jisento_rt_crash', 'wp_', 'dest' );
	$run = rt_import( 'jisento_rt_crash', $state['segments'], $dump_dir, array( 'job_id' => 'rt_crash' ), array( 'point' => $point, 'after' => 3, 'every' => 211, 'times' => 25 ) );
	if ( is_wp_error( $run ) ) {
		check( "resume after kill $point", false, $run->get_error_message() );
		continue;
	}
	$run['importer']->swap_shadows( $tables );
	$run['importer']->repair_foreign_keys( $tables, isset( $GLOBALS['rt_notes']['constraints'] ) ? $GLOBALS['rt_notes']['constraints'] : array() );
	check( "worker killed $point {$run['killed']} times", $run['killed'] > 0 );
	rt_compare( $src, $run['wpdb'], $tables, "resume after kill $point" );
}

/* ---------------------------------------------------------------- preserve mode */

$pres = jisento_test_wpdb( 'jisento_rt_preserve', 'wp_', 'dest' );
rt_exec( $pres, 'CREATE TABLE wp_options (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
rt_exec( $pres, "INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'https://destination.test'), ('home', 'https://destination.test'), ('admin_email', 'admin@destination.test')" );
rt_exec( $pres, 'CREATE TABLE wp_users (ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, PRIMARY KEY (ID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
rt_exec( $pres, "INSERT INTO wp_users (user_login) VALUES ('destination_admin')" );
rt_exec( $pres, 'CREATE TABLE wp_usermeta (umeta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL DEFAULT 0, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (umeta_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
rt_exec( $pres, "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'nickname', 'destination_admin')" );
// Preserve restores every package table except destination users/usermeta.
$keep    = array_values( array_intersect( $tables, array( 'wp_users', 'wp_usermeta' ) ) );
$restore = array_values( array_diff( $tables, $keep ) );
// Source package has no users table — keep list may be empty; still keep dest users untouched.
$run = rt_import( 'jisento_rt_preserve', $state['segments'], $dump_dir, array( 'job_id' => 'rt_pres', 'restore' => $restore, 'keep' => $keep ) );
if ( is_wp_error( $run ) ) {
	check( 'preserve import', false, $run->get_error_message() );
} else {
	$swap = $run['importer']->swap_shadows( $restore );
	check( 'preserve swap', ! is_wp_error( $swap ) );
	$pw = $run['wpdb'];
	// Simulate Live_Url identity restore after swap (home/siteurl/admin_email from destination).
	$pw->query( "UPDATE wp_options SET option_value = 'https://destination.test' WHERE option_name IN ('siteurl','home')" );
	$pw->query( "UPDATE wp_options SET option_value = 'admin@destination.test' WHERE option_name = 'admin_email'" );
	if ( ! $pw->get_var( "SELECT option_id FROM wp_options WHERE option_name = 'admin_email'" ) ) {
		$pw->query( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('admin_email', 'admin@destination.test', 'yes')" );
	}
	check( 'preserve: siteurl kept from destination', 'https://destination.test' === $pw->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ) );
	check( 'preserve: home kept from destination', 'https://destination.test' === $pw->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'home'" ) );
	check( 'preserve: admin_email kept from destination', 'admin@destination.test' === $pw->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'admin_email'" ) );
	check( 'preserve: source options content imported', '100%' === $pw->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'pct_plain'" ) );
	check( 'preserve: existing wp_users kept', 'destination_admin' === $pw->get_var( 'SELECT user_login FROM wp_users' ) && 1 === (int) $pw->get_var( 'SELECT COUNT(*) FROM wp_users' ) );
	check( 'preserve: postmeta equals source', (int) $pw->get_var( 'SELECT COUNT(*) FROM wp_postmeta' ) === $rows_target + 1 );
	$bad = array();
	foreach ( array_values( array_diff( $tables, array( 'wp_options', 'wp_users', 'wp_usermeta' ) ) ) as $table ) {
		if ( rt_fingerprint( $src, $table ) !== rt_fingerprint( $pw, $table ) ) {
			$bad[] = $table;
		}
	}
	check( 'preserve: other tables match source', ! $bad, implode( ', ', $bad ) );
}
$probe = new Database_Importer( 'wp_', 'wp_', array( 'restore' => array( 'wp_postmeta', 'wp_options' ), 'keep' => array( 'wp_users', 'wp_usermeta' ) ) );
$drop  = $probe->classify( 'DROP TABLE IF EXISTS `wp_users`;' );
check( 'DROP of a kept live table is skipped', is_array( $drop ) && 'skip' === $drop['kind'] );
$drop  = $probe->classify( 'DROP TABLE IF EXISTS `wp_postmeta`;' );
check( 'DROP of a restored table only targets its shadow', is_array( $drop ) && 'DROP TABLE IF EXISTS `wp_postmeta__js`' === $drop['sql'] );
$other = $probe->classify( 'DELETE FROM `wp_users`' );
check( 'any other statement kind is refused', is_wp_error( $other ) );
$other = $probe->classify( 'TRUNCATE TABLE `wp_postmeta`' );
check( 'TRUNCATE is refused', is_wp_error( $other ) );

/* ---------------------------------------------------------------- MySQL 8 dump into this server */

$m8 = jisento_test_wpdb( 'jisento_rt_m8', 'wp_', 'dest' );
$GLOBALS['wpdb'] = $m8;
$m8_sql = Database_Exporter::header_sql( 'wp_' )
	. "DROP TABLE IF EXISTS `wp_c0900`;\nCREATE TABLE `wp_c0900` (\n  `id` int NOT NULL,\n  `t` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,\n  `u` varchar(40) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci /*!80016 DEFAULT ENCRYPTION='N' */;\n"
	. "INSERT INTO `wp_c0900` (`id`,`t`,`u`) VALUES (1,'naïve 100%','x'),(2,'Ölfarbe','y');\n";
$m8_dir = $dump_dir . '-m8';
@mkdir( $m8_dir );
file_put_contents( $m8_dir . '/part-00001.sql', $m8_sql );
$GLOBALS['rt_notes'] = array();
$run = rt_import( 'jisento_rt_m8', array( array( 'entry' => 'database/part-00001.sql' ) ), $m8_dir, array( 'job_id' => 'rt_m8' ) );
if ( is_wp_error( $run ) ) {
	check( 'MySQL 8 collation dump restores', false, $run->get_error_message() );
} else {
	$run['importer']->swap_shadows( array( 'wp_c0900' ) );
	$collation = $run['wpdb']->get_var( "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_c0900'" );
	$dest_0900 = false;
	$p0900     = @mysqli_query( $run['wpdb']->dbh, "SELECT CONVERT('a' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci" );
	if ( $p0900 ) {
		$dest_0900 = true;
		mysqli_free_result( $p0900 );
	}
	check( 'MySQL 8 collation restored or mapped', $dest_0900 ? 'utf8mb4_0900_ai_ci' === $collation : in_array( $collation, array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci' ), true ), (string) $collation );
	check( 'collation mapping recorded for the job log', $dest_0900 || ! empty( $GLOBALS['rt_notes']['collations']['wp_c0900'] ) );
	check( 'collation kept logged when dest accepts 0900', ! $dest_0900 || ! empty( $GLOBALS['rt_notes']['collations_kept']['wp_c0900'] ) );
	check( 'rows restored under the mapped collation', 'naïve 100%' === $run['wpdb']->get_var( 'SELECT t FROM wp_c0900 WHERE id = 1' ) );
}

foreach ( array_merge( (array) glob( $dump_dir . '/*' ), (array) glob( $m8_dir . '/*' ) ) as $f ) {
	@unlink( $f );
}
@rmdir( $dump_dir );
@rmdir( $m8_dir );
jisento_test_finish();
