<?php
/**
 * Reads and writes binary glTF files.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

defined( 'ABSPATH' ) || exit;

/**
 * GLB container: a 12-byte header, a JSON chunk and a binary chunk, each
 * chunk 4-byte aligned (JSON padded with spaces, binary with zeros).
 */
final class Glb {

	/**
	 * Writes a GLB from a JSON document and a binary buffer stored in a file.
	 *
	 * @param string $dest     GLB path to write.
	 * @param array  $json     glTF JSON document.
	 * @param string $bin_path File holding the binary buffer.
	 * @param int    $bin_size Buffer size in bytes.
	 */
	public static function write( string $dest, array $json, string $bin_path, int $bin_size ): bool {
		$text = (string) wp_json_encode( $json, JSON_UNESCAPED_SLASHES );
		$text = str_pad( $text, self::aligned( strlen( $text ) ), ' ' );
		$pad  = self::aligned( $bin_size ) - $bin_size;
		$size = 12 + 8 + strlen( $text ) + 8 + $bin_size + $pad;

		$out = Stream::open( $dest, 'wb' );
		$in  = Stream::open( $bin_path, 'rb' );
		if ( null === $out || null === $in ) {
			Stream::close( $out );
			Stream::close( $in );
			return false;
		}

		$ok = Stream::write( $out, 'glTF' . pack( 'VV', 2, $size ) )
			&& Stream::write( $out, pack( 'V', strlen( $text ) ) . 'JSON' . $text )
			&& Stream::write( $out, pack( 'V', $bin_size + $pad ) . "BIN\0" );

		while ( $ok && ! feof( $in ) ) {
			$chunk = Stream::read( $in, 1048576 );
			if ( '' === $chunk ) {
				break;
			}
			$ok = Stream::write( $out, $chunk );
		}
		$ok = $ok && Stream::write( $out, str_repeat( "\0", $pad ) );

		Stream::close( $in );
		Stream::close( $out );

		return $ok && filesize( $dest ) === $size;
	}

	/**
	 * Reads the JSON document of a GLB or glTF file.
	 *
	 * @param string $path File path.
	 * @return array|null
	 */
	public static function json( string $path ): ?array {
		$head = Stream::head( $path, 20 );
		if ( 'glTF' === substr( $head, 0, 4 ) && strlen( $head ) === 20 ) {
			$chunk = unpack( 'Vlength', substr( $head, 12, 4 ) );
			$size  = is_array( $chunk ) ? (int) $chunk['length'] : 0;
			$text  = $size > 0 ? substr( Stream::head( $path, 20 + $size ), 20 ) : '';
		} else {
			$text = Stream::head( $path, (int) filesize( $path ) );
		}

		$data = json_decode( $text, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Counts the triangles of a glTF document's meshes, as drawn once each.
	 *
	 * @param array $json glTF JSON.
	 */
	public static function triangles( array $json ): int {
		$accessors = (array) ( $json['accessors'] ?? array() );
		$count     = 0;

		foreach ( (array) ( $json['meshes'] ?? array() ) as $mesh ) {
			foreach ( (array) ( $mesh['primitives'] ?? array() ) as $primitive ) {
				$mode = (int) ( $primitive['mode'] ?? 4 );
				$key  = isset( $primitive['indices'] ) ? $primitive['indices'] : ( $primitive['attributes']['POSITION'] ?? null );
				$n    = null !== $key && isset( $accessors[ $key ]['count'] ) ? (int) $accessors[ $key ]['count'] : 0;

				if ( 4 === $mode ) {
					$count += intdiv( $n, 3 );
				} elseif ( 5 === $mode || 6 === $mode ) {
					$count += max( 0, $n - 2 );
				}
			}
		}

		return $count;
	}

	/**
	 * Rounds a byte length up to a multiple of four.
	 *
	 * @param int $length Bytes.
	 */
	private static function aligned( int $length ): int {
		return ( $length + 3 ) & ~3;
	}
}
