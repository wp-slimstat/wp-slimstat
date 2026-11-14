import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { requireProBooted } from './helpers/pro-state';
import { BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb,
  snapshotOption, restoreOption, installOptionMutator, uninstallOptionMutator } from './helpers/setup';

test.describe('consent wire and unchanged settings (#366/#367)', () => {
  test.beforeAll(async () => { await installOptionMutator(); });
  test.beforeEach(async () => { await snapshotSlimstatOptions(); });
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
  // Complianz and CookieYes withdraw through wp_set_consent(), which fires wp_listen_for_consent_change.
  // The test above calls sendChange() itself, so it cannot see whether that event is wired.
  // WP Consent API alone, with SlimStat reading its statistics category. Call inside try: the caller's finally restores active_plugins.
  const useWpConsentApiAlone = async () => {
    await snapshotOption('active_plugins');
    runWordPressFixture(`<?php require_once ABSPATH . 'wp-admin/includes/plugin.php';
      update_option('active_plugins', array_values(array_filter(get_option('active_plugins'), static fn($plugin) => !preg_match('~^(cookie-law-info|complianz-gdpr|real-cookie-banner)/~', $plugin))));
      activate_plugin('wp-consent-api/wp-consent-api.php');`);
    await setSlimstatOptions({ gdpr_enabled: 'on', consent_integration: 'wp_consent_api', consent_level_integration: 'statistics', use_slimstat_banner: 'off',
      javascript_mode: 'on', is_tracking: 'on', anonymous_tracking: 'off', set_tracker_cookie: 'on', ignore_bots: 'off', ignore_users: '', ignore_ips: '', tracking_request_method: 'rest' });
  };
  test('a denial through the real WP Consent API call removes the tracking cookie, and accepting again restores it', async ({ browser }) => {
    const marker = `consent-real-api-${Date.now()}`;
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const hasTrackerCookie = async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code' && c.httpOnly);
    try {
      await useWpConsentApiAlone();
      const page = await context.newPage();
      await page.addInitScript(() => { (window as any).wp_consent_type = 'optin'; });
      await page.goto(`${BASE_URL}/?consent_wire=${marker}`);
      expect(await page.evaluate(() => typeof (window as any).wp_set_consent)).toBe('function');
      expect(await hasTrackerCookie()).toBe(false);
      // CookieYes writes "deny" on a first visit, before any decision: nothing was withdrawn, so no server call.
      let consentPosts = 0;
      page.on('request', r => { if (r.url().includes('/consent-change') && r.method() === 'POST') consentPosts++; });
      await page.evaluate(() => (window as any).wp_set_consent('statistics', 'deny'));
      await page.evaluate(() => (window as any).wp_set_consent('statistics', 'allow'));
      await expect.poll(hasTrackerCookie).toBe(true);
      expect(consentPosts).toBe(0);
      const response = page.waitForResponse(r => r.url().includes('/consent-change') && r.request().method() === 'POST', { timeout: 10000 });
      await page.evaluate(() => (window as any).wp_set_consent('statistics', 'deny'));
      expect((await response).status()).toBe(200);
      await expect.poll(hasTrackerCookie).toBe(false);
      await page.evaluate(() => (window as any).wp_set_consent('statistics', 'allow'));
      await expect.poll(hasTrackerCookie).toBe(true);
    } finally {
      try { await context.close(); } finally {
        await restoreOption('active_plugins');
        await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      }
    }
  });

  // The tracker cannot read the HttpOnly cookie, so it reports a withdrawal only when it saw consent on the same page.
  // Consent withdrawn where the tracker did not see it leaves the cookie behind: only a page request shows it to the server.
  test('a tracking cookie left from an earlier page is removed by the next page request once consent is denied', async ({ browser }) => {
    const marker = `consent-leftover-${Date.now()}`;
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const hasTrackerCookie = async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code' && c.httpOnly);
    const visits = async () => { const [rows]: any = await getPool().query('SELECT visit_id FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]); return rows.map((r: any) => r.visit_id); };
    try {
      await useWpConsentApiAlone();
      const page = await context.newPage();
      await page.addInitScript(() => { (window as any).wp_consent_type = 'optin'; });
      await context.addCookies([{ name: 'wp_consent_statistics', value: 'allow', url: BASE_URL }]);
      await page.goto(`${BASE_URL}/?consent_wire=${marker}-1`, { waitUntil: 'networkidle' });
      await expect.poll(hasTrackerCookie).toBe(true);
      await page.goto(`${BASE_URL}/?consent_wire=${marker}-2`, { waitUntil: 'networkidle' });
      // Consent stands: the page request keeps the cookie, so the two pages are one visit.
      await expect.poll(async () => (await visits()).length).toBe(2);
      expect(new Set(await visits()).size).toBe(1);
      // Withdrawn elsewhere: this page starts with "deny" and never saw consent, so the tracker sends nothing.
      await context.addCookies([{ name: 'wp_consent_statistics', value: 'deny', url: BASE_URL }]);
      let trackerRequests = 0;
      page.on('request', r => { if (/\/consent-change|\/slimstat\/v1\/hit/.test(r.url())) trackerRequests++; });
      await page.goto(`${BASE_URL}/?consent_wire=${marker}-3`, { waitUntil: 'networkidle' });
      expect(await hasTrackerCookie()).toBe(false);
      expect(trackerRequests).toBe(0);
      expect(await visits()).toHaveLength(2);
    } finally {
      try { await context.close(); } finally {
        await restoreOption('active_plugins');
        await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      }
    }
  });

  // The server and the banner itself read only "accepted" or "denied" as a decision; the tracker has to agree.
  test('an unknown value in the built-in banner cookie is not consent: no tracker request, no row', async ({ browser }) => {
    await setSlimstatOptions({ gdpr_enabled: 'on', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on', anonymous_tracking: 'off',
      set_tracker_cookie: 'on', javascript_mode: 'on', is_tracking: 'on', ignore_bots: 'off', do_not_track: 'off', tracking_request_method: 'rest' });
    const marker = `banner-cookie-${Date.now()}`;
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      await context.addCookies([{ name: 'slimstat_gdpr_consent', value: 'bogus', url: BASE_URL }]);
      const page = await context.newPage();
      const hits: string[] = [];
      page.on('request', r => { if (r.url().includes('/slimstat/v1/hit')) hits.push(r.url()); });
      await page.goto(`${BASE_URL}/?banner_cookie=${marker}`, { waitUntil: 'networkidle' });
      await expect(page.locator('#slimstat-gdpr-banner')).toBeVisible();
      await page.waitForTimeout(2000);
      expect(hits).toHaveLength(0);
      const [rows]: any = await getPool().query('SELECT id FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      expect(rows).toHaveLength(0);
    } finally { await context.close(); await getPool().query('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]); }
  });
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
