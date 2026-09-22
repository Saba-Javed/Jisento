<?php
/**
 * Serialized-data-safe string replacement for PHP serialize and JSON.
 *
 * Does not use SQL REPLACE() on serialized payloads. String tokens of the
 * form s:N:"..." have their lengths rewritten after substitution.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Replace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Serializer {

	public function replace( $data, array $replacements ) {
		if ( empty( $replacements ) ) {
			return $data;
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$new_key         = is_string( $key ) ? $this->replace_string( $key, $replacements ) : $key;
				$out[ $new_key ] = $this->replace( $value, $replacements );
			}
			return $out;
		}

		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $prop => $value ) {
				$data->{$prop} = $this->replace( $value, $replacements );
			}
			return $data;
		}

		if ( ! is_string( $data ) || '' === $data ) {
			return $data;
		}

		return $this->replace_string( $data, $replacements );
	}

	public function replace_string( $string, array $replacements ) {
		if ( $this->looks_serialized( $string ) ) {
			$offset  = 0;
			$updated = $this->replace_serialized( $string, $replacements, $offset );
			if ( is_string( $updated ) ) {
				return $updated;
			}
		}

		$trim = ltrim( $string );
		if ( '' !== $trim && ( '{' === $trim[0] || '[' === $trim[0] ) ) {
			$decoded = json_decode( $string, true );
			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_string( $decoded ) || is_int( $decoded ) ) ) {
				$replaced = $this->replace( $decoded, $replacements );
				$flags    = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
				if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
					$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
				}
				$encoded = wp_json_encode( $replaced, $flags );
				if ( is_string( $encoded ) ) {
					return $encoded;
				}
			}
		}

		return $this->replace_plain( $string, $replacements );
	}

	public function replace_plain( $string, array $replacements ) {
		return strtr( $string, $replacements );
	}

	public function looks_serialized( $string ) {
		if ( ! is_string( $string ) || strlen( $string ) < 4 ) {
			return false;
		}
		$string = trim( $string );
		if ( 'N;' === $string ) {
			return true;
		}
		if ( ! preg_match( '/^(s|a|O|C|i|d|b|R|r):/', $string ) ) {
			return false;
		}
		return ';' === substr( $string, -1 ) || '}' === substr( $string, -1 );
	}

	private function replace_serialized( $data, array $replacements, &$offset ) {
		$len = strlen( $data );
		if ( $offset >= $len ) {
			return '';
		}

		$type = $data[ $offset ];

		switch ( $type ) {
			case 's':
				if ( ! preg_match( '/^s:(\d+):"/', substr( $data, $offset ), $m ) ) {
					return $this->skip_unknown( $data, $offset );
				}
				$claimed = (int) $m[1];
				$head    = strlen( $m[0] );
				$start   = $offset + $head;
				$value   = substr( $data, $start, $claimed );
				$end     = $start + $claimed;
				if ( $end + 2 > $len || '"' !== $data[ $end ] || ';' !== $data[ $end + 1 ] ) {
					return $this->skip_unknown( $data, $offset );
				}
				$replaced     = $this->replace_string( $value, $replacements );
				$offset       = $end + 2;
				$encoded      = addcslashes( $replaced, "\0" );
				return 's:' . strlen( $replaced ) . ':"' . $replaced . '";';

			case 'S':
				return $this->skip_unknown( $data, $offset );

			case 'a':
				if ( ! preg_match( '/^a:(\d+):{/', substr( $data, $offset ), $m ) ) {
					return $this->skip_unknown( $data, $offset );
				}
				$count  = (int) $m[1];
				$offset += strlen( $m[0] );
				$pairs  = '';
				for ( $i = 0; $i < $count; $i++ ) {
					$key    = $this->replace_serialized( $data, $replacements, $offset );
					$value  = $this->replace_serialized( $data, $replacements, $offset );
					$pairs .= $key . $value;
				}
				if ( $offset < $len && '}' === $data[ $offset ] ) {
					$offset++;
				}
				return 'a:' . $count . ':{' . $pairs . '}';

			case 'O':
				if ( ! preg_match( '/^O:(\d+):"([^"]*)":(\d+):{/', substr( $data, $offset ), $m ) ) {
					return $this->skip_unknown( $data, $offset );
				}
				$class  = $m[2];
				$count  = (int) $m[3];
				$offset += strlen( $m[0] );
				$pairs  = '';
				for ( $i = 0; $i < $count; $i++ ) {
					$key    = $this->replace_serialized( $data, $replacements, $offset );
					$value  = $this->replace_serialized( $data, $replacements, $offset );
					$pairs .= $key . $value;
				}
				if ( $offset < $len && '}' === $data[ $offset ] ) {
					$offset++;
				}
				return 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{' . $pairs . '}';

			case 'C':
				return $this->skip_unknown( $data, $offset );

			case 'b':
			case 'i':
			case 'd':
				if ( ! preg_match( '/^[bid]:([^;]*);/', substr( $data, $offset ), $m ) ) {
					return $this->skip_unknown( $data, $offset );
				}
				$chunk   = $m[0];
				$offset += strlen( $chunk );
				return $chunk;

			case 'N':
				if ( $offset + 1 < $len && ';' === $data[ $offset + 1 ] ) {
					$offset += 2;
					return 'N;';
				}
				return $this->skip_unknown( $data, $offset );

			case 'R':
			case 'r':
				if ( ! preg_match( '/^[Rr]:(\d+);/', substr( $data, $offset ), $m ) ) {
					return $this->skip_unknown( $data, $offset );
				}
				$chunk   = $m[0];
				$offset += strlen( $chunk );
				return $chunk;

			default:
				return $this->skip_unknown( $data, $offset );
		}
	}

	private function skip_unknown( $data, &$offset ) {
		$offset = strlen( $data );
		return $data;
	}

	public static function url_variants( $url ) {
		$url = untrailingslashit( $url );
		$variants = array( $url );
		$encoded  = str_replace( '/', '\\/', $url );
		$variants[] = $encoded;
		$variants[] = urlencode( $url );
		$variants[] = rawurlencode( $url );

		$parts = wp_parse_url( $url );
		if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			$alt_scheme = 'https' === $parts['scheme'] ? 'http' : 'https';
			$rest       = substr( $url, strlen( $parts['scheme'] ) );
			$variants[] = $alt_scheme . $rest;

			$host = $parts['host'];
			if ( 0 === strpos( $host, 'www.' ) ) {
				$no_www = substr( $host, 4 );
			} else {
				$no_www = 'www.' . $host;
			}
			$variants[] = str_replace( $host, $no_www, $url );
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	public static function build_replacements( $source_url, $dest_url ) {
		$source_url = untrailingslashit( $source_url );
		$dest_url   = untrailingslashit( $dest_url );
		$map        = array();
		$escaped    = str_replace( '/', '\\/', $dest_url );

		foreach ( self::url_forms( $source_url ) as $source ) {
			if ( '' === $source || $source === $dest_url ) {
				continue;
			}
			$map[ $source ] = $dest_url;
			$map[ str_replace( '/', '\\/', $source ) ] = $escaped;
		}

		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $map;
	}

	/**
	 * Source forms that should all become the chosen destination URL.
	 *
	 * @param string $url Source URL.
	 * @return string[]
	 */
	private static function url_forms( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return array( untrailingslashit( $url ) );
		}
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$hosts = array( $parts['host'] );
		if ( 0 === strpos( $parts['host'], 'www.' ) ) {
			$hosts[] = substr( $parts['host'], 4 );
		} else {
			$hosts[] = 'www.' . $parts['host'];
		}
		$forms = array();
		foreach ( array( 'https', 'http' ) as $scheme ) {
			foreach ( $hosts as $host ) {
				$forms[] = $scheme . '://' . $host . $path;
			}
		}
		return array_values( array_unique( $forms ) );
	}
}
