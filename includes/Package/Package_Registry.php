<?php
/**
 * Registry of real .jisento packages. Status is never "completed" unless the file exists.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Package;

use Jisento\Migration\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb->prefix + plugin-owned identifiers, not user input.

// phpcs:disable WordPress.WP.AlternativeFunctions -- Large migration package streams cannot use WP_Filesystem.


class Package_Registry {

	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'jisento_packages';
	}

	public function create( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$this->table(),
			array(
				'migration_id' => sanitize_text_field( $data['migration_id'] ?? '' ),
				'filename'     => $this->plain_filename( $data['filename'] ?? '' ),
				'storage_key'  => sanitize_text_field( $data['storage_key'] ?? '' ),
				'size'         => (int) ( $data['size'] ?? 0 ),
				'checksum'     => sanitize_text_field( $data['checksum'] ?? '' ),
				'type'         => sanitize_key( $data['type'] ?? 'manual' ),
				'status'       => sanitize_key( $data['status'] ?? 'processing' ),
				'created_at'   => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function mark_completed( $id, $size, $checksum ) {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'status'   => 'completed',
				'size'     => (int) $size,
				'checksum' => sanitize_text_field( $checksum ),
			),
			array( 'id' => (int) $id )
		);
	}

	public function mark_failed( $id ) {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'status' => 'failed' ),
			array( 'id' => (int) $id )
		);
	}

	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', (int) $id ) );
	}

	public function get_by_storage_key( $key ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE storage_key = %s ORDER BY id DESC LIMIT 1', $key )
		);
	}

	public function delete( $id ) {
		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}
		Plugin::instance()->storage->delete( $row->storage_key );
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
		return true;
	}

	public function delete_by_storage_key( $key ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE storage_key = %s', $key ) );
	}

	public function delete_by_filename( $name ) {
		global $wpdb;
		$name = $this->plain_filename( $name );
		if ( '' === $name ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE filename = %s', $name ) );
	}

	public function upsert_completed( array $data ) {
		$key      = sanitize_text_field( $data['storage_key'] ?? '' );
		$existing = $key ? $this->get_by_storage_key( $key ) : null;
		$size     = (int) ( $data['size'] ?? 0 );
		$checksum = sanitize_text_field( $data['checksum'] ?? '' );
		$type     = sanitize_key( $data['type'] ?? 'full' );
		if ( $existing ) {
			global $wpdb;
			$wpdb->update(
				$this->table(),
				array(
					'filename'     => $this->plain_filename( $data['filename'] ?? '' ),
					'migration_id' => sanitize_text_field( $data['migration_id'] ?? $existing->migration_id ),
					'size'         => $size,
					'checksum'     => $checksum,
					'type'         => $type ? $type : 'full',
					'status'       => 'completed',
				),
				array( 'id' => (int) $existing->id )
			);
			return (int) $existing->id;
		}
		$data['status'] = 'completed';
		$id             = $this->create( $data );
		if ( $id ) {
			$this->mark_completed( $id, $size, $checksum );
		}
		return $id;
	}

	public function list_all() {
		\Jisento\Migration\Core\Installer::maybe_upgrade();
		clearstatcache();
		$storage = Plugin::instance()->storage;
		$out     = array();
		$seen    = array();
		$root    = $storage->root();

		if ( is_dir( $root ) ) {
			$dirs = scandir( $root );
			if ( $dirs ) {
				foreach ( $dirs as $dir ) {
					if ( '.' === $dir || '..' === $dir || ! is_dir( $root . '/' . $dir ) ) {
						continue;
					}
					if ( in_array( $dir, array( 'temp', 'tmp', 'chunks', 'jobs', 'logs' ), true ) ) {
						continue;
					}
					$base  = $root . '/' . $dir;
					$items = scandir( $base );
					if ( ! $items ) {
						continue;
					}
					foreach ( $items as $item ) {
						if ( ! preg_match( '/\.jisento$/i', $item ) ) {
							continue;
						}
						$path = $base . '/' . $item;
						if ( ! is_file( $path ) ) {
							continue;
						}
						clearstatcache( true, $path );
						$real = wp_normalize_path( $path );
						if ( isset( $seen[ $real ] ) ) {
							continue;
						}
						$seen[ $real ] = true;
						$this->push_file( $out, $path, $dir . '/' . $item, $item );
					}
				}
			}
		}

		$this->include_job_packages( $out, $seen );

		usort(
			$out,
			static function ( $a, $b ) {
				return ( $b['modified'] ?? 0 ) <=> ( $a['modified'] ?? 0 );
			}
		);

		return $out;
	}

	private function describe_file( $path, $key, $name ) {
		$size = (int) filesize( $path );
		$meta = array();
		if ( is_readable( $path . '.json' ) ) {
			$decoded = json_decode( (string) file_get_contents( $path . '.json' ), true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$check = $this->listing_check( $path, $meta, $size );
		$type = $this->infer_type( $name, $meta );
		$when = isset( $meta['created_at'] ) ? strtotime( $meta['created_at'] ) : filemtime( $path );
		if ( ! $when ) {
			$when = time();
		}
		$available = ! empty( $check['ok'] );
		if ( $available ) {
			$status       = 'completed';
			$status_label = __( 'Completed', 'jisento-migration' );
		} elseif ( $size > 0 && ! empty( $check['zip'] ) && empty( $meta['status'] ) ) {
			$status       = 'processing';
			$status_label = __( 'In progress', 'jisento-migration' );
		} else {
			$status       = 'unavailable';
			$status_label = __( 'Missing / corrupted', 'jisento-migration' );
		}
		$modified = filemtime( $path );
		if ( ! $modified ) {
			$modified = $when;
		}
		return array(
			'id'           => 0,
			'migration_id' => isset( $meta['migration_id'] ) ? $meta['migration_id'] : '',
			'name'         => $name,
			'filename'     => $name,
			'storage_key'  => $key,
			'relative'     => $key,
			'size'         => $size,
			'size_label'   => size_format( $size ),
			'checksum'     => isset( $meta['checksum'] ) ? $meta['checksum'] : '',
			'type'         => $type,
			'type_label'   => $this->type_label( $type ),
			'status'       => $status,
			'status_label' => $status_label,
			'available'    => $available,
			'reason'       => $available ? '' : $check['reason'],
			'modified'     => $modified,
			'date_label'   => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ),
			'created_at'   => isset( $meta['created_at'] ) ? $meta['created_at'] : gmdate( 'c', $when ),
			'manifest'     => array(
				'package_version'   => isset( $meta['package_version'] ) ? $meta['package_version'] : '',
				'format_marker'     => isset( $meta['format_marker'] ) ? $meta['format_marker'] : ( isset( $meta['signature'] ) ? $meta['signature'] : '' ),
				'home_url'          => isset( $meta['home_url'] ) ? $meta['home_url'] : '',
				'database_size'     => isset( $meta['database_size'] ) ? (int) $meta['database_size'] : 0,
				'files_size'        => isset( $meta['files_size'] ) ? (int) $meta['files_size'] : 0,
				'uncompressed_size' => isset( $meta['uncompressed_size'] ) ? (int) $meta['uncompressed_size'] : 0,
				'table_count'       => isset( $meta['table_count'] ) ? (int) $meta['table_count'] : 0,
				'file_count'        => isset( $meta['file_count'] ) ? (int) $meta['file_count'] : 0,
				'contents'          => isset( $meta['contents'] ) ? $meta['contents'] : $type,
			),
		);
	}

	private function push_file( array &$out, $path, $key, $name ) {
		try {
			$row = $this->describe_file( $path, $key, $name );
		} catch ( \Throwable $e ) {
			clearstatcache( true, $path );
			$size = is_file( $path ) ? (int) filesize( $path ) : 0;
			$row  = array(
				'id'           => 0,
				'migration_id' => '',
				'name'         => $name,
				'filename'     => $name,
				'storage_key'  => $key,
				'relative'     => $key,
				'size'         => $size,
				'size_label'   => size_format( $size ),
				'checksum'     => '',
				'type'         => $this->infer_type( $name, array() ),
				'type_label'   => $this->type_label( $this->infer_type( $name, array() ) ),
				'status'       => 'unavailable',
				'status_label' => __( 'Missing / corrupted', 'jisento-migration' ),
				'available'    => false,
				'reason'       => $e->getMessage(),
				'modified'     => time(),
				'date_label'   => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				'created_at'   => gmdate( 'c' ),
			);
		}
		$row['id'] = $this->sync_completed( $row );
		$out[]     = $row;
	}

	private function include_job_packages( array &$out, array &$seen ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jisento_jobs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; no user input.
		$rows  = $wpdb->get_results( "SELECT state_json FROM {$table} WHERE type = 'export' AND status = 'completed' ORDER BY id DESC LIMIT 20" );
		if ( ! $rows ) {
			return;
		}
		$storage = Plugin::instance()->storage;
		foreach ( $rows as $row ) {
			$state = json_decode( (string) $row->state_json, true );
			if ( ! is_array( $state ) ) {
				continue;
			}
			$name = isset( $state['package_name'] ) ? basename( (string) $state['package_name'] ) : '';
			$key  = isset( $state['package'] ) ? (string) $state['package'] : ( $name ? 'packages/' . $name : '' );
			if ( '' === $key ) {
				continue;
			}
			$path = '';
			if ( ! empty( $state['package_abs'] ) && is_file( $state['package_abs'] ) ) {
				$path = $state['package_abs'];
			}
			$resolved = $storage->resolve( $name ? $name : $key );
			if ( $resolved ) {
				$path = $resolved['path'];
				$key  = $resolved['key'];
			}
			if ( ! $path || ! is_file( $path ) ) {
				continue;
			}
			$real = wp_normalize_path( $path );
			if ( isset( $seen[ $real ] ) ) {
				continue;
			}
			$seen[ $real ] = true;
			$this->push_file( $out, $path, $key, basename( $path ) );
		}
	}

	private function sync_completed( array $row ) {
		$existing = $this->get_by_storage_key( $row['storage_key'] );
		if ( $existing ) {
			if ( ! empty( $row['available'] ) ) {
				$this->upsert_completed(
					array(
						'migration_id' => $row['migration_id'],
						'filename'     => $row['filename'],
						'storage_key'  => $row['storage_key'],
						'size'         => $row['size'],
						'checksum'     => $row['checksum'],
						'type'         => $row['type'],
					)
				);
			}
			return (int) $existing->id;
		}
		if ( empty( $row['available'] ) ) {
			return 0;
		}
		return $this->upsert_completed(
			array(
				'migration_id' => $row['migration_id'],
				'filename'     => $row['filename'],
				'storage_key'  => $row['storage_key'],
				'size'         => $row['size'],
				'checksum'     => $row['checksum'],
				'type'         => $row['type'],
				'status'       => 'completed',
			)
		);
	}

	private function infer_type( $name, array $meta ) {
		$type = isset( $meta['type'] ) ? sanitize_key( $meta['type'] ) : '';
		if ( $type && 'manual' !== $type ) {
			return $type;
		}
		if ( 0 === strpos( (string) $name, 'destination-before-migration' ) ) {
			return 'safety';
		}
		return 'full';
	}

	public function hydrate( $row ) {
		$storage = Plugin::instance()->storage;
		$path    = $storage->get_path( $row->storage_key );
		$check   = $this->verify_file( $path );
		return array(
			'id'          => (int) $row->id,
			'migration_id'=> $row->migration_id,
			'name'        => $row->filename,
			'filename'    => $row->filename,
			'storage_key' => $row->storage_key,
			'relative'    => $row->storage_key,
			'size'        => $check['size'],
			'size_label'  => size_format( $check['size'] ),
			'checksum'    => $row->checksum,
			'type'        => $row->type,
			'type_label'  => $this->type_label( $row->type ),
			'status'      => $check['ok'] ? 'completed' : 'unavailable',
			'available'   => $check['ok'],
			'integrity'   => $check,
			'modified'    => file_exists( $path ) ? filemtime( $path ) : strtotime( $row->created_at ),
			'date_label'  => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at ) ),
			'created_at'  => $row->created_at,
		);
	}

	private function hydrate_file( array $file, $dir ) {
		$path  = Plugin::instance()->storage->get_path( $file['relative'] );
		$check = $this->verify_file( $path );
		$type  = 'manual';
		if ( 0 === strpos( $file['name'], 'destination-before-migration' ) || false !== strpos( $file['name'], 'safety' ) ) {
			$type = 'safety';
		}
		return array(
			'id'          => 0,
			'migration_id'=> '',
			'name'        => $file['name'],
			'filename'    => $file['name'],
			'storage_key' => $file['relative'],
			'relative'    => $file['relative'],
			'size'        => $check['size'],
			'size_label'  => size_format( $check['size'] ),
			'checksum'    => '',
			'type'        => $type,
			'type_label'  => $this->type_label( $type ),
			'status'      => $check['ok'] ? 'completed' : 'unavailable',
			'available'   => $check['ok'],
			'integrity'   => $check,
			'modified'    => $file['modified'],
			'date_label'  => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file['modified'] ),
			'created_at'  => gmdate( 'c', $file['modified'] ),
		);
	}

	public function type_label( $type ) {
		switch ( $type ) {
			case 'database':
				return __( 'Database', 'jisento-migration' );
			case 'files':
				return __( 'wp-content', 'jisento-migration' );
			case 'safety':
				return __( 'Safety Backup', 'jisento-migration' );
			case 'upload':
				return __( 'Uploaded Package', 'jisento-migration' );
			case 'full':
			default:
				return __( 'Full', 'jisento-migration' );
		}
	}

	/**
	 * Listing status: cheap. Sidecar says completed and its size matches the file on disk.
	 * Without a sidecar the format marker and manifest are read (central directory only).
	 */
	private function listing_check( $path, array $meta, $size ) {
		return $this->verify_file( $path, 'cheap', $meta );
	}

	/**
	 * @param string $path Package path.
	 * @param string $mode "cheap" (sidecar + size, for listings, downloads and status polls) or
	 *                     "full" (format marker, manifest, segment sizes, entry counts; before an import).
	 * @param array|null $meta Sidecar, when the caller already read it.
	 * @return array ok, reason, size, format_marker, manifest, structure, zip.
	 */
	public function verify_file( $path, $mode = 'cheap', $meta = null ) {
		$result = array(
			'exists'        => false,
			'readable'      => false,
			'size'          => 0,
			'zip'           => false,
			'format_marker' => false,
			'manifest'      => false,
			'structure'     => false,
			'ok'            => false,
			'reason'        => '',
		);
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			$result['reason'] = __( 'The backup record exists, but the package file is missing.', 'jisento-migration' );
			return $result;
		}
		$result['exists']   = true;
		$result['readable'] = is_readable( $path );
		clearstatcache( true, $path );
		$result['size'] = (int) filesize( $path );
		if ( ! $result['readable'] ) {
			$result['reason'] = __( 'The package file is not readable.', 'jisento-migration' );
			return $result;
		}
		if ( $result['size'] <= 0 ) {
			$result['reason'] = __( 'The package file is empty (0 bytes).', 'jisento-migration' );
			return $result;
		}
		$handle = fopen( $path, 'rb' );
		$magic  = $handle ? (string) fread( $handle, 4 ) : '';
		if ( $handle ) {
			fclose( $handle );
		}
		$result['zip'] = ( "PK\x03\x04" === $magic || "PK\x05\x06" === $magic );
		if ( ! $result['zip'] ) {
			$result['reason'] = __( 'The package file is not a valid archive.', 'jisento-migration' );
			return $result;
		}

		if ( 'full' !== $mode ) {
			if ( null === $meta ) {
				$meta = array();
				if ( is_readable( $path . '.json' ) ) {
					$decoded = json_decode( (string) file_get_contents( $path . '.json' ), true );
					$meta    = is_array( $decoded ) ? $decoded : array();
				}
			}
			$complete = isset( $meta['status'] ) && 'completed' === $meta['status'];
			$sized    = isset( $meta['size'] ) && (int) $meta['size'] > 0;
			if ( $complete && $sized && (int) $meta['size'] === $result['size'] ) {
				$result['format_marker'] = true;
				$result['manifest']      = true;
				$result['structure']     = true;
				$result['ok']            = true;
				return $result;
			}
			if ( $complete && $sized ) {
				$result['reason'] = __( 'The package size does not match its integrity record, so the file was changed or is incomplete.', 'jisento-migration' );
				return $result;
			}
			$inspect = ( new Archive() )->inspect( $path );
		} else {
			$inspect = ( new Archive() )->verify_structure( $path );
		}
		if ( is_wp_error( $inspect ) ) {
			$result['reason'] = $inspect->get_error_message();
			return $result;
		}
		$result['format_marker'] = true;
		$result['manifest']      = ! empty( $inspect['manifest'] );
		$result['structure']     = ! empty( $inspect['has_db'] ) || ! empty( $inspect['has_files'] );
		$result['ok']            = $result['manifest'] && $result['structure'];
		if ( ! $result['ok'] ) {
			$result['reason'] = __( 'The package contains neither a database dump nor site files.', 'jisento-migration' );
		}
		return $result;
	}

	private function plain_filename( $name ) {
		$name = basename( str_replace( '\\', '/', (string) $name ) );
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
		return is_string( $name ) ? $name : '';
	}
}
