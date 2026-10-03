<?php
$path = $argv[1] ?? '';
$raw  = file_get_contents( $path );
$j    = json_decode( $raw, true );
if ( ! is_array( $j ) ) {
	fwrite( STDERR, "bad json\n" );
	exit( 1 );
}
$rows = $j;
if ( isset( $j['results'] ) && is_array( $j['results'] ) ) {
	$rows = $j['results'];
} elseif ( isset( $j['jisento-migration'] ) && is_array( $j['jisento-migration'] ) ) {
	$rows = $j['jisento-migration'];
}
$errors = array();
$warnings = array();
$by_file = array();
foreach ( $rows as $r ) {
	if ( ! is_array( $r ) ) {
		continue;
	}
	$t = isset( $r['type'] ) ? strtoupper( (string) $r['type'] ) : '';
	$c = isset( $r['code'] ) ? (string) $r['code'] : '?';
	$f = isset( $r['file'] ) ? (string) $r['file'] : '?';
	if ( 'ERROR' === $t ) {
		$errors[ $c ] = isset( $errors[ $c ] ) ? $errors[ $c ] + 1 : 1;
		$by_file[ $f ] = isset( $by_file[ $f ] ) ? $by_file[ $f ] + 1 : 1;
	}
	if ( 'WARNING' === $t ) {
		$warnings[ $c ] = isset( $warnings[ $c ] ) ? $warnings[ $c ] + 1 : 1;
	}
}
echo 'ERRORS=' . array_sum( $errors ) . ' unique=' . count( $errors ) . "\n";
arsort( $errors );
foreach ( array_slice( $errors, 0, 25, true ) as $k => $v ) {
	echo "E $v\t$k\n";
}
echo 'WARNINGS=' . array_sum( $warnings ) . ' unique=' . count( $warnings ) . "\n";
arsort( $warnings );
foreach ( array_slice( $warnings, 0, 25, true ) as $k => $v ) {
	echo "W $v\t$k\n";
}
echo "TOP FILES\n";
arsort( $by_file );
foreach ( array_slice( $by_file, 0, 15, true ) as $k => $v ) {
	echo "F $v\t$k\n";
}
