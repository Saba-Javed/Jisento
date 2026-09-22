<?php
/**
 * Storage adapter contract for local and future cloud backends.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Storage_Adapter {

	public function root();

	public function put( $relative, $contents );

	public function put_stream( $relative, $source_path );

	public function get_path( $relative );

	public function exists( $relative );

	public function delete( $relative );

	public function list_files( $relative_dir = '' );

	public function size( $relative );
}
