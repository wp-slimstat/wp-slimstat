=== WP Slimstat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Requires at least: 3.8
Tested up to: 4.3
Stable tag: 4.1.6.2

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
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 4 Mb of free PHP memory for the tracker (peak memory usage)
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

Please note: if you decide to uninstall Slimstat, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

== Frequently Asked Questions ==

Our knowledge base is available on our [support center](http://docs.wp-slimstat.com/) website.

== Screenshots ==

1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts 
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==

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

= 4.1.4.1 =
* [Fix] A bug was preventing our Export to Excel add-on from working as expected under certain circumstances (mainly WPMU-related).

= 4.1.4 =
* [Note] If you have a custom report that is still using the Custom Reports tab, please note that this approach is about to be deprecated. We are working on the documentation to explain how to use the new method.
* [Note] Our partners over at [Browscap.org](http://browscap.org/) just released a new version of their database. We're working on compiling our optimized version for it, to be included soon in our package.
* [New] You can now hide your active Slimstat add-ons, so that the list of plugins in your WordPress dashboard is not so crowded. Go to Settings > General > WordPress integration and activate the corresponding option.
* [Update] Outbound links in the Real-Time Log were restored, along with screen resolutions and a first pass of code optimizations for this crucial report.
* [Update] Added some new OS Families to the tracker (certain flavors of Blackberry and Linux, plus Fire OS).
* [Update] We are updating all our premium add-ons to use the same [update checker library](https://github.com/YahnisElsts/plugin-update-checker) (now moved to Slimstat as a shared resource). This allows third party developers to leverage the same library in case they want to offer a similar functionality for automatic updates.
* [Fix] A residue column from the old database structure was preventing the archive script from working as expected.
* [Fix] Bottom pagination for the Real-Time Log has been restored.
* [Fix] The Screen Options toggle was not working as expected for certain hidden reports.
* [Fix] Implemented code optimizations that allowed us to remove some unnecessary SQL queries. This will further improve your reports' performance.
* [Fix] We've updated our code to not drop the old ip/other_ip columns anymore, in case something goes wrong during the update.
* [Fix] Top Language Families and OS Families were showing duplicates and wrong percentages.
* [Fix] Top Categories report was throwing a warning message under certain circumstances.

= 4.1.3.2 =
* [Fix] A bug was preventing the tracker from correctly determining the country code of a given IP address

= 4.1.3.1 =
* [update] Our shortcode now supports an offset for the counters, that allows you to indicate any previous visits not tracked by Slimstat.
* [Update] Session cookie now considers multiple users logged into WordPress using the same browser within the session limit (very rare situation, but apparently not impossible).
* [Fix] A fatal error message was being displayed if a tag or a category did not exist anymore in WordPress, and Slimstat would attempt to calculate its permalink.

= 4.1.3 =
* [Note] We've updated the following add-ons to be compatible with Slimstat 4.x: Custom DB, Heatmap, Track Cookies. Go get your copy today.
* [Update] Say hello to IPv6 internet addresses. Slimstat is now compatible with this technology, and will not throw an error when users visit the site using an IPv6 address. Please note: the column type in the database has changed from INT UNSIGNED to VARCHAR(39). Update your custom code accordingly.
* [Update] Category names are now displayed instead of their URLs, when the corresponding option is enabled (thank you, Keith Gengler).
* [Update] Now the database API can be given a table alias for when JOINS are necessary, to avoid MySQL errors or ambiguous column names.
* [Fix] Map overlay was being displayed not in full width, because of some class name changes we implemented in Slimstat 4.
* [Fix] Tracking of internal link coordinates (heatmap) was not accurate if the link tag had markup inside it (thank you, [dragon013](https://wordpress.org/support/topic/for-links-a-doublelink-is-needed)).

= 4.1.2 =
* [Note] A few weeks ago we started hitting the limits imposed on our site by our existing hosting provider. It was clearly time to find a new home for Slimstat. We've been working on migrating our web platform (website and add-on repository API) to a new more powerful server, and a new domain: [wp-slimstat.com](http://www.wp-slimstat.com).
* [Note] Our dev team has released updates for our premium add-ons Export to Excel, Email Reports and User Overview, which are now fully compatible with Slimstat 4. Go get your copy today.
* [Update] More adjustments to streamline the report API and make it easier for our users to add new custom reports.
* [Update] Restored the following reports: Recent/Top Downloads, Recent/Top Outbound Links, Top Entry Pages, Top Exit Pages.
* [Fix] Bug preventing some outbound links to be properly tracked.
* [Fix] In a Windows Server environment, the way the checkboxes are added under Screen Options was creating some issues (thank you, Rafael Ortman).
* [Fix] Post and page titles were not being displayed when the corresponding option was set

= 4.1.1 =
* [Note] We are starting to hit the limits imposed by our current hosting provider, so it's time to move to a new more reliable server farm. We will be migrating our platform in the next few weeks, and this might cause some downtime for those trying to buy our premium add-ons. We apologize for the inconvenience.
* [Update] Minor adjustments to our codebase to make Slimstat easily extensible. Now you can quickly add your own reports by just passing some simple information to the plugin. More information soon available on our knowledge base website.
* [Update] Our premium add-on Export to Excel has been updated to be compatible with Slimstat 4.0. 
* [Fix] We took care of some other tenacious warnings displayed with DEBUG_MODE enabled.

= 4.1 =
* [Note] We'd like to hear from you: have you noticed any performance improvements after switching to Slimstat 4.0? Let us know through the forum or contant our support team.
* [New] Our dev team is moving forward with their effort to give Slimstat's source code a good scrub. After cleaning up the database library, it was now the report library's turn. Again, if you developed your own custom report, you will probably need to update your code to make it work with our new library. We are going to update our online documentation in the next few days.
* [New] The DB library function wp_slimstat_db::get_popular has been renamed wp_slimstat_db::get_top for consistency with the rest our codebase. Please update your code accordingly.
* [New] Say hello to your new shortcodes. We merged the ShortCodes add-on directly into our main plugin. This improves the overall performance and streamlines our software lifecycle. Please note: the shortcode syntax has been updated and simplified (you need to replace 'popular' with 'top'). All the information is being added to our [knowledge base](http://docs.wp-slimstat.com/) for your convenience. Stay tuned or contact our support team for more information.
* [Update] [AmMap](http://www.amcharts.com/javascript-maps/) has been updated to version 3.14.2. Please consider supporting this project by [purchasing a license](http://www.amcharts.com/online-store/).
* [Fix] We took care of various warnings displayed with DEBUG_MODE enabled.
* [Fix] The data import script failed to do its job in some multisite environments (thank you, pepe).
* [Fix] In Client Mode (aka Javascript mode), all page content types were being set to 'admin', under certain circumstances.
* [Fix] THe uninstall script was not removing the 'old' tables (wp_slim_stats_3, wp_slim_stats_archive_3).

== Special Thanks To ==

* [Vitaly](http://www.visbiz.org/), who volunteers quite a lot of time for QA, testing, and for his Russian localization.
* Davide Tomasello, who provided great feedback and plenty of ideas to take this plugin to the next level.

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

== Tools of the trade
[Duri.Me](http://duri.me/),
[Filezilla](https://filezilla-project.org/),
[Fontello](http://fontello.com/),
[Gimp](http://www.gimp.org/),
[Google Chrome](https://www.google.com/intl/en/chrome/browser/),
[poEdit](http://www.poedit.net/),
[Notepad++](http://notepad-plus-plus.org/),
[Tortoise SVN](http://tortoisesvn.net/),
[WAMP Server](http://www.wampserver.com/en/)