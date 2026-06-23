# Slimstat Analytics #
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

**v5.5.0** — New Goals & Funnels: define a goal to measure any conversion, then chain 2–5 steps into a funnel to see your conversion rate and exactly where visitors drop off, with ready-made templates for WooCommerce checkout, SaaS signup, and content engagement. [Full changelog](https://github.com/wp-slimstat/wp-slimstat/blob/development/CHANGELOG.md).

### Main features ###
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Goals & Funnels**: turn traffic into answers — define a goal to measure a conversion (a WooCommerce sale, a signup, a key pageview) and see uniques, totals, and conversion rate, or chain steps into a funnel to spot exactly where visitors drop off. One goal is free; up to five goals and full funnels are unlocked via Pro.
* **Admin Bar Stats**: view real-time site stats directly from the WordPress admin bar — online visitors, pageviews, and top pages at a glance.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **Customize Reports**: Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
* **GDPR**: fully compliant with GDPR European law. Integrates seamlessly with WP Consent API. Consent banner translatable with WPML and Polylang.
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via Pro).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [JQVMap](https://github.com/10bestdesign/jqvmap)).

### Contribute ###
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [GitHub repository](https://github.com/wp-slimstat/wp-slimstat) and submit code enhancements, bug fixes, or localization files to help the plugin speak even more languages. And if coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) as a token of appreciation for our hard work.

### Requirements ###
* WordPress 5.6+
* PHP 7.4+
* MySQL 5.0.3+
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)