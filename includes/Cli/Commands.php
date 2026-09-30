<?php
/**
 * WP-CLI commands: export, import, resume, status.
 * Runs the same Job_Runner::step loop without an HTTP time limit.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Cli;

use Jisento\Migration\Export\Exporter;
use Jisento\Migration\Import\Importer;
use Jisento\Migration\Jobs\Job_Runner;
use Jisento\Migration\Jobs\Step_Budget;
use Jisento\Migration\Plugin;
use Jisento\Migration\Security\Job_Continuation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Commands {

	public static function register() {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'jisento', __CLASS__ );
	}

	/**
	 * Export a .jisento package.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : full, database, or files.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - database
	 *   - files
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function export( $args, $assoc_args ) {
		$mode = isset( $assoc_args['mode'] ) ? sanitize_key( $assoc_args['mode'] ) : 'full';
		if ( ! in_array( $mode, array( 'full', 'database', 'files' ), true ) ) {
			\WP_CLI::error( 'Mode must be full, database, or files.' );
		}
		$job = ( new Exporter() )->start( array( 'mode' => $mode ) );
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}
		$secret = Job_Continuation::issue( $job->job_id );
		if ( '' === $secret ) {
			Job_Runner::fail( $job, sprintf( 'Stage: start. Operation: store the job token. Reason: the token file could not be written. Recovery: make the Jisento jobs folder writable. Job: %s', $job->job_id ) );
			\WP_CLI::error( 'Could not store the job continuation token.' );
		}
		$state                = is_array( $job->state ) ? $job->state : array();
		$state['worker_mode'] = 'cli';
		$state['loopback_ok'] = true;
		$job                  = Plugin::instance()->jobs->update( $job, array( 'state' => $state ) );
		\WP_CLI::log( 'Export job ' . $job->job_id . ' started (mode=' . $mode . ').' );
		$this->run_until_done( $job->job_id );
	}

	/**
	 * Import a package from local storage.
	 *
	 * ## OPTIONS
	 *
	 * --package=<key>
	 * : Package storage key or filename (e.g. packages/site.jisento or site.jisento).
	 *
	 * [--mode=<mode>]
	 * : replace or preserve.
	 * ---
	 * default: preserve
	 * options:
	 *   - replace
	 *   - preserve
	 * ---
	 *
	 * [--confirm-replace]
	 * : Required when --mode=replace.
	 *
	 * [--confirm-preserve]
	 * : Required when --mode=preserve (keeps this site's logins, themes and plugins).
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $assoc_args['package'] ) ) {
			\WP_CLI::error( 'Missing --package=<key>.' );
		}
		$mode = isset( $assoc_args['mode'] ) ? sanitize_key( $assoc_args['mode'] ) : 'preserve';
		if ( ! in_array( $mode, array( 'replace', 'preserve' ), true ) ) {
			\WP_CLI::error( 'Mode must be replace or preserve.' );
		}
		if ( 'replace' === $mode && empty( $assoc_args['confirm-replace'] ) ) {
			\WP_CLI::error( 'Replace mode requires --confirm-replace.' );
		}
		if ( 'preserve' === $mode && empty( $assoc_args['confirm-preserve'] ) ) {
			\WP_CLI::error( 'Preserve mode requires --confirm-preserve.' );
		}
		$package = (string) $assoc_args['package'];
		if ( 0 !== strpos( $package, 'packages/' ) ) {
			$package = 'packages/' . ltrim( $package, '/' );
		}
		$job = ( new Importer() )->start(
			array(
				'package'           => $package,
				'destination_mode'  => $mode,
				'confirm_replace'   => ! empty( $assoc_args['confirm-replace'] ),
				'confirm_preserve'  => ! empty( $assoc_args['confirm-preserve'] ),
			)
		);
		if ( is_wp_error( $job ) ) {
			\WP_CLI::error( $job->get_error_message() );
		}
		$secret = Job_Continuation::issue( $job->job_id );
		if ( '' === $secret ) {
			Job_Runner::fail( $job, sprintf( 'Stage: start. Operation: store the job token. Reason: the token file could not be written. Recovery: make the Jisento jobs folder writable. Job: %s', $job->job_id ) );
			\WP_CLI::error( 'Could not store the job continuation token.' );
		}
		$state                = is_array( $job->state ) ? $job->state : array();
		$state['worker_mode'] = 'cli';
		$state['loopback_ok'] = true;
		$job                  = Plugin::instance()->jobs->update( $job, array( 'state' => $state ) );
		\WP_CLI::log( 'Import job ' . $job->job_id . ' started (mode=' . $mode . ', package=' . $package . ').' );
		$this->run_until_done( $job->job_id );
	}

	/**
	 * Resume a paused or stalled running job (or retry a failed import via resume path for paused).
	 *
	 * ## OPTIONS
	 *
	 * --job=<id>
	 * : Job id.
	 *
	 * [--skip-themes]
	 * : Keep the destination theme active when the pin is released (do not activate the imported theme).
	 *   Use this when the imported theme fatals because its files are incomplete, e.g. after a crash mid-file-restore.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function resume( $args, $assoc_args ) {
		if ( empty( $assoc_args['job'] ) ) {
			\WP_CLI::error( 'Missing --job=<id>.' );
		}
		$job_id = (string) $assoc_args['job'];
		$plugin = Plugin::instance();
		$job    = $plugin->jobs->get( $job_id );
		if ( ! $job ) {
			\WP_CLI::error( 'Job not found.' );
		}
		if ( 'paused' === $job->status ) {
			$job = Job_Runner::resume( $job_id );
			if ( is_wp_error( $job ) ) {
				\WP_CLI::error( $job->get_error_message() );
			}
		} elseif ( 'failed' === $job->status && 'import' === $job->type ) {
			$job = Job_Runner::retry( $job_id );
			if ( is_wp_error( $job ) ) {
				\WP_CLI::error( $job->get_error_message() );
			}
		} elseif ( 'running' !== $job->status ) {
			\WP_CLI::error( 'Job status is ' . $job->status . '; nothing to resume.' );
		}
		if ( ! empty( $assoc_args['skip-themes'] ) ) {
			\Jisento\Migration\Core\Live_Url::skip_themes( $job_id );
			\WP_CLI::log( 'Will keep the destination theme (--skip-themes).' );
		}
		$state                = is_array( $job->state ) ? $job->state : array();
		$state['worker_mode'] = 'cli';
		$job                  = $plugin->jobs->update( $job, array( 'state' => $state ) );
		\WP_CLI::log( 'Resuming job ' . $job_id . '.' );
		$this->run_until_done( $job_id );
	}

	/**
	 * Show job status.
	 *
	 * ## OPTIONS
	 *
	 * [--job=<id>]
	 * : Specific job id. Without it, lists recent jobs.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$plugin = Plugin::instance();
		if ( ! empty( $assoc_args['job'] ) ) {
			$job = $plugin->jobs->get( (string) $assoc_args['job'] );
			if ( ! $job ) {
				\WP_CLI::error( 'Job not found.' );
			}
			$this->print_job( $job );
			return;
		}
		$rows = $plugin->jobs->list_recent( 15 );
		if ( ! $rows ) {
			\WP_CLI::log( 'No jobs.' );
			return;
		}
		foreach ( $rows as $row ) {
			\WP_CLI::log(
				sprintf(
					'%s  %-8s  %-10s  %-20s  %3d%%  %s',
					$row->job_id,
					$row->type,
					$row->status,
					$row->stage,
					(int) $row->progress,
					$row->updated_at
				)
			);
		}
	}

	/**
	 * @param string $job_id Job id.
	 */
	private function run_until_done( $job_id ) {
		$plugin = Plugin::instance();
		$busy_waits = 0;
		while ( true ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}
			ignore_user_abort( true );
			// CLI has no HTTP boot cost; still bound each step so progress is printed.
			$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
			Step_Budget::begin( 30 );

			$ran = Job_Runner::step( $job_id );
			if ( $ran['error'] ) {
				\WP_CLI::error( $ran['error']->get_error_message() );
			}
			$job = $ran['job'] ? $ran['job'] : $plugin->jobs->get( $job_id );
			if ( ! $job ) {
				\WP_CLI::error( 'Job disappeared.' );
			}
			$this->print_progress_line( $job );
			if ( in_array( $job->status, Job_Runner::TERMINAL, true ) ) {
				if ( 'completed' === $job->status ) {
					\WP_CLI::success( 'Job ' . $job_id . ' completed.' );
					return;
				}
				\WP_CLI::error( $job->error_summary ? $job->error_summary : ( 'Job ended with status ' . $job->status ) );
			}
			if ( ! empty( $ran['busy'] ) ) {
				$busy_waits++;
				if ( $busy_waits > 60 ) {
					\WP_CLI::error( 'Job lock stayed busy for too long. Job: ' . $job_id );
				}
				usleep( 250000 );
				continue;
			}
			$busy_waits = 0;
		}
	}

	/**
	 * @param object $job Job.
	 */
	private function print_job( $job ) {
		$payload = Plugin::instance()->jobs->to_response( $job );
		\WP_CLI::log( 'job_id:      ' . $payload['job_id'] );
		\WP_CLI::log( 'type:        ' . $payload['type'] );
		\WP_CLI::log( 'status:      ' . $payload['status'] );
		\WP_CLI::log( 'stage:       ' . $payload['stage'] );
		\WP_CLI::log( 'progress:    ' . $payload['progress'] . '%' );
		\WP_CLI::log( 'worker_mode: ' . $payload['worker_mode'] );
		\WP_CLI::log( 'stalled:     ' . ( ! empty( $payload['stalled'] ) ? 'yes' : 'no' ) );
		\WP_CLI::log( 'updated_at:  ' . $payload['updated_at'] );
		if ( ! empty( $payload['error_summary'] ) ) {
			\WP_CLI::log( 'error:       ' . $payload['error_summary'] );
		}
		if ( ! empty( $payload['package_name'] ) ) {
			\WP_CLI::log( 'package:     ' . $payload['package_name'] );
		}
		$act = isset( $payload['state']['activity'] ) ? $payload['state']['activity'] : array();
		if ( ! empty( $act['label'] ) ) {
			\WP_CLI::log( 'activity:    ' . $act['label'] . ( ! empty( $act['detail'] ) ? ' — ' . $act['detail'] : '' ) );
		}
	}

	/**
	 * @param object $job Job.
	 */
	private function print_progress_line( $job ) {
		$act    = isset( $job->state['activity'] ) && is_array( $job->state['activity'] ) ? $job->state['activity'] : array();
		$label  = isset( $act['label'] ) ? $act['label'] : $job->stage;
		$detail = isset( $act['detail'] ) ? $act['detail'] : $job->current_item;
		\WP_CLI::log(
			sprintf(
				'[%3d%%] %s — %s%s',
				(int) $job->progress,
				$job->stage,
				$label,
				$detail ? ' (' . $detail . ')' : ''
			)
		);
	}
}
