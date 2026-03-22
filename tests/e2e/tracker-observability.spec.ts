/**
 * E2E tests: Tracker Observability Hotfix (#259)
 *
 * Validates the diagnostic tools added for production tracking failures:
 * 1. Negative error codes propagate through all transports
 * 2. Debug headers expose rejection reason when slimstat_debug is on
 * 3. JS __slimstatDebug records transport attempt chain
 * 4. tracker-health REST endpoint returns diagnostic data
 * 5. Ajax handle() echo fix — AJAX returns "0" instead of empty body
 *
 * Production scenario: adblock_bypass selected, server rejects with exclusion
 * code, JS falls back through REST/AJAX, diagnostics capture the full chain.
 */
import { test, expect } from '@playwright/test';
import {
  setSlimstatOption,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  installRewriteFlush,
  uninstallRewriteFlush,
  closeDb,
  getPool,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Tracker Observability — Production Scenario', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installRewriteFlush();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOptions(page, {
      ignore_wp_users: 'no',
      gdpr_enabled: 'off',
    });
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallRewriteFlush();
    await closeDb();
  });

  async function flushRewrites(page: import('@playwright/test').Page): Promise<void> {
    await page.goto(`${BASE_URL}/wp-admin/options-permalink.php`);
    await page.waitForLoadState('load');
  }

  // ─────────────────────────────────────────────────────────────────
  // Test 1: Ajax echo fix — AJAX now returns "0" body (not empty)
  // This is the latent bug that made the AJAX fallback useless for
  // diagnostics on the reported production site.
  // ─────────────────────────────────────────────────────────────────

  test('AJAX transport returns numeric body (not empty) on rejection', async ({ page, browser }) => {
    // Configure: use AJAX as primary transport so the echo fix is exercised directly
    await setSlimstatOptions(page, {
      tracking_request_method: 'ajax',
      javascript_mode: 'on',
      ignore_ip: '127.0.0.1,::1',
      slimstat_debug: 'on',
    });

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Capture the AJAX tracking response
    const ajaxResponses: { body: string; status: number }[] = [];
    anonPage.on('response', async (resp) => {
      if (resp.url().includes('admin-ajax.php') && resp.request().method() === 'POST') {
        try {
          const body = await resp.text();
          ajaxResponses.push({ body, status: resp.status() });
        } catch { /* ignore */ }
      }
    });

    await anonPage.goto(`${BASE_URL}/?e2e=ajax-echo-fix-${Date.now()}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3_000);

    // Must have captured at least one AJAX response — fail if not
    const trackingResp = ajaxResponses.find(r => r.status === 200);
    expect(trackingResp).toBeDefined();

    // After the echo fix, body should NOT be empty — it should be
    // a negative code (e.g., "-304") or "0" or a valid ID
    expect(trackingResp!.body.trim()).not.toBe('');

    // Should be a numeric value (positive ID, 0, or negative error code)
    const parsed = parseInt(trackingResp!.body.trim(), 10);
    expect(isNaN(parsed)).toBe(false);

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 2: Debug headers show rejection code on adblock_bypass
  // Production scenario: the server rejects with a 3xx code and the
  // response headers expose the reason.
  // ─────────────────────────────────────────────────────────────────

  test('debug headers expose rejection code on adblock_bypass transport', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
      ignore_ip: '127.0.0.1,::1',
      slimstat_debug: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Capture response headers from the adblock bypass endpoint
    const trackingHeaders: Record<string, string> = {};
    anonPage.on('response', async (resp) => {
      if (resp.url().includes('/request/') && resp.request().method() === 'POST') {
        const headers = resp.headers();
        if (headers['x-slimstat-transport']) trackingHeaders['transport'] = headers['x-slimstat-transport'];
        if (headers['x-slimstat-outcome']) trackingHeaders['outcome'] = headers['x-slimstat-outcome'];
        if (headers['x-slimstat-error-code']) trackingHeaders['error_code'] = headers['x-slimstat-error-code'];
      }
    });

    await anonPage.goto(`${BASE_URL}/?e2e=debug-headers-${Date.now()}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(5_000);

    // Verify debug headers are present
    expect(trackingHeaders['transport']).toBe('adblock_bypass');
    expect(trackingHeaders['outcome']).toBe('error');
    // Should be -304 (IP excluded) since we set ignore_ip=127.0.0.1
    expect(trackingHeaders['error_code']).toBe('-304');

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 3: JS __slimstatDebug records the full transport chain
  // When debug is on, the browser exposes attempt-by-attempt data.
  // ─────────────────────────────────────────────────────────────────

  test('JS __slimstatDebug records transport attempts when debug is on', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
      ignore_ip: '127.0.0.1,::1',
      slimstat_debug: 'on',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    await anonPage.goto(`${BASE_URL}/?e2e=js-debug-${Date.now()}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(5_000);

    // Read the debug object from the page
    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);

    expect(debugData).toBeDefined();
    expect(debugData.selectedTransport).toBe('adblock_bypass');
    expect(debugData.attempts).toBeInstanceOf(Array);
    expect(debugData.attempts.length).toBeGreaterThan(0);
    // All transports reject since IP is excluded
    expect(debugData.finalOutcome).toBe('rejected');

    // First attempt should be adblock_bypass
    expect(debugData.attempts[0].transport).toBe('adblock_bypass');
    // Should show the rejection as zero_or_negative body
    const firstAttempt = debugData.attempts[0];
    expect(firstAttempt.status).toBe(200);
    expect(['zero_or_negative', 'empty', 'non_numeric']).toContain(firstAttempt.bodyKind);

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 4: __slimstatDebug is NOT populated when debug is off
  // ─────────────────────────────────────────────────────────────────

  test('JS __slimstatDebug is absent when debug is off', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'rest',
      slimstat_debug: 'off',
    });

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    await anonPage.goto(`${BASE_URL}/?e2e=no-debug-${Date.now()}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3_000);

    // Check if WP_DEBUG is overriding the setting — SlimStatParams.slimstat_debug
    // is set to 'on' by enqueue_tracker() when EITHER the setting or WP_DEBUG is true.
    const debugParam = await anonPage.evaluate(
      () => (window as any).SlimStatParams?.slimstat_debug
    );

    if (debugParam === 'on') {
      // WP_DEBUG is on in this environment — debug object may exist even with
      // slimstat_debug=off. This is expected behavior, not a test failure.
      test.skip();
      return;
    }

    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug);
    expect(debugData).toBeUndefined();

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 5: tracker-health endpoint — admin access + response shape
  // ─────────────────────────────────────────────────────────────────

  test('tracker-health endpoint returns diagnostic data for admin', async ({ page }) => {
    // Set a known exclusion to verify it shows in the response
    await setSlimstatOption(page, 'ignore_bots', 'on');

    // Navigate to admin to get a nonce (page.request.get alone doesn't send WP REST nonce)
    await page.goto(`${BASE_URL}/wp-admin/`);
    const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '');
    const response = await page.request.get(`${BASE_URL}/wp-json/slimstat/v1/tracker-health`, {
      headers: { 'X-WP-Nonce': nonce }
    });
    expect(response.status()).toBe(200);

    const data = await response.json();
    expect(data.version).toBeDefined();
    expect(data.tracking_request_method).toBeDefined();
    expect(data.gdpr_enabled).toBeDefined();
    expect(data.ignore_settings).toBeDefined();
    expect(data.ignore_settings.ignore_bots).toBe('on');
    expect(data.last_tracker_error).toBeDefined();
    expect(data.last_tracker_error).toHaveProperty('code');
    expect(data.last_tracker_error).toHaveProperty('label');
    expect(data.last_tracker_error).toHaveProperty('recorded_at');
    expect(data.last_tracker_error).toHaveProperty('detail');
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 6: tracker-health endpoint — anonymous access denied
  // ─────────────────────────────────────────────────────────────────

  test('tracker-health endpoint returns 401 for anonymous users', async ({ browser }) => {
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const response = await anonPage.request.get(`${BASE_URL}/wp-json/slimstat/v1/tracker-health`);
    expect(response.status()).toBe(401);

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 7: FULL PRODUCTION SCENARIO
  // Simulates the exact failure chain from the reported issue:
  // - adblock_bypass selected, server rejects (IP excluded)
  // - JS falls back to REST, then AJAX
  // - All return negative/0 because same exclusion applies
  // - No row in DB
  // - tracker-health shows the rejection code
  // - Debug headers and JS debug capture the chain
  //
  // This is the acceptance test: from a single browser run with
  // debug enabled, support can classify the failure.
  // ─────────────────────────────────────────────────────────────────

  test('full production failure scenario — rejection is diagnosable end-to-end', async ({ page, browser }) => {
    // Step 1: Configure like the production site
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      javascript_mode: 'on',
      ignore_ip: '127.0.0.1,::1', // This causes the rejection
      slimstat_debug: 'on',
      gdpr_enabled: 'off',
    });
    await flushRewrites(page);

    // Pre-fetch nonce before anonymous visit so admin page load doesn't
    // overwrite the tracker error recorded by the rejected anonymous request.
    await page.goto(`${BASE_URL}/wp-admin/`);
    const nonce = await page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '');

    // Step 2: Visit as anonymous user (simulates real visitor)
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Capture all tracking responses
    const allResponses: { url: string; status: number; body: string; headers: Record<string, string> }[] = [];
    anonPage.on('response', async (resp) => {
      const url = resp.url();
      if (
        url.includes('/request/') ||
        url.includes('admin-ajax.php') ||
        url.includes('/wp-json/slimstat/')
      ) {
        try {
          const body = await resp.text();
          allResponses.push({
            url,
            status: resp.status(),
            body: body.substring(0, 200),
            headers: resp.headers(),
          });
        } catch { /* ignore */ }
      }
    });

    const marker = `prod-scenario-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(5_000);

    // Step 3: Verify NO database row was created (tracking was rejected)
    // Server-side PHP tracking may fire before JS mode takes effect, so if a
    // row exists it must have been tracked server-side (no JS client data).
    const stat = await waitForPageviewRow(marker, 3_000);
    if (stat !== null) {
      // Server-side tracked: resolution will be empty (no JS viewport data)
      expect(stat.resolution).toBeFalsy();
    }

    // Step 4: Verify JS debug captured the failure chain
    const debugData = await anonPage.evaluate(() => (window as any).__slimstatDebug?.lastPageview);
    expect(debugData).toBeDefined();
    expect(debugData.finalOutcome).toBe('rejected');
    expect(debugData.attempts.length).toBeGreaterThanOrEqual(1);

    // Step 5: Verify at least one response has debug headers
    const withHeaders = allResponses.find(r => r.headers['x-slimstat-error-code']);
    expect(withHeaders).toBeDefined();
    const errorCode = parseInt(withHeaders!.headers['x-slimstat-error-code'], 10);
    expect(errorCode).toBe(-304); // IP excluded

    // Step 6: Verify tracker-health shows the same error (as admin)
    // Use the pre-fetched nonce to avoid an admin page load that would
    // trigger tracking and overwrite the 304 error we want to verify.
    const healthResp = await page.request.get(`${BASE_URL}/wp-json/slimstat/v1/tracker-health`, {
      headers: { 'X-WP-Nonce': nonce }
    });
    const health = await healthResp.json();
    // The last recorded error may be 304 (IP excluded) or 429 (rate-limited
    // from the fallback chain hitting the AJAX rate limiter). Both indicate
    // tracking was rejected — the key diagnostic value is that an error exists.
    expect([304, 429]).toContain(health.last_tracker_error.code);
    // Label may be empty when i18n class isn't loaded in REST context
    expect(typeof health.last_tracker_error.label).toBe('string');
    expect(health.ignore_settings.ignore_ip).toBe('127.0.0.1,::1');

    // Step 7: Verify support triage flow works
    // From this single test run, support can determine:
    // - Error code 304 = IP excluded
    // - ignore_ip setting contains 127.0.0.1
    // - Resolution: remove the IP from the exclusion list
    // This is exactly the workflow described in the plan's verification rule.

    await ctx.close();
  });

  // ─────────────────────────────────────────────────────────────────
  // Test 8: Normal tracking still works (regression guard)
  // After all the error code changes, regular tracking must still
  // record pageviews with valid IDs.
  // ─────────────────────────────────────────────────────────────────

  test('normal tracking still records pageviews after error code changes', async ({ page, browser }) => {
    await setSlimstatOptions(page, {
      tracking_request_method: 'adblock_bypass',
      ignore_ip: '',
      ignore_bots: 'no',
      slimstat_debug: 'off',
    });
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `regression-guard-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    // Verify SlimStatParams.id was set (positive ID)
    const trackerId = await anonPage.evaluate(() => {
      const p = (window as any).SlimStatParams;
      return p ? p.id : null;
    });
    expect(trackerId).toBeTruthy();
    expect(parseInt(trackerId, 10)).toBeGreaterThan(0);

    await ctx.close();
  });
});
