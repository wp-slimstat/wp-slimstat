# WP SlimStat v6 QA Validation Report

Validation date: 2026-09-27. Source changes have **not** been implemented. This report and the accompanying implementation plan are the deliverables for the requested validation-first stage.

## Executive Summary

**18 distinct findings reviewed**, consolidating repeated observations across the QA report's two rounds:

| Verdict | Findings |
|---|---:|
| Confirmed bugs | **2**, sharing one underlying report-definition defect |
| Intentional changes / expected behavior | **8** |
| Environment issues / false positives | **4** |
| Needs more evidence | **4** |

The two confirmed issues are anonymous records appearing in **Top Known Visitors** and records without a content author appearing as **Guest in Top Authors**. Both originate in Free. The correct fix is to explicitly exclude missing identities in those two registered reports, preserving the general query merger's correct handling of NULL groups.

**Chrome version fragmentation is a correctness improvement, not a v6 parsing regression.** The old merger combined distinct browser/version groups. Replaying the actual old and new merger implementations against the supplied SQL dataset reproduces the QA's 42-to-200-capped User Agent groups and 26-to-180 Human Browser groups. The new results match the independent SQL grouping.

Recommend fixing both misleading identity reports before release. No critical data-loss or Pro entitlement regression was established by this QA review. This is **not release qualification**: the supplied v6 development documents record unfinished qualification on earlier frozen artifacts, and this investigation does not close those gates.

### Scope and evidence provenance

| Repository | Reviewed development commit | Historical baseline |
|---|---|---|
| `wp-slimstat` | `2f4ab9190e99c168a5f4423e1376f4c59ac74dcd` | `v5.5.0`, `dff433ce05fe5c7c1ddef4428be1f5ec9c1c3877` |
| `wp-slimstat-pro` | `fb3cad58aafcbd323c0df631f7e4b5e2a8758560` | `v2.0.0`, `8750d781009a95817809109430ea7b37f45e9039` |

Both working trees were clean on `development` at the start. These were the locally available development tips, also matching their local `origin/development` references; no fresh remote fetch was performed. The full Persian QA attachment, including corrections and both rounds, was read. Relevant documents under `jaan-to/outputs/dev/v6-*/` were searched and cross-checked, particularly:

- `v6-performance/SPLIT-MERGE-D5.md`, `EXPECTED-DIFFS.md`, `AUDIT-RECONCILIATION.md`, `DECISIONS.md`, `HANDOFF.md`, and the related date, tracking, funnel and query documents.
- `v6-qualification-20260907/readiness-20260909.md` and relevant integration adjudications.
- `v6-finalization/PROGRESS.md` and `PAUSED.md` as historical context.

Historical claims in these documents were not treated as fresh test results. For example, `AUDIT-RECONCILIATION.md:158` challenges the earlier D5 claim that 45 of 66 reports use the split path; this report does not repeat that unverified count.

### Supplied SQL dataset and checks actually performed

Input: `slimstat_wordpress_1789991323.sql.gz`; SHA-256:

`a34b1f9687776baf6113261e241e312ca266cd0404196ae2d494d1b8d429552c`

Only `wp_slim_stats`, `wp_slim_events`, and their two archive tables were restored into a disposable, network-isolated MariaDB container. The original database and attachment were untouched. No WordPress application, cron, email, or tracking requests were executed against the restored data. The dump identifies MariaDB 10.11.19; the isolated query engine was MariaDB 12.3.3, so this was not an engine-floor or exact-environment qualification.

- Restored rows: **23,124 stats**, **7,254 events**, **zero archive rows**.
- Latest stored stats timestamp: `1788699581` (2026-09-06 12:59:41 using UTC arithmetic). Latest event timestamp: `1788614742`.
- Neither stats table in this supplied snapshot contains `vid_hash`. Therefore it is not the reported post-migration QA database state. This does not disprove that migration subsequently ran on the QA sites.
- Six-month test bounds: `1774483200..1790467199`, representing the reported March 26–September 26 dates in the stored legacy clock.
- Round-one bounds: **assumed** August 27–September 23 inclusive, `1787788800..1790207999`. These are a reconstruction of “Last 28 Days,” not captured request parameters. They reproduce the listed v6 first-round counts.
- SQL comparisons use stored timestamps directly. UTC arithmetic constructs those calendar bounds; it does not establish the original site's timezone.
- Actual `Query::mergeGroupResults()` implementations from v5.5.0 and development were loaded in memory and replayed on SQL-produced grouped rows. This exercises production merge code, but is not a complete WordPress/browser end-to-end execution.

| Round-one metric | SQL result | QA v6 |
|---|---:|---:|
| Pageviews / unique IPs | 226 / 115 | 226 / 115 |
| Human visits / human unique IPs | 90 / 54 | 90 / 54 |
| Single-page visits / bounce rate | 76 / 84.44% | 76 / 84.44% |
| Known names / NULL-username pageviews | 8 / 167 | 8 / Guest 167 |
| Named authors / NULL-author pageviews | 1 / 4 | 1 / Guest 4 |
| Bots / distinct resources | 72 / 81 | 72 / 81 |
| Language groups / screen-dimension pairs | 10 / 19 | 10 / 19 |
| Event-note groups / type-only Click / My Subscription | 46 / 33 / 5 | 46 / 33 / 5 |
| Bounce / entry / exit resource groups | 50 / 66 / 71 | 50 / 66 / 71 |

For six months, the dump has **21,353** pageviews, versus QA v6's **21,356**. Additional local activity is a plausible explanation, but its exact provenance is not established. This distinction matters when interpreting small old/new count differences.

## Finding-by-Finding Validation

### Finding: 1. Migration completed; cookieless warning is harmless

**QA claim:** `add-visit-identity` completed and the warning is an old record that disappears after three hours; no action is needed.

**Verdict:** **Needs More Evidence** for the claim that this particular warning was stale and harmless. Its three-hour expiry mechanism is expected behavior.

**Evidence:** Free `wp-slimstat.php:636`, `:648`, `record_degradation():693`, and `get_degradations():726` implement an hourly refresh limit and three-hour expiry. Free `src/Tracker/Session.php:268`, `findExistingAnonymousVisitId()`, queries `vid_hash` and records degradation when `probeFailed()` is true; `recordIdentityProbeFailure():323` explicitly describes inflated visit counts. `src/Migration/Migrations/AddVisitIdentity.php:86`, `shouldRun()`, checks the actual columns. The supplied SQL snapshot lacks those columns and cannot validate the later migration state.

**Root cause:** A historical failure can leave a notice after recovery. An ongoing failure re-stamps it; column existence and a completion option alone do not prove that the runtime lookup succeeds on the selected analytics connection.

**Impact:** A stale warning has no ongoing tracking impact. A current lookup failure can open a new visit for each anonymous pageview.

**Recommended action:** Check the current query result, analytics connection, and recorded failure timestamp. Waiting is sufficient only after the underlying failure has stopped. Do not infer a new migration bug from this snapshot.

---

### Finding: 2. Goals/Funnels show Free limits; Email Report is blank

**QA claim:** Initially Pro functionality was unavailable; first a preview MU plugin, then an incorrect Pro repository/vendor installation were identified. Round two restored normal behavior.

**Verdict:** **Environment Issue**; the precise historical filesystem/cookie chain was not independently reproduced.

**Evidence:** Pro `src/Addon/Addons/GoalsFunnelAddon.php:44`, constructor and `max_goals()`/`max_funnels()`, still register limits **5/3**. Free `admin/view/wp-slimstat-reports.php:1802`, `get_goals_card_state()`, defaults to one goal; `get_funnels_card_state():1896` defaults to zero funnels. Pro `src/Addon/Addons/EmailReportsAddon.php:129` registers the form callback used by Free `admin/view/email-report.php:6`. Pro `wp-slimstat-pro.php:254`, `init()`, can decline provider bootstrap on dependency errors. These paths explain the observed defaults if Pro does not bootstrap.

**Root cause:** Reported invalid test installation, consistent with missing Pro providers. Merely hiding Pro does not itself explain the quoted exception that **Free** is inactive; that exception comes from Pro `_checkRequirements():402`. A separate earlier load-order defect was fixed by `6dfd47e`; the current fallback at `wp-slimstat-pro.php:422` passes 17 functional checks.

**Impact:** First-round Pro feature comparison was invalid. Current source retains the features.

**Recommended action:** No new code fix. Validate the actual paired ZIPs with clean activation, 5/3 limits, a populated funnel, and the complete email form. Form visibility does not establish successful email delivery.

---

### Finding: 3. Pages by User was removed from Real-time

**QA claim:** Missing in round one; seen again in round two, with the removal concern retracted.

**Verdict:** **False Positive** as a removal regression.

**Evidence:** Pro `src/Addon/Addons/UserOverviewAddon.php:45`, `addReportInfo()`, still registers `slim_p8_02`, “Pages by User,” at `slimview1`. The method is byte-identical to Pro v2.0.0. `get_pages_per_user():409` remains present, as does addon registration in `src/Addon/AddonServiceProvider.php:23`.

**Root cause:** No deletion or relocation exists. Failed Pro bootstrap, saved layout, or asynchronous rendering could account for the original observation; the exact cause is unproven.

**Impact:** No verified feature loss.

**Recommended action:** Close the removal allegation; include visibility in paired-Pro acceptance testing.

---

### Finding: 4. GeoIP warning disappears

**QA claim:** The old site showed a GeoIP warning; the new site did not. The summary groups it with the harmless migration notice.

**Verdict:** **Expected Behavior** for conditional notice display; actual environment differences remain unrecorded.

**Evidence:** Free `admin/view/index.php:99` checks database-backed provider selection, `notice_geolite`, and the provider's local database file. The map path at `admin/view/wp-slimstat-reports.php:2564` likewise checks provider/file state. Equivalent conditions exist in v5.5.0. Pro also has a message in `src/Addon/Addons/MaxMindDetailsAddon.php:198`.

**Root cause:** Notice visibility depends on settings and filesystem state, which shared analytics data do not establish. This warning does **not** use `DEGRADATION_TTL`.

**Impact:** A missing warning alone does not demonstrate lost geolocation.

**Recommended action:** Compare provider, local database availability and dismissal settings. No notice-code change is justified.

---

### Finding: 5. Small Pageview/Visit/summary changes are caused by deduplication

**QA claim:** 234→226 pageviews and 92→90 visits, later 21,363→21,356 pageviews, are benign consequences of the refresh fix.

**Verdict:** **Needs More Evidence** for the old/new delta's cause. The supplied snapshot independently validates the v6 first-round values.

**Evidence:** Free `admin/view/wp-slimstat-db.php:1063`, `count_records()`, still uses `COUNT(id)` or `COUNT(DISTINCT column)` and the selected `dt` window for single-site reports. `get_visitors_summary():1987` derives visits/bounces from stored visit IDs. Free `src/Tracker/Session.php:58`, `ensureVisitId()`, affects new tracking; `src/Migration/Migrations/AddVisitIdentity.php:23` explicitly prohibits historical identity backfill. `v6-performance/EXPECTED-DIFFS.md:481` documents the forward-looking change.

**Root cause:** Undetermined for the cross-site difference. A write-time fix cannot make the same already-stored rows disappear from a frozen shared table. Different live rows, windows, settings, selected database or caches must be excluded first.

**Impact:** No v6 counting regression is shown by this dataset. Statistical smallness alone would not prove correctness.

**Recommended action:** Retain current counting logic. If exact old/new reconciliation is needed, capture both normalized bounds, SQL, cache state and immutable row sets. Equal Known Visitors, bots, referrers, direct and SERP counts are controls, not evidence of deduplication.

---

### Finding: 6. Chrome / User-Agent version groups multiply

**QA claim:** Chrome 152's large old group becomes many smaller versions; the report concludes UA parsing or Browscap granularity changed.

**Verdict:** **Intentional Change** correcting old report aggregation. The proposed parsing explanation is false.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:333` and `:549` already request `browser, browser_version` in both releases. `wp_slimstat_db::get_top():1553` reads stored values without reparsing UA strings. At v5.5.0, `src/Utils/Query.php:972`, `mergeGroupResults()`, chose only the first nonaggregate field, `browser`. Current `Query.php:1004` preserves the composite row identity. Commit `9ccc1b3d` fixes D5. Current `src/Utils/UADetector.php` has no corresponding Chrome granularity change. The optional `AddUserAgentDimension::backfill():182` copies existing tuples; these reports do not depend on that migration.

| Six-month replay on supplied dump | v5.5.0 merge | v6 merge / SQL |
|---|---:|---:|
| All UA groups, 200-row query cap | 42 | 200 |
| Human browser groups | 26 | 180 |
| Chrome 121, all-UA report | 13,419 | 3,343 |
| Chrome 108, all-UA report | absorbed | 2,874 |

The current merged tuples exactly match the SQL tuples. Uncapped SQL has 206 UA groups. The dump has no QA-day live rows, so it does not reproduce the old label Chrome 152 or every later count. **180 is all human browser/version groups, not 180 Chrome versions.**

**Root cause:** Legacy query-result merging collapsed versions on ranges including the live-day split. It could also overwrite duplicate keys within its first partition; unchanged overall sums are not a universal guarantee.

**Impact:** v6 attributes hits to their stored versions correctly. A bot can become the largest individual group without bots becoming a majority of all traffic. Top User Agents explicitly includes both humans and bots.

**Recommended action:** Keep the fix. Expand the existing changelog explanation with a browser example. `CHANGELOG.md:12` and `readme.txt:131` already document the multi-column midnight correction; the QA assertion that it is entirely absent is too strong. No parser or Pro fix, grouped-browser replacement, or optional migration is needed.

---

### Finding: 7. Top Bots splits Baiduspider and Google Bot by version

**QA claim:** New versioned bot rows appear; round two treats Top Bots as an independent control unaffected by version grouping.

**Verdict:** **Intentional Change**. The proposed independent-control premise is false.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:537` defines Top Bots using `browser, browser_version`, filtered by `browser_type = 1`, in both releases. It uses the same merger as Finding 6. On the dump, Google Bot 2.1 has **1,244** hits and its NULL-version group has **19**; the legacy merge produces **1,263**, reproducing the QA discrepancy exactly. Default Browser remains **1,696**.

**Root cause:** D5 preserves bot version groups as well as human browser groups.

**Impact:** Correct per-version counts and rankings. A row outside a screenshot is not proof of deletion; the first-round QA did not show all seven new bot rows.

**Recommended action:** Preserve the behavior; include bots in the grouped-report regression fixture and documentation.

---

### Finding: 8. Apple / OS-family totals and rankings change

**QA claim:** Apple drops 69→61 in round one and 3,359→3,352 in round two; browser processing may explain it.

**Verdict:** **Needs More Evidence** for the exact old/new decrease.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:479` groups stored `platform` through `CONCAT("p-", SUBSTRING(platform,1,3))`, as v5.5.0 did. It does not group by browser version. Six-month SQL gives Microsoft **11,663**, matching QA, and the Mac/Apple bucket **3,349**, three below the new screenshot.

**Root cause:** Not established. Chrome splitting cannot itself reduce a separate OS-family aggregate. The snapshot/runtime difference prevents attributing those rows.

**Impact:** No demonstrated OS parser regression.

**Recommended action:** Compare platform aggregates and contributing row IDs on identical frozen inputs. Do not change detection based on rank differences alone.

---

### Finding: 9. Guest dominates Top Known Visitors

**QA claim:** An additional Guest group has 167 hits in round one and 19,102 in round two, displacing named visitors.

**Verdict:** **Confirmed Bug** — a latent report-definition defect exposed by v6's correct NULL preservation.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:198`, report `slim_p1_11` in `init()`, requests `username` without a nonempty predicate. `wp_slimstat_db::get_top():1553` does not implicitly exclude NULL usernames. `Query::mergeGroupResults():1004` now preserves NULL; v5.5.0's `isset($row[$groupKey])` dropped it. `raw_results_to_html():1524` labels missing usernames Guest. The summary at `wp-slimstat-db.php:2020` uses `COUNT(DISTINCT username)`, which omits NULL.

Actual SQL/production-method replay gives **167 NULL-username hits and 8 known names** in round one, precisely matching QA. Six-month replay changes **90→91 groups**, adding **19,101** anonymous hits; the one-hit difference from QA follows the snapshot limitation.

**Root cause:** The report relied on an accidental generic-merger omission instead of declaring its own eligibility filter. The missing predicate predates v6; v6 makes the defect reliably visible on the tested split path. This is not evidence of a new identity-collection failure.

**Impact:** Anonymous pageviews appear as a known visitor, dominate ranking and occupy pagination slots. These counts are pageviews per stored name, not unique people. Named visitors are displaced, not deleted.

**Recommended action:** Add `username IS NOT NULL AND username <> ''` to this report's registered `callback_args.where`. Preserve valid commenters, historical names and names without a current WordPress account; `src/Tracker/Processor.php:303` and `:338` show both logged-in and comment-cookie identity sources. Do not require an email or join `wp_users`. Keep the all-pageviews percentage denominator.

---

### Finding: 10. Guest appears in Top Authors

**QA claim:** The existing authors remain, with an additional Guest row of 4 hits, later 2,976.

**Verdict:** **Confirmed Bug**, sharing Finding 9's report-filter omission.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:780`, report `slim_p4_18`, groups `author` without a nonempty predicate. `raw_results_to_html():1552` labels an empty author Guest. `src/Tracker/Utils.php:629` populates this field from the visited content's author; it is not the visitor's login status. The dump reproduces one named author plus **4** NULL-author hits in round one. Six-month method replay changes **7→8** groups, adding **2,973** NULL-author hits.

**Root cause:** Correct generic NULL preservation exposes missing report-specific author eligibility. The renderer's Guest label then misrepresents unassigned content metadata as an author.

**Impact:** An author leaderboard includes a non-author category, affecting ranks, totals and exports. This does not establish that anonymous visitors authored the pages or that stored named-author counts changed.

**Recommended action:** Add `author IS NOT NULL AND author <> ''` to this report's registered arguments. Preserve historical nonempty authors even when their WordPress account no longer exists. Do not rewrite stored rows or globally remove Guest rendering.

---

### Finding: 11. Friendly My account titles become raw endpoint URLs

**QA claim:** Orders and subscription endpoints display URLs instead of “My account.”

**Verdict:** **Needs More Evidence**.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:2779`, `get_resource_title()`, still checks the title-conversion setting/cache, calls `url_to_postid()`, uses the title when resolved, and falls back to an escaped URL. Full-method comparison against v5.5.0 found only `parse_url()`→`wp_parse_url()` for the home path after post resolution. Pro's `UserOverviewAddon.php:675` reuses Free's helper. No intended endpoint-title change was found in the v6 documents.

**Root cause:** Undetermined. WordPress/WooCommerce endpoint registration, rewrite rules, site URL settings or cached results are hypotheses. Analytics rows alone cannot execute WordPress's runtime resolver.

**Impact:** Less friendly labels; no proven row loss or aggregation change.

**Recommended action:** Reproduce with identical WordPress/endpoint plugins and settings, compare `url_to_postid()` for the reported paths, and compare cold/warm resolution. No speculative resolver fix or intentional-change claim.

---

### Finding: 12. Custom Events Click drops; My Subscription appears

**QA claim:** Click 37→33, event groups 48→46, with My Subscription appearing at five hits.

**Verdict:** **Expected Behavior** for the v6 results, independently matched to the snapshot. The cause of the old-side difference remains unproven.

**Evidence:** Free `admin/view/wp-slimstat-db.php:1783`, `get_top_events()`, groups full `notes`. `admin/view/wp-slimstat-reports.php:1692`, `show_events()`, picks labels from JSON text/value/id/type. SQL produces **46** groups, **33** exact type-only click notes and **5** My Subscription text events in the reconstructed round-one window.

**Root cause:** The new values reflect stored event groups. A visible label is not necessarily all events of that type, and a row entering a displayed page need not be newly recorded.

**Impact:** No demonstrated v6 event-report regression.

**Recommended action:** No code change. Compare complete event notes, timestamps, filtering and pagination if the old counts need reconciliation; do not attribute historical deltas automatically to tracker fixes.

---

### Finding: 13. Login bounce row disappears; affiliate row appears

**QA claim:** A login URL disappears from Top Bounce Pages, an affiliate endpoint appears, and total groups fall 51→50.

**Verdict:** **Intentional Change** in bounce query correctness; the v6 rows also match the supplied data.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:838`, `slim_p4_23`, uses `HAVING COUNT(visit_id)=1`. `Query::getAll()` at `src/Utils/Query.php:1796` now avoids splitting HAVING queries: one matching row in each half is two overall and must not qualify. D5 and `v6-performance/SPLIT-MERGE-D5.md:132` document this. SQL produces **50** groups. The snapshot's login resource has **two hits**, so it is correctly absent; the affiliate endpoint has **one**, so it qualifies.

**Root cause:** The old split could evaluate HAVING against incomplete groups. Stable ordering also affects which equal-count rows appear first. The exact contribution of each mechanism to the old screenshot cannot be separated without its inputs.

**Impact:** Correct eligibility for the existing report definition.

**Recommended action:** Keep the correction. Add a SQL-backed midnight/HAVING fixture; do not restore the login row merely for parity with old output.

---

### Finding: 14. Entry/Exit Pages totals and fourth-ranked entry change

**QA claim:** Exit groups 72→71 and entry groups 68→66; a different entry row appears fourth.

**Verdict:** **Expected Behavior** for the v6 snapshot results; the full old-side delta remains unaccounted for.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:852` and `:866` invoke `wp_slimstat_db::get_top_aggr()` at `admin/view/wp-slimstat-db.php:1765`: representative `MAX(id)`/`MIN(id)` per visit, then resource aggregation. SQL reproduces **71 exit / 66 entry** groups. Deterministic `counthits DESC, resource ASC` ordering stabilizes ties.

**Root cause:** New values agree with the stored rows under the stated window. Tie-breaking can explain displayed rank changes; it alone does not explain fewer total groups.

**Impact:** No confirmed v6 boundary-page regression. A changed fourth row is not a title-resolution comparison of the same resource.

**Recommended action:** Preserve the algorithm and compare complete old inputs if further reconciliation is required.

---

### Finding: 15. Top Languages has one more group

**QA claim:** Language groups increase 9→10.

**Verdict:** **Intentional Change**.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:322` groups `language`. `Query::mergeGroupResults():1032` preserves NULL groups previously dropped. Round-one SQL has **nine non-NULL groups plus one NULL group with eight hits**.

**Root cause:** The missing-language group now survives the generic merge, matching SQL.

**Impact:** More complete language coverage. An unknown language is a legitimate category, unlike an anonymous identity in a report explicitly limited to known visitors.

**Recommended action:** No filtering rollback. Include this as a negative control for the Guest fix.

---

### Finding: 16. Screen Resolution groups increase

**QA claim:** Resolution groups increase 17→19.

**Verdict:** **Intentional Change**.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:369` groups `(screen_width, screen_height)` and excludes zero dimensions. Round-one SQL has **19** qualifying pairs. D5 previously keyed on just the first field; `v6-performance/SPLIT-MERGE-D5.md:13` documents independently checked pair restoration on an earlier corpus.

**Root cause:** Different heights at the same width must remain separate. The exact old count 17 is not reproduced by this snapshot's width-only grouping, so the old screenshot inputs still differ.

**Impact:** Correct screen-size breakdown.

**Recommended action:** Keep composite grouping and add an equal-width/different-height regression case beside browser grouping tests.

---

### Finding: 17. Plugins sidebar badge 9

**QA claim:** New site shows a badge interpreted as active plugins.

**Verdict:** **False Positive** as a SlimStat issue.

**Evidence:** WordPress core `wp-admin/menu.php:310`, outside both repositories, reads `wp_get_update_data()['counts']['plugins']` to build this badge.

**Root cause:** It counts available plugin updates, not active plugins or SlimStat report rows.

**Impact:** No SlimStat regression.

**Recommended action:** No plugin-code change.

---

### Finding: 18. Access Log pagination/countdown and localhost rows differ

**QA claim:** New screenshot shows the pagination/refresh bar; old screenshot shows additional localhost login requests.

**Verdict:** **False Positive** as evidence of a new pagination regression.

**Evidence:** Free `admin/view/wp-slimstat-reports.php:1165`, `report_pagination()`, and `get_report_total_count():1191` already supply pagination, cap markers and conditional countdown in v5.5.0. Refresh depends on settings and recency of the selected range. The supplied round-one snapshot contains no loopback rows.

**Root cause:** The compared screenshots do not establish the same live rows, scroll position, range and pagination state. The old localhost rows are absent from the supplied dataset; their relationship to the eight extra old pageviews is only a hypothesis.

**Impact:** No confirmed new feature loss or counting regression.

**Recommended action:** No code change. Use pinned inputs if a repeatable discrepancy remains.

## Confirmed Issues

| Issue | Severity | Repository | Root cause | Files affected / proposed fix |
|---|---|---|---|---|
| Guest in Known Visitors | **Medium / P2**, recommend before release | Free | Missing report-level nonempty username predicate, exposed by D5 NULL preservation | `admin/view/wp-slimstat-reports.php`, `init()` / `slim_p1_11`: add explicit predicate |
| Guest in Authors | **Medium / P2**, recommend before release | Free | Missing report-level nonempty content-author predicate, exposed by the same change | Same file, `slim_p4_18`: add explicit predicate |

**Expected behavior:** Known Visitors contains identified stored names; Authors contains stored nonempty content-author names. Historical names with deleted/missing WordPress accounts remain reportable. Unknown country/language and composite browser/screen groups remain intact.

**Required tests:** NULL and empty-string identities; named/commenter/deleted-account identities; historical-only, today-only and midnight-spanning windows; filters, pagination and all-pageview percentage denominators; dashboard/AJAX/shortcode and Pro CSV/email consumers; per-author restrictions; preservation of NULL language/country and multiple browser versions. Small synthetic fixtures should be committed; private customer dump data should not be committed.

**Backward compatibility:** No schema change, migration, data rewrite, public method signature change, minimum-version bump, global Guest-label change or merger rollback. Existing report IDs and raw callback signatures remain. Fixing report eligibility changes only the two lists and their downstream representations. Do not narrow identities to registered users or require email.

**Pro/Free interaction:** Pro already reads the registered arguments. `ExportToExcelAddon::handleExportToExcel()` at `src/Addon/Addons/ExportToExcelAddon.php:202` invokes those arguments directly. `EmailReportsAddon.php:660` does the same for site-wide reports; `:544` copies them and ANDs the existing author scope for per-author email. A Free definition fix therefore reaches both without a production Pro patch. Validate these consumers together; a renderer-only fix would leave exports wrong.

**Regression risks:** Accidentally dropping NULL globally; excluding historical/commenter identities; changing percentage denominators; filtering after LIMIT, leaving wrong pagination; losing author scope; preserving stale expected results in the oracle. Avoid each by putting the predicates in the registered report definitions before query aggregation and limiting.

## Non-Issues / False Positives

- Pro Free-tier symptoms were reported resolved after repairing the test installation; current entitlement and bootstrap checks do not establish a new regression.
- Pages by User is still registered in Real-time with the same report definition.
- The WordPress Plugins badge counts updates.
- Pagination/countdown already existed; differing live/scroll state is insufficient evidence of a regression.
- The supplied data agrees with v6's event, page-boundary and round-one summary values. Their differences from old screenshots are not sufficient reason to revert correct results.
- Raw URL fallback is existing behavior, but the reported loss of friendly labels still needs runtime evidence before classifying its cause.

## Intentional Changes

- D5 preserves browser/version, bot/version and screen-dimension tuples and NULL dimension groups. It should be retained.
- HAVING runs over complete date-window groups; false bounce eligibility must not be restored.
- Deterministic tie ordering is correct even when first-page rows move.
- Three-hour degradation expiry and conditional GeoIP notices are separate mechanisms.

**Documentation:** Expand `CHANGELOG.md:12` and the corresponding `readme.txt` entry with Chrome, bot and screen examples. The generic correction is already documented. Explain that old browser versions could absorb other versions' hits; the parser is not reclassifying this history. Add the two identity-report fixes under the applicable unreleased section. Do not describe unresolved title or count differences as intentional changes. The QA-mentioned `BETA-NOTES-6.0.0.md` was not present in the inspected plugin trees; no claim is made about that unavailable file's contents.

## Fix Plan

Execute the accompanying self-contained plan, `001-v6-known-identity-reports.md`, in this order:

1. **Branch/repository:** Free `fix/v6-qa-regressions`, based on reviewed `development` `2f4ab919`. Pro remains at its reviewed development tip unless a consumer test demonstrates a separate problem.
2. **Regression first:** Add a small registered-report eligibility check using synthetic NULL/empty/named identities, and prove it fails on the reviewed source. Exercise actual report arguments/query behavior; an isolated array filter is insufficient.
3. **Production fix:** Add the two explicit `callback_args.where` predicates in `admin/view/wp-slimstat-reports.php`. Preserve merger behavior, stored data and denominator semantics.
4. **Synchronize test contracts:** Update the two entries in `tests/oracle/report-contracts.json`, captures in `tests/docker/report-answers.php`, and assertions in `tests/oracle-report-contracts-test.php`. Reuse existing oracle filtering: `where`/`not_in` for `top_username_pinned`, `require_nonempty` for `slim_p4_18_top_authors`. Add cases to the existing relevant Python tests. The current contracts omit those eligibility constraints; their corpus-presence predicates already assume nonempty identities, so they must agree after the fix.
5. **Documentation:** Add the identity fixes and clarify the already documented D5 behavior in `CHANGELOG.md` and `readme.txt`.
6. **Validation:** Run focused standalone and oracle tests, PHP lint, then the repository source/unit checks in a properly provisioned test environment. Validate both plugins together with generated reports/CSV/email body capture, without sending real emails. Check the supplied snapshot's expected known/author groups: round one **8/1**, six months **90/7**, with unchanged known-name and named-author hit counts.
7. **Remaining QA:** Capture identical old/new runtime inputs for unresolved totals, OS, migration state and URL titles. Test real browser date boundaries, goals, consent upgrades, tracker loading and paired plugin activation. Broader release qualification remains a separate obligation.

### Checks performed in this review and their limits

Sixteen distinct targeted standalone scripts passed:

- Free: `split-merge-group-key-test.php`, `report-date-filter-scope-test.php`, `live-window-clamp-test.php`, `degradation-notice-honesty-test.php`, `rounding-contract-test.php`, `tracker-script-origin-test.php`, `anonymous-identity-test.php`, `ajax-resource-fix-test.php`, `query-cache-timezone-test.php`, `resolve-geolocation-provider-test.php`, `browscap-bot-safety-net-test.php`, `goals-free-active-limit-test.php`.
- Pro: `rounding-contract-test.php`, `free-floor-load-order-test.php`, `bootstrap-failsoft-test.php`, `pro-free-version-floor-test.php`.

Also completed: eight direct checks of production `Chart::calculatePreviousArgs()` across four windows, SQL aggregate comparisons, and actual historical/current merge replay. Tests include source inspections and stubbed/reflection execution; they are not all integration tests. Standalone rounding tests do not execute WordPress's `number_format_i18n` behavior.

A focused PHPUnit attempt could not start because the current vendor autoloader could not load `PHPUnit\TextUI\Application`. No test assertions executed in that attempt; it is a tooling limitation, not a product-test failure. No dependency install or full browser/CI/engine-floor/release-ZIP suite was run.

The QA report's remaining checklist items are **coverage obligations, not automatically bugs**: actual email delivery; real overlapping funnels; form/tel/mailto journeys; restricted-author reports; Multisite; real search terms; Free-only comparison; settings defaults; Query Monitor/performance claims; boundary rounding; consent-plugin integration; Today/Yesterday/custom windows and previous-period charts. The CDN toggle was removed: its valid test is upgrading with stored `enable_cdn=on` and confirming local tracker loading, not looking for a v6 toggle to enable.

## Branch Strategy

- **Free:** `fix/v6-qa-regressions` from `development` at `2f4ab919`. This branch is created for the validation/plan artifacts and subsequent focused implementation. No source edits, commits, pushes or PRs are part of this validation stage.
- **Pro:** No production fix branch is presently necessary. Existing callbacks consume the corrected Free definitions. If a dedicated Pro consumer-test change is necessary, use `test/v6-qa-report-parity` from `fb3cad5`; do not create a fictitious Pro runtime dependency or bump its Free version floor for this fix.
- Do not mix parser changes, migration rewrites, generic query refactors or unrelated release work into this branch.

## Final Release Assessment

**Fix the two identity-report defects before recommending release. Retain the browser/bot grouping correction.** The supplied data materially supports the new reporting output, rather than the QA suggestion of a parser regression.

Four finding groups retain unresolved causal evidence: current migration-warning health, exact old/new summary deltas, OS-family deltas, and friendly endpoint-title resolution. Several other v6 values are validated while their old-side screenshot differences remain unreconstructed. The supplied snapshot predates the QA runs and does not establish equal runtime state.

The latest top-level record inspected in `v6-performance/HANDOFF.md:3` and `v6-qualification-20260907/readiness-20260909.md:3` says both releases were held on 2026-09-15 because exact-artifact qualification was incomplete. It describes the counter defect as fixed, not an open current bug. Those records concern earlier frozen commits. This review supplies no new proof closing the unfinished campaigns, live licensing/staging checks or ownership decisions.

A documented pre-existing split-query limitation also remains: each partition applies its own LIMIT (`src/Utils/Query.php:1843`, `:1858`; `SPLIT-MERGE-D5.md:143`). With populated live and historical partitions, a group's contribution can fall outside one partition's cap. That is outside the two confirmed regressions and is not demonstrated by this dump's empty QA-day live partition; do not claim this review proves every large split top-list exact. Any separate remediation must use an adversarial SQL fixture and its own scoped plan.

No fresh evidence from this review supports a parser rollback, data repair, Pro entitlement change or broad refactor. The source remains unchanged pending the next implementation stage.
