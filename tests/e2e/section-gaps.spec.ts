/**
 * QA D5: section gaps.
 *
 * - Heatmaps: the intro's own margin, the list's top padding and a paragraph's default margin stacked
 *   into a ~53px gap above the "Tracking:" line.
 * - Ecommerce: the report-row rule for `[id^="slim_"] p` (position: relative) beat core's
 *   .screen-reader-text, so the empty live region took ~32px between "Updated · Refresh" and the toolbar.
 *
 * Read-only: both hold on an empty install, with or without WooCommerce or Pro.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('Heatmaps content starts one standard gap below the intro', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimheatmap`);
  const gap = await page.locator('.ss-hm').evaluate((list) => {
    const first = [...list.children].find((c) => (c as HTMLElement).offsetHeight > 0)!;
    return first.getBoundingClientRect().top - document.querySelector('.slimstat-pageintro')!.getBoundingClientRect().bottom;
  });
  // The intro's bottom margin (--ss-space-5) is the gap; nothing below it adds to it.
  expect(gap).toBeLessThanOrEqual(21);
});

test('Ecommerce: the hidden status region takes no space', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview7`);
  const feedback = page.locator('.ss-ec-feedback');
  await expect(feedback).toHaveCount(1, { timeout: 20_000 });
  const moved = await feedback.evaluate((region) => {
    const next = region.nextElementSibling!;
    const before = next.getBoundingClientRect().top;
    (region as HTMLElement).style.display = 'none';
    return before - next.getBoundingClientRect().top;
  });
  expect(moved, 'what follows the region does not move up when it is gone').toBe(0);
});
