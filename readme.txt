=== WP Slimstat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Text Domain: wp-slimstat
Requires at least: 3.8
Tested up to: 4.4
Stable tag: 4.2.2

== Description ==
[youtube https://www.youtube.com/watch?v=iJCtjxArq4U]

= Key Features =
* Real-time activity log, server latency, heatmaps, email reports, export data to Excel, full IPv6 support, and much more
* Compatible with W3 Total Cache, WP SuperCache and most caching plugins
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman). Is your language missing or incomplete? [Contact Us](http://support.wp-slimstat.com/) if you would like to share your localization.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about Slimstat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like Slimstat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own!

= Requirements =
* WordPress 3.8+
* PHP 5.3+
* MySQL 5.0.3+
* At least 35 MB of free web space
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

= 4.1.7 =
* [New] Added new column dt_out to our table structure, to capture when a visitor leaves the page. This allows us to measure things like time on page and time on site. Please consider purchasing our [Heartbeat](http://www.wp-slimstat.com/downloads/heartbeat/) add-on to increase this metric's accuracy.
* [Update] New icon added to our custom font package, and removed unneded font files for faster loading.
* [Update] [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) version 2.2 is now part of the package.
* [Fix] Bug preventing the stylesheet from loading on the Settings pages, under certain circumstances.

= 4.1.6.3 =
* [New] Polish localization added. Thank you, [DFactory Team](https://www.dfactory.eu/).
* [Fix] Bug affecting the admin bar: permissions to display the link to just administrators were not being honored. (thank you, Nils)

= 4.1.6.2 =
* [Note] Yep, our team is active even in August, while sunbathing somewhere on the US East Coast.
* [Update] Now all the 'Recent' reports leverage the new optimized SQL code, not just Activity Log.
* [Fix] Replaced function get_the_title with the_title_attribute (thank you, [Pepe](https://wordpress.org/support/topic/html-code-in-reports-post-titles))
* [Fix] The GNU License notice was not hiding upon acceptance of the terms and conditions.

= 4.1.6.1 =
* [New] Contextual counters are now added not just to pages and posts, but to other custom post types available on your website.
* [Update] Optimized SQL query that retrieves the data for the Access Log report.
* [Update] New link for GetSocial.io partnership.
* [Fix] Patched a remote XSS vulnerability related to forged referrer URLs.
* [Fix] Bug in refreshing Access Log (second try).
* [Fix] Bug in calculating Unique IP counters for pages and posts.
* [Fix] Link to install the GeoLocation DB was pointing to the wrong tab under Settings.
* [Fix] When selecting the filter in Overview > Top Pages, reports were returning empty datasets.
* [Fix] Resetting the report layout was not always working as expected, if Slimstat was displayed in the admin bar.

= 4.1.6 =
* [New] Administrators can now set the maximum number of records that should be retrieved from the database when generating the reports (Settings > Reports). This allows those with powerful servers and unlimited PHP resources to increase this limit and get a more accurate picture of their visitors.
* [New] Extended the export functionality (via our premium Export to Excel add-on) to reports like At a Glance, Rankings, Audience Overview, etc (thank you, Tiffany).
* [Fix] Undefined variable 'temp' in wp-slimstat-admin.php (thank you, [candidhams](https://wordpress.org/support/topic/undefined-variable-temp-in-wp-slimstat-adminphp)).
* [Fix] Referrers and other information were not being displayed when the Access Log report was refreshed through the admin button (thank you, [Diggories](https://wordpress.org/support/topic/losing-referrers-on-refresh)).
* [Fix] Warning message in Top Entry Pages and Top Exit Pages (thank you, Romain).
* [Fix] The link in the admin bar, when the corresponding option was enabled, was interfering with some admin bar plugins (thank you, Christian)

= 4.1.5.2 =
* [Note] We are still getting support requests from users having issues with Slimstat because of the GeoLite add-on that was distributed a few months ago. If you are still using this separate add-on, we'd like to remind you that Slimstat 4 introduced a new more intuitive way of managing the MaxMind Geolocation database bundled with our software. Actually, the free Geolite plugin is not compatible with the latest version of Slimstat, because of the IPv6 support we introduced a few weeks ago. We recommend that you uninstall the add-on from your systems, thus improving the overall performance of your website. As usual, do not hesitate to contact us if you have any questions.
* [Update] Restored Activity Log report in the WordPress Dashboard.
* [Fix] The Add-ons tab under settings was visible even if no add-ons were installed (thank you, [greg57 and others](https://wordpress.org/support/topic/add-ons-tab-blank)).
* [Fix] Typo in our German localization (thank you, Marc-Oliver).
* [Fix] Adding users to the corresponding blacklist was not working if the table wp_users had certain collations (thank you, Romain Petges).
* [Fix] Non-standard quotes and other characters (hyphens, etc) were getting munged because of a security feature being overzealous (thank you, Victor).
* [Fix] SQL Debug Mode is now correctly displayed in the WP Dashboard reports.
* [Fix] More PHP warnings (debug mode) removed.
* [Fix] Added missing localization strings for certain operating systems (thank you, Romain Petges).

= 4.1.5.1 =
* [Update] Our Export to Excel add-on now includes the post slug, when appropriate.
* [Fix] Removed a few warnings displayed when DEBUG MODE was enabled.
* [Fix] A warning was being displayed when exporting certain reports.

= 4.1.5 =
* [New] Welcome our new partner GetSocial.io, a service that allows you to find your true influencers and understand which users are driving your traffic and conversions through their shares. Our users get free access to their platform through a new report located in the Site Analysis screen.
* [New] Slimstat can now differentiate between viewport size and screen size (or resolution). Two new reports, hidden by default, will enumerate the most popular of both categories.
* [Update] We implemented a more flexible way to change the number of results returned by the database API (via filter). Documentation to follow soon.
* [Update] Reports for Top and Recent Events are back. Go say hi, they will be waiting for you under Slimstat > Site Analysis. If you don't see them, you will need to activate them inside the Screen Options tab.
* [Update] After the report and data API overhaul, it was now the config panels' turn. We revisited the way they are managed, and consolidated how third party add-ons can add their own parameters. All of our add-ons affected by this change have been updated on our repository.
* [Update] Third party plugins and add-ons have now an easier way to increase the limit on the number of results returned by the data API.
* [Update] To avoid confusion when a date filter is set, the Real-Time Log has been renamed Access Log.
* [Update] Code optimizations in the report library/API: function get_raw_results is about to be deprecated. Please contact us if you're using it and would like to know how to proceed from now on.
* [Fix] Setting the 'is empty' filter would cause a WordPress warning regarding wpdb->prepare.
* [Fix] The setting to ignore visitors by username was not being saved as expected (thank you, Romain).
* [Fix] Custom CSS styles were not being properly enqueued (thank you, Romain).
* [Fix] Quotes in post titles were being escaped twice (better safe than sorry, right? thank you, Victor).
* [Fix] Bug affecting the Export to Excel add-on.

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

* [Vitaly](http://www.visbiz.org/), who volunteers quite a lot of time for QA, testing, and for his Russian localization.
* Davide Tomasello, who provided great feedback and plenty of ideas to take this plugin to the next level.