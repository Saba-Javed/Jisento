<?php
/**
 * B4: Migration history Logs page, retention, and redacted downloads.
 *
 * Run: php tests/logs-history-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Core\Logger;

$view   = file_get_contents( dirname( __DIR__ ) . '/admin/views/logs.php' );
$admin  = file_get_contents( dirname( __DIR__ ) . '/includes/Admin/Admin.php' );
$logger = file_get_contents( dirname( __DIR__ ) . '/includes/Core/Logger.php' );
$plugin = file_get_contents( dirname( __DIR__ ) . '/includes/Plugin.php' );

check( 'logs default heading Migration history', false !== strpos( $view, 'Migration history' ) );
check( 'history table columns', false !== strpos( $view, 'Date' ) && false !== strpos( $view, 'Type' ) && false !== strpos( $view, 'Result' ) && false !== strpos( $view, 'Duration' ) && false !== strpos( $view, 'Details' ) );
check( 'Advanced holds Debug log', false !== strpos( $view, 'Advanced' ) && false !== strpos( $view, 'Debug log' ) );
check( 'per-job Download debug log', false !== strpos( $view, 'Download debug log' ) );
check( 'type labels include import replace/preserve and key transfer', false !== strpos( $admin, 'Import replace' ) && false !== strpos( $admin, 'Import preserve' ) && false !== strpos( $admin, 'Key transfer' ) );
check( 'page_logs builds history', false !== strpos( $admin, 'migration_history' ) );
check( 'prune accepts max bytes', false !== strpos( $logger, 'function prune' ) && false !== strpos( $logger, 'max_bytes' ) );
check( 'prune protects running/resumable jobs', false !== strpos( $logger, 'protected_migration_ids' ) && false !== strpos( $logger, "'paused'" ) );
check( 'maintenance uses 20 MB cap', false !== strpos( $plugin, '20 * 1048576' ) || false !== strpos( $plugin, '20971520' ) );

$sample = Logger::redact( 'token=abc123secret password: hunter2 key=JIS-AAAA-BBBB-CCCC user_pass = \'$P$Bhash\' Authorization: Bearer eyJhbGciOi.payload' );
check( 'redacted export has no raw token value', false === strpos( $sample, 'abc123secret' ) );
check( 'redacted export has no password value', false === strpos( $sample, 'hunter2' ) );
check( 'redacted export has no migration key', false === strpos( $sample, 'JIS-AAAA-BBBB-CCCC' ) );
check( 'redacted export has no password hash', false === strpos( $sample, '$P$Bhash' ) );
check( 'redacted export has no bearer token', false === strpos( $sample, 'eyJhbGciOi' ) );
check( 'downloaded text uses redact helper', false !== strpos( $logger, 'self::redact' ) );

jisento_test_finish();
