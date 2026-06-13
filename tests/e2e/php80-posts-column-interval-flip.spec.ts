/**
 * E2E (compat matrix): PHP-8.0 posts-column interval fallback.
 *
 * Proves the Phase-2 fix at admin/index.php add_column_header(): when the
 * `posts_column_day_interval` setting is empty (admin cleared the field), the
 * column header must fall back to "28 days". On PHP 7 the old `0 == ''`
 * comparison made that fallback fire; PHP 8.0 flipped `0 == ''` to false, so the
 * header rendered an empty day count. The `empty()` fix restores the PHP-7
 * behavior on every lane — this spec is the behavioral proof.
 *
 * Tagged @compat (runs on the matrix lanes) and is most meaningful on PHP 8.x.
 */
import { test, expect } from '@playwright/test';
import {
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const POSTS_LIST = `${BASE_URL}/wp-admin/edit.php`;

test.describe('PHP 8.0 posts-column interval fallback @compat', () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    // Enable the pageviews column and clear the interval (the cleared-field case).
    await setSlimstatOptions({
      posts_column_pageviews: 'on',
      posts_column_day_interval: '',
    });
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('cleared interval renders "28 days", not an empty count @compat', async ({ page }) => {
    await page.goto(POSTS_LIST, { waitUntil: 'domcontentloaded' });

    // The column header is rendered by add_column_header() as a
    // <span class="slimstat-icon" title="Pageviews in the last 28 days">.
    const icon = page.locator('th .slimstat-icon, #wp-slimstat .slimstat-icon').first();
    await expect(icon, 'SlimStat posts column header').toBeVisible();

    const title = (await icon.getAttribute('title')) || '';
    expect(title, 'column header tooltip').toMatch(/in the last\s+28\s+days/i);
    // Guard against the PHP-8.0 regression: an empty interval ("in the last  days").
    expect(title).not.toMatch(/in the last\s+days/i);
  });
});
