<?php
/**
 * Per-stage migration timings stored on the job state.
 *
 * Each entry: start, end, seconds, bytes, throughput (bytes/sec).
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timings {

	/**
	 * Export tables at least this large get their own timing row.
	 */
	const LARGE_TABLE_BYTES = 1048576;

	/**
	 * @param array $state Job state (by ref).
	 */
	public static function ensure( array &$state ) {
		if ( ! isset( $state['timings'] ) || ! is_array( $state['timings'] ) ) {
			$state['timings'] = array();
		}
	}

	/**
	 * Start (or restart) a named stage.
	 *
	 * @param array  $state Job state.
	 * @param string $key   Stage key.
	 * @param int    $bytes Optional starting byte count.
	 */
	public static function begin( array &$state, $key, $bytes = 0 ) {
		self::ensure( $state );
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return;
		}
		$now = microtime( true );
		$state['timings'][ $key ] = array(
			'start'      => $now,
			'end'        => null,
			'seconds'    => 0.0,
			'bytes'      => max( 0, (int) $bytes ),
			'throughput' => 0.0,
		);
	}

	/**
	 * Close a stage started with begin().
	 *
	 * @param array    $state Job state.
	 * @param string   $key   Stage key.
	 * @param int|null $bytes Final bytes (null keeps existing).
	 */
	public static function end( array &$state, $key, $bytes = null ) {
		self::ensure( $state );
		$key = sanitize_key( (string) $key );
		if ( '' === $key || empty( $state['timings'][ $key ] ) || ! is_array( $state['timings'][ $key ] ) ) {
			return;
		}
		$entry = $state['timings'][ $key ];
		$now   = microtime( true );
		$start = isset( $entry['start'] ) ? (float) $entry['start'] : $now;
		$secs  = max( 0.0, $now - $start );
		if ( null !== $bytes ) {
			$entry['bytes'] = max( 0, (int) $bytes );
		}
		$entry['end']        = $now;
		$entry['seconds']    = round( $secs, 3 );
		$entry['throughput'] = ( $secs > 0 && ! empty( $entry['bytes'] ) ) ? round( (float) $entry['bytes'] / $secs, 1 ) : 0.0;
		$state['timings'][ $key ] = $entry;
	}

	/**
	 * Add elapsed seconds to a stage (for multi-request phases).
	 *
	 * @param array  $state   Job state.
	 * @param string $key     Stage key.
	 * @param float  $delta   Seconds to add.
	 * @param int    $bytes   Bytes to add.
	 */
	public static function add( array &$state, $key, $delta, $bytes = 0 ) {
		self::ensure( $state );
		$key = sanitize_key( (string) $key );
		if ( '' === $key || $delta <= 0 ) {
			return;
		}
		$entry = isset( $state['timings'][ $key ] ) ? self::normalize_entry( $state['timings'][ $key ] ) : array(
			'start'      => microtime( true ) - $delta,
			'end'        => null,
			'seconds'    => 0.0,
			'bytes'      => 0,
			'throughput' => 0.0,
		);
		$entry['seconds'] = round( (float) $entry['seconds'] + (float) $delta, 3 );
		$entry['bytes']   = (int) $entry['bytes'] + max( 0, (int) $bytes );
		$entry['end']     = microtime( true );
		if ( null === $entry['start'] ) {
			$entry['start'] = $entry['end'] - (float) $entry['seconds'];
		}
		$entry['throughput'] = ( $entry['seconds'] > 0 && $entry['bytes'] > 0 )
			? round( (float) $entry['bytes'] / (float) $entry['seconds'], 1 )
			: 0.0;
		$state['timings'][ $key ] = $entry;
	}

	/**
	 * Record transfer/upload aggregate stats.
	 *
	 * @param array $state    Job state.
	 * @param int   $requests Chunk request count.
	 * @param float $seconds  Total transfer seconds.
	 * @param int   $bytes    Bytes transferred.
	 */
	public static function set_transfer( array &$state, $requests, $seconds, $bytes ) {
		self::ensure( $state );
		$requests = max( 0, (int) $requests );
		$seconds  = max( 0.0, (float) $seconds );
		$bytes    = max( 0, (int) $bytes );
		$avg      = $requests > 0 ? round( $seconds / $requests, 3 ) : 0.0;
		$state['timings']['transfer'] = array(
			'start'                 => microtime( true ) - $seconds,
			'end'                   => microtime( true ),
			'seconds'               => round( $seconds, 3 ),
			'bytes'                 => $bytes,
			'throughput'            => ( $seconds > 0 && $bytes > 0 ) ? round( $bytes / $seconds, 1 ) : 0.0,
			'requests'              => $requests,
			'average_chunk_seconds' => $avg,
		);
	}

	/**
	 * @param mixed $entry Legacy float or structured array.
	 * @return array{start:float|null,end:float|null,seconds:float,bytes:int,throughput:float}
	 */
	public static function normalize_entry( $entry ) {
		if ( is_array( $entry ) ) {
			return array(
				'start'      => isset( $entry['start'] ) ? (float) $entry['start'] : null,
				'end'        => isset( $entry['end'] ) ? (float) $entry['end'] : null,
				'seconds'    => isset( $entry['seconds'] ) ? (float) $entry['seconds'] : 0.0,
				'bytes'      => isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0,
				'throughput' => isset( $entry['throughput'] ) ? (float) $entry['throughput'] : 0.0,
				'requests'   => isset( $entry['requests'] ) ? (int) $entry['requests'] : null,
				'average_chunk_seconds' => isset( $entry['average_chunk_seconds'] ) ? (float) $entry['average_chunk_seconds'] : null,
			);
		}
		$secs = (float) $entry;
		return array(
			'start'      => null,
			'end'        => null,
			'seconds'    => $secs,
			'bytes'      => 0,
			'throughput' => 0.0,
		);
	}

	/**
	 * Human duration: "12 s", "1 min 20 s", "2 h 5 min".
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0.0, (float) $seconds );
		if ( $seconds < 60 ) {
			$s = (int) round( $seconds );
			return $s . ' s';
		}
		if ( $seconds < 3600 ) {
			$m = (int) floor( $seconds / 60 );
			$s = (int) round( $seconds - ( $m * 60 ) );
			if ( 60 === $s ) {
				$m++;
				$s = 0;
			}
			return sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$d min %2$d s', 'jisento' ),
				$m,
				$s
			);
		}
		$h = (int) floor( $seconds / 3600 );
		$m = (int) round( ( $seconds - ( $h * 3600 ) ) / 60 );
		if ( 60 === $m ) {
			$h++;
			$m = 0;
		}
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '%1$d h %2$d min', 'jisento' ),
			$h,
			$m
		);
	}

	/**
	 * Short readable line for completion / history UI.
	 *
	 * @param string $key   Stage key.
	 * @param mixed  $entry Timing entry.
	 * @return string Empty when no useful duration.
	 */
	public static function human_line( $key, $entry ) {
		$entry = self::normalize_entry( $entry );
		if ( $entry['seconds'] <= 0 && empty( $entry['requests'] ) ) {
			return '';
		}
		$key   = (string) $key;
		$label = self::label_for( $key );
		if ( 'transfer' === $key && ! empty( $entry['requests'] ) ) {
			return sprintf(
				/* translators: 1: duration, 2: request count, 3: average chunk duration */
				__( 'Package transferred in %1$s (%2$d requests, avg chunk %3$s)', 'jisento' ),
				self::format_duration( $entry['seconds'] ),
				(int) $entry['requests'],
				self::format_duration( (float) $entry['average_chunk_seconds'] )
			);
		}
		if ( 0 === strpos( $key, 'table_' ) ) {
			$table = substr( $key, 6 );
			return sprintf(
				/* translators: 1: table name, 2: duration */
				__( 'Table %1$s exported in %2$s', 'jisento' ),
				$table,
				self::format_duration( $entry['seconds'] )
			);
		}
		return sprintf(
			/* translators: 1: stage label, 2: duration */
			__( '%1$s in %2$s', 'jisento' ),
			$label,
			self::format_duration( $entry['seconds'] )
		);
	}

	/**
	 * @param string $key Stage key.
	 * @return string
	 */
	public static function label_for( $key ) {
		$map = array(
			'validating'         => __( 'Package validated', 'jisento' ),
			'compatibility'      => __( 'Compatibility checked', 'jisento' ),
			'extracting'         => __( 'Package extracted', 'jisento' ),
			'importing_database' => __( 'Database restored', 'jisento' ),
			'importing_files'    => __( 'Files restored', 'jisento' ),
			'replacing_urls'     => __( 'URLs replaced', 'jisento' ),
			'finalizing'         => __( 'Migration finalized', 'jisento' ),
			'exporting_database' => __( 'Database exported', 'jisento' ),
			'exporting_files'    => __( 'Files exported', 'jisento' ),
			'packaging'          => __( 'Package built', 'jisento' ),
			'checksum'           => __( 'Checksum verified', 'jisento' ),
			'finalize'           => __( 'Export finalized', 'jisento' ),
			'transfer'           => __( 'Package transferred', 'jisento' ),
			'upload'             => __( 'Package uploaded', 'jisento' ),
		);
		$key = (string) $key;
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( 0 === strpos( $key, 'table_' ) ) {
			return sprintf(
				/* translators: %s: table name */
				__( 'Table %s exported', 'jisento' ),
				substr( $key, 6 )
			);
		}
		return ucwords( str_replace( '_', ' ', $key ) );
	}

	/**
	 * Ordered readable lines for UI and debug log.
	 *
	 * @param array $timings Timings map.
	 * @return string[]
	 */
	public static function readable_list( array $timings ) {
		$order = array(
			'validating',
			'compatibility',
			'extracting',
			'exporting_database',
			'exporting_files',
			'packaging',
			'checksum',
			'finalize',
			'transfer',
			'upload',
			'importing_database',
			'importing_files',
			'replacing_urls',
			'finalizing',
		);
		$lines = array();
		$seen  = array();
		foreach ( $timings as $key => $entry ) {
			if ( 0 === strpos( (string) $key, 'table_' ) ) {
				$line = self::human_line( $key, $entry );
				if ( '' !== $line ) {
					$lines[] = $line;
					$seen[ $key ] = true;
				}
			}
		}
		foreach ( $order as $key ) {
			if ( ! isset( $timings[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$line = self::human_line( $key, $timings[ $key ] );
			if ( '' !== $line ) {
				$lines[] = $line;
				$seen[ $key ] = true;
			}
		}
		foreach ( $timings as $key => $entry ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$line = self::human_line( $key, $entry );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * Seconds sum for report.total_seconds (legacy-compatible).
	 *
	 * @param array $timings Timings map.
	 * @return int
	 */
	public static function total_seconds( array $timings ) {
		$total = 0.0;
		foreach ( $timings as $key => $entry ) {
			if ( 0 === strpos( (string) $key, 'table_' ) ) {
				continue; // already covered by exporting_database aggregate when present
			}
			$entry  = self::normalize_entry( $entry );
			$total += (float) $entry['seconds'];
		}
		return (int) round( $total );
	}
}
