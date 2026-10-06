# feature/shortcodes merge review

The paired Free and Pro branches are prepared for integration into `development`, subject to the remote required checks on the commits being merged. This review does not certify an immediate production rollout to all 70,000 installations.

Reviewed against fetched `origin/development`: Free `2e6aae8e`, Pro `fb3cad5`. The complete branch differences include tracking, heatmaps, reporting, migration and admin changes beyond shortcodes (295 Free and 68 Pro files before these fixes). Both branches merge without conflicts against these development revisions.

## Confirmed risks fixed

| Priority | Failure and impact | Fix and regression evidence |
| --- | --- | --- |
| High | Restricted authors could receive other authors' heatmap rows, totals and cached viewer responses. | Apply the existing report author boundary to legacy and capture queries; include author/database identity in both list and Pro viewer caches. A real database fixture switches authors after warming caches and checks clicks, scroll, lists and denominators. |
| High | URL/form report filters could alter a published shortcode's data, and initial catalog loading could overwrite the surrounding report's filters and totals. | Explicit shortcode filters ignore request fields while retaining authorization; restore shared state around catalog and rendering. Real WordPress regressions cover request injection and cold initialization. |
| Medium | Existing public aggregate counters over personal fields disappeared; query-string variants fragmented popular-page rankings; dates could render incorrectly. | Keep aggregate counts public while protecting detailed rows, group normalized URLs before LIMIT, and select stored dates. Real database shortcode assertions cover these behaviors. |
| Medium | Cached capture requests could continue writing after the Pro viewer was removed. | Require the existing capture eligibility check at ingestion; regression asserts disabled viewers cannot write. |
| Medium | Heatmap page lists aggregated scroll across every captured URL even though the displayed list was bounded. | Restrict scroll aggregation to the selected page hashes; skip it for an empty click list. Query-budget assertions cover both paths. |
| Medium | Anonymous whitelist checks could warn on PHP 7.4; the URL sanitizer logged deprecations on WordPress 5.6. | Guard empty logins and use `esc_url_raw()` consistently. Unit, sanitizer, actual minimum-runtime and browser checks cover the changes. |
| Medium | The requested WordPress minimum was not exercised as a runtime CI job, and the current target did not match 7.1.2. | Add blocking WordPress 5.6/PHP 7.4 runtime jobs to both repositories and include the Free job in deployment-required lanes. Pin metadata and current CI lanes to 7.1.2; maintain lane/mutation guards. |

## Validation

All of the following passed locally:

- Free: 607 unit tests / 1,513 assertions; 159 integration tests / 399 assertions.
- Pro: 152 unit tests / 371 assertions.
- Both full source-level suites and PHPStan analyses; Free mutation registry and deployment-gate integrity checks.
- Actual PHP 7.4 syntax validation of all changed production PHP files against development: 88 Free and 19 Pro files.
- WordPress 5.6/PHP 7.4 and WordPress 7.1.2/PHP 8.2: paired-plugin boot, database-backed shortcode regressions and author-isolation regressions.
- WordPress 7.1.2: 25 Free browser tests covering shortcodes, mobile/RTL, heatmaps, REST/AJAX click tracking, consent, DNT, author privacy and upgrade safety; 13 Pro heatmap UI/overlay tests.
- WordPress 5.6/PHP 7.4: 23 Free browser tests covering the same areas except the separate author-report spec; 13 Pro heatmap UI/overlay tests. Author isolation is covered here by the database fixture.
- Workflow YAML parsing and whitespace checks.

Browser and upgrade tests ran in disposable Docker sites using production autoloaders and MariaDB 10.11. They did not run destructive fixtures against the existing Local site. Upgrade checks verify schema, retained fixture rows, visit counters and error-free admin/frontend requests; they are not a substitute for every historical upgrade path.

## Integration and release boundary

Publish/merge the Free fixes before or alongside the Pro fixes: Pro CI checks out the paired Free branch and runs the new shared author-isolation fixture. Do not merge or ship the Pro cache fix against an older Free branch without the matching query restrictions.

The remote required CI jobs still need to pass on the published commits. Before a general release, retain the repository's existing full PHP/WordPress/database matrix, packaged-artifact checks, historical migration/restore qualification, multisite/custom-database cases and large-dataset performance gates. Those broader production qualification runs were not all executed during this local branch review. Heatmap capture remains opt-in; no new migration or default recording change was introduced by these fixes.
