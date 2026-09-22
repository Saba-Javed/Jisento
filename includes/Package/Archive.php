<?php
/**
 * .jisento package writer/reader.
 *
 * Container: ZIP with an internal signature file so validation does not
 * depend on the .jisento extension.
 *
 * Layout:
 *   JISENTO                 (signature + format version)
 *   manifest.json
 *   checksums.json
 *   database/database.sql
 *   files/...
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Package;

use Jisento\Migration\Filesystem\File_System;
use Jisento\Migration\Security\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Archive {

	public static function zip_available() {
		return class_exists( 'ZipArchive' );
	}

	public function create( $zip_path, $staging_dir, array $manifest, array $checksums ) {
		if ( ! self::zip_available() ) {
			return new \WP_Error( 'jisento_no_zip', __( 'The PHP ZipArchive extension is required.', 'jisento' ) );
		}

		wp_mkdir_p( dirname( $zip_path ) );
		$partial = $zip_path . '.partial';
		if ( file_exists( $partial ) ) {
			@unlink( $partial );
		}
		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path );
		}

		$zip = new \ZipArchive();
		$opened = $zip->open( $partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return new \WP_Error( 'jisento_zip_open', sprintf( __( 'Unable to create the .jisento package (ZipArchive code %s).', 'jisento' ), (string) $opened ) );
		}

		$signature = JISENTO_SIGNATURE . "\n" . JISENTO_PACKAGE_VERSION . "\n" . hash( 'sha256', JISENTO_MAGIC . JISENTO_PACKAGE_VERSION );
		if ( ! $zip->addFromString( 'JISENTO', $signature ) ) {
			$zip->close();
			return new \WP_Error( 'jisento_zip_write', __( 'Unable to write the package signature.', 'jisento' ) );
		}
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFromString( 'checksums.json', wp_json_encode( $checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$files = File_System::list_files( $staging_dir );
		foreach ( $files as $file ) {
			$local = str_replace( '\\', '/', $file['relative'] );
			if ( in_array( $local, array( 'JISENTO', 'manifest.json', 'checksums.json', 'file-list.json' ), true ) ) {
				continue;
			}
			$source = realpath( $file['path'] );
			if ( ! $source || ! is_file( $source ) ) {
				continue;
			}
			if ( ! $zip->addFile( $source, $local ) ) {
				$zip->close();
				@unlink( $partial );
				return new \WP_Error( 'jisento_zip_add', sprintf( __( 'Unable to add %s to the package.', 'jisento' ), $local ) );
			}
		}

		if ( ! $zip->close() ) {
			@unlink( $partial );
			return new \WP_Error( 'jisento_zip_close', __( 'Unable to finalize the .jisento package.', 'jisento' ) );
		}

		clearstatcache( true, $partial );
		if ( ! is_file( $partial ) || filesize( $partial ) < 64 ) {
			@unlink( $partial );
			return new \WP_Error( 'jisento_zip_empty', __( 'The package was not written correctly (empty file).', 'jisento' ) );
		}

		if ( ! @rename( $partial, $zip_path ) ) {
			if ( ! File_System::stream_copy( $partial, $zip_path ) ) {
				@unlink( $partial );
				return new \WP_Error( 'jisento_zip_move', __( 'Unable to move the completed package into storage.', 'jisento' ) );
			}
			@unlink( $partial );
		}

		clearstatcache( true, $zip_path );
		$inspect = $this->inspect( $zip_path );
		if ( is_wp_error( $inspect ) ) {
			@unlink( $zip_path );
			return $inspect;
		}
		if ( filesize( $zip_path ) <= 0 ) {
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_empty', __( 'The package was not written correctly (empty file).', 'jisento' ) );
		}

		return $zip_path;
	}

	/**
	 * Start a package with signature + manifest only. Content is appended later.
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

		$signature = JISENTO_SIGNATURE . "\n" . JISENTO_PACKAGE_VERSION . "\n" . hash( 'sha256', JISENTO_MAGIC . JISENTO_PACKAGE_VERSION );
		if ( ! $zip->addFromString( 'JISENTO', $signature ) ) {
			$zip->close();
			@unlink( $zip_path );
			return new \WP_Error( 'jisento_zip_write', __( 'Unable to write the package signature.', 'jisento' ) );
		}
		if ( ! $zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
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
				return new \WP_Error( 'jisento_zip_add', sprintf( __( 'Missing file while building the package: %s', 'jisento' ), $local ) );
			}
			if ( ! $zip->addFile( $source, $local ) ) {
				$zip->close();
				return new \WP_Error( 'jisento_zip_add', sprintf( __( 'Unable to add %s to the package.', 'jisento' ), $local ) );
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
				__( 'Invalid Jisento Package. The uploaded file is corrupted or was not generated by a compatible version of Jisento Migration.', 'jisento' )
			);
		}

		$signature = $zip->getFromName( 'JISENTO' );
		if ( false === $signature || 0 !== strpos( $signature, JISENTO_SIGNATURE ) ) {
			$zip->close();
			return new \WP_Error(
				'jisento_invalid_package',
				__( 'Invalid Jisento Package. The uploaded file is corrupted or was not generated by a compatible version of Jisento Migration.', 'jisento' )
			);
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$checksum_raw = $zip->getFromName( 'checksums.json' );
		$manifest     = json_decode( (string) $manifest_raw, true );
		$checksums    = json_decode( (string) $checksum_raw, true );

		if ( ! is_array( $manifest ) || empty( $manifest['package_version'] ) ) {
			$zip->close();
			return new \WP_Error( 'jisento_bad_manifest', __( 'The package manifest is missing or invalid.', 'jisento' ) );
		}

		if ( version_compare( (string) $manifest['package_version'], '1.0', '<' ) ) {
			$zip->close();
			return new \WP_Error( 'jisento_unsupported', __( 'This package version is not supported.', 'jisento' ) );
		}

		$has_db    = false !== $zip->locateName( 'database/database.sql' );
		$has_files = false !== $zip->locateName( 'files/' ) || $this->has_files_prefix( $zip );

		$zip->close();

		return array(
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
			if ( 0 === strpos( (string) $name, 'files/' ) ) {
				return true;
			}
		}
		return false;
	}

	public function extract_entry_to( $zip_path, $entry, $dest_file ) {
		$entry = Guard::sanitize_archive_path( $entry );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to open the package.', 'jisento' ) );
		}

		$stat = $zip->statName( $entry );
		if ( false === $stat ) {
			$zip->close();
			return new \WP_Error( 'jisento_missing_entry', __( 'Package entry not found.', 'jisento' ) );
		}

		$normalized_dest  = str_replace( '\\', '/', $dest_file );
		$normalized_entry = str_replace( '\\', '/', $entry );
		$suffix           = '/' . $normalized_entry;
		if ( strlen( $normalized_dest ) > strlen( $suffix ) && substr( $normalized_dest, -strlen( $suffix ) ) === $suffix ) {
			$root = substr( $normalized_dest, 0, -strlen( $suffix ) );
			wp_mkdir_p( $root );
			if ( $zip->extractTo( $root, $entry ) && is_file( $dest_file ) ) {
				$zip->close();
				return true;
			}
		}

		wp_mkdir_p( dirname( $dest_file ) );
		$stream = $zip->getStream( $entry );
		if ( ! $stream ) {
			$zip->close();
			return new \WP_Error( 'jisento_stream', __( 'Unable to read package entry.', 'jisento' ) );
		}

		$out = fopen( $dest_file, 'wb' );
		if ( ! $out ) {
			fclose( $stream );
			$zip->close();
			return new \WP_Error( 'jisento_write', __( 'Unable to write extracted file.', 'jisento' ) );
		}

		while ( ! feof( $stream ) ) {
			$buffer = fread( $stream, 1048576 );
			if ( false === $buffer ) {
				break;
			}
			fwrite( $out, $buffer );
		}

		fclose( $out );
		fclose( $stream );
		$zip->close();
		return true;
	}

	/**
	 * Extract files/* entries incrementally starting at $index.
	 *
	 * @return array{next:int,done:bool,extracted:int}
	 */
	public function extract_files_batch( $zip_path, $dest_root, $start_index, $max_files = 400, $time_budget = 12, $mapper = null ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new \WP_Error( 'jisento_zip_open', __( 'Unable to open the package.', 'jisento' ) );
		}

		$bytes     = 0;
		$current   = '';
		$deadline  = microtime( true ) + max( 2, (float) $time_budget );
		$extracted = 0;
		$skipped   = 0;
		$i         = (int) $start_index;
		$total     = $zip->numFiles;
		$done      = true;

		for ( ; $i < $total; $i++ ) {
			if ( $i > (int) $start_index && ( microtime( true ) >= $deadline || $extracted >= $max_files || $bytes >= 33554432 ) ) {
				$done = false;
				break;
			}

			$name = $zip->getNameIndex( $i );
			if ( ! $name || 0 !== strpos( $name, 'files/' ) ) {
				continue;
			}
			if ( substr( $name, -1 ) === '/' ) {
				continue;
			}

			$relative = substr( $name, strlen( 'files/' ) );
			$safe     = Guard::sanitize_archive_path( $relative );
			if ( is_wp_error( $safe ) ) {
				$zip->close();
				return $safe;
			}
			$stat = $zip->statIndex( $i );
			$size = ( $stat && isset( $stat['size'] ) ) ? (int) $stat['size'] : 0;

			if ( is_callable( $mapper ) ) {
				$mapped = call_user_func( $mapper, $safe );
				if ( ! is_string( $mapped ) || '' === $mapped ) {
					$skipped++;
					continue;
				}
				$dest = $mapped;
			} else {
				$dest = rtrim( $dest_root, '/\\' ) . '/' . $safe;
				$normalized_dest = str_replace( '\\', '/', $dest );
				$normalized_root = rtrim( str_replace( '\\', '/', (string) $dest_root ), '/' );
				if ( '' === $normalized_root || ( 0 !== strpos( $normalized_dest, $normalized_root . '/' ) && $normalized_dest !== $normalized_root ) ) {
					$zip->close();
					return new \WP_Error( 'jisento_bad_path', __( 'Refusing to extract outside the destination directory.', 'jisento' ) );
				}
			}

			wp_mkdir_p( dirname( $dest ) );
			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				continue;
			}
			$out = fopen( $dest, 'wb' );
			if ( ! $out ) {
				fclose( $stream );
				continue;
			}
			while ( ! feof( $stream ) ) {
				$buffer = fread( $stream, 1048576 );
				if ( false === $buffer ) {
					break;
				}
				fwrite( $out, $buffer );
			}
			fclose( $out );
			fclose( $stream );
			$extracted++;
			$bytes   += $size;
			$current  = $safe;
		}

		$zip->close();

		return array(
			'next'      => $i,
			'done'      => $done || $i >= $total,
			'extracted' => $extracted,
			'bytes'     => $bytes,
			'current'   => $current,
			'total'     => $total,
			'skipped'   => $skipped,
		);
	}

	public function list_file_entries( $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return array();
		}
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( $name && 0 === strpos( $name, 'files/' ) && substr( $name, -1 ) !== '/' ) {
				$stat      = $zip->statIndex( $i );
				$entries[] = array(
					'name'  => $name,
					'size'  => isset( $stat['size'] ) ? (int) $stat['size'] : 0,
					'index' => $i,
				);
			}
		}
		$zip->close();
		return $entries;
	}
}
