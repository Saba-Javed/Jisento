<?php
/**
 * B5: Brand menu icon (filled SVG data URI + assets).
 *
 * Run: php tests/menu-icon-test.php
 */

require __DIR__ . '/bootstrap.php';

$svg    = file_get_contents( dirname( __DIR__ ) . '/assets/icons/menu-icon.svg' );
$admin  = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/Admin.php' );
$layout = file_get_contents( dirname( __DIR__ ) . '/admin/views/layout-start.php' );
$css    = file_get_contents( dirname( __DIR__ ) . '/admin/css/admin.css' );
$png256 = dirname( __DIR__ ) . '/.wordpress-org/icon-256x256.png';
$png128 = dirname( __DIR__ ) . '/.wordpress-org/icon-128x128.png';

check( 'menu-icon.svg exists', is_string( $svg ) && '' !== trim( $svg ) );
check( 'svg uses fill black only', false !== strpos( $svg, 'fill="black"' ) );
check( 'svg has no stroke attributes', ! preg_match( '/\bstroke[\s=]/i', $svg ) );
check( 'svg has no other fill colors', 1 === preg_match_all( '/fill="/', $svg ) );
check( 'svg uses path elements only for shapes', false === strpos( $svg, '<rect' ) && false !== strpos( $svg, '<path' ) );
check( 'Admin uses menu_icon_data_uri', false !== strpos( $admin, 'menu_icon_data_uri' ) && false !== strpos( $admin, 'data:image/svg+xml;base64,' ) );
check( 'add_menu_page uses data URI helper not dashicon arg', false !== strpos( $admin, '$this->menu_icon_data_uri()' ) );
check( 'layout reuses menu-icon.svg', false !== strpos( $layout, 'assets/icons/menu-icon.svg' ) && false !== strpos( $layout, 'jisento-page-icon' ) );
check( 'css styles page icon', false !== strpos( $css, 'jisento-page-icon' ) );
check( 'css mentions light and blue admin schemes', false !== strpos( $css, 'admin-color-light' ) && false !== strpos( $css, 'admin-color-blue' ) );
check( '256 PNG present', is_file( $png256 ) && filesize( $png256 ) > 100 );
check( '128 PNG present', is_file( $png128 ) && filesize( $png128 ) > 100 );
check( 'PNGs are PNG files', "\x89PNG" === substr( (string) file_get_contents( $png256 ), 0, 4 ) && "\x89PNG" === substr( (string) file_get_contents( $png128 ), 0, 4 ) );

jisento_test_finish();
