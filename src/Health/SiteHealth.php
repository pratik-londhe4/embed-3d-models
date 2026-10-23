<?php
/**
 * Site Health check.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Health;

use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Media\Models;
use PratikLondhe\Embed3DModels\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a 3D models test to Tools > Site Health: are the types still allowed,
 * did conversions fail, and is USDZ served so iPhones can open it in AR.
 */
final class SiteHealth implements Service {

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Sets up the check.
	 *
	 * @param Models $models Model attachments.
	 */
	public function __construct( Models $models ) {
		$this->models = $models;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                                     = (array) $tests;
		$tests['direct']['embed_3d_models_upload'] = array(
			'label' => __( '3D model uploads', 'embed-3d-models' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run(): array {
		$allowed = get_allowed_mime_types();
		$missing = array();
		foreach ( Formats::all() as $ext => $format ) {
			if ( ! in_array( $format['mime'], $allowed, true ) ) {
				$missing[] = $format['label'];
			}
		}
		if ( $missing ) {
			$detail = is_multisite()
				? __( 'On a multisite network, add these extensions to "Upload file types" in Network Admin > Settings.', 'embed-3d-models' )
				: __( 'Another plugin or the theme removes them from the allowed upload types.', 'embed-3d-models' );

			return $this->result(
				'recommended',
				__( 'Some 3D file types cannot be uploaded', 'embed-3d-models' ),
				/* translators: %s: list of file types, e.g. "STL, OBJ". */
				sprintf( __( 'You cannot upload these types: %s.', 'embed-3d-models' ), implode( ', ', $missing ) ) . ' ' . $detail
			);
		}

		$failed = array_filter(
			$this->models->all( 100 ),
			static function ( array $model ): bool {
				return in_array( $model['status'], array( 'failed', 'too_large' ), true );
			}
		);
		if ( $failed ) {
			return $this->result(
				'recommended',
				__( 'Some 3D models could not be converted', 'embed-3d-models' ),
				sprintf(
					/* translators: 1: number of models, 2: WP-CLI command. */
					_n( '%1$d STL or OBJ file was not converted, so the 3D Model block cannot show it. Large files can be converted with: %2$s', '%1$d STL or OBJ files were not converted, so the 3D Model block cannot show them. Large files can be converted with: %2$s', count( $failed ), 'embed-3d-models' ),
					count( $failed ),
					'wp embed-3d-models convert ' . implode( ' ', wp_list_pluck( $failed, 'id' ) ) . ' --ignore-limit'
				)
			);
		}

		$usdz = $this->models->all( 1, 'usdz' );
		if ( $usdz ) {
			$response = wp_safe_remote_head( $usdz[0]['url'], array( 'timeout' => 3 ) );
			$type     = is_wp_error( $response ) ? '' : strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( '' !== $type && 0 !== strpos( $type, 'model/vnd.usdz+zip' ) ) {
				return $this->result(
					'recommended',
					__( 'USDZ files are served with the wrong type', 'embed-3d-models' ),
					/* translators: %s: the Content-Type the server sends. */
					sprintf( __( 'Your server sends USDZ files as "%s". iPhones and iPads only open them in augmented reality when the type is model/vnd.usdz+zip. Ask your host to add that type for .usdz files.', 'embed-3d-models' ), $type )
				);
			}
		}

		return $this->result( 'good', __( '3D models can be uploaded', 'embed-3d-models' ), __( 'GLB, glTF, USDZ, STL and OBJ files can be uploaded to the Media Library.', 'embed-3d-models' ) );
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Explanation.
	 * @return array
	 */
	private function result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Media', 'embed-3d-models' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'embed_3d_models_upload',
		);
	}
}
