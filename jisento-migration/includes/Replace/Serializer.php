<?php
/**
 * Serialized-data-safe string replacement for PHP serialize and JSON.
 *
 * Does not use SQL REPLACE() on serialized payloads. String tokens of the
 * form s:N:"..." have their lengths rewritten after substitution. JSON is
 * never decoded; the replacement map carries the "\/"-escaped URL forms.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Replace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Serializer {

	/**
	 * Serialized values left unchanged because they could not be rebuilt safely.
	 *
	 * @var int
	 */
	private $skipped = 0;

	/**
	 * Compiled boundary patterns keyed by a hash of the source forms.
	 *
	 * @var string[]
	 */
	private $patterns = array();

	public function skipped_count() {
		return $this->skipped;
	}

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
		if ( ! is_string( $string ) || '' === $string || empty( $replacements ) || ! $this->contains_source( $string, $replacements ) ) {
			return $string;
		}

		if ( $this->is_serialized_value( $string ) ) {
			$rebuilt = $this->rebuild_serialized( $string, $replacements );
			if ( null === $rebuilt ) {
				$this->skipped++;
				return $string;
			}
			return $rebuilt;
		}

		// Serialized-looking data PHP cannot read here (e.g. enums, broken lengths) must not have its lengths shifted.
		if ( $this->looks_serialized( $string ) ) {
			$this->skipped++;
			return $string;
		}

		return $this->replace_plain( $string, $replacements );
	}

	public function replace_plain( $string, array $replacements ) {
		if ( ! is_string( $string ) || '' === $string || empty( $replacements ) ) {
			return $string;
		}

		$result = preg_replace_callback(
			$this->pattern( $replacements ),
			static function ( $m ) use ( $replacements ) {
				return $replacements[ $m[0] ];
			},
			$string
		);
		if ( null === $result ) {
			throw new \RuntimeException(\esc_html( 'URL replacement pattern failed with PCRE error ' . preg_last_error() ));
		}

		return $result;
	}

	public function contains_source( $string, array $replacements ) {
		foreach ( $replacements as $source => $dest ) {
			if ( false !== strpos( $string, (string) $source ) ) {
				return true;
			}
		}
		return false;
	}

	public function looks_serialized( $string ) {
		if ( ! is_string( $string ) || strlen( $string ) < 2 ) {
			return false;
		}
		$string = trim( $string );
		if ( 'N;' === $string ) {
			return true;
		}
		if ( ! preg_match( '/^(?:[aOC]:\d+:|[sSE]:\d+:"|[bid]:[^;]*;$|[Rr]:\d+;$)/', $string ) ) {
			return false;
		}
		return ';' === substr( $string, -1 ) || '}' === substr( $string, -1 );
	}

	private function is_serialized_value( $string ) {
		if ( 'b:0;' === $string ) {
			return true;
		}
		if ( ! preg_match( '/^(?:[aOCsSidbE]:|N;)/', $string ) ) {
			return false;
		}
		return false !== @unserialize( $string, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	}

	/**
	 * @return string|null Rebuilt value, or null when any token is unknown or the result does not unserialize.
	 */
	private function rebuild_serialized( $string, array $replacements ) {
		$offset = 0;
		$out    = $this->replace_serialized( $string, $replacements, $offset );
		if ( null === $out ) {
			return null;
		}

		$tail = (string) substr( $string, $offset );
		if ( '' !== trim( $tail ) ) {
			return null;
		}
		$out .= $tail;

		if ( 'b:0;' !== $out && false === @unserialize( $out, array( 'allowed_classes' => false ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			return null;
		}

		return $out;
	}

	private function replace_serialized( $data, array $replacements, &$offset ) {
		$len = strlen( $data );
		if ( $offset >= $len ) {
			return null;
		}

		switch ( $data[ $offset ] ) {
			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$claimed = (int) $m[1];
				$start   = $offset + strlen( $m[0] );
				$end     = $start + $claimed;
				if ( $end + 2 > $len || '"' !== $data[ $end ] || ';' !== $data[ $end + 1 ] ) {
					return null;
				}
				$replaced = $this->replace_string( (string) substr( $data, $start, $claimed ), $replacements );
				$offset   = $end + 2;
				return 's:' . strlen( $replaced ) . ':"' . $replaced . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $m[0] );
				$body    = $this->replace_pairs( $data, $replacements, $offset, (int) $m[1] );
				return null === $body ? null : $m[0] . $body . '}';

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"([^"]*)":(\d+):\{/', $data, $m, 0, $offset ) || strlen( $m[2] ) !== (int) $m[1] ) {
					return null;
				}
				$offset += strlen( $m[0] );
				$body    = $this->replace_pairs( $data, $replacements, $offset, (int) $m[3] );
				return null === $body ? null : $m[0] . $body . '}';

			case 'b':
			case 'i':
			case 'd':
				if ( ! preg_match( '/\G[bid]:[^;:{}"]*;/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $m[0] );
				return $m[0];

			case 'N':
				if ( $offset + 1 < $len && ';' === $data[ $offset + 1 ] ) {
					$offset += 2;
					return 'N;';
				}
				return null;

			case 'R':
			case 'r':
				if ( ! preg_match( '/\G[Rr]:\d+;/', $data, $m, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $m[0] );
				return $m[0];

			default:
				return null;
		}
	}

	private function replace_pairs( $data, array $replacements, &$offset, $count ) {
		$body = '';
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $data[ $offset ] ) || ( 's' !== $data[ $offset ] && 'i' !== $data[ $offset ] ) ) {
				return null;
			}
			$key = $this->replace_serialized( $data, $replacements, $offset );
			if ( null === $key ) {
				return null;
			}
			$value = $this->replace_serialized( $data, $replacements, $offset );
			if ( null === $value ) {
				return null;
			}
			$body .= $key . $value;
		}
		if ( ! isset( $data[ $offset ] ) || '}' !== $data[ $offset ] ) {
			return null;
		}
		$offset++;
		return $body;
	}

	private function pattern( array $replacements ) {
		$forms = array();
		foreach ( array_keys( $replacements ) as $form ) {
			$forms[] = (string) $form;
		}
		$hash = md5( implode( "\n", $forms ) );
		if ( isset( $this->patterns[ $hash ] ) ) {
			return $this->patterns[ $hash ];
		}

		usort(
			$forms,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$parts = array();
		foreach ( $forms as $form ) {
			// Host/path may end a sentence (".") but must not match a longer domain (".au") or slug ("-shop", "_x").
			// Filesystem paths must not match a longer path segment (/public_html vs /public_html2).
			if ( 0 === stripos( $form, 'file:' ) || ( ( preg_match( '~^[A-Za-z]:[\\\\/]~', $form ) || 0 === strpos( $form, '/' ) || 0 === strpos( $form, '\\' ) ) && false === strpos( $form, '://' ) ) ) {
				$boundary = '(?![A-Za-z0-9_])';
			} else {
				$boundary = self::form_has_path( $form )
					? '(?![A-Za-z0-9_-]|[.][A-Za-z0-9-])'
					: '(?![A-Za-z0-9-]|[.][A-Za-z0-9-])';
			}
			$parts[] = preg_quote( $form, '~' ) . $boundary;
		}
		$this->patterns[ $hash ] = '~(?:' . implode( '|', $parts ) . ')~';

		return $this->patterns[ $hash ];
	}

	private static function form_has_path( $form ) {
		$rest = $form;
		foreach ( array( '\\/\\/', '//' ) as $separator ) {
			$pos = strpos( $form, $separator );
			if ( false !== $pos ) {
				$rest = substr( $form, $pos + strlen( $separator ) );
				break;
			}
		}
		return false !== strpos( $rest, '/' );
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

	/**
	 * Plain and file:// forms of an absolute filesystem path, longest first when used in a map.
	 * Trailing-slash and no-slash forms are both included; callers must use a boundary so
	 * /public_html does not match /public_html2.
	 *
	 * @param string $abspath Absolute path (Unix or Windows).
	 * @return string[]
	 */
	public static function path_forms( $abspath ) {
		$abs = str_replace( '\\', '/', (string) $abspath );
		$abs = rtrim( $abs, '/' );
		if ( '' === $abs ) {
			return array();
		}
		$forms = array( $abs, $abs . '/' );
		$win   = str_replace( '/', '\\', $abs );
		$forms[] = $win;
		$forms[] = $win . '\\';
		// file:///path (when $abs is /path) and file://path
		$forms[] = 'file://' . $abs;
		$forms[] = 'file://' . $abs . '/';
		if ( 0 !== strpos( $abs, '/' ) ) {
			$forms[] = 'file:///' . $abs;
			$forms[] = 'file:///' . $abs . '/';
		}
		$forms[] = 'file://' . $win;
		$forms[] = 'file://' . $win . '\\';
		return array_values( array_unique( array_filter( $forms ) ) );
	}

	/**
	 * Map source ABSPATH forms onto destination ABSPATH forms (plain and file://).
	 *
	 * @param string $source_abspath Source ABSPATH from the package manifest.
	 * @param string $dest_abspath   Destination ABSPATH.
	 * @return array<string,string>
	 */
	public static function build_path_replacements( $source_abspath, $dest_abspath ) {
		$src_forms = self::path_forms( $source_abspath );
		$dst_abs   = rtrim( str_replace( '\\', '/', (string) $dest_abspath ), '/' );
		if ( ! $src_forms || '' === $dst_abs ) {
			return array();
		}
		$dst_slash = $dst_abs . '/';
		$dst_win   = str_replace( '/', '\\', $dst_abs );
		$dst_file  = 'file://' . $dst_abs;
		$dst_file_s = $dst_file . '/';
		$map = array();
		foreach ( $src_forms as $from ) {
			if ( '' === $from || $from === $dst_abs || $from === $dst_slash ) {
				continue;
			}
			$to = $from;
			if ( 0 === stripos( $from, 'file:' ) ) {
				$slash = ( substr( $from, -1 ) === '/' || substr( $from, -1 ) === '\\' );
				$to    = $slash ? $dst_file_s : $dst_file;
				if ( false !== strpos( $from, '\\' ) ) {
					$to = 'file://' . $dst_win . ( $slash ? '\\' : '' );
				}
			} elseif ( false !== strpos( $from, '\\' ) ) {
				$to = ( substr( $from, -1 ) === '\\' ) ? $dst_win . '\\' : $dst_win;
			} else {
				$to = ( substr( $from, -1 ) === '/' ) ? $dst_slash : $dst_abs;
			}
			$map[ $from ] = $to;
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return $map;
	}

	public static function build_replacements( $source_url, $dest_url, $replace_emails = true ) {
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

		if ( $replace_emails ) {
			foreach ( self::email_forms( $source_url, $dest_url ) as $from => $to ) {
				$map[ $from ] = $to;
			}
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
	 * @-host forms for email addresses on the source domain.
	 *
	 * @param string $source_url Source site URL.
	 * @param string $dest_url   Destination site URL.
	 * @return array<string,string>
	 */
	public static function email_forms( $source_url, $dest_url ) {
		$src = wp_parse_url( untrailingslashit( (string) $source_url ) );
		$dst = wp_parse_url( untrailingslashit( (string) $dest_url ) );
		if ( empty( $src['host'] ) || empty( $dst['host'] ) ) {
			return array();
		}
		$src_hosts = array( $src['host'] );
		if ( 0 === strpos( $src['host'], 'www.' ) ) {
			$src_hosts[] = substr( $src['host'], 4 );
		} else {
			$src_hosts[] = 'www.' . $src['host'];
		}
		$out = array();
		foreach ( array_unique( $src_hosts ) as $host ) {
			$out[ '@' . $host ] = '@' . $dst['host'];
		}
		return $out;
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
