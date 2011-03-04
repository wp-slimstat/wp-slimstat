=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9.2
Tested up to: 3.1
Stable tag: 2.3

== Description ==
A simple but powerful real-time web analytics plugin for Wordpress. It doesn't require any subscription
to external statistic services: all metrics are kept on your local server, private and accessible
to your eyes only. Features the famous one-click install-and-go.

## Requirements
* Wordpress 2.9 or higher
* [Proper Network Activation](http://wordpress.org/extend/plugins/proper-network-activation/), if you're planning to use it in a multiblog environment
* PHP 5.1 or higher
* MySQL 5.x or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space

## Database usage
WP SlimStat needs to create its own tables in order to maintain the complex information about visits, visitors, browsers and Countries. It creates 3 new tables for each blog, plus 3 shared tables (6 tables in total, for a single-user installation). Please keep this in mind before activating WP SlimStat on large networks of blogs. The alternative? Google Analytics, so that you don't have to carry the load of storing the information on your server at all. Even if this means losing most of the amazing features that make WP SlimStat unique.

## Main Features
* It tracks visits (user sessions up to 30 minutes) as defined by Google Analytics
* It tracks outbound links and downloads
* It tracks internal searches
* It tracks screen resolutions and other browser-related parameters
* It works with [W3 Total Cache](http://lab.duechiacchiere.it/index.php?topic=45.0) and [Fluency Admin](http://deanjrobinson.com/projects/fluency-admin/)
* It exports a simple API to let you develop your own reports
* Fully localizable in your language (please contribute!)
* The best country, browser and platform detection ever seen (thanks to [Browscap](http://code.google.com/p/phpbrowscap/) and [MaxMind](http://www.maxmind.com/) )
* It features real-time charts to help you visualize your visitors' trends (thanks to [FusionCharts](http://www.fusioncharts.com/free/) free edition)
* It shows very detailed information about each visitor: IP, country, city, area, organization, etc (thanks to [IP2Location](http://www.ip2location.com) )
* 10,000 pageviews use just 1.4 megabytes of DB space
* You can decide to ignore pageviews based on IP addresses, browsers, referrers, users and permalinks
* You can restrict view/admin access to specific users
* It uses WP timezone settings and date formatting
* It has a lot of filters for content drill-down
* It includes a World Map

## Related plugins
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/)

== Installation ==

1. Upload the entire folder and all the subfolders to your Wordpress plugins' folder
2. Activate it
3. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
4. You'll see a new entry under the Dashboard menu
5. To customize all its options, go to Settings > Slimstat
6. Show your appreciation! Tell your friends you are using WP SlimStat, write an article about it!
7. If you're blocking the access to your `wp-content` folder, move `wp-slimstat-js.php` to a different folder, and edit the first line to let WP SlimStat know where your `wp-config.php` is located

== Screenshots ==

1. Reports
2. Options

== Changelog ==

= 2.3 =
* I've been kind of busy, but this plugin is still actively maintained, don't worry!
* Geolocation: updated the information in the CSV file included March 2011). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.
* Browscap.ini: updated to the latest version available (February 2011)
* Implemented some DB optimizations for large data collections. You can now switch to InnoDB at anytime, if your provider supports this feature (thank you [GoMySQL](http://lab.duechiacchiere.it/index.php?topic=74))
* Fixed a bug in deleting old entries with autopurge
* Fixed a bug related to timezones and php.ini (thank you [zipper1976](http://lab.duechiacchiere.it/index.php?topic=365.0))
* Fixed a vulnerability related to XSS attempts (thank you [distortednet](http://lab.duechiacchiere.it/index.php?topic=110.0))
* Fixed a bug in generating the URL's to go to referring pages (thank you [randall](http://lab.duechiacchiere.it/index.php?topic=314.0))
* Fixed a bug in calculating percentages in WP SlimStat Dashboard (thank you Pietro)
* Added a new code to counts all the visits not associated to any browser
* Added: usernames in filters can now include spaces, underscores and dashes
* Added: a new filter to restrict the access to WP Slimstat based on user capabilities

= 2.2.3 =
* Maintance release: apparently the new optimized SQL to purge the old records was misbehaving on some installations. Now it should be fixed.

= 2.2.2 =
* Support for network installations has been dropped from WP SlimStat's source code. WP developers haven't yet released a fix to address [some of the issues](http://core.trac.wordpress.org/ticket/14170) affecting the activation process. You may want to use [Proper Network Activation](http://wordpress.org/extend/plugins/proper-network-activation/) by Scribu, if you're planning to install my plugin for a network of blogs.
* If you were using version 2.2.1 in a network of blogs (WP MultiSite), some of the tables created by my plugin are not needed anymore (redundant). Please contact me on my [support forum](http://lab.duechiacchiere.it/) for further information, each case needs to be addressed in a different way.
* WP SlimStat now speaks Spanish (thank you Noe Martinez)
* Fixed a bug that prevented filtering data using intervals when the last day of the interval was the current day
* WP SlimStat Dashboard Widgets has been updated and some bugs have been fixed
* Geolocation: updated the information in the CSV file included (November 2010). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.
* The interface has been updated to make it compatible with [Fluency Admin](http://deanjrobinson.com/projects/fluency-admin/). Please make sure to empty your cache and refresh the CSS to load the updated version
* **Work in progress**: I'm starting to implement the DB optimizations suggested by [GoMySQL](http://lab.duechiacchiere.it/index.php?topic=74.0). You will be able to activate/deactivate these features in the Maintenance panel. For larger databases, you may need to run those SQL queries directly in phpMyAdmin to avoid timeouts and the like.

= 2.2.1 =
* Maintenance release, fixed minor bugs here and there
* Added new ways of filtering and ordering your metrics. I was inspired by [this article](http://www.searchenginepeople.com/blog/optimizing-title-tag.html)
* Improved support for multi-site installations (thank you Paul Hastings)
* Smarter support for websites using HTTPS
* Fixed a bug that prevented, in some specific cases, the correct tracking of search engines and other bots (thank you Herman Peet)
* Fixed a bug that occurred when filtering by keywords containing special characters (like &, ?, etc)
* The ID passed to the javascript is now encoded, for improved security and better 'privacy'
* `Browscap.ini` has been updated to the latest version available

= 2.2 =
* WP SlimStat is now faster than ever! Most SQL queries have been optimized and are now able to better leverage indexes
* Multisite aware - Perform network installations in a few clicks (highly experimental, I need volunteers to test it!). The [underlying functions](http://core.trac.wordpress.org/ticket/14170#comment:22) are being discussed by WP Core developers, so this may change in the foreseeable future. Please don't test this feature on a production environment!
* Javascript code is now minified (thank you Google Page Speed)
* Added a new option to track legged in WP users
* Added a new option to filter metrics by post category ID
* Visitor Tracking (via cookie) is now PHP-based, not Javascript-based anymore, making it even more reliable
* Improved the way visitors are counted in some metrics
* Fixed some more warning errors that came out with PHP in 'strict' mode (thank you Julien Schmidt)
* Fixed a bug that prevented the correct display of long times frames, spanning through multiple months
* Fixed a bug that prevented filtering by search terms containing single and double quotes
* Geolocation: updated the information in the CSV file included (October 2010). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

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
* [Andrea Pinti](http://andreapinti.com/)
* [Bluewave Blog](http://blog.bluewaveweb.co.uk/)
* [Hans Schantz](http://www.aetherczar.com/)
* [Herman Peet](http://www.hermanpeet.nl/)
* [La casetta delle pesche](http://www.lacasettadellepesche.it/)
* [Mobilize Mail](http://blog.mobilizemail.com/)
* Mora Systems
* Neil Robinson
* [Sahin Eksioglu](http://www.alternatifblog.com/)

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. Useful for those who want the most relevant metrics handy.