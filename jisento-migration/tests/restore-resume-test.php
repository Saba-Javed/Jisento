<?php
/**
 * Export cursors survive binary and integer keys, and an interrupted export leaves no
 * half-written segment behind. Restore crash-resume against a real database is covered
 * by tests/roundtrip-test.php (fault injection before and after each commit).
 *
 * Run: php tests/restore-resume-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Dump_Writer;
use Jisento\Migration\Database\Sql_Scanner;

$pk = "\x1E{9da8d8863dd0ca";
check( 'reported key is 16 source bytes, and 0x1E is the first byte', 16 === strlen( $pk ) && "\x1E" === $pk[0] );
$packed = Database_Exporter::pack_pk_cursor( array( $pk, "\xFF\xFE" ) );
$round  = Database_Exporter::unpack_pk_cursor( json_decode( json_encode( $packed ), true ) );
check( 'hex cursor survives JSON', $round === array( $pk, "\xFF\xFE" ), var_export( $round, true ) );
check( 'binary export value is a hex literal', '0x' . bin2hex( $pk ) === Database_Exporter::binary_sql_literal( bin2hex( $pk ) ) );
check( 'empty binary export is not a bare 0x token', "X''" === Database_Exporter::binary_sql_literal( '' ) && 'NULL' === Database_Exporter::binary_sql_literal( null ) );
$raw_json = json_encode( "\xFF\xFE" );
check( 'raw binary does not survive JSON', false === $raw_json || json_decode( $raw_json ) !== "\xFF\xFE" );

$sql       = "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (0x" . bin2hex( $pk ) . ",'readme');\n";
$statement = Sql_Scanner::statements( $sql );
$tuples    = Sql_Scanner::value_tuples( preg_replace( '/^.*VALUES\s*/is', '', rtrim( $statement[0], "; \n" ) ) );
$fields    = Sql_Scanner::tuple_fields( $tuples[0] );
check( 'parser keeps the binary key as one hex field', '0x' . bin2hex( $pk ) === trim( $fields[0] ) );

// --- interrupted export ----------------------------------------------------

$dir = sys_get_temp_dir() . '/jisento-dump-' . getmypid();
@mkdir( $dir, 0777, true );
file_put_contents( $dir . '/part-00001.sql', 'FINISHED' );
file_put_contents( $dir . '/part-00002.sql', 'WRITTEN-BUT-NOT-RECORDED' );
file_put_contents( $dir . '/part-00003.sql.partial', 'HALF' );
file_put_contents( $dir . '/unrelated.txt', 'keep' );
$state             = Dump_Writer::initial_state( array( 'wp_posts' ) );
$state['segments'] = array( array( 'entry' => 'database/part-00001.sql' ) );
$writer            = new Dump_Writer( $dir, new stdClass() );
$writer->recover( $state );
check( 'recorded segment survives recovery', is_file( $dir . '/part-00001.sql' ) );
check( 'segment the saved state does not list is removed', ! is_file( $dir . '/part-00002.sql' ) );
check( 'half-written segment is removed', ! is_file( $dir . '/part-00003.sql.partial' ) );
check( 'unrelated files are not touched', is_file( $dir . '/unrelated.txt' ) );
foreach ( glob( $dir . '/*' ) as $file ) {
	unlink( $file );
}
rmdir( $dir );

// --- keyset pagination -----------------------------------------------------

$first_key = '1d6da4df22f5728fc3ac0c3a42771fd7';
$later_key = '1e7b6566323832613066376231333535';
check(
	'export primary keys must move forward',
	Database_Exporter::pk_hex_cmp( array( $first_key ), array( $later_key ) ) < 0
		&& 0 === Database_Exporter::pk_hex_cmp( array( $later_key ), array( strtoupper( $later_key ) ) )
		&& Database_Exporter::pk_hex_cmp( array( $later_key ), array( $first_key ) ) > 0
);
check( 'litespeed avatar id is an integer key', 'int' === Database_Exporter::column_kind_from_type( 'bigint(20) unsigned' ) );
check( 'zerofill integers are integer keys', 'int' === Database_Exporter::column_kind_from_type( 'int(5) unsigned zerofill' ) );
check( 'zerofill values compare as numbers', '7' === Database_Exporter::canonical_int( '00007' ) && 1 === Database_Exporter::key_follows( array( '00009' ), array( '00010' ), array( 'int' ) ) );
check( 'binary and varchar keys keep their own kinds', 'binary' === Database_Exporter::column_kind_from_type( 'binary(16)' ) && 'string' === Database_Exporter::column_kind_from_type( 'varchar(1000)', 'utf8mb4_unicode_ci' ) );
$int_kinds = array( 'int' );
$old_hex   = array( '__hexpk' => array( bin2hex( '9' ) ) );
$decoded9  = Database_Exporter::cursor_comparable( $old_hex, $int_kinds );
check(
	'integer 9 then 10 is forward even though the hex text is not',
	$decoded9 === array( '9' )
		&& 1 === Database_Exporter::key_follows( array( '9' ), array( '10' ), $int_kinds )
		&& Database_Exporter::pk_hex_cmp( array( bin2hex( '9' ) ), array( bin2hex( '10' ) ) ) > 0,
	var_export( $decoded9, true )
);
$walk = true;
$prev = null;
for ( $i = 1; $i <= 20; $i++ ) {
	$cur = array( (string) $i );
	if ( null !== $prev && Database_Exporter::key_follows( $prev, $cur, $int_kinds ) < 1 ) {
		$walk = false;
	}
	$prev = $cur;
}
check( 'unsigned ids 1 through 20 stay in numeric order', $walk );
check( 'a repeated integer key does not follow', 0 === Database_Exporter::key_follows( array( '10' ), array( '10' ), $int_kinds ) );
check( 'an integer key that goes backwards does not follow', -1 === Database_Exporter::key_follows( array( '10' ), array( '9' ), $int_kinds ) );
check(
	'signed and large unsigned integers use numeric order',
	1 === Database_Exporter::key_follows( array( '-10' ), array( '-2' ), $int_kinds )
		&& 1 === Database_Exporter::key_follows( array( '-2' ), array( '0' ), $int_kinds )
		&& 1 === Database_Exporter::key_follows( array( '9223372036854775807' ), array( '9223372036854775808' ), $int_kinds )
);
$avatar = Database_Exporter::keyset_predicate( array( '`id`' ), array( array( 'k' => 'int', 'v' => '9' ) ) );
check( 'avatar pagination is id greater than the last integer', $avatar[0] === '(`id`) > (%s)' && $avatar[1] === array( '9' ), var_export( $avatar, true ) );
$composite = Database_Exporter::keyset_predicate(
	array( '`id`', '`url`' ),
	array(
		array( 'k' => 'int', 'v' => '9' ),
		array( 'k' => 'string', 'h' => bin2hex( 'https://a' ) ),
	)
);
check(
	'composite pagination compares every key column',
	$composite[0] === '(`id`, `url`) > (%s, %s)' && '9' === $composite[1][0] && 'https://a' === $composite[1][1],
	var_export( $composite, true )
);
$binary_page = Database_Exporter::keyset_predicate( array( '`filenameMD5`' ), array( array( 'k' => 'binary', 'v' => $first_key ) ) );
check( 'binary pagination compares raw bytes', $binary_page[0] === '(`filenameMD5`) > (UNHEX(%s))' && $binary_page[1] === array( $first_key ) );
check(
	'composite keys use the full tuple',
	1 === Database_Exporter::key_follows( array( '1', 'b' ), array( '1', 'a' ), array( 'int', 'string' ) )
		&& 0 === Database_Exporter::key_follows( array( '1', 'b' ), array( '1', 'b' ), array( 'int', 'string' ) )
		&& -1 === Database_Exporter::key_follows( array( '2', 'a' ), array( '1', 'z' ), array( 'int', 'string' ) )
);
check(
	'text keys are not ordered as raw bytes',
	1 === Database_Exporter::key_follows( array( 'a' ), array( 'B' ), array( 'string' ) )
		&& 0 === Database_Exporter::key_follows( array( 'a' ), array( 'a' ), array( 'string' ) )
);
$packed   = Database_Exporter::pack_keyset( array( '10', 'https://a' ), array( 'int', 'string' ) );
$restored = Database_Exporter::cursor_comparable( json_decode( json_encode( $packed ), true ), array( 'int', 'string' ) );
check( 'integer and text cursors survive JSON', $restored === array( '10', 'https://a' ), var_export( $restored, true ) );

jisento_test_finish();
