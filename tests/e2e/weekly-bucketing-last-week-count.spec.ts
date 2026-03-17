/**
 * E2E: Weekly bucketing — last week hit count is wrong
 *
 * Bug report config:
 *   Page: slimview2
 *   Date Picker: Last 28 Days
 *   Report: slim_p1_01 (Pageviews)
 *   Type: Weekly
 *   Last Point: March 14 - March 17
 *
 * Hypothesis: DataBuckets::addRow() uses ISO week numbers (date('W'),
 * always Monday-start) to compute bucket offsets, but the SQL grouping
 * and label generation respect WordPress `start_of_week`. When the
 * range start is mid-week, offset calculations can misplace data.
 *
 * This test seeds known hit counts per day, fetches weekly chart data
 * via WP-CLI (simulating the AJAX handler), and verifies each bucket
 * contains exactly the expected count.
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { getPool, closeDb, snapshotOption, restoreOption } from './helpers/setup';
import { WP_ROOT } from './helpers/env';

// ─── WP-CLI chart AJAX simulation ───────────────────────────────────────────

function fetchChartData(startTs: number, endTs: number, granularity: string): any {
  const tmpFile = path.join('/tmp', `slimstat-weekly-buck-${Date.now()}.php`);
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

/** Insert `count` rows at a specific UTC timestamp, each with a distinct resource */
async function insertRows(timestamp: number, count: number, label: string): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, 'weekly-buck-e2e')`,
      [timestamp + i, `/weekly-buck-${label}-${i}`]
    );
  }
}

async function clearTestData(): Promise<void> {
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

function getV1(json: any): number[] {
  return json?.data?.data?.datasets?.v1 ?? [];
}

function getLabels(json: any): string[] {
  return json?.data?.data?.labels ?? [];
}

function sumV1(json: any): number {
  const d = json?.data?.data?.datasets?.v1;
  if (Array.isArray(d)) return d.reduce((a: number, b: number) => a + b, 0);
  return 0;
}

// ─── Date helpers ─────────────────────────────────────────────────────────────

/** Get UTC midnight timestamp for a specific date string (YYYY-MM-DD) */
function utcMidnight(dateStr: string): number {
  return Math.floor(new Date(dateStr + 'T00:00:00Z').getTime() / 1000);
}

/** Get day of week name for a timestamp */
function dayName(ts: number): string {
  return new Date(ts * 1000).toUTCString().split(',')[0];
}

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('Weekly bucketing: last week hit count (March 14-17 bug)', () => {
  test.setTimeout(90_000);

  test.afterAll(async () => {
    await clearTestData();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  /**
   * Test 1: Exact reproduction of reported scenario
   *
   * Seeds known data across the last 28 days ending March 17 2026,
   * fetches weekly chart, and validates each bucket count.
   *
   * With start_of_week=1 (Monday), expected buckets:
   *   Bucket 0: Feb 18 (Wed) - Feb 22 (Sun) = partial first week
   *   Bucket 1: Feb 23 (Mon) - Mar 1 (Sun)
   *   Bucket 2: Mar 2 (Mon) - Mar 8 (Sun)
   *   Bucket 3: Mar 9 (Mon) - Mar 15 (Sun) ← should contain Mar 14 data
   *   Bucket 4: Mar 16 (Mon) - Mar 17 (Tue) ← should contain only Mar 16-17 data
   */
  test('March 14 data lands in Mar 9 bucket, NOT in Mar 16 bucket (sow=1)', async () => {
    // Seed: 3 hits on Mar 14 (Saturday), 2 hits on Mar 15 (Sunday),
    //        5 hits on Mar 16 (Monday), 4 hits on Mar 17 (Tuesday)
    const mar14 = utcMidnight('2026-03-14'); // Saturday
    const mar15 = utcMidnight('2026-03-15'); // Sunday
    const mar16 = utcMidnight('2026-03-16'); // Monday
    const mar17 = utcMidnight('2026-03-17'); // Tuesday (today)

    // Also seed some older data for context
    const feb18 = utcMidnight('2026-02-18'); // Wednesday (range start)
    const mar02 = utcMidnight('2026-03-02'); // Monday
    const mar09 = utcMidnight('2026-03-09'); // Monday

    await insertRows(feb18, 2, 'feb18');
    await insertRows(mar02, 3, 'mar02');
    await insertRows(mar09, 1, 'mar09');
    await insertRows(mar14, 3, 'mar14');
    await insertRows(mar15, 2, 'mar15');
    await insertRows(mar16, 5, 'mar16');
    await insertRows(mar17, 4, 'mar17');

    const totalExpected = 2 + 3 + 1 + 3 + 2 + 5 + 4; // = 20

    // Range: "Last 28 Days" ending Mar 17
    // DateRangeHelper: now - P27D to now (inclusive)
    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399; // end of day

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);
    const chartSum = sumV1(json);

    console.log('Labels:', labels);
    console.log('V1 buckets:', v1);
    console.log(`Chart sum: ${chartSum}, Expected: ${totalExpected}`);

    // ASSERT 1: Total sum must match DB
    expect(chartSum, 'Chart sum must match total seeded rows').toBe(totalExpected);

    // ASSERT 2: Last bucket (Mar 16) should have exactly Mar 16 + Mar 17 = 9
    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, `Last bucket should be 9 (Mar 16=5 + Mar 17=4), got ${lastBucket}`).toBe(9);

    // ASSERT 3: Second-to-last bucket (Mar 9) should have Mar 9 + Mar 14 + Mar 15 = 6
    const mar9Bucket = v1[v1.length - 2];
    expect(mar9Bucket, `Mar 9 bucket should be 6 (Mar 9=1 + Mar 14=3 + Mar 15=2), got ${mar9Bucket}`).toBe(6);

    // ASSERT 4: Labels and data arrays same length
    expect(v1.length).toBe(labels.length);

    console.log('Test 1 PASS — Mar 14 correctly in Mar 9 bucket, Mar 16-17 in last bucket');
  });

  /**
   * Test 2: Same scenario with start_of_week=0 (Sunday)
   *
   * With Sunday start, March 14 (Saturday) should be in the
   * Mar 8 (Sun) bucket, and March 15 (Sunday) starts a new week.
   */
  test('start_of_week=0 (Sunday): Mar 14 in Mar 8 bucket, Mar 15-17 in Mar 15 bucket', async () => {
    // Snapshot and change start_of_week
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '0' WHERE option_name = 'start_of_week'");

    try {
      const mar14 = utcMidnight('2026-03-14'); // Saturday
      const mar15 = utcMidnight('2026-03-15'); // Sunday → new week with sow=0
      const mar16 = utcMidnight('2026-03-16'); // Monday
      const mar17 = utcMidnight('2026-03-17'); // Tuesday

      const feb18 = utcMidnight('2026-02-18');
      const mar01 = utcMidnight('2026-03-01'); // Sunday

      await insertRows(feb18, 2, 'feb18');
      await insertRows(mar01, 3, 'mar01');
      await insertRows(mar14, 3, 'mar14');
      await insertRows(mar15, 2, 'mar15');
      await insertRows(mar16, 5, 'mar16');
      await insertRows(mar17, 4, 'mar17');

      const totalExpected = 2 + 3 + 3 + 2 + 5 + 4; // = 19

      const rangeStart = utcMidnight('2026-02-18');
      const rangeEnd = utcMidnight('2026-03-17') + 86399;

      const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
      expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

      const v1 = getV1(json);
      const labels = getLabels(json);
      const chartSum = sumV1(json);

      console.log('Labels (sow=0):', labels);
      console.log('V1 buckets (sow=0):', v1);
      console.log(`Chart sum: ${chartSum}, Expected: ${totalExpected}`);

      // Total must match
      expect(chartSum, 'Chart sum must match total seeded rows').toBe(totalExpected);

      // Last bucket should contain Mar 15 (Sun) + Mar 16 (Mon) + Mar 17 (Tue) = 11
      const lastBucket = v1[v1.length - 1];
      expect(lastBucket, `Last bucket should be 11 (Mar 15=2 + Mar 16=5 + Mar 17=4), got ${lastBucket}`).toBe(11);

      // Second-to-last should contain Mar 14 (Sat) = part of Mar 8 (Sun) week
      // Plus any other data in that week
      const secondToLast = v1[v1.length - 2];
      expect(secondToLast, `Second-to-last bucket should include Mar 14=3`).toBeGreaterThanOrEqual(3);

      console.log('Test 2 PASS — Sunday start: Mar 14 separate from Mar 15-17');
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test 3: Cross-granularity consistency
   *
   * Same data must produce identical totals whether viewed as daily or weekly.
   * This catches the case where weekly bucketing drops or duplicates records.
   */
  test('daily vs weekly sum must be identical for same date range', async () => {
    // Seed data across the full 28-day range
    const dates = [
      '2026-02-18', '2026-02-21', '2026-02-25', '2026-03-01',
      '2026-03-05', '2026-03-09', '2026-03-12', '2026-03-14',
      '2026-03-15', '2026-03-16', '2026-03-17',
    ];

    for (const d of dates) {
      await insertRows(utcMidnight(d), 3, d.replace(/-/g, ''));
    }

    const totalExpected = dates.length * 3; // 33

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
    const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');

    expect(dailyJson?.success).toBe(true);
    expect(weeklyJson?.success).toBe(true);

    const dailySum = sumV1(dailyJson);
    const weeklySum = sumV1(weeklyJson);

    console.log(`Daily sum: ${dailySum}, Weekly sum: ${weeklySum}, DB total: ${totalExpected}`);

    expect(dailySum, 'Daily sum must match DB count').toBe(totalExpected);
    expect(weeklySum, 'Weekly sum must match DB count').toBe(totalExpected);
    expect(dailySum, 'Daily and weekly sums must match').toBe(weeklySum);

    console.log('Test 3 PASS — cross-granularity consistency confirmed');
  });

  /**
   * Test 4: start_of_week=6 (Saturday) — edge case
   *
   * With Saturday start, March 14 (Saturday) starts a new week.
   * Mar 14 (Sat) + Mar 15 (Sun) + Mar 16 (Mon) + Mar 17 (Tue) = one bucket.
   */
  test('start_of_week=6 (Saturday): Mar 14 starts a new week', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '6' WHERE option_name = 'start_of_week'");

    try {
      const mar13 = utcMidnight('2026-03-13'); // Friday → end of prev week (sow=6)
      const mar14 = utcMidnight('2026-03-14'); // Saturday → new week start
      const mar17 = utcMidnight('2026-03-17'); // Tuesday

      const feb18 = utcMidnight('2026-02-18');

      await insertRows(feb18, 2, 'feb18');
      await insertRows(mar13, 4, 'mar13');
      await insertRows(mar14, 3, 'mar14');
      await insertRows(mar17, 5, 'mar17');

      const totalExpected = 2 + 4 + 3 + 5; // = 14

      const rangeStart = utcMidnight('2026-02-18');
      const rangeEnd = utcMidnight('2026-03-17') + 86399;

      const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
      expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

      const v1 = getV1(json);
      const labels = getLabels(json);
      const chartSum = sumV1(json);

      console.log('Labels (sow=6):', labels);
      console.log('V1 buckets (sow=6):', v1);
      console.log(`Chart sum: ${chartSum}, Expected: ${totalExpected}`);

      expect(chartSum, 'Chart sum must match total seeded rows').toBe(totalExpected);

      // Last bucket: Mar 14 (Sat) starts new week, should contain Mar 14 + Mar 17 = 8
      const lastBucket = v1[v1.length - 1];
      expect(lastBucket, `Last bucket should be 8 (Mar 14=3 + Mar 17=5), got ${lastBucket}`).toBe(8);

      // Mar 13 (Fri) should NOT be in the same bucket as Mar 14
      const secondToLast = v1[v1.length - 2];
      expect(secondToLast, `Second-to-last should include Mar 13=4`).toBeGreaterThanOrEqual(4);

      console.log('Test 4 PASS — Saturday start: Mar 14 correctly starts new week');
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test 5: Verify no data loss at bucket boundaries
   *
   * Seeds exactly 1 row per day for the full 28-day range.
   * Verifies that weekly sum = 28 (no records dropped).
   */
  test('28 days × 1 hit/day: weekly chart must show exactly 28 total', async () => {
    const baseDate = new Date('2026-02-18T12:00:00Z');
    for (let i = 0; i < 28; i++) {
      const d = new Date(baseDate.getTime() + i * 86400_000);
      const dateStr = d.toISOString().split('T')[0];
      await insertRows(utcMidnight(dateStr), 1, `day${i}`);
    }

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const chartSum = sumV1(json);

    console.log('V1 buckets (1/day):', v1);
    console.log(`Chart sum: ${chartSum}, Expected: 28`);

    expect(chartSum, 'Must not lose any records in weekly bucketing').toBe(28);

    // Each bucket should have reasonable count (no negatives, no zeros except maybe partial first)
    for (let i = 0; i < v1.length; i++) {
      expect(v1[i], `Bucket ${i} must be non-negative`).toBeGreaterThanOrEqual(0);
    }

    // Sum of all buckets must equal total
    const manualSum = v1.reduce((a, b) => a + b, 0);
    expect(manualSum).toBe(28);

    console.log('Test 5 PASS — no data loss at weekly bucket boundaries');
  });
});
