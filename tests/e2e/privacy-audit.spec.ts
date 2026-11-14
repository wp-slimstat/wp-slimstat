import { test, expect } from '@playwright/test';
import { isIP } from 'node:net';
import { runWordPressFixture } from './helpers/chart';
import { BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb, snapshotOption, restoreAllOptions,
  installOptionMutator, uninstallOptionMutator } from './helpers/setup';

test.describe('privacy audit: real visitor consent boundaries', () => {
  test.beforeAll(async () => { await installOptionMutator(); });
  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await snapshotOption('permalink_structure');
    await setSlimstatOptions({ is_tracking: 'on', javascript_mode: 'on', ignore_bots: 'off', ignore_wp_users: 'no',
      ignore_users: '', ignore_ip: '', ignore_capabilities: '', do_not_track: 'off', geolocation_provider: 'disabled',
      gdpr_enabled: 'on', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on',
      anonymous_tracking: 'off', set_tracker_cookie: 'on', anonymize_ip: 'off', hash_ip: 'off' });
  });
  test.afterEach(async () => { await restoreSlimstatOptions(); await restoreAllOptions(); });
  test.afterAll(async () => { await uninstallOptionMutator(); await closeDb(); });

  test('export, erasure and archive retention operate on actual stored rows', () => {
    const result = runWordPressFixture("<?php require WP_PLUGIN_DIR . '/wp-slimstat/tests/privacy-data-wp-test.php';");
    expect(result).toContain('PASS: complete export');
  });

  test('reviewed races preserve goal counts, masking and erasure', () => {
    expect(runWordPressFixture("<?php require WP_PLUGIN_DIR . '/wp-slimstat/tests/privacy-review-wp-test.php';")).toContain('PASS: exact masked goal/funnel totals');
  });

  test('visit tokens cannot update pageviews and deleted pageviews retain a JSON consent result', async ({ browser }) => {
    await setSlimstatOptions({ anonymous_tracking: 'on' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?privacy_audit=token-purpose`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const params = await page.evaluate(() => (window as any).SlimStatParams);
      const id = parseInt(params.id, 10);
      const visitToken = runWordPressFixture(`<?php echo \\SlimStat\\Tracker\\Utils::getValueWithChecksum(${id});`).trim();
      await context.addCookies([{ name: 'slimstat_gdpr_consent', value: 'accepted', url: BASE_URL }]);
      const [before]: any = await getPool().query('SELECT * FROM wp_slim_stats WHERE id=?', [id]);
      for (const upgrade of ['', '1']) {
        const response = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimtrack', id: visitToken, consent_upgrade: upgrade, fh: 'forged-identity' } });
        expect((await response.text()).replace(/^"|"$/g, '')).toBe('-104');
      }
      const [after]: any = await getPool().query('SELECT * FROM wp_slim_stats WHERE id=?', [id]);
      expect(after).toEqual(before);
      const valid = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimtrack', id: params.id, consent_upgrade: '1', res: Buffer.from(page.url()).toString('base64') } });
      expect(parseInt((await valid.text()).replace(/^"|"$/g, ''), 10)).toBe(id);
      await getPool().execute('DELETE FROM wp_slim_stats WHERE id=?', [id]);
      const deleted = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimstat_gdpr_consent', pageview_id: params.id, consent: 'accepted', nonce: params.wp_rest_nonce } });
      expect(await deleted.json()).toMatchObject({ success: true, data: { consent: 'accepted', upgraded: false } });
      expect((await context.cookies()).find(cookie => cookie.name === 'slimstat_gdpr_consent')?.value).toBe('accepted');
    } finally { await context.close(); }
  });

  test('fresh defaults count without identity, fingerprinting or device storage', async ({ browser }) => {
    const defaults = JSON.parse(runWordPressFixture('<?php echo wp_json_encode(wp_slimstat::get_fresh_defaults());'));
    await setSlimstatOptions({ ...defaults, ignore_bots: 'off', geolocation_provider: 'disabled' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      const requests: string[] = [];
      page.on('request', r => requests.push(r.url()));
      await page.goto(`${BASE_URL}/?privacy_audit=fresh`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const id = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      const [rows]: any = await getPool().query('SELECT ip,email,username,fingerprint,visit_id,vid_hash FROM wp_slim_stats WHERE id=?', [id]);
      expect(rows[0].ip).toMatch(/^[0-9a-f]{39}$/);
      for (const key of ['email', 'username', 'fingerprint']) expect(rows[0][key] || '').toBe('');
      expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
      expect(requests.some(url => url.includes('openfpcdn'))).toBe(false);
      const storage = await page.evaluate(() => [...Object.keys(localStorage), ...Object.keys(sessionStorage)].filter(k => k.startsWith('slimstat_')));
      expect(storage).toEqual([]);
    } finally { await context.close(); }
  });

  test('Global Privacy Control prevents both JavaScript and direct server tracking', async ({ browser }) => {
    await setSlimstatOptions({ do_not_track: 'on', gdpr_enabled: 'off' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, extraHTTPHeaders: { 'Sec-GPC': '1' } });
    try {
      await context.addInitScript(() => Object.defineProperty(navigator, 'globalPrivacyControl', { value: true }));
      const page = await context.newPage();
      const marker = `privacy-gpc-${Date.now()}`;
      await page.goto(`${BASE_URL}/?${marker}`);
      await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
      const response = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
        form: { action: 'slimtrack', res: Buffer.from('/' + marker).toString('base64') },
      });
      expect(response.ok()).toBe(true);
      const [rows]: any = await getPool().query('SELECT COUNT(*) n FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      expect(Number(rows[0].n)).toBe(0);
      expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
    } finally { await context.close(); }
  });

  test('missing integration and forged upgrade flags never enable identifiers', async ({ browser }) => {
    await setSlimstatOptions({ consent_integration: '', use_slimstat_banner: 'off', anonymous_tracking: 'on' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?privacy_audit=forged`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const signedId = await page.evaluate(() => (window as any).SlimStatParams.id);
      const response = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
        form: { action: 'slimtrack', id: signedId, consent_upgrade: '1', fh: 'forged-fingerprint' },
      });
      expect(response.ok()).toBe(true);
      const [rows]: any = await getPool().query('SELECT ip,email,username,fingerprint,visit_id,vid_hash FROM wp_slim_stats WHERE id=?', [parseInt(signedId, 10)]);
      expect(rows[0].ip).toMatch(/^[0-9a-f]{39}$/);
      for (const key of ['email', 'username', 'fingerprint']) expect(rows[0][key] || '').toBe('');
      expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
    } finally { await context.close(); }
  });

  test('consented tracking redacts URL secrets and never imports guest comment identity or button values', async ({ browser }) => {
    const hash = runWordPressFixture('<?php echo COOKIEHASH;').trim();
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      await context.addCookies([
        { name: `comment_author_${hash}`, value: 'PrivateGuest', url: BASE_URL },
        { name: `comment_author_email_${hash}`, value: 'guest@example.test', url: BASE_URL },
        { name: 'slimstat_gdpr_consent', value: 'accepted', url: BASE_URL },
      ]);
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?token=private-token&email=guest%40example.test&utm_source=privacyqa`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const id = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      const [rows]: any = await getPool().query('SELECT resource,email,username,utm_source FROM wp_slim_stats WHERE id=?', [id]);
      expect(rows[0].resource).not.toMatch(/private-token|guest|email=|token=/);
      expect(String(rows[0].utm_source)).toBe('privacyqa');
      expect(rows[0].email || '').toBe(''); expect(rows[0].username || '').toBe('');
      await page.evaluate(() => { const button = document.createElement('button'); button.id = 'audit-button'; button.textContent = 'Continue'; button.value = 'private-form-value'; document.body.prepend(button); });
      await page.locator('#audit-button').click();
      await expect.poll(async () => {
        const [events]: any = await getPool().query('SELECT notes FROM wp_slim_events WHERE id=?', [id]);
        expect(JSON.stringify(events)).not.toContain('private-form-value');
        return events.length;
      }).toBeGreaterThan(0);
    } finally { await context.close(); }
  });

  for (const mode of ['on', 'no']) test(`an unavailable selected CMP stays selected and ignores old banner consent (JavaScript ${mode})`, async ({ browser, page: adminPage }) => {
    await snapshotOption('active_plugins');
    await setSlimstatOptions({ javascript_mode: mode, consent_integration: 'wp_consent_api', use_slimstat_banner: 'off', anonymous_tracking: 'on' });
    runWordPressFixture("<?php require_once ABSPATH . 'wp-admin/includes/plugin.php'; deactivate_plugins('wp-consent-api/wp-consent-api.php', true);");
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      await context.addCookies([{ name: 'slimstat_gdpr_consent', value: 'accepted', url: BASE_URL }]);
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?privacy_audit=missing-selected-cmp`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const id = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      const [rows]: any = await getPool().query('SELECT ip,email,username,fingerprint,visit_id FROM wp_slim_stats WHERE id=?', [id]);
      expect(rows[0].ip).toMatch(/^[0-9a-f]{39}$/);
      for (const key of ['email', 'username', 'fingerprint']) expect(rows[0][key] || '').toBe('');
      expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
      expect(runWordPressFixture("<?php echo wp_slimstat::$settings['consent_integration'] . ':' . wp_slimstat::$settings['use_slimstat_banner'];").trim()).toBe('wp_consent_api:off');
      // Consent settings live on the Privacy & GDPR tab (9) since the settings redesign.
      await adminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=9`);
      await expect(adminPage.locator('#consent_integration')).toHaveValue('wp_consent_api');
      await expect(adminPage.locator('#consent_integration option[value="wp_consent_api"]')).toBeEnabled();
      await adminPage.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=1`);
      await adminPage.locator('input[name="Submit"]').click();
      await adminPage.waitForLoadState('load');
      expect(runWordPressFixture("<?php $s = get_option('slimstat_options'); echo $s['consent_integration'] . ':' . $s['use_slimstat_banner'];").trim()).toBe('wp_consent_api:off');
    } finally { await context.close(); }
  });

  test('server-side tracking keeps consent controls available and respects hashing after acceptance', async ({ browser }) => {
    await setSlimstatOptions({ javascript_mode: 'off', anonymous_tracking: 'on', hash_ip: 'on', set_tracker_cookie: 'off' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?privacy_audit=server`);
      await page.locator('[data-consent="accepted"]').click();
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const id = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      await expect.poll(async () => {
        const [rows]: any = await getPool().query('SELECT ip FROM wp_slim_stats WHERE id=?', [id]);
        return rows[0]?.ip;
      }).toMatch(/^[0-9a-f]{39}$/);
      await expect(page.locator('#slimstat-gdpr-banner')).toBeHidden();
      expect((await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
      await expect(page.getByRole('button', { name: 'Privacy settings', exact: true }).last()).toBeVisible();
    } finally { await context.close(); }
  });

  for (const transport of ['rest', 'ajax', 'adblock_bypass']) {
    test(`accept, reopen, withdraw and reject stale events over ${transport}`, async ({ browser }) => {
      await setSlimstatOptions({ tracking_request_method: transport });
      if (transport === 'adblock_bypass') { runWordPressFixture("<?php update_option('permalink_structure', '/%postname%/'); flush_rewrite_rules(false);"); }
      const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      try {
        const page = await context.newPage();
        await page.goto(`${BASE_URL}/?privacy_audit=withdraw-${transport}`);
        await page.evaluate(() => { const button = document.createElement('button'); button.id = 'private-click'; button.textContent = 'Private visitor label'; document.body.prepend(button); });
        await page.locator('#private-click').click();
        expect(await page.evaluate(() => localStorage.getItem('slimstat_offline_queue'))).toBeNull();
        await page.locator('[data-consent="accepted"]').click();
        await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
        await expect.poll(async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(true);
        const signedId = await page.evaluate(() => (window as any).SlimStatParams.id);
        await expect(page.locator('#slimstat-gdpr-banner')).toBeHidden();
        await page.getByRole('button', { name: 'Privacy settings', exact: true }).last().click();
        await expect(page.locator('#slimstat-gdpr-banner')).toBeVisible();
        await page.locator('[data-consent="denied"]').click();
        await expect.poll(async () => (await context.cookies()).some(c => c.name === 'slimstat_tracking_code')).toBe(false);
        const [before]: any = await getPool().query('SELECT * FROM wp_slim_stats WHERE id=?', [parseInt(signedId, 10)]);
        await page.locator('#private-click').click();
        await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimtrack', id: signedId, pos: '1,1', no: 'cHJpdmF0ZQ--' } });
        await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
        const [events]: any = await getPool().query('SELECT COUNT(*) n FROM wp_slim_events WHERE id=?', [parseInt(signedId, 10)]);
        expect(Number(events[0].n)).toBe(0);
        const [after]: any = await getPool().query('SELECT * FROM wp_slim_stats WHERE id=?', [parseInt(signedId, 10)]);
        expect(after).toEqual(before);
        await page.reload();
        await expect(page.getByRole('button', { name: 'Privacy settings', exact: true }).last()).toBeVisible();
        await page.getByRole('button', { name: 'Privacy settings', exact: true }).last().click();
        await expect(page.locator('[data-consent="accepted"]')).toBeVisible();
      } finally { await context.close(); }
    });
  }

  test('missing CMP with anonymous mode off cannot reuse an identified visitor cookie', async ({ browser }) => {
    await setSlimstatOptions({ consent_integration: '', use_slimstat_banner: 'off', anonymous_tracking: 'off' });
    const cookie = runWordPressFixture("<?php echo '991234.' . hash_hmac('sha256', '991234', wp_slimstat::$settings['secret']);").trim();
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      await context.addCookies([{name: 'slimstat_tracking_code', value: cookie, url: BASE_URL}]);
      const marker = `privacy-missing-cmp-${Date.now()}`;
      const response = await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
        form: {action: 'slimtrack', res: Buffer.from('/' + marker).toString('base64')},
      });
      expect(response.ok()).toBe(true);
      const id = parseInt(await response.text(), 10);
      expect(id).toBeGreaterThan(0);
      const [rows]: any = await getPool().query('SELECT visit_id,vid_hash,username,email FROM wp_slim_stats WHERE id=?', [id]);
      expect(Number(rows[0].visit_id)).not.toBe(991234);
      expect(rows[0].vid_hash).toBeTruthy();
      expect(rows[0].username || '').toBe(''); expect(rows[0].email || '').toBe('');
      expect(response.headers()['set-cookie'] || '').not.toContain('slimstat_tracking_code=');
    } finally { await context.close(); }
  });

  test('consenting on a later page preserves earlier anonymous rows', async ({ browser }) => {
    await setSlimstatOptions({ anonymous_tracking: 'on', tracking_request_method: 'ajax' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      await page.goto(`${BASE_URL}/?privacy_audit=history-a`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const firstId = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      const [before]: any = await getPool().query('SELECT ip,email,username,fingerprint,visit_id,vid_hash FROM wp_slim_stats WHERE id=?', [firstId]);
      await page.goto(`${BASE_URL}/?privacy_audit=history-b`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const currentId = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      await page.locator('[data-consent="accepted"]').click();
      await expect.poll(async () => {
        const [rows]: any = await getPool().query('SELECT ip FROM wp_slim_stats WHERE id=?', [currentId]);
        return isIP(String(rows[0]?.ip || ''));
      }).toBeGreaterThan(0);
      const [after]: any = await getPool().query('SELECT ip,email,username,fingerprint,visit_id,vid_hash FROM wp_slim_stats WHERE id=?', [firstId]);
      expect(after).toEqual(before);
      const [current]: any = await getPool().query('SELECT ip,visit_id,vid_hash FROM wp_slim_stats WHERE id=?', [currentId]);
      expect(Number(current[0].visit_id)).not.toBe(Number(before[0].visit_id));
      expect(current[0].vid_hash).toBeNull();
      await expect.poll(async () => {
        const trackerCookie = (await context.cookies()).find(cookie => cookie.name === 'slimstat_tracking_code');
        return parseInt(trackerCookie?.value || '', 10);
      }).toBe(Number(current[0].visit_id));
      await page.goto(`${BASE_URL}/?privacy_audit=history-c`);
      await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams?.id || '')).toMatch(/^\d+\./);
      const nextId = await page.evaluate(() => parseInt((window as any).SlimStatParams.id, 10));
      const [next]: any = await getPool().query('SELECT ip,visit_id FROM wp_slim_stats WHERE id=?', [nextId]);
      expect(next[0].ip).toBe(current[0].ip);
      expect(Number(next[0].visit_id)).toBe(Number(current[0].visit_id));
    } finally { await context.close(); }
  });
});
