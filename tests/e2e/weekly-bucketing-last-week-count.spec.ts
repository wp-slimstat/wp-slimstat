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
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  getV1, getLabels, sumV1, sumArr, utcMidnight,
} from './helpers/chart';

// ─── Test suite ───────────────────────────────────────────────────────────────

test.describe('Weekly bucketing: last week hit count (March 14-17 bug)', () => {
  test.setTimeout(90_000);

  test.afterAll(async () => {
    await clearTestData();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  /**
   * Test 1: Exact reproduction of reported scenario
   */
  test('March 14 data lands in Mar 9 bucket, NOT in Mar 16 bucket (sow=1)', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");

    const mar14 = utcMidnight('2026-03-14');
    const mar15 = utcMidnight('2026-03-15');
    const mar16 = utcMidnight('2026-03-16');
    const mar17 = utcMidnight('2026-03-17');
    const feb18 = utcMidnight('2026-02-18');
    const mar02 = utcMidnight('2026-03-02');
    const mar09 = utcMidnight('2026-03-09');

    await insertRows(feb18, 2, 'feb18');
    await insertRows(mar02, 3, 'mar02');
    await insertRows(mar09, 1, 'mar09');
    await insertRows(mar14, 3, 'mar14');
    await insertRows(mar15, 2, 'mar15');
    await insertRows(mar16, 5, 'mar16');
    await insertRows(mar17, 4, 'mar17');

    const totalExpected = 2 + 3 + 1 + 3 + 2 + 5 + 4; // = 20

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);
    const chartSum = sumV1(json);

    console.log('Labels:', labels);
    console.log('V1 buckets:', v1);
    console.log(`Chart sum: ${chartSum}, Expected: ${totalExpected}`);

    expect(chartSum, 'Chart sum must match total seeded rows').toBe(totalExpected);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, `Last bucket should be 9 (Mar 16=5 + Mar 17=4), got ${lastBucket}`).toBe(9);

    const mar9Bucket = v1[v1.length - 2];
    expect(mar9Bucket, `Mar 9 bucket should be 6 (Mar 9=1 + Mar 14=3 + Mar 15=2), got ${mar9Bucket}`).toBe(6);

    expect(v1.length).toBe(labels.length);

    console.log('Test 1 PASS — Mar 14 correctly in Mar 9 bucket, Mar 16-17 in last bucket');

    await restoreOption('start_of_week');
  });

  /**
   * Test 2: Same scenario with start_of_week=0 (Sunday)
   */
  test('start_of_week=0 (Sunday): Mar 14 in Mar 8 bucket, Mar 15-17 in Mar 15 bucket', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '0' WHERE option_name = 'start_of_week'");

    try {
      const mar14 = utcMidnight('2026-03-14');
      const mar15 = utcMidnight('2026-03-15');
      const mar16 = utcMidnight('2026-03-16');
      const mar17 = utcMidnight('2026-03-17');
      const feb18 = utcMidnight('2026-02-18');
      const mar01 = utcMidnight('2026-03-01');

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

      expect(chartSum, 'Chart sum must match total seeded rows').toBe(totalExpected);

      const lastBucket = v1[v1.length - 1];
      expect(lastBucket, `Last bucket should be 11 (Mar 15=2 + Mar 16=5 + Mar 17=4), got ${lastBucket}`).toBe(11);

      const secondToLast = v1[v1.length - 2];
      expect(secondToLast, `Second-to-last bucket should include Mar 14=3`).toBeGreaterThanOrEqual(3);

      console.log('Test 2 PASS — Sunday start: Mar 14 separate from Mar 15-17');
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test 3: Cross-granularity consistency
   */
  test('daily vs weekly sum must be identical for same date range', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");

    try {
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
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test 4: start_of_week=6 (Saturday) — edge case
   */
  test('start_of_week=6 (Saturday): Mar 14 starts a new week', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '6' WHERE option_name = 'start_of_week'");

    try {
      const mar13 = utcMidnight('2026-03-13');
      const mar14 = utcMidnight('2026-03-14');
      const mar17 = utcMidnight('2026-03-17');
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

      const lastBucket = v1[v1.length - 1];
      expect(lastBucket, `Last bucket should be 8 (Mar 14=3 + Mar 17=5), got ${lastBucket}`).toBe(8);

      const secondToLast = v1[v1.length - 2];
      expect(secondToLast, `Second-to-last should include Mar 13=4`).toBeGreaterThanOrEqual(4);

      console.log('Test 4 PASS — Saturday start: Mar 14 correctly starts new week');
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test 5: Verify no data loss at bucket boundaries
   */
  test('28 days × 1 hit/day: weekly chart must show exactly 28 total', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '1' WHERE option_name = 'start_of_week'");

    try {
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

      for (let i = 0; i < v1.length; i++) {
        expect(v1[i], `Bucket ${i} must be non-negative`).toBeGreaterThanOrEqual(0);
      }

      const manualSum = v1.reduce((a, b) => a + b, 0);
      expect(manualSum).toBe(28);

      console.log('Test 5 PASS — no data loss at weekly bucket boundaries');
    } finally {
      await restoreOption('start_of_week');
    }
  });
});
