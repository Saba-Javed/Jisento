<?php
/**
 * Persistent job state for resumable migrations.
 *
 * Every write is "UPDATE ... WHERE job_id = ? AND version = ?". A request holding an old copy of
 * the job cannot overwrite newer state; it gets a Job_Conflict instead.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb->prefix + plugin-owned identifiers, not user input.

class Job_Store {

	const HEX_KEY = '__jisento_hex';

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'jisento_jobs';
	}

	public function create( $type, array $state = array() ) {
		global $wpdb;
		$job_id = 'jisento_mig_' . bin2hex( random_bytes( 6 ) );
		$now    = current_time( 'mysql' );
		$json   = self::encode_state( $state, $job_id );
		$ok     = $wpdb->insert(
			$this->table(),
			array(
				'job_id'       => $job_id,
				'type'         => sanitize_key( $type ),
				'status'       => 'created',
				'stage'        => 'created',
				'version'      => 0,
				'progress'     => 0,
				'bytes_done'   => 0,
				'bytes_total'  => 0,
				'current_item' => '',
				'state_json'   => $json,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		if ( ! $ok ) {
			throw new \RuntimeException(
				/* translators: 1: database error, 2: job id. */
				esc_html( sprintf( __( 'Stage: start. Operation: create the job record. Reason: database error %1$s. Recovery: deactivate and reactivate Jisento Migration so its tables are repaired, then retry. Job: %2$s', 'jisento-migration' ), $wpdb->last_error, $job_id ) )
			);
		}
		return $this->get( $job_id );
	}

	public function get( $job_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE job_id = %s',
				$job_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$decoded    = json_decode( (string) $row->state_json, true );
		$row->state = is_array( $decoded ) ? self::decode_value( $decoded ) : array();
		$row->version = isset( $row->version ) ? (int) $row->version : 0;
		return $row;
	}

	/**
	 * @param object|string $job    Job row (its version is checked) or job id (current version is used).
	 * @param array         $fields Columns, plus "state".
	 * @return object Updated job.
	 * @throws Job_Conflict When the row changed since $job was read.
	 * @throws \RuntimeException When the state cannot be encoded or the write fails.
	 */
	public function update( $job, array $fields ) {
		global $wpdb;
		if ( is_object( $job ) ) {
			$job_id  = (string) $job->job_id;
			$version = isset( $job->version ) ? (int) $job->version : null;
		} else {
			$job_id  = (string) $job;
			$version = null;
		}
		if ( null === $version ) {
			$current = $this->get( $job_id );
			if ( ! $current ) {
				/* translators: %s: runtime values. */
				throw new \RuntimeException(esc_html( sprintf( __( 'Job %s no longer exists.', 'jisento-migration' ), $job_id ) ));
			}
			$version = (int) $current->version;
		}
		if ( array_key_exists( 'state', $fields ) ) {
			$state = is_array( $fields['state'] ) ? $fields['state'] : array();
			unset( $state['checksums'], $state['files'] );
			$fields['state_json'] = self::encode_state( $state, $job_id );
			unset( $fields['state'] );
		}
		unset( $fields['version'], $fields['job_id'] );
		$fields['updated_at'] = current_time( 'mysql' );

		$sets = array();
		$args = array();
		foreach ( $fields as $column => $value ) {
			$column = preg_replace( '/[^a-z_]/', '', (string) $column );
			if ( null === $value ) {
				$sets[] = "`{$column}` = NULL";
				continue;
			}
			$sets[] = "`{$column}` = %s";
			$args[] = (string) $value;
		}
		$args[] = $job_id;
		$args[] = $version;
		$sql    = $wpdb->prepare(
			'UPDATE ' . $this->table() . ' SET ' . implode( ', ', $sets ) . ', `version` = `version` + 1 WHERE job_id = %s AND `version` = %d',
			$args
		);
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $result ) {
			throw new \RuntimeException(
				/* translators: 1: database error, 2: job id. */
				esc_html( sprintf( __( 'Operation: save job state. Reason: database error %1$s. Recovery: check the database connection and disk space, then press Retry. Job: %2$s', 'jisento-migration' ), $wpdb->last_error, $job_id ) )
			);
		}
		if ( 1 !== (int) $result ) {
			throw new Job_Conflict(
				/* translators: %s: job id. */
				esc_html( sprintf( __( 'Operation: save job state. Reason: the job was changed by another request (paused, cancelled, or a second worker) since this step started, so this step\'s result was discarded. Recovery: none needed; reload the job status. Job: %s', 'jisento-migration' ), $job_id ) )
			);
		}
		$saved = $this->get( $job_id );
		if ( ! $saved ) {
			/* translators: %s: runtime values. */
			throw new \RuntimeException(esc_html( sprintf( __( 'Job %s disappeared while it was being saved.', 'jisento-migration' ), $job_id ) ));
		}
		return $saved;
	}

	public function delete( $job_id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'job_id' => (string) $job_id ) );
	}

	public function list_recent( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_id, type, status, stage, progress, created_at, updated_at, error_summary FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	/**
	 * Running jobs (for cron / scheduler ticks).
	 *
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public function list_running( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_id, type, status, stage, updated_at FROM ' . $this->table() . " WHERE status = 'running' ORDER BY updated_at ASC LIMIT %d",
				max( 1, (int) $limit )
			)
		);
	}

	public function expire_stale() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT job_id FROM {$wpdb->prefix}jisento_jobs WHERE status IN ('created','preparing','running','paused') AND updated_at < DATE_SUB(%s, INTERVAL 2 DAY)",
				current_time( 'mysql' )
			)
		);
		if ( ! is_array( $ids ) ) {
			return;
		}
		foreach ( $ids as $job_id ) {
			$job = $this->get( (string) $job_id );
			if ( ! $job ) {
				continue;
			}
			try {
				$job = $this->update(
					$job,
					array(
						'status'        => 'failed',
						'error_summary' => __( 'Migration expired after inactivity.', 'jisento-migration' ),
					)
				);
			} catch ( \Exception $e ) {
				continue;
			}
			\Jisento\Migration\Jobs\Job_Runner::finish( $job );
		}
	}

	/**
	 * Status payload for the browser. Cheap: no package re-verification on every poll.
	 *
	 * @param object|null $job Job.
	 * @return array|null
	 */
	public function to_response( $job ) {
		if ( ! $job ) {
			return null;
		}
		$state = is_array( $job->state ) ? $job->state : array();
		unset( $state['session_token'], $state['key_plain'], $state['checksums'], $state['files'], $state['tables'], $state['table_meta'], $state['dump'], $state['plan'] );
		if ( isset( $state['remote']['token'] ) ) {
			unset( $state['remote']['token'] );
		}

		$package_ok   = false;
		$package_size = 0;
		$package_name = isset( $state['package_name'] ) ? $state['package_name'] : '';
		if ( 'export' === $job->type && 'completed' === $job->status ) {
			$path = isset( $state['package_abs'] ) ? (string) $state['package_abs'] : '';
			if ( $path && is_file( $path ) ) {
				clearstatcache( true, $path );
				$package_size = (int) filesize( $path );
				$package_ok   = $package_size > 0 && $package_size === (int) ( isset( $state['package_size'] ) ? $state['package_size'] : 0 );
			}
		}

		$worker_mode = isset( $state['worker_mode'] ) ? (string) $state['worker_mode'] : 'server';
		$loopback_ok = ! empty( $state['loopback_ok'] );

		return array(
			'job_id'        => $job->job_id,
			'type'          => $job->type,
			'status'        => $job->status,
			'stage'         => $job->stage,
			'progress'      => (int) $job->progress,
			'bytes_done'    => (int) $job->bytes_done,
			'bytes_total'   => (int) $job->bytes_total,
			'current_item'  => $job->current_item,
			'error_summary' => $job->error_summary,
			'package_ok'    => $package_ok,
			'package_size'  => $package_size,
			'package_name'  => $package_name,
			'state'         => $state,
			'created_at'    => $job->created_at,
			'updated_at'    => $job->updated_at,
			'worker_mode'   => ( 'browser' === $worker_mode ) ? 'browser' : 'server',
			'loopback_ok'   => (bool) $loopback_ok,
			'stalled'       => Job_Scheduler::is_stalled( $job ),
		);
	}

	/**
	 * @param array  $state  State.
	 * @param string $job_id For the error message.
	 * @return string JSON.
	 * @throws \RuntimeException When encoding fails. An empty state is never stored.
	 */
	public static function encode_state( array $state, $job_id = '' ) {
		$json = wp_json_encode( self::encode_value( $state ) );
		if ( ! is_string( $json ) || '' === $json ) {
			throw new \RuntimeException(
				/* translators: 1: JSON error, 2: job id. */
				esc_html( sprintf( __( 'Operation: save job state. Reason: the state could not be encoded as JSON (%1$s). Recovery: press Retry; if it repeats, send the debug log to support. Job: %2$s', 'jisento-migration' ), function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'unknown', $job_id ) )
			);
		}
		return $json;
	}

	/**
	 * Strings that are not valid UTF-8 (file paths, cursors, driver messages with raw bytes)
	 * are stored as {"__jisento_hex": "<hex>"} so JSON encoding can never drop or mangle them.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function encode_value( $value ) {
		if ( is_string( $value ) ) {
			return self::is_utf8( $value ) ? $value : array( self::HEX_KEY => bin2hex( $value ) );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && ! self::is_utf8( $key ) ) {
					$key = self::HEX_KEY . ':' . bin2hex( $key );
				}
				$out[ $key ] = self::encode_value( $item );
			}
			return $out;
		}
		return $value;
	}

	public static function decode_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( 1 === count( $value ) && isset( $value[ self::HEX_KEY ] ) && is_string( $value[ self::HEX_KEY ] ) && preg_match( '/^(?:[0-9a-f]{2})*$/', $value[ self::HEX_KEY ] ) ) {
			return (string) hex2bin( $value[ self::HEX_KEY ] );
		}
		$out    = array();
		$prefix = self::HEX_KEY . ':';
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && 0 === strpos( $key, $prefix ) ) {
				$key = (string) hex2bin( substr( $key, strlen( $prefix ) ) );
			}
			$out[ $key ] = self::decode_value( $item );
		}
		return $out;
	}

	public static function is_utf8( $value ) {
		return 1 === preg_match( '//u', (string) $value );
	}
}

