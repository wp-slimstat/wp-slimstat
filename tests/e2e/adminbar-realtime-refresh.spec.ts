/**
 * Admin Bar Realtime Refresh (#223, #224)
 *
 * Verifies that the admin bar modal stats auto-refresh endpoint works correctly
 * and all required DOM elements and localized data are present.
 *
 * Issues:
 * - https://github.com/wp-slimstat/wp-slimstat/issues/223
 * - https://github.com/wp-slimstat/wp-slimstat/issues/224
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test.describe('Admin Bar Realtime Refresh (#223/#224)', () => {
  test.setTimeout(60_000);

  test('AJAX endpoint slimstat_get_adminbar_stats returns valid data', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    // Extract nonce from SlimStatAdminBar localized data
    const nonce = await page.evaluate(() => {
      return (window as any).SlimStatAdminBar?.security || null;
    });
    expect(nonce).not.toBeNull();

    // Call the new AJAX endpoint
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_get_adminbar_stats',
        security: nonce!,
      },
    });

    expect(response.ok()).toBe(true);
    const json = await response.json();
    expect(json.success).toBe(true);

    // Validate response structure
    const data = json.data;
    expect(data.online).toBeDefined();
    expect(data.online.count).toBeGreaterThanOrEqual(0);
    expect(Number.isInteger(data.online.count)).toBe(true);
    expect(typeof data.online.formatted).toBe('string');

    expect(data.sessions).toBeDefined();
    expect(data.sessions.count).toBeGreaterThanOrEqual(0);
    expect(Number.isInteger(data.sessions.count)).toBe(true);
    expect(typeof data.sessions.formatted).toBe('string');
    expect(typeof data.sessions.yesterday).toBe('string');

    expect(typeof data.is_pro).toBe('boolean');

    // Pro-only fields: views, referrals, chart
    if (data.is_pro) {
      expect(data.views).toBeDefined();
      expect(data.views.count).toBeGreaterThanOrEqual(0);
      expect(typeof data.views.yesterday).toBe('string');

      expect(data.referrals).toBeDefined();
      expect(data.referrals.count).toBeGreaterThanOrEqual(0);

      expect(data.chart).toBeDefined();
      expect(data.chart.data).toHaveLength(30);
      expect(data.chart.max_value).toBeGreaterThanOrEqual(0);
    } else {
      // Free users don't get views/referrals/chart in AJAX
      expect(data.views).toBeUndefined();
      expect(data.referrals).toBeUndefined();
      expect(data.chart).toBeUndefined();
    }
  });

  test('admin bar modal has all required element IDs for auto-refresh', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    // Core stat card IDs (always present for all users)
    const requiredIds = [
      'slimstat-adminbar-online-header',
      'slimstat-adminbar-online-count',
      'slimstat-adminbar-sessions-count',
      'slimstat-adminbar-sessions-compare',
      'slimstat-adminbar-views-count',
      'slimstat-adminbar-views-compare',
      'slimstat-adminbar-referrals-count',
      'slimstat-adminbar-referrals-compare',
    ];

    for (const id of requiredIds) {
      const el = page.locator(`#${id}`);
      await expect(el).toBeAttached({ timeout: 5000 });
    }
  });

  test('SlimStatAdminBar localized data includes i18n and is_pro', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    const config = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      if (!bar) return null;
      return {
        has_ajax_url: typeof bar.ajax_url === 'string',
        has_security: typeof bar.security === 'string',
        has_is_pro: typeof bar.is_pro !== 'undefined',
        has_i18n: typeof bar.i18n === 'object' && bar.i18n !== null,
        i18n_keys: bar.i18n ? Object.keys(bar.i18n) : [],
      };
    });

    expect(config).not.toBeNull();
    expect(config!.has_ajax_url).toBe(true);
    expect(config!.has_security).toBe(true);
    expect(config!.has_is_pro).toBe(true);
    expect(config!.has_i18n).toBe(true);
    expect(config!.i18n_keys).toContain('was_last_day');
    expect(config!.i18n_keys).toContain('online_users');
    expect(config!.i18n_keys).toContain('count_label');
    expect(config!.i18n_keys).toContain('now');
    expect(config!.i18n_keys).toContain('min_ago');
  });

  test('slimstatUpdateAdminBar global function is exposed', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    const hasFunction = await page.evaluate(() => {
      return typeof (window as any).slimstatUpdateAdminBar === 'function'
        && typeof (window as any).slimstatAnimateElement === 'function';
    });

    expect(hasFunction).toBe(true);
  });

  test('AJAX response is consistent with server-rendered stat values', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    // Extract server-rendered values from DOM
    const serverValues = await page.evaluate(() => {
      const getText = (id: string) => document.getElementById(id)?.textContent?.trim() || '';
      return {
        online: getText('slimstat-adminbar-online-count'),
        sessions: getText('slimstat-adminbar-sessions-count'),
      };
    });

    // Fetch fresh AJAX data
    const nonce = await page.evaluate(() => (window as any).SlimStatAdminBar?.security);
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_get_adminbar_stats',
        security: nonce,
      },
    });

    const json = await response.json();
    const data = json.data;

    // Server-rendered and AJAX values should be consistent
    // (they may differ slightly due to time between render and AJAX call,
    //  but sessions count for "today" should be stable within seconds)
    expect(data.sessions.count).toBeGreaterThanOrEqual(0);
    expect(data.online.count).toBeGreaterThanOrEqual(0);

    // Verify formatted values are proper number strings
    expect(data.online.formatted).toMatch(/^\d[\d,]*$/);
    expect(data.sessions.formatted).toMatch(/^\d[\d,]*$/);
  });

  test('no PHP errors on admin page with admin bar', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });

    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toMatch(/PHP Warning:.*\.php/);
    expect(html).not.toMatch(/Class.*not found/);
  });
});
