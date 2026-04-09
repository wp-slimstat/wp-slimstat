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
import { test, expect, Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { getPool, closeDb, clearStatsTable } from './helpers/setup';

/** Login if the page was redirected to wp-login.php */
async function ensureLoggedIn(page: Page): Promise<void> {
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

// A custom date range well in the past, outside the default last-7-days window.
// Picked deterministically so the same window can be asserted across runs.
const RANGE_FROM = '2026-02-01';
const RANGE_TO   = '2026-02-28';
const SEED_RESOURCE_PREFIX = '/e2e-287-row-';

/**
 * Seed N pageview rows with a `dt` inside the custom range so the date filter
 * has rows to return when the user paginates through them.
 */
async function seedFebruaryPageviews(count: number): Promise<void> {
  const baseTs = Math.floor(new Date('2026-02-15T12:00:00Z').getTime() / 1000);
  for (let i = 0; i < count; i++) {
    await getPool().execute(
      `INSERT INTO wp_slim_stats
         (resource, dt, ip, visit_id, browser, platform, content_type)
       VALUES (?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
      [`${SEED_RESOURCE_PREFIX}${i}`, baseTs + i],
    );
  }
}

test.describe('Access Log custom date range — #287', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    // Seed 75 rows in February 2026 (the access log default page size is 50).
    // The "last 7 days" view (today is 2026-04+) is empty.
    await seedFebruaryPageviews(75);
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
    const payloads: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_load_report')) payloads.push(body);
      }
    });

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(3_000);

    // Sanity check: the seeded February rows should be in the server-
    // rendered widget (proves the URL/PHP filter pipeline is working).
    const widgetHtml = await widget.innerHTML();
    expect(widgetHtml, 'access log should show seeded February rows').toContain(SEED_RESOURCE_PREFIX);

    payloads.length = 0;

    // Invoke the factory exactly the way pagination and Screen Options do —
    // without any options. The pre-fix code drops fs[day]/fs[month]/fs[year]/
    // fs[interval] from the payload anyway; the post-fix code keeps them.
    // Await the returned jQuery deferred so we don't depend on a fixed timeout.
    await page.evaluate(() => new Promise<void>((resolve) => {
      const SlimStatAdmin = (window as any).SlimStatAdmin;
      const refresh = SlimStatAdmin.refresh_report('slim_p7_02');
      refresh().always(() => resolve());
    }));

    const slimP702 = payloads.filter((p) => p.includes('slim_p7_02'));
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
    const payloads: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_load_report')) payloads.push(body);
      }
    });

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(3_000);

    // Find any pagination link inside the access log pagination bar that
    // includes fs[start_from] in its href (the "next page" / page-N links).
    const linkCount = await widget.locator('.pagination a[href*="start_from"]').count();
    test.skip(linkCount === 0, 'no pagination link rendered (need >1 page of seed data)');

    payloads.length = 0;

    await widget.locator('.pagination a[href*="start_from"]').first().click();
    await page.waitForTimeout(3_000);

    // The pagination AJAX MUST include the custom date filters.
    expect(payloads.length, 'pagination should fire at least one AJAX call').toBeGreaterThan(0);
    const slimP702 = payloads.filter((p) => p.includes('slim_p7_02'));
    expect(slimP702.length, 'pagination should fire a slim_p7_02 AJAX call').toBeGreaterThan(0);
    const hasDateFilter = slimP702.some(
      (p) => p.includes('fs%5Bday%5D') || p.includes('fs[day]'),
    );
    expect(hasDateFilter, 'pagination payload must include fs[day] (#287)').toBe(true);

    // The next page should still contain seeded February rows.
    const widgetHtml = await widget.innerHTML();
    expect(widgetHtml, 'next page should still contain February rows').toContain(SEED_RESOURCE_PREFIX);
  });

  test('forceRecent: true still strips date filters from AJAX (regression guard)', async ({ page }) => {
    const payloads: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_load_report')) payloads.push(body);
      }
    });

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&type=custom&from=${RANGE_FROM}&to=${RANGE_TO}`,
      { waitUntil: 'domcontentloaded' },
    );
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(3_000);

    payloads.length = 0;

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

    const slimP702 = payloads.filter((p) => p.includes('slim_p7_02'));
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
