=== Site Health Monitor ===
Contributors: maisydat
Tags: monitoring, 404, sitemap, health check, email notifications, error detection
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor website errors (404, Sitemap) and send email notifications to administrators.

== Description ==

Site Health Monitor is a lightweight WordPress plugin that automatically monitors your website for common errors and sends email notifications to administrators when issues are detected.

**Features:**

* **404 Error Detection**: Automatically detects 404 errors from internal broken links (only reports when referrer is from the same domain, filtering out bot scans and direct access)
* **Sitemap Health Check**: Monitors your sitemap.xml file twice daily to ensure it's accessible
* **Email Notifications**: Sends immediate email alerts when errors are detected
* **Smart Filtering**: Ignores static assets (CSS, JS, images) and external 404s to focus on real issues
* **Easy Configuration**: Simple settings page in WordPress admin

**How it works:**

* The plugin hooks into WordPress's 404 detection system to catch broken internal links
* Sitemap monitoring runs automatically via WordPress cron (twice daily)
* All notifications are sent immediately via wp_mail() to the configured email address

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/site-health-monitor` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > Site Health Monitor to configure your notification email and sitemap URL.

== Frequently Asked Questions ==

= Do I need to configure anything? =

Yes, you should configure:
* Notification email address (defaults to admin email)
* Sitemap URL (if you want sitemap monitoring)

= Will this send too many emails? =

The plugin only sends emails for:
* 404 errors from internal broken links (same domain referrer)
* Sitemap errors (maximum twice daily)

Static assets and external 404s are automatically filtered out.

= Does this work with caching plugins? =

Yes, the plugin works with all caching plugins. 404 detection happens before caching, and sitemap checks run via cron.

= Can I disable 404 monitoring? =

Currently, 404 monitoring is always active. You can disable sitemap monitoring by leaving the sitemap URL field empty.

== Screenshots ==

1. Settings page with notification configuration
2. Monitoring status display

== Changelog ==

= 1.0.0 =
* Initial release
* 404 error detection (internal broken links only)
* Sitemap health monitoring
* Email notifications

== Upgrade Notice ==

= 1.0.0 =
Initial release of Site Health Monitor.

