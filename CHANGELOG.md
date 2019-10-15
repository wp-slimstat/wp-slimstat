## Changelog ##
### 4.8.8.1 ###
* [Update] The Privacy Mode option under Slimstat > Settings > Tracker now controls the fingerprint collection mechanism as well. If you have this option enabled to comply with European privacy laws, your visitors' IP addresses will be masked and they won't be fingerprinted (thank you, Peter).
* [Update] Improved handling of our local DNS cache to store hostnames when the option to convert IP addresses is enabled in the settings.
* [Fix] Redirects from the canonical URL to its corresponding nice permalink were being affected by a new feature introduce in version 4.8.7 (thank you, [Brookjnk](https://wordpress.org/support/topic/slimstat-forces-wordpress-to-use-302-redirects-instead-of-301/)).
* [Fix] The new tracker was throwing a Javascript error when handling events attached to DOM elements without a proper hierarchy (thank , you [pollensteyn](https://wordpress.org/support/topic/another-javascript-error/)).
* [Fix] Tracking downloads using third-party solutions like [Download Attachments](https://wordpress.org/plugins/download-attachments/) was not working as expected (thank you, [damianomalorzo](https://wordpress.org/support/topic/downolads-stats/)).
* [Fix] Inverted stacking order of dots on the map so that the most recent pageviews are always on top, when dots are really close to each other.
* [Fix] A warning message was being returned by the function looking for search keywords in the URL (thank you, Ryan).

### 4.8.8 ###
* [New] Implemented new [FingerPrintJs2](https://github.com/Valve/fingerprintjs2) library in the tracker. Your visitors are now associated with a unique identifier that does not rely on cookies, IP address or other unreliable information. This will allow Slimstat to produce more accurate results in terms of session lenghts, user patterns and much more.
* [New] Added event handler for 'beforeunload', which will allow the tracker to better meausure the time spent by your visitors on each page. This will make our upcoming new charts even more accurate.
* [Update] Improved algorithm that scans referrer URLs for search terms, and introduced a new list of search engines. Please help us improve this list by submitting your missing search engine entries.
* [Update] Added title field to Slimstat widgets (thank you, [jaroslawistok](https://wordpress.org/support/topic/broken-layout-from-3rd-widget-in-footer/)).
* [Update] Reverted a change to the Top Web Pages report that was now combining URLs with a trailing slash and ones without one into one result. As James pointed out, this is a less accurate measure and hides the fact that people might be accessing the website in different ways.
* [Update] The event tracker now annotates each event with information about the mouse button that was clicked and other useful data.
* [Fix] The Country shortcode was not working as expected because of a change in how we handle localization files.
* [Fix] Renamed slim_i18n class to avoid conflict with external libraries.

### 4.8.7.3 ###
* [New] Implemented a simple query cache to minimize the number of requests needed to crunch and display the data in the reports.
* [Update] Extended tracker to also record the 'fragment' portion of the URL, if available (this feature is only available in Client Mode).
* [Update] Do not show the resource title in Network View mode.
* [Fix] Some reports were not optimized for our Network Analytics add-on (thank you, Peter).
* [Fix] The notice displayed to share the latest news with our users would not disappear even after clicking the X button to close it (thank you, Anton).
* [Fix] The 'Top Web Pages' reports was listing duplicate entries.
* [Fix] A regression bug was affecting the Currently Online report, by showing incorrect information for the IP addresses.
* [Fix] After resetting the Customizer view, all reports were being listed twice (thank you, Anton).
* [Fix] A new feature introduced by our Javascript tracker was not working as expected in IE11 (thank you, [51nullacht](https://wordpress.org/support/topic/js-error-in-wp-slimstat-js321/)).

### 4.8.7.2 ###
* [Fix] The new tracker was having problems recording clicks on SVG elements within a link (thank you, [pollensteyn](https://wordpress.org/support/topic/javascript-error-typeerror/#post-11926542)).
* [Fix] The event handler is now capable of tracking events on BUTTONs within FORM elements.
* [Fix] Permalinks containing post query strings were not being recorded as expected.

### 4.8.7.1 ###
* [Note] We are in the process of deprecating the two columns *type* and *event_description* in the events table, and consolidating that information in the *notes* field. Code will be added to Slimstat in a few released to actually drop these columns from the database. If you are using those two columns in your custom code, please feel free to contact our support team to discuss your options and how to update your code using the information collected by the new tracker.
* [Fix] A warning message was being displayed when enabling the opt-out feature with certain versions of PHP.
* [Fix] PHP warning being displayed when trying to update some of the add-ons' settings.
* [Fix] The new tracker was recording the number of posts on an archive page even when the single article was being displayed.
* [Fix] License keys for premium add-ons were not being saved as expected, due to a side effect of the new security features we implemented in the Settings.

### 4.8.7 ###
* [New] With this update, we are introducing an *updated tracker* (both server and client-side): a new simplified codebase that gets rid of a few layers of convoluted functions and algorithms accumulated over the years. We have been working on this update for quite a while, and the recent conflict with another plugin discovered by some users convinced us to make this our top priority. Even though we have tested our new code using a variety of scenarios, you can understand how it would be impossible to cover all the possible environments available out there. Make sure to clear your caches (local, Cloudflare, WP plugins, etc), to allow Slimstat to append the new tracking script to your pages. Also, if you are using Slimstat to track *external* pages (outside of your WP install), please make sure to update the code you're using on those pages with the new one you can find in Slimstat > Settings > Tracker > External Pages.
* [New] Increased minimum WordPress requirements to version 4.9, given that we are now using some more modern functions to enqueue the tracker and implement the customizer feature.
* [Update] We tweaked the SQL query to retrieve 'recent' results, and added a GROUP BY clause to remove duplicates. This might affect some custom reports you might have created, so please don't hesitate to contact us if you have any question or experience any issues.
* [Update] The columns to store event type and description are being deprecated, and consolidated into the existing 'notes' column. Our function `ss_track()` will only accept the note parameter moving forward. Please update your custom code accordingly. In a few releases, we are going to drop those columns from the database.
* [Update] Changed wording on Traffic Sources report to explain what kind of search engine result pages are being counted (thank you, Nina).
* [Fix] The button to delete all the records from the database was not working as expected (thank you, [Softfully](https://wordpress.org/support/topic/delete-all-records-doesnt-work/)).
* [Fix] When the plugin was network activated on a group of existing blogs, the tables used to store all the records were not initialized as expected, under given circumstances.
* [Fix] A bug was affecting certain shortcodes when PHP 7.2 was enabled (thank you, Peter).
* [Fix] Emptying one of the settings and saving did not produce the desired effect.

### 4.8.6.2 ###
* [Update] We tweaked the SQL query to retrieve 'recent' results, and added a GROUP BY clause to remove duplicates. This might affect some custom reports you might have created, so please don't hesitate to contact us if you have any question or experience any issues.
* [Fix] The button to delete all the records from the database was not working as expected (thank you, [Softfully](https://wordpress.org/support/topic/delete-all-records-doesnt-work/)).

### 4.8.6.1 ###
* [Fix] A regression bug was introduced in 4.8.6, affecting some of the shortcodes and reports.

### 4.8.6 ###
* [New] Slimstat can now track most WordPress redirects and mark them with the appropriate content type.
* [Update] The GDPR compliance through third-party tools is now more flexible and allows admins to specify name/value pairs so that the cookie must CONTAIN the given string.
* [Update] Simplified code that manages the sidebar menu.
* [Update] Reorganized code that manages the plugin options.
* [Update] Rewrote the portion of code that manages tracker errors, which are now saved in a separate field in the database.
* [Update] Reintroduced feature to hide certain report pages when no reports are assigned to them.
* [Update] Decrease the number of database requests needed to record a new pageview.
* [Fix] Entries with a trailing slash and ones without were being listed as separate in Top Web Pages.
* [Fix] Typo in one of the conditions definining the Top Bots report.

### 4.8.5.1 ###
* [Fix] A bug was affecting the way shortcodes were being displayed on the website (thank you, [inndesign](https://wordpress.org/support/topic/crashes-avada-theme-in-chrome/)).
* [Fix] Some icons in the Access Log were broken and not displayed as expected.
* [Fix] Added extra code to make sure a callback function is defined for any given report.
* [Fix] Top reports where displaying an incorrect percentage value on the WordPress dashboard (thank you, [scruffy1 and a305587](https://wordpress.org/support/topic/dashboard-widgets-showing-0)).

### 4.8.5 ###
* [New] Introduced option to not track pageviews based on the ACCEPT-LANGUAGE header sent by the browser.
* [New] Introduced option to display a pageview count instead of the percentage in Top reports.
* [New] Introduced two new reports under the Audience tab: Tob Bots and Top Human Browsers.
* [Update] Removed the option to hide reports on tabs, as it was confusing users who couldn't find them. Now you can simply use the Customizer to arrange your reports, and place the ones you don't need in the Inactive box.
* [Update] Rewritten the code that manages which reports are displayed on which screen (Customizer), streamlined data structures and optimized their use. Please update all the add-ons to the latest version available. Don't hesitate to contact us if you have any questions!
* [Fix] The HTML markup in the opt-out message field was being stripped out (thank you, [paulmcmanus](https://wordpress.org/support/topic/saving-settings-flips-opt-out-message-to-plain-text/)).
* [Fix] Reports could not be properly deleted in the Customizer, if the Slimstat menu was displayed in the Admin Bar.
* [Fix] A fatal error thrown by the Maxmind library when the data file is corrupted has been addressed.
* [Fix] The icon filename for Windows 8.1 was incorrect (thank you, Dimitri).

### 4.8.4.1 ###
* [Note] As anticipated a few weeks ago, this update drops the information about your visitors' browser plugins, which had been deprecated as not useful and oftentimes unreliable. Please make sure to backup your Slimstat tables if you need to preserve this information for some reason.
* [Update] We received quite a few messages complaining about our decision to change the default position of the Slimstat menu from the sidebar to the admin bar. We are rolling back this change, and we apologize for any confusion this might have caused.
* [Update] Added visitor's language to the Activity Log report.
* [Update] Introduced code optimizations to improve performance when localizing strings related to operating systems, languages, countries, etc.
* [Fix] Page URLs were not being displayed correctly if the option to display page titles was turned off.
* [Fix] Not storing empty values in the database: leave as NULL. This will squeeze a few more bytes out of each row stored in the database.

### 4.8.4 ###
* [Note] If you're using any of our premium add-ons, please make sure to update them to the latest version available (see Slimstat > Add-ons) as we've updated some references in our code.
* [Note] We recently received an email from one of our users suggesting that we replace the line charts currently used to display reports over a timeline with **bar charts**, because 'the number of pageviews and IPs are discrete numbers, hence they should also be presented as discrete numbers', according to him. What do you think? Please let us know by [sending us a message](https://support.wp-slimstat.com/) on our support platform. Thank you.
* [Update] Renamed a few files in the admin. If you're including Slimstat libraries in your custom code, please make sure to check that your references are up-to-date. Also, make sure to clear your cache if you page layout doesn't look right.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4.5.3.
* [Update] When functioning in Client mode, the tracker will now not ignore bots, spiders and the like automatically. Please use the appropriate option under Settings > Exclusions if you would like to ignore bots. This solves an incompatibility issue with some caching plugins which "prefetch" the website, presenting themselves as bots.
* [Update] Removed tracker notice field under Settings > Maintenance as it was confusing many people and generating extra work for our customer service team.
* [Update] Removed option to not track "client properties" like screen resolution, etc. Also, removed option to not honor DNT headers, as we received complaints from privacy activists on this matter.
* [Update] Removed option to change date/time formats and numeric separators: Slimstat will now use the WordPress settings to adjust its behavior.
* [Update] Removed 'About Slimstat' report, given that some of the information in it has been moved to the Settings.
* [Update] Removed unused strings, improved contextual descriptions and applied consistent naming conventions across our codebase (first pass).
* [Update] The Slimstat admin menu is now added to the Admin Bar by default. Please go to Settings > Basic > WordPress Integration and change the corresponding option, if you prefer to use the side menu instead.
* [Update] Enabled code editor in Settings.
* [Update] Implemented a new optimized function to retrieve the post count on the Edit Posts/Pages/CPTs screens. Thank you, Lance.
* [Update] Improved browser detection feature, which will now fallback to the heuristic function if the Browscap data file doesn't contain an exact match for a given browser. This usually happens whenever a new browser version is released, which is not yet included in the data file.
* [Update] Option to track same-domain referrers is now deactivated by default on new installations.
* [Update] Enabled wildcards on the exclusion rule by capability.
* [Update] Improved the overall source code readability score. Now you don't have any other excuses to not contribute to this project!
* [Update] Table indexes are now enabled by default in the database.
* [Update] Added new WordPress filter to the Browscap Library, so that third-party tools can manipulate the data before it's returned to the tracker.
* [Update] Added [nonce](https://wordpress.org/support/article/glossary/#nonce) to Settings page for improved security.

### 4.8.3 ###
* [Note] Thank you for all the great feedback you provided to our unofficial survey about retiring the 'browser plugins' feature. The vast majority of those who replied confirmed what we already thought. Please consider backing up your database if you would like to preserve this information for future analysis. With this update, we removed the portion of code that tracks that information, but kept the existing data untouched. In a couple of releases, code will be added to actually drop this column from the database.
* [New] If English is not your primary languge, Slimstat will now display a notice asking for your help to [translate our plugin](https://translate.wordpress.org/projects/wp-plugins/wp-slimstat/) in your language. Please consider volunteering for this great opportunity to help our community!
* [Update] We are working with the GlotPress community to improve the way Slimstat speaks your language. We had to change the way certain strings are defined in our source code. Please let us know if you notice any unexpected behavior when analyzing languages, countries and operating systems.
* [Update] Removed Facebook rankings metrics, as the API has been deprecated and the new one is not accessible without a private token.
* [Update] MozRank has been deprecated, we have replaced it with the Domain Authority metric.
* [Update] Spring cleaning in the 'admin notices' department: removed some obsolete CSS code, replaced by built-in WP classes and definitions.
* [Fix] Changed the default minimum capability to access the reports from 'activate_plugins' to 'manage_options', so that regular administrators (a.k.a. non-super admins) in a multisite environment can still see their own reports (thank you, [homepageware](https://wordpress.org/support/topic/slimstat-and-multisite/)). This update does not affect existing installations: if you want regular admins to see their own stats, please go to Slimstat > Settings > Access Control and change the values in the corresponding fields.
* [Fix] The autorefresh feature for the Access Log was not working as expected. Thank you to all the users who patiently worked with us to identify the issue.
* [Fix] A conflict between the Async loader and AmCharts 4 was causing the Screen Options tab to not work as expected (thank you, [softfully](https://wordpress.org/support/topic/screen-options-doesnt-open/)).
* [Fix] Removed unused setting 'Expand Reports'

### 4.8.2 ### 
* [Note] Our team has been contemplating the idea of deprecating the information collected about your visitors' *browser plugins* (Java, PDF reader, RealView player, Silverlight, etc). In this day and age, where browsers use either built-in functionality to provide those features, or extensions that cannot be tracked for privacy purposes, it feels anachronistic to continue collecting this outdated information. By getting rid of this specific feature, we can streamline our code, improve performance, and reduce the database size. However, we wanted to hear from our users before anything is actually implemented. Please do not hesitate [to let us know](https://support.wp-slimstat.com) if you are using the 'browser plugins' field for your reporting needs.
* [New] Many CRM integration plugins rely mostly on the user emails, not usernames. For this reason, a new email field has been added to the database (thank you, [sandrodz](https://github.com/sandrodz)).
* [Update] Changed the preset intervals in the date filter dropdown so that you can get a day over day comparison (Monday over Monday, etc) for improved accuracy.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4.4.9.
* [Fix] The countdown timer on the Access Log was not working as expected (thank you, [anniest](https://wordpress.org/support/topic/no-refresh-2/)).
* [Fix] The countdown timer was causing an warning message to appear on other screens.
* [Fix] Minor aesthetic improvements.

### 4.8.1 ###
* [Update] Async mode will now serialize concurrent requests to the backend to optimize performance and reduce server load.
* [Fix] Addressed a remote XSS vulnerability disclosed by Sucuri/GoDaddy.
* [Fix] Charts were displaying the wrong label for certain values (thank you, Alex).

### 4.8 ###
* [Note] Now that we have a cleaner foundation to build on, it's time to start introducing new reports and new ways to segment your audience and the traffic they generate. While our users test the latest changes and updates (to confirm that the foundation is indeed solid and bug-free), we are hard at work implementing the first batch of new reports. Some of them will be made available in the free version, while others will be added to our premium add-on, [User Overview](https://www.wp-slimstat.com/downloads/user-overview/). And we need your help! If you think that a specific report should be added to Slimstat, please do not hesitate to let us know!
* [Note] Worried about the recent [news regarding jQuery vulnerabilities](https://www.zdnet.com/article/popular-jquery-javascript-library-impacted-by-prototype-pollution-flaw/)? Slimstat doesn't use jQuery as a dependency, so you can sleep tight knowing that your website will not be affected.
* [Update] [AmCharts](https://www.amcharts.com/javascript-charts/), the library used to render all of our charts, has been updated to version 4. This new release is not backward compatible, so the code to integrate it with Slimstat had to be completely rewritten. Please let us know if you notice any issues.
* [Update] [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), the library we use to check if a new version of our premium add-ons is available for download, has been update to version 4.6.
* [Update] If you're using our partner's CDN functionality (JsDelivr) to load the tracker, their link is now always loaded over HTTPS for added security.
* [Update] Switched the Add-on Update checker URL to HTTPS, for added security (thank you, Peter).
* [Update] Changed the protocol of all the URLs used within Slimstat, including references to our documentation, to HTTPS.
* [Update] Added icon to geolocate Originating IP addresses, when detected.
* [Fix] The optout cookie path was not being set correctly (thank you, [ralfkerkhoff](https://wordpress.org/support/topic/opt-out-cookie-per-page/)).
* [Fix] Google seems to be using a new User Agent string for its "mobile" crawler, which was causing Slimstat from incorrectly identifying visits as coming from mobile devices, instead of bots (thank you, Ron).
* [Fix] An error was being returned if SVG elements were using the A tag on a page (thank you, [snaphappyme](https://wordpress.org/support/topic/uncaught-typeerror-all_linksn-href-indexof/)).
* [Fix] A bug was causing Slimstat to incorrectly geolocate visits to websites behind a Cloudflare load balancer. Please update the IP Address Fix add-on as well.
* [Fix] Tweaked the formula to determine your website bounce rate, and updated the associated description to better reflect the underlying calculations.
