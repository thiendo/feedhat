<?php
/**
 * Targeting: whole site, selected pages/posts, or the fixed
 * `?feedhat=1` URL param.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Targeting_Basic {

	/**
	 * Hook registration.
	 */
	public static function init() {
	}

	/**
	 * Whether the widget should display on the current front-end request.
	 *
	 * The `?feedhat=1` URL param is a display override: it shows the
	 * button regardless of the configured targeting mode (but not when the
	 * widget has been switched off entirely in settings).
	 *
	 * @return bool
	 */
	public static function should_display() {
		$settings = FeedHat_Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		if ( self::is_url_param_active() ) {
			return true;
		}

		switch ( $settings['targeting_mode'] ) {
			case 'specific_pages':
				return self::is_current_page_targeted( $settings['targeting_page_ids'] );

			case 'url_param':
				return false;

			case 'all_site':
			default:
				return true;
		}
	}

	/**
	 * Whether the fixed `?feedhat=1` URL param is present with the exact
	 * expected value (case-sensitive, must equal "1").
	 *
	 * @return bool
	 */
	public static function is_url_param_active() {
		if ( ! isset( $_GET[ FEEDHAT_URL_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$value = sanitize_text_field( wp_unslash( $_GET[ FEEDHAT_URL_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return '1' === $value;
	}

	/**
	 * Whether the current singular page/post is in the targeted list.
	 *
	 * @param array $page_ids Configured page/post IDs.
	 * @return bool
	 */
	private static function is_current_page_targeted( $page_ids ) {
		if ( empty( $page_ids ) || ! is_singular() ) {
			return false;
		}

		$page_ids = array_map( 'absint', (array) $page_ids );

		return in_array( get_queried_object_id(), $page_ids, true );
	}
}
