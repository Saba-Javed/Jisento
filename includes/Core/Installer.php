<?php
/**
 * Database installer for job, log, key, and session tables.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = $wpdb->prefix . 'jisento_jobs';
		$logs    = $wpdb->prefix . 'jisento_logs';
		$keys    = $wpdb->prefix . 'jisento_keys';
		$sess    = $wpdb->prefix . 'jisento_sessions';
		$packs   = $wpdb->prefix . 'jisento_packages';

		$sql = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id varchar(64) NOT NULL,
			type varchar(32) NOT NULL,
			status varchar(32) NOT NULL,
			stage varchar(64) NOT NULL DEFAULT '',
			progress tinyint(3) unsigned NOT NULL DEFAULT 0,
			bytes_done bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_total bigint(20) unsigned NOT NULL DEFAULT 0,
			current_item text NULL,
			state_json longtext NULL,
			error_summary text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY status (status)
		) {$charset};

		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			migration_id varchar(64) NOT NULL DEFAULT '',
			stage varchar(64) NOT NULL DEFAULT '',
			operation varchar(128) NOT NULL DEFAULT '',
			item varchar(255) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'info',
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY migration_id (migration_id),
			KEY created_at (created_at)
		) {$charset};

		CREATE TABLE {$keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_hash varchar(64) NOT NULL,
			key_hint varchar(24) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			single_use tinyint(1) NOT NULL DEFAULT 1,
			expires_at datetime NOT NULL,
			used_at datetime NULL,
			created_at datetime NOT NULL,
			meta_json text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY status (status)
		) {$charset};

		CREATE TABLE {$sess} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			token_hash varchar(64) NOT NULL,
			key_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			peer_host varchar(255) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			meta_json text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY token_hash (token_hash)
		) {$charset};

		CREATE TABLE {$packs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			migration_id varchar(64) NOT NULL DEFAULT '',
			filename varchar(255) NOT NULL DEFAULT '',
			storage_key varchar(255) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			checksum varchar(64) NOT NULL DEFAULT '',
			type varchar(32) NOT NULL DEFAULT 'manual',
			status varchar(20) NOT NULL DEFAULT 'processing',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY storage_key (storage_key),
			KEY migration_id (migration_id)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'jisento_db_version', \Jisento\Migration\Plugin::DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		global $wpdb;
		$installed = get_option( 'jisento_db_version' );
		$packs     = $wpdb->prefix . 'jisento_packages';
		$jobs      = $wpdb->prefix . 'jisento_jobs';
		$have_packs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $packs ) );
		$have_jobs  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs ) );
		if ( $installed !== \Jisento\Migration\Plugin::DB_VERSION || $packs !== $have_packs || $jobs !== $have_jobs ) {
			self::install();
		}
	}
}
