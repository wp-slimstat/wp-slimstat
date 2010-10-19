=== WP SlimStat ===

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
* Added new ways to filter and order your metrics. I was inspired by [this article](http://www.searchenginepeople.com/blog/optimizing-title-tag.html) in adding a way to find the least accessed information on your blogs
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

= 2.0.9 =
* A new member has joined the WP SlimStat Family: [WP SlimStat ShortCodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/)
* Improved the Options > Maintenance panel: now you can delete rows based on the IP address, and use some new filters (thank you Herman)
* Now you can filter metrics [based on authors](http://lab.duechiacchiere.it/index.php?topic=33.0) (thank you Matt)
* A new option under Options > General allows you to move `wp-slimstat-js.php` out of its original folder, for security reasons (thank you Steven). You will have to edit this file accordingly, though, before moving it
* Added a new option to filter hits based on users being logged in (Options > Filters)
* Added a new option to optimize your database, if needed (Options > Maintenance)
* Fixed a bug that prevented links with target=_blank to open in a new window (thank you Julius and [Wordpress Deutschland](http://forum.wordpress-deutschland.org/konfiguration/71929-ext-links-oeffnen-sich-im-gleichen-fenster-trotz-_blank-2.html))
* Fixed a typo that prevented one of the icons to show up correctly
* Fixed a problem with BrowsCap that occurred when the cache folder was not writeable (thank you Julius)
* Fixed some warning errors that came out with PHP in 'strict' mode (error reporting = E_ALL and WP_DEBUG = true, thank you Olivier Sermann)
* Bounce rate percentages are now even more accurate
* Geolocalization: updated the information in the CSV file included (August 2010). Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

== Localization ==

Wp-SlimStat is fully localizable. Everything can be translate in your language
and character encoding. As for every other Wordpress plugin, I used the Portable
Object (.po) standard to do this. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder.

== List of donors in alphabetical order ==
* [Andrea Pinti](http://andreapinti.com/)
* [Bluewave Blog](http://blog.bluewaveweb.co.uk/)
* [Hans Schantz](http://www.aetherczar.com/)
* [Herman Peet](http://www.hermanpeet.nl/)
* [La casetta delle pesche](http://www.lacasettadellepesche.it/)
* [Mobilize Mail](http://blog.mobilizemail.com/)
* Mora Systems
* Neil Robinson

== Contributors in alphabetical order ==
* [Digo](http://www.showhypnose.org/blog/) - German localization
* [Tuxicoman](http://tuxicoman.jesuislibre.net/) - French localization

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. Useful for those who want the most relevant metrics handy.