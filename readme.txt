=== SlimStat Analytics ===
Contributors: veronalabs, coolmann, toxicum, parhumm, mostafas1990
Tags: analytics, statistics, tracking, reports, geolocation
Text Domain: wp-slimstat
Requires at least: 5.6
Requires PHP: 7.4
Tested up to: 6.8
Stable tag: 5.3.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The leading web analytics plugin for WordPress

== Description ==
Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Main Features =
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **Customize Reports**: Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
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
* WordPress 5.6+
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

= Report Bugs =
Having trouble with a bug? Please [create an issue](https://github.com/wp-slimstat/wp-slimstat/issues/new) on GitHub. Kindly note that [GitHub](https://github.com/wp-slimstat/wp-slimstat) is exclusively for bug reports; other inquiries will be closed.

For security vulnerabilities, please report them through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/wordpress/plugin/wp-slimstat/vdp). The Patchstack team will validate, triage, and handle any security issues.

== Frequently Asked Questions ==
An extensive knowledge base is available on our [website](https://www.wp-slimstat.com/).

== Screenshots ==
1. **Real-Time** - A real-time view of your visitors' whereabouts
2. **Word Map** - Identify them by country, browser, and operating system in a snap.
3. **Overview** - Your website traffic at a glance. Enjoy a simple, all-in-one dashboard to check your website stats quickly.
4. **Audience** - See your visitors' full information
5. **Site Analysis** - See top pages, categories, download and outbound links in an easy, simple view.
6. **Traffic Sources** - See where your visitors are coming from, such as search engines, social media, or referral websites
7. **Customize widgets** - Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
8. **WordPress Dashboard** - Add and display custom reports like Traffic Sources directly on your WordPress dashboard!
9. **Settings** - Plenty of options to customize the plugin's behavior

== Changelog ==
= 5.3.0 - 2025-08-25 =
- **New**: Tracker type options (REST API + Ad-blocker bypass) for improved tracking flexibility.
- **New**: Support for WordPress date format setting in charts.
- **New**: Hourly, daily, weekly, monthly, and yearly chart granularities for deeper insights.
- **Enhancement**: Redesigned line charts for better readability.
- **Enhancement**: Compatibility with WordPress’s Interactivity API for seamless integration.
- **Enhancement**: Added new 3 date ranges formats (Last 2 weeks, Previous month, This month).
[See full release notes](https://wp-slimstat.com/wordpress-analytics-plugin-slimstat-5-3-release-notes/?utm_source=wordpress&utm_medium=changelog&utm_campaign=changelog&utm_content=5-3-0)

= 5.2.13 - 2025-04-29 =
- **Fix**: Resolved issues with pagination in reports.

= 5.2.12 - 2025-04-26 =
- **Enhancement**: Removed red color from report export boxes to reduce eye strain and improve user experience.

= 5.2.11 - 2025-04-25 =
- Full release notes → [WordPress Real-time Analytics Plugin](https://wp-slimstat.com/wordpress-analytics-plugin-slimstat-5-2-11-release-notes/?utm_source=wordpress&utm_medium=changelog&utm_campaign=changelog&utm_content=5-2-11) – SlimStat 5.2.11 Release Notes
- **Visual Enhancement**: Improved UI with eye-catching visual elements for better user experience.
- **Enhancement**: Optimized SQL query to reduce the chances of errors and improve overall performance.
- **Enhancement**: The "Export" button for non-Pro users now links to the Slimstat PRO version page, improving clarity around upgrade options.
- **Enhancement**: Added support for the WordPress date format setting for the charts.
- **Fix**: Fatal error in EmailReportsAddon.php for missing `get_plugins` method.
- **Fix**: Prevented PHP warning by checking if 'referer' array key is set in searchterms reports view.
- **Fix**: Fix a database error related to the notes column.
- **Fix**: Prevented horizontal scrolling in the reports area and improved page loading animations by ensuring styles are applied correctly.
- **Fix**: Addressed several user-reported issues to enhance overall stability and user experience.
- **Fix**: Investigate and resolve the "Division by zero" fatal error in `wp-slimstat-db.php` caused by PHP version 8.2.22. Further investigation needed to determine the root cause and provide a fix.

= 5.2.9 - 2024-11-12 =
- **Enhancement**: Ensured compatibility with WordPress version 6.7.
- **Fix**: Resolved the Top Referring Domain Issue.

[See changelog for all versions](https://raw.githubusercontent.com/wp-slimstat/wp-slimstat/master/CHANGELOG.md).
