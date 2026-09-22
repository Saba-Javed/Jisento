<?php
define( 'ABSPATH', __DIR__ );
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
require dirname( __DIR__ ) . '/includes/Replace/Serializer.php';

use Jisento\Migration\Replace\Serializer;

$failed = 0;
function check( $name, $ok, $detail = '' ) {
	global $failed;
	if ( $ok ) { echo "OK  $name\n"; return; }
	$failed++;
	echo "FAIL $name" . ( $detail ? " — $detail" : '' ) . "\n";
}

$map = Serializer::build_replacements( 'https://www.old.example/blog', 'https://new.example' );
$s   = new Serializer();
$raw = serialize( array( 'url' => 'https://old.example/blog/page', 'keep' => 'https://other.example' ) );
$out = $s->replace( $raw, $map );
$back = unserialize( $out );
check( 'serialized url survives replacement', is_array( $back ) && 'https://new.example/page' === $back['url'], var_export( $back, true ) );
check( 'unrelated host is unchanged', is_array( $back ) && 'https://other.example' === $back['keep'] );

$json = '{"link":"https:\\/\\/old.example\\/blog\\/a"}';
$replaced = $s->replace( $json, $map );
check( 'json escaped url is replaced', false !== strpos( (string) $replaced, 'new.example' ) && false === strpos( (string) $replaced, 'old.example' ), (string) $replaced );

$plain = $s->replace( 'See https://old.example/blog and http://www.old.example/blog/x', $map );
check( 'http and www forms map to the destination', 'See https://new.example and https://new.example/x' === $plain, $plain );

echo $failed ? "\n$failed failed\n" : "\nURL checks passed\n";
exit( $failed ? 1 : 0 );
