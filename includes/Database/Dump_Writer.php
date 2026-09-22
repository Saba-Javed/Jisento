<?php
/**
 * Writes the database dump as numbered segments.
 *
 * Each call writes database/part-NNNNN.sql.partial, flushes and fsyncs it, renames it to
 * .sql, and only then returns the new cursor for the caller to save. A killed request
 * leaves at most a .partial file, which the next call deletes. Nothing is ever truncated.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dump_Writer {

	const MAX_SEGMENT_BYTES = 67108864;

	/**
	 * @var Database_Exporter
	 */
	private $exporter;

	/**
	 * @var string
	 */
	private $dir;

	/**
	 * @param string                 $dir      Directory for part-NNNNN.sql files.
	 * @param Database_Exporter|null $exporter Exporter.
	 */
	public function __construct( $dir, $exporter = null ) {
		$this->dir      = rtrim( (string) $dir, '/\\' );
		$this->exporter = $exporter ? $exporter : new Database_Exporter();
	}

	/**
	 * @param string[] $tables Tables to export.
	 * @return array Initial dump state.
	 */
	public static function initial_state( array $tables ) {
		return array(
			'tables'         => array_values( $tables ),
			'index'          => 0,
			'cursor'         => null,
			'structure_done' => false,
			'seq'            => 1,
			'segments'       => array(),
			'table_stats'    => array(),
			'done'           => ! $tables,
		);
	}

	public static function segment_name( $seq ) {
		return sprintf( 'part-%05d.sql', (int) $seq );
	}

	/**
	 * Remove files an interrupted request left behind: every .partial, and any finished
	 * segment the saved state does not list.
	 *
	 * @param array $state Dump state.
	 */
	public function recover( array $state ) {
		if ( ! is_dir( $this->dir ) ) {
			return;
		}
		$known = array();
		foreach ( $state['segments'] as $segment ) {
			$known[ basename( $segment['entry'] ) ] = true;
		}
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( ! preg_match( '/^part-\d{5}\.sql(\.partial)?$/', (string) $file ) ) {
				continue;
			}
			if ( '.partial' === substr( $file, -8 ) || ! isset( $known[ $file ] ) ) {
				@unlink( $this->dir . '/' . $file );
			}
		}
	}

	/**
	 * Write one segment.
	 *
	 * @param array $state       Dump state.
	 * @param float $time_budget Seconds.
	 * @param int   $page_rows   Rows per page.
	 * @return array|\WP_Error New state.
	 */
	public function step( array $state, $time_budget = 8, $page_rows = 500 ) {
		if ( ! empty( $state['done'] ) ) {
			return $state;
		}
		if ( ! is_dir( $this->dir ) && ! wp_mkdir_p( $this->dir ) ) {
			return new \WP_Error( 'jisento_export_dir', sprintf( __( 'Unable to create the database dump folder %s.', 'jisento' ), $this->dir ) );
		}
		$this->recover( $state );
		$name    = self::segment_name( $state['seq'] );
		$final   = $this->dir . '/' . $name;
		$partial = $final . '.partial';
		$handle  = fopen( $partial, 'xb' );
		if ( ! $handle ) {
			return new \WP_Error( 'jisento_export_write', sprintf( __( 'Unable to create database segment %s.', 'jisento' ), $name ) );
		}
		$hash    = hash_init( 'sha256' );
		$bytes   = strlen( Database_Exporter::header_sql( $GLOBALS['wpdb']->prefix ) );
		$started = microtime( true );
		$wrote   = $this->exporter->write_header( $handle, $hash );
		$next    = $state;
		if ( ! is_wp_error( $wrote ) ) {
			$wrote = $this->write_tables( $handle, $hash, $next, $bytes, $started, $time_budget, $page_rows );
		}
		if ( is_wp_error( $wrote ) ) {
			fclose( $handle );
			@unlink( $partial );
			return $wrote;
		}
		$flushed = fflush( $handle );
		if ( $flushed && function_exists( 'fsync' ) ) {
			$flushed = fsync( $handle );
		}
		fclose( $handle );
		if ( ! $flushed ) {
			@unlink( $partial );
			return new \WP_Error( 'jisento_export_write', sprintf( __( 'Database segment %s could not be flushed to disk.', 'jisento' ), $name ) );
		}
		clearstatcache( true, $partial );
		if ( (int) filesize( $partial ) !== $bytes ) {
			@unlink( $partial );
			return new \WP_Error( 'jisento_export_write', sprintf( __( 'Database segment %1$s has %2$d bytes on disk but %3$d were written.', 'jisento' ), $name, (int) filesize( $partial ), $bytes ) );
		}
		if ( is_file( $final ) ) {
			@unlink( $final );
		}
		if ( ! @rename( $partial, $final ) ) {
			@unlink( $partial );
			return new \WP_Error( 'jisento_export_write', sprintf( __( 'Database segment %s could not be renamed into place.', 'jisento' ), $name ) );
		}
		$next['segments'][] = array(
			'entry'  => 'database/' . $name,
			'bytes'  => $bytes,
			'sha256' => hash_final( $hash ),
		);
		$next['seq'] = (int) $state['seq'] + 1;
		$next['done'] = $next['index'] >= count( $next['tables'] );
		return $next;
	}

	private function write_tables( $handle, $hash, array &$state, &$bytes, $started, $time_budget, $page_rows ) {
		$pages = 0;
		while ( $state['index'] < count( $state['tables'] ) ) {
			if ( $pages > 0 && ( microtime( true ) - $started >= $time_budget || $bytes >= self::MAX_SEGMENT_BYTES ) ) {
				break;
			}
			$table = $state['tables'][ $state['index'] ];
			if ( ! isset( $state['table_stats'][ $table ] ) ) {
				$key = $this->exporter->key_columns( $table );
				$state['table_stats'][ $table ] = array(
					'rows'   => 0,
					'bytes'  => 0,
					'sha256' => '',
					'key'    => $key['source'],
					'stable' => (bool) $key['stable'],
				);
			}
			$stats = &$state['table_stats'][ $table ];
			if ( empty( $state['structure_done'] ) ) {
				$structure = $this->exporter->export_table_structure( $handle, $table, $hash );
				if ( is_wp_error( $structure ) ) {
					return $structure;
				}
				$bytes                  += $structure['bytes'];
				$stats['bytes']         += $structure['bytes'];
				$state['structure_done'] = true;
				$state['cursor']         = null;
			}
			$page = $this->exporter->export_table_rows( $handle, $table, $state['cursor'], $page_rows, $hash );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$pages++;
			$bytes          += $page['bytes'];
			$stats['bytes'] += $page['bytes'];
			$stats['rows']  += $page['rows'];
			if ( $page['rows'] > 0 ) {
				$stats['sha256'] = hash( 'sha256', $stats['sha256'] . $page['sha256'] );
			}
			unset( $stats );
			if ( $page['done'] ) {
				$state['index']++;
				$state['cursor']         = null;
				$state['structure_done'] = false;
			} else {
				$state['cursor'] = $page['cursor'];
			}
		}
		return true;
	}
}
