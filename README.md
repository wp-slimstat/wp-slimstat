# Slimstat Analytics #
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

### Main features ###
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **GDPR**: fully compliant with the GDPR European law. You can test your website at [cookiebot.com](https://www.cookiebot.com/en/).
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via premium add-ons).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [amMap](https://www.ammap.com/)).

### Premium Add-ons ###
Visit [our website](https://www.wp-slimstat.com/addons/) for a list of available extensions.

### Contribute ###
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [Github repository](https://github.com/slimstat/wp-slimstat) and submit code enhancements, bugfixes or provide localization files to let our plugin speak even more languages. [This page](https://www.paypal.com/cgi-bin/webscr?cmd###_s-xclick&hosted_button_id###BNJR5EZNY3W38)
is for those who would like to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, and coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) for Slimstat as a token of appreciation for our hard work!

### Requirements ###
* WordPress 4.9+
* PHP 5.2+ (or 7.1+ if you use the Browscap data file)
* MySQL 5.0.3+
* At least 40 MB of free web space
* At least 5 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)

## Installation ##
1. In your WordPress admin, go to Plugins > Add New
2. Search for limstat Analytics
3. Click on **Install Now** next to Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. If your `wp-admin` folder is not publicly accessible, make sure to check our [knowledge base](https://docs.wp-slimstat.com/) to see if there's anything else you need to do

## Please note ##
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.
* If you are upgrading from a version prior to 4.0, please [install version 4.0](https://downloads.wordpress.org/plugin/wp-slimstat.4.0.zip) first to upgrade the database structure and download the new Geolocation data.

## Frequently Asked Questions ##
Our knowledge base is available on our [support center](https://docs.wp-slimstat.com/) website.

