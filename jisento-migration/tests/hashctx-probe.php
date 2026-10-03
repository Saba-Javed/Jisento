<?php
// Probe: can a HashContext be serialized mid-stream and resumed in a new process?
$out = array( 'php' => PHP_VERSION );
foreach ( array( 'sha256', 'crc32b' ) as $algo ) {
	$h = hash_init( $algo );
	hash_update( $h, 'abc' );
	try {
		$s = serialize( $h );
		$u = unserialize( $s );
		hash_update( $u, 'def' );
		$out[ $algo ] = hash_final( $u ) === hash( $algo, 'abcdef' ) ? 'OK' : 'MISMATCH';
	} catch ( Throwable $e ) {
		$out[ $algo ] = 'FAIL ' . get_class( $e ) . ': ' . $e->getMessage();
	}
}
echo json_encode( $out ), "\n";
