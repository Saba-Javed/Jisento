<?php
/**
 * B2: Separate Migration Key send and receive flows.
 *
 * Run: php tests/key-flows-test.php
 */

require __DIR__ . '/bootstrap.php';

$send    = file_get_contents( dirname( __DIR__ ) . '/admin/views/key-send.php' );
$recv    = file_get_contents( dirname( __DIR__ ) . '/admin/views/key-receive.php' );
$import  = file_get_contents( dirname( __DIR__ ) . '/admin/views/import.php' );
$mig     = file_get_contents( dirname( __DIR__ ) . '/admin/views/migration.php' );
$admin   = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/Admin.php' );
$js      = file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
$partial = file_get_contents( dirname( __DIR__ ) . '/admin/views/partials/mode-review.php' );

check( 'key-send has generate only', false !== strpos( $send, 'jisento-generate-key' ) && false === strpos( $send, 'jisento-connect' ) );
check( 'key-send has copy target display', false !== strpos( $send, 'jisento-key-display' ) );
check( 'key-send has active keys table', false !== strpos( $send, 'jisento-keys-table' ) );
check( 'key-send has no connect UI', false === strpos( $send, 'jisento-connect' ) && false === strpos( $send, 'jisento-connect-key' ) );
check( 'key-receive has Connect only', false !== strpos( $recv, 'jisento-connect' ) && false === strpos( $recv, 'jisento-generate-key' ) );
check( 'key-receive has key field', false !== strpos( $recv, 'jisento-connect-key' ) );
check( 'key-receive includes mode/review', false !== strpos( $recv, 'partials/mode-review.php' ) );
check( 'import includes mode/review partial', false !== strpos( $import, 'partials/mode-review.php' ) );
check( 'mode-review has Replace this site', false !== strpos( $partial, 'Replace this site' ) );
check( 'mode-review has Start migration', false !== strpos( $partial, 'Start migration' ) );
check( 'menu registers key-send page', false !== strpos( $admin, 'jisento-key-send' ) && false !== strpos( $admin, 'page_key_send' ) );
check( 'menu registers key-receive page', false !== strpos( $admin, 'jisento-key-receive' ) && false !== strpos( $admin, 'page_key_receive' ) );
check( 'migration hub links to key-send', false !== strpos( $mig, 'page=jisento-key-send' ) );
check( 'migration hub links to key-receive', false !== strpos( $mig, 'page=jisento-key-receive' ) );
check( 'JS copy key button', false !== strpos( $js, 'jisento-copy-key' ) || false !== strpos( $js, 'Copy' ) );
check( 'JS paste instruction line', false !== strpos( $js, 'Receive migration from key' ) );
check( 'JS receive uses connection result then wizard', false !== strpos( $js, 'Connection result' ) && false !== strpos( $js, "type: 'receive'" ) );
check( 'JS start can create receive job', false !== strpos( $js, 'remoteSession' ) && false !== strpos( $js, "type: 'receive'" ) );
check( 'send page has no Test Connection', false === strpos( $send, 'Test Connection' ) && false === strpos( $recv, 'jisento-test-connection' ) );

jisento_test_finish();
