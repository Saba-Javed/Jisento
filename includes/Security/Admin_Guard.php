<?php
/**
 * Keeps a destination administrator available if an import stops early,
 * and refuses plugin updates while an import is still running.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

use Jisento\Migration\Core\Live_Url;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Guard {

	const USER_COLUMNS = array(
		'ID',
		'user_login',
		'user_pass',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_activation_key',
		'user_status',
		'display_name',
	);

	public static function register() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'block_install' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'block_auto_update' ), 10, 2 );
		add_filter( 'auto_update_theme', array( __CLASS__, 'block_auto_update' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		register_shutdown_function( array( __CLASS__, 'heal_open_import' ) );
	}

	/**
	 * @param mixed $response Install decision.
	 * @param array $extra    Upgrader context.
	 * @return mixed
	 */
	public static function block_install( $response, $extra = array() ) {
		unset( $extra );
		if ( is_wp_error( $response ) || ! self::import_running() ) {
			return $response;
		}
		return new \WP_Error(
			'jisento_import_running',
			__( 'A Jisento import is still running. Updates are blocked until it finishes so the destination is not left without an administrator.', 'jisento' )
		);
	}

	/**
	 * @param mixed $update Whether to auto-update.
	 * @param mixed $item   Update item.
	 * @return mixed
	 */
	public static function block_auto_update( $update, $item = null ) {
		unset( $item );
		if ( self::import_running() ) {
			return false;
		}
		return $update;
	}

	public static function notice() {
		if ( ! self::import_running() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'A Jisento import is in progress. Do not update or delete plugins until it finishes. Live tables stay in place until the database restore completes.', 'jisento' ) . '</p></div>';
	}

	/**
	 * An interrupted import must not be the reason the users table has no administrator.
	 */
	public static function heal_open_import() {
		$job_id = Live_Url::active_job_id();
		if ( '' === $job_id ) {
			return;
		}
		self::ensure( $job_id );
	}

	/**
	 * Save the current administrators before any table is replaced.
	 * An empty users table is not snapshotted, so a later heal cannot lock in the failure.
	 *
	 * @param string $job_id Job id.
	 */
	public static function snapshot( $job_id ) {
		if ( ! self::valid_id( $job_id ) || self::snapshot_exists( $job_id ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		if ( self::administrator_count() < 1 ) {
			return;
		}
		global $wpdb;
		$key = $wpdb->prefix . 'capabilities';
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$key,
				'%"administrator"%'
			)
		);
		if ( ! is_array( $ids ) || ! $ids ) {
			return;
		}
		$ids     = array_map( 'intval', $ids );
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$users   = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID IN ($holders)", ...$ids ),
			ARRAY_A
		);
		$meta    = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($holders)", ...$ids ),
			ARRAY_A
		);
		if ( ! is_array( $users ) || ! $users ) {
			return;
		}
		self::write(
			$job_id,
			array(
				'users' => $users,
				'meta'  => is_array( $meta ) ? $meta : array(),
			)
		);
	}

	/**
	 * Put the snapshotted administrator back when the live table has none.
	 *
	 * @param string $job_id Job id.
	 */
	public static function ensure( $job_id ) {
		if ( ! self::valid_id( $job_id ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		if ( 0 !== self::administrator_count() ) {
			return;
		}
		$data = self::read( $job_id );
		if ( ! is_array( $data ) || empty( $data['users'] ) || ! is_array( $data['users'] ) ) {
			return;
		}
		global $wpdb;
		foreach ( $data['users'] as $user ) {
			if ( ! is_array( $user ) || empty( $user['user_login'] ) ) {
				continue;
			}
			$login = (string) $user['user_login'];
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $login ) );
			if ( $exists ) {
				continue;
			}
			$row = array();
			foreach ( self::USER_COLUMNS as $column ) {
				if ( array_key_exists( $column, $user ) ) {
					$row[ $column ] = $user[ $column ];
				}
			}
			if ( empty( $row['user_login'] ) || empty( $row['user_pass'] ) ) {
				continue;
			}
			$original = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			if ( $original > 0 ) {
				$taken = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d", $original ) );
				if ( $taken ) {
					unset( $row['ID'] );
				}
			}
			$inserted = $wpdb->insert( $wpdb->users, $row );
			if ( ! $inserted ) {
				continue;
			}
			$new_id = isset( $row['ID'] ) ? (int) $row['ID'] : (int) $wpdb->insert_id;
			if ( $new_id < 1 || empty( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
				continue;
			}
			foreach ( $data['meta'] as $meta ) {
				if ( ! is_array( $meta ) || (int) $meta['user_id'] !== $original ) {
					continue;
				}
				$wpdb->insert(
					$wpdb->usermeta,
					array(
						'user_id'    => $new_id,
						'meta_key'   => isset( $meta['meta_key'] ) ? (string) $meta['meta_key'] : '',
						'meta_value' => isset( $meta['meta_value'] ) ? (string) $meta['meta_value'] : '',
					)
				);
			}
		}
	}

	/**
	 * @return int -1 when the users table is missing, otherwise the administrator count.
	 */
	public static function administrator_count() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return -1;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->users ) );
		if ( $wpdb->users !== $found ) {
			return -1;
		}
		$key = $wpdb->prefix . 'capabilities';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$key,
				'%"administrator"%'
			)
		);
	}

	/**
	 * Restore only when the live table is present and has no administrator.
	 *
	 * @param int $count administrator_count() result.
	 * @return bool
	 */
	public static function should_restore( $count ) {
		return 0 === (int) $count;
	}

	public static function import_running() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'jisento_jobs';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $found ) {
			return false;
		}
		$job = $wpdb->get_var( "SELECT job_id FROM `{$table}` WHERE type = 'import' AND status IN ('running','preparing') LIMIT 1" );
		return is_string( $job ) && '' !== $job;
	}

	private static function snapshot_exists( $job_id ) {
		return is_file( self::path( $job_id ) );
	}

	private static function valid_id( $job_id ) {
		return is_string( $job_id ) && 1 === preg_match( '/^[A-Za-z0-9_]{8,64}$/', $job_id );
	}

	private static function path( $job_id ) {
		$dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/jisento/jobs' : sys_get_temp_dir();
		return $dir . '/' . $job_id . '.admins.json';
	}

	private static function read( $job_id ) {
		$path = self::path( $job_id );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function write( $job_id, array $data ) {
		$path = self::path( $job_id );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} elseif ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return;
			}
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
		if ( is_string( $encoded ) ) {
			file_put_contents( $path, $encoded, LOCK_EX );
		}
	}
}
