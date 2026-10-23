<?php
/**
 * Plain file streams.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrappers around fopen() and friends.
 *
 * Model files can be hundreds of megabytes, so they are read and written in
 * chunks. WP_Filesystem has no streaming API, and these files are always
 * local (the upload's temp file or a file in the uploads folder).
 */
final class Stream {

	/**
	 * Opens a file.
	 *
	 * @param string $path File path.
	 * @param string $mode fopen() mode.
	 * @return resource|null
	 */
	public static function open( string $path, string $mode ) {
		// A missing file is reported by the caller, hence the @.
		$handle = @fopen( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streaming large local model files, see class comment.

		return is_resource( $handle ) ? $handle : null;
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param resource $handle File handle.
	 * @param int      $length Bytes.
	 */
	public static function read( $handle, int $length ): string {
		if ( $length < 1 ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see class comment.
		$data = fread( $handle, $length );

		return is_string( $data ) ? $data : '';
	}

	/**
	 * Reads one line, or null at the end of the file.
	 *
	 * @param resource $handle File handle.
	 */
	public static function line( $handle ): ?string {
		$line = fgets( $handle );

		return false === $line ? null : $line;
	}

	/**
	 * Writes bytes.
	 *
	 * @param resource $handle File handle.
	 * @param string   $data   Bytes.
	 */
	public static function write( $handle, string $data ): bool {
		if ( '' === $data ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- see class comment; paths are the upload temp dir or the uploads folder.
		return strlen( $data ) === fwrite( $handle, $data );
	}

	/**
	 * Closes a handle.
	 *
	 * @param resource|null $handle File handle.
	 */
	public static function close( $handle ): void {
		if ( is_resource( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see class comment.
			fclose( $handle );
		}
	}

	/**
	 * The first bytes of a file.
	 *
	 * @param string $path   File path.
	 * @param int    $length Bytes.
	 */
	public static function head( string $path, int $length ): string {
		$handle = self::open( $path, 'rb' );
		if ( null === $handle ) {
			return '';
		}
		$data = self::read( $handle, $length );
		self::close( $handle );

		return $data;
	}
}
