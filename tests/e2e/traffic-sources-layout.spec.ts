/**
 * Traffic Sources layout from the 2026-10-04 screenshot audit: Top Countries and Traffic
 * Summary each sat alone in a row, the chart's last x label touched the card edge, the
 * report guide was a 570px legacy row with a short divider, the UTM builder had a third
 * entry point (a collapsed panel), and Recent Search Terms repeated terms with no time.
 *
 * Read-only: navigation and GET requests only.
 */
import { test, expect, type Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const PAGE = `${BASE_URL}/wp-admin/admin.php?page=slimview5`;
const top = (page: Page, id: string) => page.locator(id).evaluate((e) => Math.round(e.getBoundingClientRect().top));

test.use({ viewport: { width: 1440, height: 900 } });

test('Top Countries and Traffic Summary share a row', async ({ page }) => {
  await page.goto(PAGE);
  expect(await top(page, '#slim_p3_02')).toBe(await top(page, '#slim_p1_13'));
});

test('the chart keeps its last x label clear of the card edge', async ({ page }) => {
  await page.goto(PAGE);
  const gap = () => page.evaluate(() => {
    const canvas = document.querySelector<HTMLCanvasElement>('#slimstat_chart_slim_p3_01');
    const chart = canvas && (window as any).Chart?.getChart(canvas);
    if (!chart) return -1;
    const x = chart.scales.x;
    const last = x.ticks.length - 1;
    chart.ctx.save();
    chart.ctx.font = (window as any).Chart.helpers.toFont(x.options.ticks.font).string;
    const width = chart.ctx.measureText(String(x.ticks[last].label)).width;
    chart.ctx.restore();
    const card = canvas!.closest('.postbox')!.getBoundingClientRect();
    return card.right - (canvas!.getBoundingClientRect().left + x.getPixelForTick(last) + width / 2);
  });
  await expect.poll(gap).toBeGreaterThanOrEqual(12);
});

test('the report guide lines up with the table, without a legacy row divider', async ({ page }) => {
  await page.goto(PAGE);
  const guide = page.locator('#slim_p3_04 .slimstat-acquisition__guide');
  const table = page.locator('#slim_p3_04 .slimstat-acquisition__groups');
  const [style, guideLeft, tableLeft] = await Promise.all([
    guide.evaluate((g) => ({ border: getComputedStyle(g).borderBottomStyle, maxWidth: getComputedStyle(g).maxWidth })),
    guide.evaluate((g) => Math.round(g.getBoundingClientRect().left + parseFloat(getComputedStyle(g).paddingLeft))),
    table.evaluate((t) => Math.round(t.getBoundingClientRect().left)),
  ]);
  expect(style.border).toBe('none');
  expect(guideLeft).toBe(tableLeft);
});

test('the UTM builder opens only from a report link, and closing it returns focus there', async ({ page }) => {
  await page.goto(PAGE);
  const builder = page.locator('#slimstat-utm-builder');
  const link = page.locator('#slim_p3_03 .slimstat-utm-builder-link');
  await expect(builder).toBeHidden();
  await link.click();
  await expect(builder).toBeVisible();
  await expect(page.locator('[name="website"]')).toBeFocused();
  await builder.locator('> summary').click();
  await expect(builder).toBeHidden();
  await expect(link).toBeFocused();
});

test('Recent Search Terms shows when each search happened', async ({ page }) => {
  await page.goto(`${PAGE}&type=last_90_days`);
  const rows = page.locator('#slim_p1_06 p.slimstat-tooltip-trigger');
  await expect(page.locator('#slim_p1_06 .inside')).not.toBeEmpty();
  if (await rows.count()) {
    await expect(rows.first().locator('.slimstat-row-time')).toHaveText(/\S/);
  }
});
