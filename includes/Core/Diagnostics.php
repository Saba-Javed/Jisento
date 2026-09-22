<?php
/**
 * Hosting diagnostics for migration failures.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

use Jisento\Migration\Package\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostics {

	public function run() {
		$disk = @disk_free_space( WP_CONTENT_DIR );
		$items = array(
			array( 'label' => __( 'PHP Version', 'jisento' ), 'value' => PHP_VERSION, 'ok' => version_compare( PHP_VERSION, '7.4', '>=' ) ),
			array( 'label' => __( 'WordPress Version', 'jisento' ), 'value' => get_bloginfo( 'version' ), 'ok' => true ),
			array( 'label' => __( 'ZipArchive', 'jisento' ), 'value' => Archive::zip_available() ? 'Yes' : 'No', 'ok' => Archive::zip_available() ),
			array( 'label' => __( 'cURL', 'jisento' ), 'value' => function_exists( 'curl_init' ) ? 'Yes' : 'No', 'ok' => function_exists( 'curl_init' ) ),
			array( 'label' => __( 'OpenSSL', 'jisento' ), 'value' => extension_loaded( 'openssl' ) ? 'Yes' : 'No', 'ok' => extension_loaded( 'openssl' ) ),
			array( 'label' => __( 'Memory Limit', 'jisento' ), 'value' => ini_get( 'memory_limit' ), 'ok' => true ),
			array( 'label' => __( 'Max Execution Time', 'jisento' ), 'value' => (string) ini_get( 'max_execution_time' ), 'ok' => true ),
			array( 'label' => __( 'Upload Max Filesize', 'jisento' ), 'value' => ini_get( 'upload_max_filesize' ), 'ok' => true ),
			array( 'label' => __( 'Post Max Size', 'jisento' ), 'value' => ini_get( 'post_max_size' ), 'ok' => true ),
			array( 'label' => __( 'Disk Space', 'jisento' ), 'value' => false === $disk ? 'Unknown' : size_format( $disk ), 'ok' => false === $disk || $disk > 20 * 1024 * 1024 ),
			array( 'label' => __( 'REST API', 'jisento' ), 'value' => rest_url( 'jisento/v1/' ), 'ok' => true ),
			array( 'label' => __( 'Filesystem Writable', 'jisento' ), 'value' => is_writable( WP_CONTENT_DIR ) ? 'Yes' : 'No', 'ok' => is_writable( WP_CONTENT_DIR ) ),
			array( 'label' => __( 'HTTPS', 'jisento' ), 'value' => is_ssl() ? 'Yes' : 'No', 'ok' => is_ssl() || $this->is_local() ),
		);

		$loopback = $this->loopback();
		$items[]  = array( 'label' => __( 'Loopback Requests', 'jisento' ), 'value' => $loopback['label'], 'ok' => $loopback['ok'] );

		$outbound = $this->outbound();
		$items[]  = array( 'label' => __( 'Outbound Connections', 'jisento' ), 'value' => $outbound['label'], 'ok' => $outbound['ok'] );

		return array(
			'items'  => $items,
			'remote' => array(
				'https'               => is_ssl() || $this->is_local(),
				'rest_api'            => true,
				'outbound'            => $outbound['ok'],
				'remote_http_requests'=> $outbound['ok'],
			),
		);
	}

	private function is_local() {
		return \Jisento\Migration\Security\Guard::is_local_dev();
	}

	private function loopback() {
		$url      = rest_url( 'jisento/v1/compatibility' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'cookies' => $_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'label' => $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		return array( 'ok' => $code < 500, 'label' => 'HTTP ' . $code );
	}

	private function outbound() {
		$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'label' => $response->get_error_message() );
		}
		return array( 'ok' => wp_remote_retrieve_response_code( $response ) < 500, 'label' => 'Yes' );
	}
}
