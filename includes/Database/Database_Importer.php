<?php
/**
 * Streaming SQL restore into shadow tables.
 *
 * Every table from the package is restored into "<table>__js". Live tables are only
 * touched by swap_shadows(), which renames all restored tables in one RENAME TABLE.
 * A statement, its ledger row and the resume cursor commit in one transaction.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Importer {

	const SHADOW_SUFFIX  = '__js';
	const RETIRED_SUFFIX = '__jo';

	/**
	 * Tables whose row values must never appear in an error message or log.
	 */
	const SENSITIVE_TABLE_PATTERN = '/(^|_)(users|usermeta|sessions|woocommerce_sessions|woocommerce_api_keys|wc_webhooks|application_passwords)$/i';

	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @var \mysqli
	 */
	private $dbh;

	/**
	 * @var string
	 */
	private $source_prefix;

	/**
	 * @var string
	 */
	private $dest_prefix;

	/**
	 * Destination table name => true for tables restored from the package.
	 *
	 * @var array<string,bool>|null Null restores every table.
	 */
	private $restore = null;

	/**
	 * Destination table name => true for package tables that stay as they are on the destination.
	 *
	 * @var array<string,bool>
	 */
	private $keep = array();

	/**
	 * @var string
	 */
	private $job_id = 'import';

	/**
	 * v1 packages quoted BINARY values and may contain wpdb placeholder tokens.
	 *
	 * @var bool
	 */
	private $legacy = false;

	/**
	 * Placeholder tokens to turn back into "%" (explicit opt-in only).
	 *
	 * @var string[]
	 */
	private $placeholder_tokens = array();

	/**
	 * Session variables the dump asked for, replayed at the start of each request.
	 *
	 * @var array<string,string>
	 */
	private $session = array();

	/**
	 * @var array<string,string>
	 */
	private $saved_session = array();

	/**
	 * @var string[]|null
	 */
	private $charsets = null;

	/**
	 * @var bool|null
	 */
	private $mariadb = null;

	/**
	 * @var array Notes collected during the chunk: engine conversions, collation mappings, constraint renames.
	 */
	private $notes = array();

	/**
	 * @var int
	 */
	private $statement_no = 0;

	/**
	 * @var int
	 */
	private $placeholders_repaired = 0;

	/**
	 * @var array<string,array<string,bool>>
	 */
	private $binary_columns = array();

	/**
	 * @var callable|null Test hook: called with a point name. It may throw to simulate a killed worker.
	 */
	private $fault = null;

	/**
	 * @var bool
	 */
	private $tables_ready = false;

	/**
	 * @var string
	 */
	private $last_errno = '0';

	/**
	 * @var string
	 */
	private $last_error = '';

	/**
	 * @var Sql_Escaper
	 */
	private $escaper;

	/**
	 * @param string $source_prefix Prefix in the package.
	 * @param string $dest_prefix   Prefix on this site.
	 * @param array  $options       job_id, legacy, placeholder_tokens, restore (list), keep (list), session (array).
	 */
	public function __construct( $source_prefix, $dest_prefix, array $options = array() ) {
		global $wpdb;
		$this->wpdb          = $wpdb;
		$this->dbh           = ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) ? $wpdb->dbh : null;
		$this->source_prefix = (string) $source_prefix;
		$this->dest_prefix   = (string) $dest_prefix;
		$this->escaper       = new Sql_Escaper( null );
		if ( ! empty( $options['job_id'] ) ) {
			$this->job_id = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $options['job_id'] );
		}
		$this->legacy = ! empty( $options['legacy'] );
		if ( ! empty( $options['placeholder_tokens'] ) && is_array( $options['placeholder_tokens'] ) ) {
			foreach ( $options['placeholder_tokens'] as $token ) {
				if ( is_string( $token ) && preg_match( '/^\{[a-f0-9]{64}\}$/', $token ) ) {
					$this->placeholder_tokens[] = $token;
				}
			}
		}
		if ( isset( $options['restore'] ) && is_array( $options['restore'] ) ) {
			$this->restore = array();
			foreach ( $options['restore'] as $table ) {
				$this->restore[ (string) $table ] = true;
			}
		}
		if ( isset( $options['keep'] ) && is_array( $options['keep'] ) ) {
			foreach ( $options['keep'] as $table ) {
				$this->keep[ (string) $table ] = true;
			}
		}
		if ( isset( $options['session'] ) && is_array( $options['session'] ) ) {
			$this->session = $options['session'];
		}
		if ( isset( $options['statement_no'] ) ) {
			$this->statement_no = (int) $options['statement_no'];
		}
	}

	public function set_fault_hook( $callback ) {
		$this->fault = is_callable( $callback ) ? $callback : null;
	}

	public function session() {
		return $this->session;
	}

	public function statement_no() {
		return (int) $this->statement_no;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function assert_driver() {
		if ( ! $this->dbh ) {
			return new \WP_Error( 'jisento_driver', __( 'The database restore needs the mysqli driver, but $wpdb is not using a mysqli connection (a db.php drop-in may replace it).', 'jisento' ) . $this->job_suffix() );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Names
	 * ------------------------------------------------------------------ */

	/**
	 * A shadow name stays a legal identifier and never replaces the live table in place.
	 *
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function shadow_name( $table ) {
		return self::suffixed( $table, self::SHADOW_SUFFIX, '' );
	}

	/**
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function retired_name( $table ) {
		return self::suffixed( $table, self::RETIRED_SUFFIX, 'old:' );
	}

	/**
	 * InnoDB constraint names are unique per database, so the shadow copy needs its own.
	 *
	 * @param string $name Constraint name.
	 * @return string
	 */
	public static function shadow_constraint_name( $name ) {
		return self::suffixed( $name, self::SHADOW_SUFFIX, 'fk:' );
	}

	private static function suffixed( $name, $suffix, $salt ) {
		$name = str_replace( '`', '', (string) $name );
		if ( strlen( $name . $suffix ) <= 64 ) {
			return $name . $suffix;
		}
		$hash = substr( md5( $salt . $name ), 0, 10 );
		$keep = 64 - strlen( $suffix ) - 1 - strlen( $hash );
		return substr( $name, 0, max( 1, $keep ) ) . '_' . $hash . $suffix;
	}

	/**
	 * Rename a package table name from the source prefix to the destination prefix.
	 *
	 * @param string $name Table name from the dump.
	 * @return string
	 */
	public function dest_table( $name ) {
		$name = (string) $name;
		if ( '' !== $this->source_prefix && $this->source_prefix !== $this->dest_prefix && 0 === strpos( $name, $this->source_prefix ) ) {
			return $this->dest_prefix . substr( $name, strlen( $this->source_prefix ) );
		}
		return $name;
	}

	/**
	 * @param string $table Destination table name.
	 * @return bool
	 */
	public function will_restore( $table ) {
		if ( isset( $this->keep[ $table ] ) ) {
			return false;
		}
		if ( null === $this->restore ) {
			return true;
		}
		return isset( $this->restore[ $table ] );
	}

	/* ------------------------------------------------------------------
	 * Ledger and cursor
	 * ------------------------------------------------------------------ */

	public static function cursor_table() {
		global $wpdb;
		return $wpdb->prefix . 'jisento_import_cursor';
	}

	public static function applied_table() {
		global $wpdb;
		return $wpdb->prefix . 'jisento_import_applied';
	}

	/**
	 * Tables from 1.2.x have no segment column. Their rows only belong to jobs that this
	 * version cannot resume, so they are recreated with the new layout.
	 *
	 * @return true|\WP_Error
	 */
	public function ensure_tables() {
		if ( $this->tables_ready ) {
			return true;
		}
		$cursor  = self::cursor_table();
		$applied = self::applied_table();
		foreach ( array( $cursor, $applied ) as $table ) {
			$cols = $this->rows( 'SHOW COLUMNS FROM `' . $table . '`' );
			if ( is_array( $cols ) && $cols ) {
				$names = array();
				foreach ( $cols as $col ) {
					$names[] = $col['Field'];
				}
				if ( ! in_array( 'segment', $names, true ) && ! $this->exec_sql( 'DROP TABLE `' . $table . '`' ) ) {
					return $this->driver_error( 'prepare restore log', $table );
				}
			}
		}
		$ok = $this->exec_sql(
			'CREATE TABLE IF NOT EXISTS `' . $cursor . '` (
				job_id varchar(64) NOT NULL,
				segment int(10) unsigned NOT NULL DEFAULT 0,
				byte_offset bigint(20) unsigned NOT NULL DEFAULT 0,
				piece_index int(10) unsigned NOT NULL DEFAULT 0,
				statement_no int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (job_id)
			) ENGINE=InnoDB'
		) && $this->exec_sql(
			'CREATE TABLE IF NOT EXISTS `' . $applied . '` (
				job_id varchar(64) NOT NULL,
				segment int(10) unsigned NOT NULL,
				byte_offset bigint(20) unsigned NOT NULL,
				piece_index int(10) unsigned NOT NULL,
				statement_no int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (job_id, segment, byte_offset, piece_index)
			) ENGINE=InnoDB'
		);
		if ( ! $ok ) {
			return $this->driver_error( 'prepare restore log', $cursor );
		}
		$this->tables_ready = true;
		return true;
	}

	/**
	 * The committed resume point. This row is the only source of truth for where the restore is.
	 *
	 * @return array{segment:int,offset:int,piece_index:int,statement_no:int}|null
	 */
	public function read_cursor() {
		$ready = $this->ensure_tables();
		if ( is_wp_error( $ready ) ) {
			return null;
		}
		$rows = $this->rows( 'SELECT segment, byte_offset, piece_index, statement_no FROM `' . self::cursor_table() . "` WHERE job_id = '" . $this->job_id . "'" );
		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}
		return array(
			'segment'      => (int) $rows[0]['segment'],
			'offset'       => (int) $rows[0]['byte_offset'],
			'piece_index'  => (int) $rows[0]['piece_index'],
			'statement_no' => (int) $rows[0]['statement_no'],
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public function start_segment( $segment ) {
		$ready = $this->ensure_tables();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! $this->write_cursor( (int) $segment, 0, 0 ) ) {
			return $this->driver_error( 'save resume point', self::cursor_table() );
		}
		return true;
	}

	/**
	 * Remove this job's ledger and cursor rows. Called when a job finishes, fails or is cancelled.
	 *
	 * @param string $job_id Job id.
	 */
	public static function forget_job( $job_id ) {
		global $wpdb;
		$job_id = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $job_id );
		if ( '' === $job_id || ! isset( $wpdb ) ) {
			return;
		}
		foreach ( array( self::cursor_table(), self::applied_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found === $table ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE job_id = %s', $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	private function write_cursor( $segment, $offset, $piece ) {
		return $this->exec_sql(
			sprintf(
				'INSERT INTO `%1$s` (job_id, segment, byte_offset, piece_index, statement_no) VALUES (\'%2$s\', %3$d, %4$d, %5$d, %6$d) ON DUPLICATE KEY UPDATE segment = %3$d, byte_offset = %4$d, piece_index = %5$d, statement_no = %6$d',
				self::cursor_table(),
				$this->job_id,
				(int) $segment,
				(int) $offset,
				(int) $piece,
				(int) $this->statement_no
			)
		);
	}

	private function was_applied( $segment, $offset, $piece ) {
		$found = $this->scalar(
			sprintf(
				'SELECT statement_no FROM `%s` WHERE job_id = \'%s\' AND segment = %d AND byte_offset = %d AND piece_index = %d',
				self::applied_table(),
				$this->job_id,
				(int) $segment,
				(int) $offset,
				(int) $piece
			)
		);
		return null !== $found;
	}

	private function write_ledger( $segment, $offset, $piece ) {
		return $this->exec_sql(
			sprintf(
				'INSERT INTO `%s` (job_id, segment, byte_offset, piece_index, statement_no) VALUES (\'%s\', %d, %d, %d, %d)',
				self::applied_table(),
				$this->job_id,
				(int) $segment,
				(int) $offset,
				(int) $piece,
				(int) $this->statement_no
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Session
	 * ------------------------------------------------------------------ */

	private function open_session() {
		$row = $this->rows( 'SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.foreign_key_checks AS fk, @@SESSION.time_zone AS tz' );
		$this->saved_session = is_array( $row ) && $row ? $row[0] : array();
		$names = isset( $this->session['names'] ) ? $this->session['names'] : 'utf8mb4';
		if ( ! mysqli_set_charset( $this->dbh, $names ) ) {
			return $this->driver_error( 'set connection charset ' . $names, '' );
		}
		$mode = isset( $this->session['sql_mode'] ) ? $this->session['sql_mode'] : 'NO_AUTO_VALUE_ON_ZERO';
		$sets = array(
			'SET SESSION FOREIGN_KEY_CHECKS = 0',
			"SET SESSION sql_mode = '" . Sql_Escaper::escape_manual( $mode ) . "'",
		);
		if ( isset( $this->session['time_zone'] ) ) {
			$sets[] = "SET SESSION time_zone = '" . Sql_Escaper::escape_manual( $this->session['time_zone'] ) . "'";
		}
		foreach ( $sets as $sql ) {
			if ( ! $this->exec_sql( $sql ) ) {
				return $this->driver_error( 'prepare restore session', '' );
			}
		}
		return true;
	}

	private function close_session() {
		if ( ! $this->dbh ) {
			return;
		}
		$this->exec_sql( 'ROLLBACK' );
		if ( isset( $this->saved_session['sql_mode'] ) ) {
			$this->exec_sql( "SET SESSION sql_mode = '" . Sql_Escaper::escape_manual( $this->saved_session['sql_mode'] ) . "'" );
		}
		if ( isset( $this->saved_session['fk'] ) ) {
			$this->exec_sql( 'SET SESSION FOREIGN_KEY_CHECKS = ' . ( (int) $this->saved_session['fk'] ? 1 : 0 ) );
		}
		if ( isset( $this->saved_session['tz'] ) ) {
			$this->exec_sql( "SET SESSION time_zone = '" . Sql_Escaper::escape_manual( $this->saved_session['tz'] ) . "'" );
		}
		if ( method_exists( $this->wpdb, 'set_charset' ) ) {
			$this->wpdb->set_charset( $this->dbh );
		}
	}

	/* ------------------------------------------------------------------
	 * Restore
	 * ------------------------------------------------------------------ */

	/**
	 * Restore statements from one segment, starting at the committed cursor.
	 *
	 * @param int    $segment     Segment index.
	 * @param string $path        Extracted segment file.
	 * @param float  $time_budget Seconds.
	 * @param int    $max_statements Maximum statements in this call.
	 * @return array|\WP_Error
	 */
	public function import_chunk( $segment, $path, $time_budget = 12, $max_statements = 800 ) {
		$driver = $this->assert_driver();
		if ( is_wp_error( $driver ) ) {
			return $driver;
		}
		$ready = $this->ensure_tables();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$cursor = $this->read_cursor();
		if ( ! $cursor || (int) $cursor['segment'] !== (int) $segment ) {
			return new \WP_Error( 'jisento_sql_cursor', sprintf( __( 'The restore cursor is not on segment %d. The restore stopped instead of guessing where to continue.', 'jisento' ), (int) $segment ) . $this->job_suffix() );
		}
		$this->statement_no = max( $this->statement_no, (int) $cursor['statement_no'] );
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'jisento_sql_open', sprintf( __( 'Unable to open database segment %s.', 'jisento' ), basename( $path ) ) . $this->job_suffix() );
		}
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		if ( (int) $cursor['offset'] > $size || 0 !== fseek( $handle, (int) $cursor['offset'] ) ) {
			fclose( $handle );
			return new \WP_Error( 'jisento_sql_cursor', sprintf( __( 'The restore cursor (byte %1$d) is past the end of segment %2$s (%3$d bytes).', 'jisento' ), (int) $cursor['offset'], basename( $path ), $size ) . $this->job_suffix() );
		}
		$opened = $this->open_session();
		if ( is_wp_error( $opened ) ) {
			fclose( $handle );
			return $opened;
		}
		$this->notes                 = array();
		$this->placeholders_repaired = 0;
		$result = $this->run_segment( $handle, (int) $segment, $cursor, $size, $time_budget, $max_statements );
		fclose( $handle );
		$this->close_session();
		return $result;
	}

	private function run_segment( $handle, $segment, array $cursor, $size, $time_budget, $max_statements ) {
		$started      = microtime( true );
		$deadline     = $started + max( 1, (float) $time_budget );
		$buffer_start = (int) $cursor['offset'];
		$first_piece  = (int) $cursor['piece_index'];
		$pending      = '';
		$statements   = 0;
		$last_table   = '';
		$eof          = false;
		while ( true ) {
			$split = Sql_Scanner::next_statement( $pending );
			if ( null === $split ) {
				if ( $eof ) {
					break;
				}
				if ( strlen( $pending ) > 33554432 ) {
					return new \WP_Error( 'jisento_sql_large', __( 'A single SQL statement is larger than 32 MB, so it cannot be restored safely. Export the package again with this version of Jisento.', 'jisento' ) . $this->job_suffix() );
				}
				$read = fread( $handle, 1048576 );
				if ( false === $read ) {
					return new \WP_Error( 'jisento_sql_read', __( 'Reading the database segment failed.', 'jisento' ) . $this->job_suffix() );
				}
				if ( '' === $read ) {
					$eof = true;
					continue;
				}
				$pending .= $read;
				continue;
			}
			if ( $statements > 0 && ( microtime( true ) >= $deadline || $statements >= $max_statements ) ) {
				return $this->chunk_result( false, $statements, $last_table, $started, $buffer_start, $size );
			}
			$raw     = $split[0];
			$pending = $split[1];
			$start   = $buffer_start;
			$end     = $start + strlen( $raw );
			$buffer_start = $end;
			$ran = $this->run_statement( $segment, $start, $end, $raw, $first_piece, $deadline );
			$first_piece = 0;
			if ( is_wp_error( $ran ) ) {
				return $ran;
			}
			$statements += $ran['statements'];
			if ( '' !== $ran['table'] ) {
				$last_table = $ran['table'];
			}
			if ( ! empty( $ran['partial'] ) ) {
				return $this->chunk_result( false, $statements, $last_table, $started, $start, $size );
			}
		}
		if ( '' !== trim( self::strip_leading_comments( $pending ) ) ) {
			return new \WP_Error( 'jisento_sql_truncated', sprintf( __( 'Database segment %d ends in the middle of a statement. The package is incomplete; export it again.', 'jisento' ), $segment ) . $this->job_suffix() );
		}
		if ( ! $this->write_cursor( $segment, $size, 0 ) ) {
			return $this->driver_error( 'save resume point', self::cursor_table() );
		}
		return $this->chunk_result( true, $statements, $last_table, $started, $size, $size );
	}

	private function chunk_result( $done, $statements, $table, $started, $offset, $size ) {
		return array(
			'done'                  => (bool) $done,
			'statements'            => (int) $statements,
			'statement_no'          => (int) $this->statement_no,
			'table'                 => (string) $table,
			'offset'                => (int) $offset,
			'size'                  => (int) $size,
			'seconds'               => round( microtime( true ) - $started, 3 ),
			'session'               => $this->session,
			'notes'                 => $this->notes,
			'placeholders_repaired' => (int) $this->placeholders_repaired,
		);
	}

	/**
	 * @return array{statements:int,table:string,partial?:bool}|\WP_Error
	 */
	private function run_statement( $segment, $start, $end, $raw, $first_piece, $deadline ) {
		$sql = self::strip_leading_comments( $raw );
		if ( '' === $sql || ';' === $sql ) {
			return $this->advance( $segment, $end, 0, '' );
		}
		$plan = $this->classify( $sql );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( 'skip' === $plan['kind'] ) {
			return $this->advance( $segment, $end, 0, $plan['table'] );
		}
		if ( 'set' === $plan['kind'] ) {
			$applied = $this->apply_set( $plan );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
			return $this->advance( $segment, $end, 0, '' );
		}
		if ( 'ddl' === $plan['kind'] ) {
			if ( $first_piece > 0 ) {
				return $this->advance( $segment, $end, 0, $plan['table'] );
			}
			$this->fire( 'before_exec' );
			// DDL commits implicitly. A CREATE repeated after a crash must not fail on its own half-made shadow.
			if ( ! empty( $plan['pre'] ) && ! $this->exec_sql( $plan['pre'] ) ) {
				return $this->statement_error( $plan, $plan['pre'], $segment, $start, 0 );
			}
			if ( ! $this->exec_sql( $plan['sql'] ) ) {
				return $this->statement_error( $plan, $plan['sql'], $segment, $start, 0 );
			}
			$this->fire( 'after_exec' );
			$this->statement_no++;
			if ( ! $this->exec_sql( 'START TRANSACTION' ) || ! $this->commit_progress( $segment, $start, 0, $end, 0 ) ) {
				return $this->driver_error( 'save resume point', self::cursor_table() );
			}
			$this->fire( 'after_commit' );
			return array(
				'statements' => 1,
				'table'      => $plan['table'],
			);
		}

		$pieces = Sql_Scanner::split_insert( $plan['sql'] );
		$count  = 0;
		$total  = count( $pieces );
		foreach ( $pieces as $index => $piece ) {
			if ( $index < $first_piece ) {
				continue;
			}
			if ( $count > 0 && microtime( true ) >= $deadline ) {
				return array(
					'statements' => $count,
					'table'      => $plan['table'],
					'partial'    => true,
				);
			}
			$next_offset = ( $index + 1 >= $total ) ? $end : $start;
			$next_piece  = ( $index + 1 >= $total ) ? 0 : $index + 1;
			if ( $this->was_applied( $segment, $start, $index ) ) {
				if ( ! $this->write_cursor( $segment, $next_offset, $next_piece ) ) {
					return $this->driver_error( 'save resume point', self::cursor_table() );
				}
				$count++;
				continue;
			}
			$piece = $this->prepare_insert( $piece, $plan['shadow'] );
			if ( ! $this->exec_sql( 'START TRANSACTION' ) ) {
				return $this->driver_error( 'start transaction', $plan['table'] );
			}
			$this->fire( 'before_exec' );
			if ( ! $this->exec_sql( $piece ) ) {
				$error = $this->statement_error( $plan, $piece, $segment, $start, $index );
				$this->exec_sql( 'ROLLBACK' );
				return $error;
			}
			$this->fire( 'after_exec' );
			$this->statement_no++;
			if ( ! $this->commit_progress( $segment, $start, $index, $next_offset, $next_piece ) ) {
				$error = $this->driver_error( 'save resume point', self::cursor_table() );
				$this->exec_sql( 'ROLLBACK' );
				return $error;
			}
			$this->fire( 'after_commit' );
			$count++;
		}
		return array(
			'statements' => $count,
			'table'      => $plan['table'],
		);
	}

	/**
	 * Ledger row plus cursor, then COMMIT. For INSERT the statement is already inside this transaction.
	 */
	private function commit_progress( $segment, $offset, $piece, $next_offset, $next_piece ) {
		if ( ! $this->write_ledger( $segment, $offset, $piece ) || ! $this->write_cursor( $segment, $next_offset, $next_piece ) ) {
			return false;
		}
		$this->fire( 'before_commit' );
		return $this->exec_sql( 'COMMIT' );
	}

	private function advance( $segment, $offset, $piece, $table ) {
		if ( ! $this->write_cursor( $segment, $offset, $piece ) ) {
			return $this->driver_error( 'save resume point', self::cursor_table() );
		}
		return array(
			'statements' => 0,
			'table'      => (string) $table,
		);
	}

	private function fire( $point ) {
		if ( $this->fault ) {
			call_user_func( $this->fault, $point );
		}
	}

	/**
	 * Decide what one dump statement becomes. Only the statement kinds a Jisento dump contains are accepted.
	 *
	 * @param string $sql Statement without leading comments.
	 * @return array|\WP_Error
	 */
	public function classify( $sql ) {
		$sql = rtrim( $sql );
		if ( preg_match( '/^SET\s+/i', $sql ) ) {
			return $this->classify_set( $sql );
		}
		if ( preg_match( '/^(LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $sql ) ) {
			return array(
				'kind'  => 'skip',
				'table' => '',
			);
		}
		if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([^`]+)`\s*;?$/i', $sql, $m ) ) {
			$live = $this->dest_table( $m[1] );
			if ( ! $this->will_restore( $live ) ) {
				return array(
					'kind'  => 'skip',
					'table' => $live,
				);
			}
			return array(
				'kind'   => 'ddl',
				'table'  => $live,
				'shadow' => self::shadow_name( $live ),
				'sql'    => 'DROP TABLE IF EXISTS `' . self::shadow_name( $live ) . '`',
			);
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', $sql, $m ) ) {
			$live = $this->dest_table( $m[1] );
			if ( ! $this->will_restore( $live ) ) {
				return array(
					'kind'  => 'skip',
					'table' => $live,
				);
			}
			$create = $this->shadow_create( $sql, $live );
			if ( is_wp_error( $create ) ) {
				return $create;
			}
			return array(
				'kind'   => 'ddl',
				'table'  => $live,
				'shadow' => self::shadow_name( $live ),
				'pre'    => 'DROP TABLE IF EXISTS `' . self::shadow_name( $live ) . '`',
				'sql'    => $create,
			);
		}
		if ( preg_match( '/^INSERT\s+INTO\s+`([^`]+)`/i', $sql, $m ) ) {
			$live = $this->dest_table( $m[1] );
			if ( ! $this->will_restore( $live ) ) {
				return array(
					'kind'  => 'skip',
					'table' => $live,
				);
			}
			$shadow = self::shadow_name( $live );
			return array(
				'kind'   => 'insert',
				'table'  => $live,
				'shadow' => $shadow,
				'sql'    => self::replace_target( $sql, $m[1], $shadow ),
			);
		}
		return new \WP_Error(
			'jisento_sql_unsupported',
			sprintf(
				/* translators: %s: statement start */
				__( 'The package contains a statement type the restore does not run: %s. Only SET, DROP TABLE IF EXISTS, CREATE TABLE and INSERT are accepted, so nothing can touch a live table directly.', 'jisento' ),
				self::statement_preview( $sql, 60 )
			) . $this->job_suffix()
		);
	}

	/**
	 * @param string $sql Statement.
	 * @param string $from Table name as written.
	 * @param string $to   Replacement.
	 * @return string
	 */
	private static function replace_target( $sql, $from, $to ) {
		$token = '`' . $from . '`';
		$pos   = strpos( $sql, $token );
		if ( false === $pos ) {
			return $sql;
		}
		return substr( $sql, 0, $pos ) . '`' . $to . '`' . substr( $sql, $pos + strlen( $token ) );
	}

	private function classify_set( $sql ) {
		$body = trim( preg_replace( '/^SET\s+/i', '', rtrim( $sql, "; \t\r\n" ) ) );
		if ( preg_match( '/^NAMES\s+\'?([A-Za-z0-9_]+)\'?(?:\s+COLLATE\s+\'?([A-Za-z0-9_]+)\'?)?$/i', $body, $m ) ) {
			return array(
				'kind'  => 'set',
				'var'   => 'names',
				'value' => strtolower( $m[1] ),
			);
		}
		if ( preg_match( '/^(?:SESSION\s+)?(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS)\s*=\s*\w+$/i', $body ) ) {
			return array(
				'kind'  => 'skip',
				'table' => '',
			);
		}
		if ( preg_match( '/^(?:SESSION\s+)?(SQL_MODE|TIME_ZONE)\s*=\s*\'([^\'\\\\]*)\'$/i', $body, $m ) ) {
			return array(
				'kind'  => 'set',
				'var'   => strtolower( $m[1] ),
				'value' => $m[2],
			);
		}
		if ( preg_match( '/^@[A-Za-z0-9_]+\s*=/', $body ) ) {
			return array(
				'kind'  => 'skip',
				'table' => '',
			);
		}
		return new \WP_Error( 'jisento_sql_unsupported', sprintf( __( 'The package contains a SET statement the restore does not run: %s', 'jisento' ), self::statement_preview( $sql, 80 ) ) . $this->job_suffix() );
	}

	private function apply_set( array $plan ) {
		if ( 'names' === $plan['var'] ) {
			if ( ! mysqli_set_charset( $this->dbh, $plan['value'] ) ) {
				return $this->driver_error( 'SET NAMES ' . $plan['value'], '' );
			}
			$this->session['names'] = $plan['value'];
			return true;
		}
		$sql = 'SET SESSION ' . $plan['var'] . " = '" . Sql_Escaper::escape_manual( $plan['value'] ) . "'";
		if ( ! $this->exec_sql( $sql ) ) {
			return $this->driver_error( $sql, '' );
		}
		$this->session[ $plan['var'] ] = $plan['value'];
		return true;
	}

	/**
	 * Shadow CREATE TABLE: target renamed, foreign keys pointed at shadows of restored parents,
	 * constraint names made unique, unsupported collations mapped, non-InnoDB engines converted.
	 *
	 * @param string $sql  CREATE TABLE statement.
	 * @param string $live Destination table name.
	 * @return string|\WP_Error
	 */
	public function shadow_create( $sql, $live ) {
		$sql = rtrim( $sql, "; \t\r\n" );
		if ( ! preg_match( '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([^`]+)`/i', $sql, $m ) ) {
			return $sql;
		}
		$shadow = self::shadow_name( $live );
		$sql    = 'CREATE TABLE `' . $shadow . '`' . substr( $sql, strlen( $m[0] ) );
		$split  = self::split_create( $sql );
		if ( null === $split ) {
			return new \WP_Error( 'jisento_sql_create', sprintf( __( 'The CREATE TABLE statement for %s could not be parsed.', 'jisento' ), $live ) . $this->job_suffix() );
		}
		list( $body, $tail ) = $split;

		$self  = $this;
		$body  = preg_replace_callback(
			'/(\bREFERENCES\s+)`([^`]+)`/i',
			static function ( $ref ) use ( $self ) {
				$parent = $self->dest_table( $ref[2] );
				return $ref[1] . '`' . ( $self->will_restore( $parent ) ? Database_Importer::shadow_name( $parent ) : $parent ) . '`';
			},
			$body
		);
		$notes = &$this->notes;
		$body  = preg_replace_callback(
			'/(\bCONSTRAINT\s+)`([^`]+)`/i',
			static function ( $c ) use ( $live, &$notes ) {
				$renamed = Database_Importer::shadow_constraint_name( $c[2] );
				$notes['constraints'][ $live ][ $renamed ] = $c[2];
				return $c[1] . '`' . $renamed . '`';
			},
			$body
		);

		if ( $this->is_mariadb() ) {
			$tail = preg_replace( '#/\*!80\d{3}.*?\*/#s', '', $tail );
			$body = preg_replace( '#/\*!80\d{3}.*?\*/#s', '', $body );
		}

		if ( preg_match( '/\bENGINE\s*=\s*([A-Za-z0-9_]+)/i', $tail, $engine ) && 0 !== strcasecmp( $engine[1], 'InnoDB' ) ) {
			$converted = self::convert_engine_options( $tail );
			if ( null === $converted ) {
				return new \WP_Error( 'jisento_sql_engine', sprintf( __( 'Table %1$s uses the %2$s engine, which this restore cannot convert to InnoDB.', 'jisento' ), $live, $engine[1] ) . $this->job_suffix() );
			}
			$tail = $converted;
			$this->notes['engines'][ $live ] = $engine[1];
		}

		$mapped = $this->map_collations( $body . $tail, $live );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		return $mapped;
	}

	/**
	 * @param string $sql CREATE TABLE statement.
	 * @return array{0:string,1:string}|null Column list including its closing parenthesis, and the table options.
	 */
	public static function split_create( $sql ) {
		$open = strpos( $sql, '(' );
		if ( false === $open ) {
			return null;
		}
		$len   = strlen( $sql );
		$depth = 0;
		$quote = '';
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $sql[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $ch && '`' !== $quote ) {
					$i++;
					continue;
				}
				if ( $ch === $quote ) {
					if ( isset( $sql[ $i + 1 ] ) && $sql[ $i + 1 ] === $quote ) {
						$i++;
						continue;
					}
					$quote = '';
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$quote = $ch;
				continue;
			}
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return array( substr( $sql, 0, $i + 1 ), substr( $sql, $i + 1 ) );
				}
			}
		}
		return null;
	}

	/**
	 * Table options that InnoDB rejects or that only mean something for MyISAM/Aria are removed.
	 *
	 * @param string $tail Table options.
	 * @return string|null
	 */
	public static function convert_engine_options( $tail ) {
		if ( preg_match( '/\bENGINE\s*=\s*(MRG_MyISAM|MERGE|FEDERATED|CONNECT|SPIDER|BLACKHOLE|CSV|ARCHIVE|SEQUENCE)\b/i', $tail ) ) {
			return null;
		}
		$tail = preg_replace( '/\bENGINE\s*=\s*[A-Za-z0-9_]+/i', 'ENGINE=InnoDB', $tail );
		$tail = preg_replace( '/\s*\b(ROW_FORMAT|PAGE_CHECKSUM|TRANSACTIONAL|PACK_KEYS|DELAY_KEY_WRITE|CHECKSUM|MAX_ROWS|MIN_ROWS|AVG_ROW_LENGTH)\s*=\s*[A-Za-z0-9_]+/i', '', $tail );
		return $tail;
	}

	/**
	 * @param string $sql  Statement.
	 * @param string $live Table.
	 * @return string|\WP_Error
	 */
	private function map_collations( $sql, $live ) {
		$this->load_server_names();
		$self   = $this;
		$failed = '';
		$sql    = preg_replace_callback(
			'/\b(COLLATE)(\s*=\s*|\s+)([A-Za-z0-9_]+)/i',
			static function ( $m ) use ( $self, $live, &$failed ) {
				$to = $self->resolve_collation( $m[3], $live );
				if ( null === $to ) {
					$failed = $m[3];
					return $m[0];
				}
				return $m[1] . $m[2] . $to;
			},
			$sql
		);
		if ( '' !== $failed ) {
			return new \WP_Error( 'jisento_sql_collation', sprintf( __( 'Table %1$s uses collation %2$s, which this database server does not support and has no safe equivalent.', 'jisento' ), $live, $failed ) . $this->job_suffix() );
		}
		$charsets = $this->charsets;
		$sql      = preg_replace_callback(
			'/\b(CHARSET|CHARACTER\s+SET)(\s*=\s*|\s+)([A-Za-z0-9_]+)/i',
			static function ( $m ) use ( $charsets ) {
				return $m[1] . $m[2] . Database_Importer::map_charset( $m[3], $charsets );
			},
			$sql
		);
		return $sql;
	}

	/**
	 * Keep the dump collation when the server accepts it; otherwise map to a probed replacement.
	 *
	 * @param string $name Collation from the dump.
	 * @param string $live Live table name (for job notes).
	 * @return string|null
	 */
	private function resolve_collation( $name, $live ) {
		$name = (string) $name;
		foreach ( self::charsets_for_collation( $name ) as $charset ) {
			if ( $this->probe_collation( $charset, $name ) ) {
				$this->notes['collations_kept'][ $live ][ $name ] = true;
				return $name;
			}
		}
		foreach ( self::collation_candidates( $name ) as $candidate ) {
			foreach ( self::charsets_for_collation( $candidate ) as $charset ) {
				if ( $this->probe_collation( $charset, $candidate ) ) {
					$this->notes['collations'][ $live ][ $name ] = $candidate;
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * Probe whether CONVERT(... USING charset) COLLATE collation is accepted.
	 * Results are cached on the restore session so each pair is probed once per job.
	 *
	 * @param string $charset   Character set.
	 * @param string $collation Collation name.
	 * @return bool
	 */
	private function probe_collation( $charset, $collation ) {
		$charset   = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $charset ) );
		$collation = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $collation ) );
		if ( '' === $charset || '' === $collation ) {
			return false;
		}
		$key = $charset . '/' . $collation;
		if ( ! isset( $this->session['collation_probes'] ) || ! is_array( $this->session['collation_probes'] ) ) {
			$this->session['collation_probes'] = array();
		}
		if ( array_key_exists( $key, $this->session['collation_probes'] ) ) {
			return (bool) $this->session['collation_probes'][ $key ];
		}
		$saved_errno = $this->last_errno;
		$saved_error = $this->last_error;
		$ok          = null !== $this->rows( "SELECT CONVERT('a' USING {$charset}) COLLATE {$collation}" );
		$this->last_errno                      = $saved_errno;
		$this->last_error                      = $saved_error;
		$this->session['collation_probes'][ $key ] = $ok;
		return $ok;
	}

	/**
	 * Charset names to try for a collation (utf8mb3 and utf8 are aliases on many servers).
	 *
	 * @param string $name Collation.
	 * @return string[]
	 */
	public static function charsets_for_collation( $name ) {
		$charset = self::charset_from_collation( $name );
		if ( '' === $charset ) {
			return array();
		}
		$out = array( $charset );
		if ( 'utf8mb3' === $charset ) {
			$out[] = 'utf8';
		} elseif ( 'utf8' === $charset ) {
			$out[] = 'utf8mb3';
		}
		return $out;
	}

	/**
	 * @param string $name Collation.
	 * @return string Lowercase charset prefix, or ''.
	 */
	public static function charset_from_collation( $name ) {
		$lower = strtolower( (string) $name );
		if ( preg_match( '/^(utf8mb4|utf8mb3|utf8|latin1|ascii|binary)_/', $lower, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Replacement collations to try when the original is not accepted.
	 *
	 * @param string $name Collation from the dump.
	 * @return string[]
	 */
	public static function collation_candidates( $name ) {
		$lower   = strtolower( (string) $name );
		$charset = self::charset_from_collation( $lower );
		if ( '' === $charset ) {
			return array();
		}
		if ( preg_match( '/^utf8mb4_0900_bin$/', $lower ) ) {
			return array( 'utf8mb4_bin' );
		}
		// MySQL 8 utf8mb4_0900_* (except bin): keep the historical MariaDB mapping, not uca1400.
		if ( preg_match( '/^utf8mb4_0900_/', $lower ) ) {
			return array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci' );
		}
		$charsets = self::charsets_for_collation( $lower );
		$rest     = substr( $lower, strlen( $charset ) + 1 );
		$rest     = str_replace( '_nopad', '', $rest );
		$out      = array();
		if ( preg_match( '/(_|^)(as_)?cs$/', $rest ) || preg_match( '/_bin$/', $rest ) ) {
			foreach ( $charsets as $cs ) {
				$out[] = $cs . '_bin';
			}
			return array_values( array_unique( $out ) );
		}
		$suffixes = array( '_uca1400_ai_ci', '_unicode_520_ci', '_unicode_ci', '_general_ci' );
		foreach ( $charsets as $cs ) {
			foreach ( $suffixes as $suffix ) {
				$candidate = $cs . $suffix;
				if ( $candidate !== $lower ) {
					$out[] = $candidate;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Pick a collation from a known-supported list (unit tests / offline). Prefer probe-based resolve_collation at restore time.
	 *
	 * @param string   $name      Collation from the dump.
	 * @param string[] $supported Lowercase collation names. Empty means unknown: keep the name.
	 * @return string|null
	 */
	public static function map_collation( $name, array $supported ) {
		$lower = strtolower( (string) $name );
		if ( ! $supported || in_array( $lower, $supported, true ) ) {
			return $name;
		}
		foreach ( self::collation_candidates( $name ) as $candidate ) {
			if ( in_array( strtolower( $candidate ), $supported, true ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * @param string   $name      Charset.
	 * @param string[] $supported Lowercase charset names on this server.
	 * @return string
	 */
	public static function map_charset( $name, array $supported ) {
		$lower = strtolower( (string) $name );
		if ( ! $supported || in_array( $lower, $supported, true ) ) {
			return $name;
		}
		if ( 'utf8mb3' === $lower && in_array( 'utf8', $supported, true ) ) {
			return 'utf8';
		}
		return $name;
	}

	private function load_server_names() {
		if ( null !== $this->charsets ) {
			return;
		}
		$this->charsets = array();
		foreach ( (array) $this->rows( 'SHOW COLLATION' ) as $row ) {
			if ( isset( $row['Charset'] ) && '' !== (string) $row['Charset'] ) {
				$this->charsets[ strtolower( (string) $row['Charset'] ) ] = true;
			}
		}
		foreach ( (array) $this->rows( 'SHOW CHARACTER SET' ) as $row ) {
			if ( isset( $row['Charset'] ) && '' !== (string) $row['Charset'] ) {
				$this->charsets[ strtolower( (string) $row['Charset'] ) ] = true;
			}
		}
		$this->charsets = array_keys( $this->charsets );
	}

	private function is_mariadb() {
		if ( null === $this->mariadb ) {
			$version       = (string) $this->scalar( 'SELECT VERSION()' );
			$this->mariadb = false !== stripos( $version, 'mariadb' );
		}
		return $this->mariadb;
	}

	/**
	 * v1 compatibility and the opt-in placeholder repair.
	 *
	 * @param string $sql    INSERT piece targeting the shadow table.
	 * @param string $shadow Shadow table.
	 * @return string
	 */
	private function prepare_insert( $sql, $shadow ) {
		if ( ! $this->legacy ) {
			return $sql;
		}
		if ( $this->placeholder_tokens ) {
			$count = 0;
			$sql   = str_replace( $this->placeholder_tokens, '%', $sql, $count );
			$this->placeholders_repaired += $count;
		}
		return $this->preserve_binary_literals( $sql, $shadow );
	}

	/**
	 * v1 packages could quote BINARY values. Under a utf8mb4 connection those bytes would be
	 * charset-validated, so they are sent as hex literals instead.
	 *
	 * @param string $sql   INSERT statement.
	 * @param string $table Table the statement writes to.
	 * @return string
	 */
	private function preserve_binary_literals( $sql, $table ) {
		if ( ! preg_match( '/^(INSERT\s+INTO\s+`[^`]+`\s*\((.*?)\)\s*VALUES\s*)(.*)$/is', ltrim( $sql ), $match ) ) {
			return $sql;
		}
		$binary = $this->binary_columns_for( $table );
		if ( ! $binary ) {
			return $sql;
		}
		$columns = array();
		foreach ( explode( ',', $match[2] ) as $column ) {
			$columns[] = trim( $column, " `\t\n\r" );
		}
		$indexes = array();
		foreach ( $columns as $index => $column ) {
			if ( ! empty( $binary[ $column ] ) ) {
				$indexes[] = $index;
			}
		}
		if ( ! $indexes ) {
			return $sql;
		}
		$body   = rtrim( $match[3] );
		$suffix = '';
		if ( ';' === substr( $body, -1 ) ) {
			$body   = substr( $body, 0, -1 );
			$suffix = ';';
		}
		$rebuilt = array();
		foreach ( Sql_Scanner::value_tuples( $body ) as $tuple ) {
			$fields = Sql_Scanner::tuple_fields( $tuple );
			if ( count( $fields ) !== count( $columns ) ) {
				return $sql;
			}
			foreach ( $indexes as $index ) {
				$fields[ $index ] = self::binary_token( $fields[ $index ] );
			}
			$rebuilt[] = '(' . implode( ',', $fields ) . ')';
		}
		return $rebuilt ? $match[1] . implode( ',', $rebuilt ) . $suffix : $sql;
	}

	private static function binary_token( $token ) {
		$token = trim( (string) $token );
		if ( preg_match( '/^0x[0-9a-fA-F]+$/', $token ) || preg_match( "/^X'[0-9a-fA-F]*'$/i", $token ) || 0 === strcasecmp( $token, 'NULL' ) ) {
			return $token;
		}
		$decoded = self::decode_sql_literal( $token );
		if ( null === $decoded ) {
			return 'NULL';
		}
		return '' === $decoded ? "X''" : '0x' . bin2hex( $decoded );
	}

	/**
	 * @param string $token SQL literal.
	 * @return string|null
	 */
	public static function decode_sql_literal( $token ) {
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
		$map   = array(
			'0'  => "\0",
			'b'  => "\x08",
			'n'  => "\n",
			'r'  => "\r",
			't'  => "\t",
			'Z'  => "\x1a",
			'\\' => '\\',
			"'"  => "'",
			'"'  => '"',
		);
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $inner[ $i ];
			if ( '\\' === $ch && isset( $inner[ $i + 1 ] ) ) {
				$next = $inner[ ++$i ];
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

	private function binary_columns_for( $table ) {
		if ( isset( $this->binary_columns[ $table ] ) ) {
			return $this->binary_columns[ $table ];
		}
		$map = array();
		foreach ( (array) $this->rows( 'SHOW FULL COLUMNS FROM `' . self::esc( $table ) . '`' ) as $row ) {
			$type      = isset( $row['Type'] ) ? strtolower( (string) $row['Type'] ) : '';
			$collation = isset( $row['Collation'] ) ? strtolower( (string) $row['Collation'] ) : '';
			if ( ! empty( $row['Field'] ) && ( preg_match( '/blob|binary|geometry|(^|[^a-z])bit\b/', $type ) || 'binary' === $collation ) ) {
				$map[ $row['Field'] ] = true;
			}
		}
		return $this->binary_columns[ $table ] = $map;
	}

	/* ------------------------------------------------------------------
	 * Errors
	 * ------------------------------------------------------------------ */

	public static function is_sensitive_table( $table ) {
		return 1 === preg_match( self::SENSITIVE_TABLE_PATTERN, (string) $table );
	}

	/**
	 * Quoted values inside a driver error ("Duplicate entry 'x'", "Incorrect string value: 'x'")
	 * are row data. They are removed for tables that hold credentials or personal data.
	 *
	 * @param string $error Driver error.
	 * @param string $table Table.
	 * @return string
	 */
	public static function safe_driver_error( $error, $table ) {
		$error = (string) $error;
		if ( self::is_sensitive_table( $table ) ) {
			$error = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'(?=\s+for\s+(?:key|column))/s", "'[redacted]'", $error );
			$error = preg_replace( "/(Duplicate entry|string value:?)\s+'.*?'/s", "$1 '[redacted]'", $error );
		}
		return self::printable( $error );
	}

	private function statement_error( array $plan, $sql, $segment, $offset, $piece ) {
		$table   = $plan['table'];
		$error   = self::safe_driver_error( $this->last_error, $table );
		$preview = self::is_sensitive_table( $table ) ? __( '[not shown for this table]', 'jisento' ) : self::statement_preview( $sql, 200 );
		$why     = '';
		if ( '1062' === $this->last_errno ) {
			$why = ' ' . __( 'The package contains the same key twice for this table. Rows were not skipped. Check the source table for duplicate keys and export again.', 'jisento' );
		}
		return new \WP_Error(
			'jisento_sql_error',
			sprintf(
				/* translators: 1: table, 2: errno, 3: error, 4: statement number, 5: segment, 6: offset, 7: piece, 8: preview */
				__( 'Restoring table %1$s failed. Database error %2$s: %3$s Statement %4$d (segment %5$d, byte %6$d, part %7$d): %8$s', 'jisento' ),
				$table,
				$this->last_errno,
				$error,
				$this->statement_no + 1,
				$segment,
				$offset,
				$piece,
				$preview
			) . $why . $this->job_suffix()
		);
	}

	private function driver_error( $operation, $table ) {
		return new \WP_Error(
			'jisento_sql_driver',
			sprintf(
				/* translators: 1: operation, 2: table, 3: errno, 4: error */
				__( 'Database operation "%1$s" failed%2$s. Database error %3$s: %4$s', 'jisento' ),
				$operation,
				'' !== $table ? ' on ' . $table : '',
				$this->last_errno,
				self::safe_driver_error( $this->last_error, $table )
			) . $this->job_suffix()
		);
	}

	private function job_suffix() {
		return ' Job: ' . $this->job_id;
	}

	public static function statement_preview( $sql, $length = 200 ) {
		$preview = preg_replace( '/\s+/', ' ', substr( (string) $sql, 0, (int) $length ) );
		return self::printable( (string) $preview );
	}

	private static function printable( $text ) {
		return (string) preg_replace( '/[^\x20-\x7E]/', '?', (string) $text );
	}

	/**
	 * Comment lines before a statement are not terminated by a semicolon, so they would
	 * otherwise be glued to the following statement.
	 *
	 * @param string $sql Raw statement buffer.
	 * @return string
	 */
	public static function strip_leading_comments( $sql ) {
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
			if ( 0 === strpos( $sql, '/*' ) && 0 !== strpos( $sql, '/*!' ) ) {
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

	/* ------------------------------------------------------------------
	 * Driver
	 * ------------------------------------------------------------------ */

	/**
	 * Every restore statement goes straight to mysqli, so no WordPress "query" filter can change it.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	private function exec_sql( $sql ) {
		if ( ! $this->dbh ) {
			$this->last_errno = '0';
			$this->last_error = 'no mysqli connection';
			return false;
		}
		try {
			$ok = mysqli_real_query( $this->dbh, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			$this->last_errno = (string) $e->getCode();
			$this->last_error = $e->getMessage();
			return false;
		}
		if ( ! $ok || 0 !== mysqli_errno( $this->dbh ) ) {
			$this->last_errno = (string) mysqli_errno( $this->dbh );
			$this->last_error = (string) mysqli_error( $this->dbh );
			return false;
		}
		if ( mysqli_field_count( $this->dbh ) > 0 ) {
			$result = mysqli_store_result( $this->dbh );
			if ( $result instanceof \mysqli_result ) {
				mysqli_free_result( $result );
			}
		}
		$this->last_errno = '0';
		$this->last_error = '';
		return true;
	}

	/**
	 * @param string $sql Query.
	 * @return array[]|null
	 */
	private function rows( $sql ) {
		if ( ! $this->dbh ) {
			return null;
		}
		try {
			$ok = mysqli_real_query( $this->dbh, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			$this->last_errno = (string) $e->getCode();
			$this->last_error = $e->getMessage();
			return null;
		}
		if ( ! $ok ) {
			$this->last_errno = (string) mysqli_errno( $this->dbh );
			$this->last_error = (string) mysqli_error( $this->dbh );
			return null;
		}
		$result = mysqli_store_result( $this->dbh );
		if ( ! ( $result instanceof \mysqli_result ) ) {
			return array();
		}
		$out = array();
		while ( $row = mysqli_fetch_assoc( $result ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$out[] = $row;
		}
		mysqli_free_result( $result );
		return $out;
	}

	private function scalar( $sql ) {
		$rows = $this->rows( $sql );
		if ( ! $rows ) {
			return null;
		}
		$first = reset( $rows[0] );
		return false === $first ? null : $first;
	}

	private static function esc( $ident ) {
		return str_replace( '`', '', (string) $ident );
	}

	private function quote( $value ) {
		return Sql_Escaper::quote_manual( $value );
	}

	private function table_exists( $table ) {
		$found = $this->scalar( 'SHOW TABLES LIKE ' . $this->quote( addcslashes( $table, '_%\\' ) ) );
		return (string) $found === (string) $table;
	}

	/* ------------------------------------------------------------------
	 * Before and after the swap
	 * ------------------------------------------------------------------ */

	/**
	 * Existing tables that a shadow or retired name would overwrite.
	 *
	 * @param string[] $tables Destination tables that will be restored.
	 * @return string[]
	 */
	public function colliding_names( array $tables ) {
		$hits = array();
		foreach ( $tables as $table ) {
			foreach ( array( self::shadow_name( $table ), self::retired_name( $table ) ) as $name ) {
				if ( $this->table_exists( $name ) ) {
					$hits[] = $name;
				}
			}
		}
		return $hits;
	}

	/**
	 * Row count of each restored shadow compared with the counts written into the manifest.
	 *
	 * @param array<string,int> $expected Destination table => rows.
	 * @return string[] Mismatch descriptions.
	 */
	public function count_mismatches( array $expected ) {
		$out = array();
		foreach ( $expected as $table => $rows ) {
			$shadow = self::shadow_name( $table );
			if ( ! $this->table_exists( $shadow ) ) {
				$out[] = sprintf( '%s: restored table missing', $table );
				continue;
			}
			$count = $this->scalar( 'SELECT COUNT(*) FROM `' . self::esc( $shadow ) . '`' );
			if ( null === $count || (int) $count !== (int) $rows ) {
				$out[] = sprintf( '%s: package %d rows, restored %s', $table, (int) $rows, null === $count ? '?' : (string) (int) $count );
			}
		}
		return $out;
	}

	/**
	 * @return bool True when the restored users table exists and has no rows.
	 */
	public function shadow_users_empty() {
		$shadow = self::shadow_name( $this->dest_prefix . 'users' );
		if ( ! $this->will_restore( $this->dest_prefix . 'users' ) || ! $this->table_exists( $shadow ) ) {
			return false;
		}
		$count = $this->scalar( 'SELECT COUNT(*) FROM `' . $shadow . '`' );
		return null !== $count && (int) $count < 1;
	}

	/**
	 * Prefix-dependent keys, rewritten in the shadow tables so the live tables never see the source prefix.
	 * Options: only "<prefix>user_roles". Usermeta: every key that starts with the prefix.
	 * Keys that already carry the destination prefix are left alone, so a repeat run changes nothing.
	 *
	 * @return true|\WP_Error
	 */
	public function rewrite_prefix_in_shadows() {
		$from = $this->source_prefix;
		$to   = $this->dest_prefix;
		if ( '' === $from || $from === $to ) {
			return true;
		}
		$targets = array();
		if ( $this->will_restore( $to . 'options' ) ) {
			$targets[] = array( self::shadow_name( $to . 'options' ), 'option_name', true );
		}
		if ( $this->will_restore( $to . 'usermeta' ) ) {
			$targets[] = array( self::shadow_name( $to . 'usermeta' ), 'meta_key', false );
		}
		foreach ( $targets as $target ) {
			list( $table, $column, $roles_only ) = $target;
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$where = $roles_only
				? 'BINARY `' . $column . '` = BINARY ' . $this->quote( $from . 'user_roles' )
				: 'BINARY LEFT(`' . $column . '`, ' . strlen( $from ) . ') = BINARY ' . $this->quote( $from );
			if ( 0 === strpos( $to, $from ) ) {
				$where .= ' AND BINARY LEFT(`' . $column . '`, ' . strlen( $to ) . ') <> BINARY ' . $this->quote( $to );
			}
			$sql = 'UPDATE `' . $table . '` SET `' . $column . '` = CONCAT(' . $this->quote( $to ) . ', SUBSTRING(`' . $column . '`, ' . ( strlen( $from ) + 1 ) . ')) WHERE ' . $where;
			if ( ! $this->exec_sql( $sql ) ) {
				return $this->driver_error( 'rewrite table prefix in ' . $column, $table );
			}
		}
		return true;
	}

	/**
	 * Rename every restored shadow over its live table in one RENAME TABLE.
	 * A repeated call after a crash sees no shadows left and only finishes the cleanup.
	 *
	 * @param string[] $tables Destination tables that were restored.
	 * @return array{swapped:string[],retired_left:string[]}|\WP_Error
	 */
	public function swap_shadows( array $tables ) {
		$driver = $this->assert_driver();
		if ( is_wp_error( $driver ) ) {
			return $driver;
		}
		$fk = $this->scalar( 'SELECT @@SESSION.foreign_key_checks' );
		if ( ! $this->exec_sql( 'SET SESSION FOREIGN_KEY_CHECKS = 0' ) ) {
			return $this->driver_error( 'disable foreign key checks for the swap', '' );
		}
		$result = $this->do_swap( $tables );
		$this->exec_sql( 'SET SESSION FOREIGN_KEY_CHECKS = ' . ( (int) $fk ? 1 : 0 ) );
		return $result;
	}

	private function do_swap( array $tables ) {
		$pairs   = array();
		$missing = array();
		foreach ( $tables as $live ) {
			$shadow  = self::shadow_name( $live );
			$retired = self::retired_name( $live );
			if ( ! $this->table_exists( $shadow ) ) {
				$missing[] = $live;
				continue;
			}
			if ( $this->table_exists( $retired ) ) {
				if ( ! $this->exec_sql( 'DROP TABLE `' . $retired . '`' ) ) {
					return $this->driver_error( 'drop leftover retired table before the swap', $retired );
				}
			}
			if ( $this->table_exists( $live ) ) {
				$pairs[] = '`' . $live . '` TO `' . $retired . '`';
			}
			$pairs[] = '`' . $shadow . '` TO `' . $live . '`';
		}
		if ( $missing && count( $missing ) !== count( $tables ) ) {
			return new \WP_Error(
				'jisento_shadow',
				sprintf( __( 'The restored copies of these tables are missing, so no live table was replaced: %s', 'jisento' ), implode( ', ', $missing ) ) . $this->job_suffix()
			);
		}
		if ( $missing ) {
			foreach ( $tables as $live ) {
				if ( ! $this->table_exists( $live ) ) {
					return new \WP_Error( 'jisento_shadow', sprintf( __( 'Neither the live table nor its restored copy exists for %s.', 'jisento' ), $live ) . $this->job_suffix() );
				}
			}
		} elseif ( $pairs && ! $this->exec_sql( 'RENAME TABLE ' . implode( ', ', $pairs ) ) ) {
			return $this->driver_error( 'swap restored tables into place (no live table was changed)', '' );
		}
		$left = array();
		foreach ( $tables as $live ) {
			$retired = self::retired_name( $live );
			if ( $this->table_exists( $retired ) && ! $this->exec_sql( 'DROP TABLE `' . $retired . '`' ) ) {
				$left[] = $retired . ' (' . self::printable( $this->last_error ) . ')';
			}
		}
		return array(
			'swapped'      => array_values( $tables ),
			'retired_left' => $left,
		);
	}

	/**
	 * After the swap, every foreign key must point at a live table and carry its original name.
	 *
	 * @param string[]                          $tables      Restored tables.
	 * @param array<string,array<string,string>> $constraints Table => renamed => original constraint name.
	 * @return array{repaired:string[]}|\WP_Error
	 */
	public function repair_foreign_keys( array $tables, array $constraints ) {
		$reverse = array();
		foreach ( $tables as $live ) {
			$reverse[ self::shadow_name( $live ) ]  = $live;
			$reverse[ self::retired_name( $live ) ] = $live;
		}
		$like = addcslashes( $this->dest_prefix, '_%\\' ) . '%';
		$rows = $this->rows(
			'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE
			FROM information_schema.KEY_COLUMN_USAGE k
			JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
			WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME LIKE ' . $this->quote( $like ) . '
			ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
		);
		if ( null === $rows ) {
			return $this->driver_error( 'read foreign keys', '' );
		}
		$fks = array();
		foreach ( $rows as $row ) {
			$key = $row['TABLE_NAME'] . "\0" . $row['CONSTRAINT_NAME'];
			if ( ! isset( $fks[ $key ] ) ) {
				$fks[ $key ] = array(
					'table'      => $row['TABLE_NAME'],
					'name'       => $row['CONSTRAINT_NAME'],
					'ref'        => $row['REFERENCED_TABLE_NAME'],
					'cols'       => array(),
					'ref_cols'   => array(),
					'on_update'  => $row['UPDATE_RULE'],
					'on_delete'  => $row['DELETE_RULE'],
				);
			}
			$fks[ $key ]['cols'][]     = $row['COLUMN_NAME'];
			$fks[ $key ]['ref_cols'][] = $row['REFERENCED_COLUMN_NAME'];
		}
		$fk = $this->scalar( 'SELECT @@SESSION.foreign_key_checks' );
		$this->exec_sql( 'SET SESSION FOREIGN_KEY_CHECKS = 0' );
		$repaired = array();
		$error    = null;
		foreach ( $fks as $fkey ) {
			$table = $fkey['table'];
			if ( isset( $reverse[ $table ] ) ) {
				continue;
			}
			$target = isset( $reverse[ $fkey['ref'] ] ) ? $reverse[ $fkey['ref'] ] : $fkey['ref'];
			$name   = isset( $constraints[ $table ][ $fkey['name'] ] ) ? $constraints[ $table ][ $fkey['name'] ] : $fkey['name'];
			if ( $target === $fkey['ref'] && $name === $fkey['name'] ) {
				continue;
			}
			$add = sprintf(
				'ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
				self::esc( $name ),
				'`' . implode( '`, `', array_map( array( __CLASS__, 'esc' ), $fkey['cols'] ) ) . '`',
				self::esc( $target ),
				'`' . implode( '`, `', array_map( array( __CLASS__, 'esc' ), $fkey['ref_cols'] ) ) . '`',
				self::fk_rule( $fkey['on_delete'] ),
				self::fk_rule( $fkey['on_update'] )
			);
			$drop = 'ALTER TABLE `' . self::esc( $table ) . '` DROP FOREIGN KEY `' . self::esc( $fkey['name'] ) . '`';
			$ok   = $this->exec_sql( $drop ) && $this->exec_sql( 'ALTER TABLE `' . self::esc( $table ) . '` ' . $add );
			if ( ! $ok ) {
				$error = $this->driver_error( 'recreate foreign key ' . $name, $table );
				break;
			}
			$repaired[] = $table . '.' . $name . ' -> ' . $target;
		}
		$this->exec_sql( 'SET SESSION FOREIGN_KEY_CHECKS = ' . ( (int) $fk ? 1 : 0 ) );
		if ( $error ) {
			return $error;
		}
		return array( 'repaired' => $repaired );
	}

	private static function fk_rule( $rule ) {
		$rule = strtoupper( (string) $rule );
		return in_array( $rule, array( 'CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT' ), true ) ? $rule : 'RESTRICT';
	}

	/**
	 * Convert tables back to the engine they had in the package. Only when the user asked for it.
	 *
	 * @param array<string,string> $engines Table => engine.
	 * @return string[]|\WP_Error Converted tables.
	 */
	public function restore_engines( array $engines ) {
		$done = array();
		foreach ( $engines as $table => $engine ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $engine ) || ! $this->table_exists( $table ) ) {
				continue;
			}
			if ( ! $this->exec_sql( 'ALTER TABLE `' . self::esc( $table ) . '` ENGINE=' . $engine ) ) {
				return $this->driver_error( 'convert back to ' . $engine, $table );
			}
			$done[] = $table . ' -> ' . $engine;
		}
		return $done;
	}

	/**
	 * Drop the shadow tables of a cancelled or failed import. Live tables are not touched.
	 *
	 * @param string[] $tables Destination tables.
	 * @return string[] Tables that could not be dropped.
	 */
	public static function drop_shadows( array $tables ) {
		global $wpdb;
		$left = array();
		foreach ( $tables as $table ) {
			$shadow = self::shadow_name( (string) $table );
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $shadow ) ) {
				continue;
			}
			if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . $shadow . '`' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$left[] = $shadow;
			}
		}
		return $left;
	}
}
