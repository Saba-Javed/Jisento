<?php
/**
 * Server-side job stepping: loopback HTTP, cron fallback, and loopback capability probe.
 *
 * Loopback steps authenticate with a short-lived dispatch HMAC (not the browser continuation
 * token and not a stored raw secret). Duplicate dispatches are safe: Job_Runner returns busy.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

use Jisento\Migration\Core\Lease;
use Jisento\Migration\Plugin;
use Jisento\Migration\Security\Job_Continuation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Scheduler {

	const PROBE_WAIT_SECONDS = 4;
	const STALE_STEP_SECONDS = 60;
	const KICK_AFTER_SECONDS = 30;
	const STALLED_SECONDS    = 120;

	/**
	 * @var callable|null Test hook: function( string $url, array $args ): mixed
	 */
	public static $http_post = null;

	/**
	 * Probe loopback, set worker_mode on the job, and return the updated job.
	 *
	 * @param object $job Job that already has a continuation record.
	 * @return object
	 */
	public static function prepare_worker( $job ) {
		$plugin = Plugin::instance();
		$ok     = self::probe_loopback();
		$state  = is_array( $job->state ) ? $job->state : array();
		$state['loopback_ok'] = $ok;
		$state['worker_mode'] = $ok ? 'server' : 'browser';
		try {
			$job = $plugin->jobs->update( $job, array( 'state' => $state ) );
		} catch ( Job_Conflict $e ) {
			$fresh = $plugin->jobs->get( $job->job_id );
			return $fresh ? $fresh : $job;
		}
		$plugin->logger->log(
			$job->job_id,
			'start',
			'loopback',
			'',
			'info',
			$ok
				? 'Loopback probe succeeded; steps will run on the server.'
				: 'Loopback probe failed; this browser must keep the tab open to drive steps.'
		);
		return $job;
	}

	/**
	 * Fire a non-blocking step request for a running job (server worker mode).
	 *
	 * @param string $job_id Job id.
	 * @return bool True when a request was handed to HTTP (not proof it ran).
	 */
	public static function dispatch_step( $job_id ) {
		$job_id = (string) $job_id;
		if ( ! preg_match( '/^[A-Za-z0-9_]{8,64}$/', $job_id ) ) {
			return false;
		}
		$token = Job_Continuation::issue_dispatch( $job_id );
		if ( '' === $token ) {
			return false;
		}
		$url  = self::rest_url( 'jobs/' . rawurlencode( $job_id ) );
		$args = array(
			'method'      => 'POST',
			'timeout'     => 1,
			'blocking'    => false,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', true ),
			'headers'     => array(
				'Content-Type'        => 'application/json',
				'Cache-Control'       => 'no-cache, no-store',
				'X-Jisento-Dispatch'  => $token,
			),
			'body'        => '{}',
		);
		$response = self::http_post( $url, $args );
		return ! is_wp_error( $response );
	}

	/**
	 * Dispatch when the job is running and configured for server workers.
	 *
	 * @param object|null $job Job.
	 * @return bool
	 */
	public static function maybe_dispatch( $job ) {
		if ( ! $job || empty( $job->job_id ) || 'running' !== $job->status ) {
			return false;
		}
		$state = is_array( $job->state ) ? $job->state : array();
		$mode  = isset( $state['worker_mode'] ) ? (string) $state['worker_mode'] : 'server';
		if ( 'browser' === $mode ) {
			return false;
		}
		return self::dispatch_step( $job->job_id );
	}

	/**
	 * Status-poll kick: if running, last update older than 30 s, and the job lock is free,
	 * dispatch one step. Holding the lock only long enough to see it is free.
	 *
	 * @param object $job Job.
	 * @return bool
	 */
	public static function kick_if_stale( $job ) {
		if ( ! $job || 'running' !== $job->status ) {
			return false;
		}
		$age = self::age_seconds( isset( $job->updated_at ) ? $job->updated_at : '' );
		if ( $age < self::KICK_AFTER_SECONDS ) {
			return false;
		}
		if ( ! Lease::lock_job( $job->job_id, 0 ) ) {
			return false;
		}
		Lease::unlock_job( $job->job_id );
		return self::maybe_dispatch( $job );
	}

	/**
	 * Cron: step (via dispatch) any running job whose last update is older than $seconds.
	 *
	 * @param int $seconds Stale threshold.
	 */
	public static function tick_stale( $seconds = null ) {
		$seconds = null === $seconds ? self::STALE_STEP_SECONDS : max( 15, (int) $seconds );
		$plugin  = Plugin::instance();
		$jobs    = $plugin->jobs->list_running();
		foreach ( $jobs as $row ) {
			$job = $plugin->jobs->get( $row->job_id );
			if ( ! $job || 'running' !== $job->status ) {
				continue;
			}
			$age = self::age_seconds( $job->updated_at );
			if ( $age < $seconds ) {
				continue;
			}
			self::maybe_dispatch( $job );
		}
	}

	/**
	 * @param object $job Job.
	 * @return bool
	 */
	public static function is_stalled( $job ) {
		if ( ! $job || 'running' !== $job->status ) {
			return false;
		}
		return self::age_seconds( isset( $job->updated_at ) ? $job->updated_at : '' ) >= self::STALLED_SECONDS;
	}

	/**
	 * REST URL via site_url(?rest_route=...) so pretty permalinks are not required.
	 *
	 * @param string $path Path under jisento/v1 (no leading slash), e.g. "jobs/abc" or "loopback-ping".
	 * @return string
	 */
	public static function rest_url( $path ) {
		$path = ltrim( (string) $path, '/' );
		$route = '/jisento/v1/' . $path;
		return add_query_arg( 'rest_route', $route, site_url( '/' ) );
	}

	/**
	 * Prove a loopback request reached PHP by writing and observing a marker file.
	 *
	 * @return bool
	 */
	public static function probe_loopback() {
		try {
			$nonce = bin2hex( random_bytes( 12 ) );
		} catch ( \Exception $e ) {
			return false;
		}
		$dir = self::probe_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$expect = $dir . '/' . $nonce . '.expect';
		$marker = $dir . '/' . $nonce . '.ok';
		if ( false === file_put_contents( $expect, (string) time(), LOCK_EX ) ) {
			return false;
		}
		$url  = self::rest_url( 'loopback-ping' );
		$args = array(
			'method'      => 'POST',
			'timeout'     => self::PROBE_WAIT_SECONDS,
			'blocking'    => true,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', true ),
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache, no-store',
			),
			'body'        => wp_json_encode( array( 'nonce' => $nonce ) ),
		);
		self::http_post( $url, $args );

		$deadline = microtime( true ) + self::PROBE_WAIT_SECONDS;
		$found    = false;
		while ( microtime( true ) < $deadline ) {
			clearstatcache( true, $marker );
			if ( is_file( $marker ) ) {
				$found = true;
				break;
			}
			usleep( 100000 );
		}
		@unlink( $expect );
		@unlink( $marker );
		return $found;
	}

	/**
	 * Called by the loopback-ping REST route.
	 *
	 * @param string $nonce Probe nonce.
	 * @return true|\WP_Error
	 */
	public static function complete_probe( $nonce ) {
		$nonce = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $nonce ) );
		if ( strlen( $nonce ) < 16 || strlen( $nonce ) > 64 ) {
			return new \WP_Error(
				'jisento_probe',
				__( 'Stage: start. Operation: loopback probe. Reason: the probe nonce is invalid. Recovery: start the job again. Job: -', 'jisento' ),
				array( 'status' => 400 )
			);
		}
		$dir    = self::probe_dir();
		$expect = $dir . '/' . $nonce . '.expect';
		if ( ! is_file( $expect ) ) {
			return new \WP_Error(
				'jisento_probe',
				__( 'Stage: start. Operation: loopback probe. Reason: no matching probe was waiting. Recovery: start the job again. Job: -', 'jisento' ),
				array( 'status' => 404 )
			);
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'jisento_probe',
				__( 'Stage: start. Operation: loopback probe. Reason: the probe folder is not writable. Recovery: make wp-content/jisento writable by PHP. Job: -', 'jisento' ),
				array( 'status' => 500 )
			);
		}
		$marker = $dir . '/' . $nonce . '.ok';
		if ( false === file_put_contents( $marker, (string) time(), LOCK_EX ) ) {
			return new \WP_Error(
				'jisento_probe',
				__( 'Stage: start. Operation: loopback probe. Reason: the probe marker could not be written. Recovery: make wp-content/jisento writable by PHP. Job: -', 'jisento' ),
				array( 'status' => 500 )
			);
		}
		return true;
	}

	/**
	 * @return string
	 */
	public static function probe_dir() {
		if ( defined( 'JISENTO_LOOPBACK_DIR' ) ) {
			return JISENTO_LOOPBACK_DIR;
		}
		return Plugin::instance()->storage->root() . '/temp/loopback';
	}

	/**
	 * @param string $mysql_datetime Job updated_at.
	 * @return int Seconds since that time (0 if unparseable).
	 */
	public static function age_seconds( $mysql_datetime ) {
		$mysql_datetime = (string) $mysql_datetime;
		if ( '' === $mysql_datetime ) {
			return 0;
		}
		$ts = strtotime( $mysql_datetime . ' UTC' );
		if ( false === $ts ) {
			$ts = strtotime( $mysql_datetime );
		}
		if ( false === $ts ) {
			return 0;
		}
		return max( 0, time() - (int) $ts );
	}

	/**
	 * @param string $url  URL.
	 * @param array  $args wp_remote_post args.
	 * @return array|\WP_Error
	 */
	private static function http_post( $url, array $args ) {
		if ( is_callable( self::$http_post ) ) {
			return call_user_func( self::$http_post, $url, $args );
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new \WP_Error( 'jisento_http', 'wp_remote_post is not available.' );
		}
		return wp_remote_post( $url, $args );
	}
}
