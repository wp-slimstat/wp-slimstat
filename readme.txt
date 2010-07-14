=== WP SlimStat ===

Donate link: http://www.duechiacchiere.it/wp-slimstat
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9
Tested up to: 3.0
Stable tag: 2.0.6

A smart web analytics tool. Tracks visits, pageviews, pathstats, and much more.

== Description ==

It's back, by popular demand! The first, the only, the inimitable: WP SlimStat 2.
Completely rewritten from the ground up, lightweight, optimized, much more powerful
and flexible. Please contact me if you find any bugs or unusual behaviors.

## Main Features
* Tracks visits (user sessions up to 30 minutes) as defined by Google Analytics
* Tracks outbound links and downloads
* Tracks internal searches
* Tracks screen resolutions and other browser-related parameters
* Extendable API to let you develop your own reports
* Fully localizable in your language (please contribute!)
* The best country, browser and platform detection ever seen (thanks to [Browscap](http://code.google.com/p/phpbrowscap/) and [MaxMind](http://www.maxmind.com/) )
* Real-time charts to help you visualize your visitors' trends (thanks to [FusionCharts](http://www.fusioncharts.com/free/) free edition)
* The browser recognition database is automatically updated every 2 weeks
* Shows detailed information about each visitor: Country, city, area, organization, etc (thanks to [IP2Location](http://www.ip2location.com) )
* 10,000 hits use just 1.4 megabytes of DB space
* Ignore hits based on IP addresses and networks, browsers, referrers and permalinks
* Can restrict view/admin to specific users
* Uses WP timezone settings and date formatting
* Has a lot of filters for content drill-down
* Includes a detailed World Map

## Requirements
* Wordpress 2.9 or higher
* PHP 5.x or higher
* MySQL 5.x or higher
* At least 10 MB of free web space

== Installation ==

1. Upload the entire folder and all the subfolders to your Wordpress plugins' folder
2. Activate it
3. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
4. You'll see a new entry under the Dashboard menu
5. To customize all its options, go to Settings > Slimstat
6. Show your appreciation! Tell your friends you are using WP SlimStat, write an article about it!

## Updating from 0.9.2

Unfortunately, due the completely different db structure, it's not possible to update from 0.9.2.
I mean, you can still click on the link 'Update plugin' in the alert message you see inside your Plugins' admin panel,
but once the update process is complete, WP SlimStat 2 won't be able to track anything. There's an easy fix,
though: go to Settings > SlimStat > Maintenance. If you still have an 'old' db structure, you'll see a
message "Old table detected" and a button to RESET it. Click on this button (YOU WILL LOSE ALL YOUR
DATA COLLECTED BY WP-SlimStat 0.9.2!) and then deactivate/reactivate WP SlimStat. Now, if you go back
to Maintenance, that alert message should be gone.

== Screenshots ==

1. Reports
3. Options

== Changelog ==

= 2.0.6 =
* First 'stable' version, finally!
* Added a World map to graphically represent hits and visits. You can use filters for content drill-down (thanks Michael)
* Added number_format to improve readability (thanks Digo)
* Added a new icon near to each IP address, to see all the details about that address (thanks Digo)
* Added an option to set the number of results listed within each module
* Added an internal feature that allows me to keep track of all the sites using WP SlimStat out there
* Fixed a bug in calculating the percentage of hits for each exit page ( Content -> Exit pages )
* Fixed a bug in calculating unique IPs ( Dashboard -> Summary for... )
* Fixed a bug in the 'Internal Searches' module
* Custom reports now shares the same class used by the main View panels
* Improved the overall security and stability of WP SlimStat
* Introduced some SQL code optimizations for faster queries

= 2.0.5 (release candidate 2) =
* Added an option to filter metrics by date intervals (given date plus x days)
* Fixed a bug in calculating the date range in some metrics
* Geolocalization: updated the information in the CSV file included (June 2010)

= 2.0.4 (release candidate 1) =
* Moving into Release Candidate phase
* Added an option to disable Browscap's autoupdate feature (to update browsers' definitions)
* Fixed some bugs with SQL queries

= 2.0.3 (beta3) =
* Added 'internal searches' metrics
* Added a new option to track outbound links and downloads
* Added a new option to update the ip-to-countries conversion table
* Added a new view to access the 'raw' information recorded by WP SlimStat
* Metrics are now clickable to enable the corresponding filter
* Countries and languages: if a localization is not available, use English names instead of country codes
* Geolocalization: updated the information in the CSV file included
* Fixed some bugs in the CSS to support right-to-left text orientation
* Fixed a bug that prevented filtering users who can view/config WP SlimStat reports
* Fixed a bug with InnoDB db type
* Fixed a bug that made IE display an alert message about 'installing potentially dangerous software'
* Fixed a bug in calculating some of the percentages shown in the metrics
* Fixed a bug in generating the URL's associated to the external links listed in some metrics
* Fixed a bug in creating WP SlimStat tables: it looks like MyISAM 5.0 does not like foreign keys
* Code and SQL optimizations

== Localization ==

Wp-SlimStat is fully localizable. Everything can be translate in your language
and character encoding. As for every other Wordpress plugin, I used the Portable
Object (.po) standard to do this. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder.

== Extras ==

After you download and install WP SlimStat, you'll see not one, not two, but three new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. Here is a bried description of the 
other two.

WP SlimStat Dashboard Widgets - This plugin adds some reports directly to your dashboard. Useful for who wants
the most relevant metrics handy.

WP SlimStat Custom Reports - Yes, you can! Create and view your personalized analytics for WP SlimStat. This
plugin demonstrates how to extract your own reports. Please do not hesitate to contact me if you need help with
this feature.