/**
 * QA R11: the Overview chart by week.
 *
 * - Each week ended on the next week's first day ("Sep 14 - Sep 21", then "Sep 21 - Sep 28").
 * - The 0.3-tension spline overshot: above the real peak, and below 0 into the partial week.
 * - The week still in progress reads as one, not as a week that collapsed.
 *
 * Read-only: it draws whatever the database holds, so it runs on an empty install too.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('weekly chart: ranges do not overlap, the curve does not overshoot, this week says so', async ({ page }) => {
  // Short enough that the chart starts below the fold, as on an empty install under "Get started".
  await page.setViewportSize({ width: 1280, height: 480 });
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2&type=last_28_days`);
  await page.locator('select.slimstat-granularity-select').first().selectOption('weekly');
  const canvas = page.locator('canvas[id^="slimstat_chart_"]').first();
  await page.waitForFunction(() => {
    const c = document.querySelector<HTMLCanvasElement>('canvas[id^="slimstat_chart_"]');
    const chart = c && typeof Chart !== 'undefined' && Chart.getChart(c);
    return !!chart && chart.scales.x.ticks.some((t: { label: unknown }) => / - /.test(String(t.label)));
  }, null, { timeout: 20_000 });

  const chart = await canvas.evaluate((c: HTMLCanvasElement) => {
    const ch = Chart.getChart(c)!;
    const last = ch.data.labels!.length - 1;
    const point = ch.getDatasetMeta(0).data[last];
    return {
      ticks: ch.scales.x.ticks.map((t: { value: number; label: unknown }) => ({ i: t.value, label: String(t.label) })),
      curves: ch.data.datasets.filter((d: { tension?: number }) => d.tension).map((d: { cubicInterpolationMode?: string }) => d.cubicInterpolationMode),
      lastPoint: { x: point.x, y: point.y },
    };
  });

  for (let k = 1; k < chart.ticks.length; k++) {
    const [prev, next] = [chart.ticks[k - 1], chart.ticks[k]];
    if (next.i !== prev.i + 1) continue;
    expect(prev.label.split(' - ').pop(), `"${prev.label}" ends where "${next.label}" starts`).not.toBe(next.label.split(' - ')[0]);
  }
  expect(chart.curves.length, 'the line datasets are curved').toBeGreaterThan(0);
  expect(chart.curves.every((mode) => 'monotone' === mode), 'a monotone curve never overshoots its points').toBe(true);

  // The range ends today, so its last week is still in progress.
  // A mouse move outside the viewport reaches no element, so bring the chart in first.
  await canvas.scrollIntoViewIfNeeded();
  const box = (await canvas.boundingBox())!;
  await page.mouse.move(box.x + chart.lastPoint.x, box.y + chart.lastPoint.y);
  await expect(page.locator('th').filter({ hasText: /\(Now\)$/ })).toBeVisible({ timeout: 5_000 });
});
