<?php
/**
 * Lets 3D files into the Media Library.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Service;
use const PratikLondhe\Embed3DModels\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Allows the 3D types for people who may upload them, checks each file's
 * content, converts STL and OBJ after upload, and shows models properly in
 * the Media Library.
 */
final class Uploads implements Service {

	/**
	 * Content checks.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Files that passed the content check in this request, keyed by path.
	 *
	 * @var array<string, bool>
	 */
	private array $checked = array();

	/**
	 * Sets up the service.
	 *
	 * @param Validator $validator Content checks.
	 * @param Models    $models    Model attachments.
	 */
	public function __construct( Validator $validator, Models $models ) {
		$this->validator = $validator;
		$this->models    = $models;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		// Before core's multisite check_upload_mimes(), so the network's allowed file types still apply.
		add_filter( 'upload_mimes', array( $this, 'add_mimes' ), 5, 2 ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- adds only 3D types, each checked by content in prefilter().
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'prefilter' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'prefilter' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_filetype' ), 10, 5 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_metadata' ), 10, 2 );
		add_action( 'delete_attachment', array( $this->models, 'delete_derived' ) );
		add_filter( 'post_mime_types', array( $this, 'library_filter' ) );
		add_filter( 'wp_mime_type_icon', array( $this, 'icon' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_for_js' ), 10, 2 );
	}

	/**
	 * The capability needed to upload 3D files.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to upload 3D models. Default
		 * `upload_files`, the same as images. Use `manage_options` to limit
		 * 3D uploads to administrators.
		 *
		 * @param string $capability Capability.
		 */
		$capability = (string) apply_filters( 'embed_3d_models_upload_capability', 'upload_files' );

		return '' !== $capability ? $capability : 'upload_files';
	}

	/**
	 * Adds the 3D types to the allowed upload types.
	 *
	 * @param array             $mimes Allowed types, keyed by extension pattern.
	 * @param int|\WP_User|null $user  The user being checked, or null for the current one.
	 * @return array
	 */
	public function add_mimes( $mimes, $user = null ): array {
		$mimes = (array) $mimes;
		if ( ! $this->user_can_upload( $user ) ) {
			return $mimes;
		}

		foreach ( Formats::all() as $ext => $format ) {
			$mimes[ $ext ] = $format['mime'];
		}

		return $mimes;
	}

	/**
	 * Checks a 3D file's content before WordPress stores it.
	 *
	 * @param array $file Upload: name, tmp_name, size, error.
	 * @return array
	 */
	public function prefilter( $file ): array {
		$file = (array) $file;
		$ext  = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( ! empty( $file['error'] ) || null === Formats::for_extension( $ext ) || ! $this->user_can_upload() ) {
			return $file;
		}

		$path   = (string) ( $file['tmp_name'] ?? '' );
		$result = $this->validator->validate( $path, $ext );
		if ( true === $result ) {
			$this->checked[ $path ] = true;
			return $file;
		}

		$file['error'] = $result->get_error_message();

		/**
		 * Fires when a 3D file is refused because its content is wrong.
		 *
		 * @param array     $file  The upload.
		 * @param \WP_Error $error Why it was refused.
		 */
		do_action( 'embed_3d_models_upload_rejected', $file, $result );

		return $file;
	}

	/**
	 * Accepts 3D files that passed the content check.
	 *
	 * WordPress compares the extension with what PHP's fileinfo reports, and
	 * fileinfo calls these files application/octet-stream, text/plain or
	 * application/zip, so WordPress refuses them. This re-decides only for
	 * the plugin's own extensions, only for allowed types, and only once the
	 * content check passed.
	 *
	 * @param array       $data      ext, type and proper_filename.
	 * @param string      $file      Full path to the file.
	 * @param string      $filename  The file's name.
	 * @param array|null  $mimes     Allowed types.
	 * @param string|bool $real_mime What fileinfo reported.
	 * @return array
	 */
	public function check_filetype( $data, $file, $filename, $mimes = null, $real_mime = false ): array {
		$data   = (array) $data;
		$ext    = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$format = Formats::for_extension( $ext );
		if ( null === $format ) {
			return $data;
		}

		$allowed = is_array( $mimes ) ? $mimes : get_allowed_mime_types();
		if ( ! in_array( $format['mime'], $allowed, true ) ) {
			return $data;
		}

		$path = (string) $file;
		if ( ! isset( $this->checked[ $path ] ) ) {
			$this->checked[ $path ] = true === $this->validator->validate( $path, $ext );
		}
		if ( ! $this->checked[ $path ] ) {
			return array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			);
		}

		return array(
			'ext'             => $ext,
			'type'            => $format['mime'],
			'proper_filename' => $data['proper_filename'] ?? false,
		);
	}

	/**
	 * Converts STL and OBJ (and counts triangles) when WordPress builds the
	 * attachment metadata after an upload.
	 *
	 * @param array $metadata Attachment metadata.
	 * @param int   $id       Attachment ID.
	 * @return array
	 */
	public function generate_metadata( $metadata, $id ): array {
		$metadata = (array) $metadata;
		if ( $this->models->is_model( (int) $id ) ) {
			$metadata[ Models::META_KEY ] = $this->models->process( (int) $id );
		}

		return $metadata;
	}

	/**
	 * Adds "3D models" to the Media Library's type filter.
	 *
	 * @param array $types Types keyed by MIME prefix.
	 * @return array
	 */
	public function library_filter( $types ): array {
		$types          = (array) $types;
		$types['model'] = array(
			__( '3D models', 'embed-3d-models' ),
			__( 'Manage 3D models', 'embed-3d-models' ),
			/* translators: %s: number of 3D models. */
			_n_noop( '3D model <span class="count">(%s)</span>', '3D models <span class="count">(%s)</span>', 'embed-3d-models' ),
		);

		return $types;
	}

	/**
	 * A 3D icon for models without a poster.
	 *
	 * @param string $icon Icon URL.
	 * @param string $mime MIME type.
	 */
	public function icon( $icon, $mime ): string {
		return Formats::is_model_mime( (string) $mime ) ? plugins_url( 'assets/images/model.svg', PLUGIN_FILE ) : (string) $icon;
	}

	/**
	 * Adds model details to the Media Library's data, and shows the poster
	 * as the thumbnail.
	 *
	 * @param array    $response   Attachment data for JavaScript.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function prepare_for_js( $response, $attachment ): array {
		$response = (array) $response;
		$info     = $this->models->info( (int) $attachment->ID );
		if ( null === $info ) {
			return $response;
		}

		$response['embed3dModels'] = array(
			'format'    => $info['format'],
			'label'     => $info['label'],
			'viewerUrl' => $info['viewer_url'],
			'status'    => $info['status'],
			'error'     => $info['error'],
			'triangles' => $info['triangles'],
			'posterId'  => $info['poster_id'],
			'posterUrl' => $info['poster_url'],
		);

		if ( $info['poster_id'] ) {
			$thumb = wp_get_attachment_image_src( $info['poster_id'], 'medium' );
			if ( is_array( $thumb ) ) {
				$image             = array(
					'src'    => $thumb[0],
					'width'  => $thumb[1],
					'height' => $thumb[2],
				);
				$response['image'] = $image;
				$response['thumb'] = $image;
			}
		}

		return $response;
	}

	/**
	 * Whether a user may upload 3D files.
	 *
	 * @param int|\WP_User|null $user User, or null for the current one.
	 */
	private function user_can_upload( $user = null ): bool {
		if ( null === $user ) {
			return current_user_can( self::capability() );
		}

		return user_can( $user, self::capability() );
	}
}
