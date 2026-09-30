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
use Jisento\Migration\Jobs\Step_Budget;
use Jisento\Migration\Package\Archive;
use Jisento\Migration\Package\Streaming_Zip_Writer;
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
		Step_Budget::begin();

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
				__( 'Preparing website', 'jisento-migration' ),
				__( 'Scanning the site', 'jisento-migration' ),
				count( $tables ),
				count( $tables ),
				__( 'Database', 'jisento-migration' ),
				__( 'tables', 'jisento-migration' )
			);
			return $this->save_work( $job, $state, 'preparing', $this->export_percent( $options['mode'], 'preparing', 0.2 ), 0, $db_size );
		}

		if ( empty( $state['scan_done'] ) ) {
			$more = $this->scan_batch( $state, Step_Budget::seconds( 4 ) );
			$state['activity'] = $this->activity(
				'preparing',
				__( 'Preparing website', 'jisento-migration' ),
				isset( $state['scan_current'] ) ? $state['scan_current'] : __( 'Scanning wp-content', 'jisento-migration' ),
				(int) $state['files_found'],
				0,
				__( 'Files found', 'jisento-migration' ),
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
			$state['activity'] = $this->activity( 'packaging', __( 'Adding files to package', 'jisento-migration' ), __( 'Starting package', 'jisento-migration' ), 0, (int) $state['file_count'], __( 'Files', 'jisento-migration' ), '' );
		} else {
			$table_total = isset( $state['tables'] ) ? count( $state['tables'] ) : 0;
			$state['activity'] = $this->activity( 'exporting_database', __( 'Exporting database', 'jisento-migration' ), __( 'Starting database export', 'jisento-migration' ), 0, $table_total, __( 'Database', 'jisento-migration' ), __( 'tables', 'jisento-migration' ) );
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
			throw new \RuntimeException( __( 'Unable to write the file list for this backup.', 'jisento-migration' ) );
		}

		while ( $index < count( $queue ) && ( time() - $start ) < $seconds ) {
			$dir = $queue[ $index ];
			$index++;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			if ( ! is_readable( $dir ) ) {
				fclose( $list );
				throw new \RuntimeException( sprintf( __( 'Cannot read directory: %s', 'jisento-migration' ), $dir ) );
			}
			$items = scandir( $dir );
			if ( false === $items ) {
				fclose( $list );
				throw new \RuntimeException( sprintf( __( 'Cannot read directory: %s', 'jisento-migration' ), $dir ) );
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
					throw new \RuntimeException( __( 'Operation: write the file list. Reason: the write failed (disk full or quota reached). Recovery: free disk space, then press Retry.', 'jisento-migration' ) );
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
		if ( empty( $state['timing_db_started'] ) ) {
			\Jisento\Migration\Core\Timings::begin( $state, 'exporting_database', (int) ( $state['db_size'] ?? 0 ) );
			$state['timing_db_started'] = true;
		}
		$prev_index = isset( $dump['index'] ) ? (int) $dump['index'] : 0;
		if ( empty( $state['timing_table_key'] ) && isset( $tables[ $prev_index ] ) ) {
			$table = (string) $tables[ $prev_index ];
			$meta  = isset( $state['table_meta'][ $table ] ) && is_array( $state['table_meta'][ $table ] ) ? $state['table_meta'][ $table ] : array();
			$data  = isset( $meta['data'] ) ? (int) $meta['data'] : 0;
			if ( $data >= \Jisento\Migration\Core\Timings::LARGE_TABLE_BYTES ) {
				$key = 'table_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $table );
				\Jisento\Migration\Core\Timings::begin( $state, $key, $data );
				$state['timing_table_key']  = $key;
				$state['timing_table_name'] = $table;
			}
		}
		$db     = new Database_Exporter();
		$writer = new Dump_Writer( $state['dump_dir'], $db );
		$next   = $writer->step( $dump, max( 1, Step_Budget::seconds( 8 ) ), 500 );
		$db->restore_connection_charset();
		if ( is_wp_error( $next ) ) {
			throw new \RuntimeException( $next->get_error_message() );
		}
		$state['dump'] = $next;

		$new_index = (int) $next['index'];
		if ( ! empty( $state['timing_table_key'] ) && ( $new_index > $prev_index || ! empty( $next['done'] ) ) ) {
			$tname = isset( $state['timing_table_name'] ) ? (string) $state['timing_table_name'] : '';
			$bytes = ( $tname && isset( $next['table_stats'][ $tname ]['bytes'] ) ) ? (int) $next['table_stats'][ $tname ]['bytes'] : null;
			\Jisento\Migration\Core\Timings::end( $state, (string) $state['timing_table_key'], $bytes );
			unset( $state['timing_table_key'], $state['timing_table_name'] );
			if ( $new_index > $prev_index && isset( $tables[ $new_index ] ) && empty( $next['done'] ) ) {
				$table = (string) $tables[ $new_index ];
				$meta  = isset( $state['table_meta'][ $table ] ) && is_array( $state['table_meta'][ $table ] ) ? $state['table_meta'][ $table ] : array();
				$data  = isset( $meta['data'] ) ? (int) $meta['data'] : 0;
				if ( $data >= \Jisento\Migration\Core\Timings::LARGE_TABLE_BYTES ) {
					$key = 'table_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $table );
					\Jisento\Migration\Core\Timings::begin( $state, $key, $data );
					$state['timing_table_key']  = $key;
					$state['timing_table_name'] = $table;
				}
			}
		}

		$mode        = isset( $state['options']['mode'] ) ? $state['options']['mode'] : 'full';
		$index       = (int) $next['index'];
		$table_total = count( $tables );
		$sql_bytes   = 0;
		foreach ( $next['segments'] as $segment ) {
			$sql_bytes += (int) $segment['bytes'];
		}
		if ( ! empty( $next['done'] ) ) {
			$state['sql_bytes'] = $sql_bytes;
			\Jisento\Migration\Core\Timings::end( $state, 'exporting_database', $sql_bytes );
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
			\Jisento\Migration\Core\Timings::begin( $state, 'packaging', (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 ) );
		} else {
			$stage    = 'exporting_database';
			$progress = $this->export_percent( $mode, 'database', $table_total > 0 ? $index / $table_total : 1 );
		}

		$detail = isset( $tables[ $index ] ) ? $tables[ $index ] : __( 'Database export complete', 'jisento-migration' );
		if ( isset( $tables[ $index ], $next['table_stats'][ $tables[ $index ] ] ) ) {
			$detail .= ' (' . sprintf( __( 'row %s', 'jisento-migration' ), number_format_i18n( (int) $next['table_stats'][ $tables[ $index ] ]['rows'] ) ) . ')';
		}
		$state['activity'] = $this->activity(
			'exporting_database',
			$index >= $table_total ? __( 'Exporting database', 'jisento-migration' ) : __( 'Processing database tables', 'jisento-migration' ),
			$detail,
			min( $index, $table_total ),
			$table_total,
			__( 'Database', 'jisento-migration' ),
			__( 'tables', 'jisento-migration' )
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
					'current_item' => __( 'No files to export', 'jisento-migration' ),
					'state'        => $state,
				)
			);
		}

		$fh = fopen( $list, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( __( 'The file list for this backup is missing.', 'jisento-migration' ) );
		}
		$offset = (int) ( $state['file_offset'] ?? 0 );
		if ( $offset > 0 ) {
			fseek( $fh, $offset );
		}

		while ( $index < $total && ( time() - $start ) < 4 && ! Step_Budget::exhausted() ) {
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
				throw new \RuntimeException( sprintf( __( 'Source file disappeared during export: %s', 'jisento-migration' ), $file['relative'] ) );
			}
			if ( ! File_System::stream_copy( $file['source'], $dest ) || ! is_file( $dest ) ) {
				fclose( $fh );
				throw new \RuntimeException( sprintf( __( 'Unable to copy %s into the backup staging folder.', 'jisento-migration' ), $file['relative'] ) );
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
			throw new \RuntimeException( __( 'The file list ended before every file was copied.', 'jisento-migration' ) );
		}
		$mode                       = isset( $state['options']['mode'] ) ? $state['options']['mode'] : 'full';
		$file_bytes                 = (int) ( $state['files_size'] ?? 0 );
		$file_ratio                 = $done ? 1 : ( $file_bytes > 0 ? min( 1, $copied / $file_bytes ) : ( $index / max( 1, $total ) ) );
		$progress                   = $this->export_percent( $mode, 'files', $file_ratio );

		$label = ( false !== strpos( (string) $current, 'wp-content/uploads/' ) )
			? __( 'Processing uploads', 'jisento-migration' )
			: __( 'Exporting wp-content', 'jisento-migration' );
		$state['activity'] = $this->activity(
			'exporting_files',
			$done ? __( 'Exporting wp-content', 'jisento-migration' ) : $label,
			$current ? $current : __( 'Copying files', 'jisento-migration' ),
			min( $index, $total ),
			$total,
			__( 'Files', 'jisento-migration' ),
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

	/**
	 * Options for the package writer (tests force ZIP64 or a small hash chunk).
	 *
	 * @var array
	 */
	public static $zip_options = array();

	/**
	 * Bytes added to the package per step. A file up to this size is written whole in one step
	 * (deflated when compressible); a larger one is stored and continues in the next step.
	 * Equal to Dump_Writer::MAX_SEGMENT_BYTES so every database segment can still be deflated.
	 *
	 * @var int
	 */
	public static $package_bytes = 67108864;

	private function package( $job, array $state ) {
		unset( $state['checksums'], $state['files'] );
		$plugin  = Plugin::instance();
		$tmp     = $plugin->storage->tmp_dir( $job->job_id );
		$options = isset( $state['options'] ) ? $state['options'] : array();
		$mode    = isset( $options['mode'] ) ? $options['mode'] : 'full';

		if ( empty( $state['package_name'] ) ) {
			$state['package_name'] = $this->backup_filename( $mode, isset( $options['backup_type'] ) ? $options['backup_type'] : 'manual', $job->job_id );
		}
		if ( empty( $state['package_partial'] ) ) {
			$state['package_partial'] = $plugin->storage->get_path( 'packages/' . $state['package_name'] . '.partial' );
		}
		$partial      = $state['package_partial'];
		$writer_state = $tmp . '/zip-writer.json';

		if ( empty( $state['zip_ready'] ) ) {
			$writer = Streaming_Zip_Writer::create( $partial, $writer_state, self::$zip_options );
			$writer->add_string( 'JISENTO', Archive::marker_contents() );
			$writer->set_cursor(
				array(
					'phase'       => ( 'files' === $mode ) ? 'files' : 'database',
					'seg'         => 0,
					'index'       => 0,
					'list_offset' => 0,
				)
			);
			$writer->commit();
			$writer->close();
			$state['zip_ready'] = true;
			if ( empty( $state['timings']['packaging'] ) ) {
				\Jisento\Migration\Core\Timings::begin( $state, 'packaging', (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 ) );
			}
			$state['zip_phase'] = ( 'files' === $mode ) ? 'files' : 'database';
			$state['zip_index'] = 0;
			$state['activity']  = $this->activity( 'packaging', __( 'Finalizing package', 'jisento-migration' ), __( 'Writing package header', 'jisento-migration' ), 0, (int) ( $state['file_count'] ?? 0 ), __( 'Files', 'jisento-migration' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 0.01 ), __( 'Writing package header', 'jisento-migration' ), 0 );
		}

		if ( 'finalize' === ( $state['zip_phase'] ?? '' ) ) {
			return $this->finalize_package( $job, $state, $partial, $writer_state, $tmp, $mode );
		}

		$writer   = Streaming_Zip_Writer::resume( $partial, $writer_state );
		$cursor   = $writer->cursor();
		$deadline = Step_Budget::deadline();
		$budget   = max( 1048576, (int) self::$package_bytes );
		$bytes    = 0;
		$worked   = false;
		$current  = '';
		try {
			if ( $writer->has_open_entry() ) {
				$open    = $writer->open_entry();
				$before  = $open['done'];
				$current = $open['name'];
				$writer->continue_open( $deadline, $budget );
				$worked  = true;
				$after   = $writer->open_entry();
				$bytes  += ( $after ? $after['done'] : $open['size'] ) - $before;
			}

			if ( ! $writer->has_open_entry() && 'database' === $cursor['phase'] ) {
				$segments = isset( $state['dump']['segments'] ) ? $state['dump']['segments'] : array();
				if ( 'files' !== $mode && ! $segments ) {
					throw new \RuntimeException( __( 'Operation: add the database to the package. Reason: no database segments were written. Recovery: start a new export.', 'jisento-migration' ) );
				}
				while ( $cursor['seg'] < count( $segments ) && ( ! $worked || ( $bytes < $budget && microtime( true ) < $deadline ) ) ) {
					$segment = $segments[ $cursor['seg'] ];
					$source  = $state['dump_dir'] . '/' . basename( $segment['entry'] );
					clearstatcache( true, $source );
					if ( ! is_file( $source ) || (int) filesize( $source ) !== (int) $segment['bytes'] ) {
						throw new \RuntimeException( sprintf( __( 'Operation: add the database to the package. Reason: segment %s is missing or changed size after it was written. Recovery: start a new export.', 'jisento-migration' ), $segment['entry'] ) );
					}
					if ( $bytes > 0 && (int) $segment['bytes'] <= $budget && $bytes + (int) $segment['bytes'] > $budget ) {
						break;
					}
					$complete = $writer->add_file( $source, $segment['entry'], $deadline, max( 1048576, $budget - $bytes ) );
					$cursor['seg']++;
					$worked = true;
					$writer->set_cursor( $cursor );
					$current = $segment['entry'];
					$bytes  += $complete ? (int) $segment['bytes'] : $writer->open_entry()['done'];
					if ( ! $complete ) {
						break;
					}
				}
				if ( ! $writer->has_open_entry() && $cursor['seg'] >= count( $segments ) ) {
					$cursor['phase'] = ( 'database' === $mode ) ? 'finalize' : 'files';
					$writer->set_cursor( $cursor );
				}
			}

			if ( ! $writer->has_open_entry() && 'files' === $cursor['phase'] && ( ! $worked || microtime( true ) < $deadline ) ) {
				$total = (int) ( $state['file_count'] ?? 0 );
				$list  = isset( $state['file_list'] ) ? $state['file_list'] : '';
				if ( $total > 0 && (int) $cursor['index'] < $total ) {
					$fh = is_readable( $list ) ? fopen( $list, 'rb' ) : false;
					if ( ! $fh ) {
						throw new \RuntimeException( __( 'Operation: add files to the package. Reason: the file list for this backup is missing. Recovery: start a new export.', 'jisento-migration' ) );
					}
					fseek( $fh, (int) $cursor['list_offset'] );
					while ( $cursor['index'] < $total && ( ! $worked || ( $bytes < $budget && microtime( true ) < $deadline ) ) ) {
						$line = fgets( $fh );
						if ( false === $line ) {
							fclose( $fh );
							throw new \RuntimeException( __( 'Operation: add files to the package. Reason: the file list ended before every file was added. Recovery: start a new export.', 'jisento-migration' ) );
						}
						$row = Job_Store::decode_value( json_decode( trim( $line ), true ) );
						if ( ! is_array( $row ) || empty( $row['relative'] ) ) {
							$cursor['index']++;
							$cursor['list_offset'] = (int) ftell( $fh );
							continue;
						}
						$source = ( isset( $row['source'] ) && is_file( $row['source'] ) ) ? $row['source'] : ( $tmp . '/files/' . $row['relative'] );
						$label  = Job_Store::is_utf8( $row['relative'] ) ? $row['relative'] : 'hex:' . bin2hex( $row['relative'] );
						if ( ! is_file( $source ) ) {
							fclose( $fh );
							throw new \RuntimeException( sprintf( __( 'Operation: add files to the package. Reason: the source file disappeared or is unreadable: %s. Recovery: make sure the file exists and is readable, then start a new export.', 'jisento-migration' ), $label ) );
						}
						$size = (int) filesize( $source );
						if ( $bytes > 0 && $size <= $budget && $bytes + $size > $budget ) {
							// Fits in one step on its own: start it fresh next step instead of splitting it.
							break;
						}
						$complete              = $writer->add_file( $source, 'files/' . $row['relative'], $deadline, max( 1048576, $budget - $bytes ) );
						$cursor['index']++;
						$cursor['list_offset'] = (int) ftell( $fh );
						$writer->set_cursor( $cursor );
						$worked  = true;
						$current = $label;
						$bytes  += $complete ? $size : $writer->open_entry()['done'];
						if ( ! $complete ) {
							break;
						}
					}
					fclose( $fh );
				}
				if ( ! $writer->has_open_entry() && (int) $cursor['index'] >= $total ) {
					$cursor['phase'] = 'finalize';
					$writer->set_cursor( $cursor );
				}
			}
			$writer->commit();
		} finally {
			$writer->close();
		}

		$totals             = $writer->totals();
		$state['zip_phase'] = $cursor['phase'];
		$state['zip_index'] = (int) $cursor['index'];
		$state['zip_bytes'] = (int) $totals['files_bytes'];
		if ( 'finalize' === $cursor['phase'] ) {
			$state['activity'] = $this->activity( 'finalizing', __( 'Finalizing package', 'jisento-migration' ), __( 'Package contents are ready', 'jisento-migration' ), (int) ( $state['file_count'] ?? 0 ), (int) ( $state['file_count'] ?? 0 ), __( 'Files', 'jisento-migration' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 1 ), __( 'Finalizing package', 'jisento-migration' ), $totals['offset'] );
		}
		if ( 'database' === $cursor['phase'] ) {
			$count             = isset( $state['dump']['segments'] ) ? count( $state['dump']['segments'] ) : 0;
			$state['activity'] = $this->activity( 'packaging', __( 'Adding database to package', 'jisento-migration' ), sprintf( __( 'Segment %1$d of %2$d', 'jisento-migration' ), (int) $cursor['seg'], $count ), (int) $cursor['seg'], $count, __( 'Segments', 'jisento-migration' ), '' );
			return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', 0.02 ), __( 'Adding database to package', 'jisento-migration' ), $totals['offset'] );
		}
		$total      = (int) ( $state['file_count'] ?? 0 );
		$file_bytes = (int) ( $state['files_size'] ?? 0 );
		$zip_ratio  = $file_bytes > 0 ? min( 1, $totals['files_bytes'] / $file_bytes ) : ( (int) $cursor['index'] / max( 1, $total ) );
		$state['activity']                  = $this->activity( 'packaging', __( 'Adding files to package', 'jisento-migration' ), $current ? $current : __( 'Adding files to package', 'jisento-migration' ), (int) $cursor['index'], $total, __( 'Files', 'jisento-migration' ), '' );
		$state['activity']['measure_kind']  = 'bytes';
		$state['activity']['measure_label'] = __( 'Processed', 'jisento-migration' );
		$state['activity']['measure_done']  = (int) $totals['files_bytes'];
		$state['activity']['measure_total'] = $file_bytes;
		return $this->save_progress( $job, $state, 'packaging', $this->export_percent( $mode, 'zip', $zip_ratio ), $current, $totals['offset'] );
	}

	/**
	 * manifest: add manifest.json and the central directory (the archive is complete).
	 * verify:   open the finished archive with ZipArchive and check its structure; choose the final name.
	 * rename:   rename the .partial into place (never over an existing package).
	 * confirm:  write the integrity record and register the package.
	 */
	private function finalize_package( $job, array $state, $partial, $writer_state, $tmp, $mode ) {
		$plugin         = Plugin::instance();
		$expected_files = (int) ( $state['file_count'] ?? 0 );
		$step           = isset( $state['finalize_step'] ) ? $state['finalize_step'] : 'manifest';

		if ( 'manifest' === $step ) {
			$result = Streaming_Zip_Writer::finished_result( $writer_state );
			if ( null === $result ) {
				$writer = Streaming_Zip_Writer::resume( $partial, $writer_state );
				try {
					$totals = $writer->totals();
					if ( 'database' !== $mode && $totals['files_count'] !== $expected_files ) {
						throw new \RuntimeException( sprintf( __( 'Operation: finalize the package. Reason: %1$d files were scanned but %2$d are in the package. Recovery: start a new export.', 'jisento-migration' ), $expected_files, $totals['files_count'] ) );
					}
					$writer->add_string( 'manifest.json', Archive::encode_manifest( $this->manifest( $state, $mode, $totals['files_count'], $totals['files_bytes'] ) ) );
					$result               = $writer->finish();
				} finally {
					$writer->close();
				}
			}
			$state['files_bytes']            = (int) $result['files_bytes'];
			$state['package_built_size']     = (int) $result['size'];
			$state['package_content_sha256'] = (string) $result['content_sha256'];
			$state['finalize_step']          = 'verify';
			\Jisento\Migration\Core\Timings::end( $state, 'packaging', (int) $result['size'] );
			\Jisento\Migration\Core\Timings::begin( $state, 'checksum', (int) $result['size'] );
			$state['activity']               = $this->activity( 'verifying', __( 'Verifying package', 'jisento-migration' ), $state['package_name'], 1, 1, __( 'Package', 'jisento-migration' ), '' );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.3 ), (int) $result['size'], (int) $result['size'] );
		}

		if ( 'verify' === $step ) {
			clearstatcache( true, $partial );
			if ( ! is_file( $partial ) || (int) filesize( $partial ) !== (int) $state['package_built_size'] ) {
				throw new \RuntimeException( __( 'Operation: verify the package. Reason: the built package is missing or changed size. Recovery: start a new export.', 'jisento-migration' ) );
			}
			$this->assert_package( $partial );
			$state['package_name']  = $this->free_package_name( $state['package_name'], $job->job_id );
			$state['finalize_step'] = 'rename';
			\Jisento\Migration\Core\Timings::end( $state, 'checksum', (int) $state['package_built_size'] );
			\Jisento\Migration\Core\Timings::begin( $state, 'finalize', (int) $state['package_built_size'] );
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.7 ), (int) $state['package_built_size'], (int) $state['package_built_size'] );
		}

		$name = $state['package_name'];
		$key  = 'packages/' . $name;
		$dest = $plugin->storage->get_path( $key );
		$size = (int) $state['package_built_size'];

		if ( 'rename' === $step ) {
			clearstatcache( true, $partial );
			clearstatcache( true, $dest );
			if ( is_file( $partial ) ) {
				if ( file_exists( $dest ) || ! @rename( $partial, $dest ) ) {
					throw new \RuntimeException( sprintf( __( 'Operation: save the package. Reason: %s could not be renamed into place (it already exists, or the folder is not writable). Recovery: check wp-content/jisento/packages/, then start a new export.', 'jisento-migration' ), $name ) );
				}
			} elseif ( ! is_file( $dest ) || (int) filesize( $dest ) !== $size ) {
				throw new \RuntimeException( __( 'Operation: save the package. Reason: the built package disappeared before it was saved. Recovery: start a new export.', 'jisento-migration' ) );
			}
			$state['finalize_step'] = 'confirm';
			return $this->save_work( $job, $state, 'packaging', $this->export_percent( $mode, 'finalize', 0.9 ), $size, $size );
		}

		$checksum = isset( $state['package_content_sha256'] ) ? (string) $state['package_content_sha256'] : '';
		clearstatcache( true, $dest );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || ! is_file( $dest ) || $size <= 0 || $size !== (int) filesize( $dest ) ) {
			throw new \RuntimeException( __( 'Operation: confirm the package. Reason: the saved package does not match the one that was built. Recovery: start a new export.', 'jisento-migration' ) );
		}

		$kind = $mode;
		$meta = array(
			'filename'          => $name,
			'storage_key'       => $key,
			'size'              => $size,
			'checksum'          => $checksum,
			'checksum_kind'     => 'content-sha256',
			'content_sha256'    => $checksum,
			'type'              => $kind,
			'migration_id'      => $job->job_id,
			'status'            => 'completed',
			'created_at'        => current_time( 'mysql' ),
			'package_version'   => JISENTO_PACKAGE_VERSION,
			'format_marker'     => JISENTO_FORMAT_MARKER,
			'home_url'          => home_url(),
			'database_size'     => (int) ( $state['db_size'] ?? 0 ),
			'files_size'        => (int) ( $state['files_size'] ?? 0 ),
			'uncompressed_size' => (int) ( $state['db_size'] ?? 0 ) + (int) ( $state['files_size'] ?? 0 ),
			'table_count'       => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
			'file_count'        => $expected_files,
			'contents'          => $mode,
		);
		if ( false === file_put_contents( $dest . '.json', wp_json_encode( $meta ) ) ) {
			throw new \RuntimeException( __( 'Operation: confirm the package. Reason: the integrity record could not be written (disk full or permission denied). Recovery: free disk space, then start a new export.', 'jisento-migration' ) );
		}

		\Jisento\Migration\Core\Installer::maybe_upgrade();
		$registry = new \Jisento\Migration\Package\Package_Registry();
		$pack_id  = $registry->upsert_completed( $meta );
		if ( ! $pack_id ) {
			global $wpdb;
			$detail = $wpdb->last_error ? ' ' . $wpdb->last_error : '';
			throw new \RuntimeException( __( 'The package file was saved, but it could not be added to the backup registry.', 'jisento-migration' ) . $detail );
		}

		$plugin->storage->delete_tree( $tmp );

		clearstatcache( true, $dest );
		if ( $size !== (int) filesize( $dest ) ) {
			throw new \RuntimeException( __( 'The .jisento file changed after it was verified.', 'jisento-migration' ) );
		}

		$state['activity']     = $this->activity( 'completed', __( 'Backup completed', 'jisento-migration' ), $name, 1, 1, __( 'Package', 'jisento-migration' ), '' );
		$state['package']      = $key;
		$state['package_abs']  = $dest;
		$state['package_size'] = $size;
		$state['package_id']   = $pack_id;
		$state['package_name'] = $name;
		unset( $state['package_partial'] );
		\Jisento\Migration\Core\Timings::end( $state, 'finalize', $size );
		$timing_lines = \Jisento\Migration\Core\Timings::readable_list( isset( $state['timings'] ) && is_array( $state['timings'] ) ? $state['timings'] : array() );
		$state['report']       = array(
			'source'       => home_url(),
			'started'      => $job->created_at,
			'completed'    => current_time( 'mysql' ),
			'database'     => File_System::readable_size( $state['db_size'] ?? 0 ),
			'files'        => File_System::readable_size( $state['files_size'] ?? 0 ),
			'total'        => File_System::readable_size( $size ),
			'tables'       => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
			'files_n'      => $expected_files,
			'package'      => $name,
			'size'         => $size,
			'checksum'     => $checksum,
			'timings'      => isset( $state['timings'] ) ? $state['timings'] : array(),
			'timing_lines' => $timing_lines,
			'total_seconds'=> \Jisento\Migration\Core\Timings::total_seconds( isset( $state['timings'] ) && is_array( $state['timings'] ) ? $state['timings'] : array() ),
		);

		$plugin->logger->log( $job->job_id, 'packaging', 'package', $name, 'ok', 'Verified package bytes=' . $size . ' content_sha256=' . $checksum );
		foreach ( $timing_lines as $line ) {
			$plugin->logger->log( $job->job_id, 'packaging', 'timing', '', 'info', $line );
		}

		$saved = $plugin->jobs->update(
			$job,
			array(
				'status'       => 'completed',
				'stage'        => 'completed',
				'progress'     => 100,
				'current_item' => __( 'Backup completed', 'jisento-migration' ),
				'bytes_done'   => $size,
				'bytes_total'  => $size,
				'state'        => $state,
			)
		);

		if ( ! $saved || 'completed' !== $saved->status ) {
			throw new \RuntimeException( __( 'The package was written, but the backup record could not be saved.', 'jisento-migration' ) );
		}

		return $saved;
	}

	/**
	 * The chosen package name, or the same name with a numeric suffix when a package (or its
	 * record) already uses it. An existing package is never overwritten.
	 */
	private function free_package_name( $name, $job_id ) {
		$storage = Plugin::instance()->storage;
		$base    = preg_replace( '/\.jisento$/i', '', (string) $name );
		$try     = (string) $name;
		for ( $n = 2; $n < 1000; $n++ ) {
			$path = $storage->get_path( 'packages/' . $try );
			if ( ! file_exists( $path ) && ! file_exists( $path . '.json' ) ) {
				return $try;
			}
			$try = $base . '-' . $n . '.jisento';
		}
		throw new \RuntimeException( sprintf( __( 'Operation: save the package. Reason: no free file name was found for %s. Recovery: delete old packages, then start a new export. Job: %s', 'jisento-migration' ), $name, $job_id ) );
	}

	/**
	 * Remove the unfinished package of a failed or cancelled export.
	 *
	 * @param object $job Job.
	 */
	public static function cleanup( $job ) {
		if ( 'completed' === $job->status ) {
			return;
		}
		$state   = is_array( $job->state ) ? $job->state : array();
		$partial = isset( $state['package_partial'] ) ? (string) $state['package_partial'] : '';
		if ( '' !== $partial && '.partial' === substr( $partial, -8 ) && is_file( $partial ) ) {
			@unlink( $partial );
		}
		$storage = Plugin::instance()->storage;
		$storage->delete_tree( $storage->tmp_dir( $job->job_id ) );
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
			'plugin_basename'   => defined( 'JISENTO_BASENAME' ) ? JISENTO_BASENAME : '',
			'format_marker'     => JISENTO_FORMAT_MARKER,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'site_url'          => site_url(),
			'abspath'           => rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/',
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
			$message = __( 'The export stopped but PHP supplied no error message. Check the server PHP error log.', 'jisento-migration' );
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
			throw new \RuntimeException( __( 'The .jisento file does not exist after export.', 'jisento-migration' ) );
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 ) {
			throw new \RuntimeException( __( 'The .jisento file is empty (0 bytes).', 'jisento-migration' ) );
		}
		$verify = ( new \Jisento\Migration\Package\Package_Registry() )->verify_file( $path, 'full' );
		if ( empty( $verify['ok'] ) ) {
			throw new \RuntimeException( $verify['reason'] ? $verify['reason'] : __( 'Package verification failed.', 'jisento-migration' ) );
		}
		return array(
			'size' => $size,
		);
	}

	private function discard_unverified_package( array $state ) {
		$partial = isset( $state['package_partial'] ) ? (string) $state['package_partial'] : '';
		if ( '' !== $partial && '.partial' === substr( $partial, -8 ) && is_file( $partial ) ) {
			@unlink( $partial );
		}
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
			$activity['measure_label'] = __( 'Package file', 'jisento-migration' );
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

}
