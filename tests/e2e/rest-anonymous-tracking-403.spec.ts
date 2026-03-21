/**
 * E2E tests: REST anonymous tracking — 403 nonce regression + adblock fallback URL
 *
 * Validates issue #238: anonymous visitors should NOT get a 403 from nonce
 * validation, and adblock fallback URL should only be served when the rewrite
 * rule is active.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/238
 */
import { test, expect, Page } from '@playwright/test';
import {
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
  setSlimstatSetting,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

function trackRestHits(page: Page) {
  const requests: Array<{ url: string; headers: Record<string, string> }> = [];
  page.on('request', (req) => {
    if (req.url().includes('/wp-json/slimstat/v1/hit') || req.url().includes('rest_route=/slimstat/v1/hit'))
      requests.push({ url: req.url(), headers: req.headers() });
  });
  return { requests };
}

// Alias shared helpers for brevity
const snapshotOptions = snapshotSlimstatOptions;
const restoreOptions = restoreSlimstatOptions;
const setOption = setSlimstatSetting;

test.describe('REST Anonymous Tracking — Issue #238', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });

  test.afterEach(async () => {
    await restoreOptions();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('anonymous REST tracking should succeed with exactly one request (no nonce retry)', async ({ browser }) => {
    // Use a fresh browser context with no cookies (anonymous visitor)
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anonPage = await ctx.newPage();

    const restRequests: Array<{ url: string; headers: Record<string, string> }> = [];
    const restResponses: Array<{ status: number; url: string }> = [];

    anonPage.on('request', (req) => {
      if (
        req.url().includes('/wp-json/slimstat/v1/hit') ||
        req.url().includes('rest_route=/slimstat/v1/hit')
      ) {
        restRequests.push({ url: req.url(), headers: req.headers() });
      }
    });

    anonPage.on('response', async (res) => {
      if (
        res.url().includes('/wp-json/slimstat/v1/hit') ||
        res.url().includes('rest_route=/slimstat/v1/hit')
      ) {
        restResponses.push({ status: res.status(), url: res.url() });
      }
    });

    const marker = `anon-nonce-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3000);

    // There should be at least one REST request
    expect(restRequests.length).toBeGreaterThan(0);

    // CRITICAL: Anonymous visitors should NOT send X-WP-Nonce header.
    // When nonce is sent, it causes a 403 on cached pages (stale nonce),
    // triggering an unnecessary retry. Without nonce, the request succeeds
    // on the first try since permission_callback is __return_true.
    const requestsWithNonce = restRequests.filter(
      (r) => r.headers['x-wp-nonce'] !== undefined,
    );
    expect(
      requestsWithNonce.length,
      `Expected no requests with X-WP-Nonce header for anonymous users. Found ${requestsWithNonce.length} requests with nonce.`,
    ).toBe(0);

    // Exactly one REST hit request (no retry needed)
    const hitRequests = restRequests.filter(
      (r) => r.url.includes('/slimstat/v1/hit'),
    );
    expect(hitRequests.length, 'Should make exactly one hit request (no retry)').toBe(1);

    await ctx.close();
  });

  test('adblock fallback URL should not be injected when transport is REST', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anonPage = await ctx.newPage();

    const marker = `adblock-url-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    // Extract SlimStatParams from the page
    const params = await anonPage.evaluate(() => {
      return (window as any).SlimStatParams || null;
    });

    expect(params).toBeTruthy();

    // When transport is 'rest', ajaxurl_adblock should NOT be set
    expect(
      params.ajaxurl_adblock,
      'ajaxurl_adblock should not be set when transport is rest — the rewrite rule is not active',
    ).toBeFalsy();

    await ctx.close();
  });

  test('anonymous REST tracking should record a pageview in the database', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anonPage = await ctx.newPage();

    const marker = `anon-pv-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3000);

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'Anonymous visitor pageview should be recorded when GDPR is off').toBeTruthy();

    await ctx.close();
  });

  test('anonymous: nonce present (for consent), is_logged_in=0, no X-WP-Nonce header', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anonPage = await ctx.newPage();
    const { requests } = trackRestHits(anonPage);

    await anonPage.goto(`${BASE_URL}/?e2e=nonce-flag-check`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3000);

    const anonParams = await anonPage.evaluate(() => (window as any).SlimStatParams || null);
    expect(anonParams).toBeTruthy();
    // Nonce IS present (needed for consent banner CSRF protection)
    expect(anonParams.wp_rest_nonce, 'wp_rest_nonce must be present for consent').toBeTruthy();
    // is_logged_in is '0' → JS skips X-WP-Nonce header
    expect(anonParams.is_logged_in, 'is_logged_in must be 0 for anonymous').toBe('0');
    // Verify no nonce header was sent
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'No X-WP-Nonce header when is_logged_in=0').toBe(0);

    await ctx.close();
  });
});
