/**
 * E2E: DataBuckets — ext-calendar fallback regression (Issue #228 / v5.4.3)
 *
 * Validates that DataBuckets::initSeqWeek() does NOT call jddayofweek(),
 * which requires PHP's optional ext-calendar extension.
 *
 * Background:
 *   Pre-v5.4.3: initSeqWeek() called jddayofweek() unconditionally from within
 *   the SlimStat\Helpers namespace. On servers without ext-calendar (Synology NAS,
 *   minimal PHP installs), this caused a fatal: "Call to undefined function
 *   SlimStat\Helpers\jddayofweek()" → chart AJAX → HTTP 500 → admin down.
 *   Source: Support #14684
 *
 * Fix (v5.4.3 / PR #229):
 *   Replaced jddayofweek() with self::DAY_NAMES[], a pure-PHP constant
 *   requiring no extensions.
 *
 * Test strategy:
 *   The calendar-ext-simulator mu-plugin defines SlimStat\Helpers\jddayofweek()
 *   to throw a RuntimeException. PHP resolves unqualified jddayofweek() calls
 *   inside DataBuckets to this stub BEFORE looking at the global ext-calendar
 *   function — exactly what happens when ext-calendar is absent.
 *
 *   Key sequence per test:
 *     1. Obtain chart nonce via test_create_nonce AJAX (nonce-helper mu-plugin)
 *     2. Install simulator (now active for subsequent PHP requests)
 *     3. Call chart AJAX with WEEK granularity
 *        → If old code: jddayofweek() is called → stub throws → HTTP 500
 *        → If fixed code: DAY_NAMES used → stub never called → HTTP 200
 *     4. Uninstall simulator (cleanup)
 *
 * Tests:
 *   1. Chart AJAX with WEEK granularity returns HTTP 200 — no fatal from jddayofweek()
 *   2. Chart data sum matches DB count — DAY_NAMES produces correct week boundaries
 *   3. start_of_week = 0 (Sunday) — DAY_NAMES[0] resolves correctly
 *   4. Corrupted start_of_week option — ?? 'Monday' fallback prevents crash
 */
import { test, expect } from '@playwright/test';
import {
  clearStatsTable,
  getPool,
  closeDb,
  installMuPluginByName,
  uninstallMuPluginByName,
  snapshotOption,
  restoreOption,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const SIMULATOR = 'calendar-ext-simulator-mu-plugin.php';

// ─── DB helpers ───────────────────────────────────────────────────────────────

async function insertWeeklyRows(timestamp: number, count: number, label: string): Promise<void> {
  for (let i = 0; i < count; i++) {
    await getPool().execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'no-cal-ext-e2e')`,
      [timestamp, `/no-cal-ext-${label}-${i}`]
    );
  }
}

async function countTestRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE user_agent = 'no-cal-ext-e2e'"
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

// ─── Chart AJAX helpers ───────────────────────────────────────────────────────

/**
 * Obtain the chart nonce via the nonce-helper AJAX endpoint.
 *
 * The nonce-helper mu-plugin exposes `test_create_nonce` which calls
 * wp_create_nonce() server-side for any nonce action. This is more reliable
 * than scraping slimstat_chart_vars from the page, because the page render may
 * fail (e.g., corrupted start_of_week, missing chart module, bad date range).
 *
 * Requires the nonce-helper mu-plugin to be installed (done in beforeAll).
 */
async function extractChartNonce(page: import('@playwright/test').Page): Promise<string> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: 'slimstat_chart_nonce' },
  });
  if (!res.ok()) throw new Error(`test_create_nonce failed: HTTP ${res.status()}`);
  const body = await res.json();
  if (!body?.success || !body?.data?.nonce) {
    throw new Error(`test_create_nonce returned unexpected body: ${JSON.stringify(body)}`);
  }
  return body.data.nonce;
}

async function callChartAjax(
  page: import('@playwright/test').Page,
  nonce: string,
  startTs: number,
  endTs: number,
  granularity: 'weekly' | 'daily' | 'monthly' = 'weekly'
): Promise<{ status: number; body: any }> {
  const args = JSON.stringify({
    start: startTs,
    end: endTs,
    chart_data: { data1: 'COUNT(id)', data2: 'COUNT( DISTINCT ip )' },
  });
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'slimstat_fetch_chart_data', nonce, args, granularity },
  });
  return { status: res.status(), body: res.ok() ? await res.json() : null };
}

function sumV1(json: any): number {
  const v1: number[] = json?.data?.data?.datasets?.v1 ?? [];
  return v1.reduce((a: number, b: number) => a + b, 0);
}

// ─── Time anchors ─────────────────────────────────────────────────────────────

// 3-week window anchored to a known Monday (2026-02-02)
const week1Ts = 1738454400; // 2026-02-02 00:00 UTC (Monday)
const week2Ts = week1Ts + 7 * 86400;  // 2026-02-09
const week3Ts = week1Ts + 14 * 86400; // 2026-02-16
const rangeStart = week1Ts;
const rangeEnd = week1Ts + 21 * 86400; // 2026-02-23

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('DataBuckets — ext-calendar fallback (Issue #228)', () => {
  test.setTimeout(120_000);

  test.beforeAll(() => {
    // nonce-helper provides test_create_nonce AJAX action for reliable nonce generation
    installMuPluginByName('nonce-helper-mu-plugin.php');
  });

  test.afterAll(async () => {
    // Safety: ensure simulator and nonce-helper are removed even if a test crashed
    try { uninstallMuPluginByName(SIMULATOR); } catch {}
    try { uninstallMuPluginByName('nonce-helper-mu-plugin.php'); } catch {}
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
    // Clear chart transients: the chart caches SQL results for past date ranges.
    // Without this, test 2+ see stale cached counts from test 1.
    await getPool().execute(
      "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
    );
    await snapshotOption('start_of_week');
  });

  test.afterEach(async () => {
    // Safety net: remove simulator in case a test left it installed
    try { uninstallMuPluginByName(SIMULATOR); } catch {}
    await restoreOption('start_of_week');
  });

  // ─── Test 1: WEEK granularity returns 200 — no jddayofweek() called ──────

  test('chart AJAX with WEEK granularity returns HTTP 200 when jddayofweek() would throw', async ({ page }) => {
    /**
     * Core regression test. The calendar-ext-simulator mu-plugin makes any
     * call to jddayofweek() inside SlimStat\Helpers throw a RuntimeException.
     * With the v5.4.3 fix, DAY_NAMES is used → jddayofweek() is never called
     * → HTTP 200.
     *
     * Nonce is obtained via test_create_nonce BEFORE installing the simulator.
     * The simulator is then installed before the AJAX call — it is active for
     * that PHP request.
     */
    await insertWeeklyRows(week2Ts, 5, 'w2');

    // Step 1: get nonce (simulator not yet active)
    const nonce = await extractChartNonce(page);

    // Step 2: activate simulator — jddayofweek() now throws if called
    installMuPluginByName(SIMULATOR);
    try {
      const { status, body } = await callChartAjax(page, nonce, rangeStart, rangeEnd, 'weekly');

      expect(
        status,
        `Chart AJAX returned HTTP ${status} — jddayofweek() may have been called. ` +
        'Ensure initSeqWeek() uses DAY_NAMES[] not jddayofweek() (v5.4.3 regression)'
      ).toBe(200);

      expect(body?.success, `AJAX error: ${JSON.stringify(body?.data)}`).toBe(true);

      console.log(`Test 1 PASS — HTTP ${status}, success=${body?.success} (no jddayofweek() fatal)`);
    } finally {
      uninstallMuPluginByName(SIMULATOR);
    }
  });

  // ─── Test 2: Chart sum matches DB count — DAY_NAMES produces correct buckets

  test('chart v1 sum matches DB row count — DAY_NAMES week boundaries are correct', async ({ page }) => {
    /**
     * Verifies that replacing jddayofweek() with DAY_NAMES[] produces the same
     * week-boundary labels. If DAY_NAMES has a wrong value, records fall into
     * the wrong bucket or the phantom bucket (off-by-one), causing sum < DB count.
     */
    // 3 records per week × 3 weeks = 9 total
    await insertWeeklyRows(week1Ts + 86400, 3, 'w1');
    await insertWeeklyRows(week2Ts + 86400, 3, 'w2');
    await insertWeeklyRows(week3Ts + 86400, 3, 'w3');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(9);

    const nonce = await extractChartNonce(page);
    installMuPluginByName(SIMULATOR);
    try {
      const { status, body } = await callChartAjax(page, nonce, rangeStart, rangeEnd, 'weekly');

      expect(status).toBe(200);
      expect(body?.success).toBe(true);

      const chartSum = sumV1(body);
      const labels: string[] = body?.data?.data?.labels ?? [];

      expect(chartSum).toBe(dbCount);
      expect(labels.length).toBeGreaterThanOrEqual(3);

      console.log(`Test 2 PASS — DB=${dbCount}, chart.v1 sum=${chartSum}, labels=${labels.length}`);
    } finally {
      uninstallMuPluginByName(SIMULATOR);
    }
  });

  // ─── Test 3: start_of_week = 0 (Sunday) ──────────────────────────────────

  test('chart works correctly with start_of_week = 0 (Sunday)', async ({ page }) => {
    /**
     * DAY_NAMES[0] = 'Sunday'. Verifies that the Sunday start-of-week path
     * in initSeqWeek() produces valid labels without calling jddayofweek().
     */
    await getPool().execute(
      "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('start_of_week', '0', 'yes') " +
      "ON DUPLICATE KEY UPDATE option_value = '0'"
    );

    await insertWeeklyRows(week2Ts, 4, 'sunday-start');

    const nonce = await extractChartNonce(page);
    installMuPluginByName(SIMULATOR);
    try {
      const { status, body } = await callChartAjax(page, nonce, rangeStart, rangeEnd, 'weekly');

      expect(status).toBe(200);
      expect(body?.success).toBe(true);

      const chartSum = sumV1(body);
      const labels: string[] = body?.data?.data?.labels ?? [];

      expect(labels.length).toBeGreaterThanOrEqual(1);
      expect(chartSum).toBeGreaterThan(0);

      console.log(`Test 3 PASS — start_of_week=0 (Sunday), chart.v1 sum=${chartSum}, labels=${labels.length}`);
    } finally {
      uninstallMuPluginByName(SIMULATOR);
    }
  });

  // ─── Test 4: Corrupted start_of_week falls back safely ───────────────────

  test('corrupted start_of_week option falls back to Monday — no fatal', async ({ page }) => {
    /**
     * Defensive fallback added in v5.4.3 PR #229:
     *   self::DAY_NAMES[$startOfWeek] ?? 'Monday'
     * If start_of_week holds an invalid value (corrupted option), the ?? 'Monday'
     * fallback keeps the chart working rather than crashing.
     */
    await getPool().execute(
      "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('start_of_week', '99', 'yes') " +
      "ON DUPLICATE KEY UPDATE option_value = '99'"
    );

    await insertWeeklyRows(week2Ts, 3, 'corrupted-sow');

    const nonce = await extractChartNonce(page);
    installMuPluginByName(SIMULATOR);
    try {
      const { status, body } = await callChartAjax(page, nonce, rangeStart, rangeEnd, 'weekly');

      expect(
        status,
        'Chart crashed on corrupted start_of_week — ?? Monday fallback may be missing'
      ).toBe(200);
      expect(body?.success).toBe(true);

      const labels: string[] = body?.data?.data?.labels ?? [];
      expect(labels.length).toBeGreaterThanOrEqual(1);

      console.log(`Test 4 PASS — corrupted start_of_week=99, fell back safely, labels=${labels.length}`);
    } finally {
      uninstallMuPluginByName(SIMULATOR);
    }
  });
});
