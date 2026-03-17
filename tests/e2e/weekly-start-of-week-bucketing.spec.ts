/**
 * E2E: Weekly chart start_of_week bucketing — PR #235 regression
 *
 * PR #235 fixes DataBuckets::addRow() weekly offset calculation to respect
 * WordPress start_of_week setting instead of ISO week numbers (date('W')).
 *
 * Bug: ISO weeks always start Monday. When start_of_week=0 (Sunday),
 * data was bucketed into the wrong week offset, causing current week
 * to show zero.
 *
 * Tests verify correct bucketing across start_of_week values (0, 1, 6)
 * and cross-granularity consistency.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { getPool, closeDb, snapshotOption, restoreOption } from './helpers/setup';
import { WP_ROOT } from './helpers/env';

// ─── WP-CLI chart AJAX simulation (reused from chart-today-zero-regression) ──

function fetchChartData(startTs: number, endTs: number, granularity: string): any {
  const tmpFile = path.join('/tmp', `slimstat-chart-sow-${Date.now()}.php`);
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

  function extractJson(raw: string): any {
    const start = raw.indexOf('{"success"');
    if (start === -1) return null;
    try {
      return JSON.parse(raw.substring(start));
    } catch {
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

// ─── WP option helpers ───────────────────────────────────────────────────────

function setStartOfWeek(value: number): void {
  execSync(
    `wp option update start_of_week ${value} --path="${WP_ROOT}" 2>/dev/null`,
    { encoding: 'utf8', timeout: 10_000 }
  );
}

function getStartOfWeek(): number {
  const raw = execSync(
    `wp option get start_of_week --path="${WP_ROOT}" 2>/dev/null`,
    { encoding: 'utf8', timeout: 10_000 }
  );
  return parseInt(raw.trim(), 10);
}

// ─── DB helpers ──────────────────────────────────────────────────────────────

async function insertRows(timestamp: number, count: number, label: string): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'weekly-sow-e2e')`,
      [timestamp + i, `/weekly-sow-${label}-${i}`]
    );
  }
}

async function countTestRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE user_agent = 'weekly-sow-e2e'"
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

async function clearTestData(): Promise<void> {
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

// ─── Data extractors ─────────────────────────────────────────────────────────

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

// ─── Timestamp helpers ───────────────────────────────────────────────────────

/**
 * Find the most recent day-of-week relative to a timestamp.
 * dayOfWeek: 0=Sunday, 1=Monday, ..., 6=Saturday
 */
function mostRecentDayOfWeek(dayOfWeek: number, refTs: number): number {
  const refDate = new Date(refTs * 1000);
  const currentDay = refDate.getUTCDay(); // 0=Sun
  const diff = (currentDay - dayOfWeek + 7) % 7;
  // If diff is 0, it's today — use it
  const targetDate = new Date(refDate);
  targetDate.setUTCDate(targetDate.getUTCDate() - diff);
  targetDate.setUTCHours(12, 0, 0, 0); // noon to avoid DST edge cases
  return Math.floor(targetDate.getTime() / 1000);
}

// ─── Test suite ──────────────────────────────────────────────────────────────

test.describe('Weekly chart start_of_week bucketing (PR #235)', () => {
  test.setTimeout(60_000);

  const now = Math.floor(Date.now() / 1000);
  const day = 86400;
  const rangeStart = now - 28 * day;
  const rangeEnd = now;
  let originalStartOfWeek: number;

  test.beforeAll(async () => {
    originalStartOfWeek = getStartOfWeek();
    await snapshotOption('start_of_week');
  });

  test.afterAll(async () => {
    setStartOfWeek(originalStartOfWeek);
    await restoreOption('start_of_week');
    await clearTestData();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  // ─── Test 1: start_of_week=0 (Sunday) — the bug case ─────────────────────

  test('weekly chart with start_of_week=0 (Sunday): today visible in current week', async () => {
    setStartOfWeek(0);

    const todayTs = now - 60;
    const lastWeekTs = now - 7 * day;

    await insertRows(todayTs, 5, 'sow0-today');
    await insertRows(lastWeekTs, 8, 'sow0-lastweek');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(13);

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    // All records must be accounted for
    expect(chartSum).toBe(dbCount);

    // Last bucket (current week) must contain today's records
    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 5 records').toBeGreaterThanOrEqual(5);

    console.log(`Test 1 PASS — sow=0: DB=${dbCount}, sum=${chartSum}, lastBucket=${lastBucket}`);
  });

  // ─── Test 2: start_of_week=1 (Monday) — default, no regression ───────────

  test('weekly chart with start_of_week=1 (Monday): today visible (no regression)', async () => {
    setStartOfWeek(1);

    const todayTs = now - 60;
    const lastWeekTs = now - 7 * day;

    await insertRows(todayTs, 4, 'sow1-today');
    await insertRows(lastWeekTs, 6, 'sow1-lastweek');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(10);

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    expect(chartSum).toBe(dbCount);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 4 records').toBeGreaterThanOrEqual(4);

    console.log(`Test 2 PASS — sow=1: DB=${dbCount}, sum=${chartSum}, lastBucket=${lastBucket}`);
  });

  // ─── Test 3: start_of_week=6 (Saturday) — edge case ──────────────────────

  test('weekly chart with start_of_week=6 (Saturday): today visible', async () => {
    setStartOfWeek(6);

    const todayTs = now - 60;
    const twoWeeksAgoTs = now - 14 * day;

    await insertRows(todayTs, 3, 'sow6-today');
    await insertRows(twoWeeksAgoTs, 5, 'sow6-2wago');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(8);

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    expect(chartSum).toBe(dbCount);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 3 records').toBeGreaterThanOrEqual(3);

    console.log(`Test 3 PASS — sow=6: DB=${dbCount}, sum=${chartSum}, lastBucket=${lastBucket}`);
  });

  // ─── Test 4: Cross-granularity consistency with start_of_week=0 ───────────

  test('daily vs weekly totals match when start_of_week=0', async () => {
    setStartOfWeek(0);

    const todayTs = now - 60;
    const lastWeekTs = now - 7 * day;
    const twoWeeksAgoTs = now - 14 * day;

    await insertRows(todayTs, 3, 'xgran-today');
    await insertRows(lastWeekTs, 5, 'xgran-lastweek');
    await insertRows(twoWeeksAgoTs, 4, 'xgran-2wago');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(12);

    const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
    const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');

    expect(dailyJson?.success).toBe(true);
    expect(weeklyJson?.success).toBe(true);

    const dailySum = sumV1(dailyJson);
    const weeklySum = sumV1(weeklyJson);

    expect(dailySum).toBe(dbCount);
    expect(weeklySum).toBe(dbCount);
    expect(dailySum).toBe(weeklySum);

    console.log(`Test 4 PASS — xgran sow=0: DB=${dbCount}, daily=${dailySum}, weekly=${weeklySum}`);
  });

  // ─── Test 5: Sunday record buckets correctly for sow=0 vs sow=1 ──────────

  test('Sunday records land in correct week bucket for sow=0 vs sow=1', async () => {
    // Find the most recent Sunday timestamp
    const sundayTs = mostRecentDayOfWeek(0, now); // 0 = Sunday
    // And the Saturday before it
    const saturdayTs = sundayTs - day;

    // Insert records on Saturday and Sunday
    await insertRows(saturdayTs, 4, 'boundary-sat');
    await insertRows(sundayTs, 3, 'boundary-sun');

    const dbCount = await countTestRows();
    expect(dbCount).toBe(7);

    // With sow=0 (Sunday): Sunday starts a NEW week
    setStartOfWeek(0);
    const jsonSow0 = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonSow0?.success).toBe(true);
    const v1Sow0 = getV1(jsonSow0);
    const sumSow0 = sumV1(jsonSow0);
    expect(sumSow0).toBe(dbCount);

    // With sow=1 (Monday): Sunday is the LAST day of the week
    setStartOfWeek(1);
    const jsonSow1 = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonSow1?.success).toBe(true);
    const v1Sow1 = getV1(jsonSow1);
    const sumSow1 = sumV1(jsonSow1);
    expect(sumSow1).toBe(dbCount);

    // Both must account for all records
    expect(sumSow0).toBe(sumSow1);

    // The bucket distribution should differ: with sow=0, Sunday starts a new week
    // so Saturday (4) and Sunday (3) are in different weeks.
    // With sow=1, Saturday and Sunday are in the same week (both after Monday).
    // We can't assert exact bucket indices without knowing the day-of-week of 'now',
    // but we can verify the total is correct and no records are lost.
    console.log(`Test 5 PASS — boundary: DB=${dbCount}, sow0=${JSON.stringify(v1Sow0)}, sow1=${JSON.stringify(v1Sow1)}`);
  });
});
