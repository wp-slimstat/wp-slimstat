import { createHmac } from 'crypto';
import { test, expect } from '@playwright/test';
import { unserialize as phpUnserialize } from 'php-serialize';
import {
  clearStatsTable,
  closeDb,
  getPool,
  installRewriteFlush,
  restoreOption,
  restoreSlimstatOptions,
  setSlimstatOptions,
  snapshotOption,
  snapshotSlimstatOptions,
  uninstallRewriteFlush,
  waitForPageviewRow,
  waitForTrackerId,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

async function flushRewrites(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(`${BASE_URL}/wp-admin/options-permalink.php`);
  await page.waitForLoadState('load');
  const saveButton = page.locator('#submit');
  if (await saveButton.isVisible()) {
    await saveButton.click();
    await page.waitForLoadState('load');
  }
}

async function getSlimstatSecret(): Promise<string> {
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
  ) as any;
  const options = rows.length ? phpUnserialize(rows[0].option_value) as Record<string, any> : {};
  return String(options.secret || '');
}

function signValue(value: string, secret: string): string {
  return `${value}.${createHmac('sha256', secret).update(String(value)).digest('hex')}`;
}

async function getLatestStatRow(marker: string): Promise<Record<string, any> | null> {
  const [rows] = await getPool().execute(
    'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
    [`%${marker}%`],
  ) as any;
  return rows.length ? rows[0] : null;
}

async function getStatCountForMarker(marker: string): Promise<number> {
  const [rows] = await getPool().execute(
    'SELECT COUNT(*) AS cnt FROM wp_slim_stats WHERE resource LIKE ?',
    [`%${marker}%`],
  ) as any;
  return rows[0]?.cnt ?? 0;
}

async function getTotalEventCount(): Promise<number> {
  const [rows] = await getPool().execute('SELECT COUNT(*) AS cnt FROM wp_slim_events') as any;
  return rows[0]?.cnt ?? 0;
}

async function clearDiagnosticOptions(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name IN ('slimstat_tracker_error', 'slimstat_tracker_warning', 'slimstat_geoip_error', 'slimstat_tracker_error_detail')"
  );
}

async function getTrackerHealth(page: import('@playwright/test').Page): Promise<any> {
  await page.goto(`${BASE_URL}/wp-admin/`);
  await page.waitForLoadState('load');
  const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '');
  const response = await page.request.get(`${BASE_URL}/wp-json/slimstat/v1/tracker-health`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(response.status()).toBe(200);
  return response.json();
}

test.describe('Tracking Recovery for Cached/CDN-style client-side tracking', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    installRewriteFlush();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await clearDiagnosticOptions();
    await setSlimstatOptions(page, {
      gdpr_enabled: 'off',
      javascript_mode: 'on',
      ignore_wp_users: 'no',
      ignore_bots: 'off',
      ignore_ip: '',
      ignore_resources: '',
      ignore_referers: '',
      slimstat_debug: 'on',
    });
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallRewriteFlush();
    await closeDb();
  });

  test('adblock HTML failure falls back to AJAX and records the first pageview', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    let adblockAttempts = 0;
    const requestOrder: string[] = [];

    await anonPage.route('**/request/**', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fallback();
        return;
      }
      adblockAttempts += 1;
      requestOrder.push('adblock_bypass');
      if (adblockAttempts === 1) {
        await route.fulfill({
          status: 200,
          contentType: 'text/html; charset=UTF-8',
          body: '<html><body>cached-html</body></html>',
        });
        return;
      }
      await route.fallback();
    });

    anonPage.on('request', (req) => {
      if (req.method() !== 'POST') return;
      if (req.url().includes('admin-ajax.php')) requestOrder.push('ajax');
      if (req.url().includes('/wp-json/slimstat/v1/hit')) requestOrder.push('rest_pretty');
      if (req.url().includes('rest_route=/slimstat/v1/hit')) requestOrder.push('rest_query');
    });

    const marker = `recovery-adblock-html-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    const stat = await waitForPageviewRow(marker, 20_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);
    expect(requestOrder[0]).toBe('adblock_bypass');
    expect(requestOrder).toContain('ajax');

    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
    expect(debugData?.finalOutcome).toBe('success');
    expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'adblock_bypass' && attempt.bodyKind === 'html')).toBe(true);
    expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'ajax' && attempt.bodyKind === 'numeric')).toBe(true);

    await ctx.close();
  });

  test('aborted first adblock request falls back to AJAX on the same load', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    let adblockAttempts = 0;

    await anonPage.route('**/request/**', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fallback();
        return;
      }
      adblockAttempts += 1;
      if (adblockAttempts === 1) {
        await route.abort('connectionfailed');
        return;
      }
      await route.fallback();
    });

    const marker = `recovery-timeout-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    const stat = await waitForPageviewRow(marker, 20_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
    expect(debugData?.finalOutcome).toBe('success');
    expect(debugData?.attempts?.[0]?.transport).toBe('adblock_bypass');
    expect(debugData?.attempts?.some((attempt: any) => attempt.bodyKind === 'network_error')).toBe(true);
    expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'ajax' && attempt.bodyKind === 'numeric')).toBe(true);

    await ctx.close();
  });

  test('REST pretty failure falls back to rest_route query transport', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'rest',
      javascript_mode: 'on',
    });

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    let sawQueryRoute = false;

    await anonPage.route('**/wp-json/slimstat/v1/hit', async (route) => {
      await route.fulfill({
        status: 404,
        contentType: 'text/html; charset=UTF-8',
        body: '<html><body>404</body></html>',
      });
    });

    anonPage.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('rest_route=/slimstat/v1/hit')) {
        sawQueryRoute = true;
      }
    });

    const marker = `recovery-rest-query-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    const stat = await waitForPageviewRow(marker, 20_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);
    expect(sawQueryRoute).toBe(true);

    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
    expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'rest_pretty' && attempt.status === 404)).toBe(true);
    expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'rest_query' && attempt.bodyKind === 'numeric')).toBe(true);

    await ctx.close();
  });

  test('REST query fallback uses index.php routing on index-permalink installs', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'rest',
      javascript_mode: 'on',
    });
    await snapshotOption('permalink_structure');
    await getPool().execute(
      "UPDATE wp_options SET option_value = ? WHERE option_name = 'permalink_structure'",
      ['/index.php/%postname%/'],
    );

    try {
      await flushRewrites(page);

      const ctx = await browser.newContext();
      const anonPage = await ctx.newPage();
      let queryRouteUrl = '';

      await anonPage.route('**/wp-json/slimstat/v1/hit', async (route) => {
        await route.fulfill({
          status: 404,
          contentType: 'text/html; charset=UTF-8',
          body: '<html><body>404</body></html>',
        });
      });

      anonPage.on('request', (req) => {
        if (req.method() === 'POST' && req.url().includes('rest_route=')) {
          queryRouteUrl = req.url();
        }
      });

      const marker = `recovery-rest-query-index-${Date.now()}`;
      await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

      const stat = await waitForPageviewRow(marker, 20_000);
      expect(stat).not.toBeNull();
      expect(stat!.resource).toContain(marker);
      expect(decodeURIComponent(queryRouteUrl)).toContain('/index.php?rest_route=/slimstat/v1/hit');

      const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
      expect(debugData?.attempts?.some((attempt: any) => attempt.transport === 'rest_query' && attempt.bodyKind === 'numeric')).toBe(true);

      await ctx.close();
    } finally {
      await restoreOption('permalink_structure');
      await flushRewrites(page);
    }
  });

  test('stale or malformed ci falls back to external content metadata instead of aborting tracking', async ({ page }) => {
    const markerChecksum = `recovery-ci-checksum-${Date.now()}`;
    const markerMalformed = `recovery-ci-malformed-${Date.now()}`;
    const secret = await getSlimstatSecret();

    await page.goto(`${BASE_URL}/?e2e=ci-primer-${Date.now()}`, { waitUntil: 'networkidle' });
    const ajaxUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_ajax || '');
    expect(ajaxUrl).toBeTruthy();

    const encodedData = await page.evaluate(({ checksumMarker, malformedMarker }) => {
      const b64 = (value: string) => btoa(unescape(encodeURIComponent(value)));
      return {
        checksumResource: b64(`${window.location.origin}/?e2e=${checksumMarker}`),
        malformedResource: b64(`${window.location.origin}/?e2e=${malformedMarker}`),
        ref: b64(document.referrer || ''),
      };
    }, { checksumMarker: markerChecksum, malformedMarker: markerMalformed });

    const staleChecksumResponse = await page.request.post(ajaxUrl, {
      form: {
        action: 'slimtrack',
        ref: encodedData.ref,
        res: encodedData.checksumResource,
        ci: 'stale.invalidchecksum',
      },
    });
    expect(staleChecksumResponse.status()).toBe(200);
    expect((await staleChecksumResponse.text()).trim()).toMatch(/^\d+\.[0-9a-f]+$/i);

    const malformedValue = Buffer.from('not-json', 'utf8').toString('base64').replace(/\+/g, '.').replace(/\//g, '_').replace(/=/g, '-');
    const malformedSigned = signValue(malformedValue, secret);
    const malformedResponse = await page.request.post(ajaxUrl, {
      form: {
        action: 'slimtrack',
        ref: encodedData.ref,
        res: encodedData.malformedResource,
        ci: malformedSigned,
      },
    });
    expect(malformedResponse.status()).toBe(200);
    expect((await malformedResponse.text()).trim()).toMatch(/^\d+\.[0-9a-f]+$/i);

    const checksumRow = await waitForPageviewRow(markerChecksum, 15_000);
    const malformedRow = await waitForPageviewRow(markerMalformed, 15_000);
    expect(checksumRow).not.toBeNull();
    expect(malformedRow).not.toBeNull();

    const checksumFull = await getLatestStatRow(markerChecksum);
    const malformedFull = await getLatestStatRow(markerMalformed);
    expect(checksumFull?.content_type).toBe('external');
    expect(malformedFull?.content_type).toBe('external');

    const health = await getTrackerHealth(page);
    expect([102, 103]).not.toContain(health.last_tracker_error.code);
    expect(health.last_tracker_warning.code).toBe(103);
    expect(typeof health.last_tracker_warning.label).toBe('string');
  });

  test('stale interaction id triggers pageview recovery and flushes the buffered event', async ({ page }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'ajax',
      javascript_mode: 'on',
    });

    const marker = `recovery-stale-interaction-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    const originalId = await waitForTrackerId(page);

    await page.evaluate(() => {
      const link = document.createElement('a');
      link.id = 'recover-link';
      link.href = 'https://example.com/recovery-target';
      link.target = '_blank';
      link.rel = 'noreferrer';
      link.textContent = 'Recovery Link';
      document.body.appendChild(link);
    });

    const countsBefore = {
      stats: await getStatCountForMarker(marker),
      events: await getTotalEventCount(),
    };

    const staleId = `${originalId.slice(0, -1)}${originalId.slice(-1) === '0' ? '1' : '0'}`;
    await page.evaluate((invalidId) => {
      (window as any).SlimStatParams.id = invalidId;
      (window as any).slimstatPageviewTracked = false;
    }, staleId);

    await page.click('#recover-link');

    await expect.poll(async () => getStatCountForMarker(marker), { timeout: 20_000 }).toBe(countsBefore.stats + 1);
    await expect.poll(async () => getTotalEventCount(), { timeout: 20_000 }).toBe(countsBefore.events + 1);

    const recoveredId = await page.evaluate(() => (window as any).SlimStatParams?.id || '');
    expect(recoveredId).toBeTruthy();
    expect(recoveredId).not.toBe(staleId);
  });

  test('stale pageview id retries once without id and assigns a fresh pageview id', async ({ page }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'ajax',
      javascript_mode: 'on',
    });

    const marker = `recovery-stale-pageview-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    const originalId = await waitForTrackerId(page);
    const rowsBefore = await getStatCountForMarker(marker);
    const staleId = `${originalId.slice(0, -1)}${originalId.slice(-1) === '0' ? '1' : '0'}`;

    await page.evaluate((invalidId) => {
      (window as any).SlimStatParams.id = invalidId;
      (window as any).slimstatPageviewTracked = false;
      (window as any).SlimStat._send_pageview({ isConsentRetry: true });
    }, staleId);

    await expect.poll(
      async () => page.evaluate(() => (window as any).SlimStatParams?.id || ''),
      { timeout: 20_000 }
    ).not.toBe(staleId);
    const recoveredId = await page.evaluate(() => (window as any).SlimStatParams?.id || '');
    const debugData = await page.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
    const rowCountAfter = await getStatCountForMarker(marker);

    expect(rowCountAfter).toBeGreaterThanOrEqual(rowsBefore);
    expect(debugData?.finalOutcome).toBe('success');
    expect(debugData?.attempts?.some((attempt: any) => attempt.bodyKind === 'stale_id_retry')).toBe(true);
    expect(recoveredId).toBeTruthy();
    expect(recoveredId).not.toBe(staleId);
  });

  test('explicit negative rejection does not queue offline retries and stays rejected', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
      ignore_bots: 'on',
      slimstat_debug: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext({
      userAgent: 'Googlebot/2.1 (+http://www.google.com/bot.html)',
    });
    const anonPage = await ctx.newPage();

    await anonPage.route('**/wp-json/slimstat/v1/hit', async (route) => {
      await route.fulfill({
        status: 404,
        contentType: 'text/html; charset=UTF-8',
        body: '<html><body>404</body></html>',
      });
    });

    const marker = `recovery-rejected-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(4_000);

    const stat = await waitForPageviewRow(marker, 3_000);
    expect(stat).toBeNull();

    const debugData = await anonPage.evaluate(() => ({
      lastPageview: (window as any).__slimstatDebug?.lastPageview,
      offline: JSON.parse(localStorage.getItem('slimstat_offline_queue') || '[]'),
    }));
    expect(debugData.lastPageview?.finalOutcome).toBe('rejected');
    expect(debugData.lastPageview?.attempts?.some((attempt: any) => attempt.errorCode === -313)).toBe(true);
    expect(debugData.offline).toHaveLength(0);

    await ctx.close();
  });

  test('transport memory prefers the last known good fallback after a transport-only failure', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    let requestAttempts = 0;

    await anonPage.route('**/request/**', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.fallback();
        return;
      }
      requestAttempts += 1;
      if (requestAttempts === 1) {
        await route.fulfill({
          status: 200,
          contentType: 'text/html; charset=UTF-8',
          body: '<html><body>cached-html</body></html>',
        });
        return;
      }
      await route.fallback();
    });

    const markerA = `recovery-memory-a-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${markerA}`, { waitUntil: 'networkidle' });
    expect(await waitForPageviewRow(markerA, 20_000)).not.toBeNull();
    const debugA = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);

    const markerB = `recovery-memory-b-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${markerB}`, { waitUntil: 'networkidle' });
    expect(await waitForPageviewRow(markerB, 20_000)).not.toBeNull();
    const debugB = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);

    expect(debugA?.attempts?.[0]?.transport).toBe('adblock_bypass');
    expect(debugA?.attempts?.some((attempt: any) => attempt.transport === 'ajax' && attempt.bodyKind === 'numeric')).toBe(true);
    expect(debugB?.selectedTransport).toBe('ajax');
    expect(debugB?.attempts?.[0]?.transport).toBe('ajax');

    await ctx.close();
  });

  test('GET requests to the adblock endpoint return 405 with no-store cache headers', async ({ page }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
    });
    await flushRewrites(page);

    await page.goto(`${BASE_URL}/?e2e=adblock-get-${Date.now()}`, { waitUntil: 'networkidle' });
    const adblockUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_adblock || '');
    expect(adblockUrl).toBeTruthy();

    const response = await page.request.get(adblockUrl);
    expect(response.status()).toBe(405);
    expect(response.headers()['allow']).toBe('POST');
    expect(response.headers()['cache-control']).toContain('no-store');
  });
});
