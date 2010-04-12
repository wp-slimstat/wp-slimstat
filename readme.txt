=== WP SlimStat ===

Donate link: http://www.duechiacchiere.it/wp-slimstat
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews
Requires at least: 2.9
Tested up to: 3.0
Stable tag: 2.0

A smart web analytics tool. Track visits, pageviews, pathstats, and much more. Configurable
to filter hits based on the IP addresses, browser names and so on,  with just a few clicks.

== Description ==

It's back, by popular demand! Yes, you asked for it and here it is: WP SlimStat 2.
Completely rewritten from the ground up, lightweight, optimized, much more powerful
and flexible. Please note: this is still an alpha version. It CAN be used in a production
environment, but some of the features (like filtering and 'more' links) are not active yet.
So please don't submit a bug report for these.

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
* Filters IP addresses to ignore
* Filters browsers, referrers and URLs to ignore
* Can restrict view/admin to specific users
* Tracks screen resolution and other browser-related parameters
* Uses WP timezone settings and date formatting

## Third party technologies: 
* GeoLite Country Database provided by [MaxMind](http://www.maxmind.com/app/geolitecountry?rId=piecesandbits)
* [Browser Capabilities PHP Project](http://code.google.com/p/phpbrowscap/)

== Installation ==

1. Upload the entire folder and all the subfolders to your Wordpress plugins' folder
2. Activate the plugin in your admin panel
3. Make sure your template calls the function `wp_footer()` somewhere (possibly just before the `</body>` tag)
4. You'll see a new menu entry under the Dashboard, called "Analytics". If you don't want to configure WP SlimStat, you're done.
5. You'll also have a new menu entry under Tools, called "SlimStat". Here you can customize all the options. 
6. Show your appreciation! Tell your friends you are using WP SlimStat, write an article about it!

== Screenshots ==

1. Reports
3. Options

== Changelog ==

= 2.0 =
* First release after four years. Too many things have changed to list them here ;)

== Updating IP-to-Country ==

Wp-SlimStat uses a local "IP to country" conversion table. The information is stored in your database.
This is definitely faster than downloading an XML file from remote server for every single hit. But it
also means that you need at least 2.6 megabyte of db space to host the table, and you will have to 
update it manually, once in a while. Please check WP SlimStat homepage to see if a new version of this
table is available. In this case just replace `geoip.csv` inside the plugin's folder and then deactivate
and re-activate it from the admin interface.

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