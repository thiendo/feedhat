<?php
/**
 * Handles the public feedback submission endpoint: validation, rate
 * limiting, screenshot storage, and saving the `cf_feedback` entry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Capture {

	// Defaults for the Security tab's fields (FeedHat_Settings) —
	// actual enforcement always reads the saved setting, never these
	// directly; they only seed get_settings()'s defaults.
	const RATE_LIMIT_MAX_DEFAULT            = 3;
	const RATE_LIMIT_WINDOW_MINUTES_DEFAULT = 10;
	const MIN_SUBMIT_SECONDS_DEFAULT        = 3;

	const MAX_NOTE_LENGTH = 2000;
	const MAX_IMAGE_BYTES = 8 * MB_IN_BYTES;
	const MAX_IMAGE_WIDTH = 1600;

	// A compressed file can pass MAX_IMAGE_BYTES yet still decode into a
	// bitmap far too large for GD to hold in memory (a PNG in particular
	// can compress a huge canvas down to a few hundred KB) — 20 megapixels
	// covers even a 2x-DPI capture of a large 4K display several times
	// over, while a truecolor bitmap that size (~80MB) plus a resized copy
	// still comfortably fits the raised 'image' memory_limit context
	// (wp_raise_memory_limit() below), instead of risking an uncaught fatal
	// "Allowed memory size exhausted" mid-request.
	const MAX_IMAGE_PIXELS = 20000000;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the `feedhat/v1/submit` and `/nonce` REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'feedhat/v1',
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_submit' ),
				'permission_callback' => array( __CLASS__, 'check_submit_permission' ),
			)
		);

		register_rest_route(
			'feedhat/v1',
			'/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_get_nonce' ),
				// Intentionally public: a fresh CSRF token isn't sensitive on
				// its own, and the widget needs one to recover when the
				// nonce baked into a page-cached script goes stale.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Hand out a fresh submit nonce, bypassing whatever page-cache plugin
	 * may be serving a stale one baked into the widget's localized script.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_get_nonce() {
		return rest_ensure_response( array( 'nonce' => wp_create_nonce( 'feedhat_submit' ) ) );
	}

	/**
	 * Verify the request carries a valid submit nonce.
	 *
	 * Public endpoint by design (visitors are not required to be logged in),
	 * so authorization here is a CSRF nonce check rather than a capability
	 * check.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function check_submit_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-FeedHat-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'feedhat_submit' ) ) {
			return new WP_Error(
				'feedhat_invalid_nonce',
				__( 'Security check failed. Please reload the page and try again.', 'feedhat' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate, store, and persist a submitted feedback entry.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_submit( WP_REST_Request $request ) {
		// Honeypot: real visitors never fill this hidden field.
		$honeypot = $request->get_param( 'website' );

		if ( ! empty( $honeypot ) ) {
			return new WP_Error(
				'feedhat_rejected',
				__( 'Unable to submit feedback.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		// Same generic rejection message/shape as the honeypot above — no
		// need to tell a bot which specific check it tripped.
		if ( ! self::check_submit_timing( $request ) ) {
			return new WP_Error(
				'feedhat_rejected',
				__( 'Unable to submit feedback.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::check_rate_limit() ) {
			return new WP_Error(
				'feedhat_rate_limited',
				__( 'You are submitting feedback too quickly. Please wait a moment and try again.', 'feedhat' ),
				array( 'status' => 429 )
			);
		}

		/**
		 * Filters whether the submission passes CAPTCHA verification.
		 *
		 * Always true on Free (no CAPTCHA ships there — see mục 5 in
		 * CLAUDE.md). Pro's FeedHat_Captcha hooks this to verify a
		 * Cloudflare Turnstile token when the admin has configured one.
		 *
		 * @param bool             $verified Whether the request may proceed.
		 * @param WP_REST_Request  $request  Incoming request.
		 */
		if ( ! apply_filters( 'feedhat_verify_submission', true, $request ) ) {
			return new WP_Error(
				'feedhat_captcha_failed',
				__( 'Verification failed. Please reload the page and try again.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		$settings = FeedHat_Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error(
				'feedhat_disabled',
				__( 'Feedback is currently disabled on this site.', 'feedhat' ),
				array( 'status' => 403 )
			);
		}

		$note = $request->get_param( 'note' );
		$note = is_string( $note ) ? sanitize_textarea_field( $note ) : '';

		if ( '' === trim( $note ) ) {
			return new WP_Error(
				'feedhat_missing_note',
				__( 'Please describe the issue before submitting.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $note ) > self::MAX_NOTE_LENGTH ) {
			$note = mb_substr( $note, 0, self::MAX_NOTE_LENGTH );
		}

		$screenshot_path = '';
		$screenshot      = $request->get_param( 'screenshot' );

		if ( ! empty( $screenshot ) ) {
			$stored = self::store_screenshot( $screenshot );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			$screenshot_path = $stored;
		}

		$title = $request->get_param( 'title' );
		$title = is_string( $title ) ? sanitize_text_field( $title ) : '';

		$post_id = wp_insert_post(
			array(
				'post_type'    => FeedHat_CPT_Feedback::POST_TYPE,
				'post_status'  => 'publish',
				// Falls back to the old auto-derived summary when no title
				// was given — the widget's title field is optional, and
				// existing integrations posting to this endpoint directly
				// (without a title param at all) still work exactly as before.
				'post_title'   => '' !== $title ? mb_substr( $title, 0, 120 ) : wp_trim_words( $note, 8, '…' ),
				'post_content' => $note,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'feedhat_save_failed',
				__( 'Could not save feedback. Please try again.', 'feedhat' ),
				array( 'status' => 500 )
			);
		}

		self::save_meta( $post_id, $request, $screenshot_path );

		/**
		 * Fires after a new feedback entry has been stored.
		 *
		 * @param int $post_id Feedback post ID.
		 */
		do_action( 'feedhat_after_submit', $post_id );

		$response = array( 'success' => true );

		$token = get_post_meta( $post_id, '_cf_tracking_token', true );

		if ( $token ) {
			$tracking_url = FeedHat_Tracking::get_tracking_url( $token );

			if ( $tracking_url ) {
				$response['tracking_url'] = $tracking_url;
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Persist auto-captured context and reporter fields as post meta.
	 *
	 * @param int              $post_id         Feedback post ID.
	 * @param WP_REST_Request  $request         Incoming request.
	 * @param string           $screenshot_path Relative path under the feedhat uploads dir, or ''.
	 */
	private static function save_meta( $post_id, WP_REST_Request $request, $screenshot_path ) {
		$string_param = function ( $key ) use ( $request ) {
			$value = $request->get_param( $key );

			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		};

		update_post_meta( $post_id, '_cf_page_url', esc_url_raw( (string) $request->get_param( 'page_url' ) ) );
		update_post_meta( $post_id, '_cf_browser', $string_param( 'browser' ) );
		update_post_meta( $post_id, '_cf_os', $string_param( 'os' ) );
		update_post_meta( $post_id, '_cf_screen_size', $string_param( 'screen_size' ) );
		update_post_meta( $post_id, '_cf_viewport_size', $string_param( 'viewport_size' ) );
		update_post_meta( $post_id, '_cf_wp_version', $string_param( 'wp_version' ) );
		update_post_meta( $post_id, '_cf_status', 'new' );
		// When the status last changed — creation counts as the first
		// change. Pro's Kanban board (FeedHat_Kanban) uses this to
		// show Resolved/Won't Fix cards by how recently they were closed
		// rather than by how recently they were originally submitted.
		update_post_meta( $post_id, '_cf_status_changed_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_cf_user_id', self::resolve_submitter_id() );

		// A random, unguessable token the reporter can use to check this
		// entry's status later without logging in — see
		// FeedHat_Tracking. wp_generate_password() draws from
		// wp_rand() (a CSPRNG since WP 4.4), same source already used for
		// screenshot filenames elsewhere in this file; its keyspace makes
		// guessing infeasible, so this needs no separate rate limiting.
		update_post_meta( $post_id, '_cf_tracking_token', wp_generate_password( 32, false, false ) );

		$reporter_name = $string_param( 'reporter_name' );

		if ( '' !== $reporter_name ) {
			update_post_meta( $post_id, '_cf_reporter_name', $reporter_name );
		}

		$reporter_email = $request->get_param( 'reporter_email' );
		$reporter_email = is_string( $reporter_email ) ? sanitize_email( $reporter_email ) : '';

		if ( '' !== $reporter_email ) {
			update_post_meta( $post_id, '_cf_reporter_email', $reporter_email );
		}

		if ( '' !== $screenshot_path ) {
			update_post_meta( $post_id, '_cf_screenshot_id', $screenshot_path );
		}

		$pin_notes = self::sanitize_pin_notes( $request->get_param( 'pins' ) );

		if ( $pin_notes ) {
			update_post_meta( $post_id, '_cf_pin_notes', $pin_notes );
		}
	}

	/**
	 * The submitting visitor's user ID, if logged in — resolved independently
	 * of WordPress's REST-API cookie authentication gate.
	 *
	 * This endpoint's own X-FeedHat-Nonce (not WP's standard
	 * X-WP-Nonce / 'wp_rest' nonce) is deliberately what lets anonymous
	 * visitors submit at all — see check_submit_permission(). The side
	 * effect: WordPress's REST bootstrap never recognizes a logged-in
	 * visitor's session on this route either, so plain get_current_user_id()
	 * always resolves to 0 here, even for an admin submitting while fully
	 * logged in (confirmed against wp-json/wp/v2/users/me, which returns
	 * rest_not_logged_in under the exact same cookie without an X-WP-Nonce).
	 * wp_validate_auth_cookie() is the lower-level check WordPress itself
	 * uses to identify the current user on an ordinary, non-REST page load —
	 * it reads the same login cookie directly and doesn't require any
	 * nonce, so it's exactly as secure as normal cookie-based identification
	 * anywhere else on the site.
	 *
	 * @return int
	 */
	private static function resolve_submitter_id() {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			return $user_id;
		}

		$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );

		return $cookie_user_id ? absint( $cookie_user_id ) : 0;
	}

	/**
	 * @param mixed $raw_pins JSON-encoded string from the `pins` request param, or anything else.
	 * @return array[] Each: [ 'number' => int, 'description' => string ].
	 */
	private static function sanitize_pin_notes( $raw_pins ) {
		if ( ! is_string( $raw_pins ) || '' === $raw_pins ) {
			return array();
		}

		$decoded = json_decode( $raw_pins, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $decoded as $pin ) {
			if ( ! is_array( $pin ) ) {
				continue;
			}

			$description = isset( $pin['description'] ) && is_string( $pin['description'] ) ? sanitize_textarea_field( $pin['description'] ) : '';

			if ( '' === $description ) {
				continue;
			}

			$sanitized[] = array(
				'number'      => isset( $pin['number'] ) ? absint( $pin['number'] ) : 0,
				'description' => mb_substr( $description, 0, self::MAX_NOTE_LENGTH ),
			);
		}

		return $sanitized;
	}

	/**
	 * Decode, validate, downsize, and store a base64 screenshot outside the
	 * Media Library, under wp-content/uploads/feedhat/.
	 *
	 * Public: also reused by Pro's FeedHat_Kanban for internal
	 * note image attachments — same validation/storage rules apply there.
	 *
	 * @param string $data_uri Data URI as produced by canvas.toDataURL().
	 * @return string|WP_Error Path relative to the feedhat uploads dir, or WP_Error.
	 */
	public static function store_screenshot( $data_uri ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error(
				'feedhat_gd_missing',
				__( 'Screenshot support is unavailable on this server.', 'feedhat' ),
				array( 'status' => 500 )
			);
		}

		// Decoding + resizing a full-resolution (often high-DPI) screenshot
		// needs both the source and resized bitmaps in memory at once —
		// the same reason WP core raises this before regenerating
		// thumbnails. Without it, a large capture can exhaust the default
		// PHP memory_limit and take down the whole request with a fatal
		// error instead of a clean WP_Error response.
		wp_raise_memory_limit( 'image' );

		if ( ! is_string( $data_uri ) || ! preg_match( '/^data:image\/(png|jpe?g);base64,(.+)$/', $data_uri, $matches ) ) {
			return new WP_Error(
				'feedhat_invalid_image',
				__( 'Screenshot format is not supported.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a canvas.toDataURL() screenshot payload, not obfuscated code; strict mode + validated below.
		$raw = base64_decode( $matches[2], true );

		if ( false === $raw ) {
			return new WP_Error(
				'feedhat_invalid_image',
				__( 'Screenshot data is corrupted.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $raw ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error(
				'feedhat_image_too_large',
				__( 'Screenshot is too large.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		// Don't trust the file extension/declared type — inspect real image bytes.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted input; failure is checked via the return value right below, not swallowed.
		$image_info = @getimagesizefromstring( $raw );

		if ( ! $image_info || ! in_array( $image_info['mime'], array( 'image/png', 'image/jpeg' ), true ) ) {
			return new WP_Error(
				'feedhat_invalid_image',
				__( 'Screenshot does not appear to be a valid image.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		// Reject before ever decoding it — imagecreatefromstring()/
		// imagecreatetruecolor() below have no size cap of their own, and a
		// compressed file well under MAX_IMAGE_BYTES can still unpack into a
		// bitmap big enough to exhaust memory and fatal the whole request.
		if ( $image_info[0] * $image_info[1] > self::MAX_IMAGE_PIXELS ) {
			return new WP_Error(
				'feedhat_image_too_large',
				__( 'Screenshot resolution is too large to process.', 'feedhat' ),
				array( 'status' => 400 )
			);
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'feedhat_storage_failed',
				__( 'Could not save the screenshot: the uploads directory is not available.', 'feedhat' ),
				array( 'status' => 500 )
			);
		}

		$sub_path   = 'feedhat/' . gmdate( 'Y/m' );
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . $sub_path . '/';

		// wp_mkdir_p() is a no-op success if $target_dir already exists —
		// it does NOT confirm that existing directory is still writable
		// (permissions can change after the fact, or every subfolder for a
		// given Y/m already exists from a previous month with different
		// ownership), so that's checked explicitly right after.
		if ( ! wp_mkdir_p( $target_dir ) || ! wp_is_writable( $target_dir ) ) {
			return new WP_Error(
				'feedhat_storage_failed',
				__( 'Could not create or write to the screenshot storage directory.', 'feedhat' ),
				array( 'status' => 500 )
			);
		}

		self::protect_directory( trailingslashit( $upload_dir['basedir'] ) . 'feedhat/' );

		$settings      = FeedHat_Settings::get_settings();
		$keep_original = empty( $settings['compress_screenshots'] );

		/**
		 * Whether to store the uploaded screenshot bytes as-is.
		 *
		 * Defaults to the Settings → Data "Compress screenshots" checkbox
		 * (on by default for disk safety). A separate add-on may force
		 * original storage by returning true.
		 *
		 * @param bool $keep_original Whether to skip recompression.
		 */
		$keep_original = (bool) apply_filters( 'feedhat_keep_original_screenshot', $keep_original );

		if ( $keep_original ) {
			$extension = ( 'image/png' === $image_info['mime'] ) ? 'png' : 'jpg';
			$filename  = wp_generate_password( 32, false, false ) . '.' . $extension;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- race between wp_is_writable() and this write (permissions / disk full).
			$saved = (bool) @file_put_contents( $target_dir . $filename, $raw );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted input; failure is checked via the return value right below, not swallowed.
			$image = @imagecreatefromstring( $raw );

			if ( ! $image ) {
				return new WP_Error(
					'feedhat_invalid_image',
					__( 'Screenshot could not be processed.', 'feedhat' ),
					array( 'status' => 400 )
				);
			}

			$width  = imagesx( $image );
			$height = imagesy( $image );

			if ( $width > self::MAX_IMAGE_WIDTH ) {
				$new_width  = self::MAX_IMAGE_WIDTH;
				$new_height = (int) round( $height * ( $new_width / $width ) );
				$resized    = imagecreatetruecolor( $new_width, $new_height );
				imagecopyresampled( $resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
				imagedestroy( $image );
				$image = $resized;
			}

			$filename = wp_generate_password( 32, false, false ) . '.jpg';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- return value is checked below.
			$saved = @imagejpeg( $image, $target_dir . $filename, 70 );
			imagedestroy( $image );
		}

		unset( $raw );

		if ( ! $saved ) {
			return new WP_Error(
				'feedhat_storage_failed',
				__( 'Could not save the screenshot.', 'feedhat' ),
				array( 'status' => 500 )
			);
		}

		return $sub_path . '/' . $filename;
	}

	/**
	 * Drop an index.php in the private uploads dir to block directory listing.
	 *
	 * @param string $dir Absolute path to the feedhat uploads root.
	 */
	private static function protect_directory( $dir ) {
		$index_file = $dir . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort hardening only; a failure here isn't fatal to storing the screenshot itself.
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Simple per-IP submission rate limit.
	 *
	 * @return bool True if the request is within the allowed rate.
	 */
	private static function check_rate_limit() {
		$settings = FeedHat_Settings::get_settings();
		$max      = max( 1, (int) $settings['rate_limit_max'] );
		$window   = max( 1, (int) $settings['rate_limit_window'] ) * MINUTE_IN_SECONDS;

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$key = 'cf_rl_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * Heuristic bot check: reject a submission sent faster than the
	 * configured minimum after the panel was opened (`opened_at`, a client
	 * timestamp — spoofable in principle, but enough to catch the generic
	 * scripts that just POST straight to a discovered endpoint). A missing
	 * or malformed `opened_at` never fails this check on its own, so
	 * external API callers that don't send it are unaffected; 0 disables it.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool True if the request passes (or the check is disabled).
	 */
	private static function check_submit_timing( WP_REST_Request $request ) {
		$settings    = FeedHat_Settings::get_settings();
		$min_seconds = (int) $settings['min_submit_seconds'];

		if ( $min_seconds <= 0 ) {
			return true;
		}

		$opened_at = absint( $request->get_param( 'opened_at' ) );

		if ( ! $opened_at ) {
			return true;
		}

		$elapsed = time() - $opened_at;

		if ( $elapsed < 0 ) {
			// Client clock ahead of the server's — not something we can
			// reliably judge, so don't penalize a real visitor for it.
			return true;
		}

		return $elapsed >= $min_seconds;
	}
}
