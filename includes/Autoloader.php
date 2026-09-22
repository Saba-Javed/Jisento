<?php
/**
 * PSR-4 autoloader for Jisento\Migration.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class ) {
		$prefix = 'Jisento\\Migration\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = JISENTO_PATH . 'includes/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
