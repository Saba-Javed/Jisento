<?php
/**
 * Plugin bootstrap.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration;

use Jisento\Migration\Admin\Admin;
use Jisento\Migration\Api\Rest_Controller;
use Jisento\Migration\Core\Installer;
use Jisento\Migration\Core\Logger;
use Jisento\Migration\Core\Settings;
use Jisento\Migration\Jobs\Job_Store;
use Jisento\Migration\Security\Capabilities;
use Jisento\Migration\Storage\Local_Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	const DB_VERSION = '1.1.0';

	/**
	 * @var Plugin
	 */
	private static $instance;

	/**
	 * @var Settings
	 */
	public $settings;

	/**
	 * @var Logger
	 */
	public $logger;

	/**
	 * @var Local_Storage
	 */
	public $storage;

	/**
	 * @var Job_Store
	 */
	public $jobs;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		Installer::install();
		Capabilities::add_caps();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'jisento_maintenance' );
		flush_rewrite_rules();
	}

	public function boot() {
		load_plugin_textdomain( 'jisento', false, dirname( JISENTO_BASENAME ) . '/languages' );

		$this->settings = new Settings();
		$this->logger   = new Logger();
		$this->storage  = new Local_Storage();
		$this->jobs     = new Job_Store();

		Installer::maybe_upgrade();
		$this->storage->ensure_directories();
		Security\Job_Continuation::register();
		Security\Admin_Guard::register();
		Security\Admin_Guard::heal_open_import();

		add_action( 'init', array( $this, 'on_init' ) );
		add_filter( 'upload_mimes', array( $this, 'mimes' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'jisento_maintenance', array( $this, 'run_maintenance' ) );

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		if ( ! wp_next_scheduled( 'jisento_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'jisento_maintenance' );
		}
	}

	public function on_init() {
		Capabilities::register();
	}

	public function mimes( $mimes ) {
		$mimes['jisento'] = 'application/octet-stream';
		return $mimes;
	}

	public function register_rest() {
		( new Rest_Controller() )->register_routes();
	}

	public function run_maintenance() {
		$this->jobs->expire_stale();
		( new Security\Migration_Key_Store() )->expire_stale();
		( new Security\Session_Store() )->expire_stale();
		$this->storage->enforce_retention( (int) $this->settings->get( 'keep_backups', 3 ) );
		$this->logger->prune( 30 );
	}
}
