<?php
/**
 * The 3D Model block and its scripts.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Block;

use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Media\Uploads;
use PratikLondhe\Embed3DModels\Service;
use const PratikLondhe\Embed3DModels\PLUGIN_FILE;
use const PratikLondhe\Embed3DModels\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the block, the bundled <model-viewer> and the editor script.
 *
 * On the front end the viewer loads only on pages that show the block (it is
 * the block's view script). In the editor it loads through
 * enqueue_block_assets, which reaches the editor's iframe.
 */
final class Block implements Service {

	public const VIEWER_HANDLE = 'embed-3d-models-viewer';

	/**
	 * Front-end markup.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Sets up the block.
	 *
	 * @param Renderer $renderer Front-end markup.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_in_editor' ) );
	}

	/**
	 * Registers the scripts, style and block.
	 */
	public function register_block(): void {
		$url = plugins_url( 'assets/', PLUGIN_FILE );

		wp_register_script( self::VIEWER_HANDLE, $url . 'vendor/model-viewer/model-viewer-umd.min.js', array(), '4.4.0', array( 'in_footer' => true ) );
		wp_add_inline_script( self::VIEWER_HANDLE, $this->decoder_script(), 'after' );

		wp_register_script( 'embed-3d-models-view', $url . 'js/view.js', array( self::VIEWER_HANDLE ), VERSION, array( 'in_footer' => true ) );
		wp_register_script( 'embed-3d-models-attributes', $url . 'js/attributes.js', array(), VERSION, true );
		wp_register_script(
			'embed-3d-models-editor',
			$url . 'js/block.js',
			array(
				'embed-3d-models-attributes',
				'wp-api-fetch',
				'wp-block-editor',
				'wp-blocks',
				'wp-components',
				'wp-data',
				'wp-element',
				'wp-hooks',
				'wp-i18n',
				'wp-notices',
				'wp-primitives',
			),
			VERSION,
			true
		);
		wp_add_inline_script( 'embed-3d-models-editor', 'window.embed3dModelsEditor = ' . wp_json_encode( $this->editor_settings() ) . ';', 'before' );
		wp_set_script_translations( 'embed-3d-models-editor', 'embed-3d-models' );

		wp_register_style( 'embed-3d-models', $url . 'css/block.css', array(), VERSION );

		register_block_type(
			dirname( PLUGIN_FILE ) . '/block',
			array( 'render_callback' => array( $this->renderer, 'render' ) )
		);
	}

	/**
	 * Loads the viewer in the editor (its iframe included), not on the front end.
	 */
	public function enqueue_in_editor(): void {
		if ( is_admin() ) {
			wp_enqueue_script( self::VIEWER_HANDLE );
		}
	}

	/**
	 * Where <model-viewer> finds its Draco, KTX2 and meshopt decoders.
	 *
	 * @return array<string, string>
	 */
	public static function decoder_locations(): array {
		$url      = plugins_url( 'assets/vendor/', PLUGIN_FILE );
		$defaults = array(
			'dracoDecoderLocation'   => $url . 'draco/',
			'ktx2TranscoderLocation' => $url . 'basis/',
			'meshoptDecoderLocation' => $url . 'meshopt/meshopt_decoder.js',
		);

		/**
		 * Filters where the viewer loads its decoders for compressed models.
		 * They are bundled with the plugin; point them at a CDN if you prefer.
		 *
		 * @param array $locations dracoDecoderLocation, ktx2TranscoderLocation, meshoptDecoderLocation.
		 */
		$locations = array_merge( $defaults, array_intersect_key( (array) apply_filters( 'embed_3d_models_decoder_locations', $defaults ), $defaults ) );

		return array_map(
			static function ( $location ): string {
				return esc_url_raw( (string) $location );
			},
			$locations
		);
	}

	/**
	 * Points <model-viewer> at the bundled decoders, instead of Google's CDN.
	 *
	 * The bundle replaces window.ModelViewerElement when it loads, and each
	 * element reads the locations from it when created, so this runs right
	 * after the bundle: it sets them for elements created later and, through
	 * the class's setters, for elements already on the page.
	 *
	 * The meshopt setter loads its decoder at once by adding a script to
	 * document.body. In the editor canvas the bundle loads in the head, where
	 * there is no body yet (and the canvas document can be replaced), so the
	 * setters only run when the body exists; elements created later read the
	 * locations themselves.
	 */
	private function decoder_script(): string {
		return sprintf(
			'(function(l){var g=window.ModelViewerElement=window.ModelViewerElement||{},c=g.ModelViewerElement;Object.keys(l).forEach(function(n){g[n]=l[n];if(c&&document.body){try{c[n]=l[n];}catch(e){}}});})(%s);',
			wp_json_encode( self::decoder_locations() )
		);
	}

	/**
	 * Settings for the editor script.
	 *
	 * @return array
	 */
	private function editor_settings(): array {
		$types = array();
		foreach ( Formats::all() as $ext => $format ) {
			$types[ $ext ] = array(
				'mime'    => $format['mime'],
				'label'   => $format['label'],
				'convert' => $format['convert'],
			);
		}

		return array(
			'formats'   => $types,
			'canUpload' => current_user_can( Uploads::capability() ),
		);
	}
}
