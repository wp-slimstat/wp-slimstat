/**
 * Heatmaps list from the 2026-10-04 screenshot audit: the list had its own 7/30/90 select
 * while every other SlimStat screen uses the global date picker, counts wrapped under their
 * heat dot, and Delete looked like any other link.
 *
 * Read-only: navigation and GET requests only.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const LIST = `${BASE_URL}/wp-admin/admin.php?page=slimheatmap`;
const days = (from: string, to: string) => (Date.parse(to) - Date.parse(from)) / 86400000 + 1;

test('the list uses the global date picker, and its range reaches the request', async ({ page }) => {
  const request = page.waitForRequest((r) => r.url().includes('heatmap%2Fpages') || r.url().includes('heatmap/pages'));
  await page.goto(`${LIST}&type=last_7_days`);
  const params = new URL((await request).url()).searchParams;

  await expect(page.locator('.ss-hm-toolbar select[name="range"]')).toHaveCount(0);
  await expect(page.locator('.ss-hm .slimstat-date-range-btn')).toBeVisible();
  expect(days(params.get('from')!, params.get('to')!)).toBe(7);
});

test('counts stay on one line and Delete reads as destructive', async ({ page }) => {
  await page.goto(LIST);
  await expect(page.locator('.ss-hm-table')).toHaveAttribute('aria-busy', 'false');

  const heat = page.locator('.ss-hm-table td:has(.ss-hm-heat)').first();
  if (await heat.count()) {
    expect(await heat.evaluate((td) => getComputedStyle(td).whiteSpace)).toBe('nowrap');
  }

  const remove = page.locator('.ss-hm-delete button');
  await expect(remove).toHaveClass(/(^|\s)button(\s|$)/);
  const [color, danger] = await remove.evaluate((b) => [getComputedStyle(b).color, getComputedStyle(b).getPropertyValue('--ss-danger-fg').trim()]);
  const probe = await page.evaluate((c) => { const s = document.createElement('span'); s.style.color = c; document.body.append(s); const v = getComputedStyle(s).color; s.remove(); return v; }, danger);
  expect(color).toBe(probe);
});
