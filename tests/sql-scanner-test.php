<?php
/**
 * Parser cases for real WordPress dumps. Run: php tests/sql-scanner-test.php
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/Database/Sql_Scanner.php';

use Jisento\Migration\Database\Sql_Scanner;

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

function esc_sql( $value ) {
	return strtr(
		(string) $value,
		array(
			"\0"  => '\\0',
			"\n"  => '\\n',
			"\r"  => '\\r',
			'\\'  => '\\\\',
			"'"   => "\\'",
			'"'   => '\\"',
			"\x1a" => '\\Z',
		)
	);
}

function insert( $table, $values ) {
	$quoted = array();
	foreach ( $values as $value ) {
		$quoted[] = null === $value ? 'NULL' : "'" . esc_sql( $value ) . "'";
	}
	return 'INSERT INTO `' . $table . '` (`id`,`msg`) VALUES (' . implode( ',', $quoted ) . ');';
}

$two = insert( 'wp_wfstatus', array( '1', "blocked; see log" ) ) . "\n" . insert( 'wp_wfstatus', array( '2', "next row" ) );
$parts = Sql_Scanner::statements( $two );
check( 'two wfstatus inserts stay separate', 2 === count( $parts ), 'got ' . count( $parts ) );

$nasty = "it's a status; DROP TABLE wp_users; <p style=\"color:red\">x;</p> " . '{"a":"b;c"}' . " a:1:{s:3:\"url\";s:18:\"https://old.com\";}";
$nasty .= "\x1E{9da8d8863dd0ca";
$script = insert( 'wp_wfstatus', array( '9', $nasty ) ) . "\n" . insert( 'wp_posts', array( '3', "hello" ) );
$parts  = Sql_Scanner::statements( $script );
check( 'serialized, json, html, and control bytes do not glue the next insert', 2 === count( $parts ) && false !== strpos( $parts[0], 'wp_wfstatus' ) && false !== strpos( $parts[1], 'wp_posts' ) );

$comment = insert( 'wp_wfstatus', array( '1', 'ok' ) ) . "\n-- user said: it's done;\n" . insert( 'wp_wfstatus', array( '2', 'next' ) );
$parts   = Sql_Scanner::statements( $comment );
check( 'apostrophe in a -- comment does not swallow the next insert', 2 === count( $parts ), 'got ' . count( $parts ) );

$hash = insert( 'wp_wfstatus', array( '1', 'ok' ) ) . "\n# it's done;\n" . insert( 'wp_wfstatus', array( '2', 'next' ) );
check( 'hash comment does not swallow the next insert', 2 === count( Sql_Scanner::statements( $hash ) ) );

$block = "INSERT INTO `wp_wfstatus` (`id`,`msg`) VALUES (1,'a');\n/* semi; 'quote' */\nINSERT INTO `wp_wfstatus` (`id`,`msg`) VALUES (2,'b');";
check( 'block comment does not split or glue', 2 === count( Sql_Scanner::statements( $block ) ) );

$ident = "INSERT INTO `it's_status` (`msg`) VALUES ('a');\nINSERT INTO `wp_wfstatus` (`msg`) VALUES ('b');";
$parts = Sql_Scanner::statements( $ident );
check( 'apostrophe inside a backtick name does not glue statements', 2 === count( $parts ), 'got ' . count( $parts ) );

$empty = "INSERT INTO `wp_options` (`option_id`,`option_value`) VALUES (1,'');\nINSERT INTO `wp_options` (`option_id`,`option_value`) VALUES (2,'keep');";
check( 'empty string does not glue the next insert', 2 === count( Sql_Scanner::statements( $empty ) ) );

$doubled = "INSERT INTO `wp_posts` (`post_content`) VALUES ('it''s a post; still one');\nINSERT INTO `wp_posts` (`post_content`) VALUES ('two');";
$parts   = Sql_Scanner::statements( $doubled );
check( 'doubled quotes keep the semicolon inside the value', 2 === count( $parts ) && false !== strpos( $parts[0], "it''s" ) );

$create = "CREATE TABLE `wp_wfstatus` (\n  `msg` varchar(1000) NOT NULL COMMENT 'status; do not edit'\n) ENGINE=InnoDB;\n" . insert( 'wp_wfstatus', array( '1', 'row' ) );
$parts  = Sql_Scanner::statements( $create );
check( 'create comment semicolon stays inside the create', 2 === count( $parts ) && 0 === strpos( $parts[0], 'CREATE TABLE' ) );

$multi = insert( 'wp_wfstatus', array( '1', 'a' ) ) . insert( 'wp_wfstatus', array( '2', 'b' ) );
$split = Sql_Scanner::split_insert( $multi, 10 );
check( 'split_insert does not merge two statements into one VALUES list', 2 === count( $split ) && 1 === substr_count( $split[0], 'INSERT INTO' ) && 1 === substr_count( $split[1], 'INSERT INTO' ) );

$wide = "INSERT INTO `wp_postmeta` (`meta_id`,`meta_value`) VALUES ('1','" . str_repeat( 'x', 400 ) . "'),('2','" . str_repeat( 'y', 400 ) . "');";
$split = Sql_Scanner::split_insert( $wide, 500 );
check( 'a wide insert splits into more than one statement', count( $split ) > 1 );
foreach ( $split as $i => $piece ) {
	if ( 1 !== count( Sql_Scanner::statements( $piece ) ) ) {
		check( 'split piece ' . $i . ' is one statement', false );
	}
}
check( 'every split piece is a single statement', true );

$elementor = insert(
	'wp_postmeta',
	array(
		'5',
		'{"settings":{"url":"https://old.com/path;x"},"html":"<div class=\'a;b\'>ok</div>"}',
	)
) . "\n" . insert( 'wp_postmeta', array( '6', 'after' ) );
check( 'elementor-like json stays one statement', 2 === count( Sql_Scanner::statements( $elementor ) ) );

$woo = insert( 'wp_woocommerce_sessions', array( '7', "a:1:{s:3:\"key\";s:4:\"a;b;\";}" ) ) . "\n" . insert( 'wp_woocommerce_sessions', array( '8', 'z' ) );
check( 'woocommerce serialized session stays one statement', 2 === count( Sql_Scanner::statements( $woo ) ) );

$acf = insert( 'wp_postmeta', array( '9', "field_abc; s:3:\"old\";" ) ) . "\n" . insert( 'wp_postmeta', array( '10', 'tail' ) );
check( 'acf-like meta stays one statement', 2 === count( Sql_Scanner::statements( $acf ) ) );

$hex = "INSERT INTO `wp_wfconfig` (`name`,`val`,`autoload`) VALUES ('activatingIP',0x3130332e32353332,'yes'),('actUpdateInterval',0x32,'yes'),('addCacheComment',0x30,'yes'),('emptyVal',X'','yes');\n";
$hex .= "INSERT INTO `wp_plain` (`id`,`note`) VALUES (4,'semi;colon'),(5,'it\\'s \"ok\"'),(6,NULL);\n";
$hex_parts = Sql_Scanner::statements( $hex );
check( 'hex literals and the following insert stay separate', 2 === count( $hex_parts ) && false !== strpos( $hex_parts[0], '0x3130332e32353332' ) && false !== strpos( $hex_parts[0], "X''" ) && false === strpos( $hex_parts[0], 'semi;colon' ) );
$hex_tuples = Sql_Scanner::value_tuples( preg_replace( '/^.*VALUES\s*/is', '', rtrim( $hex_parts[0], "; \n" ) ) );
check( 'each hex value stays inside its own row', 4 === count( $hex_tuples ) && false !== strpos( $hex_tuples[0], '0x3130332e32353332' ) && false !== strpos( $hex_tuples[3], "X''" ) );

$binary = "INSERT INTO `wp_wffilemods` (`filenameMD5`,`filename`) VALUES ('\x1E{ef282a0f7b1355','wp-content/plugins/elementor/asse');\n";
$parts  = array();
$rest   = $binary;
while ( '' !== $rest ) {
	$piece = substr( $rest, 0, 5 );
	$rest  = substr( $rest, 5 );
	$parts[] = $piece;
}
$buffer = '';
$found  = '';
foreach ( $parts as $piece ) {
	$buffer .= $piece;
	$split = Sql_Scanner::next_statement( $buffer );
	if ( null !== $split ) {
		$found  = $split[0];
		$buffer = $split[1];
	}
}
check( 'a control byte on a chunk boundary does not shift the statement', $found === trim( $binary ) && "\x1E" === $found[ strpos( $found, "\x1E" ) ] );

$path = sys_get_temp_dir() . '/jisento-scanner-stream.sql';
$fh   = fopen( $path, 'wb' );
$count = 20000;
for ( $i = 1; $i <= $count; $i++ ) {
	fwrite( $fh, insert( 'wp_wfstatus', array( (string) $i, "row $i; it's ok \x1E" ) ) . "\n" );
}
fclose( $fh );
$bytes = filesize( $path );
$read  = fopen( $path, 'rb' );
$pending = '';
$seen = 0;
$mem_before = memory_get_usage( true );
while ( ! feof( $read ) ) {
	$chunk = fread( $read, 262144 );
	if ( false === $chunk || '' === $chunk ) {
		break;
	}
	$pending .= $chunk;
	while ( true ) {
		$split = Sql_Scanner::next_statement( $pending );
		if ( null === $split ) {
			break;
		}
		$seen++;
		$pending = $split[1];
	}
}
fclose( $read );
@unlink( $path );
$peak = memory_get_peak_usage( true );
check( 'streamed ' . $count . ' statements from ' . $bytes . ' bytes', $seen === $count, 'seen ' . $seen );
check( 'scanner did not load the whole file as one string', $peak < ( $bytes + 8 * 1024 * 1024 ), 'peak ' . $peak );

echo $failed ? "\n$failed failed\n" : "\nAll scanner checks passed\n";
exit( $failed ? 1 : 0 );
