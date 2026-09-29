<?php
/**
 * Preserve mode: keep destination users/identity options; restore all other tables;
 * reassign orphan post authors; keep existing plugin/theme folders.
 *
 * Run: php tests/preserve-mode-test.php
 */

$root = sys_get_temp_dir() . '/jisento-preserve-mode-' . getmypid();
@mkdir( $root . '/wp-content/plugins/akismet', 0777, true );
@mkdir( $root . '/wp-content/themes/twentytwentyfour', 0777, true );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'WP_PLUGIN_DIR', $root . '/wp-content/plugins' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Import\Importer;

function pm_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? pm_rmtree( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}
register_shutdown_function(
	static function () use ( $root ) {
		pm_rmtree( $root );
	}
);
if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return WP_CONTENT_DIR . '/themes';
	}
}

/* --- Identity options --- */
check( 'siteurl/home/admin_email are preserved options', Live_Url::preserved_option( 'siteurl' ) && Live_Url::preserved_option( 'home' ) && Live_Url::preserved_option( 'admin_email' ) );
check( 'auth salt option names are preserved', Live_Url::preserved_option( 'auth_key' ) && Live_Url::preserved_option( 'logged_in_salt' ) );
check( 'hostinger options still preserved', Live_Url::preserved_option( 'hostinger_onboarding' ) );
check( 'unrelated options are not preserved', ! Live_Url::preserved_option( 'blogname' ) && ! Live_Url::preserved_option( 'my_hostinger' ) );

/* --- normalize_options forces install_missing in Preserve --- */
$ref      = new ReflectionClass( Importer::class );
$importer = $ref->newInstanceWithoutConstructor();
$norm     = $ref->getMethod( 'normalize_options' );
$norm->setAccessible( true );
$opts = $norm->invoke(
	$importer,
	array(
		'destination_mode' => 'preserve',
		'plugin_strategy'  => 'replace_matching',
		'theme_strategy'   => 'replace_matching',
		'replace_tables'   => array( 'wp_posts' ),
	)
);
check( 'preserve forces install_missing strategies', 'install_missing' === $opts['plugin_strategy'] && 'install_missing' === $opts['theme_strategy'] );
check( 'preserve clears replace_tables', array() === $opts['replace_tables'] );

/* --- Existing plugin folder is skipped; new plugin is not --- */
$snap = $ref->getMethod( 'snapshot_existing_extensions' );
$snap->setAccessible( true );
$skip = $ref->getMethod( 'should_skip_file' );
$skip->setAccessible( true );
file_put_contents( WP_PLUGIN_DIR . '/akismet/akismet.php', "<?php\n/* Plugin Name: Akismet */\n" );
$marker = 'DESTINATION_ONLY_' . md5( 'akismet' );
file_put_contents( WP_PLUGIN_DIR . '/akismet/keep.me', $marker );
$hash_before = hash_file( 'sha256', WP_PLUGIN_DIR . '/akismet/keep.me' );

$state = $snap->invoke(
	$importer,
	array(
		'options' => array(
			'destination_mode' => 'preserve',
			'plugin_strategy'  => 'replace_matching', // would overwrite without Preserve force + snapshot
			'theme_strategy'   => 'install_missing',
			'plugin_conflicts' => array(),
			'theme_conflicts'  => array(),
			'preserve_uploads' => true,
		),
	)
);
// Re-normalize like the importer does.
$state['options'] = $norm->invoke( $importer, $state['options'] );
check( 'akismet listed as existing', ! empty( $state['existing_plugins']['akismet'] ) );
check(
	'existing plugin file skipped in Preserve',
	$skip->invoke( $importer, 'wp-content/plugins/akismet/akismet.php', WP_PLUGIN_DIR . '/akismet/akismet.php', $state )
);
check(
	'missing plugin file not skipped',
	! $skip->invoke( $importer, 'wp-content/plugins/new-plugin/new-plugin.php', WP_PLUGIN_DIR . '/new-plugin/new-plugin.php', $state )
);
check( 'existing plugin hash unchanged', $hash_before === hash_file( 'sha256', WP_PLUGIN_DIR . '/akismet/keep.me' ) && $marker === file_get_contents( WP_PLUGIN_DIR . '/akismet/keep.me' ) );

/* --- Orphan author reassignment (needs DB) --- */
$db = jisento_test_wpdb( 'jisento_preserve_authors', 'wp_', 'dest' );
if ( ! $db ) {
	echo "SKIP orphan author reassignment (no database server)\n";
} else {
	$GLOBALS['wpdb'] = $db;
	$db->query( 'CREATE TABLE wp_users (ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_login varchar(60) NOT NULL, PRIMARY KEY (ID)) ENGINE=InnoDB' );
	$db->query( 'CREATE TABLE wp_posts (ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_author bigint(20) unsigned NOT NULL DEFAULT 0, post_type varchar(20) NOT NULL DEFAULT \'post\', PRIMARY KEY (ID)) ENGINE=InnoDB' );
	$db->query( "INSERT INTO wp_users (user_login) VALUES ('dest_admin')" );
	$admin_id = (int) $db->insert_id;
	$db->query( "INSERT INTO wp_posts (post_author, post_type) VALUES (99, 'post'), (99, 'product'), (99, 'shop_order'), ($admin_id, 'post')" );
	$job   = (object) array( 'job_id' => 'pm_authors' );
	$state = array(
		'options'         => array( 'destination_mode' => 'preserve' ),
		'import_admin_id' => $admin_id,
	);
	$plugin         = \Jisento\Migration\Plugin::instance();
	$plugin->logger = new class() {
		public function log() {}
	};
	$state   = $importer->reassign_orphan_authors( $job, $state );
	$authors = $db->get_col( 'SELECT post_author FROM wp_posts ORDER BY ID' );
	check( 'orphan post author reassigned', (string) $admin_id === (string) $authors[0], json_encode( $authors ) );
	check( 'orphan product author reassigned', (string) $admin_id === (string) $authors[1], json_encode( $authors ) );
	check( 'shop_order author left alone', '99' === (string) $authors[2], json_encode( $authors ) );
	check( 'existing author unchanged', (string) $admin_id === (string) $authors[3], json_encode( $authors ) );
	check( 'reassigned count logged in state', 2 === (int) $state['orphan_authors_reassigned'] );
}

check( 'wc order post types helper', array( 'shop_order', 'shop_order_refund', 'shop_subscription' ) === Importer::wc_order_post_types() );
$import_src = file_get_contents( JISENTO_PATH . 'includes/Import/Importer.php' );
check( 'preserve plan keeps users and usermeta', false !== strpos( $import_src, 'usermeta_table' ) && false !== strpos( $import_src, 'keeping destination logins' ) );
check( 'orphan author SQL excludes WooCommerce order types', false !== strpos( $import_src, "post_type NOT IN ('shop_order','shop_order_refund','shop_subscription')" ) );
check( 'confirm_preserve is required', false !== strpos( $import_src, 'confirm_preserve' ) );

jisento_test_finish();
