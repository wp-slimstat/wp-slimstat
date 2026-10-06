# Slimstat Analytics #
Real-time WordPress analytics that stay on your own server. Track pageviews and outbound links, build WooCommerce funnels, and keep every visitor privacy-first and GDPR-ready, with no data ever shared with Google. Thousands of WordPress sites already trust it to keep their numbers honest, fast, and entirely their own.

**v5.5.0** — New Goals & Funnels: define a goal to measure any conversion, then chain 2–5 steps into a funnel to see your conversion rate and exactly where visitors drop off, with ready-made templates for WooCommerce checkout, SaaS signup, and content engagement. [Full changelog](https://github.com/wp-slimstat/wp-slimstat/blob/development/CHANGELOG.md).

### Main features ###
* **Real-time access log** — Your site's pulse, live. Watch each visit land the instant it happens: the page, the spot on the map, the search or link that sent them, how quickly your server replied, human or bot.
* **Complete access log** — Every visit in one searchable table. Drill into the full history and break it down by date, country, browser, OS, referrer, search term, or content type to answer the questions the summary charts can't.
* **Goals & funnels** — Turn raw traffic into answers. Define a goal to measure a conversion (a WooCommerce sale, a signup, a key pageview) and see uniques, totals, and conversion rate. Or chain steps into a funnel to spot exactly where visitors drop off. One goal is free; up to five goals and full funnels unlock with Pro.
* **Outbound link report** — See which external links actually earn clicks. SlimStat records every outbound link your visitors follow, so you know what's sending traffic off your site and which partnerships pull their weight.
* **Know your visitors** — Go past pageviews to the people behind them: returning readers, logged-in users, and a full audience breakdown by country, language, browser, OS, and screen size. (Pro's User Overview adds per-visitor journeys, time on site, and Gravatars.)
* **Your data, your server** — No third-party cloud, no Google looking over your shoulder. Every byte lives in your WordPress database, and one-way IP hashing lets you count unique visitors without ever storing who they are.
* **GDPR, sorted** — Anonymize or hash IPs, honor Do Not Track, auto-purge old records on a schedule, and drop in a translatable consent banner that snaps straight into the WP Consent API (WPML and Polylang welcome). Compliance by design, not an afterthought.
* **Admin bar stats** — Keep an eye on the numbers without leaving your work. Online visitors, pageviews, and top pages sit one glance away in the WordPress admin bar, on every screen.
* **Make every report yours** — Rearrange, add, or hide widgets across Real-Time, Overview, Audience, Site Analysis, and Traffic Sources until each screen shows exactly what you care about.
* **Shortcodes** — Drop any report into a widget, post, or page with a single shortcode.
* **Filters** — Decide who counts. Skip your own team, known bots, whole IP ranges, admin pages, or entire countries so your stats reflect real visitors, not noise.
* **Geolocation** — Put a city and country to every visitor, plus their browser and operating system, powered by [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org).
* **World map** — Watch your audience light up across the globe at a glance, even from your phone (map by [JQVMap](https://github.com/10bestdesign/jqvmap)).
* **Export & email** — Download your reports as CSV files, generate user heatmaps, or get the day's numbers in your inbox each morning (heatmaps and email reports via Pro).
* **Cache-friendly** — Plays nicely with W3 Total Cache, WP Super Cache, Cloudflare, and most caching plugins.

### Contribute ###
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [GitHub repository](https://github.com/wp-slimstat/wp-slimstat) and submit code enhancements, bug fixes, or localization files to help the plugin speak even more languages. And if coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) as a token of appreciation for our hard work.

### Requirements ###
* WordPress 5.6+
* PHP 7.4+
* MySQL 5.6+ (or MariaDB 10.0+)
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)