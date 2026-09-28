<?php
/**
 * WordPress admin screens.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Admin;

use Jisento\Migration\Plugin;
use Jisento\Migration\Security\Capabilities;
use Jisento\Migration\Security\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const DOWNLOAD_CHUNK = 1048576;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_jisento_download', array( $this, 'download' ) );
		add_action( 'admin_post_jisento_log', array( $this, 'download_log' ) );
		add_filter( 'plugin_action_links_' . JISENTO_BASENAME, array( $this, 'action_links' ) );
	}

	public function menu() {
		$cap = Capabilities::CAP;
		add_menu_page(
			__( 'Jisento Migration', 'jisento' ),
			__( 'Jisento', 'jisento' ),
			$cap,
			'jisento',
			array( $this, 'page_migration' ),
			'dashicons-migrate',
			58
		);
		add_submenu_page( 'jisento', __( 'Migration', 'jisento' ), __( 'Migration', 'jisento' ), $cap, 'jisento', array( $this, 'page_migration' ) );
		add_submenu_page( 'jisento', __( 'Backups', 'jisento' ), __( 'Backups', 'jisento' ), $cap, 'jisento-backups', array( $this, 'page_backups' ) );
		add_submenu_page( 'jisento', __( 'Import', 'jisento' ), __( 'Import', 'jisento' ), $cap, 'jisento-import', array( $this, 'page_import' ) );
		add_submenu_page( 'jisento', __( 'Migration Keys', 'jisento' ), __( 'Migration Keys', 'jisento' ), $cap, 'jisento-keys', array( $this, 'page_keys' ) );
		add_submenu_page( 'jisento', __( 'Settings', 'jisento' ), __( 'Settings', 'jisento' ), $cap, 'jisento-settings', array( $this, 'page_settings' ) );
		add_submenu_page( 'jisento', __( 'Logs', 'jisento' ), __( 'Logs', 'jisento' ), $cap, 'jisento-logs', array( $this, 'page_logs' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'jisento' ) ) {
			return;
		}
		wp_enqueue_style( 'jisento-admin', JISENTO_URL . 'admin/css/admin.css', array(), JISENTO_VERSION );
		wp_enqueue_script( 'jisento-file-picker', JISENTO_URL . 'admin/js/file-picker.js', array(), JISENTO_VERSION, true );
		wp_enqueue_script( 'jisento-admin', JISENTO_URL . 'admin/js/admin.js', array( 'jisento-file-picker' ), JISENTO_VERSION, true );
		wp_localize_script(
			'jisento-admin',
			'jisentoAdmin',
			array(
				'root'  => esc_url_raw( rest_url( 'jisento/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'home'  => home_url(),
				'site'  => site_url(),
				'i18n'  => array(
					'running'   => __( 'Migration in Progress', 'jisento' ),
					'failed'    => __( 'Migration Failed', 'jisento' ),
					'complete'  => __( 'Migration Completed Successfully', 'jisento' ),
					'paused'    => __( 'Migration Interrupted', 'jisento' ),
				),
			)
		);
	}

	public function page_migration() {
		$this->render( 'migration' );
	}

	public function page_backups() {
		$this->render( 'backups' );
	}

	public function page_import() {
		$this->render( 'import' );
	}

	public function page_keys() {
		$this->render( 'keys' );
	}

	public function page_settings() {
		$settings = Plugin::instance()->settings->all();
		$this->render( 'settings', array( 'settings' => $settings ) );
	}

	public function page_logs() {
		$logs = Plugin::instance()->logger->query( '', 200 );
		$this->render( 'logs', array( 'logs' => $logs ) );
	}

	private function render( $view, array $data = array() ) {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to run migrations.', 'jisento' ) );
		}
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include JISENTO_PATH . 'admin/views/layout-start.php';
		include JISENTO_PATH . 'admin/views/' . $view . '.php';
		include JISENTO_PATH . 'admin/views/layout-end.php';
	}

	public function action_links( $links ) {
		$url     = admin_url( 'admin.php?page=jisento' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'jisento' ) . '</a>';
		return $links;
	}

	public function download() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'Forbidden', 'jisento' ), 403 );
		}
		check_admin_referer( 'jisento_download' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		$plugin   = Plugin::instance();
		$registry = new \Jisento\Migration\Package\Package_Registry();
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$file     = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		$path     = '';
		$filename = 'backup.jisento';
		$key      = '';

		if ( $id ) {
			$row = $registry->get( $id );
			if ( $row ) {
				$key      = $row->storage_key;
				$filename = $row->filename;
				$resolved = $plugin->storage->resolve( $key );
				$path     = $resolved ? $resolved['path'] : $plugin->storage->get_path( $key );
			}
		}

		if ( ! $path && $file ) {
			$safe = Guard::sanitize_archive_path( $file );
			if ( ! is_wp_error( $safe ) ) {
				$resolved = $plugin->storage->resolve( $safe );
				if ( $resolved ) {
					$path     = $resolved['path'];
					$key      = $resolved['key'];
					$filename = basename( $path );
				}
			}
		}

		$check = $registry->verify_file( $path );
		if ( empty( $check['ok'] ) ) {
			wp_die( esc_html( $check['reason'] ? $check['reason'] : __( 'Backup unavailable', 'jisento' ) ) );
		}

		$size   = (int) $check['size'];
		$range  = isset( $_SERVER['HTTP_RANGE'] ) ? (string) wp_unslash( $_SERVER['HTTP_RANGE'] ) : '';
		$parsed = self::parse_byte_range( $range, $size );
		if ( is_wp_error( $parsed ) ) {
			status_header( 416 );
			nocache_headers();
			header( 'Accept-Ranges: bytes' );
			header( 'Content-Range: bytes */' . $size );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-Accel-Buffering: no' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			exit;
		}

		$start   = $parsed['start'];
		$end     = $parsed['end'];
		$length  = $end - $start + 1;
		$partial = ! empty( $parsed['partial'] );

		nocache_headers();
		if ( $partial ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );

		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			wp_die( esc_html__( 'Unable to read the package file.', 'jisento' ) );
		}
		self::stream_file_range( $fp, $start, $length );
		fclose( $fp );
		exit;
	}

	/**
	 * Parse a single HTTP Range request for a download.
	 *
	 * @param string $header Raw Range header (e.g. "bytes=100-199"), or empty for the whole file.
	 * @param int    $size   File size in bytes.
	 * @return array{start:int,end:int,partial:bool}|\WP_Error
	 */
	public static function parse_byte_range( $header, $size ) {
		$size = (int) $size;
		if ( $size <= 0 ) {
			return new \WP_Error( 'jisento_range', 'empty' );
		}
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return array(
				'start'   => 0,
				'end'     => $size - 1,
				'partial' => false,
			);
		}
		// Reject multi-range and non-bytes units.
		if ( ! preg_match( '/^bytes=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m ) ) {
			return new \WP_Error( 'jisento_range', 'invalid' );
		}
		if ( '' === $m[1] && '' === $m[2] ) {
			return new \WP_Error( 'jisento_range', 'invalid' );
		}
		if ( '' === $m[1] ) {
			$suffix = (int) $m[2];
			if ( $suffix <= 0 ) {
				return new \WP_Error( 'jisento_range', 'invalid' );
			}
			$start = max( 0, $size - $suffix );
			$end   = $size - 1;
		} elseif ( '' === $m[2] ) {
			$start = (int) $m[1];
			$end   = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = (int) $m[2];
		}
		if ( $start < 0 || $end < $start || $start >= $size ) {
			return new \WP_Error( 'jisento_range', 'unsatisfiable' );
		}
		if ( $end >= $size ) {
			$end = $size - 1;
		}
		return array(
			'start'   => $start,
			'end'     => $end,
			'partial' => ( 0 !== $start || $end !== $size - 1 ),
		);
	}

	/**
	 * Stream $length bytes from $start. Stops on connection abort or fread failure.
	 *
	 * @param resource $fp     Open file handle.
	 * @param int      $start  Byte offset.
	 * @param int      $length Bytes to send.
	 * @return int Bytes written.
	 */
	public static function stream_file_range( $fp, $start, $length ) {
		$length = (int) $length;
		$start  = (int) $start;
		if ( $length <= 0 || ! is_resource( $fp ) ) {
			return 0;
		}
		if ( 0 !== fseek( $fp, $start ) ) {
			return 0;
		}
		$sent = 0;
		while ( $sent < $length ) {
			if ( connection_aborted() ) {
				break;
			}
			$want  = min( self::DOWNLOAD_CHUNK, $length - $sent );
			$chunk = fread( $fp, $want );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$sent += strlen( $chunk );
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}
		return $sent;
	}

	public function download_log() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'Forbidden', 'jisento' ), 403 );
		}
		check_admin_referer( 'jisento_log' );
		$id = isset( $_GET['migration_id'] ) ? sanitize_text_field( wp_unslash( $_GET['migration_id'] ) ) : '';
		$text = Plugin::instance()->logger->export_text( $id );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="jisento-debug-log.txt"' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
