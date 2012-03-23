=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: chart, analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, wassup, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, flot, stalker, statistics, google+, monitor, seo
Requires at least: 3.1
Tested up to: 3.3.1
Stable tag: 2.8

== Description ==
A powerful real-time web analytics plugin for Wordpress.

= Main Features =
* Real-time web analytics reports
* Modern, easy to use and customizable interface (yep, you can also drag-and-drop widgets around)
* Advanced tools, like commenters tracking, Google Plus One and Facebook Like click tracking
* The best country, browser and platform detection ever seen, thanks to [Browscap](https://github.com/garetjax/phpbrowscap) and [MaxMind](http://www.maxmind.com/)
* Drill-down capabilities: filter your stats based on IP addresses, browsers, referrers, users and much more
* View and admin access can be restricted to specific users
* Pan-and-zoom World Map, thanks to [amMap](http://www.ammap.com/).

= Requirements =
* Wordpress 3.1 or higher (it may not work on large multisite environments)
* PHP 5.1 or higher
* MySQL 5.0.3 or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 550kb of free memory for the tracker

= Browser Compatibility =
WP SlimStat uses HTML5 canvases to display its charts. This approach is not compatible with Internet Explorer 8 or older, so you're encouraged to upgrade your browser.

You may also like [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/), which enables shortcodes to show your metrics in a widget or a page.

== Installation ==

1. Go to Plugins > Add New
2. Search for WP SlimStat
3. Click on Install Now under WP SlimStat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. To customize all the plugin's options, go to Settings > Slimstat
6. If you're blocking access to your `wp-content` folder, move `wp-slimstat-js.php` to a different folder, and edit its first line of code to let WP SlimStat know where your `wp-config.php` is

== Frequently Asked Questions ==

= Can I track Google Plus One and other events? =
Yes, this plugin includes a Javascript function that can be used to track any event: click, mouseover, focus, keypress, etc. Here's the syntax:

`ss_te(e, c, f, n)`

Where:

* `e` is the event that was triggered
* `c` is a numeric value between 1 and 254 (zero is reserved for outbound clicks)
* `f` - this parameter is deprecated and will be removed in the next releases
* `n` is a custom text (up to 512 chars long) that you can use to add a note to the event tracked. If the ID attribute is defined, and no note has been specified, the ID value will be used. If the function is attached to a key-related event, the key pressed will be recorded as a note.

Examples:

* `onclick="if(typeof ss_te == 'function') ss_te(event, 5, false, 'clicked on first link');"`
* `onkeypress="if(typeof ss_te == 'function') ss_te(event, 20, false);"`
* To make your life easier, a *Google Plus One* callback function is included as well: `<g:plusone callback="slimstat_plusone"></g:plusone>`. Positive entries will have code = 3, negative ones (undo +1) code = 4. Remember: Google's javascript must be loaded *after* slimstat.js, so make sure to put things in the right place in your souce code.

= How do I create my own custom reports? =
You need to [write a plugin](http://lab.duechiacchiere.it/index.php?topic=2.0#post_customreports) that retrieves the information from WP SlimStat tables and displays it using
the format described here below. A demo plugin is included within the package: take a look at its source code
(which I tried to keep as simple as possible) and cut your imagination loose! 

= How do I display stats on my website? =
WP SlimStat has two ways of displaying its reports on your website. Including filters!

*Direct access*
You will need to edit your template and add something like this where you want your metrics to appear:
`// Load WP SlimStat VIEW, the library with all the metrics
require_once(WP_PLUGIN_DIR.'/wp-slimstat/view/wp-slimstat-view.php');

// Define a filter: I want to show only hits by people who where using Firefox, any version
$filters = array('browser' => 'Firefox', 'browser-op' => 'contains');

// Instantiate a new copy of that class
$wp_slimstat_view = new wp_slimstat_view($filters);

// Use the appropriate method to display your stats
echo $wp_slimstat_view->count_records('1=1', '*', false);`

*Using shortcodes*
Please refer to [this](http://lab.duechiacchiere.it/index.php?topic=2.0#post_shortcodes) page for more information.
[Here](http://lab.duechiacchiere.it/index.php?topic=2.0#post_displaymetrics) you can find a list of all available functions and filters.

= Can I disable outbound link tracking on a given link? =
Yes. This is useful if you notice that, after clicking on a Lightbox-powered thumbnail, the image doesn't open inside the popup window as expected.
Let's say you have a link associated to Lightbox (or one of its clones):

`<a href="/wp-slimstat">Open Image in LightBox</a>`

Change it to:

`<a href="/wp-slimstat" class="noslimstat">Open Image in LightBox</a>`

Clicks will still be tracked, but WP SlimStat will not interfere with Lightbox anymore.

= After installing WP Supercache (or other caching plugin), WP SlimStat shows very few visits, why is that? =
This plugin *is incompatible* with WP Supercache, WP Cache, Hyper Cache, or any page-based caching plugin.

= Why does WP SlimStat show more page views than actual pages clicked by a user? =
"Phantom" page views can occur when a user's browser does automatic feed retrieval,
[link pre-fetching](https://developer.mozilla.org/en/Link_prefetching_FAQ), or a page refresh. WP SlimStat tracks these because they are valid
requests from that user's browser and are indistinguishable from user link clicks. You can ignore these visits setting the corresponding option
in Settings > SlimStat > Filters

= How do I stop WP SlimStat from tracking spammers? =
Go to Settings > SlimStat > Filters and set "Ignore Spammers" to YES.

= How do I stop WP SlimStat from recording new visits on my site? =
Go to Settings > SlimStat > General and set "Activate tracking" to NO.

== Screenshots ==

1. Dashboard
2. Configuration panel
3. Mobile view

== Changelog ==

= Planned features =
* Add "internal" stats about your blog: post count, comments per post, table sizes, etc
* Merge with WP SlimStat Shortcodes
* Antiflood monitor

= 2.8 =
* Added: WP SlimStat now looks for HTTP headers like X_FORWARDED_FOR to get, when available, private IP addresses of people using proxies
* Added: You can now use wildcards in your filters: `*` for any string, `!` for any single character
* Added: new columns to track even more information about events and outbound links (thank you [SpenceDesign](http://wordpress.org/support/topic/plugin-wp-slimstat-tracking-right-click-of-download-links))
* Added: started working on heat maps. The information is already being collected, but not shown to the admin... just yet!
* Added: WP SlimStat won't activate on networks that have more than 50 blogs
* Fixed: Screen antialias detection is now much more accurate
* Fixed: visitors' tracking wasn't working properly for some users.
* Updated: Event tracking has been completely rewritten and optimized (thank you [SpenceDesign](http://wordpress.org/support/topic/plugin-wp-slimstat-tracking-right-click-of-download-links))
* Updated: Referer is now being stored as the whole URL, to keep track of port numbers, HTTPS and other protocols
* Updated: Browscap Database (February 2012)
* Updated: Geolocation Database (March 2012,  to February 2012, 173415 rows). Go to Options > Maintenance > Reset Ip-to-Countries.

= 2.7 =
* Added: Goodbye FusionMaps, welcome AmMap, which brings a new world map with pan and zoom capabilities
* Added: Direct access to the panels from the Admin Bar
* Added: Russian localization, thank you Alexander
* Updated: 'human' detection is now relying mainly on COOKIES, Javascript is only needed to detect screen resolution and other browser-related information
* Updated: various performance improvements in the tracking code (more than halved DB accesses needed to record a new hit)
* Updated: one of the database tables is not needed anymore, deactivate/activate WP SlimStat to get rid of it
* Updated: it's now much easier to update the geolocation data, no need to deactivate/activate WP Slimstat anymore
* Updated: RTL (right-to-left) support has been greatly improved (thank you Itamar)
* Updated: color-codes have a better contrast now (thank you [Steve](http://www.webcommons.biz/))
* Updated: Browscap library has been updated to version 1.0
* Fixed: 'is not empty' filter wasn't working properly
* Fixed: heuristic browser detection for mobile browsers is now much more accurate
* Geolocation: updated to February 2012, 160222 rows. Go to Options > Maintenance > Reset Ip-to-Countries.

= 2.6 =
* Added: Visit Duration panel under the Visitors tab
* Added: a new column under Posts, with the number of pageviews per post (go to Settings > SlimStat > General to activate it)
* Added: option to customize the number of rows shown in the Raw Data view
* Updated: Browscap Engine to the latest release available (November 2011)
* Updated: Minor aesthetic changes to the interface
* Fixed: bug in generating some internal URLs when redirects are in place (thank you Elio and Alexander)
* Fixed: error when parsing search strings with encoding different from UTF-8 (thank you Alexander)
* Fixed: detailed view for recent known visitors
* Geolocation: updated to January 2012, 158624 rows. Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

== List of donors in alphabetical order ==
[Andrea Pinti](http://andreapinti.com/), [Bluewave Blog](http://blog.bluewaveweb.co.uk/), [Caigo](http://www.blumannaro.net/), 
[Dennis Kowallek](http://www.adopt-a-plant.com), [Hans Schantz](http://www.aetherczar.com/), [Herman Peet](http://www.hermanpeet.nl/), [La casetta delle pesche](http://www.lacasettadellepesche.it/),
[Mobilize Mail](http://blog.mobilizemail.com/), Mora Systems, Neil Robinson, [Sahin Eksioglu](http://www.alternatifblog.com/),
[Saill White](http://saillwhite.com), [SpenceDesign](http://spencedesign.com/), Wayne Liebman

= Database usage =
WP SlimStat needs to create its own tables in order to maintain the complex information about visits, visitors, browsers and Countries. It creates 2 new tables for each blog, plus 3 shared tables (5 tables in total, for a single-user installation). Please keep this in mind before activating WP SlimStat on large networks of blogs.

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. 