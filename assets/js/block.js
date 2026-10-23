/**
 * The 3D Model block in the editor.
 *
 * Plain JavaScript on the wp.* globals, no build step. The preview is the
 * real <model-viewer>, loaded into the editor canvas by PHP.
 *
 * Hooks (wp.hooks):
 * - filter embed3dModels.viewerAttributes( attrs, attributes ): preview element attributes.
 * - filter embed3dModels.allowedTypes( mimes ): MIME types offered in the Media Library.
 * - filter embed3dModels.posterOptions( options, attributes ): model-viewer toBlob() options.
 * - action embed3dModels.modelSelected( model, attributes ): a model was picked or uploaded.
 * - action embed3dModels.posterCreated( media, attributes ): a poster was captured.
 */
( function ( wp, settings, helpers ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var hooks = wp.hooks;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var UnitControl = c.UnitControl || c.__experimentalUnitControl;

	var formats = settings.formats || {};
	var NAMESPACE = 'embed-3d-models/v1';

	var icon = el(
		wp.primitives.SVG,
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24' },
		el( wp.primitives.Path, {
			d: 'M12 2.5 3.5 7.2v9.6l8.5 4.7 8.5-4.7V7.2L12 2.5Zm0 1.7 6.6 3.6L12 11.5 5.4 7.8 12 4.2ZM5 9.1l6.25 3.5v7L5 16.1V9.1Zm7.75 10.5v-7L19 9.1v7l-6.25 3.5Z',
		} )
	);

	/**
	 * MIME types the block accepts from the Media Library (not USDZ, which is only for iPhone AR).
	 *
	 * @return {string[]} MIME types.
	 */
	function allowedTypes() {
		var mimes = Object.keys( formats )
			.filter( function ( ext ) {
				return ext !== 'usdz';
			} )
			.map( function ( ext ) {
				return formats[ ext ].mime;
			} );
		return hooks.applyFilters( 'embed3dModels.allowedTypes', mimes );
	}

	/**
	 * The accept attribute for file inputs.
	 *
	 * @return {string} Extensions.
	 */
	function accept() {
		return Object.keys( formats )
			.filter( function ( ext ) {
				return ext !== 'usdz';
			} )
			.map( function ( ext ) {
				return '.' + ext;
			} )
			.join( ',' );
	}

	/**
	 * Loads what the plugin knows about a model attachment.
	 *
	 * @param {number|undefined} id Attachment ID.
	 * @return {Array} [ model or null, error message, reload function ].
	 */
	function useModel( id ) {
		var state = useState( { model: null, error: '' } );
		var counter = useState( 0 );

		useEffect(
			function () {
				var active = true;
				if ( ! id ) {
					state[ 1 ]( { model: null, error: '' } );
					return;
				}
				apiFetch( { path: NAMESPACE + '/models/' + id } )
					.then( function ( model ) {
						if ( active ) {
							state[ 1 ]( { model: model, error: '' } );
						}
					} )
					.catch( function ( error ) {
						if ( active ) {
							state[ 1 ]( { model: null, error: ( error && error.message ) || __( 'The model could not be loaded.', 'embed-3d-models' ) } );
						}
					} );
				return function () {
					active = false;
				};
			},
			[ id, counter[ 0 ] ]
		);

		return [
			state[ 0 ].model,
			state[ 0 ].error,
			function () {
				counter[ 1 ]( counter[ 0 ] + 1 );
			},
		];
	}

	/**
	 * Uploads files through the editor's own uploader, typed by extension.
	 *
	 * @param {FileList|File[]} files    Files.
	 * @param {Function}        onDone   Called with the uploaded attachment.
	 * @param {Function}        onError  Called with a message.
	 * @param {Function}        upload   The editor's mediaUpload setting.
	 */
	function uploadModel( files, onDone, onError, upload ) {
		var list = Array.prototype.slice.call( files || [] ).map( function ( file ) {
			var mime = helpers.mimeForFile( file.name, formats );
			return mime && file.type !== mime ? new File( [ file ], file.name, { type: mime } ) : file;
		} );
		if ( ! list.length ) {
			return;
		}
		if ( ! helpers.mimeForFile( list[ 0 ].name, formats ) ) {
			onError( __( 'Choose a GLB, glTF, STL or OBJ file.', 'embed-3d-models' ) );
			return;
		}
		upload( {
			filesList: [ list[ 0 ] ],
			allowedTypes: allowedTypes(),
			onFileChange: function ( items ) {
				var item = items && items[ 0 ];
				if ( item && item.id ) {
					onDone( item );
				}
			},
			onError: function ( error ) {
				onError( ( error && error.message ) || String( error ) );
			},
		} );
	}

	/**
	 * The editor component.
	 *
	 * @param {Object} props Block props.
	 * @return {Element} Element.
	 */
	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var viewer = useRef( null );
		var busy = useState( '' );
		var loadError = useState( '' );
		var modelState = useModel( attributes.id );
		var model = modelState[ 0 ];
		var modelError = modelState[ 1 ];
		var reload = modelState[ 2 ];
		var blockProps = be.useBlockProps( { className: 'e3dm-figure' } );
		var mediaUpload = useSelect( function ( select ) {
			return select( be.store ).getSettings().mediaUpload;
		}, [] );
		var notices = useDispatch( 'core/notices' );

		var iosModel = useModel( attributes.ar ? attributes.iosId : 0 )[ 0 ];
		var src = attributes.id ? ( model && model.viewer_url ) || '' : attributes.src;
		var hasModel = !! ( attributes.id || attributes.src );

		useEffect(
			function () {
				var node = viewer.current;
				if ( ! node ) {
					return;
				}
				function onError() {
					loadError[ 1 ]( __( 'The model could not be loaded. If it is on another site, that site must allow it (CORS).', 'embed-3d-models' ) );
				}
				function onLoad() {
					loadError[ 1 ]( '' );
				}
				node.addEventListener( 'error', onError );
				node.addEventListener( 'load', onLoad );
				return function () {
					node.removeEventListener( 'error', onError );
					node.removeEventListener( 'load', onLoad );
				};
			},
			[ src ]
		);

		function error( message ) {
			notices.createErrorNotice( message, { type: 'snackbar' } );
		}

		function selectModel( media ) {
			if ( ! media || ! media.id ) {
				return;
			}
			setAttributes( {
				id: media.id,
				src: '',
				alt: attributes.alt || media.alt || '',
				cameraOrbit: '',
			} );
			hooks.doAction( 'embed3dModels.modelSelected', media, attributes );
		}

		function selectUrl( url ) {
			setAttributes( { id: undefined, src: url, cameraOrbit: '' } );
		}

		function capturePoster() {
			var node = viewer.current;
			if ( ! node || typeof node.toBlob !== 'function' ) {
				return;
			}
			busy[ 1 ]( 'poster' );
			var options = hooks.applyFilters( 'embed3dModels.posterOptions', { mimeType: 'image/webp', qualityArgument: 0.9, idealAspect: false }, attributes );
			node.toBlob( options )
				.then( function ( blob ) {
					var type = blob.type || 'image/png';
					var ext = type === 'image/webp' ? 'webp' : type === 'image/jpeg' ? 'jpg' : 'png';
					var base = ( model && model.title ) || 'model';
					var body = new window.FormData();
					body.append( 'file', new File( [ blob ], base.replace( /[^a-z0-9-_]+/gi, '-' ).toLowerCase() + '-poster.' + ext, { type: type } ) );
					body.append( 'alt_text', attributes.alt || '' );
					return apiFetch( { path: '/wp/v2/media', method: 'POST', body: body } );
				} )
				.then( function ( media ) {
					setAttributes( { posterId: media.id, poster: media.source_url } );
					hooks.doAction( 'embed3dModels.posterCreated', media, attributes );
					if ( attributes.id ) {
						return apiFetch( { path: NAMESPACE + '/models/' + attributes.id + '/poster', method: 'POST', data: { poster_id: media.id } } ).then( reload );
					}
				} )
				.catch( function ( e ) {
					error( ( e && e.message ) || __( 'The poster could not be saved.', 'embed-3d-models' ) );
				} )
				.finally( function () {
					busy[ 1 ]( '' );
				} );
		}

		function saveCamera() {
			var node = viewer.current;
			if ( node && typeof node.getCameraOrbit === 'function' ) {
				setAttributes( { cameraOrbit: helpers.orbitString( node.getCameraOrbit() ), autoRotate: false } );
			}
		}

		function convertAgain() {
			busy[ 1 ]( 'convert' );
			apiFetch( { path: NAMESPACE + '/models/' + attributes.id + '/convert', method: 'POST' } )
				.then( reload )
				.catch( function ( e ) {
					error( e.message );
				} )
				.finally( function () {
					busy[ 1 ]( '' );
				} );
		}

		if ( ! hasModel ) {
			return el(
				'figure',
				blockProps,
				el( be.MediaPlaceholder, {
					icon: el( be.BlockIcon, { icon: icon } ),
					labels: {
						title: __( '3D Model', 'embed-3d-models' ),
						instructions: __( 'Upload a GLB, glTF, STL or OBJ file, pick one from the Media Library, or insert one from a URL. STL and OBJ files are converted to GLB.', 'embed-3d-models' ),
					},
					accept: accept(),
					allowedTypes: allowedTypes(),
					handleUpload: false,
					disableMediaButtons: false,
					onSelect: function ( value ) {
						if ( value && value.id ) {
							selectModel( value );
						} else {
							uploadModel( value, selectModel, error, mediaUpload );
						}
					},
					onSelectURL: selectUrl,
					onError: error,
				} )
			);
		}

		var urls = {
			src: src,
			poster: attributes.posterId || attributes.poster ? attributes.poster : model && model.poster_url,
			iosSrc: iosModel ? iosModel.url : attributes.iosSrc,
		};
		var viewerAttrs = hooks.applyFilters( 'embed3dModels.viewerAttributes', helpers.viewerAttributes( attributes, urls ), attributes );
		var elementProps = { ref: viewer };
		Object.keys( viewerAttrs ).forEach( function ( key ) {
			if ( key === 'style' ) {
				elementProps.style = helpers.styleObject( viewerAttrs.style );
			} else if ( key !== 'reveal' ) {
				// The editor always shows the model, even with "click to load".
				elementProps[ key ] = viewerAttrs[ key ];
			}
		} );

		var status = model ? model.status : '';
		var failed = status === 'failed' || status === 'too_large';

		return el(
			Fragment,
			null,
			el(
				be.BlockControls,
				{ group: 'other' },
				el( c.ToolbarButton, {
					onClick: function () {
						setAttributes( { id: undefined, src: '', cameraOrbit: '' } );
					},
				}, __( 'Replace', 'embed-3d-models' ) )
			),
			el( Inspector, {
				attributes: attributes,
				setAttributes: setAttributes,
				model: model,
				busy: busy[ 0 ],
				canCapture: !! src && ! failed,
				onCapture: capturePoster,
				onSaveCamera: saveCamera,
				onConvert: convertAgain,
			} ),
			el(
				'figure',
				blockProps,
				failed || modelError
					? el( c.Notice, { status: 'error', isDismissible: false }, modelError || model.error )
					: src
					? el( 'model-viewer', elementProps )
					: el( c.Spinner ),
				loadError[ 0 ] ? el( c.Notice, { status: 'error', isDismissible: false }, loadError[ 0 ] ) : null,
				! attributes.alt
					? el( 'p', { className: 'e3dm-editor-hint' }, __( 'Add alternative text in the block settings so people using screen readers know what the model shows.', 'embed-3d-models' ) )
					: null,
				el( be.RichText, {
					tagName: 'figcaption',
					className: 'wp-element-caption',
					value: attributes.caption,
					placeholder: __( 'Add caption', 'embed-3d-models' ),
					onChange: function ( value ) {
						setAttributes( { caption: value } );
					},
					withoutInteractiveFormatting: true,
					'aria-label': __( '3D model caption text', 'embed-3d-models' ),
				} )
			)
		);
	}

	/**
	 * Block settings in the sidebar.
	 *
	 * @param {Object} p Props.
	 * @return {Element} Element.
	 */
	function Inspector( p ) {
		var a = p.attributes;
		var set = p.setAttributes;
		var model = p.model;

		function toggle( key, label, help ) {
			return el( c.ToggleControl, {
				label: label,
				help: help,
				checked: !! a[ key ],
				onChange: function ( value ) {
					var change = {};
					change[ key ] = value;
					set( change );
				},
				__nextHasNoMarginBottom: true,
			} );
		}

		var details = null;
		if ( model ) {
			var lines = [ sprintf( /* translators: %s: file type, e.g. STL. */ __( 'File type: %s', 'embed-3d-models' ), model.label ) ];
			if ( model.status === 'converted' ) {
				lines.push( __( 'Converted to GLB for display.', 'embed-3d-models' ) );
			}
			if ( typeof model.triangles === 'number' ) {
				lines.push( sprintf( /* translators: %s: number of triangles. */ __( 'Triangles: %s', 'embed-3d-models' ), model.triangles.toLocaleString() ) );
			}
			details = el(
				Fragment,
				null,
				lines.map( function ( line, i ) {
					return el( 'p', { key: i, className: 'e3dm-detail' }, line );
				} ),
				model.status === 'failed' || model.status === 'too_large'
					? el( c.Button, { variant: 'secondary', isBusy: p.busy === 'convert', disabled: !! p.busy, onClick: p.onConvert }, __( 'Convert again', 'embed-3d-models' ) )
					: null
			);
		}

		return el(
			be.InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Model', 'embed-3d-models' ) },
				el( c.TextareaControl, {
					label: __( 'Alternative text', 'embed-3d-models' ),
					help: __( 'Describe what the model shows, for people who cannot see it.', 'embed-3d-models' ),
					value: a.alt,
					onChange: function ( value ) {
						set( { alt: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				details
			),
			el(
				c.PanelBody,
				{ title: __( 'Viewer', 'embed-3d-models' ) },
				UnitControl
					? el( UnitControl, {
							label: __( 'Height', 'embed-3d-models' ),
							value: a.height,
							units: [
								{ value: 'px', label: 'px', default: 480 },
								{ value: 'vh', label: 'vh', default: 60 },
								{ value: 'em', label: 'em', default: 30 },
							],
							onChange: function ( value ) {
								set( { height: value || '480px' } );
							},
							__next40pxDefaultSize: true,
					  } )
					: el( c.TextControl, {
							label: __( 'Height', 'embed-3d-models' ),
							value: a.height,
							onChange: function ( value ) {
								set( { height: value } );
							},
					  } ),
				el( c.BaseControl, { label: __( 'Background colour', 'embed-3d-models' ), id: 'e3dm-background', __nextHasNoMarginBottom: true },
					el( c.ColorPalette, {
						value: a.backgroundColor,
						onChange: function ( value ) {
							set( { backgroundColor: value || '' } );
						},
						clearable: true,
					} )
				),
				toggle( 'autoRotate', __( 'Rotate automatically', 'embed-3d-models' ) ),
				toggle( 'cameraControls', __( 'Let visitors rotate the model', 'embed-3d-models' ) ),
				toggle( 'allowZoom', __( 'Let visitors zoom', 'embed-3d-models' ), __( 'Off by default, so scrolling the page never gets stuck on the model.', 'embed-3d-models' ) ),
				toggle( 'autoplay', __( 'Play the model’s animation', 'embed-3d-models' ) ),
				el( c.RangeControl, {
					label: __( 'Brightness', 'embed-3d-models' ),
					value: a.exposure,
					min: 0,
					max: 4,
					step: 0.1,
					onChange: function ( value ) {
						set( { exposure: typeof value === 'number' ? value : 1 } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.RangeControl, {
					label: __( 'Shadow', 'embed-3d-models' ),
					value: a.shadowIntensity,
					min: 0,
					max: 2,
					step: 0.1,
					onChange: function ( value ) {
						set( { shadowIntensity: typeof value === 'number' ? value : 1 } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( 'div', { className: 'e3dm-buttons' },
					el( c.Button, { variant: 'secondary', onClick: p.onSaveCamera, disabled: ! p.canCapture }, __( 'Save current camera angle', 'embed-3d-models' ) ),
					a.cameraOrbit
						? el( c.Button, {
								variant: 'tertiary',
								onClick: function () {
									set( { cameraOrbit: '' } );
								},
						  }, __( 'Reset camera', 'embed-3d-models' ) )
						: null
				)
			),
			el(
				c.PanelBody,
				{ title: __( 'Poster and loading', 'embed-3d-models' ), initialOpen: false },
				el( 'p', { className: 'e3dm-detail' }, __( 'The poster shows while the model loads. It is also the model’s thumbnail in the Media Library.', 'embed-3d-models' ) ),
				a.poster ? el( 'img', { className: 'e3dm-poster-preview', src: a.poster, alt: '' } ) : null,
				el( 'div', { className: 'e3dm-buttons' },
					el( c.Button, { variant: 'secondary', isBusy: p.busy === 'poster', disabled: ! p.canCapture || !! p.busy, onClick: p.onCapture }, __( 'Use current view as poster', 'embed-3d-models' ) ),
					el( be.MediaUploadCheck, null,
						el( be.MediaUpload, {
							allowedTypes: [ 'image' ],
							value: a.posterId,
							onSelect: function ( media ) {
								set( { posterId: media.id, poster: media.url } );
							},
							render: function ( o ) {
								return el( c.Button, { variant: 'tertiary', onClick: o.open }, __( 'Choose image', 'embed-3d-models' ) );
							},
						} )
					),
					a.poster
						? el( c.Button, {
								variant: 'tertiary',
								isDestructive: true,
								onClick: function () {
									set( { posterId: undefined, poster: '' } );
								},
						  }, __( 'Remove poster', 'embed-3d-models' ) )
						: null
				),
				el( c.SelectControl, {
					label: __( 'Loading', 'embed-3d-models' ),
					value: a.loading,
					options: [
						{ value: 'lazy', label: __( 'When scrolled into view', 'embed-3d-models' ) },
						{ value: 'eager', label: __( 'Right away', 'embed-3d-models' ) },
					],
					onChange: function ( value ) {
						set( { loading: value } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( c.ToggleControl, {
					label: __( 'Click to load', 'embed-3d-models' ),
					help: __( 'Visitors see the poster and a button, and the model downloads only when they ask for it. Good for large models.', 'embed-3d-models' ),
					checked: a.reveal === 'manual',
					onChange: function ( value ) {
						set( { reveal: value ? 'manual' : 'auto' } );
					},
					__nextHasNoMarginBottom: true,
				} )
			),
			el(
				c.PanelBody,
				{ title: __( 'Augmented reality', 'embed-3d-models' ), initialOpen: false },
				toggle( 'ar', __( 'Show “View in your space”', 'embed-3d-models' ), __( 'On phones and tablets that support it. On Android, the model’s address is opened in Google’s Scene Viewer app.', 'embed-3d-models' ) ),
				a.ar
					? el( be.MediaUploadCheck, null,
							el( be.MediaUpload, {
								allowedTypes: [ formats.usdz ? formats.usdz.mime : 'model/vnd.usdz+zip' ],
								value: a.iosId,
								onSelect: function ( media ) {
									set( { iosId: media.id, iosSrc: media.url } );
								},
								render: function ( o ) {
									return el( 'div', { className: 'e3dm-buttons' },
										el( 'p', { className: 'e3dm-detail' }, a.iosSrc
											? sprintf( /* translators: %s: file name. */ __( 'iPhone and iPad file: %s', 'embed-3d-models' ), a.iosSrc.split( '/' ).pop() )
											: __( 'iPhone and iPad need a USDZ version of the model.', 'embed-3d-models' ) ),
										el( c.Button, { variant: 'secondary', onClick: o.open }, a.iosSrc ? __( 'Replace USDZ file', 'embed-3d-models' ) : __( 'Choose USDZ file', 'embed-3d-models' ) ),
										a.iosSrc
											? el( c.Button, {
													variant: 'tertiary',
													isDestructive: true,
													onClick: function () {
														set( { iosId: undefined, iosSrc: '' } );
													},
											  }, __( 'Remove', 'embed-3d-models' ) )
											: null
									);
								},
							} )
					  )
					: null
			)
		);
	}

	wp.blocks.registerBlockType( 'embed-3d-models/model', {
		icon: icon,
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.embed3dModelsEditor || {}, window.embed3dModelsAttributes );
