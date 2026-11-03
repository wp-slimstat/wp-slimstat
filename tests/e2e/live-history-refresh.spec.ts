import { test, expect } from '@playwright/test';
import { getPool, closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';
import { requireProBooted } from './helpers/pro-state';

test('returning to Real-time retains history and refreshes the header from the same response', async ({ page }) => {
  const marker = `/live-history-${Date.now()}`;
  await snapshotSlimstatOptions();
  try {
    await setSlimstatOptions({ tracking: 'on', ignore_wp_users: 'no' });
    const clearCache = () => getPool().execute("DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_%slimstat_adminbar_%' OR option_name LIKE '\\_transient\\_%slimstat_chart_data_users_%'");
    const insert = (visit: number, secondsAgo: number) => getPool().execute(
      "INSERT INTO wp_slim_stats (visit_id,ip,resource,browser_type,dt) VALUES (?, '192.0.2.1', ?, 0, UNIX_TIMESTAMP() + COALESCE((SELECT option_value FROM wp_options WHERE option_name='gmt_offset'),0) * 3600 - ?)",
      [visit, marker, secondsAgo],
    );
    await insert(2100000001, 600);
    await clearCache();
    await page.addInitScript(() => localStorage.setItem('slimstat_live_analytics_last_active', String(Date.now() - 20 * 60000)));
    await page.goto('/wp-admin/admin.php?page=slimview1');
    await requireProBooted(page);
    const chartData = () => page.evaluate(() => {
      const canvas = document.querySelector('#slim_live_analytics canvas') as HTMLCanvasElement;
      return (window as any).Chart.getChart(canvas)?.data.datasets[0].data;
    });
    await expect.poll(chartData).toBeTruthy();
    const headerBars = await page.locator('.slimstat-adminbar__chart-bar').evaluateAll(bars => bars.map(bar => Number(bar.getAttribute('data-count'))));
    expect(headerBars.some(value => value > 0)).toBe(true);
    expect(await chartData()).toEqual(headerBars);
    await expect(page.locator('#slim_live_analytics .empty-state')).toBeHidden();

    const before = Number((await page.locator('#slim_live_analytics .users-value').innerText()).replace(/,/g, ''));
    await insert(2100000002, 0);
    await clearCache();
    const response = page.waitForResponse(res => (res.request().postData() || '').includes('action=slimstat_get_live_analytics_data'));
    await page.locator('#slim_live_analytics .live-indicator').click();
    expect((await (await response).json()).success).toBe(true);
    await expect(page.locator('#slim_live_analytics .users-value')).toHaveText(String(before + 1));
    await expect(page.locator('#slimstat-adminbar-online-count')).toHaveText(String(before + 1));
    const refreshedBars = await page.locator('.slimstat-adminbar__chart-bar').evaluateAll(bars => bars.map(bar => Number(bar.getAttribute('data-count'))));
    expect(await chartData()).toEqual(refreshedBars);
  } finally {
    await getPool().execute('DELETE FROM wp_slim_stats WHERE resource=?', [marker]);
    await restoreSlimstatOptions();
    await closeDb();
  }
});
