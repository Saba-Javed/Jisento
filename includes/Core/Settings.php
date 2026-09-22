<?php
/**
 * Plugin settings stored in a single option array.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'jisento_settings';

	/**
	 * @var array
	 */
	private $data;

	public function __construct() {
		$stored     = get_option( self::OPTION, array() );
		$this->data = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	public static function defaults() {
		return array(
			'keep_backups'            => 3,
			'chunk_size'              => 524288,
			'skip_cache'              => true,
			'https_required'          => true,
			'key_ttl'                 => 1800,
			'key_single_use'          => true,
			'default_plugin_strategy' => 'replace_matching',
			'default_theme_strategy'  => 'keep_destination',
			'max_log_days'            => 30,
			'rate_limit'              => 30,
		);
	}

	public function all() {
		return $this->data;
	}

	public function get( $key, $default = null ) {
		if ( array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}
		return $default;
	}

	public function update( array $values ) {
		$allowed = array_keys( self::defaults() );
		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			$this->data[ $key ] = $this->sanitize_value( $key, $value );
		}
		update_option( self::OPTION, $this->data, false );
	}

	private function sanitize_value( $key, $value ) {
		switch ( $key ) {
			case 'keep_backups':
			case 'chunk_size':
			case 'key_ttl':
			case 'max_log_days':
			case 'rate_limit':
				return max( 1, (int) $value );
			case 'skip_cache':
			case 'https_required':
			case 'key_single_use':
				return (bool) $value;
			case 'default_plugin_strategy':
			case 'default_theme_strategy':
				return sanitize_key( $value );
			default:
				return $value;
		}
	}
}
