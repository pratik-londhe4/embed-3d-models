// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, openEditor, wp } = require( './utils' );
const { prepareSite, shot } = require( './utils/media' );
const { torusKnotStl, binaryStl } = require( './utils/models' );

const p = ( text ) => `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

/**
 * Uploads a file as the admin.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @param {string}                                       name    File name.
 * @param {Buffer}                                       buffer  Bytes.
 * @return {Promise<number>} Attachment ID.
 */
async function upload( request, name, buffer ) {
	const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	const response = await request.post( '/index.php?rest_route=%2Fwp%2Fv2%2Fmedia', { headers: { 'X-WP-Nonce': nonce }, multipart: { file: { name, mimeType: 'application/octet-stream', buffer } } } );
	return ( await response.json() ).id;
}

/**
 * Waits until the editor canvas's model has rendered.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function editorModelLoaded( page ) {
	const viewer = page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'model-viewer' ).first();
	await expect.poll( () => viewer.evaluate( ( node ) => node.loaded ).catch( () => false ), { timeout: 60000 } ).toBe( true );
	return viewer;
}

test.describe( 'Screenshots', () => {
	test.use( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } );
	let postId;
	let knotId;

	test.beforeAll( async ( { request } ) => {
		// Leftover uploads would give the files -1 names in the pictures.
	wp( 'site', 'empty', '--uploads', '--yes' );
	prepareSite( 'Maker Notes' );
		knotId = await upload( request, 'torus-knot.stl', torusKnotStl() );
		await upload( request, 'bracket.stl', binaryStl( 40 ) );
		const block = `<!-- wp:embed-3d-models/model {"id":${ knotId },"alt":"A grey torus knot, printed in PLA","caption":"Printed in PLA at 0.2 mm layers.","backgroundColor":"#f0f0f0"} /-->`;
		postId = Number( wp( 'post', 'create', '--post_title=A knot for the shelf', '--post_status=publish', `--post_content=${ p( 'I printed this torus knot last weekend. Turn it around to see how the loops cross.' ) }\n\n${ block }`, '--porcelain' ) );
	} );

	test( '2: the block in the editor, with its settings', async ( { page } ) => {
		await openEditor( page, postId );
		const viewer = await editorModelLoaded( page );
		await viewer.click();
		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		await expect( sidebar.getByLabel( 'Alternative text' ) ).toBeVisible();

		// Capture a poster now, so the front end and the Media Library have one.
		await sidebar.getByRole( 'button', { name: 'Poster and loading' } ).click();
		await sidebar.getByRole( 'button', { name: 'Use current view as poster' } ).click();
		await expect( sidebar.locator( '.e3dm-poster-preview' ) ).toBeVisible( { timeout: 30000 } );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await page.waitForFunction( () => ! window.wp.data.select( 'core/editor' ).isEditedPostDirty() );
		await sidebar.getByRole( 'button', { name: 'Poster and loading' } ).click();
		await page.locator( '.interface-complementary-area' ).evaluate( ( node ) => node.scrollTo( 0, 0 ) );
		await shot( page, 2 );
	} );

	test( '1: a 3D model in a post', async ( { page } ) => {
		await page.goto( `/?p=${ postId }` );
		const front = page.locator( 'figure.e3dm-figure' );
		await front.evaluate( ( node ) => node.scrollIntoView( { block: 'center' } ) );
		await page.waitForFunction( () => document.querySelector( 'model-viewer' ).loaded, null, { timeout: 30000 } );
		await shot( page, 1 );
	} );

	test( '3: an uploaded STL, converted to GLB', async ( { page } ) => {
		await openEditor( page, postId );
		const viewer = await editorModelLoaded( page );
		await viewer.click();
		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		await expect( sidebar.getByText( 'Converted to GLB for display.' ) ).toBeVisible();
		await sidebar.getByRole( 'button', { name: 'Viewer' } ).click();
		await shot( page, 3 );
	} );

	test( '4: 3D models in the Media Library, with posters as thumbnails', async ( { page } ) => {
		await page.goto( '/wp-admin/upload.php?mode=grid' );
		await expect( page.locator( '.attachments .attachment' ).first() ).toBeVisible();
		await page.locator( '#media-attachment-filters' ).selectOption( 'model' );
		await expect( page.locator( '.attachments .attachment' ) ).toHaveCount( 2 );
		await shot( page, 4 );
	} );
} );
