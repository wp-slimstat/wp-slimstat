# Plan 001: Exclude unidentified records from Known Visitors and Authors

Status: IMPLEMENTED; [PR #342](https://github.com/wp-slimstat/wp-slimstat/pull/342) merged into `development`. Priority: P2; recommend before release. Effort: S. Fix risk: low if limited to report eligibility; medium if the shared merger or export flow is altered.

Planned at Free `2f4ab9190e99c168a5f4423e1376f4c59ac74dcd`, Pro `fb3cad58aafcbd323c0df631f7e4b5e2a8758560`, 2026-09-27. Implemented on `fix/v6-qa-regressions`; the original validation preceded source changes.

## Problem and verified behavior

Free's `Query::mergeGroupResults()` correctly started preserving NULL groups in D5, commit `9ccc1b3d`. The old v5.5.0 merger used `isset()` on the group key and silently dropped them. The registered Top Known Visitors (`slim_p1_11`) and Top Authors (`slim_p4_18`) reports never declared their own nonempty-identity filter, so the general correctness fix exposed anonymous/unassigned groups. Their renderer calls those groups Guest.

The supplied customer snapshot confirms 167 anonymous pageviews among 226 in the reconstructed 28-day window, and four pageviews without a content author. Six-month production-method replay changes 90 known-name groups to 91 and seven named-author groups to eight. Correcting eligibility restores 90 and seven without rewriting any rows.

Known identity is a stored nonempty username, including a commenter's name; it does not require an email, a current login, or a matching `wp_users` row. Author is the visited content's author, not the visitor. Historical names must survive deleted accounts.

## Current state and implementation boundary

`admin/view/wp-slimstat-reports.php`, `wp_slimstat_reports::init()`:

```php
// slim_p1_11, around line 198
'callback_args' => [
    'type'    => 'top',
    'columns' => 'username',
    'raw'     => ['wp_slimstat_db', 'get_top'],
],

// slim_p4_18, around line 780
'callback_args' => [
    'type'    => 'top',
    'columns' => 'author',
    'raw'     => ['wp_slimstat_db', 'get_top'],
],
```

Add one SQL predicate to each definition:

```php
'where' => "username IS NOT NULL AND username <> ''",
'where' => "author IS NOT NULL AND author <> ''",
```

These belong in their respective arrays, not together. Follow the existing `where` pattern used by `slim_p1_05` and Top Search Terms. Keep the existing formatting and raw callback shape.

Do not change `src/Utils/Query.php`, UA parsing, session tracking, migrations, Guest rendering in other views, user-account lookup policy, report IDs, percentages, or public method signatures. Do not filter the rendered rows after LIMIT; that leaves counts, pagination and Pro output wrong.

## Files in scope

Production:

- `admin/view/wp-slimstat-reports.php` — only these two report definitions.

Tests and supporting contracts:

- `tests/known-identity-reports-test.php` — new small standalone behavioral regression.
- `composer.json` — register it as `test:known-identity-reports` and in `test:source-level`.
- `tests/oracle/report-contracts.json` — update the two corresponding report contracts.
- `tests/docker/report-answers.php` — update the corresponding captured arguments to match the real reports.
- `tests/oracle-report-contracts-test.php` — require both predicates for the corresponding registry entries.
- `tests/oracle/uncaptured_top_recent_test.py` and `tests/oracle/pages_family_test.py` — exercise nonempty username/author semantics through the existing models/contracts.
- `tests/split-merge-group-key-test.php` — retain NULL controls; add an equal-width/different-height case only if needed for the requested regression guard.

Documentation:

- `CHANGELOG.md` and `readme.txt` — identity fixes and a concrete explanation of the already documented D5 correction.
- `plans/README.md` — update status when implementation is actually completed.

No Pro production file is in scope. Pro consumer checks may run against this Free branch without changing Pro. If a genuine Pro code defect emerges, stop and report it with evidence before widening this plan.

## Branch and drift check

Use Free `fix/v6-qa-regressions`, created from the reviewed development tip. Before implementing:

```sh
git diff --stat 2f4ab919..HEAD -- admin/view/wp-slimstat-reports.php tests/oracle/report-contracts.json tests/docker/report-answers.php
git status --short
```

The review branch initially contains only uncommitted `plans/` artifacts. Preserve unrelated user work if the tree changes. Compare the excerpts above to current source; do not blindly apply a stale patch. Do not push, merge or publish as part of this plan unless subsequently instructed.

## Ordered implementation

1. **Add the smallest meaningful failing check.** Use the repository's standalone PHP style: clear failure messages, nonzero exit, PHP 7.4-compatible syntax. `tests/report-author-scope-test.php` demonstrates execution of actual production methods with bounded stubs; `tests/oracle-report-contracts-test.php` demonstrates report-registry extraction. The new check must consume the actual registered report arguments and prove the SQL eligibility at the query boundary. Do not merely copy the desired predicate into a test-side array and test that array.
2. **Cover the data shapes.** Include NULL, empty string, ordinary name, comment-cookie name without a WP account, and a historical author without a current account. Assert named counts are unchanged, missing identities cannot occupy a top slot, and exclusion is applied before aggregation/LIMIT. Keep the generic NULL-language/country and multiple-browser-version controls passing. Treat existing unusual scalar identity behavior as a separate issue rather than expanding this fix without evidence.
3. **Make the two-line production correction.** Run the new test red on the reviewed source, then green after adding the two predicates. No storage migration or cache-wide flush is needed: Query cache keys include the SQL, so the new WHERE produces a different key.
4. **Align the existing oracle/capture.** For `top_username_pinned`, reuse the supported `where` clause such as `[["username", "not_in", [null, ""]]]`; for `slim_p4_18_top_authors`, set the already supported `require_nonempty` flag. `tests/oracle/families/top.py` and `families/pages.py::grouped_values()` already implement these operations; do not add a new filtering framework. Update the matching arguments in `tests/docker/report-answers.php` and source-contract expectations. Both corpus-presence predicates there already require nonempty identities, but actual captures currently do not. Ensure adapters produce eligible rows on a synthetic fixture containing both NULL and empty strings.
5. **Validate consumers and compatibility.** Dashboard, AJAX and registered-report shortcode output must agree with raw report data. Pro `ExportToExcelAddon.php:212` uses registered callback arguments; `EmailReportsAddon.php:660` uses them for site-wide mail, and `:544` copies them before adding author restrictions. Capture CSV and email body output in an isolated fixture; do not send real email. Assert absence of anonymous/unassigned groups and retention of the existing author WHERE. Check Pro 3.0.0 and, where available in the existing compatibility harness, the supported Free-first/old-Pro update window.
6. **Document and run gates.** Record the identity changes; expand the existing D5 changelog sentence with browser/bot/screen examples. Do not promise that every old aggregate sum was correct, claim a new parser, or describe unresolved title/count differences as intentional changes.

## Tests and commands

Run from the Free repository. Existing commands below were read from `composer.json`; the new test/alias must be added during implementation.

```sh
php -l admin/view/wp-slimstat-reports.php
php tests/known-identity-reports-test.php
php tests/split-merge-group-key-test.php
php tests/oracle-report-contracts-test.php
python3 tests/oracle/uncaptured_top_recent_test.py
composer test:oracle-top
composer test:oracle-pages
php tests/report-author-scope-test.php
composer test:source-level
composer test:unit
```

Expected: all execute and exit zero; the new eligibility test must have failed before the two-line fix. A script that cannot start is not a pass. In the audit checkout, a focused PHPUnit attempt could not load `PHPUnit\TextUI\Application`; use the existing CI/provisioned PHP 8.1+ development environment or restore the normal test toolchain in an isolated checkout before claiming the unit gate. Do not change runtime dependencies to solve that local tooling condition.

With both plugins available, run Pro's existing consumer/compatibility checks:

```sh
php tests/email-reports-scope-parity-test.php
php tests/email-author-counts-test.php
php tests/free-floor-load-order-test.php
```

Those commands alone are not rendered CSV/email coverage; perform the isolated consumer fixture described above as well. Follow `CONTRIBUTING.md` for the affected admin E2E lane (`npm run test:e2e` in the provisioned test environment). Never run destructive E2E fixtures on the user's live WordPress database.

For a private snapshot regression, reuse only the four analytics tables in an isolated database and the recorded bounds below. Do not commit the customer's raw data or names:

| Window | Raw `dt` bounds | Eligible names / authors | Named hits / authored hits |
|---|---|---:|---:|
| Reconstructed 28 days | `1787788800..1790207999` | 8 / 1 | 59 / 222 |
| Six months | `1774483200..1790467199` | 90 / 7 | 2,252 / 18,380 |

The all-pageviews denominators remain 226 and 21,353 respectively on this dump. Removing anonymous groups must not rebase percentages to 100% of identified hits. For browser controls, the snapshot's capped UA query remains 200 groups, Human Browsers remains 180, and Chrome 121 remains 3,343 over six months. Include a separate synthetic fixture with data on both sides of midnight; this dump has no QA-day live rows.

## Done criteria and stop conditions

- Both reports exclude NULL and empty identities before LIMIT in raw and rendered results.
- Existing named counts, date/filter behavior, author restrictions and pageview denominators remain intact.
- Current generic merge controls continue passing, especially NULL language/country and composite browser keys.
- Oracle contracts, capture arguments and registry checks agree; red-before/green-after evidence exists.
- Pro CSV/email consumers show the same eligible groups without modifying Pro's runtime code.
- Relevant standalone, oracle, lint and provisioned unit/admin checks pass with their execution scope recorded.
- Only in-scope files changed, no customer data was added, and the plan index reflects actual completion.

Stop and report if the report definition has already changed, a consumer replaces/discards the registered predicate, an unresolved account-identity policy is needed, a fix requires altering the generic merger, or tests demand widening production scope. Do not rewrite snapshots or weaken expected results to force a pass.

Maintenance: future changes to registered report arguments must also update the duplicate capture/contract definitions. That is the existing test architecture; restructuring it is outside this two-report fix.

## Implementation verification (2026-09-27)

- Two report-level eligibility predicates implemented; Pro production source unchanged.
- Behavioral regression failed before the fix and passed after it across historical/live/split ranges, limits, independent visitor/author identities, missing-only and empty tables, and NULL-language controls.
- `composer test:unit`: 431 tests, 1,089 assertions; exit 0, with 8 PHP 8.5 deprecations.
- `composer test:source-level`: passed with production autoloader rebuilt using `composer run build:autoload`.
- Supplied SQL restored into a disposable MariaDB database: both recorded windows and extra live/historical windows matched independent SQL exactly after filtering. No customer rows or names added to git.
- Separate disposable WordPress fixture: both Free HTML outputs, Pro email HTML/CSV renderers, author-restricted email output, and authorized export handlers passed. CSV percentages stayed 25% and 12.5% against all 16 pageviews.
- Eight relevant Pro standalone scripts passed (rounding, load order, fail-soft bootstrap, version floors, author counts/floor, email scope and settings).
- Reviewed final diff for minimality: two production lines; existing oracle/capture contracts kept aligned; no generic merge, parser, storage or Pro runtime changes.

- Chromium admin widget checks passed for `slim_p1_11` and `slim_p4_18`, including original percentages. The fixture browser blocked the tracker script so browser visits could not change the denominator.
- Commit `7a4030a8`: staged PHP lint and the full source-level pre-commit gate passed.

## PR review follow-up

Commit `20f32467258bf842d6eb5d689a1dfd2d7ba3c7ad` addresses all three independently verified review comments. Explicit empty-string checks preserve the stored name `"0"` in the Free renderer. The regression was added to the existing WordPress-backed escaping test: red before the guard change, all 84 assertions green afterward. Missing names still render as Guest in general reports. The standalone test now reads literal callback arguments without eval, and carries a GPL header. Full unit suite (431 tests / 1,089 assertions / 8 deprecations) and source-level pre-commit gate passed again. The literal-reader test also still fails when the two predicates are removed in a disposable copy. CI for this exact head passed before merge. Merge commit: `2e6aae8e7d1e4258bae0de2b3bbb862d4878d735`.

## Merge verification

PR #342 merged into development at 2026-09-27T17:20:53Z, commit `2e6aae8e7d1e4258bae0de2b3bbb862d4878d735`. The merge tree exactly matches tested head `20f32467258bf842d6eb5d689a1dfd2d7ba3c7ad`. PR CI run 36334473053 passed 13 jobs (nightly skipped); push run 36334470057 passed 8 jobs (PR-only E2E and nightly skipped). No failed steps were hidden by soft lanes. Full WP 6.4 E2E: 709 passed, 50 skipped. WP 6.4 and 7.1 logs confirm all 84 rendering/escaping assertions passed. All three review threads are resolved; CodeRabbit's follow-up review was rate-limited, so its success status is not claimed as a second review.

Final QA response: `jaan-to/outputs/dev/v6-qa-regressions/QA-RESPONSE.fa.md` in the workspace root. Pro remains unchanged at `fb3cad58aafcbd323c0df631f7e4b5e2a8758560`, confirmed against freshly fetched origin/development.
