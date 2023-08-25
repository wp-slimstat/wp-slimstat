=== Slimstat Analytics ===
Contributors: coolmann, toxicum, veronalabs, mostafas1990
Tags: analytics, statistics, counter, tracking, reports, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress, power stats, hit
Text Domain: wp-slimstat
Requires at least: 5.6
Requires PHP: 7.4+
Tested up to: 6.3
Stable tag: 5.0.9

== Description ==
Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Main features =
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **GDPR**: fully compliant with the GDPR European law. You can test your website at [cookiebot.com](https://www.cookiebot.com/en/).
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via premium add-ons).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [amMap](https://www.ammap.com/)).

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
1. **Overview** - Your website traffic at a glance
2. **Real-Time** - A real-time view of your visitors' whereabouts
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Audiance** - See your visitors' full information

== Changelog ==
= 5.0.9 =
* [Fix] Hardened plugin security and sanitization of GeoIP argument

= 5.0.8 =
* [Fix] Fixed the save customize page issue.

= 5.0.7 =
* [Fix] Resolved an issue with chart dataSet.
* [Fix] Fixed the messy layout on the customize page.

= 5.0.6 =
* [New] Integrated a Feedback button powered by [FeedbackBird!](https://feedbackbird.io/) in the admin area to gather user feedback.
* [Fix] Resolve STRPOS error when enqueuing scripts.
* [Fix] Ensure backward compatibility for null values.
* [Fix] Fix broken access in delete_pageView and notices_handler functions.
* [Fix] Resolve escaping issue with literal '%' character in wpdb->prepare().

= 5.0.5.1 =
* [Fix] Backward compatibility

= 5.0.5 =
* [Fix] Hardened plugin security and prepare the queries
* [Fix] Fixed comparison view issue
* [Fix] Removed GeoIP download archive in error cases as well

= 5.0.4 =
* [Fix] Backward compatibility

= 5.0.3 =
* [Fix] Fix & compatibility with the Add-Ons

= 5.0.2 =
We recently encountered an issue with the license of some libraries that did not comply with [WordPress.org](http://wordpress.org/) regulations. As a result, we have replaced those libraries in this release:

* [Fix] Replaced the chart library from amCharts with Chart.js.
* [Fix] Replaced the map world library from AmMap with JQVMap.
* [Fix] Improved plugin security and argument sanitization.

= 4.9.4 =
* [Fix] Hardened plugin security and sanitization of arguments

= 4.9.3.3 =
* [Fix] Disabled shortcode's filtering WHERE statement and make security harder.

= 4.9.3.2 =
* [Fix] The filtering issue

= 4.9.3.1 =
* [Fix] Query issue in UTF-8 slugs

= 4.9.3 =
* [Update] New logo and icon for the plugin!
* [Fix] Hardened plugin security and sanitization of user input and escaped output

= 4.9.2 =
* [Fix] Fixed tweak notice errors while activating the plugin in fresh installation
* [Update] Tested up to WordPress v6.1

= 4.9.0.1 = 
* [Fix] Entries in the Top Referring Domains report were pointing to broken links (thank you, [s7ech](https://github.com/slimstat/wp-slimstat/issues/21)).
* [Fix] The new Browscap Library requires at least PHP 7.4, up from 7.1. (thank you, [Daniel Jaraud](https://github.com/slimstat/wp-slimstat/issues/22)).

= 4.9 =
* [New] Browscap Library is now bundled with the main plugin, only definition files are downloaded dynamically.
* [New] Support MaxMind License Key for GeoLite2 database downloads.
* [New] Speedup Browscap version check when repository site is down.
* [New] Delete plugin settings and stats only if explicitly enabled in settings
* [Fix] Addressed a PHP warning of undefined variable when parsing a query string looking for search term keywords (thank you, [inndesign](https://wordpress.org/support/topic/line-747-and-line-1574-undefined)).
* [Fix] Fixed SQL error when Events Manager plugin is installed, 'Posts and Pages' is enabled, and no events are existing (thank you, [lwangamaman](https://wordpress.org/support/topic/you-have-an-error-in-your-sql-syntax-22/).
* [Fix] Starting with 4.9, PHP 7.4+ is required (thank you, [stephanie-mitchell](https://wordpress.org/support/topic/slimstats-4-8-8-1-fails-on-php-5-3-26-but-no-warning-during-installation/).
* [Fix] Opt-Out cookie does not delete slimstat cookie.

= 4.8.8.1 =
* [Update] The Privacy Mode option under Slimstat > Settings > Tracker now controls the fingerprint collection mechanism as well. If you have this option enabled to comply with European privacy laws, your visitors' IP addresses will be masked and they won't be fingerprinted (thank you, Peter).
* [Update] Improved handling of our local DNS cache to store hostnames when the option to convert IP addresses is enabled in the settings.
* [Fix] Redirects from the canonical URL to its corresponding nice permalink were being affected by a new feature introduce in version 4.8.7 (thank you, [Brookjnk](https://wordpress.org/support/topic/slimstat-forces-wordpress-to-use-302-redirects-instead-of-301/)).
* [Fix] The new tracker was throwing a Javascript error when handling events attached to DOM elements without a proper hierarchy (thank , you [pollensteyn](https://wordpress.org/support/topic/another-javascript-error/)).
* [Fix] Tracking downloads using third-party solutions like [Download Attachments](https://wordpress.org/plugins/download-attachments/) was not working as expected (thank you, [damianomalorzo](https://wordpress.org/support/topic/downolads-stats/)).
* [Fix] Inverted stacking order of dots on the map so that the most recent pageviews are always on top, when dots are really close to each other.
* [Fix] A warning message was being returned by the function looking for search keywords in the URL (thank you, Ryan).

= 4.8.8 =
* [New] Implemented new [FingerPrintJs2](https://github.com/Valve/fingerprintjs2) library in the tracker. Your visitors are now associated with a unique identifier that does not rely on cookies, IP address or other unreliable information. This will allow Slimstat to produce more accurate results in terms of session lenghts, user patterns and much more.
* [New] Added event handler for 'beforeunload', which will allow the tracker to better meausure the time spent by your visitors on each page. This will make our upcoming new charts even more accurate.
* [Update] Improved algorithm that scans referrer URLs for search terms, and introduced a new list of search engines. Please help us improve this list by submitting your missing search engine entries.
* [Update] Added title field to Slimstat widgets (thank you, [jaroslawistok](https://wordpress.org/support/topic/broken-layout-from-3rd-widget-in-footer/)).
* [Update] Reverted a change to the Top Web Pages report that was now combining URLs with a trailing slash and ones without one into one result. As James pointed out, this is a less accurate measure and hides the fact that people might be accessing the website in different ways.
* [Update] The event tracker now annotates each event with information about the mouse button that was clicked and other useful data.
* [Fix] The Country shortcode was not working as expected because of a change in how we handle localization files.
* [Fix] Renamed slim_i18n class to avoid conflict with external libraries.

= 4.8.7.3 =
* [New] Implemented a simple query cache to minimize the number of requests needed to crunch and display the data in the reports.
* [Update] Extended tracker to also record the 'fragment' portion of the URL, if available (this feature is only available in Client Mode).
* [Update] Do not show the resource title in Network View mode.
* [Fix] Some reports were not optimized for our Network Analytics add-on (thank you, Peter).
* [Fix] The notice displayed to share the latest news with our users would not disappear even after clicking the X button to close it (thank you, Anton).
* [Fix] The 'Top Web Pages' reports was listing duplicate entries.
* [Fix] A regression bug was affecting the Currently Online report, by showing incorrect information for the IP addresses.
* [Fix] After resetting the Customizer view, all reports were being listed twice (thank you, Anton).
* [Fix] A new feature introduced by our Javascript tracker was not working as expected in IE11 (thank you, [51nullacht](https://wordpress.org/support/topic/js-error-in-wp-slimstat-js321/)).

= 4.8.7.2 =
* [Fix] The new tracker was having problems recording clicks on SVG elements within a link (thank you, [pollensteyn](https://wordpress.org/support/topic/javascript-error-typeerror/#post-11926542)).
* [Fix] The event handler is now capable of tracking events on BUTTONs within FORM elements.
* [Fix] Permalinks containing post query strings were not being recorded as expected.

= 4.8.7.1 =
* [Note] We are in the process of deprecating the two columns *type* and *event_description* in the events table, and consolidating that information in the *notes* field. Code will be added to Slimstat in a few released to actually drop these columns from the database. If you are using those two columns in your custom code, please feel free to contact our support team to discuss your options and how to update your code using the information collected by the new tracker.
* [Fix] A warning message was being displayed when enabling the opt-out feature with certain versions of PHP.
* [Fix] PHP warning being displayed when trying to update some of the add-ons' settings.
* [Fix] The new tracker was recording the number of posts on an archive page even when the single article was being displayed.
* [Fix] License keys for premium add-ons were not being saved as expected, due to a side effect of the new security features we implemented in the Settings.

= 4.8.7 =
* [New] With this update, we are introducing an *updated tracker* (both server and client-side): a new simplified codebase that gets rid of a few layers of convoluted functions and algorithms accumulated over the years. We have been working on this update for quite a while, and the recent conflict with another plugin discovered by some users convinced us to make this our top priority. Even though we have tested our new code using a variety of scenarios, you can understand how it would be impossible to cover all the possible environments available out there. Make sure to clear your caches (local, Cloudflare, WP plugins, etc), to allow Slimstat to append the new tracking script to your pages. Also, if you are using Slimstat to track *external* pages (outside of your WP install), please make sure to update the code you're using on those pages with the new one you can find in Slimstat > Settings > Tracker > External Pages.
* [New] Increased minimum WordPress requirements to version 4.9, given that we are now using some more modern functions to enqueue the tracker and implement the customizer feature.
* [Update] We tweaked the SQL query to retrieve 'recent' results, and added a GROUP BY clause to remove duplicates. This might affect some custom reports you might have created, so please don't hesitate to contact us if you have any question or experience any issues.
* [Update] The columns to store event type and description are being deprecated, and consolidated into the existing 'notes' column. Our function `ss_track()` will only accept the note parameter moving forward. Please update your custom code accordingly. In a few releases, we are going to drop those columns from the database.
* [Update] Changed wording on Traffic Sources report to explain what kind of search engine result pages are being counted (thank you, Nina).
* [Fix] The button to delete all the records from the database was not working as expected (thank you, [Softfully](https://wordpress.org/support/topic/delete-all-records-doesnt-work/)).
* [Fix] When the plugin was network activated on a group of existing blogs, the tables used to store all the records were not initialized as expected, under given circumstances.
* [Fix] A bug was affecting certain shortcodes when PHP 7.2 was enabled (thank you, Peter).
* [Fix] Emptying one of the settings and saving did not produce the desired effect.