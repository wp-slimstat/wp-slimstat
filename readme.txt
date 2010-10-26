=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9.2
Tested up to: 3.1
Stable tag: 2.2.1

== Description ==
A simple but powerful real-time web analytics plugin for Wordpress. It doesn't require any subscription
to external statistic services: all metrics are kept on your local server, private and accessible
to your eyes only. Features the famous one-click install-and-go.

## Requirements
* Wordpress 2.9 or higher
* PHP 5.1 or higher
* MySQL 5.x or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space

## Main Features
* It tracks visits (user sessions up to 30 minutes) as defined by Google Analytics
* It tracks outbound links and downloads
* It tracks internal searches
* It tracks screen resolutions and other browser-related parameters
* It works with [W3 Total Cache](http://lab.duechiacchiere.it/index.php?topic=45.0)!
* It exports a simple API to let you develop your own reports
* Fully localizable in your language (please contribute!)
* The best country, browser and platform detection ever seen (thanks to [Browscap](http://code.google.com/p/phpbrowscap/) and [MaxMind](http://www.maxmind.com/) )
* Real-time charts to help you visualize your visitors' trends (thanks to [FusionCharts](http://www.fusioncharts.com/free/) free edition)
* The browser recognition database is automatically updated every 2 weeks
* It shows detailed information about each visitor: Country, city, area, organization, etc (thanks to [IP2Location](http://www.ip2location.com) )
* 10,000 pageviews use just 1.4 megabytes of DB space
* It ignores pageviews based on IP addresses, browsers, referrers, users and permalinks
* You can restrict view/admin access to specific users
* It uses WP timezone settings and date formatting
* It has a lot of filters for content drill-down
* It includes a detailed World Map

## Child projects
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/)

== Installation ==

1. Upload the entire folder and all the subfolders to your Wordpress plugins' folder
2. Activate it
3. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
4. You'll see a new entry under the Dashboard menu
5. To customize all its options, go to Settings > Slimstat
6. Show your appreciation! Tell your friends you are using WP SlimStat, write an article about it!

== Screenshots ==

1. Reports
2. Options

== Changelog ==

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
* Geolocalization: updated the information in the CSV file included (October 2010). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

= 2.1 =
* Fixed a bug that prevented Visitors to be tracked properly. If you downloaded/installed WP SlimStat 2.0.9, go to Settings > WP SlimStat > General tab and (unless you have  explicitly changed this option) set the value for `Custom Path` to the default one shown in the description underneath. 
* Fixed a bug that led to inaccurate operating systems detection (thank you Herman)
* WP SlimStat speaks French! Thank you Tuxicoman!
* Updated browscap.ini and cache.php to the latest version available

== Language Localization ==

Wp-SlimStat can speak your language! I used the Portable Object (.po) standard
to implement this feature. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder,
and contact me via the [support forum](http://lab.duechiacchiere.it) when you're ready
to send me your files. Right now the following localizations are available (in
alphabetical order):

* French - [Tuxicoman](http://tuxicoman.jesuislibre.net/) and Vidal Arpin
* German - [Digo](http://www.showhypnose.org/blog/)
* Italian

== List of donors in alphabetical order ==
* [Andrea Pinti](http://andreapinti.com/)
* [Bluewave Blog](http://blog.bluewaveweb.co.uk/)
* [Hans Schantz](http://www.aetherczar.com/)
* [Herman Peet](http://www.hermanpeet.nl/)
* [La casetta delle pesche](http://www.lacasettadellepesche.it/)
* [Mobilize Mail](http://blog.mobilizemail.com/)
* Mora Systems
* Neil Robinson

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. Useful for those who want the most relevant metrics handy.