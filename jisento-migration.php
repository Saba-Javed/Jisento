<?php
/**
 * Plugin Name:       Jisento Migration
 * Plugin URI:        https://jisento.com/migration
 * Description:       Full WordPress site migration and backup system. Export and import .jisento packages, migrate with a short-lived key, and restore with replace or preserve modes.
 * Version:           1.2.11
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Jisento
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jisento
 * Domain Path:       /languages
 *
 * @package Jisento\Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JISENTO_VERSION', '1.2.11' );
define( 'JISENTO_PACKAGE_VERSION', '1.0' );
define( 'JISENTO_FILE', __FILE__ );
define( 'JISENTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'JISENTO_URL', plugin_dir_url( __FILE__ ) );
define( 'JISENTO_BASENAME', plugin_basename( __FILE__ ) );
define( 'JISENTO_MAGIC', "JISENTO\x1A" );
define( 'JISENTO_SIGNATURE', 'JISENTO-PACKAGE-v1' );

require_once JISENTO_PATH . 'includes/Autoloader.php';

Jisento\Migration\Autoloader::register();
Jisento\Migration\Core\Live_Url::protect();

register_activation_hook( __FILE__, array( 'Jisento\Migration\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Jisento\Migration\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Jisento\Migration\Plugin::instance()->boot();
	}
);
