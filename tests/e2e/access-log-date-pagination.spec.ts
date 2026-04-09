/**
 * E2E regression: Access Log custom date range pagination (#287).
 *
 * Before the fix, refresh_report() unconditionally stripped fs[day]/fs[month]/
 * fs[year]/fs[interval] from the AJAX payload whenever the report id was
 * "slim_p7_02". The strip block fired for THREE legitimate user actions —
 * pagination clicks, initial async load, and Screen Options re-activation —
 * causing the Access Log to silently return current-date rows even though the
 * date-range header still showed the user's selected window.
 *
 * After the fix, the strip only fires when the auto-refresh listener calls
 * `refresh_report("slim_p7_02", { forceRecent: true })`. All other callers
 * (pagination, async load, granularity, Screen Options) preserve dates.
 *
 * Source: customer support ticket #15082 (sanitized).
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  seedPageviews,
  captureAdminAjax,
} from './helpers/setup';

// A custom date range well in the past, outside the default last-7-days window.
// Picked deterministically so the same window can be asserted across runs.
const RANGE_FROM = '2026-02-01';
const RANGE_TO   = '2026-02-28';
const SEED_RESOURCE_PREFIX = '/e2e-287-row-';
const FEB_BASE_TS = Math.floor(new Date('2026-02-15T12:00:00Z').getTime() / 1000);

test.describe('Access Log custom date range — #287', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    // Seed 75 rows in February 2026 (the access log default page size is 50).
    // The "last 7 days" view (today is 2026-04+) is empty.
    await seedPageviews({
      count: 75,
      resourcePrefix: SEED_RESOURCE_PREFIX,
      baseDt: FEB_BASE_TS,
    });
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('refresh_report() without forceRecent preserves date filters in AJAX', async ({ page }) => {
    // This is the core regression for #287. On the buggy branch, the
    // refresh_report factory unconditionally strips date filters whenever
    // id == "slim_p7_02", regardless of the caller. On the fixed branch,
    // the strip is gated on opts.forceRecent. The Screen Options re-
    // activation, pagination, async load, and granularity callers all
    // invoke refresh_report without forceRecent, and they all must
    // preserve the user's date range.
    const cap = captureAdminAjax(page, (b) => b.includes('slimstat_load_report'));

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    // Wait for the seeded rows to actually appear in the widget rather than
    // sleeping. The Access Log is server-rendered for slim_p7_02 (skipped
    // by the async-load loop because of .refresh-timer), so this is a
    // condition-based wait on the rendered DOM, not on a fixed timeout.
    await expect(widget).toContainText(SEED_RESOURCE_PREFIX, { timeout: 15_000 });

    cap.reset();

    // Invoke the factory exactly the way pagination and Screen Options do —
    // without any options. The pre-fix code drops fs[day]/fs[month]/fs[year]/
    // fs[interval] from the payload anyway; the post-fix code keeps them.
    // Await the returned jQuery deferred so we don't depend on a fixed timeout.
    await page.evaluate(() => new Promise<void>((resolve) => {
      const SlimStatAdmin = (window as any).SlimStatAdmin;
      const refresh = SlimStatAdmin.refresh_report('slim_p7_02');
      refresh().always(() => resolve());
    }));

    const slimP702 = cap.payloads.filter((p) => p.includes('slim_p7_02'));
    expect(slimP702.length, 'refresh_report invocation should fire AJAX').toBeGreaterThan(0);

    // The payload MUST contain the active date filters because no caller
    // passed forceRecent.
    const hasDateFilter = slimP702.some(
      (p) =>
        p.includes('fs%5Bday%5D') ||
        p.includes('fs[day]') ||
        p.includes('fs%5Binterval%5D') ||
        p.includes('fs[interval]'),
    );
    expect(
      hasDateFilter,
      'refresh_report() without forceRecent must preserve fs[day]/fs[interval] (#287)',
    ).toBe(true);
  });

  test('pagination next-page click preserves the custom date range', async ({ page }) => {
    const cap = captureAdminAjax(page, (b) => b.includes('slimstat_load_report'));

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    // Condition-based wait: the seeded rows must be present before we
    // hunt for the pagination link.
    await expect(widget).toContainText(SEED_RESOURCE_PREFIX, { timeout: 15_000 });

    // Use the deterministic "next page" arrow class. SlimStat's pagination
    // helper renders the next-page anchor with `slimstat-font-angle-right`
    // (and previous with `slimstat-font-angle-left`). The previous selector
    // `.pagination a[href*="start_from"]` matched ANY paginated anchor —
    // including page-N and last-page links — so `.first()` could pick the
    // wrong one when more than one was rendered.
    //
    // Hard-assert the next-page link exists. With 75 seeded rows and a
    // default page size of 50, the next-page arrow MUST be rendered. If
    // it's not, the seed setup, the URL params, or the PHP rendering
    // regressed and the test should fail loudly, not skip.
    const nextLink = widget.locator('.pagination a.refresh.slimstat-font-angle-right');
    await expect(
      nextLink,
      'next-page arrow must be rendered (75 rows / 50 per page)',
    ).toHaveCount(1, { timeout: 5_000 });

    cap.reset();

    // Wait for the AJAX response triggered by the click instead of sleeping.
    const ajaxPromise = page.waitForResponse(
      (resp) =>
        resp.url().includes('admin-ajax.php') &&
        resp.request().method() === 'POST' &&
        (resp.request().postData() || '').includes('slim_p7_02'),
      { timeout: 15_000 },
    );
    await nextLink.first().click();
    await ajaxPromise;
    // Wait for the swapped DOM to actually contain the new rows before
    // asserting the body — this races the .done callback's html() swap.
    await expect(widget).toContainText(SEED_RESOURCE_PREFIX, { timeout: 15_000 });

    // The pagination AJAX MUST include the custom date filters.
    expect(cap.payloads.length, 'pagination should fire at least one AJAX call').toBeGreaterThan(0);
    const slimP702 = cap.payloads.filter((p) => p.includes('slim_p7_02'));
    expect(slimP702.length, 'pagination should fire a slim_p7_02 AJAX call').toBeGreaterThan(0);
    const hasDateFilter = slimP702.some(
      (p) => p.includes('fs%5Bday%5D') || p.includes('fs[day]'),
    );
    expect(hasDateFilter, 'pagination payload must include fs[day] (#287)').toBe(true);
  });

  test('forceRecent: true still strips date filters from AJAX (regression guard)', async ({ page }) => {
    const cap = captureAdminAjax(page, (b) => b.includes('slimstat_load_report'));

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    // Condition-based wait: ensure SlimStatAdmin is hydrated before invoking
    // refresh_report directly.
    await page.waitForFunction(
      () => typeof (window as any).SlimStatAdmin?.refresh_report === 'function',
      { timeout: 15_000 },
    );

    cap.reset();

    // Bypass the access_log_count_down listener guard (which only fires when
    // .pagination .refresh-timer is mounted — and the PHP gate at
    // wp-slimstat-reports.php:1058 hides the timer for past date ranges).
    // Call the factory directly with forceRecent: true to prove the strip
    // logic still fires under the new conditional. Await the deferred so we
    // don't depend on a fixed timeout.
    await page.evaluate(() => new Promise<void>((resolve) => {
      const SlimStatAdmin = (window as any).SlimStatAdmin;
      const refresh = SlimStatAdmin.refresh_report('slim_p7_02', { forceRecent: true });
      refresh().always(() => resolve());
    }));

    const slimP702 = cap.payloads.filter((p) => p.includes('slim_p7_02'));
    expect(slimP702.length, 'forceRecent invocation should fire AJAX').toBeGreaterThan(0);

    // The forceRecent payload MUST NOT contain any date filters.
    const stripped = slimP702.every(
      (p) =>
        !p.includes('fs%5Bday%5D') &&
        !p.includes('fs[day]') &&
        !p.includes('fs%5Bmonth%5D') &&
        !p.includes('fs[month]') &&
        !p.includes('fs%5Byear%5D') &&
        !p.includes('fs[year]') &&
        !p.includes('fs%5Binterval%5D') &&
        !p.includes('fs[interval]'),
    );
    expect(stripped, 'forceRecent payload should NOT contain fs[day|month|year|interval]').toBe(true);
  });
});
