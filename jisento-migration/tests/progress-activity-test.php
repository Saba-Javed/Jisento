<?php
/**
 * Import progress activity must match the current stage.
 *
 * Run: php tests/progress-activity-test.php
 */

require __DIR__ . '/bootstrap.php';

use Jisento\Migration\Import\Importer;

$src = file_get_contents( JISENTO_PATH . 'includes/Import/Importer.php' );
check( 'report resets activity when stage and phase diverge', false !== strpos( $src, 'activity_for_stage' ) && false !== strpos( $src, '$stage !== $phase' ) );

$ref = new ReflectionClass( Importer::class );
$m   = $ref->getMethod( 'activity_for_stage' );
$m->setAccessible( true );
$importer = $ref->newInstanceWithoutConstructor();

foreach ( array( 'importing_files', 'replacing_urls', 'finalizing' ) as $stage ) {
	$act = $m->invoke( $importer, $stage );
	check(
		"activity_for_stage($stage) uses that stage as phase",
		is_array( $act ) && $stage === $act['phase'] && '' !== $act['label'] && '' !== $act['detail'],
		json_encode( $act )
	);
}

// Simulate the lagging transition: stage advances to finalizing while activity still says replacing_urls.
$lagging = array(
	'phase'  => 'replacing_urls',
	'label'  => 'Replacing URLs',
	'detail' => 'Restoring files (stale)',
);
$fixed = $m->invoke( $importer, 'finalizing' );
check( 'new stage activity is not the previous stage label', 'finalizing' === $fixed['phase'] && false === strpos( $fixed['label'], 'Replacing' ) && false === strpos( $fixed['label'], 'Restoring files' ), json_encode( $fixed ) );
check( 'lagging activity would disagree with the stage', $lagging['phase'] !== 'finalizing' );

// report() path: when fields.stage differs from activity.phase, activity_for_stage wins.
$report = $ref->getMethod( 'report' );
check( 'report method still exists for stage writes', $report->isPrivate() );

jisento_test_finish();
