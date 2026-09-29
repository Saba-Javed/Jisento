<?php
/**
 * Chunked importer with replace and preserve destination modes.
 *
 * Every database restore goes into shadow tables ("<table>__js"). Live tables are only replaced
 * by one RENAME TABLE after every segment has been restored and verified.
 *
 * - Replace mode swaps every table in the package.
 * - Preserve mode swaps only tables that do not exist on this site yet, plus existing tables the
 *   administrator explicitly listed in replace_tables. Every other existing table is kept as is.
 *
 * Nothing destructive happens before the package has been verified: format marker, manifest,
 * file count and bytes, and the SHA-256 of every database segment.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Import;

use Jisento\Migration\Core\Cleanup;
use Jisento\Migration\Core\Compatibility;
use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Database\Legacy_Package;
use Jisento\Migration\Filesystem\File_System;
use Jisento\Migration\Jobs\Job_Conflict;
use Jisento\Migration\Jobs\Job_Runner;
use Jisento\Migration\Jobs\Step_Budget;
use Jisento\Migration\Package\Archive;
use Jisento\Migration\Plugin;
use Jisento\Migration\Replace\Url_Replacer;
use Jisento\Migration\Security\Admin_Guard;
use Jisento\Migration\Security\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Importer {

	/**
	 * Most bytes written to disk per files step; larger files continue in the next step.
	 *
	 * @var int
	 */
	public static $extract_bytes = 33554432;

	/**
	 * Files never restored, wherever they are in the package.
	 */
	const SKIP_BASENAMES = array( '.htaccess', 'web.config', '.user.ini', 'php.ini', 'wp-config.php', '.env' );

	/**
	 * Drop-ins that describe the source server; only skipped directly in wp-content.
	 */
	const SKIP_DROPINS = array( 'object-cache.php', 'advanced-cache.php', 'db.php', 'db-error.php', 'maintenance.php' );

	/**
	 * @return object|\WP_Error
	 */
	public function start( array $options ) {
		$plugin = Plugin::instance();
		$job    = Job_Runner::open(
			'import',
			array(
				'options' => $this->normalize_options( $options ),
			)
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$plugin->logger->log( $job->job_id, 'created', 'import', '', 'info', 'Import job created' );
		$job = $plugin->jobs->update(
			$job,
			array(
				'status' => 'running',
				'stage'  => 'validating',
			)
		);
		self::arm( $job );
		return $job;
	}

	/**
	 * Destination protections for a running import.
	 *
	 * @param object $job Job.
	 */
	public static function arm( $job ) {
		Live_Url::capture( $job->job_id );
		if ( ! empty( $job->state['skipped_plugin_dirs'] ) ) {
			Live_Url::skip_plugin_dirs( $job->job_id, $job->state['skipped_plugin_dirs'] );
		}
		Admin_Guard::snapshot( $job->job_id );
	}

	/**
	 * Plugin folder names whose active_plugins entries must not survive the import: Jisento
	 * copies found in the package, and the source's own Jisento folder (never exported).
	 *
	 * @param string[] $copies   Archive prefixes such as "wp-content/plugins/foo/".
	 * @param array    $manifest Package manifest.
	 * @return string[]
	 */
	public static function skipped_plugin_dirs( array $copies, array $manifest ) {
		$dirs = array();
		foreach ( $copies as $prefix ) {
			if ( preg_match( '#(?:^|/)plugins/([^/]+)/?$#', (string) $prefix, $m ) ) {
				$dirs[] = $m[1];
			}
		}
		if ( ! empty( $manifest['plugin_basename'] ) && false !== strpos( (string) $manifest['plugin_basename'], '/' ) ) {
			$dirs[] = substr( (string) $manifest['plugin_basename'], 0, strpos( (string) $manifest['plugin_basename'], '/' ) );
		}
		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Cleanup when an import completes, fails or is cancelled.
	 *
	 * @param object $job Job.
	 */
	public static function cleanup( $job ) {
		$plugin = Plugin::instance();
		$state  = is_array( $job->state ) ? $job->state : array();
		Database_Importer::forget_job( $job->job_id );
		if ( empty( $state['db_swapped'] ) && ! empty( $state['plan']['restore'] ) ) {
			$left = Database_Importer::drop_shadows( $state['plan']['restore'] );
			if ( $left ) {
				$plugin->logger->log( $job->job_id, $job->stage, 'cleanup', '', 'warning', 'Could not drop restored copies (drop them manually): ' . implode( ', ', $left ) );
			}
		}
		Admin_Guard::ensure( $job->job_id );
		Admin_Guard::forget( $job->job_id );
		$warnings = Live_Url::release( $job->job_id );
		if ( $warnings && 'completed' === $job->status ) {
			$state = is_array( $job->state ) ? $job->state : array();
			if ( ! isset( $state['report'] ) || ! is_array( $state['report'] ) ) {
				$state['report'] = array();
			}
			if ( empty( $state['report']['warnings'] ) || ! is_array( $state['report']['warnings'] ) ) {
				$state['report']['warnings'] = array();
			}
			$state['report']['warnings'] = array_values( array_merge( $state['report']['warnings'], $warnings ) );
			foreach ( $warnings as $warning ) {
				$plugin->logger->log( $job->job_id, 'finalizing', 'theme', '', 'warning', $warning );
			}
			$plugin->jobs->update( $job, array( 'state' => $state ) );
		}
		if ( ! empty( $state['zip_partial']['tmp'] ) && '.jisento-tmp' === substr( (string) $state['zip_partial']['tmp'], -12 ) ) {
			@unlink( (string) $state['zip_partial']['tmp'] );
		}
		$plugin->storage->delete_tree( $plugin->storage->tmp_dir( $job->job_id ) );
	}

	/**
	 * Fields for an explicit retry of a failed import. Before the swap, everything restarts from
	 * package validation (shadow tables and the ledger were removed when the job failed). After
	 * the swap, the package is verified again and the failed post-database stage runs again;
	 * those stages are idempotent.
	 *
	 * @param object $job Failed job.
	 * @return array
	 * @throws \RuntimeException When the job cannot be retried.
	 */
	public static function retry_fields( $job ) {
		$state = is_array( $job->state ) ? $job->state : array();
		if ( empty( $state['options'] ) ) {
			throw new \RuntimeException( __( 'Operation: retry. Reason: the job has no saved options. Recovery: start a new import.', 'jisento' ) );
		}
		if ( empty( $state['db_swapped'] ) ) {
			return array(
				'stage'    => 'validating',
				'progress' => 0,
				'state'    => array( 'options' => $state['options'] ),
			);
		}
		$path = isset( $state['package_path'] ) ? (string) $state['package_path'] : '';
		if ( '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( __( 'Operation: retry. Reason: the package file is gone, and the database was already restored. Recovery: upload the same package again and start a new import.', 'jisento' ) );
		}
		$info = ( new Archive() )->verify_structure( $path );
		if ( is_wp_error( $info ) ) {
			throw new \RuntimeException( $info->get_error_message() );
		}
		if ( 'importing_database' === $job->stage ) {
			// Failed after the swap (foreign keys or engines): the swap step is safe to repeat.
			$state['db_phase'] = 'swap';
			return array(
				'stage' => 'importing_database',
				'state' => $state,
			);
		}
		$stage = in_array( $job->stage, array( 'importing_files', 'replacing_urls', 'finalizing' ), true ) ? $job->stage : 'importing_files';
		return array(
			'stage' => $stage,
			'state' => $state,
		);
	}

	public function step( $job ) {
		Step_Budget::begin();
		$state = is_array( $job->state ) ? $job->state : array();
		if ( ! empty( $state['options'] ) ) {
			$state['options'] = $this->normalize_options( $state['options'] );
		}
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
	}

	private function normalize_options( array $options ) {
		$defaults = array(
			'package'                  => '',
			'destination_mode'         => 'preserve',
			'confirm_replace'          => false,
			'replace_tables'           => array(),
			'repair_placeholders'      => false,
			'restore_original_engines' => false,
			'replace_urls'             => true,
			'replace_guids'            => false,
			'replace_emails'           => true,
			'source_url'               => '',
			'dest_url'                 => home_url(),
			'preserve_uploads'         => true,
			'plugin_strategy'          => 'install_missing',
			'theme_strategy'           => 'keep_destination',
			'plugin_conflicts'         => array(),
			'theme_conflicts'          => array(),
		);
		$options = wp_parse_args( $options, $defaults );
		$options['destination_mode'] = 'replace' === $options['destination_mode'] ? 'replace' : 'preserve';
		$tables = array();
		foreach ( (array) $options['replace_tables'] as $table ) {
			$table = preg_replace( '/[^A-Za-z0-9_$]/', '', (string) $table );
			if ( '' !== $table ) {
				$tables[] = $table;
			}
		}
		$options['replace_tables']           = array_values( array_unique( $tables ) );
		$options['repair_placeholders']      = ! empty( $options['repair_placeholders'] );
		$options['restore_original_engines'] = ! empty( $options['restore_original_engines'] );
		$options['replace_guids']            = ! empty( $options['replace_guids'] );
		$options['replace_emails']           = ! empty( $options['replace_emails'] );
		$options['replace_urls']             = ! empty( $options['replace_urls'] );
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
			throw new \RuntimeException( __( 'Operation: open the package. Reason: the selected .jisento package could not be found. Recovery: upload it again, then start the import.', 'jisento' ) );
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
		return Plugin::instance()->jobs->update( $job, $fields );
	}

	private static function error( $job, $operation, $reason, $recovery ) {
		return new \RuntimeException(
			sprintf( 'Stage: %1$s. Operation: %2$s. Reason: %3$s Recovery: %4$s Job: %5$s', $job->stage, $operation, rtrim( (string) $reason ), $recovery, $job->job_id )
		);
	}

	/* ------------------------------------------------------------------
	 * Validation: nothing is changed until the whole package checks out.
	 * ------------------------------------------------------------------ */

	private function validate( $job, array $state ) {
		$plugin  = Plugin::instance();
		$path    = $this->package_path( $state['options'] );
		$archive = new Archive();
		$info    = $archive->verify_structure( $path );
		if ( is_wp_error( $info ) ) {
			throw self::error( $job, 'verify the package', $info->get_error_message(), __( 'Export the source site again with this plugin version and upload the new package.', 'jisento' ) );
		}
		if ( empty( $info['has_db'] ) && empty( $info['has_files'] ) ) {
			throw self::error( $job, 'verify the package', __( 'The package contains neither a database dump nor site files.', 'jisento' ), __( 'Export the source site again.', 'jisento' ) );
		}
		$mode = $state['options']['destination_mode'];
		if ( 'replace' === $mode && empty( $state['options']['confirm_replace'] ) ) {
			throw self::error( $job, 'check options', __( 'Complete replacement requires explicit confirmation.', 'jisento' ), __( 'Tick the confirmation box and start again.', 'jisento' ) );
		}

		$state['package_path'] = $path;
		$state['manifest']     = $info['manifest'];
		$state['format']       = (int) $info['format'];
		$state['legacy']       = 1 === (int) $info['format'];
		$state['segments']     = $info['segments'];
		$state['validation']   = array(
			'format_marker' => true,
			'manifest'      => true,
			'structure'     => true,
			'database'      => (bool) $info['has_db'],
			'files'         => (bool) $info['has_files'],
		);
		$state['jisento_copies']      = $archive->jisento_plugin_copies( $path );
		$state['skipped_plugin_dirs'] = self::skipped_plugin_dirs( $state['jisento_copies'], $info['manifest'] );
		Live_Url::skip_plugin_dirs( $job->job_id, $state['skipped_plugin_dirs'] );
		if ( empty( $state['options']['source_url'] ) && ! empty( $info['manifest']['home_url'] ) ) {
			$state['options']['source_url'] = $info['manifest']['home_url'];
		}

		$note = sprintf( 'Package verified: format v%d, %d files, %d database segment(s).', (int) $info['format'], (int) $info['file_entries'], count( $info['segments'] ) );
		if ( $state['legacy'] ) {
			$note .= ' This is a version 1 package; it will be restored in compatibility mode.';
		}
		if ( 2 === (int) $info['format'] && empty( $info['entry_digests'] ) ) {
			$note .= ' No checksums/entries.jsonl; file restore will use ZIP CRC-32 only.';
			$plugin->logger->log( $job->job_id, 'validating', 'package', basename( $path ), 'info', 'Package has no checksums/entries.jsonl (older v2 build). Destination files will be checked with ZIP CRC-32 only.' );
		}
		$plugin->logger->log( $job->job_id, 'validating', 'package', basename( $path ), 'ok', $note );
		if ( $state['jisento_copies'] ) {
			$plugin->logger->log( $job->job_id, 'validating', 'package', '', 'info', 'Copies of Jisento Migration inside the package are not restored: ' . implode( ', ', $state['jisento_copies'] ) );
		}

		clearstatcache( true, $path );
		$manifest = $info['manifest'];
		$db_bytes = 0;
		foreach ( $info['segments'] as $segment ) {
			$db_bytes += (int) $segment['bytes'];
		}
		$state['sizes'] = array(
			'package'     => (int) filesize( $path ),
			'database'    => $db_bytes,
			'files'       => (int) $info['file_bytes'],
			'contents'    => $db_bytes + (int) $info['file_bytes'],
			'file_count'  => (int) $info['file_entries'],
			'table_count' => (int) ( isset( $manifest['table_count'] ) ? $manifest['table_count'] : 0 ),
		);

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
				'detail'         => __( 'Format marker, manifest and contents verified', 'jisento' ),
				'stage_progress' => 100,
			)
		);
	}

	private function compatibility( $job, array $state ) {
		$plugin = Plugin::instance();
		$compat = ( new Compatibility() )->run( $state['manifest'] );
		$state['compatibility'] = $compat;
		if ( ! $compat['can_run'] ) {
			throw self::error( $job, 'check destination compatibility', __( 'Destination compatibility checks failed.', 'jisento' ), __( 'View details, resolve the errors, then start the import again.', 'jisento' ) );
		}
		$plugin->logger->log( $job->job_id, 'compatibility', 'check', '', 'ok', 'Compatibility passed' );
		return $plugin->jobs->update(
			$job,
			array(
				'stage'        => 'extracting',
				'progress'     => $this->import_percent( $state, 'compatibility', 1 ),
				'current_item' => __( 'Destination compatibility', 'jisento' ),
				'state'        => $state,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Extraction: one verified segment at a time, then the restore plan.
	 * ------------------------------------------------------------------ */

	private function extract( $job, array $state ) {
		$plugin = Plugin::instance();
		if ( empty( $state['validation']['database'] ) ) {
			$state['db_swapped'] = false;
			$state['db_done']    = true;
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
		if ( 'plan' === ( isset( $state['extract_phase'] ) ? $state['extract_phase'] : '' ) ) {
			return $this->plan( $job, $state );
		}

		$segments = $state['segments'];
		$index    = isset( $state['extract_index'] ) ? (int) $state['extract_index'] : 0;
		if ( 0 === $index ) {
			$this->assert_disk_space( $job, (int) $state['sizes']['database'] );
		}
		$dir     = $plugin->storage->tmp_dir( $job->job_id ) . '/database';
		$archive = new Archive();
		$started = microtime( true );
		$done    = 0;
		while ( $index < count( $segments ) && ( 0 === $done || microtime( true ) - $started < 8 ) && ! Step_Budget::exhausted() ) {
			$segment = $segments[ $index ];
			$dest    = $dir . '/' . basename( $segment['entry'] );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw self::error( $job, 'extract the database', sprintf( __( 'Folder %s could not be created.', 'jisento' ), $dir ), __( 'Make wp-content/jisento writable by PHP, then press Retry.', 'jisento' ) );
			}
			$result = $archive->extract_verified( $state['package_path'], $segment['entry'], $dest, $segment['bytes'], $segment['sha256'] );
			if ( is_wp_error( $result ) ) {
				throw self::error( $job, sprintf( 'verify database segment %s', $segment['entry'] ), $result->get_error_message(), __( 'Upload the package again; if it fails again, export the source again. No table was changed.', 'jisento' ) );
			}
			$index++;
			$done++;
		}
		$state['extract_index'] = $index;
		if ( $index >= count( $segments ) ) {
			$state['extract_phase'] = 'plan';
			$plugin->logger->log( $job->job_id, 'extracting', 'database', '', 'ok', sprintf( '%d database segment(s) extracted; every SHA-256 matches the manifest.', count( $segments ) ) );
		}
		return $this->report(
			$job,
			$state,
			array(
				'stage'    => 'extracting',
				'progress' => $this->import_percent( $state, 'extract', count( $segments ) ? $index / count( $segments ) : 1 ),
			),
			array(
				'phase'          => 'extracting',
				'label'          => __( 'Extracting package', 'jisento' ),
				'detail'         => sprintf( __( 'Verified database segment %1$d of %2$d', 'jisento' ), $index, count( $segments ) ),
				'count_done'     => $index,
				'count_total'    => count( $segments ),
				'count_unit'     => __( 'Segments', 'jisento' ),
				'stage_progress' => (int) floor( 100 * ( count( $segments ) ? $index / count( $segments ) : 1 ) ),
			)
		);
	}

	/**
	 * Decide which tables are restored and swapped, and refuse unsafe starts.
	 */
	private function plan( $job, array $state ) {
		global $wpdb;
		$plugin        = Plugin::instance();
		$manifest      = $state['manifest'];
		$source_prefix = isset( $manifest['database_prefix'] ) ? (string) $manifest['database_prefix'] : 'wp_';
		$dest_prefix   = $wpdb->prefix;
		$probe         = new Database_Importer( $source_prefix, $dest_prefix, array( 'job_id' => $job->job_id ) );

		$source_tables = array();
		$expected      = array();
		if ( ! empty( $state['legacy'] ) ) {
			$sql  = $this->segment_path( $job, $state, 0 );
			$scan = Legacy_Package::scan( $sql );
			if ( is_wp_error( $scan ) ) {
				throw self::error( $job, 'check the version 1 dump', $scan->get_error_message(), __( 'Press Retry. If it fails again, upload the package again.', 'jisento' ) );
			}
			$source_tables = $scan['tables'];
			if ( $scan['tokens'] ) {
				$state['v1_placeholders'] = array(
					'tokens'      => count( $scan['tokens'] ),
					'occurrences' => (int) $scan['occurrences'],
				);
				if ( empty( $state['options']['repair_placeholders'] ) ) {
					throw self::error(
						$job,
						'check the version 1 dump',
						sprintf(
							/* translators: 1: occurrences, 2: distinct tokens */
							__( 'This package was created by Jisento Migration 1.2.11 or older, which replaced every "%%" character in the database with a placeholder. It contains %1$d occurrences of %2$d placeholder token(s). Importing it unchanged would put those placeholders into your content and break serialized data. No table was changed.', 'jisento' ),
							(int) $scan['occurrences'],
							count( $scan['tokens'] )
						),
						__( 'Recommended: update the plugin on the source site and export again. Or start a new import with "Repair % characters" enabled, which replaces exactly those repeated tokens with "%".', 'jisento' )
					);
				}
				$state['placeholder_tokens'] = array_keys( $scan['tokens'] );
				$plugin->logger->log( $job->job_id, 'extracting', 'repair', '', 'warning', sprintf( 'Opt-in repair enabled: %d placeholder token(s), %d occurrence(s), will be replaced with "%%" while restoring.', count( $scan['tokens'] ), (int) $scan['occurrences'] ) );
			}
		} else {
			$tables = isset( $manifest['database']['tables'] ) && is_array( $manifest['database']['tables'] ) ? $manifest['database']['tables'] : array();
			foreach ( $tables as $table => $stat ) {
				$source_tables[]                        = (string) $table;
				$expected[ $probe->dest_table( $table ) ] = (int) ( is_array( $stat ) && isset( $stat['rows'] ) ? $stat['rows'] : 0 );
			}
		}

		$existing = array();
		foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $name ) {
			$existing[ (string) $name ] = true;
		}
		$selected = array();
		foreach ( $state['options']['replace_tables'] as $name ) {
			$selected[ $probe->dest_table( $name ) ] = true;
		}
		$replace = 'replace' === $state['options']['destination_mode'];
		$restore = array();
		$keep    = array();
		foreach ( $source_tables as $table ) {
			$dest = $probe->dest_table( $table );
			if ( $replace || ! isset( $existing[ $dest ] ) || isset( $selected[ $dest ] ) ) {
				$restore[] = $dest;
			} else {
				$keep[] = $dest;
			}
		}
		$restore = array_values( array_unique( $restore ) );
		$keep    = array_values( array_unique( $keep ) );

		$collisions = $probe->colliding_names( $restore );
		if ( $collisions ) {
			throw self::error(
				$job,
				'plan the restore',
				sprintf( __( 'These tables already exist and would be overwritten by the restore work tables: %s. They are usually left over from an interrupted import.', 'jisento' ), implode( ', ', $collisions ) ),
				__( 'Check that they hold nothing you need, drop them (for example with phpMyAdmin), then start the import again. No table was changed.', 'jisento' )
			);
		}

		$state['plan'] = array(
			'restore'  => $restore,
			'keep'     => $keep,
			'expected' => $expected,
		);
		$state['db_phase']    = 'restore';
		$state['db_segment']  = 0;
		$state['db_swapped']  = false;
		$state['extract_phase'] = 'done';
		$users_table = $dest_prefix . 'users';
		$state['users_replaced'] = in_array( $users_table, $restore, true ) && isset( $existing[ $users_table ] );

		$summary = $replace
			? sprintf( 'Replace mode: %d table(s) will be restored and swapped in.', count( $restore ) )
			: sprintf( 'Preserve mode: %1$d new or selected table(s) will be restored; %2$d existing table(s) are kept unchanged: %3$s', count( $restore ), count( $keep ), implode( ', ', array_slice( $keep, 0, 40 ) ) );
		$plugin->logger->log( $job->job_id, 'extracting', 'plan', '', 'info', $summary );

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
				'detail'         => $replace ? __( 'All tables will be replaced', 'jisento' ) : sprintf( __( '%1$d tables restored, %2$d kept', 'jisento' ), count( $restore ), count( $keep ) ),
				'stage_progress' => 100,
			)
		);
	}

	private function segment_path( $job, array $state, $index ) {
		return Plugin::instance()->storage->tmp_dir( $job->job_id ) . '/database/' . basename( $state['segments'][ $index ]['entry'] );
	}

	/* ------------------------------------------------------------------
	 * Database: restore into shadows, verify, swap.
	 * ------------------------------------------------------------------ */

	private function db_importer( $job, array $state ) {
		$manifest = $state['manifest'];
		return new Database_Importer(
			isset( $manifest['database_prefix'] ) ? (string) $manifest['database_prefix'] : 'wp_',
			$GLOBALS['wpdb']->prefix,
			array(
				'job_id'             => $job->job_id,
				'legacy'             => ! empty( $state['legacy'] ),
				'placeholder_tokens' => isset( $state['placeholder_tokens'] ) ? $state['placeholder_tokens'] : array(),
				'restore'            => $state['plan']['restore'],
				'keep'               => $state['plan']['keep'],
				'session'            => isset( $state['db_session'] ) ? $state['db_session'] : array(),
				'statement_no'       => isset( $state['db_statement_no'] ) ? (int) $state['db_statement_no'] : 0,
			)
		);
	}

	private function import_database( $job, array $state ) {
		$phase = isset( $state['db_phase'] ) ? $state['db_phase'] : 'restore';
		if ( 'verify' === $phase ) {
			return $this->verify_database( $job, $state );
		}
		if ( 'swap' === $phase ) {
			return $this->swap_database( $job, $state );
		}
		return $this->restore_database( $job, $state );
	}

	private function restore_database( $job, array $state ) {
		$plugin   = Plugin::instance();
		$importer = $this->db_importer( $job, $state );
		$segments = $state['segments'];
		$cursor   = $importer->read_cursor();
		if ( null === $cursor ) {
			if ( ! empty( $state['db_started'] ) ) {
				throw self::error( $job, 'resume the database restore', __( 'The saved restore position is missing, so the restore cannot continue without guessing.', 'jisento' ), __( 'Press Retry to restart the database restore from the beginning. Live tables were not changed.', 'jisento' ) );
			}
			$started = $importer->start_segment( 0 );
			if ( is_wp_error( $started ) ) {
				throw self::error( $job, 'start the database restore', $started->get_error_message(), __( 'Check the database user can create tables, then press Retry.', 'jisento' ) );
			}
			$state['db_started'] = true;
			$cursor              = $importer->read_cursor();
			$plugin->logger->log( $job->job_id, 'importing_database', 'shadow', '', 'info', 'Restoring into work tables (suffix ' . Database_Importer::SHADOW_SUFFIX . '). Live tables, including users, stay in place until every segment is restored and checked.' );
		}
		$index = (int) $cursor['segment'];
		if ( $index >= count( $segments ) ) {
			$state['db_phase'] = 'verify';
			return $this->report( $job, $state, array( 'stage' => 'importing_database' ), array( 'phase' => 'importing_database', 'label' => __( 'Restoring database', 'jisento' ), 'detail' => __( 'Checking restored tables', 'jisento' ), 'stage_progress' => 99 ) );
		}
		$path = $this->segment_path( $job, $state, $index );
		if ( ! is_file( $path ) ) {
			throw self::error( $job, 'restore the database', sprintf( __( 'Extracted segment %s is missing.', 'jisento' ), basename( $path ) ), __( 'Press Retry to extract and verify the package again. Live tables were not changed.', 'jisento' ) );
		}
		$chunk = $importer->import_chunk( $index, $path, max( 1, Step_Budget::seconds( 12 ) ), 800 );
		if ( is_wp_error( $chunk ) ) {
			throw self::error( $job, sprintf( 'restore %s', basename( $path ) ), $chunk->get_error_message(), __( 'Fix the reported cause, then press Retry. Live tables were not changed; the restore restarts from the beginning.', 'jisento' ) );
		}
		$state['db_session']      = $chunk['session'];
		$state['db_statement_no'] = (int) $chunk['statement_no'];
		$this->record_notes( $job, $state, $chunk['notes'] );
		if ( ! empty( $chunk['placeholders_repaired'] ) ) {
			$state['placeholders_repaired'] = (int) ( isset( $state['placeholders_repaired'] ) ? $state['placeholders_repaired'] : 0 ) + (int) $chunk['placeholders_repaired'];
		}
		if ( '' !== $chunk['table'] ) {
			$name = $chunk['table'];
			if ( ! isset( $state['db_profile'][ $name ] ) ) {
				$state['db_profile'][ $name ] = array(
					'seconds'    => 0,
					'statements' => 0,
				);
			}
			$state['db_profile'][ $name ]['seconds']    += (float) $chunk['seconds'];
			$state['db_profile'][ $name ]['statements'] += (int) $chunk['statements'];
		}
		if ( $chunk['done'] ) {
			$next = $index + 1;
			if ( $next < count( $segments ) ) {
				$moved = $importer->start_segment( $next );
				if ( is_wp_error( $moved ) ) {
					throw self::error( $job, 'save the restore position', $moved->get_error_message(), __( 'Press Retry.', 'jisento' ) );
				}
			} else {
				$importer->start_segment( $next );
				$state['db_phase'] = 'verify';
			}
			@unlink( $path );
		}

		$total = 0;
		$done  = 0;
		foreach ( $segments as $i => $segment ) {
			$total += (int) $segment['bytes'];
			if ( $i < $index ) {
				$done += (int) $segment['bytes'];
			}
		}
		$done  += $chunk['done'] ? (int) $segments[ $index ]['bytes'] : (int) $chunk['offset'];
		$ratio  = $total > 0 ? min( 1, $done / $total ) : 1;
		$detail = '' !== $chunk['table']
			? sprintf( __( '%1$s · %2$d statements · %3$ss', 'jisento' ), $chunk['table'], (int) $chunk['statements'], $chunk['seconds'] )
			: __( 'Restoring database', 'jisento' );

		return $this->report(
			$job,
			$state,
			array(
				'stage'      => 'importing_database',
				'progress'   => $this->import_percent( $state, 'database', $ratio * 0.97 ),
				'bytes_done' => $done,
			),
			array(
				'phase'          => 'importing_database',
				'label'          => __( 'Restoring database', 'jisento' ),
				'detail'         => $detail,
				'count_done'     => min( count( $segments ), $index + ( $chunk['done'] ? 1 : 0 ) ),
				'count_total'    => count( $segments ),
				'count_unit'     => __( 'Segments', 'jisento' ),
				'stage_progress' => (int) floor( 97 * $ratio ),
				'measure_kind'   => 'bytes',
				'measure_label'  => __( 'Database dump', 'jisento' ),
				'measure_done'   => $done,
				'measure_total'  => $total,
			)
		);
	}

	/**
	 * Merge the importer's notes into state and log each new mapping once.
	 */
	private function record_notes( $job, array &$state, $notes ) {
		if ( empty( $notes ) || ! is_array( $notes ) ) {
			return;
		}
		$logger = Plugin::instance()->logger;
		foreach ( array( 'engines', 'collations', 'collations_kept', 'constraints' ) as $kind ) {
			if ( empty( $notes[ $kind ] ) ) {
				continue;
			}
			foreach ( $notes[ $kind ] as $table => $value ) {
				if ( isset( $state['db_notes'][ $kind ][ $table ] ) ) {
					continue;
				}
				$state['db_notes'][ $kind ][ $table ] = $value;
				if ( 'engines' === $kind ) {
					$logger->log( $job->job_id, 'importing_database', 'engine', $table, 'info', sprintf( '%1$s: ENGINE=%2$s in the package was restored as InnoDB so each statement commits atomically with its resume point.%3$s', $table, $value, empty( $state['options']['restore_original_engines'] ) ? ' It stays InnoDB.' : ' It is converted back after the swap.' ) );
				} elseif ( 'collations' === $kind ) {
					$pairs = array();
					foreach ( (array) $value as $from => $to ) {
						$pairs[] = $from . ' -> ' . $to;
					}
					$logger->log( $job->job_id, 'importing_database', 'collation', $table, 'warning', sprintf( '%1$s: collation not supported by this server, mapped: %2$s', $table, implode( ', ', $pairs ) ) );
				} elseif ( 'collations_kept' === $kind ) {
					$logger->log( $job->job_id, 'importing_database', 'collation', $table, 'info', sprintf( '%1$s: collation supported, kept: %2$s', $table, implode( ', ', array_keys( (array) $value ) ) ) );
				}
			}
		}
	}

	private function verify_database( $job, array $state ) {
		$plugin   = Plugin::instance();
		$importer = $this->db_importer( $job, $state );
		$restore  = $state['plan']['restore'];
		if ( ! empty( $state['plan']['expected'] ) ) {
			$expected = array_intersect_key( $state['plan']['expected'], array_flip( $restore ) );
			$wrong    = $importer->count_mismatches( $expected );
			if ( $wrong ) {
				throw self::error( $job, 'check restored row counts', sprintf( __( 'Restored row counts do not match the package: %s', 'jisento' ), implode( '; ', array_slice( $wrong, 0, 10 ) ) ), __( 'Export the source again. Live tables were not changed.', 'jisento' ) );
			}
		}
		if ( $importer->shadow_users_empty() ) {
			throw self::error( $job, 'check restored users', __( 'The restored users table is empty, so it was not swapped in.', 'jisento' ), __( 'Export the source again and check its users table. Live tables were not changed.', 'jisento' ) );
		}
		$rewritten = $importer->rewrite_prefix_in_shadows();
		if ( is_wp_error( $rewritten ) ) {
			throw self::error( $job, 'rewrite the table prefix', $rewritten->get_error_message(), __( 'Press Retry. Live tables were not changed.', 'jisento' ) );
		}
		$state['db_phase'] = 'swap';
		$plugin->logger->log( $job->job_id, 'importing_database', 'verify', '', 'ok', 'Restored tables checked: ' . count( $restore ) . ' table(s), row counts match the manifest.' );
		return $this->report( $job, $state, array( 'stage' => 'importing_database', 'progress' => $this->import_percent( $state, 'database', 0.98 ) ), array( 'phase' => 'importing_database', 'label' => __( 'Restoring database', 'jisento' ), 'detail' => __( 'Swapping restored tables into place', 'jisento' ), 'stage_progress' => 98 ) );
	}

	private function swap_database( $job, array $state ) {
		$plugin   = Plugin::instance();
		$importer = $this->db_importer( $job, $state );
		$restore  = $state['plan']['restore'];
		if ( $restore ) {
			$swapped = $importer->swap_shadows( $restore );
			if ( is_wp_error( $swapped ) ) {
				throw self::error( $job, 'swap restored tables into place', $swapped->get_error_message(), __( 'Press Retry. The swap is a single RENAME TABLE, so either all tables or none were replaced.', 'jisento' ) );
			}
			if ( ! empty( $swapped['retired_left'] ) ) {
				$plugin->logger->log( $job->job_id, 'importing_database', 'swap', '', 'warning', 'Old tables could not be dropped (drop them manually): ' . implode( ', ', $swapped['retired_left'] ) );
			}
		}
		$state['db_swapped'] = true;
		Live_Url::hold();
		Admin_Guard::ensure( $job->job_id );
		// Saved before the follow-up work, so a crash after this point never restores into shadows again.
		$job = $this->report( $job, $state, array( 'stage' => 'importing_database' ), array( 'phase' => 'importing_database', 'label' => __( 'Restoring database', 'jisento' ), 'detail' => __( 'Checking foreign keys', 'jisento' ), 'stage_progress' => 99 ) );

		$constraints = isset( $state['db_notes']['constraints'] ) ? $state['db_notes']['constraints'] : array();
		$fk          = $importer->repair_foreign_keys( $restore, $constraints );
		if ( is_wp_error( $fk ) ) {
			throw self::error( $job, 'repair foreign keys', $fk->get_error_message(), __( 'Press Retry.', 'jisento' ) );
		}
		if ( ! empty( $fk['repaired'] ) ) {
			$plugin->logger->log( $job->job_id, 'importing_database', 'foreign-keys', '', 'info', 'Foreign keys pointed at work-table names and were recreated: ' . implode( ', ', $fk['repaired'] ) );
		}
		if ( ! empty( $state['options']['restore_original_engines'] ) && ! empty( $state['db_notes']['engines'] ) ) {
			$converted = $importer->restore_engines( $state['db_notes']['engines'] );
			if ( is_wp_error( $converted ) ) {
				throw self::error( $job, 'convert tables back to their original engine', $converted->get_error_message(), __( 'The data is restored; convert the table manually or press Retry.', 'jisento' ) );
			}
			$plugin->logger->log( $job->job_id, 'importing_database', 'engine', '', 'info', 'Converted back after the swap: ' . implode( ', ', $converted ) );
		}
		Database_Importer::forget_job( $job->job_id );
		$note = 'Database restore complete. Statements: ' . (int) ( isset( $state['db_statement_no'] ) ? $state['db_statement_no'] : 0 ) . '.';
		if ( ! empty( $state['placeholders_repaired'] ) ) {
			$note .= ' Placeholder tokens replaced with "%": ' . (int) $state['placeholders_repaired'] . '.';
		}
		$plugin->logger->log( $job->job_id, 'importing_database', 'import', '', 'ok', $note );
		$state['db_done']  = true;
		$state['db_phase'] = 'done';
		$next = ! empty( $state['validation']['files'] ) ? 'importing_files' : ( ! empty( $state['options']['replace_urls'] ) ? 'replacing_urls' : 'finalizing' );
		return $this->report(
			$job,
			$state,
			array(
				'stage'    => $next,
				'progress' => $this->import_percent( $state, 'database', 1 ),
			),
			array(
				'phase'          => 'importing_database',
				'label'          => __( 'Restoring database', 'jisento' ),
				'detail'         => __( 'Database restore complete', 'jisento' ),
				'stage_progress' => 100,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------ */

	private function import_files( $job, array $state ) {
		$plugin  = Plugin::instance();
		$options = $state['options'];
		$sizes   = isset( $state['sizes'] ) && is_array( $state['sizes'] ) ? $state['sizes'] : array();
		$next    = ! empty( $options['replace_urls'] ) && ! empty( $state['validation']['database'] ) ? 'replacing_urls' : 'finalizing';
		if ( empty( $state['validation']['files'] ) ) {
			return $this->report(
				$job,
				$state,
				array(
					'stage'    => $next,
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

		$index = isset( $state['zip_index'] ) ? (int) $state['zip_index'] : 0;
		if ( 0 === $index ) {
			$this->assert_disk_space( $job, (int) ( isset( $sizes['files'] ) ? $sizes['files'] : 0 ) );
		}
		$archive = new Archive();
		$batch   = $archive->extract_files_batch(
			$state['package_path'],
			$index,
			400,
			max( 1, Step_Budget::seconds( 12 ) ),
			function ( $relative ) use ( $state ) {
				return $this->destination_for_archive_file( $relative, $state );
			},
			isset( $state['zip_partial'] ) && is_array( $state['zip_partial'] ) ? $state['zip_partial'] : null,
			self::$extract_bytes
		);
		if ( is_wp_error( $batch ) ) {
			unset( $state['zip_partial'] );
			throw self::error( $job, 'restore files', $batch->get_error_message(), __( 'Fix the reported cause (permissions or disk space), then press Retry. Files already restored are complete; none is half-written.', 'jisento' ) );
		}

		if ( ! empty( $batch['partial'] ) ) {
			$state['zip_partial'] = $batch['partial'];
		} else {
			unset( $state['zip_partial'] );
		}
		$state['zip_index']      = (int) $batch['next'];
		$state['files_restored'] = (int) ( isset( $state['files_restored'] ) ? $state['files_restored'] : 0 ) + (int) $batch['extracted'];
		$state['file_bytes']     = (int) ( isset( $state['file_bytes'] ) ? $state['file_bytes'] : 0 ) + (int) $batch['bytes'];
		$state['skipped_files']  = (int) ( isset( $state['skipped_files'] ) ? $state['skipped_files'] : 0 ) + (int) $batch['skipped'];
		if ( ! empty( $batch['skipped_paths'] ) ) {
			$plugin->logger->log( $job->job_id, 'importing_files', 'skip', '', 'info', 'Not restored (protected, excluded, or kept by the chosen strategy): ' . implode( ', ', array_slice( $batch['skipped_paths'], 0, 20 ) ) . ( $batch['skipped'] > 20 ? ' ...' : '' ) );
		}
		$file_total  = (int) ( isset( $sizes['file_count'] ) ? $sizes['file_count'] : 0 );
		$files_bytes = (int) ( isset( $sizes['files'] ) ? $sizes['files'] : 0 );
		$done        = ! empty( $batch['done'] );
		if ( $done ) {
			$ratio = 1;
		} elseif ( $files_bytes > 0 ) {
			$ratio = min( 1, $state['file_bytes'] / $files_bytes );
		} else {
			$ratio = $file_total > 0 ? min( 1, $state['files_restored'] / $file_total ) : 0;
		}
		return $this->report(
			$job,
			$state,
			array(
				'stage'    => $done ? $next : 'importing_files',
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

	/**
	 * @param string $relative Archive path below files/.
	 * @param array  $state    Job state.
	 * @return string|\WP_Error Destination, '' to skip on purpose, or an error that fails the job.
	 */
	public function destination_for_archive_file( $relative, array $state ) {
		$options  = $state['options'];
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		$core     = 0 === strpos( $relative, 'wp-admin/' ) || 0 === strpos( $relative, 'wp-includes/' );
		if ( $core ) {
			if ( empty( $state['manifest']['include_core'] ) ) {
				if ( ! empty( $state['legacy'] ) ) {
					return '';
				}
				return new \WP_Error( 'jisento_path', sprintf( __( 'The package contains WordPress core file %s but was not exported with core files. It was rejected.', 'jisento' ), $relative ) );
			}
		}
		$content_rel = 0 === strpos( $relative, 'wp-content/' ) ? substr( $relative, strlen( 'wp-content/' ) ) : ( $core ? '' : $relative );
		if ( ! $core && self::is_protected_content_path( $content_rel, isset( $state['jisento_copies'] ) ? $state['jisento_copies'] : array() ) ) {
			return '';
		}
		$base = strtolower( basename( $relative ) );
		if ( in_array( $base, self::SKIP_BASENAMES, true ) ) {
			return '';
		}
		$dest = $core ? rtrim( ABSPATH, '/\\' ) . '/' . $relative : rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . $content_rel;
		$root = $core ? rtrim( ABSPATH, '/\\' ) : rtrim( WP_CONTENT_DIR, '/\\' );
		$safe = self::contained( $dest, $root );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		$normalized = str_replace( '\\', '/', $dest );
		$plugin_dir = rtrim( str_replace( '\\', '/', JISENTO_PATH ), '/' );
		$storage    = rtrim( str_replace( '\\', '/', Plugin::instance()->storage->root() ), '/' );
		foreach ( array( $plugin_dir, $storage ) as $own ) {
			if ( $normalized === $own || 0 === strpos( $normalized, $own . '/' ) ) {
				return '';
			}
		}
		if ( $this->should_skip_file( $relative, $dest, $options ) ) {
			return '';
		}
		return $dest;
	}

	/**
	 * Paths under wp-content that describe this install or this server and are never restored.
	 *
	 * @param string   $content_rel Path relative to wp-content.
	 * @param string[] $copies      Archive prefixes of Jisento plugin copies in the package.
	 * @return bool
	 */
	public static function is_protected_content_path( $content_rel, array $copies = array() ) {
		$content_rel = ltrim( (string) $content_rel, '/' );
		$lower       = strtolower( $content_rel );
		if ( in_array( $lower, self::SKIP_DROPINS, true ) ) {
			return true;
		}
		foreach ( File_System::own_runtime_files() as $own ) {
			if ( $lower === strtolower( $own ) ) {
				return true;
			}
		}
		if ( 0 === strpos( $lower, 'jisento/' ) || 'jisento' === $lower ) {
			return true;
		}
		foreach ( $copies as $prefix ) {
			$prefix = preg_replace( '#^wp-content/#', '', (string) $prefix );
			if ( '' !== $prefix && 0 === strpos( $content_rel, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The nearest existing parent of $dest, resolved with realpath, must be inside $root.
	 * A symlinked folder that points elsewhere is rejected.
	 *
	 * @param string $dest Destination file.
	 * @param string $root Allowed root.
	 * @return true|\WP_Error
	 */
	public static function contained( $dest, $root ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return new \WP_Error( 'jisento_path', sprintf( __( 'The restore root %s does not exist.', 'jisento' ), $root ) );
		}
		$root_real = rtrim( str_replace( '\\', '/', $root_real ), '/' );
		$parent    = dirname( $dest );
		while ( ! is_dir( $parent ) ) {
			$up = dirname( $parent );
			if ( $up === $parent ) {
				break;
			}
			$parent = $up;
		}
		$real = realpath( $parent );
		$real = false === $real ? '' : rtrim( str_replace( '\\', '/', $real ), '/' );
		if ( '' === $real || ( $real !== $root_real && 0 !== strpos( $real . '/', $root_real . '/' ) ) ) {
			return new \WP_Error( 'jisento_path', sprintf( __( 'Restoring %1$s would write outside %2$s (through a symlink or unusual path), so the restore stopped.', 'jisento' ), $dest, $root_real ) );
		}
		if ( is_link( $dest ) ) {
			return new \WP_Error( 'jisento_path', sprintf( __( '%s is a symlink; restoring over it could write outside the site, so the restore stopped.', 'jisento' ), $dest ) );
		}
		return true;
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
			throw self::error(
				$job,
				'check disk space',
				sprintf( __( 'This step needs about %1$s and %2$s is free.', 'jisento' ), size_format( $need ), size_format( $free ) ),
				__( 'Free disk space (old backups, caches), then press Retry.', 'jisento' )
			);
		}
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
			if ( ( 'keep_destination' === $strategy || 'install_missing' === $strategy ) && $exists ) {
				return true;
			}
		}

		if ( $is_theme && $theme_slug ) {
			$strategy = $this->theme_strategy( $theme_slug, $options );
			$exists   = is_dir( get_theme_root() . '/' . $theme_slug );
			if ( 'skip' === $strategy ) {
				return true;
			}
			if ( ( 'keep_destination' === $strategy || 'install_missing' === $strategy ) && $exists ) {
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

	/* ------------------------------------------------------------------
	 * URLs and finalize
	 * ------------------------------------------------------------------ */

	private function replace_urls( $job, array $state ) {
		$plugin   = Plugin::instance();
		$source   = $state['options']['source_url'] ? $state['options']['source_url'] : $state['manifest']['home_url'];
		$dest     = $state['options']['dest_url'] ? $state['options']['dest_url'] : home_url();
		$replacer = new Url_Replacer();
		$prior    = isset( $state['url_state'] ) ? $state['url_state'] : array();
		// Only tables restored from the package: kept tables already hold this site's own URLs.
		$result   = $replacer->replace_all(
			$source,
			$dest,
			max( 1, Step_Budget::seconds( 8 ) ),
			$prior,
			array(
				'only_tables'    => isset( $state['plan']['restore'] ) ? $state['plan']['restore'] : array(),
				'replace_guids'  => ! empty( $state['options']['replace_guids'] ),
				'replace_emails' => ! isset( $state['options']['replace_emails'] ) || ! empty( $state['options']['replace_emails'] ),
			)
		);
		$state['url_state'] = $result;

		$table_total = ( isset( $result['tables'] ) && is_array( $result['tables'] ) ) ? count( $result['tables'] ) : 0;
		$table_index = isset( $result['index'] ) ? (int) $result['index'] : 0;
		$ratio       = ! empty( $result['done'] ) ? 1 : ( $table_total > 0 ? min( 1, $table_index / $table_total ) : 0 );
		if ( ! empty( $result['done'] ) ) {
			Live_Url::adopt( $dest );
			Live_Url::hold();
			$note = 'URL replacement: ' . (int) $result['updated'] . ' row(s) updated.';
			if ( ! empty( $result['emails_updated'] ) ) {
				$note .= ' Email addresses updated: ' . (int) $result['emails_updated'] . '.';
			}
			if ( ! empty( $result['skipped_values'] ) ) {
				$note .= ' ' . (int) $result['skipped_values'] . ' value(s) left unchanged because they could not be rewritten safely (unknown serialized data).';
			}
			$plugin->logger->log( $job->job_id, 'replacing_urls', 'replace', '', 'ok', $note );
		}
		$current = '';
		if ( ! empty( $result['table'] ) ) {
			$current = is_array( $result['table'] ) ? ( isset( $result['table']['name'] ) ? (string) $result['table']['name'] : '' ) : (string) $result['table'];
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
				'detail'         => '' !== $current ? $current : __( 'Updating stored addresses', 'jisento' ),
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
		$report = array(
			'source'         => isset( $state['manifest']['home_url'] ) ? $state['manifest']['home_url'] : '',
			'destination'    => home_url(),
			'started'        => $job->created_at,
			'completed'      => current_time( 'mysql' ),
			'database'       => ! empty( $state['db_done'] ),
			'files'          => ! empty( $state['validation']['files'] ),
			'urls'           => ! empty( $state['options']['replace_urls'] ),
			'warnings'       => array(),
			'errors'         => 0,
			'mode'           => $state['options']['destination_mode'],
			'tables_restored'=> isset( $state['plan']['restore'] ) ? count( $state['plan']['restore'] ) : 0,
			'tables_kept'    => isset( $state['plan']['keep'] ) ? count( $state['plan']['keep'] ) : 0,
			'users_replaced' => ! empty( $state['users_replaced'] ),
			'login_notice'   => ! empty( $state['users_replaced'] ) ? __( "Log in with the SOURCE site's username and password.", 'jisento' ) : '',
			'timings'        => isset( $state['timings'] ) ? $state['timings'] : array(),
		);
		$cleanup->verify( $report );
		if ( ! empty( $report['errors'] ) ) {
			throw self::error( $job, 'verify the migrated site', implode( ' ', $report['warnings'] ), __( 'Resolve the reported problems, then press Retry.', 'jisento' ) );
		}
		$report['total_seconds'] = 0;
		if ( ! empty( $state['timings'] ) && is_array( $state['timings'] ) ) {
			$report['total_seconds'] = (int) round( array_sum( $state['timings'] ) );
		}
		Live_Url::hold();
		Admin_Guard::ensure( $job->job_id );
		$state['report'] = $report;
		$plugin->logger->log( $job->job_id, 'finalizing', 'complete', '', 'ok', 'Migration completed' );

		return $plugin->jobs->update(
			$job,
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
