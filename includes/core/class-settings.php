<?php
/**
 * Plugin settings page (wp-admin) and the single `feedhat_settings`
 * option that stores every Free setting, per mục 4 in CLAUDE.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FeedHat_Settings {

	const OPTION_KEY = 'feedhat_settings';

	/**
	 * Hook suffix of the settings page, captured from add_submenu_page() so
	 * assets are only enqueued on that exact screen.
	 *
	 * @var string
	 */
	private static $settings_hook = '';

	/**
	 * Hook registration.
	 */
	public static function init() {
		// Default priority (10) — must run after FeedHat_Admin_List's
		// admin_menu registration (priority 5), which creates the parent
		// "edit.php?post_type=cf_feedback" menu this submenu attaches to.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_feedhat_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Get plugin settings with defaults applied.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'                  => true,
			'button_position'          => 'bottom-right',
			'button_offset_x'          => 20,
			'button_offset_y'          => 20,
			'button_style'             => 'pill',
			'button_size'              => 'medium',
			'button_color'             => '#e63946',
			'button_label'             => __( 'Feedback', 'feedhat' ),
			'targeting_mode'           => 'all_site', // all_site | specific_pages | url_param
			'targeting_page_ids'       => array(),
			'notify_email'             => get_option( 'admin_email' ),
			'notify_enabled'           => true,
			'admin_bar_enabled'        => true,
			'rate_limit_max'           => FeedHat_Capture::RATE_LIMIT_MAX_DEFAULT,
			'rate_limit_window'        => FeedHat_Capture::RATE_LIMIT_WINDOW_MINUTES_DEFAULT,
			'min_submit_seconds'       => FeedHat_Capture::MIN_SUBMIT_SECONDS_DEFAULT,
			// The page auto-created to host [feedhat_track] — see
			// FeedHat_Tracking::get_or_create_tracking_page_id().
			'tracking_page_id'         => 0,
			'compress_screenshots'     => true,
			'delete_data_on_uninstall' => false,
		);

		$settings = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Register the "Settings" submenu under Free's "FeedHat" menu.
	 */
	public static function register_menu() {
		self::$settings_hook = add_submenu_page(
			'edit.php?post_type=' . FeedHat_CPT_Feedback::POST_TYPE,
			__( 'FeedHat Settings', 'feedhat' ),
			__( 'Settings', 'feedhat' ),
			'manage_options',
			'feedhat-settings',
			array( __CLASS__, 'render_settings_page' )
			// No $position arg needed: this runs at the default admin_menu
			// priority (10), after Admin_List's (5) and, when Pro is
			// loaded, Kanban's (7) — plain append order lands this last.
		);
	}

	/**
	 * Load the settings screen's own JS only on that exact screen.
	 */
	public static function maybe_enqueue_assets( $hook ) {
		if ( $hook !== self::$settings_hook ) {
			return;
		}

		// wp-color-picker ships with WordPress core — no need to bundle
		// our own color-picker library for this.
		wp_enqueue_style( 'wp-color-picker' );

		// The Media Library modal, for Pro's logo uploader (Branding tab) —
		// harmless to load even when Pro/white-label isn't active, and only
		// ever loaded on this one settings screen.
		wp_enqueue_media();

		// Needed so the live button-style preview (render_widget_section())
		// renders with the exact same rules the front end uses — loaded
		// after admin.css so admin.css's .cf-launcher-preview-wrap override
		// (position: static, etc.) can win over widget.css's fixed layout.
		wp_enqueue_style(
			'feedhat-widget',
			FEEDHAT_URL . 'assets/css/widget.css',
			array(),
			FEEDHAT_VERSION
		);

		wp_enqueue_style(
			'feedhat-admin',
			FEEDHAT_URL . 'assets/css/admin.css',
			array( 'feedhat-widget' ),
			FEEDHAT_VERSION
		);

		wp_enqueue_script(
			'feedhat-settings',
			FEEDHAT_URL . 'assets/js/settings.js',
			array( 'jquery', 'wp-color-picker' ),
			FEEDHAT_VERSION,
			true
		);
	}

	/**
	 * The 4 corners the launcher button can be pinned to.
	 *
	 * @return array position => label
	 */
	private static function get_button_positions() {
		return array(
			'bottom-right' => __( 'Bottom right', 'feedhat' ),
			'bottom-left'  => __( 'Bottom left', 'feedhat' ),
			'top-right'    => __( 'Top right', 'feedhat' ),
			'top-left'     => __( 'Top left', 'feedhat' ),
			'middle-right' => __( 'Middle right (edge)', 'feedhat' ),
			'middle-left'  => __( 'Middle left (edge)', 'feedhat' ),
		);
	}

	/**
	 * @return array style => label
	 */
	private static function get_button_styles() {
		return array(
			'pill'    => __( 'Icon + label (filled)', 'feedhat' ),
			'outline' => __( 'Icon + label (outlined)', 'feedhat' ),
			'icon'    => __( 'Icon only', 'feedhat' ),
			'label'   => __( 'Text only', 'feedhat' ),
			'tab'     => __( 'Edge tab', 'feedhat' ),
		);
	}

	/**
	 * @return array size => label
	 */
	private static function get_button_sizes() {
		return array(
			'small'  => __( 'Small', 'feedhat' ),
			'medium' => __( 'Medium', 'feedhat' ),
			'large'  => __( 'Large', 'feedhat' ),
		);
	}

	/**
	 * Pages + posts an admin can pick from for "specific pages" targeting.
	 *
	 * @return array List of objects with ->ID, ->post_title, ->post_type.
	 */
	private static function get_targetable_posts() {
		return get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Tabs, in display order. Each Pro module appends into a specific tab
	 * via its own `feedhat_settings_tab_{slug}` action — see the
	 * per-tab `do_action()` calls in render_settings_page().
	 *
	 * @return array slug => [ 'label' => string, 'icon' => dashicons class ]
	 */
	private static function get_tabs() {
		$tabs = array(
			'general'       => array(
				'label' => __( 'General', 'feedhat' ),
				'icon'  => 'dashicons-admin-generic',
			),
			'targeting'     => array(
				'label' => __( 'Targeting', 'feedhat' ),
				'icon'  => 'dashicons-location-alt',
			),
			'notifications' => array(
				'label' => __( 'Notifications', 'feedhat' ),
				'icon'  => 'dashicons-email-alt',
			),
			'security'      => array(
				'label' => __( 'Security', 'feedhat' ),
				'icon'  => 'dashicons-shield',
			),
			'data'          => array(
				'label' => __( 'Data', 'feedhat' ),
				'icon'  => 'dashicons-database',
			),
		);

		if ( ! FeedHat_License::is_pro_active() ) {
			return $tabs;
		}

		return array(
			'general'       => $tabs['general'],
			'branding'      => array(
				'label' => __( 'Branding', 'feedhat' ),
				'icon'  => 'dashicons-art',
			),
			'targeting'     => $tabs['targeting'],
			'team'          => array(
				'label' => __( 'Team', 'feedhat' ),
				'icon'  => 'dashicons-groups',
			),
			'notifications' => $tabs['notifications'],
			'security'      => $tabs['security'],
			'data'          => $tabs['data'],
		);
	}

	/**
	 * Render the Settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'feedhat' ) );
		}

		$settings = self::get_settings();
		$tabs     = self::get_tabs();
		?>
		<div class="wrap cf-settings">
			<?php
			/*
			 * The icon lives INSIDE the <h1>, not in a separate wrapper next
			 * to it: WordPress core's own admin JS (wp-admin/js/common.js)
			 * relocates every admin_notices output — ours and every other
			 * active plugin's alike — to right after the first `.wrap h1`
			 * it finds, splicing them in between whatever came before and
			 * after that element. Keeping icon + title as one atomic <h1>
			 * means that relocation can only ever land below the whole
			 * branded title row, never in the middle of it.
			 */
			?>
			<h1 class="cf-settings__title">
				<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'FeedHat', 'feedhat' ); ?>
			</h1>
			<p class="cf-settings__tagline"><?php esc_html_e( 'See it. Mark it. Fix it — without leaving the page.', 'feedhat' ); ?></p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, cosmetic notice flag only. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'feedhat' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper cf-settings__tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="#cf-tab-<?php echo esc_attr( $slug ); ?>" class="nav-tab" data-cf-tab="<?php echo esc_attr( $slug ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="feedhat_save_settings" />
				<?php wp_nonce_field( 'feedhat_save_settings' ); ?>

				<div class="cf-tab-panel" id="cf-tab-general">
					<?php
					self::render_widget_section( $settings );
					self::render_tracking_section( $settings );
					self::render_language_section();

					/**
					 * Each fires inside its own tab panel — Pro modules append
					 * their own <h2> + form-table section here so this
					 * Free/core file never needs to know Pro's internals.
					 */
					do_action( 'feedhat_settings_tab_general' );
					?>
				</div>

				<?php if ( isset( $tabs['branding'] ) ) : ?>
				<div class="cf-tab-panel" id="cf-tab-branding">
					<?php do_action( 'feedhat_settings_tab_branding' ); ?>
				</div>
				<?php endif; ?>

				<div class="cf-tab-panel" id="cf-tab-targeting">
					<?php
					self::render_targeting_section( $settings );
					do_action( 'feedhat_settings_tab_targeting' );
					?>
				</div>

				<?php if ( isset( $tabs['team'] ) ) : ?>
				<div class="cf-tab-panel" id="cf-tab-team">
					<?php do_action( 'feedhat_settings_tab_team' ); ?>
				</div>
				<?php endif; ?>

				<div class="cf-tab-panel" id="cf-tab-notifications">
					<?php
					self::render_email_section( $settings );
					do_action( 'feedhat_settings_tab_notifications' );
					?>
				</div>

				<div class="cf-tab-panel" id="cf-tab-security">
					<?php
					self::render_security_section( $settings );
					do_action( 'feedhat_settings_tab_security' );
					?>
				</div>

				<div class="cf-tab-panel" id="cf-tab-data">
					<?php self::render_data_section( $settings ); ?>
				</div>

				<?php submit_button( __( 'Save Settings', 'feedhat' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array $settings Current settings.
	 */
	private static function render_widget_section( $settings ) {
		?>
		<h2><?php esc_html_e( 'Widget display', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable widget', 'feedhat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
						<?php esc_html_e( 'Show the feedback button on the front end', 'feedhat' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-button-position"><?php esc_html_e( 'Button position', 'feedhat' ); ?></label></th>
				<td>
					<select id="cf-button-position" name="button_position">
						<?php foreach ( self::get_button_positions() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['button_position'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( '"Middle" pins the button to the vertical center of the left/right edge instead of a corner — a spot corner-dwelling chat widgets (Messenger, Zalo, Tawk.to…) essentially never use.', 'feedhat' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Distance from edge', 'feedhat' ); ?></th>
				<td>
					<label for="cf-button-offset-x"><?php esc_html_e( 'Horizontal', 'feedhat' ); ?></label>
					<input type="number" id="cf-button-offset-x" name="button_offset_x" min="0" max="500" value="<?php echo esc_attr( $settings['button_offset_x'] ); ?>" class="small-text" /> px
					&nbsp; &nbsp;
					<label for="cf-button-offset-y"><?php esc_html_e( 'Vertical', 'feedhat' ); ?></label>
					<input type="number" id="cf-button-offset-y" name="button_offset_y" min="0" max="500" value="<?php echo esc_attr( $settings['button_offset_y'] ); ?>" class="small-text" /> px
					<p class="description"><?php esc_html_e( 'How far from the corner/edge the button sits. Increase these if another widget already occupies that spot. Vertical is ignored for the two Middle positions (always centered).', 'feedhat' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-button-style"><?php esc_html_e( 'Button style', 'feedhat' ); ?></label></th>
				<td>
					<select id="cf-button-style" name="button_style">
						<?php foreach ( self::get_button_styles() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['button_style'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-button-size"><?php esc_html_e( 'Button size', 'feedhat' ); ?></label></th>
				<td>
					<select id="cf-button-size" name="button_size">
						<?php foreach ( self::get_button_sizes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['button_size'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-button-color"><?php esc_html_e( 'Button color', 'feedhat' ); ?></label></th>
				<td>
					<input type="text" id="cf-button-color" name="button_color" value="<?php echo esc_attr( $settings['button_color'] ); ?>" class="cf-color-field" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-button-label"><?php esc_html_e( 'Button label', 'feedhat' ); ?></label></th>
				<td>
					<input type="text" id="cf-button-label" name="button_label" value="<?php echo esc_attr( $settings['button_label'] ); ?>" class="regular-text" maxlength="40" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: default button label. */
							esc_html__( 'Defaults to "%s".', 'feedhat' ),
							esc_html__( 'Feedback', 'feedhat' )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Preview', 'feedhat' ); ?></th>
				<td>
					<div class="cf-launcher-preview-wrap">
						<button type="button" id="cf-launcher-preview" class="cf-launcher cf-pos-<?php echo esc_attr( $settings['button_position'] ); ?> cf-style-<?php echo esc_attr( $settings['button_style'] ); ?> cf-size-<?php echo esc_attr( $settings['button_size'] ); ?>" style="--cf-color: <?php echo esc_attr( $settings['button_color'] ); ?>;" aria-label="<?php echo esc_attr( $settings['button_label'] ); ?>" tabindex="-1">
							<span class="cf-btn-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9l-5 4v-4H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"></path></svg></span>
							<span class="cf-launcher__label"><?php echo esc_html( $settings['button_label'] ); ?></span>
						</button>
					</div>
					<p class="description"><?php esc_html_e( 'Updates live as you change the options above.', 'feedhat' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array $settings Current settings.
	 */
	private static function render_targeting_section( $settings ) {
		$posts     = self::get_targetable_posts();
		$page_ids  = array_map( 'absint', (array) $settings['targeting_page_ids'] );
		$url_param = FEEDHAT_URL_PARAM . '=1';
		?>
		<h2><?php esc_html_e( 'Targeting', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show widget on', 'feedhat' ); ?></th>
				<td>
					<fieldset id="cf-targeting-mode">
						<label class="cf-targeting-option">
							<input type="radio" name="targeting_mode" value="all_site" <?php checked( $settings['targeting_mode'], 'all_site' ); ?> />
							<?php esc_html_e( 'The whole site', 'feedhat' ); ?>
						</label>
						<br />
						<label class="cf-targeting-option">
							<input type="radio" name="targeting_mode" value="specific_pages" <?php checked( $settings['targeting_mode'], 'specific_pages' ); ?> />
							<?php esc_html_e( 'Only selected pages/posts', 'feedhat' ); ?>
						</label>
						<br />
						<label class="cf-targeting-option">
							<input type="radio" name="targeting_mode" value="url_param" <?php checked( $settings['targeting_mode'], 'url_param' ); ?> />
							<?php esc_html_e( 'Only via a URL parameter', 'feedhat' ); ?>
						</label>

						<div class="cf-targeting-panel" data-mode="specific_pages">
							<p class="description">
								<?php esc_html_e( 'Choose the pages or posts where the widget should appear. Ctrl/Cmd-click to select more than one.', 'feedhat' ); ?>
							</p>
							<select name="targeting_page_ids[]" multiple="multiple" size="8" class="cf-page-select">
								<?php foreach ( $posts as $post ) : ?>
									<option value="<?php echo esc_attr( $post->ID ); ?>" <?php echo in_array( (int) $post->ID, $page_ids, true ) ? 'selected="selected"' : ''; ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: post title, 2: post type label. */
												__( '%1$s (%2$s)', 'feedhat' ),
												get_the_title( $post ),
												'page' === $post->post_type ? __( 'Page', 'feedhat' ) : __( 'Post', 'feedhat' )
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="cf-targeting-panel" data-mode="url_param">
							<p class="description">
								<?php
								printf(
									/* translators: %s: the fixed ?feedhat=1 URL parameter. */
									esc_html__( 'The widget shows only when a visitor adds %s to the page URL.', 'feedhat' ),
									'<code>' . esc_html( $url_param ) . '</code>'
								);
								?>
							</p>
							<p class="description">
								<em><?php esc_html_e( 'A separate FeedHat Pro plugin can use a custom parameter name and a secret value.', 'feedhat' ); ?></em>
							</p>
						</div>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array $settings Current settings.
	 */
	private static function render_email_section( $settings ) {
		?>
		<h2><?php esc_html_e( 'Email notifications', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable email notifications', 'feedhat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="notify_enabled" value="1" <?php checked( $settings['notify_enabled'] ); ?> />
						<?php esc_html_e( 'Email me when new feedback is submitted', 'feedhat' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-notify-email"><?php esc_html_e( 'Notification email', 'feedhat' ); ?></label></th>
				<td>
					<input type="email" id="cf-notify-email" name="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Sent through WordPress\'s own mail system (wp_mail) — if it does not arrive, check your site\'s SMTP configuration or install a dedicated SMTP plugin.', 'feedhat' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Admin toolbar notification', 'feedhat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="admin_bar_enabled" value="1" <?php checked( $settings['admin_bar_enabled'] ); ?> />
						<?php esc_html_e( 'Show a "Feedback" item in the WordPress admin toolbar (top bar) with a count and a list of new feedback', 'feedhat' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'A second, always-visible place to notice new feedback, on top of email and the sidebar menu badge — only appears while there is at least one new item.', 'feedhat' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Anti-spam controls. Rate limiting and the fast-fill time-trap are both
	 * heuristics, not a hard guarantee — see the code comments on
	 * FeedHat_Capture::check_rate_limit() and the "too fast" check
	 * in handle_submit() for what each one actually catches. CAPTCHA
	 * (reCAPTCHA/Turnstile), which needs a remote-loaded script, is a Pro
	 * add-on rather than something Free ships (see mục 5 in CLAUDE.md).
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_security_section( $settings ) {
		?>
		<h2><?php esc_html_e( 'Anti-spam', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Submission rate limit', 'feedhat' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: number input for max submissions, 2: number input for the time window in minutes. */
						esc_html__( 'Allow at most %1$s submissions per visitor every %2$s minutes.', 'feedhat' ),
						'<input type="number" name="rate_limit_max" min="1" max="50" value="' . esc_attr( $settings['rate_limit_max'] ) . '" class="small-text" />',
						'<input type="number" name="rate_limit_window" min="1" max="1440" value="' . esc_attr( $settings['rate_limit_window'] ) . '" class="small-text" />'
					);
					?>
					<p class="description"><?php esc_html_e( 'Identified by IP address. Protects against a single visitor (or a simple script) flooding the form.', 'feedhat' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cf-min-submit-seconds"><?php esc_html_e( 'Minimum time before sending', 'feedhat' ); ?></label></th>
				<td>
					<input type="number" id="cf-min-submit-seconds" name="min_submit_seconds" min="0" max="60" value="<?php echo esc_attr( $settings['min_submit_seconds'] ); ?>" class="small-text" />
					<?php esc_html_e( 'seconds', 'feedhat' ); ?>
					<p class="description"><?php esc_html_e( 'Silently rejects a submission sent faster than this after the panel was opened — real visitors need at least a few seconds to write a note, so this mainly catches bots that submit instantly. Set to 0 to disable.', 'feedhat' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Honeypot field', 'feedhat' ); ?></th>
				<td>
					<p><?php esc_html_e( 'Always on — a hidden field invisible to real visitors but often auto-filled by bots. Not configurable.', 'feedhat' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		if ( ! FeedHat_License::is_pro_active() ) {
			?>
			<div class="cf-pro-upsell">
				<span class="dashicons dashicons-info" aria-hidden="true"></span>
				<p><?php esc_html_e( 'CAPTCHA (Cloudflare Turnstile) for stronger bot protection is available in the separate FeedHat Pro plugin.', 'feedhat' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Surfaces the otherwise-invisible [feedhat_track] page —
	 * FeedHat_Tracking auto-creates it the first time it's needed
	 * (no setup required), but nothing else in wp-admin ever mentions it
	 * exists, where it lives, or that its shortcode can be moved elsewhere.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_tracking_section( $settings ) {
		$page_id = absint( $settings['tracking_page_id'] );
		$page_ok = $page_id && 'publish' === get_post_status( $page_id );
		?>
		<h2><?php esc_html_e( 'Feedback status tracking', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking page', 'feedhat' ); ?></th>
				<td>
					<?php if ( $page_ok ) : ?>
						<p>
							<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_permalink( $page_id ) ); ?></a>
							&nbsp;
							(<a href="<?php echo esc_url( get_edit_post_link( $page_id, 'raw' ) ); ?>"><?php esc_html_e( 'edit page', 'feedhat' ); ?></a>)
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Not created yet — it will be the first time someone submits feedback.', 'feedhat' ); ?></p>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: the [feedhat_track] shortcode tag. */
							esc_html__( 'Every submission includes a private link where the reporter can check its status here, with no login needed. This page was created automatically — feel free to rename it, move it in your menu, or edit the surrounding content, as long as the %s shortcode itself stays somewhere on it.', 'feedhat' ),
							'<code>[feedhat_track]</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Read-only: always follows the site's own language on Free. The actual
	 * override control (when Pro is active) lives in its own section below
	 * — see FeedHat_Language_Override::render_settings_section().
	 */
	private static function render_language_section() {
		?>
		<h2><?php esc_html_e( 'Language', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Widget language', 'feedhat' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: %s: current site locale, e.g. "vi" or "en_US". */
							esc_html__( 'Currently following the site language: %s', 'feedhat' ),
							'<code>' . esc_html( get_locale() ) . '</code>'
						);
						?>
					</p>
					<?php if ( ! FeedHat_License::is_pro_active() ) : ?>
						<p class="description">
							<?php esc_html_e( 'A separate FeedHat Pro plugin can show the widget in a language independent of the site language.', 'feedhat' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param array $settings Current settings.
	 */
	private static function render_data_section( $settings ) {
		?>
		<h2><?php esc_html_e( 'Data', 'feedhat' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Screenshot storage', 'feedhat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="compress_screenshots" value="1" <?php checked( $settings['compress_screenshots'] ); ?> />
						<?php esc_html_e( 'Compress screenshots (downscale wide images and save as JPEG) to save disk space', 'feedhat' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'On by default. Turn this off to store the original capture as uploaded.', 'feedhat' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'feedhat' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?> />
						<?php esc_html_e( 'Delete all feedback data (entries, screenshots, and settings) when this plugin is uninstalled', 'feedhat' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default so nothing is lost by accident.', 'feedhat' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Validate, sanitize, and persist the settings form submission.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'feedhat' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'feedhat_save_settings' );

		$settings = self::get_settings();

		$settings['enabled'] = ! empty( $_POST['enabled'] );

		if ( isset( $_POST['button_position'] ) ) {
			$position = sanitize_key( wp_unslash( $_POST['button_position'] ) );

			if ( array_key_exists( $position, self::get_button_positions() ) ) {
				$settings['button_position'] = $position;
			}
		}

		if ( isset( $_POST['button_offset_x'] ) ) {
			$settings['button_offset_x'] = min( 500, absint( wp_unslash( $_POST['button_offset_x'] ) ) );
		}

		if ( isset( $_POST['button_offset_y'] ) ) {
			$settings['button_offset_y'] = min( 500, absint( wp_unslash( $_POST['button_offset_y'] ) ) );
		}

		if ( isset( $_POST['button_style'] ) ) {
			$style = sanitize_key( wp_unslash( $_POST['button_style'] ) );

			if ( array_key_exists( $style, self::get_button_styles() ) ) {
				$settings['button_style'] = $style;
			}
		}

		if ( isset( $_POST['button_size'] ) ) {
			$size = sanitize_key( wp_unslash( $_POST['button_size'] ) );

			if ( array_key_exists( $size, self::get_button_sizes() ) ) {
				$settings['button_size'] = $size;
			}
		}

		if ( isset( $_POST['button_color'] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST['button_color'] ) );

			if ( $color ) {
				$settings['button_color'] = $color;
			}
		}

		if ( isset( $_POST['button_label'] ) ) {
			$label                    = sanitize_text_field( wp_unslash( $_POST['button_label'] ) );
			$settings['button_label'] = ( '' !== $label ) ? $label : __( 'Feedback', 'feedhat' );
		}

		if ( isset( $_POST['targeting_mode'] ) ) {
			$mode        = sanitize_key( wp_unslash( $_POST['targeting_mode'] ) );
			$valid_modes = array( 'all_site', 'specific_pages', 'url_param' );

			if ( in_array( $mode, $valid_modes, true ) ) {
				$settings['targeting_mode'] = $mode;
			}
		}

		$page_ids                       = isset( $_POST['targeting_page_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['targeting_page_ids'] ) ) : array();
		$page_ids                       = array_values( array_unique( array_filter( $page_ids ) ) );
		$settings['targeting_page_ids'] = $page_ids;

		$settings['notify_enabled'] = ! empty( $_POST['notify_enabled'] );

		if ( isset( $_POST['notify_email'] ) ) {
			$email                    = sanitize_email( wp_unslash( $_POST['notify_email'] ) );
			$settings['notify_email'] = $email ? $email : get_option( 'admin_email' );
		}

		$settings['admin_bar_enabled'] = ! empty( $_POST['admin_bar_enabled'] );

		if ( isset( $_POST['rate_limit_max'] ) ) {
			$settings['rate_limit_max'] = max( 1, min( 50, absint( wp_unslash( $_POST['rate_limit_max'] ) ) ) );
		}

		if ( isset( $_POST['rate_limit_window'] ) ) {
			$settings['rate_limit_window'] = max( 1, min( 1440, absint( wp_unslash( $_POST['rate_limit_window'] ) ) ) );
		}

		if ( isset( $_POST['min_submit_seconds'] ) ) {
			$settings['min_submit_seconds'] = max( 0, min( 60, absint( wp_unslash( $_POST['min_submit_seconds'] ) ) ) );
		}

		$settings['compress_screenshots']     = ! empty( $_POST['compress_screenshots'] );
		$settings['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] );

		update_option( self::OPTION_KEY, $settings );

		/**
		 * Fires after Free's own settings are saved, on the same
		 * nonce/capability-verified request — each Pro module hooks its own
		 * sanitize + update_option() here for the fields its settings
		 * section (added via `feedhat_settings_sections`) submits.
		 */
		do_action( 'feedhat_save_settings' );

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=' . FeedHat_CPT_Feedback::POST_TYPE . '&page=feedhat-settings' );
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', $redirect ) );
		exit;
	}
}
