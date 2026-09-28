<?php
/**
 * Shadow-table restore: statement classification, names and guards (no database needed).
 * CREATE TABLE handling and the real swap are covered by tests/roundtrip-test.php.
 *
 * Run: php tests/shadow-import-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Security\Admin_Guard;

$GLOBALS['wpdb'] = null;

$all = new Database_Importer( 'src_', 'wp_', array( 'job_id' => 'job_shadow' ) );

$insert = "INSERT INTO `src_users` (`ID`, `user_login`, `user_pass`) VALUES (1, 'admin', 'src_users');";
$row    = $all->classify( $insert );
check( 'insert goes to the shadow of the destination table', is_array( $row ) && 'insert' === $row['kind'] && 'wp_users' === $row['table'] && 0 === strpos( $row['sql'], 'INSERT INTO `wp_users__js`' ), is_array( $row ) ? $row['sql'] : '' );
check( 'insert column names stay unchanged', is_array( $row ) && false !== strpos( $row['sql'], '(`ID`, `user_login`, `user_pass`)' ) );
check( 'insert values are not rewritten', is_array( $row ) && false !== strpos( $row['sql'], "'src_users'" ) );

$drop = $all->classify( 'DROP TABLE IF EXISTS `src_usermeta`;' );
check( 'drop only ever hits the shadow name', is_array( $drop ) && 'ddl' === $drop['kind'] && 'DROP TABLE IF EXISTS `wp_usermeta__js`' === $drop['sql'] );

$kept = new Database_Importer( 'src_', 'wp_', array( 'keep' => array( 'wp_options' ), 'restore' => array( 'wp_posts' ) ) );
foreach ( array( 'DROP TABLE IF EXISTS `src_options`;', 'CREATE TABLE `src_options` (`a` int)', "INSERT INTO `src_options` VALUES (1);" ) as $sql ) {
	$res = $kept->classify( $sql );
	check( 'kept table is skipped: ' . substr( $sql, 0, 20 ), is_array( $res ) && 'skip' === $res['kind'] );
}
$res = $kept->classify( "INSERT INTO `src_links` VALUES (1);" );
check( 'table outside the restore list is skipped', is_array( $res ) && 'skip' === $res['kind'] );
$res = $kept->classify( "INSERT INTO `src_posts` VALUES (1);" );
check( 'table in the restore list is shadowed', is_array( $res ) && 'insert' === $res['kind'] && 'wp_posts__js' === $res['shadow'] );

foreach ( array( 'TRUNCATE TABLE `wp_users`', 'DELETE FROM `wp_users`', 'ALTER TABLE `wp_users` ADD x int', 'DROP TABLE `wp_users`', 'REPLACE INTO `wp_users` VALUES (1)', 'UPDATE `wp_users` SET a=1' ) as $sql ) {
	$res = $all->classify( $sql );
	check( 'unsupported statement is rejected: ' . $sql, is_wp_error( $res ) && 'jisento_sql_unsupported' === $res->get_error_code() && false !== strpos( $res->get_error_message(), 'job_shadow' ) );
}
check( 'LOCK TABLES is skipped', 'skip' === $all->classify( 'LOCK TABLES `src_users` WRITE;' )['kind'] );

$long        = str_repeat( 't', 61 );
$long_shadow = Database_Importer::shadow_name( $long );
check( 'long shadow names fit in 64 characters', strlen( $long_shadow ) <= 64 && $long_shadow !== $long );
check( 'long shadow names of different tables differ', Database_Importer::shadow_name( $long . 'a' ) !== Database_Importer::shadow_name( $long . 'b' ) );
check( 'retired name is distinct', Database_Importer::retired_name( 'wp_users' ) !== 'wp_users' && Database_Importer::retired_name( 'wp_users' ) !== Database_Importer::shadow_name( 'wp_users' ) );
check( 'shadow constraint names fit in 64 characters', strlen( Database_Importer::shadow_constraint_name( str_repeat( 'c', 64 ) ) ) <= 64 );

$split = Database_Importer::split_create( "CREATE TABLE `t` (`a` varchar(5) DEFAULT ')', KEY `k` (`a`)) ENGINE=MyISAM ROW_FORMAT=FIXED" );
check( 'split_create ignores parentheses in strings', is_array( $split ) && ' ENGINE=MyISAM ROW_FORMAT=FIXED' === $split[1] );
check( 'MyISAM options become InnoDB', ' ENGINE=InnoDB' === Database_Importer::convert_engine_options( ' ENGINE=MyISAM ROW_FORMAT=FIXED' ) );
check( 'engines without an InnoDB equivalent are refused', null === Database_Importer::convert_engine_options( ' ENGINE=MRG_MyISAM' ) );
check( 'MySQL 8 collation maps to a MariaDB one', 'utf8mb4_unicode_520_ci' === Database_Importer::map_collation( 'utf8mb4_0900_ai_ci', array( 'utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci' ) ) );
check( 'MySQL 8 bin collation maps to utf8mb4_bin', 'utf8mb4_bin' === Database_Importer::map_collation( 'utf8mb4_0900_bin', array( 'utf8mb4_bin', 'utf8mb4_unicode_ci' ) ) );
check( 'uca1400 ai_ci candidate list prefers unicode_520 when uca1400 is absent', 'utf8mb4_unicode_520_ci' === Database_Importer::map_collation( 'utf8mb4_uca1400_ai_ci', array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci' ) ) );
check( 'uca1400 as_cs maps to charset_bin', 'utf8mb4_bin' === Database_Importer::map_collation( 'utf8mb4_uca1400_as_cs', array( 'utf8mb4_bin', 'utf8mb4_unicode_ci' ) ) );
check( 'utf8mb3 candidates also try utf8_', 'utf8_unicode_ci' === Database_Importer::map_collation( 'utf8mb3_uca1400_ai_ci', array( 'utf8_unicode_ci', 'utf8_general_ci' ) ) );
check( 'unknown collation without a safe equivalent fails', null === Database_Importer::map_collation( 'foo_bar', array( 'utf8mb4_unicode_ci' ) ) );
check( 'collation kept when listed as supported', 'utf8mb4_uca1400_ai_ci' === Database_Importer::map_collation( 'utf8mb4_uca1400_ai_ci', array( 'utf8mb4_uca1400_ai_ci' ) ) );
$cands = Database_Importer::collation_candidates( 'utf8mb4_uca1400_ai_ci' );
check( 'uca1400 ai_ci candidates include unicode_520 then unicode then general', in_array( 'utf8mb4_unicode_520_ci', $cands, true ) && in_array( 'utf8mb4_unicode_ci', $cands, true ) && in_array( 'utf8mb4_general_ci', $cands, true ) );

check( 'hostinger options are preserved', Live_Url::preserved_option( 'hostinger_onboarding' ) && Live_Url::preserved_option( 'hostinger-ai-builder' ) );
check( 'other options are not preserved', ! Live_Url::preserved_option( 'siteurl' ) && ! Live_Url::preserved_option( 'home' ) && ! Live_Url::preserved_option( 'my_hostinger' ) );
check( 'missing administrators are restored', Admin_Guard::should_restore( 0 ) );
check( 'existing administrators are left alone', ! Admin_Guard::should_restore( 1 ) && ! Admin_Guard::should_restore( -1 ) );

$importer = file_get_contents( JISENTO_PATH . 'includes/Database/Database_Importer.php' );
$swap     = substr( $importer, strpos( $importer, 'function swap_shadows' ), 4000 );
check( 'swap is a single RENAME TABLE', 1 === substr_count( $swap, "'RENAME TABLE '" ) );
check( 'importer probes CONVERT COLLATE instead of trusting SHOW COLLATION names', false !== strpos( $importer, "CONVERT('a' USING" ) && false !== strpos( $importer, 'collation_probes' ) && false !== strpos( $importer, 'collations_kept' ) );

$guard = file_get_contents( JISENTO_PATH . 'includes/Security/Admin_Guard.php' );
check( 'plugin updates are blocked during an import', false !== strpos( $guard, 'upgrader_pre_install' ) && false !== strpos( $guard, 'import_running' ) );

$import = file_get_contents( JISENTO_PATH . 'includes/Import/Importer.php' );
check( 'an empty imported users table does not replace the live one', false !== strpos( $import, 'shadow_users_empty' ) );
check( 'hostinger options are put back after url replacement', false !== strpos( $import, 'Live_Url::hold()' ) );

$admin_js = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check( 'runtime errors are converted to visible plain text', false !== strpos( $admin_js, 'plainError' ) && false !== strpos( $admin_js, 'textContent' ) );

jisento_test_finish();
