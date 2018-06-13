=== Slimstat Analytics ===
Contributors: coolmann
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38
Tags: analytics, statistics, counter, tracking, reports, wassup, geolocation, online users, spider, tracker, pageviews, stats, maxmind, statistics, statpress, power stats, hit
Text Domain: wp-slimstat
Requires at least: 3.8
Requires PHP: 5.2
Tested up to: 4.9
Stable tag: 4.7.8.3

== Description ==
The leading web analytics plugin for WordPress. Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Feature Spotlight =
[youtube https://www.youtube.com/watch?v=zEKP9yC8x6g]

= Main features =
* Get access to real-time access log, measure server latency, track page events, keep an eye on your bounce rate and much more.
* Add shortcodes to your website to display reports in widgets or directly in posts and pages.
* Fully compliant with the European GDPR guidelines. You can test your website at [cookiebot.com](https://www.cookiebot.com/en/).
* Exclude users from statistics collection based on various criteria, including; user roles, common robots, IP subnets, admin pages, country, etc.
* Export your reports to CSV, generate user heatmaps or get daily emails right in your mailbox (via premium add-ons).
* Compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* Support for hashing IP addresses in the database to protect your users privacy.
* Accurate IP geolocation, browser and platform detection (courtesy of [MaxMind](http://www.maxmind.com/) and [Browscap](http://browscap.org)).
* World Map that works on your mobile device, too (courtesy of [amMap](http://www.ammap.com/)).

= Premium Add-ons =
Visit [our website](http://www.wp-slimstat.com/addons/) for a list of available extensions.

= Social Media =
[Like Us](https://www.facebook.com/slimstatistics/) on Facebook and [follow us](https://twitter.com/wp_stats) on Twitter to get the latest news and updates about our plugin.

= Contribute =
Slimstat Analytics is an open source project, dependent in large parts on community support. You can fork our [Github repository](https://github.com/slimstat/wp-slimstat) and submit code enhancements, bugfixes or provide localization files to let our plugin speak even more languages. [This page](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38)
is for those who would like to donate money - be it once, be it regularly, be it a small or a big amount. Everything is set up for an easy donation process.
Try it out, you'll be amazed how good it feels! If you're on a tight budget, and coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) for Slimstat as a token of appreciation for our hard work!

= Translations =
Slimstat is available in multiple languages: English, Belarusian (UStarCash), Chinese (沐熙工作室), Farsi, French (Michael Bastin, Jean-Michel Venet, Yves Pouplard, Henrick Kac), German (TechnoViel), Indonesian ([ChameleonJohn](https://www.chameleonjohn.com/)), Italian ([Slimstat Dev Team](https://www.wp-slimstat.com)), Japanese (h_a_l_f), Portuguese, Russian (Vitaly), Spanish ([WebHostingHub](http://www.webhostinghub.com/)), Swedish (Per Soderman) and Turkish (Seyit Mehmet Çoban). Is your language missing or incomplete? [Contact us](http://support.wp-slimstat.com/) today.

= Reviews and Feedback =
* This is by far the most accurate and in-depth tracking plugin I've encountered for WordPress [MiMango](https://wordpress.org/support/topic/excellent-plugin-and-service-9)
* I have been relying on SlimStat to not only track all traffic to my sites accurately but also to present the stats in very useful graphic format [JJD3](https://wordpress.org/support/topic/an-essential-plugin-14)
* Thanks you for such an excellent plugin. I am using it to kick Jetpack out of all the wordpress installations that I manage for myself and others - [robertwagnervt](http://wordpress.org/support/topic/plugin-wp-slimstat-excellent-but-some-errors-on-activating)
* I like Slimstat very much and so I decided to use it instead of Piwik - [Joannes](http://wordpress.org/support/topic/plugin-wp-slimstat-slimstat-and-privacy)
* Read all the [reviews](http://wordpress.org/support/view/plugin-reviews/wp-slimstat) and feel free to post your own!

= Requirements =
* WordPress 3.8+
* PHP 5.2+ (or 5.5+ if you use the Browscap data file)
* MySQL 5.0.3+
* At least 20 MB of free web space
* At least 5 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)
* IE9+ or any browser supporting HTML5, to access the reports

== Installation ==
1. In your WordPress admin, go to Plugins > Add New
2. Search for WP Slimstat Analytics
3. Click on **Install Now** under WP Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)
5. Go to Slimstat > Settings > Maintenance tab > MaxMind IP to Country section and click on "Install GeoLite DB" to detect your visitors' countries based on their IP addresses
6. If your `wp-admin` folder is not publicly accessible, make sure to check the [FAQs](http://wordpress.org/extend/plugins/wp-slimstat/faq/) to see if there's anything else you need to do

== Please note ==
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.
* If you are upgrading from a version prior to 4.0, please install version 4.0 first to upgrade the database structure and download the new Geolocation data.

== Frequently Asked Questions ==
Our knowledge base is available on our [support center](http://docs.wp-slimstat.com/) website.

== Screenshots ==
1. **Overview** - Your website traffic at a glance
2. **Activity Log** - A real-time view of your visitors' whereabouts
3. **Settings** - Plenty of options to customize the plugin's behavior
4. **Interactive World Map** - See where your visitors are coming from
5. **Responsive layout** - Keep an eye on your reports on the go

== Changelog ==
= 4.7.8.3 =
* [Fix] The opt-out message was being displayed even if the corresponding setting was turned off. Apologies for the inconvenience.

= 4.7.8.2 =
* [New] The IP to hostname conversion feature now stores in the database the information it calculates, to avoid querying the DNS server over and over again.
* [Update] The opt-out banner is now loaded dynamically, to address HTML caching issues. Thank you, [fuchsws](https://wordpress.org/support/topic/opt-out-message-vs-html-cache).

= 4.7.8.1 =
* [New] The Customizer now has its own access control settings. This allows admins to control in a more granular way who can do what.
* [Update] If you have an existing opt-in mechanism, asking your users if they want to be tracked, you can now configure Slimstat to use that cookie to determine if a given pageview should be recorded or not.

= 4.7.8 =
* [Note] A few users have reached out to us to ask if Slimstat would be compliant with the upcoming [General Data Protection Regulation (GDPR)](https://en.wikipedia.org/wiki/General_Data_Protection_Regulation) guidelines and regulations that are about to be activated all across Europe. Based on our understanding of this new law, as long as the hosting provider where you are storing the information collected by Slimstat is GDPR compliant, then you won't have to worry about any extra layers of compliance offered by software like ours. One of our primary goals is to make sure that you and only you are the sole owner of the data collected by our plugin. This has always been what makes Slimstat stand out from the crowd: while Jetpack, Google Analytics and many other services have full unrestricted access to the data they collect on your website, we at Slimstat don't treat our users as *the product* that we sell to other companies.
* [New] Our plugin now honors the [Do Not Track header](https://en.wikipedia.org/wiki/Do_Not_Track). Please note that this feature can be turned off in the settings, and will be enabled by default.
* [New] We introduced an experimental option to allow your users to opt out of tracking via a text box displayed at the bottom of your website. Please go to Settings > Filters to customize the behavior and the message to suit your needs and website layout. You can also use third-party solutions to let your visitors opt out, and then configure Slimstat to read the corresponding cookie they set.
* [New] You can now add reports to the Access Log screen, and customize it just like any other screen in Slimstat.
* [Update] Reintroduced the `interval_minutes` filter, which had been temporarily removed from our code as a side effect of our code clean-up process. Thank you, [mth75](https://wordpress.org/support/topic/wrong-currently-online-value-shortcode/).
* [Update] Moved the button to reset the report layouts to the Customizer screen.
* [Update] Deprecated the Geolocation screen. The World Map report has been moved to the Audience tab. If for some reason you cannot find the World Map, please go to Slimstat > Customize and click the Reset All button.
* [Fix] Filters were not being set when opening the corresponding links in a new window. Thank you, [forumaad](https://wordpress.org/support/topic/bug-empty-filter-line-then-open-at-new-windows/)
* [Fix] Bug affecting the report "Currently Online".
* [Fix] Bug affecting all the filter links after the Export to Excel add-on had been enabled.
* [Fix] Bug affecting the resource filter when "nice permalinks" are not enabled.

= 4.7.7 =
* [New] We've completely rewritten the portion of code that handles the date ranges in the Filter Bar. In order to simplify things, **we have deprecated** the `interval_direction` filter, which is now expressed by the sign in front of the interval value (positive for going forward from a given start date, and negative for going back in time). Please note that this change affect your existing shortcodes, if they use the aforementioned filter. We will update our documentation in the next few days to remove any reference to this filter, and to avoid any confusion. Please feel free to contact us if you have any questions or to report any issues.
* [New] The comparison chart is now always displayed, using new criteria to determine the range to use. You may want to update your settings (Settings > Reports > Default Time Span > Days, and Reports > Comparison Chart) to mimic the old behavior or hide the comparison chart altogether, if you like.
* [Update] We've reintroduced the various levels of granularity for our charts: hourly (when a single day is selected), daily (for ranges up to 120 days) and monthly. Also, the comparison chart is now always available, regardless of the selected time range. Thank you, [WebsiteOpzetten](https://wordpress.org/support/topic/char-is-not-displayed-when-the-selected-time-range-is-comprised-of-a-single-day/).
* [Update] Tooltips across the interface have a more uniform behavior.

= 4.7.6.1 =
* [Fix] The new Javascript library was interfering with the dropdown menus on the WordPress Dashboard. Thanks to all of those who helped us troubleshoot the issue.

= 4.7.6 =
* [Note] As we mentioned earlier, we've been working on streamlining and cleaning up our source code. It's incredible how layers of code can deposit on top of each other, until they form a thick layer that prevents developers from seeing clearly what's happening. It was time to apply some virtual citric acid to descale our code. If you're using any kind of server caching functionality, please make sure to clear your cache before opening a support request. Also, do not hesitate to reach out to us if you notice any strange or unusual behaviors.
* [New] You can now download the geolocation map as a PDF file or PNG image. Thank you, Steve, for suggesting this feature!
* [New] You can now exclude pageviews by content type, like 404, taxonomy, search, etc. Thank you, [victor50g](https://wordpress.org/support/topic/suggestion-enable-filtering-on-content-404/).
* [Update] Deprecated the 'direction' filter, which was a leftover from over 5 years ago, when we used our old interface.
* [Fix] The cron job to archive old records was not firing as expected under certain circumstances.
* [Fix] Charts did not render correctly, if no data was available for that specific view.

= 4.7.5.3 =
* [New] For those who can't manage to automatically update their local copy of our premium add-ons, we've added a link to manually download the zip file. You'll find it under the Plugins screen, once you've entered and saved the corresponding license key under Slimstat > Add-ons.
* [Update] The [Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library has been updated to version 4.4.
* [Fix] The world map was not being displayed correctly if no data points were available for the selected time range (thank you, Michel).

= 4.7.5.2 =
* [Update] You can now customize the amount of dots displayed on the World Map, under Slimstat > Settings > Reports > Access Log and World Map. Thank you, [service4](https://wordpress.org/support/topic/new-geolocation-map-with-cities/).
* [Fix] A dependency error was being highlighted for one of our premium add-ons under certain circumstances. Thank you, Peter.
* [Fix] The option to not set the session cookie was not working as expected. Thank you, [Bjarne](https://wordpress.org/support/topic/disable-cookies-2/#post-9887099).

= 4.7.5.1 =
* [Update] Implemented a workaround to try and fix the "Forbidden" error that a few users are experiencing when trying to download the MaxMind Geolite2 data file.
* [Fix] Updated the link to manually download the MaxMind data file from their servers, and added a new page to our knowledge base to explain how to manually install it.

= 4.7.5 =
* [New] Now that Slimstat is capable of geolocating visitors at the city level, wouldn't it make sense to display those visitors on the map? Well, of course! Go check out this new feature by accessing the Geolocation tab in Slimstat.
* [New] Updated the tracking script to handle events triggered by external libraries, like the [Vimeo API](https://github.com/vimeo/player.js/#events). Thank you, Max.
* [New] Added new operator "included_in_set", which allows you to list multiple values to match against, when composing a shortcode.
* [New] Added new option to avoid that Slimstat assigns a COOKIE to your visitors. Thank you, [dragon013](https://wordpress.org/support/topic/disable-cookies-2/).
* [Fix] A bug was preventing the feature to "restrict users" to only see their reports from working as expected.

= 4.7.4.1 =
* [Update] The Browscap data file is now loaded only when needed, thus removing its inherent overhead when unnecessary.
* [Update] The Browscap data file has been updated to the latest version available on their repository (ver 6026).
* [Fix] Addressed a remote XSS Vulnerability responsibly disclosed by one of our customers. Thank you, [riscybusiness](https://wordpress.org/support/topic/security-vulnerability-affecting-slimstat/).
* [Fix] Reintroduced the WHOIS pin, which has been removed by mistake because of a regression bug. Thank you, [brachialis](https://wordpress.org/support/topic/whois-location-icon-disappear/).

= 4.7.4 =
* [Update] New fields added to the Email Report and Export to Excel add-ons, by extending how certain reports are defined in core.
* [Fix] The [false positive](https://www.virustotal.com/#/file/43f69d9c4028f857b5b5544ea4559c03b4d58e02d75617482db517c626164363/detection) alert related to a virus in our code was fixed by updating [AmChart](https://www.amcharts.com/) to the latest version available (thank you, Sasa).
* [Fix] Removed a PHP warning of undefined index (thank you, [slewis1000 and Sasa](https://wordpress.org/support/topic/php-notice-undefined-index-country/))
* [Fix] The MozScape report was causing connectivity issues for some users, and it is now set as "hidden" by default.
* [Fix] Regression bug related to our Export to Excel add-on.

= 4.7.3.1 =
* [Fix] Apparently more people than we initially thought have issues with the MaxMind data file not being saved as expected. We are introducing a temporary fix while we try to investigate this issue further.

= 4.7.3 =
* [Fix] A [few users](https://wordpress.org/support/topic/cannot-install-maxmind-geolite-db/) pointed out a weird behavior when installing the MaxMind Geolocation data file, where an empty folder would be created instead of the actual file. If you still experience issues related to this problem, please make sure to delete the empty folder "maxmind.mmdb" under `wp-content/uploads/wp-slimstat/`.
* [Fix] Apparently Microsoft Security Essentials [was not pleased with our code](https://wordpress.org/support/topic/trojandownloader097m-donoff-detected-in-archive/), and was returning a false positive alert that a virus was included with the source code (thank you, Sasa).
* [Fix] The "content_id" filter could not be used in a shortcode to reference other pages (i.e. `[slimstat f='count-all' w='id']content_id equals 2012[/slimstat]`). Thank you, Felipe.
* [Fix] Country flags were not being displayed properly under certain circumstances (thank you, [Catmaniax](https://wordpress.org/support/topic/minor-issue-missing-png-file/)).
* [Fix] Bug preventing the new Heatmap Add-on from working as expected.

= 4.7.2.2 =
* [New] Added support for SCRIPT_DEBUG: by defining this constant in your `wp-config.php` will make Slimstat load the unminified version of the javascript tracker (thank you, Sasa)
* [Update] Added new parameter to make the `admin-ajax.php` URL relative, to solve issues like [this one](https://wordpress.org/support/topic/xmlhttprequest-cannot-load-wp-adminadmin-ajax-php-3/).
* [Fix] The Network Settings premium add-on was not working because of a bug in the main plugin. Thank you, Steve, for pointing us into the right direction.
* [Fix] Updated the schema (columns) for the archive table.


= 4.7.2.1 =
* [Fix] The new table columns "location" and "city" were not being created on a fresh install (thank you, [nielsgoossens](https://wordpress.org/support/topic/no-data-anymore-2/#post-9491034))
* [Fix] Async mode was not working as expected (thank you, [keithgbcc](https://wordpress.org/support/topic/doesnt-work-1694/#post-9487448))

= 4.7.2 =
* [New] As those who have been using Slimstat for a while know, we never stop doing our good share of research and development to improve our plugin. One feature on our wishlist was to make the geolocation functionality more accurate. Specifically, users have been asking us to track not just the Country of origin, but possibly the state and city. In order to geolocate visitors, our code has been leveraging a third-party data file provided by [MaxMind.com](https://www.maxmind.com/en/home). A while ago, they launched a new data format, which improves performance and offers a way to quickly determine the city of origin. However, the new library required a higher version of PHP, and up until now we had been hesitant to adopt it, to allow more people to use our plugin, over the chance of offering this feature. Now, after spending some time combing through their code, we found a way to get the best of both worlds: by customizing their PHP library, we were able to make it work with PHP 5.3! Which means that now Slimstat is able to tell you your visitors' city of origin (and State, when applicable) right out of the box. This information is available in the Access Log report and in a new 'Top Cities' report under the Audience tab. Please note: the MaxMind data file to enable this feature is approximately 60 Mb, and for this reason <strong>this new functionality is not enabled by default</strong>. You must go to Slimstat > Settings > Tracker and turn on the corresponding option. Then go to Slimstat > Settings > Maintenance and uninstall/install the GeoLite file to download the one that contains the city data. Please feel free to contact us if you have any questions.
* [Update] Removed backward compatibility code for those updating from a version prior to 4.2. Hopefully most of our users are using a newer version that that. If you're not, please contact our support service for instructions on how to upgrade.
* [Update] The format used to save your settings in the database has been changed. You MUST update your premium add-ons as soon as possible, and get the version compatible with this new format, or you might notice unexpected behaviors. Please contact us if you experience difficulties updating your add-ons.
* [Update] Cleaned up some old CSS code affecting the reports.

= 4.7.1 =
* [Fix] The new feature introduced in version 4.6.9.1 to allow our users to customize the default time range for the reports, had introduced a regression bug. Thank you to all our users who volunteered to test the bugfix.
* [Fix] A vulnerability has been disclosed by [Pluginvulnerabilities.com](pluginvulnerabilities.com): an attacker with admin credentials could leverage the import/export mechanism for the plugin's settings to inject some malicious code. We recommend that you upgrade to the latest version of Slimstat as soon as possible.
* [Fix] The new version of the [Add-on Update Checker library](https://github.com/YahnisElsts/plugin-update-checker), bundled with the previous release, was returning a fatal error under certain circumstances (thank you, Pepe).

