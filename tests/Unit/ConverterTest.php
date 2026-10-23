<?php
/**
 * Tests for STL and OBJ to GLB conversion.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PratikLondhe\Embed3DModels\Media\Converter;
use PratikLondhe\Embed3DModels\Media\Glb;
use PratikLondhe\Embed3DModels\Media\MeshWriter;

final class ConverterTest extends TestCase {

	/**
	 * One right triangle in the XY plane, in millimetres, Z up.
	 */
	private const TRIANGLE = array( array( 0, 0, 0 ), array( 10, 0, 0 ), array( 0, 20, 0 ) );

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return Fixtures::path( 'tmp' );
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Fixtures::clean();
		parent::tearDown();
	}

	public function test_binary_stl_becomes_a_valid_glb(): void {
		$dest   = Fixtures::path( 'glb' );
		$result = ( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );

		$this->assertSame(
			array(
				'triangles' => 1,
				'skipped'   => 0,
			),
			$result
		);

		$glb = Fixtures::read_glb( $dest );
		$this->assertSame( 'glTF', $glb['magic'] );
		$this->assertSame( 2, $glb['version'] );
		$this->assertSame( $glb['size'], $glb['length'], 'Header length is the file size.' );
		$this->assertSame( 'JSON', $glb['json_type'] );
		$this->assertSame( "BIN\0", $glb['bin_type'] );
		$this->assertSame( 0, $glb['size'] % 4, 'Chunks are 4-byte aligned.' );
		$this->assertSame( '2.0', $glb['json']['asset']['version'] );
		$this->assertSame( 3, $glb['json']['accessors'][0]['count'] );
		$this->assertSame( 72, $glb['json']['buffers'][0]['byteLength'] );
		$this->assertSame( 24, $glb['json']['bufferViews'][0]['byteStride'] );
		$this->assertSame( 1, Glb::triangles( $glb['json'] ) );
	}

	public function test_stl_is_turned_y_up_and_scaled_to_metres(): void {
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );

		$glb      = Fixtures::read_glb( $dest );
		$vertices = Fixtures::vertices( $glb['bin'] );

		// (x, y, z) mm becomes (x, z, -y) m: the third corner (0, 20, 0) is (0, 0, -0.02).
		$this->assertEqualsWithDelta( array( 0.0, 0.0, -0.02 ), array_slice( $vertices[2], 0, 3 ), 1e-6 );
		$this->assertEqualsWithDelta( array( 0.01, 0.0, 0.0 ), array_slice( $vertices[1], 0, 3 ), 1e-6 );

		// The triangle faced +Z in the file, so it faces +Y now.
		$this->assertEqualsWithDelta( array( 0.0, 1.0, 0.0 ), array_slice( $vertices[0], 3, 3 ), 1e-6 );

		$this->assertEqualsWithDelta( array( 0.0, 0.0, -0.02 ), $glb['json']['accessors'][0]['min'], 1e-6 );
		$this->assertEqualsWithDelta( array( 0.01, 0.0, 0.0 ), $glb['json']['accessors'][0]['max'], 1e-6 );
	}

	public function test_accessor_bounds_match_the_buffer_exactly(): void {
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( array( array( 0.1, 0.2, 0.3 ), array( 1.7, 0.9, 0.333 ), array( 0.05, 2.2, 1.1 ) ) ) ) ), 'stl', $dest );

		$glb       = Fixtures::read_glb( $dest );
		$positions = array_map(
			static function ( $v ) {
				return array_slice( $v, 0, 3 );
			},
			Fixtures::vertices( $glb['bin'] )
		);
		for ( $axis = 0; $axis < 3; $axis++ ) {
			$column = array_column( $positions, $axis );
			$this->assertSame( min( $column ), $glb['json']['accessors'][0]['min'][ $axis ] );
			$this->assertSame( max( $column ), $glb['json']['accessors'][0]['max'][ $axis ] );
		}
	}

	public function test_ascii_stl_is_converted(): void {
		$dest   = Fixtures::path( 'glb' );
		$result = ( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::ascii_stl( array( self::TRIANGLE, array( array( 0, 0, 0 ), array( 0, 0, 5 ), array( 5, 0, 0 ) ) ) ) ), 'stl', $dest );

		$this->assertSame( 2, $result['triangles'] );
		$this->assertSame( 6, Fixtures::read_glb( $dest )['json']['accessors'][1]['count'] );
	}

	public function test_obj_quads_are_split_and_negative_indices_resolved(): void {
		$obj    = "# a square\nv 0 0 0\nv 1 0 0\nv 1 1 0\nv 0 1 0\nf 1 2 3 4\nf -4 -2 -1\n";
		$dest   = Fixtures::path( 'glb' );
		$result = ( new Converter() )->convert( Fixtures::file( 'obj', $obj ), 'obj', $dest );

		$this->assertSame( 3, $result['triangles'] );
		$this->assertSame( 0, $result['skipped'] );

		// OBJ stays Y-up and unscaled.
		$vertices = Fixtures::vertices( Fixtures::read_glb( $dest )['bin'] );
		$this->assertEqualsWithDelta( array( 1.0, 1.0, 0.0 ), array_slice( $vertices[2], 0, 3 ), 1e-6 );
	}

	public function test_obj_vertex_normals_are_used(): void {
		$obj  = "v 0 0 0\nv 1 0 0\nv 0 1 0\nvn 0 0 2\nvn 0 0 1\nvn 1 0 0\nf 1//1 2//2 3//3\n";
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'obj', $obj ), 'obj', $dest );

		$vertices = Fixtures::vertices( Fixtures::read_glb( $dest )['bin'] );
		$this->assertEqualsWithDelta( array( 0.0, 0.0, 1.0 ), array_slice( $vertices[0], 3, 3 ), 1e-6, 'Normals are normalised.' );
		$this->assertEqualsWithDelta( array( 1.0, 0.0, 0.0 ), array_slice( $vertices[2], 3, 3 ), 1e-6 );
	}

	public function test_bad_obj_faces_are_skipped_not_fatal(): void {
		$obj    = "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\nf 1 2 9\nf 1 2\n";
		$result = ( new Converter() )->convert( Fixtures::file( 'obj', $obj ), 'obj', Fixtures::path( 'glb' ) );

		$this->assertSame( 1, $result['triangles'] );
		$this->assertSame( 2, $result['skipped'] );
	}

	public function test_degenerate_triangle_gets_an_up_normal(): void {
		$this->assertSame( array( 0.0, 1.0, 0.0 ), MeshWriter::face_normal( array( 0, 0, 0 ), array( 1, 1, 1 ), array( 2, 2, 2 ) ) );
	}

	public function test_model_without_triangles_is_an_error(): void {
		$dest   = Fixtures::path( 'glb' );
		$result = ( new Converter() )->convert( Fixtures::file( 'obj', "v 0 0 0\nv 1 0 0\n" ), 'obj', $dest );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'embed_3d_models_no_triangles', $result->get_error_code() );
		$this->assertFileDoesNotExist( $dest );
	}

	public function test_only_stl_and_obj_are_converted(): void {
		$result = ( new Converter() )->convert( Fixtures::file( 'glb', 'x' ), 'glb', Fixtures::path( 'glb' ) );

		$this->assertSame( 'embed_3d_models_not_convertible', $result->get_error_code() );
	}

	public function test_scale_and_up_axis_filters(): void {
		Filters\expectApplied( 'embed_3d_models_conversion_scale' )->once()->with( 0.001, 'stl' )->andReturn( 1.0 );
		Filters\expectApplied( 'embed_3d_models_conversion_up_axis' )->once()->with( 'z', 'stl' )->andReturn( 'y' );

		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );

		$vertices = Fixtures::vertices( Fixtures::read_glb( $dest )['bin'] );
		$this->assertEqualsWithDelta( array( 0.0, 20.0, 0.0 ), array_slice( $vertices[2], 0, 3 ), 1e-6 );
	}

	public function test_invalid_filtered_scale_and_axis_fall_back(): void {
		Filters\expectApplied( 'embed_3d_models_conversion_scale' )->andReturn( -5 );
		Filters\expectApplied( 'embed_3d_models_conversion_up_axis' )->andReturn( 'x' );

		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );

		$vertices = Fixtures::vertices( Fixtures::read_glb( $dest )['bin'] );
		$this->assertEqualsWithDelta( array( 0.0, 0.0, -0.02 ), array_slice( $vertices[2], 0, 3 ), 1e-6 );
	}

	public function test_color_filter_is_clamped_and_invalid_falls_back(): void {
		Filters\expectApplied( 'embed_3d_models_conversion_color' )->once()->andReturn( array( 2, -1, 0.5, 1 ) );
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );
		$this->assertEquals( array( 1.0, 0.0, 0.5, 1.0 ), Fixtures::read_glb( $dest )['json']['materials'][0]['pbrMetallicRoughness']['baseColorFactor'] );
	}

	public function test_color_filter_with_wrong_shape_falls_back(): void {
		Filters\expectApplied( 'embed_3d_models_conversion_color' )->once()->andReturn( 'red' );
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( self::TRIANGLE ) ) ), 'stl', $dest );
		$this->assertEquals( array( 0.42, 0.45, 0.5, 1.0 ), Fixtures::read_glb( $dest )['json']['materials'][0]['pbrMetallicRoughness']['baseColorFactor'] );
	}

	public function test_triangle_count_handles_indices_and_strips(): void {
		$json = array(
			'accessors' => array( array( 'count' => 9 ), array( 'count' => 6 ), array( 'count' => 5 ) ),
			'meshes'    => array(
				array(
					'primitives' => array(
						array(
							'attributes' => array( 'POSITION' => 1 ),
							'indices'    => 0,
						),
						array(
							'attributes' => array( 'POSITION' => 2 ),
							'mode'       => 5,
						),
						array(
							'attributes' => array( 'POSITION' => 1 ),
							'mode'       => 1,
						),
					),
				),
			),
		);

		$this->assertSame( 3 + 3, Glb::triangles( $json ) );
	}
}
