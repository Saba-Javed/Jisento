<?php
/**
 * Chunked site exporter that writes a .jisento package.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Export;

use Jisento\Migration\Database\Database_Exporter;
use Jisento\Migration\Database\Dump_Writer;
use Jisento\Migration\Filesystem\File_System;
use Jisento\Migration\Jobs\Job_Conflict;
use Jisento\Migration\Jobs\Job_Store;
use Jisento\Migration\Package\Archive;
use Jisento\Migration\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exporter {

	public function start( array $options ) {
		$plugin = Plugin::instance();
		$job    = \Jisento\Migration\Jobs\Job_Runner::open(
			'export',
			array(
				'options' => $this->normalize_options( $options ),
			)
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$plugin->logger->log( $job->job_id, 'created', 'export', '', 'info', 'Export job created' );
		return $plugin->jobs->update(
			$job,
			array(
				'status' => 'running',
				'stage'  => 'preparing',
			)
		);
	}

	public function step( $job ) {
		$plugin = Plugin::instance();
		$state  = is_array( $job->state ) ? $job->state : array();

		try {
			switch ( $job->stage ) {
				case 'created':
				case 'preparing':
					return $this->prepare( $job, $state );
				case 'exporting_database':
					return $this->export_database( $job, $state );
				case 'exporting_files':
					return $this->export_files( $job, $state );
				case 'packaging':
					return $this->package( $job, $state );
				default:
					return $job;
			}
		} catch ( Job_Conflict $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$this->discard_unverified_package( $state );
			$message = self::stage_message( $job, $e->getMessage() );
			$plugin->logger->log( $job->job_id, $job->stage, 'error', '', 'error', $message );
			return $plugin->jobs->update(
				$job,
				array(
					'status'        => 'failed',
					'error_summary' => $message,
				)
			);
		}
	}

	private function normalize_options( array $options ) {
		$defaults = array(
			'mode'            => 'full',
			'backup_type'     => 'manual',
			'skip_cache'      => true,
			'skip_backups'    => true,
			'exclude_plugins' => array(),
			'exclude_dirs'    => array(),
			'exclude_tables'  => array(),
			'include_core'    => false,
		);
		$options = wp_parse_args( $options, $defaults );
		$options['exclude_plugins'] = array_filter( array_map( 'sanitize_text_field', (array) $options['exclude_plugins'] ) );
		$options['exclude_dirs']    = array_filter( array_map( 'sanitize_text_field', (array) $options['exclude_dirs'] ) );
		$options['exclude_tables']  = array_filter( array_map( 'sanitize_text_field', (array) $options['exclude_tables'] ) );
		if ( ! in_array( $options['mode'], array( 'full', 'database', 'files' ), true ) ) {
			$options['mode'] = 'full';
		}
		return $options;
	}

	private function prepare( $job, array $state ) {
		$plugin  = Plugin::instance();
		$options = $state['options'];
		$tmp     = $plugin->storage->tmp_dir( $job->job_id );

		if ( empty( $state['prep_ready'] ) ) {
			wp_mkdir_p( $tmp . '/database' );
			wp_mkdir_p( $tmp . '/files' );

			$db      = new Database_Exporter();
			$tables  = array();
			$meta    = array();
			$db_size = 0;
			if ( 'files' !== $options['mode'] ) {
				$tables = $db->tables( $options['exclude_tables'] );
				foreach ( $tables as $table ) {
					$info     = $db->table_meta( $table );
					$meta[]   = $info;
					$db_size += $info['data'] + $info['index'];
				}
				$state['skipped_views'] = $db->skipped_views();
				if ( $state['skipped_views'] ) {
					$plugin->logger->log( $job->job_id, 'preparing', 'database', '', 'warning', 'Views are not exported (recreate them on the destination): ' . implode( ', ', $state['skipped_views'] ) );
				}
			}
			$db->restore_connection_charset();

			$state['tables']      = $tables;
			$state['table_meta']  = $meta;
			$state['db_size']     = $db_size;
			$state['dump_dir']    = $tmp . '/database';
			$state['dump']        = Dump_Writer::initial_state( $tables );
			$state['contents']    = $options['mode'];
			$state['file_list']   = $tmp . '/file-list.json';
			$state['file_index']  = 0;
			$state['file_offset'] = 0;
			$state['files_found'] = 0;
			$state['files_size']  = 0;
			$state['file_count']  = 0;
			$state['prep_ready']  = true;
			$state['scan_done']   = ( 'database' === $options['mode'] );

			if ( 'database' !== $options['mode'] ) {
				$state['excludes']   = $this->build_excludes( $options );
				$state['scan_root']  = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
				$state['scan_queue'] = $tmp . '/scan-queue.txt';
				file_put_contents( $state['scan_queue'], WP_CONTENT_DIR );
				file_put_contents( $state['file_list'], '' );
				if ( ! empty( $options['include_core'] ) ) {
					file_put_contents( $state['scan_queue'], WP_CONTENT_DIR . "\n" . ABSPATH . 'wp-admin' . "\n" . ABSPATH . 'wp-includes' );
				}
			}

			$state['activity'] = $this->activity(
				'preparing',
				__( 'Preparing website', 'jisento' ),
				__( 'Scanning the site', 'jisento' ),
				count( $tables ),
				count( $tables ),
				__( 'Database', 'jisento' ),
				__( 'tables', 'jisento' )
			);
			return $this->save_work( $job, $state, 'preparing', $this->export_percent( $options['mode'], 'preparing', 0.2 ), 0, $db_size );
		}

		if ( empty( $state['scan_done'] ) ) {
			$more = $this->scan_batch( $state, 4 );
			$state['activity'] = $this->activity(
				'preparing',
				__( 'Preparing website', 'jisento' ),
				isset( $state['scan_current'] ) ? $state['scan_current'] : __( 'Scanning wp-content', 'jisento' ),
				(int) $state['files_found'],
				0,
				__( 'Files found', 'jisento' ),
				''
			);
			if ( $more ) {
				return $this->save_work( $job, $state, 'preparing', $this->export_percent( $options['mode'], 'preparing', 0.6 ), (int) $state['files_size'], (int) $state['db_size'] + (int) $state['files_size'] );
			}
			$state['file_count'] = (int) $state['files_found'];
			$state['scan_done']  = true;
			$plugin->logger->log( $job->job_id, 'preparing', 'scan', '', 'ok', sprintf( 'Tables: %d, files: %d', isset( $state['tables'] ) ? count( $state['tables'] ) : 0, (int) $state['file_count'] ) );
		}

		$next = ( 'files' === $options['mode'] ) ? 'packaging' : 'exporting_database';
		if ( 'packaging' === $next ) {
			$state['activity'] = $this->activity( 'packaging', __( 'Adding files to package', 'jisento' ), __( 'Starting package', 'jisento' ), 0, (int) $state['file_count'], __( 'Files', 'jisento' ), '' );
		} else {
			$table_total = isset( $state['tables'] ) ? count( $state['tables'] ) : 0;
			$state['activity'] = $this->activity( 'exporting_database', __( 'Exporting database', 'jisento' ), __( 'Starting database export', 'jisento' ), 0, $table_total, __( 'Database', 'jisento' ), __( 'tables', 'jisento' ) );
		}

		return $this->save_work( $job, $state, $next, $this->export_percent( $options['mode'], 'preparing', 1 ), 0, (int) $state['db_size'] + (int) $state['files_size'] );
	}

	private function scan_batch( array &$state, $seconds ) {
		$queue_path = $state['scan_queue'];
		$list_path  = $state['file_list'];
		$root       = rtrim( str_replace( '\\', '/', $state['scan_root'] ), '/' );
		$queue      = is_readable( $queue_path ) ? file( $queue_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
		if ( ! $queue ) {
			return false;
		}

		$excludes = isset( $state['excludes'] ) ? $state['excludes'] : array();
		$found    = (int) $state['files_found'];
		$size     = (int) $state['files_size'];
		$start    = time();
		$index    = 0;
		$list     = fopen( $list_path, 'ab' );
		if ( ! $list ) {
			throw new \RuntimeException( __( 'Unable to write the file list for this backup.', 'jisento' ) );
		}

		while ( $index < count( $queue ) && ( time() - $start ) < $seconds ) {
			$dir = $queue[ $index ];
			$index++;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			if ( ! is_readable( $dir ) ) {
				fclose( $list );
				throw new \RuntimeException( sprintf( __( 'Cannot read directory: %s', 'jisento' ), $dir ) );
			}
			$items = scandir( $dir );
			if ( false === $items ) {
				fclose( $list );
				throw new \RuntimeException( sprintf( __( 'Cannot read directory: %s', 'jisento' ), $dir ) );
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$full = $dir . '/' . $item;
				if ( is_link( $full ) ) {
					continue;
				}
				$full_slash = str_replace( '\\', '/', $full );
				$abs_root   = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
				if ( 0 === strpos( $full_slash, $root . '/' ) || $full_slash === $root ) {
					$rel         = ltrim( substr( $full_slash, strlen( $root ) ), '/' );
					$archive_rel = 'wp-content/' . $rel;
					if ( File_System::is_excluded( $rel, $excludes ) ) {
						continue;
					}
					// Every copy of this plugin, under any folder name, describes this install only.
					if ( preg_match( '#^plugins/[^/]+$#', $rel ) && is_dir( $full ) && File_System::is_jisento_plugin_dir( $full ) ) {
						$state['skipped_plugin_copies'][] = $rel;
						continue;
					}
				} elseif ( 0 === strpos( $full_slash, $abs_root . '/' ) ) {
					$archive_rel = ltrim( substr( $full_slash, strlen( $abs_root ) ), '/' );
				} else {
					continue;
				}
				if ( is_dir( $full ) ) {
					$queue[] = $full;
					continue;
				}
				if ( ! is_file( $full ) ) {
					continue;
				}
				$bytes = (int) filesize( $full );
				$row   = array(
					'source'   => $full,
					'relative' => $archive_rel,
					'size'     => $bytes,
				);
				$line = Job_Store::encode_state( $row ) . "\n";
				if ( fwrite( $list, $line ) !== strlen( $line ) ) {
					fclose( $list );
					throw new \RuntimeException( __( 'Operation: write the file list. Reason: the write failed (disk full or quota reached). Recovery: free disk space, then press Retry.', 'jisento' ) );
				}
				$found++;
				$size += $bytes;
				$state['scan_current'] = Job_Store::is_utf8( $archive_rel ) ? $archive_rel : 'hex:' . bin2hex( $archive_rel );
			}
		}
		fclose( $list );

		$queue = array_slice( $queue, $index );
		file_put_contents( $queue_path, $queue ? implode( "\n", $queue ) . "\n" : '' );
		$state['files_found'] = $found;
		$state['files_size']  = $size;
		return ! empty( $queue );
	}

	private function build_excludes( array $options ) {
		$excludes = array( 'jisento' );
		foreach ( File_System::own_runtime_files() as $item ) {
			$excludes[] = $item;
		}
		$own = rtrim( str_replace( '\\', '/', JISENTO_PATH ), '/' );
		$content = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		if ( 0 === strpos( $own, $content . '/' ) ) {
			$excludes[] = substr( $own, strlen( $content ) + 1 );
		}
		if ( ! empty( $options['skip_cache'] ) ) {
			foreach ( File_System::default_cache_excludes() as $item ) {
				$excludes[] = $this->strip_wp_content( $item );
			}
		}
		if ( ! empty( $options['skip_backups'] ) ) {
			foreach ( File_System::backup_excludes() as $item ) {
				$excludes[] = $this->strip_wp_content( $item );
			}
		}
		foreach ( File_System::environment_excludes() as $item ) {
			$excludes[] = $this->strip_wp_content( $item );
		}
		foreach ( $options['exclude_plugins'] as $plugin ) {
			$excludes[] = 'plugins/' . trim( $plugin, '/' );
		}
		foreach ( $options['exclude_dirs'] as $dir ) {
			$excludes[] = $this->strip_wp_content( $dir );
		}
		return array_values( array_unique( array_filter( $excludes ) ) );
	}

	private function strip_wp_content( $path ) {
		$path = ltrim( str_replace( '\\', '/', $path ), '/' );
		if ( 0 === strpos( $path, 'wp-content/' ) ) {
			return substr( $path, strlen( 'wp-content/' ) );
		}
		return $path;
	}

	/**
	 * One database segment per step: database/part-NNNNN.sql is written as .partial, fsynced,
	 * renamed, and only then is the cursor saved with the job state. Nothing is ever truncated.
	 */
	private function export_database( $job, array $state ) {
		$tables = isset( $state['tables'] ) ? $state['tables'] : array();
		$dump   = isset( $state['dump'] ) && is_array( $state['dump'] ) ? $state['dump'] : Dump_Writer::initial_state( $tables );
		$db     = new Database_Exporter();
		$writer = new Dump_Writer( $state['dump_dir'], $db );
		$next   = $writer->step( $dump, 8, 500 );
		$db->restore_connection_charset();
		if ( is_wp_error( $next ) ) {
			throw new \RuntimeException( $next->get_error_message() );
		}
		$state['dump'] = $next;

		$mode        = isset( $state['options']['mode'] ) ? $state['options']['mode'] : 'full';
		$index       = (int) $next['index'];
		$table_total = count( $tables );
		$sql_bytes   = 0;
		foreach ( $next['segments'] as $segment ) {
			$sql_bytes += (int) $segment['bytes'];
		}
		if ( ! empty( $next['done'] ) ) {
			$state['sql_bytes'] = $sql_bytes;
			$unstable = array();
			foreach ( $next['table_stats'] as $table => $stat ) {
				if ( empty( $stat['stable'] ) ) {
					$unstable[] = $table;
				}
			}
			if ( $unstable ) {
				Plugin::instance()->logger->log( $job->job_id, 'exporting_database', 'pagination', '', 'warning', 'Exported without a stable key (no primary key and no unique NOT NULL index; rows ordered by every column): ' . implode( ', ', $unstable ) );
			}
			$stage    = 'packaging';
			$progress = $this->export_percent( $mode, 'database', 1 );
		} else {
			$stage    = 'exporting_database';
			$progress = $this->export_percent( $mode, 'database', $table_total > 0 ? $index / $table_total : 1 );
		}

		$detail = isset( $tables[ $index ] ) ? $tables[ $index ] : __( 'Database export complete', 'jisento' );
		if ( isset( $tables[ $index ], $next['table_stats'][ $tables[ $index ] ] ) ) {
			$detail .= ' (' . sprintf( __( 'row %s', 'jisento' ), number_format_i18n( (int) $next['table_stats'][ $tables[ $index ] ]['rows'] ) ) . ')';
		}
		$state['activity'] = $this->activity(
			'exporting_database',
			$index >= $table_total ? __( 'Exporting database', 'jisento' ) : __( 'Processing database tables', 'jisento' ),
			$detail,
			min( $index, $table_total ),
			$table_total,
			__( 'Database', 'jisento' ),
			__( 'tables', 'jisento' )
		);
		$bytes_total = (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 );

		return $this->save_work( $job, $state, $stage, $progress, $sql_bytes, $bytes_total );
	}

	private function export_files( $job, array $state ) {
		$plugin  = Plugin::instance();
		$tmp     = $plugin->storage->tmp_dir( $job->job_id ) . '/files';
		$total   = (int) ( $state['file_count'] ?? ( isset( $state['files'] ) ? count( $state['files'] ) : 0 ) );
		$index   = (int) $state['file_index'];
		$start   = time();
		$copied  = isset( $state['files_copied_size'] ) ? (int) $state['files_copied_size'] : 0;
		$current = '';
		$list    = isset( $state['file_list'] ) ? $state['file_list'] : '';

		if ( $total < 1 ) {
			return $plugin->jobs->update(
				$job,
				array(
					'stage'        => 'packaging',
					'progress'     => $this->export_percent( isset( $state['options']['mode'] ) ? $state['options']['mode'] : 'full', 'files', 1 ),
					'current_item' => __( 'No files to export', 'jisento' ),
					'state'        => $state,
				)
			);
		}

		$fh = fopen( $list, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( __( 'The file list for this backup is missing.', 'jisento' ) );
		}
		$offset = (int) ( $state['file_offset'] ?? 0 );
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		while ( $index < $total && ( time() - $start ) < 4 ) {
			$line = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			$file = Job_Store::decode_value( json_decode( trim( $line ), true ) );
			$index++;
			if ( ! is_array( $file ) || empty( $file['relative'] ) || empty( $file['source'] ) ) {
				continue;
			}
			$dest    = $tmp . '/' . $file['relative'];
			$current = $file['relative'];
			if ( ! is_file( $file['source'] ) ) {
				fclose( $fh );
				throw new \RuntimeException( sprintf( __( 'Source file disappeared during export: %s', 'jisento' ), $file['relative'] ) );
			}
			if ( ! File_System::stream_copy( $file['source'], $dest ) || ! is_file( $dest ) ) {
				fclose( $fh );
				throw new \RuntimeException( sprintf( __( 'Unable to copy %s into the backup staging folder.', 'jisento' ), $file['relative'] ) );
			}
			$copied += (int) filesize( $dest );
		}

		$state['file_offset']       = (int) ftell( $fh );
		$eof                        = feof( $fh );
		fclose( $fh );
		$state['file_index']        = $index;
		$state['files_copied_size'] = $copied;
		$done                       = $eof || $index >= $total;
		if ( $done && $index < $total ) {
			throw new \RuntimeException( __( 'The file list ended before every file was copied.', 'jisento' ) );
		}
		$mode                       = isset( $state['options']['mode'] ) ? $state['options']['mode'] : 'full';
		$file_bytes                 = (int) ( $state['files_size'] ?? 0 );
		$file_ratio                 = $done ? 1 : ( $file_bytes > 0 ? min( 1, $copied / $file_bytes ) : ( $index / max( 1, $total ) ) );
		$progress                   = $this->export_percent( $mode, 'files', $file_ratio );

		$label = ( false !== strpos( (string) $current, 'wp-content/uploads/' ) )
			? __( 'Processing uploads', 'jisento' )
			: __( 'Exporting wp-content', 'jisento' );
		$state['activity'] = $this->activity(
			'exporting_files',
			$done ? __( 'Exporting wp-content', 'jisento' ) : $label,
			$current ? $current : __( 'Copying files', 'jisento' ),
			min( $index, $total ),
			$total,
			__( 'Files', 'jisento' ),
			''
		);
		$sql_bytes = (int) ( isset( $state['sql_bytes'] ) ? $state['sql_bytes'] : 0 );

		return $this->save_work(
			$job,
			$state,
			$done ? 'packaging' : 'exporting_files',
			$progress,
			$sql_bytes + $copied,
			(int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 )
		);
	}

	private function backup_filename( $mode, $backup_type, $job_id ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( (string) $host );
		$host = preg_replace( '/[^a-z0-9.-]+/', '-', $host );
		$host = trim( (string) $host, '.-' );
		if ( '' === $host ) {
			$host = 'site';
		}
		$prefix = '';
		$suffix = substr( preg_replace( '/[^a-z0-9]/', '', (string) $job_id ), -6 );
		return $prefix . $host . '-' . gmdate( 'Y-m-d-His' ) . ( $suffix ? '-' . $suffix : '' ) . '.jisento';
	}

	private function package( $job, array $state ) {
		unset( $state['checksums'], $state['files'] );
		$plugin  = Plugin::instance();
		$archive = new Archive();
		$tmp     = $plugin->storage->tmp_dir( $job->job_id );
		$partial = $tmp . '/package.zip';
		$options = isset( $state['options'] ) ? $state['options'] : array();
		$mode    = isset( $options['mode'] ) ? $options['mode'] : 'full';

		if ( empty( $state['package_name'] ) ) {
			$state['package_name'] = $this->backup_filename( $mode, isset( $options['backup_type'] ) ? $options['backup_type'] : 'manual', $job->job_id );
		}

		if ( empty( $state['zip_ready'] ) ) {
			$manifest = $this->manifest( $state, $mode, 0, 0 );
			$begun    = $archive->begin( $partial, $manifest );
			if ( is_wp_error( $begun ) ) {
				throw new \RuntimeException( $begun->get_error_message() );
			}
			$state['zip_ready']  = true;
			$state['zip_phase']  = ( 'files' === $mode ) ? 'files' : 'database';
			$state['zip_index']  = 0;
			$state['zip_offset'] = 0;
			$state['activity']   = $this->activity( 'packaging', __( 'Finalizing package', 'jisento' ), __( 'Writing package header', 'jisento' ), 0, (int) ( $state['file_count'] ?? 0 ), __( 'Files', 'jisento' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 0.01 ), __( 'Writing package header', 'jisento' ), (int) filesize( $partial ) );
		}

		if ( 'finalize' === ( $state['zip_phase'] ?? '' ) ) {
			return $this->finalize_package( $job, $state, $partial, $tmp, $mode );
		}

		if ( 'database' === ( $state['zip_phase'] ?? '' ) ) {
			$segments = isset( $state['dump']['segments'] ) ? $state['dump']['segments'] : array();
			if ( 'files' !== $mode && ! $segments ) {
				throw new \RuntimeException( __( 'Operation: add the database to the package. Reason: no database segments were written. Recovery: start a new export.', 'jisento' ) );
			}
			$seg_index = (int) ( $state['zip_seg_index'] ?? 0 );
			$batch     = array();
			$bytes     = 0;
			while ( $seg_index < count( $segments ) && ( ! $batch || $bytes < 33554432 ) ) {
				$segment = $segments[ $seg_index ];
				$source  = $state['dump_dir'] . '/' . basename( $segment['entry'] );
				clearstatcache( true, $source );
				if ( ! is_file( $source ) || (int) filesize( $source ) !== (int) $segment['bytes'] ) {
					throw new \RuntimeException( sprintf( __( 'Operation: add the database to the package. Reason: segment %s is missing or changed size after it was written. Recovery: start a new export.', 'jisento' ), $segment['entry'] ) );
				}
				$batch[] = array(
					'source' => $source,
					'local'  => $segment['entry'],
				);
				$bytes += (int) $segment['bytes'];
				$seg_index++;
			}
			if ( $batch ) {
				$added = $archive->add_batch( $partial, $batch );
				if ( is_wp_error( $added ) ) {
					throw new \RuntimeException( $added->get_error_message() );
				}
			}
			$state['zip_seg_index'] = $seg_index;
			if ( $seg_index < count( $segments ) ) {
				$state['activity'] = $this->activity( 'packaging', __( 'Adding database to package', 'jisento' ), sprintf( __( 'Segment %1$d of %2$d', 'jisento' ), $seg_index, count( $segments ) ), $seg_index, count( $segments ), __( 'Segments', 'jisento' ), '' );
				return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 0.02 ), __( 'Adding database to package', 'jisento' ), (int) filesize( $partial ) );
			}
			$state['zip_phase'] = ( 'database' === $mode ) ? 'finalize' : 'files';
			$state['zip_index'] = 0;
			$state['activity']  = $this->activity( 'packaging', __( 'Adding files to package', 'jisento' ), __( 'Adding database to package', 'jisento' ), 0, (int) ( $state['file_count'] ?? 0 ), __( 'Files', 'jisento' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 'database' === $mode ? 0.35 : 0.03 ), __( 'Adding database to package', 'jisento' ), (int) filesize( $partial ) );
		}

		if ( 'files' === ( $state['zip_phase'] ?? '' ) ) {
			$total   = (int) ( $state['file_count'] ?? 0 );
			$index   = (int) ( $state['zip_index'] ?? 0 );
			$list    = isset( $state['file_list'] ) ? $state['file_list'] : '';
			$batch   = array();
			$start   = time();
			$current = '';
			$bytes   = 0;

			if ( $total > 0 ) {
				if ( ! is_readable( $list ) ) {
					throw new \RuntimeException( __( 'The file list for this backup is missing.', 'jisento' ) );
				}
				$fh     = fopen( $list, 'rb' );
				$offset = (int) ( $state['zip_offset'] ?? 0 );
				if ( ! $fh ) {
					throw new \RuntimeException( __( 'The file list for this backup is missing.', 'jisento' ) );
				}
				if ( $offset > 0 ) {
					fseek( $fh, $offset );
				}
				while ( $index < $total && ( time() - $start ) < 8 ) {
					if ( $bytes > 33554432 && count( $batch ) > 0 ) {
						break;
					}
					$line = fgets( $fh );
					if ( false === $line ) {
						break;
					}
					$row = Job_Store::decode_value( json_decode( trim( $line ), true ) );
					$index++;
					if ( ! is_array( $row ) || empty( $row['relative'] ) ) {
						continue;
					}
					$source = ( isset( $row['source'] ) && is_file( $row['source'] ) ) ? $row['source'] : ( $tmp . '/files/' . $row['relative'] );
					if ( ! is_file( $source ) ) {
						fclose( $fh );
						throw new \RuntimeException( sprintf( __( 'Operation: add files to the package. Reason: the source file disappeared or is unreadable: %s. Recovery: make sure the file exists and is readable, then start a new export.', 'jisento' ), Job_Store::is_utf8( $row['relative'] ) ? $row['relative'] : 'hex:' . bin2hex( $row['relative'] ) ) );
					}
					$batch[] = array(
						'source' => $source,
						'local'  => 'files/' . $row['relative'],
					);
					$current = $row['relative'];
					$bytes  += (int) ( isset( $row['size'] ) ? $row['size'] : filesize( $source ) );
					if ( $bytes > 33554432 ) {
						break;
					}
				}
				$state['zip_offset'] = (int) ftell( $fh );
				$eof                 = feof( $fh );
				fclose( $fh );
				if ( $batch ) {
					$added = $archive->add_batch( $partial, $batch );
					if ( is_wp_error( $added ) ) {
						throw new \RuntimeException( $added->get_error_message() );
					}
				}
				$state['zip_index'] = $index;
				$state['zip_bytes'] = (int) ( isset( $state['zip_bytes'] ) ? $state['zip_bytes'] : 0 ) + $bytes;
				$done               = ( $eof || $index >= $total ) && ( time() - $start ) < 4;
				if ( $eof && $index < $total ) {
					throw new \RuntimeException( __( 'The file list ended before every file was added to the package.', 'jisento' ) );
				}
				$file_bytes = (int) ( $state['files_size'] ?? 0 );
				$zip_ratio  = $done ? 1 : ( $file_bytes > 0 ? min( 1, (int) $state['zip_bytes'] / $file_bytes ) : ( $index / max( 1, $total ) ) );
				if ( ! $done ) {
					$state['activity'] = $this->activity( 'packaging', __( 'Adding files to package', 'jisento' ), $current ? $current : __( 'Adding files to package', 'jisento' ), $index, $total, __( 'Files', 'jisento' ), '' );
					$state['activity']['measure_kind']  = 'bytes';
					$state['activity']['measure_label'] = __( 'Processed', 'jisento' );
					$state['activity']['measure_done']  = (int) $state['zip_bytes'];
					$state['activity']['measure_total'] = $file_bytes;
					return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', $zip_ratio ), $current, (int) filesize( $partial ) );
				}
			}

			$state['zip_phase'] = 'finalize';
			$state['activity']  = $this->activity( 'finalizing', __( 'Finalizing package', 'jisento' ), __( 'Package contents are ready', 'jisento' ), (int) ( $state['file_count'] ?? 0 ), (int) ( $state['file_count'] ?? 0 ), __( 'Files', 'jisento' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 1 ), __( 'Finalizing package', 'jisento' ), (int) filesize( $partial ) );
		}

		throw new \RuntimeException( __( 'The package was not finished because the export did not reach the packaging step.', 'jisento' ) );
	}

	private function finalize_package( $job, array $state, $partial, $tmp, $mode ) {
		$plugin = Plugin::instance();
		$archive = new Archive();
		clearstatcache( true, $partial );
		if ( ! is_file( $partial ) || filesize( $partial ) <= 0 ) {
			throw new \RuntimeException( __( 'The package file was not written.', 'jisento' ) );
		}

		$expected_files = (int) ( $state['file_count'] ?? 0 );
		if ( 'database' !== $mode && $expected_files > 0 && (int) ( $state['zip_index'] ?? 0 ) < $expected_files ) {
			throw new \RuntimeException( __( 'The package is incomplete because not every file was added.', 'jisento' ) );
		}

		$name = $state['package_name'];
		$key  = 'packages/' . $name;
		$dest = $plugin->storage->get_path( $key );
		$step = isset( $state['finalize_step'] ) ? $state['finalize_step'] : 'manifest';

		if ( 'manifest' === $step ) {
			// The final counts come from the package's own central directory, so the manifest
			// describes exactly what was written and an import can detect any later change.
			$counted = $this->count_file_entries( $partial );
			if ( 'database' !== $mode && $counted['count'] !== $expected_files ) {
				throw new \RuntimeException( sprintf( __( 'Operation: finalize the package. Reason: %1$d files were scanned but %2$d are in the package. Recovery: start a new export.', 'jisento' ), $expected_files, $counted['count'] ) );
			}
			$written = $archive->write_manifest( $partial, $this->manifest( $state, $mode, $counted['count'], $counted['bytes'] ) );
			if ( is_wp_error( $written ) ) {
				throw new \RuntimeException( $written->get_error_message() );
			}
			$state['files_bytes'] = $counted['bytes'];
			wp_mkdir_p( dirname( $dest ) );
			if ( file_exists( $dest ) ) {
				@unlink( $dest );
			}
			if ( is_file( $dest . '.json' ) ) {
				@unlink( $dest . '.json' );
			}
			$state['finalize_step'] = 'copy';
			$state['copy_offset']   = 0;
			$state['activity']      = $this->activity( 'finalizing', __( 'Finalizing package', 'jisento' ), __( 'Writing package file', 'jisento' ), 0, (int) filesize( $partial ), __( 'Package', 'jisento' ), '' );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.08 ), 0, (int) filesize( $partial ) );
		}

		if ( 'copy' === $step ) {
			$chunk                = $this->copy_chunk( $partial, $dest, (int) ( $state['copy_offset'] ?? 0 ), 4 );
			$state['copy_offset'] = $chunk['offset'];
			if ( empty( $chunk['done'] ) ) {
				$state['activity'] = $this->activity(
					'finalizing',
					__( 'Finalizing package', 'jisento' ),
					__( 'Writing package file', 'jisento' ),
					$chunk['offset'],
					$chunk['total'],
					__( 'Package', 'jisento' ),
					''
				);
				$copy_ratio = $chunk['total'] > 0 ? min( 1, $chunk['offset'] / $chunk['total'] ) : 0;
				return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.08 + ( 0.72 * $copy_ratio ) ), $chunk['offset'], $chunk['total'] );
			}
			$state['finalize_step'] = 'verify';
			$state['activity']      = $this->activity( 'verifying', __( 'Verifying package', 'jisento' ), $name, 1, 1, __( 'Package', 'jisento' ), '' );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.84 ), $chunk['total'], $chunk['total'] );
		}

		if ( 'verify' === $step ) {
			$this->assert_package( $dest );
			$state['finalize_step'] = 'hash';
			$state['activity']      = $this->activity( 'checksum', __( 'Calculating checksum', 'jisento' ), $name, 0, 1, __( 'Package', 'jisento' ), '' );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.9 ), (int) filesize( $dest ), (int) filesize( $dest ) );
		}

		if ( 'hash' === $step ) {
			$checksum = File_System::hash_file( $dest );
			if ( ! is_string( $checksum ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
				throw new \RuntimeException( __( 'Package checksum validation failed.', 'jisento' ) );
			}
			$state['package_checksum']   = $checksum;
			$state['package_hashed_size'] = (int) filesize( $dest );
			$state['finalize_step']      = 'confirm';
			$state['activity']         = $this->activity( 'checksum', __( 'Calculating checksum', 'jisento' ), __( 'Re-checking the saved package', 'jisento' ), 1, 1, __( 'Package', 'jisento' ), '' );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.96 ), (int) filesize( $dest ), (int) filesize( $dest ) );
		}

		$checksum = isset( $state['package_checksum'] ) ? $state['package_checksum'] : '';
		clearstatcache( true, $dest );
		$hashed_size = isset( $state['package_hashed_size'] ) ? (int) $state['package_hashed_size'] : 0;
		if ( ! is_string( $checksum ) || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || $hashed_size !== (int) filesize( $dest ) ) {
			throw new \RuntimeException( __( 'Package checksum validation failed after the file was saved.', 'jisento' ) );
		}
		$size = (int) filesize( $dest );

		$kind = $mode;
		$meta        = array(
			'filename'     => $name,
			'storage_key'  => $key,
			'size'         => $size,
			'checksum'     => $checksum,
			'type'         => $kind,
			'migration_id' => $job->job_id,
			'status'       => 'completed',
			'created_at'         => current_time( 'mysql' ),
			'package_version'    => JISENTO_PACKAGE_VERSION,
			'format_marker'      => JISENTO_FORMAT_MARKER,
			'home_url'           => home_url(),
			'database_size'      => (int) ( $state['db_size'] ?? 0 ),
			'files_size'         => (int) ( $state['files_size'] ?? 0 ),
			'uncompressed_size'  => (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 ),
			'table_count'        => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
			'file_count'         => (int) ( $state['file_count'] ?? 0 ),
			'contents'           => $mode,
		);
		file_put_contents( $dest . '.json', wp_json_encode( $meta ) );
		clearstatcache( true, $dest );
		@touch( $dest );

		\Jisento\Migration\Core\Installer::maybe_upgrade();
		$registry = new \Jisento\Migration\Package\Package_Registry();
		$pack_id  = $registry->upsert_completed( $meta );
		if ( ! $pack_id ) {
			global $wpdb;
			$detail = $wpdb->last_error ? ' ' . $wpdb->last_error : '';
			throw new \RuntimeException( __( 'The package file was saved, but it could not be added to the backup registry.', 'jisento' ) . $detail );
		}

		$plugin->storage->delete_tree( $tmp );

		clearstatcache( true, $dest );
		$size = (int) filesize( $dest );
		if ( $size <= 0 || $size !== (int) $state['package_hashed_size'] ) {
			throw new \RuntimeException( __( 'The .jisento file changed after it was verified.', 'jisento' ) );
		}

		$state['activity']     = $this->activity( 'completed', __( 'Backup completed', 'jisento' ), $name, 1, 1, __( 'Package', 'jisento' ), '' );
		$state['package']      = $key;
		$state['package_abs']  = $dest;
		$state['package_size'] = $size;
		$state['package_id']   = $pack_id;
		$state['package_name'] = $name;
		$state['report']       = array(
			'source'    => home_url(),
			'started'   => $job->created_at,
			'completed' => current_time( 'mysql' ),
			'database'  => File_System::readable_size( $state['db_size'] ?? 0 ),
			'files'     => File_System::readable_size( $state['files_size'] ?? 0 ),
			'total'     => File_System::readable_size( $size ),
			'tables'    => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
			'files_n'   => (int) ( $state['file_count'] ?? 0 ),
			'package'   => $name,
			'size'      => $size,
			'checksum'  => $checksum,
		);

		$plugin->logger->log( $job->job_id, 'packaging', 'package', $name, 'ok', 'Verified package bytes=' . $size );

		$saved = $plugin->jobs->update(
			$job,
			array(
				'status'       => 'completed',
				'stage'        => 'completed',
				'progress'     => 100,
				'current_item' => __( 'Backup completed', 'jisento' ),
				'bytes_done'   => $size,
				'bytes_total'  => $size,
				'state'        => $state,
			)
		);

		if ( ! $saved || 'completed' !== $saved->status ) {
			throw new \RuntimeException( __( 'The package was written, but the backup record could not be saved.', 'jisento' ) );
		}

		return $saved;
	}

	/**
	 * @return array{count:int,bytes:int}
	 */
	private function count_file_entries( $zip_path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \RuntimeException( __( 'Operation: finalize the package. Reason: the package could not be reopened. Recovery: start a new export.', 'jisento' ) );
		}
		$count = 0;
		$bytes = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( $stat && 0 === strpos( (string) $stat['name'], 'files/' ) && '/' !== substr( (string) $stat['name'], -1 ) ) {
				$count++;
				$bytes += (int) $stat['size'];
			}
		}
		$zip->close();
		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	private function manifest( array $state, $mode, $file_count, $files_bytes ) {
		$dump   = isset( $state['dump'] ) && is_array( $state['dump'] ) ? $state['dump'] : array();
		$tables = array();
		if ( ! empty( $dump['table_stats'] ) ) {
			foreach ( $dump['table_stats'] as $table => $stat ) {
				$tables[ $table ] = array(
					'rows'   => (int) $stat['rows'],
					'bytes'  => (int) $stat['bytes'],
					'sha256' => (string) $stat['sha256'],
					'key'    => (string) $stat['key'],
					'stable' => (bool) $stat['stable'],
				);
			}
		}
		return array(
			'package_version'   => JISENTO_PACKAGE_VERSION,
			'plugin_version'    => JISENTO_VERSION,
			'format_marker'     => JISENTO_FORMAT_MARKER,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'database_prefix'   => $GLOBALS['wpdb']->prefix,
			'charset'           => $GLOBALS['wpdb']->charset,
			'collate'           => $GLOBALS['wpdb']->collate,
			'created_at'        => gmdate( 'c' ),
			'include_core'      => ! empty( $state['options']['include_core'] ),
			'database_size'     => (int) ( $state['db_size'] ?? 0 ),
			'files_size'        => (int) ( $state['files_size'] ?? 0 ),
			'uncompressed_size' => (int) ( $state['sql_bytes'] ?? 0 ) + (int) $files_bytes,
			'table_count'       => count( $tables ),
			'file_count'        => (int) $file_count,
			'files_bytes'       => (int) $files_bytes,
			'contents'          => $mode,
			'database'          => array(
				'segments'      => isset( $dump['segments'] ) ? array_values( $dump['segments'] ) : array(),
				'tables'        => (object) $tables,
				'skipped_views' => isset( $state['skipped_views'] ) ? array_values( $state['skipped_views'] ) : array(),
			),
		);
	}

	private static function stage_message( $job, $message ) {
		$message = trim( (string) $message );
		if ( '' === $message ) {
			$message = __( 'The export stopped but PHP supplied no error message. Check the server PHP error log.', 'jisento' );
		}
		if ( false === strpos( $message, 'Stage:' ) ) {
			$message = 'Stage: ' . $job->stage . '. ' . $message;
		}
		if ( false === strpos( $message, 'Job: ' ) ) {
			$message .= ' Job: ' . $job->job_id;
		}
		return $message;
	}

	private function assert_package( $path ) {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new \RuntimeException( __( 'The .jisento file does not exist after export.', 'jisento' ) );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			throw new \RuntimeException( __( 'The .jisento file is empty (0 bytes).', 'jisento' ) );
		}
		$verify = ( new \Jisento\Migration\Package\Package_Registry() )->verify_file( $path, 'full' );
		if ( empty( $verify['ok'] ) ) {
			throw new \RuntimeException( $verify['reason'] ? $verify['reason'] : __( 'Package verification failed.', 'jisento' ) );
		}
		return array(
			'size' => $size,
		);
	}

	private function discard_unverified_package( array $state ) {
		if ( empty( $state['package_name'] ) ) {
			return;
		}
		$dest = Plugin::instance()->storage->get_path( 'packages/' . $state['package_name'] );
		if ( ! is_file( $dest ) ) {
			return;
		}
		$sidecar = $dest . '.json';
		if ( is_readable( $sidecar ) ) {
			$saved = json_decode( (string) file_get_contents( $sidecar ), true );
			clearstatcache( true, $dest );
			if ( is_array( $saved ) && isset( $saved['status'] ) && 'completed' === $saved['status'] && isset( $saved['size'] ) && (int) $saved['size'] > 0 && (int) $saved['size'] === (int) filesize( $dest ) ) {
				return;
			}
		}
		try {
			$check = ( new \Jisento\Migration\Package\Package_Registry() )->verify_file( $dest, 'full' );
		} catch ( \Throwable $e ) {
			$check = array( 'ok' => false );
		}
		if ( empty( $check['ok'] ) ) {
			@unlink( $dest );
			if ( is_file( $dest . '.json' ) ) {
				@unlink( $dest . '.json' );
			}
		}
	}

	private function activity( $phase, $label, $detail, $done, $total, $unit, $suffix ) {
		return array(
			'phase'       => $phase,
			'label'       => $label,
			'detail'      => $detail,
			'count_done'  => (int) $done,
			'count_total' => (int) $total,
			'count_unit'  => $unit,
			'count_suffix'=> $suffix,
		);
	}

	private function export_percent( $mode, $phase, $ratio ) {
		$ratio = max( 0, min( 1, (float) $ratio ) );
		if ( 'database' === $mode ) {
			$bands = array(
				'preparing' => array( 0, 4 ),
				'database'  => array( 4, 68 ),
				'files'     => array( 68, 70 ),
				'zip'       => array( 70, 78 ),
				'finalize'  => array( 78, 99 ),
			);
		} elseif ( 'files' === $mode ) {
			$bands = array(
				'preparing' => array( 0, 6 ),
				'database'  => array( 6, 6 ),
				'files'     => array( 6, 22 ),
				'zip'       => array( 6, 88 ),
				'finalize'  => array( 88, 99 ),
			);
		} else {
			$bands = array(
				'preparing' => array( 0, 4 ),
				'database'  => array( 4, 16 ),
				'files'     => array( 16, 32 ),
				'zip'       => array( 16, 88 ),
				'finalize'  => array( 88, 99 ),
			);
		}
		$band = isset( $bands[ $phase ] ) ? $bands[ $phase ] : array( 0, 99 );
		return (int) floor( $band[0] + ( $band[1] - $band[0] ) * $ratio );
	}

	private function database_ratio( array $state, $path, $index, $count ) {
		if ( $count < 1 || $index >= $count ) {
			return 1;
		}
		$data_total = 0;
		if ( ! empty( $state['table_meta'] ) && is_array( $state['table_meta'] ) ) {
			foreach ( $state['table_meta'] as $info ) {
				if ( is_array( $info ) && isset( $info['data'] ) ) {
					$data_total += (int) $info['data'];
				}
			}
		}
		clearstatcache( true, $path );
		$sql = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $data_total > 0 && $sql > 0 ) {
			return min( 0.98, $sql / $data_total );
		}
		return $index / $count;
	}

	private function save_work( $job, array $state, $stage, $progress, $bytes_done, $bytes_total = null ) {
		unset( $state['checksums'], $state['files'] );
		$state['step_tick'] = (int) ( isset( $state['step_tick'] ) ? $state['step_tick'] : 0 ) + 1;
		$activity = ( isset( $state['activity'] ) && is_array( $state['activity'] ) ) ? $state['activity'] : array();
		$db_bytes = (int) ( isset( $state['db_size'] ) ? $state['db_size'] : 0 );
		$file_bytes = (int) ( isset( $state['files_size'] ) ? $state['files_size'] : 0 );
		$activity['sizes'] = array(
			'package'     => (int) ( isset( $state['package_size'] ) ? $state['package_size'] : 0 ),
			'database'    => $db_bytes,
			'files'       => $file_bytes,
			'contents'    => $db_bytes + $file_bytes,
			'file_count'  => (int) ( isset( $state['file_count'] ) ? $state['file_count'] : 0 ),
			'table_count' => ( isset( $state['tables'] ) && is_array( $state['tables'] ) ) ? count( $state['tables'] ) : 0,
		);
		if ( isset( $activity['count_unit'] ) && 'Package' === $activity['count_unit'] ) {
			$activity['measure_kind']  = 'bytes';
			$activity['measure_label'] = __( 'Package file', 'jisento' );
			$activity['measure_done']  = (int) $activity['count_done'];
			$activity['measure_total'] = (int) $activity['count_total'];
			$activity['count_done']    = 0;
			$activity['count_total']   = 0;
			$activity['count_unit']    = '';
		}
		if ( ! isset( $activity['stage_progress'] ) && ! empty( $activity['count_total'] ) ) {
			$activity['stage_progress'] = (int) min( 100, floor( (int) $activity['count_done'] * 100 / (int) $activity['count_total'] ) );
		}
		$state['activity'] = $activity;
		$item     = '';
		if ( ! empty( $activity['detail'] ) ) {
			$item = $activity['detail'];
		} elseif ( ! empty( $activity['label'] ) ) {
			$item = $activity['label'];
		}
		$fields = array(
			'status'       => 'running',
			'stage'        => $stage,
			'progress'     => max( 0, min( 99, (int) $progress ) ),
			'current_item' => $item,
			'bytes_done'   => max( 0, (int) $bytes_done ),
			'state'        => $state,
		);
		if ( null !== $bytes_total ) {
			$fields['bytes_total'] = max( 0, (int) $bytes_total );
		}
		return Plugin::instance()->jobs->update( $job, $fields );
	}

	private function save_progress( $job, array $state, $stage, $progress, $item, $bytes ) {
		if ( empty( $state['activity'] ) || ! is_array( $state['activity'] ) ) {
			$state['activity'] = $this->activity( $stage, $item, $item, 0, 0, '', '' );
		}
		$total = (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 );
		if ( $total < $bytes ) {
			$total = (int) $bytes;
		}
		return $this->save_work( $job, $state, $stage, $progress, $bytes, $total );
	}

	private function copy_chunk( $source, $dest, $offset, $seconds ) {
		$expected = is_file( $source ) ? (int) filesize( $source ) : 0;
		if ( $expected <= 0 ) {
			throw new \RuntimeException( __( 'The package file was not written.', 'jisento' ) );
		}
		wp_mkdir_p( dirname( $dest ) );
		$in = fopen( $source, 'rb' );
		if ( ! $in ) {
			throw new \RuntimeException( __( 'Unable to read the package while saving it.', 'jisento' ) );
		}
		$out = fopen( $dest, ( $offset > 0 && is_file( $dest ) ) ? 'rb+' : 'wb' );
		if ( ! $out ) {
			fclose( $in );
			throw new \RuntimeException( __( 'Unable to save the .jisento package into wp-content/jisento/packages/.', 'jisento' ) );
		}
		if ( $offset > 0 ) {
			ftruncate( $out, $offset );
			fseek( $out, $offset );
			fseek( $in, $offset );
		}
		$start = time();
		$pos   = $offset;
		while ( ( time() - $start ) < $seconds && ! feof( $in ) ) {
			$buffer = fread( $in, 1048576 );
			if ( false === $buffer ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( __( 'Unable to save the .jisento package into wp-content/jisento/packages/.', 'jisento' ) );
			}
			if ( '' === $buffer ) {
				break;
			}
			$written = fwrite( $out, $buffer );
			if ( false === $written || $written !== strlen( $buffer ) ) {
				fclose( $in );
				fclose( $out );
				throw new \RuntimeException( __( 'Unable to save the .jisento package into wp-content/jisento/packages/.', 'jisento' ) );
			}
			$pos += $written;
		}
		$done = feof( $in );
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $dest );
		if ( $done && (int) filesize( $dest ) !== $expected ) {
			throw new \RuntimeException( __( 'The saved package size does not match the file that was built.', 'jisento' ) );
		}
		return array(
			'offset' => $pos,
			'done'   => $done,
			'total'  => $expected,
		);
	}
}
