<?php
/**
 * Keeps the destination site on its own URL until URL replacement finishes.
 *
 * Package metadata is not the live site. Uploading or validating a package
 * must not call this. An import captures the destination URL when it starts,
 * and database restore is not allowed to leave the source home or siteurl active.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Live_Url {

	public static function protect() {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return;
		}
		self::install_guard();
		if ( self::pin_active() ) {
			self::hold();
			return;
		}
		self::heal_from_package();
	}

	public static function capture( $job_id ) {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return;
		}
		$existing = self::read();
		if ( $existing && empty( $existing['released'] ) && isset( $existing['job_id'] ) && (string) $existing['job_id'] === (string) $job_id ) {
			return;
		}
		self::write(
			array(
				'job_id'         => (string) $job_id,
				'home'           => self::db_option( 'home' ),
				'siteurl'        => self::db_option( 'siteurl' ),
				'active_plugins' => self::db_option( 'active_plugins' ),
				'hostinger'      => self::hostinger_options(),
				'released'       => false,
			)
		);
		self::install_guard();
	}

	public static function hold_if_options_statement( $sql ) {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return;
		}
		$table = $GLOBALS['wpdb']->options;
		if ( ! is_string( $sql ) || false === stripos( $sql, '`' . $table . '`' ) ) {
			return;
		}
		if ( preg_match( '/^(DROP|CREATE)\s/i', ltrim( $sql ) ) ) {
			return;
		}
		self::hold();
	}

	public static function hold() {
		$pin = self::read();
		if ( ! $pin || ! empty( $pin['released'] ) ) {
			return;
		}
		$imported = self::db_option( 'active_plugins' );
		if ( false !== $imported && $imported != ( isset( $pin['active_plugins'] ) ? $pin['active_plugins'] : null ) ) {
			$pin['imported_active_plugins'] = $imported;
			self::write( $pin );
		}
		self::force_option( 'home', isset( $pin['home'] ) ? $pin['home'] : '' );
		self::force_option( 'siteurl', isset( $pin['siteurl'] ) ? $pin['siteurl'] : '' );
		if ( array_key_exists( 'active_plugins', $pin ) ) {
			self::force_option( 'active_plugins', $pin['active_plugins'] );
		}
		if ( ! empty( $pin['hostinger'] ) && is_array( $pin['hostinger'] ) ) {
			foreach ( $pin['hostinger'] as $name => $raw ) {
				self::ensure_raw_option( (string) $name, $raw );
			}
		}
	}

	/**
	 * Destination-only options. A package must not turn Hostinger onboarding back on.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function preserved_option( $name ) {
		return is_string( $name ) && 0 === stripos( $name, 'hostinger' );
	}

	/**
	 * @return string
	 */
	public static function active_job_id() {
		$pin = self::read();
		if ( ! is_array( $pin ) || ! empty( $pin['released'] ) || empty( $pin['job_id'] ) ) {
			return '';
		}
		return (string) $pin['job_id'];
	}

	public static function adopt( $url ) {
		$pin = self::read();
		if ( ! $pin || '' === (string) $url ) {
			return;
		}
		$pin['home']    = $url;
		$pin['siteurl'] = $url;
		self::write( $pin );
		self::force_option( 'home', $url );
		self::force_option( 'siteurl', $url );
	}

	public static function release() {
		$pin = self::read();
		if ( ! $pin ) {
			return;
		}
		$pin['released'] = true;
		self::write( $pin );
		if ( isset( $pin['imported_active_plugins'] ) && false !== $pin['imported_active_plugins'] ) {
			update_option( 'active_plugins', $pin['imported_active_plugins'] );
		}
		$path = self::path();
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}

	public static function should_heal( $live_home, $live_siteurl, $request_host, array $package_homes ) {
		$request_host = strtolower( (string) $request_host );
		if ( '' === $request_host || ! preg_match( '/^[a-z0-9.-]+$/', $request_host ) ) {
			return false;
		}
		foreach ( array( $live_home, $live_siteurl ) as $live ) {
			$host = self::host_of( $live );
			if ( '' === $host || $host === $request_host ) {
				continue;
			}
			foreach ( $package_homes as $package ) {
				if ( self::host_of( $package ) === $host ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function url_with_host( $url, $host, $https ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		$parts['scheme'] = $https ? 'https' : 'http';
		$parts['host']   = $host;
		$path            = isset( $parts['path'] ) ? $parts['path'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $path;
	}

	public static function host_of( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url ) : parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( (string) $parts['host'] );
	}

	private static function heal_from_package() {
		if ( defined( 'WP_HOME' ) || defined( 'WP_SITEURL' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$admin = ( function_exists( 'is_admin' ) && is_admin() ) || false !== strpos( $uri, '/wp-admin' ) || false !== strpos( $uri, '/wp-login.php' );
		if ( ! $admin ) {
			return;
		}
		$host = self::request_host();
		if ( '' === $host ) {
			return;
		}
		$homes = self::package_home_urls();
		$home  = self::db_option( 'home' );
		$site  = self::db_option( 'siteurl' );
		if ( ! self::should_heal( $home, $site, $host, $homes ) ) {
			return;
		}
		$https   = function_exists( 'is_ssl' ) ? is_ssl() : ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] );
		$restore = self::url_with_host( $home ? $home : $site, $host, $https );
		self::force_option( 'home', $restore );
		self::force_option( 'siteurl', $restore );
	}

	private static function package_home_urls() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return array();
		}
		$urls = array();
		$dirs = array(
			WP_CONTENT_DIR . '/jisento/packages',
			WP_CONTENT_DIR . '/jisento/uploads',
			WP_CONTENT_DIR . '/jisento/backups',
		);
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( $dir . '/*.json' );
			if ( ! $files ) {
				continue;
			}
			foreach ( $files as $file ) {
				$meta = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $meta ) && ! empty( $meta['home_url'] ) ) {
					$urls[] = (string) $meta['home_url'];
				}
			}
		}
		return $urls;
	}

	private static function request_host() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		$host = strtolower( (string) $_SERVER['HTTP_HOST'] );
		$host = preg_replace( '/:\d+$/', '', $host );
		return is_string( $host ) ? $host : '';
	}

	private static function pin_active() {
		$pin = self::read();
		return is_array( $pin ) && empty( $pin['released'] ) && ! empty( $pin['home'] );
	}

	private static function force_option( $name, $value ) {
		if ( ! isset( $GLOBALS['wpdb'] ) || '' === (string) $name || false === $value || null === $value ) {
			return;
		}
		if ( is_string( $value ) && '' === $value ) {
			return;
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( ! $found ) {
			return;
		}
		$stored = function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
		if ( is_array( $value ) || is_object( $value ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $stored, $name ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) $value, $name ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private static function hostinger_options() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$like = $wpdb->esc_like( 'hostinger' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ),
			ARRAY_A
		);
		$out  = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) || ! self::preserved_option( (string) $row['option_name'] ) ) {
				continue;
			}
			$out[ (string) $row['option_name'] ] = isset( $row['option_value'] ) ? (string) $row['option_value'] : '';
		}
		return $out;
	}

	/**
	 * Write a preserved option back exactly, including when the swapped table no longer has the row.
	 *
	 * @param string $name Option name.
	 * @param mixed  $raw  Raw option_value from the destination.
	 */
	private static function ensure_raw_option( $name, $raw ) {
		if ( ! self::preserved_option( $name ) || ! is_string( $raw ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( $found ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $raw, $name ) );
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $raw, 'yes' ) );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private static function db_option( $name ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( null === $raw ) {
			return false;
		}
		return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
	}

	private static function install_guard() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! defined( 'JISENTO_PATH' ) || ! function_exists( 'wp_mkdir_p' ) ) {
			return;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}
		$file = WPMU_PLUGIN_DIR . '/jisento-live-url.php';
		$code = "<?php\n/**\n * Loads before normal plugins and puts the destination URL back if a package overwrote it.\n */\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\nrequire_once " . var_export( JISENTO_PATH . 'includes/Core/Live_Url.php', true ) . ";\n\\Jisento\\Migration\\Core\\Live_Url::protect();\n";
		if ( is_file( $file ) && file_get_contents( $file ) === $code ) {
			return;
		}
		file_put_contents( $file, $code );
	}

	private static function path() {
		$dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/jisento' : sys_get_temp_dir();
		return $dir . '/live-url.json';
	}

	private static function read() {
		$path = self::path();
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function write( array $data ) {
		$path = self::path();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		file_put_contents( $path, wp_json_encode( $data ) );
	}
}
