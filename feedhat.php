<?php
/**
 * Plugin Name:       FeedHat – On-Page Screenshot Feedback
 * Plugin URI:        https://douple.net/feedhat/
 * Description:       See it. Mark it. Fix it — without leaving the page. Visual feedback widget: capture screenshot, annotate, and report issues straight from the front end.
 * Version:           2.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Douple
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feedhat
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FEEDHAT_VERSION', '2.1.0' );
define( 'FEEDHAT_FILE', __FILE__ );
define( 'FEEDHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'FEEDHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'FEEDHAT_URL_PARAM', 'feedhat' );

require_once FEEDHAT_PATH . 'includes/core/class-license.php';

// Freemius + Pro modules exist only in the paid package (excluded from
// the WordPress.org zip — see build/build-free.sh).
if ( is_readable( FEEDHAT_PATH . 'includes/pro/class-freemius.php' ) ) {
	require_once FEEDHAT_PATH . 'includes/pro/class-freemius.php';
}

if ( FeedHat_License::ships_pro_modules() ) {
	require_once FEEDHAT_PATH . 'includes/pro/load.php';
}

final class FeedHat_Core {

	/**
	 * Singleton instance.
	 *
	 * @var FeedHat_Core|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return FeedHat_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Load core + free files. Always present in every build.
	 */
	private function includes() {
		require_once FEEDHAT_PATH . 'includes/core/class-cpt-feedback.php';
		require_once FEEDHAT_PATH . 'includes/core/class-capture.php';
		require_once FEEDHAT_PATH . 'includes/core/class-widget-render.php';
		require_once FEEDHAT_PATH . 'includes/core/class-admin-list.php';
		require_once FEEDHAT_PATH . 'includes/core/class-admin-bar.php';
		require_once FEEDHAT_PATH . 'includes/core/class-settings.php';
		require_once FEEDHAT_PATH . 'includes/core/class-email-notify.php';
		require_once FEEDHAT_PATH . 'includes/core/class-tracking.php';
		require_once FEEDHAT_PATH . 'includes/free/class-targeting-basic.php';
	}

	/**
	 * Wire up hooks: core + free always, Pro only once module code ships
	 * AND a valid license/trial (or local dev-mode override) is active.
	 */
	private function init_hooks() {
		FeedHat_CPT_Feedback::init();
		FeedHat_Settings::init();
		FeedHat_Capture::init();
		FeedHat_Widget_Render::init();
		FeedHat_Admin_List::init();
		FeedHat_Admin_Bar::init();
		FeedHat_Email_Notify::init();
		FeedHat_Tracking::init();
		FeedHat_Targeting_Basic::init();

		if ( ! FeedHat_License::ships_pro_modules() ) {
			return;
		}

		if ( ! FeedHat_License::is_pro_active() ) {
			if ( class_exists( 'FeedHat_Freemius' ) ) {
				add_action( 'admin_notices', array( 'FeedHat_Freemius', 'render_license_required_notice' ) );
			}
			return;
		}

		if ( FeedHat_License::is_dev_mode() && class_exists( 'FeedHat_Freemius' ) ) {
			add_action( 'admin_notices', array( 'FeedHat_Freemius', 'render_dev_mode_notice' ) );
		}

		FeedHat_Targeting_Advanced::init();
		FeedHat_Role_Visibility::init();
		FeedHat_White_Label::init();
		FeedHat_Multi_Recipient::init();
		FeedHat_Webhook::init();
		FeedHat_Kanban::init();
		FeedHat_Export::init();
		FeedHat_Language_Override::init();
		FeedHat_Assignment::init();
		FeedHat_SLA_Reminder::init();
		FeedHat_Mentions::init();
		FeedHat_Pin_Tool::init();
		FeedHat_Captcha::init();
	}
}

/**
 * Runs on plugin activation.
 */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- standard WP plugin bootstrap shape: main file pairs its singleton class with register_activation_hook()/register_deactivation_hook() callbacks, which must be plain functions.
function feedhat_activate() {
	require_once FEEDHAT_PATH . 'includes/core/class-cpt-feedback.php';
	FeedHat_CPT_Feedback::register_post_type();
	feedhat_maybe_migrate_legacy_options();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'feedhat_activate' );

/**
 * Runs on plugin deactivation.
 */
function feedhat_deactivate() {
	flush_rewrite_rules();

	// Harmless no-op if Pro's SLA reminder was never scheduled (Free-only,
	// or Pro inactive) — always safe to call unconditionally on deactivate.
	wp_clear_scheduled_hook( 'feedhat_pro_sla_check' );
	wp_clear_scheduled_hook( 'clarity_feedback_pro_sla_check' );
}
register_deactivation_hook( __FILE__, 'feedhat_deactivate' );

/**
 * Copy settings stored under the pre-2.0.0 option keys (Clarity Feedback)
 * so a site that already had the plugin installed keeps its configuration.
 */
function feedhat_maybe_migrate_legacy_options() {
	if ( get_option( 'feedhat_migrated_from_clarity' ) ) {
		return;
	}

	$map = array(
		'clarity_feedback_settings'                      => 'feedhat_settings',
		'clarity_feedback_pro_targeting'                 => 'feedhat_pro_targeting',
		'clarity_feedback_pro_visibility'                => 'feedhat_pro_visibility',
		'clarity_feedback_pro_recipients'                => 'feedhat_pro_recipients',
		'clarity_feedback_pro_webhooks'                  => 'feedhat_pro_webhooks',
		'clarity_feedback_pro_white_label'               => 'feedhat_pro_white_label',
		'clarity_feedback_pro_language'                  => 'feedhat_pro_language',
		'clarity_feedback_pro_assignment'                => 'feedhat_pro_assignment',
		'clarity_feedback_pro_sla'                       => 'feedhat_pro_sla',
		'clarity_feedback_pro_captcha'                   => 'feedhat_pro_captcha',
		'clarity_feedback_pro_status_changed_backfilled' => 'feedhat_pro_status_changed_backfilled',
	);

	foreach ( $map as $old_key => $new_key ) {
		if ( false !== get_option( $new_key, false ) ) {
			continue;
		}

		$old_value = get_option( $old_key, null );

		if ( null !== $old_value && false !== $old_value ) {
			add_option( $new_key, $old_value );
		}
	}

	$settings = get_option( 'feedhat_settings', array() );
	$page_id  = isset( $settings['tracking_page_id'] ) ? absint( $settings['tracking_page_id'] ) : 0;

	if ( $page_id ) {
		$page = get_post( $page_id );

		if ( $page instanceof WP_Post && false !== strpos( $page->post_content, '[clarity_feedback_track]' ) ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => str_replace( '[clarity_feedback_track]', '[feedhat_track]', $page->post_content ),
				)
			);
		}
	}

	$legacy_sla = wp_next_scheduled( 'clarity_feedback_pro_sla_check' );

	if ( $legacy_sla ) {
		wp_clear_scheduled_hook( 'clarity_feedback_pro_sla_check' );

		if ( ! wp_next_scheduled( 'feedhat_pro_sla_check' ) ) {
			wp_schedule_event( $legacy_sla, 'daily', 'feedhat_pro_sla_check' );
		}
	}

	update_option( 'feedhat_migrated_from_clarity', 1, false );
}

/**
 * Boot the plugin once all plugins are loaded.
 */
function feedhat() {
	feedhat_maybe_migrate_legacy_options();

	return FeedHat_Core::instance();
}
add_action( 'plugins_loaded', 'feedhat' );
