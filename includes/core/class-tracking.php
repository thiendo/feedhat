<?php
/**
 * Free: lets a reporter check a feedback entry's status later via a private
 * tracking link (`_cf_tracking_token`, generated per-submission in
 * FeedHat_Capture::save_meta()) — no login, no email lookup.
 *
 * The link points at a page rendering the `[feedhat_track]`
 * shortcode, auto-created the first time one is needed so this works with
 * zero setup (mirrors how e.g. WooCommerce auto-provisions its Cart/
 * Checkout pages) — an admin can still move the shortcode to a different
 * page later; get_or_create_tracking_page_id() only creates a new one if
 * the previously-used page is gone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Tracking {

	const SHORTCODE = 'feedhat_track';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_style' ) );
	}

	/**
	 * Full URL a reporter can visit to see this entry's current status.
	 *
	 * @param string $token The entry's `_cf_tracking_token` meta value.
	 * @return string URL, or '' if the tracking page couldn't be resolved/created.
	 */
	public static function get_tracking_url( $token ) {
		$page_id = self::get_or_create_tracking_page_id();

		if ( ! $page_id ) {
			return '';
		}

		return add_query_arg( 'cf_token', rawurlencode( $token ), get_permalink( $page_id ) );
	}

	/**
	 * @return int Page ID, or 0 if creating one failed.
	 */
	private static function get_or_create_tracking_page_id() {
		$settings = FeedHat_Settings::get_settings();
		$page_id  = absint( $settings['tracking_page_id'] );

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Track Your Feedback', 'feedhat' ),
				'post_content' => '[' . self::SHORTCODE . ']',
			),
			true
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return 0;
		}

		$settings['tracking_page_id'] = $new_id;
		update_option( FeedHat_Settings::OPTION_KEY, $settings );

		return $new_id;
	}

	/**
	 * Only load the tracking page's stylesheet on a page that actually
	 * contains the shortcode — never loaded broadly across the site.
	 */
	public static function maybe_enqueue_style() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		wp_enqueue_style(
			'feedhat-tracking',
			FEEDHAT_URL . 'assets/css/tracking.css',
			array(),
			FEEDHAT_VERSION
		);
	}

	/**
	 * Shortcode callback: reads ?cf_token= from the URL and shows that
	 * entry's status. No nonce needed — this is a read-only lookup keyed
	 * on an unguessable token, not a state-changing action.
	 *
	 * @return string Safe HTML.
	 */
	public static function render_shortcode() {
		$token = isset( $_GET['cf_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup by unguessable token, not a state-changing action.

		if ( '' === $token ) {
			return '<p class="cf-track__empty">' . esc_html__( 'Use the tracking link you were given when you submitted feedback to see its status here.', 'feedhat' ) . '</p>';
		}

		$posts = get_posts(
			array(
				'post_type'      => FeedHat_CPT_Feedback::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => '_cf_tracking_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- same trade-off already accepted for other status lookups (Kanban, SLA reminder); tokens are unique so this can only ever match one entry.
				'meta_value'     => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! $posts ) {
			return '<p class="cf-track__empty">' . esc_html__( "We couldn't find feedback for this tracking link. It may have been removed.", 'feedhat' ) . '</p>';
		}

		$post   = $posts[0];
		$status = get_post_meta( $post->ID, '_cf_status', true );
		$labels = FeedHat_Admin_List::get_status_labels();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ FeedHat_Admin_List::STATUS_NEW ];

		ob_start();
		?>
		<div class="cf-track">
			<span class="cf-status-badge cf-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></span>
			<h3 class="cf-track__title"><?php echo esc_html( get_the_title( $post ) ); ?></h3>
			<p class="cf-track__meta">
				<?php
				printf(
					/* translators: %s: submission date. */
					esc_html__( 'Submitted %s', 'feedhat' ),
					esc_html( get_the_date( '', $post ) )
				);
				?>
			</p>
			<div class="cf-track__note"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
