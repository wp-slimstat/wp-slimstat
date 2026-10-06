/**
 * Online counts from the 2026-10-04 screenshot audit: the header said 8 online while
 * "Currently Online" said "No data to display". They measure different windows (30 min of
 * sessions vs 5 min of IPs), so each label now names its window, and the header and the
 * admin bar show the same 30-minute figure under the same label.
 *
 * Read-only: navigation only, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('online counts name their window, and header and admin bar agree', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);

  await expect(page.locator('.slimstat-header__online-label')).toHaveText('Visitors online (last 30 min)');
  await expect(page.locator('#wp-admin-bar-slimstat-header .slimstat-adminbar__stat-title').first()).toContainText('Visitors online (last 30 min)');
  const header = (await page.locator('#slimstat-online-visitors-count').textContent())?.trim();
  await expect(page.locator('#slimstat-adminbar-online-count')).toHaveText(header!);

  await expect(page.locator('#slim_p1_04 h3').first()).toContainText('Visitors online (last 5 min)');
});
