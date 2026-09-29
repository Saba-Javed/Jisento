<?php
/**
 * Preserve file restore: do not treat a folder created mid-restore as "already on destination",
 * and fail the job when a restored plugin/theme is missing package entries.
 *
 * Run: php tests/preserve-files-test.php
 */

$root = sys_get_temp_dir() . '/jisento-preserve-files-' . getmypid();
@mkdir( $root . '/wp-content/plugins', 0777, true );
@mkdir( $root . '/wp-content/themes', 0777, true );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
define( 'WP_PLUGIN_DIR', $root . '/wp-content/plugins' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Import\Importer;

function pf_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? pf_rmtree( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}

register_shutdown_function(
	static function () use ( $root ) {
		pf_rmtree( $root );
	}
);

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return WP_CONTENT_DIR . '/themes';
	}
}

$importer = ( new ReflectionClass( Importer::class ) )->newInstanceWithoutConstructor();
$skip     = new ReflectionMethod( Importer::class, 'should_skip_file' );
$skip->setAccessible( true );
$snap = new ReflectionMethod( Importer::class, 'snapshot_existing_extensions' );
$snap->setAccessible( true );

/* --- Bug: first file creates the theme folder; later files must still restore --- */
$theme_dir = get_theme_root() . '/grillino';
$state     = array(
	'options' => array(
		'destination_mode' => 'preserve',
		'plugin_strategy'  => 'install_missing',
		'theme_strategy'   => 'install_missing',
		'plugin_conflicts' => array(),
		'theme_conflicts'  => array(),
		'preserve_uploads' => true,
	),
);
$state = $snap->invoke( $importer, $state );
check( 'snapshot: grillino not listed as existing', empty( $state['existing_themes']['grillino'] ) );

$rel_style = 'wp-content/themes/grillino/style.css';
$rel_fn    = 'wp-content/themes/grillino/functions.php';
$dest_style = get_theme_root() . '/grillino/style.css';
$dest_fn    = get_theme_root() . '/grillino/functions.php';

check(
	'should_skip before any file: style.css not skipped',
	! $skip->invoke( $importer, $rel_style, $dest_style, $state )
);

// Simulate the first restored file creating the theme folder (the Hostinger bug).
@mkdir( $theme_dir, 0777, true );
file_put_contents( $dest_style, "/* theme */\n" );

check(
	'should_skip after first file created the folder: functions.php still not skipped',
	! $skip->invoke( $importer, $rel_fn, $dest_fn, $state ),
	'install_missing must use the pre-restore snapshot, not live is_dir()'
);

/* --- Existing destination theme is kept --- */
$state2 = $snap->invoke(
	$importer,
	array(
		'options' => $state['options'],
	)
);
check( 'snapshot: grillino now existing', ! empty( $state2['existing_themes']['grillino'] ) );
check(
	'keep install_missing skips files when theme existed before restore',
	$skip->invoke( $importer, $rel_fn, $dest_fn, $state2 )
);

/* --- Post-restore verification: names + sizes --- */
$pkg = $root . '/pkg.zip';
$zip = new ZipArchive();
check( 'create package zip', true === $zip->open( $pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
$entries = array(
	'files/wp-content/themes/grillino/style.css'     => "/* theme */\n",
	'files/wp-content/themes/grillino/functions.php' => "<?php\n",
	'files/wp-content/themes/grillino/screenshot.png'=> str_repeat( 'P', 120 ),
	'files/wp-content/plugins/acme/acme.php'         => "<?php\n/* Plugin Name: Acme */\n",
	'files/wp-content/plugins/acme/includes/lib.php' => "<?php\nreturn 1;\n",
);
foreach ( $entries as $name => $bytes ) {
	$zip->addFromString( $name, $bytes );
}
$zip->close();

// Incomplete theme on disk (missing functions.php and screenshot).
pf_rmtree( $theme_dir );
@mkdir( $theme_dir, 0777, true );
file_put_contents( $dest_style, "/* theme */\n" );
@mkdir( WP_PLUGIN_DIR . '/acme/includes', 0777, true );
file_put_contents( WP_PLUGIN_DIR . '/acme/acme.php', "<?php\n/* Plugin Name: Acme */\n" );
file_put_contents( WP_PLUGIN_DIR . '/acme/includes/lib.php', "<?php\nreturn 1;\n" );

$verify_state = array(
	'options'           => array(
		'destination_mode' => 'preserve',
		'plugin_strategy'  => 'install_missing',
		'theme_strategy'   => 'install_missing',
		'plugin_conflicts' => array(),
		'theme_conflicts'  => array(),
	),
	'existing_plugins'  => array(),
	'existing_themes'   => array(),
	'jisento_copies'    => array(),
);
$result = $importer->verify_restored_extensions( $pkg, $verify_state );
check( 'incomplete restored theme fails verification', is_wp_error( $result ) );
if ( is_wp_error( $result ) ) {
	$msg = $result->get_error_message();
	check( 'failure names missing functions.php', false !== strpos( $msg, 'functions.php' ), $msg );
	check( 'failure names missing screenshot.png', false !== strpos( $msg, 'screenshot.png' ), $msg );
}

// Complete restore.
file_put_contents( $dest_fn, "<?php\n" );
file_put_contents( get_theme_root() . '/grillino/screenshot.png', str_repeat( 'P', 120 ) );
$result = $importer->verify_restored_extensions( $pkg, $verify_state );
check( 'complete restored theme+plugin passes verification', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

// Wrong size fails.
file_put_contents( $dest_fn, "<?php\n// truncated\n" );
$result = $importer->verify_restored_extensions( $pkg, $verify_state );
check( 'wrong size fails verification', is_wp_error( $result ) );
if ( is_wp_error( $result ) ) {
	check( 'wrong-size message names functions.php', false !== strpos( $result->get_error_message(), 'functions.php' ), $result->get_error_message() );
}

// Existing destination plugin (snapshot) is not verified as a restore target.
file_put_contents( $dest_fn, "<?php\n" );
$kept = $verify_state;
$kept['existing_plugins'] = array( 'acme' => true );
$kept['existing_themes']  = array( 'grillino' => true );
// Wipe files so a mistaken verify would fail.
pf_rmtree( $theme_dir );
pf_rmtree( WP_PLUGIN_DIR . '/acme' );
$result = $importer->verify_restored_extensions( $pkg, $kept );
check( 'kept destination extensions are not verified as restores', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

jisento_test_finish();
