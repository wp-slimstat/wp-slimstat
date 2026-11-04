# Form Analytics verification

These results describe an isolated QA site, not customer traffic or a production SLA. Both feature branches target `development`. No deployment or merge is authorized.

## Executed locally

- WordPress 7.1.4; real installed builder versions are listed in the architecture matrix. Browser scenarios reconcile server cohorts and totals, not just success messages.
- Pro: **15 Playwright scenarios passed**, including all eight public builders, validation, MW/Snow confirmation steps, CF7 spam/mail failure, duplicate delivery, native date/filter/segment/refresh/AI/CSV controls, privacy-safe payloads, no-form overhead, pause/resume, mobile/RTL, and a real WooCommerce 10.6.2 classic checkout. An unpaid order contributes no revenue; completing it credits its preceding form once.
- Free: **14 Playwright scenarios passed**, including saved-segment round trips, CF7 spam/mail failure and the final privacy/visibility fixes.
- Existing Free suites: **701 unit tests / 1,871 assertions** and **209 integration tests / 867 assertions** passed on PHP 8.2.29.
- Pro suite: **162 unit tests / 418 assertions** passed on PHP 8.2.29, including bounded structural field summaries. Source-level checks passed in both repositories. Clean-tree mutation checks run after the implementation commits.
- Live MySQL Free tests passed: fresh/idempotent schema, server-only success authority, forged payload rejection, same-page binding, tracking disable, duplicate confirmation, distinct Goal events and native Goal totals/unique visitors, builder transaction isolation, report filters, privacy export/erasure, retention before and after purge, and anonymous denial.
- Live MySQL Pro tests passed: temporal attribution, one order/one form, same-second ambiguity, currency separation, refunds, erasure/cache invalidation, structural summaries, explicit/idempotent online journey-index setup, complete-history comparison and authorization before cache reads.
- Both browser capture contracts passed: pending/revoked consent, sensitive and CSS-hidden controls, visibility, native validation, payload minimization and absence of client success flags.
- PHPStan at the PHP 7.4 language floor and WordPress-Core PHPCS passed for the new feature classes/templates. PSR class filenames and existing camelCase method conventions are the documented PHPCS exceptions.
- Translation catalogs were regenerated in both editions; `composer i18n:check` passed.

## Measured performance

`tests/forms/benchmark.php` creates and drops its own tables in a database named `test_form_analytics_*`. The final fixture contains **1,000,000 pageviews, 100,000 form cohorts and 6,000 daily aggregates**, spread over 20 forms, 30 days, two devices and five channels. It uses the real schema and report/storage code. The machine-readable output is [benchmark.json](benchmark.json).

| Measurement | Observed |
|---|---:|
| No-form page: added Form Analytics SQL / module requests / tracking requests | **0 / 0 / 0**, Free and Pro browser tests |
| Existing shared tracker gzip change versus development | +155 bytes |
| Conditional Free form script | about 3.2 KiB gzip |
| Conditional Pro structural-field extension | about 1.1 KiB gzip |
| Free cold overview, median / p95, 20 runs | 7.26 / 7.48 ms; 1 aggregate query |
| Free cold detail, median / p95, 20 runs | 2.75 / 2.88 ms; 4 aggregate queries |
| Free cached overview/detail | 0 analytics queries; about 0.06 ms in-process |
| Pro field summary, median / p95, 10 runs | 11.39 / 17.92 ms; 5,000 selected cohorts |
| Pro adjacent-page journeys, median / p95, 10 runs | 24.32 / 33.41 ms; 5,000 selected cohorts |
| New Free cohort storage, including indexes, 100,000 rows | 29,458,432 bytes |
| Daily aggregate storage, including index, 6,000 rows | 1,900,544 bytes |
| Sequential seen → start → confirmed + retry flows | 100 in 0.196 seconds, in-process |
| Database statements per seen / start / confirmation transition | 8 / 8 / 9; retry 6 with no duplicate counters |
| Additional allocated memory during field aggregation | 2 MiB in this fixture |

The Free EXPLAIN plans use the date primary key or `(form, day)` index, with no visit-table join. The same-visit journey index was prepared explicitly outside report timing. The QA process loaded eight form builders, WooCommerce and Pro: its 187 MiB bootstrap allocation is **not** SlimStat's standalone memory requirement. Peak allocation was about 193 MiB. A 512 MiB test-server limit was used for this unusually broad plugin matrix.

Budgets enforced structurally/tests: no-form 0/0/0, conditional scripts below 6 KiB gzip combined, 20 forms per batch/page, 8 KiB ingestion, 64 visible field slots, 10,000 selected field summaries, 20 layouts, one/four Free aggregate queries, and zero analytics queries for a warm report. Timings are local absolute measurements, not a claimed speedup, HTTP concurrency benchmark, shared-host guarantee or a before/after whole-dashboard SLA. Database growth depends on retention, forms per visit and field metadata. Full hosting/concurrency qualification remains a rollout task.

## Simplification and security review

The exact `/simplify` and `/security-review` skills were not installed. Equivalent branch reviews were performed rather than claiming those commands ran. Changes from the reviews include:

- Reuse the existing transport, report controls, money formatting, online-DDL helper, privacy APIs and global cache generation.
- Keep only one shared browser collector; adapters supply identity and verified outcome. Remove unused adapter imports.
- Reject client success flags, malformed/unbounded payloads, changed plugin shapes and ambiguous ordering; never infer success from a message or submit attempt.
- Require both reporting access and site-administrator capability before every form report/cache reader. Unsupported filters fail visibly instead of returning broader totals.
- Cache aggregate field results rather than linkable raw page summaries; invalidate authorized reads immediately on erasure; cap layout cardinality as well as row/field counts.
- Clean up optional field listeners when forms are removed and isolate extension exceptions from shared tracking.
- Do not overwrite a builder's transaction, synchronously build a large visit index on a report request, or use a blocking index-build fallback.
- Escape user-facing labels and query-derived values at rendering, bound native exports and reuse their formula-injection protection. No credentials or visitor-entered form values belong in evidence files.

## Plugin Check and packaging

PCP 2.1.0 found **no new feature-code errors or warnings** after fixes. Full raw results are not described as zero:

- Free's pre-existing scoped `src/Dependencies` libraries produce 712 findings, predominantly generic exception-output/filesystem checks; these libraries are unchanged. A first-party scan excluding that bundled dependency tree leaves only `trademarked_term` for the established `wp-slimstat` slug. Renaming the existing WordPress.org plugin is not a valid feature fix.
- Pro is distributed privately. Its existing updater triggers `plugin_updater_detected` / `update_modification_detected`, its established slug triggers `trademarked_term`, and `storage/.htaccess` triggers `hidden_files`. The access-denial file and authorized private updater are retained; removing them to satisfy WordPress.org distribution rules would be incorrect.
- These are explicit pre-existing/distribution-context exceptions, not hidden clean-check claims. Production-package checks and remote CI remain required before merge.

## Impeccable audit and polish

Product register, existing SlimStat design tokens and native report controls were retained. White report boxes follow the requested design. Audit findings fixed: Saved Filters was covered by the absolute date control at desktop widths; native Apply/date/tab colors missed AA contrast; inherited paragraph padding misaligned copy; mobile explanatory text was too small; CSS-hidden honeypots distorted field positions.

| Dimension | Score / 4 | Evidence / limit |
|---|---:|---|
| Accessibility | 3 | Zero scoped automated WCAG A/AA violations after fixes; keyboard disclosure controls tested; manual screen-reader certification not performed |
| Performance | 3 | Conditional assets, bounded tracking and indexed reports measured; shared-host/concurrent HTTP testing outstanding |
| Responsive | 3 | 390 px mobile and desktop; RTL/no-overflow/keyboard checks; physical devices not tested |
| Theming | 4 | Existing SlimStat tokens and shared components, requested white surfaces |
| Visual consistency | 4 | Native hierarchy, one trend chart plus numeric fallback, restrained upsells, business-language headings |
| **Total** | **17 / 20** | Good; remaining qualification is stated rather than implied |

The scoped axe audit reports zero violations; its incomplete contrast/ARIA checks still need human judgment. Charts have an accessible numeric table and do not rely on color alone. Screenshots show actual disposable-site scenario data, not illustrative customer results. PR comments include the final desktop/mobile captures.

## Remaining qualification

Licensed Gravity Forms runtime validation is unavailable; its adapter is based on verified public hook documentation and conservative status contracts. Elementor Pro and arbitrary native forms are not implemented. This release does not add alerts, cross-device attribution, CRM lead qualification or unrestricted individual visitor journeys. Field capture intentionally stops combining changed visible controls/steps; it does not claim exhaustive custom-builder field errors. Advanced exports currently contain aggregate form rows.

The new pinned/latest compatibility workflows must pass remotely. Existing CI provides broader PHP/WordPress regression coverage, but this is not a claim that every builder/version, multisite topology, persistent cache or external database combination was exercised locally. CodeRabbit status and merge readiness are recorded on the linked PRs after CI completes.
