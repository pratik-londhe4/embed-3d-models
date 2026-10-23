<?php
/**
 * Tests for the content checks on uploads.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PratikLondhe\Embed3DModels\Media\Converter;
use PratikLondhe\Embed3DModels\Media\Validator;

final class ValidatorTest extends TestCase {

	/**
	 * Validator under test.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Validator();
		Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return Fixtures::path( 'tmp' );
			}
		);
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Fixtures::clean();
		parent::tearDown();
	}

	/**
	 * A real GLB, made by the converter.
	 */
	private function glb(): string {
		$dest = Fixtures::path( 'glb' );
		( new Converter() )->convert( Fixtures::file( 'stl', Fixtures::binary_stl( array( array( array( 0, 0, 0 ), array( 1, 0, 0 ), array( 0, 1, 0 ) ) ) ) ), 'stl', $dest );

		return $dest;
	}

	/**
	 * The error code for a file, or "ok".
	 *
	 * @param string $path File.
	 * @param string $ext  Extension.
	 */
	private function verdict( string $path, string $ext ): string {
		$result = $this->validator->validate( $path, $ext );

		return true === $result ? 'ok' : $result->get_error_code();
	}

	public function test_valid_files_pass(): void {
		$gltf = '{"asset":{"version":"2.0"},"buffers":[{"byteLength":3,"uri":"data:application/octet-stream;base64,AAAA"}]}';

		$this->assertSame( 'ok', $this->verdict( $this->glb(), 'glb' ) );
		$this->assertSame( 'ok', $this->verdict( Fixtures::file( 'gltf', $gltf ), 'gltf' ) );
		$this->assertSame( 'ok', $this->verdict( Fixtures::file( 'usdz', Fixtures::usdz() ), 'usdz' ) );
		$this->assertSame( 'ok', $this->verdict( Fixtures::file( 'stl', Fixtures::binary_stl( array( array( array( 0, 0, 0 ), array( 1, 0, 0 ), array( 0, 1, 0 ) ) ) ) ), 'stl' ) );
		$this->assertSame( 'ok', $this->verdict( Fixtures::file( 'stl', Fixtures::ascii_stl( array( array( array( 0, 0, 0 ), array( 1, 0, 0 ), array( 0, 1, 0 ) ) ) ) ), 'stl' ) );
		$this->assertSame( 'ok', $this->verdict( Fixtures::file( 'obj', "o cube\nv 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n" ), 'OBJ' ) );
	}

	public function test_renamed_files_are_refused(): void {
		$text = "just some text\n";

		$this->assertSame( 'embed_3d_models_invalid_glb', $this->verdict( Fixtures::file( 'glb', $text ), 'glb' ) );
		$this->assertSame( 'embed_3d_models_invalid_gltf', $this->verdict( Fixtures::file( 'gltf', $text ), 'gltf' ) );
		$this->assertSame( 'embed_3d_models_invalid_usdz', $this->verdict( Fixtures::file( 'usdz', $text ), 'usdz' ) );
		$this->assertSame( 'embed_3d_models_invalid_stl', $this->verdict( Fixtures::file( 'stl', $text ), 'stl' ) );
		$this->assertSame( 'embed_3d_models_invalid_obj', $this->verdict( Fixtures::file( 'obj', $text ), 'obj' ) );
	}

	public function test_glb_with_wrong_length_or_version_is_refused(): void {
		$glb = (string) file_get_contents( $this->glb() );

		$this->assertSame( 'embed_3d_models_invalid_glb', $this->verdict( Fixtures::file( 'glb', $glb . 'extra' ), 'glb' ) );
		$this->assertSame( 'embed_3d_models_invalid_glb', $this->verdict( Fixtures::file( 'glb', substr_replace( $glb, pack( 'V', 1 ), 4, 4 ) ), 'glb' ) );
	}

	public function test_a_zip_without_usd_inside_is_not_usdz(): void {
		$zip = str_replace( 'model.usdc', 'evil.phtml', Fixtures::usdz() );

		$this->assertSame( 'embed_3d_models_invalid_usdz', $this->verdict( Fixtures::file( 'usdz', $zip ), 'usdz' ) );
	}

	public function test_gltf_with_separate_files_is_refused_unless_allowed(): void {
		$path = Fixtures::file( 'gltf', '{"asset":{"version":"2.0"},"buffers":[{"byteLength":3,"uri":"scene.bin"}]}' );

		$this->assertSame( 'embed_3d_models_external_resources', $this->verdict( $path, 'gltf' ) );

		Filters\expectApplied( 'embed_3d_models_allow_external_resources' )->once()->with( false )->andReturn( true );
		$this->assertSame( 'ok', $this->verdict( $path, 'gltf' ) );
	}

	public function test_gltf_1_is_refused(): void {
		$this->assertSame( 'embed_3d_models_invalid_gltf', $this->verdict( Fixtures::file( 'gltf', '{"asset":{"version":"1.0"}}' ), 'gltf' ) );
	}

	public function test_text_models_carrying_code_are_refused(): void {
		$this->assertSame( 'embed_3d_models_embedded_code', $this->verdict( Fixtures::file( 'obj', "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n# <?php system('id'); ?>\n" ), 'obj' ) );
		$this->assertSame( 'embed_3d_models_embedded_code', $this->verdict( Fixtures::file( 'gltf', '{"asset":{"version":"2.0","generator":"<script>alert(1)</script>"}}' ), 'gltf' ) );
		$this->assertSame( 'embed_3d_models_embedded_code', $this->verdict( Fixtures::file( 'stl', "solid x\n<?= 1 ?>\nfacet\nvertex 0 0 0\nendsolid\n" ), 'stl' ) );
	}

	public function test_code_split_across_chunks_is_still_found(): void {
		// The scan reads 1 MB chunks; put "<?php" across the boundary.
		$obj = "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n#";
		$obj = str_pad( $obj, 1048576 - 2, 'x' ) . '<?php';

		$this->assertSame( 'embed_3d_models_embedded_code', $this->verdict( Fixtures::file( 'obj', $obj ), 'obj' ) );
	}

	public function test_empty_and_unknown_files(): void {
		$this->assertSame( 'embed_3d_models_empty', $this->verdict( Fixtures::file( 'glb', '' ), 'glb' ) );
		$this->assertSame( 'embed_3d_models_unsupported', $this->verdict( Fixtures::file( 'fbx', 'x' ), 'fbx' ) );
	}

	public function test_validate_filter_can_refuse_and_its_message_is_filterable(): void {
		Filters\expectApplied( 'embed_3d_models_validate_file' )->once()->andReturn( false );
		Filters\expectApplied( 'embed_3d_models_message' )->once()->with( \Mockery::type( 'string' ), 'refused' )->andReturn( 'Custom refusal.' );

		$result = $this->validator->validate( $this->glb(), 'glb' );

		$this->assertSame( 'embed_3d_models_refused', $result->get_error_code() );
		$this->assertSame( 'Custom refusal.', $result->get_error_message() );
	}

	public function test_validate_filter_can_replace_the_error(): void {
		$error = new \WP_Error( 'too_big', 'Too big for this site.' );
		Filters\expectApplied( 'embed_3d_models_validate_file' )->once()->andReturn( $error );

		$this->assertSame( $error, $this->validator->validate( $this->glb(), 'glb' ) );
	}
}
