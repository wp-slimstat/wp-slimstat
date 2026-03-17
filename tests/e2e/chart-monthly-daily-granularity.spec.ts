/**
 * E2E: Monthly and Daily chart granularity validation
 *
 * Validates that monthly and daily chart bucketing produces correct
 * totals and bucket assignments across different start_of_week settings.
 * These granularities should NOT be affected by start_of_week, but
 * we verify no regressions were introduced by the weekly fix.
 *
 * Tests cover:
 *   - Monthly bucketing: correct month boundaries, sum = DB total
 *   - Daily bucketing: correct day boundaries, sum = DB total
 *   - Cross-granularity: daily, weekly, monthly all produce same total
 *   - sow changes don't affect daily/monthly (only weekly)
 *   - Previous period data populated for both granularities
 */
import { test, expect } from '@playwright/test';
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  getV1, getV1Prev, getLabels, sumArr, utcMidnight,
} from './helpers/chart';

// ─── DAILY TESTS ──────────────────────────────────────────────────────────────

test.describe('Daily chart granularity', () => {
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

  /**
   * Test D1: Daily buckets — each day gets its own bucket, sum = DB total
   */
  test('D1: daily buckets match DB total for Last 28 Days', async () => {
    const dates = [
      '2026-02-18', '2026-02-20', '2026-02-25', '2026-03-01',
      '2026-03-05', '2026-03-09', '2026-03-12', '2026-03-14',
      '2026-03-15', '2026-03-16', '2026-03-17',
    ];
    for (const d of dates) {
      await insertRows(utcMidnight(d), 5, d.replace(/-/g, ''));
    }
    const totalExpected = dates.length * 5; // 55

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);
    const chartSum = sumArr(v1);

    console.log('=== D1: Daily buckets ===');
    console.log('Labels count:', labels.length, 'V1 count:', v1.length);
    console.log('Sum:', chartSum, 'Expected:', totalExpected);

    expect(chartSum, 'Daily sum must match DB').toBe(totalExpected);
    expect(v1.length, 'Bucket count = label count').toBe(labels.length);

    const nonZero = v1.filter(v => v > 0);
    expect(nonZero.length, '11 days with data').toBe(11);
    for (const val of nonZero) {
      expect(val, 'Each day with data should have 5').toBe(5);
    }

    console.log('D1 PASS: daily sum =', chartSum, ', non-zero buckets =', nonZero.length);
  });

  /**
   * Test D2: Daily is unaffected by start_of_week changes
   */
  test('D2: daily totals identical across sow=1, sow=0, sow=6', async () => {
    await insertRows(utcMidnight('2026-02-18'), 10, 'feb18');
    await insertRows(utcMidnight('2026-03-07'), 20, 'mar07');
    await insertRows(utcMidnight('2026-03-14'), 15, 'mar14');
    await insertRows(utcMidnight('2026-03-17'), 25, 'mar17');
    const totalExpected = 70;

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    await snapshotOption('start_of_week');

    const results: Record<string, number> = {};

    for (const sow of ['1', '0', '6']) {
      await getPool().execute("UPDATE wp_options SET option_value = ? WHERE option_name = 'start_of_week'", [sow]);
      const json = fetchChartData(rangeStart, rangeEnd, 'daily');
      expect(json?.success).toBe(true);
      results[`sow${sow}`] = sumArr(getV1(json));
    }

    await restoreOption('start_of_week');

    console.log('=== D2: Daily vs sow ===');
    console.log('Results:', results);

    expect(results['sow1'], 'sow=1 daily total').toBe(totalExpected);
    expect(results['sow0'], 'sow=0 daily total').toBe(totalExpected);
    expect(results['sow6'], 'sow=6 daily total').toBe(totalExpected);

    console.log('D2 PASS: daily unaffected by sow changes, all =', totalExpected);
  });

  /**
   * Test D3: Daily previous period is populated
   */
  test('D3: daily previous period data is populated', async () => {
    await insertRows(utcMidnight('2026-03-10'), 8, 'curr');
    await insertRows(utcMidnight('2026-02-10'), 12, 'prev');

    const rangeStart = utcMidnight('2026-02-18');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const v1Prev = getV1Prev(json);

    console.log('=== D3: Daily prev period ===');
    console.log('Current sum:', sumArr(v1), 'Prev sum:', sumArr(v1Prev));

    expect(sumArr(v1), 'Current sum').toBe(8);
    expect(sumArr(v1Prev), 'Previous period must have data').toBe(12);

    console.log('D3 PASS: prev period sum =', sumArr(v1Prev));
  });
});

// ─── MONTHLY TESTS ────────────────────────────────────────────────────────────

test.describe('Monthly chart granularity', () => {
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

  /**
   * Test M1: Monthly buckets — data grouped by calendar month
   */
  test('M1: monthly buckets match DB total for 5-month range', async () => {
    await insertRows(utcMidnight('2025-11-20'), 15, 'nov');
    await insertRows(utcMidnight('2025-12-25'), 20, 'dec');
    await insertRows(utcMidnight('2026-01-10'), 25, 'jan');
    await insertRows(utcMidnight('2026-02-14'), 30, 'feb');
    await insertRows(utcMidnight('2026-03-07'), 35, 'mar');
    const totalExpected = 15 + 20 + 25 + 30 + 35; // 125

    const rangeStart = utcMidnight('2025-10-15');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'monthly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);
    const chartSum = sumArr(v1);

    console.log('=== M1: Monthly buckets ===');
    console.log('Labels:', labels);
    console.log('V1:', v1);
    console.log('Sum:', chartSum, 'Expected:', totalExpected);

    expect(chartSum, 'Monthly sum must match DB').toBe(totalExpected);
    expect(v1.length, 'Bucket count = label count').toBe(labels.length);

    const novIdx = labels.findIndex(l => l.includes('November'));
    const decIdx = labels.findIndex(l => l.includes('December'));
    const janIdx = labels.findIndex(l => l.includes('January'));
    const febIdx = labels.findIndex(l => l.includes('February'));
    const marIdx = labels.findIndex(l => l.includes('March'));

    expect(v1[novIdx], 'November').toBe(15);
    expect(v1[decIdx], 'December').toBe(20);
    expect(v1[janIdx], 'January').toBe(25);
    expect(v1[febIdx], 'February').toBe(30);
    expect(v1[marIdx], 'March').toBe(35);

    console.log('M1 PASS: monthly sum =', chartSum, ', all months correct');
  });

  /**
   * Test M2: Monthly is unaffected by start_of_week changes
   */
  test('M2: monthly totals identical across sow=1, sow=0, sow=6', async () => {
    await insertRows(utcMidnight('2025-11-10'), 10, 'nov');
    await insertRows(utcMidnight('2026-01-15'), 20, 'jan');
    await insertRows(utcMidnight('2026-03-05'), 30, 'mar');
    const totalExpected = 60;

    const rangeStart = utcMidnight('2025-10-01');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    await snapshotOption('start_of_week');

    const results: Record<string, number> = {};

    for (const sow of ['1', '0', '6']) {
      await getPool().execute("UPDATE wp_options SET option_value = ? WHERE option_name = 'start_of_week'", [sow]);
      const json = fetchChartData(rangeStart, rangeEnd, 'monthly');
      expect(json?.success).toBe(true);
      results[`sow${sow}`] = sumArr(getV1(json));
    }

    await restoreOption('start_of_week');

    console.log('=== M2: Monthly vs sow ===');
    console.log('Results:', results);

    expect(results['sow1'], 'sow=1 monthly total').toBe(totalExpected);
    expect(results['sow0'], 'sow=0 monthly total').toBe(totalExpected);
    expect(results['sow6'], 'sow=6 monthly total').toBe(totalExpected);

    console.log('M2 PASS: monthly unaffected by sow changes, all =', totalExpected);
  });

  /**
   * Test M3: Monthly previous period is populated
   */
  test('M3: monthly previous period data is populated', async () => {
    await insertRows(utcMidnight('2026-02-15'), 10, 'curr');
    await insertRows(utcMidnight('2025-08-15'), 15, 'prev');

    const rangeStart = utcMidnight('2025-10-01');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'monthly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const v1Prev = getV1Prev(json);

    console.log('=== M3: Monthly prev period ===');
    console.log('Current sum:', sumArr(v1), 'Prev sum:', sumArr(v1Prev));

    expect(sumArr(v1), 'Current sum').toBe(10);
    expect(sumArr(v1Prev), 'Previous period must have data').toBe(15);

    console.log('M3 PASS: prev period sum =', sumArr(v1Prev));
  });

  /**
   * Test M4: Month boundary — last day of Feb vs first day of Mar
   */
  test('M4: Feb 28 and Mar 1 land in different monthly buckets', async () => {
    await insertRows(utcMidnight('2026-02-28'), 100, 'feb28');
    await insertRows(utcMidnight('2026-03-01'), 200, 'mar01');

    const rangeStart = utcMidnight('2025-10-01');
    const rangeEnd = utcMidnight('2026-03-17') + 86399;

    const json = fetchChartData(rangeStart, rangeEnd, 'monthly');
    expect(json?.success).toBe(true);

    const v1 = getV1(json);
    const labels = getLabels(json);

    console.log('=== M4: Month boundary ===');
    console.log('Labels:', labels);
    console.log('V1:', v1);

    const febIdx = labels.findIndex(l => l.includes('February'));
    const marIdx = labels.findIndex(l => l.includes('March'));

    expect(febIdx, 'February label exists').toBeGreaterThanOrEqual(0);
    expect(marIdx, 'March label exists').toBeGreaterThanOrEqual(0);
    expect(v1[febIdx], 'Feb bucket = 100').toBe(100);
    expect(v1[marIdx], 'Mar bucket = 200').toBe(200);

    console.log('M4 PASS: Feb=100, Mar=200 — boundary correct');
  });
});

// ─── CROSS-GRANULARITY ────────────────────────────────────────────────────────

test.describe('Cross-granularity consistency', () => {
  test.setTimeout(90_000);

  test.afterAll(async () => {
    await clearTestData();
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  /**
   * Test X1: All granularities produce same total with sow=6
   */
  test('X1: daily, weekly, monthly all produce same total (sow=6)', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '6' WHERE option_name = 'start_of_week'");

    try {
      const dates = [
        '2025-11-20', '2025-12-25',
        '2026-01-10', '2026-02-14', '2026-02-28',
        '2026-03-07', '2026-03-13', '2026-03-14', '2026-03-17',
      ];
      for (const d of dates) {
        await insertRows(utcMidnight(d), 7, d.replace(/-/g, ''));
      }
      const totalExpected = dates.length * 7; // 63

      const rangeStart = utcMidnight('2025-10-15');
      const rangeEnd = utcMidnight('2026-03-17') + 86399;

      const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
      const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');
      const monthlyJson = fetchChartData(rangeStart, rangeEnd, 'monthly');

      const dailySum = sumArr(getV1(dailyJson));
      const weeklySum = sumArr(getV1(weeklyJson));
      const monthlySum = sumArr(getV1(monthlyJson));

      console.log('=== X1: Cross-granularity (sow=6) ===');
      console.log('Daily:', dailySum, 'Weekly:', weeklySum, 'Monthly:', monthlySum, 'DB:', totalExpected);

      expect(dailySum, 'Daily total').toBe(totalExpected);
      expect(weeklySum, 'Weekly total').toBe(totalExpected);
      expect(monthlySum, 'Monthly total').toBe(totalExpected);

      console.log('X1 PASS: all three granularities =', totalExpected);
    } finally {
      await restoreOption('start_of_week');
    }
  });

  /**
   * Test X2: All granularities produce same total with sow=0
   */
  test('X2: daily, weekly, monthly all produce same total (sow=0)', async () => {
    await snapshotOption('start_of_week');
    await getPool().execute("UPDATE wp_options SET option_value = '0' WHERE option_name = 'start_of_week'");

    try {
      const dates = [
        '2025-11-20', '2025-12-25',
        '2026-01-10', '2026-02-14', '2026-02-28',
        '2026-03-07', '2026-03-13', '2026-03-14', '2026-03-17',
      ];
      for (const d of dates) {
        await insertRows(utcMidnight(d), 7, d.replace(/-/g, ''));
      }
      const totalExpected = dates.length * 7; // 63

      const rangeStart = utcMidnight('2025-10-15');
      const rangeEnd = utcMidnight('2026-03-17') + 86399;

      const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
      const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');
      const monthlyJson = fetchChartData(rangeStart, rangeEnd, 'monthly');

      const dailySum = sumArr(getV1(dailyJson));
      const weeklySum = sumArr(getV1(weeklyJson));
      const monthlySum = sumArr(getV1(monthlyJson));

      console.log('=== X2: Cross-granularity (sow=0) ===');
      console.log('Daily:', dailySum, 'Weekly:', weeklySum, 'Monthly:', monthlySum, 'DB:', totalExpected);

      expect(dailySum, 'Daily total').toBe(totalExpected);
      expect(weeklySum, 'Weekly total').toBe(totalExpected);
      expect(monthlySum, 'Monthly total').toBe(totalExpected);

      console.log('X2 PASS: all three granularities =', totalExpected);
    } finally {
      await restoreOption('start_of_week');
    }
  });
});
