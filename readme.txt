=== Slimstat Analytics ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, statistics, counter, tracking, reports, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress, power stats, hit
Text Domain: wp-slimstat
Requires at least: 3.8
Requires PHP: 5.2
Tested up to: 5.2
Stable tag: 4.8.3

== Description ==
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Feature Spotlight =
[youtube https://www.youtube.com/watch?v=zEKP9yC8x6g]

= Main features =
* **Real-time access log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **GDPR guidelines**: fully compliant with the European law. You can test your website at [cookiebot.com](https://www.cookiebot.com/en/).
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via premium add-ons).
* **Cache Friendly**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: support for hashing IP addresses in the database to protect your users privacy.
* **Detailed information**: geolocate your visitors by city and country, browser type and operating system (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [amMap](http://www.ammap.com/)).

= Premium Add-ons =
Visit [our website](http://www.wp-slimstat.com/addons/) for a list of available extensions.

= Contribute =
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [Github repository](https://github.com/slimstat/wp-slimstat) and submit code enhancements, bugfixes or provide localization files to let our plugin speak even more languages. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who would like to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, and coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) for Slimstat as a token of appreciation for our hard work!

= Requirements =
* WordPress 3.8+
* PHP 5.2+ (or 7.1+ if you use the Browscap data file)
* MySQL 5.0.3+
* At least 40 MB of free web space
* At least 5 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)
* IE9+ or any browser supporting HTML5, to access the reports

== Installation ==
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP Slimstat Analytics
3. Click on **Install Now** under WP Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. Go to Slimstat > Settings > Maintenance tab > MaxMind IP to Country section and click on "Install GeoLite DB" to detect your visitors' countries based on their IP addresses
6. If your `wp-admin` folder is not publicly accessible, make sure to check our [knowledge base](https://docs.wp-slimstat.com/) to see if there's anything else you need to do

== Please note ==
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.
* If you are upgrading from a version prior to 4.0, please [install version 4.0](https://downloads.wordpress.org/plugin/wp-slimstat.4.0.zip) first to upgrade the database structure and download the new Geolocation data.

== Frequently Asked Questions ==
Our knowledge base is available on our [support center](https://docs.wp-slimstat.com/) website.

== Screenshots ==
1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==
= 4.8.4 =
* [Note] We recently received an email from one of our users suggesting that we replace the line charts currently used to display reports over a timeline with **bar charts**, because 'the number of pageviews and IPs are discrete numbers, hence they should also be presented as discrete numbers', according to him. What do you think? Please let us know by [sending us a message](https://support.wp-slimstat.com/) on our support platform. Thank you.
* [Update] Implemented a new optimized function to retrieve the post count on the Edit Posts/Pages/CPTs screens. Thank you, Lance.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4.5.3.
* [Update] Removed the tracker notice field under Settings > Maintenance as it was confusing many people and generating extra work for our customer service team.
* [Update] Removed the option to not track "client properties" like screen resolution, etc.
* [Update] Removed the 'About Slimstat' report, given that some of the information in it has been moved to the Settings.
* [Update] Removed unused strings, improved contextual descriptions and applied consistent naming conventions across our codebase (first pass).
* [Update] The Slimstat admin menu is now added to the Admin Bar by default. Please go to Settings > Basic > WordPress Integration and change the corresponding option, if you prefer to use the side menu instead.

= 4.8.3 =
* [Note] Thank you for all the great feedback you provided to our unofficial survey about retiring the 'browser plugins' feature. The vast majority of those who replied confirmed what we already thought. Please consider backing up your database if you would like to preserve this information for future analysis. With this update, we removed the portion of code that tracks that information, but kept the existing data untouched. In a couple of releases, code will be added to actually drop this column from the database.
* [New] If English is not your primary languge, Slimstat will now display a notice asking for your help to [translate our plugin](https://translate.wordpress.org/projects/wp-plugins/wp-slimstat/) in your language. Please consider volunteering for this great opportunity to help our community!
* [Update] We are working with the GlotPress community to improve the way Slimstat speaks your language. We had to change the way certain strings are defined in our source code. Please let us know if you notice any unexpected behavior when analyzing languages, countries and operating systems.
* [Update] Removed Facebook rankings metrics, as the API has been deprecated and the new one is not accessible without a private token.
* [Update] MozRank has been deprecated, we have replaced it with the Domain Authority metric.
* [Update] Spring cleaning in the 'admin notices' department: removed some obsolete CSS code, replaced by built-in WP classes and definitions.
* [Fix] Changed the default minimum capability to access the reports from 'activate_plugins' to 'manage_options', so that regular administrators (a.k.a. non-super admins) in a multisite environment can still see their own reports (thank you, [homepageware](https://wordpress.org/support/topic/slimstat-and-multisite/)). This update does not affect existing installations: if you want regular admins to see their own stats, please go to Slimstat > Settings > Access Control and change the values in the corresponding fields.
* [Fix] The autorefresh feature for the Activity Log was not working as expected. Thank you to all the users who patiently worked with us to identify the issue.
* [Fix] A conflict between the Async loader and AmCharts 4 was causing the Screen Options tab to not work as expected (thank you, [softfully](https://wordpress.org/support/topic/screen-options-doesnt-open/)).
* [Fix] Removed unused setting 'Expand Reports'

= 4.8.2 = 
* [Note] Our team has been contemplating the idea of deprecating the information collected about your visitors' *browser plugins* (Java, PDF reader, RealView player, Silverlight, etc). In this day and age, where browsers use either built-in functionality to provide those features, or extensions that cannot be tracked for privacy purposes, it feels anachronistic to continue collecting this outdated information. By getting rid of this specific feature, we can streamline our code, improve performance, and reduce the database size. However, we wanted to hear from our users before anything is actually implemented. Please do not hesitate [to let us know](https://support.wp-slimstat.com) if you are using the 'browser plugins' field for your reporting needs.
* [New] Many CRM integration plugins rely mostly on the user emails, not usernames. For this reason, a new email field has been added to the database (thank you, [sandrodz](https://github.com/sandrodz)).
* [Update] Changed the preset intervals in the date filter dropdown so that you can get a day over day comparison (Monday over Monday, etc) for improved accuracy.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4.4.9.
* [Fix] The countdown timer on the Activity Log was not working as expected (thank you, [anniest](https://wordpress.org/support/topic/no-refresh-2/)).
* [Fix] The countdown timer was causing an warning message to appear on other screens.
* [Fix] Minor aesthetic improvements.

= 4.8.1 =
* [Update] Async mode will now serialize concurrent requests to the backend to optimize performance and reduce server load.
* [Fix] Addressed a remote XSS vulnerability disclosed by Sucuri/GoDaddy.
* [Fix] Charts were displaying the wrong label for certain values (thank you, Alex).

= 4.8 =
* [Note] Now that we have a cleaner foundation to build on, it's time to start introducing new reports and new ways to segment your audience and the traffic they generate. While our users test the latest changes and updates (to confirm that the foundation is indeed solid and bug-free), we are hard at work implementing the first batch of new reports. Some of them will be made available in the free version, while others will be added to our premium add-on, [User Overview](http://www.wp-slimstat.com/downloads/user-overview/). And we need your help! If you think that a specific report should be added to Slimstat, please do not hesitate to let us know!
* [Note] Worried about the recent [news regarding jQuery vulnerabilities](https://www.zdnet.com/article/popular-jquery-javascript-library-impacted-by-prototype-pollution-flaw/)? Slimstat doesn't use jQuery as a dependency, so you can sleep tight knowing that your website will not be affected.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4. This new release is not backward compatible, so the code to integrate it with Slimstat had to be completely rewritten. Please let us know if you notice any issues.
* [Update] [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), the library we use to check if a new version of our premium add-ons is available for download, has been update to version 4.6.
* [Update] If you're using our partner's CDN functionality (JsDelivr) to load the tracker, their link is now always loaded over HTTPS for added security.
* [Update] Switched the Add-on Update checker URL to HTTPS, for added security (thank you, Peter).
* [Update] Changed the protocol of all the URLs used within Slimstat, including references to our documentation, to HTTPS.
* [Update] Added icon to geolocate Originating IP addresses, when detected.
* [Fix] The optout cookie path was not being set correctly (thank you, [ralfkerkhoff](https://wordpress.org/support/topic/opt-out-cookie-per-page/)).
* [Fix] Google seems to be using a new User Agent string for its "mobile" crawler, which was causing Slimstat from incorrectly identifying visits as coming from mobile devices, instead of bots (thank you, Ron).
* [Fix] An error was being returned if SVG elements were using the A tag on a page (thank you, [snaphappyme](https://wordpress.org/support/topic/uncaught-typeerror-all_linksn-href-indexof/)).
* [Fix] A bug was causing Slimstat to incorrectly geolocate visits to websites behind a Cloudflare load balancer. Please update the IP Address Fix add-on as well.
* [Fix] Tweaked the formula to determine your website bounce rate, and updated the associated description to better reflect the underlying calculations.

= 4.7.9.1 =
* [Fix] It turns out the new [Browscap Library](https://github.com/slimstat/browscap-db) we introduced requires PHP 7.x, not 5.6 as stated in their documentation. Added some code to prevent fatal errors for those still using an older version of PHP.

= 4.7.9 =
* [Note] Jason is back! Apologies for the radio silence in the last few months, due to personal reasons. Please know that this plugin is still very much alive and kicking. I'm working on cleaning up my development environment, updating the Git repository and streamlining coding workflows. I'm catching up on past and new feature requests and pending bugfixes. As always, thank you for your continued support.
* [Note] Happy birthday, Slimstat: April 2019 marks your 9th year in the [WordPress repository](https://plugins.trac.wordpress.org/changeset/227217) and your 13th year overall. Not many plugins out there can brag about that!
* [Update] Our optimized fork of the Browscap Library is now available as a public Github repository. Slimstat will now check for updates on Github directly, which streamlines our deployment workflow. Feel free to contact us if you experience any issues with the new data file.

= 4.7.8.3 =
* [Fix] The opt-out message was being displayed even if the corresponding setting was turned off. Apologies for the inconvenience.

= 4.7.8.2 =
* [New] The IP to hostname conversion feature now stores in the database the information it calculates, to avoid querying the DNS server over and over again.
* [Update] The opt-out banner is now loaded dynamically, to address HTML caching issues. Thank you, [fuchsws](https://wordpress.org/support/topic/opt-out-message-vs-html-cache).

= 4.7.8.1 =
* [New] The Customizer now has its own access control settings. This allows admins to control in a more granular way who can do what.
* [Update] If you have an existing opt-in mechanism, asking your users if they want to be tracked, you can now configure Slimstat to use that cookie to determine if a given pageview should be recorded or not.

= 4.7.8 =
* [Note] A few users have reached out to us to ask if Slimstat would be compliant with the upcoming [General Data Protection Regulation (GDPR)](https://en.wikipedia.org/wiki/General_Data_Protection_Regulation) guidelines and regulations that are about to be activated all across Europe. Based on our understanding of this new law, as long as the hosting provider where you are storing the information collected by Slimstat is GDPR compliant, then you won't have to worry about any extra layers of compliance offered by software like ours. One of our primary goals is to make sure that you and only you are the sole owner of the data collected by our plugin. This has always been what makes Slimstat stand out from the crowd: while Jetpack, Google Analytics and many other services have full unrestricted access to the data they collect on your website, we at Slimstat don't treat our users as *the product* that we sell to other companies.
* [New] Our plugin now honors the [Do Not Track header](https://en.wikipedia.org/wiki/Do_Not_Track). Please note that this feature can be turned off in the settings, and will be enabled by default.
* [New] We introduced an experimental option to allow your users to opt out of tracking via a text box displayed at the bottom of your website. Please go to Settings > Filters to customize the behavior and the message to suit your needs and website layout. You can also use third-party solutions to let your visitors opt out, and then configure Slimstat to read the corresponding cookie they set.
* [New] You can now add reports to the Access Log screen, and customize it just like any other screen in Slimstat.
* [Update] Reintroduced the `interval_minutes` filter, which had been temporarily removed from our code as a side effect of our code clean-up process. Thank you, [mth75](https://wordpress.org/support/topic/wrong-currently-online-value-shortcode/).
* [Update] Moved the button to reset the report layouts to the Customizer screen.
* [Update] Deprecated the Geolocation screen. The World Map report has been moved to the Audience tab. If for some reason you cannot find the World Map, please go to Slimstat > Customize and click the Reset All button.
* [Fix] Filters were not being set when opening the corresponding links in a new window. Thank you, [forumaad](https://wordpress.org/support/topic/bug-empty-filter-line-then-open-at-new-windows/)
* [Fix] Bug affecting the report "Currently Online".
* [Fix] Bug affecting all the filter links after the Export to Excel add-on had been enabled.
* [Fix] Bug affecting the resource filter when "nice permalinks" are not enabled.

= 4.7.7 =
* [New] We've completely rewritten the portion of code that handles the date ranges in the Filter Bar. In order to simplify things, **we have deprecated** the `interval_direction` filter, which is now expressed by the sign in front of the interval value (positive for going forward from a given start date, and negative for going back in time). Please note that this change affect your existing shortcodes, if they use the aforementioned filter. We will update our documentation in the next few days to remove any reference to this filter, and to avoid any confusion. Please feel free to contact us if you have any questions or to report any issues.
* [New] The comparison chart is now always displayed, using new criteria to determine the range to use. You may want to update your settings (Settings > Reports > Default Time Span > Days, and Reports > Comparison Chart) to mimic the old behavior or hide the comparison chart altogether, if you like.
* [Update] We've reintroduced the various levels of granularity for our charts: hourly (when a single day is selected), daily (for ranges up to 120 days) and monthly. Also, the comparison chart is now always available, regardless of the selected time range. Thank you, [WebsiteOpzetten](https://wordpress.org/support/topic/char-is-not-displayed-when-the-selected-time-range-is-comprised-of-a-single-day/).
* [Update] Tooltips across the interface have a more uniform behavior.