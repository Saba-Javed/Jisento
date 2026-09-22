<?php
/**
 * Local filesystem storage under wp-content/jisento/.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_Storage implements Storage_Adapter {

	public function root() {
		$dir = WP_CONTENT_DIR . '/jisento';
		return $dir;
	}

	public function ensure_directories() {
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
		$dir   = $this->get_path( $relative_dir );
		$out   = array();
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
					'name'      => $item,
					'relative'  => trim( $relative_dir . '/' . $item, '/' ),
					'size'      => filesize( $path ),
					'modified'  => filemtime( $path ),
					'download'  => $item,
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
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		$candidates = array(
			$relative,
			'packages/' . basename( $relative ),
			'backups/' . basename( $relative ),
			'uploads/' . basename( $relative ),
			'temp/' . basename( $relative ),
		);
		foreach ( array_unique( $candidates ) as $key ) {
			$path = $this->get_path( $key );
			if ( is_readable( $path ) && is_file( $path ) && filesize( $path ) > 0 ) {
				return array(
					'key'  => $key,
					'path' => $path,
				);
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
		$root = $this->root();
		if ( 0 !== strpos( str_replace( '\\', '/', $dir ), str_replace( '\\', '/', $root ) ) ) {
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
