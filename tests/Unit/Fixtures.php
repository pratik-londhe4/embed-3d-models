<?php
/**
 * Builds small 3D files for tests.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

/**
 * Writes fixture files to a temp folder and reads GLBs back.
 */
final class Fixtures {

	/**
	 * Files written, removed by clean().
	 *
	 * @var string[]
	 */
	private static array $files = array();

	/**
	 * Writes a file and returns its path.
	 *
	 * @param string $ext     Extension.
	 * @param string $content Bytes.
	 */
	public static function file( string $ext, string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'e3dm' ) . '.' . $ext;
		file_put_contents( $path, $content );
		self::$files[] = $path;

		return $path;
	}

	/**
	 * A path for a file a test will write.
	 *
	 * @param string $ext Extension.
	 */
	public static function path( string $ext ): string {
		$path          = tempnam( sys_get_temp_dir(), 'e3dm' ) . '.' . $ext;
		self::$files[] = $path;

		return $path;
	}

	/**
	 * Binary STL from triangles (each three corners of x y z).
	 *
	 * @param array $triangles Triangles.
	 */
	public static function binary_stl( array $triangles ): string {
		$data = str_pad( 'binary stl', 80, ' ' ) . pack( 'V', count( $triangles ) );
		foreach ( $triangles as $t ) {
			$data .= pack( 'g3', 0, 0, 0 ) . pack( 'g9', ...array_merge( $t[0], $t[1], $t[2] ) ) . "\0\0";
		}

		return $data;
	}

	/**
	 * ASCII STL from triangles.
	 *
	 * @param array $triangles Triangles.
	 */
	public static function ascii_stl( array $triangles ): string {
		$text = "solid test\n";
		foreach ( $triangles as $t ) {
			$text .= "  facet normal 0 0 0\n    outer loop\n";
			foreach ( $t as $p ) {
				$text .= '      vertex ' . implode( ' ', $p ) . "\n";
			}
			$text .= "    endloop\n  endfacet\n";
		}

		return $text . "endsolid test\n";
	}

	/**
	 * A minimal valid USDZ: a stored zip entry named model.usdc.
	 */
	public static function usdz(): string {
		$name = 'model.usdc';
		$body = 'PXR-USDC';

		return "PK\x03\x04" . pack( 'vvvvvVVVvv', 20, 0, 0, 0, 0, crc32( $body ), strlen( $body ), strlen( $body ), strlen( $name ), 0 ) . $name . $body;
	}

	/**
	 * Reads a GLB: header fields, JSON document and binary chunk.
	 *
	 * @param string $path GLB file.
	 * @return array{magic: string, version: int, length: int, json: array, bin: string}
	 */
	public static function read_glb( string $path ): array {
		$data   = (string) file_get_contents( $path );
		$header = unpack( 'Vversion/Vlength/VjsonLength', substr( $data, 4, 12 ) );
		$json   = substr( $data, 20, $header['jsonLength'] );
		$rest   = substr( $data, 20 + $header['jsonLength'] );
		$bin    = unpack( 'Vlength', substr( $rest, 0, 4 ) );

		return array(
			'magic'     => substr( $data, 0, 4 ),
			'version'   => $header['version'],
			'length'    => $header['length'],
			'json'      => json_decode( $json, true ),
			'json_type' => substr( $data, 16, 4 ),
			'bin_type'  => substr( $rest, 4, 4 ),
			'bin'       => substr( $rest, 8, $bin['length'] ),
			'size'      => strlen( $data ),
		);
	}

	/**
	 * Floats in a vertex buffer: position (3) then normal (3) per vertex.
	 *
	 * @param string $bin Buffer.
	 * @return float[][] Each vertex as six floats.
	 */
	public static function vertices( string $bin ): array {
		$out = array();
		for ( $offset = 0; $offset + 24 <= strlen( $bin ); $offset += 24 ) {
			$out[] = array_values( unpack( 'g6', substr( $bin, $offset, 24 ) ) );
		}

		return $out;
	}

	/**
	 * Removes the files written.
	 */
	public static function clean(): void {
		foreach ( self::$files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
			$stem = preg_replace( '/\.[a-z]+$/', '', $file );
			if ( $stem !== $file && file_exists( $stem ) ) {
				unlink( $stem );
			}
		}
		self::$files = array();
	}
}
