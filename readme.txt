=== WP SlimStat ===

Donate link: http://www.duechiacchiere.it/wp-slimstat
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9.2
Tested up to: 3.0
Stable tag: 2.0.8

== Description ==
A simple but powerful web analytics plugin for Wordpress. It doesn't require any subscription
to external statistic services: all metrics are kept on your local server, private and accessible
to your eyes only. Features the famous one-click install-and-go.

## Requirements
* Wordpress 2.9 or higher
* PHP 5.x or higher
* MySQL 5.x or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space

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

= 2.0.8 =
* WP SlimStat speaks German! Thank you [Digo](http://www.showhypnose.org/blog/)!
* Added a new option to some metrics to access more detailed information about hits and visits
* Added more options to show statistics and metrics on your website. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.0#post_displaymetrics) for further details
* Now it detects if you are using HTTPS in your admin pages (thanks Tuxicoman)
* In order to avoid conflicts with Lightbox and friends, you can now "mark" specific links to ignore. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.msg2#post_avoidconflicts) for further details
* Fixed a bug in calculating some percentages
* Reorganized the 'Raw Data' section
* Changed the extension of WP SlimStat Custom Reports from .php to .txt, so that it won't appear in your Plugins page anymore

= 2.0.7 =
* Added an option to let you choose if you want WP SlimStat to add a separate admin menu, or keep it integrate with Wordpress' menus
* Now you can display a lot of metrics on your website. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.0)
* Fixed a conflict between WP SlimStat and WP SlimStat Dashboard Widgets
* Fixed a bug that prevented the autoupdate of Browscap to run periodically
* Fixed a possible XSS vulnerability when parsing filters (thanks Nadav)
* Some graphic fine-tuning to adapt the layout to the new admin interface and colors
* Geolocalization: updated the information in the CSV file included (July 2010)

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

== Localization ==

Wp-SlimStat is fully localizable. Everything can be translate in your language
and character encoding. As for every other Wordpress plugin, I used the Portable
Object (.po) standard to do this. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder.

== Extras ==

= List of donors =
* [Bluewave Blog](http://blog.bluewaveweb.co.uk/)
* [La casetta delle pesche](http://www.lacasettadellepesche.it/)

= Contributors =
* [Digo](http://www.showhypnose.org/blog/) - German localization

= Dashboard Widgets and Custom Reports = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. Useful for who wants the most relevant metrics handy.