=== WP Slimstat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Requires at least: 3.8
Tested up to: 4.0
Stable tag: 3.7.3

== Description ==
Visit our [website](http://slimstat.getused.to.it/) for more information, or find us on [GitHub](https://github.com/getusedtoit/wp-slimstat).

= Key Features =
* Real-time activity log
* The first and only to provide information about page speed and server latency
* Compatible with W3 Total Cache, WP SuperCache and HyperCache
* The most accurate IP geolocation, browser and platform detection ever seen (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin, Jean-Michel Venet, Yves Pouplard), German (TechnoViel), Italian, Japanese (h_a_l_f), Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman). Is your language missing or incomplete? [Contact Us](http://support.getused.to.it/) if you would like to share your localization.
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about Slimstat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like Slimstat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own

= Requirements =
* WordPress 3.8+
* PHP 5.3+
* MySQL 5.0.3+
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 4 Mb of free PHP memory for the tracker (peak memory usage)
* IE9+ or any browser supporting HTML5, to access the reports

= Premium Add-ons =
Visit [our website](http://slimstat.getused.to.it/addons/) for a list of available extensions.

= Free Add-ons =
* [WP Slimstat Dashboard Widgets](http://wordpress.org/extend/plugins/wp-slimstat-dashboard-widgets) adds the most important reports right on your WordPress Dashboard
* [WP Slimstat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) allows you to share your reports with your readers

== Installation ==

0. **If you are upgrading from 2.8.4 or earlier, you MUST first install version 3.0 (deactivate/activate) and then upgrade to the latest release available**
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP Slimstat
3. Click on **Install Now** under WP Slimstat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

Please note: if you decide to uninstall Slimstat, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

== Frequently Asked Questions ==

= I see a warning message saying that a misconfigured setting and/or server environment is preventing Slimstat from properly tracking my visitors =
Slimstat's tracking engine has a server-side component, which records all the information available at the time the resource is served,
and a client-side component, which collects extra data from your visitors' browsers, like their screen resolution, (x,y) coordinates of their
clicks and the events they trigger. 

One of the files handling all the client-server communications is WordPress' `admin-ajax.php`, usually located inside your */wp-admin/* folder.
Point your browser to that file directly: if you see an error 404 or 500, then you will need to fix that problem, to allow Slimstat to do its job.
If you see the number zero, then the problem could be related to a conflict with another plugin (caching, javascript minimizers, etc).

= I am using W3 Total Cache (or WP Super Cache, HyperCache, etc), and it looks like Slimstat is not tracking all of my visitors. Can you help me? =
Go to Slimstat > Settings > General and set Tracking Mode to Javascript. Don't forget to invalidate/clear your plugin's cache, to let Slimstat add its tracking code to all the newly cached pages.
Also, if you're using W3 Total Cache, make sure to exclude wp-slimstat.js from the minifier: our code is already minified, and it looks like W3TC breaks something when it tries to minify it again.

= My screen goes blank when trying to access the reports / after installing Slimstat =
Go to Slimstat > Settings > Maintenance and click the NO PANIC Button. If that doesn't help,
[increase the amount of memory](http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP) allocated to PHP.

= Reports look all messy and not styled =
Go to Slimstat > Settings > Maintenance and click the NO PANIC Button. If that doesn't help, make sure you don't have AdBlock installed and active in your browser. For some reason, that plugin doesn't like Slimstat.

= When trying to access any of options screens, I get the following error: You do not have sufficient permissions to access this page. =
You were playing with the plugin's permission settings, weren't you? But don't worry, there's a secret passage that will allow you to unlock your access. Create a new WordPress admin user named `slimstatadmin`. Then log into your WordPress admin area with the new user and... voila: you can now access Slimstat's settings again. Update your users' permissions and then get rid of this newly created user.

= I am using WP Touch, and mobile visitors are not tracked by your plugin. How can I fix this problem? =
WP Touch has an advanced option that they call Restricted Mode, which attempts to fix issues where other plugins load scripts which interfere with WPtouch CSS and JavaScript. If you enable this feature, it will prevent Slimstat from running the tracking script (thank you, [Per](http://wordpress.org/support/topic/known-users-not-logged)).

= How can I change the colors associated to color-coded pageviews (known user, known visitors, search engines, etc)? =
Go to Slimstat > Settings > Advanced tab and paste your custom CSS into the corresponding field. Use the following code as a reference:

`[id^=slim_] .header.is-search-engine, .is-search-engine{
	background-color:#c1e751;
	color:#444;
}
[id^=slim_] .header.is-direct, .is-direct{
	background-color:#d0e0eb;
	color:#111;
}
[id^=slim_] .header.is-known-user,.is-known-user{
	background-color:#F1CF90;
}
[id^=slim_] .header.is-known-visitor,.is-known-visitor{
	background-color:#EFFD8C;
}
[id^=slim_] .header.is-spam,.is-spam{
	background-color:#AAB3AB;
	color:#222;
}`

= Can I track clicks and other events happening on the page? =
Yes, you can. This plugin includes a Javascript handler that can be attached to any event: click, mouseover, focus, keypress, etc. Here's the syntax:

`SlimStat.ss_track(event, event_id, message)`

Where:

* `event` is the event that was triggered (the word 'event' *must* be used when attaching event handlers to HTML tags, see examples below)
* `event_id` is a numeric value between 1 and 254 (zero is reserved for outbound clicks)
* `message` is a custom message (up to 512 chars long) that can be used to add a note to the event tracked. If the ID attribute is defined, and no note has been specified, the former will be recorded. If the function is attached to a key-related event, the key pressed will be recorded.

Examples:

* `onclick="if(typeof SlimStat.ss_track == 'function') SlimStat.ss_track(event, 5, 'clicked on first link');"`
* `onkeypress="if(typeof SlimStat.ss_track == 'function') SlimStat.ss_track(event, 20);"`
* To make your life easier, a *Google Plus One* callback function is included as well: `<g:plusone callback="SlimStat.slimstat_plusone"></g:plusone>`. Clicks on your Google+ button will be identified by the note 'google-plus-on/off'. Pleae refer to the [official documentation](https://developers.google.com/+/plugins/+1button/) for more information.

= How do I use all those filters in the dropdown menu? =
Here's a brief description of what they mean. Please remember that you can access the same information directly from within the admin, by 'pulling' the Help tab that should appear in the top right hand corner.

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
* `visitor's name`: visitor's name according to the cookie set by WordPress after leaving a comment

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
You will need to embed them in a plugin that leverages Slimstat APIs to retrieve the data. You can also access Slimstat's tables directly, for more complicated stuff.
Please refer to the database description and API reference guide here below for more information on what tables/methods are available.

Let's say you came up with your own SQL query, something like

`SELECT resource, COUNT(*) countresults
FROM $this->table_stats
WHERE resource <> ''
GROUP BY resource
ORDER BY countresults DESC
LIMIT 0,20`

Just write a function that gets the results and displays them, making sure to use the same HTML markup shown here below:

`public function my_cystom_report() {
	$sql = "SELECT ...";
	$results = $wpdb->get_results($sql, ARRAY_A);

	// Reports come in two sizes: normal (default) and wide.
	wp_slimstat_reports:report_header('my_custom_report_id', 'report_size', 'My Custom Report Tooltip', 'My Cool Report Name');

	foreach($results as $a_result){
		echo "<p>{$a_result['resource']} <span>{$a_result['countresults']}</span></p>";
	}
	
	wp_slimstat_reports:report_footer();
}`

Then let Slimstat know about it:

`add_action('wp_slimstat_custom_report', 'my_cystom_report');`

Save your file as `my_custom_report.php` and then [follow these instructions](http://codex.wordpress.org/Writing_a_Plugin#Standard_Plugin_Information) to make a plugin out of that file.

= Can I disable outbound link tracking on a given link? =
Yes, you can. This is useful if you notice that, after clicking on a Lightbox-powered thumbnail, the image doesn't open inside the popup window as expected.
Let's say you have a link associated to Lightbox (or one of its clones):

`<a href="/wp-slimstat">Open Image in LightBox</a>`

Change it to:

`<a href="/wp-slimstat" class="noslimstat">Open Image in LightBox</a>`

You can also use the corresponding setting under Options > Advanced, and disable outbound link tracking for *all* the external links in your site.

= Why does Slimstat show more page views than actual pages clicked by a user? =
"Phantom" page views can occur when a user's browser does automatic feed retrieval,
[link pre-fetching](https://developer.mozilla.org/en/Link_prefetching_FAQ), or a page refresh. Slimstat tracks these because they are valid
requests from that user's browser and are indistinguishable from user link clicks. You can ignore these visits setting the corresponding option
in Slimstat > Settings > Filters

= Why can't Slimstat track visitors using IPv6? =
IPv6 support, as of today, is still limited both in PHP and MySQL. There are a few workarounds that could be implemented, but this
would make the DB structure less optimized, add overhead for tracking regular requests, and you would have a half-baked product.

= How do I prevent Slimstat from tracking spammers? =
Go to Slimstat > Settings > Filters and set "Ignore Spammers" to YES.

= Can I add/show reports on my website? =
Yes, you can. Slimstat offers two ways of displaying its reports on your website.

*Via shortcodes*

Please download and install [WP Slimstat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) to enable shortcode support in Slimstat.

You will need to edit your template and add something like this where you want your metrics to appear:

`// Load WP SlimStat DB, the API library exposing all the reports
require_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/wp-slimstat-db.php');

// Initialize the API. You can pass a filter in the options, i.e. show only hits by people who where using Firefox (any version) *and* visiting 'posts':
wp_slimstat_db::init('browser contains Firefox&&&content_type equals post');

// Use the appropriate method to display your stats
echo wp_slimstat_db::count_records('1=1', '*', false);`

You can list more than one filter by using &&& to separate them (which is evaluated as AND among the filters). Please read [these FAQs](http://wordpress.org/plugins/wp-slimstat-shortcodes/faq/) for more information on how to combine filters.

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

`wp_slimstat_db::init('month equals last month');
$results = wp_slimstat_db::get_popular('t1.language');
foreach ($results...`

== Screenshots ==

1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts 
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==

= 3.7.3 =
* [Note] A few people have asked us why they don't see the search keywords anymore, for the traffic coming from Google. Blame the NSA, Prism and... your government ;) [Read more here](https://wordpress.org/support/topic/incoming-search-terms)
* [New] Slimstat now tracks server latency and page performance. Please be patient the first time you load the reports: the database is being upgraded to store this new info!
* [New] Date and time formatting is now independent from the one used by WordPress
* [Update] Autopurge is now disabled by default on new installs, since people have had issues with losing old data by mistake
* [Update] Russian Localization (thank you, [Vitaly](http://tiny.cc/iHerbSpecials))
* [Update] Real-Time Log legend has been moved to the top, for easy access (thank you, [scruffy1](https://wordpress.org/support/topic/suggestion))
* [Fix] Visit ID were not being tracked if FORCE_SSL_ADMIN was enabled in wp_config, when the site was served over HTTP (thank you, [Pepe](https://wordpress.org/support/topic/session-grouping-doesnt-seem-to-work-anymore))

= 3.7.2 =
* [New] Added operator BETWEEN, to express filters like "country code BETWEEN f,g" and get reports for France and Ghana but not for Portugal. Or something like "screen resolution BETWEEN 1000,1300" to get data for visitors whose screen *width* is at least 1000 px and no more than 1300 px. Separate the two values in the range with a comma.
* [New] A premium add-on to track all the cookies associated with the domain
* [New] Notes regarding a specific pageview are now accessible under the Real-Time Log (little note/pencil icon next to the list of browser extensions). This will allow you to know things like prefetch requests, user id, spam, etc. If you have the Track Cookies add-on, here you will also find all the information about each user's cookies
* [Fix] Counter on Posts and Pages screens was not working as expected
* [Fix] Color-coded visits in the Real-Time log were not displayed correctly (thank you, Vitaly)

= 3.7.1 =
* [Note] Our Reports API (wp-slimstat-reports.php) does not extend wp-slimstat-admin anymore, for extra flexibility in building third-party solutions
* [New] Added support for generating email reports
* [Update] MaxMind's IP Database has been updated to the latest version (2014-09-04)
* [Fix] Some settings could not be turned off, because of the Network Settings functionality (thank you, [LeonardShelby](http://wordpress.org/support/topic/cannot-disable-live-stream))

= 3.7 =
* [New] Japanese localization added (thank you, [h_a_l_f](http://wordpress.org/support/topic/japanese-localization))
* [New] Added support for [SOV Languages](http://en.wikipedia.org/wiki/Subject%E2%80%93object%E2%80%93verb) (thank you, h_a_l_f)
* [New] Added support for our Network Settings premium add-on
* [Update] All the icons used in the admin are now optimized (thank you, [scruffy1](http://wordpress.org/support/topic/suggestion-79))
* [Fix] Introduced some code optimizations (browser detection) that will solve the slow response time once and for all!
* [Fix] Add-ons page was not accessible if menu was attached to the Admin Bar (thank you, h_a_l_f)

= 3.6.9 =
* [Note] Our new website is finally live, [check it out](http://slimstat.getused.to.it/)
* [Update] [AmMap](http://www.amcharts.com/download/) has been updated to version 3.10.2 (August 6, 2014)
* [Update] Renamed some sections to make their purpose more intuitive (thank you, Aaron)
* [Fix] The browser's properties (css version, mobile device, etc) were not being recorded properly

= 3.6.8 =
* [Fix] Mobile Safari on iOS was not being properly detected
* [Fix] Google Bot icon was not being displayed under certain circumstances

= 3.6.7 =
* [Fix] Foreach warning was being displayed, related to the latest browscap update. Sorry for the inconvenience, folks!

= 3.6.6 =
* [New] Top Traffic Sources is now showing the percentage of direct pageviews (thank you, [uewinfo_frm](http://wordpress.org/support/topic/top-traffic-sources-to-add-direct-access))
* [Update] [Browscap](http://browscap.org/) has been updated to version 5031, released on July 2, 2014
* [Fix] Index name was not properly handled (thank you, [bretweinraub](https://github.com/getusedtoit/wp-slimstat/pull/8))
* [Fix] Patched XSS Vulnerability (thank you, Yogendra Sharma)
* [Fix] Code for tracking external pages could not be selected in Firefox (thank you, [KSteinmann](http://wordpress.org/support/topic/cant-select-slimstat-external-pages-tracking-script)) 

= 3.6.5 =
* [Update] MaxMind's IP Database has been updated to the latest version (2014-07-01)
* [Fix] Bug in tracking visits when the CDN option is enabled
* [Fix] Number format description was sort of misleading (thank you, [Shonu](http://wordpress.org/support/topic/number-format-label))

= 3.6.4 =
* [Fix] Issue with our UAN code

= 3.6.3 =
* [Fix] Issue with heuristic user agent detection

= 3.6.2 =
* [New] You can disable SlimScroll if it doesn't work on your browser or mobile device (thank you, Egor)
* [New] Track non-WP pages (within the same domain) with WP SlimStat. Go to Settings > Advanced and get your tracking code (thank you, [JourdanDixon](http://wordpress.org/support/topic/adding-slim-stat-to-non-wp-page))
* [Fix] Page load speed has been improved introducing some caching mechanisms for certain requests

= 3.6.1 =
* [Update] Streamlined DB initialization / update procedures (thank you, [PyroSteveJr](http://wordpress.org/support/topic/not-capturing-anything))
* [Update] [AmMap](http://www.amcharts.com/javascript-maps/) has been updated to version 3.8.13
* [Fix] Uninstall procedure was not removing all the tables as expected (thank you, [scruffy1](http://wordpress.org/support/topic/how-to-delete-slim-stat))
* [Fix] Bug in retrieving the ID of the longest comment
* [Fix] Inconsistent table records were being removed without alerting the admin
* [Fix] Bug in calculating the CIDR mask for some IP-based filters (thank you, Alan)
* [Fix] Current tab was not remembered when clicking on a report's link to filter the data
* [Fix] Sorting was not available in Asynchronous Mode for certain reports (thank you, [psn](http://wordpress.org/support/topic/order-to-show-user-overview))

= 3.6 =
* [Note] We'll go back to our biweekly release schedule after this release - we just wanted to get our latest feature to our users as soon as possible
* [New] Support for network-wide reports is now complete
* [New] Support for network-wide settings is being tested and will be added soon
* [Update] MaxMind's IP Database has been updated to the latest version (2014-04-02)
* [Update] French localization had some encoding issues (thank you, Yves Pouplard)
* [Update] Swedish localization has been updated (thank you, Per Soderman)
* [Fix] User Overview add-on is now compatible with network-wide reports
* [Fix] Author filters were not working under certain circumstances (thank you, [tbetzold](http://wordpress.org/support/topic/authors-not-able-to-see-their-own-stats))

== Special Thanks To ==

* [Vitaly](http://www.visbiz.org/), who volunteered quite a lot of time for QA and testing, and provided the complete Russian localization
* [Davide Tomasello](http://www.davidetomasello.it/), who provided great feedback and plenty of ideas to take this plugin to the next level

== Supporters ==
Slimstat is an Open Source project, dependent in large parts on donations. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who want to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, [a review](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) for Slimstat is still a nice way to say thank you!

[7times77](http://7times77.com),
[Andrea Pinti](http://andreapinti.com),
Beauzartes,
[Bluewave Blog](http://blog.bluewaveweb.co.uk),
[BoldVegan](boldvegan.com),
[Caigo](http://www.blumannaro.net),
[Christian Coppini](http://www.coppini.me),
Dave Johnson,
[David Leudolph](https://flattr.com/profile/daevu),
[Dennis Kowallek](http://www.adopt-a-plant.com),
[Damian](http://wipeoutmedia.com),
[Edward Koon](http://www.fidosysop.org),
Erik Ludvigsson,
Fabio Mascagna,
[Gabriela Lungu](http://www.cosmeticebio.org),
Gary Swarer,
Giacomo Persichini,
Hal Smith,
[Hans Schantz](http://www.aetherczar.com),
Hajrudin Mulabecirovic,
[Herman Peet](http://www.hermanpeet.nl),
John Montano,
Kitty Cooper,
[La casetta delle pesche](http://www.lacasettadellepesche.it),
Mobile Lingo Inc,
[Mobilize Mail](http://blog.mobilizemail.com),
Mora Systems,
Motionart Inc,
Neil Robinson,
[Ovidiu](http://pacura.ru/),
Rocco Ammon,
[Sahin Eksioglu](http://www.alternatifblog.com),
[Saill White](http://saillwhite.com),
[Sarah Parks](http://drawingsecretsrevealed.com),
[Sebastian Peschties](http://www.spitl.de),
[Sharon Villines](http://sociocracy.info), 
[SpenceDesign](http://spencedesign.com),
Stephane Sinclair,
[Stephen Korsman](http://blog.theotokos.co.za),
[The Parson's Rant](http://www.howardparsons.info),
Thomas Weiss,
Wayne Liebman,
Willow Ridge Press

== Tools of the trade, in alphabetical order ==
[Duri.Me](http://duri.me/),
[Filezilla](https://filezilla-project.org/),
[Fontello](http://fontello.com/),
[Gimp](http://www.gimp.org/),
[Google Chrome](https://www.google.com/intl/en/chrome/browser/),
[poEdit](http://www.poedit.net/),
[Notepad++](http://notepad-plus-plus.org/),
[Tortoise SVN](http://tortoisesvn.net/),
[WAMP Server](http://www.wampserver.com/en/)