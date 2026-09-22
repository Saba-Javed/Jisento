<?php
/**
 * Capability registration.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	const CAP = 'jisento_migrate';

	public static function register() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP ) ) {
			$role->add_cap( self::CAP );
		}
	}

	public static function add_caps() {
		self::register();
	}

	public static function current_user_can() {
		// On multisite, a site administrator has manage_options but must not be able to replace network tables.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return function_exists( 'is_super_admin' ) && is_super_admin();
		}
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}
}
