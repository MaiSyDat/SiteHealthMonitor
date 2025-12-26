<?php
/**
 * Plugin Name: Site Health Monitor
 * Plugin URI: https://wordpress.org/plugins/site-health-monitor
 * Description: Monitor website errors (404, Sitemap) and send email notifications to administrators.
 * Version: 1.0.0
 * Author: Mai Sy Dat
 * Author URI: https://hupuna.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-health-monitor
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 *
 * @package MSD_Monitor
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Plugin version.
 *
 * @since 1.0.0
 */
define( 'MSD_MONITOR_VERSION', '1.0.0' );

/**
 * Plugin directory path.
 *
 * @since 1.0.0
 */
define( 'MSD_MONITOR_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 *
 * @since 1.0.0
 */
define( 'MSD_MONITOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 *
 * @since 1.0.0
 */
function msd_monitor_activate() {
	require_once MSD_MONITOR_PATH . 'includes/class-monitor-core.php';
	MSD_Monitor_Core::get_instance()->activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @since 1.0.0
 */
function msd_monitor_deactivate() {
	require_once MSD_MONITOR_PATH . 'includes/class-monitor-core.php';
	MSD_Monitor_Core::get_instance()->deactivate();
}

register_activation_hook( __FILE__, 'msd_monitor_activate' );
register_deactivation_hook( __FILE__, 'msd_monitor_deactivate' );

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function msd_monitor_init() {
	require_once MSD_MONITOR_PATH . 'includes/class-monitor-core.php';
	require_once MSD_MONITOR_PATH . 'includes/class-monitor-admin.php';

	MSD_Monitor_Core::get_instance();
	MSD_Monitor_Admin::get_instance();
}

add_action( 'plugins_loaded', 'msd_monitor_init' );

