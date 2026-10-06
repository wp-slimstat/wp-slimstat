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

test('a preset range from the URL keeps the preset name, in the WordPress date format', async ({ page }) => {
  await page.goto(OVERVIEW);
  const range = await page.evaluate(() => {
    const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
    const [start, end] = picker.ranges['Last 28 days'];
    const format = (window as any).SlimStatDatePicker.options.date_format;
    sessionStorage.clear();
    return { from: start.format('YYYY-MM-DD'), to: end.format('YYYY-MM-DD'), label: `Last 28 days ${start.format(format)} – ${end.format(format)}`, format };
  });
  expect(range.format, 'the WordPress date format reaches the picker').not.toBe('DD/MM/YYYY');

  await page.goto(`${OVERVIEW}&from=${range.from}&to=${range.to}`);
  const label = page.locator('.slimstat-date-range-btn .date-label');
  await expect(label).toHaveText(range.label);
  await expect(label).not.toHaveText(/\d{2}\/\d{2}\/\d{4}/);

  await page.locator('.slimstat-date-range-btn').click();
  const picker = page.locator('.slimstat-daterangepicker:visible');
  await expect(picker.locator('.ranges li.active')).toHaveText('Last 28 days');
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
