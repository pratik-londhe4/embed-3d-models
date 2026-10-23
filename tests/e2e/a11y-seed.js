// Seeds a post with a 3D Model block for the accessibility checks (a11y-screens.json).
const { wp } = require( './utils' );
const { torusKnotStl } = require( './utils/models' );

/**
 * Uploads an STL and creates a post showing it.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context, logged in as the admin.
 * @param {boolean}                                      empty   Create an empty block instead.
 * @return {Promise<string>} Post ID.
 */
async function seed( request, empty = false ) {
	let block = '<!-- wp:embed-3d-models/model /-->';
	if ( ! empty ) {
		const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const response = await request.post( '/index.php?rest_route=%2Fwp%2Fv2%2Fmedia', {
			headers: { 'X-WP-Nonce': nonce },
			multipart: { file: { name: 'a11y-knot.stl', mimeType: 'application/octet-stream', buffer: torusKnotStl( 80, 8 ) } },
		} );
		const id = ( await response.json() ).id;
		block = `<!-- wp:embed-3d-models/model {"id":${ id },"alt":"A torus knot","caption":"Printed in PLA.","reveal":"manual"} /-->`;
	}
	return wp( 'post', 'create', '--post_title=Accessibility', '--post_status=publish', `--post_content=${ block }`, '--porcelain' );
}

module.exports = { seed };
