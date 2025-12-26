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
	 * Static assets extensions to ignore for 404 detection.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $static_extensions = array( 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'map', 'ico', 'woff', 'woff2', 'ttf', 'eot' );

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
	 *
	 * @since 1.0.0
	 * @param array $error_details Error details array.
	 */
	private function send_notification( $error_details ) {
		$email_address = get_option( 'msd_monitor_email_address', get_option( 'admin_email' ) );

		if ( empty( $email_address ) || ! is_email( $email_address ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Site Health Alert', 'site-health-monitor' ),
			get_bloginfo( 'name' )
		);

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		wp_mail( $email_address, $subject, $this->build_email_body( $error_details ), $headers );
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

		return in_array( $extension, $this->static_extensions, true );
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

}

