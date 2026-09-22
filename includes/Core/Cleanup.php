<?php
/**
 * Post-migration cleanup, cache flush, and verification.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanup {

	public function after_migration( array $report = array() ) {
		$this->flush_rewrites();
		$this->clear_caches();
		$this->elementor();
		$this->woocommerce();
		$this->verify( $report );
		return $report;
	}

	public function flush_rewrites() {
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	public function clear_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
			\LiteSpeed\Purge::purge_all();
		}

		$cache_dirs = array(
			WP_CONTENT_DIR . '/cache',
			WP_CONTENT_DIR . '/uploads/elementor/css',
			WP_CONTENT_DIR . '/et-cache',
		);
		foreach ( $cache_dirs as $dir ) {
			$this->empty_dir( $dir );
		}
	}

	public function elementor() {
		if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
			delete_post_meta_by_key( '_elementor_css' );
			return;
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$files = \Elementor\Plugin::$instance->files_manager;
				if ( $files && method_exists( $files, 'clear_cache' ) ) {
					$files->clear_cache();
				}
			} catch ( \Throwable $e ) {
				// Continue; cache clear is best-effort.
			}
		}
	}

	public function woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		delete_transient( 'wc_products_onsale' );
	}

	public function verify( array &$report ) {
		$report['verify'] = array(
			'theme'    => wp_get_theme()->exists(),
			'database' => $this->db_ok(),
			'uploads'  => is_dir( WP_CONTENT_DIR . '/uploads' ) && is_readable( WP_CONTENT_DIR . '/uploads' ),
			'plugins'  => is_dir( WP_PLUGIN_DIR ),
		);
		$home = get_option( 'home' );
		$site = get_option( 'siteurl' );
		if ( ! is_string( $home ) || '' === $home || ! is_string( $site ) || '' === $site ) {
			$report['errors'] = 1;
			$report['warnings'][] = __( 'WordPress home or site URL is empty after the restore.', 'jisento' );
		}
		$stylesheet = get_option( 'stylesheet' );
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			$report['warnings'][] = sprintf(
				/* translators: %s: theme stylesheet */
				__( 'Active theme %s is not installed on the destination.', 'jisento' ),
				$stylesheet
			);
		}
	}

	private function db_ok() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1' );
	}

	private function empty_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->empty_dir( $path );
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
	}

	public function remove_tmp( $job_id ) {
		$storage = \Jisento\Migration\Plugin::instance()->storage;
		$storage->delete_tree( $storage->tmp_dir( $job_id ) );
	}
}
