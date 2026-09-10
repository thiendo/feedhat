/**
 * FeedHat — front-end widget.
 * Floating launcher button -> viewport screenshot (html2canvas) -> annotate -> submit.
 */
( function () {
	'use strict';

	if ( typeof window.FeedHat === 'undefined' ) {
		return;
	}

	var config = window.FeedHat;

	var els = {};
	var state = {
		tool: 'rect',
		color: config.buttonColor || '#e63946',
		shapes: [],
		current: null,
		drawing: false,
		baseImage: null,
		submitting: false,
		strokeScale: 1,
		selectedShape: null,
		environment: null,
		openedAt: 0,
		captchaToken: '',
		captchaWidgetId: null,
	};

	var BASE_LINE_WIDTH = 4.2;
	var BASE_ARROW_HEAD_LENGTH = 14;
	var BASE_FONT_SIZE = 18;
	var HIT_TEST_PADDING = 10;
	var BASE_PIN_RADIUS = 14;

	// Static, author-written SVG markup (never user/remote data) — safe to
	// insert via innerHTML. currentColor lets each icon follow the button's
	// own text color (including the active/inactive states).
	var ICONS = {
		feedback: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9l-5 4v-4H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
		rect: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="9" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
		arrow: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><line x1="3" y1="13" x2="12" y2="4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M6 4H12V10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		pen: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M11 2l3 3-8 8H3v-3l8-8z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>',
		text: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M3 3.5h10M8 3.5v9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		move: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M8 1.5v13M1.5 8h13M8 1.5L6 3.5M8 1.5l2 2M8 14.5l-2-2M8 14.5l2-2M1.5 8l2-2M1.5 8l2 2M14.5 8l-2-2M14.5 8l-2 2" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		pin: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M8 1.5c-2.4 0-4.3 1.9-4.3 4.3 0 3.1 4.3 8.2 4.3 8.2s4.3-5.1 4.3-8.2c0-2.4-1.9-4.3-4.3-4.3z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="8" cy="5.8" r="1.4" fill="currentColor"/></svg>',
		undo: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M4 4v4h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.5 8A5 5 0 1 1 6 12.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
		clear: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path d="M3 4h10M6 4V2.5h4V4M4.5 4l.6 9a1 1 0 001 .9h3.8a1 1 0 001-.9l.6-9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	};

	/**
	 * Append an icon + text label to a toolbar button.
	 */
	function appendIconLabel( button, iconSvg, label ) {
		var icon = document.createElement( 'span' );
		icon.className = 'cf-btn-icon';
		icon.innerHTML = iconSvg;
		button.appendChild( icon );

		// Icon-only button: the label is still there for anyone who needs
		// it (a screen reader, or a CSS tooltip reading data-tooltip on
		// hover/focus — see .cf-tool-btn[data-tooltip]::after) without
		// permanently taking up toolbar width like visible text would.
		button.setAttribute( 'aria-label', label );
		button.setAttribute( 'data-tooltip', label );
	}

	function init() {
		buildLauncher();
	}

	function buildLauncher() {
		var style = config.buttonStyle || 'pill';
		var size = config.buttonSize || 'medium';

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.id = 'cf-launcher';
		btn.className = 'cf-launcher cf-pos-' + config.position + ' cf-style-' + style + ' cf-size-' + size;
		btn.style.setProperty( '--cf-color', config.buttonColor );
		// Distance from the edge(s) it's pinned to — configurable so a site
		// already running another chat widget (Messenger, Zalo, Tawk.to…)
		// in that corner can nudge this one clear of it instead of the two
		// overlapping.
		btn.style.setProperty( '--cf-offset-x', ( config.offsetX || 0 ) + 'px' );
		btn.style.setProperty( '--cf-offset-y', ( config.offsetY || 0 ) + 'px' );
		btn.setAttribute( 'aria-label', config.buttonLabel );

		// "icon" and "label" styles each show only one of the two — the
		// button always carries aria-label, and "icon" additionally gets a
		// CSS hover/focus tooltip (data-tooltip) so the label isn't lost
		// for sighted users either.
		if ( 'label' !== style ) {
			var icon = document.createElement( 'span' );
			icon.className = 'cf-btn-icon';
			icon.innerHTML = ICONS.feedback;
			btn.appendChild( icon );
		}

		if ( 'icon' !== style ) {
			var label = document.createElement( 'span' );
			label.className = 'cf-launcher__label';
			label.textContent = config.buttonLabel;
			btn.appendChild( label );
		} else {
			btn.setAttribute( 'data-tooltip', config.buttonLabel );
		}

		btn.addEventListener( 'click', openPanel );

		document.body.appendChild( btn );
		els.launcher = btn;
	}

	function openPanel() {
		els.launcher.style.display = 'none';
		state.shapes = [];
		state.current = null;
		state.baseImage = null;
		state.draggingShape = null;
		state.lastDragPoint = null;
		state.selectedShape = null;
		els.textInput = null;
		state.pendingTextPoint = null;
		state.environment = null;
		state.openedAt = Date.now();
		state.captchaToken = '';
		state.captchaWidgetId = null;

		buildModal();
		captureScreenshot();
		renderCaptchaWidget();

		// Kick off in parallel with the screenshot — by the time the
		// reporter has drawn/typed anything and hits Submit, this has
		// long since resolved.
		detectEnvironment().then( function ( environment ) {
			state.environment = environment;
		} );

		// Refresh the nonce right away rather than waiting to react to a
		// failed first submit attempt — a panel opened long after page load
		// (or served from a page cache with an older one baked in) would
		// otherwise always eat one guaranteed-to-fail request before the
		// existing reactive retry recovers it. This makes that the common
		// case instead of relying on the retry every time.
		fetchFreshNonce();
	}

	function closePanel() {
		closePinNoteEditor();

		if ( window.turnstile && null !== state.captchaWidgetId ) {
			window.turnstile.remove( state.captchaWidgetId );
			state.captchaWidgetId = null;
		}

		if ( els.overlay && els.overlay.parentNode ) {
			els.overlay.parentNode.removeChild( els.overlay );
		}

		els.launcher.style.display = '';
	}

	function buildModal() {
		// Deliberately no click-outside-to-close / Escape-to-close: an
		// accidental misclick or Escape (e.g. while cancelling a text
		// annotation) must not discard in-progress work. Only the header's
		// close button and the Cancel button dismiss the panel.
		var overlay = document.createElement( 'div' );
		overlay.className = 'cf-overlay';

		var modal = document.createElement( 'div' );
		modal.className = 'cf-modal';

		if ( config.brandColor ) {
			modal.style.setProperty( '--cf-brand-color', config.brandColor );
		}

		overlay.appendChild( modal );

		// Header.
		var header = document.createElement( 'div' );
		header.className = 'cf-modal__header';
		modal.appendChild( header );

		var title = document.createElement( 'h2' );
		title.className = 'cf-modal__title';
		title.textContent = config.i18n.title;
		header.appendChild( title );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'cf-modal__close';
		closeBtn.setAttribute( 'aria-label', config.i18n.close );
		closeBtn.textContent = '×';
		closeBtn.addEventListener( 'click', closePanel );
		header.appendChild( closeBtn );

		// Body.
		var body = document.createElement( 'div' );
		body.className = 'cf-modal__body';
		modal.appendChild( body );

		var message = document.createElement( 'p' );
		message.className = 'cf-message';
		message.style.display = 'none';
		body.appendChild( message );
		els.message = message;

		var toolbar = buildToolbar();
		body.appendChild( toolbar );

		var canvasWrap = document.createElement( 'div' );
		canvasWrap.className = 'cf-canvas-wrap';
		body.appendChild( canvasWrap );
		els.canvasWrap = canvasWrap;

		var status = document.createElement( 'div' );
		status.className = 'cf-capture-status';
		status.textContent = config.i18n.capturing;
		canvasWrap.appendChild( status );
		els.captureStatus = status;

		var canvas = document.createElement( 'canvas' );
		canvas.style.display = 'none';
		canvasWrap.appendChild( canvas );
		els.canvas = canvas;
		els.ctx = canvas.getContext( '2d' );

		bindCanvasEvents( canvas );

		body.appendChild( buildTitleField() );
		body.appendChild( buildNoteField() );

		if ( ! config.isLoggedIn ) {
			body.appendChild( buildReporterFields() );
		}

		body.appendChild( buildHoneypot() );

		if ( config.captchaEnabled ) {
			var captchaWrap = document.createElement( 'div' );
			captchaWrap.className = 'cf-captcha';
			body.appendChild( captchaWrap );
			els.captchaContainer = captchaWrap;
		}

		// Footer.
		var footer = document.createElement( 'div' );
		footer.className = 'cf-modal__footer';
		modal.appendChild( footer );

		if ( config.brandName || config.brandLogoUrl ) {
			footer.appendChild( buildBrandRow() );
		}

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'cf-btn cf-btn-secondary';
		cancelBtn.textContent = config.i18n.cancel;
		cancelBtn.addEventListener( 'click', closePanel );
		footer.appendChild( cancelBtn );
		els.cancelBtn = cancelBtn;

		var submitBtn = document.createElement( 'button' );
		submitBtn.type = 'button';
		submitBtn.className = 'cf-btn cf-btn-primary';
		submitBtn.textContent = config.i18n.submit;
		submitBtn.addEventListener( 'click', submitFeedback );
		footer.appendChild( submitBtn );
		els.submitBtn = submitBtn;

		document.body.appendChild( overlay );

		els.overlay = overlay;
	}

	function buildToolbarDivider() {
		var divider = document.createElement( 'span' );
		divider.className = 'cf-toolbar__divider';
		divider.setAttribute( 'aria-hidden', 'true' );
		return divider;
	}

	function buildToolbar() {
		var toolbar = document.createElement( 'div' );
		toolbar.className = 'cf-toolbar';

		els.toolButtons = {};

		function createToolButton( tool ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'cf-tool-btn' + ( tool.id === state.tool ? ' is-active' : '' );
			appendIconLabel( btn, tool.icon, tool.label );
			btn.addEventListener( 'click', function () {
				commitTextInput();
				closePinNoteEditor();
				state.tool = tool.id;
				state.selectedShape = null;
				Object.keys( els.toolButtons ).forEach( function ( key ) {
					els.toolButtons[ key ].classList.toggle( 'is-active', key === tool.id );
				} );
				updateCanvasCursor();
				redraw();
			} );
			els.toolButtons[ tool.id ] = btn;
			return btn;
		}

		var drawTools = [
			{ id: 'rect', label: config.i18n.toolRect, icon: ICONS.rect },
			{ id: 'arrow', label: config.i18n.toolArrow, icon: ICONS.arrow },
			{ id: 'pen', label: config.i18n.toolPen, icon: ICONS.pen },
			{ id: 'text', label: config.i18n.toolText, icon: ICONS.text },
			{ id: 'pin', label: config.i18n.toolPin, icon: ICONS.pin },
		];

		drawTools.forEach( function ( tool ) {
			toolbar.appendChild( createToolButton( tool ) );
		} );

		toolbar.appendChild( buildToolbarDivider() );

		var colorInput = document.createElement( 'input' );
		colorInput.type = 'color';
		colorInput.className = 'cf-color-input';
		colorInput.value = normalizeHexColor( state.color );
		colorInput.setAttribute( 'aria-label', config.i18n.colorLabel );
		colorInput.addEventListener( 'input', function () {
			state.color = colorInput.value;

			// Recolor the currently selected element (Move tool) instead of
			// only affecting shapes drawn from now on.
			if ( state.selectedShape ) {
				state.selectedShape.color = colorInput.value;
				redraw();
			}
		} );
		toolbar.appendChild( colorInput );
		els.colorInput = colorInput;

		toolbar.appendChild( createToolButton( { id: 'move', label: config.i18n.toolMove, icon: ICONS.move } ) );

		toolbar.appendChild( buildToolbarDivider() );

		var undoBtn = document.createElement( 'button' );
		undoBtn.type = 'button';
		undoBtn.className = 'cf-tool-btn';
		appendIconLabel( undoBtn, ICONS.undo, config.i18n.undo );
		undoBtn.addEventListener( 'click', function () {
			if ( state.shapes[ state.shapes.length - 1 ] === state.selectedShape ) {
				state.selectedShape = null;
			}
			state.shapes.pop();
			redraw();
		} );
		toolbar.appendChild( undoBtn );
		els.undoBtn = undoBtn;

		var clearBtn = document.createElement( 'button' );
		clearBtn.type = 'button';
		clearBtn.className = 'cf-tool-btn';
		appendIconLabel( clearBtn, ICONS.clear, config.i18n.clear );
		clearBtn.addEventListener( 'click', function () {
			state.shapes = [];
			state.selectedShape = null;
			redraw();
		} );
		toolbar.appendChild( clearBtn );
		els.clearBtn = clearBtn;

		return toolbar;
	}

	function buildTitleField() {
		var field = document.createElement( 'div' );
		field.className = 'cf-field';

		var label = document.createElement( 'label' );
		label.textContent = config.i18n.titleLabel;
		field.appendChild( label );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.maxLength = 120;
		input.placeholder = config.i18n.titlePlaceholder;
		field.appendChild( input );
		els.title = input;

		return field;
	}

	function buildNoteField() {
		var field = document.createElement( 'div' );
		field.className = 'cf-field';

		var label = document.createElement( 'label' );
		label.textContent = config.i18n.noteLabel;
		field.appendChild( label );

		var textarea = document.createElement( 'textarea' );
		textarea.maxLength = config.maxNoteLength;
		textarea.placeholder = config.i18n.notePlaceholder;
		field.appendChild( textarea );
		els.note = textarea;

		return field;
	}

	function buildReporterFields() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'cf-field-row';

		var nameField = document.createElement( 'div' );
		nameField.className = 'cf-field';
		var nameLabel = document.createElement( 'label' );
		nameLabel.textContent = config.i18n.reporterNameLabel;
		nameField.appendChild( nameLabel );
		var nameInput = document.createElement( 'input' );
		nameInput.type = 'text';
		nameField.appendChild( nameInput );
		els.reporterName = nameInput;

		var emailField = document.createElement( 'div' );
		emailField.className = 'cf-field';
		var emailLabel = document.createElement( 'label' );
		emailLabel.textContent = config.i18n.reporterEmailLabel;
		emailField.appendChild( emailLabel );
		var emailInput = document.createElement( 'input' );
		emailInput.type = 'email';
		emailField.appendChild( emailInput );
		els.reporterEmail = emailInput;

		wrap.appendChild( nameField );
		wrap.appendChild( emailField );

		return wrap;
	}

	function buildHoneypot() {
		var wrap = document.createElement( 'div' );
		wrap.className = 'cf-honeypot';
		wrap.setAttribute( 'aria-hidden', 'true' );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.name = 'website';
		input.tabIndex = -1;
		input.autocomplete = 'off';
		wrap.appendChild( input );
		els.honeypot = input;

		return wrap;
	}

	/**
	 * Small white-label brand row (logo + display name) shown in the footer
	 * when the Pro add-on's white-label feature is configured. Absent
	 * entirely otherwise — Free never shows any "powered by" branding.
	 */
	function buildBrandRow() {
		var row = document.createElement( 'div' );
		row.className = 'cf-modal__brand';

		if ( config.brandLogoUrl ) {
			var logo = document.createElement( 'img' );
			logo.src = config.brandLogoUrl;
			logo.alt = '';
			logo.className = 'cf-modal__brand-logo';
			row.appendChild( logo );
		}

		if ( config.brandName ) {
			var name = document.createElement( 'span' );
			name.textContent = config.brandName;
			row.appendChild( name );
		}

		return row;
	}

	function normalizeHexColor( color ) {
		return /^#[0-9a-fA-F]{6}$/.test( color ) ? color : '#e63946';
	}

	function captureScreenshot() {
		if ( typeof window.html2canvas !== 'function' ) {
			onCaptureFailed( new Error( 'html2canvas is not available' ) );
			return;
		}

		runCapture( { useCORS: true, allowTaint: false } )
			.then( function ( canvas ) {
				loadScreenshot( canvas.toDataURL( 'image/png' ) );
			} )
			.catch( function ( error ) {
				// eslint-disable-next-line no-console
				console.warn(
					'[FeedHat] Screenshot capture failed, likely a tainted canvas from a cross-origin image without CORS headers. Retrying with those images excluded.',
					error
				);
				retryWithoutCrossOriginImages( error );
			} );
	}

	/**
	 * Run html2canvas against the current viewport and eagerly read the
	 * result via toDataURL(). html2canvas can resolve successfully with a
	 * canvas that is already "tainted" by a cross-origin image loaded
	 * without an Access-Control-Allow-Origin header — that only throws a
	 * SecurityError once something actually reads the canvas, so we force
	 * that read here instead of leaving it for later.
	 *
	 * @param {Object} extraOptions html2canvas options to merge in.
	 * @return {Promise<HTMLCanvasElement>}
	 */
	function runCapture( extraOptions ) {
		extraOptions = extraOptions || {};

		// The modal (with its "Capturing screenshot…" placeholder) is already
		// in the DOM by the time this runs — always exclude it from the
		// render, or the screenshot ends up containing a picture of itself.
		// Kept separate from extraOptions.ignoreElements below (retry's own
		// cross-origin-image skip list) so neither exclusion silently
		// clobbers the other.
		var extraIgnore = extraOptions.ignoreElements;

		var options = {
			logging: false,
			// Cap the capture scale instead of trusting devicePixelRatio
			// (up to 3 on some phones/monitors) — keeps the screenshot
			// light enough for both the browser and the server's image
			// processing to handle comfortably.
			scale: Math.min( window.devicePixelRatio || 1, 2 ),
			x: window.scrollX,
			y: window.scrollY,
			width: window.innerWidth,
			height: window.innerHeight,
			windowWidth: document.documentElement.scrollWidth,
			windowHeight: document.documentElement.scrollHeight,
			ignoreElements: function ( el ) {
				if ( els.overlay && el === els.overlay ) {
					return true;
				}

				return typeof extraIgnore === 'function' && extraIgnore( el );
			},
		};

		for ( var key in extraOptions ) {
			if ( Object.prototype.hasOwnProperty.call( extraOptions, key ) && 'ignoreElements' !== key ) {
				options[ key ] = extraOptions[ key ];
			}
		}

		return window.html2canvas( document.documentElement, options ).then( function ( canvas ) {
			canvas.toDataURL( 'image/png' );
			return canvas;
		} );
	}

	/**
	 * Retry the capture with any cross-origin <img> elements visible in the
	 * viewport excluded from rendering, then paint a placeholder box over
	 * each excluded spot so the rest of the layout/text is still usable.
	 *
	 * @param {*} originalError Error from the first capture attempt (logged if this retry also fails).
	 */
	function retryWithoutCrossOriginImages( originalError ) {
		var skipped = getViewportCrossOriginImages();

		if ( ! skipped.length ) {
			onCaptureFailed( originalError );
			return;
		}

		var skippedEls = skipped.map( function ( item ) {
			return item.el;
		} );

		runCapture( {
			useCORS: true,
			allowTaint: false,
			ignoreElements: function ( el ) {
				return -1 !== skippedEls.indexOf( el );
			},
		} )
			.then( function ( canvas ) {
				drawPlaceholders( canvas, skipped );
				loadScreenshot( canvas.toDataURL( 'image/png' ) );
			} )
			.catch( function ( error ) {
				// eslint-disable-next-line no-console
				console.error( '[FeedHat] Retry capture without cross-origin images also failed:', error );
				onCaptureFailed( error );
			} );
	}

	/**
	 * Find <img> elements in the viewport whose src is on a different origin
	 * than the current page — the ones that can taint the capture canvas
	 * when their server doesn't send CORS headers.
	 *
	 * @return {Array<{el: HTMLImageElement, rect: DOMRect}>}
	 */
	function getViewportCrossOriginImages() {
		var candidates = document.querySelectorAll( 'img' );
		var results = [];

		candidates.forEach( function ( img ) {
			if ( ! img.src || isSameOrigin( img.src ) ) {
				return;
			}

			var rect = img.getBoundingClientRect();

			if ( rect.width <= 0 || rect.height <= 0 ) {
				return;
			}

			if ( rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth ) {
				return;
			}

			results.push( { el: img, rect: rect } );
		} );

		return results;
	}

	function isSameOrigin( url ) {
		try {
			return new URL( url, window.location.href ).origin === window.location.origin;
		} catch ( error ) {
			return true;
		}
	}

	/**
	 * Paint a neutral placeholder box + label over each region that had to
	 * be excluded from the capture, so it's obvious to the reporter (and
	 * whoever reviews the feedback) that something was there.
	 *
	 * @param {HTMLCanvasElement} canvas  The captured canvas (mutated in place).
	 * @param {Array}             skipped Entries from getViewportCrossOriginImages().
	 */
	function drawPlaceholders( canvas, skipped ) {
		var ctx = canvas.getContext( '2d' );
		var scaleX = canvas.width / window.innerWidth;
		var scaleY = canvas.height / window.innerHeight;

		ctx.save();
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.font = Math.max( 11, Math.round( 12 * scaleX ) ) + 'px sans-serif';

		skipped.forEach( function ( item ) {
			var x = item.rect.left * scaleX;
			var y = item.rect.top * scaleY;
			var w = item.rect.width * scaleX;
			var h = item.rect.height * scaleY;

			ctx.fillStyle = 'rgba(120, 120, 120, 0.18)';
			ctx.fillRect( x, y, w, h );
			ctx.strokeStyle = 'rgba(120, 120, 120, 0.5)';
			ctx.strokeRect( x, y, w, h );

			ctx.fillStyle = 'rgba(60, 60, 60, 0.9)';
			ctx.fillText( config.i18n.imageUnavailable, x + w / 2, y + h / 2 );
		} );

		ctx.restore();
	}

	function onCaptureFailed( error ) {
		// eslint-disable-next-line no-console
		console.error( '[FeedHat] Screenshot capture error:', error );

		if ( ! els.captureStatus ) {
			return;
		}

		els.captureStatus.textContent = config.i18n.captureFailedNotice;
	}

	function loadScreenshot( dataUrl ) {
		var img = new Image();

		img.onload = function () {
			state.baseImage = img;
			els.canvas.width = img.width;
			els.canvas.height = img.height;

			var wrapWidth = els.canvasWrap.clientWidth || img.width;

			if ( img.width > wrapWidth ) {
				els.canvas.style.width = '100%';
				els.canvas.style.height = 'auto';
				// Strokes are drawn in canvas pixel space, which can be
				// much higher-resolution than the on-screen display size
				// (e.g. a 2x/3x devicePixelRatio capture shown at 100%
				// container width) — scale line widths up to compensate,
				// so they still look bold once downscaled visually.
				state.strokeScale = img.width / wrapWidth;
			} else {
				els.canvas.style.width = img.width + 'px';
				els.canvas.style.height = img.height + 'px';
				state.strokeScale = 1;
			}

			els.captureStatus.style.display = 'none';
			els.canvas.style.display = 'block';
			redraw();
		};

		img.onerror = onCaptureFailed;
		img.src = dataUrl;
	}

	function redraw() {
		if ( ! els.ctx ) {
			return;
		}

		els.ctx.clearRect( 0, 0, els.canvas.width, els.canvas.height );

		if ( state.baseImage ) {
			els.ctx.drawImage( state.baseImage, 0, 0 );
		}

		state.shapes.forEach( drawShape );

		if ( state.current ) {
			drawShape( state.current );
		}

		if ( state.selectedShape ) {
			drawSelectionOutline( state.selectedShape );
		}
	}

	/**
	 * Dashed bounding-box outline around the shape currently selected with
	 * the Move tool, so it's clear what the color picker will recolor.
	 */
	function drawSelectionOutline( shape ) {
		var box = getShapeBoundingBox( shape );

		if ( ! box ) {
			return;
		}

		var pad = 6 * ( state.strokeScale || 1 );
		var ctx = els.ctx;

		ctx.save();
		ctx.setLineDash( [ 4 * ( state.strokeScale || 1 ), 4 * ( state.strokeScale || 1 ) ] );
		ctx.strokeStyle = '#2271b1';
		ctx.lineWidth = 1.5 * ( state.strokeScale || 1 );
		ctx.strokeRect(
			box.minX - pad,
			box.minY - pad,
			box.maxX - box.minX + pad * 2,
			box.maxY - box.minY + pad * 2
		);
		ctx.restore();
	}

	function getShapeBoundingBox( shape ) {
		if ( 'rect' === shape.tool || 'arrow' === shape.tool ) {
			return {
				minX: Math.min( shape.x1, shape.x2 ),
				maxX: Math.max( shape.x1, shape.x2 ),
				minY: Math.min( shape.y1, shape.y2 ),
				maxY: Math.max( shape.y1, shape.y2 ),
			};
		}

		if ( 'pen' === shape.tool ) {
			var xs = shape.points.map( function ( point ) {
				return point.x;
			} );
			var ys = shape.points.map( function ( point ) {
				return point.y;
			} );

			return {
				minX: Math.min.apply( null, xs ),
				maxX: Math.max.apply( null, xs ),
				minY: Math.min.apply( null, ys ),
				maxY: Math.max.apply( null, ys ),
			};
		}

		if ( 'text' === shape.tool ) {
			var metrics = measureTextShape( shape );

			return {
				minX: shape.x,
				maxX: shape.x + metrics.width,
				minY: shape.y - metrics.height,
				maxY: shape.y,
			};
		}

		if ( 'pin' === shape.tool ) {
			var pinRadiusBox = BASE_PIN_RADIUS * ( state.strokeScale || 1 );

			return {
				minX: shape.x - pinRadiusBox,
				maxX: shape.x + pinRadiusBox,
				minY: shape.y - pinRadiusBox,
				maxY: shape.y + pinRadiusBox,
			};
		}

		return null;
	}

	function drawShape( shape ) {
		var ctx = els.ctx;
		ctx.strokeStyle = shape.color;
		ctx.fillStyle = shape.color;
		ctx.lineWidth = BASE_LINE_WIDTH * ( state.strokeScale || 1 );
		ctx.lineJoin = 'round';
		ctx.lineCap = 'round';

		if ( 'pen' === shape.tool ) {
			ctx.beginPath();
			shape.points.forEach( function ( point, index ) {
				if ( 0 === index ) {
					ctx.moveTo( point.x, point.y );
				} else {
					ctx.lineTo( point.x, point.y );
				}
			} );
			ctx.stroke();
		} else if ( 'rect' === shape.tool ) {
			ctx.strokeRect( shape.x1, shape.y1, shape.x2 - shape.x1, shape.y2 - shape.y1 );
		} else if ( 'arrow' === shape.tool ) {
			drawArrow( ctx, shape.x1, shape.y1, shape.x2, shape.y2 );
		} else if ( 'text' === shape.tool ) {
			ctx.font = ( shape.fontSize || BASE_FONT_SIZE ) + 'px sans-serif';
			ctx.textBaseline = 'alphabetic';
			ctx.fillText( shape.text, shape.x, shape.y );
		} else if ( 'pin' === shape.tool ) {
			var pinRadius = BASE_PIN_RADIUS * ( state.strokeScale || 1 );

			ctx.beginPath();
			ctx.arc( shape.x, shape.y, pinRadius, 0, Math.PI * 2 );
			ctx.fill();
			ctx.lineWidth = 2 * ( state.strokeScale || 1 );
			ctx.strokeStyle = '#fff';
			ctx.stroke();

			ctx.fillStyle = '#fff';
			ctx.font = 'bold ' + Math.round( pinRadius ) + 'px sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( String( shape.number ), shape.x, shape.y );
			ctx.textAlign = 'left';
			ctx.textBaseline = 'alphabetic';
		}
	}

	/**
	 * Update the canvas cursor to hint at the active tool's behaviour.
	 */
	function updateCanvasCursor() {
		if ( ! els.canvas ) {
			return;
		}

		els.canvas.style.cursor = 'move' === state.tool ? 'move' : ( 'text' === state.tool ? 'text' : 'crosshair' );
	}

	function drawArrow( ctx, x1, y1, x2, y2 ) {
		var headLength = BASE_ARROW_HEAD_LENGTH * ( state.strokeScale || 1 );
		var angle = Math.atan2( y2 - y1, x2 - x1 );

		ctx.beginPath();
		ctx.moveTo( x1, y1 );
		ctx.lineTo( x2, y2 );
		ctx.stroke();

		ctx.beginPath();
		ctx.moveTo( x2, y2 );
		ctx.lineTo( x2 - headLength * Math.cos( angle - Math.PI / 6 ), y2 - headLength * Math.sin( angle - Math.PI / 6 ) );
		ctx.lineTo( x2 - headLength * Math.cos( angle + Math.PI / 6 ), y2 - headLength * Math.sin( angle + Math.PI / 6 ) );
		ctx.closePath();
		ctx.fill();
	}

	function bindCanvasEvents( canvas ) {
		canvas.addEventListener( 'pointerdown', onPointerDown );
		canvas.addEventListener( 'pointermove', onPointerMove );
		window.addEventListener( 'pointerup', onPointerUp );
	}

	function getCanvasPoint( evt ) {
		var rect = els.canvas.getBoundingClientRect();
		var scaleX = els.canvas.width / rect.width;
		var scaleY = els.canvas.height / rect.height;

		return {
			x: ( evt.clientX - rect.left ) * scaleX,
			y: ( evt.clientY - rect.top ) * scaleY,
		};
	}

	/**
	 * Next pin's number — sequential among pins currently on the canvas
	 * (Undo/Clear can remove some, so this is "count so far", not a
	 * separately tracked running total).
	 */
	function countPinShapes() {
		return state.shapes.filter( function ( shape ) {
			return 'pin' === shape.tool;
		} ).length;
	}

	function onPointerDown( evt ) {
		if ( ! state.baseImage || state.submitting ) {
			return;
		}

		var point = getCanvasPoint( evt );

		if ( 'move' === state.tool ) {
			evt.preventDefault();
			var hit = hitTestShape( point );

			state.selectedShape = hit;

			if ( hit ) {
				state.draggingShape = hit;
				state.lastDragPoint = point;
			}

			redraw();
			return;
		}

		if ( 'text' === state.tool ) {
			evt.preventDefault();
			openTextInput( point );
			return;
		}

		if ( 'pin' === state.tool ) {
			evt.preventDefault();

			var pinShape = {
				tool: 'pin',
				color: state.color,
				x: point.x,
				y: point.y,
				number: countPinShapes() + 1,
				description: '',
			};

			state.shapes.push( pinShape );
			redraw();
			openPinNoteEditor( pinShape, evt.clientX, evt.clientY );

			return;
		}

		evt.preventDefault();
		state.drawing = true;

		if ( 'pen' === state.tool ) {
			state.current = { tool: 'pen', color: state.color, points: [ point ] };
		} else {
			state.current = { tool: state.tool, color: state.color, x1: point.x, y1: point.y, x2: point.x, y2: point.y };
		}
	}

	function onPointerMove( evt ) {
		if ( state.draggingShape ) {
			var dragPoint = getCanvasPoint( evt );
			moveShape( state.draggingShape, dragPoint.x - state.lastDragPoint.x, dragPoint.y - state.lastDragPoint.y );
			state.lastDragPoint = dragPoint;
			redraw();
			return;
		}

		if ( ! state.drawing || ! state.current ) {
			return;
		}

		var point = getCanvasPoint( evt );

		if ( 'pen' === state.current.tool ) {
			state.current.points.push( point );
		} else {
			state.current.x2 = point.x;
			state.current.y2 = point.y;
		}

		redraw();
	}

	function onPointerUp() {
		if ( state.draggingShape ) {
			state.draggingShape = null;
			state.lastDragPoint = null;
			return;
		}

		if ( ! state.drawing ) {
			return;
		}

		state.drawing = false;

		if ( state.current ) {
			state.shapes.push( state.current );
			state.current = null;
		}

		redraw();
	}

	/**
	 * Shift a shape's coordinates by (dx, dy) canvas-space units — used
	 * while dragging it around with the Move tool.
	 */
	function moveShape( shape, dx, dy ) {
		if ( 'pen' === shape.tool ) {
			shape.points.forEach( function ( point ) {
				point.x += dx;
				point.y += dy;
			} );
		} else if ( 'text' === shape.tool || 'pin' === shape.tool ) {
			shape.x += dx;
			shape.y += dy;
		} else {
			shape.x1 += dx;
			shape.y1 += dy;
			shape.x2 += dx;
			shape.y2 += dy;
		}
	}

	/**
	 * Find the top-most drawn shape under a canvas-space point, if any.
	 */
	function hitTestShape( point ) {
		for ( var i = state.shapes.length - 1; i >= 0; i-- ) {
			if ( shapeContainsPoint( state.shapes[ i ], point ) ) {
				return state.shapes[ i ];
			}
		}

		return null;
	}

	function shapeContainsPoint( shape, point ) {
		var pad = HIT_TEST_PADDING * ( state.strokeScale || 1 );

		if ( 'rect' === shape.tool ) {
			var minX = Math.min( shape.x1, shape.x2 ) - pad;
			var maxX = Math.max( shape.x1, shape.x2 ) + pad;
			var minY = Math.min( shape.y1, shape.y2 ) - pad;
			var maxY = Math.max( shape.y1, shape.y2 ) + pad;

			return point.x >= minX && point.x <= maxX && point.y >= minY && point.y <= maxY;
		}

		if ( 'arrow' === shape.tool ) {
			return distanceToSegment( point, { x: shape.x1, y: shape.y1 }, { x: shape.x2, y: shape.y2 } ) <= pad;
		}

		if ( 'pen' === shape.tool ) {
			if ( 1 === shape.points.length ) {
				return distanceBetween( point, shape.points[ 0 ] ) <= pad;
			}

			for ( var j = 0; j < shape.points.length - 1; j++ ) {
				if ( distanceToSegment( point, shape.points[ j ], shape.points[ j + 1 ] ) <= pad ) {
					return true;
				}
			}

			return false;
		}

		if ( 'text' === shape.tool ) {
			var metrics = measureTextShape( shape );

			return (
				point.x >= shape.x - pad &&
				point.x <= shape.x + metrics.width + pad &&
				point.y >= shape.y - metrics.height - pad &&
				point.y <= shape.y + pad
			);
		}

		if ( 'pin' === shape.tool ) {
			return distanceBetween( point, { x: shape.x, y: shape.y } ) <= BASE_PIN_RADIUS * ( state.strokeScale || 1 ) + pad;
		}

		return false;
	}

	function measureTextShape( shape ) {
		var ctx = els.ctx;
		ctx.font = ( shape.fontSize || BASE_FONT_SIZE ) + 'px sans-serif';

		return { width: ctx.measureText( shape.text ).width, height: shape.fontSize || BASE_FONT_SIZE };
	}

	function distanceBetween( a, b ) {
		return Math.sqrt( Math.pow( a.x - b.x, 2 ) + Math.pow( a.y - b.y, 2 ) );
	}

	function distanceToSegment( point, a, b ) {
		var lengthSquared = Math.pow( b.x - a.x, 2 ) + Math.pow( b.y - a.y, 2 );

		if ( 0 === lengthSquared ) {
			return distanceBetween( point, a );
		}

		var t = ( ( point.x - a.x ) * ( b.x - a.x ) + ( point.y - a.y ) * ( b.y - a.y ) ) / lengthSquared;
		t = Math.max( 0, Math.min( 1, t ) );

		return distanceBetween( point, { x: a.x + t * ( b.x - a.x ), y: a.y + t * ( b.y - a.y ) } );
	}

	/**
	 * Open a small text input positioned over the clicked canvas point so
	 * the reporter can type directly onto the screenshot. Committed as a
	 * 'text' shape on blur/Enter, discarded on Escape.
	 */
	function openTextInput( canvasPoint ) {
		commitTextInput();

		var canvasRect = els.canvas.getBoundingClientRect();
		var wrapRect = els.canvasWrap.getBoundingClientRect();
		var scaleX = canvasRect.width / els.canvas.width;

		var left = ( canvasRect.left - wrapRect.left ) + els.canvasWrap.scrollLeft + canvasPoint.x * scaleX;
		var top = ( canvasRect.top - wrapRect.top ) + els.canvasWrap.scrollTop + canvasPoint.y * scaleX - BASE_FONT_SIZE;

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'cf-text-input';
		input.style.left = left + 'px';
		input.style.top = top + 'px';
		input.style.color = state.color;
		input.style.fontSize = BASE_FONT_SIZE + 'px';

		input.addEventListener( 'keydown', function ( evt ) {
			if ( 'Enter' === evt.key ) {
				evt.preventDefault();
				commitTextInput();
			} else if ( 'Escape' === evt.key ) {
				evt.preventDefault();
				removeTextInput();
			}
		} );

		input.addEventListener( 'blur', commitTextInput );

		els.canvasWrap.appendChild( input );
		els.textInput = input;
		state.pendingTextPoint = canvasPoint;

		window.requestAnimationFrame( function () {
			input.focus();
		} );
	}

	function commitTextInput() {
		if ( ! els.textInput ) {
			return;
		}

		var value = els.textInput.value.trim();
		var point = state.pendingTextPoint;

		removeTextInput();

		if ( value ) {
			state.shapes.push( {
				tool: 'text',
				color: state.color,
				x: point.x,
				y: point.y,
				text: value,
				fontSize: BASE_FONT_SIZE * ( state.strokeScale || 1 ),
			} );
			redraw();
		}
	}

	function removeTextInput() {
		if ( els.textInput && els.textInput.parentNode ) {
			els.textInput.removeEventListener( 'blur', commitTextInput );
			els.textInput.parentNode.removeChild( els.textInput );
		}

		els.textInput = null;
		state.pendingTextPoint = null;
	}

	/**
	 * A small floating editor for a just-placed pin's optional title +
	 * description, anchored near the click in screen space (not canvas
	 * space, since it floats over the canvas rather than drawing onto it).
	 * No Save/Cancel buttons — every keystroke is written straight onto the
	 * shape object, and clicking away or Escape simply closes the editor;
	 * whatever was typed (including nothing) already stuck.
	 *
	 * @param {Object} pinShape The shape object just pushed to state.shapes.
	 * @param {number} clientX  Viewport-relative X of the placing click.
	 * @param {number} clientY  Viewport-relative Y of the placing click.
	 */
	function openPinNoteEditor( pinShape, clientX, clientY ) {
		closePinNoteEditor();

		var editor = document.createElement( 'div' );
		editor.className = 'cf-pin-editor';

		var descInput = document.createElement( 'textarea' );
		descInput.className = 'cf-pin-editor__desc';
		descInput.placeholder = config.i18n.pinDescPlaceholder;
		descInput.maxLength = config.maxNoteLength;
		editor.appendChild( descInput );

		document.body.appendChild( editor );

		var rect = editor.getBoundingClientRect();
		var left = Math.min( clientX + 12, window.innerWidth - rect.width - 12 );
		var top = Math.min( clientY + 12, window.innerHeight - rect.height - 12 );
		editor.style.left = Math.max( 12, left ) + 'px';
		editor.style.top = Math.max( 12, top ) + 'px';

		function persist() {
			pinShape.description = descInput.value.trim();
		}

		function onOutsideClick( evt ) {
			if ( ! editor.contains( evt.target ) ) {
				closePinNoteEditor();
			}
		}

		function onKeydown( evt ) {
			if ( 'Escape' === evt.key ) {
				closePinNoteEditor();
			}
		}

		descInput.addEventListener( 'input', persist );

		// A tick later, not immediately: the same click that placed this
		// pin would otherwise be seen as an "outside click" and instantly
		// close the editor before the reporter ever sees it.
		window.setTimeout( function () {
			document.addEventListener( 'pointerdown', onOutsideClick, true );
			document.addEventListener( 'keydown', onKeydown, true );
		}, 0 );

		els.pinEditor = editor;
		els.pinEditorCleanup = function () {
			document.removeEventListener( 'pointerdown', onOutsideClick, true );
			document.removeEventListener( 'keydown', onKeydown, true );
		};

		descInput.focus();
	}

	function closePinNoteEditor() {
		if ( els.pinEditorCleanup ) {
			els.pinEditorCleanup();
			els.pinEditorCleanup = null;
		}

		if ( els.pinEditor && els.pinEditor.parentNode ) {
			els.pinEditor.parentNode.removeChild( els.pinEditor );
		}

		els.pinEditor = null;
	}

	/**
	 * Resolve { browser, os } for the auto-captured context. Prefers the
	 * User-Agent Client Hints API (accurate real version numbers, e.g.
	 * "Chrome 122.0.6261.94" and "macOS 14.4.0") on browsers that support
	 * it; falls back to parsing navigator.userAgent with regexes on
	 * browsers that don't expose it at all (Safari, Firefox — by design,
	 * as an anti-fingerprinting measure, so there is no better source for
	 * those without a native browser API to ask).
	 *
	 * @return {Promise<{browser: string, os: string}>}
	 */
	function detectEnvironment() {
		if ( navigator.userAgentData && typeof navigator.userAgentData.getHighEntropyValues === 'function' ) {
			return navigator.userAgentData
				.getHighEntropyValues( [ 'platform', 'platformVersion', 'fullVersionList' ] )
				.then( function ( uaData ) {
					return {
						browser: formatBrowserFromUACH( uaData ),
						os: formatOSFromUACH( uaData ),
					};
				} )
				.catch( function () {
					return detectEnvironmentFallback();
				} );
		}

		return Promise.resolve( detectEnvironmentFallback() );
	}

	function detectEnvironmentFallback() {
		return { browser: detectBrowserFromUA(), os: detectOSFromUA() };
	}

	/**
	 * @param {UADataValues} uaData
	 * @return {string}
	 */
	function formatBrowserFromUACH( uaData ) {
		var brands = uaData.fullVersionList || [];

		// fullVersionList always includes a "greased" placeholder brand
		// (e.g. "Not)A;Brand") plus the generic "Chromium" engine brand
		// alongside the browser's own — prefer the real one.
		var preferred = brands.filter( function ( brand ) {
			return ! /not.?a.?brand/i.test( brand.brand ) && 'Chromium' !== brand.brand;
		} )[ 0 ];

		if ( ! preferred ) {
			preferred = brands.filter( function ( brand ) {
				return 'Chromium' === brand.brand;
			} )[ 0 ];
		}

		if ( ! preferred ) {
			return detectBrowserFromUA();
		}

		return preferred.brand + ' ' + preferred.version;
	}

	/**
	 * @param {UADataValues} uaData
	 * @return {string}
	 */
	function formatOSFromUACH( uaData ) {
		var platform = uaData.platform || '';
		var version = uaData.platformVersion || '';

		if ( ! platform ) {
			return detectOSFromUA();
		}

		if ( 'Windows' === platform && version ) {
			// Windows 10 and 11 share the same NT kernel version and are
			// only distinguishable via this specific platformVersion
			// threshold — documented Chromium behavior, not a guess.
			var majorVersion = parseInt( version.split( '.' )[ 0 ], 10 );
			return 'Windows ' + ( majorVersion >= 13 ? '11' : '10' );
		}

		return version ? platform + ' ' + version : platform;
	}

	function detectBrowserFromUA() {
		var ua = navigator.userAgent;
		var browsers = [
			{ name: 'Edge', re: /Edg\/([\d.]+)/ },
			{ name: 'Opera', re: /OPR\/([\d.]+)/ },
			{ name: 'Chrome', re: /Chrome\/([\d.]+)/ },
			{ name: 'Firefox', re: /Firefox\/([\d.]+)/ },
			{ name: 'Safari', re: /Version\/([\d.]+).*Safari/ },
			{ name: 'Internet Explorer', re: /(?:MSIE |rv:)([\d.]+)/ },
		];

		for ( var i = 0; i < browsers.length; i++ ) {
			var match = ua.match( browsers[ i ].re );

			if ( match ) {
				return browsers[ i ].name + ' ' + match[ 1 ];
			}
		}

		return 'Unknown';
	}

	function detectOSFromUA() {
		var ua = navigator.userAgent;
		var list = [
			{ name: 'Windows', re: /Windows NT ([\d.]+)/ },
			{ name: 'macOS', re: /Mac OS X ([\d_.]+)/ },
			{ name: 'iOS', re: /(?:iPhone|iPad).*OS ([\d_]+)/ },
			{ name: 'Android', re: /Android ([\d.]+)/ },
			{ name: 'Linux', re: /Linux/ },
		];

		for ( var i = 0; i < list.length; i++ ) {
			var match = ua.match( list[ i ].re );

			if ( match ) {
				return list[ i ].name + ( match[ 1 ] ? ' ' + match[ 1 ].replace( /_/g, '.' ) : '' );
			}
		}

		return 'Unknown';
	}

	function showMessage( text, type ) {
		if ( ! els.message ) {
			return;
		}

		els.message.textContent = text;
		els.message.className = 'cf-message is-' + type;
		els.message.style.display = 'block';
	}

	// Appended after showMessage()'s success text — a real, clickable link
	// rather than plain text, since showMessage() itself only ever sets
	// textContent (see above) and would wipe out any markup placed there.
	function showTrackingLink( url ) {
		if ( ! els.message ) {
			return;
		}

		var link = document.createElement( 'a' );
		link.href = url;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.className = 'cf-tracking-link';
		link.textContent = config.i18n.trackLinkLabel;

		els.message.appendChild( document.createElement( 'br' ) );
		els.message.appendChild( link );
	}

	// Locks every field and drawing tool — used both while a request is in
	// flight (otherwise a shape drawn or a note edited between the click and
	// the server's response would silently not be part of what actually
	// gets sent, or of a retried request after a stale-nonce recovery) and
	// after a successful send, while the panel waits to auto-close.
	function setFieldsLocked( locked ) {
		els.note.disabled = locked;

		if ( els.title ) {
			els.title.disabled = locked;
		}

		if ( els.reporterName ) {
			els.reporterName.disabled = locked;
		}

		if ( els.reporterEmail ) {
			els.reporterEmail.disabled = locked;
		}

		if ( els.colorInput ) {
			els.colorInput.disabled = locked;
		}

		if ( els.undoBtn ) {
			els.undoBtn.disabled = locked;
		}

		if ( els.clearBtn ) {
			els.clearBtn.disabled = locked;
		}

		Object.keys( els.toolButtons || {} ).forEach( function ( key ) {
			els.toolButtons[ key ].disabled = locked;
		} );

		if ( els.canvas ) {
			els.canvas.style.pointerEvents = locked ? 'none' : '';
		}
	}

	function setSubmitting( isSubmitting ) {
		state.submitting = isSubmitting;
		els.submitBtn.disabled = isSubmitting;
		els.submitBtn.classList.toggle( 'is-loading', isSubmitting );
		els.submitBtn.textContent = isSubmitting ? config.i18n.submitting : config.i18n.submit;
		setFieldsLocked( isSubmitting );
	}

	// Pro: renders the Cloudflare Turnstile widget into the container added
	// in buildModal() when config.captchaEnabled. Polls briefly for
	// window.turnstile since its script loads independently and may not
	// have finished by the time the panel opens; gives up silently after a
	// few seconds (submitFeedback() then blocks on the missing token with
	// its own message rather than this failing loudly here).
	function renderCaptchaWidget() {
		if ( ! config.captchaEnabled || ! els.captchaContainer ) {
			return;
		}

		var attemptsLeft = 25;

		function attempt() {
			if ( window.turnstile ) {
				state.captchaWidgetId = window.turnstile.render( els.captchaContainer, {
					sitekey: config.captchaSiteKey,
					callback: function ( token ) {
						state.captchaToken = token;
					},
					'expired-callback': function () {
						state.captchaToken = '';
					},
				} );
				return;
			}

			attemptsLeft -= 1;

			if ( attemptsLeft > 0 ) {
				window.setTimeout( attempt, 200 );
			}
		}

		attempt();
	}

	function submitFeedback() {
		if ( state.submitting ) {
			return;
		}

		commitTextInput();
		closePinNoteEditor();

		var note = els.note.value.trim();

		if ( ! note ) {
			showMessage( config.i18n.noteRequired, 'error' );
			return;
		}

		if ( config.captchaEnabled && ! state.captchaToken ) {
			showMessage( config.i18n.captchaPending, 'error' );
			return;
		}

		// Should already be resolved by now (kicked off when the panel
		// opened, well before a reporter finishes drawing/typing) — the
		// fallback only matters if someone submits unusually fast.
		var environment = state.environment || detectEnvironmentFallback();

		var payload = {
			note: note,
			title: els.title ? els.title.value.trim() : '',
			page_url: window.location.href,
			browser: environment.browser,
			os: environment.os,
			screen_size: window.screen.width + 'x' + window.screen.height,
			viewport_size: window.innerWidth + 'x' + window.innerHeight,
			wp_version: config.wpVersion,
			website: els.honeypot ? els.honeypot.value : '',
			opened_at: Math.floor( state.openedAt / 1000 ),
		};

		if ( config.captchaEnabled ) {
			payload.turnstile_token = state.captchaToken;
		}

		if ( state.baseImage ) {
			payload.screenshot = els.canvas.toDataURL( 'image/jpeg', 0.8 );
		}

		var pinNotes = state.shapes
			.filter( function ( shape ) {
				return 'pin' === shape.tool && shape.description;
			} )
			.map( function ( shape ) {
				return { number: shape.number, description: shape.description };
			} );

		if ( pinNotes.length ) {
			payload.pins = JSON.stringify( pinNotes );
		}

		if ( els.reporterName && els.reporterName.value.trim() ) {
			payload.reporter_name = els.reporterName.value.trim();
		}

		if ( els.reporterEmail && els.reporterEmail.value.trim() ) {
			payload.reporter_email = els.reporterEmail.value.trim();
		}

		setSubmitting( true );

		postFeedback( payload, config.nonce )
			.then( function ( result ) {
				// A page-cache plugin (or simply a panel left open long
				// enough for the nonce to expire) can leave the localized
				// nonce stale. Fetch a fresh one and retry exactly once
				// before surfacing an error.
				if ( ! result.ok && result.body && 'feedhat_invalid_nonce' === result.body.code ) {
					return fetchFreshNonce().then( function ( nonce ) {
						return postFeedback( payload, nonce );
					} );
				}

				return result;
			} )
			.then( function ( result ) {
				setSubmitting( false );

				if ( result.ok ) {
					showMessage( config.i18n.success, 'success' );

					// No auto-close: a tracking link worth reading/copying is
					// shown right after this, so the reporter closes manually
					// once they're done with it instead of it vanishing after
					// a fixed delay.
					if ( result.body && result.body.tracking_url ) {
						showTrackingLink( result.body.tracking_url );
					}

					disableFormAfterSuccess();
				} else {
					showMessage( ( result.body && result.body.message ) || config.i18n.genericError, 'error' );
				}
			} )
			.catch( function () {
				setSubmitting( false );
				showMessage( config.i18n.networkError, 'error' );
			} );
	}

	function postFeedback( payload, nonce ) {
		return fetch( config.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-FeedHat-Nonce': nonce,
			},
			body: JSON.stringify( payload ),
		} ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
		} );
	}

	function fetchFreshNonce() {
		if ( ! config.nonceUrl ) {
			return Promise.resolve( config.nonce );
		}

		return fetch( config.nonceUrl, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				if ( body && body.nonce ) {
					config.nonce = body.nonce;
					return body.nonce;
				}

				return config.nonce;
			} )
			.catch( function () {
				return config.nonce;
			} );
	}

	function disableFormAfterSuccess() {
		// setSubmitting( false ) already ran by this point (see
		// submitFeedback()) and unlocked everything — re-lock it all rather
		// than leave a "sent" form still looking editable while the
		// reporter reads the success message and tracking link.
		setFieldsLocked( true );
		els.submitBtn.disabled = true;

		// "Cancel" no longer makes sense once there's nothing left to
		// discard — same button, same click handler (closePanel), just a
		// relabel now that its purpose has changed to dismissing the panel.
		if ( els.cancelBtn ) {
			els.cancelBtn.textContent = config.i18n.close;
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
