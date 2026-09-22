<?php
/**
 * Filesystem helpers: streaming copy, directory walks, cache detection.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_System {

	public static function stream_copy( $source, $dest, $chunk = 1048576 ) {
		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			return false;
		}
		$expected = (int) filesize( $source );
		$in       = fopen( $source, 'rb' );
		if ( ! $in ) {
			return false;
		}
		wp_mkdir_p( dirname( $dest ) );
		$out = fopen( $dest, 'wb' );
		if ( ! $out ) {
			fclose( $in );
			return false;
		}
		while ( ! feof( $in ) ) {
			$buffer = fread( $in, $chunk );
			if ( false === $buffer ) {
				fclose( $in );
				fclose( $out );
				@unlink( $dest );
				return false;
			}
			if ( '' === $buffer ) {
				break;
			}
			$written = fwrite( $out, $buffer );
			if ( false === $written || $written !== strlen( $buffer ) ) {
				fclose( $in );
				fclose( $out );
				@unlink( $dest );
				return false;
			}
		}
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $dest );
		if ( ! is_file( $dest ) || (int) filesize( $dest ) !== $expected ) {
			@unlink( $dest );
			return false;
		}
		return true;
	}

	public static function hash_file( $path ) {
		return hash_file( 'sha256', $path );
	}

	public static function list_files( $root, array $excludes = array() ) {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' );
		$files = array();
		if ( ! is_dir( $root ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$full = str_replace( '\\', '/', $file->getPathname() );
			$rel  = ltrim( substr( $full, strlen( $root ) ), '/' );
			if ( self::is_excluded( $rel, $excludes ) ) {
				continue;
			}
			$files[] = array(
				'relative' => $rel,
				'path'     => $file->getPathname(),
				'size'     => $file->getSize(),
			);
		}

		return $files;
	}

	public static function is_excluded( $relative, array $excludes ) {
		$relative = str_replace( '\\', '/', $relative );
		foreach ( $excludes as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', (string) $pattern ), '/' );
			if ( '' === $pattern ) {
				continue;
			}
			if ( $relative === $pattern || 0 === strpos( $relative, $pattern . '/' ) ) {
				return true;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				if ( fnmatch( $pattern, $relative ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function default_cache_excludes() {
		return array(
			'wp-content/cache',
			'wp-content/uploads/cache',
			'wp-content/uploads/elementor/css',
			'wp-content/uploads/elementor/google-fonts',
			'wp-content/et-cache',
			'wp-content/litespeed',
			'wp-content/wp-rocket-config',
			'wp-content/w3tc-config',
			'wp-content/wflogs',
			'wp-content/upgrade',
			'wp-content/upgrade-temp-backup',
			'wp-content/debug.log',
			'wp-content/jisento',
			'wp-content/backup-db',
			'wp-content/backups',
			'wp-content/updraft',
			'wp-content/ai1wm-backups',
			'wp-content/ai1wm-storage',
			'wp-content/nfwlog',
			'wp-content/cache/supercache',
			'wp-content/object-cache.php',
			'wp-content/advanced-cache.php',
		);
	}

	public static function backup_excludes() {
		return array(
			'wp-content/updraft',
			'wp-content/backups',
			'wp-content/backup-db',
			'wp-content/ai1wm-backups',
			'wp-content/ai1wm-storage',
			'wp-content/jisento',
			'wp-content/uploads/backupbuddy_backups',
			'wp-content/uploads/backupbuddy_temp',
			'wp-content/uploads/wpvividbackups',
			'wp-content/wpvividbackups',
			'wp-content/duplicator',
			'wp-content/backups-dup-lite',
			'wp-content/uploads/*.wpress',
			'wp-content/uploads/*.jisento',
		);
	}

	public static function environment_excludes() {
		return array(
			'wp-config.php',
			'wp-config-sample.php',
			'.htaccess',
			'nginx.conf',
			'web.config',
			'wp-content/object-cache.php',
			'wp-content/advanced-cache.php',
			'wp-content/db.php',
			'wp-content/sunrise.php',
			'error_log',
			'php.ini',
			'.user.ini',
			'wordfence-waf.php',
		);
	}

	public static function readable_size( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}
}
