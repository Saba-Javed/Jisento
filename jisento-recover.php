<?php
/**
 * Direct recovery when wp-admin redirects to the source domain.
 *
 * Open this file in the browser on the destination host. It does not load
 * WordPress. It only changes home and siteurl when those values match a
 * package stored by this plugin, and it sets them to the host you used to
 * open this file.
 *
 * @package Jisento\Migration
 */

$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
$host = preg_replace( '/:\d+$/', '', $host );
if ( ! is_string( $host ) || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 400 );
	echo "The recovery request did not include a usable site host.\n";
	exit;
}

$config = '';
$dir    = __DIR__;
for ( $i = 0; $i < 6; $i++ ) {
	if ( is_file( $dir . '/wp-config.php' ) ) {
		$config = $dir . '/wp-config.php';
		break;
	}
	$parent = dirname( $dir );
	if ( $parent === $dir ) {
		break;
	}
	$dir = $parent;
}
if ( '' === $config ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
	echo "wp-config.php was not found.\n";
	exit;
}

$text = (string) file_get_contents( $config );
$read = static function ( $source, $name ) {
	$pattern = '/define\s*\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]\s*,\s*([\'"])(.*?)(?<!\\\\)\1/s';
	if ( ! preg_match( $pattern, $source, $match ) ) {
		return null;
	}
	return stripcslashes( $match[2] );
};

$db_name = $read( $text, 'DB_NAME' );
$db_user = $read( $text, 'DB_USER' );
$db_pass = $read( $text, 'DB_PASSWORD' );
$db_host = $read( $text, 'DB_HOST' );
$prefix  = 'wp_';
if ( preg_match( '/\$table_prefix\s*=\s*([\'"])(.*?)(?<!\\\\)\1/', $text, $prefix_match ) ) {
	$prefix = stripcslashes( $prefix_match[2] );
}
if ( null === $db_name || null === $db_user || null === $db_host ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
	echo "The database settings in wp-config.php could not be read.\n";
	exit;
}

$content = dirname( $config ) . '/wp-content';
if ( preg_match( '/define\s*\(\s*[\'"]WP_CONTENT_DIR[\'"]\s*,\s*([\'"])(.*?)(?<!\\\\)\1/s', $text, $content_match ) ) {
	$content = stripcslashes( $content_match[2] );
}

$package_hosts = array();
foreach ( array( '/jisento/packages', '/jisento/uploads', '/jisento/backups' ) as $rel ) {
	$files = glob( $content . $rel . '/*.json' );
	if ( ! $files ) {
		continue;
	}
	foreach ( $files as $file ) {
		$meta = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $meta ) || empty( $meta['home_url'] ) ) {
			continue;
		}
		$parts = parse_url( (string) $meta['home_url'] );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$package_hosts[] = strtolower( (string) $parts['host'] );
		}
	}
}
$package_hosts = array_values( array_unique( $package_hosts ) );
if ( ! $package_hosts ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 409 );
	echo "No uploaded package metadata was found, so the live URL was not changed.\n";
	exit;
}

$mysqli = @new mysqli( $db_host, $db_user, (string) $db_pass, $db_name );
if ( $mysqli->connect_errno ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
	echo "The destination database could not be opened.\n";
	exit;
}

$table = str_replace( '`', '', $prefix . 'options' );
$https = ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'];
$changed = false;
$stmt    = $mysqli->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = ? LIMIT 1" );
$update  = $mysqli->prepare( "UPDATE `{$table}` SET option_value = ? WHERE option_name = ?" );
if ( ! $stmt || ! $update ) {
	header( 'Content-Type: text/plain; charset=utf-8', true, 500 );
	echo "The options table could not be read.\n";
	exit;
}

foreach ( array( 'home', 'siteurl' ) as $name ) {
	$stmt->bind_param( 's', $name );
	$stmt->execute();
	$stmt->bind_result( $value );
	$found = $stmt->fetch();
	$stmt->reset();
	if ( ! $found ) {
		continue;
	}
	$parts = parse_url( (string) $value );
	$live  = ( is_array( $parts ) && ! empty( $parts['host'] ) ) ? strtolower( (string) $parts['host'] ) : '';
	if ( '' === $live || $live === $host || ! in_array( $live, $package_hosts, true ) ) {
		continue;
	}
	$path = isset( $parts['path'] ) ? $parts['path'] : '';
	$next = ( $https ? 'https' : 'http' ) . '://' . $host . $path;
	$update->bind_param( 'ss', $next, $name );
	$update->execute();
	$changed = true;
}

header( 'Content-Type: text/plain; charset=utf-8' );
if ( $changed ) {
	echo "The destination home and siteurl now use {$host}. Open the site again. Package upload still does not change these values.\n";
} else {
	echo "home and siteurl do not match an uploaded package domain, so nothing was changed.\n";
}
