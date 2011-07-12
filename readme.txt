=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9.2
Tested up to: 3.2
Stable tag: 2.4.3

== Description ==
A lightwight but powerful real-time web analytics plugin for Wordpress. Spy your visitors and track what they do on your website.

## Requirements
* Wordpress 3.0 or higher (it may not work on large multisite environments)
* PHP 5.1 or higher
* MySQL 5.0.3 or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space

## Database usage
WP SlimStat needs to create its own tables in order to maintain the complex information about visits, visitors, browsers and Countries. It creates 3 new tables for each blog, plus 3 shared tables (6 tables in total, for a single-user installation). Please keep this in mind before activating WP SlimStat on large networks of blogs.

## Main Features
* Support for both InnoDB and MyISAM (autodetect)
* Visit tracking (user sessions up to 30 minutes) as defined by Google Analytics
* Spy view, screen resolution tracking and other browser-related parameters
* The best country, browser and platform detection ever seen, thanks to [Browscap](https://github.com/garetjax/phpbrowscap) and [MaxMind](http://www.maxmind.com/)
* Real-time charts (thanks to [FusionCharts](http://www.fusioncharts.com/free/) free edition)
* 10,000 hits take just 1.4 megabytes of DB space
* Filters to ignore pageviews based on IP addresses, browsers, referrers, users and permalinks
* Restrict view/admin access to specific users
* World Map

## Related plugins
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/)

== Installation ==

1. If you are upgrading from a previous version, *deactivate WP SlimStat*
2. Upload all the files and folders to your server
3. Activate it 
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. You'll see a new entry under the Dashboard menu
6. Customize all its options, go to Settings > Slimstat
8. If you're blocking access to your `wp-content` folder, move `wp-slimstat-js.php` to a different folder, and edit the first line of code to let WP SlimStat know where your `wp-config.php` is

== Screenshots ==

1. Reports
2. Options

== Changelog ==

= Wishlist and planned features =
* Check to see if username exists in regards to configuration permissions
* Google URL's parser
* Replace Flash with JQuery to draw charts

= 2.4.3 =
* Maintenance release
* Added: reintroduced support for network activations. As pointed out [in the mailing list](http://lists.automattic.com/pipermail/wp-hackers/2011-June/039871.html) by some core Wordpress developers, there's no ideal solution for large networks, and if your network has more than 40 blogs, the activation process will most likely timeout.
* Added: new color-coded interface to visually identify types of visits on-the-fly (thank you Davide). Refer to the Raw Data tab to learn about what each color means.
* Added: hostnames to the Raw Data view (thank you Jonathan)
* Added: entry in the admin bar, to access your stats from anywhere
* Updated: Browser Detection Database (Released: Wed, 22 Jun 2011 23:26:51 -0000)
* Updated: some code optimizations
* Fixed: a bug in SlimStat Dashboard preventing some users from seeing the widgets (thank you [Flauschi](http://wordpress.org/support/topic/plugin-wp-slimstat-dashboard-widgets-are-not-displayed-all-the-time?replies=2#post-2203682))
* Fixed: layout improvements (thank you Jonathan)
* Fixed: a bug with multisite environments and referrers (thank you [kevinff](http://wordpress.org/support/topic/plugin-wp-slimstat-javascript-not-included-in-my-pages))

= 2.4.2 =
* I like to move it, move it :) Drag and drop WP Slimstat panels around, organize your stats to fit your style (thank you Davide)
* Added: customize the IP lookup service URL, use your own if you like! (Go to Settings > Slim Stat > Views)
* Added: auto refresh the RAW Data panel every X seconds (Go to Settings > Slim Stat > Views to enable this feature)
* Updated: Spy view details now show search terms (thank you Sharon)
* Fixed: a bug that affected downloads tracking (thank you [ronthai](http://wordpress.org/support/topic/plugin-wp-slimstat-some-questions-and-praise))
* Geolocation: updated to June 2011, 148393 rows. Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

= 2.4.1 =
* Added: filter by visit_id (which is empty when the visitor is a bot or search engine spider)
* Added: filter results where field SOUNDS LIKE query
* Added: you can remove single filters now, not just the entire list (thank you Davide)
* Updated: CSS is forward compatible with Wordpress 3.2
* Fixed: an issue with permissions
* Fixed: minor problems with the new DB structure, file permissions, CSS compatibility

= 2.4 =
* Added: allow admins to choose what to use for decimal point and thousands separator (Settings > Views)
* Added: tooltips to get extra information about a request (hover over entries)
* Added: commenters' tracking, aka Spy View,  and corresponding filter (thank you [Davide](http://www.davidetomasello.it))
* Added: ignore hits by referer
* Added: two new filters, `empty` and `not empty`
* Updated: search keywords are now detected using Google Analytics' approach
* Removed: some unused functions from the view library `wp-slimstat-view.php`. If you are using this class in your code, please be advised that some methods may not work anymore. Contact me if you have questions.
* Fixed: PHP warning message in `wp-slimstat-js.php`
* Fixed: bug related to latency of similar requests (thank you Dennis)
* Fixed: bug in calculating some dates in the chart (thank you Saill)
* Fixed: bug related to resource filtering (thank you Arek)
* Fixed: bug in generating the URL's to go to referring pages (thank you [randall](http://lab.duechiacchiere.it/index.php?topic=314.0))
* Fixed: bug related to quotes in search strings
* Geolocation: updated the information in the CSV file included (May 2011, 146219 rows). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.
* Browscap.ini: updated to the latest version available (April 25, 2011)
* Simplified the CSS structure, now compatible with Wordpress 3.1.2
* Rewritten some critical SQL queries to use prepare instead of escape, as per [Coding Standards](http://codex.wordpress.org/Function_Reference/wpdb_Class#Protect_Queries_Against_SQL_Injection_Attacks)
* Simplified most DB queries, moving some business logic from MySQL to PHP
* Changed the IP lookup service to InfoSniper, which offers much more detailed information about your visitors

== Languages ==

Wp-SlimStat can speak your language! I used the Portable Object (.po) standard
to implement this feature. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder,
and contact me via the [support forum](http://lab.duechiacchiere.it) when you're ready
to send me your files. Right now the following localizations are available (in
alphabetical order):

* French ([Tuxicoman](http://tuxicoman.jesuislibre.net/) and Vidal Arpin)
* German - ([Digo](http://www.showhypnose.org/blog/))
* Italian
* Spanish (Noe Martinez)

== List of donors in alphabetical order ==
[Andrea Pinti](http://andreapinti.com/), [Bluewave Blog](http://blog.bluewaveweb.co.uk/), [Dennis Kowallek](http://www.adopt-a-plant.com),
[Hans Schantz](http://www.aetherczar.com/), [Herman Peet](http://www.hermanpeet.nl/), [La casetta delle pesche](http://www.lacasettadellepesche.it/),
[Mobilize Mail](http://blog.mobilizemail.com/), Mora Systems, Neil Robinson, [Sahin Eksioglu](http://www.alternatifblog.com/),
[Saill White](http://saillwhite.com), Wayne Liebman

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. 