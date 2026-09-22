<?php
/**
 * Lets a migration job keep running after the restored database replaces the destination login.
 *
 * The proof is a random value stored only as a hash in wp-content/jisento/jobs/.
 * It is not a WordPress auth cookie and it is not written into the database.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Continuation {

	const TTL = 172800;

	public static function register() {
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'accept_replaced_session' ), 101 );
	}

	/**
	 * WordPress returns "Cookie check failed" when a REST nonce is sent and the
	 * logged-in session it belonged to is gone. A valid job proof is a separate
	 * credential, so that one rejection must not abort this job route.
	 *
	 * @param mixed $result Authentication result so far.
	 * @return mixed
	 */
	public static function accept_replaced_session( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
			return $result;
		}
		$job_id = self::job_id_from_request();
		if ( '' === $job_id || ! self::matches( $job_id, self::presented() ) ) {
			return $result;
		}
		self::note_once( $job_id );
		return true;
	}

	/**
	 * Create a one-time proof for this job. Returns the raw proof once, or '' if it could not be stored.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public static function issue( $job_id ) {
		if ( ! self::valid_id( $job_id ) ) {
			return '';
		}
		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			return '';
		}
		$record = array(
			'hash'    => self::digest( $secret ),
			'expires' => time() + self::TTL,
			'noted'   => 0,
		);
		if ( ! self::write_record( $job_id, $record ) ) {
			return '';
		}
		return $secret;
	}

	/**
	 * @param string $job_id Job id.
	 * @param string $secret Presented proof.
	 * @return bool
	 */
	public static function matches( $job_id, $secret ) {
		if ( ! self::valid_id( $job_id ) || ! self::valid_secret( $secret ) ) {
			return false;
		}
		$record = self::read( $job_id );
		if ( ! is_array( $record ) || empty( $record['hash'] ) || empty( $record['expires'] ) ) {
			return false;
		}
		if ( (int) $record['expires'] < time() ) {
			return false;
		}
		return hash_equals( (string) $record['hash'], self::digest( $secret ) );
	}

	/**
	 * Header value for this request. Empty when missing or not the expected shape.
	 * The value itself is never logged.
	 *
	 * @return string
	 */
	public static function presented() {
		$secret = isset( $_SERVER['HTTP_X_JISENTO_JOB_TOKEN'] ) ? (string) $_SERVER['HTTP_X_JISENTO_JOB_TOKEN'] : '';
		return self::valid_secret( $secret ) ? $secret : '';
	}

	/**
	 * @return string
	 */
	public static function job_id_from_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( preg_match( '#/jisento/v1/jobs/([A-Za-z0-9_]{8,64})#', $uri, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Record safe session metadata once, after the destination login no longer authenticates this browser.
	 *
	 * @param string $job_id Job id.
	 * @return string
	 */
	public static function note_once( $job_id ) {
		$record = self::read( $job_id );
		if ( ! is_array( $record ) || ! empty( $record['noted'] ) ) {
			return '';
		}
		$record['noted'] = 1;
		self::write_record( $job_id, $record );
		$message = self::format_report( self::collect_flags() );
		if ( class_exists( '\Jisento\Migration\Plugin' ) ) {
			\Jisento\Migration\Plugin::instance()->logger->log( $job_id, 'runtime', 'cookie-check', '', 'info', $message );
		}
		return $message;
	}

	/**
	 * @return array<string,string>
	 */
	public static function collect_flags() {
		$home    = function_exists( 'get_option' ) ? (string) get_option( 'home' ) : '';
		$site    = function_exists( 'get_option' ) ? (string) get_option( 'siteurl' ) : '';
		$admin   = function_exists( 'admin_url' ) ? (string) admin_url() : '';
		$request = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) : '';
		$home_host = self::host_of( $home );
		$https   = isset( $_SERVER['HTTPS'] ) ? strtolower( (string) $_SERVER['HTTPS'] ) : '';
		$proto   = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) : '';
		$proto   = trim( explode( ',', $proto )[0] );

		return array(
			'home'                  => $home,
			'siteurl'               => $site,
			'admin_host'            => self::host_of( $admin ),
			'request_host'          => is_string( $request ) ? $request : '',
			'home_matches_request'  => ( '' !== $home_host && 0 === strcasecmp( $home_host, (string) $request ) ) ? 'yes' : 'no',
			'is_ssl'                => ( function_exists( 'is_ssl' ) && is_ssl() ) ? 'yes' : 'no',
			'https_server'          => ( 'on' === $https || '1' === $https ) ? 'yes' : 'no',
			'forwarded_https'       => ( 'https' === $proto ) ? 'yes' : 'no',
			'wp_home'               => defined( 'WP_HOME' ) ? self::host_of( WP_HOME ) : 'undefined',
			'wp_siteurl'            => defined( 'WP_SITEURL' ) ? self::host_of( WP_SITEURL ) : 'undefined',
			'force_ssl_admin'       => ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ) ? 'yes' : 'no',
			'cookie_domain'         => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : 'undefined',
			'cookie_path'           => defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : 'undefined',
			'site_cookie_path'      => defined( 'SITECOOKIEPATH' ) ? (string) SITECOOKIEPATH : 'undefined',
			'admin_cookie_path'     => defined( 'ADMIN_COOKIE_PATH' ) ? (string) ADMIN_COOKIE_PATH : 'undefined',
			'user_id'               => function_exists( 'get_current_user_id' ) ? (string) (int) get_current_user_id() : '0',
			'logged_in'             => ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ? 'yes' : 'no',
			'nonce_header'          => ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && '' !== (string) $_SERVER['HTTP_X_WP_NONCE'] ) ? 'present' : 'absent',
			'continuation_header'   => ( '' !== self::presented() ) ? 'present' : 'absent',
			'logged_in_cookie'      => self::cookie_presence( defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '' ),
			'auth_cookie'           => self::cookie_presence( defined( 'AUTH_COOKIE' ) ? AUTH_COOKIE : '' ),
			'secure_cookie'         => self::cookie_presence( defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : '' ),
			'salts_defined'         => self::salts_defined() ? 'yes' : 'no',
		);
	}

	/**
	 * @param array<string,string> $flags Safe metadata only.
	 * @return string
	 */
	public static function format_report( array $flags ) {
		$allowed = array(
			'home',
			'siteurl',
			'admin_host',
			'request_host',
			'home_matches_request',
			'is_ssl',
			'https_server',
			'forwarded_https',
			'wp_home',
			'wp_siteurl',
			'force_ssl_admin',
			'cookie_domain',
			'cookie_path',
			'site_cookie_path',
			'admin_cookie_path',
			'user_id',
			'logged_in',
			'nonce_header',
			'continuation_header',
			'logged_in_cookie',
			'auth_cookie',
			'secure_cookie',
			'salts_defined',
		);
		$parts   = array( 'WordPress REST cookie check does not match this browser after the restored user sessions replaced the destination login. Authentication salts were not changed.' );
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $flags ) ) {
				continue;
			}
			$value   = preg_replace( '/[\r\n\t]/', ' ', (string) $flags[ $key ] );
			$parts[] = $key . '=' . substr( $value, 0, 200 );
		}
		return implode( ' ', $parts );
	}

	/**
	 * @param string $secret Proof.
	 * @return string
	 */
	public static function digest( $secret ) {
		return hash_hmac( 'sha256', (string) $secret, self::mac_key() );
	}

	/**
	 * @param string $job_id Job id.
	 * @return string
	 */
	public static function path_for( $job_id ) {
		return self::directory() . '/' . $job_id . '.continue';
	}

	private static function directory() {
		if ( defined( 'JISENTO_CONTINUATION_DIR' ) ) {
			return JISENTO_CONTINUATION_DIR;
		}
		return \Jisento\Migration\Plugin::instance()->storage->root() . '/jobs';
	}

	/**
	 * HMAC key from a random secret file. wp_salt() is not used: when salts live in the options
	 * table, the database swap replaces them with the source site's, which would invalidate every
	 * token mid-import.
	 *
	 * @return string
	 */
	private static function mac_key() {
		static $key = null;
		if ( null !== $key ) {
			return $key;
		}
		$path = self::directory() . '/.continuation-key';
		$raw  = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
			$dir = dirname( $path );
			if ( ! is_dir( $dir ) ) {
				if ( function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $dir );
				} else {
					@mkdir( $dir, 0755, true );
				}
			}
			try {
				$fresh = bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				throw new \RuntimeException( 'Operation: create the job token key. Reason: no secure random source is available. Recovery: enable a CSPRNG for PHP (random_bytes).' );
			}
			$handle = @fopen( $path, 'xb' );
			if ( $handle ) {
				@chmod( $path, 0600 );
				fwrite( $handle, $fresh );
				fclose( $handle );
			}
			// Another request may have won the race: always use what is on disk.
			$raw = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $raw ) ) {
				throw new \RuntimeException( sprintf( 'Operation: create the job token key. Reason: %s could not be written. Recovery: make wp-content/jisento/jobs writable by PHP.', $path ) );
			}
		}
		$key = $raw;
		return $key;
	}

	public static function forget( $job_id ) {
		if ( self::valid_id( $job_id ) && is_file( self::path_for( $job_id ) ) ) {
			@unlink( self::path_for( $job_id ) );
		}
	}

	private static function valid_id( $job_id ) {
		return is_string( $job_id ) && 1 === preg_match( '/^[A-Za-z0-9_]{8,64}$/', $job_id );
	}

	private static function valid_secret( $secret ) {
		return is_string( $secret ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $secret );
	}

	private static function read( $job_id ) {
		if ( ! self::valid_id( $job_id ) ) {
			return null;
		}
		$path = self::path_for( $job_id );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	private static function write_record( $job_id, array $record ) {
		if ( ! self::valid_id( $job_id ) ) {
			return false;
		}
		$dir = self::directory();
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} elseif ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : json_encode( $record );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		$path = self::path_for( $job_id );
		if ( ! is_file( $path ) ) {
			@touch( $path );
		}
		@chmod( $path, 0600 );
		return false !== file_put_contents( $path, $encoded, LOCK_EX );
	}

	private static function cookie_presence( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return 'undefined';
		}
		return isset( $_COOKIE[ $name ] ) ? 'present' : 'absent';
	}

	private static function salts_defined() {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
			if ( ! defined( $name ) || '' === (string) constant( $name ) ) {
				return false;
			}
		}
		return true;
	}

	private static function host_of( $url ) {
		$parts = parse_url( (string) $url );
		return ( is_array( $parts ) && isset( $parts['host'] ) ) ? (string) $parts['host'] : '';
	}
}
