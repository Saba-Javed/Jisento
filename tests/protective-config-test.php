<?php
/**
 * Protective .htaccess / web.config under wp-content may be restored; rewrite rules stay skipped.
 *
 * Run: php tests/protective-config-test.php
 */

$root = sys_get_temp_dir() . '/jisento-htaccess-' . getmypid();
@mkdir( $root . '/wp-content/uploads/woocommerce_uploads', 0777, true );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Import\Importer;

function pc_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? pc_rmtree( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}
register_shutdown_function(
	static function () use ( $root ) {
		pc_rmtree( $root );
	}
);

$woo = <<<'HTA'
# Apache 2.2
<IfModule !mod_authz_core.c>
Order Deny,Allow
Deny from all
</IfModule>

# Apache 2.4
<IfModule mod_authz_core.c>
Require all denied
</IfModule>
HTA;

$cf7 = "deny from all\nOptions -Indexes\n";

$rewrite = <<<'HTA'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTA;

$web = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization>
      <deny users="*" />
    </authorization>
  </system.webServer>
</configuration>
XML;

$web_rewrite = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="wordpress">
          <match url=".*" />
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
XML;

check( 'WooCommerce-style .htaccess is protective', Importer::is_protective_htaccess( $woo ) );
check( 'CF7-style .htaccess is protective', Importer::is_protective_htaccess( $cf7 ) );
check( 'rewrite-rule .htaccess is not protective', ! Importer::is_protective_htaccess( $rewrite ) );
check( 'php_value .htaccess is not protective', ! Importer::is_protective_htaccess( "php_value upload_max_filesize 64M\n" ) );
check( 'deny web.config is protective', Importer::is_protective_web_config( $web ) );
check( 'rewrite web.config is not protective', ! Importer::is_protective_web_config( $web_rewrite ) );

$pkg = $root . '/pkg.zip';
$zip = new ZipArchive();
check( 'create package', true === $zip->open( $pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
$zip->addFromString( 'files/wp-content/uploads/woocommerce_uploads/.htaccess', $woo );
$zip->addFromString( 'files/wp-content/uploads/wc-logs/.htaccess', $woo );
$zip->addFromString( 'files/wp-content/uploads/wpcf7_uploads/.htaccess', $cf7 );
$zip->addFromString( 'files/.htaccess', $rewrite ); // site root in package = not under wp-content/.../subdir
$zip->addFromString( 'files/wp-content/.htaccess', $rewrite );
$zip->addFromString( 'files/wp-content/uploads/bad/.htaccess', $rewrite );
$zip->addFromString( 'files/wp-content/uploads/woocommerce_uploads/web.config', $web );
$zip->addFromString( 'files/wp-content/uploads/bad/web.config', $web_rewrite );
$zip->close();

$importer = ( new ReflectionClass( Importer::class ) )->newInstanceWithoutConstructor();
$state    = array(
	'package_path'   => $pkg,
	'options'        => array( 'destination_mode' => 'replace' ),
	'jisento_copies' => array(),
	'manifest'       => array(),
	'job_id'         => 'htaccess_test',
);

$cases = array(
	'wp-content/uploads/woocommerce_uploads/.htaccess' => true,
	'wp-content/uploads/wc-logs/.htaccess'             => true,
	'wp-content/uploads/wpcf7_uploads/.htaccess'       => true,
	'wp-content/uploads/woocommerce_uploads/web.config'=> true,
	'.htaccess'                                       => false,
	'wp-content/.htaccess'                            => false,
	'wp-content/uploads/bad/.htaccess'                 => false,
	'wp-content/uploads/bad/web.config'                => false,
);

foreach ( $cases as $rel => $want ) {
	$dest = $importer->destination_for_archive_file( $rel, $state );
	$ok   = $want ? ( is_string( $dest ) && '' !== $dest ) : ( '' === $dest );
	check( ( $want ? 'restore ' : 'skip ' ) . $rel, $ok, is_string( $dest ) ? $dest : gettype( $dest ) );
}

jisento_test_finish();
