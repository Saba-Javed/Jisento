<?php
/**
 * Hosting diagnostics for migration failures.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

use Jisento\Migration\Jobs\Job_Scheduler;
use Jisento\Migration\Package\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostics {

	/**
	 * All check ids, in display order.
	 *
	 * @return string[]
	 */
	public static function check_ids() {
		return array(
			'php',
			'wordpress',
			'zip',
			'curl',
			'openssl',
			'memory',
			'max_execution',
			'upload_max',
			'post_max',
			'disk',
			'rest',
			'writable',
			'https',
			'loopback',
			'outbound',
		);
	}

	/**
	 * Run every check, or one named check.
	 *
	 * @param string $only Optional check id.
	 * @return array{items:array,remote:array}
	 */
	public function run( $only = '' ) {
		$ids   = self::check_ids();
		$items = array();
		if ( '' !== (string) $only ) {
			$ids = array( sanitize_key( $only ) );
		}
		foreach ( $ids as $id ) {
			$item = $this->run_check( $id );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$outbound_ok = true;
		$loopback_ok = true;
		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) && 'outbound' === $item['id'] ) {
				$outbound_ok = ! empty( $item['ok'] );
			}
			if ( isset( $item['id'] ) && 'loopback' === $item['id'] ) {
				$loopback_ok = ! empty( $item['ok'] );
			}
		}

		return array(
			'items'  => $items,
			'remote' => array(
				'https'                => ( function_exists( 'is_ssl' ) && is_ssl() ) || $this->is_local(),
				'rest_api'             => true,
				'outbound'             => $outbound_ok,
				'remote_http_requests' => $outbound_ok,
				'loopback'             => $loopback_ok,
			),
		);
	}

	/**
	 * @param string $id Check id.
	 * @return array|null
	 */
	public function run_check( $id ) {
		switch ( $id ) {
			case 'php':
				return $this->item( $id, __( 'PHP Version', 'jisento' ), PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) );
			case 'wordpress':
				return $this->item( $id, __( 'WordPress Version', 'jisento' ), get_bloginfo( 'version' ), true );
			case 'zip':
				return $this->item( $id, __( 'ZipArchive', 'jisento' ), Archive::zip_available() ? 'Yes' : 'No', Archive::zip_available() );
			case 'curl':
				return $this->item( $id, __( 'cURL', 'jisento' ), function_exists( 'curl_init' ) ? 'Yes' : 'No', function_exists( 'curl_init' ) );
			case 'openssl':
				return $this->item( $id, __( 'OpenSSL', 'jisento' ), extension_loaded( 'openssl' ) ? 'Yes' : 'No', extension_loaded( 'openssl' ) );
			case 'memory':
				return $this->item( $id, __( 'Memory Limit', 'jisento' ), ini_get( 'memory_limit' ), true );
			case 'max_execution':
				return $this->item( $id, __( 'Max Execution Time', 'jisento' ), (string) ini_get( 'max_execution_time' ), true );
			case 'upload_max':
				return $this->item( $id, __( 'Upload Max Filesize', 'jisento' ), ini_get( 'upload_max_filesize' ), true );
			case 'post_max':
				return $this->item( $id, __( 'Post Max Size', 'jisento' ), ini_get( 'post_max_size' ), true );
			case 'disk':
				$disk = @disk_free_space( \WP_CONTENT_DIR );
				return $this->item(
					$id,
					__( 'Free disk space (reported by server)', 'jisento' ),
					false === $disk ? 'Unknown' : size_format( $disk ),
					false === $disk || $disk > 20 * 1024 * 1024,
					false,
					__( 'Your hosting plan may have a lower limit than this figure.', 'jisento' )
				);
			case 'rest':
				return $this->item( $id, __( 'REST API', 'jisento' ), rest_url( 'jisento/v1/' ), true );
			case 'writable':
				return $this->item( $id, __( 'Filesystem Writable', 'jisento' ), is_writable( \WP_CONTENT_DIR ) ? 'Yes' : 'No', is_writable( \WP_CONTENT_DIR ) );
			case 'https':
				$ssl = function_exists( 'is_ssl' ) && is_ssl();
				return $this->item( $id, __( 'HTTPS', 'jisento' ), $ssl ? 'Yes' : 'No', $ssl || $this->is_local() );
			case 'loopback':
				return $this->loopback();
			case 'outbound':
				return $this->outbound();
			default:
				return null;
		}
	}

	private function item( $id, $label, $value, $ok, $warn = false, $note = '' ) {
		$out = array(
			'id'    => $id,
			'label' => $label,
			'value' => $value,
			'ok'    => (bool) $ok,
			'warn'  => (bool) $warn,
		);
		if ( '' !== (string) $note ) {
			$out['note'] = $note;
			if ( ! $warn && $ok ) {
				$out['warn'] = true; // Soft note such as disk plan limits.
			}
		}
		return $out;
	}

	private function is_local() {
		return \Jisento\Migration\Security\Guard::is_local_dev();
	}

	private function loopback() {
		try {
			$ok = Job_Scheduler::probe_loopback();
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( $ok ) {
			return $this->item( 'loopback', __( 'Loopback Requests', 'jisento' ), __( 'Background processing works', 'jisento' ), true );
		}
		return $this->item(
			'loopback',
			__( 'Loopback Requests', 'jisento' ),
			__( 'Background processing is blocked by the server. Migrations still work, but keep this tab open.', 'jisento' ),
			false,
			true
		);
	}

	private function outbound() {
		$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return $this->item( 'outbound', __( 'Outbound Connections', 'jisento' ), $response->get_error_message(), false );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $this->item( 'outbound', __( 'Outbound Connections', 'jisento' ), $code < 500 ? 'Yes' : 'HTTP ' . $code, $code < 500 );
	}
}
