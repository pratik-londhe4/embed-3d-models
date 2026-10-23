<?php
/**
 * Removes everything Embed 3D Models stored. Runs when the plugin is deleted from wp-admin.
 *
 * The plugin stores no options. It adds GLB files converted from STL and OBJ
 * uploads, and an `embed_3d_models` entry in each model's attachment
 * metadata; both are removed here. The uploaded models themselves, and any
 * poster images, are the site's media and stay.
 *
 * @package PratikLondhe\Embed3DModels
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's files and metadata on the current site.
 */
function embed_3d_models_uninstall_site(): void {
	$page = 1;
	do {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => 'model/gltf-binary,model/gltf+json,model/vnd.usdz+zip,model/stl,model/obj',
				'posts_per_page'   => 100,
				'paged'            => $page,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		foreach ( $ids as $id ) {
			$metadata = get_post_meta( (int) $id, '_wp_attachment_metadata', true );
			if ( ! is_array( $metadata ) || ! isset( $metadata['embed_3d_models'] ) ) {
				continue;
			}

			$name = (string) ( $metadata['embed_3d_models']['glb'] ?? '' );
			$file = (string) get_attached_file( (int) $id );
			if ( '' !== $name && '' !== $file ) {
				$path = trailingslashit( dirname( $file ) ) . wp_basename( $name );
				if ( '.glb' === strtolower( substr( $path, -4 ) ) && file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}

			unset( $metadata['embed_3d_models'] );
			wp_update_attachment_metadata( (int) $id, $metadata );
		}

		++$page;
		$found = count( $ids );
	} while ( 100 === $found );
}

if ( is_multisite() ) {
	$embed_3d_models_page = 1;
	do {
		$embed_3d_models_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => ( $embed_3d_models_page - 1 ) * 100,
			)
		);
		foreach ( $embed_3d_models_sites as $embed_3d_models_site ) {
			switch_to_blog( (int) $embed_3d_models_site );
			embed_3d_models_uninstall_site();
			restore_current_blog();
		}
		++$embed_3d_models_page;
		$embed_3d_models_found = count( $embed_3d_models_sites );
	} while ( 100 === $embed_3d_models_found );
} else {
	embed_3d_models_uninstall_site();
}
