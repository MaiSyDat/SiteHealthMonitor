<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package MSD_Monitor
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled cron events.
$timestamp = wp_next_scheduled( 'msd_monitor_check_sitemap' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'msd_monitor_check_sitemap' );
}

// Delete plugin options.
delete_option( 'msd_monitor_email_address' );
delete_option( 'msd_monitor_sitemap_url' );

