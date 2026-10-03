<?php
/**
 * Phase 2.1: dispatch tokens, step budget, loopback probe marker, scheduler helpers.
 *
 * Run: php tests/job-scheduler-test.php
 */

$root = sys_get_temp_dir() . '/jisento-sched-' . getmypid();
@mkdir( $root . '/jobs', 0777, true );
@mkdir( $root . '/loopback', 0777, true );
define( 'JISENTO_CONTINUATION_DIR', $root . '/jobs' );
define( 'JISENTO_LOOPBACK_DIR', $root . '/loopback' );
require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Jobs\Job_Scheduler;
use Jisento\Migration\Jobs\Step_Budget;
use Jisento\Migration\Security\Job_Continuation;

function sched_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? sched_rmtree( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}

register_shutdown_function(
	static function () use ( $root ) {
		sched_rmtree( $root );
	}
);

$job_a = 'jisento_mig_aaaaaa';
$job_b = 'jisento_mig_bbbbbb';

// Continuity record so mac_key can be created.
$issued = Job_Continuation::issue( $job_a );
check( 'continuation issue works', preg_match( '/^[a-f0-9]{64}$/', $issued ) );

$token = Job_Continuation::issue_dispatch( $job_a, 300 );
check( 'dispatch token shape', (bool) preg_match( '/^\d{10,12}\.[a-f0-9]{64}$/', $token ), $token );
check( 'dispatch verifies for same job', Job_Continuation::verify_dispatch( $job_a, $token ) );
check( 'dispatch rejected for another job_id', ! Job_Continuation::verify_dispatch( $job_b, $token ) );

$parts   = explode( '.', $token, 2 );
$expired = (string) ( time() - 10 ) . '.' . hash_hmac( 'sha256', $job_a . '|step|' . ( time() - 10 ), file_get_contents( JISENTO_CONTINUATION_DIR . '/.continuation-key' ) );
// Rebuild expired with real key via reflection of private dispatch_mac is hard; forge by issuing short TTL then waiting is slow.
// Issue with ttl that we then rewind by reconstructing message with past expiry using same key file.
$key_file = trim( (string) file_get_contents( JISENTO_CONTINUATION_DIR . '/.continuation-key' ) );
$past     = time() - 60;
$expired  = $past . '.' . hash_hmac( 'sha256', $job_a . '|step|' . $past, $key_file );
check( 'dispatch rejected after expiry', ! Job_Continuation::verify_dispatch( $job_a, $expired ) );

$tampered = $parts[0] . '.' . str_repeat( 'ab', 32 );
check( 'dispatch rejected when hmac tampered', ! Job_Continuation::verify_dispatch( $job_a, $tampered ) );

check( 'dispatch token is not a continuation secret', ! Job_Continuation::matches( $job_a, $token ) );
check( 'continuation secret is not a dispatch token', ! Job_Continuation::verify_dispatch( $job_a, $issued ) );

// Route scope: only step_permission accepts dispatch (asserted via source + is_step_post_request).
$rest = file_get_contents( JISENTO_PATH . 'includes/Api/Rest_Controller.php' );
check( 'POST step uses step_permission', (bool) preg_match( "/'callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'step_job'\\s*\\)[\\s\\S]{0,200}'permission_callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'step_permission'\\s*\\)/", $rest ) );
check( 'pause uses job_permission (no dispatch)', (bool) preg_match( "/'callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'pause_job'\\s*\\)[\\s\\S]{0,200}'permission_callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'job_permission'\\s*\\)/", $rest ) );
check( 'cancel uses job_permission (no dispatch)', (bool) preg_match( "/'callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'cancel_job'\\s*\\)[\\s\\S]{0,200}'permission_callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'job_permission'\\s*\\)/", $rest ) );
check( 'retry uses job_permission (no dispatch)', (bool) preg_match( "/'callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'retry_job'\\s*\\)[\\s\\S]{0,200}'permission_callback'\\s*=>\\s*array\\(\\s*\\\$this,\\s*'job_permission'\\s*\\)/", $rest ) );

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI']    = '/wp-json/jisento/v1/jobs/' . $job_a;
unset( $_GET['rest_route'] );
check( 'is_step_post_request true for step URI', Job_Continuation::is_step_post_request() );

$_SERVER['REQUEST_URI'] = '/wp-json/jisento/v1/jobs/' . $job_a . '/cancel';
check( 'is_step_post_request false for cancel URI', ! Job_Continuation::is_step_post_request() );

$_SERVER['REQUEST_URI'] = '/index.php';
$_GET['rest_route']     = '/jisento/v1/jobs/' . $job_a;
check( 'is_step_post_request true for rest_route step', Job_Continuation::is_step_post_request() );

$_GET['rest_route'] = '/jisento/v1/jobs/' . $job_a . '/retry';
check( 'is_step_post_request false for rest_route retry', ! Job_Continuation::is_step_post_request() );

// Step budget includes boot (REQUEST_TIME_FLOAT).
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 5.0;
Step_Budget::begin( 12 );
$left = Step_Budget::remaining();
check( 'budget accounts for boot time (~7s left of 12)', $left > 5.5 && $left < 8.5, (string) $left );
check( 'seconds() clamps to remaining', Step_Budget::seconds( 30 ) <= $left + 0.01 );

$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 20.0;
Step_Budget::begin( 12 );
check( 'stale REQUEST_TIME_FLOAT does not exhaust CLI/multi-step budget', ! Step_Budget::exhausted() && Step_Budget::remaining() > 10.0 );

// Loopback probe marker must be written for probe to succeed.
$nonce = bin2hex( random_bytes( 12 ) );
file_put_contents( JISENTO_LOOPBACK_DIR . '/' . $nonce . '.expect', (string) time() );
$done = Job_Scheduler::complete_probe( $nonce );
check( 'complete_probe writes marker', ! is_wp_error( $done ) && is_file( JISENTO_LOOPBACK_DIR . '/' . $nonce . '.ok' ) );
$bad = Job_Scheduler::complete_probe( 'deadbeefdeadbeef' );
check( 'complete_probe rejects unknown nonce', is_wp_error( $bad ) );

$url = Job_Scheduler::rest_url( 'jobs/' . $job_a );
check( 'rest_url uses rest_route query', false !== strpos( $url, 'rest_route=' ) && false !== strpos( $url, rawurlencode( '/jisento/v1/jobs/' . $job_a ) ) || false !== strpos( $url, '/jisento/v1/jobs/' . $job_a ), $url );

$posted = array();
Job_Scheduler::$http_post = static function ( $url, $args ) use ( &$posted ) {
	$posted[] = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 200 ) );
};
$ok = Job_Scheduler::dispatch_step( $job_a );
check( 'dispatch_step fires non-blocking post', $ok && count( $posted ) === 1 );
check( 'dispatch uses ~1s timeout', isset( $posted[0]['args']['timeout'] ) && (float) $posted[0]['args']['timeout'] >= 0.9 && (float) $posted[0]['args']['timeout'] <= 1.5 );
check( 'dispatch is non-blocking', empty( $posted[0]['args']['blocking'] ) );
check( 'dispatch sends X-Jisento-Dispatch', ! empty( $posted[0]['args']['headers']['X-Jisento-Dispatch'] ) );
check( 'dispatch sends Cache-Control no-cache', ! empty( $posted[0]['args']['headers']['Cache-Control'] ) && false !== stripos( $posted[0]['args']['headers']['Cache-Control'], 'no-cache' ) );
$disp = $posted[0]['args']['headers']['X-Jisento-Dispatch'];
check( 'dispatched token verifies', Job_Continuation::verify_dispatch( $job_a, $disp ) );

$job = (object) array(
	'job_id'     => $job_a,
	'status'     => 'running',
	'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 150 ),
	'state'      => array( 'worker_mode' => 'server' ),
);
check( 'is_stalled after 120s silence', Job_Scheduler::is_stalled( $job ) );
$job->updated_at = gmdate( 'Y-m-d H:i:s', time() - 10 );
check( 'not stalled when fresh', ! Job_Scheduler::is_stalled( $job ) );

$cli = file_get_contents( JISENTO_PATH . 'includes/Cli/Commands.php' );
check( 'WP-CLI export command present', false !== strpos( $cli, 'function export' ) );
check( 'WP-CLI import command present', false !== strpos( $cli, 'function import' ) );
check( 'WP-CLI resume command present', false !== strpos( $cli, 'function resume' ) );
check( 'WP-CLI status command present', false !== strpos( $cli, 'function status' ) );

$admin = file_get_contents( JISENTO_PATH . 'admin/js/admin.js' );
check( 'admin polls every 2s in server mode', false !== strpos( $admin, 'await wait(2000)' ) );
check( 'admin keeps browser step loop when worker_mode is browser', false !== strpos( $admin, "job.worker_mode === 'browser'" ) );
check( 'admin shows Reconnecting...', false !== strpos( $admin, 'Reconnecting...' ) );
check( 'admin shows stalled Resume', false !== strpos( $admin, 'jisento-resume-stalled' ) );
check( 'admin shows loopback notice', false !== strpos( $admin, 'jisento-loopback-notice' ) );

Job_Scheduler::$http_post = null;
jisento_test_finish();
