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
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  sumV1, getV1, getLabels,
  setStartOfWeek, getStartOfWeek, mostRecentDayOfWeek,
} from './helpers/chart';

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

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    expect(chartSum).toBe(13);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 5 records').toBeGreaterThanOrEqual(5);

    console.log(`Test 1 PASS — sow=0: sum=${chartSum}, lastBucket=${lastBucket}`);
  });

  // ─── Test 2: start_of_week=1 (Monday) — default, no regression ───────────

  test('weekly chart with start_of_week=1 (Monday): today visible (no regression)', async () => {
    setStartOfWeek(1);

    const todayTs = now - 60;
    const lastWeekTs = now - 7 * day;

    await insertRows(todayTs, 4, 'sow1-today');
    await insertRows(lastWeekTs, 6, 'sow1-lastweek');

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    expect(chartSum).toBe(10);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 4 records').toBeGreaterThanOrEqual(4);

    console.log(`Test 2 PASS — sow=1: sum=${chartSum}, lastBucket=${lastBucket}`);
  });

  // ─── Test 3: start_of_week=6 (Saturday) — edge case ──────────────────────

  test('weekly chart with start_of_week=6 (Saturday): today visible', async () => {
    setStartOfWeek(6);

    const todayTs = now - 60;
    const twoWeeksAgoTs = now - 14 * day;

    await insertRows(todayTs, 3, 'sow6-today');
    await insertRows(twoWeeksAgoTs, 5, 'sow6-2wago');

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);

    expect(chartSum).toBe(8);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must contain today\'s 3 records').toBeGreaterThanOrEqual(3);

    console.log(`Test 3 PASS — sow=6: sum=${chartSum}, lastBucket=${lastBucket}`);
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

    const dailyJson = fetchChartData(rangeStart, rangeEnd, 'daily');
    const weeklyJson = fetchChartData(rangeStart, rangeEnd, 'weekly');

    expect(dailyJson?.success).toBe(true);
    expect(weeklyJson?.success).toBe(true);

    const dailySum = sumV1(dailyJson);
    const weeklySum = sumV1(weeklyJson);

    expect(dailySum).toBe(12);
    expect(weeklySum).toBe(12);
    expect(dailySum).toBe(weeklySum);

    console.log(`Test 4 PASS — xgran sow=0: daily=${dailySum}, weekly=${weeklySum}`);
  });

  // ─── Test 5: Sunday record buckets correctly for sow=0 vs sow=1 ──────────

  test('Sunday records land in correct week bucket for sow=0 vs sow=1', async () => {
    const sundayTs = mostRecentDayOfWeek(0, now);
    const saturdayTs = sundayTs - day;

    await insertRows(saturdayTs, 4, 'boundary-sat');
    await insertRows(sundayTs, 3, 'boundary-sun');

    // With sow=0 (Sunday): Sunday starts a NEW week
    setStartOfWeek(0);
    const jsonSow0 = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonSow0?.success).toBe(true);
    const v1Sow0 = getV1(jsonSow0);
    const sumSow0 = sumV1(jsonSow0);
    expect(sumSow0).toBe(7);

    // With sow=1 (Monday): Sunday is the LAST day of the week
    setStartOfWeek(1);
    const jsonSow1 = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(jsonSow1?.success).toBe(true);
    const v1Sow1 = getV1(jsonSow1);
    const sumSow1 = sumV1(jsonSow1);
    expect(sumSow1).toBe(7);

    expect(sumSow0).toBe(sumSow1);

    console.log(`Test 5 PASS — boundary: sow0=${JSON.stringify(v1Sow0)}, sow1=${JSON.stringify(v1Sow1)}`);
  });
});
