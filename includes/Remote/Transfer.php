<?php
/**
 * Remote source/destination transfer using a short-lived session.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Remote;

use Jisento\Migration\Filesystem\File_System;
use Jisento\Migration\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transfer {

	public function site_info() {
		global $wpdb;
		$like  = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$db_size = 0;
		foreach ( $tables as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
			if ( $status ) {
				$db_size += (int) $status['Data_length'] + (int) $status['Index_length'];
			}
		}

		$files_size = $this->dir_size( WP_CONTENT_DIR );

		return array(
			'domain'            => wp_parse_url( home_url(), PHP_URL_HOST ),
			'site_url'          => site_url(),
			'home_url'          => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'database'          => File_System::readable_size( $db_size ),
			'database_bytes'    => $db_size,
			'files'             => File_System::readable_size( $files_size ),
			'files_bytes'       => $files_size,
			'total'             => File_System::readable_size( $db_size + $files_size ),
			'total_bytes'       => $db_size + $files_size,
			'table_count'       => count( $tables ),
			'prefix'            => $wpdb->prefix,
			'plugin_version'    => JISENTO_VERSION,
		);
	}

	private function dir_size( $dir ) {
		$size = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}
		return $size;
	}

	public function request( $url, $method, array $headers, $body = null, $timeout = 45 ) {
		$args = array(
			'method'    => $method,
			'timeout'   => $timeout,
			'headers'   => $headers,
			'body'      => $body,
			'sslverify' => true,
		);
		if ( \Jisento\Migration\Security\Guard::is_local_dev() ) {
			$args['sslverify'] = false;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_trim_words( wp_strip_all_tags( $raw ), 30 );
			$error   = new \WP_Error(
				'jisento_remote',
				sprintf( 'HTTP %d: %s', $code, $message ? $message : __( 'Remote request failed.', 'jisento' ) ),
				array( 'status' => $code )
			);
			return $error;
		}
		return is_array( $data ) ? $data : array();
	}

	public function connect( $source_url, $key ) {
		$source_url = untrailingslashit( esc_url_raw( $source_url ) );
		if ( ! $source_url ) {
			return new \WP_Error( 'jisento_url', __( 'A valid source site URL is required.', 'jisento' ) );
		}
		$https = 0 === strpos( $source_url, 'https://' );
		$settings = Plugin::instance()->settings;
		if ( $settings->get( 'https_required', true ) && ! $https && ! \Jisento\Migration\Security\Guard::is_local_dev() ) {
			return new \WP_Error( 'jisento_https_required', __( 'Remote migration requires HTTPS.', 'jisento' ) );
		}

		$endpoint = $source_url . '/wp-json/jisento/v1/remote/handshake';
		return $this->request(
			$endpoint,
			'POST',
			array(
				'Content-Type' => 'application/json',
			),
			wp_json_encode(
				array(
					'key'      => $key,
					'peer'     => home_url(),
					'peer_wp'  => get_bloginfo( 'version' ),
					'peer_php' => PHP_VERSION,
				)
			)
		);
	}

	public function test_connection( $source_url, $key ) {
		$checks = array();
		$source_url = untrailingslashit( esc_url_raw( $source_url ) );
		$checks[] = array( 'id' => 'url', 'label' => __( 'Source reachable', 'jisento' ), 'ok' => false );

		$probe = wp_remote_get(
			$source_url . '/wp-json/',
			array(
				'timeout'   => 15,
				'sslverify' => ! \Jisento\Migration\Security\Guard::is_local_dev(),
			)
		);
		if ( is_wp_error( $probe ) ) {
			$checks[0]['detail'] = $probe->get_error_message();
			return array( 'ok' => false, 'checks' => $checks );
		}
		$code = (int) wp_remote_retrieve_response_code( $probe );
		$checks[0]['ok']     = $code >= 200 && $code < 500;
		$checks[0]['detail'] = 'HTTP ' . $code;

		$routes = json_decode( wp_remote_retrieve_body( $probe ), true );
		$has    = is_array( $routes ) && isset( $routes['routes']['/jisento/v1/remote/handshake'] );
		if ( ! $has ) {
			$alt = wp_remote_get( $source_url . '/wp-json/jisento/v1/compatibility', array( 'timeout' => 10, 'sslverify' => false ) );
			$has = ! is_wp_error( $alt ) && wp_remote_retrieve_response_code( $alt ) < 500;
		}
		$checks[] = array( 'id' => 'plugin', 'label' => __( 'Jisento detected', 'jisento' ), 'ok' => (bool) $has );

		$handshake = $this->request(
			$source_url . '/wp-json/jisento/v1/remote/probe',
			'POST',
			array( 'Content-Type' => 'application/json' ),
			wp_json_encode( array( 'key' => $key, 'peer' => home_url() ) )
		);
		$key_ok    = ! is_wp_error( $handshake ) && ! empty( $handshake['valid'] );
		$checks[]  = array(
			'id'     => 'key',
			'label'  => __( 'Migration key valid', 'jisento' ),
			'ok'     => $key_ok,
			'detail' => is_wp_error( $handshake ) ? $handshake->get_error_message() : '',
		);
		$checks[] = array( 'id' => 'session', 'label' => __( 'Session can be established', 'jisento' ), 'ok' => $key_ok );
		$checks[] = array( 'id' => 'transfer', 'label' => __( 'Remote transfer available', 'jisento' ), 'ok' => $key_ok );

		$all = true;
		foreach ( $checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$all = false;
			}
		}

		return array(
			'ok'       => $all,
			'checks'   => $checks,
			'session'  => $key_ok ? $handshake : null,
		);
	}

	public function download_package_chunk( $source_url, $session_id, $token, $job_id, $offset, $length, $dest_file, $migration_id = '' ) {
		$url      = untrailingslashit( $source_url ) . '/wp-json/jisento/v1/remote/chunk';
		$chunk_id = (int) floor( $offset / max( 1, $length ) );
		Plugin::instance()->logger->log(
			$migration_id,
			'chunk',
			'request',
			'chunk-' . $chunk_id,
			'info',
			sprintf( 'offset=%d length=%d job=%s', $offset, $length, $job_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 90,
				'redirection' => 0,
				'compress'    => false,
				'decompress'  => false,
				'sslverify'   => ! \Jisento\Migration\Security\Guard::is_local_dev(),
				'headers'     => array(
					'Content-Type'            => 'application/json',
					'Accept'                  => 'application/octet-stream',
					'X-Jisento-Session'       => $session_id,
					'X-Jisento-Session-Token' => $token,
				),
				'body'        => wp_json_encode(
					array(
						'job_id'         => $job_id,
						'offset'         => $offset,
						'length'         => $length,
						'session_id'     => $session_id,
						'session_token'  => $token,
						'chunk_index'    => $chunk_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Plugin::instance()->logger->log( $migration_id, 'chunk', 'error', 'chunk-' . $chunk_id, 'error', $response->get_error_message() );
			return new \WP_Error(
				'jisento_chunk',
				sprintf( __( 'Chunk download failed (network): %s', 'jisento' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$hash = wp_remote_retrieve_header( $response, 'x-jisento-sha256' );

		Plugin::instance()->logger->log(
			$migration_id,
			'chunk',
			'response',
			'chunk-' . $chunk_id,
			$code >= 400 ? 'error' : 'ok',
			sprintf( 'HTTP %d bytes=%d hash=%s', $code, strlen( (string) $body ), $hash ? 'yes' : 'no' )
		);

		if ( $code >= 400 ) {
			$parsed  = json_decode( $body, true );
			$reason  = is_array( $parsed ) && isset( $parsed['message'] ) ? $parsed['message'] : wp_trim_words( wp_strip_all_tags( (string) $body ), 20 );
			return new \WP_Error(
				'jisento_chunk',
				sprintf( __( 'Chunk download failed. HTTP Status: %1$d. Reason: %2$s. Chunk: %3$d.', 'jisento' ), $code, $reason, $chunk_id ),
				array( 'status' => $code )
			);
		}

		$json = json_decode( $body, true );
		if ( is_array( $json ) && ! empty( $json['data'] ) ) {
			$data = base64_decode( $json['data'] );
			if ( ! empty( $json['hash'] ) ) {
				$hash = $json['hash'];
			}
		} else {
			$data = $body;
		}

		if ( ! is_string( $data ) || '' === $data && 0 !== $offset ) {
			if ( 0 === strlen( (string) $data ) && $offset > 0 ) {
				return array( 'bytes' => 0, 'hash' => '', 'eof' => true );
			}
		}

		if ( $hash && hash( 'sha256', $data ) !== $hash ) {
			return new \WP_Error( 'jisento_checksum', sprintf( __( 'Chunk checksum mismatch for chunk %d. The transfer will retry.', 'jisento' ), $chunk_id ) );
		}

		wp_mkdir_p( dirname( $dest_file ) );
		$fp = fopen( $dest_file, file_exists( $dest_file ) ? 'c+b' : 'wb' );
		if ( ! $fp ) {
			return new \WP_Error( 'jisento_write', __( 'Unable to write transferred chunk.', 'jisento' ) );
		}
		fseek( $fp, (int) $offset );
		$written = fwrite( $fp, $data );
		fclose( $fp );

		return array(
			'bytes' => (int) $written,
			'hash'  => $hash,
			'eof'   => strlen( $data ) < $length,
		);
	}
}
