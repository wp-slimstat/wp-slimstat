import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { requireProBooted } from './helpers/pro-state';
import { BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb,
  snapshotOption, restoreOption, installOptionMutator, uninstallOptionMutator } from './helpers/setup';

test.describe('consent wire and unchanged settings (#366/#367)', () => {
  test.beforeAll(async () => { await snapshotSlimstatOptions(); await installOptionMutator(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await uninstallOptionMutator(); await closeDb(); });
  for (const source of ['wp_consent_api', 'real_cookie_banner']) {
    test(`${source} withdrawal reaches REST and removes the HttpOnly tracking cookie`, async ({ browser }) => {
      await setSlimstatOptions({ gdpr_enabled: 'off', javascript_mode: 'on', is_tracking: 'on',
        anonymous_tracking: 'off', set_tracker_cookie: 'on', ignore_bots: 'off', ignore_users: '', ignore_ips: '', tracking_request_method: 'ajax' });
      const marker = `consent-wire-${Date.now()}`;
      const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      try {
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/?consent_wire=${marker}`);
        await expect.poll(async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code' && c.httpOnly)).toBe(true);
        await setSlimstatOptions({ gdpr_enabled: 'on', consent_integration: source });
        const response = page.waitForResponse(r => r.url().includes('/consent-change') && r.request().method() === 'POST');
        await page.evaluate(source => (window as any).SlimStat.consent.sendChange(source, { statistics: 'deny' }, null), source);
        expect((await response).status()).toBe(200);
        await expect.poll(async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
        const nonce = await page.evaluate(() => (window as any).SlimStatParams.wp_rest_nonce);
        const endpoint = `${BASE_URL}/?rest_route=/slimstat/v1/consent-change`;
        const malformed = await context.request.post(endpoint, { data: { source, parsed: { statistics: 'deny' }, nonce, mode: { unknown: true } } });
        expect(malformed.status()).toBe(400);
        const denied = await context.request.post(endpoint, { data: { source, parsed: { statistics: 'deny' }, nonce: 'invalid' } });
        expect(denied.status()).toBe(403);
      } finally { await context.close(); await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]); }
    });
  }
  test('saving Tracker with GDPR off preserves an unchosen consent integration', async ({ page }) => {
    await setSlimstatOptions({ gdpr_enabled: 'off', consent_integration: '', use_slimstat_banner: 'off' });
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=2`);
    const select = page.locator('select[name="options[consent_integration]"]');
    await expect(select).toHaveValue('');
    await page.getByRole('button', { name: /save changes/i }).click();
    await page.waitForLoadState('load');
    const [rows]: any = await getPool().query("SELECT option_value FROM wp_options WHERE option_name='slimstat_options'");
    expect(String(rows[0].option_value)).toContain('s:19:"consent_integration";s:0:""');
    expect(String(rows[0].option_value)).toContain('s:19:"use_slimstat_banner";s:3:"off"');
  });

  for (const mode of ['on', 'off']) {
  test(`SlimStat banner gates WooCommerce attribution in javascript_mode=${mode} before initialization and after withdrawal @woocommerce`, async ({ browser }) => {
    await setSlimstatOptions({ javascript_mode: mode, is_tracking: 'on', gdpr_enabled: 'on', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on', anonymous_tracking: 'off', ignore_bots: 'off' });
    await snapshotOption('active_plugins');
    runWordPressFixture("<?php update_option('active_plugins', array_values(array_filter(get_option('active_plugins'), static fn($plugin) => !preg_match('~^(wp-consent-api|cookie-law-info)/~', $plugin))));");
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?utm_source=consent-regression&utm_medium=email`);
      await page.waitForFunction(() => typeof (window as any).wc_order_attribution?.setOrderTracking === 'function');
      expect((await context.cookies()).filter(c => c.name.startsWith('sbjs_'))).toEqual([]);
      await page.locator('.slimstat-gdpr-accept').click();
      await expect.poll(async () => (await context.cookies()).some(c => c.name === 'sbjs_current')).toBe(true);
      const attribution = await page.evaluate(() => (window as any).wc_order_attribution.getAttributionData());
      expect(attribution.utm_source).toBe('consent-regression');
      const encoded = Buffer.from(JSON.stringify(attribution)).toString('base64');
      const order = JSON.parse(runWordPressFixture(`<?php
        if ('tests-wordpress' !== DB_NAME) throw new RuntimeException('Disposable database required');
        add_filter('pre_wp_mail', '__return_true');
        $order = new WC_Order(); $order->set_date_created(time()); $order->set_total('19.95'); $order->save();
        try {
          do_action('woocommerce_order_save_attribution_data', $order, json_decode(base64_decode('${encoded}'), true));
          $saved = wc_get_order($order->get_id());
          echo wp_json_encode(['source'=>$saved->get_meta('_wc_order_attribution_utm_source'), 'total'=>$saved->get_total()]);
        } finally { $id=$order->get_id(); $order->delete(true); \\SlimStat\\Ecommerce\\Integration::sync($id); }
      `));
      expect(order).toEqual({ source: 'consent-regression', total: '19.95' });
      await page.reload();
      await expect.poll(async () => (await context.cookies()).some(c => c.name === 'sbjs_current')).toBe(true);
      await page.evaluate(() => {
        (window as any).wc_order_attribution.setOrderTracking(false);
        document.dispatchEvent(new CustomEvent('slimstat_banner_consent', { detail: { consent: 'accepted' } }));
      });
      expect((await context.cookies()).filter(c => c.name.startsWith('sbjs_'))).toEqual([]);
      expect(await page.evaluate(() => (window as any).wc_order_attribution.params.allowTracking)).toBe(false);
      await page.evaluate(() => (window as any).wc_order_attribution.setOrderTracking(true));
      await expect.poll(async () => (await context.cookies()).some(c => c.name === 'sbjs_current')).toBe(true);
      await page.evaluate(() => {
        document.cookie = 'slimstat_gdpr_consent=denied; path=/';
        document.dispatchEvent(new CustomEvent('slimstat_banner_consent', { detail: { consent: 'denied' } }));
      });
      await expect.poll(async () => (await context.cookies()).filter(c => c.name.startsWith('sbjs_')).length).toBe(0);
      await page.reload();
      expect((await context.cookies()).filter(c => c.name.startsWith('sbjs_'))).toEqual([]);
    } finally { try { await context.close(); } finally { await restoreOption('active_plugins'); } }
  });
  }

  test('only post-consent heatmap clicks reach storage', async ({ page, browser }) => {
    await requireProBooted(page);
    await setSlimstatOptions({ gdpr_enabled: 'on', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on',
      anonymous_tracking: 'off', javascript_mode: 'on', is_tracking: 'on', ignore_bots: 'off', do_not_track: 'off',
      heatmap_capture: 'main', heatmap_rate: 10000, heatmap_pages: '' });
    runWordPressFixture('<?php \\SlimStat\\Heatmap\\Store::setup();');
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    let id = 0;
    try {
      const visitor = await context.newPage();
      await visitor.goto(`${BASE_URL}/?consent_heatmap=${Date.now()}`);
      await visitor.evaluate(() => {
        for (const name of ['before-consent', 'after-consent']) {
          const button = document.createElement('button'); button.id = name; button.textContent = name;
          button.style.cssText = 'position:fixed;top:50px;left:' + (name.startsWith('before') ? '10px' : '180px') + ';z-index:1000';
          document.body.appendChild(button);
        }
      });
      await visitor.locator('#before-consent').click();
      await visitor.locator('.slimstat-gdpr-accept').click();
      await expect.poll(() => visitor.evaluate(() => (window as any).SlimStatParams.id || '')).toMatch(/^\d+\./);
      id = await visitor.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      await visitor.locator('#after-consent').click();
      await visitor.evaluate(() => window.dispatchEvent(new Event('pagehide')));
      await expect.poll(async () => {
        const [rows]: any = await getPool().query('SELECT e.selector FROM wp_slim_heatmap h JOIN wp_slim_heatmap_elements e ON h.sel=e.sel WHERE h.id=? AND h.kind=0', [id]);
        return rows.map((r: any) => r.selector);
      }).toEqual(['#after-consent']);
    } finally {
      await context.close();
      if (id) { await getPool().query('DELETE FROM wp_slim_stats WHERE id=?', [id]); }
    }
  });
});
