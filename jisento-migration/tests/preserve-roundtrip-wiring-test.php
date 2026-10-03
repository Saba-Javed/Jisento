<?php
/**
 * C0: full-mode run-roundtrip.ps1 must run preserve-mode-test.php against MariaDB.
 *
 * Run: php tests/preserve-roundtrip-wiring-test.php
 */

require __DIR__ . '/bootstrap.php';

$ps1 = file_get_contents( __DIR__ . '/run-roundtrip.ps1' );
check( 'run-roundtrip.ps1 is readable', is_string( $ps1 ) && '' !== $ps1 );

check(
	'full mode invokes preserve-mode-test.php',
	false !== strpos( $ps1, 'tests/preserve-mode-test.php' ) && false !== strpos( $ps1, 'Preserve mode (MariaDB)' )
);

check(
	'preserve-mode run is gated to full mode (not Quick)',
	false !== strpos( $ps1, 'if (-not $Quick)' ) &&
	preg_match( '/if\s*\(\s*-not\s+\$Quick\s*\)\s*\{[^}]*preserve-mode-test\.php/s', $ps1 )
);

check(
	'preserve-mode run sets MariaDB host/port env vars',
	false !== strpos( $ps1, "JISENTO_TEST_DB_HOST = '127.0.0.1'" ) &&
	false !== strpos( $ps1, "JISENTO_TEST_DB_PORT = '3307'" ) &&
	false !== strpos( $ps1, "JISENTO_TEST_DB_USER = 'root'" ) &&
	false !== strpos( $ps1, "JISENTO_TEST_DB_PASS = 'root'" )
);

check(
	'preserve-mode uses Invoke-PhpTestRun so SKIP lines fail the harness',
	false !== strpos( $ps1, 'Invoke-PhpTestRun' ) && false !== strpos( $ps1, 'preserve-mode-test.php' )
);

$preserve = file_get_contents( __DIR__ . '/preserve-mode-test.php' );
check(
	'preserve-mode-test still SKIPs orphan author when no DB (never silent pass)',
	false !== strpos( $preserve, 'SKIP orphan author reassignment' )
);

jisento_test_finish();
