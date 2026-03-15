/**
 * AC-221: Admin Bar Chart Data Consistency
 *
 * Verifies that the admin bar dropdown CSS chart uses the same data
 * as the Live Analytics AJAX endpoint (get_users_chart_data).
 *
 * Issue: https://github.com/wp-slimstat/wp-slimstat/issues/221
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test.describe('AC-221: Admin Bar Chart Consistency', () => {
  test.setTimeout(60_000);

  test('admin bar chart data matches Live Analytics AJAX response', async ({ page }) => {
    // 1. Navigate to any admin page (admin bar renders on all admin pages)
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(3000); // Let JS settle

    // 2. Extract chart bar data-count values from the admin bar CSS chart
    const adminBarData = await page.evaluate(() => {
      const bars = document.querySelectorAll('.slimstat-adminbar__chart-bar');
      if (bars.length === 0) return null;
      return Array.from(bars).map((bar) => parseInt(bar.getAttribute('data-count') || '0', 10));
    });

    // Admin bar chart must exist (Pro is installed)
    expect(adminBarData).not.toBeNull();
    expect(adminBarData).toHaveLength(30);

    // 3. Extract nonce from the inline script on slimview1
    //    The Live Analytics report embeds: nonce: 'xxxx'
    const nonce = await page.evaluate(() => {
      const html = document.documentElement.innerHTML;
      const match = html.match(/nonce:\s*'([a-f0-9]+)'/);
      return match ? match[1] : null;
    });

    // slimview1 always has the Live Analytics report with nonce
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
    // Load any admin page and verify the admin bar doesn't produce errors
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

    // Admin bar chart bars should exist
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
});
