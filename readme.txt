=== Slimstat Analytics ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, statistics, counter, tracking, reports, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress, power stats, hit
Text Domain: wp-slimstat
Requires at least: 3.8
Tested up to: 4.8.2
Stable tag: 4.7.2.1

== Description ==
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Feature Spotlight =
[youtube https://www.youtube.com/watch?v=zEKP9yC8x6g]

= Main features =
* Real-time access log, server latency, heatmaps, full IPv6 support, and much more.
* Exclude users from statistics collection based on various criteria, including; user roles, common robots, IP subnets, admin pages, country, etc.
* Export your reports to CSV or get daily emails right in your mailbox (via premium add-on).
* Compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* Support for hashing IP addresses in the database to protect your users privacy.
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org)).
* Add shortcodes to your website to display reports in widgets or directly in posts and pages.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= Premium Add-ons =
Visit [our website](http://www.wp-slimstat.com/addons/) for a list of available extensions.

= Social Media =
[Like Us](https://www.facebook.com/slimstatistics/) on Facebook and [follow us](https://twitter.com/wp_stats) on Twitter to get the latest news and updates about our plugin.

= Translations =
Slimstat is available in multiple languages: English, Belarusian (UStarCash), Chinese (沐熙工作室), Farsi, French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Indonesian ([ChameleonJohn](https://www.chameleonjohn.com/)), Italian ([Slimstat Dev Team](https://www.wp-slimstat.com)), Japanese (h_a_l_f), Portuguese, Russian (Vitaly), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman) and Turkish (Seyit Mehmet Çoban). Is your language missing or incomplete? [Contact us](http://support.wp-slimstat.com/) today.

= Reviews and Feedback =
* This is by far the most accurate and in-depth tracking plugin I've encountered for WordPress [MiMango](https://wordpress.org/support/topic/excellent-plugin-and-service-9)
* I have been relying on SlimStat to not only track all traffic to my sites accurately but also to present the stats in very useful graphic format [JJD3](https://wordpress.org/support/topic/an-essential-plugin-14)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like Slimstat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own!

= Requirements =
* WordPress 3.8+
* PHP 5.2+ (or 5.5+ if you use the Browscap data file)
* MySQL 5.0.3+
* At least 20 MB of free web space
* At least 5 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)
* IE9+ or any browser supporting HTML5, to access the reports

== Installation ==
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP Slimstat Analytics
3. Click on **Install Now** under WP Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. Go to Slimstat > Settings > Maintenance tab > MaxMind IP to Country section and click on "Install GeoLite DB" to detect your visitors' countries based on their IP addresses
6. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

== Please note ==
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.
* If you are upgrading from a version prior to 4.0, please install version 4.0 first to upgrade the database structure and download the new Geolocation data.

== Frequently Asked Questions ==
Our knowledge base is available on our [support center](http://docs.wp-slimstat.com/) website.

== Screenshots ==
1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==
= 4.7.2.1 =
* [Fix] The new table columns "location" and "city" were not being created on a fresh install (thank you, [nielsgoossens](https://wordpress.org/support/topic/no-data-anymore-2/#post-9491034))
* [Fix] Async mode was not working as expected (thank you, [keithgbcc](https://wordpress.org/support/topic/doesnt-work-1694/#post-9487448))

= 4.7.2 =
* [New] As those who have been using Slimstat for a while know, we never stop doing our good share of research and development to improve our plugin. One feature on our wishlist was to make the geolocation functionality more accurate. Specifically, users have been asking us to track not just the Country of origin, but possibly the state and city. In order to geolocate visitors, our code has been leveraging a third-party data file provided by [MaxMind.com](https://www.maxmind.com/en/home). A while ago, they launched a new data format, which improves performance and offers a way to quickly determine the city of origin. However, the new library required a higher version of PHP, and up until now we had been hesitant to adopt it, to allow more people to use our plugin, over the chance of offering this feature. Now, after spending some time combing through their code, we found a way to get the best of both worlds: by customizing their PHP library, we were able to make it work with PHP 5.3! Which means that now Slimstat is able to tell you your visitors' city of origin (and State, when applicable) right out of the box. This information is available in the Access Log report and in a new 'Top Cities' report under the Audience tab. Please note: the MaxMind data file to enable this feature is approximately 60 Mb, and for this reason <strong>this new functionality is not enabled by default</strong>. You must go to Slimstat > Settings > Tracker and turn on the corresponding option. Then go to Slimstat > Settings > Maintenance and uninstall/install the GeoLite file to download the one that contains the city data. Please feel free to contact us if you have any questions.
* [Update] Removed backward compatibility code for those updating from a version prior to 4.2. Hopefully most of our users are using a newer version that that. If you're not, please contact our support service for instructions on how to upgrade.
* [Update] The format used to save your settings in the database has been changed. You MUST update your premium add-ons as soon as possible, and get the version compatible with this new format, or you might notice unexpected behaviors. Please contact us if you experience difficulties updating your add-ons.
* [Update] Cleaned up some old CSS code affecting the reports.

= 4.7.1 =
* [Fix] The new feature introduced in version 4.6.9.1 to allow our users to customize the default time range for the reports, had introduced a regression bug. Thank you to all our users who volunteered to test the bugfix.
* [Fix] A vulnerability has been disclosed by [Pluginvulnerabilities.com](pluginvulnerabilities.com): an attacker with admin credentials could leverage the import/export mechanism for the plugin's settings to inject some malicious code. We recommend that you upgrade to the latest version of Slimstat as soon as possible.
* [Fix] The new version of the [Add-on Update Checker library](https://github.com/YahnisElsts/plugin-update-checker), bundled with the previous release, was returning a fatal error under certain circumstances (thank you, Pepe).

= 4.7 =
* [New] After receiving a few requests to implement a REST API within Slimstat, and given that WordPress now offers a mature and stable interface for extending its built-in REST API, we decided to finally move forward and implement this feature. Please refer to our [online knowledge base](https://slimstat.freshdesk.com/solution/articles/12000033661-slimstat-rest-api) to learn more about how to use it.
* [Update] The [Add-on Update Checker library](https://github.com/YahnisElsts/plugin-update-checker) has been updated to version 4.2, to solve an incompatibility issue with PHP 7.0 (thank you, [Sasa](https://wordpress.org/support/topic/possible-incompatibility-with-php-7-0/) and Per).
* [Update] Bootstrap Switch (which we use on our Settings panels) has been updated to version 3.3.4.
* [Fix] Apparently, there was an issue where our XMLHttpRequest didn't send the cookie for Cross-Origin URLs (thank you, Sasa).
* [Fix] Solved issue with pagination, triggered when certain long time ranges were being selected.
* [Fix] The autorefresh countdown timer was not working as expected if Async Mode was enabled (thank you, [scruffy1 and service4](https://wordpress.org/support/topic/odd-visitors-activity-heading)).

= 4.6.9.1 =
* [New] You can now customize the "past days" interval to be used as the default time range to calculate your metrics, which was previously fixed to 30 days.
* [Fix] The Overview chart was not working as expected for some users, because of a bug in one of the revised SQL queries.
* [Fix] The pageview count on the Edit Posts/Pages screens was also affected by the same bug mentioned here above.
* [Fix] A PHP warning of undefined variable $hidden_filters was being logged and/or displayed under certain circumstances.

= 4.6.9 =
* [New] Added operating systems to the column you can use to blacklist certain pageviews. Please refer to [this page](https://slimstat.freshdesk.com/solution/articles/12000031504-what-are-the-operating-system-codes-used-by-slimstat-) to learn more about what codes Slimstat uses to represent operating systems internally.
* [New] In the Customizer, you can now quickly move your reports to the Inactive bucket with just one simple click, instead of dragging and dropping them all the way to the top (thank you, [ac1643](https://wordpress.org/support/topic/customizing-the-reports/)).
* [Update] We partially rewrote the code that handles our Filter Bar, specifically when it comes to handling date ranges. A few minor bugs were squashed in the process, but please don't report us to PETA! (thank you, Boris)
* [Fix] The customizer was not working if the Slimstat menu was being displayed in the admin bar instead of as a separate menu. Please report any issues you experience in using the customizer.
* [Fix] Our charts are calculating the sum of all the data points, day by day. However other reports show a cumulative sum (let's say of Unique IPs) for the whole month. If a given IP visits your website on two different days, it will be counted twice in the Unique IPs chart, but only once in Traffic at Glance. In order to avoid any confusion, we are changing the chart legend to display the AVERAGE value, instead of the sum. 

= 4.6.8 =
* [Update] We should have known better: for every naysayer there is always a yaysayer that will [ask for just the opposite feature](https://wordpress.org/support/topic/recent-month-change/), right? Fair enough: that's what the settings panels are for, after all. In order to make everybody happy, we introduced a new option (under Slimstat > Settings > Reports) that will allow you to decide what the default time span should be: current month or past 30 days, it's totally up to you.
* [Update] We implemented Chris' idea to use a transient when recording the visit_id, to improve performance (thank you, [Chris](https://wordpress.org/support/topic/recommend-switching-slimstat_visit_id-to-use-a-transient/)).
* [Fix] If your website is using a caching plugin, the tracking code might still be appended to the page, even if the user turned off tracking. Slimstat now checks if Tracking is enabled in the settings, before processing any request it receives from the browser (thank you, Chris).
* [Fix] A false positive alert of attempted XSS injection was being returned when the 'android-app' scheme was being used to access the website (thank you, [Sasa](https://wordpress.org/support/topic/false-positive-tracker-error-attempted-xss-injection/)).

= 4.6.7 =
* [Update] Same-domain referrers are now tracked by default, when Slimstat is activated for the first time. Pre-existing installations are not affected. This change will allow us to introduce  new features as suggested [by one of our users](https://wordpress.org/support/topic/can-we-see-top-visitor-path/). Stay tuned.
* [Update] Every first of the month we get at least one or two support requests from alarmed users who think their data has disappeared. Some of them end up leaving [negative reviews](https://wordpress.org/support/topic/limited-service/) because of this. The fundamental misunderstanding was related to the fact that Slimstat uses the CURRENT MONTH time span as the default range for calculating its reports and metrics. So every first of the month all the reports can look "empty" because there is not much data to be crunched. In order to address this problem, we've changed the default time range from the current month to the last 20 days. Hopefully this will avoid further negative reviews in the future!
* [Fix] There was an incompatibility issue between our premium add-ons Custom DB and Network Settings, causing the network-wide configuration to be reset when a new blog was being created in the network.
* [Fix] The tracker was not working as expected if the blacklist by usernames contained a @ character (thank you, Eugenio).
* [Fix] A bug was affecting the way the geolocation function would calculate the country parameter from certain given IP addresses (thank you, Carsten).

= 4.6.6 =
* [New] Super administrators can now decide what reports users will see, and their arrangement both on the dashboard and Slimstat screens (thank you, [ac1643](https://wordpress.org/support/topic/specifying-the-settings-by-user/)). You will need our premium add-on [Network Analysis](https://www.wp-slimstat.com/downloads/network-view/) to unlock this feature.
* [Fix] The `post_link` shortcode attribute was not working as expected when downloads were being listed (thank you, [tophr](https://wordpress.org/support/topic/top-downloads-shortcodewidget-not-working-as-expected)).
* [Fix] Updated Track Client Info option description to make it less confusing (thank you, [Bjarne](https://wordpress.org/support/topic/track-client-info-setting-confusion/)).

= 4.6.5 =
* [New] The new Browscap Library we introduced a few versions ago added some new interesting fields to the list of information available for each browser. One of them allows to distinguish between touch and non-touch devices, even when they are not mobile devices (think touchscreen all-in-one desktop computers). Inspired by one of our most active beta testers, we decided to expose this information in the admin (Activity Log). Now you will be able to segment your metrics based on this new value for the browser type dimension.
* [New] Added Indonesian localization (thank you, [ChameleonJohn](https://www.chameleonjohn.com/)).
* [Update] You can now show display names instead of usernames when generating shortcodes. Check our documentation for more information (thank you, pepe).
* [Update] You can now group resources in shortcodes while ignoring any query string attached to them (thank you, [dragolcho](https://wordpress.org/support/topic/problem-with-short-code-2/)).
* [Fix] SQL error being returned for the Top/Recent Keywords report (thank you, pepe).
* [Fix] A regression bug was affecting the blacklist by username functionality (thank you, Ursula).

= 4.6.4 =
* [Update] When the tracker was set to work in "Client mode", the Javascript code was being added to those pages that matched one of the blacklists, even though the subsequent request would have been ignored. By avoiding this, we were able to optimize our code and improve the overall performance.
* [Fix] A conflict with a Google Maps Javascript API call was causing other scripts to not work as expected (thank you, Fulp2121).
* [Fix] A bug was preventing the tracker from detecting certain mobile devices as expected in "Client Mode".
* [Fix] Empty search terms were being counted in the Top/Recent Search Terms reports (thank you, Per).

= 4.6.3 =
* [Note] We would like to thank all the people who stepped forward to offer their help and test this new version before it was officially released. We worked with our users to identify the many different scenarios related to lightbox libraries, jQuery animations and so forth. It was a great team effort! You guys are terrific!
* [Update] Say goodbye to incompatibility issues with lightbox libraries, jQuery drop down menus, fancy animations and the like. We worked on the tracking algorithm to make it less intrusive, and to FINALLY play nice with any other event handlers attached to your DOM elements. As an added bonus, the new tracker is performing from 10 to 30 percent faster in our tests. Not bad, huh?
* [Update] If you are using the CDN service offered by our partners at jsDelivr, the tracker will now reference your current version of Slimstat, not the "trunk". This will avoid issues in the future to those who don't want to upgrade to the latest version right away (thank you, [mth75](https://wordpress.org/support/topic/new-version-56/#post-8896049)).
* [Update] The tracker is no longer looking for Shockwave Director or Real Player when detecting browser plugins (is anyone still using them?). On the other side, it is now detecting the Java Virtual Machine and any PDF viewer (either Adobe plugin or built into the browser).
* [Update] One of our users thought that all the inline data-slimstat attributes appended by our tracker to all his links looked ugly and might affect performance (no, they do not). That struck a cord in our perfectionist developer, and he decided to rewrite that functionality to minimize the intrusiveness of our algorithm.
* [Fix] Apparently the XSS vulnerability discovered by the Mitre Corporation had not been completely fixed in version 4.6, according to them. Now they confirmed that the issue has been resolved.

= 4.6.2 =
* [Update] Removed options to enable or disable tracking of "internal" and outbound links, as they were confusing many users, based on the feedback we received. Now all links are tracked, regardless of their type. This will increase the size of the "events" table, however it will also make your reports more accurate, and track data needed to generate heatmaps and other metrics.
* [Fix] The Activity Log will now group page views not just by session, but also by other events: change of IP, user logged out, etc (thank you, [catmaniax](https://wordpress.org/support/topic/cant-tell-which-user-is-which/)).
* [Fix] The Browscap Library could not be installed if the FS_METHOD constant was not set to 'direct' in wp-config.php (thank you [computershowtopro](https://wordpress.org/support/topic/browscap-dp-php-is-present-but-not-seen-by-slimstat)).
* [Fix] A PHP notice was being displayed if the widget_id for the new Slimstat Widget element was not set.
* [Fix] In order to calculate its internal timestamps without any conflicts with other plugins, Slimstat was supposed to temporarily deactivate any WordPress filters on the function `date_i18n`. It turns out something had changed in the way WordPress was structuring that information, with the side effect that Slimstat was not able to restore those filters (thank you, [catmaniax](https://wordpress.org/support/topic/slimstat-month-name-issue)).

= 4.6.1 =
* [New] You spoke, we listened. Many users have been asking us over time to add a feature to display metrics and reports on their front-facing website. Although Slimstat has been supporting shortcodes for many years, they felt like they needed more than that basic feature. We are now extending the shortcode syntax to allow users to place widgets on their websites in just a few steps. Please [refer to our knowledge base](https://slimstat.freshdesk.com/support/solutions/folders/5000259023) to learn more about this new feature, or feel free to contact us if you need help implementing it on your website.
* [New] Hovering a report's title will reveal its unique ID, which you can [use in your shortcode](https://slimstat.freshdesk.com/support/solutions/folders/5000259023) to display it on your website.
* [Update] The update notice displayed in the admin is now only shown to site administrators (single installation) and super administrators (WP MU / network), to avoid any confusion for MU site administrators.
* [Update] Improved the accessibility of our Filter Bar, by introducing (hidden) labels for all the fields. Please make sure to flush your client/server caches to load the new stylesheet.
* [Update] Removed the option to deactivate Slimscroll as it did not play nice with some other features we recently introduced, and also because the incompatibility issues between Firefox and Slimscroll have been addressed.
* [Fix] The icon to export a report as Excel comma separated value was not being visualized correctly, when the premium add-on was enabled.
* [Fix] Addressed some Javascript warning being displayed in the browser console, returned by the qTip library.
* [Fix] The height of all the Dashboard widgets (including the ones not related to Slimstat) was being affected by a typo in our CSS.
* [Fix] Cleaned up minor layout glitches and improved rendering of charts after initial round of feedback from users.

= 4.6 =
* [New] Our development team has had the task of revamping the charts available in Slimstat on their to-do list for quite a while now. Now that the compatibility issues related to our Browscap library have been addressed and resolved, it was time to tackle this new challenge and offer a beautiful new interface to analyze and interact with visual reports and charts. As an added bonus, we are also working on extending the list of supported shortcodes to allow administrators to also share these brand-new charts with their visitors, by quickly placing them on any page of their website. The same will apply to the world map, which currently displays the total number of page views by Country. Lots of exciting new features will soon be available to all our users. Stay tuned!
* [Update] Email and Excel reports now honor the setting to convert IP addresses to hostnames.
* [Update] Various cosmetic upgrades to make your reports easier to read. Please make sure to clear your browser and server caches to load the new stylesheet.
* [Fix] We patched a quite unique XSS vulnerability, responsibly disclosed by [the MITRE Corporation](https://www.mitre.org/) (thank you, guys).
* [Fix] Some more buttons and links were added to the exclusion list of things to track on the admin, when this feature is enabled.
* [Fix] Calling a function to decode an IPv6 address was failing if PHP did not support this protocol (thank you, [catmainax](https://wordpress.org/support/topic/warnings-in-debug-mode-9/))

== Support Our Work ==
Slimstat Analytics is an open source project, dependent in large parts on donations. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who want to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) for Slimstat as a token of appreciation for our hard work!
