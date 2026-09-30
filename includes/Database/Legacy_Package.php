<?php
/**
 * Checks for version 1 packages.
 *
 * Plugin versions up to 1.2.11 escaped dump values with $wpdb->_real_escape(), which turns every
 * "%" into a per-request placeholder "{64 hex}". The importer sent that SQL straight to mysqli, so
 * the placeholder was never turned back. A token that repeats is almost certainly such a
 * placeholder; a single random-looking "{hex}" value is left alone.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Legacy_Package {

	const TOKEN_PATTERN = '/\{[a-f0-9]{64}\}/';

	/**
	 * Stream a dump: repeated placeholder tokens and the tables it creates.
	 *
	 * @param string $path SQL file.
	 * @return array{tokens:array<string,int>,occurrences:int,tables:string[]}|\WP_Error
	 */
	public static function scan( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new \WP_Error( 'jisento_sql_open', sprintf( __( 'Unable to open %s to check it.', 'jisento-migration' ), basename( (string) $path ) ) );
		}
		$counts = array();
		$tables = array();
		$carry  = '';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 4194304 );
			if ( false === $chunk ) {
				fclose( $handle );
				return new \WP_Error( 'jisento_sql_read', sprintf( __( 'Reading %s failed.', 'jisento-migration' ), basename( (string) $path ) ) );
			}
			if ( '' === $chunk ) {
				break;
			}
			$buffer = $carry . $chunk;
			// Keep a tail shorter than one token so a token split across reads is counted exactly once.
			$keep  = min( strlen( $buffer ), 65 );
			$scan  = substr( $buffer, 0, strlen( $buffer ) - $keep );
			$carry = substr( $buffer, strlen( $buffer ) - $keep );
			self::count_tokens( $scan, $counts );
			self::collect_tables( $buffer, $tables );
		}
		fclose( $handle );
		self::count_tokens( $carry, $counts );

		$repeated    = array();
		$occurrences = 0;
		foreach ( $counts as $token => $count ) {
			if ( $count >= 2 ) {
				$repeated[ $token ] = $count;
				$occurrences       += $count;
			}
		}
		return array(
			'tokens'      => $repeated,
			'occurrences' => $occurrences,
			'tables'      => array_keys( $tables ),
		);
	}

	private static function count_tokens( $text, array &$counts ) {
		if ( '' === $text || false === strpos( $text, '{' ) ) {
			return;
		}
		if ( preg_match_all( self::TOKEN_PATTERN, $text, $matches ) ) {
			foreach ( $matches[0] as $token ) {
				$counts[ $token ] = isset( $counts[ $token ] ) ? $counts[ $token ] + 1 : 1;
			}
		}
	}

	private static function collect_tables( $text, array &$tables ) {
		if ( preg_match_all( '/^CREATE TABLE `([^`]+)`/m', $text, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$tables[ $name ] = true;
			}
		}
	}
}
