/**
 * E2E tests: Dashboard Access Log widget height (#247)
 *
 * Verifies the Access Log widget on the WP Dashboard has adequate height
 * to display a reasonable number of rows without excessive scrolling.
 * Also verifies the Real-time page is unaffected.
 */
import { test, expect } from '@playwright/test';
import { getPool, closeDb } from './helpers/setup';

test.describe('Dashboard Access Log Widget Height (#247)', () => {
  test.beforeAll(async () => {
    // Seed at least 15 pageviews so the widget has content
    const pool = getPool();
    const values = Array.from({ length: 15 }, (_, i) => [
      `192.168.1.${i + 1}`,
      ['/', '/sample-page/', '/hello-world/', '/about/', '/contact/'][i % 5],
      Math.floor(Date.now() / 1000) - i * 120,
      ['Chrome 120', 'Firefox 115', 'Safari 17'][i % 3],
      ['Windows', 'macOS', 'Linux'][i % 3],
      'text/html',
      100 + i,
    ]);
    for (const row of values) {
      await pool.execute(
        'INSERT INTO wp_slim_stats (ip, resource, dt, browser, platform, content_type, visit_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
        row
      );
    }
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('dashboard Access Log widget .inside height is 500px (CSS contract)', async ({ page }) => {
    await page.goto('/wp-admin/');
    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible();

    const insideHeight = await widget.locator('.inside').evaluate(
      (el) => parseFloat(window.getComputedStyle(el).height)
    );
    expect(insideHeight, 'CSS contract: #dashboard-widgets #slim_p7_02 .inside = 500px').toBe(500);
  });

  test('dashboard Access Log widget has no duplicate h3 title', async ({ page }) => {
    await page.goto('/wp-admin/');
    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible();

    // WordPress dashboard widget renders its own header via .postbox-header h2
    // There should be no additional visible h3 inside the widget
    const visibleH3Count = await widget.evaluate((el) => {
      const h3s = el.querySelectorAll('h3');
      return Array.from(h3s).filter(
        (h) => window.getComputedStyle(h).display !== 'none'
      ).length;
    });
    expect(visibleH3Count, 'No visible h3 inside dashboard widget').toBe(0);
  });

  test('Real-time page Access Log .inside height is 465px (unchanged)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible();

    const insideHeight = await widget.locator('.inside').evaluate(
      (el) => parseFloat(window.getComputedStyle(el).height)
    );
    expect(insideHeight, 'Real-time page .inside should be 465px').toBe(465);
  });

  test('Real-time page Access Log has .tall class', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible();

    const classes = await widget.getAttribute('class');
    expect(classes).toContain('tall');
    expect(classes).toContain('full-width');
  });
});
