<?php
/**
 * Chunked package upload: raw-body ranges, per-chunk SHA-256, and an exclusive lock.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Package;

use Jisento\Migration\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Upload_Session {

	const DEFAULT_CHUNK = 8388608;
	const MAX_CHUNK     = 33554432;
	const MIN_CHUNK     = 1048576;

	/**
	 * Clamp a preferred chunk size into [1 MiB, 32 MiB].
	 *
	 * @param int $preferred Preferred size.
	 * @return int
	 */
	public static function clamp_chunk( $preferred ) {
		$n = (int) $preferred;
		if ( $n < self::MIN_CHUNK ) {
			return self::MIN_CHUNK;
		}
		if ( $n > self::MAX_CHUNK ) {
			return self::MAX_CHUNK;
		}
		return $n;
	}

	/**
	 * Merge half-open ranges [start, end) and drop empties. Rejects overlaps that disagree.
	 *
	 * @param array $ranges List of [start, end].
	 * @return array|\WP_Error
	 */
	public static function merge_ranges( array $ranges ) {
		$clean = array();
		foreach ( $ranges as $r ) {
			if ( ! is_array( $r ) || count( $r ) < 2 ) {
				continue;
			}
			$a = (int) $r[0];
			$b = (int) $r[1];
			if ( $b <= $a || $a < 0 ) {
				return new \WP_Error( 'jisento_upload_range', __( 'Upload range is invalid.', 'jisento-migration' ), array( 'status' => 400 ) );
			}
			$clean[] = array( $a, $b );
		}
		usort(
			$clean,
			static function ( $x, $y ) {
				return $x[0] === $y[0] ? $x[1] - $y[1] : $x[0] - $y[0];
			}
		);
		$out = array();
		foreach ( $clean as $r ) {
			if ( ! $out ) {
				$out[] = $r;
				continue;
			}
			$last = count( $out ) - 1;
			if ( $r[0] < $out[ $last ][1] && $r[1] > $out[ $last ][1] && $r[0] > $out[ $last ][0] ) {
				// Partial overlap with different bounds: refuse (two writers disagreed).
				return new \WP_Error( 'jisento_upload_range', __( 'Upload ranges overlap. Retry the upload.', 'jisento-migration' ), array( 'status' => 409 ) );
			}
			if ( $r[0] <= $out[ $last ][1] ) {
				if ( $r[1] > $out[ $last ][1] ) {
					$out[ $last ][1] = $r[1];
				}
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Total unique bytes covered by the ranges.
	 *
	 * @param array $ranges Merged ranges.
	 * @return int
	 */
	public static function covered_bytes( array $ranges ) {
		$n = 0;
		foreach ( $ranges as $r ) {
			$n += (int) $r[1] - (int) $r[0];
		}
		return $n;
	}

	/**
	 * True when the ranges are exactly [0, $size) with no gaps or overlaps.
	 *
	 * @param array $ranges Ranges.
	 * @param int   $size   Expected size.
	 * @return bool
	 */
	public static function covers_exactly( array $ranges, $size ) {
		$size = (int) $size;
		if ( $size < 0 ) {
			return false;
		}
		if ( 0 === $size ) {
			return array() === $ranges;
		}
		$merged = self::merge_ranges( $ranges );
		if ( is_wp_error( $merged ) || 1 !== count( $merged ) ) {
			return false;
		}
		return 0 === (int) $merged[0][0] && $size === (int) $merged[0][1];
	}

	/**
	 * Paths for one upload id.
	 *
	 * @param string $upload_id Id.
	 * @return array{id:string,key:string,part:string,meta:string,lock:string}
	 */
	public static function paths( $upload_id ) {
		$upload_id = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $upload_id );
		$storage   = Plugin::instance()->storage;
		$dir_key   = 'temp/uploads/' . $upload_id;
		return array(
			'id'   => $upload_id,
			'key'  => $dir_key . '.part',
			'part' => $storage->get_path( $dir_key . '.part' ),
			'meta' => $storage->get_path( $dir_key . '.json' ),
			'lock' => $storage->get_path( $dir_key . '.lock' ),
		);
	}

	/**
	 * Delete a partial upload (part, meta, lock).
	 *
	 * @param string $upload_id Id.
	 * @return true|\WP_Error
	 */
	public static function discard( $upload_id ) {
		$paths = self::paths( $upload_id );
		if ( ! $paths['id'] ) {
			return new \WP_Error( 'jisento_upload', __( 'Upload id is missing.', 'jisento-migration' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'part', 'meta', 'lock' ) as $key ) {
			if ( ! empty( $paths[ $key ] ) && file_exists( $paths[ $key ] ) ) {
				@unlink( $paths[ $key ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		return true;
	}

	/**
	 * @param string $path Meta path.
	 * @return array|null
	 */
	public static function read_meta( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * Atomic meta write.
	 *
	 * @param string $path Meta path.
	 * @param array  $meta Meta.
	 * @return true|\WP_Error
	 */
	public static function write_meta( $path, array $meta ) {
		$json = wp_json_encode( $meta );
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Unable to save upload progress.', 'jisento-migration' ), array( 'status' => 500 ) );
		}
		$tmp = $path . '.tmp';
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'jisento_upload', __( 'Unable to save upload progress.', 'jisento-migration' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Hold the per-upload lock, run $fn, then release.
	 *
	 * @param array    $paths Paths from paths().
	 * @param callable $fn    function(): mixed|\WP_Error.
	 * @return mixed|\WP_Error
	 */
	public static function with_lock( array $paths, $fn ) {
		wp_mkdir_p( dirname( $paths['lock'] ) );
		$fh = @fopen( $paths['lock'], 'c+' );
		if ( ! $fh ) {
			return new \WP_Error( 'jisento_upload', __( 'Unable to lock the upload session.', 'jisento-migration' ), array( 'status' => 500 ) );
		}
		if ( ! flock( $fh, LOCK_EX ) ) {
			fclose( $fh );
			return new \WP_Error( 'jisento_upload', __( 'Unable to lock the upload session.', 'jisento-migration' ), array( 'status' => 500 ) );
		}
		try {
			return call_user_func( $fn );
		} finally {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/**
	 * Package file name that does not collide with an existing file, sidecar, or active job.
	 *
	 * @param string $name Suggested filename.
	 * @return string|\WP_Error
	 */
	public static function free_package_name( $name ) {
		$storage = Plugin::instance()->storage;
		$base    = preg_replace( '/\.jisento$/i', '', sanitize_file_name( (string) $name ) );
		if ( '' === $base ) {
			$base = 'upload';
		}
		$try = $base . '.jisento';
		for ( $n = 2; $n < 1000; $n++ ) {
			$path = $storage->get_path( 'packages/' . $try );
			if ( ! file_exists( $path ) && ! file_exists( $path . '.json' ) && ! self::package_in_use( 'packages/' . $try, $path ) ) {
				return $try;
			}
			$try = $base . '-' . $n . '.jisento';
		}
		return new \WP_Error( 'jisento_upload', __( 'No free package file name was found. Delete old packages and try again.', 'jisento-migration' ), array( 'status' => 409 ) );
	}

	/**
	 * @param string $key  Storage key.
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function package_in_use( $key, $path ) {
		$jobs = Plugin::instance()->jobs->list_running( 50 );
		foreach ( $jobs as $job ) {
			$state = is_array( $job->state ) ? $job->state : array();
			foreach ( array( 'package', 'package_abs', 'package_path' ) as $field ) {
				if ( empty( $state[ $field ] ) ) {
					continue;
				}
				$v = (string) $state[ $field ];
				if ( $v === $key || $v === $path || basename( $v ) === basename( $key ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
