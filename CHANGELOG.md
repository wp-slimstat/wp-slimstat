## Changelog ##
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

