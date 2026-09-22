<?php
/**
 * Streaming SQL importer with destination prefix rewriting.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Importer {

	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @var string
	 */
	private $source_prefix;

	/**
	 * @var string
	 */
	private $dest_prefix;

	/**
	 * @var array
	 */
	private $skip_tables = array();

	/**
	 * @var bool
	 */
	private $replace = false;

	/**
	 * Replace mode writes into shadow tables and swaps them only after the dump finishes.
	 *
	 * @var bool
	 */
	private $shadow = false;

	/**
	 * Live table name => shadow table name.
	 *
	 * @var array<string,string>
	 */
	private $shadow_map = array();

	/**
	 * @var array
	 */
	private $cleared = array();

	/**
	 * @var int
	 */
	private $repeated_keys = 0;

	/**
	 * @var string
	 */
	private $job_id = '';

	/**
	 * @var int
	 */
	private $statement_no = 0;

	/**
	 * @var resource|null
	 */
	private $cursor_handle = null;

	/**
	 * @var string
	 */
	private $sql_path = '';

	/**
	 * @var callable|null
	 */
	private $progress = null;

	/**
	 * @var callable|null
	 */
	private $after_statement = null;

	/**
	 * @var bool
	 */
	private $txn_open = false;

	/**
	 * @var bool
	 */
	private $cursor_ready = false;

	/**
	 * @var array
	 */
	private $pk_cache = array();

	/**
	 * @var array{offset:int,piece:int}
	 */
	private $stmt_at = array(
		'offset' => 0,
		'piece'  => 0,
	);

	/**
	 * @var string
	 */
	private $lookup_note = '';

	/**
	 * @var string
	 */
	private $stmt_source = '';

	/**
	 * SQL bytes passed to the driver, after binary literals are rewritten.
	 *
	 * @var string
	 */
	private $executed_sql = '';

	/**
	 * @var array<string,array<string,bool>>
	 */
	private $binary_columns = array();

	/**
	 * @var string
	 */
	private $repeated_note = '';

	public function set_job_id( $job_id ) {
		$this->job_id = (string) $job_id;
	}

	public function set_statement_no( $number ) {
		$this->statement_no = (int) $number;
	}

	public function statement_no() {
		return (int) $this->statement_no;
	}

	public function set_progress( $progress ) {
		$this->progress = is_callable( $progress ) ? $progress : null;
	}

	public function set_after_statement( $callback ) {
		$this->after_statement = is_callable( $callback ) ? $callback : null;
	}

	public function __construct( $source_prefix, $dest_prefix, array $skip_tables = array() ) {
		global $wpdb;
		$this->wpdb          = $wpdb;
		$this->source_prefix = $source_prefix;
		$this->dest_prefix   = $dest_prefix;
		$this->skip_tables   = $skip_tables;
	}

	public function use_replace( $replace ) {
		$this->replace = (bool) $replace;
	}

	public function use_shadow( $shadow ) {
		$this->shadow = (bool) $shadow && $this->replace;
	}

	public function set_cleared( array $tables ) {
		$this->cleared = array();
		foreach ( $tables as $table ) {
			$this->cleared[ (string) $table ] = true;
		}
	}

	public function cleared_tables() {
		return array_keys( $this->cleared );
	}

	public function repeated_keys() {
		return (int) $this->repeated_keys;
	}

	/**
	 * Process SQL from a file starting at byte offset. Returns new offset and done flag.
	 *
	 * @return array{offset:int,done:bool,statements:int,error?:string}
	 */
	public function import_chunk( $sql_path, $byte_offset, $time_budget = 12, $max_statements = 800, $piece_index = 0 ) {
		$handle = fopen( $sql_path, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'jisento_sql_open', __( 'Unable to open the database dump.', 'jisento' ) );
		}

		fseek( $handle, (int) $byte_offset );
		$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->use_binary_client();

		$deadline    = microtime( true ) + max( 2, (float) $time_budget );
		$pending     = '';
		$statements  = 0;
		$last_table  = '';
		$creates     = 0;
		$started     = microtime( true );
		$piece_index = (int) $piece_index;
		$this->sql_path = $sql_path;
		$this->load_shadow_map();
		$this->ensure_cursor_table();

		while ( ! feof( $handle ) ) {
			if ( $statements > 0 && ( microtime( true ) >= $deadline || $statements >= $max_statements ) ) {
				break;
			}

			$read = fread( $handle, 262144 );
			if ( false === $read || '' === $read ) {
				break;
			}
			$pending .= $read;

			if ( strlen( $pending ) > 33554432 && null === Sql_Scanner::statement_end( $pending ) ) {
				fclose( $handle );
				$this->restore_connection_charset();
				return new \WP_Error( 'jisento_sql_large', __( 'A single SQL statement is larger than 32MB, so it cannot be restored safely. Export the package again with this version of Jisento.', 'jisento' ) . $this->job_suffix() );
			}

			while ( '' !== $pending ) {
				$split = Sql_Scanner::next_statement( $pending );
				if ( null === $split ) {
					break;
				}
				if ( $statements > 0 && ( microtime( true ) >= $deadline || $statements >= $max_statements ) ) {
					break 2;
				}
				$sql     = $split[0];
				$pending = $split[1];
				$clean   = $this->strip_leading_comments( $sql );
				$end     = ftell( $handle ) - strlen( $pending );
				if ( '' === $clean ) {
					$this->remember_cursor( $end, 0, 0 );
					continue;
				}
				$ran = $this->run_pieces( $clean, $deadline, $piece_index, $end - strlen( $sql ), $end );
				$piece_index = 0;
				if ( is_wp_error( $ran ) ) {
					fclose( $handle );
					$this->restore_connection_charset();
					return $ran;
				}
				$statements += (int) $ran['statements'];
				$creates    += (int) $ran['creates'];
				if ( '' !== $ran['table'] ) {
					$last_table = $ran['table'];
				}
				if ( ! empty( $ran['partial'] ) ) {
					fclose( $handle );
					return $this->chunk_result( $ran['offset'], false, $statements, $last_table, $creates, $started, $ran['piece_index'], $ran['stmt_end'] );
				}
			}
		}

		$pos = ftell( $handle );
		clearstatcache( true, $sql_path );
		$size = filesize( $sql_path );
		$eof  = feof( $handle ) || ( false !== $pos && false !== $size && $pos >= $size );
		fclose( $handle );

		$tail = $this->strip_leading_comments( $pending );
		if ( $eof && '' === $tail ) {
			$pending = '';
		}
		if ( $eof && '' !== $tail ) {
			$ran = $this->run_pieces( $tail, $deadline, $piece_index, (int) $pos - strlen( $pending ), (int) $pos );
			if ( is_wp_error( $ran ) ) {
				$this->restore_connection_charset();
				return $ran;
			}
			$statements += (int) $ran['statements'];
			$creates    += (int) $ran['creates'];
			if ( '' !== $ran['table'] ) {
				$last_table = $ran['table'];
			}
			if ( ! empty( $ran['partial'] ) ) {
				return $this->chunk_result( $ran['offset'], false, $statements, $last_table, $creates, $started, $ran['piece_index'], $ran['stmt_end'] );
			}
			$pending = '';
		}

		$offset = max( (int) $byte_offset, (int) $pos - strlen( $pending ) );
		if ( $eof && '' === $pending ) {
			$this->remember_cursor( $offset, 0, 0 );
		}

		return $this->chunk_result( $offset, $eof && '' === $pending, $statements, $last_table, $creates, $started, 0, 0 );
	}

	/**
	 * A semicolon ends a statement only outside quoted values.
	 * Backslash escapes are preserved, so binary bytes such as 0x1E stay inside the value.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function take_statement( $buffer ) {
		$len    = strlen( $buffer );
		$in     = false;
		$escape = false;
		$quote  = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];
			if ( $in ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$escape = true;
					continue;
				}
				if ( $ch === $quote ) {
					$in    = false;
					$quote = '';
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				continue;
			}
			if ( ';' === $ch ) {
				return array( substr( $buffer, 0, $i + 1 ), substr( $buffer, $i + 1 ) );
			}
		}
		return null;
	}

	/**
	 * @return array{statements:int,creates:int,table:string}|\WP_Error
	 */
	private function run_pieces( $sql, $deadline, $piece_index, $start, $end ) {
		$seen  = $this->statement_progress( $sql );
		$parts = Sql_Scanner::split_insert( $sql );
		$count = 0;
		foreach ( $parts as $index => $part ) {
			if ( $index < $piece_index ) {
				continue;
			}
			if ( $count > 0 && microtime( true ) >= $deadline ) {
				return array(
					'statements' => $count,
					'creates'    => (int) $seen['creates'],
					'table'      => (string) $seen['table'],
					'partial'    => true,
					'offset'     => $start,
					'piece_index'=> $index,
					'stmt_end'   => $end,
				);
			}
			$this->stmt_at = array(
				'offset' => (int) $start,
				'piece'  => (int) $index,
			);
			if ( $this->was_applied( $start, $index ) ) {
				$result = true;
			} else {
				$result = $this->run_statement( $part );
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! $this->note_applied( $start, $index ) ) {
				return $this->statement_error( $part, ' ' . __( 'The restore log for this statement could not be written, so the statement was rolled back.', 'jisento' ) );
			}
			$count++;
			$this->statement_no++;
			$next = $index + 1;
			$saved = ( $next >= count( $parts ) ) ? $this->remember_cursor( $end, 0, 0 ) : $this->remember_cursor( $start, $next, $end );
			if ( ! $saved ) {
				return new \WP_Error( 'jisento_sql_cursor', __( 'The database statement was rolled back because its resume point could not be saved.', 'jisento' ) . $this->job_suffix() );
			}
		}
		return array(
			'statements' => $count,
			'creates'    => (int) $seen['creates'],
			'table'      => (string) $seen['table'],
			'partial'    => false,
		);
	}

	private function chunk_result( $offset, $done, $statements, $table, $creates, $started, $piece_index, $stmt_end ) {
		$this->restore_connection_charset();
		$this->notify( $offset, $piece_index, $stmt_end, $table );
		if ( $this->cursor_handle ) {
			fclose( $this->cursor_handle );
			$this->cursor_handle = null;
		}
		return array(
			'offset'       => (int) $offset,
			'done'         => (bool) $done,
			'statements'   => (int) $statements,
			'statement_no' => (int) $this->statement_no,
			'table'        => (string) $table,
			'creates'      => (int) $creates,
			'cleared'      => $this->cleared_tables(),
			'repeated'      => $this->repeated_keys,
			'repeated_note' => $this->repeated_note,
			'seconds'      => round( microtime( true ) - $started, 3 ),
			'piece_index'  => (int) $piece_index,
			'stmt_end'     => (int) $stmt_end,
		);
	}

	/**
	 * Commit the statement and its resume point together.
	 * The cursor file is written only after COMMIT, so it cannot move past uncommitted work.
	 *
	 * @return bool
	 */
	private function remember_cursor( $offset, $piece, $stmt_end ) {
		if ( '' === $this->sql_path ) {
			return true;
		}
		$this->ensure_cursor_table();
		if ( ! $this->txn_open ) {
			$this->begin_transaction();
		}
		if ( ! $this->write_cursor_row( $offset, $piece, $stmt_end ) ) {
			$this->rollback();
			return false;
		}
		$committed = $this->wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->txn_open = false;
		if ( false === $committed ) {
			return false;
		}
		if ( ! $this->cursor_handle ) {
			$this->cursor_handle = fopen( $this->sql_path . '.cursor', 'cb' );
		}
		if ( $this->cursor_handle ) {
			rewind( $this->cursor_handle );
			ftruncate( $this->cursor_handle, 0 );
			fwrite( $this->cursor_handle, (int) $offset . ' ' . (int) $piece . ' ' . (int) $stmt_end . ' ' . (int) $this->statement_no );
			fflush( $this->cursor_handle );
		}
		return true;
	}

	private function begin_transaction() {
		if ( $this->txn_open ) {
			return;
		}
		$this->wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->txn_open = true;
	}

	private function rollback() {
		if ( $this->txn_open ) {
			$this->wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$this->txn_open = false;
	}

	private function cursor_table() {
		return $this->wpdb->prefix . 'jisento_import_cursor';
	}

	private function ensure_cursor_table() {
		if ( $this->cursor_ready || $this->txn_open ) {
			return;
		}
		$table = $this->cursor_table();
		$this->wpdb->query(
			'CREATE TABLE IF NOT EXISTS `' . $table . '` (
				job_id varchar(64) NOT NULL,
				byte_offset bigint(20) unsigned NOT NULL DEFAULT 0,
				piece_index int(10) unsigned NOT NULL DEFAULT 0,
				stmt_end bigint(20) unsigned NOT NULL DEFAULT 0,
				statement_no int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (job_id)
			) ENGINE=InnoDB'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$applied = $this->applied_table();
		$this->wpdb->query(
			'CREATE TABLE IF NOT EXISTS `' . $applied . '` (
				job_id varchar(64) NOT NULL,
				byte_offset bigint(20) unsigned NOT NULL,
				piece_index int(10) unsigned NOT NULL,
				statement_no int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (job_id, byte_offset, piece_index)
			) ENGINE=InnoDB'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->cursor_ready = true;
	}

	private function applied_table() {
		return $this->wpdb->prefix . 'jisento_import_applied';
	}

	private function prepare_sql( $sql, array $args ) {
		if ( ! $args ) {
			return $sql;
		}
		return $this->wpdb->prepare( $sql, ...$args );
	}

	private function was_applied( $offset, $piece ) {
		$this->ensure_cursor_table();
		$table = $this->applied_table();
		$job   = '' !== $this->job_id ? $this->job_id : 'import';
		$found = $this->wpdb->get_var(
			$this->prepare_sql(
				'SELECT statement_no FROM `' . $table . '` WHERE job_id = %s AND byte_offset = %d AND piece_index = %d',
				array( $job, (int) $offset, (int) $piece )
			)
		);
		return null !== $found && false !== $found && '' !== $found;
	}

	private function note_applied( $offset, $piece ) {
		if ( ! $this->txn_open ) {
			$this->begin_transaction();
		}
		$table = $this->applied_table();
		$job   = '' !== $this->job_id ? $this->job_id : 'import';
		$sql   = $this->prepare_sql(
			'INSERT INTO `' . $table . '` (job_id, byte_offset, piece_index, statement_no) VALUES (%s, %d, %d, %d) ON DUPLICATE KEY UPDATE statement_no = %d',
			array( $job, (int) $offset, (int) $piece, (int) $this->statement_no + 1, (int) $this->statement_no + 1 )
		);
		$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '' === (string) $this->wpdb->last_error;
	}

	private function write_cursor_row( $offset, $piece, $stmt_end ) {
		$table = $this->cursor_table();
		$job   = '' !== $this->job_id ? $this->job_id : 'import';
		$sql   = $this->wpdb->prepare(
			'INSERT INTO `' . $table . '` (job_id, byte_offset, piece_index, stmt_end, statement_no) VALUES (%s, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE byte_offset = %d, piece_index = %d, stmt_end = %d, statement_no = %d',
			$job,
			(int) $offset,
			(int) $piece,
			(int) $stmt_end,
			(int) $this->statement_no,
			(int) $offset,
			(int) $piece,
			(int) $stmt_end,
			(int) $this->statement_no
		);
		return false !== $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function read_db_cursor( $job_id ) {
		global $wpdb;
		if ( ! $wpdb || '' === (string) $job_id ) {
			return null;
		}
		$table = $wpdb->prefix . 'jisento_import_cursor';
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT byte_offset, piece_index, stmt_end, statement_no FROM `' . $table . '` WHERE job_id = %s', $job_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'offset'       => (int) $row['byte_offset'],
			'piece_index'  => (int) $row['piece_index'],
			'stmt_end'     => (int) $row['stmt_end'],
			'statement_no' => (int) $row['statement_no'],
		);
	}

	public static function preferred_cursor( $sql_path, $job_id ) {
		$db = self::read_db_cursor( $job_id );
		if ( $db ) {
			return $db;
		}
		return self::read_cursor( $sql_path );
	}

	public static function read_cursor( $sql_path ) {
		$path = $sql_path . '.cursor';
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$parts = preg_split( '/\s+/', trim( (string) file_get_contents( $path ) ) );
		if ( ! $parts || '' === $parts[0] ) {
			return null;
		}
		return array(
			'offset'       => (int) $parts[0],
			'piece_index'  => isset( $parts[1] ) ? (int) $parts[1] : 0,
			'stmt_end'     => isset( $parts[2] ) ? (int) $parts[2] : 0,
			'statement_no' => isset( $parts[3] ) ? (int) $parts[3] : 0,
		);
	}

	private function notify( $offset, $piece, $stmt_end, $table ) {
		if ( ! $this->progress ) {
			return;
		}
		call_user_func(
			$this->progress,
			array(
				'offset'       => (int) $offset,
				'piece_index'  => (int) $piece,
				'stmt_end'     => (int) $stmt_end,
				'cleared'      => $this->cleared_tables(),
				'table'        => (string) $table,
				'statement_no' => (int) $this->statement_no,
			)
		);
	}

	private function job_suffix() {
		return '' === $this->job_id ? '' : ' Job: ' . $this->job_id;
	}

	private function spill_paths( $sql_path ) {
		return array(
			'sql' => $sql_path . '.spill',
			'pos' => $sql_path . '.spill.pos',
		);
	}

	private function write_spill( $sql_path, $sql, $offset ) {
		$paths = $this->spill_paths( $sql_path );
		file_put_contents( $paths['sql'], $sql );
		file_put_contents( $paths['pos'], (string) (int) $offset );
	}

	/**
	 * Continue rows from a statement that was too large for one request.
	 *
	 * @return array<string,mixed>|\WP_Error|null
	 */
	private function drain_spill( $sql_path, $byte_offset, $time_budget, $max_statements ) {
		$paths = $this->spill_paths( $sql_path );
		if ( ! is_file( $paths['sql'] ) || ! is_file( $paths['pos'] ) ) {
			return null;
		}
		$marked = (int) file_get_contents( $paths['pos'] );
		if ( $marked !== (int) $byte_offset ) {
			@unlink( $paths['sql'] );
			@unlink( $paths['pos'] );
			return null;
		}
		$chunk = $this->import_chunk( $paths['sql'], 0, $time_budget, $max_statements, false );
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}
		if ( empty( $chunk['done'] ) ) {
			$raw = (string) file_get_contents( $paths['sql'] );
			file_put_contents( $paths['sql'], substr( $raw, (int) $chunk['offset'] ) );
			file_put_contents( $paths['pos'], (string) $byte_offset );
			$chunk['offset'] = $byte_offset;
			$chunk['done']   = false;
			return $chunk;
		}
		@unlink( $paths['sql'] );
		@unlink( $paths['pos'] );
		$chunk['offset'] = $byte_offset;
		$chunk['done']   = false;
		return $chunk;
	}

	/**
	 * Break a multi-row INSERT into smaller statements without changing row values.
	 *
	 * @return string[]
	 */
	private function split_large_insert( $sql ) {
		$limit = 262144;
		if ( strlen( $sql ) <= $limit ) {
			return array( $sql );
		}
		if ( ! preg_match( '/^(INSERT\s+INTO\s+`[^`]+`\s*\(.*?\)\s*VALUES\s*)/is', $sql, $match ) ) {
			return array( $sql );
		}
		$prefix = $match[1];
		$body   = rtrim( substr( $sql, strlen( $prefix ) ) );
		if ( ';' === substr( $body, -1 ) ) {
			$body = substr( $body, 0, -1 );
		}
		$tuples = $this->split_tuples( $body );
		if ( count( $tuples ) < 2 ) {
			return array( $sql );
		}
		$out   = array();
		$batch = array();
		$size  = strlen( $prefix ) + 1;
		foreach ( $tuples as $tuple ) {
			$add = strlen( $tuple ) + 1;
			if ( $batch && ( $size + $add ) > $limit ) {
				$out[] = $prefix . implode( ',', $batch ) . ';';
				$batch = array();
				$size  = strlen( $prefix ) + 1;
			}
			$batch[] = $tuple;
			$size   += $add;
		}
		if ( $batch ) {
			$out[] = $prefix . implode( ',', $batch ) . ';';
		}
		return $out ? $out : array( $sql );
	}

	/**
	 * @return string[]
	 */
	private function split_tuples( $body ) {
		$tuples = array();
		$len    = strlen( $body );
		$start  = null;
		$depth  = 0;
		$in     = false;
		$escape = false;
		$quote  = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $body[ $i ];
			if ( $in ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$escape = true;
					continue;
				}
				if ( $ch === $quote ) {
					$in = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				continue;
			}
			if ( '(' === $ch ) {
				if ( 0 === $depth ) {
					$start = $i;
				}
				$depth++;
				continue;
			}
			if ( ')' === $ch && $depth > 0 ) {
				$depth--;
				if ( 0 === $depth && null !== $start ) {
					$tuples[] = substr( $body, $start, $i - $start + 1 );
					$start    = null;
				}
			}
		}
		return $tuples;
	}

	private function statement_progress( $sql ) {
		$table   = '';
		$creates = 0;
		if ( preg_match( '/^(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO)\s+`([^`]+)`/i', ltrim( $sql ), $match ) ) {
			$table = $match[1];
		}
		if ( preg_match( '/^CREATE\s+TABLE\b/i', ltrim( $sql ) ) ) {
			$creates = 1;
		}
		return array(
			'table'   => $table,
			'creates' => $creates,
		);
	}

	private function run_statement( $sql ) {
		$sql = $this->strip_leading_comments( $sql );
		$sql = $this->rewrite_statement( $sql );
		$sql = $this->apply_shadow( $sql );
		if ( '' === $sql ) {
			return true;
		}
		if ( 0 === strpos( $sql, 'SET ' ) ) {
			$this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			// The dump sets utf8mb4. Quoted BINARY values would then be charset-converted
			// and truncated, so distinct keys can collapse. Keep the client binary.
			$this->use_binary_client();
			return true;
		}

		if ( $this->should_skip_statement( $sql ) ) {
			return true;
		}

		if ( $this->is_ddl( $sql ) ) {
			if ( ! $this->replace && $this->skip_existing_create( $sql ) ) {
				return true;
			}
			$this->rollback();
			$this->drop_existing_table( $sql );
			$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->txn_open = false;
			if ( false === $result && $this->replace && $this->is_table_exists_error() ) {
				$this->drop_existing_table( $sql );
				$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		} else {
			$this->stmt_source = $sql;
			$sql = $this->preserve_binary_literals( $sql );
			$this->clear_replace_table( $sql );
			$this->begin_transaction();
			$result = $this->execute_unfiltered( $sql );
			if ( false === $result && $this->is_duplicate_entry() && $this->is_insert( $sql ) ) {
				$mysql_error = (string) $this->wpdb->last_error;
				return $this->statement_error( $sql, $this->duplicate_diagnosis( $sql ), $mysql_error );
			}
		}
		if ( false !== $result && $this->after_statement ) {
			call_user_func( $this->after_statement, $sql );
		}
		if ( false === $result ) {
			$mysql_error = (string) $this->wpdb->last_error;
			return $this->statement_error( $sql, '', $mysql_error );
		}
		return true;
	}

	private function is_insert( $sql ) {
		return (bool) preg_match( '/^INSERT\s+INTO\s+`/i', ltrim( $sql ) );
	}

	private function is_table_exists_error() {
		$err = (string) $this->wpdb->last_error;
		return false !== stripos( $err, 'already exists' );
	}

	/**
	 * The duplicate-key statement was not committed. Describe the key from the SQL bytes
	 * and whether a HEX() lookup can see the row already stored by another statement.
	 *
	 * @param string $sql Insert statement.
	 * @return string
	 */
	private function duplicate_diagnosis( $sql ) {
		$rows = $this->parse_insert_rows( $sql );
		if ( ! $rows ) {
			return ' ' . __( 'This statement is not in the committed restore log. Its primary key could not be parsed from the SQL, so the row was not skipped. The database error above is the original duplicate-key error.', 'jisento' );
		}
		$mysql_error = (string) $this->wpdb->last_error;
		$found       = $this->match_rejected_tuple( $rows, $mysql_error );
		$parsed      = $rows[ $found['index'] ];
		$sent        = $this->pk_hex( $parsed );
		$first       = $this->pk_hex( $rows[0] );
		$rejected    = $found['hex'];
		$source      = $sent;
		if ( '' !== $this->stmt_source && $this->stmt_source !== $sql ) {
			$source_rows = $this->parse_insert_rows( $this->stmt_source );
			if ( isset( $source_rows[ $found['index'] ] ) ) {
				$source = $this->pk_hex( $source_rows[ $found['index'] ] );
			}
		}
		$raw = '';
		foreach ( $parsed['pk'] as $column ) {
			$raw .= isset( $parsed['values'][ $column ] ) && null !== $parsed['values'][ $column ] ? (string) $parsed['values'][ $column ] : '';
		}
		if ( '' !== $rejected && $rejected !== $sent && '' !== $found['bytes'] ) {
			$raw = $found['bytes'];
		}
		$places = $this->key_locations( $raw );
		$match  = $this->stored_row_match( $parsed );
		if ( '' !== $this->lookup_note ) {
			$lookup = $this->lookup_note;
		} elseif ( true === $match ) {
			$lookup = __( 'the destination row is already present and its bytes match this statement', 'jisento' );
		} elseif ( false === $match ) {
			$lookup = __( 'the destination row is already present and its bytes differ', 'jisento' );
		} else {
			$lookup = __( 'no destination row matched this key', 'jisento' );
		}
		$logged   = $this->was_applied( $this->stmt_at['offset'], $this->stmt_at['piece'] ) ? __( 'yes', 'jisento' ) : __( 'no', 'jisento' );
		$cleared  = isset( $this->cleared[ $parsed['table'] ] ) ? __( 'yes', 'jisento' ) : __( 'no', 'jisento' );
		$where    = $places['offsets'] ? implode( ', ', $places['offsets'] ) : __( 'none', 'jisento' );
		$in_sql = __( 'no', 'jisento' );
		if ( '' !== $rejected && ( false !== stripos( $sql, '0x' . $rejected ) || false !== stripos( $sql, "x'" . $rejected ) ) ) {
			$in_sql = __( 'yes', 'jisento' );
		}
		$tuple    = $found['matched']
			? sprintf( 'tuple %d of %d', $found['index'] + 1, count( $rows ) )
			: sprintf( 'no tuple of %d', count( $rows ) );
		$cause    = '';
		if ( $places['count'] > 1 ) {
			$cause = ' ' . __( 'The source SQL contains this primary key more than once, so an earlier statement in the package already inserted it.', 'jisento' );
		} elseif ( 1 === $places['count'] && true === $match && 'no' === $logged ) {
			$cause = ' ' . __( 'The source SQL contains this primary key once, and this statement was not committed before. An earlier statement in this restore stored the same key.', 'jisento' );
		} elseif ( 'no' === $in_sql && '' !== $rejected ) {
			$cause = ' ' . __( 'The key MariaDB reported is not in the SQL passed to the driver, so the bytes changed after the statement was parsed.', 'jisento' );
		} elseif ( 'no' === $cleared ) {
			$cause = ' ' . __( 'This table was not cleared during this restore, so the row may already have been on the destination.', 'jisento' );
		}
		return ' ' . sprintf(
			/* translators: 1: rejected hex, 2: byte length, 3: tuple description, 4: yes or no, 5: occurrence count, 6: offsets, 7: first tuple hex, 8: source hex, 9: same or different, 10: lookup, 11: yes or no, 12: yes or no, 13: triggers, 14: byte offset, 15: piece, 16: driver SQL length, 17: schema, 18: session */
			__( 'MariaDB rejected primary key hex: %1$s (%2$d bytes), %3$s. Rejected key inside the SQL passed to the driver: %4$s. Literal raw-byte occurrences in the source file: %5$d at byte offset(s) %6$s. First tuple key: %7$s. Source literal decoded hex: %8$s (%9$s). Destination before this statement committed: %10$s. This statement is in the committed restore log: %11$s. Table cleared during this restore: %12$s. Triggers on this table: %13$s. Resume position: byte %14$d, piece %15$d. Driver SQL length: %16$d bytes. Destination schema: %17$s. Connection: %18$s. A question mark in the statement preview is only how a non-printable byte is shown. The row was not skipped.', 'jisento' ),
			'' !== $rejected ? $rejected : $sent,
			(int) ( strlen( '' !== $rejected ? $rejected : $sent ) / 2 ),
			$tuple,
			$in_sql,
			(int) $places['count'],
			$where,
			$first,
			$source,
			$source === $sent ? __( 'same bytes', 'jisento' ) : __( 'different bytes', 'jisento' ),
			$lookup,
			$logged,
			$cleared,
			$this->trigger_names( $parsed['table'] ),
			(int) $this->stmt_at['offset'],
			(int) $this->stmt_at['piece'],
			strlen( $sql ),
			$this->table_definition( $parsed['table'] ),
			$this->session_note()
		) . $cause;
	}

	/**
	 * MariaDB's duplicate-entry value is the key that collided, which may be a later tuple.
	 *
	 * @param array  $rows  Parsed insert rows.
	 * @param string $error Driver error.
	 * @return array{index:int,matched:bool,hex:string,bytes:string}
	 */
	private function match_rejected_tuple( array $rows, $error ) {
		$chosen = array(
			'index'   => 0,
			'matched' => false,
			'hex'     => '',
			'bytes'   => '',
		);
		foreach ( $this->rejected_key_candidates( $error ) as $candidate ) {
			$hex = bin2hex( $candidate );
			foreach ( $rows as $index => $row ) {
				if ( $this->pk_hex( $row ) === $hex ) {
					return array(
						'index'   => $index,
						'matched' => true,
						'hex'     => $hex,
						'bytes'   => $candidate,
					);
				}
			}
			if ( '' === $chosen['hex'] ) {
				$chosen['hex']   = $hex;
				$chosen['bytes'] = $candidate;
			}
		}
		return $chosen;
	}

	/**
	 * @param string $error MariaDB or MySQL error text.
	 * @return string[] Candidate key byte strings.
	 */
	private function rejected_key_candidates( $error ) {
		if ( ! preg_match( "/Duplicate entry '(.*)' for key/s", (string) $error, $match ) ) {
			return array();
		}
		$value = str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $match[1] );
		$out   = array( $value );
		if ( false !== strpos( $value, '\\x' ) ) {
			$decoded = preg_replace_callback(
				'/\\\\x([0-9A-Fa-f]{2})/',
				static function ( $part ) {
					return chr( hexdec( $part[1] ) );
				},
				$value
			);
			if ( is_string( $decoded ) && $decoded !== $value ) {
				$out[] = $decoded;
			}
		}
		return $out;
	}

	/**
	 * Send the statement bytes without WordPress query filters or placeholder stripping.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	private function execute_unfiltered( $sql ) {
		$this->executed_sql = $sql;
		$dbh                = ( isset( $this->wpdb->dbh ) && $this->wpdb->dbh instanceof \mysqli ) ? $this->wpdb->dbh : null;
		if ( $dbh ) {
			if ( ! mysqli_real_query( $dbh, $sql ) ) {
				$this->wpdb->last_error = mysqli_error( $dbh );
				return false;
			}
			$this->wpdb->last_error = '';
			if ( mysqli_field_count( $dbh ) > 0 ) {
				$result = mysqli_store_result( $dbh );
				if ( $result instanceof \mysqli_result ) {
					mysqli_free_result( $result );
				}
			}
			return true;
		}
		return false !== $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A utf8mb4 client rewrites invalid sequences inside quoted BINARY values, then
	 * BINARY(n) truncates them. Hex literals are ASCII and are not converted.
	 */
	private function use_binary_client() {
		$this->wpdb->query( 'SET character_set_client = binary' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( 'SET character_set_connection = binary' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->last_error = '';
	}

	private function restore_connection_charset() {
		if ( isset( $this->wpdb->dbh ) && is_object( $this->wpdb->dbh ) && method_exists( $this->wpdb, 'set_charset' ) ) {
			$this->wpdb->set_charset( $this->wpdb->dbh );
		}
	}

	private function pk_hex( array $parsed ) {
		$bytes = '';
		foreach ( $parsed['pk'] as $column ) {
			$bytes .= isset( $parsed['values'][ $column ] ) ? (string) $parsed['values'][ $column ] : '';
		}
		return bin2hex( $bytes );
	}

	private function statement_error( $sql, $why, $mysql_error = null ) {
		if ( null === $mysql_error || '' === $mysql_error ) {
			$mysql_error = (string) $this->wpdb->last_error;
		}
		$this->rollback();
		$this->restore_connection_charset();
		return new \WP_Error(
			'jisento_sql_error',
			sprintf(
				/* translators: 1: database error, 2: table, 3: statement number, 4: statement preview, 5: extra reason */
				__( 'Unable to run a database statement: %1$s Table: %2$s. Statement number: %3$d. Statement: %4$s.%5$s', 'jisento' ),
				$mysql_error,
				$this->statement_table( $sql ),
				$this->statement_no + 1,
				$this->statement_preview( $sql ),
				$why
			) . $this->job_suffix()
		);
	}

	private function parse_insert_rows( $sql ) {
		if ( ! preg_match( '/^INSERT\s+INTO\s+`([^`]+)`\s*\((.*)\)\s*VALUES\s*/is', ltrim( $sql ), $match ) ) {
			return array();
		}
		$table   = $match[1];
		$columns = array();
		foreach ( explode( ',', $match[2] ) as $column ) {
			$columns[] = trim( $column, " `\t\n\r" );
		}
		$body = trim( substr( ltrim( $sql ), strlen( $match[0] ) ) );
		if ( ';' === substr( $body, -1 ) ) {
			$body = substr( $body, 0, -1 );
		}
		$pk = $this->primary_key_columns( $table );
		if ( ! $pk ) {
			$pk = array( $columns[0] );
		}
		$rows = array();
		foreach ( Sql_Scanner::value_tuples( $body ) as $tuple ) {
			$fields = Sql_Scanner::tuple_fields( $tuple );
			if ( count( $fields ) !== count( $columns ) ) {
				continue;
			}
			$values = array();
			foreach ( $columns as $index => $column ) {
				$values[ $column ] = $this->decode_sql_literal( $fields[ $index ] );
			}
			$rows[] = array(
				'table'   => $table,
				'columns' => $columns,
				'values'  => $values,
				'pk'      => $pk,
			);
		}
		return $rows;
	}

	private function decode_sql_literal( $token ) {
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
				$map  = array(
					'0'  => "\0",
					'b'  => "\x08",
					'n'  => "\n",
					'r'  => "\r",
					't'  => "\t",
					'Z'  => chr( 26 ),
					'\\' => '\\',
					"'"  => "'",
					'"'  => '"',
				);
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

	/**
	 * Quoted binary values are charset-converted by MariaDB when the connection is utf8mb4.
	 * A BINARY(16) column then stores a different 16-byte key than the dump contains, so later
	 * rows collide. Hex literals are ASCII and are stored as the exact bytes.
	 *
	 * @param string $sql Statement about to run.
	 * @return string
	 */
	private function preserve_binary_literals( $sql ) {
		if ( ! preg_match( '/^(INSERT\s+INTO\s+`([^`]+)`\s*\((.*)\)\s*VALUES\s*)(.*)$/is', ltrim( $sql ), $match ) ) {
			return $sql;
		}
		$binary = $this->binary_columns_for( $match[2] );
		if ( ! $binary ) {
			return $sql;
		}
		$columns = array();
		foreach ( explode( ',', $match[3] ) as $column ) {
			$columns[] = trim( $column, " `\t\n\r" );
		}
		$indexes = array();
		foreach ( $columns as $index => $column ) {
			if ( ! empty( $binary[ $column ] ) ) {
				$indexes[ $index ] = true;
			}
		}
		if ( ! $indexes ) {
			return $sql;
		}
		$body   = $match[4];
		$suffix = '';
		if ( ';' === substr( rtrim( $body ), -1 ) ) {
			$body   = rtrim( $body );
			$body   = substr( $body, 0, -1 );
			$suffix = ';';
		}
		$tuples = Sql_Scanner::value_tuples( $body );
		if ( ! $tuples ) {
			return $sql;
		}
		$rebuilt = array();
		foreach ( $tuples as $tuple ) {
			$fields = Sql_Scanner::tuple_fields( $tuple );
			if ( count( $fields ) !== count( $columns ) ) {
				return $sql;
			}
			foreach ( array_keys( $indexes ) as $index ) {
				$fields[ $index ] = $this->binary_token( $fields[ $index ] );
			}
			$rebuilt[] = '(' . implode( ',', $fields ) . ')';
		}
		return $match[1] . implode( ',', $rebuilt ) . $suffix;
	}

	private function binary_token( $token ) {
		$token = trim( (string) $token );
		if ( preg_match( '/^0x[0-9a-fA-F]+$/i', $token ) ) {
			return $token;
		}
		if ( preg_match( "/^X'[0-9a-fA-F]*'$/i", $token ) ) {
			return $token;
		}
		if ( 0 === strcasecmp( $token, 'NULL' ) ) {
			return 'NULL';
		}
		$decoded = $this->decode_sql_literal( $token );
		if ( null === $decoded ) {
			return 'NULL';
		}
		$decoded = (string) $decoded;
		if ( '' === $decoded ) {
			return "X''";
		}
		return '0x' . bin2hex( $decoded );
	}

	private function binary_columns_for( $table ) {
		if ( isset( $this->binary_columns[ $table ] ) ) {
			return $this->binary_columns[ $table ];
		}
		$rows = $this->wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $this->esc( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || ! $rows ) {
			$rows = $this->wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->esc( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! is_array( $rows ) || ! $rows ) {
			return array();
		}
		$map = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['Field'] ) && self::is_binary_column( $row ) ) {
				$map[ $row['Field'] ] = true;
			}
		}
		$this->binary_columns[ $table ] = $map;
		return $map;
	}

	/**
	 * @param array $row SHOW FULL COLUMNS row.
	 * @return bool
	 */
	private static function is_binary_column( array $row ) {
		$type = isset( $row['Type'] ) ? strtolower( (string) $row['Type'] ) : '';
		if ( '' !== $type && preg_match( '/blob|binary|geometry|(^|[^a-z])bit\b/', $type ) ) {
			return true;
		}
		$collation = isset( $row['Collation'] ) ? strtolower( (string) $row['Collation'] ) : '';
		return 'binary' === $collation;
	}

	private function table_definition( $table ) {
		$saved = (string) $this->wpdb->last_error;
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( 'SHOW CREATE TABLE `' . $this->esc( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->last_error = $saved;
		if ( ! is_array( $row ) ) {
			return 'unavailable';
		}
		foreach ( array( 'Create Table', 'Create View' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
		}
		return 'unavailable';
	}

	private function session_note() {
		$saved = (string) $this->wpdb->last_error;
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( 'SELECT @@version AS version, @@character_set_client AS client_charset, @@character_set_connection AS connection_charset, @@sql_mode AS sql_mode', ARRAY_A );
		$this->wpdb->last_error = $saved;
		if ( ! is_array( $row ) ) {
			return 'unavailable';
		}
		return sprintf(
			'version %s, client charset %s, connection charset %s, sql_mode %s',
			isset( $row['version'] ) ? $row['version'] : '',
			isset( $row['client_charset'] ) ? $row['client_charset'] : '',
			isset( $row['connection_charset'] ) ? $row['connection_charset'] : '',
			isset( $row['sql_mode'] ) ? $row['sql_mode'] : ''
		);
	}

	private function trigger_names( $table ) {
		$rows = $this->wpdb->get_results( 'SHOW TRIGGERS LIKE \'' . str_replace( "'", "''", $table ) . '\'', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || ! $rows ) {
			return __( 'none', 'jisento' );
		}
		$names = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['Trigger'] ) ) {
				$names[] = $row['Trigger'];
			}
		}
		return $names ? implode( ', ', $names ) : __( 'none', 'jisento' );
	}

	/**
	 * @param string $raw Decoded bytes.
	 * @return string MySQL backslash-escaped form, without surrounding quotes.
	 */
	private static function mysql_escaped_bytes( $raw ) {
		$out = '';
		$len = strlen( (string) $raw );
		$map = array(
			"\0"   => '\\0',
			"\n"   => '\\n',
			"\r"   => '\\r',
			"\x1a" => '\\Z',
			'\\'   => '\\\\',
			"'"    => "\\'",
		);
		for ( $i = 0; $i < $len; $i++ ) {
			$ch   = $raw[ $i ];
			$out .= isset( $map[ $ch ] ) ? $map[ $ch ] : $ch;
		}
		return $out;
	}

	/**
	 * @param string $raw Primary-key bytes parsed from the statement.
	 * @return array{count:int,offsets:int[]}
	 */
	private function key_locations( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || '' === $this->sql_path || ! is_file( $this->sql_path ) ) {
			return array( 'count' => 0, 'offsets' => array() );
		}
		$hex     = bin2hex( $raw );
		$needles = array( $raw );
		if ( '' !== $hex ) {
			$needles[] = '0x' . $hex;
			$needles[] = '0x' . strtoupper( $hex );
		}
		$escaped = self::mysql_escaped_bytes( $raw );
		if ( $escaped !== $raw ) {
			$needles[] = $escaped;
		}
		$max     = 1;
		foreach ( $needles as $needle ) {
			$max = max( $max, strlen( $needle ) );
		}
		$handle  = fopen( $this->sql_path, 'rb' );
		if ( ! $handle ) {
			return array( 'count' => 0, 'offsets' => array() );
		}
		$window  = '';
		$base    = 0;
		$count   = 0;
		$offsets = array();
		$keep    = $max - 1;
		$scan    = static function ( $window, $base, $limit ) use ( $needles, &$count, &$offsets ) {
			foreach ( $needles as $needle ) {
				$from = 0;
				$len  = strlen( $needle );
				while ( ( $found = strpos( $window, $needle, $from ) ) !== false ) {
					if ( $found >= $limit ) {
						break;
					}
					$count++;
					if ( count( $offsets ) < 6 ) {
						$offsets[] = $base + $found;
					}
					$from = $found + $len;
				}
			}
		};
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1048576 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$window .= $chunk;
			if ( strlen( $window ) <= $keep ) {
				continue;
			}
			$limit = strlen( $window ) - $keep;
			$scan( $window, $base, $limit );
			$window = substr( $window, $limit );
			$base  += $limit;
		}
		fclose( $handle );
		if ( '' !== $window ) {
			$scan( $window, $base, strlen( $window ) );
		}
		return array(
			'count'   => $count,
			'offsets' => $offsets,
		);
	}

	private function primary_key_columns( $table ) {
		if ( isset( $this->pk_cache[ $table ] ) ) {
			return $this->pk_cache[ $table ];
		}
		$rows = $this->wpdb->get_results( 'SHOW KEYS FROM `' . $this->esc( $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$columns = array();
		if ( is_array( $rows ) ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
				}
			);
			foreach ( $rows as $row ) {
				if ( ! empty( $row['Column_name'] ) ) {
					$columns[] = $row['Column_name'];
				}
			}
		}
		$this->pk_cache[ $table ] = $columns;
		return $columns;
	}

	/**
	 * Compare through HEX() so binary primary keys are not fetched as raw bytes.
	 *
	 * @param array $parsed Parsed insert row.
	 * @return bool|null True when the stored row matches, false when it differs, null when it cannot be read.
	 */
	private function stored_row_match( array $parsed ) {
		$this->lookup_note = '';
		$select = array();
		$where  = array();
		$args   = array();
		foreach ( $parsed['columns'] as $column ) {
			$select[] = 'HEX(`' . $this->esc( $column ) . '`) AS `' . $this->esc( $column ) . '`';
		}
		foreach ( $parsed['pk'] as $column ) {
			if ( ! array_key_exists( $column, $parsed['values'] ) || null === $parsed['values'][ $column ] ) {
				$where[] = '`' . $this->esc( $column ) . '` IS NULL';
				continue;
			}
			$where[] = 'HEX(`' . $this->esc( $column ) . '`) = %s';
			$args[]  = strtoupper( bin2hex( (string) $parsed['values'][ $column ] ) );
		}
		if ( ! $where ) {
			return null;
		}
		$sql = 'SELECT ' . implode( ', ', $select ) . ' FROM `' . $this->esc( $parsed['table'] ) . '` WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1';
		$sql = $this->prepare_sql( $sql, $args );
		$saved = (string) $this->wpdb->last_error;
		$this->wpdb->last_error = '';
		$row   = $this->wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$lookup_error = (string) $this->wpdb->last_error;
		$this->wpdb->last_error = $saved;
		if ( '' !== $lookup_error ) {
			$this->lookup_note = sprintf(
				/* translators: 1: lookup SQL, 2: database error from that lookup */
				__( 'The lookup query failed and was not the statement MariaDB rejected. Lookup SQL: %1$s Lookup error: %2$s', 'jisento' ),
				$sql,
				$lookup_error
			);
			return null;
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( $parsed['columns'] as $column ) {
			$expected = null === $parsed['values'][ $column ] ? null : strtoupper( bin2hex( (string) $parsed['values'][ $column ] ) );
			$actual   = array_key_exists( $column, $row ) ? $row[ $column ] : null;
			if ( null === $expected || null === $actual || '' === $actual ) {
				if ( $expected !== $actual && ! ( null === $expected && ( null === $actual || '' === $actual ) ) ) {
					return false;
				}
				continue;
			}
			if ( strtoupper( (string) $actual ) !== $expected ) {
				return false;
			}
		}
		return true;
	}

	private function skip_existing_create( $sql ) {
		if ( ! preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', ltrim( $sql ), $match ) ) {
			return false;
		}
		return $this->table_exists( $match[1] );
	}

	private function table_exists( $table ) {
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $table === $found;
	}

	private function is_duplicate_entry() {
		return false !== stripos( (string) $this->wpdb->last_error, 'Duplicate entry' );
	}

	private function is_ddl( $sql ) {
		return (bool) preg_match( '/^(DROP|CREATE|ALTER|RENAME|TRUNCATE)\s/i', ltrim( $sql ) );
	}

	private function statement_table( $sql ) {
		$seen = $this->statement_progress( $sql );
		return '' !== $seen['table'] ? $seen['table'] : __( 'unknown', 'jisento' );
	}

	private function statement_preview( $sql ) {
		$preview = preg_replace( '/\s+/', ' ', substr( (string) $sql, 0, 220 ) );
		$preview = preg_replace( '/[^\x20-\x7E]/', '?', (string) $preview );
		return $preview;
	}

	/**
	 * Replace mode must not insert source rows into a table that still has destination rows.
	 * A package can also repeat a primary key when its export read the table without a stable order.
	 *
	 * @param string $sql Insert statement.
	 */
	private function clear_replace_table( $sql ) {
		if ( ! $this->replace || ! preg_match( '/^INSERT\s+INTO\s+`([^`]+)`/i', ltrim( $sql ), $match ) ) {
			return;
		}
		$table = $match[1];
		if ( '' === $table || isset( $this->cleared[ $table ] ) || $this->should_skip_statement( '`' . $table . '`' ) ) {
			return;
		}
		$this->wpdb->query( 'TRUNCATE TABLE `' . $this->esc( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->cleared[ $table ] = true;
		if ( '' !== $this->sql_path ) {
			file_put_contents( $this->sql_path . '.cleared', implode( "\n", $this->cleared_tables() ) );
		}
	}

	/**
	 * Comment lines in the dump are not terminated, so they were glued to the
	 * following DROP TABLE and the whole statement was skipped.
	 *
	 * @param string $sql Raw statement buffer.
	 * @return string
	 */
	private function strip_leading_comments( $sql ) {
		$sql = ltrim( (string) $sql );
		while ( '' !== $sql ) {
			if ( 0 === strpos( $sql, '--' ) || 0 === strpos( $sql, '#' ) ) {
				$break = strpos( $sql, "\n" );
				if ( false === $break ) {
					return '';
				}
				$sql = ltrim( substr( $sql, $break + 1 ) );
				continue;
			}
			if ( 0 === strpos( $sql, '/*' ) ) {
				$end = strpos( $sql, '*/' );
				if ( false === $end ) {
					return '';
				}
				$sql = ltrim( substr( $sql, $end + 2 ) );
				continue;
			}
			break;
		}
		return $sql;
	}

	/**
	 * Replace mode reloads tables that already exist on the destination.
	 *
	 * @param string $sql Statement about to run.
	 */
	private function drop_existing_table( $sql ) {
		if ( ! $this->replace ) {
			return;
		}
		if ( preg_match( '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM=\w+\s+)?(?:DEFINER=\S+\s+)?VIEW\s+`([^`]+)`/i', $sql, $view ) ) {
			$this->wpdb->query( 'DROP VIEW IF EXISTS `' . $this->esc( $view[1] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return;
		}
		if ( ! preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $match ) ) {
			return;
		}
		$table = str_replace( '`', '', $match[1] );
		if ( '' === $table || $this->should_skip_statement( '`' . $table . '`' ) ) {
			return;
		}
		$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function should_skip_statement( $sql ) {
		if ( empty( $this->skip_tables ) ) {
			return false;
		}
		foreach ( $this->skip_tables as $table ) {
			if ( false !== strpos( $sql, '`' . $table . '`' ) ) {
				return true;
			}
		}
		return false;
	}

	public function rewrite_statement( $sql ) {
		if ( $this->source_prefix === $this->dest_prefix || '' === $this->source_prefix ) {
			return $sql;
		}

		$from = $this->source_prefix;
		$to   = $this->dest_prefix;
		$len  = strlen( $sql );
		$out  = '';
		$in   = false;
		$esc  = false;
		$quote = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sql[ $i ];
			if ( $in ) {
				$out .= $ch;
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $quote ) {
					$in = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				$out  .= $ch;
				continue;
			}
			if ( '`' === $ch ) {
				$end = strpos( $sql, '`', $i + 1 );
				if ( false === $end ) {
					$out .= substr( $sql, $i );
					break;
				}
				$name = substr( $sql, $i + 1, $end - $i - 1 );
				if ( 0 === strpos( $name, $from ) ) {
					$name = $to . substr( $name, strlen( $from ) );
				}
				$out .= '`' . $name . '`';
				$i    = $end;
				continue;
			}
			$out .= $ch;
		}
		return $out;
	}

	public function rewrite_prefix_in_data() {
		$tables = array(
			$this->dest_prefix . 'options'  => array( 'option_name' ),
			$this->dest_prefix . 'usermeta' => array( 'meta_key' ),
		);

		foreach ( $tables as $table => $columns ) {
			$exists = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $table !== $exists ) {
				continue;
			}
			foreach ( $columns as $column ) {
				$this->wpdb->query(
					$this->wpdb->prepare(
						"UPDATE `{$this->esc( $table )}` SET `{$this->esc( $column )}` = REPLACE(`{$this->esc( $column )}`, %s, %s) WHERE `{$this->esc( $column )}` LIKE %s",
						$this->source_prefix,
						$this->dest_prefix,
						$this->wpdb->esc_like( $this->source_prefix ) . '%'
					)
				);
			}
		}
	}

	private function esc( $ident ) {
		return str_replace( '`', '', $ident );
	}

	/**
	 * A shadow name stays a legal identifier and never replaces the live table in place.
	 *
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function shadow_name( $table ) {
		$table  = str_replace( '`', '', (string) $table );
		$suffix = '__js';
		if ( strlen( $table . $suffix ) <= 64 ) {
			return $table . $suffix;
		}
		$hash = substr( md5( $table ), 0, 10 );
		$keep = 64 - 1 - strlen( $hash );
		return substr( $table, 0, max( 1, $keep ) ) . '_' . $hash;
	}

	/**
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function retired_name( $table ) {
		$table  = str_replace( '`', '', (string) $table );
		$suffix = '__jo';
		if ( strlen( $table . $suffix ) <= 64 ) {
			return $table . $suffix;
		}
		$hash = substr( md5( 'old:' . $table ), 0, 10 );
		$keep = 64 - 1 - strlen( $hash );
		return substr( $table, 0, max( 1, $keep ) ) . '_' . $hash;
	}

	/**
	 * Rewrite only the statement's target table. Column names, quoted values, and
	 * REFERENCES targets stay on the live names so the swap leaves foreign keys valid.
	 *
	 * @param string $sql Statement.
	 * @return string
	 */
	public static function shadow_statement( $sql ) {
		if ( ! preg_match( '/^(\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|UPDATE|DELETE\s+FROM)\s+)`([^`]+)`/i', $sql, $match ) ) {
			return $sql;
		}
		$live = $match[2];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $live ) || substr( $live, -4 ) === '__js' ) {
			return $sql;
		}
		$shadow = self::shadow_name( $live );
		$token  = '`' . $live . '`';
		$pos    = strpos( $sql, $token );
		if ( false === $pos ) {
			return $sql;
		}
		$sql = substr( $sql, 0, $pos ) . '`' . $shadow . '`' . substr( $sql, $pos + strlen( $token ) );
		if ( preg_match( '/^\s*CREATE\s+TABLE/i', $sql ) ) {
			$sql = preg_replace_callback(
				'/(\bREFERENCES\s+)`([A-Za-z0-9_]+)`/i',
				function ( $reference ) {
					return $reference[1] . '`' . self::shadow_name( $reference[2] ) . '`';
				},
				$sql
			);
		}
		return $sql;
	}

	/**
	 * One RENAME so a crash cannot leave the live name pointing at neither table.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $triples Live, shadow, retired.
	 * @return string
	 */
	public static function rename_swap_sql( array $triples ) {
		$parts = array();
		foreach ( $triples as $row ) {
			$parts[] = '`' . $row[0] . '` TO `' . $row[2] . '`';
			$parts[] = '`' . $row[1] . '` TO `' . $row[0] . '`';
		}
		return 'RENAME TABLE ' . implode( ', ', $parts );
	}

	/**
	 * @param string $sql Statement already prefix-rewritten.
	 * @return string
	 */
	private function apply_shadow( $sql ) {
		if ( ! $this->shadow ) {
			return $sql;
		}
		if ( ! preg_match( '/^(\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|UPDATE|DELETE\s+FROM)\s+)`([^`]+)`/i', $sql, $match ) ) {
			return $sql;
		}
		$live = $match[2];
		$next = self::shadow_statement( $sql );
		if ( $next === $sql || ! preg_match( '/^[A-Za-z0-9_]+$/', $live ) ) {
			return $sql;
		}
		$this->shadow_map[ $live ] = self::shadow_name( $live );
		$this->persist_shadow_map();
		return $next;
	}

	private function persist_shadow_map() {
		if ( '' === $this->sql_path ) {
			return;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $this->shadow_map ) : json_encode( $this->shadow_map );
		if ( is_string( $encoded ) ) {
			file_put_contents( $this->sql_path . '.shadow', $encoded, LOCK_EX );
		}
	}

	private function load_shadow_map() {
		$this->shadow_map = array();
		if ( '' === $this->sql_path || ! is_readable( $this->sql_path . '.shadow' ) ) {
			return;
		}
		$data = json_decode( (string) file_get_contents( $this->sql_path . '.shadow' ), true );
		if ( ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $live => $shadow ) {
			if ( is_string( $live ) && is_string( $shadow ) && preg_match( '/^[A-Za-z0-9_]+$/', $live ) && preg_match( '/^[A-Za-z0-9_]+$/', $shadow ) ) {
				$this->shadow_map[ $live ] = $shadow;
			}
		}
	}

	/**
	 * The source users table did not load. Swapping now would replace a live administrator with nothing.
	 *
	 * @return bool
	 */
	public function shadow_users_empty() {
		$live = $this->dest_prefix . 'users';
		if ( empty( $this->shadow_map[ $live ] ) || ! $this->table_exists( $this->shadow_map[ $live ] ) ) {
			return false;
		}
		$count = $this->wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->esc( $this->shadow_map[ $live ] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return null !== $count && (int) $count < 1;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function swap_shadows() {
		$this->load_shadow_map();
		$triples = array();
		$created = array();
		foreach ( $this->shadow_map as $live => $shadow ) {
			if ( ! $this->table_exists( $shadow ) ) {
				continue;
			}
			$retired = self::retired_name( $live );
			if ( $retired === $live || $retired === $shadow ) {
				return new \WP_Error( 'jisento_shadow', __( 'A shadow table name collided with a live table, so the live tables were not replaced.', 'jisento' ) );
			}
			if ( $this->table_exists( $retired ) ) {
				$this->wpdb->query( 'DROP TABLE `' . $this->esc( $retired ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			if ( $this->table_exists( $live ) ) {
				$triples[] = array( $live, $shadow, $retired );
			} else {
				$created[] = array( $shadow, $live );
			}
		}
		if ( $triples ) {
			$result = $this->wpdb->query( self::rename_swap_sql( $triples ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				return new \WP_Error( 'jisento_shadow', __( 'The restored tables could not be swapped into place. The live tables were left unchanged.', 'jisento' ) . ' ' . $this->wpdb->last_error );
			}
			$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
			foreach ( $triples as $row ) {
				$this->wpdb->query( 'DROP TABLE `' . $this->esc( $row[2] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			$this->wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		}
		foreach ( $created as $row ) {
			$result = $this->wpdb->query( 'RENAME TABLE `' . $this->esc( $row[0] ) . '` TO `' . $this->esc( $row[1] ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				return new \WP_Error( 'jisento_shadow', __( 'A new restored table could not be renamed into place.', 'jisento' ) . ' ' . $this->wpdb->last_error );
			}
		}
		if ( '' !== $this->sql_path && is_file( $this->sql_path . '.shadow' ) ) {
			@unlink( $this->sql_path . '.shadow' );
		}
		$this->shadow_map = array();
		return true;
	}

	/**
	 * Drop shadow tables for a cancelled import. Live tables are not touched.
	 *
	 * @param string $sql_path Dump path.
	 */
	public static function drop_shadows( $sql_path ) {
		global $wpdb;
		$path = (string) $sql_path . '.shadow';
		if ( ! is_readable( $path ) ) {
			return;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $data ) ) {
			foreach ( $data as $live => $shadow ) {
				if ( ! is_string( $shadow ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $shadow ) ) {
					continue;
				}
				if ( is_string( $live ) && self::shadow_name( $live ) !== $shadow ) {
					continue;
				}
				$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $shadow ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		@unlink( $path );
	}
}
