<?php
/**
 * Byte-safe SQL literal escaping for dump values.
 *
 * $wpdb->_real_escape(), esc_sql() and $wpdb->prepare() replace every "%" with a
 * per-request placeholder that only $wpdb->query() removes again. Dump SQL is sent
 * straight to mysqli, so those helpers must never be used for dump values.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.RestrictedFunctions -- Dump SQL must use mysqli escaping; $wpdb helpers corrupt % placeholders.

class Sql_Escaper {

	/**
	 * @var \mysqli|null
	 */
	private $dbh = null;

	/**
	 * @param mixed $dbh Connection handle from $wpdb->dbh. Anything that is not mysqli uses the manual escaper.
	 */
	public function __construct( $dbh = null ) {
		if ( $dbh instanceof \mysqli ) {
			$this->dbh = $dbh;
			self::ensure_utf8mb4( $dbh );
		}
	}

	/**
	 * mysqli_real_escape_string depends on the connection charset. utf8mb4 never uses
	 * bytes below 0x80 inside a multi-byte character, so escaping stays byte-exact.
	 *
	 * @param \mysqli $dbh Connection.
	 * @return bool
	 */
	public static function ensure_utf8mb4( $dbh ) {
		if ( ! ( $dbh instanceof \mysqli ) ) {
			return false;
		}
		$current = strtolower( (string) mysqli_character_set_name( $dbh ) );
		if ( 'utf8mb4' === $current ) {
			return true;
		}
		return (bool) mysqli_set_charset( $dbh, 'utf8mb4' );
	}

	public function uses_driver() {
		return null !== $this->dbh && 'utf8mb4' === strtolower( (string) mysqli_character_set_name( $this->dbh ) );
	}

	/**
	 * @param string $value Raw bytes.
	 * @return string Escaped bytes without surrounding quotes.
	 */
	public function escape( $value ) {
		$value = (string) $value;
		if ( $this->uses_driver() ) {
			return mysqli_real_escape_string( $this->dbh, $value );
		}
		return self::escape_manual( $value );
	}

	/**
	 * @param mixed $value Value or null.
	 * @return string SQL literal.
	 */
	public function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . $this->escape( (string) $value ) . "'";
	}

	/**
	 * Same escapes MySQL applies in mysql_real_escape_string for single-byte-safe charsets.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	public static function escape_manual( $value ) {
		return strtr(
			(string) $value,
			array(
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				'\\'   => '\\\\',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * @param mixed $value Value or null.
	 * @return string
	 */
	public static function quote_manual( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . self::escape_manual( (string) $value ) . "'";
	}
}
