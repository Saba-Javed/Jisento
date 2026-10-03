<?php
/**
 * Dev helper: build languages/jisento-migration.pot from PHP gettext calls.
 *
 * @package Jisento\Migration
 */

$root   = dirname( __DIR__ );
$domain = 'jisento-migration';
$entries = array();

$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
foreach ( $rii as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$path = $file->getPathname();
	if ( ! preg_match( '/\.php$/i', $path ) ) {
		continue;
	}
	if ( false !== strpos( $path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	if ( false !== strpos( $path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	$rel = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
	$src = file_get_contents( $path );

	if ( preg_match_all(
		"/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex)\s*\(\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1(?:\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3)?\s*,\s*'jisento-migration'\s*\)/",
		$src,
		$m,
		PREG_OFFSET_CAPTURE | PREG_SET_ORDER
	) ) {
		foreach ( $m as $match ) {
			$msgid = stripcslashes( $match[2][0] );
			$line  = substr_count( substr( $src, 0, $match[0][1] ), "\n" ) + 1;
			$entries[ $msgid ][] = $rel . ':' . $line;
		}
	}

	if ( preg_match_all(
		"/_n(?:x)?\s*\(\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\s*,\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3\s*,[^,]+,\s*'jisento-migration'\s*\)/",
		$src,
		$mn,
		PREG_OFFSET_CAPTURE | PREG_SET_ORDER
	) ) {
		foreach ( $mn as $match ) {
			$msgid  = stripcslashes( $match[2][0] );
			$plural = stripcslashes( $match[4][0] );
			$line   = substr_count( substr( $src, 0, $match[0][1] ), "\n" ) + 1;
			$key    = $msgid . "\0" . $plural;
			$entries[ $key ][] = $rel . ':' . $line;
		}
	}
}

ksort( $entries, SORT_STRING );

/**
 * Quote a string for a POT msgid.
 *
 * @param string $s String.
 * @return string
 */
function jisento_pot_quote( $s ) {
	$s = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s );
	$s = str_replace( array( "\r\n", "\r", "\n" ), array( '\\n', '\\n', '\\n' ), $s );
	if ( false !== strpos( $s, '\\n' ) ) {
		$parts = explode( '\\n', $s );
		$lines = array( '""' );
		$last  = count( $parts ) - 1;
		foreach ( $parts as $i => $p ) {
			$suffix  = ( $i < $last ) ? '\\n' : '';
			$lines[] = '"' . $p . $suffix . '"';
		}
		return implode( "\n", $lines );
	}
	return '"' . $s . '"';
}

$out   = array();
$out[] = '# Copyright (C) 2026 Jisento';
$out[] = '# This file is distributed under the GPLv2 or later.';
$out[] = 'msgid ""';
$out[] = 'msgstr ""';
$out[] = '"Project-Id-Version: Jisento Migration 1.0.0\n"';
$out[] = '"Report-Msgid-Bugs-To: https://jisento.com\n"';
$out[] = '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i:s+0000' ) . '\n"';
$out[] = '"MIME-Version: 1.0\n"';
$out[] = '"Content-Type: text/plain; charset=UTF-8\n"';
$out[] = '"Content-Transfer-Encoding: 8bit\n"';
$out[] = '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\n"';
$out[] = '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\n"';
$out[] = '"Language-Team: LANGUAGE <LL@li.org>\n"';
$out[] = '"X-Domain: jisento-migration\n"';
$out[] = '';

foreach ( $entries as $key => $refs ) {
	$refs  = array_values( array_unique( $refs ) );
	$out[] = '#: ' . implode( ' ', array_slice( $refs, 0, 40 ) );
	if ( false !== strpos( $key, "\0" ) ) {
		list( $sing, $pl ) = explode( "\0", $key, 2 );
		$out[]             = 'msgid ' . jisento_pot_quote( $sing );
		$out[]             = 'msgid_plural ' . jisento_pot_quote( $pl );
		$out[]             = 'msgstr[0] ""';
		$out[]             = 'msgstr[1] ""';
	} else {
		$out[] = 'msgid ' . jisento_pot_quote( $key );
		$out[] = 'msgstr ""';
	}
	$out[] = '';
}

$dir = $root . '/languages';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
$path = $dir . '/jisento-migration.pot';
file_put_contents( $path, implode( "\n", $out ) . "\n" );
echo 'Wrote ' . $path . ' with ' . count( $entries ) . " entries\n";
