<?php
/**
 * Checks that a 3D file is what its extension says.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

use PratikLondhe\Embed3DModels\Formats;

defined( 'ABSPATH' ) || exit;

/**
 * Content checks per format, so a renamed file or a file carrying code is
 * refused before WordPress stores it.
 */
final class Validator {

	/**
	 * Bytes read per chunk when scanning text formats.
	 */
	private const CHUNK = 1048576;

	/**
	 * Largest glTF JSON the plugin decodes, in bytes.
	 */
	private const MAX_JSON = 134217728;

	/**
	 * Checks a file.
	 *
	 * @param string $path File on disk (usually the upload's temp file).
	 * @param string $ext  The extension it was uploaded with.
	 * @return true|\WP_Error
	 */
	public function validate( string $path, string $ext ) {
		$ext    = strtolower( $ext );
		$format = Formats::for_extension( $ext );
		if ( null === $format ) {
			return $this->error( 'unsupported', __( 'This is not a 3D file type Embed 3D Models accepts.', 'embed-3d-models' ) );
		}

		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		if ( $size < 1 ) {
			return $this->error( 'empty', __( 'The file is empty or could not be read.', 'embed-3d-models' ) );
		}

		switch ( $ext ) {
			case 'glb':
				$result = $this->check_glb( $path, $size );
				break;
			case 'gltf':
				$result = $this->check_gltf( $path, $size );
				break;
			case 'usdz':
				$result = $this->check_usdz( $path );
				break;
			case 'stl':
				$result = $this->check_stl( $path, $size );
				break;
			default:
				$result = $this->check_obj( $path );
		}

		/**
		 * Filters the result of a 3D file check. Return true to accept the
		 * file, or a WP_Error (or false) to refuse it.
		 *
		 * @param bool|\WP_Error $result The plugin's verdict: true or an error.
		 * @param string         $path   File on disk.
		 * @param string         $ext    File extension.
		 */
		$filtered = apply_filters( 'embed_3d_models_validate_file', $result, $path, $ext );
		if ( true === $filtered || $filtered instanceof \WP_Error ) {
			return $filtered;
		}

		return $this->error( 'refused', __( 'This file was refused by a check on this site.', 'embed-3d-models' ) );
	}

	/**
	 * Binary glTF: header, version, length and JSON chunk.
	 *
	 * @param string $path File path.
	 * @param int    $size File size.
	 * @return true|\WP_Error
	 */
	private function check_glb( string $path, int $size ) {
		$invalid = $this->error( 'invalid_glb', __( 'This file is not a valid GLB (binary glTF 2.0) model.', 'embed-3d-models' ) );

		$handle = Stream::open( $path, 'rb' );
		if ( null === $handle ) {
			return $invalid;
		}
		$header = Stream::read( $handle, 20 );
		if ( 20 !== strlen( $header ) || 'glTF' !== substr( $header, 0, 4 ) ) {
			Stream::close( $handle );
			return $invalid;
		}

		$fields = unpack( 'Vversion/Vlength/Vchunk/Vtype', substr( $header, 4 ) );
		if ( ! is_array( $fields ) || 2 !== $fields['version'] || $size !== $fields['length'] || 0x4E4F534A !== $fields['type'] || $fields['chunk'] < 2 || $fields['chunk'] > min( self::MAX_JSON, $size - 20 ) ) {
			Stream::close( $handle );
			return $invalid;
		}

		$json = Stream::read( $handle, (int) $fields['chunk'] );
		Stream::close( $handle );

		return $this->check_gltf_json( $json, $invalid );
	}

	/**
	 * JSON glTF: version 2 with everything embedded.
	 *
	 * @param string $path File path.
	 * @param int    $size File size.
	 * @return true|\WP_Error
	 */
	private function check_gltf( string $path, int $size ) {
		$invalid = $this->error( 'invalid_gltf', __( 'This file is not a valid glTF 2.0 model.', 'embed-3d-models' ) );
		if ( $size > self::MAX_JSON ) {
			return $this->error( 'gltf_too_large', __( 'This glTF file is too large to check. Upload it as a .glb instead.', 'embed-3d-models' ) );
		}

		$code = $this->scan_text( $path );
		if ( $code['code'] ) {
			return $this->code_error();
		}

		$handle = Stream::open( $path, 'rb' );
		if ( null === $handle ) {
			return $invalid;
		}
		$json = Stream::read( $handle, $size );
		Stream::close( $handle );

		return $this->check_gltf_json( $json, $invalid );
	}

	/**
	 * Shared glTF JSON checks.
	 *
	 * @param string    $json    JSON text.
	 * @param \WP_Error $invalid Error for malformed files.
	 * @return true|\WP_Error
	 */
	private function check_gltf_json( string $json, \WP_Error $invalid ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['asset']['version'] ) || 0 !== strpos( (string) $data['asset']['version'], '2.' ) ) {
			return $invalid;
		}

		/**
		 * Filters whether glTF files may point to separate files (a .bin buffer
		 * or texture images) instead of embedding them. Off by default: the
		 * Media Library uploads one file at a time, so the separate files
		 * would be missing and the model would not load.
		 *
		 * @param bool $allow Whether to allow external resources.
		 */
		if ( apply_filters( 'embed_3d_models_allow_external_resources', false ) ) {
			return true;
		}

		foreach ( array( 'buffers', 'images' ) as $list ) {
			foreach ( (array) ( $data[ $list ] ?? array() ) as $item ) {
				$uri = is_array( $item ) && isset( $item['uri'] ) ? (string) $item['uri'] : '';
				if ( '' !== $uri && 0 !== strpos( $uri, 'data:' ) ) {
					return $this->error( 'external_resources', __( 'This glTF file points to separate files (such as a .bin file or textures) that cannot be uploaded with it. Export the model as a single .glb file and upload that instead.', 'embed-3d-models' ) );
				}
			}
		}

		return true;
	}

	/**
	 * USDZ: a zip whose first entry is a USD file.
	 *
	 * @param string $path File path.
	 * @return true|\WP_Error
	 */
	private function check_usdz( string $path ) {
		$head = Stream::head( $path, 30 + 1024 );
		if ( strlen( $head ) >= 30 && "PK\x03\x04" === substr( $head, 0, 4 ) ) {
			$lengths = unpack( 'vname', substr( $head, 26, 2 ) );
			$name    = is_array( $lengths ) ? substr( $head, 30, (int) $lengths['name'] ) : '';
			if ( preg_match( '/\.usd[ac]?$/i', $name ) ) {
				return true;
			}
		}

		return $this->error( 'invalid_usdz', __( 'This file is not a valid USDZ model.', 'embed-3d-models' ) );
	}

	/**
	 * STL: binary (exact size) or ASCII (solid/facet/vertex).
	 *
	 * @param string $path File path.
	 * @param int    $size File size.
	 * @return true|\WP_Error
	 */
	private function check_stl( string $path, int $size ) {
		if ( self::is_binary_stl( $path, $size ) ) {
			return true;
		}

		$head = ltrim( Stream::head( $path, 4096 ) );
		if ( 0 === stripos( $head, 'solid' ) && false === strpos( $head, "\0" ) ) {
			$scan = $this->scan_text( $path, array( 'facet', 'vertex' ) );
			if ( $scan['code'] ) {
				return $this->code_error();
			}
			if ( $scan['found']['facet'] && $scan['found']['vertex'] ) {
				return true;
			}
		}

		return $this->error( 'invalid_stl', __( 'This file is not a valid STL model.', 'embed-3d-models' ) );
	}

	/**
	 * OBJ: text with vertices and faces.
	 *
	 * @param string $path File path.
	 * @return true|\WP_Error
	 */
	private function check_obj( string $path ) {
		$scan = $this->scan_text( $path, array( "\nv ", "\nf " ) );
		if ( $scan['code'] ) {
			return $this->code_error();
		}
		if ( ! $scan['binary'] && $scan['found']["\nv "] && $scan['found']["\nf "] ) {
			return true;
		}

		return $this->error( 'invalid_obj', __( 'This file is not a valid OBJ model.', 'embed-3d-models' ) );
	}

	/**
	 * Whether a file is a binary STL: 80-byte header, triangle count, 50 bytes per triangle.
	 *
	 * @param string $path File path.
	 * @param int    $size File size.
	 */
	public static function is_binary_stl( string $path, int $size ): bool {
		if ( $size < 134 ) {
			return false;
		}
		$head  = Stream::head( $path, 84 );
		$count = strlen( $head ) === 84 ? unpack( 'Vcount', substr( $head, 80, 4 ) ) : false;

		return is_array( $count ) && $count['count'] > 0 && 84 + 50 * $count['count'] === $size;
	}

	/**
	 * Scans a text file in chunks for embedded code, NUL bytes and markers.
	 *
	 * Lines in OBJ files start with the marker, so the scan treats the start
	 * of the file as a line break.
	 *
	 * @param string   $path    File path.
	 * @param string[] $markers Strings to look for.
	 * @return array{code: bool, binary: bool, found: array<string, bool>}
	 */
	private function scan_text( string $path, array $markers = array() ): array {
		$result = array(
			'code'   => false,
			'binary' => false,
			'found'  => array_fill_keys( $markers, false ),
		);

		$handle = Stream::open( $path, 'rb' );
		if ( null === $handle ) {
			$result['binary'] = true;
			return $result;
		}

		$carry = "\n";
		while ( ! feof( $handle ) ) {
			$chunk = Stream::read( $handle, self::CHUNK );
			if ( '' === $chunk ) {
				break;
			}
			$text = $carry . str_replace( "\r", "\n", $chunk );

			if ( false !== strpos( $chunk, "\0" ) ) {
				$result['binary'] = true;
			}
			if ( preg_match( '/<\?(php|=)|<script/i', $text ) ) {
				$result['code'] = true;
				break;
			}
			foreach ( $markers as $marker ) {
				if ( ! $result['found'][ $marker ] && false !== stripos( $text, $marker ) ) {
					$result['found'][ $marker ] = true;
				}
			}

			// Keep the tail so a marker split across two chunks is still found.
			$carry = substr( $text, -16 );
		}
		Stream::close( $handle );

		return $result;
	}

	/**
	 * The error for a text model carrying code.
	 */
	private function code_error(): \WP_Error {
		return $this->error( 'embedded_code', __( 'This file contains code (PHP or a script tag), which a 3D model never needs. It was not uploaded.', 'embed-3d-models' ) );
	}

	/**
	 * Builds an error with a filterable message.
	 *
	 * @param string $key     Error key.
	 * @param string $message Default message.
	 */
	private function error( string $key, string $message ): \WP_Error {
		return new \WP_Error( 'embed_3d_models_' . $key, Formats::message( $key, $message ), array( 'status' => 400 ) );
	}
}
