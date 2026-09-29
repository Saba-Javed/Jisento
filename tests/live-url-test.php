<?php
/**
 * Live URL pin and mu-plugin guard.
 *
 * Run: php tests/live-url-test.php
 */

$lu_root = sys_get_temp_dir() . '/jisento-live-url-' . getmypid();
define( 'WP_CONTENT_DIR', $lu_root . '/wp-content' );
define( 'JISENTO_BASENAME', 'Jisento migration plugin/jisento-migration.php' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Live_Url;
use Jisento\Migration\Import\Importer;

function lu_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? lu_rmtree( $path ) : unlink( $path );
	}
	rmdir( $dir );
}

/**
 * Load the generated mu-plugin in a fresh PHP process, the way WordPress would.
 *
 * @return array{output:string,code:int}
 */
function lu_run_guard( $root, $plugin_dir_json ) {
	$plugins = $root . '/plugins';
	$mu      = $root . '/mu-plugins';
	@mkdir( $mu, 0777, true );
	file_put_contents( $mu . '/jisento-live-url.php', Live_Url::guard_code() );
	file_put_contents( $mu . '/jisento-live-url.json', $plugin_dir_json );
	$runner = $root . '/run.php';
	file_put_contents(
		$runner,
		"<?php\nerror_reporting( E_ALL );\ndefine( 'ABSPATH', __DIR__ . '/' );\ndefine( 'WP_PLUGIN_DIR', " . var_export( $plugins, true ) . " );\nrequire " . var_export( $mu . '/jisento-live-url.php', true ) . ";\necho \"\\nLOADED\";\n"
	);
	$out  = array();
	$code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>&1', $out, $code );
	return array(
		'output' => implode( "\n", $out ),
		'code'   => (int) $code,
	);
}

// --- mu-plugin guard -------------------------------------------------------

$code = Live_Url::guard_code();
check( 'guard holds no absolute path of this server', false === strpos( $code, rtrim( str_replace( '\\', '/', JISENTO_PATH ), '/' ) ) && false === strpos( $code, rtrim( JISENTO_PATH, '/\\' ) ) );
check( 'guard requires only after file_exists-style check', false !== strpos( $code, 'is_file( $file )' ) && strpos( $code, 'is_file( $file )' ) < strpos( $code, 'require_once $file' ) );
check( 'guard source is valid PHP', false !== @token_get_all( $code ) );

$guard_root = $lu_root . '/guard';
$spaced     = 'Jisento migration plugin';
@mkdir( $guard_root . '/plugins/' . $spaced . '/includes/Core', 0777, true );
file_put_contents(
	$guard_root . '/plugins/' . $spaced . '/includes/Core/Live_Url.php',
	"<?php\nnamespace Jisento\\Migration\\Core;\nclass Live_Url { public static function protect() { echo 'PROTECTED'; } }\n"
);

$run = lu_run_guard( $guard_root, json_encode( array( 'plugin_dir' => $spaced ) ) );
check( 'guard loads the plugin from a folder name with spaces', 0 === $run['code'] && false !== strpos( $run['output'], 'PROTECTED' ) && false !== strpos( $run['output'], 'LOADED' ), $run['output'] );

foreach ( array( '../' . $spaced, 'a/b', 'a\\b', "a\0b", '..', '.', '' ) as $bad ) {
	$run = lu_run_guard( $guard_root, json_encode( array( 'plugin_dir' => $bad ) ) );
	check( 'guard refuses plugin_dir ' . json_encode( $bad ) . ' without a fatal', 0 === $run['code'] && false === strpos( $run['output'], 'PROTECTED' ) && false !== strpos( $run['output'], 'LOADED' ), $run['output'] );
}

$run = lu_run_guard( $guard_root, json_encode( array( 'plugin_dir' => 'moved-away' ) ) );
check( 'guard does nothing (no fatal) when the plugin folder is gone', 0 === $run['code'] && false === strpos( $run['output'], 'PROTECTED' ) && false !== strpos( $run['output'], 'LOADED' ), $run['output'] );

$run = lu_run_guard( $guard_root, '{not json' );
check( 'guard does nothing (no fatal) with a broken config file', 0 === $run['code'] && false !== strpos( $run['output'], 'LOADED' ), $run['output'] );

// --- active_plugins on release ---------------------------------------------

$source_plugins = array(
	'woocommerce/woocommerce.php',
	'jisento-migration/jisento-migration.php',
	'elementor/elementor.php',
	'old-jisento-copy/jisento-migration.php',
);
$filtered = Live_Url::filter_active_plugins( $source_plugins, array( 'jisento-migration', 'old-jisento-copy' ), JISENTO_BASENAME );
check(
	'skipped Jisento copies are removed and this install stays active',
	array( 'woocommerce/woocommerce.php', 'elementor/elementor.php', JISENTO_BASENAME ) === $filtered,
	json_encode( $filtered )
);
check(
	'same folder name on both sites keeps exactly one entry',
	array( 'a/a.php', 'jisento-migration/jisento-migration.php' ) === Live_Url::filter_active_plugins( array( 'a/a.php', 'jisento-migration/jisento-migration.php' ), array( 'jisento-migration' ), 'jisento-migration/jisento-migration.php' )
);
check( 'single-file plugins are kept', in_array( 'hello.php', Live_Url::filter_active_plugins( array( 'hello.php' ), array( 'hello.php' ), JISENTO_BASENAME ), true ) );

$dirs = Importer::skipped_plugin_dirs( array( 'wp-content/plugins/old-jisento-copy/', 'plugins/jisento-migration/' ), array( 'plugin_basename' => 'jisento-src/jisento-migration.php' ) );
check( 'skipped dirs come from package copies and the source basename', array( 'old-jisento-copy', 'jisento-migration', 'jisento-src' ) === $dirs, json_encode( $dirs ) );

@mkdir( WP_CONTENT_DIR . '/jisento', 0777, true );
file_put_contents(
	WP_CONTENT_DIR . '/jisento/live-url.json',
	json_encode(
		array(
			'job_id'                  => 'job_release_test',
			'home'                    => 'https://destination.test',
			'active_plugins'          => array( JISENTO_BASENAME ),
			'imported_active_plugins' => $source_plugins,
			'released'                => false,
		)
	)
);
Live_Url::skip_plugin_dirs( 'job_release_test', $dirs );
Live_Url::release( 'job_release_test' );
$written = isset( $GLOBALS['jisento_test_options']['active_plugins'] ) ? $GLOBALS['jisento_test_options']['active_plugins'] : null;
check(
	'release writes the source plugins without skipped copies, with this install active',
	array( 'woocommerce/woocommerce.php', 'elementor/elementor.php', JISENTO_BASENAME ) === $written,
	json_encode( $written )
);
check( 'release removes the pin', ! is_file( WP_CONTENT_DIR . '/jisento/live-url.json' ) );

// --- theme pin during import / release -------------------------------------

$themes = WP_CONTENT_DIR . '/themes';
@mkdir( $themes . '/dest-theme', 0777, true );
file_put_contents( $themes . '/dest-theme/style.css', "/* Theme Name: Dest */\n" );
@mkdir( $themes . '/grillino', 0777, true );
file_put_contents( $themes . '/grillino/functions.php', "<?php require_once __DIR__ . '/inc/grillino-constants.php';\n" );
// Incomplete: no style.css and missing include — must not be activated on release.
check( 'incomplete imported theme is not ready', ! Live_Url::theme_files_ready( 'grillino', 'grillino' ) );

file_put_contents(
	WP_CONTENT_DIR . '/jisento/live-url.json',
	json_encode(
		array(
			'job_id'               => 'job_theme_pin',
			'home'                 => 'https://destination.test',
			'template'             => 'dest-theme',
			'stylesheet'           => 'dest-theme',
			'imported_template'    => 'grillino',
			'imported_stylesheet'  => 'grillino',
			'active_plugins'       => array( JISENTO_BASENAME ),
			'released'             => false,
		)
	)
);
$warn = Live_Url::apply_imported_theme(
	array(
		'imported_template'   => 'grillino',
		'imported_stylesheet' => 'grillino',
	)
);
check( 'release keeps destination theme when imported theme files are incomplete', '' !== $warn && ! isset( $GLOBALS['jisento_test_options']['stylesheet'] ), $warn );

file_put_contents( $themes . '/grillino/style.css', "/* Theme Name: Grillino */\n" );
@mkdir( $themes . '/grillino/inc', 0777, true );
file_put_contents( $themes . '/grillino/inc/grillino-constants.php', "<?php\n" );
check( 'complete imported theme is ready', Live_Url::theme_files_ready( 'grillino', 'grillino' ) );
$warn = Live_Url::apply_imported_theme(
	array(
		'imported_template'   => 'grillino',
		'imported_stylesheet' => 'grillino',
	)
);
check( 'release activates imported theme when files are complete', '' === $warn && 'grillino' === $GLOBALS['jisento_test_options']['stylesheet'] && 'grillino' === $GLOBALS['jisento_test_options']['template'] );

$warn = Live_Url::apply_imported_theme(
	array(
		'imported_template'   => 'grillino',
		'imported_stylesheet' => 'grillino',
		'skip_themes'         => true,
	)
);
check( 'skip_themes keeps destination theme with a warning', '' !== $warn && false !== strpos( $warn, 'skip-themes' ), $warn );

file_put_contents(
	WP_CONTENT_DIR . '/jisento/live-url.json',
	json_encode(
		array(
			'job_id'     => 'job_skip_theme',
			'home'       => 'https://destination.test',
			'template'   => 'dest-theme',
			'stylesheet' => 'dest-theme',
			'released'   => false,
		)
	)
);
Live_Url::skip_themes( 'job_skip_theme' );
$pin = json_decode( (string) file_get_contents( WP_CONTENT_DIR . '/jisento/live-url.json' ), true );
check( 'skip_themes is stored on the pin', ! empty( $pin['skip_themes'] ) );
@unlink( WP_CONTENT_DIR . '/jisento/live-url.json' );

$live = file_get_contents( JISENTO_PATH . 'includes/Core/Live_Url.php' );
check( 'hold pins template via pre_option_template', false !== strpos( $live, 'pre_option_template' ) && false !== strpos( $live, 'pre_option_stylesheet' ) );
check( 'capture stores destination template and stylesheet', false !== strpos( $live, "'template'" ) && false !== strpos( $live, "'stylesheet'" ) );

$import = file_get_contents( JISENTO_PATH . 'includes/Import/Importer.php' );
$swap_pos = strpos( $import, 'function swap_database' );
$files_pos = strpos( $import, "case 'importing_files'" );
$fin_pos = strpos( $import, 'function finalize' );
$rel_pos = strpos( $import, 'Live_Url::release' );
check( 'file restore stage is registered before finalize', false !== $files_pos && false !== $fin_pos && $files_pos < $fin_pos );
check( 'theme pin is released only in cleanup after the job finishes', false !== $rel_pos && false !== strpos( substr( $import, strpos( $import, 'function cleanup' ), 800 ), 'Live_Url::release' ) );
check( 'swap calls hold so the destination theme stays pinned', false !== strpos( substr( $import, $swap_pos, 1200 ), 'Live_Url::hold()' ) );

$cli = file_get_contents( JISENTO_PATH . 'includes/Cli/Commands.php' );
check( 'CLI resume documents --skip-themes', false !== strpos( $cli, 'skip-themes' ) && false !== strpos( $cli, 'skip_themes' ) );
$readme = file_get_contents( JISENTO_PATH . 'readme.txt' );
check( 'readme documents wp jisento resume --skip-themes', false !== strpos( $readme, 'wp jisento resume --job=<id> --skip-themes' ) );

// --- no unauthenticated URL changes ---------------------------------------

$live = file_get_contents( JISENTO_PATH . 'includes/Core/Live_Url.php' );
check( 'Live_Url never reads the Host header', false === strpos( $live, 'HTTP_HOST' ) && false === strpos( $live, 'heal_from_package' ) );
check( 'jisento-recover.php is gone', ! file_exists( JISENTO_PATH . 'jisento-recover.php' ) );
check( 'hostinger options are preserved', Live_Url::preserved_option( 'hostinger_onboarding' ) && Live_Url::preserved_option( 'hostinger-ai-builder' ) );
check( 'other options are not preserved', ! Live_Url::preserved_option( 'siteurl' ) && ! Live_Url::preserved_option( 'my_hostinger' ) );

$controller = file_get_contents( JISENTO_PATH . 'includes/Api/Rest_Controller.php' );
$archive    = file_get_contents( JISENTO_PATH . 'includes/Package/Archive.php' );
check( 'upload and validate do not update options', false === strpos( $controller, 'update_option' ) );
check( 'upload and validate do not extract the package', false === strpos( $controller, 'extractTo' ) && false === strpos( $controller, 'import_chunk' ) );
$inspect = substr( $archive, strpos( $archive, 'function inspect' ), strpos( $archive, 'function has_files_prefix' ) - strpos( $archive, 'function inspect' ) );
check( 'package inspect does not extract or write the database', false === strpos( $inspect, 'extractTo' ) && false === strpos( $inspect, 'query(' ) );

lu_rmtree( $lu_root );
jisento_test_finish();
