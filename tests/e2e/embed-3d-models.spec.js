// End-to-end tests: uploads, conversion, the block in the editor and on the
// front end, posters, cleanup, WP-CLI, abilities and Site Health. Every test
// checks the database or the uploads folder through WP-CLI, not just the screen.
const { test, expect, wp, wpEval, openEditor, logInAs } = require( './utils' );
const { binaryStl, glb } = require( './utils/models' );

/**
 * Uploads a file through the REST API as the logged-in admin.
 *
 * @param {import('@playwright/test').APIRequestContext} request  Request context.
 * @param {string}                                       name     File name.
 * @param {Buffer}                                       buffer   Bytes.
 * @param {string}                                       mimeType What the browser would send.
 * @return {Promise<{status: number, body: Object}>} Response.
 */
async function upload( request, name, buffer, mimeType = 'application/octet-stream' ) {
	const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
	const response = await request.post( '/index.php?rest_route=' + encodeURIComponent( '/wp/v2/media' ), {
		headers: { 'X-WP-Nonce': nonce },
		multipart: { file: { name, mimeType, buffer } },
	} );
	return { status: response.status(), body: await response.json().catch( () => null ) };
}

/**
 * The plugin's metadata for an attachment, read from the database.
 *
 * @param {number} id Attachment ID.
 * @return {Object|null} Metadata.
 */
function pluginMeta( id ) {
	return JSON.parse( wpEval( `$m = wp_get_attachment_metadata( ${ Number( id ) } ); echo wp_json_encode( $m['embed_3d_models'] ?? null );` ) );
}

/**
 * Whether a file exists next to an attachment's file.
 *
 * @param {number} id   Attachment ID.
 * @param {string} name File name.
 * @return {boolean} Exists.
 */
function siblingExists( id, name ) {
	return '1' === wpEval( `echo (int) file_exists( dirname( get_attached_file( ${ Number( id ) } ) ) . '/${ name }' );` );
}

function attachmentCount() {
	return Number( wp( 'post', 'list', '--post_type=attachment', '--post_status=inherit', '--format=count' ) );
}

test.describe( 'Uploads', () => {
	test( 'a GLB upload is stored as a 3D model with its triangle count', async ( { request } ) => {
		const { status, body } = await upload( request, 'triangle.glb', glb() );

		expect( status ).toBe( 201 );
		expect( wp( 'post', 'get', String( body.id ), '--field=post_mime_type' ) ).toBe( 'model/gltf-binary' );
		expect( pluginMeta( body.id ) ).toMatchObject( { format: 'glb', status: 'ready', triangles: 1 } );
	} );

	test( 'an STL upload is converted to a GLB next to it', async ( { request } ) => {
		const { status, body } = await upload( request, 'bracket.stl', binaryStl( 12 ), 'application/vnd.ms-pki.stl' );

		expect( status ).toBe( 201 );
		expect( wp( 'post', 'get', String( body.id ), '--field=post_mime_type' ) ).toBe( 'model/stl' );
		const meta = pluginMeta( body.id );
		expect( meta ).toMatchObject( { format: 'stl', status: 'converted', triangles: 12 } );
		expect( siblingExists( body.id, meta.glb ) ).toBe( true );
		// The GLB is a real binary glTF.
		expect( wpEval( `echo substr( file_get_contents( dirname( get_attached_file( ${ body.id } ) ) . '/${ meta.glb }' ), 0, 4 );` ) ).toBe( 'glTF' );
	} );

	test( 'a renamed text file is refused with a reason and not stored', async ( { request } ) => {
		const before = attachmentCount();
		const { status, body } = await upload( request, 'fake.glb', Buffer.from( 'hello, not a model' ) );

		expect( status ).toBeGreaterThanOrEqual( 400 );
		expect( body.message ).toContain( 'not a valid GLB' );
		expect( attachmentCount() ).toBe( before );
	} );

	test( 'a glTF that points to a separate .bin file is refused with advice', async ( { request } ) => {
		const gltf = JSON.stringify( { asset: { version: '2.0' }, buffers: [ { byteLength: 36, uri: 'scene.bin' } ] } );
		const { status, body } = await upload( request, 'scene.gltf', Buffer.from( gltf ), 'model/gltf+json' );

		expect( status ).toBeGreaterThanOrEqual( 400 );
		expect( body.message ).toContain( 'single .glb' );
	} );

	test( 'an OBJ carrying PHP is refused', async ( { request } ) => {
		const { status, body } = await upload( request, 'shell.obj', Buffer.from( "v 0 0 0\nv 1 0 0\nv 0 1 0\nf 1 2 3\n<?php echo 1; ?>\n" ) );

		expect( status ).toBeGreaterThanOrEqual( 400 );
		expect( body.message ).toContain( 'contains code' );
	} );

	test( 'an author can upload a model through WP-CLI, a contributor cannot', async () => {
		for ( const [ login, role ] of [ [ 'e2e_author', 'author' ], [ 'e2e_contributor', 'contributor' ] ] ) {
			try {
				wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, '--user_pass=password' );
			} catch ( error ) {
				// Already there from an earlier run.
			}
		}
		wpEval( `file_put_contents( '/tmp/e2e-part.stl', base64_decode( '${ binaryStl( 2 ).toString( 'base64' ) }' ) );` );

		const id = Number( wp( 'media', 'import', '/tmp/e2e-part.stl', '--user=e2e_author', '--porcelain' ) );
		expect( wp( 'post', 'get', String( id ), '--field=post_mime_type' ) ).toBe( 'model/stl' );
		expect( pluginMeta( id ).status ).toBe( 'converted' );

		expect( () => wp( 'media', 'import', '/tmp/e2e-part.stl', '--user=e2e_contributor', '--porcelain' ) ).toThrow();
	} );

	test( 'the Media Library has a 3D models filter', async ( { page, request } ) => {
		await upload( request, 'listed.glb', glb() );
		await page.goto( '/wp-admin/upload.php?mode=list' );

		await expect( page.locator( '#attachment-filter option[value="post_mime_type:model"]' ) ).toHaveText( /3D models/ );
		await page.goto( '/wp-admin/upload.php?mode=list&post_mime_type=model' );
		await expect( page.locator( '#the-list' ) ).toContainText( 'listed' );
	} );
} );

test.describe( 'Block', () => {
	test( 'insert by uploading an STL, add alt text, publish: the front end shows the converted GLB', async ( { page, request } ) => {
		const postId = Number( wp( 'post', 'create', '--post_title=3D post', '--post_status=draft', '--porcelain' ) );
		await openEditor( page, postId );

		await page.evaluate( () => {
			const block = window.wp.blocks.createBlock( 'embed-3d-models/model' );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		} );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const placeholder = canvas.locator( '.wp-block-embed-3d-models-model .components-placeholder' );
		await expect( placeholder ).toContainText( 'STL and OBJ files are converted' );
		await placeholder.locator( 'input[type="file"]' ).setInputFiles( { name: 'gear.stl', mimeType: 'application/vnd.ms-pki.stl', buffer: binaryStl( 8 ) } );

		const viewer = canvas.locator( '.wp-block-embed-3d-models-model model-viewer' );
		await expect( viewer ).toHaveAttribute( 'src', /gear(-\d+)?-stl\.glb$/, { timeout: 30000 } );
		// The model renders in the editor canvas, not just the element.
		await expect.poll( () => viewer.evaluate( ( node ) => node.loaded ), { timeout: 30000 } ).toBe( true );

		// Alt text in the sidebar.
		await page.getByRole( 'region', { name: 'Editor settings' } ).getByLabel( 'Alternative text' ).fill( 'A small gear' );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await page.waitForFunction( () => ! window.wp.data.select( 'core/editor' ).isSavingPost() && ! window.wp.data.select( 'core/editor' ).isEditedPostDirty() );

		const content = wp( 'post', 'get', String( postId ), '--field=post_content' );
		expect( content ).toMatch( /<!-- wp:embed-3d-models\/model \{"id":\d+,"alt":"A small gear"/ );

		wp( 'post', 'update', String( postId ), '--post_status=publish' );
		// The viewer and its decoders must come from this site, never a CDN.
		const external = [];
		page.on( 'request', ( r ) => {
			if ( /gstatic\.com|googleapis\.com|jsdelivr\.net|unpkg\.com|ajax\.googleapis/.test( r.url() ) ) {
				external.push( r.url() );
			}
		} );
		await page.goto( `/?p=${ postId }` );

		const front = page.locator( 'figure.e3dm-figure model-viewer' );
		await expect( front ).toHaveAttribute( 'src', /gear(-\d+)?-stl\.glb$/ );
		await expect( front ).toHaveAttribute( 'alt', 'A small gear' );
		await expect( front ).toHaveAttribute( 'disable-zoom', '' );
		await expect( page.locator( 'script[src*="model-viewer-umd.min.js"]' ) ).toHaveCount( 1 );
		// The decoders point at the plugin's own copies, not Google's CDN.
		const draco = await page.evaluate( () => window.ModelViewerElement.ModelViewerElement.dracoDecoderLocation );
		expect( draco ).toContain( '/wp-content/plugins/embed-3d-models/assets/vendor/draco/' );
		await front.scrollIntoViewIfNeeded();
		await page.waitForFunction( () => document.querySelector( 'model-viewer' ).loaded, null, { timeout: 30000 } );
		expect( external ).toEqual( [] );
	} );

	test( 'pages without the block do not load the viewer', async ( { page } ) => {
		const postId = wp( 'post', 'create', '--post_title=Plain', '--post_status=publish', '--post_content=<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', '--porcelain' );
		await page.goto( `/?p=${ postId }` );

		await expect( page.locator( 'p', { hasText: 'Hi' } ) ).toBeVisible();
		await expect( page.locator( 'script[src*="model-viewer"]' ) ).toHaveCount( 0 );
	} );

	test( '"Use current view as poster" saves an image and makes it the model thumbnail', async ( { page, request } ) => {
		const { body: model } = await upload( request, 'poster-me.glb', glb() );
		const postId = Number( wp( 'post', 'create', '--post_title=Poster', '--post_status=draft', `--post_content=<!-- wp:embed-3d-models/model {"id":${ model.id },"alt":"Triangle"} /-->`, '--porcelain' ) );
		await openEditor( page, postId );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const viewer = canvas.locator( 'model-viewer' );
		await expect( viewer ).toHaveAttribute( 'src', /poster-me(-\d+)?\.glb$/ );
		await page.waitForFunction(
			() => {
				const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
				const element = frame && frame.contentDocument.querySelector( 'model-viewer' );
				return element && element.loaded;
			},
			null,
			{ timeout: 30000 }
		);
		await viewer.click();

		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		await sidebar.getByRole( 'button', { name: 'Poster and loading' } ).click();
		await sidebar.getByRole( 'button', { name: 'Use current view as poster' } ).click();

		await expect.poll( () => wp( 'post', 'meta', 'get', String( model.id ), '_thumbnail_id' ), { timeout: 30000 } ).toMatch( /^\d+$/ );
		const posterId = wp( 'post', 'meta', 'get', String( model.id ), '_thumbnail_id' );
		expect( wp( 'post', 'get', posterId, '--field=post_mime_type' ) ).toMatch( /^image\// );
		await expect( viewer ).toHaveAttribute( 'poster', /poster/ );
	} );

	test( 'the embed3dModels.viewerAttributes JS filter changes the preview', async ( { page, request } ) => {
		const { body: model } = await upload( request, 'filtered.glb', glb() );
		const postId = Number( wp( 'post', 'create', '--post_title=Filter', '--post_status=draft', `--post_content=<!-- wp:embed-3d-models/model {"id":${ model.id }} /-->`, '--porcelain' ) );

		await page.addInitScript( () => {
			document.addEventListener( 'DOMContentLoaded', () => {
				window.wp.hooks.addFilter( 'embed3dModels.viewerAttributes', 'e2e', ( attrs ) => ( { ...attrs, 'data-e2e': 'filtered' } ) );
			} );
		} );
		await openEditor( page, postId );

		await expect( page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( 'model-viewer' ) ).toHaveAttribute( 'data-e2e', 'filtered' );
	} );

	test( 'the block asks for alt text when it is missing', async ( { page, request } ) => {
		const { body: model } = await upload( request, 'no-alt.glb', glb() );
		const postId = Number( wp( 'post', 'create', '--post_title=No alt', '--post_status=draft', `--post_content=<!-- wp:embed-3d-models/model {"id":${ model.id }} /-->`, '--porcelain' ) );
		await openEditor( page, postId );

		await expect( page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.e3dm-editor-hint' ) ).toContainText( 'Add alternative text' );
	} );
} );

test.describe( 'Cleanup', () => {
	test( 'deleting an STL attachment deletes its converted GLB', async ( { request } ) => {
		const { body } = await upload( request, 'gone.stl', binaryStl( 4 ) );
		const meta = pluginMeta( body.id );
		const dir = wpEval( `echo dirname( get_attached_file( ${ body.id } ) );` );
		expect( siblingExists( body.id, meta.glb ) ).toBe( true );

		wp( 'post', 'delete', String( body.id ), '--force' );

		expect( wpEval( `echo (int) file_exists( '${ dir }/${ meta.glb }' );` ) ).toBe( '0' );
	} );

	test( 'uninstall removes converted GLBs and the plugin metadata, keeps the uploads', async ( { request } ) => {
		const { body } = await upload( request, 'uninstall.stl', binaryStl( 4 ) );
		const meta = pluginMeta( body.id );

		// Runs uninstall.php directly: `wp plugin uninstall` would delete the mounted plugin folder.
		wpEval( "define( 'WP_UNINSTALL_PLUGIN', 'embed-3d-models/embed-3d-models.php' ); include WP_PLUGIN_DIR . '/embed-3d-models/uninstall.php';" );

		expect( siblingExists( body.id, meta.glb ) ).toBe( false );
		expect( pluginMeta( body.id ) ).toBeNull();
		expect( wpEval( `echo (int) file_exists( get_attached_file( ${ body.id } ) );` ) ).toBe( '1' );
	} );
} );

test.describe( 'WP-CLI, REST, abilities and Site Health', () => {
	test( 'wp embed-3d-models list, get, convert, check and formats', async ( { request } ) => {
		const { body } = await upload( request, 'cli.stl', binaryStl( 3 ) );

		const formats = JSON.parse( wp( 'embed-3d-models', 'formats', '--format=json' ) );
		expect( formats.map( ( f ) => f.extension ) ).toEqual( [ 'glb', 'gltf', 'usdz', 'stl', 'obj' ] );

		const list = JSON.parse( wp( 'embed-3d-models', 'list', '--format=json', '--type=stl' ) );
		expect( list.find( ( row ) => row.id === body.id ) ).toMatchObject( { status: 'converted', triangles: 3 } );

		expect( JSON.parse( wp( 'embed-3d-models', 'get', String( body.id ), '--format=json' ) )[ 0 ].format ).toBe( 'stl' );
		expect( wp( 'embed-3d-models', 'convert', String( body.id ) ) ).toContain( '3 triangles' );

		wpEval( "file_put_contents( '/tmp/e2e-bad.glb', 'nope' );" );
		expect( () => wp( 'embed-3d-models', 'check', '/tmp/e2e-bad.glb' ) ).toThrow( /not a valid GLB/ );
	} );

	test( 'REST: an author cannot set the poster of a model they cannot edit', async ( { browser, request } ) => {
		const { body: model } = await upload( request, 'admin-owned.glb', glb() );
		try {
			wp( 'user', 'create', 'e2e_author2', 'e2e_author2@example.com', '--role=author', '--user_pass=password' );
		} catch ( error ) {
			// Already there.
		}
		const { context, page } = await logInAs( browser, 'e2e_author2', 'password' );
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const response = await page.request.post( `/index.php?rest_route=${ encodeURIComponent( `/embed-3d-models/v1/models/${ model.id }/poster` ) }`, {
			headers: { 'X-WP-Nonce': nonce },
			data: { poster_id: 0 },
		} );
		await context.close();

		expect( response.status() ).toBe( 403 );
	} );

	test( 'abilities list models on WordPress 6.9 and later', async ( { rest, request } ) => {
		test.skip( '1' !== wpEval( "echo (int) function_exists( 'wp_register_ability' );" ), 'The Abilities API needs WordPress 6.9.' );
		const { body: model } = await upload( request, 'ability.glb', glb() );

		const { status, body } = await rest( 'GET', '/wp-abilities/v1/abilities/embed-3d-models/list-models/run?input[format]=glb' );

		expect( status ).toBe( 200 );
		expect( body.map( ( m ) => m.id ) ).toContain( model.id );
	} );

	test( 'Site Health reports that 3D models can be uploaded', async () => {
		const status = wpEval(
			"wp_set_current_user( 1 ); $tests = apply_filters( 'site_status_tests', array() ); $result = call_user_func( $tests['direct']['embed_3d_models_upload']['test'] ); echo $result['status'];"
		);

		expect( status ).toBe( 'good' );
	} );
} );
