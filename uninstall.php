<?php
/**
 * Uninstall cleanup. Removes plugin tables and options. Does not delete user backup files unless empty.
 *
 * @package Jisento\Migration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'jisento_jobs',
	$wpdb->prefix . 'jisento_logs',
	$wpdb->prefix . 'jisento_keys',
	$wpdb->prefix . 'jisento_sessions',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'jisento_settings' );
delete_option( 'jisento_db_version' );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jisento\\_%'" );

// Live URL guard mu-plugin must not remain after uninstall.
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	foreach ( array( 'jisento-live-url.php', 'jisento-live-url.json' ) as $jisento_mu ) {
		$path = WPMU_PLUGIN_DIR . '/' . $jisento_mu;
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $path );
		}
	}
}
