<?php
/**
 * The 3D file formats the plugin accepts.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels;

defined( 'ABSPATH' ) || exit;

/**
 * One list of formats, used by uploads, validation, conversion and the block.
 */
final class Formats {

	/**
	 * Formats keyed by file extension.
	 *
	 * Each has `mime` (the type WordPress stores), `label` (shown to people),
	 * `convert` (converted to GLB at upload) and `text` (a text format, checked
	 * for embedded code).
	 *
	 * @return array<string, array{mime: string, label: string, convert: bool, text: bool}>
	 */
	public static function all(): array {
		$defaults = array(
			'glb'  => array(
				'mime'    => 'model/gltf-binary',
				'label'   => 'GLB',
				'convert' => false,
				'text'    => false,
			),
			'gltf' => array(
				'mime'    => 'model/gltf+json',
				'label'   => 'glTF',
				'convert' => false,
				'text'    => true,
			),
			'usdz' => array(
				'mime'    => 'model/vnd.usdz+zip',
				'label'   => 'USDZ',
				'convert' => false,
				'text'    => false,
			),
			'stl'  => array(
				'mime'    => 'model/stl',
				'label'   => 'STL',
				'convert' => true,
				'text'    => false,
			),
			'obj'  => array(
				'mime'    => 'model/obj',
				'label'   => 'OBJ',
				'convert' => true,
				'text'    => true,
			),
		);

		/**
		 * Filters the accepted 3D formats. Remove a key to stop accepting that
		 * format. Only these five formats have content checks; unknown keys are
		 * ignored.
		 *
		 * @param array $formats Formats keyed by extension.
		 */
		$formats = (array) apply_filters( 'embed_3d_models_formats', $defaults );
		$result  = array();
		foreach ( $defaults as $ext => $format ) {
			if ( isset( $formats[ $ext ] ) && is_array( $formats[ $ext ] ) ) {
				$result[ $ext ] = array(
					'mime'    => (string) ( $formats[ $ext ]['mime'] ?? $format['mime'] ),
					'label'   => (string) ( $formats[ $ext ]['label'] ?? $format['label'] ),
					'convert' => $format['convert'],
					'text'    => $format['text'],
				);
			}
		}

		return $result;
	}

	/**
	 * The format for a file extension.
	 *
	 * @param string $ext File extension, any case.
	 * @return array{mime: string, label: string, convert: bool, text: bool}|null
	 */
	public static function for_extension( string $ext ): ?array {
		$all = self::all();
		$ext = strtolower( $ext );

		return $all[ $ext ] ?? null;
	}

	/**
	 * The extension for a stored MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string|null
	 */
	public static function extension_for_mime( string $mime ): ?string {
		foreach ( self::all() as $ext => $format ) {
			if ( $format['mime'] === $mime ) {
				return $ext;
			}
		}

		return null;
	}

	/**
	 * Whether a MIME type is one of the plugin's 3D types.
	 *
	 * @param string $mime MIME type.
	 */
	public static function is_model_mime( string $mime ): bool {
		return null !== self::extension_for_mime( $mime );
	}

	/**
	 * Formats the viewer can show directly (no conversion).
	 *
	 * @return string[] MIME types.
	 */
	public static function viewable_mimes(): array {
		$mimes = array();
		foreach ( self::all() as $ext => $format ) {
			if ( 'usdz' !== $ext ) {
				$mimes[] = $format['mime'];
			}
		}

		return $mimes;
	}

	/**
	 * A user-facing message, filterable.
	 *
	 * @param string $key     Message key.
	 * @param string $message Default text.
	 */
	public static function message( string $key, string $message ): string {
		/**
		 * Filters a message the plugin shows to people.
		 *
		 * @param string $message Message text.
		 * @param string $key     Which message, e.g. "invalid_glb".
		 */
		$filtered = (string) apply_filters( 'embed_3d_models_message', $message, $key );

		return '' !== $filtered ? $filtered : $message;
	}
}
