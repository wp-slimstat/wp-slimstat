=== WP Slimstat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Text Domain: wp-slimstat
Requires at least: 3.8
Tested up to: 4.4.1
Stable tag: 4.2.6

== Description ==
[youtube https://www.youtube.com/watch?v=iJCtjxArq4U]

= Key Features =
* Real-time activity log, server latency, heatmaps, email reports, export data to Excel, full IPv6 support, and much more
* Compatible with W3 Total Cache, WP SuperCache and most caching plugins
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman) and Turkish (Seyit Mehmet Çoban). Is your language missing or incomplete? [Contact us](http://support.wp-slimstat.com/) today.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about Slimstat? =
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
2. Search for WP Slimstat
3. Click on **Install Now** under WP Slimstat and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. Go to Slimstat > Settings > Maintenance tab > MaxMind IP to Country section and click on "Install GeoLite DB" to detect your visitors' countries based on their IP addresses
6. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

== Please note ==
* If you decide to uninstall Slimstat, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.
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

= 4.1.8.1 =
* [Update] Renamed and reorganized some tabs under Settings to make them easier to understand.
* [Update] Added icons for Windows 10 and Microsoft Edge 12 browser (thank you, Romain Petges).
* [Update] Top Outbound Links and other reports can now be added to the WordPress dashboard (thank you, Cole).
* [Fix] One metric's description was misleading: it was supposed to be Pageviews per Visit, not Pages per Visit (thank you, Bperun).
* [Fix] Some people were having problems locating the Save button in the settings, which was also hidden when RTL was enabled.

= 4.1.8 =
* [New] The hover effect that revealed the details of a given row in many of our list reports has been flagged as not user-friendly by some users. A new approach using a floating tooltip has been implemented to address this issue (thank you, Romain Petges).
* [New] Slimstat now differentiates between known users who are currently visiting your website, and all others users currently online (two separate reports).
* [New] Customize the look and feel of your charts. Go to Settings > Reports > Miscellaneous, and follow the instructions (thank you, [Morcom](https://wordpress.org/support/topic/slimstatadmin_chart_optionscolors)).
* [Update] The WordPress Translation Team contacted us to let us know that Slimstat has been imported into [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wp-slimstat). We are adapting the source code and relocating the localization files within our folder structure, to comply with their new guidelines.
* [Update] Removed atialiasing detection feature from the tracker source code, as it was not considered reliable and it didn't really add any useful information for the administrator.
* [Update] Top Downloads report is now showing number of downloads by default, and percentages on hover (thank you, Romain Petges).
* [Update] SlimScroll jquery library has been updated to version 1.3.6.
* [Update] [Browscap](http://browscap.org/) has been updated to version 6007, released on September 28, 2015.
* [Update] [AmMap](http://www.amcharts.com/javascript-maps/) has been updated to version 3.17.1, released on September 16, 2015.
* [Fix] A bug in the SQL to calculate the top bounce pages was affecting the report itself (thank you, Pattaya_web).
* [Fix] An update to the WordPress CSS files affected the layout of our User Overview add-on (thank you, Per Soderman).
* [Fix] Do not record referer URL, if it's the site URL itself.

== Supporters ==
Slimstat is an Open Source project, dependent in large parts on donations. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who want to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, [a review](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) for Slimstat is still a nice way to say thank you!

[7times77](http://7times77.com),
[Andrea Pinti](http://andreapinti.com),
Beauzartes,
[Bluewave Blog](http://blog.bluewaveweb.co.uk),
[BoldVegan](boldvegan.com),
[Caigo](http://www.blumannaro.net),
[Christian Coppini](http://www.coppini.me),
Dave Johnson,
[David Leudolph](https://flattr.com/profile/daevu),
[Dennis Kowallek](http://www.adopt-a-plant.com),
[Damian](http://wipeoutmedia.com),
[Edward Koon](http://www.fidosysop.org),
Erik Ludvigsson,
Fabio Mascagna,
[Gabriela Lungu](http://www.cosmeticebio.org),
Gary Swarer,
Giacomo Persichini,
Hal Smith,
[Hans Schantz](http://www.aetherczar.com),
Hajrudin Mulabecirovic,
[Herman Peet](http://www.hermanpeet.nl),
John Montano,
Kitty Cooper,
[La casetta delle pesche](http://www.lacasettadellepesche.it),
Mobile Lingo Inc,
[Mobilize Mail](http://blog.mobilizemail.com),
Mora Systems,
Motionart Inc,
Neil Robinson,
[Ovidiu](http://pacura.ru/),
Rocco Ammon,
[Sahin Eksioglu](http://www.alternatifblog.com),
[Saill White](http://saillwhite.com),
[Sarah Parks](http://drawingsecretsrevealed.com),
[Sebastian Peschties](http://www.spitl.de),
[Sharon Villines](http://sociocracy.info), 
[SpenceDesign](http://spencedesign.com),
Stephane Sinclair,
[Stephen Korsman](http://blog.theotokos.co.za),
[The Parson's Rant](http://www.howardparsons.info),
Thomas Weiss,
Wayne Liebman,
Willow Ridge Press

== Special Thanks To ==

* Vitaly, who volunteers quite a lot of time for QA, testing, and for his Russian localization.
* Davide Tomasello, who provided great feedback and plenty of ideas to take this plugin to the next level.