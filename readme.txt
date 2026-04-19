=== SlimStat Analytics ===
Contributors: veronalabs, coolmann, toxicum, parhumm, mostafas1990
Tags: analytics, statistics, tracking, reports, geolocation
Text Domain: wp-slimstat
Requires at least: 5.6
Requires PHP: 7.4
Tested up to: 6.9.4
Stable tag: 5.4.12
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The leading web analytics plugin for WordPress

== Description ==
Track returning customers and registered users, monitor Javascript events, detect intrusions, analyze email campaigns. Thousands of WordPress sites are already using it.

= Main Features =
* **Real-Time Access Log**: measure server latency, track page events, keep an eye on your bounce rate and much more.
* **Admin Bar Stats**: view real-time site stats directly from the WordPress admin bar — online visitors, pageviews, and top pages at a glance.
* **Shortcodes**: display reports in widgets or directly in posts and pages.
* **Customize Reports**: Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
* **GDPR**: fully compliant with GDPR European law. Integrates seamlessly with WP Consent API. Consent banner translatable with WPML and Polylang.
* **Filters**: exclude users from statistics collection based on various criteria, including user roles, common robots, IP subnets, admin pages, country, etc.
* **Export to Excel**: download your reports as CSV files, generate user heatmaps or get daily emails right in your mailbox (via Pro).
* **Cache**: compatible with W3 Total Cache, WP SuperCache, CloudFlare and most caching plugins.
* **Privacy**: hash IP addresses to protect your users' privacy.
* **Geolocation**: identify your visitors by city and country, browser type and operating system (courtesy of [MaxMind](https://www.maxmind.com/) and [Browscap](https://browscap.org)).
* **World Map**: see where your visitors are coming from, even on your mobile device (courtesy of [JQVMap](https://github.com/10bestdesign/jqvmap)).

= Pro Pack Features =
* **Network Analytics**: Enable a network-wide view of your reports and settings.
* **Email Reports**: Receive your reports directly in your mailbox with customizable column mappings and HTML tables.
* **Export to Excel**: Download your reports as CSV files.
* **Heatmap**: Display a heatmap layer of the most clicked areas on your website.
* **User Overview**: Monitor your registered users by tracking their activities and time on site.
* **User Avatars**: Gravatar integration in the User Overview report for quick visitor identification.
* **MaxMind Integration**: Connect to MaxMind's Geolocation API to retrieve detailed information about your visitors.
* **Custom DB**: Use an external database to store all the information about your visitors.
* **Extended Overview**: Add custom columns to the User Overview widget and export file.

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
An extensive knowledge base is available on our [website](https://www.wp-slimstat.com/).

== Screenshots ==
1. **Real-Time** - A real-time view of your visitors' whereabouts
2. **Word Map** - Identify them by country, browser, and operating system in a snap.
3. **Overview** - Your website traffic at a glance. Enjoy a simple, all-in-one dashboard to check your website stats quickly.
4. **Audience** - See your visitors' full information
5. **Site Analysis** - See top pages, categories, download and outbound links in an easy, simple view.
6. **Traffic Sources** - See where your visitors are coming from, such as search engines, social media, or referral websites
7. **Customize widgets** - Customize all pages—Real-time, Overview, Audience, Site Analysis, and Traffic Sources—to fit your needs easily!
8. **WordPress Dashboard** - Add and display custom reports like Traffic Sources directly on your WordPress dashboard!
9. **Settings** - Plenty of options to customize the plugin's behavior

== Changelog ==
= 5.4.12 - 2026-04-18 =
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
