<?php
/**
 * Splits MySQL dumps into statements without executing them.
 *
 * A semicolon ends a statement only outside quotes, identifiers, and comments.
 * This matches the dump produced by Database_Exporter: backslash escapes,
 * and also the doubled-quote form used by other dumpers.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sql_Scanner {

	/**
	 * Return the next complete statement and the unparsed remainder.
	 *
	 * @param string $buffer Bytes read so far.
	 * @return array{0:string,1:string}|null Null when the statement is incomplete.
	 */
	public static function next_statement( $buffer ) {
		$end = self::statement_end( $buffer );
		if ( null === $end ) {
			return null;
		}
		return array( substr( $buffer, 0, $end + 1 ), substr( $buffer, $end + 1 ) );
	}

	/**
	 * Split a complete SQL script into statements.
	 *
	 * @param string $sql Script.
	 * @return string[]
	 */
	public static function statements( $sql ) {
		$out = array();
		while ( '' !== $sql ) {
			$split = self::next_statement( $sql );
			if ( null === $split ) {
				$tail = trim( $sql );
				if ( '' !== $tail ) {
					$out[] = $tail;
				}
				break;
			}
			$statement = trim( $split[0] );
			if ( '' !== $statement && ';' !== $statement ) {
				$out[] = $statement;
			}
			$sql = $split[1];
		}
		return $out;
	}

	/**
	 * Turn one large INSERT into smaller INSERT statements.
	 * If the input actually contains more than one statement, those statements are returned unchanged.
	 *
	 * @param string $sql    One statement, or a blob that still contains several.
	 * @param int    $limit  Maximum bytes per returned statement.
	 * @return string[]
	 */
	public static function split_insert( $sql, $limit = 262144 ) {
		$parts = self::statements( $sql );
		if ( count( $parts ) > 1 ) {
			return $parts;
		}
		$sql = isset( $parts[0] ) ? $parts[0] : trim( $sql );
		if ( '' === $sql || strlen( $sql ) <= $limit ) {
			return array( $sql );
		}
		if ( ! preg_match( '/^(INSERT\s+INTO\s+`[^`]+`\s*\(.*?\)\s*VALUES\s*)/is', $sql, $match ) ) {
			return array( $sql );
		}
		$prefix = $match[1];
		$body   = rtrim( substr( $sql, strlen( $prefix ) ) );
		if ( ';' === substr( $body, -1 ) ) {
			$body = substr( $body, 0, -1 );
		}
		$tuples = self::tuples( $body );
		if ( count( $tuples ) < 2 ) {
			return array( $sql );
		}
		$out   = array();
		$batch = array();
		$size  = strlen( $prefix ) + 1;
		foreach ( $tuples as $tuple ) {
			$add = strlen( $tuple ) + 1;
			if ( $batch && ( $size + $add ) > $limit ) {
				$out[] = $prefix . implode( ',', $batch ) . ';';
				$batch = array();
				$size  = strlen( $prefix ) + 1;
			}
			$batch[] = $tuple;
			$size   += $add;
		}
		if ( $batch ) {
			$out[] = $prefix . implode( ',', $batch ) . ';';
		}
		return $out ? $out : array( $sql );
	}

	/**
	 * @param string $buffer Buffer.
	 * @return int|null Index of the terminating semicolon.
	 */
	public static function statement_end( $buffer ) {
		$len    = strlen( $buffer );
		$in     = false;
		$escape = false;
		$quote  = '';
		$ident  = false;
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];

			if ( $in ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$escape = true;
					continue;
				}
				if ( $ch === $quote && isset( $buffer[ $i + 1 ] ) && $buffer[ $i + 1 ] === $quote ) {
					$i++;
					continue;
				}
				if ( $ch === $quote ) {
					$in    = false;
					$quote = '';
				}
				continue;
			}

			if ( $ident ) {
				if ( '\\' === $ch ) {
					$i++;
					continue;
				}
				if ( '`' === $ch ) {
					if ( isset( $buffer[ $i + 1 ] ) && '`' === $buffer[ $i + 1 ] ) {
						$i++;
						continue;
					}
					$ident = false;
				}
				continue;
			}

			if ( '-' === $ch && isset( $buffer[ $i + 1 ] ) && '-' === $buffer[ $i + 1 ] ) {
				$next = isset( $buffer[ $i + 2 ] ) ? $buffer[ $i + 2 ] : "\n";
				if ( ' ' === $next || "\t" === $next || "\n" === $next || "\r" === $next ) {
					$nl = strpos( $buffer, "\n", $i );
					if ( false === $nl ) {
						return null;
					}
					$i = $nl;
					continue;
				}
			}

			if ( '#' === $ch ) {
				$nl = strpos( $buffer, "\n", $i );
				if ( false === $nl ) {
					return null;
				}
				$i = $nl;
				continue;
			}

			if ( '/' === $ch && isset( $buffer[ $i + 1 ] ) && '*' === $buffer[ $i + 1 ] ) {
				$end = strpos( $buffer, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 1;
				continue;
			}

			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				continue;
			}

			if ( '`' === $ch ) {
				$ident = true;
				continue;
			}

			if ( ';' === $ch ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * @param string $body VALUES body without the trailing semicolon.
	 * @return string[]
	 */
	public static function value_tuples( $body ) {
		return self::tuples( $body );
	}

	/**
	 * Split one parenthesized tuple into raw SQL literals.
	 *
	 * @param string $tuple Tuple including parentheses.
	 * @return string[]
	 */
	public static function tuple_fields( $tuple ) {
		$tuple = trim( $tuple );
		if ( '(' === substr( $tuple, 0, 1 ) && ')' === substr( $tuple, -1 ) ) {
			$tuple = substr( $tuple, 1, -1 );
		}
		$fields = array();
		$len    = strlen( $tuple );
		$start  = 0;
		$depth  = 0;
		$in     = false;
		$escape = false;
		$quote  = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $tuple[ $i ];
			if ( $in ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$escape = true;
					continue;
				}
				if ( $ch === $quote && isset( $tuple[ $i + 1 ] ) && $tuple[ $i + 1 ] === $quote ) {
					$i++;
					continue;
				}
				if ( $ch === $quote ) {
					$in = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				continue;
			}
			if ( '(' === $ch ) {
				$depth++;
				continue;
			}
			if ( ')' === $ch && $depth > 0 ) {
				$depth--;
				continue;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$fields[] = trim( substr( $tuple, $start, $i - $start ) );
				$start    = $i + 1;
			}
		}
		$fields[] = trim( substr( $tuple, $start ) );
		return $fields;
	}

	private static function tuples( $body ) {
		$tuples = array();
		$len    = strlen( $body );
		$start  = null;
		$depth  = 0;
		$in     = false;
		$escape = false;
		$quote  = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $body[ $i ];
			if ( $in ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$escape = true;
					continue;
				}
				if ( $ch === $quote && isset( $body[ $i + 1 ] ) && $body[ $i + 1 ] === $quote ) {
					$i++;
					continue;
				}
				if ( $ch === $quote ) {
					$in = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$in    = true;
				$quote = $ch;
				continue;
			}
			if ( '(' === $ch ) {
				if ( 0 === $depth ) {
					$start = $i;
				}
				$depth++;
				continue;
			}
			if ( ')' === $ch && $depth > 0 ) {
				$depth--;
				if ( 0 === $depth && null !== $start ) {
					$tuples[] = substr( $body, $start, $i - $start + 1 );
					$start    = null;
				}
			}
		}
		return $tuples;
	}
}
