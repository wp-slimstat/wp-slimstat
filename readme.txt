=== SlimStat Analytics ===
Contributors: veronalabs, coolmann, toxicum, mostafas1990
Tags: analytics, statistics, tracking, reports, geolocation
Text Domain: wp-slimstat
Requires at least: 5.6
Requires PHP: 7.4
Tested up to: 6.7
Stable tag: 5.2.10
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The leading web analytics plugin for WordPress

== Description ==
Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Main Features =
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **GDPR**: fully compliant with the GDPR European law. You can test your website at [cookiebot.com](https://www.cookiebot.com/en/).
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via Pro).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [amMap](https://www.ammap.com/)).

= Pro Pack Features =
* **Network Analytics**: Enable a network-wide view of your reports and settings.
* **Email Reports**: Receive your reports directly in your mailbox.
* **Export to Excel**: Download your reports as CSV files.
* **Heatmap**: Display a heatmap layer of the most clicked areas on your website.
* **User Overview**: Monitor your registered users by tracking their activities and time on site.
* **MaxMind Integration**: Connect to MaxMind's Geolocation API to retrieve detailed information about your visitors.
* **Custom DB**: Use an external database to store all the information about your visitors.
* **Extended Overview**: Add custom columns to the User Overview widget and export file.

= Requirements =
* WordPress 5.0+
* PHP 7.4+
* MySQL 5.0.3+
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)

== Installation ==
1. In your WordPress admin, go to Plugins > Add New
2. Search for Slimstat Analytics
3. Click on **Install Now** next to Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)

== Please note ==
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

== Frequently Asked Questions ==
An extensive knowledge base is available on our [website](https://www.wp-slimstat.com/).

== Screenshots ==
1. **Real-Time** - A real-time view of your visitors' whereabouts
2. **Overview** - Your website traffic at a glance
3. **Audience** - See your visitors' full information
4. **Site Analysis** - Provides insights into how visitors are using your website
5. **Traffic Sources** - See where your visitors are coming from, such as search engines, social media, or referral websites
6. **Customize widgets** - Allows you to customize the analytics widgets that are displayed in your Slimstat dashboard
7. **Settings** - Plenty of options to customize the plugin's behavior

== Changelog ==
= 5.2.10 - 2025-03-09 =
- **Enhancement**: Improved SQL update query to support offset with `LIMIT`.

= 5.2.9 - 2024-11-12 =
- **Enhancement**: Ensured compatibility with WordPress version 6.7.
- **Fix**: Resolved the Top Referring Domain Issue.

[See changelog for all versions](https://raw.githubusercontent.com/wp-slimstat/wp-slimstat/master/CHANGELOG.md).
