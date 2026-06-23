=== SlimStat Analytics ===
Contributors: veronalabs, coolmann, toxicum, parhumm, mostafas1990
Tags: analytics, statistics, tracking, reports, geolocation
Text Domain: wp-slimstat
Requires at least: 5.6
Requires PHP: 7.4
Recommended PHP extensions: fileinfo (required if the Browscap library is enabled)
Tested up to: 7.0
Stable tag: 5.5.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real-time WordPress analytics that stay on your own server: pageviews, outbound links, WooCommerce funnels, all privacy-first and GDPR-ready.

== Description ==
Most analytics plugins quietly hand your visitors to someone else's cloud. SlimStat doesn't. Every pageview, click, and visitor lands in your own WordPress database and stays there: yours to read in real time, yours to purge whenever you want, never shipped off to Google or anyone else.

See the whole story the moment it happens: who's on your site right now, where they are in the world, what they're reading, and which links they follow on the way out. Set a goal and watch it convert, or chain a few steps into a WooCommerce funnel and see exactly where people slip away. It's the depth you'd expect from a hosted analytics service, running entirely on your own terms.

Best part: privacy isn't a setting you have to hunt for. Anonymized IPs, Do Not Track, a consent banner, and scheduled data cleanup are GDPR-ready from the first activation. Thousands of WordPress sites already trust SlimStat to keep their analytics honest, fast, and entirely their own.

= Main Features =
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

= Pro Pack Features =
Love the free plugin? Pro is for sites that live by their numbers. It adds the heavier tools without changing a thing about how SlimStat respects your data:

* **Email reports** — Wake up to the numbers that matter. Schedule the reports you care about and have them land in your inbox as clean HTML tables, with the columns laid out your way.
* **Heatmaps** — See exactly where visitors click, and where they don't, with a heatmap layer painted right over your live pages.
* **User overview** — Follow individual registered users: what they viewed, how long they stayed, and how fast your server answered, with Gravatars to put a face to the visit.
* **Extended overview** — Add your own columns to the User Overview report and export file, so it tracks exactly what your site cares about.
* **More goals & funnels** — Up to five conversion goals and three full funnels, with ready-made templates for WooCommerce checkout, signups, and content engagement.
* **Network analytics** — Run reports and settings across an entire multisite network from one place.
* **MaxMind integration** — Connect MaxMind's geolocation API for richer, more precise detail on where your visitors come from.
* **Custom database** — Store all your analytics in a separate, external database to keep your main WordPress DB lean.
* **Export to Excel** — Download any report as a ready-to-share Excel file.

= Requirements =
* WordPress 5.6+
* PHP 7.4+
* MySQL 5.0.3+
* At least 5 MB of free web space (240 MB if you plan on using the external libraries for geolocation and browser detection)
* At least 10 MB of free DB space
* At least 32 Mb of free PHP memory for the tracker (peak memory usage)

== Installation ==
1. In your WordPress admin, go to Plugins > Add New
2. Search for Slimstat Analytics
3. Click on **Install Now** next to Slimstat Analytics and then activate the plugin
4. Make sure your template calls `wp_footer()` or the equivalent hook somewhere (possibly just before the `</body>` tag)

== Please note ==
* If you decide to uninstall Slimstat Analytics, all the stats will be **PERMANENTLY** deleted from your database. Make sure to setup a database backup (wp_slim_*) to avoid losing your data.

= Report Bugs =
Having trouble with a bug? Please [create an issue](https://github.com/wp-slimstat/wp-slimstat/issues/new) on GitHub. Kindly note that [GitHub](https://github.com/wp-slimstat/wp-slimstat) is exclusively for bug reports; other inquiries will be closed.

For security vulnerabilities, please report them through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/wordpress/plugin/wp-slimstat/vdp). The Patchstack team will validate, triage, and handle any security issues.

== Frequently Asked Questions ==

= Where is my analytics data stored? =
In your own WordPress database, full stop. Core tracking makes no external calls, so your numbers never go to Google, to us, or to any third-party cloud.

= How is this different from Google Analytics or Jetpack Stats? =
Ownership and timing. Your data never leaves your server, and there's no sampling or 24-hour delay. You see visits the moment they happen and decide how long to keep them.

= Will SlimStat slow down my site? =
It's built to stay light. Choose client-side tracking for cached sites or server-side to catch every request, and SlimStat plays nicely with W3 Total Cache, WP Super Cache, Cloudflare, and the rest.

= Is it really GDPR-ready out of the box? =
Yes. IP anonymization and hashing, Do Not Track, a translatable consent banner, WP Consent API integration, and scheduled data purging are all built in, no extra plugins required.

= Do I need the Pro version? =
Not to get real value. The free plugin covers real-time tracking, dozens of reports, geolocation, and one conversion goal. Pro adds funnels, email reports, heatmaps, multisite, and more goals.

= Where can I find more help? =
An extensive knowledge base is available on our [website](https://www.wp-slimstat.com/).

== Screenshots ==
1. **Real-Time** — Your visitors, live as they browse: where they are, what they're reading, and what brought them in.
2. **World Map** — Your audience across the globe, identified by country, browser, and operating system.
3. **Overview** — Your whole site at a glance. One clean dashboard for the numbers you check every morning.
4. **Audience** — The full picture of who's visiting: location, language, browser, device, and screen size.
5. **Site Analysis** — Top pages, categories, downloads, and outbound links, all in one simple view.
6. **Traffic Sources** — Exactly where your visitors come from: search engines, social, and referring sites.
7. **Customize widgets** — Arrange each dashboard page around the reports you actually use.
8. **WordPress Dashboard** — Pin the reports you love, like Traffic Sources, right to your WordPress dashboard.
9. **Settings** — Plenty of room to shape how SlimStat tracks, stores, and shows your data.
10. **Goals & Funnels** — Define the conversions that drive revenue and watch each step of your WooCommerce checkout or signup funnel to see exactly where visitors drop off.

== Changelog ==
= 5.5.0 - 2026-06-24 =
* Feature: New Goals & Funnels page (slimview6) — define goals and funnels in a modern card layout with pill-segmented funnel tabs, a side drawer for goal create/edit, an overlay builder for funnels, and a destructive-action confirm sheet instead of native browser prompts.
* Feature: Funnels now support 4 conversion templates (E-commerce checkout, SaaS signup, Content engagement, Start from scratch).
* Feature: Goals gain a "Paused" state via the drawer toggle — paused goals are preserved but do not count against the plan limit.
* Feature: New AJAX endpoint `slimstat_load_funnel_data` lazy-loads step data for inactive funnel tabs, reducing the initial SQL load on multi-funnel pages.
* Fix: `ajax_save_goal` now counts only active goals against the `slimstat_max_goals` limit — pausing a goal now genuinely frees a slot.
* Fix: Funnels with no matching visitors in the selected date range now render "No matching visitors in this date range" instead of a misleading 100% conversion rate.
* Fix: Dashboard widget no longer leaks the inline "Add Goal" form — widget context now passes `is_widget=true` as designed.
* Improvement: CSS custom properties split into `tokens.css` (`--ss-*` namespace). Legacy `--slimstat-*` and `--gdpr-*` aliases preserved at their existing values — datepicker and GDPR banner unaffected.
* Improvement: Goals & Funnels CSS/JS now enqueue only on screens that actually render `slim_p9_01` / `slim_p9_02`, honoring the Customizer's drag-between-screens feature.
* Improvement: Single canonical "Upgrade to Pro" label on this view (replacing previous mix of "Unlock SlimStat Pro" and "Upgrade to Premium").
* Improvement: Brand red ramp replaces indigo for funnel bars; supports `prefers-reduced-motion` and full RTL mirroring.
* Refactor: `show_goals()` / `show_funnels()` branch on `is_widget` and include new partials (`admin/view/partials/goals-funnels/*.php`) in admin mode; widget/shortcode/email/CSV paths preserved unchanged.
* Tests: New PHPUnit Integration suite covers all AJAX handlers (save/delete goal+funnel, new `ajax_load_funnel_data`), cache-version invalidation, paused-limit behavior, and the legacy CSS alias preservation.
* Security: Fixed a stored XSS affecting sites using the Cloudflare geolocation provider — a crafted `CF-IPCountry` request header could be saved as a visitor's country and execute script when an administrator opened the Audience or Access Log report. The country is now validated to a two-letter code before storage, and country/language values are escaped wherever they render into report flag images and links. Reported via WPScan.
* Fix: WordPress admin no longer crashes on PHP 7.4 hosts (an admin page was calling a function that only exists in PHP 8.0+).
* Fix: Visit tracking no longer returns a 500 error on hosts without the optional PHP `fileinfo` extension. The plugin falls back to its built-in browser detector and shows a dismissible admin notice.
* Fix: An IP-filter bug on PHP 8.1 silently added 8 extra binary bits for invalid IPs, which could make rules like "ignore my IP" behave incorrectly. Filters now match correctly across all PHP versions.
* PHP 8.1+ readiness: cleaned up six internal function signatures so they no longer trigger deprecation warnings in `debug.log`. These would have become fatal errors on PHP 9.0 — the plugin is now ready for that transition.
* Fix: On PHP 8.0+, clearing the Posts-list pageviews-column interval no longer shows an empty day count — it falls back to 28 days as it did on PHP 7.
* Tested up to WordPress 7.0; removed an obsolete WordPress-3.3 version check.
* PHP 8.0/8.3/8.4 readiness: prepared the admin JavaScript for jQuery 4.0 (no behavior change today) and removed a stray, never-loaded bundled file.
* Internal: Added a compatibility shim so modern PHP idioms work the same on older PHP 7.4 hosts. Expanded automated CI testing to cover PHP 7.4 through 8.5 (was 7.4–8.3) and to boot the full WordPress 5.6–7.0 matrix end-to-end; added PHPStan static analysis. The PHP 7.4 lane now runs real tests on every change instead of just lint checks. Added `CONTRIBUTING.md` and a few small style cleanups in the test suite.

= 5.4.12 - 2026-05-13 =
* Security: Authenticated SQL injection in the chart AJAX endpoint (slimstat_fetch_chart_data) is now blocked. The `chart_data.where` parameter is validated against the trusted report registry before reaching the query layer. Reported via Patchstack (CVSS 8.5, High).
* Security: Patch unauthenticated stored XSS via the User-Agent header (CVE-2026-7634). Storage::updateRow() now mirrors insertRow()'s sanitization, the User-Agent is sanitized at capture in Browscap, and admin tooltips are escaped via wp_kses_post(). Reported by Supakiad S. (m3ez) — E-CQURITY (Thailand) via Wordfence.
* Fix: Chrome-based mobile Googlebot and Bingbot now correctly blocked when Browscap classifies them as mobile devices (#14843)
* Fix: Google-InspectionTool mobile is now detected as a crawler
* Improvement: Bot detection regex extended with 15 new vendor tokens — Mediapartners-Google, Google-InspectionTool, Google-Site-Verification, Google Favicon, GoogleOther, GoogleAgent-Mariner, Google-Safety, DuplexWeb-Google, BingPreview, YandexDirect, YandexFavicons, WhatsApp preview, SkypeUriPreview, anthropic-ai, cohere-ai

= 5.4.11 - 2026-04-17 =
* Fix: Access Log pagination no longer drops the user's selected custom date range
* Fix: Auto Refresh setting in Settings → Reports is now honored
* Fix: Recent panels now show unique items instead of duplicating the same entry for every pageview
* Fix: Access Log "last page" no longer shows "No data to display"
* Fix: Report pagination totals are now stable across pages
* Fix: Outbound link tracking — correct sort order, sanitized URLs, and bounded storage
* Fix: Chrome-based crawlers (Googlebot, Bingbot) now correctly detected as bots
* Fix: Heatmap click positions validated and corrupted historical data recovered
* Improvement: Access Log auto-refresh pauses on hover, scroll, and hidden tab
* Improvement: Scroll position preserved across Access Log refresh
* Refactor: Replaced jQuery SlimScroll with native CSS scrolling

= 5.4.9 - 2026-04-03 =
* Fix: Scoped sortable handler to Slimstat Customize page only — prevents corrupting WordPress Dashboard widget layout
* Fix: Use sanitized URI in dashboard widget enqueue condition for consistency

= 5.4.8 - 2026-03-31 =

This release fixes remaining tracking issues from the 5.4.x upgrade cycle. If you upgraded from 5.3.x through 5.4.0-5.4.6, this update restores session cookies and client-side tracking automatically.

* Fix: Session cookies now restored for all upgrade paths, not just GDPR-disabled sites
* Fix: Client-side (JavaScript) tracking restored unconditionally — fixes zero tracking on cached sites
* Fix: Migration forced-resets gated to run once, preserving admin choices on future updates
* Fix: FingerprintJS v4 now generates fingerprints correctly — `.get()` call was missing since v3→v4 migration
* Fix: JS consent check now mirrors PHP logic when SlimStat banner is off
* Fix: Charts and reports now query the correct database for External DB addon users
* Fix: Real-time analytics queries use the correct database connection for External DB
* Fix: Complex report queries (e.g. Recent Events) now work with External DB addon
* Fix: Filter dropdown autocomplete now queries the correct database for External DB
* Fix: Visit counter seeds correctly from external database for Pro addon users
* Fix: Country percentages exceeding 100% in Audience Location map — query cache now stays fresh for live date ranges
* Fix: Filter removal via red cross button not working
* Fix: Outbound Link, Notes, and Category filter dropdowns now show individual values instead of raw concatenated strings
* Fix: Filter 'equals' operator now works on Outbound Link, Notes, and Category columns
* Fix: Chart granularity selection (Daily/Weekly/Monthly) persists across page reloads
* Fix: Chart granularity now syncs across all charts on the same page
* Fix: Chart timezone offset corrected for non-UTC servers
* Fix: Browscap Library now initializes WordPress filesystem before extraction (resolves toggle revert)
* Fix: Browscap errors now show specific failure details instead of generic messages
* Fix: Downloaded Browscap files validated as ZIP before extraction
* Fix: Browscap download compatible with hosts that block GitHub redirects
* Improvement: Chart granularity persisted via localStorage for cross-session consistency
* Improvement: sessionStorage access wrapped in try/catch for private browsing compatibility

= 5.4.6 - 2026-03-23 =

We heard you — upgrading to 5.4.x broke tracking for many of you. Visitor counts dropped to zero, IPs were masked without your permission, and a consent banner appeared on sites that never asked for one. This release fixes all of that. After updating, your site works the way it did before 5.4.0 — no manual steps required.

If you want to enable GDPR features:

* Consent banner: Settings → Tracker → Data Protection → GDPR Compliance Mode = On, then Settings → Tracker → Consent Management → choose SlimStat Banner, WP Consent API, or Real Cookie Banner
* Anonymize IPs: Settings → Tracker → Data Protection → Anonymize IP Addresses = On
* Hash IPs: Settings → Tracker → Data Protection → Hash IP Addresses = On

**Fixed**

* Visitor counts dropping to zero after upgrading: a consent banner was silently enabled on every site, blocking all anonymous visitors. The banner is now off by default. If you had configured opt-in or opt-out privacy features in an earlier version, we detect that and keep consent enabled for you automatically.
* IPs being masked or hashed without your permission: v5.4.0 changed IP storage defaults, so full IP addresses were replaced with anonymized or hashed values. Your IPs are now stored in full again, matching pre-5.4 behavior.
* Tracking broken on sites using WP Rocket, W3TC, or other caching plugins: fresh installs defaulted to server-side tracking, which doesn't work with page caching. We've restored browser-based (JavaScript) tracking as the default.
* Ad-blocker bypass failing after plugin updates: the bypass URL included the plugin version, so cached pages had a stale URL after every update. The bypass URL is now stable across versions.
* Internal tracking URLs and bypass file URLs appearing as pages in the Access Log. All SlimStat-internal URLs are now filtered from both reports and server-side tracking.
* Access Log pagination showing the same rows when clicking the next-page arrow. The second page now correctly shows the next set of results.
* Pageviews silently lost when a transport fails: the tracker now tries adblock-bypass, AJAX, and REST fallbacks before giving up.
* Stale cached tracker data causing abandoned pageviews: the tracker recovers gracefully.
* "Respect Do Not Track" setting only working when GDPR mode was on: DNT is now honored regardless of your GDPR setting. The DNT toggle is now always visible in settings.
* Migration admin notice linking to a non-existent settings page. The link now correctly opens Settings → Tracker → Data Protection.

**Improved**

* Tracker health diagnostics now distinguish between fatal errors and recoverable warnings.
* Session cookies are restored by default — returning visitors are recognized across pages again, just like in v5.3.x.
* Cookie info registered with WP Consent API now uses proper plural-aware translations.

= 5.4.5 - 2026-03-20 =
- **Fix**: Hardened user exclusion logic — fixed consent-upgrade path, capability key matching, and defensive `wp_get_current_user()` calls (#246)
- **Fix**: GDPR consent cookie domain, cached page banner display, and anonymous nonce handling
- **Fix**: Removed double-escaping in report filters and tightened XSS sanitization (#243, #244)
- **Fix**: Strict fingerprint input sanitization (#244)
- **Fix**: Output escaping in reports default case (#244)
- **Fix**: Store attachment content_type as `cpt:attachment` (#236)
- **Fix**: Narrowed dashboard nested widget CSS selectors to avoid style conflicts (#247)
- **Fix**: Increased Access Log widget height on WP Dashboard
- **Fix**: Synced stat before `ensureVisitId` to prevent ID loss on finalization
- **Fix**: Skipped REST nonce for anonymous users on non-consent tracking endpoints, removed dead adblock fallback URL
- **Security**: Restored nonce verification for all consent endpoints
- **Improved**: Refactored `isUserExcluded()` into standalone method with full test coverage
- **Improved**: Inlined `get_current_user_id()` in nonce guards for clarity

= 5.4.4 - 2026-03-17 =
- **Fix**: Chart data not showing due to incorrect bounds check ([PR #232](https://github.com/wp-slimstat/wp-slimstat/pull/232))
- **Fix**: Weekly chart not showing today's data and not respecting start_of_week setting ([PR #235](https://github.com/wp-slimstat/wp-slimstat/pull/235))
- **Improved**: Added `cpt:` prefix guidance to content type exclusion setting

= 5.4.3 - 2026-03-16 =
- **Fix**: Fixed fatal error on servers without the PHP calendar extension ([PR #229](https://github.com/wp-slimstat/wp-slimstat/pull/229))
- **Fix**: Added defensive fallback for corrupted `start_of_week` option in calendar-related reports
- **Improved**: Moved day names array to a class constant in DataBuckets for better maintainability

= 5.4.2 - 2026-03-15 =
- **Fix**: Fixed tracking data not being recorded on some server configurations — REST API and admin-ajax endpoints now return responses correctly ([PR #218](https://github.com/wp-slimstat/wp-slimstat/pull/218))
- **Fix**: Fixed visitor locations showing a proxy server IP instead of the real visitor IP on Cloudflare-powered sites ([#150](https://github.com/wp-slimstat/wp-slimstat/issues/150))
- **Fix**: Fixed 503 errors that could occur on high-traffic sites due to inefficient visit ID generation ([#155](https://github.com/wp-slimstat/wp-slimstat/issues/155))
- **Fix**: Fixed excessive server requests when WP-Cron is disabled, caused by repeated geolocation lookups ([#164](https://github.com/wp-slimstat/wp-slimstat/issues/164))
- **Fix**: Fixed a CSS rule that could accidentally disable animations across your entire site, not just on SlimStat pages ([#167](https://github.com/wp-slimstat/wp-slimstat/issues/167))
- **Fix**: Fixed outbound link clicks, file downloads, and page-exit events not being recorded — a silent regression in recent versions ([#174](https://github.com/wp-slimstat/wp-slimstat/issues/174))
- **Fix**: Fixed consent rejections being ignored — visitors who declined tracking could still be tracked, and unconfigured consent types were incorrectly treated as granted ([PR #178](https://github.com/wp-slimstat/wp-slimstat/pull/178))
- **Fix**: Fixed a crash when the WP Consent API plugin is not installed alongside SlimStat ([PR #172](https://github.com/wp-slimstat/wp-slimstat/pull/172))
- **Fix**: Fixed a crash during background geolocation database updates ([#180](https://github.com/wp-slimstat/wp-slimstat/issues/180))
- **Fix**: Fixed geolocation database updates not retrying after a failed download — previously blocked retries for up to a month ([PR #185](https://github.com/wp-slimstat/wp-slimstat/pull/185))
- **Fix**: Fixed admin page styling conflicts with WordPress core styles ([PR #175](https://github.com/wp-slimstat/wp-slimstat/pull/175))
- **Fix**: Fixed Email Reports page layout not matching other SlimStat admin pages ([PR #177](https://github.com/wp-slimstat/wp-slimstat/pull/177))
- **Fix**: Fixed browser detection failing due to a library compatibility issue ([#187](https://github.com/wp-slimstat/wp-slimstat/issues/187))
- **Fix**: Fixed the external page tracking snippet being completely broken — the snippet only set the legacy `ajaxurl` parameter while the tracker expects transport-specific endpoints ([#220](https://github.com/wp-slimstat/wp-slimstat/issues/220))
- **Improved**: Every fix in this release is backed by ~329 automated tests across 46 test files — covering tracking, geolocation, consent, performance, and upgrade safety
- **Improved**: Restored the server-side tracking API (`wp_slimstat::slimtrack()`) for themes and plugins that track visits programmatically ([#171](https://github.com/wp-slimstat/wp-slimstat/issues/171))
- **Improved**: Unique visitor counts now work correctly even when IP addresses are anonymized or hashed ([PR #178](https://github.com/wp-slimstat/wp-slimstat/pull/178))
- **Improved**: 261+ previously untranslated strings are now available for translation in all languages ([#173](https://github.com/wp-slimstat/wp-slimstat/issues/173))
- **Improved**: Geolocation now works consistently across all request types, including background tasks
- **Improved**: DB-IP restored as the default geolocation provider for new installations
- **Improved**: Faster admin page loads by removing redundant database queries ([PR #189](https://github.com/wp-slimstat/wp-slimstat/pull/189))

= 5.4.1 - 2026-03-09 =
- **New**: The GDPR consent banner message, accept, and decline labels can now be translated with WPML and Polylang ([#145](https://github.com/wp-slimstat/wp-slimstat/issues/145))
- **Fix**: Fixed the GDPR consent banner appearing even when GDPR Compliance Mode was turned off ([#140](https://github.com/wp-slimstat/wp-slimstat/issues/140))
- **Fix**: Fixed duplicate Accept/Deny buttons showing in the consent banner when the custom message contained links ([#144](https://github.com/wp-slimstat/wp-slimstat/issues/144))
- **Fix**: Fixed charts not loading in older browsers including Firefox before version 121 ([#139](https://github.com/wp-slimstat/wp-slimstat/issues/139))
- **Fix**: Fixed a potential error when chart data was missing from the page
- **Fix**: Fixed real URLs (e.g., privacy policy links) being incorrectly stripped from the consent banner message
- **Fix**: Fixed refresh button not resetting countdown timer ([#153](https://github.com/wp-slimstat/wp-slimstat/issues/153))

= 5.4.0 - 2026-03-08 =
- **Breaking**: Removed internal GDPR consent management system (shortcode, banner, opt-in/opt-out cookies) in favor of external CMP integrations.
- **New**: Integration with Consent Management Platforms (CMPs) for GDPR compliance: WP Consent API and Real Cookie Banner Pro.
- **New**: GDPR Compliance Mode toggle - Enable/disable GDPR compliance requirements (default: enabled).
- **New**: Consent change listener that automatically resumes tracking when user grants consent via CMP.
- **New**: Do Not Track (DNT) header respect with configurable option in settings.
- **New**: WordPress Privacy Policy content registration for GDPR Article 13/14 compliance.
- **Enhancement**: Refactored GDPR architecture - consent management fully delegated to external CMPs.
- **Enhancement**: Smart IP handling - automatically upgrades from anonymized/hashed IP to full IP when consent is granted.
- **Enhancement**: Improved JavaScript consent handling with polling-based consent state monitoring.
- **Enhancement**: Default data retention period set to 420 days (14 months) for GDPR compliance.
- **Fix**: Legacy mode now conservatively denies PII collection when GDPR enabled and no CMP configured.
- **Fix**: Consent revocation properly deletes tracking cookie when user opts out via banner.
- **Fix**: Removed legacy cookie-based opt-in/opt-out handling for cleaner, CMP-based consent flow.
[See full release notes](https://wp-slimstat.com/wordpress-analytics-plugin-slimstat-5-4-release-notes/?utm_source=wordpress&utm_medium=changelog&utm_campaign=changelog&utm_content=5-4-0)

= 5.3.6 =
* Security: Hardened output escaping in reports

= 5.3.5 - 2025-12-31 =
* Security: Hardened plugin security

= 5.3.4 - 2024-12-28 =
* Security: Hardened plugin security

= 5.3.3 - 2025-12-17 =
* Maintenance: Stability and compatibility improvements.

= 5.3.2 - 2025-11-24 =
- Fix: Minor improvements & Hardened plugin security.

= 5.3.1 - 2025-09-09 =
- **Fix**: Resolved "Invalid Date, NaN" error in monthly charts for 12-month ranges.
- **Fix**: Real-time report date filters not properly cleared during auto-refresh.
- **Fix**: Real-time report not updating at midnight with filters.
- **Fix**: Undefined variable $unpacked in PHP tracking logic;
- **Enhancement**: Enhanced responsive design for the "Access Log" report.
- **Enhancement**: Improved tracking logic to prevent duplicate pageviews and events.
- **Enhancement**: Enhanced interaction tracking and heartbeat finalization.

= 5.3.0 - 2025-08-25 =
- **New**: Tracker type options (REST API + Ad-blocker bypass) for improved tracking flexibility.
- **New**: Support for WordPress date format setting in charts.
- **New**: Hourly, daily, weekly, monthly, and yearly chart granularities for deeper insights.
- **Enhancement**: Redesigned line charts for better readability.
- **Enhancement**: Compatibility with WordPress’s Interactivity API for seamless integration.
- **Enhancement**: Added new 3 date ranges formats (Last 2 weeks, Previous month, This month).
[See full release notes](https://wp-slimstat.com/wordpress-analytics-plugin-slimstat-5-3-release-notes/?utm_source=wordpress&utm_medium=changelog&utm_campaign=changelog&utm_content=5-3-0)

= 5.2.13 - 2025-04-29 =
- **Fix**: Resolved issues with pagination in reports.

= 5.2.12 - 2025-04-26 =
- **Enhancement**: Removed red color from report export boxes to reduce eye strain and improve user experience.

= 5.2.11 - 2025-04-25 =
- Full release notes → [WordPress Real-time Analytics Plugin](https://wp-slimstat.com/wordpress-analytics-plugin-slimstat-5-2-11-release-notes/?utm_source=wordpress&utm_medium=changelog&utm_campaign=changelog&utm_content=5-2-11) – SlimStat 5.2.11 Release Notes
- **Visual Enhancement**: Improved UI with eye-catching visual elements for better user experience.
- **Enhancement**: Optimized SQL query to reduce the chances of errors and improve overall performance.
- **Enhancement**: The "Export" button for non-Pro users now links to the Slimstat PRO version page, improving clarity around upgrade options.
- **Enhancement**: Added support for the WordPress date format setting for the charts.
- **Fix**: Fatal error in EmailReportsAddon.php for missing `get_plugins` method.
- **Fix**: Prevented PHP warning by checking if 'referer' array key is set in searchterms reports view.
- **Fix**: Fix a database error related to the notes column.
- **Fix**: Prevented horizontal scrolling in the reports area and improved page loading animations by ensuring styles are applied correctly.
- **Fix**: Addressed several user-reported issues to enhance overall stability and user experience.
- **Fix**: Investigate and resolve the "Division by zero" fatal error in `wp-slimstat-db.php` caused by PHP version 8.2.22. Further investigation needed to determine the root cause and provide a fix.

= 5.2.9 - 2024-11-12 =
- **Enhancement**: Ensured compatibility with WordPress version 6.7.
- **Fix**: Resolved the Top Referring Domain Issue.

[See changelog for all versions](https://raw.githubusercontent.com/wp-slimstat/wp-slimstat/master/CHANGELOG.md).
