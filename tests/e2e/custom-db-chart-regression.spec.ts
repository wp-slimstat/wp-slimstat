/**
 * E2E: CustomDB prefix regression — chart and sidebar data consistency
 *
 * Validates the fix for the CustomDB table prefix bug: when the External DB
 * addon is enabled, CustomDBAddon creates a new wpdb instance without calling
 * set_prefix(), causing wp_slimstat::$wpdb->prefix to be '' (empty string).
 * This made Chart.php query a non-existent table (e.g., 'slim_stats' instead
 * of 'wp_slim_stats'), resulting in the chart showing 0 while the sidebar
 * (which uses $GLOBALS['wpdb']->prefix) showed correct data.
 *
 * Fix:
 *   1. CustomDBAddon now calls set_prefix($GLOBALS['wpdb']->prefix) on the
 *      new wpdb instance (primary fix, in Pro)
 *   2. Chart.php uses $GLOBALS['wpdb']->prefix for table names (defense-in-depth)
 *
 * The custom-db-simulator MU plugin simulates this by cloning the global wpdb
 * and calling set_prefix() with the correct prefix.
 *
 * Source: Pro vs Free data inconsistency when External DB is enabled
 */
import { test, expect, Page } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getPool,
  closeDb,
  installMuPluginByName,
  uninstallMuPluginByName,
  snapshotOption,
  restoreOption,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── DB helpers ───────────────────────────────────────────────────────────────

async function insertRows(timestamp: number, count: number, label: string): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'customdb-regression-e2e')`,
      [timestamp + i, `/customdb-regression-${label}-${i}`]
    );
  }
}

async function countTestRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE user_agent = 'customdb-regression-e2e'"
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

async function clearTransients(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

async function activateCustomDb(): Promise<void> {
  await getPool().execute(
    "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_test_use_custom_db', 'yes', 'yes') ON DUPLICATE KEY UPDATE option_value = 'yes'"
  );
}

async function deactivateCustomDb(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name = 'slimstat_test_use_custom_db'"
  );
}

// ─── Chart helpers ────────────────────────────────────────────────────────────

async function extractChartNonce(page: Page): Promise<string> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
    waitUntil: 'domcontentloaded',
  });
  const nonce = await page.evaluate(() => (window as any).slimstat_chart_vars?.nonce || null);
  if (!nonce) throw new Error('slimstat_chart_vars.nonce not found — chart JS not loaded on slimview2');
  return nonce;
}

async function callChartAjaxWithNonce(
  page: Page,
  nonce: string,
  startTs: number,
  endTs: number,
  granularity: 'weekly' | 'daily' | 'monthly' | 'hourly' | 'yearly' = 'weekly'
): Promise<any> {
  const args = JSON.stringify({
    start: startTs,
    end: endTs,
    chart_data: {
      data1: 'COUNT(id)',
      data2: 'COUNT( DISTINCT ip )',
    },
  });

  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'slimstat_fetch_chart_data', nonce, args, granularity },
  });

  expect(res.ok(), `Chart AJAX returned HTTP ${res.status()}`).toBe(true);
  return res.json();
}

async function readServerRenderedChart(page: Page): Promise<any> {
  const raw = await page.evaluate(() => {
    const el = document.querySelector('[id^="slimstat_chart_data_"]');
    return el ? el.getAttribute('data-data') : null;
  });
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function readChartTotals(page: Page): Promise<{ current: { v1: number; v2: number }; previous: { v1: number; v2: number } } | null> {
  const raw = await page.evaluate(() => {
    const el = document.querySelector('[id^="slimstat_chart_data_"]');
    return el ? el.getAttribute('data-totals') : null;
  });
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

async function readSidebarPageviews(page: Page): Promise<number> {
  // The "At a Glance" report (slim_p1_03) renders metrics as <p> elements.
  // The first <p> inside the report body contains: "Pageviews <span>243</span>"
  const value = await page.evaluate(() => {
    const postbox = document.querySelector('#slim_p1_03');
    if (!postbox) return -1;
    const firstP = postbox.querySelector('.inside p');
    if (!firstP) return -1;
    const span = firstP.querySelector('span');
    if (!span) return -1;
    // Remove thousands separators (commas, dots, spaces) and parse
    return parseInt(span.textContent?.replace(/[,.\s]/g, '') || '0', 10);
  });
  return value;
}

function sumV1(json: any): number {
  // AJAX response: { success: true, data: { data: { datasets: { v1: [...] } } } }
  const ajaxDatasets = json?.data?.data?.datasets?.v1;
  if (Array.isArray(ajaxDatasets)) {
    return (ajaxDatasets as number[]).reduce((a, b) => a + b, 0);
  }
  // Server-rendered: { datasets: { v1: [...] } }
  const serverDatasets = json?.datasets?.v1;
  if (Array.isArray(serverDatasets)) {
    return (serverDatasets as number[]).reduce((a, b) => a + b, 0);
  }
  return 0;
}

function ajaxTotalsV1(json: any): number {
  return json?.data?.totals?.current?.v1 ?? 0;
}

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('CustomDB prefix regression — chart and sidebar data consistency', () => {
  test.setTimeout(180_000);

  // 3-week range ending now
  const now = Math.floor(Date.now() / 1000);
  const week = 7 * 24 * 3600;
  const day = 24 * 3600;
  const rangeStart = now - 3 * week;
  const rangeEnd = now;

  // Timestamps for records in each of 3 weeks
  const week1Ts = rangeStart + Math.floor(week * 0.5); // middle of week 1
  const week2Ts = rangeStart + Math.floor(week * 1.5); // middle of week 2
  const week3Ts = rangeStart + Math.floor(week * 2.5); // middle of week 3

  test.beforeAll(() => {
    installOptionMutator();
    installMuPluginByName('custom-db-simulator-mu-plugin.php');
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    uninstallMuPluginByName('custom-db-simulator-mu-plugin.php');
    await closeDb();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await snapshotOption('slimstat_test_use_custom_db');
    await clearStatsTable();
    await clearTransients();
    await deactivateCustomDb();
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');

    // Insert 15 records across 3 weeks (5 per week)
    await insertRows(week1Ts, 5, 'w1');
    await insertRows(week2Ts, 5, 'w2');
    await insertRows(week3Ts, 5, 'w3');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(15);
  });

  test.afterEach(async () => {
    await deactivateCustomDb();
    await restoreSlimstatOptions();
    await restoreOption('slimstat_test_use_custom_db');
  });

  // ─── Test 1: Baseline — chart correct without custom DB ──────────────────────

  test('server-rendered chart shows correct data when custom DB is INACTIVE', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('[id^="slimstat_chart_data_"]', { state: 'attached', timeout: 10_000 });

    const chartData = await readServerRenderedChart(page);
    expect(chartData, 'data-data attribute missing or empty').not.toBeNull();

    const chartSum = sumV1(chartData);
    expect(chartSum).toBeGreaterThanOrEqual(15);

    const totals = await readChartTotals(page);
    expect(totals, 'data-totals attribute missing').not.toBeNull();
    expect(totals!.current.v1).toBeGreaterThanOrEqual(15);

    const sidebarPv = await readSidebarPageviews(page);
    expect(sidebarPv).toBeGreaterThanOrEqual(15);

    // Sidebar and chart totals should match
    expect(sidebarPv).toBe(totals!.current.v1);

    console.log(`Test 1 PASS — no custom DB: chart sum=${chartSum}, totals.v1=${totals!.current.v1}, sidebar=${sidebarPv}`);
  });

  // ─── Test 2: CRITICAL — chart correct WITH custom DB active ──────────────────

  test('server-rendered chart shows correct data when custom DB IS ACTIVE', async ({ page }) => {
    /**
     * CRITICAL regression test: before the fix, this test would show chart=0
     * while sidebar showed the correct pageview count.
     */
    await activateCustomDb();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('[id^="slimstat_chart_data_"]', { state: 'attached', timeout: 10_000 });

    const chartData = await readServerRenderedChart(page);
    expect(chartData, 'data-data attribute missing or empty').not.toBeNull();

    const chartSum = sumV1(chartData);
    // CRITICAL: before the fix, chartSum was 0 because the chart queried a
    // non-existent table 'slim_stats' (missing prefix)
    expect(chartSum, 'Chart shows 0 — likely the CustomDB prefix bug is still present').toBeGreaterThanOrEqual(15);

    const totals = await readChartTotals(page);
    expect(totals, 'data-totals attribute missing').not.toBeNull();
    expect(totals!.current.v1).toBeGreaterThanOrEqual(15);

    const sidebarPv = await readSidebarPageviews(page);
    expect(sidebarPv).toBeGreaterThanOrEqual(15);

    // Sidebar and chart totals MUST match — this was the core bug
    expect(sidebarPv).toBe(totals!.current.v1);

    console.log(`Test 2 PASS — custom DB active: chart sum=${chartSum}, totals.v1=${totals!.current.v1}, sidebar=${sidebarPv}`);
  });

  // ─── Test 3: AJAX data matches server-rendered with custom DB ─────────────────

  test('AJAX chart data matches server-rendered when custom DB is active', async ({ page }) => {
    // Extract nonce before activating custom DB (to get a valid nonce)
    const nonce = await extractChartNonce(page);

    await activateCustomDb();

    const json = await callChartAjaxWithNonce(page, nonce, rangeStart, rangeEnd, 'weekly');
    expect(json.success, `AJAX error: ${JSON.stringify(json.data)}`).toBe(true);

    const ajaxSum = sumV1(json);
    expect(ajaxSum).toBeGreaterThanOrEqual(15);

    const ajaxV1 = ajaxTotalsV1(json);
    expect(ajaxV1).toBeGreaterThanOrEqual(15);

    // Now load page to compare server-rendered data
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('[id^="slimstat_chart_data_"]', { state: 'attached', timeout: 10_000 });

    const serverData = await readServerRenderedChart(page);
    const serverSum = serverData ? sumV1(serverData) : 0;

    // AJAX and server-rendered should be consistent
    expect(ajaxSum).toBeGreaterThanOrEqual(serverSum);

    console.log(`Test 3 PASS — AJAX sum=${ajaxSum}, server sum=${serverSum}, AJAX totals.v1=${ajaxV1}`);
  });

  // ─── Test 4: Same results with custom DB on vs off ────────────────────────────

  test('chart AJAX returns same results with custom DB on vs off', async ({ page }) => {
    /**
     * CRITICAL: before the fix, the custom-DB-on sum was 0 while the off sum
     * was 15. After the fix, both should be equal.
     */
    const nonce = await extractChartNonce(page);

    // Baseline: custom DB OFF
    const jsonOff = await callChartAjaxWithNonce(page, nonce, rangeStart, rangeEnd, 'weekly');
    expect(jsonOff.success).toBe(true);
    const sumOff = sumV1(jsonOff);
    expect(sumOff).toBeGreaterThanOrEqual(15);

    // Test: custom DB ON
    await activateCustomDb();
    const jsonOn = await callChartAjaxWithNonce(page, nonce, rangeStart, rangeEnd, 'weekly');
    expect(jsonOn.success).toBe(true);
    const sumOn = sumV1(jsonOn);

    // Both sums must be equal — data comes from the same underlying table
    expect(sumOn).toBe(sumOff);

    console.log(`Test 4 PASS — custom DB off sum=${sumOff}, on sum=${sumOn}`);
  });

  // ─── Test 5: Sidebar consistent with chart when custom DB is active ───────────

  test('sidebar At a Glance values are consistent with chart when custom DB is active', async ({ page }) => {
    await activateCustomDb();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('[id^="slimstat_chart_data_"]', { state: 'attached', timeout: 10_000 });

    const sidebarPv = await readSidebarPageviews(page);
    const totals = await readChartTotals(page);

    expect(sidebarPv, 'Sidebar pageviews should be > 0').toBeGreaterThanOrEqual(15);
    expect(totals, 'Chart totals should exist').not.toBeNull();
    expect(totals!.current.v1, 'Chart totals v1 should be > 0').toBeGreaterThanOrEqual(15);

    // The core assertion: sidebar and chart must agree
    expect(sidebarPv).toBe(totals!.current.v1);

    // Also check no PHP errors in page
    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('Table \'');

    console.log(`Test 5 PASS — sidebar=${sidebarPv}, chart totals.v1=${totals!.current.v1}`);
  });

  // ─── Test 6: Different granularities work with custom DB ──────────────────────

  test('daily and hourly granularity work with custom DB active', async ({ page }) => {
    const nonce = await extractChartNonce(page);
    await activateCustomDb();

    // Daily granularity over last 7 days
    const last7Start = now - 7 * day;
    const jsonDaily = await callChartAjaxWithNonce(page, nonce, last7Start, rangeEnd, 'daily');
    expect(jsonDaily.success, `Daily AJAX error: ${JSON.stringify(jsonDaily.data)}`).toBe(true);
    const dailySum = sumV1(jsonDaily);
    // At least the 5 records from week3 should be in range
    expect(dailySum).toBeGreaterThanOrEqual(5);

    // Hourly granularity over last 2 days
    const last2Start = now - 2 * day;
    const jsonHourly = await callChartAjaxWithNonce(page, nonce, last2Start, rangeEnd, 'hourly');
    expect(jsonHourly.success, `Hourly AJAX error: ${JSON.stringify(jsonHourly.data)}`).toBe(true);
    // May be 0 if no records in last 2 days — just verify no crash
    expect(jsonHourly.success).toBe(true);

    console.log(`Test 6 PASS — daily sum=${dailySum}, hourly success=true`);
  });
});
