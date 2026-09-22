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
	 * Start a package with the format marker and manifest. Content is appended later.
	 *
	 * @param string $zip_path Absolute path.
	 * @param array  $manifest Manifest data.
	 * @return true|\WP_Error
	 */
	public function begin( $zip_path, array $manifest ) {
		if ( ! self::zip_available() ) {
			return new \WP_Error( 'jisento_no_zip', __( 'The PHP ZipArchive extension is required.', 'jisento' ) );
		}

		wp_mkdir_p( dirname( $zip_path ) );
		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path );
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $zip_path, \ZipArchive::CREATE );
		if ( true !== $opened ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to create the .jisento package.', 'jisento' ) );
		}
		if ( ! $zip->addFromString( 'JISENTO', self::marker_contents() ) ) {
			$zip->close();
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_write', __( 'Unable to write the package format marker.', 'jisento' ) );
		}
		if ( ! $zip->addFromString( 'manifest.json', self::encode_manifest( $manifest ) ) ) {
			$zip->close();
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_write', __( 'Unable to write manifest.json.', 'jisento' ) );
		}
		if ( ! $zip->close() ) {
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_close', __( 'Unable to save the package header.', 'jisento' ) );
		}
		unset( $zip );
		clearstatcache( true, $zip_path );

		if ( ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_empty', __( 'The package header was not written.', 'jisento' ) );
		}

		return true;
	}

	/**
	 * Replace manifest.json with the final values (counts known only after every file was added).
	 *
	 * @return true|\WP_Error
	 */
	public function write_manifest( $zip_path, array $manifest ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to reopen the .jisento package.', 'jisento' ) );
		}
		$ok = $zip->addFromString( 'manifest.json', self::encode_manifest( $manifest ) );
		if ( ! $zip->close() || ! $ok ) {
			return new \WP_Error( 'jisento_zip_write', __( 'Unable to write the final manifest.json.', 'jisento' ) );
		}
		return true;
	}

	private static function encode_manifest( array $manifest ) {
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new \RuntimeException( __( 'The package manifest could not be encoded as JSON.', 'jisento' ) );
		}
		return $json;
	}

	/**
	 * Append files to an existing package. One open/close per batch.
	 *
	 * @param string $zip_path Absolute path.
	 * @param array  $pairs   List of [source, local].
	 * @return true|\WP_Error
	 */
	public function add_batch( $zip_path, array $pairs ) {
		if ( ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
			return new \WP_Error( 'jisento_zip_missing', __( 'The in-progress package is missing.', 'jisento' ) );
		}

		$zip    = new \ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( true !== $opened ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to reopen the .jisento package.', 'jisento' ) );
		}

		foreach ( $pairs as $pair ) {
			$source = isset( $pair['source'] ) ? $pair['source'] : '';
			$local  = isset( $pair['local'] ) ? str_replace( '\\', '/', $pair['local'] ) : '';
			if ( '' === $local || ! is_file( $source ) ) {
				$zip->close();
				return new \WP_Error( 'jisento_zip_add', sprintf( __( 'Missing file while building the package: %s', 'jisento' ), self::printable( $local ) ) );
			}
			if ( ! $zip->addFile( $source, $local ) ) {
				$zip->close();
				return new \WP_Error( 'jisento_zip_add', sprintf( __( 'Unable to add %s to the package.', 'jisento' ), self::printable( $local ) ) );
			}
			if ( method_exists( $zip, 'setCompressionName' ) && $this->store_uncompressed( $local ) ) {
				$zip->setCompressionName( $local, \ZipArchive::CM_STORE );
			}
		}

		if ( ! $zip->close() ) {
			return new \WP_Error( 'jisento_zip_close', __( 'Unable to save the package after adding files.', 'jisento' ) );
		}
		unset( $zip );
		clearstatcache( true, $zip_path );

		if ( ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
			return new \WP_Error( 'jisento_zip_empty', __( 'The package became empty while adding files.', 'jisento' ) );
		}

		return true;
	}

	private function store_uncompressed( $local ) {
		$ext = strtolower( (string) pathinfo( $local, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'zip', 'gz', 'tgz', 'woff', 'woff2', 'pdf', 'mp3', 'ogg', 'webm', 'ico' ), true );
	}

	/**
	 * Read the format marker and manifest. Does not read file contents.
	 *
	 * @param string $zip_path Package.
	 * @return array|\WP_Error format, legacy, manifest, checksums, has_db, has_files, size.
	 */
	public function inspect( $zip_path ) {
		if ( ! is_readable( $zip_path ) ) {
			return new \WP_Error( 'jisento_unreadable', __( 'The package file cannot be read.', 'jisento' ) );
		}
		if ( ! self::zip_available() ) {
			return new \WP_Error( 'jisento_no_zip', __( 'The PHP ZipArchive extension is required.', 'jisento' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error(
				'jisento_invalid_package',
				__( 'Invalid Jisento Package. The file is not a readable ZIP container.', 'jisento' )
			);
		}

		$format = self::format_of( $zip->getFromName( 'JISENTO' ) );
		if ( 0 === $format ) {
			$zip->close();
			return new \WP_Error(
				'jisento_invalid_package',
				__( 'Invalid Jisento Package. The format marker is missing, so this file was not created by Jisento Migration or was created by a newer version.', 'jisento' )
			);
		}

		$manifest  = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		$checksums = 1 === $format ? json_decode( (string) $zip->getFromName( 'checksums.json' ), true ) : array();

		if ( ! is_array( $manifest ) || empty( $manifest['package_version'] ) ) {
			$zip->close();
			return new \WP_Error( 'jisento_bad_manifest', __( 'The package manifest is missing or invalid.', 'jisento' ) );
		}
		$major = (int) $manifest['package_version'];
		if ( $major !== $format ) {
			$zip->close();
			return new \WP_Error( 'jisento_bad_manifest', sprintf( __( 'The package format marker (v%1$d) does not match its manifest (package_version %2$s).', 'jisento' ), $format, self::printable( (string) $manifest['package_version'] ) ) );
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
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to open the package.', 'jisento' ) );
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
			return new \WP_Error( 'jisento_bad_path', sprintf( __( 'The package contains unsafe paths (absolute or with ".."), so it was rejected: %s', 'jisento' ), implode( ', ', array_slice( $bad_names, 0, 5 ) ) ) );
		}

		if ( 2 === $info['format'] ) {
			foreach ( array( 'package_version', 'plugin_version', 'home_url', 'contents' ) as $field ) {
				if ( empty( $manifest[ $field ] ) || ! is_string( $manifest[ $field ] ) ) {
					$zip->close();
					return new \WP_Error( 'jisento_bad_manifest', sprintf( __( 'The package manifest has no "%s" value.', 'jisento' ), $field ) );
				}
			}
			foreach ( array( 'file_count', 'files_bytes' ) as $field ) {
				if ( ! isset( $manifest[ $field ] ) || ! is_int( $manifest[ $field ] ) || $manifest[ $field ] < 0 ) {
					$zip->close();
					return new \WP_Error( 'jisento_bad_manifest', sprintf( __( 'The package manifest has no valid "%s" value.', 'jisento' ), $field ) );
				}
			}
			if ( $file_entries !== (int) $manifest['file_count'] ) {
				$zip->close();
				return new \WP_Error( 'jisento_package_incomplete', sprintf( __( 'The package lists %1$d files but contains %2$d. It is incomplete or was modified; export it again.', 'jisento' ), (int) $manifest['file_count'], $file_entries ) );
			}
			if ( $file_bytes !== (int) $manifest['files_bytes'] ) {
				$zip->close();
				return new \WP_Error( 'jisento_package_incomplete', sprintf( __( 'The package lists %1$d bytes of files but contains %2$d. It is incomplete or was modified; export it again.', 'jisento' ), (int) $manifest['files_bytes'], $file_bytes ) );
			}
			$segments = array();
			$raw      = isset( $manifest['database']['segments'] ) ? $manifest['database']['segments'] : array();
			if ( ! is_array( $raw ) ) {
				$zip->close();
				return new \WP_Error( 'jisento_bad_manifest', __( 'The package manifest has an invalid database segment list.', 'jisento' ) );
			}
			if ( 'files' !== $manifest['contents'] && ! $raw ) {
				$zip->close();
				return new \WP_Error( 'jisento_package_incomplete', __( 'The package should contain a database dump but lists no database segments.', 'jisento' ) );
			}
			foreach ( $raw as $index => $segment ) {
				$entry = is_array( $segment ) && isset( $segment['entry'] ) ? (string) $segment['entry'] : '';
				if ( ! preg_match( self::SEGMENT_PATTERN, $entry ) || ! isset( $segment['bytes'], $segment['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $segment['sha256'] ) ) {
					$zip->close();
					return new \WP_Error( 'jisento_bad_manifest', sprintf( __( 'Database segment %d in the manifest is malformed.', 'jisento' ), (int) $index + 1 ) );
				}
				$stat = $zip->statName( $entry );
				if ( false === $stat ) {
					$zip->close();
					return new \WP_Error( 'jisento_package_incomplete', sprintf( __( 'Database segment %s is listed in the manifest but missing from the package.', 'jisento' ), $entry ) );
				}
				if ( (int) $stat['size'] !== (int) $segment['bytes'] ) {
					$zip->close();
					return new \WP_Error( 'jisento_package_incomplete', sprintf( __( 'Database segment %1$s is %2$d bytes in the package but %3$d in the manifest.', 'jisento' ), $entry, (int) $stat['size'], (int) $segment['bytes'] ) );
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
					return new \WP_Error( 'jisento_bad_manifest', __( 'This version 1 package has no checksum for database/database.sql, so it cannot be verified.', 'jisento' ) );
				}
				$info['segments'][] = array(
					'entry'  => 'database/database.sql',
					'bytes'  => (int) $stat['size'],
					'sha256' => $hash,
				);
			}
			if ( isset( $info['checksums']['file_count'] ) && (int) $info['checksums']['file_count'] > 0 && (int) $info['checksums']['file_count'] !== $file_entries ) {
				$zip->close();
				return new \WP_Error( 'jisento_package_incomplete', sprintf( __( 'The package lists %1$d files but contains %2$d. It is incomplete; export it again.', 'jisento' ), (int) $info['checksums']['file_count'], $file_entries ) );
			}
		}
		$zip->close();
		$info['file_entries'] = $file_entries;
		$info['file_bytes']   = $file_bytes;
		return $info;
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
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to open the package.', 'jisento' ) );
		}
		$stat = $zip->statName( $entry );
		if ( false === $stat ) {
			$zip->close();
			return new \WP_Error( 'jisento_missing_entry', sprintf( __( 'Package entry %s not found.', 'jisento' ), self::printable( $entry ) ) );
		}
		$hash   = hash_init( 'sha256' );
		$result = $this->stream_entry( $zip, $entry, $stat, $dest, $hash );
		$zip->close();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$tmp = $dest . '.jisento-tmp';
		if ( (int) $bytes !== (int) $result ) {
			@unlink( $tmp );
			return new \WP_Error( 'jisento_entry_size', sprintf( __( '%1$s extracted to %2$d bytes, but the manifest records %3$d. The package is damaged; export it again.', 'jisento' ), self::printable( $entry ), (int) $result, (int) $bytes ) );
		}
		$actual = hash_final( $hash );
		if ( ! hash_equals( (string) $sha256, $actual ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'jisento_entry_hash', sprintf( __( '%s does not match the SHA-256 recorded in the manifest. The package is damaged or was modified; export it again.', 'jisento' ), self::printable( $entry ) ) );
		}
		return self::commit_tmp( $tmp, $dest );
	}

	/**
	 * Stream an entry into "<dest>.jisento-tmp". Checks every read and write, the byte count and the CRC-32.
	 *
	 * @return int|\WP_Error Bytes written.
	 */
	private function stream_entry( \ZipArchive $zip, $name, array $stat, $dest, $hash = null ) {
		$tmp = $dest . '.jisento-tmp';
		if ( ! is_dir( dirname( $dest ) ) && ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new \WP_Error( 'jisento_write', sprintf( __( 'Unable to create folder %s.', 'jisento' ), self::printable( dirname( $dest ) ) ) );
		}
		$stream = $zip->getStream( $name );
		if ( ! $stream ) {
			return new \WP_Error( 'jisento_stream', sprintf( __( 'Unable to read %s from the package.', 'jisento' ), self::printable( $name ) ) );
		}
		$out = @fopen( $tmp, 'wb' );
		if ( ! $out ) {
			fclose( $stream );
			return new \WP_Error( 'jisento_write', sprintf( __( 'Unable to write %s (permission denied or disk full).', 'jisento' ), self::printable( $tmp ) ) );
		}
		$crc     = hash_init( 'crc32b' );
		$written = 0;
		$error   = null;
		while ( ! feof( $stream ) ) {
			$buffer = fread( $stream, 1048576 );
			if ( false === $buffer ) {
				$error = new \WP_Error( 'jisento_stream', sprintf( __( 'Reading %s from the package failed (damaged ZIP data).', 'jisento' ), self::printable( $name ) ) );
				break;
			}
			if ( '' === $buffer ) {
				break;
			}
			$wrote = fwrite( $out, $buffer );
			if ( false === $wrote || $wrote !== strlen( $buffer ) ) {
				$error = new \WP_Error( 'jisento_write', sprintf( __( 'Writing %s failed after %d bytes (disk full or quota reached).', 'jisento' ), self::printable( $dest ), $written ) );
				break;
			}
			$written += $wrote;
			hash_update( $crc, $buffer );
			if ( $hash ) {
				hash_update( $hash, $buffer );
			}
		}
		fclose( $stream );
		if ( ! fclose( $out ) && ! $error ) {
			$error = new \WP_Error( 'jisento_write', sprintf( __( 'Closing %s failed (disk full).', 'jisento' ), self::printable( $tmp ) ) );
		}
		if ( ! $error && $written !== (int) $stat['size'] ) {
			$error = new \WP_Error( 'jisento_entry_size', sprintf( __( '%1$s extracted to %2$d bytes but the package says %3$d.', 'jisento' ), self::printable( $name ), $written, (int) $stat['size'] ) );
		}
		if ( ! $error && isset( $stat['crc'] ) && sprintf( '%08x', (int) $stat['crc'] & 0xFFFFFFFF ) !== hash_final( $crc ) ) {
			$error = new \WP_Error( 'jisento_entry_crc', sprintf( __( '%s failed its ZIP CRC check. The package is damaged; export or upload it again.', 'jisento' ), self::printable( $name ) ) );
		}
		if ( $error ) {
			@unlink( $tmp );
			return $error;
		}
		return $written;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function commit_tmp( $tmp, $dest ) {
		if ( ! @rename( $tmp, $dest ) ) {
			@unlink( $tmp );
			return new \WP_Error( 'jisento_write', sprintf( __( 'Unable to move the restored file into place at %s.', 'jisento' ), self::printable( $dest ) ) );
		}
		return true;
	}

	/**
	 * Restore files/* entries starting at $start_index. Each file is written to "<dest>.jisento-tmp",
	 * checked (byte count, CRC-32) and renamed over the destination, so a killed request never leaves
	 * a half-written file in place. Any unreadable or unwritable entry fails the batch.
	 *
	 * $mapper( $relative ) returns the absolute destination, '' to skip the entry on purpose, or a WP_Error.
	 *
	 * @return array{next:int,done:bool,extracted:int,bytes:int,current:string,total:int,skipped:int,skipped_paths:string[]}|\WP_Error
	 */
	public function extract_files_batch( $zip_path, $start_index, $max_files, $time_budget, $mapper ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to open the package.', 'jisento' ) );
		}

		$bytes     = 0;
		$current   = '';
		$deadline  = microtime( true ) + max( 2, (float) $time_budget );
		$extracted = 0;
		$skipped   = 0;
		$skipped_paths = array();
		$i         = (int) $start_index;
		$total     = $zip->numFiles;
		$done      = true;

		for ( ; $i < $total; $i++ ) {
			if ( $i > (int) $start_index && ( microtime( true ) >= $deadline || $extracted >= $max_files || $bytes >= 33554432 ) ) {
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
			$written = $this->stream_entry( $zip, $name, $stat, $dest );
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
			$bytes  += $written;
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
		);
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
