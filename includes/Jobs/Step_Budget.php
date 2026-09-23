<?php
/**
 * Per-request step time budget measured from WordPress request start (boot included).
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Step_Budget {

	/**
	 * Target wall time for one step, including WordPress boot (mid of 10–15 s).
	 */
	const LIMIT = 12.0;

	/**
	 * @var float|null Unix timestamp when this step must stop.
	 */
	private static $deadline = null;

	/**
	 * Start (or restart) the budget from REQUEST_TIME_FLOAT when this is still the same HTTP request.
	 * CLI and long test processes call step() many times in one PHP process; their REQUEST_TIME_FLOAT
	 * is the process start and must not exhaust every later step.
	 *
	 * @param float|null $limit Seconds from request start. Null uses LIMIT.
	 */
	public static function begin( $limit = null ) {
		$now   = microtime( true );
		$req   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $now;
		$start = ( ( $now - $req ) <= ( self::LIMIT + 5.0 ) ) ? $req : $now;
		$limit = null === $limit ? self::LIMIT : max( 1.0, (float) $limit );
		self::$deadline = $start + $limit;
	}

	/**
	 * @return float Absolute deadline timestamp.
	 */
	public static function deadline() {
		if ( null === self::$deadline ) {
			self::begin();
		}
		return (float) self::$deadline;
	}

	/**
	 * Seconds left before the deadline. Never negative.
	 *
	 * @return float
	 */
	public static function remaining() {
		return max( 0.0, self::deadline() - microtime( true ) );
	}

	/**
	 * Preferred inner budget, clamped so the request still finishes within the step limit.
	 *
	 * @param float $preferred Preferred seconds for this sub-operation.
	 * @return float At least 0.25 when any time remains; 0 when the budget is exhausted.
	 */
	public static function seconds( $preferred ) {
		$left = self::remaining();
		if ( $left <= 0 ) {
			return 0.0;
		}
		$preferred = (float) $preferred;
		if ( $preferred <= 0 ) {
			return min( 0.25, $left );
		}
		return min( $preferred, $left );
	}

	/**
	 * @return bool
	 */
	public static function exhausted() {
		return self::remaining() <= 0;
	}
}
