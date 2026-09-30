<?php
/**
 * Local filesystem storage under wp-content/uploads/jisento-{suffix}/.
 *
 * The random suffix is generated once and stored in the jisento_storage_suffix option
 * so the folder is not a predictable public uploads path. On upgrade, data is moved
 * from the legacy wp-content/jisento/ location so existing backups stay listed.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_Storage implements Storage_Adapter {

	const SUFFIX_OPTION = 'jisento_storage_suffix';

	/**
	 * Absolute path to the plugin data root (uploads/jisento-{suffix}).
	 *
	 * @return string
	 */
	public function root() {
		return self::root_path();
	}

	/**
	 * Absolute path without requiring an instance (used by Live_Url early on load).
	 *
	 * @return string
	 */
	public static function root_path() {
		$basedir = self::uploads_basedir();
		return rtrim( str_replace( '\\', '/', $basedir ), '/' ) . '/jisento-' . self::suffix();
	}

	/**
	 * Random folder suffix, created once and stored in an option.
	 *
	 * @return string
	 */
	public static function suffix() {
		$stored = function_exists( 'get_option' ) ? get_option( self::SUFFIX_OPTION, '' ) : '';
		if ( is_string( $stored ) && 1 === preg_match( '/^[a-z0-9]{8,32}$/', $stored ) ) {
			return $stored;
		}
		try {
			$fresh = bin2hex( random_bytes( 4 ) );
		} catch ( \Exception $e ) {
			$fresh = substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 8 );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::SUFFIX_OPTION, $fresh, false );
		}
		return $fresh;
	}

	/**
	 * Path of this storage root relative to the site (for export excludes), e.g. wp-content/uploads/jisento-abc123.
	 *
	 * @return string
	 */
	public static function relative_content_path() {
		$root = self::root_path();
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
			$norm    = rtrim( str_replace( '\\', '/', $root ), '/' );
			if ( 0 === strpos( $norm, $content . '/' ) ) {
				return 'wp-content/' . substr( $norm, strlen( $content ) + 1 );
			}
		}
		return 'wp-content/uploads/jisento-' . self::suffix();
	}

	/**
	 * @return string Absolute uploads basedir.
	 */
	private static function uploads_basedir() {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) && empty( $uploads['error'] ) ) {
				return (string) $uploads['basedir'];
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/uploads';
		}
		return sys_get_temp_dir() . '/jisento-uploads';
	}

	/**
	 * Legacy data root used by plugin versions before the uploads relocation.
	 *
	 * @return string
	 */
	public static function legacy_root() {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/jisento';
		}
		return '';
	}

	public function ensure_directories() {
		$this->maybe_migrate_legacy();

		$dirs = array(
			$this->root(),
			$this->root() . '/packages',
			$this->root() . '/chunks',
			$this->root() . '/jobs',
			$this->root() . '/logs',
			$this->root() . '/temp',
			$this->root() . '/backups',
			$this->root() . '/uploads',
			$this->root() . '/tmp',
		);

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$this->protect_dir( $dir );
		}
	}

	/**
	 * Move wp-content/jisento/* into the new uploads root so old backups remain listed.
	 */
	public function maybe_migrate_legacy() {
		$legacy = self::legacy_root();
		$dest   = $this->root();
		if ( '' === $legacy || ! is_dir( $legacy ) ) {
			return;
		}
		$legacy_real = realpath( $legacy );
		$dest_real   = is_dir( $dest ) ? realpath( $dest ) : false;
		if ( $legacy_real && $dest_real && $legacy_real === $dest_real ) {
			return;
		}

		if ( ! is_dir( $dest ) ) {
			wp_mkdir_p( $dest );
		}
		$this->protect_dir( $dest );

		$items = @scandir( $legacy );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$from = $legacy . '/' . $item;
			$to   = $dest . '/' . $item;
			if ( is_file( $to ) || is_dir( $to ) ) {
				// Prefer the newer copy; leave the legacy entry for a later pass / manual cleanup.
				continue;
			}
			if ( ! @rename( $from, $to ) ) {
				$this->copy_tree( $from, $to );
				if ( is_dir( $from ) ) {
					$this->delete_tree( $from );
				} elseif ( is_file( $from ) ) {
					@unlink( $from );
				}
			}
		}

		// Remove empty legacy tree (keep if anything remains).
		$left = @scandir( $legacy );
		if ( is_array( $left ) ) {
			$left = array_diff( $left, array( '.', '..' ) );
			if ( empty( $left ) ) {
				@rmdir( $legacy );
			}
		}
	}

	/**
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 */
	private function copy_tree( $from, $to ) {
		if ( is_file( $from ) ) {
			wp_mkdir_p( dirname( $to ) );
			@copy( $from, $to );
			return;
		}
		if ( ! is_dir( $from ) ) {
			return;
		}
		wp_mkdir_p( $to );
		$items = @scandir( $from );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$this->copy_tree( $from . '/' . $item, $to . '/' . $item );
		}
	}

	private function protect_dir( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			file_put_contents(
				$webconfig,
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
			);
		}
	}

	public function put( $relative, $contents ) {
		$path = $this->get_path( $relative );
		wp_mkdir_p( dirname( $path ) );
		return false !== file_put_contents( $path, $contents );
	}

	public function put_stream( $relative, $source_path ) {
		$path = $this->get_path( $relative );
		wp_mkdir_p( dirname( $path ) );
		return @copy( $source_path, $path );
	}

	public function get_path( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		return $this->root() . '/' . $relative;
	}

	public function exists( $relative ) {
		return file_exists( $this->get_path( $relative ) );
	}

	public function delete( $relative ) {
		$path = $this->get_path( $relative );
		if ( is_file( $path ) ) {
			$removed = @unlink( $path );
			if ( is_file( $path . '.json' ) ) {
				@unlink( $path . '.json' );
			}
			return $removed;
		}
		return false;
	}

	public function list_files( $relative_dir = '' ) {
		$dir = $this->get_path( $relative_dir );
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$items = scandir( $dir );
		if ( ! $items ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_file( $path ) && preg_match( '/\.jisento$/i', $item ) ) {
				$out[] = array(
					'name'     => $item,
					'relative' => trim( $relative_dir . '/' . $item, '/' ),
					'size'     => filesize( $path ),
					'modified' => filemtime( $path ),
					'download' => $item,
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['modified'] <=> $a['modified'];
			}
		);
		return $out;
	}

	public function size( $relative ) {
		$path = $this->get_path( $relative );
		if ( ! is_file( $path ) ) {
			return 0;
		}
		clearstatcache( true, $path );
		return (int) filesize( $path );
	}

	public function stream( $relative ) {
		$path = $this->get_path( $relative );
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return false;
		}
		while ( ! feof( $fp ) ) {
			$buffer = fread( $fp, 1048576 );
			if ( false === $buffer ) {
				break;
			}
			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}
		fclose( $fp );
		return true;
	}

	public function resolve( $relative ) {
		$relative   = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		$candidates = array(
			$relative,
			'packages/' . basename( $relative ),
			'backups/' . basename( $relative ),
			'uploads/' . basename( $relative ),
			'temp/' . basename( $relative ),
		);
		// Also look in the legacy root so pre-migration absolute registry keys still resolve.
		$roots = array( $this->root() );
		$legacy = self::legacy_root();
		if ( '' !== $legacy && is_dir( $legacy ) ) {
			$roots[] = $legacy;
		}
		foreach ( $roots as $root ) {
			foreach ( array_unique( $candidates ) as $key ) {
				$path = rtrim( $root, '/\\' ) . '/' . $key;
				if ( is_readable( $path ) && is_file( $path ) && filesize( $path ) > 0 ) {
					return array(
						'key'  => $key,
						'path' => $path,
					);
				}
			}
		}
		return null;
	}

	public function tmp_dir( $job_id ) {
		$dir = $this->root() . '/temp/' . sanitize_file_name( $job_id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public function enforce_retention( $keep ) {
		$keep     = max( 1, (int) $keep );
		$now      = time();
		$registry = new \Jisento\Migration\Package\Package_Registry();
		foreach ( array( 'packages', 'backups' ) as $dir ) {
			$eligible = array();
			foreach ( $this->list_files( $dir ) as $file ) {
				$mtime = (int) $file['modified'];
				$path  = $this->get_path( $file['relative'] );
				if ( $mtime <= 0 || ! is_file( $path . '.json' ) || ( $now - $mtime ) < 3600 ) {
					continue;
				}
				$eligible[] = $file;
			}
			if ( count( $eligible ) <= $keep ) {
				continue;
			}
			$remove = array_slice( $eligible, $keep );
			foreach ( $remove as $file ) {
				$this->delete( $file['relative'] );
				$registry->delete_by_storage_key( $file['relative'] );
			}
		}
	}

	public function delete_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$allowed = array( $this->root(), self::legacy_root() );
		$norm    = str_replace( '\\', '/', $dir );
		$ok      = false;
		foreach ( $allowed as $root ) {
			if ( '' === $root ) {
				continue;
			}
			$root_n = str_replace( '\\', '/', $root );
			if ( 0 === strpos( $norm, $root_n ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $dir );
	}
}
