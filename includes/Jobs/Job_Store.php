<?php
/**
 * Persistent job state for resumable migrations.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Store {

	public function create( $type, array $state = array() ) {
		global $wpdb;
		$job_id = 'jisento_mig_' . bin2hex( random_bytes( 6 ) );
		$now    = current_time( 'mysql' );
		$wpdb->insert(
			$wpdb->prefix . 'jisento_jobs',
			array(
				'job_id'       => $job_id,
				'type'         => sanitize_key( $type ),
				'status'       => 'created',
				'stage'        => 'created',
				'progress'     => 0,
				'bytes_done'   => 0,
				'bytes_total'  => 0,
				'current_item' => '',
				'state_json'   => wp_json_encode( $state ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		return $this->get( $job_id );
	}

	public function get( $job_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'jisento_jobs WHERE job_id = %s',
				$job_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$row->state = json_decode( (string) $row->state_json, true );
		if ( ! is_array( $row->state ) ) {
			$row->state = array();
		}
		return $row;
	}

	public function update( $job_id, array $fields ) {
		global $wpdb;
		if ( isset( $fields['state'] ) ) {
			if ( is_array( $fields['state'] ) ) {
				unset( $fields['state']['checksums'], $fields['state']['files'] );
			}
			$fields['state_json'] = wp_json_encode( $fields['state'] );
			unset( $fields['state'] );
		}
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update(
			$wpdb->prefix . 'jisento_jobs',
			$fields,
			array( 'job_id' => $job_id )
		);
		return $this->get( $job_id );
	}

	public function list_recent( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_id, type, status, stage, progress, created_at, updated_at, error_summary FROM ' . $wpdb->prefix . 'jisento_jobs ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
	}

	public function expire_stale() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jisento_jobs SET status = 'failed', error_summary = %s, updated_at = %s
				WHERE status IN ('created','preparing','running','paused') AND updated_at < DATE_SUB(%s, INTERVAL 2 DAY)",
				__( 'Migration expired after inactivity.', 'jisento' ),
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);
	}

	public function to_response( $job ) {
		if ( ! $job ) {
			return null;
		}
		$state = is_array( $job->state ) ? $job->state : array();
		unset( $state['session_token'], $state['key_plain'], $state['checksums'], $state['files'], $state['tables'], $state['table_meta'] );

		$package_ok   = false;
		$package_size = 0;
		$package_name = isset( $state['package_name'] ) ? $state['package_name'] : '';
		if ( 'export' === $job->type && 'completed' === $job->status ) {
			$path = isset( $state['package_abs'] ) ? $state['package_abs'] : '';
			if ( ( ! $path || ! is_file( $path ) ) && ! empty( $state['package'] ) ) {
				$resolved = \Jisento\Migration\Plugin::instance()->storage->resolve( $state['package'] );
				$path     = $resolved ? $resolved['path'] : '';
			}
			$reason = __( 'The backup record exists, but the package file is missing or corrupted.', 'jisento' );
			if ( $path && is_file( $path ) ) {
				$check = ( new \Jisento\Migration\Package\Package_Registry() )->verify_file( $path );
				if ( ! empty( $check['ok'] ) && (int) $check['size'] > 0 ) {
					$package_ok   = true;
					$package_size = (int) $check['size'];
					$package_name = basename( $path );
				} elseif ( ! empty( $check['reason'] ) ) {
					$reason = $check['reason'];
				}
			}
			if ( ! $package_ok ) {
				$job->status        = 'failed';
				$job->error_summary = $reason;
				$this->update(
					$job->job_id,
					array(
						'status'        => 'failed',
						'stage'         => 'failed',
						'error_summary' => $reason,
					)
				);
			}
		}

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
		);
	}
}
