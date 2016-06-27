=== WP Slimstat Analytics ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Text Domain: wp-slimstat
Requires at least: 3.8
Tested up to: 4.6
Stable tag: 4.3.4

== Description ==
[youtube https://www.youtube.com/watch?v=iJCtjxArq4U]

= Key Features =
* Real-time activity log, server latency, heatmaps, email reports, export data to Excel, full IPv6 support, and much more
* Compatible with W3 Total Cache, WP SuperCache and most caching plugins
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Belarusian ([UStarCash](https://www.ustarcash.com/)), Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman) and Turkish (Seyit Mehmet Çoban). Is your language missing or incomplete? [Contact us](http://support.wp-slimstat.com/) today.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about Slimstat Analytics? =
* This is by far the most accurate and in-depth tracking plugin I've encountered for WordPress [MiMango](https://wordpress.org/support/topic/excellent-plugin-and-service-9)
* I have been relying on SlimStat to not only track all traffic to my sites accurately but also to present the stats in very useful graphic format [JJD3](https://wordpress.org/support/topic/an-essential-plugin-14)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like Slimstat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own!

= Requirements =
* WordPress 3.8+
* PHP 5.3+
* MySQL 5.0.3+
* At least 15 MB of free web space
* At least 5 MB of free DB space
* At least 30 Mb of free PHP memory for the tracker (peak memory usage)
* IE9+ or any browser supporting HTML5, to access the reports

= Premium Add-ons =
Visit [our website](http://www.wp-slimstat.com/addons/) for a list of available extensions.

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

= 4.3.4 =
* [Update] [Browscap](http://browscap.org/) library updated to version 6015, released on June 20th, 2016.
* [Fix] WordPress had changed a global variable we use in our code, and made it 'protected' (thank you, [chrisl27](https://wordpress.org/support/topic/slimstat-uses-wpdb-dbname-instead-of-wpdb-prefix))
* [Fix] Some fields in the settings could not be reset to an empty value, if a non-empty value had been set (thank you, Christian)
* [Fix] Switching the menu position in the settings from Sidebar to Admin Bar was returning a permission error (thank you, [janiesc](https://wordpress.org/support/topic/cant-move-menu-position-to-admin-bar))
* [Fix] Download links with query string appended at the end of their URL were not being tracked as expected (thank you, [willfretwell](https://wordpress.org/support/topic/tracking-downloads-3)).

= 4.3.3 =
* [New] The tracker can now record more than one outbound link per pageview. The corresponding reports have been updated to keep the new column structure into consideration when calculating the values. (thank you, [john](https://wordpress.org/support/topic/external-link-tracking-replace-with-another-link))
* [Update] The default method for determining the browser from the user agent string will now be our proprietary heuristic function, not browscap anymore. If needed, you can change this under Settings > Tracker tab.
* [Fix] A PHP warning was being returned after tracking a click event on an internal download (thank you, [Stephen S](https://wordpress.org/support/topic/php-notice-undefined-var-data_js)).
* [Fix] The new version of Browscap bundled with Slimstat 4.3.2 was causing quite a few 500 Server Error messages for our users. The nature of the issue remains unclear, however we decided to roll back to the previous version of the data file, which was working without any problems.
* [Fix] Adding a trailing comma to some of the text settings could trigger unexpected behaviors in the tracker. (thank you, [paronomasiaster](https://wordpress.org/support/topic/slimstat-not-recording-hits))

= 4.3.2.3 =
* [Note] Thanks to our user Boris, we were able to clarify some license issues with our partner IP2Location. We look forward to extending the functionality implemented by our IP2Location add-on to offer a better user experience, especially when exporting the data.
* [Fix] When checking for spammers, if our Custom DB add-on was enabled, the plugin was generating a SQL error (thank you, [SGURYGF](https://wordpress.org/support/topic/custom-db-issue))

= 4.3.2.2 =
* [Note] Just a quick note to let you know that we will be focusing on a major project in the next few weeks, so both development and customer support might be less responsive than usual. Please be patient, and refrain from submitting the same request more than once. Thank you!
* [New] Slimstat now speaks Belarusian thanks to Natasha from [UStarCash](https://www.ustarcash.com/).
* [Fix] Bug in Javascript tracker with Async mode enabled was duplicating entries in the Access Log.
* [Fix] Variable type mismatch was preventing scheduled posts from publishing (thank you, [Salpertriere](https://wordpress.org/support/topic/latest-version-stops-scheduled-posts-from-publishing?replies=5#post-8365376))
* [Fix] Some Javascript strings (used to generate the charts) where not correctly encoded and were breaking the source code in certain localizations.

= 4.3.2.1 =
* [Fix] Some static text ( var_ ) sneaked into the source code. Apologies for the inconvenience.

= 4.3.2 =
* [Note] We are working on a new add-on, Slimstat Sentinel, which will alert you if suspicious activity is detected on your website. Stay tuned.
* [New] Added support for "current post/page" to shortcodes. Now you won't need to write any PHP code to show, for example, the number of pageviews for a given page. Just use the following shortcode: `[slimstat f='count' w='id']content_id equals current[/slimstat]`. You can find more information on our [knowledge base](http://docs.wp-slimstat.com)
* [Update] [Browscap](http://browscap.org/) library updated to version 6014, released on April 21th, 2016.
* [Fix] When the Network Settings add-on was activated and subsequently deactivated, some of the original options would be lost.
* [Fix] Text areas in the settings were not becoming read-only if the corresponding option was set "network wide" via the Network Settings add-on.

= 4.3.1.2 =
* [Update] Activity log entries are now grouped both by IP and by username.
* [Fix] A PHP Warning was being returned by the new Rankings report.

= 4.3.1.1 =
* [Update] [Browscap](http://browscap.org/) library updated to version 6013, released on March 15th, 2016.
* [Fix] Values in textarea setting fields were not being saved.

= 4.3.1 =
* [Note] A few users have pointed out issues upgrading from versions prior to 4.0, which introduced a new table structure (see changelog). About eight months after we released version 4.0, we removed the upgrade script to streamline our codebase and improve performance. Given all these requests for help, we now decided to restore that code, and extend it to include extra checks and warnings, if something goes wrong. Check Settings > Maintenance > Database to see if you have a notice recommending to remove table leftovers from your database.
* [New] A warning will alert administrators if a caching plugin has been detected, so that they remember to configure Slimstat Analytics accordingly.
* [Fix] Some users were getting a 403 Forbidden when trying to access the list of add-ons from our servers.
* [Fix] A PHP Error was being returned by the new Rankings report.
* [Fix] The Top Referring Domains export was missing one column.
* [Fix] A bug in the customizer was preventing the reports from being displayed correctly, under certain circumstances.
* [Fix] PHP warning being displayed in textareas (settings) under certain circumstances (thank you, [Chris](https://wordpress.org/support/topic/in-php-debug-mode-errors-appear-in-textareas))

= 4.3 =
* [Note] To celebrate Slimstat's 10th birthday, we decided to tweak its name, to better reflect what it does. A few users have pointed out over time that it hadn't been easy for them to find our plugin in the repository. With our limited resources, we have been working on giving our work more visibility, and we are convinced that adding the word "Analytics" to the plugin's name is a step in the right direction. In a few months, we hope to reap the benefits of our efforts.
* [New] Please welcome our latest add-on [Author Overview](http://www.wp-slimstat.com/downloads/author-overview/). Now you can see how popular your blog authors are: this add-on will install a new report that tells you the number of pageviews, unique IPs and unique visits generated by the posts authored by each user in your blog. (thank you, [gh0stmichael](https://wordpress.org/support/topic/how-to-display-total-unique-views-per-author))
* [Update] [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), the third-party library we use to distribute updates for our premium add-ons, has been updated to version 3.0. In the next few days, we will release a dummy update of our most popular add-ons to give you a chance to verify that everything works as expected. If this is not the case, feel free to contact us to troubleshoot the issue.
* [Update] [AmMap](https://www.amcharts.com/javascript-maps/), the library used to render the world map, has been updated to version 3.19.3, released on February 23, 2016.
* [Update] All language files are now current. Please consider contributing your localization.
* [Update] It turns out our message regarding Keyword Swarm and other fraudulent clones of Slimstat was confusing users. We updated it to better explain what the situation is. (thank you, [Multimastery](https://wordpress.org/support/topic/keyword-swarm-1))
* [Fix] A bug in our heuristic browser detection functionality was triggering a PHP fatal error, in some cases. (thank you, [mkilian](https://wordpress.org/support/topic/typo-in-browscapuadetectorphp))
* [Fix] Updated Rankings API queries (Alexa, Facebook) and replaced Google API with Mozscape's. One of our users pointed out that the Google Backlink count returned by the Google API is not accurate. Please make sure to [get your personal identification codes](https://moz.com/community/join?redirect=/products/api/keys) to access their API.
* [Fix] Changed the language code for our Japanese localization files. Now Slimstat speaks Japanese! (thank you, [Yasui3](https://wordpress.org/support/topic/change-ja_jp-to-ja))

== Supporters ==
Slimstat Analytics is an open source project, dependent in large parts on donations. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who want to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, [a review](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) for Slimstat is still a nice way to say thank you!