/**
 * FeedHat — shared wp-admin behaviour for the feedback list/edit
 * screens. Currently just the screenshot lightbox; always loaded (Free),
 * so Pro's Kanban board (assets/js/kanban.js) can reuse the same
 * .cf-lightbox-trigger convention without depending on Pro being licensed.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', initScreenshotLightbox );

	/**
	 * Click any screenshot thumbnail (Feedback Details, a Kanban card, or an
	 * internal note's attached image) to pop the full-size image open in a
	 * simple in-page overlay — no bundled lightbox library, just a
	 * full-screen <img>, closed by clicking anywhere or pressing Escape.
	 * Delegated on `document` so it also covers elements added later (e.g.
	 * a note's image appended after an AJAX add-note).
	 */
	function initScreenshotLightbox() {
		document.addEventListener( 'click', function ( evt ) {
			var thumb = evt.target.closest( '.cf-lightbox-trigger' );

			if ( ! thumb ) {
				return;
			}

			evt.preventDefault();
			openLightbox( thumb.getAttribute( 'data-full-src' ) );
		} );
	}

	function openLightbox( src ) {
		closeLightbox();

		var overlay = document.createElement( 'div' );
		overlay.className = 'cf-lightbox';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.addEventListener( 'click', closeLightbox );

		var img = document.createElement( 'img' );
		img.src = src;
		img.alt = '';
		overlay.appendChild( img );

		document.body.appendChild( overlay );
		document.addEventListener( 'keydown', onLightboxKeydown );
	}

	function closeLightbox() {
		var overlay = document.querySelector( '.cf-lightbox' );

		if ( overlay && overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}

		document.removeEventListener( 'keydown', onLightboxKeydown );
	}

	function onLightboxKeydown( evt ) {
		if ( 'Escape' === evt.key ) {
			closeLightbox();
		}
	}
} )();
