<?php
/**
 * B1: Import screen is a 3-step wizard with plain-language copy.
 *
 * Run: php tests/import-wizard-test.php
 */

require __DIR__ . '/bootstrap.php';

$view = file_get_contents( dirname( __DIR__ ) . '/admin/views/import.php' );
$css  = file_get_contents( dirname( __DIR__ ) . '/admin/css/admin.css' );
$js   = file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
$fp   = file_get_contents( dirname( __DIR__ ) . '/admin/js/file-picker.js' );

check( 'wizard nav has Package / Mode / Review', false !== strpos( $view, 'Package' ) && false !== strpos( $view, 'Mode' ) && false !== strpos( $view, 'Review' ) );
check( 'step buttons use data-wizard-goto', false !== strpos( $view, 'data-wizard-goto="1"' ) && false !== strpos( $view, 'data-wizard-goto="2"' ) && false !== strpos( $view, 'data-wizard-goto="3"' ) );
check( 'step 1 title Choose a package', false !== strpos( $view, 'Choose a package' ) );
check( 'upload card title', false !== strpos( $view, 'Upload a .jisento file' ) );
check( 'existing backup card title', false !== strpos( $view, 'Or pick an existing backup' ) );
check( 'drag and drop hint', false !== strpos( $view, 'Drag and drop' ) );
check( 'Choose file button', false !== strpos( $view, 'Choose file' ) );
check( 'step 2 title', false !== strpos( $view, 'What should happen to this site?' ) );
check( 'Replace this site card', false !== strpos( $view, 'Replace this site' ) );
check( 'Keep my logins card', false !== strpos( $view, 'Keep my logins, themes and plugins' ) );
check( 'step 3 Review and start', false !== strpos( $view, 'Review and start' ) );
check( 'review rows From To Size Links', false !== strpos( $view, 'jisento-review-from' ) && false !== strpos( $view, 'jisento-review-to' ) && false !== strpos( $view, 'jisento-review-size' ) && false !== strpos( $view, 'jisento-review-links' ) );
check( 'Advanced settings toggle', false !== strpos( $view, 'Advanced settings' ) && false !== strpos( $view, '<details' ) );
check( 'replace server paths option', false !== strpos( $view, 'Replace server paths' ) );
check( 'backup checkbox copy', false !== strpos( $view, 'I have a backup of this site' ) );
check( 'Start migration label', false !== strpos( $view, 'Start migration' ) );
check( 'no Package validated list', false === strpos( $view, 'Package validated' ) );
check( 'no format marker jargon', false === stripos( $view, 'format marker' ) );
check( 'no shadow tables jargon', false === stripos( $view, 'shadow' ) && false === stripos( $view, 'work tables' ) );
check( 'CSS wizard steps', false !== strpos( $css, 'jisento-wizard-steps' ) );
check( 'CSS dropzone', false !== strpos( $css, 'jisento-dropzone' ) );
check( 'JS uploaded and verified', false !== strpos( $js, 'uploaded and verified' ) );
check( 'JS setWizardStep', false !== strpos( $js, 'function setWizardStep' ) );
check( 'JS preserve modal on mode select', false !== strpos( $js, 'onModeSelected' ) && false !== strpos( $js, 'confirmPreserveModal' ) );
check( 'file picker drop handler', false !== strpos( $fp, "addEventListener('drop'" ) );
check( 'file picker choose button', false !== strpos( $fp, 'data-jisento-choose' ) );

jisento_test_finish();
