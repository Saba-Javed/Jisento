<?php
/**
 * Temporary authenticated remote sessions. Keys are never used as long-lived credentials.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session_Store {

	public function create( $key_id, $peer_host = '', $ttl = 28800, array $meta = array() ) {
		$session_id = 'jss_' . bin2hex( random_bytes( 12 ) );
		$token      = bin2hex( random_bytes( 32 ) );
		$hash       = $this->hash( $token );
		$exp        = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + max( 300, (int) $ttl ) ) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jisento_sessions',
			array(
				'session_id' => $session_id,
				'token_hash' => $hash,
				'key_id'     => (int) $key_id,
				'status'     => 'active',
				'peer_host'  => sanitize_text_field( $peer_host ),
				'expires_at' => $exp,
				'created_at' => current_time( 'mysql' ),
				'meta_json'  => wp_json_encode( $meta ),
			)
		);

		return array(
			'session_id' => $session_id,
			'token'      => $token,
			'expires_at' => $exp,
		);
	}

	public function hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'secure_auth' ) );
	}

	public function authenticate( $session_id, $token ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'jisento_sessions WHERE session_id = %s',
				$session_id
			)
		);
		if ( ! $row ) {
			return new \WP_Error( 'jisento_session', esc_html__( 'Invalid migration session.', 'jisento-migration' ), array( 'status' => 401 ) );
		}
		if ( 'active' !== $row->status ) {
			$code = 'expired' === $row->status ? 410 : 403;
			return new \WP_Error( 'jisento_session', esc_html__( 'This migration session is no longer valid.', 'jisento-migration' ), array( 'status' => $code ) );
		}
		if ( strtotime( $row->expires_at ) < time() ) {
			$wpdb->update( $wpdb->prefix . 'jisento_sessions', array( 'status' => 'expired' ), array( 'id' => $row->id ) );
			return new \WP_Error( 'jisento_session_expired', esc_html__( 'The migration session has expired.', 'jisento-migration' ), array( 'status' => 410 ) );
		}
		if ( ! is_string( $token ) || '' === $token || ! hash_equals( $row->token_hash, $this->hash( $token ) ) ) {
			return new \WP_Error( 'jisento_session', esc_html__( 'Invalid migration session.', 'jisento-migration' ), array( 'status' => 401 ) );
		}
		// The lifetime is fixed at creation; using the session never extends it.
		return $row;
	}

	/**
	 * @param object $row Session row.
	 * @return string Export job this session created, or ''.
	 */
	public static function bound_job( $row ) {
		$meta = isset( $row->meta_json ) ? json_decode( (string) $row->meta_json, true ) : null;
		return ( is_array( $meta ) && ! empty( $meta['export_job'] ) ) ? (string) $meta['export_job'] : '';
	}

	/**
	 * Bind the session to the one export job it may drive. A session can only be bound once.
	 *
	 * @param object $row    Session row.
	 * @param string $job_id Export job.
	 * @return bool
	 */
	public function bind_job( $row, $job_id ) {
		global $wpdb;
		if ( '' !== self::bound_job( $row ) ) {
			return false;
		}
		$meta = json_decode( (string) $row->meta_json, true );
		$meta = is_array( $meta ) ? $meta : array();
		$meta['export_job'] = (string) $job_id;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jisento_sessions SET meta_json = %s WHERE session_id = %s AND meta_json = %s",
				wp_json_encode( $meta ),
				$row->session_id,
				(string) $row->meta_json
			)
		);
		return 1 === (int) $updated;
	}

	public function revoke( $session_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'jisento_sessions',
			array( 'status' => 'revoked' ),
			array( 'session_id' => $session_id )
		);
	}

	public function expire_stale() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jisento_sessions SET status = 'expired' WHERE status = 'active' AND expires_at < %s",
				current_time( 'mysql' )
			)
		);
	}
}
