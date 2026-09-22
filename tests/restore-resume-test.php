<?php
/**
 * Proves export cursors survive binary keys, and a killed restore
 * neither replays a committed statement nor loses a rolled-back one.
 */

define( 'ABSPATH', __DIR__ );
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require dirname( __DIR__ ) . '/includes/Database/Sql_Scanner.php';
require dirname( __DIR__ ) . '/includes/Database/Database_Exporter.php';
require dirname( __DIR__ ) . '/includes/Database/Database_Importer.php';

use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Database\Sql_Scanner;

$failed = 0;
function check( $name, $ok, $detail = '' ) {
	global $failed;
	if ( $ok ) {
		echo "OK  $name\n";
		return;
	}
	$failed++;
	echo "FAIL $name" . ( $detail ? " — $detail" : '' ) . "\n";
}

function decode_literal( $token ) {
	$token = trim( (string) $token );
	if ( '' === $token || 0 === strcasecmp( $token, 'NULL' ) ) {
		return null;
	}
	if ( preg_match( "/^X'([0-9a-fA-F]*)'$/i", $token, $hex ) || preg_match( '/^0x([0-9a-fA-F]*)$/i', $token, $hex ) ) {
		return '' === $hex[1] ? '' : hex2bin( $hex[1] );
	}
	$quote = $token[0];
	if ( "'" !== $quote && '"' !== $quote ) {
		return $token;
	}
	$inner = substr( $token, 1, -1 );
	$out   = '';
	$len   = strlen( $inner );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $inner[ $i ];
		if ( '\\' === $ch && isset( $inner[ $i + 1 ] ) ) {
			$next = $inner[ ++$i ];
			$map  = array( '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => chr( 26 ), '\\' => '\\', "'" => "'", '"' => '"' );
			$out .= isset( $map[ $next ] ) ? $map[ $next ] : $next;
			continue;
		}
		if ( $ch === $quote && isset( $inner[ $i + 1 ] ) && $inner[ $i + 1 ] === $quote ) {
			$out .= $quote;
			$i++;
			continue;
		}
		$out .= $ch;
	}
	return $out;
}

function parse_insert_sql( $sql ) {
	if ( ! preg_match( '/^INSERT\s+INTO\s+`([^`]+)`\s*\((.*)\)\s*VALUES\s*/is', ltrim( $sql ), $match ) ) {
		return null;
	}
	$columns = array();
	foreach ( explode( ',', $match[2] ) as $column ) {
		$columns[] = trim( $column, " `\t\n\r" );
	}
	$body = trim( substr( ltrim( $sql ), strlen( $match[0] ) ) );
	if ( ';' === substr( $body, -1 ) ) {
		$body = substr( $body, 0, -1 );
	}
	$rows = array();
	foreach ( Sql_Scanner::value_tuples( $body ) as $tuple ) {
		$fields = Sql_Scanner::tuple_fields( $tuple );
		$row    = array();
		foreach ( $columns as $index => $column ) {
			$row[ $column ] = isset( $fields[ $index ] ) ? decode_literal( $fields[ $index ] ) : null;
		}
		$rows[] = $row;
	}
	return array( 'table' => $match[1], 'rows' => $rows );
}

class Fake_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $rows = array();
	public $pending_rows = array();
	public $cursor = null;
	public $pending_cursor = null;
	public $in_txn = false;
	public $commits = 0;
	public $kill_before_commit = 0;
	public $kill_after_commit = 0;
	public $inserts = array();

	public function prepare( $query ) {
		$args = array_slice( func_get_args(), 1 );
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		$index = 0;
		return preg_replace_callback(
			'/%[sd]/',
			static function ( $match ) use ( &$args, &$index ) {
				$value = $args[ $index++ ];
				if ( '%d' === $match[0] ) {
					return (string) (int) $value;
				}
				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$sql = trim( (string) $sql );
		$this->last_error = '';
		if ( 0 === stripos( $sql, 'START TRANSACTION' ) ) {
			$this->in_txn = true;
			return true;
		}
		if ( 0 === stripos( $sql, 'ROLLBACK' ) ) {
			$this->pending_rows   = array();
			$this->pending_cursor = null;
			$this->in_txn         = false;
			return true;
		}
		if ( 0 === stripos( $sql, 'COMMIT' ) ) {
			$this->commits++;
			if ( $this->kill_before_commit === $this->commits ) {
				$this->pending_rows   = array();
				$this->pending_cursor = null;
				$this->in_txn         = false;
				throw new RuntimeException( 'killed before commit' );
			}
			$this->apply_pending();
			$this->in_txn = false;
			if ( $this->kill_after_commit === $this->commits ) {
				throw new RuntimeException( 'killed after commit' );
			}
			return true;
		}
		if ( 0 === stripos( $sql, 'CREATE TABLE' ) || 0 === stripos( $sql, 'SET ' ) ) {
			return true;
		}
		if ( preg_match( '/^TRUNCATE TABLE `([^`]+)`/i', $sql, $match ) ) {
			unset( $this->rows[ $match[1] ], $this->pending_rows[ $match[1] ] );
			return true;
		}
		if ( preg_match( '/^INSERT INTO `wp_jisento_import_(cursor|applied)`/i', $sql ) ) {
			if ( preg_match( "/VALUES \('([^']*)', (\d+), (\d+), (\d+), (\d+)\)/", $sql, $match ) ) {
				$this->pending_cursor = array(
					'offset'       => (int) $match[2],
					'piece_index'  => (int) $match[3],
					'stmt_end'     => (int) $match[4],
					'statement_no' => (int) $match[5],
				);
				if ( ! $this->in_txn ) {
					$this->cursor         = $this->pending_cursor;
					$this->pending_cursor = null;
				}
			}
			return 1;
		}
		if ( preg_match( '/^INSERT INTO `/i', $sql ) ) {
			$parsed = parse_insert_sql( $sql );
			if ( ! $parsed ) {
				$this->last_error = 'fake parser missed insert';
				return false;
			}
			$seen = array();
			foreach ( $parsed['rows'] as $row ) {
				$pk = $this->pk_value( $row );
				if ( isset( $seen[ $pk ] ) || $this->has_pk( $parsed['table'], $pk ) ) {
					$this->last_error = "Duplicate entry '" . substr( $pk, 0, 16 ) . "' for key 'PRIMARY'";
					return false;
				}
				$seen[ $pk ] = true;
			}
			foreach ( $parsed['rows'] as $row ) {
				if ( $this->in_txn ) {
					$this->pending_rows[ $parsed['table'] ][] = $row;
				} else {
					$this->rows[ $parsed['table'] ][] = $row;
				}
			}
			$this->inserts[] = $sql;
			return 1;
		}
		return true;
	}

	public function get_results( $sql, $output = null ) {
		if ( false !== stripos( $sql, 'SHOW COLUMNS' ) || false !== stripos( $sql, 'SHOW FULL COLUMNS' ) ) {
			if ( false !== stripos( $sql, 'filemods' ) ) {
				return array(
					array( 'Field' => 'filenameMD5', 'Type' => 'binary(16)' ),
					array( 'Field' => 'filename', 'Type' => 'text' ),
				);
			}
			return array(
				array( 'Field' => 'id', 'Type' => 'varchar(20)' ),
				array( 'Field' => 'name', 'Type' => 'text' ),
			);
		}
		if ( false !== stripos( $sql, 'SHOW KEYS' ) ) {
			$column = ( false !== stripos( $sql, 'filenameMD5' ) || false !== stripos( $sql, 'filemods' ) ) ? 'filenameMD5' : 'id';
			return array( array( 'Column_name' => $column, 'Seq_in_index' => 1 ) );
		}
		return array();
	}

	public function get_row( $sql, $output = null ) {
		if ( false !== stripos( $sql, 'jisento_import_cursor' ) ) {
			if ( ! is_array( $this->cursor ) ) {
				return null;
			}
			return array(
				'byte_offset'  => $this->cursor['offset'],
				'piece_index'  => $this->cursor['piece_index'],
				'stmt_end'     => $this->cursor['stmt_end'],
				'statement_no' => $this->cursor['statement_no'],
			);
		}
		if ( preg_match( '/FROM `([^`]+)`/i', $sql, $table ) && preg_match_all( "/HEX\\(`([^`]+)`\\) = '([0-9A-Fa-f]*)'/i", $sql, $matches, PREG_SET_ORDER ) ) {
			foreach ( $this->visible_rows( $table[1] ) as $row ) {
				$ok = true;
				foreach ( $matches as $match ) {
					$have = isset( $row[ $match[1] ] ) ? strtoupper( bin2hex( (string) $row[ $match[1] ] ) ) : '';
					if ( $have !== strtoupper( $match[2] ) ) {
						$ok = false;
					}
				}
				if ( ! $ok ) {
					continue;
				}
				$out = array();
				foreach ( $row as $column => $value ) {
					$out[ $column ] = null === $value ? null : strtoupper( bin2hex( (string) $value ) );
				}
				return $out;
			}
		}
		return null;
	}

	public function get_var( $sql ) {
		return null;
	}

	private function apply_pending() {
		foreach ( $this->pending_rows as $table => $rows ) {
			foreach ( $rows as $row ) {
				$this->rows[ $table ][] = $row;
			}
		}
		$this->pending_rows = array();
		if ( $this->pending_cursor ) {
			$this->cursor         = $this->pending_cursor;
			$this->pending_cursor = null;
		}
	}

	private function visible_rows( $table ) {
		$rows = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();
		if ( isset( $this->pending_rows[ $table ] ) ) {
			$rows = array_merge( $rows, $this->pending_rows[ $table ] );
		}
		return $rows;
	}

	private function pk_value( array $row ) {
		if ( array_key_exists( 'filenameMD5', $row ) ) {
			return (string) $row['filenameMD5'];
		}
		return isset( $row['id'] ) ? (string) $row['id'] : (string) reset( $row );
	}

	private function has_pk( $table, $pk ) {
		foreach ( $this->visible_rows( $table ) as $row ) {
			if ( $this->pk_value( $row ) === (string) $pk ) {
				return true;
			}
		}
		return false;
	}
}

function restore_file( $sql, Fake_Wpdb $db, $job_id ) {
	$path = tempnam( sys_get_temp_dir(), 'jisento-sql-' );
	file_put_contents( $path, $sql );
	$GLOBALS['wpdb'] = $db;
	$offset          = 0;
	$piece           = 0;
	$statement_no    = 0;
	$repeated        = 0;
	$guard           = 0;
	$result          = null;
	while ( $guard++ < 30 ) {
		$cursor = Database_Importer::preferred_cursor( $path, $job_id );
		if ( $cursor ) {
			$offset       = (int) $cursor['offset'];
			$piece        = (int) $cursor['piece_index'];
			$statement_no = (int) $cursor['statement_no'];
		}
		$cleared = array();
		if ( is_file( $path . '.cleared' ) ) {
			$cleared = array_values( array_filter( preg_split( '/\R/', (string) file_get_contents( $path . '.cleared' ) ) ) );
		}
		$importer = new Database_Importer( 'wp_', 'wp_', array() );
		$importer->use_replace( true );
		$importer->set_job_id( $job_id );
		$importer->set_statement_no( $statement_no );
		$importer->set_cleared( $cleared );
		try {
			$result = $importer->import_chunk( $path, $offset, 30, 800, $piece );
		} catch ( RuntimeException $e ) {
			if ( 'killed before commit' !== $e->getMessage() && 'killed after commit' !== $e->getMessage() ) {
				throw $e;
			}
			continue;
		}
		if ( is_wp_error( $result ) ) {
			@unlink( $path );
			@unlink( $path . '.cursor' );
			@unlink( $path . '.cleared' );
			return $result;
		}
		$repeated += (int) $result['repeated'];
		if ( ! empty( $result['done'] ) ) {
			@unlink( $path );
			@unlink( $path . '.cursor' );
			@unlink( $path . '.cleared' );
			$result['repeated_total'] = $repeated;
			return $result;
		}
	}
	return new WP_Error( 'stuck', 'restore did not finish' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code, $message ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

$pk = "\x1E{9da8d8863dd0ca";
check( 'reported key is 16 source bytes, and 0x1E is the first byte', 16 === strlen( $pk ) && "\x1E" === $pk[0] );
$packed = Database_Exporter::pack_pk_cursor( array( $pk, "\xFF\xFE" ) );
$round  = Database_Exporter::unpack_pk_cursor( json_decode( json_encode( $packed ), true ) );
check( 'hex cursor survives JSON', $round === array( $pk, "\xFF\xFE" ), var_export( $round, true ) );
check( 'binary export value is a hex literal', '0x' . bin2hex( $pk ) === Database_Exporter::binary_sql_literal( bin2hex( $pk ) ) );
check( 'empty binary export is not a bare 0x token', "X''" === Database_Exporter::binary_sql_literal( '' ) && 'NULL' === Database_Exporter::binary_sql_literal( null ) );
$tail = tempnam( sys_get_temp_dir(), 'jisento-export-' );
file_put_contents( $tail, 'HEADER-AND-PAGE-TWO' );
check( 'uncommitted export tail is discarded', 6 === Database_Exporter::reconcile_sql_file( $tail, 6 ) && 'HEADER' === file_get_contents( $tail ) );
@unlink( $tail );
$raw_json = json_encode( "\xFF\xFE" );
check( 'raw binary does not survive JSON', false === $raw_json || json_decode( $raw_json ) !== "\xFF\xFE" );

$sql = "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('" . $pk . "','readme');\n";
$statement = Sql_Scanner::statements( $sql );
$tuples    = Sql_Scanner::value_tuples( preg_replace( '/^.*VALUES\s*/is', '', rtrim( $statement[0], "; \n" ) ) );
$fields    = Sql_Scanner::tuple_fields( $tuples[0] );
check( 'parser keeps the 0x1E byte inside the value', decode_literal( $fields[0] ) === $pk );

$unique = '';
for ( $i = 1; $i <= 6; $i++ ) {
	$unique .= "INSERT INTO `wp_items` (`id`,`name`) VALUES ('$i','row-$i');\n";
}
$db = new Fake_Wpdb();
$db->kill_before_commit = 3;
$done = restore_file( $unique, $db, 'job-before' );
check( 'kill before commit still restores every row once', ! is_wp_error( $done ) && 6 === count( $db->rows['wp_items'] ) && 0 === (int) $done['repeated_total'], is_wp_error( $done ) ? $done->get_error_message() : 'rows=' . count( $db->rows['wp_items'] ) . ' repeated=' . ( is_array( $done ) ? $done['repeated_total'] : '' ) );

$db = new Fake_Wpdb();
$db->kill_after_commit = 2;
$done = restore_file( $unique, $db, 'job-after' );
check( 'kill after commit does not execute the committed statement again', ! is_wp_error( $done ) && 6 === count( $db->rows['wp_items'] ) && 0 === (int) $done['repeated_total'], is_wp_error( $done ) ? $done->get_error_message() : 'rows=' . count( isset( $db->rows['wp_items'] ) ? $db->rows['wp_items'] : array() ) );

$db = new Fake_Wpdb();
$done = restore_file( "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('" . $pk . "','readme');\nINSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('" . $pk . "','readme');\n", $db, 'job-copy' );
$copy_message = is_wp_error( $done ) ? $done->get_error_message() : '';
check(
	'duplicate binary key stops the restore and reports the source count',
	is_wp_error( $done )
		&& 1 === count( $db->rows['wp_filemods'] )
		&& $db->rows['wp_filemods'][0]['filenameMD5'] === $pk
		&& false !== strpos( $copy_message, 'Duplicate entry' )
		&& false !== strpos( $copy_message, 'Literal raw-byte occurrences in the source file: 2' )
		&& false !== strpos( $copy_message, bin2hex( $pk ) )
		&& trim( $db->inserts[0] ) === "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (0x" . bin2hex( $pk ) . ",'readme');",
	$copy_message
);

$other = "\xFF{ef282a00000000";
$db = new Fake_Wpdb();
$done = restore_file( "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('" . $pk . "','one');\nINSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('" . $other . "','two');\n", $db, 'job-two-keys' );
check( 'two different binary keys are each inserted once', ! is_wp_error( $done ) && 2 === count( $db->rows['wp_filemods'] ), is_wp_error( $done ) ? $done->get_error_message() : var_export( $db->rows, true ) );

$db = new Fake_Wpdb();
$done = restore_file( "INSERT INTO `wp_items` (`id`,`name`) VALUES ('9','first');\nINSERT INTO `wp_items` (`id`,`name`) VALUES ('9','second');\n", $db, 'job-conflict' );
check( 'different row with the same key is a hard error', is_wp_error( $done ) && false !== strpos( $done->get_error_message(), 'Duplicate entry' ) && false !== strpos( $done->get_error_message(), 'bytes differ' ), is_wp_error( $done ) ? $done->get_error_message() : 'it was accepted' );

$fixture = <<<'SQL'
INSERT INTO `wp_plain` (`id`,`note`) VALUES (1,'hello');
INSERT INTO `wp_plain` (`id`,`qty`,`amount`,`note`) VALUES (-2,3.5,0,'');
INSERT INTO `wp_plain` (`id`,`note`) VALUES (4,'semi;colon'),(5,'it\'s "ok"'),(6,NULL);
INSERT INTO `wp_plain` (`id`,`note`) VALUES (7,'a:1:{s:3:"key";s:4:"a;b;";}'),(8,'{"html":"<p>a;b</p>"}'),(9,'<div class="x">it\'s</div>');
INSERT INTO `wp_wfconfig` (`name`,`val`,`autoload`) VALUES ('activatingIP',0x3130332e32353332,'yes'),('actUpdateInterval',0x32,'yes'),('addCacheComment',0x30,'yes'),('emptyVal',X'','yes'),('adminUserList',0x613a313a7b733a313a2261223b733a313a2262223b7d,'yes');
INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (0x1e7b3964613864383836336464306361,'readme');
SQL;
$expected = array();
foreach ( Sql_Scanner::statements( $fixture ) as $statement ) {
	$statement = trim( $statement );
	if ( '' !== $statement ) {
		$expected[] = $statement;
	}
}
$db = new Fake_Wpdb();
$done = restore_file( $fixture, $db, 'job-fixture' );
$same = $db->inserts === $expected;
check( 'fixture SQL is executed without being rewritten', ! is_wp_error( $done ) && $same, is_wp_error( $done ) ? $done->get_error_message() : var_export( $db->inserts, true ) );
check( 'hex literal is stored as its real bytes', isset( $db->rows['wp_wfconfig'][0]['val'] ) && '103.2532' === $db->rows['wp_wfconfig'][0]['val'] && '' === $db->rows['wp_wfconfig'][3]['val'] && $db->rows['wp_filemods'][0]['filenameMD5'] === $pk, var_export( isset( $db->rows['wp_wfconfig'] ) ? $db->rows['wp_wfconfig'] : array(), true ) );
check( 'serialized, json, html, semicolon, and escaped quote rows are stored', isset( $db->rows['wp_plain'] ) && false !== strpos( json_encode( $db->rows['wp_plain'] ), 'semi;colon' ) && false !== strpos( json_encode( $db->rows['wp_plain'] ), 'a;b;' ) && false !== strpos( json_encode( $db->rows['wp_plain'] ), "it's" ), var_export( isset( $db->rows['wp_plain'] ) ? $db->rows['wp_plain'] : array(), true ) );

$db = new Fake_Wpdb();
$db->kill_after_commit = 2;
$done = restore_file( $fixture, $db, 'job-fixture-resume' );
check( 'interrupted fixture resumes without running a statement twice', ! is_wp_error( $done ) && $db->inserts === $expected, is_wp_error( $done ) ? $done->get_error_message() : 'inserts=' . count( $db->inserts ) );

function mysql_quote( $raw ) {
	$out = '';
	$len = strlen( $raw );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $raw[ $i ];
		$map = array( "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z', '\\' => '\\\\', "'" => "\\'", '"' => '\\"' );
		$out .= isset( $map[ $ch ] ) ? $map[ $ch ] : $ch;
	}
	return "'" . $out . "'";
}
$reported = hex2bin( '1d6da4df22f5728fc3ac0c3a42771fd7' );
$mariadb  = hex2bin( '1e7b6566323832613066376231333535' );
$quoted   = "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (" . mysql_quote( $reported ) . ",'wp-content/plugins/elementor/asse');\n";
$quoted  .= "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (" . mysql_quote( $mariadb ) . ",'other');\n";
$quoted  .= "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES ('','empty');\n";
$db = new Fake_Wpdb();
$done = restore_file( $quoted, $db, 'job-bytes' );
$sent = implode( "\n", $db->inserts );
check(
	'quoted binary primary keys are sent as hex and are not collapsed',
	! is_wp_error( $done )
		&& 3 === count( $db->rows['wp_filemods'] )
		&& $db->rows['wp_filemods'][0]['filenameMD5'] === $reported
		&& $db->rows['wp_filemods'][1]['filenameMD5'] === $mariadb
		&& '' === $db->rows['wp_filemods'][2]['filenameMD5']
		&& false !== strpos( $sent, '0x1d6da4df22f5728fc3ac0c3a42771fd7' )
		&& false !== strpos( $sent, '0x1e7b6566323832613066376231333535' )
		&& false !== strpos( $sent, "X''" )
		&& false === strpos( $sent, $reported )
		&& ! preg_match( '/(?<![0-9a-fA-F])0x(?![0-9a-fA-F])/', $sent ),
	is_wp_error( $done ) ? $done->get_error_message() : $sent
);

$first_key = '1d6da4df22f5728fc3ac0c3a42771fd7';
$later_key = '1e7b6566323832613066376231333535';
$later_sql  = "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (0x{$later_key},'first');\n";
$later_sql .= "INSERT INTO `wp_filemods` (`filenameMD5`,`filename`) VALUES (0x{$first_key},'a'),(0x{$later_key},'b');\n";
$db = new Fake_Wpdb();
$done = restore_file( $later_sql, $db, 'job-later-tuple' );
$later_message = is_wp_error( $done ) ? $done->get_error_message() : '';
check(
	'a later tuple is the key MariaDB rejected, not the first value in the statement',
	is_wp_error( $done )
		&& 1 === count( $db->rows['wp_filemods'] )
		&& false !== strpos( $later_message, 'tuple 2 of 2' )
		&& false !== strpos( $later_message, $later_key )
		&& false !== strpos( $later_message, 'First tuple key: ' . $first_key )
		&& false !== strpos( $later_message, 'Rejected key inside the SQL passed to the driver: yes' )
		&& false !== strpos( $later_message, 'Literal raw-byte occurrences in the source file: 2' )
		&& false === strpos( $later_message, 'MariaDB rejected primary key hex: ' . $first_key ),
	$later_message
);
check(
	'export primary keys must move forward',
	Database_Exporter::pk_hex_cmp( array( $first_key ), array( $later_key ) ) < 0
		&& 0 === Database_Exporter::pk_hex_cmp( array( $later_key ), array( strtoupper( $later_key ) ) )
		&& Database_Exporter::pk_hex_cmp( array( $later_key ), array( $first_key ) ) > 0
);
check( 'litespeed avatar id is an integer key', 'int' === Database_Exporter::column_kind_from_type( 'bigint(20) unsigned' ) );
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
$packed = Database_Exporter::pack_keyset( array( '10', 'https://a' ), array( 'int', 'string' ) );
$restored = Database_Exporter::cursor_comparable( json_decode( json_encode( $packed ), true ), array( 'int', 'string' ) );
check( 'integer and text cursors survive JSON', $restored === array( '10', 'https://a' ), var_export( $restored, true ) );

echo $failed ? "\n$failed failed\n" : "\nRestore resume checks passed\n";
exit( $failed ? 1 : 0 );
