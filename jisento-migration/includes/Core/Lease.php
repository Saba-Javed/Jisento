<?php
/**
 * Site-level mutation lease: at most one export, import or receive job changes this site at a time.
 *
 * One row in {prefix}jisento_locks. Acquisition is a single INSERT ... ON DUPLICATE KEY UPDATE that
 * only overwrites the row when it has expired or already belongs to the same job, followed by a
 * re-read to confirm ownership. Times come from the database clock so PHP time zones do not matter.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lease {

	const NAME = 'site';
	const TTL  = 120;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'jisento_locks';
	}

	/**
	 * Take the lease for a job, or renew it when the job already holds it.
	 *
	 * @param string $job_id Job id.
	 * @return true|\WP_Error Error (HTTP 409) when another job holds a live lease.
	 */
	public static function acquire( $job_id ) {
		global $wpdb;
		$job_id = (string) $job_id;
		if ( '' === $job_id ) {
			return new \WP_Error( 'jisento_lease', \esc_html__( 'A job id is required to take the site lease.', 'jisento-migration' ), array( 'status' => 500 ) );
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			$token = md5( uniqid( '', true ) );
		}
		$table = self::table();
		$ttl   = (int) self::TTL;
		// Columns are assigned left to right, so expires_at must come last: the takeover
		// condition reads the old expires_at for every other column.
		$take  = '(expires_at < UTC_TIMESTAMP() OR owner_job = VALUES(owner_job))';
		$sql   = $wpdb->prepare(
			"INSERT INTO `{$table}` (name, owner_job, token, heartbeat_at, expires_at)
			VALUES (%s, %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL {$ttl} SECOND)
			ON DUPLICATE KEY UPDATE
				token        = IF({$take}, VALUES(token), token),
				heartbeat_at = IF({$take}, VALUES(heartbeat_at), heartbeat_at),
				owner_job    = IF({$take}, VALUES(owner_job), owner_job),
				expires_at   = IF(owner_job = VALUES(owner_job) AND token = VALUES(token), VALUES(expires_at), expires_at)",
			self::NAME,
			$job_id,
			$token
		);
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new \WP_Error(
				'jisento_lease',
				/* translators: 1: database error, 2: lease table name, 3: job id. */
				\esc_html( sprintf( __( 'Stage: start. Operation: take the site lease. Reason: database error %1$s. Recovery: check that the %2$s table exists (deactivate and reactivate the plugin), then retry. Job: %3$s', 'jisento-migration' ), $wpdb->last_error, $table, $job_id ) ),
				array( 'status' => 500 )
			);
		}
		$row = self::read();
		if ( $row && $row['owner_job'] === $job_id && $row['token'] === $token ) {
			return true;
		}
		$owner = $row ? $row['owner_job'] : '';
		return new \WP_Error(
			'jisento_busy', \esc_html( sprintf(
				/* translators: 1: owning job, 2: seconds, 3: job id */
				__( 'An import or export is already running on this site (job %1$s). Only one can run at a time. Wait for it to finish, cancel it, or retry after %2$d seconds if it has stopped. Job: %3$s', 'jisento-migration' ),
				$owner,
				$row ? max( 0, (int) $row['expires_in'] ) : self::TTL,
				$job_id
			) ),
			array(
				'status' => 409,
				'owner'  => $owner,
			)
		);
	}

	/**
	 * @return array{owner_job:string,token:string,expires_in:int}|null
	 */
	public static function read() {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT owner_job, token, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS expires_in FROM `{$table}` WHERE name = %s", self::NAME ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'owner_job'  => (string) $row['owner_job'],
			'token'      => (string) $row['token'],
			'expires_in' => (int) $row['expires_in'],
		);
	}

	/**
	 * Job holding a live (not expired) lease, or ''.
	 *
	 * @return string
	 */
	public static function holder() {
		$row = self::read();
		return ( $row && $row['expires_in'] >= 0 ) ? $row['owner_job'] : '';
	}

	/**
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public static function release( $job_id ) {
		global $wpdb;
		$table = self::table();
		return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE name = %s AND owner_job = %s", self::NAME, (string) $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per-job advisory lock. NULL from GET_LOCK (error, killed thread) is "not acquired".
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public static function lock_job( $job_id, $wait = 0 ) {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $job_id ), max( 0, (int) $wait ) ) );
		return null !== $got && '1' === (string) $got;
	}

	public static function unlock_job( $job_id ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $job_id ) ) );
	}

	public static function lock_name( $job_id ) {
		global $wpdb;
		// GET_LOCK names are server-wide; include the database and prefix so two sites on one server never share a lock.
		return substr( 'jisento_' . md5( $wpdb->dbname . '|' . $wpdb->prefix ) . '_' . preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $job_id ), 0, 64 );
	}
}

