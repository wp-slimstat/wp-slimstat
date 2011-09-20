=== WP SlimStat ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: analytics, visitors, users, spy, shortstat, tracking, reports, seo, referers, analyze, geolocation, online users, spider, tracker, pageviews, world map, stats, maxmind, fusion charts
Requires at least: 3.0
Tested up to: 3.3
Stable tag: 2.5

== Description ==
A lightwight but powerful real-time web analytics plugin for Wordpress. Spy your visitors and track what they do on your website.

## Requirements
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

## Main Features
* Support for both InnoDB and MyISAM (autodetect)
* Track Google Plus One and Facebook Like clicks
* Track known commenters, screen resolutions and other browser-related parameters
* The best country, browser and platform detection ever seen, thanks to [Browscap](https://github.com/garetjax/phpbrowscap) and [MaxMind](http://www.maxmind.com/)
* Filter visits based on IP addresses, browsers, referrers, users and permalinks
* Restrict view/admin access to specific users
* World Map

## Related plugins
* [WP SlimStat Shortcodes](http://wordpress.org/extend/plugins/wp-slimstat-shortcodes/)

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
WP SlimStat 2 allows you to display its reports on your website. Including filters!
You will need to edit your template and add something like this where you want your metrics to appear:
`// Load WP SlimStat VIEW, the library with all the metrics
require_once(WP_PLUGIN_DIR.'/wp-slimstat/view/wp-slimstat-view.php');

// Define a filter: I want to show only hits by people who where using Firefox, any version
$filters = array('browser' => 'Firefox', 'browser-op' => 'contains');

// Instantiate a new copy of that class
$wp_slimstat_view = new wp_slimstat_view($filters);

// Use the appropriate method to display your stats
echo $wp_slimstat_view->count_records('1=1', '*', false);`

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

= Can I track downloads and other actions? =
WP SlimStat can track outbound links (clicks on links taking users to other websites), downloads and other events.
Outbound links are automatically tracked, once you activate the corresponding option (Enable JS Tracking) in your admin panel.
In order to explicitly track downloads, you need to change your link from

`<a href="/path/to/my/download.zip">Download this cool file</a>`

to

`<a href="/path/to/my/download.zip" onclick="ss_te(event,1)">Download this cool file</a>`

Please make sure to use exactly this syntax when modifying your links. The second parameter (1, in the example here above)
can be any number between 2 and 254. Zero is reserved for tracking outbound clicks, 1 is for downloads.

== Screenshots ==

1. Dashboard
2. Configuration panel
3. It works on your mobile device

== Changelog ==

= What's cooking? =
* Google Images URL's parser
* Display visit duration and time on site
* Spam tracking / filtering

= 2.5 =
* Goodbye Flash, welcome Flot! Yes, after more than one year, Flash charts (provided by FusionChart) have been replaced by jQuery-based ones. You can now zoom in/out and pan through the chart.
* Added: event tracking functionality, to monitor your Google Plus One clicks and much more
* Added: contextual tooltips to describe what some terms mean or how to interpret the numbers (thank you Douglas)
* Added: spammers are now identified with a special 'label' among other users, and can be ignored (thank you Davide)
* Fixed: some glitches in using filters
* Fixed: if a 404 page was listed among your popular resources, its link to open it would not work
* Updated: removed some unused code

= 2.4.4 =
* Fixed: a few bugs that affected network activations, now WP SlimStat should work fine with WPMU (thank you [Kevin](http://wordpress.org/support/topic/plugin-wp-slimstat-javascript-not-included-in-my-pages))
* Fixed: some PHP warnings in DEBUG mode
* Fixed: visits coming from the server itself (bots, analyzers, etc) are now ignored (thank you [PeterN](http://lab.duechiacchiere.it/index.php?topic=428.0))
* Geolocation: updated to August 2011, 150241 rows. Go to Options > Maintenance > Reset Ip-to-Countries. Then deactivate/reactivate WP SlimStat to import the new file.

= 2.4.3 =
* Maintenance release
* Added: reintroduced support for network activations. As pointed out [in the mailing list](http://lists.automattic.com/pipermail/wp-hackers/2011-June/039871.html) by some core Wordpress developers, there's no ideal solution for large networks, and if your network has more than 40 blogs, the activation process will most likely timeout.
* Added: new color-coded interface to visually identify types of visits on-the-fly (thank you Davide). Refer to the Raw Data tab to learn about what each color means.
* Added: hostnames to the Raw Data view (thank you Jonathan)
* Added: entry in the admin bar, to access your stats from anywhere
* Updated: Browser Detection Database (Released: Wed, 22 Jun 2011 23:26:51 -0000)
* Updated: some code optimizations
* Fixed: a bug in SlimStat Dashboard preventing some users from seeing the widgets (thank you [Flauschi](http://wordpress.org/support/topic/plugin-wp-slimstat-dashboard-widgets-are-not-displayed-all-the-time?replies=2#post-2203682))
* Fixed: layout improvements (thank you Jonathan)
* Fixed: a bug with multisite environments and referrers (thank you [kevinff](http://wordpress.org/support/topic/plugin-wp-slimstat-javascript-not-included-in-my-pages))

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