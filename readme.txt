=== WP SlimStat ===

Donate link: http://www.duechiacchiere.it/wp-slimstat
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews
Requires at least: 2.9
Tested up to: 3.0
Stable tag: 2.0.2

A smart web analytics tool. Track visits, pageviews, pathstats, and much more.

== Description ==

It's back, by popular demand! The first, the only, the inimitable: WP SlimStat 2.
Completely rewritten from the ground up, lightweight, optimized, much more powerful
and flexible. Please note: this is a beta version. It CAN be used in a production
environment, but some features are still being developed or improved. Please send me
your feedback if you find any bugs or unusual behaviors.

## Features:
* Tracks visits (user sessions up to 30 minutes) as defined by Google Analytics
* Loosely based on Wettone's [SlimStat 2](http://slimstat.net/)
* Reuses some of the features introduced by [Wp-SlimStat-Ex](http://082net.com/2006/756/wp-slimstat-ex-plugin-en/)
* You can develop your own reports and add them to WP SlimStat
* Reorganized interface, easier to use and understand
* Fully localizable in your language (please contribute!)
* The best country, browser and platform detection ever seen (thanks to [Browscap](http://code.google.com/p/phpbrowscap/) and [MaxMind](http://www.maxmind.com/) )
* Real-time graphs to help you visualize your visitors' trends (thanks to [FusionCharts](http://www.fusioncharts.com/free/) free edition)
* The browser recognition database is automatically updated every 2 weeks
* 10,000 hits use just 1.4 megabytes of DB space
* Tracks internal searches
* Filters hits based on IP addresses and networks, browsers, referrers and permalinks
* Can restrict view/admin to specific users
* Tracks screen resolution and other browser-related parameters
* Uses WP timezone settings and date formatting
* Content drill-down

## Third party technologies: 
* GeoLite Country Database provided by [MaxMind](http://www.maxmind.com/app/geolitecountry?rId=piecesandbits)
* [Browser Capabilities PHP Project](http://code.google.com/p/phpbrowscap/)

== Installation ==

1. Upload the entire folder and all the subfolders to your Wordpress plugins' folder
2. Activate it
3. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
4. You'll see a new menu entry under the Dashboard, called "Analytics"
5. To customize all its options, go to Settings > Slimstat
6. Show your appreciation! Tell your friends you are using WP SlimStat, write an article about it!

## Updating from 0.9.2

Unfortunately, due the completely different db structure, it's not possible to update from 0.9.2 to 2.0.x.
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

= 2.0.2 (beta2) =
* Added full compatibility with right-to-left text orientation (i.e. Arabic and Hebrew)
* Added filters for content drilldown
* Added hourly graphs (click on a day on the graph to switch to this view)
* Fixed a bug that prevented WP timezone to be correctly applied to all the metrics

= 2.0.1 (beta1) =
* End of alpha phase
* More reports added
* You can now purge the database using filters (i.e. delete rows whose IP is...)
* Code optimizations
* Improved localizations
* Updated version of browscap.ini

= 2.0 (alpha) =
* First release after four years. Too many things have changed to list them here ;)

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