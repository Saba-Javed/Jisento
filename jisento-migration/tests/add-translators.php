<?php
/**
 * Add translators comments before i18n calls that contain placeholders.
 *
 * @package Jisento\Migration
 */

$root = dirname( __DIR__ );
$rii  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$changed = 0;

foreach ( $rii as $file ) {
	if ( ! $file->isFile() || ! preg_match( '/\.php$/', $file->getFilename() ) ) {
		continue;
	}
	$path = $file->getPathname();
	if ( false !== strpos( $path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		continue;
	}
	$src  = file_get_contents( $path );
	$lines = preg_split( "/\r\n|\n|\r/", $src );
	$out   = array();
	$mod   = false;
	for ( $i = 0, $n = count( $lines ); $i < $n; $i++ ) {
		$line = $lines[ $i ];
		$prev = $i > 0 ? trim( $lines[ $i - 1 ] ) : '';
		$needs = (bool) preg_match( '/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_nx|_x)\s*\(/', $line )
			&& (bool) preg_match( '/%(?:\d+\$)?[sd]/', $line )
			&& ! preg_match( '/translators:/i', $prev )
			&& ! preg_match( '/^\s*\/\*|^\s*\/\//', $line );
		if ( $needs ) {
			// Infer placeholders from the string.
			preg_match_all( '/%(?:\d+\$)?[sd]/', $line, $m );
			$ph = array_unique( $m[0] );
			$comment = "\t\t/* translators: Placeholder values are inserted at runtime. */";
			if ( $ph ) {
				$comment = "\t\t/* translators: " . implode( ', ', $ph ) . ': runtime values. */';
			}
			// Match indentation of the i18n line.
			if ( preg_match( '/^(\s*)/', $line, $ind ) ) {
				$comment = $ind[1] . '/* translators: ' . ( $ph ? implode( ', ', $ph ) . ': runtime values.' : 'Placeholder values are inserted at runtime.' ) . ' */';
			}
			$out[] = $comment;
			$mod   = true;
		}
		$out[] = $line;
	}
	if ( $mod ) {
		file_put_contents( $path, implode( "\n", $out ) . ( substr( $src, -1 ) === "\n" ? "\n" : '' ) );
		$changed++;
		echo "updated $path\n";
	}
}
echo "files=$changed\n";
