/**
 * AC-221: Admin Bar Chart Data Consistency
 *
 * Verifies that the admin bar dropdown CSS chart uses the same data
 * as the Live Analytics AJAX endpoint (get_users_chart_data).
 *
 * Issue: https://github.com/wp-slimstat/wp-slimstat/issues/221
 *
 * Note: The chart data consistency comparison (test 1) only applies when
 * Pro is active/licensed. Free users see decorative placeholder data in
 * the admin bar chart, which is expected behavior.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

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

    if (!isPro) {
      // Free users get decorative placeholder data in admin bar chart.
      // The chart shows fake values for visual appeal, not real analytics.
      // This is expected behavior — skip the data comparison.
      test.skip(true, 'Pro not active — admin bar chart shows decorative placeholder data (expected)');
      return;
    }

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

    // Admin bar chart bars should exist (both Pro and Free render 30 bars)
    const barCount = await page.locator('.slimstat-adminbar__chart-bar').count();
    expect(barCount).toBe(30);
  });

  test('admin bar chart data values are non-negative integers', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });

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

  test('admin bar chart uses LiveAnalyticsReport when Pro is active', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    const isPro = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      return bar?.is_pro === true || bar?.is_pro === '1';
    });

    if (!isPro) {
      test.skip(true, 'Pro not active — cannot verify LiveAnalyticsReport usage');
      return;
    }

    // When Pro is active, the admin bar chart should show real data from
    // LiveAnalyticsReport::get_users_chart_data(), not the fake placeholder array.
    // The placeholder array is: [3,5,4,7,6,8,5,9,7,6,8,10,7,5,6,8,9,7,6,5,8,10,9,7,6,8,5,7,6,8]
    const adminBarData = await page.evaluate(() => {
      const bars = document.querySelectorAll('.slimstat-adminbar__chart-bar');
      return Array.from(bars).map((bar) => parseInt(bar.getAttribute('data-count') || '0', 10));
    });

    const fakeData = [3, 5, 4, 7, 6, 8, 5, 9, 7, 6, 8, 10, 7, 5, 6, 8, 9, 7, 6, 5, 8, 10, 9, 7, 6, 8, 5, 7, 6, 8];

    // If Pro is active, the data should NOT be the placeholder array
    // (unless by extraordinary coincidence the real data matches exactly)
    expect(adminBarData).not.toEqual(fakeData);
  });

  test('free users see decorative chart with valid structure', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/index.php`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000);

    const isPro = await page.evaluate(() => {
      const bar = (window as any).SlimStatAdminBar;
      return bar?.is_pro === true || bar?.is_pro === '1';
    });

    if (isPro) {
      test.skip(true, 'Pro is active — this test validates free-user behavior');
      return;
    }

    // Free users should still see 30 chart bars with valid structure
    const barCount = await page.locator('.slimstat-adminbar__chart-bar').count();
    expect(barCount).toBe(30);

    // Bars should have valid data-count and data-minutes-ago attributes
    const firstBar = await page.locator('.slimstat-adminbar__chart-bar').first();
    const lastBar = await page.locator('.slimstat-adminbar__chart-bar').last();

    expect(await firstBar.getAttribute('data-minutes-ago')).toBe('29');
    expect(await lastBar.getAttribute('data-minutes-ago')).toBe('0');

    // Chart should have visible height (not all 0%)
    const hasVisibleBars = await page.evaluate(() => {
      const bars = document.querySelectorAll('.slimstat-adminbar__chart-bar');
      return Array.from(bars).some((bar) => {
        const height = (bar as HTMLElement).style.height;
        return height && parseInt(height) > 3;
      });
    });
    expect(hasVisibleBars).toBe(true);
  });
});
