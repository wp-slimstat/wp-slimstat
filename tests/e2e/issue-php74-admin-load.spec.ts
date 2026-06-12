/**
 * Issue #303 follow-up — wp-admin must load cleanly on PHP 7.4.
 *
 * Repo's wp-env is pinned to PHP 7.4 (.wp-env.json), so this spec directly
 * exercises what until v5.4.14 was a per-admin-page fatal: str_contains()
 * in admin/index.php. The previous E2E suite never visited wp-admin pages,
 * which is why the bug shipped.
 */
import { test, expect } from '@playwright/test';
import { readDebugLog, truncateDebugLog } from './helpers/setup';
import { BASE_URL } from './helpers/env';

const ADMIN_PAGES = [
  { url: '/wp-admin/',                            name: 'php74-admin-root',     isSlim: false },
  { url: '/wp-admin/admin.php?page=slimview1',    name: 'php74-slim-page',      isSlim: true  },
  { url: '/wp-admin/edit.php',                    name: 'php74-non-slim-page',  isSlim: false },
];

test.describe('Issue #303 follow-up — PHP 7.4 wp-admin loads (str_contains regression)', () => {
  test.setTimeout(45_000);

  test.beforeEach(() => truncateDebugLog());

  test.afterEach(() => {
    const log = readDebugLog();
    expect(log, 'debug.log must not contain `Call to undefined function str_contains`')
      .not.toMatch(/Call to undefined function str_contains/);
    expect(log, 'debug.log must not contain any PHP Fatal from wp-slimstat path')
      .not.toMatch(/PHP Fatal error[\s\S]{0,500}wp-slimstat/);
  });

  for (const p of ADMIN_PAGES) {
    test(`${p.name}-loads-200: GET ${p.url} returns 200`, async ({ page }) => {
      const response = await page.goto(`${BASE_URL}${p.url}`, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response!.status(), `${p.url} status — pre-v5.4.14 this 500ed on PHP 7.4`).toBe(200);
      await expect(page.locator('body')).toHaveClass(/wp-admin/);
    });
  }

  test('php74-slim-page-enqueues-datepicker', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });
    const datepicker = await page.locator('script[src*="jquery-ui"][src*="datepicker"], script[id*="jquery-ui-datepicker"]').count();
    expect(datepicker, 'jquery-ui-datepicker must be enqueued on SlimStat report pages').toBeGreaterThan(0);
  });

  test('php74-non-slim-page-no-datepicker', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' });
    const datepicker = await page.locator('script[src*="jquery-ui"][src*="datepicker"]').count();
    expect(datepicker, 'jquery-ui-datepicker must NOT be enqueued on non-SlimStat pages').toBe(0);
  });
});
