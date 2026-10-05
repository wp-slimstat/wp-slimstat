/**
 * At a Glance names what it counts (QA R7, U3). "From Any SERP 67" sat beside Traffic Sources'
 * "From External SERP 362", and "Last 30 minutes" beside "Visitors online"; both count pageviews.
 *
 * Traffic Sources' Direct Pageviews also counts pre-channel rows with no referrer, which Channels
 * lists as Unassigned (QA R10); its tooltip says so instead of the two numbers silently disagreeing.
 *
 * Read-only: navigation only, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('At a Glance labels say they count pageviews (QA R7, U3)', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  const glance = page.locator('#slim_p1_03');
  await expect(glance).toContainText('Pageviews with a search term');
  await expect(glance).toContainText('Pageviews, last 30 minutes');
  await expect(glance).not.toContainText('From Any SERP');
});

test('Direct Pageviews says older no-referrer rows show as Unassigned in Channels (QA R10)', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview5`);
  const direct = page.locator('#slim_p3_02 p').filter({ hasText: 'Direct Pageviews' });
  await expect(direct.locator('.slimstat-tooltip-content')).toContainText('Unassigned');
});
