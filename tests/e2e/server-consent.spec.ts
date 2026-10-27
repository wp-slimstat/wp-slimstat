import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb } from './helpers/setup';

test.describe('server tracking waits for banner consent', () => {
  test.beforeAll(async () => { await snapshotSlimstatOptions(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await closeDb(); });
  for (const decision of ['accepted', 'denied']) {
    test(`fresh visitor ${decision}: banner works and stored pageviews follow consent`, async ({ browser }) => {
      await setSlimstatOptions({ javascript_mode: 'off', is_tracking: 'on', gdpr_enabled: 'on',
        consent_integration: 'slimstat_banner', use_slimstat_banner: 'on', anonymous_tracking: 'off',
        ignore_bots: 'off', ignore_ips: '', ignore_users: '', do_not_track: 'off', set_tracker_cookie: 'on' });
      const marker = `server-consent-${decision}-${Date.now()}`;
      const count = async () => {
        const [rows]: any = await getPool().query('SELECT COUNT(*) n FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
        return Number(rows[0].n);
      };
      const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      try {
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/?server_consent=${marker}`);
        await expect(page.locator('#slimstat-gdpr-banner')).toBeVisible();
        expect(await count()).toBe(0);
        expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
        await page.locator(`[data-consent="${decision}"]`).click();
        await expect.poll(async () => (await context.cookies()).find(c => c.name === 'slimstat_gdpr_consent')?.value).toBe(decision);
        await expect(page.locator('#slimstat-gdpr-banner')).not.toBeVisible();
        await expect.poll(count).toBe(decision === 'accepted' ? 1 : 0);
        await page.reload();
        await expect(page.locator('#slimstat-gdpr-banner')).not.toBeVisible();
        await expect.poll(count).toBe(decision === 'accepted' ? 2 : 0);
        expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(decision === 'accepted');
      } finally {
        await context.close();
        await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      }
    });
  }
});
