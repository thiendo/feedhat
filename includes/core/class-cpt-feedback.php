<?php
/**
 * Registers the `cf_feedback` custom post type used to store feedback entries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_CPT_Feedback {

	const POST_TYPE = 'cf_feedback';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the private `cf_feedback` post type.
	 *
	 * Not public, no archive/front-end URL — feedback is only ever
	 * accessed through the wp-admin management screen.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'           => _x( 'Feedback', 'post type general name', 'feedhat' ),
			'singular_name'  => _x( 'Feedback', 'post type singular name', 'feedhat' ),
			'menu_name'      => _x( 'FeedHat', 'admin menu', 'feedhat' ),
			'name_admin_bar' => _x( 'Feedback', 'add new on admin bar', 'feedhat' ),
			'add_new'        => __( 'Add New', 'feedhat' ),
			'add_new_item'   => __( 'Add New Feedback', 'feedhat' ),
			'edit_item'      => __( 'Edit Feedback', 'feedhat' ),
			'new_item'       => __( 'New Feedback', 'feedhat' ),
			'view_item'      => __( 'View Feedback', 'feedhat' ),
			'search_items'   => __( 'Search Feedback', 'feedhat' ),
			'not_found'      => __( 'No feedback found.', 'feedhat' ),
			'all_items'      => __( 'All Feedback', 'feedhat' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false, // Custom admin menu is registered by the settings/admin class.
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'editor', 'custom-fields' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Total number of stored feedback entries (used to enforce the Free cap).
	 *
	 * @return int
	 */
	public static function get_total_count() {
		$counts = wp_count_posts( self::POST_TYPE );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Number of stored feedback entries with a given `_cf_status` value.
	 * Used for the wp-admin menu bell badge (status = new).
	 *
	 * @param string $status 'new' or 'resolved'.
	 * @return int
	 */
	public static function get_status_count( $status ) {
		global $wpdb;

		$cache_key = 'status_count_' . $status;
		$count     = wp_cache_get( $cache_key, 'feedhat' );

		if ( false !== $count ) {
			return (int) $count;
		}

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- counting posts by a meta value has no non-direct-query equivalent as cheap as a single COUNT(); result is cached above.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_value = %s",
				'_cf_status',
				self::POST_TYPE,
				'publish',
				$status
			)
		);

		// Short TTL: this only feeds admin-facing "new feedback" badges, so a
		// few seconds of staleness after a status change is an acceptable
		// trade-off for skipping the join on every page load in between.
		wp_cache_set( $cache_key, (int) $count, 'feedhat', 30 );

		return (int) $count;
	}
}
