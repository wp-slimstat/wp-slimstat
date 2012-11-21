=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: chart, analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, wassup, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, flot, stalker, statistics, google+, monitor, seo
Requires at least: 3.1
Tested up to: 3.4.2
Stable tag: 2.8.4

== Description ==
A powerful real-time web analytics plugin for Wordpress.

= Main Features =
* Real-time web analytics reports
* Modern, easy to use and customizable interface (yep, you can move boxes around and hide the ones you don't need)
* Track people who leave comments on your site or click on you Google Plus One and Facebook Like buttons
* Fully compliant with European Privacy Laws (IP Anonymizer)
* The best country, browser and platform detection ever seen
* It includes GeoLite data created by [MaxMind](http://www.maxmind.com/)
* Drill-down capabilities: filter your stats based on IP addresses, browsers, referrers, users and much more
* View and admin access can be restricted to specific users
* Pan-and-zoom World Map, courtesy of [amMap](http://www.ammap.com/).

= Available data =
* Right Now: a detailed view of the most recent activity on your site (Username, Public IP, Private IP, Browser, Referring URL, Visit Session, Resources accessed, and much more)
* Overview: Daily/Hourly Pageviews (chart), Currently Connected, Spy View, Recent Known Visitors, Traffic Sources Overview, Recent Search Terms, Popular Posts, Recent Countries, Recent/Top Languages
* Visitors: Daily/Hourly Human Visits, Visit Duration, Bots, Top User Agents, Top Operating Systems, Top IP Addresses, Top Screen Resolutions, Colordepths, Browser Plugins (Flash, Acrobat, etc), Top Countries
* Content: Daily/Hourly Average Pageviews per visit, Outbound Links, Recent Events, Recent URLs, Recent Bounce Pages, Recent Feeds, Recent 404 URLs, Recent Internal Searches, Top Categories
* Traffic Sources: Daily/Hourly Traffic Sources, Top Search Terms, Top Traffic Sources, Top Search Engines, Top Sites, Correlation between search terms and pages, Unique Referrers
* World Map: pan and zoom to get detailed visual information about your visitors
* Custom Reports: bring your own report!

= What are people saying about WP SlimStat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* WP SlimStat has been really good - I'm really pleased with it and am using it on 6 websites - [kieronam](http://wordpress.org/support/topic/plugin-wp-slimstat-possible-to-suppress-cookies?replies=5)
* I like SlimStat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Thanks again for your help, all is well now, this plugin is epic - [thescarletfire](http://wordpress.org/support/topic/plugin-wp-slimstat-just-updated-and-now-a-problem)
* I have a portal of twelve property agencies, where they list their properties with WP-Property, and they always asked if it was possible to get individual statistics per agency (regular WP users, something Jetpack can't do), and your plugin handled this nice and easy! - [Raphael Suzuki](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I love this plugin. Been using it for the better part of a year now - [mikeambrosio](http://wordpress.org/support/topic/plugin-wp-slimstat-visual-chart-stopped-working)
* With JetPack, my page views are between zero or 20 on the average [..] With WP SlimStat I saw page views went up to an average of 200 per day for five days now and unique visitors are on the rise as well - [h2ofilters](http://wordpress.org/support/topic/plugin-wp-slimstat-why-does-is-data-different-from-jetpack-or-wpstats#post-2831769)
* What i liked so much on your slimstats is the very accurate hourly realtime stats [quakesos](http://wordpress.org/support/topic/plugin-wp-slimstat-w3-total-cache#post-2043814)

= Requirements =
* Wordpress 3.1 or higher (it may not work on large multisite environments)
* PHP 5.1 or higher
* MySQL 5.0.3 or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 5 Mb of free memory for the tracker

= Browser Compatibility =
WP SlimStat uses HTML5 canvases to display its charts. This approach is not compatible with Internet Explorer 8 or older, so you're encouraged to upgrade your browser.

You may also like [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/), which allows you to share your reports with your readers.

== Installation ==

1. Go to Plugins > Add New
2. Search for WP SlimStat
3. Click on Install Now under WP SlimStat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. To customize all the plugin's options, go to Settings > Slimstat
6. If you're blocking access to your `wp-content` folder, move `wp-slimstat-js.php` to a different folder, and edit its first line of code to let WP SlimStat know where your `wp-config.php` is

== Frequently Asked Questions ==

= My screen goes blank when trying to access the reports / after installing WP SlimStat =
Try [increasing the amount of memory](http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP) allocated to PHP. WP SlimStat requires about 5 Mb of RAM to track a pageview.

= When trying to access amy of the SlimStat options in the backend, I get the following error: You do not have sufficient permissions to access this page. =
You were playing with the plugin's permission settings, weren't you? But don't worry, there's a secret passage that will allow you to unlock your access. Create a new user `slimstatadmin`, and assign him the Administrator role. Then log into your WordPress admin area with the new user and... voila: you can now access WP SlimStat's settings again. Update your users' permissions and then get rid of this newly created user.

= I'm very surprised by the discrepancies between Google Analytics, Jetpack Stats and Slimstat. Why is that? =
Both Jetpack and GA use Javascript to track visitors. All the other pageviews are ignored, because search engines and other crawlers don't execute that client-side code.

WP SlimStat, on the other side, has a server-based tracking engine, which can capture *all* of your visitors, both 'humans' and 'bots'. That's the main reason why you may see more (some times much more) traffic recorded by WP SlimStat. 

= Can I track clicks and other events happening on the page? =
Yes, you can. This plugin includes a Javascript function that can be attached to any event: click, mouseover, focus, keypress, etc. Here's the syntax:

`ss_track(e, c, n)`

Where:

* `e` is the event that was triggered (the word 'event' *must* be used when attaching event handlers to HTML tags, see examples below)
* `c` is a numeric value between 1 and 254 (zero is reserved for outbound clicks)
* `n` is a custom text (up to 512 chars long) that you can use to add a note to the event tracked. If the ID attribute is defined, and no note has been specified, the former will be recorded. If the function is attached to a key-related event, the key pressed will be recorded.

Examples:

* `onclick="if(typeof ss_track == 'function') ss_track(event, 5, 'clicked on first link');"`
* `onkeypress="if(typeof ss_track == 'function') ss_track(event, 20);"`
* To make your life easier, a *Google Plus One* callback function is included as well: `<g:plusone callback="slimstat_plusone"></g:plusone>`. They will be identified by the note 'google-plus-on/off'. Pleae refer to the [official documentation](https://developers.google.com/+/plugins/+1button/) for more information.

= How do I use all those filters in the dropdown menu? =
Here's a brief description of their meaning. Please remember that you can access the same information directly from within the admin, by 'pulling' the Help tab that should appear in the top right hand corner.

Basic filters:

* `browser`: user agent (Firefox, Chrome, ...)
* `country code`: 2-letter code (us, ru, de, it, ...)
* `referring domain`: domain name of the referrer page (i.e., www.google.com if a visitor was coming from Google)
* `ip`: visitor's public IP address
* `search terms`: keywords visitors used to find your website on a search engine
* `language code`: please refer to the [language culture names](http://msdn.microsoft.com/en-us/library/ee825488(v=cs.20).aspx) (first column) for more information
* `operating system`: accepts identifiers like win7, win98, macosx, ...; please refer to [this manual page](http://php.net/manual/en/function.get-browser.php) for more information about these codes
* `permalink`: URL accessed on your site
* `referer`: complete URL of the referrer page
* `visitor's name`: visitor's name according to the cookie set by Wordpress after leaving a comment

Advanced filters:

* `browser capabilities`: plugins or extensions installed by that user (flash, java, silverlight...)
* `browser version`: user agent version (9.0, 11, ...)
* `browser type`: 1 = search engine crawler, 2 = mobile device, 3 = syndication reader, 0 = all others
* `color depth`: visitor's screen's color depth (8, 16, 24, ...)
* `css version`: what CSS standard was supported by that browser (1, 2, 3 and other integer values)
* `pageview attributes`: this field is set to [pre] if the resource has been accessed through Link Prefetching or similar techniques
* `post author`: author associated to that post/page when the resource was accessed
* `post category id`: ID of the category/term associated to the resource, when available
* `private ip`: visitor's private IP address, if available
* `resource content type`: post, page, cpt:*custom-post-type*, attachment, singular, post_type_archive, tag, taxonomy, category, date, author, archive, search, feed, home; please refer to the [Conditional Tags](http://codex.wordpress.org/Conditional_Tags) manual page for more information
* `screen resolution`: viewport width and height (1024x768, 800x600, ...)
* `visit id`: generally used in conjunction with 'is not empty', identifies human visitors


= How do I create my own custom reports? =
You will need to embed them in a plugin that leverages WP SlimStat APIs to retrieve the data. You can also access WP SlimStat's tables directly, for more complicated stuff.
Please refer to the database description and API reference guide here below for more information on what tables/methods are available.

Let's say you came up with your own SQL query, something like

`SELECT resource, COUNT(*) countresults
FROM $this->table_stats
WHERE resource <> ''
GROUP BY resource
ORDER BY countresults DESC
LIMIT 0,20`

Just write a function that gets the results and displays them, making sure to use the same HTML mark-up shown in the example here below:

`public function my_cystom_report() {
	$sql = "SELECT ...";
	$results = $wpdb->get_results($sql, ARRAY_A);

	// Boxes come in three sizes: wide, medium, normal (default).
	wp_slimstat_boxes:box_header('my_custom_box_id', 'My Custom Box Inline Help', 'medium', false, '', 'My cool report');

	foreach($results as $a_result){
		echo "<p>{$a_result['resource']} <span>{$a_result['countresults']}</span></p>";
	}
	
	wp_slimstat_boxes:box_footer(); // closes the DIV's open by box_header
}`

Then let WP SlimStat know about it:

`add_action('wp_slimstat_custom_report', 'my_cystom_report');`

Save your file as `my_custom_report.php` and then [follow these instructions](http://codex.wordpress.org/Writing_a_Plugin#Standard_Plugin_Information) on how to make a plugin out of that file.

= Can I disable outbound link tracking on a given link? =
Yes, you can. This is useful if you notice that, after clicking on a Lightbox-powered thumbnail, the image doesn't open inside the popup window as expected.
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

= Why can't WP SlimStat track visitors using IPv6? =
IPv6 support, as of today, is still really limited both in PHP and MySQL. There are a few workaround that could be implemented, but the end
result would be to complicate the DB structure, add overhead for tracking regular requests, and have a half-baked product.

= How do I stop WP SlimStat from tracking spammers? =
Go to Settings > SlimStat > Filters and set "Ignore Spammers" to YES.

= How do I stop WP SlimStat from recording new visits on my site? =
Go to Settings > SlimStat > General and set "Activate tracking" to NO.

= Can I add/show reports on my website? =
Yes, you can. WP SlimStat offers two ways of displaying its reports on your website.

*Via shortcodes*

Please download and install [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) to enable shortcode support in WP SlimStat.

*Using the API*

You will need to edit your template and add something like this where you want your metrics to appear:

`// Load WP SlimStat DB, the API library exposing all the reports
require_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/wp-slimstat-db.php');

// Initialize the API. You can pass a filter in the options, i.e. show only hits by people who where using Firefox, any version
wp_slimstat_db::init('browser contains Firefox');

// Use the appropriate method to display your stats
echo wp_slimstat_db::count_records('1=1', '*', false);`

*Available methods*

* `count_records($where_clause = '1=1', $column = '*', $use_filters = true)` - returns the number of records matching your criteria
 * **$where_clause** is the one used in the SQL query
 * **$column**, if specified, will count DISTINCT values
 * **$use_filters** can be true or false, it enables or disables previously set filters (useful to count ALL the records in the database, since by default a filter for the current month is enabled)
* `count_bouncing_pages()` - returns the number of [pages that 'bounce'](http://en.wikipedia.org/wiki/Bounce_rate#Definition)
* `count_exit_pages()` - returns the number of [exit pages](http://support.google.com/analytics/bin/answer.py?hl=en&answer=2525491)
* `get_recent($column = 'id', $custom_where = '', $join_tables = '', $having_clause = '')` - returns recent results matching your criteria
 * **$column** is the column you want group results by
 * **$custom_where** can be used to replace the default WHERE clause
 * **$join_tables** by default, this method return all the columns of wp_slim_stats; if you need to access (join) other tables, use this param to list them: `tb.*, tss.*, tci.*`
 * **$having_clause** can be used to further filter results based on aggregate functions
* `get_popular($column = 'id', $custom_where = '', $join_tables = '')`
 * **$column** is the column you want group results by
 * **$custom_where** can be used to replace the default WHERE clause
 * **$_more_columns** to 'group by' more than one column and return the corresponding rows

*Examples*

Recent Posts: 

`wp_slimstat_db::init('content_type equals post');
$results = wp_slimstat_db::get_recent('t1.resource');
foreach ($results...`

Top Languages last month:

`wp_slimstat_db::init('month equals '.date('n', strtotime('-1 month')));
$results = wp_slimstat_db::get_popular('t1.language');
foreach ($results...`

*Database description (see wp-slimstat-admin.php)*

wp_slim_stats t1

`id INT UNSIGNED NOT NULL auto_increment,
ip INT UNSIGNED DEFAULT 0,
other_ip INT UNSIGNED DEFAULT 0,
user VARCHAR(255) DEFAULT '',
language VARCHAR(5) DEFAULT '',
country VARCHAR(2) DEFAULT '',
domain VARCHAR(255) DEFAULT '',
referer VARCHAR(2048) DEFAULT '',
searchterms VARCHAR(2048) DEFAULT '',
resource VARCHAR(2048) DEFAULT '',
browser_id SMALLINT UNSIGNED NOT NULL DEFAULT 0,
screenres_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
content_info_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
plugins VARCHAR(255) DEFAULT '',
notes VARCHAR(512) DEFAULT '',
visit_id INT UNSIGNED NOT NULL DEFAULT 0,
dt INT(10) UNSIGNED DEFAULT 0,
PRIMARY KEY id (id)`

wp_slim_countries tc

`ip_from INT UNSIGNED DEFAULT 0,
ip_to INT UNSIGNED DEFAULT 0,
country_code CHAR(2) DEFAULT '',
CONSTRAINT ip_from_idx PRIMARY KEY (ip_from, ip_to)`

wp_slim_browsers tb

`browser_id SMALLINT UNSIGNED NOT NULL auto_increment,
browser VARCHAR(40) DEFAULT '',
version VARCHAR(15) DEFAULT '',
platform VARCHAR(15) DEFAULT '',
css_version VARCHAR(5) DEFAULT '',
type TINYINT UNSIGNED DEFAULT 0,
PRIMARY KEY (browser_id)`

wp_slim_screenres tss

`screenres_id MEDIUMINT UNSIGNED NOT NULL auto_increment,
resolution VARCHAR(12) DEFAULT '',
colordepth VARCHAR(5) DEFAULT '',
antialias BOOL DEFAULT FALSE,
PRIMARY KEY (screenres_id)`

wp_slim_content_info tci

`content_info_id MEDIUMINT UNSIGNED NOT NULL auto_increment,
content_type VARCHAR(64) DEFAULT '',
category VARCHAR(256) DEFAULT '',
author VARCHAR(64) DEFAULT '',
PRIMARY KEY (content_info_id)`

wp_slim_outbound to

`outbound_id INT UNSIGNED NOT NULL auto_increment,
outbound_domain VARCHAR(255) DEFAULT '',
outbound_resource VARCHAR(2048) DEFAULT '',
type TINYINT UNSIGNED DEFAULT 0,
notes VARCHAR(512) DEFAULT '',
position VARCHAR(32) DEFAULT '',
id INT UNSIGNED NOT NULL DEFAULT 0,
dt INT(10) UNSIGNED DEFAULT 0,
PRIMARY KEY (outbound_id)`

= I've noticed that the file `view.css` contains numerous base64 encoded strings, which I find kind of alarming. Is this normal? =

I'm particularly serious when it comes to data integrity and safety, and I always try to put the best of my knowledge to make sure WP SlimStat is secure and doesn't carry any vulnerability.
What you found in the CSS is a common technique used by experienced web developers to optimize site performances by 'embedding' small images (mainly icons)
directly into the CSS, thus avoiding extra requests to the server to download all the media elements referenced by the page.
You can get more information about this technique on [Wikipedia](http://en.wikipedia.org/wiki/Data_URI_scheme).

== Screenshots ==

1. What's happening right now on your site
2. All the information at your fingertips
3. Configuration panels offer flexibility and plenty of options
4. Mobile view, to keep an eye on your stats on the go
5. Access your stats from within Wordpress for iOS

== Changelog ==

= Planned features =
* Add "internal" stats about your blog: post count, comments per post, table sizes, etc
* Javascript-based tracking functionality (a-la Google Analytics), that plays nicely with W3 Total Cache & co.
* Antiflood monitor and database monitor

= 2.8.4 =
* Fixed: SQL deadlock when tracking a new pageview (thank you Wordpressian.com)
* Fixed: SQL bug in WP SlimStat Dashboard
* Fixed: Custom Report demo was not using the new API (thank you [MongooseDoom](http://wordpress.org/support/topic/custom-report-demo-out-of-date))
* Fixed: Javascript tracker compatibility with older versions of Internet Explorer (thank you [Ov3rfly](http://wordpress.org/support/topic/javascript-error-object-does-not-support-with-ie7ie6))
* Updated: New UNIQUE indexes are now used on lookup tables

= 2.8.3 =
* Added: more browsers' icons; if your favorite browser's icon is missing, let me know and I'll add it
* Added: got locked out of the settings page? We got a solution for that (see the FAQs)
* Added: it's now easier to ignore visits coming from your own website (thank you, [Ovidiu](http://wordpress.org/support/topic/plugin-wp-slimstat-small-feature-request))
* Added: ignore users by their role/capability (thank you, [Ovidiu](http://wordpress.org/support/topic/plugin-wp-slimstat-inspiration-for-future-versions))
* Added: if "Ignore Spammers" is set to Yes, and you mark a comment as spam, the corresponding pageviews will be removed from the database (thank you, [Ovidiu](http://wordpress.org/support/topic/plugin-wp-slimstat-inspiration-for-future-versions))
* Added: CDN support, to load the javascript tracking code from [JSDelivr](http://www.jsdelivr.com/), a reliable and fast CDN network for developers (free of charge)
* Added: filter by year (just enter the YEAR in the corresponding input box) and by hour (click on a data point in the 'Hourly' chart, to activate it)
* Added: chart annotations (thank you, [Thomas](http://wordpress.org/support/topic/plugin-wp-slimstat-feature-request-date-markers-in-graph))
* Fixed: a minor bug when setting both the 'other_ip' and 'ip' filters
* Fixed: URL encoding of Persian and other non-western addresses (thank you [mehotkhan](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstas-dont-work-with-unicode-url?replies=10))
* Fixed: tag names where not being displayed properly (thank you [gosunatxrea](http://shdb.info/))
* Fixed: some issues with browsers and operating systems' names (upper case, lower case)
* Fixed: layout issues on the Maintenance screen
* Fixed: stats can now be accessed via [Wordpress for iOS 3.1](http://ios.wordpress.org/)
* Fixed: date filters were not working as expected in some cases, hopefully now they do (thank you, Thomas)
* Updated: German localization is now pretty much up to date, thanks to [HAL-9000](http://wordpress.org/support/topic/plugin-wp-slimstat-translation-de)
* Updated: Geolocation service page now opens in a modal window
* Updated: permissions are now easier to configure and understand
* Updated: Browscap Database (Version: 5009 - Released: Monday, July 30, 2012 at 8:00 PM UTC)
* Updated: Geolocation Database (August 2012, 169652 rows). Go to Options > Maintenance > Reset Ip-to-Countries.

= 2.8.2 =
* Fixed: empty Right Now screen reported by some users
* Fixed: Javascript error on Dashboard Widgets (thank you [astereo](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-dash-widgets-js-issue?replies=6#post-2917386))
* Fixed: in some specific cases, the IP Lookup URL was not initialized

= 2.8.1 =
* Added: new filters
* Added: counter for currently connected users
* Added: a brand-new contextual help, for those overwhelmed by all the information provided by WP SlimStat
* Added: "Show on screen" functionality, to hide/show panels and further customize the layout of WP SlimStat views
* Added: IP Anonymizer functionality (Settings > SlimStat > Filters). Thank you [Hebbet](http://wordpress.org/support/topic/plugin-wp-slimstat-anonymize-ip-addresses) and [johannes.b](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Added: QuickTime (browser extension) is now being tracked
* Added: tons of icons and flags (for Countries, browsers, operating systems and plugins), to make the interface even more intuitive
* Fixed: a bug in the activation procedure was preventing, in some cases, WP SlimStat from creating its tables
* Fixed: the Dashboard Widgets plugin now follows the permission settings to view the stats
* Fixed: stats in Posts screen were not activating the right filter
* Fixed: a bug in the function to display the tooltips in the chart
* Updated: it's now much easier to write your own custom reports
* Updated: rewritten this readme and elaborated on many 'hidden' details to make WP SlimStat easier to use
* Updated: rearranged the overall folder structure, now easier to understand
* Updated: the 'engine' to visualize your stats has been completely rewritten and modularized
* Updated: using static PHP classes for improved performance
* Updated: [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) has been revamped, check it out!
* Updated: halved the amount of memory needed to identify user agents (while keeping the usual sharp, accurate information)
* Updated: color and styling changes
* Updated: weekends are now highlighed in the chart
* Updated: rearranged the order of the main tabs to give more emphasis to visitors and what's happening right now (thank you Jennifer)
* Updated: filters on Authors and Category IDs are now much more efficient

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
* Updated: Geolocation Database (March 2012, 173415 rows). Go to Options > Maintenance > Reset Ip-to-Countries.

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

(Previous versions omitted)

== Donors ==
[Andrea Pinti](http://andreapinti.com/), [Bluewave Blog](http://blog.bluewaveweb.co.uk/), [Caigo](http://www.blumannaro.net/), 
[Dennis Kowallek](http://www.adopt-a-plant.com), [Damian](http://wipeoutmedia.com/), [Hans Schantz](http://www.aetherczar.com/), Hajrudin Mulabecirovic,
[Herman Peet](http://www.hermanpeet.nl/), John Montano, [La casetta delle pesche](http://www.lacasettadellepesche.it/), [Mobilize Mail](http://blog.mobilizemail.com/),
Mora Systems, Neil Robinson, [Ovidiu](http://pacura.ru/), [Sahin Eksioglu](http://www.alternatifblog.com/), [Saill White](http://saillwhite.com),
[Sharon Villines](http://sociocracy.info), [SpenceDesign](http://spencedesign.com/), Stephane Sinclair, [The Parson's Rant](http://www.howardparsons.info), Wayne Liebman

== Special Thanks To ==

* [Thomas Nielsen](http://www.bogt.dk/), for his generous donation and for helping with the new icon set.

== Database usage ==
WP SlimStat uses its own tables in order to maintain the complex information about visits, visitors, browsers and Countries. It adds 2 new tables for each blog, plus 4 shared tables (6 tables in total, for a single-user installation). Please keep this in mind before activating WP SlimStat on large networks of blogs.

== Dashboard Widgets == 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. 