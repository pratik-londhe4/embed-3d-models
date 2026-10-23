/**
 * Block attributes to <model-viewer> attributes, for the editor preview.
 *
 * Mirrors Renderer::viewer_attributes() in PHP. No WordPress dependencies,
 * so it is unit tested in Node (tests/js/attributes.test.js).
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.embed3dModelsAttributes = factory();
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var HEIGHT = /^\d{1,4}(\.\d{1,2})?(px|vh|svh|em|rem|%)$/;
	var ORBIT_VALUE = '-?\\d+(\\.\\d+)?(e-?\\d+)?(deg|rad|m|cm|mm|%)?';
	var ORBIT = new RegExp( '^' + ORBIT_VALUE + ' ' + ORBIT_VALUE + ' ' + ORBIT_VALUE + '$' );

	/**
	 * A CSS height: a number with px, vh, em, rem or %, else 480px.
	 *
	 * @param {string} value Height.
	 * @return {string} Safe height.
	 */
	function height( value ) {
		var trimmed = String( value || '' ).trim();
		return HEIGHT.test( trimmed ) ? trimmed : '480px';
	}

	/**
	 * A camera orbit of three CSS values, or null.
	 *
	 * @param {string} value Orbit.
	 * @return {string|null} Orbit or null.
	 */
	function orbit( value ) {
		var trimmed = String( value || '' ).trim();
		return ORBIT.test( trimmed ) ? trimmed : null;
	}

	/**
	 * A number within bounds.
	 *
	 * @param {*}      value    Value.
	 * @param {number} min      Minimum.
	 * @param {number} max      Maximum.
	 * @param {number} fallback Used when not a number.
	 * @return {string} Number as text.
	 */
	function number( value, min, max, fallback ) {
		var n = typeof value === 'number' ? value : parseFloat( value );
		if ( ! isFinite( n ) ) {
			n = fallback;
		}
		n = Math.max( min, Math.min( max, n ) );
		return String( Math.round( n * 100 ) / 100 );
	}

	/**
	 * A hex colour, or null.
	 *
	 * @param {string} value Colour.
	 * @return {string|null} Colour or null.
	 */
	function color( value ) {
		return /^#([0-9a-f]{3}){1,2}$/i.test( String( value || '' ) ) ? value : null;
	}

	/**
	 * The <model-viewer> attributes for block attributes.
	 *
	 * True becomes an empty string (a bare attribute); false and null are
	 * left out, because a custom element's boolean attribute is on whenever
	 * it is present, even as "false".
	 *
	 * @param {Object} attributes Block attributes.
	 * @param {Object} urls       { src, poster, iosSrc } resolved by the editor.
	 * @return {Object} Attribute name to string value.
	 */
	function viewerAttributes( attributes, urls ) {
		var a = attributes || {};
		var u = urls || {};
		var background = color( a.backgroundColor );
		var ar = !! a.ar;
		var raw = {
			src: u.src || '',
			alt: a.alt || '',
			poster: u.poster || null,
			loading: a.loading === 'eager' ? 'eager' : 'lazy',
			reveal: a.reveal === 'manual' ? 'manual' : 'auto',
			'camera-controls': !! a.cameraControls,
			'disable-zoom': ! a.allowZoom,
			'touch-action': 'pan-y',
			'auto-rotate': !! a.autoRotate,
			autoplay: !! a.autoplay,
			ar: ar,
			'ar-modes': ar ? 'webxr scene-viewer quick-look' : null,
			'ios-src': ar && u.iosSrc ? u.iosSrc : null,
			exposure: number( a.exposure, 0, 4, 1 ),
			'shadow-intensity': number( a.shadowIntensity, 0, 2, 1 ),
			'camera-orbit': orbit( a.cameraOrbit ),
			style: 'height:' + height( a.height ) + ';' + ( background ? 'background-color:' + background + ';' : '' ),
		};

		return clean( raw );
	}

	/**
	 * Drops false and null values and turns true into ''.
	 *
	 * @param {Object} raw Attributes.
	 * @return {Object} Cleaned attributes.
	 */
	function clean( raw ) {
		var out = {};
		Object.keys( raw ).forEach( function ( key ) {
			var value = raw[ key ];
			if ( value === false || value === null || value === undefined ) {
				return;
			}
			out[ key ] = value === true ? '' : String( value );
		} );
		return out;
	}

	/**
	 * Turns an inline style string into a React style object.
	 *
	 * @param {string} style CSS declarations.
	 * @return {Object} Style object.
	 */
	function styleObject( style ) {
		var out = {};
		String( style || '' )
			.split( ';' )
			.forEach( function ( declaration ) {
				var index = declaration.indexOf( ':' );
				if ( index < 1 ) {
					return;
				}
				var name = declaration.slice( 0, index ).trim().replace( /-([a-z])/g, function ( m, c ) {
					return c.toUpperCase();
				} );
				out[ name ] = declaration.slice( index + 1 ).trim();
			} );
		return out;
	}

	/**
	 * The MIME type the plugin stores for a file name, from its extension.
	 *
	 * Browsers report 3D files inconsistently (empty, application/octet-stream,
	 * or on Windows application/vnd.ms-pki.stl for STL), so uploads are typed
	 * by extension before they are sent.
	 *
	 * @param {string} name    File name.
	 * @param {Object} formats Formats keyed by extension, each with a mime.
	 * @return {string|null} MIME type or null.
	 */
	function mimeForFile( name, formats ) {
		var match = /\.([a-z0-9]+)$/i.exec( String( name || '' ) );
		var ext = match ? match[ 1 ].toLowerCase() : '';
		return formats && formats[ ext ] ? formats[ ext ].mime : null;
	}

	/**
	 * A camera orbit string from model-viewer's getCameraOrbit().
	 *
	 * @param {{theta:number,phi:number,radius:number}} position Spherical position.
	 * @return {string} Orbit, e.g. "0.785rad 1.047rad 2.5m".
	 */
	function orbitString( position ) {
		function round( n ) {
			return Math.round( n * 1000 ) / 1000;
		}
		return round( position.theta ) + 'rad ' + round( position.phi ) + 'rad ' + round( position.radius ) + 'm';
	}

	return {
		height: height,
		orbit: orbit,
		viewerAttributes: viewerAttributes,
		styleObject: styleObject,
		mimeForFile: mimeForFile,
		orbitString: orbitString,
	};
} );
