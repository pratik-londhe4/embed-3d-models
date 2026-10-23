// Small 3D files for the end-to-end tests, built in memory.

/**
 * A binary STL of one or more triangles (corners in millimetres).
 *
 * @param {number} count Triangles.
 * @return {Buffer} STL bytes.
 */
function binaryStl( count = 12 ) {
	const buffer = Buffer.alloc( 84 + 50 * count );
	buffer.write( 'e2e binary stl', 0, 'ascii' );
	buffer.writeUInt32LE( count, 80 );
	for ( let i = 0; i < count; i++ ) {
		const base = 84 + i * 50 + 12;
		const corners = [ 0, 0, i, 10, 0, i, 0, 20, i + 5 ];
		corners.forEach( ( value, j ) => buffer.writeFloatLE( value, base + j * 4 ) );
	}
	return buffer;
}

/**
 * A GLB holding one triangle with positions only.
 *
 * @return {Buffer} GLB bytes.
 */
function glb() {
	const bin = Buffer.alloc( 36 );
	[ 0, 0, 0, 1, 0, 0, 0, 1, 0 ].forEach( ( value, i ) => bin.writeFloatLE( value, i * 4 ) );
	const json = {
		asset: { version: '2.0', generator: 'e2e' },
		scene: 0,
		scenes: [ { nodes: [ 0 ] } ],
		nodes: [ { mesh: 0 } ],
		meshes: [ { primitives: [ { attributes: { POSITION: 0 } } ] } ],
		buffers: [ { byteLength: 36 } ],
		bufferViews: [ { buffer: 0, byteLength: 36 } ],
		accessors: [ { bufferView: 0, componentType: 5126, count: 3, type: 'VEC3', min: [ 0, 0, 0 ], max: [ 1, 1, 0 ] } ],
	};
	let text = JSON.stringify( json );
	while ( text.length % 4 ) {
		text += ' ';
	}
	const jsonChunk = Buffer.from( text );
	const header = Buffer.alloc( 12 );
	const total = 12 + 8 + jsonChunk.length + 8 + bin.length;
	header.write( 'glTF', 0, 'ascii' );
	header.writeUInt32LE( 2, 4 );
	header.writeUInt32LE( total, 8 );
	const chunkHeader = ( length, type ) => {
		const h = Buffer.alloc( 8 );
		h.writeUInt32LE( length, 0 );
		h.write( type, 4, 'binary' );
		return h;
	};
	return Buffer.concat( [ header, chunkHeader( jsonChunk.length, 'JSON' ), jsonChunk, chunkHeader( bin.length, 'BIN\0' ), bin ] );
}

/**
 * A torus knot as a binary STL, in millimetres and Z-up like a file from a
 * 3D-printing tool. Used for the demo and the screenshots.
 *
 * @param {number} segments Steps along the knot.
 * @param {number} sides    Steps around the tube.
 * @return {Buffer} STL bytes.
 */
function torusKnotStl( segments = 220, sides = 14 ) {
	const p = 2;
	const q = 3;
	const radius = 30;
	const tube = 7;
	const curve = ( t ) => {
		const r = radius * ( 2 + Math.cos( q * t ) ) / 3;
		return [ r * Math.cos( p * t ), r * Math.sin( p * t ), ( radius * Math.sin( q * t ) ) / 3 + 20 ];
	};
	const sub = ( a, b ) => [ a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ], a[ 2 ] - b[ 2 ] ];
	const cross = ( a, b ) => [ a[ 1 ] * b[ 2 ] - a[ 2 ] * b[ 1 ], a[ 2 ] * b[ 0 ] - a[ 0 ] * b[ 2 ], a[ 0 ] * b[ 1 ] - a[ 1 ] * b[ 0 ] ];
	const norm = ( a ) => {
		const l = Math.hypot( ...a ) || 1;
		return a.map( ( v ) => v / l );
	};
	const rings = [];
	for ( let i = 0; i < segments; i++ ) {
		const t = ( i / segments ) * Math.PI * 2;
		const c = curve( t );
		const tangent = norm( sub( curve( t + 0.01 ), c ) );
		const normal = norm( cross( tangent, [ 0, 0, 1 ] ) );
		const binormal = cross( tangent, normal );
		const ring = [];
		for ( let j = 0; j < sides; j++ ) {
			const a = ( j / sides ) * Math.PI * 2;
			ring.push( [ 0, 1, 2 ].map( ( k ) => c[ k ] + tube * ( Math.cos( a ) * normal[ k ] + Math.sin( a ) * binormal[ k ] ) ) );
		}
		rings.push( ring );
	}
	const triangles = [];
	for ( let i = 0; i < segments; i++ ) {
		const r1 = rings[ i ];
		const r2 = rings[ ( i + 1 ) % segments ];
		for ( let j = 0; j < sides; j++ ) {
			const k = ( j + 1 ) % sides;
			triangles.push( [ r1[ j ], r2[ j ], r2[ k ] ], [ r1[ j ], r2[ k ], r1[ k ] ] );
		}
	}
	const buffer = Buffer.alloc( 84 + 50 * triangles.length );
	buffer.write( 'torus knot', 0, 'ascii' );
	buffer.writeUInt32LE( triangles.length, 80 );
	triangles.forEach( ( tri, i ) => {
		tri.flat().forEach( ( value, j ) => buffer.writeFloatLE( value, 84 + i * 50 + 12 + j * 4 ) );
	} );
	return buffer;
}

module.exports = { binaryStl, glb, torusKnotStl };
