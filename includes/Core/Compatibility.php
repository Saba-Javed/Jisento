<?php
/**
 * Compatibility checks before import/export.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

use Jisento\Migration\Package\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Compatibility {

	public function run( array $manifest = array() ) {
		$checks = array();

		$checks[] = $this->item( 'wordpress', __( 'WordPress detected', 'jisento' ), true, sprintf( 'WordPress %s', get_bloginfo( 'version' ) ) );

		$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
		$checks[] = $this->item( 'php', __( 'PHP version compatible', 'jisento' ), $php_ok, 'PHP ' . PHP_VERSION, $php_ok ? 'ok' : 'error' );

		if ( ! empty( $manifest['php_version'] ) && version_compare( PHP_VERSION, $manifest['php_version'], '<' ) ) {
			$checks[] = $this->item(
				'php_source',
				__( 'Destination PHP is older than the source site', 'jisento' ),
				true,
				sprintf( 'Source PHP %s / Destination PHP %s', $manifest['php_version'], PHP_VERSION ),
				'warn'
			);
		}

		global $wpdb;
		$db_ok = (bool) $wpdb->check_connection( false );
		$checks[] = $this->item( 'database', __( 'Database connection working', 'jisento' ), $db_ok, DB_NAME );

		$disk = $this->disk_free();
		$need = isset( $manifest['files_size'] ) ? (int) $manifest['files_size'] + (int) ( $manifest['database_size'] ?? 0 ) : 0;
		$disk_ok = $disk < 0 || $need === 0 || $disk > ( $need * 1.2 );
		$level = 'ok';
		if ( $disk >= 0 && $disk < 50 * 1024 * 1024 ) {
			$level = 'warn';
		}
		if ( ! $disk_ok ) {
			$level = 'error';
		}
		$checks[] = $this->item(
			'disk',
			__( 'Disk space sufficient', 'jisento' ),
			$disk_ok,
			$disk < 0 ? __( 'Unable to determine free space', 'jisento' ) : size_format( $disk ),
			$level
		);

		$exts = array( 'json', 'mbstring' );
		$missing = array();
		foreach ( $exts as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}
		$zip_ok = Archive::zip_available();
		if ( ! $zip_ok ) {
			$missing[] = 'zip';
		}
		$checks[] = $this->item(
			'extensions',
			__( 'Required PHP extensions available', 'jisento' ),
			empty( $missing ),
			empty( $missing ) ? 'json, mbstring, zip' : implode( ', ', $missing )
		);

		$writable = is_writable( WP_CONTENT_DIR );
		$checks[] = $this->item( 'permissions', __( 'Filesystem permissions', 'jisento' ), $writable, WP_CONTENT_DIR );

		$memory = $this->ini_bytes( ini_get( 'memory_limit' ) );
		$checks[] = $this->item(
			'memory',
			__( 'Available memory', 'jisento' ),
			true,
			ini_get( 'memory_limit' ),
			( $memory > 0 && $memory < 64 * 1024 * 1024 ) ? 'warn' : 'ok'
		);

		$max_exec = (int) ini_get( 'max_execution_time' );
		$checks[] = $this->item(
			'execution',
			__( 'PHP execution time', 'jisento' ),
			true,
			(string) $max_exec,
			( $max_exec > 0 && $max_exec < 30 ) ? 'warn' : 'ok'
		);

		$upload = $this->ini_bytes( ini_get( 'upload_max_filesize' ) );
		$checks[] = $this->item(
			'upload',
			__( 'Upload limits', 'jisento' ),
			true,
			ini_get( 'upload_max_filesize' ) . ' / ' . ini_get( 'post_max_size' ),
			( $upload > 0 && $upload < 8 * 1024 * 1024 ) ? 'warn' : 'ok'
		);

		$checks[] = $this->item( 'url', __( 'Destination URL', 'jisento' ), true, home_url() );

		$errors = 0;
		$warns  = 0;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['level'] ) {
				$errors++;
			}
			if ( 'warn' === $check['level'] ) {
				$warns++;
			}
		}

		return array(
			'checks'   => $checks,
			'can_run'  => 0 === $errors,
			'warnings' => $warns,
			'errors'   => $errors,
		);
	}

	private function item( $id, $label, $ok, $detail = '', $level = null ) {
		if ( null === $level ) {
			$level = $ok ? 'ok' : 'error';
		}
		return array(
			'id'     => $id,
			'label'  => $label,
			'ok'     => (bool) $ok,
			'detail' => $detail,
			'level'  => $level,
		);
	}

	private function disk_free() {
		$bytes = @disk_free_space( WP_CONTENT_DIR );
		return false === $bytes ? -1 : (int) $bytes;
	}

	private function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$num  = (float) $value;
		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// Fall through.
			case 'm':
				$num *= 1024;
				// Fall through.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}
}
