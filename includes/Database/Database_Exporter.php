<?php
/**
 * Database table discovery and streaming SQL export.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Exporter {

	/**
	 * Flush a multi-row INSERT when statement text reaches this size (~1.5 MiB).
	 */
	const INSERT_FLUSH_BYTES = 1572864;

	/**
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * @var array<string,array<string,bool>>
	 */
	private $binary_columns = array();

	/**
	 * @var array<string,string[]>
	 */
	private $column_names = array();

	/**
	 * @var array<string,array<string,string>>
	 */
	private $column_kinds = array();

	/**
	 * @var array<string,array<string,string>>
	 */
	private $column_types = array();

	/**
	 * @var array<string,array<string,bool>>
	 */
	private $not_null = array();

	/**
	 * @var array<string,array>
	 */
	private $key_cache = array();

	/**
	 * @var string[]
	 */
	private $skipped_views = array();

	/**
	 * @var Sql_Escaper
	 */
	private $escaper;

	public function __construct() {
		global $wpdb;
		$this->wpdb    = $wpdb;
		$dbh           = isset( $wpdb->dbh ) ? $wpdb->dbh : null;
		$this->escaper = new Sql_Escaper( $dbh );
	}

	/**
	 * The export reads through a utf8mb4 connection so 4-byte characters are never
	 * replaced by "?" on sites whose wp-config still says utf8.
	 */
	public function restore_connection_charset() {
		if ( isset( $this->wpdb->dbh ) && is_object( $this->wpdb->dbh ) && method_exists( $this->wpdb, 'set_charset' ) ) {
			$this->wpdb->set_charset( $this->wpdb->dbh );
		}
	}

	public function tables( array $exclude = array() ) {
		$prefix = $this->wpdb->prefix;
		$like   = $this->wpdb->esc_like( $prefix ) . '%';
		$found  = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		$out    = array();
		$this->skipped_views = array();
		foreach ( (array) $found as $row ) {
			$table = isset( $row[0] ) ? (string) $row[0] : '';
			$type  = isset( $row[1] ) ? strtoupper( (string) $row[1] ) : 'BASE TABLE';
			if ( '' === $table ) {
				continue;
			}
			$short = substr( $table, strlen( $prefix ) );
			if ( in_array( $table, $exclude, true ) || in_array( $short, $exclude, true ) ) {
				continue;
			}
			if ( 0 === strpos( $short, 'jisento_' ) ) {
				continue;
			}
			if ( 'VIEW' === $type ) {
				$this->skipped_views[] = $table;
				continue;
			}
			$out[] = $table;
		}
		return $out;
	}

	/**
	 * Views are not exported. The caller records them in the manifest and the job log.
	 *
	 * @return string[]
	 */
	public function skipped_views() {
		return $this->skipped_views;
	}

	public function table_meta( $table ) {
		$status = $this->wpdb->get_row( $this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		return array(
			'name'    => $table,
			'engine'  => $status ? $status['Engine'] : '',
			'rows'    => $status ? (int) $status['Rows'] : 0,
			'data'    => $status ? (int) $status['Data_length'] : 0,
			'index'   => $status ? (int) $status['Index_length'] : 0,
		);
	}

	/**
	 * Session header written at the top of every segment, so each segment restores on its own.
	 *
	 * @param string $prefix Table prefix.
	 * @return string
	 */
	public static function header_sql( $prefix ) {
		$lines = array(
			'-- Jisento Migration SQL dump',
			'-- Plugin: ' . ( defined( 'JISENTO_VERSION' ) ? JISENTO_VERSION : '' ),
			'-- Prefix: ' . $prefix,
			'SET NAMES utf8mb4;',
			'SET FOREIGN_KEY_CHECKS=0;',
			'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';',
			'',
		);
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @return true|\WP_Error
	 */
	public function write_header( $handle, $hash = null ) {
		return self::write_all( $handle, self::header_sql( $this->wpdb->prefix ), $hash );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function write_footer( $handle, $hash = null ) {
		return self::write_all( $handle, "SET FOREIGN_KEY_CHECKS=1;\n", $hash );
	}

	/**
	 * A short write means the disk is full or the file is gone. The segment must not be kept.
	 *
	 * @param resource         $handle File.
	 * @param string           $bytes  Data.
	 * @param mixed            $hash   HashContext, a list of them, or null.
	 * @return true|\WP_Error
	 */
	public static function write_all( $handle, $bytes, $hash = null ) {
		$bytes  = (string) $bytes;
		$length = strlen( $bytes );
		$done   = 0;
		while ( $done < $length ) {
			$wrote = fwrite( $handle, 0 === $done ? $bytes : substr( $bytes, $done ) );
			if ( false === $wrote || 0 === $wrote ) {
				return new \WP_Error( 'jisento_export_write', __( 'Writing the database segment failed (disk full or file removed).', 'jisento' ) );
			}
			$done += $wrote;
		}
		foreach ( is_array( $hash ) ? $hash : array( $hash ) as $ctx ) {
			if ( null !== $ctx ) {
				hash_update( $ctx, $bytes );
			}
		}
		return true;
	}

	/**
	 * @return array{bytes:int}|\WP_Error
	 */
	public function export_table_structure( $handle, $table, $hash = null ) {
		$create = $this->wpdb->get_row( 'SHOW CREATE TABLE `' . $this->esc_ident( $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $create || empty( $create[1] ) ) {
			return new \WP_Error( 'jisento_create', sprintf( __( 'Unable to read structure for table %s.', 'jisento' ), $table ) );
		}
		$sql   = "\n-- Table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
		$wrote = self::write_all( $handle, $sql, $hash );
		if ( is_wp_error( $wrote ) ) {
			return $wrote;
		}
		return array( 'bytes' => strlen( $sql ) );
	}

	public function primary_columns( $table ) {
		$rows = $this->wpdb->get_results( 'SHOW KEYS FROM `' . $this->esc_ident( $table ) . '` WHERE Key_name = \'PRIMARY\'', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $rows ) {
			return array();
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'];
			}
		);
		$columns = array();
		foreach ( $rows as $row ) {
			$columns[] = $row['Column_name'];
		}
		return $columns;
	}

	/**
	 * Columns used to page through a table: the primary key, otherwise a UNIQUE index whose
	 * columns are all NOT NULL. Without either, every column is used and the order is not
	 * guaranteed to be stable across requests; the manifest records that.
	 *
	 * @param string $table Table.
	 * @return array{columns:string[],source:string,stable:bool}
	 */
	public function key_columns( $table ) {
		if ( isset( $this->key_cache[ $table ] ) ) {
			return $this->key_cache[ $table ];
		}
		$this->binary_column_map( $table );
		$pk = $this->primary_columns( $table );
		if ( $pk ) {
			return $this->key_cache[ $table ] = array(
				'columns' => $pk,
				'source'  => 'primary',
				'stable'  => true,
			);
		}
		$rows    = $this->wpdb->get_results( 'SHOW KEYS FROM `' . $this->esc_ident( $table ) . '` WHERE Non_unique = 0', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$indexes = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['Key_name'] ) || empty( $row['Column_name'] ) ) {
				continue;
			}
			$indexes[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		ksort( $indexes );
		foreach ( $indexes as $columns ) {
			ksort( $columns );
			$columns = array_values( $columns );
			$usable  = true;
			foreach ( $columns as $column ) {
				if ( empty( $this->not_null[ $table ][ $column ] ) ) {
					$usable = false;
					break;
				}
			}
			if ( $usable && $columns ) {
				return $this->key_cache[ $table ] = array(
					'columns' => $columns,
					'source'  => 'unique',
					'stable'  => true,
				);
			}
		}
		return $this->key_cache[ $table ] = array(
			'columns' => isset( $this->column_names[ $table ] ) ? $this->column_names[ $table ] : array(),
			'source'  => 'none',
			'stable'  => false,
		);
	}

	/**
	 * Read one page of rows and write it as INSERT statements.
	 * Keyed tables use keyset pagination on the full ordered key tuple. Tables without a
	 * usable key are ordered by every column and paged with OFFSET.
	 *
	 * @param resource $handle Segment file.
	 * @param string   $table  Table.
	 * @param mixed    $cursor array( '__keyset' => ... ), array( '__offset' => n ), or null.
	 * @param int      $limit  Rows per page.
	 * @param mixed    $hash   HashContext or list of HashContext updated with every byte written.
	 * @return array{done:bool,cursor:array|null,rows:int,bytes:int,sha256:string}|\WP_Error
	 */
	public function export_table_rows( $handle, $table, $cursor, $limit = 500, $hash = null ) {
		$limit  = max( 1, (int) $limit );
		$ident  = '`' . $this->esc_ident( $table ) . '`';
		$binary = $this->binary_column_map( $table );
		$select = $this->select_list( $table, $binary );
		if ( '' === $select ) {
			return new \WP_Error( 'jisento_export_columns', sprintf( __( 'Unable to read the columns for table %s, so its rows were not exported.', 'jisento' ), $table ) );
		}
		$key       = $this->key_columns( $table );
		$keyed     = 'none' !== $key['source'];
		$order_sql = array();
		foreach ( $key['columns'] as $col ) {
			$order_sql[] = $ident . '.`' . $this->esc_ident( $col ) . '`';
		}
		if ( ! $order_sql ) {
			return new \WP_Error( 'jisento_export_columns', sprintf( __( 'Table %s has no columns to order by, so its rows were not exported.', 'jisento' ), $table ) );
		}
		$order  = ' ORDER BY ' . implode( ', ', $order_sql );
		$offset = 0;
		if ( $keyed ) {
			$where = '';
			$args  = array();
			if ( is_array( $cursor ) && isset( $cursor['__keyset'] ) ) {
				if ( ! is_array( $cursor['__keyset'] ) || count( $cursor['__keyset'] ) !== count( $key['columns'] ) ) {
					return new \WP_Error( 'jisento_export_cursor', sprintf( __( 'The export cursor for table %s does not match its key columns (%s), so the export stopped instead of writing the table again.', 'jisento' ), $table, implode( ', ', $key['columns'] ) ) );
				}
				$predicate = self::keyset_predicate( $order_sql, $cursor['__keyset'] );
				if ( null === $predicate ) {
					return new \WP_Error( 'jisento_export_cursor', sprintf( __( 'The export cursor for table %s is not a valid keyset, so the export stopped instead of writing the table again.', 'jisento' ), $table ) );
				}
				$where = ' WHERE ' . $predicate[0];
				$args  = $predicate[1];
			} elseif ( null !== $cursor ) {
				return new \WP_Error( 'jisento_export_cursor', sprintf( __( 'The export cursor for table %s is not a keyset cursor, so the export stopped instead of writing the table again.', 'jisento' ), $table ) );
			}
			$args[] = $limit;
			$sql    = 'SELECT ' . $select . ' FROM ' . $ident . $where . $order . ' LIMIT %d';
			$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$offset = ( is_array( $cursor ) && isset( $cursor['__offset'] ) ) ? max( 0, (int) $cursor['__offset'] ) : 0;
			$sql    = 'SELECT ' . $select . ' FROM ' . $ident . $order . ' LIMIT %d OFFSET %d';
			$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'jisento_export_read', sprintf( __( 'Unable to read rows from table %1$s. Database error: %2$s', 'jisento' ), $table, (string) $this->wpdb->last_error ) );
		}
		if ( ! $rows ) {
			return array(
				'done'   => true,
				'cursor' => null,
				'rows'   => 0,
				'bytes'  => 0,
				'sha256' => hash( 'sha256', '' ),
			);
		}
		$kinds = array();
		if ( $keyed ) {
			$labels = array();
			foreach ( $key['columns'] as $col ) {
				$kinds[]  = isset( $this->column_kinds[ $table ][ $col ] ) ? $this->column_kinds[ $table ][ $col ] : 'string';
				$labels[] = $col . ' ' . ( isset( $this->column_types[ $table ][ $col ] ) ? $this->column_types[ $table ][ $col ] : $kinds[ count( $kinds ) - 1 ] );
			}
			$previous = self::cursor_comparable( $cursor, $kinds );
			if ( is_wp_error( $previous ) ) {
				return $previous;
			}
			foreach ( $rows as $row ) {
				$current = self::comparable_tuple( $row, $key['columns'], $kinds );
				if ( null === $current ) {
					return new \WP_Error( 'jisento_export_key', sprintf( __( 'Table %1$s returned a key value in (%2$s) that is not a valid integer, so the export stopped instead of comparing it as text.', 'jisento' ), $table, implode( ', ', $labels ) ) );
				}
				if ( null !== $previous && self::key_follows( $previous, $current, $kinds ) < 1 ) {
					return new \WP_Error(
						'jisento_export_repeat',
						sprintf(
							/* translators: 1: table, 2: key description, 3: previous key, 4: next key */
							__( 'Table %1$s key (%2$s) did not advance. Previous key: %3$s. Next key: %4$s. The export stopped instead of writing that key twice.', 'jisento' ),
							$table,
							implode( ', ', $labels ),
							self::format_key( $previous, $kinds ),
							self::format_key( $current, $kinds )
						)
					);
				}
				$previous = $current;
			}
		}

		$page    = hash_init( 'sha256' );
		$targets = array( $page );
		foreach ( is_array( $hash ) ? $hash : array( $hash ) as $ctx ) {
			if ( null !== $ctx ) {
				$targets[] = $ctx;
			}
		}
		$columns = array();
		foreach ( array_keys( $rows[0] ) as $col ) {
			$columns[] = '`' . $this->esc_ident( $col ) . '`';
		}
		$prefix  = 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES ';
		$group   = array();
		$size    = strlen( $prefix );
		$written = 0;
		// Prefer large multi-row INSERTs: flush around 1–2 MB of statement text (not row-count / 256 KB).
		$flush_at = self::INSERT_FLUSH_BYTES;
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $column => $value ) {
				if ( isset( $binary[ $column ] ) ) {
					$values[] = self::binary_sql_literal( $value );
				} else {
					$values[] = $this->sql_value( $value );
				}
			}
			$tuple   = '(' . implode( ',', $values ) . ')';
			$group[] = $tuple;
			$size   += strlen( $tuple ) + 1;
			if ( $size >= $flush_at ) {
				$sql   = $prefix . implode( ',', $group ) . ";\n";
				$wrote = self::write_all( $handle, $sql, $targets );
				if ( is_wp_error( $wrote ) ) {
					return $wrote;
				}
				$written += strlen( $sql );
				$group    = array();
				$size     = strlen( $prefix );
			}
		}
		if ( $group ) {
			$sql   = $prefix . implode( ',', $group ) . ";\n";
			$wrote = self::write_all( $handle, $sql, $targets );
			if ( is_wp_error( $wrote ) ) {
				return $wrote;
			}
			$written += strlen( $sql );
		}

		$done   = count( $rows ) < $limit;
		$cursor = null;
		if ( ! $done ) {
			if ( $keyed ) {
				$tuple  = self::comparable_tuple( $rows[ count( $rows ) - 1 ], $key['columns'], $kinds );
				$cursor = self::pack_keyset( $tuple, $kinds );
			} else {
				$cursor = array( '__offset' => $offset + count( $rows ) );
			}
		}
		return array(
			'done'   => $done,
			'cursor' => $cursor,
			'rows'   => count( $rows ),
			'bytes'  => $written,
			'sha256' => hash_final( $page ),
		);
	}

	/**
	 * Integer, binary, or string. String covers varchar, text, enum, and datetime.
	 * Those are ordered by MariaDB, not by a PHP string compare.
	 *
	 * @param string $type      Column type from SHOW COLUMNS.
	 * @param string $collation Column collation.
	 * @return string int|binary|string
	 */
	public static function column_kind_from_type( $type, $collation = '' ) {
		$type      = strtolower( (string) $type );
		$collation = strtolower( (string) $collation );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type ) ) {
			return 'int';
		}
		if ( ( '' !== $type && preg_match( '/blob|binary|geometry|(^|[^a-z])bit\b/', $type ) ) || 'binary' === $collation ) {
			return 'binary';
		}
		return 'string';
	}

	/**
	 * Decimal form of an integer primary key. Rejects anything that is not a base-10 integer.
	 *
	 * @param mixed $value Value returned by MariaDB.
	 * @return string|null
	 */
	public static function canonical_int( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(-?)([0-9]+)$/', $value, $match ) ) {
			return null;
		}
		// ZEROFILL columns return "00012"; the key is still the integer 12.
		$digits = ltrim( $match[2], '0' );
		if ( '' === $digits ) {
			return '0';
		}
		return $match[1] . $digits;
	}

	/**
	 * @param string $left  Canonical integer.
	 * @param string $right Canonical integer.
	 * @return int Negative when $left is less.
	 */
	public static function cmp_int_strings( $left, $right ) {
		$left  = self::canonical_int( $left );
		$right = self::canonical_int( $right );
		if ( null === $left || null === $right ) {
			return 0;
		}
		$left_neg  = ( '-' === $left[0] );
		$right_neg = ( '-' === $right[0] );
		if ( $left_neg !== $right_neg ) {
			return $left_neg ? -1 : 1;
		}
		$left_digits  = ltrim( $left_neg ? substr( $left, 1 ) : $left, '0' );
		$right_digits = ltrim( $right_neg ? substr( $right, 1 ) : $right, '0' );
		if ( '' === $left_digits ) {
			$left_digits = '0';
		}
		if ( '' === $right_digits ) {
			$right_digits = '0';
		}
		if ( $left_digits === $right_digits ) {
			return 0;
		}
		if ( strlen( $left_digits ) !== strlen( $right_digits ) ) {
			$cmp = strlen( $left_digits ) < strlen( $right_digits ) ? -1 : 1;
		} else {
			$cmp = strcmp( $left_digits, $right_digits ) < 0 ? -1 : 1;
		}
		return $left_neg ? -$cmp : $cmp;
	}

	/**
	 * Byte order of two even-length hex strings. This matches BINARY/VARBINARY comparison.
	 *
	 * @param string $left  Hex.
	 * @param string $right Hex.
	 * @return int
	 */
	public static function cmp_hex( $left, $right ) {
		$left  = strtolower( (string) $left );
		$right = strtolower( (string) $right );
		if ( $left === $right ) {
			return 0;
		}
		return strcmp( $left, $right ) < 0 ? -1 : 1;
	}

	/**
	 * 1 when $current is strictly after $previous, 0 when it is the same key, -1 when an
	 * integer or binary column went backwards. Text columns are ordered by MariaDB's
	 * collation, so a different text value is not treated as a repeat.
	 *
	 * @param array    $previous Comparable values.
	 * @param array    $current  Comparable values.
	 * @param string[] $kinds    int, binary, or string, one per column.
	 * @return int
	 */
	public static function key_follows( array $previous, array $current, array $kinds ) {
		$n = count( $kinds );
		for ( $i = 0; $i < $n; $i++ ) {
			$before = array_key_exists( $i, $previous ) ? $previous[ $i ] : null;
			$after  = array_key_exists( $i, $current ) ? $current[ $i ] : null;
			if ( $before === $after ) {
				continue;
			}
			if ( null === $before ) {
				return 1;
			}
			if ( null === $after ) {
				return -1;
			}
			if ( 'int' === $kinds[ $i ] ) {
				$cmp = self::cmp_int_strings( $before, $after );
				if ( 0 === $cmp ) {
					continue;
				}
				return $cmp < 0 ? 1 : -1;
			}
			if ( 'binary' === $kinds[ $i ] ) {
				$cmp = self::cmp_hex( $before, $after );
				if ( 0 === $cmp ) {
					continue;
				}
				return $cmp < 0 ? 1 : -1;
			}
			return 1;
		}
		return 0;
	}

	/**
	 * Values in comparison form: integer decimal, binary hex, or the original string.
	 *
	 * @param array    $row   Result row.
	 * @param string[] $pk    Primary-key names.
	 * @param string[] $kinds Column kinds.
	 * @return array|null Null when an integer value is not an integer.
	 */
	public static function comparable_tuple( array $row, array $pk, array $kinds ) {
		$tuple = array();
		foreach ( $pk as $index => $col ) {
			$kind = isset( $kinds[ $index ] ) ? $kinds[ $index ] : 'string';
			if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
				$tuple[] = null;
				continue;
			}
			if ( 'int' === $kind ) {
				$value = self::canonical_int( $row[ $col ] );
				if ( null === $value ) {
					return null;
				}
				$tuple[] = $value;
				continue;
			}
			if ( 'binary' === $kind ) {
				$tuple[] = strtolower( (string) $row[ $col ] );
				continue;
			}
			$tuple[] = (string) $row[ $col ];
		}
		return $tuple;
	}

	/**
	 * @param mixed    $cursor Saved cursor.
	 * @param string[] $kinds  Column kinds.
	 * @return array|null|\WP_Error
	 */
	public static function cursor_comparable( $cursor, array $kinds ) {
		if ( ! is_array( $cursor ) ) {
			return null;
		}
		if ( isset( $cursor['__keyset'] ) && is_array( $cursor['__keyset'] ) ) {
			$tuple = array();
			foreach ( $cursor['__keyset'] as $index => $part ) {
				if ( ! is_array( $part ) ) {
					return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not a valid keyset, so the export stopped instead of writing the table again.', 'jisento' ) );
				}
				$kind = isset( $kinds[ $index ] ) ? $kinds[ $index ] : ( isset( $part['k'] ) ? $part['k'] : 'string' );
				if ( ! isset( $part['v'] ) || null === $part['v'] ) {
					if ( isset( $part['h'] ) && is_string( $part['h'] ) ) {
						$tuple[] = hex2bin( $part['h'] );
						continue;
					}
					$tuple[] = null;
					continue;
				}
				if ( 'int' === $kind ) {
					$value = self::canonical_int( $part['v'] );
					if ( null === $value ) {
						return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not a valid integer, so the export stopped instead of writing the table again.', 'jisento' ) );
					}
					$tuple[] = $value;
					continue;
				}
				$tuple[] = (string) $part['v'];
			}
			return $tuple;
		}
		if ( isset( $cursor['__hexpk'] ) && is_array( $cursor['__hexpk'] ) ) {
			$tuple = array();
			foreach ( $cursor['__hexpk'] as $index => $hex ) {
				$kind = isset( $kinds[ $index ] ) ? $kinds[ $index ] : 'string';
				if ( null === $hex ) {
					$tuple[] = null;
					continue;
				}
				if ( ! is_string( $hex ) || ! preg_match( '/^[0-9a-fA-F]*$/', $hex ) ) {
					return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not valid hexadecimal, so the export stopped instead of writing the table again.', 'jisento' ) );
				}
				if ( 'binary' === $kind ) {
					$tuple[] = strtolower( $hex );
					continue;
				}
				$raw = hex2bin( $hex );
				if ( 'int' === $kind ) {
					$value = self::canonical_int( $raw );
					if ( null === $value ) {
						return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not a valid integer, so the export stopped instead of writing the table again.', 'jisento' ) );
					}
					$tuple[] = $value;
					continue;
				}
				$tuple[] = $raw;
			}
			return $tuple;
		}
		return null;
	}

	/**
	 * Cursor that survives JSON. Integers stay decimal. Binary stays hex.
	 * Text is hex so a non-UTF-8 byte cannot be corrupted when the job is saved.
	 *
	 * @param array    $tuple Comparable tuple.
	 * @param string[] $kinds Column kinds.
	 * @return array{__keyset:array}
	 */
	public static function pack_keyset( array $tuple, array $kinds ) {
		$parts = array();
		foreach ( $tuple as $index => $value ) {
			$kind = isset( $kinds[ $index ] ) ? $kinds[ $index ] : 'string';
			if ( null === $value ) {
				$parts[] = array(
					'k' => $kind,
					'v' => null,
				);
				continue;
			}
			if ( 'string' === $kind ) {
				$parts[] = array(
					'k' => 'string',
					'h' => bin2hex( (string) $value ),
				);
				continue;
			}
			$parts[] = array(
				'k' => $kind,
				'v' => (string) $value,
			);
		}
		return array( '__keyset' => $parts );
	}

	/**
	 * Row-constructor predicate. Integer and text values are bound as strings so MariaDB
	 * applies numeric comparison or the column collation. Binary values stay UNHEX().
	 *
	 * @param string[] $idents Quoted identifiers.
	 * @param array    $parts  Keyset parts.
	 * @return array{0:string,1:array}|null
	 */
	public static function keyset_predicate( array $idents, array $parts ) {
		if ( count( $idents ) !== count( $parts ) ) {
			return null;
		}
		$placeholders = array();
		$args         = array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || empty( $part['k'] ) ) {
				return null;
			}
			$raw = null;
			if ( isset( $part['h'] ) && is_string( $part['h'] ) ) {
				if ( ! preg_match( '/^[0-9a-fA-F]*$/', $part['h'] ) ) {
					return null;
				}
				$raw = hex2bin( $part['h'] );
			} elseif ( array_key_exists( 'v', $part ) && null !== $part['v'] ) {
				$raw = (string) $part['v'];
			}
			if ( null === $raw ) {
				$placeholders[] = 'NULL';
				continue;
			}
			if ( 'binary' === $part['k'] ) {
				if ( ! preg_match( '/^[0-9a-fA-F]*$/', $raw ) ) {
					return null;
				}
				$placeholders[] = 'UNHEX(%s)';
				$args[]         = strtolower( $raw );
				continue;
			}
			if ( 'int' === $part['k'] ) {
				$value = self::canonical_int( $raw );
				if ( null === $value ) {
					return null;
				}
				$placeholders[] = '%s';
				$args[]         = $value;
				continue;
			}
			$placeholders[] = '%s';
			$args[]         = $raw;
		}
		return array(
			'(' . implode( ', ', $idents ) . ') > (' . implode( ', ', $placeholders ) . ')',
			$args,
		);
	}

	/**
	 * @param array    $tuple Comparable values.
	 * @param string[] $kinds Column kinds.
	 * @return string
	 */
	public static function format_key( array $tuple, array $kinds ) {
		$shown = array();
		foreach ( $tuple as $index => $value ) {
			$kind = isset( $kinds[ $index ] ) ? $kinds[ $index ] : 'string';
			if ( null === $value ) {
				$shown[] = 'NULL';
				continue;
			}
			if ( 'binary' === $kind ) {
				$shown[] = '0x' . strtolower( (string) $value );
				continue;
			}
			$shown[] = (string) $value;
		}
		return implode( ', ', $shown );
	}

	/**
	 * Compare two primary-key tuples stored as lowercase hex (null for SQL NULL).
	 * A negative result means $left is before $right.
	 *
	 * @param array $left  Previous key.
	 * @param array $right Next key.
	 * @return int
	 */
	public static function pk_hex_cmp( array $left, array $right ) {
		$n = max( count( $left ), count( $right ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$a = array_key_exists( $i, $left ) ? $left[ $i ] : null;
			$b = array_key_exists( $i, $right ) ? $right[ $i ] : null;
			if ( $a === $b ) {
				continue;
			}
			if ( null === $a ) {
				return -1;
			}
			if ( null === $b ) {
				return 1;
			}
			$cmp = strcmp( strtolower( (string) $a ), strtolower( (string) $b ) );
			if ( 0 !== $cmp ) {
				return $cmp < 0 ? -1 : 1;
			}
		}
		return 0;
	}

	/**
	 * @param array    $row    Result row. Binary columns are already HEX() text.
	 * @param string[] $pk     Primary-key column names.
	 * @param array    $binary Binary column map.
	 * @return array
	 */
	public static function pk_hex_tuple( array $row, array $pk, array $binary ) {
		$packed = array();
		foreach ( $pk as $col ) {
			if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
				$packed[] = null;
				continue;
			}
			if ( isset( $binary[ $col ] ) ) {
				$packed[] = strtolower( (string) $row[ $col ] );
			} else {
				$packed[] = bin2hex( (string) $row[ $col ] );
			}
		}
		return $packed;
	}

	/**
	 * Hex literal for a value already returned by MySQL HEX().
	 *
	 * @param mixed $hex Hex text, or null.
	 * @return string
	 */
	public static function binary_sql_literal( $hex ) {
		if ( null === $hex ) {
			return 'NULL';
		}
		$hex = strtolower( (string) $hex );
		if ( ! preg_match( '/^[0-9a-f]*$/', $hex ) ) {
			$hex = bin2hex( $hex );
		}
		if ( '' === $hex ) {
			return "X''";
		}
		return '0x' . $hex;
	}

	/**
	 * Primary-key cursor that survives JSON. Raw binary keys are not valid UTF-8
	 * and would change when the job state is saved, so the next page would repeat rows.
	 *
	 * @param array $values Raw column values.
	 * @return array{__hexpk:array}
	 */
	public static function pack_pk_cursor( array $values ) {
		$packed = array();
		foreach ( $values as $value ) {
			if ( null === $value ) {
				$packed[] = null;
			} else {
				$packed[] = bin2hex( (string) $value );
			}
		}
		return array( '__hexpk' => $packed );
	}

	/**
	 * @param mixed $cursor Saved cursor.
	 * @return array|null Raw values, or null when this is not a hex cursor.
	 */
	public static function unpack_pk_cursor( $cursor ) {
		if ( ! is_array( $cursor ) || ! isset( $cursor['__hexpk'] ) || ! is_array( $cursor['__hexpk'] ) ) {
			return null;
		}
		$values = array();
		foreach ( $cursor['__hexpk'] as $value ) {
			if ( null === $value ) {
				$values[] = null;
				continue;
			}
			if ( ! is_string( $value ) || '' !== $value && ! preg_match( '/^[0-9a-fA-F]*$/', $value ) ) {
				return null;
			}
			$values[] = hex2bin( $value );
		}
		return $values;
	}

	private function binary_column_map( $table ) {
		if ( isset( $this->binary_columns[ $table ] ) ) {
			return $this->binary_columns[ $table ];
		}
		$rows = $this->wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $this->esc_ident( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || ! $rows ) {
			$rows = $this->wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->esc_ident( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! is_array( $rows ) || ! $rows ) {
			return array();
		}
		$map   = array();
		$names = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['Field'] ) ) {
				continue;
			}
			$names[] = $row['Field'];
			$this->not_null[ $table ][ $row['Field'] ] = isset( $row['Null'] ) && 'NO' === strtoupper( (string) $row['Null'] );
			$type    = isset( $row['Type'] ) ? strtolower( (string) $row['Type'] ) : '';
			$collate = isset( $row['Collation'] ) ? strtolower( (string) $row['Collation'] ) : '';
			$kind    = self::column_kind_from_type( $type, $collate );
			$this->column_kinds[ $table ][ $row['Field'] ] = $kind;
			$this->column_types[ $table ][ $row['Field'] ] = $type;
			if ( 'binary' === $kind ) {
				$map[ $row['Field'] ] = true;
			}
		}
		$this->column_names[ $table ]   = $names;
		$this->binary_columns[ $table ] = $map;
		return $map;
	}

	private function select_list( $table, array $binary ) {
		if ( ! isset( $this->column_names[ $table ] ) ) {
			$this->binary_column_map( $table );
		}
		$names = isset( $this->column_names[ $table ] ) ? $this->column_names[ $table ] : array();
		if ( ! $names ) {
			return '';
		}
		$parts = array();
		foreach ( $names as $name ) {
			$ident = '`' . $this->esc_ident( $name ) . '`';
			if ( isset( $binary[ $name ] ) ) {
				$parts[] = 'HEX(' . $ident . ') AS ' . $ident;
			} else {
				$parts[] = $ident;
			}
		}
		return implode( ', ', $parts );
	}

	private function sql_value( $value ) {
		return $this->escaper->quote( $value );
	}

	private function esc_ident( $ident ) {
		return str_replace( '`', '', $ident );
	}
}
