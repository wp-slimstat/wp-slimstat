/**
 * At a Glance names what it counts (QA R7, U3). "From Any SERP 67" sat beside Traffic Sources'
 * "From External SERP 362", and "Last 30 minutes" beside "Visitors online"; both count pageviews.
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
