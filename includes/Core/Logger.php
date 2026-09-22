<?php
/**
 * Migration logger. Never records passwords, keys, or credentials.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	const REDACT_PATTERNS = '/(password|passwd|secret|token|authorization|migration[_-]?key|db_password|auth_key|nonce_key|logged_in_key|secure_auth)/i';

	public function log( $migration_id, $stage, $operation, $item, $status, $message = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jisento_logs';

		$wpdb->insert(
			$table,
			array(
				'migration_id' => sanitize_text_field( (string) $migration_id ),
				'stage'        => sanitize_key( (string) $stage ),
				'operation'    => sanitize_text_field( (string) $operation ),
				'item'         => sanitize_text_field( substr( (string) $item, 0, 255 ) ),
				'status'       => sanitize_key( (string) $status ),
				'message'      => $this->redact( (string) $message ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function query( $migration_id = '', $limit = 200 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jisento_logs';
		$limit = max( 1, min( 1000, (int) $limit ) );

		if ( $migration_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE migration_id = %s ORDER BY id DESC LIMIT %d",
					$migration_id,
					$limit
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	public function export_text( $migration_id = '' ) {
		$rows = $this->query( $migration_id, 1000 );
		$out  = "timestamp\tmigration_id\tstage\toperation\titem\tstatus\tmessage\n";
		foreach ( array_reverse( $rows ) as $row ) {
			$out .= sprintf(
				"%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
				$row->created_at,
				$row->migration_id,
				$row->stage,
				$row->operation,
				$row->item,
				$row->status,
				str_replace( array( "\t", "\n" ), ' ', (string) $row->message )
			);
		}
		return $out;
	}

	public function prune( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jisento_logs';
		$days  = max( 1, (int) $days );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
				current_time( 'mysql' ),
				$days
			)
		);
	}

	public function redact( $message ) {
		$message = preg_replace( self::REDACT_PATTERNS, '[redacted]', $message );
		$message = preg_replace( '/JIS-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/i', 'JIS-[redacted]', $message );
		return $message;
	}
}
