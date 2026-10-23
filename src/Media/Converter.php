<?php
/**
 * Converts STL and OBJ files to GLB.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

use PratikLondhe\Embed3DModels\Formats;

defined( 'ABSPATH' ) || exit;

/**
 * Turns STL and OBJ meshes into a GLB the viewer can show, without loading
 * the whole model into memory.
 *
 * STL is streamed triangle by triangle. OBJ faces refer back to vertices by
 * number, so OBJ vertices are kept, packed at 12 bytes each.
 */
final class Converter {

	/**
	 * Converts a file.
	 *
	 * @param string $source Source file.
	 * @param string $ext    Source format: stl or obj.
	 * @param string $dest   GLB to write.
	 * @return array{triangles: int, skipped: int}|\WP_Error
	 */
	public function convert( string $source, string $ext, string $dest ) {
		$ext = strtolower( $ext );
		if ( ! in_array( $ext, array( 'stl', 'obj' ), true ) ) {
			return $this->error( 'not_convertible', __( 'Only STL and OBJ files are converted.', 'embed-3d-models' ) );
		}

		$in = Stream::open( $source, 'rb' );
		if ( null === $in ) {
			return $this->error( 'unreadable', __( 'The model file could not be read.', 'embed-3d-models' ) );
		}

		$temp = wp_tempnam( 'embed-3d-models' );
		$out  = '' !== $temp ? Stream::open( $temp, 'wb' ) : null;
		if ( null === $out ) {
			Stream::close( $in );
			return $this->error( 'no_temp', __( 'Could not write a temporary file for the conversion.', 'embed-3d-models' ) );
		}

		$writer = new MeshWriter( $out, 'z' === $this->up_axis( $ext ), $this->scale( $ext ) );
		if ( 'obj' === $ext ) {
			$skipped = $this->read_obj( $in, $writer );
		} elseif ( Validator::is_binary_stl( $source, (int) filesize( $source ) ) ) {
			$skipped = $this->read_binary_stl( $in, $writer );
		} else {
			$skipped = $this->read_ascii_stl( $in, $writer );
		}
		$mesh = $writer->finish();
		Stream::close( $in );
		Stream::close( $out );

		if ( ! $mesh['ok'] ) {
			wp_delete_file( $temp );
			return $this->error( 'write_failed', __( 'Could not write the converted model. The disk may be full.', 'embed-3d-models' ) );
		}
		if ( $mesh['vertices'] < 3 ) {
			wp_delete_file( $temp );
			return $this->error( 'no_triangles', __( 'No triangles were found in this model, so there is nothing to show.', 'embed-3d-models' ) );
		}

		$bytes = $mesh['vertices'] * MeshWriter::STRIDE;
		$ok    = Glb::write( $dest, $this->document( $mesh, $bytes ), $temp, $bytes );
		wp_delete_file( $temp );
		if ( ! $ok ) {
			wp_delete_file( $dest );
			return $this->error( 'write_failed', __( 'Could not write the converted model. The disk may be full.', 'embed-3d-models' ) );
		}

		return array(
			'triangles' => intdiv( $mesh['vertices'], 3 ),
			'skipped'   => $skipped,
		);
	}

	/**
	 * Binary STL: 80-byte header, count, then 50 bytes per triangle
	 * (normal, three corners, attribute bytes).
	 *
	 * @param resource   $in     Source.
	 * @param MeshWriter $writer Output.
	 * @return int Triangles skipped.
	 */
	private function read_binary_stl( $in, MeshWriter $writer ): int {
		Stream::read( $in, 84 );
		$skipped = 0;
		$carry   = '';

		while ( ! feof( $in ) ) {
			$data = $carry . Stream::read( $in, 50 * 20000 );
			$full = intdiv( strlen( $data ), 50 );
			if ( 0 === $full ) {
				break;
			}
			for ( $i = 0; $i < $full; $i++ ) {
				$f = unpack( 'g9', substr( $data, $i * 50 + 12, 36 ) );
				if ( ! is_array( $f ) || ! $writer->triangle( array( $f[1], $f[2], $f[3] ), array( $f[4], $f[5], $f[6] ), array( $f[7], $f[8], $f[9] ) ) ) {
					++$skipped;
				}
			}
			$carry = substr( $data, $full * 50 );
		}

		return $skipped;
	}

	/**
	 * ASCII STL: `vertex x y z` lines, three per facet.
	 *
	 * @param resource   $in     Source.
	 * @param MeshWriter $writer Output.
	 * @return int Triangles skipped.
	 */
	private function read_ascii_stl( $in, MeshWriter $writer ): int {
		$skipped = 0;
		$corners = array();

		while ( null !== ( $line = Stream::line( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- reading line by line.
			$line = trim( $line );
			if ( 0 === stripos( $line, 'vertex' ) ) {
				$corners[] = $this->numbers( substr( $line, 6 ), 3 );
				if ( 3 === count( $corners ) ) {
					if ( null === $corners[0] || null === $corners[1] || null === $corners[2] || ! $writer->triangle( $corners[0], $corners[1], $corners[2] ) ) {
						++$skipped;
					}
					$corners = array();
				}
			} elseif ( 0 === stripos( $line, 'endfacet' ) ) {
				$corners = array();
			}
		}

		return $skipped;
	}

	/**
	 * OBJ: `v` and `vn` lines, `f` lines of 1-based (or negative) indices,
	 * polygons split into a fan of triangles. Texture coordinates, groups and
	 * materials are ignored.
	 *
	 * @param resource   $in     Source.
	 * @param MeshWriter $writer Output.
	 * @return int Triangles skipped.
	 */
	private function read_obj( $in, MeshWriter $writer ): int {
		$positions = '';
		$normals   = '';
		$skipped   = 0;

		while ( null !== ( $line = Stream::line( $in ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- reading line by line.
			$line = trim( $line );
			if ( 0 === strpos( $line, 'v ' ) || 0 === strpos( $line, "v\t" ) ) {
				$p          = $this->numbers( substr( $line, 2 ), 3 );
				$positions .= null === $p ? pack( 'g3', NAN, NAN, NAN ) : pack( 'g3', $p[0], $p[1], $p[2] );
			} elseif ( 0 === strpos( $line, 'vn ' ) || 0 === strpos( $line, "vn\t" ) ) {
				$n        = $this->numbers( substr( $line, 3 ), 3 );
				$normals .= null === $n ? pack( 'g3', 0, 0, 0 ) : pack( 'g3', $n[0], $n[1], $n[2] );
			} elseif ( 0 === strpos( $line, 'f ' ) || 0 === strpos( $line, "f\t" ) ) {
				$skipped += $this->obj_face( substr( $line, 2 ), $positions, $normals, $writer );
			}
		}

		return $skipped;
	}

	/**
	 * Writes one OBJ face as a fan of triangles.
	 *
	 * @param string     $spec      The face's index list.
	 * @param string     $positions Packed vertices so far.
	 * @param string     $normals   Packed normals so far.
	 * @param MeshWriter $writer    Output.
	 * @return int Triangles skipped.
	 */
	private function obj_face( string $spec, string $positions, string $normals, MeshWriter $writer ): int {
		$v_count = intdiv( strlen( $positions ), 12 );
		$n_count = intdiv( strlen( $normals ), 12 );
		$corners = array();

		foreach ( preg_split( '/\s+/', trim( $spec ) ) as $token ) {
			$parts = explode( '/', $token );
			$v     = $this->obj_index( $parts[0], $v_count );
			$n     = isset( $parts[2] ) ? $this->obj_index( $parts[2], $n_count ) : null;
			if ( null === $v ) {
				return max( 1, count( $corners ) - 1 );
			}
			$corners[] = array(
				'p' => array_values( (array) unpack( 'g3', substr( $positions, $v * 12, 12 ) ) ),
				'n' => null === $n ? null : array_values( (array) unpack( 'g3', substr( $normals, $n * 12, 12 ) ) ),
			);
		}

		$skipped = 0;
		$count   = count( $corners );
		for ( $i = 1; $i < $count - 1; $i++ ) {
			$fan         = array( $corners[0], $corners[ $i ], $corners[ $i + 1 ] );
			$fan_normals = null !== $fan[0]['n'] && null !== $fan[1]['n'] && null !== $fan[2]['n']
				? array_merge( $fan[0]['n'], $fan[1]['n'], $fan[2]['n'] )
				: null;
			if ( ! $writer->triangle( $fan[0]['p'], $fan[1]['p'], $fan[2]['p'], $fan_normals ) ) {
				++$skipped;
			}
		}

		return $count < 3 ? 1 : $skipped;
	}

	/**
	 * Turns an OBJ index (1-based, or negative from the end) into a 0-based one.
	 *
	 * @param string $token Index text.
	 * @param int    $count Items defined so far.
	 */
	private function obj_index( string $token, int $count ): ?int {
		if ( ! preg_match( '/^-?\d+$/', $token ) ) {
			return null;
		}
		$index = (int) $token;
		$index = $index < 0 ? $count + $index : $index - 1;

		return $index >= 0 && $index < $count ? $index : null;
	}

	/**
	 * Reads the first $count numbers from a line.
	 *
	 * @param string $text  Numbers separated by spaces.
	 * @param int    $count How many.
	 * @return float[]|null
	 */
	private function numbers( string $text, int $count ): ?array {
		$parts = preg_split( '/\s+/', trim( $text ) );
		if ( ! is_array( $parts ) || count( $parts ) < $count ) {
			return null;
		}
		$numbers = array();
		foreach ( array_slice( $parts, 0, $count ) as $part ) {
			if ( ! is_numeric( $part ) ) {
				return null;
			}
			$numbers[] = (float) $part;
		}

		return $numbers;
	}

	/**
	 * The glTF document for the converted mesh: one node, one mesh, one
	 * material, positions and normals interleaved in one buffer view.
	 *
	 * @param array $mesh  Totals from MeshWriter::finish().
	 * @param int   $bytes Buffer size.
	 */
	private function document( array $mesh, int $bytes ): array {
		return array(
			'asset'       => array(
				'version'   => '2.0',
				'generator' => 'Embed 3D Models',
			),
			'scene'       => 0,
			'scenes'      => array( array( 'nodes' => array( 0 ) ) ),
			'nodes'       => array( array( 'mesh' => 0 ) ),
			'meshes'      => array(
				array(
					'primitives' => array(
						array(
							'attributes' => array(
								'POSITION' => 0,
								'NORMAL'   => 1,
							),
							'material'   => 0,
							'mode'       => 4,
						),
					),
				),
			),
			'materials'   => array(
				array(
					'pbrMetallicRoughness' => array(
						'baseColorFactor' => $this->color(),
						'metallicFactor'  => 0.0,
						'roughnessFactor' => 0.7,
					),
					'doubleSided'          => true,
				),
			),
			'buffers'     => array( array( 'byteLength' => $bytes ) ),
			'bufferViews' => array(
				array(
					'buffer'     => 0,
					'byteOffset' => 0,
					'byteLength' => $bytes,
					'byteStride' => MeshWriter::STRIDE,
					'target'     => 34962,
				),
			),
			'accessors'   => array(
				array(
					'bufferView'    => 0,
					'byteOffset'    => 0,
					'componentType' => 5126,
					'count'         => $mesh['vertices'],
					'type'          => 'VEC3',
					'min'           => $mesh['min'],
					'max'           => $mesh['max'],
				),
				array(
					'bufferView'    => 0,
					'byteOffset'    => 12,
					'componentType' => 5126,
					'count'         => $mesh['vertices'],
					'type'          => 'VEC3',
				),
			),
		);
	}

	/**
	 * Position scale for a format.
	 *
	 * @param string $ext Format.
	 */
	private function scale( string $ext ): float {
		$default = 'stl' === $ext ? 0.001 : 1.0;

		/**
		 * Filters the scale applied to converted models. glTF uses metres;
		 * STL files are usually in millimetres, so STL defaults to 0.001.
		 * The scale matters for augmented reality, where the model is shown
		 * at real size.
		 *
		 * @param float  $scale Scale factor.
		 * @param string $ext   Source format, stl or obj.
		 */
		$scale = (float) apply_filters( 'embed_3d_models_conversion_scale', $default, $ext );

		return is_finite( $scale ) && $scale > 0 ? $scale : $default;
	}

	/**
	 * Which axis points up in a format's files.
	 *
	 * @param string $ext Format.
	 */
	private function up_axis( string $ext ): string {
		$default = 'stl' === $ext ? 'z' : 'y';

		/**
		 * Filters which axis points up in source files. glTF is Y-up; STL
		 * files from CAD and 3D-printing tools are usually Z-up.
		 *
		 * @param string $axis "y" or "z".
		 * @param string $ext  Source format, stl or obj.
		 */
		$axis = apply_filters( 'embed_3d_models_conversion_up_axis', $default, $ext );

		return in_array( $axis, array( 'y', 'z' ), true ) ? $axis : $default;
	}

	/**
	 * Base colour of converted models.
	 *
	 * @return float[] RGBA, 0 to 1.
	 */
	private function color(): array {
		$default = array( 0.42, 0.45, 0.5, 1.0 );

		/**
		 * Filters the colour of converted STL and OBJ models (they carry no
		 * material of their own), as linear RGBA from 0 to 1.
		 *
		 * @param float[] $color Red, green, blue, alpha.
		 */
		$color = (array) apply_filters( 'embed_3d_models_conversion_color', $default );
		if ( 4 !== count( $color ) ) {
			return $default;
		}

		return array_map(
			static function ( $value ): float {
				return max( 0.0, min( 1.0, (float) $value ) );
			},
			array_values( $color )
		);
	}

	/**
	 * Builds an error with a filterable message.
	 *
	 * @param string $key     Error key.
	 * @param string $message Default message.
	 */
	private function error( string $key, string $message ): \WP_Error {
		return new \WP_Error( 'embed_3d_models_' . $key, Formats::message( $key, $message ) );
	}
}
