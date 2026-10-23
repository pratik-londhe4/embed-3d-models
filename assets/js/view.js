/**
 * Front end of the 3D Model block: the "Load 3D model" button for
 * click-to-load models, and a message when a model fails to load.
 */
( function () {
	'use strict';

	function setup( figure ) {
		var viewer = figure.querySelector( 'model-viewer' );
		if ( ! viewer || viewer.dataset.e3dmReady ) {
			return;
		}
		viewer.dataset.e3dmReady = '1';

		var button = viewer.querySelector( '.e3dm-load' );
		var message = viewer.querySelector( '.e3dm-error-message' );

		if ( button ) {
			button.addEventListener( 'click', function () {
				if ( typeof viewer.dismissPoster === 'function' ) {
					viewer.dismissPoster();
				}
				button.remove();
			} );
		}

		viewer.addEventListener( 'load', function () {
			if ( button ) {
				button.remove();
			}
		} );

		viewer.addEventListener( 'error', function () {
			if ( message ) {
				message.hidden = false;
			}
			if ( button ) {
				button.remove();
			}
			figure.classList.add( 'e3dm-has-error' );
		} );
	}

	function init() {
		document.querySelectorAll( '.e3dm-figure' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
