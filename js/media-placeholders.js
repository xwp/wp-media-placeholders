( function() {
	"use strict";
	var WPMediaPlaceholders = window.WPMediaPlaceholders;
	var Holder              = window.Holder;

	var MediaPlaceholders = {
		iterator: 0,
		init: function() {
			this.eventListeners();
		},
		eventListeners: function() {
			document.addEventListener( 'error', function( e ) {
				if ( typeof e.target !== 'undefined' ) {
					if ( e.target.nodeName.toLowerCase() === 'img' ) {
						var width;
						var height;
						var knownDimensions = false;

						var filename    = e.target.src;
						var upload_base = WPMediaPlaceholders.baseURL;

						// First, we'll try to obtain the dimensions from the image filename
						var dimensions = MediaPlaceholders.getDimensionsFromFilename( filename );

						if ( dimensions ) {
							width           = dimensions[1];
							height          = dimensions[2];
							knownDimensions = true;
						// If we still have no dimensions, we'll look into the catalog
						} else {
							for ( var i in WPMediaPlaceholders.catalog ) {
								if ( WPMediaPlaceholders.catalog.hasOwnProperty( i ) ) {
									var catalogFilename = upload_base + '/' + i;
									if ( filename.replace( /^https?\:/, '' ) === catalogFilename ) {
										width           = WPMediaPlaceholders.catalog[i].width;
										height          = WPMediaPlaceholders.catalog[i].height;
										knownDimensions = true;
									}
								}
							}
						}
						// If we still don't have dimensions, then the failing image is not WP attachment, we'll just need
						// to look for actual dimensions of the element in the document
						width  = width  ? width  : MediaPlaceholders.getNormalizedValue( e.target, 'width' );
						height = height ? height : MediaPlaceholders.getNormalizedValue( e.target, 'height' );

						var holderClassName = 'holder-victim-' + MediaPlaceholders.iterator;
						var holderSrc       = 'holder.js/' + width + 'x' + height + '/';

						if ( ! knownDimensions ) {
							holderSrc += 'auto/textmode:exact/';
						}

						e.target.setAttribute( 'data-src', holderSrc );
						e.target.className += ' ' + holderClassName;

						Holder.run({
							domain: 'holder.js',
							images: '.' + holderClassName
						});

						e.preventDefault();
					}
				}
			}, true );
		},
		getNormalizedValue: function( el, prop ) {
			var retVal;
			if ( el.getAttribute( prop ) ) {
				retVal = el.getAttribute( prop );
			} else if ( MediaPlaceholders._getCurrentStyle( el, prop ) ) {
				retVal = MediaPlaceholders._getCurrentStyle( el, prop );
			} else {
				retVal = 200;
			}
			if ( retVal.slice && retVal.slice(-1) !== '%' ) {
				retVal = parseInt( retVal );
			}
			return retVal;
		},
		_getCurrentStyle: function( el, prop ) {
			var retVal;
			if ( el.currentStyle ) {
				retVal = el.currentStyle[ prop ];
			} else if ( window.getComputedStyle ) {
				retVal = document.defaultView.getComputedStyle( el, null ).getPropertyValue( prop );
			}
			return retVal;
		},
		getDimensionsFromFilename: function( filename ) {
			var re = /-(\d+)x(\d+)(\.\w+)$/gi;
			var matches = re.exec( filename );

			return matches;
		}
	};
	MediaPlaceholders.init();
} )();
