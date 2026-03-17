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
import { getPool, snapshotOption, restoreOption } from './helpers/setup';
import {
  fetchChartData, insertRows, clearTestData,
  sumV1, getV1, getLabels, getV1Prev,
} from './helpers/chart';

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

  // ─── Test 1: Daily — today's records visible ──────────────────────────────

  test('daily chart: records inserted for TODAY are visible (not zero)', async () => {
    await insertRows(todayTs, 5, 'today');
    await insertRows(yesterdayTs, 10, 'yesterday');

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success, `Chart error: ${JSON.stringify(json)}`).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(15);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Today bucket must not be zero').toBeGreaterThanOrEqual(5);

    expect(v1.length).toBe(labels.length);

    console.log(`Test 1 PASS — daily: sum=${chartSum}, today=${lastBucket}, labels=${labels.length}`);
  });

  // ─── Test 2: Weekly — current partial week visible ────────────────────────

  test('weekly chart: current partial week records visible (not zero)', async () => {
    await insertRows(todayTs, 3, 'weekly-today');
    await insertRows(lastWeekTs, 7, 'weekly-lastweek');
    await insertRows(twoWeeksAgoTs, 4, 'weekly-2wago');

    const json = fetchChartData(rangeStart, rangeEnd, 'weekly');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(14);

    const lastBucket = v1[v1.length - 1];
    expect(lastBucket, 'Current week bucket must not be zero').toBeGreaterThanOrEqual(3);

    expect(v1.length).toBeLessThanOrEqual(labels.length);

    console.log(`Test 2 PASS — weekly: sum=${chartSum}, last week=${lastBucket}, labels=${labels.length}`);
  });

  // ─── Test 3: 28-day range accuracy ────────────────────────────────────────

  test('28-day daily chart: sum of all buckets equals DB record count', async () => {
    const intervals = [0, 3, 7, 10, 14, 18, 21, 25, 27];
    for (const daysAgo of intervals) {
      const ts = daysAgo === 0 ? todayTs : now - daysAgo * day;
      await insertRows(ts, 2, `day-${daysAgo}`);
    }

    const json = fetchChartData(rangeStart, rangeEnd, 'daily');
    expect(json?.success).toBe(true);

    const chartSum = sumV1(json);
    const v1 = getV1(json);
    const labels = getLabels(json);

    expect(chartSum).toBe(18);
    expect(v1.length).toBe(labels.length);
    expect(labels.length).toBeGreaterThanOrEqual(28);

    console.log(`Test 3 PASS — 28d daily: sum=${chartSum}, labels=${labels.length}`);
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

    const dailySum = sumV1(fetchChartData(rangeStart, rangeEnd, 'daily'));
    const weeklySum = sumV1(fetchChartData(rangeStart, rangeEnd, 'weekly'));

    expect(dailySum).toBe(12);
    expect(weeklySum).toBe(12);
    expect(dailySum).toBe(weeklySum);

    console.log(`Test 5 PASS — consistency: daily=${dailySum}, weekly=${weeklySum}`);
  });
});
