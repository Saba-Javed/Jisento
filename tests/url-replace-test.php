<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url ) { return parse_url( $url ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['jisento_test_options'][ $name ] = $value;
		return true;
	}
}

$jisento_src = getenv( 'JISENTO_SRC' ) ? rtrim( getenv( 'JISENTO_SRC' ), '/\\' ) : dirname( __DIR__ );
require_once $jisento_src . '/includes/Replace/Serializer.php';
require_once $jisento_src . '/includes/Replace/Url_Replacer.php';

use Jisento\Migration\Replace\Serializer;
use Jisento\Migration\Replace\Url_Replacer;

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

// (a) JSON is never re-encoded.
check( 'json {} without url stays {}', '{}' === $s->replace( '{}', $map ), var_export( $s->replace( '{}', $map ), true ) );
$json = '{"v":1.10,"u":"https:\\/\\/old.example\\/blog\\/x","o":{},"e":"caf\\u00e9"}';
$got  = $s->replace( $json, $map );
check( 'json keeps 1.10, {} and unicode escapes while replacing url', '{"v":1.10,"u":"https:\\/\\/new.example\\/x","o":{},"e":"caf\\u00e9"}' === $got, (string) $got );
$got = $s->replace( '[{"u":"https://old.example/blog/y"},{}]', $map );
check( 'json array with unescaped url keeps {}', '[{"u":"https://new.example/y"},{}]' === $got, (string) $got );

// (b) Unknown or malformed serialized tokens leave the whole value unchanged.
$u    = 'https://old.example/blog/x';
$su   = 's:' . strlen( $u ) . ':"' . $u . '";';
$cases = array(
	'C: token'           => 'a:2:{s:1:"u";' . $su . 's:1:"c";C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}}',
	'S: token'           => 'a:2:{s:1:"u";' . $su . 's:1:"s";S:3:"\\61bc";}',
	'E: token'           => 'a:2:{s:1:"u";' . $su . 's:1:"e";E:7:"Foo:Bar";}',
	'wrong length'       => 'a:1:{s:1:"u";s:99:"' . $u . '";}',
	'trailing garbage'   => 'a:1:{s:1:"u";' . $su . '}garbage',
	'nested unknown'     => 'a:1:{s:4:"deep";a:2:{s:1:"u";' . $su . 's:1:"c";C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}}}',
);
$before_skipped = method_exists( $s, 'skipped_count' ) ? $s->skipped_count() : 0;
foreach ( $cases as $label => $value ) {
	$got = $s->replace( $value, $map );
	check( "serialized with $label is returned unchanged", $got === $value, (string) $got );
}
check( 'unchanged serialized values are counted as skipped', method_exists( $s, 'skipped_count' ) && $s->skipped_count() - $before_skipped === count( $cases ) );

$inner  = serialize( array( 'u' => 'https://old.example/blog/in' ) );
$nested = serialize( array( 'inner' => $inner, 'json' => '{"u":"https:\\/\\/old.example\\/blog\\/j","o":{}}', 'n' => 1.5, 'b' => false, 'z' => null ) );
$got    = $s->replace( $nested, $map );
$outer  = @unserialize( $got );
$in     = is_array( $outer ) ? @unserialize( $outer['inner'] ) : false;
check( 'nested serialized and json strings are processed recursively', is_array( $in ) && 'https://new.example/in' === $in['u'] && '{"u":"https:\\/\\/new.example\\/j","o":{}}' === $outer['json'] && 1.5 === $outer['n'] && false === $outer['b'] && null === $outer['z'], (string) $got );

$umap = Serializer::build_replacements( 'https://old.example', 'https://ünï.example' );
$got  = @unserialize( $s->replace( serialize( array( 'u' => 'https://old.example/p' ) ), $umap ) );
check( 'serialized lengths are recomputed in bytes', is_array( $got ) && 'https://ünï.example/p' === $got['u'] );

// (c) Boundaries.
$hmap = Serializer::build_replacements( 'https://old.example', 'https://new.example' );
check( 'old.example does not match old.example.au', 'x https://old.example.au/p y' === $s->replace( 'x https://old.example.au/p y', $hmap ), $s->replace( 'x https://old.example.au/p y', $hmap ) );
check( 'old.example does not match old.example.com', 'https://old.example.com/x' === $s->replace( 'https://old.example.com/x', $hmap ) );
check( 'old.example does not match old.example-shop.com', 'https://old.example-shop.com' === $s->replace( 'https://old.example-shop.com', $hmap ) );
check( 'escaped old.example does not match escaped old.example.au', 'https:\\/\\/old.example.au\\/p' === $s->replace( 'https:\\/\\/old.example.au\\/p', $hmap ) );
check( 'sentence-ending period after host is replaced', 'See https://new.example.' === $s->replace( 'See https://old.example.', $hmap ), $s->replace( 'See https://old.example.', $hmap ) );
check( 'host before </p> with a period is replaced', 'Our website address is: https://new.example.</p>' === $s->replace( 'Our website address is: https://old.example.</p>', $hmap ) );
check( 'host inside parentheses is replaced', '(https://new.example)' === $s->replace( '(https://old.example)', $hmap ) );
check( 'host before a comma is replaced', 'Visit https://new.example, please' === $s->replace( 'Visit https://old.example, please', $hmap ) );
$ser_period = serialize( array( 'p' => 'Site: https://old.example.</p>' ) );
$ser_got    = @unserialize( $s->replace( $ser_period, $hmap ) );
check( 'serialized sentence-ending period is replaced', is_array( $ser_got ) && 'Site: https://new.example.</p>' === $ser_got['p'], is_array( $ser_got ) ? $ser_got['p'] : 'fail' );
$json_period = '{"u":"https:\\/\\/old.example."}';
check( 'JSON sentence-ending period is replaced', '{"u":"https:\\/\\/new.example."}' === $s->replace( $json_period, $hmap ), $s->replace( $json_period, $hmap ) );

// Email addresses on the source host.
$emap = Serializer::build_replacements( 'https://old.example', 'https://new.example', true );
check( 'email @-host form is in the replacement map', isset( $emap['@old.example'] ) && '@new.example' === $emap['@old.example'] );
check( 'plain email on the source host is replaced', 'wordpress@new.example' === $s->replace( 'wordpress@old.example', $emap ) );
check( 'user@old.example.au stays unchanged', 'user@old.example.au' === $s->replace( 'user@old.example.au', $emap ) );
check( 'bare host without @ or scheme is not replaced', 'Contact old.example for help' === $s->replace( 'Contact old.example for help', $emap ) );
$cf7 = serialize(
	array(
		'subject' => 'WordPress',
		'sender'  => 'wordpress@old.example',
		'body'    => 'From: [your-email]',
		'recipient' => 'admin@old.example',
	)
);
$cf7_got = @unserialize( $s->replace( $cf7, $emap ) );
check(
	'serialized CF7 _mail emails are replaced',
	is_array( $cf7_got ) && 'wordpress@new.example' === $cf7_got['sender'] && 'admin@new.example' === $cf7_got['recipient'],
	is_array( $cf7_got ) ? json_encode( $cf7_got ) : 'fail'
);
$ej = '{"from":"wordpress@old.example"}';
check( 'JSON email on the source host is replaced', '{"from":"wordpress@new.example"}' === $s->replace( $ej, $emap ) );
$no_mail = Serializer::build_replacements( 'https://old.example', 'https://new.example', false );
check( 'replace_emails off omits @-host forms', ! isset( $no_mail['@old.example'] ) && isset( $no_mail['https://old.example'] ) );

check( 'host form keeps following path and port', 'https://new.example/p https://new.example:8080/q https://new.example' === $s->replace( 'http://www.old.example/p https://old.example:8080/q https://old.example', $hmap ) );
check( '/blog does not match /blogger', 'https://old.example/blogger' === $s->replace( 'https://old.example/blogger', $map ), $s->replace( 'https://old.example/blogger', $map ) );
check( '/blog does not match /blog_x or /blog-x', 'https://old.example/blog_x https://old.example/blog-x' === $s->replace( 'https://old.example/blog_x https://old.example/blog-x', $map ) );
check( '/blog matches /blog/, /blog? and /blog#', 'https://new.example/ https://new.example?p=1 https://new.example#top' === $s->replace( 'https://old.example/blog/ https://old.example/blog?p=1 https://old.example/blog#top', $map ) );
$smap = Serializer::build_replacements( 'https://old.example', 'https://old.example/sub' );
check( 'destination containing source is not replaced twice', 'https://old.example/sub/a' === $s->replace( 'https://old.example/a', $smap ) );
check( 'build_replacements keeps escaped and scheme/www variants', isset( $hmap['https://old.example'], $hmap['http://www.old.example'], $hmap['https:\\/\\/www.old.example'] ) && 'https:\\/\\/new.example' === $hmap['http:\\/\\/old.example'] );

// Database behaviour.
class Jisento_Test_Fake_Wpdb {
	public $prefix       = 'wp_';
	public $last_error   = '';
	public $log          = array();
	public $tables       = array();
	public $fail_updates = false;
	public $zero_affected = array();
	private $prepared    = array();

	public function add_table( $name, array $columns, array $keys, array $rows ) {
		$cols = array();
		foreach ( $columns as $field => $def ) {
			$cols[] = array(
				'Field'     => $field,
				'Type'      => $def[0],
				'Collation' => isset( $def[2] ) ? $def[2] : ( preg_match( '/char|text/', $def[0] ) ? 'utf8mb4_unicode_ci' : null ),
				'Null'      => $def[1],
				'Key'       => '',
			);
		}
		$key_rows = array();
		foreach ( $keys as $key_name => $key_cols ) {
			foreach ( array_values( $key_cols ) as $i => $col ) {
				$key_rows[] = array( 'Table' => $name, 'Non_unique' => '0', 'Key_name' => $key_name, 'Seq_in_index' => (string) ( $i + 1 ), 'Column_name' => $col );
				foreach ( $cols as &$c ) {
					if ( $c['Field'] === $col && '' === $c['Key'] ) {
						$c['Key'] = 'PRIMARY' === $key_name ? 'PRI' : 'UNI';
					}
				}
				unset( $c );
			}
		}
		$this->tables[ $name ] = array( 'columns' => $cols, 'keys' => $key_rows, 'rows' => $rows );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query ) {
		$args = func_get_args();
		array_shift( $args );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$sql = preg_replace_callback(
			'/%%|%d|%s/',
			function ( $m ) use ( &$i, $args ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
		$this->prepared[ $sql ] = array( $query, $args );
		return $sql;
	}

	public function get_col( $sql ) {
		$this->log[]      = $sql;
		$this->last_error = '';
		$names            = array_keys( $this->tables );
		if ( isset( $this->prepared[ $sql ] ) && false !== strpos( $this->prepared[ $sql ][0], 'LIKE' ) ) {
			$like = stripcslashes( rtrim( $this->prepared[ $sql ][1][0], '%' ) );
			$out  = array();
			foreach ( $names as $name ) {
				if ( 0 === strpos( $name, $like ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}
		return $names;
	}

	public function get_results( $sql, $output = null ) {
		$this->log[]      = $sql;
		$this->last_error = '';
		if ( preg_match( '/^SHOW (?:FULL )?COLUMNS FROM `([^`]+)`/', $sql, $m ) ) {
			return $this->tables[ $m[1] ]['columns'];
		}
		if ( preg_match( '/^SHOW KEYS FROM `([^`]+)`/', $sql, $m ) ) {
			return $this->tables[ $m[1] ]['keys'];
		}
		list( $template, $args ) = $this->prepared[ $sql ];
		preg_match( '/^SELECT (.*?) FROM `([^`]+)`/', $template, $m );
		preg_match_all( '/`([^`]+)`/', $m[1], $sel );
		$table = $m[2];
		preg_match( '/ORDER BY (.*?) LIMIT/', $template, $om );
		preg_match_all( '/`([^`]+)`/', $om[1], $order );
		$order = $order[1];
		if ( false !== strpos( $template, 'OFFSET %d' ) ) {
			$offset = (int) array_pop( $args );
			$limit  = (int) array_pop( $args );
		} else {
			$offset = 0;
			$limit  = (int) array_pop( $args );
		}
		$rows = $this->tables[ $table ]['rows'];
		usort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				return Jisento_Test_Fake_Wpdb::cmp( Jisento_Test_Fake_Wpdb::pick( $a, $order ), Jisento_Test_Fake_Wpdb::pick( $b, $order ) );
			}
		);
		if ( false !== strpos( $template, ' WHERE ' ) ) {
			$cursor = $args;
			$rows   = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $order, $cursor ) {
						return Jisento_Test_Fake_Wpdb::cmp( Jisento_Test_Fake_Wpdb::pick( $row, $order ), $cursor ) > 0;
					}
				)
			);
		}
		$out = array();
		foreach ( array_slice( $rows, $offset, $limit ) as $row ) {
			$out[] = self::pick( $row, $sel[1], true );
		}
		return $out;
	}

	public function query( $sql ) {
		$this->log[]      = $sql;
		$this->last_error = '';
		list( $template, $args ) = $this->prepared[ $sql ];
		preg_match( '/^UPDATE `([^`]+)` SET (.*) WHERE (.*)$/', $template, $m );
		preg_match_all( '/`([^`]+)` = %[sd]/', $m[2], $set );
		preg_match_all( '/`([^`]+)` = %[sd]/', $m[3], $where );
		$data  = array_combine( $set[1], array_slice( $args, 0, count( $set[1] ) ) );
		$match = array_combine( $where[1], array_slice( $args, count( $set[1] ) ) );
		return $this->apply( $m[1], $data, $match );
	}

	public function update( $table, $data, $where ) {
		$this->log[]      = 'UPDATE ' . $table;
		$this->last_error = '';
		return $this->apply( $table, $data, $where );
	}

	private function apply( $table, array $data, array $where ) {
		if ( $this->fail_updates ) {
			$this->last_error = 'Deadlock found when trying to get lock';
			return false;
		}
		if ( in_array( $table, $this->zero_affected, true ) ) {
			return 0;
		}
		$affected = 0;
		foreach ( $this->tables[ $table ]['rows'] as &$row ) {
			foreach ( $where as $col => $value ) {
				if ( (string) $row[ $col ] !== (string) $value ) {
					continue 2;
				}
			}
			$changed = false;
			foreach ( $data as $col => $value ) {
				if ( $row[ $col ] !== (string) $value ) {
					$row[ $col ] = (string) $value;
					$changed     = true;
				}
			}
			$affected += $changed ? 1 : 0;
		}
		unset( $row );
		return $affected;
	}

	public static function pick( array $row, array $cols, $assoc = false ) {
		$out = array();
		foreach ( $cols as $col ) {
			if ( $assoc ) {
				$out[ $col ] = $row[ $col ];
			} else {
				$out[] = $row[ $col ];
			}
		}
		return $out;
	}

	public static function cmp( array $a, array $b ) {
		foreach ( array_values( $a ) as $i => $x ) {
			$y = $b[ $i ];
			$c = ( is_numeric( $x ) && is_numeric( $y ) ) ? ( $x + 0 <=> $y + 0 ) : strcmp( (string) $x, (string) $y );
			if ( 0 !== $c ) {
				return $c;
			}
		}
		return 0;
	}

	public function row( $table, $col, $where_col, $where_val ) {
		foreach ( $this->tables[ $table ]['rows'] as $row ) {
			if ( (string) $row[ $where_col ] === (string) $where_val ) {
				return $row[ $col ];
			}
		}
		return null;
	}
}

function jisento_test_db() {
	$old = 'https://old.example/blog';
	$db  = new Jisento_Test_Fake_Wpdb();
	$db->add_table(
		'wp_options',
		array( 'option_id' => array( 'bigint(20) unsigned', 'NO' ), 'option_name' => array( 'varchar(191)', 'NO' ), 'option_value' => array( 'longtext', 'NO' ) ),
		array( 'PRIMARY' => array( 'option_id' ), 'option_name' => array( 'option_name' ) ),
		array(
			array( 'option_id' => '1', 'option_name' => 'siteurl', 'option_value' => $old ),
			array( 'option_id' => '2', 'option_name' => 'widget', 'option_value' => serialize( array( 'u' => $old . '/w' ) ) ),
			array( 'option_id' => '3', 'option_name' => 'json', 'option_value' => '{"o":{},"u":"https:\\/\\/old.example\\/blog\\/j"}' ),
			array( 'option_id' => '4', 'option_name' => 'other', 'option_value' => 'https://old.example.au/x' ),
			array( 'option_id' => '5', 'option_name' => 'enum', 'option_value' => 'a:2:{s:1:"u";s:' . strlen( $old ) . ':"' . $old . '";s:1:"e";E:7:"Foo:Bar";}' ),
		)
	);
	$db->add_table(
		'wp_posts',
		array( 'ID' => array( 'bigint(20) unsigned', 'NO' ), 'post_content' => array( 'longtext', 'NO' ), 'guid' => array( 'varchar(255)', 'NO' ) ),
		array( 'PRIMARY' => array( 'ID' ) ),
		array(
			array( 'ID' => '1', 'post_content' => '<a href="' . $old . '/p">', 'guid' => $old . '/?p=1' ),
			array( 'ID' => '2', 'post_content' => 'https://old.example/blogger', 'guid' => $old . '/?p=2' ),
		)
	);
	$db->add_table(
		'wp_combo',
		array( 'a' => array( 'int(11)', 'NO' ), 'b' => array( 'varchar(20)', 'NO' ), 'val' => array( 'text', 'YES' ) ),
		array( 'PRIMARY' => array( 'a', 'b' ) ),
		array(
			array( 'a' => '1', 'b' => 'x', 'val' => $old . '/1x' ),
			array( 'a' => '1', 'b' => 'y', 'val' => $old . '/1y' ),
			array( 'a' => '2', 'b' => 'x', 'val' => $old . '/2x' ),
			array( 'a' => '10', 'b' => 'a', 'val' => $old . '/10a' ),
			array( 'a' => '2', 'b' => 'y', 'val' => $old . '/2y' ),
		)
	);
	$db->add_table(
		'wp_blobby',
		array(
			'id'   => array( 'int(11)', 'NO' ),
			'data' => array( 'blob', 'YES' ),
			'bin'  => array( 'varbinary(255)', 'YES' ),
			'raw'  => array( 'varchar(255)', 'YES', 'binary' ),
			'bits' => array( 'bit(8)', 'YES' ),
			'note' => array( 'text', 'YES' ),
		),
		array( 'PRIMARY' => array( 'id' ) ),
		array( array( 'id' => '1', 'data' => $old . '/b', 'bin' => $old . '/b', 'raw' => $old . '/b', 'bits' => $old, 'note' => $old . '/n' ) )
	);
	$db->add_table( 'wp_nokey', array( 'body' => array( 'text', 'YES' ) ), array(), array( array( 'body' => $old ) ) );
	$db->add_table(
		'wp_weakuniq',
		array( 'slug' => array( 'varchar(20)', 'YES' ), 'body' => array( 'text', 'YES' ) ),
		array( 'slug' => array( 'slug' ) ),
		array( array( 'slug' => 'a', 'body' => $old ) )
	);
	$db->add_table(
		'wp_uniq',
		array( 'slug' => array( 'varchar(20)', 'NO' ), 'body' => array( 'text', 'YES' ) ),
		array( 'slug' => array( 'slug' ) ),
		array( array( 'slug' => 'a', 'body' => $old . '/u' ) )
	);
	$db->add_table(
		'wp_binkey',
		array( 'k' => array( 'varchar(20)', 'NO' ), 'body' => array( 'text', 'YES' ) ),
		array( 'PRIMARY' => array( 'k' ) ),
		array(
			array( 'k' => "a\xff", 'body' => $old . '/1' ),
			array( 'k' => "b\xfe\x00'", 'body' => $old . '/2' ),
			array( 'k' => 'c', 'body' => $old . '/3' ),
		)
	);
	$db->add_table( 'wp_zero', array( 'id' => array( 'int(11)', 'NO' ), 'body' => array( 'text', 'YES' ) ), array( 'PRIMARY' => array( 'id' ) ), array( array( 'id' => '1', 'body' => $old ) ) );
	foreach ( array( 'wp_staging_options', 'wp_staging_posts', 'wp_jisento_jobs' ) as $other ) {
		$db->add_table( $other, array( 'id' => array( 'int(11)', 'NO' ), 'body' => array( 'longtext', 'NO' ) ), array( 'PRIMARY' => array( 'id' ) ), array( array( 'id' => '1', 'body' => $old ) ) );
	}
	$db->add_table( 'other_options', array( 'id' => array( 'int(11)', 'NO' ), 'body' => array( 'longtext', 'NO' ) ), array( 'PRIMARY' => array( 'id' ) ), array( array( 'id' => '1', 'body' => $old ) ) );
	$db->zero_affected = array( 'wp_zero' );
	return $db;
}

function jisento_test_run( $db, $batch, $budget, array $options = array(), $max_calls = 50 ) {
	$GLOBALS['wpdb'] = $db;
	$replacer        = new Url_Replacer( $batch );
	$state           = array();
	$result          = array( 'json_ok' => true, 'calls' => 0, 'error' => '' );
	try {
		for ( $i = 0; $i < $max_calls; $i++ ) {
			$result['calls']++;
			$res  = $replacer->replace_all( 'https://www.old.example/blog', 'https://new.example', $budget, $state, $options );
			$json = json_encode( $res );
			if ( false === $json ) {
				$result['json_ok'] = false;
				$state             = $res;
			} else {
				$state = json_decode( $json, true );
			}
			if ( ! empty( $res['done'] ) ) {
				break;
			}
		}
	} catch ( \Throwable $e ) {
		$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
	}
	$result['state'] = $state;
	return $result;
}

$GLOBALS['jisento_test_options'] = array();
$db  = jisento_test_db();
$run = jisento_test_run( $db, 2, 8 );
$st  = $run['state'];
check( 'db run completes without error', ! empty( $st['done'] ) && '' === $run['error'], $run['error'] );
check( 'composite pk rows are each updated with their own value', 'https://new.example/1x' === $db->row( 'wp_combo', 'val', 'b', 'x' ) && 'https://new.example/1y' === $db->tables['wp_combo']['rows'][1]['val'] && 'https://new.example/2y' === $db->tables['wp_combo']['rows'][4]['val'] && 'https://new.example/10a' === $db->tables['wp_combo']['rows'][3]['val'], var_export( array_column( $db->tables['wp_combo']['rows'], 'val' ), true ) );
$combo_updates = array_values( array_filter( $db->log, function ( $q ) { return 0 === strpos( $q, 'UPDATE `wp_combo`' ); } ) );
check( 'composite pk update binds the full key tuple', $combo_updates && false !== strpos( $combo_updates[0], '`a` = 1 AND `b` = \'x\'' ), var_export( $combo_updates, true ) );
$selects = array_values( array_filter( $db->log, function ( $q ) { return 0 === strpos( $q, 'SELECT' ); } ) );
$offsets = array_filter( $selects, function ( $q ) { return false !== stripos( $q, 'OFFSET' ); } );
check( 'paging never uses OFFSET', $selects && ! $offsets, var_export( array_values( $offsets ), true ) );
$keyset = array_filter( $selects, function ( $q ) { return false !== strpos( $q, 'FROM `wp_combo` WHERE (`a`, `b`) > (' ) && false !== strpos( $q, 'ORDER BY `a`, `b` LIMIT 2' ); } );
check( 'composite pk uses keyset WHERE on the full tuple', (bool) $keyset, var_export( $selects, true ) );
check( 'blob, varbinary, bit and binary-collation columns are untouched', 'https://old.example/blog/b' === $db->tables['wp_blobby']['rows'][0]['data'] && 'https://old.example/blog/b' === $db->tables['wp_blobby']['rows'][0]['bin'] && 'https://old.example/blog/b' === $db->tables['wp_blobby']['rows'][0]['raw'] && 'https://old.example/blog' === $db->tables['wp_blobby']['rows'][0]['bits'], var_export( $db->tables['wp_blobby']['rows'][0], true ) );
check( 'text column next to blobs is replaced', 'https://new.example/n' === $db->tables['wp_blobby']['rows'][0]['note'] );
check( 'guid is untouched by default', 'https://old.example/blog/?p=1' === $db->row( 'wp_posts', 'guid', 'ID', 1 ) && 'https://new.example/p' === substr( $db->row( 'wp_posts', 'post_content', 'ID', 1 ), 9, 21 ), $db->row( 'wp_posts', 'guid', 'ID', 1 ) );
check( '/blogger post is not rewritten', 'https://old.example/blogger' === $db->row( 'wp_posts', 'post_content', 'ID', 2 ) );
check( 'json option keeps {}', '{"o":{},"u":"https:\\/\\/new.example\\/j"}' === $db->row( 'wp_options', 'option_value', 'option_id', 3 ), (string) $db->row( 'wp_options', 'option_value', 'option_id', 3 ) );
check( 'serialized option is rewritten validly', array( 'u' => 'https://new.example/w' ) === @unserialize( (string) $db->row( 'wp_options', 'option_value', 'option_id', 2 ) ) );
check( 'old.example.au option is untouched', 'https://old.example.au/x' === $db->row( 'wp_options', 'option_value', 'option_id', 4 ) );
check( 'unique NOT NULL index is used when there is no primary key', 'https://new.example/u' === $db->row( 'wp_uniq', 'body', 'slug', 'a' ) );
check( 'tables without a usable key are reported in skipped_tables', isset( $st['skipped_tables']['wp_nokey'], $st['skipped_tables']['wp_weakuniq'] ) && is_string( $st['skipped_tables']['wp_nokey'] ) && 'https://old.example/blog' === $db->row( 'wp_nokey', 'body', 'body', 'https://old.example/blog' ), var_export( isset( $st['skipped_tables'] ) ? $st['skipped_tables'] : null, true ) );
check( 'other install and plugin job tables are untouched', 'https://old.example/blog' === $db->row( 'wp_staging_options', 'body', 'id', 1 ) && 'https://old.example/blog' === $db->row( 'wp_staging_posts', 'body', 'id', 1 ) && 'https://old.example/blog' === $db->row( 'wp_jisento_jobs', 'body', 'id', 1 ) && 'https://old.example/blog' === $db->row( 'other_options', 'body', 'id', 1 ) );
$expected_updated = 3 + 1 + 5 + 1 + 1 + 3;
check( 'updated counts only rows actually affected', isset( $st['updated'] ) && $expected_updated === (int) $st['updated'], 'got ' . ( isset( $st['updated'] ) ? $st['updated'] : 'none' ) . ", want $expected_updated" );
check( 'skipped_values counts serialized values left unchanged', isset( $st['skipped_values'] ) && 1 === (int) $st['skipped_values'] && false !== strpos( $db->row( 'wp_options', 'option_value', 'option_id', 5 ), 'E:7:"Foo:Bar"' ) && false !== strpos( $db->row( 'wp_options', 'option_value', 'option_id', 5 ), 'old.example' ) );
check( 'return shape keeps legacy and new keys', ! array_diff( array( 'done', 'updated', 'tables', 'index', 'table', 'offset', 'cursor', 'skipped_tables', 'skipped_values', 'emails_updated', 'paths_updated' ), array_keys( $st ) ), implode( ',', array_keys( $st ) ) );
check( 'siteurl and home are updated at the end', isset( $GLOBALS['jisento_test_options']['siteurl'], $GLOBALS['jisento_test_options']['home'] ) && 'https://new.example' === $GLOBALS['jisento_test_options']['home'] );

$db  = jisento_test_db();
$run = jisento_test_run( $db, 2, 0, array( 'only_tables' => array( 'wp_binkey', 'wp_combo', 'wp_missing' ) ), 30 );
check( 'resumable run with zero budget finishes one batch per call', ! empty( $run['state']['done'] ) && '' === $run['error'], $run['error'] . ' calls=' . $run['calls'] );
check( 'state with binary key cursor survives json', $run['json_ok'] );
check( 'binary keys are paged and updated', 'https://new.example/1' === $db->row( 'wp_binkey', 'body', 'k', "a\xff" ) && 'https://new.example/2' === $db->row( 'wp_binkey', 'body', 'k', "b\xfe\x00'" ) && 'https://new.example/3' === $db->row( 'wp_binkey', 'body', 'k', 'c' ) );
check( 'only_tables processes exactly the listed existing tables', isset( $run['state']['tables'] ) && 2 === count( $run['state']['tables'] ) && 'https://old.example/blog' === $db->row( 'wp_options', 'option_value', 'option_id', 1 ) && 'https://new.example/2y' === $db->tables['wp_combo']['rows'][4]['val'] );

$db  = jisento_test_db();
$run = jisento_test_run( $db, 200, 8, array( 'only_tables' => array( 'wp_posts' ), 'replace_guids' => true ) );
check( 'guid is replaced when replace_guids is set', 'https://new.example/?p=1' === $db->row( 'wp_posts', 'guid', 'ID', 1 ), (string) $db->row( 'wp_posts', 'guid', 'ID', 1 ) );

$db               = jisento_test_db();
$db->fail_updates = true;
$run              = jisento_test_run( $db, 200, 8, array( 'only_tables' => array( 'wp_options' ) ) );
check( 'failed update throws RuntimeException with table, key and db error', 0 === strpos( $run['error'], 'RuntimeException' ) && false !== strpos( $run['error'], 'wp_options' ) && false !== strpos( $run['error'], 'option_id' ) && false !== strpos( $run['error'], 'Deadlock' ), $run['error'] );
check( 'failure message does not leak row values', '' !== $run['error'] && false === strpos( $run['error'], 'old.example' ) && false === strpos( $run['error'], 'new.example' ) );

// (h) Table ownership.
if ( method_exists( 'Jisento\Migration\Replace\Url_Replacer', 'own_tables' ) ) {
	$own = Url_Replacer::own_tables( array( 'wp_options', 'wp_posts', 'wp_staging_options', 'wp_staging_posts', 'wp_staging_postmeta', 'wp_jisento_jobs', 'wp_wc_orders', 'wp_yoast_options', 'wpx_options' ), 'wp_' );
	check( 'own_tables excludes a nested install and jisento tables', array( 'wp_options', 'wp_posts', 'wp_wc_orders', 'wp_yoast_options' ) === $own, implode( ',', $own ) );
	$own = Url_Replacer::own_tables( array( 'wp_blogs', 'wp_options', 'wp_posts', 'wp_2_options', 'wp_2_posts', 'wp_staging_options', 'wp_staging_posts' ), 'wp_' );
	check( 'own_tables keeps multisite subsite tables', array( 'wp_blogs', 'wp_options', 'wp_posts', 'wp_2_options', 'wp_2_posts' ) === $own, implode( ',', $own ) );
} else {
	check( 'own_tables exists', false );
}

/* --- filesystem ABSPATH / file:// path replacement --- */
$src_abs = '/home/source-user/domains/source.example/public_html';
$dst_abs = '/home/dest-user/domains/dest.example/public_html';
$path_map = Serializer::build_path_replacements( $src_abs . '/', $dst_abs . '/' );
check( 'path map includes plain ABSPATH', isset( $path_map[ $src_abs ] ) && $dst_abs === $path_map[ $src_abs ] );
check( 'path map includes trailing-slash form', isset( $path_map[ $src_abs . '/' ] ) && $dst_abs . '/' === $path_map[ $src_abs . '/' ] );
$file_from = 'file://' . $src_abs;
check( 'path map includes file:// form', isset( $path_map[ $file_from ] ), implode( ' | ', array_keys( $path_map ) ) );

$s = new Serializer();
$wc = 'file:///home/source-user/domains/source.example/public_html/wp-content/uploads/woocommerce_uploads/';
$got = $s->replace( $wc, $path_map );
check(
	'WooCommerce download directory file:// path is rewritten',
	'file:///home/dest-user/domains/dest.example/public_html/wp-content/uploads/woocommerce_uploads/' === $got,
	(string) $got
);

$ser = serialize( array( 'path' => $src_abs . '/wp-content/uploads/file.pdf' ) );
$out = $s->replace( $ser, $path_map );
$back = unserialize( $out );
check(
	'serialized setting holding a path is rewritten',
	is_array( $back ) && $dst_abs . '/wp-content/uploads/file.pdf' === $back['path'],
	var_export( $back, true )
);

$similar = '/home/source-user/domains/source.example/public_html2/wp-content/uploads/x';
$got = $s->replace( $similar, $path_map );
check( 'similar non-matching path is unchanged', $similar === $got, (string) $got );

$exporter = file_get_contents( $jisento_src . '/includes/Export/Exporter.php' );
check( 'manifest records source abspath', false !== strpos( $exporter, "'abspath'" ) );

echo $failed ? "\n$failed failed\n" : "\nURL checks passed\n";
exit( $failed ? 1 : 0 );
