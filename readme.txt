=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: chart, analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, wassup, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, flot, stalker, statistics, google+, monitor, seo
Requires at least: 3.0
Tested up to: 3.3
Stable tag: 2.5.3

== Description ==
A powerful real-time web analytics plugin for Wordpress. Spy your visitors and track what they do on your website.

= Main Features =
* Supports both InnoDB and MyISAM (autodetect)
* Tracks Google Plus One and Facebook Like clicks
* Tracks known commenters, screen resolutions, spammers and other browser-related parameters
* The best country, browser and platform detection ever seen, thanks to [Browscap](https://github.com/garetjax/phpbrowscap) and [MaxMind](http://www.maxmind.com/)
* Filters visits based on IP addresses, browsers, referrers, users and permalinks
* View and admin access can be restricted to specific users
* World Map

= Requirements =
* Wordpress 3.0 or higher (it may not work on large multisite environments)
* PHP 5.1 or higher
* MySQL 5.0.3 or higher
* At least 5 MB of free web space
* At least 5 MB of free DB space

## Browser Compatibility
As of version 2.5, WP SlimStat has switched from Flash to Javascript to draw its charts. This new approach, though, is not compatible with Internet Explorer 8 or older,
so you're encouraged to either keep your current version of WP SlimStat, or upgrade your browser.

## Database usage
WP SlimStat needs to create its own tables in order to maintain the complex information about visits, visitors, browsers and Countries. It creates 3 new tables for each blog, plus 3 shared tables (6 tables in total, for a single-user installation). Please keep this in mind before activating WP SlimStat on large networks of blogs.

You may also like [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/), which enables shortcodes to show your metrics in a widget or a page.

== Installation ==

1. If you are upgrading from a previous version, *deactivate WP SlimStat*
2. Upload all the files and folders to your server
3. Activate it 
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. You'll see a new entry under the Dashboard menu
6. Customize all its options, go to Settings > Slimstat
7. If you're blocking access to your `wp-content` folder, move `wp-slimstat-js.php` to a different folder, and edit the first line of code to let WP SlimStat know where your `wp-config.php` is

== Frequently Asked Questions ==

= Can I track Google Plus One and other events? =
Yes, this plugin comes with a nice javascript function you can use to track any event occurring on your page (downloads, clicks, outbound links, Facebook Likes, Google Plus One, etc). The function is

`ss_te(event, code, follow_link)`

where event is the event that triggered this call, code is an integer between 2 and 254 and follow_link is true if you want to open the target URL after clicking on the link (useful for downloads and outbound links). 
If the third param is set to false, then the event may not be specified (use an empty string as placeholder).

Handy shortcuts will make your life easier:

* *Google Plus One*: change the default Google tag to `<g:plusone callback="slimstat_plusone"></g:plusone>`. Then take a look at SlimStat > Content > Recent Events. Positive entries will have code = 3, negative ones (undo +1) code = 4.
Remember: Google's javascript must be loaded *after* slimstat.js, so make sure to put things in the right place in your souce code.
* *onClick mouse event*: add the following code to the links you want to track: `onclick="if(typeof ss_te == 'function'){ss_te('', 5, false);}"` where 5 can be any number between 2 and 254.

= How do I create my own custom reports? =
You will need to write a plugin that retrieves the information from WP SlimStat tables and displays it using
the format described here below. A demo plugin is included within the package: take a look at its source code
(which I tried to keep as simple as possible) and then cut your imagination loose! 

More information at http://lab.duechiacchiere.it/index.php?topic=2.0#post_customreports

= How do I add some stats to my theme? =
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
Please refer to [this](http://lab.duechiacchiere.it/index.php?topic=2.0#post_shortcodes) page for more information about this approach.

[Here](http://lab.duechiacchiere.it/index.php?topic=2.0#post_displaymetrics) you can find a list of all available functions and filters.

= I am experiencing a conflict with Lightbox or one of its clones =
After clicking on the thumbnail, the bigger image would show up not inside the 'nice' popup window as expected?
The way my plugin tracks external/outbound links is incompatible with the way Lightbox works. Unfortunately, 
Javascript doesn't allow me to detect if a specific link is being "managed" by Lightbox, so here's the workaround.

Let's say you have a link associated to Lightbox (or one of its hundreds clones):

`<a href="/wp-slimstat">Open Image in LightBox</a>`

To tell WP SlimStat to let Lightbox do its job, change it to:

`<a href="/wp-slimstat" class="noslimstat">Open Image in LightBox</a>`

The click will still be tracked, but will avoid any conflicts with third party Javascript codes.

= After installing WP Supercache (or other caching plugin), WP SlimStat shows very few visits, why is that? =
This plugin is incompatible with WP Supercache, WP Cache, Hyper Cache, or any page-based caching plugin.

= Can I track downloads and other actions? =
WP SlimStat can track outbound links (clicks on links taking users to other websites), downloads and other events.
Outbound links are automatically tracked, once you activate the corresponding option (Enable JS Tracking) in your admin panel.
In order to explicitly track downloads, you need to change your link from

`<a href="/path/to/my/download.zip">Download this cool file</a>`

to

`<a href="/path/to/my/download.zip" onclick="ss_te(event,1)">Download this cool file</a>`

Please make sure to use exactly this syntax when modifying your links. The second parameter (1, in the example here above)
can be any number between 2 and 254. Zero is reserved for tracking outbound clicks, 1 is for downloads.

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
3. It works on your mobile device!

== Changelog ==

= Planned features =
* Display visit duration and time on site
* Add "internal" stats about your blog: post count, comments per post, table sizes, etc

= 2.5.3 =
* Maintenance release: it looks like 2.5.2 shipped with an annoying bug, triggered by some templates out there, preventing visits to be properly tracked. If you haven't experienced any problems, there's no need to upgrade
* Added: option to ignore pageviews generated by Safari via Link Prefetching (thank you [Steve](http://wordpress.org/support/topic/plugin-wp-slimstat-suggestion-filter-setting-to-ignore-safari-preview))

= 2.5.2 =
* Added: forward compatible (heuristic) user agent detection, for those browsers not yet identified by Browscap (thank you Davide)
* Added: Google Images query string parser
* Added: detailed information about the database structure (go to Settings > SlimStat > Maintenance)
* Added: filter 'not equal to', which allows you to further narrow down your analytics
* Added: option to ignore pageviews generated by Firefox via Link Prefetching
* Added: option to ignore pageviews coming from a given Country
* Updated: tracking javascript is now enqueued for better performance (thank you [stevemagruder](http://wordpress.org/support/topic/plugin-wp-slimstat-version-251-significantly-increases-page-load-times))
* Updated: make sure that users blacklisted and allowed to access WP SlimStat exist in the database
* Updated: Browser Detection Database (Released Thu, 03 Nov 2011 07:00:27 -0000)
* Fixed: error in creating one of the tables on some MySQL versions (thank you [scorpress](http://wordpress.org/support/topic/plugin-wp-slimstat-country-detection-is-not-working))
* Geolocation: updated to November 2011, 155183 rows. Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

= 2.5.1 =
* Updated: Browser Detection Database (Released: Sun, 16 Oct 2011 06:38:35 -0000), which includes Firefox 7 and new iPhone patterns
* Fixed: error in generating some links to go to the Details page (thank you [nlegg](http://wordpress.org/support/topic/plugin-wp-slimstat-incorrect-links-on-slimstat-dashboard-in-wp-multisite))
* Fixed: conflict with some old Firefox versions in opening tracked links in a new tab
* Geolocation: updated to October 2011, 153683 rows. Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

= 2.5 =
* Goodbye Flash, welcome Flot! Yes, after more than one year, Flash charts (provided by FusionChart) have been replaced by jQuery-based ones. You can now zoom in/out and pan through the chart.
* Added: event tracking functionality, to monitor your Google Plus One clicks and much more
* Added: contextual tooltips to describe what some terms mean or how to interpret the numbers (thank you Douglas)
* Added: spammers are now identified with a special 'label' among other users, and can be ignored (thank you Davide)
* Fixed: some glitches in using filters
* Fixed: if a 404 page was listed among your popular resources, its link to open it would not work
* Updated: removed some unused code

== Languages ==

Wp-SlimStat can speak your language! I used the Portable Object (.po) standard
to implement this feature. If you want to provide a localized file in your
language, use the template files (.pot) you'll find inside the `lang` folder,
and contact me via the [support forum](http://lab.duechiacchiere.it) when you're ready
to send me your files. Right now the following localizations are available (in
alphabetical order):

* French ([Tuxicoman](http://tuxicoman.jesuislibre.net/), Vidal Arpin)
* German ([Digo](http://www.showhypnose.org/blog/))
* Italian
* Spanish (Noe Martinez)
* Swedish (Zebel Khan)

== List of donors in alphabetical order ==
[Andrea Pinti](http://andreapinti.com/), [Bluewave Blog](http://blog.bluewaveweb.co.uk/), [Dennis Kowallek](http://www.adopt-a-plant.com),
[Hans Schantz](http://www.aetherczar.com/), [Herman Peet](http://www.hermanpeet.nl/), [La casetta delle pesche](http://www.lacasettadellepesche.it/),
[Mobilize Mail](http://blog.mobilizemail.com/), Mora Systems, Neil Robinson, [Sahin Eksioglu](http://www.alternatifblog.com/),
[Saill White](http://saillwhite.com), Wayne Liebman

= Dashboard Widgets = 
After you download and install WP SlimStat, you'll see not one, but two new plugins in your administration panel.
Don't worry, you just need to activate the first one in order to track your visitors. WP SlimStat Dashboard Widgets
adds some reports directly to your dashboard. 