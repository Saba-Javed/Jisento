<?php
/**
 * The only way a job is created, stepped, paused, resumed, retried or cancelled.
 *
 * - A job holds the site lease (Core\Lease) for its whole life, so only one export, import or
 *   receive job changes this site at a time.
 * - Each step also holds the per-job GET_LOCK, and re-reads the job after taking it, so two
 *   requests can never run the same step on the same state.
 * - Job_Store::update is version-checked; a step whose job changed underneath it is discarded.
 * - A failed job stays failed. Only retry() moves it back to running, after re-validation.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

use Jisento\Migration\Core\Lease;
use Jisento\Migration\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Runner {

	const TERMINAL = array( 'completed', 'failed', 'cancelled' );

	/**
	 * @var callable|null Test hook: function( string $type ) returning an object with step( $job ).
	 */
	public static $worker_factory = null;

	/**
	 * @return \WP_Error|null
	 */
	public static function multisite_error() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return new \WP_Error(
				'jisento_multisite',
				__( 'Stage: start. Operation: start an export or import. Reason: this is a WordPress multisite network, and Jisento Migration only supports single sites; running it here could overwrite every site in the network. Recovery: use a multisite-aware migration tool.', 'jisento' ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * Create a job and take the site lease for it. The job row is removed again when the lease
	 * is held by someone else, so a refused start leaves nothing behind.
	 *
	 * @param string $type  export|import|receive.
	 * @param array  $state Initial state.
	 * @return object|\WP_Error Job with status "created".
	 */
	public static function open( $type, array $state ) {
		$blocked = self::multisite_error();
		if ( $blocked ) {
			return $blocked;
		}
		$plugin = Plugin::instance();
		$holder = Lease::holder();
		if ( '' !== $holder ) {
			return self::busy_error( $holder, '' );
		}
		$job   = $plugin->jobs->create( $type, $state );
		$lease = Lease::acquire( $job->job_id );
		if ( is_wp_error( $lease ) ) {
			$plugin->jobs->delete( $job->job_id );
			return $lease;
		}
		return $job;
	}

	private static function busy_error( $holder, $job_id ) {
		return new \WP_Error(
			'jisento_busy',
			sprintf(
				/* translators: 1: running job id, 2: job id */
				__( 'Stage: start. Operation: start a job. Reason: an import or export is already running on this site (job %1$s), and only one can run at a time. Recovery: wait for it to finish or cancel it, then try again. Job: %2$s', 'jisento' ),
				$holder,
				'' !== $job_id ? $job_id : '-'
			),
			array(
				'status' => 409,
				'owner'  => $holder,
			)
		);
	}

	/**
	 * Run one step.
	 *
	 * @param string        $job_id Job id.
	 * @param callable|null $worker function( $job ) returning the updated job. Default: by job type.
	 * @return array{job:object|null,busy:bool,error:\WP_Error|null}
	 */
	public static function step( $job_id, $worker = null ) {
		$plugin = Plugin::instance();
		$job    = $plugin->jobs->get( $job_id );
		if ( ! $job ) {
			return self::result( null, false, new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) ) );
		}
		if ( 'running' !== $job->status ) {
			// Failed jobs are never restarted here; that needs an explicit retry().
			return self::result( $job, false );
		}
		if ( ! Lease::lock_job( $job->job_id ) ) {
			return self::result( $job, true );
		}
		try {
			$job = $plugin->jobs->get( $job_id );
			if ( ! $job || 'running' !== $job->status ) {
				return self::result( $job, false );
			}
			$lease = Lease::acquire( $job->job_id );
			if ( is_wp_error( $lease ) ) {
				$data = $lease->get_error_data();
				if ( is_array( $data ) && isset( $data['status'] ) && 409 === (int) $data['status'] ) {
					$job = self::fail(
						$job,
						sprintf(
							/* translators: 1: other job, 2: job id */
							__( 'Stage: %1$s. Operation: renew the site lease. Reason: this job stopped sending heartbeats for more than two minutes and job %2$s took over the site. Recovery: let the other job finish, then start this one again. Job: %3$s', 'jisento' ),
							$job->stage,
							isset( $data['owner'] ) ? $data['owner'] : '-',
							$job->job_id
						),
						false
					);
					return self::result( $job, false );
				}
				return self::result( $job, false, $lease );
			}
			if ( null === $worker ) {
				$worker = self::default_worker( $job );
			}
			try {
				$job = call_user_func( $worker, $job );
			} catch ( Job_Conflict $e ) {
				$job = $plugin->jobs->get( $job_id );
				return self::result( $job, false );
			} catch ( \Throwable $e ) {
				$current = $plugin->jobs->get( $job_id );
				$job     = $current ? self::fail( $current, self::message_from( $current, $e ) ) : null;
				return self::result( $job, false );
			}
			if ( $job && in_array( $job->status, self::TERMINAL, true ) ) {
				self::finish( $job );
			}
			return self::result( $job, false );
		} finally {
			Lease::unlock_job( $job_id );
		}
	}

	private static function default_worker( $job ) {
		if ( is_callable( self::$worker_factory ) ) {
			$made = call_user_func( self::$worker_factory, $job->type );
			return array( $made, 'step' );
		}
		if ( 'export' === $job->type ) {
			return array( new \Jisento\Migration\Export\Exporter(), 'step' );
		}
		return array( new \Jisento\Migration\Import\Importer(), 'step' );
	}

	private static function result( $job, $busy, $error = null ) {
		return array(
			'job'   => $job,
			'busy'  => (bool) $busy,
			'error' => $error,
		);
	}

	private static function message_from( $job, \Throwable $e ) {
		$message = trim( (string) $e->getMessage() );
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %s: PHP exception class */
				__( 'The worker stopped with %s but PHP supplied no error message. Recovery: check the server PHP error log, then press Retry.', 'jisento' ),
				get_class( $e )
			);
		}
		if ( false === strpos( $message, 'Stage:' ) ) {
			$message = 'Stage: ' . $job->stage . '. ' . $message;
		}
		if ( false === strpos( $message, 'Job: ' ) ) {
			$message .= ' Job: ' . $job->job_id;
		}
		return $message;
	}

	/**
	 * Mark a job failed (version-checked), log it and run the terminal cleanup.
	 *
	 * @param object $job     Current job.
	 * @param string $message Error summary.
	 * @param bool   $cleanup Run finish().
	 * @return object|null
	 */
	public static function fail( $job, $message, $cleanup = true ) {
		$plugin  = Plugin::instance();
		$message = \Jisento\Migration\Core\Logger::redact( (string) $message );
		$plugin->logger->log( $job->job_id, $job->stage, 'error', '', 'error', $message );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$job = $plugin->jobs->update(
					$job,
					array(
						'status'        => 'failed',
						'error_summary' => $message,
					)
				);
				break;
			} catch ( Job_Conflict $e ) {
				$job = $plugin->jobs->get( $job->job_id );
				if ( ! $job || in_array( $job->status, self::TERMINAL, true ) ) {
					break;
				}
			}
		}
		if ( $job && $cleanup ) {
			self::finish( $job );
		}
		return $job;
	}

	/**
	 * Cleanup for a job that reached completed, failed or cancelled.
	 *
	 * @param object $job Job.
	 */
	public static function finish( $job ) {
		if ( in_array( $job->type, array( 'import', 'receive' ), true ) ) {
			\Jisento\Migration\Import\Importer::cleanup( $job );
		} elseif ( 'export' === $job->type ) {
			\Jisento\Migration\Export\Exporter::cleanup( $job );
		}
		Lease::release( $job->job_id );
	}

	/**
	 * Cancel. Waits for a running step to end so cleanup never races with the worker.
	 *
	 * @param string $job_id Job id.
	 * @return object|\WP_Error
	 */
	public static function cancel( $job_id ) {
		$plugin = Plugin::instance();
		if ( ! Lease::lock_job( $job_id, 25 ) ) {
			return new \WP_Error( 'jisento_busy', sprintf( __( 'Stage: cancel. Operation: stop the job. Reason: a step is still running and did not finish within 25 seconds. Recovery: press Cancel again in a moment. Job: %s', 'jisento' ), $job_id ), array( 'status' => 409 ) );
		}
		try {
			$job = $plugin->jobs->get( $job_id );
			if ( ! $job ) {
				return new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) );
			}
			if ( 'completed' === $job->status || 'cancelled' === $job->status ) {
				return $job;
			}
			$job = $plugin->jobs->update(
				$job,
				array(
					'status'        => 'cancelled',
					'stage'         => 'cancelled',
					'error_summary' => __( 'Cancelled by administrator.', 'jisento' ),
				)
			);
			$plugin->logger->log( $job_id, 'cancelled', 'cancel', '', 'info', 'Cancelled by administrator' );
			self::finish( $job );
			return $job;
		} finally {
			Lease::unlock_job( $job_id );
		}
	}

	/**
	 * @param string $job_id Job id.
	 * @return object|\WP_Error
	 */
	public static function pause( $job_id ) {
		$plugin = Plugin::instance();
		if ( ! Lease::lock_job( $job_id, 25 ) ) {
			return new \WP_Error( 'jisento_busy', sprintf( __( 'Stage: pause. Operation: pause the job. Reason: a step is still running. Recovery: press Pause again in a moment. Job: %s', 'jisento' ), $job_id ), array( 'status' => 409 ) );
		}
		try {
			$job = $plugin->jobs->get( $job_id );
			if ( ! $job ) {
				return new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) );
			}
			if ( 'running' !== $job->status ) {
				return $job;
			}
			return $plugin->jobs->update( $job, array( 'status' => 'paused' ) );
		} finally {
			Lease::unlock_job( $job_id );
		}
	}

	/**
	 * Resume a paused job. Failed jobs need retry().
	 *
	 * @param string $job_id Job id.
	 * @return object|\WP_Error
	 */
	public static function resume( $job_id ) {
		$plugin = Plugin::instance();
		$job    = $plugin->jobs->get( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) );
		}
		if ( 'paused' !== $job->status ) {
			return $job;
		}
		$lease = Lease::acquire( $job_id );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		return $plugin->jobs->update( $job, array( 'status' => 'running' ) );
	}

	/**
	 * Explicit retry of a failed job: take the lease again, then let the job type re-validate
	 * its state and choose a safe restart point.
	 *
	 * @param string $job_id Job id.
	 * @return object|\WP_Error
	 */
	public static function retry( $job_id ) {
		$plugin = Plugin::instance();
		$blocked = self::multisite_error();
		if ( $blocked ) {
			return $blocked;
		}
		if ( ! Lease::lock_job( $job_id ) ) {
			return new \WP_Error( 'jisento_busy', sprintf( __( 'Stage: retry. Operation: restart the job. Reason: another request is working on it. Recovery: wait a moment and reload. Job: %s', 'jisento' ), $job_id ), array( 'status' => 409 ) );
		}
		try {
			$job = $plugin->jobs->get( $job_id );
			if ( ! $job ) {
				return new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) );
			}
			if ( 'failed' !== $job->status ) {
				return new \WP_Error( 'jisento_retry', sprintf( __( 'Stage: retry. Operation: restart the job. Reason: only a failed job can be retried; this one is %1$s. Recovery: none needed. Job: %2$s', 'jisento' ), $job->status, $job_id ), array( 'status' => 409 ) );
			}
			if ( 'import' !== $job->type ) {
				return new \WP_Error( 'jisento_retry', sprintf( __( 'Stage: retry. Operation: restart the job. Reason: a failed %1$s cannot be resumed safely. Recovery: start a new one. Job: %2$s', 'jisento' ), $job->type, $job_id ), array( 'status' => 409 ) );
			}
			$lease = Lease::acquire( $job_id );
			if ( is_wp_error( $lease ) ) {
				return $lease;
			}
			try {
				$fields = \Jisento\Migration\Import\Importer::retry_fields( $job );
			} catch ( \Throwable $e ) {
				Lease::release( $job_id );
				return new \WP_Error( 'jisento_retry', self::message_from( $job, $e ), array( 'status' => 409 ) );
			}
			$fields['status']        = 'running';
			$fields['error_summary'] = '';
			$job = $plugin->jobs->update( $job, $fields );
			$plugin->logger->log( $job_id, $job->stage, 'retry', '', 'info', 'Retry requested by administrator; restarting at ' . $job->stage );
			\Jisento\Migration\Import\Importer::arm( $job );
			return $job;
		} finally {
			Lease::unlock_job( $job_id );
		}
	}
}
