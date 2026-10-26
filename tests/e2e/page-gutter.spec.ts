/**
 * Every SlimStat screen keeps a right gutter equal to the WordPress left one
 * (2026-10-04 screenshot audit: the date button, Refresh, tables and the heatmap
 * sidebar ran to the viewport edge).
 *
 * Read-only: navigation only.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

for (const page_slug of ['slimview1', 'slimview2', 'slimview5', 'slimview7', 'slimheatmap']) {
  test(`${page_slug} has a right gutter`, async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=${page_slug}`);
    const gap = await page.evaluate(() => {
      const wrap = document.querySelector('.wrap-slimstat')!.getBoundingClientRect();
      const content = document.querySelector('#wpcontent')!;
      return { right: document.documentElement.clientWidth - wrap.right, left: wrap.left - content.getBoundingClientRect().left };
    });
    expect(gap.right).toBeGreaterThanOrEqual(gap.left);
  });
}
