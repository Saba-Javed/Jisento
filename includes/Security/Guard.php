<?php
/**
 * Request security helpers: nonces, rate limits, path checks.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guard {

	public static function require_admin_rest() {
		if ( ! Capabilities::current_user_can() ) {
			return new \WP_Error( 'jisento_forbidden', __( 'You are not allowed to run migrations.', 'jisento' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rate_limit( $bucket, $max = 30, $window = 60 ) {
		$key   = 'jisento_rl_' . md5( $bucket . self::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new \WP_Error( 'jisento_rate_limited', __( 'Too many requests. Please wait and try again.', 'jisento' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip;
	}

	public static function is_https_request() {
		return is_ssl();
	}

	public static function require_https_for_remote() {
		$settings = \Jisento\Migration\Plugin::instance()->settings;
		if ( $settings->get( 'https_required', true ) && ! self::is_https_request() && ! self::is_local_dev() ) {
			return new \WP_Error(
				'jisento_https_required',
				__( 'Remote migration requires HTTPS.', 'jisento' ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	public static function is_local_dev() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || '.local' === substr( $host, -6 ) || '.test' === substr( $host, -5 );
	}

	/**
	 * Prevent archive path traversal. Returns a sanitized relative path or WP_Error.
	 *
	 * @param string $relative Relative path from the archive.
	 * @return string|\WP_Error
	 */
	public static function sanitize_archive_path( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative ) {
			return new \WP_Error( 'jisento_bad_path', __( 'Empty archive path.', 'jisento' ) );
		}

		if ( false !== strpos( $relative, "\0" ) ) {
			return new \WP_Error( 'jisento_bad_path', __( 'Invalid archive path.', 'jisento' ) );
		}

		$parts = explode( '/', $relative );
		foreach ( $parts as $part ) {
			if ( '.' === $part || '..' === $part || '' === $part ) {
				return new \WP_Error( 'jisento_bad_path', __( 'Archive path traversal is not allowed.', 'jisento' ) );
			}
		}

		if ( preg_match( '#^[a-zA-Z]:/#', $relative ) || 0 === strpos( $relative, '//' ) ) {
			return new \WP_Error( 'jisento_bad_path', __( 'Absolute archive paths are not allowed.', 'jisento' ) );
		}

		return $relative;
	}

	public static function is_inside_directory( $path, $root ) {
		$real_path = realpath( $path );
		$real_root = realpath( $root );
		if ( false === $real_root ) {
			return false;
		}
		if ( false === $real_path ) {
			$real_path = $path;
		}
		$real_path = str_replace( '\\', '/', $real_path );
		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' ) . '/';
		return 0 === strpos( $real_path . '/', $real_root ) || $real_path === rtrim( $real_root, '/' );
	}
}
