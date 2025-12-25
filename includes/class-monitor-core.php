<?php
/**
 * Core Monitor Class
 *
 * Handles all monitoring logic including 404 detection, sitemap checks, and database error listening.
 *
 * @package MSD_Monitor
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Core Monitor Class
 *
 * @since 1.0.0
 */
class MSD_Monitor_Core {

	/**
	 * Instance of this class.
	 *
	 * @since 1.0.0
	 * @var MSD_Monitor_Core
	 */
	private static $instance = null;

	/**
	 * Cooldown period in seconds (60 minutes).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $cooldown_period = 3600;

	/**
	 * Static assets extensions to ignore for 404 detection.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $static_extensions = array( 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'map', 'ico', 'woff', 'woff2', 'ttf', 'eot' );

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 * @return MSD_Monitor_Core
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
		// 404 Error Detector.
		add_action( 'template_redirect', array( $this, 'detect_404_errors' ) );

		// Sitemap Health Check Cron.
		add_action( 'msd_monitor_check_sitemap', array( $this, 'check_sitemap_health' ) );

		// Database Error Listener (available in WordPress 5.2+).
		// Note: This hook may not be available in older WordPress versions.
		// WordPress will simply not fire the action if it doesn't exist.
		add_action( 'wp_db_query_error', array( $this, 'handle_database_error' ), 10, 2 );
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// Schedule sitemap health check cron (twice daily).
		if ( ! wp_next_scheduled( 'msd_monitor_check_sitemap' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'msd_monitor_check_sitemap' );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clear scheduled cron.
		$timestamp = wp_next_scheduled( 'msd_monitor_check_sitemap' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'msd_monitor_check_sitemap' );
		}
	}

	/**
	 * Detect 404 errors.
	 *
	 * Hooked into template_redirect to catch 404 pages.
	 * Filters out static assets to only report actual page URLs.
	 *
	 * @since 1.0.0
	 */
	public function detect_404_errors() {
		if ( ! is_404() ) {
			return;
		}

		// Get the requested URL.
		$requested_url = $this->get_requested_url();

		// Filter out static assets.
		if ( $this->is_static_asset( $requested_url ) ) {
			return;
		}

		// Check rate limiting.
		$transient_key = 'msd_monitor_404_' . md5( $requested_url );
		if ( get_transient( $transient_key ) ) {
			return; // Still in cooldown period.
		}

		// Gather error details.
		$error_details = array(
			'type'        => '404 Error',
			'url'         => $requested_url,
			'referrer'    => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'Direct access',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown',
			'ip_address' => $this->get_client_ip(),
		);

		// Send notification email.
		$this->send_notification( $error_details );

		// Set cooldown transient.
		set_transient( $transient_key, true, $this->cooldown_period );
	}

	/**
	 * Check sitemap health.
	 *
	 * Cron job that runs twice daily to check if sitemap is accessible.
	 *
	 * @since 1.0.0
	 */
	public function check_sitemap_health() {
		$sitemap_url = get_option( 'msd_monitor_sitemap_url', '' );

		if ( empty( $sitemap_url ) ) {
			return; // No sitemap URL configured.
		}

		// Check rate limiting.
		$transient_key = 'msd_monitor_sitemap_error';
		if ( get_transient( $transient_key ) ) {
			return; // Still in cooldown period.
		}

		// Sanitize sitemap URL.
		$sitemap_url = esc_url_raw( $sitemap_url );

		// Fetch sitemap.
		$response = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'     => 30,
				'sslverify'   => true,
				'redirection' => 5,
			)
		);

		// Check if request failed or returned non-200 status.
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$error_code    = is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response );
			$error_message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response );

			$error_details = array(
				'type'         => 'Sitemap Error',
				'url'          => $sitemap_url,
				'error_code'   => $error_code,
				'error_message' => $error_message,
			);

			// Send notification email.
			$this->send_notification( $error_details );

			// Set cooldown transient.
			set_transient( $transient_key, true, $this->cooldown_period );
		}
	}

	/**
	 * Handle database errors.
	 *
	 * Listens to wp_db_query_error action to catch SQL query errors.
	 *
	 * @since 1.0.0
	 * @param string $query The SQL query that caused the error.
	 * @param string $error The error message.
	 */
	public function handle_database_error( $query, $error ) {
		// Check rate limiting.
		$transient_key = 'msd_monitor_db_error';
		if ( get_transient( $transient_key ) ) {
			return; // Still in cooldown period.
		}

		$error_details = array(
			'type'         => 'Database Error',
			'query'        => sanitize_text_field( $query ),
			'error_message' => sanitize_text_field( $error ),
		);

		// Send notification email.
		$this->send_notification( $error_details );

		// Set cooldown transient.
		set_transient( $transient_key, true, $this->cooldown_period );
	}

	/**
	 * Send notification email.
	 *
	 * Sends email notification to administrator using wp_mail().
	 *
	 * @since 1.0.0
	 * @param array $error_details Error details array.
	 */
	private function send_notification( $error_details ) {
		$email_address = get_option( 'msd_monitor_email_address', get_option( 'admin_email' ) );

		if ( empty( $email_address ) || ! is_email( $email_address ) ) {
			return; // Invalid email address.
		}

		// Build email subject.
		$subject = sprintf(
			/* translators: %s: Error type */
			__( '[%s] Site Health Alert', 'site-health-monitor' ),
			get_bloginfo( 'name' )
		);

		// Build email body.
		$body = $this->build_email_body( $error_details );

		// Set email headers.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		// Send email.
		wp_mail( $email_address, $subject, $body, $headers );
	}

	/**
	 * Build email body HTML.
	 *
	 * @since 1.0.0
	 * @param array $error_details Error details array.
	 * @return string HTML email body.
	 */
	private function build_email_body( $error_details ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$body = '<html><body>';
		$body .= '<h2>' . esc_html__( 'Site Health Alert', 'site-health-monitor' ) . '</h2>';
		$body .= '<p>' . esc_html__( 'An error has been detected on your website:', 'site-health-monitor' ) . '</p>';
		$body .= '<table style="border-collapse: collapse; width: 100%; max-width: 600px;">';

		$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'Error Type', 'site-health-monitor' ) . '</td>';
		$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['type'] ) . '</td></tr>';

		if ( isset( $error_details['url'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'URL', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['url'] ) . '</td></tr>';
		}

		if ( isset( $error_details['referrer'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'Referrer', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['referrer'] ) . '</td></tr>';
		}

		if ( isset( $error_details['user_agent'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'User Agent', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['user_agent'] ) . '</td></tr>';
		}

		if ( isset( $error_details['ip_address'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'IP Address', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['ip_address'] ) . '</td></tr>';
		}

		if ( isset( $error_details['error_code'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'Error Code', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['error_code'] ) . '</td></tr>';
		}

		if ( isset( $error_details['error_message'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'Error Message', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( $error_details['error_message'] ) . '</td></tr>';
		}

		if ( isset( $error_details['query'] ) ) {
			$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'SQL Query', 'site-health-monitor' ) . '</td>';
			$body .= '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . esc_html( $error_details['query'] ) . '</code></td></tr>';
		}

		$body .= '<tr><td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">' . esc_html__( 'Time', 'site-health-monitor' ) . '</td>';
		$body .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html( current_time( 'mysql' ) ) . '</td></tr>';

		$body .= '</table>';
		$body .= '<p><small>' . esc_html__( 'This is an automated message from Site Health Monitor plugin.', 'site-health-monitor' ) . '</small></p>';
		$body .= '</body></html>';

		return $body;
	}

	/**
	 * Get requested URL.
	 *
	 * @since 1.0.0
	 * @return string Requested URL.
	 */
	private function get_requested_url() {
		$protocol = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http';
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $protocol . '://' . $host . $uri;
	}

	/**
	 * Check if URL is a static asset.
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return bool True if static asset, false otherwise.
	 */
	private function is_static_asset( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $path ) ) {
			return false;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, $this->static_extensions, true );
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'HTTP_X_FORWARDED_FOR',  // Proxy.
			'REMOTE_ADDR',           // Standard.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) && ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				// Validate IP.
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'Unknown';
	}
}

