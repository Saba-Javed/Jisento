<?php
/**
 * REST API for admin job control and authenticated remote transfer.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Api;

use Jisento\Migration\Core\Compatibility;
use Jisento\Migration\Core\Lease;
use Jisento\Migration\Export\Exporter;
use Jisento\Migration\Import\Importer;
use Jisento\Migration\Jobs\Job_Runner;
use Jisento\Migration\Plugin;
use Jisento\Migration\Remote\Transfer;
use Jisento\Migration\Security\Guard;
use Jisento\Migration\Security\Migration_Key_Store;
use Jisento\Migration\Security\Session_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller {

	const NS = 'jisento/v1';

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_job' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-zA-Z0-9_]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'job_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'step_job' ),
					'permission_callback' => array( $this, 'job_permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-zA-Z0-9_]+)/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pause_job' ),
				'permission_callback' => array( $this, 'job_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-zA-Z0-9_]+)/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_job' ),
				'permission_callback' => array( $this, 'job_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-zA-Z0-9_]+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_job' ),
				'permission_callback' => array( $this, 'job_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<id>[a-zA-Z0-9_]+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'job_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/compatibility',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'compatibility' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/backups',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_backups' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/backups/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete_backup' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_package' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/keys',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_keys' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_key' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/keys/(?P<id>\d+)/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke_key' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'connect_key' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/plugins-themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'plugins_themes' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/remote/handshake',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remote_handshake' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/remote/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'remote_info' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/remote/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remote_export' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/remote/export-step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remote_export_step' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/remote/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remote_chunk' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/remote/probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remote_probe' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/upload/init',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_init' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/upload/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_chunk' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/upload/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_complete' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
		register_rest_route(
			self::NS,
			'/packages/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate_package' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	public function admin_permission( \WP_REST_Request $request ) {
		$allowed = Guard::require_admin_rest();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return true;
	}

	/**
	 * Status, step, pause, resume, and cancel keep working after the restored
	 * database drops the destination browser session. Creating a job still
	 * requires the logged-in administrator.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function job_permission( \WP_REST_Request $request ) {
		if ( \Jisento\Migration\Security\Capabilities::current_user_can() ) {
			return true;
		}
		$id = isset( $request['id'] ) ? (string) $request['id'] : '';
		if ( \Jisento\Migration\Security\Job_Continuation::matches( $id, \Jisento\Migration\Security\Job_Continuation::presented() ) ) {
			\Jisento\Migration\Security\Job_Continuation::note_once( $id );
			return true;
		}
		return new \WP_Error( 'jisento_forbidden', __( 'You are not allowed to run migrations.', 'jisento' ), array( 'status' => 403 ) );
	}

	public function create_job( \WP_REST_Request $request ) {
		$type    = sanitize_key( $request->get_param( 'type' ) );
		$options = (array) $request->get_param( 'options' );
		if ( 'export' === $type ) {
			$job = ( new Exporter() )->start( $options );
		} elseif ( 'receive' === $type ) {
			$plugin = Plugin::instance();
			$job    = Job_Runner::open(
				'receive',
				array(
					'options' => $options,
					'remote'  => array(
						'source_url' => isset( $options['source_url'] ) ? esc_url_raw( $options['source_url'] ) : '',
						'session_id' => isset( $options['session_id'] ) ? sanitize_text_field( $options['session_id'] ) : '',
						'token'      => isset( $options['token'] ) ? sanitize_text_field( $options['token'] ) : '',
					),
				)
			);
			if ( ! is_wp_error( $job ) ) {
				$job = $plugin->jobs->update(
					$job,
					array(
						'status' => 'running',
						'stage'  => 'uploading',
					)
				);
				Importer::arm( $job );
			}
		} elseif ( 'import' === $type ) {
			$job = ( new Importer() )->start( $options );
		} else {
			return new \WP_Error( 'jisento_type', __( 'Unknown job type.', 'jisento' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return $this->respond_job( $job );
	}

	/**
	 * @param object|null $job Job row.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond_job( $job ) {
		if ( ! $job || empty( $job->job_id ) ) {
			return new \WP_Error( 'jisento_job', __( 'The job could not be created.', 'jisento' ), array( 'status' => 500 ) );
		}
		$secret = \Jisento\Migration\Security\Job_Continuation::issue( $job->job_id );
		if ( '' === $secret ) {
			Job_Runner::fail( $job, sprintf( __( 'Stage: start. Operation: store the job token. Reason: the token file could not be written. Recovery: make wp-content/jisento/jobs writable by PHP, then start again. Job: %s', 'jisento' ), $job->job_id ) );
			return new \WP_Error(
				'jisento_continuation',
				__( 'The job could not store a continuation proof, so it was not started.', 'jisento' ),
				array( 'status' => 500 )
			);
		}
		$response                       = Plugin::instance()->jobs->to_response( $job );
		$response['continuation_token'] = $secret;
		return rest_ensure_response( $response );
	}

	public function get_job( \WP_REST_Request $request ) {
		$job = Plugin::instance()->jobs->get( $request['id'] );
		if ( ! $job ) {
			return new \WP_Error( 'jisento_missing', __( 'Job not found.', 'jisento' ), array( 'status' => 404 ) );
		}
		$payload                 = Plugin::instance()->jobs->to_response( $job );
		$payload['lease_holder'] = Lease::holder();
		return rest_ensure_response( $payload );
	}

	public function step_job( \WP_REST_Request $request ) {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 40 );
		}
		$controller = $this;
		$ran        = Job_Runner::step(
			(string) $request['id'],
			static function ( $job ) use ( $controller ) {
				if ( 'receive' === $job->type ) {
					return $controller->step_receive( $job );
				}
				if ( 'export' === $job->type ) {
					return ( new Exporter() )->step( $job );
				}
				return ( new Importer() )->step( $job );
			}
		);
		return $this->step_response( $ran );
	}

	private function step_response( array $ran ) {
		if ( $ran['error'] ) {
			return $ran['error'];
		}
		$payload                = Plugin::instance()->jobs->to_response( $ran['job'] );
		$payload['worker_busy'] = $ran['busy'];
		return rest_ensure_response( $payload );
	}

	/**
	 * Called from inside Job_Runner::step, which holds the job lock and the site lease.
	 *
	 * @param object $job Receive job.
	 * @return object
	 */
	public function step_receive( $job ) {
		$plugin = Plugin::instance();
		$state  = $job->state;
		$remote = isset( $state['remote'] ) ? $state['remote'] : array();
		$xfer   = new Transfer();
		$headers = array(
			'Content-Type'            => 'application/json',
			'X-Jisento-Session'       => $remote['session_id'],
			'X-Jisento-Session-Token' => $remote['token'],
		);

		if ( empty( $remote['export_job'] ) ) {
			$started = $xfer->request( untrailingslashit( $remote['source_url'] ) . '/wp-json/jisento/v1/remote/export', 'POST', $headers, wp_json_encode( array() ) );
			if ( is_wp_error( $started ) ) {
				throw new \RuntimeException( 'Operation: start the export on the source site. Reason: ' . $started->get_error_message() . ' Recovery: check the source site is reachable and no other export runs there, then start again.' );
			}
			if ( empty( $started['job_id'] ) ) {
				throw new \RuntimeException( 'Operation: start the export on the source site. Reason: the source did not return an export job. Recovery: update Jisento Migration on the source site, then start again.' );
			}
			$state['remote']['export_job'] = (string) $started['job_id'];
			return $plugin->jobs->update(
				$job,
				array(
					'stage'        => 'uploading',
					'current_item' => __( 'Starting remote export', 'jisento' ),
					'state'        => $state,
				)
			);
		}

		if ( empty( $state['remote']['package_ready'] ) ) {
			$stepped = $xfer->request(
				untrailingslashit( $remote['source_url'] ) . '/wp-json/jisento/v1/remote/export-step',
				'POST',
				$headers,
				wp_json_encode( array( 'job_id' => $state['remote']['export_job'] ) )
			);
			if ( is_wp_error( $stepped ) ) {
				throw new \RuntimeException( 'Operation: run the export on the source site. Reason: ' . $stepped->get_error_message() . ' Recovery: press Retry on a new import once the source is reachable.' );
			}
			if ( isset( $stepped['status'] ) && 'failed' === $stepped['status'] ) {
				throw new \RuntimeException( 'Operation: run the export on the source site. Reason: the source export failed: ' . ( isset( $stepped['error_summary'] ) ? (string) $stepped['error_summary'] : '' ) . ' Recovery: fix the problem on the source site, then start a new migration.' );
			}
			if ( isset( $stepped['status'] ) && 'completed' === $stepped['status'] ) {
				$state['remote']['package_ready'] = true;
				$state['remote']['package_size']  = isset( $stepped['state']['package_size'] ) ? (int) $stepped['state']['package_size'] : 0;
				$state['remote']['offset']        = 0;
				$slug                             = 'packages/remote-' . $job->job_id . '.jisento';
				$state['options']['package']      = $slug;
				$state['remote']['local_package'] = $plugin->storage->get_path( $slug );
			}
			return $plugin->jobs->update(
				$job,
				array(
					'progress'     => isset( $stepped['progress'] ) ? min( 40, (int) $stepped['progress'] ) : (int) $job->progress,
					'current_item' => isset( $stepped['current_item'] ) ? $stepped['current_item'] : '',
					'state'        => $state,
				)
			);
		}

		if ( empty( $state['remote']['transfer_done'] ) ) {
			$chunk  = max( 131072, min( 1048576, (int) Plugin::instance()->settings->get( 'chunk_size', 524288 ) ) );
			$result = $xfer->download_package_chunk(
				$remote['source_url'],
				$remote['session_id'],
				$remote['token'],
				$state['remote']['export_job'],
				(int) $state['remote']['offset'],
				$chunk,
				$state['remote']['local_package'],
				$job->job_id
			);
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( 'Operation: download the package. Reason: ' . $result->get_error_message() . ' Recovery: start a new migration once the source is reachable.' );
			}
			$state['remote']['offset'] += (int) $result['bytes'];
			$total = max( 1, (int) $state['remote']['package_size'] );
			if ( $result['bytes'] < $chunk || $state['remote']['offset'] >= $total ) {
				$state['remote']['transfer_done'] = true;
			}
			return $plugin->jobs->update(
				$job,
				array(
					'stage'        => 'uploading',
					'progress'     => 40 + min( 20, (int) floor( ( $state['remote']['offset'] / $total ) * 20 ) ),
					'bytes_done'   => $state['remote']['offset'],
					'bytes_total'  => $total,
					'current_item' => __( 'Transferring package', 'jisento' ),
					'state'        => $state,
				)
			);
		}

		// The download is complete: from here on this job is an ordinary import of that package.
		return $plugin->jobs->update(
			$job,
			array(
				'type'  => 'import',
				'stage' => 'validating',
				'state' => $state,
			)
		);
	}

	private function job_result( $job ) {
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return rest_ensure_response( Plugin::instance()->jobs->to_response( $job ) );
	}

	public function pause_job( \WP_REST_Request $request ) {
		return $this->job_result( Job_Runner::pause( (string) $request['id'] ) );
	}

	public function resume_job( \WP_REST_Request $request ) {
		return $this->job_result( Job_Runner::resume( (string) $request['id'] ) );
	}

	/**
	 * Explicit retry of a failed job. Never automatic.
	 */
	public function retry_job( \WP_REST_Request $request ) {
		return $this->job_result( Job_Runner::retry( (string) $request['id'] ) );
	}

	public function cancel_job( \WP_REST_Request $request ) {
		return $this->job_result( Job_Runner::cancel( (string) $request['id'] ) );
	}

	public function compatibility() {
		return rest_ensure_response( ( new Compatibility() )->run() );
	}

	public function list_backups() {
		nocache_headers();
		$registry = new \Jisento\Migration\Package\Package_Registry();
		$files    = $registry->list_all();
		$response = rest_ensure_response( $files );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	public function delete_backup( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );
		if ( $id ) {
			( new \Jisento\Migration\Package\Package_Registry() )->delete( $id );
			return rest_ensure_response( array( 'deleted' => true ) );
		}
		$name = Guard::sanitize_archive_path( $request->get_param( 'name' ) );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$registry = new \Jisento\Migration\Package\Package_Registry();
		$resolved = Plugin::instance()->storage->resolve( $name );
		if ( $resolved ) {
			Plugin::instance()->storage->delete( $resolved['key'] );
			$registry->delete_by_storage_key( $resolved['key'] );
		}
		$registry->delete_by_filename( basename( (string) $name ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function upload_package( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Upload failed.', 'jisento' ), array( 'status' => 400 ) );
		}
		$tmp  = $files['file']['tmp_name'];
		$name = sanitize_file_name( $files['file']['name'] );
		if ( ! preg_match( '/\.jisento$/i', $name ) ) {
			$name .= '.jisento';
		}
		$dest_rel = 'uploads/' . $name;
		$dest     = Plugin::instance()->storage->get_path( $dest_rel );
		wp_mkdir_p( dirname( $dest ) );
		if ( ! move_uploaded_file( $tmp, $dest ) && ! @copy( $tmp, $dest ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Unable to store the uploaded package.', 'jisento' ), array( 'status' => 500 ) );
		}
		$inspect = ( new \Jisento\Migration\Package\Archive() )->inspect( $dest );
		if ( is_wp_error( $inspect ) ) {
			@unlink( $dest );
			return $inspect;
		}
		return rest_ensure_response(
			array(
				'package'  => $dest_rel,
				'manifest' => $inspect['manifest'],
			)
		);
	}

	public function list_keys() {
		$store = new Migration_Key_Store();
		return rest_ensure_response( $store->list_keys() );
	}

	public function create_key() {
		$settings = Plugin::instance()->settings;
		$store    = new Migration_Key_Store();
		$created  = $store->create( (int) $settings->get( 'key_ttl', 1800 ), (bool) $settings->get( 'key_single_use', true ) );
		return rest_ensure_response( $created );
	}

	public function revoke_key( \WP_REST_Request $request ) {
		( new Migration_Key_Store() )->revoke( (int) $request['id'] );
		return rest_ensure_response( array( 'revoked' => true ) );
	}

	public function connect_key( \WP_REST_Request $request ) {
		$source = esc_url_raw( $request->get_param( 'source_url' ) );
		$key    = strtoupper( sanitize_text_field( $request->get_param( 'key' ) ) );
		$combo  = sanitize_text_field( $request->get_param( 'connection' ) );
		if ( $combo && false !== strpos( $combo, '#' ) ) {
			$parts  = explode( '#', $combo, 2 );
			$source = esc_url_raw( $parts[0] );
			$key    = strtoupper( sanitize_text_field( $parts[1] ) );
		}
		$xfer   = new Transfer();
		$result = $xfer->connect( $source, $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function save_settings( \WP_REST_Request $request ) {
		Plugin::instance()->settings->update( (array) $request->get_json_params() );
		return rest_ensure_response( Plugin::instance()->settings->all() );
	}

	public function logs( \WP_REST_Request $request ) {
		$id   = sanitize_text_field( $request->get_param( 'migration_id' ) );
		$logs = Plugin::instance()->logger->query( $id, 300 );
		return rest_ensure_response( $logs );
	}

	public function plugins_themes() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'slug'    => dirname( $file ) === '.' ? $file : dirname( $file ),
			);
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $slug,
			);
		}
		return rest_ensure_response(
			array(
				'plugins' => $plugins,
				'themes'  => $themes,
			)
		);
	}

	private function remote_session( \WP_REST_Request $request ) {
		$https = Guard::require_https_for_remote();
		if ( is_wp_error( $https ) ) {
			return $https;
		}
		$session_id = sanitize_text_field( $request->get_param( 'session_id' ) );
		if ( ! $session_id ) {
			$session_id = sanitize_text_field( $request->get_header( 'x-jisento-session' ) );
		}
		$token = sanitize_text_field( $request->get_param( 'session_token' ) );
		if ( ! $token ) {
			$token = sanitize_text_field( $request->get_header( 'x-jisento-session-token' ) );
		}
		return ( new Session_Store() )->authenticate( $session_id, $token );
	}

	public function remote_handshake( \WP_REST_Request $request ) {
		$limit = Guard::rate_limit( 'handshake', 10, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$https = Guard::require_https_for_remote();
		if ( is_wp_error( $https ) ) {
			return $https;
		}
		$key  = strtoupper( sanitize_text_field( $request->get_param( 'key' ) ) );
		$peer = esc_url_raw( $request->get_param( 'peer' ) );
		$row  = ( new Migration_Key_Store() )->find_active( $key );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$session = ( new Session_Store() )->create(
			(int) $row->id,
			wp_parse_url( $peer, PHP_URL_HOST ),
			28800,
			array( 'peer' => $peer )
		);
		( new Migration_Key_Store() )->consume( (int) $row->id );
		$info = ( new Transfer() )->site_info();
		Plugin::instance()->logger->log( '', 'handshake', 'connect', $info['domain'], 'ok', 'Remote session established' );
		return rest_ensure_response(
			array(
				'session_id' => $session['session_id'],
				'token'      => $session['token'],
				'expires_at' => $session['expires_at'],
				'source'     => $info,
			)
		);
	}

	public function remote_info( \WP_REST_Request $request ) {
		$sess = $this->remote_session( $request );
		if ( is_wp_error( $sess ) ) {
			return $sess;
		}
		return rest_ensure_response( ( new Transfer() )->site_info() );
	}

	public function remote_export( \WP_REST_Request $request ) {
		$sess = $this->remote_session( $request );
		if ( is_wp_error( $sess ) ) {
			return $sess;
		}
		$bound = Session_Store::bound_job( $sess );
		if ( '' !== $bound ) {
			// A retried request after a timeout gets the job this session already created.
			$existing = Plugin::instance()->jobs->get( $bound );
			if ( $existing ) {
				return rest_ensure_response( Plugin::instance()->jobs->to_response( $existing ) );
			}
			return new \WP_Error( 'jisento_session', __( 'This migration session already created an export, which no longer exists. Connect again with a new migration key.', 'jisento' ), array( 'status' => 409 ) );
		}
		$job = ( new Exporter() )->start(
			array(
				'mode'         => 'full',
				'skip_cache'   => true,
				'skip_backups' => true,
			)
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! ( new Session_Store() )->bind_job( $sess, $job->job_id ) ) {
			Job_Runner::cancel( $job->job_id );
			return new \WP_Error( 'jisento_session', __( 'Another request already started an export with this migration session.', 'jisento' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( Plugin::instance()->jobs->to_response( $job ) );
	}

	/**
	 * @return object|\WP_Error The session's own export job.
	 */
	private function remote_bound_job( \WP_REST_Request $request ) {
		$sess = $this->remote_session( $request );
		if ( is_wp_error( $sess ) ) {
			return $sess;
		}
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$bound  = Session_Store::bound_job( $sess );
		if ( '' === $bound || ! hash_equals( $bound, $job_id ) ) {
			return new \WP_Error( 'jisento_forbidden', __( 'This migration session may only access the export job it created.', 'jisento' ), array( 'status' => 403 ) );
		}
		$job = Plugin::instance()->jobs->get( $job_id );
		if ( ! $job || 'export' !== $job->type ) {
			return new \WP_Error( 'jisento_missing', __( 'Export job not found.', 'jisento' ), array( 'status' => 404 ) );
		}
		return $job;
	}

	public function remote_export_step( \WP_REST_Request $request ) {
		$job = $this->remote_bound_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		ignore_user_abort( true );
		// Same lock, lease and state checks as the admin step route: a destination that retries
		// after its own timeout gets "worker busy" instead of a second concurrent step.
		return $this->step_response( Job_Runner::step( $job->job_id ) );
	}

	public function remote_chunk( \WP_REST_Request $request ) {
		$job = $this->remote_bound_job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$job_id = $job->job_id;
		if ( 'completed' !== $job->status ) {
			return new \WP_Error( 'jisento_package', __( 'Remote package is not ready.', 'jisento' ), array( 'status' => 409 ) );
		}
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$length = min( 2 * 1024 * 1024, max( 1024, (int) $request->get_param( 'length' ) ) );
		$index  = (int) $request->get_param( 'chunk_index' );

		if ( ! $job || empty( $job->state['package'] ) ) {
			Plugin::instance()->logger->log( $job_id, 'chunk', 'serve', 'chunk-' . $index, 'error', 'HTTP 409 package not ready offset=' . $offset );
			return new \WP_Error( 'jisento_package', __( 'Remote package is not ready.', 'jisento' ), array( 'status' => 409 ) );
		}

		$resolved = Plugin::instance()->storage->resolve( $job->state['package'] );
		$path     = $resolved ? $resolved['path'] : Plugin::instance()->storage->get_path( $job->state['package'] );
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			Plugin::instance()->logger->log( $job_id, 'chunk', 'serve', 'chunk-' . $index, 'error', 'HTTP 404 package missing' );
			return new \WP_Error( 'jisento_package', __( 'Remote package is missing.', 'jisento' ), array( 'status' => 404 ) );
		}

		clearstatcache( true, $path );
		$total = (int) filesize( $path );
		if ( $offset > $total ) {
			return new \WP_Error( 'jisento_chunk', __( 'Requested chunk is past the end of the package.', 'jisento' ), array( 'status' => 404 ) );
		}

		$fp = fopen( $path, 'rb' );
		fseek( $fp, $offset );
		$data = fread( $fp, $length );
		fclose( $fp );
		$hash = hash( 'sha256', $data );

		Plugin::instance()->logger->log(
			$job_id,
			'chunk',
			'serve',
			'chunk-' . $index,
			'ok',
			sprintf( 'HTTP 200 offset=%d size=%d total=%d', $offset, strlen( $data ), $total )
		);

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . strlen( $data ) );
		header( 'X-Jisento-SHA256: ' . $hash );
		header( 'X-Jisento-Offset: ' . $offset );
		header( 'X-Jisento-Total: ' . $total );
		header( 'X-Jisento-Bytes: ' . strlen( $data ) );
		header( 'Cache-Control: no-store' );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function remote_probe( \WP_REST_Request $request ) {
		$limit = Guard::rate_limit( 'handshake', 10, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$https = Guard::require_https_for_remote();
		if ( is_wp_error( $https ) ) {
			return $https;
		}
		$key = strtoupper( sanitize_text_field( $request->get_param( 'key' ) ) );
		$row = ( new Migration_Key_Store() )->find_active( $key );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return rest_ensure_response(
			array(
				'valid'  => true,
				'source' => ( new Transfer() )->site_info(),
			)
		);
	}

	public function test_connection( \WP_REST_Request $request ) {
		$xfer = new Transfer();
		return rest_ensure_response(
			$xfer->test_connection(
				esc_url_raw( $request->get_param( 'source_url' ) ),
				strtoupper( sanitize_text_field( $request->get_param( 'key' ) ) )
			)
		);
	}

	public function diagnostics() {
		return rest_ensure_response( ( new \Jisento\Migration\Core\Diagnostics() )->run() );
	}

	private function upload_chunk_bytes() {
		$max = (int) wp_max_upload_size();
		if ( $max < 2 * 1048576 ) {
			$max = 2 * 1048576;
		}
		$chunk = $max - ( 256 * 1024 );
		if ( $chunk > 16 * 1048576 ) {
			$chunk = 16 * 1048576;
		}
		if ( $chunk < 1048576 ) {
			$chunk = 1048576;
		}
		return (int) $chunk;
	}

	private function upload_paths( $upload_id ) {
		$upload_id = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $upload_id );
		$key       = 'temp/uploads/' . $upload_id . '.part';
		$storage   = Plugin::instance()->storage;
		return array(
			'id'   => $upload_id,
			'key'  => $key,
			'part' => $storage->get_path( $key ),
			'meta' => $storage->get_path( 'temp/uploads/' . $upload_id . '.json' ),
		);
	}

	private function read_upload_meta( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $meta ) ? $meta : null;
	}

	public function upload_init( \WP_REST_Request $request ) {
		$size      = max( 0, (int) $request->get_param( 'size' ) );
		$name      = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$existing  = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $request->get_param( 'upload_id' ) );
		if ( $existing ) {
			$paths = $this->upload_paths( $existing );
			$meta  = $this->read_upload_meta( $paths['meta'] );
			if ( is_array( $meta ) && is_file( $paths['part'] ) && (int) $meta['size'] === $size ) {
				clearstatcache( true, $paths['part'] );
				return rest_ensure_response(
					array(
						'upload_id' => $existing,
						'chunk'     => $this->upload_chunk_bytes(),
						'received'  => (int) filesize( $paths['part'] ),
					)
				);
			}
		}
		$upload_id = 'up_' . bin2hex( random_bytes( 8 ) );
		$paths     = $this->upload_paths( $upload_id );
		wp_mkdir_p( dirname( $paths['part'] ) );
		$fp = fopen( $paths['part'], 'wb' );
		if ( ! $fp ) {
			return new \WP_Error( 'jisento_upload', __( 'Unable to start the upload.', 'jisento' ), array( 'status' => 500 ) );
		}
		fclose( $fp );
		$meta = array(
			'key'      => $paths['key'],
			'filename' => $name,
			'size'     => $size,
		);
		file_put_contents( $paths['meta'], wp_json_encode( $meta ) );
		return rest_ensure_response(
			array(
				'upload_id' => $upload_id,
				'chunk'     => $this->upload_chunk_bytes(),
			)
		);
	}

	public function upload_chunk( \WP_REST_Request $request ) {
		$paths  = $this->upload_paths( $request->get_param( 'upload_id' ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$meta   = $this->read_upload_meta( $paths['meta'] );
		if ( ! is_array( $meta ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Upload session expired. Please retry.', 'jisento' ), array( 'status' => 410 ) );
		}
		$files = $request->get_file_params();
		if ( empty( $files['chunk']['tmp_name'] ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Missing upload chunk.', 'jisento' ), array( 'status' => 400 ) );
		}
		clearstatcache( true, $paths['part'] );
		$current = is_file( $paths['part'] ) ? (int) filesize( $paths['part'] ) : 0;
		if ( $offset > $current ) {
			return new \WP_Error( 'jisento_upload', __( 'Upload chunk is out of order. Please retry the upload.', 'jisento' ), array( 'status' => 409 ) );
		}
		$mode = ( $offset === $current ) ? 'ab' : 'rb+';
		$fp   = fopen( $paths['part'], $mode );
		if ( ! $fp ) {
			return new \WP_Error( 'jisento_upload', __( 'Unable to write upload chunk.', 'jisento' ), array( 'status' => 500 ) );
		}
		if ( 'rb+' === $mode ) {
			fseek( $fp, $offset );
		}
		$in = fopen( $files['chunk']['tmp_name'], 'rb' );
		if ( ! $in ) {
			fclose( $fp );
			return new \WP_Error( 'jisento_upload', __( 'Unable to read upload chunk.', 'jisento' ), array( 'status' => 500 ) );
		}
		$copied = stream_copy_to_stream( $in, $fp );
		if ( false === $copied ) {
			fclose( $in );
			fclose( $fp );
			return new \WP_Error( 'jisento_upload', __( 'Unable to write upload chunk.', 'jisento' ), array( 'status' => 500 ) );
		}
		fflush( $fp );
		ftruncate( $fp, $offset + (int) $copied );
		fclose( $in );
		fclose( $fp );
		return rest_ensure_response(
			array(
				'received' => $offset + (int) $copied,
				'size'     => (int) $meta['size'],
			)
		);
	}

	public function upload_complete( \WP_REST_Request $request ) {
		$paths = $this->upload_paths( $request->get_param( 'upload_id' ) );
		$meta  = $this->read_upload_meta( $paths['meta'] );
		if ( ! is_array( $meta ) ) {
			return new \WP_Error( 'jisento_upload', __( 'Upload session expired. Please retry.', 'jisento' ), array( 'status' => 410 ) );
		}
		$storage = Plugin::instance()->storage;
		$part    = $paths['part'];
		clearstatcache( true, $part );
		$size = is_file( $part ) ? (int) filesize( $part ) : 0;
		if ( $size <= 0 ) {
			return new \WP_Error( 'jisento_upload', __( 'Uploaded file is empty.', 'jisento' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $meta['size'] ) && (int) $meta['size'] !== $size ) {
			return new \WP_Error( 'jisento_upload', __( 'The uploaded package is incomplete. Please retry the upload.', 'jisento' ), array( 'status' => 400 ) );
		}
		$name = ! empty( $meta['filename'] ) ? $meta['filename'] : ( $paths['id'] . '.jisento' );
		if ( ! preg_match( '/\.jisento$/i', $name ) ) {
			$name .= '.jisento';
		}
		$key  = 'packages/' . $name;
		$dest = $storage->get_path( $key );
		wp_mkdir_p( dirname( $dest ) );
		if ( ! @rename( $part, $dest ) ) {
			\Jisento\Migration\Filesystem\File_System::stream_copy( $part, $dest );
			@unlink( $part );
		}
		@unlink( $paths['meta'] );
		$inspect = ( new \Jisento\Migration\Package\Archive() )->inspect( $dest );
		if ( is_wp_error( $inspect ) ) {
			@unlink( $dest );
			return $inspect;
		}
		$checksum = \Jisento\Migration\Filesystem\File_System::hash_file( $dest );
		clearstatcache( true, $dest );
		$size     = (int) filesize( $dest );
		$manifest = isset( $inspect['manifest'] ) && is_array( $inspect['manifest'] ) ? $inspect['manifest'] : array();
		$record   = array(
			'filename'          => $name,
			'storage_key'       => $key,
			'size'              => $size,
			'checksum'          => $checksum,
			'type'              => 'upload',
			'status'            => 'completed',
			'created_at'        => current_time( 'mysql' ),
			'package_version'   => isset( $manifest['package_version'] ) ? $manifest['package_version'] : '',
			'format_marker'     => \JISENTO_FORMAT_MARKER,
			'home_url'          => isset( $manifest['home_url'] ) ? $manifest['home_url'] : '',
			'database_size'     => isset( $manifest['database_size'] ) ? (int) $manifest['database_size'] : 0,
			'files_size'        => isset( $manifest['files_size'] ) ? (int) $manifest['files_size'] : 0,
			'uncompressed_size' => isset( $manifest['uncompressed_size'] ) ? (int) $manifest['uncompressed_size'] : 0,
			'table_count'       => isset( $manifest['table_count'] ) ? (int) $manifest['table_count'] : 0,
			'file_count'        => isset( $manifest['file_count'] ) ? (int) $manifest['file_count'] : 0,
			'contents'          => isset( $manifest['contents'] ) ? $manifest['contents'] : '',
		);
		file_put_contents( $dest . '.json', wp_json_encode( $record ) );
		$id = ( new \Jisento\Migration\Package\Package_Registry() )->create( $record );
		return rest_ensure_response(
			array(
				'package'    => $key,
				'package_id' => $id,
				'manifest'   => $manifest,
				'size'       => $size,
			)
		);
	}

	public function validate_package( \WP_REST_Request $request ) {
		$key      = sanitize_text_field( $request->get_param( 'package' ) );
		$resolved = Plugin::instance()->storage->resolve( $key );
		if ( ! $resolved ) {
			return new \WP_Error( 'jisento_invalid_package', __( 'Invalid Jisento Package. The file is missing.', 'jisento' ), array( 'status' => 400 ) );
		}
		$sidecar = $resolved['path'] . '.json';
		if ( is_readable( $sidecar ) ) {
			$meta = json_decode( (string) file_get_contents( $sidecar ), true );
			clearstatcache( true, $resolved['path'] );
			$size = (int) filesize( $resolved['path'] );
			if ( is_array( $meta ) && isset( $meta['status'] ) && 'completed' === $meta['status'] && isset( $meta['size'] ) && (int) $meta['size'] === $size && $size > 0 && ! empty( $meta['home_url'] ) && ! empty( $meta['package_version'] ) ) {
				return rest_ensure_response(
					array(
						'ok'        => true,
						'package'   => $resolved['key'],
						'manifest'  => array(
							'package_version'   => $meta['package_version'],
							'format_marker'     => isset( $meta['format_marker'] ) ? $meta['format_marker'] : ( isset( $meta['signature'] ) ? $meta['signature'] : '' ),
							'home_url'          => $meta['home_url'],
							'database_size'     => isset( $meta['database_size'] ) ? (int) $meta['database_size'] : 0,
							'files_size'        => isset( $meta['files_size'] ) ? (int) $meta['files_size'] : 0,
							'uncompressed_size' => isset( $meta['uncompressed_size'] ) ? (int) $meta['uncompressed_size'] : 0,
							'table_count'       => isset( $meta['table_count'] ) ? (int) $meta['table_count'] : 0,
							'file_count'        => isset( $meta['file_count'] ) ? (int) $meta['file_count'] : 0,
							'contents'          => isset( $meta['contents'] ) ? $meta['contents'] : '',
						),
						'has_db'    => 'files' !== ( isset( $meta['contents'] ) ? $meta['contents'] : '' ),
						'has_files' => 'database' !== ( isset( $meta['contents'] ) ? $meta['contents'] : '' ),
						'size'      => $size,
						'reused'    => true,
					)
				);
			}
		}
		$inspect = ( new \Jisento\Migration\Package\Archive() )->inspect( $resolved['path'] );
		if ( is_wp_error( $inspect ) ) {
			return $inspect;
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'package'  => $resolved['key'],
				'manifest' => $inspect['manifest'],
				'has_db'   => $inspect['has_db'],
				'has_files'=> $inspect['has_files'],
				'size'     => $inspect['size'],
			)
		);
	}
}
