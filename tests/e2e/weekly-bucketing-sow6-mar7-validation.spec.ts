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
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  getV1, getV1Prev, getLabels, getPrevLabels, sumArr, utcMidnight,
} from './helpers/chart';

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
  });

  test.beforeEach(async () => {
    await clearTestData();
  });

  /**
   * Test 1: Mar 7 bucket must contain ONLY Sat Mar 7 – Fri Mar 13 data
   */
  test('Mar 7 bucket contains only Sat Mar 7 – Fri Mar 13 data', async () => {
    await insertRows(utcMidnight('2026-02-18'), 5, 'feb18');
    await insertRows(utcMidnight('2026-02-25'), 8, 'feb25');
    await insertRows(utcMidnight('2026-03-03'), 12, 'mar03');
    await insertRows(utcMidnight('2026-03-07'), 10, 'mar07');
    await insertRows(utcMidnight('2026-03-09'), 20, 'mar09');
    await insertRows(utcMidnight('2026-03-13'), 15, 'mar13');
    await insertRows(utcMidnight('2026-03-14'), 30, 'mar14');
    await insertRows(utcMidnight('2026-03-16'), 25, 'mar16');
    await insertRows(utcMidnight('2026-03-17'), 35, 'mar17');

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

    expect(sumArr(v1), 'Total sum must match DB').toBe(totalExpected);

    expect(v1.length, 'Should have 5 weekly buckets').toBe(5);

    expect(v1[3], 'Mar 7 bucket: must be 45 (Mar 7=10 + Mar 9=20 + Mar 13=15)').toBe(45);
    expect(v1[4], 'Mar 14 bucket: must be 90 (Mar 14=30 + Mar 16=25 + Mar 17=35)').toBe(90);

    expect(v1[0], 'Feb 18 bucket').toBe(5);
    expect(v1[1], 'Feb 21 bucket').toBe(8);
    expect(v1[2], 'Feb 28 bucket').toBe(12);

    console.log('PASS: Mar 7 bucket = 45, Mar 14 bucket = 90 (correctly separated)');
  });

  /**
   * Test 2: Previous period data is correctly mapped (not zero)
   */
  test('previous period for Mar 7-14 range is populated (not zero)', async () => {
    await insertRows(utcMidnight('2026-03-07'), 10, 'curr-mar07');
    await insertRows(utcMidnight('2026-03-10'), 15, 'curr-mar10');

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

    const currentSum = sumArr(v1);
    expect(currentSum, 'Current period sum').toBe(25);

    const prevSum = sumArr(v1Prev);
    expect(prevSum, 'Previous period must have data (not zero)').toBeGreaterThan(0);
    expect(prevSum, 'Previous period sum').toBe(20);

    console.log('PASS: Previous period correctly populated, sum =', prevSum);
  });

  /**
   * Test 3: Cross-granularity — daily and weekly totals must match
   */
  test('daily vs weekly totals match with sow=6', async () => {
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
   */
  test('Mar 13 (Fri) in Mar 7 bucket, Mar 14 (Sat) in Mar 14 bucket — boundary split', async () => {
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

    expect(v1[3], 'Mar 7 bucket must be exactly 100 (Mar 13 only)').toBe(100);
    expect(v1[4], 'Mar 14 bucket must be exactly 200 (Mar 14 only)').toBe(200);
    expect(v1[3] + v1[4], 'Combined must be 300').toBe(300);

    console.log('PASS: Boundary split correct — Mar 13=100 in bucket 3, Mar 14=200 in bucket 4');
  });
});
