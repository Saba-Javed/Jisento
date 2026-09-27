<?php
/**
 * Phase 2.2: streaming ZIP writer, resumable package build and resumable large-file restore.
 *
 * Run: php tests/streaming-zip-test.php
 * 7-Zip and unzip checks print SKIP when the tool is not installed.
 */

$root = sys_get_temp_dir() . '/jisento-zip-' . getmypid();
@mkdir( $root, 0777, true );
define( 'WP_CONTENT_DIR', $root . '/wp-content' );
require __DIR__ . '/bootstrap.php';

set_error_handler(
	static function ( $no, $message, $file, $line ) {
		check( 'no PHP warning or notice', false, $message . ' at ' . basename( $file ) . ':' . $line );
		return true;
	}
);

use Jisento\Migration\Export\Exporter;
use Jisento\Migration\Package\Archive;
use Jisento\Migration\Package\Streaming_Zip_Writer;
use Jisento\Migration\Plugin;

function zt_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		is_dir( $path ) ? zt_rmtree( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}

register_shutdown_function(
	static function () use ( $root ) {
		zt_rmtree( $root );
	}
);

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo() {
		return '6.6';
	}
}

function zt_bytes( $n, $seed ) {
	mt_srand( $seed );
	$out = '';
	while ( strlen( $out ) < $n ) {
		$out .= pack( 'N', mt_rand() );
	}
	return substr( $out, 0, $n );
}

/**
 * Open with ZipArchive (consistency check) and compare every entry's bytes and CRC-32.
 *
 * @param string $zip_path Archive.
 * @param array  $expected name => bytes.
 * @return string '' when everything matches, otherwise the first problem.
 */
function zt_verify_zip( $zip_path, array $expected ) {
	$zip = new ZipArchive();
	$rc  = $zip->open( $zip_path, ZipArchive::CHECKCONS );
	if ( true !== $rc ) {
		return 'ZipArchive::open failed with code ' . $rc;
	}
	if ( $zip->numFiles !== count( $expected ) ) {
		$n = $zip->numFiles;
		$zip->close();
		return 'entry count ' . $n . ' != ' . count( $expected );
	}
	foreach ( $expected as $name => $bytes ) {
		$stat = $zip->statName( $name );
		if ( false === $stat ) {
			$zip->close();
			return 'missing ' . $name;
		}
		$data = $zip->getFromName( $name );
		if ( $data !== $bytes ) {
			$zip->close();
			return 'bytes differ for ' . $name;
		}
		if ( ( (int) $stat['crc'] & 0xFFFFFFFF ) !== (int) hexdec( hash( 'crc32b', $bytes ) ) ) {
			$zip->close();
			return 'CRC differs for ' . $name;
		}
		if ( (int) $stat['size'] !== strlen( $bytes ) ) {
			$zip->close();
			return 'size differs for ' . $name;
		}
	}
	$zip->close();
	return '';
}

function zt_data_offset( $zip_path, $name ) {
	return method_exists( Archive::class, 'data_offset' ) ? Archive::data_offset( $zip_path, $name ) : -1;
}

function zt_content_sha( array $entries, $chunk ) {
	$ctx = hash_init( 'sha256' );
	foreach ( $entries as $name => $bytes ) {
		$digest = hash( 'sha256', Streaming_Zip_Writer::chunk_hashes_of( $bytes, $chunk ) );
		hash_update( $ctx, $name . "\0" . strlen( $bytes ) . "\0" . hash( 'crc32b', $bytes ) . "\0" . $digest . "\n" );
	}
	return hash_final( $ctx );
}

function zt_find_tool( array $candidates ) {
	foreach ( $candidates as $candidate ) {
		if ( false !== strpos( $candidate, '/' ) || false !== strpos( $candidate, '\\' ) ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
			continue;
		}
		$which = stripos( PHP_OS, 'WIN' ) === 0 ? 'where ' . $candidate . ' 2>NUL' : 'command -v ' . $candidate . ' 2>/dev/null';
		$found = trim( (string) shell_exec( $which ) );
		if ( '' !== $found ) {
			$lines = preg_split( '/\r?\n/', $found );
			return $lines[0];
		}
	}
	return '';
}

function zt_external_checks( $zip_path, $label ) {
	$seven = zt_find_tool( array( '7z', '7za', 'C:/Program Files/7-Zip/7z.exe', 'C:/Program Files (x86)/7-Zip/7z.exe' ) );
	if ( '' === $seven ) {
		echo "SKIP 7-Zip test of $label: 7-Zip is not installed\n";
	} else {
		exec( '"' . $seven . '" t "' . $zip_path . '" 2>&1', $out, $rc );
		$text = implode( "\n", $out );
		check( "7-Zip tests $label without errors", 0 === $rc && false !== stripos( $text, 'Everything is Ok' ), $text );
	}
	$unzip = zt_find_tool( array( 'unzip' ) );
	if ( '' === $unzip ) {
		echo "SKIP unzip test of $label: unzip is not installed\n";
	} else {
		exec( '"' . $unzip . '" -t "' . $zip_path . '" 2>&1', $out2, $rc2 );
		$text2 = implode( "\n", $out2 );
		check( "unzip -t $label without errors", 0 === $rc2 && false !== stripos( $text2, 'No errors detected' ), $text2 );
	}
}

/* ---------------------------------------------------------------- crc32_combine */

$a = zt_bytes( 100003, 1 );
$b = zt_bytes( 77777, 2 );
$combined = Streaming_Zip_Writer::crc32_combine( (int) hexdec( hash( 'crc32b', $a ) ), (int) hexdec( hash( 'crc32b', $b ) ), strlen( $b ) );
check( 'crc32_combine(A, B) equals crc32(A.B)', (int) hexdec( hash( 'crc32b', $a . $b ) ) === $combined );
$crc = 0;
$all = '';
foreach ( array( 1, 4096, 65536, 3, 0, 250000 ) as $i => $len ) {
	$piece = zt_bytes( $len, 10 + $i );
	$crc   = Streaming_Zip_Writer::crc32_combine( $crc, (int) hexdec( hash( 'crc32b', $piece ) ), strlen( $piece ) );
	$all  .= $piece;
}
check( 'crc32_combine chained over 6 uneven pieces', (int) hexdec( hash( 'crc32b', $all ) ) === $crc );

/* ---------------------------------------------------------------- spanning STORE entry, kill, resume */

$chunk   = 65536;
$src_dir = $root . '/src';
@mkdir( $src_dir, 0777, true );
$video   = zt_bytes( 5 * $chunk + 1234, 3 );
file_put_contents( $src_dir . '/video.mp4', $video );
$text    = str_repeat( "Compressible line of text for the deflate path.\n", 4000 );
file_put_contents( $src_dir . '/notes.txt', $text );
file_put_contents( $src_dir . '/empty.txt', '' );

$zip_path = $root . '/span.jisento.partial';
$state    = $root . '/span-writer.json';
$far      = microtime( true ) + 60;

$w = Streaming_Zip_Writer::create( $zip_path, $state, array( 'hash_chunk' => $chunk ) );
$w->add_string( 'JISENTO', 'marker' );
$complete = $w->add_file( $src_dir . '/video.mp4', 'files/video.mp4', $far, $chunk );
$w->commit();
$w->close();
$requests = 1;
check( 'stored entry is still open after request 1', ! $complete );

// Request 2 copies one more chunk.
$w = Streaming_Zip_Writer::resume( $zip_path, $state );
$w->continue_open( $far, $chunk );
$w->commit();
$w->close();
$requests++;
$committed = filesize( $zip_path );

// Request 3 is killed after writing data but before commit().
Streaming_Zip_Writer::$fault = function ( $point ) {
	if ( 'after_data' === $point ) {
		throw new RuntimeException( 'simulated kill' );
	}
};
$killed = false;
$w      = Streaming_Zip_Writer::resume( $zip_path, $state );
try {
	$w->continue_open( $far, 2 * $chunk );
} catch ( RuntimeException $e ) {
	$killed = true;
}
$w->close();
$requests++;
Streaming_Zip_Writer::$fault = null;
clearstatcache();
check( 'kill mid-entry left uncommitted bytes in the .partial', $killed && filesize( $zip_path ) > $committed );
// A torn write from the dead request.
file_put_contents( $zip_path, 'GARBAGE', FILE_APPEND );

$w = Streaming_Zip_Writer::resume( $zip_path, $state );
clearstatcache();
check( 'resume truncates the .partial to the committed offset first', filesize( $zip_path ) === $committed, filesize( $zip_path ) . ' vs ' . $committed );
while ( ! $w->continue_open( $far, $chunk ) ) {
	$w->commit();
	$w->close();
	$requests++;
	$w = Streaming_Zip_Writer::resume( $zip_path, $state );
}
$requests++;
check( 'stored entry spanned at least 3 requests', $requests >= 3, (string) $requests );
$w->add_file( $src_dir . '/notes.txt', 'files/notes.txt', $far, 1 << 20 );
$w->add_file( $src_dir . '/empty.txt', 'files/empty.txt', $far, 1 << 20 );
$w->commit();
$w->close();

// Kill right after a header was patched: the patched entry is past the committed length and is redone.
$w = Streaming_Zip_Writer::resume( $zip_path, $state );
Streaming_Zip_Writer::$fault = function ( $point ) {
	if ( 'after_patch' === $point ) {
		throw new RuntimeException( 'simulated kill after patch' );
	}
};
try {
	$w->add_string( 'files/late.txt', 'late entry' );
} catch ( RuntimeException $e ) {
	$w->close();
}
Streaming_Zip_Writer::$fault = null;
$w = Streaming_Zip_Writer::resume( $zip_path, $state );
$w->add_string( 'files/late.txt', 'late entry' );
$w->add_string( 'manifest.json', '{"ok":true}' );
$totals = $w->totals();
check( 'files counters exclude the marker and manifest', 4 === $totals['files_count'] && strlen( $video . $text . 'late entry' ) === $totals['files_bytes'], json_encode( $totals ) );
$result = $w->finish();

$expected = array(
	'JISENTO'         => 'marker',
	'files/video.mp4' => $video,
	'files/notes.txt' => $text,
	'files/empty.txt' => '',
	'files/late.txt'  => 'late entry',
	'manifest.json'   => '{"ok":true}',
);
clearstatcache();
check( 'finish() reports the archive size', filesize( $zip_path ) === $result['size'] );
$problem = zt_verify_zip( $zip_path, $expected );
check( 'ZipArchive (CHECKCONS) reads every entry with matching bytes and CRC-32', '' === $problem, $problem );
check( 'content_sha256 matches an independent recomputation', zt_content_sha( $expected, $chunk ) === $result['content_sha256'] );
check( 'finished_result() returns the same result for a repeated finalize', Streaming_Zip_Writer::finished_result( $state ) == $result );
$zip = new ZipArchive();
$zip->open( $zip_path );
$video_stat = $zip->statName( 'files/video.mp4' );
$notes_stat = $zip->statName( 'files/notes.txt' );
$zip->close();
check( 'precompressed type (.mp4) is stored', 0 === (int) $video_stat['comp_method'] );
check( 'small text file is deflated', 8 === (int) $notes_stat['comp_method'] && $notes_stat['comp_size'] < $notes_stat['size'] );
$raw = file_get_contents( $zip_path );
check( 'no data descriptors (general purpose bit 3 never set)', false === strpos( $raw, "PK\x07\x08" ) );
check( 'not ZIP64 when not needed', false === strpos( $raw, "PK\x06\x06" ) );
check( 'Archive::data_offset locates stored data', substr( $raw, zt_data_offset( $zip_path, 'files/video.mp4' ), 64 ) === substr( $video, 0, 64 ) );
zt_external_checks( $zip_path, 'spanning archive' );

/* ---------------------------------------------------------------- big file larger than the byte budget is stored */

$big_text = str_repeat( "Text that would compress very well.\n", 60000 );
file_put_contents( $src_dir . '/big.txt', $big_text );
$bz = $root . '/budget.zip';
$w  = Streaming_Zip_Writer::create( $bz, $bz . '.json', array( 'hash_chunk' => $chunk ) );
$w->add_file( $src_dir . '/big.txt', 'files/big.txt', $far, 1048576 );
while ( $w->has_open_entry() ) {
	$w->continue_open( $far, 1048576 );
}
$w->finish();
$zip = new ZipArchive();
$zip->open( $bz );
$big_stat = $zip->statName( 'files/big.txt' );
$zip->close();
check( 'a compressible file larger than the per-step budget is stored, not deflated', 0 === (int) $big_stat['comp_method'] && strlen( $big_text ) > 1048576 );
check( 'budget archive reads back', '' === zt_verify_zip( $bz, array( 'files/big.txt' => $big_text ) ) );

/* ---------------------------------------------------------------- forced ZIP64 */

$z64 = $root . '/zip64.zip';
$w   = Streaming_Zip_Writer::create( $z64, $z64 . '.json', array( 'force_zip64' => true, 'hash_chunk' => $chunk ) );
$w->add_string( 'JISENTO', 'marker' );
$w->add_file( $src_dir . '/video.mp4', 'files/video.mp4', $far, 2 * $chunk );
$w->commit();
$w->close();
$w = Streaming_Zip_Writer::resume( $z64, $z64 . '.json' );
while ( ! $w->continue_open( $far, 2 * $chunk ) ) {
	$w->commit();
}
$w->add_file( $src_dir . '/notes.txt', 'files/notes.txt', $far, 1 << 20 );
$w->add_string( 'files/ünïcode-名前.txt', 'utf8 name' );
$r64 = $w->finish();
$raw = file_get_contents( $z64 );
check( 'forced ZIP64 writes the ZIP64 end record and locator', false !== strpos( $raw, "PK\x06\x06" ) && false !== strpos( $raw, "PK\x06\x07" ) );
$eocd = unpack( 'vcount/vtotal/Vsize/Voffset', substr( $raw, -22 + 8, 12 ) );
check( 'forced ZIP64 EOCD fields are 0xFFFF/0xFFFFFFFF', 0xFFFF === $eocd['total'] && 0xFFFFFFFF === $eocd['offset'] );
$expected64 = array(
	'JISENTO'                => 'marker',
	'files/video.mp4'        => $video,
	'files/notes.txt'        => $text,
	'files/ünïcode-名前.txt' => 'utf8 name',
);
$problem = zt_verify_zip( $z64, $expected64 );
check( 'ZipArchive reads the forced ZIP64 archive with matching bytes and CRC-32', '' === $problem, $problem );
check( 'Archive::data_offset follows ZIP64 extra fields', substr( $raw, zt_data_offset( $z64, 'files/video.mp4' ), 64 ) === substr( $video, 0, 64 ) );
zt_external_checks( $z64, 'forced ZIP64 archive' );

/* ---------------------------------------------------------------- resumable restore of one large file */

$archive = new Archive();
$dest    = $root . '/restore';
@mkdir( $dest, 0777, true );
$big     = zt_bytes( 3 * 1048576 + 777, 4 );
file_put_contents( $src_dir . '/movie.mp4', $big );
$rz = $root . '/restore.zip';
$w  = Streaming_Zip_Writer::create( $rz, $rz . '.json', array( 'hash_chunk' => $chunk ) );
$w->add_string( 'JISENTO', 'marker' );
$w->add_string( 'files/a.txt', 'small a' );
$w->add_file( $src_dir . '/movie.mp4', 'files/movie.mp4', $far, 64 << 20 );
while ( $w->has_open_entry() ) {
	$w->continue_open( $far, 64 << 20 );
}
$w->add_string( 'files/z.txt', 'small z' );
$w->finish();

$mapper = function ( $rel ) use ( $dest ) {
	return $dest . '/' . $rel;
};
$index   = 0;
$partial = null;
$calls   = 0;
$pieces  = 0;
$max     = 0;
do {
	$batch = $archive->extract_files_batch( $rz, $index, 400, 30, $mapper, $partial, 1048576 );
	if ( is_wp_error( $batch ) ) {
		break;
	}
	$calls++;
	$max     = max( $max, $batch['bytes'] );
	$index   = $batch['next'];
	$partial = $batch['partial'];
	if ( $partial ) {
		$pieces++;
		check( 'destination is not in place while the file is partial', ! is_file( $dest . '/movie.mp4' ) || 0 !== strcmp( md5_file( $dest . '/movie.mp4' ), md5( $big ) ) );
		if ( 2 === $pieces ) {
			// A killed request wrote past the saved offset: the next call must cut it back.
			file_put_contents( $partial['tmp'], 'TORN', FILE_APPEND );
		}
	}
} while ( ! $batch['done'] && $calls < 50 );
check( 'large file restore returned no error', ! is_wp_error( $batch ), is_wp_error( $batch ) ? $batch->get_error_message() : '' );
check( 'large stored file was restored across at least 3 calls', $pieces >= 3, (string) $pieces );
check( 'no call wrote more than the byte budget', $max <= 1048576, (string) $max );
check( 'restored large file is byte-identical', is_file( $dest . '/movie.mp4' ) && md5_file( $dest . '/movie.mp4' ) === md5( $big ) );
check( 'small files around it were restored', 'small a' === @file_get_contents( $dest . '/a.txt' ) && 'small z' === @file_get_contents( $dest . '/z.txt' ) );
check( 'no .jisento-tmp is left behind', ! glob( $dest . '/*.jisento-tmp' ) );

// Missing temporary file on resume: the entry restarts from zero and is still correct.
zt_rmtree( $dest );
@mkdir( $dest, 0777, true );
$first = $archive->extract_files_batch( $rz, 0, 400, 30, $mapper, null, 1048576 );
@unlink( $first['partial']['tmp'] );
$index   = $first['next'];
$partial = $first['partial'];
$calls   = 0;
do {
	$batch   = $archive->extract_files_batch( $rz, $index, 400, 30, $mapper, $partial, 1048576 );
	$index   = $batch['next'];
	$partial = $batch['partial'];
	$calls++;
} while ( ! is_wp_error( $batch ) && ! $batch['done'] && $calls < 50 );
check( 'lost temporary file restarts the entry and the result is still correct', is_file( $dest . '/movie.mp4' ) && md5_file( $dest . '/movie.mp4' ) === md5( $big ) );

// Damaged stored data fails the CRC check instead of producing a wrong file.
$bad = $root . '/bad.zip';
copy( $rz, $bad );
$at  = strpos( file_get_contents( $bad ), substr( $big, 0, 64 ) ) + 2000000;
$fh  = fopen( $bad, 'r+b' );
fseek( $fh, $at );
fwrite( $fh, 'XXXX' );
fclose( $fh );
zt_rmtree( $dest );
@mkdir( $dest, 0777, true );
$index   = 0;
$partial = null;
$calls   = 0;
do {
	$batch = $archive->extract_files_batch( $bad, $index, 400, 30, $mapper, $partial, 1048576 );
	if ( is_wp_error( $batch ) ) {
		break;
	}
	$index   = $batch['next'];
	$partial = $batch['partial'];
	$calls++;
} while ( ! $batch['done'] && $calls < 50 );
check( 'damaged large entry fails its CRC check', is_wp_error( $batch ) && 'jisento_entry_crc' === $batch->get_error_code() );
check( 'damaged large entry is not put in place', ! is_file( $dest . '/movie.mp4' ) && ! glob( $dest . '/*.jisento-tmp' ) );

// Legacy package: a large deflated entry is also restored in bounded pieces.
$legacy_text = str_repeat( zt_bytes( 700, 5 ) . "\n", 4000 );
$lz          = $root . '/legacy.zip';
$zip         = new ZipArchive();
$zip->open( $lz, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'files/big.log', $legacy_text );
$zip->close();
zt_rmtree( $dest );
@mkdir( $dest, 0777, true );
$index   = 0;
$partial = null;
$calls   = 0;
$max     = 0;
do {
	$batch = $archive->extract_files_batch( $lz, $index, 400, 30, $mapper, $partial, 1048576 );
	if ( is_wp_error( $batch ) ) {
		break;
	}
	$max     = max( $max, $batch['bytes'] );
	$index   = $batch['next'];
	$partial = $batch['partial'];
	$calls++;
} while ( ! $batch['done'] && $calls < 50 );
check( 'legacy deflated large entry restored in bounded pieces', ! is_wp_error( $batch ) && $calls >= 3 && $max <= 1048576 && md5_file( $dest . '/big.log' ) === md5( $legacy_text ), $calls . ' calls, max ' . $max );

/* ---------------------------------------------------------------- Exporter: package built in place, killed, resumed */

class ZT_Jobs {
	public $saves = 0;
	public function update( $job, array $fields ) {
		$this->saves++;
		$copy = clone $job;
		foreach ( $fields as $k => $v ) {
			$copy->$k = $v;
		}
		return $copy;
	}
}
class ZT_Logger {
	public function log() {}
}
$GLOBALS['wpdb'] = (object) array(
	'prefix'  => 'wp_',
	'charset' => 'utf8mb4',
	'collate' => '',
);

$plugin          = Plugin::instance();
$plugin->storage = new \Jisento\Migration\Storage\Local_Storage();
$plugin->jobs    = new ZT_Jobs();
$plugin->logger  = new ZT_Logger();

$site = $root . '/site/wp-content/uploads';
@mkdir( $site, 0777, true );
$files = array(
	'wp-content/uploads/clip.mp4'  => zt_bytes( 3 * 1048576 + 99, 6 ),
	'wp-content/uploads/page.html' => str_repeat( '<p>hello</p>', 5000 ),
	'wp-content/uploads/empty.css' => '',
	'wp-content/uploads/data.json' => json_encode( range( 1, 5000 ) ),
);
$job_id = 'jisento_mig_ziptest';
$tmp    = $plugin->storage->tmp_dir( $job_id );
$list   = $tmp . '/file-list.json';
$lines  = '';
$size   = 0;
foreach ( $files as $rel => $bytes ) {
	$path = $root . '/site/' . $rel;
	file_put_contents( $path, $bytes );
	$lines .= json_encode( array( 'source' => $path, 'relative' => $rel, 'size' => strlen( $bytes ) ) ) . "\n";
	$size  += strlen( $bytes );
}
file_put_contents( $list, $lines );

$job = (object) array(
	'job_id'     => $job_id,
	'type'       => 'export',
	'status'     => 'running',
	'stage'      => 'packaging',
	'created_at' => gmdate( 'Y-m-d H:i:s' ),
	'state'      => array(
		'options'    => array( 'mode' => 'files' ),
		'file_count' => count( $files ),
		'files_size' => $size,
		'file_list'  => $list,
		'tables'     => array(),
	),
);

Exporter::$package_bytes = 1048576;
Exporter::$zip_options   = array( 'hash_chunk' => $chunk );
$exporter = new Exporter();
$method   = new ReflectionMethod( Exporter::class, 'package' );
$method->setAccessible( true );

$steps       = 0;
$kills       = 0;
$data_events = 0;
Streaming_Zip_Writer::$fault = function ( $point ) use ( &$data_events, &$kills ) {
	if ( 'after_data' === $point ) {
		$data_events++;
		if ( 3 === $data_events ) {
			$kills++;
			throw new RuntimeException( 'simulated kill during packaging' );
		}
	}
};
$error = '';
while ( $steps < 60 && ( $job->state['finalize_step'] ?? '' ) !== 'confirm' ) {
	$steps++;
	try {
		$job = $method->invoke( $exporter, $job, $job->state );
	} catch ( RuntimeException $e ) {
		if ( false === strpos( $e->getMessage(), 'simulated kill' ) ) {
			$error = $e->getMessage();
			break;
		}
		// The job update never happened; the next request starts from the saved state.
	}
}
Streaming_Zip_Writer::$fault = null;
check( 'exporter reached the confirm step without an error', '' === $error && 'confirm' === ( $job->state['finalize_step'] ?? '' ), $error );
check( 'exporter survived a kill while packaging', 1 === $kills );

$packages = $plugin->storage->get_path( 'packages' );
$name     = $job->state['package_name'];
$final    = $packages . '/' . $name;
check( 'package was renamed from .partial to its final name', is_file( $final ) && ! is_file( $final . '.partial' ) );
check( 'no second copy of the package exists anywhere in the job temp folder', ! glob( $tmp . '/*.zip' ) && ! is_file( $tmp . '/package.zip' ) );
check( 'exporter source has no copy_chunk step', false === strpos( file_get_contents( JISENTO_PATH . 'includes/Export/Exporter.php' ), 'copy_chunk' ) );
check( 'Archive no longer has the ZipArchive add_batch writer', ! method_exists( Archive::class, 'add_batch' ) );

$expected_pkg = array( 'JISENTO' => Archive::marker_contents() );
foreach ( $files as $rel => $bytes ) {
	$expected_pkg[ 'files/' . $rel ] = $bytes;
}
$zip = new ZipArchive();
$zip->open( $final );
$manifest_json = $zip->getFromName( 'manifest.json' );
$clip_stat     = $zip->statName( 'files/wp-content/uploads/clip.mp4' );
$zip->close();
$expected_pkg['manifest.json'] = $manifest_json;
$problem = zt_verify_zip( $final, $expected_pkg );
check( 'real package opens in ZipArchive; every entry has matching bytes and CRC-32', '' === $problem, $problem );
$manifest = json_decode( (string) $manifest_json, true );
check( 'manifest counts come from the writer', count( $files ) === (int) $manifest['file_count'] && $size === (int) $manifest['files_bytes'] );
check( 'package passes Archive::verify_structure', ! is_wp_error( ( new Archive() )->verify_structure( $final ) ) );
check( 'content_sha256 is stored and matches a recomputation', zt_content_sha( $expected_pkg, $chunk ) === $job->state['package_content_sha256'] );
check( 'large upload was stored and spanned steps', 0 === (int) $clip_stat['comp_method'] && $steps >= 6, (string) $steps );
zt_external_checks( $final, 'real package built by the exporter' );

// With the step budget already spent, each step still adds one file and then stops, even when
// the files are empty and no bytes are counted.
$job2_id = 'jisento_mig_zipdeadline';
$tmp2    = $plugin->storage->tmp_dir( $job2_id );
$lines   = '';
for ( $i = 0; $i < 20; $i++ ) {
	$path = $root . '/site/wp-content/uploads/empty-' . $i . '.txt';
	file_put_contents( $path, '' );
	$lines .= json_encode( array( 'source' => $path, 'relative' => 'wp-content/uploads/empty-' . $i . '.txt', 'size' => 0 ) ) . "\n";
}
file_put_contents( $tmp2 . '/file-list.json', $lines );
$job2 = (object) array(
	'job_id'     => $job2_id,
	'type'       => 'export',
	'status'     => 'running',
	'stage'      => 'packaging',
	'created_at' => gmdate( 'Y-m-d H:i:s' ),
	'state'      => array(
		'options'    => array( 'mode' => 'files' ),
		'file_count' => 20,
		'files_size' => 0,
		'file_list'  => $tmp2 . '/file-list.json',
		'tables'     => array(),
	),
);
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true ) - 5;
\Jisento\Migration\Jobs\Step_Budget::begin( 1.0 );
$job2      = $method->invoke( $exporter, $job2, $job2->state );
$job2      = $method->invoke( $exporter, $job2, $job2->state );
$first_add = (int) $job2->state['zip_index'];
check( 'an exhausted step budget stops the file loop after one file', 1 === $first_add, (string) $first_add );
\Jisento\Migration\Jobs\Step_Budget::begin();

// A failed or cancelled export removes its .partial.
$cancel_job = (object) array(
	'job_id' => 'jisento_mig_zipcancel',
	'type'   => 'export',
	'status' => 'cancelled',
	'state'  => array( 'package_partial' => $packages . '/cancelled.jisento.partial' ),
);
file_put_contents( $packages . '/cancelled.jisento.partial', 'x' );
Exporter::cleanup( $cancel_job );
check( 'cancelled export removes its .partial', ! is_file( $packages . '/cancelled.jisento.partial' ) );

jisento_test_finish();
