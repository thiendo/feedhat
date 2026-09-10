<?php
/**
 * wp-admin feedback management screen: menu + bell badge, list table
 * columns (screenshot, page URL, browser/OS, status), and status toggle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Admin_List {

	const STATUS_NEW      = 'new';
	const STATUS_RESOLVED = 'resolved';

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Priority 5: must register the top-level menu (add_menu_page)
		// before any submenu (e.g. Settings) registers itself, otherwise
		// WordPress computes a different admin-page hookname at sidebar
		// render time than the one add_submenu_page() actually hooked —
		// the submenu link then renders with a broken raw-slug href.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 5 );
		add_action( 'current_screen', array( __CLASS__, 'restrict_screen_access' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_admin_assets' ) );
		add_action( 'admin_post_feedhat_toggle_status', array( __CLASS__, 'handle_toggle_status' ) );

		$post_type = FeedHat_CPT_Feedback::POST_TYPE;

		add_filter( "manage_edit-{$post_type}_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_detail_metabox' ) );
	}

	/**
	 * Block direct access to the feedback list/edit screens for anyone
	 * without `manage_options`, regardless of what the CPT's own default
	 * post-type capabilities would otherwise allow.
	 */
	public static function restrict_screen_access() {
		$screen = get_current_screen();

		if ( ! $screen || FeedHat_CPT_Feedback::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access FeedHat.', 'feedhat' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Register the top-level "FeedHat" menu pointing at the CPT's
	 * own list table, with a red bell badge showing the New count — same
	 * markup WordPress core uses for the Comments menu badge.
	 */
	public static function register_menu() {
		$post_type = FeedHat_CPT_Feedback::POST_TYPE;
		$new_count = absint( FeedHat_CPT_Feedback::get_status_count( self::STATUS_NEW ) );

		// Short on purpose: "FeedHat" + the badge doesn't fit the
		// sidebar's fixed width and wraps to a second line — same reason
		// WP core's own menus are short ("Comments", not "WordPress
		// Comments"). The full brand name still shows as the page title.
		$menu_title = __( 'Feedback', 'feedhat' );

		if ( $new_count > 0 ) {
			// Same markup WordPress core uses for its own menu badges
			// (e.g. Plugins update count) — already styled by core to sit
			// inline next to the menu text, no custom CSS needed.
			$menu_title .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>',
				$new_count
			);
		}

		add_menu_page(
			__( 'FeedHat', 'feedhat' ),
			$menu_title,
			'manage_options',
			'edit.php?post_type=' . $post_type,
			'',
			'dashicons-images-alt2',
			26
		);

		// Reclaim the top-level item's own click destination. WordPress
		// sends clicks on a top-level menu to whichever submenu is FIRST
		// in $submenu[$parent_slug] (see wp-admin/menu-header.php) —
		// registering a submenu whose slug is identical to the parent's
		// guarantees that's this list rather than whichever submenu (e.g.
		// Settings) happened to register first. It also gives the list its
		// own visible sidebar row instead of only being reachable by
		// clicking the top-level label itself.
		//
		// This class's admin_menu priority (5, see init() above) is what
		// actually guarantees "first" here — this runs before any other
		// submenu (Settings, Kanban) has registered anything, so the plain
		// append below lands it at index 0 regardless of the $position arg
		// passed (add_submenu_page() only honors $position once the array
		// already has more entries than that position — see the priority-7
		// comment in FeedHat_Kanban::init()).
		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'All Feedback', 'feedhat' ),
			__( 'All Feedback', 'feedhat' ),
			'manage_options',
			'edit.php?post_type=' . $post_type,
			'',
			0
		);
	}

	/**
	 * Only load the admin stylesheet on the feedback list/edit screens.
	 */
	public static function maybe_enqueue_admin_assets() {
		$screen = get_current_screen();

		if ( ! $screen || FeedHat_CPT_Feedback::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'feedhat-admin',
			FEEDHAT_URL . 'assets/css/admin.css',
			array(),
			FEEDHAT_VERSION
		);

		// Screenshot lightbox — shared with Pro's Kanban board (which reuses
		// the same .cf-lightbox-trigger class), but must not depend on Pro
		// being licensed/loaded, since the Feedback Details screenshot is a
		// Free feature.
		wp_enqueue_script(
			'feedhat-admin',
			FEEDHAT_URL . 'assets/js/admin.js',
			array(),
			FEEDHAT_VERSION,
			true
		);
	}

	/**
	 * Replace the default list table columns with feedback-specific ones.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['cf_screenshot'] = __( 'Screenshot', 'feedhat' );
				$new[ $key ]          = __( 'Feedback', 'feedhat' );
				continue;
			}

			if ( 'date' === $key ) {
				continue; // Re-added below, after our custom columns.
			}

			$new[ $key ] = $label;
		}

		$new['cf_note']       = __( 'Note', 'feedhat' );
		$new['cf_page_url']   = __( 'Page', 'feedhat' );
		$new['cf_browser_os'] = __( 'Browser / OS', 'feedhat' );
		$new['cf_status']     = __( 'Status', 'feedhat' );
		$new['date']          = __( 'Submitted', 'feedhat' );

		return $new;
	}

	/**
	 * Render a single custom column's content.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'cf_screenshot':
				self::render_screenshot_column( $post_id );
				break;

			case 'cf_note':
				self::render_note_column( $post_id );
				break;

			case 'cf_page_url':
				self::render_page_url_column( $post_id );
				break;

			case 'cf_browser_os':
				self::render_browser_os_column( $post_id );
				break;

			case 'cf_status':
				self::render_status_column( $post_id );
				break;
		}
	}

	/**
	 * Small clickable screenshot thumbnail (opens the full image in a new tab).
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_screenshot_column( $post_id ) {
		$path = get_post_meta( $post_id, '_cf_screenshot_id', true );

		if ( ! $path ) {
			echo '<span class="description">' . esc_html__( 'No screenshot', 'feedhat' ) . '</span>';
			return;
		}

		// $path (from FeedHat_Capture::store_screenshot()) already
		// starts with "feedhat/" — don't prepend it again here.
		$upload_dir = wp_upload_dir();
		$url        = trailingslashit( $upload_dir['baseurl'] ) . ltrim( $path, '/' );

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="cf-thumb-link"><img src="%1$s" alt="%2$s" class="cf-thumb" /></a>',
			esc_url( $url ),
			esc_attr__( 'Feedback screenshot', 'feedhat' )
		);
	}

	/**
	 * Feedback note content (post_content), truncated with the full text
	 * available as a title-attribute tooltip.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_note_column( $post_id ) {
		$post = get_post( $post_id );
		$note = $post ? $post->post_content : '';

		if ( '' === trim( $note ) ) {
			echo '&#8212;';
			return;
		}

		$excerpt = $note;

		if ( mb_strlen( $excerpt ) > 150 ) {
			$excerpt = mb_substr( $excerpt, 0, 150 ) . '…';
		}

		printf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $note ),
			esc_html( $excerpt )
		);
	}

	/**
	 * Clickable link to the page the feedback was submitted from.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_page_url_column( $post_id ) {
		$url = get_post_meta( $post_id, '_cf_page_url', true );

		if ( ! $url ) {
			echo '&#8212;';
			return;
		}

		$display = preg_replace( '#^https?://#', '', $url );

		if ( mb_strlen( $display ) > 40 ) {
			$display = mb_substr( $display, 0, 40 ) . '…';
		}

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html( $display )
		);
	}

	/**
	 * Browser + OS + screen size, auto-captured at submission time.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_browser_os_column( $post_id ) {
		$browser  = get_post_meta( $post_id, '_cf_browser', true );
		$os       = get_post_meta( $post_id, '_cf_os', true );
		$screen   = get_post_meta( $post_id, '_cf_screen_size', true );
		$viewport = get_post_meta( $post_id, '_cf_viewport_size', true );

		echo esc_html( $browser ? $browser : __( 'Unknown', 'feedhat' ) );

		$detail_parts = array_filter(
			array(
				$os,
				$screen ? sprintf(
					/* translators: %s: screen resolution, e.g. "1920x1080". */
					__( 'Screen %s', 'feedhat' ),
					$screen
				) : '',
				$viewport ? sprintf(
					/* translators: %s: browser viewport size, e.g. "1488x1135". */
					__( 'Viewport %s', 'feedhat' ),
					$viewport
				) : '',
			)
		);

		if ( $detail_parts ) {
			echo '<br><span class="description">' . esc_html( implode( ' · ', $detail_parts ) ) . '</span>';
		}
	}

	/**
	 * Status badge (New/Resolved) plus a link to toggle it.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_status_column( $post_id ) {
		self::render_status_badge( $post_id );
	}

	/**
	 * Shared status badge + toggle-link markup, used by both the list
	 * table's Status column and the single-post detail metabox.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_status_badge( $post_id ) {
		$status = get_post_meta( $post_id, '_cf_status', true );
		$labels = self::get_status_labels();

		if ( ! isset( $labels[ $status ] ) ) {
			$status = self::STATUS_NEW;
		}

		printf(
			'<span class="cf-status-badge cf-badge-%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $labels[ $status ] )
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Free's own quick-action link stays a simple resolve/reopen toggle
		// regardless of how many statuses actually exist — a Pro Kanban
		// status (In Progress/Won't Fix) is treated the same as New here
		// (not resolved yet), same as it already is for the bell badge/list
		// filtering elsewhere. Anything more granular happens in Kanban.
		$target_status = ( self::STATUS_RESOLVED === $status ) ? self::STATUS_NEW : self::STATUS_RESOLVED;
		$toggle_label  = ( self::STATUS_RESOLVED === $status )
			? __( 'Mark as New', 'feedhat' )
			: __( 'Mark Resolved', 'feedhat' );

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'feedhat_toggle_status',
					'post_id' => $post_id,
					'status'  => $target_status,
				),
				admin_url( 'admin-post.php' )
			),
			'feedhat_toggle_status_' . $post_id
		);

		printf(
			'<br><a href="%1$s" class="cf-status-toggle">%2$s</a>',
			esc_url( $url ),
			esc_html( $toggle_label )
		);
	}

	/**
	 * All statuses a feedback entry can currently be in — Free's own 2, or
	 * Pro Kanban's 4 (New/In Progress/Resolved/Won't Fix) when that module
	 * is active, so the badge never mislabels a Pro-only status as "New".
	 *
	 * Public: also used by FeedHat_Tracking so the public-facing
	 * status-tracking page shows the exact same labels as wp-admin.
	 *
	 * @return array status => label
	 */
	public static function get_status_labels() {
		if ( class_exists( 'FeedHat_Kanban' ) && FeedHat_License::is_pro_active() ) {
			return FeedHat_Kanban::get_statuses();
		}

		return array(
			self::STATUS_NEW      => __( 'New', 'feedhat' ),
			self::STATUS_RESOLVED => __( 'Resolved', 'feedhat' ),
		);
	}

	/**
	 * Register the "Feedback Details" metabox on the single feedback edit
	 * screen — the default screen otherwise shows almost nothing useful,
	 * since every captured meta key is underscore-prefixed and therefore
	 * automatically hidden from WordPress's own "Custom Fields" metabox.
	 */
	public static function register_detail_metabox() {
		add_meta_box(
			'feedhat-details',
			__( 'Feedback Details', 'feedhat' ),
			array( __CLASS__, 'render_detail_metabox' ),
			FeedHat_CPT_Feedback::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Feedback post.
	 */
	public static function render_detail_metabox( $post ) {
		$post_id = $post->ID;

		echo '<div class="cf-detail">';

		// A quick-glance strip up top — status (with its own resolve/reopen
		// action), when, and who — so the 3 things an admin scans for first
		// don't require hunting through the same long list as Browser/OS/
		// WordPress version below.
		echo '<div class="cf-detail__summary">';
		echo '<div class="cf-detail__summary-item cf-detail__summary-item--status">';
		self::render_status_badge( $post_id );
		echo '</div>';
		printf(
			'<div class="cf-detail__summary-item"><span class="dashicons dashicons-clock" aria-hidden="true"></span>%s</div>',
			esc_html( get_the_date( '', $post ) . ' ' . get_the_time( '', $post ) )
		);
		printf(
			'<div class="cf-detail__summary-item"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>%s</div>',
			self::get_reporter_html( $post_id ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML.
		);
		echo '</div>';

		echo '<div class="cf-detail__body">';

		echo '<div class="cf-detail__col cf-detail__col--screenshot">';
		self::render_detail_screenshot( $post_id );
		self::render_pin_notes( $post_id );
		echo '</div>';

		echo '<div class="cf-detail__col cf-detail__col--info">';
		echo '<h4 class="cf-detail__group-title">' . esc_html__( 'Page & environment', 'feedhat' ) . '</h4>';
		echo '<dl class="cf-detail__list">';

		self::render_detail_item( 'admin-links', __( 'Page', 'feedhat' ), self::get_page_url_link_html( $post_id ) );
		self::render_detail_item( 'admin-site-alt3', __( 'Browser', 'feedhat' ), esc_html( get_post_meta( $post_id, '_cf_browser', true ) ) );
		self::render_detail_item( 'laptop', __( 'Operating System', 'feedhat' ), esc_html( get_post_meta( $post_id, '_cf_os', true ) ) );
		self::render_detail_item( 'desktop', __( 'Screen size', 'feedhat' ), esc_html( get_post_meta( $post_id, '_cf_screen_size', true ) ) );
		self::render_detail_item( 'editor-expand', __( 'Viewport', 'feedhat' ), esc_html( get_post_meta( $post_id, '_cf_viewport_size', true ) ) );
		self::render_detail_item( 'wordpress-alt', __( 'WordPress version', 'feedhat' ), esc_html( get_post_meta( $post_id, '_cf_wp_version', true ) ) );

		echo '</dl>';
		echo '</div>';

		echo '</div>'; // .cf-detail__body

		echo '</div>'; // .cf-detail
	}

	/**
	 * Full-width screenshot preview, clickable to open the original file.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_detail_screenshot( $post_id ) {
		$path = get_post_meta( $post_id, '_cf_screenshot_id', true );

		if ( ! $path ) {
			echo '<p class="description">' . esc_html__( 'No screenshot was captured for this feedback.', 'feedhat' ) . '</p>';
			return;
		}

		$upload_dir = wp_upload_dir();
		$url        = trailingslashit( $upload_dir['baseurl'] ) . ltrim( $path, '/' );

		printf(
			'<p class="cf-detail__screenshot"><button type="button" class="cf-lightbox-trigger" data-full-src="%1$s" aria-label="%2$s"><img src="%1$s" alt="" /></button></p>',
			esc_url( $url ),
			esc_attr__( 'Feedback screenshot — click to view full size', 'feedhat' )
		);
	}

	/**
	 * Pro's per-pin descriptions (FeedHat_Pin_Tool) —
	 * shown as a small numbered list under the screenshot, since each
	 * entry maps to one of the numbered pin markers drawn on it.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_pin_notes( $post_id ) {
		$pins = get_post_meta( $post_id, '_cf_pin_notes', true );

		if ( ! is_array( $pins ) || ! $pins ) {
			return;
		}

		echo '<ul class="cf-pin-notes">';

		foreach ( $pins as $pin ) {
			$number      = isset( $pin['number'] ) ? absint( $pin['number'] ) : 0;
			$description = isset( $pin['description'] ) ? $pin['description'] : '';

			if ( '' === $description ) {
				continue;
			}

			printf(
				'<li class="cf-pin-notes__item"><span class="cf-pin-notes__number">%1$d</span><span class="cf-pin-notes__body">%2$s</span></li>',
				absint( $number ),
				nl2br( esc_html( $description ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * One "icon + label: value" row in the Page & environment list. Skips
	 * itself entirely (no empty row with just a label) when nothing was
	 * captured for it — e.g. an old entry submitted before a field like
	 * viewport size was added.
	 *
	 * @param string $dashicon   Dashicon name, without the "dashicons-" prefix.
	 * @param string $label      Row label (escaped here).
	 * @param string $value_html Pre-escaped/pre-built row value HTML.
	 */
	private static function render_detail_item( $dashicon, $label, $value_html ) {
		if ( '' === $value_html ) {
			return;
		}

		printf(
			'<div class="cf-detail__item"><dt><span class="dashicons dashicons-%1$s" aria-hidden="true"></span>%2$s</dt><dd>%3$s</dd></div>',
			esc_attr( $dashicon ),
			esc_html( $label ),
			$value_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass already-escaped/safe HTML.
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string Safe HTML, or '' if no page URL was captured.
	 */
	private static function get_page_url_link_html( $post_id ) {
		$url = get_post_meta( $post_id, '_cf_page_url', true );

		if ( ! $url ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
			esc_url( $url )
		);
	}

	/**
	 * Who submitted this feedback: reporter name/email if given, the
	 * logged-in user if not, or "Anonymous" if neither.
	 *
	 * @param int $post_id Post ID.
	 * @return string Safe HTML.
	 */
	private static function get_reporter_html( $post_id ) {
		$name    = get_post_meta( $post_id, '_cf_reporter_name', true );
		$email   = get_post_meta( $post_id, '_cf_reporter_email', true );
		$user_id = absint( get_post_meta( $post_id, '_cf_user_id', true ) );

		$parts = array();

		if ( $name ) {
			$parts[] = esc_html( $name );
		}

		if ( $email ) {
			$parts[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
		}

		if ( $parts ) {
			return implode( ' &middot; ', $parts );
		}

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			if ( $user ) {
				return sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( get_edit_user_link( $user_id ) ),
					esc_html( $user->display_name )
				);
			}
		}

		return esc_html__( 'Anonymous (not logged in)', 'feedhat' );
	}

	/**
	 * Handle the "Mark Resolved" / "Mark as New" row action.
	 */
	public static function handle_toggle_status() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		check_admin_referer( 'feedhat_toggle_status_' . $post_id );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( ! $post_id || ! in_array( $status, array( self::STATUS_NEW, self::STATUS_RESOLVED ), true ) ) {
			wp_die( esc_html__( 'Invalid feedback status request.', 'feedhat' ), '', array( 'response' => 400 ) );
		}

		if ( FeedHat_CPT_Feedback::POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'feedhat' ), '', array( 'response' => 403 ) );
		}

		update_post_meta( $post_id, '_cf_status', $status );
		// See the identical comment in FeedHat_Capture::save_meta()
		// — tracked here too so an entry toggled on Free still has an
		// accurate value if the site later upgrades to Pro's Kanban board.
		update_post_meta( $post_id, '_cf_status_changed_at', current_time( 'mysql' ) );

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=' . FeedHat_CPT_Feedback::POST_TYPE );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
