<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Core/Live_Url.php';

use Jisento\Migration\Core\Live_Url;

$failed = 0;
function check( $name, $ok, $detail = '' ) {
	global $failed;
	if ( $ok ) {
		echo "OK  $name\n";
		return;
	}
	$failed++;
	echo "FAIL $name" . ( $detail ? " — $detail" : '' ) . "\n";
}

check(
	'package domain on the live site is healed when the request host differs',
	Live_Url::should_heal( 'https://source.example', 'https://source.example', 'dest.example', array( 'https://source.example' ) )
);
check(
	'a healthy destination URL is left alone',
	! Live_Url::should_heal( 'https://dest.example', 'https://dest.example', 'dest.example', array( 'https://source.example' ) )
);
check(
	'an unrelated live domain is left alone',
	! Live_Url::should_heal( 'https://other.example', 'https://other.example', 'dest.example', array( 'https://source.example' ) )
);
check(
	'restored URL keeps the path and uses the request host',
	'https://dest.example/blog' === Live_Url::url_with_host( 'https://source.example/blog', 'dest.example', true )
);

$controller = file_get_contents( dirname( __DIR__ ) . '/includes/Api/Rest_Controller.php' );
$archive    = file_get_contents( dirname( __DIR__ ) . '/includes/Package/Archive.php' );
check( 'upload and validate do not update options', false === strpos( $controller, 'update_option' ) );
check( 'upload and validate do not extract the package', false === strpos( $controller, 'extractTo' ) && false === strpos( $controller, 'import_chunk' ) );
$inspect = substr( $archive, strpos( $archive, 'function inspect' ), strpos( $archive, 'function has_files_prefix' ) - strpos( $archive, 'function inspect' ) );
check( 'package inspect does not extract or write the database', false === strpos( $inspect, 'extractTo' ) && false === strpos( $inspect, 'query(' ) );

echo $failed ? "\n$failed failed\n" : "\nLive URL checks passed\n";
exit( $failed ? 1 : 0 );
