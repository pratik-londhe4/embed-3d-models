<?php
/**
 * Front-end markup for the 3D Model block.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Block;

use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Media\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Turns block attributes into a <model-viewer> element inside a figure.
 *
 * The block is dynamic, so the model's address is looked up on every view:
 * a model converted again, or moved to a CDN by another plugin, still shows.
 */
final class Renderer {

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Sets up the renderer.
	 *
	 * @param Models $models Model attachments.
	 */
	public function __construct( Models $models ) {
		$this->models = $models;
	}

	/**
	 * Renders the block.
	 *
	 * @param array          $attributes Block attributes.
	 * @param string         $content    Saved content (empty, the block is dynamic).
	 * @param \WP_Block|null $block      Block instance.
	 */
	public function render( $attributes, $content = '', $block = null ): string {
		$attributes = (array) $attributes;
		$viewer     = $this->viewer_attributes( $attributes );
		if ( '' === (string) ( $viewer['src'] ?? '' ) ) {
			return '';
		}

		/**
		 * Filters the attributes of the <model-viewer> element. Keys are
		 * attribute names; true adds a bare attribute, false or null leaves it
		 * out. See https://modelviewer.dev/docs/ for every attribute.
		 *
		 * @param array          $viewer     Element attributes.
		 * @param array          $attributes Block attributes.
		 * @param \WP_Block|null $block      Block instance.
		 */
		$viewer = (array) apply_filters( 'embed_3d_models_viewer_attributes', $viewer, $attributes, $block );

		$inner = '<p class="e3dm-error-message" hidden>' . esc_html( Formats::message( 'load_failed', __( 'This 3D model could not be loaded.', 'embed-3d-models' ) ) ) . '</p>';
		if ( 'manual' === ( $viewer['reveal'] ?? '' ) ) {
			$inner .= '<button type="button" class="e3dm-load">' . esc_html__( 'Load 3D model', 'embed-3d-models' ) . '</button>';
		}

		$caption = trim( (string) ( $attributes['caption'] ?? '' ) );
		$html    = sprintf(
			'<figure %1$s><model-viewer %2$s>%3$s</model-viewer>%4$s</figure>',
			get_block_wrapper_attributes( array( 'class' => 'e3dm-figure' ) ),
			self::html_attributes( $viewer ),
			$inner,
			'' === $caption ? '' : '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>'
		);

		/**
		 * Filters the block's HTML.
		 *
		 * @param string $html       Block HTML.
		 * @param array  $attributes Block attributes.
		 */
		return (string) apply_filters( 'embed_3d_models_render', $html, $attributes );
	}

	/**
	 * The <model-viewer> attributes for a set of block attributes.
	 *
	 * @param array $attributes Block attributes.
	 * @return array<string, string|bool|null>
	 */
	public function viewer_attributes( array $attributes ): array {
		$src  = '';
		$info = null;
		$id   = (int) ( $attributes['id'] ?? 0 );
		if ( $id > 0 ) {
			$info = $this->models->info( $id );
			$src  = null === $info ? '' : $info['viewer_url'];
		}
		if ( '' === $src && $id < 1 ) {
			$src = (string) ( $attributes['src'] ?? '' );
		}

		$poster    = '';
		$poster_id = (int) ( $attributes['posterId'] ?? 0 );
		if ( $poster_id > 0 ) {
			$poster = (string) wp_get_attachment_image_url( $poster_id, 'large' );
		}
		if ( '' === $poster ) {
			$poster = (string) ( $attributes['poster'] ?? '' );
		}
		if ( '' === $poster && null !== $info ) {
			$poster = $info['poster_url'];
		}

		$ar      = ! empty( $attributes['ar'] );
		$ios_src = '';
		if ( $ar ) {
			$ios_id  = (int) ( $attributes['iosId'] ?? 0 );
			$ios_src = $ios_id > 0 ? (string) wp_get_attachment_url( $ios_id ) : (string) ( $attributes['iosSrc'] ?? '' );
		}

		$reveal = 'manual' === ( $attributes['reveal'] ?? '' ) ? 'manual' : 'auto';
		$style  = 'height:' . self::height( (string) ( $attributes['height'] ?? '' ) ) . ';';
		$color  = sanitize_hex_color( (string) ( $attributes['backgroundColor'] ?? '' ) );
		if ( $color ) {
			$style .= 'background-color:' . $color . ';';
		}

		return array(
			'src'              => esc_url_raw( $src ),
			'alt'              => (string) ( $attributes['alt'] ?? '' ),
			'poster'           => '' === $poster ? null : esc_url_raw( $poster ),
			'loading'          => 'eager' === ( $attributes['loading'] ?? '' ) ? 'eager' : 'lazy',
			'reveal'           => $reveal,
			'camera-controls'  => ! empty( $attributes['cameraControls'] ),
			'disable-zoom'     => empty( $attributes['allowZoom'] ),
			'touch-action'     => 'pan-y',
			'auto-rotate'      => ! empty( $attributes['autoRotate'] ),
			'autoplay'         => ! empty( $attributes['autoplay'] ),
			'ar'               => $ar,
			'ar-modes'         => $ar ? 'webxr scene-viewer quick-look' : null,
			'ios-src'          => '' === $ios_src ? null : esc_url_raw( $ios_src ),
			'exposure'         => self::number( $attributes['exposure'] ?? 1, 0, 4, 1 ),
			'shadow-intensity' => self::number( $attributes['shadowIntensity'] ?? 1, 0, 2, 1 ),
			'camera-orbit'     => self::orbit( (string) ( $attributes['cameraOrbit'] ?? '' ) ),
			'style'            => $style,
		);
	}

	/**
	 * Builds an HTML attribute string. True adds a bare attribute; false and null are left out.
	 *
	 * @param array $attributes Name => value.
	 */
	public static function html_attributes( array $attributes ): string {
		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) || null === $value || false === $value ) {
				continue;
			}
			if ( in_array( $name, array( 'src', 'poster', 'ios-src', 'skybox-image', 'environment-image' ), true ) ) {
				$value = esc_url( (string) $value );
			}
			$parts[] = true === $value ? $name : $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return implode( ' ', $parts );
	}

	/**
	 * A CSS height: a number with px, vh, em, rem or %, else 480px.
	 *
	 * @param string $height Height.
	 */
	public static function height( string $height ): string {
		return preg_match( '/^\d{1,4}(\.\d{1,2})?(px|vh|svh|em|rem|%)$/', trim( $height ) ) ? trim( $height ) : '480px';
	}

	/**
	 * A camera orbit: three CSS values (angle, angle, length or percent), or null.
	 *
	 * @param string $orbit Orbit, e.g. "45deg 60deg 2.5m".
	 */
	public static function orbit( string $orbit ): ?string {
		$value = '-?\d+(\.\d+)?(e-?\d+)?(deg|rad|m|cm|mm|%)?';

		return preg_match( '/^' . $value . ' ' . $value . ' ' . $value . '$/', trim( $orbit ) ) ? trim( $orbit ) : null;
	}

	/**
	 * A number within bounds, as a string.
	 *
	 * @param mixed $value    Value.
	 * @param float $min      Minimum.
	 * @param float $max      Maximum.
	 * @param float $fallback Used when the value is not a number.
	 */
	private static function number( $value, float $min, float $max, float $fallback ): string {
		$number = is_numeric( $value ) ? (float) $value : $fallback;

		return (string) round( max( $min, min( $max, $number ) ), 2 );
	}
}
