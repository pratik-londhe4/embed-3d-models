<?php
/**
 * 3D model attachments.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

use PratikLondhe\Embed3DModels\Formats;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, converts and describes 3D model attachments. Used by the upload
 * hooks, the block, REST, WP-CLI and the abilities.
 *
 * What the plugin knows about a model is stored in the attachment metadata
 * under `embed_3d_models`: status, triangle count, the converted GLB's file
 * name and any error.
 */
final class Models {

	public const META_KEY = 'embed_3d_models';

	/**
	 * Default size limit for converting at upload: 64 MB.
	 */
	private const MAX_CONVERT_BYTES = 67108864;

	/**
	 * Converts STL and OBJ files.
	 *
	 * @var Converter
	 */
	private Converter $converter;

	/**
	 * Sets up the repository.
	 *
	 * @param Converter $converter Converter.
	 */
	public function __construct( Converter $converter ) {
		$this->converter = $converter;
	}

	/**
	 * Whether an attachment is a 3D model.
	 *
	 * @param int $id Attachment ID.
	 */
	public function is_model( int $id ): bool {
		return $id > 0 && 'attachment' === get_post_type( $id ) && Formats::is_model_mime( (string) get_post_mime_type( $id ) );
	}

	/**
	 * Works out the plugin's metadata for a model: converts STL and OBJ,
	 * counts triangles for glTF. Does not save it.
	 *
	 * @param int  $id           Attachment ID.
	 * @param bool $ignore_limit Convert files over the size limit too.
	 * @return array Plugin metadata.
	 */
	public function process( int $id, bool $ignore_limit = false ): array {
		$file   = (string) get_attached_file( $id );
		$ext    = (string) Formats::extension_for_mime( (string) get_post_mime_type( $id ) );
		$format = Formats::for_extension( $ext );
		$meta   = array(
			'format' => $ext,
			'status' => 'ready',
		);

		if ( null === $format || '' === $file || ! is_readable( $file ) ) {
			$meta['status'] = 'failed';
			$meta['error']  = Formats::message( 'missing_file', __( 'The model file is missing from the uploads folder.', 'embed-3d-models' ) );
			return $meta;
		}

		if ( ! $format['convert'] ) {
			$json = 'usdz' === $ext ? null : Glb::json( $file );
			if ( null !== $json ) {
				$meta['triangles'] = Glb::triangles( $json );
			}
			return $meta;
		}

		$this->delete_derived( $id );

		if ( ! $ignore_limit && (int) filesize( $file ) > $this->max_convert_bytes() ) {
			$meta['status'] = 'too_large';
			$meta['error']  = Formats::message( 'too_large', __( 'This file is too large to convert while uploading. Ask an administrator to run: wp embed-3d-models convert', 'embed-3d-models' ) . ' ' . $id );
			return $meta;
		}

		$dest   = $this->derived_path( $file, $ext );
		$result = $this->converter->convert( $file, $ext, $dest );

		if ( is_wp_error( $result ) ) {
			$meta['status'] = 'failed';
			$meta['error']  = $result->get_error_message();

			/**
			 * Fires when an STL or OBJ file could not be converted to GLB.
			 *
			 * @param int       $id    Attachment ID.
			 * @param \WP_Error $error What went wrong.
			 */
			do_action( 'embed_3d_models_conversion_failed', $id, $result );

			return $meta;
		}

		$meta['status']    = 'converted';
		$meta['glb']       = wp_basename( $dest );
		$meta['glb_size']  = (int) filesize( $dest );
		$meta['triangles'] = $result['triangles'];
		if ( $result['skipped'] > 0 ) {
			$meta['skipped'] = $result['skipped'];
		}

		/**
		 * Fires after an STL or OBJ file was converted to GLB.
		 *
		 * @param int    $id   Attachment ID.
		 * @param array  $meta The plugin's metadata for the model.
		 * @param string $dest Path of the GLB.
		 */
		do_action( 'embed_3d_models_converted', $id, $meta, $dest );

		return $meta;
	}

	/**
	 * Processes a model again and saves the result.
	 *
	 * @param int  $id           Attachment ID.
	 * @param bool $ignore_limit Convert files over the size limit too.
	 * @return array|\WP_Error Model info.
	 */
	public function reprocess( int $id, bool $ignore_limit = false ) {
		if ( ! $this->is_model( $id ) ) {
			return $this->not_a_model();
		}

		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$metadata[ self::META_KEY ] = $this->process( $id, $ignore_limit );
		wp_update_attachment_metadata( $id, $metadata );

		return (array) $this->info( $id );
	}

	/**
	 * Everything the block, REST, WP-CLI and abilities need about a model.
	 *
	 * @param int $id Attachment ID.
	 * @return array|null Null when it is not a model.
	 */
	public function info( int $id ): ?array {
		if ( ! $this->is_model( $id ) ) {
			return null;
		}

		$mime     = (string) get_post_mime_type( $id );
		$ext      = (string) Formats::extension_for_mime( $mime );
		$format   = (array) Formats::for_extension( $ext );
		$metadata = $this->raw_metadata( $id );
		$meta     = isset( $metadata[ self::META_KEY ] ) && is_array( $metadata[ self::META_KEY ] ) ? $metadata[ self::META_KEY ] : array();
		$url      = (string) wp_get_attachment_url( $id );
		$status   = (string) ( $meta['status'] ?? ( $format['convert'] ? 'pending' : 'ready' ) );
		$viewer   = '';

		if ( 'converted' === $status && ! empty( $meta['glb'] ) ) {
			$viewer = trailingslashit( dirname( $url ) ) . rawurlencode( (string) $meta['glb'] );
		} elseif ( ! $format['convert'] && 'usdz' !== $ext ) {
			$viewer = $url;
		}

		$poster_id = (int) get_post_meta( $id, '_thumbnail_id', true );

		return array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'format'     => $ext,
			'label'      => (string) ( $format['label'] ?? strtoupper( $ext ) ),
			'mime'       => $mime,
			'url'        => $url,
			'viewer_url' => $viewer,
			'status'     => $status,
			'error'      => (string) ( $meta['error'] ?? '' ),
			'triangles'  => isset( $meta['triangles'] ) ? (int) $meta['triangles'] : null,
			'filesize'   => (int) ( $metadata['filesize'] ?? 0 ),
			'poster_id'  => $poster_id > 0 && wp_attachment_is_image( $poster_id ) ? $poster_id : 0,
			'poster_url' => $poster_id > 0 ? (string) wp_get_attachment_image_url( $poster_id, 'large' ) : '',
		);
	}

	/**
	 * Lists models, newest first.
	 *
	 * @param int    $limit  Maximum number, 1 to 100.
	 * @param string $format Only this format (extension), or empty for all.
	 * @return array[] Model info.
	 */
	public function all( int $limit = 20, string $format = '' ): array {
		$mimes = array();
		foreach ( Formats::all() as $ext => $item ) {
			if ( '' === $format || $ext === $format ) {
				$mimes[] = $item['mime'];
			}
		}
		if ( ! $mimes ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => implode( ',', $mimes ),
				'posts_per_page'   => max( 1, min( 100, $limit ) ),
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		return array_values( array_filter( array_map( array( $this, 'info' ), array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Sets (or with 0, removes) a model's poster image, which the Media
	 * Library also uses as its thumbnail.
	 *
	 * @param int $id        Model attachment ID.
	 * @param int $poster_id Image attachment ID, or 0.
	 * @return array|\WP_Error Model info.
	 */
	public function set_poster( int $id, int $poster_id ) {
		if ( ! $this->is_model( $id ) ) {
			return $this->not_a_model();
		}

		if ( 0 === $poster_id ) {
			delete_post_meta( $id, '_thumbnail_id' );
		} elseif ( wp_attachment_is_image( $poster_id ) ) {
			update_post_meta( $id, '_thumbnail_id', $poster_id );
		} else {
			return new \WP_Error( 'embed_3d_models_not_an_image', Formats::message( 'not_an_image', __( 'The poster must be an image from the Media Library.', 'embed-3d-models' ) ), array( 'status' => 400 ) );
		}

		/**
		 * Fires after a model's poster image was set or removed.
		 *
		 * @param int $id        Model attachment ID.
		 * @param int $poster_id Image attachment ID, or 0 when removed.
		 */
		do_action( 'embed_3d_models_poster_set', $id, $poster_id );

		return (array) $this->info( $id );
	}

	/**
	 * Deletes the GLB converted from a model, if there is one.
	 *
	 * @param int $id Attachment ID.
	 */
	public function delete_derived( int $id ): void {
		$metadata = $this->raw_metadata( $id );
		$name     = isset( $metadata[ self::META_KEY ]['glb'] ) ? (string) $metadata[ self::META_KEY ]['glb'] : '';
		$file     = (string) get_attached_file( $id );
		if ( '' === $name || '' === $file ) {
			return;
		}

		// Only ever a file next to the original, never a path from elsewhere.
		$path = trailingslashit( dirname( $file ) ) . wp_basename( $name );
		if ( '.glb' !== strtolower( substr( $path, -4 ) ) || ! file_exists( $path ) ) {
			return;
		}

		wp_delete_file( $path );

		/**
		 * Fires after the GLB converted from a model was deleted.
		 *
		 * @param int    $id   Attachment ID.
		 * @param string $path Deleted file.
		 */
		do_action( 'embed_3d_models_derived_deleted', $id, $path );
	}

	/**
	 * Largest file converted at upload, in bytes.
	 */
	public function max_convert_bytes(): int {
		/**
		 * Filters the largest STL or OBJ file converted while it is uploaded.
		 * Larger files are stored but not converted; WP-CLI can convert them.
		 *
		 * @param int $bytes Size in bytes. Default 64 MB.
		 */
		$bytes = (int) apply_filters( 'embed_3d_models_max_convert_bytes', self::MAX_CONVERT_BYTES );

		return $bytes > 0 ? $bytes : self::MAX_CONVERT_BYTES;
	}

	/**
	 * Where the GLB for a source file goes: `name.stl` becomes `name-stl.glb`.
	 *
	 * @param string $file Source file.
	 * @param string $ext  Source format.
	 */
	private function derived_path( string $file, string $ext ): string {
		$dir  = dirname( $file );
		$name = wp_basename( $file, '.' . pathinfo( $file, PATHINFO_EXTENSION ) ) . '-' . $ext . '.glb';

		return trailingslashit( $dir ) . wp_unique_filename( $dir, $name );
	}

	/**
	 * The attachment metadata as stored.
	 *
	 * Read from post meta, because wp_get_attachment_metadata() is typed for
	 * images and the plugin only needs its own key and the file size.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function raw_metadata( int $id ): array {
		$metadata = get_post_meta( $id, '_wp_attachment_metadata', true );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * The error for an ID that is not a model.
	 */
	private function not_a_model(): \WP_Error {
		return new \WP_Error( 'embed_3d_models_not_a_model', Formats::message( 'not_a_model', __( 'That attachment is not a 3D model.', 'embed-3d-models' ) ), array( 'status' => 404 ) );
	}
}
