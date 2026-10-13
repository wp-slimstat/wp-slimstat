/**
 * AC-221: Admin Bar Chart Data Consistency
 *
 * Verifies that the admin bar dropdown CSS chart uses the same data
 * as the Live Analytics AJAX endpoint (get_users_chart_data).
 *
 * Issue: https://github.com/wp-slimstat/wp-slimstat/issues/221
 *
 * Note: The chart data consistency comparison (test 1) only applies when
 * Pro is active/licensed. Free renders no chart and shows a "Pro" badge
 * in place of Views and Referrals (audit F3).
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { requireProBooted } from './helpers/pro-state';

test.describe('AC-221: Admin Bar Chart Consistency', () => {
  test.setTimeout(60_000);

  test('admin bar chart data matches Live Analytics AJAX response', async ({ page }) => {
    // 1. Navigate to the Live Analytics admin page
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    // Check Pro status — this test is only meaningful for Pro users
    const isPro = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      return bar?.is_pro === true || bar?.is_pro === '1';
    });

    // Skips only where Pro is genuinely absent (Free CI lanes); an installed Pro that
    // failed to boot fails here by name rather than passing as "free behaviour".
    await requireProBooted(page);
    expect(isPro, 'SlimStatAdminBar.is_pro must be true once Pro has booted').toBe(true);

    // 2. Extract chart bar data-count values from the admin bar CSS chart
    const adminBarData = await page.evaluate(() => {
      const bars = document.querySelectorAll('.slimstat-adminbar__chart-bar');
      if (bars.length === 0) return null;
      return Array.from(bars).map((bar) => parseInt(bar.getAttribute('data-count') || '0', 10));
    });

    expect(adminBarData).not.toBeNull();
    expect(adminBarData).toHaveLength(30);

    // 3. Extract nonce from the page
    const nonce = await page.evaluate(() => {
      const html = document.documentElement.innerHTML;
      // Pro path: nonce inside LiveAnalytics config object
      const m1 = html.match(/nonce:\s*'([a-f0-9]+)'/);
      if (m1) return m1[1];
      // Free path: var nonce = 'xxxx'
      const m2 = html.match(/var nonce = '([a-f0-9]+)'/);
      if (m2) return m2[1];
      // Fallback: localized wp_slimstat_ajax object
      const ajax = (window as any).wp_slimstat_ajax;
      return ajax?.nonce || null;
    });

    expect(nonce).not.toBeNull();

    // 4. Fetch Live Analytics data via AJAX
    const ajaxResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_get_live_analytics_data',
        nonce: nonce,
        report_id: 'slim_live_analytics',
        metric: 'users',
      },
    });

    expect(ajaxResponse.ok()).toBe(true);
    const ajaxJson = await ajaxResponse.json();
    expect(ajaxJson.success).toBe(true);

    const liveData: number[] = ajaxJson.data.active_users_per_minute?.data;
    expect(liveData).toBeDefined();
    expect(liveData).toHaveLength(30);

    // 5. Compare: both should use the same underlying data source
    //    Due to caching (60s transient), both should return identical arrays
    //    when fetched in the same minute window
    expect(adminBarData).toEqual(liveData);
  });

  test('admin bar chart renders without PHP errors after fix', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const html = await page.content();

    // No PHP errors from the LiveAnalyticsReport instantiation
    expect(html).not.toContain('Fatal error');
    expect(html).not.toMatch(/PHP Warning:.*\.php/);
    expect(html).not.toMatch(/Class.*LiveAnalyticsReport.*not found/);

    // Pro renders 30 bars; Free renders no chart at all (audit F3)
    const isPro = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      return bar?.is_pro === true || bar?.is_pro === '1';
    });
    const barCount = await page.locator('.slimstat-adminbar__chart-bar').count();
    expect(barCount).toBe(isPro ? 30 : 0);
  });

  test('admin bar chart data values are non-negative integers', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await requireProBooted(page);

    const data = await page.evaluate(() => {
      const bars = document.querySelectorAll('.slimstat-adminbar__chart-bar');
      return Array.from(bars).map((bar) => ({
        count: parseInt(bar.getAttribute('data-count') || '-1', 10),
        minutesAgo: parseInt(bar.getAttribute('data-minutes-ago') || '-1', 10),
      }));
    });

    expect(data).toHaveLength(30);

    // All counts must be non-negative integers
    for (const bar of data) {
      expect(bar.count).toBeGreaterThanOrEqual(0);
      expect(Number.isInteger(bar.count)).toBe(true);
    }

    // Minutes-ago should range from 29 (first bar) to 0 (last bar)
    expect(data[0].minutesAgo).toBe(29);
    expect(data[data.length - 1].minutesAgo).toBe(0);
  });

  test('free users see real figures only: no chart, Pro badges, no invented numbers (audit F3)', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });

    const isPro = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      return bar?.is_pro === true || bar?.is_pro === '1';
    });

    if (isPro) {
      // NAMED DISPOSITION: the only Pro-related skip the suite keeps. This test asserts
      // FREE behaviour, so it is meaningless with Pro booted; it is not a Pro spec
      // skipping on a missing Pro.
      test.skip(true, 'Pro is active — this test validates free-user behavior');
      return;
    }

    await expect(page.locator('.slimstat-adminbar__chart-bar')).toHaveCount(0);
    await expect(page.locator('#wpadminbar .slimstat-adminbar__pro-badge')).toHaveCount(2);
    const stats = await page.locator('#wpadminbar .slimstat-adminbar__stats-grid').textContent();
    expect(stats).not.toMatch(/\b(248|312)\b/);
    // The upsell line shows on SlimStat screens only (audit F7).
    await expect(page.locator('#wpadminbar .slimstat-adminbar__cta-link')).toHaveCount(1);
    await page.goto(`${BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#wpadminbar .slimstat-adminbar__cta')).toHaveCount(0);
  });
});
