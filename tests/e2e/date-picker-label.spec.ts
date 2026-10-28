/**
 * Date picker regressions from the 2026-10-04 screenshot audit.
 *
 * - A from/to range that equals a preset is named after it: the button and the
 *   highlighted preset agree (it once read "Custom Range" beside a highlighted "Last 28 Days").
 * - Dates follow the WordPress date format, not a fixed DD/MM/YYYY.
 * - Clear cache is a footer action, not an entry in the preset list.
 *
 * Read-only: navigation only, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const OVERVIEW = `${BASE_URL}/wp-admin/admin.php?page=slimview2`;

for (const [day, preset] of [[15, 'Last 28 days'], [28, 'Last 28 days'], [30, 'Last 30 days']] as const) {
test(`URL preset agrees with its highlighted range on day ${day}: ${preset}`, async ({ page }) => {
  await page.clock.setFixedTime(new Date(`2026-10-${day}T12:00:00Z`));
  await page.goto(OVERVIEW);
  await page.waitForFunction(() => Boolean((window as any).jQuery?.('.slimstat-date-range-input').data('daterangepicker')));
  const range = await page.evaluate((preset) => {
    const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
    const [start, end] = picker.ranges[preset];
    const format = (window as any).SlimStatDatePicker.options.date_format;
    sessionStorage.clear();
    return { from: start.format('YYYY-MM-DD'), to: end.format('YYYY-MM-DD'), label: `${preset} ${start.format(format)} – ${end.format(format)}`, format };
  }, preset);
  expect(range.format, 'the WordPress date format reaches the picker').not.toBe('DD/MM/YYYY');

  await page.goto(`${OVERVIEW}&from=${range.from}&to=${range.to}`);
  await page.waitForFunction(() => Boolean((window as any).jQuery?.('.slimstat-date-range-input').data('daterangepicker')));
  const label = page.locator('.slimstat-date-range-btn .date-label');
  await expect(label).toHaveText(range.label);
  await expect(label).not.toHaveText(/\d{2}\/\d{2}\/\d{4}/);

  await page.locator('.slimstat-date-range-btn').click();
  const picker = page.locator('.slimstat-daterangepicker:visible');
  await expect(picker.locator('.ranges li.active')).toHaveText(preset);
  await expect(picker.locator('.slimstat-clear-cache-wrap #slimstat-clear-cache')).toHaveText('Clear cache');
  await expect(picker.locator('.ranges #slimstat-clear-cache')).toHaveCount(0);
  // A text link, not a blue outline button in a red picker (QA D10).
  const look = await picker.locator('#slimstat-clear-cache').evaluate((b) => {
    const cs = getComputedStyle(b);
    return { border: cs.borderTopWidth, background: cs.backgroundColor, rgb: (cs.color.match(/\d+/g) || []).slice(0, 3).map(Number) };
  });
  expect([look.border, look.background]).toEqual(['0px', 'rgba(0, 0, 0, 0)']);
  expect(look.rgb[2], 'Clear cache is not link blue').toBeLessThanOrEqual(Math.max(look.rgb[0], look.rgb[1]));
});
}

test('a custom range keeps its label, highlight and calendar controls', async ({ page }) => {
  await page.clock.setFixedTime(new Date('2026-10-28T12:00:00Z'));
  await page.goto(`${OVERVIEW}&from=2026-10-03&to=2026-10-09`);
  await page.waitForFunction(() => Boolean((window as any).jQuery?.('.slimstat-date-range-input').data('daterangepicker')));
  await expect(page.locator('.slimstat-date-range-btn .date-label')).toContainText('Custom range');
  await page.locator('.slimstat-date-range-btn').click();
  const picker = page.locator('.slimstat-daterangepicker:visible');
  await expect(picker.locator('.ranges li.active')).toHaveText('Custom range');
  await expect(picker.locator('.drp-calendar.left')).toBeVisible();
  await expect(picker.locator('.applyBtn')).toBeVisible();
});
