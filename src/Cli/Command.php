<?php
/**
 * `wp embed-3d-models` commands.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels\Cli;

use PratikLondhe\Embed3DModels\Formats;
use PratikLondhe\Embed3DModels\Media\Models;
use PratikLondhe\Embed3DModels\Media\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, inspects and converts 3D models, sets posters and checks files.
 */
final class Command {

	/**
	 * Model attachments.
	 *
	 * @var Models
	 */
	private Models $models;

	/**
	 * Content checks.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * Sets up the command.
	 *
	 * @param Models    $models    Model attachments.
	 * @param Validator $validator Content checks.
	 */
	public function __construct( Models $models, Validator $validator ) {
		$this->models    = $models;
		$this->validator = $validator;
	}

	/**
	 * Lists 3D models, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--type=<type>]
	 * : Only this file type: glb, gltf, usdz, stl or obj.
	 *
	 * [--limit=<limit>]
	 * : At most this many, up to 100.
	 * ---
	 * default: 100
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array_map( array( $this, 'row' ), $this->models->all( (int) ( $assoc_args['limit'] ?? 100 ), (string) ( $assoc_args['type'] ?? '' ) ) );

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'title', 'format', 'status', 'triangles', 'poster_id' ) );
	}

	/**
	 * Shows one model.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function get( array $args, array $assoc_args ): void {
		$info = $this->models->info( (int) $args[0] );
		if ( null === $info ) {
			\WP_CLI::error( 'Attachment ' . (int) $args[0] . ' is not a 3D model.' );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'table' === $format ) {
			$rows = array();
			foreach ( (array) $info as $field => $value ) {
				$rows[] = array(
					'field' => $field,
					'value' => is_scalar( $value ) ? (string) $value : '',
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, array( $info ), array_keys( (array) $info ) );
	}

	/**
	 * Converts STL and OBJ models to GLB again (and counts triangles for glTF).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs.
	 *
	 * [--ignore-limit]
	 * : Convert files larger than the upload conversion limit too.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function convert( array $args, array $assoc_args ): void {
		$failed = 0;
		foreach ( $args as $id ) {
			$result = $this->models->reprocess( (int) $id, ! empty( $assoc_args['ignore-limit'] ) );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $id . ': ' . $result->get_error_message() );
				++$failed;
			} elseif ( in_array( $result['status'], array( 'failed', 'too_large' ), true ) ) {
				\WP_CLI::warning( $id . ': ' . $result['error'] );
				++$failed;
			} else {
				\WP_CLI::log( $id . ': ' . $result['status'] . ( null === $result['triangles'] ? '' : ', ' . number_format( $result['triangles'] ) . ' triangles' ) );
			}
		}

		if ( $failed ) {
			\WP_CLI::error( $failed . ' of ' . count( $args ) . ' not converted.' );
		}
		\WP_CLI::success( count( $args ) . ' processed.' );
	}

	/**
	 * Sets a model's poster image (also its Media Library thumbnail).
	 *
	 * ## OPTIONS
	 *
	 * <model-id>
	 * : Model attachment ID.
	 *
	 * <image-id>
	 * : Image attachment ID, or 0 to remove the poster.
	 *
	 * @subcommand set-poster
	 *
	 * @param array $args Positional arguments.
	 */
	public function set_poster( array $args ): void {
		$result = $this->models->set_poster( (int) $args[0], (int) $args[1] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( (int) $args[1] ? 'Poster set.' : 'Poster removed.' );
	}

	/**
	 * Checks whether a file on disk would be accepted as a 3D model upload.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the file.
	 *
	 * @param array $args Positional arguments.
	 */
	public function check( array $args ): void {
		$path   = (string) $args[0];
		$result = $this->validator->validate( $path, (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'This file would be accepted.' );
	}

	/**
	 * Lists the accepted 3D file types.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function formats( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Formats::all() as $ext => $format ) {
			$rows[] = array(
				'extension' => $ext,
				'label'     => $format['label'],
				'mime'      => $format['mime'],
				'converted' => $format['convert'] ? 'to GLB' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'extension', 'label', 'mime', 'converted' ) );
	}

	/**
	 * A list row.
	 *
	 * @param array $info Model info.
	 * @return array
	 */
	private function row( array $info ): array {
		return array(
			'id'        => $info['id'],
			'title'     => $info['title'],
			'format'    => $info['label'],
			'status'    => $info['status'],
			'triangles' => null === $info['triangles'] ? '' : $info['triangles'],
			'poster_id' => $info['poster_id'] ? $info['poster_id'] : '',
		);
	}
}
