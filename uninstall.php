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
