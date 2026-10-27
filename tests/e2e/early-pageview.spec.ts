import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb,
  installOptionMutator, uninstallOptionMutator } from './helpers/setup';

const human = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
test.describe('early pageviews and explicit automation (#364/#365)', () => {
  test.beforeAll(async () => { await snapshotSlimstatOptions(); await installOptionMutator(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await uninstallOptionMutator(); await closeDb(); });
  test('SQL debug is visible only when enabled and preserves report rows', async ({ page }) => {
    await setSlimstatOptions({ async_load: 'no', show_sql_debug: 'off' });
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    const box = page.locator('#slim_p1_10');
    await expect(box).toBeVisible();
    await expect(box.locator('p.debug')).toHaveCount(0);
    const rows = await box.locator('p:not(.debug)').allTextContents();
    await setSlimstatOptions({ show_sql_debug: 'on' });
    await page.reload();
    await expect(box.locator('p.debug').first()).toContainText('SELECT');
    expect(await box.locator('p:not(.debug)').allTextContents()).toEqual(rows);
  });
  for (const consent of ['allowed', 'denied']) {
    test(`slow image does not drop or duplicate pageviews; consent=${consent}`, async ({ browser }) => {
      await setSlimstatOptions({ javascript_mode: 'on', gdpr_enabled: consent === 'denied' ? 'on' : 'off',
        anonymous_tracking: 'off', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on',
        set_tracker_cookie: 'on', ignore_bots: 'on', ignore_users: '', ignore_ips: '', ignore_resources: '',
        is_tracking: 'on', tracking_request_method: 'ajax', ignore_wp_users: 'no' });
      const marker = `early-${consent}-${Date.now()}`;
      const context = await browser.newContext({ userAgent: human, storageState: { cookies: [], origins: [] } });
      const page = await context.newPage();
      const errors: string[] = [];
      page.on('pageerror', e => errors.push(e.message));
      let release: (() => void)[] = [];
      await page.route('**/slow-early-image.png', async route => {
        await new Promise<void>(resolve => release.push(resolve));
        await route.abort().catch(() => {});
      });
      await page.route(`**/*early_case=${marker}*`, async route => {
        const response = await route.fetch();
        const body = (await response.text()).replace('</body>', '<img src="/slow-early-image.png"></body>');
        await route.fulfill({ response, body });
      });
      try {
        for (let i = 0; i < 3; i++) {
          await page.goto(`${BASE_URL}/?early_case=${marker}&page=${i}`, { waitUntil: 'domcontentloaded' });
          expect(await page.evaluate(() => document.readyState)).toBe('interactive');
          if (consent === 'allowed') {
            await expect.poll(async () => {
              const [rows]: any = await getPool().query('SELECT COUNT(*) n FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
              return Number(rows[0].n);
            }, { timeout: 2500 }).toBe(i + 1);
          } else { await page.waitForTimeout(1200); }
          expect(await page.evaluate(() => document.readyState)).toBe('interactive');
          if (i === 2) { release.forEach(fn => fn()); await page.waitForLoadState('load'); await page.waitForTimeout(1000); }
        }
        await page.goto('about:blank');
        await page.waitForTimeout(300);
        const [rows]: any = await getPool().query('SELECT COUNT(*) n, COUNT(DISTINCT visit_id) visits FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
        expect(Number(rows[0].n)).toBe(consent === 'allowed' ? 3 : 0);
        if (consent === 'allowed') expect(Number(rows[0].visits)).toBe(1);
        expect(errors).toEqual([]);
      } finally { release.forEach(fn => fn()); await context.close(); await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]); }
    });
  }
  for (const mode of ['on', 'off']) {
    for (const excluded of ['on', 'off']) {
      test(`HeadlessChrome in client mode=${mode}, bot exclusion=${excluded}`, async ({ browser }) => {
        await setSlimstatOptions({ javascript_mode: mode, gdpr_enabled: 'off', anonymous_tracking: 'off',
          ignore_bots: excluded, enable_browscap: 'no', ignore_users: '', ignore_ips: '', ignore_resources: '',
          is_tracking: 'on', tracking_request_method: 'ajax', ignore_wp_users: 'no' });
        const marker = `headless-${mode}-${excluded}-${Date.now()}`;
        const context = await browser.newContext({ userAgent: human.replace(' Chrome/', ' HeadlessChrome/'), storageState: { cookies: [], origins: [] } });
        try {
          const page = await context.newPage();
          await page.goto(`${BASE_URL}/?automation_case=${marker}`);
          await page.waitForTimeout(2000);
          const [rows]: any = await getPool().query('SELECT browser_type FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
          expect(rows.length).toBe(excluded === 'on' ? 0 : 1);
          if (rows.length) expect(Number(rows[0].browser_type)).toBe(1);
        } finally { await context.close(); await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]); }
      });
    }
  }
});
