/**
 * Tests for assets/js/attributes.js, the editor preview's attribute logic.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const a = require( '../../assets/js/attributes.js' );

const formats = {
	glb: { mime: 'model/gltf-binary' },
	stl: { mime: 'model/stl' },
	obj: { mime: 'model/obj' },
};

test( 'booleans become bare attributes and false ones are left out', () => {
	const attrs = a.viewerAttributes( { autoRotate: true, cameraControls: false, allowZoom: false, ar: false }, { src: 'x.glb' } );

	assert.strictEqual( attrs[ 'auto-rotate' ], '' );
	assert.strictEqual( attrs[ 'disable-zoom' ], '' );
	assert.ok( ! ( 'camera-controls' in attrs ) );
	assert.ok( ! ( 'ar' in attrs ) );
	assert.ok( ! ( 'ar-modes' in attrs ) );
} );

test( 'matches the PHP renderer defaults', () => {
	const attrs = a.viewerAttributes( {}, { src: 'x.glb' } );

	assert.deepStrictEqual( attrs, {
		src: 'x.glb',
		alt: '',
		loading: 'lazy',
		reveal: 'auto',
		'disable-zoom': '',
		'touch-action': 'pan-y',
		exposure: '1',
		'shadow-intensity': '1',
		style: 'height:480px;',
	} );
} );

test( 'ar adds modes and the iPhone file only when on', () => {
	const on = a.viewerAttributes( { ar: true }, { src: 'x.glb', iosSrc: 'x.usdz' } );
	assert.strictEqual( on[ 'ar-modes' ], 'webxr scene-viewer quick-look' );
	assert.strictEqual( on[ 'ios-src' ], 'x.usdz' );

	const off = a.viewerAttributes( { ar: false }, { src: 'x.glb', iosSrc: 'x.usdz' } );
	assert.ok( ! ( 'ios-src' in off ) );
} );

test( 'unsafe values are cleaned', () => {
	const attrs = a.viewerAttributes(
		{ height: '1px;color:red', backgroundColor: 'red', exposure: 50, shadowIntensity: 'x', cameraOrbit: '1 2 3" x', loading: 'never' },
		{ src: 'x.glb' }
	);

	assert.strictEqual( attrs.style, 'height:480px;' );
	assert.strictEqual( attrs.exposure, '4' );
	assert.strictEqual( attrs[ 'shadow-intensity' ], '1' );
	assert.ok( ! ( 'camera-orbit' in attrs ) );
	assert.strictEqual( attrs.loading, 'lazy' );
} );

test( 'valid height, colour and orbit are kept', () => {
	const attrs = a.viewerAttributes( { height: '60vh', backgroundColor: '#ffeedd', cameraOrbit: '45deg 55deg 2.5m' }, { src: 'x.glb' } );

	assert.strictEqual( attrs.style, 'height:60vh;background-color:#ffeedd;' );
	assert.strictEqual( attrs[ 'camera-orbit' ], '45deg 55deg 2.5m' );
} );

test( 'style strings become React style objects', () => {
	assert.deepStrictEqual( a.styleObject( 'height:60vh;background-color:#fff;' ), { height: '60vh', backgroundColor: '#fff' } );
} );

test( 'files are typed by extension, whatever the browser reported', () => {
	assert.strictEqual( a.mimeForFile( 'Part.STL', formats ), 'model/stl' );
	assert.strictEqual( a.mimeForFile( 'chair.glb', formats ), 'model/gltf-binary' );
	assert.strictEqual( a.mimeForFile( 'photo.jpg', formats ), null );
	assert.strictEqual( a.mimeForFile( 'noext', formats ), null );
} );

test( 'camera orbit string round-trips through the sanitiser', () => {
	const orbit = a.orbitString( { theta: 0.78539816, phi: 1.0471975, radius: 2.5 } );

	assert.strictEqual( orbit, '0.785rad 1.047rad 2.5m' );
	assert.strictEqual( a.orbit( orbit ), orbit );
} );
