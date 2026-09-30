<?php
/**
 * Minimal WordPress stubs so plugin classes run under plain "php tests/xxx.php".
 *
 * Database tests read JISENTO_TEST_DB_HOST, _PORT, _USER, _PASS (defaults 127.0.0.1:3317 root/root)
 * and skip themselves when no server answers.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/jisento-wp/' );
}
if ( ! defined( 'JISENTO_PATH' ) ) {
	define( 'JISENTO_PATH', ( getenv( 'JISENTO_SRC' ) ? rtrim( getenv( 'JISENTO_SRC' ), '/\\' ) : dirname( __DIR__ ) ) . '/' );
}
if ( ! defined( 'JISENTO_VERSION' ) ) {
	define( 'JISENTO_VERSION', 'test' );
}
if ( ! defined( 'JISENTO_PACKAGE_VERSION' ) ) {
	define( 'JISENTO_PACKAGE_VERSION', '2.0' );
}
if ( ! defined( 'JISENTO_MAGIC' ) ) {
	define( 'JISENTO_MAGIC', "JISENTO\x1A" );
}
if ( ! defined( 'JISENTO_FORMAT_MARKER' ) ) {
	define( 'JISENTO_FORMAT_MARKER', 'JISENTO-PACKAGE-v2' );
}
if ( ! defined( 'JISENTO_FORMAT_MARKER_V1' ) ) {
	define( 'JISENTO_FORMAT_MARKER_V1', 'JISENTO-PACKAGE-v1' );
}
if ( ! defined( 'JISENTO_SIGNATURE' ) ) {
	define( 'JISENTO_SIGNATURE', 'JISENTO-PACKAGE-v1' );
}
foreach ( array( 'ARRAY_A', 'ARRAY_N', 'OBJECT' ) as $jisento_const ) {
	if ( ! defined( $jisento_const ) ) {
		define( $jisento_const, $jisento_const );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}
		public function get_error_message( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}
		public function get_error_data( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

$GLOBALS['jisento_test_options'] = array();

$jisento_stubs = array(
	'__'                  => function ( $t ) { return $t; },
	'esc_html__'          => function ( $t ) { return $t; },
	'esc_html'            => function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); },
	'esc_attr'            => function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); },
	'is_wp_error'         => function ( $x ) { return $x instanceof WP_Error; },
	'sanitize_key'        => function ( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); },
	'sanitize_text_field' => function ( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); },
	'sanitize_file_name'  => function ( $s ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $s ); },
	'wp_json_encode'      => function ( $d, $o = 0 ) { return json_encode( $d, $o ); },
	'untrailingslashit'   => function ( $s ) { return rtrim( (string) $s, '/\\' ); },
	'trailingslashit'     => function ( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; },
	'wp_parse_url'        => function ( $u, $c = -1 ) { return parse_url( (string) $u, $c ); },
	'wp_normalize_path'   => function ( $p ) { return str_replace( '\\', '/', (string) $p ); },
	'wp_unslash'          => function ( $v ) { return $v; },
	'absint'              => function ( $v ) { return abs( (int) $v ); },
	'wp_parse_args'       => function ( $a, $d = array() ) { return array_merge( $d, (array) $a ); },
	'current_time'        => function ( $t ) { return gmdate( 'Y-m-d H:i:s' ); },
	'size_format'         => function ( $b ) { return (string) $b . ' B'; },
	'home_url'            => function () { return 'https://destination.test'; },
	'site_url'            => function () { return 'https://destination.test'; },
	'apply_filters'       => function ( $tag, $value ) { return $value; },
	'do_action'           => function () {},
	'add_action'          => function () {},
	'add_filter'          => function () {},
	'is_multisite'        => function () { return ! empty( $GLOBALS['jisento_test_multisite'] ); },
	'wp_mkdir_p'          => function ( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); },
	'add_query_arg'       => function () {
		$args = func_get_args();
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = isset( $args[1] ) ? (string) $args[1] : '';
		} else {
			$params = array( $args[0] => isset( $args[1] ) ? $args[1] : '' );
			$url    = isset( $args[2] ) ? (string) $args[2] : '';
		}
		$parts = parse_url( $url );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		foreach ( $params as $k => $v ) {
			$query[ $k ] = $v;
		}
		$base = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
			. ( isset( $parts['host'] ) ? $parts['host'] : '' )
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' );
		return $base . ( $query ? '?' . http_build_query( $query ) : '' );
	},
	'get_option'          => function ( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['jisento_test_options'] ) ? $GLOBALS['jisento_test_options'][ $k ] : $d; },
	'update_option'       => function ( $k, $v ) { $GLOBALS['jisento_test_options'][ $k ] = $v; return true; },
	'delete_option'       => function ( $k ) { unset( $GLOBALS['jisento_test_options'][ $k ] ); return true; },
	'maybe_serialize'     => function ( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; },
	'maybe_unserialize'   => function ( $v ) { $u = @unserialize( (string) $v ); return ( false === $u && 'b:0;' !== $v ) ? $v : $u; },
	'wp_cache_delete'     => function () { return true; },
);
foreach ( $jisento_stubs as $jisento_name => $jisento_fn ) {
	if ( ! function_exists( $jisento_name ) ) {
		$GLOBALS['jisento_stub_fns'][ $jisento_name ] = $jisento_fn;
		eval( 'function ' . $jisento_name . '() { return call_user_func_array( $GLOBALS["jisento_stub_fns"]["' . $jisento_name . '"], func_get_args() ); }' ); // phpcs:ignore
	}
}

require_once JISENTO_PATH . 'includes/Autoloader.php';
\Jisento\Migration\Autoloader::register();

$GLOBALS['jisento_failed'] = 0;
$GLOBALS['jisento_passed'] = 0;
if ( ! function_exists( 'check' ) ) {
	function check( $name, $ok, $detail = '' ) {
		if ( $ok ) {
			$GLOBALS['jisento_passed']++;
			echo "OK   $name\n";
			return true;
		}
		$GLOBALS['jisento_failed']++;
		echo "FAIL $name" . ( '' !== (string) $detail ? ' -- ' . $detail : '' ) . "\n";
		return false;
	}
}

function jisento_test_finish() {
	echo "\n" . $GLOBALS['jisento_passed'] . ' passed, ' . $GLOBALS['jisento_failed'] . " failed\n";
	exit( $GLOBALS['jisento_failed'] > 0 ? 1 : 0 );
}

/**
 * Enough of wpdb, over a real mysqli connection, for the exporter and importer.
 */
class Jisento_Test_Wpdb {
	public $prefix     = 'wp_';
	public $dbh;
	public $last_error = '';
	public $insert_id  = 0;
	public $charset    = 'utf8mb4';
	public $collate    = '';
	public $dbname     = '';
	public $options;
	public $users;
	public $usermeta;
	public $posts;
	public $queries    = array();

	public function __construct( mysqli $dbh, $dbname, $prefix = 'wp_' ) {
		$this->dbh    = $dbh;
		$this->dbname = $dbname;
		$this->set_prefix( $prefix );
		$this->set_charset( $dbh );
	}

	public function set_prefix( $prefix ) {
		$this->prefix   = $prefix;
		$this->options  = $prefix . 'options';
		$this->users    = $prefix . 'users';
		$this->usermeta = $prefix . 'usermeta';
		$this->posts    = $prefix . 'posts';
	}

	public function set_charset( $dbh, $charset = null, $collate = null ) {
		mysqli_set_charset( $dbh, $charset ? $charset : $this->charset );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query ) {
		$args = array_slice( func_get_args(), 1 );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$dbh = $this->dbh;
		return preg_replace_callback(
			'/%%|%[sdf]/',
			static function ( $m ) use ( &$args, &$i, $dbh ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$value = $args[ $i++ ];
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $value;
				}
				return "'" . mysqli_real_escape_string( $dbh, (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		try {
			$result = mysqli_query( $this->dbh, $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
		if ( false === $result ) {
			$this->last_error = mysqli_error( $this->dbh );
			return false;
		}
		if ( $result instanceof mysqli_result ) {
			$rows = array();
			while ( $row = mysqli_fetch_assoc( $result ) ) {
				$rows[] = $row;
			}
			mysqli_free_result( $result );
			$this->last_result = $rows;
			return count( $rows );
		}
		$this->insert_id = mysqli_insert_id( $this->dbh );
		return mysqli_affected_rows( $this->dbh );
	}

	public $last_result = array();

	private function fetch( $sql, $mode ) {
		$this->last_error = '';
		try {
			$result = mysqli_query( $this->dbh, $sql );
		} catch ( mysqli_sql_exception $e ) {
			$this->last_error = $e->getMessage();
			return null;
		}
		if ( ! ( $result instanceof mysqli_result ) ) {
			if ( false === $result ) {
				$this->last_error = mysqli_error( $this->dbh );
			}
			return null;
		}
		$rows = array();
		while ( $row = ( 'ARRAY_N' === $mode ? mysqli_fetch_row( $result ) : mysqli_fetch_assoc( $result ) ) ) {
			$rows[] = ( 'OBJECT' === $mode ) ? (object) $row : $row;
		}
		mysqli_free_result( $result );
		return $rows;
	}

	public function get_results( $sql, $mode = 'OBJECT' ) {
		$rows = $this->fetch( $sql, $mode );
		return null === $rows ? null : $rows;
	}

	public function get_row( $sql, $mode = 'OBJECT' ) {
		$rows = $this->fetch( $sql, $mode );
		return $rows ? $rows[0] : null;
	}

	public function get_col( $sql ) {
		$rows = $this->fetch( $sql, 'ARRAY_N' );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = $row[0];
		}
		return $out;
	}

	public function get_var( $sql ) {
		$rows = $this->fetch( $sql, 'ARRAY_N' );
		return ( $rows && array_key_exists( 0, $rows[0] ) ) ? $rows[0][0] : null;
	}

	private function where_sql( array $where ) {
		$parts = array();
		foreach ( $where as $k => $v ) {
			$parts[] = null === $v ? "`$k` IS NULL" : "`$k` = '" . mysqli_real_escape_string( $this->dbh, (string) $v ) . "'";
		}
		return implode( ' AND ', $parts );
	}

	public function insert( $table, array $data ) {
		$cols = array();
		$vals = array();
		foreach ( $data as $k => $v ) {
			$cols[] = "`$k`";
			$vals[] = null === $v ? 'NULL' : "'" . mysqli_real_escape_string( $this->dbh, (string) $v ) . "'";
		}
		return $this->query( "INSERT INTO `$table` (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
	}

	public function update( $table, array $data, array $where ) {
		$sets = array();
		foreach ( $data as $k => $v ) {
			$sets[] = "`$k` = " . ( null === $v ? 'NULL' : "'" . mysqli_real_escape_string( $this->dbh, (string) $v ) . "'" );
		}
		return $this->query( "UPDATE `$table` SET " . implode( ', ', $sets ) . ' WHERE ' . $this->where_sql( $where ) );
	}

	public function delete( $table, array $where ) {
		return $this->query( "DELETE FROM `$table` WHERE " . $this->where_sql( $where ) );
	}
}

/**
 * @return mysqli|null
 */
function jisento_test_connect( $role = '' ) {
	$env  = function ( $name, $default ) use ( $role ) {
		$specific = '' !== $role ? getenv( 'JISENTO_TEST_' . strtoupper( $role ) . '_DB_' . $name ) : false;
		if ( false !== $specific && '' !== $specific ) {
			return $specific;
		}
		$generic = getenv( 'JISENTO_TEST_DB_' . $name );
		return ( false !== $generic && '' !== $generic ) ? $generic : $default;
	};
	mysqli_report( MYSQLI_REPORT_OFF );
	$dbh = @mysqli_connect( $env( 'HOST', '127.0.0.1' ), $env( 'USER', 'root' ), $env( 'PASS', 'root' ), '', (int) $env( 'PORT', '3317' ) );
	return $dbh ? $dbh : null;
}

/**
 * Fresh database and a wpdb over it.
 */
function jisento_test_wpdb( $dbname, $prefix = 'wp_', $role = '' ) {
	$dbh = jisento_test_connect( $role );
	if ( ! $dbh ) {
		return null;
	}
	mysqli_query( $dbh, 'DROP DATABASE IF EXISTS `' . $dbname . '`' );
	mysqli_query( $dbh, 'CREATE DATABASE `' . $dbname . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
	mysqli_select_db( $dbh, $dbname );
	return new Jisento_Test_Wpdb( $dbh, $dbname, $prefix );
}

/**
 * New connection to an existing database, as a new PHP request would get.
 */
function jisento_test_reconnect( $dbname, $prefix = 'wp_', $role = '' ) {
	$dbh = jisento_test_connect( $role );
	if ( ! $dbh ) {
		return null;
	}
	mysqli_select_db( $dbh, $dbname );
	return new Jisento_Test_Wpdb( $dbh, $dbname, $prefix );
}

function jisento_test_skip( $why ) {
	echo "SKIP $why\n";
	exit( 0 );
}
