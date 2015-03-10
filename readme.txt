=== WP Slimstat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Requires at least: 3.8
Tested up to: 4.2
Stable tag: 3.9.8

== Description ==
[youtube https://www.youtube.com/watch?v=iJCtjxArq4U]

= Key Features =
* Real-time activity log, server latency, heatmaps, email reports, export data to Excel, and much more
* Compatible with W3 Total Cache, WP SuperCache and most caching plugins
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman). Is your language missing or incomplete? [Contact Us](http://support.getused.to.it/) if you would like to share your localization.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= Track countries by IP Address =
Starting from Slimstat 3.9.8, we removed the functionality that allows the tracker to identify a visitor's country based on his IP address.
The team who manages the WordPress Plugin Repository notified us that since the [MaxMind GeoLite library](http://dev.maxmind.com/geoip/legacy/geolite/)
used by our plugin to geolocate visitors is released under the Creative Commons BY-SA 3.0 license, it violates the repository guidelines, and cannot
be bundled with the code. We were required to remove it and alter the plugin so that this functionality becomes optional. By default, your visitors' country
will be set to Unknown. You can download the geolocation DB as a [separate add-on](http://slimstat.getused.to.it/downloads/get-country/) on our website, free of charge,
to restore this feature. Don't forget to enter your license key in the corresponding field under Slimstat > Add-ons, to receive free updates!

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
Visit [our website](http://slimstat.getused.to.it/addons/) for a list of available extensions.

= Free Add-ons =
* [WP Slimstat Dashboard Widgets](http://wordpress.org/extend/plugins/wp-slimstat-dashboard-widgets) adds the most important reports right on your WordPress Dashboard
* [WP Slimstat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) allows you to share your reports with your readers

== Installation ==

0. **If you are upgrading from 2.8.4 or earlier, you MUST first install version 3.0 (deactivate/activate) and then upgrade to the latest release available**
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP Slimstat
3. Click on **Install Now** under WP Slimstat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

Please note: if you decide to uninstall Slimstat, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

== Frequently Asked Questions ==

Our knowledge base is available on our [support center](https://slimstat.freshdesk.com/support/solutions) website.

== Screenshots ==

1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts 
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==

= 3.9.8 =
* [Note] The team who manages the WordPress Plugin Repository notified us that since the [MaxMind GeoLite library](http://dev.maxmind.com/geoip/legacy/geolite/) used by Slimstat to geolocate visitors is released under the Creative Commons BY-SA 3.0 license, it violates the repository guidelines, and cannot be bundled with the plugin. We were required to remove the code and alter the plugin so that this functionality becomes optional. We apologize for the inconvenience. However, the only immediate consequence is that your visitors' country will not be identified; everything else will still work as usual. You can download the geolocation DB as a [separate add-on](http://slimstat.getused.to.it/downloads/get-country/) on our store, free of charge. Don't forget to enter your license key in the corresponding field under Slimstat > Add-ons, to receive free updates!
* [New] A few new options under Slimstat > Settings > General tab > WordPress Integration section, allow you to have more control over the information displayed in the Posts admin screen (thank you, Brad).

= 3.9.7 =
* [Note] The uninstall routine now deletes the archive table (wp_slim_stats_archive) along with all the other tables (thank you, KalleL)
* [New] Some users who are using our "track external sites" feature, were getting an error saying that no 'Access-Control-Allow-Origin' header was present on the requested resource. We've added a new option under Settings > Advanced that allows you to specify what domains to allow. Please refer to [this page](http://www.w3.org/TR/cors/#security) for more information about the security implications of allowing an external domain to submit AJAX requests to your server.
* [New] Added debugging information (most recent tracker error code) under Slimstat > Settings > Maintenance tab > Debugging. This information is useful to troubleshoot issues with the tracker. Please include it when sending a support request.
* [Fix] The option to delete pageviews based on given filters (Settings > Maintenance > Data Maintenance) was not working as expected (thank you, [kentahayashi](https://wordpress.org/support/topic/cant-delete-pageviews-on-version-396))
* [Fix] The uninstall script was not deleting all the tables as expected (thank you, [KalleL](https://wordpress.org/support/topic/unable-to-uninstall-wp-slimstat-from-db))
* [Fix] We've implemented [Marc-Alexandre's new recommendations](http://blog.sucuri.net/2015/02/security-advisory-wp-slimstat-3-9-5-and-lower.html) to further tighten up our SQL queries.
* [Fix] The new encryption key was affecting the way external sites could be tracked. You can now track non-WP sites again: please make sure to copy and paste the new tracking code (Settings > Advanced) right before your closing BODY tag at the end of your pages.

= 3.9.6 =
* [Note] The security of our users' data is our top priority, and for this reason we tightened our SQL queries and made our encryption key harder to guess. If you are using a caching plugin, please flush its cache so that the tracking code can be regenerated with the new key. Also, if you are using Slimstat to track external websites, please make sure to replace the tracking code with the new one available under Settings > Advanced. As usual, feel free to contact us if you have any questions.
* [Note] Added un-minified js tracker to the repo, for those who would like to take a look at how things work.
* [New] Introduced option to ignore bots when in Server-side mode.
* [Update] Cleaned up the Settings/Filters screen by consolidating some options.
* [Update] AmMap has been updated to version 3.13.1
* [Update] MaxMind GeoLite IP has been updated to the latest version (2015-02-04).
* [Fix] Patched a rare SQL injection vulnerability exploitable using a bruteforce attack on the secret key (used to encrypt the data between client and server).
* [Fix] Increased checks on SQL code that stores data in the database (maybe_insert_row).
* [Fix] Report filters could not be removed after being set.

= 3.9.5 =
* [Note] Some of our add-ons had a bug preventing them from properly checking for updates. Please [contact us](http://support.getused.to.it) if you need to obtain the latest version of your add-ons.
* [Update] The Save button in the settings is now always visible, so that there is no need to scroll all the way to the bottom to save your options.
* [Update] More data layer updates introduced in wp_slimstat_db. Keep an eye on your custom add-ons!
* [Fix] Pagination was not working as expected when a date range was set in the filters (thank you, [nick-v](https://wordpress.org/support/topic/paging-is-broke))

= 3.9.4 =
* [Note] The URL of the CDN has changed, and is now using the official WordPress repository as a source: cdn.jsdelivr.net/wp/wp-slimstat/trunk/wp-slimstat.js - Please update your "external" tracking codes accordingly.
* [Note] The structure of the array **wp_slimstat_db::$sql_filters** has changed! Please make sure to update your custom code accordingly. Feel free to [contact us](http://support.getused.to.it) for more information.
* [New] The wait is over. Our heatmap add-on is finally [available on our store](http://slimstat.getused.to.it/downloads/heatmap/)! We would like to thank all those who provided helpful feedback to improve this initial release!
* [New] Our [knowledge base](https://slimstat.freshdesk.com/support/solutions) has been extended with a list of all the actions and filters available in Slimstat.
* [Fix] The Add-on update checker had a bug preventing the functionality to work as expected. Please make sure to get the latest version of your premium add-ons!
* [Fix] Date intervals were not accurate because of a bug related to calculating timezones in MySQL (thank you, [Chrisssssi](https://wordpress.org/support/topic/conflicting-data)).
* [Fix] Line height of report rows has been added to avoid conflicts with other plugins tweaking this parameter in the admin (thank you, [yk11](https://wordpress.org/support/topic/widgets-bottom-is-cut-off)).

= 3.9.3 =
* [Note] We're starting to work on a completely redesigned data layer, which will require less SQL resources and offer a much needed performance improvement. Stay tuned.
* [New] Three new settings to turn off the tracker completely on specific links (internal and external), by class name, rel attribute or simply by URL.
* [Update] MaxMind GeoLite IP has been updated to the latest version (2015-01-06).
* [Fix] Bug affecting our add-ons setting page, in some specific circumstances (thank you, Erik).

= 3.9.2 =
* [New] Welcome our recommended partner, [ManageWP](https://managewp.com/?utm_source=A&utm_medium=Banner&utm_content=mwp_banner_25_300x250&utm_campaign=A&utm_mrl=2844). You will get a 10% discount on their products using our affiliation link.
* [Fix] XSS Vulnerability introduced by the new Save Filters functionality (thank you, [Ryan](https://wpvulndb.com/vulnerabilities/7744)).

= 3.9.1 =
* [New] Quickly delete single pageviews in the Real-Time Log screen
* [New] Option to fix an issue occurring when the DB server and the website are in different timezones. Please disable this option if your charts seem to be off!
* [New] Using the new [WP Proxy CDN feature](https://github.com/jsdelivr/jsdelivr/issues/2632). Please contact us if you notice any problems with this new option, as this feature is still being tested.
* [Update] Reintroduced the NO PANIC button under Settings > Maintenance > Miscellaneous
* [Fix] Conflict with WP-Jalali, which forces date_i18n to return not western numerals but their Farsi representation

= 3.9 =
* [Note] Announcing our latest add-on: heatmaps! Get your free copy of our beta: contact our support team today.
* [New] Section under Settings > Filters that allows you to specify what links you want to "leave alone", so that the tracker doesn't interfere with your lightbox treatments.
* [New] You can now turn on the option to collect mouse coordinates for internal links, which will be used to draw the heatmap on your pages.
* [New] Operator "is included in" has been added to search matches in lists of strings (see [W3resources](http://www.w3resource.com/mysql/string-functions/mysql-find_in_set-function.php), thank you [pchrisl](https://github.com/27pchrisl/wp-slimstat/commit/5a5bc3b8c21ec16445292d8674d669c37c2a08b4))
* [New] Added new reports: Top Bounce Pages, Top Exit Pages, Recent Exit Pages (thank you, [Random Dev](https://wordpress.org/support/topic/no-visitor-path-through-site-wslimstat))
* [Update] Partial overhaul of the javascript tracker. We reintroduced the new algorithm to track pageviews, which now avoids the problem of triggering the popup blocker on links opening in a new tab. 
* [Update] Added browser and operating system to Spy View report
* [Update] MaxMind GeoLite IP has been updated to the latest version (2014-12-02)
* [Fix] Bug in archiving old pageviews under certain circumstances (thank you, Thomas)
* [Fix] Added max height to overlay, for those who have very long lists of saved filters
* [Fix] The button to reset date filters was not being displayed in some cases (thank you, [RangerPretzel](https://wordpress.org/support/topic/custom-data-range-in-slimstat-produces-charts-with-days-that-have-0-pageviews))
* [Fix] Charts were not accurate when a custom interval was selected and the mysql server's timezone was different from the web server timezone (thank you, [RangerPretzel](https://wordpress.org/support/topic/custom-data-range-in-slimstat-produces-charts-with-days-that-have-0-pageviews))

= 3.8.5 =
* [Update] Show notices only to admin users (thank you, [thisismyway](https://wordpress.org/support/topic/hide-notifications-for-non-admins))
* [Fix] The javascript tracker had been changed to deal with popup blocker issues, but the new code was causing even more problems to other people. Implemented a synchronous solution to make everybody happy! (thank you, bishoph)

= 3.8.4 =
* [New] You can now archive old pageviews, instead of deleting them
* [Update] Code optimizations to the Javascript tracker (and a bugfix - thank you, [themadproducer](https://wordpress.org/support/topic/external-links-problem))
* [Fix] Fixed a corrupted browscap data file (thank you, [crzyhrse](https://wordpress.org/support/topic/clobbered-my-sites-again))
* [Fix] Do not refresh the Real-Time log if a date filter is set (thank you, [asylum119](https://wordpress.org/support/topic/viewing-yesterdays-stats-still-auto-refreshes))

= 3.8.3 =
* [Update] Browscap v5035 - November 4, 2014 (this should fix all the issues with recent Firefox versions)
* [Fix] The originating IP address was not being ignored, if it was the same as the IP address (thank you, [morcom](https://wordpress.org/support/topic/real-time-log-originating-ip-on-all-entries))
* [Fix] Visits in map were not correctly displayed (thank you, [psn](https://wordpress.org/support/topic/numbers-of-visit-in-maps-shows-zero))

= 3.8.2 =
* [New] You can now load, save and delete filters (or "goal conversions", in Google's terminology). Please test this new functionality and let us know if you find any bugs!
* [Update] Added new WordPress filter hooks to initialize the arrays storing the data about the pageview (thank you, Tayyab)
* [Update] [AmMap](http://www.amcharts.com/download/) version 3.11.3
* [Update] MaxMind GeoLite IP has been updated to the latest version (2014-11-05)
* [Fix] Bug affecting links opening in a new tab/window (target=_blank). Our thanks go to all the users who helped us troubleshoot the issue!
* [Fix] Backward compatibility of new date/time filters with old ones
* [Fix] Issue with counters on Posts/Pages screen (thank you, [vaguiners](https://wordpress.org/support/topic/0-visits-in-every-post-in-list-of-post-after-update))
* [Fix] Warning about undefined array index in date/time filters (thank you, Chris)
* [Fix] Some tooltips were being displayed outside of the browser viewport (thank you, Vitaly)

= 3.8.1 =
* It was only released on Github to solve a critical bug affecting external links

= 3.8 =
* [New] We increased the filter granularity to the minute, so that now you can see who visited your website between 9 am and 10.34 am (thank you, [berserk77](https://wordpress.org/support/topic/need-help-with-some-filtering-features))
* [New] If admin is served over HTTPS but IP lookup service is not, don't use inline overlay dialog (thank you, [509tyler](https://wordpress.org/support/topic/https-overlay-suggestion))
* [Update] Javascript libraries: qTip v2.2.1 and SlimScroll 1.3.3
* [Fix] Outbound links from within the admin were not tracked as expected (thank you [mobilemindtech](https://wordpress.org/support/topic/outbound-links-problem-in-version-374))
* [Fix] Firewall Fix add-on was not tracking the originating ip's country as expected (thank you, JeanLuc)

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

== Tools of the trade, in alphabetical order ==
[Duri.Me](http://duri.me/),
[Filezilla](https://filezilla-project.org/),
[Fontello](http://fontello.com/),
[Gimp](http://www.gimp.org/),
[Google Chrome](https://www.google.com/intl/en/chrome/browser/),
[poEdit](http://www.poedit.net/),
[Notepad++](http://notepad-plus-plus.org/),
[Tortoise SVN](http://tortoisesvn.net/),
[WAMP Server](http://www.wampserver.com/en/)