<?php
/**
 * Tests for model attachments: conversion, posters and cleanup.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PratikLondhe\Embed3DModels\Media\Converter;
use PratikLondhe\Embed3DModels\Media\Models;

final class ModelsTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Attachments: id => [ mime, file ].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $attachments = array();

	/**
	 * Post meta: id => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * Folder holding the "uploads".
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->models = new Models( new Converter() );
		$this->dir    = sys_get_temp_dir() . '/e3dm-' . uniqid();
		mkdir( $this->dir );

		Functions\when( 'get_post_type' )->alias(
			function ( $id ) {
				return isset( $this->attachments[ $id ] ) ? 'attachment' : false;
			}
		);
		Functions\when( 'get_post_mime_type' )->alias(
			function ( $id ) {
				return $this->attachments[ $id ][0] ?? false;
			}
		);
		Functions\when( 'get_attached_file' )->alias(
			function ( $id ) {
				return $this->attachments[ $id ][1] ?? false;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) {
				return $this->meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			function ( $id ) {
				return $this->meta[ $id ]['_wp_attachment_metadata'] ?? false;
			}
		);
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( $id, $data ) {
				$this->meta[ $id ]['_wp_attachment_metadata'] = $data;
				return true;
			}
		);
		Functions\when( 'wp_attachment_is_image' )->alias(
			static function ( $id ) {
				return 500 === $id;
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			function ( $id ) {
				return 'https://example.com/uploads/' . basename( $this->attachments[ $id ][1] );
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://example.com/uploads/poster.webp' );
		Functions\when( 'get_the_title' )->justReturn( 'Model' );
		Functions\when( 'trailingslashit' )->alias(
			static function ( $path ) {
				return rtrim( $path, '/' ) . '/';
			}
		);
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_unique_filename' )->alias(
			static function ( $dir, $name ) {
				return $name;
			}
		);
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
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
		Fixtures::clean();
		parent::tearDown();
	}

	/**
	 * Adds an STL attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	private function stl( int $id ): string {
		$file = $this->dir . '/part.stl';
		file_put_contents( $file, Fixtures::binary_stl( array( array( array( 0, 0, 0 ), array( 1, 0, 0 ), array( 0, 1, 0 ) ) ) ) );
		$this->attachments[ $id ] = array( 'model/stl', $file );

		return $file;
	}

	public function test_stl_is_converted_next_to_the_original(): void {
		$this->stl( 7 );
		Actions\expectDone( 'embed_3d_models_converted' )->once()->with( 7, \Mockery::type( 'array' ), $this->dir . '/part-stl.glb' );

		$info = $this->models->reprocess( 7 );

		$this->assertSame( 'converted', $info['status'] );
		$this->assertSame( 1, $info['triangles'] );
		$this->assertSame( 'https://example.com/uploads/part-stl.glb', $info['viewer_url'] );
		$this->assertFileExists( $this->dir . '/part-stl.glb' );
		$this->assertSame( 'part-stl.glb', $this->meta[7]['_wp_attachment_metadata']['embed_3d_models']['glb'] );
	}

	public function test_files_over_the_limit_wait_for_wp_cli(): void {
		$this->stl( 7 );
		Filters\expectApplied( 'embed_3d_models_max_convert_bytes' )->andReturn( 10 );

		$this->assertSame( 'too_large', $this->models->reprocess( 7 )['status'] );
		$this->assertFileDoesNotExist( $this->dir . '/part-stl.glb' );

		$this->assertSame( 'converted', $this->models->reprocess( 7, true )['status'], 'WP-CLI can ignore the limit.' );
	}

	public function test_invalid_size_limit_falls_back(): void {
		Filters\expectApplied( 'embed_3d_models_max_convert_bytes' )->andReturn( -1 );

		$this->assertSame( 67108864, $this->models->max_convert_bytes() );
	}

	public function test_failed_conversion_fires_an_action_and_is_reported(): void {
		$file = $this->dir . '/broken.obj';
		file_put_contents( $file, "v 0 0 0\n" );
		$this->attachments[8] = array( 'model/obj', $file );
		Actions\expectDone( 'embed_3d_models_conversion_failed' )->once()->with( 8, \Mockery::type( \WP_Error::class ) );

		$info = $this->models->reprocess( 8 );

		$this->assertSame( 'failed', $info['status'] );
		$this->assertSame( '', $info['viewer_url'] );
		$this->assertStringContainsString( 'No triangles', $info['error'] );
	}

	public function test_glb_is_shown_as_uploaded(): void {
		$this->attachments[9] = array( 'model/gltf-binary', $this->dir . '/a.glb' );

		$info = $this->models->info( 9 );

		$this->assertSame( 'ready', $info['status'] );
		$this->assertSame( 'https://example.com/uploads/a.glb', $info['viewer_url'] );
	}

	public function test_usdz_is_never_the_viewer_file(): void {
		$this->attachments[10] = array( 'model/vnd.usdz+zip', $this->dir . '/a.usdz' );

		$this->assertSame( '', $this->models->info( 10 )['viewer_url'] );
	}

	public function test_non_models_are_refused(): void {
		$this->attachments[11] = array( 'image/jpeg', $this->dir . '/a.jpg' );

		$this->assertNull( $this->models->info( 11 ) );
		$this->assertSame( 'embed_3d_models_not_a_model', $this->models->reprocess( 11 )->get_error_code() );
		$this->assertSame( 'embed_3d_models_not_a_model', $this->models->set_poster( 11, 500 )->get_error_code() );
	}

	public function test_poster_is_set_removed_and_must_be_an_image(): void {
		$this->stl( 7 );
		Actions\expectDone( 'embed_3d_models_poster_set' )->twice();

		$this->assertSame( 500, $this->models->set_poster( 7, 500 )['poster_id'] );
		$this->assertSame( 500, $this->meta[7]['_thumbnail_id'] );
		$this->assertSame( 'embed_3d_models_not_an_image', $this->models->set_poster( 7, 501 )->get_error_code() );
		$this->assertSame( 0, $this->models->set_poster( 7, 0 )['poster_id'] );
	}

	public function test_deleting_removes_only_the_derived_glb(): void {
		$source = $this->stl( 7 );
		$this->models->reprocess( 7 );
		Actions\expectDone( 'embed_3d_models_derived_deleted' )->once()->with( 7, $this->dir . '/part-stl.glb' );

		$this->models->delete_derived( 7 );

		$this->assertFileDoesNotExist( $this->dir . '/part-stl.glb' );
		$this->assertFileExists( $source );
	}

	public function test_a_tampered_file_name_cannot_delete_elsewhere(): void {
		$this->stl( 7 );
		$outside = sys_get_temp_dir() . '/e3dm-outside-' . uniqid() . '.glb';
		file_put_contents( $outside, 'x' );
		$this->meta[7]['_wp_attachment_metadata'] = array( 'embed_3d_models' => array( 'glb' => '../' . basename( $outside ) ) );
		Actions\expectDone( 'embed_3d_models_derived_deleted' )->never();

		$this->models->delete_derived( 7 );

		$this->assertFileExists( $outside );
		unlink( $outside );
	}
}
