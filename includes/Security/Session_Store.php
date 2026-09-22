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
			return new \WP_Error( 'jisento_session', __( 'Invalid migration session.', 'jisento' ), array( 'status' => 401 ) );
		}
		if ( 'active' !== $row->status ) {
			$code = 'expired' === $row->status ? 410 : 403;
			return new \WP_Error( 'jisento_session', __( 'This migration session is no longer valid.', 'jisento' ), array( 'status' => $code ) );
		}
		if ( strtotime( $row->expires_at ) < time() ) {
			$wpdb->update( $wpdb->prefix . 'jisento_sessions', array( 'status' => 'expired' ), array( 'id' => $row->id ) );
			return new \WP_Error( 'jisento_session_expired', __( 'The migration session has expired.', 'jisento' ), array( 'status' => 410 ) );
		}
		if ( ! hash_equals( $row->token_hash, $this->hash( $token ) ) ) {
			return new \WP_Error( 'jisento_session', __( 'Invalid migration session.', 'jisento' ), array( 'status' => 401 ) );
		}
		$this->touch( $row->session_id );
		return $row;
	}

	public function touch( $session_id, $extend = 7200 ) {
		global $wpdb;
		$exp = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + 28800 ) );
		$wpdb->update(
			$wpdb->prefix . 'jisento_sessions',
			array( 'expires_at' => $exp ),
			array(
				'session_id' => $session_id,
				'status'     => 'active',
			)
		);
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
