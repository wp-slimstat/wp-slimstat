# Slimstat Analytics #
Real-time WordPress analytics that stay on your own server. Track pageviews and outbound links, build WooCommerce funnels, and configure consent and retention controls, with no analytics sent to Google. Thousands of WordPress sites already trust it to keep their numbers honest, fast, and entirely their own.

**v6.1.0** — Turn any report into plain-language findings with AI, follow a country down to its cities, and learn SlimStat from video tutorials. SlimStat 6 brought heatmaps, a WooCommerce revenue dashboard, channels and UTM campaigns, and faster reports. [Full changelog](https://github.com/wp-slimstat/wp-slimstat/blob/master/CHANGELOG.md).

### Main features ###
* **Real-time analytics** — Your site's pulse, live. Watch each visit land the instant it happens: the page, the spot on the map, the search or link that sent them, how quickly your server replied, human or bot. No sampling, no 24-hour delay.
* **WooCommerce revenue dashboard** — Net sales, orders, period comparisons, top products and where your buyers came from, in one page next to your traffic. Switch it on when you're ready; order sync runs quietly in the background and supports HPOS. WooCommerce 8.3+ required.
* **Heatmaps** — See which pages get clicked, how often, on which devices, and the five most clicked links and buttons on each page. SlimStat Pro paints full click and scroll heatmaps over your live pages.
* **Channels and UTM campaigns** — Know where every visit really came from: search, social, email, paid, AI assistants and crawlers, or a campaign you tagged. Build and copy campaign links straight from the report.
* **Goals & funnels** — Turn raw traffic into answers. Define a goal (a WooCommerce sale, a signup, a key pageview) and see uniques, totals and conversion rate. Chain steps into a funnel to spot exactly where visitors drop off. One goal is free; up to five goals and full funnels unlock with Pro.
* **Built for performance** — A light tracker that writes far less per pageview, charts that read half as many database rows, and a choice of client-side tracking for cached sites or server-side to catch every request.
* **Complete access log** — Every visit in one searchable table. Drill into the full history and break it down by date, country, browser, OS, referrer, search term or content type to answer the questions the summary charts can't.
* **Outbound links & downloads** — See which external links and files actually earn clicks, so you know what sends traffic off your site and which partnerships pull their weight.
* **Know your visitors** — Go past pageviews to the people behind them: returning readers, logged-in users, and a full audience breakdown by country, language, browser, OS and screen size. (Pro's User Overview adds per-visitor journeys, time on site and Gravatars.)
* **Your data, your server** — No third-party cloud, no Google looking over your shoulder. Every byte lives in your WordPress database, and one-way IP hashing lets you count unique visitors without ever storing who they are.
* **Privacy controls** — Anonymize or hash IPs, honor Do Not Track and Global Privacy Control, auto-purge old records on a schedule, and drop in a translatable consent banner that snaps straight into the WP Consent API (WPML and Polylang welcome). Review these controls against your site’s requirements.
* **Geolocation & world map** — Put a city and country to every visitor, plus their browser and operating system, powered by [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org). Watch your audience light up across the globe, even from your phone (map by [jsVectorMap](https://github.com/themustafaomar/jsvectormap)).
* **Shortcodes & Shortcode Playground** — Drop any report, live counter or Ecommerce KPI into a post, page or widget. Browse, preview with your own data and copy the shortcode under SlimStat → Shortcodes.
* **Admin bar stats** — Keep an eye on the numbers without leaving your work. Online visitors, pageviews and top pages sit one glance away in the WordPress admin bar, on every screen.
* **Make every report yours** — Rearrange, add or hide widgets across Real-Time, Overview, Audience, Site Analysis and Traffic Sources until each screen shows exactly what you care about.
* **Filters** — Decide who counts. Skip your own team, known bots, whole IP ranges, admin pages or entire countries so your stats reflect real visitors, not noise.
* **Export & email** — Download your reports as CSV files, or get the numbers that matter in your inbox each morning (email reports and Excel export via Pro).
* **Cache-friendly** — Plays nicely with W3 Total Cache, WP Super Cache, Cloudflare and most caching plugins.

### Contribute ###
Slimstat Analytics is an open source project, dependent in large part on community support. You can fork our [GitHub repository](https://github.com/wp-slimstat/wp-slimstat) and submit code enhancements, bug fixes, or localization files to help the plugin speak even more languages. And if coding is not your thing, please consider writing [a review](https://wordpress.org/support/plugin/wp-slimstat/reviews/#new-post) as a token of appreciation for our hard work.

### Requirements ###
* WordPress 5.6+
* PHP 7.4+
* MySQL 5.6+ (or MariaDB 10.0+)
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)