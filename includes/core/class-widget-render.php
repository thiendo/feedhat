<?php
/**
 * Renders the floating feedback button + panel on the front end.
 *
 * Assets are only enqueued when the widget will actually be shown for the
 * current request (targeting matched).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Widget_Render {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Decide whether the widget applies to this request and, if so, enqueue it.
	 */
	public static function maybe_enqueue() {
		if ( is_admin() ) {
			return;
		}

		$should_display = FeedHat_Targeting_Basic::should_display();

		/**
		 * Filters whether the feedback widget should render for the current
		 * request. Basic targeting (whole site / selected pages / ?feedhat=1)
		 * has already run by this point — a separate Pro plugin can layer
		 * post type, taxonomy, URL-pattern, or role targeting on top.
		 *
		 * @param bool $should_display Free's own targeting decision.
		 */
		$should_display = apply_filters( 'feedhat_should_display_widget', $should_display );

		if ( ! $should_display ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Enqueue widget CSS/JS and pass settings + i18n strings to the front end.
	 */
	private static function enqueue_assets() {
		$settings = FeedHat_Settings::get_settings();

		/**
		 * Filters the locale used for the widget's own visible strings —
		 * Pro's language-override feature hooks this to show the widget in
		 * a language independent of the site's own (CLAUDE.md mục 3:
		 * "Ngôn ngữ override"). Empty string (default) means "follow the
		 * site's own language", i.e. no switch at all.
		 *
		 * @param string $locale Locale to switch to for this request, or ''.
		 */
		$locale_override = apply_filters( 'feedhat_widget_locale', '' );

		if ( $locale_override ) {
			switch_to_locale( $locale_override );
		}

		wp_enqueue_style(
			'feedhat-widget',
			FEEDHAT_URL . 'assets/css/widget.css',
			array(),
			FEEDHAT_VERSION
		);

		wp_enqueue_script(
			'feedhat-html2canvas',
			FEEDHAT_URL . 'assets/js/vendor/html2canvas-pro.min.js',
			array(),
			'2.4.0',
			true
		);

		wp_enqueue_script(
			'feedhat-widget',
			FEEDHAT_URL . 'assets/js/widget.js',
			array( 'feedhat-html2canvas' ),
			FEEDHAT_VERSION,
			true
		);

		$localize_data = array(
			'restUrl'       => esc_url_raw( rest_url( 'feedhat/v1/submit' ) ),
			'nonceUrl'      => esc_url_raw( rest_url( 'feedhat/v1/nonce' ) ),
			'nonce'         => wp_create_nonce( 'feedhat_submit' ),
			'position'      => sanitize_html_class( $settings['button_position'] ),
			'offsetX'       => absint( $settings['button_offset_x'] ),
			'offsetY'       => absint( $settings['button_offset_y'] ),
			'buttonStyle'   => sanitize_html_class( $settings['button_style'] ),
			'buttonSize'    => sanitize_html_class( $settings['button_size'] ),
			'buttonColor'   => sanitize_hex_color( $settings['button_color'] ),
			'buttonLabel'   => $settings['button_label'],
			'maxNoteLength' => 2000,
			'wpVersion'     => get_bloginfo( 'version' ),
			'isLoggedIn' => is_user_logged_in(),
			'i18n'       => array(
				'title'               => __( 'Send feedback', 'feedhat' ),
				'close'               => __( 'Close', 'feedhat' ),
				'toolRect'            => __( 'Rectangle', 'feedhat' ),
				'toolArrow'           => __( 'Arrow', 'feedhat' ),
				'toolPen'             => __( 'Pen', 'feedhat' ),
				'toolText'            => __( 'Text', 'feedhat' ),
				'toolPin'             => __( 'Pin', 'feedhat' ),
				'pinDescPlaceholder'  => __( 'Describe in more detail if needed', 'feedhat' ),
				'toolMove'            => __( 'Move', 'feedhat' ),
				'undo'                => __( 'Undo', 'feedhat' ),
				'clear'               => __( 'Clear', 'feedhat' ),
				'colorLabel'          => __( 'Color', 'feedhat' ),
				'notePlaceholder'     => __( 'Describe the issue…', 'feedhat' ),
				'noteLabel'           => __( 'Your feedback', 'feedhat' ),
				'titleLabel'          => __( 'Title', 'feedhat' ),
				'titlePlaceholder'    => __( 'Title (optional)', 'feedhat' ),
				'reporterNameLabel'   => __( 'Name (optional)', 'feedhat' ),
				'reporterEmailLabel'  => __( 'Email (optional)', 'feedhat' ),
				'submit'              => __( 'Send feedback', 'feedhat' ),
				'submitting'          => __( 'Sending…', 'feedhat' ),
				'cancel'              => __( 'Cancel', 'feedhat' ),
				'success'             => __( 'Thanks! Your feedback has been sent.', 'feedhat' ),
				'trackLinkLabel'      => __( 'Track the status of this feedback →', 'feedhat' ),
				'noteRequired'        => __( 'Please describe the issue before sending.', 'feedhat' ),
				'captchaPending'      => __( 'Please wait a moment for verification and try again.', 'feedhat' ),
				'genericError'        => __( 'Something went wrong. Please try again.', 'feedhat' ),
				'networkError'        => __( 'Could not reach the server. Please check your connection and try again.', 'feedhat' ),
				'captureFailedNotice' => __( 'Could not capture a screenshot automatically, but you can still send your note.', 'feedhat' ),
				'capturing'           => __( 'Capturing screenshot…', 'feedhat' ),
				'imageUnavailable'    => __( 'Image unavailable', 'feedhat' ),
			),
		);

		/**
		 * Filters the data localized to the front-end widget script. Used by
		 * the Pro add-on's white-label feature to override branding (logo,
		 * display name, accent color) without touching Free's own code.
		 *
		 * @param array $localize_data
		 */
		$localize_data = apply_filters( 'feedhat_widget_localize_data', $localize_data );

		wp_localize_script( 'feedhat-widget', 'FeedHat', $localize_data );

		if ( $locale_override ) {
			restore_previous_locale();
		}
	}
}
