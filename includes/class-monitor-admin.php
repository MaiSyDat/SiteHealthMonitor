<?php
/**
 * Admin Settings Class
 *
 * Handles admin UI and settings page.
 *
 * @package MSD_Monitor
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Admin Settings Class
 *
 * @since 1.0.0
 */
class MSD_Monitor_Admin {

	/**
	 * Instance of this class.
	 *
	 * @since 1.0.0
	 * @var MSD_Monitor_Admin
	 */
	private static $instance = null;

	/**
	 * Settings page hook suffix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 * @return MSD_Monitor_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings page to Settings menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page() {
		$this->page_hook = add_submenu_page(
			'options-general.php',
			__( 'Site Health Monitor', 'site-health-monitor' ),
			__( 'Site Health Monitor', 'site-health-monitor' ),
			'manage_options',
			'site-health-monitor',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		register_setting(
			'msd_monitor_settings',
			'msd_monitor_email_address',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_option( 'admin_email' ),
			)
		);

		register_setting(
			'msd_monitor_settings',
			'msd_monitor_sitemap_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_section(
			'msd_monitor_main_section',
			__( 'Notification Settings', 'site-health-monitor' ),
			array( $this, 'render_section_description' ),
			'site-health-monitor'
		);

		add_settings_field(
			'msd_monitor_email_address',
			__( 'Notification Email Address', 'site-health-monitor' ),
			array( $this, 'render_email_field' ),
			'site-health-monitor',
			'msd_monitor_main_section'
		);

		add_settings_field(
			'msd_monitor_sitemap_url',
			__( 'Sitemap URL to Check', 'site-health-monitor' ),
			array( $this, 'render_sitemap_field' ),
			'site-health-monitor',
			'msd_monitor_main_section'
		);
	}

	/**
	 * Render section description.
	 *
	 * @since 1.0.0
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure email notifications for website errors. The plugin monitors 404 errors (internal broken links only) and sitemap health.', 'site-health-monitor' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Note:', 'site-health-monitor' ) . '</strong> ' . esc_html__( 'Notifications are sent immediately for every valid error detected.', 'site-health-monitor' ) . '</p>';
	}

	/**
	 * Render email address field.
	 *
	 * @since 1.0.0
	 */
	public function render_email_field() {
		$value = get_option( 'msd_monitor_email_address', get_option( 'admin_email' ) );
		?>
		<input type="email" 
			id="msd_monitor_email_address" 
			name="msd_monitor_email_address" 
			value="<?php echo esc_attr( $value ); ?>" 
			class="regular-text" 
			placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Email address where error notifications will be sent. Defaults to admin email if empty.', 'site-health-monitor' ); ?>
		</p>
		<?php
	}

	/**
	 * Render sitemap URL field.
	 *
	 * @since 1.0.0
	 */
	public function render_sitemap_field() {
		$value = get_option( 'msd_monitor_sitemap_url', '' );
		?>
		<input type="url" 
			id="msd_monitor_sitemap_url" 
			name="msd_monitor_sitemap_url" 
			value="<?php echo esc_url( $value ); ?>" 
			class="regular-text" 
			placeholder="https://example.com/sitemap.xml" />
		<p class="description">
			<?php esc_html_e( 'Full URL to your sitemap.xml file. The plugin will check this URL twice daily. Leave empty to disable sitemap monitoring.', 'site-health-monitor' ); ?>
		</p>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'site-health-monitor' ) );
		}

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				'msd_monitor_messages',
				'msd_monitor_message',
				__( 'Settings saved successfully.', 'site-health-monitor' ),
				'success'
			);
		}

		settings_errors( 'msd_monitor_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'msd_monitor_settings' );
				do_settings_sections( 'site-health-monitor' );
				submit_button( __( 'Save Settings', 'site-health-monitor' ) );
				?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Monitoring Status', 'site-health-monitor' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( '404 Error Detection', 'site-health-monitor' ); ?></th>
						<td>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<strong><?php esc_html_e( 'Active', 'site-health-monitor' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Monitoring internal broken links only (404 errors with referrer from the same domain). Static assets and external 404s are ignored.', 'site-health-monitor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemap Health Check', 'site-health-monitor' ); ?></th>
						<td>
							<?php
							$sitemap_url = get_option( 'msd_monitor_sitemap_url', '' );
							if ( ! empty( $sitemap_url ) ) {
								$next_run = wp_next_scheduled( 'msd_monitor_check_sitemap' );
								?>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<strong><?php esc_html_e( 'Active', 'site-health-monitor' ); ?></strong>
								<p class="description">
									<?php
									if ( $next_run ) {
										printf(
											/* translators: %s: Next scheduled time */
											esc_html__( 'Next check scheduled for: %s', 'site-health-monitor' ),
											esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) )
										);
									} else {
										esc_html_e( 'Cron job not scheduled. Please deactivate and reactivate the plugin.', 'site-health-monitor' );
									}
									?>
								</p>
								<?php
							} else {
								?>
								<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
								<strong><?php esc_html_e( 'Inactive', 'site-health-monitor' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'Please configure a sitemap URL above to enable monitoring.', 'site-health-monitor' ); ?>
								</p>
								<?php
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}

