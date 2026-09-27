<?php
/**
 * Append-only ZIP writer that builds one archive across many short requests.
 *
 * - Every local header is written with CRC/size placeholders and patched in place (seek back)
 *   when its entry completes. No data descriptors, so every reader sees final values.
 * - An entry is deflated only when it is small enough to finish inside the current request:
 *   a deflate context cannot be carried to the next request. Anything else is stored and may
 *   span requests.
 * - A spanning stored entry only pauses on a hash-chunk boundary. PHP 7.4 cannot serialize a
 *   HashContext, so the CRC-32 of each request's piece is merged with crc32_combine(), and the
 *   entry digest is SHA-256 over the SHA-256 of every fixed-size chunk (see chunk_digest()).
 * - commit() flushes the archive, then saves the committed length. resume() truncates the
 *   archive back to that length before anything is appended, so bytes written by a killed
 *   request are discarded.
 * - Finished entries are appended to a JSON-lines index; finish() builds the central directory
 *   (ZIP64 when needed) from it.
 *
 * @package Jisento\Migration
 */

namespace Jisento\Migration\Package;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Streaming_Zip_Writer {

	const HASH_CHUNK  = 8388608;
	const DEFLATE_MAX = 67108864;
	const READ_BYTES  = 1048576;
	const MAX32       = 0xFFFFFFFF;

	/**
	 * Assumed deflate throughput before this request has measured its own.
	 */
	const DEFLATE_RATE = 20971520;

	/**
	 * @var callable|null Test hook: function( string $point ). Points: after_data, after_patch.
	 */
	public static $fault = null;

	private $path;
	private $state_path;
	private $index_path;
	private $state;
	private $fh;
	private $index_fh;
	private $deflate_rate = 0.0;

	private function __construct( $path, $state_path ) {
		$this->path       = (string) $path;
		$this->state_path = (string) $state_path;
		$this->index_path = $this->state_path . '.index';
	}

	/**
	 * Start a new archive, replacing any earlier attempt at the same paths.
	 *
	 * @param string $path       Archive (.partial) path.
	 * @param string $state_path Sidecar JSON path.
	 * @param array  $options    force_zip64 (bool), hash_chunk (int, tests only).
	 * @return self
	 */
	public static function create( $path, $state_path, array $options = array() ) {
		$writer = new self( $path, $state_path );
		$dir    = dirname( $writer->path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( sprintf( 'Operation: create the package. Reason: folder %s could not be created. Recovery: make wp-content/jisento writable by PHP, then start a new export.', $dir ) );
		}
		$chunk = isset( $options['hash_chunk'] ) ? (int) $options['hash_chunk'] : self::HASH_CHUNK;
		if ( $chunk < 4096 || 0 !== $chunk % 4096 ) {
			throw new \InvalidArgumentException( 'hash_chunk must be a positive multiple of 4096.' );
		}
		$writer->state = array(
			'version'     => 1,
			'offset'      => 0,
			'index_bytes' => 0,
			'count'       => 0,
			'files_count' => 0,
			'files_bytes' => 0,
			'force_zip64' => ! empty( $options['force_zip64'] ),
			'hash_chunk'  => $chunk,
			'open'        => null,
			'finished'    => false,
		);
		$writer->open_handles( true );
		$writer->commit();
		return $writer;
	}

	/**
	 * Reopen an archive after an earlier request, discarding anything past the committed length.
	 *
	 * @param string $path       Archive path.
	 * @param string $state_path Sidecar path.
	 * @return self
	 */
	public static function resume( $path, $state_path ) {
		$writer = new self( $path, $state_path );
		$raw    = is_readable( $writer->state_path ) ? file_get_contents( $writer->state_path ) : false;
		$state  = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['offset'], $state['index_bytes'], $state['hash_chunk'] ) ) {
			throw new \RuntimeException( 'Operation: resume building the package. Reason: the package progress file is missing or damaged. Recovery: start a new export.' );
		}
		if ( ! empty( $state['finished'] ) ) {
			throw new \RuntimeException( 'Operation: resume building the package. Reason: the package was already finished. Recovery: none needed; reload the job status.' );
		}
		clearstatcache( true, $writer->path );
		if ( ! is_file( $writer->path ) || (int) filesize( $writer->path ) < (int) $state['offset'] ) {
			throw new \RuntimeException( 'Operation: resume building the package. Reason: the partial package is missing or shorter than its committed length. Recovery: start a new export.' );
		}
		$writer->state = $state;
		$writer->open_handles( false );
		return $writer;
	}

	private function open_handles( $fresh ) {
		$mode = $fresh ? 'w+b' : 'r+b';
		$fh   = @fopen( $this->path, $mode );
		$ix   = @fopen( $this->index_path, $fresh ? 'w+b' : ( is_file( $this->index_path ) ? 'r+b' : 'w+b' ) );
		if ( ! $fh || ! $ix ) {
			if ( $fh ) {
				fclose( $fh );
			}
			if ( $ix ) {
				fclose( $ix );
			}
			throw new \RuntimeException( sprintf( 'Operation: open the package for writing. Reason: %s could not be opened (permission denied or disk full). Recovery: make wp-content/jisento writable by PHP, then start a new export.', basename( $this->path ) ) );
		}
		// Anything past the committed length was written by a request that did not finish.
		if ( ! ftruncate( $fh, (int) $this->state['offset'] ) || ! ftruncate( $ix, (int) $this->state['index_bytes'] ) ) {
			fclose( $fh );
			fclose( $ix );
			throw new \RuntimeException( 'Operation: resume building the package. Reason: the partial package could not be truncated to its committed length. Recovery: start a new export.' );
		}
		fseek( $fh, (int) $this->state['offset'] );
		fseek( $ix, (int) $this->state['index_bytes'] );
		$this->fh       = $fh;
		$this->index_fh = $ix;
	}

	public function close() {
		if ( $this->fh ) {
			fclose( $this->fh );
			$this->fh = null;
		}
		if ( $this->index_fh ) {
			fclose( $this->index_fh );
			$this->index_fh = null;
		}
	}

	/**
	 * Flush the archive and index, then save the committed lengths.
	 */
	public function commit() {
		$this->flush_handle( $this->fh );
		$this->flush_handle( $this->index_fh );
		$this->state['offset']      = $this->tell_end( $this->fh );
		$this->state['index_bytes'] = $this->tell_end( $this->index_fh );
		$json = wp_json_encode( $this->state );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Operation: save package progress. Reason: the progress could not be encoded as JSON. Recovery: start a new export.' );
		}
		$tmp = $this->state_path . '.tmp';
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! @rename( $tmp, $this->state_path ) ) {
			@unlink( $tmp );
			throw new \RuntimeException( sprintf( 'Operation: save package progress. Reason: %s could not be written (disk full or permission denied). Recovery: free disk space, then start a new export.', basename( $this->state_path ) ) );
		}
	}

	private function flush_handle( $handle ) {
		if ( ! fflush( $handle ) ) {
			throw new \RuntimeException( 'Operation: write the package. Reason: flushing to disk failed (disk full or quota reached). Recovery: free disk space, then start a new export.' );
		}
		if ( function_exists( 'fsync' ) ) {
			fsync( $handle );
		}
	}

	private function tell_end( $handle ) {
		fseek( $handle, 0, SEEK_END );
		return (int) ftell( $handle );
	}

	/**
	 * @return bool True while a stored entry is only partly written.
	 */
	public function has_open_entry() {
		return ! empty( $this->state['open'] );
	}

	/**
	 * @return array{name:string,done:int,size:int}|null
	 */
	public function open_entry() {
		if ( empty( $this->state['open'] ) ) {
			return null;
		}
		return array(
			'name' => (string) $this->state['open']['name'],
			'done' => (int) $this->state['open']['done'],
			'size' => (int) $this->state['open']['usize'],
		);
	}

	/**
	 * Caller position (for example the next file to add). Saved by the same commit() as the
	 * archive length, so a request killed between commit() and the job update cannot add a file twice.
	 *
	 * @return array
	 */
	public function cursor() {
		return isset( $this->state['cursor'] ) && is_array( $this->state['cursor'] ) ? $this->state['cursor'] : array();
	}

	/**
	 * @param array $cursor Saved at the next commit().
	 */
	public function set_cursor( array $cursor ) {
		$this->state['cursor'] = $cursor;
	}

	/**
	 * Result of finish() when an earlier request already finished the archive.
	 *
	 * @param string $state_path Sidecar path.
	 * @return array{size:int,count:int,content_sha256:string}|null
	 */
	public static function finished_result( $state_path ) {
		$raw   = is_readable( $state_path ) ? file_get_contents( $state_path ) : false;
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $state ) || empty( $state['finished'] ) || empty( $state['result'] ) || ! is_array( $state['result'] ) ) {
			return null;
		}
		return $state['result'];
	}

	/**
	 * @return array{count:int,files_count:int,files_bytes:int,offset:int}
	 */
	public function totals() {
		return array(
			'count'       => (int) $this->state['count'],
			'files_count' => (int) $this->state['files_count'],
			'files_bytes' => (int) $this->state['files_bytes'],
			'offset'      => (int) $this->state['offset'],
		);
	}

	/**
	 * Add an entry from a string. Always finishes in this request.
	 *
	 * @param string $name Entry name.
	 * @param string $data Contents.
	 */
	public function add_string( $name, $data ) {
		$this->assert_can_add( $name );
		$data   = (string) $data;
		$size   = strlen( $data );
		$method = ( $size > 0 && ! self::precompressed( $name ) ) ? 8 : 0;
		$entry  = $this->begin_entry( $name, $size, time(), $method );
		$crc    = hash( 'crc32b', $data );
		$digest = hash( 'sha256', self::chunk_hashes_of( $data, (int) $this->state['hash_chunk'] ), false );
		if ( 8 === $method ) {
			$out = gzdeflate( $data, 6 );
			if ( false === $out ) {
				throw new \RuntimeException( sprintf( 'Operation: compress %s. Reason: zlib failed. Recovery: start a new export.', self::printable( $name ) ) );
			}
		} else {
			$out = $data;
		}
		$this->write( $out );
		$entry['crc']   = (int) hexdec( $crc );
		$entry['csize'] = strlen( $out );
		$entry['sha']   = $digest;
		$this->complete_entry( $entry );
	}

	/**
	 * Add a file. A compressible file no larger than $max_bytes that can be deflated before the
	 * deadline is deflated in full now; any other file is stored and may stop on a chunk
	 * boundary, leaving an open entry for continue_open().
	 *
	 * @param string $source   Absolute path.
	 * @param string $name     Entry name.
	 * @param float  $deadline microtime(true) at which a stored entry should pause.
	 * @param int    $max_bytes Maximum stored bytes to copy in this call.
	 * @return bool True when the entry is complete.
	 */
	public function add_file( $source, $name, $deadline, $max_bytes = 33554432 ) {
		$this->assert_can_add( $name );
		clearstatcache( true, $source );
		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the source file is missing or unreadable. Recovery: make sure the file exists and is readable, then start a new export.', self::printable( $name ) ) );
		}
		$size  = (int) filesize( $source );
		$mtime = (int) filemtime( $source );
		if ( $size > 0 && $size <= (int) $max_bytes && ! self::precompressed( $name ) && $this->deflate_fits( $size, $deadline ) ) {
			$this->deflate_file( $source, $name, $size, $mtime );
			return true;
		}
		$entry           = $this->begin_entry( $name, $size, $mtime, 0 );
		$entry['source'] = (string) $source;
		$entry['mtime']  = $mtime;
		$entry['done']   = 0;
		$entry['crc']    = 0;
		$entry['chunks'] = array();
		$this->state['open'] = $entry;
		return $this->continue_open( $deadline, $max_bytes );
	}

	/**
	 * Copy more of the open stored entry. Pauses only on a hash-chunk boundary.
	 *
	 * @param float $deadline  microtime(true).
	 * @param int   $max_bytes Maximum bytes in this call (rounded up to one chunk).
	 * @return bool True when the entry is complete.
	 */
	public function continue_open( $deadline, $max_bytes = 33554432 ) {
		$entry = $this->state['open'];
		if ( ! $entry ) {
			return true;
		}
		$source = (string) $entry['source'];
		clearstatcache( true, $source );
		if ( ! is_file( $source ) || (int) filesize( $source ) !== (int) $entry['usize'] || (int) filemtime( $source ) !== (int) $entry['mtime'] ) {
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the file changed or disappeared while it was being added. Recovery: start a new export when the site is not being edited.', self::printable( $entry['name'] ) ) );
		}
		$in = @fopen( $source, 'rb' );
		if ( ! $in ) {
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the source file could not be opened. Recovery: make sure the file is readable, then start a new export.', self::printable( $entry['name'] ) ) );
		}
		$done  = (int) $entry['done'];
		$size  = (int) $entry['usize'];
		$chunk = (int) $this->state['hash_chunk'];
		if ( $done > 0 && 0 !== fseek( $in, $done ) ) {
			fclose( $in );
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: seeking in the source file failed. Recovery: start a new export.', self::printable( $entry['name'] ) ) );
		}
		$piece_crc = hash_init( 'crc32b' );
		$piece_len = 0;
		$budget    = max( $chunk, (int) $max_bytes );
		while ( $done < $size ) {
			if ( $piece_len > 0 && 0 === $done % $chunk && ( $piece_len >= $budget || microtime( true ) >= $deadline ) ) {
				break;
			}
			$sha  = hash_init( 'sha256' );
			$left = min( $chunk, $size - $done );
			while ( $left > 0 ) {
				$buffer = fread( $in, min( self::READ_BYTES, $left ) );
				if ( false === $buffer || '' === $buffer ) {
					fclose( $in );
					throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: reading the source file stopped early. Recovery: start a new export.', self::printable( $entry['name'] ) ) );
				}
				$this->write( $buffer );
				hash_update( $piece_crc, $buffer );
				hash_update( $sha, $buffer );
				$len        = strlen( $buffer );
				$left      -= $len;
				$done      += $len;
				$piece_len += $len;
			}
			$entry['chunks'][] = hash_final( $sha );
		}
		fclose( $in );
		$entry['crc']  = self::crc32_combine( (int) $entry['crc'], (int) hexdec( hash_final( $piece_crc ) ), $piece_len );
		$entry['done'] = $done;
		self::fire( 'after_data' );
		if ( $done < $size ) {
			$this->state['open'] = $entry;
			return false;
		}
		$entry['csize'] = $size;
		$entry['sha']   = hash( 'sha256', implode( '', array_map( 'hex2bin', $entry['chunks'] ) ) );
		if ( 0 === $size ) {
			$entry['sha'] = hash( 'sha256', '' );
		}
		unset( $entry['source'], $entry['mtime'], $entry['done'], $entry['chunks'] );
		$this->state['open'] = null;
		$this->complete_entry( $entry );
		return true;
	}

	/**
	 * Write the central directory and end records. The writer is unusable afterwards.
	 *
	 * @return array{size:int,count:int,content_sha256:string}
	 */
	public function finish() {
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( 'Operation: finish the package. Reason: an entry is still being written. Recovery: start a new export.' );
		}
		$this->commit();
		$cd_start = (int) $this->state['offset'];
		fseek( $this->fh, $cd_start );
		fseek( $this->index_fh, 0 );
		$content = hash_init( 'sha256' );
		$count   = 0;
		$read    = 0;
		$limit   = (int) $this->state['index_bytes'];
		$force   = ! empty( $this->state['force_zip64'] );
		while ( $read < $limit ) {
			$line = fgets( $this->index_fh );
			if ( false === $line ) {
				break;
			}
			$read += strlen( $line );
			$e     = json_decode( $line, true );
			if ( ! is_array( $e ) || ! isset( $e['n'] ) ) {
				throw new \RuntimeException( 'Operation: finish the package. Reason: the entry index is damaged. Recovery: start a new export.' );
			}
			$name = hex2bin( $e['n'] );
			$this->write( $this->central_record( $e, $name, $force ) );
			hash_update( $content, $name . "\0" . (int) $e['us'] . "\0" . sprintf( '%08x', (int) $e['crc'] ) . "\0" . $e['sha'] . "\n" );
			$count++;
		}
		if ( $count !== (int) $this->state['count'] ) {
			throw new \RuntimeException( sprintf( 'Operation: finish the package. Reason: the entry index lists %1$d entries but %2$d were written. Recovery: start a new export.', $count, (int) $this->state['count'] ) );
		}
		$cd_end  = (int) ftell( $this->fh );
		$cd_size = $cd_end - $cd_start;
		$zip64   = $force || $count >= 0xFFFF || $cd_size >= self::MAX32 || $cd_start >= self::MAX32;
		if ( $zip64 ) {
			$this->write(
				pack( 'VP', 0x06064b50, 44 ) . pack( 'vv', 45, 45 ) . pack( 'VV', 0, 0 )
				. pack( 'PPPP', $count, $count, $cd_size, $cd_start )
			);
			$this->write( pack( 'VVPV', 0x07064b50, 0, $cd_end, 1 ) );
		}
		$this->write(
			pack( 'Vvv', 0x06054b50, 0, 0 )
			. pack( 'vv', $zip64 ? 0xFFFF : $count, $zip64 ? 0xFFFF : $count )
			. pack( 'VV', $zip64 ? self::MAX32 : $cd_size, $zip64 ? self::MAX32 : $cd_start )
			. pack( 'v', 0 )
		);
		$this->flush_handle( $this->fh );
		$result = array(
			'size'           => $this->tell_end( $this->fh ),
			'count'          => $count,
			'files_count'    => (int) $this->state['files_count'],
			'files_bytes'    => (int) $this->state['files_bytes'],
			'content_sha256' => hash_final( $content ),
		);
		$this->state['finished'] = true;
		$this->state['result']   = $result;
		$this->commit();
		$this->close();
		return $result;
	}

	/**
	 * Remove the sidecar and index. The archive itself is left to the caller.
	 */
	public function discard_sidecars() {
		$this->close();
		@unlink( $this->state_path );
		@unlink( $this->state_path . '.tmp' );
		@unlink( $this->index_path );
	}

	/* ------------------------------------------------------------------ */

	private function assert_can_add( $name ) {
		if ( ! empty( $this->state['finished'] ) ) {
			throw new \RuntimeException( 'Operation: add to the package. Reason: the package is already finished. Recovery: start a new export.' );
		}
		if ( $this->has_open_entry() ) {
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the previous entry is not finished. Recovery: start a new export.', self::printable( $name ) ) );
		}
		if ( '' === (string) $name || strlen( (string) $name ) > 0xFFFF ) {
			throw new \RuntimeException( 'Operation: add to the package. Reason: an entry name is empty or too long. Recovery: start a new export.' );
		}
	}

	private function deflate_fits( $size, $deadline ) {
		if ( $size > self::DEFLATE_MAX || ! function_exists( 'deflate_init' ) ) {
			return false;
		}
		$rate = $this->deflate_rate > 0 ? $this->deflate_rate : self::DEFLATE_RATE;
		$left = (float) $deadline - microtime( true );
		// Deflating must finish in this request; allow for a slower disk than measured.
		return $size <= $rate * $left * 0.5;
	}

	private function deflate_file( $source, $name, $size, $mtime ) {
		$started = microtime( true );
		$entry   = $this->begin_entry( $name, $size, $mtime, 8 );
		$in      = @fopen( $source, 'rb' );
		$ctx     = deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
		if ( ! $in || false === $ctx ) {
			if ( $in ) {
				fclose( $in );
			}
			throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the file or the compressor could not be opened. Recovery: make sure the file is readable, then start a new export.', self::printable( $name ) ) );
		}
		$crc    = hash_init( 'crc32b' );
		$chunk  = (int) $this->state['hash_chunk'];
		$sha    = hash_init( 'sha256' );
		$in_sha = 0;
		$hashes = '';
		$read   = 0;
		$csize  = 0;
		while ( $read < $size ) {
			$buffer = fread( $in, (int) min( self::READ_BYTES, $size - $read, $chunk - $in_sha ) );
			if ( false === $buffer || '' === $buffer ) {
				fclose( $in );
				throw new \RuntimeException( sprintf( 'Operation: add %s to the package. Reason: the file became shorter while it was read. Recovery: start a new export when the site is not being edited.', self::printable( $name ) ) );
			}
			$len     = strlen( $buffer );
			$read   += $len;
			$in_sha += $len;
			hash_update( $crc, $buffer );
			hash_update( $sha, $buffer );
			if ( $in_sha === $chunk ) {
				$hashes .= hash_final( $sha, true );
				$sha     = hash_init( 'sha256' );
				$in_sha  = 0;
			}
			$out = deflate_add( $ctx, $buffer, ZLIB_NO_FLUSH );
			if ( false === $out ) {
				fclose( $in );
				throw new \RuntimeException( sprintf( 'Operation: compress %s. Reason: zlib failed. Recovery: start a new export.', self::printable( $name ) ) );
			}
			if ( '' !== $out ) {
				$this->write( $out );
				$csize += strlen( $out );
			}
		}
		fclose( $in );
		if ( $in_sha > 0 ) {
			$hashes .= hash_final( $sha, true );
		}
		$tail = deflate_add( $ctx, '', ZLIB_FINISH );
		if ( false === $tail ) {
			throw new \RuntimeException( sprintf( 'Operation: compress %s. Reason: zlib failed. Recovery: start a new export.', self::printable( $name ) ) );
		}
		$this->write( $tail );
		$csize += strlen( $tail );
		$entry['crc']   = (int) hexdec( hash_final( $crc ) );
		$entry['csize'] = $csize;
		$entry['sha']   = hash( 'sha256', $hashes );
		$this->complete_entry( $entry );
		$seconds = microtime( true ) - $started;
		if ( $seconds > 0.05 ) {
			$this->deflate_rate = $size / $seconds;
		}
	}

	private function begin_entry( $name, $size, $mtime, $method ) {
		$name   = (string) $name;
		$offset = $this->tell_end( $this->fh );
		$zip64  = ! empty( $this->state['force_zip64'] ) || $size >= self::MAX32;
		$dos    = self::dos_time( $mtime );
		$flags  = preg_match( '//u', $name ) && preg_match( '/[\x80-\xFF]/', $name ) ? 0x0800 : 0;
		$extra  = $zip64 ? pack( 'vvPP', 0x0001, 16, 0, 0 ) : '';
		$this->write(
			pack( 'Vvvv', 0x04034b50, $zip64 ? 45 : 20, $flags, $method )
			. pack( 'vv', $dos['time'], $dos['date'] )
			. pack( 'VVV', 0, $zip64 ? self::MAX32 : 0, $zip64 ? self::MAX32 : 0 )
			. pack( 'vv', strlen( $name ), strlen( $extra ) )
			. $name . $extra
		);
		return array(
			'name'   => $name,
			'offset' => $offset,
			'method' => (int) $method,
			'flags'  => $flags,
			'time'   => $dos['time'],
			'date'   => $dos['date'],
			'usize'  => (int) $size,
			'zip64'  => $zip64,
		);
	}

	private function complete_entry( array $entry ) {
		$end      = $this->tell_end( $this->fh );
		$name_len = strlen( $entry['name'] );
		fseek( $this->fh, (int) $entry['offset'] + 14 );
		if ( $entry['zip64'] ) {
			$this->write( pack( 'VVV', (int) $entry['crc'], self::MAX32, self::MAX32 ) );
			fseek( $this->fh, (int) $entry['offset'] + 30 + $name_len + 4 );
			$this->write( pack( 'PP', (int) $entry['usize'], (int) $entry['csize'] ) );
		} else {
			$this->write( pack( 'VVV', (int) $entry['crc'], (int) $entry['csize'], (int) $entry['usize'] ) );
		}
		fseek( $this->fh, $end );
		self::fire( 'after_patch' );
		$line = wp_json_encode(
			array(
				'n'   => bin2hex( $entry['name'] ),
				'm'   => (int) $entry['method'],
				'f'   => (int) $entry['flags'],
				't'   => (int) $entry['time'],
				'd'   => (int) $entry['date'],
				'crc' => (int) $entry['crc'],
				'cs'  => (int) $entry['csize'],
				'us'  => (int) $entry['usize'],
				'off' => (int) $entry['offset'],
				'z'   => (bool) $entry['zip64'],
				'sha' => (string) $entry['sha'],
			)
		) . "\n";
		fseek( $this->index_fh, 0, SEEK_END );
		if ( false === fwrite( $this->index_fh, $line ) ) {
			throw new \RuntimeException( 'Operation: write the package index. Reason: disk full or permission denied. Recovery: free disk space, then start a new export.' );
		}
		$this->state['count']++;
		if ( 0 === strpos( $entry['name'], 'files/' ) && '/' !== substr( $entry['name'], -1 ) ) {
			$this->state['files_count']++;
			$this->state['files_bytes'] += (int) $entry['usize'];
		}
	}

	private function central_record( array $e, $name, $force ) {
		$us    = (int) $e['us'];
		$cs    = (int) $e['cs'];
		$off   = (int) $e['off'];
		$zip64 = $force || ! empty( $e['z'] ) || $us >= self::MAX32 || $cs >= self::MAX32 || $off >= self::MAX32;
		$extra = $zip64 ? pack( 'vvPPP', 0x0001, 24, $us, $cs, $off ) : '';
		$ver   = $zip64 ? 45 : 20;
		return pack( 'Vvvvv', 0x02014b50, $ver, $ver, (int) $e['f'], (int) $e['m'] )
			. pack( 'vv', (int) $e['t'], (int) $e['d'] )
			. pack( 'VVV', (int) $e['crc'], $zip64 ? self::MAX32 : $cs, $zip64 ? self::MAX32 : $us )
			. pack( 'vvvvv', strlen( $name ), strlen( $extra ), 0, 0, 0 )
			. pack( 'VV', 0, $zip64 ? self::MAX32 : $off )
			. $name . $extra;
	}

	private function write( $bytes ) {
		$bytes = (string) $bytes;
		if ( '' === $bytes ) {
			return;
		}
		$wrote = fwrite( $this->fh, $bytes );
		if ( false === $wrote || $wrote !== strlen( $bytes ) ) {
			throw new \RuntimeException( sprintf( 'Operation: write the package. Reason: only %1$d of %2$d bytes were written (disk full or quota reached). Recovery: free disk space, then start a new export.', (int) $wrote, strlen( $bytes ) ) );
		}
	}

	private static function fire( $point ) {
		if ( is_callable( self::$fault ) ) {
			call_user_func( self::$fault, $point );
		}
	}

	/**
	 * Entry digest used in the content checksum: SHA-256 over the raw SHA-256 of every
	 * $chunk-sized piece of the contents (the last piece may be shorter; an empty entry has none).
	 *
	 * @param string $data  Contents.
	 * @param int    $chunk Chunk size.
	 * @return string Raw concatenated chunk hashes.
	 */
	public static function chunk_hashes_of( $data, $chunk ) {
		$out = '';
		$len = strlen( $data );
		for ( $i = 0; $i < $len; $i += $chunk ) {
			$out .= hash( 'sha256', substr( $data, $i, $chunk ), true );
		}
		return $out;
	}

	/**
	 * Same digest as chunk_hashes_of(), streamed from a file.
	 *
	 * @param string $path  File.
	 * @param int    $chunk Chunk size.
	 * @return string Hex digest.
	 */
	public static function file_digest( $path, $chunk = self::HASH_CHUNK ) {
		$in     = fopen( $path, 'rb' );
		$hashes = '';
		while ( $in && ! feof( $in ) ) {
			$sha  = hash_init( 'sha256' );
			$got  = 0;
			while ( $got < $chunk && ! feof( $in ) ) {
				$buffer = fread( $in, (int) min( self::READ_BYTES, $chunk - $got ) );
				if ( false === $buffer || '' === $buffer ) {
					break;
				}
				hash_update( $sha, $buffer );
				$got += strlen( $buffer );
			}
			if ( $got > 0 ) {
				$hashes .= hash_final( $sha, true );
			}
		}
		if ( $in ) {
			fclose( $in );
		}
		return hash( 'sha256', $hashes );
	}

	/**
	 * CRC-32 of A followed by B, from crc(A), crc(B) and len(B). Port of zlib crc32_combine().
	 *
	 * @param int $crc1 CRC of the first part.
	 * @param int $crc2 CRC of the second part.
	 * @param int $len2 Length of the second part.
	 * @return int
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		$crc1 = (int) $crc1 & 0xFFFFFFFF;
		$crc2 = (int) $crc2 & 0xFFFFFFFF;
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		$odd    = array( 0xEDB88320 );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::gf2_square( $odd );
		$odd  = self::gf2_square( $even );
		do {
			$even = self::gf2_square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::gf2_square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
	}

	private static function gf2_times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			$i++;
		}
		return $sum & 0xFFFFFFFF;
	}

	private static function gf2_square( array $mat ) {
		$out = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$out[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
		return $out;
	}

	private static function dos_time( $ts ) {
		$ts = (int) $ts;
		if ( $ts < 315532800 ) {
			$ts = 315532800;
		}
		$d = getdate( $ts );
		return array(
			'time' => ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ),
			'date' => ( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'],
		);
	}

	/**
	 * Types that are already compressed; deflating them wastes CPU for almost no gain.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	public static function precompressed( $name ) {
		$ext = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'm4a', 'ogg', 'oga', 'flac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'woff', 'woff2', 'pdf', 'ico', 'jisento' ), true );
	}

	private static function printable( $text ) {
		$text = (string) $text;
		return 1 === preg_match( '//u', $text ) ? $text : 'hex:' . bin2hex( $text );
	}
}
