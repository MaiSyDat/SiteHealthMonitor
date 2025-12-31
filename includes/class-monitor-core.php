<?php
/**
 * Core Monitor Class
 *
 * Handles all monitoring logic including 404 detection and sitemap checks.
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
	 * Default static assets extensions to ignore for 404 detection.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_static_extensions = array( 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'map', 'ico', 'woff', 'woff2', 'ttf', 'eot' );

	/**
	 * Default bot/scanner User Agents to ignore.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_bot_user_agents = array( 'go-http-client', 'curl', 'wget', 'python-requests', 'python-urllib', 'scanner', 'bot', 'crawler', 'spider', 'monitor', 'check', 'test' );

	/**
	 * Default suspicious file patterns to ignore (common vulnerability scanner targets).
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $default_suspicious_patterns = array(
		'/\.php$/', // Any .php file in root
		'/wp-admin\/.*\.php$/', // PHP files in wp-admin
		'/wp-includes\/.*\.php$/', // PHP files in wp-includes
		'/wp-content\/.*\.php$/', // PHP files in wp-content (except themes/plugins)
		'/wp-content\/themes\/.*\/style\.php$/', // style.php in themes
		'/wp-content\/uploads\/.*\.php$/', // PHP files in uploads
	);

	/**
	 * Track if 404 notification has been sent for current request.
	 * Prevents duplicate emails when multiple hooks fire.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $notification_sent = false;

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
		add_action( 'wp', array( $this, 'detect_404_errors' ), 1 );
		add_action( 'template_redirect', array( $this, 'detect_404_errors' ), 0 );
		add_action( 'msd_monitor_check_sitemap', array( $this, 'check_sitemap_health' ) );
		add_action( 'msd_monitor_cleanup_old_records', array( $this, 'cleanup_old_records' ) );
	}

	/**
	 * Plugin activation.
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		if ( ! wp_next_scheduled( 'msd_monitor_check_sitemap' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'msd_monitor_check_sitemap' );
		}
		
		// Schedule cleanup of old notification records.
		if ( ! wp_next_scheduled( 'msd_monitor_cleanup_old_records' ) ) {
			wp_schedule_event( time(), 'daily', 'msd_monitor_cleanup_old_records' );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		$timestamp = wp_next_scheduled( 'msd_monitor_check_sitemap' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'msd_monitor_check_sitemap' );
		}
		
		$cleanup_timestamp = wp_next_scheduled( 'msd_monitor_cleanup_old_records' );
		if ( $cleanup_timestamp ) {
			wp_unschedule_event( $cleanup_timestamp, 'msd_monitor_cleanup_old_records' );
		}
	}

	/**
	 * Detect 404 errors (Internal broken links only).
	 *
	 * Hooked into 'wp' and 'template_redirect' actions to catch 404 pages before redirection plugins
	 * can redirect them. Only reports 404s when the referrer is from the same domain.
	 * Filters out static assets to only report actual page URLs.
	 *
	 * @since 1.0.0
	 */
	public function detect_404_errors() {
		if ( $this->notification_sent ) {
			return;
		}

		global $wp_query;
		
		if ( ! is_404() && ! ( isset( $wp_query->is_404 ) && $wp_query->is_404 ) ) {
			return;
		}

		$requested_url = $this->get_requested_url();

		if ( $this->is_static_asset( $requested_url ) ) {
			return;
		}

		// Filter out suspicious/scanner URLs (common vulnerability scanner targets).
		if ( $this->is_suspicious_url( $requested_url ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Filter out bot/scanner requests.
		if ( $this->is_bot_request( $user_agent ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		if ( empty( $referrer ) || ! $this->is_internal_referrer( $referrer ) ) {
			return;
		}

		$error_details = array(
			'type'        => '404 Error (Internal Broken Link)',
			'url'         => $requested_url,
			'referrer'    => $referrer,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown',
			'ip_address' => $this->get_client_ip(),
		);

		$this->send_notification( $error_details );
		$this->notification_sent = true;
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
			return;
		}

		$sitemap_url = esc_url_raw( $sitemap_url );
		$response    = wp_remote_get(
			$sitemap_url,
			array(
				'timeout'     => 30,
				'sslverify'   => true,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$error_details = array(
				'type'          => 'Sitemap Error',
				'url'           => $sitemap_url,
				'error_code'    => is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response ),
				'error_message' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response ),
			);

			$this->send_notification( $error_details );
		}
	}

	/**
	 * Send notification email.
	 * OPTIMIZED: Prevents spam by only sending email once per URL per 24 hours.
	 *
	 * @since 1.0.0
	 * @param array $error_details Error details array.
	 */
	private function send_notification( $error_details ) {
		// Get error URL (unique identifier for the error).
		$error_url = isset( $error_details['url'] ) ? $error_details['url'] : '';
		
		if ( empty( $error_url ) ) {
			return;
		}

		// Check if we should send email (anti-spam: 24h cooldown per URL).
		if ( ! $this->should_send_notification( $error_url ) ) {
			return;
		}

		$email_address = get_option( 'msd_monitor_email_address', get_option( 'admin_email' ) );

		if ( empty( $email_address ) || ! is_email( $email_address ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Site Health Alert', 'site-health-monitor' ),
			get_bloginfo( 'name' )
		);

		/**
		 * Filter the email subject before sending.
		 *
		 * @since 1.1.0
		 * @param string $subject Email subject.
		 * @param array  $error_details Error details array.
		 */
		$subject = apply_filters( 'msd_monitor_email_subject', $subject, $error_details );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		/**
		 * Filter the email headers before sending.
		 *
		 * @since 1.1.0
		 * @param array $headers Email headers array.
		 * @param array $error_details Error details array.
		 */
		$headers = apply_filters( 'msd_monitor_email_headers', $headers, $error_details );

		// Build email body.
		$email_body = $this->build_email_body( $error_details );

		/**
		 * Filter the email body before sending.
		 *
		 * @since 1.1.0
		 * @param string $email_body Email body HTML.
		 * @param array  $error_details Error details array.
		 */
		$email_body = apply_filters( 'msd_monitor_email_body', $email_body, $error_details );

		// Send email.
		$sent = wp_mail( $email_address, $subject, $email_body, $headers );

		// If email sent successfully, record the notification timestamp.
		if ( $sent ) {
			$this->record_notification_sent( $error_url );
		}
	}

	/**
	 * Check if notification should be sent for this URL.
	 * Prevents spam: only send once per URL per 24 hours.
	 *
	 * @since 1.0.0
	 * @param string $error_url The error URL to check.
	 * @return bool True if should send, false otherwise.
	 */
	private function should_send_notification( $error_url ) {
		// Normalize URL (remove trailing slash, convert to lowercase for consistency).
		$normalized_url = $this->normalize_url( $error_url );
		
		// Create unique key for this URL.
		$option_key = 'msd_monitor_sent_' . md5( $normalized_url );
		
		// Get last sent timestamp.
		$last_sent = get_option( $option_key, 0 );
		
		// If never sent, allow sending.
		if ( empty( $last_sent ) || 0 === intval( $last_sent ) ) {
			return true;
		}
		
		// Check if 24 hours have passed since last notification.
		$hours_since_last = ( time() - intval( $last_sent ) ) / HOUR_IN_SECONDS;
		
		// Only send if 24+ hours have passed.
		return $hours_since_last >= 24;
	}

	/**
	 * Record that a notification was sent for this URL.
	 * Stores timestamp in wp_options for tracking.
	 *
	 * @since 1.0.0
	 * @param string $error_url The error URL.
	 * @return void
	 */
	private function record_notification_sent( $error_url ) {
		// Normalize URL.
		$normalized_url = $this->normalize_url( $error_url );
		
		// Create unique key.
		$option_key = 'msd_monitor_sent_' . md5( $normalized_url );
		
		// Store current timestamp.
		update_option( $option_key, time(), false );
		
		// Schedule cleanup of old records (runs once daily).
		if ( ! wp_next_scheduled( 'msd_monitor_cleanup_old_records' ) ) {
			wp_schedule_event( time(), 'daily', 'msd_monitor_cleanup_old_records' );
		}
	}

	/**
	 * Normalize URL for consistent comparison.
	 * Removes trailing slashes, converts to lowercase, removes query strings for 404 errors.
	 *
	 * @since 1.0.0
	 * @param string $url URL to normalize.
	 * @return string Normalized URL.
	 */
	private function normalize_url( $url ) {
		if ( empty( $url ) ) {
			return '';
		}

		// Parse URL.
		$parsed = wp_parse_url( $url );
		
		if ( ! $parsed ) {
			return $url;
		}

		// Rebuild URL with normalized components.
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'http';
		$host   = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		$path   = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
		
		// For 404 errors, ignore query strings and fragments (they don't affect the broken link).
		// This prevents duplicate notifications for same URL with different query params.
		$normalized = $scheme . '://' . $host . $path;
		
		return $normalized;
	}

	/**
	 * Cleanup old notification records (older than 7 days).
	 * Prevents wp_options table from growing too large.
	 * Hooked into daily cron.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function cleanup_old_records() {
		global $wpdb;
		
		// Delete all options older than 7 days (we only need 24h tracking, but keep 7 days for safety).
		$cutoff_time = time() - ( 7 * DAY_IN_SECONDS );
		
		// Get all notification option keys.
		$option_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND CAST(option_value AS UNSIGNED) < %d",
				'msd_monitor_sent_%',
				$cutoff_time
			)
		);
		
		// Delete old records.
		if ( ! empty( $option_keys ) ) {
			foreach ( $option_keys as $key ) {
				delete_option( $key );
			}
		}
	}

	/**
	 * Build email body HTML.
	 *
	 * @since 1.0.0
	 * @param array $error_details Error details array.
	 * @return string HTML email body.
	 */
	private function build_email_body( $error_details ) {
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
	/**
	 * Get requested URL.
	 *
	 * @since 1.0.0
	 * @return string Requested URL.
	 */
	private function get_requested_url() {
		$protocol = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ) ? 'https' : 'http';
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( empty( $host ) ) {
			return '';
		}

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

		return in_array( $extension, $this->get_static_extensions(), true );
	}

	/**
	 * Get client IP address.
	 *
	 * @since 1.0.0
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'Unknown';
	}

	/**
	 * Check if referrer is from the same domain (internal link).
	 *
	 * @since 1.0.0
	 * @param string $referrer Referrer URL.
	 * @return bool True if referrer is from the same domain, false otherwise.
	 */
	private function is_internal_referrer( $referrer ) {
		if ( empty( $referrer ) ) {
			return false;
		}

		$site_host     = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );

		if ( empty( $site_host ) || empty( $referrer_host ) ) {
			return false;
		}

		return strtolower( $referrer_host ) === strtolower( $site_host );
	}

	/**
	 * Check if request is from a bot/scanner.
	 *
	 * @since 1.0.0
	 * @param string $user_agent User agent string.
	 * @return bool True if bot/scanner, false otherwise.
	 */
	private function is_bot_request( $user_agent ) {
		if ( empty( $user_agent ) ) {
			return false;
		}

		$user_agent_lower = strtolower( $user_agent );

		foreach ( $this->get_bot_user_agents() as $bot_pattern ) {
			if ( false !== strpos( $user_agent_lower, $bot_pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get static extensions list (filterable).
	 *
	 * @since 1.1.0
	 * @return array Array of static file extensions.
	 */
	private function get_static_extensions() {
		/**
		 * Filter the list of static file extensions to ignore for 404 detection.
		 *
		 * @since 1.1.0
		 * @param array $extensions Array of file extensions (without dot).
		 */
		return apply_filters( 'msd_monitor_static_extensions', $this->default_static_extensions );
	}

	/**
	 * Get bot user agents list (filterable).
	 *
	 * @since 1.1.0
	 * @return array Array of bot user agent patterns.
	 */
	private function get_bot_user_agents() {
		/**
		 * Filter the list of bot/scanner user agents to ignore.
		 *
		 * @since 1.1.0
		 * @param array $user_agents Array of user agent patterns (case-insensitive matching).
		 */
		return apply_filters( 'msd_monitor_bot_user_agents', $this->default_bot_user_agents );
	}

	/**
	 * Get suspicious URL patterns list (filterable).
	 *
	 * @since 1.1.0
	 * @return array Array of regex patterns for suspicious URLs.
	 */
	private function get_suspicious_patterns() {
		/**
		 * Filter the list of suspicious URL patterns to ignore (common vulnerability scanner targets).
		 *
		 * @since 1.1.0
		 * @param array $patterns Array of regex patterns (e.g., '/\.php$/').
		 */
		return apply_filters( 'msd_monitor_suspicious_patterns', $this->default_suspicious_patterns );
	}

	/**
	 * Check if URL matches suspicious patterns (common vulnerability scanner targets).
	 *
	 * @since 1.0.0
	 * @param string $url URL to check.
	 * @return bool True if suspicious, false otherwise.
	 */
	private function is_suspicious_url( $url ) {
		if ( empty( $url ) ) {
			return false;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $path ) ) {
			return false;
		}

		// Check against suspicious patterns.
		foreach ( $this->get_suspicious_patterns() as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		// Additional check: if referrer is the same as requested URL, it's likely a scanner.
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! empty( $referrer ) ) {
			$referrer_path = wp_parse_url( $referrer, PHP_URL_PATH );
			if ( $referrer_path === $path ) {
				// Same path in referrer and request = likely scanner.
				return true;
			}
		}

		return false;
	}

}

