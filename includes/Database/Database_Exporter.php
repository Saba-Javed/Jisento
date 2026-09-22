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

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function tables( array $exclude = array() ) {
		$prefix = $this->wpdb->prefix;
		$like   = $this->wpdb->esc_like( $prefix ) . '%';
		$found  = $this->wpdb->get_col( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$out    = array();
		foreach ( $found as $table ) {
			$short = substr( $table, strlen( $prefix ) );
			if ( in_array( $table, $exclude, true ) || in_array( $short, $exclude, true ) ) {
				continue;
			}
			if ( 0 === strpos( $short, 'jisento_' ) ) {
				continue;
			}
			$out[] = $table;
		}
		return $out;
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

	public function write_header( $handle ) {
		$lines = array(
			'-- Jisento Migration SQL dump',
			'-- Plugin: ' . JISENTO_VERSION,
			'-- Prefix: ' . $this->wpdb->prefix,
			'SET NAMES utf8mb4;',
			'SET FOREIGN_KEY_CHECKS=0;',
			'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';',
			'',
		);
		fwrite( $handle, implode( "\n", $lines ) . "\n" );
	}

	public function write_footer( $handle ) {
		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
	}

	public function export_table_structure( $handle, $table ) {
		$create = $this->wpdb->get_row( 'SHOW CREATE TABLE `' . $this->esc_ident( $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $create || empty( $create[1] ) ) {
			return new \WP_Error( 'jisento_create', sprintf( __( 'Unable to read structure for table %s.', 'jisento' ), $table ) );
		}
		fwrite( $handle, "\n-- Table {$table}\n" );
		fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
		fwrite( $handle, $create[1] . ";\n\n" );
		return true;
	}

	/**
	 * Export a slice of rows. Returns next offset or -1 when complete.
	 */
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
	 * Read a stable page of rows. Cursor is the last primary-key tuple, or array( '__offset' => n ) for tables without a key.
	 *
	 * @return array{done:bool,cursor:array|null}|\WP_Error
	 */
	public function export_table_rows( $handle, $table, $cursor, $limit = 500 ) {
		$limit  = max( 1, (int) $limit );
		$pk     = $this->primary_columns( $table );
		$ident  = '`' . $this->esc_ident( $table ) . '`';
		$binary = $this->binary_column_map( $table );
		$select = $this->select_list( $table, $binary );
		if ( '' === $select ) {
			return new \WP_Error( 'jisento_export_columns', sprintf( __( 'Unable to read the columns for table %s, so its rows were not exported again.', 'jisento' ), $table ) );
		}
		if ( $pk ) {
			$order_sql = array();
			foreach ( $pk as $col ) {
				$order_sql[] = '`' . $this->esc_ident( $col ) . '`';
			}
			$order = ' ORDER BY ' . implode( ', ', $order_sql );
			$where = '';
			$args  = array();
			if ( is_array( $cursor ) && isset( $cursor['__offset'] ) ) {
				$sql  = 'SELECT ' . $select . ' FROM ' . $ident . $order . ' LIMIT %d OFFSET %d';
				$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit, (int) $cursor['__offset'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			} else {
				if ( is_array( $cursor ) && isset( $cursor['__keyset'] ) ) {
					if ( ! is_array( $cursor['__keyset'] ) || count( $cursor['__keyset'] ) !== count( $pk ) ) {
						return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor does not match this table, so the export stopped instead of writing the table again.', 'jisento' ) );
					}
					$predicate = self::keyset_predicate( $order_sql, $cursor['__keyset'] );
					if ( null === $predicate ) {
						return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not a valid keyset, so the export stopped instead of writing the table again.', 'jisento' ) );
					}
					$where = ' WHERE ' . $predicate[0];
					$args  = $predicate[1];
				} elseif ( is_array( $cursor ) && isset( $cursor['__hexpk'] ) ) {
					if ( ! is_array( $cursor['__hexpk'] ) || count( $cursor['__hexpk'] ) !== count( $pk ) ) {
						return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor does not match this table, so the export stopped instead of writing the table again.', 'jisento' ) );
					}
					$placeholders = array();
					foreach ( $cursor['__hexpk'] as $value ) {
						if ( null === $value ) {
							$placeholders[] = 'NULL';
							continue;
						}
						if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-fA-F]*$/', $value ) ) {
							return new \WP_Error( 'jisento_export_cursor', __( 'The database export cursor is not valid hexadecimal, so the export stopped instead of writing the table again.', 'jisento' ) );
						}
						$placeholders[] = 'UNHEX(%s)';
						$args[]         = $value;
					}
					$where = ' WHERE (' . implode( ', ', $order_sql ) . ') > (' . implode( ', ', $placeholders ) . ')';
				} elseif ( is_array( $cursor ) && count( $cursor ) === count( $pk ) ) {
					$placeholders = array();
					foreach ( $cursor as $value ) {
						$placeholders[] = '%s';
						$args[]         = $value;
					}
					$where = ' WHERE (' . implode( ', ', $order_sql ) . ') > (' . implode( ', ', $placeholders ) . ')';
				}
				$args[] = $limit;
				$sql    = 'SELECT ' . $select . ' FROM ' . $ident . $where . $order . ' LIMIT %d';
				$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		} else {
			$offset = ( is_array( $cursor ) && isset( $cursor['__offset'] ) ) ? (int) $cursor['__offset'] : 0;
			$sql    = 'SELECT ' . $select . ' FROM ' . $ident . ' LIMIT %d OFFSET %d';
			$rows   = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'jisento_export_read', sprintf( __( 'Unable to read rows from table %s. %s', 'jisento' ), $table, (string) $this->wpdb->last_error ) );
		}
		if ( ! $rows ) {
			return array(
				'done'   => true,
				'cursor' => null,
			);
		}
		$kinds  = array();
		$labels = array();
		if ( $pk ) {
			foreach ( $pk as $col ) {
				$kinds[]  = isset( $this->column_kinds[ $table ][ $col ] ) ? $this->column_kinds[ $table ][ $col ] : 'string';
				$labels[] = $col . ' ' . ( isset( $this->column_types[ $table ][ $col ] ) ? $this->column_types[ $table ][ $col ] : $kinds[ count( $kinds ) - 1 ] );
			}
			$previous = self::cursor_comparable( $cursor, $kinds );
			if ( is_wp_error( $previous ) ) {
				return $previous;
			}
			foreach ( $rows as $row ) {
				$current = self::comparable_tuple( $row, $pk, $kinds );
				if ( null === $current ) {
					return new \WP_Error( 'jisento_export_key', sprintf( __( 'Table %s returned a primary key that is not a valid integer, so the export stopped instead of comparing it as text.', 'jisento' ), $table ) );
				}
				if ( null !== $previous && self::key_follows( $previous, $current, $kinds ) < 1 ) {
					return new \WP_Error(
						'jisento_export_repeat',
						sprintf(
							/* translators: 1: table, 2: key description, 3: previous key, 4: next key */
							__( 'Table %1$s primary key (%2$s) repeated or went backwards. Previous: %3$s. Next: %4$s. The export stopped instead of writing that key twice.', 'jisento' ),
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

		$columns = array();
		$group   = array();
		foreach ( array_keys( $rows[0] ) as $col ) {
			$columns[] = '`' . $this->esc_ident( $col ) . '`';
		}
		$prefix = 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES ';
		$bytes  = strlen( $prefix );
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
			$bytes  += strlen( $tuple ) + 1;
			if ( count( $group ) >= 40 || $bytes >= 262144 ) {
				fwrite( $handle, $prefix . implode( ',', $group ) . ";\n" );
				$group = array();
				$bytes = strlen( $prefix );
			}
		}
		if ( $group ) {
			fwrite( $handle, $prefix . implode( ',', $group ) . ";\n" );
		}

		$done   = count( $rows ) < $limit;
		$cursor = null;
		if ( ! $done ) {
			$last = $rows[ count( $rows ) - 1 ];
			if ( $pk ) {
				$tuple = self::comparable_tuple( $last, $pk, $kinds );
				if ( null === $tuple ) {
					return new \WP_Error( 'jisento_export_key', sprintf( __( 'Table %s returned a primary key that is not a valid integer, so the export stopped instead of comparing it as text.', 'jisento' ), $table ) );
				}
				$cursor = self::pack_keyset( $tuple, $kinds );
			} else {
				$cursor = array( '__offset' => $offset + count( $rows ) );
			}
		}
		return array(
			'done'   => $done,
			'cursor' => $cursor,
		);
	}

	/**
	 * Drop bytes written after the last saved export cursor.
	 * A request can be killed after it appends SQL and before that cursor is stored.
	 *
	 * @param string $path      SQL file.
	 * @param int    $committed Byte length that matches the saved cursor.
	 * @return int
	 */
	public static function reconcile_sql_file( $path, $committed ) {
		$committed = max( 0, (int) $committed );
		if ( ! is_file( $path ) ) {
			return 0;
		}
		clearstatcache( true, $path );
		$size = (int) filesize( $path );
		if ( $size > $committed ) {
			$handle = fopen( $path, 'rb+' );
			if ( $handle ) {
				ftruncate( $handle, $committed );
				fclose( $handle );
			}
			clearstatcache( true, $path );
			$size = (int) filesize( $path );
		}
		return $size;
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
		if ( ! preg_match( '/^-?(0|[1-9][0-9]*)$/', $value ) ) {
			return null;
		}
		if ( '-0' === $value ) {
			return '0';
		}
		return $value;
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
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . $this->wpdb->_real_escape( (string) $value ) . "'";
	}

	private function esc_ident( $ident ) {
		return str_replace( '`', '', $ident );
	}
}
