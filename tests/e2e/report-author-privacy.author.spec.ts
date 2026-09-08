import { test, expect } from '@playwright/test';
import {
  clearStatsTable,
  closeDb,
  getPool,
  restoreAllOptions,
  restoreSlimstatOptions,
  setSlimstatSetting,
  snapshotOption,
  snapshotSlimstatOptions,
} from './helpers/setup';
import { AUTHOR_USER, BASE_URL } from './helpers/env';

let wpNow = 0;

async function chartNonce(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, { waitUntil: 'domcontentloaded' });
  const value = await page.evaluate(() => (window as any).slimstat_chart_vars?.nonce);
  expect(typeof value).toBe('string');
  return value;
}

async function liveNonce(page: import('@playwright/test').Page): Promise<string> {
  const value = await page.evaluate(() => (window as any).wp_slimstat_ajax?.nonce);
  expect(typeof value).toBe('string');
  return value;
}

async function adminbarNonce(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' });
  const value = await page.evaluate(() => (window as any).SlimStatAdminBar?.security);
  expect(typeof value).toBe('string');
  return value;
}

async function pinWpClockAwayFromMidnight(): Promise<number> {
  const [rows] = await getPool().execute('SELECT UNIX_TIMESTAMP() AS now, HOUR(UTC_TIMESTAMP()) AS hour') as any;
  const offset = 12 - Number(rows[0].hour);
  await getPool().execute("UPDATE wp_options SET option_value = '' WHERE option_name = 'timezone_string'");
  await getPool().execute("UPDATE wp_options SET option_value = ? WHERE option_name = 'gmt_offset'", [String(offset)]);
  return Number(rows[0].now) + offset * 3600;
}

async function seedAuthorRows(now: number): Promise<void> {
  now -= 120;
  await getPool().execute(
    `INSERT INTO wp_slim_stats
       (resource, country, dt, dt_out, ip, visit_id, browser, platform, content_type, author)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      '/author-own', 'de', now, now, '127.0.0.1', 101, 'Chrome', 'Windows', 'post', AUTHOR_USER,
      '/author-secret-a', 'us', now, now, '127.0.0.2', 201, 'Chrome', 'Windows', 'post', 'another-author',
      '/author-secret-b', 'gb', now, now, '127.0.0.3', 202, 'Chrome', 'Windows', 'post', 'another-author',
    ],
  );
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_slimstat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_%'",
  );
}

test.describe('author report privacy boundaries', () => {
  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await snapshotOption('timezone_string');
    await snapshotOption('gmt_offset');
    wpNow = await pinWpClockAwayFromMidnight();
    await clearStatsTable();
    await setSlimstatSetting('restrict_authors_view', 'on');
    await setSlimstatSetting('can_view', '');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    await restoreAllOptions();
    await clearStatsTable();
  });

  test.afterAll(async () => { await closeDb(); });

  test('configured capability denies a valid author chart nonce', async ({ page }) => {
    await setSlimstatSetting('capability_can_view', 'read');
    const validNonce = await chartNonce(page);
    await setSlimstatSetting('capability_can_view', 'manage_options');
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_fetch_chart_data',
        nonce: validNonce,
        granularity: 'hourly',
        args: JSON.stringify({ start: wpNow - 3600, end: wpNow, chart_data: { data1: 'COUNT(id)', data2: 'COUNT(DISTINCT ip)' } }),
      },
    });
    expect(await response.json()).toMatchObject({ success: false, data: { message: 'Insufficient permissions' } });
  });

  test('forged chart filters, admin bar, and autosuggest remain in the author scope', async ({ page }) => {
    await setSlimstatSetting('capability_can_view', 'read');
    await seedAuthorRows(wpNow);
    const validChartNonce = await chartNonce(page);
    const validLiveNonce = await liveNonce(page);
    const chartResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_fetch_chart_data',
        nonce: validChartNonce,
        granularity: 'hourly',
        args: JSON.stringify({
          start: wpNow - 3600,
          end: wpNow + 1,
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
    expect(adminbar.data.is_pro).toBe(true);
    expect(Math.max(...adminbar.data.chart.data)).toBe(1);

    for (const metric of ['users', 'pages', 'countries']) {
      const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
        form: {
          action: 'slimstat_get_live_analytics_data',
          nonce: validLiveNonce,
          report_id: 'slim_live_analytics',
          metric,
        },
      });
      const live = await response.json();
      expect(live.success).toBe(true);
      expect(live.data.users_live).toBe(1);
      expect(live.data.pages_live).toBe(1);
      expect(live.data.countries_live).toBe(1);
      expect(Math.max(...live.data.active_users_per_minute.data)).toBe(1);
    }
  });
});
