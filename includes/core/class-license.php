<?php
/**
 * Detects whether this install ships Pro modules (includes/pro/).
 *
 * The WordPress.org package never includes that directory or Freemius.
 * Licensing lives in includes/pro/class-freemius.php and is loaded only
 * when that file is present (the Freemius-sold package).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_License {

	/**
	 * Whether Pro features from includes/pro/ should run on this site.
	 *
	 * Defaults to false. The Freemius bootstrap (Pro package only) hooks
	 * `feedhat_is_pro_active` when a license, trial, or local dev-mode
	 * override is in effect.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return (bool) apply_filters( 'feedhat_is_pro_active', false );
	}

	/**
	 * Local-only bypass, defined in wp-config.php as FEEDHAT_PRO_DEV_MODE.
	 * The Pro package's Freemius bootstrap honors this; this helper exists
	 * so core can show a notice when that constant is set.
	 *
	 * @return bool
	 */
	public static function is_dev_mode() {
		return defined( 'FEEDHAT_PRO_DEV_MODE' ) && FEEDHAT_PRO_DEV_MODE;
	}

	/**
	 * Whether includes/pro/ is on disk. The WordPress.org zip excludes it.
	 *
	 * @return bool
	 */
	public static function ships_pro_modules() {
		return is_readable( FEEDHAT_PATH . 'includes/pro/load.php' );
	}
}
