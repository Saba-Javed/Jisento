<?php
/**
 * Migration key generation, hashing, expiry, and revocation.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migration_Key_Store {

	public function create( $ttl = 1800, $single_use = true ) {
		$raw  = strtoupper( bin2hex( random_bytes( 8 ) ) );
		$key  = sprintf( 'JIS-%s-%s-%s', substr( $raw, 0, 4 ), substr( $raw, 4, 4 ), substr( $raw, 8, 4 ) . substr( $raw, 12, 1 ) );
		$hash = $this->hash( $key );
		$now  = current_time( 'mysql' );
		$exp  = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl ) );
		$exp  = get_date_from_gmt( $exp );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jisento_keys',
			array(
				'key_hash'   => $hash,
				'key_hint'   => self::hint( $key ),
				'status'     => 'active',
				'single_use' => $single_use ? 1 : 0,
				'expires_at' => $exp,
				'created_at' => $now,
				'meta_json'  => wp_json_encode(
					array(
						'site_url' => home_url(),
					)
				),
			)
		);

		return array(
			'id'         => (int) $wpdb->insert_id,
			'key'        => $key,
			'expires_at' => $exp,
			'single_use' => (bool) $single_use,
			'hint'       => self::hint( $key ),
			'connect'    => home_url() . '#' . $key,
		);
	}

	public function hash( $key ) {
		return hash_hmac( 'sha256', strtoupper( trim( $key ) ), wp_salt( 'auth' ) );
	}

	public function find_active( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'jisento_keys WHERE key_hash = %s',
				$this->hash( $key )
			)
		);
		if ( ! $row ) {
			return new \WP_Error( 'jisento_bad_key', __( 'Invalid migration key.', 'jisento-migration' ), array( 'status' => 403 ) );
		}
		if ( 'active' !== $row->status ) {
			return new \WP_Error( 'jisento_key_used', __( 'This migration key is no longer valid.', 'jisento-migration' ), array( 'status' => 403 ) );
		}
		if ( strtotime( $row->expires_at ) < time() ) {
			$this->revoke( (int) $row->id );
			return new \WP_Error( 'jisento_key_expired', __( 'This migration key has expired.', 'jisento-migration' ), array( 'status' => 403 ) );
		}
		return $row;
	}

	public function consume( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'jisento_keys WHERE id = %d', $id ) );
		if ( $row && (int) $row->single_use ) {
			$wpdb->update(
				$wpdb->prefix . 'jisento_keys',
				array(
					'status'  => 'used',
					'used_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id )
			);
		}
	}

	public function revoke( $id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'jisento_keys',
			array( 'status' => 'revoked' ),
			array( 'id' => (int) $id )
		);
	}

	public function list_keys() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, key_hint, status, single_use, expires_at, used_at, created_at FROM ' . $wpdb->prefix . 'jisento_keys ORDER BY id DESC LIMIT 50'
		);
		foreach ( (array) $rows as $row ) {
			// Hints stored by 1.2.x hold the first characters of the key; never show those.
			if ( isset( $row->key_hint ) && 0 !== strpos( (string) $row->key_hint, '…' ) ) {
				$row->key_hint = '…';
			}
		}
		return $rows;
	}

	/**
	 * Only the last four characters are ever shown.
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function hint( $key ) {
		return '…' . substr( (string) $key, -4 );
	}

	public function expire_stale() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jisento_keys SET status = 'expired' WHERE status = 'active' AND expires_at < %s",
				current_time( 'mysql' )
			)
		);
	}
}
