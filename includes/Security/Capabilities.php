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
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}
}
