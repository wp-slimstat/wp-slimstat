=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, tracking, reports, analyze, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress
Requires at least: 3.8
Tested up to: 3.8
Stable tag: 3.5.1

== Description ==
A powerful real-time web analytics plugin for WordPress. Visit our [official site](http://slimstat.getused.to.it/) for more information.

= Key Features =
* Real-time reports
* Compatible with W3 Total Cache, WP SuperCache and HyperCache
* The most accurate IP geolocation, browser and platform detection ever seen (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org))
* Advanced filtering
* Available in multiple languages: English, Chinese (沐熙工作室), Farsi ([Dean](http://www.mangallery.net)), French (Michael Bastin), German (TechnoViel), Italian, Portuguese, Russian ([Vitaly](http://www.visbiz.org/)), Spanish, Swedish (Per Soderman). Is your language is missing or incomplete? [Contact Us](http://slimstat.getused.to.it/contact-us/) if you would like to share your localization)
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= What are people saying about WP SlimStat? =
* One of the 15+ Cool Free SEO Plugins for WordPress - [udesign](http://www.pixeldetail.com/wordpress/free-seo-plugins-for-wordpress/)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like SlimStat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own

= Requirements =
* WordPress 3.8+ (it may not work on *large* multisite environments)
* PHP 5.3+
* MySQL 5.0.3+
* At least 5 MB of free web space
* At least 5 MB of free DB space
* At least 10 Mb of free memory for the tracker
* IE9+ or any browser supporting HTML5

= Premium Add-ons =
Visit [our website](http://slimstat.getused.to.it/addons/) for an updated list of available extensions.

= Free Add-ons =
* [WP SlimStat Dashboard Widgets](http://wordpress.org/extend/plugins/wp-slimstat-dashboard-widgets) adds the most important reports right on your WordPress Dashboard
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) allows you to share your reports with your readers

== Installation ==

0. **If you are upgrading from 2.8.4 or earlier, you MUST first install version 3.0 (deactivate/activate) and then upgrade to the latest release available**
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP SlimStat
3. Click on **Install Now** under WP SlimStat
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

Please note: if you decide to uninstall WP SlimStat, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

== Frequently Asked Questions ==

= I see a warning message saying that a misconfigured setting and/or server environment is preventing WP SlimStat from properly tracking my visitors =
WP SlimStat's tracking engine has a server-side component, which records all the information available at the time the resource is served,
and a client-side component, which collects extra data from your visitors' browsers, like their screen resolution, (x,y) coordinates of their
clicks and the events they trigger. 

One of the files handling all the client-server communications is WordPress' `admin-ajax.php`, usually located inside your */wp-admin/* folder.
Point your browser to that file directly: if you see an error 404 or 500, then you will need to fix that problem, to allow WP SlimStat to do its job.
If you see the number zero, then the problem could be related to a conflict with another plugin (caching, javascript minimizers, etc).

= I am using W3 Total Cache (or WP Super Cache, HyperCache, etc), and it looks like WP SlimStat is not tracking all of my visitors. Can you help me? =
Go to SlimStat > Settings > General and set Tracking Mode to Javascript. Don't forget to invalidate/clear your plugin's cache, to let SlimStat add its tracking code to all the newly cached pages.
Also, if you're using W3 Total Cache, make sure to exclude wp-slimstat.js from the minifier: our code is already minified, and it looks like W3TC breaks something when it tries to minify it again.

= My screen goes blank when trying to access the reports / after installing WP SlimStat =
Go to SlimStat > Settings > Maintenance and click on the No Panic Button. If that doesn't help,
[increase the amount of memory](http://codex.wordpress.org/Editing_wp-config.php#Increasing_memory_allocated_to_PHP) allocated to PHP.

= When trying to access any of options screens, I get the following error: You do not have sufficient permissions to access this page. =
You were playing with the plugin's permission settings, weren't you? But don't worry, there's a secret passage that will allow you to unlock your access. Create a new WordPress admin user named `slimstatadmin`. Then log into your WordPress admin area with the new user and... voila: you can now access WP SlimStat's settings again. Update your users' permissions and then get rid of this newly created user.

= I am using WP Touch, and mobile visitors are not tracked by your plugin. How can I fix this problem? =
WP Touch has an advanced option that they call Restricted Mode, which attempts to fix issues where other plugins load scripts which interfere with WPtouch CSS and JavaScript. If you enable this feature, it will prevent WP SlimStat from running the tracking script (thank you, [Per](http://wordpress.org/support/topic/known-users-not-logged)).

= How can I change the colors associated to color-coded pageviews (known user, known visitors, search engines, etc)? =
Go to SlimStat > Settings > Advanced tab and paste your custom CSS into the corresponding field. Use the following code as a reference:

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
You will need to embed them in a plugin that leverages WP SlimStat APIs to retrieve the data. You can also access WP SlimStat's tables directly, for more complicated stuff.
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

Then let WP SlimStat know about it:

`add_action('wp_slimstat_custom_report', 'my_cystom_report');`

Save your file as `my_custom_report.php` and then [follow these instructions](http://codex.wordpress.org/Writing_a_Plugin#Standard_Plugin_Information) to make a plugin out of that file.

= Can I disable outbound link tracking on a given link? =
Yes, you can. This is useful if you notice that, after clicking on a Lightbox-powered thumbnail, the image doesn't open inside the popup window as expected.
Let's say you have a link associated to Lightbox (or one of its clones):

`<a href="/wp-slimstat">Open Image in LightBox</a>`

Change it to:

`<a href="/wp-slimstat" class="noslimstat">Open Image in LightBox</a>`

You can also use the corresponding setting under Options > Advanced, and disable outbound link tracking for *all* the external links in your site.

= Why does WP SlimStat show more page views than actual pages clicked by a user? =
"Phantom" page views can occur when a user's browser does automatic feed retrieval,
[link pre-fetching](https://developer.mozilla.org/en/Link_prefetching_FAQ), or a page refresh. WP SlimStat tracks these because they are valid
requests from that user's browser and are indistinguishable from user link clicks. You can ignore these visits setting the corresponding option
in SlimStat > Settings > Filters

= Why can't WP SlimStat track visitors using IPv6? =
IPv6 support, as of today, is still limited both in PHP and MySQL. There are a few workarounds that could be implemented, but this
would make the DB structure less optimized, add overhead for tracking regular requests, and you would have a half-baked product.

= How do I prevent WP SlimStat from tracking spammers? =
Go to SlimStat > Settings > Filters and set "Ignore Spammers" to YES.

= Can I add/show reports on my website? =
Yes, you can. WP SlimStat offers two ways of displaying its reports on your website.

*Via shortcodes*

Please download and install [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/) to enable shortcode support in WP SlimStat.

*Using the API*

You will need to edit your template and add something like this where you want your metrics to appear:

`// Load WP SlimStat DB, the API library exposing all the reports
require_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/view/wp-slimstat-db.php');

// Initialize the API. You can pass a filter in the options, i.e. show only hits by people who where using Firefox (any version) *and* visiting 'posts':
wp_slimstat_db::init('browser contains Firefox|content_type equals post');

// Use the appropriate method to display your stats
echo wp_slimstat_db::count_records('1=1', '*', false);`

You can list more than one filter by using the pipe char | to separate them (which is evaluated as AND among the filters).

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

`wp_slimstat_db::init('content_type equals post|visit_id');
$results = wp_slimstat_db::get_recent('t1.resource');
foreach ($results...`

Top Languages last month:

`wp_slimstat_db::init('month equals '.date('n', strtotime('-1 month')));
$results = wp_slimstat_db::get_popular('t1.language');
foreach ($results...`

== Screenshots ==

1. **Overview** - Your website traffic at a glance
2. **Right Now** - A real-time view of your visitors' whereabouts 
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==

= 3.5.1 =
* [Note] We got quite a lot of feedback about the new interface. Thank you for speaking up (yes, I'm looking at you, Romain and dFactory)!
* [Note] Please make sure to clear your browser cache, if your reports look all messy and broken!
* [New] You can now reload the World Map without reloading the entire page.
* [Update] Colors now adapt to the admin color scheme currently active (thank you, [dFactory](http://wordpress.org/support/topic/v35-oh-man)).
* [Update] Added new responsive layout for very large screens (width > 1440px).
* [Update] Say hi to our own Icon Font Set, which replaces all the png icons we were using before (courtesy of [Fontello](http://fontello.com/), thank you, [dFactory](http://wordpress.org/support/topic/v35-oh-man)).
* [Update] A new World Flags icon set makes things look even more consistent
* [Update] Chart Tooltips are back!
* [Update] RTL Support is being added to the CSS
* [Update] FAQs have been updated to match the new Settings screens.
* [Update] [flot](https://github.com/flot/flot) plotting library updated to version 0.8.2 stable
* [Fix] The Add-ons Settings page was not rendering properly (thank you, Alexander et al.).
* [Fix] The author filter was not working properly (thank you, [advertisingtech](http://wordpress.org/support/topic/author-filter-1))
* [Fix] Bug with the new Currently Online report (it was honiring date filters, which was sort of confusing).
* [Fix] Bug affecting tooltips in Firefox.
* [Fix] Bug affecting database indexes (thank you, [frequencycast](http://wordpress.org/support/topic/ip-address-labelling-plug-in-stopped-working))
* [Fix] Bug affecting SlimScroll in Firefox (thank you, [Gerard ter Beke](http://wordpress.org/support/topic/scrolling-activity-log-in-firefox-does-not-work-in-v35)).
* [Fix] Conflict with another plugin related to the logout button (thank you, [GusRuss89](http://wordpress.org/support/topic/logout-button-conflicts-with-wp-slimstat))
* [Fix] Bug on masking IP Addresses on 32-bit systems, which apparently hadn't been fixed in 3.5 (thank you, carbeck)

= 3.5 =
* [Note] Please make sure to clear your browser cache, if your reports look all messy and broken!
* [New] A polished and fully responsive interface, optimized for the new WordPress 3.8 admin layout. If you're still using WP 3.7 or earlier, you may want to stick to version 3.4.3. 
* [New] Implemented *Slim*Scroll (what else? hehe) by [Piotr Rochala](http://rocha.la).
* [New] Added qTip 2 to handle tooltips and contextual help.
* [New] Added two new reports: who's currently online (registered users), and top language families (thank you, Vitaly)
* [New] Now you can track 'fake' pageviews (if events are not enough for you)
* [New] Pagination has been added to most reports: now you will not miss anything!
* [Update] The 'Latest News' ribbon is now only displayed within the report screens, and it disappears automatically after 5 minutes.
* [Update] [Browscap](http://browscap.co/) has been updated to version 5021-b9, released on Dec 8, 2013
* [Updade] Most add-ons have been updated to use the new look and feel, icons, etc.
* [Update] All the Settings screens have been reorganized and redesigned (and are now fully responsive!). We replaced the old "for geeks only" descriptions with new ones, easier to understand.
* [Update] The plugin's source code is being consolidated and reorganized. You reports will now load faster than ever!
* [Update] By default, Javascript Mode (or Tracking Mode, in v3.5) is now enabled
* [Update] Added new icon for Windows 8.1
* [Update] MaxMind / Geolocation database updated to December 2013
* [Fix] Bug on masking IP Addresses on 32-bit systems (thank you, Per)

= 3.4.3 =
* [Fix] Bug in parsing the data returned by Alexa (new Rankings report) was causing some reports to disappear (thank you, [pepe](http://wordpress.org/support/topic/php-warnings-in-rankings-box))
* [Fix] A few PHP notices (thank you, [supriyos](http://wordpress.org/support/topic/errors-after-upgrading-to-342))
* [Fix] Bug in masking local IP addresses (thank you, [carbeck](http://wordpress.org/support/topic/1272552550-1))

= 3.4.2 =
* [New] Three new reports give you detailed information about your rankings (Google, Facebook, Alexa), your content and your site's security.
* [Update] Complete Russian Localization (thank you, Vitaly!)
* [Update] Top Browsers now groups browsers by name, if the user agent string is not enabled/displayed (thank you, Vitaly)
* [Update] Much improved language detection and localization (thank you, Vitaly)
* [Update] By default only admins can now see the stats (minimum capability to view: activate_plugins). 
* [Update] Removed unused languages from the dictionary (who is using operating systems in Herero, Igbo, Hiri Motu, Church Slavic anyway?) 
* [Update] Optimized code to manage the plugin's options (removed unnecessary db interactions)
* [Update] World Map ([AmMap](http://www.ammap.com/download/)) updated to version 3.7
* [Update] MaxMind / Geolocation database updated to November 2013
* [Update] Consolidated reports and improved performance on Overview tab
* [Fix] Bug in converting some IP addresses to long integers (thank you, [tkleinsteuber](http://wordpress.org/support/topic/wrong-geolocation-for-rfc-1918-private-ip-ranges))
* [Fix] Some PHP warnings about undefined variables

= 3.4.1 =
* [New] Report to visualize top Outbound Links and Downloads (thank you, [bobinoz](http://wordpress.org/support/topic/tracking-outbound-links-1))
* [New] Purge data by user agent (thank you, [GermanKiwi](http://wordpress.org/support/topic/purge-data-based-on-user-agent))
* [New] Import/Export all your settings in a text file. Go to Settings > Maintenance and give it a try! (thank you, [Mike](http://wordpress.org/support/topic/feature-request-save-out-settings))
* [Update] Cosmetic updates to the interface
* [Update] Right Now Extended is now set to 'No' by default
* [Fix] Filters were not being reset if API was invoked more than once on the same page (thank you, [PV-Patrick](http://wordpress.org/support/topic/api-calls-in-the_loop))
* [Fix] Compatibility issues with User Overview (thank you, Thorsten)
* [Fix] Bug affecting the data recorded when URLs were using non-ASCII characters (thank you, [dimitrios1988](http://wordpress.org/support/topic/stats-now-showing-in-non-ascii-characters))
* [Fix] Compatibility issues with Export to Excel
* [Fix] Bug related to the new HTTP POST-based Filtering system
* [Fix] Issue with French localization encoding (thank you [whoaloic](http://wordpress.org/support/topic/foreign-language-encoding-issue))
* [Fix] Elaborated on how to use multiple filters with the API (thank you, [Statistiker](http://wordpress.org/support/topic/filter-most-popular-posts))

= 3.4 =
* [Note] We can't believe we're already crossing the 600,000 downloads mark! To celebrate this accomplishment, we're working on a brand new website! Stay tuned.
* [New] Local IP Addresses are now marked as such (thank you, [Thorsten](http://wordpress.org/support/topic/wrong-geolocation-for-rfc-1918-private-ip-ranges))
* [Update] SlimStat's filters have been reimplemented to use HTTP POST requests, in order to avoid issues with very long URIs (thank you, John)
* [Update] You can now restrict access to the configuration screens by specifying the minimum capability required (default: activate_plugins)
* [Update] Localization files have consolidated and are now easier to manage. Send us your localization!
* [Fix] Clicking on report titles doesn't collapse the box anymore (thank you, psn)
* [Fix] Minor fixes to the Javascript used on admin pages
* [Fix] Restored compatibility with the plugin Dashboard Widgets

= 3.3.6 =
* [New] Since you've asked, we added a datepicker to the filters
* [Update] MaxMind / Geolocation database updated to October 2013
* [Fix] We had some issues with our repository, which made WP SlimStat unavailable for a while. Sorry for the inconvenience.

= 3.3.5 =
* [Note] Our add-on [Export To Excel](http://slimstat.getused.to.it/addons/wp-slimstat-export-to-excel/) can now export the tabular data that makes up the charts (thank you, [consensus](http://wordpress.org/support/topic/graph-export))
* [New] Now all the charts include comparison data for both metrics
* [Fix] A javascript variable name conflict introduced in version 3.3.4 was affecting some advanced functionality (thank you, [Nanowisdoms](http://wordpress.org/support/topic/expand-details-option-not-working-in-334))
* [Fix] A pretty unique combination of settings was affecting the way the Spy View data was being listed (thank you, [Nanowisdoms](http://wordpress.org/support/topic/live-visitor-as-in-currently-still-on-the-site-view))

== Distinguished Users ==

* [Vitaly](http://www.visbiz.org/) - Volunteered quite a lot of time for QA and testing, and provided the complete Russian localization
* [Davide Tomasello](http://www.davidetomasello.it/) - Gave us great feedback and plenty of ideas to take this plugin to the next level

== Supporters ==
[7times77](http://7times77.com),
[Andrea Pinti](http://andreapinti.com),
Beauzartes,
[Bluewave Blog](http://blog.bluewaveweb.co.uk),
[BoldVegan](boldvegan.com),
[Caigo](http://www.blumannaro.net),
[Christian Coppini](http://www.coppini.me),
Dave Johnson,
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
[La casetta delle pesche](http://www.lacasettadellepesche.it),
Mobile Lingo Inc,
[Mobilize Mail](http://blog.mobilizemail.com),
Mora Systems,
Motionart Inc,
Neil Robinson,
[Ovidiu](http://pacura.ru/),
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
* [Duri.Me](http://duri.me/)
* [Filezilla](https://filezilla-project.org/)
* [Fontello](http://fontello.com/)
* [Gimp](http://www.gimp.org/)
* [Google Chrome](https://www.google.com/intl/en/chrome/browser/)
* [poEdit](http://www.poedit.net/)
* [Notepad++](http://notepad-plus-plus.org/)
* [Tortoise SVN](http://tortoisesvn.net/)
* [WAMP Server](http://www.wampserver.com/en/)