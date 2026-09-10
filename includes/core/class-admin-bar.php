<?php
/**
 * Adds a "Feedback" node to the WordPress admin toolbar (front-end and
 * wp-admin alike) so admins spot new feedback without having to be on the
 * feedback screen already — a second, always-visible layer on top of the
 * sidebar menu bell badge, only shown while there is at least one new item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Admin_Bar {

	const MAX_ITEMS = 5;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_node' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Build the "Feedback" toolbar node + its dropdown of recent new items.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function register_node( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		$new_count = absint( FeedHat_CPT_Feedback::get_status_count( FeedHat_Admin_List::STATUS_NEW ) );

		if ( $new_count < 1 ) {
			return;
		}

		$list_url = admin_url( 'edit.php?post_type=' . FeedHat_CPT_Feedback::POST_TYPE );

		$awaiting_text = sprintf(
			/* translators: Hidden accessibility text. %s: Number of new feedback entries. */
			_n( '%s new feedback entry', '%s new feedback entries', $new_count, 'feedhat' ),
			number_format_i18n( $new_count )
		);

		// Note: core's own Comments toolbar badge classes (.awaiting-mod,
		// .pending-count) are ONLY ever styled scoped to #adminmenu (the
		// sidebar) — wp-includes/css/admin-bar.css never styles them for the
		// toolbar, so reusing them here would render as bare unstyled text.
		// A fully custom .cf-ab-badge (styled below) is used instead.
		$icon   = '<span class="ab-icon dashicons-before dashicons-images-alt2" aria-hidden="true"></span>';
		$title  = '<span class="ab-label">' . esc_html__( 'Feedback', 'feedhat' ) . '</span>';
		$title .= '<span class="cf-ab-badge" aria-hidden="true">' . number_format_i18n( $new_count ) . '</span>';
		$title .= '<span class="screen-reader-text">' . esc_html( $awaiting_text ) . '</span>';

		$wp_admin_bar->add_node(
			array(
				'id'    => 'feedhat',
				'title' => $icon . $title,
				'href'  => $list_url,
			)
		);

		foreach ( self::get_recent_new_feedback( self::MAX_ITEMS ) as $post ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'feedhat',
					'id'     => 'feedhat-' . $post->ID,
					'title'  => self::build_item_label( $post ),
					'href'   => get_edit_post_link( $post->ID, 'raw' ),
				)
			);
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'feedhat',
				'id'     => 'feedhat-view-all',
				'title'  => esc_html__( 'View all feedback', 'feedhat' ) . ' →',
				'href'   => $list_url,
			)
		);
	}

	/**
	 * Most recent feedback entries still marked "new".
	 *
	 * @param int $limit Max number of entries.
	 * @return WP_Post[]
	 */
	private static function get_recent_new_feedback( $limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => FeedHat_CPT_Feedback::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- no non-meta way to filter by status; same trade-off already accepted in FeedHat_Kanban.
					array(
						'key'   => '_cf_status',
						'value' => FeedHat_Admin_List::STATUS_NEW,
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Reporter + time-ago + a short note excerpt, for one dropdown row.
	 *
	 * @param WP_Post $post Feedback post.
	 * @return string HTML.
	 */
	private static function build_item_label( $post ) {
		$reporter = get_post_meta( $post->ID, '_cf_reporter_name', true );
		$who      = $reporter ? $reporter : __( 'Anonymous', 'feedhat' );
		// time() here, not current_time( 'timestamp' ) — get_post_timestamp()
		// is already a real UTC Unix timestamp, and current_time( 'timestamp' )
		// is well known to NOT be one (it's shifted by the site's UTC
		// offset), which would throw human_time_diff()'s result off by
		// exactly that offset.
		$when = human_time_diff( get_post_timestamp( $post ), time() );

		$note = trim( $post->post_content );

		if ( mb_strlen( $note ) > 70 ) {
			$note = mb_substr( $note, 0, 70 ) . '…';
		}

		$meta_line = sprintf(
			/* translators: 1: reporter name, 2: time ago, e.g. "2 hours". */
			__( '%1$s · %2$s ago', 'feedhat' ),
			'<strong>' . esc_html( $who ) . '</strong>',
			esc_html( $when )
		);

		$label = '<span class="cf-ab-row__meta">' . $meta_line . '</span>';

		if ( '' !== $note ) {
			$label .= '<span class="cf-ab-row__note">' . esc_html( $note ) . '</span>';
		}

		return $label;
	}

	/**
	 * Small inline styles for the badge + dropdown rows, attached to the
	 * always-registered 'admin-bar' handle so it prints wherever the
	 * toolbar itself does — no separate stylesheet/HTTP request needed.
	 */
	public static function enqueue_styles() {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'/* Notification-style count badge (custom — see build_item_label()
			 * comment for why core\'s own .pending-count/.awaiting-mod
			 * classes don\'t work here). */
			#wp-admin-bar-feedhat .cf-ab-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 8px;
				height: 16px;
				margin-left: 6px;
				padding: 0 5px;
				border-radius: 999px;
				background: #d63638;
				color: #fff;
				font-size: 10px;
				font-weight: 600;
				line-height: 16px;
				vertical-align: middle;
			}

			/* Core forces a fixed `height: 26px` + `white-space: nowrap` on
			 * every dropdown row (#wpadminbar .quicklinks .menupop ul li
			 * .ab-item — see wp-includes/css/admin-bar.css), sized for a
			 * single line of text and a ~140px-wide box. Our rows are two
			 * lines (name/time + note excerpt) and need more room, so
			 * without a higher-specificity override the note line renders
			 * outside that 26px box and visually overlaps the row below it.
			 * `#wpadminbar #wp-admin-bar-feedhat-default li
			 * .ab-item` carries two IDs against core\'s one, which is what
			 * actually wins here — matching class/element counts wouldn\'t
			 * be enough (same specificity bug as the notes [hidden] fix).
			 */
			#wpadminbar #wp-admin-bar-feedhat-default li .ab-item {
				height: auto;
				min-width: 300px;
				line-height: 1.4;
				white-space: normal;
				padding: 10px 14px;
				border-bottom: 1px solid rgba(240, 246, 252, 0.06);
			}
			#wpadminbar #wp-admin-bar-feedhat-default li:last-child .ab-item {
				border-bottom: none;
			}
			#wpadminbar #wp-admin-bar-feedhat-default li:hover .ab-item,
			#wpadminbar #wp-admin-bar-feedhat-default li.hover .ab-item {
				background: rgba(240, 246, 252, 0.04);
			}
			#wp-admin-bar-feedhat .cf-ab-row__meta {
				display: block;
				white-space: nowrap;
			}
			#wp-admin-bar-feedhat .cf-ab-row__note {
				display: block;
				margin-top: 4px;
				color: #a7aaad;
				font-size: 11px;
				line-height: 1.5;
				white-space: normal;
			}

			/* "View all feedback" footer row reads as an action, not a list item. */
			#wpadminbar #wp-admin-bar-feedhat-view-all .ab-item {
				font-weight: 600;
				color: #72aee6;
			}'
		);
	}

	/**
	 * Whether the admin has left this toolbar node turned on (Settings →
	 * Email notifications → "Admin toolbar notification"). Defaults on.
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		$settings = FeedHat_Settings::get_settings();

		return ! empty( $settings['admin_bar_enabled'] );
	}
}
