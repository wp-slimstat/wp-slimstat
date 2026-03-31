/**
 * E2E: DataBuckets chart accuracy — default and custom DB simulation
 *
 * Validates the fix for off-by-one bounds check in DataBuckets::addRow().
 * The bug: `$offset <= $this->points` allowed records to be stored in a
 * phantom bucket (index == points) with no corresponding label, causing
 * those records to be silently dropped from chart display.
 * The fix: `$offset >= 0 && $offset < $this->points`
 *
 * Also simulates the slimstat_custom_wpdb filter (used by the external DB addon)
 * to confirm that Query.php's use of global $wpdb creates no read/write split.
 *
 * How the chart works:
 *  - Chart data is server-side rendered into `data-data` HTML attribute on
 *    `[id^="slimstat_chart_data_"]` element (readable without AJAX)
 *  - `slimstat_fetch_chart_data` AJAX fires only when user changes granularity
 *  - Both paths go through DataBuckets::addRow() — same fix applies to both
 *
 * Tests:
 *  1. Direct AJAX call returns v1 sum matching DB count (default DB)
 *  2. Server-rendered chart `data-data` v1 sum >= DB count (default DB)
 *  3. Out-of-range records are NOT counted in chart
 *  4. Empty range — zero records, no crash, sum = 0
 *  5. Custom DB filter active — Query.php still reads from default DB (no split)
 *  6. Phantom bucket regression — last-week records land in valid bucket
 *
 * Source: Support #14684 / GitHub #231
 */
import { test, expect } from '@playwright/test';
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
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'databuckets-e2e-test')`,
      [timestamp, `/databuckets-e2e-${label}-${i}`]
    );
  }
}

async function countTestRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE user_agent = 'databuckets-e2e-test'"
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

// ─── Chart AJAX helper ─────────────────────────────────────────────────────────

/**
 * Navigate to slimview2 and extract the chart nonce.
 *
 * Must be called BEFORE any state that could break slimview2 rendering —
 * specifically before activating the custom DB simulator, which causes
 * wp_slimstat_db to query a non-existent slimext_slim_stats table.
 */
async function extractChartNonce(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
    waitUntil: 'domcontentloaded',
  });
  const nonce = await page.evaluate(() => (window as any).slimstat_chart_vars?.nonce || null);
  if (!nonce) throw new Error('slimstat_chart_vars.nonce not found — chart JS not loaded on slimview2');
  return nonce;
}

/**
 * Call the slimstat_fetch_chart_data AJAX endpoint with a pre-fetched nonce.
 * Use extractChartNonce() first, especially when page state may break slimview2.
 */
async function callChartAjaxWithNonce(
  page: import('@playwright/test').Page,
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

/**
 * Convenience: extract nonce from slimview2 then call chart AJAX.
 * Only use when slimview2 is guaranteed to render correctly (no custom DB active).
 */
async function callChartAjax(
  page: import('@playwright/test').Page,
  startTs: number,
  endTs: number,
  granularity: 'weekly' | 'daily' | 'monthly' | 'hourly' | 'yearly' = 'weekly'
): Promise<any> {
  const nonce = await extractChartNonce(page);
  return callChartAjaxWithNonce(page, nonce, startTs, endTs, granularity);
}

/**
 * Read the server-rendered chart data from the `data-data` HTML attribute.
 * This is what the page shows on first load (without any AJAX interaction).
 */
async function readServerRenderedChart(page: import('@playwright/test').Page): Promise<any> {
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

/**
 * Sum all v1 values in chart datasets.
 * Handles both AJAX response structure (json.data.data.datasets.v1)
 * and server-rendered structure (json.datasets.v1).
 */
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

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('DataBuckets chart accuracy — default and custom DB (Support #14684)', () => {
  test.setTimeout(180_000);

  // 3-week range ending now
  const now = Math.floor(Date.now() / 1000);
  const week = 7 * 24 * 3600;
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
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    await restoreOption('slimstat_test_use_custom_db');
  });

  // ─── Test 1: Direct AJAX — sum matches DB count ──────────────────────────────

  test('direct AJAX: weekly chart v1 sum equals inserted record count (default DB)', async ({ page }) => {
    // Insert 9 records across 3 weeks: 3 + 4 + 2
    await insertRows(week1Ts, 3, 'ajax-w1');
    await insertRows(week2Ts, 4, 'ajax-w2');
    await insertRows(week3Ts, 2, 'ajax-w3');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(9);

    const json = await callChartAjax(page, rangeStart, rangeEnd, 'weekly');

    // AJAX must succeed — nonce validated, no PHP error
    expect(json.success, `AJAX error: ${JSON.stringify(json.data)}`).toBe(true);

    // Chart v1 (pageviews) sum must equal DB count — no records silently lost
    const chartSum = sumV1(json);
    expect(chartSum).toBe(dbCount);

    // Correct number of labels: one per ISO week in range (3 weeks)
    const labels: string[] = json?.data?.data?.labels ?? [];
    expect(labels.length).toBeGreaterThanOrEqual(3);

    console.log(`Test 1 PASS — DB=${dbCount}, chart.v1 sum=${chartSum}, labels=${labels.length}`);
  });

  // ─── Test 2: Server-rendered — sum matches DB count ─────────────────────────

  test('server-rendered chart data-data v1 sum >= inserted record count (default DB)', async ({ page }) => {
    await insertRows(week1Ts, 3, 'sr-w1');
    await insertRows(week2Ts, 4, 'sr-w2');
    await insertRows(week3Ts, 2, 'sr-w3');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(9);

    // Navigate to slimview2 — chart reports (slim_p1_01) are rendered server-side here
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    // Use 'attached' not 'visible' — chart elements may be inside collapsed postboxes
    await page.waitForSelector('[id^="slimstat_chart_data_"]', { state: 'attached', timeout: 10_000 });

    const chartData = await readServerRenderedChart(page);
    expect(chartData, 'data-data attribute missing or empty').not.toBeNull();

    // The page has a default date range (usually last 30 days); our records are within it
    const chartSum = sumV1(chartData);
    // chartSum >= 9: our 9 records should be in the default date range
    // (may be > 9 if there is other existing data in the DB for this range)
    expect(chartSum).toBeGreaterThanOrEqual(dbCount);

    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('Undefined offset');

    console.log(`Test 2 PASS — DB=${dbCount}, server-rendered sum=${chartSum}`);
  });

  // ─── Test 3: Out-of-range records are NOT counted ────────────────────────────

  test('records with dt outside the AJAX range are excluded from chart datasets', async ({ page }) => {
    // Insert 5 records INSIDE the 3-week range
    await insertRows(week2Ts, 5, 'in-range');

    // Insert 3 records OUTSIDE the range (7 days before rangeStart)
    const beforeRangeTs = rangeStart - week;
    await insertRows(beforeRangeTs, 3, 'out-of-range');

    const totalDb = await countTestRows();
    expect(totalDb).toBe(8); // 5 in + 3 out

    const json = await callChartAjax(page, rangeStart, rangeEnd, 'weekly');
    expect(json.success).toBe(true);

    const chartSum = sumV1(json);
    // Chart should only count the 5 in-range records — out-of-range are filtered by DB query
    expect(chartSum).toBe(5);

    console.log(`Test 3 PASS — total DB=${totalDb}, in-range=5, chart.v1 sum=${chartSum}`);
  });

  // ─── Test 4: Empty range — zero records, no crash ────────────────────────────

  test('chart AJAX returns success with v1 sum = 0 when no records exist', async ({ page }) => {
    // DB is cleared in beforeEach — no records at all
    const dbCount = await countTestRows();
    expect(dbCount).toBe(0);

    const json = await callChartAjax(page, rangeStart, rangeEnd, 'weekly');

    // Must not crash — DataBuckets must handle empty results gracefully
    expect(json.success, `Expected success, got: ${JSON.stringify(json.data)}`).toBe(true);

    const chartSum = sumV1(json);
    expect(chartSum).toBe(0);

    // Labels must still be generated (buckets exist even when empty)
    const labels: string[] = json?.data?.data?.labels ?? [];
    expect(labels.length).toBeGreaterThanOrEqual(3); // 3 weeks → ≥3 labels

    console.log(`Test 4 PASS — empty range, chart.v1 sum=${chartSum}, labels=${labels.length}`);
  });

  // ─── Test 5: Custom DB filter active — chart AJAX remains stable ──────────────

  test('chart AJAX succeeds and reads from wp_slim_stats when slimstat_custom_wpdb filter is active', async ({ page }) => {
    /**
     * Regression test for CustomDB prefix bug: when the slimstat_custom_wpdb filter
     * returns a custom wpdb instance, the chart must still query the correctly-prefixed
     * table (wp_slim_stats) — not a broken unprefixed table name.
     *
     * The fix has two layers:
     *   1. CustomDBAddon calls set_prefix() on the new wpdb (primary fix, in Pro)
     *   2. Chart.php uses $GLOBALS['wpdb']->prefix for table names (defense-in-depth)
     *
     * The simulator now mirrors the fixed behavior: set_prefix($GLOBALS['wpdb']->prefix)
     * so the custom wpdb has the correct prefix. Chart.php also uses global prefix
     * as defense-in-depth.
     *
     * This test verifies that:
     *   1. The AJAX handler returns HTTP 200 (no fatal error)
     *   2. Chart data accurately reflects wp_slim_stats contents (data is NOT lost)
     *   3. The off-by-one fix (DataBuckets.php:209) works correctly under this filter
     */

    // Extract nonce BEFORE activating the filter (safe to do either way, but consistent)
    const nonce = await extractChartNonce(page);

    // Insert 5 records into wp_slim_stats (the table Chart.php reads from)
    await insertRows(week2Ts, 5, 'custom-db-default');

    // Activate the custom DB simulator — wp_slimstat::$wpdb now uses a cloned wpdb
    // with set_prefix() called (same prefix as global, simulating fixed CustomDB addon)
    await getPool().execute(
      "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_test_use_custom_db', 'yes', 'yes') ON DUPLICATE KEY UPDATE option_value = 'yes'"
    );

    // Chart AJAX must succeed — the custom DB filter must NOT crash the handler
    const json = await callChartAjaxWithNonce(page, nonce, rangeStart, rangeEnd, 'weekly');

    expect(json.success, `AJAX error: ${JSON.stringify(json.data)}`).toBe(true);

    // Chart reads from wp_slim_stats — our 5 records must be visible
    const chartSum = sumV1(json);
    expect(chartSum).toBe(5);

    const labels: string[] = json?.data?.data?.labels ?? [];
    expect(labels.length).toBeGreaterThanOrEqual(3);

    console.log(`Test 5 PASS — custom DB filter active, chart reads correct table: sum=${chartSum}, labels=${labels.length}`);
  });

  // ─── Test 6: Phantom bucket regression — boundary records counted correctly ──

  test('DataBuckets off-by-one regression: records at last-week boundary land in valid bucket', async ({ page }) => {
    /**
     * Regression test for DataBuckets.php:209 off-by-one:
     *
     * OLD: `$offset <= $this->points`  → allowed $offset == points → phantom bucket
     * NEW: `$offset >= 0 && $offset < $this->points` → only [0, points-1] valid
     *
     * Scenario: insert records across ALL 3 weeks including specifically in week 3
     * (the last valid bucket). With the old code, a calculation error could cause
     * some last-week records to be placed in an out-of-bounds index.
     *
     * Assertion: sum(datasets.v1) == count of records inserted within the range.
     * If any records silently fall into a phantom bucket, the sum will be less
     * than the DB count — and this test will catch it.
     */

    // Insert 2 records near the end of the range (last week, close to rangeEnd)
    // These are the records most likely to trigger the off-by-one
    const lastWeekTs = rangeEnd - 3600; // 1 hour before end
    const midLastWeekTs = rangeEnd - Math.floor(week * 0.25); // 1.75 days before end
    await insertRows(lastWeekTs, 2, 'phantom-end');
    await insertRows(midLastWeekTs, 3, 'phantom-mid-last');

    // Also insert in weeks 1 and 2 to verify those buckets are fine
    await insertRows(week1Ts, 1, 'phantom-w1');
    await insertRows(week2Ts, 1, 'phantom-w2');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(7); // 2 + 3 + 1 + 1

    const json = await callChartAjax(page, rangeStart, rangeEnd, 'weekly');
    expect(json.success, `AJAX error: ${JSON.stringify(json.data)}`).toBe(true);

    const chartSum = sumV1(json);

    // Critical assertion: ALL valid records must appear in chart.
    // If the off-by-one bug is present, records near the range boundary
    // would land in datasets[points] (phantom, no label) and be invisible.
    expect(chartSum).toBe(dbCount);

    // Verify no extra phantom datasets beyond label count
    const labels: string[] = json?.data?.data?.labels ?? [];
    const v1Array: number[] = json?.data?.data?.datasets?.v1 ?? [];
    // After the fix, v1 array length should match labels length
    expect(v1Array.length).toBeLessThanOrEqual(labels.length);

    console.log(
      `Test 6 PASS — DB=${dbCount}, chart.v1 sum=${chartSum}, labels=${labels.length}, v1 array length=${v1Array.length}`
    );
  });

  // ─── Test 7: Monthly granularity — sum matches DB count ──────────────────────

  test('chart AJAX with MONTHLY granularity: v1 sum equals inserted record count', async ({ page }) => {
    /**
     * Coverage gap for DataBuckets.php:209 fix — MONTH granularity uses a
     * different offset formula ($diff->y * 12 + $diff->m) from WEEK.
     * Records before the range start calculate to offset -1 and are rejected
     * by the >= 0 check (not shifted, just dropped — shiftDatasets() is dead).
     *
     * This test verifies that records within a 2-month range all land in valid
     * buckets and the chart sum equals the DB count.
     */

    // Fixed 2-month range: 2026-02-01 → 2026-03-31 UTC (past, cache-eligible range)
    const monthStart = 1738368000; // 2026-02-01 00:00 UTC
    const monthEnd   = 1743379199; // 2026-03-31 23:59 UTC
    const feb15Ts    = 1739577600; // 2026-02-15 UTC (mid Feb)
    const mar10Ts    = 1741564800; // 2026-03-10 UTC (mid Mar)

    await insertRows(feb15Ts, 4, 'month-feb');
    await insertRows(mar10Ts, 3, 'month-mar');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(7);

    // Clear transients — past ranges are cached; stale cache from other tests would fail this
    await getPool().execute(
      "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
    );

    const json = await callChartAjax(page, monthStart, monthEnd, 'monthly');
    expect(json.success, `AJAX error: ${JSON.stringify(json.data)}`).toBe(true);

    const chartSum = sumV1(json);

    // All 7 records are within the 2-month range; none should fall to offset -1
    expect(chartSum).toBe(dbCount);

    const labels: string[] = json?.data?.data?.labels ?? [];
    expect(labels.length).toBeGreaterThanOrEqual(2); // Feb + Mar

    // v1 array length must not exceed label count (no phantom bucket beyond bounds)
    const v1Array: number[] = json?.data?.data?.datasets?.v1 ?? [];
    expect(v1Array.length).toBeLessThanOrEqual(labels.length);

    console.log(`Test 7 PASS — monthly: DB=${dbCount}, chart.v1 sum=${chartSum}, labels=${labels.length}`);
  });
});
