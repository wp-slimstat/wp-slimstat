=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: chart, analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, wassup, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, flot, stalker, statistics, google+, monitor, seo
Requires at least: 3.1
Tested up to: 3.5.1
Stable tag: 3.0

== Description ==
A powerful real-time web analytics plugin for Wordpress. Visit the [official site](http://slimstat.duechiacchiere.it) for more information.

= Main Features =
* Real-time web analytics reports
* Compatible with W3 Total Cache and friends
* Modern, easy to use and customizable interface (yep, you can move boxes around and hide the ones you don't need)
* Complies with European Privacy Laws (IP Anonymizer)
* The most accurate ip geolocation, browser and platform detection ever seen (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://tempdownloads.browserscap.com/))
* Comb through your data with advanced filters (IP addresses, browsers, search terms, users and much more)
* View and admin access can be restricted to specific users
* Pan-and-zoom JavaScript World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about WP SlimStat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like SlimStat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
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
WP SlimStat uses the HTML5 Canvas element and SVG graphics to display its charts and the world map. Unfortunately Internet Explorer 8 and older versions don't support them, so you're encouraged to upgrade your browser.

= Free Add-ons =
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) allows you to share your reports with your readers
* [WP SlimStat Dashboard Widgets](http://wordpress.org/extend/plugins/wp-slimstat-dashboard-widgets) adds the most important reports to your WordPress Dashboard

== Installation ==

1. Go to Plugins > Add New
2. Search for WP SlimStat
3. Click on Install Now under WP SlimStat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. To customize all the plugin's options, go to Settings > Slimstat
6. If your wp-admin folder is not publicly accessible, please make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

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
Don't forget to invalidate/clear your plugin's cache, to let SlimStat add its tracking code to all the newly cached pages.

= I am using W3 Total Cache and after upgrading WP SlimStat, no visit are being tracked. How so? =
Please clear your plugin's cache, in order for WP SlimStat to add the new tracking code to all the pages.

= My screen goes blank when trying to access the reports / after installing WP SlimStat =
Try [to increase the amount of memory](http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP) allocated to PHP. If that doesn't help,
go to Settings > SlimStat > Maintenance and click on the Reset Tabs button.

= When trying to access any of options screens, I get the following error: You do not have sufficient permissions to access this page. =
You were playing with the plugin's permission settings, weren't you? But don't worry, there's a secret passage that will allow you to unlock your access. Create a new user `slimstatadmin`, and assign him the Administrator role. Then log into your WordPress admin area with the new user and... voila: you can now access WP SlimStat's settings again. Update your users' permissions and then get rid of this newly created user.

= I am using WP Touch, and mobile visitors are not tracked by your plugin. How can I fix this problem? =
WP Touch has an advanced option that they call Restricted Mode, which attempts to fix issues where other plugins load scripts which interfere with WPtouch CSS and JavaScript. If you enable this feature, it will prevent WP SlimStat from running the tracking script (thank you, [Per](http://wordpress.org/support/topic/known-users-not-logged)).

= Why do I see discrepancies between Google Analytics, Jetpack Stats and Slimstat? =
Both Jetpack and GA use Javascript to track visitors. All the other pageviews are ignored, because search engines and other crawlers don't execute that client-side code.

WP SlimStat, on the other side, has a server-based tracking engine, which can capture *all* of your visitors, both 'humans' and 'bots'. That's the main reason why you may see more (some times much more) traffic recorded by WP SlimStat. 

= I noticed that the file `view.css` contains numerous base64 encoded strings, which I find kind of alarming. Is this normal? =
I'm particularly serious when it comes to data integrity and safety, and I always try to put the best of my knowledge to make sure WP SlimStat is secure and doesn't carry any vulnerability.
What you found in the CSS is a common technique used by experienced web developers to optimize site performances by 'embedding' small images (mainly icons)
directly into the CSS, thus avoiding extra requests to the server to download all the media elements referenced by the page.
You can get more information about this technique on [Wikipedia](http://en.wikipedia.org/wiki/Data_URI_scheme).

= How can I change the colors associated to color-coded pageviews (known user, known visitors, search engines, etc)? =
Go to Settings > SlimStat > View tab and paste your custom CSS into the corresponding field. Use the following code as a reference:

`.postbox p.is-search-engine,
.legend span.is-search-engine{background-color:#C1E751;color:#444}

.postbox p.is-direct,
.legend span.is-direct{background-color:#D0E0EB;color:#111}

.postbox p.is-known-user,
.legend span.is-known-user{background-color:#F1CF90}

.postbox p.is-known-visitor,
.legend span.is-known-visitor{background-color:#EFFD8C}

.postbox p.is-spam,
.legend span.is-spam{background-color:#AAB3AB;color:#222}`

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

== Screenshots ==

1. What's happening right now on your site
2. All the information at your fingertips
3. Configuration panels offer flexibility and plenty of options
4. Mobile view, to keep an eye on your stats on the go
5. Access your stats from within Wordpress for iOS

== Changelog ==

= 3.0 =
* Added: a brand new client-side tracker, which replaces the previous one after 3 years of honorable duty. The new engine implements Wordpress Core Developers' guidelines on leveraging WP's built-in Ajax functionality, thus eliminating the need for custom hacks and non-standard implementations
* Added: SERP positions are now displayed also under the Overview tab (thank you, Richie)
* Added: you can finally customize those ugly colors associated to known users, search engines, etc. Check the FAQs to learn how.
* Added: login page (wp-login.php) is now tracked
* Added: two new powerful filters to leverage [MySQL Regular Expressions' power](http://dev.mysql.com/doc/refman/5.1/en/regexp.html): matches and does_not_match. Try for example to filter your stats where browser matches fire|chro ;) (thank you, [carbeck](http://wordpress.org/support/topic/feature-request-regex-in-show-records-where…-filters))
* Added: you can now filter your metrics by post ID, for those who change their permalinks every now and then
* Added: a few new metrics (Top Tags, Recent Downloads, Top Downloads). You may need to reset your Tabs in order to see the new boxes (go to Settings > Maintenance > Reset Tabs)
* Added: a brand-new set of hooks for your actions and filters. Take a look at the [Documentation](http://slimstat.duechiacchiere.it/documentation/) for more information, or see [how you can use them](http://wordpress.org/support/topic/plugin-wp-slimstat-possible-to-suppress-cookies#post-4016035) to customize WP SlimStat's behavior
* Added: new option to expand each row's details by default (thank you, [pedjas](http://wordpress.org/support/topic/can-we-get-absolute-count-numbers-in-stats))
* Updated: a brand new JavaScript-based World Map replaces the existing Flash map, so that your mobile device can be happy now
* Updated: admin menus have been consolidated and simplified (bye bye link under Dashboard, hello dropdown menu in the admin bar)
* Updated: plugin files have been consolidated as well, making the structure much easier to manage and more flexible
* Updated: viewer API has been partially rewritten and code optimizations have been implemented (which should make your stats appear faster)
* Updated: after the nth complaint from a user who had deleted WP SlimStat by mistake, the Dashboard Widgets add-on is now available as a [separate plugin on the repository](http://wordpress.org/extend/plugins/wp-slimstat-dashboard-widgets/)
* Updated: world map has been updated (thank you, [intrepidkarthi](http://wordpress.org/support/topic/indian-border-is-shown-wrongly-in-the-world-map-stats))
* Updated: search terms detection is now more accurate when JavaScript Mode is turned on
* Updated: localization files have been consolidated and are now easier to maintain
* Updated: browscap.ini (browser database), v5018 - February 2013
* Fixed: minor bugs affecting the tracker
* Fixed: chart is using the correct max Y-axis when using intervals (thank you, [MGmirkin](http://wordpress.org/support/topic/feature-request-scale-graph-of-specific-date-range-by-views-during-that-range))
* Fixed: bug that made all the stats disappear after activating the standalone menu (thank you, [Davide](http://www.davidetomasello.it/))
* Fixed: bug in filtering Top Pages (thank you, [TechnoViel](http://wordpress.org/support/topic/slimstat-showing-old-hits-in-current-month-day-but-not-when-filtering-by-page))
* Launched: [WP SlimStat](http://slimstat.duechiacchiere.it/) official website is now live (and growing)

= 2.9.5 =
* Fixed: vulnerability that would allow visitors to inject javascript code into the admin, under specific circumstances (thank you, [mikes88](http://wordpress.org/support/topic/sanitize-the-plugin))

= 2.9.4 =
* Fixed: bug that prevented the 'Limit Results' option from working properly (thank you, Romain Petges)
* Fixed: a bug was preventing those users who were using the Refresh Interval feature from visualizing the stats
* Fixed: a bug affecting the filter on browsers and operating system in the Right Now tab (thank you, Davide)
* Updated: some new SQL optimizations have been implemented

= 2.9.3 =
* Updated: SQL optimizations have been implemented in the core DB APIs, your stats should now load a little faster
* Fixed: minor bugs in the core tracking functionality, affecting the way tags associated to posts were recorded (thank you, [Neil](http://wordpress.org/support/topic/categories-vs-tags) and [Davide](http://www.davidetomasello.it/))
* Updated: ip geolocation database (February 2013). Go to Settings > SlimStat > Maintenance tab > Update Geolocation DB to load the new data.

= 2.9.2 =
* Added: asynchronous views, to make your stats load faster (even on your Wordpress Dashboard). Enable it under Settings > Views
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

== Donors ==
[7times77](http://7times77.com),
[Andrea Pinti](http://andreapinti.com/),
[Bluewave Blog](http://blog.bluewaveweb.co.uk/),
[Caigo](http://www.blumannaro.net/), 
[Dennis Kowallek](http://www.adopt-a-plant.com),
[Damian](http://wipeoutmedia.com/),
[Edward Koon](http://www.fidosysop.org/),
[Gabriela Lungu](http://www.cosmeticebio.org/),
Gary Swarer,
Giacomo Persichini,
Hal Smith,
[Hans Schantz](http://www.aetherczar.com/),
Hajrudin Mulabecirovic,
[Herman Peet](http://www.hermanpeet.nl/),
John Montano, 
[La casetta delle pesche](http://www.lacasettadellepesche.it/),
[Mobilize Mail](http://blog.mobilizemail.com/),
Mora Systems,
Neil Robinson,
[Ovidiu](http://pacura.ru/),
[Sahin Eksioglu](http://www.alternatifblog.com/),
[Saill White](http://saillwhite.com),
[Sarah Parks](http://drawingsecretsrevealed.com/),
[Sharon Villines](http://sociocracy.info), 
[SpenceDesign](http://spencedesign.com/),
Stephane Sinclair,
[The Parson's Rant](http://www.howardparsons.info),
Wayne Liebman

== Special Thanks To ==

* [Davide Tomasello](http://www.davidetomasello.it/), for all the great feedback he sent me and the time spent looking for bugs and inconsistencies
* [Thomas Nielsen](http://www.bogt.dk/), for his generous donation and for helping with the new icon set.
