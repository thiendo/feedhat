<?php
/**
 * Sends a single-recipient email notification (via wp_mail()) when new
 * feedback is submitted, per the site's own `notify_enabled`/`notify_email`
 * settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Email_Notify {

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'feedhat_after_submit', array( __CLASS__, 'maybe_notify' ) );
	}

	/**
	 * @param int $post_id Feedback post ID.
	 */
	public static function maybe_notify( $post_id ) {
		$settings = FeedHat_Settings::get_settings();

		$should_send = ! empty( $settings['notify_enabled'] ) && is_email( $settings['notify_email'] );

		/**
		 * Filters whether Free's own single-recipient notification should
		 * send. The Pro add-on's multi-recipient/routing feature hooks this
		 * to return false once it has its own recipient routing configured,
		 * so a single feedback submission never emails the same inbox twice.
		 *
		 * @param bool $should_send Free's own decision.
		 * @param int  $post_id     Feedback post ID.
		 */
		$should_send = apply_filters( 'feedhat_should_send_default_notification', $should_send, $post_id );

		if ( ! $should_send ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		wp_mail(
			$settings['notify_email'],
			self::build_subject(),
			self::build_message( $post_id, $post )
		);
	}

	/**
	 * @return string
	 */
	private static function build_subject() {
		return sprintf(
			/* translators: %s: site name. */
			__( '[%s] New feedback received', 'feedhat' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * @param int     $post_id Feedback post ID.
	 * @param WP_Post $post    Feedback post object.
	 * @return string
	 */
	private static function build_message( $post_id, $post ) {
		$lines = array(
			__( 'New feedback was submitted on your site:', 'feedhat' ),
			'',
			$post->post_content,
			'',
			sprintf(
				/* translators: %s: page URL the feedback was submitted from. */
				__( 'Page: %s', 'feedhat' ),
				get_post_meta( $post_id, '_cf_page_url', true )
			),
			sprintf(
				/* translators: 1: browser, 2: operating system. */
				__( 'Browser / OS: %1$s / %2$s', 'feedhat' ),
				get_post_meta( $post_id, '_cf_browser', true ),
				get_post_meta( $post_id, '_cf_os', true )
			),
			sprintf(
				/* translators: 1: screen resolution, 2: browser viewport size. */
				__( 'Screen / Viewport: %1$s / %2$s', 'feedhat' ),
				get_post_meta( $post_id, '_cf_screen_size', true ),
				get_post_meta( $post_id, '_cf_viewport_size', true )
			),
			'',
			sprintf(
				/* translators: %s: wp-admin feedback list link. */
				__( 'Manage feedback: %s', 'feedhat' ),
				admin_url( 'edit.php?post_type=' . FeedHat_CPT_Feedback::POST_TYPE )
			),
		);

		return implode( "\n", $lines );
	}
}
