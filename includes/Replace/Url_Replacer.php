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

	public function __construct() {
		$this->serializer = new Serializer();
	}

	public function replace_all( $source_url, $dest_url, $time_budget = 8, $state = array() ) {
		global $wpdb;

		$replacements = Serializer::build_replacements( $source_url, $dest_url );
		if ( empty( $replacements ) ) {
			return array(
				'done'    => true,
				'updated' => isset( $state['updated'] ) ? (int) $state['updated'] : 0,
				'table'   => '',
				'offset'  => 0,
			);
		}

		$tables = isset( $state['tables'] ) ? $state['tables'] : $this->discover_text_tables();
		$index  = isset( $state['index'] ) ? (int) $state['index'] : 0;
		$offset = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
		$updated = isset( $state['updated'] ) ? (int) $state['updated'] : 0;
		$start  = time();

		while ( $index < count( $tables ) ) {
			if ( ( time() - $start ) >= $time_budget ) {
				return array(
					'done'    => false,
					'updated' => $updated,
					'tables'  => $tables,
					'index'   => $index,
					'offset'  => $offset,
					'table'   => $tables[ $index ]['name'],
				);
			}

			$table   = $tables[ $index ];
			$primary = $table['primary'];
			$columns = $table['columns'];
			$name    = $table['name'];

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT `' . $this->esc( $primary ) . '`, `' . implode( '`,`', array_map( array( $this, 'esc' ), $columns ) ) . '` FROM `' . $this->esc( $name ) . '` ORDER BY `' . $this->esc( $primary ) . '` LIMIT %d OFFSET %d',
					80,
					$offset
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! $rows ) {
				$index++;
				$offset = 0;
				continue;
			}

			foreach ( $rows as $row ) {
				$id      = $row[ $primary ];
				$changed = false;
				$set     = array();
				$format  = array();
				foreach ( $columns as $column ) {
					$original = $row[ $column ];
					if ( null === $original || '' === $original ) {
						continue;
					}
					$new = $this->serializer->replace( $original, $replacements );
					if ( $new !== $original ) {
						$set[ $column ] = $new;
						$format[]       = '%s';
						$changed        = true;
					}
				}
				if ( $changed ) {
					$wpdb->update( $name, $set, array( $primary => $id ), $format, is_numeric( $id ) ? array( '%d' ) : array( '%s' ) );
					$updated++;
				}
			}

			$offset += count( $rows );
			if ( count( $rows ) < 80 ) {
				$index++;
				$offset = 0;
			}
		}

		update_option( 'siteurl', $dest_url );
		update_option( 'home', $dest_url );

		return array(
			'done'    => true,
			'updated' => $updated,
			'tables'  => $tables,
			'index'   => $index,
			'offset'  => 0,
			'table'   => '',
		);
	}

	private function discover_text_tables() {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$out    = array();

		foreach ( $tables as $table ) {
			if ( 0 === strpos( substr( $table, strlen( $prefix ) ), 'jisento_' ) ) {
				continue;
			}
			$cols    = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $this->esc( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$text    = array();
			$primary = '';
			foreach ( $cols as $col ) {
				$type = strtolower( $col['Type'] );
				if ( 'PRI' === $col['Key'] && ! $primary ) {
					$primary = $col['Field'];
				}
				if ( preg_match( '/char|text|blob|json/i', $type ) ) {
					$text[] = $col['Field'];
				}
			}
			if ( $primary && $text ) {
				$out[] = array(
					'name'    => $table,
					'primary' => $primary,
					'columns' => $text,
				);
			}
		}

		return $out;
	}

	private function esc( $ident ) {
		return str_replace( '`', '', $ident );
	}
}
