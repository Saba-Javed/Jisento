<?php
/**
 * Thrown when a job row changed since it was read (another request or an administrator action won).
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Job_Conflict extends \RuntimeException {
}
