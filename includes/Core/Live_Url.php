<?php
/**
 * Keeps the destination site on its own URL until URL replacement finishes.
 *
 * An import captures the destination home, siteurl and active_plugins when it starts, and the
 * database restore is not allowed to leave the source values active. The pin only applies while
 * its import job is running: once the job completes, fails or is cancelled (or the job row is
 * gone) the pin is released and nothing is forced any more.
 *
 * Nothing here trusts the request Host header.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Live_Url {

	const GUARD_FILE   = 'jisento-live-url.php';
	const GUARD_CONFIG = 'jisento-live-url.json';

	public static function protect() {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return;
		}
		$pin = self::read();
		if ( ! is_array( $pin ) || ! empty( $pin['released'] ) || empty( $pin['home'] ) ) {
			return;
		}
		if ( ! self::job_running( isset( $pin['job_id'] ) ? (string) $pin['job_id'] : '' ) ) {
			self::release();
			return;
		}
		self::hold();
	}

	public static function capture( $job_id ) {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return;
		}
		$existing = self::read();
		if ( $existing && empty( $existing['released'] ) && isset( $existing['job_id'] ) && (string) $existing['job_id'] === (string) $job_id ) {
			self::install_guard();
			return;
		}
		self::write(
			array(
				'job_id'         => (string) $job_id,
				'home'           => self::db_option( 'home' ),
				'siteurl'        => self::db_option( 'siteurl' ),
				'active_plugins' => self::db_option( 'active_plugins' ),
				'template'       => self::db_option( 'template' ),
				'stylesheet'     => self::db_option( 'stylesheet' ),
				'hostinger'      => self::hostinger_options(),
				'released'       => false,
			)
		);
		self::install_guard();
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
		$imported_template = self::db_option( 'template' );
		if ( false !== $imported_template && (string) $imported_template !== (string) ( isset( $pin['template'] ) ? $pin['template'] : '' ) ) {
			$pin['imported_template'] = $imported_template;
			self::write( $pin );
		}
		$imported_style = self::db_option( 'stylesheet' );
		if ( false !== $imported_style && (string) $imported_style !== (string) ( isset( $pin['stylesheet'] ) ? $pin['stylesheet'] : '' ) ) {
			$pin['imported_stylesheet'] = $imported_style;
			self::write( $pin );
		}
		self::force_option( 'home', isset( $pin['home'] ) ? $pin['home'] : '' );
		self::force_option( 'siteurl', isset( $pin['siteurl'] ) ? $pin['siteurl'] : '' );
		if ( array_key_exists( 'active_plugins', $pin ) ) {
			self::force_option( 'active_plugins', $pin['active_plugins'] );
		}
		if ( array_key_exists( 'template', $pin ) && false !== $pin['template'] && null !== $pin['template'] && '' !== (string) $pin['template'] ) {
			self::force_option( 'template', $pin['template'] );
		}
		if ( array_key_exists( 'stylesheet', $pin ) && false !== $pin['stylesheet'] && null !== $pin['stylesheet'] && '' !== (string) $pin['stylesheet'] ) {
			self::force_option( 'stylesheet', $pin['stylesheet'] );
		}
		self::attach_theme_pins( $pin );
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

	/**
	 * Stop pinning. With a job id, only that job's pin is released.
	 * Applies imported active_plugins and theme when their files are present.
	 *
	 * @param string $job_id Optional job id.
	 * @return string[] Warnings (e.g. imported theme kept off because files were missing).
	 */
	public static function release( $job_id = '' ) {
		$warnings = array();
		$pin      = self::read();
		if ( $pin && ( '' === (string) $job_id || ( isset( $pin['job_id'] ) && (string) $pin['job_id'] === (string) $job_id ) ) ) {
			if ( empty( $pin['released'] ) && function_exists( 'update_option' ) ) {
				if ( isset( $pin['imported_active_plugins'] ) && is_array( $pin['imported_active_plugins'] ) ) {
					$skipped = isset( $pin['skipped_plugin_dirs'] ) && is_array( $pin['skipped_plugin_dirs'] ) ? $pin['skipped_plugin_dirs'] : array();
					$own     = defined( 'JISENTO_BASENAME' ) ? (string) JISENTO_BASENAME : '';
					update_option( 'active_plugins', self::filter_active_plugins( $pin['imported_active_plugins'], $skipped, $own ) );
				}
				$theme_warning = self::apply_imported_theme( $pin );
				if ( '' !== $theme_warning ) {
					$warnings[] = $theme_warning;
				}
			}
			$path = self::path();
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
		if ( ! self::read() ) {
			self::remove_guard();
		}
		return $warnings;
	}

	/**
	 * Do not activate the imported theme when this job's pin is released.
	 *
	 * @param string $job_id Job id.
	 */
	public static function skip_themes( $job_id ) {
		$pin = self::read();
		if ( ! $pin || ! empty( $pin['released'] ) || ! isset( $pin['job_id'] ) || (string) $pin['job_id'] !== (string) $job_id ) {
			return;
		}
		$pin['skip_themes'] = true;
		self::write( $pin );
	}

	/**
	 * Whether a theme (and its parent, for a child theme) can be loaded from disk.
	 *
	 * @param string $stylesheet Stylesheet slug.
	 * @param string $template   Template slug (parent for child themes).
	 * @return bool
	 */
	public static function theme_files_ready( $stylesheet, $template = '' ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return false;
		}
		$stylesheet = (string) $stylesheet;
		$template   = (string) $template;
		if ( '' === $stylesheet ) {
			return false;
		}
		$dir = WP_CONTENT_DIR . '/themes/' . $stylesheet;
		if ( ! is_dir( $dir ) || ! is_readable( $dir . '/style.css' ) ) {
			return false;
		}
		if ( '' !== $template && $template !== $stylesheet ) {
			$parent = WP_CONTENT_DIR . '/themes/' . $template;
			if ( ! is_dir( $parent ) || ! is_readable( $parent . '/style.css' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Activate the imported theme on release, or keep the destination theme with a warning.
	 *
	 * @param array $pin Pin data.
	 * @return string Warning message, or ''.
	 */
	public static function apply_imported_theme( array $pin ) {
		if ( ! empty( $pin['skip_themes'] ) ) {
			return __( 'The imported theme was not activated (--skip-themes). The destination theme stays active.', 'jisento' );
		}
		$style = isset( $pin['imported_stylesheet'] ) ? (string) $pin['imported_stylesheet'] : '';
		$tmpl  = isset( $pin['imported_template'] ) ? (string) $pin['imported_template'] : $style;
		if ( '' === $style ) {
			return '';
		}
		if ( ! self::theme_files_ready( $style, $tmpl ) ) {
			return sprintf(
				/* translators: 1: stylesheet slug, 2: template slug */
				__( 'The imported theme "%1$s" (template "%2$s") is incomplete on disk, so the destination theme was kept. Finish restoring theme files, then switch themes in Appearance, or resume with: wp jisento resume --job=<id> after the files are present.', 'jisento' ),
				$style,
				$tmpl
			);
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( 'stylesheet', $style );
			update_option( 'template', '' !== $tmpl ? $tmpl : $style );
		}
		return '';
	}

	/**
	 * Remember which plugin folders in the package were Jisento copies that the import skipped.
	 *
	 * @param string   $job_id Job id.
	 * @param string[] $dirs   Folder names under wp-content/plugins.
	 */
	public static function skip_plugin_dirs( $job_id, array $dirs ) {
		$pin = self::read();
		if ( ! $pin || ! empty( $pin['released'] ) || ! isset( $pin['job_id'] ) || (string) $pin['job_id'] !== (string) $job_id ) {
			return;
		}
		$pin['skipped_plugin_dirs'] = array_values( array_unique( array_map( 'strval', $dirs ) ) );
		self::write( $pin );
	}

	/**
	 * The source's active_plugins as they apply here: entries for skipped Jisento copies are
	 * removed (their folder was not restored, or holds a different install), and this plugin
	 * stays active under its own folder name.
	 *
	 * @param array    $plugins      active_plugins from the package.
	 * @param string[] $skipped_dirs Plugin folder names of skipped Jisento copies.
	 * @param string   $own          This plugin's basename (JISENTO_BASENAME).
	 * @return string[]
	 */
	public static function filter_active_plugins( array $plugins, array $skipped_dirs, $own ) {
		$skip = array();
		foreach ( $skipped_dirs as $dir ) {
			$skip[ trim( str_replace( '\\', '/', (string) $dir ), '/' ) ] = true;
		}
		$out = array();
		foreach ( $plugins as $entry ) {
			$entry = (string) $entry;
			$slash = strpos( $entry, '/' );
			if ( false !== $slash && isset( $skip[ substr( $entry, 0, $slash ) ] ) ) {
				continue;
			}
			if ( ! in_array( $entry, $out, true ) ) {
				$out[] = $entry;
			}
		}
		$own = (string) $own;
		if ( '' !== $own && ! in_array( $own, $out, true ) ) {
			$out[] = $own;
		}
		return $out;
	}

	/**
	 * Pin template/stylesheet for the rest of this request so a half-restored theme cannot load.
	 *
	 * @param array $pin Pin data.
	 */
	private static function attach_theme_pins( array $pin ) {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		$template   = isset( $pin['template'] ) ? (string) $pin['template'] : '';
		$stylesheet = isset( $pin['stylesheet'] ) ? (string) $pin['stylesheet'] : '';
		if ( '' === $template && '' === $stylesheet ) {
			return;
		}
		if ( ! empty( $GLOBALS['jisento_theme_pins_attached'] ) ) {
			return;
		}
		$GLOBALS['jisento_theme_pins_attached'] = true;
		if ( '' !== $template ) {
			add_filter(
				'pre_option_template',
				static function () use ( $template ) {
					return $template;
				}
			);
		}
		if ( '' !== $stylesheet ) {
			add_filter(
				'pre_option_stylesheet',
				static function () use ( $stylesheet ) {
					return $stylesheet;
				}
			);
		}
	}

	/**
	 * mu-plugin source. It holds no absolute paths: the plugin folder name is read at runtime
	 * from a JSON file next to it, and every step is guarded so a missing or moved plugin can
	 * never cause a fatal error.
	 *
	 * @return string
	 */
	public static function guard_code() {
		return <<<'PHP'
<?php
/**
 * Jisento Migration: keeps this site on its own URL while an import runs.
 * Written and removed by the Jisento Migration plugin. Safe to delete.
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}
call_user_func(
	function () {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return;
		}
		$config = __DIR__ . '/jisento-live-url.json';
		if ( ! is_readable( $config ) ) {
			return;
		}
		$data = json_decode( (string) @file_get_contents( $config ), true );
		if ( ! is_array( $data ) || ! isset( $data['plugin_dir'] ) || ! is_string( $data['plugin_dir'] ) ) {
			return;
		}
		$dir = $data['plugin_dir'];
		// Any single path segment, including spaces; never a separator, NUL, "." or "..".
		if ( '' === $dir || '.' === $dir || '..' === $dir || strlen( $dir ) !== strcspn( $dir, "/\\\0" ) ) {
			return;
		}
		$file = WP_PLUGIN_DIR . '/' . $dir . '/includes/Core/Live_Url.php';
		if ( ! is_file( $file ) ) {
			return;
		}
		try {
			require_once $file;
			if ( class_exists( '\Jisento\Migration\Core\Live_Url', false ) ) {
				\Jisento\Migration\Core\Live_Url::protect();
			}
		} catch ( \Throwable $e ) {
			error_log( 'Jisento live URL guard: ' . $e->getMessage() );
		}
	}
);

PHP;
	}

	/**
	 * @return string Folder name of this plugin under WP_PLUGIN_DIR, or '' when it is not there.
	 */
	public static function plugin_dir_name() {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! defined( 'JISENTO_PATH' ) ) {
			return '';
		}
		$plugins = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );
		$own     = rtrim( str_replace( '\\', '/', JISENTO_PATH ), '/' );
		if ( dirname( $own ) !== $plugins ) {
			return '';
		}
		return basename( $own );
	}

	private static function job_running( $job_id ) {
		if ( '' === $job_id ) {
			return false;
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'jisento_jobs';
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$status   = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE job_id = %s", $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $suppress ) {
			$wpdb->suppress_errors( $suppress );
		}
		if ( null === $status && '' !== (string) $wpdb->last_error ) {
			// The jobs table cannot be read (for example mid-swap); keep the pin rather than guess.
			return true;
		}
		return in_array( (string) $status, array( 'running', 'paused' ), true );
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
		$stored = ( is_array( $value ) || is_object( $value ) ) ? serialize( $value ) : (string) $value;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $stored, $name ) );
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
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! function_exists( 'wp_mkdir_p' ) ) {
			return;
		}
		$dir_name = self::plugin_dir_name();
		if ( '' === $dir_name ) {
			return;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return;
		}
		$config = WPMU_PLUGIN_DIR . '/' . self::GUARD_CONFIG;
		$json   = json_encode( array( 'plugin_dir' => $dir_name ) );
		if ( ! is_file( $config ) || file_get_contents( $config ) !== $json ) {
			file_put_contents( $config, $json );
		}
		$file = WPMU_PLUGIN_DIR . '/' . self::GUARD_FILE;
		$code = self::guard_code();
		if ( is_file( $file ) && file_get_contents( $file ) === $code ) {
			return;
		}
		// Write beside the target and rename, so a request never loads a half-written mu-plugin.
		$tmp = $file . '.jisento-tmp';
		if ( false !== file_put_contents( $tmp, $code ) && strlen( $code ) === (int) filesize( $tmp ) ) {
			@rename( $tmp, $file );
		}
		@unlink( $tmp );
	}

	/**
	 * Remove the mu-plugin and its config. Also replaces a copy restored from an old package whose
	 * require_once points at another server's path.
	 */
	public static function remove_guard() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}
		foreach ( array( self::GUARD_FILE, self::GUARD_CONFIG ) as $name ) {
			$path = WPMU_PLUGIN_DIR . '/' . $name;
			if ( is_file( $path ) ) {
				@unlink( $path );
			}
		}
	}

	/**
	 * A mu-plugin from plugin versions up to 1.2.11 hard-codes an absolute require_once path.
	 * If it was copied from another server it fatals every request; replace or remove it.
	 */
	public static function repair_guard() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}
		$file = WPMU_PLUGIN_DIR . '/' . self::GUARD_FILE;
		if ( ! is_file( $file ) ) {
			return;
		}
		$code = (string) @file_get_contents( $file );
		if ( $code === self::guard_code() ) {
			return;
		}
		if ( self::read() ) {
			@unlink( $file );
			self::install_guard();
		} else {
			self::remove_guard();
		}
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
