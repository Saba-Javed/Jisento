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

global $wpdb;

$settings = get_option( 'jisento_settings', array() );
$delete_backups = is_array( $settings ) && ! empty( $settings['delete_backups_on_uninstall'] );

$tables = array(
	$wpdb->prefix . 'jisento_jobs',
	$wpdb->prefix . 'jisento_logs',
	$wpdb->prefix . 'jisento_keys',
	$wpdb->prefix . 'jisento_sessions',
	$wpdb->prefix . 'jisento_packages',
	$wpdb->prefix . 'jisento_locks',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

wp_clear_scheduled_hook( 'jisento_maintenance' );
wp_clear_scheduled_hook( 'jisento_job_tick' );

if ( $delete_backups ) {
	$suffix = get_option( 'jisento_storage_suffix', '' );
	$roots  = array();
	if ( is_string( $suffix ) && '' !== $suffix && function_exists( 'wp_upload_dir' ) ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			$roots[] = rtrim( (string) $uploads['basedir'], '/\\' ) . '/jisento-' . $suffix;
		}
	}
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$roots[] = WP_CONTENT_DIR . '/jisento';
	}
	foreach ( array_unique( $roots ) as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $root );
	}
}

delete_option( 'jisento_settings' );
delete_option( 'jisento_db_version' );
delete_option( 'jisento_storage_suffix' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jisento\\_%'" );

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	foreach ( array( 'jisento-live-url.php', 'jisento-live-url.json' ) as $jisento_mu ) {
		$path = WPMU_PLUGIN_DIR . '/' . $jisento_mu;
		if ( is_file( $path ) ) {
			@unlink( $path );
		}
	}
}
