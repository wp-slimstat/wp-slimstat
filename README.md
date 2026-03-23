# Slimstat Analytics #
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

**v5.4.6** — We heard you. Upgrading to 5.4.x broke tracking for many of you. This release fixes it: your site works the way it did before 5.4.0, no manual steps required. [Full changelog](https://github.com/wp-slimstat/wp-slimstat/blob/development/CHANGELOG.md).

### Main features ###
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Admin Bar Stats**: view real-time site stats directly from the WordPress admin bar — online visitors, pageviews, and top pages at a glance.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **Customize Reports**: Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
* **GDPR**: fully compliant with GDPR European law. Integrates seamlessly with WP Consent API. Consent banner translatable with WPML and Polylang.
* **Filters**: exclude users from slimstat collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via premium add-ons).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [JQVMap](https://github.com/10bestdesign/jqvmap)).

### Contribute ###
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [Github repository](https://github.com/slimstat/wp-slimstat) and submit code enhancements, bugfixes or provide localization files to let our plugin speak even more languages. [This page](https://www.paypal.com/cgi-bin/webscr?cmd###_s-xclick&hosted_button_id###BNJR5EZNY3W38)
is for those who would like to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, and coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) for Slimstat as a token of appreciation for our hard work!

### Requirements ###
* WordPress 5.6+
* PHP 7.4+
* MySQL 5.0.3+
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)