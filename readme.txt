=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: chart, analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, wassup, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, flot, stalker, statistics, google+, monitor, seo
Requires at least: 3.1
Tested up to: 3.5.1
Stable tag: 2.9.4

== Description ==
A powerful real-time web analytics plugin for Wordpress.

= Main Features =
* Real-time web analytics reports
* Compatible with W3 Total Cache and friends
* Modern, easy to use and customizable interface (yep, you can move boxes around and hide the ones you don't need)
* Complies with European Privacy Laws (IP Anonymizer)
* The most accurate ip geolocation, browser and platform detection ever seen (it includes GeoLite data created by [MaxMind](http://www.maxmind.com/))
* Comb through your data with advanced filters (IP addresses, browsers, search terms, users and much more)
* View and admin access can be restricted to specific users
* Pan-and-zoom World Map, courtesy of [amMap](http://www.ammap.com/).

= What are people saying about WP SlimStat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like SlimStat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* I have a portal of twelve property agencies, where they list their properties with WP-Property, and they always asked if it was possible to get individual statistics per agency (regular WP users, something Jetpack can't do), and your plugin handled this nice and easy! - [Raphael Suzuki](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* With JetPack, my page views are between zero or 20 on the average [..] With WP SlimStat I saw page views went up to an average of 200 per day for five days now and unique visitors are on the rise as well - [h2ofilters](http://wordpress.org/support/topic/plugin-wp-slimstat-why-does-is-data-different-from-jetpack-or-wpstats#post-2831769)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own

= Available analytics =
* Right Now: a detailed view of the most recent activity on your site (Username, Public IP, Private IP, Browser, Referring URL, Visit Session, Resources accessed, and much more)
* Overview: Daily/Hourly Pageviews (chart), Currently Connected, Spy View, Recent Known Visitors, Traffic Sources Overview, Recent Search Terms, Popular Posts, Recent Countries, Recent/Top Languages
* Visitors: Daily/Hourly Human Visits, Visit Duration, Bots, Top User Agents, Top Operating Systems, Top IP Addresses, Top Screen Resolutions, Colordepths, Browser Plugins (Flash, Acrobat, etc), Top Countries
* Content: Daily/Hourly Average Pageviews per visit, Outbound Links, Recent Events, Recent URLs, Recent Bounce Pages, Recent Feeds, Recent 404 URLs, Recent Internal Searches, Top Categories
* Traffic Sources: Daily/Hourly Traffic Sources, Top Search Terms, Top Traffic Sources, Top Search Engines, Top Sites, Correlation between search terms and pages, Unique Referrers
* World Map: pan and zoom to get detailed visual information about your visitors
* Custom Reports: bring your own report!

= Requirements =
* Wordpress 3.1 or higher (it may not work on large multisite environments; some users have reported problems in accessing the configuration page under Wordpress 3.3.x or earlier)
* PHP 5.1 or higher
* MySQL 5.0.3 or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 5 Mb of free memory for the tracker

= Browser Compatibility =
WP SlimStat uses HTML5 canvases to display its charts. Unfortunately Internet Explorer 8 and older version don't support canvases, so you're encouraged to upgrade your browser.

= Do more =
* Take a look at [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/), which allows you to share your reports with your readers.

== Installation ==

1. Go to Plugins > Add New
2. Search for WP SlimStat
3. Click on Install Now under WP SlimStat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. To customize all the plugin's options, go to Settings > Slimstat
6. If you moved your wp-config.php to a different location, or are blocking access to your wp-content folder, 
   please make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

== Frequently Asked Questions ==

= I see a warning message saying that a misconfigured setting and/or server environment is preventing WP SlimStat from properly tracking my visitors =
WP SlimStat's tracking engine has a server-side component, which records all the information available at the time the resource is served,
and a client-side component, which collects extra data from your visitors' browsers, like their screen resolution, (x,y) coordinates of their
clicks and the events they trigger. 

One of the files responsible for taking care of this is `admin-ajax.php`, usually located inside your */wp-admin/* folder.
Point your browser to that file directly: if you see an error 404 or 500, then you will need to fix that problem, to allow WP SlimStat to do its job.
If you see the number zero, then you're fine.

= I am using W3 Total Cache/WP Super Cache, and it looks like your plugin is not tracking all of my visitors. Can you help me? =
Simply go to Settings > SlimStat and enable the option Javascript Mode. WP SlimStat will only track human visitors (just like Google Analytics does, pretty much), but its accuracy will dramatically improve.

= My screen goes blank when trying to access the reports / after installing WP SlimStat =
Try [to increase the amount of memory](http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP) allocated to PHP. WP SlimStat requires about 5 Mb of RAM to track a pageview.

= When trying to access any of options screens, I get the following error: You do not have sufficient permissions to access this page. =
You were playing with the plugin's permission settings, weren't you? But don't worry, there's a secret passage that will allow you to unlock your access. Create a new user `slimstatadmin`, and assign him the Administrator role. Then log into your WordPress admin area with the new user and... voila: you can now access WP SlimStat's settings again. Update your users' permissions and then get rid of this newly created user.

= Why do I see discrepancies between Google Analytics, Jetpack Stats and Slimstat? =
Both Jetpack and GA use Javascript to track visitors. All the other pageviews are ignored, because search engines and other crawlers don't execute that client-side code.

WP SlimStat, on the other side, has a server-based tracking engine, which can capture *all* of your visitors, both 'humans' and 'bots'. That's the main reason why you may see more (some times much more) traffic recorded by WP SlimStat. 

= Can I track clicks and other events happening on the page? =
Yes, you can. This plugin includes a Javascript handler that can be attached to any event: click, mouseover, focus, keypress, etc. Here's the syntax:

`ss_track(e, c, n)`

Where:

* `e` is the event that was triggered (the word 'event' *must* be used when attaching event handlers to HTML tags, see examples below)
* `c` is a numeric value between 1 and 254 (zero is reserved for outbound clicks)
* `n` is a custom text (up to 512 chars long) that you can use to add a note to the event tracked. If the ID attribute is defined, and no note has been specified, the former will be recorded. If the function is attached to a key-related event, the key pressed will be recorded.

Examples:

* `onclick="if(typeof ss_track == 'function') ss_track(event, 5, 'clicked on first link');"`
* `onkeypress="if(typeof ss_track == 'function') ss_track(event, 20);"`
* To make your life easier, a *Google Plus One* callback function is included as well: `<g:plusone callback="slimstat_plusone"></g:plusone>`. Clicks on your Google+ button will be identified by the note 'google-plus-on/off'. Pleae refer to the [official documentation](https://developers.google.com/+/plugins/+1button/) for more information.

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

You can also use the corresponding setting in the Options > Advanced page to disable outbound link tracking for *all* the external links in your site.

= Why does WP SlimStat show more page views than actual pages clicked by a user? =
"Phantom" page views can occur when a user's browser does automatic feed retrieval,
[link pre-fetching](https://developer.mozilla.org/en/Link_prefetching_FAQ), or a page refresh. WP SlimStat tracks these because they are valid
requests from that user's browser and are indistinguishable from user link clicks. You can ignore these visits setting the corresponding option
in Settings > SlimStat > Filters

= Why can't WP SlimStat track visitors using IPv6? =
IPv6 support, as of today, is still really limited both in PHP and MySQL. There are a few workarounds that could be implemented, but this
would make the DB structure less optimized, add overhead for tracking regular requests, and you would have a half-baked product.

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

* `count_records($where_clause = '1=1', $column = '*', $use_filters = true, $use_date_filters = true)` - returns the number of records matching your criteria
 * **$where_clause** is the one used in the SQL query
 * **$column**, if specified, will count DISTINCT values
 * **$use_filters** can be true or false, it enables or disables previously set filters (useful to count ALL the records in the database, since by default a filter for the current month is enabled)
 * **$use_date_filters** can be set to false to count ALL the pageviews from the beginning of time
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

= I noticed that the file `view.css` contains numerous base64 encoded strings, which I find kind of alarming. Is this normal? =

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

= 2.9.4 =
* Fixed: a bug was preventing those users who were using the Refresh Interval feature from visualizing the stats
* Fixed: a bug affecting the filter on browsers and operating system in the Right Now tab (thank you, Davide)
* Updated: some new SQL optimizations have been implemented

= 2.9.3 =
* Fixed: minor bugs in the core tracking functionality, affecting the way tags associated to posts were recorded (thank you, [Neil](http://wordpress.org/support/topic/categories-vs-tags) and [Davide](http://www.davidetomasello.it/))
* Updated: SQL optimizations have been implemented, your stats should now load a little faster
* Updated: ip geolocation database (February 2013). Go to Settings > SlimStat > Maintenance tab > Update Geolocation DB to load the new data.

= 2.9.2 =
* Added: asynchronous views, to make your stats load faster (even on your Wordpress Dashboard). Enable them under Settings > Views
* Added: posts and pages' IDs are now being tracked as well, so that you can keep analyzing your traffic even if your permalink structure changes (thank you, [Clifford Paulick](http://wordpress.org/support/topic/current-posts-stats))

= 2.9.1 =
* Fixed: a few issues arose after the release of version 2.9, related to the new JavaScript Mode option introduced in that version. I would like to thank Ed Konn, Melinda Hightower and all the other users who patiently helped me figure out what the problem was and pointed me in the right direction. You guys rock!
* Fixed: cleaned up the plugin's stylesheet

= 2.9 =
* Added: you've been asking for it and here you have it: Javascript-based tracking functionality (a-la Google Analytics). Go the plugin's settings page to activate it. A nice side effect is that the option to ignore bots is now even more effective, check it out! (thank you, [Drew](http://wordpress.org/support/topic/feature-request-for-next-release))
* Added: the next generation of WP SlimStat's cornerstone has been set. A new Wordpress action 'slimstat_track_pageview' and three filters (one for the pageview, one for the content type and one for the browser tracker) are now called right before the data is stored into the database, thus allowing third-party tools to manipulate that information and tweak the tracker as they like
* Added: option to disable the Stats link associated to each post in the Edit Posts screen (thank you, [Andrzej](http://wordpress.org/support/topic/plugin-wp-slimstat-editquick-edittrashstats))
* Added: option to disable outbound/external link tracking (thank you, emrys)
* Added: outbound link tracking now records your links' TITLE attribute (thank you, emrys)
* Added: have you moved/renamed your wp-config.php and WP SlimStat is complaining about it? Not anymore: create a new file called wp-slimstat-config.php inside your wp-content to point my plugin to your wp-config
* Added: option to restrict authors to see only stats for their own content (thank you, [Zeb](http://wordpress.org/support/topic/includes-2))
* Added: Chinese localization (thank you, meme)
* Fixed: bug in generating the link to view stats for a specific post from the Edit Posts page (thank you, [dgunn](http://wordpress.org/support/topic/post-column-contains-incorrect-filters))
* Fixed: bug that prevented outbound clicks to be properly tracked (thank you, emrys)
* Fixed: conflict with Ajax event calendar plugin (thank you [saill](http://wordpress.org/support/topic/overview-and-visitors-graphs-contain-no-data))
* Fixed: bug in pagination under Right Now
* Fixed: Dashboard widgets were frozen if chart box was collapsed (thank you, [Nicole Parks](http://wordpress.org/support/topic/wp-slimstat-dashboard-widgets-frozen)) for your patience!)
* Fixed: RTL support has been updated and improved

= 2.8.7 =
* Added: two new options to customize the duration of your visitors' sessions, for those who believe that 30 minutes are not enough
* Fixed: usernames in filters are now allowed to have capital letters and spaces (thank you [Thomas Nielsen](http://www.bogt.dk/))
* Fixed: options would reset to the default values on reactivation (thank you [mysticscholar](http://wordpress.org/support/topic/auto-purge-keeps-resetting))
* Fixed: PHP warning when tag has no posts (thank you [wondible](http://wordpress.org/support/topic/array_pop-error-on-tag-with-no-posts))
* Fixed: wp-slimstat-js.php is now also looking for your wp-config.php in the parent folder (thank you [pixolin](http://wordpress.org/support/topic/error-misconfigured-setting-when-wp-configphp-outside-wp-root))
* Fixed: bug in calculating the Stats link for each post in the Edit Posts screen (thank you [dgunn](http://wordpress.org/support/topic/post-column-contains-incorrect-filters))
* Fixed: apparently $table_prefix in wp-config.php can be left empty, who knew! (thank you [Ian](http://wordpress.org/support/topic/a-misconfigured-setting-andor-server-environment-is-preventing-wp-slimstat-from))
* Fixed: Wordpress database deadlock (thank you [Texiwill](http://wordpress.org/support/topic/wordpress-database-error-deadlock-1))
* Updated: WP SlimStat will now track your visitors' screen resolution, not the size of their viewport anymore

= 2.8.6 =
* Added: a warning message is displayed if WP SlimStat's tracking engine is not working properly
* Added: link to see a page's stats directly on the Edit Pages screen (thank you JoseVega)
* Fixed: empty Right Now screen for some users
* Fixed: bug in calculating visit durations
* Updated: improved performance by using optimized SQL queries
* Updated: styles are now compatible with Wordpress 3.5
* Updated: ip geolocation database (December 2012, 104530 rows). Go to Settings > SlimStat > Maintenance tab > Update Geolocation DB to load the new data.

= 2.8.5 =
* Fixed: XSS vulnerability that could be used to inject javascript code into the admin (thank you [xssAlert](http://wordpress.org/support/topic/xss-vurnability))
* Fixed: Bug in the function that upgrades the environment and the options (thank you [thescarletfire](http://wordpress.org/support/topic/php-warning-stripos-expects-parameter-1-to-be-string-array-given))
* Fixed: Charsets other than UTF-8 in URLs are now detected more accurately (thank you [JakeM](http://wordpress.org/support/topic/foreign-languages-encoding-support-issue))
* Fixed: WP SlimStat Dashboard is now fully compatible with Wordpress MU (thank you [doublesharp](http://wordpress.org/support/topic/plugin-wp-slimstat-dashboard-widgets-not-working-on-multisite))
* Updated: improved heuristic browser detection

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

== Donors ==
[Andrea Pinti](http://andreapinti.com/), [Bluewave Blog](http://blog.bluewaveweb.co.uk/), [Caigo](http://www.blumannaro.net/), 
[Dennis Kowallek](http://www.adopt-a-plant.com), [Damian](http://wipeoutmedia.com/), Giacomo Persichini,
[Hans Schantz](http://www.aetherczar.com/), Hajrudin Mulabecirovic, [Herman Peet](http://www.hermanpeet.nl/), John Montano, 
[La casetta delle pesche](http://www.lacasettadellepesche.it/), [Laszlo Mihalka](http://7times77.com), [Mobilize Mail](http://blog.mobilizemail.com/),
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