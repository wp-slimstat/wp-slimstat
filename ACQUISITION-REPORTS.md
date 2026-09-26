# UTM and channel reports

The Traffic Sources screen includes **Channels** and **UTM Campaigns**. Both use SlimStat's date picker, filters, saved segments, author permissions, report customization, AJAX loading and export callback. Values link to the existing filter bar. Counts are **recorded pageviews**, not sessions, visitors, conversions or revenue.

## Using the reports

Tag incoming campaign links consistently, for example:

```text
https://example.com/offer?utm_source=newsletter&utm_medium=email&utm_campaign=autumn
```

UTM Campaigns starts with one expandable total per campaign. Its breakdown groups the complete combination of `utm_campaign`, `utm_source`, `utm_medium`, `utm_content`, `utm_term` and `utm_id`. Expand a campaign to compare sources and media. **More tags** expands content, term and campaign ID within a row when any are present; these values remain filterable and all six dimensions remain in exports. Missing primary values show **Not set**. Tags preserve case: `Autumn` and `autumn` are different campaigns. Detected bots, including AI fetchers, are excluded from this table.

Channels starts with one expandable total per channel. Expand it to compare normalized sources. Collapsed rows preview up to three recorded sources; this is a preview, not a source count. Its total includes every matching stored pageview, including bots if tracking settings collected them. **Internal Navigation** separates subsequent same-site navigation from incoming traffic. Tags on internal links override that classification, so tag incoming links only.

Shares use all matching pageviews, including rows beyond the configured result limit. Pagination applies to summaries. Group totals are aggregated independently before the result cap, never added up from a truncated breakdown. A single bounded detail query covers the visible groups; a partial breakdown states its shown and complete pageview counts. Shares in both views use the complete report total. Pagination is deterministic, and reaching the limit produces an explanation. Tables have column headers, visible keyboard focus, a keyboard-accessible horizontal scroll region on small screens, RTL support and expandable help. There is no new JavaScript dependency.

Customize can move either report to another SlimStat screen or the WordPress Dashboard. The existing widget shortcode also accepts `slim_p3_03` (Channels) and `slim_p3_04` (UTM Campaigns), with the same date and dimension filters. Public widgets show the same expandable summaries without report-filter links. Saved segments preserve the selected dates, and changing dates retains filters submitted by the report form. Literal punctuation, HTML entities and backslashes in tag values survive drilldowns, AJAX refreshes and saved segments; the shared form helper sets values without parsing them as HTML.

## Classification rules

Classification occurs once when the pageview is recorded, using the original URL, referrer and user agent. It has no network request, cookie, session-attribution carryover or per-hit database lookup. Updating the provider list affects future records only.

Precedence:

1. Recognized AI user agents: user-requested fetches and crawlers are distinct. Other detected bots have their own channel. All honor the existing bot exclusion setting.
2. Explicit UTM medium and source. Known paid media distinguish paid search, social, video and shopping; display, email, affiliates, audio, SMS, push, messaging, cross-network and explicit AI media also have categories. Unknown explicit media produce **Unassigned**, rather than borrowing a contradictory referrer.
3. Recognized advertising click identifiers when no explicit medium resolved the channel. Google/Microsoft click IDs alone cannot establish the campaign network, so ambiguous paid traffic uses **Paid Other**. `dclid` uses Display. The identifiers themselves are not added as new stored dimensions.
4. Known referring domains, including AI assistants, search, social, video, shopping, email and messaging. Matching uses exact hosts or subdomains; specific hosts such as Gemini, Gmail and Google Docs take precedence over the Google domain. URL paths and lookalike domain suffixes cannot claim a provider.
5. Known Android app referrers, and Facebook/Instagram in-app user agents only when tags and a referrer are absent. These signals do not establish paid traffic.
6. Other external referrers, internal navigation or **Direct / unknown**.

This is a deterministic, pageview-scoped taxonomy informed by GA's channel definitions; it is not a claim of GA session-attribution parity. No method can identify every source when browsers/apps strip referrers. Untagged email and private messages can be Direct / unknown. Generic Google/Bing search referrals cannot reliably distinguish AI-generated search answers. A claimed bot user agent is not authenticated bot identity. The code makes no IP-verification or human-engagement claim for an AI fetch.

## Storage and upgrade behavior

`Schema` owns eight nullable columns on both `slim_stats` and `slim_stats_archive`. The channel key uses `VARCHAR(32)`. The normalized source and six UTM fields use `VARBINARY(764)` containing validated UTF-8, limited to 191 characters. Byte comparison preserves exact tags, supports four-byte characters on older tables, and avoids a later table-wide charset conversion silently making campaign grouping case-insensitive. The UI receives ordinary UTF-8 strings. Regex filters explicitly convert these fields to case-sensitive UTF-8 for MySQL 8 compatibility.

Parsing decodes once, preserves encoded delimiters, literal percent escapes, Unicode and zero-valued tags, removes markup/control characters and rejects malformed UTF-8, duplicate tags and array parameters. Query strings over 16 KiB are not attributed from a partial parse. Values longer than 191 characters are truncated, so keep campaign identifiers within that limit. Do not put personal data in campaign URLs.

Existing installations use **Migration → Enable UTM and channel reports**. One batched ALTER adds missing fields per table. Online ALTER is attempted first, with the existing compatibility fallback to a regular ALTER. A large table may be rebuilt and unsupported online operations can pause tracking writes; the UI discloses this without promising a duration. A partial upgrade can be rerun. The migration framework's existing lock and kill switch apply.

Historical rows remain NULL and display **Not attributed**. They are not guessed from previously decoded URLs or discarded referrers. A destination-specific readiness option keeps ordinary tracking working while setup is pending. Restoring an older table triggers the existing missing-column retry and disables further acquisition writes until setup is repaired. Archive copying includes every new field.

## Queries and performance

Each rendered report uses three aggregate queries: group totals, a bounded breakdown for the visible groups, and the report denominator. Public widgets read the cached readiness marker without a schema probe. There is no query per expanded row, and native disclosure controls add no JavaScript requests. Aggregation happens in SQL over the existing indexed `dt` range, with existing global and author filters. There are no new indexes, dimension tables, scheduled backfills or per-pageview joins. Historical-only queries use the existing cache; ranges reaching today stay live. One aggregation spans midnight so the result cap cannot lose a group's older or newer contributions. The existing network merge receives the full group key and applies its cap after merging.

The database test creates and removes private tables on a separate analytics connection. Its 98,304-row fixture spans 96 days and verifies totals, the three-query report limit and an indexed one-day range with EXPLAIN. A Local MySQL 8 sample measured summary queries at approximately 5/42/59 ms for 1/30/96 days and detail queries at 10/59/93 ms. Docker/MariaDB summary queries measured 3/86/302 ms. These observations vary with database engine and load on the shared development machine; they are not production guarantees. Wide ranges still require grouping and sorting matching rows; use narrower dates/filters on large installations. Add an index only after measuring a representative workload and its write/storage cost.

## Verification

Run the repository's unit and integration suites separately; their WordPress mocking bootstraps differ:

```sh
composer dump-autoload --dev -o
composer test:unit
composer test:integration
composer phpstan
composer run build:autoload
composer test:source-level
composer i18n:check
```

Against an isolated wp-env test database:

```sh
npx wp-env run tests-cli wp eval-file wp-content/plugins/wp-slimstat/tests/acquisition-reports-db-test.php
npx playwright test --config=tests/e2e/playwright.config.ts acquisition-reports.spec.ts --project=admin
```

The Playwright run requires the existing test-environment credentials, WordPress root and disposable MySQL connection variables. It refuses the normal live database by default. It exercises REST/AJAX/server tracking, exact tag storage, AI categories and exclusions, totals/shares, drilldowns, pagination, date presets/custom ranges, saved segments, Customize/Dashboard placement, mobile/RTL, keyboard help, stored XSS, author scope and AJAX authentication/nonce checks. When Pro is installed, the same flow also verifies the real CSV export of the selected campaign, including percent escapes and zero-valued tags. The database test covers upgrades, partial restoration, charset conversion, archival columns, date/author filters, shortcode/widget rendering, exact campaign grouping and capped aggregation across midnight. The DB checks are wired into CI on WordPress 6.4, 7.0 and 7.1.

Local validation used WordPress 7.1/PHP 8.3 with Free and Pro, plus a compatibility run on WordPress 6.4/PHP 7.4. The Pro companion fix preserves zero-valued cells in CSV and email output; its tests render email HTML/CSV without sending messages. An additional isolated two-site network exercised the real Pro SQL rewriter: both report totals, per-site grouping, result caps, shares, author scope and case-sensitive filters passed. This check is reproducible with `wp eval-file wp-content/plugins/wp-slimstat/tests/acquisition-network-test.php` on a disposable network whose database name starts with `test_acquisition_network_`. Network validation covered the live query/render path, not a multisite browser run. A network must complete the schema migration on each participating site's analytics tables.

The local checks passed: 544 unit tests (1,221 assertions), 159 integration tests (399 assertions), 175 disposable-database checks on both MariaDB and Local MySQL 8, 21 combined report/date/filter browser scenarios plus five value-less-filter regressions, the two-site network check, PHPStan, source-level gates and translation checks. The final UI pass passed all 27 acquisition, legacy filter and date-pagination scenarios, including exact entity/backslash round trips and 300px Dashboard placement. Initial-pageview and server-referrer regressions also passed. The Pro suite passed 111 tests (246 assertions), PHPStan and source-level gates. Existing PHPUnit deprecation notices remain; there were no test failures in these final runs.

## Research sources

Reviewed 2026-09-26. The primary sources informing the decisions are:

- [Google Analytics channel definitions](https://support.google.com/analytics/answer/9756891?hl=en): paid/organic channel families, AI Assistant medium and the treatment of AI search features. The implementation intentionally avoids claiming an ad-network type from a click ID alone.
- [Google campaign URL guidance](https://support.google.com/analytics/answer/10917952?hl=en): campaign parameters, consistent tagging and case-sensitive values.
- [OpenAI bots](https://developers.openai.com/api/docs/bots), [Perplexity crawlers](https://docs.perplexity.ai/docs/resources/perplexity-crawlers), and [Anthropic crawler guidance](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler): separate crawling/search indexing from user-requested fetching and human referral traffic.
- [Applebot guidance](https://support.apple.com/en-us/119829): Applebot-Extended is a usage-control token, not a separate crawler; it is not recognized as an HTTP crawler in these rules.
- [MySQL binary string comparisons](https://dev.mysql.com/doc/refman/8.0/en/charset-binary-collations.html): exact byte comparison versus character collation, informing campaign storage.

Provider domains and agent tokens are a maintained list, not a universal discovery service. Future changes belong beside classifier fixtures that cover legitimate signals, collisions, missing information and spoofed hosts.

## Report design references

The summary-to-source flow follows [Plausible acquisition reports](https://plausible.io/docs/top-referrers) and [Carbon expandable table guidance](https://carbondesignsystem.com/components/data-table/usage/) (reviewed 2026-09-26). Report scope remains recorded pageviews; no visitor, session or conversion metrics are inferred for decoration.
