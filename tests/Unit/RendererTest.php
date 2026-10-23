<?php
/**
 * Tests for the block's front-end markup.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PratikLondhe\Embed3DModels\Block\Renderer;
use PratikLondhe\Embed3DModels\Media\Converter;
use PratikLondhe\Embed3DModels\Media\Models;

final class RendererTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new Renderer( new Models( new Converter() ) );
		Functions\when( 'get_block_wrapper_attributes' )->alias(
			static function ( $extra ) {
				return 'class="wp-block-embed-3d-models-model ' . $extra['class'] . '"';
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( $color ) {
				return preg_match( '/^#([a-f0-9]{3}){1,2}$/i', $color ) ? $color : null;
			}
		);
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( false );
	}

	public function test_renders_model_viewer_with_blog_friendly_defaults(): void {
		$html = $this->renderer->render(
			array(
				'src'            => 'https://example.com/chair.glb',
				'alt'            => 'A "chair"',
				'cameraControls' => true,
				'autoRotate'     => true,
				'caption'        => 'My chair',
			)
		);

		$this->assertStringContainsString( '<figure class="wp-block-embed-3d-models-model e3dm-figure">', $html );
		$this->assertStringContainsString( 'src="https://example.com/chair.glb"', $html );
		$this->assertStringContainsString( 'alt="A &quot;chair&quot;"', $html );
		$this->assertStringContainsString( ' camera-controls ', $html );
		$this->assertStringContainsString( ' disable-zoom ', $html, 'Zoom is off unless allowed.' );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'touch-action="pan-y"', $html );
		$this->assertStringNotContainsString( ' ar ', $html, 'AR is off by default.' );
		$this->assertStringNotContainsString( 'e3dm-load', $html );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">My chair</figcaption>', $html );
	}

	public function test_nothing_renders_without_a_model(): void {
		$this->assertSame( '', $this->renderer->render( array( 'alt' => 'x' ) ) );
	}

	public function test_click_to_load_adds_the_button(): void {
		$html = $this->renderer->render(
			array(
				'src'    => 'https://example.com/a.glb',
				'reveal' => 'manual',
			)
		);

		$this->assertStringContainsString( 'reveal="manual"', $html );
		$this->assertStringContainsString( '<button type="button" class="e3dm-load">Load 3D model</button>', $html );
	}

	public function test_ar_adds_modes_and_ios_file(): void {
		$attrs = $this->renderer->viewer_attributes(
			array(
				'src'    => 'https://example.com/a.glb',
				'ar'     => true,
				'iosSrc' => 'https://example.com/a.usdz',
			)
		);

		$this->assertTrue( $attrs['ar'] );
		$this->assertSame( 'webxr scene-viewer quick-look', $attrs['ar-modes'] );
		$this->assertSame( 'https://example.com/a.usdz', $attrs['ios-src'] );
	}

	public function test_unsafe_values_are_cleaned(): void {
		$attrs = $this->renderer->viewer_attributes(
			array(
				'src'             => 'https://example.com/a.glb',
				'height'          => '100px;background:url(x)',
				'backgroundColor' => 'red;color:blue',
				'exposure'        => 99,
				'shadowIntensity' => 'lots',
				'cameraOrbit'     => '0deg 75deg 105%" onload="x',
				'loading'         => 'never',
			)
		);

		$this->assertSame( 'height:480px;', $attrs['style'] );
		$this->assertSame( '4', $attrs['exposure'] );
		$this->assertSame( '1', $attrs['shadow-intensity'] );
		$this->assertNull( $attrs['camera-orbit'] );
		$this->assertSame( 'lazy', $attrs['loading'] );
	}

	public function test_valid_values_are_kept(): void {
		$this->assertSame( '60vh', Renderer::height( '60vh' ) );
		$this->assertSame( '0.785rad 1.047rad 2.5m', Renderer::orbit( '0.785rad 1.047rad 2.5m' ) );
		$this->assertSame( '45deg 55deg 105%', Renderer::orbit( '45deg 55deg 105%' ) );
	}

	public function test_html_attributes_skip_bad_names_and_false_values(): void {
		$this->assertSame(
			'a="1" b c="&lt;x&gt;"',
			Renderer::html_attributes(
				array(
					'a'          => '1',
					'b'          => true,
					'c'          => '<x>',
					'd'          => false,
					'e'          => null,
					'on load'    => 'x',
					'"><script>' => 'x',
				)
			)
		);
	}

	public function test_viewer_attributes_filter(): void {
		Filters\expectApplied( 'embed_3d_models_viewer_attributes' )
			->once()
			->andReturnUsing(
				static function ( $viewer ) {
					$viewer['environment-image'] = 'neutral';
					$viewer['auto-rotate']       = false;
					return $viewer;
				}
			);

		$html = $this->renderer->render(
			array(
				'src'        => 'https://example.com/a.glb',
				'autoRotate' => true,
			)
		);

		$this->assertStringContainsString( 'environment-image="neutral"', $html );
		$this->assertStringNotContainsString( 'auto-rotate', $html );
	}

	public function test_render_filter(): void {
		Filters\expectApplied( 'embed_3d_models_render' )->once()->andReturn( '<p>replaced</p>' );

		$this->assertSame( '<p>replaced</p>', $this->renderer->render( array( 'src' => 'https://example.com/a.glb' ) ) );
	}
}
