/**
 * FeedHat — Settings page: color picker + targeting panel toggle.
 */
( function ( $ ) {
	'use strict';

	function toggleTargetingPanels() {
		var mode = $( 'input[name="targeting_mode"]:checked' ).val();

		$( '.cf-targeting-panel' ).each( function () {
			var panel = $( this );
			panel.toggle( panel.data( 'mode' ) === mode );
		} );
	}

	/**
	 * Generic conditional-field toggle shared by every Pro settings section:
	 * any element with `data-cf-show-when="field_name=value"` shows only
	 * while that checkbox/radio-group/select currently equals `value`
	 * (checkboxes compare against "1"/"0"). One mechanism reused everywhere
	 * instead of bespoke JS per section.
	 */
	function initConditionalFields() {
		$( '[data-cf-show-when]' ).each( function () {
			var el   = $( this );
			var rule = String( el.data( 'cf-show-when' ) ).split( '=' );
			var name = rule[ 0 ];
			var value = rule[ 1 ];
			var group = $( '[name="' + name + '"]' );

			function sync() {
				var current;

				if ( group.is( ':checkbox' ) ) {
					current = group.is( ':checked' ) ? '1' : '0';
				} else if ( group.is( ':radio' ) ) {
					current = group.filter( ':checked' ).val();
				} else {
					current = group.val();
				}

				el.toggle( current === value );
			}

			group.on( 'change', sync );
			sync();
		} );
	}

	/**
	 * All tab panels stay in the DOM at all times (just hidden via CSS), so
	 * switching tabs never drops any field from the single form/single Save
	 * button — only which panel is visible changes.
	 */
	function initTabs() {
		var tabs   = $( '.cf-settings__tabs .nav-tab' );
		var panels = $( '.cf-tab-panel' );

		if ( ! tabs.length ) {
			return;
		}

		function activate( slug ) {
			tabs.each( function () {
				$( this ).toggleClass( 'nav-tab-active', $( this ).data( 'cf-tab' ) === slug );
			} );
			panels.each( function () {
				$( this ).toggleClass( 'is-active', this.id === 'cf-tab-' + slug );
			} );
		}

		tabs.on( 'click', function ( evt ) {
			evt.preventDefault();
			var slug = $( this ).data( 'cf-tab' );

			activate( slug );

			// replaceState, not location.hash — setting the hash directly
			// makes some browsers jump-scroll to the (hidden) panel's id.
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', '#cf-tab-' + slug );
			}
		} );

		var initialSlug = window.location.hash.replace( '#cf-tab-', '' );
		var hasInitial   = initialSlug && document.getElementById( 'cf-tab-' + initialSlug );

		activate( hasInitial ? initialSlug : tabs.first().data( 'cf-tab' ) );
	}

	var BUTTON_STYLES = [ 'pill', 'outline', 'icon', 'label', 'tab' ];
	var BUTTON_SIZES = [ 'small', 'medium', 'large' ];
	var BUTTON_POSITIONS = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'middle-right', 'middle-left' ];

	/**
	 * Keeps the "Preview" button in the Widget display section in sync with
	 * the Position/Style/Size/Color/Label fields above it, using the exact
	 * same CSS classes the front-end launcher renders with (see
	 * buildLauncher() in widget.js) so it's an accurate preview, not an
	 * approximation — including the "Edge tab" style's vertical layout on
	 * the two Middle positions.
	 */
	function initButtonPreview() {
		var $preview = $( '#cf-launcher-preview' );

		if ( ! $preview.length ) {
			return;
		}

		var $position = $( 'select[name="button_position"]' );
		var $style = $( 'select[name="button_style"]' );
		var $size = $( 'select[name="button_size"]' );
		var $color = $( 'input[name="button_color"]' );
		var $label = $( 'input[name="button_label"]' );

		function sync() {
			BUTTON_POSITIONS.forEach( function ( value ) {
				$preview.removeClass( 'cf-pos-' + value );
			} );
			BUTTON_STYLES.forEach( function ( value ) {
				$preview.removeClass( 'cf-style-' + value );
			} );
			BUTTON_SIZES.forEach( function ( value ) {
				$preview.removeClass( 'cf-size-' + value );
			} );

			$preview
				.addClass( 'cf-pos-' + $position.val() )
				.addClass( 'cf-style-' + $style.val() )
				.addClass( 'cf-size-' + $size.val() )
				.css( '--cf-color', $color.val() || '#e63946' )
				.attr( 'aria-label', $label.val() )
				.attr( 'data-tooltip', $label.val() );

			$preview.find( '.cf-launcher__label' ).text( $label.val() );
		}

		$position.on( 'change', sync );
		$style.on( 'change', sync );
		$size.on( 'change', sync );
		$label.on( 'input', sync );
		// wp-color-picker updates this input's value and fires a native
		// "change" on it whenever a color is picked — no special API hook
		// needed beyond listening like any other field.
		$color.on( 'change input', sync );
	}

	/**
	 * Pro's white-label logo field (Branding tab): opens the WP Media
	 * Library instead of requiring the admin to know/paste a URL by hand.
	 * Only wires up if the field is actually on the page (Pro inactive on
	 * Free installs never renders it).
	 */
	function initLogoUploader() {
		var $uploadBtn = $( '#cf-wl-logo-upload' );

		if ( ! $uploadBtn.length || ! window.wp || ! wp.media ) {
			return;
		}

		var $urlField = $( '#cf-wl-logo-url' );
		var $preview = $( '#cf-wl-logo-preview' );
		var $removeBtn = $( '#cf-wl-logo-remove' );
		var frame;

		function setLogo( url ) {
			$urlField.val( url );

			if ( url ) {
				$preview.attr( 'src', url ).prop( 'hidden', false );
				$removeBtn.prop( 'hidden', false );
			} else {
				$preview.attr( 'src', '' ).prop( 'hidden', true );
				$removeBtn.prop( 'hidden', true );
			}
		}

		$uploadBtn.on( 'click', function ( evt ) {
			evt.preventDefault();

			if ( ! frame ) {
				frame = wp.media( {
					title: $uploadBtn.text(),
					library: { type: [ 'image/png', 'image/jpeg', 'image/gif', 'image/svg+xml' ] },
					multiple: false,
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					setLogo( attachment.url );
				} );
			}

			frame.open();
		} );

		$removeBtn.on( 'click', function ( evt ) {
			evt.preventDefault();
			setLogo( '' );
		} );

		// Manually typing/clearing the URL field keeps the preview in sync
		// too, not just the uploader button.
		$urlField.on( 'input', function () {
			setLogo( $urlField.val() );
		} );
	}

	$( function () {
		if ( $.fn.wpColorPicker ) {
			$( '.cf-color-field' ).wpColorPicker();
		}

		$( 'input[name="targeting_mode"]' ).on( 'change', toggleTargetingPanels );
		toggleTargetingPanels();

		initConditionalFields();
		initTabs();
		initButtonPreview();
		initLogoUploader();
	} );
} )( jQuery );
