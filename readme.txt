=== WP Slimstat Analytics ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Text Domain: wp-slimstat
Requires at least: 3.8
Tested up to: 4.4.2
Stable tag: 4.3

== Description ==
[youtube https://www.youtube.com/watch?v=iJCtjxArq4U]

= Key Features =
* Real-time activity log, server latency, heatmaps, email reports, export data to Excel, full IPv6 support, and much more
* Compatible with W3 Total Cache, WP SuperCache and most caching plugins
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman) and Turkish (Seyit Mehmet Çoban). Is your language missing or incomplete? [Contact us](http://support.wp-slimstat.com/) today.
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

= 4.2.7 =
* [Note] Our previous request for help did not fall on deaf ears. Each donation we received in the past few weeks, reminded us just how important is our work for our community, and how much you all appreciate it. To show how grateful we are, we decided to give back to our community twice as much. Yes, you read that right. In the next few days, we will be sending our donors coupons that can be used on our store to get a discount equal to double the amount they donated. If you are a donor and don't get an email from us in a few days, feel free to contact us so that we can look into it. This promotion will only apply to donations received by midnight on February 15, 2016. Again, thank you for stepping up and being such a great community of supporters. It means a lot to us.
* [New] Option to configure the tracker to work in asynchronous mode. (see [this thread](https://wordpress.org/support/topic/slimstat-bbpress-need-to-double-click-links-ff-or-wait-chrome-to-load?replies=8#post-8031657) for more information)
* [Update] [Browscap]() library updated to version 6012, released on February 4th, 2016.
* [Fix] The tracker was erroneously returning "invalid checksum" even when other errors were occurring, or filters being applied to a pageview.
* [Fix] The javascript tracker was not compressed.
* [Fix] Top Pages Not Found report had been removed by mistake. (thank you, Bperun)

= 4.2.6 =
* [New] Reintroduced our beloved Async Mode (Slimstat > Settings > Reports > Functionality). Activate this feature if your reports take a while to load. It breaks down the load on your server into multiple requests, thus avoiding memory issues and performance problems.
* [New] Turkish localization added. (thank you, Seyit Mehmet Çoban)
* [Update] To avoid confusion, we updated the option from 'Delete Records' to 'Archive Records'. Please go make sure it is set according to your needs. (thank you, Steve)
* [Update] Our premium add-on Network View, now renamed Network Analysis, is now compatible with Slimstat 4.x. [Go grab your copy today](http://www.wp-slimstat.com/downloads/network-view/)
* [Fix] Some charts were not displaying accurate metrics under certain circumstances (filters).
* [Fix] A PHP warning was being displayed if WP_DEBUG was set to true. (thank you, [Salpetriere](https://wordpress.org/support/topic/another-php-error-with-plugin))
* [Fix] A fatal error message was being displayed on the login screen if the data structure was not up-to-date or corrupted. (thank you, Chuck)

= 4.2.5 =
* [New] A filter to customize the list of report screens/pages: slimstat_screens_info. Please contact us for more information.
* [Update] After we introduced the new Customizer function, one of our most active users noted that pages would not disappear from the side navigation if no reports were associated to them. We update the functionality to include this new feature.
* [Update] Detecting search engines and searchterms has become a real challenge in the last few years (thank you, NSA!). We updated our algorithm to detect if a referer is a search engine or not. (thank you, HubieDoobieDoo)
* [Update] Instead of creating a copy of the MaxMind database for each site in a network, now the plugin uses a shared one stored in the main "uploads" folder.
* [Update] How many people read this changelog? We want to conduct a little experiment to find out: use code CHANGELOG to get $25 off any new order placed after January 18, 2016 on [our store](http://www.wp-slimstat.com/addons/). One use per account, only available to the first 20 users who will place an order.
* [Update] Unfortunately our partner GetSocial.io has decided to terminate their partnership with us. The corresponding report has been removed from the admin.
* [Fix] The tracker was not working as expected in Internet Explorer 7, returning the error message "Object doesn't support this property or method: trim" (thank you, Nick).
* [Fix] Traffic Sources report was not grouping records as expected (thank you, [HubieDoobieDoo](https://wordpress.org/support/topic/search-engine-detection-googlefr-1))

= 4.2.4 =
* [New] We rewrote the heuristic algorithm that decodes the user agent string. Also, we introduced a new option (under Settings > Tracker) to allow you to choose the detection logic to be used first: the heuristic function is much faster and requires very little memory, but it might be less accurate, and not produce the right match; browscap.ini, the third party database we use, is memory intensive and it uses a bruteforce approach to determine a visitor's browser, but it's very accurate and precise even with the most obscure user agent strings (almost all of them). You decide which one works best for you.
* [New] You can now reset the tracker status in order to better troubleshoot issues with the plugin (thank you, Per).
* [Update] We now include the smaller version of the Browscap database, which covers the 50,000 most common user agent strings, instead of the full version which covers about 130,000 strings. Please contact us if your project requires the high level of accuracy offered by the latter.
* [Update] Swedish localization updated and 100% complete (thank you, Per).
* [Update] Some web accelerators (Cloudflare and others) use the custom header HTTP_X_REAL_IP to keep track of a visitor's originating IP. Our code is now inspecting this header (thank you, Saeid).
* [Update] Added many new flavors of Linux to the feature in charge of detecting the user's operating system.
* [Update] Moved plugin screenshots to 'assets' folder in the repository, so that they are not downloaded with the zip file anymore.
* [Fix] Other plugins might affect the format of the value returned by date_i18n (by introducing, for example, Persian numbers). This was preventing Slimstat from being able to calculate dates and time frames (thank you, Saeid).
* [Fix] The new Javascript tracker was not working as expected in Internet Explorer 10 and 11 (thank you, Arne).

= 4.2.3 =
* [Note] The Javascript Tracker has been partially rewritten to store each link's state as inline "data" attributes. This had already been implemented a while ago, however the tracker was not using those values to decide what to do; it was using instead Javascript variables. Now the code has been consolidated, also allowing third party tools to affect the behavior of the tracker at runtime, by modifying the values associated to the data attributes. Please feel free to contact us if you want to know more about this new feature.
* [Note] As the tracker engine has been changed, please make sure to flush your server caches to take advantage of all the new features and optimizations.
* [Update] The Activity Log report displays when users login and logout (this feature requires our premium add-on [User Overview](http://www.wp-slimstat.com/downloads/user-overview/)). You can also create filters on this event using the 'Annotations' filter in the dropdown. Contact our support team for more information.
* [Update] Minor CSS improvements to adapt the layout to WordPress 4.4.
* [Update] Tracker Error Codes are now called Tracker Statuses, to avoid confusion between when the plugin is not working and when it's actually doing its job.
* [Update] Javascript tracker is now capable of tracking custom link types.
* [Fix] Custom filters (used in some of our premium add-on) where affecting the way charts were being displayed.
* [Fix] Resolved an encoding issue, introduced by our XSS patch in version 4.2.2 (thank you, [Paper Bird](https://wordpress.org/support/topic/special-characters-in-page-titles-displaying-as-iso-code))
* [Fix] Shorcodes were not working as expected if the value passed to the filter was zero (thanky you, [Sheriffonline](https://wordpress.org/support/topic/monthly-visitors-showing-0-always)).
* [Fix] Shortcode for countries will now return Country names, not codes (thank you, [Sheriffonline](https://wordpress.org/support/topic/monthly-visitors-showing-0-always)).
* [Fix] Time ranges within the same day (i.e. visitors in the last 5 minutes) were not being calculated correctly.

= 4.2.2 =
* [Note] The WordPress Translation Team contacted us to let us know that Slimstat has been imported into [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wp-slimstat). We adapted the source code and moved the localization files within our folder structure, to comply with their new guidelines. It looks like it will now be much easier for our users to contribute, and help Slimstat speak many new languages.
* [New] You can now filter existing users in groups, using wildcards.
* [New] Our premium add-on User Overview now extends the tracker to record login/logout events. This feature can be useful if you have a membership site and want to know when users actually log in.
* [Update] Time on page now displays hours, if the duration is long enough (thank you, Ralf)
* [Fix] Patched a javascript vulnerability that might be exploited via the Activity Log report (thank you, Ivan)
* [Fix] Session tracking cookie does not differentiate between logged in and anonymous users anymore.
* [Fix] Tracker was not working as expected on the WordPress login screen and when admin-ajax.php was being called by other scripts.
* [Fix] Blacklist by username was incorrectly considering substrings.
* [Fix] When running reports on date intervals, start and end of calculated timeframe were skewed by one day under certain circumstances.
* [Fix] Link on Edit Posts/Pages counter was not working as expected (thank you, [SGURYGF](https://wordpress.org/support/topic/pagesposts-view-count-0)).
* [Fix] Miscellaneous code optimizations and clean-up.

= 4.2.1 =
* [Note] If you're upgrading from a version prior to 4.0, please upgrade to version 4.0 first. To simplify our codebase, we removed all the upgrade scripts to support versions prior to 4.0.
* [New] Say hi to your new charts: search terms (total and unique per day or even hour), outbound links, users. If you can't see the new charts, don't forget to give the No Panic button a try.
* [Update] We are dropping old unused columns from the main table: ip_temp, other_ip_temp, ip_num, other_ip_num. Please make sure you have a backup, in case you need those columns for other custom purposes.
* [Fix] Some users were seeing "ghost reports" in their admin screens. A residue from Halloween, we assume.
* [Fix] Our "loading" animated icon was not being displayed correctly on refresh.
* [Fix] Chart legend was not being displayed as expected.

= 4.2.0.1 =
* [Fix] The Access Log report was not displaying referrers and other critical information.

= 4.2 =
* [Note] You now have full control over the placement of your reports. Move them not just within each screen, but from one screen to another. Build your own custom Overview, by simply dragging and dropping report labels just like you already do with widgets and widget areas. Compare multiple charts in one screen, and much more. Go to Slimstat > Customize and... have fun!
* [Note] If for any reasons your reports are not being displayed correctly, make sure to give the No Panic button a try (under Settings > Maintenance)
* [Note] Did you say charts? We are adding new visual reports to Slimstat, to make your metrics easier to interpret. Stay tuned!
* [Update] Support for the old "Custom Report" screen (already deprecated in version 4.0) has been removed from the source code. Please update your custom reports accordingly.
* [Update] Renamed and reorganized tabs under Settings to make them easier to understand.
* [Update] [Flot](http://www.flotcharts.org/) chart library updated to version 0.8.3

== Supporters ==
Slimstat Analytics is an open source project, dependent in large parts on donations. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who want to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, [a review](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) for Slimstat is still a nice way to say thank you!