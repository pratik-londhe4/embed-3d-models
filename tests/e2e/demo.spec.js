// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, openEditor, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { torusKnotStl } = require( './utils/models' );

const p = ( text ) => `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

test( 'demo: upload an STL into the block, set alt text and a poster, see it on the site', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	// Leftover uploads would give the files -1 names in the pictures.
	wp( 'site', 'empty', '--uploads', '--yes' );
	prepareSite( 'Maker Notes' );
	const postId = Number(
		wp( 'post', 'create', '--post_title=A knot for the shelf', '--post_status=publish', `--post_content=${ p( 'I printed this torus knot in PLA last weekend. Turn it around to see how the loops cross.' ) }`, '--porcelain' )
	);

	await recordDemo(
		browser,
		async ( page, demo ) => {
			await openEditor( page, postId );
			const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
			await expect( canvas.getByText( 'I printed this torus knot' ) ).toBeVisible();
			await canvas.getByText( 'I printed this torus knot' ).click();
			await demo.start();
			await demo.pause( 800 );

			// Insert the block.
			await demo.click( page.getByRole( 'button', { name: /Block Inserter|Add block/ } ).first(), 500 );
			await demo.type( page.getByRole( 'searchbox', { name: /Search/ } ), '3D model' );
			await demo.click( page.getByRole( 'option', { name: '3D Model' } ), 600 );
			// Close the inserter: its block directory lists other plugins.
			await demo.click( page.getByRole( 'button', { name: 'Close Block Inserter' } ).or( page.getByRole( 'button', { name: /Block Inserter|Add block/ } ).first() ).first(), 600 );

			// Upload an STL: it is converted to GLB on the server.
			const placeholder = canvas.locator( '.wp-block-embed-3d-models-model .components-placeholder' );
			await expect( placeholder ).toBeVisible();
			const chooser = page.waitForEvent( 'filechooser' );
			await demo.click( placeholder.getByRole( 'button', { name: 'Upload' } ), 200 );
			await ( await chooser ).setFiles( { name: 'torus-knot.stl', mimeType: 'application/octet-stream', buffer: torusKnotStl() } );
			const viewer = canvas.locator( 'model-viewer' );
			await expect.poll( () => viewer.evaluate( ( node ) => node.loaded ).catch( () => false ), { timeout: 60000 } ).toBe( true );
			await demo.moveTo( viewer );
			await demo.pause( 2000 );

			// Alt text and a poster.
			const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
			await demo.type( sidebar.getByLabel( 'Alternative text' ), 'A grey torus knot, printed in PLA' );
			await demo.click( sidebar.getByRole( 'button', { name: 'Poster and loading' } ), 500 );
			await demo.click( sidebar.getByRole( 'button', { name: 'Use current view as poster' } ), 300 );
			await expect( sidebar.locator( '.e3dm-poster-preview' ) ).toBeVisible( { timeout: 30000 } );
			await demo.pause( 1400 );

			await demo.click( page.getByRole( 'region', { name: 'Editor top bar' } ).getByRole( 'button', { name: 'Save', exact: true } ), 300 );
			await page.waitForFunction( () => ! window.wp.data.select( 'core/editor' ).isEditedPostDirty() );

			// On the site: visitors turn it around.
			await page.goto( `/?p=${ postId }` );
			const front = page.locator( 'figure.e3dm-figure model-viewer' );
			await front.scrollIntoViewIfNeeded();
			await page.waitForFunction( () => document.querySelector( 'model-viewer' ).loaded, null, { timeout: 30000 } );
			await demo.pause( 1200 );
			const box = await front.boundingBox();
			const y = box.y + box.height / 2;
			await page.mouse.move( box.x + box.width * 0.35, y, { steps: 15 } );
			await page.mouse.down();
			await page.mouse.move( box.x + box.width * 0.7, y - 40, { steps: 40 } );
			await page.mouse.up();
			await demo.pause( 2000 );
		},
		{ viewport: { width: 1280, height: 760 }, width: 960, fps: 10 }
	);
} );
