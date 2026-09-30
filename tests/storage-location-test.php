<?php
/**
 * Storage root under uploads/jisento-{suffix} and legacy migration.
 *
 * Run: php tests/storage-location-test.php
 */

$root = sys_get_temp_dir() . '/jisento-stor-' . getmypid();
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Filesystem\File_System;
use Jisento\Migration\Storage\Local_Storage;

@mkdir( WP_CONTENT_DIR . '/jisento/packages', 0777, true );
@mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/jisento/packages/old.jisento', 'PKG' );
file_put_contents( WP_CONTENT_DIR . '/jisento/packages/old.jisento.json', '{}' );

$GLOBALS['jisento_test_options']['jisento_storage_suffix'] = 'abcd1234';

$storage = new Local_Storage();
$storage->ensure_directories();
$dest = $storage->root();

check( 'root is under uploads/jisento-suffix', false !== strpos( str_replace( '\\', '/', $dest ), '/uploads/jisento-abcd1234' ) );
check( 'legacy package moved', is_file( $dest . '/packages/old.jisento' ) );
check( 'legacy sidecar moved', is_file( $dest . '/packages/old.jisento.json' ) );
check( 'legacy root removed when empty', ! is_dir( WP_CONTENT_DIR . '/jisento' ) );
check( 'root has deny .htaccess', is_file( $dest . '/.htaccess' ) );
check( 'root has index.php', is_file( $dest . '/index.php' ) );
check( 'root has web.config', is_file( $dest . '/web.config' ) );
check( 'packages subdir protected', is_file( $dest . '/packages/.htaccess' ) && is_file( $dest . '/packages/index.php' ) );

$rel = Local_Storage::relative_content_path();
check( 'relative path', 'wp-content/uploads/jisento-abcd1234' === $rel, $rel );

$ex = File_System::default_cache_excludes();
check( 'exact storage path excluded from export', in_array( $rel, $ex, true ) );
check( 'wildcard jisento-* excluded', in_array( 'wp-content/uploads/jisento-*', $ex, true ) );
check( 'legacy path still excluded', in_array( 'wp-content/jisento', $ex, true ) );
check(
	'is_excluded matches nested file under storage',
	File_System::is_excluded( 'wp-content/uploads/jisento-abcd1234/packages/old.jisento', $ex )
);

// resolve still finds a leftover under legacy if migration left a collision.
@mkdir( WP_CONTENT_DIR . '/jisento/packages', 0777, true );
file_put_contents( WP_CONTENT_DIR . '/jisento/packages/legacy-only.jisento', 'LEGACY' );
$resolved = $storage->resolve( 'packages/legacy-only.jisento' );
check( 'resolve finds leftover legacy package', is_array( $resolved ) && is_file( $resolved['path'] ) );

jisento_test_finish();
