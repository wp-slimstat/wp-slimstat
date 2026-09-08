import { test, expect } from '@playwright/test';
import {
  clearStatsTable,
  closeDb,
  getPool,
  restoreSlimstatOptions,
  setSlimstatSetting,
  snapshotSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

async function chartNonce(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, { waitUntil: 'domcontentloaded' });
  const value = await page.evaluate(() => (window as any).slimstat_chart_vars?.nonce);
  expect(typeof value).toBe('string');
  return value;
}

async function adminbarNonce(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' });
  const value = await page.evaluate(() => (window as any).SlimStatAdminBar?.security);
  expect(typeof value).toBe('string');
  return value;
}

async function seedAuthorRows(): Promise<void> {
  const now = Math.floor(Date.now() / 1000) - 120;
  await getPool().execute(
    `INSERT INTO wp_slim_stats
       (resource, dt, dt_out, ip, visit_id, browser, platform, content_type, author)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      '/author-own', now, now, '127.0.0.1', 101, 'Chrome', 'Windows', 'post', 'qaauthor',
      '/author-secret-a', now, now, '127.0.0.2', 201, 'Chrome', 'Windows', 'post', 'another-author',
      '/author-secret-b', now, now, '127.0.0.3', 202, 'Chrome', 'Windows', 'post', 'another-author',
    ],
  );
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_slimstat_%' OR option_name LIKE '_transient_timeout_slimstat_%'",
  );
}

test.describe('author report privacy boundaries', () => {
  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatSetting('restrict_authors_view', 'on');
    await setSlimstatSetting('can_view', '');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    await clearStatsTable();
  });

  test.afterAll(async () => { await closeDb(); });

  test('configured capability denies a valid author chart nonce', async ({ page }) => {
    await setSlimstatSetting('capability_can_view', 'read');
    const validNonce = await chartNonce(page);
    await setSlimstatSetting('capability_can_view', 'manage_options');
    const now = Math.floor(Date.now() / 1000);
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_fetch_chart_data',
        nonce: validNonce,
        granularity: 'hourly',
        args: JSON.stringify({ start: now - 3600, end: now, chart_data: { data1: 'COUNT(id)', data2: 'COUNT(DISTINCT ip)' } }),
      },
    });
    expect(await response.json()).toMatchObject({ success: false, data: { message: 'Insufficient permissions' } });
  });

  test('forged chart filters, admin bar, and autosuggest remain in the author scope', async ({ page }) => {
    await setSlimstatSetting('capability_can_view', 'read');
    await seedAuthorRows();
    const validChartNonce = await chartNonce(page);
    const now = Math.floor(Date.now() / 1000);
    const chartResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_fetch_chart_data',
        nonce: validChartNonce,
        granularity: 'hourly',
        args: JSON.stringify({
          start: now - 3600,
          end: now + 1,
          filters: { author: ['equals', 'another-author'] },
          chart_data: { data1: 'COUNT(id)', data2: 'COUNT(DISTINCT ip)' },
        }),
      },
    });
    const chart = await chartResponse.json();
    expect(chart.success).toBe(true);
    expect(chart.data.data.datasets.v1.reduce((sum: number, value: number) => sum + value, 0)).toBe(1);

    const security = await adminbarNonce(page);
    const filtersResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_get_filter_options', security, dimension: 'resource' },
    });
    expect(await filtersResponse.json()).toEqual({ success: true, data: ['/author-own'] });

    const adminbarResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_get_adminbar_stats', security },
    });
    const adminbar = await adminbarResponse.json();
    expect(adminbar.success).toBe(true);
    expect(adminbar.data.online.count).toBe(1);
    expect(adminbar.data.sessions.count).toBe(1);
  });
});
