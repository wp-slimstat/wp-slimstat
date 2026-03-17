/**
 * E2E: Chart "today shows zero" regression — Support ticket reproduction
 *
 * Reproduces the exact scenario reported by user acekin26:
 *   - Daily chart for "Last 28 Days" shows 0 pageviews for today
 *   - Weekly chart shows 0 for current partial week
 *
 * Root cause: DataBuckets::addRow() bounds check `$offset <= $this->points`
 * allowed records at the exact range boundary to land in a phantom bucket
 * at index == points (no label), making them invisible.
 *
 * Fix: `$offset >= 0 && $offset < $this->points` (PR #232)
 *
 * Uses WP-CLI to simulate Chart AJAX handler server-side.
 *
 * Source: wp.org support ticket "Wrong stats" by acekin26
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { getPool, closeDb } from './helpers/setup';
import { WP_ROOT } from './helpers/env';

// ─── WP-CLI chart AJAX simulation ───────────────────────────────────────────

function fetchChartData(startTs: number, endTs: number, granularity: string): any {
  const tmpFile = path.join('/tmp', `slimstat-chart-${Date.now()}.php`);
  // Simulate the AJAX handler with wp_set_current_user + nonce
  const phpCode = `<?php
wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);

$_POST['args'] = json_encode([
    'start' => ${startTs},
    'end' => ${endTs},
    'chart_data' => [
        'data1' => 'COUNT(id)',
        'data2' => 'COUNT( DISTINCT ip )',
    ],
]);
$_POST['granularity'] = '${granularity}';
$_REQUEST['granularity'] = '${granularity}';
$_POST['nonce'] = wp_create_nonce('slimstat_chart_nonce');
$_REQUEST['_ajax_nonce'] = $_POST['nonce'];

// Clear transients to avoid stale cache
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'");

ob_start();
try {
    \\SlimStat\\Modules\\Chart::ajaxFetchChartData();
} catch (\\Throwable $e) {
    ob_end_clean();
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
$output = ob_get_clean();
echo $output;
`;

  fs.writeFileSync(tmpFile, phpCode);

  // Extract JSON from WP-CLI output (may contain PHP deprecation warnings mixed in)
  function extractJson(raw: string): any {
    // Find the JSON object — starts with {"success": and ends at the last }
    const start = raw.indexOf('{"success"');
    if (start === -1) return null;
    try {
      return JSON.parse(raw.substring(start));
    } catch {
      // Try to find balanced braces
      let depth = 0;
      for (let i = start; i < raw.length; i++) {
        if (raw[i] === '{') depth++;
        if (raw[i] === '}') depth--;
        if (depth === 0) {
          try { return JSON.parse(raw.substring(start, i + 1)); } catch { return null; }
        }
      }
    }
    return null;
  }

  try {
    const raw = execSync(`wp eval-file "${tmpFile}" --path="${WP_ROOT}" 2>/dev/null`, {
      encoding: 'utf8',
      timeout: 30_000,
    });
    const parsed = extractJson(raw);
    if (parsed) return parsed;
    throw new Error(`No JSON in output: ${raw.substring(0, 300)}`);
  } catch (e: any) {
    if (e.stdout) {
      const parsed = extractJson(e.stdout);
      if (parsed) return parsed;
    }
    throw new Error(`WP-CLI chart call failed: ${e.message}`);
  } finally {
    try { fs.unlinkSync(tmpFile); } catch {}
  }
}

// ─── DB helpers ───────────────────────────────────────────────────────────────

async function insertRows(timestamp: number, count: number, label: string): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'chart-today-zero-e2e')`,
      [timestamp + i, `/chart-today-zero-${label}-${i}`]
    );
  }
}

async function countTestRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE user_agent = 'chart-today-zero-e2e'"
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

async function clearTestData(): Promise<void> {
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

// ─── Data extractors (response structure: data.data.datasets.v1) ─────────────

function sumV1(json: any): number {
  const d = json?.data?.data?.datasets?.v1;
  if (Array.isArray(d)) return d.reduce((a: number, b: number) => a + b, 0);
  return 0;
}

function getV1(json: any): number[] {
  return json?.data?.data?.datasets?.v1 ?? [];
}

function getLabels(json: any): string[] {
  return json?.data?.data?.labels ?? [];
}

function getV1Prev(json: any): number[] {
  return json?.data?.data?.datasets_prev?.v1 ?? [];
}

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('Chart "today shows zero" regression (Support ticket: acekin26)', () => {
  test.setTimeout(60_000);

  const now = Math.floor(Date.now() / 1000);
  const day = 86400;
  const rangeStart = now - 28 * day;
  const rangeEnd = now;

  const todayTs = now - 60;
  const yesterdayTs = now - day;
  const lastWeekTs = now - 7 * day;
  const twoWeeksAgoTs = now - 14 * day;
  const prevMidTs = (rangeStart - 28 * day) + 14 * day;

  test.afterAll(async () => {
    await clearTestData();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  // ─── Test 1: Daily — today's records visible ──────────────────────────────

  test('daily chart: records inserted for TODAY are visible (not zero)', async () => {
    await insertRows(todayTs, 5, 'today');
    await insertRows(yesterdayTs, 10, 'yesterday');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(15);

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(dbCount);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Today bucket must not be zero').toBeGreaterThanOrEqual(5);

    expect(v1.length).toBe(labels.length);

    console.log(`Test 1 PASS — daily: DB=${dbCount}, sum=${chartSum}, today=${lastBucket}, labels=${labels.length}`);
  });

  // ─── Test 2: Weekly — current partial week visible ────────────────────────

  test('weekly chart: current partial week records visible (not zero)', async () => {
    await insertRows(todayTs, 3, 'weekly-today');
    await insertRows(lastWeekTs, 7, 'weekly-lastweek');
    await insertRows(twoWeeksAgoTs, 4, 'weekly-2wago');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(14);

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(dbCount);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must not be zero').toBeGreaterThanOrEqual(3);

    expect(v1.length).toBeLessThanOrEqual(labels.length);

    console.log(`Test 2 PASS — weekly: DB=${dbCount}, sum=${chartSum}, last week=${lastBucket}, labels=${labels.length}`);
  });

  // ─── Test 3: 28-day range accuracy ────────────────────────────────────────

  test('28-day daily chart: sum of all buckets equals DB record count', async () => {
    // Use todayTs (now-60) for day 0 to avoid exact boundary edge case
    const intervals = [0, 3, 7, 10, 14, 18, 21, 25, 27];
    for (const daysAgo of intervals) {
      const ts = daysAgo === 0 ? todayTs : now - daysAgo * day;
      await insertRows(ts, 2, `day-${daysAgo}`);
    }

    const dbCount = await countTestRows();
    expect(dbCount).toBe(18);

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(dbCount);
    expect(v1.length).toBe(labels.length);
    expect(labels.length).toBeGreaterThanOrEqual(28);

    console.log(`Test 3 PASS — 28d daily: DB=${dbCount}, sum=${chartSum}, labels=${labels.length}`);
  });

  // ─── Test 4: Previous period comparison ───────────────────────────────────

  test('daily chart: previous period comparison (datasets_prev) is populated', async () => {
    await insertRows(todayTs, 3, 'curr-today');
    await insertRows(lastWeekTs, 5, 'curr-lastweek');
    await insertRows(prevMidTs, 4, 'prev-mid');

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1Current = getV1(json);
    const v1Prev = getV1Prev(json);

    const currentSum = v1Current.reduce((a: number, b: number) => a + b, 0);
    expect(currentSum).toBe(8);

    const prevSum = v1Prev.reduce((a: number, b: number) => a + b, 0);
    expect(prevSum).toBe(4);

    expect(v1Current.length).toBe(v1Prev.length);

    console.log(`Test 4 PASS — prev period: current=${currentSum}, prev=${prevSum}`);
  });

  // ─── Test 5: Cross-granularity consistency ────────────────────────────────

  test('same data produces consistent totals across daily and weekly granularities', async () => {
    await insertRows(todayTs, 3, 'multi-today');
    await insertRows(lastWeekTs, 5, 'multi-lastweek');
    await insertRows(twoWeeksAgoTs, 4, 'multi-2wago');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(12);

    const dailySum = sumV1(fetchChartData(rangeStart, rangeEnd, 'daily'));
    const weeklySum = sumV1(fetchChartData(rangeStart, rangeEnd, 'weekly'));

    expect(dailySum).toBe(dbCount);
    expect(weeklySum).toBe(dbCount);
    expect(dailySum).toBe(weeklySum);

    console.log(`Test 5 PASS — consistency: DB=${dbCount}, daily=${dailySum}, weekly=${weeklySum}`);
  });
});
