<?php
/**
 * REST routes for 3D models.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Rest;

use PratikLondhe\Embed3DModels\Media\Models;
use PratikLondhe\Embed3DModels\Service;

defined( 'ABSPATH' ) || exit;

/**
 * `embed-3d-models/v1/models/<id>`: read a model, set its poster, convert it again.
 */
final class ModelsController implements Service {

	public const NAMESPACE = 'embed-3d-models/v1';

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Sets up the routes.
	 *
	 * @param Models $models Model attachments.
	 */
	public function __construct( Models $models ) {
		$this->models = $models;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/models/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/models/(?P<id>\d+)/poster',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_poster' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $id + array(
					'poster_id' => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/models/(?P<id>\d+)/convert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convert' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => $id,
			)
		);
	}

	/**
	 * Whether the user may edit this model attachment.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_edit( $request ): bool {
		return current_user_can( 'edit_post', (int) $request->get_param( 'id' ) );
	}

	/**
	 * Returns a model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get( $request ) {
		$info = $this->models->info( (int) $request->get_param( 'id' ) );
		if ( null === $info ) {
			return new \WP_Error( 'embed_3d_models_not_a_model', __( 'That attachment is not a 3D model.', 'embed-3d-models' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( $info );
	}

	/**
	 * Sets or removes a model's poster.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_poster( $request ) {
		$poster_id = (int) $request->get_param( 'poster_id' );
		if ( $poster_id > 0 && ! current_user_can( 'edit_post', $poster_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You cannot use that image.', 'embed-3d-models' ), array( 'status' => 403 ) );
		}

		$result = $this->models->set_poster( (int) $request->get_param( 'id' ), $poster_id );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * Converts a model again (STL and OBJ), within the upload size limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert( $request ) {
		$result = $this->models->reprocess( (int) $request->get_param( 'id' ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}
}
