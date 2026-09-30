<?php
/**
 * Uninstall cleanup. Removes plugin tables, options, cron, and the live-URL mu-plugin.
 * Backup files are kept unless "Delete backups on uninstall" is enabled (default off).
 *
 * @package Jisento\Migration
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Uninstall runs without WP_Filesystem / plugin bootstrap.

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Run uninstall cleanup in function scope so no unprefixed globals are created.
 */
function jisento_uninstall() {
	global $wpdb;

	$jisento_settings = get_option( 'jisento_settings', array() );
	$jisento_delete_backups = is_array( $jisento_settings ) && ! empty( $jisento_settings['delete_backups_on_uninstall'] );

	$jisento_tables = array(
		$wpdb->prefix . 'jisento_jobs',
		$wpdb->prefix . 'jisento_logs',
		$wpdb->prefix . 'jisento_keys',
		$wpdb->prefix . 'jisento_sessions',
		$wpdb->prefix . 'jisento_packages',
		$wpdb->prefix . 'jisento_locks',
	);

	foreach ( $jisento_tables as $jisento_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$jisento_table}" );
	}

	wp_clear_scheduled_hook( 'jisento_maintenance' );
	wp_clear_scheduled_hook( 'jisento_job_tick' );

	if ( $jisento_delete_backups ) {
		$jisento_suffix = get_option( 'jisento_storage_suffix', '' );
		$jisento_roots  = array();
		if ( is_string( $jisento_suffix ) && '' !== $jisento_suffix && function_exists( 'wp_upload_dir' ) ) {
			$jisento_uploads = wp_upload_dir( null, false );
			if ( ! empty( $jisento_uploads['basedir'] ) ) {
				$jisento_roots[] = rtrim( (string) $jisento_uploads['basedir'], '/\\' ) . '/jisento-' . $jisento_suffix;
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$jisento_roots[] = WP_CONTENT_DIR . '/jisento';
		}
		foreach ( array_unique( $jisento_roots ) as $jisento_root ) {
			if ( ! is_dir( $jisento_root ) ) {
				continue;
			}
			$jisento_items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $jisento_root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $jisento_items as $jisento_item ) {
				if ( $jisento_item->isDir() ) {
					@rmdir( $jisento_item->getPathname() );
				} else {
					@unlink( $jisento_item->getPathname() );
				}
			}
			@rmdir( $jisento_root );
		}
	}

	delete_option( 'jisento_settings' );
	delete_option( 'jisento_db_version' );
	delete_option( 'jisento_storage_suffix' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jisento\\_%'" );

	if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
		foreach ( array( 'jisento-live-url.php', 'jisento-live-url.json' ) as $jisento_mu ) {
			$jisento_path = WPMU_PLUGIN_DIR . '/' . $jisento_mu;
			if ( is_file( $jisento_path ) ) {
				@unlink( $jisento_path );
			}
		}
	}
}

jisento_uninstall();
