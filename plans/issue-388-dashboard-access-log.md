# Issue #388: keep the Dashboard Access Log free of promotions

Target: `development`, for 6.0.1. [Issue #388](https://github.com/wp-slimstat/wp-slimstat/issues/388).

The Free Dashboard Access Log appends an undismissible Pro preview. Use the
issue's preferred fix: register its normal report callback and remove the
Dashboard-only promotion renderer. Keep the Access Log, saved widget preferences,
deferred loading, and promotions on SlimStat's own screens.

1. Update the existing promotion regression to dismiss the release notice and
   require a visible seeded Access Log without promotion markup or copy.
2. Update the Dashboard refresh regression to require a successful AJAX response
   and usable report content without the promotional footer.
3. Remove the special Dashboard callback and its unused JavaScript wrapper
   handling; remove the two obsolete strings from the POT catalog.
4. Run the regressions before and after the fix on a disposable WordPress site,
   then check adjacent Dashboard/release-notice behavior and catalog freshness.
5. Commit and open one Free-plugin PR targeting `development` with `Fixes #388`.

No schema, settings migration, new dismissal state, or Pro-plugin change is needed.

## Verification

Completed on 2026-11-03 in an isolated Free-only WordPress 7.0 / PHP 8.3.36
instance, based on `development` at `1f8ca9c6`.

- Before the fix, both revised regressions failed specifically because the
  Dashboard Access Log contained `.slimstat-pro-adds` and `.slimstat-preview`.
  The seeded log entry was visible and the release notice was dismissed.
- After the fix, the four E2E specs below passed: **21 passed, 1 skipped**.
  The skipped case requires Pro, which is not installed in this Free-only lane.
  Coverage includes saved log data, disabling Dashboard widgets, AJAX refresh,
  deferred loading with both Async settings, widget sizing, SlimStat-screen
  promotions, and release-notice dismissal/expiry.
- `ReleaseNoticeTest`: **9 tests, 59 assertions**, all passed on PHP 8.3.36.
- Dashboard queue self-check, PHP lint, JavaScript syntax, catalog freshness
  (`python3 tests/check-pot.py`), and `git diff --check` passed.
- The complete E2E suite and full PHP/WordPress release matrix were not run.

E2E command, with the disposable instance configured through environment variables:

```sh
npx playwright test --config=tests/e2e/playwright.config.ts \
  tests/e2e/october-promotions.spec.ts \
  tests/e2e/dashboard-deferred-reports.spec.ts \
  tests/e2e/dashboard-access-log-height.spec.ts \
  tests/e2e/release-notice.spec.ts --project=admin --retries=0
```
