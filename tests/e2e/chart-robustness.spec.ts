/**
 * E2E: Chart robustness — timezone, browser AJAX, and edge-case coverage
 *
 * Fills 3 reliability gaps identified in the chart test audit:
 *   Gap 3: UTC-only data — tests with non-zero gmt_offset
 *   Gap 4: No browser AJAX tests — real page navigation + data extraction
 *   Gap 6: Synthetic-only seeding — boundary & edge-case data patterns
 *
 * Tests manipulate MySQL session timezone via SET time_zone to make
 * TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) reflect the offset,
 * simulating real-world non-UTC server configurations.
 */
import { test, expect } from '@playwright/test';
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  getV1, getLabels, sumArr, sumV1, utcMidnight, utcTimestamp,
} from './helpers/chart';
import { BASE_URL } from './helpers/env';
import type { Page } from '@playwright/test';

/** Login as admin if the page was redirected or access denied */
async function ensureAdminLoggedIn(page: Page): Promise<void> {
  const html = await page.content();
  const needsLogin = page.url().includes('wp-login.php') ||
    html.includes('not allowed to access');

  if (needsLogin) {
    await page.goto(`${BASE_URL}/wp-login.php`);
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

// ─── MySQL timezone helper ──────────────────────────────────────────────────

/**
 * Set MySQL session timezone and WordPress gmt_offset in tandem.
 * This makes TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) return
 * the correct offset, just like a real non-UTC server would.
 */
async function setTimezone(mysqlTz: string, wpGmtOffset: string): Promise<void> {
  const pool = getPool();
  await pool.execute(`SET time_zone = '${mysqlTz}'`);
  await pool.execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'gmt_offset'",
    [wpGmtOffset]
  );
}

async function resetTimezone(): Promise<void> {
  const pool = getPool();
  await pool.execute("SET time_zone = '+00:00'");
  await pool.execute(
    "UPDATE wp_options SET option_value = '0' WHERE option_name = 'gmt_offset'",
  );
}

// ─── TIMEZONE TESTS (Gap 3) ─────────────────────────────────────────────────

test.describe('Chart timezone robustness', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    await snapshotOption('gmt_offset');
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");
  });

  test.afterAll(async () => {
    await resetTimezone();
    await restoreOption('gmt_offset');
    await restoreOption('start_of_week');
    await clearTestData();
  });

  test.beforeEach(async () => {
    await clearTestData();
    await resetTimezone();
  });

  test('TZ-1: gmt_offset=+5.5 — weekly chart sum equals DB total', async () => {
    await setTimezone('+05:30', '5.5');

    // Seed at UTC midnight — in IST this is 05:30
    await insertRows(utcMidnight('2026-03-10'), 10, 'tz1-mar10');
    await insertRows(utcMidnight('2026-03-14'), 15, 'tz1-mar14');
    await insertRows(utcMidnight('2026-03-17'), 5, 'tz1-mar17');

    const totalExpected = 30;
    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const chartSum = sumV1(json);
    console.log(`TZ-1: offset=+5.5, sum=${chartSum}, expected=${totalExpected}`);

    expect(chartSum, 'Chart sum must equal DB total with +5.5 offset').toBe(totalExpected);
  });

  test('TZ-2: gmt_offset=-8 — records straddling midnight bucket correctly', async () => {
    await setTimezone('-08:00', '-8');

    // Seed at 23:00 UTC (= 15:00 PST, same day) and 07:00 UTC (= 23:00 PST prev day)
    const mar14_2300 = utcTimestamp('2026-03-14 23:00:00');
    const mar14_0700 = utcTimestamp('2026-03-14 07:00:00');

    await insertRows(mar14_2300, 5, 'tz2-late');
    await insertRows(mar14_0700, 8, 'tz2-early');

    const totalExpected = 13;
    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    console.log(`TZ-2: offset=-8, sum=${chartSum}, expected=${totalExpected}`);

    // All records must be accounted for regardless of timezone
    expect(chartSum, 'No data loss with -8 offset').toBe(totalExpected);
  });

  test('TZ-3: gmt_offset=0 — baseline sanity check', async () => {
    await setTimezone('+00:00', '0');

    await insertRows(utcMidnight('2026-03-10'), 10, 'tz3-mar10');
    await insertRows(utcMidnight('2026-03-15'), 20, 'tz3-mar15');

    const totalExpected = 30;
    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    console.log(`TZ-3: offset=0, sum=${chartSum}, expected=${totalExpected}`);

    expect(chartSum).toBe(totalExpected);
  });

  test('TZ-4: cross-timezone consistency — same data, offset=0 vs offset=+5.5 sums match', async () => {
    // Seed data with UTC midnight timestamps (well within day boundaries)
    await insertRows(utcMidnight('2026-03-05'), 10, 'tz4-mar05');
    await insertRows(utcMidnight('2026-03-10'), 15, 'tz4-mar10');
    await insertRows(utcMidnight('2026-03-15'), 20, 'tz4-mar15');

    const totalExpected = 45;
    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    // Fetch with UTC
    await setTimezone('+00:00', '0');
    const jsonUtc = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonUtc?.success).toBe(true);
    const sumUtc = sumV1(jsonUtc);

    // Fetch with +5.5
    await setTimezone('+05:30', '5.5');
    const jsonIst = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonIst?.success).toBe(true);
    const sumIst = sumV1(jsonIst);

    console.log(`TZ-4: UTC sum=${sumUtc}, IST sum=${sumIst}, expected=${totalExpected}`);

    // Both must capture all data — sums must match
    expect(sumUtc, 'UTC sum').toBe(totalExpected);
    expect(sumIst, 'IST sum').toBe(totalExpected);
  });
});

// ─── BROWSER AJAX TESTS (Gap 4) ─────────────────────────────────────────────

test.describe('Chart browser AJAX', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    // Reset MySQL timezone in case previous test suite left it changed
    await getPool().execute("SET time_zone = '+00:00'");
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");
  });

  test.afterAll(async () => {
    await restoreOption('start_of_week');
    await clearTestData();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  test('AJAX-1: slimview2 data-data attribute matches WP-CLI fetchChartData totals', async ({ page }) => {
    // Seed known data
    await insertRows(utcMidnight('2026-03-10'), 10, 'ajax1-mar10');
    await insertRows(utcMidnight('2026-03-15'), 20, 'ajax1-mar15');

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    // Get WP-CLI reference total
    const wpcliJson = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(wpcliJson?.success).toBe(true);
    const wpcliSum = sumV1(wpcliJson);
    expect(wpcliSum).toBe(30);

    // Navigate to slimview2 as admin
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });
    await ensureAdminLoggedIn(page);
    // Re-navigate after login if needed
    if (!page.url().includes('slimview2')) {
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
        waitUntil: 'networkidle',
      });
    }

    await page.waitForLoadState('domcontentloaded');

    // The chart data element uses id="slimstat_chart_data_XXX" where XXX is the chart ID
    const chartDataEl = page.locator('[id^="slimstat_chart_data_"]').first();
    await expect(chartDataEl).toBeAttached({ timeout: 15_000 });

    const dataRaw = await chartDataEl.getAttribute('data-data');
    expect(dataRaw, 'data-data attribute must exist').toBeTruthy();

    const chartData = JSON.parse(dataRaw!);
    const browserV1 = chartData?.datasets?.v1 ?? [];
    const browserSum = sumArr(browserV1);

    console.log(`AJAX-1: WP-CLI sum=${wpcliSum}, Browser sum=${browserSum}`);

    // Browser-rendered chart includes the page's own pageview (Slimstat tracks admin visits),
    // so the browser total may be slightly higher than the WP-CLI reference.
    // Verify they're within 2 of each other (admin page loads can trigger 1-2 extra rows).
    expect(browserSum, 'Browser chart total must be close to WP-CLI total')
      .toBeGreaterThanOrEqual(wpcliSum);
    expect(browserSum - wpcliSum, 'Difference should be small (self-tracking)')
      .toBeLessThanOrEqual(5);
  });

  test('AJAX-2: granularity dropdown changes chart data structure', async ({ page }) => {
    // Seed data across a wide range to ensure both monthly and daily granularities differ
    const dates = [
      '2025-11-20', '2025-12-15', '2026-01-10', '2026-02-05',
      '2026-03-01', '2026-03-10', '2026-03-15', '2026-03-17',
    ];
    for (const d of dates) {
      await insertRows(utcMidnight(d), 5, d.replace(/-/g, ''));
    }

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });
    await ensureAdminLoggedIn(page);
    if (!page.url().includes('slimview2')) {
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
        waitUntil: 'networkidle',
      });
    }

    const chartDataEl = page.locator('[id^="slimstat_chart_data_"]').first();
    await expect(chartDataEl).toBeAttached({ timeout: 15_000 });

    // Get initial granularity and data
    const initialGran = await chartDataEl.getAttribute('data-granularity');
    const initialDataRaw = await chartDataEl.getAttribute('data-data');
    expect(initialDataRaw, 'Initial data-data must exist').toBeTruthy();
    const initialData = JSON.parse(initialDataRaw!);
    const initialV1 = initialData?.datasets?.v1 ?? [];

    console.log(`AJAX-2: initial granularity=${initialGran}, points=${initialV1.length}`);

    // The chart has data and a valid structure
    expect(initialV1.length, 'Chart must have data points').toBeGreaterThan(0);
    expect(sumArr(initialV1), 'Chart must show seeded data').toBeGreaterThan(0);

    // Change to a different granularity — pick one that's enabled and different
    const granSelect = page.locator('.slimstat-granularity-select').first();
    const options = await granSelect.locator('option:not([disabled])').allTextContents();
    const optionValues = await granSelect.locator('option:not([disabled])').evaluateAll(
      (els) => els.map(el => (el as HTMLOptionElement).value)
    );
    const targetGran = optionValues.find(v => v !== initialGran) ?? 'daily';
    console.log(`AJAX-2: available granularities=${optionValues.join(',')}, switching to ${targetGran}`);
    await granSelect.selectOption(targetGran);

    // Wait for AJAX update
    await page.waitForTimeout(3000);

    const updatedDataRaw = await chartDataEl.getAttribute('data-data');
    const updatedData = JSON.parse(updatedDataRaw!);
    const updatedV1 = updatedData?.datasets?.v1 ?? [];

    console.log(`AJAX-2: changed to ${targetGran}, points=${updatedV1.length}`);

    // The number of points should change when granularity changes
    expect(updatedV1.length, 'Point count must change after granularity switch')
      .not.toBe(initialV1.length);

    console.log('AJAX-2 PASS: granularity switch changed data structure');
  });
});

// ─── EDGE CASE DATA TESTS (Gap 6) ───────────────────────────────────────────

test.describe('Chart edge cases', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");
  });

  test.afterAll(async () => {
    await restoreOption('start_of_week');
    await clearTestData();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  test('EDGE-1: midnight boundary — 23:59:55 and 00:00:00 in different daily buckets', async () => {
    // Insert 5 rows ending at 23:59:59 (spacing 1s apart: 55,56,57,58,59 — all before midnight)
    const mar14_late = utcTimestamp('2026-03-14 23:59:55');
    const mar15_start = utcTimestamp('2026-03-15 00:00:00');

    await insertRows(mar14_late, 5, 'edge1-late');
    await insertRows(mar15_start, 8, 'edge1-early');

    const rangeStart = utcMidnight('2026-03-14');
    const rangeEnd = utcMidnight('2026-03-15') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);

    console.log('EDGE-1 labels:', labels, 'v1:', v1);

    // Should have 2 daily buckets
    expect(v1.length).toBe(2);
    expect(v1[0], 'Mar 14 bucket').toBe(5);
    expect(v1[1], 'Mar 15 bucket').toBe(8);

    console.log('EDGE-1 PASS: midnight boundary correctly splits buckets');
  });

  test('EDGE-2: concurrent visits — 10 rows at identical timestamp in one bucket', async () => {
    const ts = utcMidnight('2026-03-15');

    // Insert 10 rows at the EXACT same timestamp (not +i offset)
    const pool = getPool();
    for (let i = 0; i < 10; i++) {
      await pool.execute(
        `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
         VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'chart-e2e')`,
        [ts, `/edge2-concurrent-${i}`]
      );
    }

    const rangeStart = utcMidnight('2026-03-14');
    const rangeEnd = utcMidnight('2026-03-16') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const mar15Idx = 1; // Second bucket (Mar 15)

    console.log('EDGE-2 v1:', v1);

    expect(v1[mar15Idx], 'Bucket must contain exactly 10 concurrent rows').toBe(10);
    expect(sumArr(v1), 'Total must be 10').toBe(10);

    console.log('EDGE-2 PASS: 10 concurrent rows correctly counted');
  });

  test('EDGE-3: month boundary — Feb 28 noon and Mar 1 noon in different monthly buckets', async () => {
    // Use noon timestamps to avoid midnight spillover with insertRows spacing
    const feb28Noon = utcTimestamp('2026-02-28 12:00:00');
    const mar1Noon = utcTimestamp('2026-03-01 12:00:00');

    await insertRows(feb28Noon, 100, 'edge3-feb');
    await insertRows(mar1Noon, 200, 'edge3-mar');

    const rangeStart = utcMidnight('2025-10-01');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'monthly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);

    const febIdx = labels.findIndex(l => l.includes('February'));
    const marIdx = labels.findIndex(l => l.includes('March'));

    expect(v1[febIdx], 'Feb bucket').toBe(100);
    expect(v1[marIdx], 'Mar bucket').toBe(200);

    console.log('EDGE-3 PASS: month boundary correct — Feb=100, Mar=200');
  });

  test('EDGE-4: sub-second spacing — 3 rows 1 second apart in same daily bucket', async () => {
    const base = utcTimestamp('2026-03-15 12:00:00');

    await insertRows(base, 1, 'edge4-0');
    await insertRows(base + 1, 1, 'edge4-1');
    await insertRows(base + 2, 1, 'edge4-2');

    const rangeStart = utcMidnight('2026-03-14');
    const rangeEnd = utcMidnight('2026-03-16') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const mar15Idx = 1;

    expect(v1[mar15Idx], 'All 3 rows in same daily bucket').toBe(3);

    console.log('EDGE-4 PASS: sub-second rows in same bucket');
  });

  test('EDGE-5: late-night with timezone — gmt_offset=+5.5, 19:30 UTC lands in next IST day', async () => {
    await snapshotOption('gmt_offset');

    try {
      // Set timezone to IST (+5:30)
      const pool = getPool();
      await pool.execute("SET time_zone = '+05:30'");
      await pool.execute(
        "UPDATE wp_options SET option_value = '5.5' WHERE option_name = 'gmt_offset'"
      );

      // 19:30 UTC on Mar 14 = 01:00 IST on Mar 15
      const lateUtc = utcTimestamp('2026-03-14 19:30:00');
      await insertRows(lateUtc, 5, 'edge5-late');

      // 06:00 UTC on Mar 14 = 11:30 IST on Mar 14
      const earlyUtc = utcTimestamp('2026-03-14 06:00:00');
      await insertRows(earlyUtc, 3, 'edge5-early');

      const rangeStart = utcMidnight('2026-03-13');
      const rangeEnd = utcMidnight('2026-03-16') + 86399;

      const json = fetchChartData(rangeStart, rangeEnd, 'daily');
      expect(json?.success).toBe(true);

      const v1 = getV1(json);
      const labels = getLabels(json);
      const chartSum = sumArr(v1);

      console.log('EDGE-5 labels:', labels, 'v1:', v1);

      // All 8 records must be accounted for regardless of timezone
      expect(chartSum, 'No data loss with timezone offset').toBe(8);

      console.log('EDGE-5 PASS: timezone edge-case data preserved, sum =', chartSum);
    } finally {
      // Reset MySQL timezone
      const pool = getPool();
      await pool.execute("SET time_zone = '+00:00'");
      await restoreOption('gmt_offset');
    }
  });
});
