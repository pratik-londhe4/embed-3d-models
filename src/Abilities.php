<?php
/**
 * Abilities: 3D models for AI agents and other Abilities API clients.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels;

use PratikLondhe\Embed3DModels\Media\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's abilities on WordPress 6.9 and later.
 */
final class Abilities implements Service {

	public const CATEGORY = 'embed-3d-models';

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Sets up the abilities.
	 *
	 * @param Models $models Model attachments.
	 */
	public function __construct( Models $models ) {
		$this->models = $models;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( '3D models', 'embed-3d-models' ),
				'description' => __( '3D model files in the Media Library: list them, read their details, convert them and set their poster images.', 'embed-3d-models' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$model = array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'format'     => array(
					'type' => 'string',
					'enum' => array( 'glb', 'gltf', 'usdz', 'stl', 'obj' ),
				),
				'url'        => array( 'type' => 'string' ),
				'viewer_url' => array(
					'type'        => 'string',
					'description' => 'The file the 3D Model block shows: the converted GLB for STL and OBJ, empty for USDZ.',
				),
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'ready', 'converted', 'pending', 'failed', 'too_large' ),
				),
				'error'      => array( 'type' => 'string' ),
				'triangles'  => array( 'type' => array( 'integer', 'null' ) ),
				'poster_id'  => array( 'type' => 'integer' ),
			),
		);
		$id    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		$this->ability(
			'list-models',
			__( 'List 3D models', 'embed-3d-models' ),
			__( 'Lists 3D model files in the Media Library, newest first, with format, conversion status and triangle count.', 'embed-3d-models' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'format' => array(
						'type' => 'string',
						'enum' => array( 'glb', 'gltf', 'usdz', 'stl', 'obj' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => $model,
			),
			array( $this, 'list_models' ),
			static function (): bool {
				return current_user_can( 'upload_files' );
			},
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-model',
			__( 'Get a 3D model', 'embed-3d-models' ),
			__( 'Details of one 3D model attachment: format, the file the block shows, conversion status, triangle count and poster.', 'embed-3d-models' ),
			$this->id_schema( array( 'id' => $id ) ),
			$model,
			array( $this, 'get_model' ),
			array( $this, 'can_edit' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'convert-model',
			__( 'Convert a 3D model again', 'embed-3d-models' ),
			__( 'Converts an STL or OBJ model to GLB again, replacing the previous conversion. Counts triangles for GLB and glTF.', 'embed-3d-models' ),
			$this->id_schema( array( 'id' => $id ) ),
			$model,
			array( $this, 'convert_model' ),
			array( $this, 'can_edit' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'set-model-poster',
			__( 'Set a 3D model poster', 'embed-3d-models' ),
			__( 'Sets the image shown before a 3D model loads, also used as its Media Library thumbnail. Use 0 to remove it.', 'embed-3d-models' ),
			$this->id_schema(
				array(
					'id'        => $id,
					'poster_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				)
			),
			$model,
			array( $this, 'set_model_poster' ),
			static function ( $input = null ): bool {
				$input  = (array) $input;
				$poster = (int) ( $input['poster_id'] ?? 0 );

				return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) ) && ( 0 === $poster || current_user_can( 'edit_post', $poster ) );
			},
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Whether the user may edit the model named in the input.
	 *
	 * @param array|null $input Ability input.
	 */
	public function can_edit( $input = null ): bool {
		return current_user_can( 'edit_post', (int) ( ( (array) $input )['id'] ?? 0 ) );
	}

	/**
	 * Ability: list models.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function list_models( $input = null ): array {
		$input = (array) $input;

		return $this->models->all( (int) ( $input['limit'] ?? 20 ), (string) ( $input['format'] ?? '' ) );
	}

	/**
	 * Ability: one model.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_model( $input = null ) {
		$info = $this->models->info( (int) ( ( (array) $input )['id'] ?? 0 ) );

		return null === $info ? new \WP_Error( 'embed_3d_models_not_a_model', __( 'That attachment is not a 3D model.', 'embed-3d-models' ) ) : $info;
	}

	/**
	 * Ability: convert again.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function convert_model( $input = null ) {
		return $this->models->reprocess( (int) ( ( (array) $input )['id'] ?? 0 ) );
	}

	/**
	 * Ability: set the poster.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function set_model_poster( $input = null ) {
		$input = (array) $input;

		return $this->models->set_poster( (int) ( $input['id'] ?? 0 ), (int) ( $input['poster_id'] ?? 0 ) );
	}

	/**
	 * An input schema whose properties are all required.
	 *
	 * @param array $properties Properties.
	 */
	private function id_schema( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Registers one ability in the plugin's category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
