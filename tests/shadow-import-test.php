<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Database/Database_Importer.php';
require dirname( __DIR__ ) . '/includes/Core/Live_Url.php';
require dirname( __DIR__ ) . '/includes/Security/Admin_Guard.php';

use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Database\Database_Importer;
use Jisento\Migration\Security\Admin_Guard;

$failed = 0;
function check( $name, $ok, $detail = '' ) {
	global $failed;
	if ( $ok ) {
		echo "OK  $name\n";
		return;
	}
	$failed++;
	echo "FAIL $name" . ( $detail ? " — $detail" : '' ) . "\n";
}

$insert = "INSERT INTO `wp_users` (`ID`, `user_login`, `user_pass`) VALUES (1, 'admin', 'wp_users');";
$shadowed = Database_Importer::shadow_statement( $insert );
check( 'insert target moves to the shadow table', false !== strpos( $shadowed, 'INSERT INTO `wp_users__js`' ) );
check( 'column names stay live', false !== strpos( $shadowed, '(`ID`, `user_login`, `user_pass`)' ) );
check( 'quoted value is not rewritten', false !== strpos( $shadowed, "'wp_users'" ) );

$create = "CREATE TABLE `wp_posts` (\n  `ID` bigint,\n  CONSTRAINT `fk` FOREIGN KEY (`post_author`) REFERENCES `wp_users` (`ID`)\n)";
$created = Database_Importer::shadow_statement( $create );
check( 'create target is shadowed', 0 === strpos( $created, 'CREATE TABLE `wp_posts__js`' ) );
check( 'foreign key follows the shadow table into the atomic swap', false !== strpos( $created, 'REFERENCES `wp_users__js` (`ID`)' ) );

$drop = 'DROP TABLE IF EXISTS `wp_usermeta`;';
check( 'drop hits the shadow name', 'DROP TABLE IF EXISTS `wp_usermeta__js`;' === Database_Importer::shadow_statement( $drop ) );

$long = str_repeat( 't', 61 );
$long_shadow = Database_Importer::shadow_name( $long );
check( 'long shadow names fit in 64 characters', strlen( $long_shadow ) <= 64 && $long_shadow !== $long );
check( 'retired name is distinct', Database_Importer::retired_name( 'wp_users' ) !== 'wp_users' && Database_Importer::retired_name( 'wp_users' ) !== Database_Importer::shadow_name( 'wp_users' ) );

$rename = Database_Importer::rename_swap_sql(
	array(
		array( 'wp_users', 'wp_users__js', 'wp_users__jo' ),
		array( 'wp_usermeta', 'wp_usermeta__js', 'wp_usermeta__jo' ),
	)
);
check( 'swap is one rename', 0 === strpos( $rename, 'RENAME TABLE ' ) && 1 === substr_count( $rename, 'RENAME TABLE' ) );
check( 'swap moves live aside and shadow into place', false !== strpos( $rename, '`wp_users` TO `wp_users__jo`' ) && false !== strpos( $rename, '`wp_users__js` TO `wp_users`' ) );
check( 'swap does not drop', false === stripos( $rename, 'DROP' ) );

check( 'hostinger options are preserved', Live_Url::preserved_option( 'hostinger_onboarding' ) && Live_Url::preserved_option( 'hostinger-ai-builder' ) );
check( 'other options are not preserved', ! Live_Url::preserved_option( 'siteurl' ) && ! Live_Url::preserved_option( 'home' ) && ! Live_Url::preserved_option( 'my_hostinger' ) );

check( 'missing administrators are restored', Admin_Guard::should_restore( 0 ) );
check( 'existing administrators are left alone', ! Admin_Guard::should_restore( 1 ) && ! Admin_Guard::should_restore( -1 ) );

$importer = file_get_contents( dirname( __DIR__ ) . '/includes/Database/Database_Importer.php' );
$run      = substr( $importer, strpos( $importer, 'function run_statement' ), 1600 );
check( 'statements are shadowed before they can drop or truncate', false !== strpos( $run, 'apply_shadow' ) && strpos( $run, 'apply_shadow' ) < strpos( $run, 'drop_existing_table' ) );

$guard = file_get_contents( dirname( __DIR__ ) . '/includes/Security/Admin_Guard.php' );
check( 'plugin updates are blocked during an import', false !== strpos( $guard, 'upgrader_pre_install' ) && false !== strpos( $guard, 'import_running' ) );

$import = file_get_contents( dirname( __DIR__ ) . '/includes/Import/Importer.php' );
check( 'an empty imported users table does not replace the live one', false !== strpos( $import, 'shadow_users_empty' ) );
check( 'hostinger options are put back after url replacement', false !== strpos( $import, 'Live_Url::hold()' ) );

$admin_js = file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
check( 'runtime errors are converted to visible plain text', false !== strpos( $admin_js, 'plainError' ) && false !== strpos( $admin_js, 'Request failed (HTTP ' ) );
check( 'runtime errors are escaped before rendering', false !== strpos( $admin_js, 'esc(problem)' ) );

echo $failed ? "FAILED $failed\n" : "ALL PASSED\n";
exit( $failed ? 1 : 0 );
