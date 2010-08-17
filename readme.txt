=== WP SlimStat ===

Donate link: http://www.duechiacchiere.it/wp-slimstat
Tags: analytics, statistics, slimstat, shortstat, tracking, pathstat, reports, referers, hits, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 2.9.2
Tested up to: 3.0.1
Stable tag: 2.0.9

== Description ==
A simple but powerful real-time web analytics plugin for Wordpress. It doesn't require any subscription
to external statistic services: all metrics are kept on your local server, private and accessible
to your eyes only. Features the famous one-click install-and-go. Results are shown in real-time!

## Requirements
* Wordpress 2.9 or higher
* PHP 5.1 or higher
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

= 2.1.0 =
* Fixed a bug that prevented Visitors to be tracked properly. If you downloaded/installed WP SlimStat 2.0.9, go to Options > General and (unless you have  explicitly changed this option) replace the value in the field with the default one shown in the description underneath. 
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

= 2.0.8 =
* WP SlimStat speaks German! Thank you [Digo](http://www.showhypnose.org/blog/)!
* Added a new option to some metrics to access more detailed information about hits and visits
* Added more options to show statistics and metrics on your website. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.0#post_displaymetrics) for further details
* Now it detects if you are using HTTPS in your admin pages (thank you Tuxicoman)
* In order to avoid conflicts with Lightbox and friends, you can now "mark" specific links to ignore. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.msg2#post_avoidconflicts) for further details
* Fixed a bug in calculating some percentages
* Reorganized the 'Raw Data' section
* Changed the extension of WP SlimStat Custom Reports from .php to .txt, so that it won't appear in your Plugins page anymore

= 2.0.7 =
* Added an option to let you choose if you want WP SlimStat to add a separate admin menu, or keep it integrate with Wordpress' menus
* Now you can display a lot of metrics on your website. See the [How-to Guide](http://lab.duechiacchiere.it/index.php?topic=2.0)
* Fixed a conflict between WP SlimStat and WP SlimStat Dashboard Widgets
* Fixed a bug that prevented the autoupdate of Browscap to run periodically
* Fixed a possible XSS vulnerability when parsing filters (thank you Nadav)
* Some graphic fine-tuning to adapt the layout to the new admin interface and colors
* Geolocalization: updated the information in the CSV file included (July 2010)

== Localization ==

Wp-SlimStat is fully localizable. Everything can be translate in your language
and character encoding. As for every other Wordpress plugin, I used the Portable
Object (.po) standard to do this. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder.

== List of donors ==
* [Bluewave Blog](http://blog.bluewaveweb.co.uk/)
* [La casetta delle pesche](http://www.lacasettadellepesche.it/)
* [Herman Peet](http://www.hermanpeet.nl/)

== Contributors ==
* [Digo](http://www.showhypnose.org/blog/) - German localization

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. Useful for those who want the most relevant metrics handy.