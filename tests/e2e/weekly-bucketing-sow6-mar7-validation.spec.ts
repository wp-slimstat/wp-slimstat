/**
 * E2E: Weekly bucketing sow=6 (Saturday) — Mar 7-14 bucket validation
 *
 * Validates the exact scenario from screenshots:
 *   - Page: slimview2, Last 28 Days, Weekly, sow=6
 *   - "Mar 7 - Mar 14" bucket: 302 (old/wrong) vs 159 (fix/correct)
 *   - Previous period tooltip: 0 (old/wrong) vs 17 (fix/correct)
 *
 * With sow=6 (Saturday), week boundaries are Sat-Fri:
 *   - "Mar 7" bucket = Sat Mar 7 – Fri Mar 13
 *   - "Mar 14" bucket = Sat Mar 14 – Tue Mar 17 (partial)
 *
 * The old code used ISO date('W') (Monday-start) which shifts all
 * buckets by 1 position when sow=6, causing Mar 14-17 data to land
 * in the "Mar 7" bucket and inflating its count.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { getPool, closeDb, snapshotOption, restoreOption } from './helpers/setup';
import { WP_ROOT } from './helpers/env';

// ─── WP-CLI chart AJAX simulation ───────────────────────────────────────────

function fetchChartData(startTs: number, endTs: number, granularity: string): any {
  const tmpFile = path.join('/tmp', `slimstat-sow6-${Date.now()}.php`);
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

// ─── DB helpers ───────────────────────────────────────────────────────────────

async function insertRows(timestamp: number, count: number, label: string): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, CONCAT('10.0.0.', FLOOR(RAND()*254)+1), ?, 'test', '0', 'test', 'en', 1, 'sow6-validation-e2e')`,
      [timestamp + i * 60, `/sow6-validation-${label}-${i}`]
    );
  }
}

async function clearTestData(): Promise<void> {
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

function utcMidnight(dateStr: string): number {
  return Math.floor(new Date(dateStr + 'T00:00:00Z').getTime() / 1000);
}

function getV1(json: any): number[] {
  return json?.data?.data?.datasets?.v1 ?? [];
}

function getV1Prev(json: any): number[] {
  return json?.data?.data?.datasets_prev?.v1 ?? [];
}

function getLabels(json: any): string[] {
  return json?.data?.data?.labels ?? [];
}

function getPrevLabels(json: any): string[] {
  return json?.data?.data?.prev_labels ?? [];
}

function sumArr(arr: number[]): number {
  return arr.reduce((a, b) => a + b, 0);
}

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('Weekly bucketing sow=6: Mar 7-14 bucket validation', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '6' WHERE option_name = 'start_of_week'");
  });

  test.afterAll(async () => {
    await restoreOption('start_of_week');
    await clearTestData();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  /**
   * Test 1: Mar 7 bucket must contain ONLY Sat Mar 7 – Fri Mar 13 data
   *
   * Seeds specific counts per day and verifies exact bucket contents.
   * With sow=6, labels should be: [Feb 18, Feb 21, Feb 28, Mar 7, Mar 14]
   */
  test('Mar 7 bucket contains only Sat Mar 7 – Fri Mar 13 data', async () => {
    // Seed data: specific counts per day in the critical range
    // Mar 7 (Sat) = 10, Mar 9 (Mon) = 20, Mar 13 (Fri) = 15  → total in Mar 7 bucket = 45
    // Mar 14 (Sat) = 30, Mar 16 (Mon) = 25, Mar 17 (Tue) = 35 → total in Mar 14 bucket = 90
    await insertRows(utcMidnight('2026-02-18'), 5, 'feb18');   // bucket 0 (partial)
    await insertRows(utcMidnight('2026-02-25'), 8, 'feb25');   // bucket 1
    await insertRows(utcMidnight('2026-03-03'), 12, 'mar03');  // bucket 2
    await insertRows(utcMidnight('2026-03-07'), 10, 'mar07');  // bucket 3 (Sat - week start)
    await insertRows(utcMidnight('2026-03-09'), 20, 'mar09');  // bucket 3 (Mon)
    await insertRows(utcMidnight('2026-03-13'), 15, 'mar13');  // bucket 3 (Fri - last day of week)
    await insertRows(utcMidnight('2026-03-14'), 30, 'mar14');  // bucket 4 (Sat - new week)
    await insertRows(utcMidnight('2026-03-16'), 25, 'mar16');  // bucket 4 (Mon)
    await insertRows(utcMidnight('2026-03-17'), 35, 'mar17');  // bucket 4 (Tue)

    const totalExpected = 5 + 8 + 12 + 10 + 20 + 15 + 30 + 25 + 35; // = 160

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);

    console.log('=== Test 1: Mar 7 bucket isolation ===');
    console.log('Labels:', labels);
    console.log('V1:', v1);
    console.log('Sum:', sumArr(v1), 'Expected:', totalExpected);

    // Total must match
    expect(sumArr(v1), 'Total sum must match DB').toBe(totalExpected);

    // Expected buckets with sow=6:
    // [0] Feb 18 (Wed-Fri, partial): 5
    // [1] Feb 21 (Sat-Fri): 8
    // [2] Feb 28 (Sat-Fri): 12
    // [3] Mar 7 (Sat-Fri): 10+20+15 = 45
    // [4] Mar 14 (Sat-Tue): 30+25+35 = 90

    expect(v1.length, 'Should have 5 weekly buckets').toBe(5);

    // CRITICAL: Mar 7 bucket must be exactly 45 (not inflated by Mar 14-17 data)
    expect(v1[3], 'Mar 7 bucket: must be 45 (Mar 7=10 + Mar 9=20 + Mar 13=15)').toBe(45);

    // CRITICAL: Mar 14 bucket must be exactly 90 (not 0 or deflated)
    expect(v1[4], 'Mar 14 bucket: must be 90 (Mar 14=30 + Mar 16=25 + Mar 17=35)').toBe(90);

    // Other buckets
    expect(v1[0], 'Feb 18 bucket').toBe(5);
    expect(v1[1], 'Feb 21 bucket').toBe(8);
    expect(v1[2], 'Feb 28 bucket').toBe(12);

    console.log('PASS: Mar 7 bucket = 45, Mar 14 bucket = 90 (correctly separated)');
  });

  /**
   * Test 2: Previous period data is correctly mapped (not zero)
   *
   * Seeds data in both current and previous periods.
   * With "Last 28 Days" (Feb 18 - Mar 17), previous period = Jan 21 - Feb 17.
   */
  test('previous period for Mar 7-14 range is populated (not zero)', async () => {
    // Current period: seed in Mar 7-13 range
    await insertRows(utcMidnight('2026-03-07'), 10, 'curr-mar07');
    await insertRows(utcMidnight('2026-03-10'), 15, 'curr-mar10');

    // Previous period: seed in the equivalent range (~28 days earlier)
    // Feb 7-13 maps to the same bucket position as Mar 7-13
    await insertRows(utcMidnight('2026-02-07'), 8, 'prev-feb07');
    await insertRows(utcMidnight('2026-02-10'), 12, 'prev-feb10');

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const v1Prev = getV1Prev(json);
    const labels = getLabels(json);
    const prevLabels = getPrevLabels(json);

    console.log('=== Test 2: Previous period validation ===');
    console.log('Labels:', labels);
    console.log('Prev labels:', prevLabels);
    console.log('V1 current:', v1, 'sum:', sumArr(v1));
    console.log('V1 previous:', v1Prev, 'sum:', sumArr(v1Prev));

    // Current period: Mar 7+10 data should be in bucket 3 (Mar 7 week)
    const currentSum = sumArr(v1);
    expect(currentSum, 'Current period sum').toBe(25);

    // Previous period must not be all zeros
    const prevSum = sumArr(v1Prev);
    expect(prevSum, 'Previous period must have data (not zero)').toBeGreaterThan(0);
    expect(prevSum, 'Previous period sum').toBe(20);

    console.log('PASS: Previous period correctly populated, sum =', prevSum);
  });

  /**
   * Test 3: Cross-granularity — daily and weekly totals must match
   */
  test('daily vs weekly totals match with sow=6', async () => {
    // Seed across full range
    const dates = [
      '2026-02-18', '2026-02-22', '2026-02-28', '2026-03-03',
      '2026-03-07', '2026-03-09', '2026-03-13', '2026-03-14',
      '2026-03-16', '2026-03-17',
    ];
    for (const d of dates) {
      await insertRows(utcMidnight(d), 5, d.replace(/-/g, ''));
    }
    const totalExpected = dates.length * 5; // 50

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
    const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');

    const dailySum = sumArr(getV1(dailyJson));
    const weeklySum = sumArr(getV1(weeklyJson));

    console.log('=== Test 3: Cross-granularity (sow=6) ===');
    console.log('Daily sum:', dailySum, 'Weekly sum:', weeklySum, 'DB:', totalExpected);

    expect(dailySum, 'Daily total').toBe(totalExpected);
    expect(weeklySum, 'Weekly total').toBe(totalExpected);
    expect(dailySum, 'Daily == Weekly').toBe(weeklySum);

    console.log('PASS: daily =', dailySum, '== weekly =', weeklySum);
  });

  /**
   * Test 4: Boundary precision — Mar 13 (Fri) and Mar 14 (Sat) in different buckets
   *
   * Mar 13 is the LAST day of the "Mar 7" week (Fri).
   * Mar 14 is the FIRST day of the "Mar 14" week (Sat).
   * They must never be in the same bucket.
   */
  test('Mar 13 (Fri) in Mar 7 bucket, Mar 14 (Sat) in Mar 14 bucket — boundary split', async () => {
    // Only seed on the boundary days
    await insertRows(utcMidnight('2026-03-13'), 100, 'fri-mar13');
    await insertRows(utcMidnight('2026-03-14'), 200, 'sat-mar14');

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);

    console.log('=== Test 4: Boundary split ===');
    console.log('Labels:', labels);
    console.log('V1:', v1);

    // Mar 7 bucket (index 3): must contain ONLY Mar 13 = 100
    expect(v1[3], 'Mar 7 bucket must be exactly 100 (Mar 13 only)').toBe(100);

    // Mar 14 bucket (index 4): must contain ONLY Mar 14 = 200
    expect(v1[4], 'Mar 14 bucket must be exactly 200 (Mar 14 only)').toBe(200);

    // They must not be mixed
    expect(v1[3] + v1[4], 'Combined must be 300').toBe(300);

    console.log('PASS: Boundary split correct — Mar 13=100 in bucket 3, Mar 14=200 in bucket 4');
  });
});
