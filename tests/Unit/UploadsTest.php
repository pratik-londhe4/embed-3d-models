<?php
/**
 * Tests for the upload hooks and the format list.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Media\Converter;
use PratikLondhe\Embed3DModels\Media\Models;
use PratikLondhe\Embed3DModels\Media\Uploads;
use PratikLondhe\Embed3DModels\Media\Validator;

final class UploadsTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Uploads
	 */
	private Uploads $uploads;

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private array $caps = array( 'upload_files' );

	protected function setUp(): void {
		parent::setUp();
		$this->uploads = new Uploads( new Validator(), new Models( new Converter() ) );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
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

	public function test_formats_can_be_removed_by_filter(): void {
		Filters\expectApplied( 'embed_3d_models_formats' )->andReturnUsing(
			static function ( $formats ) {
				unset( $formats['obj'] );
				$formats['fbx'] = array( 'mime' => 'model/fbx' );
				return $formats;
			}
		);

		$this->assertSame( array( 'glb', 'gltf', 'usdz', 'stl' ), array_keys( Formats::all() ) );
	}

	public function test_formats_filter_returning_junk_disables_nothing_unexpected(): void {
		Filters\expectApplied( 'embed_3d_models_formats' )->andReturn( 'nope' );

		$this->assertSame( array(), Formats::all() );
	}

	public function test_message_filter_falls_back_on_empty(): void {
		Filters\expectApplied( 'embed_3d_models_message' )->once()->andReturn( '' );

		$this->assertSame( 'Default.', Formats::message( 'x', 'Default.' ) );
	}

	public function test_mimes_are_added_for_users_who_can_upload(): void {
		$mimes = $this->uploads->add_mimes( array( 'jpg' => 'image/jpeg' ) );

		$this->assertSame( 'model/gltf-binary', $mimes['glb'] );
		$this->assertSame( 'model/stl', $mimes['stl'] );
		$this->assertSame( 'image/jpeg', $mimes['jpg'] );
	}

	public function test_capability_filter_limits_uploads(): void {
		Filters\expectApplied( 'embed_3d_models_upload_capability' )->andReturn( 'manage_options' );

		$this->assertSame( array( 'jpg' => 'image/jpeg' ), $this->uploads->add_mimes( array( 'jpg' => 'image/jpeg' ) ) );

		$this->caps[] = 'manage_options';
		$this->assertArrayHasKey( 'glb', $this->uploads->add_mimes( array() ) );
	}

	public function test_empty_capability_falls_back(): void {
		Filters\expectApplied( 'embed_3d_models_upload_capability' )->andReturn( '' );

		$this->assertSame( 'upload_files', Uploads::capability() );
	}

	public function test_prefilter_refuses_a_fake_model_with_a_reason(): void {
		Actions\expectDone( 'embed_3d_models_upload_rejected' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( \WP_Error::class ) );

		$file = $this->uploads->prefilter(
			array(
				'name'     => 'chair.glb',
				'tmp_name' => Fixtures::file( 'tmp', 'not a model' ),
				'error'    => 0,
			)
		);

		$this->assertSame( 'This file is not a valid GLB (binary glTF 2.0) model.', $file['error'] );
	}

	public function test_prefilter_leaves_other_files_alone(): void {
		Actions\expectDone( 'embed_3d_models_upload_rejected' )->never();
		$file = array(
			'name'     => 'photo.jpg',
			'tmp_name' => '/nope',
			'error'    => 0,
		);

		$this->assertSame( $file, $this->uploads->prefilter( $file ) );
	}

	public function test_filetype_check_accepts_a_real_model_that_fileinfo_misreads(): void {
		$stl  = Fixtures::file( 'tmp', Fixtures::binary_stl( array( array( array( 0, 0, 0 ), array( 1, 0, 0 ), array( 0, 1, 0 ) ) ) ) );
		$data = $this->uploads->check_filetype(
			array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			),
			$stl,
			'part.stl',
			array( 'stl' => 'model/stl' ),
			'application/octet-stream'
		);

		$this->assertSame( 'stl', $data['ext'] );
		$this->assertSame( 'model/stl', $data['type'] );
	}

	public function test_filetype_check_refuses_when_the_content_is_wrong(): void {
		$data = $this->uploads->check_filetype(
			array(
				'ext'             => 'stl',
				'type'            => 'model/stl',
				'proper_filename' => false,
			),
			Fixtures::file( 'tmp', 'hello' ),
			'part.stl',
			array( 'stl' => 'model/stl' ),
			'text/plain'
		);

		$this->assertFalse( $data['ext'] );
		$this->assertFalse( $data['type'] );
	}

	public function test_filetype_check_respects_types_the_user_may_not_upload(): void {
		$original = array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		);

		$this->assertSame( $original, $this->uploads->check_filetype( $original, '/x', 'part.stl', array( 'jpg' => 'image/jpeg' ), false ) );
	}

	public function test_library_gets_a_3d_models_filter(): void {
		$types = $this->uploads->library_filter( array() );

		$this->assertSame( '3D models', $types['model'][0] );
	}
}
