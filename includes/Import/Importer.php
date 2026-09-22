<?php
/**
 * Chunked importer with replace and preserve destination modes.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Import;

use Jisento\Migration\Core\Cleanup;
use Jisento\Migration\Core\Compatibility;
use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Package\Archive;
use Jisento\Migration\Plugin;
use Jisento\Migration\Replace\Url_Replacer;
use Jisento\Migration\Security\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Importer {

	public function start( array $options ) {
		$plugin = Plugin::instance();
		$job    = $plugin->jobs->create(
			'import',
			array(
				'options' => $this->normalize_options( $options ),
			)
		);
		$plugin->logger->log( $job->job_id, 'created', 'import', '', 'info', 'Import job created' );
		Live_Url::capture( $job->job_id );
		\Jisento\Migration\Security\Admin_Guard::snapshot( $job->job_id );
		return $plugin->jobs->update(
			$job->job_id,
			array(
				'status' => 'running',
				'stage'  => 'validating',
			)
		);
	}

	public function step( $job ) {
		$plugin = Plugin::instance();
		$state  = is_array( $job->state ) ? $job->state : array();
		if ( ! empty( $state['options'] ) ) {
			$state['options'] = $this->normalize_options( $state['options'] );
		}

		try {
			switch ( $job->stage ) {
				case 'created':
				case 'validating':
					return $this->validate( $job, $state );
				case 'compatibility':
				case 'safety_backup':
					return $this->compatibility( $job, $state );
				case 'extracting':
					return $this->extract( $job, $state );
				case 'importing_database':
					return $this->import_database( $job, $state );
				case 'importing_files':
					return $this->import_files( $job, $state );
				case 'replacing_urls':
					return $this->replace_urls( $job, $state );
				case 'finalizing':
					return $this->finalize( $job, $state );
				default:
					return $job;
			}
		} catch ( \Throwable $e ) {
			$message = trim( (string) $e->getMessage() );
			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %s: PHP exception class */
					__( 'The import stopped with %s but PHP supplied no error message. Check the server PHP error log.', 'jisento' ),
					get_class( $e )
				);
			}
			$plugin->logger->log( $job->job_id, $job->stage, 'error', '', 'error', $message );
			return $plugin->jobs->update(
				$job->job_id,
				array(
					'status'        => 'failed',
					'error_summary' => $message,
				)
			);
		}
	}

	private function normalize_options( array $options ) {
		$defaults = array(
			'package'              => '',
			'destination_mode'     => 'preserve',
			'confirm_replace'      => false,
			'safety_backup'        => false,
			'replace_urls'         => true,
			'source_url'           => '',
			'dest_url'             => home_url(),
			'preserve_plugins'     => true,
			'preserve_themes'      => true,
			'preserve_uploads'     => true,
			'preserve_tables'      => false,
			'preserve_users'       => true,
			'preserve_options'     => false,
			'plugin_strategy'      => 'install_missing',
			'theme_strategy'       => 'keep_destination',
			'plugin_conflicts'     => array(),
			'theme_conflicts'      => array(),
		);
		$options = wp_parse_args( $options, $defaults );
		$options['destination_mode'] = 'replace' === $options['destination_mode'] ? 'replace' : 'preserve';
		return $options;
	}

	private function package_path( array $options ) {
		$plugin   = Plugin::instance();
		$relative = ltrim( str_replace( '\\', '/', $options['package'] ), '/' );
		$relative = Guard::sanitize_archive_path( $relative );
		if ( is_wp_error( $relative ) ) {
			throw new \RuntimeException( $relative->get_error_message() );
		}
		$path = $plugin->storage->resolve( $relative );
		if ( ! $path ) {
			throw new \RuntimeException( __( 'The selected .jisento package could not be found.', 'jisento' ) );
		}
		return $path['path'];
	}

	private function import_percent( array $state, $phase, $ratio ) {
		$ratio    = max( 0, min( 1, (float) $ratio ) );
		$contents = isset( $state['manifest']['contents'] ) ? $state['manifest']['contents'] : 'full';
		$bands    = array(
			'compatibility' => array( 2, 4 ),
			'extract'       => array( 4, 14 ),
			'database'      => array( 14, 58 ),
			'files'         => array( 58, 80 ),
			'urls'          => array( 80, 98 ),
		);
		if ( 'database' === $contents ) {
			$bands['extract']  = array( $bands['extract'][0], $bands['extract'][0] + 4 );
			$bands['database'] = array( $bands['extract'][1], $bands['urls'][0] - 8 );
			$bands['files']    = array( $bands['database'][1], $bands['database'][1] );
			$bands['urls']     = array( $bands['database'][1], 98 );
		} elseif ( 'files' === $contents ) {
			$bands['database'] = array( $bands['extract'][1], $bands['extract'][1] );
			$bands['files']    = array( $bands['extract'][1], $bands['urls'][0] );
		}
		$band = isset( $bands[ $phase ] ) ? $bands[ $phase ] : array( 0, 98 );
		return (int) floor( $band[0] + ( $band[1] - $band[0] ) * $ratio );
	}

	private function report( $job, array $state, array $fields, array $activity ) {
		$phase = isset( $activity['phase'] ) ? (string) $activity['phase'] : '';
		$now   = microtime( true );
		if ( '' !== $phase ) {
			if ( ! empty( $state['timing_mark'] ) && ! empty( $state['timing_phase'] ) ) {
				$delta = $now - (float) $state['timing_mark'];
				if ( $delta > 0 && $delta < 180 ) {
					if ( ! isset( $state['timings'] ) || ! is_array( $state['timings'] ) ) {
						$state['timings'] = array();
					}
					$key = (string) $state['timing_phase'];
					$state['timings'][ $key ] = ( isset( $state['timings'][ $key ] ) ? (float) $state['timings'][ $key ] : 0 ) + $delta;
				}
			}
			$state['timing_mark']  = $now;
			$state['timing_phase'] = $phase;
		}
		$state['activity'] = $activity;
		$fields['state']   = $state;
		if ( empty( $fields['current_item'] ) ) {
			$fields['current_item'] = ! empty( $activity['detail'] ) ? $activity['detail'] : $activity['label'];
		}
		return Plugin::instance()->jobs->update( $job->job_id, $fields );
	}

	private function validate( $job, array $state ) {
		$plugin  = Plugin::instance();
		$path    = $this->package_path( $state['options'] );
		$archive = new Archive();
		$info    = $archive->inspect( $path );
		if ( is_wp_error( $info ) ) {
			throw new \RuntimeException( $info->get_error_message() );
		}
		if ( empty( $info['has_db'] ) && empty( $info['has_files'] ) ) {
			throw new \RuntimeException( __( 'The package does not contain a database dump or site files.', 'jisento' ) );
		}

		$state['package_path'] = $path;
		$state['manifest']     = $info['manifest'];
		$state['validation']   = array(
			'signature'  => true,
			'manifest'   => true,
			'database'   => (bool) $info['has_db'],
			'files'      => (bool) $info['has_files'],
		);

		if ( empty( $state['options']['source_url'] ) && ! empty( $info['manifest']['home_url'] ) ) {
			$state['options']['source_url'] = $info['manifest']['home_url'];
		}

		$plugin->logger->log( $job->job_id, 'validating', 'package', basename( $path ), 'ok', 'Package signature valid' );

		clearstatcache( true, $path );
		$database_bytes = (int) ( isset( $info['manifest']['database_size'] ) ? $info['manifest']['database_size'] : 0 );
		$files_bytes    = (int) ( isset( $info['manifest']['files_size'] ) ? $info['manifest']['files_size'] : 0 );
		if ( isset( $info['manifest']['uncompressed_size'] ) ) {
			$contents_bytes = (int) $info['manifest']['uncompressed_size'];
		} else {
			$contents_bytes = $database_bytes + $files_bytes;
		}
		$state['sizes'] = array(
			'package'     => (int) filesize( $path ),
			'database'    => $database_bytes,
			'files'       => $files_bytes,
			'contents'    => $contents_bytes,
			'file_count'  => (int) ( isset( $info['manifest']['file_count'] ) ? $info['manifest']['file_count'] : 0 ),
			'table_count' => (int) ( isset( $info['manifest']['table_count'] ) ? $info['manifest']['table_count'] : 0 ),
		);

		$mode = $state['options']['destination_mode'];
		if ( 'replace' === $mode && empty( $state['options']['confirm_replace'] ) ) {
			throw new \RuntimeException( __( 'Complete replacement requires explicit confirmation.', 'jisento' ) );
		}

		return $this->report(
			$job,
			$state,
			array(
				'stage'    => 'compatibility',
				'progress' => 2,
			),
			array(
				'phase'          => 'validating',
				'label'          => __( 'Validating package', 'jisento' ),
				'detail'         => __( 'Package signature and manifest verified', 'jisento' ),
				'stage_progress' => 100,
			)
		);
	}

	private function compatibility( $job, array $state ) {
		$plugin = Plugin::instance();
		$compat = ( new Compatibility() )->run( $state['manifest'] );
		$state['compatibility'] = $compat;
		if ( ! $compat['can_run'] ) {
			throw new \RuntimeException( __( 'Destination compatibility checks failed. View details and resolve the errors before continuing.', 'jisento' ) );
		}
		$plugin->logger->log( $job->job_id, 'compatibility', 'check', '', 'ok', 'Compatibility passed' );
		return $plugin->jobs->update(
			$job->job_id,
			array(
				'stage'        => 'extracting',
				'progress'     => $this->import_percent( $state, 'compatibility', 1 ),
				'current_item' => __( 'Destination compatibility', 'jisento' ),
				'state'        => $state,
			)
		);
	}

	private function extract( $job, array $state ) {
		$plugin  = Plugin::instance();
		$tmp     = $plugin->storage->tmp_dir( $job->job_id );
		$archive = new Archive();
		$sql     = $tmp . '/database/database.sql';

		if ( empty( $state['sql_extracted'] ) ) {
			$has_db = ! empty( $state['validation']['database'] );
			if ( ! $has_db ) {
				$state['sql_extracted'] = true;
				$state['sql_done']      = true;
				return $this->report(
					$job,
					$state,
					array(
						'stage'    => 'importing_files',
						'progress' => $this->import_percent( $state, 'extract', 1 ),
					),
					array(
						'phase'          => 'extracting',
						'label'          => __( 'Extracting package', 'jisento' ),
						'detail'         => __( 'Package has no database dump', 'jisento' ),
						'stage_progress' => 100,
					)
				);
			}
			$this->assert_disk_space( $job, (int) ( isset( $state['sizes']['database'] ) ? $state['sizes']['database'] : 0 ) );
			$result = $archive->extract_entry_to( $state['package_path'], 'database/database.sql', $sql );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			$state['sql_extracted'] = true;
			$state['sql_path']      = $sql;
			$sql_bytes              = is_file( $sql ) ? (int) filesize( $sql ) : 0;
			$plugin->logger->log( $job->job_id, 'extracting', 'database', 'database.sql', 'ok', 'SQL extracted' );
			return $this->report(
				$job,
				$state,
				array(
					'stage'    => 'importing_database',
					'progress' => $this->import_percent( $state, 'extract', 1 ),
				),
				array(
					'phase'          => 'extracting',
					'label'          => __( 'Extracting package', 'jisento' ),
					'detail'         => __( 'Database dump extracted', 'jisento' ),
					'stage_progress' => 100,
					'measure_kind'   => 'bytes',
					'measure_label'  => __( 'Extracted', 'jisento' ),
					'measure_done'   => $sql_bytes,
					'measure_total'  => $sql_bytes,
				)
			);
		}

		return $this->report(
			$job,
			$state,
			array(
				'stage'    => 'importing_database',
				'progress' => $this->import_percent( $state, 'extract', 1 ),
			),
			array(
				'phase'          => 'extracting',
				'label'          => __( 'Extracting package', 'jisento' ),
				'detail'         => __( 'Database dump extracted', 'jisento' ),
				'stage_progress' => 100,
			)
		);
	}

	private function import_database( $job, array $state ) {
		$plugin        = Plugin::instance();
		$manifest      = $state['manifest'];
		$source_prefix = isset( $manifest['database_prefix'] ) ? $manifest['database_prefix'] : 'wp_';
		$dest_prefix   = $GLOBALS['wpdb']->prefix;
		$skip          = $this->skip_tables( $state, $source_prefix, $dest_prefix );
		$importer      = new Database_Importer( $source_prefix, $dest_prefix, $skip );
		$replace = 'replace' === $state['options']['destination_mode'];
		$cursor  = Database_Importer::preferred_cursor( $state['sql_path'], $job->job_id );
		$cleared = isset( $state['cleared_tables'] ) && is_array( $state['cleared_tables'] ) ? $state['cleared_tables'] : array();
		$cleared_file = $state['sql_path'] . '.cleared';
		if ( is_readable( $cleared_file ) ) {
			$lines = preg_split( '/\R/', (string) file_get_contents( $cleared_file ) );
			if ( is_array( $lines ) ) {
				$cleared = array_values( array_unique( array_merge( $cleared, array_filter( $lines ) ) ) );
			}
		}
		if ( $replace && ! empty( $state['sql_offset'] ) && ! $cleared && ! $cursor ) {
			$state['sql_offset'] = 0;
		}
		$offset = isset( $state['sql_offset'] ) ? (int) $state['sql_offset'] : 0;
		$piece  = isset( $state['piece_index'] ) ? (int) $state['piece_index'] : 0;
		if ( $cursor && ( $cursor['offset'] > $offset || ( $cursor['offset'] === $offset && $cursor['piece_index'] > $piece ) ) ) {
			$offset = (int) $cursor['offset'];
			$piece  = (int) $cursor['piece_index'];
			$state['stmt_end'] = (int) $cursor['stmt_end'];
			if ( ! empty( $cursor['statement_no'] ) ) {
				$state['statement_no'] = (int) $cursor['statement_no'];
			}
		}
		$importer->use_replace( $replace );
		$shadow_file = $state['sql_path'] . '.shadow';
		$use_shadow  = $replace && ( ( 0 === $offset && 0 === $piece ) || is_file( $shadow_file ) );
		if ( $replace && ! $use_shadow ) {
			throw new \RuntimeException( __( 'This import was interrupted after it had already changed live tables. Cancel it and start a new import. The new import keeps the current administrator in place until the restore finishes.', 'jisento' ) . ' Job: ' . $job->job_id );
		}
		if ( $use_shadow && ! is_file( $shadow_file ) ) {
			file_put_contents( $shadow_file, '{}' );
		}
		$importer->use_shadow( $use_shadow );
		if ( $use_shadow && empty( $state['shadow_noted'] ) ) {
			$plugin->logger->log( $job->job_id, 'importing_database', 'shadow', '', 'info', 'Replace mode is restoring into shadow tables. Live tables, including users, stay in place until the database restore finishes.' );
			$state['shadow_noted'] = true;
		}
		$importer->set_job_id( $job->job_id );
		$importer->set_statement_no( isset( $state['statement_no'] ) ? (int) $state['statement_no'] : 0 );
		$importer->set_cleared( $cleared );
		Live_Url::capture( $job->job_id );
		\Jisento\Migration\Security\Admin_Guard::snapshot( $job->job_id );
		$importer->set_after_statement( array( Live_Url::class, 'hold_if_options_statement' ) );
		$importer->set_progress(
			function ( $info ) use ( &$state, $job, $plugin ) {
				$state['sql_offset']      = (int) $info['offset'];
				$state['piece_index']     = (int) $info['piece_index'];
				$state['stmt_end']        = (int) $info['stmt_end'];
				$state['cleared_tables']  = $info['cleared'];
				$state['statement_no']    = (int) $info['statement_no'];
				$plugin->jobs->update(
					$job->job_id,
					array(
						'stage'        => 'importing_database',
						'current_item' => isset( $info['table'] ) ? $info['table'] : '',
						'state'        => $state,
					)
				);
			}
		);

		$result = $importer->import_chunk( $state['sql_path'], $offset, 12, 800, $piece );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}

		$state['sql_offset']       = $result['offset'];
		$state['piece_index']      = isset( $result['piece_index'] ) ? (int) $result['piece_index'] : 0;
		$state['stmt_end']         = isset( $result['stmt_end'] ) ? (int) $result['stmt_end'] : 0;
		$state['statement_no']     = isset( $result['statement_no'] ) ? (int) $result['statement_no'] : (int) ( isset( $state['statement_no'] ) ? $state['statement_no'] : 0 );
		$state['sql_done']         = ! empty( $result['done'] );
		if ( ! empty( $result['repeated'] ) ) {
			$state['identical_rows'] = (int) ( isset( $state['identical_rows'] ) ? $state['identical_rows'] : 0 ) + (int) $result['repeated'];
			if ( empty( $state['identical_noted'] ) && ! empty( $result['repeated_note'] ) ) {
				$plugin->logger->log( $job->job_id, 'importing_database', 'import', isset( $result['table'] ) ? (string) $result['table'] : '', 'info', (string) $result['repeated_note'] );
				$state['identical_noted'] = true;
			}
		}
		if ( isset( $result['cleared'] ) && is_array( $result['cleared'] ) ) {
			$state['cleared_tables'] = $result['cleared'];
		}
		$state['tables_restored']  = (int) ( isset( $state['tables_restored'] ) ? $state['tables_restored'] : 0 ) + (int) ( isset( $result['creates'] ) ? $result['creates'] : 0 );
		$sizes                     = isset( $state['sizes'] ) && is_array( $state['sizes'] ) ? $state['sizes'] : array();
		$table_total               = (int) ( isset( $sizes['table_count'] ) ? $sizes['table_count'] : 0 );
		$sql_size                  = is_file( $state['sql_path'] ) ? (int) filesize( $state['sql_path'] ) : 0;
		$ratio                     = 0;
		if ( $sql_size > 0 ) {
			$ratio = min( 1, (int) $result['offset'] / $sql_size );
		} elseif ( $table_total > 0 ) {
			$ratio = min( 1, $state['tables_restored'] / $table_total );
		}
		if ( $state['sql_done'] ) {
			$ratio = 1;
		}

		if ( ! empty( $result['table'] ) ) {
			if ( ! isset( $state['db_profile'] ) || ! is_array( $state['db_profile'] ) ) {
				$state['db_profile'] = array();
			}
			$name = (string) $result['table'];
			if ( ! isset( $state['db_profile'][ $name ] ) ) {
				$state['db_profile'][ $name ] = array(
					'seconds'    => 0,
					'statements' => 0,
				);
			}
			$state['db_profile'][ $name ]['seconds']    += (float) ( isset( $result['seconds'] ) ? $result['seconds'] : 0 );
			$state['db_profile'][ $name ]['statements'] += (int) ( isset( $result['statements'] ) ? $result['statements'] : 0 );
		}

		$next_stage = 'importing_database';
		if ( $state['sql_done'] ) {
			if ( $use_shadow ) {
				if ( $importer->shadow_users_empty() ) {
					\Jisento\Migration\Security\Admin_Guard::ensure( $job->job_id );
					throw new \RuntimeException( __( 'The imported users table is empty, so the live administrator was left in place.', 'jisento' ) . ' Job: ' . $job->job_id );
				}
				$swapped = $importer->swap_shadows();
				if ( is_wp_error( $swapped ) ) {
					\Jisento\Migration\Security\Admin_Guard::ensure( $job->job_id );
					throw new \RuntimeException( $swapped->get_error_message() . ' Job: ' . $job->job_id );
				}
				Live_Url::hold();
				\Jisento\Migration\Security\Admin_Guard::ensure( $job->job_id );
			}
			if ( $source_prefix !== $dest_prefix ) {
				$importer->rewrite_prefix_in_data();
			}
			$this->apply_user_preservation( $state );
			@unlink( $state['sql_path'] . '.cursor' );
			@unlink( $state['sql_path'] . '.cleared' );
			$done_note = 'Database import complete. Statements: ' . (int) $state['statement_no'];
			if ( ! empty( $state['identical_rows'] ) ) {
				$done_note .= ' Identical repeated rows already stored and not inserted again: ' . (int) $state['identical_rows'] . '.';
			}
			$plugin->logger->log( $job->job_id, 'importing_database', 'import', '', 'ok', $done_note );
			$next_stage = empty( $state['validation']['files'] ) ? ( ! empty( $state['options']['replace_urls'] ) ? 'replacing_urls' : 'finalizing' ) : 'importing_files';
		}

		$detail = ! empty( $result['table'] ) ? (string) $result['table'] : __( 'Importing database', 'jisento' );
		if ( ! empty( $result['table'] ) && ! empty( $result['statements'] ) ) {
			$detail = sprintf(
				/* translators: 1: table name, 2: statement count, 3: seconds */
				__( '%1$s · %2$d statements · %3$ss', 'jisento' ),
				$result['table'],
				(int) $result['statements'],
				isset( $result['seconds'] ) ? $result['seconds'] : 0
			);
		}
		if ( ! $state['sql_done'] && $ratio >= 0.98 ) {
			$detail = __( 'Finalizing database...', 'jisento' );
			if ( ! empty( $result['table'] ) ) {
				$detail .= ' ' . $result['table'];
			}
		}
		if ( $state['sql_done'] ) {
			$detail = __( 'Database restore complete', 'jisento' );
		}

		return $this->report(
			$job,
			$state,
			array(
				'stage'      => $next_stage,
				'progress'   => $this->import_percent( $state, 'database', $ratio ),
				'bytes_done' => (int) $result['offset'],
			),
			array(
				'phase'          => 'importing_database',
				'label'          => __( 'Restoring database', 'jisento' ),
				'detail'         => $detail,
				'count_done'     => (int) $state['tables_restored'],
				'count_total'    => $table_total,
				'count_unit'     => __( 'Tables', 'jisento' ),
				'stage_progress' => (int) floor( 100 * $ratio ),
				'measure_kind'   => 'bytes',
				'measure_label'  => __( 'Database dump', 'jisento' ),
				'measure_done'   => (int) $result['offset'],
				'measure_total'  => $sql_size,
			)
		);
	}

	private function skip_tables( array $state, $source_prefix, $dest_prefix ) {
		$options = $state['options'];
		if ( 'preserve' !== $options['destination_mode'] ) {
			return array();
		}
		$skip = array();
		if ( ! empty( $options['preserve_users'] ) ) {
			$skip[] = $dest_prefix . 'users';
			$skip[] = $dest_prefix . 'usermeta';
		}
		if ( ! empty( $options['preserve_options'] ) ) {
			$skip[] = $dest_prefix . 'options';
		}
		if ( ! empty( $options['preserve_tables'] ) ) {
			global $wpdb;
			$like    = $wpdb->esc_like( $dest_prefix ) . '%';
			$existing = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			foreach ( $existing as $table ) {
				$skip[] = $table;
			}
		}
		return array_values( array_unique( $skip ) );
	}

	private function apply_user_preservation( array $state ) {
		if ( 'preserve' !== $state['options']['destination_mode'] ) {
			return;
		}
		if ( empty( $state['options']['preserve_users'] ) ) {
			return;
		}
		// Destination users already reside in the live tables that may have been replaced.
		// Preserve mode with preserve_users keeps current user IDs by skipping usermeta/users overwrite
		// at SQL level when those tables are excluded. If they were imported, we cannot perfectly
		// restore prior users without a snapshot; safety backup is the recovery path.
	}

	private function import_files( $job, array $state ) {
		$options = $state['options'];
		$sizes   = isset( $state['sizes'] ) && is_array( $state['sizes'] ) ? $state['sizes'] : array();
		if ( empty( $state['validation']['files'] ) ) {
			return $this->report(
				$job,
				$state,
				array(
					'stage'    => ! empty( $options['replace_urls'] ) ? 'replacing_urls' : 'finalizing',
					'progress' => $this->import_percent( $state, 'files', 1 ),
				),
				array(
					'phase'          => 'importing_files',
					'label'          => __( 'Restoring files', 'jisento' ),
					'detail'         => __( 'Package has no files', 'jisento' ),
					'stage_progress' => 100,
				)
			);
		}

		$this->assert_disk_space( $job, (int) ( isset( $sizes['files'] ) ? $sizes['files'] : 0 ) );
		$archive = new Archive();
		$index   = isset( $state['zip_index'] ) ? (int) $state['zip_index'] : 0;
		$batch   = $archive->extract_files_batch(
			$state['package_path'],
			'',
			$index,
			400,
			12,
			function ( $relative ) use ( $options ) {
				return $this->destination_for_archive_file( $relative, $options );
			}
		);
		if ( is_wp_error( $batch ) ) {
			throw new \RuntimeException( $batch->get_error_message() );
		}

		$state['zip_index']     = (int) $batch['next'];
		$state['files_restored'] = (int) ( isset( $state['files_restored'] ) ? $state['files_restored'] : 0 ) + (int) $batch['extracted'];
		$state['file_bytes']    = (int) ( isset( $state['file_bytes'] ) ? $state['file_bytes'] : 0 ) + (int) $batch['bytes'];
		$state['skipped_files'] = (int) ( isset( $state['skipped_files'] ) ? $state['skipped_files'] : 0 ) + (int) ( isset( $batch['skipped'] ) ? $batch['skipped'] : 0 );
		$file_total             = (int) ( isset( $sizes['file_count'] ) ? $sizes['file_count'] : 0 );
		$files_bytes            = (int) ( isset( $sizes['files'] ) ? $sizes['files'] : 0 );
		$done                   = ! empty( $batch['done'] );
		if ( $files_bytes > 0 ) {
			$ratio = min( 1, $state['file_bytes'] / $files_bytes );
		} elseif ( $file_total > 0 ) {
			$ratio = min( 1, $state['files_restored'] / $file_total );
		} else {
			$ratio = $done ? 1 : 0;
		}
		if ( $done ) {
			$ratio = 1;
		}

		return $this->report(
			$job,
			$state,
			array(
				'stage'    => $done ? ( ! empty( $options['replace_urls'] ) ? 'replacing_urls' : 'finalizing' ) : 'importing_files',
				'progress' => $this->import_percent( $state, 'files', $ratio ),
			),
			array(
				'phase'          => 'importing_files',
				'label'          => __( 'Restoring files', 'jisento' ),
				'detail'         => ! empty( $batch['current'] ) ? $batch['current'] : __( 'Restoring files into the site', 'jisento' ),
				'count_done'     => (int) $state['files_restored'],
				'count_total'    => $file_total,
				'count_unit'     => __( 'Files', 'jisento' ),
				'stage_progress' => (int) floor( 100 * $ratio ),
				'measure_kind'   => 'bytes',
				'measure_label'  => __( 'Restored', 'jisento' ),
				'measure_done'   => (int) $state['file_bytes'],
				'measure_total'  => $files_bytes,
			)
		);
	}

	private function destination_for_archive_file( $relative, array $options ) {
		$dest = $this->map_destination_path( $relative );
		if ( ! $dest ) {
			return '';
		}
		if ( $this->should_skip_file( $relative, $dest, $options ) ) {
			return '';
		}
		$normalized = str_replace( '\\', '/', $dest );
		$content    = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$root       = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$plugin_dir = rtrim( str_replace( '\\', '/', JISENTO_PATH ), '/' );
		$storage    = rtrim( str_replace( '\\', '/', Plugin::instance()->storage->root() ), '/' );
		if ( 0 !== strpos( $normalized, $content . '/' ) && 0 !== strpos( $normalized, $root . '/' ) ) {
			return '';
		}
		if ( $normalized === $plugin_dir || 0 === strpos( $normalized, $plugin_dir . '/' ) ) {
			return '';
		}
		if ( $normalized === $storage || 0 === strpos( $normalized, $storage . '/' ) ) {
			return '';
		}
		$base = strtolower( basename( $normalized ) );
		if ( in_array( $base, array( '.htaccess', 'web.config', '.user.ini', 'php.ini', 'wp-config.php', '.env' ), true ) ) {
			return '';
		}
		return $dest;
	}

	private function assert_disk_space( $job, $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes <= 0 || ! function_exists( 'disk_free_space' ) ) {
			return;
		}
		$free = @disk_free_space( WP_CONTENT_DIR );
		if ( false === $free ) {
			return;
		}
		$need = (int) ( $bytes * 1.1 ) + 67108864;
		if ( $free < $need ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: required space, 2: free space, 3: job id */
					__( 'Not enough free disk space. This step needs about %1$s and %2$s is free. Job: %3$s', 'jisento' ),
					size_format( $need ),
					size_format( $free ),
					$job->job_id
				)
			);
		}
	}

	private function map_destination_path( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( 0 === strpos( $relative, 'wp-content/' ) ) {
			return WP_CONTENT_DIR . '/' . substr( $relative, strlen( 'wp-content/' ) );
		}
		if ( 0 === strpos( $relative, 'wp-admin/' ) || 0 === strpos( $relative, 'wp-includes/' ) ) {
			return rtrim( ABSPATH, '/\\' ) . '/' . $relative;
		}
		return WP_CONTENT_DIR . '/' . $relative;
	}

	private function should_skip_file( $relative, $dest, array $options ) {
		if ( 'replace' === $options['destination_mode'] ) {
			return false;
		}

		$is_plugin = false !== strpos( $relative, '/plugins/' ) || 0 === strpos( $relative, 'wp-content/plugins/' ) || 0 === strpos( $relative, 'plugins/' );
		$is_theme  = false !== strpos( $relative, '/themes/' ) || 0 === strpos( $relative, 'wp-content/themes/' ) || 0 === strpos( $relative, 'themes/' );
		$is_upload = false !== strpos( $relative, '/uploads/' ) || 0 === strpos( $relative, 'wp-content/uploads/' ) || 0 === strpos( $relative, 'uploads/' );

		$plugin_slug = $this->plugin_slug_from_relative( $relative );
		$theme_slug  = $this->theme_slug_from_relative( $relative );

		if ( $is_plugin && $plugin_slug ) {
			$strategy = $this->plugin_strategy( $plugin_slug, $options );
			$exists   = is_dir( WP_PLUGIN_DIR . '/' . $plugin_slug ) || file_exists( WP_PLUGIN_DIR . '/' . $plugin_slug . '.php' );
			if ( 'skip' === $strategy ) {
				return true;
			}
			if ( 'keep_destination' === $strategy && $exists ) {
				return true;
			}
			if ( 'install_missing' === $strategy && $exists ) {
				return true;
			}
			if ( 'replace_matching' === $strategy ) {
				return false;
			}
		}

		if ( $is_theme && $theme_slug ) {
			$strategy = $this->theme_strategy( $theme_slug, $options );
			$exists   = is_dir( get_theme_root() . '/' . $theme_slug );
			if ( 'skip' === $strategy ) {
				return true;
			}
			if ( 'keep_destination' === $strategy && $exists ) {
				return true;
			}
			if ( 'install_missing' === $strategy && $exists ) {
				return true;
			}
		}

		if ( $is_upload && ! empty( $options['preserve_uploads'] ) && file_exists( $dest ) ) {
			return true;
		}

		return false;
	}

	private function plugin_slug_from_relative( $relative ) {
		if ( preg_match( '#plugins/([^/]+)#', $relative, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function theme_slug_from_relative( $relative ) {
		if ( preg_match( '#themes/([^/]+)#', $relative, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function plugin_strategy( $slug, array $options ) {
		if ( ! empty( $options['plugin_conflicts'][ $slug ] ) ) {
			return sanitize_key( $options['plugin_conflicts'][ $slug ] );
		}
		return sanitize_key( $options['plugin_strategy'] );
	}

	private function theme_strategy( $slug, array $options ) {
		if ( ! empty( $options['theme_conflicts'][ $slug ] ) ) {
			return sanitize_key( $options['theme_conflicts'][ $slug ] );
		}
		return sanitize_key( $options['theme_strategy'] );
	}

	private function replace_urls( $job, array $state ) {
		$plugin = Plugin::instance();
		$source = $state['options']['source_url'] ? $state['options']['source_url'] : $state['manifest']['home_url'];
		$dest   = $state['options']['dest_url'] ? $state['options']['dest_url'] : home_url();
		$replacer = new Url_Replacer();
		$prior    = isset( $state['url_state'] ) ? $state['url_state'] : array();
		$result   = $replacer->replace_all( $source, $dest, 8, $prior );
		$state['url_state'] = $result;

		$table_total = ( isset( $result['tables'] ) && is_array( $result['tables'] ) ) ? count( $result['tables'] ) : 0;
		$table_index = isset( $result['index'] ) ? (int) $result['index'] : 0;
		$ratio       = ! empty( $result['done'] ) ? 1 : ( $table_total > 0 ? min( 1, $table_index / $table_total ) : 0 );
		if ( ! empty( $result['done'] ) ) {
			Live_Url::adopt( $dest );
			Live_Url::hold();
			$plugin->logger->log( $job->job_id, 'replacing_urls', 'replace', '', 'ok', 'Updated ' . (int) $result['updated'] . ' rows' );
		}

		return $this->report(
			$job,
			$state,
			array(
				'stage'    => ! empty( $result['done'] ) ? 'finalizing' : 'replacing_urls',
				'progress' => $this->import_percent( $state, 'urls', $ratio ),
			),
			array(
				'phase'          => 'replacing_urls',
				'label'          => __( 'Replacing URLs', 'jisento' ),
				'detail'         => ! empty( $result['table'] ) ? $result['table'] : __( 'Updating stored addresses', 'jisento' ),
				'count_done'     => $table_index,
				'count_total'    => $table_total,
				'count_unit'     => __( 'Tables', 'jisento' ),
				'stage_progress' => (int) floor( 100 * $ratio ),
			)
		);
	}

	private function finalize( $job, array $state ) {
		$plugin  = Plugin::instance();
		$cleanup = new Cleanup();
		$step    = isset( $state['finalize_step'] ) ? $state['finalize_step'] : 'rewrites';
		if ( 'rewrites' === $step ) {
			$cleanup->flush_rewrites();
			$state['finalize_step'] = 'caches';
			return $this->report( $job, $state, array( 'stage' => 'finalizing', 'progress' => 98 ), array( 'phase' => 'finalizing', 'label' => __( 'Finalizing migration', 'jisento' ), 'detail' => __( 'Updating permalinks', 'jisento' ), 'stage_progress' => 25 ) );
		}
		if ( 'caches' === $step ) {
			$cleanup->clear_caches();
			$state['finalize_step'] = 'plugins';
			return $this->report( $job, $state, array( 'stage' => 'finalizing', 'progress' => 99 ), array( 'phase' => 'finalizing', 'label' => __( 'Finalizing migration', 'jisento' ), 'detail' => __( 'Clearing caches', 'jisento' ), 'stage_progress' => 50 ) );
		}
		if ( 'plugins' === $step ) {
			$cleanup->elementor();
			$cleanup->woocommerce();
			$state['finalize_step'] = 'verify';
			return $this->report( $job, $state, array( 'stage' => 'finalizing', 'progress' => 99 ), array( 'phase' => 'finalizing', 'label' => __( 'Finalizing migration', 'jisento' ), 'detail' => __( 'Checking Elementor and WooCommerce', 'jisento' ), 'stage_progress' => 75 ) );
		}
		$report  = array(
			'source'      => isset( $state['manifest']['home_url'] ) ? $state['manifest']['home_url'] : '',
			'destination' => home_url(),
			'started'     => $job->created_at,
			'completed'   => current_time( 'mysql' ),
			'database'    => ! empty( $state['sql_done'] ),
			'files'       => true,
			'urls'        => ! empty( $state['options']['replace_urls'] ),
			'warnings'    => array(),
			'errors'      => 0,
			'mode'        => $state['options']['destination_mode'],
			'timings'     => isset( $state['timings'] ) ? $state['timings'] : array(),
		);
		$cleanup->verify( $report );
		if ( ! empty( $report['errors'] ) ) {
			throw new \RuntimeException( implode( ' ', $report['warnings'] ) . ' Job: ' . $job->job_id );
		}
		$report['total_seconds'] = 0;
		if ( ! empty( $state['timings'] ) && is_array( $state['timings'] ) ) {
			$report['total_seconds'] = (int) round( array_sum( $state['timings'] ) );
		}
		$cleanup->remove_tmp( $job->job_id );
		Live_Url::hold();
		\Jisento\Migration\Security\Admin_Guard::ensure( $job->job_id );
		Live_Url::release();
		$state['report'] = $report;
		$plugin->logger->log( $job->job_id, 'finalizing', 'complete', '', 'ok', 'Migration completed' );

		return $plugin->jobs->update(
			$job->job_id,
			array(
				'status'       => 'completed',
				'stage'        => 'completed',
				'progress'     => 100,
				'current_item' => __( 'Migration completed successfully', 'jisento' ),
				'state'        => $state,
			)
		);
	}
}
