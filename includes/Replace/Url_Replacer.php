<?php
/**
 * URL replacement across WordPress tables with serialized-data safety.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Replace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Url_Replacer {

	/**
	 * @var Serializer
	 */
	private $serializer;

	/**
	 * @var int
	 */
	private $batch_size;

	public function __construct( $batch_size = 200 ) {
		$this->serializer = new Serializer();
		$this->batch_size = max( 1, (int) $batch_size );
	}

	/**
	 * Replace the source URL with the destination URL in every text column, resumably.
	 *
	 * @param string $source_url  Source site URL.
	 * @param string $dest_url    Destination site URL.
	 * @param int    $time_budget Seconds to work before returning a resumable state (at least one batch always runs).
	 * @param array  $state       State returned by a previous call, or empty to start.
	 * @param array  $options     only_tables (string[]), replace_guids (bool), replace_emails (bool, default true); also read from $state.
	 * @return array done, updated, tables, index, table, offset, cursor, skipped_tables, skipped_values, emails_updated.
	 * @throws \RuntimeException When a query fails.
	 */
	public function replace_all( $source_url, $dest_url, $time_budget = 8, $state = array(), array $options = array() ) {
		$state   = is_array( $state ) ? $state : array();
		$options = array_merge(
			array(
				'only_tables'         => isset( $state['only_tables'] ) ? $state['only_tables'] : null,
				'replace_guids'       => ! empty( $state['replace_guids'] ),
				'replace_emails'      => array_key_exists( 'replace_emails', $state ) ? ! empty( $state['replace_emails'] ) : true,
				'extra_replacements'  => isset( $state['extra_replacements'] ) && is_array( $state['extra_replacements'] ) ? $state['extra_replacements'] : array(),
				'skip_url_replace'    => ! empty( $state['skip_url_replace'] ),
			),
			$options
		);
		if ( ! array_key_exists( 'replace_emails', $options ) ) {
			$options['replace_emails'] = true;
		} else {
			$options['replace_emails'] = ! empty( $options['replace_emails'] );
		}
		if ( ! isset( $options['extra_replacements'] ) || ! is_array( $options['extra_replacements'] ) ) {
			$options['extra_replacements'] = array();
		}

		$updated        = isset( $state['updated'] ) ? (int) $state['updated'] : 0;
		$skipped_values = isset( $state['skipped_values'] ) ? (int) $state['skipped_values'] : 0;
		$emails_updated = isset( $state['emails_updated'] ) ? (int) $state['emails_updated'] : 0;
		$paths_updated  = isset( $state['paths_updated'] ) ? (int) $state['paths_updated'] : 0;
		$skipped_tables = ( isset( $state['skipped_tables'] ) && is_array( $state['skipped_tables'] ) ) ? $state['skipped_tables'] : array();

		if ( ! empty( $options['skip_url_replace'] ) ) {
			$replacements = array();
			$email_from   = array();
		} else {
			$replacements = Serializer::build_replacements( $source_url, $dest_url, $options['replace_emails'] );
			$email_from   = $options['replace_emails'] ? array_keys( Serializer::email_forms( $source_url, $dest_url ) ) : array();
		}
		$path_keys = array();
		if ( $options['extra_replacements'] ) {
			foreach ( $options['extra_replacements'] as $from => $to ) {
				$replacements[ (string) $from ] = (string) $to;
				$path_keys[ (string) $from ]    = true;
			}
			uksort(
				$replacements,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);
		}
		if ( empty( $replacements ) ) {
			$tables = ( isset( $state['tables'] ) && is_array( $state['tables'] ) ) ? $state['tables'] : array();
			return $this->state( true, $updated, $tables, count( $tables ), null, $skipped_tables, $skipped_values, $emails_updated, $paths_updated );
		}

		if ( isset( $state['tables'] ) && is_array( $state['tables'] ) ) {
			$tables = $this->normalize_tables( $state['tables'], $options, $skipped_tables );
		} else {
			$tables = $this->discover_text_tables( $options, $skipped_tables );
		}
		$index    = isset( $state['index'] ) ? (int) $state['index'] : 0;
		$cursor   = ( isset( $state['cursor'] ) && is_array( $state['cursor'] ) ) ? $state['cursor'] : null;
		$baseline = $this->serializer->skipped_count();
		$start    = time();
		$batches  = 0;
		$count    = count( $tables );

		while ( $index < $count ) {
			if ( $batches > 0 && ( time() - $start ) >= $time_budget ) {
				$skipped_values += $this->serializer->skipped_count() - $baseline;
				return $this->state( false, $updated, $tables, $index, $cursor, $skipped_tables, $skipped_values, $emails_updated, $paths_updated );
			}

			$table = $tables[ $index ];
			if ( empty( $table['key'] ) || empty( $table['columns'] ) ) {
				$index++;
				$cursor = null;
				continue;
			}

			$rows = $this->fetch_batch( $table, $cursor );
			$batches++;
			$path_probe = $path_keys ? array_fill_keys( array_keys( $path_keys ), true ) : array();
			foreach ( $rows as $row ) {
				$had_path = false;
				if ( $path_probe ) {
					foreach ( $row as $value ) {
						if ( is_string( $value ) && $this->serializer->contains_source( $value, $path_probe ) ) {
							$had_path = true;
							break;
						}
					}
				}
				$result          = $this->replace_row( $table, $row, $replacements, $email_from );
				$updated        += $result['updated'];
				$emails_updated += $result['emails'];
				if ( $result['updated'] && $had_path ) {
					$paths_updated++;
				}
			}

			if ( count( $rows ) < $this->batch_size ) {
				$index++;
				$cursor = null;
			} else {
				$cursor = $this->encode_cursor( $table, $rows[ count( $rows ) - 1 ] );
			}
		}

		$skipped_values += $this->serializer->skipped_count() - $baseline;
		if ( empty( $options['skip_url_replace'] ) && is_string( $dest_url ) && '' !== $dest_url ) {
			update_option( 'siteurl', $dest_url );
			update_option( 'home', $dest_url );
		}
		return $this->state( true, $updated, $tables, $index, null, $skipped_tables, $skipped_values, $emails_updated, $paths_updated );
	}

	/**
	 * Tables that belong to this install: prefixed, not plugin job tables, and not another
	 * install whose prefix merely starts with ours (e.g. wp_staging_ next to wp_).
	 *
	 * @param string[] $all_tables Table names.
	 * @param string   $prefix     This install's table prefix.
	 * @return string[]
	 */
	public static function own_tables( array $all_tables, $prefix ) {
		$prefix = (string) $prefix;
		$names  = array();
		foreach ( $all_tables as $table ) {
			$table = (string) $table;
			if ( '' === $prefix || 0 === strpos( $table, $prefix ) ) {
				$names[] = $table;
			}
		}

		$lookup    = array_flip( $names );
		$multisite = isset( $lookup[ $prefix . 'blogs' ] );
		$foreign   = array();
		foreach ( $names as $table ) {
			if ( strlen( $table ) <= 7 || 'options' !== substr( $table, -7 ) ) {
				continue;
			}
			$other = substr( $table, 0, -7 );
			if ( $other === $prefix || ( '' !== $prefix && 0 !== strpos( $other, $prefix ) ) ) {
				continue;
			}
			if ( ! isset( $lookup[ $other . 'posts' ] ) ) {
				continue;
			}
			if ( $multisite && preg_match( '/^\d+_$/', substr( $other, strlen( $prefix ) ) ) ) {
				continue;
			}
			$foreign[] = $other;
		}

		$out = array();
		foreach ( $names as $table ) {
			if ( 0 === strpos( $table, $prefix . 'jisento_' ) ) {
				continue;
			}
			foreach ( $foreign as $other ) {
				if ( 0 === strpos( $table, $other ) ) {
					continue 2;
				}
			}
			$out[] = $table;
		}

		return $out;
	}

	private function state( $done, $updated, array $tables, $index, $cursor, array $skipped_tables, $skipped_values, $emails_updated = 0, $paths_updated = 0 ) {
		return array(
			'done'           => (bool) $done,
			'updated'        => (int) $updated,
			'tables'         => $tables,
			'index'          => (int) $index,
			'table'          => ( ! $done && isset( $tables[ $index ]['name'] ) ) ? $tables[ $index ]['name'] : '',
			'offset'         => 0,
			'cursor'         => $cursor,
			'skipped_tables' => $skipped_tables,
			'skipped_values' => (int) $skipped_values,
			'emails_updated' => (int) $emails_updated,
			'paths_updated'  => (int) $paths_updated,
		);
	}

	private function fetch_batch( array $table, $cursor ) {
		global $wpdb;

		$keys = array_map( array( $this, 'ident' ), $table['key'] );
		$sql  = 'SELECT ' . implode( ', ', array_map( array( $this, 'ident' ), array_merge( $table['key'], $table['columns'] ) ) ) . ' FROM ' . $this->ident( $table['name'] );
		$args = array();

		if ( null !== $cursor ) {
			if ( count( $cursor ) !== count( $table['key'] ) ) {
				throw new \RuntimeException(esc_html( 'URL replacement cursor does not match the key of table ' . $table['name'] . ' (key: ' . implode( ', ', $table['key'] ) . ')' ));
			}
			$placeholders = array();
			foreach ( array_values( $cursor ) as $value ) {
				if ( is_int( $value ) ) {
					$placeholders[] = '%d';
					$args[]         = $value;
					continue;
				}
				if ( ! is_string( $value ) || ! preg_match( '/^(?:[0-9a-fA-F]{2})*$/', $value ) ) {
					throw new \RuntimeException(esc_html( 'URL replacement cursor is corrupt for table ' . $table['name'] . ' (key: ' . implode( ', ', $table['key'] ) . ')' ));
				}
				$placeholders[] = '%s';
				$args[]         = (string) hex2bin( $value );
			}
			if ( 1 === count( $keys ) ) {
				$sql .= ' WHERE ' . $keys[0] . ' > ' . $placeholders[0];
			} else {
				$sql .= ' WHERE (' . implode( ', ', $keys ) . ') > (' . implode( ', ', $placeholders ) . ')';
			}
		}

		$sql   .= ' ORDER BY ' . implode( ', ', $keys ) . ' LIMIT %d';
		$args[] = $this->batch_size;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException(esc_html( 'URL replacement failed reading table ' . $table['name'] . ' (key: ' . implode( ', ', $table['key'] ) . '): ' . $wpdb->last_error ));
		}

		return $rows;
	}

	private function replace_row( array $table, array $row, array $replacements, array $email_from = array() ) {
		global $wpdb;

		$set    = array();
		$args   = array();
		$emails = 0;
		foreach ( $table['columns'] as $column ) {
			if ( ! isset( $row[ $column ] ) || '' === $row[ $column ] ) {
				continue;
			}
			$before = (string) $row[ $column ];
			$new    = $this->serializer->replace( $before, $replacements );
			if ( $new !== $before ) {
				$set[]  = $this->ident( $column ) . ' = %s';
				$args[] = $new;
				foreach ( $email_from as $from ) {
					$emails += max( 0, substr_count( $before, $from ) - substr_count( $new, $from ) );
				}
			}
		}
		if ( ! $set ) {
			return array(
				'updated' => 0,
				'emails'  => 0,
			);
		}

		$where = array();
		foreach ( $table['key'] as $column ) {
			$value = (string) $row[ $column ];
			if ( $this->is_int_key( $table, $column, $value ) ) {
				$where[] = $this->ident( $column ) . ' = %d';
				$args[]  = (int) $value;
			} else {
				$where[] = $this->ident( $column ) . ' = %s';
				$args[]  = $value;
			}
		}

		$sql    = 'UPDATE ' . $this->ident( $table['name'] ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $where );
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			throw new \RuntimeException(esc_html( 'URL replacement failed updating table ' . $table['name'] . ' (key: ' . implode( ', ', $table['key'] ) . '): ' . $wpdb->last_error ));
		}

		return array(
			'updated' => ( (int) $result > 0 ) ? 1 : 0,
			'emails'  => $emails,
		);
	}

	private function encode_cursor( array $table, array $row ) {
		$cursor = array();
		foreach ( $table['key'] as $column ) {
			$value    = (string) $row[ $column ];
			$cursor[] = $this->is_int_key( $table, $column, $value ) ? (int) $value : bin2hex( $value );
		}
		return $cursor;
	}

	private function is_int_key( array $table, $column, $value ) {
		return ! empty( $table['int_keys'] ) && in_array( $column, $table['int_keys'], true ) && preg_match( '/^-?\d+$/', $value ) && (string) (int) $value === $value;
	}

	private function normalize_tables( array $tables, array $options, array &$skipped ) {
		$out = array();
		foreach ( $tables as $table ) {
			if ( isset( $table['key'], $table['columns'] ) && is_array( $table['key'] ) ) {
				$out[] = $table;
				continue;
			}
			$name  = isset( $table['name'] ) ? (string) $table['name'] : '';
			$fresh = '' !== $name ? $this->describe_table( $name, $options, $skipped ) : null;
			$out[] = $fresh ? $fresh : array(
				'name'     => $name,
				'key'      => array(),
				'int_keys' => array(),
				'columns'  => array(),
			);
		}
		return $out;
	}

	private function discover_text_tables( array $options, array &$skipped ) {
		global $wpdb;

		if ( is_array( $options['only_tables'] ) ) {
			$existing = $wpdb->get_col( 'SHOW TABLES' );
			$this->assert_no_error( 'listing tables' );
			$wanted = array();
			foreach ( $options['only_tables'] as $name ) {
				$wanted[] = (string) $name;
			}
			$names = array_values( array_intersect( array_unique( $wanted ), (array) $existing ) );
		} else {
			$like  = $wpdb->esc_like( $wpdb->prefix ) . '%';
			$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			$this->assert_no_error( 'listing tables' );
			$names = self::own_tables( (array) $found, $wpdb->prefix );
		}

		$out = array();
		foreach ( $names as $name ) {
			$table = $this->describe_table( $name, $options, $skipped );
			if ( $table ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	private function describe_table( $name, array $options, array &$skipped ) {
		global $wpdb;

		$cols = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $this->esc( $name ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assert_no_error( 'reading columns of ' . $name );
		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . $this->esc( $name ) . '` WHERE Non_unique = 0', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assert_no_error( 'reading keys of ' . $name );

		$not_null = array();
		$int_cols = array();
		$text     = array();
		$skip     = array();
		if ( $name === $wpdb->prefix . 'posts' && empty( $options['replace_guids'] ) ) {
			$skip[] = 'guid';
		}
		foreach ( (array) $cols as $col ) {
			$field = $col['Field'];
			$type  = strtolower( (string) $col['Type'] );
			if ( 'NO' === $col['Null'] ) {
				$not_null[ $field ] = true;
			}
			if ( preg_match( '/^(?:tiny|small|medium|big)?int\b/', $type ) ) {
				$int_cols[] = $field;
			}
			$collation = isset( $col['Collation'] ) ? strtolower( (string) $col['Collation'] ) : '';
			if ( preg_match( '/^(?:(?:var)?char|(?:tiny|medium|long)?text|json)\b/', $type ) && 'binary' !== $collation && ! in_array( $field, $skip, true ) ) {
				$text[] = $field;
			}
		}

		$indexes = array();
		foreach ( (array) $keys as $key ) {
			$indexes[ $key['Key_name'] ][ (int) $key['Seq_in_index'] ] = $key['Column_name'];
		}
		$key_cols = array();
		if ( isset( $indexes['PRIMARY'] ) ) {
			ksort( $indexes['PRIMARY'] );
			$key_cols = array_values( $indexes['PRIMARY'] );
		} else {
			foreach ( $indexes as $columns ) {
				ksort( $columns );
				$usable = true;
				foreach ( $columns as $column ) {
					if ( empty( $not_null[ $column ] ) ) {
						$usable = false;
						break;
					}
				}
				if ( $usable ) {
					$key_cols = array_values( $columns );
					break;
				}
			}
		}

		$text = array_values( array_diff( $text, $key_cols ) );
		if ( ! $text ) {
			return null;
		}
		if ( ! $key_cols ) {
			$skipped[ $name ] = 'No primary key or NOT NULL unique index; URLs in this table were not replaced.';
			return null;
		}

		return array(
			'name'     => $name,
			'key'      => $key_cols,
			'int_keys' => array_values( array_intersect( $key_cols, $int_cols ) ),
			'columns'  => $text,
		);
	}

	private function assert_no_error( $action ) {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException(esc_html( 'URL replacement failed ' . $action . ': ' . $wpdb->last_error ));
		}
	}

	private function ident( $ident ) {
		return '`' . str_replace( array( '`', '%' ), array( '', '%%' ), $ident ) . '`';
	}

	private function esc( $ident ) {
		return str_replace( '`', '', $ident );
	}
}
