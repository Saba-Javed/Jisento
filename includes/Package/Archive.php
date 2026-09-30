<?php
/**
 * .jisento package writer/reader.
 *
 * Container: ZIP. The "JISENTO" entry is a format marker: it names the layout version so the
 * importer knows how to read the package. It is a constant string and proves nothing about who
 * built the package; package authenticity is not verified by this plugin.
 *
 * v2 layout:
 *   JISENTO                     format marker + package version
 *   manifest.json               package_version, plugin_version, database.segments[] (entry, bytes, sha256),
 *                               database.tables{} (rows, bytes, sha256, key, stable), file_count, files_bytes
 *   database/part-NNNNN.sql     database dump segments
 *   files/...                   site files
 *   checksums/entries.jsonl     per-entry digests (name, size, CRC-32, chunked SHA-256) + content_sha256
 *
 * v1 layout (read only): JISENTO, manifest.json, checksums.json, database/database.sql, files/...
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Package;

use Jisento\Migration\Security\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Large migration package streams cannot use WP_Filesystem.


class Archive {

	const SEGMENT_PATTERN = '#^database/part-\d{5}\.sql$#';

	public static function zip_available() {
		return class_exists( 'ZipArchive' );
	}

	public static function marker_contents() {
		return JISENTO_FORMAT_MARKER . "\n" . JISENTO_PACKAGE_VERSION . "\n" . hash( 'sha256', JISENTO_MAGIC . JISENTO_PACKAGE_VERSION );
	}

	/**
	 * @param string|false $contents JISENTO entry.
	 * @return int 2, 1, or 0 when the marker is not a Jisento one.
	 */
	public static function format_of( $contents ) {
		if ( ! is_string( $contents ) ) {
			return 0;
		}
		$first = strtok( $contents, "\n" );
		if ( JISENTO_FORMAT_MARKER === $first ) {
			return 2;
		}
		if ( JISENTO_FORMAT_MARKER_V1 === $first ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Manifest as stored in manifest.json.
	 *
	 * @param array $manifest Manifest data.
	 * @return string
	 */
	public static function encode_manifest( array $manifest ) {
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new \RuntimeException(\esc_html__( 'The package manifest could not be encoded as JSON.', 'jisento-migration' ));
		}
		return $json;
	}

	/**
	 * Read the format marker and manifest. Does not read file contents.
	 *
	 * @param string $zip_path Package.
	 * @return array|\WP_Error format, legacy, manifest, checksums, has_db, has_files, size.
	 */
	public function inspect( $zip_path ) {
		if ( ! is_readable( $zip_path ) ) {
			return new \WP_Error( 'jisento_unreadable', \esc_html__( 'The package file cannot be read.', 'jisento-migration' ));
		}
		if ( ! self::zip_available() ) {
			return new \WP_Error( 'jisento_no_zip', \esc_html__( 'The PHP ZipArchive extension is required.', 'jisento-migration' ));
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error(
				'jisento_invalid_package', \esc_html__( 'Invalid Jisento Package. The file is not a readable ZIP container.', 'jisento-migration' ));
		}

		$format = self::format_of( $zip->getFromName( 'JISENTO' ) );
		if ( 0 === $format ) {
			$zip->close();
			return new \WP_Error(
				'jisento_invalid_package', \esc_html__( 'Invalid Jisento Package. The format marker is missing, so this file was not created by Jisento Migration or was created by a newer version.', 'jisento-migration' ));
		}

		$manifest  = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		$checksums = 1 === $format ? json_decode( (string) $zip->getFromName( 'checksums.json' ), true ) : array();

		if ( ! is_array( $manifest ) || empty( $manifest['package_version'] ) ) {
			$zip->close();
			return new \WP_Error( 'jisento_bad_manifest', \esc_html__( 'The package manifest is missing or invalid.', 'jisento-migration' ));
		}
		$major = (int) $manifest['package_version'];
		if ( $major !== $format ) {
			$zip->close();
			/* translators: %1$d, %2$s: runtime values. */
			return new \WP_Error( 'jisento_bad_manifest', \esc_html( sprintf( __( 'The package format marker (v%1$d) does not match its manifest (package_version %2$s).', 'jisento-migration' ), $format, self::printable( (string) $manifest['package_version'] ) ) ));
		}

		if ( 2 === $format ) {
			$segments = isset( $manifest['database']['segments'] ) && is_array( $manifest['database']['segments'] ) ? $manifest['database']['segments'] : array();
			$has_db   = count( $segments ) > 0;
		} else {
			$has_db = false !== $zip->locateName( 'database/database.sql' );
		}
		$has_files = $this->has_files_prefix( $zip );
		$zip->close();

		return array(
			'format'    => $format,
			'legacy'    => 1 === $format,
			'manifest'  => $manifest,
			'checksums' => is_array( $checksums ) ? $checksums : array(),
			'has_db'    => $has_db,
			'has_files' => $has_files,
			'size'      => filesize( $zip_path ),
		);
	}

	private function has_files_prefix( \ZipArchive $zip ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 === strpos( (string) $name, 'files/' ) && '/' !== substr( (string) $name, -1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Structural check before anything is extracted or restored: manifest fields, every database
	 * segment present with the recorded size, and the files/ entry count and bytes match the manifest.
	 * Segment hashes are checked while each segment is extracted (extract_verified), still before
	 * any table is created.
	 *
	 * @param string $zip_path Package.
	 * @return array|\WP_Error inspect() result plus segments (v2) or legacy_sql (v1).
	 */
	public function verify_structure( $zip_path ) {
		$info = $this->inspect( $zip_path );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$manifest = $info['manifest'];
		$zip      = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', \esc_html__( 'Unable to open the package.', 'jisento-migration' ));
		}

		$file_entries = 0;
		$file_bytes   = 0;
		$bad_names    = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $stat ? (string) $stat['name'] : '';
			if ( 0 !== strpos( $name, 'files/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}
			if ( is_wp_error( Guard::sanitize_archive_path( substr( $name, 6 ) ) ) ) {
				$bad_names[] = self::printable( $name );
				continue;
			}
			$file_entries++;
			$file_bytes += (int) $stat['size'];
		}
		if ( $bad_names ) {
			$zip->close();
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_bad_path', \esc_html( sprintf( __( 'The package contains unsafe paths (absolute or with ".."), so it was rejected: %s', 'jisento-migration' ), implode( ', ', array_slice( $bad_names, 0, 5 ) ) ) ));
		}

		if ( 2 === $info['format'] ) {
			foreach ( array( 'package_version', 'plugin_version', 'home_url', 'contents' ) as $field ) {
				if ( empty( $manifest[ $field ] ) || ! is_string( $manifest[ $field ] ) ) {
					$zip->close();
					/* translators: %s: runtime values. */
					return new \WP_Error( 'jisento_bad_manifest', \esc_html( sprintf( __( 'The package manifest has no "%s" value.', 'jisento-migration' ), $field ) ));
				}
			}
			foreach ( array( 'file_count', 'files_bytes' ) as $field ) {
				if ( ! isset( $manifest[ $field ] ) || ! is_int( $manifest[ $field ] ) || $manifest[ $field ] < 0 ) {
					$zip->close();
					/* translators: %s: runtime values. */
					return new \WP_Error( 'jisento_bad_manifest', \esc_html( sprintf( __( 'The package manifest has no valid "%s" value.', 'jisento-migration' ), $field ) ));
				}
			}
			if ( $file_entries !== (int) $manifest['file_count'] ) {
				$zip->close();
				/* translators: %1$d, %2$d: runtime values. */
				return new \WP_Error( 'jisento_package_incomplete', \esc_html( sprintf( __( 'The package lists %1$d files but contains %2$d. It is incomplete or was modified; export it again.', 'jisento-migration' ), (int) $manifest['file_count'], $file_entries ) ));
			}
			if ( $file_bytes !== (int) $manifest['files_bytes'] ) {
				$zip->close();
				/* translators: %1$d, %2$d: runtime values. */
				return new \WP_Error( 'jisento_package_incomplete', \esc_html( sprintf( __( 'The package lists %1$d bytes of files but contains %2$d. It is incomplete or was modified; export it again.', 'jisento-migration' ), (int) $manifest['files_bytes'], $file_bytes ) ));
			}
			$segments = array();
			$raw      = isset( $manifest['database']['segments'] ) ? $manifest['database']['segments'] : array();
			if ( ! is_array( $raw ) ) {
				$zip->close();
				return new \WP_Error( 'jisento_bad_manifest', \esc_html__( 'The package manifest has an invalid database segment list.', 'jisento-migration' ));
			}
			if ( 'files' !== $manifest['contents'] && ! $raw ) {
				$zip->close();
				return new \WP_Error( 'jisento_package_incomplete', \esc_html__( 'The package should contain a database dump but lists no database segments.', 'jisento-migration' ));
			}
			foreach ( $raw as $index => $segment ) {
				$entry = is_array( $segment ) && isset( $segment['entry'] ) ? (string) $segment['entry'] : '';
				if ( ! preg_match( self::SEGMENT_PATTERN, $entry ) || ! isset( $segment['bytes'], $segment['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $segment['sha256'] ) ) {
					$zip->close();
					/* translators: %d: runtime values. */
					return new \WP_Error( 'jisento_bad_manifest', \esc_html( sprintf( __( 'Database segment %d in the manifest is malformed.', 'jisento-migration' ), (int) $index + 1 ) ));
				}
				$stat = $zip->statName( $entry );
				if ( false === $stat ) {
					$zip->close();
					/* translators: %s: runtime values. */
					return new \WP_Error( 'jisento_package_incomplete', \esc_html( sprintf( __( 'Database segment %s is listed in the manifest but missing from the package.', 'jisento-migration' ), $entry ) ));
				}
				if ( (int) $stat['size'] !== (int) $segment['bytes'] ) {
					$zip->close();
					/* translators: %1$s, %2$d, %3$d: runtime values. */
					return new \WP_Error( 'jisento_package_incomplete', \esc_html( sprintf( __( 'Database segment %1$s is %2$d bytes in the package but %3$d in the manifest.', 'jisento-migration' ), $entry, (int) $stat['size'], (int) $segment['bytes'] ) ));
				}
				$segments[] = array(
					'entry'  => $entry,
					'bytes'  => (int) $segment['bytes'],
					'sha256' => (string) $segment['sha256'],
				);
			}
			$info['segments'] = $segments;
		} else {
			$info['segments'] = array();
			if ( $info['has_db'] ) {
				$hash = isset( $info['checksums']['database/database.sql'] ) ? (string) $info['checksums']['database/database.sql'] : '';
				$stat = $zip->statName( 'database/database.sql' );
				if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
					$zip->close();
					return new \WP_Error( 'jisento_bad_manifest', \esc_html__( 'This version 1 package has no checksum for database/database.sql, so it cannot be verified.', 'jisento-migration' ));
				}
				$info['segments'][] = array(
					'entry'  => 'database/database.sql',
					'bytes'  => (int) $stat['size'],
					'sha256' => $hash,
				);
			}
			if ( isset( $info['checksums']['file_count'] ) && (int) $info['checksums']['file_count'] > 0 && (int) $info['checksums']['file_count'] !== $file_entries ) {
				$zip->close();
				/* translators: %1$d, %2$d: runtime values. */
				return new \WP_Error( 'jisento_package_incomplete', \esc_html( sprintf( __( 'The package lists %1$d files but contains %2$d. It is incomplete; export it again.', 'jisento-migration' ), (int) $info['checksums']['file_count'], $file_entries ) ));
			}
		}
		$zip->close();
		$info['file_entries'] = $file_entries;
		$info['file_bytes']   = $file_bytes;

		if ( 2 === $info['format'] ) {
			$digests = self::read_entry_digests( $zip_path );
			if ( is_wp_error( $digests ) ) {
				return $digests;
			}
			$info['entry_digests'] = $digests;
			if ( $digests ) {
				$recomputed = Streaming_Zip_Writer::content_sha256_of( array_values( $digests['entries'] ) );
				if ( $recomputed !== $digests['content_sha256'] ) {
					return new \WP_Error( 'jisento_content_sha', \esc_html__( 'The package content checksum does not match checksums/entries.jsonl. The package is damaged or was modified; export it again.', 'jisento-migration' ));
				}
				$recorded = self::recorded_content_sha256( $zip_path, $manifest );
				if ( is_string( $recorded ) && $recorded !== $digests['content_sha256'] ) {
					return new \WP_Error( 'jisento_content_sha', \esc_html__( 'The package content checksum does not match its integrity record. The package is damaged or was modified; export it again.', 'jisento-migration' ));
				}
				foreach ( array_keys( $digests['entries'] ) as $ename ) {
					if ( 0 !== strpos( $ename, 'files/' ) || '/' === substr( $ename, -1 ) ) {
						continue;
					}
					// Presence of every listed files/* entry was already checked via file_count; missing digest lines are checked on extract.
				}
			}
		} else {
			$info['entry_digests'] = null;
		}

		return $info;
	}

	/**
	 * content_sha256 from the registry sidecar or the manifest, when present.
	 *
	 * @param string $zip_path Package.
	 * @param array  $manifest Manifest.
	 * @return string|null
	 */
	public static function recorded_content_sha256( $zip_path, array $manifest = array() ) {
		if ( ! empty( $manifest['content_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $manifest['content_sha256'] ) ) {
			return (string) $manifest['content_sha256'];
		}
		$sidecar = $zip_path . '.json';
		if ( is_readable( $sidecar ) ) {
			$meta = json_decode( (string) file_get_contents( $sidecar ), true );
			if ( is_array( $meta ) && ! empty( $meta['content_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $meta['content_sha256'] ) ) {
				return (string) $meta['content_sha256'];
			}
			if ( is_array( $meta ) && isset( $meta['checksum_kind'], $meta['checksum'] ) && 'content-sha256' === $meta['checksum_kind'] && preg_match( '/^[a-f0-9]{64}$/', (string) $meta['checksum'] ) ) {
				return (string) $meta['checksum'];
			}
		}
		return null;
	}

	/**
	 * Read checksums/entries.jsonl. Null when the entry is absent (older v2 packages).
	 *
	 * @param string $zip_path Package.
	 * @return array{hash_chunk:int,content_sha256:string,entries:array}|null|\WP_Error
	 */
	public static function read_entry_digests( $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', \esc_html__( 'Unable to open the package.', 'jisento-migration' ));
		}
		$raw = $zip->getFromName( Streaming_Zip_Writer::ENTRY_DIGESTS );
		$zip->close();
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$lines = preg_split( '/\r?\n/', (string) $raw );
		if ( ! $lines ) {
			return new \WP_Error( 'jisento_entry_digests', \esc_html__( 'checksums/entries.jsonl is empty. The package is damaged; export it again.', 'jisento-migration' ));
		}
		$header = json_decode( array_shift( $lines ), true );
		if ( ! is_array( $header ) || empty( $header['content_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $header['content_sha256'] ) || empty( $header['hash_chunk'] ) || (int) $header['hash_chunk'] < 4096 ) {
			return new \WP_Error( 'jisento_entry_digests', \esc_html__( 'checksums/entries.jsonl has a damaged header. The package is damaged; export it again.', 'jisento-migration' ));
		}
		$entries = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || empty( $row['n'] ) || ! isset( $row['us'], $row['crc'], $row['sha'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $row['sha'] ) ) {
				return new \WP_Error( 'jisento_entry_digests', \esc_html__( 'checksums/entries.jsonl has a damaged entry line. The package is damaged; export it again.', 'jisento-migration' ));
			}
			$entries[ (string) $row['n'] ] = array(
				'n'   => (string) $row['n'],
				'us'  => (int) $row['us'],
				'crc' => strtolower( (string) $row['crc'] ),
				'sha' => (string) $row['sha'],
			);
		}
		return array(
			'hash_chunk'     => (int) $header['hash_chunk'],
			'content_sha256' => (string) $header['content_sha256'],
			'entries'        => $entries,
		);
	}

	/**
	 * Extract one entry to $dest through "<dest>.jisento-tmp", verifying size and SHA-256 before the rename.
	 *
	 * @param string $zip_path Package.
	 * @param string $entry    Entry name.
	 * @param string $dest     Destination file.
	 * @param int    $bytes    Expected size.
	 * @param string $sha256   Expected hash.
	 * @return true|\WP_Error
	 */
	public function extract_verified( $zip_path, $entry, $dest, $bytes, $sha256 ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', \esc_html__( 'Unable to open the package.', 'jisento-migration' ));
		}
		$stat = $zip->statName( $entry );
		if ( false === $stat ) {
			$zip->close();
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_missing_entry', \esc_html( sprintf( __( 'Package entry %s not found.', 'jisento-migration' ), self::printable( $entry ) ) ));
		}
		$hash   = hash_init( 'sha256' );
		$result = $this->stream_entry( $zip, $entry, $stat, $dest, $hash );
		$zip->close();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$tmp = $dest . '.jisento-tmp';
		if ( (int) $bytes !== (int) $result['bytes'] ) {
			@unlink( $tmp );
			/* translators: %1$s, %2$d, %3$d: runtime values. */
			return new \WP_Error( 'jisento_entry_size', \esc_html( sprintf( __( '%1$s extracted to %2$d bytes, but the manifest records %3$d. The package is damaged; export it again.', 'jisento-migration' ), self::printable( $entry ), (int) $result['bytes'], (int) $bytes ) ));
		}
		$actual = hash_final( $hash );
		if ( ! hash_equals( (string) $sha256, $actual ) ) {
			@unlink( $tmp );
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_entry_hash', \esc_html( sprintf( __( '%s does not match the SHA-256 recorded in the manifest. The package is damaged or was modified; export it again.', 'jisento-migration' ), self::printable( $entry ) ) ));
		}
		return self::commit_tmp( $tmp, $dest );
	}

	/**
	 * Stream an entry into "<dest>.jisento-tmp". Checks every read and write, the byte count and the CRC-32.
	 * When $expected_sha and $hash_chunk are set, also builds the chunked per-entry digest while writing.
	 *
	 * @return array{bytes:int,sha:?string}|\WP_Error
	 */
	private function stream_entry( \ZipArchive $zip, $name, array $stat, $dest, $hash = null, $expected_sha = null, $hash_chunk = 0 ) {
		$tmp = $dest . '.jisento-tmp';
		if ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) {
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Unable to create folder %s.', 'jisento-migration' ), self::printable( dirname( $dest ) ) ) ));
		}
		$stream = $zip->getStream( $name );
		if ( ! $stream ) {
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Unable to read %s from the package.', 'jisento-migration' ), self::printable( $name ) ) ));
		}
		$out = @fopen( $tmp, 'wb' );
		if ( ! $out ) {
			fclose( $stream );
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Unable to write %s (permission denied or disk full).', 'jisento-migration' ), self::printable( $tmp ) ) ));
		}
		$crc       = hash_init( 'crc32b' );
		$written   = 0;
		$error     = null;
		$chunk     = max( 0, (int) $hash_chunk );
		$chunk_sha = $chunk > 0 ? hash_init( 'sha256' ) : null;
		$in_chunk  = 0;
		$hashes    = '';
		while ( ! feof( $stream ) ) {
			$buffer = fread( $stream, 1048576 );
			if ( false === $buffer ) {
				/* translators: %s: runtime values. */
				$error = new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Reading %s from the package failed (damaged ZIP data).', 'jisento-migration' ), self::printable( $name ) ) ));
				break;
			}
			if ( '' === $buffer ) {
				break;
			}
			$wrote = fwrite( $out, $buffer );
			if ( false === $wrote || $wrote !== strlen( $buffer ) ) {
				/* translators: 1: destination path, 2: bytes written. */
				$error = new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Writing %1$s failed after %2$d bytes (disk full or quota reached).', 'jisento-migration' ), self::printable( $dest ), $written ) ));
				break;
			}
			$written += $wrote;
			hash_update( $crc, $buffer );
			if ( $hash ) {
				hash_update( $hash, $buffer );
			}
			if ( $chunk_sha ) {
				$left = strlen( $buffer );
				$pos  = 0;
				while ( $left > 0 ) {
					$take = (int) min( $left, $chunk - $in_chunk );
					hash_update( $chunk_sha, substr( $buffer, $pos, $take ) );
					$in_chunk += $take;
					$pos      += $take;
					$left     -= $take;
					if ( $in_chunk === $chunk ) {
						$hashes   .= hash_final( $chunk_sha, true );
						$chunk_sha = hash_init( 'sha256' );
						$in_chunk  = 0;
					}
				}
			}
		}
		fclose( $stream );
		if ( ! fclose( $out ) && ! $error ) {
			/* translators: %s: runtime values. */
			$error = new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Closing %s failed (disk full).', 'jisento-migration' ), self::printable( $tmp ) ) ));
		}
		if ( ! $error && $written !== (int) $stat['size'] ) {
			/* translators: %1$s, %2$d, %3$d: runtime values. */
			$error = new \WP_Error( 'jisento_entry_size', \esc_html( sprintf( __( '%1$s extracted to %2$d bytes but the package says %3$d.', 'jisento-migration' ), self::printable( $name ), $written, (int) $stat['size'] ) ));
		}
		if ( ! $error && isset( $stat['crc'] ) && sprintf( '%08x', (int) $stat['crc'] & 0xFFFFFFFF ) !== hash_final( $crc ) ) {
			/* translators: %s: runtime values. */
			$error = new \WP_Error( 'jisento_entry_crc', \esc_html( sprintf( __( '%s failed its ZIP CRC check. The package is damaged; export or upload it again.', 'jisento-migration' ), self::printable( $name ) ) ));
		}
		$digest = null;
		if ( ! $error && $chunk_sha ) {
			if ( $in_chunk > 0 ) {
				$hashes .= hash_final( $chunk_sha, true );
			}
			$digest = ( 0 === $written ) ? hash( 'sha256', '' ) : hash( 'sha256', $hashes );
			if ( is_string( $expected_sha ) && $digest !== $expected_sha ) {
				/* translators: %s: runtime values. */
				$error = new \WP_Error( 'jisento_entry_sha', \esc_html( sprintf( __( '%s failed its SHA-256 check. The package is damaged or was modified; export or upload it again.', 'jisento-migration' ), self::printable( $name ) ) ));
			}
		}
		if ( $error ) {
			@unlink( $tmp );
			return $error;
		}
		return array(
			'bytes' => $written,
			'sha'   => $digest,
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function commit_tmp( $tmp, $dest ) {
		if ( ! @rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Unable to move the restored file into place at %s.', 'jisento-migration' ), self::printable( $dest ) ) ));
		}
		return true;
	}

	/**
	 * Restore files/* entries starting at $start_index. Each file is written to "<dest>.jisento-tmp",
	 * checked (byte count, CRC-32) and renamed over the destination, so a killed request never leaves
	 * a half-written file in place. Any unreadable or unwritable entry fails the batch.
	 *
	 * No call writes more than $max_bytes. An entry larger than that is written in pieces across
	 * calls: the result carries "partial" (index, offset, crc) and the caller passes it back as
	 * $resume. The .jisento-tmp file keeps the bytes written so far.
	 *
	 * $mapper( $relative ) returns the absolute destination, '' to skip the entry on purpose, or a WP_Error.
	 *
	 * @return array{next:int,done:bool,extracted:int,bytes:int,current:string,total:int,skipped:int,skipped_paths:string[],partial:array|null}|\WP_Error
	 */
	public function extract_files_batch( $zip_path, $start_index, $max_files, $time_budget, $mapper, $resume = null, $max_bytes = 33554432 ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', \esc_html__( 'Unable to open the package.', 'jisento-migration' ));
		}

		$digests = self::read_entry_digests( $zip_path );
		if ( is_wp_error( $digests ) ) {
			$zip->close();
			return $digests;
		}
		$digest_map = $digests ? $digests['entries'] : null;
		$hash_chunk = $digests ? (int) $digests['hash_chunk'] : 0;

		$bytes     = 0;
		$current   = '';
		$deadline  = microtime( true ) + max( 2, (float) $time_budget );
		$extracted = 0;
		$skipped   = 0;
		$skipped_paths = array();
		$i         = (int) $start_index;
		$total     = $zip->numFiles;
		$done      = true;
		$max_bytes = max( 1048576, (int) $max_bytes );
		$resume    = ( is_array( $resume ) && isset( $resume['index'] ) && (int) $resume['index'] === $i ) ? $resume : null;

		for ( ; $i < $total; $i++ ) {
			if ( $i > (int) $start_index && ( microtime( true ) >= $deadline || $extracted >= $max_files || $bytes >= $max_bytes ) ) {
				$done = false;
				break;
			}

			$stat = $zip->statIndex( $i );
			$name = $stat ? (string) $stat['name'] : '';
			if ( 0 !== strpos( $name, 'files/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}

			$safe = Guard::sanitize_archive_path( substr( $name, strlen( 'files/' ) ) );
			if ( is_wp_error( $safe ) ) {
				$zip->close();
				return $safe;
			}
			$dest = call_user_func( $mapper, $safe );
			if ( is_wp_error( $dest ) ) {
				$zip->close();
				return $dest;
			}
			if ( ! is_string( $dest ) || '' === $dest ) {
				$skipped++;
				if ( count( $skipped_paths ) < 50 ) {
					$skipped_paths[] = $safe;
				}
				continue;
			}
			$expected_sha = null;
			if ( is_array( $digest_map ) ) {
				if ( empty( $digest_map[ $name ]['sha'] ) ) {
					$zip->close();
					/* translators: %s: runtime values. */
					return new \WP_Error( 'jisento_entry_sha', \esc_html( sprintf( __( '%s has no SHA-256 in checksums/entries.jsonl. The package is incomplete; export it again.', 'jisento-migration' ), self::printable( $name ) ) ));
				}
				$expected_sha = (string) $digest_map[ $name ]['sha'];
			}
			if ( (int) $stat['size'] > $max_bytes || ( $resume && (int) $resume['index'] === $i ) ) {
				$room = $max_bytes - $bytes;
				if ( $i > (int) $start_index && $room < 65536 ) {
					$done = false;
					break;
				}
				$piece = $this->extract_piece( $zip, $zip_path, $name, $stat, $dest, $resume && (int) $resume['index'] === $i ? $resume : null, $room, $expected_sha, $hash_chunk );
				$resume = null;
				if ( is_wp_error( $piece ) ) {
					$zip->close();
					return $piece;
				}
				$bytes  += $piece['written'];
				$current = $safe;
				if ( ! $piece['complete'] ) {
					$zip->close();
					return array(
						'next'          => $i,
						'done'          => false,
						'extracted'     => $extracted,
						'bytes'         => $bytes,
						'current'       => $safe,
						'total'         => $total,
						'skipped'       => $skipped,
						'skipped_paths' => $skipped_paths,
						'partial'       => array(
							'index'  => $i,
							'offset' => $piece['offset'],
							'crc'    => $piece['crc'],
							'chunks' => $piece['chunks'],
							'tmp'    => $dest . '.jisento-tmp',
						),
					);
				}
				$extracted++;
				continue;
			}
			if ( $i > (int) $start_index && $bytes + (int) $stat['size'] > $max_bytes ) {
				$done = false;
				break;
			}
			$written = $this->stream_entry( $zip, $name, $stat, $dest, null, $expected_sha, $hash_chunk );
			if ( is_wp_error( $written ) ) {
				$zip->close();
				return $written;
			}
			$committed = self::commit_tmp( $dest . '.jisento-tmp', $dest );
			if ( is_wp_error( $committed ) ) {
				$zip->close();
				return $committed;
			}
			$extracted++;
			$bytes  += (int) $written['bytes'];
			$current = $safe;
		}

		$zip->close();

		return array(
			'next'          => $i,
			'done'          => $done || $i >= $total,
			'extracted'     => $extracted,
			'bytes'         => $bytes,
			'current'       => $current,
			'total'         => $total,
			'skipped'       => $skipped,
			'skipped_paths' => $skipped_paths,
			'partial'       => null,
			'digests'       => (bool) $digests,
		);
	}

	/**
	 * Write up to $limit more bytes of one large entry into "<dest>.jisento-tmp".
	 * Stored entries are read straight from the package at their data offset; deflated entries
	 * (only in packages from older versions) are decompressed from the start and the bytes
	 * already written are skipped, so writes stay bounded either way.
	 * When $expected_sha is set, pauses only on hash-chunk boundaries and checks the digest before commit.
	 *
	 * @return array{complete:bool,written:int,offset:int,crc:int,chunks:string[]}|\WP_Error
	 */
	private function extract_piece( \ZipArchive $zip, $zip_path, $name, array $stat, $dest, $resume, $limit, $expected_sha = null, $hash_chunk = 0 ) {
		$tmp    = $dest . '.jisento-tmp';
		$size   = (int) $stat['size'];
		$offset = $resume ? (int) $resume['offset'] : 0;
		$crc    = $resume ? (int) $resume['crc'] : 0;
		$chunks = ( $resume && isset( $resume['chunks'] ) && is_array( $resume['chunks'] ) ) ? $resume['chunks'] : array();
		$chunk  = max( 0, (int) $hash_chunk );
		clearstatcache( true, $tmp );
		if ( $offset > 0 && ( ! is_file( $tmp ) || (int) filesize( $tmp ) < $offset || $offset > $size || ( $chunk > 0 && 0 !== $offset % $chunk ) ) ) {
			// The saved position does not match the file on disk: restart this entry.
			$offset = 0;
			$crc    = 0;
			$chunks = array();
		}
		if ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) {
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Unable to create folder %s.', 'jisento-migration' ), self::printable( dirname( $dest ) ) ) ));
		}
		$out = @fopen( $tmp, $offset > 0 ? 'r+b' : 'wb' );
		if ( ! $out ) {
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Unable to write %s (permission denied or disk full).', 'jisento-migration' ), self::printable( $tmp ) ) ));
		}
		ftruncate( $out, $offset );
		fseek( $out, $offset );

		$method = isset( $stat['comp_method'] ) ? (int) $stat['comp_method'] : -1;
		$in     = null;
		if ( 0 === $method ) {
			$start = self::data_offset( $zip_path, $name );
			if ( is_wp_error( $start ) ) {
				fclose( $out );
				return $start;
			}
			$in = @fopen( $zip_path, 'rb' );
			if ( ! $in || 0 !== fseek( $in, $start + $offset ) ) {
				if ( $in ) {
					fclose( $in );
				}
				fclose( $out );
				/* translators: %s: runtime values. */
				return new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Unable to read %s from the package.', 'jisento-migration' ), self::printable( $name ) ) ));
			}
		} else {
			$in = $zip->getStream( $name );
			if ( ! $in ) {
				fclose( $out );
				/* translators: %s: runtime values. */
				return new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Unable to read %s from the package.', 'jisento-migration' ), self::printable( $name ) ) ));
			}
			$skip = $offset;
			while ( $skip > 0 ) {
				$buffer = fread( $in, (int) min( 1048576, $skip ) );
				if ( false === $buffer || '' === $buffer ) {
					fclose( $in );
					fclose( $out );
					/* translators: %s: runtime values. */
					return new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Reading %s from the package failed (damaged ZIP data).', 'jisento-migration' ), self::printable( $name ) ) ));
				}
				$skip -= strlen( $buffer );
			}
		}

		$piece     = hash_init( 'crc32b' );
		$written   = 0;
		$want      = (int) min( max( 0, (int) $limit ), $size - $offset );
		$chunk_sha = null;
		$in_chunk  = 0;
		if ( $chunk > 0 ) {
			if ( $offset + $want < $size ) {
				// Pause only on a hash-chunk boundary so the digest state can be saved without HashContext.
				$aligned = $offset + $want;
				$aligned -= $aligned % $chunk;
				if ( $aligned <= $offset ) {
					$want = (int) min( $chunk, $size - $offset );
				} else {
					$want = $aligned - $offset;
				}
			}
			$chunk_sha = hash_init( 'sha256' );
			$in_chunk  = 0;
		}
		$error = null;
		while ( $written < $want ) {
			$take = (int) min( 1048576, $want - $written );
			if ( $chunk > 0 ) {
				$take = (int) min( $take, $chunk - $in_chunk );
			}
			$buffer = fread( $in, $take );
			if ( false === $buffer || '' === $buffer ) {
				/* translators: %s: runtime values. */
				$error = new \WP_Error( 'jisento_stream', \esc_html( sprintf( __( 'Reading %s from the package failed (damaged ZIP data).', 'jisento-migration' ), self::printable( $name ) ) ));
				break;
			}
			$wrote = fwrite( $out, $buffer );
			if ( false === $wrote || $wrote !== strlen( $buffer ) ) {
				/* translators: 1: destination path, 2: bytes written. */
				$error = new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Writing %1$s failed after %2$d bytes (disk full or quota reached).', 'jisento-migration' ), self::printable( $dest ), $offset + $written ) ));
				break;
			}
			hash_update( $piece, $buffer );
			$written += $wrote;
			if ( $chunk_sha ) {
				hash_update( $chunk_sha, $buffer );
				$in_chunk += $wrote;
				if ( $in_chunk === $chunk ) {
					$chunks[]  = hash_final( $chunk_sha );
					$chunk_sha = hash_init( 'sha256' );
					$in_chunk  = 0;
				}
			}
		}
		fclose( $in );
		if ( ! fclose( $out ) && ! $error ) {
			/* translators: %s: runtime values. */
			$error = new \WP_Error( 'jisento_write', \esc_html( sprintf( __( 'Closing %s failed (disk full).', 'jisento-migration' ), self::printable( $tmp ) ) ));
		}
		if ( $error ) {
			@unlink( $tmp );
			return $error;
		}
		$crc    = Streaming_Zip_Writer::crc32_combine( $crc, (int) hexdec( hash_final( $piece ) ), $written );
		$offset += $written;
		if ( $offset < $size ) {
			return array(
				'complete' => false,
				'written'  => $written,
				'offset'   => $offset,
				'crc'      => $crc,
				'chunks'   => $chunks,
			);
		}
		if ( isset( $stat['crc'] ) && ( (int) $stat['crc'] & 0xFFFFFFFF ) !== $crc ) {
			@unlink( $tmp );
			/* translators: %s: runtime values. */
			return new \WP_Error( 'jisento_entry_crc', \esc_html( sprintf( __( '%s failed its ZIP CRC check. The package is damaged; export or upload it again.', 'jisento-migration' ), self::printable( $name ) ) ));
		}
		if ( is_string( $expected_sha ) ) {
			if ( $chunk_sha && $in_chunk > 0 ) {
				$chunks[] = hash_final( $chunk_sha );
			}
			$digest = ( 0 === $size ) ? hash( 'sha256', '' ) : hash( 'sha256', implode( '', array_map( 'hex2bin', $chunks ) ) );
			if ( $digest !== $expected_sha ) {
				@unlink( $tmp );
				/* translators: %s: runtime values. */
				return new \WP_Error( 'jisento_entry_sha', \esc_html( sprintf( __( '%s failed its SHA-256 check. The package is damaged or was modified; export or upload it again.', 'jisento-migration' ), self::printable( $name ) ) ));
			}
		}
		$committed = self::commit_tmp( $tmp, $dest );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		return array(
			'complete' => true,
			'written'  => $written,
			'offset'   => $offset,
			'crc'      => $crc,
			'chunks'   => $chunks,
		);
	}

	/**
	 * Absolute byte offset of an entry's data, read from the central directory and local header.
	 *
	 * @param string $zip_path Package.
	 * @param string $name     Entry name.
	 * @return int|\WP_Error
	 */
	public static function data_offset( $zip_path, $name ) {
		/* translators: %s: runtime values. */
		$bad = new \WP_Error( 'jisento_zip_directory', \esc_html( sprintf( __( 'The package directory could not be read to locate %s. The package is damaged; export or upload it again.', 'jisento-migration' ), self::printable( $name ) ) ));
		$fh  = @fopen( $zip_path, 'rb' );
		if ( ! $fh ) {
			return $bad;
		}
		$size = (int) filesize( $zip_path );
		$tail = min( $size, 65557 );
		fseek( $fh, $size - $tail );
		$buf = (string) fread( $fh, $tail );
		$pos = strrpos( $buf, "PK\x05\x06" );
		if ( false === $pos || strlen( $buf ) < $pos + 22 ) {
			fclose( $fh );
			return $bad;
		}
		$eocd      = unpack( 'vdisk/vcd_disk/vcount_disk/vcount/Vcd_size/Vcd_offset', substr( $buf, $pos + 4, 16 ) );
		$cd_offset = (int) $eocd['cd_offset'];
		$cd_size   = (int) $eocd['cd_size'];
		if ( 0xFFFFFFFF === $cd_offset || 0xFFFFFFFF === $cd_size || 0xFFFF === (int) $eocd['count'] ) {
			$loc = $pos - 20;
			if ( $loc < 0 || "PK\x06\x07" !== substr( $buf, $loc, 4 ) ) {
				fclose( $fh );
				return $bad;
			}
			$rec_at = unpack( 'P', substr( $buf, $loc + 8, 8 ) );
			fseek( $fh, (int) $rec_at[1] );
			$rec = (string) fread( $fh, 56 );
			if ( "PK\x06\x06" !== substr( $rec, 0, 4 ) ) {
				fclose( $fh );
				return $bad;
			}
			$z64       = unpack( 'Pcount_disk/Pcount/Pcd_size/Pcd_offset', substr( $rec, 24, 32 ) );
			$cd_offset = (int) $z64['cd_offset'];
			$cd_size   = (int) $z64['cd_size'];
		}
		fseek( $fh, $cd_offset );
		$read = 0;
		while ( $read < $cd_size ) {
			$head = (string) fread( $fh, 46 );
			if ( strlen( $head ) < 46 || "PK\x01\x02" !== substr( $head, 0, 4 ) ) {
				break;
			}
			$h     = unpack( 'Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr( $head, 20, 26 ) );
			$ename = (string) fread( $fh, $h['nlen'] );
			$extra = $h['elen'] > 0 ? (string) fread( $fh, $h['elen'] ) : '';
			if ( $h['clen'] > 0 ) {
				fseek( $fh, $h['clen'], SEEK_CUR );
			}
			$read += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
			if ( $ename !== $name ) {
				continue;
			}
			$local = (int) $h['offset'];
			if ( 0xFFFFFFFF === $local ) {
				$local = self::zip64_local_offset( $extra, $h );
				if ( $local < 0 ) {
					break;
				}
			}
			fseek( $fh, $local );
			$lh = (string) fread( $fh, 30 );
			fclose( $fh );
			if ( strlen( $lh ) < 30 || "PK\x03\x04" !== substr( $lh, 0, 4 ) ) {
				return $bad;
			}
			$lens = unpack( 'vnlen/velen', substr( $lh, 26, 4 ) );
			return $local + 30 + (int) $lens['nlen'] + (int) $lens['elen'];
		}
		fclose( $fh );
		return $bad;
	}

	private static function zip64_local_offset( $extra, array $h ) {
		$at = 0;
		while ( $at + 4 <= strlen( $extra ) ) {
			$field = unpack( 'vid/vlen', substr( $extra, $at, 4 ) );
			if ( 0x0001 === (int) $field['id'] ) {
				$data = substr( $extra, $at + 4, (int) $field['len'] );
				$pos  = 0;
				if ( 0xFFFFFFFF === (int) $h['usize'] ) {
					$pos += 8;
				}
				if ( 0xFFFFFFFF === (int) $h['csize'] ) {
					$pos += 8;
				}
				if ( strlen( $data ) < $pos + 8 ) {
					return -1;
				}
				$v = unpack( 'P', substr( $data, $pos, 8 ) );
				return (int) $v[1];
			}
			$at += 4 + (int) $field['len'];
		}
		return -1;
	}

	/**
	 * Plugin folders inside the package that hold a copy of Jisento Migration (any folder name).
	 *
	 * @param string $zip_path Package.
	 * @return string[] Archive-relative folder prefixes like "wp-content/plugins/foo/".
	 */
	public function jisento_plugin_copies( $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array();
		}
		$found = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! preg_match( '#^files/((?:wp-content/)?plugins/[^/]+/)[^/]+\.php$#', $name, $m ) || isset( $found[ $m[1] ] ) ) {
				continue;
			}
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				continue;
			}
			$head = (string) fread( $stream, 8192 );
			fclose( $stream );
			if ( \Jisento\Migration\Filesystem\File_System::is_jisento_plugin_header( $head ) ) {
				$found[ $m[1] ] = true;
			}
		}
		$zip->close();
		return array_keys( $found );
	}

	private static function printable( $text ) {
		$text = (string) $text;
		return 1 === preg_match( '//u', $text ) ? $text : 'hex:' . bin2hex( $text );
	}
}

