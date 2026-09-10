<?php
/**
 * Uninstall handler for FeedHat.
 *
 * Only deletes plugin data when the site owner explicitly opted in via
 * the "Delete all data on uninstall" setting.
 */

// Block direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Runs the opt-in data cleanup. Wrapped in a function (rather than running
 * at the file's top level) so its working variables stay local instead of
 * polluting the global scope this file executes in.
 */
function feedhat_run_uninstall() {
	$settings = get_option( 'feedhat_settings', array() );

	if ( ! is_array( $settings ) || array() === $settings ) {
		$settings = get_option( 'clarity_feedback_settings', array() );
	}

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// Delete all cf_feedback posts (and their attached meta).
	$posts = get_posts(
		array(
			'post_type'   => 'cf_feedback',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	// Delete the "Track Your Feedback" page auto-created by
	// FeedHat_Tracking, if one was ever created.
	$tracking_page_id = isset( $settings['tracking_page_id'] ) ? absint( $settings['tracking_page_id'] ) : 0;

	if ( $tracking_page_id ) {
		wp_delete_post( $tracking_page_id, true );
	}

	// Delete plugin settings — Free's own, plus every Pro option key (present
	// or not; delete_option() on a nonexistent key is a harmless no-op, so no
	// need to gate these behind FeedHat_License::ships_pro_modules()).
	$option_keys = array(
		'feedhat_settings',
		'feedhat_pro_targeting',
		'feedhat_pro_visibility',
		'feedhat_pro_recipients',
		'feedhat_pro_webhooks',
		'feedhat_pro_white_label',
		'feedhat_pro_language',
		'feedhat_pro_assignment',
		'feedhat_pro_sla',
		'feedhat_pro_captcha',
		'feedhat_pro_status_changed_backfilled',
		'feedhat_migrated_from_clarity',
		'clarity_feedback_settings',
		'clarity_feedback_pro_targeting',
		'clarity_feedback_pro_visibility',
		'clarity_feedback_pro_recipients',
		'clarity_feedback_pro_webhooks',
		'clarity_feedback_pro_white_label',
		'clarity_feedback_pro_language',
		'clarity_feedback_pro_assignment',
		'clarity_feedback_pro_sla',
		'clarity_feedback_pro_captcha',
		'clarity_feedback_pro_status_changed_backfilled',
	);

	foreach ( $option_keys as $option_key ) {
		delete_option( $option_key );
	}

	wp_clear_scheduled_hook( 'feedhat_pro_sla_check' );
	wp_clear_scheduled_hook( 'clarity_feedback_pro_sla_check' );

	// Remove uploaded screenshots directory (current + pre-2.0.0 path).
	$upload_dir = wp_upload_dir();
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( $wp_filesystem ) {
		foreach ( array( 'feedhat/', 'clarity-feedback/' ) as $subdir ) {
			$target_dir = trailingslashit( $upload_dir['basedir'] ) . $subdir;

			if ( is_dir( $target_dir ) ) {
				$wp_filesystem->delete( $target_dir, true );
			}
		}
	}
}

feedhat_run_uninstall();
